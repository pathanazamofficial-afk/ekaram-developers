# EK Aram Developers - Implementation Checklist

## Completed local foundation

- [x] PHP/XAMPP/MariaDB environment diagnosed
- [x] VS Code PHP validation configured; `.env` excluded from source control
- [x] GD and ZIP enabled in XAMPP PHP CLI configuration
- [x] Added a user-authorized 24-table local development schema
- [x] Created the local `ekaram_developers` database and imported the schema
- [x] Verified PDO connectivity, 24 tables, six roles, and 100% default commission allocation
- [x] Added secure configuration, PDO, session, CSRF, output escaping, and upload helpers
- [x] Added database-backed public content and enquiry/callback/site-visit request endpoints
- [x] Added admin sign-in and project/specification/plot/amenity/gallery management screens
- [x] Unified all team accounts under the Marketing Associate role, portal, and login
- [x] Added lead assignment/status and follow-up workflows
- [x] Added 10-hour plot reservation, admin confirmation/cancellation, and CLI expiry processing
- [x] Added additive commission/lead workflow migration for verified collections, milestone batches, ownership and recruitment history, and visit logistics
- [x] Removed booking-confirmation commission generation; added agreement/registration commission batch creation, review, holds/rejections, and transactional wallet credits
- [x] Added Admin-only designation allocation with change history; existing reporting links remain intact
- [x] Added explicit Admin approve/reject actions, required rejection reason, and persisted decision actor/timestamps
- [x] Simplified registration to designation allocation and profile approval; successful approval notifies the Admin dashboard
- [x] Added hashed, single-use seven-day invitations that do not grant a role or designation; invitees remain pending until Admin approval
- [x] Added Marketing Associate booking and site visit requests, admin notifications, and visit rescheduling/vehicle assignment
- [x] Added commission CSV reports with date, customer, plot, lead owner, recipient, stage, and status filters
- [x] Added encrypted withdrawal details, balance reservations, approvals/payout ledger, and audit events
- [x] Added admin/associate notification views and dashboard operational counts
- [x] Added customer contact management and filtered CSV reports
- [x] Added local CLI bootstrap for the first admin account

## Current validation

- [x] PHP syntax check passed for 72 PHP files after the unified-account migration
- [x] Updated commission workflow smoke test passes with a common account role and five assigned designations
- [x] Approval workflow test verifies assignment stays pending, approval/rejection actor and times persist, and rejection without a reason is refused
- [x] Recruitment invitations use hashed tokens, expire after seven days, are single-use, and never assign a designation
- [x] Local PHP server returned HTTP 200 for the homepage and both registration pages
- [x] Local public page and static assets smoke test
- [x] Enquiry POST returned success; invalid CSRF returned 403; rapid repeat returned 429
- [x] Admin and Marketing Associate sign-in/dashboard smoke tests
- [x] Local database migrated to the shared account/designation model; no legacy rank-role accounts remain
- [x] Lead assignment, owner-lock history, follow-up, reservation, and booking confirmation without booking-stage commission
- [x] Rollback-only smoke test passed for verified collection milestones, batch review/holds, wallet ledger credits, and duplicate-milestone rejection
- [x] Encrypted payout detail round-trip, withdrawal approval/payment, and wallet debit
- [x] Reservation expiry CLI job releases an expired plot
- [x] Removed temporary integration-test users, customers, projects, bookings, and ledger data
- [ ] Verify root and `public/` `.htaccess` rules through Apache

## Remaining before full CRM completion

- [x] Employee registration/login and account approval/activation/blocking
- [x] Reporting hierarchy and manager-scoped dashboards
- [ ] OTP provider integration and password reset
- [x] 10-hour reservation transaction and admin booking approval workflow
- [x] Commission calculation from verified collections at agreement and registration milestones
- [x] Wallet ledger entries and withdrawal approval/payout
- [x] Lead assignment/follow-up and notifications
- [x] Customer contact management and report/export workflows
- [x] Filtered commission reporting and team-level performance summary
- [ ] Focused automated tests for auth, IDOR boundaries, concurrency, and financial invariants
- [ ] Confirm payment/cancellation/commission reversal policies and reconcile the development schema before production
- [ ] Complete Hostinger deployment and backup procedure

## Database status

The local database has the unified Marketing Associate and approval-audit migrations applied after the commission and recruitment migrations. Back up any other target database before applying `database/unified_marketing_associate_migration.sql` and then `database/marketing_associate_approval_audit_migration.sql`; first ensure its Marketing Associate, commission/team, and recruitment-invite schemas are present. Apply each migration only once. The approval-audit migration adds rejection actor, timestamp, and reason fields; it does not add a table.

## Important

`database/dev_baseline.sql` is a development schema created at the user's request; it is not an approved production migration. The old `database/phase2_public_schema.sql` is a four-table starter. Do not deploy the local `.env` or use development/test content as production data.
