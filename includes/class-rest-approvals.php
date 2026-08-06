<?php
/**
 * REST API controller for ticket approval actions (schema v3).
 *
 * Endpoints:
 *   POST /tickets/{id}/approve           — approve current step → advance or complete
 *   POST /tickets/{id}/reject            — reject current step → restart / back_one / terminal
 *   GET  /tickets/{id}/approvals         — approval timeline for a ticket
 *   GET  /approvals/pending              — tickets pending current user's approval
 *   POST /approvals/{id}/token-approve   — one-click token-based approve (from email link)
 *   POST /approvals/{id}/token-reject    — one-click token-based reject (from email link)
 *
 * Permission for approve/reject: current user must be the resolved approver
 * of the ticket's current pending step (user match OR role match).
 *
 * Token endpoints authenticate via the per-row token (single-use, 7-day expiry)
 * so approvers can act from an email link without logging in. Token approval
 * does NOT grant ticket view — viewing still requires login + view cap.
 */

if (!defined('ABSPATH')) {
    exit;
}

function rcmi_tickets_register_approval_routes() {
    $namespace = 'rcmi/v1';

    register_rest_route($namespace, '/tickets/(?P<id>\d+)/approve', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_ticket_approve',
            'permission_callback' => 'rcmi_tickets_perm_ticket_approve',
            'args'                => [
                'id'      => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'comment' => ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            ],
        ],
    ]);

    register_rest_route($namespace, '/tickets/(?P<id>\d+)/reject', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_ticket_reject',
            'permission_callback' => 'rcmi_tickets_perm_ticket_approve',
            'args'                => [
                'id'      => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'comment' => ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            ],
        ],
    ]);

    register_rest_route($namespace, '/tickets/(?P<id>\d+)/approvals', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_ticket_approvals',
            'permission_callback' => 'rcmi_tickets_perm_ticket_approvals_view',
            'args'                => ['id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int']],
        ],
    ]);

    register_rest_route($namespace, '/approvals/pending', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_pending_approvals',
            'permission_callback' => function () {
                return rcmi_tickets_can(get_current_user_id(), 'view_any');
            },
        ],
    ]);

    register_rest_route($namespace, '/approvals/(?P<id>\d+)/token-approve', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_token_approve',
            'permission_callback' => '__return_true', // authenticated by token inside callback
            'args'                => [
                'id'     => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'token'  => ['required' => true, 'type' => 'string'],
                'comment' => ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            ],
        ],
    ]);

    register_rest_route($namespace, '/approvals/(?P<id>\d+)/token-reject', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_token_reject',
            'permission_callback' => '__return_true',
            'args'                => [
                'id'     => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'token'  => ['required' => true, 'type' => 'string'],
                'comment' => ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            ],
        ],
    ]);
}
add_action('rest_api_init', 'rcmi_tickets_register_approval_routes');

// ── helpers ──────────────────────────────────────────────────────────

/**
 * Get all approval rows for a ticket, ordered by sort_order.
 */
function rcmi_tickets_get_ticket_approvals($ticket_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE ticket_id = %d ORDER BY cycle ASC, sort_order ASC, id ASC",
        (int) $ticket_id
    ), ARRAY_A);
    return array_map('rcmi_tickets_format_ticket_approval', $rows);
}

function rcmi_tickets_format_ticket_approval($row) {
    $approver_name = '';
    if ($row['approver_user_id']) {
        $u = get_userdata((int) $row['approver_user_id']);
        $approver_name = $u ? $u->display_name : '';
    }
    $decided_by_name = null;
    if ($row['decided_by']) {
        $du = get_userdata((int) $row['decided_by']);
        $decided_by_name = $du ? $du->display_name : null;
    }
    return [
        'id'               => (int) $row['id'],
        'ticket_id'        => (int) $row['ticket_id'],
        'chain_id'         => (int) $row['chain_id'],
        'step_id'          => (int) $row['step_id'],
        'sort_order'       => (int) $row['sort_order'],
        'cycle'            => (int) ($row['cycle'] ?? 1),
        'approver_user_id' => $row['approver_user_id'] !== null ? (int) $row['approver_user_id'] : null,
        'approver_role'    => $row['approver_role'],
        'approver_name'    => $approver_name,
        'status'           => $row['status'],
        'decided_at'       => $row['decided_at'],
        'decided_by'       => $row['decided_by'] !== null ? (int) $row['decided_by'] : null,
        'decided_by_name'  => $decided_by_name,
        'comment'          => $row['comment'],
    ];
}

