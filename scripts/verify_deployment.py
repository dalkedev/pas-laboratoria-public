import argparse
import json
import time
import urllib.error
import urllib.parse
import urllib.request


def get(base: str, path: str) -> tuple[bytes, str]:
    request = urllib.request.Request(base + path, headers={"Cache-Control": "no-cache", "User-Agent": "PAS-deployment-verifier/1.0"})
    with urllib.request.urlopen(request, timeout=20) as response:
        if response.status != 200:
            raise ValueError(f"Unexpected HTTP status for {path}: {response.status}")
        final = urllib.parse.urlsplit(response.url)
        original = urllib.parse.urlsplit(base)
        if final.scheme != "https" or final.netloc != original.netloc:
            raise ValueError(f"Unexpected redirect outside the configured HTTPS origin for {path}")
        return response.read(), response.headers.get("Content-Type", "")


def main() -> None:
    parser = argparse.ArgumentParser(description="Verify the public HTTPS application and release version.")
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--version", required=True)
    args = parser.parse_args()
    base = args.base_url.rstrip("/")
    parsed = urllib.parse.urlsplit(base)
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path:
        raise ValueError("Use an HTTPS origin, e.g. https://dalke-124382.hox.pl")
    for attempt in range(5):
        try:
            data, _ = get(base, "/version.json?verify=" + urllib.parse.quote(args.version))
            if json.loads(data).get("version") != args.version:
                raise ValueError("Published version does not match the tested commit.")
            health_body, _ = get(base, "/z1/status.php")
            health = json.loads(health_body)
            if health.get("status") != "ok" or health.get("database") != "ok":
                raise ValueError("PHP application or MySQL connection is not ready.")
            for path in ("/", "/z1/"):
                body, content_type = get(base, path)
                if "text/html" not in content_type or len(body) < 100 or b"<?php" in body:
                    raise ValueError(f"Invalid rendered PHP page: {path}")
            for task in (2, 3, 4, 5):
                body, content_type = get(base, f"/z{task}/logowanie.php")
                if "text/html" not in content_type or b'name="pass"' not in body or b"<?php" in body:
                    raise ValueError(f"Task {task} login page is not ready.")
            for path in ("/app/config.local.php", "/lab/", "/database/schema.sql", "/storage/mycloud/"):
                try:
                    get(base, path)
                except urllib.error.HTTPError as error:
                    if error.code not in (403, 404):
                        raise
                else:
                    raise ValueError(f"A private or laboratory path is publicly reachable: {path}")
            print(f"HTTPS verified: release {args.version}, PHP pages, MySQL readiness and private path rejection.")
            return
        except (OSError, ValueError, urllib.error.URLError):
            if attempt == 4:
                raise
            time.sleep(3)


if __name__ == "__main__":
    main()
