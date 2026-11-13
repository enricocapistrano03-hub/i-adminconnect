<?php
$page_title_custom = '<div class="breadcrumb-nav"><a href="my_requests.php" style="color: inherit; text-decoration: none;">My Requests</a> &gt; Re-upload / Edit Document</div><h1>Re-upload Documents</h1><p>Update and resubmit the documents requested by the registrar.</p>';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/mailer.php';

$ref = trim($_GET['ref'] ?? '');
$id = (int)($_GET['id'] ?? 0);

if (empty($ref) && $id <= 0) {
    echo "<div class='content-card'><h2>Invalid Request</h2><p>Missing request reference. <a href='my_requests.php'>Return to My Requests</a></p></div>";
    include __DIR__ . '/includes/footer.php';
    exit();
}

// Fetch request record
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT r.*, rt.name as request_type_name, rt.requirements, u.full_name, u.email as requester_email, u.role as requester_role 
        FROM requests r 
        JOIN request_types rt ON r.request_type_id = rt.id 
        JOIN users u ON r.user_id = u.id 
        WHERE r.id = ? LIMIT 1");
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("SELECT r.*, rt.name as request_type_name, rt.requirements, u.full_name, u.email as requester_email, u.role as requester_role 
        FROM requests r 
        JOIN request_types rt ON r.request_type_id = rt.id 
        JOIN users u ON r.user_id = u.id 
        WHERE r.tracking_number = ? LIMIT 1");
    $stmt->execute([$ref]);
}

$req = $stmt->fetch();

if (!$req) {
    echo "<div class='content-card'><h2>Request Not Found</h2><p>Could not locate the specified request. <a href='my_requests.php'>Return to My Requests</a></p></div>";
    include __DIR__ . '/includes/footer.php';
    exit();
}

// Security Check: Only the requester (or admin/registrar) can edit
if ($user_role !== 'admin' && $user_role !== 'registrar' && (int)$req['user_id'] !== (int)$user_id) {
    echo "<div class='content-card'><h2>Access Denied</h2><p>You do not have permission to modify this request. <a href='my_requests.php'>Return to My Requests</a></p></div>";
    include __DIR__ . '/includes/footer.php';
    exit();
}

$success_msg = '';
$error_msg = '';

// Handle Re-upload Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requester_notes = trim($_POST['requester_notes'] ?? '');
    $uploaded_file_name = $req['attached_filename'];
    $file_size_formatted = $req['file_size_kb'];
    $has_new_file = false;

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
        $file_orig_name = basename($f['name']);
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
        if (!$has_new_file && empty($uploaded_file_name)) {
            $error_msg = "Please attach the required document requested for revision.";
        } else {
            $now_str = date('F j, Y g:i A');
            $new_remarks = !empty($requester_notes) 
                ? "Requester re-submitted revision on {$now_str}. Note: {$requester_notes}"
                : "Revised documents submitted by requester on {$now_str}. Ready for re-evaluation.";

            // Combine previous notes if any
            $updated_notes = $req['additional_notes'];
            if (!empty($requester_notes)) {
                $updated_notes = trim(($req['additional_notes'] ? $req['additional_notes'] . "\n" : '') . "[Revision " . date('m/d/Y') . "]: " . $requester_notes);
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
            log_audit_action($pdo, $user_name, $user_role, "Re-uploaded revised documents for {$req['tracking_number']}");

            // Notify staff
            if ($user_role === 'teacher') {
                $staff_stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'Active'");
                $target_loc = 'Faculty Request Management';
            } else {
                $staff_stmt = $pdo->query("SELECT id FROM users WHERE role = 'registrar' AND status = 'Active'");
                $target_loc = 'Assigned Requests';
            }

            $notif_insert = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message, location) VALUES (?, ?, ?, ?, ?)");
            foreach ($staff_stmt->fetchAll() as $staff) {
                $notif_insert->execute([
                    $staff['id'],
                    $req['tracking_number'],
                    "Revision Submitted for {$req['tracking_number']}",
                    "{$user_name} re-uploaded the required documents for {$req['request_type_name']}.",
                    $target_loc
                ]);
            }

            // Confirmation notification for user
            $notif_insert->execute([
                $req['user_id'],
                $req['tracking_number'],
                "Revision Submitted: {$req['tracking_number']}",
                "Your updated documents for {$req['request_type_name']} have been submitted and are now being processed.",
                'My Requests'
            ]);

            // Dispatch Live Real-Time Email to Requester
            if (!empty($req['requester_email'])) {
                send_request_status_email(
                    $req['requester_email'],
                    $req['full_name'],
                    $req['tracking_number'],
                    $req['request_type_name'],
                    'Processing',
                    "Your updated documents have been submitted and are now being processed by the office.",
                    'Office of the Registrar'
                );
            }

            $success_msg = "Your revised documents for <strong>{$req['tracking_number']}</strong> have been successfully submitted! The request status has been updated to <strong>Processing</strong>, and a real-time email confirmation has been sent to your registered inbox.";

            // Refresh current request record
            $stmt = $pdo->prepare("SELECT r.*, rt.name as request_type_name, rt.requirements, u.full_name, u.email as requester_email, u.role as requester_role 
                FROM requests r 
                JOIN request_types rt ON r.request_type_id = rt.id 
                JOIN users u ON r.user_id = u.id 
                WHERE r.id = ? LIMIT 1");
            $stmt->execute([$req['id']]);
            $req = $stmt->fetch();
        }
    }
}

