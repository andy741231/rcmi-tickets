<?php
/**
 * Admin settings page for RCMI Tickets.
 *
 * Adds a "Tickets" submenu under the Tickets top-level menu showing:
 *   - Setup status (whether the [rcmi_tickets] shortcode page exists)
 *   - A one-click "Create Tickets Page" button
 *   - Current shortcode page link
 *   - Database schema version
 *   - GitHub update status + "Check for updates" button
 *
 * Also adds:
 *   - A "Check for updates" action link on the Plugins page row
 *   - A "Settings" action link on the Plugins page row
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================
// Form handling — must run on admin_init (before any HTML output)
// so wp_safe_redirect() doesn't hit "headers already sent".
// ============================================================

/**
 * Handle "Create Tickets Page" and "Recreate Tickets Page" form
 * submissions. Runs on admin_init so the redirect happens before
 WordPress sends the admin page header.
 */
function rcmi_tickets_handle_page_form() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Create page
    if (isset($_POST['rcmi_tickets_create_page']) && check_admin_referer('rcmi_tickets_create_page')) {
        $page_id = wp_insert_post([
            'post_title'   => __('Tickets', 'rcmi-tickets'),
            'post_content' => '[rcmi_tickets]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_name'    => 'tickets',
        ]);

        if (!is_wp_error($page_id)) {
            delete_transient('rcmi_tickets_app_url');
            wp_safe_redirect(add_query_arg('rcmi_tickets_page_created', $page_id, admin_url('admin.php?page=rcmi-tickets')));
            exit;
        }

        // Error — redirect back with error message in query arg
        wp_safe_redirect(add_query_arg('rcmi_tickets_page_error', urlencode($page_id->get_error_message()), admin_url('admin.php?page=rcmi-tickets')));
        exit;
    }

    // Recreate page (delete old + create new)
    if (isset($_POST['rcmi_tickets_recreate_page']) && check_admin_referer('rcmi_tickets_recreate_page')) {
        $old_id = absint($_POST['rcmi_tickets_old_page_id']);
        if ($old_id) {
            wp_delete_post($old_id, true);
            delete_transient('rcmi_tickets_app_url');
        }
        $page_id = wp_insert_post([
            'post_title'   => __('Tickets', 'rcmi-tickets'),
            'post_content' => '[rcmi_tickets]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_name'    => 'tickets',
        ]);

        if (!is_wp_error($page_id)) {
            delete_transient('rcmi_tickets_app_url');
            wp_safe_redirect(add_query_arg('rcmi_tickets_page_created', $page_id, admin_url('admin.php?page=rcmi-tickets')));
            exit;
        }

        wp_safe_redirect(add_query_arg('rcmi_tickets_page_error', urlencode($page_id->get_error_message()), admin_url('admin.php?page=rcmi-tickets')));
        exit;
    }
}
add_action('admin_init', 'rcmi_tickets_handle_page_form');

// ============================================================
// Admin menu
// ============================================================

add_action('admin_menu', function () {
    add_menu_page(
        __('RCMI Tickets', 'rcmi-tickets'),
        __('Tickets', 'rcmi-tickets'),
        'manage_options',
        'rcmi-tickets',
        'rcmi_tickets_render_settings_page',
        'dashicons-tickets-alt',
        30
    );
});

// ============================================================
// Settings page render
// ============================================================

