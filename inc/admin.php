<?php
/**
 * Kyasshu admin page (Tegatai style): header, tab navigation, cards.
 *
 * Tabs: Übersicht, Cache, Kompression, Preload, Frontend, Log.
 * Every tab has its own form and only saves its own fields, so a switch that is not on
 * the current tab is never reset by saving.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'kyasshu_menu' );

function kyasshu_menu() {
    // Same menu entry and position as before
    add_menu_page( 'Kyasshu', 'Kyasshu', 'manage_options', 'kyasshu', 'kyasshu_render_admin', 'dashicons-performance', 99 );
}

/** Tabs and the switches / fields each tab saves. */
function kyasshu_admin_tabs(): array {
    return [
        'overview' => [ 'label' => 'Übersicht',   'toggles' => [],                                                                              'fields' => [] ],
        'cache'    => [ 'label' => 'Cache',       'toggles' => [ 'kyasshu_enabled', 'kyasshu_minify_html', 'kyasshu_smart_purge' ],               'fields' => [ 'kyasshu_lifespan', 'kyasshu_exclusions' ] ],
        'compress' => [ 'label' => 'Kompression', 'toggles' => [ 'kyasshu_zstd', 'kyasshu_dict' ],                                                'fields' => [] ],
        'preload'  => [ 'label' => 'Preload',     'toggles' => [ 'kyasshu_preload' ],                                                             'fields' => [ 'kyasshu_sitemap_url' ] ],
        'frontend' => [ 'label' => 'Frontend',    'toggles' => [ 'kyasshu_instant_page', 'kyasshu_lazy_load', 'kyasshu_tweak_bloat', 'kyasshu_tweak_dns', 'kyasshu_hb_front', 'kyasshu_hb_limit' ], 'fields' => [ 'kyasshu_tweak_fonts' ] ],
        'log'      => [ 'label' => 'Log',         'toggles' => [ 'kyasshu_debug' ],                                                               'fields' => [] ],
    ];
}

/**
 * Handles a submitted form. Returns the toast text ('' = nothing done).
 */
function kyasshu_admin_handle_post(): string {
    if ( ! isset( $_POST['kyasshu_action'] ) || ! check_admin_referer( 'kyasshu_admin_action', 'kyasshu_nonce' ) ) {
        return '';
    }
    $act = sanitize_key( wp_unslash( $_POST['kyasshu_action'] ) );

    if ( $act === 'save' ) {
        $tab  = sanitize_key( wp_unslash( $_POST['kyasshu_tab'] ?? '' ) );
        $tabs = kyasshu_admin_tabs();
        if ( ! isset( $tabs[ $tab ] ) ) return '';

        $old_zstd = (int) kyasshu_get_setting( 'kyasshu_zstd', 1 );
        $old_dict = (int) kyasshu_get_setting( 'kyasshu_dict', 0 );

        foreach ( $tabs[ $tab ]['toggles'] as $key ) {
            kyasshu_update_setting( $key, isset( $_POST[ $key ] ) ? 1 : 0 );
        }
        foreach ( $tabs[ $tab ]['fields'] as $key ) {
            $raw = wp_unslash( $_POST[ $key ] ?? '' );
            switch ( $key ) {
                case 'kyasshu_lifespan':
                    kyasshu_update_setting( $key, max( 0, (int) $raw ) );
                    break;
                case 'kyasshu_sitemap_url':
                    kyasshu_update_setting( $key, esc_url_raw( trim( (string) $raw ) ) );
                    break;
                default:
                    kyasshu_update_setting( $key, sanitize_textarea_field( (string) $raw ) );
            }
        }

        $msg = 'Einstellungen gespeichert.';
        if ( $tab === 'compress' ) {
            // A changed format needs fresh files, so the cache is rebuilt
            $new_zstd = (int) kyasshu_get_setting( 'kyasshu_zstd', 1 );
            $new_dict = (int) kyasshu_get_setting( 'kyasshu_dict', 0 );
            if ( $new_dict && ! $old_dict ) {
                $built = kyasshu_build_dictionary();
                $msg  .= is_wp_error( $built ) ? ' Wörterbuch: ' . $built->get_error_message() : ' Wörterbuch gebaut, Cache wird neu aufgebaut.';
            } elseif ( ! $new_dict && $old_dict ) {
                kyasshu_remove_dictionary();
                $msg .= ' Wörterbuch entfernt, Cache wird neu aufgebaut.';
            }
            if ( $new_zstd !== $old_zstd || $new_dict !== $old_dict ) kyasshu_purge_all();
        }
        return $msg;
    }

    if ( $act === 'flush' ) {
        kyasshu_purge_all();
        return '🔥 Cache geleert, Preloader gestartet.';
    }

    if ( $act === 'dict_build' ) {
        $built = kyasshu_build_dictionary();
        if ( is_wp_error( $built ) ) return '⚠️ ' . $built->get_error_message();
        kyasshu_purge_all();
        return '📖 Wörterbuch neu gebaut (' . size_format( $built['size'] ) . ' aus ' . (int) $built['pages'] . ' Seiten), Cache wird neu aufgebaut.';
    }

    if ( $act === 'db_clean' ) {
        return '🧹 Datenbank bereinigt: ' . (int) kyasshu_db_cleanup() . ' Einträge.';
    }

    if ( $act === 'test_log' ) {
        // Force a log entry (debug is enabled for this one call)
        $old_debug = kyasshu_get_setting( 'kyasshu_debug' );
        kyasshu_update_setting( 'kyasshu_debug', 1 );
        kyasshu_log( 'TEST ENTRY from Admin Panel at ' . wp_date( 'H:i:s' ) );
        if ( ! $old_debug ) kyasshu_update_setting( 'kyasshu_debug', 0 );
        return '📝 Test-Eintrag geschrieben.';
    }

    if ( $act === 'clear_log' ) {
        $file = KYASSHU_CACHE_ROOT . 'kyasshu-debug.log';
        if ( is_file( $file ) ) @file_put_contents( $file, '' );
        return 'Log geleert.';
    }

    return '';
}

