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
 * Valid status values (frozen per §3/§5).
 */
function rcmi_tickets_valid_statuses() {
    return ['Received', 'Pending Approval', 'Approved', 'In Progress', 'Rejected', 'Rejected: Pending Revision', 'Completed'];
}

function rcmi_tickets_status_transition_allowed($old_status, $new_status) {
    $transitions = [
        'Received' => ['Approved', 'Rejected'],
        'Pending Approval' => ['Approved', 'Rejected'],
        'Approved' => ['In Progress'],
        'In Progress' => ['Completed'],
    ];
    return in_array($new_status, $transitions[$old_status] ?? [], true);
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

    register_rest_route($namespace, '/tickets/batch-delete', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_batch_delete',
            'permission_callback' => function () {
                return rcmi_tickets_can(get_current_user_id(), 'manage');
            },
            'args'                => [
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'required' => true],
            ],
        ],
    ]);

    register_rest_route($namespace, '/tickets/batch-archive', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_batch_archive',
            'permission_callback' => function () {
                return rcmi_tickets_can(get_current_user_id(), 'manage');
            },
            'args'                => [
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'required' => true],
            ],
        ],
    ]);

    register_rest_route($namespace, '/tickets/export', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_csv_export',
            'permission_callback' => 'rcmi_tickets_perm_list',
            'args'                => rcmi_tickets_list_args(),
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

    register_rest_route($namespace, '/tickets/(?P<id>\d+)/assignees', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_assignee_update',
            'permission_callback' => 'rcmi_tickets_perm_assignee_update',
            'args'                => [
                'id'            => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'assignee_ids'  => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
        ],
    ]);

    register_rest_route($namespace, '/tickets/(?P<id>\d+)/archive', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_archive',
            'permission_callback' => 'rcmi_tickets_perm_archive',
            'args'                => ['id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int']],
        ],
    ]);

    register_rest_route($namespace, '/tickets/(?P<id>\d+)/resurrect', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_resurrect',
            'permission_callback' => 'rcmi_tickets_perm_archive',
            'args'                => ['id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int']],
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
        'archived'       => ['type' => 'boolean', 'default' => false],
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
        'due_date'       => ['type' => 'string', 'validate_callback' => function ($v) { return $v === '' || strtotime($v) !== false; }],
        'assignee_ids'   => ['type' => 'array', 'items' => ['type' => 'integer']],
        'tag_ids'        => ['type' => 'array', 'items' => ['type' => 'integer']],
        'form_answers'   => ['type' => 'object'], // map of field_key => value (schema v3)
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
    $author_name = $author ? $author->display_name : '';
    $author_email = $author ? $author->user_email : '';

    // Public submissions share a guest requestor account; recover the submitter's
    // actual name and email. Prefer the dedicated submitter_name/submitter_email
    // columns (DB v11+); fall back to parsing the description text for older
    // tickets that predate the columns.
    if ($author && $author->user_login === 'guest_submitter') {
        if (!empty($row['submitter_email']) && is_email($row['submitter_email'])) {
            $author_email = $row['submitter_email'];
            $author_name = !empty($row['submitter_name']) ? $row['submitter_name'] : $author_name;
        } else {
            $source = wp_strip_all_tags((string) ($row['description_text'] ?: $row['description']));
            if (preg_match('/Submitted by:\s*(.*?)\s*\(([^)]+)\)/i', $source, $matches)) {
                $candidate_email = trim($matches[2]);
                if (is_email($candidate_email)) {
                    $author_name = trim($matches[1]) ?: $author_name;
                    $author_email = $candidate_email;
                }
            }
        }
    }

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

    // Schema v3: form answers + approval info
    $form_answers = rcmi_tickets_get_ticket_form_answers($row['id']);
    $approvals = rcmi_tickets_get_ticket_approvals($row['id']);
    $status_log = rcmi_tickets_get_ticket_status_log($row['id']);
    $current_step = null;
    foreach ($approvals as $a) {
        if ($a['status'] === 'pending') {
            $current_step = $a;
            break;
        }
    }
    $approval_chain = null;
    if ($approvals) {
        $approval_chain = rcmi_tickets_load_approval_chain($approvals[0]['chain_id']);
    }

    // Whether the current user can approve/reject the current pending step
    $can_approve_current_step = $current_step && rcmi_tickets_user_can_approve_ticket(get_current_user_id(), (int) $row['id']);

    return [
        'id'              => (int) $row['id'],
        'author_id'       => (int) $row['author_id'],
        'author_name'     => $author_name,
        'author_email'    => $author_email,
        'title'           => $row['title'],
        'description'     => $row['description'],
        'status'          => $row['status'],
        'archived'        => (bool) ($row['archived'] ?? 0),
        'due_date'        => $row['due_date'],
        'updated_by'      => $row['updated_by'],
        'updated_by_name' => $updater ? $updater->display_name : null,
        'assignee_ids'    => $row['assignee_ids'],
        'assignees'       => $assignees,
        'tag_ids'         => $row['tag_ids'],
        'tags'            => $tags,
        'form_answers'    => $form_answers,
        'approval_chain'  => $approval_chain,
        'current_approval_step' => $current_step,
        'can_approve_current_step' => (bool) $can_approve_current_step,
        'approval_history' => $approvals,
        'status_history'  => $status_log,
        'created_at'      => $row['created_at'],
        'updated_at'      => $row['updated_at'],
    ];
}

