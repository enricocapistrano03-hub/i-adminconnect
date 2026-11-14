<?php
// Start output buffering to allow dynamic breadcrumb / header overrides if needed
$ref = trim($_GET['ref'] ?? '');
$id = (int)($_GET['id'] ?? 0);

require_once __DIR__ . '/config/db_conn.php';
require_once __DIR__ . '/includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'guest';
$user_name = $_SESSION['full_name'] ?? 'User';

if ($user_id <= 0) {
    header("Location: login.php");
    exit();
}

if (empty($ref) && $id <= 0) {
    require_once __DIR__ . '/includes/header.php';
    echo "<div class='content-card'><h2>Invalid Request</h2><p>Missing request reference. <a href='my_requests.php'>Return to My Requests</a></p></div>";
    include __DIR__ . '/includes/footer.php';
    exit();
}

// Fetch request record with request type code
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT r.*, rt.name as request_type_name, rt.code as request_type_code, rt.requirements, u.full_name, u.email as requester_email, u.role as requester_role 
        FROM requests r 
        JOIN request_types rt ON r.request_type_id = rt.id 
        JOIN users u ON r.user_id = u.id 
        WHERE r.id = ? LIMIT 1");
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("SELECT r.*, rt.name as request_type_name, rt.code as request_type_code, rt.requirements, u.full_name, u.email as requester_email, u.role as requester_role 
        FROM requests r 
        JOIN request_types rt ON r.request_type_id = rt.id 
        JOIN users u ON r.user_id = u.id 
        WHERE r.tracking_number = ? LIMIT 1");
    $stmt->execute([$ref]);
}

$req = $stmt->fetch();

if (!$req) {
    require_once __DIR__ . '/includes/header.php';
    echo "<div class='content-card'><h2>Request Not Found</h2><p>Could not locate the specified request. <a href='my_requests.php'>Return to My Requests</a></p></div>";
    include __DIR__ . '/includes/footer.php';
    exit();
}

// Security Check: Only the requester (or admin/registrar) can edit
if ($user_role !== 'admin' && $user_role !== 'registrar' && (int)$req['user_id'] !== (int)$user_id) {
    require_once __DIR__ . '/includes/header.php';
    echo "<div class='content-card'><h2>Access Denied</h2><p>You do not have permission to modify this request. <a href='my_requests.php'>Return to My Requests</a></p></div>";
    include __DIR__ . '/includes/footer.php';
    exit();
}

// Detect if this is Certificate of Rating (CR)
$is_cr = (($req['request_type_code'] ?? '') === 'CR' 
    || ($req['request_type_name'] ?? '') === 'Certificate of Rating' 
    || stripos($req['request_type_name'] ?? '', 'Rating') !== false 
    || strpos($req['additional_notes'] ?? '', '[3-Year Performance Ratings]') !== false);

// Extract pre-filled 3-year performance ratings if available
$cr_sy1 = '2022–2023'; $cr_r1 = '';
$cr_sy2 = '2023–2024'; $cr_r2 = '';
$cr_sy3 = '2024–2025'; $cr_r3 = '';

if ($is_cr && !empty($req['additional_notes'])) {
    if (preg_match_all('/•\s*SY\s*([^:\n]+):\s*([0-9.]+)/u', $req['additional_notes'], $matches, PREG_SET_ORDER)) {
        if (isset($matches[0])) { $cr_sy1 = trim($matches[0][1]); $cr_r1 = trim($matches[0][2]); }
        if (isset($matches[1])) { $cr_sy2 = trim($matches[1][1]); $cr_r2 = trim($matches[1][2]); }
        if (isset($matches[2])) { $cr_sy3 = trim($matches[2][1]); $cr_r3 = trim($matches[2][2]); }
    }
}

// Dynamic page title
if ($is_cr) {
    $page_title_custom = '<div class="breadcrumb-nav"><a href="my_requests.php" style="color: inherit; text-decoration: none;">My Requests</a> &gt; Update Ratings</div><h1>Resubmit Rating Details</h1><p>Update your 3-Year Performance Ratings for administrative verification.</p>';
} else {
    $page_title_custom = '<div class="breadcrumb-nav"><a href="my_requests.php" style="color: inherit; text-decoration: none;">My Requests</a> &gt; Re-upload / Edit Document</div><h1>Re-upload Documents</h1><p>Update and resubmit the documents requested by the office.</p>';
}