/** Last lines of the debug log, newest first. */
function kyasshu_admin_log_tail( int $lines = 200 ): string {
    $file = KYASSHU_CACHE_ROOT . 'kyasshu-debug.log';
    if ( ! is_readable( $file ) ) return '';
    $size = filesize( $file );
    $fp   = @fopen( $file, 'rb' );
    if ( ! $fp ) return '';
    // Only read the end of a big log
    if ( $size > 262144 ) fseek( $fp, -262144, SEEK_END );
    $data = (string) stream_get_contents( $fp );
    fclose( $fp );
    $rows = array_slice( array_filter( explode( "\n", $data ), 'strlen' ), -$lines );
    return implode( "\n", array_reverse( $rows ) );
}

function kyasshu_render_admin() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $toast = kyasshu_admin_handle_post();

    $tabs = kyasshu_admin_tabs();
    $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation only.
    $tab  = isset( $tabs[ $tab ] ) ? $tab : 'overview';
    $base = admin_url( 'admin.php?page=kyasshu' );

    $s = static fn( string $key, $default ) => kyasshu_get_setting( $key, $default );
    $enabled = (int) $s( 'kyasshu_enabled', 1 );
    $caps    = kyasshu_capabilities( $tab === 'compress' || $tab === 'overview' ); // fresh here, so a newly installed tool shows up
    $dict    = kyasshu_dictionary();
    $queue   = get_option( 'kyasshu_preload_queue', [] );
    $queue_n = is_array( $queue ) ? count( $queue ) : 0;
    $formats = count( array_filter( $caps, fn( $v ) => $v !== '' ) );
    $banner  = plugins_url( 'images/kyasshu-banner.webp', KYASSHU_FILE );

    kyasshu_admin_styles();
    ?>
    <div class="kys-wrap">
        <div class="kys-header">
            <div class="kys-title"><img class="kys-banner" src="<?php echo esc_url( $banner ); ?>" width="772" height="250" alt="Kyasshu"></div>
            <div class="kys-badge">v<?php echo esc_html( KYASSHU_VERSION ); ?></div>
        </div>

        <nav class="kys-nav">
            <?php foreach ( $tabs as $key => $t ) : ?>
                <a class="kys-tab <?php echo $tab === $key ? 'active' : ''; ?>" href="<?php echo esc_url( $key === 'overview' ? $base : add_query_arg( 'tab', $key, $base ) ); ?>"><?php echo esc_html( $t['label'] ); ?></a>
            <?php endforeach; ?>
            <div class="kys-nav-info">
                <span class="kys-chip"><span class="kys-dot" style="background:<?php echo $enabled ? '#10b981' : '#ef4444'; ?>"></span>Cache <?php echo $enabled ? 'aktiv' : 'aus'; ?></span>
                <span class="kys-chip"><span class="kys-dot" style="background:#06b6d4"></span><?php echo (int) $formats; ?>/5 Formate</span>
            </div>
        </nav>

        <?php if ( ! $enabled ) : ?>
            <div class="kys-alert">Die Cache Engine ist ausgeschaltet. Einschalten unter „Cache“.</div>
        <?php endif; ?>

        <?php
        switch ( $tab ) {
            case 'cache':    kyasshu_tab_cache();                    break;
            case 'compress': kyasshu_tab_compress( $caps, $dict );   break;
            case 'preload':  kyasshu_tab_preload( $queue_n );        break;
            case 'frontend': kyasshu_tab_frontend();                 break;
            case 'log':      kyasshu_tab_log();                      break;
            default:         kyasshu_tab_overview( $caps, $dict, $queue_n );
        }
        ?>
    </div>
    <?php if ( $toast !== '' ) : ?>
        <div class="kys-toast" id="kys-toast"><?php echo esc_html( $toast ); ?></div>
        <script>setTimeout(function(){var t=document.getElementById('kys-toast');if(t){t.style.opacity='0';}},4000);</script>
    <?php endif;
}

