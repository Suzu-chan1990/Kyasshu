<?php
/**
 * Kyasshu – pre-compressed variants of the cached pages.
 *
 * Next to index.html the engine writes one file per encoding, nginx picks the best one:
 *   index.html_gzip          gzip            (PHP zlib)
 *   index.html_br            brotli          (PHP brotli extension or brotli CLI)
 *   index.html_zst           zstd            (PHP zstd extension or zstd CLI)
 *   index.html_dcb_<id>      dcb = brotli with the site's compression dictionary
 *   index.html_dcz_<id>      dcz = zstd with the site's compression dictionary
 *
 * dcb/dcz follow "Compression Dictionary Transport" (RFC 9842): the browser downloads the
 * dictionary once (<link rel="compression-dictionary">), later sends its SHA-256 in
 * "Available-Dictionary", and nginx answers with the matching dcb/dcz file. <id> is that hash in
 * Base64 without the trailing "=": the dictionary is tuned so the hash contains only letters and
 * digits, so nginx can use the header directly as file suffix (no generated nginx config, no
 * reload). Every encoding is only written when the server can produce it; otherwise nginx falls
 * back to br/gzip.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const KYASSHU_DICT_MAX = 262144; // 256 KB: big enough for header, nav, footer and the grid markup

add_action( 'wp_head', 'kyasshu_dictionary_link', 2 );

/* =================================================================
 * Capabilities of this server
 * ================================================================= */

/** True if a PHP function exists and is not listed in disable_functions. */
function kyasshu_fn_allowed( string $fn ): bool {
    static $disabled = null;
    if ( $disabled === null ) {
        $disabled = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
    }
    return function_exists( $fn ) && ! in_array( $fn, $disabled, true );
}

/**
 * How shell commands can be run: 'exec', else 'proc_open' (some hosts only block exec),
 * else '' (no CLI tools at all).
 */
function kyasshu_runner(): string {
    static $runner = null;
    if ( $runner === null ) {
        $runner = kyasshu_fn_allowed( 'exec' ) ? 'exec' : ( kyasshu_fn_allowed( 'proc_open' ) ? 'proc_open' : '' );
    }
    return $runner;
}

/** True if shell commands can be run at all. */
function kyasshu_can_exec(): bool {
    return kyasshu_runner() !== '';
}

/**
 * Runs a shell command. Returns [exit code, output] or null if commands cannot be run.
 *
 * @return array{0:int,1:string}|null
 */
function kyasshu_run( string $cmd ): ?array {
    $runner = kyasshu_runner();
    if ( $runner === 'exec' ) {
        $out = [];
        $rc  = 1;
        @exec( $cmd, $out, $rc );
        return [ (int) $rc, implode( "\n", $out ) ];
    }
    if ( $runner === 'proc_open' ) {
        $pipes = [];
        $proc  = @proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
        if ( ! is_resource( $proc ) ) return null;
        $out = (string) stream_get_contents( $pipes[1] );
        $err = (string) stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        return [ (int) proc_close( $proc ), trim( $out . "\n" . $err ) ];
    }
    return null;
}

