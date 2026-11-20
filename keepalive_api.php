<?php
// ==============================================================================
// i-AdminConnect Session Keepalive / Heartbeat Endpoint
// Updates session last_activity timestamp during active user interaction
// ==============================================================================
require_once __DIR__ . '/config/db_conn.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'expired', 'message' => 'Session has expired']);
    exit();
}

$_SESSION['last_activity'] = time();

echo json_encode([
    'status' => 'active',
    'last_activity' => $_SESSION['last_activity'],
    'expires_in' => 15 * 60
]);
