<?php

declare(strict_types=1);

require __DIR__ . '/functions.php';

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        try {
            $inserted = addFeed(
                (string) ($_POST['url'] ?? ''),
                (string) ($_POST['language'] ?? ''),
                (string) ($_POST['tags'] ?? ''),
                isset($_POST['is_top'])
            );
            $message = sprintf(
                'Source added. %d recent article%s imported.',
                $inserted,
                $inserted === 1 ? '' : 's'
            );
        } catch (Throwable $error) {
            $message = $error->getMessage();
            $messageType = 'error';
        }
    } elseif (isset($_POST['delete'])) {
        deleteFeed((int) ($_POST['id'] ?? 0));
        $message = 'Source and its articles deleted.';
    }
}

$feeds = getFeeds();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Sources · Mini RSS Reader</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="shell narrow">
  <header class="topbar">
    <div>
      <p class="eyebrow">Simple feed management</p>
      <h1>Sources</h1>
    </div>
    <a class="button ghost" href="index.php">← Back</a>
  </header>

  <?php if ($message !== ''): ?>
    <div class="message <?= escape($messageType) ?>" role="status"><?= escape($message) ?></div>
  <?php endif; ?>

  <section class="panel">
    <h2>Add a feed</h2>
    <form method="post" class="form-grid">
      <label class="full">RSS or Atom URL
        <input type="url" name="url" required placeholder="https://example.com/feed.xml">
      </label>
      <label>Language
        <select name="language">
          <option value="">—</option>
          <option value="fr">French</option>
          <option value="en">English</option>
        </select>
      </label>
      <label>Tags
        <input type="text" name="tags" placeholder="SEO, AI, technology">
      </label>
      <label class="check"><input type="checkbox" name="is_top"> Top source</label>
      <div class="full"><button class="button" type="submit" name="add">Add source</button></div>
    </form>
    <p class="hint">Only the five most recent articles are imported when a source is added.</p>
  </section>

  <section class="panel">
    <h2>Saved sources</h2>
    <?php if ($feeds === []): ?>
      <p class="hint">No sources have been added yet.</p>
    <?php else: ?>
      <div class="source-list">
        <?php foreach ($feeds as $feed): ?>
          <div class="source-row">
            <div>
              <strong><?= escape($feed['domain']) ?></strong>
              <div class="source-meta">
                <?= $feed['language'] !== '' ? escape(strtoupper($feed['language'])) : 'No language' ?>
                <?= (int) $feed['is_top'] === 1 ? ' · Top source' : '' ?>
                <?= $feed['tags'] !== '' ? ' · ' . escape(implode(', ', splitTags($feed['tags']))) : '' ?>
              </div>
            </div>
            <form method="post" onsubmit="return confirm('Delete this source and all of its articles?')">
              <input type="hidden" name="id" value="<?= (int) $feed['id'] ?>">
              <button class="link-danger" type="submit" name="delete">Delete</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