/**
 * Get form answers for a ticket as a map of field_key => value (schema v3).
 */
function rcmi_tickets_get_ticket_form_answers($ticket_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT fa.value, ff.field_key, ff.type
         FROM {$wpdb->prefix}rcmi_form_answers fa
         INNER JOIN {$wpdb->prefix}rcmi_form_fields ff ON ff.id = fa.field_id
         WHERE fa.ticket_id = %d",
        (int) $ticket_id
    ), ARRAY_A);

    $answers = [];
    foreach ($rows as $r) {
        $val = $r['value'];
        // Decode JSON for multi-value (checkbox) — stored as JSON array
        if ($r['type'] === 'checkbox' || $r['type'] === 'radio') {
            $decoded = json_decode($val, true);
            $answers[$r['field_key']] = (is_array($decoded)) ? $decoded : $val;
        } else {
            $answers[$r['field_key']] = $val;
        }
    }
    return $answers;
}

/**
 * Save form answers for a ticket (replace). $answers is field_key => value (schema v3).
 */
function rcmi_tickets_sync_form_answers($ticket_id, $answers) {
    global $wpdb;

    // Wipe existing
    $wpdb->delete($wpdb->prefix . 'rcmi_form_answers', ['ticket_id' => (int) $ticket_id], ['%d']);

    if (!is_array($answers)) {
        return;
    }

    // Build field_key => field_id map
    $fields = rcmi_tickets_get_all_form_fields();
    $key_to_id = [];
    foreach ($fields as $f) {
        $key_to_id[$f['field_key']] = (int) $f['id'];
    }

    foreach ($answers as $key => $value) {
        if (!isset($key_to_id[$key])) {
            continue; // unknown field, skip
        }
        $stored = is_array($value) ? wp_json_encode(array_map('strval', $value)) : (string) $value;
        $wpdb->insert(
            $wpdb->prefix . 'rcmi_form_answers',
            [
                'ticket_id' => (int) $ticket_id,
                'field_id'  => $key_to_id[$key],
                'value'     => $stored,
            ],
            ['%d', '%d', '%s']
        );
    }
}

/**
 * Resolve which approval chain applies to a ticket based on its form answers.
 * Match priority: exact trigger_field_key + trigger_value match → else default chain
 * (trigger_field_key NULL or empty) → else null (no chain).
 *
 * @param array $form_answers field_key => value
 * @return array|null Chain row (with steps) or null.
 */
function rcmi_tickets_resolve_approval_chain($form_answers) {
    $chains = rcmi_tickets_get_all_approval_chains();
    if (!$chains) {
        return null;
    }

    // First pass: exact match on trigger_field_key + trigger_value
    foreach ($chains as $chain) {
        if (!$chain['is_active']) {
            continue;
        }
        $tfk = $chain['trigger_field_key'];
        $tv = $chain['trigger_value'];
        if ($tfk && $tv && isset($form_answers[$tfk]) && (string) $form_answers[$tfk] === (string) $tv) {
            return $chain;
        }
    }

    // Second pass: default chain (no trigger)
    foreach ($chains as $chain) {
        if (!$chain['is_active']) {
            continue;
        }
        if (!$chain['trigger_field_key']) {
            return $chain;
        }
    }

    return null;
}

