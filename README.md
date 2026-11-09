# TSV Officer Masterlist

A simple PHP/MySQL website for maintaining TSV officer records and downloading a DOCX masterlist.

## Requirements

- XAMPP with Apache, PHP, and MySQL/MariaDB
- PHP PDO MySQL, DOM, and zlib extensions enabled

## Setup

1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Open phpMyAdmin at `http://localhost/phpmyadmin`.
3. Import `database.sql`. It creates the `tsv_secretary` database, `officers` table, and database-backed signatory settings.
4. If your MySQL credentials are not the XAMPP defaults, update `$dbUser` and `$dbPassword` in `db.php`.
5. Open `http://localhost/tsv_secretary_website/`.

The app supports creating, editing, deleting, searching, and filtering officer records. Click an officer's status badge to switch between active and inactive; the change is saved without reloading the page. The status filter applies as soon as it is changed. Officer roles are limited to Locale TSV Lead, Locale TSV Assistant Lead, Locale TSV Secretary, and Locale TSV Officer.

Use **Form generator** to open the weekly/monthly print selection portal. Weekly selections are remembered in the database. The weekly Pagpapalaganap monitoring report, attendance form, meeting agenda, Suguan, TSV checklist, Form 6 Resumen, hymnfiles Patotoo, and the monthly pulong attendance generate from the original supplied DOCX templates; forms marked **Soon** still need their templates. The attendance form and agenda ask for the weekly meeting day/time and place; the monthly attendance asks for the pulong date. The agenda also asks for prayer names and up to three agenda topics. **Generate masterlist DOCX**, in the All officers toolbar, fills the original supplied masterlist template with all officer records without adding a generated-on line.

The original DOCX templates are included in the `templates` folder. Generation updates template text while keeping its page layout, styles, images, and other package parts.

Use **Signatories** to update the Pastor, PD Tagasubaybay, TSV Lead, Assistant TSV Lead, and TSV Secretary names stored in the database. If you already imported an earlier version of the database, import the updated `database.sql` to add the print selections and new signatory settings; existing values and officer records are preserved.

## Suguan

Open **Form generator** and tick **Suguan** to plan the week's operators; the planner appears inside the generator (use the arrows to plan other weeks — the Suguan, checklist and Resumen are generated for the week shown). A new week starts with the regular schedule (Wed 7:30 PM, Thu 5:30 AM, Thu 7:45 PM, Sat 6:00 PM, Sun 6:00 AM, Sun 8:00 AM PNK, Sun 10:00 AM), or copies the previous saved week's regular services with the roles rotated (Computer → Audio, Audio → Video, Video → Computer). EWS toggles and edited times carry over until you change them; services with **Weekly** turned off are for that week only.

- Click an officer's name, then click a Computer/Video/Audio role to assign it (or click the role first, then the name, or drag the name onto the role). Press Esc to stop assigning.
- Drag a service by its handle, use the arrows, or hover between services and click **Add service here** to insert one (for example a Pamamahayag after the first Pagsamba).
- **Auto rotate** shifts the roles in every service of the week. Changes save automatically.
- The **Suguan** form prints five services per page (extra pages are added as needed). The **TSV Checklist** prints one checklist per worship service (Pagsamba, including EWS) with the locale, date, day, time, Nakasugo operators and signatories filled in.

- The **Resumen of TSV Form 6** prints one week (Pagsamba and English services) as a half-sheet (8.5 × 7 in) document. A salaysay letter is generated with it for every worship service that has fewer than 3 operators.

PNK services can also be toggled to EWS; EWS services get a checklist page and a Resumen row like a Pagsamba.

