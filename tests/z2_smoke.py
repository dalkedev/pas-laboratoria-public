from __future__ import annotations

import argparse
import base64
import http.cookiejar
from html.parser import HTMLParser
import random
import re
import shutil
import struct
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path
import zlib

from smoke import Client, NoRedirect


checks = 0
PROTECTED = ['/z2/', '/z2/header.php', '/z2/avatar.php'] + [f'/z2/polecenie{number}.php' for number in ('1_1', '1_2', '2_1', '2_2', '3_1', '3_2', '3_3')]
JPEG = base64.b64decode('/9j/4AAQSkZJRgABAQAASABIAAD/4QBMRXhpZgAATU0AKgAAAAgAAYdpAAQAAAABAAAAGgAAAAAAA6ABAAMAAAABAAEAAKACAAQAAAABAAAAAqADAAQAAAABAAAAAgAAAAD/7QA4UGhvdG9zaG9wIDMuMAA4QklNBAQAAAAAAAA4QklNBCUAAAAAABDUHYzZjwCyBOmACZjs+EJ+/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/bAEMAAgICAgICAwICAwUDAwMFBgUFBQUGCAYGBgYGCAoICAgICAgKCgoKCgoKCgwMDAwMDA4ODg4ODw8PDw8PDw8PD//bAEMBAgICBAQEBwQEBxALCQsQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEP/dAAQAAf/aAAwDAQACEQMRAD8A83ooor/QA/h8/9k=')


def expect(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1
    print(f'PASS {message}')


def png(width=2, height=2, image_data=True, noise=False):
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)
    result = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 6, 0, 0, 0))
    if image_data:
        pixels = random.Random(42).randbytes(width * height * 4) if noise else b'\x20\x80\xe0\xff' * width * height
        scanlines = b''.join(b'\x00' + pixels[row * width * 4:(row + 1) * width * 4] for row in range(height))
        result += chunk(b'IDAT', zlib.compress(scanlines))
    return result + chunk(b'IEND', b'')


class Page(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.elements = []
        self.text = []
        self.skip = 0
        self.feed(html)

    def handle_starttag(self, tag, attributes):
        self.elements.append((tag, dict(attributes)))
        if tag in ('script', 'style'):
            self.skip += 1

    def handle_endtag(self, tag):
        if tag in ('script', 'style') and self.skip:
            self.skip -= 1

    def handle_data(self, data):
        if not self.skip:
            self.text.append(data)


class Browser(Client):
    cookie_name = "PAS_Z2_SESSION"

    def __init__(self, base):
        super().__init__(base)
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPCookieProcessor(self.jar))

    def raw(self, path, fields=None, upload=None, cookie=None):
        headers = {'User-Agent': 'Dalke-Z2-local-smoke/1.0'}
        body = None
        if upload is not None:
            boundary = 'pas-z2-' + uuid.uuid4().hex
            parts = []
            for key, value in (fields or {}).items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
            filename, media_type, content = upload
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="avatar"; filename="{filename}"\r\nContent-Type: {media_type}\r\n\r\n'.encode() + content + b'\r\n')
            parts.append(f'--{boundary}--\r\n'.encode())
            body = b''.join(parts)
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        elif fields is not None:
            body = urllib.parse.urlencode(fields).encode()
        if cookie is not None:
            headers['Cookie'] = f'{self.cookie_name}={cookie}'
        request = urllib.request.Request(self.base + path, data=body, headers=headers)
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read()

    def token(self, path):
        status, _, body = self.request(path)
        expect(status == 200, f'{path} form available')
        match = re.search(r'name="csrf" value="([0-9a-f]{64})"', body)
        expect(match is not None, f'{path} CSRF token present')
        return match.group(1)


