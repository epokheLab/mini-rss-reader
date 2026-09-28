# Mini RSS Reader

A tiny, self-hosted RSS and Atom reader focused on simplicity and readability.

Mini RSS Reader is a deliberately small PHP application for collecting a handful of feeds in one clean reading view. It uses SQLite, has no build step, no framework, no scheduled task, and no user account system.

## Features

- RSS 2.0, RSS 1.0/RDF, and Atom feeds
- A clean, chronological reading view
- Up to 50 recent articles on the home page
- Five recent articles imported when a source is first added
- Manual refresh for newly published articles
- Optional language, editable tags, and "top source" metadata
- Multiple tags per source, separated with commas
- Filters for French, English, top sources, and custom tags
- Article deduplication by URL
- SQLite storage created automatically on first run

## Requirements

- PHP 8.1 or later
- PDO SQLite extension
- SimpleXML extension
- cURL extension or `allow_url_fopen` enabled
- A web server that can write to the `data/` directory

## Installation

1. Download or clone the project:

   ```bash
   git clone https://github.com/epokheLab/mini-rss-reader.git
   cd mini-rss-reader
   ```

2. Upload the project files to your web server.
3. Make sure PHP can write to the `data/` directory.
4. Open `admin.php` in your browser and add your first RSS or Atom feed.
5. Return to `index.php` to read your articles.

The SQLite database is created automatically as `data/reader.sqlite`. It contains your feeds and articles and must remain private. It is excluded by `.gitignore` and is not included in release archives.

## Updating

Use the **Refresh** button on the home page whenever you want to check every saved feed for new articles. Mini RSS Reader intentionally does not use a cron job in this first version.

## Data and privacy

Mini RSS Reader has no authentication. Install it only at a location whose access model you understand. The included `robots.txt` asks search engines not to crawl the application, but it is not access control.

The included `data/.htaccess` blocks direct web access to the data directory on Apache-compatible servers. If you use Nginx or another web server, configure an equivalent rule or move the database outside the public web root.

Never commit or distribute your SQLite database. Before creating a release, verify that no `*.sqlite`, `*.sqlite3`, `*.db`, `*-wal`, `*-shm`, or `*-journal` files are present.

## Project structure

```text
mini-rss-reader/
├── admin.php          # Add and remove feed sources
├── index.php          # Reading view and manual refresh
├── functions.php      # Database, feed parsing, and shared helpers
├── assets/
│   └── style.css      # Interface styles
├── data/
│   ├── .gitkeep       # Keeps the empty directory in Git
│   └── .htaccess      # Blocks direct access on Apache
├── .gitignore
├── LICENSE
├── README.md
└── robots.txt
```

## V1 scope

The first version is intentionally limited: no login, search, favorites, read/unread state, images, excerpts, pagination, AI summaries, or background refresh. The goal is a lightweight reader that is easy to install, understand, and extend.

## Development

Created by Deborah Botton with assistance from ChatGPT by OpenAI.

## License

Mini RSS Reader is available under the [MIT License](LICENSE).
