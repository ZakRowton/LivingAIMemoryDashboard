/**
 * MemoryGraph MCP sidecar — keeps MCP sessions warm (stdio subprocess or streamable HTTP).
 * Bind to loopback only. Configure via env (see README).
 */
'use strict';

const http = require('http');
const crypto = require('crypto');
const { Client } = require('@modelcontextprotocol/sdk/client/index.js');
const { StdioClientTransport } = require('@modelcontextprotocol/sdk/client/stdio.js');
const { StreamableHTTPClientTransport } = require('@modelcontextprotocol/sdk/client/streamableHttp.js');

const HOST = process.env.MEMORYGRAPH_MCP_SIDECAR_HOST || '127.0.0.1';
const PORT = parseInt(process.env.MEMORYGRAPH_MCP_SIDECAR_PORT || '8765', 10);
const IDLE_MS = parseInt(process.env.MEMORYGRAPH_MCP_SIDECAR_IDLE_MS || String(15 * 60 * 1000), 10);
const AUTH_TOKEN = (process.env.MEMORYGRAPH_MCP_PROXY_SECRET || '').trim();

const sessions = new Map();
const mutexByKey = new Map();

function effectiveTransport(server) {
  let t = String(server.transport || 'stdio').toLowerCase().trim();
  const url = String(server.url || '').trim();
  const cmd = String(server.command || '').trim();
  if ((t === '' || t === 'stdio') && url !== '' && cmd === '') {
    return 'streamablehttp';
  }
  return t || 'stdio';
}

function buildHttpHeaders(server) {
  const headers = {};
  const h = server.headers && typeof server.headers === 'object' ? server.headers : {};
  const env = server.env && typeof server.env === 'object' ? server.env : {};
  for (const [k, v] of Object.entries(h)) {
    if (typeof k === 'string' && k.trim() !== '') {
      headers[k.trim().toLowerCase()] = String(v);
    }
  }
  for (const [k, v] of Object.entries(env)) {
    if (typeof k !== 'string' || !k.trim()) continue;
    const nk = k.trim();
    const hyphen = nk.replace(/_/g, '-').toLowerCase();
    if (headers[nk.toLowerCase()] === undefined) {
      headers[nk.toLowerCase()] = String(v);
    }
    if (headers[hyphen] === undefined) {
      headers[hyphen] = String(v);
    }
  }
  return headers;
}

function toPlainJson(obj) {
  try {
    return JSON.parse(JSON.stringify(obj, (_k, v) => (typeof v === 'bigint' ? v.toString() : v)));
  } catch {
    return obj;
  }
}

function sessionMeta(key, extra) {
  const s = sessions.get(key);
  if (!s) return null;
  return {
    serverKey: key,
    transport: s.transport,
    connectedAt: s.connectedAt,
    lastUsed: s.lastUsed,
    callCount: s.callCount,
    lastError: s.lastError,
    ...extra,
  };
}

async function disposeSession(key) {
  const s = sessions.get(key);
  if (!s) return;
  sessions.delete(key);
  try {
    if (s.client) {
      await s.client.close();
    }
  } catch (_) {
    /* ignore */
  }
  s.client = null;
  s.transport = null;
}

function touchSession(key) {
  const s = sessions.get(key);
  if (s) {
    s.lastUsed = Date.now();
  }
}

function getMutex(key) {
  if (!mutexByKey.has(key)) {
    let chain = Promise.resolve();
    mutexByKey.set(key, (fn) => {
      const next = chain.then(() => fn());
      chain = next.catch(() => {});
      return next;
    });
  }
  return mutexByKey.get(key);
}

async function connectSession(serverKey, server) {
  await disposeSession(serverKey);
  const transportType = effectiveTransport(server);
  let transport;
  if (transportType === 'streamablehttp' || transportType === 'streamable_http' || transportType === 'http') {
    const url = String(server.url || '').trim();
    if (!url) {
      throw new Error('streamablehttp MCP server requires url');
    }
    const hdrs = buildHttpHeaders(server);
    const fetchHeaders = {
      Accept: 'application/json, text/event-stream',
      'Content-Type': 'application/json',
      ...hdrs,
    };
    transport = new StreamableHTTPClientTransport(new URL(url), {
      requestInit: { headers: fetchHeaders },
    });
  } else {
    const command = String(server.command || '').trim();
    if (!command) {
      throw new Error('stdio MCP server requires command');
    }
    const args = Array.isArray(server.args) ? server.args.map(String) : [];
    const env = server.env && typeof server.env === 'object' ? { ...process.env, ...Object.fromEntries(Object.entries(server.env).map(([k, v]) => [k, String(v)])) } : undefined;
    const cwd = String(server.cwd || '').trim();
    transport = new StdioClientTransport({
      command,
      args,
      env,
      cwd: cwd && cwd.length ? cwd : undefined,
      stderr: 'pipe',
    });
  }

  const client = new Client({ name: 'memorygraph-mcp-sidecar', version: '1.0.0' }, { capabilities: {} });
  await client.connect(transport);

  sessions.set(serverKey, {
    client,
    transport,
    transportType,
    connectedAt: Date.now(),
    lastUsed: Date.now(),
    callCount: 0,
    lastError: null,
  });
}

