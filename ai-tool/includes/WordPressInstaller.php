<?php
/**
 * Downloads WordPress, creates MySQL database, installs WP, activates theme.
 */

declare(strict_types=1);

final class WordPressInstaller
{
    private array $config;

    /** @var callable|null */
    private $logger;

    public function __construct(array $config, ?callable $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function resolveHtdocsPath(): string
    {
        $htdocs = (string) ($this->config['htdocs_path'] ?? '');

        if (!empty($this->config['auto_detect_paths'])) {
            if (!$this->isWritableDir($htdocs)) {
                $candidates = [
                    dirname(__DIR__, 2), // parent of ai-tool (typical htdocs)
                    dirname(__DIR__),
                    '/opt/lampp/htdocs',
                    '/var/www/html',
                    __DIR__ . '/../../htdocs',
                ];
                foreach ($candidates as $candidate) {
                    $real = realpath($candidate) ?: $candidate;
                    if ($this->isWritableDir($real)) {
                        $this->log('Auto-detected htdocs: ' . $real);
                        return rtrim($real, DIRECTORY_SEPARATOR);
                    }
                }
            }
        }

        return rtrim($htdocs, DIRECTORY_SEPARATOR);
    }

    public function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        return $text !== '' ? $text : 'website';
    }

    public function projectSlug(string $websiteName, string $domain): string
    {
        $fromDomain = preg_replace('/^www\./i', '', $domain) ?? $domain;
        $fromDomain = preg_replace('/\.[a-z]{2,}(\.[a-z]{2})?$/i', '', $fromDomain) ?? $fromDomain;
        $slug = $this->slugify($fromDomain !== '' ? $fromDomain : $websiteName);
        return $slug;
    }

    public function databaseName(string $slug): string
    {
        $name = 'wp_' . preg_replace('/[^a-z0-9_]/', '_', $slug);
        $name = substr($name, 0, 64);
        return $name !== '' ? $name : 'wp_site';
    }

    /**
     * Full install: folder + download WP + DB + wp-config + core install.
     *
     * @return array{path:string,url:string,db_name:string,slug:string}
     */
    public function install(
        string $websiteName,
        string $domain,
        string $slug
    ): array {
        $htdocs = $this->resolveHtdocsPath();
        $sitePath = $htdocs . DIRECTORY_SEPARATOR . $slug;
        $dbName = $this->databaseName($slug);
        $siteUrl = rtrim((string) $this->config['site_url_base'], '/') . '/' . $slug;

        $this->log('Project folder: ' . $sitePath);
        $this->log('Database: ' . $dbName);
        $this->log('Site URL: ' . $siteUrl);

        $this->ensureDirectory($sitePath);
        $this->downloadAndExtractWordPress($sitePath);
        $this->createDatabase($dbName);
        $this->writeWpConfig($sitePath, $dbName);
        $this->runCoreInstall($sitePath, $siteUrl, $websiteName);

        return [
            'path'    => $sitePath,
            'url'     => $siteUrl,
            'db_name' => $dbName,
            'slug'    => $slug,
        ];
    }

