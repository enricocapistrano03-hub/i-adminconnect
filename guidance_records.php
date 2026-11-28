<?php
$page_title_custom = '<div class="breadcrumb-nav">Dashboard &gt; Guidance &amp; Counseling</div><h1>Guidance Records &amp; Good Moral Hold List</h1><p>Registry of student disciplinary records and official Good Moral Certificate clearance holds.</p>';
require_once __DIR__ . '/includes/header.php';

// Access Control: Administrator and Registrar only
if (!in_array($user_role, ['admin', 'registrar'])) {
    header("Location: dashboard.php");
    exit();
}

$success_msg = '';
$error_msg = '';

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// -----------------------------------------------------------------------------
// 1. Handle Clear Case (Remove Hold)
// -----------------------------------------------------------------------------
if ($action === 'clear_case' && !empty($_GET['id'])) {
    $target_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM disciplinary_records WHERE id = ?");
    $stmt->execute([$target_id]);
    $rec = $stmt->fetch();

    if (!$rec) {
        $error_msg = "Disciplinary record not found.";
    } else {
        $upd = $pdo->prepare("UPDATE disciplinary_records SET status = 'Cleared', cleared_date = NOW(), cleared_by = ? WHERE id = ?");
        $upd->execute([$user_name, $target_id]);

        log_audit_action($pdo, $user_name, $user_role, "Cleared Guidance Hold for {$rec['student_name']} (LRN: {$rec['lrn']}) - Case: {$rec['case_title']}");
        $success_msg = "Guidance Hold for <strong>" . htmlspecialchars($rec['student_name']) . "</strong> (LRN: {$rec['lrn']}) has been officially <strong>Cleared</strong>! The student is now eligible to request a Good Moral Certificate.";
    }
}

// -----------------------------------------------------------------------------
// 2. Handle Re-activate Case (Reimpose Hold)
// -----------------------------------------------------------------------------
if ($action === 'hold_case' && !empty($_GET['id'])) {
    $target_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM disciplinary_records WHERE id = ?");
    $stmt->execute([$target_id]);
    $rec = $stmt->fetch();

    if (!$rec) {
        $error_msg = "Disciplinary record not found.";
    } else {
        $upd = $pdo->prepare("UPDATE disciplinary_records SET status = 'Active Hold', cleared_date = NULL, cleared_by = NULL WHERE id = ?");
        $upd->execute([$target_id]);

        log_audit_action($pdo, $user_name, $user_role, "Re-activated Guidance Hold for {$rec['student_name']} (LRN: {$rec['lrn']}) - Case: {$rec['case_title']}");
        $success_msg = "Guidance Hold for <strong>" . htmlspecialchars($rec['student_name']) . "</strong> has been <strong>Re-activated</strong>. Good Moral Certificate requests are now held.";
    }
}

// -----------------------------------------------------------------------------
// 3. Handle Delete Case
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_case') {
    $target_id = (int)($_POST['target_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM disciplinary_records WHERE id = ?");
    $stmt->execute([$target_id]);
    $rec = $stmt->fetch();

    if (!$rec) {
        $error_msg = "Record not found or already deleted.";
    } else {
        $del = $pdo->prepare("DELETE FROM disciplinary_records WHERE id = ?");
        $del->execute([$target_id]);

        log_audit_action($pdo, $user_name, $user_role, "Deleted Guidance record ID {$target_id}: {$rec['student_name']} (LRN: {$rec['lrn']}) - {$rec['case_title']}");
        $success_msg = "Record for <strong>" . htmlspecialchars($rec['student_name']) . "</strong> has been permanently removed.";
    }
}

// -----------------------------------------------------------------------------
// 4. Handle Add New Disciplinary Case / Hold
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_case') {
    $lrn = trim($_POST['lrn'] ?? '');
    $student_name = trim($_POST['student_name'] ?? '');
    $grade_section = trim($_POST['grade_section'] ?? '');
    $case_title = trim($_POST['case_title'] ?? '');
    $case_details = trim($_POST['case_details'] ?? '');
    $date_reported = trim($_POST['date_reported'] ?? date('Y-m-d'));
    $status = trim($_POST['status'] ?? 'Active Hold');

    if (empty($lrn) || empty($student_name) || empty($case_title) || empty($date_reported)) {
        $error_msg = "Please provide the Student LRN, Full Name, Case Title, and Date Reported.";
    } elseif (!preg_match('/^[0-9]+$/', $lrn)) {
        $error_msg = "Student LRN must contain numeric digits only (letters and symbols are not allowed).";
    } elseif (!preg_match("/^[a-zA-Z\sñÑ.'-]+$/u", $student_name)) {
        $error_msg = "Student Name must contain letters and spaces only.";
    } elseif (!in_array($status, ['Active Hold', 'Cleared'])) {
        $error_msg = "Invalid case status selected.";
    } else {
        $cleared_date = ($status === 'Cleared') ? date('Y-m-d H:i:s') : null;
        $cleared_by = ($status === 'Cleared') ? $user_name : null;

        $ins = $pdo->prepare("INSERT INTO disciplinary_records 
            (lrn, student_name, grade_section, case_title, case_details, date_reported, status, cleared_date, cleared_by, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $lrn,
            $student_name,
            $grade_section,
            $case_title,
            $case_details,
            $date_reported,
            $status,
            $cleared_date,
            $cleared_by,
            $user_name
        ]);

        $status_label = ($status === 'Active Hold') ? 'Active Hold (Good Moral Restricted)' : 'Cleared';
        log_audit_action($pdo, $user_name, $user_role, "Logged Guidance Record for {$student_name} (LRN: {$lrn}) - Status: {$status_label}");
        $success_msg = "Disciplinary record for <strong>" . htmlspecialchars($student_name) . "</strong> (LRN: {$lrn}) was successfully recorded!";
    }
}

