<?php
$page_title_custom = '<div class="breadcrumb-nav">Dashboard &gt; Request &gt; Request Details</div><h1>Request Details</h1><p>Review and process the selected administrative request.</p>';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/mailer.php';

if ($user_role !== 'admin' && $user_role !== 'registrar') {
    die("<div class='content-card'><h2>Access Denied</h2><p>Registrar or Administrator privileges required.</p></div>");
}

$req_id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT r.*, u.full_name, u.email as requester_email, u.role as requester_role, rt.name as request_type_name, rt.code as request_type_code 
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

        // Send Real-Time Email Notification
        $mail_info = '';
        if (!empty($req['requester_email'])) {
            $m_res = send_request_status_email(
                $req['requester_email'],
                $req['full_name'],
                $req['tracking_number'],
                $req['request_type_name'],
                'Under Review',
                'Requirements verified. Your request is now under administrative review.',
                'Office of the Registrar'
            );
            if ($m_res['success']) {
                $mail_info = " &bull; Real-time email sent to <strong>{$req['requester_email']}</strong>";
            }
        }

        log_audit_action($pdo, $user_name, $actor_role, "Moved Request {$req['tracking_number']} to Under Review");
        $alert_msg = "Request status updated to <strong>Under Review</strong>.{$mail_info}";
        $req['status'] = 'Under Review';
        $req['current_stage'] = 2;

    } elseif ($action === 'process') {
        $update_sql = "UPDATE requests SET status = 'Processing', current_stage = 2, stage2_time = ?, remarks = 'Document is currently being prepared and encoded.' WHERE id = ?";
        $pdo->prepare($update_sql)->execute([$now_str, $req_id]);

        $notif_msg = "Your request for {$req['request_type_name']} is currently being processed and prepared.";
        $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message) VALUES (?, ?, ?, ?)");
        $stmt_notif->execute([$req['user_id'], $req['tracking_number'], "Request {$req['tracking_number']} Processing", $notif_msg]);

        // Send Real-Time Email Notification
        $mail_info = '';
        if (!empty($req['requester_email'])) {
            $m_res = send_request_status_email(
                $req['requester_email'],
                $req['full_name'],
                $req['tracking_number'],
                $req['request_type_name'],
                'Processing',
                'Document is currently being prepared and encoded.',
                'Office of the Registrar'
            );
            if ($m_res['success']) {
                $mail_info = " &bull; Real-time email sent to <strong>{$req['requester_email']}</strong>";
            }
        }

        log_audit_action($pdo, $user_name, $actor_role, "Moved Request {$req['tracking_number']} to Processing");
        $alert_msg = "Request status updated to <strong>Processing</strong>.{$mail_info}";
        $req['status'] = 'Processing';
        $req['current_stage'] = 2;

    } elseif ($action === 'approve') {
        $update_sql = "UPDATE requests SET status = 'Ready for Release', current_stage = 3, stage3_time = ?, remarks = 'Document is ready for release. Please proceed to the Registrar\'s Office.' WHERE id = ?";
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute([$now_str, $req_id]);

        // Insert Notification for User
        $notif_msg = "Your request for {$req['request_type_name']} has been approved and marked Ready for Release by the Office of the Registrar. No Fee Required.";
        $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message, location) VALUES (?, ?, ?, ?, 'Please visit the Registrar\'s Office to claim your document.')");
        $stmt_notif->execute([$req['user_id'], $req['tracking_number'], "Request {$req['tracking_number']} Ready for Release", $notif_msg]);

        // Send Real-Time Email Notification
        $mail_info = '';
        if (!empty($req['requester_email'])) {
            $m_res = send_request_status_email(
                $req['requester_email'],
                $req['full_name'],
                $req['tracking_number'],
                $req['request_type_name'],
                'Ready for Release',
                'Your request has been approved and marked Ready for Release. Please proceed to the Registrar\'s Office to claim your document. No fee required.',
                'Office of the Registrar'
            );
            if ($m_res['success']) {
                $mail_info = " &bull; Real-time notification email sent to <strong>{$req['requester_email']}</strong>";
            }
        }

        log_audit_action($pdo, $user_name, $actor_role, "Approved Request {$req['tracking_number']} ({$req['request_type_name']}) - Ready for Release");

        $alert_msg = "Request Approved Successfully! You have marked request <strong>{$req['tracking_number']}</strong> as Ready for Release.{$mail_info}";
        $req['status'] = 'Ready for Release';
        $req['current_stage'] = 3;

    } elseif ($action === 'complete') {
        $update_sql = "UPDATE requests SET status = 'Completed', current_stage = 4, stage4_time = ?, remarks = 'Document released and transaction successfully completed.' WHERE id = ?";
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute([$now_str, $req_id]);

        // Insert Notification for User
        $notif_msg = "Your request for {$req['request_type_name']} ({$req['tracking_number']}) has been officially completed and released.";
        $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message, location) VALUES (?, ?, ?, ?, 'Registrar\'s Office')");
        $stmt_notif->execute([$req['user_id'], $req['tracking_number'], "Request {$req['tracking_number']} Completed", $notif_msg]);

        // Send Real-Time Email Notification
        $mail_info = '';
        if (!empty($req['requester_email'])) {
            $m_res = send_request_status_email(
                $req['requester_email'],
                $req['full_name'],
                $req['tracking_number'],
                $req['request_type_name'],
                'Completed',
                'Your document has been released and the transaction is officially completed.',
                'Office of the Registrar'
            );
            if ($m_res['success']) {
                $mail_info = " &bull; Real-time confirmation email sent to <strong>{$req['requester_email']}</strong>";
            }
        }

        log_audit_action($pdo, $user_name, $actor_role, "Marked Request {$req['tracking_number']} ({$req['request_type_name']}) as Completed");

        $alert_msg = "Transaction Complete! Request <strong>{$req['tracking_number']}</strong> is now marked as <strong>Completed</strong>.{$mail_info}";
        $req['status'] = 'Completed';
        $req['current_stage'] = 4;

    } elseif ($action === 'revise') {
        $update_sql = "UPDATE requests SET status = 'Returned for Revision', remarks = ? WHERE id = ?";
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute([$remarks ?: 'Please re-upload a clear copy of required documents.', $req_id]);

        $office_name = ($req['requester_role'] === 'teacher') ? 'Administrator Office' : 'Office of the Registrar';

        $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message, location) VALUES (?, ?, ?, ?, ?)");
        $stmt_notif->execute([$req['user_id'], $req['tracking_number'], "Request {$req['tracking_number']} Needs Revision", "Your request needs revision: " . ($remarks ?: 'Please verify documents.'), $office_name]);

        // Send Real-Time Email Notification
        $mail_info = '';
        if (!empty($req['requester_email'])) {
            $m_res = send_request_status_email(
                $req['requester_email'],
                $req['full_name'],
                $req['tracking_number'],
                $req['request_type_name'],
                'Returned for Revision',
                $remarks ?: 'Please re-upload a clear copy of required documents via My Requests portal.',
                $office_name
            );
            if ($m_res['success']) {
                $mail_info = " &bull; Real-time alert email sent to <strong>{$req['requester_email']}</strong>";
            }
        }

        log_audit_action($pdo, $user_name, $actor_role, "Returned Request {$req['tracking_number']} for Revision");
        $alert_msg = "Request Returned for Revision. Requester has been notified via portal & email.{$mail_info}";
        $req['status'] = 'Returned for Revision';

    } elseif ($action === 'reject') {
        $update_sql = "UPDATE requests SET status = 'Rejected', remarks = ? WHERE id = ?";
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute([$remarks ?: 'Request rejected due to incomplete requirements.', $req_id]);

        $office_name = ($req['requester_role'] === 'teacher') ? 'Administrator Office' : 'Office of the Registrar';

        $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, request_id, title, message, location) VALUES (?, ?, ?, ?, ?)");
        $stmt_notif->execute([$req['user_id'], $req['tracking_number'], "Request {$req['tracking_number']} Rejected", "Your request was rejected: " . ($remarks ?: 'Requirements incomplete.'), $office_name]);

        // Send Real-Time Email Notification
        $mail_info = '';
        if (!empty($req['requester_email'])) {
            $m_res = send_request_status_email(
                $req['requester_email'],
                $req['full_name'],
                $req['tracking_number'],
                $req['request_type_name'],
                'Rejected',
                $remarks ?: 'Request rejected due to incomplete requirements.',
                $office_name
            );
            if ($m_res['success']) {
                $mail_info = " &bull; Notice email sent to <strong>{$req['requester_email']}</strong>";
            }
        }

        log_audit_action($pdo, $user_name, $actor_role, "Rejected Request {$req['tracking_number']}");
        $alert_msg = "Request has been rejected. Requester has been notified via email.{$mail_info}";
        $req['status'] = 'Rejected';
    }
}