/* =================================================================
 * Building blocks
 * ================================================================= */

/** Opens a form for one tab (nonce + action + tab). */
function kyasshu_form_open( string $tab ): void {
    echo '<form method="post">';
    wp_nonce_field( 'kyasshu_admin_action', 'kyasshu_nonce' );
    echo '<input type="hidden" name="kyasshu_tab" value="' . esc_attr( $tab ) . '">';
}

/** Save button row, closes the form. */
function kyasshu_form_save(): void {
    echo '<div class="kys-actions"><button type="submit" name="kyasshu_action" value="save" class="kys-btn">Speichern</button></div></form>';
}

/** One action button in its own small form (no settings are sent with it). */
function kyasshu_action_button( string $action, string $label, string $class = 'kys-btn', string $confirm = '' ): void {
    echo '<form method="post" class="kys-inline">';
    wp_nonce_field( 'kyasshu_admin_action', 'kyasshu_nonce' );
    $onclick = $confirm !== '' ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '';
    echo '<button type="submit" name="kyasshu_action" value="' . esc_attr( $action ) . '" class="' . esc_attr( $class ) . '"' . $onclick . '>' . esc_html( $label ) . '</button>';
    echo '</form>';
}

/** Switch row. $disabled greys it out (format not available on this server). */
function kyasshu_switch( string $name, string $label, string $desc, $val, bool $disabled = false ): void {
    ?>
    <label class="kys-switch-row<?php echo $disabled ? ' is-disabled' : ''; ?>">
        <span class="kys-switch-text">
            <span class="kys-switch-label"><?php echo esc_html( $label ); ?></span>
            <span class="kys-desc"><?php echo esc_html( $desc ); ?></span>
        </span>
        <span class="kys-switch">
            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $val ); ?> <?php disabled( $disabled && ! $val ); ?>>
            <span class="kys-slider"></span>
        </span>
    </label>
    <?php
}

/** Kept for compatibility with older code that may still call it. */
function k_toggle( $name, $label, $desc, $val ) {
    kyasshu_switch( (string) $name, (string) $label, (string) $desc, $val );
}

/* =================================================================
 * Tabs
 * ================================================================= */

function kyasshu_tab_overview( array $caps, ?array $dict, int $queue_n ): void {
    $stats = kyasshu_get_stats();
    ?>
    <div class="kys-metrics">
        <div class="kys-metric">
            <div class="kys-metric-label">Cache-Dateien</div>
            <div class="kys-metric-value"><?php echo esc_html( (string) $stats['count'] ); ?></div>
            <div class="kys-metric-note">HTML und alle komprimierten Kopien</div>
        </div>
        <div class="kys-metric">
            <div class="kys-metric-label">Speicher</div>
            <div class="kys-metric-value info"><?php echo esc_html( (string) $stats['size'] ); ?></div>
        </div>
        <div class="kys-metric">
            <div class="kys-metric-label">Preload-Warteschlange</div>
            <div class="kys-metric-value <?php echo $queue_n ? 'warn' : 'ok'; ?>"><?php echo (int) $queue_n; ?></div>
            <div class="kys-metric-note"><?php echo $queue_n ? 'wird in Paketen per WP-Cron abgearbeitet' : 'nichts offen'; ?></div>
        </div>
        <div class="kys-metric">
            <div class="kys-metric-label">Wörterbuch</div>
            <div class="kys-metric-value kys-metric-small <?php echo $dict ? 'ok' : ''; ?>"><?php echo $dict ? esc_html( size_format( $dict['size'] ) ) : 'aus'; ?></div>
            <div class="kys-metric-note"><?php echo $dict ? 'DCB / DCZ aktiv' : 'unter „Kompression“ einschalten'; ?></div>
        </div>
    </div>

    <div class="kys-grid">
        <div class="kys-card">
            <h3>Aktionen</h3>
            <div class="kys-btn-row">
                <?php kyasshu_action_button( 'flush', '🔥 Cache leeren', 'kys-btn', 'Den ganzen Cache löschen und neu aufbauen?' ); ?>
                <?php kyasshu_action_button( 'db_clean', '🧹 Datenbank bereinigen', 'kys-btn kys-btn-secondary', 'Revisionen, Auto-Drafts und Spam-Kommentare endgültig löschen?' ); ?>
            </div>
            <span class="kys-desc">„Cache leeren“ löscht alle Seiten und startet den Preloader. Die Datenbank-Bereinigung löscht Revisionen, Auto-Drafts älter als 7 Tage, Spam-Kommentare und abgelaufene Transients – in Paketen, höchstens 20 Sekunden pro Klick.</span>
        </div>

        <div class="kys-card">
            <h3>Formate auf diesem Server</h3>
            <?php kyasshu_format_badges( $caps ); ?>
            <span class="kys-desc">Grün = wird geschrieben, sobald der Schalter an ist. Mit der Maus über ein Format zeigt, ob die PHP-Erweiterung oder das Kommandozeilen-Tool benutzt wird.</span>
        </div>
    </div>

    <div class="kys-card">
        <h3>System</h3>
        <table class="kys-table kys-table-boxed">
            <tr><td>Cache-Ordner</td><td><code><?php echo esc_html( KYASSHU_CACHE_ROOT ); ?></code></td></tr>
            <tr><td>Log-Datei</td><td><code><?php echo esc_html( KYASSHU_CACHE_ROOT . 'kyasshu-debug.log' ); ?></code></td></tr>
            <tr><td>PHP</td><td><?php echo esc_html( PHP_VERSION ); ?> · exec() <?php echo kyasshu_can_exec() ? '<span class="kys-pill kys-pill-ok">erlaubt</span>' : '<span class="kys-pill kys-pill-bad">gesperrt</span>'; ?></td></tr>
            <tr><td>WP-CLI</td><td><code>wp kyasshu flush</code> · <code>wp kyasshu gc</code> · <code>wp kyasshu warmup</code></td></tr>
        </table>
    </div>
    <?php
}

