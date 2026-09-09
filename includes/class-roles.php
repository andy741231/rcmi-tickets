<?php
/**
 * Role and capability registration for RCMI Tickets.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * All custom capabilities used by the ticket system.
 */
function rcmi_tickets_all_caps() {
    return ['rcmi_view_tickets', 'rcmi_create_tickets', 'rcmi_manage_tickets'];
}

/**
 * Register ticket roles and grant caps to administrators.
 * Called on plugin activation.
 */
function rcmi_tickets_register_roles() {
    add_role('rcmi_ticket_user', __('Ticket User', 'rcmi-tickets'), [
        'read'               => true,
        'rcmi_view_tickets'  => true,
        'rcmi_create_tickets' => true,
    ]);

    add_role('rcmi_ticket_manager', __('Ticket Manager', 'rcmi-tickets'), [
        'read'                => true,
        'rcmi_view_tickets'   => true,
        'rcmi_create_tickets' => true,
        'rcmi_manage_tickets' => true,
    ]);

    $admin = get_role('administrator');
    if ($admin) {
        foreach (rcmi_tickets_all_caps() as $cap) {
            $admin->add_cap($cap);
        }
    }
}

/**
 * Remove ticket roles and caps. Called on plugin deactivation.
 */
function rcmi_tickets_remove_roles() {
    remove_role('rcmi_ticket_user');
    remove_role('rcmi_ticket_manager');

    $admin = get_role('administrator');
    if ($admin) {
        foreach (rcmi_tickets_all_caps() as $cap) {
            $admin->remove_cap($cap);
        }
    }
}

/**
 * Auto-grant the Ticket User role on SSO login. Policy: every UH account
 * that authenticates through OpenID Connect becomes a ticket user.
 * Content roles (administrators, editors) and users who already hold a
 * ticket role (e.g. manually-assigned managers) are left untouched, so
 * manual role decisions are never overridden.
 */
add_action('openid-connect-generic-user-logged-in', 'rcmi_tickets_sso_grant_ticket_user');

function rcmi_tickets_sso_grant_ticket_user($user) {
    if (!$user instanceof WP_User || !$user->ID) {
        return;
    }
    if ($user->has_cap('manage_options') || $user->has_cap('edit_posts')) {
        return;
    }
    if (array_intersect(['rcmi_ticket_user', 'rcmi_ticket_manager'], (array) $user->roles)) {
        return;
    }
    $user->add_role('rcmi_ticket_user');
}

/**
 * Keep pure ticket users out of wp-admin. Users who also have content
 * capabilities (editors, admins) are untouched so the marketing site
 * backend keeps working for them.
 */
add_action('admin_init', function () {
    if (wp_doing_ajax() || current_user_can('manage_options') || current_user_can('edit_posts')) {
        return;
    }

    $user = wp_get_current_user();
    $ticket_roles = ['rcmi_ticket_user', 'rcmi_ticket_manager'];
    if (array_intersect($ticket_roles, (array) $user->roles)) {
        wp_redirect(rcmi_tickets_get_app_url());
        exit;
    }
});

/**
 * URL of the page hosting the [rcmi_tickets] shortcode. Works with any
 * permalink structure (including plain permalinks). Cached in a transient,
 * invalidated when a page is saved.
 */
function rcmi_tickets_get_app_url() {
    $cached = get_transient('rcmi_tickets_app_url');
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $url = '';
    $candidates = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        's'              => '[rcmi_tickets]',
        'posts_per_page' => 5,
        'fields'         => 'ids',
    ]);

    foreach ($candidates as $page_id) {
        if (has_shortcode(get_post($page_id)->post_content, 'rcmi_tickets')) {
            $url = get_permalink($page_id);
            break;
        }
    }

    set_transient('rcmi_tickets_app_url', $url ?: home_url('/'), HOUR_IN_SECONDS);
    return $url ?: home_url('/');
}

add_action('save_post_page', function () {
    delete_transient('rcmi_tickets_app_url');
});