function rcmi_tickets_render_settings_page() {
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'setup';
    $shortcode_page_id = rcmi_tickets_find_shortcode_page();
    $created = isset($_GET['rcmi_tickets_page_created']) ? absint($_GET['rcmi_tickets_page_created']) : 0;
    $page_error = isset($_GET['rcmi_tickets_page_error']) ? urldecode(sanitize_text_field($_GET['rcmi_tickets_page_error'])) : '';

    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-tickets-alt" style="font-size:28px;width:28px;height:28px;vertical-align:middle;color:#C8102E;"></span> <?php esc_html_e('RCMI Tickets', 'rcmi-tickets'); ?></h1>

        <?php if ($created): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo wp_kses_post(sprintf(
                    /* translators: %s: edit page URL */
                    __('Tickets page created successfully! <a href="%s">View it</a> or <a href="%s">edit it</a>.', 'rcmi-tickets'),
                    esc_url(get_permalink($created)),
                    esc_url(admin_url('post.php?post=' . $created . '&action=edit'))
                )); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($page_error): ?>
            <div class="notice notice-error is-dismissible">
                <p><strong><?php esc_html_e('Error creating tickets page:', 'rcmi-tickets'); ?></strong> <?php echo esc_html($page_error); ?></p>
            </div>
        <?php endif; ?>

        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url(admin_url('admin.php?page=rcmi-tickets&tab=setup')); ?>" class="nav-tab <?php echo $tab === 'setup' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Setup', 'rcmi-tickets'); ?></a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=rcmi-tickets&tab=info')); ?>" class="nav-tab <?php echo $tab === 'info' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Info & Updates', 'rcmi-tickets'); ?></a>
        </h2>

        <?php if ($tab === 'setup'): ?>
            <?php rcmi_tickets_render_setup_tab($shortcode_page_id); ?>
        <?php else: ?>
            <?php rcmi_tickets_render_info_tab(); ?>
        <?php endif; ?>
    </div>
    <?php
}

// ============================================================
// Setup tab
// ============================================================

function rcmi_tickets_render_setup_tab($shortcode_page_id) {
    ?>
    <div style="max-width:800px;margin-top:20px;">

        <?php if ($shortcode_page_id): ?>
            <!-- Shortcode page exists -->
            <div class="card" style="max-width:none;border-left:4px solid #00B388;">
                <h2 class="title" style="color:#00B388;"><?php esc_html_e('Setup Complete', 'rcmi-tickets'); ?></h2>
                <p><?php esc_html_e('The ticket system is ready. A page with the [rcmi_tickets] shortcode has been found.', 'rcmi-tickets'); ?></p>
                <p>
                    <strong><?php esc_html_e('Page:', 'rcmi-tickets'); ?></strong>
                    <a href="<?php echo esc_url(get_permalink($shortcode_page_id)); ?>" target="_blank"><?php echo esc_html(get_the_title($shortcode_page_id)); ?></a>
                    &nbsp;&nbsp;
                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $shortcode_page_id . '&action=edit')); ?>" class="button button-small"><?php esc_html_e('Edit page', 'rcmi-tickets'); ?></a>
                </p>
                <p style="color:#666;font-size:13px;">
                    <?php esc_html_e('Shortcode used:', 'rcmi-tickets'); ?>
                    <code>[rcmi_tickets]</code>
                </p>
            </div>

            <h3><?php esc_html_e('How it works', 'rcmi-tickets'); ?></h3>
            <ul style="list-style:disc;padding-left:20px;">
                <li><?php echo wp_kses_post(__('Users visit the tickets page and sign in with their WordPress account.', 'rcmi-tickets')); ?></li>
                <li><?php echo wp_kses_post(__('Users with the <strong>Ticket User</strong> role can view and create tickets.', 'rcmi-tickets')); ?></li>
                <li><?php echo wp_kses_post(__('Users with the <strong>Ticket Manager</strong> role can manage all tickets, change statuses, and assign tickets.', 'rcmi-tickets')); ?></li>
                <li><?php echo wp_kses_post(__('Administrators automatically have all ticket management capabilities.', 'rcmi-tickets')); ?></li>
            </ul>

            <h3><?php esc_html_e('Test users', 'rcmi-tickets'); ?></h3>
            <p style="color:#666;font-size:13px;"><?php esc_html_e('Create test users with these roles to try the system:', 'rcmi-tickets'); ?></p>
            <table class="widefat" style="max-width:600px;">
                <thead>
                    <tr><th><?php esc_html_e('Role', 'rcmi-tickets'); ?></th><th><?php esc_html_e('Capabilities', 'rcmi-tickets'); ?></th></tr>
                </thead>
                <tbody>
                    <tr><td><code>rcmi_ticket_user</code></td><td><?php esc_html_e('View & create tickets', 'rcmi-tickets'); ?></td></tr>
                    <tr><td><code>rcmi_ticket_manager</code></td><td><?php esc_html_e('View, create, manage, assign, change status', 'rcmi-tickets'); ?></td></tr>
                </tbody>
            </table>

        <?php else: ?>
            <!-- No shortcode page found -->
            <div class="card" style="max-width:none;border-left:4px solid #C8102E;">
                <h2 class="title" style="color:#C8102E;"><?php esc_html_e('Setup Required', 'rcmi-tickets'); ?></h2>
                <p><?php echo wp_kses_post(__('No page with the <code>[rcmi_tickets]</code> shortcode was found. The ticket system needs a page to display on.', 'rcmi-tickets')); ?></p>

                <form method="post" action="">
                    <?php wp_nonce_field('rcmi_tickets_create_page'); ?>
                    <p>
                        <button type="submit" name="rcmi_tickets_create_page" class="button button-primary button-large">
                            <span class="dashicons dashicons-plus-alt" style="vertical-align:middle;margin-right:4px;"></span>
                            <?php esc_html_e('Create Tickets Page Automatically', 'rcmi-tickets'); ?>
                        </button>
                    </p>
                    <p style="color:#666;font-size:13px;">
                        <?php esc_html_e('This will create a published page titled "Tickets" with the [rcmi_tickets] shortcode.', 'rcmi-tickets'); ?>
                    </p>
                </form>

                <hr style="border:none;border-top:1px solid #ddd;margin:20px 0;">

                <h3><?php esc_html_e('Or create it manually', 'rcmi-tickets'); ?></h3>
                <ol style="list-style:decimal;padding-left:20px;">
                    <li><?php echo wp_kses_post(__('Go to <a href="' . esc_url(admin_url('post-new.php?post_type=page')) . '">Pages &rarr; Add New</a>', 'rcmi-tickets')); ?></li>
                    <li><?php echo wp_kses_post(__('Title the page (e.g. "Tickets")', 'rcmi-tickets')); ?></li>
                    <li><?php echo wp_kses_post(__('Add this shortcode to the page content: <code>[rcmi_tickets]</code>', 'rcmi-tickets')); ?></li>
                    <li><?php echo wp_kses_post(__('Publish the page', 'rcmi-tickets')); ?></li>
                </ol>
            </div>
        <?php endif; ?>

    </div>
    <?php
}

