KCBSA Management Information System

XAMPP setup

1. Install XAMPP with PHP 8.2+ and MySQL/MariaDB. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy the `kcbsa_mis` folder into XAMPP's `htdocs` directory. For example: `C:\xampp\htdocs\kcbsa_mis`.
3. Open phpMyAdmin at `http://localhost/phpmyadmin`.
4. Import `kcbsa_mis/database/kcbsa.sql` into a **fresh, empty** database setup. The script creates `kcbsa_mis` and seeds demo data. It does not drop existing tables. Back up any existing database before attempting to reuse its name; this import is intended to run once.
5. Open `kcbsa_mis/api/config.php` and set `DB_HOST`, `DB_NAME`, `DB_USER` and `DB_PASS` to match your local MySQL account. The defaults match a standard local XAMPP install.
6. Visit `http://localhost/kcbsa_mis/` in a browser and sign in with a demo account below.

The application uses only local HTML, CSS and JavaScript files. It has no CDN or external network dependency. The PHP API and MySQL database must be running for data entry and sign-in.

Demo accounts

All demo accounts use the same password: `KCBSA2026!`. Passwords are stored as hashes in the database. Change or disable these accounts before using any non-demo data.

| Role | Username |
| --- | --- |
| Secretary | `secretary` |
| Treasurer | `treasurer` |
| Welfare Officer | `welfare` |
| Safety Officer | `safety` |
| Chairperson | `chairperson` |
| Rider | `rider001` through `rider025`