<?php
/**
 * REST API controller for approval chains (schema v3).
 *
 * Endpoints:
 *   GET    /approval-chains           — list all chains + steps
 *   POST   /approval-chains           — create chain + steps
 *   PUT    /approval-chains/{id}      — update chain + steps (steps replaced)
 *   DELETE /approval-chains/{id}      — delete chain (+ cascade steps; ticket_approvals kept for history)
 *
 * Chain shape:
 *   {
 *     name, description, trigger_field_key, trigger_value, on_reject, is_active,
 *     steps: [
 *       { name, approver_type: 'user'|'role', approver_user_id, approver_role, sort_order }
 *     ]
 *   }
 *
 * on_reject: 'restart' | 'back_one' | 'terminal'
 *   - restart:  reject → status Received, chain reset to step 1, requestor can edit/resubmit
 *   - back_one: reject → status stays Pending Approval, chain back one step
 *   - terminal: reject → status Rejected (terminal), chain closed
 */

if (!defined('ABSPATH')) {
    exit;
}

function rcmi_tickets_valid_on_reject_modes() {
    return ['restart', 'back_one', 'terminal'];
}

function rcmi_tickets_valid_approver_types() {
    return ['user', 'role'];
}

function rcmi_tickets_register_approval_chain_routes() {
    $namespace = 'rcmi/v1';

    register_rest_route($namespace, '/approval-chains', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_approval_chains_list',
            'permission_callback' => 'rcmi_tickets_perm_approval_chains_read',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_approval_chain_create',
            'permission_callback' => 'rcmi_tickets_perm_approval_chains_write',
            'args'                => rcmi_tickets_approval_chain_write_args(),
        ],
    ]);

    register_rest_route($namespace, '/approval-chains/(?P<id>\d+)', [
        [
            'methods'             => 'PUT',
            'callback'            => 'rcmi_tickets_handle_approval_chain_update',
            'permission_callback' => 'rcmi_tickets_perm_approval_chains_write',
            'args'                => rcmi_tickets_approval_chain_write_args(true),
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'rcmi_tickets_handle_approval_chain_delete',
            'permission_callback' => 'rcmi_tickets_perm_approval_chains_write',
            'args'                => ['id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int']],
        ],
    ]);
}
add_action('rest_api_init', 'rcmi_tickets_register_approval_chain_routes');

function rcmi_tickets_perm_approval_chains_read() {
    return rcmi_tickets_can(get_current_user_id(), 'view_any');
}

function rcmi_tickets_perm_approval_chains_write() {
    return rcmi_tickets_can(get_current_user_id(), 'manage');
}

function rcmi_tickets_approval_chain_write_args($is_update = false) {
    return [
        'name'                => ['type' => 'string', 'required' => !$is_update, 'sanitize_callback' => 'sanitize_text_field'],
        'description'         => ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
        'trigger_field_key'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
        'trigger_value'       => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
        'on_reject'           => ['type' => 'string', 'default' => 'restart', 'validate_callback' => function ($v) { return in_array($v, rcmi_tickets_valid_on_reject_modes(), true); }],
        'completion_message'     => ['type' => 'string', 'sanitize_callback' => 'rcmi_tickets_sanitize_completion_message'],
        'completion_assignee_id' => ['type' => 'integer', 'validate_callback' => function ($v) { return !(int) $v || (bool) get_userdata((int) $v); }],
        'is_active'              => ['type' => 'boolean', 'default' => true],
        'steps'               => ['type' => 'array', 'required' => !$is_update],
    ];
}

// ── helpers ──────────────────────────────────────────────────────────

/**
 * Sanitize the completion message HTML from the RichTextEditor.
 * Allows a safe subset of tags (p, br, strong, em, a, ul, ol, li)
 * matching what the email renderer permits.
 */
function rcmi_tickets_sanitize_completion_message($value) {
    if (!is_string($value) || $value === '') {
        return '';
    }
    $allowed = [
        'p'      => [],
        'br'     => [],
        'strong' => [],
        'em'     => [],
        'b'      => [],
        'i'      => [],
        'a'      => ['href' => true, 'title' => true],
        'ul'     => [],
        'ol'     => [],
        'li'     => [],
    ];
    return wp_kses($value, $allowed);
}

function rcmi_tickets_load_approval_chain($id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_approval_chains WHERE id = %d",
        (int) $id
    ), ARRAY_A);
    if (!$row) {
        return null;
    }
    return rcmi_tickets_format_approval_chain($row);
}