// ============================================================
// Info & Updates tab
// ============================================================

function rcmi_tickets_render_info_tab() {
    $db_version = get_option('rcmi_tickets_db_version', '0');
    $installed_sha = get_option('rcmi_tickets_installed_sha');
    $commit = rcmi_tickets_get_github_commit();
    $update_available = $commit && $commit['sha'] !== ($installed_sha ?: RCMI_TICKETS_VERSION);

    // Count tickets
    global $wpdb;
    $ticket_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rcmi_tickets");

    // Count users by role
    $user_count = (int) (new WP_User_Query(['role' => 'rcmi_ticket_user', 'fields' => 'ID', 'number' => -1]))->get_total();
    $manager_count = (int) (new WP_User_Query(['role' => 'rcmi_ticket_manager', 'fields' => 'ID', 'number' => -1]))->get_total();

    ?>
    <div style="max-width:800px;margin-top:20px;">

        <h2><?php esc_html_e('System Information', 'rcmi-tickets'); ?></h2>
        <table class="widefat" style="max-width:600px;">
            <tbody>
                <tr><td><strong><?php esc_html_e('Plugin version', 'rcmi-tickets'); ?></strong></td><td><code><?php echo esc_html(RCMI_TICKETS_VERSION); ?></code></td></tr>
                <tr><td><strong><?php esc_html_e('Database schema', 'rcmi-tickets'); ?></strong></td><td><code>v<?php echo esc_html($db_version); ?></code></td></tr>
                <tr><td><strong><?php esc_html_e('PHP version', 'rcmi-tickets'); ?></strong></td><td><code><?php echo esc_html(PHP_VERSION); ?></code></td></tr>
                <tr><td><strong><?php esc_html_e('Tickets in database', 'rcmi-tickets'); ?></strong></td><td><?php echo (int) $ticket_count; ?></td></tr>
                <tr><td><strong><?php esc_html_e('Ticket users', 'rcmi-tickets'); ?></strong></td><td><?php echo (int) $user_count; ?></td></tr>
                <tr><td><strong><?php esc_html_e('Ticket managers', 'rcmi-tickets'); ?></strong></td><td><?php echo (int) $manager_count; ?></td></tr>
            </tbody>
        </table>

        <h2 style="margin-top:30px;"><?php esc_html_e('GitHub Updates', 'rcmi-tickets'); ?></h2>
        <table class="widefat" style="max-width:600px;">
            <tbody>
                <tr>
                    <td><strong><?php esc_html_e('Repository', 'rcmi-tickets'); ?></strong></td>
                    <td><a href="https://github.com/<?php echo esc_attr(RCMI_TICKETS_GITHUB_USER); ?>/<?php echo esc_attr(RCMI_TICKETS_GITHUB_REPO); ?>" target="_blank"><?php echo esc_html(RCMI_TICKETS_GITHUB_USER . '/' . RCMI_TICKETS_GITHUB_REPO); ?></a></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Installed commit', 'rcmi-tickets'); ?></strong></td>
                    <td><code><?php echo esc_html($installed_sha ? substr($installed_sha, 0, 7) : RCMI_TICKETS_VERSION); ?></code></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Latest commit', 'rcmi-tickets'); ?></strong></td>
                    <td>
                        <?php if ($commit): ?>
                            <code><?php echo esc_html($commit['short_sha']); ?></code>
                            <br><small style="color:#666;"><?php echo esc_html($commit['message']); ?></small>
                        <?php else: ?>
                            <em><?php esc_html_e('Unable to fetch (check your connection)', 'rcmi-tickets'); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Update status', 'rcmi-tickets'); ?></strong></td>
                    <td>
                        <?php if ($update_available): ?>
                            <span style="color:#C8102E;font-weight:600;">&#9888; <?php esc_html_e('Update available', 'rcmi-tickets'); ?></span>
                        <?php else: ?>
                            <span style="color:#00B388;font-weight:600;">&#10003; <?php esc_html_e('Up to date', 'rcmi-tickets'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <p style="margin-top:15px;">
            <a href="<?php echo esc_url(add_query_arg('rcmi_tickets_check_updates', '1', admin_url('admin.php?page=rcmi-tickets&tab=info'))); ?>" class="button button-secondary">
                <span class="dashicons dashicons-update" style="vertical-align:middle;margin-right:4px;"></span>
                <?php esc_html_e('Check for updates', 'rcmi-tickets'); ?>
            </a>
            <?php if ($update_available): ?>
                <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" class="button button-primary">
                    <?php esc_html_e('Go to Plugins to update', 'rcmi-tickets'); ?>
                </a>
            <?php endif; ?>
        </p>

        <?php if (isset($_GET['rcmi_tickets_checked'])): ?>
            <div class="notice notice-success is-dismissible" style="margin-top:15px;">
                <p><?php esc_html_e('Update check completed. See status above.', 'rcmi-tickets'); ?></p>
            </div>
        <?php endif; ?>

    </div>
    <?php
}

