<?php
/**
 * REST API controller for ticket comments (ticket-plan.md §5 comment rows).
 *
 * Endpoints:
 *   GET    /tickets/{id}/comments            — threaded tree, pinned top-level first
 *   POST   /tickets/{id}/comments            — add comment or reply (parent_id, parses @mentions)
 *   PUT    /comments/{id}                    — edit body (owner or manage)
 *   DELETE /comments/{id}                    — delete (owner or manage, replies cascade)
 *   POST   /comments/{id}/pin                — toggle pinned (manage only)
 *   POST   /comments/{id}/reactions          — toggle emoji reaction (view cap)
 *   GET    /tickets/{id}/mentionable-users   — users with ticket access (view cap)
 */

if (!defined('ABSPATH')) {
    exit;
}

function rcmi_tickets_register_comment_routes() {
    $namespace = 'rcmi/v1';

    register_rest_route($namespace, '/tickets/(?P<ticket_id>\d+)/comments', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_comment_list',
            'permission_callback' => 'rcmi_tickets_perm_comment_list',
            'args'                => [
                'ticket_id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
            ],
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_comment_create',
            'permission_callback' => 'rcmi_tickets_perm_comment_create',
            'args'                => [
                'ticket_id'      => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'body'           => ['type' => 'string', 'sanitize_callback' => 'wp_kses_post'],
                'parent_id'      => ['type' => 'integer', 'validate_callback' => function ($v) { return $v === 0 || rcmi_tickets_validate_int($v); }],
                'attachment_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
        ],
    ]);

    register_rest_route($namespace, '/comments/(?P<id>\d+)', [
        [
            'methods'             => 'PUT',
            'callback'            => 'rcmi_tickets_handle_comment_update',
            'permission_callback' => 'rcmi_tickets_perm_comment_modify',
            'args'                => [
                'id'   => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'body' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'wp_kses_post'],
            ],
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'rcmi_tickets_handle_comment_delete',
            'permission_callback' => 'rcmi_tickets_perm_comment_modify',
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
            ],
        ],
    ]);

    register_rest_route($namespace, '/comments/(?P<id>\d+)/pin', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_comment_pin',
            'permission_callback' => 'rcmi_tickets_perm_comment_pin',
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
            ],
        ],
    ]);

    register_rest_route($namespace, '/comments/(?P<id>\d+)/reactions', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_comment_reaction',
            'permission_callback' => 'rcmi_tickets_perm_comment_react',
            'args'                => [
                'id'   => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'type' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ],
    ]);

    register_rest_route($namespace, '/tickets/(?P<ticket_id>\d+)/mentionable-users', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_mentionable_users',
            'permission_callback' => 'rcmi_tickets_perm_comment_list',
            'args'                => [
                'ticket_id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
            ],
        ],
    ]);
}
add_action('rest_api_init', 'rcmi_tickets_register_comment_routes');

// ── helpers ──────────────────────────────────────────────────────────

/**
 * Load a comment row by ID.
 */
function rcmi_tickets_load_comment($id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_ticket_comments WHERE id = %d",
        (int) $id
    ), ARRAY_A);
    if (!$row) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['ticket_id'] = (int) $row['ticket_id'];
    $row['user_id'] = (int) $row['user_id'];
    $row['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
    $row['pinned'] = (int) $row['pinned'];
    $row['mentions'] = $row['mentions'] ? json_decode($row['mentions'], true) : [];
    return $row;
}

/**
 * Get the ticket_id for a comment (walks up parent chain if needed).
 */
function rcmi_tickets_comment_ticket_id($comment_id) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_comments WHERE id = %d",
        (int) $comment_id
    ));
}

/**
 * Format a comment for API response.
 */
