<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
Security::startSession();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Register as a Marketing Associate with EK Aram Developers. Registrations require administrator approval.">
  <title>Marketing Associate Sign Up | EK Aram Developers</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
  <main class="admin-auth associate-auth">
    <a class="brand admin-brand" href="/"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a>
    <p class="section-kicker">Marketing Associate Portal</p>
    <h1>Sign Up</h1>
    <p>Submit your details for administrator review. Portal access is enabled only after approval.</p>
    <form class="admin-form associate-registration-form" data-lead-form method="post" enctype="multipart/form-data" action="/api/marketing-associates/register.php">
      <input type="hidden" name="csrf_token" value="<?= Security::escape(Security::csrfToken()) ?>">
      <input type="hidden" name="invite_token" value="<?= Security::escape(is_string($_GET['invite'] ?? null) ? $_GET['invite'] : '') ?>">
      <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
      <label>Full Name<input name="full_name" required maxlength="150" autocomplete="name"></label>
      <label>Contact Number
        <span class="associate-phone-input"><span aria-label="India country code">+91</span><input name="contact_number" type="tel" inputmode="numeric" pattern="[6-9][0-9]{9}" minlength="10" maxlength="10" placeholder="10-digit mobile number" autocomplete="tel-national" required></span>
      </label>
      <label>Aadhaar Number<input name="aadhaar_number" type="text" inputmode="numeric" pattern="[0-9]{12}" minlength="12" maxlength="12" autocomplete="off" required></label>
      <label>PAN Number<input name="pan_number" type="text" pattern="[A-Za-z]{5}[0-9]{4}[A-Za-z]" minlength="10" maxlength="10" autocomplete="off" required></label>
      <label>Bank Account Number<input name="account_number" type="text" inputmode="numeric" pattern="[0-9]{6,34}" minlength="6" maxlength="34" autocomplete="off" required></label>
      <label>IFSC Code<input name="ifsc_code" type="text" pattern="[A-Za-z]{4}0[A-Za-z0-9]{6}" minlength="11" maxlength="11" autocomplete="off" required></label>
      <label class="full">Branch Location<input name="branch_location" type="text" maxlength="150" autocomplete="off" required></label>
      <label class="full">Profile Photo (JPG, PNG or WebP; up to 5 MB)
        <input name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" data-photo-input>
        <img class="associate-photo-preview" data-photo-preview alt="Selected profile photo preview" hidden>
      </label>
      <label>Create Password (12+ characters)<input name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></label>
      <label>Confirm Password<input name="confirm_password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></label>
      <label class="consent full"><input name="consent" type="checkbox" value="1" required> I consent to EK Aram Developers processing my identity and bank details to review this registration.</label>
      <button class="button button-dark full" type="submit">Submit Registration</button>
      <p class="form-status full" role="status" aria-live="polite"></p>
    </form>
    <p><a href="/marketing-associate/login.php">Already registered? Login</a></p>
    <p><a href="/">Return to website</a></p>
  </main>
  <script src="/assets/js/marketing-associate-profile.js" defer></script>
  <script src="/assets/js/main.js" defer></script>
</body>
</html>
