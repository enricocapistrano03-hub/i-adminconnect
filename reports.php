<?php
$page_title_custom = "<h1>Reports &amp; Analytics</h1><p>Administrative throughput, monthly document volume, and performance metrics.</p>";
require_once __DIR__ . '/includes/header.php';

if ($user_role !== 'admin') {
    die("Access denied.");
}

$current_month_name = date('F Y');

// Fetch high-level totals
$stmt = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status IN ('Ready for Release', 'Released') THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status IN ('Pending', 'Under Review') THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected
    FROM requests");
$totals = $stmt->fetch();

$total = (int)($totals['total'] ?? 0);
$completed = (int)($totals['completed'] ?? 0);
$backlog = (int)($totals['pending'] ?? 0);
$rate = $total > 0 ? round(($completed / $total) * 100) : 0;

// Dynamic Turnaround Time Calculation (Time from submission to release)
$tat_stmt = $pdo->query("SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_sec
    FROM requests 
    WHERE status IN ('Ready for Release', 'Released') AND updated_at >= created_at");
$avg_sec = $tat_stmt->fetchColumn();

if ($avg_sec !== null && $avg_sec > 0 && $completed > 0) {
    $days = round($avg_sec / 86400, 1);
    if ($days >= 1.0) {
        $avg_tat = "{$days}d"; // e.g. 1.8d
    } elseif ($avg_sec >= 3600) {
        $avg_tat = round($avg_sec / 3600, 1) . "h"; // e.g. 2.5h
    } elseif ($avg_sec >= 60) {
        $avg_tat = round($avg_sec / 60) . "m"; // e.g. 15m
    } else {
        $avg_tat = round($avg_sec) . "s"; // e.g. 56s
    }
} else {
    $avg_tat = "0.0d";
}

// Fetch document breakdown dynamically
$breakdown_stmt = $pdo->query("SELECT rt.name, COUNT(r.id) as req_count
    FROM request_types rt
    LEFT JOIN requests r ON r.request_type_id = rt.id
    GROUP BY rt.id
    ORDER BY req_count DESC, rt.name ASC");
$breakdown = $breakdown_stmt->fetchAll(PDO::FETCH_ASSOC);

$bar_colors = [
    '#0284c7', // Sky Blue
    '#10b981', // Emerald
    '#8b5cf6', // Violet
    '#f59e0b', // Amber
    '#ec4899', // Pink
    '#06b6d4', // Cyan
    '#6366f1', // Indigo
    '#14b8a6'  // Teal
];
?>

<!-- 1. Top KPI Summary Grid -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">TOTAL VOLUME</div>
    <div class="stat-value"><?= $total ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">APPROVAL RATE</div>
    <div class="stat-value"><?= $rate ?>%</div>
  </div>
  <div class="stat-card" title="Average duration required to review and release a request">
    <div class="stat-label">AVERAGE TURNAROUND</div>
    <div class="stat-value"><?= $avg_tat ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">ACTIVE BACKLOG</div>
    <div class="stat-value"><?= $backlog ?></div>
  </div>
</div>

<!-- 2. Monthly Request Breakdown Card -->
<div class="content-card">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 10px;">
    <h2 class="card-title" style="margin: 0;">Monthly Request Summary (<?= htmlspecialchars($current_month_name) ?>)</h2>
    <span style="font-size: 0.8rem; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 4px 12px; border-radius: 14px;">
      <?= $total ?> Total <?= $total === 1 ? 'Transaction' : 'Transactions' ?>
    </span>
  </div>

  <p style="font-size: 0.9rem; color: #64748b; margin-bottom: 24px;">
    Real-time breakdown of administrative document transactions processed through i-AdminConnect.
  </p>

  <?php if ($total === 0): ?>
    <div style="text-align: center; padding: 40px 20px; color: #94a3b8; background: #f8fafc; border-radius: 12px; border: 1px dashed #cbd5e1;">
      <div style="font-size: 2.2rem; margin-bottom: 8px;">📊</div>
      <div style="font-weight: 700; color: #475569; font-size: 0.95rem;">No request data for <?= htmlspecialchars($current_month_name) ?></div>
      <div style="font-size: 0.82rem; color: #94a3b8; margin-top: 4px;">Progress bars and percentage distribution will automatically display here as students and teachers submit requests.</div>
    </div>
  <?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 18px;">
      <?php 
        $color_idx = 0;
        foreach ($breakdown as $row): 
          $cnt = (int)$row['req_count'];
          $pct = $total > 0 ? round(($cnt / $total) * 100) : 0;
          $c = $bar_colors[$color_idx % count($bar_colors)];
          $color_idx++;

          // Display document type if it has requests OR if total < 5 show it to give overview
          if ($cnt === 0 && $total >= 5) continue;
      ?>
        <div>
          <div style="display: flex; justify-content: space-between; font-size: 0.86rem; font-weight: 700; margin-bottom: 6px;">
            <span style="color: #1e293b;"><?= htmlspecialchars($row['name']) ?></span>
            <span style="color: <?= $cnt > 0 ? '#0f172a' : '#94a3b8' ?>;">
              <?= $cnt ?> <?= $cnt === 1 ? 'request' : 'requests' ?> (<?= $pct ?>%)
            </span>
          </div>
          <div style="background: #e2e8f0; height: 10px; border-radius: 6px; overflow: hidden;">
            <div style="background: <?= $c ?>; width: <?= $pct ?>%; height: 100%; transition: width 0.5s ease-out; border-radius: 6px;"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div style="margin-top: 32px; text-align: right;">
    <a href="export_csv.php?scope=all" class="btn-primary-cta" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
      <span>📥</span> Download Detailed Report (.CSV)
    </a>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
