<?php
/**
 * REST API: GET /meta — bootstrap endpoint for the SPA frontend (§5).
 *
 * Returns statuses, priorities, current user info, capabilities, tags,
 * and assignable users in a single round-trip.
 */

if (!defined('ABSPATH')) {
    exit;
}

function rcmi_tickets_register_meta_route() {
    register_rest_route('rcmi/v1', '/meta', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_meta',
            'permission_callback' => 'rcmi_tickets_perm_meta',
        ],
    ]);
}
add_action('rest_api_init', 'rcmi_tickets_register_meta_route');

function rcmi_tickets_perm_meta() {
    return rcmi_tickets_can(get_current_user_id(), 'view_any');
}

function rcmi_tickets_handle_meta() {
    global $wpdb;
    $user_id = get_current_user_id();
    $user = get_userdata($user_id);

    // Tags
    $tag_rows = $wpdb->get_results(
        "SELECT id, name, slug FROM {$wpdb->prefix}rcmi_ticket_tags ORDER BY name"
    , ARRAY_A);
    $tags = array_map(function ($r) {
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'slug' => $r['slug']];
    }, $tag_rows);

    // Assignable users: all ticket users + managers + admins
    $assignable_ids = [];
    foreach (['rcmi_ticket_user', 'rcmi_ticket_manager', 'administrator'] as $role) {
        $query = new WP_User_Query(['role' => $role, 'fields' => 'ID', 'number' => -1]);
        $assignable_ids = array_merge($assignable_ids, array_map('intval', $query->get_results()));
    }
    $assignable_ids = array_values(array_unique($assignable_ids));

    $assignable_users = [];
    foreach ($assignable_ids as $uid) {
        $u = get_userdata($uid);
        if (!$u) continue;
        $assignable_users[] = [
            'id'           => (int) $uid,
            'display_name' => $u->display_name,
            'user_login'   => $u->user_login,
            'user_email'   => $u->user_email,
        ];
    }

    // Current user capabilities
    $caps = [
        'manage'  => rcmi_tickets_can($user_id, 'manage'),
        'create'  => rcmi_tickets_can($user_id, 'create'),
        'view'    => rcmi_tickets_can($user_id, 'view_any'),
        'pin'     => rcmi_tickets_can($user_id, 'pin'),
    ];

    return new WP_REST_Response([
        'statuses'  => rcmi_tickets_valid_statuses(),
        'priorities' => rcmi_tickets_valid_priorities(),
        'current_user' => [
            'id'           => (int) $user_id,
            'display_name' => $user ? $user->display_name : '',
            'user_login'   => $user ? $user->user_login : '',
            'user_email'   => $user ? $user->user_email : '',
        ],
        'caps'             => $caps,
        'tags'             => $tags,
        'assignable_users' => $assignable_users,
    ], 200);
}
