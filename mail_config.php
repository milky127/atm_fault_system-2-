<?php
// config/mail_config.php

return [
    // Gmail SMTP server details
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_secure' => 'tls', // Use 'tls' for port 587, or 'ssl' for port 465

    // Your new Gmail account
    'smtp_username' => 'cbe.atmsupport@gmail.com',
    'smtp_password' => 'zvmn yymu kvcf vllp', // Paste the App Password here

    // Sender details
    'from_email' => 'cbe.atmsupport@gmail.com',
    'from_name' => 'CBE ATM Support System',

    // Set to true to only log emails (for testing), false to actually send them
    'dry_run' => false,
];