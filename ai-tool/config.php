<?php
/**
 * AI Tool – configuration
 *
 * Copy this file to your XAMPP htdocs as: D:\xampp\htdocs\ai-tool\
 * Set your Anthropic (Claude) API key below before using the form.
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
    // Claude / Anthropic API
    // Get a key at: https://console.anthropic.com/
    // Prefer config.local.php or ANTHROPIC_API_KEY env var.
    // ------------------------------------------------------------------
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