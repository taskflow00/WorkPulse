<?php

require_once __DIR__ . '/../config/supabase.php';

// Keep sessions for 30 days.
define('WORKPULSE_SESSION_LIFETIME', 60 * 60 * 24 * 30);


// Store PHP sessions in the Supabase database.
class WorkPulseSessionHandler implements
    SessionHandlerInterface,
    SessionUpdateTimestampHandlerInterface
{
    private function request(
        string $method,
        string $query,
        ?array $payload = null
    ): array|false {

        $url = SUPABASE_URL .
            '/rest/v1/workpulse_sessions' .
            $query;

        $headers = [
            'apikey: ' . SUPABASE_SERVICE_ROLE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
            'Prefer: resolution=merge-duplicates'
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        $curl = curl_init($url);

        curl_setopt_array($curl, $options);

        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);


        if ($body === false) {
            return false;
        }

        return [
            'status' => $status,
            'body' => $body
        ];
    }


    public function open(string $path, string $name): bool
    {
        return true;
    }


    public function close(): bool
    {
        return true;
    }


    public function read(string $id): string|false
    {
        $query = '?select=data,expires_at&id=eq.' .
            rawurlencode($id) .
            '&limit=1';

        $result = $this->request('GET', $query);

        if (
            $result === false ||
            $result['status'] !== 200
        ) {
            return false;
        }

        $rows = json_decode($result['body'], true);

        if (empty($rows[0])) {
            return '';
        }

        // Reject expired sessions.
        if (strtotime($rows[0]['expires_at']) <= time()) {
            $this->destroy($id);

            return '';
        }

        return $rows[0]['data'];
    }


    public function write(string $id, string $data): bool
    {
        $expiresAt = gmdate(
            'Y-m-d\TH:i:s\Z',
            time() + WORKPULSE_SESSION_LIFETIME
        );

        $payload = [
            'id' => $id,
            'data' => $data,
            'expires_at' => $expiresAt,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z')
        ];

        $result = $this->request(
            'POST',
            '?on_conflict=id',
            $payload
        );

        return (
            $result !== false &&
            $result['status'] >= 200 &&
            $result['status'] < 300
        );
    }


    public function destroy(string $id): bool
    {
        $query = '?id=eq.' . rawurlencode($id);

        $result = $this->request('DELETE', $query);

        return (
            $result !== false &&
            $result['status'] >= 200 &&
            $result['status'] < 300
        );
    }


    public function gc(int $max_lifetime): int|false
    {
        $now = rawurlencode(gmdate('Y-m-d\TH:i:s\Z'));

        $result = $this->request(
            'DELETE',
            '?expires_at=lt.' . $now
        );

        if (
            $result === false ||
            $result['status'] < 200 ||
            $result['status'] >= 300
        ) {
            return false;
        }

        return 0;
    }


    // Check whether a session ID still exists and has not expired.
    public function validateId(string $id): bool
    {
        $query = '?select=id,expires_at&id=eq.' .
            rawurlencode($id) .
            '&limit=1';

        $result = $this->request('GET', $query);

        if (
            $result === false ||
            $result['status'] !== 200
        ) {
            return false;
        }

        $rows = json_decode($result['body'], true);

        if (empty($rows[0])) {
            return false;
        }

        if (strtotime($rows[0]['expires_at']) <= time()) {
            $this->destroy($id);

            return false;
        }

        return true;
    }


    // Extend the session expiration whenever the user returns.
    public function updateTimestamp(
        string $id,
        string $data
    ): bool {

        $expiresAt = gmdate(
            'Y-m-d\TH:i:s\Z',
            time() + WORKPULSE_SESSION_LIFETIME
        );

        $payload = [
            'expires_at' => $expiresAt,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z')
        ];

        $result = $this->request(
            'PATCH',
            '?id=eq.' . rawurlencode($id),
            $payload
        );

        return (
            $result !== false &&
            $result['status'] >= 200 &&
            $result['status'] < 300
        );
    }
}


// The server-side key is required to access the protected session table.
if (SUPABASE_SERVICE_ROLE_KEY === '') {
    http_response_code(500);
    exit('Session storage is not configured.');
}


// Configure PHP sessions.
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.gc_maxlifetime', (string) WORKPULSE_SESSION_LIFETIME);
ini_set('session.cookie_lifetime', (string) WORKPULSE_SESSION_LIFETIME);

if (headers_sent($file, $line)) {
    error_log("HEADERS ALREADY SENT: $file:$line");
} else {
    error_log("HEADERS NOT SENT YET");
}


// Detect HTTPS, including when running behind a reverse proxy.
$isSecure = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
);


// Give the WorkPulse session its own cookie name.
session_name('WORKPULSE_SESSION');

session_set_cookie_params([
    'lifetime' => WORKPULSE_SESSION_LIFETIME,
    'path' => '/',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax'
]);


// Register the database session handler.
$sessionHandler = new WorkPulseSessionHandler();

if (!session_set_save_handler($sessionHandler, true)) {
    http_response_code(500);
    exit('Unable to initialize session storage.');
}


// Start the session.
if (!session_start()) {
    http_response_code(500);
    exit('Unable to start your session.');
}


// Refresh the browser cookie expiration on every request.
// This makes the 30-day expiration a sliding expiration.
$cookie = session_get_cookie_params();

setcookie(session_name(), session_id(), [
    'expires' => time() + WORKPULSE_SESSION_LIFETIME,
    'path' => $cookie['path'],
    'domain' => $cookie['domain'],
    'secure' => $cookie['secure'],
    'httponly' => $cookie['httponly'],
    'samesite' => $cookie['samesite'] ?? 'Lax'
]);


// Destroy the current session and expire its browser cookie.
function workpulse_end_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];

        session_destroy();
    }

    $cookie = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?? 'Lax'
    ]);
}
    