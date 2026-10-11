<?php
$page_title_custom = '<div class="breadcrumb-nav">Dashboard &gt; Request &gt; Request Details</div><h1>Request Details</h1><p>Review and process the selected administrative request.</p>';
require_once __DIR__ . '/includes/header.php';

if ($user_role !== 'admin' && $user_role !== 'registrar') {
    die("<div class='content-card'><h2>Access Denied</h2><p>Registrar or Administrator privileges required.</p></div>");
}

$req_id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT r.*, u.full_name, u.role as requester_role, rt.name as request_type_name 
    FROM requests r
    JOIN users u ON r.user_id = u.id
    JOIN request_types rt ON r.request_type_id = rt.id
    WHERE r.id = ?");
$stmt->execute([$req_id]);
$req = $stmt->fetch();

$back_url = ($user_role === 'registrar') ? 'assigned_requests.php' : 'manage_requests.php';

if (!$req) {
    die("<div class='content-card'><h2>Request not found.</h2><p><a href='{$back_url}'>&laquo; Return to Requests</a></p></div>");
}

$alert_msg = '';

// Process Status Actions (Registrar & Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');
    $now_str = date('F j, Y g:i A');
    $actor_role = strtoupper($user_role);

    if ($action === 'review') {
        $update_sql = "UPDATE requests SET status = 'Under Review', current_stage = 2, stage2_time = ?, remarks = 'Requirements verified. Under administrative review.' WHERE id = ?";
        $pdo->prepare($update_sql)->execute([$now_str, $req_id]);

        $notif_msg = "Your request for {$req['request_type_name']} is now Under Review by the Office of the Registrar.";
        $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message) VALUES (?, ?, ?, ?)");
        $stmt_notif->execute([$req['user_id'], $req['tracking_number'], "Request {$req['tracking_number']} Under Review", $notif_msg]);

        log_audit_action($pdo, $user_name, $actor_role, "Moved Request {$req['tracking_number']} to Under Review");
        $alert_msg = "Request status updated to <strong>Under Review</strong>.";
        $req['status'] = 'Under Review';
        $req['current_stage'] = 2;

    } elseif ($action === 'process') {
        $update_sql = "UPDATE requests SET status = 'Processing', current_stage = 3, stage3_time = ?, remarks = 'Document is currently being prepared and encoded.' WHERE id = ?";
        $pdo->prepare($update_sql)->execute([$now_str, $req_id]);

        $notif_msg = "Your request for {$req['request_type_name']} is currently being processed and prepared.";
        $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message) VALUES (?, ?, ?, ?)");
        $stmt_notif->execute([$req['user_id'], $req['tracking_number'], "Request {$req['tracking_number']} Processing", $notif_msg]);

        log_audit_action($pdo, $user_name, $actor_role, "Moved Request {$req['tracking_number']} to Processing");
        $alert_msg = "Request status updated to <strong>Processing</strong>.";
        $req['status'] = 'Processing';
        $req['current_stage'] = 3;

    } elseif ($action === 'approve') {
        $update_sql = "UPDATE requests SET status = 'Ready for Release', current_stage = 4, stage4_time = ?, remarks = 'Document is ready for release. Please proceed to the Registrar\'s Office.' WHERE id = ?";
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute([$now_str, $req_id]);

        // Insert Notification for User
        $notif_msg = "Your request for {$req['request_type_name']} has been approved and marked Ready for Release by the Office of the Registrar. No Fee Required.";
        $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message, location) VALUES (?, ?, ?, ?, 'Please visit the Registrar\'s Office to claim your document.')");
        $stmt_notif->execute([$req['user_id'], $req['tracking_number'], "Request {$req['tracking_number']} Ready for Release", $notif_msg]);

        log_audit_action($pdo, $user_name, $actor_role, "Approved Request {$req['tracking_number']} ({$req['request_type_name']}) - Ready for Release");

        $alert_msg = "Request Approved Successfully! You have marked request <strong>{$req['tracking_number']}</strong> as Ready for Release. The requester has received an automated notification.";
        $req['status'] = 'Ready for Release';
        $req['current_stage'] = 4;

    } elseif ($action === 'revise') {
        $update_sql = "UPDATE requests SET status = 'Returned for Revision', remarks = ? WHERE id = ?";
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute([$remarks ?: 'Please re-upload a clear copy of required documents.', $req_id]);

        $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message) VALUES (?, ?, ?, ?)");
        $stmt_notif->execute([$req['user_id'], $req['tracking_number'], "Request {$req['tracking_number']} Needs Revision", "Your request needs revision: " . ($remarks ?: 'Please verify documents.')]);

        log_audit_action($pdo, $user_name, $actor_role, "Returned Request {$req['tracking_number']} for Revision");
        $alert_msg = "Request Returned for Revision. Requester has been notified.";
        $req['status'] = 'Returned for Revision';

    } elseif ($action === 'reject') {
        $update_sql = "UPDATE requests SET status = 'Rejected', remarks = ? WHERE id = ?";
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute([$remarks ?: 'Request rejected due to incomplete requirements.', $req_id]);

        $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message) VALUES (?, ?, ?, ?)");
        $stmt_notif->execute([$req['user_id'], $req['tracking_number'], "Request {$req['tracking_number']} Rejected", "Your request was rejected: " . ($remarks ?: 'Requirements incomplete.')]);

        log_audit_action($pdo, $user_name, $actor_role, "Rejected Request {$req['tracking_number']}");
        $alert_msg = "Request has been rejected.";
        $req['status'] = 'Rejected';
    }
}

$is_approved = ($req['status'] === 'Ready for Release' || $req['status'] === 'Released');
$status_class = strtolower(str_replace(' ', '-', $req['status']));
?>

