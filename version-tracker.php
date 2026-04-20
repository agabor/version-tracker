<?php
/**
 * Plugin Name: Version Tracker
 * Description: Automatically tracks WordPress core, plugin, and theme version changes daily
 * Version: 1.2.0
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
define('VERSION_TRACKER_CHECKPOINTS_TABLE', 'version_tracker_checkpoints');
define('VERSION_TRACKER_CRON_HOOK', 'version_tracker_daily_check');

require_once(plugin_dir_path(__FILE__) . 'includes/email-report.php');

register_activation_hook(__FILE__, 'activate_version_tracker');
register_deactivation_hook(__FILE__, 'deactivate_version_tracker');

add_action(VERSION_TRACKER_CRON_HOOK, 'check_versions');
add_action('admin_menu', 'version_tracker_add_admin_menu');
add_action('admin_enqueue_scripts', 'version_tracker_enqueue_admin_assets');

function activate_version_tracker() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    $checkpoints_table = $wpdb->prefix . VERSION_TRACKER_CHECKPOINTS_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    
    $checkpoints_sql = "CREATE TABLE IF NOT EXISTS $checkpoints_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        date datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY date (date)
    ) $charset_collate;";
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        checkpoint_id bigint(20) NOT NULL,
        type varchar(20) NOT NULL,
        name varchar(255) NOT NULL,
        old_version varchar(50),
        new_version varchar(50),
        state varchar(20) NOT NULL DEFAULT 'current',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY checkpoint_id (checkpoint_id),
        KEY type_name (type, name),
        KEY state (state),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($checkpoints_sql);
    dbDelta($sql);
    
    create_checkpoint_if_needed();
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

function create_checkpoint_if_needed() {
    global $wpdb;
    
    $checkpoints_table = $wpdb->prefix . VERSION_TRACKER_CHECKPOINTS_TABLE;
    
    $checkpoint_exists = $wpdb->get_var("SELECT COUNT(*) FROM $checkpoints_table");
    
    if ($checkpoint_exists == 0) {
        $wpdb->insert(
            $checkpoints_table,
            ['date' => current_time('mysql')],
            ['%s']
        );
    }
}

function get_last_checkpoint_id() {
    global $wpdb;
    
    $checkpoints_table = $wpdb->prefix . VERSION_TRACKER_CHECKPOINTS_TABLE;
    
    $checkpoint_id = $wpdb->get_var(
        "SELECT id FROM $checkpoints_table ORDER BY id DESC LIMIT 1"
    );
    
    return $checkpoint_id ? intval($checkpoint_id) : 0;
}

function check_versions() {
    $core_version = get_core_version();
    $plugins_versions = get_plugins_versions();
    $themes_versions = get_themes_versions();
    
    compare_and_update_versions('core', ['WordPress' => $core_version]);
    compare_and_update_versions('plugin', $plugins_versions);
    compare_and_update_versions('theme', $themes_versions);
    
    mark_removed_items('plugin', array_keys($plugins_versions));
    mark_removed_items('theme', array_keys($themes_versions));
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
    $checkpoint_id = get_last_checkpoint_id();
    
    foreach ($current_items as $name => $version) {
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE type = %s AND name = %s AND state = %s ORDER BY created_at DESC LIMIT 1",
            $type,
            $name,
            'current'
        ));
        
        if (!$existing) {
            log_version_change($type, $name, null, $version, 'current', $checkpoint_id);
        } elseif ($existing->new_version !== $version) {
            $wpdb->update(
                $table_name,
                ['state' => 'old'],
                ['id' => $existing->id],
                ['%s'],
                ['%d']
            );
            log_version_change($type, $name, $existing->new_version, $version, 'current', $checkpoint_id);
        }
    }
}

function mark_removed_items($type, $current_items) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    $checkpoint_id = get_last_checkpoint_id();
    
    $current_items_sql = implode(',', array_map(function($item) {
        return "'" . esc_sql($item) . "'";
    }, $current_items));
    
    if (empty($current_items)) {
        $query = $wpdb->prepare(
            "SELECT * FROM $table_name WHERE type = %s AND state = %s",
            $type,
            'current'
        );
    } else {
        $query = "SELECT * FROM $table_name WHERE type = '" . esc_sql($type) . "' AND state = 'current' AND name NOT IN ($current_items_sql)";
    }
    
    $removed_items = $wpdb->get_results($query);
    
    foreach ($removed_items as $item) {
        $wpdb->update(
            $table_name,
            ['state' => 'old'],
            ['id' => $item->id],
            ['%s'],
            ['%d']
        );
        
        log_version_change($type, $item->name, $item->new_version, null, 'removed', $checkpoint_id);
    }
}

function log_version_change($type, $name, $old_version, $new_version, $state, $checkpoint_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    
    $wpdb->insert(
        $table_name,
        [
            'checkpoint_id' => $checkpoint_id,
            'type' => $type,
            'name' => $name,
            'old_version' => $old_version,
            'new_version' => $new_version,
            'state' => $state,
            'created_at' => current_time('mysql')
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
    );
}

function version_tracker_get_checkpoints() {
    global $wpdb;
    
    $checkpoints_table = $wpdb->prefix . VERSION_TRACKER_CHECKPOINTS_TABLE;
    
    $checkpoints = $wpdb->get_results(
        "SELECT id, date FROM $checkpoints_table ORDER BY id DESC"
    );
    
    return $checkpoints;
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

function version_tracker_get_grouped_plugin_changes($checkpoint_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE type = %s AND checkpoint_id = %d ORDER BY name, created_at DESC",
        'plugin',
        intval($checkpoint_id)
    ));
    
    $grouped = [];
    foreach ($results as $record) {
        $display_state = get_display_state($record);
        
        if (!isset($grouped[$display_state])) {
            $grouped[$display_state] = [];
        }
        $grouped[$display_state][] = $record;
    }
    
    return $grouped;
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
        '1.2.0'
    );

    wp_enqueue_style(
            'version-tracker-table',
            plugins_url('css/table.css', __FILE__),
            [],
            '1.2.0'
    );

    wp_enqueue_script(
        'version-tracker-admin',
        plugins_url('js/admin.js', __FILE__),
        ['jquery'],
        '1.2.0',
        true
    );
    
    wp_localize_script('version-tracker-admin', 'versionTrackerAdmin', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'getVersionsAction' => 'version_tracker_get_versions',
        'createCheckpointAction' => 'version_tracker_create_checkpoint',
        'deleteCheckpointAction' => 'version_tracker_delete_last_checkpoint',
        'manualCheckAction' => 'version_tracker_manual_check',
        'sendReportAction' => 'version_tracker_send_report'
    ]);
}

function version_tracker_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $checkpoints = version_tracker_get_checkpoints();
    $selected_checkpoint_id = isset($_GET['vt_checkpoint']) ? intval($_GET['vt_checkpoint']) : (count($checkpoints) > 0 ? $checkpoints[0]->id : 0);
    $saved_emails = version_tracker_get_saved_report_emails_string();
    
    ?>
    <div class="wrap">
        <h1>Version Tracker</h1>
        
        <div class="vt-checkpoint-selector-container">
            <label for="vt-checkpoint-selector">Select Checkpoint:</label>
            <select id="vt-checkpoint-selector" name="vt_checkpoint">
                <?php foreach ($checkpoints as $checkpoint): ?>
                    <option value="<?php echo esc_attr($checkpoint->id); ?>" <?php selected($selected_checkpoint_id, $checkpoint->id); ?>>
                        <?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($checkpoint->date))); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="vt-filter-btn" class="button button-primary">Show Changes</button>
            <button type="button" id="vt-manual-check-btn" class="button button-secondary">Check Now</button>
            <button type="button" id="vt-create-checkpoint-btn" class="button button-secondary">Create Checkpoint</button>
            <button type="button" id="vt-delete-checkpoint-btn" class="button button-danger">Delete Last Checkpoint</button>
        </div>
        
        <div class="vt-email-input-container">
            <label for="vt-report-email-input">Report Email Addresses:</label>
            <input type="text" id="vt-report-email-input" class="vt-email-input" value="<?php echo esc_attr($saved_emails); ?>" placeholder="Enter email addresses separated by commas (e.g., email1@example.com, email2@example.com)">
            <p class="vt-email-info">Enter one or more email addresses separated by commas where you want to receive the report</p>
            <button type="button" id="vt-send-report-btn" class="button button-secondary">Send Report</button>
        </div>
        
        <div id="vt-versions-container">
            <?php version_tracker_display_plugins_since_checkpoint($selected_checkpoint_id); ?>
        </div>
    </div>
    <?php
}

function version_tracker_display_plugins_since_checkpoint($checkpoint_id) {
    $available_updates = get_plugins_with_available_updates();
    $available_updates_html = version_tracker_generate_available_updates_table_html($available_updates);
    
    $grouped = version_tracker_get_grouped_plugin_changes($checkpoint_id);
    $changes_html = version_tracker_generate_table_html($grouped);
    
    echo $available_updates_html;
    echo $changes_html;
}

add_action('wp_ajax_version_tracker_get_versions', function() {
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(['error' => 'Unauthorized']));
    }
    
    $checkpoint_id = isset($_POST['checkpoint_id']) ? intval($_POST['checkpoint_id']) : 0;
    
    if ($checkpoint_id === 0) {
        wp_die(json_encode(['error' => 'Invalid checkpoint ID']));
    }
    
    $available_updates = get_plugins_with_available_updates();
    $available_updates_html = version_tracker_generate_available_updates_table_html($available_updates);
    
    $grouped = version_tracker_get_grouped_plugin_changes($checkpoint_id);
    $changes_html = version_tracker_generate_table_html($grouped);
    
    $html = $available_updates_html . $changes_html;
    
    wp_die(json_encode(['html' => $html]));
});

add_action('wp_ajax_version_tracker_create_checkpoint', function() {
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(['error' => 'Unauthorized']));
    }
    
    global $wpdb;
    $checkpoints_table = $wpdb->prefix . VERSION_TRACKER_CHECKPOINTS_TABLE;
    
    $result = $wpdb->insert(
        $checkpoints_table,
        ['date' => current_time('mysql')],
        ['%s']
    );
    
    if ($result) {
        wp_die(json_encode(['success' => true, 'checkpoint_id' => $wpdb->insert_id]));
    } else {
        wp_die(json_encode(['error' => 'Failed to create checkpoint']));
    }
});

add_action('wp_ajax_version_tracker_delete_last_checkpoint', function() {
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(['error' => 'Unauthorized']));
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    $checkpoints_table = $wpdb->prefix . VERSION_TRACKER_CHECKPOINTS_TABLE;
    
    $last_checkpoint = $wpdb->get_row(
        "SELECT id FROM $checkpoints_table ORDER BY id DESC LIMIT 1"
    );
    
    if (!$last_checkpoint) {
        wp_die(json_encode(['error' => 'No checkpoint to delete']));
    }
    
    $checkpoint_id = intval($last_checkpoint->id);
    $version_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE checkpoint_id = %d",
        $checkpoint_id
    ));
    
    if ($version_count > 0) {
        wp_die(json_encode(['error' => 'Cannot delete checkpoint that contains version records']));
    }
    
    $delete_result = $wpdb->delete(
        $checkpoints_table,
        ['id' => $checkpoint_id],
        ['%d']
    );
    
    if ($delete_result) {
        wp_die(json_encode(['success' => true]));
    } else {
        wp_die(json_encode(['error' => 'Failed to delete checkpoint']));
    }
});

add_action('wp_ajax_version_tracker_manual_check', function() {
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(['error' => 'Unauthorized']));
    }
    
    check_versions();
    
    wp_die(json_encode(['success' => true, 'message' => 'Version check completed successfully']));
});

add_action('wp_ajax_version_tracker_send_report', function() {
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(['error' => 'Unauthorized']));
    }
    
    $checkpoint_id = isset($_POST['checkpoint_id']) ? intval($_POST['checkpoint_id']) : 0;
    $recipient_emails_string = isset($_POST['recipient_emails']) ? sanitize_text_field($_POST['recipient_emails']) : '';
    
    if ($checkpoint_id === 0) {
        wp_die(json_encode(['error' => 'Invalid checkpoint ID']));
    }
    
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
    
    $result = version_tracker_send_report_email($checkpoint_id, $recipient_emails);
    
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