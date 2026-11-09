<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
$googleConfigPath = __DIR__ . '/google-oauth-config.php';
if (is_file($googleConfigPath)) {
    require_once $googleConfigPath;
}
$smtpConfigPath = __DIR__ . '/smtp-config.php';
if (is_file($smtpConfigPath)) {
    require_once $smtpConfigPath;
}

const SESSION_LIFETIME_SECONDS = 30 * 24 * 3600;
$sessionDirectory = __DIR__ . '/storage/sessions';
if (!is_dir($sessionDirectory)) {
    @mkdir($sessionDirectory, 0700, true);
}
if (is_dir($sessionDirectory) && is_writable($sessionDirectory)) {
    session_save_path($sessionDirectory);
}
ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME_SECONDS);
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME_SECONDS,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const ADMIN_EMAIL = 'diyoradaminovaz.m@gmail.com';
const TUTOR_HEARTBEAT_SECONDS = 150;
const TUTOR_SUBJECTS = ['Calculus 1', 'Calculus 3', 'Physics', 'English', 'Computer Science', 'Biology', 'General tutoring'];
const MAX_APPLICATION_FILE_BYTES = 4 * 1024 * 1024;
const MAX_SUPPORTING_DOCUMENTS = 5;
const SAFE_FILE_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 _().-]{0,95}\.[A-Za-z0-9]{1,5}$/D';
const APPLICATION_FILE_TYPES = [
    'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
const APPLICATION_FILE_SIGNATURES = [
    'pdf' => '%PDF', 'png' => "\x89PNG", 'jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF",
    'doc' => "\xD0\xCF\x11\xE0", 'docx' => 'PK',
];

function debugLog(string $event, array $context = []): void {
    $entry = [
        'time' => gmdate('c'),
        'event' => $event,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'action' => $_GET['action'] ?? null,
        'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'context' => $context,
    ];
    $logPath = __DIR__ . '/storage/tutorrush-debug.log';
    error_log(json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, $logPath);
}

function respond(int $status, array $payload): never {
    http_response_code($status);
    if ($status >= 400) {
        debugLog('api_error', ['status' => $status, 'error' => $payload['error'] ?? 'Unknown error']);
    }
    echo json_encode($payload);
    exit;
}

function requestBody(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(405, ['error' => 'Only POST requests are supported.']);
    }
    if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        respond(415, ['error' => 'Content-Type must be application/json.']);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        respond(400, ['error' => 'A JSON request body is required.']);
    }
    debugLog('api_request', ['fields' => array_keys($payload)]);
    return $payload;
}

function validateText(mixed $value, int $maximumLength, string $field): string {
    $text = trim((string) $value);
    if ($text === '' || mb_strlen($text) > $maximumLength) {
        respond(422, ['error' => "$field is required and must be $maximumLength characters or fewer."]);
    }
    return $text;
}

function csrfToken(): string {
    if (!isset($_SESSION['tutorrush_csrf_token'])) {
        $_SESSION['tutorrush_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['tutorrush_csrf_token'];
}

function requireCsrfToken(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        respond(403, ['error' => 'Your session verification expired. Refresh and try again.']);
    }
}

function googleConfig(): array {
    $clientId = defined('TUTORUSH_GOOGLE_CLIENT_ID') ? TUTORUSH_GOOGLE_CLIENT_ID : '';
    $clientSecret = defined('TUTORUSH_GOOGLE_CLIENT_SECRET') ? TUTORUSH_GOOGLE_CLIENT_SECRET : '';
    $redirectUri = defined('TUTORUSH_GOOGLE_REDIRECT_URI') ? TUTORUSH_GOOGLE_REDIRECT_URI : '';
    if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
        throw new RuntimeException('Google sign-in is not configured.');
    }
    return ['client_id' => $clientId, 'client_secret' => $clientSecret, 'redirect_uri' => $redirectUri];
}

function googleRequest(string $url, array $data = [], ?string $accessToken = null): array {
    $headers = ['Accept: application/json'];
    $content = '';
    $method = 'GET';
    if ($data) {
        $method = 'POST';
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $content = http_build_query($data);
    }
    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $response = file_get_contents($url, false, $context);
    if ($response === false) {
        throw new RuntimeException('Google sign-in could not reach Google.');
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Google returned an invalid response.');
    }
    return $decoded;
}

function dashboardForRole(string $role): string {
    return match ($role) {
        'tutor' => 'tutor-dashboard.html',
        'admin' => 'admin-dashboard.html',
        default => 'student-dashboard.html',
    };
}

function redirectGoogleError(string $flow, string $error): never {
    $page = $flow === 'signup' ? 'sign-up.html' : 'login.html';
    header('Location: ' . $page . '?google_error=' . rawurlencode($error), true, 302);
    exit;
}