// ============================================================
// Helpers
// ============================================================

/**
 * Find the page ID that contains the [rcmi_tickets] shortcode.
 *
 * @return int Page ID, or 0 if not found.
 */
function rcmi_tickets_find_shortcode_page() {
    $candidates = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        's'              => '[rcmi_tickets]',
        'posts_per_page' => 5,
        'fields'         => 'ids',
    ]);

    foreach ($candidates as $page_id) {
        if (has_shortcode(get_post($page_id)->post_content, 'rcmi_tickets')) {
            return (int) $page_id;
        }
    }

    return 0;
}

// ============================================================
// Plugin action links (Plugins page row)
// ============================================================

/**
 * Add "Settings" and "Check for updates" links to the plugin's
 * action row on the Plugins page.
 */
function rcmi_tickets_add_action_links($links, $file) {
    if (plugin_basename(RCMI_TICKETS_FILE) !== $file) {
        return $links;
    }

    // Settings link
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=rcmi-tickets')) . '">' . __('Settings', 'rcmi-tickets') . '</a>';

    // Check for updates link
    $check_url = add_query_arg('rcmi_tickets_check_updates', '1', admin_url('plugins.php'));
    $check_link = '<a href="' . esc_url($check_url) . '">' . __('Check for updates', 'rcmi-tickets') . '</a>';

    // Prepend so they appear first
    array_unshift($links, $check_link);
    array_unshift($links, $settings_link);

    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(RCMI_TICKETS_FILE), 'rcmi_tickets_add_action_links', 10, 2);
