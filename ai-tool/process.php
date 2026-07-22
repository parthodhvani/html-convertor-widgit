<?php
/**
 * Handles form submit — calls Claude and installs WordPress.
 * Returns JSON when requested via fetch; otherwise renders an HTML report.
 */

declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

require_once __DIR__ . '/includes/SiteGenerator.php';

$config = require __DIR__ . '/config.php';

$wantsJson = (
    (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_GET['format']) && $_GET['format'] === 'json')
);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        exit;
    }
    header('Location: index.php');
    exit;
}

$websiteName = trim((string) ($_POST['website_name'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$domain      = trim((string) ($_POST['domain'] ?? ''));

$logs = [];
$logger = static function (string $line) use (&$logs, $wantsJson): void {
    $logs[] = $line;
    if ($wantsJson) {
        // Streaming is hard over single JSON response; collect for final payload.
        return;
    }
};

try {
    $generator = new SiteGenerator($config, $logger);
    $report = $generator->generate($websiteName, $description, $domain);

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'     => true,
            'report' => $report,
            'logs'   => $report['logs'] ?? $logs,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    renderHtml(true, $report, null);
} catch (Throwable $e) {
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'error' => $e->getMessage(),
            'logs'  => $logs,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    renderHtml(false, null, $e->getMessage());
}

/**
 * @param array<string, mixed>|null $report
 */
function renderHtml(bool $ok, ?array $report, ?string $error): void
{
    $title = $ok ? 'Website generated' : 'Generation failed';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <div class="page">
    <header class="hero">
      <p class="eyebrow">AI Tool</p>
      <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    </header>
    <main class="card">
      <?php if (!$ok): ?>
        <div class="notice notice-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <p><a class="btn" href="index.php">Back to form</a></p>
      <?php else: ?>
        <?php
        $url = htmlspecialchars((string) ($report['local_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $path = htmlspecialchars((string) ($report['folder_path'] ?? ''), ENT_QUOTES, 'UTF-8');
        $db = htmlspecialchars((string) ($report['database_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $user = htmlspecialchars((string) ($report['admin_username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $pass = htmlspecialchars((string) ($report['admin_password'] ?? ''), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="notice notice-ok">
          Site is ready at <a href="<?= $url ?>" target="_blank" rel="noopener"><?= $url ?></a>
        </div>
        <dl class="report-grid">
          <dt>Folder</dt><dd><code><?= $path ?></code></dd>
          <dt>Database</dt><dd><code><?= $db ?></code></dd>
          <dt>Admin</dt><dd><code><?= $user ?></code> / <code><?= $pass ?></code></dd>
          <dt>Admin URL</dt><dd><a href="<?= $url ?>/wp-admin/" target="_blank" rel="noopener"><?= $url ?>/wp-admin/</a></dd>
        </dl>

        <h2>Website summary</h2>
        <p><?= nl2br(htmlspecialchars((string) ($report['website_summary'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>

        <h2>Sitemap</h2>
        <ul>
          <?php foreach ((array) ($report['sitemap'] ?? []) as $item): ?>
            <li><?= htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>

        <h2>Color palette</h2>
        <pre class="log"><?= htmlspecialchars(json_encode($report['color_palette'] ?? [], JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') ?></pre>

        <h2>Image prompts</h2>
        <pre class="log"><?= htmlspecialchars(json_encode($report['image_prompts'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></pre>

        <h2>Remaining manual tasks</h2>
        <ul>
          <?php foreach ((array) ($report['remaining_manual_tasks'] ?? []) as $task): ?>
            <li><?= htmlspecialchars((string) $task, ENT_QUOTES, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>

        <h2>Full report (JSON)</h2>
        <pre class="log"><?= htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></pre>

        <p><a class="btn" href="index.php">Generate another site</a></p>
      <?php endif; ?>
    </main>
  </div>
</body>
</html>
    <?php
}
