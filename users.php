<?php
$page_title_custom = "<h1>User Account Directory</h1><p>Manage authorized students, teachers, registrars, and administrative personnel.</p>";
require_once __DIR__ . '/includes/header.php';

if ($user_role !== 'admin') {
    die("<div class='content-card'><h2>Access Denied</h2><p>Administrator privileges required.</p></div>");
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$target_id = (int)($_GET['id'] ?? ($_POST['target_id'] ?? 0));
$success_msg = '';
$error_msg = '';
$bulk_summary = null;

// 1. Handle Approval of Pending Registrar/User
if ($action === 'approve' && $target_id > 0) {
    $stmt = $pdo->prepare("UPDATE users SET status = 'Active' WHERE id = ? AND status = 'Pending'");
    $stmt->execute([$target_id]);

    $stmt_u = $pdo->prepare("SELECT full_name, email, role FROM users WHERE id = ?");
    $stmt_u->execute([$target_id]);
    $u_info = $stmt_u->fetch();

    if ($u_info) {
        log_audit_action($pdo, $user_name, 'ADMIN', "Approved and activated account: {$u_info['full_name']} ({$u_info['email']})");
        $success_msg = "Account for <strong>" . htmlspecialchars($u_info['full_name']) . "</strong> has been approved and activated! They can now log in and access the system.";
    }
}

// 2. Handle Permanent User Deletion (Cascades to requests, attachments, and notifications)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_user' && $target_id > 0) {
    if ($target_id === (int)$user_id) {
        $error_msg = "You cannot delete your own Administrator account while logged in.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$target_id]);
        $del_user = $stmt->fetch();

        if (!$del_user) {
            $error_msg = "User record not found or already deleted.";
        } else {
            // Find all requests for this user and remove physical file uploads
            $req_stmt = $pdo->prepare("SELECT id, tracking_number, attached_filename FROM requests WHERE user_id = ?");
            $req_stmt->execute([$target_id]);
            $user_requests = $req_stmt->fetchAll();

            $uploads_dir = __DIR__ . '/uploads/';
            foreach ($user_requests as $req) {
                if (!empty($req['attached_filename'])) {
                    $atts = parse_attachments($req['attached_filename'], $req['file_size_kb'] ?? '');
                    foreach ($atts as $att) {
                        $fn = $att['file'];
                        $clean_fn = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fn);
                        if (file_exists($uploads_dir . $fn)) {
                            @unlink($uploads_dir . $fn);
                        } elseif (file_exists($uploads_dir . $clean_fn)) {
                            @unlink($uploads_dir . $clean_fn);
                        } else {
                            $matches = glob($uploads_dir . '*' . $clean_fn);
                            if (!empty($matches)) {
                                foreach ($matches as $m) @unlink($m);
                            }
                        }
                    }
                }

                // Delete notifications tied to this tracking number
                $pdo->prepare("DELETE FROM notifications WHERE request_id = ?")->execute([$req['tracking_number']]);
            }

            // Delete notifications sent to this user
            $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$target_id]);

            // Delete all requests of this user
            $pdo->prepare("DELETE FROM requests WHERE user_id = ?")->execute([$target_id]);

            // Delete OTP records for this email
            $pdo->prepare("DELETE FROM otp_verifications WHERE email = ?")->execute([$del_user['email']]);

            // Delete the user record from database
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$target_id]);

            // Log audit action
            log_audit_action($pdo, $user_name, 'ADMIN', "Permanently deleted user account ID {$target_id}: {$del_user['full_name']} ({$del_user['role']}) and all related records");

            $success_msg = "User <strong>" . htmlspecialchars($del_user['full_name']) . "</strong> (" . ucfirst($del_user['role']) . ") and all related requests, files, and data were permanently deleted from the system.";
        }
    }
}

