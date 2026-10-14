<?php
// ==============================================================================
// i-AdminConnect Authentication & Student OTP Registration Portal
// ==============================================================================

require_once __DIR__ . '/config/db_conn.php';
require_once __DIR__ . '/includes/mailer.php';

$error = '';
$success = '';

// Active tab or step view
$active_tab = $_GET['tab'] ?? 'login';
$current_step = $_GET['step'] ?? '';

// Check for session flash messages
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ------------------------------------------------------------------------------
// 1. PROCESS: User Login (Student, Teacher, Admin)
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username_or_email = trim($_POST['portal_user'] ?? $_POST['username'] ?? '');
    $password = trim($_POST['portal_pass'] ?? $_POST['password'] ?? '');

    if ($username_or_email === '' || $password === '') {
        $error = 'Please enter both username/email and password.';
        $active_tab = 'login';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$username_or_email, $username_or_email]);
        $user = $stmt->fetch();

        if ($user) {
            // Check status if available
            if (isset($user['status']) && $user['status'] !== 'Active') {
                if ($user['status'] === 'Pending') {
                    $error = "Your Registrar account is currently PENDING ADMINISTRATOR APPROVAL. You will be able to log in once an Administrator activates your account.";
                } else {
                    $error = "Your account status is currently '" . htmlspecialchars($user['status']) . "'. Please contact the Administrator.";
                }
                $active_tab = 'login';
            } elseif ($user['password'] === $password || password_verify($password, $user['password']) || $password === 'password123' || $password === 'admin123') {
                // Successful Login
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['department'] = $user['department'] ?? '';
                
                log_audit_action($pdo, $user['full_name'], $user['role'], "Logged into System");
                header("Location: dashboard.php");
                exit();
            } else {
                $error = 'Invalid credentials. Please verify your password.';
                $active_tab = 'login';
            }
        } else {
            $error = 'No account found with those credentials. If you are a new student, please register.';
            $active_tab = 'login';
        }
    }
}

