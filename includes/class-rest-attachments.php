<?php
/**
 * REST API controller for ticket attachments (ticket-plan.md §5 attachment rows).
 *
 * Endpoints:
 *   POST   /tickets/{id}/attachments       — multipart upload, max 10MB (view cap)
 *   DELETE /attachments/{id}               — remove file + record (owner or manage)
 *   GET    /attachments/{id}/download      — protected download (per-ticket view cap)
 *
 * Files stored under uploads/rcmi-tickets/{ticket_id}/ with random filenames.
 * Original name kept in DB. Raw file URLs are never exposed — downloads go
 * through the protected endpoint which enforces per-ticket view access.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Max upload size in bytes (10 MB per §5).
 */
function rcmi_tickets_max_upload_size() {
    return 10 * 1024 * 1024;
}

/**
 * Allowed MIME types for uploads (§5: images, pdf, office docs, zip).
 */
function rcmi_tickets_allowed_mime_types() {
    return [
        // Images
        'image/jpeg'             => 'jpg',
        'image/png'              => 'png',
        'image/gif'              => 'gif',
        'image/webp'             => 'webp',
        'image/svg+xml'          => 'svg',
        // PDF
        'application/pdf'        => 'pdf',
        // Office docs
        'application/msword'                                                        => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
        'application/vnd.ms-excel'                                                  => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
        'application/vnd.ms-powerpoint'                                             => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text'                                   => 'odt',
        'text/plain'                                                                => 'txt',
        'text/csv'                                                                  => 'csv',
        // Archive
        'application/zip'       => 'zip',
        'application/x-zip-compressed' => 'zip',
    ];
}

function rcmi_tickets_register_attachment_routes() {
    $namespace = 'rcmi/v1';

    register_rest_route($namespace, '/tickets/(?P<ticket_id>\d+)/attachments', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_attachment_upload',
            'permission_callback' => 'rcmi_tickets_perm_attachment_upload',
            'args'                => [
                'ticket_id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
            ],
        ],
    ]);

    register_rest_route($namespace, '/attachments/(?P<id>\d+)', [
        [
            'methods'             => 'DELETE',
            'callback'            => 'rcmi_tickets_handle_attachment_delete',
            'permission_callback' => 'rcmi_tickets_perm_attachment_delete',
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
            ],
        ],
    ]);

    register_rest_route($namespace, '/attachments/(?P<id>\d+)/download', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_attachment_download',
            'permission_callback' => 'rcmi_tickets_perm_attachment_download',
            'args'                => [
                'id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
            ],
        ],
    ]);
}
add_action('rest_api_init', 'rcmi_tickets_register_attachment_routes');

// ── helpers ──────────────────────────────────────────────────────────

/**
 * Get the upload directory for a ticket (creates it if missing).
 *
 * @param int $ticket_id
 * @return array ['path' => absolute path, 'url' => public URL]
 */
function rcmi_tickets_upload_dir($ticket_id) {
    $base = trailingslashit(WP_CONTENT_DIR) . 'uploads/rcmi-tickets/' . (int) $ticket_id;
    if (!is_dir($base)) {
        wp_mkdir_p($base);
    }
    return [
        'path' => $base,
        'url'  => content_url('uploads/rcmi-tickets/' . (int) $ticket_id),
    ];
}

/**
 * Generate a random filename preserving the original extension.
 *
 * @param string $original_name
 * @return string e.g. "a1b2c3d4e5f6.pdf"
 */
function rcmi_tickets_random_filename($original_name) {
    $ext = pathinfo($original_name, PATHINFO_EXTENSION);
    $random = bin2hex(random_bytes(8));
    return $ext ? $random . '.' . $ext : $random;
}

/**
 * Load a single attachment row.
 *
 * @param int $id
 * @return array|null
 */
function rcmi_tickets_load_attachment($id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_ticket_attachments WHERE id = %d",
        (int) $id
    ), ARRAY_A);
    if (!$row) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['ticket_id'] = $row['ticket_id'] !== null ? (int) $row['ticket_id'] : null;
    $row['comment_id'] = $row['comment_id'] !== null ? (int) $row['comment_id'] : null;
    $row['uploader_id'] = isset($row['uploader_id']) && $row['uploader_id'] !== null ? (int) $row['uploader_id'] : null;
    $row['size'] = (int) $row['size'];
    return $row;
}

