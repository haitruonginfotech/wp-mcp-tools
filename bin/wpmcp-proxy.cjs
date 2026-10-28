#!/usr/bin/env node
/**
 * WP MCP Tools — stdio <-> HTTP proxy for MCP clients that only speak stdio (Claude Desktop) or that
 * cannot reach a local site (https://my-site.local) from the cloud.
 *
 * The client starts this file as a local subprocess. It reads JSON-RPC lines from stdin, POSTs them to the
 * site's MCP endpoint with HTTP Basic auth (WordPress Application Password) and writes the replies to stdout.
 * Node.js 18+, no dependencies. Plain CommonJS, so it works as a .cjs/.js file and inside a .mcpb bundle.
 *
 * Environment:
 *   WP_URL            Site address, e.g. https://my-site.local          (required)
 *   WP_USERNAME       WordPress user                                     (required)
 *   WP_APP_PASSWORD   Application Password                              (required)
 *   MCP_PROTOCOL_VERSION  Optional: force this protocol version in "initialize".
 *   WPMCP_INSECURE_TLS    "1" = accept any certificate (hosts *.local, *.test, *.localhost, localhost, 127.0.0.1 always do).
 *   MCP_LOG_FILE      Optional path for a debug log (stderr is used otherwise, errors only).
 *
 * @version 0.9.0
 */
'use strict';

const http = require('http');
const https = require('https');
const fs = require('fs');
const readline = require('readline');

const VERSION = '0.9.0';
const UA = 'wpmcp-proxy/' + VERSION + ' (+https://github.com/haitruonginfotech/wp-mcp-tools)';
const REST_PATH = '/wp-mcp-tools/mcp';

function log(msg) {
  const line = '[wpmcp-proxy] ' + msg + '\n';
  if (process.env.MCP_LOG_FILE) {
    try { fs.appendFileSync(process.env.MCP_LOG_FILE, new Date().toISOString() + ' ' + line); } catch (e) { /* ignore */ }
  } else if (/error|fail|refus|invalid/i.test(msg)) {
    process.stderr.write(line);
  }
}

function die(msg) {
  process.stderr.write('[wpmcp-proxy] ' + msg + '\n');
  process.exit(1);
}

/** Parse WP_URL. Returns { base, parsed, isLocal, pw, auth }. Exported for tests. */
function configure(env) {
  const raw = String(env.WP_URL || '').trim();
  if (!raw) throw new Error('WP_URL is not set (e.g. https://my-site.local).');
  let parsed;
  try { parsed = new URL(/^https?:\/\//i.test(raw) ? raw : 'https://' + raw); } catch (e) { throw new Error('WP_URL is not a valid URL: ' + raw); }
  const user = String(env.WP_USERNAME || '').trim();
  const pass = String(env.WP_APP_PASSWORD || '').replace(/\s+/g, '');
  if (!user || !pass) throw new Error('WP_USERNAME and WP_APP_PASSWORD are required (create an Application Password under Users > Profile).');
  const host = parsed.hostname.toLowerCase();
  const isLocal = String(env.WPMCP_INSECURE_TLS || '') === '1'
    || /\.(test|local|localhost|invalid)$/.test(host) || host === 'localhost' || host === '127.0.0.1' || host === '[::1]' || host === '::1';
  const base = parsed.origin + parsed.pathname.replace(/\/+$/, '');
  return { base, parsed, isLocal, auth: 'Basic ' + Buffer.from(user + ':' + pass).toString('base64') };
}

/** One HTTP request. Resolves { status, headers, body }. */
function request(cfg, method, url, headers, payload) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const lib = u.protocol === 'https:' ? https : http;
    const opts = { method, headers: Object.assign({ 'User-Agent': UA }, headers), timeout: 120000 };
    if (u.protocol === 'https:' && cfg.isLocal) opts.rejectUnauthorized = false;
    const req = lib.request(u, opts, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => resolve({ status: res.statusCode || 0, headers: res.headers, body: Buffer.concat(chunks).toString('utf8') }));
    });
    req.on('timeout', () => req.destroy(new Error('Request timed out after 120 s')));
    req.on('error', reject);
    if (payload) req.write(payload);
    req.end();
  });
}

/** Turn a response body (JSON or text/event-stream) into an array of JSON-RPC messages. Exported for tests. */
function parseBody(contentType, body) {
  const text = String(body || '').trim();
  if (!text) return [];
  if (/text\/event-stream/i.test(contentType || '')) {
    const out = [];
    for (const block of text.split(/\r?\n\r?\n/)) {
      const data = block.split(/\r?\n/).filter((l) => l.startsWith('data:')).map((l) => l.slice(5).replace(/^ /, '')).join('\n');
      if (!data) continue;
      try { out.push(JSON.parse(data)); } catch (e) { log('invalid SSE data skipped'); }
    }
    return out;
  }
  const parsed = JSON.parse(text);
  return Array.isArray(parsed) ? parsed : [parsed];
}

function rpcError(id, message, data) {
  return { jsonrpc: '2.0', id, error: { code: -32000, message, data } };
}

