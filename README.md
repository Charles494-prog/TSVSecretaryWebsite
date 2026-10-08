# TSV Officer Masterlist

A simple PHP/MySQL website for maintaining TSV officer records and downloading a DOCX masterlist.

## Requirements

- XAMPP with Apache, PHP, and MySQL/MariaDB
- PHP PDO MySQL extension enabled

## Setup

1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Open phpMyAdmin at `http://localhost/phpmyadmin`.
3. Import `database.sql`. It creates the `tsv_secretary` database, `officers` table, and database-backed signatory settings.
4. If your MySQL credentials are not the XAMPP defaults, update `$dbUser` and `$dbPassword` in `db.php`.
5. Open `http://localhost/tsv_secretary_website/`.

The app supports creating, editing, deleting, searching, and filtering officer records. Click an officer's status badge to switch between active and inactive; the change is saved without reloading the page. The status filter applies as soon as it is changed. Officer roles are limited to Locale TSV Lead, Locale TSV Assistant Lead, Locale TSV Secretary, and Locale TSV Officer ng Officers.

Use **Form generator** to open the weekly/monthly print selection portal. Weekly forms are selected by default, and changes are saved to the database. **Generate masterlist DOCX** downloads a Word document containing all officers, regardless of the current search or status filter. The named weekly/monthly forms are listed in the portal; their document templates and autofill rules must be provided before those individual forms can be generated.

Use **Edit signatories** to update the Pastor, PD Tagasubaybay, TSV Lead, Assistant TSV Lead, and TSV Secretary names stored in the database. If you already imported an earlier version of the database, import the updated `database.sql` to add the print selections and new signatory settings; existing values and officer records are preserved.

To extend an existing database for the imported masterlist fields, run `migrate_masterlist_columns.sql` once in phpMyAdmin before using this version. It adds roster number, Telegram contact, and raw source text for incomplete dates without removing existing fields.

The default XAMPP database account is often `root` with no password. Set a password for MySQL and update `db.php` before exposing the site beyond your local machine. This starter app does not include user login or role-based access.
