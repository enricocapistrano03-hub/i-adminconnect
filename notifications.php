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

// Fetch all notifications for this user
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC");
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
    ?>
      <div class="notification-card">
        <div>
          <div class="notification-title">
            <span>✔</span> <?= htmlspecialchars($n['title']) ?>
          </div>
          <div class="notification-details">
            <?= htmlspecialchars($n['message']) ?>
            <?php if (!empty($n['location'])): ?>
              <div style="margin-top: 4px; font-weight: 600;">
                📍 <?= htmlspecialchars($n['location']) ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="notification-meta"><?= $time_str ?></div>
        </div>
        <?php if (!empty($n['request_id'])): ?>
          <?php if ($user_role === 'admin'): ?>
            <!-- Admin can view request in management/details -->
            <a href="manage_requests.php?search=<?= urlencode($n['request_id']) ?>" class="btn-primary-cta" style="padding: 8px 18px; font-size: 0.85rem; text-decoration: none; display: inline-block;">
              Review Request
            </a>
          <?php elseif ($user_role === 'registrar'): ?>
            <a href="assigned_requests.php?search=<?= urlencode($n['request_id']) ?>" class="btn-primary-cta" style="padding: 8px 18px; font-size: 0.85rem; text-decoration: none; display: inline-block;">
              Process Request
            </a>
          <?php else: ?>
            <button type="button" class="btn-primary-cta" style="padding: 8px 18px; font-size: 0.85rem; cursor: pointer;" onclick="openTrackerModal('<?= htmlspecialchars($n['request_id'], ENT_QUOTES) ?>')">
              View Request
            </button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