/**
 * Initialize the approval chain for a newly-created ticket:
 * create rcmi_ticket_approvals rows for each step, resolve role → user,
 * set ticket status to 'Pending Approval', email first approver.
 *
 * @param int   $ticket_id
 * @param array $chain  Chain with steps
 * @return bool True if chain was initialized, false if no resolvable approver.
 */
function rcmi_tickets_init_ticket_approval_chain($ticket_id, $chain, $cycle = 1) {
    global $wpdb;
    $ticket_id = (int) $ticket_id;
    $cycle = (int) $cycle;

    if (!$chain || empty($chain['steps'])) {
        return false;
    }

    $order = 1;
    $first_step_id = null;
    foreach ($chain['steps'] as $step) {
        $approver_user_id = null;
        $approver_role = null;

        if ($step['approver_type'] === 'user') {
            $approver_user_id = (int) $step['approver_user_id'];
        } else {
            $approver_role = $step['approver_role'];
            // Resolve role → first user with that role
            $users = get_users(['role' => $approver_role, 'number' => 1, 'fields' => 'ID']);
            if ($users) {
                $approver_user_id = (int) $users[0];
            }
        }

        $tok = rcmi_tickets_generate_approval_token();
        $wpdb->insert($wpdb->prefix . 'rcmi_ticket_approvals', [
            'ticket_id'        => $ticket_id,
            'chain_id'         => (int) $chain['id'],
            'step_id'          => (int) $step['id'],
            'sort_order'       => $order,
            'cycle'            => $cycle,
            'approver_user_id' => $approver_user_id,
            'approver_role'    => $approver_role,
            'status'           => 'pending',
            'token'            => $tok['token'],
            'token_expires'    => $tok['expires'],
        ], ['%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s']);

        if ($order === 1) {
            $first_step_id = (int) $wpdb->insert_id;
        }
        $order++;
    }

    // Set ticket to Pending Approval
    $wpdb->update(
        $wpdb->prefix . 'rcmi_tickets',
        ['status' => 'Pending Approval'],
        ['id' => $ticket_id],
        ['%s'],
        ['%d']
    );

    // Apply the chain's default assignee (completion_assignee_id) at chain
    // init time so they are assigned upfront. They receive the "Approved"
    // notification once all steps clear (email_status_changed loops assignee_ids)
    // and can then move the ticket to In Progress / Complete.
    $default_assignee = !empty($chain['completion_assignee_id']) ? (int) $chain['completion_assignee_id'] : 0;
    if ($default_assignee && get_userdata($default_assignee)) {
        $already_assigned = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}rcmi_ticket_assignees WHERE ticket_id = %d AND user_id = %d",
            $ticket_id,
            $default_assignee
        ));
        if (!$already_assigned) {
            $wpdb->insert($wpdb->prefix . 'rcmi_ticket_assignees', [
                'ticket_id' => $ticket_id,
                'user_id'   => $default_assignee,
            ], ['%d', '%d']);
        }
    }

    // Email first approver
    if ($first_step_id) {
        do_action('rcmi_ticket_approval_step', $ticket_id, $first_step_id, 'chain_started');
    }

    return true;
}

/**
 * Reset a ticket's approval chain back to step 1 (used on resubmit after restart).
 * Preserves old approval rows as history and creates new rows with an incremented cycle.
 */
function rcmi_tickets_restart_ticket_approval_chain($ticket_id) {
    global $wpdb;
    $ticket_id = (int) $ticket_id;

    // Find the chain from the existing rows
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT chain_id, MAX(cycle) as max_cycle FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE ticket_id = %d",
        $ticket_id
    ), ARRAY_A);
    if (!$existing || !$existing['chain_id']) {
        return false;
    }
    $chain = rcmi_tickets_load_approval_chain((int) $existing['chain_id']);
    if (!$chain) {
        return false;
    }

    // Mark all existing pending rows as skipped (they should not remain pending)
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}rcmi_ticket_approvals
         SET status = 'skipped', token = NULL
         WHERE ticket_id = %d AND status = 'pending'",
        $ticket_id
    ));

    // Re-init with next cycle number
    $next_cycle = (int) $existing['max_cycle'] + 1;
    return rcmi_tickets_init_ticket_approval_chain($ticket_id, $chain, $next_cycle);
}

