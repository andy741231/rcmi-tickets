<?php
/**
 * Public REST API endpoints for guest ticket submission.
 *
 * Endpoints:
 *   GET  /public/meta    — form fields, priorities, allowed mime types (no auth)
 *   POST /public/submit  — create a ticket from a guest (no auth, honeypot + rate limit)
 *
 * @package RCMI_Tickets
 */

if (!defined('ABSPATH')) {
    exit;
}

function rcmi_tickets_register_public_routes() {
    $namespace = 'rcmi/v1';

    register_rest_route($namespace, '/public/meta', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_public_meta',
            'permission_callback' => '__return_true',
        ],
    ]);

    register_rest_route($namespace, '/public/submit', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_public_submit',
            'permission_callback' => '__return_true',
            'args'                => [
                'submitter_name'  => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'submitter_email' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email'],
                'title'           => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'description'     => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'wp_kses_post'],
                'form_answers'    => ['type' => 'object', 'default' => []],
                'website'         => ['type' => 'string', 'default' => ''],  // honeypot
            ],
        ],
    ]);

    // View endpoint: read-only access for external users with a view token
    register_rest_route($namespace, '/public/tickets/(?P<ticket_id>\d+)', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_public_view_get',
            'permission_callback' => '__return_true',
            'args'                => [
                'ticket_id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'token'     => ['required' => true, 'type' => 'string'],
            ],
        ],
    ]);

    register_rest_route($namespace, '/public/tickets/(?P<ticket_id>\d+)/revision', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_public_revision_get',
            'permission_callback' => '__return_true',
            'args'                => [
                'ticket_id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'token'     => ['required' => true, 'type' => 'string'],
            ],
        ],
        [
            'methods'             => 'PUT',
            'callback'            => 'rcmi_tickets_handle_public_revision_update',
            'permission_callback' => '__return_true',
            'args'                => [
                'ticket_id'    => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'token'        => ['required' => true, 'type' => 'string'],
                'form_answers' => ['required' => true, 'type' => 'object'],
            ],
        ],
    ]);

    register_rest_route($namespace, '/public/attachments/(?P<ticket_id>\d+)', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_public_attachment_upload',
            'permission_callback' => '__return_true',
            'args'                => [
                'ticket_id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
            ],
        ],
    ]);

    // Public comment endpoints (token-based auth for external users)
    register_rest_route($namespace, '/public/tickets/(?P<ticket_id>\d+)/comments', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_public_comment_list',
            'permission_callback' => '__return_true',
            'args'                => [
                'ticket_id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'token'     => ['required' => true, 'type' => 'string'],
            ],
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_public_comment_create',
            'permission_callback' => '__return_true',
            'args'                => [
                'ticket_id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'token'     => ['required' => true, 'type' => 'string'],
                'body'      => ['type' => 'string', 'sanitize_callback' => 'wp_kses_post'],
                'parent_id' => ['type' => 'integer'],
            ],
        ],
    ]);
}

add_action('rest_api_init', 'rcmi_tickets_register_public_routes');

/**
 * Public meta: form fields, priorities, allowed mime types.
 * No user-specific data is exposed.
 */
function rcmi_tickets_handle_public_meta() {
    $form_fields = rcmi_tickets_get_all_form_fields();
    $allowed_mime = array_keys(rcmi_tickets_allowed_mime_types());

    return new WP_REST_Response([
        'form_fields'        => $form_fields,
        'priorities'         => ['Low', 'Medium', 'High', 'Urgent'],
        'allowed_mime_types' => $allowed_mime,
        'is_public'          => true,
        'public_success'     => rcmi_tickets_get_success_message(),
    ], 200);
}

/**
 * Public ticket submission with honeypot + rate limiting.
 */
