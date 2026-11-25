# EK ARAM DEVELOPERS - Project Foundation Architecture

## 1. Purpose

This document defines the proposed foundation for the public real estate website and the internal agent/CRM system. It is an architecture and review deliverable only; no new application modules or database migrations are being implemented in this phase.

The baseline requirements are:

- Core PHP using a structured MVC-compatible design
- MySQL with PDO and prepared statements
- Apache and `.htaccess` support for Hostinger shared hosting
- HTML5, CSS3, JavaScript, and Bootstrap 5 or Tailwind CSS
- Separate public/admin areas and one shared Marketing Associate portal
- One shared database for all modules
- Role-based access control and hierarchical reporting
- Immutable financial and audit records

## 2. Existing Project and Migration Review

The workspace began with a small public-site foundation and now includes a local development baseline and first application modules:

- Database-backed public landing page, amenities page, public forms, media serving, and admin content screens under `public/`
- PDO, environment loading, session, CSRF, authentication, and upload helpers under `app/`
- Local environment settings, `database/dev_baseline.sql`, and `scripts/create_admin.php`
- Root and public Apache `.htaccess` files and VS Code PHP validation settings

`database/phase2_public_schema.sql` remains the original four-table public-site starter. At the user's request, `database/dev_baseline.sql` was added and imported into the local XAMPP database `ekaram_developers`. It defines 24 development tables covering the requested domains and seeds only six system roles and version-1 commission rates; it contains no fabricated customer, booking, wallet, or financial transactions. This is a newly generated development baseline, **not an approved production migration**.

No prior approved 24-table migration was supplied. The application now reads live project content from the local database and displays an empty state when no published project exists; it no longer treats synthetic plot inventory as live.

The development baseline is suitable for local implementation only. Review and reconcile it with company policy before production use. Any material business-rule changes, especially booking payment and commission settlement behavior, still require confirmation.

## 3. Architecture

### 3.1 Recommended Folder Structure

```text
/ekaram
  /app
    /Config
      app.php
      database.php
      otp.php
    /Controllers
      /Public
      /Admin
      /Agent
      /Api
    /Core
      Router.php
      Controller.php
      Model.php
      Database.php
      Auth.php
      Authorization.php
      Csrf.php
      Session.php
      Validator.php
      Upload.php
      AuditLogger.php
      Response.php
    /Middleware
      AuthMiddleware.php
      RoleMiddleware.php
      CsrfMiddleware.php
      RateLimitMiddleware.php
    /Models
      User.php
      Role.php
      Customer.php
      Project.php
      Plot.php
      Booking.php
      SiteVisit.php
      Commission.php
      Wallet.php
      Withdrawal.php
      Notification.php
      AuditLog.php
    /Services
      AuthService.php
      OtpService.php
      PlotReservationService.php
      BookingService.php
      CommissionService.php
      WalletService.php
      WithdrawalService.php
      NotificationService.php
      ReportService.php
      UploadService.php
      MailService.php
      SmsService.php
    /Views
      /layouts
      /public
      /admin
      /agent
      /auth
      /errors
  /public                      # Only web-accessible document root
    /assets
      /css
      /js
      /images
    index.php
    .htaccess
  /routes
    web.php
    api.php
  /database
    /migrations
    /seeders
  /storage
    /logs
    /cache
    /tmp
    /uploads                 # Non-executable; outside public document root
  /tests
    /Unit
    /Integration
  /docs
  /.env.example
  /.htaccess
  /composer.json
  /README.md
```

The workspace currently has only the smaller set of files listed in Section 2. This is the target structure, not a claim that those folders/modules already exist. On Hostinger, configure the domain document root to `public/` where possible. If the account requires the repository root as document root, deny direct HTTP access to every non-public directory and sensitive file in the root `.htaccess`; the current root rules do not yet establish that full boundary.

### 3.2 Request Flow

```text
Apache / .htaccess
  -> public/index.php
  -> Router
  -> Middleware
  -> Controller
  -> Service
  -> Model / PDO
  -> MySQL
  -> View or JSON response
```

Use controllers for HTTP orchestration only. Put business rules in services so public and dashboard operations use the same logic.

### 3.3 Area Separation

| Area | URL prefix | Allowed roles |
| --- | --- | --- |
| Public website | `/` | Public |
| Authentication | `/login`, `/register`, `/otp`, `/password-reset` | Public and pending/approved users according to flow |
| Admin | `/admin/*` | Admin only |
| Marketing Associate dashboards | `/marketing-associate/*` | Approved active associates with an Admin-assigned designation |
| Legacy agent URLs | `/agent/*` | Redirect to the shared Marketing Associate portal |
| REST-style APIs | `/api/*` | Session-authenticated or explicitly public endpoints |

