<?php
/**
 * AI Tool – form UI
 * Place this folder in: D:\xampp\htdocs\ai-tool\
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$provider = strtolower((string) ($config['ai_provider'] ?? 'local'));
$claudeConfigured = (($config['anthropic_api_key'] ?? '') !== ''
    && ($config['anthropic_api_key'] ?? '') !== 'YOUR_ANTHROPIC_API_KEY_HERE');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AI WordPress Website Generator</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <div class="page">
    <header class="hero">
      <p class="eyebrow">AI Tool</p>
      <h1>WordPress Website Generator</h1>
      <p class="lede">
        Enter your business details. This tool builds a complete local WordPress site
        under your htdocs folder with database, custom theme, and Gutenberg content.
        <strong>No Claude or Cursor API key required</strong> (local generator mode).
      </p>
    </header>

    <div class="notice notice-ok" role="status">
      Running in <strong><?= htmlspecialchars($provider, ENT_QUOTES, 'UTF-8') ?></strong> mode.
      <?php if ($provider === 'local'): ?>
        Works without any API key. Optional Claude key can be added later for richer AI copy.
      <?php elseif ($claudeConfigured): ?>
        Claude API key detected.
      <?php else: ?>
        Claude key missing — set <code>anthropic_api_key</code> or switch <code>ai_provider</code> to <code>local</code>.
      <?php endif; ?>
    </div>

    <main class="card">
      <form id="generate-form" method="post" action="process.php" autocomplete="on">
        <div class="field">
          <label for="website_name">Website name</label>
          <input
            type="text"
            id="website_name"
            name="website_name"
            required
            maxlength="120"
            placeholder="e.g. Blue Harbor Plumbing"
          >
        </div>

        <div class="field">
          <label for="description">Description</label>
          <textarea
            id="description"
            name="description"
            required
            rows="6"
            maxlength="4000"
            placeholder="Describe the business, services, audience, and any must-have pages…"
          ></textarea>
        </div>

        <div class="field">
          <label for="domain">Domain</label>
          <input
            type="text"
            id="domain"
            name="domain"
            required
            maxlength="180"
            placeholder="e.g. blueharborplumbing.com"
          >
        </div>

        <button type="submit" class="btn" id="submit-btn">
          Generate WordPress website
        </button>
      </form>

      <div id="status" class="status" hidden>
        <h2>Generation progress</h2>
        <pre id="log" class="log" aria-live="polite"></pre>
        <div id="result" class="result" hidden></div>
      </div>
    </main>

    <footer class="foot">
      <p>Admin credentials after install: <code>nimesh</code> / <code>nimesh@123</code></p>
      <p>Sites are created next to this folder in htdocs, e.g. <code>http://localhost/your-site-slug/</code></p>
      <p>Cursor API keys cannot power this form (they are for Cloud Agents, not chat completions).</p>
    </footer>
  </div>

  <script src="assets/app.js"></script>
</body>
</html>
