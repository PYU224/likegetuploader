#!/usr/bin/env python3
"""Python stdlib + PHP CLI (pdo_sqlite, mbstring, fileinfo, curl).
Run: python3 tools/security_regression.py. Set PHP_BIN to select PHP.
Only temporary installations and loopback servers are used; no production data.
"""
import concurrent.futures as futures
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import sqlite3
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(os.environ.get('LGU_TEST_SOURCE', Path(__file__).resolve().parents[1]))
PHP = os.environ.get('PHP_BIN', 'php')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


class Browser:
    def __init__(self, base):
        self.base, self.csrf = base, ''
        self.jar = http.cookiejar.CookieJar()
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())

    def request(self, path, fields=None, blob=None, cookie=None):
        headers, data = {}, None
        if fields is not None:
            fields = {**fields, 'csrf': self.csrf}
            if blob is None:
                data = urllib.parse.urlencode(fields).encode()
                headers['Content-Type'] = 'application/x-www-form-urlencoded'
            else:
                boundary = 'lgu-security-test-boundary'
                chunks = [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k, v in fields.items()]
                chunks += [f'--{boundary}\r\nContent-Disposition: form-data; name="blob"; filename="blob"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode(), blob, f'\r\n--{boundary}--\r\n'.encode()]
                data = b''.join(chunks)
                headers['Content-Type'] = f'multipart/form-data; boundary={boundary}'
        if cookie:
            headers['Cookie'] = cookie
        try:
            response = self.http.open(urllib.request.Request(self.base+path, data=data, headers=headers), timeout=30)
        except urllib.error.HTTPError as e:
            response = e
        with response:
            return response.code, response.read(), response.headers

    def page(self, path='index.php'):
        code, body, headers = self.request(path)
        assert code == 200, (code, body)
        match = re.search(rb'name="csrf" value="([0-9a-f]+)"', body)
        if match:
            self.csrf = match[1].decode()
        return body, headers

    def post(self, fields, blob=None):
        code, body, _ = self.request('upload.php', fields, blob)
        return code, json.loads(body)

    def init(self, size, expected=200):
        code, obj = self.post({'action':'init', 'name':'test.txt', 'size':size, 'delkey':'delete-test'})
        assert code == expected, (code, obj, expected)
        return obj.get('token')

    def login(self, password):
        self.page('admin.php')
        code, body, _ = self.request('admin.php', {'password':password})
        assert code == 303, (code, body)
        assert b'name="act" value="logout"' in self.page('admin.php')[0]


class S3(BaseHTTPRequestHandler):
    objects, uploads = {}, {}
    deny_delete, deny_create, counter = False, False, 0
    state_lock = threading.Lock()

    def log_message(self, *args):
        pass

    def respond(self, status, body=b'', **headers):
        self.send_response(status)
        for k,v in headers.items():
            self.send_header(k,v)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        parsed = urllib.parse.urlsplit(self.path)
        q = urllib.parse.parse_qs(parsed.query, keep_blank_values=True)
        self.rfile.read(int(self.headers.get('Content-Length',0)))
        with self.state_lock:
            if 'uploads' in q and self.deny_create:
                return self.respond(503, b'<Error><Message>Test unavailable</Message></Error>')
            if 'uploads' in q:
                S3.counter += 1
                uid = str(S3.counter)
                self.uploads[uid] = {'key':parsed.path, 'parts':{}}
                return self.respond(200, f'<InitiateMultipartUploadResult><UploadId>{uid}</UploadId></InitiateMultipartUploadResult>'.encode())
            uid = q.get('uploadId',[''])[0]
            if uid not in self.uploads:
                return self.respond(404)
            upload = self.uploads.pop(uid)
            self.objects[parsed.path] = b''.join(v for k,v in sorted(upload['parts'].items()))
            self.respond(200,b'<CompleteMultipartUploadResult/>')

    def do_PUT(self):
        q = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query)
        body = self.rfile.read(int(self.headers.get('Content-Length',0)))
        with self.state_lock:
            uid = q.get('uploadId',[''])[0]
            if uid not in self.uploads:
                return self.respond(404)
            self.uploads[uid]['parts'][int(q['partNumber'][0])] = body
            self.respond(200, ETag='"'+hashlib.md5(body).hexdigest()+'"')

    def do_DELETE(self):
        parsed = urllib.parse.urlsplit(self.path)
        q = urllib.parse.parse_qs(parsed.query)
        with self.state_lock:
            if self.deny_delete:
                return self.respond(403,b'<Error><Message>Test AccessDenied</Message></Error>')
            if 'uploadId' in q:
                return self.respond(204 if self.uploads.pop(q['uploadId'][0],None) else 404)
            self.objects.pop(parsed.path,None)
            self.respond(204)


