<?php

declare(strict_types=1);

require __DIR__ . '/functions.php';

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh'])) {
    $result = refreshFeeds();

    if ($result['feed_count'] === 0) {
        $message = 'Add a source before refreshing your feeds.';
        $messageType = 'notice';
    } elseif ($result['failures'] !== []) {
        $message = sprintf(
            'Refresh completed with %d new article%s. %d source%s could not be updated.',
            $result['inserted'],
            $result['inserted'] === 1 ? '' : 's',
            count($result['failures']),
            count($result['failures']) === 1 ? '' : 's'
        );
        $messageType = 'notice';
    } else {
        $message = sprintf(
            'Refresh complete: %d new article%s added.',
            $result['inserted'],
            $result['inserted'] === 1 ? '' : 's'
        );
    }
}

$view = isset($_GET['view']) && in_array($_GET['view'], ['fr', 'en', 'top'], true) ? $_GET['view'] : '';
$tag = isset($_GET['tag']) ? trim((string) $_GET['tag']) : '';
$tags = getTags();

if ($tag !== '' && !in_array($tag, $tags, true)) {
    $tag = '';
}

$articles = getArticles($view, $tag);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Mini RSS Reader</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&amp;family=Raleway:wght@600;700&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="shell">
  <header class="topbar">
    <div>
      <p class="eyebrow">Mini RSS Reader</p>
      <h1>My feeds</h1>
    </div>
    <div class="topbar-actions">
      <form method="post">
        <button class="button" type="submit" name="refresh">Refresh</button>
      </form>
      <a class="button ghost" href="admin.php">Manage Sources</a>
    </div>
  </header>

  <nav class="filters" aria-label="Feed filters">
    <a class="filter <?= $view === '' && $tag === '' ? 'active' : '' ?>" href="index.php">All</a>
    <a class="filter <?= $view === 'fr' && $tag === '' ? 'active' : '' ?>" href="<?= escape(queryUrl(['view' => 'fr'])) ?>">French</a>
    <a class="filter <?= $view === 'en' && $tag === '' ? 'active' : '' ?>" href="<?= escape(queryUrl(['view' => 'en'])) ?>">English</a>
    <a class="filter <?= $view === 'top' && $tag === '' ? 'active' : '' ?>" href="<?= escape(queryUrl(['view' => 'top'])) ?>">Top sources</a>
    <?php foreach ($tags as $availableTag): ?>
      <a class="filter <?= $tag === $availableTag ? 'active' : '' ?>" href="<?= escape(queryUrl(['tag' => $availableTag])) ?>"><?= escape($availableTag) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($message !== ''): ?>
    <div class="message <?= escape($messageType) ?>" role="status"><?= escape($message) ?></div>
  <?php endif; ?>

  <section class="article-list" aria-label="Recent articles">
    <?php if ($articles === []): ?>
      <div class="empty-state">
        <h2>No articles yet</h2>
        <p>Add a source or choose another filter to start reading.</p>
        <a class="button" href="admin.php">Add a source</a>
      </div>
    <?php else: ?>
      <?php foreach ($articles as $article): ?>
        <article class="article-row">
          <p class="article-meta"><?= escape($article['domain']) ?> <span aria-hidden="true">·</span> <?= escape(formatArticleDate($article['published_at'])) ?></p>
          <h2><a href="<?= escape($article['url']) ?>" target="_blank" rel="noopener noreferrer"><?= escape($article['title']) ?></a></h2>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
