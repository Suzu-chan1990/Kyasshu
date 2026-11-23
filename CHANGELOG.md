# Changelog

All notable changes to Kyasshu are listed here.

## [2.8.9] - 2026-11-23

### Fixed
- nginx: cached pages lost every `add_header` of the server block (HSTS, X-Frame-Options, Content-Security-Policy, Tegatai's X-Robots-Tag …), because the Kyasshu locations had their own `add_header` lines – in nginx that drops all inherited ones. `Content-Encoding`, `Vary`, `Use-As-Dictionary` and the dictionary's `Cache-Control` are now set on server level through variables (empty values are not sent); the Kyasshu locations have no `add_header` any more. **Replace the Kyasshu block in the nginx config with the new rules.**

### Changed
- nginx: the compressed copies are `internal` – they can only be reached through the cache rewrite, not opened directly.
- nginx: the plain cached page also gets `Vary: Accept-Encoding, Available-Dictionary`.

## [2.8.8] - 2026-11-23

### Added
- Hook `kyasshu_purge_tree`: removes the cached page of a URL and of every URL below it (used by the theme for its day/hour card packets).
- Hook `kyasshu_warm_later`: queue a URL for the preloader (the theme queues card packets that were served by PHP, so the next visitor gets the file).
- Filter `kyasshu_min_length` for the white-screen protection (default 1000 bytes), so short fragments can be cached.

### Changed
- The rewarm queue holds up to 1000 URLs (was 300).

## [2.8.7] - 2026-11-23

### Changed
- Format badges have three states: green = active (works and is switched on), grey = available but switched off, struck through = missing. Before, DCB/DCZ showed green even with the dictionary switched off.
- The chip in the tab bar counts active formats ("3/5 Formate aktiv").
- Every format is tested with a small sample compression (zstd and DCZ also check the output), so a tool that is installed but does not work for the PHP user no longer counts as available. An old brotli without `-D` drops DCB automatically.

### Added
- Path detection for the server rules: the URL path of the cache folder is taken from the file system (cache folder minus document root, symlinks resolved) and cross-checked with `content_url()`, so renamed content folders (e.g. `kontentsu`) and a CDN content URL give correct rules. The "Server-Regeln" card shows web server, document root, cache folder, URL path and whether everything matches; the rules start with a header naming site and paths.
- Apache / LiteSpeed rules (`.htaccess`, mod_rewrite + mod_headers) for all formats including DCB/DCZ, tested on Apache 2.4. The "Server-Regeln" card switches between nginx and Apache and preselects the server the site runs on. Generic copy in `docs/apache-kyasshu.conf`.
- CLI tools start through `proc_open()` with an argument list (no shell involved at all), stdin and stderr on `/dev/null`, and a timeout of 30 seconds (filter `kyasshu_cli_timeout`); a hanging tool is killed. If `proc_open()` is disabled, `exec()` is used with every argument escaped and the coreutils `timeout` command as limit.
- Program paths must be absolute and only contain plain characters; temp file names use random bytes.
- nginx and Apache rules block `kyasshu-debug.log` and the lock files from the web (the debug log was reachable under the cache URL before).
- Failed starts, non-zero exit codes, timeouts and missing output files are written to the debug log; temp files are always removed.

## [2.8.6] - 2026-11-22

### Added
- zstd: every cached page is also written as `index.html_zst` (level 19), with the PHP zstd extension or the `zstd` command-line tool. Switch "zstd" in the new "🗜️ Kompression" card, on by default.
- Compression dictionaries (RFC 9842): `index.html_dcb_<hash>` (Brotli with dictionary) and `index.html_dcz_<hash>` (zstd with dictionary). Kyasshu builds the dictionary from cached pages of the site and links it with `<link rel="compression-dictionary">`. Switch "Compression Dictionary (DCB / DCZ)", off by default.
- The dictionary's hash contains only letters and digits, so nginx uses the browser's `Available-Dictionary` header directly as file suffix: no generated nginx config, no reload after a rebuild.
- Action "Wörterbuch neu bauen" (rebuilds the dictionary and the cache).
- Status badges per format (gzip, Brotli, zstd, DCB, DCZ) showing whether the PHP extension or the CLI tool is used.
- Complete nginx rules on the admin page, with the cache path of the site filled in; a generic copy in `docs/nginx-kyasshu.conf`.
- `wp kyasshu warmup` also reports `+ zstd`, `+ dcb`, `+ dcz`.
- Switches for "Bloat entfernen", "DNS Prefetch", "Heartbeat im Frontend aus" and "Heartbeat drosseln".
- Updates from GitHub releases (Plugin Update Checker 5.6).

### Changed
- New settings page in the Tegatai look: header, tabs (Übersicht, Cache, Kompression, Preload, Frontend, Log), metrics, format badges, log viewer with "Log leeren". Every tab saves only its own fields. The menu entry stays where it was.
- The compressed copies are written by one function for all formats (`inc/compress.php`). Each format is only written if the server can produce it.
- Copies of an older state (format switched off, old dictionary) are removed when a page is written again.
- Purging a single page removes every compressed copy; a full purge also removes `_zst`, `_dcb_*` and `_dcz_*`.
- Placeholders on the settings page no longer show site-specific paths.

### Fixed
- Saving the settings switched off the bloat, DNS prefetch and both Heartbeat tweaks, because the page had no switches for them.
- The hourly garbage collection would have deleted the dictionary after the cache timeout; it is kept now. A full purge without `exec()` keeps it too.
- The CLI tools are found even with `open_basedir` set.

## [2.8.5] and earlier

First versions before the GitHub release: static cache in the WP Rocket layout served by nginx, gzip and Brotli copies, token-protected preloader with a WP-Cron queue, smart purge with rewarm queue, WP-CLI commands, frontend tweaks, database cleanup and dashboard widget.
