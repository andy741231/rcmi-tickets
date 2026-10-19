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
    if (!array_intersect(['rcmi_ticket_user', 'rcmi_ticket_manager'], (array) $user->roles)) {
        $user->add_role('rcmi_ticket_user');
    }
    rcmi_tickets_remove_default_role($user);
}

/**
 * Drop the site's auto-assigned default role (usually Subscriber) once a
 * ticket role is in place — wp_insert_user adds it at creation, but the
 * ticket user role already carries the 'read' cap it provides.
 */
function rcmi_tickets_remove_default_role($user) {
    $default_role = get_option('default_role', 'subscriber');
    if ($default_role && $default_role !== 'rcmi_ticket_user' && in_array($default_role, (array) $user->roles, true)) {
        $user->remove_role($default_role);
    }
}

/**
 * Create SSO users with the ticket role directly so they never receive
 * the default role in the first place. Only applies when nothing else
 * has set a role on the pending user.
 */
add_filter('openid-connect-generic-alter-user-data', function ($user_data, $user_claim) {
    if (empty($user_data['role'])) {
        $user_data['role'] = 'rcmi_ticket_user';
    }
    return $user_data;
}, 10, 2);

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

/**
 * Request the Graph `User.Read` scope during SSO so the access token can
 * read the Entra profile. Injected via the plugin's settings filter so it
 * works regardless of whether the scope comes from DB settings or
 * wp-config constants — jobTitle/department are not emittable token claims.
 */
add_filter('openid-connect-generic-settings', function ($settings) {
    $scope = (string) ($settings->scope ?? '');
    if (!preg_match('/\bUser\.Read\b/i', $scope)) {
        $settings->scope = trim($scope . ' User.Read');
    }
    return $settings;
});

/**
 * Sync Entra job info (jobTitle, department, …) into user meta on SSO
 * login. Reads the Graph access token the plugin stores per login; if the
 * token lacks User.Read, the call fails silently and login proceeds.
 */
add_action('openid-connect-generic-user-logged-in', 'rcmi_tickets_sso_sync_job_info', 20);

function rcmi_tickets_sso_sync_job_info($user) {
    if (!$user instanceof WP_User || !$user->ID) {
        return;
    }
    $token_response = get_user_option('openid-connect-generic-last-token-response', $user->ID);
    $access_token = is_array($token_response) ? ($token_response['access_token'] ?? '') : '';
    if (!$access_token) {
        return;
    }

    $response = wp_remote_get(
        'https://graph.microsoft.com/v1.0/me?$select=jobTitle,department,companyName,employeeId,officeLocation,businessPhones',
        [
            'headers' => ['Authorization' => 'Bearer ' . $access_token],
            'timeout' => 5,
        ]
    );
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return;
    }
    $me = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($me)) {
        return;
    }

    $map = [
        'jobTitle'       => 'rcmi_job_title',
        'department'     => 'rcmi_department',
        'companyName'    => 'rcmi_company_name',
        'employeeId'     => 'rcmi_employee_id',
        'officeLocation' => 'rcmi_office_location',
    ];
    foreach ($map as $field => $meta_key) {
        if (!empty($me[$field]) && is_string($me[$field])) {
            update_user_meta($user->ID, $meta_key, sanitize_text_field($me[$field]));
        } else {
            delete_user_meta($user->ID, $meta_key);
        }
    }

    // businessPhones is an array — use the first entry as the contact number.
    $phone = !empty($me['businessPhones']) && is_array($me['businessPhones'])
        ? (string) reset($me['businessPhones'])
        : '';
    if ($phone !== '') {
        update_user_meta($user->ID, 'rcmi_phone', sanitize_text_field($phone));
    } else {
        delete_user_meta($user->ID, 'rcmi_phone');
    }
}
