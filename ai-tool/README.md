# AI WordPress Website Generator (`ai-tool`)

Core PHP form that builds a complete local WordPress website under your XAMPP `htdocs` folder.

## Important: no Claude / Cursor API key required

**Default mode is `local`** — a built-in generator creates brand colors, pages, posts, menus, and the **AI Starter** theme from your form fields.

| Key type | Works here? |
|----------|-------------|
| None (local mode) | Yes (default) |
| Anthropic Claude API key | Optional, richer AI copy |
| Cursor API key | **No** — Cursor keys are for Cloud Agents, not PHP chat completions |

## Install on Windows XAMPP

1. Copy this folder to:
   ```
   D:\xampp\htdocs\ai-tool\
   ```
2. Start **Apache** and **MySQL** in XAMPP.
3. Open:
   ```
   http://localhost/ai-tool/
   ```

No API key setup needed for local mode.

## Form fields

| Field | Type |
|-------|------|
| Website name | text box |
| Description | textarea |
| Domain | text box |
| Submit | generates the site |

## What happens on submit

1. Builds a website package (local generator, or Claude if configured).
2. Creates `D:\xampp\htdocs\{website-slug}\`
3. Creates MySQL database `wp_{website_slug}` (drops/recreates if it exists)
4. Downloads and installs latest WordPress
5. Creates admin user **nimesh** / **nimesh@123**
6. Installs custom theme **AI Starter**
7. Creates pages, posts, menus, homepage/blog settings
8. Saves a JSON report under `ai-tool/generated/`

## Optional: Claude mode

If you later get an Anthropic key:

```php
// config.local.php
return [
  'ai_provider' => 'anthropic',
  'anthropic_api_key' => 'sk-ant-...',
];
```

## Defaults

| Setting | Value |
|---------|--------|
| AI provider | `local` |
| Admin user | `nimesh` |
| Admin password | `nimesh@123` |
| Admin email | `admin@example.com` |
| DB user | `root` (XAMPP default) |
| DB password | empty (XAMPP default) |
| Site URL | `http://localhost/{slug}/` |

## Requirements

- PHP 8.0+ with `curl` (or `allow_url_fopen`), `mysqli`, `zip` (or system `unzip`)
- MySQL running (XAMPP)
- Outbound HTTPS to `wordpress.org` (to download WordPress)

## Folder structure

```
ai-tool/
  index.php
  process.php
  config.php
  includes/
    LocalPackageBuilder.php   ← no-API generator
    ClaudeClient.php          ← optional
    SiteGenerator.php
    WordPressInstaller.php
    PromptBuilder.php
  assets/
  logs/
  generated/
```
