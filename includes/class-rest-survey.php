<?php
/**
 * REST API + helpers for the customer-satisfaction survey (schema v14).
 *
 * Endpoints:
 *   GET    /survey-settings               — global survey settings (manager)
 *   PUT    /survey-settings               — update settings (manager)
 *   GET    /survey-fields                 — list survey fields
 *   POST   /survey-fields                 — create a field
 *   PUT    /survey-fields/{id}            — update a field
 *   DELETE /survey-fields/{id}            — delete a field (+ its answers)
 *   PUT    /survey-fields/reorder         — bulk reorder (array of ids)
 *   GET    /survey/{ticket_id}            — survey form + state (token or author)
 *   POST   /survey/{ticket_id}            — submit survey answers (token or author)
 *   GET    /tickets/{id}/survey           — response + email log (manager)
 *   POST   /tickets/{id}/survey/resend    — send survey email now (manager)
 *   GET    /survey-responses              — paginated response list (manager)
 *   GET    /survey-responses/export       — filtered responses as CSV (manager)
 *   GET    /survey-field-values           — distinct stored answer values (manager)
 *
 * Survey fields reuse the form-field sanitizer; valid types are the ticket
 * form types plus 'rating' and 'scale'. Conditions support the shared v2
 * {action, match, rules} shape and may reference ticket form answers via
 * "ticket:<field_key>" rules.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================
// Settings
// ============================================================

function rcmi_tickets_survey_default_settings() {
    return [
        'enabled'             => true,
        'intro_text'          => '',
        'thank_you_text'      => 'Thank you for your feedback!',
        'reminders_enabled'   => true,
        'first_reminder_days' => 7,
        'repeat_every_days'   => 7,
        'max_reminders'       => 1,
        'close_after_days'    => 30,
    ];
}

function rcmi_tickets_survey_settings() {
    $stored = get_option('rcmi_tickets_survey_settings', []);
    if (!is_array($stored)) {
        $stored = [];
    }
    $s = array_merge(rcmi_tickets_survey_default_settings(), $stored);
    return [
        'enabled'             => (bool) $s['enabled'],
        'intro_text'          => (string) $s['intro_text'],
        'thank_you_text'      => (string) $s['thank_you_text'],
        'reminders_enabled'   => (bool) $s['reminders_enabled'],
        'first_reminder_days' => max(0, (int) $s['first_reminder_days']),
        'repeat_every_days'   => max(1, (int) $s['repeat_every_days']),
        'max_reminders'       => max(0, (int) $s['max_reminders']),
        'close_after_days'    => max(1, (int) $s['close_after_days']),
    ];
}

// ============================================================
// Survey field CRUD (mirrors rcmi_form_fields handlers)
// ============================================================

function rcmi_tickets_valid_survey_field_types() {
    return array_merge(rcmi_tickets_valid_field_types(), ['rating', 'scale']);
}

function rcmi_tickets_get_all_survey_fields() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT f.*,
                (SELECT COUNT(*) FROM {$wpdb->prefix}rcmi_survey_answers sa WHERE sa.field_id = f.id) AS answer_count
         FROM {$wpdb->prefix}rcmi_survey_fields f
         ORDER BY sort_order ASC, id ASC"
    , ARRAY_A);
    return array_map('rcmi_tickets_format_survey_field', $rows);
}

function rcmi_tickets_load_survey_field($id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_survey_fields WHERE id = %d",
        (int) $id
    ), ARRAY_A);
    if (!$row) {
        return null;
    }
    return rcmi_tickets_format_survey_field($row);
}

function rcmi_tickets_format_survey_field($row) {
    $config = $row['config'] ? json_decode($row['config'], true) : null;
    if (is_array($config) && isset($config['cascade_options']) && is_array($config['cascade_options']) && empty($config['cascade_options'])) {
        $config['cascade_options'] = new stdClass();
    }
    return [
        'id'           => (int) $row['id'],
        'field_key'    => $row['field_key'],
        'label'        => $row['label'],
        'type'         => $row['type'],
        'required'     => (bool) $row['required'],
        'sort_order'   => (int) $row['sort_order'],
        'config'       => is_array($config) ? $config : new stdClass(),
        'answer_count' => isset($row['answer_count']) ? (int) $row['answer_count'] : 0,
        'created_at'   => $row['created_at'],
        'updated_at'   => $row['updated_at'],
    ];
}

// ============================================================
// Survey token + URL
// ============================================================

/**
 * Stable per-ticket survey token. Works for all tickets (public + SSO),
 * unlike view tokens which require a public guest submitter.
 */
function rcmi_tickets_survey_token($ticket_id) {
    return hash_hmac('sha256', 'survey:' . (int) $ticket_id, rcmi_tickets_view_token_secret());
}

function rcmi_tickets_survey_url($ticket_id) {
    $base = function_exists('rcmi_tickets_get_app_url') ? rcmi_tickets_get_app_url() : home_url('/');
    return untrailingslashit($base) . '#/survey/' . (int) $ticket_id . '?token=' . rawurlencode(rcmi_tickets_survey_token($ticket_id));
}

/**
 * Access check for the public survey endpoints: a valid survey token, or a
 * logged-in user who authored the ticket. Returns the ticket row or WP_Error.
 */
function rcmi_tickets_survey_access($ticket_id, $token) {
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket) {
        return new WP_Error('rcmi_tickets_survey_not_found', 'Ticket not found.', ['status' => 404]);
    }

    $provided = (string) $token;
    if ($provided !== '' && hash_equals(rcmi_tickets_survey_token($ticket_id), $provided)) {
        return $ticket;
    }

    $uid = get_current_user_id();
    if ($uid && (int) $ticket['author_id'] === $uid) {
        return $ticket;
    }

    return new WP_Error('rcmi_tickets_survey_forbidden', 'This survey link is invalid.', ['status' => 403]);
}

// ============================================================
// Eligibility
// ============================================================

/**
 * Convert a stored site-local mysql datetime (e.g. status_log.changed_at or
 * survey_emails.sent_at, both written with current_time('mysql')) into a
 * Unix timestamp using the site timezone. Numeric timestamps pass through
 * unchanged; empty/unparseable input returns 0.
 *
 * @param string|int|null $value
 * @return int
 */