/**
 * Get the current pending approval row for a ticket (the active step).
 */
function rcmi_tickets_get_pending_approval($ticket_id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_ticket_approvals
         WHERE ticket_id = %d AND status = 'pending'
         AND cycle = (SELECT MAX(cycle) FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE ticket_id = %d)
         ORDER BY sort_order ASC LIMIT 1",
        (int) $ticket_id, (int) $ticket_id
    ), ARRAY_A);
    return $row ?: null;
}

/**
 * Check whether a user can approve/reject the current pending step of a ticket.
 * Used by both permission_callback and the inline check.
 */
function rcmi_tickets_user_can_approve_ticket($user_id, $ticket_id) {
    $pending = rcmi_tickets_get_pending_approval($ticket_id);
    if (!$pending) {
        return false;
    }
    return rcmi_tickets_user_can_approve_row($user_id, $pending);
}

function rcmi_tickets_user_can_approve_row($user_id, $approval_row) {
    $user_id = (int) $user_id;
    if (!$user_id) {
        return false;
    }
    // Specific user match
    if ($approval_row['approver_user_id'] !== null && (int) $approval_row['approver_user_id'] === $user_id) {
        return true;
    }
    // Role-based: user has the role
    if (!empty($approval_row['approver_role'])) {
        $user = get_userdata($user_id);
        if ($user && in_array($approval_row['approver_role'], (array) $user->roles, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Check whether a user is an approver anywhere in the ticket's approval
 * chain (any step, not just the current pending one). This is used for
 * view permission so that future-step approvers can see the ticket
 * before it reaches their step.
 */
function rcmi_tickets_user_in_approval_chain($user_id, $ticket_id) {
    global $wpdb;
    $user_id = (int) $user_id;
    if (!$user_id) {
        return false;
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT approver_user_id, approver_role FROM {$wpdb->prefix}rcmi_ticket_approvals
         WHERE ticket_id = %d
         AND cycle = (SELECT MAX(cycle) FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE ticket_id = %d)",
        (int) $ticket_id, (int) $ticket_id
    ), ARRAY_A);

    if (!$rows) {
        return false;
    }

    $user = get_userdata($user_id);
    $user_roles = $user ? (array) $user->roles : [];

    foreach ($rows as $r) {
        if ($r['approver_user_id'] !== null && (int) $r['approver_user_id'] === $user_id) {
            return true;
        }
        if (!empty($r['approver_role']) && in_array($r['approver_role'], $user_roles, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Generate a fresh token + expiry for an approval row.
 */
function rcmi_tickets_generate_approval_token() {
    return [
        'token'   => wp_generate_password(64, false, false),
        'expires' => gmdate('Y-m-d H:i:s', time() + (7 * DAY_IN_SECONDS)),
    ];
}

/**
 * Reset an approval row to pending with a fresh token.
 */
function rcmi_tickets_reset_approval_to_pending($approval_id) {
    global $wpdb;
    $tok = rcmi_tickets_generate_approval_token();
    $wpdb->update(
        $wpdb->prefix . 'rcmi_ticket_approvals',
        [
            'status'        => 'pending',
            'decided_at'    => null,
            'decided_by'    => null,
            'comment'       => null,
            'token'         => $tok['token'],
            'token_expires' => $tok['expires'],
        ],
        ['id' => (int) $approval_id],
        ['%s', '%s', '%s', '%s', '%s', '%s'],
        ['%d']
    );
    return $tok;
}

// ── permission callbacks ─────────────────────────────────────────────

function rcmi_tickets_perm_ticket_approve($request) {
    $ticket = rcmi_tickets_load_ticket($request['id']);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    $uid = get_current_user_id();
    if (!rcmi_tickets_can($uid, 'view', $ticket)) {
        return false;
    }
    return rcmi_tickets_user_can_approve_ticket($uid, (int) $request['id']);
}

function rcmi_tickets_perm_ticket_approvals_view($request) {
    $ticket = rcmi_tickets_load_ticket($request['id']);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    return rcmi_tickets_can(get_current_user_id(), 'view', $ticket);
}

// ── handlers ─────────────────────────────────────────────────────────

function rcmi_tickets_handle_ticket_approve($request) {
    global $wpdb;
    $ticket_id = (int) $request['id'];
    $user_id = get_current_user_id();
    $comment = $request['comment'] ?? '';
    $now = current_time('mysql');

    $pending = rcmi_tickets_get_pending_approval($ticket_id);
    if (!$pending) {
        return new WP_Error('rcmi_tickets_no_pending', 'No pending approval step for this ticket.', ['status' => 409]);
    }

    // Mark current step approved
    $wpdb->update(
        $wpdb->prefix . 'rcmi_ticket_approvals',
        [
            'status'     => 'approved',
            'decided_at' => $now,
            'decided_by' => $user_id,
            'comment'    => $comment ?: null,
            'token'      => null, // invalidate token
        ],
        ['id' => (int) $pending['id']],
        ['%s', '%s', '%d', '%s', '%s'],
        ['%d']
    );

    // Find next pending step in the same cycle
    $next = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_ticket_approvals
         WHERE ticket_id = %d AND status = 'pending' AND cycle = %d
         ORDER BY sort_order ASC LIMIT 1",
        $ticket_id, (int) $pending['cycle']
    ), ARRAY_A);

    if ($next) {
        // Advance: notify next approver
        do_action('rcmi_ticket_approval_step', $ticket_id, (int) $next['id'], 'approve_advanced');
        return new WP_REST_Response([
            'approved_step' => (int) $pending['id'],
            'next_step'     => (int) $next['id'],
            'ticket_status' => 'Pending Approval',
        ], 200);
    }

    // No more steps → ticket Approved
    $wpdb->update(
        $wpdb->prefix . 'rcmi_tickets',
        ['status' => 'Approved', 'updated_at' => $now, 'updated_by' => $user_id],
        ['id' => $ticket_id],
        ['%s', '%s', '%d'],
        ['%d']
    );

    do_action('rcmi_ticket_status_changed', $ticket_id, 'Approved', 'Pending Approval', null);

    return new WP_REST_Response([
        'approved_step'  => (int) $pending['id'],
        'next_step'      => null,
        'ticket_status'  => 'Approved',
    ], 200);
}

function rcmi_tickets_handle_ticket_reject($request) {
    global $wpdb;
    $ticket_id = (int) $request['id'];
    $user_id = get_current_user_id();
    $comment = $request['comment'] ?? '';
    $now = current_time('mysql');

    $pending = rcmi_tickets_get_pending_approval($ticket_id);
    if (!$pending) {
        return new WP_Error('rcmi_tickets_no_pending', 'No pending approval step for this ticket.', ['status' => 409]);
    }

    // Load chain to get on_reject policy
    $chain = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_approval_chains WHERE id = %d",
        (int) $pending['chain_id']
    ), ARRAY_A);
    $on_reject = $chain['on_reject'] ?? 'restart';

    // Mark current step rejected
    $wpdb->update(
        $wpdb->prefix . 'rcmi_ticket_approvals',
        [
            'status'     => 'rejected',
            'decided_at' => $now,
            'decided_by' => $user_id,
            'comment'    => $comment ?: null,
            'token'      => null,
        ],
        ['id' => (int) $pending['id']],
        ['%s', '%s', '%d', '%s', '%s'],
        ['%d']
    );

    if ($on_reject === 'terminal') {
        // Hard-reject: ticket status Rejected, close all remaining pending steps in the same cycle
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}rcmi_ticket_approvals
             SET status = 'rejected', token = NULL
             WHERE ticket_id = %d AND status = 'pending' AND cycle = %d",
            $ticket_id, (int) $pending['cycle']
        ));
        $wpdb->update(
            $wpdb->prefix . 'rcmi_tickets',
            ['status' => 'Rejected', 'updated_at' => $now, 'updated_by' => $user_id],
            ['id' => $ticket_id],
            ['%s', '%s', '%d'],
            ['%d']
        );
        do_action('rcmi_ticket_status_changed', $ticket_id, 'Rejected', 'Pending Approval', $comment);
        do_action('rcmi_ticket_approval_rejected', $ticket_id, 'terminal', $comment);
        return new WP_REST_Response([
            'rejected_step' => (int) $pending['id'],
            'ticket_status' => 'Rejected',
            'on_reject'     => 'terminal',
        ], 200);
    }

    if ($on_reject === 'back_one') {
        // Move back one step: find the previous step in the same cycle
        $prev = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rcmi_ticket_approvals
             WHERE ticket_id = %d AND cycle = %d AND sort_order < %d
             ORDER BY sort_order DESC LIMIT 1",
            $ticket_id, (int) $pending['cycle'], (int) $pending['sort_order']
        ), ARRAY_A);

        if ($prev) {
            rcmi_tickets_reset_approval_to_pending($prev['id']);
            do_action('rcmi_ticket_approval_step', $ticket_id, (int) $prev['id'], 'reject_back_one');
            return new WP_REST_Response([
                'rejected_step'   => (int) $pending['id'],
                'reopened_step'   => (int) $prev['id'],
                'ticket_status'   => 'Pending Approval',
                'on_reject'       => 'back_one',
            ], 200);
        }
        // No previous step → fall through to restart semantics
    }

    // restart (or back_one with no previous step): reset to step 1
    // Only reset steps in the same cycle — old cycles are preserved as history
    $first = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_ticket_approvals
         WHERE ticket_id = %d AND cycle = %d ORDER BY sort_order ASC LIMIT 1",
        $ticket_id, (int) $pending['cycle']
    ), ARRAY_A);

    // Reset all steps in the same cycle EXCEPT the just-rejected one to pending with fresh tokens.
    // The rejected step keeps its status/comment as a historical record.
    $all = $wpdb->get_results($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE ticket_id = %d AND cycle = %d AND id != %d",
        $ticket_id, (int) $pending['cycle'], (int) $pending['id']
    ), ARRAY_A);
    foreach ($all as $r) {
        rcmi_tickets_reset_approval_to_pending($r['id']);
    }

    // Set ticket back to Received so author can edit/resubmit
    $wpdb->update(
        $wpdb->prefix . 'rcmi_tickets',
        ['status' => 'Received', 'updated_at' => $now, 'updated_by' => $user_id],
        ['id' => $ticket_id],
        ['%s', '%s', '%d'],
        ['%d']
    );

    do_action('rcmi_ticket_status_changed', $ticket_id, 'Received', 'Pending Approval', $comment);
    do_action('rcmi_ticket_approval_rejected', $ticket_id, 'restart', $comment);

    return new WP_REST_Response([
        'rejected_step' => (int) $pending['id'],
        'ticket_status' => 'Received',
        'on_reject'     => 'restart',
    ], 200);
}

