import test from 'node:test';
import assert from 'node:assert/strict';
import { Readable, Writable } from 'node:stream';
import { mkdtemp, writeFile, chmod, rm, symlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import {
  ConfigError, TransportError, MAX_BYTES, FALLBACK_PROTOCOL, SUPPORTED_PROTOCOLS,
  validateUrl, loadConfig, classifyMessage, createProxy, readLines, serve,
} from './stdio.mjs';

const endpoint = 'https://shop.example/wp-json/fames-mcp/v1/mcp';
const secret = 'test-app-password-do-not-print';
const basic = `Basic ${Buffer.from(`mcp-user:${secret}`).toString('base64')}`;
const config = { url: endpoint, authorization: basic };
const request = (id = 1, method = 'tools/list', params) => ({ jsonrpc: '2.0', id, method, ...(params ? { params } : {}) });
const notification = { jsonrpc: '2.0', method: 'notifications/initialized' };
const response = (id = 1, result = { tools: [] }) => ({ jsonrpc: '2.0', id, result });
const jsonResponse = data => new Response(JSON.stringify(data), { headers: { 'Content-Type': 'application/json; charset=UTF-8' } });

function capture() {
  let text = '';
  const stream = new Writable({ write(chunk, encoding, done) { text += chunk.toString(); done(); } });
  return { stream, text: () => text };
}

async function run(messages, fetchImpl, options = {}) {
  const output = capture();
  const errors = capture();
  const chunks = Array.isArray(messages) ? messages.map(message => `${JSON.stringify(message)}\n`) : [messages];
  await serve({
    input: Readable.from(chunks), output: output.stream, errorOutput: errors.stream,
    proxy: createProxy(config, { fetchImpl, ...options }),
    ...(options.maxBytes ? { maxBytes: options.maxBytes } : {}),
  });
  return { output: output.text(), errors: errors.text(), replies: output.text().trim() ? output.text().trim().split('\n').map(JSON.parse) : [] };
}

test('HTTPS endpoint accepts a WordPress subdirectory and rejects unsafe or ambiguous URLs', () => {
  assert.equal(validateUrl(endpoint), endpoint);
  assert.equal(validateUrl('https://shop.example/wordpress/wp-json/fames-mcp/v1/mcp'), 'https://shop.example/wordpress/wp-json/fames-mcp/v1/mcp');
  for (const value of [undefined, '', 'http://shop.example/mcp', 'file:///mcp', 'https:shop.example/mcp', 'https://u:p@shop.example/mcp', 'https://@shop.example/mcp', `${endpoint}?`, `${endpoint}?token=${secret}`, `${endpoint}#`, `${endpoint}#fragment`, 'https://shop.example/\npath', 'https://shop.example\\path']) {
    assert.throws(() => validateUrl(value), ConfigError);
  }
});

test('environment credentials are encoded only in Authorization and TLS bypass is rejected', async () => {
  const env = { FG_MCP_URL: endpoint, FG_WP_USER: 'mcp-user', FG_WP_APP_PASSWORD: secret };
  assert.deepEqual(await loadConfig(env), config);
  await assert.rejects(loadConfig({ ...env, NODE_TLS_REJECT_UNAUTHORIZED: '0' }), /TLS verification/);
  await assert.rejects(loadConfig({ ...env, FG_WP_USER: 'name:password' }), ConfigError);
  await assert.rejects(loadConfig({ ...env, FG_WP_APP_PASSWORD: 'bad\nsecret' }), ConfigError);
  await assert.rejects(loadConfig({ ...env, FG_WP_APP_PASSWORD: '' }), ConfigError);
});

test('a restricted credential file wins over env; permissive files and symlinks are refused on Unix', async t => {
  const dir = await mkdtemp(join(tmpdir(), 'fames-bridge-test-'));
  t.after(() => rm(dir, { recursive: true, force: true }));
  const file = join(dir, 'application-password');
  await writeFile(file, `${secret}\n`, { mode: 0o600 });
  const env = { FG_MCP_URL: endpoint, FG_WP_USER: 'mcp-user', FG_WP_APP_PASSWORD: 'ignored-env-secret', FG_WP_APP_PASSWORD_FILE: file };
  assert.deepEqual(await loadConfig(env), config);
  if (process.platform !== 'win32') {
    await chmod(file, 0o644);
    await assert.rejects(loadConfig(env), /mode 600 or 400/);
    await chmod(file, 0o600);
    const link = join(dir, 'link');
    await symlink(file, link);
    await assert.rejects(loadConfig({ ...env, FG_WP_APP_PASSWORD_FILE: link }), ConfigError);
  }
  await writeFile(file, 'x'.repeat(4097));
  await assert.rejects(loadConfig(env), ConfigError);
  await assert.rejects(loadConfig({ ...env, FG_WP_APP_PASSWORD_FILE: join(dir, 'missing') }), error => {
    assert.ok(!error.message.includes(dir));
    return error instanceof ConfigError;
  });
});

test('initialize, initialized notification, and tool request forward serially with negotiated headers', async () => {
  const seen = [];
  let inFlight = 0;
  let highestInFlight = 0;
  const proxy = createProxy(config, { fetchImpl: async (url, init) => {
    inFlight++;
    highestInFlight = Math.max(highestInFlight, inFlight);
    assert.equal(url, endpoint);
    assert.equal(init.method, 'POST');
    assert.equal(init.redirect, 'error');
    assert.equal(init.headers.Authorization, basic);
    assert.equal(init.headers.Accept, 'application/json, text/event-stream');
    assert.equal(init.headers['Content-Type'], 'application/json');
    assert.ok(init.signal instanceof AbortSignal);
    const message = JSON.parse(init.body);
    seen.push({ message, protocol: init.headers['MCP-Protocol-Version'] });
    await new Promise(resolve => setImmediate(resolve));
    inFlight--;
    if (message.method === 'initialize') return jsonResponse(response(message.id, { protocolVersion: '2025-11-25', capabilities: { tools: {} }, serverInfo: { name: 'fames', version: '0.1.0' } }));
    if (!Object.hasOwn(message, 'id')) return new Response(null, { status: 202 });
    return jsonResponse(response(message.id));
  } });
  const results = await Promise.all([
    proxy.forward(request(1, 'initialize', { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'test', version: '1' } })),
    proxy.forward(notification),
    proxy.forward(request(2)),
  ]);
  assert.equal(highestInFlight, 1);
  assert.deepEqual(seen.map(item => item.message.method), ['initialize', 'notifications/initialized', 'tools/list']);
  assert.deepEqual(seen.map(item => item.protocol), [FALLBACK_PROTOCOL, '2025-11-25', '2025-11-25']);
  assert.equal(results[1], null);
  assert.equal(results[2].id, 2);
});

