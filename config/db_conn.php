<?php
// ==============================================================================
// i-AdminConnect Database Connection Configuration (XAMPP / MySQL)
// ==============================================================================

// Set system timezone to Philippine Standard Time (PST / Asia/Manila, UTC+8)
date_default_timezone_set('Asia/Manila');

// Canonical Non-WWW Redirection on Production (Prevents 403 Forbidden / CORS / Session mismatch)
if (!empty($_SERVER['HTTP_HOST']) && strpos(strtolower($_SERVER['HTTP_HOST']), 'www.iadminconnect.com') === 0) {
    $redirect_url = 'https://iadminconnect.com' . ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . $redirect_url, true, 301);
    exit();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ------------------------------------------------------------------------------
// 5-Minute Session Inactivity Timeout Guard (300 seconds)
// Protects student and faculty data if a device is left unattended
// ------------------------------------------------------------------------------
$session_timeout_seconds = 5 * 60; // 300 seconds = 5 minutes

if (!empty($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout_seconds)) {
        // Clear session variables and destroy session
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        $current_script = basename($_SERVER['PHP_SELF'] ?? '');
        $is_api = (strpos($current_script, '_api.php') !== false || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false));

        if ($is_api) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'session_timeout', 'message' => 'Your session has expired due to 5 minutes of inactivity. Please log in again.']);
            exit();
        } elseif ($current_script !== 'login.php' && $current_script !== 'logout.php') {
            header("Location: login.php?timeout=1");
            exit();
        }
    } else {
        // Update last activity timestamp on active request
        $_SESSION['last_activity'] = time();
    }
}

// Determine database credentials based on environment (Hostinger Production vs Localhost XAMPP)
$is_hostinger = (
    (isset($_SERVER['SERVER_NAME']) && (strpos($_SERVER['SERVER_NAME'], 'iadminconnect.com') !== false || strpos($_SERVER['SERVER_NAME'], 'i-adminconnect.online') !== false || strpos($_SERVER['SERVER_NAME'], 'hostinger') !== false)) ||
    (isset($_SERVER['HTTP_HOST']) && (strpos($_SERVER['HTTP_HOST'], 'iadminconnect.com') !== false || strpos($_SERVER['HTTP_HOST'], 'i-adminconnect.online') !== false || strpos($_SERVER['HTTP_HOST'], 'hostinger') !== false))
);