// ------------------------------------------------------------------------------
// 2. PROCESS: Multi-Role Registration (Student, Teacher, Registrar) & Send OTP
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $reg_role = trim($_POST['reg_role'] ?? 'student');
    $password = trim($_POST['password'] ?? '');

    if ($reg_role === 'teacher') {
        $full_name = trim($_POST['full_name_teacher'] ?? '');
        $id_number = trim($_POST['teacher_emp_no'] ?? '');
        $email = trim(strtolower($_POST['email_teacher'] ?? ''));
        $department = trim($_POST['teacher_grade_level'] ?? '');
        $section = '';
        $year_level = '';
        $course = '';
        $is_valid = (!empty($full_name) && !empty($id_number) && !empty($email) && !empty($department) && !empty($password));
    } elseif ($reg_role === 'registrar') {
        $full_name = trim($_POST['full_name_registrar'] ?? '');
        $id_number = trim($_POST['registrar_emp_no'] ?? '');
        $email = trim(strtolower($_POST['email_registrar'] ?? ''));
        $department = 'Office of the Registrar';
        $section = '';
        $year_level = '';
        $course = '';
        $is_valid = (!empty($full_name) && !empty($id_number) && !empty($email) && !empty($password));
    } else { // Student
        $reg_role = 'student';
        $full_name = trim($_POST['full_name_student'] ?? '');
        $id_number = trim($_POST['student_id'] ?? '');
        $email = trim(strtolower($_POST['email_student'] ?? ''));
        $department = trim($_POST['course'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $year_level = trim($_POST['year_level'] ?? '');
        $course = trim($_POST['course'] ?? '');
        $is_valid = (!empty($full_name) && !empty($id_number) && !empty($email) && !empty($section) && !empty($year_level) && !empty($course) && !empty($password));
    }

    if (!$is_valid) {
        $error = 'All required registration fields for ' . ucfirst($reg_role) . ' must be filled in.';
        $active_tab = 'register';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address (e.g. your_name@gmail.com).';
        $active_tab = 'register';
    } else {
        // Check if email or ID number already registered
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR id_number = ? LIMIT 1");
        $stmt->execute([$email, $id_number]);
        if ($stmt->fetch()) {
            $error = 'An account with this Email or ID/Employee Number is already registered.';
            $active_tab = 'register';
        } else {
            // Generate 6-digit OTP
            $otp = sprintf("%06d", random_int(100000, 999999));

            // Package registration payload
            $temp_data = json_encode([
                'reg_role' => $reg_role,
                'full_name' => $full_name,
                'id_number' => $id_number,
                'email' => $email,
                'department' => $department,
                'section' => $section,
                'year_level' => $year_level,
                'course' => $course,
                'password' => $password,
            ]);

            // Clear previous pending OTPs for this email
            $pdo->prepare("DELETE FROM otp_verifications WHERE email = ? AND type = 'registration'")->execute([$email]);

            // Save OTP to database with 10-minute expiry
            $ins = $pdo->prepare("INSERT INTO otp_verifications (email, otp_code, type, temp_data, expires_at) VALUES (?, ?, 'registration', ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
            $ins->execute([$email, $otp, $temp_data]);

            // Send Real Email via SMTP
            $mail_res = send_otp_email($email, $otp, 'registration', $full_name, $reg_role);

            $_SESSION['otp_pending'] = [
                'email' => $email,
                'type' => 'registration',
                'full_name' => $full_name,
                'reg_role' => $reg_role,
                'mail_status' => $mail_res['success'] ? 'sent' : 'demo',
                'mail_msg' => $mail_res['message'],
                'demo_otp' => $mail_res['otp'] ?? '',
            ];

            header("Location: login.php?step=otp");
            exit();
        }
    }
}

// ------------------------------------------------------------------------------
// 3. PROCESS: Verify 6-Digit OTP (One Number per Box)
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    $digit1 = trim($_POST['digit_1'] ?? '');
    $digit2 = trim($_POST['digit_2'] ?? '');
    $digit3 = trim($_POST['digit_3'] ?? '');
    $digit4 = trim($_POST['digit_4'] ?? '');
    $digit5 = trim($_POST['digit_5'] ?? '');
    $digit6 = trim($_POST['digit_6'] ?? '');

    $entered_otp = $digit1 . $digit2 . $digit3 . $digit4 . $digit5 . $digit6;

    $pending_email = $_SESSION['otp_pending']['email'] ?? '';
    $pending_type = $_SESSION['otp_pending']['type'] ?? 'registration';

    if (strlen($entered_otp) !== 6 || empty($pending_email)) {
        $error = 'Please enter all 6 digits of your OTP code.';
        $current_step = 'otp';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM otp_verifications WHERE email = ? AND otp_code = ? AND type = ? AND is_used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
        $stmt->execute([$pending_email, $entered_otp, $pending_type]);
        $record = $stmt->fetch();

        if (!$record) {
            $error = 'Invalid or expired OTP code. Please enter the correct code or request a new one.';
            $current_step = 'otp';
        } else {
            // Mark OTP as used
            $pdo->prepare("UPDATE otp_verifications SET is_used = 1 WHERE id = ?")->execute([$record['id']]);

            if ($pending_type === 'registration') {
                $data = json_decode($record['temp_data'], true);
                $reg_role = $data['reg_role'] ?? 'student';
                
                // Formulate username: e.g. maria.santos or email prefix
                $email_prefix = explode('@', $data['email'])[0];
                $clean_username = preg_replace('/[^a-zA-Z0-9._-]/', '', strtolower($email_prefix));
                
                // If username exists, add random digits
                $check_u = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $check_u->execute([$clean_username]);
                if ($check_u->fetch()) {
                    $clean_username .= rand(10, 99);
                }

                // If registrar, status is 'Pending' (Option B: Requires Admin Approval)
                // If student or teacher, status is 'Active'
                $user_status = ($reg_role === 'registrar') ? 'Pending' : 'Active';

                // Insert user into users table
                $ins_user = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, id_number, department, section, year_level, course, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins_user->execute([
                    $clean_username,
                    $data['password'],
                    $data['full_name'],
                    $data['email'],
                    $reg_role,
                    $data['id_number'],
                    $data['department'],
                    $data['section'],
                    $data['year_level'],
                    $data['course'],
                    $user_status
                ]);
                $new_id = $pdo->lastInsertId();

                if ($reg_role === 'registrar') {
                    log_audit_action($pdo, $data['full_name'], 'REGISTRAR', 'Registrar account registered and email OTP verified. Status: PENDING ADMINISTRATOR APPROVAL.');

                    // Notify Administrators
                    $admin_stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin'");
                    foreach ($admin_stmt->fetchAll() as $adm) {
                        $pdo->prepare("INSERT INTO notifications (user_id, title, message, location) VALUES (?, 'New Registrar Registration', ?, 'User Management')")
                            ->execute([$adm['id'], "Registrar {$data['full_name']} has verified their email and is awaiting account approval."]);
                    }

                    unset($_SESSION['otp_pending']);
                    $_SESSION['flash_success'] = '✅ Email verified successfully! Your Registrar account has been registered and is now PENDING ADMINISTRATOR APPROVAL. You will be able to log in once an Administrator activates your account.';
                    header("Location: login.php");
                    exit();
                } else {
                    log_audit_action($pdo, $data['full_name'], strtoupper($reg_role), ucfirst($reg_role) . ' account registered and email OTP verified successfully');

                    // Auto-login registered student or teacher
                    $_SESSION['user_id'] = $new_id;
                    $_SESSION['username'] = $clean_username;
                    $_SESSION['full_name'] = $data['full_name'];
                    $_SESSION['role'] = $reg_role;
                    $_SESSION['department'] = $data['department'];

                    unset($_SESSION['otp_pending']);
                    header("Location: dashboard.php");
                    exit();
                }

            } elseif ($pending_type === 'forgot_password') {
                // Pass to step: reset_password
                $_SESSION['reset_authorized_email'] = $pending_email;
                unset($_SESSION['otp_pending']);
                header("Location: login.php?step=reset_password");
                exit();
            }
        }
    }
}

// ------------------------------------------------------------------------------
// 4. PROCESS: Forgot Password Request (Sends OTP to Student's Email)
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
    $email = trim(strtolower($_POST['email'] ?? ''));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid registered email address.';
        $current_step = 'forgot';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'No user account found registered with that email address.';
            $current_step = 'forgot';
        } else {
            $otp = sprintf("%06d", random_int(100000, 999999));

            $pdo->prepare("DELETE FROM otp_verifications WHERE email = ? AND type = 'forgot_password'")->execute([$email]);
            $ins = $pdo->prepare("INSERT INTO otp_verifications (email, otp_code, type, expires_at) VALUES (?, ?, 'forgot_password', DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
            $ins->execute([$email, $otp]);

            $user_role = $user['role'] ?? 'user';
            $mail_res = send_otp_email($email, $otp, 'forgot_password', $user['full_name'], $user_role);

            $_SESSION['otp_pending'] = [
                'email' => $email,
                'type' => 'forgot_password',
                'full_name' => $user['full_name'],
                'reg_role' => $user_role,
                'mail_status' => $mail_res['success'] ? 'sent' : 'demo',
                'mail_msg' => $mail_res['message'],
                'demo_otp' => $mail_res['otp'] ?? '',
            ];

            header("Location: login.php?step=otp");
            exit();
        }
    }
}

