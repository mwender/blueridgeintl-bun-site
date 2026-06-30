<?php
/**
 * Contact-form handler for blueridgelogistics.com (static + PHP-FPM deploy).
 *
 * Sends submissions through SMTP2GO's HTTP email API
 * (https://developers.smtp2go.com/reference/send-standard-email).
 *
 * The front-end form (src/routes/contact.tsx) POSTs here via fetch and expects
 * a JSON response: { ok: true } on success, { ok: false, error } otherwise.
 *
 * Deployed to /api/contact.php by `bun run build:php` (scripts/html-to-php.mjs
 * copies server/contact.php into dist/client/api/; config/sample files are
 * intentionally NOT copied — see below).
 *
 * ── Configuration ──────────────────────────────────────────────────────────
 * Secrets live OUTSIDE the web root. The handler looks for config in this
 * order:
 *   1. $BRL_CONTACT_CONFIG env var → absolute path to a PHP file returning an
 *      array (highest precedence).
 *   2. Default: <one level above the document root>/contact-config.php
 *      (e.g. if the web root is /…/site/public, this is /…/site/contact-config.php).
 *   3. Env vars: SMTP2GO_API_KEY, SMTP2GO_SENDER, CONTACT_TO.
 *
 * Copy server/contact-config.sample.php to that location and fill it in.
 */

// ── Helpers ────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

function fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// Strip CR/LF so submitted values can't inject extra mail headers.
function clean_header(string $v): string {
    return trim(str_replace(["\r", "\n", "%0a", "%0d"], '', $v));
}

/**
 * Resolve SMTP2GO config from a file above the web root, falling back to env
 * vars. Returns an array with at least api_key/sender/to, or null if unset.
 */
function load_contact_config(): ?array {
    $path = getenv('BRL_CONTACT_CONFIG') ?: '';
    if ($path === '' && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $path = dirname($_SERVER['DOCUMENT_ROOT']) . '/contact-config.php';
    }
    if ($path !== '' && is_file($path)) {
        $cfg = include $path;
        if (is_array($cfg)) {
            return $cfg;
        }
    }
    // Fallback: environment variables.
    $key = getenv('SMTP2GO_API_KEY');
    if ($key) {
        return [
            'api_key' => $key,
            'sender'  => getenv('SMTP2GO_SENDER') ?: 'no-reply@blueridgelogistics.com',
            'to'      => getenv('CONTACT_TO') ?: '',
        ];
    }
    return null;
}

// ── Method guard ─────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail(405, 'Method not allowed.');
}

// ── Honeypot: bots fill hidden fields; humans don't. Pretend success. ──────
if (!empty($_POST['website'])) {
    echo json_encode(['ok' => true]);
    exit;
}

// ── Collect + validate ─────────────────────────────────────────────────────
$name    = clean_header(trim($_POST['name']    ?? ''));
$company = clean_header(trim($_POST['company'] ?? ''));
$email   = clean_header(trim($_POST['email']   ?? ''));
$phone   = clean_header(trim($_POST['phone']   ?? ''));
$lane    = clean_header(trim($_POST['lane']    ?? ''));
$message = trim($_POST['message'] ?? '');

if ($name === '' || $email === '' || $message === '') {
    fail(422, 'Please fill in your name, email, and message.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(422, 'Please enter a valid email address.');
}

// ── Config ─────────────────────────────────────────────────────────────────
$config = load_contact_config();
if (!$config || empty($config['api_key']) || empty($config['sender']) || empty($config['to'])) {
    error_log('contact.php: SMTP2GO config missing or incomplete (api_key/sender/to).');
    fail(500, 'The contact form is not configured yet. Please email or call us directly.');
}

// ── Compose ─────────────────────────────────────────────────────────────
$subject = 'Quote request from ' . $name . ($company !== '' ? " ({$company})" : '');

$body = "New contact form submission from blueridgelogistics.com\n\n"
      . "Name:     {$name}\n"
      . "Company:  " . ($company !== '' ? $company : '—') . "\n"
      . "Email:    {$email}\n"
      . "Phone:    " . ($phone !== '' ? $phone : '—') . "\n"
      . "Lane:     " . ($lane !== '' ? $lane : '—') . "\n\n"
      . "Message:\n{$message}\n";

$payload = [
    'sender'  => $config['sender'],
    'to'      => is_array($config['to']) ? $config['to'] : [$config['to']],
    'subject' => clean_header($subject),
    'text_body' => $body,
    // Replies go to the visitor, not the no-reply sender.
    'custom_headers' => [
        ['header' => 'Reply-To', 'value' => $name . ' <' . $email . '>'],
    ],
];

// ── Send via SMTP2GO HTTP API ───────────────────────────────────────────────
$ch = curl_init('https://api.smtp2go.com/v3/email/send');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Smtp2go-Api-Key: ' . $config['api_key'],
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log('contact.php: SMTP2GO request failed: ' . $curlErr);
    fail(502, 'Could not reach the mail server. Please try again or call our ops line.');
}

$result    = json_decode($response, true);
$succeeded = $result['data']['succeeded'] ?? 0;

if ($httpCode !== 200 || (int) $succeeded < 1) {
    $apiError = $result['data']['error'] ?? ('HTTP ' . $httpCode);
    error_log('contact.php: SMTP2GO send failed: ' . $apiError . ' :: ' . $response);
    fail(502, 'Your message could not be sent. Please try again or call our ops line.');
}

echo json_encode(['ok' => true]);
