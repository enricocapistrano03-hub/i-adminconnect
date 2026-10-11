<?php
require_once __DIR__ . '/config/db_conn.php';
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'student';

if ($user_id <= 0) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$tracking_number = trim($_POST['tracking_number'] ?? $_GET['tracking_number'] ?? '');
if (empty($tracking_number)) {
    echo json_encode(['error' => 'Missing tracking number']);
    exit();
}

// Find request
$stmt = $pdo->prepare("SELECT r.*, rt.name as request_type_name FROM requests r 
    JOIN request_types rt ON r.request_type_id = rt.id 
    WHERE r.tracking_number = ? LIMIT 1");
$stmt->execute([$tracking_number]);
$req = $stmt->fetch();

if (!$req) {
    echo json_encode(['error' => 'Request not found']);
    exit();
}

// Check permission
if ($user_role === 'student' || $user_role === 'teacher') {
    if ((int)$req['user_id'] !== $user_id) {
        echo json_encode(['error' => 'You do not have permission to acknowledge this request.']);
        exit();
    }
}

if ($req['status'] !== 'Ready for Release' && $req['status'] !== 'Released') {
    echo json_encode(['error' => 'Only requests marked as Ready for Release can be acknowledged as received.']);
    exit();
}

$now_str = date('F j, Y g:i A');

// Update to Released
$stmt_up = $pdo->prepare("UPDATE requests SET status = 'Released', remarks = ?, updated_at = NOW() WHERE id = ?");
$stmt_up->execute(["Document acknowledged and claimed by requester on {$now_str}.", $req['id']]);

// Audit log
log_audit_action($pdo, $user_name, strtoupper($user_role), "Acknowledged receipt of request {$tracking_number} ({$req['request_type_name']})");

// Notify Registrars and Admins
$staff_stmt = $pdo->query("SELECT id FROM users WHERE role IN ('registrar', 'admin') AND status = 'Active'");
$notif_insert = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message, location) VALUES (?, ?, ?, ?, 'Assigned Requests Queue')");
foreach ($staff_stmt->fetchAll() as $staff) {
    $notif_insert->execute([
        $staff['id'],
        $tracking_number,
        "Request {$tracking_number} Acknowledged & Received",
        "{$user_name} has acknowledged and confirmed receipt of document request {$tracking_number}."
    ]);
}

echo json_encode([
    'success' => true,
    'message' => 'Document marked as received successfully!',
    'tracking_number' => $tracking_number,
    'status' => 'Released',
    'received_time' => $now_str
]);
exit();
