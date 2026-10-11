<?php
require_once __DIR__ . '/config/db_conn.php';
header('Content-Type: application/json');

$tracking_number = trim($_GET['tracking_number'] ?? '');
if (empty($tracking_number)) {
    echo json_encode(['error' => 'Missing tracking number']);
    exit();
}

$stmt = $pdo->prepare("SELECT r.*, rt.name as request_type_name 
    FROM requests r 
    JOIN request_types rt ON r.request_type_id = rt.id 
    WHERE r.tracking_number = ? LIMIT 1");
$stmt->execute([$tracking_number]);
$req = $stmt->fetch();

if (!$req) {
    echo json_encode(['error' => 'Request not found']);
    exit();
}

$percent = 25;
if ($req['current_stage'] == 2) $percent = 50;
if ($req['current_stage'] == 3) $percent = 75;
if ($req['current_stage'] == 4) $percent = 100;

echo json_encode([
    'id' => $req['tracking_number'],
    'requestType' => $req['request_type_name'],
    'status' => $req['status'],
    'currentStage' => (int)$req['current_stage'],
    'percent' => $percent,
    'stage1' => $req['stage1_time'] ?: date('F j, Y g:i A', strtotime($req['created_at'])),
    'stage2' => $req['stage2_time'],
    'stage3' => $req['stage3_time'],
    'stage4' => $req['stage4_time'],
    'remarks' => $req['remarks'] ?: 'Your request is in progress.',
    'attachedFile' => $req['attached_filename'],
    'fileSize' => $req['file_size_kb'],
    'canAcknowledge' => ($req['status'] === 'Ready for Release')
]);
exit();
?>