async function ensureSession(serverKey, server) {
  const existing = sessions.get(serverKey);
  if (existing && existing.client) {
    touchSession(serverKey);
    return existing;
  }
  await connectSession(serverKey, server);
  return sessions.get(serverKey);
}

async function withSession(serverKey, server, fn) {
  const run = getMutex(serverKey);
  return run(async () => {
    let lastErr;
    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        const s = await ensureSession(serverKey, server);
        s.callCount++;
        touchSession(serverKey);
        const out = await fn(s.client);
        s.lastError = null;
        return out;
      } catch (e) {
        lastErr = e;
        const entry = sessions.get(serverKey);
        if (entry) {
          entry.lastError = String(e && e.message ? e.message : e);
        }
        await disposeSession(serverKey);
      }
    }
    throw lastErr;
  });
}

async function listTools(serverKey, server) {
  return withSession(serverKey, server, async (client) => {
    const r = await client.listTools();
    const tools = Array.isArray(r && r.tools) ? r.tools.map((t) => toPlainJson(t)) : [];
    return { tools };
  });
}

async function callTool(serverKey, server, toolName, args) {
  return withSession(serverKey, server, async (client) => {
    const r = await client.callTool({
      name: toolName,
      arguments: args && typeof args === 'object' ? args : {},
    });
    return toPlainJson(r);
  });
}

function sweepIdle() {
  const now = Date.now();
  for (const [key, s] of sessions.entries()) {
    if (IDLE_MS > 0 && now - s.lastUsed > IDLE_MS) {
      disposeSession(key).catch(() => {});
    }
  }
}

if (IDLE_MS > 0) {
  setInterval(sweepIdle, Math.min(IDLE_MS / 2, 120000));
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8');
      if (!raw.trim()) {
        resolve({});
        return;
      }
      try {
        resolve(JSON.parse(raw));
      } catch (e) {
        reject(e);
      }
    });
    req.on('error', reject);
  });
}

function sendJson(res, code, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(code, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
  });
  res.end(body);
}

function checkAuth(req, res) {
  if (!AUTH_TOKEN) return true;
  const h = req.headers['x-memorygraph-mcp-proxy'] || req.headers['x-memorygraph_mcp_proxy'];
  if (h !== AUTH_TOKEN) {
    sendJson(res, 401, { error: 'unauthorized' });
    return false;
  }
  return true;
}

const server = http.createServer(async (req, res) => {
  if (!checkAuth(req, res)) return;

  const url = new URL(req.url || '/', `http://${HOST}`);

  if (req.method === 'GET' && url.pathname === '/health') {
    const list = [];
    for (const key of sessions.keys()) {
      list.push(sessionMeta(key));
    }
    sendJson(res, 200, {
      ok: true,
      host: HOST,
      port: PORT,
      idleMs: IDLE_MS,
      sessionCount: sessions.size,
      sessions: list,
    });
    return;
  }

  if (req.method !== 'POST') {
    sendJson(res, 405, { error: 'method_not_allowed' });
    return;
  }

  try {
    const body = await readBody(req);

    if (url.pathname === '/invalidate') {
      const sk = body.serverKey;
      if (sk && typeof sk === 'string') {
        await disposeSession(sk);
        sendJson(res, 200, { ok: true, invalidated: sk });
        return;
      }
      for (const k of [...sessions.keys()]) {
        await disposeSession(k);
      }
      sendJson(res, 200, { ok: true, invalidated: 'all' });
      return;
    }

    if (url.pathname === '/v1/list-tools') {
      const serverKey = body.serverKey;
      const serverCfg = body.server;
      if (!serverKey || typeof serverKey !== 'string' || !serverCfg || typeof serverCfg !== 'object') {
        sendJson(res, 400, { error: 'serverKey and server required' });
        return;
      }
      const t0 = Date.now();
      const result = await listTools(serverKey, serverCfg);
      sendJson(res, 200, { ok: true, ms: Date.now() - t0, ...result });
      return;
    }

    if (url.pathname === '/v1/call') {
      const serverKey = body.serverKey;
      const serverCfg = body.server;
      const toolName = body.toolName;
      const args = body.arguments;
      if (!serverKey || typeof serverKey !== 'string' || !serverCfg || typeof serverCfg !== 'object' || !toolName || typeof toolName !== 'string') {
        sendJson(res, 400, { error: 'serverKey, server, and toolName required' });
        return;
      }
      const t0 = Date.now();
      const result = await callTool(serverKey, serverCfg, toolName, args && typeof args === 'object' ? args : {});
      sendJson(res, 200, { ok: true, ms: Date.now() - t0, result });
      return;
    }

    sendJson(res, 404, { error: 'not_found' });
  } catch (e) {
    sendJson(res, 500, { ok: false, error: String(e && e.message ? e.message : e) });
  }
});

server.listen(PORT, HOST, () => {
  console.error(`[mcp-sidecar] listening on http://${HOST}:${PORT} idle=${IDLE_MS}ms auth=${AUTH_TOKEN ? 'on' : 'off'}`);
});