function rcmi_tickets_format_comment($row) {
    global $wpdb;
    $user = get_userdata($row['user_id']);
    $comment_id = (int) $row['id'];

    // Include comment attachments
    $att_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, original_name, mime_type, size FROM {$wpdb->prefix}rcmi_ticket_attachments
         WHERE comment_id = %d ORDER BY id",
        $comment_id
    ), ARRAY_A);
    $attachments = array_map(function ($a) {
        return [
            'id'            => (int) $a['id'],
            'original_name' => $a['original_name'],
            'mime_type'     => $a['mime_type'],
            'size'          => (int) $a['size'],
        ];
    }, $att_rows);

    return [
        'id'          => $comment_id,
        'ticket_id'   => (int) $row['ticket_id'],
        'user_id'     => (int) $row['user_id'],
        'user_name'   => $user ? $user->display_name : '',
        'user_email'  => $user ? $user->user_email : '',
        'body'        => $row['body'],
        'parent_id'   => $row['parent_id'],
        'pinned'      => (bool) $row['pinned'],
        'mentions'    => is_array($row['mentions']) ? $row['mentions'] : [],
        'reactions'   => rcmi_tickets_get_comment_reactions($comment_id),
        'attachments' => $attachments,
        'replies'     => [],
        'created_at'  => $row['created_at'],
        'updated_at'  => $row['updated_at'],
    ];
}

/**
 * Recursively fetch and assemble a comment tree.
 * Top-level comments are sorted: pinned first, then by created_at.
 * Replies are sorted by created_at only.
 *
 * @param int $ticket_id
 * @param int|null $parent_id null for top-level
 * @return array
 */
function rcmi_tickets_fetch_comment_tree($ticket_id, $parent_id = null) {
    global $wpdb;

    if ($parent_id === null) {
        // Top-level: pinned first, then chronological
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rcmi_ticket_comments
             WHERE ticket_id = %d AND parent_id IS NULL
             ORDER BY pinned DESC, created_at ASC",
            (int) $ticket_id
        ), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rcmi_ticket_comments
             WHERE ticket_id = %d AND parent_id = %d
             ORDER BY created_at ASC",
            (int) $ticket_id, (int) $parent_id
        ), ARRAY_A);
    }

    $tree = [];
    foreach ($rows as $row) {
        $row['id'] = (int) $row['id'];
        $row['ticket_id'] = (int) $row['ticket_id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        $row['pinned'] = (int) $row['pinned'];
        $row['mentions'] = $row['mentions'] ? json_decode($row['mentions'], true) : [];
        $formatted = rcmi_tickets_format_comment($row);
        $formatted['replies'] = rcmi_tickets_fetch_comment_tree($ticket_id, $row['id']);
        $tree[] = $formatted;
    }

    return $tree;
}

/**
 * Recursively delete a comment and all its replies (plus reactions/attachments).
 */
function rcmi_tickets_delete_comment_recursive($comment_id) {
    global $wpdb;
    $comment_id = (int) $comment_id;

    // Find replies
    $replies = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rcmi_ticket_comments WHERE parent_id = %d",
        $comment_id
    ));
    foreach ($replies as $reply_id) {
        rcmi_tickets_delete_comment_recursive((int) $reply_id);
    }

    // Delete reactions
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_comment_reactions', ['comment_id' => $comment_id], ['%d']);

    // Delete comment attachments (DB rows only — file deletion is Task 8's concern)
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_attachments', ['comment_id' => $comment_id], ['%d']);

    // Delete the comment itself
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_comments', ['id' => $comment_id], ['%d']);
}

// ── mention helpers ──────────────────────────────────────────────────

/**
 * Get user IDs that can be @mentioned on a ticket (§4): author + assignees + managers.
 *
 * @param array $ticket Ticket row (must have author_id and assignee_ids).
 * @return int[]
 */