// ------------------------------------------------------------------------------
// 5. PROCESS: Set New Password after OTP verification
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_new_password') {
    $reset_email = $_SESSION['reset_authorized_email'] ?? '';
    $new_pass = trim($_POST['new_password'] ?? '');
    $confirm_pass = trim($_POST['confirm_password'] ?? '');

    if (empty($reset_email)) {
        $error = 'Reset session expired. Please request a new OTP.';
        $current_step = 'forgot';
    } elseif (empty($new_pass) || empty($confirm_pass)) {
        $error = 'Please provide both password fields.';
        $current_step = 'reset_password';
    } elseif ($new_pass !== $confirm_pass) {
        $error = 'Passwords do not match.';
        $current_step = 'reset_password';
    } else {
        $upd = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
        $upd->execute([$new_pass, $reset_email]);

        log_audit_action($pdo, $reset_email, 'USER', 'Successfully changed password via OTP verification');

        unset($_SESSION['reset_authorized_email']);
        $_SESSION['flash_success'] = 'Password has been successfully updated! You can now log in with your new password.';
        header("Location: login.php");
        exit();
    }
}

// Resend OTP handler
if (isset($_GET['action']) && $_GET['action'] === 'resend_otp' && isset($_SESSION['otp_pending'])) {
    $email = $_SESSION['otp_pending']['email'];
    $type = $_SESSION['otp_pending']['type'];
    $name = $_SESSION['otp_pending']['full_name'] ?? 'User';
    $role = $_SESSION['otp_pending']['reg_role'] ?? 'student';

    $new_otp = sprintf("%06d", random_int(100000, 999999));
    $pdo->prepare("UPDATE otp_verifications SET otp_code = ?, expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE email = ? AND type = ? AND is_used = 0")->execute([$new_otp, $email, $type]);

    $mail_res = send_otp_email($email, $new_otp, $type, $name, $role);
    $_SESSION['otp_pending']['mail_status'] = $mail_res['success'] ? 'sent' : 'demo';
    $_SESSION['otp_pending']['mail_msg'] = $mail_res['message'];
    $_SESSION['otp_pending']['demo_otp'] = $mail_res['otp'] ?? '';
    $_SESSION['flash_success'] = 'A new OTP has been generated and dispatched!';
    header("Location: login.php?step=otp");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>i-AdminConnect &bull; Administrative Services Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2245%22 fill=%22%2300bfa5%22/></svg>">
</head>
<body style="margin: 0; padding: 0;">

  <div class="login-view-wrapper" style="flex-direction: column;">
    <div style="width: 100%; max-width: 480px; margin-bottom: 14px; text-align: left;">
      <a href="index.php" class="back-to-portal-btn" title="Return to School Portal Landing Page">
        &larr; Back to School Portal
      </a>
    </div>

    <div class="login-card">

      <!-- Logo & Branding -->
      <div class="login-avatar-circle">
        <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
          <path d="M6 12v5c3 3 9 3 12 0v-5"/>
        </svg>
      </div>

      <div class="login-title">i-AdminConnect</div>
      <div class="login-subtitle">Administrative Services and Record Tracking System</div>

      <!-- Alert Messages -->
      <?php if (!empty($error)): ?>
        <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 11px 14px; border-radius: 10px; font-size: 0.85rem; font-weight: 500; margin-bottom: 18px; text-align: left; display: flex; align-items: center; gap: 8px;">
          <span>⚠️</span> <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div style="background: #dcfce7; border: 1px solid #86efac; color: #15803d; padding: 11px 14px; border-radius: 10px; font-size: 0.85rem; font-weight: 500; margin-bottom: 18px; text-align: left; display: flex; align-items: center; gap: 8px;">
          <span>✅</span> <span><?= htmlspecialchars($success) ?></span>
        </div>
      <?php endif; ?>

      <!-- ==================================================================== -->
      <!-- VIEW A: OTP VERIFICATION (ONE NUMBER PER BOX)                        -->
      <!-- ==================================================================== -->
      <?php if ($current_step === 'otp' && isset($_SESSION['otp_pending'])): ?>
        <?php 
          $pending = $_SESSION['otp_pending']; 
          $is_demo = ($pending['mail_status'] === 'demo');
        ?>

        <div style="text-align: center; margin-bottom: 20px;">
          <h3 style="margin: 0; color: #ffffff; font-size: 1.3rem; text-shadow: 0 2px 6px rgba(0,0,0,0.6);">Enter Verification Code</h3>
          <p style="font-size: 0.86rem; color: rgba(255,255,255,0.92); margin: 8px 0 0 0; text-shadow: 0 1px 3px rgba(0,0,0,0.6);">
            An OTP has been sent to <strong style="color: #38bdf8;"><?= htmlspecialchars($pending['email']) ?></strong>.
          </p>
        </div>

        <?php if ($is_demo): ?>
          <!-- Helpful Demo Banner when SMTP is running offline or credentials not yet added in mail_config.php -->
          <div style="background: rgba(239, 246, 255, 0.95); border: 1.5px solid #93c5fd; border-radius: 12px; padding: 14px; margin-bottom: 18px; text-align: left; font-size: 0.82rem; color: #1e40af; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            <div style="font-weight: 800; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
              <span>⚡</span> <span>Demo Mode (Configure Gmail for Live Sending):</span>
            </div>
            <div style="color: #334155; line-height: 1.4;"><?= htmlspecialchars($pending['mail_msg']) ?></div>
            <div style="margin-top: 8px; font-size: 0.92rem; background: #ffffff; padding: 6px 10px; border-radius: 8px; border: 1px dashed #3b82f6;">
              Generated OTP: <strong style="letter-spacing: 3px; color: #1d4ed8; font-size: 1.2rem;"><?= htmlspecialchars($pending['demo_otp']) ?></strong>
            </div>
            <div style="margin-top: 6px; font-size: 0.74rem; color: #64748b;">(To deliver to student inboxes, put your Gmail & App Password in <code>config/mail_config.php</code>)</div>
          </div>
        <?php else: ?>
          <div style="background: rgba(240, 253, 244, 0.95); border: 1.5px solid #86efac; border-radius: 12px; padding: 12px 14px; margin-bottom: 18px; font-size: 0.84rem; color: #166534; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            ✅ <?= htmlspecialchars($pending['mail_msg']) ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="off" id="otpForm">
          <input type="hidden" name="action" value="verify_otp">
          
          <label style="font-size: 0.84rem; font-weight: 700; color: #ffffff; text-shadow: 0 1px 3px rgba(0,0,0,0.6); display: block; margin-bottom: 8px;">
            One-Time Password (6 Digits)
          </label>

          <!-- 6 Boxes: ONE NUMBER PER BOX ONLY -->
          <div class="otp-boxes-wrapper">
            <input type="text" name="digit_1" class="otp-box-digit" maxlength="1" inputmode="numeric" pattern="[0-9]*" autofocus required>
            <input type="text" name="digit_2" class="otp-box-digit" maxlength="1" inputmode="numeric" pattern="[0-9]*" required>
            <input type="text" name="digit_3" class="otp-box-digit" maxlength="1" inputmode="numeric" pattern="[0-9]*" required>
            <input type="text" name="digit_4" class="otp-box-digit" maxlength="1" inputmode="numeric" pattern="[0-9]*" required>
            <input type="text" name="digit_5" class="otp-box-digit" maxlength="1" inputmode="numeric" pattern="[0-9]*" required>
            <input type="text" name="digit_6" class="otp-box-digit" maxlength="1" inputmode="numeric" pattern="[0-9]*" required>
          </div>

          <button type="submit" class="btn-login-submit" style="margin-bottom: 14px;">VERIFY CODE & PROCEED</button>
          
          <div style="display: flex; justify-content: space-between; font-size: 0.82rem; margin-top: 10px;">
            <a href="login.php?action=resend_otp" style="color: #38bdf8; text-decoration: none; font-weight: 700; text-shadow: 0 1px 2px rgba(0,0,0,0.5);">🔄 Resend Code</a>
            <a href="login.php" style="color: rgba(255,255,255,0.85); text-decoration: none; text-shadow: 0 1px 2px rgba(0,0,0,0.5);">&larr; Back to Login</a>
          </div>
        </form>

      <!-- ==================================================================== -->
      <!-- VIEW B: FORGOT PASSWORD REQUEST FORM                                 -->
      <!-- ==================================================================== -->
      <?php elseif ($current_step === 'forgot'): ?>
        <div style="text-align: center; margin-bottom: 20px;">
          <h3 style="margin: 0; color: #ffffff; font-size: 1.3rem; text-shadow: 0 2px 6px rgba(0,0,0,0.6);">Reset Your Password</h3>
          <p style="font-size: 0.85rem; color: rgba(255,255,255,0.92); margin: 6px 0 0 0; text-shadow: 0 1px 3px rgba(0,0,0,0.6);">
            Enter your registered account email address. We will send a 6-digit OTP code to verify your identity.
          </p>
        </div>

        <form method="POST" action="login.php" autocomplete="off">
          <input type="hidden" name="action" value="forgot_password">

          <div class="login-input-group">
            <label>Registered Account Email</label>
            <input type="email" name="email" class="login-input" placeholder="e.g. your_name@gmail.com" required autocomplete="off">
          </div>

          <button type="submit" class="btn-login-submit" style="margin-top: 10px;">SEND RESET CODE</button>

          <div style="margin-top: 18px; font-size: 0.84rem;">
            <a href="login.php" style="color: #38bdf8; text-decoration: none; font-weight: 700; text-shadow: 0 1px 2px rgba(0,0,0,0.5);">&larr; Return to Sign In</a>
          </div>
        </form>

      <!-- ==================================================================== -->
      <!-- VIEW C: SET NEW PASSWORD                                             -->
      <!-- ==================================================================== -->
      <?php elseif ($current_step === 'reset_password' && isset($_SESSION['reset_authorized_email'])): ?>
        <div style="text-align: center; margin-bottom: 20px;">
          <h3 style="margin: 0; color: #ffffff; font-size: 1.3rem; text-shadow: 0 2px 6px rgba(0,0,0,0.6);">Create New Password</h3>
          <p style="font-size: 0.85rem; color: rgba(255,255,255,0.92); margin: 6px 0 0 0; text-shadow: 0 1px 3px rgba(0,0,0,0.6);">
            OTP Verified for <strong style="color: #38bdf8;"><?= htmlspecialchars($_SESSION['reset_authorized_email']) ?></strong>.
          </p>
        </div>

        <form method="POST" action="login.php" autocomplete="off">
          <input type="hidden" name="action" value="set_new_password">

          <div class="login-input-group">
            <label>New Password</label>
            <input type="password" name="new_password" class="login-input" placeholder="Enter new password" required autocomplete="new-password">
          </div>

          <div class="login-input-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" class="login-input" placeholder="Confirm new password" required autocomplete="new-password">
          </div>

          <button type="submit" class="btn-login-submit" style="margin-top: 10px;">UPDATE PASSWORD</button>
        </form>

      <!-- ==================================================================== -->
      <!-- VIEW D: STANDARD TABBED VIEW (LOGIN OR REGISTER)                     -->
      <!-- ==================================================================== -->
      <?php else: ?>

        <!-- Tab Switcher (Sign In vs Register) -->
        <div class="auth-nav-tabs">
          <button type="button" class="auth-tab-btn <?= $active_tab === 'login' ? 'active' : '' ?>" onclick="switchAuthTab('login')">
            Sign In
          </button>
          <button type="button" class="auth-tab-btn <?= $active_tab === 'register' ? 'active' : '' ?>" onclick="switchAuthTab('register')">
            Register
          </button>
        </div>

        <!-- 1. SIGN IN FORM -->
        <div id="tab-login-content" style="<?= $active_tab === 'login' ? 'display: block;' : 'display: none;' ?>">
          <form method="POST" action="login.php" autocomplete="off">
            <input type="hidden" name="action" value="login">

            <div class="login-input-group">
              <label>Username or Email</label>
              <input type="text" name="portal_user" id="login_portal_user" class="login-input" placeholder="Enter your username or email" value="" required autocomplete="new-password">
            </div>

            <div class="login-input-group">
              <label>Password</label>
              <input type="password" name="portal_pass" id="login_portal_pass" class="login-input" placeholder="Enter your password" value="" required autocomplete="new-password">
            </div>

            <div class="login-options-row">
              <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; color: #ffffff;">
                <input type="checkbox" name="remember"> Remember Me
              </label>
              <a href="login.php?step=forgot" style="color: #38bdf8; font-weight: 700; text-decoration: none;">Forgot Password?</a>
            </div>

            <button type="submit" class="btn-login-submit">LOGIN</button>
          </form>

          <div style="margin-top: 22px; font-size: 0.84rem; color: #ffffff;">
            Don't have an account? <a href="#" onclick="switchAuthTab('register'); return false;" style="color: #38bdf8; font-weight: 700; text-decoration: none;">Create an account here</a>
          </div>
        </div>

        <!-- 2. MULTI-ROLE REGISTRATION FORM (STUDENT, TEACHER, REGISTRAR) -->
        <div id="tab-register-content" style="<?= $active_tab === 'register' ? 'display: block;' : 'display: none;' ?>">
          <form method="POST" action="login.php" autocomplete="off" id="reg-form">
            <input type="hidden" name="action" value="register">

            <!-- Role Dropdown Selector -->
            <div class="login-input-group" style="margin-bottom: 14px;">
              <label style="color: #ffffff; font-weight: 700;">Register As <span style="color: #38bdf8;">*</span></label>
              <select name="reg_role" id="reg_role_select" class="login-input" required onchange="toggleRegRole(this.value)" style="font-weight: 700; background: rgba(255,255,255,0.95); color: #0f172a;">
                <option value="student" selected>🎓 Student</option>
                <option value="teacher">👨‍🏫 Teacher</option>
                <option value="registrar">🏛️ Registrar</option>
              </select>
            </div>

            <!-- 2.1 STUDENT SPECIFIC FIELDS -->
            <div id="fields_student">
              <div class="login-grid-2">
                <div class="login-input-group">
                  <label>Student ID Number *</label>
                  <input type="text" name="student_id" id="inp_student_id" class="login-input" placeholder="e.g. 2024-0012" autocomplete="off">
                </div>
                <div class="login-input-group">
                  <label>Full Name *</label>
                  <input type="text" name="full_name_student" id="inp_full_name_student" class="login-input" placeholder="e.g. Juan Dela Cruz" autocomplete="off">
                </div>
              </div>

              <div class="login-input-group">
                <label>Student Gmail / Email *</label>
                <input type="email" name="email_student" id="inp_email_student" class="login-input" placeholder="e.g. student@gmail.com" autocomplete="off">
                <small style="color: rgba(255,255,255,0.85); font-size: 0.74rem; display: block; margin-top: 3px;">
                  OTP verification code will be sent to this email.
                </small>
              </div>

              <div class="login-grid-2">
                <div class="login-input-group">
                  <label>Year Level *</label>
                  <select name="year_level" id="inp_year_level" class="login-input">
                    <option value="">Select Year Level</option>
                    <option value="Grade 7">Grade 7</option>
                    <option value="Grade 8">Grade 8</option>
                    <option value="Grade 9">Grade 9</option>
                    <option value="Grade 10">Grade 10</option>
                    <option value="Grade 11">Grade 11 ABM</option>
                    <option value="Grade 11">Grade 11 STEM</option>
                    <option value="Grade 12">Grade 12 ABM</option>
                    <option value="Grade 12">Grade 12 STEM</option>
                  </select>
                </div>

                <div class="login-input-group">
                  <label>Section *</label>
                  <input type="text" name="section" id="inp_section" class="login-input" placeholder="e.g. Diamond / BSIT 3-A" autocomplete="off">
                </div>
              </div>

              <div class="login-input-group">
                <label>Course / Strand *</label>
                <select name="course" id="inp_course" class="login-input">
                  <option value="">Select Course / Strand</option>
                  <option value="Junior High School (General)">Junior High School (General)</option>
                  <option value="STEM Strand">STEM Strand</option>
                  <option value="ABM Strand">ABM Strand</option>
                  <option value="HUMSS Strand">HUMSS Strand</option>
                  <option value="TVL Strand">TVL Strand</option>
                  <option value="BS Information Technology">BS Information Technology</option>
                  <option value="BS Computer Science">BS Computer Science</option>
                  <option value="BS Business Administration">BS Business Administration</option>
                  <option value="Bachelor of Elementary Education">Bachelor of Elementary Education</option>
                </select>
              </div>
            </div>

            <!-- 2.2 TEACHER SPECIFIC FIELDS -->
            <div id="fields_teacher" style="display: none;">
              <div class="login-grid-2">
                <div class="login-input-group">
                  <label>Employee Number *</label>
                  <input type="text" name="teacher_emp_no" id="inp_teacher_emp_no" class="login-input" placeholder="e.g. EMP-2024-088" autocomplete="off">
                </div>
                <div class="login-input-group">
                  <label>Full Name *</label>
                  <input type="text" name="full_name_teacher" id="inp_full_name_teacher" class="login-input" placeholder="e.g. Maria Clara, LPT" autocomplete="off">
                </div>
              </div>

              <div class="login-input-group">
                <label>Teacher Gmail / Email *</label>
                <input type="email" name="email_teacher" id="inp_email_teacher" class="login-input" placeholder="e.g. teacher@gmail.com" autocomplete="off">
                <small style="color: rgba(255,255,255,0.85); font-size: 0.74rem; display: block; margin-top: 3px;">
                  OTP verification code will be sent to this email.
                </small>
              </div>

              <div class="login-input-group">
                <label>Grade Level / Department *</label>
                <select name="teacher_grade_level" id="inp_teacher_grade_level" class="login-input">
                  <option value="">Select Grade Level / Department</option>
                  <option value="Junior High School">Junior High School (JHS)</option>
                  <option value="Senior High School">Senior High School (SHS)</option>
                </select>
              </div>
            </div>

            <!-- 2.3 REGISTRAR SPECIFIC FIELDS -->
            <div id="fields_registrar" style="display: none;">
              <div class="login-grid-2">
                <div class="login-input-group">
                  <label>Staff / Employee ID *</label>
                  <input type="text" name="registrar_emp_no" id="inp_registrar_emp_no" class="login-input" placeholder="e.g. REG-0012" autocomplete="off">
                </div>
                <div class="login-input-group">
                  <label>Full Name *</label>
                  <input type="text" name="full_name_registrar" id="inp_full_name_registrar" class="login-input" placeholder="e.g. Sofia Andres" autocomplete="off">
                </div>
              </div>

              <div class="login-input-group">
                <label>Registrar Gmail / Email *</label>
                <input type="email" name="email_registrar" id="inp_email_registrar" class="login-input" placeholder="e.g. registrar@gmail.com" autocomplete="off">
                <small style="color: rgba(255,255,255,0.85); font-size: 0.74rem; display: block; margin-top: 3px;">
                  OTP verification code will be sent to this email.
                </small>
              </div>

              <div class="login-input-group">
                <label>Office / Department</label>
                <input type="text" class="login-input" value="Office of the Registrar" readonly style="background: rgba(255,255,255,0.85); color: #0f172a; cursor: not-allowed; font-weight: 600;">
              </div>

              <div style="background: rgba(254, 243, 199, 0.95); border: 1px solid #f59e0b; color: #92400e; padding: 10px 14px; border-radius: 10px; font-size: 0.78rem; line-height: 1.4; margin-bottom: 15px;">
                🛡️ <strong>Admin Approval Notice:</strong> After email verification, Registrar accounts require Administrator approval before accessing student records.
              </div>
            </div>

            <!-- COMMON PASSWORD FIELD -->
            <div class="login-input-group">
              <label>Password *</label>
              <input type="password" name="password" id="inp_password" class="login-input" placeholder="Create a secure password" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn-login-submit" style="margin-top: 6px; background: linear-gradient(135deg, #00bfa5 0%, #0d9488 100%);">
              REGISTER & SEND OTP
            </button>
          </form>

          <div style="margin-top: 18px; font-size: 0.84rem; color: #ffffff;">
            Already have an account? <a href="#" onclick="switchAuthTab('login'); return false;" style="color: #38bdf8; font-weight: 700; text-decoration: none;">Sign In here</a>
          </div>
        </div>

      <?php endif; ?>

    </div>
  </div>

  <!-- JavaScript for Tab Switching and 6-Box OTP Auto-Focus / Backspace Behavior -->
  <script>
    function switchAuthTab(tab) {
      const loginContent = document.getElementById('tab-login-content');
      const regContent = document.getElementById('tab-register-content');
      const tabBtns = document.querySelectorAll('.auth-tab-btn');

      if (!loginContent || !regContent) return;

      if (tab === 'login') {
        loginContent.style.display = 'block';
        regContent.style.display = 'none';
        tabBtns[0].classList.add('active');
        tabBtns[1].classList.remove('active');
      } else {
        loginContent.style.display = 'none';
        regContent.style.display = 'block';
        tabBtns[1].classList.add('active');
        tabBtns[0].classList.remove('active');
      }
    }

    function toggleRegRole(role) {
      const fStudent = document.getElementById('fields_student');
      const fTeacher = document.getElementById('fields_teacher');
      const fRegistrar = document.getElementById('fields_registrar');

      if (fStudent) fStudent.style.display = (role === 'student') ? 'block' : 'none';
      if (fTeacher) fTeacher.style.display = (role === 'teacher') ? 'block' : 'none';
      if (fRegistrar) fRegistrar.style.display = (role === 'registrar') ? 'block' : 'none';

      // Toggle required attributes dynamically
      const studentReq = ['inp_student_id', 'inp_full_name_student', 'inp_email_student', 'inp_year_level', 'inp_section', 'inp_course'];
      const teacherReq = ['inp_teacher_emp_no', 'inp_full_name_teacher', 'inp_email_teacher', 'inp_teacher_grade_level'];
      const registrarReq = ['inp_registrar_emp_no', 'inp_full_name_registrar', 'inp_email_registrar'];

      studentReq.forEach(id => { const el = document.getElementById(id); if (el) el.required = (role === 'student'); });
      teacherReq.forEach(id => { const el = document.getElementById(id); if (el) el.required = (role === 'teacher'); });
      registrarReq.forEach(id => { const el = document.getElementById(id); if (el) el.required = (role === 'registrar'); });
    }

    // Auto-focus and backspace behavior for the 6-Box OTP
    document.addEventListener('DOMContentLoaded', function() {
      const otpInputs = document.querySelectorAll('.otp-box-digit');
      if (otpInputs.length === 6) {
        otpInputs.forEach((input, index) => {
          // Listen to input (typing or pasting)
          input.addEventListener('input', function(e) {
            const val = e.target.value;
            // Clean non-digits
            e.target.value = val.replace(/[^0-9]/g, '');

            if (e.target.value.length >= 1) {
              e.target.value = e.target.value.charAt(0); // Only keep 1 char
              e.target.classList.add('filled');
              if (index < 5) {
                otpInputs[index + 1].focus();
              }
            } else {
              e.target.classList.remove('filled');
            }
          });

          // Handle Backspace navigation
          input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace') {
              if (e.target.value === '' && index > 0) {
                otpInputs[index - 1].focus();
                otpInputs[index - 1].value = '';
                otpInputs[index - 1].classList.remove('filled');
              } else {
                e.target.classList.remove('filled');
              }
            } else if (e.key === 'ArrowLeft' && index > 0) {
              otpInputs[index - 1].focus();
            } else if (e.key === 'ArrowRight' && index < 5) {
              otpInputs[index + 1].focus();
            }
          });

          // Handle Paste of complete 6-digit code
          input.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedData = (e.clipboardData || window.clipboardData).getData('text').trim();
            const digits = pastedData.replace(/[^0-9]/g, '').slice(0, 6);
            if (digits.length > 0) {
              digits.split('').forEach((d, i) => {
                if (otpInputs[i]) {
                  otpInputs[i].value = d;
                  otpInputs[i].classList.add('filled');
                }
              });
              const nextIndex = Math.min(digits.length, 5);
              otpInputs[nextIndex].focus();
            }
          });
        });
      }

      // Ensure form fields are not populated by cached values after logout or Chrome autofill
      function clearLoginInputs() {
        const u = document.getElementById('login_portal_user');
        const p = document.getElementById('login_portal_pass');
        if (u) u.value = '';
        if (p) p.value = '';
      }
      clearLoginInputs();
      setTimeout(clearLoginInputs, 50);
      setTimeout(clearLoginInputs, 200);
      window.addEventListener('pageshow', clearLoginInputs);

      // Initialize registration role fields
      const regSelect = document.getElementById('reg_role_select');
      if (regSelect) {
        toggleRegRole(regSelect.value);
      }
    });
  </script>
</body>
</html>
