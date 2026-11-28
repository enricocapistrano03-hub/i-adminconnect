<?php
// Admin Dashboard & Request Management Table (Slide 15)
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$doc_filter = trim($_GET['doc'] ?? 'ALL');

// Pre-calculate live request counts per teacher/faculty document type
$teacher_doc_counts = [];
$total_all_teacher_reqs = 0;
try {
    $count_t_stmt = $pdo->query("SELECT rt.id, rt.code, rt.name, COUNT(r.id) as total_count 
        FROM request_types rt 
        LEFT JOIN requests r ON r.request_type_id = rt.id 
        LEFT JOIN users u ON r.user_id = u.id AND u.role = 'teacher'
        WHERE rt.target_role IN ('teacher', 'both')
        GROUP BY rt.id, rt.code, rt.name
        ORDER BY rt.id ASC");
    while ($dc = $count_t_stmt->fetch(PDO::FETCH_ASSOC)) {
        $teacher_doc_counts[$dc['code']] = (int)$dc['total_count'];
        $total_all_teacher_reqs += (int)$dc['total_count'];
    }
} catch (Exception $e) {}

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
if ($doc_filter !== '' && $doc_filter !== 'ALL') {
    $query .= " AND (rt.code = ? OR rt.id = ?)";
    $params[] = $doc_filter;
    $params[] = $doc_filter;
}

$query .= " ORDER BY r.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$all_requests = $stmt->fetchAll();

// URL builder preserving current filters
function build_faculty_filter_url($target_doc, $search, $status) {
    $p = [];
    if ($target_doc !== 'ALL' && !empty($target_doc)) $p['doc'] = $target_doc;
    if ($search !== '') $p['search'] = $search;
    if ($status !== '' && $status !== 'ALL') $p['status'] = $status;
    $curr_file = basename($_SERVER['PHP_SELF']);
    return $curr_file . (!empty($p) ? ('?' . http_build_query($p)) : '');
}
?>

<style>
.doc-filter-pill {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 8px 16px;
  border-radius: 20px;
  font-size: 0.84rem;
  font-weight: 600;
  text-decoration: none;
  background: #ffffff;
  color: #334155;
  border: 1.5px solid #cbd5e1;
  white-space: nowrap;
  transition: all 0.15s ease-in-out;
}
.doc-filter-pill:hover {
  background: #f8fafc;
  border-color: #94a3b8;
  color: #0f172a;
}
.doc-filter-pill.active {
  background: #0284c7;
  color: #ffffff;
  border-color: #0284c7;
  font-weight: 700;
  box-shadow: 0 3px 10px rgba(2, 132, 199, 0.3);
}
.doc-filter-pill .doc-badge {
  padding: 2px 7px;
  border-radius: 10px;
  font-size: 0.72rem;
  font-weight: 800;
  background: #f1f5f9;
  color: #475569;
}
.doc-filter-pill.active .doc-badge {
  background: rgba(255, 255, 255, 0.25);
  color: #ffffff;
}
</style>

<div class="content-card">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; gap: 15px; flex-wrap: wrap;">
    <form method="GET" style="flex-grow: 1; max-width: 500px; display: flex; gap: 10px;">
      <?php if ($doc_filter !== '' && $doc_filter !== 'ALL'): ?>
        <input type="hidden" name="doc" value="<?= htmlspecialchars($doc_filter) ?>">
      <?php endif; ?>
      <?php if ($status_filter !== '' && $status_filter !== 'ALL'): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
      <?php endif; ?>
      <input 
        type="text" 
        name="search" 
        class="form-control" 
        placeholder="Search tracking ID, teacher name, or document..." 
        value="<?= htmlspecialchars($search) ?>"
      />
      <button type="submit" class="btn-primary-cta" style="padding: 10px 18px; font-size: 0.85rem;">Filter</button>
      <?php if ($search !== '' || $status_filter !== '' || ($doc_filter !== '' && $doc_filter !== 'ALL')): ?>
        <a href="<?= basename($_SERVER['PHP_SELF']) ?>" class="btn-primary-cta" style="background: #e2e8f0; color: #475569; padding: 10px 14px; font-size: 0.85rem; text-decoration: none;">Reset</a>
      <?php endif; ?>
    </form>

    <div style="display: flex; gap: 10px;">
      <form method="GET" id="status-filter-form">
        <?php if ($search !== ''): ?>
          <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <?php endif; ?>
        <?php if ($doc_filter !== '' && $doc_filter !== 'ALL'): ?>
          <input type="hidden" name="doc" value="<?= htmlspecialchars($doc_filter) ?>">
        <?php endif; ?>
        <select name="status" class="form-control" style="width: auto;" onchange="this.form.submit()">
          <option value="ALL">All Statuses</option>
          <option value="Pending" <?= $status_filter === 'Pending' ? 'selected' : '' ?>>Pending</option>
          <option value="Under Review" <?= $status_filter === 'Under Review' ? 'selected' : '' ?>>Under Review</option>
          <option value="Returned for Revision" <?= $status_filter === 'Returned for Revision' ? 'selected' : '' ?>>Returned for Revision</option>
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
        if ($doc_filter !== '' && $doc_filter !== 'ALL') $export_params['doc'] = $doc_filter;
      ?>
      <a href="export_csv.php?<?= http_build_query($export_params) ?>" class="btn-primary-cta" style="padding: 10px 18px; font-size: 0.85rem; text-decoration: none;">Export</a>
    </div>
  </div>

  <!-- Quick Document Type Filter Bar (Horizontal Line of Pill Buttons) -->
  <div style="display: flex; gap: 8px; align-items: center; overflow-x: auto; padding: 2px 2px 14px 2px; margin-bottom: 20px; border-bottom: 1.5px solid #f1f5f9; -webkit-overflow-scrolling: touch; scrollbar-width: thin;">
    <div style="font-size: 0.82rem; font-weight: 700; color: #475569; display: flex; align-items: center; gap: 4px; margin-right: 6px; white-space: nowrap;">
      <span>📑 Document:</span>
    </div>

    <!-- All Requests Pill -->
    <a href="<?= build_faculty_filter_url('ALL', $search, $status_filter) ?>" class="doc-filter-pill <?= ($doc_filter === 'ALL' || empty($doc_filter)) ? 'active' : '' ?>">
      <span>All Requests</span>
      <span class="doc-badge"><?= $total_all_teacher_reqs ?></span>
    </a>

    <!-- Certificate of Employment Pill -->
    <a href="<?= build_faculty_filter_url('COE', $search, $status_filter) ?>" class="doc-filter-pill <?= ($doc_filter === 'COE') ? 'active' : '' ?>" title="Certificate of Employment (COE)">
      <span>COE</span>
      <span class="doc-badge"><?= (int)($teacher_doc_counts['COE'] ?? 0) ?></span>
    </a>

    <!-- Service Record Pill -->
    <a href="<?= build_faculty_filter_url('SR', $search, $status_filter) ?>" class="doc-filter-pill <?= ($doc_filter === 'SR') ? 'active' : '' ?>" title="Service Record">
      <span>Service Record</span>
      <span class="doc-badge"><?= (int)($teacher_doc_counts['SR'] ?? 0) ?></span>
    </a>

    <!-- Certificate of Rating Pill -->
    <a href="<?= build_faculty_filter_url('CR', $search, $status_filter) ?>" class="doc-filter-pill <?= ($doc_filter === 'CR') ? 'active' : '' ?>" title="Certificate of Rating">
      <span>Cert. of Rating</span>
      <span class="doc-badge"><?= (int)($teacher_doc_counts['CR'] ?? 0) ?></span>
    </a>

    <!-- Leave Credits Pill -->
    <a href="<?= build_faculty_filter_url('LC', $search, $status_filter) ?>" class="doc-filter-pill <?= ($doc_filter === 'LC') ? 'active' : '' ?>" title="Leave Credits">
      <span>Leave Credits</span>
      <span class="doc-badge"><?= (int)($teacher_doc_counts['LC'] ?? 0) ?></span>
    </a>
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
              <td>
                <?= htmlspecialchars($row['request_type_name']) ?>
                <?php if ((int)($row['copies'] ?? 1) > 1): ?>
                  <span style="font-size: 0.72rem; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; margin-left: 4px;">
                    <?= (int)$row['copies'] ?> copies
                  </span>
                <?php endif; ?>
              </td>
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
