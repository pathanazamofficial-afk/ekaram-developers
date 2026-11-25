# EK Aram Developers

PHP/MySQL real-estate website and CRM for XAMPP locally and Apache/MySQL shared hosting.

## Local development

Requirements: XAMPP with PHP 8.1+ and MariaDB/MySQL. The application does not need Node.js, Composer, or a long-running application server.

1. The local `.env` is configured for the XAMPP `root` account and local `ekaram_developers` database. It is ignored by Git and must never be deployed.
2. The local database and 24-table development baseline are installed. For a fresh local setup, create an empty database in phpMyAdmin and import `database/dev_baseline.sql`.
3. `database/dev_baseline.sql` is a development schema created with the user's authorization, not the previously referenced approved production migration. The older `database/phase2_public_schema.sql` is only a four-table starter.
4. Import `database/brochure_content_seed.sql` after the baseline to publish the brochure-backed Quantum City @ Lam project page. It adds the brochure's location distances, listed features, amenities, address, and map search only; it creates no sample plots or availability. This seed is safe to re-run and does not change the schema. Admin-managed specifications use `Location: <place>` labels for distance cards and `Highlight: <feature>` labels for project highlights.
5. Import `database/marketing_associates_migration.sql` after the baseline to add the separate Marketing Associate role and encrypted registration table. The portal uses `APP_ENCRYPTION_KEY` to encrypt Aadhaar, PAN, and bank account numbers and to create keyed duplicate-detection fingerprints. Keep this key backed up and do not rotate it without a data migration plan.
6. Import `database/marketing_associate_wallet_migration.sql` after the Marketing Associate migration to add private associate profiles, reporting-manager assignments, immutable withdrawal status history, and auditable wallet administration. It extends the existing wallet and withdrawal ledger instead of creating parallel wallet tables. Then run `php scripts/migrate_marketing_associate_bank_data.php` with the environment's `APP_ENCRYPTION_KEY` set; it encrypts existing IFSC/branch values and clears their plaintext columns. Profile photos are re-encoded and stored under private `storage/uploads/marketing-associates/`; serve them only through the authenticated photo endpoint.
7. The supplied brochure PDF is served from `public/assets/project-brochure.pdf`; the extracted page images in `public/assets/images/` are brochure artwork/reference images, not photos of completed facilities. The supplied 235-plot price sheet is verified and imported by `php scripts/import_quantum_city_plot_prices.php`; plots 188–197 intentionally retain no area or price. The supplied site layout is displayed above the plot inventory from `public/assets/project-layout-plan.pdf`; the interactive plot cards remain a number-sorted inventory and are not positioned to surveyed coordinates. Agreement uses the `booked` status; Recent Agreement is stored separately as `recent_agreement`, and status changes are recorded in `plot_status_history`. For existing databases, apply `database/quantum_city_plot_facing_migration.sql` and `database/quantum_city_recent_agreement_migration.sql` once, then run `php scripts/import_quantum_city_plot_facings.php` and `php scripts/mark_quantum_city_recent_agreements.php`. Fresh databases include both columns and statuses in `database/dev_baseline.sql`. A surveyed map pin and business WhatsApp number were not supplied.
8. Configure a random payout encryption key in the local `.env`. Generate it without displaying it in the shell:

   ```powershell
   $key = & "C:\xampp\php\php.exe" -r "echo base64_encode(random_bytes(32));"
   Add-Content -LiteralPath .env -Value "APP_ENCRYPTION_KEY=$key"
   Remove-Variable key
   ```

   Back up this key securely. Losing it makes encrypted payout details unreadable. Use a different key for every environment.
9. Create the first admin from PowerShell without putting the password in command history:

   ```powershell
   $env:ADMIN_NAME = Read-Host "Administrator name"
   $env:ADMIN_EMAIL = Read-Host "Administrator email"
   $env:ADMIN_PHONE = Read-Host "Administrator phone"
   $secure = Read-Host "Administrator password (12+ characters)" -AsSecureString
   $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
   try {
     $env:ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
     & "C:\xampp\php\php.exe" scripts\create_admin.php
   } finally {
     Remove-Item Env:\ADMIN_PASSWORD -ErrorAction SilentlyContinue
     [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
   }
   ```

