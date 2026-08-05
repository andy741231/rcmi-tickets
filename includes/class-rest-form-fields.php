<?php
/**
 * REST API controller for dynamic form fields (schema v3).
 *
 * Endpoints:
 *   GET    /form-fields           — list all fields, sorted by sort_order
 *   POST   /form-fields           — create a field
 *   PUT    /form-fields/{id}      — update a field
 *   DELETE /form-fields/{id}      — delete a field (+ cascade answers)
 *   PUT    /form-fields/reorder   — bulk reorder (array of ids)
 *
 * Field types: text|longtext|dropdown|checkbox|radio|date|number|section
 * config JSON shape (validated by type):
 *   {
 *     options: ["a","b"],            // dropdown/radio/checkbox
 *     placeholder: "…",              // text/longtext
 *     default: "…",                  // any
 *     logic: { field_key, op, value, action: 'show'|'hide' },
 *     cascades_from: "<field_key>",  // dropdown only
 *     cascade_options: { "<parent_value>": ["Sub A","Sub B"] }
 *   }
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Valid field types.
 */
function rcmi_tickets_valid_field_types() {
    return ['text', 'longtext', 'dropdown', 'checkbox', 'radio', 'date', 'number', 'section'];
}

/**
 * Allowed config keys per field type.
 */
function rcmi_tickets_allowed_config_keys($type) {
    $common = ['default', 'logic'];
    $map = [
        'text'      => array_merge($common, ['placeholder']),
        'longtext'  => array_merge($common, ['placeholder']),
        'dropdown'  => array_merge($common, ['options', 'cascades_from', 'cascade_options']),
        'checkbox'  => array_merge($common, ['options']),
        'radio'     => array_merge($common, ['options']),
        'date'      => $common,
        'number'    => array_merge($common, ['placeholder', 'min', 'max', 'step']),
        'section'   => [],
    ];
    return $map[$type] ?? $common;
}

/**
 * Validate and sanitize a field config array based on its type.
 *
 * @param array|null $config
 * @param string     $type
 * @return array|null Clean config, or null if input is empty.
 */
function rcmi_tickets_validate_field_config($config, $type) {
    if (!is_array($config)) {
        return null;
    }
    $allowed = rcmi_tickets_allowed_config_keys($type);
    $clean = [];

    foreach ($config as $k => $v) {
        if (!in_array($k, $allowed, true)) {
            continue;
        }
        switch ($k) {
            case 'options':
                if (is_array($v)) {
                    $clean['options'] = array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $v))));
                }
                break;
            case 'cascade_options':
                if (is_array($v)) {
                    $co = new stdClass(); // force JSON object, never []
                    foreach ($v as $pv => $subs) {
                        $pv = sanitize_text_field((string) $pv);
                        if (is_array($subs)) {
                            $co->{$pv} = array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $subs))));
                        }
                    }
                    $clean['cascade_options'] = $co;
                }
                break;
            case 'cascades_from':
                $clean['cascades_from'] = sanitize_text_field((string) $v);
                break;
            case 'placeholder':
            case 'default':
                $clean[$k] = sanitize_text_field((string) $v);
                break;
            case 'min':
            case 'max':
            case 'step':
                $clean[$k] = is_numeric($v) ? 0 + $v : null;
                break;
            case 'logic':
                if (is_array($v) && isset($v['field_key'], $v['op'], $v['value'], $v['action'])) {
                    $clean['logic'] = [
                        'field_key' => sanitize_text_field((string) $v['field_key']),
                        'op'        => in_array($v['op'], ['equals', 'not_equals', 'contains', 'not_contains'], true) ? $v['op'] : 'equals',
                        'value'     => sanitize_text_field((string) $v['value']),
                        'action'    => in_array($v['action'], ['show', 'hide'], true) ? $v['action'] : 'show',
                    ];
                }
                break;
        }
    }
    return $clean ?: null;
}