function rcmi_tickets_handle_ticket_approvals($request) {
    $ticket_id = (int) $request['id'];
    return new WP_REST_Response(rcmi_tickets_get_ticket_approvals($ticket_id), 200);
}

function rcmi_tickets_handle_pending_approvals() {
    global $wpdb;
    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_REST_Response(['items' => []], 200);
    }

    // Find approval rows where this user is the approver AND status is pending
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ta.ticket_id, ta.id AS approval_id, ta.sort_order, ta.approver_user_id, ta.approver_role
         FROM {$wpdb->prefix}rcmi_ticket_approvals ta
         INNER JOIN {$wpdb->prefix}rcmi_tickets t ON t.id = ta.ticket_id
         WHERE ta.status = 'pending' AND t.status = 'Pending Approval'
         ORDER BY ta.ticket_id DESC",
    ), ARRAY_A);

    $user = get_userdata($user_id);
    $user_roles = $user ? (array) $user->roles : [];

    $items = [];
    foreach ($rows as $r) {
        $matches = false;
        if ($r['approver_user_id'] !== null && (int) $r['approver_user_id'] === $user_id) {
            $matches = true;
        } elseif (!empty($r['approver_role']) && in_array($r['approver_role'], $user_roles, true)) {
            $matches = true;
        }
        if (!$matches) {
            continue;
        }
        $ticket = rcmi_tickets_load_ticket($r['ticket_id']);
        if (!$ticket) {
            continue;
        }
        $formatted = rcmi_tickets_format_ticket($ticket);
        $formatted['pending_approval_id'] = (int) $r['approval_id'];
        $formatted['pending_step_order'] = (int) $r['sort_order'];
        $items[] = $formatted;
    }

    return new WP_REST_Response(['items' => $items], 200);
}

