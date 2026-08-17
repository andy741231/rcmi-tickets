<?php
/**
 * REST API controller for auto-tag rules.
 *
 * Endpoints:
 *   GET    /tag-rules          — list all rules (manage cap)
 *   POST   /tag-rules          — create a rule (manage cap)
 *   PUT    /tag-rules/{id}     — update a rule (manage cap)
 *   DELETE /tag-rules/{id}     — delete a rule (manage cap)
 *
 * Auto-tag evaluation is triggered on ticket create/update via
 * rcmi_tickets_apply_auto_tags(), which lives in this file.
 */

if (!defined('ABSPATH')) {
    exit;
}

function rcmi_tickets_register_tag_rule_routes() {
    $namespace = 'rcmi/v1';
    $valid_operators = ['equals', 'not_equals', 'contains', 'not_contains'];

    register_rest_route($namespace, '/tag-rules', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_tag_rule_list',
            'permission_callback' => 'rcmi_tickets_perm_tag_rule_manage',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_tag_rule_create',
            'permission_callback' => 'rcmi_tickets_perm_tag_rule_manage',
            'args'                => [
                'field_key'  => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'operator'   => ['type' => 'string', 'required' => true, 'validate_callback' => function ($v) use ($valid_operators) { return in_array($v, $valid_operators, true); }],
                'value'      => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'tag_name'   => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'is_active'  => ['type' => 'boolean', 'default' => true],
            ],
        ],
    ]);

    register_rest_route($namespace, '/tag-rules/(?P<id>\d+)', [
        [
            'methods'             => 'PUT',
            'callback'            => 'rcmi_tickets_handle_tag_rule_update',
            'permission_callback' => 'rcmi_tickets_perm_tag_rule_manage',
            'args'                => [
                'id'         => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'field_key'  => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'operator'   => ['type' => 'string', 'validate_callback' => function ($v) use ($valid_operators) { return in_array($v, $valid_operators, true); }],
                'value'      => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'tag_name'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'is_active'  => ['type' => 'boolean'],
            ],
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'rcmi_tickets_handle_tag_rule_delete',
            'permission_callback' => 'rcmi_tickets_perm_tag_rule_manage',
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
            ],
        ],
    ]);
}
add_action('rest_api_init', 'rcmi_tickets_register_tag_rule_routes');

// ── permission callbacks ─────────────────────────────────────────────

function rcmi_tickets_perm_tag_rule_manage() {
    return rcmi_tickets_can(get_current_user_id(), 'manage');
}

// ── handlers ─────────────────────────────────────────────────────────

function rcmi_tickets_handle_tag_rule_list() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}rcmi_tag_rules ORDER BY created_at DESC"
    , ARRAY_A);

    return new WP_REST_Response(array_map('rcmi_tickets_format_tag_rule', $rows), 200);
}

function rcmi_tickets_handle_tag_rule_create($request) {
    global $wpdb;
    $now = current_time('mysql');

    $wpdb->insert($wpdb->prefix . 'rcmi_tag_rules', [
        'field_key' => $request['field_key'],
        'operator'  => $request['operator'],
        'value'     => $request['value'],
        'tag_name'  => $request['tag_name'],
        'is_active' => !empty($request['is_active']) ? 1 : 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], ['%s', '%s', '%s', '%s', '%d', '%s', '%s']);

    $id = (int) $wpdb->insert_id;
    if (!$id) {
        return new WP_Error('rcmi_tag_rule_create_failed', 'Failed to create tag rule.', ['status' => 500]);
    }

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_tag_rules WHERE id = %d", $id
    ), ARRAY_A);

    return new WP_REST_Response(rcmi_tickets_format_tag_rule($row), 201);
}

function rcmi_tickets_handle_tag_rule_update($request) {
    global $wpdb;
    $id = (int) $request['id'];

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_tag_rules WHERE id = %d", $id
    ), ARRAY_A);
    if (!$existing) {
        return new WP_Error('rcmi_tag_rule_not_found', 'Tag rule not found.', ['status' => 404]);
    }

    $data = [];
    $format = [];

    foreach (['field_key', 'operator', 'value', 'tag_name'] as $field) {
        if (isset($request[$field])) {
            $data[$field] = $request[$field];
            $format[] = '%s';
        }
    }
    if (isset($request['is_active'])) {
        $data['is_active'] = !empty($request['is_active']) ? 1 : 0;
        $format[] = '%d';
    }

    if ($data) {
        $data['updated_at'] = current_time('mysql');
        $format[] = '%s';
        $wpdb->update($wpdb->prefix . 'rcmi_tag_rules', $data, ['id' => $id], $format, ['%d']);
    }

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_tag_rules WHERE id = %d", $id
    ), ARRAY_A);

    return new WP_REST_Response(rcmi_tickets_format_tag_rule($row), 200);
}