function rcmi_tickets_survey_local_ts($value) {
    if ($value === null || $value === '') {
        return 0;
    }
    if (is_numeric($value)) {
        return (int) $value;
    }
    try {
        return (new DateTimeImmutable((string) $value, wp_timezone()))->getTimestamp();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * When the ticket was completed: the latest 'Completed' row in the status
 * log. Falls back to the ticket's updated_at for tickets completed before
 * the log existed.
 */
function rcmi_tickets_survey_completed_at($ticket_id) {
    global $wpdb;
    $completed = $wpdb->get_var($wpdb->prepare(
        "SELECT changed_at FROM {$wpdb->prefix}rcmi_ticket_status_log
         WHERE ticket_id = %d AND new_status = 'Completed'
         ORDER BY changed_at DESC, id DESC LIMIT 1",
        (int) $ticket_id
    ));
    if ($completed) {
        return $completed;
    }
    $ticket = rcmi_tickets_load_ticket($ticket_id);
    return $ticket ? $ticket['updated_at'] : null;
}

/**
 * Whether the ticket's approval chain allows the survey. Tickets without a
 * chain follow the global setting (return null = no opinion).
 *
 * @return bool|null
 */
function rcmi_tickets_survey_chain_allows($ticket_id) {
    global $wpdb;
    $chain_id = $wpdb->get_var($wpdb->prepare(
        "SELECT chain_id FROM {$wpdb->prefix}rcmi_ticket_approvals WHERE ticket_id = %d ORDER BY id DESC LIMIT 1",
        (int) $ticket_id
    ));
    if (!$chain_id) {
        return null;
    }
    $enabled = $wpdb->get_var($wpdb->prepare(
        "SELECT survey_enabled FROM {$wpdb->prefix}rcmi_approval_chains WHERE id = %d",
        (int) $chain_id
    ));
    // A deleted chain can't veto — treat missing rows like no chain.
    return $enabled === null ? null : (bool) $enabled;
}

/**
 * Full eligibility report for a ticket's survey.
 *
 * @return array { eligible, reasons[], state, completed_at, closes_at }
 */
function rcmi_tickets_survey_eligibility($ticket_id) {
    $settings = rcmi_tickets_survey_settings();
    $reasons = [];

    $ticket = rcmi_tickets_load_ticket($ticket_id);
    if (!$ticket) {
        return ['eligible' => false, 'reasons' => ['not_found'], 'state' => 'unavailable', 'completed_at' => null, 'closes_at' => null];
    }

    if (!$settings['enabled']) {
        $reasons[] = 'disabled';
    }

    $has_questions = false;
    foreach (rcmi_tickets_get_all_survey_fields() as $f) {
        if ($f['type'] !== 'section') {
            $has_questions = true;
            break;
        }
    }
    if (!$has_questions) {
        $reasons[] = 'no_fields';
    }

    if ($ticket['status'] !== 'Completed') {
        $reasons[] = 'not_completed';
    }

    if (rcmi_tickets_survey_chain_allows($ticket_id) === false) {
        $reasons[] = 'chain_disabled';
    }

    $completed_at = rcmi_tickets_survey_completed_at($ticket_id);
    $closes_at = null;
    if ($ticket['status'] === 'Completed' && $completed_at) {
        $closes_ts = rcmi_tickets_survey_local_ts($completed_at) + $settings['close_after_days'] * DAY_IN_SECONDS;
        $closes_at = wp_date('Y-m-d H:i:s', $closes_ts);
        if (time() >= $closes_ts) {
            $reasons[] = 'closed';
        }
    }

    $eligible = empty($reasons);
    if ($eligible) {
        $state = 'open';
    } elseif (count($reasons) === 1 && $reasons[0] === 'closed') {
        $state = 'closed';
    } else {
        $state = 'unavailable';
    }

    return [
        'eligible'     => $eligible,
        'reasons'      => $reasons,
        'state'        => $state,
        'completed_at' => $completed_at,
        'closes_at'    => $closes_at,
    ];
}

function rcmi_tickets_survey_is_eligible($ticket_id) {
    $elig = rcmi_tickets_survey_eligibility($ticket_id);
    return !empty($elig['eligible']);
}

// ============================================================
// Responses + email log
// ============================================================

/**
 * Format one rcmi_survey_answers row for the API: multi-value types are
 * stored JSON-encoded, so decode them back to arrays.
 */
function rcmi_tickets_format_survey_answer($a) {
    $value = $a['value'];
    if (in_array($a['type'], ['checkbox', 'radio', 'cascade'], true)) {
        $decoded = json_decode((string) $value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        }
    }
    return [
        'field_id'  => (int) $a['field_id'],
        'field_key' => $a['field_key'],
        'label'     => $a['label'],
        'type'      => $a['type'],
        'value'     => $value,
    ];
}

function rcmi_tickets_get_survey_response($ticket_id) {
    global $wpdb;
    $response = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_survey_responses WHERE ticket_id = %d",
        (int) $ticket_id
    ), ARRAY_A);
    if (!$response) {
        return null;
    }
    $answers = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_survey_answers WHERE response_id = %d ORDER BY id ASC",
        (int) $response['id']
    ), ARRAY_A);

    return [
        'id'                 => (int) $response['id'],
        'ticket_id'          => (int) $response['ticket_id'],
        'respondent_user_id' => $response['respondent_user_id'] !== null ? (int) $response['respondent_user_id'] : null,
        'respondent_email'   => $response['respondent_email'],
        'submitted_at'       => $response['submitted_at'],
        'answers'            => array_map('rcmi_tickets_format_survey_answer', $answers),
    ];
}

function rcmi_tickets_get_survey_email_log($ticket_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rcmi_survey_emails WHERE ticket_id = %d ORDER BY sent_at ASC, id ASC",
        (int) $ticket_id
    ), ARRAY_A);
    return array_map(function ($r) {
        $sent_by = '';
        if ($r['sent_by']) {
            $u = get_userdata((int) $r['sent_by']);
            $sent_by = $u ? $u->display_name : '';
        }
        return [
            'id'         => (int) $r['id'],
            'kind'       => $r['kind'],
            'recipient'  => $r['recipient'],
            'sent_by'    => (int) $r['sent_by'],
            'sent_by_name' => $sent_by,
            'sent_at'    => $r['sent_at'],
            'success'    => (bool) $r['success'],
        ];
    }, $rows);
}

function rcmi_tickets_log_survey_email($ticket_id, $kind, $recipient, $sent_by, $success) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'rcmi_survey_emails', [
        'ticket_id' => (int) $ticket_id,
        'kind'      => in_array($kind, ['initial', 'reminder', 'manual'], true) ? $kind : 'initial',
        'recipient' => (string) $recipient,
        'sent_by'   => (int) $sent_by,
        'sent_at'   => current_time('mysql'),
        'success'   => $success ? 1 : 0,
    ], ['%d', '%s', '%s', '%d', '%s', '%d']);
}

/**
 * Recipients for a survey email — the same target as the completion
 * notification: the external submitter's address on public tickets,
 * otherwise the requestor's user email.
 */
