<?php
require_once __DIR__ . "/includes/mailer.php";

$to      = "cbe.atmsupport@gmail.com";   // send to yourself for testing
$subject = "CBE ATM System — Test Email";
$body    = "<h2>Test successful!</h2><p>If you're reading this, your email setup works.</p>";

$result = send_email($to, $subject, $body);

echo "<pre>";
echo $result ? "✅ Email sent successfully\n" : "❌ Email failed to send\n";
echo "\nCheck logs/emails.log for details.\n";
echo "</pre>";