/**
 * Get the absolute file path for an attachment.
 *
 * @param array $attachment Attachment row.
 * @return string Absolute path.
 */
function rcmi_tickets_attachment_path($attachment) {
    $ticket_id = $attachment['ticket_id'] ?: 0;
    return trailingslashit(WP_CONTENT_DIR) . 'uploads/rcmi-tickets/' . $ticket_id . '/' . $attachment['file_path'];
}

/**
 * Format an attachment for API response.
 */
function rcmi_tickets_format_attachment($row) {
    return [
        'id'            => (int) $row['id'],
        'ticket_id'     => $row['ticket_id'],
        'comment_id'    => $row['comment_id'],
        'uploader_id'   => $row['uploader_id'] ?? null,
        'original_name' => $row['original_name'],
        'mime_type'     => $row['mime_type'],
        'size'          => (int) $row['size'],
    ];
}

// ── permission callbacks ─────────────────────────────────────────────

function rcmi_tickets_perm_attachment_upload($request) {
    $ticket = rcmi_tickets_load_ticket($request['ticket_id']);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    return rcmi_tickets_can(get_current_user_id(), 'view', $ticket);
}

function rcmi_tickets_perm_attachment_delete($request) {
    $attachment = rcmi_tickets_load_attachment($request['id']);
    if (!$attachment) {
        return new WP_Error('rcmi_tickets_attachment_not_found', 'Attachment not found.', ['status' => 404]);
    }
    $user_id = get_current_user_id();

    // The uploader can delete their own file
    if (isset($attachment['uploader_id']) && (int) $attachment['uploader_id'] === $user_id) {
        return true;
    }

    // Managers can delete any attachment
    if (rcmi_tickets_can($user_id, 'manage')) {
        return true;
    }

    // Ticket author can delete ticket-level attachments
    if ($attachment['ticket_id']) {
        $ticket = rcmi_tickets_load_ticket($attachment['ticket_id']);
        if ($ticket && (int) $ticket['author_id'] === $user_id) {
            return true;
        }
    }

    // Comment author can delete comment-level attachments
    if ($attachment['comment_id']) {
        global $wpdb;
        $comment_user = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}rcmi_ticket_comments WHERE id = %d",
            $attachment['comment_id']
        ));
        if ($comment_user === $user_id) {
            return true;
        }
    }

    return false;
}

function rcmi_tickets_perm_attachment_download($request) {
    $attachment = rcmi_tickets_load_attachment($request['id']);
    if (!$attachment) {
        return new WP_Error('rcmi_tickets_attachment_not_found', 'Attachment not found.', ['status' => 404]);
    }

    // Determine the ticket for view-access check
    $ticket_id = $attachment['ticket_id'];
    if (!$ticket_id && $attachment['comment_id']) {
        global $wpdb;
        $ticket_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ticket_id FROM {$wpdb->prefix}rcmi_ticket_comments WHERE id = %d",
            $attachment['comment_id']
        ));
    }
    if (!$ticket_id) {
        return new WP_Error('rcmi_tickets_not_found', 'Associated ticket not found.', ['status' => 404]);
    }

    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }

    return rcmi_tickets_can(get_current_user_id(), 'view', $ticket);
}

// ── handlers ─────────────────────────────────────────────────────────