function handleGoogleOAuth(PDO $database, string $action): never {
    $flow = ($_SESSION['google_oauth']['flow'] ?? ($_GET['flow'] ?? 'login')) === 'signup' ? 'signup' : 'login';
    try {
        $config = googleConfig();
    } catch (Throwable $exception) {
        debugLog('google_oauth_not_configured');
        redirectGoogleError($flow, 'not_configured');
    }

    if ($action === 'google-start') {
        $flow = ($_GET['flow'] ?? 'login') === 'signup' ? 'signup' : 'login';
        $role = $_GET['role'] ?? 'student';
        if (!in_array($role, ['student', 'tutor', 'both'], true)) $role = 'student';
        $state = bin2hex(random_bytes(32));
        $_SESSION['google_oauth'] = ['state' => $state, 'flow' => $flow, 'role' => $role];
        $query = http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
        ]);
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query, true, 302);
        exit;
    }

    $oauthState = $_SESSION['google_oauth'] ?? null;
    unset($_SESSION['google_oauth']);
    if (!is_array($oauthState) || !isset($_GET['state']) || !hash_equals($oauthState['state'], (string) $_GET['state'])) {
        redirectGoogleError($flow, 'state');
    }
    $flow = $oauthState['flow'];
    if (isset($_GET['error']) || !isset($_GET['code'])) {
        redirectGoogleError($flow, 'cancelled');
    }

    try {
        $tokens = googleRequest('https://oauth2.googleapis.com/token', [
            'code' => (string) $_GET['code'],
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);
        if (empty($tokens['access_token'])) {
            throw new RuntimeException('Google did not return an access token.');
        }
        $identity = googleRequest('https://openidconnect.googleapis.com/v1/userinfo', [], $tokens['access_token']);
        $email = strtolower(trim((string) ($identity['email'] ?? '')));
        $name = trim((string) ($identity['name'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($identity['email_verified'] ?? false, [true, 'true', 1, '1'], true)) {
            throw new RuntimeException('Google did not provide a verified email address.');
        }
        if ($name === '') $name = strstr($email, '@', true) ?: 'Google user';
        $name = mb_substr($name, 0, 80);

        $statement = $database->prepare('SELECT id, name, email, role, approval_status FROM users WHERE email = ?');
        $statement->execute([$email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $role = $email === strtolower(ADMIN_EMAIL) ? 'admin' : $oauthState['role'];
            try {
                $insert = $database->prepare('INSERT INTO users (name, email, password_hash, role, email_verified_at, approval_status) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?)');
                $approval = $role === 'admin' ? 'approved' : 'pending';
                $insert->execute([$name, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $role, $approval]);
                $user = ['id' => (int) $database->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => $role, 'approval_status' => $approval];
            } catch (PDOException $exception) {
                $statement->execute([$email]);
                $user = $statement->fetch(PDO::FETCH_ASSOC);
                if (!$user) throw $exception;
            }
        } else {
            $database->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP) WHERE id = ?')->execute([(int) $user['id']]);
        }

        session_regenerate_id(true);
        $_SESSION['tutorrush_user_id'] = (int) $user['id'];
        $_SESSION['tutorrush_role'] = $user['role'];
        header('Location: ' . (($user['approval_status'] ?? 'approved') === 'pending' ? 'waitlist.html' : dashboardForRole($user['role'])), true, 302);
        exit;
    } catch (Throwable $exception) {
        debugLog('google_oauth_failed', ['reason' => $exception->getMessage()]);
        redirectGoogleError($flow, 'failed');
    }
}

function sendPasswordResetEmail(string $email, string $name, string $token): bool {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
    $resetUrl = $scheme . '://' . $host . '/reset-password.html?token=' . rawurlencode($token);
    $smtpHost = getenv('TUTORUSH_SMTP_HOST') ?: (defined('TUTORUSH_SMTP_HOST') ? TUTORUSH_SMTP_HOST : 'smtp.hostinger.com');
    $smtpPort = (int) (getenv('TUTORUSH_SMTP_PORT') ?: (defined('TUTORUSH_SMTP_PORT') ? TUTORUSH_SMTP_PORT : 465));
    $smtpUsername = getenv('TUTORUSH_SMTP_USERNAME') ?: (defined('TUTORUSH_SMTP_USERNAME') ? TUTORUSH_SMTP_USERNAME : 'info@tutorush.com');
    $smtpPassword = getenv('TUTORUSH_SMTP_PASSWORD') ?: (defined('TUTORUSH_SMTP_PASSWORD') ? TUTORUSH_SMTP_PASSWORD : null);
    $fromEmail = getenv('TUTORUSH_MAIL_FROM') ?: (defined('TUTORUSH_MAIL_FROM') ? TUTORUSH_MAIL_FROM : $smtpUsername);
    $fromName = getenv('TUTORUSH_MAIL_FROM_NAME') ?: (defined('TUTORUSH_MAIL_FROM_NAME') ? TUTORUSH_MAIL_FROM_NAME : 'TutorRush');

    if (!$smtpPassword) {
        debugLog('password_reset_mail_failed', ['reason' => 'TUTORUSH_SMTP_PASSWORD is not configured']);
        return false;
    }

    try {
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $smtpHost;
        $mailer->SMTPAuth = true;
        $mailer->Username = $smtpUsername;
        $mailer->Password = $smtpPassword;
        $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mailer->Port = $smtpPort;
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($fromEmail, $fromName);
        $mailer->addAddress($email, $name);
        $mailer->Subject = 'Reset your TutorRush password';
        $mailer->Body = "Hi $name,\n\nUse this link to reset your TutorRush password:\n$resetUrl\n\nThis link expires in 30 minutes. If you did not request this, you can ignore this email.\n";
        $mailer->send();
        return true;
    } catch (Throwable $exception) {
        debugLog('password_reset_mail_failed', ['reason' => $exception->getMessage()]);
        return false;
    }
}

function sendVerificationEmail(string $email, string $name, string $token): bool {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
    $verifyUrl = $scheme . '://' . $host . '/verify-email.html?token=' . rawurlencode($token);
    $smtpHost = getenv('TUTORUSH_SMTP_HOST') ?: (defined('TUTORUSH_SMTP_HOST') ? TUTORUSH_SMTP_HOST : 'smtp.hostinger.com');
    $smtpPort = (int) (getenv('TUTORUSH_SMTP_PORT') ?: (defined('TUTORUSH_SMTP_PORT') ? TUTORUSH_SMTP_PORT : 465));
    $smtpUsername = getenv('TUTORUSH_SMTP_USERNAME') ?: (defined('TUTORUSH_SMTP_USERNAME') ? TUTORUSH_SMTP_USERNAME : 'info@tutorush.com');
    $smtpPassword = getenv('TUTORUSH_SMTP_PASSWORD') ?: (defined('TUTORUSH_SMTP_PASSWORD') ? TUTORUSH_SMTP_PASSWORD : null);
    $fromEmail = getenv('TUTORUSH_MAIL_FROM') ?: (defined('TUTORUSH_MAIL_FROM') ? TUTORUSH_MAIL_FROM : $smtpUsername);
    $fromName = getenv('TUTORUSH_MAIL_FROM_NAME') ?: (defined('TUTORUSH_MAIL_FROM_NAME') ? TUTORUSH_MAIL_FROM_NAME : 'TutorRush');
    if (!$smtpPassword) {
        debugLog('verification_mail_failed', ['reason' => 'TUTORUSH_SMTP_PASSWORD is not configured']);
        return false;
    }
    try {
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP(); $mailer->Host = $smtpHost; $mailer->SMTPAuth = true; $mailer->Username = $smtpUsername; $mailer->Password = $smtpPassword;
        $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS; $mailer->Port = $smtpPort; $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($fromEmail, $fromName); $mailer->addAddress($email, $name); $mailer->Subject = 'Confirm your TutorRush account';
        $mailer->Body = "Hi $name,\n\nConfirm your TutorRush account by clicking this link:\n$verifyUrl\n\nThis link expires in 24 hours.\n";
        $mailer->send(); return true;
    } catch (Throwable $exception) {
        debugLog('verification_mail_failed', ['reason' => $exception->getMessage()]); return false;
    }
}

function requireUserId(): int {
    if (!isset($_SESSION['tutorrush_user_id'])) {
        respond(401, ['error' => 'Please log in first.']);
    }
    return (int) $_SESSION['tutorrush_user_id'];
}

function requireApprovedUser(PDO $database): int {
    $userId = requireUserId();
    $statement = $database->prepare('SELECT role, approval_status FROM users WHERE id = ?');
    $statement->execute([$userId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) respond(401, ['error' => 'Please log in first.']);
    if ($row['role'] !== 'admin' && $row['approval_status'] !== 'approved') {
        respond(403, ['error' => 'Your account is on the waitlist. We will let you know when it is approved.']);
    }
    return $userId;
}

// Tutors count as reachable only while they are online and their open dashboard keeps checking in.
function availableTutorIds(PDO $database, string $subject, int $excludeUserId, ?int $requestId = null): array {
    $sql = "SELECT id, tutor_subjects FROM users WHERE id != ? AND availability_status = 'online' AND role IN ('tutor', 'both') AND approval_status = 'approved' AND last_seen_at >= datetime('now', ?)";
    $params = [$excludeUserId, '-' . TUTOR_HEARTBEAT_SECONDS . ' seconds'];
    if ($requestId !== null) {
        $sql .= ' AND NOT EXISTS (SELECT 1 FROM match_declines d WHERE d.request_id = ? AND d.tutor_id = users.id)';
        $params[] = $requestId;
    }
    $statement = $database->prepare($sql);
    $statement->execute($params);
    $ids = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        $subjects = json_decode((string) $candidate['tutor_subjects'], true);
        if (is_array($subjects) && in_array($subject, $subjects, true)) $ids[] = (int) $candidate['id'];
    }
    return $ids;
}

function sendPlainEmail(string $email, string $name, string $subject, string $body): bool {
    $smtpPassword = getenv('TUTORUSH_SMTP_PASSWORD') ?: (defined('TUTORUSH_SMTP_PASSWORD') ? TUTORUSH_SMTP_PASSWORD : null);
    if (!$smtpPassword) return false;
    $smtpUsername = getenv('TUTORUSH_SMTP_USERNAME') ?: (defined('TUTORUSH_SMTP_USERNAME') ? TUTORUSH_SMTP_USERNAME : 'info@tutorush.com');
    try {
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = getenv('TUTORUSH_SMTP_HOST') ?: (defined('TUTORUSH_SMTP_HOST') ? TUTORUSH_SMTP_HOST : 'smtp.hostinger.com');
        $mailer->SMTPAuth = true;
        $mailer->Username = $smtpUsername;
        $mailer->Password = $smtpPassword;
        $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mailer->Port = (int) (getenv('TUTORUSH_SMTP_PORT') ?: (defined('TUTORUSH_SMTP_PORT') ? TUTORUSH_SMTP_PORT : 465));
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom(getenv('TUTORUSH_MAIL_FROM') ?: (defined('TUTORUSH_MAIL_FROM') ? TUTORUSH_MAIL_FROM : $smtpUsername), getenv('TUTORUSH_MAIL_FROM_NAME') ?: (defined('TUTORUSH_MAIL_FROM_NAME') ? TUTORUSH_MAIL_FROM_NAME : 'TutorRush'));
        $mailer->addAddress($email, $name);
        $mailer->Subject = $subject;
        $mailer->Body = $body;
        $mailer->send();
        return true;
    } catch (Throwable $exception) {
        debugLog('mail_failed', ['reason' => $exception->getMessage()]);
        return false;
    }
}

function studentRequestPayload(PDO $database, int $requestId, int $studentId): ?array {
    $statement = $database->prepare('SELECT m.id, m.status, m.subject, m.help_type, m.duration, m.note, m.created_at, t.name AS tutor_name, r.id AS room_id FROM match_requests m LEFT JOIN users t ON t.id = m.tutor_id LEFT JOIN study_rooms r ON r.match_request_id = m.id WHERE m.id = ? AND m.student_id = ?');
    $statement->execute([$requestId, $studentId]);
    $match = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$match) return null;
    $createdAt = strtotime($match['created_at'] . ' UTC');
    if ($match['status'] === 'pending' && $createdAt < time() - OFFER_TTL_MINUTES * 60) {
        $match['status'] = 'expired';
    }
    return [
        'id' => (int) $match['id'],
        'status' => $match['status'],
        'subject' => $match['subject'],
        'help_type' => $match['help_type'],
        'duration' => (int) $match['duration'],
        'note' => $match['note'],
        'tutor_name' => $match['tutor_name'],
        'room_id' => $match['room_id'] === null ? null : (int) $match['room_id'],
        'available_tutors' => $match['status'] === 'pending' ? count(availableTutorIds($database, $match['subject'], $studentId, (int) $match['id'])) : null,
        'created_at' => isoTime($match['created_at']),
        'now' => isoTime(gmdate('Y-m-d H:i:s')),
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $createdAt + OFFER_TTL_MINUTES * 60),
    ];
}

function applicationDirectory(): string {
    $directory = __DIR__ . '/storage/applications';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the application directory.');
    }
    return $directory;
}

function applicationDocuments(PDO $database, int $userId): array {
    $statement = $database->prepare('SELECT id, kind, original_name, size, created_at FROM application_documents WHERE user_id = ? ORDER BY kind DESC, id ASC');
    $statement->execute([$userId]);
    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'kind' => $row['kind'],
        'name' => $row['original_name'],
        'size' => (int) $row['size'],
        'createdAt' => isoTime($row['created_at']),
    ], $statement->fetchAll(PDO::FETCH_ASSOC));
}

function requireAdmin(): void {
    if (($_SESSION['tutorrush_role'] ?? '') !== 'admin') {
        respond(403, ['error' => 'Administrator access is required.']);
    }
}

function userPayload(array $user): array {
    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'approvalStatus' => $user['approval_status'] ?? 'approved',
        'availabilityStatus' => $user['availability_status'] ?? ((int) ($user['is_online'] ?? 0) ? 'online' : 'offline'),
        'subjects' => json_decode((string) ($user['tutor_subjects'] ?? ''), true) ?: [],
    ];
}

const OFFER_TTL_MINUTES = 15;
const MAX_ROOM_FILE_BYTES = 3 * 1024 * 1024;
const MAX_ROOM_FILES = 25;
const ROOM_FILE_TYPES = [
    'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'txt' => 'text/plain', 'md' => 'text/plain', 'csv' => 'text/csv',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

function isoTime(?string $value): ?string {
    return $value === null ? null : str_replace(' ', 'T', $value) . 'Z';
}

function activeRoomId(PDO $database, int $userId): ?int {
    $statement = $database->prepare("SELECT id FROM study_rooms WHERE status = 'active' AND (student_id = ? OR tutor_id = ?) LIMIT 1");
    $statement->execute([$userId, $userId]);
    $id = $statement->fetchColumn();
    return $id === false ? null : (int) $id;
}

function loadRoomForUser(PDO $database, int $roomId, int $userId): array {
    $statement = $database->prepare('SELECT r.id, r.student_id, r.tutor_id, r.video_token, r.status, r.ended_by, r.created_at, r.ended_at, r.call_started_at, m.subject, m.help_type, m.duration, m.note, s.name AS student_name, t.name AS tutor_name FROM study_rooms r JOIN match_requests m ON m.id = r.match_request_id JOIN users s ON s.id = r.student_id JOIN users t ON t.id = r.tutor_id WHERE r.id = ? AND (r.student_id = ? OR r.tutor_id = ?)');
    $statement->execute([$roomId, $userId, $userId]);
    $room = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$room) respond(404, ['error' => 'Study room not found.']);
    return $room;
}

function requireActiveRoom(array $room): void {
    if ($room['status'] !== 'active') respond(409, ['error' => 'This session has ended. The room is read-only.']);
}

