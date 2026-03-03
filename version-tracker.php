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