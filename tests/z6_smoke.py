from __future__ import annotations

import argparse
import base64
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
import urllib.parse
import uuid

from z3_smoke import Browser, Document
from z5_smoke import MEDIA_B64


checks = 0


def expect(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1
    print(f'PASS {message}', flush=True)


class Queue(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.active = False
        self.data = ''
        self.feed(html)

    def handle_starttag(self, tag, attributes):
        if tag == 'script' and 'data-player-queue' in dict(attributes):
            self.active = True

    def handle_endtag(self, tag):
        if tag == 'script':
            self.active = False

    def handle_data(self, data):
        if self.active:
            self.data += data


class LocalMedia:
    def __init__(self, base, task, prefix):
        self.base, self.task, self.prefix = base, task, prefix
        self.video = task == 'z6b'
        self.table, self.key = ('film', 'idf') if self.video else ('song', 'ids')
        self.genre_key = 'idft' if self.video else 'idmt'
        self.creator_key = 'director' if self.video else 'musician'
        self.text_key = 'subtitles' if self.video else 'lyrics'
        self.filename = 'clip.mp4' if self.video else 'tone.mp3'
        self.content = base64.b64decode(MEDIA_B64[self.filename])
        self.password = 'test-' + task
        self.users = []
        self.root = Path(__file__).resolve().parents[1]
        self.docker = shutil.which('docker') or '/Applications/Docker.app/Contents/Resources/bin/docker'
        self.agent = 'MediaSmoke/' + prefix + '/' + task
        gateway = self.command(self.docker, 'compose', 'ps', '-q', 'gateway')
        networks = json.loads(self.command(self.docker, 'inspect', '--format', '{{json .NetworkSettings.Networks}}', gateway))
        gateway_ip = next(item['IPAddress'] for name, item in networks.items() if name.endswith('_secure'))
        self.buckets = ','.join("'" + hashlib.sha256(f'{scope}|{gateway_ip}'.encode()).hexdigest() + "'" for scope in ('login', 'registration'))
        self.throttle = self.sql(f'SELECT bucket,failures,window_started,blocked_until FROM auth_attempts WHERE bucket IN ({self.buckets});')
        self.sql(f'DELETE FROM auth_attempts WHERE bucket IN ({self.buckets});')

    def command(self, *arguments):
        result = subprocess.run(arguments, cwd=self.root, text=True, capture_output=True, timeout=40)
        if result.returncode:
            raise AssertionError(f'{self.task}: local fixture command failed.')
        return result.stdout.strip()

    def sql(self, query):
        return self.command(self.docker, 'compose', 'exec', '-T', '-e', f'MYSQL_PWD=local-only-{self.task}', 'db', 'mysql', '--batch', '--skip-column-names', '-u', self.task, '-D', self.task, '-e', query)

    def browser(self, label):
        return Browser(self.base, self.agent + '/' + label, task=self.task)

    def csrf(self, browser, path=''):
        status, _, page = browser.page(f'/{self.task}/' + path)
        if status != 200:
            raise AssertionError(f'{self.task}: unable to load a form: HTTP {status}.')
        token = Document(page).meta.get('csrf-token')
        if not token or not re.fullmatch('[0-9a-f]{64}', token):
            raise AssertionError('Missing form CSRF token.')
        return token

    def post(self, browser, endpoint, **fields):
        return browser.request(f'/{self.task}/{endpoint}', fields={'csrf': self.csrf(browser), **fields})

    def register(self, browser, user):
        token = self.csrf(browser, 'register.php')
        status, headers, _ = browser.request(f'/{self.task}/add.php', fields={'csrf': token, 'user': user, 'pass': self.password, 'pass_repeat': self.password})
        expect(status == 303 and 'login.php' in headers.get('Location', ''), f'{self.task}: a separate account registers')
        self.users.append(user)

    def login(self, browser, user):
        token = self.csrf(browser, 'login.php')
        before = browser.sid()
        status, headers, _ = browser.request(f'/{self.task}/verify.php', fields={'csrf': token, 'user': user, 'pass': self.password})
        expect(status == 303 and headers.get('Location') == f'/{self.task}/' and browser.sid() != before, f'{self.task}: login rotates its task session')
        cookie = next(item for item in browser.jar if item.name == browser.cookie_name)
        expect(cookie.path == f'/{self.task}/' and cookie.has_nonstandard_attr('HttpOnly'), f'{self.task}: session cookie is scoped to this task')

    def upload(self, browser, title, content=None, token=None):
        boundary = 'media-' + uuid.uuid4().hex
        text = 'WEBVTT\n\n00:00.000 --> 00:01.000\nLocal subtitle\n' if self.video else 'Local lyrics <b>remain text</b>'
        fields = {'csrf': self.csrf(browser, 'select.php') if token is None else token, 'title': title, self.creator_key: 'Local test creator', self.genre_key: '1', self.text_key: text}
        parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode() for key, value in fields.items()]
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{self.filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + (self.content if content is None else content) + b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode())
        return browser.request(f'/{self.task}/upload.php', payload=b''.join(parts), media_type='multipart/form-data; boundary=' + boundary)

    def playlist(self, browser, name, public=False):
        status, headers, _ = self.post(browser, 'playlist_save.php', name=name, **({'public': '1'} if public else {}))
        match = re.search(r'[?&]id=([0-9]+)', headers.get('Location', ''))
        expect(status == 303 and match is not None, f'{self.task}: playlist is created')
        return int(match.group(1))

    def cleanup(self):
        if self.users:
            users = ','.join("'" + user + "'" for user in self.users)
            filenames = self.sql(f'SELECT m.filename FROM {self.table} m JOIN users u ON u.id=m.idu WHERE u.username IN ({users});').splitlines()
            if filenames:
                folder, extension = ('films', 'mp4') if self.video else ('songs', 'mp3')
                code = 'require "/var/www/html/app/bootstrap.php";$root=rtrim(config("MEDIA_STORAGE_ROOT","/var/www/html/storage"),"/");$files=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);foreach($files as $name){if(!preg_match("/^[a-f0-9]{48}\\.' + extension + '$/D",$name))throw new RuntimeException("Invalid fixture file.");$path=$root."/' + folder + '/".$name;if(is_file($path)&&!is_link($path)&&!unlink($path))throw new RuntimeException("Fixture cleanup failed.");}'
                self.command(self.docker, 'compose', 'exec', '-T', 'app', 'php', '-r', code, '--', json.dumps(filenames))
            self.sql(f'DELETE FROM users WHERE username IN ({users});')
        self.sql(f"DELETE FROM goscieportalu WHERE user_agent LIKE '{self.agent}%';")
        self.sql(f'DELETE FROM auth_attempts WHERE bucket IN ({self.buckets});')
        if self.throttle:
            values = []
            for line in self.throttle.splitlines():
                bucket, failures, window, blocked = line.split('\t')
                values.append(f"('{bucket}',{int(failures)},{int(window)},{int(blocked)})")
            self.sql('INSERT INTO auth_attempts(bucket,failures,window_started,blocked_until) VALUES ' + ','.join(values) + ';')


def exercise(context):
    task = context.task
    first, second = context.browser('owner'), context.browser('viewer')
    first_user, second_user = context.prefix + '_owner', context.prefix + '_viewer'
    for path in ('', 'select.php', 'playlist.php', 'stream.php?id=1'):
        status, headers, body = first.request(f'/{task}/' + path)
        expect(status == 303 and headers.get('Location') == f'/{task}/login.php' and b'data-authenticated="true"' not in body, f'{task}: anonymous access is redirected to its own sign-in page')
    context.register(first, first_user)
    context.register(second, second_user)
    context.login(first, first_user)
    context.login(second, second_user)
    other_task = 'z6a' if task == 'z6b' else 'z6b'
    status, headers, _ = first.request(f'/{other_task}/')
    expect(status == 303 and headers.get('Location') == f'/{other_task}/login.php', f'{task}: this session does not authenticate the other media app')
    status, _, _ = context.upload(first, 'Missing token', token='')
    expect(status == 403, f'{task}: uploads require CSRF')
    status, _, _ = context.upload(first, 'Invalid media', content=b'<?php echo "not a media file"; ?>')
    expect(status == 422, f'{task}: non-media content is rejected even with a media filename')
    titles = [context.prefix + ' First <img src=x onerror=alert(1)>', context.prefix + ' Second']
    for title in titles:
        status, _, _ = context.upload(first, title)
        expect(status == 303, f'{task}: genuine media uploads with metadata')
    ids = [int(value) for value in context.sql(f"SELECT m.{context.key} FROM {context.table} m JOIN users u ON u.id=m.idu WHERE u.username='{first_user}' ORDER BY m.{context.key};").splitlines()]
    expect(len(ids) == 2 and context.sql(f'SELECT COUNT(DISTINCT filename) FROM {context.table} WHERE {context.key} IN ({ids[0]},{ids[1]});') == '2', f'{task}: identical uploaded filenames are stored under distinct private names')
    expect(context.sql(f'SELECT {context.creator_key},{context.genre_key},size FROM {context.table} WHERE {context.key}={ids[0]};') == f'Local test creator\t1\t{len(context.content)}', f'{task}: title, creator, genre and byte size are stored')
    private = context.playlist(first, context.prefix + ' Private')
    public = context.playlist(first, context.prefix + ' Public', public=True)
    own = context.playlist(second, context.prefix + ' Reused')
    expect(context.sql(f'SELECT public FROM playlistname WHERE idpl={private};') == '0', f'{task}: new playlists are private by default')
    status, _, _ = context.post(first, 'playlist_add.php', idpl=private, **{context.key: ids[0]})
    expect(status == 303, f'{task}: owner adds an item to a private playlist')
    status, _, _ = second.request(f'/{task}/playlist.php?id={private}')
    expect(status == 404, f'{task}: another account cannot open a private playlist')
    status, _, _ = second.request(f'/{task}/stream.php?id={ids[0]}')
    expect(status == 404, f'{task}: unshared media bytes stay private')
    status, _, _ = context.post(second, 'playlist_add.php', idpl=own, **{context.key: ids[0]})
    expect(status == 404, f'{task}: private media cannot be added by guessing its ID')
    status, _, _ = context.post(first, 'playlist_add.php', idpl=public, **{context.key: ids[1]})
    expect(status == 303, f'{task}: owner publishes an item through a public playlist')
    status, _, public_page = second.page(f'/{task}/playlist.php?id={public}')
    expect(status == 200 and titles[1] in public_page, f'{task}: public playlist is visible to another signed-in account')
    status, headers, content = second.request(f'/{task}/stream.php?id={ids[1]}')
    expected_mime = 'video/mp4' if context.video else 'audio/mpeg'
    expect(status == 200 and headers.get_content_type() == expected_mime and content == context.content, f'{task}: public playback returns the exact media bytes and MIME type')
    status, headers, content = second.request(f'/{task}/stream.php?id={ids[1]}', headers={'Range': 'bytes=3-14'})
    expect(status == 206 and content == context.content[3:15] and headers.get('Content-Range') == f'bytes 3-14/{len(context.content)}', f'{task}: playback supports exact byte-range seeking')
    status, _, _ = second.request(f'/{task}/stream.php?id={ids[1]}', headers={'Range': 'bytes=999999999-'})
    expect(status == 416, f'{task}: out-of-file seek ranges are rejected')
    status, _, _ = context.post(second, 'playlist_add.php', idpl=own, **{context.key: ids[1]})
    expect(status == 303, f'{task}: public media can be reused in another owner playlist')
    status, _, _ = context.post(second, 'playlist_add.php', idpl=own, **{context.key: ids[1]})
    expect(status == 409, f'{task}: duplicate playlist entries are rejected')
    for endpoint, values in (('playlist_save.php', {'idpl': public, 'name': 'Changed'}), ('playlist_add.php', {'idpl': public, context.key: ids[1]}), ('playlist_delete.php', {'idpl': public}), ('media_delete.php', {'id': ids[1]})):
        status, _, _ = context.post(second, endpoint, **values)
        expect(status == 404, f'{task}: public read access cannot mutate another owner data')
    status, _, _ = context.post(first, 'playlist_save.php', idpl=public, name=context.prefix + ' Public')
    expect(status == 303 and second.request(f'/{task}/playlist.php?id={public}')[0] == 404, f'{task}: owner can make a public playlist private again')
    expect(second.request(f'/{task}/stream.php?id={ids[1]}')[0] == 200, f'{task}: an existing reused entry remains playable in its owner playlist')
    context.post(first, 'playlist_save.php', idpl=public, name=context.prefix + ' Public', public='1')
    status, _, _ = context.post(first, 'playlist_add.php', idpl=public, **{context.key: ids[0]})
    expect(status == 303, f'{task}: public playlist accepts a second ordered item')
    status, _, playlist_page = first.page(f'/{task}/playlist.php?id={public}')
    queue = json.loads(Queue(playlist_page).data)
    expect(status == 200 and [item['id'] for item in queue] == [ids[1], ids[0]] and '<img src=x onerror=alert(1)>' not in playlist_page, f'{task}: player receives the saved playlist order and safe text')
    expect(all('/' + task + '/stream.php?' in item['url'] for item in queue), f'{task}: queue streams remain in the current task')
    if context.video:
        status, headers, text = second.request(f'/{task}/subtitles.php?id={ids[1]}')
        expect(status == 200 and headers.get_content_type() == 'text/vtt' and text.startswith(b'WEBVTT') and b'Local subtitle' in text, f'{task}: video subtitles are served as authorized WebVTT')
    else:
        expect(all(item['lyrics'] == 'Local lyrics <b>remain text</b>' for item in queue), f'{task}: audio queue carries the stored lyrics as data')
    status, _, library = second.page(f'/{task}/')
    expect(status == 200 and context.prefix + ' Private' not in library and context.prefix + ' Public' in library, f'{task}: library lists public playlists without exposing private names')
    status, _, filtered = first.page(f'/{task}/?' + urllib.parse.urlencode({'q': "' OR 1=1 -- "}))
    expect(status == 200 and titles[1] not in filtered, f'{task}: library filtering treats SQL-like text literally')
    session = second.sid()
    status, _, _ = context.post(second, 'logout.php')
    status_old, headers, _ = context.browser('old-session').request(f'/{task}/stream.php?id={ids[1]}', cookie=session)
    expect(status == 303 and status_old == 303 and headers.get('Location') == f'/{task}/login.php', f'{task}: sign-out invalidates a previously captured stream session')
    return {'task': task, 'username': first_user, 'password': context.password, 'playlist_url': f'{context.base}/{task}/playlist.php?id={public}', 'playlist_id': public}


def main():
    parser = argparse.ArgumentParser(description='Local audio/video library integration checks.')
    parser.add_argument('--base-url', default='http://127.0.0.1:8081')
    parser.add_argument('--keep-fixtures', type=Path)
    parser.add_argument('--cleanup-fixtures', type=Path)
    args = parser.parse_args()
    origin = urllib.parse.urlsplit(args.base_url)
    if origin.scheme != 'http' or origin.hostname not in ('127.0.0.1', 'localhost', '::1') or origin.username or origin.password or origin.query or origin.fragment or origin.path not in ('', '/'):
        parser.error('Use a local loopback HTTP origin.')
    if args.cleanup_fixtures:
        fixtures = json.loads(args.cleanup_fixtures.read_text())
        prefix = fixtures['prefix']
        if not re.fullmatch(r'z6smoke_[0-9]+', prefix):
            raise ValueError('Invalid test fixture prefix.')
        for task in ('z6a', 'z6b'):
            context = LocalMedia(args.base_url.rstrip('/'), task, prefix)
            context.users = [prefix + '_owner', prefix + '_viewer']
            context.cleanup()
        args.cleanup_fixtures.unlink()
        print('Local media browser fixtures removed.')
        return
    prefix = 'z6smoke_' + str(time.time_ns())
    contexts = []
    fixtures = []
    complete = False
    try:
        for task in ('z6a', 'z6b'):
            context = LocalMedia(args.base_url.rstrip('/'), task, prefix)
            contexts.append(context)
            fixtures.append(exercise(context))
        first, second = contexts
        client = second.browser('database-isolation')
        token = second.csrf(client, 'login.php')
        status, _, _ = client.request('/z6b/verify.php', fields={'csrf': token, 'user': prefix + '_owner', 'pass': first.password})
        expect(status == 401, 'matching usernames in the two media databases retain independent passwords')
        complete = True
        if args.keep_fixtures:
            args.keep_fixtures.parent.mkdir(parents=True, exist_ok=True)
            args.keep_fixtures.write_text(json.dumps({'prefix': prefix, 'apps': fixtures}, indent=2))
        print(f'OK: {checks} audio and video library checks passed locally.')
    finally:
        if not (complete and args.keep_fixtures):
            for context in reversed(contexts):
                context.cleanup()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(f'FAIL: {error}', file=sys.stderr)
        sys.exit(1)