<div class="content-card" style="max-width: 950px; margin: 0 auto;">
  <?php if (!empty($alert_msg)): ?>
    <div class="alert-success-banner" style="margin-bottom: 20px;">
      <div style="font-weight: 800; font-size: 1rem; margin-bottom: 4px;">✅ Status Updated</div>
      <div><?= $alert_msg ?></div>
    </div>
  <?php endif; ?>

  <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 20px;">
    <h2 style="font-size: 1.25rem; font-weight: 800; color: #0f172a;">Request Information</h2>
    <span class="badge-status <?= $status_class ?>" style="font-size: 0.9rem; padding: 6px 16px;">
      <?= htmlspecialchars($req['status']) ?>
    </span>
  </div>

  <div class="details-grid">
    <div>
      <div class="details-item-label">Request ID</div>
      <div class="details-item-val"><?= htmlspecialchars($req['tracking_number']) ?></div>
    </div>
    <div>
      <div class="details-item-label">Document Type</div>
      <div class="details-item-val"><?= htmlspecialchars($req['request_type_name']) ?></div>
    </div>
    <div>
      <div class="details-item-label"><?= strtoupper($req['requester_role']) ?></div>
      <div class="details-item-val"><?= htmlspecialchars($req['full_name']) ?></div>
    </div>
    <div>
      <div class="details-item-label">Date Submitted</div>
      <div class="details-item-val"><?= date('F j, Y g:i A', strtotime($req['created_at'])) ?></div>
    </div>
    <div style="grid-column: span 2;">
      <div class="details-item-label">Purpose</div>
      <div class="details-item-val"><?= htmlspecialchars($req['purpose']) ?></div>
    </div>
    <div style="grid-column: span 2;">
      <div class="details-item-label">Attached File</div>
      <div class="details-item-val" style="display: flex; align-items: center; gap: 8px;">
        <?php if (!empty($req['attached_filename'])): ?>
          <a href="view_file.php?id=<?= $req['id'] ?>" target="_blank" class="attachment-preview-btn" title="Click to view file in new tab">
            📎 <?= htmlspecialchars($req['attached_filename']) ?> (<?= htmlspecialchars($req['file_size_kb'] ?? 'File') ?>) ↗
          </a>
        <?php else: ?>
          <span style="color: #94a3b8;">None</span>
        <?php endif; ?>
        <span style="background: #dcfce7; color: #15803d; font-size: 0.75rem; font-weight: 700; padding: 2px 8px; border-radius: 6px;">Verified</span>
      </div>
    </div>
  </div>

  <!-- Smart Guard Verification Alert (Slide 16) -->
  <div class="smart-guard-box" style="background: #ecfdf5; border-color: #a7f3d0; margin-bottom: 15px;">
    <div>
      <strong>File Verified:</strong> The <?= strtolower($req['requester_role']) ?> uploaded the correct file (<strong><?= htmlspecialchars($req['attached_filename'] ?? 'document') ?></strong>). All requirements are complete.
    </div>
  </div>

  <!-- Fee Panel (Slide 16) -->
  <div class="requirements-panel" style="background: #f0fdf4; border-color: #bbf7d0; margin-bottom: 25px;">
    <div style="font-weight: 700; color: #166534;">Document Fee: FREE</div>
    <div style="font-size: 0.88rem; color: #166534; margin-top: 4px;">
      No Fee Required. This document is free of charge. The <?= strtolower($req['requester_role']) ?> can claim it directly at the Registrar's Office.
    </div>
  </div>

  <!-- Action Buttons for Registrar & Admin -->
  <form method="POST" id="action-form" class="details-actions-row" style="flex-wrap: wrap; gap: 10px;">
    <input type="hidden" name="action" id="action-type" value="">
    <input type="hidden" name="remarks" id="action-remarks" value="">

    <?php if ($req['status'] === 'Pending'): ?>
      <button type="button" class="btn-primary-cta" style="background: #0284c7; padding: 10px 18px; font-size: 0.9rem;" onclick="submitAction('review')">Mark Under Review</button>
    <?php endif; ?>
    <?php if ($req['status'] === 'Pending' || $req['status'] === 'Under Review'): ?>
      <button type="button" class="btn-primary-cta" style="background: #0d9488; padding: 10px 18px; font-size: 0.9rem;" onclick="submitAction('process')">Set to Processing</button>
    <?php endif; ?>
    <?php if ($req['status'] !== 'Ready for Release' && $req['status'] !== 'Released'): ?>
      <button type="button" class="btn-action-approve" onclick="submitAction('approve')">Mark Ready for Release</button>
    <?php endif; ?>
    <button type="button" class="btn-action-revise" onclick="submitAction('revise')">Return for Revision</button>
    <button type="button" class="btn-action-reject" onclick="submitAction('reject')">Reject</button>
    <a href="<?= $back_url ?>" class="btn-action-back" style="text-decoration: none; display: inline-flex; align-items: center;">Back</a>
  </form>
</div>

<script>
function submitAction(type) {
  const form = document.getElementById('action-form');
  const actionInput = document.getElementById('action-type');
  const remarksInput = document.getElementById('action-remarks');

  actionInput.value = type;

  if (type === 'reject') {
    const reason = prompt('Please enter reason for rejection:', 'Incomplete supporting documents.');
    if (reason === null) return;
    remarksInput.value = reason;
  } else if (type === 'revise') {
    const reason = prompt('Please enter revision instructions for requester:', 'Please re-upload a clear photocopy of your ID.');
    if (reason === null) return;
    remarksInput.value = reason;
  }

  form.submit();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
