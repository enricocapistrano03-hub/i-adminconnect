<?php
$page_title_custom = '<div class="breadcrumb-nav">Dashboard &gt; New Request</div><h1>Submit a New Request</h1><p>Select the document type to see the specific requirements.</p>';
require_once __DIR__ . '/includes/header.php';

$success_msg = '';
$error_msg = '';

$is_teacher = ($user_role === 'teacher');

// Role-specific field labels and placeholders
if ($is_teacher) {
    $purpose_label = 'Purpose / Reason <span style="color: #ef4444;">*</span>';
    $purpose_placeholder = 'For Update Service Credit / Absent';
    $purpose_hint = '';

    $notes_label = 'Additional Notes & Specific Instructions <span style="font-weight: normal; color: #64748b;">(Optional)</span>';
    $notes_placeholder = 'e.g., Inclusive dates or school year, special instructions...';
    $notes_hint = '';
} else {
    $purpose_label = 'Purpose / Reason <span style="color: #ef4444;">*</span>';
    $purpose_placeholder = 'e.g., College Admission, Scholarship Application, Transfer to Another School, Employment, etc.';
    $purpose_hint = 'Specify the purpose of this request (e.g., College Entrance Exam, Scholarship, Transferee).';

    $notes_label = 'Additional Notes <span style="font-weight: normal; color: #64748b;">(RECENT SECTION, SPECIFIC SCHOOL / TRANSFEREE UNIVERSITY)</span>';
    $notes_placeholder = 'e.g., Section 10-Einstein, transferring to PUP Manila, special instructions...';
    $notes_hint = 'Mention your recent grade level, section, school year, or destination university/company.';
}


// Available document types for this user role
$stmt = $pdo->prepare("SELECT * FROM request_types WHERE target_role = ? OR target_role = 'both' ORDER BY id ASC");
$stmt->execute([$user_role]);
$available_types = $stmt->fetchAll();

// Fetch student's LRN / ID Number
$user_lrn = $_SESSION['id_number'] ?? '';
if (empty($user_lrn) && !empty($user_id)) {
    $u_stmt = $pdo->prepare("SELECT id_number FROM users WHERE id = ? LIMIT 1");
    $u_stmt->execute([$user_id]);
    $user_lrn = $u_stmt->fetchColumn() ?: '';
}

// Check if student has an active disciplinary hold with Guidance Office (Restricts Good Moral Certificate)
$has_guidance_hold = false;
$guidance_hold_record = null;
if (!empty($user_lrn) || !empty($user_name)) {
    $hold_stmt = $pdo->prepare("SELECT * FROM disciplinary_records WHERE status = 'Active Hold' AND (lrn = ? OR student_name = ? OR student_name LIKE ?) LIMIT 1");
    $hold_stmt->execute([$user_lrn, $user_name, "%{$user_name}%"]);
    $guidance_hold_record = $hold_stmt->fetch();
    if ($guidance_hold_record) {
        $has_guidance_hold = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $doc_type_id = (int)($_POST['doc_type_id'] ?? 0);
    $raw_copies = $_POST['copies'] ?? '1';
    $copies = max(1, min(10, (int)$raw_copies));
    $purpose = trim($_POST['purpose'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Strict validation for copies (digits only between 1 and 10)
    if (!preg_match('/^[0-9]+$/', (string)$raw_copies) || (int)$raw_copies < 1 || (int)$raw_copies > 10) {
        $error_msg = 'Number of copies must be a valid number between 1 and 10 (letters and symbols are not allowed).';
    }

    // Identify selected document type code
    $doc_code = '';
    $selected_type_name = '';
    foreach ($available_types as $t) {
        if ((int)$t['id'] === $doc_type_id) {
            $doc_code = $t['code'] ?? '';
            $selected_type_name = $t['name'] ?? '';
            break;
        }
    }

    // Guidance Disciplinary Hold Enforcement for Good Moral Certificate (GMC)
    if (empty($error_msg) && $doc_code === 'GMC') {
        $chk_hold = $pdo->prepare("SELECT * FROM disciplinary_records WHERE status = 'Active Hold' AND (lrn = ? OR student_name = ? OR student_name LIKE ?) LIMIT 1");
        $chk_hold->execute([$user_lrn, $user_name, "%{$user_name}%"]);
        $active_hold = $chk_hold->fetch();
        if ($active_hold) {
            $error_msg = "Cannot submit Good Moral Certificate: You currently have an Active Disciplinary Hold on file with the Guidance and Counseling Office (\"" . htmlspecialchars($active_hold['case_title']) . "\"). Please coordinate with the Guidance Office to settle and clear your record before requesting a Good Moral Certificate.";
        }
    }

    // Special validation & processing for Certificate of Employment (COE) and Service Record (SR)
    if (empty($error_msg) && ($doc_code === 'COE' || $doc_code === 'SR')) {
        $emp_full_name = trim($_POST['emp_full_name'] ?? '');
        $emp_number = trim($_POST['emp_number'] ?? '');
        $emp_position = trim($_POST['emp_position'] ?? '');
        $emp_salary_grade = trim($_POST['emp_salary_grade'] ?? '');
        $emp_step = trim($_POST['emp_step'] ?? '');
        $emp_date_hired = trim($_POST['emp_date_hired'] ?? '');
        $emp_purpose = trim($_POST['emp_purpose'] ?? '');
        $emp_with_comp = trim($_POST['emp_with_compensation'] ?? 'Yes');
        $emp_notes = trim($_POST['emp_notes'] ?? '');

        if (empty($emp_full_name) || empty($emp_number) || empty($emp_position) || empty($emp_salary_grade) || empty($emp_step) || empty($emp_date_hired) || empty($emp_purpose)) {
            $error_msg = 'Please fill in all required employee fields (Full Name, Employee Number, Position, Salary Grade, Step, Date Hired, and Purpose).';
        } elseif (!preg_match("/^[a-zA-Z\sñÑ.'-]+$/u", $emp_full_name)) {
            $error_msg = 'Employee Full Name must only contain letters, spaces, hyphens, and periods (numbers and special symbols are strictly not allowed).';
        } elseif (!preg_match('/^[0-9-]+$/', $emp_number)) {
            $error_msg = 'Employee Number must contain numbers and hyphens only (letters are strictly not allowed).';
        } else {
            $purpose = $emp_purpose;

            if ($doc_code === 'COE') {
                $coe_summary = "[Certificate of Employment (COE) Details]\n"
                    . "• Full Name: " . $emp_full_name . "\n"
                    . "• Employee Number: " . $emp_number . "\n"
                    . "• Position: " . $emp_position . "\n"
                    . "• Salary Grade: " . $emp_salary_grade . "\n"
                    . "• Step: " . $emp_step . "\n"
                    . "• Date Hired: " . $emp_date_hired . "\n"
                    . "• Purpose: " . $emp_purpose . "\n"
                    . "• With Compensation: " . $emp_with_comp;

                $notes = empty($emp_notes) ? $coe_summary : $coe_summary . "\n\nAdditional Notes: " . $emp_notes;
            } else {
                // Service Record (SR) - Excludes With Compensation as requested
                $sr_summary = "[Service Record (SR) Details]\n"
                    . "• Full Name: " . $emp_full_name . "\n"
                    . "• Employee Number: " . $emp_number . "\n"
                    . "• Position: " . $emp_position . "\n"
                    . "• Salary Grade: " . $emp_salary_grade . "\n"
                    . "• Step: " . $emp_step . "\n"
                    . "• Date Hired: " . $emp_date_hired . "\n"
                    . "• Purpose: " . $emp_purpose;

                $notes = empty($emp_notes) ? $sr_summary : $sr_summary . "\n\nAdditional Notes: " . $emp_notes;
            }
        }
    }

    // Special validation & processing for Certificate of Rating (CR) - 3-Year Performance Ratings
    if (empty($error_msg) && $doc_code === 'CR') {
        $sy1 = trim($_POST['sy_year_1'] ?? '2022–2023');
        $sy2 = trim($_POST['sy_year_2'] ?? '2023–2024');
        $sy3 = trim($_POST['sy_year_3'] ?? '2024–2025');
        $r1 = (float)($_POST['rating_year_1'] ?? 0);
        $r2 = (float)($_POST['rating_year_2'] ?? 0);
        $r3 = (float)($_POST['rating_year_3'] ?? 0);

        if ($r1 <= 0 || $r2 <= 0 || $r3 <= 0 || $r1 > 5.00 || $r2 > 5.00 || $r3 > 5.00) {
            $error_msg = 'Please enter valid numerical rating grades between 1.00 and 5.00 for all three (3) School Years (e.g. 4.32, 4.38, 4.40).';
        } else {
            $avg_rating = round(($r1 + $r2 + $r3) / 3, 2);
            $adjectival = ($avg_rating >= 4.50) ? 'Outstanding' : (($avg_rating >= 3.50) ? 'Very Satisfactory' : 'Satisfactory');

            $rating_summary = "[3-Year Performance Ratings]\n"
                . "• SY {$sy1}: " . number_format($r1, 2) . "\n"
                . "• SY {$sy2}: " . number_format($r2, 2) . "\n"
                . "• SY {$sy3}: " . number_format($r3, 2) . "\n"
                . "• 3-Year Average: " . number_format($avg_rating, 2) . " ({$adjectival})";

            $notes = empty($notes) ? $rating_summary : $rating_summary . "\n\nAdditional Notes: " . $notes;
        }
    }

    // Special validation & processing for Form 137 / SF10
    if (empty($error_msg) && $doc_code === 'F137') {
        $recent_sec = trim($_POST['f137_recent_section'] ?? '');
        $grad_yr = trim($_POST['f137_year_graduated'] ?? '');
        $trans_school = trim($_POST['f137_transferee_school'] ?? '');

        // Handle "Others" or custom transferee school selection
        if ($trans_school === 'Others (Not in Top 100 List / Please specify below)' || $trans_school === 'Others' || stripos($trans_school, 'Others') !== false) {
            $custom_other = trim($_POST['f137_other_school_input'] ?? '');
            if (empty($custom_other)) {
                $error_msg = 'Please specify the Complete School Name and Campus Address for your Transferee School under "Others".';
            } else {
                $trans_school = "Others: " . $custom_other;
            }
        }

        if (empty($error_msg)) {
            if (empty($recent_sec) || empty($grad_yr) || empty($trans_school)) {
                $error_msg = 'Please fill in your Recent Section, Year Graduated / Last SY, and Specific School / Transferee University for Form 137 processing.';
            } elseif (!preg_match('/^[0-9]{4}$/', $grad_yr)) {
                // Panel Feedback: Reject inputs like '2026a' with clear error
                $error_msg = 'Invalid Year format for Form 137 ("' . htmlspecialchars($grad_yr) . '"). Please enter a valid 4-digit numeric year (e.g. 2024, 2026). Letters and special characters are strictly not allowed.';
            } elseif ((int)$grad_yr < 1970 || (int)$grad_yr > ((int)date('Y') + 2)) {
                $error_msg = 'Year Graduated / Last SY must be between 1970 and ' . ((int)date('Y') + 2) . '.';
            } else {
                $f137_summary = "[Form 137 Student Records Info]\n"
                    . "• Recent Section: " . $recent_sec . "\n"
                    . "• Year Graduated / Last SY: " . $grad_yr . "\n"
                    . "• Specific School / Transferee University: " . $trans_school;

                $notes = empty($notes) ? $f137_summary : $f137_summary . "\n\nAdditional Notes: " . $notes;
            }
        }
    }

    // Special validation & processing for Good Moral Certificate (GMC), Certificate of Grades (COG), and Certificate of Enrollment (COE_ST)
    if (empty($error_msg) && in_array($doc_code, ['GMC', 'COG', 'COE_ST'])) {
        $gmc_name = trim($_POST['gmc_full_name'] ?? '');
        $gmc_sec = trim($_POST['gmc_section_strand'] ?? '');
        $gmc_status = trim($_POST['gmc_status'] ?? 'Currently Enrolled');
        $gmc_year = trim($_POST['gmc_year_graduated'] ?? '');
        $gmc_purpose = trim($_POST['gmc_purpose_school'] ?? '');
        $gmc_notes = trim($_POST['gmc_notes'] ?? '');

        if (empty($gmc_name)) {
            $gmc_name = $user_name;
        }

        // Fallback to general purpose if entered
        if (empty($gmc_purpose) && !empty($purpose)) {
            $gmc_purpose = $purpose;
        }

        $doc_title_label = ($doc_code === 'GMC') ? 'Good Moral Certificate' : (($doc_code === 'COG') ? 'Certificate of Grades' : 'Certificate of Enrollment');

        if (empty($gmc_name) || empty($gmc_sec) || empty($gmc_purpose)) {
            $error_msg = "Please fill in your Full Name, Section / Strand, and Specific School / Purpose for the {$doc_title_label}.";
        } elseif (!preg_match("/^[a-zA-Z\sñÑ.'-]+$/u", $gmc_name)) {
            $error_msg = 'Student Full Name must only contain letters, spaces, hyphens, and periods (numbers and special symbols are strictly not allowed).';
        } elseif ($gmc_status === 'Graduated' && empty($gmc_year)) {
            $error_msg = 'Please specify your Year Graduated / Batch since you indicated you have graduated.';
        } elseif (!empty($gmc_year) && !preg_match('/^[0-9]{4}$/', $gmc_year)) {
            // Panel Feedback: Reject inputs like '2026a' with clear error
            $error_msg = 'Invalid Year format for ' . $doc_title_label . ' ("' . htmlspecialchars($gmc_year) . '"). Please enter a valid 4-digit numeric year (e.g. 2024, 2026). Letters and special characters are strictly not allowed.';
        } elseif (!empty($gmc_year) && ((int)$gmc_year < 1970 || (int)$gmc_year > ((int)date('Y') + 2))) {
            $error_msg = 'Year must be between 1970 and ' . ((int)date('Y') + 2) . '.';
        } else {
            $purpose = $gmc_purpose;

            $status_str = ($gmc_status === 'Graduated')
                ? ('Graduated / Alumnus' . (!empty($gmc_year) ? ' (Batch / Year: ' . $gmc_year . ')' : ''))
                : ('Currently Enrolled (Undergraduate)' . (!empty($gmc_year) ? ' [Expected: ' . $gmc_year . ']' : ''));

            $gmc_summary = "[{$doc_title_label} Details]\n"
                . "• Full Name: " . $gmc_name . "\n"
                . "• Section / Strand: " . $gmc_sec . "\n"
                . "• Student Status: " . $status_str . "\n"
                . "• Destination / Purpose: " . $gmc_purpose;

            if (!empty($gmc_notes)) {
                $gmc_summary .= "\n• Remarks: " . $gmc_notes;
            }

            $notes = empty($notes) ? $gmc_summary : $gmc_summary . "\n\nAdditional Notes: " . $notes;
        }
    }

    if (empty($error_msg) && ($doc_type_id <= 0 || empty($purpose))) {
        $error_msg = 'Please select a document type and fill in the purpose.';
    } elseif (empty($error_msg)) {
        $uploaded_file_name = null;
        $file_size_formatted = null;
        $file_verified = 0;
        $uploaded_files_list = [];
        $total_bytes = 0;
        $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];
        $uploads_dir = __DIR__ . '/uploads/';
        if (!is_dir($uploads_dir)) @mkdir($uploads_dir, 0755, true);

        // Standardize file arrays (supports multiple attachments[] or single attachment)
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

        // Validate and save files
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
                }
            }
        }

        if (empty($error_msg) && !empty($uploaded_files_list)) {
            $file_verified = 1;
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

        // Enforce strictly required attachment for Form 137 / SF10
        if ($doc_code === 'F137' && (empty($uploaded_files_list) || empty($uploaded_file_name))) {
            $error_msg = 'Kailangang mag-attach ng supporting document para sa Form 137 / SF10 (hal. Request Letter mula sa bagong paaralan o Valid ID). Hindi makakapag-proceed ang inyong request kapag walang kalakip na dokumento.';
        }

        if (empty($error_msg)) {
            // Generate next unique tracking number by extracting max numeric suffix
            // This avoids duplicate key errors with seeded data (RQ-001, RQ-010, etc.)
            $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(tracking_number, 4) AS UNSIGNED)) as max_num FROM requests WHERE tracking_number REGEXP '^RQ-[0-9]+$'");
            $max_num = (int)($stmt->fetchColumn() ?? 0) + 1;
            $tracking_num = 'RQ-' . str_pad($max_num, 3, '0', STR_PAD_LEFT);

            $now_str = date('F j, Y g:i A');

            // Custom remarks and status for Certificate of Rating (CR) vs general requests
            $initial_remarks = ($doc_code === 'CR')
                ? "Awaiting Admin Verification: 3-Year Performance Ratings submitted. Administrator will cross-reference points with official school records."
                : "Request received. Document is currently being processed and prepared.";

            // Automatic status set to 'Processing' (Stage 2 in 4-Stage Tracker) upon submission as requested by the panel
            $insert_sql = "INSERT INTO requests 
                (tracking_number, user_id, request_type_id, copies, purpose, additional_notes, attached_filename, file_size_kb, file_verified, status, current_stage, stage1_time, stage2_time, remarks) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Processing', 2, ?, ?, ?)";
            $stmt = $pdo->prepare($insert_sql);
            $stmt->execute([
                $tracking_num,
                $user_id,
                $doc_type_id,
                $copies,
                $purpose,
                $notes,
                $uploaded_file_name,
                $file_size_formatted,
                $file_verified,
                $now_str,
                $now_str,
                $initial_remarks
            ]);

            // Fetch request type name for audit log and notifications
            $stmt = $pdo->prepare("SELECT name FROM request_types WHERE id = ?");
            $stmt->execute([$doc_type_id]);
            $type_name = $stmt->fetchColumn() ?: $selected_type_name;

            // Audit log
            $copy_txt = $copies > 1 ? " ({$copies} copies)" : " (1 copy)";
            $doc_name_str = !empty($type_name) ? " for {$type_name}" : "";
            $log_desc = ($doc_code === 'CR')
                ? "Submitted Certificate of Rating {$tracking_num}{$copy_txt} (3-Yr Ratings Awaiting Admin Verification)"
                : "Submitted New Request {$tracking_num}{$doc_name_str}{$copy_txt} (Auto-Set to Processing)";
            log_audit_action($pdo, $user_name, $user_role, $log_desc);

            // Notify Active Registrars (for student) or Admins (for teacher)
            if ($user_role === 'teacher') {
                $staff_stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'Active'");
                $target_loc = 'Faculty Request Management';
                $target_office = 'Administrator';
            } else {
                $staff_stmt = $pdo->query("SELECT id FROM users WHERE role = 'registrar' AND status = 'Active'");
                $target_loc = 'Assigned Requests';
                $target_office = 'Office of the Registrar';
            }

            $admin_notif_title = ($doc_code === 'CR') ? "New CR Request - Verify Ratings ({$tracking_num})" : "New Request {$tracking_num} in Processing";
            $admin_notif_msg = ($doc_code === 'CR')
                ? "{$user_name} submitted a request for Certificate of Rating. Please cross-reference 3-Year Performance Ratings against records."
                : "{$user_name} submitted a request for {$type_name}{$copy_txt}. Auto-set to Processing.";

            $notif_insert = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message, location) VALUES (?, ?, ?, ?, ?)");
            foreach ($staff_stmt->fetchAll() as $staff) {
                $notif_insert->execute([
                    $staff['id'],
                    $tracking_num,
                    $admin_notif_title,
                    $admin_notif_msg,
                    $target_loc
                ]);
            }

            // In-app notification for requester
            $req_notif_msg = ($doc_code === 'CR')
                ? "Your request for Certificate of Rating has been submitted. Your 3-year performance ratings are currently Awaiting Administrative Verification."
                : "Your request for {$type_name}{$copy_txt} has been submitted to {$target_office} and is now being processed.";

            $notif_insert->execute([
                $user_id,
                $tracking_num,
                "Request {$tracking_num} Processing",
                $req_notif_msg,
                $target_office
            ]);

            // Real-Time Email Notification for requester
            require_once __DIR__ . '/includes/mailer.php';
            $u_stmt = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
            $u_stmt->execute([$user_id]);
            $u_email = $u_stmt->fetchColumn();
            if (!empty($u_email)) {
                $email_lead = ($doc_code === 'CR')
                    ? "Your request for Certificate of Rating has been received and is currently Awaiting Administrative Verification of your 3-year performance ratings by the {$target_office}."
                    : "Your request has been received and automatically set to Processing by the {$target_office}.";

                send_request_status_email(
                    $u_email,
                    $user_name,
                    $tracking_num,
                    "{$type_name}{$copy_txt}",
                    'Processing',
                    $email_lead,
                    $target_office
                );
            }

            $success_msg = ($doc_code === 'CR')
                ? "Your request for <strong>Certificate of Rating</strong>{$copy_txt} &mdash; Tracking: <strong>{$tracking_num}</strong> has been submitted. Your 3-year performance ratings have been recorded and are now <strong>Awaiting Administrative Verification</strong> against official records. A confirmation has been sent to your registered email."
                : "Your request for <strong>{$type_name}</strong>{$copy_txt} &mdash; Tracking: <strong>{$tracking_num}</strong> has been submitted and is now <strong>Processing</strong> by the <strong>{$target_office}</strong>. A confirmation has been sent to your registered email.";
        }
    }
}
?>

