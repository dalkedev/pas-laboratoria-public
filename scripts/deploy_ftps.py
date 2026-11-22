from __future__ import annotations

import argparse
import ftplib
import hashlib
import io
import json
import os
import posixpath
import re
import ssl
import sys
import uuid
import zipfile
from pathlib import Path, PurePosixPath

PUBLIC_EXTENSIONS = {".php", ".css", ".js", ".svg", ".ico", ".png", ".jpg", ".jpeg", ".webp", ".txt", ".json", ".mp3", ".html"}
REQUIRED_FILES = {"app/bootstrap.php", "public_html/index.php", "public_html/z1/index.php", "public_html/version.json"}
CERTIFICATES_DIRECTORY = Path(__file__).resolve().parents[1] / "config/certs"
INTERMEDIATE_CERTIFICATES = {
    "int-ye1.pem": "30028f6eecdb9ba45b1a2440187278be1bc08c360da6611f21b4b3e74b103981",
    "root-ye-by-x2.pem": "2681e97e939cab7cde4fad1dc733fc7c99e39a203190e8f298e1c02ab431b1e9",
}


class VerifiedFTP_TLS(ftplib.FTP_TLS):
    def ntransfercmd(self, command, rest=None):
        connection, size = ftplib.FTP.ntransfercmd(self, command, rest)
        if self._prot_p:
            connection = self.context.wrap_socket(connection, server_hostname=self.host, session=self.sock.session)
        return connection, size


def create_tls_context() -> ssl.SSLContext:
    context = ssl.create_default_context()
    context.verify_flags &= ~getattr(ssl, "VERIFY_X509_PARTIAL_CHAIN", 0)
    for name, digest in INTERMEDIATE_CERTIFICATES.items():
        certificate = CERTIFICATES_DIRECTORY / name
        if hashlib.sha256(certificate.read_bytes()).hexdigest() != digest:
            raise ValueError(f"Bundled public intermediate certificate checksum mismatch: {name}")
        context.load_verify_locations(cafile=str(certificate))
    return context


def read_release(archive: Path) -> tuple[str, list[tuple[str, bytes]]]:
    with zipfile.ZipFile(archive) as release:
        names = release.namelist()
        if len(names) != len(set(names)):
            raise ValueError("Duplicate release archive entries.")
        metadata = json.loads(release.read("manifest.json"))
        if not isinstance(metadata, dict) or not isinstance(metadata.get("sha256"), dict) or not isinstance(metadata.get("version"), str) or not re.fullmatch(r"[A-Za-z0-9._-]{1,80}", metadata["version"]):
            raise ValueError("Invalid release manifest.")
        hashes = metadata["sha256"]
        if missing := REQUIRED_FILES - set(hashes):
            raise ValueError("Incomplete release layout: " + ", ".join(sorted(missing)))
        if set(names) != set(hashes) | {"manifest.json"}:
            raise ValueError("Archive differs from its allowlist manifest.")
        files: list[tuple[str, bytes]] = []
        for name, digest in sorted(hashes.items()):
            path = PurePosixPath(name)
            if path.is_absolute() or len(path.parts) < 2 or path.parts[0] not in {"app", "public_html"} or ".." in path.parts or "\\" in name:
                raise ValueError(f"Unsafe archive path: {name}")
            if path.as_posix() != name or any(not re.fullmatch(r"[A-Za-z0-9._-]+", part) or part in {".", ".."} for part in path.parts):
                raise ValueError(f"Unsupported archive path: {name}")
            if any(part in {"lab", "tests", "database", ".git"} or part.startswith(".env") for part in path.parts) or path.name in {"config.local.php", "config.example.php"}:
                raise ValueError(f"Private or laboratory file in release: {name}")
            allowed = path.name == ".htaccess" or (path.suffix == ".php" if path.parts[0] == "app" else path.suffix in PUBLIC_EXTENSIONS)
            if not allowed:
                raise ValueError(f"File type is not on the deployment allowlist: {name}")
            mode = release.getinfo(name).external_attr >> 16
            if mode & 0o170000 == 0o120000:
                raise ValueError(f"Symlink in release: {name}")
            data = release.read(name)
            if hashlib.sha256(data).hexdigest() != digest:
                raise ValueError(f"Checksum mismatch: {name}")
            files.append((name, data))
        if json.loads(release.read("public_html/version.json")) != {"version": metadata["version"]}:
            raise ValueError("Public marker differs from the release version.")
    files.sort(key=lambda item: item[0] == "public_html/version.json")
    return metadata["version"], files