function roomPayload(array $room, int $viewerId): array {
    $viewerIsTutor = (int) $room['tutor_id'] === $viewerId;
    $active = $room['status'] === 'active';
    return [
        'id' => (int) $room['id'],
        'status' => $room['status'],
        'role' => $viewerIsTutor ? 'tutor' : 'student',
        'subject' => $room['subject'],
        'helpType' => $room['help_type'],
        'duration' => (int) $room['duration'],
        'note' => $room['note'],
        'studentName' => $room['student_name'],
        'tutorName' => $room['tutor_name'],
        'partnerName' => $viewerIsTutor ? $room['student_name'] : $room['tutor_name'],
        'createdAt' => isoTime($room['created_at']),
        'endedAt' => isoTime($room['ended_at']),
        'endedBy' => $room['ended_by'] === null ? null : ((int) $room['ended_by'] === $viewerId ? 'you' : 'partner'),
        'videoToken' => $active && ($viewerIsTutor || $room['call_started_at'] !== null) ? $room['video_token'] : null,
        'callStarted' => $room['call_started_at'] !== null,
    ];
}

function roomMessages(PDO $database, int $roomId, int $viewerId, int $afterId): array {
    $statement = $database->prepare('SELECT m.id, m.sender_id, m.kind, m.body, m.file_id, m.created_at, u.name AS sender_name FROM room_messages m JOIN users u ON u.id = m.sender_id WHERE m.room_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT 200');
    $statement->execute([$roomId, $afterId]);
    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'mine' => (int) $row['sender_id'] === $viewerId,
        'kind' => $row['kind'],
        'body' => $row['body'],
        'fileId' => $row['file_id'] === null ? null : (int) $row['file_id'],
        'senderName' => $row['sender_name'],
        'createdAt' => isoTime($row['created_at']),
    ], $statement->fetchAll(PDO::FETCH_ASSOC));
}

function roomFiles(PDO $database, int $roomId): array {
    $statement = $database->prepare('SELECT f.id, f.original_name, f.size, f.created_at, u.name AS uploader_name FROM room_files f JOIN users u ON u.id = f.uploader_id WHERE f.room_id = ? ORDER BY f.id DESC');
    $statement->execute([$roomId]);
    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'name' => $row['original_name'],
        'size' => (int) $row['size'],
        'uploaderName' => $row['uploader_name'],
        'createdAt' => isoTime($row['created_at']),
    ], $statement->fetchAll(PDO::FETCH_ASSOC));
}

function uploadDirectory(): string {
    $directory = __DIR__ . '/storage/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the upload directory.');
    }
    return $directory;
}

