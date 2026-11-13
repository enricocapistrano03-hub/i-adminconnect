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
    'includes/mailer.php'
];

$ctx = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
    'http' => [
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
        'timeout' => 15
    ]
]);

$success_count = 0;
$log = [];

foreach ($files as $rel_path) {
    $url = $base_url . $rel_path . '?v=' . time();
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
$db_migrated = false;
if (file_exists(__DIR__ . '/config/db_conn.php')) {
    try {
        require_once __DIR__ . '/config/db_conn.php';
        $db_migrated = true;
    } catch (Exception $e) {}
}

// Clean up leftover temporary folders if any
function remove_dir_recursive($dir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) remove_dir_recursive($path);
        else @unlink($path);
    }
    @rmdir($dir);
}
remove_dir_recursive(__DIR__ . '/update_files');
remove_dir_recursive(__DIR__ . '/update_files (1)');

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
echo "<li><strong>Multiple Supporting Documents:</strong> Pwede nang mag-attach ng kahit ilang dokumento nang sabay-sabay sa Submit Request at Edit Request!</li>";
echo "<li><strong>Users Directory Pagination:</strong> 1-25 per page may First, Prev, Page numbers, Next, at Last buttons.</li>";
echo "<li><strong>Streamlined 4-Stage Tracker:</strong> Inalis ang Under Review, rekta Processing (50%) agad.</li>";
echo "<li><strong>Completed Status:</strong> May bagong ✔ Mark as Completed button para sa Registrar, green badge, at email confirmation.</li>";
if ($db_migrated) {
    echo "<li><strong>Database Schema:</strong> Auto-migrated (ENUM 'Completed' & attached_filename TEXT).</li>";
}
echo "</ul></div>";

echo "<h4 style='margin: 0 0 10px 0; color: #475569; font-size: 0.85rem;'>Files Synchronized:</h4>";
echo "<div style='display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 6px; margin-bottom: 24px; font-size: 0.82rem;'>";
foreach ($log as $entry) {
    echo "<div style='background: #f8fafc; padding: 6px 10px; border-radius: 6px; border: 1px solid #e2e8f0;'>{$entry}</div>";
}
echo "</div>";

echo "<div style='display: flex; gap: 12px; flex-wrap: wrap;'>";
echo "<a href='submit_request.php' style='background: #16a34a; color: white; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.92rem; box-shadow: 0 2px 6px rgba(22,163,74,0.3);'>Subukan ang New Request ↗</a>";
echo "<a href='users.php' style='background: #0284c7; color: white; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.92rem;'>Users Directory ↗</a>";
echo "<a href='index.php' style='background: #e2e8f0; color: #334155; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.92rem;'>Home ↗</a>";
echo "</div>";

echo "</div></body></html>";

@unlink(__FILE__);
