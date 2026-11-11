<?php
/**
 * Completed-email flag regression checks (schema v16). Run under WP-CLI:
 *   php wp-cli.phar eval-file wp-content/plugins/rcmi-tickets/tests/check-completion-email.php
 *
 * Covers rcmi_tickets_chain_email_settings defaults (no chain / deleted
 * chain), the four completion_email_enabled × survey_enabled combinations
 * rendered through rcmi_tickets_email_preview_collect (at most one email,
 * correct sections in HTML + plain), survey-only ineligible suppression
 * (global off / no fields), initial-invite logging only when the invite
 * actually rides in the email, hostile-title placeholder escaping, the
 * configurable button, and REST create defaults + partial-PUT flag
 * preservation. Fixture rows/options are cleaned up in finally; nothing
 * is really mailed (preview dry-run).
 */

if (!function_exists('rcmi_tickets_email_status_changed')) {
    fwrite(STDERR, "Plugin functions not loaded — run via wp-cli eval-file.\n");
    exit(1);
}

function ce_check($label, $cond) {
    if ($cond) {
        $GLOBALS['ce_pass'] = ($GLOBALS['ce_pass'] ?? 0) + 1;
        echo "  ok    $label\n";
    } else {
        $GLOBALS['ce_errors'][] = $label;
        echo "  FAIL  $label\n";
    }
}

// Capture what a Completed transition would send for a ticket — never sends.
function ce_capture_completed($ticket_id) {
    return rcmi_tickets_email_preview_collect(function () use ($ticket_id) {
        rcmi_tickets_email_status_changed($ticket_id, 'Completed', 'In Progress', null);
    });
}

function ce_capture_survey($ticket_id, $kind) {
    return rcmi_tickets_email_preview_collect(function () use ($ticket_id, $kind) {
        rcmi_tickets_send_survey_email($ticket_id, $kind, 0);
    });
}

function ce_initial_log_count($ticket_id) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}rcmi_survey_emails WHERE ticket_id = %d AND kind = 'initial'",
        (int) $ticket_id
    ));
}

global $wpdb;

// Apply any pending schema upgrade through the plugin's normal upgrade
// path — idempotent and a no-op when the stored version is current —
// rather than running dbDelta unconditionally.
rcmi_tickets_maybe_upgrade_schema();
$v16_col = (bool) $wpdb->get_var(
    "SHOW COLUMNS FROM {$wpdb->prefix}rcmi_approval_chains LIKE 'completion_email_enabled'");
ce_check('v16 column exists', $v16_col);
if (!$v16_col) {
    fwrite(STDERR, "Prerequisite missing: rcmi_approval_chains.completion_email_enabled "
        . "(stored schema version " . get_option('rcmi_tickets_db_version', '?')
        . "). Run the plugin's schema upgrade first.\n");
    exit(1);
}

$saved_cm      = get_option('rcmi_tickets_completion_message', null);
$saved_survey  = get_option('rcmi_tickets_survey_settings', null);
$saved_uid     = get_current_user_id();

$admin = get_users(['role' => 'administrator', 'number' => 1]);
$author_id = $admin ? (int) $admin[0]->ID : 1;

$created = [
    'field_id' => 0, 'ticket_id' => 0, 'orphan_id' => 0, 'log_ids' => [],
    'chain_id' => 0, 'approval_ids' => [], 'rest_chain_id' => 0,
];

