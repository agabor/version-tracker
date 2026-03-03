<?php
/**
 * Plugin Name: Version Tracker
 * Description: Automatically tracks WordPress core, plugin, and theme version changes daily
 * Version: 1.0.0
 * Author: Gabor Angyal
 * Author URI: https://webshop.tech
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: version-tracker
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VERSION_TRACKER_TABLE', 'version_tracker');
define('VERSION_TRACKER_CRON_HOOK', 'version_tracker_daily_check');

register_activation_hook(__FILE__, 'activate_version_tracker');
register_deactivation_hook(__FILE__, 'deactivate_version_tracker');

add_action(VERSION_TRACKER_CRON_HOOK, 'check_versions');
add_action('admin_menu', 'version_tracker_add_admin_menu');
add_action('admin_enqueue_scripts', 'version_tracker_enqueue_admin_assets');

function activate_version_tracker() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        type varchar(20) NOT NULL,
        name varchar(255) NOT NULL,
        version varchar(50) NOT NULL,
        state varchar(20) NOT NULL DEFAULT 'current',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY type_name (type, name),
        KEY state (state),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    schedule_version_check();
    
    check_versions();
}

function deactivate_version_tracker() {
    wp_clear_scheduled_hook(VERSION_TRACKER_CRON_HOOK);
}

function schedule_version_check() {
    if (!wp_next_scheduled(VERSION_TRACKER_CRON_HOOK)) {
        wp_schedule_event(time(), 'daily', VERSION_TRACKER_CRON_HOOK);
    }
}

function check_versions() {
    $core_version = get_core_version();
    $plugins_versions = get_plugins_versions();
    $themes_versions = get_themes_versions();
    
    compare_and_update_versions('core', ['WordPress' => $core_version]);
    compare_and_update_versions('plugin', $plugins_versions);
    compare_and_update_versions('theme', $themes_versions);
}

function get_core_version() {
    global $wp_version;
    return $wp_version;
}

function get_plugins_versions() {
    if (!function_exists('get_plugins')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    
    $all_plugins = get_plugins();
    $plugins_data = [];
    
    foreach ($all_plugins as $plugin_path => $plugin_data) {
        $plugin_name = $plugin_data['Name'];
        $plugin_version = $plugin_data['Version'];
        $plugins_data[$plugin_name] = $plugin_version;
    }
    
    return $plugins_data;
}

function get_themes_versions() {
    $themes = wp_get_themes();
    $themes_data = [];
    
    foreach ($themes as $theme) {
        $theme_name = $theme->get('Name');
        $theme_version = $theme->get('Version');
        $themes_data[$theme_name] = $theme_version;
    }
    
    return $themes_data;
}

function compare_and_update_versions($type, $current_items) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    
    foreach ($current_items as $name => $version) {
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE type = %s AND name = %s AND state = %s ORDER BY created_at DESC LIMIT 1",
            $type,
            $name,
            'current'
        ));
        
        if (!$existing) {
            log_version_change($type, $name, $version, 'current');
        } elseif ($existing->version !== $version) {
            $wpdb->update(
                $table_name,
                ['state' => 'old'],
                ['id' => $existing->id],
                ['%s'],
                ['%d']
            );
            log_version_change($type, $name, $version, 'current');
        }
    }
}

function log_version_change($type, $name, $version, $state) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    
    $wpdb->insert(
        $table_name,
        [
            'type' => $type,
            'name' => $name,
            'version' => $version,
            'state' => $state,
            'created_at' => current_time('mysql')
        ],
        ['%s', '%s', '%s', '%s', '%s']
    );
}

function version_tracker_add_admin_menu() {
    add_menu_page(
        'Version Tracker',
        'Version Tracker',
        'manage_options',
        'version-tracker',
        'version_tracker_admin_page',
        'dashicons-update'
    );
}

function version_tracker_enqueue_admin_assets($hook) {
    if ($hook !== 'toplevel_page_version-tracker') {
        return;
    }
    
    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style('jquery-ui-datepicker');
    
    wp_enqueue_style(
        'version-tracker-admin',
        plugins_url('css/admin.css', __FILE__),
        [],
        '1.0.0'
    );
    
    wp_enqueue_script(
        'version-tracker-admin',
        plugins_url('js/admin.js', __FILE__),
        ['jquery', 'jquery-ui-datepicker'],
        '1.0.0',
        true
    );
    
    wp_localize_script('version-tracker-admin', 'versionTrackerAdmin', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'todayDate' => current_time('Y-m-d')
    ]);
}

function version_tracker_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $selected_date = isset($_GET['vt_date']) ? sanitize_text_field($_GET['vt_date']) : current_time('Y-m-d');
    
    ?>
    <div class="wrap">
        <h1>Version Tracker</h1>
        
        <div class="vt-date-picker-container">
            <label for="vt-date-picker">Select Date:</label>
            <input type="text" id="vt-date-picker" name="vt_date" value="<?php echo esc_attr($selected_date); ?>" />
            <button type="button" id="vt-filter-btn" class="button button-primary">Filter</button>
        </div>
        
        <div id="vt-versions-container">
            <?php version_tracker_display_versions($selected_date); ?>
        </div>
    </div>
    <?php
}

function version_tracker_display_versions($date) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE DATE(created_at) = %s ORDER BY type, name, created_at DESC",
        $date
    ));
    
    if (empty($results)) {
        echo '<p>No version records found for ' . esc_html($date) . '</p>';
        return;
    }
    
    $grouped = [];
    foreach ($results as $record) {
        if (!isset($grouped[$record->type])) {
            $grouped[$record->type] = [];
        }
        $grouped[$record->type][] = $record;
    }
    
    ?>
    <div class="vt-results">
        <?php foreach ($grouped as $type => $records): ?>
            <div class="vt-type-section">
                <h2><?php echo esc_html(ucfirst($type)); ?>s</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Version</th>
                            <th>State</th>
                            <th>Recorded At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <tr class="state-<?php echo esc_attr($record->state); ?>">
                                <td><?php echo esc_html($record->name); ?></td>
                                <td><?php echo esc_html($record->version); ?></td>
                                <td><span class="vt-state-badge state-<?php echo esc_attr($record->state); ?>"><?php echo esc_html(ucfirst($record->state)); ?></span></td>
                                <td><?php echo esc_html($record->created_at); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

add_action('wp_ajax_version_tracker_get_versions', function() {
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(['error' => 'Unauthorized']));
    }
    
    $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : current_time('Y-m-d');
    
    ob_start();
    version_tracker_display_versions($date);
    $html = ob_get_clean();
    
    wp_die(json_encode(['html' => $html]));
});