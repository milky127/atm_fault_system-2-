<?php
// includes/mailer.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../libs/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../libs/PHPMailer/src/Exception.php';

function send_email(string $to, string $subject, string $body): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $config = require __DIR__ . '/../config/mail_config.php';

    // Log the attempt
    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    $log_line = date('Y-m-d H:i:s') . " | TO: {$to} | SUBJECT: {$subject}" . PHP_EOL;
    @file_put_contents($log_dir . '/emails.log', $log_line, FILE_APPEND);

    // Dry run for testing
    if (!empty($config['dry_run'])) {
        @file_put_contents($log_dir . '/emails.log', "  → SKIPPED (dry_run enabled)" . PHP_EOL, FILE_APPEND);
        return true;
    }

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = 'UTF-8';

        // Recipients
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();

        @file_put_contents($log_dir . '/emails.log', "  → SENT OK" . PHP_EOL, FILE_APPEND);
        return true;

    } catch (Exception $e) {
        @file_put_contents($log_dir . '/emails.log', "  → FAILED: " . $mail->ErrorInfo . PHP_EOL, FILE_APPEND);
        return false;
    }
}

if (!function_exists("email_assignment")) {

    function email_assignment(
        string $to,
        string $tech_name,
        string $incident_number,
        string $title,
        string $atm_code,
        string $priority
    ): bool {
        $subject = "[CBE ATM] New incident assigned: {$incident_number}";
        $priority_c = ucfirst($priority);

        $body = "
        <html><body style='font-family:Arial,sans-serif;color:#333;line-height:1.5;'>
            <div style='max-width:560px;margin:0 auto;'>
                <div style='background:#8E1B8F;padding:20px;text-align:center;border-radius:12px 12px 0 0;'>
                    <h2 style='color:#fff;margin:0;'>ATM Support System</h2>
                </div>
                <div style='background:#f8f8f8;padding:30px;border-radius:0 0 12px 12px;'>
                    <h3 style='color:#252525;margin-top:0;'>New Incident Assigned</h3>
                    <p>Hi <strong>" . htmlspecialchars($tech_name) . "</strong>,</p>
                    <p>You have been assigned to the following incident:</p>
                    <table style='width:100%;border-collapse:collapse;margin:20px 0;'>
                        <tr>
                            <td style='padding:10px 14px;background:#fff;border:1px solid #eee;font-weight:600;width:35%;'>Incident</td>
                            <td style='padding:10px 14px;background:#fff;border:1px solid #eee;'>" . htmlspecialchars($incident_number) . "</td>
                        </tr>
                        <tr>
                            <td style='padding:10px 14px;background:#fff;border:1px solid #eee;font-weight:600;'>Title</td>
                            <td style='padding:10px 14px;background:#fff;border:1px solid #eee;'>" . htmlspecialchars($title) . "</td>
                        </tr>
                        <tr>
                            <td style='padding:10px 14px;background:#fff;border:1px solid #eee;font-weight:600;'>ATM</td>
                            <td style='padding:10px 14px;background:#fff;border:1px solid #eee;'>" . htmlspecialchars($atm_code) . "</td>
                        </tr>
                        <tr>
                            <td style='padding:10px 14px;background:#fff;border:1px solid #eee;font-weight:600;'>Priority</td>
                            <td style='padding:10px 14px;background:#fff;border:1px solid #eee;'>{$priority_c}</td>
                        </tr>
                    </table>
                    <p>Please log in to the system to begin work.</p>
                    <p style='color:#888;font-size:12px;margin-bottom:0;'>— ATM Fault Management System</p>
                </div>
            </div>
        </body></html>
        ";

        return send_email($to, $subject, $body);
    }
}