function rcmi_tickets_register_form_field_routes() {
    $namespace = 'rcmi/v1';

    register_rest_route($namespace, '/form-fields', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_form_fields_list',
            'permission_callback' => 'rcmi_tickets_perm_form_fields_read',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_form_field_create',
            'permission_callback' => 'rcmi_tickets_perm_form_fields_write',
            'args'                => rcmi_tickets_form_field_write_args(),
        ],
    ]);

    register_rest_route($namespace, '/form-fields/reorder', [
        [
            'methods'             => 'PUT',
            'callback'            => 'rcmi_tickets_handle_form_fields_reorder',
            'permission_callback' => 'rcmi_tickets_perm_form_fields_write',
            'args'                => [
                'ids' => ['type' => 'array', 'required' => true, 'items' => ['type' => 'integer']],
            ],
        ],
    ]);

    register_rest_route($namespace, '/form-fields/(?P<id>\d+)', [
        [
            'methods'             => 'PUT',
            'callback'            => 'rcmi_tickets_handle_form_field_update',
            'permission_callback' => 'rcmi_tickets_perm_form_fields_write',
            'args'                => rcmi_tickets_form_field_write_args(true),
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'rcmi_tickets_handle_form_field_delete',
            'permission_callback' => 'rcmi_tickets_perm_form_fields_write',
            'args'                => ['id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int']],
        ],
    ]);
}
add_action('rest_api_init', 'rcmi_tickets_register_form_field_routes');

function rcmi_tickets_perm_form_fields_read() {
    return rcmi_tickets_can(get_current_user_id(), 'view_any');
}

function rcmi_tickets_perm_form_fields_write() {
    return rcmi_tickets_can(get_current_user_id(), 'manage');
}

function rcmi_tickets_form_field_write_args($is_update = false) {
    $args = [
        'field_key'  => ['type' => 'string', 'required' => !$is_update, 'sanitize_callback' => 'sanitize_key'],
        'label'      => ['type' => 'string', 'required' => !$is_update, 'sanitize_callback' => 'sanitize_text_field'],
        'type'       => ['type' => 'string', 'required' => !$is_update, 'validate_callback' => function ($v) { return in_array($v, rcmi_tickets_valid_field_types(), true); }],
        'required'   => ['type' => 'boolean', 'default' => false],
        'config'     => ['type' => 'object'],
    ];
    return $args;
}

// ── helpers ──────────────────────────────────────────────────────────

function rcmi_tickets_load_form_field($id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_form_fields WHERE id = %d",
        (int) $id
    ), ARRAY_A);
    if (!$row) {
        return null;
    }
    return rcmi_tickets_format_form_field($row);
}

function rcmi_tickets_format_form_field($row) {
    $config = $row['config'] ? json_decode($row['config'], true) : null;
    if (is_array($config)) {
        // Force cascade_options to be a JSON object ({}), never an array ([]).
        // json_decode(..., true) converts {} to [] which then serializes back
        // as [] — breaking the frontend which expects an object.
        if (isset($config['cascade_options']) && is_array($config['cascade_options']) && empty($config['cascade_options'])) {
            $config['cascade_options'] = new stdClass();
        }
    }
    return [
        'id'         => (int) $row['id'],
        'field_key'  => $row['field_key'],
        'label'      => $row['label'],
        'type'       => $row['type'],
        'required'   => (bool) $row['required'],
        'sort_order' => (int) $row['sort_order'],
        'config'     => is_array($config) ? $config : new stdClass(),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function rcmi_tickets_get_all_form_fields() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}rcmi_form_fields ORDER BY sort_order ASC, id ASC"
    , ARRAY_A);
    return array_map('rcmi_tickets_format_form_field', $rows);
}

// ── handlers ─────────────────────────────────────────────────────────

function rcmi_tickets_handle_form_fields_list() {
    return new WP_REST_Response(rcmi_tickets_get_all_form_fields(), 200);
}