def remote_root(value: str, domain_jailed: bool = False) -> str:
    if not value.startswith("/") or "\\" in value or any(part in {".", ".."} or (part and not re.fullmatch(r"[A-Za-z0-9._-]+", part)) for part in value.split("/")):
        raise ValueError("TOX_FTPS_ROOT must be an absolute FTP domain directory without traversal.")
    normalized = posixpath.normpath(value)
    if normalized == "/" and not domain_jailed:
        raise ValueError("TOX_FTPS_ROOT='/' requires TOX_FTPS_DOMAIN_JAILED=true and a domain-scoped FTP account.")
    if PurePosixPath(normalized).name in {"public_html", "private_html"}:
        raise ValueError("TOX_FTPS_ROOT must name the domain directory ABOVE public_html.")
    return normalized


def ensure_directory(ftp: ftplib.FTP_TLS, directory: str) -> None:
    try:
        ftp.cwd(directory)
    except ftplib.error_perm:
        ftp.mkd(directory)
        ftp.cwd(directory)


def main() -> None:
    parser = argparse.ArgumentParser(description="Deploy a validated production package over FTPS.")
    parser.add_argument("archive", type=Path)
    parser.add_argument("--dry-run", action="store_true", help="Validate the package; do not connect or write anything.")
    parser.add_argument("--check-connection", action="store_true", help="Connect over FTPS and verify the existing domain root; do not upload.")
    args = parser.parse_args()
    version, files = read_release(args.archive)
    if args.dry_run:
        print(f"Package valid: {len(files)} files; version {version}; app/ stays outside public_html/.")
        return
    required = ("TOX_FTPS_HOST", "TOX_FTPS_USER", "TOX_FTPS_PASSWORD", "TOX_FTPS_ROOT")
    if missing := [name for name in required if not os.environ.get(name)]:
        raise ValueError("Missing deployment configuration: " + ", ".join(missing))
    root = remote_root(os.environ["TOX_FTPS_ROOT"], os.environ.get("TOX_FTPS_DOMAIN_JAILED") == "true")
    context = create_tls_context()
    with VerifiedFTP_TLS(context=context, timeout=30) as ftp:
        ftp.connect(os.environ["TOX_FTPS_HOST"], int(os.environ.get("TOX_FTPS_PORT", "21")))
        ftp.auth()
        ftp.login(os.environ["TOX_FTPS_USER"], os.environ["TOX_FTPS_PASSWORD"])
        ftp.prot_p()
        ftp.cwd(root)
        ftp.cwd(posixpath.join(root, "public_html"))
        ftp.cwd(root)
        if args.check_connection:
            print("FTPS certificate, login and existing domain root verified; no files uploaded.")
            return
        release_id = uuid.uuid4().hex[:12]
        for name, data in files:
            path = PurePosixPath(name)
            ftp.cwd(root)
            for part in path.parts[:-1]:
                ensure_directory(ftp, part)
            temporary = f".deploy-{release_id}-{path.name}"
            ftp.storbinary(f"STOR {temporary}", io.BytesIO(data))
            ftp.rename(temporary, path.name)
        print(f"Uploaded {len(files)} files over verified FTPS; release {version}.")
        print("Private server configuration was preserved. HTTPS verification is still required.")


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError, zipfile.BadZipFile, ftplib.Error) as error:
        message = str(error)
        for key in ("TOX_FTPS_PASSWORD", "TOX_FTPS_USER"):
            if secret := os.environ.get(key):
                message = message.replace(secret, "[redacted]")
        print(f"Deployment failed: {message}", file=sys.stderr)
        sys.exit(1)