def main():
    parser = argparse.ArgumentParser(description='Local task 2 authentication, avatar and protected-page integration checks.')
    parser.add_argument('--base-url', default='http://127.0.0.1:8081')
    args = parser.parse_args()
    parsed = urllib.parse.urlsplit(args.base_url)
    if parsed.scheme != 'http' or parsed.hostname not in ('127.0.0.1', 'localhost', '::1') or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in ('', '/'):
        parser.error('Task 2 integration checks require a local loopback HTTP origin.')
    root = Path(__file__).resolve().parents[1]
    docker = shutil.which('docker') or '/Applications/Docker.app/Contents/Resources/bin/docker'
    prefix = 'z2smoke_' + str(time.time_ns())
    password = "x"
    plain_user, png_user, jpg_user = (prefix + suffix for suffix in ('_plain', '_png', '_jpg'))

    def sql(query):
        result = subprocess.run([docker, 'compose', 'exec', '-T', '-e', 'MYSQL_PWD=local-only-z2', 'db', 'mysql', '--batch', '--skip-column-names', '-u', 'z2', '-D', 'z2', '-e', query], cwd=root, text=True, capture_output=True, timeout=30)
        if result.returncode:
            raise AssertionError('Local task 2 database command failed; check the local schema and Docker services.')
        return result.stdout.strip()

    def clear_throttle():
        sql('DELETE FROM auth_attempts;')

    def form_values(browser, user, repeated=None):
        return {'csrf': browser.token('/z2/rejestruj.php'), 'user': user, 'pass': password, 'pass_repeat': password if repeated is None else repeated}

    def register(browser, user, upload=None):
        clear_throttle()
        status, headers, _ = browser.raw('/z2/add.php', form_values(browser, user), upload)
        expect(status == 303 and headers.get('Location') == '/z2/logowanie.php?registered=1', f'{user.rsplit("_", 1)[-1]} account registration succeeds')
        expect(sql(f"SELECT COUNT(*) FROM users WHERE username='{user}' AND LENGTH(password)>=60;") == '1', 'registration stores a password hash')

    def login(browser, user):
        clear_throttle()
        token = browser.token('/z2/logowanie.php')
        before = browser.sid()
        status, headers, body = browser.request('/z2/weryfikuj.php', {'csrf': token, 'user': user, 'pass': password})
        expect(status == 303 and headers.get('Location') == '/z2/', 'new task 2 account logs in')
        expect(bool(browser.sid()) and browser.sid() != before, 'task 2 login rotates session identifier')
        expect(password not in body and '$2y$' not in body, 'login response does not expose credentials')
        return before, browser.sid()

    def protected_denied(browser, message, cookie=None):
        for path in PROTECTED:
            status, headers, _ = browser.raw(path, cookie=cookie)
            expect(status == 303 and headers.get('Location') == '/z2/logowanie.php', f'{message}: {path}')

    def document(body):
        page = Page(body)
        expect(not any(tag == 'footer' for tag, _ in page.elements), 'task 2 document has no footer')
        visible = ' '.join(page.text).lower()
        expect(not any(term in visible for term in ('csrf', 'mysql', 'pdo', 'password_hash', 'password_verify', 'user_avatars', 'bootstrap')), 'task 2 document has no technical explanations')
        return page

    try:
        clear_throttle()
        anonymous = Browser(args.base_url)
        protected_denied(anonymous, 'anonymous access denied')
        status, _, registration = anonymous.request('/z2/rejestruj.php')
        expect(status == 200, 'task 2 registration is public')
        registration_page = document(registration)
        avatar_fields = [attributes for tag, attributes in registration_page.elements if tag == 'input' and attributes.get('name') == 'avatar']
        expect(len(avatar_fields) == 1 and avatar_fields[0].get('type') == 'file' and 'required' not in avatar_fields[0], 'avatar upload is optional')
        expect(any(tag == 'form' and attributes.get('enctype') == 'multipart/form-data' for tag, attributes in registration_page.elements), 'registration supports multipart uploads')
        for endpoint in ('/z2/add.php', '/z2/weryfikuj.php'):
            status, _, _ = anonymous.request(endpoint, {'user': plain_user, 'pass': password, 'pass_repeat': password})
            expect(status == 403, f'{endpoint} rejects missing CSRF')
        values = form_values(anonymous, plain_user)
        values['csrf'] = '0' * 64
        status, _, _ = anonymous.request('/z2/add.php', values)
        expect(status == 403, 'registration rejects incorrect CSRF')
        status, _, _ = anonymous.request('/z2/add.php', form_values(anonymous, plain_user, password + 'different'))
        expect(status == 422, 'registration rejects mismatched passwords')
        expect(sql(f"SELECT COUNT(*) FROM users WHERE username='{plain_user}';") == '0', 'failed validation creates no user')

        invalid_avatars = [
            ('svg-disguised', b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            ('php-disguised', b'<?php echo "not-an-image"; ?>'),
            ('corrupt-png', b'\x89PNG\r\n\x1a\nnot-a-complete-image'),
            ('header-without-image', png(image_data=False)),
            ('too-large', png() + b'x' * (2 * 1024 * 1024)),
            ('dimensions-too-wide', png(2001, 1)),
            ('dimensions-too-tall', png(1, 2001)),
        ]
        for label, content in invalid_avatars:
            clear_throttle()
            user = prefix + '_invalid_' + label.replace('-', '_')
            status, _, _ = anonymous.raw('/z2/add.php', form_values(anonymous, user), ('avatar.png', 'image/png', content))
            expect(status == 422, f'avatar rejected: {label} (HTTP {status})')
            expect(sql(f"SELECT COUNT(*) FROM users WHERE username='{user}';") == '0', f'invalid avatar creates no user: {label}')

        plain = Browser(args.base_url)
        register(plain, plain_user)
        expect(sql(f"SELECT COUNT(*) FROM user_avatars a JOIN users u ON u.id=a.user_id WHERE u.username='{plain_user}';") == '0', 'omitted avatar creates no uploaded blob')
        clear_throttle()
        status, _, _ = plain.request('/z2/add.php', form_values(plain, plain_user))
        expect(status == 409, 'duplicate registration is rejected')
        expect(sql(f"SELECT COUNT(*) FROM users WHERE username='{plain_user}';") == '1', 'duplicate registration leaves one user')
        clear_throttle()
        token = plain.token('/z2/logowanie.php')
        for user, secret in ((plain_user, 'wrong'), (prefix + '_unknown', password), (plain_user, "x') OR 1=1 -- "), ("nobody' OR 1=1 -- ", password)):
            status, _, body = plain.request('/z2/weryfikuj.php', {'csrf': token, 'user': user, 'pass': secret})
            expect(status == 401, 'bad credentials or SQL injection cannot log in')
            expect('data-authenticated="true"' not in body, 'failed login never renders authenticated content')
        old_sid, logged_sid = login(plain, plain_user)
        status, headers, image = plain.raw('/z2/avatar.php')
        expect(status == 200 and headers.get_content_type() == 'image/svg+xml' and b'<svg' in image, 'omitted upload serves a default SVG avatar')
        expect(b'<script' not in image and headers.get('X-Content-Type-Options') == 'nosniff', 'default avatar is inert and nosniff')

        for path in ['/z2/'] + [item for item in PROTECTED if '/polecenie' in item]:
            status, headers, body = plain.request(path)
            expect(status == 200 and 'data-authenticated="true"' in body, f'authenticated task page accessible: {path}')
            expect('no-store' in headers.get('Cache-Control', ''), f'protected page is not cached: {path}')
            document(body)
            if '/polecenie' in path:
                label = re.search(r'polecenie([1-3])_([1-3])', path)
                expect(f'polecenie {label.group(1)}.{label.group(2)}' in ' '.join(Page(body).text).lower(), f'protected page renders its own command: {path}')

        status, _, shell = plain.request('/z2/')
        shell_page = Page(shell)
        expect(any(attributes.get('id') == 'myHeader' for _, attributes in shell_page.elements), 'task page has a dynamic header target')
        static_assets = [(tag, attributes.get('href') or attributes.get('src')) for tag, attributes in shell_page.elements if tag in ('link', 'script') and urllib.parse.urlsplit(attributes.get('href') or attributes.get('src') or '').path.rsplit('/', 1)[-1] in ('bootstrap.min.css', 'bootstrap.bundle.min.js')]
        expect(any(tag == 'link' for tag, _ in static_assets) and any(tag == 'script' for tag, _ in static_assets), 'task page loads Bootstrap CSS and JavaScript')
        for tag, url in static_assets:
            target = urllib.parse.urlsplit(urllib.parse.urljoin(args.base_url + '/z2/', url))
            expect(target.netloc == parsed.netloc, 'Bootstrap dependency is served locally')
            status, headers, content = plain.raw(target.path)
            mime = headers.get_content_type()
            expect(status == 200 and len(content) > 1000 and (mime == 'text/css' if tag == 'link' else mime in ('text/javascript', 'application/javascript')), f'Bootstrap asset returns correct content type: {target.path}')
        status, headers, javascript = plain.raw('/z2/twoj_js.js')
        expect(status == 200 and headers.get_content_type() in ('text/javascript', 'application/javascript'), 'custom navigation loader is JavaScript')
        expect(b'myLoadHeader' in javascript and b'header.php' in javascript, 'custom loader requests the shared header')
        status, _, navbar = plain.request('/z2/header.php')
        expect(status == 200, 'authenticated shared header is available')
        nav_page = document(navbar)
        hrefs = [attributes.get('href', '') for tag, attributes in nav_page.elements if tag == 'a']
        for path in [item for item in PROTECTED if '/polecenie' in item]:
            expect(any(urllib.parse.urlsplit(urllib.parse.urljoin(args.base_url + '/z2/', href)).path == path for href in hrefs), f'navbar links to {path}')
        expect(any(tag == 'form' and attributes.get('action') == '/z2/logout.php' and attributes.get('method') == 'post' for tag, attributes in nav_page.elements), 'navbar has a POST logout form')
        expect('navbar-toggler' in navbar and 'data-bs-toggle="collapse"' in navbar and 'data-bs-toggle="dropdown"' in navbar, 'navbar has Bootstrap collapse and dropdown controls')
        expect(any(tag == 'img' and urllib.parse.urlsplit(urllib.parse.urljoin(args.base_url + '/z2/', attributes.get('src', ''))).path == '/z2/avatar.php' for tag, attributes in nav_page.elements), 'navbar renders the current avatar')

        protected_denied(Browser(args.base_url), 'separate browser has no session')
        status, _, _ = Browser(args.base_url).raw('/z2/', cookie=old_sid)
        expect(status == 303, 'session from before login cannot access task 2')
        status, _, _ = Browser(args.base_url).raw('/z2/', cookie=logged_sid)
        expect(status == 200, 'another tab with the current session can access task 2')
        logout_token = plain.token('/z2/header.php')
        status, headers, _ = plain.request('/z2/logout.php')
        expect(status == 303 and headers.get('Location') == '/z2/', 'logout GET redirects without changing session state')
        status, _, _ = plain.request('/z2/')
        expect(status == 200, 'logout GET keeps the session active')
        status, _, _ = plain.request('/z2/logout.php', {})
        expect(status == 403, 'logout rejects missing CSRF')
        status, headers, _ = plain.request('/z2/logout.php', {'csrf': logout_token})
        expect(status == 303 and headers.get('Location') == '/z2/logowanie.php', 'logout destroys the session')
        protected_denied(Browser(args.base_url), 'logged-out session denied', cookie=logged_sid)

        marker = b'<?php echo "z2-upload-marker"; ?>'
        with_png = Browser(args.base_url)
        uploaded_png = png(300, 300, noise=True) + marker
        expect(len(uploaded_png) > 32 * 1024, 'valid PNG fixture exceeds the former gateway limit')
        register(with_png, png_user, ('avatar.png', 'image/png', uploaded_png))
        blob = bytes.fromhex(sql(f"SELECT HEX(a.image) FROM user_avatars a JOIN users u ON u.id=a.user_id WHERE u.username='{png_user}';"))
        expect(blob.startswith(b'\x89PNG\r\n\x1a\n') and marker not in blob, 'uploaded PNG is decoded and re-encoded before database storage')
        width, height = struct.unpack('>II', blob[16:24])
        expect(1 <= width <= 256 and 1 <= height <= 256 and len(blob) <= 2 * 1024 * 1024, 'avatar is resized within 256 pixels and bounded database storage size')
        login(with_png, png_user)
        status, headers, served = with_png.raw('/z2/avatar.php')
        expect(status == 200 and headers.get_content_type() == 'image/png' and served == blob, 'authenticated PNG avatar matches the stored image blob')
        expect('no-store' in headers.get('Cache-Control', '') and headers.get('X-Content-Type-Options') == 'nosniff', 'uploaded avatar has private no-store and nosniff response')
        with_jpg = Browser(args.base_url)
        register(with_jpg, jpg_user, ('avatar.jpg', 'image/jpeg', JPEG))
        login(with_jpg, jpg_user)
        status, headers, served = with_jpg.raw('/z2/avatar.php')
        expect(status == 200 and headers.get_content_type() == 'image/png' and served.startswith(b'\x89PNG\r\n\x1a\n'), 'JPEG upload is normalized to a PNG avatar')
        expect(sql(f"SELECT COUNT(*) FROM user_avatars a JOIN users u ON u.id=a.user_id WHERE u.username IN ('{png_user}','{jpg_user}');") == '2', 'both uploaded avatars are associated with their own users')
        print(f'OK: {checks} task 2 checks passed against local PHP and MySQL.')
    finally:
        sql(f"DELETE a FROM user_avatars a JOIN users u ON u.id=a.user_id WHERE u.username LIKE '{prefix}%';")
        sql(f"DELETE FROM users WHERE username LIKE '{prefix}%';")
        clear_throttle()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(f'FAIL: {error}', file=sys.stderr)
        sys.exit(1)
