#!/usr/bin/env php
<?php
/**
 * Test SendGrid email configuration
 * Usage: php scripts/test-email.php <recipient@example.com>
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

if ($argc < 2) {
  echo "Usage: php scripts/test-email.php <recipient@example.com>\n";
  exit(1);
}

$toEmail = $argv[1];

echo "Testing SendGrid email configuration...\n\n";

// Check SendGrid API key
$apiKey = jarvis_setting_get('SENDGRID_API_KEY') ?: getenv('SENDGRID_API_KEY') ?: '';
if ($apiKey) {
  echo "✓ SendGrid API key found: " . substr($apiKey, 0, 10) . "..." . substr($apiKey, -5) . "\n";
} else {
  echo "✗ SendGrid API key not configured!\n";
  echo "  Set it using: php scripts/set-secret.php SENDGRID_API_KEY 'your-api-key'\n";
  exit(1);
}

// Check MAIL_FROM
$from = jarvis_mail_from();
echo "✓ MAIL_FROM: {$from}\n";
echo "\n";

// Verify sender domain
$domain = substr(strrchr($from, "@"), 1);
echo "Important: Make sure '{$domain}' is verified in your SendGrid account!\n";
echo "Visit: https://app.sendgrid.com/settings/sender_auth/senders\n\n";

// Send test email
echo "Sending test email to {$toEmail}...\n";
$subject = 'JARVIS Test Email - ' . date('Y-m-d H:i:s');
$bodyText = "This is a test email from JARVIS to verify SendGrid configuration.\n\nSent at: " . date('c');
$bodyHtml = "<p>This is a test email from <b>JARVIS</b> to verify SendGrid configuration.</p><p>Sent at: " . date('c') . "</p>";

$result = jarvis_send_email($toEmail, $subject, $bodyText, $bodyHtml);

if ($result) {
  echo "\n✓ Email sent successfully!\n";
  echo "  Check the inbox for {$toEmail}\n";
  echo "  Also check spam/junk folder if not received\n";
} else {
  echo "\n✗ Email failed to send!\n";
  echo "  Check the error log for details\n";
  echo "  Common issues:\n";
  echo "    1. Sender email/domain not verified in SendGrid\n";
  echo "    2. Invalid API key\n";
  echo "    3. SendGrid account suspended or payment issue\n";
  exit(1);
}