def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0))
        return sock.getsockname()[1]


with tempfile.TemporaryDirectory(prefix='lgu-security-') as temp:
    work = Path(temp)
    web, sessions = work/'web', work/'sessions'
    web.mkdir(); sessions.mkdir()
    settings = {}
    port = free_port()

    def config(name, **overrides):
        settings[name].update(overrides)
        (web/name/'test-settings.json').write_text(json.dumps(settings[name]))

    def install(name, legacy_db=False, **overrides):
        target = web/name
        shutil.copytree(ROOT,target,ignore=shutil.ignore_patterns('.git','data','config.php','__pycache__'))
        settings[name] = {'secret':'same-test-secret-on-purpose', 'admin_password':'admin-'+name,
                          'pretty_urls':False,'max_file_size':10000000,'max_total_size':10,
                          'uploads_per_hour':50,'max_pending_uploads':30,'max_pending_uploads_total':100,
                          'demo':{'enabled':False}}
        (target/'config.php').write_text("<?php return array_replace_recursive(require __DIR__.'/config.sample.php', json_decode(file_get_contents(__DIR__.'/test-settings.json'),true));")
        config(name,**overrides)
        if legacy_db:
            (target/'data/files').mkdir(parents=True)
            with sqlite3.connect(target/'data/uploader.sqlite') as db:
                db.executescript("""CREATE TABLE files (id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL, comment TEXT NOT NULL DEFAULT '', size INTEGER NOT NULL,
                    mime TEXT NOT NULL DEFAULT '', md5 TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL,
                    downloads INTEGER NOT NULL DEFAULT 0, delkey TEXT, dlpass TEXT, ip TEXT NOT NULL DEFAULT '',
                    hidden INTEGER NOT NULL DEFAULT 0, imported INTEGER NOT NULL DEFAULT 0, skey TEXT);
                    INSERT INTO files(id,name,size,created_at) VALUES(1,'existing.txt',3,1);""")
            (target/'data/files/1').write_bytes(b'old')
        b = Browser(f'http://127.0.0.1:{port}/{name}/')
        b.page()
        return b

    def query(name, sql, params=()):
        with sqlite3.connect(web/name/'data/uploader.sqlite') as db:
            db.row_factory = sqlite3.Row
            return [dict(row) for row in db.execute(sql,params).fetchall()]

    def expire(name, token, legacy=False):
        path = web/name/f'data/tmp/{token}.json'
        meta = json.loads(path.read_text())
        if legacy:
            meta.pop('expires_at',None)
        else:
            meta['expires_at'] = int(time.time())-10
        path.write_text(json.dumps(meta))
        if legacy:
            os.utime(path,(time.time()-90000,time.time()-90000))

    def cleanup(name, expected=0):
        r = subprocess.run([PHP,str(web/name/'tools/cleanup_uploads.php')],capture_output=True,text=True,timeout=30)
        assert r.returncode == expected,(r.returncode,r.stdout,r.stderr)

    def chunk(b, token, data, index=0):
        code,obj = b.post({'action':'chunk','token':token,'index':index},data)
        assert code == 200,(code,obj)
        return obj

    def finish(b, token):
        code,obj = b.post({'action':'finish','token':token})
        assert code == 200,(code,obj)
        return obj['id']

    s3 = ThreadingHTTPServer(('127.0.0.1',0),S3)
    threading.Thread(target=s3.serve_forever,daemon=True).start()
    log = open(work/'php.log','w+')
    server = subprocess.Popen([PHP,'-d',f'session.save_path={sessions}','-d','upload_max_filesize=16M',
                               '-d','post_max_size=20M','-S',f'127.0.0.1:{port}','-t',str(web)],
                               stdout=log,stderr=log,env={**os.environ,'PHP_CLI_SERVER_WORKERS':'4'},start_new_session=True)
    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):
                    break
            except OSError:
                if server.poll() is not None:
                    raise RuntimeError('PHP test server did not start')
                time.sleep(.05)
        a = install('quota')
        token = a.init(6)
        a.init(6,413)
        chunk(a,token,b'abcdef')
        assert chunk(a,token,b'abcdef')['duplicate']
        fid = finish(a,token)
        assert finish(a,token) == fid
        assert (web/'quota'/f'data/files/{fid}').read_bytes() == b'abcdef'
        a.init(4); a.init(1,413)
        assert len([p for p in (web/'quota/data/tmp').glob('*.lock') if not p.name.startswith('.')]) == 2
        print('PASS capacity reservations, completion, retries, no rejected-init lock leak',flush=True)

        clients = [install('race')]
        for _ in range(7):
            c = Browser(clients[0].base); c.page(); clients.append(c)
        barrier = threading.Barrier(8)
        def competing_init(c):
            barrier.wait()
            return c.post({'action':'init','name':'race.txt','size':6})
        with futures.ThreadPoolExecutor(max_workers=8) as pool:
            results = list(pool.map(competing_init,clients))
        assert [code for code,_ in results].count(200) == 1,results
        assert [code for code,_ in results].count(413) == 7,results
        winner = next(obj['token'] for code,obj in results if code == 200)
        chunk(clients[0],winner,b'abcdef')
        with futures.ThreadPoolExecutor(max_workers=2) as pool:
            ids = list(pool.map(lambda c:finish(c,winner),clients[:2]))
        assert ids[0] == ids[1] and len(query('race','SELECT * FROM files')) == 1
        print('PASS concurrent reservation and concurrent finish',flush=True)

        a = install('rate',uploads_per_hour=2)
        token = a.init(1); expire('rate',token)
        assert a.post({'action':'chunk','token':token,'index':0},b'x')[0] == 410
        cleanup('rate')
        token = a.init(1); expire('rate',token,legacy=True); cleanup('rate')
        a.init(1,429)
        assert not list((web/'rate/data/tmp').glob('*.part'))
        print('PASS start-rate limit, expiry and legacy cleanup',flush=True)

        a = install('pending',max_pending_uploads=2)
        a.init(1); a.init(1); a.init(1,429)
        a = install('global',max_pending_uploads_total=1)
        a.init(1); a.init(1,429)
        print('PASS per-IP and global pending limits',flush=True)

        a,b = install('autha'),install('authb')
        a.login('admin-autha')
        ca,cb = next(iter(a.jar)),next(iter(b.jar))
        assert ca.name != cb.name
        assert ca.path == '/autha/' and cb.path == '/authb/'
        code,body,_ = b.request('admin.php',cookie=f'{cb.name}={ca.value}')
        assert code == 200 and b'name="password"' in body and b'name="act" value="logout"' not in body
        assert b'name="act" value="logout"' in a.page('admin.php')[0]
        config('autha',admin_password='rotated-password')
        assert b'name="password"' in a.page('admin.php')[0]
        a.login('rotated-password')
        session_file = sessions / ('sess_' + next(iter(a.jar)).value)
        raw = session_file.read_text()
        changed, count = re.subn(r'admin\|s:\d+:"[^"]*";', 'admin|b:1;', raw)
        assert count == 1
        session_file.write_text(changed)
        assert b'name="password"' in a.page('admin.php')[0]
        print('PASS explicit session-ID replay with shared storage rejected; password rotation',flush=True)

        s3_settings = {'endpoint':f'http://127.0.0.1:{s3.server_port}','bucket':'test','key':'dummy','secret':'dummy','region':'auto'}
        a = install('migration', legacy_db=True)
        row = query('migration', 'SELECT * FROM files')[0]
        assert row['name'] == 'existing.txt' and row['delete_pending'] == 0 and row['upload_token'] is None
        assert (web/'migration/data/files/1').read_bytes() == b'old'
        a.init(7); a.init(1,413)
        print('PASS existing v1.3.4 database migration preserves files and quota',flush=True)

        a = install('localdelete')
        token = a.init(3); chunk(a,token,b'abc'); fid = finish(a,token)
        original = web/'localdelete'/f'data/files/{fid}'
        original.unlink(); original.mkdir()  # deterministic unlink failure, including when run as root
        code,body,_ = a.request(f'file.php?id={fid}',{'act':'delete','delkey':'delete-test'})
        assert code == 503 and query('localdelete','SELECT delete_pending FROM files')[0]['delete_pending'] == 1
        cleanup('localdelete',1)
        original.rmdir(); original.write_bytes(b'abc'); cleanup('localdelete')
        assert not query('localdelete','SELECT * FROM files')
        print('PASS uploader deletion failure stays pending; local retry succeeds',flush=True)

        a = install('failedinit',storage='s3',s3=s3_settings)
        S3.deny_create = True; a.init(6,502); S3.deny_create = False
        pending = list((web/'failedinit/data/tmp').glob('*.json'))
        assert len(pending) == 1 and json.loads(pending[0].read_text())['expires_at'] < time.time()
        cleanup('failedinit'); a.init(6)
        print('PASS failed upload initialization becomes eligible for immediate cleanup',flush=True)

        a = install('remote',storage='s3',s3=s3_settings,max_total_size=10000000)
        payload = b'x'*(5*1024*1024)+b'last'
        token = a.init(len(payload))
        chunk(a,token,payload[:5*1024*1024]); chunk(a,token,payload[5*1024*1024:],1)
        fid = finish(a,token)
        row = query('remote','SELECT * FROM files')[0]
        assert row['md5'] == hashlib.md5(payload).hexdigest()
        assert S3.objects['/test/'+row['skey']] == payload
        a.login('admin-remote'); S3.deny_delete = True
        assert a.request('admin.php',{'act':'delete','id':fid})[0] == 303
        row = query('remote','SELECT * FROM files')[0]
        assert row['delete_pending'] == 1 and row['hidden'] == 1 and row['size'] == len(payload)
        a.request('admin.php',{'act':'hide','id':fid})
        assert query('remote','SELECT hidden FROM files')[0]['hidden'] == 1
        assert a.request('dl.php',{'id':fid})[0] == 404
        cleanup('remote',1)
        assert query('remote','SELECT * FROM files')
        S3.deny_delete = False; cleanup('remote')
        assert not query('remote','SELECT * FROM files') and '/test/'+row['skey'] not in S3.objects
        print('PASS S3 multipart and failed deletion: hidden, accounted, retryable',flush=True)

        a = install('abandoned',storage='s3',s3=s3_settings)
        token = a.init(6); chunk(a,token,b'abcdef'); expire('abandoned',token)
        S3.deny_delete = True; cleanup('abandoned',1)
        a.init(6,413)
        assert (web/'abandoned'/f'data/tmp/{token}.json').exists()
        S3.deny_delete = False; cleanup('abandoned'); a.init(6)
        print('PASS failed cleanup retains reservation; successful delete/abort releases it',flush=True)
        print('All security regression tests passed.',flush=True)
    except BaseException:
        log.flush(); log.seek(0); print(log.read()[-16000:])
        raise
    finally:
        os.killpg(server.pid,signal.SIGTERM)
        server.wait(timeout=10)
        log.close(); s3.shutdown()
