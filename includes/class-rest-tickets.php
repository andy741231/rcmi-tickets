<?php
/**
 * REST API controller for tickets (ticket-plan.md §5 rows 1–6).
 *
 * Endpoints:
 *   GET    /tickets                  — list with filters/sort/pagination
 *   POST   /tickets                  — create
 *   GET    /tickets/{id}             — single ticket with relations
 *   PUT    /tickets/{id}             — update
 *   DELETE /tickets/{id}             — delete (+ attachments from disk)
 *   POST   /tickets/{id}/status      — change status (per §4 policy)
 *
 * All permission checks go through rcmi_tickets_can() (class-permissions.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Valid status and priority values (frozen per §3/§5).
 */
function rcmi_tickets_valid_statuses() {
    return ['Received', 'Approved', 'Rejected', 'Completed'];
}

function rcmi_tickets_valid_priorities() {
    return ['Low', 'Medium', 'High'];
}

/**
 * Register ticket routes.
 */
function rcmi_tickets_register_ticket_routes() {
    $namespace = 'rcmi/v1';

    register_rest_route($namespace, '/tickets', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_list',
            'permission_callback' => 'rcmi_tickets_perm_list',
            'args'                => rcmi_tickets_list_args(),
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_create',
            'permission_callback' => 'rcmi_tickets_perm_create',
            'args'                => rcmi_tickets_write_args(),
        ],
    ]);

    register_rest_route($namespace, '/tickets/(?P<id>\d+)', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_get',
            'permission_callback' => 'rcmi_tickets_perm_get',
            'args'                => ['id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int']],
        ],
        [
            'methods'             => 'PUT',
            'callback'            => 'rcmi_tickets_handle_update',
            'permission_callback' => 'rcmi_tickets_perm_update',
            'args'                => rcmi_tickets_write_args(true),
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'rcmi_tickets_handle_delete',
            'permission_callback' => 'rcmi_tickets_perm_delete',
            'args'                => ['id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int']],
        ],
    ]);

    register_rest_route($namespace, '/tickets/(?P<id>\d+)/status', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_status',
            'permission_callback' => 'rcmi_tickets_perm_status',
            'args'                => [
                'id'      => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'status'  => ['required' => true, 'type' => 'string', 'validate_callback' => function ($v) { return in_array($v, rcmi_tickets_valid_statuses(), true); }],
                'message' => ['type' => 'string'],
            ],
        ],
    ]);
}
add_action('rest_api_init', 'rcmi_tickets_register_ticket_routes');

// ── helpers ──────────────────────────────────────────────────────────

function rcmi_tickets_validate_int($v) {
    return is_numeric($v) && (int) $v > 0;
}

/**
 * Argument definitions for list endpoint (§5 row 1).
 */
function rcmi_tickets_list_args() {
    return [
        'search'         => ['type' => 'string'],
        'status'         => ['type' => 'array', 'items' => ['type' => 'string', 'validate_callback' => function ($v) { return in_array($v, rcmi_tickets_valid_statuses(), true); }]],
        'tag_ids'        => ['type' => 'array', 'items' => ['type' => 'integer']],
        'assignee_ids'   => ['type' => 'array', 'items' => ['type' => 'integer']],
        'scope'          => ['type' => 'string', 'validate_callback' => function ($v) { return in_array($v, ['assigned', 'submitted', 'all'], true); }],
        'date_from'      => ['type' => 'string'],
        'date_to'        => ['type' => 'string'],
        'sort'           => ['type' => 'string', 'default' => 'created_at'],
        'order'          => ['type' => 'string', 'default' => 'desc', 'validate_callback' => function ($v) { return in_array(strtolower($v), ['asc', 'desc'], true); }],
        'page'           => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
        'per_page'       => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100],
    ];
}

/**
 * Argument definitions for create/update (§5 rows 2, 4).
 */
function rcmi_tickets_write_args($is_update = false) {
    $args = [
        'title'          => ['type' => 'string', 'required' => !$is_update, 'sanitize_callback' => 'sanitize_text_field'],
        'description'    => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'wp_kses_post'],
        'priority'       => ['type' => 'string', 'validate_callback' => function ($v) { return in_array($v, rcmi_tickets_valid_priorities(), true); }],
        'due_date'       => ['type' => 'string', 'validate_callback' => function ($v) { return $v === '' || strtotime($v) !== false; }],
        'assignee_ids'   => ['type' => 'array', 'items' => ['type' => 'integer']],
        'tag_ids'        => ['type' => 'array', 'items' => ['type' => 'integer']],
    ];
    if ($is_update) {
        $args['status'] = ['type' => 'string', 'validate_callback' => function ($v) { return in_array($v, rcmi_tickets_valid_statuses(), true); }];
    }
    return $args;
}