function kyasshu_tab_cache(): void {
    kyasshu_form_open( 'cache' );
    ?>
    <div class="kys-grid">
        <div class="kys-card">
            <h3>Core & Speed</h3>
            <?php
            kyasshu_switch( 'kyasshu_enabled', 'Cache Engine', 'Schaltet das ganze Caching ein oder aus', kyasshu_get_setting( 'kyasshu_enabled', 1 ) );
            kyasshu_switch( 'kyasshu_smart_purge', 'Smart Purge', 'Beim Speichern nur die betroffenen Seiten löschen und neu aufwärmen', kyasshu_get_setting( 'kyasshu_smart_purge', 1 ) );
            kyasshu_switch( 'kyasshu_minify_html', 'HTML Minifier', 'Entfernt überflüssige Leerzeichen (experimentell)', kyasshu_get_setting( 'kyasshu_minify_html', 0 ) );
            ?>
        </div>
        <div class="kys-card">
            <h3>Lebensdauer & Ausnahmen</h3>
            <label class="kys-label" for="kys-lifespan">Cache-Timeout (Stunden)</label>
            <input id="kys-lifespan" type="number" min="0" name="kyasshu_lifespan" value="<?php echo esc_attr( (string) kyasshu_get_setting( 'kyasshu_lifespan', 10 ) ); ?>" class="kys-input kys-input-short">
            <span class="kys-desc">Ältere Dateien löscht die stündliche Bereinigung. 0 = nie.</span>

            <label class="kys-label" for="kys-excl">Ausschließen (URLs, eine pro Zeile)</label>
            <textarea id="kys-excl" name="kyasshu_exclusions" class="kys-input" rows="5" placeholder="/shop/checkout"><?php echo esc_textarea( (string) kyasshu_get_setting( 'kyasshu_exclusions', '' ) ); ?></textarea>
        </div>
    </div>
    <?php
    kyasshu_form_save();
}

