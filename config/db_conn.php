<?php
// ==============================================================================
// i-AdminConnect Database Connection Configuration (XAMPP / MySQL)
// ==============================================================================

// Set system timezone to Philippine Standard Time (PST / Asia/Manila, UTC+8)
date_default_timezone_set('Asia/Manila');

// Canonical Non-WWW Redirection on Production (Prevents 403 Forbidden / CORS / Session mismatch)
if (!empty($_SERVER['HTTP_HOST']) && strpos(strtolower($_SERVER['HTTP_HOST']), 'www.iadminconnect.com') === 0) {
    $redirect_url = 'https://iadminconnect.com' . ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . $redirect_url, true, 301);
    exit();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ------------------------------------------------------------------------------
// 15-Minute Session Inactivity Timeout Guard (900 seconds)
// Protects student and faculty data if a device is left unattended
// ------------------------------------------------------------------------------
$session_timeout_seconds = 15 * 60; // 900 seconds = 15 minutes

if (!empty($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout_seconds)) {
        // Clear session variables and destroy session
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        $current_script = basename($_SERVER['PHP_SELF'] ?? '');
        $is_api = (strpos($current_script, '_api.php') !== false || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false));

        if ($is_api) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'session_timeout', 'message' => 'Your session has expired due to 15 minutes of inactivity. Please log in again.']);
            exit();
        } elseif ($current_script !== 'login.php' && $current_script !== 'logout.php') {
            header("Location: login.php?timeout=1");
            exit();
        }
    } else {
        // Update last activity timestamp on active request
        $_SESSION['last_activity'] = time();
    }
}

// Determine database credentials based on environment (Hostinger Production vs Localhost XAMPP)
$is_hostinger = (
    (isset($_SERVER['SERVER_NAME']) && (strpos($_SERVER['SERVER_NAME'], 'iadminconnect.com') !== false || strpos($_SERVER['SERVER_NAME'], 'i-adminconnect.online') !== false || strpos($_SERVER['SERVER_NAME'], 'hostinger') !== false)) ||
    (isset($_SERVER['HTTP_HOST']) && (strpos($_SERVER['HTTP_HOST'], 'iadminconnect.com') !== false || strpos($_SERVER['HTTP_HOST'], 'i-adminconnect.online') !== false || strpos($_SERVER['HTTP_HOST'], 'hostinger') !== false))
);

