<?php
require_once __DIR__ . '/../config/db_conn.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'student';
$first_name = explode(' ', $user_name)[0];

// Count unread notifications
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_count = (int)$stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>i-AdminConnect: Administrative Services and Records Tracking System</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2245%22 fill=%22%2300bfa5%22/></svg>">
</head>
<body>
<div id="app-container">
  <div id="sidebarBackdrop" class="sidebar-backdrop"></div>
  <?php include __DIR__ . '/sidebar.php'; ?>
  
  <main class="main-content">
    <header class="app-header">
      <div style="display: flex; align-items: flex-start; gap: 14px;">
        <button type="button" class="mobile-hamburger-btn" id="mobileMenuBtn" aria-label="Open navigation menu">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
          </svg>
        </button>
        <div class="header-greeting">
          <?php if (isset($page_title_custom)): ?>
            <?= $page_title_custom ?>
          <?php elseif ($user_role === 'admin'): ?>
            <h1>Administrator Dashboard</h1>
            <p>Executive overview of faculty requests, users, and institutional document workflows.</p>
          <?php elseif ($user_role === 'registrar'): ?>
            <h1>Registrar Dashboard</h1>
            <p>Operational hub for student document requests, verification, and records release.</p>
          <?php elseif ($user_role === 'teacher'): ?>
            <h1>Welcome, <?= htmlspecialchars($first_name) ?>!</h1>
            <p>Manage your faculty requests and track administrative approvals.</p>
          <?php else: ?>
            <h1>Welcome, <?= htmlspecialchars($first_name) ?>!</h1>
            <p>Manage your administrative requests and track their status.</p>
          <?php endif; ?>
        </div>
      </div>
      
      <div class="header-actions">

        <div class="bell-btn" onclick="window.location.href='notifications.php'" title="View Notifications">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#1e293b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
          </svg>
          <?php if ($unread_count > 0): ?>
            <div class="bell-badge"><?= $unread_count ?></div>
          <?php endif; ?>
        </div>

        <div class="badge-role">
          <?= ucfirst($user_role) ?>
        </div>
      </div>
    </header>
    
    <div id="view-body">
