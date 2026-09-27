// A TLS-terminating pass-through proxy for the browser group.
//
// The analytics plugin attaches the API key only when beaconUrl parses as https:
// (analytics/src/helpers.ts isHttpsUrl(), no localhost exception), so the page is
// given https://127.0.0.1:8443 as its beaconUrl and this proxy forwards every
// request to the plain-http ingest on 127.0.0.1:8002.
//
// The browser test asserts CORS behaviour through this proxy, so it must be
// invisible: OPTIONS is forwarded like any other method, request headers go out
// exactly as they arrived (Origin and Host included), the body is streamed byte
// for byte, the path and query string are untouched (the unload beacon carries
// ?api_key=), and the target's status and response headers come back verbatim.
// The proxy never adds or strips a header of its own, except on the 502 it
// answers when the target cannot be reached. The one normalisation: a header
// name repeated with different case (Set-Cookie, then set-cookie) goes out with
// every value, in order, under its first spelling.
//
// Environment:
//   SCARLETT_PROXY_LISTEN   host:port to listen on        (default 127.0.0.1:8443)
//   SCARLETT_PROXY_TARGET   plain-http origin to forward to (default http://127.0.0.1:8002)
//   SCARLETT_PROXY_CERT     certificate path (default tests/Fixtures/tls/cert.pem)
//   SCARLETT_PROXY_KEY      private key path (default tests/Fixtures/tls/key.pem)
//   SCARLETT_PROXY_LOG      1 to log each request and its outcome to stderr
//
// Run make-cert.sh first. On start it prints one line:
//   tls-proxy listening on https://HOST:PORT -> TARGET

import { readFileSync } from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');

const listen = process.env.SCARLETT_PROXY_LISTEN || '127.0.0.1:8443';
const target = new URL(process.env.SCARLETT_PROXY_TARGET || 'http://127.0.0.1:8002');
const certPath = process.env.SCARLETT_PROXY_CERT || resolve(root, 'tests/Fixtures/tls/cert.pem');
const keyPath = process.env.SCARLETT_PROXY_KEY || resolve(root, 'tests/Fixtures/tls/key.pem');
const logging = process.env.SCARLETT_PROXY_LOG === '1';

/** A log line on stderr, never on the wire. */
function log(line) {
    if (logging) {
        process.stderr.write(`tls-proxy ${new Date().toISOString()} ${line}\n`);
    }
}

const separator = listen.lastIndexOf(':');
const listenHost = separator > 0 ? listen.slice(0, separator) : '127.0.0.1';
const listenPort = Number(separator > 0 ? listen.slice(separator + 1) : listen);

if (target.protocol !== 'http:') {
    console.error(`tls-proxy: SCARLETT_PROXY_TARGET must be a plain http origin, got ${target.href}`);
    process.exit(1);
}

if (!Number.isInteger(listenPort) || listenPort < 1 || listenPort > 65535) {
    console.error(`tls-proxy: SCARLETT_PROXY_LISTEN must be host:port, got ${listen}`);
    process.exit(1);
}

let cert;
let key;

try {
    cert = readFileSync(certPath);
    key = readFileSync(keyPath);
} catch (error) {
    console.error(`tls-proxy: cannot read the certificate (${error.message}); run make-cert.sh first`);
    process.exit(1);
}

// Headers Node's http layer writes on its own when a message does not carry them.
// Removing one that the other side did not send stops Node inventing it, which
// is what keeps the pass-through exact in both directions.
const IMPLICIT_REQUEST = ['connection', 'host', 'transfer-encoding'];
const IMPLICIT_RESPONSE = ['connection', 'date', 'keep-alive', 'transfer-encoding'];

/**
 * Copies raw headers ([name, value, name, value, ...]) onto an outgoing message in
 * their original order, keeping each name's first spelling and every repeated
 * value, then removes whichever implicit header the source did not have. Array
 * headers passed to writeHead() or http.request() cannot do the removal: Node
 * appends Connection to them regardless.
 */
function copyHeaders(rawHeaders, outgoing, implicit) {
    const present = new Map();

    for (let i = 0; i < rawHeaders.length; i += 2) {
        const lower = rawHeaders[i].toLowerCase();
        const entry = present.get(lower) || { name: rawHeaders[i], values: [] };
        entry.values.push(rawHeaders[i + 1]);
        present.set(lower, entry);
    }

    for (const { name, values } of present.values()) {
        outgoing.setHeader(name, values.length === 1 ? values[0] : values);
    }

    for (const name of implicit) {
        if (!present.has(name)) {
            outgoing.removeHeader(name);
        }
    }
}

const server = https.createServer({ cert, key }, (req, res) => {
    log(`> ${req.method} ${req.url} origin=${req.headers.origin || '-'}`);
    res.on('finish', () => log(`< ${res.statusCode} ${req.method} ${req.url}`));
    const upstream = http.request({
        protocol: target.protocol,
        hostname: target.hostname,
        port: target.port || 80,
        method: req.method,
        // req.url is the raw request target: path plus query string, unparsed.
        path: req.url,
        agent: false,
        setHost: false,
    });

    copyHeaders(req.rawHeaders, upstream, IMPLICIT_REQUEST);

    let responded = false;

    upstream.on('response', (response) => {
        responded = true;
        res.sendDate = false;
        res.statusCode = response.statusCode;
        res.statusMessage = response.statusMessage;
        copyHeaders(response.rawHeaders, res, IMPLICIT_RESPONSE);
        // Out now, so nothing the target does afterwards can replace them.
        res.flushHeaders();
        response.pipe(res);
    });

    upstream.on('error', (error) => {
        log(`! ${req.method} ${req.url} ${error.code || error.message} responded=${responded}`);
        // An error after the target answered is the target misframing its own
        // response, not the target being down. The plugin's amphp server sends
        // `transfer-encoding: chunked` and a chunk terminator on a 204, which the
        // client parser rejects once the 204 is complete. The response already
        // started, so it is finished as received rather than turned into a 502.
        if (responded) {
            if (!res.writableEnded) {
                res.end();
            }

            return;
        }

        res.writeHead(502, { 'Content-Type': 'text/plain', 'X-Scarlett-Proxy-Error': '1' });
        res.end(`tls-proxy: ${target.origin} is unreachable (${error.code || error.message})\n`);
    });

    req.pipe(upstream);
});

const sockets = new Set();

server.on('connection', (socket) => {
    sockets.add(socket);
    socket.on('close', () => sockets.delete(socket));
});

server.on('error', (error) => {
    console.error(`tls-proxy: ${error.message}`);
    process.exit(1);
});

server.listen(listenPort, listenHost, () => {
    console.log(`tls-proxy listening on https://${listenHost}:${listenPort} -> ${target.origin}`);
});

function shutdown() {
    server.close(() => process.exit(0));

    for (const socket of sockets) {
        socket.destroy();
    }
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