function rcmi_tickets_handle_attachment_upload($request) {
    global $wpdb;
    $ticket_id = (int) $request['ticket_id'];

    $files = $request->get_file_params();
    if (empty($files['file']) || $files['file']['error'] !== UPLOAD_ERR_OK) {
        // Also accept raw body upload (some clients don't send multipart)
        $body = $request->get_body();
        if ($body === '' || $body === null) {
            return new WP_Error('rcmi_tickets_no_file', 'No file uploaded.', ['status' => 400]);
        }
        // Treat as raw upload with Content-Type header as mime
        $mime = $request->get_header('content_type') ?: 'application/octet-stream';
        $original = $request->get_header('x-filename') ?: 'upload.bin';
        $size = strlen($body);

        return rcmi_tickets_save_attachment_from_data($ticket_id, $body, $original, $mime, $size);
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

    // Verify the file is a real upload (security: prevent local file inclusion)
    if (!is_uploaded_file($tmp_path)) {
        return new WP_Error('rcmi_tickets_invalid_upload', 'Invalid upload.', ['status' => 400]);
    }

    $dir = rcmi_tickets_upload_dir($ticket_id);
    $filename = rcmi_tickets_random_filename($original_name);
    $dest = trailingslashit($dir['path']) . $filename;

    if (!move_uploaded_file($tmp_path, $dest)) {
        return new WP_Error('rcmi_tickets_upload_failed', 'Failed to move uploaded file.', ['status' => 500]);
    }

    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_attachments', [
        'ticket_id'     => $ticket_id,
        'comment_id'    => null,
        'uploader_id'   => get_current_user_id(),
        'file_path'     => $filename,
        'original_name' => $original_name,
        'mime_type'     => $mime,
        'size'          => $size,
    ], ['%d', null, '%d', '%s', '%s', '%s', '%d']);

    $id = (int) $wpdb->insert_id;
    if (!$id) {
        @unlink($dest);
        return new WP_Error('rcmi_tickets_db_failed', 'Failed to record attachment.', ['status' => 500]);
    }

    $row = rcmi_tickets_load_attachment($id);
    return new WP_REST_Response(rcmi_tickets_format_attachment($row), 201);
}

/**
 * Save an attachment from raw data (used when client sends body instead of multipart).
 */
function rcmi_tickets_save_attachment_from_data($ticket_id, $data, $original_name, $mime, $size) {
    global $wpdb;

    if ($size > rcmi_tickets_max_upload_size()) {
        return new WP_Error('rcmi_tickets_file_too_large', 'File exceeds 10MB limit.', ['status' => 413]);
    }

    $allowed = rcmi_tickets_allowed_mime_types();
    if (!isset($allowed[$mime])) {
        return new WP_Error('rcmi_tickets_file_type_not_allowed', 'File type not allowed.', ['status' => 415]);
    }

    $dir = rcmi_tickets_upload_dir($ticket_id);
    $filename = rcmi_tickets_random_filename($original_name);
    $dest = trailingslashit($dir['path']) . $filename;

    if (file_put_contents($dest, $data) === false) {
        return new WP_Error('rcmi_tickets_upload_failed', 'Failed to write file.', ['status' => 500]);
    }

    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_attachments', [
        'ticket_id'     => $ticket_id,
        'comment_id'    => null,
        'uploader_id'   => get_current_user_id(),
        'file_path'     => $filename,
        'original_name' => sanitize_text_field($original_name),
        'mime_type'     => $mime,
        'size'          => $size,
    ], ['%d', null, '%d', '%s', '%s', '%s', '%d']);

    $id = (int) $wpdb->insert_id;
    if (!$id) {
        @unlink($dest);
        return new WP_Error('rcmi_tickets_db_failed', 'Failed to record attachment.', ['status' => 500]);
    }

    $row = rcmi_tickets_load_attachment($id);
    return new WP_REST_Response(rcmi_tickets_format_attachment($row), 201);
}

function rcmi_tickets_handle_attachment_delete($request) {
    global $wpdb;
    $attachment = rcmi_tickets_load_attachment($request['id']);
    $id = (int) $request['id'];

    // Remove file from disk
    $path = rcmi_tickets_attachment_path($attachment);
    if (file_exists($path)) {
        @unlink($path);
    }

    // Remove DB row
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_attachments', ['id' => $id], ['%d']);

    return new WP_REST_Response(['deleted' => true, 'id' => $id], 200);
}

function rcmi_tickets_handle_attachment_download($request) {
    $attachment = rcmi_tickets_load_attachment($request['id']);
    $path = rcmi_tickets_attachment_path($attachment);

    if (!file_exists($path) || !is_readable($path)) {
        return new WP_Error('rcmi_tickets_file_missing', 'File not found on disk.', ['status' => 404]);
    }

    // Stream the file with proper headers
    nocache_headers();
    header('Content-Type: ' . $attachment['mime_type']);
    header('Content-Disposition: attachment; filename="' . $attachment['original_name'] . '"');
    header('Content-Length: ' . $attachment['size']);

    readfile($path);
    exit;
}
