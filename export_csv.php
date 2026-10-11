<?php
require_once __DIR__ . '/config/db_conn.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$user_role = $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($user_role, ['admin', 'registrar'])) {
    die("Access denied.");
}

$scope = $_GET['scope'] ?? '';
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

// Registrar is strictly limited to student requests
if ($user_role === 'registrar') {
    $role_filter = 'student';
    $scope_title = 'Student';
} elseif ($scope === 'faculty' || $scope === 'teacher') {
    $role_filter = 'teacher';
    $scope_title = 'Faculty';
} elseif ($scope === 'student') {
    $role_filter = 'student';
    $scope_title = 'Student';
} else {
    // Admin default / reports: all requests
    $role_filter = null;
    $scope_title = 'All';
}

$query = "SELECT r.tracking_number, u.full_name, u.role, rt.name as request_type, r.status, r.purpose, r.created_at, r.stage4_time
          FROM requests r
          JOIN users u ON r.user_id = u.id
          JOIN request_types rt ON r.request_type_id = rt.id
          WHERE 1=1";
$params = [];

if ($role_filter !== null) {
    $query .= " AND u.role = ?";
    $params[] = $role_filter;
}

if ($search !== '') {
    $query .= " AND (r.tracking_number LIKE ? OR u.full_name LIKE ? OR rt.name LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($status_filter !== '' && $status_filter !== 'ALL') {
    $query .= " AND r.status = ?";
    $params[] = $status_filter;
}

$query .= " ORDER BY r.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "i-AdminConnect_{$scope_title}_Requests_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
// Add UTF-8 BOM so Excel on Windows displays characters properly
fputs($output, "\xEF\xBB\xBF");

fputcsv($output, ['Tracking ID', 'Requester Name', 'Role', 'Document Type', 'Purpose', 'Status', 'Date Submitted', 'Completion Date']);

foreach ($rows as $row) {
    fputcsv($output, [
        $row['tracking_number'],
        $row['full_name'],
        strtoupper($row['role']),
        $row['request_type'],
        $row['purpose'],
        $row['status'],
        date('F j, Y g:i A', strtotime($row['created_at'])),
        $row['stage4_time'] ?: ($row['status'] === 'Released' ? 'Released' : 'Pending Completion')
    ]);
}

fclose($output);
exit();
