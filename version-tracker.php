<?php
/**
 * Plugin Name: Version Tracker
 * Description: Automatically tracks WordPress core, plugin, and theme version changes daily
 * Version: 2.0.0
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
define('VERSION_TRACKER_API_KEY', 'VRmBv3VizXMGKk4JhYXE');

require_once(plugin_dir_path(__FILE__) . 'includes/email-report.php');

register_activation_hook(__FILE__, 'activate_version_tracker');
register_deactivation_hook(__FILE__, 'deactivate_version_tracker');

add_action('upgrader_process_complete', 'version_tracker_handle_plugin_update', 10, 2);
add_action('deleted_plugin', 'version_tracker_handle_plugin_deletion', 10, 1);
add_action('admin_menu', 'version_tracker_add_admin_menu');
add_action('admin_enqueue_scripts', 'version_tracker_enqueue_admin_assets');
add_action('rest_api_init', 'version_tracker_register_rest_routes');

function activate_version_tracker() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        type varchar(20) NOT NULL,
        slug varchar(255) NOT NULL,
        display_name varchar(255) NOT NULL,
        old_version varchar(50),
        new_version varchar(50),
        is_reported tinyint(1) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY type_slug (type, slug),
        KEY created_at (created_at),
        KEY is_reported (is_reported)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    if (version_tracker_is_table_empty()) {
        version_tracker_record_initial_plugins();
    }
}

function deactivate_version_tracker() {
}

function version_tracker_is_table_empty() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    
    return $count === 0 || $count === '0';
}

function version_tracker_record_initial_plugins() {
    if (!function_exists('get_plugins')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    
    $all_plugins = get_plugins();
    
    foreach ($all_plugins as $plugin_path => $plugin_data) {
        $plugin_slug = version_tracker_get_plugin_slug($plugin_path);
        $plugin_name = $plugin_data['Name'];
        $current_version = $plugin_data['Version'];
        
        version_tracker_log_version_change('plugin', $plugin_slug, $plugin_name, null, $current_version);
    }
}

function version_tracker_get_plugin_slug($plugin_path) {
    return dirname($plugin_path);
}

function version_tracker_handle_plugin_update($upgrader, $hook_extra) {
    if (!isset($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
        return;
    }
    
    if (!isset($hook_extra['plugins']) || empty($hook_extra['plugins'])) {
        return;
    }
    
    if (!function_exists('get_plugins')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    
    $all_plugins = get_plugins();
    
    foreach ($hook_extra['plugins'] as $plugin_path) {
        if (!isset($all_plugins[$plugin_path])) {
            continue;
        }
        
        $plugin_data = $all_plugins[$plugin_path];
        $plugin_slug = version_tracker_get_plugin_slug($plugin_path);
        $plugin_name = $plugin_data['Name'];
        $new_version = $plugin_data['Version'];
        
        $existing = version_tracker_get_current_plugin_record($plugin_slug);
        
        if (!$existing) {
            version_tracker_log_version_change('plugin', $plugin_slug, $plugin_name, null, $new_version);
        } elseif ($existing->new_version !== $new_version) {
            version_tracker_log_version_change('plugin', $plugin_slug, $plugin_name, $existing->new_version, $new_version);
        }
    }
}

function version_tracker_handle_plugin_deletion($plugin) {
    if (!function_exists('get_plugins')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    
    $plugin_slug = version_tracker_get_plugin_slug($plugin);
    $existing = version_tracker_get_current_plugin_record($plugin_slug);
    
    if ($existing) {
        version_tracker_log_version_change('plugin', $plugin_slug, $existing->display_name, $existing->new_version, null);
    }
}

function version_tracker_get_current_plugin_record($plugin_slug) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE type = %s AND slug = %s ORDER BY created_at DESC LIMIT 1",
        'plugin',
        $plugin_slug
    ));
}

function version_tracker_log_version_change($type, $slug, $display_name, $old_version, $new_version) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    
    $wpdb->insert(
        $table_name,
        [
            'type' => $type,
            'slug' => $slug,
            'display_name' => $display_name,
            'old_version' => $old_version,
            'new_version' => $new_version,
            'is_reported' => 0,
            'created_at' => current_time('mysql')
        ],
        ['%s', '%s', '%s', '%s', '%s', '%d', '%s']
    );
}

function get_display_state($record) {
    if (!$record->old_version && $record->new_version) {
        return 'installed';
    } elseif ($record->old_version && $record->new_version) {
        return 'updated';
    } elseif ($record->old_version && !$record->new_version) {
        return 'deleted';
    }
    
    return 'installed';
}

function version_tracker_get_unreported_plugin_changes() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    
    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE is_reported = %d ORDER BY slug, created_at DESC", 0));
    
    $grouped = [];
    $seen = [];
    
    foreach ($results as $record) {
        if (isset($seen[$record->slug])) {
            continue;
        }
        
        $seen[$record->slug] = true;
        $display_state = get_display_state($record);
        
        if (!isset($grouped[$display_state])) {
            $grouped[$display_state] = [];
        }
        $grouped[$display_state][] = $record;
    }
    
    return $grouped;
}

function version_tracker_get_grouped_plugin_changes() {
    return version_tracker_get_unreported_plugin_changes();
}

function version_tracker_mark_as_reported($record_ids) {
    if (!is_array($record_ids)) {
        $record_ids = [$record_ids];
    }
    
    $record_ids = array_map('intval', $record_ids);
    $record_ids = array_filter($record_ids);
    
    if (empty($record_ids)) {
        return false;
    }
    
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    $placeholders = implode(',', array_fill(0, count($record_ids), '%d'));
    
    $query = "UPDATE $table_name SET is_reported = 1 WHERE id IN ($placeholders)";
    
    return $wpdb->query($wpdb->prepare($query, ...$record_ids)) !== false;
}

function version_tracker_mark_all_as_reported() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    
    return $wpdb->query("UPDATE $table_name SET is_reported = 1 WHERE is_reported = 0") !== false;
}

function get_plugins_with_available_updates() {
    if (!function_exists('get_plugins')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    
    if (!function_exists('wp_update_plugins')) {
        require_once(ABSPATH . 'wp-admin/includes/update.php');
    }
    
    wp_update_plugins();
    
    $all_plugins = get_plugins();
    $updates = get_site_transient('update_plugins');
    
    if (!$updates || !isset($updates->response)) {
        return [];
    }
    
    $plugins_with_updates = [];
    
    foreach ($updates->response as $plugin_path => $plugin_data) {
        if (isset($all_plugins[$plugin_path])) {
            $plugin_name = $all_plugins[$plugin_path]['Name'];
            $current_version = $all_plugins[$plugin_path]['Version'];
            $new_version = $plugin_data->new_version;
            
            $plugins_with_updates[$plugin_name] = [
                'current_version' => $current_version,
                'available_version' => $new_version
            ];
        }
    }
    
    return $plugins_with_updates;
}

function version_tracker_get_saved_report_emails() {
    $saved_emails = get_option('version_tracker_report_emails');
    
    if ($saved_emails && is_array($saved_emails) && !empty($saved_emails)) {
        $valid_emails = array_filter($saved_emails, function($email) {
            return is_email($email);
        });
        
        if (!empty($valid_emails)) {
            return array_values($valid_emails);
        }
    }
    
    $admin_email = get_option('admin_email');
    return is_email($admin_email) ? [$admin_email] : [];
}

function version_tracker_get_saved_report_emails_string() {
    $emails = version_tracker_get_saved_report_emails();
    return implode(', ', $emails);
}

function version_tracker_register_rest_routes() {
    register_rest_route('version-tracker/v1', '/changes', [
        'methods' => 'GET',
        'callback' => 'version_tracker_rest_get_changes',
        'permission_callback' => 'version_tracker_check_api_key'
    ]);
}

function version_tracker_check_api_key($request) {
    $api_key = $request->get_header('X-API-Key');
    
    if (empty($api_key)) {
        return new WP_Error('missing_api_key', 'API key is missing', ['status' => 401]);
    }
    
    if ($api_key !== VERSION_TRACKER_API_KEY) {
        return new WP_Error('invalid_api_key', 'Invalid API key', ['status' => 403]);
    }
    
    return true;
}

function version_tracker_rest_get_changes($request) {
    $grouped = version_tracker_get_unreported_plugin_changes();
    $serialized = version_tracker_serialize_grouped_changes($grouped);
    
    return new WP_REST_Response([
        'success' => true,
        'data' => $serialized
    ], 200);
}

function version_tracker_add_admin_menu() {
    add_submenu_page(
        'tools.php',
        'Version Tracker',
        'Version Tracker',
        'manage_options',
        'version-tracker',
        'version_tracker_admin_page'
    );
}

function version_tracker_enqueue_admin_assets($hook) {
    if ($hook !== 'tools_page_version-tracker') {
        return;
    }
    
    wp_enqueue_style(
        'version-tracker-admin',
        plugins_url('css/admin.css', __FILE__),
        [],
        '2.0.0'
    );

    wp_enqueue_style(
            'version-tracker-table',
            plugins_url('css/table.css', __FILE__),
            [],
            '2.0.0'
    );

    wp_enqueue_script(
        'version-tracker-admin',
        plugins_url('js/admin.js', __FILE__),
        ['jquery'],
        '2.0.0',
        true
    );
    
    wp_localize_script('version-tracker-admin', 'versionTrackerAdmin', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'sendReportAction' => 'version_tracker_send_report'
    ]);
}

function version_tracker_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $saved_emails = version_tracker_get_saved_report_emails_string();
    
    ?>
    <div class="wrap">
        <h1>Version Tracker</h1>
        
        <div class="vt-email-input-container">
            <label for="vt-report-email-input">Report Email Addresses:</label>
            <input type="text" id="vt-report-email-input" class="vt-email-input" value="<?php echo esc_attr($saved_emails); ?>" placeholder="Enter email addresses separated by commas (e.g., email1@example.com, email2@example.com)">
            <p class="vt-email-info">Enter one or more email addresses separated by commas where you want to receive the report</p>
            <button type="button" id="vt-send-report-btn" class="button button-secondary">Send Report</button>
        </div>
        
        <div class="vt-bulk-actions">
            <button type="button" id="vt-mark-all-reported-btn" class="button button-secondary">Mark All as Reported</button>
            <span class="vt-bulk-action-info">Select rows to mark individual changes as reported</span>
        </div>
        
        <div id="vt-versions-container">
            <?php version_tracker_display_plugins(); ?>
        </div>
    </div>
    <?php
}

function version_tracker_display_plugins() {
    $available_updates = get_plugins_with_available_updates();
    $available_updates_html = version_tracker_generate_available_updates_table_html($available_updates);
    
    $grouped = version_tracker_get_unreported_plugin_changes();
    $changes_html = version_tracker_generate_table_html($grouped);
    
    echo $available_updates_html;
    echo $changes_html;
}

add_action('wp_ajax_version_tracker_send_report', function() {
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(['error' => 'Unauthorized']));
    }
    
    $recipient_emails_string = isset($_POST['recipient_emails']) ? sanitize_text_field($_POST['recipient_emails']) : '';
    
    if (empty($recipient_emails_string)) {
        $recipient_emails = version_tracker_get_saved_report_emails();
    } else {
        $recipient_emails = version_tracker_parse_email_list($recipient_emails_string);
        
        if (empty($recipient_emails)) {
            wp_die(json_encode(['error' => 'No valid email addresses provided']));
        }
        
        $invalid_emails = version_tracker_get_invalid_emails($recipient_emails_string);
        if (!empty($invalid_emails)) {
            wp_die(json_encode(['error' => 'Invalid email addresses: ' . implode(', ', $invalid_emails)]));
        }
        
        update_option('version_tracker_report_emails', $recipient_emails);
    }
    
    $result = version_tracker_send_report_email($recipient_emails);
    
    if ($result['success']) {
        $recipient_count = count($result['sent_to']);
        $message = sprintf('Report sent successfully to %d recipient%s', $recipient_count, $recipient_count !== 1 ? 's' : '');
        wp_die(json_encode(['success' => true, 'message' => $message]));
    } else {
        wp_die(json_encode(['error' => 'Failed to send report email: ' . $result['error']]));
    }
});

add_action('wp_ajax_version_tracker_get_saved_email', function() {
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(['error' => 'Unauthorized']));
    }
    
    $emails = version_tracker_get_saved_report_emails_string();
    
    wp_die(json_encode(['email' => $emails]));
});

add_action('wp_ajax_version_tracker_mark_reported', function() {
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(['error' => 'Unauthorized']));
    }
    
    $record_ids = isset($_POST['record_ids']) ? array_map('intval', (array)$_POST['record_ids']) : [];
    
    if (empty($record_ids)) {
        wp_die(json_encode(['error' => 'No records specified']));
    }
    
    $result = version_tracker_mark_as_reported($record_ids);
    
    if ($result) {
        wp_die(json_encode(['success' => true, 'message' => 'Changes marked as reported']));
    } else {
        wp_die(json_encode(['error' => 'Failed to mark records as reported']));
    }
});

add_action('wp_ajax_version_tracker_mark_all_reported', function() {
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(['error' => 'Unauthorized']));
    }
    
    $result = version_tracker_mark_all_as_reported();
    
    if ($result) {
        wp_die(json_encode(['success' => true, 'message' => 'All changes marked as reported']));
    } else {
        wp_die(json_encode(['error' => 'Failed to mark all records as reported']));
    }
});