<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$table_name = $wpdb->prefix . 'version_tracker';
$checkpoints_table_name = $wpdb->prefix . 'version_tracker_checkpoints';

$wpdb->query("DROP TABLE IF EXISTS $table_name");
$wpdb->query("DROP TABLE IF EXISTS $checkpoints_table_name");