Do not authorize by hidden navigation links alone. Every controller action and API route must verify permission server-side.

## 4. Role and Hierarchy Model

All team members use the single `marketing_associate` account role and shared registration/login. The Admin saves one designation, then approves or rejects the profile; a designation is not a separate account role. Registration does not require or assign a reporting manager.

Designations and reporting order:

1. Deputy Director (top-level; no reporting manager)
2. Deputy General Manager
3. Assistant General Manager
4. Deputy Manager
5. Marketing Manager

Every approved Marketing Associate must have one of these five Admin-assigned designations. Existing reporting links are preserved, but are no longer prerequisites for designation, approval, or login. Designation changes are audited with the actor, before/after values and a reason. The commission rate remains centrally configured by designation; the applicable version and rate at allocation are also recorded in the assignment audit.

Admin designation assignments are authoritative. Employees must not assign their own designation or reporting manager.

Hierarchy queries must enforce scope using the reporting chain stored in the database. Authorization must not depend only on the current designation label.

## 5. Database Architecture

### 5.1 Checked-in Schemas and Table Groups

The legacy starter SQL contains four tables:

| Existing table | Current purpose | Important limitations against the requested system |
| --- | --- | --- |
| `projects` | Public project facts and publication status | No project media/gallery model; map and brochure are URL fields; no detailed project configuration |
| `amenities` | Project-linked amenity name/image/order | No separate media management; image URL trust and validation must be addressed |
| `plots` | Project-linked number, dimensions, status, and optional price | No dimension units/structured dimensions, pricing history, map coordinates/layout, status history, reservation owner/expiry, or booking relationship |
| `customer_leads` | Basic public enquiries | No customer lifecycle/assignment, duplicate handling, consent, follow-ups, visit or booking links, or retention policy |

The initial local development baseline had 24 tables. It is extended by `database/commission_and_team_workflows_migration.sql`, which adds `recruitment_history`, `lead_ownership_history`, `plot_collections`, `booking_milestones`, `commission_batches`, `commission_exclusions`, and `commission_batch_status_history`, plus additive ownership, recruiter, visit-logistics, and commission-stage columns. The later `database/recruitment_invites_migration.sql` adds hashed, expiring, single-use invitations:

`roles`, `users`, `employee_profiles`, `otp_challenges`, `projects`, `project_specifications`, `amenities`, `project_gallery`, `plots`, `plot_status_history`, `customers`, `leads`, `lead_assignments`, `lead_followups`, `site_visits`, `bookings`, `booking_status_history`, `commission_rule_versions`, `commissions`, `wallets`, `wallet_transactions`, `withdrawal_requests`, `notifications`, and `audit_logs`.

The generated development tables cover these domains:

| Domain | Required tables or equivalent data |
| --- | --- |
| Identity | users, roles, user-role assignments or equivalent |
| Hierarchy | employee profile/designation and reporting relationship or equivalent |
| Authentication | OTP challenge/attempt and password-reset lifecycle or equivalent |
| Projects | projects, project content/specifications |
| Inventory | plots, dimension units/pricing, map/layout data, plot status history |
| Media | amenities, banners, galleries, brochures or equivalent managed content |
| Customers | leads/customers, assignment, consent, follow-ups and interaction history |
| Visits | site visits, assigned staff and status/history |
| Bookings | booking, reservation expiration, payment/status history and cancellation |
| Financial | versioned commission configuration, commission ledger, wallet and immutable transactions |
| Withdrawals | withdrawal request, review, payout and reconciliation records |
| Notifications | in-app notifications and optional delivery/attempt records |
| Security | audit trail and login/OTP abuse controls; database-backed sessions if selected |

The updated local development baseline contains 32 tables. Applying the Marketing Associate extensions yields 36 tables. `database/unified_marketing_associate_migration.sql` preserves that table count while adding designation and invitation-suggestion columns, migrating the five former rank roles to the common account role, and preserving legacy hierarchy/history. `database/marketing_associate_approval_audit_migration.sql` adds the rejecting-admin, rejection-time, and required rejection-reason fields without creating another table. The migrations keep missing legacy identity/bank values null rather than inventing data. This is a chosen local baseline, not evidence that an earlier approved schema was reviewed. The legacy four-table SQL and the development baseline are alternatives; do not import the four-table starter over the development database. The development schema has not been validated against company-specific payment, reporting, compliance, or accounting rules.

For an existing database, back it up and apply the Marketing Associate migrations if missing, then `database/commission_and_team_workflows_migration.sql` and `database/recruitment_invites_migration.sql` if missing, followed by `database/unified_marketing_associate_migration.sql` and `database/marketing_associate_approval_audit_migration.sql` exactly once and in that order. The checked-in `database/dev_baseline.sql` already includes the commission/ownership workflow and recruitment-invite schemas for fresh installs; apply the associate migrations and then both unified/approval-audit migrations after the baseline. Do not repeat migrations already applied.

