<?php
// ==============================================================================
// Admin Executive Overview Dashboard (100% Real-Time & Dynamic from Database)
// ==============================================================================

// Fetch dynamic counts from database (Teacher requests only for Administrator)
$stmt = $pdo->query("SELECT 
    COUNT(r.id) as total_count,
    SUM(CASE WHEN r.status IN ('Pending', 'Under Review') THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN r.status = 'Processing' THEN 1 ELSE 0 END) as processing_count,
    SUM(CASE WHEN r.status = 'Ready for Release' THEN 1 ELSE 0 END) as ready_count
    FROM requests r
    JOIN users u ON r.user_id = u.id
    WHERE u.role = 'teacher'");
$counts = $stmt->fetch();

$total_pending = (int)($counts['pending_count'] ?? 0);
$total_processing = (int)($counts['processing_count'] ?? 0);
$total_ready = (int)($counts['ready_count'] ?? 0);
$total_month = (int)($counts['total_count'] ?? 0);

// Fetch Top 5 Most Requested Documents for Teachers dynamically
$top_docs_stmt = $pdo->query("SELECT rt.id, rt.name, COUNT(r.id) as count
    FROM request_types rt
    LEFT JOIN requests r ON r.request_type_id = rt.id AND r.user_id IN (SELECT id FROM users WHERE role = 'teacher')
    WHERE rt.target_role IN ('teacher', 'both')
    GROUP BY rt.id
    ORDER BY count DESC, rt.id ASC
    LIMIT 5");
$top_docs = $top_docs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate dynamic scale for the bar chart
$max_val = 0;
foreach ($top_docs as $d) {
    if ((int)$d['count'] > $max_val) {
        $max_val = (int)$d['count'];
    }
}

// Chart axis ceiling (minimum 10 for clean visual scale when zero or low)
if ($max_val <= 10) {
    $y_scale_max = 10;
} elseif ($max_val <= 50) {
    $y_scale_max = ceil($max_val / 10) * 10;
} else {
    $y_scale_max = ceil($max_val / 30) * 30;
}
$step = $y_scale_max / 5;

// Fetch Recent 5 Activities for Teachers
$stmt = $pdo->query("SELECT r.*, u.full_name as student_or_teacher_name, u.role as requester_role, rt.name as request_type_name
    FROM requests r
    JOIN users u ON r.user_id = u.id
    JOIN request_types rt ON r.request_type_id = rt.id
    WHERE u.role = 'teacher'
    ORDER BY r.id DESC LIMIT 5");
$recent_activity = $stmt->fetchAll();
?>

<div class="admin-dashboard-container">

  <!-- 1. Top Row: 4 Metric Cards -->
  <div class="admin-kpi-grid">
    <!-- Card 1: TOTAL PENDING -->
    <div class="admin-kpi-card">
      <div class="kpi-header">
        <div class="kpi-icon-box red">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <polyline points="12 6 12 12 16 14"></polyline>
          </svg>
        </div>
        <div class="kpi-label">TOTAL PENDING</div>
      </div>
      <div class="kpi-number"><?= $total_pending ?></div>
      <span class="kpi-pill red"><?= $total_pending > 0 ? 'Requires Action' : 'No Backlog' ?></span>
    </div>

    <!-- Card 2: PROCESSING -->
    <div class="admin-kpi-card">
      <div class="kpi-header">
        <div class="kpi-icon-box purple">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"></circle>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
          </svg>
        </div>
        <div class="kpi-label">PROCESSING</div>
      </div>
      <div class="kpi-number"><?= $total_processing ?></div>
      <span class="kpi-pill yellow"><?= $total_processing > 0 ? 'In Progress' : 'Idle Queue' ?></span>
    </div>

    <!-- Card 3: READY FOR RELEASE -->
    <div class="admin-kpi-card">
      <div class="kpi-header">
        <div class="kpi-icon-box green">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path>
          </svg>
        </div>
        <div class="kpi-label">READY FOR RELEASE</div>
      </div>
      <div class="kpi-number"><?= $total_ready ?></div>
      <span class="kpi-pill green">Approved &amp; Ready</span>
    </div>

    <!-- Card 4: TOTAL REQUESTS (MONTH) -->
    <div class="admin-kpi-card">
      <div class="kpi-header">
        <div class="kpi-icon-box blue">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <polyline points="14 2 14 8 20 8"></polyline>
            <line x1="16" y1="13" x2="8" y2="13"></line>
            <line x1="16" y1="17" x2="8" y2="17"></line>
            <polyline points="10 9 9 9 8 9"></polyline>
          </svg>
        </div>
        <div class="kpi-label">TOTAL REQUESTS (MONTH)</div>
      </div>
      <div class="kpi-number"><?= $total_month ?></div>
      <span class="kpi-pill blue">processed</span>
    </div>
  </div>

  <!-- 2. Bottom Row: 2 Large Panels (Chart & Recent Activity) -->
  <div class="admin-main-grid">

    <!-- Left Panel: Top 5 Requested Documents (Bar Chart) -->
    <div class="admin-card-panel">
      <div class="admin-card-header">
        <h2 class="admin-card-title">MOST REQUESTED DOCUMENTS (TOP 5)</h2>
        <?php if ($total_month > 0): ?>
          <span style="font-size: 0.78rem; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 4px 10px; border-radius: 12px;">
            <?= $total_month ?> Total Recorded
          </span>
        <?php else: ?>
          <span style="font-size: 0.78rem; font-weight: 700; color: #64748b; background: #f1f5f9; padding: 4px 10px; border-radius: 12px;">
            Awaiting Submissions
          </span>
        <?php endif; ?>
      </div>

      <div class="chart-wrapper">
        <svg viewBox="0 0 520 270" width="100%" height="100%" preserveAspectRatio="xMidYMid meet" style="font-family: inherit;">
          <!-- Grid Lines and Dynamic Y-Axis Scale Values -->
          <?php 
            $chart_baseline_y = 215;
            $chart_top_y = 25;
            $chart_height_range = $chart_baseline_y - $chart_top_y; // 190px
          ?>

          <?php for ($i = 5; $i >= 0; $i--): 
            $y_val = round($step * $i);
            $y_pos = $chart_baseline_y - (($i / 5) * $chart_height_range);
            $is_base = ($i === 0);
          ?>
            <line x1="38" y1="<?= $y_pos ?>" x2="505" y2="<?= $y_pos ?>" stroke="<?= $is_base ? '#cbd5e1' : '#e2e8f0' ?>" stroke-width="<?= $is_base ? '1.2' : '1' ?>" />
            <text x="30" y="<?= $y_pos + 4 ?>" font-size="11" fill="#64748b" font-weight="600" text-anchor="end"><?= $y_val ?></text>
          <?php endfor; ?>

          <!-- 5 Dynamic Bars -->
          <?php 
            $slot_width = (505 - 38) / 5; // ~93.4px
            $bar_width = 46;
            
            foreach ($top_docs as $idx => $doc):
              $cnt = (int)$doc['count'];
              $slot_center = 38 + ($idx * $slot_width) + ($slot_width / 2);
              $bar_x = $slot_center - ($bar_width / 2);
              
              // Calculate proportional bar height
              $bar_h = ($y_scale_max > 0) ? round(($cnt / $y_scale_max) * $chart_height_range) : 0;
              if ($cnt > 0 && $bar_h < 4) $bar_h = 4; // minimum visibility
              
              $bar_y = $chart_baseline_y - $bar_h;
              
              // Friendly short label
              $short_name = $doc['name'];
              $words = explode(' ', $short_name);
              $line1 = $words[0] . (isset($words[1]) ? ' ' . $words[1] : '');
              $line2 = '';
              for ($w = 2; $w < count($words); $w++) {
                $line2 .= ($w > 2 ? ' ' : '') . $words[$w];
              }
              // Clean up parentheses
              $line2 = str_replace(['(', ')'], '', $line2);
          ?>
            <!-- Bar -->
            <?php if ($bar_h > 0): ?>
              <rect x="<?= $bar_x ?>" y="<?= $bar_y ?>" width="<?= $bar_width ?>" height="<?= $bar_h ?>" fill="#0284c7" rx="3" />
            <?php else: ?>
              <rect x="<?= $bar_x ?>" y="<?= $chart_baseline_y - 2 ?>" width="<?= $bar_width ?>" height="2" fill="#e2e8f0" rx="1" />
            <?php endif; ?>

            <!-- Value Label above bar -->
            <text x="<?= $slot_center ?>" y="<?= $bar_y - 8 ?>" font-size="12" font-weight="700" fill="<?= $cnt > 0 ? '#0f172a' : '#94a3b8' ?>" text-anchor="middle"><?= $cnt ?></text>

            <!-- Document Name Labels below axis -->
            <text x="<?= $slot_center ?>" y="232" font-size="10.5" font-weight="600" fill="#475569" text-anchor="middle">
              <tspan x="<?= $slot_center ?>" dy="0"><?= htmlspecialchars($line1) ?></tspan>
              <?php if (!empty($line2)): ?>
                <tspan x="<?= $slot_center ?>" dy="13"><?= htmlspecialchars($line2) ?></tspan>
              <?php endif; ?>
            </text>
          <?php endforeach; ?>
        </svg>
      </div>
    </div>

    <!-- Right Panel: Recent Activity -->
    <div class="admin-card-panel">
      <div class="admin-card-header">
        <h2 class="admin-card-title">RECENT TEACHER REQUESTS</h2>
        <a href="manage_requests.php" class="btn-view-all-requests">View All Teacher Requests</a>
      </div>

      <div class="activity-table-wrapper">
        <table class="activity-table">
          <thead>
            <tr>
              <th>Request ID</th>
              <th>Name</th>
              <th>User Type</th>
              <th>Request Type</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent_activity)): ?>
              <tr>
                <td colspan="5" style="text-align: center; color: #94a3b8; padding: 45px 20px;">
                  <div style="font-size: 2rem; margin-bottom: 8px;">📋</div>
                  <div style="font-weight: 700; color: #475569; font-size: 0.95rem;">No recent teacher requests found</div>
                  <div style="font-size: 0.82rem; color: #94a3b8; margin-top: 4px;">Faculty and teacher requests will automatically appear here in real-time.</div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($recent_activity as $act): ?>
                <tr>
                  <td class="activity-req-id"><?= htmlspecialchars($act['tracking_number']) ?></td>
                  <td style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($act['student_or_teacher_name']) ?></td>
                  <td style="color: #64748b;"><?= ucfirst($act['requester_role']) ?></td>
                  <td style="color: #334155;"><?= htmlspecialchars($act['request_type_name']) ?></td>
                  <td style="text-align: right;">
                    <a href="request_details.php?id=<?= $act['id'] ?>" class="btn-table-view">View</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

</div>
