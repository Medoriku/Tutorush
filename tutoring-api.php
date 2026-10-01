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
    ];
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
    $userTableSql = (string) $database->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if (!str_contains($userTableSql, "'tutor'")) {
        $database->exec('PRAGMA foreign_keys = OFF');
        $database->exec('ALTER TABLE users RENAME TO users_before_tutor_role');
        $database->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'student' CHECK(role IN ('student', 'tutor', 'admin')),
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
    $promoteAdmin = $database->prepare('UPDATE users SET role = \'admin\' WHERE email = ?');
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
    $database->exec('CREATE TABLE IF NOT EXISTS email_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id)
    )');
    $request = requestBody();
    $action = $request['action'] ?? '';

    if (!is_string($action) || !in_array($action, ['session', 'signup', 'verify-email', 'resend-verification', 'login', 'request-password-reset', 'reset-password', 'booking', 'message', 'availability', 'admin-dashboard', 'admin-set-role', 'admin-delete-user'], true)) {
        respond(404, ['error' => 'Unknown action.']);
    }

    if ($action === 'session') {
        $user = null;
        if (isset($_SESSION['tutorrush_user_id'])) {
            $statement = $database->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
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
        $statement = $database->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = ?');
        $statement->execute([$email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            respond(404, ['error' => 'No account exists for that email address.']);
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
        respond(200, ['message' => 'User deleted.']);
    }
} catch (PDOException $exception) {
    respond(500, ['error' => 'The TutorRush database is unavailable.']);
} catch (Throwable $exception) {
    respond(500, ['error' => 'The TutorRush service could not process the request.']);
}