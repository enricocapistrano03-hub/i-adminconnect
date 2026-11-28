<?php
$page_title_custom = '<div class="breadcrumb-nav">Dashboard &gt; Academic Records</div><h1>Student Grade Management &amp; Encoding</h1><p>Upload batch grading sheets via CSV, encode individual student grades, and generate official SF9 report cards.</p>';
require_once __DIR__ . '/includes/header.php';

// Access Control: Administrator, Registrar, and Teacher only
if (!in_array($user_role, ['admin', 'registrar', 'teacher'])) {
    header("Location: dashboard.php");
    exit();
}

$success_msg = '';
$error_msg = '';

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// -----------------------------------------------------------------------------
// 1. Handle Batch CSV Upload
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_csv') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = "Please select a valid CSV file to upload.";
    } else {
        $tmp_file = $_FILES['csv_file']['tmp_name'];
        $file_name = $_FILES['csv_file']['name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if ($ext !== 'csv') {
            $error_msg = "Invalid file type. Only standard .csv files are supported. You can export your Excel spreadsheet as CSV (Comma delimited).";
        } else {
            $fallback_sy = trim($_POST['fallback_school_year'] ?? '2025-2026');
            $fallback_sem = trim($_POST['fallback_semester'] ?? '1st Semester');
            $default_teacher = ($user_role === 'teacher') ? $user_name : 'Faculty Staff';

            $handle = fopen($tmp_file, 'r');
            if ($handle === false) {
                $error_msg = "Could not open the uploaded CSV file.";
            } else {
                // Detect and skip UTF-8 BOM if present
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }

                // Read header row
                $first_line = fgets($handle);
                $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';
                $headers = str_getcsv($first_line, $delimiter);

                // Normalize header mapping
                $header_map = [];
                foreach ($headers as $idx => $hdr) {
                    $cleaned = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $hdr)));
                    $header_map[$cleaned] = $idx;
                }

                $inserted_count = 0;
                $updated_count = 0;
                $skipped_count = 0;
                $row_index = 1;

                $upsert_stmt = $pdo->prepare("INSERT INTO student_grades 
                    (lrn, student_name, grade_level, section, school_year, semester, subject_code, subject_name, q1, q2, q3, q4, final_grade, remarks, teacher_name)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        student_name = VALUES(student_name),
                        grade_level = VALUES(grade_level),
                        section = VALUES(section),
                        subject_code = VALUES(subject_code),
                        q1 = VALUES(q1),
                        q2 = VALUES(q2),
                        q3 = VALUES(q3),
                        q4 = VALUES(q4),
                        final_grade = VALUES(final_grade),
                        remarks = VALUES(remarks),
                        teacher_name = VALUES(teacher_name)");

                $pdo->beginTransaction();

                try {
                    while (($row = fgetcsv($handle, 4096, $delimiter)) !== false) {
                        $row_index++;
                        if (empty(array_filter($row))) continue; // skip blank rows

                        // Extract by flexible column names
                        $lrn = trim($row[$header_map['lrn'] ?? 0] ?? '');
                        $name = trim($row[$header_map['studentname'] ?? ($header_map['name'] ?? 1)] ?? '');
                        $grade = trim($row[$header_map['gradelevel'] ?? ($header_map['grade'] ?? 2)] ?? 'Grade 11');
                        $section = trim($row[$header_map['section'] ?? 3] ?? '');
                        $sy = trim($row[$header_map['schoolyear'] ?? ($header_map['sy'] ?? 4)] ?? $fallback_sy);
                        $sem = trim($row[$header_map['semester'] ?? ($header_map['sem'] ?? 5)] ?? $fallback_sem);
                        $code = trim($row[$header_map['subjectcode'] ?? ($header_map['code'] ?? 6)] ?? '');
                        $subject = trim($row[$header_map['subjectname'] ?? ($header_map['subject'] ?? 7)] ?? '');
                        
                        $q1_raw = trim($row[$header_map['q1'] ?? 8] ?? '');
                        $q2_raw = trim($row[$header_map['q2'] ?? 9] ?? '');
                        $q3_raw = trim($row[$header_map['q3'] ?? 10] ?? '');
                        $q4_raw = trim($row[$header_map['q4'] ?? 11] ?? '');
                        $final_raw = trim($row[$header_map['finalgrade'] ?? ($header_map['final'] ?? 12)] ?? '');
                        $remarks_raw = trim($row[$header_map['remarks'] ?? 13] ?? '');
                        $teacher = trim($row[$header_map['teachername'] ?? ($header_map['teacher'] ?? 14)] ?? $default_teacher);

                        if (empty($lrn) || empty($name) || empty($subject)) {
                            $skipped_count++;
                            continue;
                        }

                        // Sanitize grades
                        $q1 = (is_numeric($q1_raw) && $q1_raw >= 50 && $q1_raw <= 100) ? (float)$q1_raw : null;
                        $q2 = (is_numeric($q2_raw) && $q2_raw >= 50 && $q2_raw <= 100) ? (float)$q2_raw : null;
                        $q3 = (is_numeric($q3_raw) && $q3_raw >= 50 && $q3_raw <= 100) ? (float)$q3_raw : null;
                        $q4 = (is_numeric($q4_raw) && $q4_raw >= 50 && $q4_raw <= 100) ? (float)$q4_raw : null;

                        // Calculate final grade if missing
                        if (is_numeric($final_raw) && $final_raw >= 50 && $final_raw <= 100) {
                            $final_grade = (float)$final_raw;
                        } else {
                            $valid_quarters = array_filter([$q1, $q2, $q3, $q4], function($v) { return $v !== null; });
                            if (!empty($valid_quarters)) {
                                $final_grade = round(array_sum($valid_quarters) / count($valid_quarters));
                            } else {
                                $final_grade = null;
                            }
                        }

                        // Remarks
                        if (!empty($remarks_raw)) {
                            $remarks = $remarks_raw;
                        } elseif ($final_grade !== null) {
                            $remarks = ($final_grade >= 75) ? 'Passed' : 'Failed';
                        } else {
                            $remarks = 'Pending';
                        }

                        // Check if row exists for count stats
                        $check_exist = $pdo->prepare("SELECT id FROM student_grades WHERE lrn = ? AND school_year = ? AND semester = ? AND subject_name = ?");
                        $check_exist->execute([$lrn, $sy, $sem, $subject]);
                        $exists = (bool)$check_exist->fetch();

                        $upsert_stmt->execute([
                            $lrn, $name, $grade, $section, $sy, $sem, $code, $subject,
                            $q1, $q2, $q3, $q4, $final_grade, $remarks, $teacher
                        ]);

                        if ($exists) {
                            $updated_count++;
                        } else {
                            $inserted_count++;
                        }
                    }

                    $pdo->commit();
                    fclose($handle);

                    log_audit_action($pdo, $user_name, $user_role, "Imported Grades CSV ($file_name): $inserted_count new, $updated_count updated, $skipped_count skipped.");
                    $success_msg = "Successfully uploaded and processed <strong>" . ($inserted_count + $updated_count) . "</strong> grade records! (<strong>{$inserted_count}</strong> newly added, <strong>{$updated_count}</strong> updated, <strong>{$skipped_count}</strong> skipped/blank).";
                } catch (Exception $ex) {
                    $pdo->rollBack();
                    fclose($handle);
                    $error_msg = "Database error while importing CSV: " . $ex->getMessage();
                }
            }
        }
    }
}

