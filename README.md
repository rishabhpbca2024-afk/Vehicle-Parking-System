# SmartPark — Vehicle Parking Management System (Node.js, Express & MySQL)

A modern, functional, responsive, and production-ready **Vehicle Parking Management System** built with **Node.js**, **Express.js**, **MySQL**, **EJS**, and **Bootstrap 5**.

The system features real-time Indian vehicle plate validation, ACID transaction-safe slot allocation, PhonePe UPI QR code checkout simulation, thermal printable tickets and tax invoices, role-based access control (Admin & Operator), and a **1-Click Recruiter Demo Showcase**.

---

## ⚡ Quick Start (Node.js & MySQL)

### 1. Install Dependencies
```bash
npm install
```

### 2. Configure Environment (`.env`)
Make sure your `.env` contains your MySQL connection:
```env
DATABASE_URL="mysql://root@127.0.0.1:3306/parking_management"
DB_PASSWORD="your_mysql_password"
PORT=8000
SESSION_SECRET="smartpark_secret_key"
```

### 3. Start the Server
```bash
npm start
```
*Or double click **`start_server.bat`** (or `start_node.bat`)*

Then open your browser at:  
👉 **[http://127.0.0.1:8000](http://127.0.0.1:8000)**

---

## 🌟 Recruiter & Portfolio Live Demo Mode
When you open the login page (`http://127.0.0.1:8000/login`), you have instant 1-click access buttons:
- 🟢 **1-Click Admin Demo**: Auto-logs in as **Rishabh Pal** (Full analytics, rates configuration, reports & slot management).
- 🔵 **1-Click Operator Demo**: Auto-logs in as **Muskan Sharma** (Desk operations, vehicle entry, ticket printing, and checkout).

---

## 🌟 Key Features

1. **Automated Database Installer (`install.php`)**:
   - Zero manual SQL execution required.
   - Automatically parses `DATABASE_URL` and `DB_PASSWORD`.
   - Creates the database with `utf8mb4` encoding if permitted by host.
   - Graceful fallback for restricted shared hosting where databases are pre-created via cPanel/phpMyAdmin.
   - Idempotent schema creation (safe to run repeatedly without data loss).
   - Seeds editable default parking rates and starter parking slots.
   - Automatically locks down once an administrator account is initialized.

2. **First-Time Administrator Setup (`setup-admin.php`)**:
   - Secure interactive wizard to provision the initial owner account.
   - Uses `password_hash()` with `PASSWORD_DEFAULT`.
   - Permanently locked once initialized to prevent unauthorized re-registration.

3. **Concurrency-Safe Vehicle Entry (`vehicles/entry.php`)**:
   - Server-side plate normalization (automatic uppercase and whitespace trimming).
   - Duplicate active parking session prevention.
   - Row-level database transactions (`FOR UPDATE`) to prevent race conditions when two operators assign the same slot.
   - Automatic nearest available slot allocation or optional manual slot picking.
   - Snapshot tariff rate saved with session (protects past records against future rate revisions).

4. **Printable Parking Access Tickets (`vehicles/ticket.php`)**:
   - Thermal receipt & card layout with simulated barcode.
   - Ticket #, vehicle registration, slot, level, check-in timestamp, and rate notice.
   - Dedicated `@media print` styles for 80mm/58mm thermal printers or standard paper.

5. **Active Parked Vehicles Roster (`vehicles/active.php`)**:
   - Real-time elapsed time counter.
   - Authoritative live charge estimation based on billing increment rules.
   - Search by registration number or slot, vehicle type filter, and pagination.

6. **Exit Checkout & Billing (`vehicles/checkout.php`)**:
   - Server-calculated authoritative billing (browser amounts are never trusted).
   - Formula: Minimum floor hours + configurable billing increment quantum.
   - Transactional slot release and payment recording (CASH, UPI, CARD).
   - Printable official payment tax invoice / receipt (`payments/receipt.php`).

7. **Slot & Capacity Management (`slots/index.php`)**:
   - Switchable Visual Grid View and Detailed Table View.
   - Floor, vehicle type, and status filtering.
   - Immutability safety: Occupied slots cannot be deleted, marked out of service, or assigned to incompatible vehicle types while in use.

8. **Parking Tariff & Policy Configuration (`rates/index.php`)**:
   - Admin-only management of hourly rates, minimum billable hours, and increment steps for:
     - Two Wheelers (Bikes / Scooters)
     - Cars / Sedans
     - SUVs / MUVs
     - Heavy Commercial Vehicles / Trucks / Buses
   - Rate changes never retroactively modify charges for past or ongoing active sessions.

9. **Financial & Operational Audits (`reports/daily.php`, `reports/monthly.php`)**:
   - Daily reports with breakdown by vehicle category and payment instrument.
   - Day-by-day monthly revenue matrix.
   - Strictly counts verified `PAID` receipts.

10. **Application Security & Hardening**:
    - 100% PDO prepared statements preventing SQL Injection.
    - Synchronizer token pattern CSRF protection for all POST actions.
    - Context-aware XSS output escaping (`htmlspecialchars`).
    - Session fixation mitigation (`session_regenerate_id()`).
    - HTTP-only and SameSite cookie security flags.
    - Protected sensitive files via `.htaccess` (blocks `.env`, `.git`, `config/`, `database/`, and log files).

---

## 🛠️ Technology Stack

- **Backend**: Core PHP 8.1 or later (Vanilla, modular architecture; zero external composer dependencies).
- **Database**: MySQL 8.0+ or MariaDB 10.4+ (InnoDB engine with Foreign Keys and Indexes).
- **Frontend**: HTML5, CSS3, Bootstrap 5.3.3, Bootstrap Icons 1.11.3, Chart.js 4.4.2.
- **Development Environment**: XAMPP on Windows, WAMP, Laragon, or standalone Apache/PHP.

---

## 🚀 Quick Start Guide (XAMPP on Windows)

### Step 1: Place Project in XAMPP
Copy the project folder into your XAMPP `htdocs` directory:
```text
C:\xampp\htdocs\Vechile_MS
```
*(Or rename folder to `parking-management` if preferred)*

### Step 2: Start Apache & MySQL
1. Open the **XAMPP Control Panel**.
2. Click **Start** for **Apache**.
3. Click **Start** for **MySQL**.
4. Ensure both services show green status indicators.

### Step 3: Verify PHP PDO Extension
PDO and PDO MySQL are enabled by default in XAMPP. To double check:
1. Open `C:\xampp\php\php.ini` in a text editor.
2. Confirm the following lines are NOT commented out (no leading semicolon `;`):
   ```ini
   extension=pdo_mysql
   ```

### Step 4: Configure Environment (`.env`)
1. In the project root, copy `.env.example` to `.env`:
   ```powershell
   Copy-Item .env.example .env
   ```
2. Open `.env` and verify your connection parameters.

#### Local XAMPP Default Configuration:
For a standard local XAMPP setup (where MySQL user is `root` with no password):
```env
DATABASE_URL="mysql://root@127.0.0.1:3306/parking_management"
DB_PASSWORD=""
```

#### Precedence Rule for Credentials:
- If `DB_PASSWORD` is explicitly set in `.env` (even if blank `""`), it takes precedence over any password contained in `DATABASE_URL`.
- If `DB_PASSWORD` is omitted, credentials from `DATABASE_URL` (e.g. `mysql://user:secret@host:port/dbname`) are used.
- Password strings are safely URL-decoded and NEVER exposed in logs, stack traces, or templates.

---

## ⚙️ Running the Automated Database Installer

You can run the installer in **one of two convenient ways**:

### Method A: Via Web Browser (Recommended)
1. Open your web browser and navigate to:
   ```text
   http://localhost/Vechile_MS/install.php
   ```
   *(Or `http://localhost/parking-management/install.php`)*
2. The installer will display a visual prerequisites check (PHP version, PDO, PDO MySQL).
3. Click **Run Automated Database Setup**.
4. The system will:
   - Connect to the MySQL server.
   - Automatically create the database `parking_management` with `utf8mb4` character set.
   - Build all 6 tables (`users`, `parking_slots`, `vehicles`, `parking_rates`, `parking_sessions`, `payments`) with InnoDB constraints and foreign keys.
   - Seed default parking rates and starter parking slots.
   - Verify table queryability.
5. Click **Proceed to Create Initial Administrator**.

### Method B: Via Command Line (CLI)
If PHP is registered in your system PATH or using XAMPP's PHP binary:
```powershell
C:\xampp\php\php.exe install.php
```

---

## 👤 Creating the First Administrator Account

1. After the installer completes, navigate to:
   ```text
   http://localhost/Vechile_MS/setup-admin.php
   ```
2. Enter the initial administrator credentials:
   - **Administrator Full Name** (e.g. `System Administrator`)
   - **Email Address** (e.g. `admin@smartpark.local`)
   - **Password** (minimum 8 characters)
   - **Confirm Password**
3. Click **Create Administrator Account**.
4. **Security Lock**: Once created, `setup-admin.php` and `install.php` are permanently locked to protect against unauthorized account creation or credential resets.

---

## 🔑 Logging In & Everyday Operation

1. Navigate to:
   ```text
   http://localhost/Vechile_MS/login.php
   ```
2. Sign in with the administrator email and password you just created.
3. You will be redirected to the **Live Dashboard**.

### Daily Workflow Overview:
1. **Vehicle Entry**: Click **New Entry** in the top navbar or sidebar. Enter plate number, choose vehicle category, and optionally select a slot or allow auto-allocation. Click **Issue Parking Ticket**.
2. **Ticket Printing**: Print the thermal ticket and hand it to the vehicle driver.
3. **Active Parking**: View all currently parked vehicles, elapsed time, and real-time accrued charges under **Active Parking**.
4. **Checkout & Billing**: Click **Checkout** on any active vehicle. Review the authoritative duration and bill, select the payment method (`CASH`, `UPI`, or `CARD`), and click **Confirm Payment & Release Slot**.
5. **Print Receipt**: Hand the official tax receipt to the driver. The parking slot is instantly released back to `AVAILABLE`.

---

## 📁 Project Architecture & File Structure

```text
Vechile_MS/
├── .env.example              # Documented configuration template
├── .gitignore                # Protects .env, logs, and sensitive files
├── .htaccess                 # Apache security hardening rules
├── index.php                 # Smart router & state redirector
├── install.php               # Automated zero-SQL installer wizard
├── setup-admin.php           # Secure first-time administrator initialization
├── login.php                 # Session login with CSRF & active checks
├── logout.php                # Safe session destruction
├── profile.php               # Profile management & password change
├── error.php                 # Safe friendly error page
├── README.md                 # Setup & deployment documentation
├── config/
│   ├── env.php               # Standalone .env parser (zero dependencies)
│   ├── app.php               # Base URL resolver, session init, timezone
│   └── database.php          # Secure PDO database connector & URL parser
├── database/
│   └── schema.sql            # InnoDB DDL schema, relationships, indexes, seeds
├── includes/
│   ├── auth.php              # RBAC guards (require_login, require_admin)
│   ├── csrf.php              # Synchronizer token pattern CSRF utilities
│   ├── functions.php         # Business logic, billing formulas, escaping
│   ├── header.php            # Shared HTML head, CSS, typography
│   ├── sidebar.php           # Collapsible responsive navigation bar
│   ├── navbar.php            # Top bar, live digital clock, profile menu
│   └── footer.php            # Layout closing tags, Bootstrap JS, app.js
├── dashboard/
│   └── index.php             # Real-time metrics, Chart.js revenue trend
├── slots/
│   ├── index.php             # Slot grid/table view with floor/type filters
│   ├── create.php            # POST action for adding slots
│   ├── edit.php              # Edit slot details (locked when occupied)
│   ├── toggle_status.php     # Quick Out-of-Service toggle
│   └── delete.php            # Safe slot deletion with reference integrity
├── vehicles/
│   ├── entry.php             # Check-in console with ACID transaction locking
│   ├── ticket.php            # Thermal-formatted printable entry ticket
│   ├── active.php            # Currently parked vehicles roster & billing estimates
│   ├── checkout.php          # Checkout console with authoritative billing
│   └── history.php           # Comprehensive searchable session history
├── payments/
│   ├── index.php             # Audited payments transaction log
│   └── receipt.php           # Printable official tax payment receipt
├── rates/
│   └── index.php             # Tariff & billing rules manager (Admin only)
├── users/
│   ├── index.php             # Operator and Admin accounts roster
│   └── edit.php              # User role, activation, and password reset
├── reports/
│   ├── daily.php             # Daily operations & financial breakdown
│   └── monthly.php           # Month-by-month volume and revenue report
└── assets/
    ├── css/
    │   └── style.css         # Modern dark slate theme & print media CSS
    └── js/
        └── app.js            # Live clock, slot filtering, confirm dialogs
```

---

## 🗄️ Database Schema & Relationships

The database architecture is designed with **MySQL InnoDB** for relational integrity:

```mermaid
erDiagram
    users ||--o{ parking_sessions : "created_by"
    vehicles ||--o{ parking_sessions : "parks"
    parking_slots ||--o{ parking_sessions : "assigned_to"
    parking_sessions ||--|| payments : "settled_by"

    users {
        int id PK
        string name
        string email UK
        string password_hash
        enum role "ADMIN, OPERATOR"
        tinyint is_active
        datetime created_at
    }

    parking_slots {
        int id PK
        string slot_number
        string floor_number
        enum vehicle_type "TWO_WHEELER, CAR, SUV, HEAVY"
        enum status "AVAILABLE, OCCUPIED, OUT_OF_SERVICE"
        datetime created_at
    }

    vehicles {
        int id PK
        string registration_number UK
        enum vehicle_type
        datetime created_at
    }

    parking_rates {
        int id PK
        enum vehicle_type UK
        decimal hourly_rate
        int minimum_charge_hours
        int billing_increment_minutes
        tinyint is_active
    }

    parking_sessions {
        int id PK
        int vehicle_id FK
        int slot_id FK
        int created_by FK
        datetime entry_time
        datetime exit_time
        decimal rate_per_hour
        decimal amount
        enum status "ACTIVE, COMPLETED"
    }

    payments {
        int id PK
        int session_id FK
        decimal amount
        enum method "CASH, UPI, CARD"
        enum status "PAID, PENDING, FAILED"
        datetime paid_at
    }
```

---

## 💰 Documented Authoritative Billing Rule

When a vehicle checks out, charges are strictly calculated on the server using the following mathematical model:

1. **Elapsed Time Calculation**:
   $$\text{Duration (minutes)} = \max(1, \text{Exit Time} - \text{Entry Time})$$
2. **Quantum Increments**:
   Using the configured billing increment step (default: 60 minutes):
   $$\text{Increments Consumed} = \lceil \frac{\text{Duration (minutes)}}{\text{Increment Step}} \rceil$$
   $$\text{Calculated Hours} = \text{Increments Consumed} \times \frac{\text{Increment Step}}{60}$$
3. **Minimum Billable Hours**:
   $$\text{Billable Hours} = \max(\text{Minimum Charge Hours}, \text{Calculated Hours})$$
4. **Final Tariff**:
   $$\text{Total Amount} = \text{round}(\text{Billable Hours} \times \text{Snapshot Rate Per Hour}, 2)$$

*Example*: A car parks for 75 minutes at ₹30/hour with a 1-hour minimum charge and 60-minute increment.
- Increments consumed = $\lceil 75 / 60 \rceil = 2$.
- Billable hours = $\max(1, 2) = 2.0$ hours.
- Total charge = $2 \times 30 = \text{₹}60.00$.

---

## 🔧 Troubleshooting Common Issues

| Issue | Likely Cause | Resolution |
| :--- | :--- | :--- |
| **`SQLSTATE[HY000] [2002] Connection refused`** | MySQL service is not running | Open XAMPP Control Panel and start the **MySQL** service. |
| **`Access denied for user 'root'@'localhost'`** | Incorrect password in `.env` | Update `DB_PASSWORD=""` in `.env` to match your local MySQL configuration. |
| **`Call to undefined function PDO`** | Missing PHP PDO MySQL driver | In `php.ini`, ensure `extension=pdo_mysql` is uncommented, then restart Apache. |
| **`CREATE DATABASE permission denied`** | Hosted MySQL user has restricted database creation rights | Create a database named `parking_management` manually via cPanel / phpMyAdmin, and the installer will install tables into the existing database. |
| **"Cannot delete slot with historical records"** | Foreign Key RESTRICT constraint | Slots with past parking sessions preserve audit logs. Mark the slot as **Out of Service** instead of deleting. |
| **"Vehicle already has an active session"** | Duplicate entry attempt | Check **Active Parking** to ensure the vehicle completes checkout before re-entering. |

---

## 🛡️ Production Deployment Checklist

1. **Environment Flag**: In `.env`, change `APP_ENV="development"` to `APP_ENV="production"` to disable on-screen error traces and enable private logging.
2. **HTTPS / SSL**: Deploy behind an SSL certificate. The session engine automatically enables the `Secure` cookie flag when HTTPS is detected.
3. **Lock Installer**: Verify `setup-admin.php` and `install.php` are locked. For enhanced security on public servers, you may also restrict access to `install.php` in your web server config.
4. **Directory Security**: Ensure the provided `.htaccess` is active on Apache with `AllowOverride All` enabled.

---

## 📄 License
This project is open-source and free for educational and commercial parking facility use.