try {
    $now = current_time('mysql');

    // Distinctive markers so section presence/absence asserts are
    // unambiguous; both options are restored in the finally block.
    update_option('rcmi_tickets_completion_message', '<p>ZCE completion note</p>');
    update_option('rcmi_tickets_survey_settings', array_merge(rcmi_tickets_survey_default_settings(), [
        'enabled'       => true,
        'email_subject' => 'ZCE subject #{ticket}',
        'email_lead'    => '<p>ZCE invite on {title}?</p>',
        'reminder_lead' => '<p>ZCE reminder</p>',
        'email_button'  => 'ZCE BTN',
    ]));

    // One non-section survey question (eligibility requires it).
    $wpdb->insert($wpdb->prefix . 'rcmi_survey_fields', [
        'field_key' => 'test_ce_q', 'label' => 'CE question', 'type' => 'rating',
        'required' => 0, 'sort_order' => 9999, 'config' => wp_json_encode(['max' => 5]),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $created['field_id'] = (int) $wpdb->insert_id;

    // Main fixture ticket: hostile title exercises {title} escaping.
    $wpdb->insert($wpdb->prefix . 'rcmi_tickets', [
        'author_id' => $author_id, 'title' => 'Ce <script>alert(1)</script> ticket',
        'status' => 'Completed', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $created['ticket_id'] = (int) $wpdb->insert_id;

    // Orphan ticket: no approval rows at first.
    $wpdb->insert($wpdb->prefix . 'rcmi_tickets', [
        'author_id' => $author_id, 'title' => 'Ce orphan ticket',
        'status' => 'Completed', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $created['orphan_id'] = (int) $wpdb->insert_id;

    foreach ([$created['ticket_id'], $created['orphan_id']] as $tid) {
        $wpdb->insert($wpdb->prefix . 'rcmi_ticket_status_log', [
            'ticket_id' => $tid, 'old_status' => 'In Progress',
            'new_status' => 'Completed', 'changed_by' => 0, 'changed_at' => $now,
        ]);
        $created['log_ids'][] = (int) $wpdb->insert_id;
    }

    // Chain + approval linking the main ticket to it.
    $wpdb->insert($wpdb->prefix . 'rcmi_approval_chains', [
        'name' => 'CE flag chain', 'is_active' => 1,
        'survey_enabled' => 1, 'completion_email_enabled' => 1,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $created['chain_id'] = (int) $wpdb->insert_id;
    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_approvals', [
        'ticket_id' => $created['ticket_id'], 'chain_id' => $created['chain_id'],
        'step_id' => 0, 'sort_order' => 1, 'status' => 'approved',
    ]);
    $created['approval_ids'][] = (int) $wpdb->insert_id;

    $tid    = $created['ticket_id'];
    $cid    = $created['chain_id'];
    $ticket = rcmi_tickets_load_ticket($tid);
    $set_flags = function ($completion, $survey) use ($wpdb, $cid) {
        $wpdb->update($wpdb->prefix . 'rcmi_approval_chains', [
            'completion_email_enabled' => $completion ? 1 : 0,
            'survey_enabled'           => $survey ? 1 : 0,
        ], ['id' => $cid]);
    };

    // ── 1. Chain email settings helper ──────────────────────

    echo "Chain email settings\n";

    ce_check('no approval row → both true',
        rcmi_tickets_chain_email_settings($created['orphan_id'])
            === ['completion_email_enabled' => true, 'survey_enabled' => true]);

    // Approval pointing at a chain that is then deleted → LEFT JOIN NULL.
    $wpdb->insert($wpdb->prefix . 'rcmi_approval_chains', [
        'name' => 'CE tmp chain', 'is_active' => 1,
        'survey_enabled' => 0, 'completion_email_enabled' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $tmp_chain = (int) $wpdb->insert_id;
    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_approvals', [
        'ticket_id' => $created['orphan_id'], 'chain_id' => $tmp_chain,
        'step_id' => 0, 'sort_order' => 1, 'status' => 'approved',
    ]);
    $created['approval_ids'][] = (int) $wpdb->insert_id;
    $wpdb->delete($wpdb->prefix . 'rcmi_approval_chains', ['id' => $tmp_chain], ['%d']);

    $settings = rcmi_tickets_chain_email_settings($created['orphan_id']);
    ce_check('deleted chain → both true',
        $settings === ['completion_email_enabled' => true, 'survey_enabled' => true]);
    $cap = ce_capture_completed($created['orphan_id']);
    ce_check('deleted chain → one email', count($cap) === 1);
    ce_check('deleted chain → both sections',
        $cap && strpos($cap[0]['html'], 'ZCE completion note') !== false
        && strpos($cap[0]['html'], 'ZCE invite on') !== false);

    // Latest approval row wins: a second, later approval to the flag chain.
    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_approvals', [
        'ticket_id' => $created['orphan_id'], 'chain_id' => $cid,
        'step_id' => 0, 'sort_order' => 2, 'status' => 'approved',
    ]);
    $created['approval_ids'][] = (int) $wpdb->insert_id;
    $set_flags(false, false);
    ce_check('latest chain row applies',
        rcmi_tickets_chain_email_settings($created['orphan_id'])
            === ['completion_email_enabled' => false, 'survey_enabled' => false]);
    $set_flags(true, true);

    // ── 2. Four flag combinations ────────────────────────────

    echo "Flag combinations\n";

    $log0 = ce_initial_log_count($tid);

    // completion=1, survey=1 → one email, both sections, invite logged.
    $set_flags(true, true);
    $cap = ce_capture_completed($tid);
    ce_check('both → exactly one email', count($cap) === 1);
    ce_check('both → Completed subject kept',
        $cap && strpos($cap[0]['subject'], 'Completed') !== false
        && strpos($cap[0]['subject'], 'ZCE subject') === false);
    ce_check('both → completion then survey in html',
        $cap && strpos($cap[0]['html'], 'ZCE completion note') !== false
        && strpos($cap[0]['html'], 'ZCE completion note') < strpos($cap[0]['html'], 'ZCE invite on'));
    ce_check('both → completion above box, invite inside it',
        $cap && strpos($cap[0]['html'], 'ZCE completion note') !== false
        && strpos($cap[0]['html'], 'ZCE completion note') < strpos($cap[0]['html'], 'background:#f0fdf4')
        && strpos($cap[0]['html'], 'ZCE invite on') > strpos($cap[0]['html'], 'background:#f0fdf4'));
    ce_check('both → survey button + url in html',
        $cap && strpos($cap[0]['html'], '>ZCE BTN</a>') !== false
        && strpos($cap[0]['html'], '#/survey/' . $tid) !== false);
    ce_check('both → no View ticket button (ticket ref linked)',
        $cap && strpos($cap[0]['html'], '>View ticket</a>') === false
        && strpos($cap[0]['html'], '#/ticket/' . $tid) !== false);
    ce_check('both → plain has both sections',
        $cap && strpos($cap[0]['plain'], 'ZCE completion note') !== false
        && strpos($cap[0]['plain'], 'ZCE invite on') !== false
        && strpos($cap[0]['plain'], 'ZCE BTN: ') !== false);
    ce_check('both → hostile title escaped, placeholders substituted',
        $cap && strpos($cap[0]['html'], 'ZCE invite on Ce &lt;script&gt;alert(1)&lt;/script&gt; ticket?') !== false
        && strpos($cap[0]['html'], '<script>alert(1)</script>') === false);
    ce_check('both → initial invite logged',
        ce_initial_log_count($tid) === $log0 + 1);
    $log0 = ce_initial_log_count($tid);

    // completion=1, survey=0 → completion only, normal View ticket CTA.
    $set_flags(true, false);
    $cap = ce_capture_completed($tid);
    ce_check('completion-only → exactly one email', count($cap) === 1);
    ce_check('completion-only → message present',
        $cap && strpos($cap[0]['html'], 'ZCE completion note') !== false);
    ce_check('completion-only → no survey copy/button',
        $cap && strpos($cap[0]['html'], 'ZCE invite') === false
        && strpos($cap[0]['html'], 'ZCE BTN') === false
        && strpos($cap[0]['html'], '#/survey/') === false);
    ce_check('completion-only → no green survey box',
        $cap && strpos($cap[0]['html'], '#f0fdf4') === false);
    ce_check('completion-only → View ticket CTA kept',
        $cap && strpos($cap[0]['html'], '>View ticket</a>') !== false);
    ce_check('completion-only → plain matches',
        $cap && strpos($cap[0]['plain'], 'ZCE completion note') !== false
        && strpos($cap[0]['plain'], 'ZCE BTN') === false);
    ce_check('completion-only → no invite logged',
        ce_initial_log_count($tid) === $log0);

    // completion=0, survey=1 → survey only, survey CTA + linked ticket ref.
    $set_flags(false, true);
    $cap = ce_capture_completed($tid);
    ce_check('survey-only → exactly one email', count($cap) === 1);
    ce_check('survey-only → invite + button, no message',
        $cap && strpos($cap[0]['html'], 'ZCE invite on') !== false
        && strpos($cap[0]['html'], '>ZCE BTN</a>') !== false
        && strpos($cap[0]['html'], 'ZCE completion note') === false);
    ce_check('survey-only → no View ticket button',
        $cap && strpos($cap[0]['html'], '>View ticket</a>') === false);
    ce_check('survey-only → plain has invite, not message',
        $cap && strpos($cap[0]['plain'], 'ZCE invite on') !== false
        && strpos($cap[0]['plain'], 'ZCE completion note') === false);
    ce_check('survey-only → initial invite logged',
        ce_initial_log_count($tid) === $log0 + 1);
    $log0 = ce_initial_log_count($tid);

    // completion=0, survey=0 → no email at all.
    $set_flags(false, false);
    $cap = ce_capture_completed($tid);
    ce_check('neither → zero emails', count($cap) === 0);
    ce_check('neither → nothing logged', ce_initial_log_count($tid) === $log0);

    // ── 3. Survey-only but ineligible → no email ─────────────

    echo "Survey-only ineligible\n";

    $set_flags(false, true);

    // Globally disabled survey.
    $ss = rcmi_tickets_survey_settings();
    update_option('rcmi_tickets_survey_settings', array_merge($ss, ['enabled' => false]));
    $cap = ce_capture_completed($tid);
    ce_check('survey disabled → zero emails', count($cap) === 0);
    ce_check('survey disabled → no invite logged', ce_initial_log_count($tid) === $log0);
    update_option('rcmi_tickets_survey_settings', $ss);

    // No non-section questions at all → simulate an empty field list by
    // intercepting only the survey-fields SELECT (exact string from
    // rcmi_tickets_get_all_survey_fields); all other queries pass through
    // and no rows are mutated.
    $fields_query = "SELECT f.*,
                (SELECT COUNT(*) FROM {$wpdb->prefix}rcmi_survey_answers sa WHERE sa.field_id = f.id) AS answer_count
         FROM {$wpdb->prefix}rcmi_survey_fields f
         ORDER BY sort_order ASC, id ASC";
    $empty_fields = function ($query) use ($fields_query, $wpdb) {
        return $query === $fields_query
            ? "SELECT * FROM {$wpdb->prefix}rcmi_survey_fields WHERE 1 = 0"
            : $query;
    };
    add_filter('query', $empty_fields);
    try {
        $cap = ce_capture_completed($tid);
        ce_check('no questions → zero emails', count($cap) === 0);
        ce_check('no questions → no invite logged', ce_initial_log_count($tid) === $log0);
    } finally {
        remove_filter('query', $empty_fields);
    }

    $set_flags(true, true);

    // ── 4. Survey email content helper + standalone sender ───

    echo "Survey email content\n";

    $manual = rcmi_tickets_survey_email_content($ticket, 'manual');
    ce_check('manual → invitation subject',
        $manual['subject'] === 'ZCE subject #' . $tid);
    ce_check('manual → invitation lead', strpos($manual['lead_plain'], 'ZCE invite on') !== false);
    ce_check('initial → same invitation copy',
        rcmi_tickets_survey_email_content($ticket, 'initial') === $manual);
    $reminder = rcmi_tickets_survey_email_content($ticket, 'reminder');
    ce_check('reminder → prefixed subject',
        strpos($reminder['subject'], 'Reminder: ') === 0);
    ce_check('reminder → reminder lead',
        strpos($reminder['lead_plain'], 'ZCE reminder') !== false);
    ce_check('configurable button returned', $manual['button'] === 'ZCE BTN');

    $cap = ce_capture_survey($tid, 'manual');
    ce_check('standalone manual → one email with configured subject + button',
        count($cap) === 1
        && $cap[0]['subject'] === 'ZCE subject #' . $tid
        && strpos($cap[0]['html'], '>ZCE BTN</a>') !== false
        && strpos($cap[0]['html'], 'ZCE invite on') !== false);
    $cap = ce_capture_survey($tid, 'reminder');
    ce_check('standalone reminder → prefixed subject + reminder lead',
        count($cap) === 1
        && strpos($cap[0]['subject'], 'Reminder: ') === 0
        && strpos($cap[0]['html'], 'ZCE reminder') !== false);

    // ── 5. REST create defaults + partial PUT ────────────────

    echo "REST flags\n";

    $mgr = get_user_by('login', 'ticketmgr1') ?: ($admin ? $admin[0] : null);
    ce_check('manager fixture user found', (bool) $mgr);
    if ($mgr) {
        wp_set_current_user((int) $mgr->ID);
    }

    $rest = function ($method, $route, $body = null) {
        $req = new WP_REST_Request($method, $route);
        if ($body !== null) {
            $req->set_header('Content-Type', 'application/json');
            $req->set_body(wp_json_encode($body));
        }
        return rest_do_request($req);
    };

    $r = $rest('POST', '/rcmi/v1/approval-chains', [
        'name'  => 'CE REST chain',
        'steps' => [['name' => 'S1', 'approver_type' => 'role', 'approver_role' => 'rcmi_ticket_manager']],
    ]);
    ce_check('create accepted', in_array($r->get_status(), [200, 201], true));
    $rest_chain = $r->get_data();
    $created['rest_chain_id'] = (int) ($rest_chain['id'] ?? 0);
    ce_check('create defaults both flags on',
        !empty($rest_chain['completion_email_enabled']) && !empty($rest_chain['survey_enabled']));

    // Explicitly off round-trips as off.
    $r = $rest('PUT', '/rcmi/v1/approval-chains/' . $created['rest_chain_id'], [
        'survey_enabled' => false, 'completion_email_enabled' => false,
    ]);
    $rest_chain = $r->get_data();
    ce_check('PUT sets both flags off',
        isset($rest_chain['survey_enabled'], $rest_chain['completion_email_enabled'])
        && $rest_chain['survey_enabled'] === false && $rest_chain['completion_email_enabled'] === false);

    // A partial PUT that never mentions the flags must not reset them.
    $r = $rest('PUT', '/rcmi/v1/approval-chains/' . $created['rest_chain_id'], [
        'name' => 'CE REST chain renamed',
    ]);
    $rest_chain = $r->get_data();
    ce_check('partial PUT preserves both flags off',
        $rest_chain['survey_enabled'] === false && $rest_chain['completion_email_enabled'] === false);
    ce_check('partial PUT applies the edit', $rest_chain['name'] === 'CE REST chain renamed');

    // GET round-trip exposes both flags.
    $r = $rest('GET', '/rcmi/v1/approval-chains/' . $created['rest_chain_id']);
    $rest_chain = $r->get_data();
    ce_check('GET round-trips both flags',
        array_key_exists('completion_email_enabled', $rest_chain)
        && array_key_exists('survey_enabled', $rest_chain));

    // Permissions still gate the endpoint.
    $outsider = get_user_by('login', 'outsider1');
    if ($outsider) {
        wp_set_current_user((int) $outsider->ID);
        $r = $rest('POST', '/rcmi/v1/approval-chains', [
            'name'  => 'Nope',
            'steps' => [['name' => 'S1', 'approver_type' => 'role', 'approver_role' => 'rcmi_ticket_manager']],
        ]);
        ce_check('non-manager create denied', in_array($r->get_status(), [401, 403], true));
    }
    wp_set_current_user(0);
    $r = $rest('POST', '/rcmi/v1/approval-chains', [
        'name'  => 'Nope',
        'steps' => [['name' => 'S1', 'approver_type' => 'role', 'approver_role' => 'rcmi_ticket_manager']],
    ]);
    ce_check('anonymous create denied', in_array($r->get_status(), [401, 403], true));
    if ($mgr) {
        wp_set_current_user((int) $mgr->ID);
    }
} finally {
    // Clean up every row this test created; restore touched options.
    foreach ($created['approval_ids'] as $aid) {
        $wpdb->delete($wpdb->prefix . 'rcmi_ticket_approvals', ['id' => $aid], ['%d']);
    }
    foreach ([$created['chain_id'], $created['rest_chain_id']] as $chain_id) {
        if ($chain_id) {
            $wpdb->delete($wpdb->prefix . 'rcmi_approval_steps', ['chain_id' => $chain_id], ['%d']);
            $wpdb->delete($wpdb->prefix . 'rcmi_approval_chains', ['id' => $chain_id], ['%d']);
        }
    }
    foreach ($created['log_ids'] as $lid) {
        $wpdb->delete($wpdb->prefix . 'rcmi_ticket_status_log', ['id' => $lid], ['%d']);
    }
    foreach ([$created['ticket_id'], $created['orphan_id']] as $tid) {
        if ($tid) {
            $wpdb->delete($wpdb->prefix . 'rcmi_survey_emails', ['ticket_id' => $tid], ['%d']);
            $wpdb->delete($wpdb->prefix . 'rcmi_tickets', ['id' => $tid], ['%d']);
        }
    }
    if ($created['field_id']) {
        $wpdb->delete($wpdb->prefix . 'rcmi_survey_fields', ['id' => $created['field_id']], ['%d']);
    }
    if ($saved_cm === null) {
        delete_option('rcmi_tickets_completion_message');
    } else {
        update_option('rcmi_tickets_completion_message', $saved_cm);
    }
    if ($saved_survey === null) {
        delete_option('rcmi_tickets_survey_settings');
    } else {
        update_option('rcmi_tickets_survey_settings', $saved_survey);
    }
    wp_set_current_user($saved_uid);
}

// ── Summary ────────────────────────────────────────────────

$errors = $GLOBALS['ce_errors'] ?? [];
if ($errors) {
    fwrite(STDERR, "\n" . count($errors) . " check(s) failed:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
printf("\nCompletion-email check passed: %d assertions.%s", $GLOBALS['ce_pass'] ?? 0, "\n");