Eligible designated associates can create one-time, seven-day registration invitations; invitations never set a designation or reporting manager. Every new member registers through the shared portal, remains pending, and can operate only after Admin designation assignment and approval. A Marketing Manager invitation carries no lower-designation suggestion because the five-designation chain has no rank below Marketing Manager.

### 5.2 Concurrency and Reservations

Temporary reservations expire 10 hours after creation.

All plot reservation and booking confirmation operations must use database transactions.

Use a row lock on the plot record and/or a conditional update that requires an available status before changing it.

No agent or public endpoint may confirm a booking or directly mark a plot sold. Only an admin-authorized workflow may create a confirmed/sold state.

Expired reservations are released by the CLI expiry task and opportunistically when an associate requests a new reservation on a plot whose previous reservation has expired. Configure the CLI task as a Hostinger cron job.

### 5.3 Financial Rules

Commission is created only at verified agreement and registration milestones, based on actual verified customer collections up to the milestone. Booking confirmation alone does not create commission. The default designation allocation is up to 12% of eligible collection:

| Recipient | Default share |
| --- | --- |
| Marketing Manager | 7% |
| Deputy Manager | 2% |
| Assistant General Manager | 1% |
| Deputy General Manager | 1% |
| Deputy Director | 1% |
| Company | Remainder after employee shares |

The five designation rates are configurable, capped at 12% in total, and versioned; company retention is derived as the remainder. The active rule version and rate are snapshotted on each milestone batch. Only active eligible associates in the locked lead owner's reporting chain receive commissions; lower designations outside that chain are excluded, missing or inactive tiers are explicitly recorded as excluded, and shares are not redistributed. The milestone ledger prevents booking-stage credits and duplicate milestone commissions.

Wallet balance is cached but changes only in the same database transaction that appends the corresponding wallet transaction. Application routes do not provide direct balance edits. Financial corrections should use compensating ledger entries; ledger immutability is enforced by application workflow, not a database-level prohibition.

Payment and commission calculations must be deterministic and use integer minor units or database decimal types with an explicitly documented precision.

### 5.4 Audit Requirements

Audit every sensitive action, including:

- Account approval, rejection, activation, deactivation, and blocking
- Role and reporting-manager changes
- Plot edits, blocking, unblocking, reservation, booking, and cancellation
- Commission configuration changes
- Wallet credits, debits, and adjustments
- Withdrawal approvals and payment records
- Customer, visit, and booking status changes

Audit rows must retain actor, action, before value, after value, record identity, timestamp, IP address where available, and request identifier.

## 6. Security Architecture

### 6.1 Authentication

- Hash passwords using `password_hash()` and verify with `password_verify()`.
- Regenerate session IDs after login and privilege changes.
- Store session identifiers in secure, HTTP-only cookies.
- Set `Secure`, `HttpOnly`, and `SameSite=Lax` or stricter cookie attributes.
- Add absolute session expiry and idle timeout.
- Regenerate or invalidate OTP and password-reset tokens after use.
- Apply login and OTP attempt limits with IP and account scopes.
- Reject deactivated and blocked users before dashboard routing.

### 6.2 Authorization

- Use centralized role and permission checks.
- Use route middleware plus controller and service-level authorization.
- Scope all hierarchy queries to the authenticated user's allowed reporting descendants.
- Never trust role or employee ID values supplied by the browser.
- Protect all state-changing endpoints with CSRF tokens.
- Use POST/PUT/PATCH/DELETE as appropriate and validate HTTP methods.

### 6.3 Input and Output

- Use PDO prepared statements for every database query containing untrusted values.
- Validate values by type, length, format, and allowed options on the server.
- Return escaped HTML for all user-provided display values.
- Set JSON response headers and validate JSON before use.
- Do not concatenate SQL or output raw database error details.

### 6.4 Uploads

- Store uploads outside public PHP execution roots where possible.
- Allow only approved MIME types and extensions.
- Revalidate file signatures.
- Enforce maximum size.
- Generate random server filenames.
- Scan for image validity and strip metadata where applicable.
- Never execute or serve an uploaded file as executable content.

### 6.5 Configuration

Keep all secrets in environment variables or a protected Hostinger configuration file.

Never commit:

- Database credentials
- OTP credentials
- SMTP credentials
- SMS/WhatsApp API credentials
- Encryption keys
- Session secrets

The `.env` loader must not execute external code. No credential values may appear in JavaScript or public views.

### 6.6 Headers and Web Server

