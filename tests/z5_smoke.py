from __future__ import annotations

import base64
from concurrent.futures import ThreadPoolExecutor
import io
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zipfile

from z2_smoke import png
from z3_smoke import Document
from z4_smoke import Local


MEDIA_B64 = {'tone.mp3': 'SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjYyLjEyLjEwMgAAAAAAAAAAAAAA//tAwAAAAAAAAAAAAAAAAAAAAAAASW5mbwAAAA8AAAAFAAACwABoaGhoaGhoaGhoaGhoaGhoaGhojo6Ojo6Ojo6Ojo6Ojo6Ojo6Ojo60tLS0tLS0tLS0tLS0tLS0tLS0tNra2tra2tra2tra2tra2tra2tra//////////////////////////8AAAAATGF2YzYyLjI4AAAAAAAAAAAAAAAAJAMGAAAAAAAAAsC1UziRAAAAAAD/+xDEAAAEdBNVVJCAMKYJrzcaIAIAAa05QAABWTo9UFAIBgkB8HwfB8oCAIBhEHwf1Ag7E4f4g3AEk/bAYDgcDgAAAAAAKIkqmRRkCOkCSBaj94UB8BMb8CKUL6gaEvwkDSoAAADgCf/7EsQCgoUcHS+90AAgm4OldY7gTIAAAOC4IEAFJQRgMbD4SaWFOYlBOYKASCQCLJAoAmvd3f+v1AASwAU7WEJZkYYYjCbpcmbjjCYZAgaRl30JAVOxOwf/v////03fpjDQ4w4dMdODPv/7EMQEggUEHxgN+yJAsoOkaZ9kTIMwrxvjUM4cNOsbQwngbTXMAgZuqHV+awbJpaHP1/QAVFAYAD+JFmIIb85g0BWGZyu4ZkgVxg1gXnEsYxRhjmE8g5LwT/sz/7f//6oAAADAAWAA//sSxAODxVgfJ6z7giCtBCRCvAAEAKAoJgYOY8hgYBTmPGt6fUUZisOJEs6QAmAQMpdO91f/8h+qGRGACYC4CJgIAmGCkF4aByqZnDjXmG6EmYNIEpgHgbmBeBOYFYE5deL28I0BIgEC//sQxAKABPw7SBmjgACQhaDDnmAAGC8LCjIInhXB8mWsMWMlNv3IilnHB2N5vJkAREWZhUFfljwswAOAFQxjtE1PET4BaLyT4TYyn6dQ1W6kiAQkJA1Bo99Z3iJMQU1FNC4wqqqqqqo=', 'clip.mp4': 'AAAAIGZ0eXBpc29tAAACAGlzb21pc28yYXZjMW1wNDEAAAMVbW9vdgAAAGxtdmhkAAAAAAAAAAAAAAAAAAAD6AAAAGQAAQAAAQAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAAj90cmFrAAAAXHRraGQAAAADAAAAAAAAAAAAAAABAAAAAAAAAGQAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAABAAAAAQAAAAAAAkZWR0cwAAABxlbHN0AAAAAAAAAAEAAABkAAAAAAABAAAAAAG3bWRpYQAAACBtZGhkAAAAAAAAAAAAAAAAAAAoAAAABABVxAAAAAAALWhkbHIAAAAAAAAAAHZpZGUAAAAAAAAAAAAAAABWaWRlb0hhbmRsZXIAAAABYm1pbmYAAAAUdm1oZAAAAAEAAAAAAAAAAAAAACRkaW5mAAAAHGRyZWYAAAAAAAAAAQAAAAx1cmwgAAAAAQAAASJzdGJsAAAAvnN0c2QAAAAAAAAAAQAAAK5hdmMxAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAABAAEABIAAAASAAAAAAAAAABFUxhdmM2Mi4yOC4xMDIgbGlieDI2NAAAAAAAAAAAAAAAGP//AAAANGF2Y0MBZAAK/+EAF2dkAAqs2V7ARAAAAwAEAAADAFA8SJZYAQAGaOvjyyLA/fj4AAAAABBwYXNwAAAAAQAAAAEAAAAUYnRydAAAAAAAAN2QAAAAAAAAABhzdHRzAAAAAAAAAAEAAAABAAAEAAAAABxzdHNjAAAAAAAAAAEAAAABAAAAAQAAAAEAAAAUc3RzegAAAAAAAALFAAAAAQAAABRzdGNvAAAAAAAAAAEAAANFAAAAYnVkdGEAAABabWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAbWRpcmFwcGwAAAAAAAAAAAAAAAAtaWxzdAAAACWpdG9vAAAAHWRhdGEAAAABAAAAAExhdmY2Mi4xMi4xMDIAAAAIZnJlZQAAAs1tZGF0AAACrgYF//+q3EXpvebZSLeWLNgg2SPu73gyNjQgLSBjb3JlIDE2NSByMzIyMiBiMzU2MDVhIC0gSC4yNjQvTVBFRy00IEFWQyBjb2RlYyAtIENvcHlsZWZ0IDIwMDMtMjAyNSAtIGh0dHA6Ly93d3cudmlkZW9sYW4ub3JnL3gyNjQuaHRtbCAtIG9wdGlvbnM6IGNhYmFjPTEgcmVmPTMgZGVibG9jaz0xOjA6MCBhbmFseXNlPTB4MzoweDExMyBtZT1oZXggc3VibWU9NyBwc3k9MSBwc3lfcmQ9MS4wMDowLjAwIG1peGVkX3JlZj0xIG1lX3JhbmdlPTE2IGNocm9tYV9tZT0xIHRyZWxsaXM9MSA4eDhkY3Q9MSBjcW09MCBkZWFkem9uZT0yMSwxMSBmYXN0X3Bza2lwPTEgY2hyb21hX3FwX29mZnNldD0tMiB0aHJlYWRzPTEgbG9va2FoZWFkX3RocmVhZHM9MSBzbGljZWRfdGhyZWFkcz0wIG5yPTAgZGVjaW1hdGU9MSBpbnRlcmxhY2VkPTAgYmx1cmF5X2NvbXBhdD0wIGNvbnN0cmFpbmVkX2ludHJhPTAgYmZyYW1lcz0zIGJfcHlyYW1pZD0yIGJfYWRhcHQ9MSBiX2JpYXM9MCBkaXJlY3Q9MSB3ZWlnaHRiPTEgb3Blbl9nb3A9MCB3ZWlnaHRwPTIga2V5aW50PTI1MCBrZXlpbnRfbWluPTEwIHNjZW5lY3V0PTQwIGludHJhX3JlZnJlc2g9MCByY19sb29rYWhlYWQ9NDAgcmM9Y3JmIG1idHJlZT0xIGNyZj0yMy4wIHFjb21wPTAuNjAgcXBtaW49MCBxcG1heD02OSBxcHN0ZXA9NCBpcF9yYXRpbz0xLjQwIGFxPTE6MS4wMACAAAAAD2WIhAA3//728P4FNlYEwQ=='}
checks = 0


