<?php
// Admin Dashboard & Request Management Table (Slide 15)
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$query = "SELECT r.*, u.full_name as student_or_teacher_name, u.role as requester_role, rt.name as request_type_name
          FROM requests r
          JOIN users u ON r.user_id = u.id
          JOIN request_types rt ON r.request_type_id = rt.id
          WHERE u.role = 'teacher'";

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
$all_requests = $stmt->fetchAll();
?>

<div class="content-card">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 15px; flex-wrap: wrap;">
    <form method="GET" style="flex-grow: 1; max-width: 500px; display: flex; gap: 10px;">
      <input 
        type="text" 
        name="search" 
        class="form-control" 
        placeholder="Search tracking ID, teacher name, or document..."
        value="<?= htmlspecialchars($search) ?>"
      />
      <button type="submit" class="btn-primary-cta" style="padding: 10px 18px; font-size: 0.85rem;">Filter</button>
      <?php if ($search !== '' || $status_filter !== ''): ?>
        <a href="<?= basename($_SERVER['PHP_SELF']) ?>" class="btn-primary-cta" style="background: #e2e8f0; color: #475569; padding: 10px 14px; font-size: 0.85rem; text-decoration: none;">Reset</a>
      <?php endif; ?>
    </form>

    <div style="display: flex; gap: 10px;">
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
        $export_params = ['scope' => 'faculty'];
        if ($search !== '') $export_params['search'] = $search;
        if ($status_filter !== '' && $status_filter !== 'ALL') $export_params['status'] = $status_filter;
      ?>
      <a href="export_csv.php?<?= http_build_query($export_params) ?>" class="btn-primary-cta" style="padding: 10px 18px; font-size: 0.85rem; text-decoration: none;">Export</a>
    </div>
  </div>

  <div class="table-container">
    <table class="custom-table">
      <thead>
        <tr>
          <th>Request ID</th>
          <th>Name</th>
          <th>User Type</th>
          <th>Request Type</th>
          <th>Status</th>
          <th>Date</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($all_requests)): ?>
          <tr>
            <td colspan="7" style="text-align: center; color: #94a3b8; padding: 24px;">No matching records found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($all_requests as $row): 
            $status_class = strtolower(str_replace(' ', '-', $row['status']));
            $date_str = date('F j, Y', strtotime($row['created_at']));
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($row['tracking_number']) ?></strong></td>
              <td><?= htmlspecialchars($row['student_or_teacher_name']) ?></td>
              <td><?= ucfirst($row['requester_role']) ?></td>
              <td><?= htmlspecialchars($row['request_type_name']) ?></td>
              <td><span class="badge-status <?= $status_class ?>"><?= htmlspecialchars($row['status']) ?></span></td>
              <td><?= htmlspecialchars($date_str) ?></td>
              <td>
                <a href="request_details.php?id=<?= $row['id'] ?>" class="btn-action-view" style="text-decoration: none; display: inline-block;">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
