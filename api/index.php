<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Missing api/config.php. Copy api/config.example.php to api/config.php and update the settings.']);
    exit;
}
$config = require $configFile;

function security_headers(array $config): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header_remove('X-Powered-By');
    if (!empty($config['security']['content_security_policy'])) {
        header('Content-Security-Policy: ' . $config['security']['content_security_policy']);
    }
}
security_headers($config);

function json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function text_response(int $status, string $payload, string $contentType = 'text/plain; charset=utf-8'): void
{
    http_response_code($status);
    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo $payload;
    exit;
}

function db(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $db = $config['db'];
    $charset = $db['charset'] ?? 'utf8mb4';
    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$charset}";
    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function route_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/api', PHP_URL_PATH) ?: '/api';
    $pos = strpos($path, '/api');
    if ($pos !== false) $path = substr($path, $pos);
    $path = rtrim($path, '/');
    return $path === '' ? '/api' : $path;
}

function parse_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) json_response(400, ['error' => 'Invalid JSON body.']);
    return $data;
}

function app_secret(array $config): string
{
    $secret = (string)($config['security']['app_secret'] ?? '');
    if (strlen($secret) < 32 || strpos($secret, 'replace-with') === 0) {
        json_response(500, ['error' => 'api/config.php needs a unique strong security.app_secret value. Generate one before using the site.']);
    }
    return $secret;
}

function secret_hash(string $value, array $config): string
{
    return hash_hmac('sha256', $value, app_secret($config));
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function ip_hash(array $config): string
{
    return secret_hash(client_ip(), $config);
}

function user_agent(): string
{
    return clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 255);
}

function clean_string($value, int $max = 5000): string
{
    $value = str_replace("\r", '', (string)($value ?? ''));
    $value = trim($value);
    if (function_exists('mb_substr')) return mb_substr($value, 0, $max);
    return substr($value, 0, $max);
}

function clean_email($value): string
{
    return strtolower(clean_string($value, 254));
}

function is_email(string $value): bool
{
    return (bool)filter_var($value, FILTER_VALIDATE_EMAIL);
}

function valid_phone(string $value): bool
{
    if ($value === '') return true;
    return (bool)preg_match('/^[0-9+()\s.\-]{7,30}$/', $value);
}

function make_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function iso_datetime(?string $sqlDateTime): ?string
{
    if (!$sqlDateTime) return null;
    $ts = strtotime($sqlDateTime);
    return $ts ? date('c', $ts) : $sqlDateTime;
}

function sql_datetime(int $timestamp): string
{
    return date('Y-m-d H:i:s', $timestamp);
}

function time_hm(?string $time): string
{
    return $time ? substr($time, 0, 5) : '';
}

function bool_int($value): int
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
}

function money_decimal($value): float
{
    return round(max(0, (float)$value), 2);
}

function honeypot_filled(array $body): bool
{
    foreach (['website', 'companyWebsite', 'faxNumber', 'url'] as $key) {
        if (clean_string($body[$key] ?? '', 255) !== '') return true;
    }
    return false;
}

