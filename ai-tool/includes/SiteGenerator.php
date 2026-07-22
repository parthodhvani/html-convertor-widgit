<?php
/**
 * Orchestrates package generation + WordPress install + theme/content apply.
 *
 * Default mode is local (no API key). Optional Anthropic Claude mode when configured.
 */

declare(strict_types=1);

require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/PromptBuilder.php';
require_once __DIR__ . '/LocalPackageBuilder.php';
require_once __DIR__ . '/WordPressInstaller.php';

final class SiteGenerator
{
    private array $config;
    private ClaudeClient $claude;
    private WordPressInstaller $installer;

    /** @var callable|null */
    private $logger;

    /** @var list<string> */
    private array $logLines = [];

    public function __construct(array $config, ?callable $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;

        $log = function (string $msg): void {
            $this->emit($msg);
        };

        $this->claude    = new ClaudeClient($config, $log);
        $this->installer = new WordPressInstaller($config, $log);
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(string $websiteName, string $description, string $domain): array
    {
        $websiteName = trim($websiteName);
        $description = trim($description);
        $domain      = trim($domain);

        if ($websiteName === '' || $description === '' || $domain === '') {
            throw new InvalidArgumentException('Website name, description, and domain are required.');
        }

        $this->ensureAppDirs();

        $slug    = $this->installer->projectSlug($websiteName, $domain);
        $htdocs  = $this->installer->resolveHtdocsPath();
        $project = $htdocs . DIRECTORY_SEPARATOR . $slug;
        $dbName  = $this->installer->databaseName($slug);

        $this->emit('=== AI WordPress Website Generator ===');
        $this->emit('Website: ' . $websiteName);
        $this->emit('Domain: ' . $domain);
        $this->emit('Slug: ' . $slug);

        // ------------------------------------------------------------------
        // 1) Build website package (local by default; Claude optional)
        // ------------------------------------------------------------------
        $provider = strtolower((string) ($this->config['ai_provider'] ?? 'local'));
        if ($provider === 'auto') {
            $provider = $this->claude->isConfigured() ? 'anthropic' : 'local';
        }

        if ($provider === 'anthropic' || $provider === 'claude') {
            if (!$this->claude->isConfigured()) {
                throw new RuntimeException(
                    'Anthropic/Claude API key is not set. Use ai_provider=local (default), '
                    . 'or set anthropic_api_key in config.php.'
                );
            }
            $this->emit('Step A: Connecting to Claude and generating website package…');
            $userPrompt = PromptBuilder::buildAgencyPrompt(
                $websiteName,
                $domain,
                $description,
                $htdocs,
                $project,
                $dbName
            );
            $package = $this->claude->chatJson(
                [
                    [
                        'role'    => 'user',
                        'content' => $userPrompt . "\n\nReturn the complete JSON website package now.",
                    ],
                ],
                PromptBuilder::jsonSystemPrompt()
            );
            $this->emit('Claude package received.');
        } else {
            if (in_array($provider, ['cursor', 'cursor_api'], true)) {
                $this->emit(
                    'Note: Cursor API keys are for Cloud Agents, not Claude-style chat. '
                    . 'Falling back to built-in local generator.'
                );
            }
            $this->emit('Step A: Building website package with built-in local generator (no API key)…');
            $package = LocalPackageBuilder::build($websiteName, $description, $domain);
        }

        $packageFile = $this->config['generated_dir'] . DIRECTORY_SEPARATOR .
            $slug . '-' . date('Ymd-His') . '.json';
        file_put_contents(
            $packageFile,
            json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $this->emit('Saved package: ' . $packageFile);

        // ------------------------------------------------------------------
        // 2) Install WordPress + database
        // ------------------------------------------------------------------
        $this->emit('Step B: Installing WordPress locally…');
        $install = $this->installer->install($websiteName, $domain, $slug);

        // ------------------------------------------------------------------
        // 3) Write theme + apply content
        // ------------------------------------------------------------------
        $this->emit('Step C: Creating AI Starter theme and importing content…');
        $this->applyPackage($install['path'], $install['url'], $websiteName, $package);

        $report = $this->buildReport($websiteName, $domain, $install, $package);

        $reportFile = $this->config['generated_dir'] . DIRECTORY_SEPARATOR .
            $slug . '-report-' . date('Ymd-His') . '.json';
        file_put_contents(
            $reportFile,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $this->emit('=== DONE ===');
        $this->emit('Open: ' . $install['url']);
        $this->emit('Admin: ' . $install['url'] . '/wp-admin/');

        return $report;
    }

    /**
     * @param array<string, mixed> $package
     */
    private function applyPackage(string $sitePath, string $siteUrl, string $siteTitle, array $package): void
    {
        $themeSlug = (string) ($this->config['theme_slug'] ?? 'ai-starter');
        $themeDir  = $sitePath . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR .
            'themes' . DIRECTORY_SEPARATOR . $themeSlug;

        if (!is_dir($themeDir) && !mkdir($themeDir, 0755, true) && !is_dir($themeDir)) {
            throw new RuntimeException('Could not create theme directory.');
        }

        $themeFiles = $package['theme_files'] ?? [];
        if (!is_array($themeFiles) || $themeFiles === []) {
            $themeFiles = $this->fallbackThemeFiles($package);
        }

        foreach ($themeFiles as $filename => $contents) {
            $filename = basename((string) $filename);
            if ($filename === '' || strpos($filename, '..') !== false) {
                continue;
            }
            if (is_array($contents) || is_object($contents)) {
                $contents = json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
            $contents = (string) $contents;
            // Normalize theme header name
            if ($filename === 'style.css' && stripos($contents, 'Theme Name:') === false) {
                $contents = "/*\nTheme Name: AI Starter\nTheme URI: " . $siteUrl .
                    "\nDescription: Lightweight custom theme generated by AI Tool.\nVersion: 1.0.0\nText Domain: ai-starter\n*/\n\n" . $contents;
            }
            file_put_contents($themeDir . DIRECTORY_SEPARATOR . $filename, $contents);
            $this->emit('Wrote theme file: ' . $filename);
        }

        // Minimal screenshot placeholder (1x1 PNG) if missing
        $screenshot = $themeDir . DIRECTORY_SEPARATOR . 'screenshot.png';
        if (!file_exists($screenshot)) {
            $png = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO5W7lkAAAAASUVORK5CYII='
            );
            if ($png !== false) {
                file_put_contents($screenshot, $png);
            }
        }

        // Prefer WP-CLI for content; fall back to bootstrapping WP
        $wpCli = $this->installer->findWpCli();
        if ($wpCli !== null) {
            $this->applyWithWpCli($wpCli, $sitePath, $siteUrl, $siteTitle, $themeSlug, $package);
        } else {
            $this->applyWithWpLoad($sitePath, $siteUrl, $siteTitle, $themeSlug, $package);
        }
    }

    /**
     * @param array<string, mixed> $package
     */
    private function applyWithWpCli(
        string $wpCli,
        string $sitePath,
        string $siteUrl,
        string $siteTitle,
        string $themeSlug,
        array $package
    ): void {
        $this->runWp($wpCli, $sitePath, 'theme activate ' . escapeshellarg($themeSlug));
        $this->runWp($wpCli, $sitePath, 'option update blogname ' . escapeshellarg($siteTitle));
        $this->runWp($wpCli, $sitePath, 'rewrite structure ' . escapeshellarg('/%postname%/') . ' --hard');

        $settings = is_array($package['settings'] ?? null) ? $package['settings'] : [];
        $tz = (string) ($settings['timezone'] ?? 'Asia/Kolkata');
        $this->runWp($wpCli, $sitePath, 'option update timezone_string ' . escapeshellarg($tz));

        $pageIds = [];
        $pages = is_array($package['pages'] ?? null) ? $package['pages'] : [];
        $frontId = 0;
        $blogTitle = (string) ($settings['blog_page_title'] ?? 'Blog');
        $blogSlug  = (string) ($settings['blog_page_slug'] ?? 'blog');
        $blogId = 0;

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $title   = (string) ($page['title'] ?? 'Page');
            $slug    = (string) ($page['slug'] ?? $this->installer->slugify($title));
            $content = (string) ($page['content'] ?? '');
            $status  = (string) ($page['status'] ?? 'publish');

            $tmp = tempnam(sys_get_temp_dir(), 'wppage');
            file_put_contents($tmp, $content);

            $out = $this->runWp(
                $wpCli,
                $sitePath,
                sprintf(
                    'post create %s --post_type=page --post_title=%s --post_name=%s --post_status=%s --porcelain',
                    escapeshellarg($tmp),
                    escapeshellarg($title),
                    escapeshellarg($slug),
                    escapeshellarg($status)
                )
            );
            @unlink($tmp);
            $id = (int) trim($out);
            if ($id > 0) {
                $pageIds[$slug] = $id;
                if (!empty($page['is_front_page'])) {
                    $frontId = $id;
                }
                if ($slug === $blogSlug || strcasecmp($title, $blogTitle) === 0) {
                    $blogId = $id;
                }
            }
            $this->emit('Created page: ' . $title . ' (#' . $id . ')');
        }

        // Ensure blog page exists
        if ($blogId === 0) {
            $out = $this->runWp(
                $wpCli,
                $sitePath,
                sprintf(
                    'post create --post_type=page --post_title=%s --post_name=%s --post_status=publish --porcelain',
                    escapeshellarg($blogTitle),
                    escapeshellarg($blogSlug)
                )
            );
            $blogId = (int) trim($out);
            $pageIds[$blogSlug] = $blogId;
        }

        if ($frontId === 0 && isset($pageIds['home'])) {
            $frontId = (int) $pageIds['home'];
        }
        if ($frontId === 0 && $pageIds !== []) {
            $frontId = (int) reset($pageIds);
        }

        if ($frontId > 0) {
            $this->runWp($wpCli, $sitePath, 'option update show_on_front page');
            $this->runWp($wpCli, $sitePath, 'option update page_on_front ' . $frontId);
        }
        if ($blogId > 0) {
            $this->runWp($wpCli, $sitePath, 'option update page_for_posts ' . $blogId);
        }

        $posts = is_array($package['posts'] ?? null) ? $package['posts'] : [];
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }
            $title   = (string) ($post['title'] ?? 'Post');
            $slug    = (string) ($post['slug'] ?? $this->installer->slugify($title));
            $content = (string) ($post['content'] ?? '');
            $excerpt = (string) ($post['excerpt'] ?? '');
            $tmp = tempnam(sys_get_temp_dir(), 'wppost');
            file_put_contents($tmp, $content);
            $this->runWp(
                $wpCli,
                $sitePath,
                sprintf(
                    'post create %s --post_type=post --post_title=%s --post_name=%s --post_excerpt=%s --post_status=publish',
                    escapeshellarg($tmp),
                    escapeshellarg($title),
                    escapeshellarg($slug),
                    escapeshellarg($excerpt)
                )
            );
            @unlink($tmp);
            $this->emit('Created post: ' . $title);
        }

        // Menus
        $menus = is_array($package['menus'] ?? null) ? $package['menus'] : [];
        foreach ($menus as $location => $items) {
            if (!is_array($items)) {
                continue;
            }
            $menuName = ucfirst((string) $location);
            $this->runWp($wpCli, $sitePath, 'menu create ' . escapeshellarg($menuName));
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $title = (string) ($item['title'] ?? '');
                $slug  = (string) ($item['slug'] ?? '');
                $pageId = $pageIds[$slug] ?? 0;
                if ($pageId > 0) {
                    $this->runWp(
                        $wpCli,
                        $sitePath,
                        sprintf(
                            'menu item add-post %s %d --title=%s',
                            escapeshellarg($menuName),
                            $pageId,
                            escapeshellarg($title)
                        )
                    );
                }
            }
            // Assign if location exists
            $this->runWp(
                $wpCli,
                $sitePath,
                sprintf('menu location assign %s %s', escapeshellarg($menuName), escapeshellarg((string) $location)),
                false
            );
        }