function rcmi_tickets_survey_recipients($ticket) {
    $recipients = [];
    $is_public_ticket = function_exists('rcmi_tickets_is_public_ticket') && rcmi_tickets_is_public_ticket($ticket);
    if ($is_public_ticket && !empty($ticket['submitter_email']) && is_email($ticket['submitter_email'])) {
        $recipients[] = $ticket['submitter_email'];
    } else {
        $author = get_userdata((int) $ticket['author_id']);
        if ($author && is_email($author->user_email)) {
            $recipients[] = $author->user_email;
        }
    }
    return array_values(array_unique($recipients));
}

// ============================================================
// Ticket answer context for "ticket:<key>" conditions
// ============================================================

/**
 * The ticket form answers needed by survey conditions, keyed
 * "ticket:<field_key>". Only keys actually referenced by a survey rule
 * are included.
 */
function rcmi_tickets_survey_ticket_context($ticket_id, $survey_fields) {
    $needed = [];
    foreach ((array) $survey_fields as $f) {
        $logic = is_array($f['config'] ?? null) ? ($f['config']['logic'] ?? []) : [];
        if (!is_array($logic)) {
            $logic = [];
        }
        $rules = $logic['rules'] ?? [];
        // Legacy single-rule shape on rows saved before v2.
        if (empty($rules) && !empty($logic['field_key'])) {
            $rules = [$logic];
        }
        foreach ((array) $rules as $rule) {
            $key = is_array($rule) ? ($rule['field_key'] ?? '') : '';
            if (is_string($key) && strpos($key, 'ticket:') === 0) {
                $needed[] = substr($key, 7);
            }
        }
    }
    $needed = array_unique(array_filter($needed));
    if (!$needed) {
        return new stdClass();
    }
    $form_answers = rcmi_tickets_get_ticket_form_answers($ticket_id);
    $context = [];
    foreach ($needed as $key) {
        if (array_key_exists($key, $form_answers)) {
            $context['ticket:' . $key] = $form_answers[$key];
        }
    }
    return $context ?: new stdClass();
}

// ============================================================
// Reminders
// ============================================================

/**
 * Pure scheduling check: should a reminder go out now?
 *
 * @param string|int $completed_at  Completion time (mysql string or ts).
 * @param int        $reminders_sent How many 'reminder' emails already sent.
 * @param string|int|null $last_sent_at Last reminder send time.
 * @param array      $settings      Survey settings.
 * @param int        $now           Current timestamp.
 * @return bool
 */
function rcmi_tickets_survey_reminder_due($completed_at, $reminders_sent, $last_sent_at, $settings, $now) {
    if (empty($settings['reminders_enabled'])) {
        return false;
    }
    $completed_ts = rcmi_tickets_survey_local_ts($completed_at);
    if (!$completed_ts) {
        return false;
    }
    $reminders_sent = (int) $reminders_sent;
    $max = (int) ($settings['max_reminders'] ?? 1);
    if ($max <= 0 || $reminders_sent >= $max) {
        return false;
    }
    $close_days = (int) ($settings['close_after_days'] ?? 30);
    if ($now >= $completed_ts + $close_days * DAY_IN_SECONDS) {
        return false;
    }
    if ($reminders_sent === 0) {
        $first_days = (int) ($settings['first_reminder_days'] ?? 7);
        return $now >= $completed_ts + $first_days * DAY_IN_SECONDS;
    }
    $repeat_days = (int) ($settings['repeat_every_days'] ?? 7);
    $last_ts = rcmi_tickets_survey_local_ts($last_sent_at);
    if (!$last_ts) {
        return false;
    }
    return $now >= $last_ts + $repeat_days * DAY_IN_SECONDS;
}

/**
 * WP-Cron runner (daily): Completed tickets without a response whose survey
 * is still eligible get a reminder when the schedule says one is due.
 *
 * @return int Number of tickets emailed.
 */
function rcmi_tickets_run_survey_reminders() {
    global $wpdb;
    $settings = rcmi_tickets_survey_settings();
    if (!$settings['enabled'] || !$settings['reminders_enabled']) {
        return 0;
    }

    $ticket_ids = $wpdb->get_col(
        "SELECT t.id FROM {$wpdb->prefix}rcmi_tickets t
         WHERE t.status = 'Completed'
           AND NOT EXISTS (
               SELECT 1 FROM {$wpdb->prefix}rcmi_survey_responses r WHERE r.ticket_id = t.id
           )"
    );

    $emailed = 0;
    foreach ((array) $ticket_ids as $ticket_id) {
        $ticket_id = (int) $ticket_id;
        $elig = rcmi_tickets_survey_eligibility($ticket_id);
        if (empty($elig['eligible'])) {
            continue;
        }
        $reminders_sent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rcmi_survey_emails WHERE ticket_id = %d AND kind = 'reminder'",
            $ticket_id
        ));
        $last_sent = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sent_at) FROM {$wpdb->prefix}rcmi_survey_emails WHERE ticket_id = %d AND kind = 'reminder'",
            $ticket_id
        ));
        if (rcmi_tickets_survey_reminder_due($elig['completed_at'], $reminders_sent, $last_sent, $settings, time())) {
            $emailed += rcmi_tickets_send_survey_email($ticket_id, 'reminder', 0);
        }
    }
    return $emailed;
}
add_action('rcmi_tickets_survey_reminders', 'rcmi_tickets_run_survey_reminders');

// Schedule the daily reminder pass; cleared on deactivation.
add_action('init', function () {
    if (!wp_next_scheduled('rcmi_tickets_survey_reminders')) {
        wp_schedule_event(time(), 'daily', 'rcmi_tickets_survey_reminders');
    }
});

// ============================================================
// Routes
// ============================================================

