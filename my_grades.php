<?php
$page_title_custom = '<div class="breadcrumb-nav">Dashboard &gt; Academic Progress</div><h1>My Grades &amp; SF9 Report Card</h1><p>View your official quarterly ratings, general weighted average (GWA), and official progress report card.</p>';
require_once __DIR__ . '/includes/header.php';

// Access Control: Accessible to students (and administrative roles)
$student_user_id = $_SESSION['user_id'] ?? 0;

// Fetch current user details
$user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->execute([$student_user_id]);
$current_student = $user_stmt->fetch();

if (!$current_student) {
    echo "<div class='content-card'><h2>Account Not Found</h2></div>";
    include __DIR__ . '/includes/footer.php';
    exit();
}

$student_lrn = trim($current_student['id_number'] ?? '');
$student_name = trim($current_student['full_name'] ?? '');
$student_grade_level = trim($current_student['year_level'] ?? 'Grade 11');
$student_section = trim($current_student['section'] ?? '');
$student_strand = trim($current_student['course'] ?? 'Science, Technology, Engineering, and Mathematics (STEM)');

// Find all distinct school years and semesters for this student
$sy_stmt = $pdo->prepare("SELECT DISTINCT school_year, semester 
    FROM student_grades 
    WHERE lrn = ? OR student_name = ? OR student_name LIKE ?
    ORDER BY school_year DESC, semester ASC");
$sy_stmt->execute([$student_lrn, $student_name, "%{$student_name}%"]);
$sy_options = $sy_stmt->fetchAll();

// Default or selected filters
$sel_sy = $_GET['sy'] ?? ($sy_options[0]['school_year'] ?? '2025-2026');
$sel_sem = $_GET['sem'] ?? ($sy_options[0]['semester'] ?? '1st Semester');

// Fetch student's grades for the selected SY & Semester
$grades_stmt = $pdo->prepare("SELECT * FROM student_grades 
    WHERE (lrn = ? OR student_name = ? OR student_name LIKE ?) 
      AND school_year = ? 
      AND semester = ?
    ORDER BY subject_name ASC");
$grades_stmt->execute([$student_lrn, $student_name, "%{$student_name}%", $sel_sy, $sel_sem]);
$my_grades = $grades_stmt->fetchAll();

// Calculate GWA & Academic Standing
$total_points = 0;
$graded_subjects_count = 0;
$passed_subjects = 0;
$failed_subjects = 0;

foreach ($my_grades as $g) {
    if ($g['final_grade'] !== null) {
        $total_points += (float)$g['final_grade'];
        $graded_subjects_count++;
        if ($g['final_grade'] >= 75) {
            $passed_subjects++;
        } else {
            $failed_subjects++;
        }
    }
}

$gwa = ($graded_subjects_count > 0) ? round($total_points / $graded_subjects_count, 2) : null;

// Determine Honor status
$honors_text = '';
$honors_badge_color = '#16a34a';
if ($gwa !== null) {
    if ($gwa >= 98 && $failed_subjects === 0) {
        $honors_text = 'With Highest Honors 🏆';
        $honors_badge_color = '#b45309';
    } elseif ($gwa >= 95 && $failed_subjects === 0) {
        $honors_text = 'With High Honors 🥇';
        $honors_badge_color = '#b45309';
    } elseif ($gwa >= 90 && $failed_subjects === 0) {
        $honors_text = 'With Honors 🎖️';
        $honors_badge_color = '#0284c7';
    } elseif ($gwa >= 75 && $failed_subjects === 0) {
        $honors_text = 'Promoted / Passed';
        $honors_badge_color = '#16a34a';
    } else {
        $honors_text = 'Needs Academic Intervention';
        $honors_badge_color = '#dc2626';
    }
}
?>

<div class="content-card">

  <!-- Period Selector & Print Header -->
  <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 24px;">
    
    <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
      <div style="font-weight: 700; font-size: 0.88rem; color: #334155;">Academic Period:</div>
      
      <select name="sy" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <?php if (empty($sy_options)): ?>
          <option value="2025-2026">2025-2026</option>
        <?php else: 
          $rendered_sy = [];
          foreach ($sy_options as $opt): 
            if (in_array($opt['school_year'], $rendered_sy)) continue;
            $rendered_sy[] = $opt['school_year'];
        ?>
          <option value="<?= htmlspecialchars($opt['school_year']) ?>" <?= $sel_sy === $opt['school_year'] ? 'selected' : '' ?>>
            S.Y. <?= htmlspecialchars($opt['school_year']) ?>
          </option>
        <?php endforeach; endif; ?>
      </select>

      <select name="sem" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="1st Semester" <?= $sel_sem === '1st Semester' ? 'selected' : '' ?>>1st Semester</option>
        <option value="2nd Semester" <?= $sel_sem === '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option>
      </select>
    </form>

    <div>
      <button type="button" onclick="printReportCard()" class="btn-primary-cta" style="background: #0284c7; padding: 10px 20px; font-size: 0.88rem; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);">
        <span>🖨️ Print / Save SF9 Report Card</span>
      </button>
    </div>

  </div>

  <!-- Academic Summary KPI Cards -->
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 28px;">
    
    <!-- GWA Card -->
    <div style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 1.5px solid #86efac; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
      <div style="font-size: 0.78rem; font-weight: 800; color: #166534; text-transform: uppercase; letter-spacing: 0.5px;">General Weighted Average (GWA)</div>
      <div style="font-size: 2.3rem; font-weight: 900; color: #14532d; margin-top: 6px;">
        <?= ($gwa !== null) ? number_format($gwa, 2) : '--' ?>
      </div>
      <div style="font-size: 0.78rem; font-weight: 700; color: #166534; margin-top: 4px;">
        <?= $honors_text ?: 'Awaiting complete grade encoding' ?>
      </div>
    </div>

    <!-- Academic Standing -->
    <div style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
      <div style="font-size: 0.78rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Academic Standing</div>
      <div style="margin-top: 8px;">
        <span style="background: <?= ($gwa && $gwa >= 75) ? '#ecfdf5' : '#f8fafc' ?>; color: <?= $honors_badge_color ?>; border: 1.5px solid <?= ($gwa && $gwa >= 75) ? '#a7f3d0' : '#cbd5e1' ?>; padding: 6px 14px; border-radius: 20px; font-size: 0.88rem; font-weight: 800; display: inline-block;">
          <?= $honors_text ?: 'Pending Assessment' ?>
        </span>
      </div>
      <div style="font-size: 0.76rem; color: #64748b; margin-top: 10px;">Based on DepEd K-12 Grading System</div>
    </div>

    <!-- Enrolled Subjects -->
    <div style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
      <div style="font-size: 0.78rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Enrolled Subjects</div>
      <div style="font-size: 2rem; font-weight: 800; color: #0f172a; margin-top: 6px;">
        <?= count($my_grades) ?>
      </div>
      <div style="font-size: 0.76rem; color: #64748b; margin-top: 4px;">
        <?= $passed_subjects ?> passed &bull; <?= $failed_subjects ?> failed
      </div>
    </div>

    <!-- Student Information -->
    <div style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
      <div style="font-size: 0.78rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Learner Profile</div>
      <div style="font-weight: 700; color: #0f172a; margin-top: 6px; font-size: 0.95rem;"><?= htmlspecialchars($student_name) ?></div>
      <div style="font-size: 0.76rem; color: #64748b; margin-top: 2px;">LRN: <span style="font-family: monospace; font-weight: 700; color: #0284c7;"><?= htmlspecialchars($student_lrn ?: 'Unassigned') ?></span></div>
      <div style="font-size: 0.76rem; color: #64748b; margin-top: 2px;"><?= htmlspecialchars($student_grade_level) ?> &bull; <?= htmlspecialchars($student_section ?: 'Senior High School') ?></div>
    </div>

  </div>

  <!-- Printable Official SF9 / Report Card Sheet Container -->
  <div id="printableReportCard" style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; padding: 32px 36px; box-shadow: 0 4px 16px rgba(0,0,0,0.04);">
    
    <!-- DepEd Official Letterhead -->
    <div style="text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 16px; margin-bottom: 24px;">
      <div style="display: flex; align-items: center; justify-content: center; gap: 16px; margin-bottom: 6px;">
        <img src="assets/iAdminConnect%20LOGO.png" alt="School Seal" style="width: 58px; height: 58px; object-fit: contain; border-radius: 50%;" onerror="this.src='assets/logo.png'">
        <div>
          <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1.5px; color: #475569; font-weight: 600;">Republic of the Philippines &bull; Department of Education</div>
          <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; color: #475569; font-weight: 600;">National Capital Region &bull; Division of Taguig City and Pateros</div>
          <div style="font-size: 1.35rem; font-weight: 900; color: #0f172a; margin-top: 2px; letter-spacing: 0.5px;">TAGUIG SCIENCE HIGH SCHOOL</div>
        </div>
      </div>
      <div style="font-size: 0.95rem; font-weight: 800; color: #0284c7; margin-top: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
        Learner Progress Report Card (School Form 9 &ndash; SF9)
      </div>
    </div>

    <!-- Learner Details Table -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; margin-bottom: 22px; font-size: 0.88rem; line-height: 1.6;">
      <div>
        <div><strong>Student Name:</strong> <?= htmlspecialchars($student_name) ?></div>
        <div><strong>Learner Reference Number (LRN):</strong> <span style="font-family: monospace; font-weight: 700; color: #0284c7;"><?= htmlspecialchars($student_lrn ?: 'Pending') ?></span></div>
        <div><strong>Grade &amp; Section:</strong> <?= htmlspecialchars($student_grade_level) ?> &ndash; <?= htmlspecialchars($student_section ?: 'Regular') ?></div>
        <div><strong>Track &amp; Strand:</strong> <?= htmlspecialchars($student_strand) ?></div>
      </div>
      <div>
        <div><strong>School Year:</strong> <?= htmlspecialchars($sel_sy) ?></div>
        <div><strong>Semester:</strong> <?= htmlspecialchars($sel_sem) ?></div>
        <div><strong>Curriculum:</strong> Enhanced Basic Education (K-12)</div>
        <div><strong>Status:</strong> <span style="color: #16a34a; font-weight: 700;">Officially Enrolled</span></div>
      </div>
    </div>

    <!-- Official Report Card Table -->
    <?php if (empty($my_grades)): ?>
      <div style="text-align: center; padding: 48px 20px; background: #f8fafc; border-radius: 8px; border: 1.5px dashed #cbd5e1;">
        <div style="font-size: 2.2rem; margin-bottom: 8px;">📑</div>
        <div style="font-weight: 700; color: #334155; font-size: 1.05rem;">No Grade Records Encoded Yet</div>
        <div style="font-size: 0.85rem; color: #64748b; max-width: 460px; margin: 6px auto 0 auto; line-height: 1.5;">
          Your quarterly grades for <strong>S.Y. <?= htmlspecialchars($sel_sy) ?> &ndash; <?= htmlspecialchars($sel_sem) ?></strong> have not yet been posted by your subject teachers or the Registrar.
        </div>
      </div>
    <?php else: ?>
      <div style="overflow-x: auto; margin-bottom: 22px;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
          <thead>
            <tr style="background: #0f172a; color: white;">
              <th style="padding: 12px 14px; text-align: left; border: 1px solid #0f172a;">Learning Areas / Subject Title</th>
              <th style="padding: 12px 10px; text-align: center; border: 1px solid #0f172a; width: 75px;">Q1</th>
              <th style="padding: 12px 10px; text-align: center; border: 1px solid #0f172a; width: 75px;">Q2</th>
              <th style="padding: 12px 10px; text-align: center; border: 1px solid #0f172a; width: 105px;">Sem Final</th>
              <th style="padding: 12px 10px; text-align: center; border: 1px solid #0f172a; width: 110px;">Action / Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($my_grades as $row): 
              $is_p = (strtolower($row['remarks']) === 'passed');
              $rem_color = $is_p ? '#166534' : '#b91c1c';
              $rem_bg = $is_p ? '#ecfdf5' : '#fef2f2';
            ?>
              <tr style="border-bottom: 1px solid #e2e8f0;">
                <td style="padding: 10px 14px; border: 1px solid #cbd5e1;">
                  <div style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($row['subject_name']) ?></div>
                  <?php if (!empty($row['subject_code'])): ?>
                    <span style="font-size: 0.72rem; color: #64748b; font-family: monospace;"><?= htmlspecialchars($row['subject_code']) ?></span>
                  <?php endif; ?>
                </td>
                <td style="padding: 10px; text-align: center; border: 1px solid #cbd5e1; font-weight: 600; color: #334155;">
                  <?= ($row['q1'] !== null) ? number_format($row['q1'], 0) : '&ndash;' ?>
                </td>
                <td style="padding: 10px; text-align: center; border: 1px solid #cbd5e1; font-weight: 600; color: #334155;">
                  <?= ($row['q2'] !== null) ? number_format($row['q2'], 0) : '&ndash;' ?>
                </td>
                <td style="padding: 10px; text-align: center; border: 1px solid #cbd5e1; font-weight: 800; font-size: 1rem; color: <?= ($row['final_grade'] >= 75) ? '#166534' : '#b91c1c' ?>;">
                  <?= ($row['final_grade'] !== null) ? number_format($row['final_grade'], 0) : '&ndash;' ?>
                </td>
                <td style="padding: 10px; text-align: center; border: 1px solid #cbd5e1;">
                  <span style="background: <?= $rem_bg ?>; color: <?= $rem_color ?>; font-weight: 800; font-size: 0.78rem; padding: 4px 10px; border-radius: 6px; display: inline-block;">
                    <?= htmlspecialchars($row['remarks']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background: #f8fafc; font-weight: 800; border-top: 2px solid #0f172a;">
              <td style="padding: 14px; border: 1px solid #cbd5e1; text-align: right; text-transform: uppercase;">
                General Weighted Average (GWA):
              </td>
              <td colspan="2" style="border: 1px solid #cbd5e1;"></td>
              <td style="padding: 14px 10px; border: 1px solid #cbd5e1; text-align: center; font-size: 1.15rem; color: #166534;">
                <?= ($gwa !== null) ? number_format($gwa, 2) : '--' ?>
              </td>
              <td style="padding: 14px 10px; border: 1px solid #cbd5e1; text-align: center; font-weight: 800; color: <?= $honors_badge_color ?>;">
                <?= $honors_text ?: '--' ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- DepEd Grading Scale Legend -->
      <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-bottom: 30px; font-size: 0.78rem; color: #475569; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px;">
        <div><strong>Grading Scale:</strong> Outstanding (90-100) &bull; Very Satisfactory (85-89) &bull; Satisfactory (80-84) &bull; Fairly Satisfactory (75-79) &bull; Did Not Meet Expectations (Below 75)</div>
        <div><strong>Passing Grade:</strong> 75%</div>
      </div>

      <!-- Official Signatures Block -->
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 50px; text-align: center;">
        <div>
          <div style="border-bottom: 1.5px solid #0f172a; width: 80%; margin: 0 auto 6px auto;"></div>
          <div style="font-weight: 800; font-size: 0.88rem; color: #0f172a;">CLASS ADVISER</div>
          <div style="font-size: 0.76rem; color: #64748b;">Signature over Printed Name</div>
        </div>
        <div>
          <div style="border-bottom: 1.5px solid #0f172a; width: 80%; margin: 0 auto 6px auto;"></div>
          <div style="font-weight: 800; font-size: 0.88rem; color: #0f172a;">SCHOOL PRINCIPAL / REGISTRAR</div>
          <div style="font-size: 0.76rem; color: #64748b;">Official School Authorization &amp; Dry Seal</div>
        </div>
      </div>
    <?php endif; ?>

  </div>

</div>

<!-- Print Stylesheet -->
<style>
@media print {
  body * {
    visibility: hidden;
  }
  #printableReportCard, #printableReportCard * {
    visibility: visible;
  }
  #printableReportCard {
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
    margin: 0;
    padding: 20px;
    border: none !important;
    box-shadow: none !important;
  }
  .sidebar, header, .breadcrumb-nav, button {
    display: none !important;
  }
}
</style>

<script>
function printReportCard() {
  window.print();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