function rcmi_tickets_handle_tag_rule_delete($request) {
    global $wpdb;
    $id = (int) $request['id'];

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rcmi_tag_rules WHERE id = %d", $id
    ));
    if (!$existing) {
        return new WP_Error('rcmi_tag_rule_not_found', 'Tag rule not found.', ['status' => 404]);
    }

    $wpdb->delete($wpdb->prefix . 'rcmi_tag_rules', ['id' => $id], ['%d']);

    return new WP_REST_Response(['deleted' => true, 'id' => $id], 200);
}

// ── helpers ──────────────────────────────────────────────────────────

function rcmi_tickets_format_tag_rule($row) {
    return [
        'id'         => (int) $row['id'],
        'field_key'  => $row['field_key'],
        'operator'   => $row['operator'],
        'value'      => $row['value'],
        'tag_name'   => $row['tag_name'],
        'is_active'  => (bool) (int) $row['is_active'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

/**
 * Evaluate all active tag rules against a ticket's form answers and
 * return the set of tag names that should be auto-applied.
 *
 * @param array $form_answers field_key => value
 * @return string[] tag names to apply
 */
function rcmi_tickets_evaluate_auto_tags(array $form_answers) {
    global $wpdb;
    $rules = $wpdb->get_results(
        "SELECT field_key, operator, value, tag_name
         FROM {$wpdb->prefix}rcmi_tag_rules
         WHERE is_active = 1"
    , ARRAY_A);

    if (!$rules) {
        return [];
    }

    $tag_names = [];
    foreach ($rules as $rule) {
        $field_key = $rule['field_key'];
        $answer = $form_answers[$field_key] ?? null;
        $rule_value = $rule['value'];
        $matched = false;

        switch ($rule['operator']) {
            case 'equals':
                $matched = is_array($answer)
                    ? in_array($rule_value, $answer, true)
                    : (string) $answer === $rule_value;
                break;
            case 'not_equals':
                $matched = is_array($answer)
                    ? !in_array($rule_value, $answer, true)
                    : (string) $answer !== $rule_value;
                break;
            case 'contains':
                $matched = is_array($answer)
                    ? in_array($rule_value, $answer, true)
                    : strpos((string) $answer, $rule_value) !== false;
                break;
            case 'not_contains':
                $matched = is_array($answer)
                    ? !in_array($rule_value, $answer, true)
                    : strpos((string) $answer, $rule_value) === false;
                break;
        }

        if ($matched) {
            $tag_names[] = $rule['tag_name'];
        }
    }

    return array_values(array_unique($tag_names));
}

/**
 * Apply auto-tags to a ticket. Merges with any existing manually-set tags
 * so that manual tags are preserved. Creates tags on the fly if they don't
 * exist yet (via rcmi_tickets_tag_ids_from_names).
 *
 * @param int   $ticket_id
 * @param array $form_answers field_key => value
 */
function rcmi_tickets_apply_auto_tags($ticket_id, array $form_answers) {
    $auto_tag_names = rcmi_tickets_evaluate_auto_tags($form_answers);
    if (empty($auto_tag_names)) {
        return;
    }

    global $wpdb;

    // Get existing tag IDs for this ticket
    $existing_tag_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT tag_id FROM {$wpdb->prefix}rcmi_ticket_tag_map WHERE ticket_id = %d",
        (int) $ticket_id
    ));
    $existing_tag_ids = array_map('intval', $existing_tag_ids);

    // Create / resolve auto-tag IDs (allow_create=true because tag names
    // come from manager-configured rules, not user input)
    $auto_tag_ids = rcmi_tickets_tag_ids_from_names($auto_tag_names, true);

    // Merge: add auto-tag IDs that aren't already mapped
    $to_add = array_diff($auto_tag_ids, $existing_tag_ids);
    foreach ($to_add as $tid) {
        $wpdb->insert($wpdb->prefix . 'rcmi_ticket_tag_map', [
            'ticket_id' => (int) $ticket_id,
            'tag_id'    => (int) $tid,
        ], ['%d', '%d']);
    }
}