function rcmi_tickets_get_mentionable_user_ids(array $ticket) {
    $ids = [(int) $ticket['author_id']];
    $ids = array_merge($ids, (array) ($ticket['assignee_ids'] ?? []));
    $ids = array_merge($ids, rcmi_tickets_get_manage_user_ids());
    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

/**
 * Build a lookup map of name variants (lowercased) → user ID for a set of users.
 * Variants: display_name, user_login, first_name, last_name, "first last", "last first".
 *
 * @param int[] $user_ids
 * @return array Map of lowercase name string => user ID.
 */
function rcmi_tickets_build_mention_lookup(array $user_ids) {
    $lookup = [];
    foreach ($user_ids as $uid) {
        $user = get_userdata($uid);
        if (!$user) {
            continue;
        }
        $first = get_user_meta($uid, 'first_name', true);
        $last  = get_user_meta($uid, 'last_name', true);

        $variants = [
            $user->display_name,
            $user->user_login,
            $first,
            $last,
        ];
        if ($first && $last) {
            $variants[] = $first . ' ' . $last;
            $variants[] = $last . ' ' . $first;
        }

        foreach ($variants as $v) {
            $v = trim($v);
            if ($v !== '') {
                $lookup[mb_strtolower($v)] = (int) $uid;
            }
        }
    }
    return $lookup;
}

/**
 * Parse @mentions from comment body text and validate them against the
 * mentionable-user set for a ticket. Returns an array of mentioned user IDs.
 *
 * Strategy: for each "@" in the text, try progressively longer word sequences
 * (up to 4 words) against the mention lookup. Longest match wins — this
 * handles both "@John" and "@John Smith" correctly.
 *
 * @param string $body    Raw comment body (may contain HTML).
 * @param array  $ticket  Ticket row for mention validation.
 * @return int[] Validated mentioned user IDs.
 */
function rcmi_tickets_parse_mentions($body, array $ticket) {
    // Strip HTML so we match against plain text
    $text = wp_strip_all_tags($body);
    $mentionable_ids = rcmi_tickets_get_mentionable_user_ids($ticket);
    $lookup = rcmi_tickets_build_mention_lookup($mentionable_ids);

    if (empty($lookup)) {
        return [];
    }

    $mentioned = [];
    // Find all @positions
    if (!preg_match_all('/@([\w\s\-\']+)/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    foreach ($matches[1] as $match) {
        $candidate = trim($match[0]);
        // Split into words and try progressively longer matches (up to 4 words)
        $words = preg_split('/\s+/', $candidate);
        $found = false;
        for ($len = min(4, count($words)); $len >= 1 && !$found; $len--) {
            $try = mb_strtolower(implode(' ', array_slice($words, 0, $len)));
            if (isset($lookup[$try])) {
                $mentioned[] = $lookup[$try];
                $found = true;
            }
        }
    }

    // Deduplicate and exclude the commenter (self-mention is pointless)
    $mentioned = array_unique($mentioned);
    $commenter = get_current_user_id();
    $mentioned = array_filter($mentioned, function ($uid) use ($commenter) {
        return (int) $uid !== (int) $commenter;
    });

    return array_values($mentioned);
}

// ── reaction helpers ─────────────────────────────────────────────────

/**
 * Get reactions for a comment, grouped by type.
 *
 * @param int $comment_id
 * @return array Map of type => ['count' => int, 'user_ids' => int[]]
 */
function rcmi_tickets_get_comment_reactions($comment_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT type, user_id FROM {$wpdb->prefix}rcmi_ticket_comment_reactions WHERE comment_id = %d",
        (int) $comment_id
    ), ARRAY_A);

    $reactions = [];
    foreach ($rows as $r) {
        $type = $r['type'];
        if (!isset($reactions[$type])) {
            $reactions[$type] = ['count' => 0, 'user_ids' => []];
        }
        $reactions[$type]['count']++;
        $reactions[$type]['user_ids'][] = (int) $r['user_id'];
    }
    return $reactions;
}

/**
 * Toggle a reaction: if the user already reacted with this type, remove it;
 * otherwise add it. Idempotent.
 *
 * @param int    $comment_id
 * @param int    $user_id
 * @param string $type Emoji/reaction type (max 10 chars).
 * @return array Updated reactions map.
 */
function rcmi_tickets_toggle_reaction($comment_id, $user_id, $type) {
    global $wpdb;
    $comment_id = (int) $comment_id;
    $user_id = (int) $user_id;
    $type = substr(sanitize_text_field($type), 0, 10);

    if ($type === '') {
        return rcmi_tickets_get_comment_reactions($comment_id);
    }

    // PK is (comment_id, user_id) — one reaction per user per comment.
    // Query the existing type (if any) so we can toggle / update.
    $existing_type = $wpdb->get_var($wpdb->prepare(
        "SELECT type FROM {$wpdb->prefix}rcmi_ticket_comment_reactions
         WHERE comment_id = %d AND user_id = %d",
        $comment_id, $user_id
    ));

    if ($existing_type !== null) {
        if ($existing_type === $type) {
            // Same reaction → toggle off (delete)
            $wpdb->delete($wpdb->prefix . 'rcmi_ticket_comment_reactions', [
                'comment_id' => $comment_id,
                'user_id'    => $user_id,
            ], ['%d', '%d']);
        } else {
            // Different reaction → update the type
            $wpdb->update($wpdb->prefix . 'rcmi_ticket_comment_reactions', [
                'type' => $type,
            ], [
                'comment_id' => $comment_id,
                'user_id'    => $user_id,
            ], ['%s'], ['%d', '%d']);
        }
    } else {
        // No existing reaction → insert new
        $wpdb->insert($wpdb->prefix . 'rcmi_ticket_comment_reactions', [
            'comment_id' => $comment_id,
            'user_id'    => $user_id,
            'type'       => $type,
        ], ['%d', '%d', '%s']);
    }

    return rcmi_tickets_get_comment_reactions($comment_id);
}

