<?php
/**
 * Shared permission helpers for RCMI Tickets.
 *
 * Per-ticket access rules (ticket-plan.md §4):
 * - view:          manage cap, OR author, OR assignee
 * - update:        manage cap, OR (author AND status = 'Received')
 * - delete:        manage cap
 * - change_status: manage cap (any transition), OR assignee (only to 'Completed')
 * - comment/react: anyone who can view
 * - pin:           manage cap only
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalize a ticket row (array|object) into a plain array with the keys
 * the permission checks need.
 *
 * @param array|object $ticket Ticket row. Expected keys: author_id, status, assignee_ids (array of int).
 * @return array
 */
function rcmi_tickets_normalize_ticket($ticket) {
    $ticket = (array) $ticket;
    return [
        'author_id'    => (int) ($ticket['author_id'] ?? 0),
        'status'       => (string) ($ticket['status'] ?? ''),
        'assignee_ids' => array_map('intval', (array) ($ticket['assignee_ids'] ?? [])),
    ];
}

/**
 * Check whether a user can perform an action, optionally on a specific ticket.
 *
 * @param int               $user_id User ID.
 * @param string            $action  One of: view_any, create, view, update, delete,
 *                                   change_status, comment, react, pin, manage.
 * @param array|object|null $ticket  Ticket row (required for per-ticket actions).
 * @param string|null       $new_status Target status, required for change_status.
 * @return bool
 */
function rcmi_tickets_can($user_id, $action, $ticket = null, $new_status = null) {
    $user_id = (int) $user_id;
    if (!$user_id) {
        return false;
    }

    $manage = user_can($user_id, 'rcmi_manage_tickets');
    $view   = $manage || user_can($user_id, 'rcmi_view_tickets');
    $create = $manage || user_can($user_id, 'rcmi_create_tickets');

    switch ($action) {
        case 'manage':
            return $manage;

        case 'view_any':
            return $view;

        case 'create':
            return $create;

        case 'view':
        case 'comment':
        case 'react':
            if (!$view || !$ticket) {
                return false;
            }
            $t = rcmi_tickets_normalize_ticket($ticket);
            return $manage
                || $t['author_id'] === $user_id
                || in_array($user_id, $t['assignee_ids'], true);

        case 'update':
            if (!$ticket) {
                return false;
            }
            if ($manage) {
                return true;
            }
            $t = rcmi_tickets_normalize_ticket($ticket);
            return $t['author_id'] === $user_id && $t['status'] === 'Received';

        case 'delete':
        case 'pin':
            return $manage;

        case 'change_status':
            if (!$ticket || !$new_status) {
                return false;
            }
            if ($manage) {
                return true;
            }
            $t = rcmi_tickets_normalize_ticket($ticket);
            return $new_status === 'Completed' && in_array($user_id, $t['assignee_ids'], true);
    }

    return false;
}

/**
 * IDs of all users with ticket-manage access (managers + administrators).
 * Used by the mentionable-users endpoint.
 *
 * @return int[]
 */
function rcmi_tickets_get_manage_user_ids() {
    $ids = [];

    foreach (['rcmi_ticket_manager', 'administrator'] as $role) {
        $query = new WP_User_Query([
            'role'   => $role,
            'fields' => 'ID',
            'number' => -1,
        ]);
        $ids = array_merge($ids, array_map('intval', $query->get_results()));
    }

    return array_values(array_unique($ids));
}
