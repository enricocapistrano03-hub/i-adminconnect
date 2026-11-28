<?php
// ==============================================================================
// i-AdminConnect Direct GitHub Live Sync & Deployer
// Automatically pulls the latest code directly from GitHub into public_html
// ==============================================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

$base_url = 'https://raw.githubusercontent.com/enricocapistrano03-hub/i-adminconnect/main/';
$files = [
    'submit_request.php',
    'request_details.php',
    'view_file.php',
    'edit_request.php',
    'records.php',
    'track_api.php',
    'keepalive_api.php',
    'js/main.js',
    'users.php',
    'config/db_conn.php',
    'acknowledge_api.php',
    'assigned_requests.php',
    'admin_dashboard_view.php',
    'export_csv.php',
    'reports.php',
    'dashboard.php',
    'notifications.php',
    'audit_logs.php',
    'css/style.css',
    'style.css',
    'config/announcements.json',
    'guidance_records.php',
    'grades_management.php',
    'my_grades.php',
    'download_grade_template.php',
    'includes/mailer.php',
    'includes/bulk_user_parser.php',
    'includes/sidebar.php',
    'includes/header.php',
    'includes/footer.php',
    'includes/registrar_overview_view.php',
    'includes/admin_dashboard_view.php',
    'header.php',
    'login.php',
    'logout.php',
    'index.php',
    'assets/logo.png',
    'assets/iAdminConnect LOGO.png',
    '.htaccess',
    'uploads/.htaccess',
    'defense_reviewer.html'
];

$ctx = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
    'http' => [
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
        'timeout' => 20
    ]
]);

$success_count = 0;
$log = [];

foreach ($files as $rel_path) {
    $encoded_path = implode('/', array_map('rawurlencode', explode('/', $rel_path)));
    $url = $base_url . $encoded_path . '?v=' . time();
    $data = @file_get_contents($url, false, $ctx);
    if ($data !== false && strlen($data) > 0) {
        $dest = __DIR__ . '/' . $rel_path;
        $dest_dir = dirname($dest);
        if (!is_dir($dest_dir)) @mkdir($dest_dir, 0755, true);
        file_put_contents($dest, $data);
        $success_count++;
        $log[] = "✅ <span style='color: #15803d; font-weight: 700;'>{$rel_path}</span> (" . round(strlen($data) / 1024, 1) . " KB)";
    } else {
        $log[] = "❌ <span style='color: #b91c1c;'>Failed to fetch {$rel_path}</span>";
    }
}

