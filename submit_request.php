<?php
$page_title_custom = '<div class="breadcrumb-nav">Dashboard &gt; New Request</div><h1>Submit a New Request</h1><p>Select the document type to see the specific requirements.</p>';
require_once __DIR__ . '/includes/header.php';

$success_msg = '';
$error_msg = '';

// Available document types for this user role
$stmt = $pdo->prepare("SELECT * FROM request_types WHERE target_role = ? OR target_role = 'both' ORDER BY id ASC");
$stmt->execute([$user_role]);
$available_types = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $doc_type_id = (int)($_POST['doc_type_id'] ?? 0);
    $purpose = trim($_POST['purpose'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($doc_type_id <= 0 || empty($purpose)) {
        $error_msg = 'Please select a document type and fill in the purpose.';
    } else {
        $uploaded_file_name = 'Document.pdf';
        $file_size_formatted = '120 KB';

        // Process File Upload if attached
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['attachment']['tmp_name'];
            $file_orig_name = basename($_FILES['attachment']['name']);
            $file_size = $_FILES['attachment']['size'];
            $file_ext = strtolower(pathinfo($file_orig_name, PATHINFO_EXTENSION));

            $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];

            if (!in_array($file_ext, $allowed_exts)) {
                $error_msg = "Invalid file type (.{$file_ext}). Allowed formats: PDF, JPG, PNG.";
            } elseif ($file_size > 5 * 1024 * 1024) {
                $error_msg = "File is too large. Maximum allowed size is 5MB.";
            } else {
                $unique_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file_orig_name);
                $destination = __DIR__ . '/uploads/' . $unique_name;
                if (move_uploaded_file($file_tmp, $destination)) {
                    $uploaded_file_name = $file_orig_name;
                    $file_size_formatted = $file_size > 1024 * 1024 
                        ? round($file_size / (1024 * 1024), 1) . ' MB' 
                        : round($file_size / 1024) . ' KB';
                }
            }
        }

        if (empty($error_msg)) {
            // Generate next unique tracking number by extracting max numeric suffix
            // This avoids duplicate key errors with seeded data (RQ-001, RQ-010, etc.)
            $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(tracking_number, 4) AS UNSIGNED)) as max_num FROM requests WHERE tracking_number REGEXP '^RQ-[0-9]+$'");
            $max_num = (int)($stmt->fetchColumn() ?? 0) + 1;
            $tracking_num = 'RQ-' . str_pad($max_num, 3, '0', STR_PAD_LEFT);

            $now_str = date('F j, Y g:i A');

            $insert_sql = "INSERT INTO requests 
                (tracking_number, user_id, request_type_id, purpose, additional_notes, attached_filename, file_size_kb, file_verified, status, current_stage, stage1_time, remarks) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'Pending', 1, ?, 'Request submitted. Awaiting administrative review.')";
            $stmt = $pdo->prepare($insert_sql);
            $stmt->execute([
                $tracking_num,
                $user_id,
                $doc_type_id,
                $purpose,
                $notes,
                $uploaded_file_name,
                $file_size_formatted,
                $now_str
            ]);

            // Audit log
            log_audit_action($pdo, $user_name, $user_role, "Submitted New Request {$tracking_num}");

            // Fetch request type name for alert
            $stmt = $pdo->prepare("SELECT name FROM request_types WHERE id = ?");
            $stmt->execute([$doc_type_id]);
            $type_name = $stmt->fetchColumn();

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

            $notif_insert = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message, location) VALUES (?, ?, ?, ?, ?)");
            foreach ($staff_stmt->fetchAll() as $staff) {
                $notif_insert->execute([
                    $staff['id'],
                    $tracking_num,
                    "New Request {$tracking_num} Received",
                    "{$user_name} submitted a new request for {$type_name}.",
                    $target_loc
                ]);
            }

            $success_msg = "Your request for <strong>{$type_name}</strong> (<strong>{$tracking_num}</strong>) has been submitted to the <strong>{$target_office}</strong>. You will receive a notification once your document is processed. No fee required.";
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
          <option value="<?= $t['id'] ?>" data-req='<?= htmlspecialchars($t['requirements'], ENT_QUOTES) ?>' data-name="<?= htmlspecialchars($t['name']) ?>">
            <?= htmlspecialchars($t['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Dynamic Requirements Panel (Slide 11, 13) -->
    <div class="form-group">
      <label class="form-label">Requirements</label>
      <div id="requirements-box" class="form-control" style="background: #f8fafc; min-height: 48px; display: flex; align-items: center;">
        <span style="color: #94a3b8; font-size: 0.9rem;">Select a document type to view the requirements</span>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Purpose / Reason <span style="color: #ef4444;">*</span></label>
      <input 
        type="text" 
        name="purpose" 
        class="form-control" 
        placeholder="e.g., For promotion, Job application, College Admission, etc." 
        required 
      />
    </div>

    <div class="form-group">
      <label class="form-label">Additional Notes <span style="font-weight: normal; color: #64748b;">(Optional)</span></label>
      <input 
        type="text" 
        name="notes" 
        class="form-control" 
        placeholder="Any special instructions..." 
      />
    </div>

    <div class="form-group">
      <label class="form-label">Attach Supporting Document <span style="font-weight: normal; color: #64748b;">(PDF, JPG, PNG — Max 5MB)</span></label>
      <div style="display: flex; align-items: center; gap: 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 8px 12px;">
        <label for="attachment" style="background: #e2e8f0; border: 1px solid #cbd5e1; padding: 6px 16px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; cursor: pointer; color: #1e293b;">
          Browse...
        </label>
        <input type="file" name="attachment" id="attachment" style="display: none;" onchange="validateSmartGuard(this)" />
        <span id="file-chosen-text" style="font-size: 0.9rem; color: #64748b;">No file selected.</span>
      </div>

      <!-- Smart Guard Bar (Slide 10, 11) -->
      <div id="smart-guard-box" class="smart-guard-box">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <span id="smart-guard-text">Smart Guard: No file uploaded yet. Allowed formats: PDF, JPG, PNG..</span>
      </div>
    </div>

    <div style="display: flex; gap: 12px; margin-top: 25px;">
      <button type="submit" class="btn-primary-cta">Submit Request</button>
      <a href="dashboard.php" class="btn-primary-cta" style="background: #ffffff; color: #475569; border: 1px solid #cbd5e1; box-shadow: none; text-decoration: none;">✕ Cancel</a>
    </div>
  </form>
</div>

<script>
function updateRequirements(select) {
  const selectedOption = select.options[select.selectedIndex];
  const box = document.getElementById('requirements-box');
  
  if (!select.value) {
    box.className = 'form-control';
    box.style.background = '#f8fafc';
    box.innerHTML = '<span style="color: #94a3b8; font-size: 0.9rem;">Select a document type to view the requirements</span>';
    return;
  }

  const name = selectedOption.getAttribute('data-name');
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
  html += `<li><span style="color: #16a34a; font-weight: bold;">💰</span> Fee: NO fee required</li></ul>`;
  html += `<span class="fee-badge">Fee: FREE</span>`;
  box.innerHTML = html;
}

function validateSmartGuard(input) {
  const file = input.files[0];
  const label = document.getElementById('file-chosen-text');
  const box = document.getElementById('smart-guard-box');
  const text = document.getElementById('smart-guard-text');

  if (!file) {
    label.textContent = 'No file selected.';
    box.style.backgroundColor = '#d1fae5';
    box.style.borderColor = '#6ee7b7';
    box.style.color = '#065f46';
    text.textContent = 'Smart Guard: No file uploaded yet. Allowed formats: PDF, JPG, PNG..';
    return;
  }

  const ext = file.name.split('.').pop().toLowerCase();
  const allowed = ['pdf', 'jpg', 'jpeg', 'png'];

  if (!allowed.includes(ext)) {
    label.textContent = file.name;
    box.style.backgroundColor = '#fee2e2';
    box.style.borderColor = '#fca5a5';
    box.style.color = '#b91c1c';
    text.innerHTML = `<strong>Smart Guard Warning:</strong> Invalid file type (.${ext}). Allowed: PDF, JPG, PNG. ❌`;
    return;
  }

  if (file.size > 5 * 1024 * 1024) {
    label.textContent = file.name;
    box.style.backgroundColor = '#fee2e2';
    box.style.borderColor = '#fca5a5';
    box.style.color = '#b91c1c';
    text.innerHTML = `<strong>Smart Guard Warning:</strong> File exceeds 5MB limit. ❌`;
    return;
  }

  const sizeFormatted = file.size > 1024 * 1024 ? (file.size / (1024 * 1024)).toFixed(1) + ' MB' : Math.round(file.size / 1024) + ' KB';
  label.innerHTML = `<strong>${file.name}</strong> (${sizeFormatted})`;
  box.style.backgroundColor = '#dcfce7';
  box.style.borderColor = '#86efac';
  box.style.color = '#15803d';
  text.innerHTML = `<strong>Smart Guard:</strong> File validated. Type: ${ext.toUpperCase()}, Size: ${sizeFormatted} ✅`;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