function rcmi_tickets_handle_public_submit($request) {
    global $wpdb;
    $now = current_time('mysql');

    // ── Honeypot: if the hidden "website" field is filled, it's a bot ──
    $honeypot = $request['website'] ?? '';
    if (!empty($honeypot)) {
        // Pretend success so bots don't retry
        return new WP_REST_Response(['id' => 0, 'message' => 'Thank you for your submission.'], 201);
    }

    // ── Validate email ──
    $submitter_email = $request['submitter_email'];
    if (!is_email($submitter_email)) {
        return new WP_Error('rcmi_tickets_invalid_email', 'A valid email address is required.', ['status' => 400]);
    }

    // ── Rate limit: max 25 submissions per IP per hour ──
    $ip = rcmi_tickets_get_client_ip();
    $transient_key = 'rcmi_pub_rl_' . md5($ip);
    $count = (int) get_transient($transient_key);
    if ($count >= 25) {
        return new WP_Error('rcmi_tickets_rate_limited', 'Too many submissions from your IP. Please try again later.', ['status' => 429]);
    }

    $title = $request['title'];
    $description = $request['description'] ?? '';
    $submitter_name = $request['submitter_name'];
    $form_answers = $request['form_answers'] ?? [];

    if (empty($title)) {
        return new WP_Error('rcmi_tickets_missing_title', 'A title is required.', ['status' => 400]);
    }

    // ── Find or create a "Guest Submitter" user to use as author ──
    $guest_user_id = rcmi_tickets_get_or_create_guest_user();

    $description_text = wp_strip_all_tags($description);

    // Append submitter info to description so staff can see who submitted
    $full_description = $description;
    if ($full_description) {
        $full_description .= "\n\n---\n";
    }
    $full_description .= '<p><em>Submitted by: ' . esc_html($submitter_name) . ' (' . esc_html($submitter_email) . ')</em></p>';

    $full_description_text = $description_text . "\n\n---\nSubmitted by: {$submitter_name} ({$submitter_email})";

    $wpdb->insert($wpdb->prefix . 'rcmi_tickets', [
        'author_id'        => $guest_user_id,
        'title'            => $title,
        'description'      => $full_description,
        'description_text' => $full_description_text,
        'status'           => 'Received',
        'due_date'         => null,
        'submitter_name'   => $submitter_name,
        'submitter_email'  => $submitter_email,
        'created_at'       => $now,
        'updated_at'       => $now,
    ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

    $ticket_id = (int) $wpdb->insert_id;
    if (!$ticket_id) {
        return new WP_Error('rcmi_tickets_create_failed', 'Failed to create ticket.', ['status' => 500]);
    }

    // Store submitter info in ticket meta (via WP post meta on the page? No — use a simple option-based map)
    // Actually, store as a custom row in the comments table as a "system" note, or use the ticket's own meta.
    // Simplest: store in a dedicated meta table if one exists, otherwise just keep it in the description.
    // For now, the description already contains it. Let's also store it as a transient for the email receipt.

    // Save form answers
    rcmi_tickets_sync_form_answers($ticket_id, $form_answers);

    // Sync due_date from reserved "Due Date" form field (if present)
    $form_due_date = rcmi_tickets_extract_due_date_from_answers($form_answers);
    if ($form_due_date !== null) {
        $wpdb->update($wpdb->prefix . 'rcmi_tickets', ['due_date' => $form_due_date], ['id' => $ticket_id], ['%s'], ['%d']);
    }

    // Increment rate limit counter (1 hour TTL)
    set_transient($transient_key, $count + 1, HOUR_IN_SECONDS);

    // ── Generate view token + send receipt email to submitter ──
    $view_token = rcmi_tickets_create_view_token($ticket_id);
    rcmi_tickets_email_public_receipt($ticket_id, $submitter_name, $submitter_email, $title, $view_token);

    // ── Notify assignees if any (via do_action) ──
    do_action('rcmi_ticket_created', $ticket_id, $guest_user_id, []);

    // ── Resolve + init approval chain ──
    $chain = rcmi_tickets_resolve_approval_chain($form_answers);
    if ($chain) {
        rcmi_tickets_init_ticket_approval_chain($ticket_id, $chain);
    }

    $success = rcmi_tickets_get_success_message();

    return new WP_REST_Response([
        'id'      => $ticket_id,
        'message' => $success['message'],
    ], 201);
}

function rcmi_tickets_is_public_ticket($ticket) {
    $author = get_userdata((int) $ticket['author_id']);
    return $author && $author->user_login === 'guest_submitter';
}

/**
 * Generate a view token for external users to access their ticket.
 * Valid for 90 days. Different from revision tokens which are only
 * generated for rejected tickets.
 */
function rcmi_tickets_create_view_token($ticket_id) {
    global $wpdb;
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket || !rcmi_tickets_is_public_ticket($ticket)) {
        return '';
    }

    $token = bin2hex(random_bytes(32));
    $wpdb->update($wpdb->prefix . 'rcmi_tickets', [
        'view_token_hash'    => hash('sha256', $token),
        'view_token_expires' => gmdate('Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS),
    ], ['id' => (int) $ticket_id], ['%s', '%s'], ['%d']);

    return $token;
}

function rcmi_tickets_public_view_url($ticket_id, $token) {
    $base = function_exists('rcmi_tickets_get_app_url') ? rcmi_tickets_get_app_url() : home_url('/');
    return untrailingslashit($base) . '#/ticket/' . (int) $ticket_id . '/view?token=' . rawurlencode($token);
}

/**
 * Validate a view token and return the ticket if valid.
 */
function rcmi_tickets_validate_view_token($ticket_id, $token) {
    global $wpdb;
    $ticket = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_tickets WHERE id = %d",
        (int) $ticket_id
    ), ARRAY_A);
    if (!$ticket || !rcmi_tickets_is_public_ticket($ticket)) {
        return new WP_Error('rcmi_tickets_view_unavailable', 'This ticket is not available for public viewing.', ['status' => 404]);
    }
    if (!$ticket['view_token_hash'] || !$ticket['view_token_expires'] || strtotime($ticket['view_token_expires'] . ' UTC') < time() || !hash_equals($ticket['view_token_hash'], hash('sha256', (string) $token))) {
        return new WP_Error('rcmi_tickets_bad_view_token', 'This view link is invalid or has expired.', ['status' => 403]);
    }
    return rcmi_tickets_load_ticket($ticket_id);
}