// -----------------------------------------------------------------------------
// 5. Handle Edit Case
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_case') {
    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $lrn = trim($_POST['lrn'] ?? '');
    $student_name = trim($_POST['student_name'] ?? '');
    $grade_section = trim($_POST['grade_section'] ?? '');
    $case_title = trim($_POST['case_title'] ?? '');
    $case_details = trim($_POST['case_details'] ?? '');
    $date_reported = trim($_POST['date_reported'] ?? date('Y-m-d'));
    $status = trim($_POST['status'] ?? 'Active Hold');

    if ($edit_id <= 0 || empty($lrn) || empty($student_name) || empty($case_title)) {
        $error_msg = "Please fill in all required fields.";
    } elseif (!preg_match('/^[0-9]+$/', $lrn)) {
        $error_msg = "Student LRN must contain numbers only.";
    } elseif (!preg_match("/^[a-zA-Z\sñÑ.'-]+$/u", $student_name)) {
        $error_msg = "Student Name must contain letters and spaces only.";
    } else {
        // Fetch previous status
        $prev_stmt = $pdo->prepare("SELECT status FROM disciplinary_records WHERE id = ?");
        $prev_stmt->execute([$edit_id]);
        $prev_status = $prev_stmt->fetchColumn();

        $cleared_sql = "";
        $params = [$lrn, $student_name, $grade_section, $case_title, $case_details, $date_reported, $status];

        if ($status === 'Cleared' && $prev_status !== 'Cleared') {
            $cleared_sql = ", cleared_date = NOW(), cleared_by = ?";
            $params[] = $user_name;
        } elseif ($status === 'Active Hold') {
            $cleared_sql = ", cleared_date = NULL, cleared_by = NULL";
        }

        $params[] = $edit_id;

        $upd = $pdo->prepare("UPDATE disciplinary_records SET 
            lrn = ?, 
            student_name = ?, 
            grade_section = ?, 
            case_title = ?, 
            case_details = ?, 
            date_reported = ?, 
            status = ?{$cleared_sql} 
            WHERE id = ?");
        $upd->execute($params);

        log_audit_action($pdo, $user_name, $user_role, "Updated Guidance Record ID {$edit_id} for {$student_name} (Status: {$status})");
        $success_msg = "Disciplinary record for <strong>" . htmlspecialchars($student_name) . "</strong> has been updated!";
    }
}

// -----------------------------------------------------------------------------
// Filters and Query Setup
// -----------------------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status_filter'] ?? 'ALL');

$where_clauses = ["1=1"];
$query_params = [];

if ($search !== '') {
    $where_clauses[] = "(lrn LIKE ? OR student_name LIKE ? OR grade_section LIKE ? OR case_title LIKE ?)";
    $like_term = "%{$search}%";
    $query_params = array_merge($query_params, [$like_term, $like_term, $like_term, $like_term]);
}

