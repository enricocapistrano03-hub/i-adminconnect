<?php
$page_title_custom = "<h1>Audit Trail & System Logs</h1><p>Comprehensive security activity and administrative transaction history.</p>";
require_once __DIR__ . '/includes/header.php';

if ($user_role !== 'admin') {
    die("Access denied.");
}

$stmt = $pdo->query("SELECT * FROM audit_logs ORDER BY id DESC");
$logs = $stmt->fetchAll();
?>

<div class="content-card">
  <div class="card-header-flex">
    <h2 class="card-title">Security & Administrative Audit Logs</h2>
    <span style="font-size: 0.85rem; color: #64748b;">Total entries: <?= count($logs) ?></span>
  </div>

  <div class="table-container">
    <table class="custom-table">
      <thead>
        <tr>
          <th>Timestamp</th>
          <th>User</th>
          <th>Role</th>
          <th>Action Performed</th>
          <th>IP Address</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td><span style="color: #64748b; font-size: 0.85rem;"><?= date('F j, Y g:i A', strtotime($l['created_at'])) ?></span></td>
            <td><strong><?= htmlspecialchars($l['user_name']) ?></strong></td>
            <td><span style="background: #e2e8f0; padding: 3px 10px; border-radius: 12px; font-size: 0.78rem; font-weight: 700;"><?= htmlspecialchars($l['role']) ?></span></td>
            <td><?= htmlspecialchars($l['action']) ?></td>
            <td><code><?= htmlspecialchars($l['ip_address']) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
