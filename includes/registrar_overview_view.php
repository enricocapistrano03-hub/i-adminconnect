<?php
// ==============================================================================
// Registrar Operational Overview Dashboard
// Displays live request metrics, pending processing queue, and rapid review tools
// ==============================================================================

// Fetch dynamic counts from database (Student requests only for Registrar)
$stmt = $pdo->query("SELECT 
    COUNT(r.id) as total_count,
    SUM(CASE WHEN r.status IN ('Pending', 'Under Review') THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN r.status = 'Processing' THEN 1 ELSE 0 END) as processing_count,
    SUM(CASE WHEN r.status = 'Ready for Release' THEN 1 ELSE 0 END) as ready_count,
    SUM(CASE WHEN r.status = 'Released' THEN 1 ELSE 0 END) as released_count
    FROM requests r
    JOIN users u ON r.user_id = u.id
    WHERE u.role = 'student'");
$counts = $stmt->fetch();

$total_assigned = (int)($counts['total_count'] ?? 0);
$total_pending = (int)($counts['pending_count'] ?? 0);
$total_processing = (int)($counts['processing_count'] ?? 0);
$total_ready = (int)($counts['ready_count'] ?? 0);
$total_released = (int)($counts['released_count'] ?? 0);

// Fetch recent 6 student requests
$stmt = $pdo->query("SELECT r.*, u.full_name as student_or_teacher_name, u.role as requester_role, rt.name as request_type_name
    FROM requests r
    JOIN users u ON r.user_id = u.id
    JOIN request_types rt ON r.request_type_id = rt.id
    WHERE u.role = 'student'
    ORDER BY r.id DESC LIMIT 6");
$recent_queue = $stmt->fetchAll();
?>

<div class="admin-dashboard-container">

  <!-- Operational Greeting Card -->
  <div style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: white; border-radius: 16px; padding: 22px 28px; margin-bottom: 24px; box-shadow: 0 10px 25px -5px rgba(2, 132, 199, 0.25); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
    <div>
      <div style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #bae6fd; margin-bottom: 4px;">
        🏛️ Office of the Registrar
      </div>
      <h2 style="font-size: 1.45rem; font-weight: 800; margin: 0; color: #ffffff;">
        Document Processing & Records Hub
      </h2>
      <p style="font-size: 0.88rem; color: #e0f2fe; margin-top: 4px; margin-bottom: 0;">
        Manage, verify, and release student credentials and official academic certifications in real-time.
      </p>
    </div>
    <div>
      <a href="assigned_requests.php" class="btn-primary-cta" style="background: #ffffff; color: #0284c7; font-weight: 700; padding: 10px 20px; font-size: 0.9rem; text-decoration: none; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
        📂 View All Assigned Requests &rarr;
      </a>
    </div>
  </div>

  <!-- 4 Metric KPI Cards -->
  <div class="admin-kpi-grid">
    <!-- Card 1: TOTAL ASSIGNED -->
    <div class="admin-kpi-card" style="border-left: 4px solid #0284c7;">
      <div class="admin-kpi-top">
        <div>
          <div class="admin-kpi-label">TOTAL ASSIGNED</div>
          <div class="admin-kpi-value"><?= $total_assigned ?></div>
        </div>
        <div class="admin-kpi-icon-box" style="background: #e0f2fe;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <polyline points="14 2 14 8 20 8"></polyline>
            <line x1="16" y1="13" x2="8" y2="13"></line>
            <line x1="16" y1="17" x2="8" y2="17"></line>
            <polyline points="10 9 9 9 8 9"></polyline>
          </svg>
        </div>
      </div>
      <div class="admin-kpi-status" style="color: #0284c7;">Overall lifetime submitted</div>
    </div>

    <!-- Card 2: PENDING REVIEW -->
    <div class="admin-kpi-card" style="border-left: 4px solid #f59e0b;">
      <div class="admin-kpi-top">
        <div>
          <div class="admin-kpi-label">PENDING REVIEW</div>
          <div class="admin-kpi-value"><?= $total_pending ?></div>
        </div>
        <div class="admin-kpi-icon-box" style="background: #fef3c7;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <polyline points="12 6 12 12 16 14"></polyline>
          </svg>
        </div>
      </div>
      <div class="admin-kpi-status" style="color: #b45309;">Requires verification</div>
    </div>

    <!-- Card 3: CURRENTLY PROCESSING -->
    <div class="admin-kpi-card" style="border-left: 4px solid #00bfa5;">
      <div class="admin-kpi-top">
        <div>
          <div class="admin-kpi-label">IN PROCESSING</div>
          <div class="admin-kpi-value"><?= $total_processing ?></div>
        </div>
        <div class="admin-kpi-icon-box" style="background: #ccfbf1;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0d9488" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
          </svg>
        </div>
      </div>
      <div class="admin-kpi-status" style="color: #0d9488;">Under preparation</div>
    </div>

    <!-- Card 4: READY FOR RELEASE -->
    <div class="admin-kpi-card" style="border-left: 4px solid #10b981;">
      <div class="admin-kpi-top">
        <div>
          <div class="admin-kpi-label">READY FOR RELEASE</div>
          <div class="admin-kpi-value"><?= $total_ready ?></div>
        </div>
        <div class="admin-kpi-icon-box" style="background: #dcfce7;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
            <polyline points="22 4 12 14.01 9 11.01"></polyline>
          </svg>
        </div>
      </div>
      <div class="admin-kpi-status" style="color: #15803d;">Awaiting student pickup</div>
    </div>
  </div>

  <!-- Operational Queue Table -->
  <div class="admin-card-panel" style="margin-top: 24px;">
    <div class="admin-card-header">
      <div>
        <h2 class="admin-card-title">INCOMING & ACTIVE REQUEST QUEUE</h2>
        <p style="font-size: 0.85rem; color: #64748b; margin-top: 3px; margin-bottom: 0;">
          Student requests queued for review, stage updates, and document release.
        </p>
      </div>
      <a href="assigned_requests.php" class="btn-view-all-requests">View All &rarr;</a>
    </div>

    <div class="activity-table-wrapper">
      <table class="activity-table">
        <thead>
          <tr>
            <th>Tracking ID</th>
            <th>Requester Name</th>
            <th>User Type</th>
            <th>Requested Document</th>
            <th>Date Submitted</th>
            <th>Current Status</th>
            <th style="text-align: right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recent_queue)): ?>
            <tr>
              <td colspan="7" style="text-align: center; color: #94a3b8; padding: 45px 20px;">
                <div style="font-size: 2.2rem; margin-bottom: 8px;">📭</div>
                <div style="font-weight: 700; color: #475569; font-size: 0.95rem;">No document requests currently pending</div>
                <div style="font-size: 0.82rem; color: #94a3b8; margin-top: 4px;">Incoming student and teacher requests will automatically appear here in real-time.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($recent_queue as $req): 
              $status_class = strtolower(str_replace(' ', '-', $req['status']));
              $date_str = date('M j, Y g:i A', strtotime($req['created_at']));
            ?>
              <tr>
                <td class="activity-req-id"><strong><?= htmlspecialchars($req['tracking_number']) ?></strong></td>
                <td style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($req['student_or_teacher_name']) ?></td>
                <td><span style="font-size: 0.8rem; font-weight: 600; color: #64748b;"><?= ucfirst($req['requester_role']) ?></span></td>
                <td style="color: #334155;"><?= htmlspecialchars($req['request_type_name']) ?></td>
                <td style="color: #64748b; font-size: 0.82rem;"><?= $date_str ?></td>
                <td><span class="badge-status <?= $status_class ?>"><?= htmlspecialchars($req['status']) ?></span></td>
                <td style="text-align: right;">
                  <a href="request_details.php?id=<?= $req['id'] ?>" class="btn-primary-cta" style="padding: 5px 14px; font-size: 0.82rem; text-decoration: none;">
                    Review & Process
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
