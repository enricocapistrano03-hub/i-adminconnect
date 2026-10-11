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
                    $fn = $req['attached_filename'];
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

// Fetch all users with search filter
$search = trim($_GET['search'] ?? '');
$role_filter = trim($_GET['role_filter'] ?? 'ALL');

$query = "SELECT * FROM users WHERE 1=1";
$params = [];

if ($search !== '') {
    $query .= " AND (full_name LIKE ? OR email LIKE ? OR id_number LIKE ? OR department LIKE ?)";
    $term = "%{$search}%";
    $params = array_merge($params, [$term, $term, $term, $term]);
}

if ($role_filter !== '' && $role_filter !== 'ALL') {
    $query .= " AND role = ?";
    $params[] = $role_filter;
}

$query .= " ORDER BY FIELD(status, 'Pending', 'Active', 'Inactive', 'Dropped'), role ASC, full_name ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$all_users = $stmt->fetchAll();
?>

<div class="content-card">
  <?php if (!empty($success_msg)): ?>
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
    <div>
      <span style="background: #0284c7; color: white; padding: 6px 14px; border-radius: 20px; font-size: 0.82rem; font-weight: 700;">
        Total: <?= count($all_users) ?> Users
      </span>
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

<script>
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
  if (e.key === 'Escape') closeEditUserModal();
});

// Close when clicking outside modal card
document.getElementById('editUserModal')?.addEventListener('click', (e) => {
  if (e.target.id === 'editUserModal') closeEditUserModal();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
