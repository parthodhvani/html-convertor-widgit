<?php
/**
 * AI Tool – configuration
 *
 * Copy this folder to: D:\xampp\htdocs\ai-tool\
 *
 * Default mode needs NO API key (built-in local generator).
 * Optional: set Anthropic Claude key for richer AI content.
 *
 * Note: A Cursor API key cannot replace Claude chat here.
 * Cursor keys are for Cloud Agents, not PHP chat completions.
 */

declare(strict_types=1);

$local = [];
$localFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
if (is_file($localFile)) {
    $loaded = require $localFile;
    if (is_array($loaded)) {
        $local = $loaded;
    }
}

$defaults = [
    // ------------------------------------------------------------------
    // AI provider: local | anthropic | auto
    // local     = built-in generator (no API key)  ← default
    // anthropic = Claude Messages API (needs key)
    // auto      = Claude if key present, else local
    // ------------------------------------------------------------------
    'ai_provider' => getenv('AI_PROVIDER') ?: 'local',

    // Optional Claude / Anthropic API (only used when ai_provider=anthropic|auto)
    'anthropic_api_key' => getenv('ANTHROPIC_API_KEY') ?: 'YOUR_ANTHROPIC_API_KEY_HERE',
    'anthropic_model'   => getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-20250514',
    'anthropic_api_url' => 'https://api.anthropic.com/v1/messages',
    'anthropic_version' => '2023-06-01',
    'anthropic_max_tokens' => 16000,

    // ------------------------------------------------------------------
    // Local XAMPP / WordPress environment (Windows defaults)
    // ------------------------------------------------------------------
    'xampp_path'   => getenv('XAMPP_PATH') ?: 'D:\\xampp',
    'htdocs_path'  => getenv('HTDOCS_PATH') ?: 'D:\\xampp\\htdocs',

    // Auto-detect Linux alternatives when not on Windows
    'auto_detect_paths' => true,

    // MySQL (XAMPP defaults)
    'db_host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'db_user'     => getenv('DB_USER') ?: 'root',
    'db_password' => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
    'db_port'     => (int) (getenv('DB_PORT') ?: 3306),

    // WordPress admin (from generator prompt)
    'wp_admin_user'  => 'nimesh',
    'wp_admin_pass'  => 'nimesh@123',
    'wp_admin_email' => 'admin@example.com',

    // Local site URL base (no trailing slash). Folder slug is appended.
    'site_url_base' => getenv('SITE_URL_BASE') ?: 'http://localhost',

    // WordPress download
    'wordpress_zip_url' => 'https://wordpress.org/latest.zip',

    // Paths relative to this app
    'logs_dir'      => __DIR__ . DIRECTORY_SEPARATOR . 'logs',
    'generated_dir' => __DIR__ . DIRECTORY_SEPARATOR . 'generated',

    // Theme
    'theme_slug' => 'ai-starter',
    'theme_name' => 'AI Starter',
];

return array_merge($defaults, $local);
