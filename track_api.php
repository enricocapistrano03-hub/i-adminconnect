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

$status = $req['status'];
$current_stage = (int)$req['current_stage'];

// 4-Stage Lifecycle: 1. Submitted -> 2. Processing -> 3. Ready for Release -> 4. Completed
if ($status === 'Pending') {
    $current_stage = 1;
    $percent = 25;
} elseif ($status === 'Processing' || $status === 'Under Review') {
    $current_stage = 2;
    $percent = 50;
} elseif ($status === 'Ready for Release') {
    $current_stage = 3;
    $percent = 75;
} elseif ($status === 'Completed' || $status === 'Released') {
    $current_stage = 4;
    $percent = 100;
} else {
    // Returned for Revision or Rejected
    $percent = ($current_stage >= 3) ? 75 : 50;
}

// Stage timestamps
$stage1_time = $req['stage1_time'] ?: date('F j, Y g:i A', strtotime($req['created_at']));
$stage2_time = ($current_stage >= 2) ? ($req['stage2_time'] ?: ($req['stage3_time'] ?: $stage1_time)) : null;
$stage3_time = ($current_stage >= 3) ? ($req['stage3_time'] ?: ($req['stage4_time'] ?: null)) : null;
$stage4_time = ($current_stage >= 4) ? ($req['stage4_time'] ?: date('F j, Y g:i A', strtotime($req['updated_at']))) : null;

$is_completed = ($status === 'Completed' || $status === 'Released');
$attachments = parse_attachments($req['attached_filename'] ?? '', $req['file_size_kb'] ?? '');

echo json_encode([
    'id' => $req['tracking_number'],
    'requestType' => $req['request_type_name'],
    'copies' => (int)($req['copies'] ?? 1),
    'status' => $is_completed ? 'Completed' : $req['status'],
    'currentStage' => $current_stage,
    'percent' => $percent,
    'stage1' => $stage1_time,
    'stage2' => $stage2_time,
    'stage3' => $stage3_time,
    'stage4' => $stage4_time,
    'remarks' => $req['remarks'] ?: ($is_completed ? 'Transaction successfully completed and document claimed.' : 'Your request is in progress.'),
    'attachedFiles' => $attachments,
    'attachedFile' => !empty($attachments) ? (count($attachments) > 1 ? count($attachments) . ' Attached Documents' : $attachments[0]['name']) : null,
    'fileSize' => !empty($attachments) ? (count($attachments) > 1 ? $req['file_size_kb'] : $attachments[0]['size']) : null,
    'canAcknowledge' => ($req['status'] === 'Ready for Release'),
    'canEdit' => ($req['status'] === 'Returned for Revision'),
    'isCompleted' => $is_completed
]);
exit();
?>
