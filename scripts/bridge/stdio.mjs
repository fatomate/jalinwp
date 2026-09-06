#!/usr/bin/env node
/** Dependency-free stdio transport for the JalinWP JSON endpoint. */
import { constants } from 'node:fs';
import { open } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

/** @typedef {string | number} RpcId */
/** @typedef {Record<string, unknown>} RpcMessage */
/** @typedef {{ url: string, authorization: string }} BridgeConfig */
/** @typedef {{ forward(message: RpcMessage): Promise<RpcMessage | null> }} BridgeProxy */
/** @typedef {(input: RequestInfo | URL, init?: RequestInit) => Promise<Response>} FetchImplementation */

export const MAX_BYTES = 1024 * 1024;
export const TIMEOUT_MS = 30_000;
export const FALLBACK_PROTOCOL = '2025-03-26';
export const SUPPORTED_PROTOCOLS = Object.freeze(['2025-11-25', '2025-06-18', FALLBACK_PROTOCOL]);

export class ConfigError extends Error {}
export class TransportError extends Error {
  /** @param {string} message @param {number} [code] */
  constructor(message, code = -32098) {
    super(message);
    this.code = code;
  }
}

/** @param {unknown} value @returns {string} */
export function validateUrl(value) {
  if (typeof value !== 'string' || !value || /[\s\u0000-\u001f\u007f]/u.test(value)) {
    throw new ConfigError('Set FG_MCP_URL to the full HTTPS MCP endpoint.');
  }
  let url;
  try { url = new URL(value); } catch {
    throw new ConfigError('FG_MCP_URL must be a valid absolute HTTPS URL.');
  }
  if (!/^https:\/\//i.test(value) || value.includes('\\') || url.protocol !== 'https:' || !url.hostname || url.username || url.password || /^https:\/\/[^/?#]*@/i.test(value) || value.includes('?') || value.includes('#')) {
    throw new ConfigError('FG_MCP_URL requires HTTPS and must not contain credentials, a query, or a fragment.');
  }
  return url.href;
}

/** A file takes precedence over an environment password, without printing either. */
export async function loadConfig(env = process.env) {
  if (env.NODE_TLS_REJECT_UNAUTHORIZED === '0') {
    throw new ConfigError('TLS verification must remain enabled; remove NODE_TLS_REJECT_UNAUTHORIZED=0.');
  }
  const url = validateUrl(env.FG_MCP_URL);
  const user = env.FG_WP_USER;
  if (typeof user !== 'string' || !user.trim() || user.length > 256 || /[:\u0000-\u001f\u007f]/u.test(user)) {
    throw new ConfigError('Set FG_WP_USER to a WordPress username without colons or control characters.');
  }
  let password = env.FG_WP_APP_PASSWORD;
  if (env.FG_WP_APP_PASSWORD_FILE) {
    let handle;
    try {
      // Do not follow a symlink on systems supporting O_NOFOLLOW.
      handle = await open(env.FG_WP_APP_PASSWORD_FILE, constants.O_RDONLY | (constants.O_NOFOLLOW || 0));
      const stat = await handle.stat();
      if (!stat.isFile() || stat.size > 4096) {
        throw new ConfigError('The application-password file must be a regular file no larger than 4096 bytes.');
      }
      if (process.platform !== 'win32' && ((stat.mode & 0o077) !== 0 || (process.getuid && stat.uid !== process.getuid()))) {
        throw new ConfigError('The application-password file must belong to this user and have mode 600 or 400.');
      }
      const bytes = Buffer.alloc(4097);
      let size = 0;
      while (size < bytes.length) {
        const read = await handle.read(bytes, size, bytes.length - size, null);
        if (read.bytesRead === 0) break;
        size += read.bytesRead;
      }
      if (size > 4096) throw new ConfigError('The application-password file is too large.');
      password = new TextDecoder('utf-8', { fatal: true }).decode(bytes.subarray(0, size)).trim();
    } catch (error) {
      if (error instanceof ConfigError) throw error;
      throw new ConfigError('Cannot read FG_WP_APP_PASSWORD_FILE securely; check its location and permissions.');
    } finally {
      if (handle) await handle.close();
    }
  }
  if (typeof password !== 'string' || !password.trim() || Buffer.byteLength(password) > 4096 || /[\u0000-\u001f\u007f]/u.test(password)) {
    throw new ConfigError('Set FG_WP_APP_PASSWORD_FILE (preferred) or FG_WP_APP_PASSWORD to a WordPress Application Password.');
  }
  return Object.freeze({ url, authorization: `Basic ${Buffer.from(`${user}:${password.trim()}`, 'utf8').toString('base64')}` });
}

/** @param {unknown} value @returns {value is RpcMessage} */
const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value);
/** @param {RpcMessage} value @param {string} name */
const hasOwn = (value, name) => Object.prototype.hasOwnProperty.call(value, name);
/** @param {unknown} id @returns {id is RpcId} */
const validId = id => typeof id === 'string' || (typeof id === 'number' && Number.isFinite(id));