// -----------------------------------------------------------------------------
// 2. Handle Single Grade Add / Edit
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_grade') {
    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $lrn = trim($_POST['lrn'] ?? '');
    $student_name = trim($_POST['student_name'] ?? '');
    $grade_level = trim($_POST['grade_level'] ?? 'Grade 11');
    $section = trim($_POST['section'] ?? '');
    $school_year = trim($_POST['school_year'] ?? '2025-2026');
    $semester = trim($_POST['semester'] ?? '1st Semester');
    $subject_code = trim($_POST['subject_code'] ?? '');
    $subject_name = trim($_POST['subject_name'] ?? '');
    $q1_in = trim($_POST['q1'] ?? '');
    $q2_in = trim($_POST['q2'] ?? '');
    $q3_in = trim($_POST['q3'] ?? '');
    $q4_in = trim($_POST['q4'] ?? '');
    $teacher_name = trim($_POST['teacher_name'] ?? ($user_role === 'teacher' ? $user_name : 'Faculty Staff'));

    if (empty($lrn) || empty($student_name) || empty($subject_name)) {
        $error_msg = "LRN, Student Name, and Subject Name are required fields.";
    } else {
        $q1 = (is_numeric($q1_in) && $q1_in >= 50 && $q1_in <= 100) ? (float)$q1_in : null;
        $q2 = (is_numeric($q2_in) && $q2_in >= 50 && $q2_in <= 100) ? (float)$q2_in : null;
        $q3 = (is_numeric($q3_in) && $q3_in >= 50 && $q3_in <= 100) ? (float)$q3_in : null;
        $q4 = (is_numeric($q4_in) && $q4_in >= 50 && $q4_in <= 100) ? (float)$q4_in : null;

        $valid_q = array_filter([$q1, $q2, $q3, $q4], function($v) { return $v !== null; });
        $final_grade = !empty($valid_q) ? round(array_sum($valid_q) / count($valid_q)) : null;
        $remarks = ($final_grade !== null) ? (($final_grade >= 75) ? 'Passed' : 'Failed') : 'Pending';

        if ($edit_id > 0) {
            $stmt = $pdo->prepare("UPDATE student_grades SET 
                lrn = ?, student_name = ?, grade_level = ?, section = ?, school_year = ?, semester = ?, 
                subject_code = ?, subject_name = ?, q1 = ?, q2 = ?, q3 = ?, q4 = ?, final_grade = ?, remarks = ?, teacher_name = ?
                WHERE id = ?");
            $stmt->execute([
                $lrn, $student_name, $grade_level, $section, $school_year, $semester,
                $subject_code, $subject_name, $q1, $q2, $q3, $q4, $final_grade, $remarks, $teacher_name, $edit_id
            ]);
            log_audit_action($pdo, $user_name, $user_role, "Updated Grade ID {$edit_id}: {$student_name} - {$subject_name} ({$final_grade})");
            $success_msg = "Grade record for <strong>" . htmlspecialchars($student_name) . "</strong> ({$subject_name}) has been successfully updated!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO student_grades 
                (lrn, student_name, grade_level, section, school_year, semester, subject_code, subject_name, q1, q2, q3, q4, final_grade, remarks, teacher_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    student_name = VALUES(student_name),
                    grade_level = VALUES(grade_level),
                    section = VALUES(section),
                    subject_code = VALUES(subject_code),
                    q1 = VALUES(q1),
                    q2 = VALUES(q2),
                    q3 = VALUES(q3),
                    q4 = VALUES(q4),
                    final_grade = VALUES(final_grade),
                    remarks = VALUES(remarks),
                    teacher_name = VALUES(teacher_name)");
            $stmt->execute([
                $lrn, $student_name, $grade_level, $section, $school_year, $semester,
                $subject_code, $subject_name, $q1, $q2, $q3, $q4, $final_grade, $remarks, $teacher_name
            ]);
            log_audit_action($pdo, $user_name, $user_role, "Encoded New Grade: {$student_name} (LRN: {$lrn}) - {$subject_name} ({$final_grade})");
            $success_msg = "Grade record for <strong>" . htmlspecialchars($student_name) . "</strong> ({$subject_name}) successfully encoded!";
        }
    }
}

// -----------------------------------------------------------------------------
// 3. Handle Delete Grade
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_grade') {
    $target_id = (int)($_POST['target_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM student_grades WHERE id = ?");
    $stmt->execute([$target_id]);
    $rec = $stmt->fetch();

    if ($rec) {
        $del = $pdo->prepare("DELETE FROM student_grades WHERE id = ?");
        $del->execute([$target_id]);
        log_audit_action($pdo, $user_name, $user_role, "Deleted Grade Record ID {$target_id}: {$rec['student_name']} - {$rec['subject_name']}");
        $success_msg = "Grade record for <strong>" . htmlspecialchars($rec['student_name']) . "</strong> ({$rec['subject_name']}) has been deleted.";
    } else {
        $error_msg = "Grade record not found.";
    }
}

// -----------------------------------------------------------------------------
// 4. Query Filters & Data
// -----------------------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$sy_filter = trim($_GET['sy'] ?? 'ALL');
$sem_filter = trim($_GET['sem'] ?? 'ALL');
$grade_filter = trim($_GET['grade'] ?? 'ALL');
$section_filter = trim($_GET['section'] ?? 'ALL');

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(g.lrn LIKE ? OR g.student_name LIKE ? OR g.subject_name LIKE ?)";
    $term = "%{$search}%";
    $params = array_merge($params, [$term, $term, $term]);
}

if ($sy_filter !== 'ALL' && $sy_filter !== '') {
    $where[] = "g.school_year = ?";
    $params[] = $sy_filter;
}

if ($sem_filter !== 'ALL' && $sem_filter !== '') {
    $where[] = "g.semester = ?";
    $params[] = $sem_filter;
}

if ($grade_filter !== 'ALL' && $grade_filter !== '') {
    $where[] = "g.grade_level = ?";
    $params[] = $grade_filter;
}

if ($section_filter !== 'ALL' && $section_filter !== '') {
    $where[] = "g.section = ?";
    $params[] = $section_filter;
}

$where_clause = implode(' AND ', $where);

// KPI Aggregates
$kpi_students = (int)$pdo->query("SELECT COUNT(DISTINCT lrn) FROM student_grades")->fetchColumn();
$kpi_records = (int)$pdo->query("SELECT COUNT(*) FROM student_grades")->fetchColumn();
$kpi_passed = (int)$pdo->query("SELECT COUNT(*) FROM student_grades WHERE remarks = 'Passed'")->fetchColumn();
$kpi_rate = ($kpi_records > 0) ? round(($kpi_passed / $kpi_records) * 100, 1) : 0;
$kpi_subjects = (int)$pdo->query("SELECT COUNT(DISTINCT subject_name) FROM student_grades")->fetchColumn();

// Fetch Filter Dropdown Distinct Values
$distinct_sy = $pdo->query("SELECT DISTINCT school_year FROM student_grades ORDER BY school_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$distinct_grades = $pdo->query("SELECT DISTINCT grade_level FROM student_grades ORDER BY grade_level ASC")->fetchAll(PDO::FETCH_COLUMN);
$distinct_sections = $pdo->query("SELECT DISTINCT section FROM student_grades WHERE section IS NOT NULL AND section != '' ORDER BY section ASC")->fetchAll(PDO::FETCH_COLUMN);

// Fetch Main Grade Records
$list_stmt = $pdo->prepare("SELECT g.* FROM student_grades g WHERE {$where_clause} ORDER BY g.school_year DESC, g.semester ASC, g.student_name ASC, g.subject_name ASC LIMIT 250");
$list_stmt->execute($params);
$grades_list = $list_stmt->fetchAll();

// Pre-fetch Registered Students for Auto-fill in Add Modal
$reg_students = $pdo->query("SELECT id_number, full_name, year_level, section FROM users WHERE role = 'student' AND id_number IS NOT NULL AND id_number != '' ORDER BY full_name ASC LIMIT 100")->fetchAll();
?>

<div class="content-card">

  <!-- Alert Notifications -->
  <?php if (!empty($success_msg)): ?>
    <div style="background: #ecfdf5; border-left: 4px solid #10b981; padding: 14px 18px; border-radius: 8px; margin-bottom: 22px; color: #065f46; display: flex; align-items: center; justify-content: space-between;">
      <div><?= $success_msg ?></div>
      <button onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #065f46;">&times;</button>
    </div>
  <?php endif; ?>

  <?php if (!empty($error_msg)): ?>
    <div style="background: #fef2f2; border-left: 4px solid #ef4444; padding: 14px 18px; border-radius: 8px; margin-bottom: 22px; color: #991b1b; display: flex; align-items: center; justify-content: space-between;">
      <div><?= htmlspecialchars($error_msg) ?></div>
      <button onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #991b1b;">&times;</button>
    </div>
  <?php endif; ?>

  <!-- KPI Stat Counters -->
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 26px;">
    
    <div style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
      <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Graded Students</div>
      <div style="font-size: 1.85rem; font-weight: 800; color: #0f172a; margin-top: 6px;"><?= number_format($kpi_students) ?></div>
      <div style="font-size: 0.78rem; color: #64748b; margin-top: 4px;">Registered learners with encoded grades</div>
    </div>

    <div style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
      <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Total Subject Grades</div>
      <div style="font-size: 1.85rem; font-weight: 800; color: #0284c7; margin-top: 6px;"><?= number_format($kpi_records) ?></div>
      <div style="font-size: 0.78rem; color: #0284c7; margin-top: 4px;">Quarterly &amp; final rating entries</div>
    </div>

    <div style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
      <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Overall Passing Rate</div>
      <div style="font-size: 1.85rem; font-weight: 800; color: #16a34a; margin-top: 6px;"><?= $kpi_rate ?>%</div>
      <div style="font-size: 0.78rem; color: #16a34a; margin-top: 4px;"><?= number_format($kpi_passed) ?> passed subject ratings</div>
    </div>

    <div style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
      <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Active Subjects</div>
      <div style="font-size: 1.85rem; font-weight: 800; color: #8b5cf6; margin-top: 6px;"><?= number_format($kpi_subjects) ?></div>
      <div style="font-size: 0.78rem; color: #8b5cf6; margin-top: 4px;">Core, applied &amp; specialized curriculum</div>
    </div>

  </div>

  <!-- Action Bar & Filter Controls -->
  <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 22px;">
    
    <!-- Action Buttons -->
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
      <button type="button" class="btn-primary-cta" onclick="openUploadModal()" style="display: inline-flex; align-items: center; gap: 6px; background: #0284c7; padding: 10px 18px; font-size: 0.88rem;">
        <span>📂 Upload CSV / Excel</span>
      </button>

      <button type="button" class="btn-primary-cta" onclick="openAddGradeModal()" style="display: inline-flex; align-items: center; gap: 6px; background: #16a34a; padding: 10px 18px; font-size: 0.88rem;">
        <span>➕ Encode Single Grade</span>
      </button>

      <a href="download_grade_template.php" class="btn-primary-cta" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; text-decoration: none; padding: 10px 16px; font-size: 0.88rem; display: inline-flex; align-items: center; gap: 6px;">
        <span>📥 Download CSV Template</span>
      </a>
    </div>

    <!-- Search & Filter Form -->
    <form method="GET" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
      <input 
        type="text" 
        name="search" 
        class="form-control" 
        placeholder="Search LRN, name, subject..." 
        value="<?= htmlspecialchars($search) ?>"
        style="width: 220px;"
      />

      <select name="sy" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="ALL">All School Years</option>
        <?php foreach ($distinct_sy as $sy_opt): ?>
          <option value="<?= htmlspecialchars($sy_opt) ?>" <?= $sy_filter === $sy_opt ? 'selected' : '' ?>><?= htmlspecialchars($sy_opt) ?></option>
        <?php endforeach; ?>
        <?php if (!in_array('2025-2026', $distinct_sy)): ?>
          <option value="2025-2026" <?= $sy_filter === '2025-2026' ? 'selected' : '' ?>>2025-2026</option>
        <?php endif; ?>
      </select>

      <select name="sem" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="ALL">All Semesters</option>
        <option value="1st Semester" <?= $sem_filter === '1st Semester' ? 'selected' : '' ?>>1st Sem</option>
        <option value="2nd Semester" <?= $sem_filter === '2nd Semester' ? 'selected' : '' ?>>2nd Sem</option>
      </select>

      <button type="submit" class="btn-primary-cta" style="padding: 10px 16px; font-size: 0.85rem;">Filter</button>
      
      <?php if ($search !== '' || $sy_filter !== 'ALL' || $sem_filter !== 'ALL' || $grade_filter !== 'ALL' || $section_filter !== 'ALL'): ?>
        <a href="grades_management.php" class="btn-primary-cta" style="background: #e2e8f0; color: #475569; padding: 10px 14px; font-size: 0.85rem; text-decoration: none;">Reset</a>
      <?php endif; ?>
    </form>

  </div>

  <!-- Grades Table -->
  <div class="table-container">
    <table class="custom-table">
      <thead>
        <tr>
          <th>Student LRN &amp; Name</th>
          <th>Grade &amp; Section</th>
          <th>School Year &amp; Sem</th>
          <th>Subject Name</th>
          <th style="text-align: center;">Q1</th>
          <th style="text-align: center;">Q2</th>
          <th style="text-align: center;">Final Rating</th>
          <th style="text-align: center;">Remarks</th>
          <th style="text-align: center; min-width: 130px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($grades_list)): ?>
          <tr>
            <td colspan="9" style="text-align: center; color: #94a3b8; padding: 48px 20px;">
              <div style="font-size: 2.2rem; margin-bottom: 8px;">📊</div>
              <div style="font-weight: 700; color: #475569; font-size: 1rem;">No grade records found</div>
              <div style="font-size: 0.85rem; color: #94a3b8; margin-top: 4px;">Click <strong>"Upload CSV / Excel"</strong> or <strong>"Encode Single Grade"</strong> to add records.</div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($grades_list as $row): 
            $is_passed = (strtolower($row['remarks']) === 'passed');
            $rem_bg = $is_passed ? '#ecfdf5' : '#fef2f2';
            $rem_color = $is_passed ? '#047857' : '#b91c1c';
            $rem_border = $is_passed ? '#a7f3d0' : '#fecaca';
          ?>
            <tr>
              <td>
                <div style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($row['student_name']) ?></div>
                <div style="font-size: 0.76rem; color: #64748b; font-family: monospace;">LRN: <?= htmlspecialchars($row['lrn']) ?></div>
              </td>
              <td>
                <div><?= htmlspecialchars($row['grade_level']) ?></div>
                <div style="font-size: 0.78rem; color: #64748b;"><?= htmlspecialchars($row['section'] ?: 'No section') ?></div>
              </td>
              <td>
                <div style="font-weight: 600; color: #334155;"><?= htmlspecialchars($row['school_year']) ?></div>
                <div style="font-size: 0.78rem; color: #64748b;"><?= htmlspecialchars($row['semester']) ?></div>
              </td>
              <td>
                <div style="font-weight: 600; color: #0f172a;"><?= htmlspecialchars($row['subject_name']) ?></div>
                <?php if (!empty($row['subject_code'])): ?>
                  <span style="font-size: 0.72rem; background: #f1f5f9; color: #475569; padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($row['subject_code']) ?></span>
                <?php endif; ?>
              </td>
              <td style="text-align: center; font-weight: 600; color: #334155;">
                <?= ($row['q1'] !== null) ? number_format($row['q1'], 0) : '&ndash;' ?>
              </td>
              <td style="text-align: center; font-weight: 600; color: #334155;">
                <?= ($row['q2'] !== null) ? number_format($row['q2'], 0) : '&ndash;' ?>
              </td>
              <td style="text-align: center; font-weight: 800; font-size: 0.95rem; color: <?= ($row['final_grade'] >= 75) ? '#15803d' : '#b91c1c' ?>;">
                <?= ($row['final_grade'] !== null) ? number_format($row['final_grade'], 0) : '&ndash;' ?>
              </td>
              <td style="text-align: center;">
                <span style="background: <?= $rem_bg ?>; color: <?= $rem_color ?>; border: 1px solid <?= $rem_border ?>; padding: 3px 8px; border-radius: 6px; font-size: 0.74rem; font-weight: 800;">
                  <?= htmlspecialchars($row['remarks']) ?>
                </span>
              </td>
              <td style="text-align: center; white-space: nowrap;">
                <button type="button" 
                  class="btn-primary-cta" 
                  style="padding: 6px 10px; font-size: 0.78rem; background: #f8fafc; color: #0284c7; border: 1px solid #bae6fd; box-shadow: none;"
                  onclick='viewStudentCard(<?= json_encode($row['lrn']) ?>, <?= json_encode($row['student_name']) ?>, <?= json_encode($row['school_year']) ?>, <?= json_encode($row['semester']) ?>)'
                  title="View Official SF9 / Report Card">
                  📜 SF9
                </button>
                <button type="button" 
                  class="btn-primary-cta" 
                  style="padding: 6px 10px; font-size: 0.78rem; background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; box-shadow: none;"
                  onclick='editGrade(<?= json_encode($row) ?>)'
                  title="Edit Grade">
                  ✏️
                </button>
                <button type="button" 
                  class="btn-primary-cta" 
                  style="padding: 6px 10px; font-size: 0.78rem; background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; box-shadow: none;"
                  onclick="deleteGrade(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['student_name'])) ?>', '<?= htmlspecialchars(addslashes($row['subject_name'])) ?>')"
                  title="Delete Record">
                  🗑️
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 1: Batch Upload CSV                                                 -->
<!-- ========================================================================= -->
<div id="uploadModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
  <div style="background: white; border-radius: 16px; max-width: 580px; width: 100%; padding: 28px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); max-height: 90vh; overflow-y: auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
      <h2 style="margin: 0; font-size: 1.25rem; color: #0f172a; display: flex; align-items: center; gap: 8px;">
        <span>📂</span> Batch Upload Student Grades (CSV)
      </h2>
      <button onclick="closeUploadModal()" style="background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer;">&times;</button>
    </div>

    <p style="font-size: 0.88rem; color: #64748b; margin-top: 0; line-height: 1.5;">
      Upload an official grading sheet in CSV format. If your file is in Excel (.xlsx), simply click <strong>File &gt; Save As &gt; CSV (Comma delimited)</strong>.
    </p>

    <div style="background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 12px; padding: 18px; margin-bottom: 20px; text-align: center;">
      <div style="font-size: 0.84rem; color: #475569; margin-bottom: 10px;">
        💡 Kailangan ng tamang template para walang error sa pag-upload?
      </div>
      <a href="download_grade_template.php" style="display: inline-flex; align-items: center; gap: 6px; background: #ffffff; color: #0284c7; border: 1.5px solid #0284c7; padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 700; text-decoration: none;">
        📥 Download Official CSV Template (.csv)
      </a>
    </div>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_csv">

      <div style="margin-bottom: 16px;">
        <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; color: #334155;">Select CSV File *</label>
        <input type="file" name="csv_file" accept=".csv" required class="form-control" style="padding: 10px;">
        <small style="color: #64748b; font-size: 0.76rem;">Accepted format: .csv (UTF-8 or standard Comma-delimited)</small>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px;">
        <div>
          <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; color: #334155;">Default School Year</label>
          <input type="text" name="fallback_school_year" value="2025-2026" class="form-control" placeholder="2025-2026">
          <small style="color: #64748b; font-size: 0.74rem;">Gagamitin kung walang SY column sa CSV</small>
        </div>
        <div>
          <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; color: #334155;">Default Semester</label>
          <select name="fallback_semester" class="form-control">
            <option value="1st Semester">1st Semester</option>
            <option value="2nd Semester">2nd Semester</option>
          </select>
          <small style="color: #64748b; font-size: 0.74rem;">Gagamitin kung walang Sem column sa CSV</small>
        </div>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 10px;">
        <button type="button" onclick="closeUploadModal()" class="btn-primary-cta" style="background: #e2e8f0; color: #475569;">Cancel</button>
        <button type="submit" class="btn-primary-cta" style="background: #0284c7; padding: 10px 22px;">Upload &amp; Import Grades</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 2: Add / Edit Single Grade Record                                   -->
<!-- ========================================================================= -->
<div id="gradeModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
  <div style="background: white; border-radius: 16px; max-width: 620px; width: 100%; padding: 28px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); max-height: 90vh; overflow-y: auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
      <h2 id="modalTitle" style="margin: 0; font-size: 1.25rem; color: #0f172a;">Encode Student Grade</h2>
      <button onclick="closeGradeModal()" style="background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer;">&times;</button>
    </div>

    <!-- Quick Student Lookup Auto-fill -->
    <div id="quickStudentPickerRow" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 14px; margin-bottom: 16px;">
      <label style="display: block; font-weight: 700; font-size: 0.8rem; color: #166534; margin-bottom: 6px;">⚡ Quick Auto-Fill mula sa Registered Students:</label>
      <select id="studentPicker" class="form-control" style="background: white;" onchange="autoFillStudent(this)">
        <option value="">-- Piliin ang estudyante para mag-auto fill --</option>
        <?php foreach ($reg_students as $st): ?>
          <option value="<?= htmlspecialchars($st['id_number']) ?>" 
                  data-name="<?= htmlspecialchars($st['full_name']) ?>"
                  data-grade="<?= htmlspecialchars($st['year_level'] ?: 'Grade 11') ?>"
                  data-sec="<?= htmlspecialchars($st['section'] ?: '') ?>">
            <?= htmlspecialchars($st['full_name']) ?> (LRN: <?= htmlspecialchars($st['id_number']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <form method="POST" id="gradeForm">
      <input type="hidden" name="action" value="save_grade">
      <input type="hidden" name="edit_id" id="form_edit_id" value="0">

      <div style="display: grid; grid-template-columns: 1.2fr 1.8fr; gap: 14px; margin-bottom: 14px;">
        <div>
          <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; color: #334155;">Student LRN (12 Digits) *</label>
          <input type="text" name="lrn" id="form_lrn" required class="form-control" placeholder="136887180001" maxlength="12" pattern="\d{12}">
        </div>
        <div>
          <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; color: #334155;">Student Full Name *</label>
          <input type="text" name="student_name" id="form_student_name" required class="form-control" placeholder="Dela Cruz, Juan M.">
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
        <div>
          <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; color: #334155;">Grade Level *</label>
          <select name="grade_level" id="form_grade_level" class="form-control">
            <option value="Grade 11">Grade 11</option>
            <option value="Grade 12">Grade 12</option>
            <option value="Grade 10">Grade 10</option>
            <option value="Grade 9">Grade 9</option>
            <option value="Grade 8">Grade 8</option>
            <option value="Grade 7">Grade 7</option>
          </select>
        </div>
        <div>
          <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; color: #334155;">Section / Strand</label>
          <input type="text" name="section" id="form_section" class="form-control" placeholder="STEM - Einstein">
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
        <div>
          <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; color: #334155;">School Year *</label>
          <input type="text" name="school_year" id="form_school_year" required value="2025-2026" class="form-control" placeholder="2025-2026">
        </div>
        <div>
          <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; color: #334155;">Semester *</label>
          <select name="semester" id="form_semester" class="form-control">
            <option value="1st Semester">1st Semester</option>
            <option value="2nd Semester">2nd Semester</option>
          </select>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 14px; margin-bottom: 14px;">
        <div>
          <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; color: #334155;">Subject Code</label>
          <input type="text" name="subject_code" id="form_subject_code" class="form-control" placeholder="GENMATH-11">
        </div>
        <div>
          <label style="display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 6px; color: #334155;">Subject Title *</label>
          <input type="text" name="subject_name" id="form_subject_name" required class="form-control" placeholder="General Mathematics">
        </div>
      </div>

      <!-- Quarter Grades & Live Average -->
      <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 20px;">
        <label style="display: block; font-weight: 700; font-size: 0.85rem; color: #1e293b; margin-bottom: 10px;">Quarter Grades (Numeric 60 - 100):</label>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; align-items: center;">
          <div>
            <label style="display: block; font-size: 0.78rem; font-weight: 700; color: #64748b; margin-bottom: 4px;">Quarter 1</label>
            <input type="number" step="1" min="50" max="100" name="q1" id="form_q1" class="form-control" placeholder="e.g. 88" oninput="computeLiveAverage()">
          </div>
          <div>
            <label style="display: block; font-size: 0.78rem; font-weight: 700; color: #64748b; margin-bottom: 4px;">Quarter 2</label>
            <input type="number" step="1" min="50" max="100" name="q2" id="form_q2" class="form-control" placeholder="e.g. 90" oninput="computeLiveAverage()">
          </div>
          <div style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; text-align: center;">
            <div style="font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Final Rating</div>
            <div id="liveFinalRating" style="font-size: 1.3rem; font-weight: 800; color: #16a34a; margin-top: 2px;">--</div>
            <div id="liveRemarks" style="font-size: 0.72rem; font-weight: 800; color: #64748b;">Pending</div>
          </div>
        </div>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 10px;">
        <button type="button" onclick="closeGradeModal()" class="btn-primary-cta" style="background: #e2e8f0; color: #475569;">Cancel</button>
        <button type="submit" id="btnSubmitGrade" class="btn-primary-cta" style="background: #16a34a; padding: 10px 24px;">Save Grade Record</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 3: Official DepEd SF9 / Student Report Card Modal                   -->
<!-- ========================================================================= -->
<div id="sf9Modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
  <div style="background: white; border-radius: 16px; max-width: 800px; width: 100%; padding: 32px; box-shadow: 0 20px 45px rgba(0,0,0,0.3); max-height: 92vh; overflow-y: auto;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1.5px solid #e2e8f0; padding-bottom: 12px;">
      <div style="display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 1.6rem;">📜</span>
        <div>
          <h2 style="margin: 0; font-size: 1.15rem; color: #0f172a;">Official School Form 9 (SF9) / Learner Progress Report</h2>
          <div style="font-size: 0.78rem; color: #64748b;">Department of Education &bull; i-AdminConnect Academic Verification</div>
        </div>
      </div>
      <div style="display: flex; gap: 8px;">
        <button onclick="printSF9()" class="btn-primary-cta" style="background: #0284c7; padding: 6px 14px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 4px;">
          🖨️ Print Report Card
        </button>
        <button onclick="closeSF9Modal()" style="background: none; border: none; font-size: 1.6rem; color: #64748b; cursor: pointer;">&times;</button>
      </div>
    </div>

    <!-- Printable Card Content Container -->
    <div id="sf9PrintArea">
      <!-- School Letterhead -->
      <div style="text-align: center; margin-bottom: 20px; border-bottom: 2px solid #0f172a; padding-bottom: 14px;">
        <div style="font-size: 0.82rem; text-transform: uppercase; letter-spacing: 1px; color: #475569;">Republic of the Philippines &bull; Department of Education</div>
        <div style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin: 4px 0;">TAGUIG SCIENCE HIGH SCHOOL</div>
        <div style="font-size: 0.85rem; font-weight: 700; color: #0284c7;">SENIOR HIGH SCHOOL PROGRESS REPORT CARD (SF9)</div>
      </div>

      <!-- Learner Information Grid -->
      <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 20px; font-size: 0.88rem;">
        <div>
          <div><strong>Student Name:</strong> <span id="sf9StudentName">--</span></div>
          <div style="margin-top: 4px;"><strong>Learner Reference Number (LRN):</strong> <span id="sf9LRN" style="font-family: monospace; font-weight: 700;">--</span></div>
        </div>
        <div>
          <div><strong>School Year:</strong> <span id="sf9SY">--</span></div>
          <div style="margin-top: 4px;"><strong>Semester:</strong> <span id="sf9Sem">--</span></div>
        </div>
      </div>

      <!-- Subjects & Grades Table -->
      <div style="margin-bottom: 20px;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
          <thead>
            <tr style="background: #0f172a; color: white;">
              <th style="padding: 10px; text-align: left; border: 1px solid #0f172a;">Learning Areas / Subject Title</th>
              <th style="padding: 10px; text-align: center; border: 1px solid #0f172a; width: 65px;">Q1</th>
              <th style="padding: 10px; text-align: center; border: 1px solid #0f172a; width: 65px;">Q2</th>
              <th style="padding: 10px; text-align: center; border: 1px solid #0f172a; width: 90px;">Final Rating</th>
              <th style="padding: 10px; text-align: center; border: 1px solid #0f172a; width: 95px;">Remarks</th>
            </tr>
          </thead>
          <tbody id="sf9TableBody">
            <!-- Dynamically populated via JavaScript -->
          </tbody>
          <tfoot>
            <tr style="background: #f1f5f9; font-weight: 800;">
              <td style="padding: 12px 10px; border: 1px solid #cbd5e1; text-align: right;">GENERAL WEIGHTED AVERAGE (GWA):</td>
              <td colspan="2" style="border: 1px solid #cbd5e1;"></td>
              <td id="sf9GWA" style="padding: 12px 10px; border: 1px solid #cbd5e1; text-align: center; font-size: 1.05rem; color: #166534;">--</td>
              <td id="sf9FinalRemarks" style="padding: 12px 10px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800;">--</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Signatures Footer -->
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 40px; text-align: center;">
        <div>
          <div style="border-bottom: 1.5px solid #0f172a; width: 75%; margin: 0 auto 6px auto;"></div>
          <div style="font-weight: 700; font-size: 0.85rem; color: #0f172a;">Class Adviser</div>
          <div style="font-size: 0.75rem; color: #64748b;">Signature over Printed Name</div>
        </div>
        <div>
          <div style="border-bottom: 1.5px solid #0f172a; width: 75%; margin: 0 auto 6px auto;"></div>
          <div style="font-weight: 700; font-size: 0.85rem; color: #0f172a;">School Principal / Registrar</div>
          <div style="font-size: 0.75rem; color: #64748b;">Official School Authorization</div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- ========================================================================= -->
<!-- Modal 4: Delete Confirmation                                              -->
<!-- ========================================================================= -->
<div id="deleteModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
  <div style="background: white; border-radius: 16px; max-width: 440px; width: 100%; padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.25);">
    <h3 style="margin-top: 0; color: #991b1b; display: flex; align-items: center; gap: 8px;">
      <span>⚠️</span> Delete Grade Record
    </h3>
    <p style="font-size: 0.88rem; color: #475569; line-height: 1.5;" id="deleteConfirmText">
      Are you sure you want to delete this grade record?
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="delete_grade">
      <input type="hidden" name="target_id" id="deleteTargetId" value="0">
      <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
        <button type="button" onclick="closeDeleteModal()" class="btn-primary-cta" style="background: #e2e8f0; color: #475569;">Cancel</button>
        <button type="submit" class="btn-primary-cta" style="background: #dc2626; color: white;">Yes, Delete Record</button>
      </div>
    </form>
  </div>
</div>

<!-- JavaScript for Modals & Auto-calculation -->
<script>
// Auto-fill student details
function autoFillStudent(selectElem) {
  const opt = selectElem.options[selectElem.selectedIndex];
  if (!opt || !opt.value) return;
  document.getElementById('form_lrn').value = opt.value;
  document.getElementById('form_student_name').value = opt.getAttribute('data-name') || '';
  document.getElementById('form_grade_level').value = opt.getAttribute('data-grade') || 'Grade 11';
  document.getElementById('form_section').value = opt.getAttribute('data-sec') || '';
}

// Live calculation of Final Grade and Remarks
function computeLiveAverage() {
  const q1 = parseFloat(document.getElementById('form_q1').value);
  const q2 = parseFloat(document.getElementById('form_q2').value);
  const ratingElem = document.getElementById('liveFinalRating');
  const remarksElem = document.getElementById('liveRemarks');

  const valid = [];
  if (!isNaN(q1)) valid.push(q1);
  if (!isNaN(q2)) valid.push(q2);

  if (valid.length > 0) {
    const avg = Math.round(valid.reduce((a, b) => a + b, 0) / valid.length);
    ratingElem.innerText = avg;
    if (avg >= 75) {
      ratingElem.style.color = '#16a34a';
      remarksElem.innerText = 'PASSED';
      remarksElem.style.color = '#16a34a';
    } else {
      ratingElem.style.color = '#dc2626';
      remarksElem.innerText = 'FAILED';
      remarksElem.style.color = '#dc2626';
    }
  } else {
    ratingElem.innerText = '--';
    ratingElem.style.color = '#64748b';
    remarksElem.innerText = 'Pending';
    remarksElem.style.color = '#64748b';
  }
}

// Upload Modal
function openUploadModal() {
  document.getElementById('uploadModal').style.display = 'flex';
}
function closeUploadModal() {
  document.getElementById('uploadModal').style.display = 'none';
}

// Add/Edit Grade Modal
function openAddGradeModal() {
  document.getElementById('modalTitle').innerText = 'Encode Student Grade';
  document.getElementById('form_edit_id').value = '0';
  document.getElementById('gradeForm').reset();
  document.getElementById('quickStudentPickerRow').style.display = 'block';
  document.getElementById('btnSubmitGrade').innerText = 'Save Grade Record';
  computeLiveAverage();
  document.getElementById('gradeModal').style.display = 'flex';
}

function editGrade(row) {
  document.getElementById('modalTitle').innerText = 'Edit Student Grade';
  document.getElementById('form_edit_id').value = row.id;
  document.getElementById('form_lrn').value = row.lrn;
  document.getElementById('form_student_name').value = row.student_name;
  document.getElementById('form_grade_level').value = row.grade_level;
  document.getElementById('form_section').value = row.section || '';
  document.getElementById('form_school_year').value = row.school_year;
  document.getElementById('form_semester').value = row.semester;
  document.getElementById('form_subject_code').value = row.subject_code || '';
  document.getElementById('form_subject_name').value = row.subject_name;
  document.getElementById('form_q1').value = row.q1 !== null ? row.q1 : '';
  document.getElementById('form_q2').value = row.q2 !== null ? row.q2 : '';
  document.getElementById('quickStudentPickerRow').style.display = 'none';
  document.getElementById('btnSubmitGrade').innerText = 'Update Grade Record';
  computeLiveAverage();
  document.getElementById('gradeModal').style.display = 'flex';
}

function closeGradeModal() {
  document.getElementById('gradeModal').style.display = 'none';
}

// Delete Modal
function deleteGrade(id, studentName, subjectName) {
  document.getElementById('deleteTargetId').value = id;
  document.getElementById('deleteConfirmText').innerHTML = `Are you sure you want to permanently delete the grade record for <strong>${studentName}</strong> (${subjectName})?`;
  document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() {
  document.getElementById('deleteModal').style.display = 'none';
}

// SF9 Student Report Card Viewer
const allGrades = <?= json_encode($grades_list) ?>;

function viewStudentCard(lrn, studentName, sy, sem) {
  const studentGrades = allGrades.filter(g => g.lrn === lrn && g.school_year === sy && g.semester === sem);
  
  document.getElementById('sf9StudentName').innerText = studentName;
  document.getElementById('sf9LRN').innerText = lrn;
  document.getElementById('sf9SY').innerText = sy;
  document.getElementById('sf9Sem').innerText = sem;

  const tbody = document.getElementById('sf9TableBody');
  tbody.innerHTML = '';

  let totalPoints = 0;
  let subjectCount = 0;

  if (studentGrades.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 20px; color: #94a3b8;">No records found for this semester.</td></tr>';
    document.getElementById('sf9GWA').innerText = '--';
    document.getElementById('sf9FinalRemarks').innerText = '--';
  } else {
    studentGrades.forEach(row => {
      const q1Str = row.q1 !== null ? Math.round(row.q1) : '&ndash;';
      const q2Str = row.q2 !== null ? Math.round(row.q2) : '&ndash;';
      const finalStr = row.final_grade !== null ? Math.round(row.final_grade) : '&ndash;';
      const isPass = (row.remarks || '').toLowerCase() === 'passed';

      if (row.final_grade !== null) {
        totalPoints += parseFloat(row.final_grade);
        subjectCount++;
      }

      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td style="padding: 8px 10px; border: 1px solid #cbd5e1;">
          <strong>${row.subject_name}</strong>
          ${row.subject_code ? `<span style="font-size: 0.75rem; color: #64748b; margin-left: 4px;">(${row.subject_code})</span>` : ''}
        </td>
        <td style="padding: 8px 10px; border: 1px solid #cbd5e1; text-align: center;">${q1Str}</td>
        <td style="padding: 8px 10px; border: 1px solid #cbd5e1; text-align: center;">${q2Str}</td>
        <td style="padding: 8px 10px; border: 1px solid #cbd5e1; text-align: center; font-weight: 700; color: ${isPass ? '#166534' : '#b91c1c'}">${finalStr}</td>
        <td style="padding: 8px 10px; border: 1px solid #cbd5e1; text-align: center; font-weight: 700; color: ${isPass ? '#166534' : '#b91c1c'}">${row.remarks}</td>
      `;
      tbody.appendChild(tr);
    });

    if (subjectCount > 0) {
      const gwa = (totalPoints / subjectCount).toFixed(2);
      document.getElementById('sf9GWA').innerText = gwa;
      if (parseFloat(gwa) >= 75) {
        let standing = 'PASSED';
        if (parseFloat(gwa) >= 98) standing = 'PASSED (With Highest Honors)';
        else if (parseFloat(gwa) >= 95) standing = 'PASSED (With High Honors)';
        else if (parseFloat(gwa) >= 90) standing = 'PASSED (With Honors)';
        document.getElementById('sf9FinalRemarks').innerText = standing;
        document.getElementById('sf9FinalRemarks').style.color = '#166534';
      } else {
        document.getElementById('sf9FinalRemarks').innerText = 'FAILED';
        document.getElementById('sf9FinalRemarks').style.color = '#b91c1c';
      }
    } else {
      document.getElementById('sf9GWA').innerText = '--';
      document.getElementById('sf9FinalRemarks').innerText = 'Pending';
    }
  }

  document.getElementById('sf9Modal').style.display = 'flex';
}

function closeSF9Modal() {
  document.getElementById('sf9Modal').style.display = 'none';
}

function printSF9() {
  const printContents = document.getElementById('sf9PrintArea').innerHTML;
  const originalContents = document.body.innerHTML;
  document.body.innerHTML = '<div style="padding: 30px; font-family: sans-serif;">' + printContents + '</div>';
  window.print();
  document.body.innerHTML = originalContents;
  window.location.reload();
}

// Close modals when clicking outside
window.onclick = function(e) {
  if (e.target === document.getElementById('uploadModal')) closeUploadModal();
  if (e.target === document.getElementById('gradeModal')) closeGradeModal();
  if (e.target === document.getElementById('sf9Modal')) closeSF9Modal();
  if (e.target === document.getElementById('deleteModal')) closeDeleteModal();
};
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