function rcmi_tickets_format_approval_chain($row) {
    global $wpdb;
    $steps = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_approval_steps WHERE chain_id = %d ORDER BY sort_order ASC, id ASC",
        (int) $row['id']
    ), ARRAY_A);

    $formatted_steps = array_map(function ($s) {
        return [
            'id'               => (int) $s['id'],
            'chain_id'         => (int) $s['chain_id'],
            'sort_order'       => (int) $s['sort_order'],
            'approver_type'    => $s['approver_type'],
            'approver_user_id' => $s['approver_user_id'] !== null ? (int) $s['approver_user_id'] : null,
            'approver_role'    => $s['approver_role'],
            'name'             => $s['name'],
        ];
    }, $steps);

    return [
        'id'                  => (int) $row['id'],
        'name'                => $row['name'],
        'description'         => $row['description'],
        'trigger_field_key'   => $row['trigger_field_key'],
        'trigger_value'       => $row['trigger_value'],
        'on_reject'           => $row['on_reject'],
        'completion_message'     => $row['completion_message'] ?? '',
        'completion_assignee_id' => $row['completion_assignee_id'] !== null ? (int) $row['completion_assignee_id'] : null,
        'is_active'              => (bool) $row['is_active'],
        'steps'               => $formatted_steps,
        'created_at'          => $row['created_at'],
        'updated_at'          => $row['updated_at'],
    ];
}

function rcmi_tickets_get_all_approval_chains() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}rcmi_approval_chains ORDER BY id ASC"
    , ARRAY_A);
    return array_map('rcmi_tickets_format_approval_chain', $rows);
}

/**
 * Validate a steps array. Each step must have approver_type and exactly
 * one of approver_user_id / approver_role populated.
 *
 * @param array $steps
 * @return WP_Error|null Null if valid, WP_Error otherwise.
 */
function rcmi_tickets_validate_chain_steps($steps) {
    if (!is_array($steps) || count($steps) === 0) {
        return new WP_Error('rcmi_tickets_no_steps', 'At least one step is required.', ['status' => 400]);
    }
    foreach ($steps as $i => $step) {
        if (!is_array($step)) {
            return new WP_Error('rcmi_tickets_step_invalid', "Step {$i} is invalid.", ['status' => 400]);
        }
        $type = $step['approver_type'] ?? '';
        if (!in_array($type, rcmi_tickets_valid_approver_types(), true)) {
            return new WP_Error('rcmi_tickets_step_type', "Step {$i}: invalid approver_type.", ['status' => 400]);
        }
        $uid = isset($step['approver_user_id']) ? (int) $step['approver_user_id'] : null;
        $role = $step['approver_role'] ?? '';
        if ($type === 'user') {
            if (!$uid || !get_userdata($uid)) {
                return new WP_Error('rcmi_tickets_step_user', "Step {$i}: invalid approver_user_id.", ['status' => 400]);
            }
        } else { // role
            if (empty($role)) {
                return new WP_Error('rcmi_tickets_step_role', "Step {$i}: approver_role required.", ['status' => 400]);
            }
        }
    }
    return null;
}

/**
 * Replace all steps for a chain (delete + insert).
 */
function rcmi_tickets_replace_chain_steps($chain_id, $steps) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'rcmi_approval_steps', ['chain_id' => (int) $chain_id], ['%d']);

    $order = 1;
    foreach ($steps as $step) {
        $type = $step['approver_type'];
        $wpdb->insert($wpdb->prefix . 'rcmi_approval_steps', [
            'chain_id'         => (int) $chain_id,
            'sort_order'       => $order,
            'approver_type'    => $type,
            'approver_user_id' => $type === 'user' ? (int) $step['approver_user_id'] : null,
            'approver_role'    => $type === 'role' ? sanitize_text_field((string) ($step['approver_role'] ?? '')) : null,
            'name'             => sanitize_text_field((string) ($step['name'] ?? '')),
        ], ['%d', '%d', '%s', '%d', '%s', '%s']);
        $order++;
    }
}

// ── handlers ─────────────────────────────────────────────────────────

function rcmi_tickets_handle_approval_chains_list() {
    return new WP_REST_Response(rcmi_tickets_get_all_approval_chains(), 200);
}