$status_class = strtolower(str_replace(' ', '-', $req['status']));
?>

<div class="content-card" style="max-width: 850px; margin: 0 auto;">
  <?php if (!empty($success_msg)): ?>
    <div class="alert-success-banner" style="margin-bottom: 22px;">
      <div style="font-weight: 800; font-size: 1.05rem; margin-bottom: 4px;">✅ Re-upload Successful!</div>
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

  <!-- Revision Alert Callout (Highlights registrar's feedback) -->
  <div style="background: #fffbeb; border: 1.5px solid #f59e0b; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px;">
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 6px;">
      <div style="font-weight: 800; color: #b45309; font-size: 1rem; display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 1.2rem;">⚠️</span> Registrar / Office Revision Instructions
      </div>
      <span class="badge-status <?= $status_class ?>"><?= htmlspecialchars($req['status']) ?></span>
    </div>
    <div style="font-size: 0.92rem; color: #78350f; line-height: 1.5; background: #fef3c7; padding: 10px 14px; border-radius: 8px; margin-top: 8px;">
      <strong>Remarks:</strong> <?= htmlspecialchars($req['remarks'] ?: 'Please re-upload a clear copy of required documents.') ?>
    </div>
    <div style="font-size: 0.82rem; color: #92400e; margin-top: 8px;">
      Please attach the corrected document below. Your Request ID (<strong><?= htmlspecialchars($req['tracking_number']) ?></strong>) and original request details will remain unchanged.
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
          <span style="color: #94a3b8;">No file previously attached</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Re-upload Form -->
  <form method="POST" enctype="multipart/form-data">
    <div class="form-group">
      <label class="form-label">
        Upload Corrected / Supporting Document(s) <span style="color: #ef4444;">*</span>
        <span style="font-weight: normal; color: #64748b;">(Pwede mag-attach ng kahit ilan / Multiple Documents — PDF, JPG, PNG)</span>
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

    <div class="form-group">
      <label class="form-label">Requester Notes / Explanation to Registrar <span style="font-weight: normal; color: #64748b;">(Optional)</span></label>
      <textarea 
        name="requester_notes" 
        class="form-control" 
        rows="3" 
        placeholder="Explain any corrections made or details requested by the registrar..."
      ></textarea>
    </div>

    <div style="display: flex; gap: 12px; margin-top: 25px; align-items: center;">
      <button type="submit" class="btn-primary-cta" style="background: #059669; padding: 10px 24px; font-size: 0.95rem;">
        ✔ Submit Revised Document
      </button>
      <a href="my_requests.php" class="btn-primary-cta" style="background: #ffffff; color: #475569; border: 1px solid #cbd5e1; box-shadow: none; text-decoration: none; padding: 10px 20px;">
        ✕ Cancel
      </a>
    </div>
  </form>
</div>

<script>
let selectedFiles = [];

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function handleFilesSelected(input) {
  const newFiles = Array.from(input.files || []);
  if (newFiles.length === 0) return;

  newFiles.forEach(nf => {
    const exists = selectedFiles.some(f => f.name === nf.name && f.size === nf.size);
    if (!exists) {
      selectedFiles.push(nf);
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

  if (window.DataTransfer) {
    const dt = new DataTransfer();
    selectedFiles.forEach(f => dt.items.add(f));
    input.files = dt.files;
  }

  if (selectedFiles.length === 0) {
    input.value = '';
    label.textContent = 'No new file selected yet. You can select multiple documents at once.';
    if (preview) { preview.innerHTML = ''; preview.style.display = 'none'; }
    box.style.backgroundColor = '#d1fae5';
    box.style.borderColor = '#6ee7b7';
    box.style.color = '#065f46';
    text.textContent = 'Smart Guard: Select clear replacement or supporting document(s) to validate format and size.';
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
  text.innerHTML = `<strong>Smart Guard:</strong> All ${selectedFiles.length} document(s) validated successfully. (Total size: ${totalFmt}) ✅`;
}

function validateSmartGuard(input) {
  handleFilesSelected(input);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
