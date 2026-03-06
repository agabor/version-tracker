<?php
/**
 * Plugin Name: Version Tracker
 * Description: Automatically tracks WordPress core, plugin, and theme version changes daily
 * Version: 1.0.6
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

function version_tracker_get_admin_email() {
    return get_option('admin_email');
}

function version_tracker_generate_report_html($checkpoint_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE type = %s AND checkpoint_id >= %d ORDER BY name, created_at DESC",
        'plugin',
        intval($checkpoint_id)
    ));
    
    if (empty($results)) {
        return '<p>No plugin changes found since selected checkpoint.</p>';
    }
    
    $grouped = [];
    foreach ($results as $record) {
        $display_state = get_display_state($record);
        
        if (!isset($grouped[$display_state])) {
            $grouped[$display_state] = [];
        }
        $grouped[$display_state][] = $record;
    }
    
    $state_labels = [
        'installed' => 'Installed',
        'updated' => 'Updated',
        'deleted' => 'Deleted'
    ];
    
    $state_order = ['installed', 'updated', 'deleted'];
    
    $html = '';
    
    foreach ($state_order as $state) {
        if (isset($grouped[$state])) {
            $html .= '<div style="margin-bottom: 30px;">';
            $html .= '<h2 style="margin-top: 0; padding-bottom: 10px; border-bottom: 2px solid #0073aa; color: #0073aa;">' . esc_html($state_labels[$state]) . '</h2>';
            $html .= '<table style="width: 100%; border-collapse: collapse; margin-top: 10px;">';
            $html .= '<thead><tr style="background-color: #f5f5f5; border-bottom: 1px solid #ddd;">';
            $html .= '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Plugin Name</th>';
            $html .= '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Version Info</th>';
            $html .= '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">State</th>';
            $html .= '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Changed At</th>';
            $html .= '</tr></thead>';
            $html .= '<tbody>';
            
            foreach ($grouped[$state] as $record) {
                $html .= '<tr style="border-bottom: 1px solid #ddd;">';
                $html .= '<td style="padding: 10px; border: 1px solid #ddd;">' . esc_html($record->name) . '</td>';
                $html .= '<td style="padding: 10px; border: 1px solid #ddd;">';
                
                if ($state === 'installed') {
                    $html .= '<span style="background-color: #e8f4f8; color: #0073aa; padding: 4px 8px; border-radius: 3px; font-family: monospace; font-weight: 500;">' . esc_html($record->new_version) . '</span>';
                } elseif ($state === 'updated') {
                    $html .= '<span style="background-color: #fff3cd; color: #856404; padding: 4px 8px; border-radius: 3px; font-family: monospace; font-weight: 500;">' . esc_html($record->old_version) . '</span>';
                    $html .= ' <span style="margin: 0 6px; color: #666; font-weight: bold;">→</span> ';
                    $html .= '<span style="background-color: #d4edda; color: #155724; padding: 4px 8px; border-radius: 3px; font-family: monospace; font-weight: 500;">' . esc_html($record->new_version) . '</span>';
                } elseif ($state === 'deleted') {
                    $html .= '<span style="background-color: #fff3cd; color: #856404; padding: 4px 8px; border-radius: 3px; font-family: monospace; font-weight: 500;">' . esc_html($record->old_version) . '</span>';
                }
                
                $html .= '</td>';
                $html .= '<td style="padding: 10px; border: 1px solid #ddd;">';
                $html .= '<span style="display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 12px; font-weight: 600;';
                
                if ($state === 'installed') {
                    $html .= 'background-color: #d4edda; color: #155724;';
                } elseif ($state === 'updated') {
                    $html .= 'background-color: #fff3cd; color: #856404;';
                } elseif ($state === 'deleted') {
                    $html .= 'background-color: #f8d7da; color: #721c24;';
                }
                
                $html .= '">' . esc_html(ucfirst($state)) . '</span>';
                $html .= '</td>';
                $html .= '<td style="padding: 10px; border: 1px solid #ddd;">' . esc_html(date_i18n('Y-m-d H:i:s', strtotime($record->created_at))) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody>';
            $html .= '</table>';
            $html .= '</div>';
        }
    }
    
    return $html;
}

function version_tracker_send_report_email($checkpoint_id) {
    $admin_email = version_tracker_get_admin_email();
    $site_name = get_bloginfo('name');
    
    $subject = sprintf('[%s] Plugin Update Report', $site_name);
    
    $report_html = version_tracker_generate_report_html($checkpoint_id);
    
    $message = '<!DOCTYPE html>';
    $message .= '<html><head><meta charset="UTF-8"><title>Plugin Update Report</title></head>';
    $message .= '<body style="font-family: Arial, sans-serif; color: #333; background-color: #f9f9f9;">';
    $message .= '<div style="max-width: 600px; margin: 20px auto; padding: 20px; background-color: #ffffff; border: 1px solid #ddd; border-radius: 4px;">';
    $message .= '<h1 style="margin-top: 0; color: #0073aa;">Version Tracker Report</h1>';
    $message .= '<p style="margin: 10px 0; color: #666;">Site: <strong>' . esc_html($site_name) . '</strong></p>';
    $message .= '<p style="margin: 10px 0; color: #666;">Report generated on: <strong>' . esc_html(date_i18n('Y-m-d H:i:s', current_time('timestamp'))) . '</strong></p>';
    $message .= '<hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">';
    $message .= $report_html;
    $message .= '<hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">';
    $message .= '<p style="margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 12px; color: #999;">This is an automated email from Version Tracker plugin.</p>';
    $message .= '</div>';
    $message .= '</body></html>';
    
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    
    return wp_mail($admin_email, $subject, $message, $headers);
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
    
    wp_enqueue_style(
        'version-tracker-admin',
        plugins_url('css/admin.css', __FILE__),
        [],
        '1.0.6'
    );
    
    wp_enqueue_script(
        'version-tracker-admin',
        plugins_url('js/admin.js', __FILE__),
        ['jquery'],
        '1.0.6',
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
            <button type="button" id="vt-send-report-btn" class="button button-secondary">Send Report</button>
        </div>
        
        <div id="vt-versions-container">
            <?php version_tracker_display_plugins_since_checkpoint($selected_checkpoint_id); ?>
        </div>
    </div>
    <?php
}

function version_tracker_display_plugins_since_checkpoint($checkpoint_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . VERSION_TRACKER_TABLE;
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE type = %s AND checkpoint_id >= %d ORDER BY name, created_at DESC",
        'plugin',
        intval($checkpoint_id)
    ));
    
    if (empty($results)) {
        echo '<p>No plugin changes found since selected checkpoint.</p>';
        return;
    }
    
    $grouped = [];
    foreach ($results as $record) {
        $display_state = get_display_state($record);
        
        if (!isset($grouped[$display_state])) {
            $grouped[$display_state] = [];
        }
        $grouped[$display_state][] = $record;
    }
    
    $state_labels = [
        'installed' => 'Installed',
        'updated' => 'Updated',
        'deleted' => 'Deleted'
    ];
    
    $state_order = ['installed', 'updated', 'deleted'];
    
    ?>
    <div class="vt-results">
        <?php foreach ($state_order as $state): ?>
            <?php if (isset($grouped[$state])): ?>
                <div class="vt-state-section">
                    <h2><?php echo esc_html($state_labels[$state]); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Plugin Name</th>
                                <th>Version Info</th>
                                <th>State</th>
                                <th>Changed At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped[$state] as $record): ?>
                                <tr class="state-<?php echo esc_attr($state); ?>">
                                    <td><?php echo esc_html($record->name); ?></td>
                                    <td>
                                        <?php if ($state === 'installed'): ?>
                                            <span class="vt-version"><?php echo esc_html($record->new_version); ?></span>
                                        <?php elseif ($state === 'updated'): ?>
                                            <span class="vt-old-version"><?php echo esc_html($record->old_version); ?></span>
                                            <span class="vt-arrow">→</span>
                                            <span class="vt-new-version"><?php echo esc_html($record->new_version); ?></span>
                                        <?php elseif ($state === 'deleted'): ?>
                                            <span class="vt-old-version"><?php echo esc_html($record->old_version); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="vt-state-badge state-<?php echo esc_attr($state); ?>"><?php echo esc_html(ucfirst($state)); ?></span></td>
                                    <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($record->created_at))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php
}

add_action('wp_ajax_version_tracker_get_versions', function() {
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(['error' => 'Unauthorized']));
    }
    
    $checkpoint_id = isset($_POST['checkpoint_id']) ? intval($_POST['checkpoint_id']) : 0;
    
    if ($checkpoint_id === 0) {
        wp_die(json_encode(['error' => 'Invalid checkpoint ID']));
    }
    
    ob_start();
    version_tracker_display_plugins_since_checkpoint($checkpoint_id);
    $html = ob_get_clean();
    
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
    
    if ($checkpoint_id === 0) {
        wp_die(json_encode(['error' => 'Invalid checkpoint ID']));
    }
    
    $result = version_tracker_send_report_email($checkpoint_id);
    
    if ($result) {
        wp_die(json_encode(['success' => true, 'message' => 'Report sent successfully to administrator email']));
    } else {
        wp_die(json_encode(['error' => 'Failed to send report email']));
    }
});