function rcmi_tickets_register_survey_routes() {
    $namespace = 'rcmi/v1';

    register_rest_route($namespace, '/survey-settings', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_survey_settings_get',
            'permission_callback' => 'rcmi_tickets_perm_settings',
        ],
        [
            'methods'             => 'PUT',
            'callback'            => 'rcmi_tickets_handle_survey_settings_update',
            'permission_callback' => 'rcmi_tickets_perm_settings',
        ],
    ]);

    register_rest_route($namespace, '/survey-fields', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_survey_fields_list',
            'permission_callback' => 'rcmi_tickets_perm_form_fields_read',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_survey_field_create',
            'permission_callback' => 'rcmi_tickets_perm_form_fields_write',
            'args'                => rcmi_tickets_survey_field_write_args(),
        ],
    ]);

    register_rest_route($namespace, '/survey-fields/reorder', [
        [
            'methods'             => 'PUT',
            'callback'            => 'rcmi_tickets_handle_survey_fields_reorder',
            'permission_callback' => 'rcmi_tickets_perm_form_fields_write',
            'args'                => [
                'ids' => ['type' => 'array', 'required' => true, 'items' => ['type' => 'integer']],
            ],
        ],
    ]);

    register_rest_route($namespace, '/survey-fields/(?P<id>\d+)', [
        [
            'methods'             => 'PUT',
            'callback'            => 'rcmi_tickets_handle_survey_field_update',
            'permission_callback' => 'rcmi_tickets_perm_form_fields_write',
            'args'                => rcmi_tickets_survey_field_write_args(true),
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'rcmi_tickets_handle_survey_field_delete',
            'permission_callback' => 'rcmi_tickets_perm_form_fields_write',
            'args'                => ['id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int']],
        ],
    ]);

    // Manager list of all submitted responses (drives #/survey-responses).
    register_rest_route($namespace, '/survey-responses', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_survey_responses',
            'permission_callback' => 'rcmi_tickets_perm_settings',
            'args'                => rcmi_tickets_survey_responses_args(),
        ],
    ]);

    // CSV export of the filtered response set (same args as the list).
    register_rest_route($namespace, '/survey-responses/export', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_survey_export',
            'permission_callback' => 'rcmi_tickets_perm_settings',
            'args'                => rcmi_tickets_survey_responses_args(),
        ],
    ]);

    // Distinct stored values for a survey question (drives the list filter).
    register_rest_route($namespace, '/survey-field-values', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_survey_field_values',
            'permission_callback' => 'rcmi_tickets_perm_settings',
            'args'                => [
                'field_key' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ],
    ]);

    // Respondent endpoints — permission enforced inside the handler
    // (valid survey token, or the logged-in ticket author).
    register_rest_route($namespace, '/survey/(?P<id>\d+)', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_survey_get',
            'permission_callback' => '__return_true',
            'args'                => [
                'id'    => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'token' => ['type' => 'string', 'default' => ''],
            ],
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_survey_submit',
            'permission_callback' => '__return_true',
            'args'                => [
                'id'      => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int'],
                'token'   => ['type' => 'string', 'default' => ''],
                'answers' => ['type' => 'object', 'default' => []],
            ],
        ],
    ]);

    // Manager endpoints
    register_rest_route($namespace, '/tickets/(?P<id>\d+)/survey', [
        [
            'methods'             => 'GET',
            'callback'            => 'rcmi_tickets_handle_ticket_survey_get',
            'permission_callback' => 'rcmi_tickets_perm_settings',
            'args'                => ['id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int']],
        ],
    ]);

    register_rest_route($namespace, '/tickets/(?P<id>\d+)/survey/resend', [
        [
            'methods'             => 'POST',
            'callback'            => 'rcmi_tickets_handle_ticket_survey_resend',
            'permission_callback' => 'rcmi_tickets_perm_settings',
            'args'                => ['id' => ['required' => true, 'validate_callback' => 'rcmi_tickets_validate_int']],
        ],
    ]);
}
add_action('rest_api_init', 'rcmi_tickets_register_survey_routes');

// ── settings handlers ──────────────────────────────────────

function rcmi_tickets_handle_survey_settings_get() {
    return new WP_REST_Response(rcmi_tickets_survey_settings(), 200);
}

function rcmi_tickets_handle_survey_settings_update($request) {
    $params = $request->get_json_params() ?: [];
    $s = rcmi_tickets_survey_settings();

    if (isset($params['enabled'])) {
        $s['enabled'] = !empty($params['enabled']);
    }
    if (isset($params['intro_text'])) {
        $s['intro_text'] = rcmi_tickets_sanitize_completion_message((string) $params['intro_text']);
    }
    if (isset($params['thank_you_text'])) {
        $s['thank_you_text'] = rcmi_tickets_sanitize_completion_message((string) $params['thank_you_text']);
    }
    if (isset($params['reminders_enabled'])) {
        $s['reminders_enabled'] = !empty($params['reminders_enabled']);
    }
    foreach (['first_reminder_days', 'repeat_every_days', 'max_reminders', 'close_after_days'] as $k) {
        if (isset($params[$k]) && is_numeric($params[$k])) {
            $s[$k] = (int) $params[$k];
        }
    }

    update_option('rcmi_tickets_survey_settings', $s);
    return new WP_REST_Response(rcmi_tickets_survey_settings(), 200);
}

// ── survey field handlers ──────────────────────────────────

function rcmi_tickets_survey_field_write_args($is_update = false) {
    return [
        'field_key' => ['type' => 'string', 'required' => !$is_update, 'sanitize_callback' => 'sanitize_key'],
        'label'     => ['type' => 'string', 'required' => !$is_update, 'sanitize_callback' => 'sanitize_text_field'],
        'type'      => ['type' => 'string', 'required' => !$is_update, 'validate_callback' => function ($v) { return in_array($v, rcmi_tickets_valid_survey_field_types(), true); }],
        'required'  => ['type' => 'boolean', 'default' => false],
        'config'    => ['type' => 'object'],
    ];
}

function rcmi_tickets_handle_survey_fields_list() {
    return new WP_REST_Response(rcmi_tickets_get_all_survey_fields(), 200);
}