/** @param {unknown} message @returns {'notification' | 'request' | 'response' | null} */
export function classifyMessage(message) {
  if (!isObject(message) || message.jsonrpc !== '2.0') return null;
  if (typeof message.method === 'string' && message.method.length > 0) {
    if (hasOwn(message, 'result') || hasOwn(message, 'error')) return null;
    if (hasOwn(message, 'params') && !isObject(message.params) && !Array.isArray(message.params)) return null;
    if (!hasOwn(message, 'id')) return 'notification';
    return validId(message.id) ? 'request' : null;
  }
  if (hasOwn(message, 'method') || !hasOwn(message, 'id') || !validId(message.id)) return null;
  const result = hasOwn(message, 'result');
  const error = hasOwn(message, 'error');
  if (result === error) return null;
  if (error && (!isObject(message.error) || !Number.isInteger(message.error.code) || typeof message.error.message !== 'string')) return null;
  return 'response';
}

/** @param {Response} response */
async function cancelBody(response) {
  try { await response.body?.cancel(); } catch { /* Never log remote exception details. */ }
}

/** @param {Response} response @param {number} [maxBytes] */
export async function readBoundedBody(response, maxBytes = MAX_BYTES) {
  const length = response.headers.get('content-length');
  if (length !== null && (!/^\d+$/.test(length) || Number(length) > maxBytes)) {
    await cancelBody(response);
    throw new TransportError('Gateway response exceeds the allowed body size.');
  }
  if (!response.body) return '';
  const reader = response.body.getReader();
  const parts = [];
  let lengthRead = 0;
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      lengthRead += value.byteLength;
      if (lengthRead > maxBytes) {
        await reader.cancel();
        throw new TransportError('Gateway response exceeds the allowed body size.');
      }
      parts.push(Buffer.from(value));
    }
    return new TextDecoder('utf-8', { fatal: true }).decode(Buffer.concat(parts, lengthRead));
  } finally {
    reader.releaseLock();
  }
}

/** Forward one message at a time, including notifications, to preserve lifecycle ordering. */
/**
 * @param {BridgeConfig} config
 * @param {{ fetchImpl?: FetchImplementation, timeoutMs?: number, maxBytes?: number }} [options]
 * @returns {BridgeProxy}
 */
export function createProxy(config, { fetchImpl = globalThis.fetch, timeoutMs = TIMEOUT_MS, maxBytes = MAX_BYTES } = {}) {
  let protocolVersion = FALLBACK_PROTOCOL;
  /** @type {Promise<void>} */
  let queue = Promise.resolve();

  /** @param {RpcMessage} message @returns {Promise<RpcMessage | null>} */
  async function send(message) {
    const kind = classifyMessage(message);
    if (!kind) throw new TransportError('Invalid JSON-RPC message.', -32600);
    const body = JSON.stringify(message);
    if (Buffer.byteLength(body) > maxBytes) throw new TransportError('MCP message exceeds the allowed line size.', -32600);
    const controller = new AbortController();
    let timer;
    const expired = new Promise((_, reject) => {
      timer = setTimeout(() => {
        controller.abort();
        reject(new TransportError('HTTPS request timed out; its outcome may be unknown. No automatic retry was attempted.', -32097));
      }, timeoutMs);
    });
    const operation = async () => {
      const response = await fetchImpl(config.url, {
        method: 'POST',
        redirect: 'error',
        signal: controller.signal,
        headers: {
          Authorization: config.authorization,
          'Content-Type': 'application/json',
          Accept: 'application/json, text/event-stream',
          'MCP-Protocol-Version': protocolVersion,
        },
        body,
      });
      if (response.redirected || (response.status >= 300 && response.status < 400)) {
        await cancelBody(response);
        throw new TransportError('Gateway redirects are refused. Configure the final HTTPS MCP endpoint.');
      }
      if (!response.ok) {
        await cancelBody(response);
        throw new TransportError(`Gateway returned HTTP ${response.status}. Check credentials, gateway access, and endpoint settings. No automatic retry was attempted.`);
      }
      if (kind !== 'request') {
        const text = await readBoundedBody(response, maxBytes);
        if (response.status !== 202 || text.length !== 0) {
          throw new TransportError('The gateway must acknowledge notifications and client responses with an empty HTTP 202.');
        }
        return null;
      }
      if (response.status !== 200 || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json') {
        await cancelBody(response);
        throw new TransportError('Expected an HTTP 200 JSON response from the JalinWP gateway. Streaming responses are not supported by this bridge.');
      }
      const text = await readBoundedBody(response, maxBytes);
      /** @type {unknown} */
      let reply;
      try { reply = JSON.parse(text); } catch { throw new TransportError('The gateway returned invalid JSON.'); }
      if (!isObject(reply) || classifyMessage(reply) !== 'response' || reply.id !== message.id) {
        throw new TransportError('The gateway returned an invalid or mismatched JSON-RPC response.');
      }
      if (message.method === 'initialize' && isObject(reply.result)) {
        if (controller.signal.aborted) throw new TransportError('The initialization request timed out.', -32097);
        const selected = reply.result.protocolVersion;
        if (typeof selected !== 'string' || !SUPPORTED_PROTOCOLS.includes(selected)) {
          throw new TransportError('The gateway negotiated an unsupported MCP protocol version.');
        }
        protocolVersion = selected;
      }
      return reply;
    };
    try {
      return await Promise.race([operation(), expired]);
    } catch (error) {
      if (error instanceof TransportError) throw error;
      throw new TransportError('HTTPS transport failed; the request outcome may be unknown. Check connectivity and TLS. No automatic retry was attempted.');
    } finally {
      clearTimeout(timer);
    }
  }

  return {
    forward(message) {
      const pending = queue.then(() => send(message));
      queue = pending.then(() => {}, () => {});
      return pending;
    },
  };
}

