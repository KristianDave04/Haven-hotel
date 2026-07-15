<?php
// mailer_config.php
//
// SMTP credentials used by sendVerificationEmail() in sign-up.php.
// Fill these in with your own mail provider's details, then keep this file
// OUT of version control / public web access (e.g. add it to .gitignore and
// make sure your web server config doesn't serve raw .php as text).
//
// Common providers:
//   - Gmail:   smtp.gmail.com, port 587, requires an "App Password" (not your normal password)
//   - Outlook: smtp.office365.com, port 587
//   - SendGrid/Mailgun/etc: use the SMTP relay host/port/credentials they give you

define('SMTP_HOST', 'smtp.example.com');
define('SMTP_USERNAME', 'your-smtp-username@example.com');
define('SMTP_PASSWORD', 'your-smtp-password-or-app-password');
define('SMTP_PORT', 587);

define('SMTP_FROM_EMAIL', 'no-reply@havenhotel.example');
define('SMTP_FROM_NAME', 'Haven Hotel');