function rcmi_tickets_handle_survey_field_create($request) {
    global $wpdb;
    $now = current_time('mysql');

    $field_key = $request['field_key'];
    $config = rcmi_tickets_validate_field_config($request['config'], $request['type']);

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rcmi_survey_fields WHERE field_key = %s",
        $field_key
    ));
    if ($exists) {
        return new WP_Error('rcmi_tickets_field_key_exists', 'Field key already exists.', ['status' => 409]);
    }

    $sort_order = (int) $wpdb->get_var("SELECT MAX(sort_order) FROM {$wpdb->prefix}rcmi_survey_fields") + 1;

    $wpdb->insert($wpdb->prefix . 'rcmi_survey_fields', [
        'field_key'  => $field_key,
        'label'      => $request['label'],
        'type'       => $request['type'],
        'required'   => !empty($request['required']) ? 1 : 0,
        'sort_order' => $sort_order,
        'config'     => $config ? wp_json_encode($config) : null,
        'created_at' => $now,
        'updated_at' => $now,
    ], ['%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);

    $id = (int) $wpdb->insert_id;
    if (!$id) {
        return new WP_Error('rcmi_tickets_field_create_failed', 'Failed to create field.', ['status' => 500]);
    }

    return new WP_REST_Response(rcmi_tickets_load_survey_field($id), 201);
}

function rcmi_tickets_handle_survey_field_update($request) {
    global $wpdb;
    $id = (int) $request['id'];
    $existing = rcmi_tickets_load_survey_field($id);
    if (!$existing) {
        return new WP_Error('rcmi_tickets_field_not_found', 'Field not found.', ['status' => 404]);
    }

    $data = [];
    $format = [];

    if (isset($request['field_key'])) {
        $new_key = sanitize_key($request['field_key']);
        if ($new_key !== $existing['field_key']) {
            $conflict = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rcmi_survey_fields WHERE field_key = %s AND id != %d",
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
        $wpdb->update($wpdb->prefix . 'rcmi_survey_fields', $data, ['id' => $id], $format, ['%d']);
    }

    return new WP_REST_Response(rcmi_tickets_load_survey_field($id), 200);
}

function rcmi_tickets_handle_survey_field_delete($request) {
    global $wpdb;
    $id = (int) $request['id'];
    // Survey answers keep label/type snapshots precisely so they survive
    // question edits — deleting a field must NOT delete past responses.
    $wpdb->delete($wpdb->prefix . 'rcmi_survey_fields', ['id' => $id], ['%d']);
    return new WP_REST_Response(['deleted' => true, 'id' => $id], 200);
}

function rcmi_tickets_handle_survey_fields_reorder($request) {
    global $wpdb;
    $ids = array_filter(array_map('intval', (array) $request['ids']));
    if (!$ids) {
        return new WP_Error('rcmi_tickets_no_ids', 'No ids provided.', ['status' => 400]);
    }
    $order = 1;
    foreach ($ids as $id) {
        $wpdb->update(
            $wpdb->prefix . 'rcmi_survey_fields',
            ['sort_order' => $order, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
        $order++;
    }
    return new WP_REST_Response(['reordered' => true, 'ids' => $ids], 200);
}

// ── respondent handlers ────────────────────────────────────

function rcmi_tickets_handle_survey_get($request) {
    $ticket = rcmi_tickets_survey_access((int) $request['id'], (string) $request['token']);
    if (is_wp_error($ticket)) {
        return $ticket;
    }
    $ticket_id = (int) $ticket['id'];
    $settings = rcmi_tickets_survey_settings();
    $fields = rcmi_tickets_get_all_survey_fields();
    $response = rcmi_tickets_get_survey_response($ticket_id);

    if ($response) {
        $state = 'submitted';
    } else {
        $elig = rcmi_tickets_survey_eligibility($ticket_id);
        $state = $elig['state'];
    }

    return new WP_REST_Response([
        'ticket_id'       => $ticket_id,
        'ticket_title'    => $ticket['title'],
        'fields'          => $fields,
        'intro_text'      => $settings['intro_text'],
        'thank_you_text'  => $settings['thank_you_text'],
        'ticket_answers'  => rcmi_tickets_survey_ticket_context($ticket_id, $fields),
        'state'           => $state,
        'response'        => $response,
    ], 200);
}

function rcmi_tickets_handle_survey_submit($request) {
    global $wpdb;
    $ticket = rcmi_tickets_survey_access((int) $request['id'], (string) $request['token']);
    if (is_wp_error($ticket)) {
        return $ticket;
    }
    $ticket_id = (int) $ticket['id'];

    if (rcmi_tickets_get_survey_response($ticket_id)) {
        return new WP_Error('rcmi_tickets_survey_submitted', 'This survey has already been submitted.', ['status' => 409]);
    }

    $elig = rcmi_tickets_survey_eligibility($ticket_id);
    if (empty($elig['eligible'])) {
        return new WP_Error('rcmi_tickets_survey_' . ($elig['state'] === 'closed' ? 'closed' : 'unavailable'),
            $elig['state'] === 'closed' ? 'This survey has closed.' : 'This survey is not available.',
            ['status' => 409]);
    }

    $fields = rcmi_tickets_get_all_survey_fields();
    $submitted = (array) $request['answers'];

    // Evaluate conditions against submitted answers + the ticket context.
    $eval_answers = (array) rcmi_tickets_survey_ticket_context($ticket_id, $fields);
    foreach ($submitted as $k => $v) {
        if (is_scalar($v) || is_array($v)) {
            $eval_answers[$k] = $v;
        }
    }

    $errors = [];
    $clean = [];
    foreach ($fields as $f) {
        if ($f['type'] === 'section') {
            continue;
        }
        $key = $f['field_key'];
        $config = is_array($f['config']) ? $f['config'] : [];
        $visible = rcmi_tickets_field_is_visible($config, $eval_answers);
        if (!$visible) {
            continue; // hidden questions are skipped entirely
        }
        $raw = $submitted[$key] ?? null;
        $empty = $raw === null || $raw === '' || (is_array($raw) && count($raw) === 0);
        if ($empty) {
            if ($f['required']) {
                $errors[] = sprintf('%s is required.', $f['label']);
            }
            continue;
        }
        $value = rcmi_tickets_sanitize_survey_answer($f, $raw, $field_error);
        if ($field_error) {
            $errors[] = $field_error;
            continue;
        }
        $clean[] = ['field' => $f, 'value' => $value];
    }

    if ($errors) {
        return new WP_Error('rcmi_tickets_survey_invalid', implode(' ', $errors), ['status' => 400]);
    }

    // Respondent identity: logged-in user, else the ticket's recipient.
    $uid = get_current_user_id();
    $respondent_email = null;
    if ($uid) {
        $u = get_userdata($uid);
        $respondent_email = $u ? $u->user_email : null;
    } else {
        $recipients = rcmi_tickets_survey_recipients($ticket);
        $respondent_email = $recipients[0] ?? null;
    }

    $wpdb->insert($wpdb->prefix . 'rcmi_survey_responses', [
        'ticket_id'          => $ticket_id,
        'respondent_user_id' => $uid ?: null,
        'respondent_email'   => $respondent_email,
        'submitted_at'       => current_time('mysql'),
    ], ['%d', '%d', '%s', '%s']);

    $response_id = (int) $wpdb->insert_id;
    if (!$response_id) {
        // UNIQUE(ticket_id) — a concurrent submit won the race.
        return new WP_Error('rcmi_tickets_survey_submitted', 'This survey has already been submitted.', ['status' => 409]);
    }

    foreach ($clean as $item) {
        $f = $item['field'];
        $wpdb->insert($wpdb->prefix . 'rcmi_survey_answers', [
            'response_id' => $response_id,
            'field_id'    => (int) $f['id'],
            'field_key'   => $f['field_key'],
            'label'       => $f['label'],
            'type'        => $f['type'],
            'value'       => $item['value'],
        ], ['%d', '%d', '%s', '%s', '%s', '%s']);
    }

    return new WP_REST_Response([
        'state'          => 'submitted',
        'thank_you_text' => rcmi_tickets_survey_settings()['thank_you_text'],
        'response'       => rcmi_tickets_get_survey_response($ticket_id),
    ], 201);
}

/**
 * Validate + sanitize a single survey answer by field type.
 * Returns the value to store (arrays JSON-encoded like form_answers);
 * $error is set when the value fails type constraints.
 */
function rcmi_tickets_sanitize_survey_answer($field, $raw, &$error) {
    $error = null;
    $config = is_array($field['config']) ? $field['config'] : [];
    $label = $field['label'];

    switch ($field['type']) {
        case 'rating':
            $max = isset($config['max']) && is_numeric($config['max']) ? (int) $config['max'] : 5;
            $max = min(10, max(3, $max));
            if (!is_numeric($raw) || (int) $raw < 1 || (int) $raw > $max) {
                $error = sprintf('%s must be a rating between 1 and %d.', $label, $max);
                return null;
            }
            return (string) (int) $raw;

        case 'scale':
            $min = isset($config['min']) && is_numeric($config['min']) ? (int) $config['min'] : 0;
            $max = isset($config['max']) && is_numeric($config['max']) ? (int) $config['max'] : 10;
            if (!is_numeric($raw) || (int) $raw < $min || (int) $raw > $max) {
                $error = sprintf('%s must be between %d and %d.', $label, $min, $max);
                return null;
            }
            return (string) (int) $raw;

        case 'number':
            if (!is_numeric($raw)) {
                $error = sprintf('%s must be a number.', $label);
                return null;
            }
            if (!empty($config['integer_only']) && (float) $raw != (int) $raw) {
                $error = sprintf('%s must be a whole number.', $label);
                return null;
            }
            return (string) (0 + $raw);

        case 'checkbox':
        case 'cascade':
            if (!is_array($raw)) {
                $error = sprintf('%s is invalid.', $label);
                return null;
            }
            $values = array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $raw))));
            return $values ? wp_json_encode($values) : null;

        case 'radio':
        case 'dropdown':
            $value = sanitize_text_field((string) $raw);
            $options = (array) ($config['options'] ?? []);
            if ($options && !in_array($value, $options, true) && empty($config['allow_other'])) {
                $error = sprintf('%s is not a valid choice.', $label);
                return null;
            }
            return $value;

        case 'longtext':
            return sanitize_textarea_field((string) $raw);

        case 'date':
            $value = sanitize_text_field((string) $raw);
            if (strtotime($value) === false) {
                $error = sprintf('%s must be a valid date.', $label);
                return null;
            }
            return $value;

        default:
            return sanitize_text_field((string) $raw);
    }
}

