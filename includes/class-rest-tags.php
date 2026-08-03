<?php
/**
 * REST API controller for tags (ticket-plan.md §5 tag rows).
 *
 * Endpoints:
 *   GET    /tags       — list all tags (view cap)
 *   POST   /tags       — create a tag (manage cap)
 *   PUT    /tags/{id}  — rename a tag (manage cap)
 *   DELETE /tags/{id}  — delete a tag + its mappings (manage cap)
 *
 * Also exposes rcmi_tickets_tag_ids_from_names() — a shared helper that
 * creates tags on the fly and returns their IDs. Used by Task 4's ticket
 * create/update flows when tag names (instead of IDs) are provided.
 */

if (!defined('ABSPATH')) {
    exit;
}

function rcmi_tickets_register_tag_routes() {
    $namespace = 'rcmi/v1';

    register_rest_route($namespace, '/tags', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_tag_list',
            'permission_callback' => 'rcmi_tickets_perm_tag_view',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_tag_create',
            'permission_callback' => 'rcmi_tickets_perm_tag_manage',
            'args'                => [
                'name' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ],
    ]);

    register_rest_route($namespace, '/tags/(?P<id>\d+)', [
        [
            'methods'             => 'PUT',
            'callback'            => 'rcmi_tickets_handle_tag_update',
            'permission_callback' => 'rcmi_tickets_perm_tag_manage',
            'args'                => [
                'id'   => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'name' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'rcmi_tickets_handle_tag_delete',
            'permission_callback' => 'rcmi_tickets_perm_tag_manage',
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
            ],
        ],
    ]);
}
add_action('rest_api_init', 'rcmi_tickets_register_tag_routes');

// ── permission callbacks ─────────────────────────────────────────────

function rcmi_tickets_perm_tag_view() {
    return rcmi_tickets_can(get_current_user_id(), 'view_any');
}

function rcmi_tickets_perm_tag_manage() {
    return rcmi_tickets_can(get_current_user_id(), 'manage');
}

// ── handlers ─────────────────────────────────────────────────────────

function rcmi_tickets_handle_tag_list() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT id, name, slug FROM {$wpdb->prefix}rcmi_ticket_tags ORDER BY name"
    , ARRAY_A);

    return new WP_REST_Response(array_map(function ($r) {
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'slug' => $r['slug']];
    }, $rows), 200);
}

function rcmi_tickets_handle_tag_create($request) {
    global $wpdb;
    $name = trim($request['name']);
    if ($name === '') {
        return new WP_Error('rcmi_tickets_tag_empty', 'Tag name cannot be empty.', ['status' => 400]);
    }

    // Check for duplicate name
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rcmi_ticket_tags WHERE name = %s",
        $name
    ));
    if ($existing) {
        return new WP_Error('rcmi_tickets_tag_duplicate', 'A tag with this name already exists.', ['status' => 409]);
    }

    $slug = rcmi_tickets_tag_slug($name);

    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_tags', [
        'name' => $name,
        'slug' => $slug,
    ], ['%s', '%s']);

    $id = (int) $wpdb->insert_id;
    if (!$id) {
        return new WP_Error('rcmi_tickets_tag_create_failed', 'Failed to create tag.', ['status' => 500]);
    }

    return new WP_REST_Response(['id' => $id, 'name' => $name, 'slug' => $slug], 201);
}

function rcmi_tickets_handle_tag_update($request) {
    global $wpdb;
    $id = (int) $request['id'];
    $name = trim($request['name']);
    if ($name === '') {
        return new WP_Error('rcmi_tickets_tag_empty', 'Tag name cannot be empty.', ['status' => 400]);
    }

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rcmi_ticket_tags WHERE id = %d",
        $id
    ), ARRAY_A);
    if (!$existing) {
        return new WP_Error('rcmi_tickets_tag_not_found', 'Tag not found.', ['status' => 404]);
    }

    // Check for duplicate name (excluding this tag)
    $dup = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rcmi_ticket_tags WHERE name = %s AND id != %d",
        $name, $id
    ));
    if ($dup) {
        return new WP_Error('rcmi_tickets_tag_duplicate', 'A tag with this name already exists.', ['status' => 409]);
    }

    $slug = rcmi_tickets_tag_slug($name);
    $wpdb->update($wpdb->prefix . 'rcmi_ticket_tags', [
        'name' => $name,
        'slug' => $slug,
    ], ['id' => $id], ['%s', '%s'], ['%d']);

    return new WP_REST_Response(['id' => $id, 'name' => $name, 'slug' => $slug], 200);
}

function rcmi_tickets_handle_tag_delete($request) {
    global $wpdb;
    $id = (int) $request['id'];

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rcmi_ticket_tags WHERE id = %d",
        $id
    ));
    if (!$existing) {
        return new WP_Error('rcmi_tickets_tag_not_found', 'Tag not found.', ['status' => 404]);
    }

    // Delete mappings first, then the tag
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_tag_map', ['tag_id' => $id], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_tags', ['id' => $id], ['%d']);

    return new WP_REST_Response(['deleted' => true, 'id' => $id], 200);
}

// ── shared helper ────────────────────────────────────────────────────

/**
 * Generate a unique slug for a tag name.
 * Falls back to name-{id} if the slug already exists.
 *
 * @param string $name
 * @return string
 */
function rcmi_tickets_tag_slug($name) {
    global $wpdb;
    $base = sanitize_title($name);
    if ($base === '') {
        $base = 'tag';
    }
    $slug = $base;
    $i = 1;
    while ($wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rcmi_ticket_tags WHERE slug = %s",
        $slug
    ))) {
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

/**
 * Create tags on the fly from an array of names and return their IDs.
 * Existing tags (matched by name) are reused. Used by ticket create/update
 * when the frontend sends tag names instead of IDs.
 *
 * @param string[] $names
 * @return int[] Tag IDs in the same order as the input names.
 */
function rcmi_tickets_tag_ids_from_names(array $names) {
    global $wpdb;
    $ids = [];

    foreach ($names as $name) {
        $name = trim(sanitize_text_field($name));
        if ($name === '') {
            continue;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rcmi_ticket_tags WHERE name = %s",
            $name
        ));

        if ($existing) {
            $ids[] = (int) $existing;
        } else {
            $slug = rcmi_tickets_tag_slug($name);
            $wpdb->insert($wpdb->prefix . 'rcmi_ticket_tags', [
                'name' => $name,
                'slug' => $slug,
            ], ['%s', '%s']);
            $ids[] = (int) $wpdb->insert_id;
        }
    }

    return $ids;
}
