<?php

declare(strict_types=1);

const DATABASE_FILE = __DIR__ . '/data/reader.sqlite';
const INITIAL_ARTICLE_LIMIT = 5;
const HOME_ARTICLE_LIMIT = 50;
const FEED_TIMEOUT_SECONDS = 15;

function database(): PDO
{
    static $database = null;

    if ($database instanceof PDO) {
        return $database;
    }

    $dataDirectory = dirname(DATABASE_FILE);

    if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0775, true) && !is_dir($dataDirectory)) {
        throw new RuntimeException('The data directory could not be created.');
    }

    if (!is_writable($dataDirectory)) {
        throw new RuntimeException('The data directory is not writable by PHP.');
    }

    $database = new PDO('sqlite:' . DATABASE_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec('PRAGMA journal_mode = WAL');
    createSchema($database);

    return $database;
}

function createSchema(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS feeds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            url TEXT NOT NULL UNIQUE,
            domain TEXT NOT NULL,
            language TEXT NOT NULL DEFAULT "",
            tags TEXT NOT NULL DEFAULT "",
            is_top INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $database->exec(
        'CREATE TABLE IF NOT EXISTS articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            feed_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            url TEXT NOT NULL UNIQUE,
            published_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (feed_id) REFERENCES feeds(id) ON DELETE CASCADE
        )'
    );

    $database->exec('CREATE INDEX IF NOT EXISTS idx_articles_published_at ON articles(published_at DESC)');
    $database->exec('CREATE INDEX IF NOT EXISTS idx_articles_feed_id ON articles(feed_id)');
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeTags(string $tags): string
{
    $items = preg_split('/[,;]+/', $tags) ?: [];
    $normalized = [];

    foreach ($items as $item) {
        $item = trim(preg_replace('/\s+/', ' ', $item) ?? '');

        if ($item === '') {
            continue;
        }

        $key = function_exists('mb_strtolower') ? mb_strtolower($item, 'UTF-8') : strtolower($item);
        $normalized[$key] = $item;
    }

    natcasesort($normalized);

    // Store comma-delimited values without padding so exact tag filters stay simple.
    return implode(',', $normalized);
}

function splitTags(string $tags): array
{
    if ($tags === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $tags))));
}

function domainFromUrl(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);

    if (!is_string($host) || $host === '') {
        throw new RuntimeException('The feed URL does not contain a valid host name.');
    }

    return preg_replace('/^www\./i', '', strtolower($host)) ?? strtolower($host);
}

function validateFeedUrl(string $url): string
{
    $url = trim($url);

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Enter a valid feed URL.');
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Only HTTP and HTTPS feed URLs are supported.');
    }

    return $url;
}

function fetchFeed(string $url): string
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('The feed request could not be initialized.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => FEED_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => FEED_TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'MiniRSSReader/1.0 (+https://github.com/epokheLab/mini-rss-reader)',
            CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*;q=0.1'],
        ]);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body) || $body === '') {
            throw new RuntimeException($error !== '' ? 'The feed could not be downloaded: ' . $error : 'The feed returned no content.');
        }

        if ($status >= 400) {
            throw new RuntimeException('The feed server returned HTTP status ' . $status . '.');
        }

        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => FEED_TIMEOUT_SECONDS,
            'follow_location' => 1,
            'max_redirects' => 5,
            'user_agent' => 'MiniRSSReader/1.0 (+https://github.com/epokheLab/mini-rss-reader)',
            'header' => "Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*;q=0.1\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if (!is_string($body) || $body === '') {
        throw new RuntimeException('The feed could not be downloaded. Enable cURL or allow_url_fopen in PHP.');
    }

    return $body;
}

function parseFeed(string $xml): array
{
    $previousSetting = libxml_use_internal_errors(true);
    $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previousSetting);

    if (!$document instanceof SimpleXMLElement) {
        $message = isset($errors[0]) ? trim($errors[0]->message) : 'Unknown XML error';
        throw new RuntimeException('The URL does not contain a valid RSS or Atom feed: ' . $message);
    }

    $rootName = strtolower($document->getName());
    $entries = [];

    if ($rootName === 'feed') {
        foreach ($document->entry as $entry) {
            $entries[] = parseAtomEntry($entry);
        }
    } elseif ($rootName === 'rss' && isset($document->channel)) {
        foreach ($document->channel->item as $item) {
            $entries[] = parseRssItem($item);
        }
    } elseif ($rootName === 'rdf' || $rootName === 'rdf:rdf') {
        foreach ($document->item as $item) {
            $entries[] = parseRssItem($item);
        }
    } elseif (isset($document->channel->item)) {
        foreach ($document->channel->item as $item) {
            $entries[] = parseRssItem($item);
        }
    }

    $entries = array_values(array_filter($entries, static fn (array $entry): bool => $entry['title'] !== '' && $entry['url'] !== ''));

    if ($entries === []) {
        throw new RuntimeException('No readable articles were found in this feed.');
    }

    usort($entries, static fn (array $a, array $b): int => strcmp($b['published_at'], $a['published_at']));

    return $entries;
}

function parseAtomEntry(SimpleXMLElement $entry): array
{
    $url = '';

    foreach ($entry->link as $link) {
        $attributes = $link->attributes();
        $relationship = strtolower((string) ($attributes['rel'] ?? 'alternate'));
        $href = trim((string) ($attributes['href'] ?? ''));

        if ($href !== '' && ($relationship === '' || $relationship === 'alternate')) {
            $url = $href;
            break;
        }
    }

    if ($url === '' && isset($entry->link)) {
        $url = trim((string) $entry->link);
    }

    $date = firstNonEmpty([
        (string) ($entry->published ?? ''),
        (string) ($entry->updated ?? ''),
        (string) ($entry->created ?? ''),
    ]);

    return normalizeEntry((string) ($entry->title ?? ''), $url, $date);
}