/**
 * Load a single ticket row with assignee_ids populated.
 *
 * @param int $id
 * @return array|null Ticket row or null if not found.
 */
function rcmi_tickets_load_ticket($id) {
    global $wpdb;
    $id = (int) $id;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_tickets WHERE id = %d",
        $id
    ), ARRAY_A);

    if (!$row) {
        return null;
    }

    $row['id'] = (int) $row['id'];
    $row['author_id'] = (int) $row['author_id'];
    $row['updated_by'] = $row['updated_by'] !== null ? (int) $row['updated_by'] : null;
    $row['assignee_ids'] = rcmi_tickets_get_assignee_ids($id);
    $row['tag_ids'] = rcmi_tickets_get_ticket_tag_ids($id);

    return $row;
}

function rcmi_tickets_get_assignee_ids($ticket_id) {
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->prefix}rcmi_ticket_assignees WHERE ticket_id = %d ORDER BY user_id",
        (int) $ticket_id
    ));
    return array_map('intval', $ids);
}

function rcmi_tickets_get_ticket_tag_ids($ticket_id) {
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT tag_id FROM {$wpdb->prefix}rcmi_ticket_tag_map WHERE ticket_id = %d ORDER BY tag_id",
        (int) $ticket_id
    ));
    return array_map('intval', $ids);
}

/**
 * Sync assignees for a ticket (replace).
 */
function rcmi_tickets_sync_assignees($ticket_id, $assignee_ids) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_assignees', ['ticket_id' => (int) $ticket_id], ['%d']);

    $assignee_ids = array_filter(array_map('intval', (array) $assignee_ids));
    $assignee_ids = array_unique($assignee_ids);
    foreach ($assignee_ids as $uid) {
        if (get_userdata($uid)) {
            $wpdb->insert($wpdb->prefix . 'rcmi_ticket_assignees', [
                'ticket_id' => (int) $ticket_id,
                'user_id'   => $uid,
            ], ['%d', '%d']);
        }
    }
}

/**
 * Sync tags for a ticket (replace).
 */
function rcmi_tickets_sync_tags($ticket_id, $tag_ids) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_tag_map', ['ticket_id' => (int) $ticket_id], ['%d']);

    $tag_ids = array_filter(array_map('intval', (array) $tag_ids));
    $tag_ids = array_unique($tag_ids);
    foreach ($tag_ids as $tid) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rcmi_ticket_tags WHERE id = %d",
            $tid
        ));
        if ($exists) {
            $wpdb->insert($wpdb->prefix . 'rcmi_ticket_tag_map', [
                'ticket_id' => (int) $ticket_id,
                'tag_id'    => $tid,
            ], ['%d', '%d']);
        }
    }
}

/**
 * Format a ticket row for API response (adds user display info, tags).
 */
function rcmi_tickets_format_ticket($row) {
    $author = get_userdata($row['author_id']);
    $updater = $row['updated_by'] ? get_userdata($row['updated_by']) : null;

    $assignees = [];
    foreach ($row['assignee_ids'] as $uid) {
        $u = get_userdata($uid);
        if ($u) {
            $assignees[] = [
                'id'           => (int) $uid,
                'display_name' => $u->display_name,
                'user_email'   => $u->user_email,
            ];
        }
    }

    $tags = rcmi_tickets_get_ticket_tags($row['id']);

    return [
        'id'              => (int) $row['id'],
        'author_id'       => (int) $row['author_id'],
        'author_name'     => $author ? $author->display_name : '',
        'title'           => $row['title'],
        'description'     => $row['description'],
        'status'          => $row['status'],
        'priority'        => $row['priority'],
        'due_date'        => $row['due_date'],
        'updated_by'      => $row['updated_by'],
        'updated_by_name' => $updater ? $updater->display_name : null,
        'assignee_ids'    => $row['assignee_ids'],
        'assignees'       => $assignees,
        'tag_ids'         => $row['tag_ids'],
        'tags'            => $tags,
        'created_at'      => $row['created_at'],
        'updated_at'      => $row['updated_at'],
    ];
}