/** Byte-bounded framing; overlong lines are discarded through their next newline. */
/**
 * @param {AsyncIterable<Uint8Array | string>} input
 * @param {number} [maxBytes]
 * @returns {AsyncGenerator<{ oversized: true } | { bytes: Buffer }>}
 */
export async function* readLines(input, maxBytes = MAX_BYTES) {
  /** @type {Buffer[]} */
  let parts = [];
  let bytes = 0;
  let oversized = false;
  const finish = () => {
    /** @type {{ oversized: true } | { bytes: Buffer }} */
    const item = oversized ? { oversized: true } : { bytes: Buffer.concat(parts, bytes) };
    parts = [];
    bytes = 0;
    oversized = false;
    return item;
  };
  for await (const chunk of input) {
    const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
    let start = 0;
    while (start < buffer.length) {
      const newline = buffer.indexOf(10, start);
      const end = newline === -1 ? buffer.length : newline;
      const segment = buffer.subarray(start, end);
      if (!oversized) {
        bytes += segment.length;
        if (bytes > maxBytes) { oversized = true; parts = []; }
        else if (segment.length) parts.push(Buffer.from(segment));
      }
      if (newline !== -1) yield finish();
      start = end + 1;
    }
  }
  if (bytes > 0 || oversized) yield finish();
}

/** @param {RpcId | null} id @param {number} code @param {string} message */
export const rpcError = (id, code, message) => ({ jsonrpc: '2.0', id, error: { code, message } });

/** @param {import('node:stream').Writable} stream @param {RpcMessage} message */
async function writeLine(stream, message) {
  await new Promise((resolve, reject) => {
    stream.write(`${JSON.stringify(message)}\n`, error => error ? reject(error) : resolve(undefined));
  });
}

/**
 * @param {{ input?: AsyncIterable<Uint8Array | string>, output?: import('node:stream').Writable, errorOutput?: import('node:stream').Writable, proxy: BridgeProxy, maxBytes?: number }} options
 */
export async function serve({ input = process.stdin, output = process.stdout, errorOutput = process.stderr, proxy, maxBytes = MAX_BYTES }) {
  for await (const line of readLines(input, maxBytes)) {
    if ('oversized' in line) {
      await writeLine(output, rpcError(null, -32600, 'MCP message exceeds the allowed line size.'));
      continue;
    }
    let message;
    try {
      const text = new TextDecoder('utf-8', { fatal: true }).decode(line.bytes).trim();
      if (!text) continue;
      message = JSON.parse(text);
    } catch {
      await writeLine(output, rpcError(null, -32700, 'Invalid UTF-8 or JSON input.'));
      continue;
    }
    const kind = classifyMessage(message);
    // Even malformed identifiable notifications never receive a JSON-RPC reply.
    const notificationLike = isObject(message) && typeof message.method === 'string' && !hasOwn(message, 'id');
    if (!kind) {
      if (notificationLike) errorOutput.write('JalinWP bridge: invalid notification discarded.\n');
      else await writeLine(output, rpcError(isObject(message) && validId(message.id) ? message.id : null, -32600, 'Invalid JSON-RPC message.'));
      continue;
    }
    try {
      const reply = await proxy.forward(message);
      if (kind === 'request' && reply !== null) await writeLine(output, reply);
    } catch (error) {
      const safe = error instanceof TransportError ? error : new TransportError('The bridge could not complete the HTTPS request.');
      if (kind === 'request') await writeLine(output, rpcError(message.id, safe.code, safe.message));
      else errorOutput.write(`JalinWP bridge: ${safe.message}\n`);
    }
  }
}

export async function main() {
  try {
    if (Number(process.versions.node.split('.')[0]) < 22) throw new ConfigError('Node.js 22 or newer is required.');
    const config = await loadConfig();
    await serve({ proxy: createProxy(config) });
  } catch (error) {
    const message = error instanceof ConfigError ? error.message : 'Bridge stopped because its input, output, or local runtime failed.';
    process.stderr.write(`JalinWP bridge: ${message}\n`);
    process.exitCode = 1;
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) await main();