/**
 * Log a status change to the rcmi_ticket_status_log table.
 * Hooked on rcmi_ticket_status_changed.
 */
function rcmi_tickets_log_status_change($ticket_id, $new_status, $old_status, $message = null) {
    global $wpdb;
    if ($new_status === $old_status) {
        return;
    }
    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_status_log', [
        'ticket_id'  => (int) $ticket_id,
        'old_status' => (string) $old_status,
        'new_status' => (string) $new_status,
        'changed_by' => (int) get_current_user_id(),
        'changed_at' => current_time('mysql'),
        'message'    => $message ?: null,
    ], ['%d', '%s', '%s', '%d', '%s', '%s']);
}
add_action('rcmi_ticket_status_changed', 'rcmi_tickets_log_status_change', 5, 4);

/**
 * Get status change log entries for a ticket, ordered chronologically.
 * Only returns post-approval statuses that are meaningful in the timeline
 * (In Progress, Completed) — approval/rejection transitions are already
 * represented by the approval step rows.
 *
 * @param int $ticket_id
 * @return array Formatted status log entries
 */
function rcmi_tickets_get_ticket_status_log($ticket_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_ticket_status_log
         WHERE ticket_id = %d
         ORDER BY changed_at ASC, id ASC",
        (int) $ticket_id
    ), ARRAY_A);

    $result = [];
    foreach ($rows as $row) {
        $changed_by_name = null;
        if ($row['changed_by']) {
            $u = get_userdata((int) $row['changed_by']);
            $changed_by_name = $u ? $u->display_name : null;
        }
        $result[] = [
            'id'              => (int) $row['id'],
            'new_status'      => $row['new_status'],
            'old_status'      => $row['old_status'],
            'changed_by'      => (int) $row['changed_by'],
            'changed_by_name' => $changed_by_name,
            'changed_at'      => $row['changed_at'],
            'message'         => $row['message'],
        ];
    }
    return $result;
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
    // Completed tickets cannot be deleted — they must be sent to Ticket Heaven instead
    if ($ticket['status'] === 'Completed') {
        return new WP_Error('rcmi_tickets_no_delete_completed', 'Completed tickets cannot be deleted. Send them to Ticket Heaven instead.', ['status' => 409]);
    }
    return rcmi_tickets_can(get_current_user_id(), 'delete', $ticket);
}

function rcmi_tickets_perm_archive($request) {
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
    if (!rcmi_tickets_status_transition_allowed($ticket['status'], $new_status)) {
        return new WP_Error('rcmi_tickets_invalid_status_transition', 'This status change is not allowed.', ['status' => 409]);
    }
    return rcmi_tickets_can(get_current_user_id(), 'change_status', $ticket, $new_status);
}

/**
 * Permission for assignee updates: managers + current step approver.
 */
function rcmi_tickets_perm_assignee_update($request) {
    $ticket = rcmi_tickets_load_ticket($request['id']);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    $user_id = get_current_user_id();
    // Managers can always edit assignees
    if (rcmi_tickets_can($user_id, 'manage')) {
        return true;
    }
    // Current step approver can edit assignees
    if (function_exists('rcmi_tickets_user_can_approve_ticket') && rcmi_tickets_user_can_approve_ticket($user_id, (int) $request['id'])) {
        return true;
    }
    return new WP_Error('rcmi_tickets_forbidden', 'You do not have permission to edit assignees on this ticket.', ['status' => 403]);
}

// ── handlers ─────────────────────────────────────────────────────────