// ── permission callbacks ─────────────────────────────────────────────

function rcmi_tickets_perm_comment_list($request) {
    $ticket = rcmi_tickets_load_ticket($request['ticket_id']);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    return rcmi_tickets_can(get_current_user_id(), 'view', $ticket);
}

function rcmi_tickets_perm_comment_create($request) {
    $ticket = rcmi_tickets_load_ticket($request['ticket_id']);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    return rcmi_tickets_can(get_current_user_id(), 'comment', $ticket);
}

function rcmi_tickets_perm_comment_modify($request) {
    $comment = rcmi_tickets_load_comment($request['id']);
    if (!$comment) {
        return new WP_Error('rcmi_tickets_comment_not_found', 'Comment not found.', ['status' => 404]);
    }
    $user_id = get_current_user_id();
    // Owner can modify their own comment; managers can modify any
    if ($comment['user_id'] === $user_id) {
        return true;
    }
    return rcmi_tickets_can($user_id, 'manage');
}

function rcmi_tickets_perm_comment_pin($request) {
    $comment = rcmi_tickets_load_comment($request['id']);
    if (!$comment) {
        return new WP_Error('rcmi_tickets_comment_not_found', 'Comment not found.', ['status' => 404]);
    }
    return rcmi_tickets_can(get_current_user_id(), 'pin');
}

// ── handlers ─────────────────────────────────────────────────────────

function rcmi_tickets_handle_comment_list($request) {
    $ticket_id = (int) $request['ticket_id'];
    $tree = rcmi_tickets_fetch_comment_tree($ticket_id, null);
    return new WP_REST_Response(['items' => $tree], 200);
}

function rcmi_tickets_handle_comment_create($request) {
    global $wpdb;
    $ticket_id = (int) $request['ticket_id'];
    $body = $request['body'] ?? '';
    $parent_id = isset($request['parent_id']) && $request['parent_id'] ? (int) $request['parent_id'] : null;
    $now = current_time('mysql');

    // Body can be empty only if there are attachments (but attachments are Task 8;
    // for now, require body to be non-empty)
    if (trim(wp_strip_all_tags($body)) === '' && empty($request['attachment_ids'])) {
        return new WP_Error('rcmi_tickets_comment_empty', 'Comment body cannot be empty.', ['status' => 400]);
    }

    // Validate parent_id belongs to the same ticket
    if ($parent_id !== null) {
        $parent = rcmi_tickets_load_comment($parent_id);
        if (!$parent) {
            return new WP_Error('rcmi_tickets_parent_not_found', 'Parent comment not found.', ['status' => 404]);
        }
        if ($parent['ticket_id'] !== $ticket_id) {
            return new WP_Error('rcmi_tickets_parent_mismatch', 'Parent comment belongs to a different ticket.', ['status' => 400]);
        }
    }

    // Parse @mentions and validate against users with ticket access (§5)
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    $mentioned_ids = rcmi_tickets_parse_mentions($body, $ticket);
    $mentions_json = json_encode($mentioned_ids);

    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_comments', [
        'ticket_id' => $ticket_id,
        'user_id'   => get_current_user_id(),
        'body'      => $body,
        'parent_id' => $parent_id,
        'pinned'    => 0,
        'mentions'  => $mentions_json,
        'created_at' => $now,
        'updated_at' => $now,
    ], ['%d', '%d', '%s', $parent_id === null ? null : '%d', '%d', '%s', '%s', '%s']);

    $comment_id = (int) $wpdb->insert_id;
    if (!$comment_id) {
        return new WP_Error('rcmi_tickets_comment_create_failed', 'Failed to create comment.', ['status' => 500]);
    }

    // Fire mention action for email notifications (Task 9 hooks here)
    if (!empty($mentioned_ids)) {
        do_action('rcmi_ticket_mention', $comment_id, $ticket_id, get_current_user_id(), $mentioned_ids);
    }

    // Link attachment_ids to this comment (attachments are uploaded separately in Task 8)
    if (!empty($request['attachment_ids'])) {
        global $wpdb;
        foreach (array_filter(array_map('intval', (array) $request['attachment_ids'])) as $att_id) {
            $wpdb->update($wpdb->prefix . 'rcmi_ticket_attachments', [
                'comment_id' => $comment_id,
                'ticket_id'  => $ticket_id,
            ], ['id' => $att_id], ['%d', '%d'], ['%d']);
        }
    }

    $row = rcmi_tickets_load_comment($comment_id);
    $formatted = rcmi_tickets_format_comment($row);
    $formatted['replies'] = [];
    return new WP_REST_Response($formatted, 201);
}