test('all supported negotiated versions are forwarded; unsupported negotiation is rejected', async () => {
  for (const version of SUPPORTED_PROTOCOLS) {
    let header;
    const proxy = createProxy(config, { fetchImpl: async (_, init) => {
      const message = JSON.parse(init.body);
      header = init.headers['MCP-Protocol-Version'];
      return jsonResponse(response(message.id, message.method === 'initialize' ? { protocolVersion: version } : {}));
    } });
    await proxy.forward(request(1, 'initialize'));
    await proxy.forward(request(2, 'ping'));
    assert.equal(header, version);
  }
  const result = await run([request(1, 'initialize')], async () => jsonResponse(response(1, { protocolVersion: '2099-01-01' })));
  assert.equal(result.replies[0].error.code, -32098);
});

test('successful notifications and client responses produce no stdout replies', async () => {
  const result = await run([notification, response(3, {})], async () => new Response(null, { status: 202 }));
  assert.equal(result.output, '');
  assert.equal(result.errors, '');
});

test('notification HTTP failures or nonempty acknowledgements remain stderr-only and sanitized', async () => {
  for (const fetchImpl of [
    async () => new Response(secret, { status: 401 }),
    async () => new Response(secret, { status: 202 }),
    async () => { throw new Error(`private authorization: ${basic}; ${secret}`); },
  ]) {
    const result = await run([notification], fetchImpl);
    assert.equal(result.output, '');
    assert.ok(result.errors.includes('JalinWP bridge:'));
    assert.ok(!result.errors.includes(secret));
    assert.ok(!result.errors.includes(basic));
  }
});

test('a valid tool result and JSON-RPC application error are passed through unchanged', async () => {
  const messages = [response(1, { content: [{ type: 'text', text: 'Product saved.' }], isError: false }), { jsonrpc: '2.0', id: 2, error: { code: -32602, message: 'Unknown product.' } }];
  let index = 0;
  const result = await run([request(1, 'tools/call'), request(2, 'tools/call')], async () => jsonResponse(messages[index++]));
  assert.deepEqual(result.replies, messages);
  assert.equal(result.errors, '');
});

test('HTTP failures, redirects, invalid bodies, and mismatched responses return errors to the request id without leaking secrets', async () => {
  for (const fetchImpl of [
    async () => new Response(`${secret} ${basic}`, { status: 403 }),
    async () => new Response(secret, { status: 302, headers: { Location: `https://elsewhere.example/${secret}` } }),
    async () => new Response(secret, { headers: { 'Content-Type': 'text/html' } }),
    async () => new Response(secret, { headers: { 'Content-Type': 'text/event-stream' } }),
    async () => new Response(secret, { headers: { 'Content-Type': 'application/json' } }),
    async () => jsonResponse(response('wrong-id', { private: secret })),
    async () => new Response(null, { status: 202 }),
    async () => { throw new Error(`${secret} ${basic}`); },
  ]) {
    const result = await run([request('original-id', 'tools/call')], fetchImpl);
    assert.equal(result.replies.length, 1);
    assert.equal(result.replies[0].id, 'original-id');
    assert.equal(result.replies[0].error.code, -32098);
    assert.ok(!result.output.includes(secret));
    assert.ok(!result.output.includes(basic));
    assert.equal(result.errors, '');
  }
});