function kyasshu_tab_compress( array $caps, ?array $dict ): void {
    $has_dict_tool = $caps['dcb'] !== '' || $caps['dcz'] !== '';
    $dict_on       = (int) kyasshu_get_setting( 'kyasshu_dict', 0 );
    ?>
    <div class="kys-card">
        <h3>Formate auf diesem Server</h3>
        <?php kyasshu_format_badges( $caps ); ?>
        <span class="kys-desc">gzip und Brotli werden immer geschrieben, wenn verfügbar. Jede Seite liegt danach als <code>index.html</code> plus <code>_gzip</code>, <code>_br</code>, <code>_zst</code>, <code>_dcb_…</code>, <code>_dcz_…</code> im Cache; nginx liefert die beste Datei, die der Browser versteht.</span>
    </div>

    <div class="kys-grid">
        <div class="kys-card">
            <h3>Einstellungen</h3>
            <?php kyasshu_form_open( 'compress' ); ?>
            <?php
            kyasshu_switch( 'kyasshu_zstd', 'zstd', $caps['zstd'] !== '' ? 'Schreibt index.html_zst (Level 19)' : 'Nicht verfügbar – weder PHP-zstd noch zstd-CLI', kyasshu_get_setting( 'kyasshu_zstd', 1 ), $caps['zstd'] === '' );
            kyasshu_switch( 'kyasshu_dict', 'Compression Dictionary (DCB / DCZ)', $has_dict_tool ? 'Eigenes Wörterbuch aus den Seiten der Website. Braucht die nginx-Regeln.' : 'Nicht verfügbar – braucht brotli-CLI ab 1.1 oder zstd', $dict_on, ! $has_dict_tool );
            ?>
            <span class="kys-desc">Eine Änderung leert den Cache, damit alle Seiten mit den neuen Formaten geschrieben werden.</span>
            <?php kyasshu_form_save(); ?>
        </div>

        <div class="kys-card">
            <h3>Wörterbuch</h3>
            <?php if ( $dict ) : ?>
                <table class="kys-table kys-table-boxed">
                    <tr><td>Hash</td><td><code><?php echo esc_html( substr( $dict['id'], 0, 16 ) ); ?>…</code></td></tr>
                    <tr><td>Größe</td><td><?php echo esc_html( size_format( $dict['size'] ) ); ?></td></tr>
                    <tr><td>Gebaut</td><td>vor <?php echo esc_html( human_time_diff( (int) kyasshu_get_setting( 'kyasshu_dict_built', time() ) ) ); ?></td></tr>
                    <tr><td>Datei</td><td><a class="kys-link" href="<?php echo esc_url( $dict['url'] ); ?>" target="_blank" rel="noopener">dictionary/….dict ↗</a></td></tr>
                </table>
                <div class="kys-btn-row" style="margin-top:12px;">
                    <?php kyasshu_action_button( 'dict_build', '📖 Wörterbuch neu bauen', 'kys-btn kys-btn-secondary', 'Wörterbuch neu bauen? Danach wird der ganze Cache neu aufgebaut.' ); ?>
                </div>
                <span class="kys-desc">Neu bauen lohnt sich, wenn sich Theme oder Layout stark geändert haben. nginx braucht dafür kein Reload.</span>
            <?php elseif ( $dict_on ) : ?>
                <p class="kys-empty kys-empty-warn">Noch kein Wörterbuch vorhanden.</p>
                <?php kyasshu_action_button( 'dict_build', '📖 Wörterbuch bauen', 'kys-btn' ); ?>
            <?php else : ?>
                <p class="kys-empty kys-empty-muted">Ausgeschaltet.</p>
                <span class="kys-desc">Mit Wörterbuch kennt der Browser Header, Navigation und Footer schon – jede weitere Seite ist dann nur noch ein Bruchteil so groß. Funktioniert in Chromium-Browsern über HTTPS; alle anderen bekommen zstd, Brotli oder gzip.</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="kys-card">
        <h3>nginx-Regeln</h3>
        <span class="kys-desc" style="margin-bottom:10px;">In den <code>server { }</code>-Block vor die <code>location</code>-Blöcke, danach <code>nginx -t</code> und Reload. Ersetzt einen älteren Kyasshu-Block, der nur <code>_gzip</code>/<code>_br</code> kannte. Ohne diese Regeln liegen die neuen Dateien nur ungenutzt im Cache.</span>
        <textarea readonly class="kys-input kys-code" rows="18" onclick="this.select();"><?php echo esc_textarea( kyasshu_nginx_rules() ); ?></textarea>
    </div>
    <?php
}

function kyasshu_tab_preload( int $queue_n ): void {
    kyasshu_form_open( 'preload' );
    ?>
    <div class="kys-grid">
        <div class="kys-card">
            <h3>Preloader</h3>
            <?php kyasshu_switch( 'kyasshu_preload', 'Auto Preloader', 'Wärmt den Cache nach jedem „Cache leeren“ wieder auf', kyasshu_get_setting( 'kyasshu_preload', 1 ) ); ?>
            <span class="kys-desc">Nur der Preloader schreibt Cache-Dateien – normale Besucher nie. Er arbeitet eine Warteschlange per WP-Cron in Paketen zu 20 Seiten ab. Offen: <strong><?php echo (int) $queue_n; ?></strong></span>
        </div>
        <div class="kys-card">
            <h3>Quellen</h3>
            <label class="kys-label" for="kys-sitemap">Sitemap-URL (optional)</label>
            <input id="kys-sitemap" type="url" name="kyasshu_sitemap_url" value="<?php echo esc_attr( (string) kyasshu_get_setting( 'kyasshu_sitemap_url', '' ) ); ?>" class="kys-input" placeholder="https://example.com/sitemap.xml">
            <span class="kys-desc">Ohne Sitemap wird nur die Startseite vorgeladen; geänderte Beiträge, ihre Archive und die Startseite wärmt Smart Purge selbst auf. Für alles auf einmal: <code>wp kyasshu warmup</code>.</span>
        </div>
    </div>
    <?php
    kyasshu_form_save();
}

