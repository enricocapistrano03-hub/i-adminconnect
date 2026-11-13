<?php
// ==============================================================================
// i-AdminConnect Database Connection Configuration (XAMPP / MySQL)
// ==============================================================================

// Set system timezone to Philippine Standard Time (PST / Asia/Manila, UTC+8)
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Determine database credentials based on environment (Hostinger Production vs Localhost XAMPP)
$is_hostinger = (
    (isset($_SERVER['SERVER_NAME']) && (strpos($_SERVER['SERVER_NAME'], 'iadminconnect.com') !== false || strpos($_SERVER['SERVER_NAME'], 'hostinger') !== false)) ||
    (isset($_SERVER['HTTP_HOST']) && (strpos($_SERVER['HTTP_HOST'], 'iadminconnect.com') !== false || strpos($_SERVER['HTTP_HOST'], 'hostinger') !== false))
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
?>