function rate_limit_or_fail(PDO $pdo, array $config, string $action, string $identifier, int $maxAttempts, int $windowSeconds, int $lockSeconds): void
{
    $hash = secret_hash($action . '|' . $identifier, $config);
    $now = time();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM rate_limits WHERE action = ? AND identifier_hash = ? FOR UPDATE');
        $stmt->execute([$action, $hash]);
        $row = $stmt->fetch();
        if ($row && !empty($row['locked_until']) && strtotime($row['locked_until']) > $now) {
            $pdo->commit();
            json_response(429, ['error' => 'Too many attempts. Please try again later.']);
        }
        if (!$row) {
            $stmt = $pdo->prepare('INSERT INTO rate_limits (action, identifier_hash, attempts, window_start, locked_until) VALUES (?, ?, 1, ?, NULL)');
            $stmt->execute([$action, $hash, sql_datetime($now)]);
        } else {
            $windowStart = strtotime($row['window_start']);
            if ($windowStart === false || ($now - $windowStart) > $windowSeconds) {
                $stmt = $pdo->prepare('UPDATE rate_limits SET attempts = 1, window_start = ?, locked_until = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $stmt->execute([sql_datetime($now), $row['id']]);
            } else {
                $attempts = ((int)$row['attempts']) + 1;
                $lockedUntil = $attempts > $maxAttempts ? sql_datetime($now + $lockSeconds) : null;
                $stmt = $pdo->prepare('UPDATE rate_limits SET attempts = ?, locked_until = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $stmt->execute([$attempts, $lockedUntil, $row['id']]);
                if ($lockedUntil !== null) {
                    $pdo->commit();
                    json_response(429, ['error' => 'Too many attempts. Please try again later.']);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function clear_rate_limit(PDO $pdo, array $config, string $action, string $identifier): void
{
    $hash = secret_hash($action . '|' . $identifier, $config);
    $stmt = $pdo->prepare('DELETE FROM rate_limits WHERE action = ? AND identifier_hash = ?');
    $stmt->execute([$action, $hash]);
}

function payment_reference(PDO $pdo): string
{
    $date = date('Ymd');
    for ($i = 0; $i < 10; $i++) {
        $ref = 'SCL-' . $date . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE payment_reference = ?');
        $stmt->execute([$ref]);
        if ((int)$stmt->fetchColumn() === 0) return $ref;
    }
    return 'SCL-' . $date . '-' . strtoupper(base_convert((string)time(), 10, 36));
}

function core_programmes(): array
{
    return ['The Ascent', 'The Ridgeline', 'The Summit'];
}

function is_core_programme(string $programme): bool
{
    foreach (core_programmes() as $name) {
        if (strcasecmp($name, trim($programme)) === 0) return true;
    }
    return false;
}

function canonical_programme_name(string $programme): string
{
    foreach (core_programmes() as $name) {
        if (strcasecmp($name, trim($programme)) === 0) return $name;
    }
    return trim($programme);
}

function programme_prices(PDO $pdo): array
{
    $defaults = [
        'The Ascent' => 95.00,
        'The Ridgeline' => 295.00,
        'The Summit' => 495.00,
    ];
    $placeholders = implode(',', array_fill(0, count(core_programmes()), '?'));
    $stmt = $pdo->prepare("SELECT programme, price_per_participant FROM programme_prices WHERE programme IN ($placeholders)");
    $stmt->execute(core_programmes());
    $prices = [];
    foreach ($stmt->fetchAll() as $row) {
        $prices[$row['programme']] = (float)$row['price_per_participant'];
    }
    $out = [];
    foreach (core_programmes() as $programme) {
        $out[] = [
            'programme' => $programme,
            'pricePerParticipant' => $prices[$programme] ?? $defaults[$programme],
        ];
    }
    return $out;
}

function price_for_programme(PDO $pdo, string $programme, float $fallback): float
{
    $stmt = $pdo->prepare('SELECT price_per_participant FROM programme_prices WHERE LOWER(programme) = LOWER(?) LIMIT 1');
    $stmt->execute([$programme]);
    $price = $stmt->fetchColumn();
    return $price === false ? $fallback : (float)$price;
}

function settings(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM settings WHERE id = 1')->fetch();
    $defaultPrice = $row ? (float)$row['price_per_participant'] : 95.00;
    $payment = $row ? [
        'accountName' => $row['payment_account_name'],
        'bankName' => $row['payment_bank_name'],
        'sortCode' => $row['payment_sort_code'],
        'accountNumber' => $row['payment_account_number'],
        'instructions' => $row['payment_instructions'],
    ] : [
        'accountName' => 'SummitCraft Leadership Ltd',
        'bankName' => 'Demo Bank',
        'sortCode' => '00-00-00',
        'accountNumber' => '00000000',
        'instructions' => 'Please make a bank transfer using the payment reference exactly as shown. Your place is held while payment is pending.',
    ];
    return [
        // Kept for backward compatibility. This is now the default/fallback price only.
        'pricePerParticipant' => $defaultPrice,
        'defaultPricePerParticipant' => $defaultPrice,
        'programmePrices' => programme_prices($pdo),
        'payment' => $payment,
    ];
}

function booked_participants(PDO $pdo, string $sessionId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(participants), 0) FROM bookings WHERE session_id = ? AND status <> 'cancelled'");
    $stmt->execute([$sessionId]);
    return (int)$stmt->fetchColumn();
}

function map_session(array $row, ?int $booked = null): array
{
    $capacity = (int)$row['capacity'];
    $booked = $booked ?? (int)($row['booked'] ?? 0);
    return [
        'id' => $row['id'],
        'programme' => $row['programme'],
        'title' => $row['title'],
        'startDate' => $row['start_date'],
        'startTime' => time_hm($row['start_time']),
        'endDate' => $row['end_date'],
        'endTime' => time_hm($row['end_time']),
        'location' => $row['location'],
        'pricePerParticipant' => (float)($row['price_per_participant'] ?? $row['programme_price'] ?? 0),
        'capacity' => $capacity,
        'active' => (bool)$row['active'],
        'notes' => $row['notes'],
        'booked' => $booked,
        'availableSpaces' => max($capacity - $booked, 0),
        'createdAt' => iso_datetime($row['created_at'] ?? null),
        'updatedAt' => iso_datetime($row['updated_at'] ?? null),
    ];
}

function public_session(array $session): array
{
    return [
        'id' => $session['id'],
        'programme' => $session['programme'],
        'title' => $session['title'],
        'startDate' => $session['startDate'],
        'startTime' => $session['startTime'],
        'endDate' => $session['endDate'],
        'endTime' => $session['endTime'],
        'location' => $session['location'],
        'pricePerParticipant' => $session['pricePerParticipant'],
        'capacity' => $session['capacity'],
        'active' => $session['active'],
        'notes' => $session['notes'],
        'booked' => $session['booked'],
        'availableSpaces' => $session['availableSpaces'],
    ];
}

function list_sessions(PDO $pdo, bool $publicOnly = false, string $programme = ''): array
{
    $where = [];
    $params = [];
    if ($publicOnly) {
        $where[] = 's.active = 1';
        $where[] = 's.programme IN (?,?,?)';
        $params = array_merge($params, core_programmes());
    }
    if ($programme !== '') {
        $where[] = 'LOWER(s.programme) = LOWER(?)';
        $params[] = $programme;
    }
    $sql = "SELECT s.*, COALESCE(pp.price_per_participant, st.price_per_participant, 0) AS price_per_participant, COALESCE(bs.booked, 0) AS booked
            FROM sessions s
            CROSS JOIN settings st
            LEFT JOIN programme_prices pp ON LOWER(pp.programme) = LOWER(s.programme)
            LEFT JOIN (
                SELECT session_id, SUM(participants) AS booked
                FROM bookings
                WHERE status <> 'cancelled'
                GROUP BY session_id
            ) bs ON bs.session_id = s.id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY s.start_date ASC, s.start_time ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('map_session', $stmt->fetchAll());
}

function map_enquiry(array $row): array
{
    return [
        'id' => $row['id'],
        'fullName' => $row['full_name'],
        'organisation' => $row['organisation'],
        'email' => $row['email'],
        'phone' => $row['phone'],
        'programme' => $row['programme'],
        'message' => $row['message'],
        'hearAbout' => $row['hear_about'],
        'status' => $row['status'],
        'privacyConsent' => (bool)($row['privacy_consent'] ?? 0),
        'createdAt' => iso_datetime($row['created_at']),
        'updatedAt' => iso_datetime($row['updated_at']),
    ];
}

function map_booking(array $row): array
{
    return [
        'id' => $row['id'],
        'sessionId' => $row['session_id'],
        'sessionTitle' => $row['session_title'],
        'programme' => $row['programme'],
        'sessionStartDate' => $row['session_start_date'],
        'sessionStartTime' => time_hm($row['session_start_time']),
        'sessionEndDate' => $row['session_end_date'],
        'sessionEndTime' => time_hm($row['session_end_time']),
        'sessionLocation' => $row['session_location'],
        'fullName' => $row['full_name'],
        'organisation' => $row['organisation'],
        'email' => $row['email'],
        'phone' => $row['phone'],
        'participants' => (int)$row['participants'],
        'message' => $row['message'],
        'hearAbout' => $row['hear_about'],
        'pricePerParticipant' => (float)$row['price_per_participant'],
        'amountDue' => (float)$row['amount_due'],
        'paymentReference' => $row['payment_reference'],
        'status' => $row['status'],
        'privacyConsent' => (bool)($row['privacy_consent'] ?? 0),
        'paymentReceivedAt' => iso_datetime($row['payment_received_at'] ?? null),
        'createdAt' => iso_datetime($row['created_at']),
        'updatedAt' => iso_datetime($row['updated_at']),
    ];
}

function map_audit(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'adminUsername' => $row['admin_username'],
        'action' => $row['action'],
        'entityType' => $row['entity_type'],
        'entityId' => $row['entity_id'],
        'details' => json_decode($row['details_json'] ?: '{}', true),
        'createdAt' => iso_datetime($row['created_at']),
    ];
}

function csv_escape($value): string
{
    $s = (string)($value ?? '');
    if (preg_match('/[",\n]/', $s)) return chr(34) . str_replace(chr(34), chr(34) . chr(34), $s) . chr(34);
    return $s;
}

function to_csv(array $rows, array $columns): string
{
    $lines = [];
    $lines[] = implode(',', array_map(function ($c) { return csv_escape($c['label']); }, $columns));
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(function ($c) use ($row) { return csv_escape($row[$c['key']] ?? ''); }, $columns));
    }
    return implode("\n", $lines);
}

function cookie_name(array $config): string
{
    return (string)($config['security']['admin_cookie_name'] ?? 'scl_admin_session');
}

function cookie_options(array $config, int $expires): array
{
    return [
        'expires' => $expires,
        'path' => (string)($config['security']['cookie_path'] ?? '/'),
        'secure' => (bool)($config['security']['cookie_secure'] ?? false),
        'httponly' => true,
        'samesite' => (string)($config['security']['cookie_samesite'] ?? 'Strict'),
    ];
}

function set_admin_cookie(array $config, string $token, int $expires): void
{
    setcookie(cookie_name($config), $token, cookie_options($config, $expires));
}

function clear_admin_cookie(array $config): void
{
    setcookie(cookie_name($config), '', cookie_options($config, time() - 3600));
}

function create_admin_session(PDO $pdo, array $config, string $username): array
{
    $ttl = (int)($config['security']['admin_session_ttl_seconds'] ?? 14400);
    $token = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(32));
    $expires = time() + $ttl;
    $stmt = $pdo->prepare('INSERT INTO admin_sessions (token_hash, username, csrf_hash, ip_hash, user_agent, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([secret_hash($token, $config), $username, secret_hash($csrf, $config), ip_hash($config), user_agent(), sql_datetime($expires)]);
    set_admin_cookie($config, $token, $expires);
    return ['csrfToken' => $csrf, 'expiresAt' => iso_datetime(sql_datetime($expires))];
}

function current_admin_session(PDO $pdo, array $config): ?array
{
    $token = (string)($_COOKIE[cookie_name($config)] ?? '');
    if ($token === '') return null;
    $tokenHash = secret_hash($token, $config);
    $stmt = $pdo->prepare('SELECT * FROM admin_sessions WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$tokenHash]);
    $session = $stmt->fetch();
    if (!$session) return null;
    $stmt = $pdo->prepare('UPDATE admin_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE token_hash = ?');
    $stmt->execute([$tokenHash]);
    return $session;
}

function require_admin(PDO $pdo, array $config): array
{
    $session = current_admin_session($pdo, $config);
    if (!$session) json_response(401, ['error' => 'Admin login required.']);
    return $session;
}

function rotate_csrf(PDO $pdo, array $config, array $session): string
{
    $csrf = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('UPDATE admin_sessions SET csrf_hash = ?, last_seen_at = CURRENT_TIMESTAMP WHERE token_hash = ?');
    $stmt->execute([secret_hash($csrf, $config), $session['token_hash']]);
    return $csrf;
}

function require_csrf(array $session, array $config): void
{
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !hash_equals((string)$session['csrf_hash'], secret_hash($sent, $config))) {
        json_response(403, ['error' => 'Security check failed. Please refresh the admin page and try again.']);
    }
}

function audit(PDO $pdo, array $config, string $action, string $entityType = '', string $entityId = '', array $details = [], string $adminUsername = ''): void
{
    $stmt = $pdo->prepare('INSERT INTO audit_logs (admin_username, action, entity_type, entity_id, details_json, ip_hash, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $adminUsername,
        $action,
        $entityType,
        $entityId,
        json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ip_hash($config),
        user_agent(),
    ]);
}

function queue_email(PDO $pdo, string $type, string $entityId, string $to, string $subject, string $body): void
{
    $stmt = $pdo->prepare('INSERT INTO email_logs (email_type, entity_id, recipient_email, subject, body, status) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$type, $entityId, $to, $subject, $body, 'queued']);
}

function maybe_send_email(PDO $pdo, array $config, string $type, string $entityId, string $to, string $subject, string $body): void
{
    if (!is_email($to)) return;
    queue_email($pdo, $type, $entityId, $to, $subject, $body);
    $emailConfig = $config['email'] ?? [];
    if (empty($emailConfig['enabled']) || ($emailConfig['method'] ?? 'log') !== 'mail') return;
    $from = clean_string($emailConfig['from_email'] ?? '', 254);
    $headers = $from !== '' ? "From: {$from}\r\nReply-To: {$from}\r\nContent-Type: text/plain; charset=UTF-8" : "Content-Type: text/plain; charset=UTF-8";
    @mail($to, $subject, $body, $headers);
}

function notify_enquiry(PDO $pdo, array $config, array $enquiry): void
{
    $businessName = $config['app']['business_name'] ?? 'SummitCraft Leadership';
    $adminEmail = clean_email($config['email']['admin_email'] ?? '');
    maybe_send_email($pdo, $config, 'enquiry_customer', $enquiry['id'], $enquiry['email'], 'Your enquiry has been received', "Thank you for contacting {$businessName}.\n\nWe have received your enquiry and will respond soon.\n\nProgramme: {$enquiry['programme']}\n");
    if ($adminEmail) {
        maybe_send_email($pdo, $config, 'enquiry_admin', $enquiry['id'], $adminEmail, 'New website enquiry', "New enquiry from {$enquiry['full_name']} ({$enquiry['email']}).\n\nProgramme: {$enquiry['programme']}\nMessage:\n{$enquiry['message']}\n");
    }
}

function notify_booking(PDO $pdo, array $config, array $booking, array $payment, array $session): void
{
    $businessName = $config['app']['business_name'] ?? 'SummitCraft Leadership';
    $adminEmail = clean_email($config['email']['admin_email'] ?? '');
    $body = "Thank you for booking with {$businessName}.\n\nPayment reference: {$booking['payment_reference']}\nAmount due: £" . number_format((float)$booking['amount_due'], 2) . "\nParticipants: {$booking['participants']}\nSession: {$booking['session_title']}\nDate: {$booking['session_start_date']} {$booking['session_start_time']}\n\nBank transfer details\nAccount name: {$payment['accountName']}\nBank: {$payment['bankName']}\nSort code: {$payment['sortCode']}\nAccount number: {$payment['accountNumber']}\n\nPlease use the payment reference exactly as shown.\n";
    maybe_send_email($pdo, $config, 'booking_customer', $booking['id'], $booking['email'], 'Your booking and payment instructions', $body);
    if ($adminEmail) {
        maybe_send_email($pdo, $config, 'booking_admin', $booking['id'], $adminEmail, 'New website booking', "New booking from {$booking['full_name']} ({$booking['email']}).\n\nReference: {$booking['payment_reference']}\nAmount due: £" . number_format((float)$booking['amount_due'], 2) . "\nSession: {$booking['session_title']}\n");
    }
}

function session_payload(array $body, array $existing = []): array
{
    return [
        'id' => $existing['id'] ?? make_id('ses'),
        'programme' => canonical_programme_name(clean_string($body['programme'] ?? ($existing['programme'] ?? ''), 120)),
        'title' => clean_string($body['title'] ?? ($existing['title'] ?? ''), 180),
        'start_date' => clean_string($body['startDate'] ?? ($existing['start_date'] ?? ''), 20),
        'start_time' => clean_string($body['startTime'] ?? ($existing['start_time'] ?? ''), 20),
        'end_date' => clean_string($body['endDate'] ?? ($existing['end_date'] ?? ''), 20),
        'end_time' => clean_string($body['endTime'] ?? ($existing['end_time'] ?? ''), 20),
        'location' => clean_string($body['location'] ?? ($existing['location'] ?? ''), 200),
        'capacity' => max(0, (int)($body['capacity'] ?? ($existing['capacity'] ?? 0))),
        'active' => array_key_exists('active', $body) ? bool_int($body['active']) : (int)($existing['active'] ?? 1),
        'notes' => clean_string($body['notes'] ?? ($existing['notes'] ?? ''), 1000),
    ];
}

function validate_session_data(array $session): array
{
    $errors = [];
    if ($session['programme'] === '') $errors[] = 'Programme is required.';
    elseif (!is_core_programme($session['programme'])) $errors[] = 'Programme must be The Ascent, The Ridgeline or The Summit.';
    if ($session['title'] === '') $errors[] = 'Session title is required.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $session['start_date'])) $errors[] = 'Start date is required.';
    if (!preg_match('/^\d{2}:\d{2}$/', substr($session['start_time'], 0, 5))) $errors[] = 'Start time is required.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $session['end_date'])) $errors[] = 'End date is required.';
    if (!preg_match('/^\d{2}:\d{2}$/', substr($session['end_time'], 0, 5))) $errors[] = 'End time is required.';
    if ($session['capacity'] < 1) $errors[] = 'Capacity must be at least 1.';
    $start = strtotime($session['start_date'] . ' ' . substr($session['start_time'], 0, 5));
    $end = strtotime($session['end_date'] . ' ' . substr($session['end_time'], 0, 5));
    if ($start && $end && $end <= $start) $errors[] = 'End date/time must be after start date/time.';
    return $errors;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $pdo = db($config);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = route_path();

    if ($method === 'GET' && $path === '/api/health') {
        json_response(200, ['ok' => true, 'service' => 'summitcraft-php-mysql-api', 'time' => date('c')]);
    }

    if ($method === 'GET' && $path === '/api/public/settings') {
        $s = settings($pdo);
        json_response(200, [
            'programmes' => core_programmes(),
            'pricePerParticipant' => $s['pricePerParticipant'],
            'defaultPricePerParticipant' => $s['defaultPricePerParticipant'],
            'programmePrices' => $s['programmePrices'],
        ]);
    }

    if ($method === 'GET' && $path === '/api/public/sessions') {
        $programme = canonical_programme_name(clean_string($_GET['programme'] ?? '', 120));
        if ($programme !== '' && !is_core_programme($programme)) {
            json_response(200, ['sessions' => []]);
        }
        $sessions = array_map('public_session', list_sessions($pdo, true, $programme));
        json_response(200, ['sessions' => $sessions]);
    }

    if ($method === 'POST' && $path === '/api/enquiries') {
        $limits = $config['rate_limits']['public_forms'] ?? ['max_attempts' => 8, 'window_seconds' => 600, 'lock_seconds' => 900];
        rate_limit_or_fail($pdo, $config, 'public_enquiry', client_ip(), (int)$limits['max_attempts'], (int)$limits['window_seconds'], (int)$limits['lock_seconds']);
        $body = parse_json_body();
        if (honeypot_filled($body)) json_response(201, ['enquiryId' => make_id('enq'), 'message' => 'Enquiry received.']);
        $enquiry = [
            'id' => make_id('enq'),
            'full_name' => clean_string($body['fullName'] ?? '', 160),
            'organisation' => clean_string($body['organisation'] ?? '', 180),
            'email' => clean_email($body['email'] ?? ''),
            'phone' => clean_string($body['phone'] ?? '', 80),
            'programme' => canonical_programme_name(clean_string($body['programme'] ?? '', 120)),
            'message' => clean_string($body['message'] ?? '', 5000),
            'hear_about' => clean_string($body['hearAbout'] ?? '', 120),
            'privacy_consent' => bool_int($body['privacyConsent'] ?? false),
            'status' => 'new',
        ];
        $errors = [];
        if (strlen($enquiry['full_name']) < 2) $errors[] = 'Full name is required.';
        if (!is_email($enquiry['email'])) $errors[] = 'A valid email address is required.';
        if (!valid_phone($enquiry['phone'])) $errors[] = 'Please enter a valid phone number or leave it blank.';
        if ($enquiry['programme'] !== '' && !is_core_programme($enquiry['programme'])) $errors[] = 'Please choose The Ascent, The Ridgeline or The Summit as the programme, or leave it blank for a general enquiry.';
        if (strlen($enquiry['message']) < 20) $errors[] = 'Message must be at least 20 characters.';
        if (!$enquiry['privacy_consent']) $errors[] = 'Please confirm that you agree to the privacy statement.';
        if ($errors) json_response(400, ['error' => implode(' ', $errors)]);

        $stmt = $pdo->prepare('INSERT INTO enquiries (id, full_name, organisation, email, phone, programme, message, hear_about, privacy_consent, status, ip_hash, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$enquiry['id'], $enquiry['full_name'], $enquiry['organisation'], $enquiry['email'], $enquiry['phone'], $enquiry['programme'], $enquiry['message'], $enquiry['hear_about'], $enquiry['privacy_consent'], $enquiry['status'], ip_hash($config), user_agent()]);
        notify_enquiry($pdo, $config, $enquiry);
        json_response(201, ['enquiryId' => $enquiry['id'], 'message' => 'Enquiry received.']);
    }

    if ($method === 'POST' && $path === '/api/bookings') {
        $limits = $config['rate_limits']['public_forms'] ?? ['max_attempts' => 8, 'window_seconds' => 600, 'lock_seconds' => 900];
        rate_limit_or_fail($pdo, $config, 'public_booking', client_ip(), (int)$limits['max_attempts'], (int)$limits['window_seconds'], (int)$limits['lock_seconds']);
        $body = parse_json_body();
        if (honeypot_filled($body)) json_response(201, ['bookingId' => make_id('bok'), 'reference' => 'SCL-' . date('Ymd') . '-PENDING', 'message' => 'Booking received.']);
        $sessionId = clean_string($body['sessionId'] ?? '', 80);
        $participants = max(1, (int)($body['participants'] ?? 1));
        if ($participants > 50) json_response(400, ['error' => 'Please contact us directly for bookings over 50 participants.']);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT s.*, COALESCE(pp.price_per_participant, st.price_per_participant, 0) AS price_per_participant FROM sessions s CROSS JOIN settings st LEFT JOIN programme_prices pp ON LOWER(pp.programme) = LOWER(s.programme) WHERE s.id = ? AND s.active = 1 FOR UPDATE');
        $stmt->execute([$sessionId]);
        $sessionRow = $stmt->fetch();
        if (!$sessionRow) {
            $pdo->rollBack();
            json_response(404, ['error' => 'Selected session was not found.']);
        }
        if (!is_core_programme($sessionRow['programme'])) {
            $pdo->rollBack();
            json_response(400, ['error' => 'This session is not linked to one of the three bookable programmes.']);
        }
        $booked = booked_participants($pdo, $sessionId);
        $session = map_session($sessionRow, $booked);
        if ($participants > $session['availableSpaces']) {
            $pdo->rollBack();
            json_response(409, ['error' => 'Only ' . $session['availableSpaces'] . ' space(s) are available for this session.']);
        }

        $s = settings($pdo);
        $programmePrice = (float)($sessionRow['price_per_participant'] ?? price_for_programme($pdo, $sessionRow['programme'], (float)$s['defaultPricePerParticipant']));
        $booking = [
            'id' => make_id('bok'),
            'session_id' => $sessionRow['id'],
            'session_title' => $sessionRow['title'],
            'programme' => $sessionRow['programme'],
            'session_start_date' => $sessionRow['start_date'],
            'session_start_time' => $sessionRow['start_time'],
            'session_end_date' => $sessionRow['end_date'],
            'session_end_time' => $sessionRow['end_time'],
            'session_location' => $sessionRow['location'],
            'full_name' => clean_string($body['fullName'] ?? '', 160),
            'organisation' => clean_string($body['organisation'] ?? '', 180),
            'email' => clean_email($body['email'] ?? ''),
            'phone' => clean_string($body['phone'] ?? '', 80),
            'participants' => $participants,
            'message' => clean_string($body['message'] ?? '', 5000),
            'hear_about' => clean_string($body['hearAbout'] ?? '', 120),
            'privacy_consent' => bool_int($body['privacyConsent'] ?? false),
            'price_per_participant' => $programmePrice,
            'amount_due' => $participants * $programmePrice,
            'payment_reference' => payment_reference($pdo),
            'status' => 'pending_payment',
        ];

        $errors = [];
        if (strlen($booking['full_name']) < 2) $errors[] = 'Full name is required.';
        if (!is_email($booking['email'])) $errors[] = 'A valid email address is required.';
        if (!valid_phone($booking['phone'])) $errors[] = 'Please enter a valid phone number or leave it blank.';
        if ($participants < 1) $errors[] = 'At least one participant is required.';
        if (!$booking['privacy_consent']) $errors[] = 'Please confirm that you agree to the privacy statement.';
        if ($errors) {
            $pdo->rollBack();
            json_response(400, ['error' => implode(' ', $errors)]);
        }

        $stmt = $pdo->prepare('INSERT INTO bookings (id, session_id, session_title, programme, session_start_date, session_start_time, session_end_date, session_end_time, session_location, full_name, organisation, email, phone, participants, message, hear_about, privacy_consent, price_per_participant, amount_due, payment_reference, status, ip_hash, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$booking['id'], $booking['session_id'], $booking['session_title'], $booking['programme'], $booking['session_start_date'], $booking['session_start_time'], $booking['session_end_date'], $booking['session_end_time'], $booking['session_location'], $booking['full_name'], $booking['organisation'], $booking['email'], $booking['phone'], $booking['participants'], $booking['message'], $booking['hear_about'], $booking['privacy_consent'], $booking['price_per_participant'], $booking['amount_due'], $booking['payment_reference'], $booking['status'], ip_hash($config), user_agent()]);
        $pdo->commit();

        notify_booking($pdo, $config, $booking, $s['payment'], $session);
        $sessionAfter = map_session($sessionRow, $booked + $participants);
        json_response(201, [
            'bookingId' => $booking['id'],
            'reference' => $booking['payment_reference'],
            'amountDue' => (float)$booking['amount_due'],
            'pricePerParticipant' => (float)$booking['price_per_participant'],
            'participants' => $booking['participants'],
            'status' => $booking['status'],
            'booking' => [
                'fullName' => $booking['full_name'],
                'email' => $booking['email'],
                'organisation' => $booking['organisation'],
                'phone' => $booking['phone'],
            ],
            'session' => public_session($sessionAfter),
            'payment' => $s['payment'],
        ]);
    }

    if ($method === 'POST' && $path === '/api/admin/login') {
        $body = parse_json_body();
        $username = clean_string($body['username'] ?? '', 100);
        $password = (string)($body['password'] ?? '');
        $loginLimits = $config['rate_limits']['admin_login'] ?? ['max_attempts' => 5, 'window_seconds' => 900, 'lock_seconds' => 1800];
        rate_limit_or_fail($pdo, $config, 'admin_login_ip', client_ip(), (int)$loginLimits['max_attempts'], (int)$loginLimits['window_seconds'], (int)$loginLimits['lock_seconds']);
        rate_limit_or_fail($pdo, $config, 'admin_login_user', strtolower($username), (int)$loginLimits['max_attempts'], (int)$loginLimits['window_seconds'], (int)$loginLimits['lock_seconds']);
        $admin = $config['admin'];
        if ($username === ($admin['username'] ?? 'admin') && password_verify($password, $admin['password_hash'] ?? '')) {
            clear_rate_limit($pdo, $config, 'admin_login_ip', client_ip());
            clear_rate_limit($pdo, $config, 'admin_login_user', strtolower($username));
            $sessionInfo = create_admin_session($pdo, $config, $username);
            audit($pdo, $config, 'admin_login_success', 'admin', $username, [], $username);
            json_response(200, [
                'username' => $username,
                'csrfToken' => $sessionInfo['csrfToken'],
                'expiresAt' => $sessionInfo['expiresAt'],
                'defaultPasswordInUse' => (bool)($admin['using_default_password'] ?? false),
            ]);
        }
        audit($pdo, $config, 'admin_login_failed', 'admin', $username, [], $username);
        json_response(401, ['error' => 'Incorrect admin username or password.']);
    }

    if (strpos($path, '/api/admin') === 0) {
        $adminSession = require_admin($pdo, $config);
        if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            require_csrf($adminSession, $config);
        }
        $adminUsername = $adminSession['username'] ?? ($config['admin']['username'] ?? 'admin');

        if ($method === 'GET' && $path === '/api/admin/me') {
            json_response(200, [
                'username' => $adminUsername,
                'csrfToken' => rotate_csrf($pdo, $config, $adminSession),
                'defaultPasswordInUse' => (bool)($config['admin']['using_default_password'] ?? false),
            ]);
        }

        if ($method === 'POST' && $path === '/api/admin/logout') {
            $stmt = $pdo->prepare('UPDATE admin_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE token_hash = ?');
            $stmt->execute([$adminSession['token_hash']]);
            audit($pdo, $config, 'admin_logout', 'admin', $adminUsername, [], $adminUsername);
            clear_admin_cookie($config);
            json_response(200, ['ok' => true]);
        }

        if ($method === 'GET' && $path === '/api/admin/dashboard') {
            $activeSessions = (int)$pdo->query('SELECT COUNT(*) FROM sessions WHERE active = 1')->fetchColumn();
            $enquiries = (int)$pdo->query('SELECT COUNT(*) FROM enquiries')->fetchColumn();
            $bookings = (int)$pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
            $bookedParticipants = (int)$pdo->query("SELECT COALESCE(SUM(participants), 0) FROM bookings WHERE status <> 'cancelled'")->fetchColumn();
            $pendingPayments = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending_payment'")->fetchColumn();
            $paidBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'paid'")->fetchColumn();
            json_response(200, compact('enquiries', 'bookings', 'activeSessions', 'bookedParticipants', 'pendingPayments', 'paidBookings'));
        }

        if ($method === 'GET' && $path === '/api/admin/enquiries') {
            $rows = $pdo->query('SELECT * FROM enquiries ORDER BY created_at DESC')->fetchAll();
            json_response(200, ['enquiries' => array_map('map_enquiry', $rows)]);
        }

        if ($method === 'GET' && $path === '/api/admin/bookings') {
            $rows = $pdo->query('SELECT * FROM bookings ORDER BY created_at DESC')->fetchAll();
            json_response(200, ['bookings' => array_map('map_booking', $rows)]);
        }

        if ($method === 'PUT' && preg_match('#^/api/admin/bookings/([^/]+)$#', $path, $m)) {
            $body = parse_json_body();
            $allowed = ['pending_payment', 'paid', 'cancelled', 'attended', 'refunded'];
            $status = clean_string($body['status'] ?? '', 40);
            if (!in_array($status, $allowed, true)) json_response(400, ['error' => 'Invalid booking status.']);
            $paymentReceivedSql = $status === 'paid' ? ', payment_received_at = COALESCE(payment_received_at, CURRENT_TIMESTAMP)' : '';
            $stmt = $pdo->prepare("UPDATE bookings SET status = ?, updated_at = CURRENT_TIMESTAMP {$paymentReceivedSql} WHERE id = ?");
            $stmt->execute([$status, $m[1]]);
            if ($stmt->rowCount() < 1) json_response(404, ['error' => 'Not found']);
            audit($pdo, $config, 'booking_status_updated', 'booking', $m[1], ['status' => $status], $adminUsername);
            $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
            $stmt->execute([$m[1]]);
            json_response(200, ['booking' => map_booking($stmt->fetch())]);
        }

        if ($method === 'GET' && $path === '/api/admin/sessions') {
            json_response(200, ['sessions' => list_sessions($pdo, false)]);
        }

        if ($method === 'POST' && $path === '/api/admin/sessions') {
            $body = parse_json_body();
            $session = session_payload($body);
            $errors = validate_session_data($session);
            if ($errors) json_response(400, ['error' => implode(' ', $errors)]);
            $stmt = $pdo->prepare('INSERT INTO sessions (id, programme, title, start_date, start_time, end_date, end_time, location, capacity, active, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$session['id'], $session['programme'], $session['title'], $session['start_date'], $session['start_time'], $session['end_date'], $session['end_time'], $session['location'], $session['capacity'], $session['active'], $session['notes']]);
            audit($pdo, $config, 'session_created', 'session', $session['id'], ['title' => $session['title']], $adminUsername);
            $stmt = $pdo->prepare('SELECT s.*, COALESCE(pp.price_per_participant, st.price_per_participant, 0) AS price_per_participant FROM sessions s CROSS JOIN settings st LEFT JOIN programme_prices pp ON LOWER(pp.programme) = LOWER(s.programme) WHERE s.id = ?');
            $stmt->execute([$session['id']]);
            json_response(201, ['session' => map_session($stmt->fetch(), 0)]);
        }

        if ($method === 'PUT' && preg_match('#^/api/admin/sessions/([^/]+)$#', $path, $m)) {
            $stmt = $pdo->prepare('SELECT * FROM sessions WHERE id = ?');
            $stmt->execute([$m[1]]);
            $existing = $stmt->fetch();
            if (!$existing) json_response(404, ['error' => 'Not found']);
            $session = session_payload(parse_json_body(), $existing);
            $errors = validate_session_data($session);
            $booked = booked_participants($pdo, $m[1]);
            if ($session['capacity'] < $booked) $errors[] = 'Capacity cannot be lower than the number already booked (' . $booked . ').';
            if ($errors) json_response(400, ['error' => implode(' ', $errors)]);
            $stmt = $pdo->prepare('UPDATE sessions SET programme = ?, title = ?, start_date = ?, start_time = ?, end_date = ?, end_time = ?, location = ?, capacity = ?, active = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$session['programme'], $session['title'], $session['start_date'], $session['start_time'], $session['end_date'], $session['end_time'], $session['location'], $session['capacity'], $session['active'], $session['notes'], $m[1]]);
            audit($pdo, $config, 'session_updated', 'session', $m[1], ['title' => $session['title']], $adminUsername);
            $stmt = $pdo->prepare('SELECT s.*, COALESCE(pp.price_per_participant, st.price_per_participant, 0) AS price_per_participant FROM sessions s CROSS JOIN settings st LEFT JOIN programme_prices pp ON LOWER(pp.programme) = LOWER(s.programme) WHERE s.id = ?');
            $stmt->execute([$m[1]]);
            json_response(200, ['session' => map_session($stmt->fetch(), booked_participants($pdo, $m[1]))]);
        }

        if ($method === 'DELETE' && preg_match('#^/api/admin/sessions/([^/]+)$#', $path, $m)) {
            $stmt = $pdo->prepare('UPDATE sessions SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$m[1]]);
            if ($stmt->rowCount() < 1) json_response(404, ['error' => 'Not found']);
            audit($pdo, $config, 'session_deactivated', 'session', $m[1], [], $adminUsername);
            $stmt = $pdo->prepare('SELECT s.*, COALESCE(pp.price_per_participant, st.price_per_participant, 0) AS price_per_participant FROM sessions s CROSS JOIN settings st LEFT JOIN programme_prices pp ON LOWER(pp.programme) = LOWER(s.programme) WHERE s.id = ?');
            $stmt->execute([$m[1]]);
            json_response(200, ['session' => map_session($stmt->fetch(), booked_participants($pdo, $m[1])), 'message' => 'Session has been deactivated.']);
        }

        if ($method === 'GET' && $path === '/api/admin/settings') {
            $adminSettings = settings($pdo);
            $adminSettings['programmes'] = core_programmes();
            json_response(200, ['settings' => $adminSettings]);
        }

        if ($method === 'PUT' && $path === '/api/admin/settings') {
            $body = parse_json_body();
            $current = settings($pdo);
            $payment = is_array($body['payment'] ?? null) ? $body['payment'] : [];
            $defaultPrice = money_decimal($body['defaultPricePerParticipant'] ?? $body['pricePerParticipant'] ?? $current['defaultPricePerParticipant']);
            if ($defaultPrice <= 0) json_response(400, ['error' => 'Default price per participant must be greater than zero.']);

            $values = [
                $defaultPrice,
                clean_string($payment['accountName'] ?? $current['payment']['accountName'], 160),
                clean_string($payment['bankName'] ?? $current['payment']['bankName'], 160),
                clean_string($payment['sortCode'] ?? $current['payment']['sortCode'], 20),
                clean_string($payment['accountNumber'] ?? $current['payment']['accountNumber'], 40),
                clean_string($payment['instructions'] ?? $current['payment']['instructions'], 1000),
            ];

            $programmePrices = is_array($body['programmePrices'] ?? null) ? $body['programmePrices'] : [];
            foreach ($programmePrices as $entry) {
                if (!is_array($entry)) continue;
                $programmeName = clean_string($entry['programme'] ?? '', 120);
                $programmePrice = money_decimal($entry['pricePerParticipant'] ?? 0);
                if ($programmeName === '') continue;
                if (!is_core_programme($programmeName)) json_response(400, ['error' => 'Only The Ascent, The Ridgeline and The Summit can have programme prices.']);
                if ($programmePrice <= 0) json_response(400, ['error' => 'Programme price for ' . $programmeName . ' must be greater than zero.']);
            }

            $pdo->beginTransaction();
            $cleanup = $pdo->prepare('DELETE FROM programme_prices WHERE programme NOT IN (?,?,?)');
            $cleanup->execute(core_programmes());
            $stmt = $pdo->prepare('UPDATE settings SET price_per_participant = ?, payment_account_name = ?, payment_bank_name = ?, payment_sort_code = ?, payment_account_number = ?, payment_instructions = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1');
            $stmt->execute($values);
            $stmt = $pdo->prepare('INSERT INTO programme_prices (programme, price_per_participant) VALUES (?, ?) ON DUPLICATE KEY UPDATE price_per_participant = VALUES(price_per_participant), updated_at = CURRENT_TIMESTAMP');
            $savedProgrammePrices = [];
            foreach ($programmePrices as $entry) {
                if (!is_array($entry)) continue;
                $programmeName = clean_string($entry['programme'] ?? '', 120);
                $programmePrice = money_decimal($entry['pricePerParticipant'] ?? 0);
                if ($programmeName === '') continue;
                if (!is_core_programme($programmeName)) continue;
                $programmeName = canonical_programme_name($programmeName);
                $stmt->execute([$programmeName, $programmePrice]);
                $savedProgrammePrices[$programmeName] = $programmePrice;
            }
            $pdo->commit();
            audit($pdo, $config, 'settings_updated', 'settings', '1', ['default_price_per_participant' => $values[0], 'programme_prices' => $savedProgrammePrices], $adminUsername);
            json_response(200, ['settings' => settings($pdo)]);
        }

        if ($method === 'GET' && $path === '/api/admin/audit') {
            $rows = $pdo->query('SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 200')->fetchAll();
            json_response(200, ['logs' => array_map('map_audit', $rows)]);
        }

        if ($method === 'GET' && $path === '/api/admin/export/enquiries') {
            $rows = array_map('map_enquiry', $pdo->query('SELECT * FROM enquiries ORDER BY created_at DESC')->fetchAll());
            text_response(200, to_csv($rows, [
                ['key' => 'createdAt', 'label' => 'Created'],
                ['key' => 'fullName', 'label' => 'Full name'],
                ['key' => 'organisation', 'label' => 'Organisation'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'programme', 'label' => 'Programme'],
                ['key' => 'message', 'label' => 'Message'],
                ['key' => 'hearAbout', 'label' => 'Hear about'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'privacyConsent', 'label' => 'Privacy consent'],
            ]), 'text/csv; charset=utf-8');
        }

        if ($method === 'GET' && $path === '/api/admin/export/bookings') {
            $rows = array_map('map_booking', $pdo->query('SELECT * FROM bookings ORDER BY created_at DESC')->fetchAll());
            text_response(200, to_csv($rows, [
                ['key' => 'createdAt', 'label' => 'Created'],
                ['key' => 'paymentReference', 'label' => 'Payment reference'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'paymentReceivedAt', 'label' => 'Payment received at'],
                ['key' => 'fullName', 'label' => 'Full name'],
                ['key' => 'organisation', 'label' => 'Organisation'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'participants', 'label' => 'Participants'],
                ['key' => 'pricePerParticipant', 'label' => 'Price per participant'],
                ['key' => 'amountDue', 'label' => 'Amount due'],
                ['key' => 'programme', 'label' => 'Programme'],
                ['key' => 'sessionTitle', 'label' => 'Session'],
                ['key' => 'sessionStartDate', 'label' => 'Session start date'],
                ['key' => 'sessionStartTime', 'label' => 'Session start time'],
                ['key' => 'sessionLocation', 'label' => 'Location'],
                ['key' => 'message', 'label' => 'Message'],
                ['key' => 'hearAbout', 'label' => 'Hear about'],
                ['key' => 'privacyConsent', 'label' => 'Privacy consent'],
            ]), 'text/csv; charset=utf-8');
        }
    }

    json_response(404, ['error' => 'Not found']);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $debug = (bool)($config['app']['debug'] ?? false);
    json_response(500, ['error' => 'Database error. Check api/config.php and import database/schema.sql.' . ($debug ? ' ' . $e->getMessage() : '')]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $debug = (bool)($config['app']['debug'] ?? false);
    json_response(500, ['error' => $debug ? ($e->getMessage() ?: 'Server error') : 'Server error.']);
}
