<?php
/**
 * ScholarHub PHP API Router
 * Handles all /api/* requests on Apache + PHP shared hosting (Hostinger).
 * No frameworks required — pure PHP with cURL for Supabase & Groq.
 */

// ── Config ───────────────────────────────────────────────────────────────────
$configFile = __DIR__ . '/../config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

// Fallback to server environment variables if config.php not present
foreach ([
    'SUPABASE_URL', 'SUPABASE_ANON_KEY', 'SUPABASE_SERVICE_KEY',
    'SESSION_SECRET', 'ADMIN_PASSWORD', 'GROQ_API_KEY',
] as $key) {
    if (!defined($key)) {
        define($key, (string)(getenv($key) ?: ''));
    }
}

// ── CORS + JSON headers ───────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function json_out(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw ?: '{}', true) ?? [];
}

function b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string {
    $pad = (4 - strlen($data) % 4) % 4;
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $pad));
}

function jwt_sign(array $payload): string {
    $header  = b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $pay     = b64url_encode(json_encode($payload));
    $sig     = b64url_encode(hash_hmac('sha256', "$header.$pay", SESSION_SECRET, true));
    return "$header.$pay.$sig";
}

function jwt_verify(string $token): array|false {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$h, $p, $s] = $parts;
    $expected = b64url_encode(hash_hmac('sha256', "$h.$p", SESSION_SECRET, true));
    if (!hash_equals($expected, $s)) return false;
    $data = json_decode(b64url_decode($p), true);
    if (!is_array($data)) return false;
    if (isset($data['exp']) && $data['exp'] < time()) return false;
    return $data;
}

function require_auth(): array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($auth, 'Bearer ')) {
        json_out(['error' => 'Unauthorized'], 401);
    }
    $payload = jwt_verify(substr($auth, 7));
    if (!$payload) {
        json_out(['error' => 'Invalid or expired token'], 401);
    }
    return $payload;
}

/**
 * Make a request to Supabase REST API.
 *
 * @param string     $method        HTTP method
 * @param string     $path          e.g. "scholarships?select=*"
 * @param array|null $body          JSON body (for POST/PATCH)
 * @param bool       $useServiceKey Use service role key (admin) vs anon key (public)
 * @return array{code:int, body:mixed}
 */
function supabase(string $method, string $path, ?array $body = null, bool $useServiceKey = false): array {
    $url = SUPABASE_URL . '/rest/v1/' . $path;
    $key = $useServiceKey ? SUPABASE_SERVICE_KEY : SUPABASE_ANON_KEY;

    $headers = [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'Prefer: return=representation',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        json_out(['error' => 'Supabase connection error: ' . $err], 502);
    }

    return ['code' => $code, 'body' => json_decode($resp ?: '{}', true)];
}

/**
 * Make an HTTP request to an external API (Groq).
 */
function http_post(string $url, array $headers, array $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        json_out(['error' => ['message' => 'External API error: ' . $err]], 502);
    }

    return ['code' => $code, 'body' => json_decode($resp ?: '{}', true)];
}

// ── Rate limiting (file-based, per-IP) ───────────────────────────────────────