// ── manager handlers ───────────────────────────────────────

/**
 * Argument definitions for the response list + CSV export. Filter params
 * mirror the /tickets list: search, author_id (requestor), department,
 * date_from/date_to on submitted_at, and field_filters[key]=value on the
 * stored survey answers.
 */
function rcmi_tickets_survey_responses_args() {
    return [
        'search'     => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
        'author_id'  => ['type' => 'integer', 'sanitize_callback' => 'absint'],
        'department' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
        'date_from'  => ['type' => 'string'],
        'date_to'    => ['type' => 'string'],
        'field_filters' => ['type' => 'object'],
        'page'       => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
        'per_page'   => ['type' => 'integer', 'default' => 12, 'minimum' => 1, 'maximum' => 50],
        'sort'       => ['type' => 'string', 'default' => 'submitted_at',
            'validate_callback' => function ($v) { return in_array($v, ['submitted_at', 'ticket_id'], true); }],
        'order'      => ['type' => 'string', 'default' => 'desc',
            'validate_callback' => function ($v) { return in_array(strtolower($v), ['asc', 'desc'], true); }],
    ];
}

/**
 * Build the shared response-list WHERE clause (aliases: r = responses,
 * t = tickets). Used by the list endpoint and the CSV export so both
 * always filter identically. Returns [where_sql, prepared_args].
 */
function rcmi_tickets_build_survey_responses_where($params) {
    global $wpdb;
    $where = ['1=1'];
    $args = [];

    // Search: ticket id (numeric), ticket title, or the respondent's
    // name/email — the public submitter columns on public tickets, the
    // author's display_name/user_email otherwise, plus the stored
    // respondent identity on the response row itself.
    if (!empty($params['search'])) {
        $s = sanitize_text_field((string) $params['search']);
        $like = '%' . $wpdb->esc_like($s) . '%';
        $parts = [
            't.title LIKE %s',
            't.submitter_name LIKE %s',
            't.submitter_email LIKE %s',
            'r.respondent_email LIKE %s',
            "t.author_id IN (SELECT ID FROM {$wpdb->users} WHERE display_name LIKE %s OR user_email LIKE %s)",
        ];
        $search_args = [$like, $like, $like, $like, $like, $like];
        if (is_numeric($s)) {
            $parts[] = 'r.ticket_id = %d';
            $search_args[] = (int) $s;
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
        $args = array_merge($args, $search_args);
    }

    // Requestor filter (ticket author)
    if (!empty($params['author_id'])) {
        $where[] = 't.author_id = %d';
        $args[] = (int) $params['author_id'];
    }

    // Department filter — resolves the SSO-synced rcmi_department meta on
    // the ticket's author (same mechanism as the /tickets list filter).
    if (!empty($params['department'])) {
        $where[] = "t.author_id IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'rcmi_department' AND meta_value = %s)";
        $args[] = sanitize_text_field($params['department']);
    }

    // Date range on the response's submitted_at
    if (!empty($params['date_from'])) {
        $where[] = 'r.submitted_at >= %s';
        $args[] = $params['date_from'] . ' 00:00:00';
    }
    if (!empty($params['date_to'])) {
        $where[] = 'r.submitted_at <= %s';
        $args[] = $params['date_to'] . ' 23:59:59';
    }

    rcmi_tickets_append_survey_field_filters($params, $where, $args);

    return [implode(' AND ', $where), $args];
}

/**
 * Append survey-question filters to a response list WHERE clause.
 * Expects $params['field_filters'] as a map of field_key => value.
 * A value matches when the stored answer equals it exactly, or when it
 * appears as an element of a JSON-array answer (checkbox/cascade) —
 * same semantics as rcmi_tickets_append_field_filters().
 *
 * @param array $params Request params
 * @param array $where  WHERE fragments (by ref)
 * @param array $args   Prepared-statement args (by ref)
 */
function rcmi_tickets_append_survey_field_filters($params, &$where, &$args) {
    global $wpdb;
    if (empty($params['field_filters']) || !is_array($params['field_filters'])) {
        return;
    }
    // Live field keys plus keys still present on stored answers — deleting
    // a survey field keeps its answers, so they must stay filterable.
    $valid_keys = array_merge(
        array_column(rcmi_tickets_get_all_survey_fields(), 'field_key'),
        (array) $wpdb->get_col("SELECT DISTINCT field_key FROM {$wpdb->prefix}rcmi_survey_answers")
    );
    foreach ($params['field_filters'] as $key => $val) {
        $key = sanitize_key((string) $key);
        $val = sanitize_text_field((string) $val);
        if ($key === '' || $val === '' || !in_array($key, $valid_keys, true)) {
            continue;
        }
        $like = '%"' . $wpdb->esc_like($val) . '"%';
        $where[] = "r.id IN (
            SELECT sa.response_id FROM {$wpdb->prefix}rcmi_survey_answers sa
            WHERE sa.field_key = %s AND (sa.value = %s OR sa.value LIKE %s)
        )";
        $args[] = $key;
        $args[] = $val;
        $args[] = $like;
    }
}