/**
 * Public view GET: returns full ticket data for read-only display.
 */
function rcmi_tickets_handle_public_view_get($request) {
    $ticket = rcmi_tickets_validate_view_token((int) $request['ticket_id'], (string) $request['token']);
    if (is_wp_error($ticket)) {
        return $ticket;
    }

    $formatted = rcmi_tickets_format_ticket($ticket);
    $formatted['form_answers'] = rcmi_tickets_get_ticket_form_answers((int) $ticket['id']);
    $formatted['attachments'] = rcmi_tickets_get_ticket_attachments((int) $ticket['id']);

    return new WP_REST_Response($formatted, 200);
}

function rcmi_tickets_create_revision_token($ticket_id) {
    global $wpdb;
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket || !rcmi_tickets_is_public_ticket($ticket) || $ticket['status'] !== 'Rejected: Pending Revision') {
        return '';
    }

    $token = bin2hex(random_bytes(32));
    $wpdb->update($wpdb->prefix . 'rcmi_tickets', [
        'revision_token_hash'    => hash('sha256', $token),
        'revision_token_expires' => gmdate('Y-m-d H:i:s', time() + WEEK_IN_SECONDS),
    ], ['id' => (int) $ticket_id], ['%s', '%s'], ['%d']);

    return $token;
}

function rcmi_tickets_public_revision_url($ticket_id, $token) {
    $base = function_exists('rcmi_tickets_get_app_url') ? rcmi_tickets_get_app_url() : home_url('/');
    return untrailingslashit($base) . '#/revision/' . (int) $ticket_id . '?token=' . rawurlencode($token);
}

