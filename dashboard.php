<?php
require_once __DIR__ . '/includes/header.php';

// If user is Admin, load executive overview dashboard
if ($user_role === 'admin') {
    include __DIR__ . '/includes/admin_overview_view.php';
    include __DIR__ . '/includes/footer.php';
    exit();
}

// If user is Registrar, load Registrar operational dashboard
if ($user_role === 'registrar') {
    include __DIR__ . '/includes/registrar_overview_view.php';
    include __DIR__ . '/includes/footer.php';
    exit();
}

// Fetch stats for this user
$stmt = $pdo->prepare("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status IN ('Pending', 'Under Review', 'Submitted') THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status IN ('Ready for Release', 'Released') THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected
    FROM requests WHERE user_id = ?");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();

// Fetch recent 5 requests
$stmt = $pdo->prepare("SELECT r.*, rt.name as request_type_name 
    FROM requests r 
    JOIN request_types rt ON r.request_type_id = rt.id 
    WHERE r.user_id = ? 
    ORDER BY r.id DESC LIMIT 5");
$stmt->execute([$user_id]);
$recent_requests = $stmt->fetchAll();
?>

<!-- Metric Stats Summary (Slide 8, 9) -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">TOTAL REQUESTS</div>
    <div class="stat-value"><?= (int)($stats['total'] ?? 0) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">PENDING</div>
    <div class="stat-value"><?= (int)($stats['pending'] ?? 0) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">COMPLETED</div>
    <div class="stat-value"><?= (int)($stats['completed'] ?? 0) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">REJECTED</div>
    <div class="stat-value"><?= (int)($stats['rejected'] ?? 0) ?></div>
  </div>
</div>

<!-- Recent Requests Card (Slide 8, 9) -->
<div class="content-card">
  <div class="card-header-flex">
    <h2 class="card-title">My Recent Requests</h2>
    <a href="my_requests.php" class="card-link">View all &gt;</a>
  </div>

  <div class="table-container">
    <table class="custom-table">
      <thead>
        <tr>
          <th>Request ID</th>
          <th>Request Type</th>
          <th>Date</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recent_requests)): ?>
          <tr>
            <td colspan="5" style="text-align: center; color: #94a3b8; padding: 24px;">
              No requests submitted yet. Click "Submit Request" below to begin.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($recent_requests as $req): 
            $status_class = strtolower(str_replace(' ', '-', $req['status']));
            $date_str = date('F j, Y', strtotime($req['created_at']));
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($req['tracking_number']) ?></strong></td>
              <td><?= htmlspecialchars($req['request_type_name']) ?></td>
              <td><?= htmlspecialchars($date_str) ?></td>
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

<!-- Bottom CTA Card (Slide 8, 9) -->
<div class="bottom-cta-card">
  <div class="cta-title">Administrative Request</div>
  <a href="submit_request.php" class="btn-primary-cta" style="text-decoration: none; display: inline-block;">Submit Request</a>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