    public function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            $this->log('Project folder exists — cleaning non-essential files for reinstall…');
            // Keep folder but remove WP core if present for fresh install
            if (file_exists($path . DIRECTORY_SEPARATOR . 'wp-config.php')
                || file_exists($path . DIRECTORY_SEPARATOR . 'wp-load.php')) {
                $this->log('Existing WordPress detected — removing for clean install.');
                $this->removeDirectoryContents($path);
            }
        } else {
            if (!mkdir($path, 0755, true) && !is_dir($path)) {
                throw new RuntimeException('Could not create project folder: ' . $path);
            }
            $this->log('Created project folder.');
        }
    }

    public function downloadAndExtractWordPress(string $sitePath): void
    {
        if (file_exists($sitePath . DIRECTORY_SEPARATOR . 'wp-load.php')) {
            $this->log('WordPress files already present — skipping download.');
            return;
        }

        $zipUrl  = (string) $this->config['wordpress_zip_url'];
        $tmpZip  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wordpress-latest-' . uniqid('', true) . '.zip';
        $tmpDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wp-extract-' . uniqid('', true);

        $this->log('Downloading WordPress from ' . $zipUrl);
        $this->downloadFile($zipUrl, $tmpZip);

        if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Could not create temp extract directory.');
        }

        $this->log('Extracting WordPress…');
        $this->unzip($tmpZip, $tmpDir);

        $wpSource = $tmpDir . DIRECTORY_SEPARATOR . 'wordpress';
        if (!is_dir($wpSource)) {
            // Sometimes zip extracts differently
            $entries = array_values(array_filter(scandir($tmpDir) ?: [], static function ($e) {
                return $e !== '.' && $e !== '..';
            }));
            if (count($entries) === 1 && is_dir($tmpDir . DIRECTORY_SEPARATOR . $entries[0])) {
                $wpSource = $tmpDir . DIRECTORY_SEPARATOR . $entries[0];
            } else {
                throw new RuntimeException('Unexpected WordPress zip structure.');
            }
        }

        $this->copyDirectory($wpSource, $sitePath);
        @unlink($tmpZip);
        $this->removeDirectory($tmpDir);
        $this->log('WordPress files ready.');
    }

    public function createDatabase(string $dbName): void
    {
        $this->log('Creating MySQL database: ' . $dbName);
        $mysqli = $this->connectMysql(false);

        $escaped = $mysqli->real_escape_string($dbName);
        // Drop if exists, then recreate
        if (!$mysqli->query("DROP DATABASE IF EXISTS `{$escaped}`")) {
            throw new RuntimeException('Failed to drop database: ' . $mysqli->error);
        }
        if (!$mysqli->query("CREATE DATABASE `{$escaped}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            throw new RuntimeException('Failed to create database: ' . $mysqli->error);
        }
        $mysqli->close();
        $this->log('Database ready.');
    }

    public function writeWpConfig(string $sitePath, string $dbName): void
    {
        $sample = $sitePath . DIRECTORY_SEPARATOR . 'wp-config-sample.php';
        $target = $sitePath . DIRECTORY_SEPARATOR . 'wp-config.php';

        if (!file_exists($sample) && !file_exists($target)) {
            throw new RuntimeException('wp-config-sample.php not found.');
        }

        $contents = file_exists($target)
            ? (string) file_get_contents($target)
            : (string) file_get_contents($sample);

        $dbUser = (string) $this->config['db_user'];
        $dbPass = (string) $this->config['db_password'];
        $dbHost = (string) $this->config['db_host'];
        $port   = (int) ($this->config['db_port'] ?? 3306);
        if ($port && $port !== 3306) {
            $dbHost .= ':' . $port;
        }

        $replacements = [
            "database_name_here" => $dbName,
            "username_here"      => $dbUser,
            "password_here"      => $dbPass,
            "localhost"          => $dbHost,
        ];

        foreach ($replacements as $search => $replace) {
            $contents = str_replace($search, $replace, $contents);
        }

        // Inject salts if still placeholders
        if (strpos($contents, 'put your unique phrase here') !== false) {
            $salts = $this->generateSalts();
            $keys = [
                'AUTH_KEY',
                'SECURE_AUTH_KEY',
                'LOGGED_IN_KEY',
                'NONCE_KEY',
                'AUTH_SALT',
                'SECURE_AUTH_SALT',
                'LOGGED_IN_SALT',
                'NONCE_SALT',
            ];
            foreach ($keys as $i => $key) {
                $pattern = "/define\(\s*'" . $key . "'\s*,\s*'put your unique phrase here'\s*\)\s*;/";
                $contents = preg_replace(
                    $pattern,
                    "define( '" . $key . "', '" . $salts[$i] . "' );",
                    $contents,
                    1
                ) ?? $contents;
            }
        }

        // Ensure debug off for local generated sites (can be toggled later)
        if (strpos($contents, "define( 'WP_DEBUG'") === false
            && strpos($contents, 'define("WP_DEBUG"') === false) {
            $contents = str_replace(
                "/* That's all, stop editing!",
                "define( 'WP_DEBUG', false );\n\n/* That's all, stop editing!",
                $contents
            );
        }

        if (file_put_contents($target, $contents) === false) {
            throw new RuntimeException('Could not write wp-config.php');
        }
        $this->log('wp-config.php configured.');
    }

    public function runCoreInstall(string $sitePath, string $siteUrl, string $siteTitle): void
    {
        $adminUser  = (string) $this->config['wp_admin_user'];
        $adminPass  = (string) $this->config['wp_admin_pass'];
        $adminEmail = (string) $this->config['wp_admin_email'];

        $wpCli = $this->findWpCli();
        if ($wpCli !== null) {
            $this->log('Installing WordPress via WP-CLI…');
            $cmd = sprintf(
                '%s core install --path=%s --url=%s --title=%s --admin_user=%s --admin_password=%s --admin_email=%s --skip-email 2>&1',
                escapeshellarg($wpCli),
                escapeshellarg($sitePath),
                escapeshellarg($siteUrl),
                escapeshellarg($siteTitle),
                escapeshellarg($adminUser),
                escapeshellarg($adminPass),
                escapeshellarg($adminEmail)
            );
            $output = [];
            $code   = 0;
            exec($cmd, $output, $code);
            $this->log(implode("\n", $output));
            if ($code !== 0) {
                throw new RuntimeException('WP-CLI install failed with code ' . $code);
            }
            $this->log('WordPress installed (WP-CLI).');
            return;
        }

        $this->log('WP-CLI not found — using PHP installer…');
        $this->installViaPhpSubprocess($sitePath, $siteUrl, $siteTitle, $adminUser, $adminPass, $adminEmail);
        $this->log('WordPress installed (PHP).');
    }

    /**
     * Activate theme and apply content using WP-CLI when available,
     * otherwise via direct $wpdb / wp-load bootstrap.
     */
    public function bootstrapWordPress(string $sitePath): void
    {
        $load = $sitePath . DIRECTORY_SEPARATOR . 'wp-load.php';
        if (!file_exists($load)) {
            throw new RuntimeException('wp-load.php missing — WordPress not installed correctly.');
        }

        // Prevent theme output during CLI-like bootstrap
        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }

        require_once $load;
    }

    public function findWpCli(): ?string
    {
        $candidates = ['wp', 'wp.bat'];
        foreach ($candidates as $bin) {
            $where = $this->which($bin);
            if ($where !== null) {
                return $where;
            }
        }

        $xampp = (string) ($this->config['xampp_path'] ?? '');
        $extra = [
            $xampp . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'wp.bat',
            'C:\\Program Files\\wp-cli\\wp.bat',
            '/usr/local/bin/wp',
            '/usr/bin/wp',
        ];
        foreach ($extra as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function installViaPhpSubprocess(
        string $sitePath,
        string $siteUrl,
        string $siteTitle,
        string $adminUser,
        string $adminPass,
        string $adminEmail
    ): void {
        $script = $sitePath . DIRECTORY_SEPARATOR . 'ai-tool-install.php';
        $php = <<<'PHP'
<?php
define('WP_INSTALLING', true);
define('WP_USE_THEMES', false);
require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$siteUrl    = getenv('AI_TOOL_SITE_URL') ?: '';
$siteTitle  = getenv('AI_TOOL_SITE_TITLE') ?: '';
$adminUser  = getenv('AI_TOOL_ADMIN_USER') ?: '';
$adminPass  = getenv('AI_TOOL_ADMIN_PASS') ?: '';
$adminEmail = getenv('AI_TOOL_ADMIN_EMAIL') ?: '';

if (function_exists('is_blog_installed') && is_blog_installed()) {
    update_option('siteurl', $siteUrl);
    update_option('home', $siteUrl);
    echo "ALREADY_INSTALLED\n";
    exit(0);
}

$result = wp_install($siteTitle, $adminUser, $adminEmail, true, '', $adminPass);
if (empty($result['user_id'])) {
    fwrite(STDERR, "wp_install failed\n");
    exit(1);
}

update_option('siteurl', $siteUrl);
update_option('home', $siteUrl);
echo "INSTALL_OK\n";
PHP;

        if (file_put_contents($script, $php) === false) {
            throw new RuntimeException('Could not write install script.');
        }

        $phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $xamppPhp = (string) ($this->config['xampp_path'] ?? '') . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe';
        if (is_file($xamppPhp)) {
            $phpBin = $xamppPhp;
        }

        $envPrefix = sprintf(
            'AI_TOOL_SITE_URL=%s AI_TOOL_SITE_TITLE=%s AI_TOOL_ADMIN_USER=%s AI_TOOL_ADMIN_PASS=%s AI_TOOL_ADMIN_EMAIL=%s',
            escapeshellarg($siteUrl),
            escapeshellarg($siteTitle),
            escapeshellarg($adminUser),
            escapeshellarg($adminPass),
            escapeshellarg($adminEmail)
        );

        // Windows-friendly: putenv in current process then exec child inherits on most stacks;
        // also pass via inline for Unix.
        putenv('AI_TOOL_SITE_URL=' . $siteUrl);
        putenv('AI_TOOL_SITE_TITLE=' . $siteTitle);
        putenv('AI_TOOL_ADMIN_USER=' . $adminUser);
        putenv('AI_TOOL_ADMIN_PASS=' . $adminPass);
        putenv('AI_TOOL_ADMIN_EMAIL=' . $adminEmail);

        $cmd = sprintf('%s %s 2>&1', escapeshellarg($phpBin), escapeshellarg($script));
        if (stripos(PHP_OS, 'WIN') === false) {
            $cmd = $envPrefix . ' ' . $cmd;
        }

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        @unlink($script);

        $this->log(implode("\n", $output));
        if ($code !== 0) {
            throw new RuntimeException('PHP WordPress install failed (exit ' . $code . ').');
        }
    }

    private function connectMysql(bool $selectDb, ?string $dbName = null): mysqli
    {
        if (!extension_loaded('mysqli')) {
            throw new RuntimeException('PHP mysqli extension is required. Enable it in php.ini.');
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new mysqli(
            (string) $this->config['db_host'],
            (string) $this->config['db_user'],
            (string) $this->config['db_password'],
            $selectDb && $dbName ? $dbName : '',
            (int) ($this->config['db_port'] ?? 3306)
        );

        if ($mysqli->connect_error) {
            throw new RuntimeException(
                'MySQL connection failed: ' . $mysqli->connect_error .
                '. Ensure XAMPP MySQL is running.'
            );
        }

        $mysqli->set_charset('utf8mb4');
        return $mysqli;
    }

    /**
     * @return list<string>
     */
    private function generateSalts(): array
    {
        $salts = [];
        for ($i = 0; $i < 8; $i++) {
            $salts[] = $this->randomString(64);
        }
        return $salts;
    }

    private function randomString(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{}';
        $max   = strlen($chars) - 1;
        $out   = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    private function downloadFile(string $url, string $dest): void
    {
        if (function_exists('curl_init')) {
            $fp = fopen($dest, 'wb');
            if ($fp === false) {
                throw new RuntimeException('Cannot write temp zip: ' . $dest);
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 600,
                CURLOPT_USERAGENT      => 'ai-tool-wp-generator/1.0',
            ]);
            $ok  = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);
            if ($ok === false || $code >= 400) {
                @unlink($dest);
                throw new RuntimeException('WordPress download failed: ' . ($err ?: 'HTTP ' . $code));
            }
            return;
        }

        $data = @file_get_contents($url);
        if ($data === false || file_put_contents($dest, $data) === false) {
            throw new RuntimeException('WordPress download failed (file_get_contents).');
        }
    }

    private function unzip(string $zipFile, string $destDir): void
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new RuntimeException('Could not open WordPress zip.');
            }
            if (!$zip->extractTo($destDir)) {
                $zip->close();
                throw new RuntimeException('Could not extract WordPress zip.');
            }
            $zip->close();
            return;
        }

        // Fallback to system unzip
        $cmd = sprintf('unzip -qo %s -d %s 2>&1', escapeshellarg($zipFile), escapeshellarg($destDir));
        exec($cmd, $output, $code);
        if ($code !== 0) {
            throw new RuntimeException('unzip failed: ' . implode("\n", $output));
        }
    }

    private function copyDirectory(string $src, string $dst): void
    {
        $src = rtrim($src, '/\\');
        $dst = rtrim($dst, '/\\');
        if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
            throw new RuntimeException('Cannot create: ' . $dst);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $target = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new RuntimeException('Cannot create: ' . $target);
                }
            } else {
                if (!copy($item->getPathname(), $target)) {
                    throw new RuntimeException('Cannot copy: ' . $item->getPathname());
                }
            }
        }
    }

    private function removeDirectoryContents(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        $this->removeDirectoryContents($dir);
        @rmdir($dir);
    }

    private function isWritableDir(string $path): bool
    {
        return $path !== '' && is_dir($path) && is_writable($path);
    }

    private function which(string $bin): ?string
    {
        $cmd = stripos(PHP_OS, 'WIN') === 0
            ? 'where ' . escapeshellarg($bin)
            : 'command -v ' . escapeshellarg($bin);
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        if ($code === 0 && !empty($out[0])) {
            return trim($out[0]);
        }
        return null;
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)($message);
        }
    }
}