try {
    $databaseDirectory = __DIR__ . '/storage';
    if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0750, true) && !is_dir($databaseDirectory)) {
        throw new RuntimeException('Could not create the database directory.');
    }

    $database = new PDO('sqlite:' . $databaseDirectory . '/tutorrush.sqlite');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT \'student\' CHECK(role IN (\'student\', \'tutor\', \'both\', \'admin\')),
        is_online INTEGER NOT NULL DEFAULT 0,
        availability_status TEXT NOT NULL DEFAULT \'offline\',
        email_verified_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $userColumns = $database->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('role', $userColumns, true)) {
        $database->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'student'");
    }
    if (!in_array('email_verified_at', $userColumns, true)) {
        $database->exec('ALTER TABLE users ADD COLUMN email_verified_at TEXT');
        $database->exec("UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE email_verified_at IS NULL");
    }
    if (!in_array('is_online', $userColumns, true)) {
        $database->exec('ALTER TABLE users ADD COLUMN is_online INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('availability_status', $userColumns, true)) {
        $database->exec("ALTER TABLE users ADD COLUMN availability_status TEXT NOT NULL DEFAULT 'offline'");
        $database->exec("UPDATE users SET availability_status = CASE WHEN is_online = 1 THEN 'online' ELSE 'offline' END");
    }
    if (!in_array('tutor_subjects', $userColumns, true)) {
        $database->exec("ALTER TABLE users ADD COLUMN tutor_subjects TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('approval_status', $userColumns, true)) {
        $database->exec("ALTER TABLE users ADD COLUMN approval_status TEXT NOT NULL DEFAULT 'approved'");
    }
    if (!in_array('last_seen_at', $userColumns, true)) {
        $database->exec('ALTER TABLE users ADD COLUMN last_seen_at TEXT');
    }
    foreach (['school', 'field_of_study', 'application_submitted_at'] as $applicationColumn) {
        if (!in_array($applicationColumn, $userColumns, true)) {
            $database->exec("ALTER TABLE users ADD COLUMN $applicationColumn TEXT");
        }
    }
    $userTableSql = (string) $database->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if (!str_contains($userTableSql, "'tutor'")) {
        $database->exec('PRAGMA foreign_keys = OFF');
        $database->exec('ALTER TABLE users RENAME TO users_before_tutor_role');
        $database->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'student' CHECK(role IN ('student', 'tutor', 'both', 'admin')),
            is_online INTEGER NOT NULL DEFAULT 0,
            availability_status TEXT NOT NULL DEFAULT 'offline',
            email_verified_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $database->exec('INSERT INTO users (id, name, email, password_hash, role, created_at) SELECT id, name, email, password_hash, role, created_at FROM users_before_tutor_role');
        $database->exec('DROP TABLE users_before_tutor_role');
        $database->exec('PRAGMA foreign_keys = ON');
    }
    $userTableSql = (string) $database->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if (!str_contains($userTableSql, "'both'")) {
        $database->exec('PRAGMA foreign_keys = OFF');
        $database->exec('ALTER TABLE users RENAME TO users_before_both_role');
        $database->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'student' CHECK(role IN ('student', 'tutor', 'both', 'admin')),
            is_online INTEGER NOT NULL DEFAULT 0,
            availability_status TEXT NOT NULL DEFAULT 'offline',
            email_verified_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $database->exec('INSERT INTO users (id, name, email, password_hash, role, created_at) SELECT id, name, email, password_hash, role, created_at FROM users_before_both_role');
        $database->exec('DROP TABLE users_before_both_role');
        $database->exec('PRAGMA foreign_keys = ON');
    }
    $dependentTableDefinitions = [
        'bookings' => [
            'CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER NOT NULL, tutor_name TEXT NOT NULL, mode TEXT NOT NULL CHECK(mode IN (\'immediate\', \'plan\')), status TEXT NOT NULL, scheduled_for TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(student_id) REFERENCES users(id))',
            'id, student_id, tutor_name, mode, status, scheduled_for, created_at',
        ],
        'messages' => [
            'CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, tutor_name TEXT NOT NULL, sender TEXT NOT NULL CHECK(sender IN (\'student\', \'tutor\')), body TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id))',
            'id, user_id, tutor_name, sender, body, created_at',
        ],
        'availability' => [
            'CREATE TABLE availability (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, scheduled_for TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id))',
            'id, user_id, scheduled_for, created_at',
        ],
        'password_resets' => [
            'CREATE TABLE password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE, expires_at INTEGER NOT NULL, used_at INTEGER, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id))',
            'id, user_id, token_hash, expires_at, used_at, created_at',
        ],
    ];
    $database->exec('PRAGMA foreign_keys = OFF');
    foreach ($dependentTableDefinitions as $tableName => [$createSql, $columns]) {
        $tableSql = (string) $database->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '$tableName'")->fetchColumn();
        if ($tableSql !== '' && (str_contains($tableSql, 'users_before_tutor_role') || str_contains($tableSql, 'users_before_both_role'))) {
            $legacyTable = $tableName . '_before_role_repair';
            $database->exec("ALTER TABLE $tableName RENAME TO $legacyTable");
            $database->exec($createSql);
            $database->exec("INSERT INTO $tableName ($columns) SELECT $columns FROM $legacyTable");
            $database->exec("DROP TABLE $legacyTable");
        }
    }
    $database->exec('PRAGMA foreign_keys = ON');
    $promoteAdmin = $database->prepare('UPDATE users SET role = \'admin\', email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP) WHERE email = ?');
    $promoteAdmin->execute([ADMIN_EMAIL]);
    $database->exec('CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        tutor_name TEXT NOT NULL,
        mode TEXT NOT NULL CHECK(mode IN (\'immediate\', \'plan\')),
        status TEXT NOT NULL,
        scheduled_for TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(student_id) REFERENCES users(id)
    )');
    $database->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        tutor_name TEXT NOT NULL,
        sender TEXT NOT NULL CHECK(sender IN (\'student\', \'tutor\')),
        body TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id)
    )');
    $database->exec('CREATE TABLE IF NOT EXISTS availability (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        scheduled_for TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id)
    )');
    $database->exec('CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at INTEGER NOT NULL,
        used_at INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id)
    )');
    $database->exec('CREATE TABLE IF NOT EXISTS match_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        tutor_id INTEGER,
        subject TEXT NOT NULL,
        help_type TEXT NOT NULL,
        duration INTEGER NOT NULL,
        note TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(student_id) REFERENCES users(id),
        FOREIGN KEY(tutor_id) REFERENCES users(id)
    )');
    $matchColumns = $database->query('PRAGMA table_info(match_requests)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('subject', $matchColumns, true)) {
        $database->exec("ALTER TABLE match_requests ADD COLUMN subject TEXT NOT NULL DEFAULT 'General tutoring'");
    }
    foreach ($database->query('PRAGMA table_info(match_requests)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        if ($column['name'] === 'tutor_id' && (int) $column['notnull'] === 1) {
            // Requests are now broadcast to every matching tutor, so the tutor is only set once someone accepts.
            $database->exec('PRAGMA foreign_keys = OFF');
            $database->exec('BEGIN');
            $database->exec('CREATE TABLE match_requests_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id INTEGER NOT NULL,
                tutor_id INTEGER,
                subject TEXT NOT NULL,
                help_type TEXT NOT NULL,
                duration INTEGER NOT NULL,
                note TEXT,
                status TEXT NOT NULL DEFAULT \'pending\',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(student_id) REFERENCES users(id),
                FOREIGN KEY(tutor_id) REFERENCES users(id)
            )');
            $database->exec("INSERT INTO match_requests_new (id, student_id, tutor_id, subject, help_type, duration, note, status, created_at) SELECT id, student_id, tutor_id, subject, help_type, duration, note, CASE WHEN status = 'pending' THEN 'cancelled' ELSE status END, created_at FROM match_requests");
            $database->exec('DROP TABLE match_requests');
            $database->exec('ALTER TABLE match_requests_new RENAME TO match_requests');
            $database->exec('COMMIT');
            $database->exec('PRAGMA foreign_keys = ON');
        }
    }
    $database->exec('CREATE TABLE IF NOT EXISTS match_declines (
        request_id INTEGER NOT NULL,
        tutor_id INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(request_id, tutor_id),
        FOREIGN KEY(request_id) REFERENCES match_requests(id),
        FOREIGN KEY(tutor_id) REFERENCES users(id)
    )');
    $database->exec('CREATE TABLE IF NOT EXISTS email_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id)
    )');
    $database->exec('CREATE TABLE IF NOT EXISTS study_rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        match_request_id INTEGER NOT NULL UNIQUE,
        student_id INTEGER NOT NULL,
        tutor_id INTEGER NOT NULL,
        video_token TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'active\',
        ended_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ended_at TEXT,
        FOREIGN KEY(match_request_id) REFERENCES match_requests(id),
        FOREIGN KEY(student_id) REFERENCES users(id),
        FOREIGN KEY(tutor_id) REFERENCES users(id)
    )');
    $database->exec('CREATE TABLE IF NOT EXISTS room_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER NOT NULL,
        sender_id INTEGER NOT NULL,
        kind TEXT NOT NULL DEFAULT \'user\',
        body TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(room_id) REFERENCES study_rooms(id),
        FOREIGN KEY(sender_id) REFERENCES users(id)
    )');
    $database->exec('CREATE TABLE IF NOT EXISTS room_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER NOT NULL,
        uploader_id INTEGER NOT NULL,
        original_name TEXT NOT NULL,
        stored_name TEXT NOT NULL UNIQUE,
        size INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(room_id) REFERENCES study_rooms(id),
        FOREIGN KEY(uploader_id) REFERENCES users(id)
    )');
    $database->exec('CREATE INDEX IF NOT EXISTS idx_room_messages_room ON room_messages(room_id, id)');
    $database->exec('CREATE TABLE IF NOT EXISTS room_call_signals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER NOT NULL,
        sender_id INTEGER NOT NULL,
        signal_type TEXT NOT NULL,
        payload TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(room_id) REFERENCES study_rooms(id),
        FOREIGN KEY(sender_id) REFERENCES users(id)
    )');
    $database->exec('CREATE INDEX IF NOT EXISTS idx_call_signals_room ON room_call_signals(room_id, id)');
    $roomColumns = array_column($database->query('PRAGMA table_info(study_rooms)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('call_started_at', $roomColumns, true)) {
        $database->exec('ALTER TABLE study_rooms ADD COLUMN call_started_at TEXT');
    }
    $messageColumns = array_column($database->query('PRAGMA table_info(room_messages)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('file_id', $messageColumns, true)) {
        $database->exec('ALTER TABLE room_messages ADD COLUMN file_id INTEGER');
    }
    $database->exec('CREATE TABLE IF NOT EXISTS feedback_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        room_id INTEGER,
        category TEXT NOT NULL,
        message TEXT NOT NULL,
        page TEXT,
        status TEXT NOT NULL DEFAULT \'open\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_by INTEGER,
        resolved_at TEXT,
        FOREIGN KEY(user_id) REFERENCES users(id)
    )');
    $database->exec('CREATE TABLE IF NOT EXISTS application_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        kind TEXT NOT NULL,
        original_name TEXT NOT NULL,
        stored_name TEXT NOT NULL UNIQUE,
        size INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id)
    )');

    $action = $_GET['action'] ?? '';
    if (in_array($action, ['google-start', 'google-callback'], true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            redirectGoogleError('login', 'failed');
        }
        handleGoogleOAuth($database, $action);
    }

    $request = requestBody();
    $action = $request['action'] ?? '';

    if (!is_string($action) || !in_array($action, ['session', 'signup', 'verify-email', 'resend-verification', 'login', 'logout', 'request-password-reset', 'reset-password', 'set-tutor-status', 'set-tutor-subjects', 'find-online-tutor', 'tutor-requests', 'accept-match-request', 'decline-match-request', 'cancel-match-request', 'match-request-status', 'my-match-request', 'my-rooms', 'room-details', 'room-send-message', 'room-upload-file', 'room-download-file', 'room-start-call', 'room-send-signal', 'room-get-signals', 'end-room', 'booking', 'submit-feedback', 'admin-feedback', 'admin-set-feedback-status', 'my-application', 'submit-application', 'upload-application-document', 'delete-application-document', 'admin-user-details', 'admin-application-document', 'message', 'availability', 'admin-dashboard', 'admin-set-role', 'admin-set-approval', 'admin-delete-user'], true)) {
        respond(404, ['error' => 'Unknown action.']);
    }

    if ($action === 'session') {
        $user = null;
        if (isset($_SESSION['tutorrush_user_id'])) {
            $statement = $database->prepare('SELECT id, name, email, role, is_online, availability_status, tutor_subjects, approval_status FROM users WHERE id = ?');
            $statement->execute([(int) $_SESSION['tutorrush_user_id']]);
            $user = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($user) {
                $database->prepare('UPDATE users SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $user['id']]);
            }
        }
        respond(200, ['csrfToken' => csrfToken(), 'user' => $user ? userPayload($user) : null]);
    }

    if ($action === 'request-password-reset') {
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            respond(422, ['error' => 'Provide a valid email address.']);
        }
        $statement = $database->prepare('SELECT id, name, email FROM users WHERE email = ?');
        $statement->execute([$email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $insert = $database->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
            $insert->execute([(int) $user['id'], hash('sha256', $token), time() + 1800]);
            if (!sendPasswordResetEmail($user['email'], $user['name'], $token)) {
                debugLog('password_reset_mail_failed', ['userId' => (int) $user['id']]);
            }
        }
        respond(200, ['message' => 'If an account exists for that email, a reset link has been sent.']);
    }

    if ($action === 'verify-email') {
        $token = trim((string) ($request['token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) respond(422, ['error' => 'This confirmation link is invalid.']);
        $statement = $database->prepare('SELECT id, user_id FROM email_verifications WHERE token_hash = ? AND expires_at > ?');
        $statement->execute([hash('sha256', $token), time()]);
        $verification = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$verification) respond(400, ['error' => 'This confirmation link is invalid or expired.']);
        $update = $database->prepare('UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->execute([(int) $verification['user_id']]);
        $delete = $database->prepare('DELETE FROM email_verifications WHERE user_id = ?');
        $delete->execute([(int) $verification['user_id']]);
        respond(200, ['message' => 'Your email is confirmed. You are on our waitlist now, and we will let you know when your account is approved. You can log in to check your status.']);
    }

    if ($action === 'resend-verification') {
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            respond(422, ['error' => 'Provide a valid email address.']);
        }
        $statement = $database->prepare('SELECT id, name, email, email_verified_at FROM users WHERE email = ?');
        $statement->execute([$email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user || !empty($user['email_verified_at'])) {
            respond(200, ['message' => 'If the account needs confirmation, a new email has been sent.']);
        }
        $database->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([(int) $user['id']]);
        $token = bin2hex(random_bytes(32));
        $database->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)')->execute([(int) $user['id'], hash('sha256', $token), time() + 86400]);
        if (!sendVerificationEmail($user['email'], $user['name'], $token)) {
            respond(500, ['error' => 'We could not send the confirmation email. Contact support.']);
        }
        respond(200, ['message' => 'A new confirmation email has been sent.']);
    }

    requireCsrfToken();

    if ($action === 'logout') {
        $database->prepare("UPDATE users SET is_online = 0, availability_status = 'offline' WHERE id = ? AND availability_status = 'online'")->execute([(int) ($_SESSION['tutorrush_user_id'] ?? 0)]);
        $_SESSION = [];
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
        respond(200, ['ok' => true]);
    }

    if ($action === 'signup') {
        $name = validateText($request['name'] ?? '', 80, 'Name');
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        $password = (string) ($request['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || strlen($password) < 8 || strlen($password) > 128) {
            respond(422, ['error' => 'Provide a name, valid email, and password of at least 8 characters.']);
        }
        $requestedRole = $request['role'] ?? 'student';
        if (!in_array($requestedRole, ['student', 'tutor', 'both'], true)) {
            $requestedRole = 'student';
        }
        $role = $email === ADMIN_EMAIL ? 'admin' : $requestedRole;
        $school = validateText($request['school'] ?? '', 120, 'School or university');
        $fieldOfStudy = trim((string) ($request['fieldOfStudy'] ?? ''));
        if (mb_strlen($fieldOfStudy) > 120 || ($role !== 'student' && $role !== 'admin' && $fieldOfStudy === '')) {
            respond(422, ['error' => 'Tell us what you study (up to 120 characters).']);
        }
        try {
            $statement = $database->prepare('INSERT INTO users (name, email, password_hash, role, email_verified_at, approval_status, school, field_of_study) VALUES (?, ?, ?, ?, NULL, ?, ?, ?)');
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $role === 'admin' ? 'approved' : 'pending', $school, $fieldOfStudy ?: null]);
        } catch (PDOException $exception) {
            if ((int) $exception->getCode() === 23000) respond(409, ['error' => 'An account with that email already exists.']);
            throw $exception;
        }
        $userId = (int) $database->lastInsertId();
        $token = bin2hex(random_bytes(32));
        $verification = $database->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
        $verification->execute([$userId, hash('sha256', $token), time() + 86400]);
        if (!sendVerificationEmail($email, $name, $token)) {
            $database->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$userId]);
            $database->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            respond(500, ['error' => 'Your account was created, but the confirmation email could not be sent. Contact support.']);
        }
        respond(201, ['message' => 'You are on the waitlist! Check your email to confirm your address. We will let you know as soon as your account is approved.']);
    }

    if ($action === 'login') {
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        $password = (string) ($request['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) > 128) respond(422, ['error' => 'Provide a valid email and password.']);
        $statement = $database->prepare('SELECT id, name, email, password_hash, role, email_verified_at, availability_status, approval_status FROM users WHERE email = ?');
        $statement->execute([$email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            respond(404, ['error' => 'No account exists for that email address.']);
        }
        if ($email === strtolower(ADMIN_EMAIL) && empty($user['email_verified_at'])) {
            $database->prepare("UPDATE users SET role = 'admin', email_verified_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int) $user['id']]);
            $user['role'] = 'admin';
            $user['email_verified_at'] = gmdate('Y-m-d H:i:s');
        }
        if (empty($user['email_verified_at'])) {
            respond(403, ['error' => 'Please confirm your email before logging in. Check your inbox for the confirmation link.']);
        }
        if (!password_verify($password, $user['password_hash'])) {
            respond(401, ['error' => 'That email is registered, but the password is incorrect.']);
        }
        session_regenerate_id(true);
        $_SESSION['tutorrush_user_id'] = (int) $user['id'];
        $_SESSION['tutorrush_role'] = $user['role'];
        respond(200, ['user' => userPayload($user)]);
    }

    if ($action === 'reset-password') {
        $token = trim((string) ($request['token'] ?? ''));
        $password = (string) ($request['password'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || strlen($password) < 8 || strlen($password) > 128) {
            respond(422, ['error' => 'Use a valid reset link and a password of at least 8 characters.']);
        }
        $statement = $database->prepare('SELECT id, user_id FROM password_resets WHERE token_hash = ? AND expires_at > ? AND used_at IS NULL');
        $statement->execute([hash('sha256', $token), time()]);
        $reset = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$reset) respond(400, ['error' => 'This reset link is invalid or expired.']);
        $update = $database->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $update->execute([password_hash($password, PASSWORD_DEFAULT), (int) $reset['user_id']]);
        $used = $database->prepare('UPDATE password_resets SET used_at = ? WHERE id = ?');
        $used->execute([time(), (int) $reset['id']]);
        respond(200, ['message' => 'Your password has been reset. You can log in now.']);
    }

    if ($action === 'set-tutor-status') {
        $userId = requireApprovedUser($database);
        $status = filter_var($request['online'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $userStatement = $database->prepare('SELECT role, tutor_subjects FROM users WHERE id = ?');
        $userStatement->execute([$userId]);
        $tutorRow = $userStatement->fetch(PDO::FETCH_ASSOC);
        $role = $tutorRow['role'] ?? null;
        if (!in_array($role, ['tutor', 'both', 'admin'], true) || $status === null) {
            respond(403, ['error' => 'Tutor access is required.']);
        }
        if ($status && activeRoomId($database, $userId) !== null) {
            respond(409, ['error' => 'End your active study session before going online.']);
        }
        if ($status && !json_decode((string) $tutorRow['tutor_subjects'], true)) {
            respond(422, ['error' => 'Choose at least one subject you can teach before going online.']);
        }
        $availabilityStatus = $status ? 'online' : 'offline';
        $statement = $database->prepare('UPDATE users SET is_online = ?, availability_status = ?, last_seen_at = CURRENT_TIMESTAMP WHERE id = ?');
        $statement->execute([$status ? 1 : 0, $availabilityStatus, $userId]);
        respond(200, ['online' => (bool) $status, 'status' => $availabilityStatus]);
    }

    if ($action === 'set-tutor-subjects') {
        $userId = requireApprovedUser($database);
        $subjects = $request['subjects'] ?? [];
        if (!is_array($subjects) || count($subjects) > 20) respond(422, ['error' => 'Choose up to 20 subjects.']);
        $subjects = array_values(array_unique(array_filter(array_map(static fn($subject) => trim((string) $subject), $subjects))));
        $statement = $database->prepare('UPDATE users SET tutor_subjects = ?, availability_status = CASE WHEN ? = 0 AND availability_status = \'online\' THEN \'offline\' ELSE availability_status END, is_online = CASE WHEN ? = 0 AND availability_status = \'online\' THEN 0 ELSE is_online END WHERE id = ?');
        $statement->execute([json_encode($subjects, JSON_UNESCAPED_UNICODE), count($subjects), count($subjects), $userId]);
        respond(200, ['subjects' => $subjects]);
    }

    if ($action === 'find-online-tutor') {
        $userId = requireApprovedUser($database);
        $subject = validateText($request['subject'] ?? '', 80, 'Class or topic');
        $helpType = validateText($request['helpType'] ?? '', 60, 'Help type');
        $duration = filter_var($request['duration'] ?? null, FILTER_VALIDATE_INT);
        $note = trim((string) ($request['note'] ?? ''));
        if (!$duration || !in_array($duration, [30, 60, 90, 120], true) || mb_strlen($note) > 500) {
            respond(422, ['error' => 'Choose a valid session duration and keep your note under 500 characters.']);
        }
        if (activeRoomId($database, $userId) !== null) {
            respond(409, ['error' => 'You already have an active study session. End it before requesting a new tutor.']);
        }
        if (!availableTutorIds($database, $subject, $userId)) {
            respond(404, ['error' => 'No tutors are online for that subject right now. Try again soon.']);
        }
        $database->prepare("UPDATE match_requests SET status = 'cancelled' WHERE student_id = ? AND status = 'pending'")->execute([$userId]);
        $database->prepare('INSERT INTO match_requests (student_id, subject, help_type, duration, note) VALUES (?, ?, ?, ?, ?)')->execute([$userId, $subject, $helpType, $duration, $note ?: null]);
        respond(200, ['request' => studentRequestPayload($database, (int) $database->lastInsertId(), $userId)]);
    }

    if ($action === 'tutor-requests') {
        $userId = requireApprovedUser($database);
        $database->prepare('UPDATE users SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$userId]);
        $tutorStatement = $database->prepare('SELECT availability_status, tutor_subjects FROM users WHERE id = ?');
        $tutorStatement->execute([$userId]);
        $tutor = $tutorStatement->fetch(PDO::FETCH_ASSOC);
        $requests = [];
        if ($tutor && $tutor['availability_status'] === 'online') {
            $subjects = json_decode((string) $tutor['tutor_subjects'], true) ?: [];
            // Oldest request first, so the student who has waited longest is served first.
            $statement = $database->prepare("SELECT m.id, m.subject, m.help_type, m.duration, m.note, m.created_at, u.name AS student_name FROM match_requests m JOIN users u ON u.id = m.student_id WHERE m.status = 'pending' AND m.tutor_id IS NULL AND m.student_id != ? AND m.created_at >= datetime('now', ?) AND NOT EXISTS (SELECT 1 FROM match_declines d WHERE d.request_id = m.id AND d.tutor_id = ?) ORDER BY m.id ASC");
            $statement->execute([$userId, '-' . OFFER_TTL_MINUTES . ' minutes', $userId]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (in_array($row['subject'], $subjects, true)) {
                    $requests[] = [...$row, 'created_at' => isoTime($row['created_at'])];
                }
            }
        }
        respond(200, ['requests' => $requests, 'availabilityStatus' => $tutor['availability_status'] ?? 'offline', 'now' => isoTime(gmdate('Y-m-d H:i:s'))]);
    }

    if ($action === 'accept-match-request') {
        $tutorId = requireApprovedUser($database);
        $requestId = filter_var($request['requestId'] ?? null, FILTER_VALIDATE_INT);
        if (!$requestId) respond(422, ['error' => 'A valid request is required.']);
        if (activeRoomId($database, $tutorId) !== null) {
            respond(409, ['error' => 'End your current study session before accepting another.']);
        }
        $tutorStatement = $database->prepare('SELECT role, availability_status, tutor_subjects FROM users WHERE id = ?');
        $tutorStatement->execute([$tutorId]);
        $tutorRow = $tutorStatement->fetch(PDO::FETCH_ASSOC);
        if (!$tutorRow || $tutorRow['availability_status'] !== 'online' || !in_array($tutorRow['role'], ['tutor', 'both', 'admin'], true)) {
            respond(409, ['error' => 'Switch to online before accepting requests.']);
        }
        $subjectStatement = $database->prepare('SELECT subject FROM match_requests WHERE id = ?');
        $subjectStatement->execute([$requestId]);
        $requestSubject = $subjectStatement->fetchColumn();
        if ($requestSubject === false) respond(404, ['error' => 'This request no longer exists.']);
        if (!in_array($requestSubject, json_decode((string) $tutorRow['tutor_subjects'], true) ?: [], true)) {
            respond(403, ['error' => 'This request is outside the subjects you teach.']);
        }
        $database->beginTransaction();
        try {
            // The single guarded UPDATE is what makes the first accepting tutor win.
            $statement = $database->prepare("UPDATE match_requests SET status = 'accepted', tutor_id = ? WHERE id = ? AND status = 'pending' AND tutor_id IS NULL AND student_id != ? AND created_at >= datetime('now', ?)");
            $statement->execute([$tutorId, $requestId, $tutorId, '-' . OFFER_TTL_MINUTES . ' minutes']);
            if ($statement->rowCount() === 0) {
                $database->rollBack();
                $currentStatus = $database->prepare('SELECT status FROM match_requests WHERE id = ?');
                $currentStatus->execute([$requestId]);
                respond(409, ['error' => $currentStatus->fetchColumn() === 'accepted' ? 'Another tutor accepted this student first.' : 'This request is no longer available.']);
            }
            $studentStatement = $database->prepare('SELECT student_id FROM match_requests WHERE id = ?');
            $studentStatement->execute([$requestId]);
            $studentId = (int) $studentStatement->fetchColumn();
            $database->prepare('INSERT INTO study_rooms (match_request_id, student_id, tutor_id, video_token) VALUES (?, ?, ?, ?)')->execute([$requestId, $studentId, $tutorId, bin2hex(random_bytes(12))]);
            $roomId = (int) $database->lastInsertId();
            $database->prepare('INSERT INTO room_messages (room_id, sender_id, kind, body) VALUES (?, ?, ?, ?)')->execute([$roomId, $tutorId, 'system', 'Session started. Say hello and share what you want to work on.']);
            $database->prepare("UPDATE users SET is_online = 0, availability_status = 'busy' WHERE id = ?")->execute([$tutorId]);
            $database->commit();
        } catch (Throwable $exception) {
            if ($database->inTransaction()) $database->rollBack();
            throw $exception;
        }
        respond(200, ['roomId' => $roomId]);
    }

    if ($action === 'match-request-status') {
        $studentId = requireApprovedUser($database);
        $requestId = filter_var($request['requestId'] ?? null, FILTER_VALIDATE_INT);
        if (!$requestId) respond(422, ['error' => 'A valid request is required.']);
        $match = studentRequestPayload($database, $requestId, $studentId);
        if (!$match) respond(404, ['error' => 'Match request not found.']);
        respond(200, ['request' => $match]);
    }

    if ($action === 'my-match-request') {
        $studentId = requireApprovedUser($database);
        $statement = $database->prepare("SELECT id FROM match_requests WHERE student_id = ? AND status = 'pending' AND created_at >= datetime('now', ?) ORDER BY id DESC LIMIT 1");
        $statement->execute([$studentId, '-' . OFFER_TTL_MINUTES . ' minutes']);
        $pendingId = $statement->fetchColumn();
        respond(200, ['request' => $pendingId === false ? null : studentRequestPayload($database, (int) $pendingId, $studentId)]);
    }

    if ($action === 'decline-match-request') {
        $tutorId = requireApprovedUser($database);
        $requestId = filter_var($request['requestId'] ?? null, FILTER_VALIDATE_INT);
        if (!$requestId) respond(422, ['error' => 'A valid request is required.']);
        $statement = $database->prepare("SELECT id FROM match_requests WHERE id = ? AND status = 'pending' AND tutor_id IS NULL");
        $statement->execute([$requestId]);
        if ($statement->fetchColumn() === false) respond(404, ['error' => 'This request is no longer pending.']);
        $database->prepare('INSERT OR IGNORE INTO match_declines (request_id, tutor_id) VALUES (?, ?)')->execute([$requestId, $tutorId]);
        respond(200, ['status' => 'declined']);
    }

    if ($action === 'cancel-match-request') {
        $userId = requireApprovedUser($database);
        $requestId = filter_var($request['requestId'] ?? null, FILTER_VALIDATE_INT);
        if (!$requestId) respond(422, ['error' => 'A valid request is required.']);
        $statement = $database->prepare("UPDATE match_requests SET status = 'cancelled' WHERE id = ? AND student_id = ? AND status = 'pending'");
        $statement->execute([$requestId, $userId]);
        if ($statement->rowCount() === 0) respond(404, ['error' => 'This request is no longer pending.']);
        respond(200, ['status' => 'cancelled']);
    }

    if ($action === 'my-rooms') {
        $userId = requireUserId();
        $statement = $database->prepare('SELECT r.id, r.status, r.created_at, r.ended_at, r.student_id, r.tutor_id, m.subject, m.help_type, s.name AS student_name, t.name AS tutor_name FROM study_rooms r JOIN match_requests m ON m.id = r.match_request_id JOIN users s ON s.id = r.student_id JOIN users t ON t.id = r.tutor_id WHERE r.student_id = ? OR r.tutor_id = ? ORDER BY r.id DESC LIMIT 25');
        $statement->execute([$userId, $userId]);
        $rooms = array_map(static function (array $room) use ($userId): array {
            $asTutor = (int) $room['tutor_id'] === $userId;
            return [
                'id' => (int) $room['id'],
                'status' => $room['status'],
                'role' => $asTutor ? 'tutor' : 'student',
                'subject' => $room['subject'],
                'helpType' => $room['help_type'],
                'partnerName' => $asTutor ? $room['student_name'] : $room['tutor_name'],
                'createdAt' => isoTime($room['created_at']),
                'endedAt' => isoTime($room['ended_at']),
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
        respond(200, ['rooms' => $rooms]);
    }

    if (in_array($action, ['room-details', 'room-send-message', 'room-upload-file', 'room-download-file', 'room-start-call', 'room-send-signal', 'room-get-signals', 'end-room'], true)) {
        $userId = requireUserId();
        $roomId = filter_var($request['roomId'] ?? null, FILTER_VALIDATE_INT);
        if (!$roomId) respond(422, ['error' => 'A valid room is required.']);
        $room = loadRoomForUser($database, $roomId, $userId);

        if ($action === 'room-details') {
            $afterId = max(0, (int) ($request['afterMessageId'] ?? 0));
            respond(200, ['room' => roomPayload($room, $userId), 'messages' => roomMessages($database, $roomId, $userId, $afterId), 'files' => roomFiles($database, $roomId)]);
        }

        if ($action === 'room-send-message') {
            requireActiveRoom($room);
            $body = validateText($request['body'] ?? '', 2000, 'Message');
            $database->prepare('INSERT INTO room_messages (room_id, sender_id, body) VALUES (?, ?, ?)')->execute([$roomId, $userId, $body]);
            respond(201, ['id' => (int) $database->lastInsertId()]);
        }

        if ($action === 'room-upload-file') {
            requireActiveRoom($room);
            $name = (string) ($request['name'] ?? '');
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _().-]{0,95}\.[A-Za-z0-9]{1,5}$/D', $name) || str_contains($name, '..')) {
                respond(422, ['error' => 'File names can only use letters, numbers, spaces, dashes, underscores, dots, and parentheses, and must end with an extension.']);
            }
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!array_key_exists($extension, ROOM_FILE_TYPES)) {
                respond(422, ['error' => 'That file type is not allowed. Use PDF, images, text, Office documents, or CSV.']);
            }
            $contents = base64_decode((string) ($request['data'] ?? ''), true);
            if ($contents === false || $contents === '') respond(422, ['error' => 'The file could not be read.']);
            if (strlen($contents) > MAX_ROOM_FILE_BYTES) respond(413, ['error' => 'Files can be up to 3 MB.']);
            $count = $database->prepare('SELECT COUNT(*) FROM room_files WHERE room_id = ?');
            $count->execute([$roomId]);
            if ((int) $count->fetchColumn() >= MAX_ROOM_FILES) respond(422, ['error' => 'This room has reached its file limit.']);
            $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
            if (file_put_contents(uploadDirectory() . '/' . $storedName, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Could not store the uploaded file.');
            }
            $database->beginTransaction();
            $database->prepare('INSERT INTO room_files (room_id, uploader_id, original_name, stored_name, size) VALUES (?, ?, ?, ?, ?)')->execute([$roomId, $userId, $name, $storedName, strlen($contents)]);
            $fileId = (int) $database->lastInsertId();
            $database->prepare('INSERT INTO room_messages (room_id, sender_id, kind, body, file_id) VALUES (?, ?, ?, ?, ?)')->execute([$roomId, $userId, 'file', 'shared a file: ' . $name, $fileId]);
            $database->commit();
            respond(201, ['id' => $fileId]);
        }

        if ($action === 'room-download-file') {
            $fileId = filter_var($request['fileId'] ?? null, FILTER_VALIDATE_INT);
            if (!$fileId) respond(422, ['error' => 'A valid file is required.']);
            $statement = $database->prepare('SELECT original_name, stored_name FROM room_files WHERE id = ? AND room_id = ?');
            $statement->execute([$fileId, $roomId]);
            $file = $statement->fetch(PDO::FETCH_ASSOC);
            $path = $file ? uploadDirectory() . '/' . basename($file['stored_name']) : '';
            if (!$file || !is_file($path)) respond(404, ['error' => 'File not found.']);
            $extension = strtolower(pathinfo($file['stored_name'], PATHINFO_EXTENSION));
            respond(200, ['name' => $file['original_name'], 'mime' => ROOM_FILE_TYPES[$extension] ?? 'application/octet-stream', 'data' => base64_encode((string) file_get_contents($path))]);
        }

        if ($action === 'room-start-call') {
            requireActiveRoom($room);
            if ((int) $room['tutor_id'] !== $userId) respond(403, ['error' => 'Only the tutor can start the video call.']);
            $database->prepare('DELETE FROM room_call_signals WHERE room_id = ?')->execute([$roomId]);
            $database->prepare('UPDATE study_rooms SET call_started_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$roomId]);
            if ($room['call_started_at'] === null) {
                $database->prepare('INSERT INTO room_messages (room_id, sender_id, kind, body) VALUES (?, ?, ?, ?)')->execute([$roomId, $userId, 'system', $room['tutor_name'] . ' started the video call. Open the Video call tab to join.']);
            }
            respond(200, ['started' => true]);
        }

        if ($action === 'room-send-signal') {
            requireActiveRoom($room);
            if ($room['call_started_at'] === null) respond(409, ['error' => 'The tutor has not started the call yet.']);
            $type = $request['signalType'] ?? '';
            $payload = $request['payload'] ?? null;
            if (!in_array($type, ['offer', 'answer', 'ice'], true) || !is_array($payload)) {
                respond(422, ['error' => 'Invalid call signal.']);
            }
            $isTutor = (int) $room['tutor_id'] === $userId;
            if (($type === 'offer' && !$isTutor) || ($type === 'answer' && $isTutor)) {
                respond(403, ['error' => 'Only the tutor creates the offer, and only the student answers it.']);
            }
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($encoded === false || strlen($encoded) > 20000) respond(422, ['error' => 'Call signal is too large.']);
            $database->prepare('INSERT INTO room_call_signals (room_id, sender_id, signal_type, payload) VALUES (?, ?, ?, ?)')->execute([$roomId, $userId, $type, $encoded]);
            respond(201, ['id' => (int) $database->lastInsertId()]);
        }

        if ($action === 'room-get-signals') {
            requireActiveRoom($room);
            $afterId = max(0, (int) ($request['afterId'] ?? 0));
            $statement = $database->prepare('SELECT id, signal_type, payload FROM room_call_signals WHERE room_id = ? AND sender_id != ? AND id > ? ORDER BY id ASC LIMIT 100');
            $statement->execute([$roomId, $userId, $afterId]);
            $signals = array_map(static fn(array $signal): array => [
                'id' => (int) $signal['id'],
                'type' => $signal['signal_type'],
                'payload' => json_decode($signal['payload'], true),
            ], $statement->fetchAll(PDO::FETCH_ASSOC));
            respond(200, ['signals' => $signals]);
        }

        if ($action === 'end-room') {
            if ($room['status'] === 'active') {
                $enderName = (int) $room['tutor_id'] === $userId ? $room['tutor_name'] : $room['student_name'];
                $database->beginTransaction();
                try {
                    $database->prepare("UPDATE study_rooms SET status = 'ended', ended_by = ?, ended_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'active'")->execute([$userId, $roomId]);
                    $database->prepare('INSERT INTO room_messages (room_id, sender_id, kind, body) VALUES (?, ?, ?, ?)')->execute([$roomId, $userId, 'system', $enderName . ' ended the session. This room is now read-only.']);
                    $database->prepare("UPDATE users SET is_online = 0, availability_status = 'offline' WHERE id = ? AND availability_status = 'busy'")->execute([(int) $room['tutor_id']]);
                    $database->commit();
                } catch (Throwable $exception) {
                    if ($database->inTransaction()) $database->rollBack();
                    throw $exception;
                }
            }
            respond(200, ['room' => roomPayload(loadRoomForUser($database, $roomId, $userId), $userId)]);
        }
    }

    if ($action === 'booking') {
        $userId = requireUserId();
        $tutorName = validateText($request['tutorName'] ?? '', 80, 'Tutor name');
        $mode = $request['mode'] ?? '';
        $scheduledFor = validateText($request['scheduledFor'] ?? '', 120, 'Session time');
        $status = $request['status'] ?? 'saved';
        if (!in_array($mode, ['immediate', 'plan'], true) || !in_array($status, ['waiting', 'saved', 'accepted'], true)) {
            respond(422, ['error' => 'A tutor, session mode, and time are required.']);
        }
        $statement = $database->prepare('INSERT INTO bookings (student_id, tutor_name, mode, status, scheduled_for) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$userId, $tutorName, $mode, $status, $scheduledFor]);
        respond(201, ['booking' => ['id' => (int) $database->lastInsertId()]]);
    }

    if ($action === 'message') {
        $userId = requireUserId();
        $tutorName = validateText($request['tutorName'] ?? '', 80, 'Tutor name');
        $sender = $request['sender'] ?? 'student';
        $body = validateText($request['body'] ?? '', 2000, 'Message');
        if (!in_array($sender, ['student', 'tutor'], true)) {
            respond(422, ['error' => 'A recipient and message are required.']);
        }
        $statement = $database->prepare('INSERT INTO messages (user_id, tutor_name, sender, body) VALUES (?, ?, ?, ?)');
        $statement->execute([$userId, $tutorName, $sender, $body]);
        respond(201, ['message' => ['id' => (int) $database->lastInsertId()]]);
    }

    if ($action === 'availability') {
        $userId = requireUserId();
        $scheduledFor = validateText($request['scheduledFor'] ?? '', 120, 'Availability time');
        $statement = $database->prepare('INSERT INTO availability (user_id, scheduled_for) VALUES (?, ?)');
        $statement->execute([$userId, $scheduledFor]);
        respond(201, ['availability' => ['id' => (int) $database->lastInsertId()]]);
    }

    if ($action === 'submit-feedback') {
        $userId = requireUserId();
        $category = $request['category'] ?? '';
        if (!in_array($category, ['bug', 'video', 'chat', 'files', 'suggestion', 'other'], true)) {
            respond(422, ['error' => 'Choose a feedback category.']);
        }
        $message = validateText($request['message'] ?? '', 1000, 'Feedback');
        $page = (string) ($request['page'] ?? '');
        $page = preg_match('/^[A-Za-z0-9_.\/-]{1,80}$/', $page) ? $page : null;
        $recent = $database->prepare("SELECT COUNT(*) FROM feedback_reports WHERE user_id = ? AND created_at >= datetime('now', '-1 hour')");
        $recent->execute([$userId]);
        if ((int) $recent->fetchColumn() >= 5) respond(429, ['error' => 'You have sent several reports recently. Please try again later.']);
        $roomId = filter_var($request['roomId'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($roomId !== null) {
            $member = $database->prepare('SELECT id FROM study_rooms WHERE id = ? AND (student_id = ? OR tutor_id = ?)');
            $member->execute([$roomId, $userId, $userId]);
            if ($member->fetchColumn() === false) $roomId = null;
        }
        $database->prepare('INSERT INTO feedback_reports (user_id, room_id, category, message, page) VALUES (?, ?, ?, ?, ?)')->execute([$userId, $roomId, $category, $message, $page]);
        respond(201, ['message' => 'Thanks, your report was sent to the TutorRush team.']);
    }

    if ($action === 'admin-feedback') {
        requireAdmin();
        $rows = $database->query('SELECT f.id, f.category, f.message, f.page, f.status, f.created_at, f.resolved_at, f.room_id, u.name AS user_name, u.email AS user_email, u.role AS user_role, m.subject AS room_subject FROM feedback_reports f JOIN users u ON u.id = f.user_id LEFT JOIN study_rooms r ON r.id = f.room_id LEFT JOIN match_requests m ON m.id = r.match_request_id ORDER BY f.id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
        $reports = array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'category' => $row['category'],
            'message' => $row['message'],
            'page' => $row['page'],
            'status' => $row['status'],
            'createdAt' => isoTime($row['created_at']),
            'resolvedAt' => isoTime($row['resolved_at']),
            'roomId' => $row['room_id'] === null ? null : (int) $row['room_id'],
            'roomSubject' => $row['room_subject'],
            'userName' => $row['user_name'],
            'userEmail' => $row['user_email'],
            'userRole' => $row['user_role'],
        ], $rows);
        respond(200, ['reports' => $reports]);
    }

    if ($action === 'admin-set-feedback-status') {
        requireAdmin();
        $reportId = filter_var($request['reportId'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = $request['status'] ?? '';
        if (!$reportId || !in_array($newStatus, ['open', 'resolved'], true)) respond(422, ['error' => 'A valid report and status are required.']);
        $statement = $database->prepare('UPDATE feedback_reports SET status = ?, resolved_by = ?, resolved_at = ' . ($newStatus === 'resolved' ? 'CURRENT_TIMESTAMP' : 'NULL') . ' WHERE id = ?');
        $statement->execute([$newStatus, $newStatus === 'resolved' ? (int) $_SESSION['tutorrush_user_id'] : null, $reportId]);
        if ($statement->rowCount() === 0) respond(404, ['error' => 'Report not found.']);
        respond(200, ['status' => $newStatus]);
    }

    if ($action === 'admin-dashboard') {
        requireAdmin();
        $summary = [
            'users' => (int) $database->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'bookings' => (int) $database->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
            'messages' => (int) $database->query('SELECT COUNT(*) FROM messages')->fetchColumn(),
        ];
        $recentBookings = $database->query('SELECT b.tutor_name, b.mode, b.status, b.scheduled_for, u.name AS student_name FROM bookings b JOIN users u ON u.id = b.student_id ORDER BY b.id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
        $users = array_map(static fn(array $row): array => [
            ...$row,
            'subjects' => json_decode((string) $row['tutor_subjects'], true) ?: [],
            'transcript' => (int) $row['transcript_count'] > 0,
        ], $database->query("SELECT u.id, u.name, u.email, u.role, u.approval_status, u.email_verified_at, u.created_at, u.school, u.field_of_study, u.tutor_subjects, u.application_submitted_at, (SELECT COUNT(*) FROM application_documents d WHERE d.user_id = u.id) AS document_count, (SELECT COUNT(*) FROM application_documents d WHERE d.user_id = u.id AND d.kind = 'transcript') AS transcript_count FROM users u ORDER BY u.created_at DESC, u.id DESC")->fetchAll(PDO::FETCH_ASSOC));
        respond(200, ['summary' => $summary, 'recentBookings' => $recentBookings, 'users' => $users]);
    }

    if ($action === 'admin-set-role') {
        requireAdmin();
        $userId = filter_var($request['userId'] ?? null, FILTER_VALIDATE_INT);
        $role = $request['role'] ?? '';
        if (!$userId || !in_array($role, ['student', 'tutor', 'both', 'admin'], true)) {
            respond(422, ['error' => 'A valid user and role are required.']);
        }
        if ($userId === (int) ($_SESSION['tutorrush_user_id'] ?? 0) && $role !== 'admin') {
            respond(422, ['error' => 'You cannot remove your own admin access.']);
        }
        $statement = $database->prepare('UPDATE users SET role = ? WHERE id = ?');
        $statement->execute([$role, $userId]);
        if ($statement->rowCount() === 0) {
            respond(404, ['error' => 'User not found or role is unchanged.']);
        }
        respond(200, ['message' => 'User role updated.', 'role' => $role]);
    }

    if ($action === 'my-application') {
        $userId = requireUserId();
        $statement = $database->prepare('SELECT name, email, role, approval_status, school, field_of_study, tutor_subjects, application_submitted_at FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) respond(401, ['error' => 'Please log in first.']);
        respond(200, ['application' => [
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => $row['role'],
            'approvalStatus' => $row['approval_status'],
            'school' => $row['school'] ?? '',
            'fieldOfStudy' => $row['field_of_study'] ?? '',
            'subjects' => json_decode((string) $row['tutor_subjects'], true) ?: [],
            'submittedAt' => isoTime($row['application_submitted_at']),
            'documents' => applicationDocuments($database, $userId),
            'availableSubjects' => TUTOR_SUBJECTS,
        ]]);
    }

    if ($action === 'submit-application') {
        $userId = requireUserId();
        $roleStatement = $database->prepare('SELECT role FROM users WHERE id = ?');
        $roleStatement->execute([$userId]);
        $role = $roleStatement->fetchColumn();
        $isTutor = in_array($role, ['tutor', 'both'], true);
        $school = validateText($request['school'] ?? '', 120, 'School or university');
        $fieldOfStudy = trim((string) ($request['fieldOfStudy'] ?? ''));
        if (mb_strlen($fieldOfStudy) > 120 || ($isTutor && $fieldOfStudy === '')) {
            respond(422, ['error' => 'Tell us what you study (up to 120 characters).']);
        }
        if ($isTutor) {
            $subjects = array_values(array_intersect(TUTOR_SUBJECTS, is_array($request['subjects'] ?? null) ? $request['subjects'] : []));
            if (!$subjects) respond(422, ['error' => 'Choose at least one topic you can tutor.']);
            $transcript = $database->prepare("SELECT COUNT(*) FROM application_documents WHERE user_id = ? AND kind = 'transcript'");
            $transcript->execute([$userId]);
            if ((int) $transcript->fetchColumn() === 0) respond(422, ['error' => 'Upload your transcript as a PDF before submitting.']);
            $database->prepare('UPDATE users SET school = ?, field_of_study = ?, tutor_subjects = ?, application_submitted_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$school, $fieldOfStudy, json_encode($subjects, JSON_UNESCAPED_UNICODE), $userId]);
        } else {
            $database->prepare('UPDATE users SET school = ?, field_of_study = ?, application_submitted_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$school, $fieldOfStudy ?: null, $userId]);
        }
        respond(200, ['message' => 'Application saved. We will review it and email you when you are approved.']);
    }

    if ($action === 'upload-application-document') {
        $userId = requireUserId();
        $kind = $request['kind'] ?? '';
        if (!in_array($kind, ['transcript', 'supporting'], true)) respond(422, ['error' => 'Choose a document type.']);
        $name = (string) ($request['name'] ?? '');
        if (!preg_match(SAFE_FILE_NAME_PATTERN, $name) || str_contains($name, '..')) {
            respond(422, ['error' => 'File names can only use letters, numbers, spaces, dashes, underscores, dots, and parentheses, and must end with an extension.']);
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($kind === 'transcript' ? $extension !== 'pdf' : !array_key_exists($extension, APPLICATION_FILE_TYPES)) {
            respond(422, ['error' => $kind === 'transcript' ? 'Your transcript must be a PDF.' : 'Supporting documents can be PDF, PNG, JPG, DOC, or DOCX.']);
        }
        $contents = base64_decode((string) ($request['data'] ?? ''), true);
        if ($contents === false || $contents === '') respond(422, ['error' => 'The file could not be read.']);
        if (strlen($contents) > MAX_APPLICATION_FILE_BYTES) respond(413, ['error' => 'Files can be up to 4 MB.']);
        if (!str_starts_with($contents, APPLICATION_FILE_SIGNATURES[$extension])) {
            respond(422, ['error' => "That file does not look like a real .$extension file."]);
        }
        if ($kind === 'supporting') {
            $count = $database->prepare("SELECT COUNT(*) FROM application_documents WHERE user_id = ? AND kind = 'supporting'");
            $count->execute([$userId]);
            if ((int) $count->fetchColumn() >= MAX_SUPPORTING_DOCUMENTS) respond(422, ['error' => 'You can attach up to ' . MAX_SUPPORTING_DOCUMENTS . ' supporting documents.']);
        }
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        if (file_put_contents(applicationDirectory() . '/' . $storedName, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Could not store the uploaded document.');
        }
        $replaced = [];
        if ($kind === 'transcript') {
            $old = $database->prepare("SELECT stored_name FROM application_documents WHERE user_id = ? AND kind = 'transcript'");
            $old->execute([$userId]);
            $replaced = $old->fetchAll(PDO::FETCH_COLUMN);
            $database->prepare("DELETE FROM application_documents WHERE user_id = ? AND kind = 'transcript'")->execute([$userId]);
        }
        $database->prepare('INSERT INTO application_documents (user_id, kind, original_name, stored_name, size) VALUES (?, ?, ?, ?, ?)')->execute([$userId, $kind, $name, $storedName, strlen($contents)]);
        foreach ($replaced as $oldName) {
            @unlink(applicationDirectory() . '/' . basename((string) $oldName));
        }
        respond(201, ['documents' => applicationDocuments($database, $userId)]);
    }

    if ($action === 'delete-application-document') {
        $userId = requireUserId();
        $documentId = filter_var($request['documentId'] ?? null, FILTER_VALIDATE_INT);
        if (!$documentId) respond(422, ['error' => 'A valid document is required.']);
        $statement = $database->prepare('SELECT stored_name FROM application_documents WHERE id = ? AND user_id = ?');
        $statement->execute([$documentId, $userId]);
        $storedName = $statement->fetchColumn();
        if ($storedName === false) respond(404, ['error' => 'Document not found.']);
        $database->prepare('DELETE FROM application_documents WHERE id = ?')->execute([$documentId]);
        @unlink(applicationDirectory() . '/' . basename((string) $storedName));
        respond(200, ['documents' => applicationDocuments($database, $userId)]);
    }

    if ($action === 'admin-user-details') {
        requireAdmin();
        $userId = filter_var($request['userId'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId) respond(422, ['error' => 'A valid user is required.']);
        $statement = $database->prepare('SELECT id, name, email, role, approval_status, school, field_of_study, tutor_subjects, application_submitted_at, created_at FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) respond(404, ['error' => 'User not found.']);
        respond(200, ['user' => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => $row['role'],
            'approvalStatus' => $row['approval_status'],
            'school' => $row['school'] ?? '',
            'fieldOfStudy' => $row['field_of_study'] ?? '',
            'subjects' => json_decode((string) $row['tutor_subjects'], true) ?: [],
            'submittedAt' => isoTime($row['application_submitted_at']),
            'createdAt' => isoTime($row['created_at']),
            'documents' => applicationDocuments($database, $userId),
        ]]);
    }

    if ($action === 'admin-application-document') {
        requireAdmin();
        $documentId = filter_var($request['documentId'] ?? null, FILTER_VALIDATE_INT);
        if (!$documentId) respond(422, ['error' => 'A valid document is required.']);
        $statement = $database->prepare('SELECT original_name, stored_name FROM application_documents WHERE id = ?');
        $statement->execute([$documentId]);
        $document = $statement->fetch(PDO::FETCH_ASSOC);
        $path = $document ? applicationDirectory() . '/' . basename($document['stored_name']) : '';
        if (!$document || !is_file($path)) respond(404, ['error' => 'Document not found.']);
        $extension = strtolower(pathinfo($document['stored_name'], PATHINFO_EXTENSION));
        respond(200, ['name' => $document['original_name'], 'mime' => APPLICATION_FILE_TYPES[$extension] ?? 'application/octet-stream', 'data' => base64_encode((string) file_get_contents($path))]);
    }

    if ($action === 'admin-set-approval') {
        requireAdmin();
        $approve = filter_var($request['approved'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($approve === null) respond(422, ['error' => 'Choose whether to approve or revoke access.']);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $loginUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'tutorush.com') . '/login.html';
        $notify = static fn(array $person): bool => sendPlainEmail($person['email'], $person['name'], 'Your TutorRush account is approved', "Hi {$person['name']},\n\nGood news: your TutorRush account has been approved and you are in!\nLog in here to get started:\n$loginUrl\n\nThe TutorRush team\n");
        if (!empty($request['all'])) {
            if (!$approve) respond(422, ['error' => 'Bulk revoke is not supported.']);
            set_time_limit(120);
            $pending = $database->query("SELECT id, name, email FROM users WHERE approval_status = 'pending' AND email_verified_at IS NOT NULL LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            $mark = $database->prepare("UPDATE users SET approval_status = 'approved' WHERE id = ?");
            foreach ($pending as $person) {
                $mark->execute([(int) $person['id']]);
                $notify($person);
            }
            respond(200, ['approved' => count($pending)]);
        }
        $userId = filter_var($request['userId'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId) respond(422, ['error' => 'A valid user is required.']);
        $statement = $database->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
        $statement->execute([$userId]);
        $person = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$person) respond(404, ['error' => 'User not found.']);
        if ($person['role'] === 'admin') respond(422, ['error' => 'Admins are always approved.']);
        $database->prepare('UPDATE users SET approval_status = ? WHERE id = ?')->execute([$approve ? 'approved' : 'pending', $userId]);
        if (!$approve) {
            $database->prepare("UPDATE users SET is_online = 0, availability_status = 'offline' WHERE id = ? AND availability_status = 'online'")->execute([$userId]);
        }
        $emailed = $approve ? $notify($person) : false;
        respond(200, ['approvalStatus' => $approve ? 'approved' : 'pending', 'emailed' => $emailed]);
    }

    if ($action === 'admin-delete-user') {
        requireAdmin();
        $userId = filter_var($request['userId'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId) respond(422, ['error' => 'A valid user is required.']);
        if ($userId === (int) ($_SESSION['tutorrush_user_id'] ?? 0)) {
            respond(422, ['error' => 'You cannot delete your own admin account.']);
        }
        $database->beginTransaction();
        try {
            $roomIds = $database->prepare('SELECT id FROM study_rooms WHERE student_id = ? OR tutor_id = ?');
            $roomIds->execute([$userId, $userId]);
            $storedFiles = [];
            foreach ($roomIds->fetchAll(PDO::FETCH_COLUMN) as $roomId) {
                $database->prepare('DELETE FROM room_call_signals WHERE room_id = ?')->execute([$roomId]);
                $files = $database->prepare('SELECT stored_name FROM room_files WHERE room_id = ?');
                $files->execute([$roomId]);
                $storedFiles = array_merge($storedFiles, $files->fetchAll(PDO::FETCH_COLUMN));
                foreach (['room_files', 'room_messages'] as $table) {
                    $database->prepare("DELETE FROM $table WHERE room_id = ?")->execute([$roomId]);
                }
                $database->prepare('DELETE FROM study_rooms WHERE id = ?')->execute([$roomId]);
            }
            $database->prepare('DELETE FROM room_call_signals WHERE sender_id = ? OR room_id IN (SELECT id FROM study_rooms WHERE student_id = ? OR tutor_id = ?)')->execute([$userId, $userId, $userId]);
            $database->prepare('DELETE FROM match_declines WHERE tutor_id = ? OR request_id IN (SELECT id FROM match_requests WHERE student_id = ? OR tutor_id = ?)')->execute([$userId, $userId, $userId]);
            $database->prepare('DELETE FROM match_requests WHERE student_id = ? OR tutor_id = ?')->execute([$userId, $userId]);
            $applicationFiles = $database->prepare('SELECT stored_name FROM application_documents WHERE user_id = ?');
            $applicationFiles->execute([$userId]);
            $applicationStored = $applicationFiles->fetchAll(PDO::FETCH_COLUMN);
            $database->prepare('DELETE FROM application_documents WHERE user_id = ?')->execute([$userId]);
            foreach (['password_resets' => 'user_id', 'email_verifications' => 'user_id', 'messages' => 'user_id', 'availability' => 'user_id', 'bookings' => 'student_id', 'feedback_reports' => 'user_id'] as $table => $column) {
                $statement = $database->prepare("DELETE FROM $table WHERE $column = ?");
                $statement->execute([$userId]);
            }
            $statement = $database->prepare('DELETE FROM users WHERE id = ?');
            $statement->execute([$userId]);
            if ($statement->rowCount() === 0) {
                $database->rollBack();
                respond(404, ['error' => 'User not found.']);
            }
            $database->commit();
        } catch (Throwable $exception) {
            $database->rollBack();
            throw $exception;
        }
        foreach ($storedFiles as $storedName) {
            @unlink(uploadDirectory() . '/' . basename((string) $storedName));
        }
        foreach ($applicationStored as $storedName) {
            @unlink(applicationDirectory() . '/' . basename((string) $storedName));
        }
        respond(200, ['message' => 'User deleted.']);
    }
} catch (PDOException $exception) {
    respond(500, ['error' => 'The TutorRush database is unavailable.']);
} catch (Throwable $exception) {
    respond(500, ['error' => 'The TutorRush service could not process the request.']);
}