function rcmi_tickets_get_ticket_tags($ticket_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT t.id, t.name, t.slug FROM {$wpdb->prefix}rcmi_ticket_tags t
         INNER JOIN {$wpdb->prefix}rcmi_ticket_tag_map m ON m.tag_id = t.id
         WHERE m.ticket_id = %d ORDER BY t.name",
        (int) $ticket_id
    ), ARRAY_A);

    return array_map(function ($r) {
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'slug' => $r['slug']];
    }, $rows);
}

/**
 * Get attachment rows for a ticket.
 */
function rcmi_tickets_get_ticket_attachments($ticket_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, ticket_id, comment_id, file_path, original_name, mime_type, size
         FROM {$wpdb->prefix}rcmi_ticket_attachments WHERE ticket_id = %d ORDER BY id",
        (int) $ticket_id
    ), ARRAY_A);

    return array_map(function ($r) {
        return [
            'id'            => (int) $r['id'],
            'ticket_id'     => $r['ticket_id'] !== null ? (int) $r['ticket_id'] : null,
            'comment_id'    => $r['comment_id'] !== null ? (int) $r['comment_id'] : null,
            'original_name' => $r['original_name'],
            'mime_type'     => $r['mime_type'],
            'size'          => (int) $r['size'],
        ];
    }, $rows);
}

// ── permission callbacks ─────────────────────────────────────────────

function rcmi_tickets_perm_list() {
    return rcmi_tickets_can(get_current_user_id(), 'view_any');
}

function rcmi_tickets_perm_create() {
    return rcmi_tickets_can(get_current_user_id(), 'create');
}

function rcmi_tickets_perm_get($request) {
    $ticket = rcmi_tickets_load_ticket($request['id']);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    return rcmi_tickets_can(get_current_user_id(), 'view', $ticket);
}

function rcmi_tickets_perm_update($request) {
    $ticket = rcmi_tickets_load_ticket($request['id']);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    return rcmi_tickets_can(get_current_user_id(), 'update', $ticket);
}

function rcmi_tickets_perm_delete($request) {
    $ticket = rcmi_tickets_load_ticket($request['id']);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    return rcmi_tickets_can(get_current_user_id(), 'delete', $ticket);
}

function rcmi_tickets_perm_status($request) {
    $ticket = rcmi_tickets_load_ticket($request['id']);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    $new_status = isset($request['status']) ? (string) $request['status'] : '';
    return rcmi_tickets_can(get_current_user_id(), 'change_status', $ticket, $new_status);
}

// ── handlers ─────────────────────────────────────────────────────────