$is_approved = ($req['status'] === 'Ready for Release' || $req['status'] === 'Released' || $req['status'] === 'Completed');
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
      <div class="details-item-label">Number of Copies</div>
      <div class="details-item-val">
        <span style="font-weight: 700; color: #047857; background: #ecfdf5; padding: 2px 8px; border-radius: 4px; border: 1px solid #a7f3d0; font-size: 0.88rem;">
          📄 <?= (int)($req['copies'] ?? 1) ?> <?= ((int)($req['copies'] ?? 1) > 1) ? 'Copies' : 'Copy' ?>
        </span>
      </div>
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
    <?php
    $attachments = parse_attachments($req['attached_filename'] ?? '', $req['file_size_kb'] ?? '');
    $has_real_attachment = !empty($attachments);
    ?>
    <div style="grid-column: span 2;">
      <div class="details-item-label">Attached Supporting Document(s)</div>
      <div class="details-item-val" style="display: flex; flex-direction: column; gap: 8px; margin-top: 4px;">
        <?php if ($has_real_attachment): ?>
          <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
            <?php foreach ($attachments as $idx => $att): ?>
              <a href="view_file.php?id=<?= $req['id'] ?>&file=<?= urlencode($att['file']) ?>" target="_blank" class="attachment-preview-btn" title="Click to view <?= htmlspecialchars($att['name']) ?> in new tab" style="display: inline-flex; align-items: center; gap: 6px;">
                📎 <?= htmlspecialchars($att['name']) ?> (<?= htmlspecialchars($att['size']) ?>) ↗
              </a>
            <?php endforeach; ?>
            <span style="background: #dcfce7; color: #15803d; font-size: 0.75rem; font-weight: 700; padding: 4px 10px; border-radius: 6px;">
              <?= count($attachments) ?> <?= count($attachments) > 1 ? 'Files' : 'File' ?> Verified
            </span>
          </div>
        <?php else: ?>
          <span style="color: #64748b; font-size: 0.9rem; font-weight: 500;">None (No attachment required)</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!empty($req['remarks'])): ?>
    <div style="grid-column: span 2;">
      <div class="details-item-label">Current Remarks / Feedback</div>
      <div class="details-item-val" style="color: #b45309; font-weight: 600;"><?= htmlspecialchars($req['remarks']) ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($req['additional_notes'])): ?>
    <div style="grid-column: span 2;">
      <div class="details-item-label">Additional / Revision Notes</div>
      <div class="details-item-val" style="white-space: pre-line;"><?= htmlspecialchars($req['additional_notes']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <?php 
  $has_cr_ratings = (
      $req['request_type_name'] === 'Certificate of Rating' || 
      strpos($req['additional_notes'] ?? '', '[3-Year Performance Ratings]') !== false
  );
  $has_f137_info = (
      ($req['request_type_code'] ?? '') === 'F137' || 
      strpos($req['additional_notes'] ?? '', '[Form 137 Student Records Info]') !== false
  );
  ?>

  <?php if ($has_f137_info): ?>
    <!-- Dedicated Form 137 Student Records Panel for Registrar -->
    <div style="background: #eff6ff; border: 2px solid #93c5fd; border-radius: 14px; padding: 20px 22px; margin-bottom: 22px; box-shadow: 0 4px 15px rgba(30, 64, 175, 0.05);">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; border-bottom: 1px solid #bfdbfe; padding-bottom: 12px; margin-bottom: 14px;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <span style="font-size: 1.6rem;">📁</span>
          <div>
            <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #1e40af;">Form 137 / SF10 Student Record Locator</h3>
            <p style="margin: 2px 0 0 0; font-size: 0.82rem; color: #2563eb;">
              Impormasyon para sa mabilis na paghahanap ng rekord sa archives ng Registrar:
            </p>
          </div>
        </div>
        <span style="background: #1e40af; color: #ffffff; font-size: 0.78rem; font-weight: 800; padding: 4px 12px; border-radius: 20px;">
          Student Archive Search
        </span>
      </div>
      <div style="white-space: pre-line; font-size: 0.92rem; color: #1e3a8a; line-height: 1.6; background: #ffffff; padding: 14px 18px; border-radius: 10px; border: 1px solid #bfdbfe;">
        <?= htmlspecialchars($req['additional_notes']) ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($has_cr_ratings): ?>
    <!-- Dedicated 3-Year Performance Ratings Verification Panel for Admin -->
    <div style="background: #f0fdf4; border: 2px solid #86efac; border-radius: 14px; padding: 20px 22px; margin-bottom: 22px; box-shadow: 0 4px 15px rgba(22, 101, 52, 0.06);">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; border-bottom: 1px solid #bbf7d0; padding-bottom: 12px; margin-bottom: 14px;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <span style="font-size: 1.6rem;">⭐</span>
          <div>
            <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: #166534;">3-Year Performance Ratings Verification (IPCRF Points)</h3>
            <p style="margin: 2px 0 0 0; font-size: 0.82rem; color: #15803d;">
              Ipinasang rating points ng guro para sa Certificate of Rating:
            </p>
          </div>
        </div>
        <span style="background: #166534; color: #ffffff; font-size: 0.78rem; font-weight: 800; padding: 4px 12px; border-radius: 20px;">
          Verification Required
        </span>
      </div>

      <!-- Ratings Breakdown Box -->
      <div style="background: #ffffff; border: 1.5px solid #bbf7d0; border-radius: 10px; padding: 14px 18px; margin-bottom: 14px; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; font-size: 0.92rem; color: #0f172a; white-space: pre-line; line-height: 1.7; border-left: 4px solid #16a34a;">
<?= htmlspecialchars($req['additional_notes']) ?>
      </div>

      <!-- Admin Action Instructions -->
      <div style="background: #ffffff; border: 1px solid #fed7aa; border-left: 4px solid #ea580c; border-radius: 8px; padding: 10px 14px; font-size: 0.83rem; color: #7c2d12; line-height: 1.5;">
        <strong>🔍 Administrator Action Guide:</strong><br>
        • <strong>Kung TAMA ang points:</strong> I-click ang <strong>"Mark Ready for Release"</strong>.<br>
        • <strong>Kung MALI ang points:</strong> I-click ang <strong>"Reject"</strong> ➔ piliin ang <strong>"Reject for Revision"</strong>. I-note na kailangan makipag-coordinate sa kanilang <em>Subject or Head Coordinator</em> para sa kanilang final rating bago mag-request muli.
      </div>
    </div>
  <?php endif; ?>

  <?php if ($has_real_attachment): ?>
    <!-- Smart Guard Verification Alert (Shown ONLY when supporting file(s) were actually uploaded) -->
    <div class="smart-guard-box" style="background: #ecfdf5; border-color: #a7f3d0; margin-bottom: 15px;">
      <div>
        <strong>Files Verified:</strong> The <?= strtolower($req['requester_role']) ?> uploaded <?= count($attachments) ?> supporting document(s) (<strong><?= htmlspecialchars(implode(', ', array_column($attachments, 'name'))) ?></strong>). All requirements are verified.
      </div>
    </div>
  <?php endif; ?>

  <!-- Fee Panel (Slide 16) -->
  <div class="requirements-panel" style="background: #f0fdf4; border-color: #bbf7d0; margin-bottom: 25px;">
    <div style="font-weight: 700; color: #166534;">Document Fee: FREE</div>
    <div style="font-size: 0.88rem; color: #166534; margin-top: 4px;">
      No Fee Required. This document is free of charge. The <?= strtolower($req['requester_role']) ?> can claim it directly at the Registrar's Office.
    </div>
  </div>

  <!-- Action Buttons for Registrar & Admin (Unified 3-Button Layout) -->
  <form method="POST" id="action-form" class="details-actions-row" style="flex-wrap: wrap; gap: 10px; align-items: center;">
    <input type="hidden" name="action" id="action-type" value="">
    <input type="hidden" name="remarks" id="action-remarks" value="">

    <?php if ($req['status'] === 'Completed' || $req['status'] === 'Released'): ?>
      <div style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: #ecfdf5; color: #047857; font-weight: 700; border-radius: 8px; border: 1px solid #a7f3d0; font-size: 0.95rem;">
        ✔ Transaction Completed &amp; Released
      </div>
    <?php elseif ($req['status'] === 'Rejected'): ?>
      <div style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: #fef2f2; color: #b91c1c; font-weight: 700; border-radius: 8px; border: 1px solid #fecaca; font-size: 0.95rem;">
        ✕ Request Closed (Rejected)
      </div>
    <?php else: ?>
      <?php if ($req['status'] === 'Ready for Release'): ?>
        <button type="button" class="btn-primary-cta" style="background: #059669; padding: 10px 20px; font-size: 0.92rem; font-weight: 700;" onclick="submitAction('complete')">✔ Mark as Completed</button>
      <?php else: ?>
        <button type="button" class="btn-action-approve" onclick="submitAction('approve')">Mark Ready for Release</button>
      <?php endif; ?>

      <button type="button" class="btn-action-reject" onclick="openRejectModal()">Reject</button>
    <?php endif; ?>

    <a href="<?= $back_url ?>" class="btn-action-back" style="text-decoration: none; display: inline-flex; align-items: center;">Back</a>
  </form>
</div>

<!-- Reject / Revision Action Modal (Unified for Students & Teachers) -->
<div id="reject-modal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 99999; align-items: center; justify-content: center; padding: 15px;" onclick="handleRejectOverlayClick(event)">
  <div class="modal-dialog" style="max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; background: #ffffff; padding: 26px 28px; border-radius: 18px; box-shadow: 0 20px 40px rgba(0,0,0,0.25);" onclick="event.stopPropagation()">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; border-bottom: 1px solid #f1f5f9; padding-bottom: 14px;">
      <div style="display: flex; align-items: center; gap: 12px;">
        <div style="width: 44px; height: 44px; border-radius: 12px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
          ⚠️
        </div>
        <div>
          <h3 style="margin: 0; font-size: 1.18rem; font-weight: 800; color: #0f172a;">Decline / Reject Request</h3>
          <p style="margin: 3px 0 0 0; font-size: 0.84rem; color: #64748b;">
            <strong><?= htmlspecialchars($req['tracking_number']) ?></strong> &bull; <?= htmlspecialchars($req['full_name']) ?> (<?= htmlspecialchars($req['request_type_name']) ?>)
          </p>
        </div>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeRejectModal()" style="position: static; font-size: 1.5rem; line-height: 1; padding: 0; cursor: pointer; color: #94a3b8; background: transparent; border: none;" aria-label="Close modal">&times;</button>
    </div>

    <!-- Option Selection Cards -->
    <div style="margin-bottom: 18px;">
      <label class="form-label" style="font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 8px; display: block;">
        Piliin ang Aksyon (Action Type) <span style="color: #ef4444;">*</span>
      </label>
      
      <div style="display: flex; flex-direction: column; gap: 10px;">
        <!-- Option 1: Reject for Revision -->
        <label id="card-option-revise" style="display: flex; align-items: flex-start; gap: 12px; padding: 13px 15px; border: 2px solid #ea580c; background: #fff7ed; border-radius: 12px; cursor: pointer; transition: all 0.2s ease;">
          <input type="radio" name="modal_action_choice" id="choice_revise" value="revise" checked onchange="switchRejectAction('revise')" style="margin-top: 3px; accent-color: #ea580c; width: 17px; height: 17px;">
          <div style="flex: 1;">
            <div style="display: flex; align-items: center; gap: 8px; font-weight: 800; color: #c2410c; font-size: 0.93rem;">
              <span>🔄 Reject for Revision (Return for Revision)</span>
              <span style="background: #fed7aa; color: #9a3412; font-size: 0.72rem; padding: 2px 7px; border-radius: 5px; font-weight: 800;">RECOMMENDED</span>
            </div>
            <p style="margin: 4px 0 0 0; font-size: 0.82rem; color: #9a3412; line-height: 1.45;">
              Ibabalik ang request sa <?= strtolower($req['requester_role']) ?> upang makapag-upload o makapag-ayos siya ng mga kailangang dokumento gamit ang <strong>"Edit"</strong> button sa kanyang dashboard.
            </p>
          </div>
        </label>

        <!-- Option 2: Permanent Rejection -->
        <label id="card-option-reject" style="display: flex; align-items: flex-start; gap: 12px; padding: 13px 15px; border: 1.5px solid #cbd5e1; background: #f8fafc; border-radius: 12px; cursor: pointer; transition: all 0.2s ease;">
          <input type="radio" name="modal_action_choice" id="choice_reject" value="reject" onchange="switchRejectAction('reject')" style="margin-top: 3px; accent-color: #dc2626; width: 17px; height: 17px;">
          <div style="flex: 1;">
            <div style="display: flex; align-items: center; gap: 8px; font-weight: 800; color: #334155; font-size: 0.93rem;">
              <span>❌ Permanent Rejection (Diretsong Reject)</span>
            </div>
            <p style="margin: 4px 0 0 0; font-size: 0.82rem; color: #64748b; line-height: 1.45;">
              Tuluyang tatanggihan at isasara ang request na ito (halimbawa: ineligible, pekeng dokumento, o hindi pinapayagang transaksyon). Hindi na ito mabubuksan pa muli.
            </p>
          </div>
        </label>
      </div>
    </div>

    <!-- Reason / Note Field -->
    <div style="margin-bottom: 18px;">
      <label class="form-label" style="font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 6px; display: block;">
        <span id="note-label-text">Revision Instructions / Note para sa Requester</span> <span style="color: #ef4444;">*</span>
      </label>
      <textarea id="modal_remarks_input" class="form-control" rows="3" placeholder="Ilagay ang tagubilin o kulang na dokumento para sa requester..." style="width: 100%; border-radius: 10px; font-size: 0.88rem; padding: 10px 12px; box-sizing: border-box; resize: vertical; border: 1.5px solid #cbd5e1; line-height: 1.45;"></textarea>

      <!-- Quick Note Suggestion Chips -->
      <div id="quick-presets-wrapper" style="margin-top: 8px;">
        <div style="font-size: 0.74rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Mabilisang Piliin (Quick Presets):</div>
        <div id="quick-chips-container" style="display: flex; flex-wrap: wrap; gap: 6px;"></div>
      </div>
    </div>

    <!-- Modal Footer Actions -->
    <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #f1f5f9; padding-top: 16px;">
      <button type="button" class="btn-action-back" onclick="closeRejectModal()" style="padding: 9px 18px; font-size: 0.88rem;">Cancel</button>
      <button type="button" id="modal-submit-action-btn" class="btn-action-revise" onclick="submitModalAction()" style="padding: 9px 22px; font-size: 0.88rem; font-weight: 700; background: #e67e22; color: #ffffff; border: none; border-radius: 10px; cursor: pointer;">
        🔄 Submit Return for Revision
      </button>
    </div>
  </div>
</div>

<script>
function openRejectModal() {
  const modal = document.getElementById('reject-modal');
  if (modal) {
    modal.classList.add('active');
    modal.style.display = 'flex';
    switchRejectAction('revise');
    setTimeout(() => {
      const input = document.getElementById('modal_remarks_input');
      if (input) input.focus();
    }, 100);
  }
}

function closeRejectModal() {
  const modal = document.getElementById('reject-modal');
  if (modal) {
    modal.classList.remove('active');
    modal.style.display = 'none';
  }
}

function handleRejectOverlayClick(e) {
  if (e.target && e.target.id === 'reject-modal') {
    closeRejectModal();
  }
}

const isCR = <?= $has_cr_ratings ? 'true' : 'false' ?>;
const isTeacher = <?= ($req['requester_role'] === 'teacher') ? 'true' : 'false' ?>;
const docCode = <?= json_encode($req['request_type_code'] ?? '') ?>;
const isGMC = (docCode === 'GMC' || <?= (stripos($req['request_type_name'], 'Good Moral') !== false) ? 'true' : 'false' ?>);

let revisionPresets = [];
let rejectPresets = [];

if (isTeacher) {
  // Admin (Teacher requests)
  // For Certificate of Rating, show only Rating Discrepancy preset for revision
  if (isCR) {
    revisionPresets = [
      "Rating Discrepancy: Mali ang ipinasang 3-year rating points. Mangyaring makipag-ugnayan sa inyong Subject o Head Coordinator para makuha ang inyong official final rating bago mag-request muli."
    ];
  } else {
    // COE, Service Record, and other faculty requests: no presets (admin types custom remarks)
    revisionPresets = [];
  }
  // Permanent rejection for Admin has NO presets (admin types custom remarks)
  rejectPresets = [];
} else {
  // Registrar (Student requests)
  if (isGMC) {
    // Good Moral Certificate has NO presets in both revision and rejection as instructed
    revisionPresets = [];
    rejectPresets = [];
  } else {
    // Other student requests (Form 137, Certificate of Grades, Certificate of Enrollment)
    revisionPresets = [
      "Blurry or unreadable document copy. Please re-upload a clear copy.",
      "Formal Request Letter addressed to the Registrar / Admin required.",
      "Please provide section, school year, and specific purpose in notes."
    ];
    rejectPresets = [
      "Incorrect document type requested. Please submit a new request.",
      "Duplicate request already being processed."
    ];
  }
}

function switchRejectAction(type) {
  const cardRevise = document.getElementById('card-option-revise');
  const cardReject = document.getElementById('card-option-reject');
  const radioRevise = document.getElementById('choice_revise');
  const radioReject = document.getElementById('choice_reject');
  const submitBtn = document.getElementById('modal-submit-action-btn');
  const noteLabel = document.getElementById('note-label-text');
  const remarksInput = document.getElementById('modal_remarks_input');

  if (type === 'revise') {
    radioRevise.checked = true;
    cardRevise.style.border = '2px solid #ea580c';
    cardRevise.style.background = '#fff7ed';
    cardReject.style.border = '1.5px solid #cbd5e1';
    cardReject.style.background = '#f8fafc';

    submitBtn.className = 'btn-action-revise';
    submitBtn.style.background = '#e67e22';
    submitBtn.innerHTML = '🔄 Submit Return for Revision';
    noteLabel.innerHTML = 'Revision Instructions / Note para sa Requester';
    remarksInput.placeholder = 'Ilagay ang tagubilin o kulang na detalye para sa requester...';

    renderChips(revisionPresets);
  } else {
    radioReject.checked = true;
    cardReject.style.border = '2px solid #dc2626';
    cardReject.style.background = '#fef2f2';
    cardRevise.style.border = '1.5px solid #cbd5e1';
    cardRevise.style.background = '#f8fafc';

    submitBtn.className = 'btn-action-reject';
    submitBtn.style.background = '#dc2626';
    submitBtn.innerHTML = '❌ Confirm Permanent Rejection';
    noteLabel.innerHTML = 'Official Reason for Rejection';
    remarksInput.placeholder = 'Ilagay ang opisyal na dahilan kung bakit tinanggihan ang request...';

    renderChips(rejectPresets);
  }
}

function renderChips(presets) {
  const container = document.getElementById('quick-chips-container');
  const wrapper = document.getElementById('quick-presets-wrapper');
  if (!container) return;
  container.innerHTML = '';

  if (!presets || presets.length === 0) {
    if (wrapper) wrapper.style.display = 'none';
    return;
  }

  if (wrapper) wrapper.style.display = 'block';
  presets.forEach(text => {
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.textContent = text;
    chip.style.cssText = 'background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 4px 10px; font-size: 0.76rem; color: #334155; cursor: pointer; text-align: left; transition: all 0.15s ease;';
    chip.onmouseover = () => { chip.style.background = '#e2e8f0'; chip.style.borderColor = '#94a3b8'; };
    chip.onmouseout = () => { chip.style.background = '#f1f5f9'; chip.style.borderColor = '#cbd5e1'; };
    chip.onclick = () => {
      const textarea = document.getElementById('modal_remarks_input');
      textarea.value = text;
      textarea.focus();
    };
    container.appendChild(chip);
  });
}

function submitModalAction() {
  const radioRevise = document.getElementById('choice_revise');
  const actionType = radioRevise.checked ? 'revise' : 'reject';
  const remarks = document.getElementById('modal_remarks_input').value.trim();

  if (!remarks) {
    alert('Please enter a note / reason for the requester (or click one of the quick presets).');
    document.getElementById('modal_remarks_input').focus();
    return;
  }

  const form = document.getElementById('action-form');
  document.getElementById('action-type').value = actionType;
  document.getElementById('action-remarks').value = remarks;

  closeRejectModal();
  form.submit();
}

function submitAction(type) {
  if (type === 'reject') {
    openRejectModal();
    return;
  }
  const form = document.getElementById('action-form');
  document.getElementById('action-type').value = type;
  form.submit();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