if ($is_hostinger) {
    $db_host = "localhost";
    $db_user = "u840449196_usr_eMgyuJkY";
    $db_pass = "8ns|TEHkH/C";
    $db_name = "u840449196_db_eMgyuJkY";
} else {
    $db_host = "localhost";
    $db_user = "root";
    $db_pass = "";
    $db_name = "i_adminconnect_db";
}

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    // Ensure MySQL session operates on Philippine Time (UTC+8)
    $pdo->exec("SET time_zone = '+08:00'");

    // Auto-migrate schema updates if missing (e.g., copies column in requests)
    try {
        $pdo->query("SELECT copies FROM requests LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE requests ADD COLUMN copies INT NOT NULL DEFAULT 1 AFTER request_type_id");
        } catch (Exception $ex) {
            // Table may not exist yet or column concurrently added
        }
    }

    // Auto-migrate status enum to support 'Completed'
    try {
        $pdo->exec("ALTER TABLE requests MODIFY COLUMN status ENUM('Pending','Under Review','Processing','Ready for Release','Released','Completed','Returned for Revision','Rejected') NOT NULL DEFAULT 'Processing'");
    } catch (Exception $e) {
        // Silently ignore if already migrated
    }

    // Auto-migrate users status enum to support 'Graduated' (Alumni student status)
    try {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN status ENUM('Active','Inactive','Dropped','Pending','Graduated') NOT NULL DEFAULT 'Active'");
    } catch (Exception $e) {
        // Silently ignore if already migrated
    }

    // Auto-migrate login_attempts column in users table
    try {
        $pdo->query("SELECT login_attempts FROM users LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN login_attempts INT NOT NULL DEFAULT 0 AFTER status");
        } catch (Exception $ex) {
            // Silently ignore if concurrently added
        }
    }

    // Auto-migrate lockout_time column in users table for temporary rate limiting (15-minute cooldown)
    try {
        $pdo->query("SELECT lockout_time FROM users LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN lockout_time INT NOT NULL DEFAULT 0 AFTER login_attempts");
        } catch (Exception $ex) {
            // Silently ignore if concurrently added
        }
    }

    // Auto-restore any accounts that were permanently set to Inactive by legacy 3-attempt lockout
    try {
        $pdo->exec("UPDATE users SET status = 'Active', login_attempts = 0, lockout_time = 0 WHERE status = 'Inactive' AND login_attempts >= 3");
    } catch (Exception $e) {
        // Silently ignore
    }

    // Auto-migrate attached_filename to TEXT to support multiple documents JSON
    try {
        $pdo->exec("ALTER TABLE requests MODIFY COLUMN attached_filename TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE requests MODIFY COLUMN file_size_kb VARCHAR(255) DEFAULT NULL");
    } catch (Exception $e) {
        // Silently ignore if already migrated
    }

    // Clean up phantom 'Document.pdf' placeholder data where no file actually exists
    try {
        $pdo->exec("UPDATE requests SET attached_filename = NULL, file_size_kb = NULL, file_verified = 0 WHERE attached_filename = 'Document.pdf'");
    } catch (Exception $e) {
        // Silently ignore if table not yet loaded
    }

    // Auto-migrate disciplinary_records table for Guidance Hold List
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS disciplinary_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lrn VARCHAR(32) NOT NULL,
            student_name VARCHAR(255) NOT NULL,
            grade_section VARCHAR(100) DEFAULT NULL,
            case_title VARCHAR(255) NOT NULL,
            case_details TEXT DEFAULT NULL,
            date_reported DATE NOT NULL,
            status ENUM('Active Hold', 'Cleared') NOT NULL DEFAULT 'Active Hold',
            cleared_date DATETIME DEFAULT NULL,
            cleared_by VARCHAR(150) DEFAULT NULL,
            created_by VARCHAR(150) DEFAULT 'Guidance Office',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_lrn (lrn),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Seed initial sample guidance records if table is brand new
        $chk_cases = $pdo->query("SELECT COUNT(*) FROM disciplinary_records");
        if ($chk_cases && (int)$chk_cases->fetchColumn() === 0) {
            $sample_ins = $pdo->prepare("INSERT INTO disciplinary_records 
                (lrn, student_name, grade_section, case_title, case_details, date_reported, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $sample_ins->execute([
                '136887180099',
                'Sample Hold Student (Guidance Case)',
                'Grade 10 - Einstein',
                'Pending Disciplinary Review: Campus Conduct & Property Disturbance',
                'Student has an unresolved disciplinary citation regarding campus disturbance reported by faculty adviser. Good Moral clearance held pending counseling completion.',
                date('Y-m-d', strtotime('-5 days')),
                'Active Hold',
                'Guidance Office'
            ]);
        }
    } catch (Exception $e) {
        // Silently ignore
    }

    // Auto-migrate student_grades table for Viewing of Grades & SF9 Report Card
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_grades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lrn VARCHAR(32) NOT NULL,
            student_name VARCHAR(150) NOT NULL,
            grade_level VARCHAR(50) NOT NULL,
            section VARCHAR(50) DEFAULT NULL,
            school_year VARCHAR(20) NOT NULL,
            semester VARCHAR(30) NOT NULL DEFAULT '1st Semester',
            subject_code VARCHAR(30) DEFAULT NULL,
            subject_name VARCHAR(150) NOT NULL,
            q1 DECIMAL(5,2) DEFAULT NULL,
            q2 DECIMAL(5,2) DEFAULT NULL,
            q3 DECIMAL(5,2) DEFAULT NULL,
            q4 DECIMAL(5,2) DEFAULT NULL,
            final_grade DECIMAL(5,2) DEFAULT NULL,
            remarks VARCHAR(30) DEFAULT 'Passed',
            teacher_name VARCHAR(150) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_lrn (lrn),
            INDEX idx_sy_sem (school_year, semester),
            INDEX idx_grade_section (grade_level, section),
            UNIQUE KEY uniq_student_subject (lrn, school_year, semester, subject_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Seed initial sample grades if table is empty
        $chk_grades = $pdo->query("SELECT COUNT(*) FROM student_grades");
        if ($chk_grades && (int)$chk_grades->fetchColumn() === 0) {
            $sample_subjects = [
                ['sub' => 'Oral Communication in Context', 'code' => 'OCC-11', 'q1' => 88, 'q2' => 90, 'final' => 89, 'rem' => 'Passed'],
                ['sub' => 'Komunikasyon at Pananaliksik sa Wika at Kulturang Pilipino', 'code' => 'KPWKP-11', 'q1' => 91, 'q2' => 92, 'final' => 92, 'rem' => 'Passed'],
                ['sub' => 'General Mathematics', 'code' => 'GENMATH-11', 'q1' => 86, 'q2' => 89, 'final' => 88, 'rem' => 'Passed'],
                ['sub' => 'Earth and Life Science', 'code' => 'ELS-11', 'q1' => 87, 'q2' => 91, 'final' => 89, 'rem' => 'Passed'],
                ['sub' => 'Personal Development / Pansariling Kaunlaran', 'code' => 'PERDEV-11', 'q1' => 93, 'q2' => 94, 'final' => 94, 'rem' => 'Passed'],
                ['sub' => 'Understanding Culture, Society and Politics', 'code' => 'UCSP-11', 'q1' => 89, 'q2' => 90, 'final' => 90, 'rem' => 'Passed'],
                ['sub' => 'Physical Education and Health 1', 'code' => 'PEH-11', 'q1' => 95, 'q2' => 96, 'final' => 96, 'rem' => 'Passed'],
                ['sub' => 'Empowerment Technologies (E-Tech)', 'code' => 'ETECH-11', 'q1' => 92, 'q2' => 94, 'final' => 93, 'rem' => 'Passed'],
            ];

            $students_to_seed = [];
            try {
                $st_query = $pdo->query("SELECT id_number, full_name, section, year_level FROM users WHERE role = 'student' AND id_number IS NOT NULL AND id_number != '' LIMIT 5");
                while ($s = $st_query->fetch(PDO::FETCH_ASSOC)) {
                    $students_to_seed[] = [
                        'lrn' => $s['id_number'],
                        'name' => $s['full_name'],
                        'grade' => !empty($s['year_level']) ? $s['year_level'] : 'Grade 11',
                        'sec' => !empty($s['section']) ? $s['section'] : 'STEM - Einstein'
                    ];
                }
            } catch (Exception $e) {}

            if (empty($students_to_seed)) {
                $students_to_seed[] = [
                    'lrn' => '136887180001',
                    'name' => 'Maria Santos',
                    'grade' => 'Grade 11',
                    'sec' => 'STEM - Einstein'
                ];
                $students_to_seed[] = [
                    'lrn' => '136887180002',
                    'name' => 'Enrico Capistrano',
                    'grade' => 'Grade 11',
                    'sec' => 'STEM - Einstein'
                ];
            }

            $ins_g = $pdo->prepare("INSERT IGNORE INTO student_grades 
                (lrn, student_name, grade_level, section, school_year, semester, subject_code, subject_name, q1, q2, final_grade, remarks, teacher_name)
                VALUES (?, ?, ?, ?, '2025-2026', '1st Semester', ?, ?, ?, ?, ?, ?, 'Faculty Adviser')");

            foreach ($students_to_seed as $stu) {
                foreach ($sample_subjects as $sub) {
                    $ins_g->execute([
                        $stu['lrn'],
                        $stu['name'],
                        $stu['grade'],
                        $stu['sec'],
                        $sub['code'],
                        $sub['sub'],
                        $sub['q1'],
                        $sub['q2'],
                        $sub['final'],
                        $sub['rem']
                    ]);
                }
            }
        }
    } catch (Exception $e) {
        // Silently ignore
    }

    // Auto-migrate high-performance database indexes for scale and alumni lookups (Phase 4)
    $ensure_index = function($table, $idx_name, $cols) use ($pdo) {
        try {
            $chk = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$idx_name}'");
            if ($chk && !$chk->fetch()) {
                $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$idx_name}` ({$cols})");
            }
        } catch (Exception $ex) {
            // Silently ignore if already exists or table busy
        }
    };

    // Indexes on users table (instant LRN, alumni status, and student queries)
    $ensure_index('users', 'idx_users_id_number', '`id_number`');
    $ensure_index('users', 'idx_users_role_status', '`role`, `status`');
    $ensure_index('users', 'idx_users_fullname', '`full_name`(50)');

    // Indexes on requests table (instant status filtering, sorting, and user queries)
    $ensure_index('requests', 'idx_requests_status', '`status`');
    $ensure_index('requests', 'idx_requests_user_status', '`user_id`, `status`');
    $ensure_index('requests', 'idx_requests_created_at', '`created_at`');

    // Indexes on audit_logs table (fast audit trail pagination and search)
    $ensure_index('audit_logs', 'idx_audit_logs_created_at', '`created_at`');
    $ensure_index('audit_logs', 'idx_audit_logs_role', '`role`');
} catch (PDOException $e) {
    // Helpful message if database hasn't been imported yet in phpMyAdmin
    die("<div style='font-family: sans-serif; padding: 20px; background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; margin: 40px auto; max-width: 600px; color: #991b1b;'>
        <h2 style='margin-top:0;'>⚠️ Database Connection Failed</h2>
        <p>Could not connect to MySQL database <strong>{$db_name}</strong>.</p>
        <p><strong>Remedy for XAMPP:</strong></p>
        <ol>
            <li>Make sure <strong>Apache</strong> and <strong>MySQL</strong> are started in your XAMPP Control Panel.</li>
            <li>Open <a href='http://localhost/phpmyadmin' target='_blank'>http://localhost/phpmyadmin</a> in your browser.</li>
            <li>Click <strong>Import</strong> tab and import the file: <code>database/i_adminconnect_db.sql</code>.</li>
        </ol>
        <p><small>Error details: " . htmlspecialchars($e->getMessage()) . "</small></p>
    </div>");
}

// Audit logger helper
function log_audit_action($pdo, $user_name, $role, $action) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_name, role, action, ip_address) VALUES (?, ?, ?, ?)");
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt->execute([$user_name, strtoupper($role), $action, $ip]);
    } catch (Exception $e) {
        // Silently ignore log write errors
    }
}

/**
 * Parse attachments JSON or legacy string into structured array of files
 * @param string|null $attached_filename
 * @param string|null $file_size_kb
 * @return array Array of ['name' => ..., 'file' => ..., 'size' => ...]
 */
function parse_attachments($attached_filename, $file_size_kb = '') {
    if (empty($attached_filename)) return [];
    $trimmed = trim((string)$attached_filename);
    if ($trimmed === 'Document.pdf' || $trimmed === '') return [];

    if ((str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) || 
        (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))) {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && !empty($decoded)) {
            $result = [];
            foreach ($decoded as $item) {
                if (is_array($item)) {
                    $result[] = [
                        'name' => $item['name'] ?? 'Document',
                        'file' => $item['file'] ?? ($item['name'] ?? ''),
                        'size' => $item['size'] ?? 'File'
                    ];
                } elseif (is_string($item)) {
                    $result[] = [
                        'name' => $item,
                        'file' => $item,
                        'size' => 'File'
                    ];
                }
            }
            if (!empty($result)) return $result;
        }
    }

    // Single legacy file
    return [[
        'name' => $trimmed,
        'file' => $trimmed,
        'size' => $file_size_kb ?: 'File'
    ]];
}
?>