function rcmi_tickets_validate_revision_token($ticket_id, $token) {
    global $wpdb;
    $ticket = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_tickets WHERE id = %d",
        (int) $ticket_id
    ), ARRAY_A);
    if (!$ticket || !rcmi_tickets_is_public_ticket($ticket) || $ticket['status'] !== 'Rejected: Pending Revision') {
        return new WP_Error('rcmi_tickets_revision_unavailable', 'This revision link is no longer available.', ['status' => 404]);
    }
    if (!$ticket['revision_token_hash'] || !$ticket['revision_token_expires'] || strtotime($ticket['revision_token_expires'] . ' UTC') < time() || !hash_equals($ticket['revision_token_hash'], hash('sha256', (string) $token))) {
        return new WP_Error('rcmi_tickets_bad_revision_token', 'This revision link is invalid or has expired.', ['status' => 403]);
    }
    return rcmi_tickets_load_ticket($ticket_id);
}

function rcmi_tickets_handle_public_revision_get($request) {
    $ticket = rcmi_tickets_validate_revision_token((int) $request['ticket_id'], (string) $request['token']);
    if (is_wp_error($ticket)) {
        return $ticket;
    }

    return new WP_REST_Response([
        'id'           => (int) $ticket['id'],
        'title'        => $ticket['title'],
        'form_answers' => rcmi_tickets_get_ticket_form_answers((int) $ticket['id']),
    ], 200);
}

function rcmi_tickets_handle_public_revision_update($request) {
    global $wpdb;
    $ticket = rcmi_tickets_validate_revision_token((int) $request['ticket_id'], (string) $request['token']);
    if (is_wp_error($ticket)) {
        return $ticket;
    }

    $ticket_id = (int) $ticket['id'];
    $form_answers = $request['form_answers'];
    rcmi_tickets_sync_form_answers($ticket_id, $form_answers);
    rcmi_tickets_apply_auto_tags($ticket_id, $form_answers);
    // Sync due_date from reserved "Due Date" form field (if present)
    $form_due_date = rcmi_tickets_extract_due_date_from_answers($form_answers);
    if ($form_due_date !== null) {
        $wpdb->update($wpdb->prefix . 'rcmi_tickets', ['due_date' => $form_due_date], ['id' => $ticket_id], ['%s'], ['%d']);
    }
    $restarted = rcmi_tickets_restart_ticket_approval_chain($ticket_id);
    $data = [
        'revision_token_hash'    => null,
        'revision_token_expires' => null,
        'updated_at'             => current_time('mysql'),
    ];
    $format = [null, null, '%s'];
    if (!$restarted) {
        $data['status'] = 'Received';
        $format[] = '%s';
    }
    $wpdb->update($wpdb->prefix . 'rcmi_tickets', $data, ['id' => $ticket_id], $format, ['%d']);

    return new WP_REST_Response([
        'id'      => $ticket_id,
        'message' => 'Your revised ticket has been resubmitted for approval.',
    ], 200);
}

/**
 * Get or create a "Guest Submitter" user for public ticket authorship.
 */
function rcmi_tickets_get_or_create_guest_user() {
    $guest = get_user_by('login', 'guest_submitter');
    if ($guest) {
        return (int) $guest->ID;
    }

    $user_id = wp_insert_user([
        'user_login' => 'guest_submitter',
        'user_email' => 'guest-submitter@noreply.local',
        'display_name' => 'Guest Submitter',
        'user_pass' => wp_generate_password(64, true, true),
        'role' => '',
    ]);

    if (is_wp_error($user_id)) {
        // Fallback: use user ID 1 (admin) if guest creation fails
        return 1;
    }

    return (int) $user_id;
}

/**
 * Get the real client IP address.
 */
function rcmi_tickets_get_client_ip() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * Send a receipt email to the public submitter.
 * Styled consistently with approval/rejection emails.
 */
