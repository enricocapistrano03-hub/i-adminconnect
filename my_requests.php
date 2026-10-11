<?php
$page_title_custom = "<h1>My Requests</h1><p>Full history and live status tracking of your submitted requests.</p>";
require_once __DIR__ . '/includes/header.php';

$stmt = $pdo->prepare("SELECT r.*, rt.name as request_type_name 
    FROM requests r 
    JOIN request_types rt ON r.request_type_id = rt.id 
    WHERE r.user_id = ? 
    ORDER BY r.id DESC");
$stmt->execute([$user_id]);
$my_requests = $stmt->fetchAll();
?>

<div class="content-card">
  <div class="card-header-flex">
    <h2 class="card-title">All My Submitted Requests</h2>
    <a href="submit_request.php" class="btn-primary-cta" style="padding: 8px 16px; font-size: 0.85rem; text-decoration: none;">+ New Request</a>
  </div>

  <div class="table-container">
    <table class="custom-table">
      <thead>
        <tr>
          <th>Request ID</th>
          <th>Request Type</th>
          <th>Date Submitted</th>
          <th>Purpose</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($my_requests)): ?>
          <tr>
            <td colspan="6" style="text-align: center; color: #94a3b8; padding: 24px;">No requests found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($my_requests as $req): 
            $status_class = strtolower(str_replace(' ', '-', $req['status']));
            $date_str = date('F j, Y', strtotime($req['created_at']));
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($req['tracking_number']) ?></strong></td>
              <td><?= htmlspecialchars($req['request_type_name']) ?></td>
              <td><?= htmlspecialchars($date_str) ?></td>
              <td><?= htmlspecialchars(mb_strimwidth($req['purpose'], 0, 45, '...')) ?></td>
              <td><span class="badge-status <?= $status_class ?>"><?= htmlspecialchars($req['status']) ?></span></td>
              <td>
                <button type="button" class="btn-action-track" onclick="openTrackerModal('<?= htmlspecialchars($req['tracking_number'], ENT_QUOTES) ?>')">Track</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