if ($is_hostinger) {
    $db_host = "localhost";
    $db_user = "u840449196_usr_eMgyuJkY";
    $db_pass = "8ns|TEHkH/C";
    $db_name = "u840449196_db_eMgyuJkY";
} else {
    $db_host = "localhost";
    $db_user = "root";
    $db_pass = "";
    $db_name = "i_adminconnect_db";
}

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    // Ensure MySQL session operates on Philippine Time (UTC+8)
    $pdo->exec("SET time_zone = '+08:00'");

    // Auto-migrate schema updates if missing (e.g., copies column in requests)
    try {
        $pdo->query("SELECT copies FROM requests LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE requests ADD COLUMN copies INT NOT NULL DEFAULT 1 AFTER request_type_id");
        } catch (Exception $ex) {
            // Table may not exist yet or column concurrently added
        }
    }

    // Auto-migrate status enum to support 'Completed'
    try {
        $pdo->exec("ALTER TABLE requests MODIFY COLUMN status ENUM('Pending','Under Review','Processing','Ready for Release','Released','Completed','Returned for Revision','Rejected') NOT NULL DEFAULT 'Processing'");
    } catch (Exception $e) {
        // Silently ignore if already migrated
    }

    // Auto-migrate login_attempts column in users table
    try {
        $pdo->query("SELECT login_attempts FROM users LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN login_attempts INT NOT NULL DEFAULT 0 AFTER status");
        } catch (Exception $ex) {
            // Silently ignore if concurrently added
        }
    }

    // Auto-migrate lockout_time column in users table for temporary rate limiting (15-minute cooldown)
    try {
        $pdo->query("SELECT lockout_time FROM users LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN lockout_time INT NOT NULL DEFAULT 0 AFTER login_attempts");
        } catch (Exception $ex) {
            // Silently ignore if concurrently added
        }
    }

    // Auto-restore any accounts that were permanently set to Inactive by legacy 3-attempt lockout
    try {
        $pdo->exec("UPDATE users SET status = 'Active', login_attempts = 0, lockout_time = 0 WHERE status = 'Inactive' AND login_attempts >= 3");
    } catch (Exception $e) {
        // Silently ignore
    }

    // Auto-migrate attached_filename to TEXT to support multiple documents JSON
    try {
        $pdo->exec("ALTER TABLE requests MODIFY COLUMN attached_filename TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE requests MODIFY COLUMN file_size_kb VARCHAR(255) DEFAULT NULL");
    } catch (Exception $e) {
        // Silently ignore if already migrated
    }

    // Clean up phantom 'Document.pdf' placeholder data where no file actually exists
    try {
        $pdo->exec("UPDATE requests SET attached_filename = NULL, file_size_kb = NULL, file_verified = 0 WHERE attached_filename = 'Document.pdf'");
    } catch (Exception $e) {
        // Silently ignore if table not yet loaded
    }
} catch (PDOException $e) {
    // Helpful message if database hasn't been imported yet in phpMyAdmin
    die("<div style='font-family: sans-serif; padding: 20px; background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; margin: 40px auto; max-width: 600px; color: #991b1b;'>
        <h2 style='margin-top:0;'>⚠️ Database Connection Failed</h2>
        <p>Could not connect to MySQL database <strong>{$db_name}</strong>.</p>
        <p><strong>Remedy for XAMPP:</strong></p>
        <ol>
            <li>Make sure <strong>Apache</strong> and <strong>MySQL</strong> are started in your XAMPP Control Panel.</li>
            <li>Open <a href='http://localhost/phpmyadmin' target='_blank'>http://localhost/phpmyadmin</a> in your browser.</li>
            <li>Click <strong>Import</strong> tab and import the file: <code>database/i_adminconnect_db.sql</code>.</li>
        </ol>
        <p><small>Error details: " . htmlspecialchars($e->getMessage()) . "</small></p>
    </div>");
}

// Audit logger helper
function log_audit_action($pdo, $user_name, $role, $action) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_name, role, action, ip_address) VALUES (?, ?, ?, ?)");
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt->execute([$user_name, strtoupper($role), $action, $ip]);
    } catch (Exception $e) {
        // Silently ignore log write errors
    }
}

/**
 * Parse attachments JSON or legacy string into structured array of files
 * @param string|null $attached_filename
 * @param string|null $file_size_kb
 * @return array Array of ['name' => ..., 'file' => ..., 'size' => ...]
 */
function parse_attachments($attached_filename, $file_size_kb = '') {
    if (empty($attached_filename)) return [];
    $trimmed = trim((string)$attached_filename);
    if ($trimmed === 'Document.pdf' || $trimmed === '') return [];

    if ((str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) || 
        (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))) {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && !empty($decoded)) {
            $result = [];
            foreach ($decoded as $item) {
                if (is_array($item)) {
                    $result[] = [
                        'name' => $item['name'] ?? 'Document',
                        'file' => $item['file'] ?? ($item['name'] ?? ''),
                        'size' => $item['size'] ?? 'File'
                    ];
                } elseif (is_string($item)) {
                    $result[] = [
                        'name' => $item,
                        'file' => $item,
                        'size' => 'File'
                    ];
                }
            }
            if (!empty($result)) return $result;
        }
    }

    // Single legacy file
    return [[
        'name' => $trimmed,
        'file' => $trimmed,
        'size' => $file_size_kb ?: 'File'
    ]];
}
?>
