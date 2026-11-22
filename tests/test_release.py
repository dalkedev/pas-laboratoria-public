import contextlib
import hashlib
import importlib.util
import io
import json
import ssl
import tempfile
import unittest
import warnings
import zipfile
from pathlib import Path
from unittest.mock import patch

PROJECT = Path(__file__).resolve().parents[1]


def load_script(name):
    spec = importlib.util.spec_from_file_location(name, PROJECT / "scripts" / f"{name}.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


builder = load_script("build_release")
deployer = load_script("deploy_ftps")


class ReleaseSafetyTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / "project"
        self.root.mkdir()
        self.write("app/bootstrap.php", "<?php echo 'application';")
        self.write("public/index.php", "<?php echo 'overview';")
        self.write("public/z1/index.php", "<?php echo 'assignment';")
        self.write("public/assets/style.css", "body { color: black; }")
        self.write("public/.htaccess", "Options -Indexes")
        self.write("app/config.local.php", "<?php return 'SECRET-DATABASE-PASSWORD';")
        self.write("app/config.example.php", "<?php return 'example';")
        self.write(".env", "SECRET-ENVIRONMENT")
        self.write("lab/index.php", "<?php echo 'UNSAFE-LABORATORY';")
        self.write("database/schema.sql", "PRIVATE-SCHEMA")

    def write(self, name, text):
        target = self.root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text, encoding="utf-8")
        return target

    def build(self, output="package"):
        with patch.object(builder, "ROOT", self.root), contextlib.redirect_stdout(io.StringIO()):
            return builder.build(Path(self.temporary.name) / output, "test-commit")

    def rewrite_archive(self, archive, mutate):
        with zipfile.ZipFile(archive) as source:
            contents = {name: source.read(name) for name in source.namelist()}
        mutate(contents)
        target = Path(self.temporary.name) / "modified.zip"
        with zipfile.ZipFile(target, "w") as output:
            for name, data in contents.items():
                output.writestr(name, data)
        return target

    def add_manifest_file(self, contents, name, data):
        metadata = json.loads(contents["manifest.json"])
        metadata["sha256"][name] = hashlib.sha256(data).hexdigest()
        contents["manifest.json"] = json.dumps(metadata).encode()
        contents[name] = data

    def test_package_excludes_all_private_and_laboratory_sources(self):
        archive = self.build()
        version, files = deployer.read_release(archive)
        self.assertEqual(version, "test-commit")
        names = {name for name, _ in files}
        self.assertEqual(names, {
            "app/bootstrap.php", "public_html/index.php", "public_html/z1/index.php",
            "public_html/assets/style.css", "public_html/.htaccess", "public_html/version.json",
        })
        combined = b"\n".join(data for _, data in files)
        for secret in (b"SECRET-DATABASE-PASSWORD", b"SECRET-ENVIRONMENT", b"UNSAFE-LABORATORY", b"PRIVATE-SCHEMA"):
            self.assertNotIn(secret, combined)
        self.assertEqual(files[-1][0], "public_html/version.json")
        self.assertEqual(files[0][0], "app/bootstrap.php")

    def test_build_is_reproducible_for_the_same_version(self):
        self.assertEqual(self.build("first").read_bytes(), self.build("second").read_bytes())

    def test_source_output_directory_is_rejected_without_modification(self):
        before = (self.root / "app/bootstrap.php").read_bytes()
        with patch.object(builder, "ROOT", self.root), self.assertRaises(ValueError):
            builder.build(self.root / "app/package", "test")
        self.assertEqual((self.root / "app/bootstrap.php").read_bytes(), before)

    def test_symlink_to_private_source_is_rejected(self):
        (self.root / "public/secret.php").symlink_to(self.root / "app/config.local.php")
        with self.assertRaises(ValueError):
            self.build()

    def test_lab_copied_into_public_is_rejected(self):
        self.write("public/lab/index.php", "<?php echo 'vulnerable';")
        with self.assertRaises(ValueError):
            self.build()

    def test_checksum_corruption_is_rejected(self):
        changed = self.rewrite_archive(self.build(), lambda files: files.update({"app/bootstrap.php": b"corrupt"}))
        with self.assertRaisesRegex(ValueError, "Checksum mismatch"):
            deployer.read_release(changed)

    def test_path_traversal_even_with_valid_hash_is_rejected(self):
        changed = self.rewrite_archive(self.build(), lambda files: self.add_manifest_file(files, "public_html/../config.local.php", b"secret"))
        with self.assertRaisesRegex(ValueError, "Unsafe archive path"):
            deployer.read_release(changed)

    def test_local_configuration_in_forged_manifest_is_rejected(self):
        changed = self.rewrite_archive(self.build(), lambda files: self.add_manifest_file(files, "app/config.local.php", b"secret"))
        with self.assertRaisesRegex(ValueError, "Private or laboratory file"):
            deployer.read_release(changed)

    def test_file_missing_from_allowlist_is_rejected(self):
        changed = self.rewrite_archive(self.build(), lambda files: files.update({"public_html/backdoor.php": b"unexpected"}))
        with self.assertRaisesRegex(ValueError, "allowlist manifest"):
            deployer.read_release(changed)

    def test_version_marker_mismatch_is_rejected(self):
        changed = self.rewrite_archive(self.build(), lambda files: self.add_manifest_file(files, "public_html/version.json", b'{"version":"different"}'))
        with self.assertRaisesRegex(ValueError, "Public marker differs"):
            deployer.read_release(changed)

    def test_duplicate_archive_entries_are_rejected(self):
        archive = self.build()
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            with zipfile.ZipFile(archive, "a") as output:
                output.writestr("public_html/index.php", b"duplicate")
        with self.assertRaisesRegex(ValueError, "Duplicate"):
            deployer.read_release(archive)

    def test_domain_root_must_not_be_document_root_or_traversal(self):
        self.assertEqual(deployer.remote_root("/domains/dalke-124382.hox.pl/"), "/domains/dalke-124382.hox.pl")
        for invalid in ("/", "public_html", "/domains/site/public_html", "/domains/../public_html", "/domains/site\nDELE file"):
            with self.subTest(path=invalid), self.assertRaises(ValueError):
                deployer.remote_root(invalid)

    def test_jailed_account_may_use_root_only_with_explicit_setting(self):
        self.assertEqual(deployer.remote_root("/", domain_jailed=True), "/")
        with self.assertRaisesRegex(ValueError, "DOMAIN_JAILED"):
            deployer.remote_root("/")
        with self.assertRaises(ValueError):
            deployer.remote_root("/public_html", domain_jailed=True)

    def test_ftps_keeps_system_roots_and_full_certificate_validation(self):
        original = ssl.create_default_context()
        context = deployer.create_tls_context()
        self.assertTrue(context.check_hostname)
        self.assertEqual(context.verify_mode, ssl.CERT_REQUIRED)
        self.assertFalse(context.verify_flags & getattr(ssl, "VERIFY_X509_PARTIAL_CHAIN", 0))
        original_roots = {repr(certificate["subject"]) for certificate in original.get_ca_certs()}
        self.assertTrue(original_roots <= {repr(certificate["subject"]) for certificate in context.get_ca_certs()})
        for name in deployer.INTERMEDIATE_CERTIFICATES:
            certificate_context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
            certificate_context.load_verify_locations(cafile=str(deployer.CERTIFICATES_DIRECTORY / name))
            certificates = certificate_context.get_ca_certs()
            self.assertEqual(len(certificates), 1)
            self.assertNotEqual(certificates[0]["subject"], certificates[0]["issuer"])

    def test_modified_public_ca_file_is_rejected(self):
        certificate_directory = Path(self.temporary.name) / "certs"
        certificate_directory.mkdir()
        (certificate_directory / "int-ye1.pem").write_text("modified certificate", encoding="utf-8")
        with patch.object(deployer, "CERTIFICATES_DIRECTORY", certificate_directory), self.assertRaisesRegex(ValueError, "checksum mismatch"):
            deployer.create_tls_context()


if __name__ == "__main__":
    unittest.main()
