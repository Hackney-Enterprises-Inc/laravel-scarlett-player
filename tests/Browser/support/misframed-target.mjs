// A raw TCP target for TlsProxySmokeTest that frames a 204 the way the Pest
// browser plugin's amphp server does: `transfer-encoding: chunked` plus a chunk
// terminator after a response that by definition has no body. Node's HTTP client
// rejects those trailing bytes once the 204 is complete, which is the case the
// proxy must survive without turning the answer into a 502.
//
//   node misframed-target.mjs <port>

import net from 'node:net';

const port = Number(process.argv[2] || 48004);

const server = net.createServer((socket) => {
    let buffered = '';

    socket.on('data', (chunk) => {
        buffered += chunk.toString('latin1');

        if (!buffered.includes('\r\n\r\n')) {
            return;
        }

        socket.end(
            'HTTP/1.1 204 No Content\r\n'
            + 'access-control-allow-origin: http://127.0.0.1:8001\r\n'
            + 'access-control-allow-credentials: true\r\n'
            + 'x-scarlett-echo: misframed\r\n'
            + 'transfer-encoding: chunked\r\n'
            + '\r\n'
            + '0\r\n\r\n',
        );
    });
});

server.listen(port, '127.0.0.1', () => console.log(`misframed target listening on ${port}`));

process.on('SIGTERM', () => server.close(() => process.exit(0)));
