<?php
if ( ! defined( 'ABSPATH' ) ) exit;
add_action('wp_dashboard_setup', 'kyasshu_add_dashboard_widget');
function kyasshu_add_dashboard_widget() {
    if ( ! current_user_can('manage_options') ) return;
    wp_add_dashboard_widget('kyasshu_dashboard_widget', '⚡ Kyasshu Status', 'kyasshu_render_dashboard_widget');
}
function kyasshu_render_dashboard_widget() {
    if ( isset($_POST['kyasshu_dash_flush']) && check_admin_referer('kyasshu_dash_action', 'kyasshu_dash_nonce') ) {
        kyasshu_purge_all();
        echo '<div style="background:#d1fae5; color:#065f46; padding:10px; border-radius:6px; margin-bottom:15px; border:1px solid #a7f3d0;">Cache geleert & Preload gestartet!</div>';
    }
    $stats = kyasshu_get_stats();
    $enabled = kyasshu_get_setting('kyasshu_enabled', 1);
    $status_html = $enabled 
        ? '<span style="color:#10b981; font-weight:bold;">● Aktiv</span>' 
        : '<span style="color:#ef4444; font-weight:bold;">● Inaktiv</span>';
    ?>
    <div style="font-family: 'Inter', system-ui, sans-serif;">
        <div style="display:flex; justify-content:space-between; margin-bottom:15px; border-bottom:1px solid #f0f0f1; padding-bottom:10px;">
            <span>Engine Status:</span>
            <?php echo $status_html; ?>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:20px;">
            <div style="background:#f8fafc; padding:10px; border-radius:6px; text-align:center; border:1px solid #e2e8f0;">
                <div style="font-size:18px; font-weight:bold; color:#0f1720;"><?php echo $stats['count']; ?></div>
                <div style="font-size:11px; color:#64748b; text-transform:uppercase;">Dateien</div>
            </div>
            <div style="background:#f8fafc; padding:10px; border-radius:6px; text-align:center; border:1px solid #e2e8f0;">
                <div style="font-size:18px; font-weight:bold; color:#0f1720;"><?php echo $stats['size']; ?></div>
                <div style="font-size:11px; color:#64748b; text-transform:uppercase;">Speicher</div>
            </div>
        </div>
        <form method="post">
            <?php wp_nonce_field('kyasshu_dash_action', 'kyasshu_dash_nonce'); ?>
            <button type="submit" name="kyasshu_dash_flush" value="1" class="button" style="width:100%; text-align:center; color:#dc2626; border-color:#fecaca;">
                🗑️ Cache jetzt leeren
            </button>
        </form>
    </div>
    <?php
}