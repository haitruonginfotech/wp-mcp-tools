#!/usr/bin/env node
// v0.9.0: proxy against a fake MCP server with a SELF-SIGNED certificate, SSE replies and plain permalinks.
// Usage: node tests/proxy_tls.js PROXY_FILE        (needs openssl)
'use strict';
const https = require('https');
const { spawnSync, spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const proxy = process.argv[2];
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'wpmcp-tls-'));
const key = path.join(dir, 'k.pem'), crt = path.join(dir, 'c.pem');
const ssl = spawnSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', key, '-out', crt, '-subj', '/CN=localhost', '-days', '1'], { stdio: 'ignore' });
if (ssl.status !== 0) { console.log('SKIP openssl not available'); process.exit(0); }

let fails = 0;
const check = (n, ok, i) => { console.log((ok ? 'PASS ' : 'FAIL ') + n + (i ? ' — ' + i : '')); if (!ok) fails++; };
const seen = { auth: '', session: '', urls: [] };

const server = https.createServer({ key: fs.readFileSync(key), cert: fs.readFileSync(crt) }, (req, res) => {
  seen.urls.push(req.method + ' ' + req.url);
  if (req.method === 'GET' && req.url === '/wp-json/') { res.writeHead(404, { 'Content-Type': 'text/html' }); return res.end('no pretty permalinks'); }
  let body = '';
  req.on('data', (c) => (body += c));
  req.on('end', () => {
    seen.auth = req.headers.authorization || '';
    const msg = JSON.parse(body);
    if (msg.method === 'notifications/initialized') { res.writeHead(202); return res.end(); }
    if (msg.method === 'initialize') {
      res.writeHead(200, { 'Content-Type': 'application/json', 'Mcp-Session-Id': 'sess-1' });
      return res.end(JSON.stringify({ jsonrpc: '2.0', id: msg.id, result: { protocolVersion: '2025-06-18', capabilities: {}, serverInfo: { name: 'fake', version: '1' } } }));
    }
    seen.session = req.headers['mcp-session-id'] || '';
    res.writeHead(200, { 'Content-Type': 'text/event-stream' });
    res.end('event: message\ndata: ' + JSON.stringify({ jsonrpc: '2.0', id: msg.id, result: { tools: [{ name: 'x' }] } }) + '\n\n');
  });
});

server.listen(0, '127.0.0.1', () => {
  const port = server.address().port;
  const p = spawn('node', [proxy], { env: Object.assign({}, process.env, { WP_URL: 'https://localhost:' + port, WP_USERNAME: 'admin', WP_APP_PASSWORD: 'abcd efgh ijkl' }) });
  const lines = [];
  let buf = '';
  p.stdout.on('data', (d) => { buf += d; let i; while ((i = buf.indexOf('\n')) >= 0) { lines.push(JSON.parse(buf.slice(0, i))); buf = buf.slice(i + 1); } });
  const wait = (n) => new Promise((ok) => { const t = setInterval(() => { if (lines.length >= n) { clearInterval(t); ok(); } }, 50); setTimeout(() => { clearInterval(t); ok(); }, 15000); });
  p.stdin.write(JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: {} }) + '\n');
  wait(1).then(() => {
    p.stdin.write(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }) + '\n');
    p.stdin.write(JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/list' }) + '\n');
    return wait(2);
  }).then(() => {
    check('self-signed HTTPS on a local host is accepted', lines[0] && lines[0].result && lines[0].result.serverInfo.name === 'fake');
    check('SSE reply is parsed', lines[1] && lines[1].id === 2 && lines[1].result.tools[0].name === 'x');
    check('Basic auth sent (spaces stripped)', seen.auth === 'Basic ' + Buffer.from('admin:abcdefghijkl').toString('base64'));
    check('session id is reused', seen.session === 'sess-1');
    check('plain permalinks fall back to ?rest_route=', seen.urls.some((u) => u.startsWith('POST /?rest_route=%2Fwp-mcp-tools%2Fmcp')), seen.urls.join(' | '));
    p.stdin.end();
    p.on('close', () => { server.close(); console.log(fails ? '\nFAILED' : '\nALL OK'); process.exit(fails ? 1 : 0); });
  });
});