// ── token-based endpoints (email one-click links) ────────────────────

function rcmi_tickets_validate_approval_token($approval_id, $token) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE id = %d",
        (int) $approval_id
    ), ARRAY_A);
    if (!$row) {
        return new WP_Error('rcmi_tickets_approval_not_found', 'Approval record not found.', ['status' => 404]);
    }
    if (!$row['token'] || !hash_equals($row['token'], (string) $token)) {
        return new WP_Error('rcmi_tickets_bad_token', 'Invalid or missing token.', ['status' => 403]);
    }
    if ($row['status'] !== 'pending') {
        return new WP_Error('rcmi_tickets_already_decided', 'This step has already been decided.', ['status' => 409]);
    }
    if ($row['token_expires'] && strtotime($row['token_expires']) < time()) {
        return new WP_Error('rcmi_tickets_token_expired', 'Token has expired.', ['status' => 403]);
    }
    return $row;
}

function rcmi_tickets_handle_token_approve($request) {
    $approval_id = (int) $request['id'];
    $token = (string) $request['token'];
    $comment = $request['comment'] ?? '';

    $row = rcmi_tickets_validate_approval_token($approval_id, $token);
    if (is_wp_error($row)) {
        return $row;
    }

    // Resolve the acting user from the approver_user_id (token IS the auth)
    $acting_user_id = (int) ($row['approver_user_id'] ?: 0);
    if (!$acting_user_id) {
        // Role-based step: token-only approval not supported (can't pick a specific user).
        return new WP_Error('rcmi_tickets_role_step_no_token', 'Role-based steps require login to approve.', ['status' => 400]);
    }

    return rcmi_tickets_apply_approve($row, $acting_user_id, $comment);
}