function rcmi_tickets_email_public_receipt($ticket_id, $name, $email, $title, $view_token = '') {
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    $ticket_url = $view_token
        ? rcmi_tickets_public_view_url($ticket_id, $view_token)
        : rcmi_tickets_email_ticket_url($ticket_id);

    $subject = sprintf(__('Ticket submitted: #%d %s', 'rcmi-tickets'), $ticket_id, $title);

    // Ticket details (same table used in approval/rejection emails)
    $details = $ticket ? rcmi_tickets_email_ticket_details($ticket) : ['html' => '', 'plain' => ''];

    $html = '<!doctype html><html><body>'
        . '<h2>' . __('Thank you for your submission', 'rcmi-tickets') . '</h2>'
        . '<p>' . sprintf(__('Hi %s,', 'rcmi-tickets'), esc_html($name)) . '</p>'
        . '<p>' . __('We have received your ticket and our team will review it shortly.', 'rcmi-tickets') . '</p>'
        . '<p><strong>' . __('Ticket #:', 'rcmi-tickets') . '</strong> ' . (int) $ticket_id . '</p>'
        . '<p><strong>' . __('Title:', 'rcmi-tickets') . '</strong> ' . esc_html($title) . '</p>'
        . '<p>' . __('You will receive updates by email as your ticket is processed.', 'rcmi-tickets') . '</p>'
        . '<p style="margin-top:1rem;"><a href="' . esc_url($ticket_url) . '" style="display:inline-block;padding:.6rem 1.2rem;background:#c8102e;color:#fff;text-decoration:none;border-radius:.375rem;">' . __('View ticket', 'rcmi-tickets') . '</a></p>'
        . '<h3 style="margin-top:1.5rem;font-size:15px;color:#333;">' . __('Ticket details', 'rcmi-tickets') . '</h3>'
        . '<table style="border-collapse:collapse;margin-top:.5rem;">' . $details['html'] . '</table>'
        . '</body></html>';

    $plain = sprintf(
        "Thank you for your submission\n\nHi %s,\n\nWe have received your ticket and our team will review it shortly.\n\nTicket #%d\nTitle: %s\n\nYou will receive updates by email as your ticket is processed.\n\nView ticket: %s\n\nTicket details:\n%s",
        $name,
        $ticket_id,
        $title,
        wp_strip_all_tags($ticket_url),
        $details['plain']
    );

    rcmi_tickets_send_email($email, $subject, $html, $plain);
}

/**
 * Public attachment upload — allows guest submitters to attach files.
 * Uses the same storage logic as the authenticated endpoint but with
 * the guest user as uploader and rate limiting.
 */
function rcmi_tickets_handle_public_attachment_upload($request) {
    global $wpdb;
    $ticket_id = (int) $request['ticket_id'];

    // Rate limit: max 10 file uploads per IP per hour
    $ip = rcmi_tickets_get_client_ip();
    $transient_key = 'rcmi_pub_att_rl_' . md5($ip);
    $count = (int) get_transient($transient_key);
    if ($count >= 10) {
        return new WP_Error('rcmi_tickets_rate_limited', 'Too many uploads from your IP. Please try again later.', ['status' => 429]);
    }

    $files = $request->get_file_params();
    if (empty($files['file']) || $files['file']['error'] !== UPLOAD_ERR_OK) {
        return new WP_Error('rcmi_tickets_no_file', 'No file uploaded.', ['status' => 400]);
    }

    $file = $files['file'];
    $original_name = $file['name'];
    $tmp_path = $file['tmp_name'];
    $mime = $file['type'];
    $size = (int) $file['size'];

    // Validate size
    if ($size > rcmi_tickets_max_upload_size()) {
        return new WP_Error('rcmi_tickets_file_too_large', 'File exceeds 10MB limit.', ['status' => 413]);
    }

    // Validate MIME type
    $allowed = rcmi_tickets_allowed_mime_types();
    if (!isset($allowed[$mime])) {
        return new WP_Error('rcmi_tickets_file_type_not_allowed', 'File type not allowed.', ['status' => 415]);
    }

    // Verify the file is a real upload
    if (!is_uploaded_file($tmp_path)) {
        return new WP_Error('rcmi_tickets_invalid_upload', 'Invalid upload.', ['status' => 400]);
    }

    $guest_user_id = rcmi_tickets_get_or_create_guest_user();

    $dir = rcmi_tickets_upload_dir($ticket_id);
    $filename = rcmi_tickets_random_filename($original_name);
    $dest = trailingslashit($dir['path']) . $filename;

    if (!move_uploaded_file($tmp_path, $dest)) {
        return new WP_Error('rcmi_tickets_upload_failed', 'Failed to move uploaded file.', ['status' => 500]);
    }

    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_attachments', [
        'ticket_id'     => $ticket_id,
        'comment_id'    => null,
        'uploader_id'   => $guest_user_id,
        'file_path'     => $filename,
        'original_name' => $original_name,
        'mime_type'     => $mime,
        'size'          => $size,
    ], ['%d', null, '%d', '%s', '%s', '%s', '%d']);

    $id = (int) $wpdb->insert_id;

    // Increment rate limit
    set_transient($transient_key, $count + 1, HOUR_IN_SECONDS);

    return new WP_REST_Response([
        'id'            => $id,
        'ticket_id'     => $ticket_id,
        'original_name' => $original_name,
        'mime_type'     => $mime,
        'size'          => $size,
    ], 201);
}