The Form generator can also print this week's Suguan, checklist and Resumen, the Patotoo na nadelete ang hymnfiles (covering Monday to Sunday ending on the most recent Sunday), and the monthly TSV Officers Pulong Attendance. Set the locale, district and the district supervisor under **Signatories**. Monthly reports (pulong attendance, TSV Form 3 as .xlsx, Directory Report) ask for the month; Form 3 officer counts include inactive officers and the Directory Report schedule comes from the Suguan. Overview shows this month's birthdays, **Export Excel** downloads the masterlist as an .xlsx spreadsheet, **Export SQL** downloads a backup of the officer records table, **Import** restores or adds records from a .sql/.mysql backup (after a review and confirmation step), and the moon/sun button switches between dark and light mode.

Officers have an optional **Wedding anniversary** next to the birthday; both print in full ("July 08, 2003") on the monthly pulong attendance. Recruits entered for the monthly attendance (name, birthday, wedding date) are remembered for the next time until changed. The Webex display name for TSV Form 3 is set under **Signatories**. The wedding column and the longer settings column are added to an existing database automatically on the first page load.

## TSV Memories

**TSV Memories** in the sidebar keeps photos and videos of TSV activities like a social media feed. **New memory** takes one or more photos or videos, a caption and the activity date (left blank, it is dated the day the photos are added). Posts can be edited (caption, date, add or remove photos) or deleted from the ⋯ menu or the photo viewer. View them as a **Timeline**, a **Horizontal** timeline (oldest on the left, newest on the right; rest the mouse near either side to glide along, click a memory to zoom it open), a **Gallery** or a **Calendar** (clicking a day opens its photos), filter by year, and see **On this day** memories from earlier years.

Photos are resized in the browser (up to 1600 px, about 500 KB). Videos (MP4, WebM or MOV, up to 100 MB each) are uploaded in 512 KB pieces with a progress percentage. A single video plays right in the feed; videos use a YouTube-style player (seek bar, volume, playback speed, picture-in-picture, full screen, and YouTube's keyboard shortcuts: Space/K, J/L, arrows, M, F). Videos play at the quality they were uploaded in. Both are stored in the database, so **Export SQL** backups include them and **Import** restores them; each video adds about 1.33× its size to the backup. Importing the same backup again skips photos and videos that are already there. `.htaccess` raises PHP's upload limit to 512 MB for these larger backups; keep it when copying or zipping the site.

For an existing database, run `migrate_suguan.sql` and `migrate_monthly_reports.sql` once to add the Suguan table and the locale settings.

To extend an existing database for the imported masterlist fields, run `migrate_masterlist_columns.sql` once in phpMyAdmin before using this version. It adds roster number, Telegram contact, and raw source text for incomplete dates without removing existing fields.

For an existing database with the older officer-role spelling, run `migrate_officer_role_label.sql` once to rename those records to `Locale TSV Officer`.

The default XAMPP database account is often `root` with no password. Set a password for MySQL and update `db.php` before exposing the site beyond your local machine. This starter app does not include user login or role-based access.

## Installing on another PC

`tsv-secretary.bat` does everything:

- **Site not installed yet:** installs XAMPP 8.2.12 if missing (asking for administrator permission once), downloads the site zip from the Google Drive link in `DRIVE_LINK`, copies it to `C:\xampp\htdocs\tsv_secretary_website`, starts MySQL and Apache, creates the `tsv_secretary` database from `database.sql`, adds a **TSV Secretary** desktop shortcut, and opens the site.
- **Site already installed:** downloads the zip from Drive and, if it differs from the installed one, copies the new files over (officer records in MySQL and an existing `db.php` are kept; offline, it just uses the installed version; a folder with `.git` is never updated). Then it starts MySQL and Apache in the background (if they are not running) and opens the site.

To prepare it: zip this folder (select all files, then **Send to > Compressed (zipped) folder**; the hidden `.git` folder is not needed), upload the zip to Google Drive shared as **Anyone with the link**, and paste the share link into `DRIVE_LINK` in `tsv-secretary.bat`. Then send only the .bat file to the new PC.

The new database starts empty. To bring the officer records over, use **Export SQL** on the old PC and **Import** on the new one.
