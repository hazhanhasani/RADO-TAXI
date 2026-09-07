<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$root = dirname(__DIR__, 2);
$config = require $root . '/rado-system/config.php';
$secretsFile = $root . '/rado-system/private/ci-secrets.php';

function fail(int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}
function b64urlDecode(string $data): string {
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode($data, true);
    if ($decoded === false) fail(401, 'invalid token encoding');
    return $decoded;
}
function httpJson(string $url, string $ua): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ' . $ua],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) fail(503, 'OIDC key service unavailable');
    $json = json_decode((string) $body, true);
    if (!is_array($json)) fail(503, 'invalid OIDC key response');
    return $json;
}
function bearerToken(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) fail(401, 'missing bearer token');
    return trim($m[1]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, 'method not allowed');
if (!is_file($secretsFile)) fail(503, 'RADO CI secrets are not configured');
if (!extension_loaded('openssl') || !extension_loaded('curl')) fail(503, 'required PHP extensions unavailable');

$jwt = bearerToken();
$parts = explode('.', $jwt);
if (count($parts) !== 3) fail(401, 'invalid token');
[$h64, $p64, $s64] = $parts;
$header = json_decode(b64urlDecode($h64), true);
$claims = json_decode(b64urlDecode($p64), true);
if (!is_array($header) || !is_array($claims)) fail(401, 'invalid token payload');
if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) fail(401, 'unsupported token');

$now = time();
if (($claims['iss'] ?? '') !== $config['oidc_issuer']) fail(401, 'invalid issuer');
$aud = $claims['aud'] ?? null;
$audOk = is_string($aud)
    ? hash_equals($config['oidc_audience'], $aud)
    : (is_array($aud) && in_array($config['oidc_audience'], $aud, true));
if (!$audOk) fail(401, 'invalid audience');
if ((int)($claims['exp'] ?? 0) < $now - 30) fail(401, 'expired token');
if ((int)($claims['nbf'] ?? 0) > $now + 30) fail(401, 'token not active');
if (($claims['repository'] ?? '') !== $config['repo']) fail(403, 'repository not allowed');
$ref = (string)($claims['ref'] ?? '');
if (!($ref === 'refs/heads/main' || str_starts_with($ref, 'refs/tags/v'))) fail(403, 'ref not allowed');
$event = (string)($claims['event_name'] ?? '');
if (!in_array($event, ['push', 'workflow_dispatch'], true)) fail(403, 'event not allowed');

$cacheDir = $root . '/rado-system/state';
@mkdir($cacheDir, 0755, true);
$cacheFile = $cacheDir . '/github-oidc-jwks.json';
$jwks = null;
if (is_file($cacheFile) && filemtime($cacheFile) > $now - 3600) {
    $jwks = json_decode((string)file_get_contents($cacheFile), true);
}
if (!is_array($jwks)) {
    $jwks = httpJson('https://token.actions.githubusercontent.com/.well-known/jwks', $config['user_agent']);
    @file_put_contents($cacheFile, json_encode($jwks, JSON_UNESCAPED_SLASHES), LOCK_EX);
}
$key = null;
foreach (($jwks['keys'] ?? []) as $candidate) {
    if (($candidate['kid'] ?? '') === $header['kid']) { $key = $candidate; break; }
}
if (!is_array($key) || empty($key['x5c'][0])) fail(401, 'signing key not found');
$pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split((string)$key['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
$verified = openssl_verify($h64 . '.' . $p64, b64urlDecode($s64), $pem, OPENSSL_ALGO_SHA256);
if ($verified !== 1) fail(401, 'invalid token signature');

$secrets = require $secretsFile;
$keystorePath = $root . '/rado-system/private/rado-release.keystore';
if (!is_file($keystorePath)) fail(503, 'signing keystore missing');
foreach (['store_password','key_alias','key_password','neshan_map_key'] as $required) {
    if (!isset($secrets[$required]) || trim((string)$secrets[$required]) === '') fail(503, 'CI secrets incomplete');
}

echo json_encode([
    'ok' => true,
    'keystore_base64' => base64_encode((string)file_get_contents($keystorePath)),
    'store_password' => (string)$secrets['store_password'],
    'key_alias' => (string)$secrets['key_alias'],
    'key_password' => (string)$secrets['key_password'],
    'neshan_map_key' => (string)$secrets['neshan_map_key'],
], JSON_UNESCAPED_SLASHES);
