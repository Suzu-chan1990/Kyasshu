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
 * How CLI tools can be started: 'proc_open' (preferred: argument list, no shell, timeout),
 * else 'exec' (shell command with escaped arguments), else '' (no CLI tools at all).
 */
function kyasshu_runner(): string {
    static $runner = null;
    if ( $runner === null ) {
        $runner = kyasshu_fn_allowed( 'proc_open' ) && kyasshu_fn_allowed( 'proc_get_status' ) && kyasshu_fn_allowed( 'proc_terminate' )
            ? 'proc_open'
            : ( kyasshu_fn_allowed( 'exec' ) ? 'exec' : '' );
    }
    return $runner;
}

/** True if CLI tools can be started at all. */
function kyasshu_can_exec(): bool {
    return kyasshu_runner() !== '';
}

/** Seconds a CLI tool may run before it is stopped (filter "kyasshu_cli_timeout"). */
function kyasshu_cli_timeout(): int {
    return max( 5, (int) apply_filters( 'kyasshu_cli_timeout', 30 ) );
}

/** Only absolute paths made of plain characters are accepted as program paths. */
function kyasshu_safe_bin( string $bin ): bool {
    return (bool) preg_match( '#^/[A-Za-z0-9._+/-]+$#D', $bin ) && strpos( $bin, '..' ) === false;
}

/**
 * Runs a program with an argument list and returns [exit code, stdout], or null if it could
 * not be started.
 *
 * proc_open: the argument array goes straight to the program (no shell, so no shell
 * injection is possible), stdin and stderr are /dev/null, and the process is killed after
 * the timeout (exit code 124, like coreutils "timeout").
 * exec: every argument is escaped with escapeshellarg(); the coreutils "timeout" command
 * limits the run time when it exists.
 *
 * @param string[] $argv program path first, then the arguments
 * @return array{0:int,1:string}|null
 */
