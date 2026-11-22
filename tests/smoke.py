from __future__ import annotations

import argparse
import http.cookiejar
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


class Client:
    cookie_name = "PAS_SESSION"

    def __init__(self, base):
        self.base = base.rstrip('/')
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(self.jar))

    def request(self, path, data=None, cookie=None):
        encoded = None if data is None else urllib.parse.urlencode(data).encode()
        headers = {'User-Agent': 'Dalke-Z1-local-smoke/1.0'}
        if cookie is not None:
            headers['Cookie'] = f'{self.cookie_name}={cookie}'
        request = urllib.request.Request(self.base + path, data=encoded, headers=headers)
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read().decode('utf-8')

    def sid(self):
        return next((cookie.value for cookie in self.jar if cookie.name == self.cookie_name), None)

    def form(self, path):
        status, headers, body = self.request(path)
        expect(status == 200, f'{path} serves form')
        match = re.search(r'name="csrf" value="([0-9a-f]{64})"', body)
        expect(match is not None, f'{path} includes CSRF token')
        return match.group(1)


checks = 0


def expect(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1
    print(f'PASS {message}')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base-url', default='http://127.0.0.1:8081')
    parser.add_argument('--public', action='store_true', help='Read-only checks; no submissions or database access.')
    args = parser.parse_args()
    hostname = urllib.parse.urlsplit(args.base_url).hostname
    if not args.public and hostname not in ('127.0.0.1', 'localhost', '::1'):
        parser.error('Full tests require a loopback URL. Use --public for a remote site.')
    root = Path(__file__).resolve().parent.parent
    docker = shutil.which('docker') or '/Applications/Docker.app/Contents/Resources/bin/docker'

    def sql(query):
        result = subprocess.run(
            [docker, 'compose', 'exec', '-T', '-e', 'MYSQL_PWD=local-only-z1', 'db',
             'mysql', '--batch', '--skip-column-names', '-u', 'z1', '-D', 'z1', '-e', query],
            cwd=root, text=True, capture_output=True, check=True,
        )
        return result.stdout.strip()

    def clear_throttle():
        sql('TRUNCATE TABLE auth_attempts;')

    browser = Client(args.base_url)
    status, headers, body = 0, {}, '{}'
    for attempt in range(40):
        try:
            status, headers, body = browser.request('/z1/status.php')
            if status == 200:
                break
        except (urllib.error.URLError, TimeoutError):
            pass
        time.sleep(1)
    expect(status == 200 and json.loads(body) == {'status': 'ok', 'database': 'ok'}, 'database readiness')
    status, headers, body = browser.request('/')
    expect(status == 200 and '<title>dalke</title>' in body, 'root title dalke')
    expect('charset=UTF-8' in headers.get('Content-Type', ''), 'UTF-8 response')
    task_routes = ('z1', 'z2', 'z3', 'z4', 'z5', 'z6a', 'z6b')
    expect(all(f'href="/{task}/"' in body for task in task_routes), 'required task links')
    expect(headers.get('X-Content-Type-Options') == 'nosniff', 'nosniff header')
    expect('frame-ancestors' in headers.get('Content-Security-Policy', ''), 'CSP frame restriction')
    expect('no-store' in headers.get('Cache-Control', ''), 'no-store response')
    status, _, _ = browser.request('/z1/index4.php')
    expect(status == 303, 'anonymous protected page redirects')
    token = browser.form('/z1/index3.php')
    cookie = next(c for c in browser.jar if c.name == 'PAS_SESSION')
    expect(cookie.has_nonstandard_attr('HttpOnly'), 'session cookie HttpOnly')
    expect(cookie.get_nonstandard_attr('SameSite') == 'Lax', 'session cookie SameSite=Lax')
    if args.public:
        expect(cookie.secure, 'production session cookie Secure')
        expect(urllib.parse.urlsplit(args.base_url).scheme == 'https', 'public URL uses HTTPS')
    for path in ('/z1/index1.php', '/z1/weryfikuj1.php'):
        status, response_headers, body = browser.request(path)
        expect(status == 200 and '<form' not in body, f'{path} has no vulnerable public form')
    if args.public:
        print(f'OK: {checks} read-only public checks. No production accounts created.')
        return

    clear_throttle()
    username = 'smoke_' + str(time.time_ns())
    password = "Ą"
    try:
        status, _, _ = browser.request('/z1/weryfikuj3.php', {'user': 'user1', 'pass': 'pass1'})
        expect(status == 403, 'missing CSRF rejected')
        status, _, _ = browser.request('/z1/weryfikuj3.php', {'csrf': 'x' * 64, 'user': 'user1', 'pass': 'pass1'})
        expect(status == 403, 'wrong CSRF rejected')
        for account, secret in (('user1', 'pass1'), ('user2', 'pass2')):
            token = browser.form('/z1/index2.php')
            status, _, body = browser.request('/z1/weryfikuj2.php', {'csrf': token, 'user': account, 'pass': secret})
            expect(status == 200 and 'data-result="success"' in body, f'two-phase verification {account}')
            expect(secret not in body and '$2y$' not in body, 'password and hash not echoed')
            status, _, _ = browser.request('/z1/index4.php')
            expect(status == 303, 'phase 2 does not grant authenticated session')
        cases = [('user1', 'incorrect'), ('unknown_user', 'pass1'), ('user1', "' OR 1=1 -- "), ("user1' OR 1=1 -- ", 'anything')]
        for phase in (2, 3):
            clear_throttle()
            for account, secret in cases:
                token = browser.form(f'/z1/index{phase}.php')
                status, _, body = browser.request(f'/z1/weryfikuj{phase}.php', {'csrf': token, 'user': account, 'pass': secret})
                expect(status == 401 and 'nieprawidłowy login lub hasło.' in body, f'phase {phase} bad credentials and SQLi rejected')
                expect('data-result="success"' not in body, 'invalid credentials never verified')
        clear_throttle()
        token = browser.form('/z1/rejestruj.php')
        status, _, _ = browser.request('/z1/add.php', {'csrf': token, 'user': username, 'pass': password, 'pass_repeat': password + 'x'})
        expect(status == 422, 'repeat password mismatch rejected')
        status, _, _ = browser.request('/z1/add.php', {'csrf': token, 'user': username, 'pass': '', 'pass_repeat': ''})
        expect(status == 422, 'empty password rejected')
        long_password = 'x' * 73
        status, _, _ = browser.request('/z1/add.php', {'csrf': token, 'user': username, 'pass': long_password, 'pass_repeat': long_password})
        expect(status == 422, 'password exceeding bcrypt 72 bytes rejected')
        status, headers, _ = browser.request('/z1/add.php', {'csrf': token, 'user': username, 'pass': password, 'pass_repeat': password})
        expect(status == 303 and headers['Location'] == '/z1/index3.php?registered=1', 'registration succeeds with special-character password')
        expect(sql(f"SELECT COUNT(*) FROM users WHERE username='{username}' AND password <> '{password.replace(chr(39), chr(39) * 2)}' AND LENGTH(password) >= 60;") == '1', 'new password stored as hash')
        expect(sql("SELECT COUNT(*) FROM users WHERE username IN ('user1','user2') AND password IN ('pass1','pass2');") == '0', 'seed passwords stored as hashes')
        status, _, _ = browser.request('/z1/add.php', {'csrf': token, 'user': username, 'pass': password, 'pass_repeat': password})
        expect(status == 409, 'duplicate registration rejected')
        clear_throttle()
        token = browser.form('/z1/index3.php')
        old_sid = browser.sid()
        status, headers, _ = browser.request('/z1/weryfikuj3.php', {'csrf': token, 'user': username, 'pass': password})
        expect(status == 303 and headers['Location'] == '/z1/index4.php', 'new account can log in')
        new_sid = browser.sid()
        expect(bool(new_sid) and old_sid != new_sid, 'session identifier rotates after login')
        status, headers, body = browser.request('/z1/index4.php')
        expect(status == 200 and 'data-authenticated="true"' in body and username in body, 'authenticated panel accessible')
        expect('no-store' in headers.get('Cache-Control', ''), 'protected panel no-store')
        status, _, _ = Client(args.base_url).request('/z1/index4.php', cookie=old_sid)
        expect(status == 303, 'pre-login session cannot access panel')
        status, _, body = Client(args.base_url).request('/z1/index4.php', cookie=new_sid)
        expect(status == 200 and 'data-authenticated="true"' in body, 'another tab shares authenticated session')
        status, _, _ = browser.request('/z1/logout.php', {})
        expect(status == 403, 'logout without CSRF rejected')
        token = browser.form('/z1/logout.php')
        status, headers, _ = browser.request('/z1/logout.php', {'csrf': token})
        expect(status == 303 and headers['Location'] == '/z1/index3.php', 'logout clears session')
        status, _, _ = Client(args.base_url).request('/z1/index4.php', cookie=new_sid)
        expect(status == 303, 'logged-out session denied in another tab')
        clear_throttle()
        token = browser.form('/z1/index3.php')
        for attempt in range(5):
            status, _, _ = browser.request('/z1/weryfikuj3.php', {'csrf': token, 'user': 'user1', 'pass': 'wrong'})
            expect(status == 401, f'failed attempt {attempt + 1}')
        status, headers, _ = browser.request('/z1/weryfikuj3.php', {'csrf': token, 'user': 'user1', 'pass': 'pass1'})
        expect(status == 429 and int(headers.get('Retry-After', '0')) > 0, 'five failures trigger timed block')
        fresh = Client(args.base_url)
        token = fresh.form('/z1/index3.php')
        status, _, _ = fresh.request('/z1/weryfikuj3.php', {'csrf': token, 'user': 'user1', 'pass': 'pass1'})
        expect(status == 429, 'fresh cookie does not bypass database throttle')
        expect(sql('SELECT COUNT(*) FROM auth_attempts WHERE blocked_until > UNIX_TIMESTAMP();') == '1', 'throttle persisted in MySQL')
        print(f'OK: {checks} checks passed against local PHP and MySQL.')
    finally:
        sql(f"DELETE FROM users WHERE username='{username}';")
        clear_throttle()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(f'FAIL: {error}', file=sys.stderr)
        sys.exit(1)