function check_rate_limit(string $ip, int $maxPerMinute = 20): bool {
    $dir  = sys_get_temp_dir() . '/sh_rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . md5($ip) . '.json';
    $now  = time();

    $data = ['count' => 0, 'reset' => $now + 60];
    if (file_exists($file)) {
        $stored = json_decode(file_get_contents($file), true) ?? $data;
        if ($now < ($stored['reset'] ?? 0)) {
            $data = $stored;
        }
    }

    if ($data['count'] >= $maxPerMinute) return false;
    $data['count']++;
    file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

// ── Router ────────────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];

// Strip /api prefix from the path
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = ltrim(preg_replace('#^/?api/?#i', '', (string)$uri), '/');

// ── GET /api/health
if ($path === 'health' && $method === 'GET') {
    json_out(['status' => 'ok', 'ts' => time()]);
}

// ── POST /api/auth/login
if ($path === 'auth/login' && $method === 'POST') {
    if (!ADMIN_PASSWORD || !SESSION_SECRET) {
        json_out(['error' => 'Server misconfigured'], 500);
    }
    $body     = get_body();
    $password = (string)($body['password'] ?? '');
    if (!hash_equals(ADMIN_PASSWORD, $password)) {
        json_out(['error' => 'Incorrect password'], 401);
    }
    $token = jwt_sign([
        'role' => 'admin',
        'iat'  => time(),
        'exp'  => time() + 8 * 3600,
    ]);
    json_out(['token' => $token]);
}

// ── GET /api/scholarships  (public — published only)
if ($path === 'scholarships' && $method === 'GET') {
    if (!SUPABASE_URL || !SUPABASE_ANON_KEY) {
        json_out(['error' => 'Database not configured'], 500);
    }
    $r = supabase('GET', 'scholarships?select=*&is_published=eq.true&order=created_at.desc');
    json_out($r['body'], $r['code']);
}

// ── GET /api/scholarships/all  (admin — all records)
if ($path === 'scholarships/all' && $method === 'GET') {
    require_auth();
    $r = supabase('GET', 'scholarships?select=*&order=created_at.desc', null, true);
    json_out($r['body'], $r['code']);
}

// ── POST /api/scholarships  (admin — create)
if ($path === 'scholarships' && $method === 'POST') {
    require_auth();
    $body = get_body();
    if (empty($body['title'])) {
        json_out(['error' => 'title is required'], 400);
    }
    $r = supabase('POST', 'scholarships', $body, true);
    json_out($r['body'], $r['code'] === 201 ? 201 : $r['code']);
}

// ── PATCH /api/scholarships/:id  (admin — update)
if (preg_match('#^scholarships/(\d+)$#', $path, $m) && $method === 'PATCH') {
    require_auth();
    $id   = (int)$m[1];
    $body = get_body();
    if (empty($body)) {
        json_out(['error' => 'No fields to update'], 400);
    }
    $r = supabase('PATCH', "scholarships?id=eq.$id", $body, true);
    if ($r['code'] >= 200 && $r['code'] < 300) {
        json_out(['success' => true]);
    }
    json_out(['error' => $r['body']], $r['code']);
}

// ── DELETE /api/scholarships/:id  (admin — delete)
if (preg_match('#^scholarships/(\d+)$#', $path, $m) && $method === 'DELETE') {
    require_auth();
    $id = (int)$m[1];
    $r  = supabase('DELETE', "scholarships?id=eq.$id", null, true);
    if ($r['code'] >= 200 && $r['code'] < 300) {
        json_out(['success' => true]);
    }
    json_out(['error' => $r['body']], $r['code']);
}

// ── POST /api/ai/chat  and  POST /api/ai/advisor  (Groq proxy)
if (in_array($path, ['ai/chat', 'ai/advisor'], true) && $method === 'POST') {
    if (!GROQ_API_KEY) {
        json_out(['error' => ['message' => 'AI service not configured']], 500);
    }

    // Rate limit by IP
    $ip = (string)(
        ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null)
            ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]
            : ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
    );
    $ip = trim($ip);
    if (!check_rate_limit($ip, 20)) {
        json_out(['error' => ['message' => 'Rate limit exceeded. Try again in a minute.']], 429);
    }

    $body     = get_body();
    $messages = $body['messages'] ?? null;
    if (!is_array($messages) || empty($messages)) {
        json_out(['error' => ['message' => 'messages array is required']], 400);
    }

    $allowed = ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768'];
    $model   = (string)($body['model'] ?? 'llama-3.3-70b-versatile');
    if (!in_array($model, $allowed, true)) {
        json_out(['error' => ['message' => 'Model not allowed. Use: ' . implode(', ', $allowed)]], 400);
    }

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'max_tokens'  => min((int)($body['max_tokens'] ?? 800), 1500),
        'temperature' => min(max((float)($body['temperature'] ?? 0.7), 0.0), 1.0),
    ];

    $r = http_post(
        'https://api.groq.com/openai/v1/chat/completions',
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ],
        $payload
    );
    json_out($r['body'], $r['code']);
}

// ── 404 fallback ──────────────────────────────────────────────────────────────
json_out(['error' => 'Not found', 'path' => $path], 404);