function kyasshu_run( array $argv, ?int $timeout = null ): ?array {
    $argv    = array_values( array_map( 'strval', $argv ) );
    $timeout = $timeout ?? kyasshu_cli_timeout();
    if ( ! $argv || ! kyasshu_safe_bin( $argv[0] ) ) return null;

    if ( kyasshu_runner() === 'proc_open' ) {
        $pipes = [];
        $proc  = @proc_open( $argv, [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes );
        if ( ! is_resource( $proc ) ) return null;
        stream_set_blocking( $pipes[1], false );

        $out      = '';
        $deadline = microtime( true ) + $timeout;
        $rc       = -1;
        while ( true ) {
            $read = [ $pipes[1] ];
            $w = $e = null;
            if ( @stream_select( $read, $w, $e, 0, 200000 ) ) {
                $chunk = (string) fread( $pipes[1], 65536 );
                $out  .= $chunk;
                if ( $chunk === '' && feof( $pipes[1] ) ) usleep( 20000 ); // output closed, process still ending
            }
            $status = proc_get_status( $proc );
            if ( ! $status['running'] ) {
                $out .= (string) stream_get_contents( $pipes[1] );
                $rc   = (int) $status['exitcode'];
                break;
            }
            if ( microtime( true ) > $deadline ) {
                proc_terminate( $proc, 9 );
                $rc = 124;
                kyasshu_log( 'CLI TIMEOUT after ' . $timeout . 's: ' . basename( $argv[0] ) );
                break;
            }
        }
        fclose( $pipes[1] );
        $close = proc_close( $proc );
        // proc_get_status() reports the real exit code only once; proc_close() covers the rest
        if ( $rc === -1 ) $rc = (int) $close;
        return [ $rc, $out ];
    }

    if ( kyasshu_runner() === 'exec' ) {
        static $timeout_bin = null;
        if ( $timeout_bin === null ) {
            $timeout_bin = '';
            foreach ( [ '/usr/bin/timeout', '/bin/timeout' ] as $t ) {
                if ( @is_file( $t ) ) { $timeout_bin = $t; break; }
            }
        }
        $cmd = implode( ' ', array_map( 'escapeshellarg', $argv ) ) . ' </dev/null 2>/dev/null';
        if ( $timeout_bin !== '' ) $cmd = escapeshellarg( $timeout_bin ) . ' -s KILL ' . (int) $timeout . ' ' . $cmd;
        $lines = [];
        $rc    = 1;
        @exec( $cmd, $lines, $rc );
        if ( (int) $rc === 124 || (int) $rc === 137 ) kyasshu_log( 'CLI TIMEOUT after ' . $timeout . 's: ' . basename( $argv[0] ) );
        return [ (int) $rc, implode( "\n", $lines ) ];
    }
    return null;
}

/**
 * Path of a CLI tool, or '' if it is not installed. The usual folders are checked first;
 * if open_basedir hides them, a fixed "command -v <name>" (name is a plain constant) is asked.
 */
function kyasshu_cli( string $name ): string {
    if ( ! preg_match( '/^[a-z0-9_-]+$/D', $name ) || ! kyasshu_can_exec() ) return '';
    foreach ( [ '/usr/bin', '/usr/local/bin', '/bin', '/opt/homebrew/bin' ] as $dir ) {
        if ( @is_file( "$dir/$name" ) ) return "$dir/$name";
    }
    $r    = kyasshu_run( [ '/bin/sh', '-c', 'command -v ' . escapeshellarg( $name ) ], 5 );
    $path = $r && $r[0] === 0 ? trim( (string) strtok( $r[1], "\n" ) ) : '';
    return kyasshu_safe_bin( $path ) ? $path : '';
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
 * $args is an argument list with the placeholders {in} and {out}; every element is passed as
 * one argument (no shell parsing). Returns null on a failed start, a non-zero exit code
 * (including a timeout), a missing or empty output file. Temp files are always removed.
 *
 * @param string[] $args
 */
function kyasshu_cli_compress( string $bin, array $args, string $data, string $dir ): ?string {
    if ( $bin === '' || $bin === 'php' || ! kyasshu_safe_bin( $bin ) || ! kyasshu_can_exec() ) return null;
    $tmp_in  = $dir . 'kyasshu-src.tmp.' . bin2hex( random_bytes( 8 ) );
    $tmp_out = $tmp_in . '.out';
    if ( @file_put_contents( $tmp_in, $data ) === false ) {
        @unlink( $tmp_in );
        return null;
    }

    $argv = [ $bin ];
    foreach ( $args as $a ) $argv[] = strtr( (string) $a, [ '{in}' => $tmp_in, '{out}' => $tmp_out ] );
    $r = kyasshu_run( $argv );

    $result = false;
    if ( $r === null ) {
        kyasshu_log( 'CLI ERROR: could not start ' . basename( $bin ) );
    } elseif ( $r[0] !== 0 ) {
        kyasshu_log( 'CLI ERROR: ' . basename( $bin ) . ' exit code ' . $r[0] );
    } elseif ( ! is_file( $tmp_out ) ) {
        kyasshu_log( 'CLI ERROR: ' . basename( $bin ) . ' wrote no output file' );
    } else {
        $result = file_get_contents( $tmp_out );
    }
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
    return kyasshu_cli_compress( $how, [ '-f', '-q', '11', '-o', '{out}', '{in}' ], $data, $dir );
}

function kyasshu_compress_zstd( string $data, string $dir, ?string $how = null ): ?string {
    $how ??= kyasshu_capabilities()['zstd'];
    if ( $how === 'php' ) {
        $r = zstd_compress( $data, 19 );
        return $r === false ? null : $r;
    }
    return kyasshu_cli_compress( $how, [ '-q', '-f', '-19', '--no-progress', '-o', '{out}', '{in}' ], $data, $dir );
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
        $body = kyasshu_cli_compress( $how, [ '-f', '-q', '11', '-D', $dict_file, '-o', '{out}', '{in}' ], $data, $dir );
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
        $body = kyasshu_cli_compress( $how, [ '-q', '-f', '-19', '--no-progress', '-D', $dict_file, '-o', '{out}', '{in}' ], $data, $dir );
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
 * Paths for the server rules, detected for this site.
 *
 * The URL path of the cache folder is taken from the file system first (cache folder minus
 * document root): that is exactly what nginx ($document_root + path) and Apache look at.
 * content_url() (WP_CONTENT_URL, e.g. a renamed "wp-content") is the fallback and the
 * cross-check. 'status': ok = both agree and the folder lies under the document root,
 * mismatch = they disagree (rules need a manual check), unknown = no document root known.
 *
 * @return array{fs:string,web:string,docroot:string,web_from_url:string,status:string,host:string}
 */
function kyasshu_rule_paths(): array {
    static $p = null;
    if ( $p !== null ) return $p;

    $fs       = rtrim( KYASSHU_CACHE_ROOT, '/' );
    $from_url = '/' . trim( (string) parse_url( content_url( 'cache/wp-rocket' ), PHP_URL_PATH ), '/' );
    $docroot  = rtrim( (string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' ), '/' );

    $web    = $from_url;
    $status = 'unknown';
    if ( $docroot !== '' ) {
        // Compare real paths, so symlinked document roots (common with Froxlor/Plesk) still match
        $real_fs   = rtrim( (string) ( @realpath( $fs ) ?: $fs ), '/' );
        $real_root = rtrim( (string) ( @realpath( $docroot ) ?: $docroot ), '/' );
        if ( str_starts_with( $real_fs . '/', $real_root . '/' ) ) {
            $web    = '/' . ltrim( substr( $real_fs, strlen( $real_root ) ), '/' );
            $status = $web === $from_url ? 'ok' : 'mismatch';
        } else {
            $status = 'mismatch';
        }
    }

    return $p = [
        'fs'           => $fs,
        'web'          => $web,
        'docroot'      => $docroot,
        'web_from_url' => $from_url,
        'status'       => $status,
        'host'         => (string) parse_url( home_url( '/' ), PHP_URL_HOST ),
    ];
}

/** Header lines for the generated rules: for which site and which paths they were made. */
function kyasshu_rules_header( string $comment = '#' ): string {
    $p = kyasshu_rule_paths();
    return "{$comment} Generated by Kyasshu " . ( defined( 'KYASSHU_VERSION' ) ? KYASSHU_VERSION : '' ) . " for {$p['host']}\n"
         . "{$comment} Cache folder: {$p['fs']}\n"
         . "{$comment} URL path:     {$p['web']}\n";
}

/**
 * Example nginx rules for all encodings, with the cache path of this site filled in.
 * Static: nothing changes when the dictionary is rebuilt, no reload needed.
 */
function kyasshu_nginx_rules(): string {
    $web    = kyasshu_rule_paths()['web'];
    $q      = preg_quote( $web, '#' );
    $header = kyasshu_rules_header();

    return <<<NGINX
# ---- Kyasshu: serve cached pages from disk (server block, before the locations) ----
{$header}
# Replaces an older Kyasshu/rocket block that only knew _gzip/_br.
set \$kyasshu_bypass 0;
if (\$request_method !~ ^(GET|HEAD)\$) { set \$kyasshu_bypass 1; }
if (\$query_string != "") { set \$kyasshu_bypass 1; }
if (\$http_cookie ~* "wordpress_logged_in_|wp-postpass_|comment_author_") { set \$kyasshu_bypass 1; }

# Best simple encoding the browser accepts: zstd > br > gzip
set \$kyasshu_enc "";
set \$kyasshu_enc_name "";
if (\$http_accept_encoding ~* "\\bgzip\\b") { set \$kyasshu_enc "_gzip"; set \$kyasshu_enc_name "gzip"; }
if (\$http_accept_encoding ~* "\\bbr\\b")   { set \$kyasshu_enc "_br";   set \$kyasshu_enc_name "br"; }
if (\$http_accept_encoding ~* "\\bzstd\\b") { set \$kyasshu_enc "_zst";  set \$kyasshu_enc_name "zstd"; }

# Dictionary (dcb/dcz): the browser names its dictionary by hash, the hash is the file suffix.
# "_none" keeps the name invalid unless the browser sent both headers.
set \$kyasshu_dcx "_none";
set \$kyasshu_dcx_name "";
if (\$http_accept_encoding ~* "\\bdcb\\b") { set \$kyasshu_dcx "_dcb"; set \$kyasshu_dcx_name "dcb"; }
if (\$http_accept_encoding ~* "\\bdcz\\b") { set \$kyasshu_dcx "_dcz"; set \$kyasshu_dcx_name "dcz"; }
set \$kyasshu_dict "";
if (\$http_available_dictionary ~ "^:([A-Za-z0-9]{43})=:\$") { set \$kyasshu_dict "_\$1"; }

# Pick the best file that exists and remember its encoding; the rewrite hands it to the
# locations below
set \$kyasshu_base "{$web}/\$host\${uri}index.html";
set \$kyasshu_file "";
set \$kyasshu_ce "";
set \$kyasshu_vary "";
if (-f \$document_root\$kyasshu_base)                           { set \$kyasshu_file \$kyasshu_base; set \$kyasshu_vary "Accept-Encoding, Available-Dictionary"; }
if (-f \$document_root\$kyasshu_base\$kyasshu_enc)              { set \$kyasshu_file \$kyasshu_base\$kyasshu_enc; set \$kyasshu_ce \$kyasshu_enc_name; }
if (-f \$document_root\$kyasshu_base\$kyasshu_dcx\$kyasshu_dict) { set \$kyasshu_file \$kyasshu_base\$kyasshu_dcx\$kyasshu_dict; set \$kyasshu_ce \$kyasshu_dcx_name; }
if (\$kyasshu_bypass) { set \$kyasshu_file ""; set \$kyasshu_ce ""; set \$kyasshu_vary ""; }
if (\$kyasshu_file != "") { rewrite ^ \$kyasshu_file last; }

# The dictionary file: the browser keeps it and uses it for all pages of the site
set \$kyasshu_uad "";
set \$kyasshu_dict_cc "";
if (\$uri ~ "^{$q}/dictionary/([A-Za-z0-9]{43})\\.dict\$") {
    set \$kyasshu_uad 'match="/*", match-dest=("document"), id="\$1"';
    set \$kyasshu_dict_cc "public, max-age=2592000";
}

# Headers on server level: empty values are not sent. The locations below have no add_header
# of their own, so they keep every add_header of the server block (HSTS, security headers,
# X-Robots-Tag of Tegatai ...). In nginx a location with its own add_header would drop them.
add_header Content-Encoding \$kyasshu_ce;
add_header Vary \$kyasshu_vary;
add_header Use-As-Dictionary \$kyasshu_uad;
add_header Cache-Control \$kyasshu_dict_cc;

# Compressed copies: only reachable through the rewrite above ("internal")
location ~ ^{$q}/.+_(gzip|br|zst)\$ { internal; gzip off; types {} default_type "text/html; charset=UTF-8"; }
location ~ "^{$q}/.+_dc[bz]_[A-Za-z0-9]{43}\$" { internal; gzip off; types {} default_type "text/html; charset=UTF-8"; }

# The dictionary itself (headers come from the server level above)
location ~ "^{$q}/dictionary/[A-Za-z0-9]{43}\\.dict\$" {
    types {} default_type text/plain;
    gzip on; gzip_types text/plain;
}

# Debug log and lock files of Kyasshu are not for visitors
location ~ ^{$q}/(kyasshu-debug\.log|.*\.kyasshu-lock)\$ { deny all; }
NGINX;
}

/**
 * The same rules for Apache (and LiteSpeed): a block for the .htaccess in the WordPress root,
 * above "# BEGIN WordPress". Needs mod_rewrite and mod_headers.
 * The file checks use the absolute cache path, the rewrite targets the URL path.
 */
function kyasshu_apache_rules(): string {
    $paths = kyasshu_rule_paths();
    $fs    = $paths['fs'];
    $web   = $paths['web'];

    $variant = static function ( string $label, array $conds, string $suffix, string $env ) {
        $out = "# {$label}\n"
             . "RewriteCond %{ENV:KYS_OK} =1\n";
        foreach ( $conds as $c ) $out .= "RewriteCond {$c}\n";
        $out .= "RewriteCond \"%{ENV:KYS_FS}{$suffix}\" -f\n"
              . "RewriteRule ^ \"%{ENV:KYS_WEB}{$suffix}\" [L{$env}]\n";
        return $out;
    };
    $no_comp = ',E=no-gzip:1,E=no-brotli:1';

    $rules  = "# BEGIN Kyasshu\n";
    $rules .= kyasshu_rules_header();
    $rules .= "# Above \"# BEGIN WordPress\" in the .htaccess of the WordPress root.\n";
    $rules .= "<IfModule mod_rewrite.c>\n";
    $rules .= "RewriteEngine On\n\n";
    $rules .= "# Cached pages only for GET/HEAD, no query string, not logged in, URL ending in \"/\"\n";
    $rules .= "RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)\$\n";
    $rules .= "RewriteCond %{QUERY_STRING} ^\$\n";
    $rules .= "RewriteCond %{HTTP_COOKIE} !(wordpress_logged_in_|wp-postpass_|comment_author_) [NC]\n";
    $rules .= "RewriteCond %{HTTP_HOST} ^[A-Za-z0-9.-]+\$\n";
    $rules .= "RewriteCond %{REQUEST_URI} /\$\n";
    $rules .= "RewriteRule ^ - [E=KYS_OK:1,E=KYS_FS:{$fs}/%{HTTP_HOST}%{REQUEST_URI}index.html,E=KYS_WEB:{$web}/%{HTTP_HOST}%{REQUEST_URI}index.html]\n\n";
    $rules .= "# Dictionary the browser has (dcb/dcz): its hash is the file suffix\n";
    $rules .= "RewriteCond %{ENV:KYS_OK} =1\n";
    $rules .= "RewriteCond %{HTTP:Available-Dictionary} ^:([A-Za-z0-9]{43})=:\$\n";
    $rules .= "RewriteRule ^ - [E=KYS_DICT:%1]\n\n";
    $rules .= "# Best file the browser accepts: dcz > dcb > zstd > br > gzip > plain\n";
    $rules .= $variant( 'dcz', [ '%{HTTP:Accept-Encoding} \bdcz\b [NC]', '%{ENV:KYS_DICT} !=""' ], '_dcz_%{ENV:KYS_DICT}', $no_comp );
    $rules .= $variant( 'dcb', [ '%{HTTP:Accept-Encoding} \bdcb\b [NC]', '%{ENV:KYS_DICT} !=""' ], '_dcb_%{ENV:KYS_DICT}', $no_comp );
    $rules .= $variant( 'zstd', [ '%{HTTP:Accept-Encoding} \bzstd\b [NC]' ], '_zst', $no_comp );
    $rules .= $variant( 'br', [ '%{HTTP:Accept-Encoding} \bbr\b [NC]' ], '_br', $no_comp );
    $rules .= $variant( 'gzip', [ '%{HTTP:Accept-Encoding} \bgzip\b [NC]' ], '_gzip', $no_comp );
    $rules .= $variant( 'plain', [], '', '' );
    $rules .= "</IfModule>\n\n";

    $rules .= "# Headers per encoding (the files are already compressed: no second compression)\n";
    $rules .= "<IfModule mod_headers.c>\n";
    foreach ( [ '_gzip' => 'gzip', '_br' => 'br', '_zst' => 'zstd', '_dcb_[A-Za-z0-9]{43}' => 'dcb', '_dcz_[A-Za-z0-9]{43}' => 'dcz' ] as $suffix => $enc ) {
        $rules .= "<FilesMatch \"^index\\.html{$suffix}\$\">\n"
                . "    ForceType \"text/html; charset=UTF-8\"\n"
                . "    SetEnv no-gzip 1\n"
                . "    SetEnv no-brotli 1\n"
                . "    Header set Content-Encoding {$enc}\n"
                . "    Header merge Vary \"Accept-Encoding, Available-Dictionary\"\n"
                . "</FilesMatch>\n";
    }
    $rules .= "# The dictionary itself: the browser keeps it and uses it for all pages of the site\n";
    $rules .= "<FilesMatch \"^[A-Za-z0-9]{43}\\.dict\$\">\n"
            . "    ForceType text/plain\n"
            . "    Header set Use-As-Dictionary \"match=\\\"/*\\\", match-dest=(\\\"document\\\")\"\n"
            . "    Header set Cache-Control \"public, max-age=2592000\"\n"
            . "</FilesMatch>\n";
    $rules .= "</IfModule>\n\n";
    $rules .= "# Debug log and lock files of Kyasshu are not for visitors\n";
    $rules .= "<FilesMatch \"^(kyasshu-debug\\.log|\\.kyasshu-lock)\$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n";
    $rules .= "# END Kyasshu\n";
    return $rules;
}
