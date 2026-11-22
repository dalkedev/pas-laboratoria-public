from __future__ import annotations

import argparse
import hashlib
import http.cookiejar
from html.parser import HTMLParser
import ipaddress
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

from smoke import NoRedirect


checks = 0
PROTECTED = ['/z3/', '/z3/header.php', '/z3/avatar.php', '/z3/visits.php'] + [f'/z3/index{number}.php' for number in range(1, 10)]


def expect(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1
    print(f'PASS {message}')


class Document(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.elements = []
        self.meta = {}
        self.rows = []
        self.row = None
        self.text = []
        self.feed(html)

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        self.elements.append((tag, attributes))
        if tag == 'meta' and 'name' in attributes:
            self.meta[attributes['name']] = attributes.get('content', '')
        if tag == 'tr' and 'data-visit-id' in attributes:
            self.row = {'id': int(attributes['data-visit-id']), 'text': ''}

    def handle_endtag(self, tag):
        if tag == 'tr' and self.row is not None:
            self.rows.append(self.row)
            self.row = None

    def handle_data(self, data):
        self.text.append(data)
        if self.row is not None:
            self.row['text'] += data + ' '


class Browser:
    def __init__(self, base, agent, task="z3"):
        self.base = base.rstrip('/')
        self.agent = agent
        self.cookie_name = f"PAS_{task.upper()}_SESSION"
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPCookieProcessor(self.jar))

    def request(self, path, fields=None, payload=None, media_type=None, headers=None, cookie=None):
        request_headers = {'User-Agent': self.agent}
        request_headers.update(headers or {})
        if fields is not None:
            payload = urllib.parse.urlencode(fields).encode()
            request_headers['Content-Type'] = 'application/x-www-form-urlencoded'
        if media_type:
            request_headers['Content-Type'] = media_type
        if cookie is not None:
            request_headers['Cookie'] = f'{self.cookie_name}={cookie}'
        request = urllib.request.Request(self.base + path, data=payload, headers=request_headers)
        try:
            response = self.opener.open(request, timeout=25)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read()

    def page(self, path, **arguments):
        status, headers, body = self.request(path, **arguments)
        return status, headers, body.decode('utf-8')

    def sid(self):
        return next((cookie.value for cookie in self.jar if cookie.name == self.cookie_name), None)

    def metrics(self, data):
        return self.request('/z3/visit.php', payload=json.dumps(data).encode(), media_type='application/json')


def tokens(body):
    meta = Document(body).meta
    result = {'visit_id': meta.get('z3-visit-id'), 'visit_nonce': meta.get('z3-visit-nonce'), 'csrf': meta.get('csrf-token')}
    expect(bool(re.fullmatch(r'[1-9][0-9]*', result['visit_id'] or '')) and all(re.fullmatch(r'[0-9a-f]{64}', result[key] or '') for key in ('visit_nonce', 'csrf')), 'page supplies a session-bound visit ID, nonce and CSRF token')
    return result


def main():
    parser = argparse.ArgumentParser(description='Local task 3 visit logging, metrics, diagnostics and access-control checks.')
    parser.add_argument('--base-url', default='http://127.0.0.1:8081')
    args = parser.parse_args()
    origin = urllib.parse.urlsplit(args.base_url)
    if origin.scheme != 'http' or origin.hostname not in ('localhost', '127.0.0.1', '::1') or origin.username or origin.password or origin.query or origin.fragment or origin.path not in ('', '/'):
        parser.error('Task 3 integration checks require a local loopback HTTP origin.')
    root = Path(__file__).resolve().parents[1]
    docker = shutil.which('docker') or '/Applications/Docker.app/Contents/Resources/bin/docker'
    prefix = 'z3smoke_' + str(time.time_ns())
    agent = 'Z3Smoke/' + prefix
    users = [prefix + suffix for suffix in ('_a', '_b', '_c')]
    password = 'x'

    def command(*arguments):
        result = subprocess.run(arguments, cwd=root, text=True, capture_output=True, timeout=35)
        if result.returncode:
            raise AssertionError('Local task 3 Docker/database command failed; check the local schema and running services.')
        return result.stdout.strip()

    def sql(query):
        return command(docker, 'compose', 'exec', '-T', '-e', 'MYSQL_PWD=local-only-z3', 'db', 'mysql', '--batch', '--skip-column-names', '-u', 'z3', '-D', 'z3', '-e', query)

    gateway_id = command(docker, 'compose', 'ps', '-q', 'gateway')
    networks = json.loads(command(docker, 'inspect', '--format', '{{json .NetworkSettings.Networks}}', gateway_id))
    gateway_ip = next(value['IPAddress'] for name, value in networks.items() if name.endswith('_secure'))
    buckets = [hashlib.sha256(f'{scope}|{gateway_ip}'.encode()).hexdigest() for scope in ('login', 'registration')]
    bucket_sql = ','.join("'" + bucket + "'" for bucket in buckets)
    prior_throttle = sql(f'SELECT bucket,failures,window_started,blocked_until FROM auth_attempts WHERE bucket IN ({bucket_sql});')
    sql(f'DELETE FROM auth_attempts WHERE bucket IN ({bucket_sql});')

    def fresh_visit(browser):
        status, _, body = browser.page('/z3/logowanie.php')
        expect(status == 200, 'guest login page is available and records a visit')
        return tokens(body)

    def metrics_payload(visit):
        return {**visit, 'browser': 'Z3 Test Browser', 'screen_width': 1920, 'screen_height': 1080, 'viewport_width': 1440, 'viewport_height': 900, 'colors': 24, 'cookies_enabled': True, 'java_enabled': False, 'language': 'pl-PL'}

    def metric_status(browser, data, expected, label):
        status, headers, body = browser.metrics(data)
        expect(status == expected and headers.get_content_type() == 'application/json', f'{label}: HTTP {status}')
        result = json.loads(body)
        expect(isinstance(result.get('status'), str), 'metrics response is structured JSON')
        return result

    def deny(browser, label, cookie=None):
        for path in PROTECTED:
            status, headers, body = browser.request(path, cookie=cookie)
            expect(status == 303 and headers.get('Location') == '/z3/logowanie.php' and b'<nav' not in body and b'data-authenticated="true"' not in body, f'{label}: {path}')

    try:
        guest = Browser(args.base_url, agent + ' <script>alert("ua")</script>')
        deny(guest, 'anonymous request cannot load protected content or navbar')
        status, _, body = guest.page('/z3/logowanie.php?ipaddress=203.0.113.19&datetime=2000-01-01', headers={'X-Real-IP': '198.51.100.77', 'X-Forwarded-For': '203.0.113.19'})
        expect(status == 200, 'public login page accepts an ordinary guest request')
        visit = tokens(body)
        row = sql(f"SELECT ipaddress,TIMESTAMPDIFF(SECOND,datetime,NOW()),browser,screen_width IS NULL FROM goscieportalu WHERE id={visit['visit_id']};").split('\t')
        expect(ipaddress.ip_address(row[0]).is_private and row[0] not in ('198.51.100.77', '203.0.113.19'), 'visit IP comes from the server, ignoring forged forwarding and query values')
        expect(abs(int(row[1])) < 60 and row[3] == '1', 'visit timestamp is current and browser metrics initially remain unknown')
        expect(sql(f"SELECT COUNT(*) FROM goscieportalu WHERE id={visit['visit_id']} AND user_agent LIKE 'Z3Smoke/{prefix}%';") == '1', 'server records the actual User-Agent')
        expect(sql("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='goscieportalu' AND COLUMN_NAME='ipaddress';") == '45', 'IP column supports full IPv6 text')

        valid = metrics_payload(visit)
        for missing in ('csrf', 'visit_nonce'):
            invalid = dict(valid)
            invalid[missing] = '0' * 64
            metric_status(guest, invalid, 403, f'forged {missing} is rejected')
        stranger = Browser(args.base_url, agent + '/stranger')
        stranger_visit = fresh_visit(stranger)
        metric_status(stranger, {**valid, 'csrf': stranger_visit['csrf']}, 403, 'another session cannot update a known visit ID and nonce')
        metric_status(Browser(args.base_url, agent + '/no-cookie'), valid, 403, 'metrics from a session without the visit are rejected')
        for key, value in (('screen_width', 0), ('screen_height', 65536), ('viewport_width', '1440'), ('viewport_height', 2.5), ('colors', 65), ('cookies_enabled', 1), ('java_enabled', 'false'), ('language', '<script>'), ('browser', 'x' * 129)):
            metric_status(guest, {**valid, key: value}, 422, f'invalid metric {key} is rejected')
        expect(sql(f"SELECT screen_width IS NULL FROM goscieportalu WHERE id={visit['visit_id']};") == '1', 'rejected metrics do not partially mutate the visit')
        for payload, media_type, expected in ((b'{', 'application/json', 400), (b'"scalar"', 'application/json', 400), (b'{}', 'text/plain', 415), (b'x' * 4097, 'application/json', 413)):
            status, _, _ = guest.request('/z3/visit.php', payload=payload, media_type=media_type)
            expect(status == expected, f'metrics reject malformed/type/oversize body with HTTP {expected}')
        status, headers, _ = guest.request('/z3/visit.php')
        expect(status == 405 and headers.get('Allow') == 'POST', 'metrics endpoint requires POST')
        original_ip, original_datetime = sql(f"SELECT ipaddress,datetime FROM goscieportalu WHERE id={visit['visit_id']};").split('\t')
        stored_browser = '<img src=x onerror=alert(1)> ' + prefix
        result = metric_status(guest, {**valid, 'browser': stored_browser, 'ipaddress': '203.0.113.20', 'datetime': '2000-01-01 00:00:00'}, 200, 'guest can submit valid metrics for their own visit')
        expect(result['status'] == 'ok', 'valid metrics are acknowledged')
        persisted = sql(f"SELECT screen_width,screen_height,viewport_width,viewport_height,colors,cookies_enabled,java_enabled,language FROM goscieportalu WHERE id={visit['visit_id']};")
        expect(persisted == '1920\t1080\t1440\t900\t24\t1\t0\tpl-PL', 'all browser metrics persist with correct types')
        expect(sql(f"SELECT ipaddress,datetime FROM goscieportalu WHERE id={visit['visit_id']};") == original_ip + '\t' + original_datetime, 'client metrics cannot overwrite server IP or timestamp')
        metric_status(guest, valid, 403, 'consumed visit nonce cannot be replayed')
        first = fresh_visit(guest)
        latest = None
        for _ in range(20):
            status, _, page = guest.page('/z3/logowanie.php')
            if status != 200:
                raise AssertionError('Unable to create bounded visit-token test pages.')
            meta = Document(page).meta
            latest = {'visit_id': meta['z3-visit-id'], 'visit_nonce': meta['z3-visit-nonce'], 'csrf': meta['csrf-token']}
        metric_status(guest, metrics_payload(first), 403, 'oldest pending visit token is evicted beyond the 20-entry bound')
        metric_status(guest, metrics_payload(latest), 200, 'newest pending visit token remains valid')

        browsers = [Browser(args.base_url, agent + '/' + str(number)) for number in range(3)]
        for browser, user in zip(browsers, users):
            status, _, page = browser.page('/z3/rejestruj.php')
            expect(status == 200, 'task 3 registration form is public')
            csrf = tokens(page)['csrf']
            status, headers, _ = browser.request('/z3/add.php', fields={'csrf': csrf, 'user': user, 'pass': password, 'pass_repeat': password})
            expect(status == 303 and headers.get('Location') == '/z3/logowanie.php?registered=1', 'task 3 registers a separate account with a four-character password')
        browser = browsers[0]
        status, _, page = browser.page('/z3/logowanie.php')
        csrf = tokens(page)['csrf']
        prior_sid = browser.sid()
        status, headers, _ = browser.request('/z3/weryfikuj.php', fields={'csrf': csrf, 'user': users[0], 'pass': password})
        expect(status == 303 and headers.get('Location') == '/z3/' and browser.sid() != prior_sid, 'task 3 login succeeds and rotates the session ID')
        active_sid = browser.sid()
        deny(Browser(args.base_url, agent + '/second-browser'), 'copied protected URL in a new browser is denied')

        status, _, header = browser.page('/z3/header.php')
        expect(status == 200 and '<nav' in header and users[0] in header, 'authenticated navbar identifies the current user')
        for number in range(1, 10):
            expect(f'/z3/index{number}.php' in header, f'navbar links to diagnostic/visitor page {number}')
        current_count = sql(f"SELECT COUNT(*) FROM goscieportalu WHERE user_agent LIKE 'Z3Smoke/{prefix}%';")
        for path in ('/z3/header.php', '/z3/avatar.php', '/z3/twoj_js.js', '/z3/twoj_css.css', '/z3/vendor/ua-parser.min.js'):
            status, _, _ = browser.request(path)
            expect(status == 200, f'authenticated or static resource available: {path}')
        expect(sql(f"SELECT COUNT(*) FROM goscieportalu WHERE user_agent LIKE 'Z3Smoke/{prefix}%';") == current_count, 'navbar, avatar and static resources do not create duplicate visits')
        status, headers, javascript = browser.request('/z3/twoj_js.js')
        expect(headers.get_content_type() in ('text/javascript', 'application/javascript') and all(value in javascript for value in (b'UAParser', b'screen.width', b'navigator.cookieEnabled', b'navigator.javaEnabled', b'/z3/visit.php')), 'browser script collects real browser and screen metrics')
        for number in range(1, 9):
            status, headers, diagnostic = browser.page(f'/z3/index{number}.php')
            expect(status == 200 and 'data-authenticated="true"' in diagnostic and 'no-store' in headers.get('Cache-Control', ''), f'diagnostic page {number} renders only with an authenticated session')
            expect(not any(secret in diagnostic for secret in ('local-only-z3', 'local-only-root', active_sid, 'DB_PASSWORD', 'TOX_FTPS_PASSWORD')), f'diagnostic page {number} does not expose environment secrets or the session ID')
        for number in (1, 3, 4, 5, 6, 7, 8):
            status, _, output = browser.page(f'/z3/index{number}.php?host=203.0.113.254&port=4444&cmd=printf+{prefix}&ip=203.0.113.254&path=/etc/passwd')
            expect(status == 200 and prefix not in output and '203.0.113.254' not in output and 'root:x:' not in output, f'diagnostic page {number} ignores arbitrary command, target, port and path arguments')

        fixture_agent = agent + '/fixture'
        fixture_ids = []
        for number in range(1, 13):
            fixture_ip = 'fd00:0000:0000:0000:0000:0000:0000:' + f'{number:04x}'
            fixture_browser = prefix + f'_fixture_{number:02d}'
            sql(f"INSERT INTO goscieportalu(ipaddress,datetime,browser,user_agent,screen_width,screen_height,viewport_width,viewport_height,colors,cookies_enabled,java_enabled,language) VALUES ('{fixture_ip}','2026-01-01 00:00:{number:02d}','{fixture_browser}','{fixture_agent}',1920,1080,1440,900,24,1,0,'pl-PL');")
        fixture_ids = [int(value) for value in sql(f"SELECT id FROM goscieportalu WHERE user_agent='{fixture_agent}' ORDER BY id;").splitlines()]
        expect(len(fixture_ids) == 12, 'isolated IPv6 visitor fixtures were inserted without touching other visitors')
        query = urllib.parse.urlencode({'q': prefix + '_fixture_', 'sort': 'id', 'dir': 'asc', 'page': 1})
        status, _, first_page = browser.page('/z3/visits.php?' + query)
        expect(status == 200 and [row['id'] for row in Document(first_page).rows] == fixture_ids[:10], 'visitor filter, ascending sort and ten-row first page work')
        query = urllib.parse.urlencode({'q': prefix + '_fixture_', 'sort': 'id', 'dir': 'asc', 'page': 2})
        status, _, second_page = browser.page('/z3/visits.php?' + query)
        expect(status == 200 and [row['id'] for row in Document(second_page).rows] == fixture_ids[10:], 'visitor pagination returns the remaining filtered rows')
        query = urllib.parse.urlencode({'q': prefix + '_fixture_', 'sort': 'browser', 'dir': 'desc', 'page': 1})
        status, _, descending = browser.page('/z3/visits.php?' + query)
        expect(status == 200 and [row['id'] for row in Document(descending).rows] == list(reversed(fixture_ids))[:10], 'whitelisted browser sorting supports descending order')
        query = urllib.parse.urlencode({'q': "' OR 1=1 -- ", 'sort': 'id DESC; SELECT 1 --', 'dir': 'asc;SELECT 1', 'page': -4})
        status, _, injected = browser.page('/z3/visits.php?' + query)
        expect(status == 200 and not Document(injected).rows, 'visitor filtering and sort parameters treat SQL injection strings as data')
        query = urllib.parse.urlencode({'q': '<img src=x onerror=alert(1)>', 'sort': 'id', 'dir': 'desc'})
        status, _, escaped = browser.page('/z3/visits.php?' + query)
        expect(status == 200 and stored_browser not in escaped and '&lt;img' in escaped and visit['visit_id'] in escaped, 'stored browser labels are HTML-escaped in visitor history')
        status, _, escaped_ua = browser.page('/z3/visits.php?' + urllib.parse.urlencode({'q': agent + ' <script>', 'sort': 'id', 'dir': 'asc'}))
        expect(status == 200 and '<script>alert("ua")</script>' not in escaped_ua, 'stored User-Agent text cannot become executable HTML')
        status, _, local_rows = browser.page('/z3/visits.php?' + urllib.parse.urlencode({'q': prefix + '_fixture_01'}))
        expect(status == 200 and 'fd00:0000:0000:0000:0000:0000:0000:0001' in local_rows and 'maps/place/' not in local_rows and 'maps?q=' not in local_rows, 'private IPv6 visitor has no invented geolocation map link')
        status, _, alias = browser.page('/z3/index9.php?' + urllib.parse.urlencode({'q': prefix + '_fixture_01'}))
        expect(status == 200 and any(row['id'] == fixture_ids[0] for row in Document(alias).rows), 'navbar index9 renders the visitor-history table')

        csrf_match = re.search(r'name="csrf" value="([0-9a-f]{64})"', header)
        expect(csrf_match is not None, 'navbar provides a logout CSRF token')
        status, _, _ = browser.request('/z3/logout.php', fields={})
        expect(status == 403, 'task 3 logout rejects missing CSRF')
        status, headers, _ = browser.request('/z3/logout.php', fields={'csrf': csrf_match.group(1)})
        expect(status == 303 and headers.get('Location') == '/z3/logowanie.php', 'task 3 logout destroys the authenticated session')
        deny(Browser(args.base_url, agent + '/logged-out'), 'old session cannot access protected pages after logout', cookie=active_sid)
        expect(sql(f"SELECT COUNT(*) FROM users WHERE username IN ('{users[0]}','{users[1]}','{users[2]}');") == '3', 'hostile filter and diagnostic requests did not modify user accounts')
        print(f'OK: {checks} task 3 checks passed against local PHP and MySQL.')
    finally:
        sql(f"DELETE FROM goscieportalu WHERE user_agent LIKE 'Z3Smoke/{prefix}%';")
        user_list = ','.join("'" + user + "'" for user in users)
        sql(f'DELETE FROM users WHERE username IN ({user_list});')
        sql(f'DELETE FROM auth_attempts WHERE bucket IN ({bucket_sql});')
        if prior_throttle:
            values = []
            for line in prior_throttle.splitlines():
                bucket, failures, window, blocked = line.split('\t')
                values.append(f"('{bucket}',{int(failures)},{int(window)},{int(blocked)})")
            sql('INSERT INTO auth_attempts(bucket,failures,window_started,blocked_until) VALUES ' + ','.join(values) + ';')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(f'FAIL: {error}', file=sys.stderr)
        sys.exit(1)
