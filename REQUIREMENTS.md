# SOFTWARE REQUIREMENTS SPECIFICATION (SRS)
# Tunza Waleti — Digital Savings Wallet System

**Document Version:** 1.0  
**Date:** September 20, 2026  
**Project Type:** Industrial Practical Training (IPT) Project  
**Classification:** Internal / Academic  

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Overall Description](#2-overall-description)
3. [User Roles & Stakeholders](#3-user-roles--stakeholders)
4. [Functional Requirements](#4-functional-requirements)
   - 4.1 [Authentication & User Management](#41-authentication--user-management)
   - 4.2 [Customer Dashboard](#42-customer-dashboard)
   - 4.3 [Savings & Deposits](#43-savings--deposits)
   - 4.4 [Withdrawals](#44-withdrawals)
   - 4.5 [Transaction History](#45-transaction-history)
   - 4.6 [Goals & Savings Plans](#46-goals--savings-plans)
   - 4.7 [Support System](#47-support-system)
   - 4.8 [Admin Panel](#48-admin-panel)
   - 4.9 [Customer Care Panel](#49-customer-care-panel)
   - 4.10 [Transaction Officer Panel](#410-transaction-officer-panel)
   - 4.11 [Notifications & SMS](#411-notifications--sms)
5. [Non-Functional Requirements](#5-non-functional-requirements)
6. [System Architecture](#6-system-architecture)
7. [Database Requirements](#7-database-requirements)
8. [API Requirements](#8-api-requirements)
9. [Security Requirements](#9-security-requirements)
10. [Technology Stack](#10-technology-stack)
11. [Constraints & Assumptions](#11-constraints--assumptions)
12. [Glossary](#12-glossary)

---

## 1. Introduction

### 1.1 Purpose
This Software Requirements Specification (SRS) describes the functional and non-functional requirements for **Tunza Waleti**, a web-based digital savings wallet platform. The system enables users to deposit money, lock savings for a defined period, and withdraw upon maturity — promoting disciplined saving behaviour.

### 1.2 Project Scope
Tunza Waleti is a PHP/MySQL web application that provides:
- A customer-facing savings wallet with deposit, withdrawal, and goal-tracking features.
- An admin panel for user management, transaction oversight, and system configuration.
- A customer care portal for handling support tickets and sending SMS notifications.
- A transaction officer portal for reviewing, approving, and escalating transactions.

### 1.3 Intended Audience
| Audience | Purpose |
|----------|---------|
| Developers | Implementation guidance |
| QA Testers | Test case derivation |
| Project Supervisors / Lecturers | Academic evaluation |
| System Administrators | Deployment & maintenance |
| End Users | Understanding of features |

### 1.4 Definitions & Acronyms
| Term | Definition |
|------|-----------|
| SRS | Software Requirements Specification |
| IPT | Industrial Practical Training |
| PDO | PHP Data Objects (database abstraction layer) |
| CSRF | Cross-Site Request Forgery |
| XSS | Cross-Site Scripting |
| SMS | Short Message Service |
| M-Pesa | Mobile money payment service |
| Maturity Date | The date on which a locked savings plan can be withdrawn |

---

## 2. Overall Description

### 2.1 Product Perspective
Tunza Waleti is a standalone web application accessed via a browser. It integrates with an SMS gateway (Africa's Talking / Twilio) and a mobile money payment gateway (M-Pesa) to facilitate real-world financial transactions.

### 2.2 Product Features (Summary)
- Secure user registration and login
- Digital wallet with real-time balance tracking
- Savings deposit with configurable lock-in periods (1, 3, 6, 12 months)
- Maturity-based withdrawal restrictions
- Savings goal creation and progress tracking
- SMS and in-app notifications
- Multi-role admin system (Admin, Customer Care, Transaction Officer)
- Support ticket system
- Comprehensive transaction audit logs

### 2.3 Operating Environment
- **Server:** Apache 2.4+ / Nginx (XAMPP for local development)
- **Backend Language:** PHP 8.0+
- **Database:** MySQL 8.0+
- **Client:** Modern web browsers (Chrome, Firefox, Edge, Safari)
- **Network:** HTTPS (SSL/TLS required for production)

---

## 3. User Roles & Stakeholders

| Role | Description |
|------|-------------|
| **Customer** | A registered end-user who manages their own wallet, deposits, withdrawals, and savings goals. |
| **Admin** | Has full system access — manages users, roles, transactions, staff, and system settings. |
| **Customer Care Agent** | Handles support tickets, views customer information, and sends SMS notifications to users. |
| **Transaction Officer** | Reviews and manages pending/escalated transactions; cannot modify user account data. |
| **System (Automated)** | Performs scheduled jobs such as maturity checks, SMS reminders, and balance updates. |

---

## 4. Functional Requirements

### 4.1 Authentication & User Management

#### REQ-AUTH-001: User Registration
- The system shall allow new users to register with: full name, email address, phone number, and password.
- The system shall validate that the email and phone number are unique.
- The system shall hash passwords using `bcrypt` before storage.
- The system shall send a verification SMS or email upon successful registration.

#### REQ-AUTH-002: User Login
- The system shall authenticate users via email/phone and password.
- The system shall implement rate limiting (maximum 5 failed attempts before a temporary lockout).
- The system shall create a secure session on successful login.
- The system shall support a "Remember Me" option using persistent cookies.

#### REQ-AUTH-003: Password Management
- The system shall provide a "Forgot Password" flow via email/SMS OTP.
- The system shall allow authenticated users to change their password.
- The system shall enforce a minimum password strength (8+ characters, mixed case, digit required).

#### REQ-AUTH-004: User Profile Management
- Users shall be able to view and update their profile (name, phone, profile photo).
- Users shall be able to request account deletion.

#### REQ-AUTH-005: Logout
- The system shall destroy the session and invalidate cookies on logout.
- The system shall redirect the user to the login page.

#### REQ-AUTH-006: Role-Based Access Control (RBAC)
- The system shall enforce role-based access, ensuring each user only accesses pages permitted to their role.
- Roles: `customer`, `admin`, `customer_care`, `transaction_officer`.
- The admin shall be able to assign and change user roles.

---

### 4.2 Customer Dashboard

#### REQ-DASH-001: Balance Overview
- The dashboard shall display the user's current total wallet balance.
- The dashboard shall display total amount in active (locked) savings.
- The dashboard shall display total amount in matured (withdrawable) savings.

#### REQ-DASH-002: Quick Actions
- The dashboard shall provide quick-access buttons for: Deposit, Withdraw, View History, and Add Goal.

#### REQ-DASH-003: Savings Summary
- The dashboard shall list all active savings plans with: amount, period, start date, maturity date, and status.
- A visual progress bar shall show the time elapsed towards maturity for each savings plan.

#### REQ-DASH-004: Recent Transactions
- The dashboard shall display the last 5–10 transactions (type, amount, date, status).

---

### 4.3 Savings & Deposits

#### REQ-DEP-001: Create Deposit / New Savings
- Users shall be able to deposit an amount into a new savings plan.
- Users shall select a savings lock-in period: 1 month, 3 months, 6 months, or 12 months.
- The system shall automatically calculate and display the maturity date before confirmation.
- The minimum deposit amount shall be configurable by the admin (default: KES 100 / TZS 1,000).
- The maximum deposit amount per transaction shall be configurable by the admin.

#### REQ-DEP-002: Payment Integration
- Deposits shall be initiated via M-Pesa STK Push (or equivalent mobile money).
- The system shall verify payment confirmation from the payment gateway before creating a savings record.
- The system shall generate a unique reference number for each deposit transaction.

#### REQ-DEP-003: Savings Status
- Savings status shall progress through: `pending → active → matured → withdrawn`.
- The system shall automatically update status from `active` to `matured` on the maturity date via a scheduled job (CRON).

#### REQ-DEP-004: Terms & Conditions
- Users shall be required to accept terms and conditions before confirming a deposit.
- The system shall record the timestamp of the user's acceptance.

---

### 4.4 Withdrawals

#### REQ-WITH-001: Matured Savings Withdrawal
- Users shall only be able to withdraw from savings plans with status `matured`.
- Attempting to withdraw from an active (non-matured) plan shall display a clear error message with the remaining lock-in time.

#### REQ-WITH-002: Withdrawal to Mobile Money
- Users shall provide their M-Pesa / mobile money number for withdrawal.
- The system shall initiate a payment disbursement to the user's mobile number.
- The system shall update the savings status to `withdrawn` only upon confirmed disbursement.

#### REQ-WITH-003: Withdrawal Verification
- For amounts above a configurable threshold, the system shall require an SMS OTP (2FA) before processing.

#### REQ-WITH-004: Withdrawal Receipt
- The system shall generate a withdrawal receipt (displayed on-screen and optionally sent via SMS) with: amount, date, reference number, and account balance.

---

### 4.5 Transaction History

#### REQ-HIST-001: Full History View
- Users shall be able to view a paginated list of all their transactions.
- Each record shall include: date/time, type (deposit/withdrawal/reversal), amount, reference number, status, and description.

#### REQ-HIST-002: Filtering & Search
- Users shall be able to filter transaction history by: date range, transaction type, and status.

#### REQ-HIST-003: Export
- Users shall be able to export their transaction history as a CSV or PDF file.

---

### 4.6 Goals & Savings Plans

#### REQ-GOAL-001: Create Goal
- Users shall be able to create a named savings goal (e.g., "School Fees", "Land Purchase").
- Each goal shall have: name, target amount, target date, and optional description.

#### REQ-GOAL-002: Track Goal Progress
- The system shall display the progress of each goal: amount saved vs. target amount.
- A visual progress bar shall be shown on the goals page and dashboard.

#### REQ-GOAL-003: Link Deposits to Goals
- Users shall optionally link a deposit to a specific goal.

---

### 4.7 Support System

#### REQ-SUP-001: Submit Support Ticket
- Customers shall be able to submit support tickets from within their dashboard.
- A ticket shall include: subject, message/description, and priority level.

#### REQ-SUP-002: Track Ticket Status
- Customers shall be able to view the status of their submitted tickets: `open`, `in progress`, `resolved`, `closed`.

#### REQ-SUP-003: Ticket Responses
- Customer care agents shall be able to respond to tickets.
- The customer shall receive an SMS or in-app notification when a ticket is updated.

---

### 4.8 Admin Panel

#### REQ-ADMIN-001: Admin Dashboard
- The admin dashboard shall display: total registered users, total deposits (today/this month), total withdrawals, total active savings, pending transactions, and system alerts.

#### REQ-ADMIN-002: User Management
- The admin shall be able to: view all users, search/filter users, view individual user profiles and transaction history, activate/deactivate accounts, and delete accounts.

#### REQ-ADMIN-003: Role Management
- The admin shall be able to assign and modify user roles (customer, customer_care, transaction_officer, admin).

#### REQ-ADMIN-004: Transaction Management
- The admin shall be able to view all system transactions with filtering by date, type, user, and status.
- The admin shall be able to manually reverse or flag transactions with an audit trail.

#### REQ-ADMIN-005: System Settings
- The admin shall be able to configure: minimum/maximum deposit amounts, SMS gateway credentials, payment gateway credentials, interest rates (if applicable), and maintenance mode.

#### REQ-ADMIN-006: Staff Management
- The admin shall be able to add, edit, and deactivate staff accounts (customer_care, transaction_officer).

#### REQ-ADMIN-007: Reports & Analytics
- The admin shall be able to generate reports: user growth, deposit volume, withdrawal volume, and savings completion rates.

---

### 4.9 Customer Care Panel

#### REQ-CC-001: View Customer Information
- Customer care agents shall be able to search for and view customer profiles and basic account information.
- They shall NOT have access to full wallet balances or transaction details (read-only on basic profile).

#### REQ-CC-002: Ticket Management
- Agents shall view all open and assigned tickets.
- Agents shall respond to, escalate, and close tickets.

#### REQ-CC-003: Send SMS
- Agents shall be able to compose and send a custom SMS notification to a specific customer or a group.

#### REQ-CC-004: Ticket Dashboard
- The customer care dashboard shall show: total open tickets, tickets assigned to the agent, tickets resolved today, and average resolution time.

---

### 4.10 Transaction Officer Panel

#### REQ-TO-001: Transaction Review
- Transaction officers shall be able to view pending and flagged transactions.
- They shall be able to approve or reject flagged transactions with a reason.

#### REQ-TO-002: Escalations
- The transaction officer shall be able to escalate suspicious or high-value transactions to the admin.
- An escalation log shall record: officer name, reason, timestamp, and admin response.

#### REQ-TO-003: Transaction Dashboard
- The dashboard shall show: total transactions today, pending review count, flagged transaction count, and recently approved/rejected items.

---

### 4.11 Notifications & SMS

#### REQ-NOTIF-001: SMS Notifications
- The system shall send SMS notifications for: successful deposit, successful withdrawal, savings maturity (reminder 7 days before and on the day), OTP for 2FA, and support ticket updates.

#### REQ-NOTIF-002: In-App Notifications
- The system shall display in-app notifications/alerts for key events on the user dashboard.

#### REQ-NOTIF-003: Notification Log
- All sent notifications shall be logged in the database with: recipient, message, channel (SMS/email), timestamp, and delivery status.

#### REQ-NOTIF-004: Maturity Alerts (Automated)
- A scheduled CRON job shall run daily to identify savings plans maturing within 7 days and send SMS reminders, and update status of newly matured savings.

---

## 5. Non-Functional Requirements

### 5.1 Performance
| Requirement | Target |
|-------------|--------|
| Page load time | < 3 seconds under normal load |
| API response time | < 1 second |
| Concurrent users supported | 500+ simultaneous users |
| Database query time | < 500ms for 90% of queries |
| System uptime | 99.9% availability |

### 5.2 Scalability
- The system architecture shall support horizontal scaling (adding more servers).
- The database shall be designed with indexing strategies for tables expected to hold 1,000,000+ records.
- The system shall be configurable for 10,000+ registered users without re-architecture.

### 5.3 Reliability & Availability
- All financial transactions shall use database transactions with commit/rollback to ensure data consistency.
- The system shall implement daily automated database backups.
- A rollback plan shall be documented for each deployment.

### 5.4 Security
- All passwords shall be hashed using `password_hash()` with `PASSWORD_BCRYPT`.
- All database queries shall use PDO prepared statements to prevent SQL injection.
- All user-facing outputs shall be sanitized with `htmlspecialchars()` to prevent XSS.
- CSRF tokens shall be generated and validated on all forms.
- Sessions shall be secured: HTTPS only, HttpOnly, and SameSite=Strict cookie flags.
- Rate limiting: maximum 5 login attempts per IP per minute.
- Sensitive operations (withdrawals above threshold) shall require SMS OTP (2FA).
- Full audit trail for all admin and transaction officer actions.

### 5.5 Usability
- The interface shall be mobile-responsive (mobile-first design).
- Core user flows (deposit, withdraw) shall be completable in fewer than 4 steps.
- All error messages shall be descriptive and user-friendly.

### 5.6 Maintainability
- Code shall follow PSR-12 coding standards for PHP.
- All functions and classes shall be documented with PHPDoc comments.
- The codebase shall be managed using Git version control with meaningful commit messages.
- Environment-specific configuration shall be stored in `.env` files, not hardcoded.

### 5.7 Compliance
- The system shall comply with relevant data protection regulations (local data privacy laws).
- Financial transaction logs shall be retained for a minimum of 7 years.
- User consent for data processing shall be obtained and recorded during registration.

---

## 6. System Architecture

```
+----------------------------------------------------------+
|                      CLIENT LAYER                        |
|   Web Browser (Chrome / Firefox / Edge / Safari)         |
|   Pages: Login, Dashboard, Deposit, Withdraw, History,   |
|           Goals, Support, Admin Panel, Customer Care      |
+-------------------------+--------------------------------+
                          | HTTPS
+-------------------------v--------------------------------+
|                 PRESENTATION LAYER                       |
|   HTML5 / CSS3 / JavaScript (Vanilla JS)                 |
|   Responsive layouts, AJAX calls, Form validation        |
+-------------------------+--------------------------------+
                          |
+-------------------------v--------------------------------+
|                  APPLICATION LAYER                       |
|   PHP 8.0+                                               |
|   - Authentication & Session Management                  |
|   - Savings & Wallet Logic                               |
|   - Transaction Processing                               |
|   - Role-Based Access Control                            |
|   - REST API Endpoints                                   |
|   - SMS & Payment Gateway Integration                    |
+-------------------------+--------------------------------+
                          |
+-------------------------v--------------------------------+
|                    DATA LAYER                            |
|   MySQL 8.0+                                             |
|   - users, wallets, savings, transactions                |
|   - notifications, tickets, audit_logs                   |
|   - settings, staff_roles                                |
+----------------------------------------------------------+
          |                              |
+---------v------------------+  +--------v-----------------+
|  SMS Gateway               |  |  Payment Gateway         |
|  Africa's Talking / Twilio |  |  M-Pesa STK Push         |
|                            |  |  (Daraja API)            |
+----------------------------+  +--------------------------+
```

### 6.1 Folder Structure
```
tunza-waleti/
+-- index.php                  # Landing / Login page
+-- login.php                  # Login handler
+-- register.php               # User registration
+-- pages/                     # Customer-facing pages
|   +-- dashboard.php
|   +-- deposit.php
|   +-- withdraw.php
|   +-- history.php
|   +-- profile.php
|   +-- add_goal.php
|   +-- support.php
|   +-- logout.php
+-- admin/                     # Admin panel pages
|   +-- dashboard.php
|   +-- users.php
|   +-- transactions.php
|   +-- settings.php
|   +-- manage_role.php
|   +-- login.php
|   +-- customer_care/         # Customer care sub-panel
|   |   +-- dashboard.php
|   |   +-- tickets.php
|   |   +-- send_sms.php
|   |   +-- users_view.php
|   +-- transaction_officer/   # Transaction officer sub-panel
|       +-- dashboard.php
|       +-- transactions.php
|       +-- escalations.php
+-- api/                       # REST API endpoints
|   +-- auth.php
|   +-- savings.php
|   +-- transactions.php
|   +-- notifications.php
+-- database/                  # DB connection & config
|   +-- config.php
|   +-- database.php
|   +-- functions.php
|   +-- auth.php
+-- includes/                  # Shared page components
|   +-- header.php
+-- assets/                    # Static assets
|   +-- css/
|   +-- js/
|   +-- images/
+-- sql/                       # Database schema & seeds
```

---

## 7. Database Requirements

### 7.1 Core Tables

| Table | Description |
|-------|-------------|
| `users` | Stores all registered users and staff (role-differentiated) |
| `wallets` | Stores each user's wallet balance |
| `savings` | Individual savings plans (deposits with lock-in periods) |
| `transactions` | Ledger of all financial transactions |
| `goals` | User-defined savings goals |
| `notifications` | Log of all SMS and in-app notifications sent |
| `support_tickets` | Customer support tickets |
| `ticket_responses` | Responses on support tickets |
| `escalations` | Transaction officer escalation records |
| `audit_logs` | Immutable log of all admin/officer actions |
| `settings` | System-wide configurable settings (key-value) |

### 7.2 Key Relationships
```
users       (1) ----< (many) savings
users       (1) ----< (many) transactions
users       (1) ----< (many) goals
savings     (1) ----< (many) transactions
users       (1) ----< (many) support_tickets
support_tickets (1) ----< (many) ticket_responses
transactions (1) ----< (1)   escalations
```

### 7.3 Key Fields per Table

**users**
```
id, full_name, email, phone, password_hash, role,
is_active, is_verified, created_at, updated_at
```

**savings**
```
id, user_id, amount, period_months, start_date,
maturity_date, status (pending|active|matured|withdrawn),
reference, goal_id (nullable), created_at
```

**transactions**
```
id, user_id, savings_id, type (deposit|withdrawal|reversal),
amount, reference, description, status (pending|success|failed),
created_at
```

**support_tickets**
```
id, user_id, subject, message, priority (low|medium|high|critical),
status (open|in_progress|resolved|closed), assigned_to,
created_at, updated_at
```

---

## 8. API Requirements

### 8.1 REST API Endpoints

| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| POST | `/api/auth/register` | Register a new user | No |
| POST | `/api/auth/login` | Login and create session | No |
| POST | `/api/auth/logout` | Destroy session | Yes |
| GET | `/api/user/profile` | Get authenticated user profile | Yes |
| PUT | `/api/user/profile` | Update user profile | Yes |
| POST | `/api/savings/deposit` | Create a new savings deposit | Yes |
| GET | `/api/savings/active` | List all active savings plans | Yes |
| GET | `/api/savings/matured` | List all matured savings plans | Yes |
| POST | `/api/savings/withdraw` | Initiate withdrawal from matured savings | Yes |
| GET | `/api/transactions` | Get paginated transaction history | Yes |
| POST | `/api/notifications/sms` | Send SMS (admin/CC only) | Admin/CC |
| GET | `/api/dashboard/stats` | Get dashboard summary statistics | Yes |
| GET | `/api/goals` | Get user goals | Yes |
| POST | `/api/goals` | Create a new goal | Yes |

### 8.2 Response Format
All API responses shall use JSON with the following structure:
```json
{
  "success": true,
  "message": "Human-readable message",
  "data": {},
  "errors": null
}
```

---

## 9. Security Requirements

| Requirement | Implementation |
|-------------|----------------|
| Password hashing | `password_hash()` with `PASSWORD_BCRYPT` |
| SQL Injection prevention | PDO prepared statements throughout |
| XSS prevention | `htmlspecialchars()` on all rendered output |
| CSRF protection | Per-session token on all state-changing forms |
| Session security | HTTPS-only, HttpOnly, SameSite=Strict cookies |
| Rate limiting | Max 5 login attempts/IP/minute; lockout for 15 min |
| 2FA (withdrawals) | SMS OTP for withdrawals above configurable threshold |
| Audit logging | Immutable log of all admin and officer actions |
| File upload security | Validation of type & size; store outside web root |
| Error handling | Production errors logged, not displayed to users |
| HTTPS enforcement | HTTP to HTTPS redirect enforced at server level |

---

## 10. Technology Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Frontend | HTML5, CSS3, Vanilla JavaScript | Latest |
| Backend | PHP | 8.0+ |
| Database | MySQL | 8.0+ |
| Web Server | Apache (XAMPP for dev) / Nginx (production) | 2.4+ |
| SMS Gateway | Africa's Talking / Twilio | Latest API |
| Payment Gateway | Safaricom Daraja API (M-Pesa) | v2 |
| Version Control | Git / GitHub | Latest |
| Hosting (prod) | AWS / Azure / DigitalOcean | — |
| Security | SSL/TLS, bcrypt, PDO | — |

---

## 11. Constraints & Assumptions

### 11.1 Constraints
- The system is developed as an IPT (academic) project; production deployment with live payments is subject to regulatory approval.
- SMS gateway and M-Pesa integration require API keys from the institution/organization.
- The system is web-only (no native mobile apps in this version).
- Development is performed locally using XAMPP.

### 11.2 Assumptions
- All users have access to a mobile phone for SMS OTP.
- Internet connectivity is available for all users.
- The institution will provide or approve credentials for SMS and payment gateways.
- Academic/mock data may be used in lieu of live payment integration during development.

---

## 12. Glossary

| Term | Definition |
|------|-----------|
| **Savings Plan** | A time-locked deposit made by a user that cannot be withdrawn until the maturity date. |
| **Maturity Date** | The date on which a savings plan unlocks and becomes withdrawable. |
| **Wallet Balance** | The total free funds available to a user (excluding locked savings). |
| **Deposit** | Adding funds to a savings plan via mobile money. |
| **Withdrawal** | Retrieving funds from a matured savings plan. |
| **Escalation** | A transaction flagged by a transaction officer for admin review. |
| **OTP** | One-Time Password sent via SMS for two-factor authentication. |
| **STK Push** | Safaricom's M-Pesa payment prompt sent to a user's phone. |
| **CRON Job** | A scheduled automated task run on the server at defined intervals. |
| **RBAC** | Role-Based Access Control — users only access features permitted to their role. |

---

*Document prepared by the Tunza Waleti Development Team.*  
*This is a living document and may be updated as the project evolves.*
