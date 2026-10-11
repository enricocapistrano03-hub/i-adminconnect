<?php
// ==============================================================================
// i-AdminConnect Attachment Viewer & Streamer
// Serves verified attachments (PDFs, Images) securely to authorized users
// ==============================================================================

require_once __DIR__ . '/config/db_conn.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    die("Access denied. Please log in to view documents.");
}

$id = (int)($_GET['id'] ?? 0);
$ref = trim($_GET['ref'] ?? '');

if ($id <= 0 && empty($ref)) {
    die("Invalid request parameters.");
}

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE tracking_number = ? LIMIT 1");
    $stmt->execute([$ref]);
}

$req = $stmt->fetch();
if (!$req || empty($req['attached_filename'])) {
    die("<div style='font-family: sans-serif; padding: 30px; text-align: center; color: #64748b;'>
        <h2>⚠️ Document Not Found</h2>
        <p>No attachment is linked to this request record.</p>
    </div>");
}

$filename = $req['attached_filename'];
$clean_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
$uploads_dir = __DIR__ . '/uploads/';

$target_path = null;

// 1. Direct file check
if (file_exists($uploads_dir . $filename)) {
    $target_path = $uploads_dir . $filename;
} elseif (file_exists($uploads_dir . $clean_name)) {
    $target_path = $uploads_dir . $clean_name;
} else {
    // 2. Pattern match in uploads directory
    $matches = glob($uploads_dir . '*' . $clean_name);
    if (!empty($matches)) {
        // Pick the most recent match
        $target_path = $matches[count($matches) - 1];
    }
}

if (!$target_path || !file_exists($target_path)) {
    die("<div style='font-family: sans-serif; padding: 30px; text-align: center; color: #64748b;'>
        <h2>⚠️ File Missing on Server</h2>
        <p>The file <strong>" . htmlspecialchars($filename) . "</strong> could not be located in the uploads directory.</p>
    </div>");
}

$ext = strtolower(pathinfo($target_path, PATHINFO_EXTENSION));
$mime_types = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'txt'  => 'text/plain',
];

$content_type = $mime_types[$ext] ?? 'application/octet-stream';

// Stream file inline to browser
header("Content-Type: " . $content_type);
header("Content-Length: " . filesize($target_path));
header("Content-Disposition: inline; filename=\"" . basename($filename) . "\"");
header("Cache-Control: private, max-age=3600");

readfile($target_path);
exit();