// 3. Handle User Editing / Updating
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_user') {
    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $id_number = trim($_POST['id_number'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $role = trim(strtolower($_POST['role'] ?? 'student'));
    $department = trim($_POST['department'] ?? '');
    $status = trim($_POST['status'] ?? 'Active');

    $allowed_roles = ['student', 'teacher', 'registrar', 'admin'];
    $allowed_statuses = ['Active', 'Pending', 'Inactive', 'Dropped'];

    if ($edit_id <= 0 || empty($full_name) || empty($email)) {
        $error_msg = "Please fill in all required fields (Full Name and Email).";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = "Please provide a valid email address.";
    } elseif (!in_array($role, $allowed_roles)) {
        $error_msg = "Invalid role selected.";
    } elseif (!in_array($status, $allowed_statuses)) {
        $error_msg = "Invalid account status.";
    } else {
        // Check email uniqueness
        $chk_email = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $chk_email->execute([$email, $edit_id]);
        if ($chk_email->fetch()) {
            $error_msg = "That email address is already in use by another user.";
        } else {
            // Check ID Number uniqueness if provided
            $id_conflict = false;
            if (!empty($id_number)) {
                $chk_id = $pdo->prepare("SELECT id FROM users WHERE id_number = ? AND id != ? LIMIT 1");
                $chk_id->execute([$id_number, $edit_id]);
                $id_conflict = (bool)$chk_id->fetch();
            }

            if ($id_conflict) {
                $error_msg = "That ID / Employee Number is already assigned to another user.";
            } else {
                $upd = $pdo->prepare("UPDATE users SET 
                    id_number = ?, 
                    full_name = ?, 
                    email = ?, 
                    role = ?, 
                    department = ?, 
                    course = ?, 
                    status = ? 
                    WHERE id = ?");
                $course_val = ($role === 'student' ? $department : null);
                $upd->execute([
                    $id_number,
                    $full_name,
                    $email,
                    $role,
                    $department,
                    $course_val,
                    $status,
                    $edit_id
                ]);

                log_audit_action($pdo, $user_name, 'ADMIN', "Updated user account ID {$edit_id}: {$full_name} ({$role})");
                $success_msg = "User account for <strong>" . htmlspecialchars($full_name) . "</strong> has been successfully updated in real time!";
            }
        }
    }
}

// 4. Handle Manually Adding New User (Admin Feature)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_user') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $id_number = trim($_POST['id_number'] ?? '');
    $role = trim(strtolower($_POST['role'] ?? 'student'));
    $department = trim($_POST['department'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $year_level = trim($_POST['year_level'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $status = trim($_POST['status'] ?? 'Active');

    $allowed_roles = ['student', 'teacher', 'registrar', 'admin'];
    $allowed_statuses = ['Active', 'Pending', 'Inactive', 'Dropped'];

    if (empty($full_name) || empty($email) || empty($password)) {
        $error_msg = "Please provide Full Name, Email Address, and Initial Password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = "Please provide a valid email address.";
    } elseif (!in_array($role, $allowed_roles)) {
        $error_msg = "Invalid role selected.";
    } elseif (!in_array($status, $allowed_statuses)) {
        $error_msg = "Invalid account status.";
    } else {
        // Check email uniqueness
        $chk_email = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $chk_email->execute([$email]);
        if ($chk_email->fetch()) {
            $error_msg = "That email address is already in use by another account.";
        } else {
            // Check ID Number uniqueness if provided
            $id_conflict = false;
            if (!empty($id_number)) {
                $chk_id = $pdo->prepare("SELECT id FROM users WHERE id_number = ? LIMIT 1");
                $chk_id->execute([$id_number]);
                $id_conflict = (bool)$chk_id->fetch();
            }

            if ($id_conflict) {
                $error_msg = "That ID / Employee Number is already assigned to another account.";
            } else {
                // Formulate clean username from email prefix
                $email_prefix = explode('@', $email)[0];
                $clean_username = preg_replace('/[^a-zA-Z0-9._-]/', '', strtolower($email_prefix));
                if (empty($clean_username)) {
                    $clean_username = 'user' . rand(100, 999);
                }
                $chk_u = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $chk_u->execute([$clean_username]);
                if ($chk_u->fetch()) {
                    $clean_username .= rand(10, 99);
                }

                $course_val = ($role === 'student' ? $department : null);

                $ins = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, id_number, department, section, year_level, course, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([
                    $clean_username,
                    $password,
                    $full_name,
                    $email,
                    $role,
                    $id_number,
                    $department,
                    $section,
                    $year_level,
                    $course_val,
                    $status
                ]);

                log_audit_action($pdo, $user_name, 'ADMIN', "Manually created new {$role} account: {$full_name} ({$email})");
                $success_msg = "New " . ucfirst($role) . " account for <strong>" . htmlspecialchars($full_name) . "</strong> has been created successfully with status <strong>" . htmlspecialchars($status) . "</strong>!";
            }
        }
    }
}

// 5. Handle Bulk Upload of Users (CSV file or Direct Paste)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bulk_upload') {
    $default_role = trim(strtolower($_POST['default_role'] ?? 'student'));
    $default_status = trim($_POST['default_status'] ?? 'Active');
    $raw_input = '';

    if (!in_array($default_role, ['student', 'teacher', 'registrar'])) {
        $default_role = 'student';
    }
    if (!in_array($default_status, ['Active', 'Pending'])) {
        $default_status = 'Active';
    }

    // Check if file was uploaded
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['csv_file']['tmp_name'];
        $file_name = $_FILES['csv_file']['name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt'])) {
            $error_msg = "Invalid file format. Please upload a valid .csv file.";
        } else {
            $raw_input = @file_get_contents($file_tmp);
        }
    } else {
        $error_msg = "Please select a valid CSV spreadsheet file (.csv) to upload.";
    }

    if (empty($error_msg) && !empty($raw_input)) {
        require_once __DIR__ . '/includes/bulk_user_parser.php';
        $parsed_users = parse_bulk_users_input($raw_input, $default_role, $default_status);

        if (empty($parsed_users)) {
            $error_msg = "No valid user records could be recognized. Please make sure required columns (such as Full Name, Email, and LRN / ID) are included.";
        } else {
            $inserted_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            $error_details = [];

            $stmt_ins = $pdo->prepare("INSERT INTO users 
                (username, password, full_name, email, role, id_number, department, section, year_level, course, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    password = VALUES(password),
                    full_name = VALUES(full_name),
                    role = VALUES(role),
                    id_number = VALUES(id_number),
                    department = VALUES(department),
                    section = VALUES(section),
                    year_level = VALUES(year_level),
                    course = VALUES(course),
                    status = VALUES(status)");

            foreach ($parsed_users as $idx => $u) {
                try {
                    // Prevent username collision with another user's email
                    $username = $u['username'];
                    $chk_u = $pdo->prepare("SELECT id, email FROM users WHERE username = ? LIMIT 1");
                    $chk_u->execute([$username]);
                    $found_user = $chk_u->fetch();
                    if ($found_user && strtolower($found_user['email']) !== strtolower($u['email'])) {
                        $username = $username . rand(10, 999);
                    }

                    $stmt_ins->execute([
                        $username,
                        $u['password'],
                        $u['full_name'],
                        $u['email'],
                        $u['role'],
                        $u['id_number'],
                        $u['department'],
                        $u['section'],
                        $u['year_level'],
                        $u['course'],
                        $u['status']
                    ]);

                    $rc = $stmt_ins->rowCount();
                    if ($rc === 1) {
                        $inserted_count++;
                    } else {
                        // rowCount is 2 on update or 0 if row unchanged
                        $updated_count++;
                    }
                } catch (Exception $e) {
                    $skipped_count++;
                    $error_details[] = "Row #" . ($idx + 1) . " (" . htmlspecialchars($u['full_name']) . "): " . $e->getMessage();
                }
            }

            $total_processed = count($parsed_users);
            $total_saved = $inserted_count + $updated_count;
            log_audit_action($pdo, $user_name, 'ADMIN', "Bulk user import: {$total_saved} processed ({$inserted_count} new, {$updated_count} updated)");

            $bulk_summary = [
                'total' => $total_processed,
                'inserted' => $inserted_count,
                'updated' => $updated_count,
                'skipped' => $skipped_count,
                'errors' => $error_details
            ];

            $success_msg = "Bulk user upload completed successfully! Processed <strong>{$total_processed}</strong> records: <strong>{$inserted_count}</strong> newly created and <strong>{$updated_count}</strong> updated/synced into the database.";
        }
    }
}

// Fetch users with search, role filter, and pagination (25 per page)
$search = trim($_GET['search'] ?? '');
$role_filter = trim($_GET['role_filter'] ?? 'ALL');

$where_clauses = " WHERE 1=1";
$params = [];

if ($search !== '') {
    $where_clauses .= " AND (full_name LIKE ? OR email LIKE ? OR id_number LIKE ? OR department LIKE ?)";
    $term = "%{$search}%";
    $params = array_merge($params, [$term, $term, $term, $term]);
}

if ($role_filter !== '' && $role_filter !== 'ALL') {
    $where_clauses .= " AND role = ?";
    $params[] = $role_filter;
}

// 1. Count total matching users
$count_query = "SELECT COUNT(*) FROM users" . $where_clauses;
$stmt_count = $pdo->prepare($count_query);
$stmt_count->execute($params);
$total_matching_users = (int)$stmt_count->fetchColumn();

// 2. Pagination variables (25 users per page)
$per_page = 25;
$total_pages = max(1, (int)ceil($total_matching_users / $per_page));
$current_page = max(1, min($total_pages, (int)($_GET['page'] ?? 1)));
$offset = ($current_page - 1) * $per_page;

$from_record = ($total_matching_users > 0) ? ($offset + 1) : 0;
$to_record = min($offset + $per_page, $total_matching_users);

// 3. Fetch sliced page of users
$query = "SELECT * FROM users" . $where_clauses . " ORDER BY FIELD(status, 'Pending', 'Active', 'Inactive', 'Dropped'), role ASC, full_name ASC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$all_users = $stmt->fetchAll();
?>

<div class="content-card">
  <?php if (!empty($bulk_summary)): ?>
    <div style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%); border: 1.5px solid #86efac; border-radius: 14px; padding: 20px; margin-bottom: 24px; box-shadow: 0 4px 15px rgba(22, 163, 74, 0.08);">
      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 14px;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <span style="font-size: 1.8rem;">🎉</span>
          <div>
            <h3 style="margin: 0; color: #166534; font-size: 1.15rem; font-weight: 800;">Bulk Import Operation Complete!</h3>
            <p style="margin: 2px 0 0 0; color: #15803d; font-size: 0.88rem;">All records have been synchronized and successfully saved into the MySQL database.</p>
          </div>
        </div>
        <span style="background: #16a34a; color: white; padding: 5px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 700;">Live Synced to DB</span>
      </div>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px;">
        <div style="background: white; border-radius: 10px; padding: 12px; border: 1px solid #bbf7d0; text-align: center;">
          <div style="font-size: 1.5rem; font-weight: 800; color: #0f172a;"><?= $bulk_summary['total'] ?></div>
          <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Total Processed</div>
        </div>
        <div style="background: white; border-radius: 10px; padding: 12px; border: 1px solid #bbf7d0; text-align: center;">
          <div style="font-size: 1.5rem; font-weight: 800; color: #16a34a;"><?= $bulk_summary['inserted'] ?></div>
          <div style="font-size: 0.75rem; color: #15803d; font-weight: 700; text-transform: uppercase;">New Created</div>
        </div>
        <div style="background: white; border-radius: 10px; padding: 12px; border: 1px solid #bbf7d0; text-align: center;">
          <div style="font-size: 1.5rem; font-weight: 800; color: #0284c7;"><?= $bulk_summary['updated'] ?></div>
          <div style="font-size: 0.75rem; color: #0369a1; font-weight: 700; text-transform: uppercase;">Updated / Synced</div>
        </div>
        <?php if ($bulk_summary['skipped'] > 0): ?>
          <div style="background: white; border-radius: 10px; padding: 12px; border: 1px solid #fecaca; text-align: center;">
            <div style="font-size: 1.5rem; font-weight: 800; color: #dc2626;"><?= $bulk_summary['skipped'] ?></div>
            <div style="font-size: 0.75rem; color: #b91c1c; font-weight: 700; text-transform: uppercase;">Skipped / Warnings</div>
          </div>
        <?php endif; ?>
      </div>
      <?php if (!empty($bulk_summary['errors'])): ?>
        <div style="margin-top: 14px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 10px 14px; font-size: 0.82rem; color: #be123c;">
          <strong>Warnings on skipped rows:</strong>
          <ul style="margin: 4px 0 0 16px; padding: 0;">
            <?php foreach (array_slice($bulk_summary['errors'], 0, 5) as $err): ?>
              <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  <?php elseif (!empty($success_msg)): ?>
    <div class="alert-success-banner" style="margin-bottom: 20px;">
      <div style="font-weight: 800; font-size: 1rem; margin-bottom: 4px;">✅ Operation Successful!</div>
      <div><?= $success_msg ?></div>
    </div>
  <?php endif; ?>

  <?php if (!empty($error_msg)): ?>
    <div style="background: #fee2e2; border: 1.5px solid #fca5a5; color: #b91c1c; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 0.92rem;">
      <strong>⚠️ Error:</strong> <?= htmlspecialchars($error_msg) ?>
    </div>
  <?php endif; ?>

  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
    <div>
      <h2 class="card-title" style="margin-bottom: 4px;">Authorized System Users</h2>
      <p style="font-size: 0.9rem; color: #64748b; margin: 0;">
        Registered student, teacher, registrar, and administrator accounts in the institution.
      </p>
    </div>
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
      <span style="background: #0284c7; color: white; padding: 7px 15px; border-radius: 20px; font-size: 0.82rem; font-weight: 700;">
        Total: <?= number_format($total_matching_users) ?> Users
      </span>
      <button type="button" class="btn-primary-cta" onclick="openBulkUploadModal()" style="background: #0284c7; padding: 8px 18px; font-size: 0.86rem; display: inline-flex; align-items: center; gap: 7px; border: none; cursor: pointer; border-radius: 9px; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);">
        <span style="font-size: 1rem;">📁</span> <span>Bulk Upload</span>
      </button>
      <button type="button" class="btn-primary-cta" onclick="openAddUserModal()" style="background: #16a34a; padding: 8px 18px; font-size: 0.86rem; display: inline-flex; align-items: center; gap: 7px; border: none; cursor: pointer; border-radius: 9px; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);">
        <span style="font-size: 1rem;">➕</span> <span>Add User</span>
      </button>
    </div>
  </div>

  <!-- Search & Filter Controls -->
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 12px; flex-wrap: wrap;">
    <form method="GET" style="display: flex; gap: 10px; flex-grow: 1; max-width: 480px;">
      <input 
        type="text" 
        name="search" 
        class="form-control" 
        placeholder="Search name, ID number, email, or department..." 
        value="<?= htmlspecialchars($search) ?>"
      />
      <button type="submit" class="btn-primary-cta" style="padding: 10px 18px; font-size: 0.85rem;">Search</button>
      <?php if ($search !== '' || $role_filter !== 'ALL'): ?>
        <a href="users.php" class="btn-primary-cta" style="background: #e2e8f0; color: #475569; padding: 10px 14px; font-size: 0.85rem; text-decoration: none;">Reset</a>
      <?php endif; ?>
    </form>

    <form method="GET">
      <?php if ($search !== ''): ?>
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
      <?php endif; ?>
      <select name="role_filter" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="ALL">All Roles</option>
        <option value="student" <?= $role_filter === 'student' ? 'selected' : '' ?>>Students</option>
        <option value="teacher" <?= $role_filter === 'teacher' ? 'selected' : '' ?>>Teachers</option>
        <option value="registrar" <?= $role_filter === 'registrar' ? 'selected' : '' ?>>Registrars</option>
        <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Administrators</option>
      </select>
    </form>
  </div>

  <div class="table-container">
    <table class="custom-table">
      <thead>
        <tr>
          <th>ID Number</th>
          <th>Full Name</th>
          <th>Email Address</th>
          <th>Role</th>
          <th>Department / Program</th>
          <th>Account Status</th>
          <th style="text-align: right; min-width: 190px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($all_users)): ?>
          <tr>
            <td colspan="7" style="text-align: center; color: #94a3b8; padding: 30px;">
              No users matching the search criteria.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($all_users as $u): 
            $badge_color = $u['role'] === 'admin' ? 'ready' : ($u['role'] === 'registrar' ? 'ready-for-release' : ($u['role'] === 'teacher' ? 'processing' : 'pending'));
            $user_payload = [
              'id' => $u['id'],
              'id_number' => $u['id_number'] ?? '',
              'full_name' => $u['full_name'],
              'email' => $u['email'],
              'role' => $u['role'],
              'department' => $u['department'] ?? '',
              'status' => $u['status']
            ];
          ?>
            <tr>
              <td><code><?= htmlspecialchars($u['id_number'] ?: '—') ?></code></td>
              <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
              <td><?= htmlspecialchars($u['email']) ?></td>
              <td><span class="badge-status <?= $badge_color ?>"><?= strtoupper($u['role']) ?></span></td>
              <td><?= htmlspecialchars($u['department'] ?: '—') ?></td>
              <td>
                <?php if ($u['status'] === 'Pending'): ?>
                  <span class="badge-status" style="background: #fef3c7; color: #b45309; font-weight: 700;">Pending Approval</span>
                <?php elseif ($u['status'] === 'Active'): ?>
                  <span style="color: #16a34a; font-weight: 700;">Active</span>
                <?php else: ?>
                  <span style="color: #94a3b8; font-weight: 600;"><?= htmlspecialchars($u['status']) ?></span>
                <?php endif; ?>
              </td>
              <td style="text-align: right; white-space: nowrap;">
                <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end;">
                  <?php if ($u['status'] === 'Pending'): ?>
                    <a href="users.php?action=approve&id=<?= $u['id'] ?>" class="btn-user-action approve" title="Approve and activate this account" onclick="return confirm('Approve and activate this account?');">
                      ✔ Approve
                    </a>
                  <?php endif; ?>

                  <!-- Edit Button -->
                  <button type="button" class="btn-user-action edit" onclick="openEditUserModal(<?= htmlspecialchars(json_encode($user_payload), ENT_QUOTES) ?>)" title="Edit user profile">
                    ✏️ Edit
                  </button>

                  <!-- Delete Button -->
                  <?php if ((int)$u['id'] !== (int)$user_id): ?>
                    <form method="POST" action="users.php" style="display: inline; margin: 0;" onsubmit="return confirm('⚠️ WARNING: Are you sure you want to permanently delete <?= htmlspecialchars(addslashes($u['full_name']), ENT_QUOTES) ?> (<?= ucfirst($u['role']) ?>)?\n\nThis will remove their account and ALL associated requests, uploaded documents, and notifications from the system. This cannot be undone.');">
                      <input type="hidden" name="action" value="delete_user">
                      <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                      <button type="submit" class="btn-user-action delete" title="Permanently delete user and records">
                        🗑️ Delete
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="current-user-tag" title="Your currently logged-in account">You</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination Controls (25 users per page) -->
  <?php if ($total_matching_users > 0): ?>
    <div class="table-pagination-footer">
      <div class="pagination-info">
        Showing <strong><?= $from_record ?></strong> &ndash; <strong><?= $to_record ?></strong> of <strong><?= number_format($total_matching_users) ?></strong> users
        <?php if ($total_pages > 1): ?>
          <span style="color: #94a3b8; margin-left: 6px;">(Page <?= $current_page ?> of <?= $total_pages ?>)</span>
        <?php endif; ?>
      </div>

      <?php if ($total_pages > 1): ?>
        <?php
          function build_user_page_url($p, $s, $r) {
            $params = ['page' => $p];
            if ($s !== '') $params['search'] = $s;
            if ($r !== '' && $r !== 'ALL') $params['role_filter'] = $r;
            return 'users.php?' . http_build_query($params);
          }

          $window = 2;
          $start_page = max(1, $current_page - $window);
          $end_page = min($total_pages, $current_page + $window);
        ?>
        <div class="pagination-controls">
          <!-- First & Previous -->
          <?php if ($current_page > 1): ?>
            <a href="<?= build_user_page_url(1, $search, $role_filter) ?>" class="pagination-btn" title="First Page">&laquo;</a>
            <a href="<?= build_user_page_url($current_page - 1, $search, $role_filter) ?>" class="pagination-btn" title="Previous Page">&lsaquo; Prev</a>
          <?php else: ?>
            <span class="pagination-btn disabled">&laquo;</span>
            <span class="pagination-btn disabled">&lsaquo; Prev</span>
          <?php endif; ?>

          <!-- First Page Jump if outside window -->
          <?php if ($start_page > 1): ?>
            <a href="<?= build_user_page_url(1, $search, $role_filter) ?>" class="pagination-btn">1</a>
            <?php if ($start_page > 2): ?>
              <span class="pagination-ellipsis">&hellip;</span>
            <?php endif; ?>
          <?php endif; ?>

          <!-- Window Page Numbers -->
          <?php for ($p = $start_page; $p <= $end_page; $p++): ?>
            <?php if ($p === $current_page): ?>
              <span class="pagination-btn active"><?= $p ?></span>
            <?php else: ?>
              <a href="<?= build_user_page_url($p, $search, $role_filter) ?>" class="pagination-btn"><?= $p ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <!-- Last Page Jump if outside window -->
          <?php if ($end_page < $total_pages): ?>
            <?php if ($end_page < $total_pages - 1): ?>
              <span class="pagination-ellipsis">&hellip;</span>
            <?php endif; ?>
            <a href="<?= build_user_page_url($total_pages, $search, $role_filter) ?>" class="pagination-btn"><?= $total_pages ?></a>
          <?php endif; ?>

          <!-- Next & Last -->
          <?php if ($current_page < $total_pages): ?>
            <a href="<?= build_user_page_url($current_page + 1, $search, $role_filter) ?>" class="pagination-btn" title="Next Page">Next &rsaquo;</a>
            <a href="<?= build_user_page_url($total_pages, $search, $role_filter) ?>" class="pagination-btn" title="Last Page">&raquo;</a>
          <?php else: ?>
            <span class="pagination-btn disabled">Next &rsaquo;</span>
            <span class="pagination-btn disabled">&raquo;</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ============================================================================== -->
<!-- EDIT USER MODAL (ADMIN ONLY)                                                   -->
<!-- ============================================================================== -->
<div id="editUserModal" class="user-modal-overlay">
  <div class="user-modal-card">
    <div class="user-modal-header">
      <div style="display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 1.4rem;">✏️</span>
        <div>
          <h3 style="margin: 0; font-size: 1.2rem; color: #0f172a; font-weight: 800;">Edit User Account</h3>
          <p style="margin: 2px 0 0 0; font-size: 0.82rem; color: #64748b;">Update account details in real-time</p>
        </div>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeEditUserModal()">&times;</button>
    </div>

    <form method="POST" action="users.php">
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="edit_id" id="modal_edit_id">

      <div class="user-modal-body">
        <div class="form-group" style="margin-bottom: 14px;">
          <label class="form-label">ID / Employee Number</label>
          <input type="text" name="id_number" id="modal_id_number" class="form-control" placeholder="e.g. 2024-0012 or EMP-102">
        </div>

        <div class="form-group" style="margin-bottom: 14px;">
          <label class="form-label">Full Name <span style="color: #ef4444;">*</span></label>
          <input type="text" name="full_name" id="modal_full_name" class="form-control" required placeholder="e.g. Juan Dela Cruz">
        </div>

        <div class="form-group" style="margin-bottom: 14px;">
          <label class="form-label">Email Address <span style="color: #ef4444;">*</span></label>
          <input type="email" name="email" id="modal_email" class="form-control" required placeholder="e.g. name@school.edu.ph">
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
          <div class="form-group">
            <label class="form-label">Role <span style="color: #ef4444;">*</span></label>
            <select name="role" id="modal_role" class="form-control" required>
              <option value="student">Student</option>
              <option value="teacher">Teacher</option>
              <option value="registrar">Registrar</option>
              <option value="admin">Administrator</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Account Status <span style="color: #ef4444;">*</span></label>
            <select name="status" id="modal_status" class="form-control" required>
              <option value="Active">Active</option>
              <option value="Pending">Pending Approval</option>
              <option value="Inactive">Inactive</option>
              <option value="Dropped">Dropped</option>
            </select>
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 18px;">
          <label class="form-label">Department / Program / Grade Level</label>
          <input type="text" name="department" id="modal_department" class="form-control" placeholder="e.g. BS Information Technology, Junior High School, etc.">
        </div>
      </div>

      <div class="user-modal-footer">
        <button type="button" class="btn-primary-cta" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; box-shadow: none;" onclick="closeEditUserModal()">Cancel</button>
        <button type="submit" class="btn-primary-cta" style="background: #0284c7;">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================================== -->
<!-- ADD USER MODAL (ADMIN ONLY)                                                    -->
<!-- ============================================================================== -->
<div id="addUserModal" class="user-modal-overlay">
  <div class="user-modal-card">
    <div class="user-modal-header">
      <div style="display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 1.4rem;">➕</span>
        <div>
          <h3 style="margin: 0; font-size: 1.2rem; color: #0f172a; font-weight: 800;">Add New User Account</h3>
          <p style="margin: 2px 0 0 0; font-size: 0.82rem; color: #64748b;">Create authorized student, teacher, registrar, or admin profile</p>
        </div>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeAddUserModal()">&times;</button>
    </div>

    <form method="POST" action="users.php" autocomplete="off">
      <input type="hidden" name="action" value="add_user">

      <div class="user-modal-body">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
          <div class="form-group">
            <label class="form-label">Account Role <span style="color: #ef4444;">*</span></label>
            <select name="role" id="add_modal_role" class="form-control" required onchange="handleRoleChangeAddUser(this.value)">
              <option value="student" selected>Student</option>
              <option value="teacher">Teacher</option>
              <option value="registrar">Registrar</option>
              <option value="admin">Administrator</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Initial Status <span style="color: #ef4444;">*</span></label>
            <select name="status" class="form-control" required>
              <option value="Active" selected>Active (Can login immediately)</option>
              <option value="Pending">Pending Approval</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 14px;">
          <label class="form-label" id="add_modal_id_label">ID / LRN / Employee Number</label>
          <input type="text" name="id_number" id="add_modal_id_number" class="form-control" placeholder="e.g. 654651 (Student LRN / ID)">
        </div>

        <div class="form-group" style="margin-bottom: 14px;">
          <label class="form-label">Full Name <span style="color: #ef4444;">*</span></label>
          <input type="text" name="full_name" class="form-control" required placeholder="e.g. Maria Clara S. Santos">
        </div>

        <div class="form-group" style="margin-bottom: 14px;">
          <label class="form-label">Email Address <span style="color: #ef4444;">*</span></label>
          <input type="email" name="email" class="form-control" required placeholder="e.g. user@gmail.com or user@school.edu.ph">
        </div>

        <div class="form-group" style="margin-bottom: 14px;">
          <label class="form-label" id="add_modal_dept_label">Program / Academic Strand</label>
          <input type="text" name="department" id="add_modal_department" class="form-control" placeholder="e.g. STEM Strand, Junior High School (General)">
        </div>

        <!-- Student-Specific Fields: Year Level & Section -->
        <div id="add_modal_student_fields" style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
          <div class="form-group">
            <label class="form-label">Year Level</label>
            <input type="text" name="year_level" id="add_modal_year_level" class="form-control" placeholder="e.g. Grade 7, Grade 11">
          </div>
          <div class="form-group">
            <label class="form-label">Section</label>
            <input type="text" name="section" id="add_modal_section" class="form-control" placeholder="e.g. 7-Pascal, 11-Curie">
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 14px;">
          <label class="form-label">Initial Password <span style="color: #ef4444;">*</span></label>
          <input type="text" name="password" class="form-control" required value="TagSci@2026" placeholder="e.g. TagSci@2026">
          <small style="color: #64748b; font-size: 0.76rem; display: block; margin-top: 4px;">
            Default initial password. The user can log in with this password right away.
          </small>
        </div>
      </div>

      <div class="user-modal-footer">
        <button type="button" class="btn-primary-cta" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; box-shadow: none;" onclick="closeAddUserModal()">Cancel</button>
        <button type="submit" class="btn-primary-cta" style="background: #16a34a; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);">➕ Create Account</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================================== -->
<!-- BULK UPLOAD MODAL (ADMIN ONLY)                                                 -->
<!-- ============================================================================== -->
<div id="bulkUploadModal" class="user-modal-overlay">
  <div class="user-modal-card" style="max-width: 660px;">
    <div class="user-modal-header">
      <div style="display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 1.4rem;">📁</span>
        <div>
          <h3 style="margin: 0; font-size: 1.2rem; color: #0f172a; font-weight: 800;">Bulk Upload User Accounts</h3>
          <p style="margin: 2px 0 0 0; font-size: 0.82rem; color: #64748b;">Batch import student or teacher accounts directly into the database</p>
        </div>
      </div>
      <button type="button" class="modal-close-btn" onclick="closeBulkUploadModal()">&times;</button>
    </div>

    <form method="POST" action="users.php" enctype="multipart/form-data">
      <input type="hidden" name="action" value="bulk_upload">

      <div class="user-modal-body">
        <!-- Download Starter Template Box -->
        <div style="background: #f0fdf4; border: 1.5px dashed #86efac; border-radius: 12px; padding: 14px 16px; margin-bottom: 18px;">
          <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
            <div style="font-weight: 700; font-size: 0.9rem; color: #166534; display: flex; align-items: center; gap: 6px;">
              <span>📥</span> <span>Download Official CSV Templates</span>
            </div>
            <span style="font-size: 0.75rem; color: #15803d; background: #dcfce7; padding: 2px 8px; border-radius: 6px; font-weight: 600;">Excel / Sheets Ready</span>
          </div>
          <p style="font-size: 0.82rem; color: #15803d; margin: 0 0 10px 0; line-height: 1.4;">
            Fill in student or teacher records using our pre-formatted spreadsheet template. You can upload batches out-of-order or by section.
          </p>
          <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="download_template.php?type=students" download="students_template.csv" class="btn-primary-cta" style="background: #16a34a; font-size: 0.82rem; padding: 7px 14px; text-decoration: none; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px;">
              <span>🎓</span> <span>Download Student Template (.csv)</span>
            </a>
            <a href="download_template.php?type=teachers" download="teachers_template.csv" class="btn-primary-cta" style="background: #0284c7; font-size: 0.82rem; padding: 7px 14px; text-decoration: none; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px;">
              <span>👨‍🏫</span> <span>Download Teacher Template (.csv)</span>
            </a>
          </div>
        </div>

        <!-- Target Role & Default Status -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
          <div class="form-group">
            <label class="form-label">Default Account Role <span style="color: #ef4444;">*</span></label>
            <select name="default_role" class="form-control" required>
              <option value="student" selected>Student (TagSci Students)</option>
              <option value="teacher">Teacher (Faculty Members)</option>
              <option value="registrar">Registrar Personnel</option>
            </select>
            <small style="color: #64748b; font-size: 0.74rem;">If a role column exists in the CSV, it will take precedence.</small>
          </div>

          <div class="form-group">
            <label class="form-label">Initial Account Status <span style="color: #ef4444;">*</span></label>
            <select name="default_status" class="form-control" required>
              <option value="Active" selected>Active (Can login immediately)</option>
              <option value="Pending">Pending Approval</option>
            </select>
            <small style="color: #64748b; font-size: 0.74rem;">Active accounts do not need approval.</small>
          </div>
        </div>

        <!-- File Upload Section -->
        <div style="background: #f8fafc; border: 2px dashed #93c5fd; border-radius: 12px; padding: 18px; margin-bottom: 16px; text-align: center;">
          <div style="font-size: 2rem; margin-bottom: 6px;">📂</div>
          <label class="form-label" style="font-size: 0.95rem; font-weight: 700; color: #0f172a; margin-bottom: 4px; display: block;">Select Prepared CSV File (.csv) <span style="color: #ef4444;">*</span></label>
          <p style="font-size: 0.8rem; color: #64748b; margin: 0 0 12px 0;">
            Choose the filled-out template file saved from Microsoft Excel, Google Sheets, or WPS Office.
          </p>
          <input type="file" name="csv_file" id="bulk_csv_file" accept=".csv, .txt" required class="form-control" style="max-width: 380px; margin: 0 auto; padding: 10px; background: #ffffff; border: 1.5px solid #cbd5e1; cursor: pointer;">
        </div>

        <!-- Column Rules & Security Notice -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; font-size: 0.8rem; color: #475569;">
          <div style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">💡 Automatic Field Intelligence:</div>
          <ul style="margin: 0 0 0 16px; padding: 0; line-height: 1.5;">
            <li><strong>Password:</strong> If left blank, automatically generated as <code>&lt;LRN&gt;@&lt;LastName&gt;</code> (e.g. <code>425818190003@Ado</code>), which meets all password rules.</li>
            <li><strong>Username:</strong> If left blank, automatically derived from email prefix or <code>firstname.lastname</code>.</li>
            <li><strong>Safe Updates:</strong> If an account with the same email already exists, its details (section, year level, etc.) will be cleanly updated without duplicate errors.</li>
          </ul>
        </div>
      </div>

      <div class="user-modal-footer">
        <button type="button" class="btn-primary-cta" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; box-shadow: none;" onclick="closeBulkUploadModal()">Cancel</button>
        <button type="submit" class="btn-primary-cta" style="background: #0284c7; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);">🚀 Start Bulk Import</button>
      </div>
    </form>
  </div>
</div>

<script>
function openBulkUploadModal() {
  const modal = document.getElementById('bulkUploadModal');
  if (modal) {
    modal.classList.add('active');
  }
}

function closeBulkUploadModal() {
  const modal = document.getElementById('bulkUploadModal');
  if (modal) {
    modal.classList.remove('active');
  }
}

function openAddUserModal() {
  const modal = document.getElementById('addUserModal');
  if (modal) {
    modal.classList.add('active');
  }
}

function closeAddUserModal() {
  const modal = document.getElementById('addUserModal');
  if (modal) {
    modal.classList.remove('active');
  }
}

function handleRoleChangeAddUser(role) {
  const studentFields = document.getElementById('add_modal_student_fields');
  const deptLabel = document.getElementById('add_modal_dept_label');
  const deptInput = document.getElementById('add_modal_department');
  const idLabel = document.getElementById('add_modal_id_label');
  const idInput = document.getElementById('add_modal_id_number');

  if (role === 'student') {
    if (studentFields) studentFields.style.display = 'grid';
    if (deptLabel) deptLabel.textContent = 'Program / Academic Strand';
    if (deptInput) { deptInput.value = ''; deptInput.placeholder = 'e.g. STEM Strand, Junior High School (General)'; }
    if (idLabel) idLabel.textContent = 'Student LRN / ID Number';
    if (idInput) idInput.placeholder = 'e.g. 654651';
  } else if (role === 'teacher') {
    if (studentFields) studentFields.style.display = 'none';
    if (deptLabel) deptLabel.textContent = 'Grade Level / Department';
    if (deptInput) { deptInput.value = ''; deptInput.placeholder = 'e.g. Junior High School, Senior High School'; }
    if (idLabel) idLabel.textContent = 'Teacher Employee ID Number';
    if (idInput) idInput.placeholder = 'e.g. EMP-2026-001';
  } else if (role === 'registrar') {
    if (studentFields) studentFields.style.display = 'none';
    if (deptLabel) deptLabel.textContent = 'Office';
    if (deptInput) { deptInput.value = 'Office of the Registrar'; deptInput.placeholder = 'Office of the Registrar'; }
    if (idLabel) idLabel.textContent = 'Registrar Employee Number';
    if (idInput) idInput.placeholder = 'e.g. REG-001';
  } else if (role === 'admin') {
    if (studentFields) studentFields.style.display = 'none';
    if (deptLabel) deptLabel.textContent = 'Office';
    if (deptInput) { deptInput.value = 'Administration'; deptInput.placeholder = 'Administration'; }
    if (idLabel) idLabel.textContent = 'Admin Employee Number';
    if (idInput) idInput.placeholder = 'e.g. ADM-001';
  }
}

function openEditUserModal(user) {
  document.getElementById('modal_edit_id').value = user.id;
  document.getElementById('modal_id_number').value = user.id_number || '';
  document.getElementById('modal_full_name').value = user.full_name || '';
  document.getElementById('modal_email').value = user.email || '';
  document.getElementById('modal_role').value = user.role || 'student';
  document.getElementById('modal_status').value = user.status || 'Active';
  document.getElementById('modal_department').value = user.department || '';

  const modal = document.getElementById('editUserModal');
  modal.classList.add('active');
}

function closeEditUserModal() {
  const modal = document.getElementById('editUserModal');
  modal.classList.remove('active');
}

// Close on escape key
window.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeEditUserModal();
    closeAddUserModal();
    closeBulkUploadModal();
  }
});

// Close when clicking outside modal card
document.getElementById('editUserModal')?.addEventListener('click', (e) => {
  if (e.target.id === 'editUserModal') closeEditUserModal();
});
document.getElementById('addUserModal')?.addEventListener('click', (e) => {
  if (e.target.id === 'addUserModal') closeAddUserModal();
});
document.getElementById('bulkUploadModal')?.addEventListener('click', (e) => {
  if (e.target.id === 'bulkUploadModal') closeBulkUploadModal();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