require_once __DIR__ . '/includes/header.php';

$success_msg = '';
$error_msg = '';

// Handle Re-upload / Revision Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requester_notes = trim($_POST['requester_notes'] ?? '');
    $uploaded_file_name = $req['attached_filename'];
    $file_size_formatted = $req['file_size_kb'];
    $has_new_file = false;

    // Branch: Certificate of Rating vs Other Documents
    if ($is_cr) {
        $sy1 = trim($_POST['sy_year_1'] ?? '2022–2023');
        $sy2 = trim($_POST['sy_year_2'] ?? '2023–2024');
        $sy3 = trim($_POST['sy_year_3'] ?? '2024–2025');
        $r1 = (float)($_POST['rating_year_1'] ?? 0);
        $r2 = (float)($_POST['rating_year_2'] ?? 0);
        $r3 = (float)($_POST['rating_year_3'] ?? 0);

        if ($r1 <= 0 || $r2 <= 0 || $r3 <= 0) {
            $error_msg = 'Please enter valid numerical rating grades for all three (3) School Years (e.g. 4.32, 4.38, 4.40).';
        } else {
            $cr_r1 = $r1; $cr_r2 = $r2; $cr_r3 = $r3;
            $cr_sy1 = $sy1; $cr_sy2 = $sy2; $cr_sy3 = $sy3;

            $avg_rating = round(($r1 + $r2 + $r3) / 3, 2);
            $adjectival = ($avg_rating >= 4.50) ? 'Outstanding' : (($avg_rating >= 3.50) ? 'Very Satisfactory' : 'Satisfactory');

            $rating_summary = "[3-Year Performance Ratings]\n"
                . "• SY {$sy1}: " . number_format($r1, 2) . "\n"
                . "• SY {$sy2}: " . number_format($r2, 2) . "\n"
                . "• SY {$sy3}: " . number_format($r3, 2) . "\n"
                . "• 3-Year Average: " . number_format($avg_rating, 2) . " ({$adjectival})";

            // Clean previous rating block from additional_notes
            $orig_notes = $req['additional_notes'] ?? '';
            $cleaned_notes = preg_replace('/\[3-Year Performance Ratings\][\s\S]*?(?=\n\n|\[Revision|$)/u', '', $orig_notes);
            $cleaned_notes = trim($cleaned_notes);

            $updated_notes = $rating_summary;
            if (!empty($cleaned_notes)) {
                $updated_notes .= "\n\n" . $cleaned_notes;
            }
            if (!empty($requester_notes)) {
                $updated_notes .= "\n\n[Revision " . date('m/d/Y') . "]: " . $requester_notes;
            }
        }
    } else {
        // Non-CR Document Revision: Process optional or required file uploads
        $uploaded_files_list = [];
        $total_bytes = 0;
        $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];
        $uploads_dir = __DIR__ . '/uploads/';
        if (!is_dir($uploads_dir)) @mkdir($uploads_dir, 0755, true);

        $files_to_process = [];
        if (!empty($_FILES['attachments']['name'])) {
            if (is_array($_FILES['attachments']['name'])) {
                foreach ($_FILES['attachments']['name'] as $idx => $fname) {
                    if (isset($_FILES['attachments']['error'][$idx]) && $_FILES['attachments']['error'][$idx] === UPLOAD_ERR_OK && !empty($fname)) {
                        $files_to_process[] = [
                            'name'     => $fname,
                            'tmp_name' => $_FILES['attachments']['tmp_name'][$idx],
                            'size'     => $_FILES['attachments']['size'][$idx]
                        ];
                    }
                }
            } elseif ($_FILES['attachments']['error'] === UPLOAD_ERR_OK) {
                $files_to_process[] = [
                    'name'     => $_FILES['attachments']['name'],
                    'tmp_name' => $_FILES['attachments']['tmp_name'],
                    'size'     => $_FILES['attachments']['size']
                ];
            }
        } elseif (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $files_to_process[] = [
                'name'     => $_FILES['attachment']['name'],
                'tmp_name' => $_FILES['attachment']['tmp_name'],
                'size'     => $_FILES['attachment']['size']
            ];
        }

        foreach ($files_to_process as $i => $f) {
            $file_orig_name = preg_replace('/[\'"`\\\\;<>^~#$%&*+=\[\]{}|]/', '_', basename($f['name']));
            $file_size = (int)$f['size'];
            $file_ext = strtolower(pathinfo($file_orig_name, PATHINFO_EXTENSION));

            if (!in_array($file_ext, $allowed_exts)) {
                $error_msg = "Invalid file type (.{$file_ext}) for '{$file_orig_name}'. Allowed formats: PDF, JPG, PNG.";
                break;
            } elseif ($file_size > 10 * 1024 * 1024) {
                $error_msg = "File '{$file_orig_name}' is too large. Maximum allowed size is 10MB per file.";
                break;
            } else {
                $unique_name = time() . '_' . $i . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file_orig_name);
                $destination = $uploads_dir . $unique_name;
                if (move_uploaded_file($f['tmp_name'], $destination)) {
                    $f_size_fmt = $file_size > 1024 * 1024 
                        ? round($file_size / (1024 * 1024), 1) . ' MB' 
                        : round($file_size / 1024) . ' KB';
                    $uploaded_files_list[] = [
                        'name' => $file_orig_name,
                        'file' => $unique_name,
                        'size' => $f_size_fmt
                    ];
                    $total_bytes += $file_size;
                } else {
                    $error_msg = "Failed to save uploaded file '{$file_orig_name}'. Please try again.";
                    break;
                }
            }
        }

        if (empty($error_msg) && !empty($uploaded_files_list)) {
            $has_new_file = true;
            $uploaded_file_name = json_encode($uploaded_files_list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (count($uploaded_files_list) === 1) {
                $file_size_formatted = $uploaded_files_list[0]['size'];
            } else {
                $total_fmt = $total_bytes > 1024 * 1024 
                    ? round($total_bytes / (1024 * 1024), 1) . ' MB' 
                    : round($total_bytes / 1024) . ' KB';
                $file_size_formatted = count($uploaded_files_list) . ' Files (' . $total_fmt . ')';
            }
        }

        if (empty($error_msg)) {
            if (!$has_new_file && empty($uploaded_file_name) && empty($requester_notes)) {
                $error_msg = "Please attach the required document or provide explanation notes for revision.";
            } else {
                $updated_notes = $req['additional_notes'];
                if (!empty($requester_notes)) {
                    $updated_notes = trim(($req['additional_notes'] ? $req['additional_notes'] . "\n" : '') . "[Revision " . date('m/d/Y') . "]: " . $requester_notes);
                }
            }
        }
    }

    if (empty($error_msg)) {
        $now_str = date('F j, Y g:i A');
        if ($is_cr) {
            $new_remarks = !empty($requester_notes) 
                ? "Awaiting Admin Verification: Revised 3-Year Performance Ratings submitted on {$now_str}. Note: {$requester_notes}"
                : "Awaiting Admin Verification: Revised 3-Year Performance Ratings submitted on {$now_str}. Administrator will cross-reference points with official school records.";
        } else {
            $new_remarks = !empty($requester_notes) 
                ? "Requester re-submitted revision on {$now_str}. Note: {$requester_notes}"
                : "Revised documents submitted by requester on {$now_str}. Ready for re-evaluation.";
        }

        // Update request keeping tracking_number and request_type_id intact
        $update_sql = "UPDATE requests SET 
            attached_filename = ?, 
            file_size_kb = ?, 
            additional_notes = ?, 
            status = 'Processing', 
            current_stage = 2, 
            stage2_time = ?, 
            remarks = ? 
            WHERE id = ?";
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute([
            $uploaded_file_name,
            $file_size_formatted,
            $updated_notes,
            $now_str,
            $new_remarks,
            $req['id']
        ]);

        // Audit log
        $audit_action = $is_cr 
            ? "Re-submitted corrected 3-Year Performance Ratings for {$req['tracking_number']}"
            : "Re-uploaded revised documents for {$req['tracking_number']}";
        log_audit_action($pdo, $user_name, $user_role, $audit_action);

        // Notify staff
        if ($req['requester_role'] === 'teacher') {
            $staff_stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'Active'");
            $target_loc = 'Faculty Request Management';
            $office_sender = 'Administrator Office';
        } else {
            $staff_stmt = $pdo->query("SELECT id FROM users WHERE role = 'registrar' AND status = 'Active'");
            $target_loc = 'Assigned Requests';
            $office_sender = 'Office of the Registrar';
        }

        $staff_title = $is_cr ? "Revised Ratings Submitted: {$req['tracking_number']}" : "Revision Submitted for {$req['tracking_number']}";
        $staff_body = $is_cr 
            ? "{$user_name} submitted revised 3-Year Performance Ratings for Certificate of Rating. Please cross-reference and verify points against school records."
            : "{$user_name} re-uploaded the required documents for {$req['request_type_name']}.";

        $notif_insert = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message, location) VALUES (?, ?, ?, ?, ?)");
        foreach ($staff_stmt->fetchAll() as $staff) {
            $notif_insert->execute([
                $staff['id'],
                $req['tracking_number'],
                $staff_title,
                $staff_body,
                $target_loc
            ]);
        }

        // Confirmation notification for user
        $user_notif_title = $is_cr ? "Ratings Resubmitted: {$req['tracking_number']}" : "Revision Submitted: {$req['tracking_number']}";
        $user_notif_body = $is_cr 
            ? "Your corrected 3-Year Performance Ratings for {$req['request_type_name']} have been submitted and are now Awaiting Admin Verification."
            : "Your updated documents for {$req['request_type_name']} have been submitted and are now being processed.";

        $notif_insert->execute([
            $req['user_id'],
            $req['tracking_number'],
            $user_notif_title,
            $user_notif_body,
            'My Requests'
        ]);

        // Email to Requester
        if (!empty($req['requester_email'])) {
            $email_desc = $is_cr
                ? "Your updated 3-Year Performance Ratings have been submitted and are now awaiting verification by the Administrator Office."
                : "Your updated documents have been submitted and are now being processed by the office.";
            send_request_status_email(
                $req['requester_email'],
                $req['full_name'],
                $req['tracking_number'],
                $req['request_type_name'],
                'Processing',
                $email_desc,
                $office_sender
            );
        }

        $success_msg = $is_cr
            ? "Your revised 3-Year Performance Ratings for <strong>{$req['tracking_number']}</strong> have been successfully submitted! The request status has been updated to <strong>Processing (Awaiting Admin Verification)</strong>."
            : "Your revised documents for <strong>{$req['tracking_number']}</strong> have been successfully submitted! The request status has been updated to <strong>Processing</strong>, and a real-time email confirmation has been sent to your registered inbox.";

        // Refresh request record
        $stmt = $pdo->prepare("SELECT r.*, rt.name as request_type_name, rt.code as request_type_code, rt.requirements, u.full_name, u.email as requester_email, u.role as requester_role 
            FROM requests r 
            JOIN request_types rt ON r.request_type_id = rt.id 
            JOIN users u ON r.user_id = u.id 
            WHERE r.id = ? LIMIT 1");
        $stmt->execute([$req['id']]);
        $req = $stmt->fetch();
    }
}

