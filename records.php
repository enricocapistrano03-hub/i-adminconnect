<?php
$page_title_custom = "<h1>Records Archive</h1><p>Centralized repository of released certificates and official archives.</p>";
require_once __DIR__ . '/includes/header.php';

$stmt = $pdo->query("SELECT r.*, u.full_name, u.role as requester_role, rt.name as request_type_name
    FROM requests r
    JOIN users u ON r.user_id = u.id
    JOIN request_types rt ON r.request_type_id = rt.id
    WHERE r.status IN ('Ready for Release', 'Released')
    ORDER BY r.id DESC");
$records = $stmt->fetchAll();
?>

<div class="content-card">
  <h2 class="card-title" style="margin-bottom: 10px;">Official Document Records Archive</h2>
  <p style="font-size: 0.9rem; color: #64748b; margin-bottom: 20px;">
    Completed and released records available for official verification.
  </p>

  <div class="table-container">
    <table class="custom-table">
      <thead>
        <tr>
          <th>Record Ref</th>
          <th>Recipient</th>
          <th>Role</th>
          <th>Document</th>
          <th>Release Date</th>
          <th>Verified Attachment</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $r): ?>
          <tr>
            <td><strong><?= htmlspecialchars($r['tracking_number']) ?></strong></td>
            <td><?= htmlspecialchars($r['full_name']) ?></td>
            <td><?= ucfirst($r['requester_role']) ?></td>
            <td><?= htmlspecialchars($r['request_type_name']) ?></td>
            <td><?= htmlspecialchars($r['stage4_time'] ?: date('F j, Y', strtotime($r['updated_at']))) ?></td>
            <td>
              <?php if (!empty($r['attached_filename'])): ?>
                <a href="view_file.php?id=<?= $r['id'] ?>" target="_blank" class="attachment-preview-btn" title="Click to view file in new tab">
                  📄 <?= htmlspecialchars($r['attached_filename']) ?> ↗
                </a>
              <?php else: ?>
                <span style="color: #94a3b8; font-size: 0.82rem;">None</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
