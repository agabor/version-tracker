<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$table_name = $wpdb->prefix . 'version_tracker';

$wpdb->query("DROP TABLE IF EXISTS $table_name");