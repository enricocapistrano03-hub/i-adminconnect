<?php
$page_title_custom = '<div class="breadcrumb-nav">Dashboard &gt; Assigned Requests</div><h1>Student Assigned Requests</h1><p>Review, verify, and process student document applications in real-time.</p>';
require_once __DIR__ . '/includes/header.php';

// Access control: Registrar and Admin
if ($user_role !== 'registrar' && $user_role !== 'admin') {
    die("<div class='content-card'><h2>Access Denied</h2><p>Only Registrar and Administrator accounts can access this section.</p></div>");
}

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$doc_filter = trim($_GET['doc'] ?? 'ALL');

// Pre-fetch active guidance holds into fast lookup maps (immune to SQL collation mismatch)
$active_holds_lrns = [];
$active_holds_names = [];
try {
    $holds_stmt = $pdo->query("SELECT lrn, student_name FROM disciplinary_records WHERE status = 'Active Hold'");
    if ($holds_stmt) {
        while ($h = $holds_stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($h['lrn'])) $active_holds_lrns[trim($h['lrn'])] = true;
            if (!empty($h['student_name'])) $active_holds_names[strtolower(trim($h['student_name']))] = true;
        }
    }
} catch (Exception $e) {
    // Graceful fallback if table not yet loaded
}

// Pre-calculate live request counts per student document type
$doc_counts = [];
$total_all_student_reqs = 0;
try {
    $count_stmt = $pdo->query("SELECT rt.id, rt.code, rt.name, COUNT(r.id) as total_count 
        FROM request_types rt 
        LEFT JOIN requests r ON r.request_type_id = rt.id 
        LEFT JOIN users u ON r.user_id = u.id AND u.role = 'student'
        WHERE rt.target_role IN ('student', 'both')
        GROUP BY rt.id, rt.code, rt.name
        ORDER BY rt.id ASC");
    while ($dc = $count_stmt->fetch(PDO::FETCH_ASSOC)) {
        $doc_counts[$dc['code']] = (int)$dc['total_count'];
        $total_all_student_reqs += (int)$dc['total_count'];
    }
} catch (Exception $e) {}

$query = "SELECT r.*, u.full_name as student_or_teacher_name, u.role as requester_role, u.id_number, rt.name as request_type_name, rt.code as request_type_code
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
    if ($status_filter === 'Completed' || $status_filter === 'Released') {
        $query .= " AND (r.status = 'Completed' OR r.status = 'Released')";
    } else {
        $query .= " AND r.status = ?";
        $params[] = $status_filter;
    }
}
if ($doc_filter !== '' && $doc_filter !== 'ALL') {
    $query .= " AND (rt.code = ? OR rt.id = ?)";
    $params[] = $doc_filter;
    $params[] = $doc_filter;
}

$query .= " ORDER BY r.id DESC";
$assigned_requests = [];
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $assigned_requests = $stmt->fetchAll();
} catch (Exception $e) {
    // Fallback if any column issue
    $assigned_requests = [];
}

