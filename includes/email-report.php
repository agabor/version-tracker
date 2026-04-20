<?php

if (!defined('ABSPATH')) {
    exit;
}

function version_tracker_parse_email_list($email_string) {
    $emails = array_map('trim', explode(',', $email_string));
    $valid_emails = array_filter($emails, function($email) {
        return is_email($email);
    });
    
    return array_values($valid_emails);
}

function version_tracker_get_invalid_emails($email_string) {
    $emails = array_map('trim', explode(',', $email_string));
    $invalid_emails = array_filter($emails, function($email) {
        return !empty($email) && !is_email($email);
    });
    
    return array_values($invalid_emails);
}

function version_tracker_get_embedded_styles() {
    $css_file = plugin_dir_path(__FILE__) . '../css/table.css';
    
    if (file_exists($css_file)) {
        $css_content = file_get_contents($css_file);
        return '<style>' . $css_content . '</style>';
    }
    
    return '';
}

function version_tracker_generate_available_updates_table_html($available_updates) {
    if (empty($available_updates)) {
        return '';
    }
    
    $html = '<div class="vt-available-updates-section">';
    $html .= '<h2>Not Updated</h2>';
    $html .= '<table>';
    $html .= '<thead><tr>';
    $html .= '<th>Plugin Name</th>';
    $html .= '<th>Version Info</th>';
    $html .= '<th>Status</th>';
    $html .= '</tr></thead>';
    $html .= '<tbody>';
    
    foreach ($available_updates as $plugin_name => $version_info) {
        $html .= '<tr class="state-available-update">';
        $html .= '<td>' . esc_html($plugin_name) . '</td>';
        $html .= '<td>';
        $html .= '<span class="vt-current-version">' . esc_html($version_info['current_version']) . '</span>';
        $html .= '<span class="vt-arrow">→</span>';
        $html .= '<span class="vt-available-version">' . esc_html($version_info['available_version']) . '</span>';
        $html .= '</td>';
        $html .= '<td><span class="vt-update-available-badge">Update Available</span></td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>';
    
    return $html;
}

function version_tracker_generate_table_html($grouped) {
    if (empty($grouped)) {
        return '<p>No plugin changes found since selected checkpoint.</p>';
    }
    
    $state_labels = [
        'installed' => 'Installed',
        'updated' => 'Updated',
        'deleted' => 'Deleted'
    ];
    
    $state_order = ['installed', 'updated', 'deleted'];
    
    $html = '<div class="vt-results">';
    
    foreach ($state_order as $state) {
        if (isset($grouped[$state])) {
            $html .= '<div class="vt-state-section">';
            $html .= '<h2>' . esc_html($state_labels[$state]) . '</h2>';
            $html .= '<table>';
            $html .= '<thead><tr>';
            $html .= '<th>Plugin Name</th>';
            $html .= '<th>Version Info</th>';
            $html .= '<th>State</th>';
            $html .= '<th>Changed At</th>';
            $html .= '</tr></thead>';
            $html .= '<tbody>';
            
            foreach ($grouped[$state] as $record) {
                $html .= '<tr class="state-' . esc_attr($state) . '">';
                $html .= '<td>' . esc_html($record->name) . '</td>';
                $html .= '<td>';
                
                if ($state === 'installed') {
                    $html .= '<span class="vt-version">' . esc_html($record->new_version) . '</span>';
                } elseif ($state === 'updated') {
                    $html .= '<span class="vt-old-version">' . esc_html($record->old_version) . '</span>';
                    $html .= '<span class="vt-arrow">→</span>';
                    $html .= '<span class="vt-new-version">' . esc_html($record->new_version) . '</span>';
                } elseif ($state === 'deleted') {
                    $html .= '<span class="vt-old-version">' . esc_html($record->old_version) . '</span>';
                }
                
                $html .= '</td>';
                $html .= '<td><span class="vt-state-badge state-' . esc_attr($state) . '">' . esc_html(ucfirst($state)) . '</span></td>';
                $html .= '<td>' . esc_html(date_i18n('Y-m-d H:i:s', strtotime($record->created_at))) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody>';
            $html .= '</table>';
            $html .= '</div>';
        }
    }
    
    $html .= '</div>';
    
    return $html;
}

function version_tracker_generate_report_html($checkpoint_id) {
    $available_updates = get_plugins_with_available_updates();
    $available_updates_html = version_tracker_generate_available_updates_table_html($available_updates);
    
    $grouped = version_tracker_get_grouped_plugin_changes($checkpoint_id);
    $changes_html = version_tracker_generate_table_html($grouped);
    
    return $available_updates_html . $changes_html;
}

function version_tracker_send_report_email($checkpoint_id, $recipient_emails) {
    if (!is_array($recipient_emails)) {
        $recipient_emails = [$recipient_emails];
    }
    
    $recipient_emails = array_filter($recipient_emails, function($email) {
        return is_email($email);
    });
    
    if (empty($recipient_emails)) {
        return [
            'success' => false,
            'error' => 'No valid recipient emails provided',
            'sent_to' => []
        ];
    }
    
    $site_name = get_bloginfo('name');
    $subject = sprintf('[%s] Plugin Update Report', $site_name);
    
    $report_html = version_tracker_generate_report_html($checkpoint_id);
    $embedded_styles = version_tracker_get_embedded_styles();
    
    $message = version_tracker_build_report_email_body($site_name, $report_html, $embedded_styles);
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    
    $sent_to = [];
    $failed_emails = [];
    
    foreach ($recipient_emails as $recipient_email) {
        $result = wp_mail($recipient_email, $subject, $message, $headers);
        
        if ($result) {
            $sent_to[] = $recipient_email;
        } else {
            $failed_emails[] = $recipient_email;
        }
    }
    
    if (!empty($sent_to)) {
        return [
            'success' => true,
            'sent_to' => $sent_to,
            'failed' => $failed_emails
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Failed to send emails to all recipients',
            'sent_to' => [],
            'failed' => $failed_emails
        ];
    }
}

function version_tracker_build_report_email_body($site_name, $report_html, $embedded_styles) {
    $message = '<!DOCTYPE html>';
    $message .= '<html><head><meta charset="UTF-8"><title>Plugin Update Report</title>';
    $message .= $embedded_styles;
    $message .= '</head>';
    $message .= '<body style="font-family: Arial, sans-serif; color: #333; background-color: #f9f9f9;">';
    $message .= '<div style="max-width: 600px; margin: 20px auto; padding: 20px; background-color: #ffffff; border: 1px solid #ddd; border-radius: 4px;">';
    $message .= '<h1 style="margin-top: 0; color: #0073aa;">Plugin Update Report</h1>';
    $message .= '<p style="margin: 10px 0; color: #666;">Site: <strong>' . esc_html($site_name) . '</strong></p>';
    $message .= '<p style="margin: 10px 0; color: #666;">Report generated on: <strong>' . esc_html(date_i18n('Y-m-d H:i:s', current_time('timestamp'))) . '</strong></p>';
    $message .= '<hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">';
    $message .= $report_html;
    $message .= '<hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">';
    $message .= '<p style="margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 12px; color: #999;">This is an automated email from the Version Tracker plugin, developed by <a href="https://webshop.tech" target="_blank">webshop.tech</a>.</p>';
    $message .= '</div>';
    $message .= '</body></html>';
    
    return $message;
}