/**
 * Public comment list: returns threaded comments for a ticket (read-only).
 * Authenticated via view token.
 */
function rcmi_tickets_handle_public_comment_list($request) {
    $ticket = rcmi_tickets_validate_view_token((int) $request['ticket_id'], (string) $request['token']);
    if (is_wp_error($ticket)) {
        return $ticket;
    }

    $tree = rcmi_tickets_fetch_comment_tree((int) $ticket['id'], null);
    return new WP_REST_Response(['items' => $tree], 200);
}

/**
 * Public comment create: external user posts a comment using view token auth.
 * The guest user is used as the comment author.
 */
function rcmi_tickets_handle_public_comment_create($request) {
    global $wpdb;
    $ticket = rcmi_tickets_validate_view_token((int) $request['ticket_id'], (string) $request['token']);
    if (is_wp_error($ticket)) {
        return $ticket;
    }

    $ticket_id = (int) $ticket['id'];
    $body = $request['body'] ?? '';
    $parent_id = isset($request['parent_id']) && $request['parent_id'] ? (int) $request['parent_id'] : null;
    $now = current_time('mysql');

    if (trim(wp_strip_all_tags($body)) === '') {
        return new WP_Error('rcmi_tickets_comment_empty', 'Comment body cannot be empty.', ['status' => 400]);
    }

    if ($parent_id !== null) {
        $parent = rcmi_tickets_load_comment($parent_id);
        if (!$parent) {
            return new WP_Error('rcmi_tickets_parent_not_found', 'Parent comment not found.', ['status' => 404]);
        }
        if ($parent['ticket_id'] !== $ticket_id) {
            return new WP_Error('rcmi_tickets_parent_mismatch', 'Parent comment belongs to a different ticket.', ['status' => 400]);
        }
    }

    // Use the guest user as author
    $guest_user_id = rcmi_tickets_get_or_create_guest_user();

    // Parse @mentions of staff (assignees/managers) so the external submitter
    // can loop in the right person; the guest account itself is excluded via
    // the explicit $commenter argument (no self-mentions).
    $mentioned_ids = rcmi_tickets_parse_mentions($body, $ticket, $guest_user_id);
    $mentions_json = json_encode($mentioned_ids);

    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_comments', [
        'ticket_id'  => $ticket_id,
        'user_id'    => $guest_user_id,
        'body'       => $body,
        'parent_id'  => $parent_id,
        'pinned'     => 0,
        'mentions'   => $mentions_json,
        'created_at' => $now,
        'updated_at' => $now,
    ], ['%d', '%d', '%s', $parent_id === null ? null : '%d', '%d', '%s', '%s', '%s']);

    $comment_id = (int) $wpdb->insert_id;
    if (!$comment_id) {
        return new WP_Error('rcmi_tickets_comment_create_failed', 'Failed to create comment.', ['status' => 500]);
    }

    if (!empty($mentioned_ids)) {
        do_action('rcmi_ticket_mention', $comment_id, $ticket_id, $guest_user_id, $mentioned_ids);
    }

    $row = rcmi_tickets_load_comment($comment_id);
    $formatted = rcmi_tickets_format_comment($row);
    $formatted['replies'] = [];
    return new WP_REST_Response($formatted, 201);
}