// URL builder preserving current filters
function build_student_filter_url($target_doc, $search, $status) {
    $p = [];
    if ($target_doc !== 'ALL' && !empty($target_doc)) $p['doc'] = $target_doc;
    if ($search !== '') $p['search'] = $search;
    if ($status !== '' && $status !== 'ALL') $p['status'] = $status;
    return 'assigned_requests.php' . (!empty($p) ? ('?' . http_build_query($p)) : '');
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
        placeholder="Search tracking ID, student name, or document..." 
        value="<?= htmlspecialchars($search) ?>"
      />
      <button type="submit" class="btn-primary-cta" style="padding: 10px 18px; font-size: 0.85rem;">Filter</button>
      <?php if ($search !== '' || $status_filter !== '' || ($doc_filter !== '' && $doc_filter !== 'ALL')): ?>
        <a href="assigned_requests.php" class="btn-primary-cta" style="background: #e2e8f0; color: #475569; padding: 10px 14px; font-size: 0.85rem; text-decoration: none;">Reset</a>
      <?php endif; ?>
    </form>

    <div style="display: flex; gap: 10px; align-items: center;">
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
          <option value="Returned for Revision" <?= $status_filter === 'Returned for Revision' ? 'selected' : '' ?>>Returned for Revision</option>
          <option value="Processing" <?= $status_filter === 'Processing' ? 'selected' : '' ?>>Processing</option>
          <option value="Ready for Release" <?= $status_filter === 'Ready for Release' ? 'selected' : '' ?>>Ready for Release</option>
          <option value="Completed" <?= ($status_filter === 'Completed' || $status_filter === 'Released') ? 'selected' : '' ?>>Completed</option>
          <option value="Rejected" <?= $status_filter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
      </form>
      <?php 
        $export_params = ['scope' => 'student'];
        if ($search !== '') $export_params['search'] = $search;
        if ($status_filter !== '' && $status_filter !== 'ALL') $export_params['status'] = $status_filter;
        if ($doc_filter !== '' && $doc_filter !== 'ALL') $export_params['doc'] = $doc_filter;
      ?>
      <a href="export_csv.php?<?= http_build_query($export_params) ?>" class="btn-primary-cta" style="padding: 10px 18px; font-size: 0.85rem; text-decoration: none; background: #0f172a;">Export CSV</a>
    </div>
  </div>

  <!-- Quick Document Type Filter Bar (Horizontal Line of Pill Buttons) -->
  <div style="display: flex; gap: 8px; align-items: center; overflow-x: auto; padding: 2px 2px 14px 2px; margin-bottom: 20px; border-bottom: 1.5px solid #f1f5f9; -webkit-overflow-scrolling: touch; scrollbar-width: thin;">
    <div style="font-size: 0.82rem; font-weight: 700; color: #475569; display: flex; align-items: center; gap: 4px; margin-right: 6px; white-space: nowrap;">
      <span>📑 Document:</span>
    </div>

    <!-- All Requests Pill -->
    <a href="<?= build_student_filter_url('ALL', $search, $status_filter) ?>" class="doc-filter-pill <?= ($doc_filter === 'ALL' || empty($doc_filter)) ? 'active' : '' ?>">
      <span>All Requests</span>
      <span class="doc-badge"><?= $total_all_student_reqs ?></span>
    </a>

    <!-- Form 137 Pill -->
    <a href="<?= build_student_filter_url('F137', $search, $status_filter) ?>" class="doc-filter-pill <?= ($doc_filter === 'F137') ? 'active' : '' ?>" title="Form 137 (Transcript of Records)">
      <span>Form 137</span>
      <span class="doc-badge"><?= (int)($doc_counts['F137'] ?? 0) ?></span>
    </a>

    <!-- Good Moral Pill -->
    <a href="<?= build_student_filter_url('GMC', $search, $status_filter) ?>" class="doc-filter-pill <?= ($doc_filter === 'GMC') ? 'active' : '' ?>" title="Good Moral Certificate">
      <span>Good Moral</span>
      <span class="doc-badge"><?= (int)($doc_counts['GMC'] ?? 0) ?></span>
    </a>

    <!-- Certificate of Enrollment Pill -->
    <a href="<?= build_student_filter_url('COE_ST', $search, $status_filter) ?>" class="doc-filter-pill <?= ($doc_filter === 'COE_ST') ? 'active' : '' ?>" title="Certificate of Enrollment">
      <span>Enrollment Cert</span>
      <span class="doc-badge"><?= (int)($doc_counts['COE_ST'] ?? 0) ?></span>
    </a>

    <!-- Certificate of Grades Pill -->
    <a href="<?= build_student_filter_url('COG', $search, $status_filter) ?>" class="doc-filter-pill <?= ($doc_filter === 'COG') ? 'active' : '' ?>" title="Certificate of Grades">
      <span>Grades Cert</span>
      <span class="doc-badge"><?= (int)($doc_counts['COG'] ?? 0) ?></span>
    </a>
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
          <th style="text-align: center; min-width: 155px; white-space: nowrap;">Action</th>
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
            $req_lrn = trim($req['id_number'] ?? '');
            $req_name = strtolower(trim($req['student_or_teacher_name'] ?? ''));
            $has_hold = (!empty($req_lrn) && isset($active_holds_lrns[$req_lrn])) || (!empty($req_name) && isset($active_holds_names[$req_name]));
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($req['tracking_number']) ?></strong></td>
              <td><?= htmlspecialchars($req['student_or_teacher_name']) ?></td>
              <td><span style="font-size: 0.78rem; font-weight: 700; color: #64748b;"><?= strtoupper($req['requester_role']) ?></span></td>
              <td>
                <div style="font-weight: 600;"><?= htmlspecialchars($req['request_type_name']) ?></div>
                <div style="margin-top: 3px; display: flex; flex-wrap: wrap; gap: 4px; align-items: center;">
                  <?php if ((int)($req['copies'] ?? 1) > 1): ?>
                    <span style="font-size: 0.72rem; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px;">
                      <?= (int)$req['copies'] ?> copies
                    </span>
                  <?php endif; ?>
                  <?php if ($has_hold && (($req['request_type_code'] ?? '') === 'GMC' || stripos($req['request_type_name'], 'Good Moral') !== false)): ?>
                    <span style="background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; font-size: 0.70rem; font-weight: 800; padding: 2px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;" title="Active Disciplinary Hold - Guidance clearance required">
                      ⚠️ GUIDANCE HOLD
                    </span>
                  <?php endif; ?>
                </div>
              </td>
              <td><?= htmlspecialchars(mb_strimwidth($req['purpose'], 0, 35, '...')) ?></td>
              <td><span class="badge-status <?= $status_class ?>"><?= htmlspecialchars($req['status']) ?></span></td>
              <td style="color: #64748b; font-size: 0.82rem; white-space: nowrap;"><?= $date_str ?></td>
              <td style="text-align: center; white-space: nowrap;">
                <a href="request_details.php?id=<?= $req['id'] ?>" class="btn-primary-cta" style="padding: 8px 16px; font-size: 0.82rem; font-weight: 700; text-decoration: none; white-space: nowrap; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; box-shadow: 0 2px 6px rgba(0, 132, 255, 0.25);">
                  Review &amp; Process
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