Recommended baseline headers:

```text
Content-Security-Policy
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy
Strict-Transport-Security (after confirmed HTTPS deployment)
```

Apache `.htaccess` must:

- Route all public requests through `public/index.php`
- Deny direct web access to `/app`, `/database`, `/storage`, `/docs`, and `/routes`
- Disable directory listing
- Reject sensitive file types where appropriate
- Enforce HTTPS in production

## 7. API Approach

No module is required to expose a public REST API. APIs should be added only for:

- Public project and plot information
- Public lead, enquiry, and visit submissions
- Authenticated dashboard actions
- Map status updates
- Notifications

Public APIs must:

- Use CSRF protection where browser-session-based
- Apply server-side validation
- Rate limit submissions
- Never expose private customer, wallet, audit, or employee data

## 8. Remaining Production Work and Business Decisions

The local application includes public content/forms, admin content management, unified Marketing Associate registration/approval/login, Admin-assigned designations and reporting, team dashboards, lead ownership history, associate booking and site-visit requests, 10-hour reservations, admin booking confirmation, versioned collection-based commission rules, verified collection and milestone workflows, commission batch review and wallet credits, filtered commission reports, withdrawal review/payout, notifications, and audit records. Production completion still requires:

1. Select an OTP/SMS provider and define resend, recovery, and account-lockout policies; OTP storage exists but OTP login/password reset are not implemented.
2. Define receipt evidence and payment reconciliation requirements; there is no payment gateway or bank reconciliation integration.
3. Confirm commission reversal accounting before production; missing reporting-chain tiers are recorded as exclusions and are not redistributed.
4. Configure and safely back up a production payout-encryption key; losing it prevents decryption of stored payout details.
5. Expand customer lifecycle management and reporting/analytics beyond contact editing and filtered CSV exports.
6. Define backup/restore, retention, migration rollback, production credential, and incident-response procedures.
7. Reconcile this generated development DDL with accounting, privacy, and reporting requirements before production use.

## 9. Potential Security Issues to Address

- **Web-root boundary:** root `.htaccess` now denies application, database, storage, and setup-script paths, but these rules have not yet been exercised under Apache. Prefer `public/` as Hostinger's document root.
- **Local secrets:** `.env` is ignored and contains XAMPP credentials plus a local encryption key; never deploy it. Production credentials and encryption keys must be provisioned outside the public directory.
- **Authentication scope:** admin and shared Marketing Associate password login, registration review, account status management, designation assignment, and reporting-chain scoping are implemented. Reporting-manager assignment is not required for approval or login. OTP delivery/password reset and account-scoped login throttling are not.
- **Financial workflows:** booking confirmation does not create commission. Admin-recorded collections must be verified, and commission is calculated once at agreement/registration against verified collection totals. Batch wallet credit is transactional and idempotent. Reversals affecting a commissioned milestone are blocked pending a separate audited correction workflow. These workflows are not a substitute for production accounting review; ledger immutability is application-enforced, not database-enforced.
- **Session and abuse controls:** sessions use HTTP-only, SameSite cookies and CSRF tokens. Lead requests use validation, consent, honeypot, per-session cooldown, and a database-backed IP limit. OTP brute-force protections are pending with OTP delivery.
- **Uploads:** amenity/gallery images are stored outside the web root, type-checked and re-encoded; brochure PDFs are signature-checked and served as attachments.
- **Public data:** public project queries are prepared and scoped by project; map URLs are limited to HTTPS Google Maps hosts. Database failure returns an unavailable response instead of synthetic inventory.
- **Privacy and retention:** customer records collect contact consent; payout-detail reveals are audited. Formal retention/deletion policy and administrator access-log review are still required.
- **Apache behavior:** direct config/migration exposure is addressed in root rules, but deployment and rewrite behavior must be tested with the actual Hostinger document root.
- **Operational disclosure:** do not return database errors, credentials, or internal identifiers to API clients; keep JSON response shape and status codes consistent.

## 10. Implementation Checklist

See [IMPLEMENTATION_CHECKLIST.md](IMPLEMENTATION_CHECKLIST.md) for the current feature and validation status. The remaining production blockers are OTP/password recovery, payment reconciliation and commission correction policy, focused automated security/concurrency tests, Apache/Hostinger verification, and schema review.

## 11. Approval Gate

The application is implemented against the user-authorized development baseline. Production deployment remains blocked pending the decisions and verification above.

Do not proceed to Prompt 2 or any application module until:

1. The development schema is reviewed and reconciled with production business rules.
2. OTP provider, commission settlement, booking payment/cancellation, and reversal policies are confirmed.
3. Remaining CRM/reporting modules, security/concurrency tests, and Hostinger setup are completed and reviewed.