function parseRssItem(SimpleXMLElement $item): array
{
    $namespaces = $item->getNameSpaces(true);
    $date = firstNonEmpty([
        (string) ($item->pubDate ?? ''),
        (string) ($item->date ?? ''),
    ]);

    if ($date === '' && isset($namespaces['dc'])) {
        $date = (string) ($item->children($namespaces['dc'])->date ?? '');
    }

    $url = trim((string) ($item->link ?? ''));

    if ($url === '' && isset($item->guid)) {
        $guid = trim((string) $item->guid);
        if (filter_var($guid, FILTER_VALIDATE_URL)) {
            $url = $guid;
        }
    }

    return normalizeEntry((string) ($item->title ?? ''), $url, $date);
}

function normalizeEntry(string $title, string $url, string $date): array
{
    $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $url = '';
    }

    $timestamp = $date !== '' ? strtotime($date) : false;
    $publishedAt = $timestamp !== false ? gmdate('Y-m-d H:i:s', $timestamp) : gmdate('Y-m-d H:i:s');

    return [
        'title' => $title,
        'url' => $url,
        'published_at' => $publishedAt,
    ];
}

function firstNonEmpty(array $values): string
{
    foreach ($values as $value) {
        $value = trim($value);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function addFeed(string $url, string $language, string $tags, bool $isTop): int
{
    $url = validateFeedUrl($url);
    $language = in_array($language, ['fr', 'en'], true) ? $language : '';
    $tags = normalizeTags($tags);
    $articles = array_slice(parseFeed(fetchFeed($url)), 0, INITIAL_ARTICLE_LIMIT);
    $database = database();

    $database->beginTransaction();

    try {
        $statement = $database->prepare(
            'INSERT INTO feeds (url, domain, language, tags, is_top) VALUES (:url, :domain, :language, :tags, :is_top)'
        );
        $statement->execute([
            ':url' => $url,
            ':domain' => domainFromUrl($url),
            ':language' => $language,
            ':tags' => $tags,
            ':is_top' => $isTop ? 1 : 0,
        ]);

        $feedId = (int) $database->lastInsertId();
        $inserted = insertArticles($database, $feedId, $articles);
        $database->commit();

        return $inserted;
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }

        if ($error instanceof PDOException && str_contains(strtolower($error->getMessage()), 'unique')) {
            throw new RuntimeException('This feed has already been added.');
        }

        throw $error;
    }
}

function insertArticles(PDO $database, int $feedId, array $articles): int
{
    $statement = $database->prepare(
        'INSERT OR IGNORE INTO articles (feed_id, title, url, published_at)
         VALUES (:feed_id, :title, :url, :published_at)'
    );
    $inserted = 0;

    foreach ($articles as $article) {
        $statement->execute([
            ':feed_id' => $feedId,
            ':title' => $article['title'],
            ':url' => $article['url'],
            ':published_at' => $article['published_at'],
        ]);
        $inserted += $statement->rowCount();
    }

    return $inserted;
}

function refreshFeeds(): array
{
    $database = database();
    $feeds = $database->query('SELECT id, url, domain FROM feeds ORDER BY id')->fetchAll();
    $inserted = 0;
    $failures = [];

    foreach ($feeds as $feed) {
        try {
            $articles = parseFeed(fetchFeed($feed['url']));
            $inserted += insertArticles($database, (int) $feed['id'], $articles);
        } catch (Throwable $error) {
            $failures[] = $feed['domain'] . ': ' . $error->getMessage();
        }
    }

    return ['inserted' => $inserted, 'failures' => $failures, 'feed_count' => count($feeds)];
}

function deleteFeed(int $feedId): void
{
    $statement = database()->prepare('DELETE FROM feeds WHERE id = :id');
    $statement->execute([':id' => $feedId]);
}

function updateFeedTags(int $feedId, string $tags): void
{
    $statement = database()->prepare('UPDATE feeds SET tags = :tags WHERE id = :id');
    $statement->execute([
        ':id' => $feedId,
        ':tags' => normalizeTags($tags),
    ]);
}

function getFeeds(): array
{
    return database()->query('SELECT * FROM feeds ORDER BY domain COLLATE NOCASE, id')->fetchAll();
}

function getTags(): array
{
    $tags = [];

    foreach (getFeeds() as $feed) {
        foreach (splitTags($feed['tags']) as $tag) {
            $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
            $tags[$key] = $tag;
        }
    }

    natcasesort($tags);

    return array_values($tags);
}

function getArticles(string $view = '', string $tag = ''): array
{
    $where = [];
    $parameters = [];

    if ($view === 'fr' || $view === 'en') {
        $where[] = 'feeds.language = :language';
        $parameters[':language'] = $view;
    } elseif ($view === 'top') {
        $where[] = 'feeds.is_top = 1';
    }

    if ($tag !== '') {
        $where[] = "(',' || lower(feeds.tags) || ',') LIKE :tag";
        $parameters[':tag'] = '%,' . strtolower($tag) . ',%';
    }

    $sql = 'SELECT articles.*, feeds.domain
            FROM articles
            JOIN feeds ON feeds.id = articles.feed_id';

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY articles.published_at DESC, articles.id DESC LIMIT ' . HOME_ARTICLE_LIMIT;
    $statement = database()->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchAll();
}

function formatArticleDate(string $date): string
{
    try {
        $value = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        $value = $value->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $value->format('M j, Y · H:i');
    } catch (Throwable) {
        return $date;
    }
}

function queryUrl(array $parameters): string
{
    $parameters = array_filter($parameters, static fn ($value): bool => $value !== '');
    return 'index.php' . ($parameters === [] ? '' : '?' . http_build_query($parameters));
}
