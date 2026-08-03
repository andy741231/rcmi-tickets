<?php
/**
 * RCMI Tickets uninstall handler.
 *
 * Destructive cleanup is opt-in. Define RCMI_TICKETS_ALLOW_UNINSTALL as true
 * in wp-config.php before deleting the plugin if tables and roles should be
 * removed. A normal plugin deletion leaves data intact.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!defined('RCMI_TICKETS_ALLOW_UNINSTALL') || true !== RCMI_TICKETS_ALLOW_UNINSTALL) {
    return;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'rcmi_ticket_comment_reactions',
    $wpdb->prefix . 'rcmi_ticket_attachments',
    $wpdb->prefix . 'rcmi_ticket_comments',
    $wpdb->prefix . 'rcmi_ticket_tag_map',
    $wpdb->prefix . 'rcmi_ticket_tags',
    $wpdb->prefix . 'rcmi_ticket_assignees',
    $wpdb->prefix . 'rcmi_tickets',
];

foreach ($tables as $table) {
    // Table names are generated from the trusted WordPress prefix and fixed
    // suffixes above; values cannot be parameterized in DROP TABLE SQL.
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

remove_role('rcmi_ticket_user');
remove_role('rcmi_ticket_manager');

$admin = get_role('administrator');
if ($admin) {
    foreach (['rcmi_view_tickets', 'rcmi_create_tickets', 'rcmi_manage_tickets'] as $cap) {
        $admin->remove_cap($cap);
    }
}

$options = [
    'rcmi_tickets_installed_sha',
];
foreach ($options as $option) {
    delete_option($option);
}

delete_transient('rcmi_tickets_github_commit');
delete_transient('rcmi_tickets_app_url');