function rcmi_tickets_handle_form_field_create($request) {
    global $wpdb;
    $now = current_time('mysql');

    $field_key = $request['field_key'];
    $label = $request['label'];
    $type = $request['type'];
    $required = !empty($request['required']);
    $config = rcmi_tickets_validate_field_config($request['config'], $type);

    // Uniqueness check on field_key
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rcmi_form_fields WHERE field_key = %s",
        $field_key
    ));
    if ($exists) {
        return new WP_Error('rcmi_tickets_field_key_exists', 'Field key already exists.', ['status' => 409]);
    }

    // Always assign sort_order to end of the list
    $max = (int) $wpdb->get_var("SELECT MAX(sort_order) FROM {$wpdb->prefix}rcmi_form_fields");
    $sort_order = $max + 1;

    $wpdb->insert($wpdb->prefix . 'rcmi_form_fields', [
        'field_key'  => $field_key,
        'label'      => $label,
        'type'       => $type,
        'required'   => $required ? 1 : 0,
        'sort_order' => $sort_order,
        'config'     => $config ? wp_json_encode($config) : null,
        'created_at' => $now,
        'updated_at' => $now,
    ], ['%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);

    $id = (int) $wpdb->insert_id;
    if (!$id) {
        return new WP_Error('rcmi_tickets_field_create_failed', 'Failed to create field.', ['status' => 500]);
    }

    return new WP_REST_Response(rcmi_tickets_load_form_field($id), 201);
}

function rcmi_tickets_handle_form_field_update($request) {
    global $wpdb;
    $id = (int) $request['id'];
    $existing = rcmi_tickets_load_form_field($id);
    if (!$existing) {
        return new WP_Error('rcmi_tickets_field_not_found', 'Field not found.', ['status' => 404]);
    }

    $data = [];
    $format = [];

    if (isset($request['field_key'])) {
        $new_key = sanitize_key($request['field_key']);
        if ($new_key !== $existing['field_key']) {
            $conflict = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rcmi_form_fields WHERE field_key = %s AND id != %d",
                $new_key, $id
            ));
            if ($conflict) {
                return new WP_Error('rcmi_tickets_field_key_exists', 'Field key already exists.', ['status' => 409]);
            }
        }
        $data['field_key'] = $new_key;
        $format[] = '%s';
    }
    if (isset($request['label'])) {
        $data['label'] = sanitize_text_field($request['label']);
        $format[] = '%s';
    }
    if (isset($request['type'])) {
        $data['type'] = $request['type'];
        $format[] = '%s';
    }
    if (isset($request['required'])) {
        $data['required'] = !empty($request['required']) ? 1 : 0;
        $format[] = '%d';
    }
    if (isset($request['sort_order'])) {
        $data['sort_order'] = (int) $request['sort_order'];
        $format[] = '%d';
    }
    if (isset($request['config'])) {
        $type = $data['type'] ?? $existing['type'];
        $config = rcmi_tickets_validate_field_config($request['config'], $type);
        $data['config'] = $config ? wp_json_encode($config) : null;
        $format[] = '%s';
    }

    if ($data) {
        $data['updated_at'] = current_time('mysql');
        $format[] = '%s';
        $wpdb->update($wpdb->prefix . 'rcmi_form_fields', $data, ['id' => $id], $format, ['%d']);
    }

    return new WP_REST_Response(rcmi_tickets_load_form_field($id), 200);
}

function rcmi_tickets_handle_form_field_delete($request) {
    global $wpdb;
    $id = (int) $request['id'];

    // Cascade: delete answers for this field
    $wpdb->delete($wpdb->prefix . 'rcmi_form_answers', ['field_id' => $id], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_form_fields', ['id' => $id], ['%d']);

    return new WP_REST_Response(['deleted' => true, 'id' => $id], 200);
}

function rcmi_tickets_handle_form_fields_reorder($request) {
    global $wpdb;
    $ids = array_filter(array_map('intval', (array) $request['ids']));
    if (!$ids) {
        return new WP_Error('rcmi_tickets_no_ids', 'No ids provided.', ['status' => 400]);
    }

    $order = 1;
    foreach ($ids as $id) {
        $wpdb->update(
            $wpdb->prefix . 'rcmi_form_fields',
            ['sort_order' => $order, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
        $order++;
    }

    return new WP_REST_Response(['reordered' => true, 'ids' => $ids], 200);
}
