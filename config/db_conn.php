<?php
// ==============================================================================
// i-AdminConnect Database Connection Configuration (XAMPP / MySQL)
// ==============================================================================

// Set system timezone to Philippine Standard Time (PST / Asia/Manila, UTC+8)
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "i_adminconnect_db";

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    // Ensure MySQL session operates on Philippine Time (UTC+8)
    $pdo->exec("SET time_zone = '+08:00'");
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