/** Path of a CLI tool, or '' if it is not installed. */
function kyasshu_cli( string $name ): string {
    $r    = kyasshu_run( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' );
    $path = trim( strtok( (string) ( $r[1] ?? '' ), "\n" ) ?: '' );
    // No is_executable(): with open_basedir it fails for /usr/bin even though the command works
    return ( $path !== '' && $path[0] === '/' ) ? $path : '';
}

/** A writable folder for test files and temp files of the CLI tools. */
function kyasshu_work_dir(): string {
    if ( is_dir( KYASSHU_CACHE_ROOT ) || @mkdir( KYASSHU_CACHE_ROOT, 0755, true ) ) {
        if ( is_writable( KYASSHU_CACHE_ROOT ) ) return KYASSHU_CACHE_ROOT;
    }
    return trailingslashit( function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir() );
}

/**
 * What this server can really produce. Every candidate (PHP extension or CLI tool) is tested
 * by compressing a small sample, so a format only counts if it actually works for this
 * PHP user. Cached for an hour (and per request).
 *
 * @return array{gzip:string,br:string,zstd:string,dcb:string,dcz:string} '' = not available,
 *         otherwise how: 'php' (extension) or the CLI path
 */
function kyasshu_capabilities( bool $fresh = false ): array {
    static $caps = null;
    if ( $caps !== null && ! $fresh ) return $caps;
    if ( ! $fresh ) {
        $cached = get_transient( 'kyasshu_caps' );
        if ( is_array( $cached ) && isset( $cached['dcz'] ) ) return $caps = $cached;
    }

    $brotli_cli = kyasshu_cli( 'brotli' );
    $zstd_cli   = kyasshu_cli( 'zstd' );

    $candidates = [
        'gzip' => function_exists( 'gzencode' ) ? 'php' : '',
        'br'   => function_exists( 'brotli_compress' ) ? 'php' : $brotli_cli,
        'zstd' => function_exists( 'zstd_compress' ) ? 'php' : $zstd_cli,
        'dcb'  => kyasshu_php_brotli_has_dict() ? 'php' : $brotli_cli,
        'dcz'  => function_exists( 'zstd_compress_dict' ) ? 'php' : $zstd_cli,
    ];

    // Test sample and test dictionary: a format is only "available" if this works
    $dir    = kyasshu_work_dir();
    $sample = str_repeat( '<div class="card"><a href="/kyasshu/">Kyasshu test</a></div>', 40 );
    $dfile  = $dir . 'kyasshu-test.tmp.' . uniqid( '', true ) . '.dict';
    $dict_ok = @file_put_contents( $dfile, str_repeat( '<div class="card"><a href="/">dictionary</a></div>', 20 ) ) !== false;

    $caps = [];
    foreach ( $candidates as $fmt => $how ) {
        $ok = false;
        if ( $how !== '' ) {
            switch ( $fmt ) {
                case 'gzip':
                    $r  = gzencode( $sample, 6 );
                    $ok = is_string( $r ) && strncmp( $r, "\x1f\x8b", 2 ) === 0;
                    break;
                case 'br':
                    $ok = kyasshu_compress_br( $sample, $dir, $how ) !== null;
                    break;
                case 'zstd':
                    $r  = kyasshu_compress_zstd( $sample, $dir, $how );
                    $ok = $r !== null && strncmp( $r, "\x28\xb5\x2f\xfd", 4 ) === 0;
                    break;
                case 'dcb':
                    $ok = $dict_ok && kyasshu_compress_dcb( $sample, $dfile, str_repeat( "\0", 32 ), $dir, $how ) !== null;
                    break;
                case 'dcz':
                    $r  = $dict_ok ? kyasshu_compress_dcz( $sample, $dfile, str_repeat( "\0", 32 ), $dir, $how ) : null;
                    $ok = $r !== null && substr( $r, 40, 4 ) === "\x28\xb5\x2f\xfd";
                    break;
            }
        }
        $caps[ $fmt ] = $ok ? $how : '';
    }
    @unlink( $dfile );

    set_transient( 'kyasshu_caps', $caps, HOUR_IN_SECONDS );
    return $caps;
}

/** The PHP brotli extension takes a dictionary as 4th argument since version 0.15. */
function kyasshu_php_brotli_has_dict(): bool {
    if ( ! function_exists( 'brotli_compress' ) ) return false;
    try {
        $params = ( new ReflectionFunction( 'brotli_compress' ) )->getParameters();
        return count( $params ) >= 4;
    } catch ( Throwable $e ) {
        return false;
    }
}

/**
 * Which formats are really in use right now: available on this server AND switched on
 * (zstd switch, dictionary switch with a built dictionary). gzip and Brotli have no switch.
 *
 * @return array{gzip:bool,br:bool,zstd:bool,dcb:bool,dcz:bool}
 */
function kyasshu_active_formats(): array {
    $caps = kyasshu_capabilities();
    $dict = kyasshu_dictionary() !== null;
    return [
        'gzip' => $caps['gzip'] !== '',
        'br'   => $caps['br'] !== '',
        'zstd' => $caps['zstd'] !== '' && (bool) kyasshu_get_setting( 'kyasshu_zstd', 1 ),
        'dcb'  => $caps['dcb'] !== '' && $dict,
        'dcz'  => $caps['dcz'] !== '' && $dict,
    ];
}

/* =================================================================
 * Compressors (each returns the compressed bytes, or null).
 * $how overrides the detected method (used by the capability test).
 * ================================================================= */

/**
 * Runs a CLI compressor on $data through temp files in $dir.
 * $args gets the placeholders {in} and {out} (already shell-escaped).
 */
function kyasshu_cli_compress( string $bin, string $args, string $data, string $dir ): ?string {
    if ( $bin === '' || $bin === 'php' || ! kyasshu_can_exec() ) return null;
    $tmp_in  = $dir . 'kyasshu-src.tmp.' . uniqid( '', true );
    $tmp_out = $tmp_in . '.out';
    if ( @file_put_contents( $tmp_in, $data ) === false ) return null;

    $cmd = escapeshellarg( $bin ) . ' ' . strtr( $args, [ '{in}' => escapeshellarg( $tmp_in ), '{out}' => escapeshellarg( $tmp_out ) ] ) . ' 2>/dev/null';
    $r   = kyasshu_run( $cmd );

    $result = ( $r !== null && $r[0] === 0 && is_file( $tmp_out ) ) ? file_get_contents( $tmp_out ) : false;
    @unlink( $tmp_in );
    @unlink( $tmp_out );
    return ( $result === false || $result === '' ) ? null : $result;
}

function kyasshu_compress_br( string $data, string $dir, ?string $how = null ): ?string {
    $how ??= kyasshu_capabilities()['br'];
    if ( $how === 'php' ) {
        $r = brotli_compress( $data, 11, 0 );
        return $r === false ? null : $r;
    }
    return kyasshu_cli_compress( $how, '-f -q 11 -o {out} {in}', $data, $dir );
}

function kyasshu_compress_zstd( string $data, string $dir, ?string $how = null ): ?string {
    $how ??= kyasshu_capabilities()['zstd'];
    if ( $how === 'php' ) {
        $r = zstd_compress( $data, 19 );
        return $r === false ? null : $r;
    }
    return kyasshu_cli_compress( $how, '-q -f -19 --no-progress -o {out} {in}', $data, $dir );
}

/**
 * dcb: 4 magic bytes, the dictionary's SHA-256, then a brotli stream that uses the dictionary.
 * The brotli CLI needs version 1.1+ for "-D"; older versions fail and the test drops dcb.
 */
function kyasshu_compress_dcb( string $data, string $dict_file, string $dict_hash_raw, string $dir, ?string $how = null ): ?string {
    $how ??= kyasshu_capabilities()['dcb'];
    if ( $how === '' ) return null;
    if ( $how === 'php' ) {
        $dict = file_get_contents( $dict_file );
        $body = $dict === false ? false : brotli_compress( $data, 11, 0, $dict );
        $body = $body === false ? null : $body;
    } else {
        $body = kyasshu_cli_compress( $how, '-f -q 11 -D ' . escapeshellarg( $dict_file ) . ' -o {out} {in}', $data, $dir );
    }
    return $body === null ? null : "\xff\x44\x43\x42" . $dict_hash_raw . $body;
}

/**
 * dcz: 8 magic bytes (a zstd skippable frame), the dictionary's SHA-256, then a zstd frame
 * that uses the dictionary as raw content (level 19 keeps the window at 8 MB, as browsers require).
 */
function kyasshu_compress_dcz( string $data, string $dict_file, string $dict_hash_raw, string $dir, ?string $how = null ): ?string {
    $how ??= kyasshu_capabilities()['dcz'];
    if ( $how === '' ) return null;
    if ( $how === 'php' ) {
        $dict = file_get_contents( $dict_file );
        $body = $dict === false ? false : zstd_compress_dict( $data, $dict, 19 );
        $body = $body === false ? null : $body;
    } else {
        $body = kyasshu_cli_compress( $how, '-q -f -19 --no-progress -D ' . escapeshellarg( $dict_file ) . ' -o {out} {in}', $data, $dir );
    }
    return $body === null ? null : "\x5e\x2a\x4d\x18\x20\x00\x00\x00" . $dict_hash_raw . $body;
}

/* =================================================================
 * Writing the variants (called by the engine after index.html)
 * ================================================================= */

/**
 * Writes every enabled and available variant of $content into $path.
 * $write is the engine's atomic writer (temp file + rename). Returns the written suffixes.
 */
function kyasshu_write_variants( string $path, string $content, callable $write ): array {
    $done = [];
    $caps = kyasshu_capabilities();

    if ( $caps['gzip'] !== '' ) {
        $gz = gzencode( $content, 6 );
        if ( $gz !== false && $write( $path . 'index.html_gzip', $gz ) ) $done[] = 'gzip';
    }
    if ( $caps['br'] !== '' ) {
        $br = kyasshu_compress_br( $content, $path );
        if ( $br !== null && $write( $path . 'index.html_br', $br ) ) $done[] = 'br';
    }
    if ( kyasshu_get_setting( 'kyasshu_zstd', 1 ) && $caps['zstd'] !== '' ) {
        $zst = kyasshu_compress_zstd( $content, $path );
        if ( $zst !== null && $write( $path . 'index.html_zst', $zst ) ) $done[] = 'zstd';
    }

    $dict = kyasshu_dictionary();
    if ( $dict !== null ) {
        if ( $caps['dcb'] !== '' ) {
            $dcb = kyasshu_compress_dcb( $content, $dict['file'], $dict['hash_raw'], $path );
            if ( $dcb !== null && $write( $path . 'index.html_dcb_' . $dict['id'], $dcb ) ) $done[] = 'dcb';
        }
        if ( $caps['dcz'] !== '' ) {
            $dcz = kyasshu_compress_dcz( $content, $dict['file'], $dict['hash_raw'], $path );
            if ( $dcz !== null && $write( $path . 'index.html_dcz_' . $dict['id'], $dcz ) ) $done[] = 'dcz';
        }
    }

    // Remove variants of an older state (feature switched off, old dictionary): they would
    // otherwise be served with outdated content next to the fresh index.html
    $keep = [];
    foreach ( [ 'gzip' => '_gzip', 'br' => '_br', 'zstd' => '_zst' ] as $enc => $suffix ) {
        if ( in_array( $enc, $done, true ) ) $keep[] = 'index.html' . $suffix;
    }
    if ( $dict !== null ) {
        if ( in_array( 'dcb', $done, true ) ) $keep[] = 'index.html_dcb_' . $dict['id'];
        if ( in_array( 'dcz', $done, true ) ) $keep[] = 'index.html_dcz_' . $dict['id'];
    }
    foreach ( (array) glob( $path . 'index.html_*' ) as $f ) {
        if ( ! is_string( $f ) ) continue;
        $name = basename( $f );
        if ( strpos( $name, '.tmp.' ) !== false || in_array( $name, $keep, true ) ) continue;
        @unlink( $f );
    }
    return $done;
}

/* =================================================================
 * Compression dictionary
 * ================================================================= */

function kyasshu_dict_dir(): string {
    return KYASSHU_CACHE_ROOT . 'dictionary/';
}

/**
 * The active dictionary, or null if the feature is off or no dictionary is built.
 *
 * @return array{id:string,file:string,url:string,hash_raw:string,size:int}|null
 */
function kyasshu_dictionary(): ?array {
    static $dict = false;
    if ( $dict !== false ) return $dict;
    $dict = null;
    if ( ! kyasshu_get_setting( 'kyasshu_dict', 0 ) ) return $dict;

    $id = (string) kyasshu_get_setting( 'kyasshu_dict_id', '' );
    if ( ! preg_match( '/^[A-Za-z0-9]{43}$/D', $id ) ) return $dict;
    $file = kyasshu_dict_dir() . $id . '.dict';
    if ( ! is_readable( $file ) ) return $dict;

    return $dict = [
        'id'       => $id,
        'file'     => $file,
        'url'      => content_url( 'cache/wp-rocket/dictionary/' . $id . '.dict' ),
        'hash_raw' => (string) base64_decode( $id . '=' ),
        'size'     => (int) filesize( $file ),
    ];
}

/** Tells the browser where the dictionary is; it downloads it when idle. */
function kyasshu_dictionary_link(): void {
    if ( is_admin() ) return;
    $dict = kyasshu_dictionary();
    if ( $dict === null ) return;
    $caps = kyasshu_capabilities();
    if ( $caps['dcb'] === '' && $caps['dcz'] === '' ) return;
    echo '<link rel="compression-dictionary" href="' . esc_url( $dict['url'] ) . '">' . "\n";
}

/**
 * Builds a new dictionary from cached pages of the site: the home page first (header, nav,
 * grid and footer are the same on almost every page), then a few other cached pages.
 * A short marker at the end is varied until the SHA-256 in Base64 has no "+" or "/"
 * (about four tries), so the hash can be used as file name.
 * The caller purges the cache afterwards, so every dcb/dcz file uses the new dictionary.
 *
 * @return array{id:string,size:int,pages:int}|WP_Error
 */
function kyasshu_build_dictionary() {
    $host = preg_replace( '/[^a-zA-Z0-9.-]/', '', (string) parse_url( home_url( '/' ), PHP_URL_HOST ) );
    if ( $host === '' ) return new WP_Error( 'kyasshu_dict', 'Host der Seite nicht gefunden.' );
    $base = KYASSHU_CACHE_ROOT . $host . '/';

    // The home page has to be cached: warm it first if needed
    if ( ! is_file( $base . 'index.html' ) ) {
        wp_remote_get( home_url( '/' ), kyasshu_preload_request_args( 'Dictionary', 30 ) );
    }
    if ( ! is_file( $base . 'index.html' ) ) {
        return new WP_Error( 'kyasshu_dict', 'Die Startseite ist nicht im Cache. Bitte Cache aufwärmen und erneut versuchen.' );
    }

    $files = [ $base . 'index.html' ];
    foreach ( array_merge( (array) glob( $base . '*/index.html' ), (array) glob( $base . '*/*/index.html' ) ) as $f ) {
        if ( count( $files ) >= 5 ) break;
        if ( is_string( $f ) && ! in_array( $f, $files, true ) ) $files[] = $f;
    }

    // Home page in full, the others shortened: repeated markup is what makes a good dictionary
    $dict  = '';
    $pages = 0;
    foreach ( $files as $i => $f ) {
        $html = (string) file_get_contents( $f );
        if ( $html === '' ) continue;
        $dict .= $i === 0 ? $html : substr( $html, 0, 65536 );
        $pages++;
        if ( strlen( $dict ) >= KYASSHU_DICT_MAX ) break;
    }
    $dict = substr( $dict, 0, KYASSHU_DICT_MAX - 64 );
    if ( strlen( $dict ) < 1000 ) return new WP_Error( 'kyasshu_dict', 'Zu wenig Inhalt für ein Wörterbuch.' );

    $id = '';
    for ( $n = 0; $n < 1000; $n++ ) {
        $try = $dict . "\n<!-- kyasshu-dict " . $n . " -->\n";
        $b64 = base64_encode( hash( 'sha256', $try, true ) );
        if ( preg_match( '/^[A-Za-z0-9]{43}=$/D', $b64 ) ) {
            $dict = $try;
            $id   = substr( $b64, 0, 43 );
            break;
        }
    }
    if ( $id === '' ) return new WP_Error( 'kyasshu_dict', 'Kein passender Hash gefunden.' );

    $dir = kyasshu_dict_dir();
    if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) {
        return new WP_Error( 'kyasshu_dict', 'Ordner kann nicht angelegt werden: ' . $dir );
    }
    $tmp = $dir . $id . '.dict.tmp.' . uniqid( '', true );
    if ( @file_put_contents( $tmp, $dict ) === false || ! @rename( $tmp, $dir . $id . '.dict' ) ) {
        @unlink( $tmp );
        return new WP_Error( 'kyasshu_dict', 'Wörterbuch konnte nicht geschrieben werden.' );
    }
    @chmod( $dir . $id . '.dict', 0644 );
    foreach ( (array) glob( $dir . '*.dict' ) as $old ) {
        if ( is_string( $old ) && basename( $old ) !== $id . '.dict' ) @unlink( $old );
    }

    kyasshu_update_setting( 'kyasshu_dict_id', $id );
    kyasshu_update_setting( 'kyasshu_dict_built', time() );
    kyasshu_log( "DICTIONARY: built $id (" . strlen( $dict ) . " bytes from $pages pages)" );

    return [ 'id' => $id, 'size' => strlen( $dict ), 'pages' => $pages ];
}

