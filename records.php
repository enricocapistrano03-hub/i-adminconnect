<?php
$page_title_custom = "<h1>Records Archive</h1><p>Centralized repository of released certificates and official archives.</p>";
require_once __DIR__ . '/includes/header.php';

// Include Completed, Ready for Release, and Released so all finalized requests appear immediately
$stmt = $pdo->query("SELECT r.*, u.full_name, u.role as requester_role, rt.name as request_type_name
    FROM requests r
    JOIN users u ON r.user_id = u.id
    JOIN request_types rt ON r.request_type_id = rt.id
    WHERE r.status IN ('Completed', 'Ready for Release', 'Released')
    ORDER BY r.id DESC");
$records = $stmt->fetchAll();
?>

<div class="content-card">
  <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
    <div>
      <h2 class="card-title" style="margin-bottom: 4px;">Official Document Records Archive</h2>
      <p style="font-size: 0.9rem; color: #64748b; margin: 0;">
        Completed and released records available for official verification. (Total: <?= count($records) ?> <?= count($records) === 1 ? 'Record' : 'Records' ?>)
      </p>
    </div>
    <!-- Quick Real-Time Search -->
    <div style="max-width: 320px; width: 100%;">
      <input type="text" id="record-search" class="form-control" placeholder="🔍 Search records, recipient, ref..." onkeyup="filterRecordsTable()" style="font-size: 0.88rem; padding: 8px 14px; border-radius: 8px;" />
    </div>
  </div>

  <div class="table-container">
    <table class="custom-table" id="records-table">
      <thead>
        <tr>
          <th>Record Ref</th>
          <th>Recipient</th>
          <th>Role</th>
          <th>Document</th>
          <th>Status</th>
          <th>Release Date</th>
          <th>Verified Attachment</th>
          <th style="text-align: center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($records)): ?>
          <?php foreach ($records as $r): 
            $status_class = strtolower(str_replace(' ', '-', $r['status']));
            $release_date = $r['stage4_time'] ?: ($r['stage3_time'] ?: date('F j, Y', strtotime($r['updated_at'])));
          ?>
            <tr>
              <td>
                <a href="request_details.php?id=<?= $r['id'] ?>" style="color: #0284c7; font-weight: 800; text-decoration: none;">
                  <?= htmlspecialchars($r['tracking_number']) ?>
                </a>
              </td>
              <td><?= htmlspecialchars($r['full_name']) ?></td>
              <td>
                <span style="font-size: 0.82rem; font-weight: 700; padding: 2px 8px; border-radius: 6px; <?= $r['requester_role'] === 'teacher' ? 'background: #eff6ff; color: #1e40af;' : 'background: #f0fdf4; color: #166534;' ?>">
                  <?= ucfirst($r['requester_role']) ?>
                </span>
              </td>
              <td><?= htmlspecialchars($r['request_type_name']) ?></td>
              <td>
                <span class="badge-status <?= $status_class ?>"><?= htmlspecialchars($r['status']) ?></span>
              </td>
              <td><?= htmlspecialchars($release_date) ?></td>
              <td>
                <?php 
                $r_atts = parse_attachments($r['attached_filename'] ?? '', $r['file_size_kb'] ?? '');
                if (!empty($r_atts)): 
                ?>
                  <div style="display: flex; flex-direction: column; gap: 4px;">
                    <?php foreach ($r_atts as $att): ?>
                      <a href="view_file.php?id=<?= $r['id'] ?>&file=<?= urlencode($att['file']) ?>" target="_blank" class="attachment-preview-btn" title="View <?= htmlspecialchars($att['name']) ?> in new tab">
                        📄 <?= htmlspecialchars(mb_strimwidth($att['name'], 0, 22, '...')) ?> ↗
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span style="color: #94a3b8; font-size: 0.82rem;">None</span>
                <?php endif; ?>
              </td>
              <td style="text-align: center;">
                <a href="request_details.php?id=<?= $r['id'] ?>" style="background: #f8fafc; border: 1px solid #cbd5e1; color: #0284c7; padding: 5px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                  View ↗
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="8" style="text-align: center; color: #94a3b8; padding: 35px 20px;">
              Walang completed o released records na nahanap sa archive.
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function filterRecordsTable() {
  const query = document.getElementById('record-search').value.toLowerCase();
  const rows = document.querySelectorAll('#records-table tbody tr');
  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = text.includes(query) ? '' : 'none';
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