function rcmi_tickets_handle_approval_chain_create($request) {
    global $wpdb;
    $now = current_time('mysql');

    $steps = $request['steps'];
    $err = rcmi_tickets_validate_chain_steps($steps);
    if ($err) {
        return $err;
    }

    $name = $request['name'];
    $description = $request['description'] ?? '';
    $trigger_field_key = $request['trigger_field_key'] ?? null;
    $trigger_value = $request['trigger_value'] ?? null;
    $on_reject = $request['on_reject'] ?? 'restart';
    $completion_message = $request['completion_message'] ?? '';
    $completion_assignee_id = !empty($request['completion_assignee_id']) ? (int) $request['completion_assignee_id'] : null;
    $is_active = !empty($request['is_active']);

    $wpdb->insert($wpdb->prefix . 'rcmi_approval_chains', [
        'name'                   => $name,
        'description'            => $description,
        'trigger_field_key'      => $trigger_field_key ?: null,
        'trigger_value'          => $trigger_value ?: null,
        'on_reject'              => $on_reject,
        'completion_message'     => $completion_message ?: null,
        'completion_assignee_id' => $completion_assignee_id,
        'is_active'              => $is_active ? 1 : 0,
        'created_at'             => $now,
        'updated_at'             => $now,
    ], ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);

    $id = (int) $wpdb->insert_id;
    if (!$id) {
        return new WP_Error('rcmi_tickets_chain_create_failed', 'Failed to create chain.', ['status' => 500]);
    }

    rcmi_tickets_replace_chain_steps($id, $steps);

    return new WP_REST_Response(rcmi_tickets_load_approval_chain($id), 201);
}

function rcmi_tickets_handle_approval_chain_update($request) {
    global $wpdb;
    $id = (int) $request['id'];
    $existing = rcmi_tickets_load_approval_chain($id);
    if (!$existing) {
        return new WP_Error('rcmi_tickets_chain_not_found', 'Chain not found.', ['status' => 404]);
    }

    $data = [];
    $format = [];

    if (isset($request['name'])) {
        $data['name'] = sanitize_text_field($request['name']);
        $format[] = '%s';
    }
    if (isset($request['description'])) {
        $data['description'] = sanitize_textarea_field($request['description']);
        $format[] = '%s';
    }
    if (isset($request['trigger_field_key'])) {
        $data['trigger_field_key'] = $request['trigger_field_key'] ?: null;
        $format[] = '%s';
    }
    if (isset($request['trigger_value'])) {
        $data['trigger_value'] = $request['trigger_value'] ?: null;
        $format[] = '%s';
    }
    if (isset($request['on_reject'])) {
        if (!in_array($request['on_reject'], rcmi_tickets_valid_on_reject_modes(), true)) {
            return new WP_Error('rcmi_tickets_bad_on_reject', 'Invalid on_reject value.', ['status' => 400]);
        }
        $data['on_reject'] = $request['on_reject'];
        $format[] = '%s';
    }
    if (isset($request['completion_message'])) {
        $data['completion_message'] = $request['completion_message'] ?: null;
        $format[] = '%s';
    }
    if (isset($request['completion_assignee_id'])) {
        $assignee_id = (int) $request['completion_assignee_id'];
        if ($assignee_id && !get_userdata($assignee_id)) {
            return new WP_Error('rcmi_tickets_bad_completion_assignee', 'Invalid completion assignee.', ['status' => 400]);
        }
        $data['completion_assignee_id'] = $assignee_id ?: null;
        $format[] = '%d';
    }
    if (isset($request['is_active'])) {
        $data['is_active'] = !empty($request['is_active']) ? 1 : 0;
        $format[] = '%d';
    }

    if ($data) {
        $data['updated_at'] = current_time('mysql');
        $format[] = '%s';
        $wpdb->update($wpdb->prefix . 'rcmi_approval_chains', $data, ['id' => $id], $format, ['%d']);
    }

    // Replace steps if provided
    if (isset($request['steps'])) {
        $err = rcmi_tickets_validate_chain_steps($request['steps']);
        if ($err) {
            return $err;
        }
        rcmi_tickets_replace_chain_steps($id, $request['steps']);
    }

    return new WP_REST_Response(rcmi_tickets_load_approval_chain($id), 200);
}

function rcmi_tickets_handle_approval_chain_delete($request) {
    global $wpdb;
    $id = (int) $request['id'];

    // Cascade: delete steps. ticket_approvals rows are kept for history
    // (they reference chain_id but the chain is gone — UI should handle null chain).
    $wpdb->delete($wpdb->prefix . 'rcmi_approval_steps', ['chain_id' => $id], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_approval_chains', ['id' => $id], ['%d']);

    return new WP_REST_Response(['deleted' => true, 'id' => $id], 200);
}
