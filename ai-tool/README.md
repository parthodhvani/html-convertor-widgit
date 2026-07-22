# AI WordPress Website Generator (`ai-tool`)

Core PHP form that connects to **Claude (Anthropic API)** and builds a complete local WordPress website under your XAMPP `htdocs` folder.

## Install on Windows XAMPP

1. Copy this folder to:
   ```
   D:\xampp\htdocs\ai-tool\
   ```
2. Start **Apache** and **MySQL** in XAMPP.
3. Open `D:\xampp\htdocs\ai-tool\config.php` and set your Claude API key:
   ```php
   'anthropic_api_key' => 'sk-ant-...',
   ```
   Get a key at [console.anthropic.com](https://console.anthropic.com/).
4. Open:
   ```
   http://localhost/ai-tool/
   ```

## Form fields

| Field | Type |
|-------|------|
| Website name | text box |
| Description | textarea |
| Domain | text box |
| Submit | generates the site |

## What happens on submit

1. Form data is sent to Claude with the full **AI WordPress Website Generator** prompt.
2. Claude returns a structured website package (brand, design system, theme files, Gutenberg pages/posts, patterns, SEO, image prompts).
3. This tool then:
   - Creates `D:\xampp\htdocs\{website-slug}\`
   - Creates MySQL database `wp_{website_slug}` (drops/recreates if it exists)
   - Downloads and installs latest WordPress
   - Writes `wp-config.php`
   - Creates admin user **nimesh** / **nimesh@123**
   - Installs custom theme **AI Starter**
   - Creates pages, posts, menus, homepage/blog settings
   - Saves a JSON report under `ai-tool/generated/`

## Defaults

| Setting | Value |
|---------|--------|
| Admin user | `nimesh` |
| Admin password | `nimesh@123` |
| Admin email | `admin@example.com` |
| DB user | `root` (XAMPP default) |
| DB password | empty (XAMPP default) |
| Site URL | `http://localhost/{slug}/` |

## Optional: WP-CLI

If [WP-CLI](https://wp-cli.org/) is installed and on `PATH`, it is preferred for install/content. Otherwise PHP `wp_install()` + `wp-load.php` are used.

## Requirements

- PHP 8.0+ with `curl` (or `allow_url_fopen`), `mysqli`, `zip` (or system `unzip`)
- MySQL running (XAMPP)
- Anthropic API key with access to the configured model
- Outbound HTTPS to `api.anthropic.com` and `wordpress.org`

## Security notes

- Do not commit real API keys.
- This tool is for **local development only**.
- `logs/` and `generated/` may contain business content from Claude — keep them private.

## Folder structure

```
ai-tool/
  index.php              Form UI
  process.php            Submit handler
  config.php             API key + XAMPP paths
  includes/
    ClaudeClient.php
    PromptBuilder.php
    WordPressInstaller.php
    SiteGenerator.php
  assets/
    style.css
    app.js
  logs/
  generated/
```