test('timeouts abort the fetch and never retry a potentially completed write', async () => {
  let calls = 0;
  let observedSignal;
  const result = await run([request(77, 'tools/call', { name: 'woocommerce_update_product' })], async (_, init) => {
    calls++;
    observedSignal = init.signal;
    await new Promise((resolve, reject) => init.signal.addEventListener('abort', () => reject(new Error(secret)), { once: true }));
  }, { timeoutMs: 15 });
  assert.equal(calls, 1);
  assert.equal(observedSignal.aborted, true);
  assert.equal(result.replies[0].id, 77);
  assert.equal(result.replies[0].error.code, -32097);
  assert.match(result.replies[0].error.message, /outcome may be unknown/);
  assert.ok(!result.output.includes(secret));
});

test('the deadline covers streaming body reads too', async () => {
  let signal;
  const result = await run([request(8)], async (_, init) => {
    signal = init.signal;
    const stream = new ReadableStream({
      start(controller) {
        init.signal.addEventListener('abort', () => controller.error(new Error(secret)), { once: true });
        controller.enqueue(new TextEncoder().encode('{'));
      },
    });
    return new Response(stream, { headers: { 'Content-Type': 'application/json' } });
  }, { timeoutMs: 15 });
  assert.equal(signal.aborted, true);
  assert.equal(result.replies[0].error.code, -32097);
});

test('oversized declared or streamed responses are refused and their bodies cancelled', async () => {
  for (const declaredLength of [false, true]) {
    let cancelled = false;
    const stream = new ReadableStream({
      start(controller) { controller.enqueue(new Uint8Array(MAX_BYTES + 1)); },
      cancel() { cancelled = true; },
    });
    const result = await run([request(9)], async () => new Response(stream, {
      headers: { 'Content-Type': 'application/json', ...(declaredLength ? { 'Content-Length': String(MAX_BYTES + 1) } : {}) },
    }));
    assert.equal(cancelled, true);
    assert.equal(result.replies[0].error.code, -32098);
    assert.match(result.replies[0].error.message, /body size/);
  }
});

test('chunked UTF-8 and CRLF framing work; oversized lines recover without forwarding their payload', async () => {
  const valid = `${JSON.stringify(request('café'))}\r\n`;
  const encoded = Buffer.from(valid);
  const split = encoded.indexOf(0xc3) + 1;
  const chunks = [Buffer.alloc(600_000, 120), Buffer.alloc(600_000, 120), Buffer.from('\n'), encoded.subarray(0, split), encoded.subarray(split)];
  const lines = [];
  for await (const line of readLines(Readable.from(chunks))) lines.push(line);
  assert.equal(lines.length, 2);
  assert.equal(lines[0].oversized, true);
  assert.equal(lines[1].bytes.toString(), valid.trimEnd() + '\r');
  let calls = 0;
  const result = await run(`${'x'.repeat(MAX_BYTES + 1)}\n${valid}`, async (_, init) => {
    calls++;
    return jsonResponse(response(JSON.parse(init.body).id));
  });
  assert.equal(calls, 1);
  assert.equal(result.replies[0].error.code, -32600);
  assert.equal(result.replies[1].id, 'café');
});

test('malformed JSON, batch arrays, invalid notifications and invalid UTF-8 do not reach HTTPS', async () => {
  let calls = 0;
  const result = await run(`not-json\n[]\n${JSON.stringify({ ...notification, params: false })}\n`, async () => { calls++; });
  assert.equal(calls, 0);
  assert.deepEqual(result.replies.map(reply => reply.error.code), [-32700, -32600]);
  assert.match(result.errors, /invalid notification/);
  const invalidUtf8 = await run(Buffer.from([0xff, 10]), async () => { calls++; });
  assert.equal(invalidUtf8.replies[0].error.code, -32700);
  assert.equal(calls, 0);
  assert.equal(classifyMessage({ ...request(), id: null }), null);
  assert.equal(classifyMessage({ ...response(), error: { code: 1, message: 'duplicate' } }), null);
});

test('a failed request does not poison the serial queue', async () => {
  let calls = 0;
  const proxy = createProxy(config, { fetchImpl: async (_, init) => {
    calls++;
    if (calls === 1) throw new Error(secret);
    return jsonResponse(response(JSON.parse(init.body).id));
  } });
  const [first, second] = await Promise.allSettled([proxy.forward(request(1)), proxy.forward(request(2))]);
  assert.equal(first.status, 'rejected');
  assert.ok(first.reason instanceof TransportError);
  assert.equal(second.status, 'fulfilled');
  assert.equal(second.value.id, 2);
});

test('CLI configuration failures stay on stderr, with no credentials or endpoint secrets', () => {
  const script = fileURLToPath(new URL('./stdio.mjs', import.meta.url));
  const result = spawnSync(process.execPath, [script], {
    encoding: 'utf8', env: { FG_MCP_URL: `https://user:${secret}@shop.example/mcp`, FG_WP_USER: 'mcp-user', FG_WP_APP_PASSWORD: secret },
  });
  assert.equal(result.status, 1);
  assert.equal(result.stdout, '');
  assert.match(result.stderr, /requires HTTPS/);
  assert.ok(!result.stderr.includes(secret));
  assert.ok(!result.stderr.includes(basic));
});