$status_class = strtolower(str_replace(' ', '-', $req['status']));
?>

<div class="content-card" style="max-width: 850px; margin: 0 auto;">
  <?php if (!empty($success_msg)): ?>
    <div class="alert-success-banner" style="margin-bottom: 22px;">
      <div style="font-weight: 800; font-size: 1.05rem; margin-bottom: 4px;">✅ <?= $is_cr ? 'Ratings Resubmitted Successfully!' : 'Re-upload Successful!' ?></div>
      <div><?= $success_msg ?></div>
      <div style="margin-top: 14px; display: flex; gap: 10px;">
        <a href="my_requests.php" class="btn-primary-cta" style="padding: 7px 18px; font-size: 0.85rem; text-decoration: none;">Back to My Requests</a>
        <button type="button" class="btn-action-track" onclick="openTrackerModal('<?= htmlspecialchars($req['tracking_number'], ENT_QUOTES) ?>')">Track Request Status</button>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($error_msg)): ?>
    <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 12px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem;">
      <strong>Error:</strong> <?= htmlspecialchars($error_msg) ?>
    </div>
  <?php endif; ?>

  <!-- Revision Alert Callout (Highlights office feedback) -->
  <div style="background: #fffbeb; border: 1.5px solid #f59e0b; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px;">
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 6px;">
      <div style="font-weight: 800; color: #b45309; font-size: 1rem; display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 1.2rem;">⚠️</span> <?= ($req['requester_role'] === 'teacher') ? 'Administrator Revision Instructions' : 'Registrar / Office Revision Instructions' ?>
      </div>
      <span class="badge-status <?= $status_class ?>"><?= htmlspecialchars($req['status']) ?></span>
    </div>
    <div style="font-size: 0.92rem; color: #78350f; line-height: 1.5; background: #fef3c7; padding: 10px 14px; border-radius: 8px; margin-top: 8px;">
      <strong>Remarks:</strong> <?= htmlspecialchars($req['remarks'] ?: 'Please update the requested details.') ?>
    </div>
    <div style="font-size: 0.82rem; color: #92400e; margin-top: 8px;">
      <?php if ($is_cr): ?>
        Mangyaring i-update at ilagay ang tamang rating points sa ibaba ayon sa inyong opisyal na record o koordinasyon sa inyong Head Coordinator. Hindi na kailangan mag-attach ng files.
      <?php else: ?>
        Please attach the corrected document below. Your Request ID (<strong><?= htmlspecialchars($req['tracking_number']) ?></strong>) and original request details will remain unchanged.
      <?php endif; ?>
    </div>
  </div>

  <!-- Locked Request Details (Read-only for integrity) -->
  <div class="details-grid" style="margin-bottom: 24px; background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0;">
    <div>
      <div class="details-item-label">Request ID</div>
      <div class="details-item-val" style="color: #0284c7; font-weight: 800;"><?= htmlspecialchars($req['tracking_number']) ?></div>
    </div>
    <div>
      <div class="details-item-label">Document Type</div>
      <div class="details-item-val"><?= htmlspecialchars($req['request_type_name']) ?></div>
    </div>
    <div>
      <div class="details-item-label">Date Submitted</div>
      <div class="details-item-val"><?= date('F j, Y', strtotime($req['created_at'])) ?></div>
    </div>
    <div>
      <div class="details-item-label">Original Purpose</div>
      <div class="details-item-val"><?= htmlspecialchars($req['purpose']) ?></div>
    </div>
    <?php $existing_atts = parse_attachments($req['attached_filename'] ?? '', $req['file_size_kb'] ?? ''); ?>
    <div style="grid-column: span 2;">
      <div class="details-item-label">Currently Attached Document(s)</div>
      <div class="details-item-val" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
        <?php if (!empty($existing_atts)): ?>
          <?php foreach ($existing_atts as $att): ?>
            <a href="view_file.php?ref=<?= urlencode($req['tracking_number']) ?>&file=<?= urlencode($att['file']) ?>" target="_blank" class="attachment-preview-btn" style="text-decoration: none;">
              📎 <?= htmlspecialchars($att['name']) ?> (<?= htmlspecialchars($att['size']) ?>) ↗
            </a>
          <?php endforeach; ?>
        <?php else: ?>
          <span style="color: #94a3b8;"><?= $is_cr ? 'None required (Ratings-based verification)' : 'No file previously attached' ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Revision Form -->
  <form method="POST" enctype="multipart/form-data">
    <?php if ($is_cr): ?>
      <!-- 3-Year Performance Ratings Inputs for Certificate of Rating -->
      <div style="background: #f0fdf4; border: 2px solid #86efac; border-radius: 14px; padding: 18px 20px; margin-bottom: 22px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 14px;">
          <div style="font-weight: 800; color: #166534; font-size: 1.02rem; display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 1.3rem;">⭐</span> 3-Year Performance Ratings (IPCRF Points)
          </div>
          <div id="rating-avg-badge" style="background: #166534; color: #ffffff; padding: 4px 14px; border-radius: 20px; font-weight: 700; font-size: 0.85rem; <?= (!empty($cr_r1) && !empty($cr_r2) && !empty($cr_r3)) ? '' : 'display: none;' ?>">
            3-Yr Avg: <span id="avg-val">0.00</span>
          </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px;">
          <!-- Year 1 -->
          <div style="background: #ffffff; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px;">
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Year 1 School Year</label>
            <input type="text" name="sy_year_1" id="sy_year_1" class="form-control" value="<?= htmlspecialchars($cr_sy1) ?>" placeholder="e.g. 2022–2023" style="font-size: 0.88rem; margin-bottom: 6px;">
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Numerical Rating (Points) <span style="color: #ef4444;">*</span></label>
            <input type="number" step="0.01" min="1.00" max="5.00" name="rating_year_1" id="rating_year_1" class="form-control" value="<?= htmlspecialchars($cr_r1) ?>" placeholder="e.g. 4.32" oninput="calculateRatingAvg()" required style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">
          </div>

          <!-- Year 2 -->
          <div style="background: #ffffff; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px;">
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Year 2 School Year</label>
            <input type="text" name="sy_year_2" id="sy_year_2" class="form-control" value="<?= htmlspecialchars($cr_sy2) ?>" placeholder="e.g. 2023–2024" style="font-size: 0.88rem; margin-bottom: 6px;">
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Numerical Rating (Points) <span style="color: #ef4444;">*</span></label>
            <input type="number" step="0.01" min="1.00" max="5.00" name="rating_year_2" id="rating_year_2" class="form-control" value="<?= htmlspecialchars($cr_r2) ?>" placeholder="e.g. 4.38" oninput="calculateRatingAvg()" required style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">
          </div>

          <!-- Year 3 -->
          <div style="background: #ffffff; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px;">
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Year 3 School Year</label>
            <input type="text" name="sy_year_3" id="sy_year_3" class="form-control" value="<?= htmlspecialchars($cr_sy3) ?>" placeholder="e.g. 2024–2025" style="font-size: 0.88rem; margin-bottom: 6px;">
            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Numerical Rating (Points) <span style="color: #ef4444;">*</span></label>
            <input type="number" step="0.01" min="1.00" max="5.00" name="rating_year_3" id="rating_year_3" class="form-control" value="<?= htmlspecialchars($cr_r3) ?>" placeholder="e.g. 4.40" oninput="calculateRatingAvg()" required style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">
          </div>
        </div>

        <div style="margin-top: 12px; font-size: 0.82rem; color: #166534; display: flex; align-items: flex-start; gap: 6px; line-height: 1.45;">
          <span>ℹ️</span> <em>Hindi na kailangan mag-attach ng anumang file para sa Certificate of Rating. Ilagay lamang ang tamang points na na-coordinate sa inyong Subject o Head Coordinator upang ma-verify muli ng Administrator.</em>
        </div>
      </div>
    <?php else: ?>
      <!-- File Attachment section for non-CR documents -->
      <div class="form-group">
        <label class="form-label">
          Upload Corrected / Supporting Document(s) <span style="font-weight: normal; color: #64748b;">(Pwede mag-attach ng kahit ilan / Multiple Documents — PDF, JPG, PNG)</span>
        </label>
        <div style="display: flex; align-items: center; gap: 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px 14px; flex-wrap: wrap;">
          <label for="attachment" style="background: #e2e8f0; border: 1px solid #cbd5e1; padding: 8px 18px; border-radius: 6px; font-size: 0.85rem; font-weight: 700; cursor: pointer; color: #1e293b; display: inline-flex; align-items: center; gap: 6px;">
            📁 Browse Document(s)...
          </label>
          <input type="file" name="attachments[]" id="attachment" multiple style="display: none;" onchange="handleFilesSelected(this)" />
          <span id="file-chosen-text" style="font-size: 0.9rem; color: #64748b;">No new file selected yet. You can select multiple documents at once.</span>
        </div>
        <div id="file-list-preview" style="display: none; margin-top: 10px; gap: 6px; flex-wrap: wrap;"></div>

        <!-- Smart Guard Bar -->
        <div id="smart-guard-box" class="smart-guard-box" style="margin-top: 10px;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
          <span id="smart-guard-text">Smart Guard: Select clear replacement or supporting document(s) to validate format and size.</span>
        </div>
      </div>
    <?php endif; ?>

    <div class="form-group">
      <label class="form-label">Requester Notes / Explanation <span style="font-weight: normal; color: #64748b;">(Optional)</span></label>
      <textarea 
        name="requester_notes" 
        class="form-control" 
        rows="3" 
        placeholder="<?= $is_cr ? 'Halimbawa: Na-check at na-confirm na po sa Head Coordinator ang tamang points...' : 'Explain any corrections made or details requested by the office...' ?>"
      ></textarea>
    </div>

    <div style="display: flex; gap: 12px; margin-top: 25px; align-items: center;">
      <button type="submit" class="btn-primary-cta" style="background: #059669; padding: 10px 24px; font-size: 0.95rem;">
        <?= $is_cr ? '✔ Submit Corrected Ratings' : '✔ Submit Revised Document' ?>
      </button>
      <a href="my_requests.php" class="btn-primary-cta" style="background: #ffffff; color: #475569; border: 1px solid #cbd5e1; box-shadow: none; text-decoration: none; padding: 10px 20px;">
        ✕ Cancel
      </a>
    </div>
  </form>