function hint(status, cfg) {
  if (status === 401 || status === 403) return 'Check WP_USERNAME / WP_APP_PASSWORD (Application Passwords must be allowed; on HTTP they need WP_ENVIRONMENT_TYPE=local). A firewall/CDN may also block the request (HTTP ' + status + ').';
  if (status === 404) return 'The MCP endpoint was not found: is WP MCP Tools active, and is the MCP server switched on?';
  if (status >= 500) return 'The site returned a server error. Check the WordPress debug log.';
  return '';
}

function main() {
  let cfg;
  try { cfg = configure(process.env); } catch (e) { die(e.message); }

  let endpoint = null;   // resolved once
  let session = '';
  let protocol = '';     // negotiated protocol version, sent as MCP-Protocol-Version on later requests
  let initMsg = null;
  let ready = null;      // promise resolved after the first initialize reply
  const pending = new Set();

  const send = (obj) => process.stdout.write(JSON.stringify(obj) + '\n');

  async function resolveEndpoint() {
    if (endpoint) return endpoint;
    let pretty = true;
    try {
      const r = await request(cfg, 'GET', cfg.base + '/wp-json/', { Accept: 'application/json' });
      pretty = r.status < 400 && /json/i.test(String(r.headers['content-type'] || ''));
    } catch (e) { log('permalink probe failed: ' + e.message); }
    endpoint = pretty ? cfg.base + '/wp-json' + REST_PATH : cfg.base + '/?rest_route=' + encodeURIComponent(REST_PATH);
    log('endpoint ' + endpoint + (pretty ? ' (pretty permalinks)' : ' (plain permalinks)'));
    return endpoint;
  }

  async function post(msg, retried) {
    const url = await resolveEndpoint();
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: cfg.auth };
    if (session) headers['Mcp-Session-Id'] = session;
    if (protocol && msg.method !== 'initialize') headers['MCP-Protocol-Version'] = protocol;
    const res = await request(cfg, 'POST', url, headers, JSON.stringify(msg));
    if (res.headers['mcp-session-id']) session = String(res.headers['mcp-session-id']);
    // Session expired on the server: initialize again once, then retry.
    if (res.status === 404 && session && !retried && initMsg && msg.method !== 'initialize') {
      log('session lost, re-initializing');
      session = '';
      const re = await post(Object.assign({}, initMsg, { id: 'wpmcp-reinit' }), true);
      if (re.status < 400) { await post({ jsonrpc: '2.0', method: 'notifications/initialized' }, true); return post(msg, true); }
    }
    return res;
  }

  async function handle(msg) {
    const isRequest = msg && msg.id !== undefined && msg.method;
    try {
      if (msg.method === 'initialize') {
        if (process.env.MCP_PROTOCOL_VERSION) msg.params = Object.assign({}, msg.params, { protocolVersion: process.env.MCP_PROTOCOL_VERSION });
        initMsg = msg;
        session = '';
        let done;
        ready = new Promise((r) => { done = r; });
        try { await forward(msg, isRequest); } finally { done(); }
        return;
      }
      if (msg.method === 'notifications/initialized') {
        // Later requests must wait until the server has seen this notification (the server tracks the handshake per session).
        const prev = ready;
        ready = (async () => {
          if (prev) await prev;
          try { await forward(msg, false); } catch (e) { log('error: ' + e.message); }
        })();
        await ready;
        return;
      }
      if (ready) await ready;
      await forward(msg, isRequest);
    } catch (e) {
      log('error: ' + e.message);
      if (isRequest) send(rpcError(msg.id, 'Could not reach the site: ' + e.message, { url: cfg.base }));
    }
  }

  async function forward(msg, isRequest) {
    const res = await post(msg, false);
    if (res.status >= 400) {
      log('HTTP ' + res.status + ' for ' + (msg.method || 'message'));
      if (isRequest) {
        let detail = '';
        try { const j = JSON.parse(res.body); const e = j.message || j.error || ''; detail = typeof e === 'string' ? e : (e.message || JSON.stringify(e)); } catch (e) { detail = String(res.body).slice(0, 200).replace(/\s+/g, ' '); }
        send(rpcError(msg.id, 'HTTP ' + res.status + (detail ? ': ' + detail : ''), { hint: hint(res.status, cfg) }));
      }
      return;
    }
    // A notification never gets a reply on stdout (some servers answer with an empty result anyway).
    if (!isRequest) return;
    for (const m of parseBody(res.headers['content-type'], res.body)) {
      if (msg.method === 'initialize' && m && m.result && m.result.protocolVersion) protocol = String(m.result.protocolVersion);
      send(m);
    }
  }

  const rl = readline.createInterface({ input: process.stdin, terminal: false });
  rl.on('line', (line) => {
    const t = line.trim();
    if (!t) return;
    let msg;
    try { msg = JSON.parse(t); } catch (e) { log('invalid JSON from client skipped'); return; }
    const p = handle(msg).finally(() => pending.delete(p));
    pending.add(p);
  });
  rl.on('close', () => { Promise.allSettled([...pending]).then(() => process.exit(0)); });
  log('started for ' + cfg.base + (cfg.isLocal ? ' (local host: TLS verification off)' : ''));
}

if (require.main === module) main();
module.exports = { configure, parseBody, VERSION };
