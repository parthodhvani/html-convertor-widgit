# Petra Müller – WordPress Site (AI Starter)

Production-quality local WordPress website for **Petra Müller – Astrologie Schweiz** (Zug), built with the custom **AI Starter** theme and **core Gutenberg blocks only** (no page builders).

## Inferred business (placeholders were empty)

| Field | Decision |
|-------|----------|
| Website Name | Petra Müller – Astrologie Schweiz |
| Domain | petramuellerzug.ch (brand reference) |
| Business | Astrological counselling, coaching, personality development, lectures & meditation in Zug / Switzerland |

## Local environment note

This cloud agent runs on **Linux**, not Windows XAMPP. Equivalent paths:

| Spec (Windows) | This environment |
|----------------|------------------|
| `D:\xampp\htdocs\petra-mueller` | `/var/www/html/petra-mueller` |
| MySQL via XAMPP | MariaDB (`wp_petra_mueller`) |
| Apache | PHP built-in server `http://127.0.0.1:8080` |

## Install on your Windows XAMPP (real local path)

This cloud environment **cannot write to** `D:\xampp\...` or open your
`http://localhost:8082/phpmyadmin/`. Use the Windows package:

1. Download **`petra-mueller-xampp-install.zip`** from the agent artifacts  
   (or use folder `petra-mueller-site/xampp-install` after pull)
2. Start **Apache + MySQL** in XAMPP Control Panel
3. Double-click **`INSTALL-XAMPP.bat`**

That creates:

- Folder: `D:\xampp\htdocs\petra-mueller`
- Database: `wp_petra_mueller` (shows in phpMyAdmin)
- Site: http://localhost:8082/petra-mueller/

Manual phpMyAdmin path: import `01-create-database.sql` then `wp_petra_mueller.sql`.  
Details: `xampp-install/README.md`.

## Quick start (Linux cloud VM — already installed)

```bash
sudo service mariadb start
cd /var/www/html/petra-mueller
php -S 127.0.0.1:8080 router.php
```

- Front: http://127.0.0.1:8080/
- Admin: http://127.0.0.1:8080/wp-admin/
- User: `nimesh` / Pass: `nimesh@123`

## Redeploy theme + content

```bash
bash /workspace/petra-mueller-site/setup-wordpress.sh
```

## Package contents

- `theme/ai-starter/` — custom theme (theme.json, patterns, CSS)
- `setup-wordpress.sh` — WP-CLI installer for theme, pages, posts, menus
- `FINAL-REPORT.md` — full delivery report
- `IMAGE-PROMPTS.md` — copyright-safe image generation prompts