if ($status_filter !== '' && $status_filter !== 'ALL') {
    $where_clauses[] = "status = ?";
    $query_params[] = $status_filter;
}

$where_sql = implode(' AND ', $where_clauses);

// KPI Counters
$total_all = (int)$pdo->query("SELECT COUNT(*) FROM disciplinary_records")->fetchColumn();
$total_active = (int)$pdo->query("SELECT COUNT(*) FROM disciplinary_records WHERE status = 'Active Hold'")->fetchColumn();
$total_cleared = (int)$pdo->query("SELECT COUNT(*) FROM disciplinary_records WHERE status = 'Cleared'")->fetchColumn();

// Fetch Registered Students for quick selection in modal
$student_users = $pdo->query("SELECT id_number, full_name, section, year_level FROM users WHERE role = 'student' ORDER BY full_name ASC LIMIT 250")->fetchAll();

// Main List Query
$sql = "SELECT * FROM disciplinary_records WHERE {$where_sql} ORDER BY (status = 'Active Hold') DESC, id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($query_params);
$cases = $stmt->fetchAll();
?>

<div class="content-card">
  <?php if (!empty($success_msg)): ?>
    <div style="background: #dcfce7; border: 1.5px solid #86efac; color: #166534; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 0.92rem; display: flex; align-items: center; gap: 10px;">
      <span style="font-size: 1.3rem;">✅</span>
      <div><?= $success_msg ?></div>
    </div>
  <?php endif; ?>

  <?php if (!empty($error_msg)): ?>
    <div style="background: #fee2e2; border: 1.5px solid #fca5a5; color: #b91c1c; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 0.92rem; display: flex; align-items: center; gap: 10px;">
      <span style="font-size: 1.3rem;">❌</span>
      <div><strong>Error:</strong> <?= htmlspecialchars($error_msg) ?></div>
    </div>
  <?php endif; ?>

  <!-- Guidance Header & KPI Cards -->
  <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 22px; flex-wrap: wrap; gap: 16px;">
    <div>
      <h2 class="card-title" style="margin-bottom: 4px; display: flex; align-items: center; gap: 10px;">
        <span>🛡️ Guidance Disciplinary Clearance &amp; Hold Registry</span>
      </h2>
      <p style="font-size: 0.88rem; color: #64748b; margin: 0; max-width: 650px;">
        Students listed with an <strong>Active Hold</strong> will be automatically restricted from receiving Good Moral Certificates until formally cleared by the Guidance and Counseling Office.
      </p>
    </div>

    <button type="button" class="btn-primary-cta" onclick="openAddCaseModal()" style="background: #16a34a; padding: 9px 20px; font-size: 0.88rem; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; border-radius: 9px; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);">
      <span style="font-size: 1.1rem;">➕</span> <span>Add Disciplinary Record</span>
    </button>
  </div>

  <!-- Summary Metric Badges -->
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-bottom: 24px;">
    <div style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.02);">
      <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Total Cases Logged</div>
      <div style="font-size: 1.6rem; font-weight: 800; color: #0f172a; margin-top: 4px;"><?= number_format($total_all) ?></div>
      <small style="color: #64748b; font-size: 0.75rem;">Institutional records</small>
    </div>

    <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 12px; padding: 16px;">
      <div style="font-size: 0.8rem; font-weight: 700; color: #991b1b; text-transform: uppercase; display: flex; align-items: center; gap: 6px;">
        <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #dc2626;"></span>
        Active Clearance Holds
      </div>
      <div style="font-size: 1.6rem; font-weight: 800; color: #dc2626; margin-top: 4px;"><?= number_format($total_active) ?></div>
      <small style="color: #b91c1c; font-size: 0.75rem; font-weight: 600;">⚠️ Good Moral Certificate Restricted</small>
    </div>

    <div style="background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 12px; padding: 16px;">
      <div style="font-size: 0.8rem; font-weight: 700; color: #166534; text-transform: uppercase; display: flex; align-items: center; gap: 6px;">
        <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #16a34a;"></span>
        Cleared / Resolved Cases
      </div>
      <div style="font-size: 1.6rem; font-weight: 800; color: #16a34a; margin-top: 4px;"><?= number_format($total_cleared) ?></div>
      <small style="color: #15803d; font-size: 0.75rem; font-weight: 600;">✔ Good Moral Issuance Allowed</small>
    </div>
  </div>

  <!-- Search & Filter Controls -->
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 12px; flex-wrap: wrap;">
    <form method="GET" style="display: flex; gap: 10px; flex-grow: 1; max-width: 480px;">
      <input 
        type="text" 
        name="search" 
        class="form-control" 
        placeholder="Search Student LRN, Name, Section, Case..." 
        value="<?= htmlspecialchars($search) ?>"
      />
      <button type="submit" class="btn-primary-cta" style="padding: 10px 18px; font-size: 0.85rem;">Search</button>
      <?php if ($search !== '' || $status_filter !== 'ALL'): ?>
        <a href="guidance_records.php" class="btn-primary-cta" style="background: #e2e8f0; color: #475569; padding: 10px 14px; font-size: 0.85rem; text-decoration: none;">Reset</a>
      <?php endif; ?>
    </form>

    <form method="GET" style="display: flex; align-items: center; gap: 8px;">
      <?php if ($search !== ''): ?>
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
      <?php endif; ?>
      <label style="font-size: 0.85rem; font-weight: 600; color: #475569;">Filter Status:</label>
      <select name="status_filter" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="ALL">All Records</option>
        <option value="Active Hold" <?= $status_filter === 'Active Hold' ? 'selected' : '' ?>>🔴 Active Holds (Restricted)</option>
        <option value="Cleared" <?= $status_filter === 'Cleared' ? 'selected' : '' ?>>🟢 Cleared (Resolved)</option>
      </select>
    </form>
  </div>

  <!-- Disciplinary Cases Table -->
  <div class="table-container">
    <table class="custom-table">
      <thead>
        <tr>
          <th>Student LRN</th>
          <th>Student Full Name</th>
          <th>Grade &amp; Section</th>
          <th>Disciplinary Case Title</th>
          <th>Date Reported</th>
          <th>Clearance Status</th>
          <th style="text-align: right; min-width: 170px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($cases)): ?>
          <tr>
            <td colspan="7" style="text-align: center; color: #94a3b8; padding: 35px 20px;">
              <span style="font-size: 2rem; display: block; margin-bottom: 8px;">🛡️</span>
              No guidance disciplinary records found matching the criteria.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($cases as $c): 
            $is_hold = ($c['status'] === 'Active Hold');
            $case_payload = [
              'id' => $c['id'],
              'lrn' => $c['lrn'],
              'student_name' => $c['student_name'],
              'grade_section' => $c['grade_section'] ?? '',
              'case_title' => $c['case_title'],
              'case_details' => $c['case_details'] ?? '',
              'date_reported' => $c['date_reported'],
              'status' => $c['status']
            ];
          ?>
            <tr style="<?= $is_hold ? 'background: #fffafa;' : '' ?>">
              <td>
                <code style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($c['lrn']) ?></code>
              </td>
              <td>
                <strong><?= htmlspecialchars($c['student_name']) ?></strong>
              </td>
              <td><?= htmlspecialchars($c['grade_section'] ?: '—') ?></td>
              <td>
                <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($c['case_title']) ?></div>
                <?php if (!empty($c['case_details'])): ?>
                  <small style="color: #64748b; font-size: 0.76rem; display: block; max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($c['case_details']) ?>">
                    <?= htmlspecialchars($c['case_details']) ?>
                  </small>
                <?php endif; ?>
              </td>
              <td><?= date('M d, Y', strtotime($c['date_reported'])) ?></td>
              <td>
                <?php if ($is_hold): ?>
                  <span class="badge-status" style="background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; font-weight: 800; font-size: 0.78rem;" title="Good Moral Certificate is locked and held for this student">
                    🔴 ACTIVE HOLD
                  </span>
                <?php else: ?>
                  <span class="badge-status" style="background: #dcfce7; color: #15803d; border: 1px solid #86efac; font-weight: 700; font-size: 0.78rem;" title="Cleared on <?= htmlspecialchars($c['cleared_date'] ?? '') ?> by <?= htmlspecialchars($c['cleared_by'] ?? 'Guidance') ?>">
                    🟢 CLEARED
                  </span>
                <?php endif; ?>
              </td>
              <td style="text-align: right; white-space: nowrap;">
                <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                  <?php if ($is_hold): ?>
                    <!-- Clear Case Button -->
                    <a 
                      href="guidance_records.php?action=clear_case&id=<?= $c['id'] ?>" 
                      class="btn-user-action" 
                      style="background: #dcfce7; color: #166534; border: 1.5px solid #86efac; font-weight: 700; text-decoration: none; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem;" 
                      title="Clear disciplinary hold and allow Good Moral Certificate issuance" 
                      onclick="return confirm('Clear guidance hold for <?= htmlspecialchars(addslashes($c['student_name'])) ?>?\n\nThis will remove the restriction and allow the Registrar to issue a Good Moral Certificate.');"
                    >
                      ✔ Clear Hold
                    </a>
                  <?php else: ?>
                    <!-- Reimpose Hold Button -->
                    <a 
                      href="guidance_records.php?action=hold_case&id=<?= $c['id'] ?>" 
                      class="btn-user-action" 
                      style="background: #fef2f2; color: #b91c1c; border: 1.5px solid #fecaca; font-weight: 600; text-decoration: none; padding: 4px 8px; border-radius: 6px; font-size: 0.8rem;" 
                      title="Re-activate disciplinary hold" 
                      onclick="return confirm('Re-activate guidance hold for <?= htmlspecialchars(addslashes($c['student_name'])) ?>?');"
                    >
                      ⛔ Put on Hold
                    </a>
                  <?php endif; ?>

                  <!-- Edit Case -->
                  <button 
                    type="button" 
                    class="btn-user-action edit" 
                    onclick="openEditCaseModal(<?= htmlspecialchars(json_encode($case_payload), ENT_QUOTES) ?>)" 
                    title="Edit record details"
                  >
                    ✏️ Edit
                  </button>

                  <!-- Delete Case -->
                  <form method="POST" action="guidance_records.php" style="display: inline; margin: 0;" onsubmit="return confirm('Delete disciplinary record for <?= htmlspecialchars(addslashes($c['student_name']), ENT_QUOTES) ?>?');">
                    <input type="hidden" name="action" value="delete_case">
                    <input type="hidden" name="target_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="btn-user-action delete" title="Remove record">
                      🗑️
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============================================================================== -->
<!-- ADD DISCIPLINARY RECORD MODAL                                                 -->
<!-- ============================================================================== -->
<div id="addCaseModal" class="user-modal-overlay">
  <div class="user-modal-card" style="max-width: 580px;">
    <div class="user-modal-header">
      <div style="display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 1.4rem;">🛡️</span>
        <div>
          <h3 style="margin: 0; font-size: 1.15rem; color: #0f172a; font-weight: 800;">Add Disciplinary Record / Hold</h3>
          <p style="margin: 2px 0 0 0; font-size: 0.8rem; color: #64748b;">Record case from Guidance Office to safeguard Good Moral Certificate issuance</p>
        </div>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeAddCaseModal()">&times;</button>
    </div>

    <form method="POST" action="guidance_records.php">
      <input type="hidden" name="action" value="add_case">

      <div class="user-modal-body">
        <!-- Quick Student Selector from System Database -->
        <?php if (!empty($student_users)): ?>
          <div class="form-group" style="margin-bottom: 12px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 10px 12px;">
            <label class="form-label" style="font-size: 0.78rem; font-weight: 700; color: #475569; margin-bottom: 4px;">
              ⚡ Quick Select Existing Student (Optional Lookup)
            </label>
            <select class="form-control" style="font-size: 0.85rem;" onchange="populateStudentFields(this)">
              <option value="">-- Choose registered student to auto-fill LRN &amp; Name --</option>
              <?php foreach ($student_users as $stu): ?>
                <option 
                  value="<?= htmlspecialchars($stu['id_number'] ?: '') ?>" 
                  data-name="<?= htmlspecialchars($stu['full_name']) ?>" 
                  data-sec="<?= htmlspecialchars(trim(($stu['year_level'] ?? '') . ' ' . ($stu['section'] ?? ''))) ?>"
                >
                  <?= htmlspecialchars($stu['full_name']) ?> (LRN: <?= htmlspecialchars($stu['id_number'] ?: 'No LRN') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
          <div class="form-group">
            <label class="form-label">Student LRN (12 Digits) <span style="color: #ef4444;">*</span></label>
            <input 
              type="text" 
              name="lrn" 
              id="add_case_lrn" 
              class="form-control" 
              required 
              maxlength="12"
              placeholder="e.g. 136887180061" 
              oninput="this.value = this.value.replace(/[^0-9]/g, '');"
            >
            <small style="color: #64748b; font-size: 0.73rem;">Numbers only (0-9).</small>
          </div>

          <div class="form-group">
            <label class="form-label">Grade Level &amp; Section</label>
            <input 
              type="text" 
              name="grade_section" 
              id="add_case_section" 
              class="form-control" 
              placeholder="e.g. Grade 10 - Einstein"
            >
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 12px;">
          <label class="form-label">Student Full Name <span style="color: #ef4444;">*</span></label>
          <input 
            type="text" 
            name="student_name" 
            id="add_case_name" 
            class="form-control" 
            required 
            placeholder="e.g. Juan M. Dela Cruz"
            oninput="this.value = this.value.replace(/[^a-zA-Z\sñÑ.'-]/g, '');"
          >
        </div>

        <div class="form-group" style="margin-bottom: 12px;">
          <label class="form-label">Disciplinary Offense / Case Title <span style="color: #ef4444;">*</span></label>
          <input 
            type="text" 
            name="case_title" 
            id="add_case_title" 
            list="case_title_presets"
            class="form-control" 
            required 
            placeholder="Select preset or type offense title..."
          >
          <datalist id="case_title_presets">
            <option value="Campus Misconduct & Property Disturbance">
            <option value="Bullying / Cyberbullying Incident">
            <option value="Repeated Truancy / Unexcused Cutting Classes">
            <option value="Academic Dishonesty / Unauthorized Materials">
            <option value="Vandalism & Damage to School Property">
            <option value="Disrespectful Conduct toward Faculty / Staff">
            <option value="Physical Altercation / Campus Fighting">
            <option value="Violation of School Code of Conduct">
            <option value="Pending Guidance Counseling Compliance">
          </datalist>
        </div>

        <div class="form-group" style="margin-bottom: 12px;">
          <label class="form-label">Case Details / Guidance Office Narrative</label>
          <textarea 
            name="case_details" 
            id="add_case_details" 
            class="form-control" 
            rows="3" 
            placeholder="Brief narrative of the case, sanctions imposed, or conditions for clearance..."
            style="resize: vertical; font-size: 0.88rem;"
          ></textarea>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
          <div class="form-group">
            <label class="form-label">Date Reported <span style="color: #ef4444;">*</span></label>
            <input 
              type="date" 
              name="date_reported" 
              id="add_case_date" 
              class="form-control" 
              required 
              value="<?= date('Y-m-d') ?>"
            >
          </div>

          <div class="form-group">
            <label class="form-label">Clearance Status <span style="color: #ef4444;">*</span></label>
            <select name="status" id="add_case_status" class="form-control" required>
              <option value="Active Hold" selected>🔴 Active Hold (Restricted)</option>
              <option value="Cleared">🟢 Cleared (No Hold)</option>
            </select>
          </div>
        </div>
      </div>

      <div class="user-modal-footer">
        <button type="button" class="btn-primary-cta" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; box-shadow: none;" onclick="closeAddCaseModal()">Cancel</button>
        <button type="submit" class="btn-primary-cta" style="background: #16a34a;">Save Record</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================================== -->
<!-- EDIT DISCIPLINARY RECORD MODAL                                                -->
<!-- ============================================================================== -->
<div id="editCaseModal" class="user-modal-overlay">
  <div class="user-modal-card" style="max-width: 580px;">
    <div class="user-modal-header">
      <div style="display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 1.4rem;">✏️</span>
        <div>
          <h3 style="margin: 0; font-size: 1.15rem; color: #0f172a; font-weight: 800;">Edit Disciplinary Record</h3>
          <p style="margin: 2px 0 0 0; font-size: 0.8rem; color: #64748b;">Update student case details or clearance status</p>
        </div>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeEditCaseModal()">&times;</button>
    </div>

    <form method="POST" action="guidance_records.php">
      <input type="hidden" name="action" value="edit_case">
      <input type="hidden" name="edit_id" id="modal_edit_id">

      <div class="user-modal-body">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
          <div class="form-group">
            <label class="form-label">Student LRN (12 Digits) <span style="color: #ef4444;">*</span></label>
            <input 
              type="text" 
              name="lrn" 
              id="modal_edit_lrn" 
              class="form-control" 
              required 
              maxlength="12"
              oninput="this.value = this.value.replace(/[^0-9]/g, '');"
            >
          </div>

          <div class="form-group">
            <label class="form-label">Grade Level &amp; Section</label>
            <input 
              type="text" 
              name="grade_section" 
              id="modal_edit_section" 
              class="form-control"
            >
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 12px;">
          <label class="form-label">Student Full Name <span style="color: #ef4444;">*</span></label>
          <input 
            type="text" 
            name="student_name" 
            id="modal_edit_name" 
            class="form-control" 
            required 
            oninput="this.value = this.value.replace(/[^a-zA-Z\sñÑ.'-]/g, '');"
          >
        </div>

        <div class="form-group" style="margin-bottom: 12px;">
          <label class="form-label">Disciplinary Offense / Case Title <span style="color: #ef4444;">*</span></label>
          <input 
            type="text" 
            name="case_title" 
            id="modal_edit_title" 
            list="case_title_presets"
            class="form-control" 
            required
          >
        </div>

        <div class="form-group" style="margin-bottom: 12px;">
          <label class="form-label">Case Details / Guidance Office Narrative</label>
          <textarea 
            name="case_details" 
            id="modal_edit_details" 
            class="form-control" 
            rows="3" 
            style="resize: vertical; font-size: 0.88rem;"
          ></textarea>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
          <div class="form-group">
            <label class="form-label">Date Reported <span style="color: #ef4444;">*</span></label>
            <input 
              type="date" 
              name="date_reported" 
              id="modal_edit_date" 
              class="form-control" 
              required
            >
          </div>

          <div class="form-group">
            <label class="form-label">Clearance Status <span style="color: #ef4444;">*</span></label>
            <select name="status" id="modal_edit_status" class="form-control" required>
              <option value="Active Hold">🔴 Active Hold (Restricted)</option>
              <option value="Cleared">🟢 Cleared (No Hold)</option>
            </select>
          </div>
        </div>
      </div>

      <div class="user-modal-footer">
        <button type="button" class="btn-primary-cta" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; box-shadow: none;" onclick="closeEditCaseModal()">Cancel</button>
        <button type="submit" class="btn-primary-cta" style="background: #0284c7;">Update Record</button>
      </div>
    </form>
  </div>
</div>

<script>
function populateStudentFields(select) {
  const opt = select.options[select.selectedIndex];
  if (!opt || !opt.value) return;

  const lrnInput = document.getElementById('add_case_lrn');
  const nameInput = document.getElementById('add_case_name');
  const secInput = document.getElementById('add_case_section');

  if (lrnInput) lrnInput.value = opt.value;
  if (nameInput) nameInput.value = opt.getAttribute('data-name') || '';
  if (secInput) secInput.value = opt.getAttribute('data-sec') || '';
}

function openAddCaseModal() {
  const modal = document.getElementById('addCaseModal');
  if (modal) modal.classList.add('active');
}

function closeAddCaseModal() {
  const modal = document.getElementById('addCaseModal');
  if (modal) modal.classList.remove('active');
}

function openEditCaseModal(data) {
  document.getElementById('modal_edit_id').value = data.id;
  document.getElementById('modal_edit_lrn').value = data.lrn || '';
  document.getElementById('modal_edit_name').value = data.student_name || '';
  document.getElementById('modal_edit_section').value = data.grade_section || '';
  document.getElementById('modal_edit_title').value = data.case_title || '';
  document.getElementById('modal_edit_details').value = data.case_details || '';
  document.getElementById('modal_edit_date').value = data.date_reported || '';
  document.getElementById('modal_edit_status').value = data.status || 'Active Hold';

  const modal = document.getElementById('editCaseModal');
  if (modal) modal.classList.add('active');
}

function closeEditCaseModal() {
  const modal = document.getElementById('editCaseModal');
  if (modal) modal.classList.remove('active');
}

window.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeAddCaseModal();
    closeEditCaseModal();
  }
});

document.getElementById('addCaseModal')?.addEventListener('click', (e) => {
  if (e.target.id === 'addCaseModal') closeAddCaseModal();
});
document.getElementById('editCaseModal')?.addEventListener('click', (e) => {
  if (e.target.id === 'editCaseModal') closeEditCaseModal();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
