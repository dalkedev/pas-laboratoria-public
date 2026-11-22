from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
import urllib.parse

from z3_smoke import Browser, Document


checks = 0


def expect(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1
    print(f'PASS {message}')


class Local:
    def __init__(self, portal, checker=expect):
        parser = argparse.ArgumentParser(description=f'Local task {portal} integration checks.')
        parser.add_argument('--base-url', default='http://127.0.0.1:8081')
        args = parser.parse_args()
        origin = urllib.parse.urlsplit(args.base_url)
        if origin.scheme != 'http' or origin.hostname not in ('127.0.0.1', 'localhost', '::1') or origin.username or origin.password or origin.query or origin.fragment or origin.path not in ('', '/'):
            parser.error('Use a local loopback HTTP origin.')
        self.base = args.base_url.rstrip('/')
        self.portal = portal
        self.check = checker
        self.root = Path(__file__).resolve().parents[1]
        self.docker = shutil.which('docker') or '/Applications/Docker.app/Contents/Resources/bin/docker'
        self.prefix = f'z{portal}smoke_' + str(time.time_ns())
        self.agent = f'Z{portal}Smoke/' + self.prefix
        self.users = []
        gateway = self.command(self.docker, 'compose', 'ps', '-q', 'gateway')
        networks = json.loads(self.command(self.docker, 'inspect', '--format', '{{json .NetworkSettings.Networks}}', gateway))
        gateway_ip = next(item['IPAddress'] for name, item in networks.items() if name.endswith('_secure'))
        buckets = [hashlib.sha256(f'{scope}|{gateway_ip}'.encode()).hexdigest() for scope in ('login', 'registration')]
        self.bucket_sql = ','.join("'" + item + "'" for item in buckets)
        self.throttle = self.sql(f'SELECT bucket,failures,window_started,blocked_until FROM auth_attempts WHERE bucket IN ({self.bucket_sql});')
        self.sql(f'DELETE FROM auth_attempts WHERE bucket IN ({self.bucket_sql});')

    def command(self, *arguments):
        result = subprocess.run(arguments, cwd=self.root, text=True, capture_output=True, timeout=40)
        if result.returncode:
            raise AssertionError('A local Docker/database fixture command failed.')
        return result.stdout.strip()

    def sql(self, query):
        task = f'z{self.portal}'
        return self.command(self.docker, 'compose', 'exec', '-T', '-e', f'MYSQL_PWD=local-only-{task}', 'db', 'mysql', '--batch', '--skip-column-names', '-u', task, '-D', task, '-e', query)

    def php(self, script, *arguments):
        return self.command(self.docker, 'compose', 'exec', '-T', 'app', 'php', '-r', script, '--', *arguments)

    def browser(self, label):
        return Browser(self.base, self.agent + '/' + label, task=f'z{self.portal}')

    def csrf(self, browser, path=None):
        status, _, body = browser.page(path or f'/z{self.portal}/')
        if status != 200:
            raise AssertionError(f'Unable to load a local task {self.portal} form: HTTP {status}.')
        csrf = Document(body).meta.get('csrf-token')
        if csrf is None:
            match = re.search(r'name="csrf" value="([0-9a-f]{64})"', body)
            csrf = match.group(1) if match else None
        if not csrf or not re.fullmatch('[0-9a-f]{64}', csrf):
            raise AssertionError('Expected a CSRF token in the form.')
        return csrf

    def register(self, browser, username=None, **extra):
        username = username or self.prefix + '_' + str(len(self.users))
        csrf = self.csrf(browser, f'/z{self.portal}/rejestruj.php')
        status, headers, _ = browser.request(f'/z{self.portal}/add.php', fields={'csrf': csrf, 'user': username, 'pass': 't3st', 'pass_repeat': 't3st', **extra})
        self.check(status == 303 and headers.get('Location') == f'/z{self.portal}/logowanie.php?registered=1', f'task {self.portal} registers a disposable account')
        self.users.append(username)
        user_id = self.sql(f"SELECT id FROM users WHERE username='{username}';")
        if not user_id.isdigit():
            raise AssertionError('Registered test account is missing.')
        return int(user_id)

    def login(self, browser, username):
        csrf = self.csrf(browser, f'/z{self.portal}/logowanie.php')
        before = browser.sid()
        status, headers, _ = browser.request(f'/z{self.portal}/weryfikuj.php', fields={'csrf': csrf, 'user': username, 'pass': 't3st'})
        self.check(status == 303 and headers.get('Location') == f'/z{self.portal}/' and browser.sid() != before, f'task {self.portal} logs in and rotates its session ID')

    def cleanup(self):
        self.sql(f"DELETE FROM goscieportalu WHERE user_agent LIKE '{self.agent}%';")
        if self.users:
            users = ','.join("'" + user + "'" for user in self.users)
            self.sql(f'DELETE FROM users WHERE username IN ({users});')
        self.sql(f'DELETE FROM auth_attempts WHERE bucket IN ({self.bucket_sql});')
        if self.throttle:
            values = []
            for row in self.throttle.splitlines():
                bucket, failures, window, blocked = row.split('\t')
                values.append(f"('{bucket}',{int(failures)},{int(window)},{int(blocked)})")
            self.sql('INSERT INTO auth_attempts(bucket,failures,window_started,blocked_until) VALUES ' + ','.join(values) + ';')


def main():
    context = Local(4)
    first, second = context.browser('first'), context.browser('second')
    try:
        for path in ('/', '/index1.php', '/dodaj1.php', '/kasuj1.php', '/edit.php?id=1', '/header.php'):
            status, headers, body = context.browser('anonymous').request('/z4' + path)
            expect(status == 303 and headers.get('Location') == '/z4/logowanie.php' and b'<nav' not in body, f'anonymous access denied: {path}')
        first_id = context.register(first)
        first_user = context.users[-1]
        second_id = context.register(second, worker='1')
        second_user = context.users[-1]
        expect(context.sql(f'SELECT worker FROM users WHERE id={second_id};') == '1', 'worker registration records the worker role')
        context.login(first, first_user)
        context.login(second, second_user)
        csrf = context.csrf(first, '/z4/dodaj1.php')
        status, _, _ = first.request('/z4/dodaj2.php', fields={'host': '127.0.0.1', 'port': '80'})
        expect(status == 403, 'adding a monitored service requires CSRF')
        status, _, _ = first.request('/z4/probe.php', fields={})
        expect(status == 403, 'probing monitored services requires CSRF')
        status, _, _ = first.request('/z4/staff.php')
        expect(status == 403, 'ordinary owners cannot open the administrator assignment form')
        for host, port in (('127.0.0.1', '80'), ('127.0.0.1', '65534')):
            status, _, _ = first.request('/z4/dodaj2.php', fields={'csrf': csrf, 'host': host, 'port': port, 'user_id': second_id})
            expect(status == 303, f'owner adds exact host and port pair {host}:{port}')
        status, _, _ = first.request('/z4/dodaj2.php', fields={'csrf': csrf, 'host': '127.0.0.1', 'port': '80'})
        expect(status == 409, 'duplicate owner host and port pair is rejected')
        expect(context.sql(f'SELECT COUNT(*) FROM domeny WHERE user_id={first_id};') == '2', 'service ownership comes from the session rather than a submitted user ID')
        other_csrf = context.csrf(second, '/z4/dodaj1.php')
        status, _, _ = second.request('/z4/dodaj2.php', fields={'csrf': other_csrf, 'host': '127.0.0.1', 'port': '80'})
        expect(status == 303, 'a second owner can monitor the same host and port independently')
        rows = context.sql(f'SELECT id,port FROM domeny WHERE user_id={first_id} ORDER BY port;').splitlines()
        open_id, closed_id = [int(row.split('\t')[0]) for row in rows]
        other_id = int(context.sql(f'SELECT id FROM domeny WHERE user_id={second_id};'))
        for client, own_ids, hidden_ids in ((first, (open_id, closed_id), (other_id,)), (second, (other_id,), (open_id, closed_id))):
            status, _, page = client.page('/z4/index1.php')
            expect(status == 200, 'authenticated owner can read the monitor')
            expect(all(re.search(fr'data-(?:domain|domena|service)-id="{value}"|name="id" value="{value}"|[?&]id={value}(?:&|"|\s)', page) for value in own_ids), 'monitor contains the current owner services')
            expect(not any(re.search(fr'data-(?:domain|domena|service)-id="{value}"|name="id" value="{value}"|[?&]id={value}(?:&|"|\s)', page) for value in hidden_ids), 'monitor omits another owner services')
        status, _, _ = second.request('/z4/edit.php', fields={'csrf': other_csrf, 'id': open_id, 'host': 'localhost', 'port': '443'})
        expect(status in (403, 404), 'another owner cannot edit a service by guessed ID')
        status, _, _ = second.request('/z4/probe.php', fields={'csrf': other_csrf, 'id': closed_id})
        expect(status in (403, 404), 'another owner cannot probe a service by guessed ID')
        for host, port in (('bad/host', '80'), ('127.0.0.1', '0'), ('127.0.0.1', '65536'), ("x' OR 1=1 --", '80')):
            status, _, _ = first.request('/z4/dodaj2.php', fields={'csrf': csrf, 'host': host, 'port': port})
            expect(status == 422, f'invalid service specification is rejected: {host}:{port}')

        context.sql(f'UPDATE domeny SET checked_at=NULL,errors=0,total_errors=0,failure=NULL WHERE id={closed_id};')
        for expected_errors in (1, 2):
            context.sql(f'UPDATE domeny SET checked_at=NULL WHERE id={closed_id};')
            status, headers, _ = first.request('/z4/probe.php', fields={'csrf': csrf, 'id': closed_id})
            state = context.sql(f'SELECT errors,total_errors,failure IS NOT NULL FROM domeny WHERE id={closed_id};')
            expect(status == 200 and headers.get_content_type() == 'application/json' and state == f'{expected_errors}\t{expected_errors}\t1', 'failed local TCP checks increment consecutive and total errors and record outage start')
        failure_started = context.sql(f'SELECT failure FROM domeny WHERE id={closed_id};')
        status, _, _ = first.request('/z4/probe.php', fields={'csrf': csrf, 'id': closed_id})
        expect(status == 200 and context.sql(f'SELECT errors FROM domeny WHERE id={closed_id};') == '2' and context.sql(f'SELECT failure FROM domeny WHERE id={closed_id};') == failure_started, 'ten-second lease avoids duplicate counts and preserves outage start')
        context.sql(f'UPDATE domeny SET errors=2,total_errors=7,failure=NOW(),checked_at=NULL WHERE id={open_id};')
        status, _, _ = first.request('/z4/probe.php', fields={'csrf': csrf, 'id': open_id})
        expect(status == 200 and context.sql(f'SELECT errors,total_errors,failure IS NULL FROM domeny WHERE id={open_id};') == '0\t7\t1', 'successful local TCP check resets the current outage while retaining total failures')
        expect(int(context.sql(f'SELECT COUNT(*) FROM z4_probe_history WHERE domena_id IN ({open_id},{closed_id});')) >= 3, 'probe results are retained in service history')
        status, _, own_history = first.page('/z4/history.php')
        other_status, _, other_history = second.page('/z4/history.php')
        expect(status == other_status == 200 and '65534' in own_history and '65534' not in other_history, 'probe history remains scoped to its owner')
        status, _, _ = first.request('/z4/dodaj2.php', fields={'csrf': csrf, 'host': '192.0.2.123', 'port': '80'})
        expect(status == 303, 'owner can save a service outside the probe allowlist')
        denied_id = int(context.sql(f"SELECT id FROM domeny WHERE user_id={first_id} AND host='192.0.2.123';"))
        status, _, _ = first.request('/z4/probe.php', fields={'csrf': csrf, 'id': denied_id})
        expect(status == 200 and 'niedozwol' in context.sql(f'SELECT status FROM domeny WHERE id={denied_id};').lower(), 'unapproved target is refused without a public network probe')
        status, _, _ = first.request('/z4/edit.php', fields={'csrf': csrf, 'id': denied_id, 'host': 'localhost', 'port': '443'})
        expect(status == 303 and context.sql(f'SELECT host,port FROM domeny WHERE id={denied_id};') == 'localhost\t443', 'owner can update an exact monitored host and port pair')

        if context.sql("SELECT COUNT(*) FROM users WHERE username='admin';") == '0':
            admin = context.browser('local-admin')
            context.register(admin, username='admin')
            context.login(admin, 'admin')
            status, _, admin_page = admin.page('/z4/index1.php')
            expect(status == 200 and first_user in admin_page and second_user in admin_page, 'disposable local admin can see services from both owners')
            status, _, _ = admin.request('/z4/staff.php', fields={'csrf': context.csrf(admin), 'host': 'localhost', 'port': '80', 'user_id': second_id})
            expect(status == 303 and context.sql(f"SELECT COUNT(*) FROM domeny WHERE user_id={second_id} AND host='localhost' AND port=80;") == '1', 'local administrator can assign a service to a registered worker')
        else:
            raise AssertionError('Local admin fixture requires an unused admin username; existing account was preserved.')
        status, _, _ = first.request('/z4/kasuj2.php', fields={'host': '127.0.0.1', 'port': '65534'})
        expect(status == 403, 'deleting a monitored service requires CSRF')
        status, _, _ = first.request('/z4/kasuj2.php', fields={'csrf': csrf, 'host': '127.0.0.1', 'port': '65534'})
        expect(status == 303 and context.sql(f'SELECT COUNT(*) FROM domeny WHERE id={closed_id};') == '0' and context.sql(f'SELECT COUNT(*) FROM domeny WHERE id={open_id};') == '1', 'exact-pair deletion removes one port and keeps the other service')
        expect(context.sql(f'SELECT COUNT(*) FROM domeny WHERE id={other_id};') == '1', 'deleting an exact pair does not delete another owner matching pair')
        print(f'OK: {checks} task 4 checks passed against local PHP and MySQL.')
    finally:
        context.cleanup()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(f'FAIL: {error}', file=sys.stderr)
        sys.exit(1)
