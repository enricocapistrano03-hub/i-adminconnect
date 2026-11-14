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
    'js/main.js',
    'users.php',
    'config/db_conn.php',
    'acknowledge_api.php',
    'assigned_requests.php',
    'admin_dashboard_view.php',
    'reports.php',
    'dashboard.php',
    'css/style.css',
    'includes/mailer.php',
    'includes/sidebar.php',
    'includes/header.php',
    'login.php',
    'index.php',
    'assets/logo.png',
    'assets/iAdminConnect LOGO.png',
    '.htaccess'
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

// Trigger database auto-migration
if (file_exists(__DIR__ . '/config/db_conn.php')) {
    try {
        require_once __DIR__ . '/config/db_conn.php';
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

echo "<div style='background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 14px 18px; margin-bottom: 20px;'>";
echo "<h3 style='margin: 0 0 8px 0; color: #166534; font-size: 0.95rem;'>Mga Na-update:</h3>";
echo "<ul style='margin: 0; padding-left: 20px; color: #15803d; font-size: 0.88rem; line-height: 1.6;'>";
echo "<li><strong>Records Archive (records.php):</strong> Agad nang papasok at makikita ang mga requests na minarkahang 'Completed' (kasama ng 'Ready for Release' at 'Released'), may kasama ding Status badge, clickable Ref link, at real-time search bar.</li>";
echo "<li><strong>Admin / Teacher Reject Presets (request_details.php):</strong> Inalis ang quick presets sa Permanent Rejection (admin na ang maglalagay). Sa COE at Service Record, inalis din ang presets sa Return for Revision. Sa Certificate of Rating, tanging Rating Discrepancy lamang ang nakalagay.</li>";
echo "<li><strong>Registrar / Student Reject Presets (request_details.php):</strong> Sa Good Moral Certificate, inalis lahat ng presets sa Revision at Permanent Rejection. Sa ibang student requests, inalis ang Valid ID at Incomplete Docs sa revision, at inalis ang Record Not Found at Institutional Guidelines sa rejection.</li>";
echo "<li><strong>Form 137 Student Locator &amp; Required Attachment (submit_request.php):</strong> Pinalitan ang additional notes ng required fields (Recent Section, Year Graduated, Transferee University) at ginawang strictly required ang pag-attach ng dokumento (Request Letter / Valid ID) bago makapag-proceed.</li>";
echo "</ul></div>";

echo "<div style='display: flex; gap: 12px; flex-wrap: wrap;'>";
echo "<a href='dashboard.php' style='background: #16a34a; color: white; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.92rem;'>Pumunta sa Dashboard ↗</a>";
echo "<a href='submit_request.php' style='background: #0284c7; color: white; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.92rem;'>New Request ↗</a>";
echo "</div>";

echo "</div></body></html>";

// Keep update.php available for future fast updates