function kyasshu_tab_frontend(): void {
    kyasshu_form_open( 'frontend' );
    ?>
    <div class="kys-grid">
        <div class="kys-card">
            <h3>Laden & Darstellung</h3>
            <?php
            kyasshu_switch( 'kyasshu_instant_page', 'Instant Page', 'Lädt Links vor, sobald die Maus darüber ist', kyasshu_get_setting( 'kyasshu_instant_page', 1 ) );
            kyasshu_switch( 'kyasshu_lazy_load', 'Native Lazy Load', 'Bilder im Inhalt erst laden, wenn sie sichtbar werden', kyasshu_get_setting( 'kyasshu_lazy_load', 0 ) );
            kyasshu_switch( 'kyasshu_tweak_dns', 'DNS Prefetch', 'YouTube, i.ytimg.com und Google Fonts vorab auflösen', kyasshu_get_setting( 'kyasshu_tweak_dns', 1 ) );
            ?>
            <label class="kys-label" for="kys-fonts">Lokale Fonts vorladen (eine URL pro Zeile)</label>
            <textarea id="kys-fonts" name="kyasshu_tweak_fonts" class="kys-input" rows="4" placeholder="/wp-content/themes/mytheme/fonts/inter.woff2"><?php echo esc_textarea( (string) kyasshu_get_setting( 'kyasshu_tweak_fonts', '' ) ); ?></textarea>
        </div>
        <div class="kys-card">
            <h3>Ballast & Heartbeat</h3>
            <?php
            kyasshu_switch( 'kyasshu_tweak_bloat', 'Bloat entfernen', 'Emoji-Skripte, wp-embed, oEmbed-Links und XML-RPC aus', kyasshu_get_setting( 'kyasshu_tweak_bloat', 1 ) );
            kyasshu_switch( 'kyasshu_hb_front', 'Heartbeat im Frontend aus', 'Kein Heartbeat-Skript für Besucher', kyasshu_get_setting( 'kyasshu_hb_front', 1 ) );
            kyasshu_switch( 'kyasshu_hb_limit', 'Heartbeat drosseln', 'Im Admin nur alle 60 Sekunden', kyasshu_get_setting( 'kyasshu_hb_limit', 1 ) );
            ?>
        </div>
    </div>
    <?php
    kyasshu_form_save();
}

function kyasshu_tab_log(): void {
    $tail = kyasshu_admin_log_tail();
    ?>
    <div class="kys-card">
        <h3>Debug</h3>
        <?php kyasshu_form_open( 'log' ); ?>
        <?php kyasshu_switch( 'kyasshu_debug', 'Debug-Modus', 'Schreibt jede Cache-Aktion in kyasshu-debug.log im Cache-Ordner', kyasshu_get_setting( 'kyasshu_debug', 0 ) ); ?>
        <?php kyasshu_form_save(); ?>
    </div>

    <div class="kys-card">
        <h3>Log <span class="kys-h3-note">neueste Einträge oben</span></h3>
        <div class="kys-btn-row" style="margin-bottom:12px;">
            <?php kyasshu_action_button( 'test_log', '📝 Test-Eintrag', 'kys-btn kys-btn-secondary' ); ?>
            <?php kyasshu_action_button( 'clear_log', 'Log leeren', 'kys-btn kys-btn-secondary kys-btn-danger', 'Log wirklich leeren?' ); ?>
        </div>
        <div class="kys-terminal"><?php echo $tail !== '' ? esc_html( $tail ) : 'Noch keine Einträge.'; ?></div>
    </div>
    <?php
}

/** Format badges: green = available, struck through = missing. */
function kyasshu_format_badges( array $caps ): void {
    echo '<div class="kys-formats">';
    foreach ( [ 'gzip' => 'gzip', 'br' => 'Brotli', 'zstd' => 'zstd', 'dcb' => 'DCB', 'dcz' => 'DCZ' ] as $k => $label ) {
        $how = $caps[ $k ] ?? '';
        $tip = $how === '' ? 'auf diesem Server nicht verfügbar' : ( $how === 'php' ? 'PHP-Erweiterung' : 'CLI: ' . $how );
        echo '<span class="kys-format ' . ( $how === '' ? 'is-off' : 'is-on' ) . '" title="' . esc_attr( $tip ) . '">' . esc_html( $label )
           . '<small>' . esc_html( $how === '' ? '–' : ( $how === 'php' ? 'PHP' : 'CLI' ) ) . '</small></span>';
    }
    echo '</div>';
}

/* =================================================================
 * Styles (same look as Tegatai Secure)
 * ================================================================= */

