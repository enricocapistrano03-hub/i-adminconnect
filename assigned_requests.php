<?php
$page_title_custom = '<div class="breadcrumb-nav">Dashboard &gt; Assigned Requests</div><h1>Student Assigned Requests</h1><p>Review, verify, and process student document applications in real-time.</p>';
require_once __DIR__ . '/includes/header.php';

// Access control: Registrar and Admin
if ($user_role !== 'registrar' && $user_role !== 'admin') {
    die("<div class='content-card'><h2>Access Denied</h2><p>Only Registrar and Administrator accounts can access this section.</p></div>");
}

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$query = "SELECT r.*, u.full_name as student_or_teacher_name, u.role as requester_role, rt.name as request_type_name
          FROM requests r
          JOIN users u ON r.user_id = u.id
          JOIN request_types rt ON r.request_type_id = rt.id
          WHERE u.role = 'student'";

$params = [];
if ($search !== '') {
    $query .= " AND (r.tracking_number LIKE ? OR u.full_name LIKE ? OR rt.name LIKE ?)";
    $term = "%{$search}%";
    $params = array_merge($params, [$term, $term, $term]);
}
if ($status_filter !== '' && $status_filter !== 'ALL') {
    $query .= " AND r.status = ?";
    $params[] = $status_filter;
}

$query .= " ORDER BY r.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$assigned_requests = $stmt->fetchAll();
?>

<div class="content-card">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; gap: 15px; flex-wrap: wrap;">
    <form method="GET" style="flex-grow: 1; max-width: 500px; display: flex; gap: 10px;">
      <input 
        type="text" 
        name="search" 
        class="form-control" 
        placeholder="Search tracking ID, student name, or document..."
        value="<?= htmlspecialchars($search) ?>"
      />
      <button type="submit" class="btn-primary-cta" style="padding: 10px 18px; font-size: 0.85rem;">Filter</button>
      <?php if ($search !== '' || $status_filter !== ''): ?>
        <a href="assigned_requests.php" class="btn-primary-cta" style="background: #e2e8f0; color: #475569; padding: 10px 14px; font-size: 0.85rem; text-decoration: none;">Reset</a>
      <?php endif; ?>
    </form>

    <div style="display: flex; gap: 10px; align-items: center;">
      <form method="GET" id="status-filter-form">
        <?php if ($search !== ''): ?>
          <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <?php endif; ?>
        <select name="status" class="form-control" style="width: auto;" onchange="this.form.submit()">
          <option value="ALL">All Statuses</option>
          <option value="Pending" <?= $status_filter === 'Pending' ? 'selected' : '' ?>>Pending</option>
          <option value="Under Review" <?= $status_filter === 'Under Review' ? 'selected' : '' ?>>Under Review</option>
          <option value="Processing" <?= $status_filter === 'Processing' ? 'selected' : '' ?>>Processing</option>
          <option value="Ready for Release" <?= $status_filter === 'Ready for Release' ? 'selected' : '' ?>>Ready for Release</option>
          <option value="Released" <?= $status_filter === 'Released' ? 'selected' : '' ?>>Released</option>
          <option value="Rejected" <?= $status_filter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
      </form>
      <?php 
        $export_params = ['scope' => 'student'];
        if ($search !== '') $export_params['search'] = $search;
        if ($status_filter !== '' && $status_filter !== 'ALL') $export_params['status'] = $status_filter;
      ?>
      <a href="export_csv.php?<?= http_build_query($export_params) ?>" class="btn-primary-cta" style="padding: 10px 18px; font-size: 0.85rem; text-decoration: none; background: #0f172a;">Export CSV</a>
    </div>
  </div>

  <div class="table-container">
    <table class="custom-table">
      <thead>
        <tr>
          <th>Tracking ID</th>
          <th>Requester</th>
          <th>Type</th>
          <th>Document Name</th>
          <th>Purpose</th>
          <th>Status</th>
          <th>Date Submitted</th>
          <th style="text-align: right;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($assigned_requests)): ?>
          <tr>
            <td colspan="8" style="text-align: center; color: #94a3b8; padding: 48px 20px;">
              <div style="font-size: 2.2rem; margin-bottom: 8px;">📭</div>
              <div style="font-weight: 700; color: #475569; font-size: 1rem;">No matching requests found</div>
              <div style="font-size: 0.85rem; color: #94a3b8; margin-top: 4px;">Adjust your search or filter criteria above.</div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($assigned_requests as $req): 
            $status_class = strtolower(str_replace(' ', '-', $req['status']));
            $date_str = date('M j, Y g:i A', strtotime($req['created_at']));
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($req['tracking_number']) ?></strong></td>
              <td><?= htmlspecialchars($req['student_or_teacher_name']) ?></td>
              <td><span style="font-size: 0.78rem; font-weight: 700; color: #64748b;"><?= strtoupper($req['requester_role']) ?></span></td>
              <td><?= htmlspecialchars($req['request_type_name']) ?></td>
              <td><?= htmlspecialchars(mb_strimwidth($req['purpose'], 0, 35, '...')) ?></td>
              <td><span class="badge-status <?= $status_class ?>"><?= htmlspecialchars($req['status']) ?></span></td>
              <td style="color: #64748b; font-size: 0.82rem;"><?= $date_str ?></td>
              <td style="text-align: right;">
                <a href="request_details.php?id=<?= $req['id'] ?>" class="btn-primary-cta" style="padding: 6px 14px; font-size: 0.82rem; text-decoration: none;">
                  Review & Process
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