<div class="content-card" style="max-width: 850px; margin: 0 auto;">
  <?php if (!empty($success_msg)): ?>
    <div class="alert-success-banner">
      <div style="font-weight: 800; font-size: 1rem; margin-bottom: 4px;">✅ Request Submitted Successfully!</div>
      <div><?= $success_msg ?></div>
      <div style="margin-top: 12px;">
        <a href="dashboard.php" class="btn-primary-cta" style="padding: 6px 16px; font-size: 0.85rem; text-decoration: none;">Go to Dashboard</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($error_msg)): ?>
    <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 12px 18px; border-radius: 10px; margin-bottom: 18px; font-size: 0.9rem;">
      <strong>Error:</strong> <?= htmlspecialchars($error_msg) ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="submit_request.php" enctype="multipart/form-data">
    <div class="form-group">
      <label class="form-label">Document Type <span style="color: #ef4444;">*</span></label>
      <select name="doc_type_id" id="doc_type_id" class="form-control" required onchange="updateRequirements(this)">
        <option value="">Select Document Type...</option>
        <?php foreach ($available_types as $t): ?>
          <option value="<?= $t['id'] ?>" data-code="<?= htmlspecialchars($t['code']) ?>" data-req='<?= htmlspecialchars($t['requirements'], ENT_QUOTES) ?>' data-name="<?= htmlspecialchars($t['name']) ?>" <?= (!empty($doc_type_id) && (int)$doc_type_id === (int)$t['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($t['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Top 100+ Philippine Universities & High Schools Autocomplete DataList -->
    <datalist id="ph_universities_schools_list">
      <!-- Special Custom / Other Option -->
      <option value="Others (Not in Top 100 List / Please specify below)">Specify Other School / Destination</option>

      <!-- Premier State Universities and Colleges (SUCs) -->
      <option value="University of the Philippines Diliman (UPD)">UP Diliman - Quezon City</option>
      <option value="University of the Philippines Manila (UPM)">UP Manila - Padre Faura, Ermita</option>
      <option value="University of the Philippines Los Baños (UPLB)">UP Los Baños - Laguna</option>
      <option value="University of the Philippines Baguio (UPB)">UP Baguio - Governor Pack Rd</option>
      <option value="University of the Philippines Visayas (UPV)">UP Visayas - Miagao, Iloilo</option>
      <option value="University of the Philippines Mindanao (UPMin)">UP Mindanao - Davao City</option>
      <option value="University of the Philippines Cebu (UPC)">UP Cebu - Lahug, Cebu City</option>
      <option value="University of the Philippines Open University (UPOU)">UP Open University - Los Baños, Laguna</option>
      <option value="Polytechnic University of the Philippines (PUP Main - Sta. Mesa, Manila)">PUP Main Campus - Sta. Mesa, Manila</option>
      <option value="Polytechnic University of the Philippines (PUP Taguig Campus)">PUP Taguig - General Santos Ave, Lower Bicutan</option>
      <option value="Polytechnic University of the Philippines (PUP Quezon City Campus)">PUP Quezon City - Commonwealth</option>
      <option value="Polytechnic University of the Philippines (PUP San Juan Campus)">PUP San Juan - Addition Hills</option>
      <option value="Polytechnic University of the Philippines (PUP Parañaque Campus)">PUP Parañaque - Col. E. De Leon St.</option>
      <option value="Technological University of the Philippines (TUP Manila)">TUP Manila - Ayala Blvd, Ermita</option>
      <option value="Technological University of the Philippines (TUP Taguig Campus)">TUP Taguig - Km. 14 East Service Rd, Western Bicutan</option>
      <option value="Technological University of the Philippines (TUP Cavite Campus)">TUP Cavite - Salawag, Dasmariñas</option>
      <option value="Rizal Technological University (RTU Boni - Mandaluyong)">RTU Boni - Mandaluyong City</option>
      <option value="Rizal Technological University (RTU Pasig Campus)">RTU Pasig - Maybunga, Pasig City</option>
      <option value="Philippine Normal University (PNU Manila)">PNU Manila - Taft Avenue, Ermita</option>
      <option value="Eulogio 'Amang' Rodriguez Institute of Science and Technology (EARIST Manila)">EARIST - Nagtahan, Sampaloc, Manila</option>
      <option value="Philippine State College of Aeronautics (PhilSCA - Pasay)">PhilSCA - Piccio Garden, Pasay City</option>
      <option value="Marikina Polytechnic College (MPC)">MPC - Chanyungco St, Marikina</option>

      <!-- Local Universities and Colleges (LUCs) -->
      <option value="Pamantasan ng Lungsod ng Maynila (PLM - Intramuros)">PLM - General Luna St, Intramuros, Manila</option>
      <option value="Universidad de Manila (UDM - Mehan Garden)">UDM - Mehan Garden, Ermita, Manila</option>
      <option value="Taguig City University (TCU - Taguig)">TCU - Gen. Santos Ave, Central Bicutan, Taguig City</option>
      <option value="Pamantasan ng Lungsod ng Pasig (PLP)">PLP - Alcalde St, Kapasigan, Pasig City</option>
      <option value="Pamantasan ng Lungsod ng Marikina (PLMar)">PLMar - Brazil St, Greenheights, Marikina</option>
      <option value="Pamantasan ng Lungsod ng Muntinlupa (PLMun)">PLMun - University Rd, Poblacion, Muntinlupa</option>
      <option value="Quezon City University (QCU - San Bartolome)">QCU - 673 Quirino Highway, Novaliches, QC</option>
      <option value="University of Makati (UMak)">UMak - J.P. Rizal Ext, West Rembo, Taguig / Makati</option>
      <option value="Pateros Technological College (PTC)">PTC - College St, Sto. Rosario-Kanluran, Pateros</option>
      <option value="Parañaque City College (PCC)">PCC - Coastal Rd, San Dionisio, Parañaque</option>
      <option value="City University of Pasay (CUP)">CUP - Pasadena St, F.B. Harrison, Pasay</option>
      <option value="City of Malabon University (CMU)">CMU - Pampano St, Maya-maya, Malabon</option>
      <option value="Navotas Polytechnic College (NPC)">NPC - Bangkulasi, Navotas City</option>
      <option value="Valenzuela City Technological College (VCTC)">VCTC - Kamagong St, Fortune Village, Valenzuela</option>

      <!-- Top Private Universities (Metro Manila) -->
      <option value="Ateneo de Manila University (ADMU - Quezon City)">ADMU - Katipunan Ave, Loyola Heights, QC</option>
      <option value="De La Salle University (DLSU - Manila, Taft)">DLSU - 2401 Taft Ave, Malate, Manila</option>
      <option value="De La Salle-College of Saint Benilde (DLS-CSB - Manila)">DLS-CSB - Taft Ave / Ocampo St, Malate, Manila</option>
      <option value="University of Santo Tomas (UST - Manila)">UST - España Blvd, Sampaloc, Manila</option>
      <option value="Far Eastern University (FEU - Manila)">FEU - Nicanor Reyes St, Sampaloc, Manila</option>
      <option value="FEU Institute of Technology (FEU Tech - Manila)">FEU Tech - P. Paredes St, Sampaloc, Manila</option>
      <option value="Far Eastern University - Alabang (FEU Alabang)">FEU Alabang - Filinvest City, Muntinlupa</option>
      <option value="Far Eastern University - Diliman (FEU Diliman)">FEU Diliman - Sampaguita Ave, Quezon City</option>
      <option value="Mapúa University (Intramuros, Manila)">Mapúa University - Muralla St, Intramuros, Manila</option>
      <option value="Mapúa University (Makati Campus)">Mapúa Makati - Pablo Ocampo Sr. Ext, Makati</option>
      <option value="University of the East (UE - Manila)">UE Manila - 221 C.M. Recto Ave, Sampaloc, Manila</option>
      <option value="University of the East (UE - Caloocan)">UE Caloocan - Samson Rd, Caloocan City</option>
      <option value="Technological Institute of the Philippines (TIP - Manila)">TIP Manila - P. Casal / Arlegui, Quiapo, Manila</option>
      <option value="Technological Institute of the Philippines (TIP - Quezon City)">TIP QC - 938 Aurora Blvd, Cubao, Quezon City</option>
      <option value="Adamson University (AdU - San Marcelino, Manila)">AdU - 900 San Marcelino St, Ermita, Manila</option>
      <option value="National University (NU - Main Manila)">NU Manila - 551 M.F. Jhocson St, Sampaloc, Manila</option>
      <option value="National University (NU - Mall of Asia, Pasay)">NU MOA - Coral Way, Pasay City</option>
      <option value="National University (NU - Fairview, Quezon City)">NU Fairview - SM City Fairview, QC</option>
      <option value="San Beda University (SBU - Mendiola, Manila)">SBU - 638 Mendiola St, San Miguel, Manila</option>
      <option value="Centro Escolar University (CEU - Manila, Mendiola)">CEU Manila - 9 Mendiola St, San Miguel, Manila</option>
      <option value="Centro Escolar University (CEU - Makati Campus)">CEU Makati - Gil Puyat Ave, Makati City</option>
      <option value="Colegio de San Juan de Letran (Intramuros, Manila)">Letran - 151 Muralla St, Intramuros, Manila</option>
      <option value="Lyceum of the Philippines University (LPU - Manila)">LPU Manila - Muralla cor. Real St, Intramuros</option>
      <option value="Manila Central University (MCU - Caloocan)">MCU - EDSA, Morning Breeze, Caloocan</option>
      <option value="Trinity University of Asia (TUA - Quezon City)">TUA - Cathedral Heights, E. Rodriguez Sr. Ave, QC</option>
      <option value="Miriam College (MC - Quezon City)">Miriam College - Katipunan Ave, Loyola Heights, QC</option>
      <option value="St. Scholastica's College (SSC - Manila)">SSC Manila - 2560 Leon Guinto St, Malate, Manila</option>
      <option value="San Sebastian College – Recoletos (SSC-R - Manila)">SSC-R - C.M. Recto Ave, Quiapo, Manila</option>
      <option value="Philippine Christian University (PCU - Manila)">PCU - 1648 Taft Ave, Malate, Manila</option>
      <option value="Philippine Women's University (PWU - Manila)">PWU - 1743 Taft Ave, Malate, Manila</option>
      <option value="Emilio Aguinaldo College (EAC - Manila)">EAC Manila - 1113-1117 San Marcelino St, Paco, Manila</option>
      <option value="Arellano University (AU - Juan Sumulong, Manila)">AU - 2600 Legarda St, Sampaloc, Manila</option>
      <option value="Arellano University (AU - Andres Bonifacio, Pasig)">AU Pasig - Pag-asa St, Caniogan, Pasig City</option>
      <option value="Asia Pacific College (APC - Magallanes, Makati)">APC - 3 Humabon St, Magallanes, Makati</option>
      <option value="FEATI University (Sta. Cruz, Manila)">FEATI - Helios St, Sta. Cruz, Manila</option>
      <option value="Our Lady of Fatima University (OLFU - Valenzuela)">OLFU Valenzuela - MacArthur Highway, Valenzuela</option>
      <option value="Our Lady of Fatima University (OLFU - Quezon City)">OLFU QC - Regalado Ave, Fairview, QC</option>
      <option value="Our Lady of Fatima University (OLFU - Antipolo)">OLFU Antipolo - Sumulong Highway, Antipolo</option>
      <option value="University of Perpetual Help System DALTA (Las Piñas)">Perpetual DALTA - Alabang-Zapote Rd, Las Piñas</option>
      <option value="STI College (Global City / Bonifacio Global City)">STI Global City - 32nd St, University Parkway, BGC, Taguig</option>
      <option value="AMA Computer University (Main - Project 8, Quezon City)">AMACU - Maximina St, Villa Arca, QC</option>

      <!-- Leading Regional & Provincial Universities -->
      <option value="Saint Louis University (SLU - Baguio City)">SLU - A. Bonifacio St, Baguio City</option>
      <option value="University of Baguio (UB - Baguio City)">UB - General Luna Rd, Baguio City</option>
      <option value="University of the Cordilleras (UC - Baguio City)">UC - Gov. Pack Rd, Baguio City</option>
      <option value="Central Luzon State University (CLSU - Nueva Ecija)">CLSU - Science City of Muñoz, Nueva Ecija</option>
      <option value="Angeles University Foundation (AUF - Angeles City, Pampanga)">AUF - MacArthur Highway, Angeles City</option>
      <option value="Holy Angel University (HAU - Angeles City, Pampanga)">HAU - Holy Angel Ave, Angeles City</option>
      <option value="Bulacan State University (BulSU - Malolos, Bulacan)">BulSU - Guinhawa, Malolos, Bulacan</option>
      <option value="Cavite State University (CvSU - Main Indang)">CvSU Main - Bancod, Indang, Cavite</option>
      <option value="De La Salle University - Dasmariñas (DLSU-D)">DLSU-D - DBB-B, Dasmariñas City, Cavite</option>
      <option value="De La Salle Medical and Health Sciences Institute (DLSMHSI)">DLSMHSI - Gov. D. Mangubat Ave, Dasmariñas, Cavite</option>
      <option value="Batangas State University (BatStateU - The National Engineering University)">BatStateU - Rizal Ave, Batangas City</option>
      <option value="University of San Carlos (USC - Cebu City)">USC - P. del Rosario St / Talamban, Cebu City</option>
      <option value="University of San Jose-Recoletos (USJ-R - Cebu City)">USJ-R - Magallanes St, Cebu City</option>
      <option value="Cebu Institute of Technology - University (CIT-U - Cebu City)">CIT-U - N. Bacalso Ave, Cebu City</option>
      <option value="Cebu Normal University (CNU - Cebu City)">CNU - Osmeña Blvd, Cebu City</option>
      <option value="Silliman University (SU - Dumaguete City)">Silliman - Hibbard Ave, Dumaguete City, Negros Oriental</option>
      <option value="Central Philippine University (CPU - Iloilo City)">CPU - Lopez Jaena St, Jaro, Iloilo City</option>
      <option value="University of San Agustin (USA - Iloilo City)">USA - General Luna St, Iloilo City</option>
      <option value="West Visayas State University (WVSU - Iloilo City)">WVSU - Luna St, La Paz, Iloilo City</option>
      <option value="Ateneo de Davao University (ADDU - Davao City)">ADDU - E. Jacinto St, Davao City</option>
      <option value="University of the Immaculate Conception (UIC - Davao City)">UIC - Fr. Selga St, Davao City</option>
      <option value="University of Mindanao (UM - Davao City)">UM - Bolton St / Matina, Davao City</option>
      <option value="Mindanao State University - Iligan Institute of Technology (MSU-IIT)">MSU-IIT - Andres Bonifacio Ave, Tibanga, Iligan City</option>
      <option value="Mindanao State University (MSU - Main Marawi)">MSU Main - Marawi City, Lanao del Sur</option>
      <option value="Xavier University – Ateneo de Cagayan (XU - Cagayan de Oro)">Xavier Ateneo - Corrales Ave, Cagayan de Oro City</option>
      <option value="Ateneo de Naga University (ADNU - Naga City)">ADNU - Ateneo Ave, Naga City, Camarines Sur</option>

      <!-- Science High Schools & Partner Public High Schools -->
      <option value="Philippine Science High School (PSHS - Main Campus, Diliman)">PSHS Main - Agham Rd, Diliman, QC</option>
      <option value="Manila Science High School (MSHS - Ermita, Manila)">MSHS - Padre Faura cor. Taft Ave, Ermita, Manila</option>
      <option value="Quezon City Science High School (QCSHS)">QCSHS - Golden Ave, Bago Bantay, Quezon City</option>
      <option value="Makati Science High School (MSHS - Kalayaan, Makati)">MSHS - Kalayaan Ave, Cembo, Taguig / Makati</option>
      <option value="Pasig City Science High School (PCSHS)">PCSHS - Caruncho Ave, San Nicolas, Pasig</option>
      <option value="Senator Renato 'Compañero' Cayetano Memorial Science & Technology High School (SRCCMSTHS)">SRCCMSTHS - 31st St, Ususan, Taguig City</option>
      <option value="Taguig National High School (TNHS - Taguig)">TNHS - General Santos Ave, Lower Bicutan, Taguig City</option>
      <option value="Signal Village National High School (SVNHS - Taguig)">SVNHS - Ballecer St, Central Signal Village, Taguig City</option>
      <option value="Western Bicutan National High School (WBNHS)">WBNHS - EP Housing Phase 2, Western Bicutan, Taguig City</option>
      <option value="Upper Bicutan National High School (UBNHS)">UBNHS - General Santos Ave, Upper Bicutan, Taguig City</option>
      <option value="Bagumbayan National High School (BNHS - Taguig)">BNHS - MLQ Ave, Bagumbayan, Taguig City</option>
      <option value="Tipas National High School (TNHS - Taguig)">Tipas NHS - F. Manalo St, Ibayo Tipas, Taguig City</option>
    </datalist>

    <!-- Request Dynamic Fields Container (Only shown when a Document Type is selected) -->
    <div id="request-fields-container" style="display: <?= !empty($doc_type_id) ? 'block' : 'none' ?>;">

    <!-- Dynamic Requirements & Document Guide Panel -->
    <div class="form-group">
      <label class="form-label">Requirements & Guidelines</label>
      <div id="requirements-box" class="form-control" style="background: #f8fafc; min-height: 48px; display: flex; align-items: center;">
        <span style="color: #94a3b8; font-size: 0.9rem;">Select a document type to view the requirements and guidelines</span>
      </div>
    </div>

    <!-- Special 3-Year Performance Ratings Panel for Certificate of Rating (CR) -->
    <div id="ratings-section" style="display: none; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 12px; padding: 18px 20px; margin-bottom: 20px;">
      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
        <div style="display: flex; align-items: center; gap: 8px;">
          <span style="font-size: 1.3rem;">⭐</span>
          <div>
            <h4 style="margin: 0; color: #166534; font-size: 0.98rem; font-weight: 800;">3-Year Performance Ratings (IPCRF Points) <span style="color: #ef4444;">*</span></h4>
            <small style="color: #15803d; font-size: 0.8rem;">Ilagay ang inyong official rating grade para sa 3 magkasunod na School Years. Ibe-verify ito ng Administrator sa school records.</small>
          </div>
        </div>
        <div id="rating-avg-badge" style="background: #166534; color: #ffffff; padding: 4px 12px; border-radius: 20px; font-weight: 700; font-size: 0.82rem; display: none;">
          3-Yr Avg: <span id="avg-val">0.00</span>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px;">
        <!-- Year 1 -->
        <div style="background: #ffffff; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Year 1 School Year</label>
          <input type="text" name="sy_year_1" id="sy_year_1" class="form-control" value="2022–2023" placeholder="e.g. 2022–2023" style="font-size: 0.88rem; margin-bottom: 6px;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Numerical Rating (Points) <span style="color: #ef4444;">*</span></label>
          <input type="number" step="0.01" min="1.00" max="5.00" name="rating_year_1" id="rating_year_1" class="form-control" placeholder="e.g. 4.32" oninput="this.value = this.value.replace(/[^0-9.]/g, ''); calculateRatingAvg();" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">
        </div>

        <!-- Year 2 -->
        <div style="background: #ffffff; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Year 2 School Year</label>
          <input type="text" name="sy_year_2" id="sy_year_2" class="form-control" value="2023–2024" placeholder="e.g. 2023–2024" style="font-size: 0.88rem; margin-bottom: 6px;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Numerical Rating (Points) <span style="color: #ef4444;">*</span></label>
          <input type="number" step="0.01" min="1.00" max="5.00" name="rating_year_2" id="rating_year_2" class="form-control" placeholder="e.g. 4.38" oninput="this.value = this.value.replace(/[^0-9.]/g, ''); calculateRatingAvg();" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">
        </div>

        <!-- Year 3 -->
        <div style="background: #ffffff; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Year 3 School Year</label>
          <input type="text" name="sy_year_3" id="sy_year_3" class="form-control" value="2024–2025" placeholder="e.g. 2024–2025" style="font-size: 0.88rem; margin-bottom: 6px;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Numerical Rating (Points) <span style="color: #ef4444;">*</span></label>
          <input type="number" step="0.01" min="1.00" max="5.00" name="rating_year_3" id="rating_year_3" class="form-control" placeholder="e.g. 4.40" oninput="this.value = this.value.replace(/[^0-9.]/g, ''); calculateRatingAvg();" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">
        </div>
      </div>

      <div style="margin-top: 10px; font-size: 0.8rem; color: #166534; display: flex; align-items: flex-start; gap: 6px; line-height: 1.45;">
        <span>ℹ️</span> <em>Paalala: I-ve-verify ng Administrator ang inyong ratings sa official records. Kapag may discrepancy, mare-reject ito for revision upang makipag-coordinate kayo sa inyong Subject/Head Coordinator bago mag-request muli.</em>
      </div>
    </div>

    <!-- Number of Copies (Available for both Teachers and Students) -->
    <div class="form-group">
      <label class="form-label">Number of Copies <span style="color: #ef4444;">*</span></label>
      <div style="display: flex; align-items: center; gap: 10px; max-width: 220px;">
        <input 
          type="number" 
          name="copies" 
          id="copies" 
          class="form-control" 
          value="1" 
          min="1" 
          max="10" 
          required 
          oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(parseInt(this.value) > 10) this.value = 10; if(parseInt(this.value) < 1 && this.value !== '') this.value = 1;"
          style="font-weight: 700; width: 90px; text-align: center; font-size: 1rem;"
        />
        <span style="font-size: 0.9rem; font-weight: 600; color: #475569;">copy / copies</span>
      </div>
    </div>

    <!-- Dedicated COE & Service Record (SR) Employee Details Panel -->
    <div id="faculty-emp-fields-group" style="display: none; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 12px; padding: 18px 20px; margin-bottom: 22px;">
      <div style="font-weight: 800; color: #166534; font-size: 0.98rem; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
        <span>📋</span> <span id="faculty-emp-fields-title">Employee Information</span> <span style="font-size: 0.75rem; font-weight: 700; color: #15803d; background: #dcfce7; padding: 2px 8px; border-radius: 6px;">Required by Admin</span>
      </div>
      <p id="faculty-emp-fields-desc" style="font-size: 0.82rem; color: #166534; margin: 0 0 14px 0; line-height: 1.45;">
        Kinakailangan ang mga impormasyong ito upang ma-verify at ma-generate ang inyong official employment records:
      </p>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px;">
        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Full Name <span style="color: #ef4444;">*</span>
          </label>
          <input 
            type="text" 
            name="emp_full_name" 
            id="emp_full_name" 
            class="form-control" 
            placeholder="e.g., Juan Dela Cruz"
            oninput="validateNameOnly(this, 'Employee Full Name')"
            style="background: #ffffff;"
          />
        </div>

        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Employee Number <span style="color: #ef4444;">*</span>
          </label>
          <input 
            type="text" 
            name="emp_number" 
            id="emp_number" 
            class="form-control" 
            placeholder="e.g., 1029384 (Numbers only)"
            oninput="this.value = this.value.replace(/[^0-9-]/g, '')"
            style="background: #ffffff;"
          />
        </div>

        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Position <span style="color: #ef4444;">*</span>
          </label>
          <select 
            name="emp_position" 
            id="emp_position" 
            class="form-control" 
            style="background: #ffffff;"
            onchange="autoSuggestSalaryGrade(this.value)"
          >
            <option value="">-- Piliin ang DepEd Position --</option>
            <optgroup label="DepEd Teaching Positions (JHS & SHS)">
              <option value="Teacher I">Teacher I</option>
              <option value="Teacher II">Teacher II</option>
              <option value="Teacher III">Teacher III</option>
              <option value="Special Science Teacher I">Special Science Teacher I (TagSci)</option>
              <option value="Special Science Teacher II">Special Science Teacher II (TagSci)</option>
              <option value="Teacher IV">Teacher IV</option>
              <option value="Teacher V">Teacher V</option>
              <option value="Teacher VI">Teacher VI</option>
              <option value="Teacher VII">Teacher VII</option>
            </optgroup>
            <optgroup label="Master Teachers">
              <option value="Master Teacher I">Master Teacher I</option>
              <option value="Master Teacher II">Master Teacher II</option>
              <option value="Master Teacher III">Master Teacher III</option>
              <option value="Master Teacher IV">Master Teacher IV</option>
            </optgroup>
            <optgroup label="Department Heads & School Administration">
              <option value="Head Teacher I">Head Teacher I</option>
              <option value="Head Teacher II">Head Teacher II</option>
              <option value="Head Teacher III">Head Teacher III</option>
              <option value="Head Teacher IV">Head Teacher IV</option>
              <option value="Head Teacher V">Head Teacher V</option>
              <option value="Head Teacher VI">Head Teacher VI</option>
              <option value="Assistant School Principal I">Assistant School Principal I</option>
              <option value="Assistant School Principal II">Assistant School Principal II</option>
              <option value="School Principal I">School Principal I</option>
              <option value="School Principal II">School Principal II</option>
              <option value="School Principal III">School Principal III</option>
              <option value="School Principal IV">School Principal IV</option>
            </optgroup>
            <optgroup label="Non-Teaching & Administrative Personnel">
              <option value="Administrative Officer II">Administrative Officer II</option>
              <option value="Administrative Assistant III">Administrative Assistant III</option>
              <option value="Administrative Assistant II">Administrative Assistant II</option>
              <option value="Administrative Aide VI">Administrative Aide VI</option>
              <option value="Registrar I">Registrar I</option>
              <option value="Guidance Counselor I">Guidance Counselor I</option>
            </optgroup>
            <option value="Other Faculty / Staff">Other Faculty / Staff Position</option>
          </select>
        </div>

        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Salary Grade <span style="color: #ef4444;">*</span>
          </label>
          <select 
            name="emp_salary_grade" 
            id="emp_salary_grade" 
            class="form-control" 
            style="background: #ffffff;"
          >
            <option value="">-- Piliin ang Salary Grade --</option>
            <option value="SG 11">SG 11 (Teacher I / Entry Level)</option>
            <option value="SG 12">SG 12 (Teacher II)</option>
            <option value="SG 13">SG 13 (Teacher III / SST I)</option>
            <option value="SG 14">SG 14 (SST II / Teacher IV)</option>
            <option value="SG 15">SG 15 (Head Teacher I / Teacher V)</option>
            <option value="SG 16">SG 16 (Head Teacher II / Teacher VI)</option>
            <option value="SG 17">SG 17 (Head Teacher III / Teacher VII)</option>
            <option value="SG 18">SG 18 (Master Teacher I / Head Teacher IV)</option>
            <option value="SG 19">SG 19 (Master Teacher II / Head Teacher V / Asst. Principal I)</option>
            <option value="SG 20">SG 20 (Master Teacher III / Head Teacher VI / Asst. Principal II)</option>
            <option value="SG 21">SG 21 (Master Teacher IV / Principal I)</option>
            <option value="SG 22">SG 22 (Principal II)</option>
            <option value="SG 23">SG 23 (Principal III)</option>
            <option value="SG 24">SG 24 (Principal IV)</option>
            <option value="SG 10">SG 10</option>
            <option value="SG 9">SG 9</option>
            <option value="SG 8">SG 8</option>
            <option value="SG 6">SG 6</option>
          </select>
        </div>

        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Step <span style="color: #ef4444;">*</span>
          </label>
          <select 
            name="emp_step" 
            id="emp_step" 
            class="form-control" 
            style="background: #ffffff;"
          >
            <option value="">-- Piliin ang Step Increment --</option>
            <option value="Step 1">Step 1 (Starting / Base Step)</option>
            <option value="Step 2">Step 2 (3 Years Satisfactory Service)</option>
            <option value="Step 3">Step 3 (6 Years Satisfactory Service)</option>
            <option value="Step 4">Step 4 (9 Years Satisfactory Service)</option>
            <option value="Step 5">Step 5 (12 Years Satisfactory Service)</option>
            <option value="Step 6">Step 6 (15 Years Satisfactory Service)</option>
            <option value="Step 7">Step 7 (18 Years Satisfactory Service)</option>
            <option value="Step 8">Step 8 (Maximum Step Increment)</option>
          </select>
        </div>

        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Date Hired <span style="color: #ef4444;">*</span>
          </label>
          <input 
            type="date" 
            name="emp_date_hired" 
            id="emp_date_hired" 
            class="form-control" 
            style="background: #ffffff;"
          />
        </div>

        <div style="grid-column: 1 / -1;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Purpose <span style="color: #ef4444;">*</span>
          </label>
          <input 
            type="text" 
            name="emp_purpose" 
            id="emp_purpose" 
            class="form-control" 
            placeholder="e.g., DepEd Promotion / Ranking, GSIS / Pag-IBIG Loan, Visa Application, Step Increment, Retirement, etc."
            style="background: #ffffff;"
          />
        </div>

        <!-- With Compensation Radio (ONLY for COE, Hidden for Service Record) -->
        <div id="with-compensation-group" style="grid-column: 1 / -1;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 6px;">
            With Compensation <span style="color: #ef4444;">*</span>
          </label>
          <div style="display: flex; gap: 20px; align-items: center; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 16px; flex-wrap: wrap;">
            <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: #1e293b; cursor: pointer; font-size: 0.9rem;">
              <input type="radio" name="emp_with_compensation" id="emp_with_compensation_yes" value="Yes" checked style="width: 17px; height: 17px; accent-color: #166534;">
              <span>Yes (With Monthly Salary &amp; Compensation Breakdown)</span>
            </label>
            <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: #1e293b; cursor: pointer; font-size: 0.9rem;">
              <input type="radio" name="emp_with_compensation" id="emp_with_compensation_no" value="No" style="width: 17px; height: 17px; accent-color: #166534;">
              <span>No (Without Compensation Breakdown)</span>
            </label>
          </div>
        </div>

        <div style="grid-column: 1 / -1;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Additional Notes &amp; Specific Instructions <span style="font-weight: normal; color: #64748b;">(Optional &mdash; e.g. Addressed to specific agency / institution)</span>
          </label>
          <input 
            type="text" 
            name="emp_notes" 
            id="emp_notes" 
            class="form-control" 
            placeholder="e.g., Addressed to GSIS Taguig; Covering SY 2022–2025; For Step Increment..."
            style="background: #ffffff;"
          />
        </div>
      </div>
    </div>

    <!-- Generic Purpose Group (Shown for non-COE/SR documents) -->
    <div class="form-group" id="general-purpose-group">
      <label class="form-label" id="general-purpose-label"><?= $purpose_label ?></label>
      <input 
        type="text" 
        name="purpose" 
        id="general_purpose_input"
        class="form-control" 
        placeholder="<?= htmlspecialchars($purpose_placeholder) ?>" 
        required 
      />
      <small id="general-purpose-hint" style="color: #64748b; font-size: 0.78rem; display: <?= !empty($purpose_hint) ? 'block' : 'none' ?>; margin-top: 4px;"><?= $purpose_hint ?></small>
    </div>

    <!-- Form 137 Dedicated Student Record Inputs (Shown only when Form 137 is selected) -->
    <div id="f137-fields-group" style="display: none; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 12px; padding: 18px 20px; margin-bottom: 22px;">
      <div style="font-weight: 800; color: #166534; font-size: 0.98rem; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
        <span>📁</span> Form 137 / SF10 Student Information <span style="font-size: 0.75rem; font-weight: 700; color: #15803d; background: #dcfce7; padding: 2px 8px; border-radius: 6px;">Required by Registrar</span>
      </div>
      <p style="font-size: 0.82rem; color: #166534; margin: 0 0 14px 0; line-height: 1.45;">
        Kinakailangan ng Registrar ang mga impormasyong ito upang mabilis at tumpak na mahanap ang inyong permanent student record sa school archives:
      </p>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px;">
        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Recent Section (Grade &amp; Section) <span style="color: #ef4444;">*</span>
          </label>
          <input 
            type="text" 
            name="f137_recent_section" 
            id="f137_recent_section" 
            class="form-control" 
            placeholder="e.g., Grade 10 - Einstein / Grade 12 - STEM 1"
            style="background: #ffffff;"
          />
        </div>

        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Year Graduated / Last SY Attended <span style="color: #ef4444;">*</span>
          </label>
          <select 
            name="f137_year_graduated" 
            id="f137_year_graduated" 
            class="form-control" 
            style="background: #ffffff;"
          >
            <option value="">-- Piliin ang Taon / SY Attended --</option>
            <?php for ($y = 2026; $y >= 2004; $y--): ?>
              <option value="<?= $y ?>"><?= $y ?> (SY <?= ($y - 1) . '-' . $y ?>)</option>
            <?php endfor; ?>
          </select>
          <small id="f137_year_hint" style="color: #15803d; font-size: 0.75rem; display: block; margin-top: 3px;">Pumili sa listahan mula 2026 hanggang sa pagkakatatag ng TagSci (2004).</small>
        </div>

        <div style="grid-column: 1 / -1;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Specific School / Transferee University <span style="color: #ef4444;">*</span>
          </label>
          <input 
            type="text" 
            name="f137_transferee_school" 
            id="f137_transferee_school" 
            list="ph_universities_schools_list"
            class="form-control" 
            placeholder="Type school name to search Top 100 Universities / High Schools or select..."
            autocomplete="off"
            oninput="handleTransfereeSchoolChange(this)"
            style="background: #ffffff;"
          />
          <small style="color: #15803d; font-size: 0.75rem; display: block; margin-top: 4px;">
            🔍 Habang nagta-type, unti-unting lalabas ang mga pangalan ng Top 100+ Philippine Universities at Schools. Piliin ang inyong target school.
          </small>

          <!-- Quick Suggestion Badges -->
          <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; align-items: center;">
            <span style="font-size: 0.75rem; color: #475569; font-weight: 700;">Quick Select:</span>
            <button type="button" class="school-quick-pill" onclick="selectQuickSchool('Polytechnic University of the Philippines (PUP Main - Sta. Mesa, Manila)')">PUP Manila</button>
            <button type="button" class="school-quick-pill" onclick="selectQuickSchool('University of the Philippines Diliman (UPD)')">UP Diliman</button>
            <button type="button" class="school-quick-pill" onclick="selectQuickSchool('University of Santo Tomas (UST - Manila)')">UST</button>
            <button type="button" class="school-quick-pill" onclick="selectQuickSchool('De La Salle University (DLSU - Manila, Taft)')">DLSU</button>
            <button type="button" class="school-quick-pill" onclick="selectQuickSchool('Ateneo de Manila University (ADMU - Quezon City)')">Ateneo</button>
            <button type="button" class="school-quick-pill" onclick="selectQuickSchool('Pamantasan ng Lungsod ng Maynila (PLM - Intramuros)')">PLM</button>
            <button type="button" class="school-quick-pill" onclick="selectQuickSchool('Taguig City University (TCU - Taguig)')">TCU</button>
            <button type="button" class="school-quick-pill" onclick="selectQuickSchool('Technological University of the Philippines (TUP Taguig Campus)')">TUP Taguig</button>
            <button type="button" class="school-quick-pill" onclick="selectQuickSchool('Others (Not in Top 100 List / Please specify below)')" style="background: #fef3c7; color: #92400e; border-color: #fde68a;">+ Others (Custom)</button>
          </div>

          <!-- Custom "Others" School Specification Box -->
          <div id="f137_other_school_box" style="display: none; margin-top: 10px; background: #ffffff; border: 1.5px dashed #16a34a; border-radius: 8px; padding: 12px 14px;">
            <label class="form-label" style="font-size: 0.8rem; font-weight: 700; color: #166534; margin-bottom: 3px;">
              Specify Transferee School Name &amp; Complete Campus Address <span style="color: #ef4444;">*</span>
            </label>
            <input 
              type="text" 
              name="f137_other_school_input" 
              id="f137_other_school_input" 
              class="form-control" 
              placeholder="e.g. University of Eastern Philippines - Catarman Campus, Northern Samar"
              style="background: #f8fafc; font-size: 0.9rem;"
            />
            <small style="color: #64748b; font-size: 0.74rem; display: block; margin-top: 3px;">Pakilagay ang buong opisyal na pangalan ng school kasama ang campus/branch kung wala sa Top 100 list.</small>
          </div>
        </div>
      </div>
    </div>

    <!-- Student Certificate Dedicated Student Information Panel (Shown for GMC, COG, and COE_ST) -->
    <div id="gmc-fields-group" style="display: none; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 12px; padding: 18px 20px; margin-bottom: 22px;">
      <div style="font-weight: 800; color: #166534; font-size: 0.98rem; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
        <span id="student-doc-icon">📜</span> <span id="student-doc-title">Student Information</span> <span id="student-doc-badge" style="font-size: 0.75rem; font-weight: 700; color: #15803d; background: #dcfce7; padding: 2px 8px; border-radius: 6px;">Required by Office</span>
      </div>
      <p id="student-doc-desc" style="font-size: 0.82rem; color: #166534; margin: 0 0 14px 0; line-height: 1.45;">
        Kinakailangan ang mga impormasyong ito upang mabilis at tumpak na maihanda ang inyong opisyal na sertipiko:
      </p>

      <!-- Guidance Disciplinary Hold Alert (Shown dynamically if student has an active hold) -->
      <?php if ($has_guidance_hold && $guidance_hold_record): ?>
        <div id="guidance-hold-warning-box" style="display: none; background: #fef2f2; border: 2px solid #ef4444; border-radius: 10px; padding: 14px 18px; margin-bottom: 18px;">
          <div style="display: flex; align-items: flex-start; gap: 10px;">
            <span style="font-size: 1.6rem; line-height: 1;">⚠️</span>
            <div>
              <div style="font-weight: 800; color: #991b1b; font-size: 0.95rem; margin-bottom: 4px;">
                CLEARANCE HOLD: PENDING DISCIPLINARY RECORD WITH GUIDANCE OFFICE
              </div>
              <p style="margin: 0 0 8px 0; color: #b91c1c; font-size: 0.85rem; line-height: 1.45;">
                Ipinapakita sa opisyal na talaan ng Guidance and Counseling Office na mayroon kayong <strong>Pending Disciplinary Case</strong>: 
                <span style="background: #fee2e2; padding: 2px 6px; border-radius: 4px; font-weight: 700; color: #7f1d1d;"><?= htmlspecialchars($guidance_hold_record['case_title']) ?></span> 
                (Iniulat noong: <?= date('F j, Y', strtotime($guidance_hold_record['date_reported'])) ?>).
              </p>
              <div style="background: #ffffff; border: 1px solid #fecaca; border-radius: 6px; padding: 10px 14px; font-size: 0.82rem; color: #7f1d1d; line-height: 1.45;">
                🔒 <strong>Patakaran sa Good Moral Certificate:</strong> Hindi po maaaring magsumite o magpalabas ng Good Moral Certificate habang may aktibong hold. Mangyaring magsadya sa <strong>Guidance and Counseling Office</strong> upang makipag-ugnayan at ma-settle ang inyong clearance bago muling magsumite.
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px;">
        <!-- Full Name -->
        <div style="grid-column: 1 / -1;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Full Name (Pangalan ng Mag-aaral) <span style="color: #ef4444;">*</span>
          </label>
          <input 
            type="text" 
            name="gmc_full_name" 
            id="gmc_full_name" 
            class="form-control" 
            value="<?= htmlspecialchars($user_name) ?>"
            placeholder="e.g., Juan M. Dela Cruz"
            oninput="validateNameOnly(this, 'Student Full Name')"
            style="background: #ffffff;"
          />
          <small style="color: #15803d; font-size: 0.75rem; display: block; margin-top: 3px;">Siguraduhing tama ang spelling ng inyong buong opisyal na pangalan. Titik (letters) lamang ang tinatanggap.</small>
        </div>

        <!-- Section / Strand -->
        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Section / Strand (e.g. STEM / ABM / Grade &amp; Section) <span style="color: #ef4444;">*</span>
          </label>
          <input 
            type="text" 
            name="gmc_section_strand" 
            id="gmc_section_strand" 
            class="form-control" 
            placeholder="e.g., Grade 12 - STEM 1 / Grade 12 - ABM / Grade 10 - Einstein"
            style="background: #ffffff;"
          />
          <small style="color: #15803d; font-size: 0.75rem; display: block; margin-top: 3px;">Ilagay ang inyong Section at Strand (e.g. STEM, ABM, GAS, HUMSS, TVL).</small>
        </div>

        <!-- Student Status (Currently Enrolled vs Graduated) -->
        <div>
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Student Status <span style="color: #ef4444;">*</span>
          </label>
          <select 
            name="gmc_status" 
            id="gmc_status" 
            class="form-control" 
            onchange="updateGmcGradStatus()"
            style="background: #ffffff;"
          >
            <option value="Currently Enrolled">Currently Enrolled (Kasalukuyang Nag-aaral)</option>
            <option value="Graduated">Graduated / Alumnus (Naka-graduate na)</option>
          </select>
          <small style="color: #15803d; font-size: 0.75rem; display: block; margin-top: 3px;">Pumili kung kasalukuyang mag-aaral o nagtapos na.</small>
        </div>

        <!-- Year Graduated (Required only if Graduated, optional if Currently Enrolled) -->
        <div>
          <label id="gmc_year_label" class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Year Graduated <span id="gmc_year_req_badge" style="font-weight: normal; color: #64748b;">(Optional kung Currently Enrolled)</span>
          </label>
          <select 
            name="gmc_year_graduated" 
            id="gmc_year_graduated" 
            class="form-control" 
            style="background: #ffffff;"
          >
            <option value="">-- Iwanang Blangko kung Currently Enrolled o Pumili ng Taon --</option>
            <?php for ($y = 2026; $y >= 2004; $y--): ?>
              <option value="<?= $y ?>"><?= $y ?> (Batch / SY <?= ($y - 1) . '-' . $y ?>)</option>
            <?php endfor; ?>
          </select>
          <small id="gmc_year_hint" style="color: #15803d; font-size: 0.75rem; display: block; margin-top: 3px;">Kung hindi pa graduate, iwanang blangko. Piliin ang taon kung Alumnus / Graduated.</small>
        </div>

        <!-- Target School / University or Purpose -->
        <div style="grid-column: 1 / -1;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Specific School / University or Purpose <span style="color: #ef4444;">*</span>
          </label>
          <input 
            type="text" 
            name="gmc_purpose_school" 
            id="gmc_purpose_school" 
            list="ph_universities_schools_list"
            class="form-control" 
            autocomplete="off"
            placeholder="e.g., PUP Manila - College Admission / UST / DOST Scholarship / Employment"
            style="background: #ffffff;"
          />
          <small id="student-doc-purpose-hint" style="color: #15803d; font-size: 0.75rem; display: block; margin-top: 3px;">Pangalan ng school/kolehiyo o layunin kung bakit kinakailangan ang dokumento. May autocomplete sa Top 100 Universities.</small>
        </div>

        <!-- Optional Additional Notes -->
        <div style="grid-column: 1 / -1;">
          <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: #166534; margin-bottom: 4px;">
            Additional Notes &amp; Special Instructions <span style="font-weight: normal; color: #64748b;">(Optional)</span>
          </label>
          <input 
            type="text" 
            name="gmc_notes" 
            id="gmc_notes" 
            class="form-control" 
            placeholder="e.g., Special deadline, requested for college application form, etc."
            style="background: #ffffff;"
          />
        </div>
      </div>
    </div>

    <div id="general-notes-group" class="form-group">
      <label class="form-label" id="general-notes-label"><?= $notes_label ?></label>
      <input 
        type="text" 
        name="notes" 
        id="general_notes_input"
        class="form-control" 
        placeholder="<?= htmlspecialchars($notes_placeholder) ?>" 
      />
      <small id="general-notes-hint" style="color: #64748b; font-size: 0.78rem; display: <?= !empty($notes_hint) ? 'block' : 'none' ?>; margin-top: 4px;"><?= $notes_hint ?></small>
    </div>

    <div class="form-group" id="attachment-form-group">
      <label class="form-label" id="attachment-label">
        Attach Supporting Document(s) <span id="attachment-optional-badge" style="font-weight: normal; color: #64748b;">(Optional — Pwede mag-attach ng kahit ilan / Multiple Documents)</span>
      </label>
      <div style="display: flex; align-items: center; gap: 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px 14px; flex-wrap: wrap;">
        <label for="attachment" style="background: #e2e8f0; border: 1px solid #cbd5e1; padding: 8px 18px; border-radius: 6px; font-size: 0.85rem; font-weight: 700; cursor: pointer; color: #1e293b; display: inline-flex; align-items: center; gap: 6px;">
          📁 Choose Document(s)...
        </label>
        <input type="file" name="attachments[]" id="attachment" multiple style="display: none;" onchange="handleFilesSelected(this)" />
        <span id="file-chosen-text" style="font-size: 0.9rem; color: #64748b;">No file selected (Optional). Pwede pumili ng higit sa isang dokumento.</span>
      </div>
      <div id="file-list-preview" style="display: none; margin-top: 10px; gap: 6px; flex-wrap: wrap;"></div>
      <small id="attachment-hint-text" style="color: #64748b; font-size: 0.78rem; display: block; margin-top: 6px;">
        Uploading files is optional. Maaari kang mag-attach ng maraming dokumento sabay-sabay (hal. Valid ID, Formal Letter, SF10, Certificates). Allowed formats: PDF, JPG, PNG (hanggang 10MB bawat file).
      </small>

      <!-- Smart Guard Bar -->
      <div id="smart-guard-box" class="smart-guard-box" style="margin-top: 10px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <span id="smart-guard-text">Smart Guard: Optional. Supports single or multiple documents. Allowed formats: PDF, JPG, PNG (Max 10MB each).</span>
      </div>
    </div>

    <div style="display: flex; gap: 12px; margin-top: 25px;">
      <button type="submit" class="btn-primary-cta" id="btn-submit-request">Submit Request</button>
      <a href="dashboard.php" class="btn-primary-cta" style="background: #ffffff; color: #475569; border: 1px solid #cbd5e1; box-shadow: none; text-decoration: none;">✕ Cancel</a>
    </div>
    </div><!-- End #request-fields-container -->
  </form>
</div>

<style>
.school-quick-pill {
  background: #f1f5f9;
  border: 1px solid #cbd5e1;
  color: #334155;
  font-size: 0.73rem;
  font-weight: 600;
  padding: 3px 9px;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.15s ease;
  line-height: 1.4;
}
.school-quick-pill:hover {
  background: #dcfce7;
  color: #166534;
  border-color: #86efac;
}
.realtime-input-error {
  animation: fadeInErr 0.2s ease-in-out;
}
@keyframes fadeInErr {
  from { opacity: 0; transform: translateY(-3px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>

<script>
const isTeacher = <?= $is_teacher ? 'true' : 'false' ?>;

function validateYearOnly(input, fieldName) {
  const original = input.value;
  const filtered = original.replace(/[^0-9]/g, '');
  if (original !== filtered) {
    input.value = filtered;
    showInputToastError(input, `Numbers only! Ang "${original}" ay may kasamang letters. 4-digit year lamang ang tinatanggap (hal. 2024, 2026).`);
  } else {
    clearInputToastError(input);
  }
  if (input.value.length > 4) {
    input.value = input.value.slice(0, 4);
  }
}

function validateNameOnly(input, fieldName) {
  const original = input.value;
  const filtered = original.replace(/[^a-zA-Z\sñÑ.'-]/g, '');
  if (original !== filtered) {
    input.value = filtered;
    showInputToastError(input, `Letters only! Bawal ang numbers o special symbols sa ${fieldName}.`);
  } else {
    clearInputToastError(input);
  }
}

function showInputToastError(input, message) {
  input.style.borderColor = '#ef4444';
  input.style.backgroundColor = '#fef2f2';
  
  let parent = input.closest('div');
  if (!parent) parent = input.parentNode;
  let errEl = parent.querySelector('.realtime-input-error');
  if (!errEl) {
    errEl = document.createElement('div');
    errEl.className = 'realtime-input-error';
    errEl.style.color = '#b91c1c';
    errEl.style.fontSize = '0.74rem';
    errEl.style.fontWeight = '700';
    errEl.style.marginTop = '4px';
    errEl.style.display = 'flex';
    errEl.style.alignItems = 'center';
    errEl.style.gap = '4px';
    parent.appendChild(errEl);
  }
  errEl.innerHTML = `⚠️ ${message}`;
  errEl.style.display = 'flex';

  setTimeout(() => {
    input.style.borderColor = '';
    input.style.backgroundColor = '';
  }, 3200);
}

function clearInputToastError(input) {
  let parent = input.closest('div');
  if (!parent) parent = input.parentNode;
  let errEl = parent.querySelector('.realtime-input-error');
  if (errEl) {
    errEl.style.display = 'none';
  }
  input.style.borderColor = '';
  input.style.backgroundColor = '';
}

function handleTransfereeSchoolChange(input) {
  const val = input.value.trim();
  const otherBox = document.getElementById('f137_other_school_box');
  const otherInput = document.getElementById('f137_other_school_input');
  
  const isOther = (val === 'Others (Not in Top 100 List / Please specify below)' || val.toLowerCase() === 'others' || val.toLowerCase().startsWith('others:'));
  if (isOther) {
    if (otherBox) otherBox.style.display = 'block';
    if (otherInput) {
      otherInput.required = true;
      otherInput.focus();
    }
  } else {
    if (otherBox) otherBox.style.display = 'none';
    if (otherInput) otherInput.required = false;
  }
}

function selectQuickSchool(schoolName) {
  const input = document.getElementById('f137_transferee_school');
  if (input) {
    input.value = schoolName;
    handleTransfereeSchoolChange(input);
  }
}

// Document-specific guidance and reminders
const docGuidance = {
  // Teacher Documents
  'COE': {
    badge: 'Faculty Guide',
    badgeColor: '#1e40af',
    badgeBg: '#dbeafe',
    text: '<strong>Certificate of Employment (COE):</strong> Piliin kung <strong>With Compensation Breakdown (Gross Monthly Salary)</strong> o <strong>Without Compensation</strong> sa form, at ilagay ang purpose o addressed institution (e.g., Bank loan, Visa application, GSIS).'
  },
  'LC': {
    badge: 'Faculty Guide',
    badgeColor: '#1e40af',
    badgeBg: '#dbeafe',
    text: '<strong>Leave Credits:</strong> Ilagay sa <em>Purpose / Reason</em> ang <strong>For Update Service Credit / Absent</strong> o iba pang layunin, at ilagay ang inclusive calendar years sa <em>Additional Notes</em> kung may nais tukuyin.'
  },
  'SR': {
    badge: 'Faculty Guide',
    badgeColor: '#1e40af',
    badgeBg: '#dbeafe',
    text: '<strong>Service Record:</strong> State if requested for Step Increment, Loyalty Pay, Promotion / Ranking, or GSIS / Retirement processing, and the inclusive years needed.'
  },
  'CR': {
    badge: 'Faculty Guide',
    badgeColor: '#1e40af',
    badgeBg: '#dbeafe',
    text: '<strong>Certificate of Rating:</strong> Required na ilagay ang inyong numerical rating grade para sa 3 magkasunod na School Years sa lalabas na panel sa ibaba. Ibe-verify ito ng Administrator sa official school records bago i-release.'
  },

  // Student Documents
  'F137': {
    badge: 'Student Guide',
    badgeColor: '#065f46',
    badgeBg: '#d1fae5',
    text: '<strong>Form 137 / SF10:</strong> Provide the exact name and address of the receiving school/university and your last attended Grade & Section at TagSci in <em>Additional Notes</em>.'
  },
  'COG': {
    badge: 'Student Guide',
    badgeColor: '#065f46',
    badgeBg: '#d1fae5',
    text: '<strong>Certificate of Grades:</strong> State the specific Grade Level, School Year, and grading quarters/semester needed.'
  },
  'COE_ST': {
    badge: 'Student Guide',
    badgeColor: '#065f46',
    badgeBg: '#d1fae5',
    text: '<strong>Certificate of Enrollment:</strong> State your current grade level and section for official enrollment validation.'
  },
  'GMC': {
    badge: 'Student Guide',
    badgeColor: '#065f46',
    badgeBg: '#d1fae5',
    text: '<strong>Good Moral Certificate:</strong> Specify if needed for College Admission, Scholarship Application, or Internship in the <em>Purpose</em> field.'
  }
};

function calculateRatingAvg() {
  const r1 = parseFloat(document.getElementById('rating_year_1').value) || 0;
  const r2 = parseFloat(document.getElementById('rating_year_2').value) || 0;
  const r3 = parseFloat(document.getElementById('rating_year_3').value) || 0;
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

function autoSuggestSalaryGrade(pos) {
  const sgSelect = document.getElementById('emp_salary_grade');
  if (!sgSelect || !pos) return;

  const map = {
    'Teacher I': 'SG 11',
    'Teacher II': 'SG 12',
    'Teacher III': 'SG 13',
    'Special Science Teacher I': 'SG 13',
    'Special Science Teacher II': 'SG 14',
    'Teacher IV': 'SG 14',
    'Teacher V': 'SG 15',
    'Teacher VI': 'SG 16',
    'Teacher VII': 'SG 17',
    'Master Teacher I': 'SG 18',
    'Master Teacher II': 'SG 19',
    'Master Teacher III': 'SG 20',
    'Master Teacher IV': 'SG 21',
    'Head Teacher I': 'SG 15',
    'Head Teacher II': 'SG 16',
    'Head Teacher III': 'SG 17',
    'Head Teacher IV': 'SG 18',
    'Head Teacher V': 'SG 19',
    'Head Teacher VI': 'SG 20',
    'Assistant School Principal I': 'SG 19',
    'Assistant School Principal II': 'SG 20',
    'School Principal I': 'SG 21',
    'School Principal II': 'SG 22',
    'School Principal III': 'SG 23',
    'School Principal IV': 'SG 24',
    'Administrative Officer II': 'SG 11',
    'Administrative Assistant III': 'SG 9',
    'Administrative Assistant II': 'SG 8',
    'Administrative Aide VI': 'SG 6',
    'Registrar I': 'SG 11',
    'Guidance Counselor I': 'SG 11'
  };

  if (map[pos]) {
    sgSelect.value = map[pos];
    // Gentle pulse visual cue
    sgSelect.style.borderColor = '#16a34a';
    sgSelect.style.backgroundColor = '#f0fdf4';
    setTimeout(() => {
      sgSelect.style.borderColor = '';
      sgSelect.style.backgroundColor = '#ffffff';
    }, 1200);
  }
}

function updateGmcGradStatus() {
  const statusSelect = document.getElementById('gmc_status');
  const yearInput = document.getElementById('gmc_year_graduated');
  const yearBadge = document.getElementById('gmc_year_req_badge');
  const yearHint = document.getElementById('gmc_year_hint');
  
  if (!statusSelect || !yearInput) return;

  const docTypeSelect = document.getElementById('doc_type_id');
  const curCode = docTypeSelect ? docTypeSelect.options[docTypeSelect.selectedIndex]?.getAttribute('data-code') : '';

  if (statusSelect.value === 'Graduated') {
    if (yearBadge) {
      yearBadge.innerHTML = '<span style="color: #ef4444; font-weight: 800;">*</span> <span style="font-weight: 700; color: #166534; background: #dcfce7; padding: 1px 6px; border-radius: 4px; font-size: 0.72rem;">Required para sa Alumnus</span>';
    }
    if (yearHint) {
      yearHint.textContent = 'Pumili sa listahan ng taon ng inyong pagtatapos (hal. 2024 / Batch 2023).';
    }
    if (['GMC', 'COG', 'COE_ST'].includes(curCode)) {
      yearInput.required = true;
    }
  } else {
    if (yearBadge) {
      yearBadge.innerHTML = '(Optional kung Currently Enrolled)';
    }
    if (yearHint) {
      yearHint.textContent = 'Kung hindi pa graduate, iwanang blangko o piliin ang inaasahang taon.';
    }
    yearInput.required = false;
  }
}

function updateRequirements(select) {
  const selectedOption = select.options[select.selectedIndex];
  const box = document.getElementById('requirements-box');
  const ratingsSection = document.getElementById('ratings-section');
  const facultyGroup = document.getElementById('faculty-emp-fields-group');
  const facultyTitle = document.getElementById('faculty-emp-fields-title');
  const withCompGroup = document.getElementById('with-compensation-group');
  const generalPurposeGroup = document.getElementById('general-purpose-group');
  const generalPurposeInput = document.getElementById('general_purpose_input');
  const generalPurposeLabel = document.getElementById('general-purpose-label');
  const generalPurposeHint = document.getElementById('general-purpose-hint');
  const f137Group = document.getElementById('f137-fields-group');
  const gmcGroup = document.getElementById('gmc-fields-group');
  const generalNotes = document.getElementById('general-notes-group');
  const generalNotesLabel = document.getElementById('general-notes-label');
  const generalNotesInput = document.getElementById('general_notes_input');
  const generalNotesHint = document.getElementById('general-notes-hint');

  const empName = document.getElementById('emp_full_name');
  const empNum = document.getElementById('emp_number');
  const empPos = document.getElementById('emp_position');
  const empSg = document.getElementById('emp_salary_grade');
  const empStep = document.getElementById('emp_step');
  const empDh = document.getElementById('emp_date_hired');
  const empPurp = document.getElementById('emp_purpose');

  const fSec = document.getElementById('f137_recent_section');
  const fYr = document.getElementById('f137_year_graduated');
  const fSch = document.getElementById('f137_transferee_school');

  const gmcName = document.getElementById('gmc_full_name');
  const gmcSec = document.getElementById('gmc_section_strand');
  const gmcPurp = document.getElementById('gmc_purpose_school');
  const gmcYear = document.getElementById('gmc_year_graduated');

  const attLabel = document.getElementById('attachment-label');
  const attOptionalBadge = document.getElementById('attachment-optional-badge');
  const attChosen = document.getElementById('file-chosen-text');
  const attHint = document.getElementById('attachment-hint-text');
  const guardText = document.getElementById('smart-guard-text');
  const guardBox = document.getElementById('smart-guard-box');
  
  const fieldsContainer = document.getElementById('request-fields-container');
  
  if (!select.value) {
    if (fieldsContainer) fieldsContainer.style.display = 'none';
    box.className = 'form-control';
    box.style.background = '#f8fafc';
    box.innerHTML = '<span style="color: #94a3b8; font-size: 0.9rem;">Select a document type to view the requirements and guidelines</span>';
    if (ratingsSection) ratingsSection.style.display = 'none';
    if (facultyGroup) facultyGroup.style.display = 'none';
    if (f137Group) f137Group.style.display = 'none';
    if (gmcGroup) gmcGroup.style.display = 'none';
    if (generalPurposeGroup) generalPurposeGroup.style.display = 'none';
    if (generalPurposeInput) generalPurposeInput.required = false;
    if (generalNotes) generalNotes.style.display = 'none';
    return;
  }

  // Document type is selected: reveal dynamic fields container
  if (fieldsContainer) fieldsContainer.style.display = 'block';

  const name = selectedOption.getAttribute('data-name');
  const code = selectedOption.getAttribute('data-code');
  const reqData = selectedOption.getAttribute('data-req');
  let reqs = [];
  try {
    reqs = JSON.parse(reqData);
  } catch(e) {
    reqs = [];
  }

  box.className = 'requirements-panel';
  box.style.background = '#dcfce7';
  let html = `<div class="requirements-title">📋 ${name}</div><ul>`;
  reqs.forEach(r => {
    html += `<li><span style="color: #16a34a; font-weight: bold;">✔</span> ${r}</li>`;
  });
  html += `</ul>`;

  // Attach dynamic role-based guide tip if available
  if (docGuidance[code]) {
    const tip = docGuidance[code];
    html += `<div style="margin-top: 12px; background: #ffffff; border: 1px solid #cbd5e1; border-left: 4px solid ${tip.badgeColor}; border-radius: 6px; padding: 10px 14px; font-size: 0.83rem; color: #334155; line-height: 1.5;">
      <span style="display: inline-block; font-weight: 700; color: ${tip.badgeColor}; background: ${tip.badgeBg}; padding: 2px 8px; border-radius: 4px; font-size: 0.72rem; margin-right: 6px; text-transform: uppercase;">💡 ${tip.badge}</span>
      ${tip.text}
    </div>`;
  }

  box.innerHTML = html;

  // Toggle 3-Year Ratings input section for Certificate of Rating (CR)
  if (ratingsSection) {
    if (code === 'CR') {
      ratingsSection.style.display = 'block';
      const r1 = document.getElementById('rating_year_1');
      const r2 = document.getElementById('rating_year_2');
      const r3 = document.getElementById('rating_year_3');
      if (r1) r1.required = true;
      if (r2) r2.required = true;
      if (r3) r3.required = true;
      calculateRatingAvg();
    } else {
      ratingsSection.style.display = 'none';
      const r1 = document.getElementById('rating_year_1');
      const r2 = document.getElementById('rating_year_2');
      const r3 = document.getElementById('rating_year_3');
      if (r1) r1.required = false;
      if (r2) r2.required = false;
      if (r3) r3.required = false;
    }
  }

  // Toggle Dedicated COE & Service Record (SR) Fields vs GMC vs Form 137 vs General Purpose/Notes
  if (code === 'COE' || code === 'SR') {
    if (facultyGroup) facultyGroup.style.display = 'block';
    if (generalPurposeGroup) generalPurposeGroup.style.display = 'none';
    if (generalNotes) generalNotes.style.display = 'none';
    if (f137Group) f137Group.style.display = 'none';
    if (gmcGroup) gmcGroup.style.display = 'none';

    if (generalPurposeInput) generalPurposeInput.required = false;

    if (empName) empName.required = true;
    if (empNum) empNum.required = true;
    if (empPos) empPos.required = true;
    if (empSg) empSg.required = true;
    if (empStep) empStep.required = true;
    if (empDh) empDh.required = true;
    if (empPurp) empPurp.required = true;

    if (fSec) fSec.required = false;
    if (fYr) fYr.required = false;
    if (fSch) fSch.required = false;

    if (gmcName) gmcName.required = false;
    if (gmcSec) gmcSec.required = false;
    if (gmcPurp) gmcPurp.required = false;
    if (gmcYear) gmcYear.required = false;

    if (code === 'COE') {
      if (facultyTitle) facultyTitle.textContent = 'Certificate of Employment (COE) Employee Information';
      if (withCompGroup) withCompGroup.style.display = 'block';
    } else {
      // Service Record (SR) - Exclude With Compensation
      if (facultyTitle) facultyTitle.textContent = 'Service Record (SR) Employee Information';
      if (withCompGroup) withCompGroup.style.display = 'none';
    }
  } else if (code === 'GMC' || code === 'COG' || code === 'COE_ST') {
    if (gmcGroup) gmcGroup.style.display = 'block';
    if (facultyGroup) facultyGroup.style.display = 'none';
    if (generalPurposeGroup) generalPurposeGroup.style.display = 'none';
    if (generalNotes) generalNotes.style.display = 'none';
    if (f137Group) f137Group.style.display = 'none';

    if (generalPurposeInput) generalPurposeInput.required = false;

    if (empName) empName.required = false;
    if (empNum) empNum.required = false;
    if (empPos) empPos.required = false;
    if (empSg) empSg.required = false;
    if (empStep) empStep.required = false;
    if (empDh) empDh.required = false;
    if (empPurp) empPurp.required = false;

    if (fSec) fSec.required = false;
    if (fYr) fYr.required = false;
    if (fSch) fSch.required = false;

    if (gmcName) gmcName.required = true;
    if (gmcSec) gmcSec.required = true;
    if (gmcPurp) gmcPurp.required = true;

    // Dynamically adjust header and text for GMC vs COG vs COE_ST
    const docIcon = document.getElementById('student-doc-icon');
    const docTitle = document.getElementById('student-doc-title');
    const docBadge = document.getElementById('student-doc-badge');
    const docDesc = document.getElementById('student-doc-desc');

    const holdBox = document.getElementById('guidance-hold-warning-box');
    const submitBtn = document.getElementById('btn-submit-request');

    if (code === 'GMC') {
      if (docIcon) docIcon.textContent = '📜';
      if (docTitle) docTitle.textContent = 'Good Moral Certificate Student Information';
      if (docBadge) docBadge.textContent = 'Required by Guidance Office';
      if (docDesc) docDesc.textContent = 'Kinakailangan ng Guidance Office ang mga impormasyong ito upang mabilis at tumpak na maihanda ang inyong Certificate of Good Moral Character:';

      // Check Guidance Clearance Hold specifically for Good Moral Certificate
      const hasHold = <?= $has_guidance_hold ? 'true' : 'false' ?>;
      if (hasHold) {
        if (holdBox) holdBox.style.display = 'block';
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.style.opacity = '0.5';
          submitBtn.style.cursor = 'not-allowed';
          submitBtn.title = 'Naka-hold ang inyong Good Moral Certificate dahil sa pending Guidance record.';
        }
      } else {
        if (holdBox) holdBox.style.display = 'none';
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.style.opacity = '1';
          submitBtn.style.cursor = 'pointer';
          submitBtn.title = '';
        }
      }
    } else {
      if (holdBox) holdBox.style.display = 'none';
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor = 'pointer';
        submitBtn.title = '';
      }

      if (code === 'COG') {
        if (docIcon) docIcon.textContent = '📊';
        if (docTitle) docTitle.textContent = 'Certificate of Grades Student Information';
        if (docBadge) docBadge.textContent = 'Required by Registrar';
        if (docDesc) docDesc.textContent = 'Kinakailangan ng Registrar ang mga impormasyong ito upang mabilis at tumpak na maihanda ang inyong Certificate of Grades:';
      } else if (code === 'COE_ST') {
        if (docIcon) docIcon.textContent = '🎓';
        if (docTitle) docTitle.textContent = 'Certificate of Enrollment Student Information';
        if (docBadge) docBadge.textContent = 'Required by Registrar';
        if (docDesc) docDesc.textContent = 'Kinakailangan ng Registrar ang mga impormasyong ito upang ma-verify at maihanda ang inyong official Certificate of Enrollment:';
      }
    }

    updateGmcGradStatus();
  } else if (code === 'F137') {
    if (f137Group) f137Group.style.display = 'block';
    if (generalPurposeGroup) generalPurposeGroup.style.display = 'block';
    if (generalNotes) generalNotes.style.display = 'none';
    if (facultyGroup) facultyGroup.style.display = 'none';
    if (gmcGroup) gmcGroup.style.display = 'none';

    if (generalPurposeInput) generalPurposeInput.required = true;

    if (empName) empName.required = false;
    if (empNum) empNum.required = false;
    if (empPos) empPos.required = false;
    if (empSg) empSg.required = false;
    if (empStep) empStep.required = false;
    if (empDh) empDh.required = false;
    if (empPurp) empPurp.required = false;

    if (fSec) fSec.required = true;
    if (fYr) fYr.required = true;
    if (fSch) fSch.required = true;

    if (gmcName) gmcName.required = false;
    if (gmcSec) gmcSec.required = false;
    if (gmcPurp) gmcPurp.required = false;
    if (gmcYear) gmcYear.required = false;
  } else {
    if (facultyGroup) facultyGroup.style.display = 'none';
    if (f137Group) f137Group.style.display = 'none';
    if (gmcGroup) gmcGroup.style.display = 'none';
    if (generalPurposeGroup) generalPurposeGroup.style.display = 'block';
    if (generalNotes) generalNotes.style.display = 'block';

    if (generalPurposeInput) generalPurposeInput.required = true;

    if (empName) empName.required = false;
    if (empNum) empNum.required = false;
    if (empPos) empPos.required = false;
    if (empSg) empSg.required = false;
    if (empStep) empStep.required = false;
    if (empDh) empDh.required = false;
    if (empPurp) empPurp.required = false;

    if (fSec) fSec.required = false;
    if (fYr) fYr.required = false;
    if (fSch) fSch.required = false;

    if (gmcName) gmcName.required = false;
    if (gmcSec) gmcSec.required = false;
    if (gmcPurp) gmcPurp.required = false;
    if (gmcYear) gmcYear.required = false;
  }

  // Dynamically configure General Purpose and Notes fields based on selected document code
  if (code === 'LC') {
    if (generalPurposeLabel) generalPurposeLabel.innerHTML = 'Purpose / Reason <span style="color: #ef4444;">*</span>';
    if (generalPurposeInput) generalPurposeInput.placeholder = 'For Update Service Credit / Absent';
    if (generalPurposeHint) {
      generalPurposeHint.textContent = '';
      generalPurposeHint.style.display = 'none';
    }
    if (generalNotesLabel) generalNotesLabel.innerHTML = 'Additional Notes & Specific Instructions <span style="font-weight: normal; color: #64748b;">(Optional)</span>';
    if (generalNotesInput) generalNotesInput.placeholder = 'e.g., Inclusive dates or school year, special instructions...';
    if (generalNotesHint) {
      generalNotesHint.textContent = '';
      generalNotesHint.style.display = 'none';
    }
  } else if (code === 'CR') {
    if (generalPurposeLabel) generalPurposeLabel.innerHTML = 'Purpose / Reason <span style="color: #ef4444;">*</span>';
    if (generalPurposeInput) generalPurposeInput.placeholder = 'e.g., DepEd Promotion / Ranking, ERF / Reclassification, Step Increment';
    if (generalPurposeHint) {
      generalPurposeHint.textContent = 'Indicate the purpose for your Certificate of Rating request.';
      generalPurposeHint.style.display = 'block';
    }
    if (generalNotesLabel) generalNotesLabel.innerHTML = 'Additional Notes & Specific Instructions <span style="font-weight: normal; color: #64748b;">(Optional)</span>';
    if (generalNotesInput) generalNotesInput.placeholder = 'e.g., Inclusive 3 school years, special instructions...';
    if (generalNotesHint) {
      generalNotesHint.textContent = '';
      generalNotesHint.style.display = 'none';
    }
  } else if (code === 'F137') {
    if (generalPurposeLabel) generalPurposeLabel.innerHTML = 'Purpose / Reason <span style="color: #ef4444;">*</span>';
    if (generalPurposeInput) generalPurposeInput.placeholder = 'e.g., College Entrance Exam, Transfer to Another School, Scholarship';
    if (generalPurposeHint) {
      generalPurposeHint.textContent = 'Specify the purpose of this request (e.g., College Entrance Exam, Scholarship, Transferee).';
      generalPurposeHint.style.display = 'block';
    }
    if (generalNotesLabel) generalNotesLabel.innerHTML = 'Additional Notes <span style="font-weight: normal; color: #64748b;">(RECENT SECTION, SPECIFIC SCHOOL / TRANSFEREE UNIVERSITY)</span>';
    if (generalNotesInput) generalNotesInput.placeholder = 'e.g., Section 10-Einstein, transferring to PUP Manila, special instructions...';
    if (generalNotesHint) {
      generalNotesHint.textContent = 'Mention your recent grade level, section, school year, or destination university/company.';
      generalNotesHint.style.display = 'block';
    }
  } else if (!['COE', 'SR', 'GMC', 'COG', 'COE_ST'].includes(code)) {
    if (isTeacher) {
      if (generalPurposeLabel) generalPurposeLabel.innerHTML = 'Purpose / Reason <span style="color: #ef4444;">*</span>';
      if (generalPurposeInput) generalPurposeInput.placeholder = 'For Update Service Credit / Absent';
      if (generalPurposeHint) {
        generalPurposeHint.textContent = '';
        generalPurposeHint.style.display = 'none';
      }
      if (generalNotesLabel) generalNotesLabel.innerHTML = 'Additional Notes & Specific Instructions <span style="font-weight: normal; color: #64748b;">(Optional)</span>';
      if (generalNotesInput) generalNotesInput.placeholder = 'e.g., Inclusive dates or school year, special instructions...';
      if (generalNotesHint) {
        generalNotesHint.textContent = '';
        generalNotesHint.style.display = 'none';
      }
    } else {
      if (generalPurposeLabel) generalPurposeLabel.innerHTML = 'Purpose / Reason <span style="color: #ef4444;">*</span>';
      if (generalPurposeInput) generalPurposeInput.placeholder = 'e.g., College Admission, Scholarship Application, Transfer to Another School, Employment, etc.';
      if (generalPurposeHint) {
        generalPurposeHint.textContent = 'Specify the purpose of this request (e.g., College Entrance Exam, Scholarship, Transferee).';
        generalPurposeHint.style.display = 'block';
      }
      if (generalNotesLabel) generalNotesLabel.innerHTML = 'Additional Notes <span style="font-weight: normal; color: #64748b;">(RECENT SECTION, SPECIFIC SCHOOL / TRANSFEREE UNIVERSITY)</span>';
      if (generalNotesInput) generalNotesInput.placeholder = 'e.g., Section 10-Einstein, transferring to PUP Manila, special instructions...';
      if (generalNotesHint) {
        generalNotesHint.textContent = 'Mention your recent grade level, section, school year, or destination university/company.';
        generalNotesHint.style.display = 'block';
      }
    }
  }

  // Toggle Form 137 specific attachment requirements vs other documents
  if (code === 'F137') {
    if (attOptionalBadge) {
      attOptionalBadge.innerHTML = '<span style="color: #ef4444; font-weight: 800;">*</span> <span style="font-weight: 700; color: #b91c1c; background: #fee2e2; padding: 2px 8px; border-radius: 4px; font-size: 0.78rem;">Required — Mag-attach ng dokumento</span>';
    }
    if (attChosen && selectedFiles.length === 0) {
      attChosen.textContent = 'No file selected yet (Required). Paki-attach ang supporting document.';
    }
    if (attHint) {
      attHint.innerHTML = '<strong style="color: #b91c1c;">Required ang pag-attach ng supporting document para sa Form 137 / SF10:</strong> Mag-attach ng Formal Request Letter mula sa bagong paaralan, Valid School ID, o SF10 Request Form bago mag-submit.';
    }
    if (guardText && selectedFiles.length === 0) {
      guardText.textContent = 'Smart Guard: Required. Please select at least one supporting document (PDF, JPG, PNG - Max 10MB each).';
      if (guardBox) {
        guardBox.style.backgroundColor = '#fef3c7';
        guardBox.style.borderColor = '#fde68a';
        guardBox.style.color = '#92400e';
      }
    }
  } else {
    // Revert to Optional for other documents
    if (attOptionalBadge) {
      attOptionalBadge.innerHTML = '(Optional — Pwede mag-attach ng kahit ilan / Multiple Documents)';
    }
    if (attChosen && selectedFiles.length === 0) {
      attChosen.textContent = 'No file selected (Optional). Pwede pumili ng higit sa isang dokumento.';
    }
    if (attHint) {
      if (code === 'GMC') {
        attHint.innerHTML = 'Uploading files is optional. Maaari kang mag-attach ng inyong <strong>Guidance Clearance Form</strong> o <strong>Valid School ID</strong> kung mayroon na kayo. Allowed formats: PDF, JPG, PNG (hanggang 10MB bawat file).';
      } else if (code === 'COG') {
        attHint.innerHTML = 'Uploading files is optional. Maaari kang mag-attach ng inyong <strong>Previous Semester Grade Slip</strong> o <strong>Valid School ID</strong> kung mayroon na kayo. Allowed formats: PDF, JPG, PNG (hanggang 10MB bawat file).';
      } else if (code === 'COE_ST') {
        attHint.innerHTML = 'Uploading files is optional. Maaari kang mag-attach ng inyong <strong>Registration Card (COR)</strong> o <strong>Valid School ID</strong> kung mayroon na kayo. Allowed formats: PDF, JPG, PNG (hanggang 10MB bawat file).';
      } else {
        attHint.innerHTML = 'Uploading files is optional. Maaari kang mag-attach ng maraming dokumento sabay-sabay (hal. Valid ID, Formal Letter, SF10, Certificates). Allowed formats: PDF, JPG, PNG (hanggang 10MB bawat file).';
      }
    }
    if (guardText && selectedFiles.length === 0) {
      guardText.textContent = 'Smart Guard: Optional. Supports single or multiple documents. Allowed formats: PDF, JPG, PNG (Max 10MB each).';
      if (guardBox) {
        guardBox.style.backgroundColor = '#d1fae5';
        guardBox.style.borderColor = '#6ee7b7';
        guardBox.style.color = '#065f46';
      }
    }
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
  // Replace quotes, apostrophes, and potentially unsafe characters with underscores to prevent WAF / ModSecurity 403 blocks
  return name.replace(/['"`\\;<>^~#$%&*+=[\]{}|]/g, '_');
}

function handleFilesSelected(input) {
  const newFiles = Array.from(input.files || []);
  if (newFiles.length === 0) return;

  newFiles.forEach(nf => {
    let fileToAdd = nf;
    // Sanitize filename to prevent ModSecurity / LiteSpeed WAF 403 triggers (e.g. Student's ID.jpg)
    if (/['"`\\;<>^~#$%&*+=[\]{}|]/.test(nf.name)) {
      const cleanName = sanitizeFileName(nf.name);
      try {
        fileToAdd = new File([nf], cleanName, { type: nf.type, lastModified: nf.lastModified });
      } catch (e) {
        fileToAdd = nf;
      }
    }
    // Avoid exact duplicate additions (same name and size)
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

  // Sync in-memory files array back to HTML input via DataTransfer
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
    if (preview) { preview.innerHTML = ''; preview.style.display = 'none'; }
    const docTypeSelect = document.getElementById('doc_type_id');
    const curCode = docTypeSelect ? docTypeSelect.options[docTypeSelect.selectedIndex]?.getAttribute('data-code') : '';
    if (curCode === 'F137') {
      label.textContent = 'No file selected yet (Required). Paki-attach ang supporting document.';
      box.style.backgroundColor = '#fef3c7';
      box.style.borderColor = '#fde68a';
      box.style.color = '#92400e';
      text.textContent = 'Smart Guard: Required. Please select at least one supporting document (PDF, JPG, PNG - Max 10MB each).';
    } else {
      label.textContent = 'No file selected (Optional). Pwede pumili ng higit sa isang dokumento.';
      box.style.backgroundColor = '#d1fae5';
      box.style.borderColor = '#6ee7b7';
      box.style.color = '#065f46';
      text.textContent = 'Smart Guard: Optional. Supports single or multiple documents. Allowed formats: PDF, JPG, PNG (Max 10MB each).';
    }
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
      errorMsg = `Invalid format for "${f.name}". Only PDF, JPG, and PNG are allowed. ❌`;
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
    label.textContent = `${selectedFiles.length} document(s) chosen`;
    if (preview) { preview.innerHTML = fileListHtml; preview.style.display = 'flex'; }
    box.style.backgroundColor = '#fee2e2';
    box.style.borderColor = '#fca5a5';
    box.style.color = '#b91c1c';
    text.innerHTML = `<strong>Smart Guard Warning:</strong> ${errorMsg}`;
    return;
  }

  const totalFmt = totalBytes > 1024 * 1024 ? (totalBytes / (1024 * 1024)).toFixed(1) + ' MB' : Math.round(totalBytes / 1024) + ' KB';
  label.innerHTML = `<strong>${selectedFiles.length} ${selectedFiles.length > 1 ? 'Documents' : 'Document'} Selected</strong> (Total: ${totalFmt})`;
  if (preview) {
    preview.innerHTML = fileListHtml;
    preview.style.display = 'flex';
  }
  box.style.backgroundColor = '#dcfce7';
  box.style.borderColor = '#86efac';
  box.style.color = '#15803d';
  text.innerHTML = `<strong>Smart Guard:</strong> All ${selectedFiles.length} document(s) validated successfully! (Total size: ${totalFmt}) ✅`;
}

// Fallback alias
function validateSmartGuard(input) {
  handleFilesSelected(input);
}

// Ensure all attached files are sanitized right before form submission and validate Form 137
document.addEventListener('DOMContentLoaded', function() {
  const docTypeSelect = document.getElementById('doc_type_id');
  if (docTypeSelect) {
    updateRequirements(docTypeSelect);
  }

  const form = document.querySelector('form');
  if (form) {
    form.addEventListener('submit', function(e) {
      const docTypeSelect = document.getElementById('doc_type_id');
      const curCode = docTypeSelect ? docTypeSelect.options[docTypeSelect.selectedIndex]?.getAttribute('data-code') : '';

      // Validate copies input
      const copiesInput = document.getElementById('copies');
      if (copiesInput) {
        const val = copiesInput.value.trim();
        if (!/^[0-9]+$/.test(val) || parseInt(val) < 1 || parseInt(val) > 10) {
          e.preventDefault();
          alert('Invalid Number of Copies. Numbers only between 1 and 10.');
          copiesInput.focus();
          return false;
        }
      }

      // Check Form 137 inputs
      if (curCode === 'F137') {
        const f137Yr = document.getElementById('f137_year_graduated');
        if (f137Yr) {
          const val = f137Yr.value.trim();
          if (val === '' || !/^[0-9]{4}$/.test(val)) {
            e.preventDefault();
            alert('Pakipili po ang inyong Year Graduated / Last SY Attended mula sa dropdown list.');
            f137Yr.focus();
            showInputToastError(f137Yr, 'Please select a year');
            return false;
          }
        }

        const f137School = document.getElementById('f137_transferee_school');
        const otherInput = document.getElementById('f137_other_school_input');
        if (f137School && (f137School.value.includes('Others') || f137School.value.trim().toLowerCase() === 'others')) {
          if (otherInput && otherInput.value.trim() === '') {
            e.preventDefault();
            alert('Pakilagay po ang buong pangalan at campus address ng inyong transferee school sa ilalim ng "Others".');
            otherInput.focus();
            return false;
          }
        }
      }

      // Check Good Moral Certificate & Student Certificates
      if (['GMC', 'COG', 'COE_ST'].includes(curCode)) {
        if (curCode === 'GMC' && <?= $has_guidance_hold ? 'true' : 'false' ?>) {
          e.preventDefault();
          alert('Hindi po maaaring magsumite ng Good Moral Certificate dahil may aktibong Disciplinary Hold kayo sa Guidance Office. Mangyaring makipag-ugnayan sa Guidance Office para sa inyong clearance.');
          return false;
        }

        const gmcName = document.getElementById('gmc_full_name');
        if (gmcName && !/^[a-zA-Z\sñÑ.'-]+$/.test(gmcName.value.trim())) {
          e.preventDefault();
          alert('Student Full Name must only contain letters, spaces, hyphens, and periods (numbers and symbols are not allowed).');
          gmcName.focus();
          showInputToastError(gmcName, 'Letters only (letters, spaces, periods, hyphens)');
          return false;
        }

        const gmcYr = document.getElementById('gmc_year_graduated');
        const gmcStatus = document.getElementById('gmc_status')?.value;
        if (gmcYr) {
          const val = gmcYr.value.trim();
          if (gmcStatus === 'Graduated' && (val === '' || !/^[0-9]{4}$/.test(val))) {
            e.preventDefault();
            alert('Pakipili po ang taon ng inyong graduation mula sa dropdown (Required po para sa Graduated / Alumnus).');
            gmcYr.focus();
            showInputToastError(gmcYr, 'Please select graduation year');
            return false;
          } else if (val !== '' && !/^[0-9]{4}$/.test(val)) {
            e.preventDefault();
            alert('Invalid Year format: "' + val + '". 4-digit numbers lamang po ang ilagay (hal. 2024, 2026).');
            gmcYr.focus();
            showInputToastError(gmcYr, '4-digit numbers only (e.g. 2026)');
            return false;
          }
        }
      }

      // Check Faculty COE & Service Record inputs
      if (curCode === 'COE' || curCode === 'SR') {
        const empName = document.getElementById('emp_full_name');
        if (empName && !/^[a-zA-Z\sñÑ.'-]+$/.test(empName.value.trim())) {
          e.preventDefault();
          alert('Employee Full Name must only contain letters, spaces, hyphens, and periods (numbers and symbols are not allowed).');
          empName.focus();
          showInputToastError(empName, 'Letters only');
          return false;
        }
        const empNum = document.getElementById('emp_number');
        if (empNum && !/^[0-9-]+$/.test(empNum.value.trim())) {
          e.preventDefault();
          alert('Employee Number must contain numbers and hyphens only (letters are not allowed).');
          empNum.focus();
          showInputToastError(empNum, 'Numbers and hyphens only');
          return false;
        }
      }

      // Enforce file attachment for Form 137 / SF10
      if (curCode === 'F137' && selectedFiles.length === 0) {
        e.preventDefault();
        alert('Required po mag-attach ng supporting document para sa Form 137 / SF10 bago makapag-proceed (hal. Formal Request Letter o Valid ID).');
        const box = document.getElementById('smart-guard-box');
        const text = document.getElementById('smart-guard-text');
        if (box && text) {
          box.style.backgroundColor = '#fee2e2';
          box.style.borderColor = '#fca5a5';
          box.style.color = '#b91c1c';
          text.innerHTML = '<strong>Smart Guard:</strong> Required po mag-attach ng kahit 1 supporting document para sa Form 137 / SF10 bago mag-submit. ❌';
        }
        const attGroup = document.getElementById('attachment-form-group');
        if (attGroup) attGroup.scrollIntoView({ behavior: 'smooth' });
        return false;
      }

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
