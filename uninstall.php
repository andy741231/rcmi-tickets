<?php
/**
 * RCMI Tickets uninstall handler.
 *
 * Destructive cleanup is opt-in. Define RCMI_TICKETS_ALLOW_UNINSTALL as true
 * in wp-config.php before deleting the plugin if all plugin-owned data should
 * be removed. A normal deletion preserves data; users and pages are always kept.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!defined('RCMI_TICKETS_ALLOW_UNINSTALL') || true !== RCMI_TICKETS_ALLOW_UNINSTALL) {
    return;
}

require_once __DIR__ . '/includes/class-data-lifecycle.php';

global $wpdb;
$manifest = rcmi_tickets_data_manifest();

foreach ($manifest['tables'] as $table_suffix) {
    $table = $wpdb->prefix . $table_suffix;
    // Table names are generated from the trusted WordPress prefix and fixed
    // suffixes above; values cannot be parameterized in DROP TABLE SQL.
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

foreach ($manifest['roles'] as $role) {
    remove_role($role);
}

$admin = get_role('administrator');
if ($admin) {
    foreach ($manifest['capabilities'] as $cap) {
        $admin->remove_cap($cap);
    }
}

foreach ($manifest['options'] as $option) {
    delete_option($option);
}

foreach ($manifest['transients'] as $transient) {
    delete_transient($transient);
}

foreach ($manifest['transient_prefixes'] as $prefix) {
    $value_pattern = $wpdb->esc_like('_transient_' . $prefix) . '%';
    $timeout_pattern = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $value_pattern,
        $timeout_pattern
    ));
}

$upload_root = trailingslashit(WP_CONTENT_DIR) . $manifest['upload_directory'];
if (is_dir($upload_root)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($upload_root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
        } elseif ($item->isDir()) {
            @rmdir($item->getPathname());
        }
    }
    @rmdir($upload_root);
}
