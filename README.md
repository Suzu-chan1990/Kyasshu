<p align="center"><img src="./assets/banner-772x250.png" width="772" alt="Kyasshu"></p>

![WordPress](https://img.shields.io/badge/WordPress-7.1%2B-blue)
![PHP](https://img.shields.io/badge/PHP-8.4%2B-purple)
![nginx](https://img.shields.io/badge/nginx-required-009639)
![License](https://img.shields.io/badge/license-GPLv2%2B-green)

# Kyasshu キャッシュ

Static page cache for WordPress on nginx. Kyasshu saves every page as a finished HTML file, together with pre-compressed copies (gzip, Brotli, zstd and the dictionary formats DCB and DCZ). nginx serves these files straight from disk, so a cached page never starts PHP or touches the database.

Only Kyasshu's own preloader writes cache files. A normal visitor can never fill the cache with a broken, personal or half-loaded page.

Current version: `2.8.6` – see [CHANGELOG.md](CHANGELOG.md).

---

## Requirements

* WordPress 7.1 or newer
* PHP 8.4 or newer with zlib
* nginx (the cache is served by nginx rules, see [nginx setup](#nginx-setup))
* `exec()` allowed for PHP, if you want Brotli, zstd or DCB/DCZ through the command-line tools
* Optional: `brotli` command-line tool (1.1 or newer for DCB) or the PHP brotli extension
* Optional: `zstd` command-line tool or the PHP zstd extension
* Optional: WP-CLI for `wp kyasshu flush | gc | warmup`

Every format is only written if the server can produce it. Without Brotli or zstd, Kyasshu still works with gzip.

---

## Features

### Cache engine

* Cache files in the WP Rocket layout: `wp-content/cache/wp-rocket/<host>/<path>/index.html`
* Only the preloader writes cache files (secret token header). Visitors are always served, never written.
* Atomic writes (temp file + rename) with a lock per page: nginx never sees a half-written file
* Only real HTML is cached; very short output (white screen, fatal error) is skipped
* Tracking parameters (`utm_*`, `fbclid`, `gclid` …) are ignored; any other query string means a dynamic page and is not cached
* REST API and AJAX requests are never cached
* Optional HTML minifier
* URL exclusions (one per line)

### Pre-compressed copies

| File | Encoding | Made with |
|---|---|---|
| `index.html_gzip` | gzip | PHP zlib |
| `index.html_br` | Brotli (quality 11) | PHP brotli extension or `brotli` CLI |
| `index.html_zst` | zstd (level 19) | PHP zstd extension or `zstd` CLI |
| `index.html_dcb_<hash>` | DCB – Brotli with dictionary | PHP brotli ≥ 0.15 or `brotli` CLI ≥ 1.1 |
| `index.html_dcz_<hash>` | DCZ – zstd with dictionary | PHP zstd extension or `zstd` CLI |

The admin page shows which formats this server can produce and how (extension or CLI).

### Compression dictionary (DCB / DCZ)

[Compression Dictionary Transport](https://www.rfc-editor.org/rfc/rfc9842) lets the browser keep a dictionary made from your own site. Header, navigation, footer and card markup are then almost free on every following page, so a page is often only a fraction of its Brotli size.

* Kyasshu builds the dictionary from the cached home page and a few other cached pages (max. 256 KB)
* Pages announce it with `<link rel="compression-dictionary">`; the browser fetches it when idle
* The file name is the SHA-256 of the dictionary in Base64. Kyasshu tunes the dictionary so this hash only contains letters and digits, so nginx can use the browser's `Available-Dictionary` header directly as file suffix. **No generated nginx config and no nginx reload when the dictionary changes.**
* "Wörterbuch neu bauen" rebuilds it and rebuilds the cache
* Off by default, because it needs the nginx rules

Browsers without dictionary support (currently everything except Chromium-based browsers) automatically get zstd, Brotli or gzip. Dictionaries only work over HTTPS.

### Purge and preload

* Smart purge on save: the post, the home page, its term archives, its author archive and pages that list all artists
* Changed pages are queued and warmed again shortly after
* Full flush removes all cache files and starts the preloader
* The preloader works through a queue in batches of 20 via WP-Cron (home page, optional sitemap, pages, categories)
* Hourly garbage collection removes files older than the cache timeout (the dictionary is kept)

### Frontend tweaks

* Instant Page: preload on mouse hover
* Native lazy loading for images
* YouTube facade: thumbnail first, player only after a click
* Preload of local fonts (one URL per line)
* DNS prefetch for YouTube, i.ytimg.com and Google Fonts
* Remove bloat: emoji scripts, wp-embed, oEmbed discovery links, XML-RPC
* Heartbeat off in the frontend, throttled to 60 seconds in the admin

### Housekeeping

* Database cleanup: revisions, auto-drafts older than 7 days, spam comments (with their meta) and expired transients – in small batches, max. 20 seconds per click
* Dashboard widget with cache size and file count
* Debug log (`kyasshu-debug.log` in the cache folder) with a viewer on the settings page
* Settings in their own table `{prefix}kyasshu_settings`, not in `wp_options`

---

## Installation

1. Download `kyasshu.zip` from the [latest release](https://github.com/Suzu-chan1990/Kyasshu/releases/latest)
2. WordPress → Plugins → Add New → Upload Plugin, then activate
3. Add the nginx rules (next section) and reload nginx
4. Kyasshu → **Cache Leeren** to fill the cache
5. Optional: switch on **Compression Dictionary** in the "🗜️ Kompression" card

Updates are offered in the WordPress plugin list as soon as a new GitHub release exists.

---

## nginx setup

The admin page (card "🗜️ Kompression" → "nginx-Regeln") shows the rules with the cache path of your site filled in. A generic copy is in [`docs/nginx-kyasshu.conf`](docs/nginx-kyasshu.conf).

Put the rules into the `server { }` block before your `location` blocks, for example as an include:

```nginx
server {
    server_name example.com;
    root /var/www/example;

    include /etc/nginx/snippets/nginx-kyasshu.conf;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }
    # ... PHP location etc.
}
```

What the rules do:

1. Skip the cache for POST requests, query strings and logged-in users or commenters
2. Pick the best file that exists: dictionary (DCZ/DCB) → zstd → Brotli → gzip → plain HTML
3. Hand it to a location that sets the right `Content-Encoding`, `Content-Type` and `Vary`
4. Serve the dictionary with `Use-As-Dictionary` and a 30-day cache lifetime

Notes:

* `add_header` inside a location replaces the `add_header` lines of the server block. Repeat your security headers in these locations if you set any.
* Regexes that contain `{43}` must stay in quotes.
* No nginx module is needed: the files are already compressed.
* A CDN in front of the site may not pass DCB/DCZ through. zstd, Brotli and gzip still work.

Test:

```bash
curl -sI -H 'Accept-Encoding: gzip, br, zstd' https://example.com/ | grep -i content-encoding
# content-encoding: zstd
```

For DCB/DCZ open two pages in Chrome; on the second one DevTools → Network shows `Content-Encoding: dcz`.

---

## WP-CLI

Run WP-CLI as the site's PHP-FPM user, not as root.

```bash
wp kyasshu flush     # delete all cache files and start the preloader
wp kyasshu gc        # remove expired cache files
wp kyasshu warmup    # warm the cache and check every file
```

`warmup` reports per URL which files were written, for example
`[HTTP 200, Cache OK + gzip + br + zstd + dcb + dcz]`.

---

## Files and folders

```
kyasshu/
├── kyasshu.php            main file, settings, activation, GitHub updater
├── inc/
│   ├── engine.php         output buffer and cache writer
│   ├── compress.php       gzip / Brotli / zstd / DCB / DCZ, dictionary, nginx rules
│   ├── filesystem.php     purge, smart purge, rewarm queue, stats
│   ├── preload.php        preloader queue
│   ├── cron.php           garbage collection
│   ├── api.php            WP-CLI commands
│   ├── admin.php          settings page
│   ├── dashboard.php      dashboard widget
│   ├── tweaks.php         frontend tweaks
│   ├── heartbeat.php      Heartbeat control
│   └── db-clean.php       database cleanup
├── images/kyasshu-icon.webp    icon on the settings page
├── lib/plugin-update-checker/   updates from GitHub releases
└── docs/nginx-kyasshu.conf      generic nginx rules
```

---

## Privacy

Kyasshu sends no data to third parties. The preloader only requests pages of your own site. The GitHub updater contacts `api.github.com` from the admin area, WP-Cron and WP-CLI to look for new releases.

---

## License

GPL v2 or later – see [LICENSE](LICENSE).

Bundled: [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Yahnis Elsts (MIT).