</div>

<script>
function calculateRatingAvg() {
  const r1 = parseFloat(document.getElementById('rating_year_1')?.value) || 0;
  const r2 = parseFloat(document.getElementById('rating_year_2')?.value) || 0;
  const r3 = parseFloat(document.getElementById('rating_year_3')?.value) || 0;
  const badge = document.getElementById('rating-avg-badge');
  const avgVal = document.getElementById('avg-val');

  if (r1 > 0 && r2 > 0 && r3 > 0) {
    const avg = ((r1 + r2 + r3) / 3).toFixed(2);
    let desc = 'Satisfactory';
    if (avg >= 4.50) desc = 'Outstanding';
    else if (avg >= 3.50) desc = 'Very Satisfactory';
    else if (avg < 2.50) desc = 'Unsatisfactory';

    if (avgVal) avgVal.textContent = `${avg} (${desc})`;
    if (badge) badge.style.display = 'inline-block';
  } else {
    if (badge) badge.style.display = 'none';
  }
}

let selectedFiles = [];

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function sanitizeFileName(name) {
  return name.replace(/['"`\\;<>^~#$%&*+=[\]{}|]/g, '_');
}

function handleFilesSelected(input) {
  const newFiles = Array.from(input.files || []);
  if (newFiles.length === 0) return;

  newFiles.forEach(nf => {
    let fileToAdd = nf;
    if (/['"`\\;<>^~#$%&*+=[\]{}|]/.test(nf.name)) {
      const cleanName = sanitizeFileName(nf.name);
      try {
        fileToAdd = new File([nf], cleanName, { type: nf.type, lastModified: nf.lastModified });
      } catch (e) {
        fileToAdd = nf;
      }
    }
    const exists = selectedFiles.some(f => f.name === fileToAdd.name && f.size === fileToAdd.size);
    if (!exists) {
      selectedFiles.push(fileToAdd);
    }
  });

  syncAndRenderFiles();
}

function removeSelectedFile(index) {
  selectedFiles.splice(index, 1);
  syncAndRenderFiles();
}

function syncAndRenderFiles() {
  const input = document.getElementById('attachment');
  const label = document.getElementById('file-chosen-text');
  const box = document.getElementById('smart-guard-box');
  const text = document.getElementById('smart-guard-text');
  const preview = document.getElementById('file-list-preview');

  if (!input) return;

  if (window.DataTransfer) {
    const dt = new DataTransfer();
    selectedFiles.forEach(f => {
      let fileToAdd = f;
      if (/['"`\\;<>^~#$%&*+=[\]{}|]/.test(f.name)) {
        try {
          fileToAdd = new File([f], sanitizeFileName(f.name), { type: f.type, lastModified: f.lastModified });
        } catch (e) {}
      }
      dt.items.add(fileToAdd);
    });
    input.files = dt.files;
  }

  if (selectedFiles.length === 0) {
    input.value = '';
    if (label) label.textContent = 'No new file selected yet. You can select multiple documents at once.';
    if (preview) { preview.innerHTML = ''; preview.style.display = 'none'; }
    if (box) {
      box.style.backgroundColor = '#d1fae5';
      box.style.borderColor = '#6ee7b7';
      box.style.color = '#065f46';
    }
    if (text) text.textContent = 'Smart Guard: Select clear replacement or supporting document(s) to validate format and size.';
    return;
  }

  const allowed = ['pdf', 'jpg', 'jpeg', 'png'];
  let totalBytes = 0;
  let fileListHtml = '';
  let hasError = false;
  let errorMsg = '';

  selectedFiles.forEach((f, idx) => {
    const ext = f.name.split('.').pop().toLowerCase();
    totalBytes += f.size;

    if (!allowed.includes(ext)) {
      hasError = true;
      errorMsg = `Invalid format for "${f.name}". Allowed formats: PDF, JPG, PNG. ❌`;
    } else if (f.size > 10 * 1024 * 1024) {
      hasError = true;
      errorMsg = `File "${f.name}" exceeds 10MB limit. ❌`;
    }

    const sz = f.size > 1024 * 1024 ? (f.size / (1024 * 1024)).toFixed(1) + ' MB' : Math.round(f.size / 1024) + ' KB';
    fileListHtml += `
      <div style="display: inline-flex; align-items: center; gap: 8px; background: #ffffff; border: 1.5px solid #cbd5e1; padding: 5px 10px; border-radius: 8px; font-size: 0.84rem; color: #1e293b; margin-right: 6px; margin-bottom: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <span>📄 <strong>${escapeHtml(f.name)}</strong> <span style="color: #64748b;">(${sz})</span></span>
        <button type="button" onclick="removeSelectedFile(${idx})" title="Alisin ang dokumentong ito" style="background: #fee2e2; border: 1px solid #fca5a5; color: #dc2626; border-radius: 50%; width: 20px; height: 20px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 800; cursor: pointer; padding: 0; line-height: 1;">✕</button>
      </div>
    `;
  });

  if (hasError) {
    if (label) label.textContent = `${selectedFiles.length} document(s) chosen`;
    if (preview) { preview.innerHTML = fileListHtml; preview.style.display = 'flex'; }
    if (box) {
      box.style.backgroundColor = '#fee2e2';
      box.style.borderColor = '#fca5a5';
      box.style.color = '#b91c1c';
    }
    if (text) text.innerHTML = `<strong>Smart Guard Warning:</strong> ${errorMsg}`;
    return;
  }

  const totalFmt = totalBytes > 1024 * 1024 ? (totalBytes / (1024 * 1024)).toFixed(1) + ' MB' : Math.round(totalBytes / 1024) + ' KB';
  if (label) label.innerHTML = `<strong>${selectedFiles.length} ${selectedFiles.length > 1 ? 'Documents' : 'Document'} Selected</strong> (Total: ${totalFmt})`;
  if (preview) {
    preview.innerHTML = fileListHtml;
    preview.style.display = 'flex';
  }
  if (box) {
    box.style.backgroundColor = '#dcfce7';
    box.style.borderColor = '#86efac';
    box.style.color = '#15803d';
  }
  if (text) text.innerHTML = `<strong>Smart Guard:</strong> All ${selectedFiles.length} document(s) validated successfully. (Total size: ${totalFmt}) ✅`;
}

// Auto calculate on load if ratings exist
document.addEventListener('DOMContentLoaded', function() {
  calculateRatingAvg();

  const form = document.querySelector('form');
  if (form) {
    form.addEventListener('submit', function() {
      const input = document.getElementById('attachment');
      if (input && window.DataTransfer && selectedFiles.length > 0) {
        const dt = new DataTransfer();
        selectedFiles.forEach(f => {
          let fileToAdd = f;
          if (/['"`\\;<>^~#$%&*+=[\]{}|]/.test(f.name)) {
            try {
              fileToAdd = new File([f], sanitizeFileName(f.name), { type: f.type, lastModified: f.lastModified });
            } catch(e) {}
          }
          dt.items.add(fileToAdd);
        });
        input.files = dt.files;
      }
    });
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