function rcmi_tickets_handle_list($request) {
    global $wpdb;

    $params = $request->get_params();
    $user_id = get_current_user_id();
    $is_manager = rcmi_tickets_can($user_id, 'manage');

    $where = ['1=1'];
    $args = [];

    // Scope: non-managers can only see assigned or submitted
    $scope = $params['scope'] ?? 'all';
    if (!$is_manager || $scope !== 'all') {
        if ($scope === 'assigned') {
            $where[] = "t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_assignees WHERE user_id = %d)";
            $args[] = $user_id;
        } elseif ($scope === 'submitted') {
            $where[] = 't.author_id = %d';
            $args[] = $user_id;
        } else {
            // Non-manager default: assigned OR submitted
            $where[] = "(t.author_id = %d OR t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_assignees WHERE user_id = %d))";
            $args[] = $user_id;
            $args[] = $user_id;
        }
    }

    // Search
    if (!empty($params['search'])) {
        $search = '%' . $wpdb->esc_like(sanitize_text_field($params['search'])) . '%';
        $where[] = '(t.title LIKE %s OR t.description_text LIKE %s)';
        $args[] = $search;
        $args[] = $search;
    }

    // Status filter
    if (!empty($params['status']) && is_array($params['status'])) {
        $statuses = array_filter($params['status'], function ($s) {
            return in_array($s, rcmi_tickets_valid_statuses(), true);
        });
        if ($statuses) {
            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $where[] = "t.status IN ($placeholders)";
            $args = array_merge($args, $statuses);
        }
    }

    // Tag filter
    if (!empty($params['tag_ids']) && is_array($params['tag_ids'])) {
        $tag_ids = array_filter(array_map('intval', $params['tag_ids']));
        if ($tag_ids) {
            $placeholders = implode(',', array_fill(0, count($tag_ids), '%d'));
            $where[] = "t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_tag_map WHERE tag_id IN ($placeholders))";
            $args = array_merge($args, $tag_ids);
        }
    }

    // Assignee filter
    if (!empty($params['assignee_ids']) && is_array($params['assignee_ids'])) {
        $assignee_ids = array_filter(array_map('intval', $params['assignee_ids']));
        if ($assignee_ids) {
            $placeholders = implode(',', array_fill(0, count($assignee_ids), '%d'));
            $where[] = "t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_assignees WHERE user_id IN ($placeholders))";
            $args = array_merge($args, $assignee_ids);
        }
    }

    // Date range
    if (!empty($params['date_from'])) {
        $where[] = 't.created_at >= %s';
        $args[] = $params['date_from'] . ' 00:00:00';
    }
    if (!empty($params['date_to'])) {
        $where[] = 't.created_at <= %s';
        $args[] = $params['date_to'] . ' 23:59:59';
    }

    // Sorting (whitelist columns to prevent SQL injection)
    $valid_sort = ['id', 'title', 'status', 'priority', 'due_date', 'created_at', 'updated_at'];
    $sort = in_array($params['sort'] ?? 'created_at', $valid_sort, true) ? $params['sort'] : 'created_at';
    $order = strtolower($params['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    $where_clause = implode(' AND ', $where);

    // Pagination
    $page = max(1, (int) ($params['page'] ?? 1));
    $per_page = min(100, max(1, (int) ($params['per_page'] ?? 10)));
    $offset = ($page - 1) * $per_page;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT t.* FROM {$wpdb->prefix}rcmi_tickets t WHERE {$where_clause} ORDER BY t.{$sort} {$order} LIMIT %d OFFSET %d",
        array_merge($args, [$per_page, $offset])
    ), ARRAY_A);

    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}rcmi_tickets t WHERE {$where_clause}",
        $args
    ));

    $tickets = [];
    foreach ($rows as $row) {
        $row['id'] = (int) $row['id'];
        $row['author_id'] = (int) $row['author_id'];
        $row['updated_by'] = $row['updated_by'] !== null ? (int) $row['updated_by'] : null;
        $row['assignee_ids'] = rcmi_tickets_get_assignee_ids($row['id']);
        $row['tag_ids'] = rcmi_tickets_get_ticket_tag_ids($row['id']);
        $tickets[] = rcmi_tickets_format_ticket($row);
    }

    return new WP_REST_Response([
        'items'      => $tickets,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $per_page,
        'total_pages' => (int) ceil($total / $per_page),
    ], 200);
}