function rcmi_tickets_handle_list($request) {
    global $wpdb;

    $params = $request->get_params();
    $user_id = get_current_user_id();
    $is_manager = rcmi_tickets_can($user_id, 'manage');

    $where = ['1=1'];
    $args = [];

    // Archived filter: default excludes archived tickets; archived=true shows only archived
    $archived = isset($params['archived']) ? filter_var($params['archived'], FILTER_VALIDATE_BOOLEAN) : false;
    if ($archived) {
        $where[] = 't.archived = 1';
    } else {
        $where[] = 't.archived = 0';
    }

    // Scope: non-managers can only see assigned, submitted, or approval-chain
    $scope = $params['scope'] ?? 'all';
    if (!$is_manager || $scope !== 'all') {
        if ($scope === 'assigned') {
            $where[] = "t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_assignees WHERE user_id = %d)";
            $args[] = $user_id;
        } elseif ($scope === 'submitted') {
            $where[] = 't.author_id = %d';
            $args[] = $user_id;
        } else {
            // Non-manager default: assigned OR submitted OR in approval chain
            $vis = "(t.author_id = %d OR t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_assignees WHERE user_id = %d)";
            $vis_args = [$user_id, $user_id];

            // Approval chain: specific user match
            $vis .= " OR t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE approver_user_id = %d)";
            $vis_args[] = $user_id;

            // Approval chain: role-based match
            $current_user = wp_get_current_user();
            $user_roles = (array) $current_user->roles;
            if ($user_roles) {
                $role_placeholders = implode(',', array_fill(0, count($user_roles), '%s'));
                $vis .= " OR t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE approver_role IN ($role_placeholders))";
                foreach ($user_roles as $role) {
                    $vis_args[] = $role;
                }
            }

            $vis .= ")";
            $where[] = $vis;
            $args = array_merge($args, $vis_args);
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
    $valid_sort = ['id', 'title', 'status', 'due_date', 'created_at', 'updated_at'];
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

/**
 * CSV export: returns all matching tickets (same filters as list) as a
 * CSV download. Includes core fields + dynamic form field columns.
 */
function rcmi_tickets_handle_csv_export($request) {
    global $wpdb;

    $params = $request->get_params();
    $user_id = get_current_user_id();
    $is_manager = rcmi_tickets_can($user_id, 'manage');

    // Build the same WHERE clause as the list handler
    $where = ['1=1'];
    $args = [];

    // Archived filter: default excludes archived tickets; archived=true shows only archived
    $archived = isset($params['archived']) ? filter_var($params['archived'], FILTER_VALIDATE_BOOLEAN) : false;
    if ($archived) {
        $where[] = 't.archived = 1';
    } else {
        $where[] = 't.archived = 0';
    }

    $scope = $params['scope'] ?? 'all';
    if (!$is_manager || $scope !== 'all') {
        if ($scope === 'assigned') {
            $where[] = "t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_assignees WHERE user_id = %d)";
            $args[] = $user_id;
        } elseif ($scope === 'submitted') {
            $where[] = 't.author_id = %d';
            $args[] = $user_id;
        } else {
            $vis = "(t.author_id = %d OR t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_assignees WHERE user_id = %d)";
            $vis_args = [$user_id, $user_id];
            $vis .= " OR t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE approver_user_id = %d)";
            $vis_args[] = $user_id;
            $current_user = wp_get_current_user();
            $user_roles = (array) $current_user->roles;
            if ($user_roles) {
                $role_placeholders = implode(',', array_fill(0, count($user_roles), '%s'));
                $vis .= " OR t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE approver_role IN ($role_placeholders))";
                foreach ($user_roles as $role) {
                    $vis_args[] = $role;
                }
            }
            $vis .= ")";
            $where[] = $vis;
            $args = array_merge($args, $vis_args);
        }
    }

    if (!empty($params['search'])) {
        $search = '%' . $wpdb->esc_like(sanitize_text_field($params['search'])) . '%';
        $where[] = '(t.title LIKE %s OR t.description_text LIKE %s)';
        $args[] = $search;
        $args[] = $search;
    }
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
    if (!empty($params['tag_ids']) && is_array($params['tag_ids'])) {
        $tag_ids = array_filter(array_map('intval', $params['tag_ids']));
        if ($tag_ids) {
            $placeholders = implode(',', array_fill(0, count($tag_ids), '%d'));
            $where[] = "t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_tag_map WHERE tag_id IN ($placeholders))";
            $args = array_merge($args, $tag_ids);
        }
    }
    if (!empty($params['assignee_ids']) && is_array($params['assignee_ids'])) {
        $assignee_ids = array_filter(array_map('intval', $params['assignee_ids']));
        if ($assignee_ids) {
            $placeholders = implode(',', array_fill(0, count($assignee_ids), '%d'));
            $where[] = "t.id IN (SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_assignees WHERE user_id IN ($placeholders))";
            $args = array_merge($args, $assignee_ids);
        }
    }
    if (!empty($params['date_from'])) {
        $where[] = 't.created_at >= %s';
        $args[] = $params['date_from'] . ' 00:00:00';
    }
    if (!empty($params['date_to'])) {
        $where[] = 't.created_at <= %s';
        $args[] = $params['date_to'] . ' 23:59:59';
    }

    $valid_sort = ['id', 'title', 'status', 'due_date', 'created_at', 'updated_at'];
    $sort = in_array($params['sort'] ?? 'created_at', $valid_sort, true) ? ($params['sort'] ?? 'created_at') : 'created_at';
    $order = strtolower($params['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $where_clause = implode(' AND ', $where);

    // Fetch ALL matching rows (no pagination for export)
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT t.* FROM {$wpdb->prefix}rcmi_tickets t WHERE {$where_clause} ORDER BY t.{$sort} {$order}",
        $args
    ), ARRAY_A);

    // Get form field definitions for dynamic columns
    $form_fields = rcmi_tickets_get_all_form_fields();

    // Build CSV — primary columns mirror the on-screen TicketList table
    // (Ticket, Status, Assignee, Due, Updated), followed by dynamic form
    // fields, then supplementary audit columns at the end.
    $primary_headers   = ['ID', 'Title', 'Status', 'Assignee', 'Due Date', 'Updated'];
    $field_headers     = array_map(function ($f) { return $f['label']; }, $form_fields);
    $extra_headers     = ['Requestor', 'Requestor Email', 'Tags', 'Created'];
    $all_headers       = array_merge($primary_headers, $field_headers, $extra_headers);

    $output = fopen('php://temp', 'r+');
    // BOM for Excel UTF-8 compatibility
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $all_headers);

    foreach ($rows as $row) {
        $row['id'] = (int) $row['id'];
        $row['author_id'] = (int) $row['author_id'];
        $row['assignee_ids'] = rcmi_tickets_get_assignee_ids($row['id']);
        $row['tag_ids'] = rcmi_tickets_get_ticket_tag_ids($row['id']);
        $formatted = rcmi_tickets_format_ticket($row);

        // Assignee names (Assignee column — matches UI "Assignee")
        $assignee_names = implode('; ', array_map(function ($a) { return $a['display_name']; }, $formatted['assignees']));
        // Tag names
        $tag_names = implode('; ', array_map(function ($t) { return $t['name']; }, $formatted['tags']));

        $primary_row = [
            $formatted['id'],
            $formatted['title'],
            $formatted['status'],
            $assignee_names,
            $formatted['due_date'] ?: '',
            $formatted['updated_at'],
        ];

        // Form answer values
        $field_values = [];
        foreach ($form_fields as $f) {
            $key = $f['field_key'];
            $val = $formatted['form_answers'][$key] ?? '';
            if (is_array($val)) {
                $val = implode('; ', $val);
            }
            $field_values[] = $val;
        }

        $extra_row = [
            $formatted['author_name'],
            $formatted['author_email'],
            $tag_names,
            $formatted['created_at'],
        ];

        fputcsv($output, array_merge($primary_row, $field_values, $extra_row));
    }

    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);

    $filename = 'tickets-export-' . current_time('Y-m-d-His') . '.csv';

    // Serve as a raw file download — must echo directly and exit to
    // prevent WP REST from JSON-encoding the CSV body (which turns
    // real newlines into literal "\n" text).
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

function rcmi_tickets_handle_create($request) {
    global $wpdb;
    $now = current_time('mysql');

    $title = $request['title'] ?? '';
    $description = $request['description'] ?? '';
    $due_date = !empty($request['due_date']) ? $request['due_date'] : null;
    $assignee_ids = $request['assignee_ids'] ?? [];
    $tag_ids = $request['tag_ids'] ?? [];
    $form_answers = $request['form_answers'] ?? [];

    if (empty($title)) {
        return new WP_Error('rcmi_tickets_missing_title', 'Title is required.', ['status' => 400]);
    }

    $description_text = wp_strip_all_tags($description);

    // Insert as 'Received' first; chain init (if any) will flip to 'Pending Approval'
    $wpdb->insert($wpdb->prefix . 'rcmi_tickets', [
        'author_id'        => get_current_user_id(),
        'title'            => $title,
        'description'      => $description,
        'description_text' => $description_text,
        'status'           => 'Received',
        'due_date'         => $due_date,
        'created_at'       => $now,
        'updated_at'       => $now,
    ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

    $ticket_id = (int) $wpdb->insert_id;
    if (!$ticket_id) {
        return new WP_Error('rcmi_tickets_create_failed', 'Failed to create ticket.', ['status' => 500]);
    }

    rcmi_tickets_sync_assignees($ticket_id, $assignee_ids);
    rcmi_tickets_sync_tags($ticket_id, $tag_ids);
    rcmi_tickets_sync_form_answers($ticket_id, $form_answers);

    // Sync due_date from reserved "Due Date" form field (if present)
    $form_due_date = rcmi_tickets_extract_due_date_from_answers($form_answers);
    if ($form_due_date !== null) {
        $wpdb->update($wpdb->prefix . 'rcmi_tickets', ['due_date' => $form_due_date], ['id' => $ticket_id], ['%s'], ['%d']);
    }

    // Auto-tag: evaluate rules against form answers and merge in matching tags
    rcmi_tickets_apply_auto_tags($ticket_id, $form_answers);

    // Schema v3: resolve + init approval chain (flips status to 'Pending Approval' if matched)
    // Default assignee is applied inside init_ticket_approval_chain.
    // The assignee notification email is sent when the ticket is approved
    // (via rcmi_ticket_status_changed → 'Approved'), not at creation time.
    $chain = rcmi_tickets_resolve_approval_chain($form_answers);
    if ($chain) {
        rcmi_tickets_init_ticket_approval_chain($ticket_id, $chain);
    }

    $row = rcmi_tickets_load_ticket($ticket_id);
    do_action('rcmi_ticket_created', $ticket_id, get_current_user_id(), $row['assignee_ids']);

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

    $fields = ['title' => '%s', 'description' => '%s', 'due_date' => '%s'];
    foreach ($fields as $field => $fmt) {
        if (isset($request[$field])) {
            $val = $request[$field];
            // Empty string → NULL for DATE columns (strict SQL mode rejects '')
            // Format must be null (not %s) so $wpdb emits SQL NULL, not ''
            if ($field === 'due_date' && $val === '') {
                $data[$field] = null;
                $format[] = null;
            } else {
                $data[$field] = $val;
                $format[] = $fmt;
            }
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
    if (isset($request['form_answers'])) {
        rcmi_tickets_sync_form_answers($request['id'], $request['form_answers']);
        // Re-evaluate auto-tags when form answers change
        rcmi_tickets_apply_auto_tags((int) $request['id'], $request['form_answers']);
        // Sync due_date from reserved "Due Date" form field (if present)
        $form_due_date = rcmi_tickets_extract_due_date_from_answers($request['form_answers']);
        if ($form_due_date !== null) {
            $wpdb->update($wpdb->prefix . 'rcmi_tickets', ['due_date' => $form_due_date, 'updated_at' => $now], ['id' => (int) $request['id']], ['%s', '%s'], ['%d']);
        }
    }

    // Schema v3: resubmit path. If the ticket was rejected (restart or back_one)
    // and the requestor just edited it, restart the chain → status back to 'Pending Approval'.
    if ($ticket['status'] === 'Received' || $ticket['status'] === 'Rejected: Pending Revision') {
        $has_chain = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE ticket_id = %d",
            (int) $request['id']
        ));
        if ($has_chain) {
            rcmi_tickets_restart_ticket_approval_chain((int) $request['id']);
        }
    }

    // Fire status change action if status was updated
    if (isset($data['status']) && $ticket['status'] !== $data['status']) {
        do_action('rcmi_ticket_status_changed', (int) $request['id'], $data['status'], $ticket['status'], null);
    }

    $row = rcmi_tickets_load_ticket($request['id']);
    return new WP_REST_Response(rcmi_tickets_format_ticket($row), 200);
}

function rcmi_tickets_delete_ticket_data($ticket_id) {
    global $wpdb;
    $ticket_id = (int) $ticket_id;

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
    $wpdb->delete($wpdb->prefix . 'rcmi_form_answers', ['ticket_id' => $ticket_id], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_approvals', ['ticket_id' => $ticket_id], ['%d']);

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
}

function rcmi_tickets_handle_delete($request) {
    $ticket_id = (int) $request['id'];
    rcmi_tickets_delete_ticket_data($ticket_id);
    return new WP_REST_Response(['deleted' => true, 'id' => $ticket_id], 200);
}

function rcmi_tickets_handle_archive($request) {
    global $wpdb;
    $ticket_id = (int) $request['id'];
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    if ($ticket['status'] !== 'Completed') {
        return new WP_Error('rcmi_tickets_not_completed', 'Only completed tickets can be sent to Ticket Heaven.', ['status' => 409]);
    }
    $wpdb->update(
        $wpdb->prefix . 'rcmi_tickets',
        ['archived' => 1, 'updated_at' => current_time('mysql'), 'updated_by' => get_current_user_id()],
        ['id' => $ticket_id],
        ['%d', '%s', '%d'],
        ['%d']
    );
    return new WP_REST_Response(['archived' => true, 'id' => $ticket_id], 200);
}

function rcmi_tickets_handle_resurrect($request) {
    global $wpdb;
    $ticket_id = (int) $request['id'];
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    $wpdb->update(
        $wpdb->prefix . 'rcmi_tickets',
        ['archived' => 0, 'updated_at' => current_time('mysql'), 'updated_by' => get_current_user_id()],
        ['id' => $ticket_id],
        ['%d', '%s', '%d'],
        ['%d']
    );
    return new WP_REST_Response(['resurrected' => true, 'id' => $ticket_id], 200);
}

function rcmi_tickets_handle_batch_delete($request) {
    $ids = array_filter(array_map('intval', (array) $request['ids']));
    if (!$ids) {
        return new WP_Error('rcmi_tickets_no_ids', 'No ticket IDs provided.', ['status' => 400]);
    }

    $deleted = [];
    $skipped = [];
    foreach ($ids as $id) {
        $ticket = rcmi_tickets_load_ticket($id);
        if ($ticket && $ticket['status'] === 'Completed') {
            $skipped[] = $id;
            continue;
        }
        rcmi_tickets_delete_ticket_data($id);
        $deleted[] = $id;
    }

    return new WP_REST_Response([
        'deleted'  => true,
        'ids'      => $deleted,
        'count'    => count($deleted),
        'skipped'  => $skipped,
    ], 200);
}

function rcmi_tickets_handle_batch_archive($request) {
    global $wpdb;
    $ids = array_filter(array_map('intval', (array) $request['ids']));
    if (!$ids) {
        return new WP_Error('rcmi_tickets_no_ids', 'No ticket IDs provided.', ['status' => 400]);
    }

    $archived = [];
    $skipped = [];
    $now = current_time('mysql');
    $user_id = get_current_user_id();

    foreach ($ids as $id) {
        $ticket = rcmi_tickets_load_ticket($id);
        if (!$ticket || $ticket['status'] !== 'Completed') {
            $skipped[] = $id;
            continue;
        }
        $wpdb->update(
            $wpdb->prefix . 'rcmi_tickets',
            ['archived' => 1, 'updated_at' => $now, 'updated_by' => $user_id],
            ['id' => $id],
            ['%d', '%s', '%d'],
            ['%d']
        );
        $archived[] = $id;
    }

    return new WP_REST_Response([
        'archived' => true,
        'ids'      => $archived,
        'count'    => count($archived),
        'skipped'  => $skipped,
    ], 200);
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

/**
 * Handle assignee-only update (managers + current step approver).
 * Returns the updated ticket with new assignees.
 */
function rcmi_tickets_handle_assignee_update($request) {
    global $wpdb;
    $ticket_id = (int) $request['id'];
    $assignee_ids = isset($request['assignee_ids']) ? $request['assignee_ids'] : [];

    rcmi_tickets_sync_assignees($ticket_id, $assignee_ids);

    // Update the ticket's updated_at/updated_by timestamp
    $wpdb->update($wpdb->prefix . 'rcmi_tickets', [
        'updated_by'  => get_current_user_id(),
        'updated_at'  => current_time('mysql'),
    ], ['id' => $ticket_id], ['%d', '%s'], ['%d']);

    $row = rcmi_tickets_load_ticket($ticket_id);
    return new WP_REST_Response(rcmi_tickets_format_ticket($row), 200);
}
