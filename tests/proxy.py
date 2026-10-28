#!/usr/bin/env python3
"""v0.9.0 stdio proxy (bin/wpmcp-proxy.cjs) against a running test site.

Usage: python3 tests/proxy.py BASE_URL USER APP_PASSWORD PROXY_FILE
"""
import json, os, subprocess, sys, threading, queue

base, user, pw, proxy = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
fails = []


def check(name, ok, info=''):
    print(('PASS ' if ok else 'FAIL ') + name + (f' — {info}' if info else ''))
    if not ok:
        fails.append(name)


class Proxy:
    def __init__(self, env_over):
        env = dict(os.environ, WP_URL=base, WP_USERNAME=user, WP_APP_PASSWORD=pw)
        env.update(env_over)
        self.p = subprocess.Popen(['node', proxy], stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                  stderr=subprocess.PIPE, env=env, text=True, bufsize=1)
        self.q = queue.Queue()
        threading.Thread(target=self._read, daemon=True).start()

    def _read(self):
        for line in self.p.stdout:
            line = line.strip()
            if line:
                self.q.put(json.loads(line))

    def send(self, obj):
        self.p.stdin.write(json.dumps(obj) + '\n')
        self.p.stdin.flush()

    def recv(self, timeout=60):
        try:
            return self.q.get(timeout=timeout)
        except queue.Empty:
            return None

    def close(self):
        try:
            self.p.stdin.close()
            self.p.wait(timeout=20)
        except Exception:
            self.p.kill()
        return self.p.stderr.read()


# 1. happy path
x = Proxy({})
x.send({'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {
    'protocolVersion': '2025-06-18', 'capabilities': {}, 'clientInfo': {'name': 'proxytest', 'version': '1'}}})
r = x.recv()
check('initialize through proxy', bool(r) and r.get('id') == 1 and 'result' in r, str(r)[:120])
x.send({'jsonrpc': '2.0', 'method': 'notifications/initialized'})
x.send({'jsonrpc': '2.0', 'id': 2, 'method': 'tools/list', 'params': {}})
r = x.recv()
tools = [t['name'] for t in (r or {}).get('result', {}).get('tools', [])]
check('tools/list through proxy', 'wpmcp-get-site-info' in tools, f'{len(tools)} tools')
# two requests in flight at once: both must be answered, matched by id
x.send({'jsonrpc': '2.0', 'id': 3, 'method': 'tools/call', 'params': {'name': 'wpmcp-get-site-info', 'arguments': {}}})
x.send({'jsonrpc': '2.0', 'id': 4, 'method': 'tools/list', 'params': {}})
got = {}
for _ in range(2):
    m = x.recv()
    if m:
        got[m.get('id')] = m
check('concurrent requests both answered', set(got) == {3, 4}, str(sorted(got)))
txt = ''.join(c.get('text', '') for c in got.get(3, {}).get('result', {}).get('content', []))
check('tools/call returns site info', 'WordPress' in txt or 'site' in txt.lower(), txt[:80])
err = x.close()
check('proxy exits cleanly when stdin closes', x.p.returncode == 0, err.strip()[:120])

# 2. wrong password -> JSON-RPC error with a hint, not a hang
x = Proxy({'WP_APP_PASSWORD': 'wrong wrong wrong wrong'})
x.send({'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {
    'protocolVersion': '2025-06-18', 'capabilities': {}, 'clientInfo': {'name': 'proxytest', 'version': '1'}}})
r = x.recv()
check('bad credentials -> error with HTTP 401 and hint',
      bool(r) and 'error' in r and '401' in r['error']['message'] and 'hint' in (r['error'].get('data') or {}), str(r)[:200])
x.close()

# 3. unreachable site -> error, not a crash
x = Proxy({'WP_URL': 'http://127.0.0.1:9'})
x.send({'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {}})
r = x.recv()
check('unreachable site -> JSON-RPC error', bool(r) and 'error' in r and 'Could not reach' in r['error']['message'], str(r)[:160])
x.close()

# 4. missing configuration -> exit code 1 and a clear message
p = subprocess.run(['node', proxy], env={k: v for k, v in os.environ.items() if not k.startswith('WP_')},
                   capture_output=True, text=True, timeout=20)
check('missing WP_URL -> exit 1 with message', p.returncode == 1 and 'WP_URL' in p.stderr, p.stderr.strip()[:100])

print('\n%s' % ('ALL OK' if not fails else 'FAILED: ' + ', '.join(fails)))
sys.exit(1 if fails else 0)
