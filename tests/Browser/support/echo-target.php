<?php

declare(strict_types=1);

/*
 * The plain-http target TlsProxySmokeTest puts behind tls-proxy.mjs, served with
 * PHP's built-in server. It echoes what arrived (method, raw path, raw query
 * string, headers, body) as JSON and sets distinctive response headers, including
 * CORS ones, so the test can prove the proxy passes both directions verbatim.
 */

$headers = [];

foreach (getallheaders() as $name => $value) {
    $headers[strtolower((string) $name)] = $value;
}

$body = (string) file_get_contents('php://input');

$payload = json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'query' => $_SERVER['QUERY_STRING'] ?? '',
    'headers' => $headers,
    'body_base64' => base64_encode($body),
    'body_sha256' => hash('sha256', $body),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

http_response_code(($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS' ? 200 : 201);

header('Content-Type: application/json');
header('Content-Length: '.strlen($payload));
header('X-Scarlett-Echo: target');
header('Access-Control-Allow-Origin: '.($headers['origin'] ?? 'null'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Vary: Origin');

echo $payload;