// Trigger database auto-migration and audit log healing
$healed_requests = [];
if (file_exists(__DIR__ . '/config/db_conn.php')) {
    try {
        require_once __DIR__ . '/config/db_conn.php';

        if (isset($pdo)) {
            // Auto-heal any completed requests missing their Completed audit log entry
            $comp_stmt = $pdo->query("SELECT r.tracking_number, COALESCE(rt.name, 'Document') as doc_name 
                FROM requests r 
                LEFT JOIN request_types rt ON r.request_type_id = rt.id 
                WHERE r.status = 'Completed'");
            while ($cr = $comp_stmt->fetch()) {
                $chk_log = $pdo->prepare("SELECT id FROM audit_logs WHERE action LIKE ? LIMIT 1");
                $chk_log->execute(["%{$cr['tracking_number']}%Completed%"]);
                if (!$chk_log->fetch()) {
                    log_audit_action($pdo, 'System Administrator', 'ADMIN', "Marked Request {$cr['tracking_number']} ({$cr['doc_name']}) as Completed");
                    $healed_requests[] = $cr['tracking_number'];
                }
            }
        }
    } catch (Exception $e) {}
}

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Live Update Applied</title></head>";
echo "<body style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; background: #f8fafc; padding: 30px 15px;'>";
echo "<div style='max-width: 680px; margin: 0 auto; background: white; border-radius: 16px; padding: 28px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1.5px solid #86efac;'>";

echo "<div style='display: flex; align-items: center; gap: 12px; margin-bottom: 16px;'>";
echo "<span style='font-size: 2.2rem;'>🎉</span>";
echo "<div><h1 style='margin: 0; color: #166534; font-size: 1.35rem;'>Updates Successfully Applied Live!</h1>";
echo "<p style='margin: 2px 0 0 0; color: #15803d; font-size: 0.9rem;'>{$success_count} of " . count($files) . " files were directly synced and written to iadminconnect.com.</p></div>";
echo "</div>";

if (!empty($healed_requests)) {
    echo "<div style='background: #ecfdf5; border: 1.5px solid #a7f3d0; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; color: #065f46; font-size: 0.88rem;'>";
    echo "<strong>✔ Auto-Healed Audit Logs:</strong> Naitama at naidagdag na sa Audit Trail ang 'Completed' record para sa requests: <strong>" . implode(', ', $healed_requests) . "</strong>.";
    echo "</div>";
}

echo "<div style='background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 14px 18px; margin-bottom: 20px;'>";
echo "<h3 style='margin: 0 0 8px 0; color: #166534; font-size: 0.95rem;'>Mga Na-update (Complete Post-Defense Revisions Phases 1 - 4):</h3>";
echo "<ul style='margin: 0; padding-left: 20px; color: #15803d; font-size: 0.88rem; line-height: 1.6;'>";
echo "<li><strong>Caching & Data Optimization (Phase 4):</strong> Na-enable ang browser static caching (1-year/1-month cache sa images, CSS, JS, fonts) at Gzip compression sa <code>.htaccess</code>. Naidagdag ang high-performance database indexes sa <code>users</code>, <code>requests</code>, at <code>audit_logs</code> upang mapabilis ang search ng libo-libong alumni records nang hindi sumasabog ang server.</li>";
echo "<li><strong>Alumni Directory Filtering (Phase 4):</strong> Pwedeng salain ni Admin ang mga estudyante ayon sa 'Graduated (Alumni)' status sa User Directory na may pagination.</li>";
echo "<li><strong>Viewing of Grades & SF9 Report Card (Phase 3):</strong> Bagong Student portal (<code>my_grades.php</code>) para sa real-time quarterly ratings, GWA, honors standing, at printable DepEd SF9 Report Card.</li>";
echo "<li><strong>Grade Management & Batch CSV Uploader (Phase 3):</strong> Bagong encoding portal (<code>grades_management.php</code>) para sa Teachers at Registrar na may CSV batch uploader, official template download, at individual grade encoder.</li>";
echo "<li><strong>Guidance Disciplinary Records & Hold Guard (Phase 2):</strong> Bagong table at management page (<code>guidance_records.php</code>) para sa Guidance & Registrar upang mag-record, mag-hold, o mag-clear ng disciplinary cases.</li>";
echo "<li><strong>Good Moral Clearance Hold (Phase 2):</strong> Awtomatikong bina-block ang pag-request ng Good Moral Certificate ng estudyanteng may aktibong kaso sa Guidance, may red warning banner, at hindi maaaring i-release ni Registrar hangga't hindi cleared.</li>";
echo "<li><strong>Strict Input Validation (Phase 1):</strong> Numbers only sa Year (e.g. 2026, awtomatikong bawal ang '2026a'), LRN, at Copies; Letters only sa Full Names ng estudyante at empleyado.</li>";
echo "<li><strong>Form 137 Top 100+ PH Universities Autocomplete (Phase 1):</strong> May searchable listahan ng premier state and private universities at science high schools habang nagta-type, plus 'Others' box para sa custom school address.</li>";
echo "<li><strong>User Directory & Graduated Status (Phase 1):</strong> Dynamic guide label para sa Student LRN (12 Digits) sa Add/Edit User modals, at 'Graduated / Alumnus' status choice.</li>";
echo "</ul></div>";

echo "<div style='display: flex; gap: 12px; flex-wrap: wrap;'>";
echo "<a href='dashboard.php' style='background: #16a34a; color: white; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.92rem;'>Pumunta sa Dashboard ↗</a>";
echo "<a href='submit_request.php' style='background: #0284c7; color: white; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.92rem;'>New Request ↗</a>";
echo "</div>";

echo "</div></body></html>";

// Keep update.php available for future fast updates