function rcmi_tickets_handle_create($request) {
    global $wpdb;
    $now = current_time('mysql');

    $title = $request['title'] ?? '';
    $description = $request['description'] ?? '';
    $priority = $request['priority'] ?? 'Medium';
    $due_date = !empty($request['due_date']) ? $request['due_date'] : null;
    $assignee_ids = $request['assignee_ids'] ?? [];
    $tag_ids = $request['tag_ids'] ?? [];

    if (empty($title)) {
        return new WP_Error('rcmi_tickets_missing_title', 'Title is required.', ['status' => 400]);
    }

    $description_text = wp_strip_all_tags($description);

    $wpdb->insert($wpdb->prefix . 'rcmi_tickets', [
        'author_id'        => get_current_user_id(),
        'title'            => $title,
        'description'      => $description,
        'description_text' => $description_text,
        'status'           => 'Received',
        'priority'         => $priority,
        'due_date'         => $due_date,
        'created_at'       => $now,
        'updated_at'       => $now,
    ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

    $ticket_id = (int) $wpdb->insert_id;
    if (!$ticket_id) {
        return new WP_Error('rcmi_tickets_create_failed', 'Failed to create ticket.', ['status' => 500]);
    }

    rcmi_tickets_sync_assignees($ticket_id, $assignee_ids);
    rcmi_tickets_sync_tags($ticket_id, $tag_ids);

    do_action('rcmi_ticket_created', $ticket_id, get_current_user_id(), $assignee_ids);

    $row = rcmi_tickets_load_ticket($ticket_id);
    return new WP_REST_Response(rcmi_tickets_format_ticket($row), 201);
}

function rcmi_tickets_handle_get($request) {
    $row = rcmi_tickets_load_ticket($request['id']);
    $formatted = rcmi_tickets_format_ticket($row);
    $formatted['attachments'] = rcmi_tickets_get_ticket_attachments($row['id']);
    return new WP_REST_Response($formatted, 200);
}

function rcmi_tickets_handle_update($request) {
    global $wpdb;
    $ticket = rcmi_tickets_load_ticket($request['id']);
    $now = current_time('mysql');

    $data = [];
    $format = [];

    $fields = ['title' => '%s', 'description' => '%s', 'priority' => '%s', 'due_date' => '%s'];
    foreach ($fields as $field => $fmt) {
        if (isset($request[$field])) {
            $data[$field] = $request[$field];
            $format[] = $fmt;
        }
    }

    // Status change via update (managers only — permission_callback already enforced)
    if (isset($request['status']) && in_array($request['status'], rcmi_tickets_valid_statuses(), true)) {
        $old_status = $ticket['status'];
        $new_status = $request['status'];
        if ($old_status !== $new_status) {
            $data['status'] = $new_status;
            $format[] = '%s';
        }
    }

    if (isset($request['description'])) {
        $data['description_text'] = wp_strip_all_tags($request['description']);
        $format[] = '%s';
    }

    if ($data) {
        $data['updated_by'] = get_current_user_id();
        $format[] = '%d';
        $data['updated_at'] = $now;
        $format[] = '%s';

        $wpdb->update($wpdb->prefix . 'rcmi_tickets', $data, ['id' => (int) $request['id']], $format, ['%d']);
    }

    if (isset($request['assignee_ids'])) {
        rcmi_tickets_sync_assignees($request['id'], $request['assignee_ids']);
    }
    if (isset($request['tag_ids'])) {
        rcmi_tickets_sync_tags($request['id'], $request['tag_ids']);
    }

    // Fire status change action if status was updated
    if (isset($data['status']) && $ticket['status'] !== $data['status']) {
        do_action('rcmi_ticket_status_changed', (int) $request['id'], $data['status'], $ticket['status'], null);
    }

    $row = rcmi_tickets_load_ticket($request['id']);
    return new WP_REST_Response(rcmi_tickets_format_ticket($row), 200);
}

function rcmi_tickets_handle_delete($request) {
    global $wpdb;
    $ticket_id = (int) $request['id'];

    // Delete attachment files from disk
    $attachments = rcmi_tickets_get_ticket_attachments($ticket_id);
    foreach ($attachments as $att) {
        $full_path = trailingslashit(WP_CONTENT_DIR) . 'uploads/rcmi-tickets/' . $ticket_id . '/' . $att['file_path'];
        if (file_exists($full_path)) {
            @unlink($full_path);
        }
    }

    // Delete DB rows (cascade manually since we don't use FK constraints)
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_attachments', ['ticket_id' => $ticket_id], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_assignees', ['ticket_id' => $ticket_id], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_tag_map', ['ticket_id' => $ticket_id], ['%d']);

    // Delete comments and their reactions/attachments
    $comment_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rcmi_ticket_comments WHERE ticket_id = %d",
        $ticket_id
    ));
    if ($comment_ids) {
        $placeholders = implode(',', array_fill(0, count($comment_ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rcmi_ticket_comment_reactions WHERE comment_id IN ($placeholders)",
            $comment_ids
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rcmi_ticket_attachments WHERE comment_id IN ($placeholders)",
            $comment_ids
        ));
        $wpdb->delete($wpdb->prefix . 'rcmi_ticket_comments', ['ticket_id' => $ticket_id], ['%d']);
    }

    $wpdb->delete($wpdb->prefix . 'rcmi_tickets', ['id' => $ticket_id], ['%d']);

    // Clean up the ticket upload directory
    $dir = trailingslashit(WP_CONTENT_DIR) . 'uploads/rcmi-tickets/' . $ticket_id;
    if (is_dir($dir)) {
        @rmdir($dir);
    }

    return new WP_REST_Response(['deleted' => true, 'id' => $ticket_id], 200);
}

function rcmi_tickets_handle_status($request) {
    global $wpdb;
    $ticket = rcmi_tickets_load_ticket($request['id']);
    $old_status = $ticket['status'];
    $new_status = $request['status'];
    $message = isset($request['message']) ? sanitize_textarea_field($request['message']) : null;

    $wpdb->update($wpdb->prefix . 'rcmi_tickets', [
        'status'      => $new_status,
        'updated_by'  => get_current_user_id(),
        'updated_at'  => current_time('mysql'),
    ], ['id' => (int) $request['id']], ['%s', '%d', '%s'], ['%d']);

    do_action('rcmi_ticket_status_changed', (int) $request['id'], $new_status, $old_status, $message);

    $row = rcmi_tickets_load_ticket($request['id']);
    return new WP_REST_Response(rcmi_tickets_format_ticket($row), 200);
}