/** Switches the dictionary off: dictionary files removed (dcb/dcz files go with the next purge). */
function kyasshu_remove_dictionary(): void {
    foreach ( (array) glob( kyasshu_dict_dir() . '*.dict' ) as $old ) {
        if ( is_string( $old ) ) @unlink( $old );
    }
    kyasshu_update_setting( 'kyasshu_dict_id', '' );
}

/* =================================================================
 * nginx rules shown in the admin
 * ================================================================= */

/**
 * Example nginx rules for all encodings, with the cache path of this site filled in.
 * Static: nothing changes when the dictionary is rebuilt, no reload needed.
 */
function kyasshu_nginx_rules(): string {
    $web = '/' . trim( (string) parse_url( content_url( 'cache/wp-rocket' ), PHP_URL_PATH ), '/' );
    $q   = preg_quote( $web, '#' );

    return <<<NGINX
# ---- Kyasshu: serve cached pages from disk (server block, before the locations) ----
# Replaces an older Kyasshu/rocket block that only knew _gzip/_br.
set \$kyasshu_bypass 0;
if (\$request_method !~ ^(GET|HEAD)\$) { set \$kyasshu_bypass 1; }
if (\$query_string != "") { set \$kyasshu_bypass 1; }
if (\$http_cookie ~* "wordpress_logged_in_|wp-postpass_|comment_author_") { set \$kyasshu_bypass 1; }

# Best simple encoding the browser accepts: zstd > br > gzip
set \$kyasshu_enc "";
if (\$http_accept_encoding ~* "\\bgzip\\b") { set \$kyasshu_enc "_gzip"; }
if (\$http_accept_encoding ~* "\\bbr\\b")   { set \$kyasshu_enc "_br"; }
if (\$http_accept_encoding ~* "\\bzstd\\b") { set \$kyasshu_enc "_zst"; }

# Dictionary (dcb/dcz): the browser names its dictionary by hash, the hash is the file suffix.
# "_none" keeps the name invalid unless the browser sent both headers.
set \$kyasshu_dcx "_none";
if (\$http_accept_encoding ~* "\\bdcb\\b") { set \$kyasshu_dcx "_dcb"; }
if (\$http_accept_encoding ~* "\\bdcz\\b") { set \$kyasshu_dcx "_dcz"; }
set \$kyasshu_dict "";
if (\$http_available_dictionary ~ "^:([A-Za-z0-9]{43})=:\$") { set \$kyasshu_dict "_\$1"; }

# Pick the best file that exists; the rewrite hands it to the matching location below
set \$kyasshu_base "{$web}/\$host\${uri}index.html";
set \$kyasshu_file "";
if (-f \$document_root\$kyasshu_base)                           { set \$kyasshu_file \$kyasshu_base; }
if (-f \$document_root\$kyasshu_base\$kyasshu_enc)              { set \$kyasshu_file \$kyasshu_base\$kyasshu_enc; }
if (-f \$document_root\$kyasshu_base\$kyasshu_dcx\$kyasshu_dict) { set \$kyasshu_file \$kyasshu_base\$kyasshu_dcx\$kyasshu_dict; }
if (\$kyasshu_bypass) { set \$kyasshu_file ""; }
if (\$kyasshu_file != "") { rewrite ^ \$kyasshu_file last; }

# Headers per encoding (regexes with {43} must stay in quotes). Note: add_header inside a
# location replaces add_header lines of the server block - repeat security headers here if needed.
location ~ ^{$q}/.+_gzip\$ { gzip off; types {} default_type "text/html; charset=UTF-8"; add_header Content-Encoding gzip; add_header Vary "Accept-Encoding, Available-Dictionary"; }
location ~ ^{$q}/.+_br\$   { gzip off; types {} default_type "text/html; charset=UTF-8"; add_header Content-Encoding br;   add_header Vary "Accept-Encoding, Available-Dictionary"; }
location ~ ^{$q}/.+_zst\$  { gzip off; types {} default_type "text/html; charset=UTF-8"; add_header Content-Encoding zstd; add_header Vary "Accept-Encoding, Available-Dictionary"; }
location ~ "^{$q}/.+_dcb_[A-Za-z0-9]{43}\$" { gzip off; types {} default_type "text/html; charset=UTF-8"; add_header Content-Encoding dcb; add_header Vary "Accept-Encoding, Available-Dictionary"; }
location ~ "^{$q}/.+_dcz_[A-Za-z0-9]{43}\$" { gzip off; types {} default_type "text/html; charset=UTF-8"; add_header Content-Encoding dcz; add_header Vary "Accept-Encoding, Available-Dictionary"; }

# The dictionary itself: the browser keeps it and uses it for all pages of the site
location ~ "^{$q}/dictionary/(?<kyasshu_did>[A-Za-z0-9]{43})\\.dict\$" {
    types {} default_type text/plain;
    gzip on; gzip_types text/plain;
    add_header Use-As-Dictionary 'match="/*", match-dest=("document"), id="\$kyasshu_did"';
    add_header Cache-Control "public, max-age=2592000";
}
NGINX;
}