function rcmi_tickets_handle_token_reject($request) {
    $approval_id = (int) $request['id'];
    $token = (string) $request['token'];
    $comment = $request['comment'] ?? '';

    $row = rcmi_tickets_validate_approval_token($approval_id, $token);
    if (is_wp_error($row)) {
        return $row;
    }

    $acting_user_id = (int) ($row['approver_user_id'] ?: 0);
    if (!$acting_user_id) {
        return new WP_Error('rcmi_tickets_role_step_no_token', 'Role-based steps require login to reject.', ['status' => 400]);
    }

    return rcmi_tickets_apply_reject($row, $acting_user_id, $comment);
}

/**
 * Shared approve logic used by both logged-in and token endpoints.
 */
function rcmi_tickets_apply_approve($pending, $user_id, $comment) {
    global $wpdb;
    $ticket_id = (int) $pending['ticket_id'];
    $now = current_time('mysql');

    $wpdb->update(
        $wpdb->prefix . 'rcmi_ticket_approvals',
        [
            'status'     => 'approved',
            'decided_at' => $now,
            'decided_by' => $user_id,
            'comment'    => $comment ?: null,
            'token'      => null,
        ],
        ['id' => (int) $pending['id']],
        ['%s', '%s', '%d', '%s', '%s'],
        ['%d']
    );

    $next = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_ticket_approvals
         WHERE ticket_id = %d AND status = 'pending'
         ORDER BY sort_order ASC LIMIT 1",
        $ticket_id
    ), ARRAY_A);

    if ($next) {
        do_action('rcmi_ticket_approval_step', $ticket_id, (int) $next['id'], 'approve_advanced');
        return new WP_REST_Response([
            'approved_step' => (int) $pending['id'],
            'next_step'     => (int) $next['id'],
            'ticket_status' => 'Pending Approval',
        ], 200);
    }

    $wpdb->update(
        $wpdb->prefix . 'rcmi_tickets',
        ['status' => 'Approved', 'updated_at' => $now, 'updated_by' => $user_id],
        ['id' => $ticket_id],
        ['%s', '%s', '%d'],
        ['%d']
    );
    do_action('rcmi_ticket_status_changed', $ticket_id, 'Approved', 'Pending Approval', null);

    return new WP_REST_Response([
        'approved_step'  => (int) $pending['id'],
        'next_step'      => null,
        'ticket_status'  => 'Approved',
    ], 200);
}

