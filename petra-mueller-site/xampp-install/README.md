# XAMPP install package — Petra Müller WordPress

This package installs WordPress **on your Windows PC** at:

`D:\xampp\htdocs\petra-mueller`

and creates database **`wp_petra_mueller`** (visible in phpMyAdmin at
http://localhost:8082/phpmyadmin/).

> The cloud agent cannot write to your `D:\` drive or open your local
> phpMyAdmin. Run the installer **on your Windows machine** (one double-click).

## Before you start

1. Open **XAMPP Control Panel**
2. Start **Apache** and **MySQL**
3. Confirm phpMyAdmin opens: http://localhost:8082/phpmyadmin/

## Option A — Automatic (recommended)

1. Copy the entire `xampp-install` folder to your Windows PC  
   (or pull this git branch and open `petra-mueller-site\xampp-install`)
2. If you also downloaded `petra-mueller-full.zip`, extract it so that  
   `xampp-install\petra-mueller\wp-config.php` exists (full site, faster)
3. Double-click **`INSTALL-XAMPP.bat`**
4. Open http://localhost:8082/petra-mueller/

Admin: `nimesh` / `nimesh@123`

## Option B — Manual via phpMyAdmin

1. Copy folder `petra-mueller` (from the full zip) to `D:\xampp\htdocs\petra-mueller`
2. Open http://localhost:8082/phpmyadmin/
3. Go to **SQL** tab → paste / import `01-create-database.sql`
4. Select database `wp_petra_mueller` → **Import** → choose `wp_petra_mueller.sql`
5. Edit `D:\xampp\htdocs\petra-mueller\wp-config.php`:
   - `DB_NAME` = `wp_petra_mueller`
   - `DB_USER` = `root`
   - `DB_PASSWORD` = `` (empty for default XAMPP)
   - `DB_HOST` = `localhost`
6. Open http://localhost:8082/petra-mueller/

## Files

| File | Purpose |
|------|---------|
| `INSTALL-XAMPP.bat` | One-click installer |
| `write-wp-config.ps1` | Writes XAMPP DB credentials |
| `01-create-database.sql` | DROP/CREATE database |
| `wp_petra_mueller.sql` | Full content dump (URLs set for localhost:8082) |
| `payload/` | Theme + uploads (used if full WP not bundled) |
| `petra-mueller/` | Optional full WordPress tree (if present) |

## If MySQL root has a password

Edit `INSTALL-XAMPP.bat` and change every:

```bat
"%MYSQL%" -u root
```

to:

```bat
"%MYSQL%" -u root -pYOURPASSWORD
```
