<?php
require_once __DIR__ . '/config/db_conn.php';

$target_role = $_GET['role'] ?? 'teacher';

$stmt = $pdo->prepare("SELECT * FROM users WHERE role = ? LIMIT 1");
$stmt->execute([$target_role]);
$user = $stmt->fetch();

if ($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
}

$referer = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
header("Location: " . $referer);
exit();
?>
