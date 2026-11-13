<?php
// Auto-updater: extracts update_files.zip or copies from update_files folders
$zip_file = __DIR__ . '/update_files.zip';
if (file_exists($zip_file) && class_exists('ZipArchive')) {
    $zip = new ZipArchive;
    if ($zip->open($zip_file) === TRUE) {
        $zip->extractTo(__DIR__);
        $zip->close();
    }
}

if (!function_exists('copy_recursive')) {
    function copy_recursive($src, $dst) {
        if (!is_dir($src)) return;
        $dir = opendir($src);
        @mkdir($dst, 0755, true);
        while (false !== ($file = readdir($dir))) {
            if ($file !== '.' && $file !== '..') {
                if (is_dir($src . '/' . $file)) {
                    copy_recursive($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }
}

foreach (['update_files', 'update_files (1)'] as $folder) {
    $src = __DIR__ . '/' . $folder;
    if (is_dir($src)) {
        copy_recursive($src, __DIR__);
    }
}

echo "<div style='font-family: sans-serif; max-width: 650px; margin: 40px auto; padding: 25px; border-radius: 12px; background: #ecfdf5; border: 1.5px solid #86efac;'>";
echo "<h1 style='color: #15803d; margin-top: 0; font-size: 1.4rem;'>✅ SUCCESS! Lahat ng updates ay opisyal nang nailapat sa iadminconnect.com!</h1>";
echo "<ul style='color: #166534; font-size: 0.95rem; line-height: 1.6;'>";
echo "<li><strong>Multiple Supporting Documents:</strong> Pwede nang mag-upload ng kahit ilang files sa Attach Document (sabay-sabay).</li>";
echo "<li><strong>Pagination sa Users:</strong> 1-25 per page may navigation buttons.</li>";
echo "<li><strong>Streamlined Tracker:</strong> Wala nang Under Review, rekta Processing (50%).</li>";
echo "<li><strong>Completed Status:</strong> May berdeng Complete button at complete tracking lifecycle.</li>";
echo "</ul>";
echo "<p><a href='submit_request.php' style='display: inline-block; background: #16a34a; color: white; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: bold;'>Subukan ang New Request ↗</a> ";
echo "<a href='users.php' style='display: inline-block; background: #0284c7; color: white; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: bold; margin-left: 8px;'>Tingnan ang Users Directory ↗</a></p>";
echo "</div>";

@unlink(__FILE__);