function rcmi_tickets_apply_reject($pending, $user_id, $comment) {
    global $wpdb;
    $ticket_id = (int) $pending['ticket_id'];
    $now = current_time('mysql');

    $chain = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_approval_chains WHERE id = %d",
        (int) $pending['chain_id']
    ), ARRAY_A);
    $on_reject = $chain['on_reject'] ?? 'restart';

    $wpdb->update(
        $wpdb->prefix . 'rcmi_ticket_approvals',
        [
            'status'     => 'rejected',
            'decided_at' => $now,
            'decided_by' => $user_id,
            'comment'    => $comment ?: null,
            'token'      => null,
        ],
        ['id' => (int) $pending['id']],
        ['%s', '%s', '%d', '%s', '%s'],
        ['%d']
    );

    if ($on_reject === 'terminal') {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}rcmi_ticket_approvals
             SET status = 'rejected', token = NULL
             WHERE ticket_id = %d AND status = 'pending'",
            $ticket_id
        ));
        $wpdb->update(
            $wpdb->prefix . 'rcmi_tickets',
            ['status' => 'Rejected', 'updated_at' => $now, 'updated_by' => $user_id],
            ['id' => $ticket_id],
            ['%s', '%s', '%d'],
            ['%d']
        );
        do_action('rcmi_ticket_status_changed', $ticket_id, 'Rejected', 'Pending Approval', $comment);
        do_action('rcmi_ticket_approval_rejected', $ticket_id, 'terminal', $comment);
        return new WP_REST_Response([
            'rejected_step' => (int) $pending['id'],
            'ticket_status' => 'Rejected',
            'on_reject'     => 'terminal',
        ], 200);
    }

    if ($on_reject === 'back_one') {
        $prev = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rcmi_ticket_approvals
             WHERE ticket_id = %d AND sort_order < %d
             ORDER BY sort_order DESC LIMIT 1",
            $ticket_id, (int) $pending['sort_order']
        ), ARRAY_A);
        if ($prev) {
            rcmi_tickets_reset_approval_to_pending($prev['id']);
            do_action('rcmi_ticket_approval_step', $ticket_id, (int) $prev['id'], 'reject_back_one');
            return new WP_REST_Response([
                'rejected_step' => (int) $pending['id'],
                'reopened_step' => (int) $prev['id'],
                'ticket_status' => 'Pending Approval',
                'on_reject'     => 'back_one',
            ], 200);
        }
    }

    // restart
    $all = $wpdb->get_results($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE ticket_id = %d",
        $ticket_id
    ), ARRAY_A);
    foreach ($all as $r) {
        rcmi_tickets_reset_approval_to_pending($r['id']);
    }
    $wpdb->update(
        $wpdb->prefix . 'rcmi_tickets',
        ['status' => 'Received', 'updated_at' => $now, 'updated_by' => $user_id],
        ['id' => $ticket_id],
        ['%s', '%s', '%d'],
        ['%d']
    );
    do_action('rcmi_ticket_status_changed', $ticket_id, 'Received', 'Pending Approval', $comment);
    do_action('rcmi_ticket_approval_rejected', $ticket_id, 'restart', $comment);

    return new WP_REST_Response([
        'rejected_step' => (int) $pending['id'],
        'ticket_status' => 'Received',
        'on_reject'     => 'restart',
    ], 200);
}