function kyasshu_admin_styles(): void {
    ?>
    <style>
        .kys-wrap { --kys-primary:#06b6d4; --kys-accent:#7c3aed; --kys-bg:#f8fafc; --kys-surface:#ffffff; --kys-text:#0f1720; --kys-muted:#6b7280; --kys-border:#e2e8f0; --kys-success:#10b981; --kys-danger:#ef4444;
            max-width:1400px; margin:20px 20px 30px 0; font-family:'Inter',system-ui,sans-serif; color:var(--kys-text); }
        /* Light cyan-to-lavender header with the banner, same as Tegatai Secure */
        .kys-header { background:linear-gradient(120deg,#ecfeff 0%,#eef2ff 55%,#f5f3ff 100%); color:var(--kys-text); border:1px solid #cffafe; padding:18px 22px; border-radius:12px; margin-bottom:18px; min-height:56px; box-sizing:border-box; display:flex; justify-content:space-between; align-items:center; box-shadow:0 1px 3px rgba(0,0,0,.04); }
        .kys-title { display:flex; align-items:center; min-width:0; }
        .kys-banner { display:block; height:110px; width:auto; max-width:100%; margin:-12px 0; filter:drop-shadow(0 2px 4px rgba(15,23,32,.12)); }
        .kys-badge { background:linear-gradient(90deg,var(--kys-primary),var(--kys-accent)); padding:5px 10px; border-radius:20px; font-size:10px; font-weight:700; color:#fff; border:1px solid rgba(255,255,255,.2); }

        .kys-nav { display:flex; align-items:center; gap:10px; background:var(--kys-surface); padding:12px 20px; border-radius:10px; border:1px solid var(--kys-border); margin-bottom:18px; overflow-x:auto; box-shadow:0 1px 3px rgba(0,0,0,.02); }
        .kys-tab { padding:8px 16px; border-radius:6px; text-decoration:none; color:var(--kys-muted); font-size:13px; font-weight:600; white-space:nowrap; transition:.2s; }
        .kys-tab:hover { background:var(--kys-bg); color:var(--kys-text); }
        .kys-tab.active, .kys-tab.active:hover { background:linear-gradient(90deg,var(--kys-primary),var(--kys-accent)); color:#fff; }
        .kys-tab:focus { box-shadow:none; outline:2px solid rgba(6,182,212,.4); }
        .kys-nav-info { margin-left:auto; display:flex; gap:8px; }
        .kys-chip { display:inline-flex; align-items:center; gap:7px; background:var(--kys-bg); border:1px solid var(--kys-border); border-radius:999px; padding:6px 10px; color:#475569; font-size:11px; font-weight:750; white-space:nowrap; }
        .kys-dot { width:6px; height:6px; border-radius:999px; display:inline-block; }

        .kys-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(380px,1fr)); gap:18px; }
        .kys-grid > .kys-card { margin-bottom:0; }
        .kys-grid + .kys-card, .kys-grid + form, .kys-grid + .kys-actions { margin-top:18px; }
        .kys-card { background:var(--kys-surface); border-radius:10px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,.05); border:1px solid var(--kys-border); margin-bottom:18px; min-width:0; }
        .kys-card h3 { margin:0 0 12px; font-size:12px; font-weight:700; border-bottom:1px solid var(--kys-border); padding-bottom:10px; text-transform:uppercase; color:var(--kys-muted); letter-spacing:.5px; }
        .kys-h3-note { text-transform:none; letter-spacing:0; font-weight:600; color:#94a3b8; margin-left:6px; }
        .kys-desc { font-size:11px; color:var(--kys-muted); line-height:1.5; display:block; margin-top:6px; }
        .kys-desc code, .kys-table code { font-size:11px; background:var(--kys-bg); border:1px solid var(--kys-border); border-radius:5px; padding:1px 6px; }
        .kys-empty { margin:0 0 10px; font-size:13px; font-weight:600; }
        .kys-empty-warn { color:#d97706; }
        .kys-empty-muted { color:var(--kys-muted); }

        .kys-switch-row { display:flex; justify-content:space-between; align-items:center; gap:16px; background:var(--kys-bg); border:1px solid var(--kys-border); border-radius:8px; padding:12px 14px; margin-bottom:8px; cursor:pointer; }
        .kys-switch-row.is-disabled { opacity:.55; cursor:not-allowed; }
        .kys-switch-text { min-width:0; }
        .kys-switch-label { display:block; font-size:13px; font-weight:700; }
        .kys-switch-row .kys-desc { margin-top:2px; }
        .kys-switch { position:relative; flex:0 0 44px; width:44px; height:24px; }
        .kys-switch input { opacity:0; width:0; height:0; position:absolute; }
        .kys-slider { position:absolute; inset:0; background:#cbd5e1; border-radius:34px; transition:.25s; }
        .kys-slider:before { content:""; position:absolute; height:18px; width:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.25s; box-shadow:0 2px 4px rgba(0,0,0,.2); }
        .kys-switch input:checked + .kys-slider { background:linear-gradient(90deg,var(--kys-primary),var(--kys-accent)); }
        .kys-switch input:checked + .kys-slider:before { transform:translateX(20px); }
        .kys-switch input:focus-visible + .kys-slider { box-shadow:0 0 0 2px rgba(6,182,212,.4); }

        .kys-label { display:block; font-size:13px; font-weight:600; margin:14px 0 6px; }
        .kys-wrap .kys-input { width:100%; min-height:36px; padding:8px 10px; border:1px solid var(--kys-border); border-radius:6px; font-size:13px; font-family:monospace; background:var(--kys-bg); color:var(--kys-text); transition:all .2s; box-sizing:border-box; margin:0; }
        .kys-wrap .kys-input:focus { border-color:var(--kys-primary); outline:none; background:var(--kys-surface); box-shadow:0 0 0 2px rgba(6,182,212,.15); }
        .kys-wrap .kys-input-short { max-width:140px; }
        .kys-wrap .kys-code { font-size:11px; white-space:pre; line-height:1.5; }

        .kys-actions { margin-top:14px; }
        .kys-btn-row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .kys-inline { display:inline; margin:0; }
        .kys-btn { background:linear-gradient(90deg,var(--kys-primary),var(--kys-accent)); border:0; padding:9px 18px; border-radius:6px; font-weight:600; font-size:13px; color:#fff; cursor:pointer; }
        .kys-btn:hover { filter:brightness(1.07); }
        .kys-btn-secondary { background:var(--kys-surface); border:1px solid var(--kys-border); padding:8px 18px; color:var(--kys-text); }
        .kys-btn-secondary:hover { filter:none; border-color:var(--kys-primary); }
        .kys-btn-danger { color:#b91c1c; }
        .kys-btn-danger:hover { border-color:var(--kys-danger); }

        .kys-metrics { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:18px; }
        .kys-metric { background:var(--kys-surface); border:1px solid var(--kys-border); border-radius:12px; padding:16px 17px; box-shadow:0 1px 5px rgba(15,23,42,.035); min-width:0; }
        .kys-metric-label { color:#64748b; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; }
        .kys-metric-value { margin-top:7px; color:#0f172a; font-size:25px; line-height:1; font-weight:850; letter-spacing:-.035em; font-variant-numeric:tabular-nums; }
        .kys-metric-value.ok { color:#059669; }
        .kys-metric-value.warn { color:#d97706; }
        .kys-metric-value.info { color:#7c3aed; }
        .kys-metric-small { font-size:20px; }
        .kys-metric-note { margin-top:7px; color:#94a3b8; font-size:10px; }

        .kys-formats { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:6px; }
        .kys-format { display:inline-flex; align-items:center; gap:6px; padding:6px 11px; border-radius:999px; font-size:12px; font-weight:800; border:1px solid; }
        .kys-format small { font-size:9px; font-weight:800; opacity:.75; }
        .kys-format.is-on { background:#ecfeff; border-color:#a5f3fc; color:#0e7490; }
        .kys-format.is-off { background:#f8fafc; border-color:var(--kys-border); color:#94a3b8; text-decoration:line-through; }

        .kys-table { width:100%; border-collapse:collapse; font-size:12px; }
        .kys-table td { padding:9px 12px; border-bottom:1px solid var(--kys-border); vertical-align:middle; word-break:break-all; }
        .kys-table td:first-child { width:130px; color:var(--kys-muted); font-weight:700; word-break:normal; }
        .kys-table tr:last-child td { border-bottom:0; }
        .kys-table-boxed { border:1px solid var(--kys-border); border-radius:6px; overflow:hidden; border-collapse:separate; border-spacing:0; }
        .kys-link { text-decoration:none; font-weight:800; color:#0891b2; }
        .kys-link:hover { color:var(--kys-accent); }
        .kys-pill { display:inline-block; padding:2px 8px; border-radius:999px; font-weight:800; font-size:10px; }
        .kys-pill-ok { background:#ecfdf5; color:#047857; }
        .kys-pill-bad { background:#fef2f2; color:#b91c1c; }

        .kys-alert { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; border-radius:10px; padding:12px 16px; margin-bottom:18px; font-size:13px; font-weight:600; }
        .kys-terminal { background:#0f172a; color:#10b981; font-family:monospace; font-size:12px; padding:15px; border-radius:8px; height:420px; overflow-y:auto; white-space:pre-wrap; border:1px solid #1e293b; }
        .kys-toast { min-width:200px; background:#0f1720; color:#fff; text-align:center; border-radius:6px; padding:10px 16px; position:fixed; z-index:99999; left:50%; transform:translateX(-50%); bottom:40px; font-size:13px; font-weight:600; box-shadow:0 4px 12px rgba(0,0,0,.15); transition:opacity .5s; }

        @media (max-width: 782px) { .kys-wrap { margin:12px 10px 24px 0; } .kys-header { padding:15px 16px; } .kys-banner { height:auto; margin:0; } .kys-nav-info { display:none; } .kys-grid { grid-template-columns:1fr; } }
    </style>
    <?php
}