        // Register patterns via a small PHP drop-in if provided
        $this->writePatternsPhp($sitePath, $themeSlug, $package);
    }

    /**
     * @param array<string, mixed> $package
     */
    private function applyWithWpLoad(
        string $sitePath,
        string $siteUrl,
        string $siteTitle,
        string $themeSlug,
        array $package
    ): void {
        // Isolate WP bootstrap in a subprocess-like include with output buffering
        $applyScript = $sitePath . DIRECTORY_SEPARATOR . 'ai-tool-apply.php';
        $payloadFile = $sitePath . DIRECTORY_SEPARATOR . 'ai-tool-package.json';
        file_put_contents(
            $payloadFile,
            json_encode($package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $php = $this->buildApplyScript($themeSlug, $siteTitle, $siteUrl);
        file_put_contents($applyScript, $php);

        $phpBin = $this->findPhpBinary();
        $cmd = sprintf(
            '%s %s 2>&1',
            escapeshellarg($phpBin),
            escapeshellarg($applyScript)
        );
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        foreach ($output as $line) {
            $this->emit($line);
        }
        @unlink($applyScript);
        @unlink($payloadFile);

        if ($code !== 0) {
            throw new RuntimeException('Failed applying content via wp-load (exit ' . $code . ').');
        }
    }

    private function buildApplyScript(string $themeSlug, string $siteTitle, string $siteUrl): string
    {
        $themeSlugEsc = var_export($themeSlug, true);
        $siteTitleEsc = var_export($siteTitle, true);
        $siteUrlEsc   = var_export($siteUrl, true);

        return <<<PHP
<?php
define('WP_USE_THEMES', false);
require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

\$package = json_decode((string) file_get_contents(__DIR__ . '/ai-tool-package.json'), true);
if (!is_array(\$package)) {
    fwrite(STDERR, "Invalid package\\n");
    exit(1);
}

switch_theme({$themeSlugEsc});
update_option('blogname', {$siteTitleEsc});
update_option('siteurl', {$siteUrlEsc});
update_option('home', {$siteUrlEsc});
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();

\$settings = is_array(\$package['settings'] ?? null) ? \$package['settings'] : [];
if (!empty(\$settings['timezone'])) {
    update_option('timezone_string', (string) \$settings['timezone']);
}

\$pageIds = [];
\$frontId = 0;
\$blogId = 0;
\$blogSlug = (string) (\$settings['blog_page_slug'] ?? 'blog');
\$blogTitle = (string) (\$settings['blog_page_title'] ?? 'Blog');

foreach ((array) (\$package['pages'] ?? []) as \$page) {
    if (!is_array(\$page)) continue;
    \$title = (string) (\$page['title'] ?? 'Page');
    \$slug  = (string) (\$page['slug'] ?? sanitize_title(\$title));
    \$id = wp_insert_post([
        'post_title'   => \$title,
        'post_name'    => \$slug,
        'post_content' => (string) (\$page['content'] ?? ''),
        'post_status'  => (string) (\$page['status'] ?? 'publish'),
        'post_type'    => 'page',
    ], true);
    if (is_wp_error(\$id)) {
        echo 'Page error: ' . \$id->get_error_message() . PHP_EOL;
        continue;
    }
    \$pageIds[\$slug] = (int) \$id;
    if (!empty(\$page['is_front_page'])) \$frontId = (int) \$id;
    if (\$slug === \$blogSlug) \$blogId = (int) \$id;
    if (!empty(\$page['meta_title'])) update_post_meta((int)\$id, '_ai_meta_title', (string)\$page['meta_title']);
    if (!empty(\$page['meta_description'])) update_post_meta((int)\$id, '_ai_meta_description', (string)\$page['meta_description']);
    echo "Created page: {\$title} (#{\$id})" . PHP_EOL;
}

if (\$blogId === 0) {
    \$blogId = (int) wp_insert_post([
        'post_title' => \$blogTitle,
        'post_name' => \$blogSlug,
        'post_status' => 'publish',
        'post_type' => 'page',
    ]);
    \$pageIds[\$blogSlug] = \$blogId;
}

if (\$frontId === 0 && isset(\$pageIds['home'])) \$frontId = (int) \$pageIds['home'];
if (\$frontId === 0 && \$pageIds) \$frontId = (int) reset(\$pageIds);

if (\$frontId > 0) {
    update_option('show_on_front', 'page');
    update_option('page_on_front', \$frontId);
}
if (\$blogId > 0) {
    update_option('page_for_posts', \$blogId);
}

foreach ((array) (\$package['posts'] ?? []) as \$post) {
    if (!is_array(\$post)) continue;
    \$id = wp_insert_post([
        'post_title'   => (string) (\$post['title'] ?? 'Post'),
        'post_name'    => (string) (\$post['slug'] ?? ''),
        'post_content' => (string) (\$post['content'] ?? ''),
        'post_excerpt' => (string) (\$post['excerpt'] ?? ''),
        'post_status'  => 'publish',
        'post_type'    => 'post',
    ], true);
    if (!is_wp_error(\$id)) {
        if (!empty(\$post['categories']) && is_array(\$post['categories'])) {
            wp_set_post_terms((int)\$id, \$post['categories'], 'category', false);
        }
        if (!empty(\$post['tags']) && is_array(\$post['tags'])) {
            wp_set_post_terms((int)\$id, \$post['tags'], 'post_tag', false);
        }
        echo 'Created post: ' . (\$post['title'] ?? '') . PHP_EOL;
    }
}

\$menus = (array) (\$package['menus'] ?? []);
foreach (\$menus as \$location => \$items) {
    if (!is_array(\$items)) continue;
    \$menuName = ucfirst((string) \$location);
    \$menuId = wp_create_nav_menu(\$menuName);
    if (is_wp_error(\$menuId)) continue;
    foreach (\$items as \$item) {
        if (!is_array(\$item)) continue;
        \$slug = (string) (\$item['slug'] ?? '');
        \$pageId = \$pageIds[\$slug] ?? 0;
        if (\$pageId <= 0) continue;
        wp_update_nav_menu_item((int)\$menuId, 0, [
            'menu-item-title' => (string) (\$item['title'] ?? ''),
            'menu-item-object' => 'page',
            'menu-item-object-id' => \$pageId,
            'menu-item-type' => 'post_type',
            'menu-item-status' => 'publish',
        ]);
    }
    \$locations = get_theme_mod('nav_menu_locations', []);
    if (!is_array(\$locations)) \$locations = [];
    \$locations[(string)\$location] = (int) \$menuId;
    set_theme_mod('nav_menu_locations', \$locations);
    echo "Created menu: {\$menuName}" . PHP_EOL;
}

\$patterns = (array) (\$package['block_patterns'] ?? []);
if (\$patterns) {
    \$patternDir = get_theme_root() . '/' . {$themeSlugEsc} . '/patterns';
    if (!is_dir(\$patternDir)) {
        mkdir(\$patternDir, 0755, true);
    }
    foreach (\$patterns as \$pattern) {
        if (!is_array(\$pattern)) {
            continue;
        }
        \$rawSlug = strtolower((string) (\$pattern['slug'] ?? 'pattern'));
        \$slug = preg_replace('/[^a-z0-9\\-]+/', '-', \$rawSlug);
        \$slug = trim(str_replace('ai-starter/', '', (string) \$slug), '-');
        \$title = str_replace(['*/', "\\n"], ['', ' '], (string) (\$pattern['title'] ?? 'Pattern'));
        \$content = (string) (\$pattern['content'] ?? '');
        \$file = \$patternDir . '/' . basename((string) \$slug) . '.php';
        \$php = "<?php\\n/**\\n * Title: {\$title}\\n * Slug: ai-starter/{\$slug}\\n * Categories: featured\\n */\\n?>\\n" . \$content;
        file_put_contents(\$file, \$php);
        echo "Pattern: {\$slug}" . PHP_EOL;
    }
}

echo "APPLY_OK" . PHP_EOL;
PHP;
    }

    /**
     * @param array<string, mixed> $package
     */
    private function writePatternsPhp(string $sitePath, string $themeSlug, array $package): void
    {
        $patterns = $package['block_patterns'] ?? [];
        if (!is_array($patterns) || $patterns === []) {
            return;
        }

        $patternDir = $sitePath . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR .
            'themes' . DIRECTORY_SEPARATOR . $themeSlug . DIRECTORY_SEPARATOR . 'patterns';
        if (!is_dir($patternDir) && !mkdir($patternDir, 0755, true) && !is_dir($patternDir)) {
            return;
        }

        foreach ($patterns as $pattern) {
            if (!is_array($pattern)) {
                continue;
            }
            $slug = strtolower((string) ($pattern['slug'] ?? 'pattern'));
            $slug = preg_replace('/[^a-z0-9\\-]+/', '-', $slug) ?? 'pattern';
            $slug = trim(str_replace('ai-starter/', '', $slug), '-');
            $title = (string) ($pattern['title'] ?? 'Pattern');
            $content = (string) ($pattern['content'] ?? '');
            $headerTitle = str_replace(['*/', "\n"], ['', ' '], $title);
            $file = $patternDir . DIRECTORY_SEPARATOR . $slug . '.php';
            $php = "<?php\n/**\n * Title: {$headerTitle}\n * Slug: ai-starter/{$slug}\n * Categories: featured\n */\n?>\n" . $content;
            file_put_contents($file, $php);
            $this->emit('Pattern file: ' . $slug);
        }
    }

    /**
     * @param array<string, mixed> $package
     * @return array<string, string>
     */
    private function fallbackThemeFiles(array $package): array
    {
        $colors = $package['design_system']['colors'] ?? [];
        $primary = (string) ($colors['primary'] ?? '#0f766e');
        $secondary = (string) ($colors['secondary'] ?? '#134e4a');
        $accent = (string) ($colors['accent'] ?? '#f59e0b');
        $heading = (string) ($package['design_system']['typography']['heading_font'] ?? 'DM Sans');
        $body = (string) ($package['design_system']['typography']['body_font'] ?? 'Source Sans 3');
        $fontsUrl = (string) ($package['design_system']['typography']['google_fonts_url'] ??
            'https://fonts.googleapis.com/css2?family=DM+Sans:wght@500;700&family=Source+Sans+3:wght@400;600&display=swap');

        $themeJson = [
            '$schema' => 'https://schemas.wp.org/trunk/theme.json',
            'version' => 2,
            'settings' => [
                'color' => [
                    'palette' => [
                        ['slug' => 'primary', 'name' => 'Primary', 'color' => $primary],
                        ['slug' => 'secondary', 'name' => 'Secondary', 'color' => $secondary],
                        ['slug' => 'accent', 'name' => 'Accent', 'color' => $accent],
                        ['slug' => 'base', 'name' => 'Base', 'color' => '#ffffff'],
                        ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#111827'],
                    ],
                ],
                'typography' => [
                    'fontFamilies' => [
                        [
                            'fontFamily' => "\"{$heading}\", system-ui, sans-serif",
                            'name' => 'Heading',
                            'slug' => 'heading',
                        ],
                        [
                            'fontFamily' => "\"{$body}\", system-ui, sans-serif",
                            'name' => 'Body',
                            'slug' => 'body',
                        ],
                    ],
                ],
                'layout' => [
                    'contentSize' => '720px',
                    'wideSize' => '1200px',
                ],
            ],
            'styles' => [
                'color' => [
                    'background' => 'var(--wp--preset--color--base)',
                    'text' => 'var(--wp--preset--color--contrast)',
                ],
                'typography' => [
                    'fontFamily' => 'var(--wp--preset--font-family--body)',
                ],
                'elements' => [
                    'heading' => [
                        'typography' => [
                            'fontFamily' => 'var(--wp--preset--font-family--heading)',
                        ],
                    ],
                    'button' => [
                        'color' => [
                            'background' => 'var(--wp--preset--color--primary)',
                            'text' => '#ffffff',
                        ],
                        'border' => ['radius' => '8px'],
                    ],
                ],
            ],
        ];

        return [
            'style.css' => "/*\nTheme Name: AI Starter\nDescription: Lightweight custom theme generated by AI Tool.\nVersion: 1.0.0\nText Domain: ai-starter\n*/\n\n:root {\n  --ai-primary: {$primary};\n  --ai-secondary: {$secondary};\n  --ai-accent: {$accent};\n}\n\nbody {\n  margin: 0;\n  font-family: \"{$body}\", system-ui, sans-serif;\n  color: #111827;\n  background: #fff;\n}\n\na { color: var(--ai-primary); }\n\n.site-header, .site-footer {\n  padding: 1.25rem 1.5rem;\n}\n\n.site-header {\n  border-bottom: 1px solid #e5e7eb;\n  display: flex;\n  gap: 1.5rem;\n  align-items: center;\n  justify-content: space-between;\n}\n\n.site-title {\n  font-family: \"{$heading}\", system-ui, sans-serif;\n  font-size: 1.25rem;\n  font-weight: 700;\n  text-decoration: none;\n  color: var(--ai-secondary);\n}\n\n.wp-block-button__link {\n  background: var(--ai-primary);\n  border-radius: 8px;\n}\n\n.site-main {\n  max-width: 1200px;\n  margin: 0 auto;\n  padding: 2rem 1.5rem 4rem;\n}\n",
            'functions.php' => "<?php\nadd_action('after_setup_theme', function () {\n    add_theme_support('title-tag');\n    add_theme_support('post-thumbnails');\n    add_theme_support('wp-block-styles');\n    add_theme_support('editor-styles');\n    add_theme_support('responsive-embeds');\n    add_theme_support('align-wide');\n    register_nav_menus([\n        'primary' => __('Primary Menu', 'ai-starter'),\n        'footer'  => __('Footer Menu', 'ai-starter'),\n    ]);\n});\n\nadd_action('wp_enqueue_scripts', function () {\n    wp_enqueue_style('ai-starter-fonts', " . var_export($fontsUrl, true) . ", [], null);\n    wp_enqueue_style('ai-starter-style', get_stylesheet_uri(), ['ai-starter-fonts'], '1.0.0');\n});\n",
            'theme.json' => json_encode($themeJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'index.php' => "<?php get_header(); ?>\n<main class=\"site-main\">\n<?php if (have_posts()) : while (have_posts()) : the_post(); ?>\n  <article <?php post_class(); ?>>\n    <h1><?php the_title(); ?></h1>\n    <?php the_content(); ?>\n  </article>\n<?php endwhile; else : ?>\n  <p><?php esc_html_e('No content found.', 'ai-starter'); ?></p>\n<?php endif; ?>\n</main>\n<?php get_footer();\n",
            'front-page.php' => "<?php get_header(); ?>\n<main class=\"site-main\">\n<?php while (have_posts()) : the_post(); the_content(); endwhile; ?>\n</main>\n<?php get_footer();\n",
            'page.php' => "<?php get_header(); ?>\n<main class=\"site-main\">\n<?php while (have_posts()) : the_post(); ?>\n  <article <?php post_class(); ?>>\n    <h1><?php the_title(); ?></h1>\n    <?php the_content(); ?>\n  </article>\n<?php endwhile; ?>\n</main>\n<?php get_footer();\n",
            'single.php' => "<?php get_header(); ?>\n<main class=\"site-main\">\n<?php while (have_posts()) : the_post(); ?>\n  <article <?php post_class(); ?>>\n    <h1><?php the_title(); ?></h1>\n    <div class=\"entry-meta\"><?php echo esc_html(get_the_date()); ?></div>\n    <?php the_content(); ?>\n  </article>\n<?php endwhile; ?>\n</main>\n<?php get_footer();\n",
            'archive.php' => "<?php get_header(); ?>\n<main class=\"site-main\">\n  <h1><?php the_archive_title(); ?></h1>\n<?php if (have_posts()) : while (have_posts()) : the_post(); ?>\n  <article <?php post_class(); ?>>\n    <h2><a href=\"<?php the_permalink(); ?>\"><?php the_title(); ?></a></h2>\n    <?php the_excerpt(); ?>\n  </article>\n<?php endwhile; the_posts_pagination(); endif; ?>\n</main>\n<?php get_footer();\n",
            'header.php' => "<!DOCTYPE html>\n<html <?php language_attributes(); ?>>\n<head>\n<meta charset=\"<?php bloginfo('charset'); ?>\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<?php wp_head(); ?>\n</head>\n<body <?php body_class(); ?>>\n<?php wp_body_open(); ?>\n<header class=\"site-header\">\n  <a class=\"site-title\" href=\"<?php echo esc_url(home_url('/')); ?>\"><?php bloginfo('name'); ?></a>\n  <nav class=\"primary-nav\" aria-label=\"Primary\">\n    <?php wp_nav_menu(['theme_location' => 'primary', 'container' => false, 'fallback_cb' => false]); ?>\n  </nav>\n</header>\n",
            'footer.php' => "<footer class=\"site-footer\">\n  <nav aria-label=\"Footer\">\n    <?php wp_nav_menu(['theme_location' => 'footer', 'container' => false, 'fallback_cb' => false]); ?>\n  </nav>\n  <p>&copy; <?php echo esc_html(date('Y')); ?> <?php bloginfo('name'); ?></p>\n</footer>\n<?php wp_footer(); ?>\n</body>\n</html>\n",
            '404.php' => "<?php get_header(); ?>\n<main class=\"site-main\">\n  <h1><?php esc_html_e('Page not found', 'ai-starter'); ?></h1>\n  <p><?php esc_html_e('The page you requested could not be found.', 'ai-starter'); ?></p>\n  <p><a class=\"wp-block-button__link\" href=\"<?php echo esc_url(home_url('/')); ?>\"><?php esc_html_e('Back to home', 'ai-starter'); ?></a></p>\n</main>\n<?php get_footer();\n",
        ];
    }

    /**
     * @param array<string, mixed> $install
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    private function buildReport(
        string $websiteName,
        string $domain,
        array $install,
        array $package
    ): array {
        $pages = [];
        foreach ((array) ($package['pages'] ?? []) as $page) {
            if (is_array($page) && isset($page['title'])) {
                $pages[] = (string) $page['title'];
            }
        }
        $posts = [];
        foreach ((array) ($package['posts'] ?? []) as $post) {
            if (is_array($post) && isset($post['title'])) {
                $posts[] = (string) $post['title'];
            }
        }
        $patterns = [];
        foreach ((array) ($package['block_patterns'] ?? []) as $pattern) {
            if (is_array($pattern) && isset($pattern['title'])) {
                $patterns[] = (string) $pattern['title'];
            }
        }

        return [
            'website_summary' => $package['website_summary'] ?? $websiteName,
            'sitemap' => $package['sitemap'] ?? $pages,
            'brand_guidelines' => $package['brand_guidelines'] ?? [],
            'color_palette' => $package['design_system']['colors'] ?? [],
            'typography' => $package['design_system']['typography'] ?? [],
            'design_system' => $package['design_system'] ?? [],
            'theme_structure' => array_keys((array) ($package['theme_files'] ?? [])),
            'database_name' => $install['db_name'],
            'local_url' => $install['url'],
            'admin_username' => $this->config['wp_admin_user'],
            'admin_password' => $this->config['wp_admin_pass'],
            'folder_path' => $install['path'],
            'installed_pages' => $pages,
            'installed_posts' => $posts,
            'block_patterns_created' => $patterns,
            'image_prompts' => $package['image_prompts'] ?? [],
            'seo_summary' => $package['seo_summary'] ?? '',
            'accessibility_summary' => $package['accessibility_summary'] ?? '',
            'performance_summary' => $package['performance_summary'] ?? '',
            'remaining_manual_tasks' => $package['final_report']['remaining_manual_tasks']
                ?? [
                    'Add real images using the provided image prompts',
                    'Replace contact placeholders with real business details',
                    'Review sample testimonials before going live',
                ],
            'domain' => $domain,
            'logs' => $this->logLines,
        ];
    }

    private function runWp(string $wpCli, string $sitePath, string $args, bool $throw = true): string
    {
        $cmd = sprintf(
            '%s %s --path=%s 2>&1',
            escapeshellarg($wpCli),
            $args,
            escapeshellarg($sitePath)
        );
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $text = implode("\n", $output);
        if ($code !== 0 && $throw) {
            throw new RuntimeException('WP-CLI failed: ' . $cmd . "\n" . $text);
        }
        return $text;
    }

    private function findPhpBinary(): string
    {
        if (defined('PHP_BINARY') && PHP_BINARY) {
            return PHP_BINARY;
        }
        $xampp = (string) ($this->config['xampp_path'] ?? '');
        $candidates = [
            $xampp . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe',
            'php',
        ];
        foreach ($candidates as $bin) {
            if ($bin === 'php' || is_file($bin)) {
                return $bin;
            }
        }
        return 'php';
    }

    private function ensureAppDirs(): void
    {
        foreach (['logs_dir', 'generated_dir'] as $key) {
            $dir = (string) ($this->config[$key] ?? '');
            if ($dir !== '' && !is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    private function emit(string $message): void
    {
        $line = '[' . date('H:i:s') . '] ' . $message;
        $this->logLines[] = $line;

        $logFile = ($this->config['logs_dir'] ?? '') . DIRECTORY_SEPARATOR . 'generator.log';
        if (is_dir(dirname($logFile))) {
            @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
        }

        if ($this->logger) {
            ($this->logger)($line);
        }
    }
}
