<?php
require_once __DIR__ . '/config/db_conn.php';

// Handle Clear All Action
if (isset($_GET['action']) && $_GET['action'] === 'clear_all') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid = $_SESSION['user_id'] ?? 0;
    if ($uid > 0) {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$uid]);
    }
    header("Location: notifications.php");
    exit();
}

$user_role_check = $_SESSION['role'] ?? 'student';
if ($user_role_check === 'admin') {
    $page_title_custom = '<div class="breadcrumb-nav">Dashboard &gt; Notifications</div><h1>Administrator Notifications</h1><p>System alerts, newly submitted requests, and administrative actions.</p>';
} elseif ($user_role_check === 'registrar') {
    $page_title_custom = '<div class="breadcrumb-nav">Dashboard &gt; Notifications</div><h1>Registrar Notifications</h1><p>Assigned student requests and document processing alerts.</p>';
} else {
    $page_title_custom = '<div class="breadcrumb-nav">Dashboard &gt; Notifications</div><h1>My Notifications</h1><p>Stay updated on the status of your request.</p>';
}

require_once __DIR__ . '/includes/header.php';

// Mark unread notifications as read
$stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
$stmt->execute([$user_id]);

// Fetch all notifications for this user with live request status
$stmt = $pdo->prepare("SELECT n.*, r.status as current_request_status, r.current_stage 
    FROM notifications n 
    LEFT JOIN requests r ON n.request_id = r.tracking_number 
    WHERE n.user_id = ? 
    ORDER BY n.id DESC");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();
?>

<div class="content-card" style="max-width: 900px; margin: 0 auto;">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; gap: 12px;">
    <div>
      <h2 style="font-size: 1.35rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px; margin: 0;">
        <span style="color: #0284c7;">🔔</span> <?= $user_role === 'admin' ? 'Administrator Notifications' : ($user_role === 'registrar' ? 'Registrar Notifications' : 'My Notifications') ?>
      </h2>
      <p style="font-size: 0.88rem; color: #64748b; margin-top: 4px; margin-bottom: 0;">
        <?= $user_role === 'admin' ? 'Recent teacher requests requiring administrative attention.' : ($user_role === 'registrar' ? 'Assigned student document requests requiring processing.' : 'Stay updated on the status of your administrative requests.') ?>
      </p>
    </div>

    <div style="display: flex; align-items: center; gap: 10px;">
      <span style="background: #0284c7; color: white; font-size: 0.8rem; font-weight: 700; padding: 5px 14px; border-radius: 20px;">
        <?= count($notifications) ?> Total
      </span>
      <?php if (!empty($notifications)): ?>
        <a href="notifications.php?action=clear_all" onclick="return confirm('Clear all notifications?');" style="background: #f1f5f9; color: #64748b; font-size: 0.8rem; font-weight: 700; padding: 5px 12px; border-radius: 8px; text-decoration: none; border: 1px solid #e2e8f0; transition: all 0.15s;">
          🗑️ Clear All
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($notifications)): ?>
    <div style="text-align: center; color: #94a3b8; padding: 48px 0;">
      <div style="font-size: 2.5rem; margin-bottom: 10px;">📭</div>
      <p style="font-size: 1rem; font-weight: 600; color: #64748b; margin: 0;">No notifications found.</p>
      <p style="font-size: 0.82rem; color: #94a3b8; margin-top: 4px;">You are all caught up with your administrative updates!</p>
    </div>
  <?php else: ?>
    <?php foreach ($notifications as $n): 
      $time_str = date('F j, Y g:i A', strtotime($n['created_at']));
      $req_status = $n['current_request_status'] ?? '';
      $is_completed = ($req_status === 'Completed' || $req_status === 'Released');
      $is_ready = ($req_status === 'Ready for Release');

      // Dynamically reflect current status on legacy "in Processing" titles
      $display_title = $n['title'];
      if ($is_completed && strpos($display_title, 'in Processing') !== false) {
          $display_title = "Request " . ($n['request_id'] ?? '') . " Completed";
      } elseif ($is_ready && strpos($display_title, 'in Processing') !== false) {
          $display_title = "Request " . ($n['request_id'] ?? '') . " Ready for Release";
      }
    ?>
      <div class="notification-card" style="<?= $is_completed ? 'border-left: 4px solid #10b981;' : ($is_ready ? 'border-left: 4px solid #0284c7;' : '') ?>">
        <div>
          <div class="notification-title" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <span><?= $is_completed ? '🎉' : '✔' ?></span> <?= htmlspecialchars($display_title) ?>
            <?php if (!empty($req_status)): ?>
              <?php if ($is_completed): ?>
                <span class="badge-status" style="background: #d1fae5; color: #065f46; font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 10px;">✔ Completed</span>
              <?php elseif ($is_ready): ?>
                <span class="badge-status" style="background: #dcfce7; color: #15803d; font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 10px;">Ready for Release</span>
              <?php elseif ($req_status === 'Returned for Revision'): ?>
                <span class="badge-status" style="background: #ffedd5; color: #c2410c; font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 10px;">Revision Needed</span>
              <?php elseif ($req_status === 'Rejected'): ?>
                <span class="badge-status" style="background: #fee2e2; color: #b91c1c; font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 10px;">Rejected</span>
              <?php else: ?>
                <span class="badge-status" style="background: #e0f2fe; color: #0369a1; font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 10px;">Processing</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="notification-details">
            <?= htmlspecialchars($n['message']) ?>
            <?php if (!empty($n['location'])): ?>
              <div style="margin-top: 4px; font-weight: 600;">
                📍 <?= htmlspecialchars($n['location']) ?>
              </div>
            <?php endif; ?>
            <?php if ($is_completed && strpos($n['message'], 'Auto-set to Processing') !== false): ?>
              <div style="margin-top: 5px; color: #166534; font-size: 0.82rem; font-weight: 600;">
                🎉 Note: This document request has been processed and is officially Completed.
              </div>
            <?php endif; ?>
          </div>
          <div class="notification-meta"><?= $time_str ?></div>
        </div>
        <?php if (!empty($n['request_id'])): ?>
          <?php if ($user_role === 'admin'): ?>
            <!-- Admin can view request in management/details -->
            <a href="manage_requests.php?search=<?= urlencode($n['request_id']) ?>" class="btn-primary-cta" style="padding: 8px 18px; font-size: 0.85rem; text-decoration: none; display: inline-block; <?= $is_completed ? 'background: #10b981;' : '' ?>">
              <?= $is_completed ? 'View Completed' : 'Review Request' ?>
            </a>
          <?php elseif ($user_role === 'registrar'): ?>
            <a href="assigned_requests.php?search=<?= urlencode($n['request_id']) ?>" class="btn-primary-cta" style="padding: 8px 18px; font-size: 0.85rem; text-decoration: none; display: inline-block; <?= $is_completed ? 'background: #10b981;' : '' ?>">
              <?= $is_completed ? 'View Completed' : 'Process Request' ?>
            </a>
          <?php else: ?>
            <button type="button" class="btn-primary-cta" style="padding: 8px 18px; font-size: 0.85rem; cursor: pointer; <?= $is_completed ? 'background: #10b981;' : '' ?>" onclick="openTrackerModal('<?= htmlspecialchars($n['request_id'], ENT_QUOTES) ?>')">
              <?= $is_completed ? 'View Completed' : 'View Request' ?>
            </button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