10. Start the PHP development server from the project root:

   ```powershell
   & "C:\xampp\php\php.exe" -S 127.0.0.1:8097 -t public
   ```

11. Visit `http://127.0.0.1:8097/` for the public site, `/admin/login.php` for admin content management, `/agent/register.php` for employee registration, and `/marketing-associate/signup.php` for Marketing Associate registration.

The project rate is stored in `projects.price_per_sqyd`; area and facing are stored per plot in `plots.area_sqyd` and `plots.facing_direction`. The public map and CSV download calculate each total from the project rate and plot area. The plot map displays and filters by facing, and admins can update a plot's facing from the plot inventory form. Updating a project rate in the admin dashboard recalculates inventory prices while leaving existing booking amounts unchanged.

Marketing Associate IDs are displayed using at least three digits (for example, `#001`); the underlying IDs and links remain numeric.

The workspace PHP validator uses the XAMPP executable in `.vscode/settings.json`. To make `php` available in new VS Code terminals, add `C:\xampp\php` to your Windows user `Path` and restart VS Code.

## Schema and production warning

The development baseline has 24 tables and seeds only system roles and the default commission distribution. It does not include production or demo customers, bookings, wallets, or transactions. Review and approve the schema and business rules before deployment; configure production database credentials, HTTPS, uploads, backups, and the Hostinger document root separately. Never deploy the local `.env`.

For Hostinger, point the domain document root at the `public` directory where possible. Keep `.env`, `app`, `database`, `scripts`, and `storage` outside the public document root. Configure the encryption key as an environment variable or in a protected `.env` outside the web root. Set up a Hostinger cron job to run `scripts/expire_reservations.php` every 10-15 minutes using the account's PHP CLI executable; this releases expired 10-hour plot reservations.

Set `WHATSAPP_PHONE` to the business WhatsApp number in international digits-only format (including country code). Set `CONTACT_PHONE`, `CONTACT_EMAIL`, and `CONTACT_ADDRESS` to company-approved contact details; these are intentionally blank until the company supplies real values.

## Current features

- Database-backed project details, specifications, amenities, gallery, plot inventory, brochure links, and map
- Enquiry, callback, and site-visit request flows with CSRF checks and transactional inserts
- Admin sign-in and management of projects, specifications, plots, amenities, gallery images, and visit status
- Separate Marketing Associate sign-up/login and admin approval, rejection, blocking, remarks, and masked sensitive-data review
- Marketing Associate profile photo, approved-only performance and wallet dashboard, admin credits/reversals, and auditable withdrawal lifecycle
- Employee registration, admin approval, role assignment, reporting hierarchy, employee sign-in, and scoped team dashboards
- Lead assignment/status updates and employee follow-up notes
- Admin customer contact management and filtered CSV reports for leads, bookings, commissions, and withdrawals
- 10-hour plot reservations, admin booking confirmation/cancellation, and expiry cleanup
- Versioned commission percentages, hierarchy-based commission records, admin approval, and wallet credits
- Encrypted payout details, balance-checked withdrawal requests, admin review, and auditable payout ledger entries
- Admin/employee notifications and basic operational dashboard counts
- Private upload storage for re-encoded JPEG/PNG images and validated PDF brochures
- Audit records for public leads, employee actions, bookings, commission/withdrawal changes, and sensitive payout-detail access

## Remaining production work

- Select and integrate an OTP/SMS provider; the OTP table exists but passwordless OTP login and password reset are not enabled.
- Finalize booking payment/receipt, cancellation, commission reversal, and payout approval policies.
- Expand reports/analytics beyond the current filtered operational tables and CSV exports.
- Review the development schema as the production migration and complete an Apache/Hostinger deployment test, backups, retention, and recovery procedures.