function rcmi_tickets_handle_comment_update($request) {
    global $wpdb;
    $comment_id = (int) $request['id'];
    $body = $request['body'];

    if (trim(wp_strip_all_tags($body)) === '') {
        return new WP_Error('rcmi_tickets_comment_empty', 'Comment body cannot be empty.', ['status' => 400]);
    }

    $wpdb->update($wpdb->prefix . 'rcmi_ticket_comments', [
        'body'       => $body,
        'updated_at' => current_time('mysql'),
    ], ['id' => $comment_id], ['%s', '%s'], ['%d']);

    $row = rcmi_tickets_load_comment($comment_id);
    $formatted = rcmi_tickets_format_comment($row);
    $formatted['replies'] = rcmi_tickets_fetch_comment_tree($row['ticket_id'], $comment_id);
    return new WP_REST_Response($formatted, 200);
}

function rcmi_tickets_handle_comment_delete($request) {
    $comment_id = (int) $request['id'];
    rcmi_tickets_delete_comment_recursive($comment_id);
    return new WP_REST_Response(['deleted' => true, 'id' => $comment_id], 200);
}

function rcmi_tickets_handle_comment_pin($request) {
    global $wpdb;
    $comment_id = (int) $request['id'];
    $comment = rcmi_tickets_load_comment($comment_id);

    // Toggle pinned
    $new_pinned = $comment['pinned'] ? 0 : 1;
    $wpdb->update($wpdb->prefix . 'rcmi_ticket_comments', [
        'pinned' => $new_pinned,
    ], ['id' => $comment_id], ['%d'], ['%d']);

    $row = rcmi_tickets_load_comment($comment_id);
    return new WP_REST_Response([
        'id'     => (int) $row['id'],
        'pinned' => (bool) $row['pinned'],
    ], 200);
}

// ── reaction permission + handler ───────────────────────────────────

function rcmi_tickets_perm_comment_react($request) {
    $comment = rcmi_tickets_load_comment($request['id']);
    if (!$comment) {
        return new WP_Error('rcmi_tickets_comment_not_found', 'Comment not found.', ['status' => 404]);
    }
    $ticket = rcmi_tickets_load_ticket($comment['ticket_id']);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    return rcmi_tickets_can(get_current_user_id(), 'react', $ticket);
}

function rcmi_tickets_handle_comment_reaction($request) {
    $comment_id = (int) $request['id'];
    $user_id = get_current_user_id();
    $type = $request['type'];

    $reactions = rcmi_tickets_toggle_reaction($comment_id, $user_id, $type);

    return new WP_REST_Response([
        'comment_id' => $comment_id,
        'reactions'  => $reactions,
    ], 200);
}

// ── mentionable-users handler ────────────────────────────────────────

function rcmi_tickets_handle_mentionable_users($request) {
    $ticket = rcmi_tickets_load_ticket($request['ticket_id']);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }

    $user_ids = rcmi_tickets_get_mentionable_user_ids($ticket);
    $users = [];
    foreach ($user_ids as $uid) {
        $user = get_userdata($uid);
        if (!$user) {
            continue;
        }
        $users[] = [
            'id'           => (int) $uid,
            'display_name' => $user->display_name,
            'user_login'   => $user->user_login,
            'first_name'   => get_user_meta($uid, 'first_name', true),
            'last_name'    => get_user_meta($uid, 'last_name', true),
        ];
    }

    return new WP_REST_Response($users, 200);
}