/**
 * Resolve the respondent/requestor display fields for one response row
 * (joined to its ticket). Mirrors rcmi_tickets_survey_recipients(): the
 * external submitter on public tickets, else the ticket's author — with
 * the stored respondent identity as the final fallback.
 *
 * @param array $r Response row with ticket_title/ticket_author_id/
 *                 submitter_name/submitter_email joined in.
 * @return array{respondent_name:string, respondent_email:string,
 *   requestor_email:string, requestor_job_title:string,
 *   requestor_department:string, requestor_photo:string}
 */
function rcmi_tickets_survey_respondent_details($r) {
    $is_public = function_exists('rcmi_tickets_is_public_ticket')
        && rcmi_tickets_is_public_ticket(['author_id' => (int) $r['ticket_author_id']]);
    $requestor_email = '';
    $requestor_job_title = '';
    $requestor_department = '';
    $requestor_photo = '';
    if ($is_public) {
        $respondent_name = (string) ($r['submitter_name'] ?? '');
        $respondent_email = (string) ($r['submitter_email'] ?? '');
        $requestor_email = $respondent_email;
    } else {
        $author = !empty($r['ticket_author_id']) ? get_userdata((int) $r['ticket_author_id']) : null;
        $respondent_name = $author ? $author->display_name : '';
        $respondent_email = $author ? $author->user_email : '';
        $requestor_email = $respondent_email;
        if (!empty($r['ticket_author_id'])) {
            $requestor_job_title = (string) get_user_meta((int) $r['ticket_author_id'], 'rcmi_job_title', true);
            $requestor_department = (string) get_user_meta((int) $r['ticket_author_id'], 'rcmi_department', true);
            $requestor_photo = (string) get_user_meta((int) $r['ticket_author_id'], 'rcmi_photo', true);
        }
    }
    // Fall back to the stored respondent identity when the ticket
    // resolves to nothing (deleted user or orphaned response row).
    if ($respondent_name === '' && $respondent_email === '') {
        $respondent_email = (string) ($r['respondent_email'] ?? '');
        $respondent = !empty($r['respondent_user_id']) ? get_userdata((int) $r['respondent_user_id']) : null;
        if ($respondent) {
            $respondent_name = $respondent->display_name;
            if ($respondent_email === '') {
                $respondent_email = $respondent->user_email;
            }
        }
    }
    if ($respondent_name === '') {
        $respondent_name = $respondent_email;
    }

    return [
        'respondent_name'      => $respondent_name,
        'respondent_email'     => $respondent_email,
        'requestor_email'      => $requestor_email,
        'requestor_job_title'  => $requestor_job_title,
        'requestor_department' => $requestor_department,
        'requestor_photo'      => $requestor_photo,
    ];
}

/**
 * GET /survey-responses — paginated list of all submitted responses for
 * the manager-facing "Survey responses" page (sort + pagination mirror
 * the /tickets list shape: {items, total, page, per_page, total_pages}).
 */
function rcmi_tickets_handle_survey_responses($request) {
    global $wpdb;

    $sort = in_array($request['sort'], ['submitted_at', 'ticket_id'], true) ? $request['sort'] : 'submitted_at';
    $order = strtolower((string) $request['order']) === 'asc' ? 'ASC' : 'DESC';
    $page = max(1, (int) $request['page']);
    $per_page = min(50, max(1, (int) $request['per_page']));
    $offset = ($page - 1) * $per_page;

    [$where_clause, $args] = rcmi_tickets_build_survey_responses_where($request->get_params());

    // LEFT JOIN keeps the response listed even if its ticket was deleted.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, t.title AS ticket_title, t.author_id AS ticket_author_id,
                t.submitter_name, t.submitter_email
         FROM {$wpdb->prefix}rcmi_survey_responses r
         LEFT JOIN {$wpdb->prefix}rcmi_tickets t ON t.id = r.ticket_id
         WHERE {$where_clause}
         ORDER BY r.{$sort} {$order}, r.id {$order}
         LIMIT %d OFFSET %d",
        array_merge($args, [$per_page, $offset])
    ), ARRAY_A);

    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}rcmi_survey_responses r
         LEFT JOIN {$wpdb->prefix}rcmi_tickets t ON t.id = r.ticket_id
         WHERE {$where_clause}",
        $args
    ));

    // One query for the whole page's answers — they come back in insert
    // (id ASC) order, which is the field order at submit time.
    $answers_by_response = [];
    $response_ids = array_map('intval', wp_list_pluck($rows, 'id'));
    if ($response_ids) {
        $id_placeholders = implode(',', array_fill(0, count($response_ids), '%d'));
        $answer_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rcmi_survey_answers WHERE response_id IN ($id_placeholders) ORDER BY id ASC",
            $response_ids
        ), ARRAY_A);
        foreach ($answer_rows as $a) {
            $answers_by_response[(int) $a['response_id']][] = $a;
        }
    }

    $items = [];
    foreach ($rows as $r) {
        $people = rcmi_tickets_survey_respondent_details($r);
        $items[] = [
            'id'               => (int) $r['id'],
            'ticket_id'        => (int) $r['ticket_id'],
            'ticket_title'     => (string) ($r['ticket_title'] ?? ''),
            'respondent_name'  => $people['respondent_name'],
            'respondent_email' => $people['respondent_email'],
            'requestor_email'  => $people['requestor_email'],
            'requestor_job_title' => $people['requestor_job_title'],
            'requestor_department' => $people['requestor_department'],
            'requestor_photo'  => $people['requestor_photo'],
            'submitted_at'     => $r['submitted_at'],
            'answers'          => array_map('rcmi_tickets_format_survey_answer', $answers_by_response[(int) $r['id']] ?? []),
        ];
    }

    return new WP_REST_Response([
        'items'       => $items,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => (int) ceil($total / $per_page),
    ], 200);
}

