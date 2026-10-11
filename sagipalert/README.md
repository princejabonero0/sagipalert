# SagipAlert

Community Emergency Incident Reporting, Dispatch, Coordination, Response, and Documentation System for Minglanilla, Cebu.
Built with plain PHP (8.0+), PDO and MySQL/MariaDB. No framework or Composer needed.

## Requirements
- PHP 8.0+ with `pdo_mysql`, `fileinfo`, `mbstring`
- MySQL 5.7+ or MariaDB 10.3+
- Apache (XAMPP/WAMP/Laragon works) or `php -S`

## Install (XAMPP example)
1. Copy the `sagipalert` folder into `C:\xampp\htdocs\`.
2. Start Apache and MySQL in XAMPP.
3. Check `config.php` (default: user `root`, empty password, database `sagipalert`).
4. Open `http://localhost/sagipalert/setup.php` once. It creates the database, tables and demo accounts.
5. **Delete `setup.php`**, then log in at `http://localhost/sagipalert/`.

Without Apache: `php -S localhost:8000` inside the folder, then open `http://localhost:8000/setup.php`.
(The `.htaccess` protection of `storage/` and `inc/` only works on Apache; uploaded files are always served through `file.php` with an access check.)

## Demo accounts (change these passwords!)
| Role | Email | Password |
|---|---|---|
| Admin | admin@sagipalert.local | Admin@12345 |
| Dispatcher | dispatcher@sagipalert.local | Dispatch@12345 |
| Responder | responder@sagipalert.local | Respond@12345 |
| Resident | resident@sagipalert.local | Resident@12345 |

Residents can self-register. Dispatcher, responder and admin accounts are created by the admin under **Users**.

## How it maps to your capstone objectives
| Objective | Where |
|---|---|
| Incident reporting, location, evidence | `report.php` (type, description, barangay, address, GPS, photo/PDF) |
| Verification, priority and severity | `incident.php` (dispatcher: verify/reject, classify) |
| Dispatch and responder assignment | `incident.php` (assign responders with instructions) |
| Response coordination and status tracking | Responder accepts, posts updates, resolves; status history timeline |
| Documentation and records | Evidence and official documents per incident, outcome, printable record |
| Security and access control | Role-based access, bcrypt passwords, CSRF tokens, login lockout, prepared statements, upload validation, files served only via `file.php` |
| Notifications, reports, audit trail | `notifications.php`, `reports.php` (summary + CSV), `audit.php` |

## Workflow
`Reported` → (dispatcher verifies) `Verified` → (assign responder) `Assigned` → (responder accepts) `Responding` → `Resolved` → `Archived`. Unverifiable reports become `Rejected`.

## Customising
Edit `config.php` for the barangay list, incident types, upload limits and DB settings.

## Known limitations (consistent with your Scope and Limitations)
- Notifications are in-app only (no SMS/email).
- No automatic vehicle dispatch or integration with external government systems.
- Notifications refresh on page load (no live push).
