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
    'reports.php',
    'dashboard.php',
    'notifications.php',
    'css/style.css',
    'style.css',
    'config/announcements.json',
    'includes/mailer.php',
    'includes/sidebar.php',
    'includes/header.php',
    'includes/footer.php',
    'includes/registrar_overview_view.php',
    'header.php',
    'login.php',
    'logout.php',
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
echo "<li><strong>Request Details (request_details.php):</strong> Inalis na ang Permanent Rejection option card. Kapag pinindot ang Reject, diretso nang maglalagay ng dahilan / tagubilin para sa requester at magsa-submit as Return for Revision.</li>";
echo "<li><strong>3-Attempt Login Security Lockout (login.php &amp; db_conn.php):</strong> Kapag naka-3 sunod-sunod na maling password attempt, awtomatikong mala-lock ang account (status Inactive) at may babalang makipag-ugnayan sa School Administrator.</li>";
echo "<li><strong>Admin One-Click Account Unlock (users.php):</strong> May bagong '🔓 Unlock' button at '🔒 Locked (3 Attempts)' indicator sa User Directory para madaling ma-reactivate ng Administrator ang na-lock na estudyante o guro.</li>";
echo "</ul></div>";

echo "<div style='display: flex; gap: 12px; flex-wrap: wrap;'>";
echo "<a href='dashboard.php' style='background: #16a34a; color: white; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.92rem;'>Pumunta sa Dashboard ↗</a>";
echo "<a href='submit_request.php' style='background: #0284c7; color: white; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.92rem;'>New Request ↗</a>";
echo "</div>";

echo "</div></body></html>";

// Keep update.php available for future fast updates

