<?php
define('DEV_MODE', true);
define('REQUIRE_AUTH', false);

// ── SMTP email reminders (#74) ────────────────────────────────────────────────
// Set these to enable server-side email reminders via PHP mail() or SMTP
// For SMTP: configure php.ini SMTP settings or use a library like PHPMailer
define('SMTP_HOST', '');   // e.g. 'smtp.gmail.com'
define('SMTP_PORT', 587);
define('SMTP_USER', '');   // e.g. 'you@gmail.com'
define('SMTP_PASS', '');   // app password
define('SMTP_FROM', '');   // e.g. 'calendar@yourdomain.com'

// ── SMS reminders via Twilio (#75) ────────────────────────────────────────────
// Sign up at twilio.com, get a SID, Auth Token, and a phone number
define('TWILIO_SID',   '');  // e.g. 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
define('TWILIO_TOKEN', '');  // Auth token
define('TWILIO_FROM',  '');  // Twilio phone number e.g. '+15005550006'