def expect(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1
    print(f'PASS {message}')


def pdf():
    content = b'BT /F1 12 Tf 20 60 Td (Local cloud integration test) Tj ET'
    objects = [b'<< /Type /Catalog /Pages 2 0 R >>', b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>', b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 100] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', b'<< /Length ' + str(len(content)).encode() + b' >>\nstream\n' + content + b'\nendstream']
    result = bytearray(b'%PDF-1.4\n')
    offsets = [0]
    for number, item in enumerate(objects, 1):
        offsets.append(len(result))
        result.extend(f'{number} 0 obj\n'.encode() + item + b'\nendobj\n')
    xref = len(result)
    result.extend(f'xref\n0 {len(offsets)}\n0000000000 65535 f \n'.encode())
    for offset in offsets[1:]:
        result.extend(f'{offset:010d} 00000 n \n'.encode())
    result.extend(f'trailer\n<< /Size {len(offsets)} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n'.encode())
    return bytes(result)


def docx():
    output = io.BytesIO()
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
        archive.writestr('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>')
        archive.writestr('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>')
        archive.writestr('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Local cloud integration test</w:t></w:r></w:p></w:body></w:document>')
    return output.getvalue()


def main():
    context = Local(5, expect)
    first, second = context.browser('first'), context.browser('second')

    def upload(browser, name, content, folder='', csrf=None):
        boundary = 'z5-' + uuid.uuid4().hex
        fields = {'csrf': csrf if csrf is not None else context.csrf(browser), 'dir': folder}
        parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode() for key, value in fields.items()]
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="fileToUpload"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + content + b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode())
        return browser.request('/z5/upload.php', payload=b''.join(parts), media_type='multipart/form-data; boundary=' + boundary)

    def file_url(name, folder='', **extra):
        return '/z5/file.php?' + urllib.parse.urlencode({'dir': folder, 'name': name, 'download': '1', **extra})

    def post(browser, endpoint, **fields):
        return browser.request('/z5/' + endpoint, fields={'csrf': context.csrf(browser), **fields})

    try:
        for endpoint in ('/', '/header.php', '/select.php', '/file.php?name=x', '/view.php?name=x', '/logowania.php', '/visits.php'):
            status, headers, body = context.browser('anonymous').request('/z5' + endpoint)
            expect(status == 303 and headers.get('Location') == '/z5/logowanie.php' and b'<nav' not in body, f'private cloud requires login: {endpoint}')
        context.register(first)
        first_user = context.users[-1]
        context.register(second)
        second_user = context.users[-1]
        csrf = context.csrf(first, '/z5/logowanie.php')
        status, _, _ = first.request('/z5/weryfikuj.php', fields={'csrf': csrf, 'user': first_user, 'pass': 'wrong'})
        expect(status == 401, 'failed cloud login is rejected')
        context.login(first, first_user)
        context.login(second, second_user)
        logs = context.sql(f"SELECT login_success FROM goscieportalu WHERE application='z5' AND event_type='login' AND login_name='{first_user}' ORDER BY id;")
        expect(logs.splitlines() == ['0', '1'], 'failed and successful login attempts are recorded in order')
        status, _, log_page = first.page('/z5/logowania.php')
        status_b, _, log_page_b = second.page('/z5/logowania.php')
        expect(status == status_b == 200 and 'błędne' in log_page and 'błędne' not in log_page_b, 'each account sees only its own login history')
        for browser in (first, second):
            status, _, page = browser.page('/z5/')
            links = [attributes.get('href', '') for tag, attributes in Document(page).elements if tag == 'a']
            expect(status == 200 and not any('/file.php?' in link for link in links), 'new account has an empty home directory')
        storage = json.loads(context.php('$_SERVER["DOCUMENT_ROOT"]="/var/www/html/public";require "/var/www/html/app/z5_storage.php";$root=z5_private_root();$homes=[];foreach(array_slice($argv,1) as $name)$homes[$name]=is_dir($root."/".$name);echo json_encode(["root"=>$root,"docroot"=>realpath($_SERVER["DOCUMENT_ROOT"]),"homes"=>$homes]);', first_user, second_user))
        expect(not storage['root'].startswith(storage['docroot'] + '/') and all(storage['homes'].values()), 'each username has its own home outside the web document root')
        status, _, _ = first.request('/z5/mkdir.php', fields={'name': 'folder', 'dir': ''})
        expect(status == 403, 'directory creation requires CSRF')
        status, _, _ = upload(first, 'missing-csrf.bin', b'unchanged', csrf='')
        expect(status == 403, 'file upload requires CSRF')
        status, _, _ = post(first, 'mkdir.php', name='folder', dir='')
        expect(status == 303, 'owner creates a home subdirectory')
        status, _, folder_page = first.page('/z5/?dir=folder')
        folder_elements = Document(folder_page).elements
        expect(status == 200 and not any(tag == 'form' and attributes.get('action') == '/z5/mkdir.php' for tag, attributes in folder_elements), 'subdirectory offers navigation upward and hides nested-directory creation')
        status, _, _ = post(first, 'mkdir.php', name='nested', dir='folder')
        expect(status == 422, 'server enforces the one-level directory rule')
        for name in ('..', '../escape', 'a/b', 'a\\b', 'a' * 256):
            status, _, _ = post(first, 'mkdir.php', name=name, dir='')
            expect(status == 422, f'invalid directory name is rejected: {name[:20]}')
        files = {'report.pdf': pdf(), 'document.docx': docx(), 'image.png': png(), 'arbitrary.bin': b'\x00\xff\x01exact bytes\r\n\x00', 'script.php': b'<?php echo "unexpected-execution"; ?>'}
        files.update({name: base64.b64decode(value) for name, value in MEDIA_B64.items()})
        for name, content in files.items():
            status, _, _ = upload(first, name, content)
            expect(status == 303, f'cloud accepts arbitrary supported file bytes: {name}')
            status, headers, returned = first.request(file_url(name))
            expect(status == 200 and returned == content and headers.get('Content-Disposition', '').startswith('attachment') and headers.get_content_type() == 'application/octet-stream', f'download preserves exact original bytes and uses attachment: {name}')
        status, _, _ = upload(first, 'report.pdf', b'overwrite')
        expect(status == 409 and first.request(file_url('report.pdf'))[2] == files['report.pdf'], 'duplicate upload cannot overwrite the existing file')
        peer = context.browser('same-owner-peer')
        context.login(peer, first_user)
        candidates = (b'first complete upload' * 1000, b'second complete upload' * 1000)
        with ThreadPoolExecutor(max_workers=2) as pool:
            pending = [pool.submit(upload, client, 'race.bin', content) for client, content in zip((first, peer), candidates)]
            outcomes = [future.result()[0] for future in pending]
        expect(sorted(outcomes) == [303, 409] and first.request(file_url('race.bin'))[2] == candidates[outcomes.index(303)], 'concurrent uploads from independent sessions preserve exactly one complete winning file')
        for name in ('.', '..', 'ą' * 128):
            status, _, _ = upload(first, name, b'invalid-name')
            expect(status == 422, 'upload rejects dot names and names exceeding the UTF-8 byte limit')
        xss_name = '<img src=x onerror=alert(1)>.txt'
        status, _, _ = upload(first, xss_name, b'inert')
        status_page, _, rendered = first.page('/z5/')
        expect(status == 303 and status_page == 200 and xss_name not in rendered and '&lt;img' in rendered, 'valid special filename is escaped when listed')
        shared_name = 'private.txt'
        status, _, _ = upload(first, shared_name, b'first-owner', folder='folder')
        expect(status == 303, 'file upload works inside a home subdirectory')
        status, _, _ = upload(second, shared_name, b'second-owner')
        expect(status == 303 and second.request(file_url(shared_name))[2] == b'second-owner' and first.request(file_url(shared_name, 'folder'))[2] == b'first-owner', 'accounts with the same filename retain independent contents')
        status, _, _ = second.request(file_url('report.pdf', user=first_user, username=first_user))
        expect(status == 404, 'submitted owner names cannot download another account file')
        for path in ('/z5/?dir=../' + first_user, file_url('../report.pdf'), file_url('private.txt', '../' + first_user)):
            status, _, _ = second.request(path)
            expect(status == 422, 'path traversal cannot escape the current account home')
        status, _, _ = post(second, 'delete.php', name='report.pdf', dir='', user=first_user)
        expect(status == 404 and first.request(file_url('report.pdf'))[2] == files['report.pdf'], 'another account cannot delete an owner file')
        for name in ('script.php', 'report.pdf'):
            status, headers, content = first.request(file_url(name, inline='1'))
            expect(status == 200 and headers.get('Content-Disposition', '').startswith('attachment') and content == files[name], f'active or document content remains a download even with inline requested: {name}')
        for name, mime in (('image.png', 'image/png'), ('tone.mp3', 'audio/mpeg'), ('clip.mp4', 'video/mp4')):
            status, headers, content = first.request(file_url(name, inline='1'))
            expect(status == 200 and headers.get_content_type() == mime and headers.get('Content-Disposition', '').startswith('inline') and content == files[name] and 'sandbox' in headers.get('Content-Security-Policy', ''), f'recognized media preview retains exact bytes with sandbox headers: {name}')
            status, _, page = first.page('/z5/view.php?' + urllib.parse.urlencode({'name': name}))
            elements = Document(page).elements
            expected_tag = {'image.png': 'img', 'tone.mp3': 'audio', 'clip.mp4': 'video'}[name]
            expect(status == 200 and any(tag == expected_tag and 'autoplay' not in attributes for tag, attributes in elements), f'media view renders a non-autoplay {expected_tag} preview')
        status, headers, content = first.request(file_url('report.pdf'), headers={'Range': 'bytes=1-9'})
        expect(status == 206 and content == files['report.pdf'][1:10] and headers.get('Content-Range') == f'bytes 1-9/{len(files["report.pdf"])}', 'single byte range serves exactly the requested bytes')
        status, _, content = first.request(file_url('report.pdf'), headers={'Range': 'bytes=-8'})
        expect(status == 206 and content == files['report.pdf'][-8:], 'suffix byte ranges serve exactly the file tail')
        for value in ('bytes=99999999-', 'bytes=5-2', 'bytes=0-2,4-6'):
            status, headers, _ = first.request(file_url('report.pdf'), headers={'Range': value})
            expect(status == 416 and headers.get('Content-Range') == f'bytes */{len(files["report.pdf"])}', 'invalid or multipart byte range is rejected')
        large = (b'Z5-large-file-exact-bytes\x00' * 180000)[:4 * 1024 * 1024]
        status, _, _ = upload(first, 'large.bin', large)
        expect(len(large) > 3 * 1024 * 1024 and status == 303 and first.request(file_url('large.bin'))[2] == large, 'file larger than the former three-MiB gateway limit uploads and downloads intact')
        for path in (f'/storage/mycloud/{first_user}/script.php', f'/z5/{first_user}/script.php', f'/mycloud/{first_user}/script.php'):
            status, _, content = context.browser('public-path').request(path)
            expect(status in (403, 404) and b'unexpected-execution' not in content, 'private uploaded PHP cannot be fetched or executed through a public path')
        status, _, _ = post(first, 'delete.php', name='folder', dir='')
        expect(status == 409, 'nonempty subdirectory cannot be deleted')
        status, _, _ = post(first, 'delete.php', name=shared_name, dir='folder')
        status_folder, _, _ = post(first, 'delete.php', name='folder', dir='')
        expect(status == status_folder == 303 and first.request(file_url(shared_name, 'folder'))[0] == 404, 'owner can remove a file and then its empty subdirectory')
        sid = first.sid()
        status, _, _ = first.request('/z5/logout.php', fields={'csrf': context.csrf(first, '/z5/header.php')})
        status_old, headers, _ = context.browser('old-session').request(file_url('report.pdf'), cookie=sid)
        expect(status == 303 and status_old == 303 and headers.get('Location') == '/z5/logowanie.php', 'logout invalidates previously captured file-download session')
        print(f'OK: {checks} task 5 checks passed against local PHP and MySQL.')
    finally:
        if context.users:
            context.php('$_SERVER["DOCUMENT_ROOT"]="/var/www/html/public";require "/var/www/html/app/z5_storage.php";$root=z5_private_root();$metadata=dirname($root)."/.mycloud-meta-".substr(hash("sha256",$root),0,16);foreach(array_slice($argv,1) as $name){if(!preg_match("/^z5smoke_[0-9]+_[0-9]+$/D",$name))throw new RuntimeException("Invalid test home.");$home=$root."/".$name;if(is_dir($home)&&!is_link($home)){$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($home,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($iterator as $item){$path=$item->getPathname();if($item->isLink()||!$item->isDir())unlink($path);else rmdir($path);}rmdir($home);}@unlink($metadata."/".hash("sha256",$name).".lock");}', *context.users)
        context.cleanup()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(f'FAIL: {error}', file=sys.stderr)
        sys.exit(1)
