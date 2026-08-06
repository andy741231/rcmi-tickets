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

    // Schema v3: form fields, approval chains, allowed mime types, pending approval count
    $form_fields = rcmi_tickets_get_all_form_fields();
    $approval_chains = rcmi_tickets_get_all_approval_chains();
    $allowed_mime = array_keys(rcmi_tickets_allowed_mime_types());

    // Count tickets pending current user's approval
    $pending_approval_count = 0;
    $pending_rows = $wpdb->get_results(
        "SELECT ta.approver_user_id, ta.approver_role
         FROM {$wpdb->prefix}rcmi_ticket_approvals ta
         INNER JOIN {$wpdb->prefix}rcmi_tickets t ON t.id = ta.ticket_id
         WHERE ta.status = 'pending' AND t.status = 'Pending Approval'"
    , ARRAY_A);
    $user_obj = get_userdata($user_id);
    $user_roles = $user_obj ? (array) $user_obj->roles : [];
    foreach ($pending_rows as $r) {
        if ($r['approver_user_id'] !== null && (int) $r['approver_user_id'] === $user_id) {
            $pending_approval_count++;
        } elseif (!empty($r['approver_role']) && in_array($r['approver_role'], $user_roles, true)) {
            $pending_approval_count++;
        }
    }

    // Inbox summary counts (across all visible tickets, not just current page)
    $is_manager = rcmi_tickets_can($user_id, 'manage');
    $vis_where = $is_manager ? '' : "WHERE t.author_id = %d OR t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_assignees WHERE user_id = %d)";
    $vis_args = $is_manager ? [] : [$user_id, $user_id];
    $vis_clause = $is_manager ? '' : $wpdb->prepare($vis_where, $vis_args);

    $status_counts = [];
    $status_rows = $wpdb->get_results(
        "SELECT t.status, COUNT(*) as cnt FROM {$wpdb->prefix}rcmi_tickets t {$vis_clause} GROUP BY t.status"
    , ARRAY_A);
    foreach ($status_rows as $r) {
        $status_counts[$r['status']] = (int) $r['cnt'];
    }

    $today = current_time('Y-m-d');
    // Note: $vis_clause is already a fully-prepared string (values baked in),
    // so we must NOT pass $vis_args again to prepare() — only the %s for dates.
    $due_soon_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}rcmi_tickets t {$vis_clause}" . ($vis_clause ? " AND" : " WHERE") . " t.due_date IS NOT NULL AND t.due_date >= %s AND t.due_date <= DATE_ADD(%s, INTERVAL 7 DAY) AND t.status NOT IN ('Completed','Rejected')",
        $today, $today
    ));
    $overdue_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}rcmi_tickets t {$vis_clause}" . ($vis_clause ? " AND" : " WHERE") . " t.due_date IS NOT NULL AND t.due_date < %s AND t.status NOT IN ('Completed','Rejected')",
        $today
    ));
    $total_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rcmi_tickets t {$vis_clause}");

    $inbox_summary = [
        'total'            => $total_count,
        'received'         => $status_counts['Received'] ?? 0,
        'pending_approval' => $status_counts['Pending Approval'] ?? 0,
        'approved'         => $status_counts['Approved'] ?? 0,
        'rejected'         => $status_counts['Rejected'] ?? 0,
        'completed'        => $status_counts['Completed'] ?? 0,
        'due_soon'         => $due_soon_count,
        'overdue'          => $overdue_count,
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
        'form_fields'      => $form_fields,
        'approval_chains'  => $approval_chains,
        'allowed_mime_types' => $allowed_mime,
        'pending_approval_count' => $pending_approval_count,
        'inbox_summary'    => $inbox_summary,
    ], 200);
}
