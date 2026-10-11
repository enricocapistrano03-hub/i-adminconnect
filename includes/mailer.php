<?php
// ==============================================================================
// i-AdminConnect Lightweight Native SMTP Mailer for Gmail
// ==============================================================================

function send_otp_email($to_email, $otp_code, $type = 'registration', $recipient_name = 'User', $role = 'student') {
    $config_file = __DIR__ . '/../config/mail_config.php';
    $config = file_exists($config_file) ? require($config_file) : [];

    $host = $config['smtp_host'] ?? 'smtp.gmail.com';
    $port = (int)($config['smtp_port'] ?? 587);
    $user = trim($config['smtp_user'] ?? '');
    $pass = trim(str_replace(' ', '', $config['smtp_pass'] ?? ''));
    $from_email = !empty($config['from_email']) ? $config['from_email'] : ($user ?: 'noreply@adminconnect.edu.ph');
    $from_name = $config['from_name'] ?? 'i-AdminConnect Portal';

    $role_clean = strtolower(trim($role));
    if ($role_clean === 'teacher') {
        $role_label = 'Teacher';
        $account_desc = 'teacher account';
    } elseif ($role_clean === 'registrar') {
        $role_label = 'Registrar';
        $account_desc = 'registrar account';
    } else {
        $role_label = 'Student';
        $account_desc = 'student account';
    }

    $is_reset = ($type === 'forgot_password');
    $subject_title = $is_reset ? "{$role_label} Password Reset Verification Code" : "{$role_label} Registration OTP Verification";
    $action_desc = $is_reset ? "resetting your {$account_desc} password" : "creating your {$account_desc}";

    // Build responsive HTML email template
    $html_body = '<!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8">
      <title>' . htmlspecialchars($subject_title) . '</title>
      <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 30px; color: #1e293b; }
        .email-container { max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
        .email-header { background: linear-gradient(135deg, #0d3b66 0%, #00bfa5 100%); color: #ffffff; padding: 28px 24px; text-align: center; }
        .email-header h1 { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
        .email-header p { margin: 6px 0 0 0; font-size: 13px; opacity: 0.9; }
        .email-content { padding: 32px 28px; }
        .otp-box { background: #f8fafc; border: 2px dashed #00bfa5; border-radius: 12px; padding: 20px; text-align: center; margin: 24px 0; }
        .otp-number { font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #0d3b66; font-family: "Courier New", Courier, monospace; }
        .expiry-note { font-size: 13px; color: #64748b; margin-top: 8px; }
        .email-footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 18px 24px; text-align: center; font-size: 12px; color: #94a3b8; }
      </style>
    </head>
    <body>
      <div class="email-container">
        <div class="email-header">
          <h1>i-AdminConnect</h1>
          <p>Administrative Services and Record Tracking System</p>
        </div>
        <div class="email-content">
          <h2 style="font-size: 18px; color: #0f172a; margin-top: 0;">Hello, ' . htmlspecialchars($recipient_name) . '!</h2>
          <p style="font-size: 14px; line-height: 1.6; color: #475569;">
            We received a request for ' . $action_desc . ' on the <strong>i-AdminConnect Portal</strong>. Please use the One-Time Password (OTP) below to complete your verification:
          </p>
          <div class="otp-box">
            <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #00bfa5; margin-bottom: 6px; letter-spacing: 1px;">Your One-Time Code</div>
            <div class="otp-number">' . htmlspecialchars($otp_code) . '</div>
            <div class="expiry-note">⏳ This code will expire in <strong>10 minutes</strong>.</div>
          </div>
          <p style="font-size: 13px; line-height: 1.5; color: #64748b;">
            ⚠️ If you did not make this request, please disregard this email. Never share your OTP with anyone.
          </p>
        </div>
        <div class="email-footer">
          &copy; ' . date('Y') . ' i-AdminConnect Portal &bull; Office of the Registrar and Administration
        </div>
      </div>
    </body>
    </html>';

    // If SMTP credentials are empty or placeholder, return demo mode info
    if (empty($user) || empty($pass) || strpos($user, 'your_') !== false || $user === 'iadminconnect.demo@gmail.com') {
        return [
            'success' => false,
            'mode' => 'demo',
            'otp' => $otp_code,
            'message' => 'Gmail SMTP credentials not yet set in config/mail_config.php. OTP code is available in demo mode.'
        ];
    }

    // Connect via stream_socket_client
    $timeout = 15;
    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
    if (!$socket) {
        return [
            'success' => false,
            'mode' => 'demo',
            'otp' => $otp_code,
            'message' => "Could not connect to SMTP server: {$errstr} ({$errno})"
        ];
    }

    $read = function() use ($socket) {
        $data = "";
        while ($str = fgets($socket, 512)) {
            $data .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        return $data;
    };

    $send = function($cmd) use ($socket) {
        fputs($socket, $cmd . "\r\n");
    };

    try {
        $read(); // 220
        $send("EHLO " . gethostname());
        $read(); // 250

        // STARTTLS
        $send("STARTTLS");
        $tls_res = $read();
        if (substr($tls_res, 0, 3) !== '220') {
            fclose($socket);
            return [
                'success' => false,
                'mode' => 'demo',
                'otp' => $otp_code,
                'message' => "STARTTLS rejected: {$tls_res}"
            ];
        }

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return [
                'success' => false,
                'mode' => 'demo',
                'otp' => $otp_code,
                'message' => "Failed to initiate TLS encryption"
            ];
        }

        $send("EHLO " . gethostname());
        $read(); // 250

        // Authenticate
        $send("AUTH LOGIN");
        $auth_res = $read(); // 334
        if (substr($auth_res, 0, 3) !== '334') {
            fclose($socket);
            return [
                'success' => false,
                'mode' => 'demo',
                'otp' => $otp_code,
                'message' => "AUTH LOGIN not accepted: {$auth_res}"
            ];
        }

        $send(base64_encode($user));
        $read(); // 334
        $send(base64_encode($pass));
        $pass_res = $read();
        if (substr($pass_res, 0, 3) !== '235') {
            fclose($socket);
            return [
                'success' => false,
                'mode' => 'demo',
                'otp' => $otp_code,
                'message' => "Gmail Authentication Failed: Please check your Gmail App Password. Server said: {$pass_res}"
            ];
        }

        // Send Email Envelope
        $send("MAIL FROM:<{$user}>");
        $read();
        $send("RCPT TO:<{$to_email}>");
        $rcpt_res = $read();
        if (substr($rcpt_res, 0, 3) !== '250') {
            fclose($socket);
            return [
                'success' => false,
                'mode' => 'demo',
                'otp' => $otp_code,
                'message' => "Recipient rejected: {$rcpt_res}"
            ];
        }

        $send("DATA");
        $read(); // 354

        $subject = "Your {$role_label} Verification Code: {$otp_code} - i-AdminConnect";
        $headers = [
            "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <{$user}>",
            "To: <{$to_email}>",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "Content-Transfer-Encoding: 8bit",
            "Date: " . date('r'),
            "X-Mailer: i-AdminConnect-PHP"
        ];

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $html_body . "\r\n.\r\n";
        fputs($socket, $payload);
        $final_res = $read();
        $send("QUIT");
        fclose($socket);

        if (substr($final_res, 0, 3) === '250') {
            return [
                'success' => true,
                'mode' => 'live',
                'otp' => $otp_code,
                'message' => "Verification code sent to your Gmail ({$to_email})! Please check your inbox or spam folder."
            ];
        } else {
            return [
                'success' => false,
                'mode' => 'demo',
                'otp' => $otp_code,
                'message' => "Message rejected by server: {$final_res}"
            ];
        }
    } catch (Exception $e) {
        if (is_resource($socket)) fclose($socket);
        return [
            'success' => false,
            'mode' => 'demo',
            'otp' => $otp_code,
            'message' => "Error sending email: " . $e->getMessage()
        ];
    }
}
