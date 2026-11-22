from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import subprocess
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PUBLIC_EXTENSIONS = {".php", ".css", ".js", ".svg", ".ico", ".png", ".jpg", ".jpeg", ".webp", ".txt", ".json", ".mp3", ".html"}
FORBIDDEN_PARTS = {"lab", "tests", "database", ".git", "node_modules", "__pycache__"}
FORBIDDEN_NAMES = {"media.local.php", "config.local.php", "config.example.php", ".env", ".env.local", ".DS_Store"}
REQUIRED_FILES = {"app/bootstrap.php", "public_html/index.php", "public_html/z1/index.php"}


def release_files() -> list[tuple[Path, Path]]:
    result: list[tuple[Path, Path]] = []
    for source_name, destination_name in (("app", "app"), ("public", "public_html")):
        source = ROOT / source_name
        if not source.is_dir():
            raise ValueError(f"Missing application directory: {source_name}")
        for item in sorted(source.rglob("*")):
            if item.is_symlink():
                raise ValueError(f"Symlinks are not allowed in a release: {item.relative_to(ROOT)}")
            if not item.is_file():
                continue
            relative = item.relative_to(source)
            if item.name in FORBIDDEN_NAMES:
                continue
            if FORBIDDEN_PARTS.intersection(relative.parts) or any(part.startswith(".env") for part in relative.parts):
                raise ValueError(f"Forbidden deployment path: {item.relative_to(ROOT)}")
            if any(not re.fullmatch(r"[A-Za-z0-9._-]+", part) or part in {".", ".."} for part in relative.parts):
                raise ValueError(f"Deployment file name contains unsupported characters: {item.relative_to(ROOT)}")
            allowed = item.name == ".htaccess" or (item.suffix == ".php" if source_name == "app" else item.suffix in PUBLIC_EXTENSIONS)
            if not allowed:
                raise ValueError(f"File type is not on the deployment allowlist: {item.relative_to(ROOT)}")
            if relative.as_posix() == "version.json":
                continue
            result.append((item, Path(destination_name) / relative))
    if missing := REQUIRED_FILES - {destination.as_posix() for _, destination in result}:
        raise ValueError("Incomplete application layout: " + ", ".join(sorted(missing)))
    return result


def build(destination: Path, version: str) -> Path:
    if not re.fullmatch(r"[A-Za-z0-9._-]{1,80}", version):
        raise ValueError("Version must contain only letters, digits, dots, dashes and underscores (max 80).")
    destination = destination.resolve()
    root = ROOT.resolve()
    if destination == root or destination in root.parents or destination.is_relative_to(root / "app") or destination.is_relative_to(root / "public"):
        raise ValueError("Output must be outside the application source directories.")
    destination.mkdir(parents=True, exist_ok=True)
    archive = destination / "release.zip"
    files = release_files()
    with tempfile.TemporaryDirectory(prefix="pas-release-") as temporary:
        staging = Path(temporary)
        manifest: dict[str, str] = {}
        for source, relative in files:
            target = staging / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target)
            manifest[relative.as_posix()] = hashlib.sha256(target.read_bytes()).hexdigest()
        version_file = staging / "public_html/version.json"
        version_file.parent.mkdir(parents=True, exist_ok=True)
        version_file.write_text(json.dumps({"version": version}, indent=2) + "\n", encoding="utf-8")
        manifest["public_html/version.json"] = hashlib.sha256(version_file.read_bytes()).hexdigest()
        (staging / "manifest.json").write_text(json.dumps({"version": version, "sha256": manifest}, indent=2) + "\n", encoding="utf-8")
        with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED) as output:
            for item in sorted(staging.rglob("*")):
                if item.is_file():
                    info = zipfile.ZipInfo(item.relative_to(staging).as_posix(), date_time=(2026, 1, 1, 0, 0, 0))
                    info.compress_type = zipfile.ZIP_DEFLATED
                    info.external_attr = 0o100644 << 16
                    output.writestr(info, item.read_bytes())
    print(f"Release built: {archive} ({len(manifest)} application files, version {version})")
    return archive


def main() -> None:
    parser = argparse.ArgumentParser(description="Build a production deployment package.")
    parser.add_argument("--output", type=Path, default=ROOT / "work/release")
    parser.add_argument("--version")
    args = parser.parse_args()
    version = args.version
    if not version:
        try:
            version = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True, stderr=subprocess.DEVNULL).strip()
        except (OSError, subprocess.CalledProcessError):
            version = "local"
    build(args.output, version)


if __name__ == "__main__":
    main()
