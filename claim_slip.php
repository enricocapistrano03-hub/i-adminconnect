<?php
// ==============================================================================
// i-AdminConnect Official Document Claim Slip (Claim Stub / Release Voucher)
// Taguig Science High School - Office of the School Registrar
// ==============================================================================
require_once __DIR__ . '/config/db_conn.php';

// Auth Check: Requires logged-in session
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$ref = trim($_GET['ref'] ?? '');
if (empty($ref)) {
    die("<div style='font-family: sans-serif; padding: 30px; text-align: center; color: #dc2626;'><h3>Error: Invalid Request Reference</h3><p><a href='dashboard.php'>&laquo; Return to Dashboard</a></p></div>");
}

$stmt = $pdo->prepare("SELECT r.*, u.full_name, u.id_number, u.email as requester_email, u.role as requester_role, u.section, u.year_level, rt.name as request_type_name, rt.code as request_type_code 
    FROM requests r
    JOIN users u ON r.user_id = u.id
    JOIN request_types rt ON r.request_type_id = rt.id
    WHERE r.tracking_number = ? LIMIT 1");
$stmt->execute([$ref]);
$req = $stmt->fetch();

if (!$req) {
    die("<div style='font-family: sans-serif; padding: 30px; text-align: center; color: #dc2626;'><h3>Error: Request Record Not Found</h3><p><a href='dashboard.php'>&laquo; Return to Dashboard</a></p></div>");
}

$curr_user_id = (int)$_SESSION['user_id'];
$curr_user_role = $_SESSION['role'] ?? 'student';

// Security check: Only the owner student, Registrar, or Admin can view
if ($curr_user_role !== 'admin' && $curr_user_role !== 'registrar' && $curr_user_id !== (int)$req['user_id']) {
    die("<div style='font-family: sans-serif; padding: 30px; text-align: center; color: #dc2626;'><h3>Access Denied</h3><p>You are not authorized to view this document claim slip.</p><p><a href='dashboard.php'>&laquo; Return to Dashboard</a></p></div>");
}

// Generate unique digital verification security hash
$security_hash = strtoupper(substr(md5($req['tracking_number'] . $req['created_at'] . 'TAGSCI_SECRET'), 0, 10));
$claim_code = "TSHS-CLAIM-" . preg_replace('/[^0-9]/', '', $req['tracking_number']) . "-{$security_hash}";

$pickup_formatted = !empty($req['pickup_date']) ? date('F j, Y', strtotime($req['pickup_date'])) : 'Pending Scheduling by Registrar';
$pickup_day = !empty($req['pickup_date']) ? date('l', strtotime($req['pickup_date'])) : '';
$is_ready = ($req['status'] === 'Ready for Release');
$is_completed = ($req['status'] === 'Completed' || $req['status'] === 'Released');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Claim Slip - <?= htmlspecialchars($req['tracking_number']) ?> - Taguig Science High School</title>
  <link rel="icon" type="image/png" href="assets/logo.png">
  <style>
    :root {
      --primary: #15803d;
      --primary-dark: #166534;
      --primary-light: #dcfce7;
      --indigo: #4338ca;
      --slate-800: #1e293b;
      --slate-600: #475569;
      --slate-100: #f1f5f9;
    }
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: #f8fafc;
      color: #0f172a;
      line-height: 1.5;
      padding: 24px 16px;
    }
    .slip-container {
      max-width: 680px;
      margin: 0 auto;
      background: #ffffff;
      border: 2px solid #cbd5e1;
      border-radius: 18px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.08);
      overflow: hidden;
      position: relative;
    }
    /* Action Bar (Screen Only) */
    .screen-actions {
      max-width: 680px;
      margin: 0 auto 16px auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
    }
    .btn-action {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 9px 18px;
      border-radius: 9px;
      font-size: 0.88rem;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
      border: none;
      transition: all 0.2s ease;
    }
    .btn-print {
      background: #16a34a;
      color: #ffffff;
      box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);
    }
    .btn-print:hover {
      background: #15803d;
      transform: translateY(-1px);
    }
    .btn-back {
      background: #ffffff;
      color: #475569;
      border: 1.5px solid #cbd5e1;
    }
    .btn-back:hover {
      background: #f1f5f9;
    }
    /* Official DepEd Header */
    .slip-header {
      background: linear-gradient(135deg, #14532d 0%, #166534 100%);
      color: #ffffff;
      padding: 24px 28px 20px 28px;
      text-align: center;
      position: relative;
      border-bottom: 4px solid #f59e0b;
    }
    .header-logos {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 16px;
      margin-bottom: 8px;
    }
    .header-logos img {
      width: 54px;
      height: 54px;
      object-fit: contain;
      filter: drop-shadow(0 2px 4px rgba(0,0,0,0.25));
    }
    .slip-header h4 {
      font-size: 0.76rem;
      font-weight: 600;
      letter-spacing: 1px;
      text-transform: uppercase;
      opacity: 0.9;
    }
    .slip-header h2 {
      font-size: 1.25rem;
      font-weight: 900;
      letter-spacing: 0.5px;
      margin: 2px 0;
      text-transform: uppercase;
    }
    .slip-header h3 {
      font-size: 0.88rem;
      font-weight: 700;
      color: #fde68a;
      letter-spacing: 0.5px;
    }
    /* Title Ribbon */
    .slip-title-ribbon {
      background: #ecfdf5;
      border-bottom: 1px solid #bbf7d0;
      padding: 10px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
    }
    .slip-title-ribbon h1 {
      font-size: 1.05rem;
      font-weight: 900;
      color: #14532d;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .status-badge {
      font-size: 0.76rem;
      font-weight: 800;
      padding: 4px 12px;
      border-radius: 20px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .status-ready {
      background: #dcfce7;
      color: #15803d;
      border: 1.5px solid #86efac;
    }
    .status-completed {
      background: #e0e7ff;
      color: #4338ca;
      border: 1.5px solid #c7d2fe;
    }
    .status-processing {
      background: #fef3c7;
      color: #92400e;
      border: 1.5px solid #fde68a;
    }
    /* Barcode Box */
    .barcode-section {
      padding: 18px 24px 14px 24px;
      background: #ffffff;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px dashed #cbd5e1;
      flex-wrap: wrap;
      gap: 14px;
    }
    .barcode-val {
      font-size: 1.8rem;
      font-weight: 900;
      font-family: "Courier New", Courier, monospace;
      color: #0f172a;
      letter-spacing: 3px;
      line-height: 1;
    }
    .barcode-lines {
      display: inline-block;
      height: 38px;
      width: 170px;
      background: repeating-linear-gradient(
        90deg,
        #0f172a 0px,
        #0f172a 2px,
        transparent 2px,
        transparent 4px,
        #0f172a 4px,
        #0f172a 7px,
        transparent 7px,
        transparent 9px,
        #0f172a 9px,
        #0f172a 10px,
        transparent 10px,
        transparent 13px
      );
      margin-top: 5px;
    }
    /* Scheduled Pickup Date Highlight Box */
    .pickup-highlight-card {
      margin: 18px 24px;
      background: linear-gradient(135deg, #eff6ff 0%, #e0e7ff 100%);
      border: 2px solid #3b82f6;
      border-radius: 14px;
      padding: 16px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 14px;
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
    }
    .pickup-date-display {
      font-size: 1.35rem;
      font-weight: 900;
      color: #1e3a8a;
      line-height: 1.2;
    }
    .pickup-window-info {
      font-size: 0.82rem;
      color: #1e40af;
      margin-top: 4px;
      font-weight: 600;
    }
    /* Details Grid */
    .details-table-wrap {
      padding: 0 24px 16px 24px;
    }
    .details-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.88rem;
    }
    .details-table td {
      padding: 9px 12px;
      border: 1px solid #e2e8f0;
      vertical-align: top;
    }
    .details-table .label-col {
      background: #f8fafc;
      font-weight: 700;
      color: #475569;
      width: 32%;
    }
    .details-table .val-col {
      color: #0f172a;
      font-weight: 600;
    }
    /* Instructions */
    .instructions-box {
      margin: 0 24px 20px 24px;
      background: #fefce8;
      border: 1.5px solid #fef08a;
      border-radius: 12px;
      padding: 14px 18px;
      font-size: 0.82rem;
      color: #854d0e;
      line-height: 1.55;
    }
    .instructions-box ol {
      margin: 6px 0 0 18px;
      padding: 0;
    }
    .instructions-box li {
      margin-bottom: 3px;
    }
    /* Footer Stamp */
    .slip-footer {
      background: #f8fafc;
      border-top: 1px solid #e2e8f0;
      padding: 14px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
      font-size: 0.74rem;
      color: #64748b;
    }
    .security-hash-text {
      font-family: monospace;
      font-weight: 700;
      color: #0f172a;
    }
    /* Print Optimization */
    @media print {
      body {
        background: #ffffff;
        padding: 0;
      }
      .screen-actions {
        display: none !important;
      }
      .slip-container {
        border: 2px solid #000000;
        box-shadow: none;
        max-width: 100%;
        margin: 0;
        page-break-inside: avoid;
      }
      .slip-header {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
      .pickup-highlight-card {
        border: 2px solid #000000;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
      .barcode-lines {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
    }
  </style>
</head>
<body>

  <!-- Screen Action Bar -->
  <div class="screen-actions">
    <div style="display: flex; align-items: center; gap: 8px;">
      <a href="<?= ($curr_user_role === 'student' ? 'my_requests.php' : 'request_details.php?id=' . $req['id']) ?>" class="btn-action btn-back">
        &laquo; <?= ($curr_user_role === 'student' ? 'My Requests' : 'Back to Details') ?>
      </a>
      <a href="dashboard.php" class="btn-action btn-back">Dashboard</a>
    </div>
    <div style="display: flex; align-items: center; gap: 8px;">
      <button type="button" class="btn-action btn-print" onclick="window.print()">
        🖨️ Print Claim Slip
      </button>
    </div>
  </div>

  <div class="slip-container">
    <!-- Official Institutional Header -->
    <div class="slip-header">
      <div class="header-logos">
        <img src="assets/logo.png" alt="TagSci Logo">
        <div>
          <h4>Republic of the Philippines &bull; Department of Education</h4>
          <h4>National Capital Region &bull; Division of Taguig City and Pateros</h4>
          <h2>Taguig Science High School</h2>
          <h3>Office of the School Registrar &bull; Student Records Unit</h3>
        </div>
      </div>
    </div>

    <!-- Title Ribbon -->
    <div class="slip-title-ribbon">
      <h1><span>🎫</span> Official Document Claim Slip</h1>
      <?php if ($is_completed): ?>
        <span class="status-badge status-completed">✔ Claimed / Completed</span>
      <?php elseif ($is_ready): ?>
        <span class="status-badge status-ready">✔ Ready for Release</span>
      <?php else: ?>
        <span class="status-badge status-processing">⏳ In Process</span>
      <?php endif; ?>
    </div>

    <!-- Barcode & Tracking Number -->
    <div class="barcode-section">
      <div>
        <div style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800; color: #64748b; letter-spacing: 0.5px;">Tracking Reference Number:</div>
        <div class="barcode-val"><?= htmlspecialchars($req['tracking_number']) ?></div>
        <div class="barcode-lines"></div>
      </div>
      <div style="text-align: right;">
        <div style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; color: #64748b;">Verification Security Hash:</div>
        <div style="font-family: monospace; font-size: 0.88rem; font-weight: 800; color: #15803d; background: #dcfce7; padding: 3px 8px; border-radius: 6px; display: inline-block; margin-top: 2px;">
          <?= $security_hash ?>
        </div>
        <div style="font-size: 0.7rem; color: #64748b; margin-top: 4px;">System ID: #<?= (int)$req['id'] ?></div>
      </div>
    </div>

    <!-- Scheduled Pickup Date Highlight Box -->
    <div class="pickup-highlight-card">
      <div style="display: flex; align-items: center; gap: 14px;">
        <div style="width: 54px; height: 54px; border-radius: 12px; background: #ffffff; border: 2px solid #3b82f6; display: flex; flex-direction: column; align-items: center; justify-content: center; font-weight: 900; line-height: 1.1; color: #1e3a8a;">
          <span style="font-size: 0.72rem; text-transform: uppercase;"><?= !empty($req['pickup_date']) ? date('M', strtotime($req['pickup_date'])) : 'DATE' ?></span>
          <span style="font-size: 1.35rem;"><?= !empty($req['pickup_date']) ? date('j', strtotime($req['pickup_date'])) : '—' ?></span>
        </div>
        <div>
          <div style="font-size: 0.76rem; text-transform: uppercase; font-weight: 800; color: #2563eb; letter-spacing: 0.5px;">Scheduled Pickup Date (Petsa ng Pagkuha):</div>
          <div class="pickup-date-display">
            <?= $pickup_formatted ?> <?= !empty($pickup_day) ? "<span style='font-size: 0.95rem; font-weight: 600; color: #3b82f6;'>({$pickup_day})</span>" : "" ?>
          </div>
          <div class="pickup-window-info">
            📍 Location: <strong>Office of the Registrar (Window 1 / Window 2)</strong> &bull; Campus Hours (7:00 AM &ndash; 3:30 PM)
          </div>
        </div>
      </div>
      <div style="text-align: right;">
        <span style="background: #ffffff; border: 1.5px solid #3b82f6; color: #1d4ed8; font-weight: 800; font-size: 0.76rem; padding: 4px 10px; border-radius: 6px; display: inline-block;">
          Present this at Window
        </span>
      </div>
    </div>

    <!-- Student & Request Details Table -->
    <div class="details-table-wrap">
      <table class="details-table">
        <tbody>
          <tr>
            <td class="label-col">Student Full Name:</td>
            <td class="val-col" style="font-size: 0.96rem; color: #047857;"><strong><?= htmlspecialchars($req['full_name']) ?></strong></td>
          </tr>
          <tr>
            <td class="label-col">Learner Reference No. (LRN):</td>
            <td class="val-col" style="font-family: monospace; font-size: 0.94rem;"><strong><?= htmlspecialchars($req['id_number'] ?: '—') ?></strong></td>
          </tr>
          <tr>
            <td class="label-col">Grade &amp; Section / Strand:</td>
            <td class="val-col"><?= htmlspecialchars(($req['year_level'] ? $req['year_level'] . ' - ' : '') . ($req['section'] ?: 'Senior High School')) ?></td>
          </tr>
          <tr>
            <td class="label-col">Document Requested:</td>
            <td class="val-col">
              <strong style="color: #1e3a8a;"><?= htmlspecialchars($req['request_type_name']) ?></strong>
              <span style="background: #f1f5f9; padding: 2px 7px; border-radius: 4px; font-size: 0.75rem; color: #475569; margin-left: 6px;">
                <?= (int)($req['copies'] ?? 1) ?> <?= (int)($req['copies'] ?? 1) > 1 ? 'copies' : 'copy' ?>
              </span>
            </td>
          </tr>
          <tr>
            <td class="label-col">Purpose / Destination:</td>
            <td class="val-col"><?= htmlspecialchars($req['purpose']) ?></td>
          </tr>
          <tr>
            <td class="label-col">Date Requested:</td>
            <td class="val-col"><?= date('F j, Y g:i A', strtotime($req['created_at'])) ?></td>
          </tr>
          <?php if (!empty($req['remarks'])): ?>
          <tr>
            <td class="label-col">Registrar Remarks:</td>
            <td class="val-col" style="color: #b45309;"><?= htmlspecialchars($req['remarks']) ?></td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Instructions for Student -->
    <div class="instructions-box">
      <strong>⚠️ Mga Tagubilin sa Pag-claim ng Dokumento (Claiming Guidelines):</strong>
      <ol>
        <li>Ipakita ang <strong>Official Claim Slip</strong> na ito (maaaring ipakita sa screen ng inyong smartphone o magdala ng printed copy) sa **Office of the Registrar**.</li>
        <li>Magpakita ng <strong>Valid School ID</strong> (o Government-Issued ID para sa mga Alumni) bilang patunay ng pagkakakilanlan.</li>
        <li>Kung kinatawan (Representative / Magulang) ang kukuha sa inyong ngalan: Magdala ng <em>Authorization Letter</em> na may lagda ng estudyante kalakip ang photocopy ng ID ng estudyante at ID ng kinatawan.</li>
      </ol>
    </div>

    <!-- Footer Security Stamp -->
    <div class="slip-footer">
      <div>
        Generated by <strong>i-AdminConnect Portal</strong> &bull; <?= date('F j, Y g:i A') ?><br>
        <span class="security-hash-text">Code: <?= htmlspecialchars($claim_code) ?></span>
      </div>
      <div style="text-align: right;">
        <span style="color: #15803d; font-weight: 800;">Taguig Science High School</span><br>
        Official Registrar Document Release Slip
      </div>
    </div>
  </div>

</body>
</html>