/**
 * GET /survey-field-values — distinct stored values for a survey question
 * with per-value response counts. JSON-array answers (checkbox groups,
 * cascade paths) are exploded into elements so every selectable value can
 * be filtered — same semantics as /field-values. Deleted fields keep
 * their stored answers, so a key with answers resolves even when no live
 * survey field carries it.
 */
function rcmi_tickets_handle_survey_field_values($request) {
    global $wpdb;
    $key = sanitize_key((string) $request->get_param('field_key'));

    $known = in_array($key, array_column(rcmi_tickets_get_all_survey_fields(), 'field_key'), true)
        || (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}rcmi_survey_answers WHERE field_key = %s LIMIT 1",
            $key
        ));
    if (!$known) {
        return new WP_Error('rcmi_tickets_field_not_found', 'Unknown field.', ['status' => 404]);
    }

    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT sa.value FROM {$wpdb->prefix}rcmi_survey_answers sa WHERE sa.field_key = %s",
        $key
    ));

    $counts = [];
    foreach ($rows as $v) {
        $decoded = json_decode((string) $v, true);
        $items = is_array($decoded) ? $decoded : [$v];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            $counts[$item] = ($counts[$item] ?? 0) + 1;
        }
    }
    arsort($counts);

    $values = [];
    foreach (array_slice($counts, 0, 500, true) as $value => $count) {
        $values[] = ['value' => $value, 'count' => $count];
    }
    return new WP_REST_Response(['field_key' => $key, 'values' => $values], 200);
}

/**
 * CSV export: returns all matching responses (same filters as the list,
 * no pagination) as a CSV download. Primary columns mirror the on-screen
 * table; each survey field adds a column headed by its label.
 */
function rcmi_tickets_handle_survey_export($request) {
    global $wpdb;

    $params = $request->get_params();
    [$where_clause, $args] = rcmi_tickets_build_survey_responses_where($params);

    $sort = in_array($params['sort'] ?? 'submitted_at', ['submitted_at', 'ticket_id'], true) ? $params['sort'] : 'submitted_at';
    $order = strtolower((string) ($params['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    // Fetch ALL matching rows (no pagination for export), capped at a
    // sane maximum like the ticket export.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, t.title AS ticket_title, t.author_id AS ticket_author_id,
                t.submitter_name, t.submitter_email
         FROM {$wpdb->prefix}rcmi_survey_responses r
         LEFT JOIN {$wpdb->prefix}rcmi_tickets t ON t.id = r.ticket_id
         WHERE {$where_clause}
         ORDER BY r.{$sort} {$order}, r.id {$order}
         LIMIT 5000",
        $args
    ), ARRAY_A);

    // Answers keyed by response_id → field_id (id ASC = field order at
    // submit time); deleted fields keep no column but stay in the data.
    $answers_by_response = [];
    $response_ids = array_map('intval', wp_list_pluck($rows, 'id'));
    if ($response_ids) {
        $id_placeholders = implode(',', array_fill(0, count($response_ids), '%d'));
        $answer_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rcmi_survey_answers WHERE response_id IN ($id_placeholders) ORDER BY id ASC",
            $response_ids
        ), ARRAY_A);
        foreach ($answer_rows as $a) {
            $answers_by_response[(int) $a['response_id']][(int) $a['field_id']] = $a;
        }
    }

    // One column per survey question, in builder order.
    $survey_fields = rcmi_tickets_get_all_survey_fields();

    $headers = array_merge(
        ['Response ID', 'Ticket ID', 'Ticket Title', 'Respondent', 'Email', 'Job Title', 'Department', 'Submitted'],
        array_map(function ($f) { return $f['label']; }, $survey_fields)
    );

    $output = fopen('php://temp', 'r+');
    // BOM for Excel UTF-8 compatibility
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers);

    foreach ($rows as $r) {
        $people = rcmi_tickets_survey_respondent_details($r);
        $answers = $answers_by_response[(int) $r['id']] ?? [];

        $field_values = [];
        foreach ($survey_fields as $f) {
            $a = $answers[(int) $f['id']] ?? null;
            $val = '';
            if ($a) {
                $formatted = rcmi_tickets_format_survey_answer($a);
                $val = $formatted['value'];
                if (is_array($val)) {
                    $val = implode($a['type'] === 'cascade' ? ' › ' : '; ', $val);
                }
            }
            $field_values[] = $val;
        }

        fputcsv($output, array_merge([
            (int) $r['id'],
            (int) $r['ticket_id'],
            (string) ($r['ticket_title'] ?? ''),
            $people['respondent_name'],
            $people['respondent_email'],
            $people['requestor_job_title'],
            $people['requestor_department'],
            (string) $r['submitted_at'],
        ], $field_values));
    }

    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);

    $filename = 'survey-responses-' . current_time('Y-m-d-His') . '.csv';

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

function rcmi_tickets_handle_ticket_survey_get($request) {
    $ticket_id = (int) $request['id'];
    if (!rcmi_tickets_load_ticket($ticket_id)) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    return new WP_REST_Response([
        'eligibility' => rcmi_tickets_survey_eligibility($ticket_id),
        'survey_url'  => rcmi_tickets_survey_url($ticket_id),
        'response'    => rcmi_tickets_get_survey_response($ticket_id),
        'emails'      => rcmi_tickets_get_survey_email_log($ticket_id),
    ], 200);
}

function rcmi_tickets_handle_ticket_survey_resend($request) {
    $ticket_id = (int) $request['id'];
    if (!rcmi_tickets_load_ticket($ticket_id)) {
        return new WP_Error('rcmi_tickets_not_found', 'Ticket not found.', ['status' => 404]);
    }
    if (rcmi_tickets_get_survey_response($ticket_id)) {
        return new WP_Error('rcmi_tickets_survey_submitted', 'This survey has already been submitted.', ['status' => 409]);
    }
    $elig = rcmi_tickets_survey_eligibility($ticket_id);
    if (empty($elig['eligible'])) {
        return new WP_Error('rcmi_tickets_survey_ineligible', 'This ticket is not eligible for the survey.', ['status' => 409]);
    }

    $sent = rcmi_tickets_send_survey_email($ticket_id, 'manual', get_current_user_id());
    if (!$sent) {
        return new WP_Error('rcmi_tickets_survey_no_recipient', 'No survey recipient could be resolved for this ticket.', ['status' => 400]);
    }

    return new WP_REST_Response([
        'sent'   => $sent,
        'emails' => rcmi_tickets_get_survey_email_log($ticket_id),
    ], 200);
}
