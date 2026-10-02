<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
$smtpConfigPath = __DIR__ . '/smtp-config.php';
if (is_file($smtpConfigPath)) {
    require_once $smtpConfigPath;
}

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const ADMIN_EMAIL = 'diyoradaminovaz.m@gmail.com';

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
    $statement = $database->prepare('SELECT m.id, m.sender_id, m.kind, m.body, m.created_at, u.name AS sender_name FROM room_messages m JOIN users u ON u.id = m.sender_id WHERE m.room_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT 200');
    $statement->execute([$roomId, $afterId]);
    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'mine' => (int) $row['sender_id'] === $viewerId,
        'kind' => $row['kind'],
        'body' => $row['body'],
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
        tutor_id INTEGER NOT NULL,
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
    $roomColumns = array_column($database->query('PRAGMA table_info(study_rooms)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('call_started_at', $roomColumns, true)) {
        $database->exec('ALTER TABLE study_rooms ADD COLUMN call_started_at TEXT');
    }

    $request = requestBody();
    $action = $request['action'] ?? '';

    if (!is_string($action) || !in_array($action, ['session', 'signup', 'verify-email', 'resend-verification', 'login', 'request-password-reset', 'reset-password', 'set-tutor-status', 'set-tutor-subjects', 'find-online-tutor', 'tutor-requests', 'accept-match-request', 'decline-match-request', 'cancel-match-request', 'match-request-status', 'my-rooms', 'room-details', 'room-send-message', 'room-upload-file', 'room-download-file', 'room-start-call', 'end-room', 'booking', 'message', 'availability', 'admin-dashboard', 'admin-set-role', 'admin-delete-user'], true)) {
        respond(404, ['error' => 'Unknown action.']);
    }

    if ($action === 'session') {
        $user = null;
        if (isset($_SESSION['tutorrush_user_id'])) {
            $statement = $database->prepare('SELECT id, name, email, role, is_online, availability_status, tutor_subjects FROM users WHERE id = ?');
            $statement->execute([(int) $_SESSION['tutorrush_user_id']]);
            $user = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
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
        respond(200, ['message' => 'Your email is confirmed. You can log in now.']);
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
        try {
            $statement = $database->prepare('INSERT INTO users (name, email, password_hash, role, email_verified_at) VALUES (?, ?, ?, ?, NULL)');
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
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
        respond(201, ['message' => 'Account created. Check your email to confirm your account.']);
    }

    if ($action === 'login') {
        $email = strtolower(trim((string) ($request['email'] ?? '')));
        $password = (string) ($request['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) > 128) respond(422, ['error' => 'Provide a valid email and password.']);
        $statement = $database->prepare('SELECT id, name, email, password_hash, role, email_verified_at, availability_status FROM users WHERE email = ?');
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
        $userId = requireUserId();
        $status = filter_var($request['online'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $userStatement = $database->prepare('SELECT role FROM users WHERE id = ?');
        $userStatement->execute([$userId]);
        $role = $userStatement->fetchColumn();
        if (!in_array($role, ['tutor', 'both', 'admin'], true) || $status === null) {
            respond(403, ['error' => 'Tutor access is required.']);
        }
        if ($status && activeRoomId($database, $userId) !== null) {
            respond(409, ['error' => 'End your active study session before going online.']);
        }
        $availabilityStatus = $status ? 'online' : 'offline';
        $statement = $database->prepare('UPDATE users SET is_online = ?, availability_status = ? WHERE id = ?');
        $statement->execute([$status ? 1 : 0, $availabilityStatus, $userId]);
        respond(200, ['online' => (bool) $status, 'status' => $availabilityStatus]);
    }

    if ($action === 'set-tutor-subjects') {
        $userId = requireUserId();
        $subjects = $request['subjects'] ?? [];
        if (!is_array($subjects) || count($subjects) > 20) respond(422, ['error' => 'Choose up to 20 subjects.']);
        $subjects = array_values(array_unique(array_filter(array_map(static fn($subject) => trim((string) $subject), $subjects))));
        $statement = $database->prepare('UPDATE users SET tutor_subjects = ? WHERE id = ?');
        $statement->execute([json_encode($subjects, JSON_UNESCAPED_UNICODE), $userId]);
        respond(200, ['subjects' => $subjects]);
    }

    if ($action === 'find-online-tutor') {
        $userId = requireUserId();
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
        $statement = $database->prepare("SELECT id, name, role, tutor_subjects FROM users WHERE id != ? AND availability_status = 'online' AND role IN ('tutor', 'both') ORDER BY RANDOM()");
        $statement->execute([$userId]);
        $tutor = null;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $subjects = json_decode((string) $candidate['tutor_subjects'], true);
            if (is_array($subjects) && in_array($subject, $subjects, true)) { $tutor = $candidate; break; }
        }
        if (!$tutor) respond(404, ['error' => 'No tutors are online right now. Try again soon.']);
        $requestInsert = $database->prepare('INSERT INTO match_requests (student_id, tutor_id, subject, help_type, duration, note) VALUES (?, ?, ?, ?, ?, ?)');
        $requestInsert->execute([$userId, (int) $tutor['id'], $subject, $helpType, $duration, $note ?: null]);
        respond(200, ['tutor' => ['id' => (int) $tutor['id'], 'name' => $tutor['name'], 'role' => $tutor['role']], 'request' => ['id' => (int) $database->lastInsertId(), 'subject' => $subject, 'helpType' => $helpType, 'duration' => $duration, 'note' => $note]]);
    }

    if ($action === 'tutor-requests') {
        $userId = requireUserId();
        $statement = $database->prepare("SELECT m.id, m.subject, m.help_type, m.duration, m.note, m.status, m.created_at, u.name AS student_name FROM match_requests m JOIN users u ON u.id = m.student_id WHERE m.tutor_id = ? AND m.status = 'pending' AND m.created_at >= datetime('now', ?) ORDER BY m.id DESC");
        $statement->execute([$userId, '-' . OFFER_TTL_MINUTES . ' minutes']);
        $requests = array_map(static fn(array $row): array => [...$row, 'created_at' => isoTime($row['created_at'])], $statement->fetchAll(PDO::FETCH_ASSOC));
        respond(200, ['requests' => $requests]);
    }

    if ($action === 'accept-match-request') {
        $tutorId = requireUserId();
        $requestId = filter_var($request['requestId'] ?? null, FILTER_VALIDATE_INT);
        if (!$requestId) respond(422, ['error' => 'A valid request is required.']);
        if (activeRoomId($database, $tutorId) !== null) {
            respond(409, ['error' => 'End your current study session before accepting another.']);
        }
        $database->beginTransaction();
        try {
            $statement = $database->prepare("UPDATE match_requests SET status = 'accepted' WHERE id = ? AND tutor_id = ? AND status = 'pending' AND created_at >= datetime('now', ?)");
            $statement->execute([$requestId, $tutorId, '-' . OFFER_TTL_MINUTES . ' minutes']);
            if ($statement->rowCount() === 0) {
                $database->rollBack();
                respond(404, ['error' => 'This offer is no longer available.']);
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
        $studentId = requireUserId();
        $requestId = filter_var($request['requestId'] ?? null, FILTER_VALIDATE_INT);
        if (!$requestId) respond(422, ['error' => 'A valid request is required.']);
        $statement = $database->prepare('SELECT m.id, m.status, m.subject, m.help_type, m.duration, m.note, m.created_at, t.name AS tutor_name, r.id AS room_id FROM match_requests m JOIN users t ON t.id = m.tutor_id LEFT JOIN study_rooms r ON r.match_request_id = m.id WHERE m.id = ? AND m.student_id = ?');
        $statement->execute([$requestId, $studentId]);
        $match = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$match) respond(404, ['error' => 'Match request not found.']);
        if ($match['status'] === 'pending' && strtotime($match['created_at'] . ' UTC') < time() - OFFER_TTL_MINUTES * 60) {
            $match['status'] = 'expired';
        }
        unset($match['created_at']);
        $match['room_id'] = $match['room_id'] === null ? null : (int) $match['room_id'];
        respond(200, ['request' => $match]);
    }

    if ($action === 'decline-match-request' || $action === 'cancel-match-request') {
        $userId = requireUserId();
        $requestId = filter_var($request['requestId'] ?? null, FILTER_VALIDATE_INT);
        if (!$requestId) respond(422, ['error' => 'A valid request is required.']);
        $isDecline = $action === 'decline-match-request';
        $statement = $database->prepare('UPDATE match_requests SET status = ? WHERE id = ? AND ' . ($isDecline ? 'tutor_id' : 'student_id') . " = ? AND status = 'pending'");
        $statement->execute([$isDecline ? 'declined' : 'cancelled', $requestId, $userId]);
        if ($statement->rowCount() === 0) respond(404, ['error' => 'This request is no longer pending.']);
        respond(200, ['status' => $isDecline ? 'declined' : 'cancelled']);
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

    if (in_array($action, ['room-details', 'room-send-message', 'room-upload-file', 'room-download-file', 'room-start-call', 'end-room'], true)) {
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
            $name = trim(basename(str_replace('\\', '/', (string) ($request['name'] ?? ''))));
            $name = mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name), 0, 120);
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($name === '' || !array_key_exists($extension, ROOM_FILE_TYPES)) {
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
            $database->prepare('INSERT INTO room_messages (room_id, sender_id, kind, body) VALUES (?, ?, ?, ?)')->execute([$roomId, $userId, 'file', 'shared a file: ' . $name]);
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
            if ($room['call_started_at'] === null) {
                $database->prepare('UPDATE study_rooms SET call_started_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$roomId]);
                $database->prepare('INSERT INTO room_messages (room_id, sender_id, kind, body) VALUES (?, ?, ?, ?)')->execute([$roomId, $userId, 'system', $room['tutor_name'] . ' started the video call. Open the Video call tab to join.']);
            }
            respond(200, ['started' => true]);
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

    if ($action === 'admin-dashboard') {
        requireAdmin();
        $summary = [
            'users' => (int) $database->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'bookings' => (int) $database->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
            'messages' => (int) $database->query('SELECT COUNT(*) FROM messages')->fetchColumn(),
        ];
        $recentBookings = $database->query('SELECT b.tutor_name, b.mode, b.status, b.scheduled_for, u.name AS student_name FROM bookings b JOIN users u ON u.id = b.student_id ORDER BY b.id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
        $users = $database->query('SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
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
                $files = $database->prepare('SELECT stored_name FROM room_files WHERE room_id = ?');
                $files->execute([$roomId]);
                $storedFiles = array_merge($storedFiles, $files->fetchAll(PDO::FETCH_COLUMN));
                foreach (['room_files', 'room_messages'] as $table) {
                    $database->prepare("DELETE FROM $table WHERE room_id = ?")->execute([$roomId]);
                }
                $database->prepare('DELETE FROM study_rooms WHERE id = ?')->execute([$roomId]);
            }
            $database->prepare('DELETE FROM match_requests WHERE student_id = ? OR tutor_id = ?')->execute([$userId, $userId]);
            foreach (['password_resets' => 'user_id', 'email_verifications' => 'user_id', 'messages' => 'user_id', 'availability' => 'user_id', 'bookings' => 'student_id'] as $table => $column) {
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
        respond(200, ['message' => 'User deleted.']);
    }
} catch (PDOException $exception) {
    respond(500, ['error' => 'The TutorRush database is unavailable.']);
} catch (Throwable $exception) {
    respond(500, ['error' => 'The TutorRush service could not process the request.']);
}