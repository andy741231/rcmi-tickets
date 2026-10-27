<?php
/**
 * Survey feature regression checks. Run under WP-CLI:
 *   php wp-cli.phar eval-file wp-content/plugins/rcmi-tickets/tests/check-survey.php
 *
 * Covers the condition evaluator (legacy + v2, show/hide, cascade arrays,
 * ticket: context keys), the legacy→v2 sanitizer, survey tokens,
 * reminder scheduling, and chain opt-out eligibility.
 */

if (!function_exists('rcmi_tickets_field_is_visible')) {
    fwrite(STDERR, "Plugin functions not loaded — run via wp-cli eval-file.\n");
    exit(1);
}

$errors = [];
$pass = 0;

// eval-file wraps this file in a function, so plain `global` doesn't reach
// the file's top-level vars — collect results through $GLOBALS instead.
function survey_check($label, $cond) {
    if ($cond) {
        $GLOBALS['survey_pass'] = ($GLOBALS['survey_pass'] ?? 0) + 1;
        echo "  ok    $label\n";
    } else {
        $GLOBALS['survey_errors'][] = $label;
        echo "  FAIL  $label\n";
    }
}

// ── 1. Condition evaluator ─────────────────────────────────

echo "Evaluator\n";

// Legacy single-rule shape.
$legacy = ['logic' => ['action' => 'show', 'field_key' => 'service', 'op' => 'equals', 'value' => 'IT']];
survey_check('legacy show/equals true',  rcmi_tickets_field_is_visible($legacy, ['service' => 'IT']) === true);
survey_check('legacy show/equals false', rcmi_tickets_field_is_visible($legacy, ['service' => 'HR']) === false);
survey_check('legacy hide/equals',       rcmi_tickets_field_is_visible(['logic' => ['action' => 'hide', 'field_key' => 'service', 'op' => 'equals', 'value' => 'IT']], ['service' => 'IT']) === false);
survey_check('legacy hide/equals miss → visible', rcmi_tickets_field_is_visible(['logic' => ['action' => 'hide', 'field_key' => 'service', 'op' => 'equals', 'value' => 'IT']], ['service' => 'HR']) === true);

// v2 all/any.
$v2_all = ['logic' => ['action' => 'show', 'match' => 'all', 'rules' => [
    ['field_key' => 'service', 'op' => 'equals', 'value' => 'IT'],
    ['field_key' => 'vip', 'op' => 'equals', 'value' => 'yes'],
]]];
$v2_any = ['logic' => ['action' => 'show', 'match' => 'any', 'rules' => $v2_all['logic']['rules']]];
survey_check('v2 all both match',   rcmi_tickets_field_is_visible($v2_all, ['service' => 'IT', 'vip' => 'yes']) === true);
survey_check('v2 all one misses',   rcmi_tickets_field_is_visible($v2_all, ['service' => 'IT', 'vip' => 'no']) === false);
survey_check('v2 any one matches',  rcmi_tickets_field_is_visible($v2_any, ['service' => 'IT', 'vip' => 'no']) === true);
survey_check('v2 any none match',   rcmi_tickets_field_is_visible($v2_any, ['service' => 'HR', 'vip' => 'no']) === false);
survey_check('v2 hide when any match', rcmi_tickets_field_is_visible(
    ['logic' => ['action' => 'hide', 'match' => 'any', 'rules' => $v2_all['logic']['rules']]],
    ['service' => 'HR', 'vip' => 'yes']
) === false);

// Cascade array values — middle-level match on a stored path like
// "Administrative Service › Graphic Design › Newsletter".
$cascade_path = ['Administrative Service', 'Graphic Design', 'Newsletter'];
survey_check('cascade contains middle level', rcmi_tickets_field_is_visible(
    ['logic' => ['action' => 'show', 'match' => 'all', 'rules' => [
        ['field_key' => 'service', 'op' => 'contains', 'value' => 'Graphic Design'],
    ]]],
    ['service' => $cascade_path]
) === true);
survey_check('cascade not_contains middle level', rcmi_tickets_field_is_visible(
    ['logic' => ['action' => 'show', 'match' => 'all', 'rules' => [
        ['field_key' => 'service', 'op' => 'not_contains', 'value' => 'Graphic Design'],
    ]]],
    ['service' => $cascade_path]
) === false);
survey_check('string contains still works', rcmi_tickets_field_is_visible(
    ['logic' => ['field_key' => 'note', 'op' => 'contains', 'value' => 'urgent', 'action' => 'show']],
    ['note' => 'this is urgent work']
) === true);

// ticket: context keys — survey fields read the ticket's form answers.
survey_check('ticket: context key', rcmi_tickets_field_is_visible(
    ['logic' => ['action' => 'show', 'match' => 'all', 'rules' => [
        ['field_key' => 'ticket:service', 'op' => 'contains', 'value' => 'Graphic Design'],
    ]]],
    ['ticket:service' => $cascade_path, 'rating' => '5']
) === true);
survey_check('ticket: context key miss', rcmi_tickets_field_is_visible(
    ['logic' => ['action' => 'show', 'match' => 'all', 'rules' => [
        ['field_key' => 'ticket:service', 'op' => 'equals', 'value' => 'IT'],
    ]]],
    ['ticket:service' => 'HR']
) === false);

// No logic → always visible.
survey_check('no logic → visible', rcmi_tickets_field_is_visible([], []) === true);
survey_check('empty rules → visible', rcmi_tickets_field_is_visible(['logic' => ['action' => 'show', 'match' => 'all', 'rules' => []]], []) === true);

// ── 2. Legacy → v2 sanitizer ───────────────────────────────

echo "Sanitizer\n";

$norm = rcmi_tickets_normalize_field_logic(['action' => 'hide', 'field_key' => 'a', 'op' => 'not_equals', 'value' => 'x']);
survey_check('legacy → v2 action', $norm['action'] === 'hide');
survey_check('legacy → v2 match all', $norm['match'] === 'all');
survey_check('legacy → v2 single rule', count($norm['rules']) === 1 && $norm['rules'][0]['field_key'] === 'a' && $norm['rules'][0]['op'] === 'not_equals');

$norm2 = rcmi_tickets_normalize_field_logic(['action' => 'show', 'match' => 'any', 'rules' => [
    ['field_key' => 'a', 'op' => 'bogus', 'value' => '1'],
    ['field_key' => '', 'op' => 'equals', 'value' => '2'], // dropped: no field_key
]]);
survey_check('v2 keeps match any', $norm2['match'] === 'any');
survey_check('v2 drops empty rules + bad op → equals', count($norm2['rules']) === 1 && $norm2['rules'][0]['op'] === 'equals');
survey_check('all-empty rules → null', rcmi_tickets_normalize_field_logic(['rules' => [['field_key' => '', 'op' => 'equals', 'value' => '']]]) === null);

$cfg = rcmi_tickets_validate_field_config(['logic' => ['action' => 'show', 'field_key' => 's', 'op' => 'equals', 'value' => 'v']], 'text');
survey_check('validate_field_config converts legacy logic', isset($cfg['logic']['rules']) && $cfg['logic']['match'] === 'all');

$rating_cfg = rcmi_tickets_validate_field_config(['max' => 7, 'low_label' => 'Bad', 'high_label' => 'Great', 'bogus' => 1], 'rating');
survey_check('rating config keeps max + labels', $rating_cfg['max'] === 7 && $rating_cfg['low_label'] === 'Bad' && $rating_cfg['high_label'] === 'Great' && !isset($rating_cfg['bogus']));

$num_cfg = rcmi_tickets_validate_field_config(['integer_only' => true], 'number');
survey_check('number integer_only kept', !empty($num_cfg['integer_only']));

survey_check('survey types = ticket types + rating/scale',
    rcmi_tickets_valid_survey_field_types() === array_merge(rcmi_tickets_valid_field_types(), ['rating', 'scale']));

// ── 3. Survey token ────────────────────────────────────────

echo "Token\n";

$tok = rcmi_tickets_survey_token(42);
survey_check('token deterministic', $tok === rcmi_tickets_survey_token(42));
survey_check('token valid', hash_equals($tok, rcmi_tickets_survey_token(42)));
survey_check('token per ticket', $tok !== rcmi_tickets_survey_token(43));
survey_check('token invalid rejected', !hash_equals($tok, strrev($tok)));

// ── 4. reminder_due ────────────────────────────────────────

echo "Reminder scheduling\n";

$settings = rcmi_tickets_survey_default_settings(); // first 7d, repeat 7d, max 1, close 30d
$now = time();
// Stored datetimes are site-local (current_time('mysql')) — build inputs
// with wp_date so they parse back under wp_timezone() to the right instant.
$completed = wp_date('Y-m-d H:i:s', $now - 8 * DAY_IN_SECONDS); // completed 8 days ago

survey_check('before day 7 → false', rcmi_tickets_survey_reminder_due(
    wp_date('Y-m-d H:i:s', $now - 6 * DAY_IN_SECONDS), 0, null, $settings, $now) === false);
survey_check('on/after day 7 → true', rcmi_tickets_survey_reminder_due($completed, 0, null, $settings, $now) === true);
survey_check('max reached → false', rcmi_tickets_survey_reminder_due($completed, 1, wp_date('Y-m-d H:i:s', $now - DAY_IN_SECONDS), $settings, $now) === false);

$settings['max_reminders'] = 3;
survey_check('repeat: < interval since last → false', rcmi_tickets_survey_reminder_due(
    $completed, 1, wp_date('Y-m-d H:i:s', $now - 6 * DAY_IN_SECONDS), $settings, $now) === false);
survey_check('repeat: >= interval since last → true', rcmi_tickets_survey_reminder_due(
    $completed, 1, wp_date('Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS), $settings, $now) === true);
survey_check('past close window → false', rcmi_tickets_survey_reminder_due(
    wp_date('Y-m-d H:i:s', $now - 31 * DAY_IN_SECONDS), 0, null, $settings, $now) === false);
survey_check('reminders disabled → false', rcmi_tickets_survey_reminder_due(
    $completed, 0, null, array_merge($settings, ['reminders_enabled' => false]), $now) === false);
survey_check('max_reminders 0 → false', rcmi_tickets_survey_reminder_due(
    $completed, 0, null, array_merge($settings, ['max_reminders' => 0]), $now) === false);

// Timezone: stored datetimes are site-local (current_time('mysql')), so
// parsing must use wp_timezone(), not UTC. Under the old UTC parsing a
// UTC-6 site would fire reminders ~6 hours early.
$saved_tz = get_option('timezone_string');
update_option('timezone_string', 'America/Chicago');
try {
    $tz_settings = rcmi_tickets_survey_default_settings();
    $local_minus_1h = wp_date('Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS + 3600); // wall clock: 1h before due
    $local_plus_1h  = wp_date('Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS - 3600); // wall clock: 1h past due
    survey_check('tz: 1h before first-reminder day → false',
        rcmi_tickets_survey_reminder_due($local_minus_1h, 0, null, $tz_settings, $now) === false);
    survey_check('tz: 1h past first-reminder day → true',
        rcmi_tickets_survey_reminder_due($local_plus_1h, 0, null, $tz_settings, $now) === true);
    survey_check('local_ts parses site-local datetime',
        rcmi_tickets_survey_local_ts($local_plus_1h) === $now - 7 * DAY_IN_SECONDS - 3600);
    survey_check('local_ts passes through numeric ts',
        rcmi_tickets_survey_local_ts($now) === $now);
    survey_check('local_ts empty → 0', rcmi_tickets_survey_local_ts('') === 0 && rcmi_tickets_survey_local_ts(null) === 0);
} finally {
    update_option('timezone_string', $saved_tz);
}

// ── 5. Eligibility + chain opt-out (real rows) ─────────────

echo "Eligibility\n";

global $wpdb;
$saved_settings = get_option('rcmi_tickets_survey_settings', null);
update_option('rcmi_tickets_survey_settings', array_merge(rcmi_tickets_survey_default_settings(), ['enabled' => true]));

$created = ['ticket_id' => 0, 'chain_id' => 0, 'approval_id' => 0, 'log_id' => 0, 'field_id' => 0];
try {
    $now_mysql = current_time('mysql');
    $admin = get_users(['role' => 'administrator', 'number' => 1]);
    $author_id = $admin ? (int) $admin[0]->ID : 1;

    // A survey must have at least one non-section question.
    $wpdb->insert($wpdb->prefix . 'rcmi_survey_fields', [
        'field_key' => 'test_survey_q', 'label' => 'Test question', 'type' => 'rating',
        'required' => 0, 'sort_order' => 9999, 'config' => wp_json_encode(['max' => 5]),
        'created_at' => $now_mysql, 'updated_at' => $now_mysql,
    ]);
    $created['field_id'] = (int) $wpdb->insert_id;

    $wpdb->insert($wpdb->prefix . 'rcmi_tickets', [
        'author_id' => $author_id, 'title' => 'Survey test ticket',
        'status' => 'Completed', 'created_at' => $now_mysql, 'updated_at' => $now_mysql,
    ]);
    $created['ticket_id'] = (int) $wpdb->insert_id;

    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_status_log', [
        'ticket_id' => $created['ticket_id'], 'old_status' => 'In Progress',
        'new_status' => 'Completed', 'changed_by' => 0, 'changed_at' => $now_mysql,
    ]);
    $created['log_id'] = (int) $wpdb->insert_id;

    survey_check('no chain → eligible', rcmi_tickets_survey_eligibility($created['ticket_id'])['eligible'] === true);

    // Chain with survey_enabled = 0 → opt-out.
    $wpdb->insert($wpdb->prefix . 'rcmi_approval_chains', [
        'name' => 'Survey test chain', 'is_active' => 1, 'survey_enabled' => 0,
        'created_at' => $now_mysql, 'updated_at' => $now_mysql,
    ]);
    $created['chain_id'] = (int) $wpdb->insert_id;

    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_approvals', [
        'ticket_id' => $created['ticket_id'], 'chain_id' => $created['chain_id'],
        'step_id' => 0, 'sort_order' => 1, 'status' => 'approved',
    ]);
    $created['approval_id'] = (int) $wpdb->insert_id;

    $elig = rcmi_tickets_survey_eligibility($created['ticket_id']);
    survey_check('chain survey_enabled=0 → not eligible', $elig['eligible'] === false);
    survey_check('reason is chain_disabled', in_array('chain_disabled', $elig['reasons'], true));

    // Flip to enabled → eligible again.
    $wpdb->update($wpdb->prefix . 'rcmi_approval_chains', ['survey_enabled' => 1], ['id' => $created['chain_id']]);
    survey_check('chain survey_enabled=1 → eligible', rcmi_tickets_survey_eligibility($created['ticket_id'])['eligible'] === true);
} finally {
    // Clean up every row this test created.
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_approvals', ['id' => $created['approval_id']], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_approval_chains', ['id' => $created['chain_id']], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_status_log', ['id' => $created['log_id']], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_tickets', ['id' => $created['ticket_id']], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_survey_fields', ['id' => $created['field_id']], ['%d']);
    if ($saved_settings === null) {
        delete_option('rcmi_tickets_survey_settings');
    } else {
        update_option('rcmi_tickets_survey_settings', $saved_settings);
    }
}

// ── 6. Deleting a survey field keeps stored answers ────────

echo "Field delete keeps answers\n";

$del = ['ticket_id' => 0, 'field_id' => 0, 'response_id' => 0];
try {
    $now_mysql = current_time('mysql');
    $wpdb->insert($wpdb->prefix . 'rcmi_survey_fields', [
        'field_key' => 'test_survey_del', 'label' => 'Deleted question', 'type' => 'rating',
        'required' => 0, 'sort_order' => 9998, 'config' => wp_json_encode(['max' => 5]),
        'created_at' => $now_mysql, 'updated_at' => $now_mysql,
    ]);
    $del['field_id'] = (int) $wpdb->insert_id;

    $wpdb->insert($wpdb->prefix . 'rcmi_tickets', [
        'author_id' => $author_id, 'title' => 'Survey delete test ticket',
        'status' => 'Completed', 'created_at' => $now_mysql, 'updated_at' => $now_mysql,
    ]);
    $del['ticket_id'] = (int) $wpdb->insert_id;

    $wpdb->insert($wpdb->prefix . 'rcmi_survey_responses', [
        'ticket_id' => $del['ticket_id'], 'respondent_email' => 'test@example.com',
        'submitted_at' => $now_mysql,
    ]);
    $del['response_id'] = (int) $wpdb->insert_id;

    $wpdb->insert($wpdb->prefix . 'rcmi_survey_answers', [
        'response_id' => $del['response_id'], 'field_id' => $del['field_id'],
        'field_key' => 'test_survey_del', 'label' => 'Deleted question',
        'type' => 'rating', 'value' => '5',
    ]);

    $req = new WP_REST_Request('DELETE', '/rcmi/v1/survey-fields/' . $del['field_id']);
    $req->set_param('id', $del['field_id']);
    $res = rcmi_tickets_handle_survey_field_delete($req);

    survey_check('delete handler returns 200', $res->get_status() === 200);
    survey_check('field row removed',
        !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rcmi_survey_fields WHERE id = %d", $del['field_id'])));
    survey_check('answer row kept after field delete',
        (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}rcmi_survey_answers WHERE field_id = %d", $del['field_id'])) === 1);

    $response = rcmi_tickets_get_survey_response($del['ticket_id']);
    survey_check('response still returns snapshot answers',
        $response && count($response['answers']) === 1
        && $response['answers'][0]['label'] === 'Deleted question'
        && $response['answers'][0]['type'] === 'rating'
        && $response['answers'][0]['value'] === '5');
} finally {
    $wpdb->delete($wpdb->prefix . 'rcmi_survey_answers', ['response_id' => $del['response_id']], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_survey_responses', ['id' => $del['response_id']], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_tickets', ['id' => $del['ticket_id']], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_survey_fields', ['id' => $del['field_id']], ['%d']);
}

// ── 7. Ticket context: non-array config guard ─────────────

echo "Ticket context\n";

// format_survey_field() emits 'config' => new stdClass() when the DB
// column is NULL — the context builder must not index it as an array.
$ctx = null;
$ctx_threw = false;
try {
    $ctx = rcmi_tickets_survey_ticket_context(0, [
        ['config' => new stdClass()],
        ['config' => ['logic' => 'garbage']],
        ['config' => null],
    ]);
} catch (Throwable $e) {
    $ctx_threw = true;
}
survey_check('non-array config → no throw', !$ctx_threw);
survey_check('non-array config → empty context',
    $ctx instanceof stdClass && (array) $ctx === []);

// ── 8. Global completion message ──────────────────────────

echo "Global completion message\n";

$cm = ['ticket_id' => 0, 'chain_id' => 0, 'approval_id' => 0];
// Captured before try so the finally always has it.
$saved_cm = get_option('rcmi_tickets_completion_message', null);
try {
    $now_mysql = current_time('mysql');
    $wpdb->insert($wpdb->prefix . 'rcmi_tickets', [
        'author_id' => $author_id, 'title' => 'Completion message test ticket',
        'status' => 'Completed', 'created_at' => $now_mysql, 'updated_at' => $now_mysql,
    ]);
    $cm['ticket_id'] = (int) $wpdb->insert_id;

    // One global completion message — same for every ticket regardless of
    // chain.
    update_option('rcmi_tickets_completion_message', '<p>Global test msg</p>');
    survey_check('no chain → global message',
        rcmi_tickets_get_chain_completion_message($cm['ticket_id']) === '<p>Global test msg</p>');
    survey_check('default mentions the survey',
        strpos(rcmi_tickets_default_completion_message(), 'survey') !== false);

    // Chain with empty completion_message → still the global.
    $wpdb->insert($wpdb->prefix . 'rcmi_approval_chains', [
        'name' => 'Completion msg test chain', 'is_active' => 1,
        'created_at' => $now_mysql, 'updated_at' => $now_mysql,
    ]);
    $cm['chain_id'] = (int) $wpdb->insert_id;
    $wpdb->insert($wpdb->prefix . 'rcmi_ticket_approvals', [
        'ticket_id' => $cm['ticket_id'], 'chain_id' => $cm['chain_id'],
        'step_id' => 0, 'sort_order' => 1, 'status' => 'approved',
    ]);
    $cm['approval_id'] = (int) $wpdb->insert_id;

    survey_check('empty chain message → still global',
        rcmi_tickets_get_chain_completion_message($cm['ticket_id']) === '<p>Global test msg</p>');

    // A legacy per-chain message no longer overrides the global.
    $wpdb->update($wpdb->prefix . 'rcmi_approval_chains',
        ['completion_message' => '<p>Custom done note</p>'], ['id' => $cm['chain_id']]);
    survey_check('per-chain message ignored (global wins)',
        rcmi_tickets_get_chain_completion_message($cm['ticket_id']) === '<p>Global test msg</p>');

    // Option absent → lazy fallback to chain #1's stored text (sanitized),
    // or the default when chain #1 is blank/missing. Read-only: chain #1
    // is never modified by the getter.
    delete_option('rcmi_tickets_completion_message');
    $chain1_msg = $wpdb->get_var(
        "SELECT completion_message FROM {$wpdb->prefix}rcmi_approval_chains WHERE id = 1"
    );
    $expected = rcmi_tickets_sanitize_completion_message((string) $chain1_msg);
    $expected = $expected !== '' ? $expected : rcmi_tickets_default_completion_message();
    survey_check('unset option → sanitized chain-1 fallback',
        rcmi_tickets_get_completion_message() === $expected);
    // An explicitly-empty stored option also resolves to the default.
    update_option('rcmi_tickets_completion_message', '');
    survey_check('empty global option → default',
        rcmi_tickets_get_completion_message() === rcmi_tickets_default_completion_message());
} finally {
    if ($saved_cm === null) {
        delete_option('rcmi_tickets_completion_message');
    } else {
        update_option('rcmi_tickets_completion_message', $saved_cm);
    }
    $wpdb->delete($wpdb->prefix . 'rcmi_ticket_approvals', ['id' => $cm['approval_id']], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_approval_chains', ['id' => $cm['chain_id']], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_tickets', ['id' => $cm['ticket_id']], ['%d']);
}

// ── 9. Response list filters + field-values ────────────────

echo "Response list filters\n";

$flt = ['ticket_id' => 0, 'response_id' => 0, 'field_id' => 0];
$dept_saved = get_user_meta($author_id, 'rcmi_department', true);
try {
    $now_mysql = current_time('mysql');

    $wpdb->insert($wpdb->prefix . 'rcmi_survey_fields', [
        'field_key' => 'test_filter_q', 'label' => 'Filter question', 'type' => 'checkbox',
        'required' => 0, 'sort_order' => 9997, 'config' => wp_json_encode(['options' => ['PickA', 'PickB']]),
        'created_at' => $now_mysql, 'updated_at' => $now_mysql,
    ]);
    $flt['field_id'] = (int) $wpdb->insert_id;

    $wpdb->insert($wpdb->prefix . 'rcmi_tickets', [
        'author_id' => $author_id, 'title' => 'ZZZ survey filter ticket',
        'status' => 'Completed', 'created_at' => $now_mysql, 'updated_at' => $now_mysql,
    ]);
    $flt['ticket_id'] = (int) $wpdb->insert_id;

    $wpdb->insert($wpdb->prefix . 'rcmi_survey_responses', [
        'ticket_id' => $flt['ticket_id'], 'respondent_email' => 'filterfix@example.com',
        'submitted_at' => $now_mysql,
    ]);
    $flt['response_id'] = (int) $wpdb->insert_id;

    // Checkbox-style JSON answer — the LIKE '%"value"%' branch must match.
    $wpdb->insert($wpdb->prefix . 'rcmi_survey_answers', [
        'response_id' => $flt['response_id'], 'field_id' => $flt['field_id'],
        'field_key' => 'test_filter_q', 'label' => 'Filter question',
        'type' => 'checkbox', 'value' => wp_json_encode(['PickA', 'PickB']),
    ]);

    $list = function (array $params) {
        $req = new WP_REST_Request('GET', '/rcmi/v1/survey-responses');
        $req->set_param('page', 1);
        $req->set_param('per_page', 50);
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        return rcmi_tickets_handle_survey_responses($req)->get_data();
    };
    $has_fixture = function ($data) use ($flt) {
        foreach ($data['items'] as $it) {
            if ((int) $it['id'] === $flt['response_id']) {
                return true;
            }
        }
        return false;
    };

    // Search: title fragment, numeric ticket id, respondent email.
    $d = $list(['search' => 'ZZZ survey filter']);
    survey_check('search: title fragment finds fixture', $has_fixture($d) && $d['total'] === 1);
    $d = $list(['search' => 'no-such-ticket-xyz']);
    survey_check('search: miss excludes fixture', !$has_fixture($d));
    $d = $list(['search' => (string) $flt['ticket_id']]);
    survey_check('search: numeric ticket id finds fixture', $has_fixture($d));
    $d = $list(['search' => 'filterfix@example.com']);
    survey_check('search: respondent email finds fixture', $has_fixture($d));

    // Requestor + department (rcmi_department usermeta on the author).
    $d = $list(['author_id' => $author_id]);
    survey_check('author_id includes fixture', $has_fixture($d));
    $d = $list(['author_id' => $author_id + 10000]);
    survey_check('author_id mismatch excludes fixture', !$has_fixture($d));

    update_user_meta($author_id, 'rcmi_department', 'ZZZ Test Dept');
    $d = $list(['department' => 'ZZZ Test Dept']);
    survey_check('department match includes fixture', $has_fixture($d));
    $d = $list(['department' => 'No Such Dept']);
    survey_check('department mismatch excludes fixture', !$has_fixture($d));

    // Submitted date range.
    $d = $list(['date_from' => wp_date('Y-m-d', time() + DAY_IN_SECONDS)]);
    survey_check('date_from tomorrow excludes fixture', !$has_fixture($d));
    $d = $list(['date_to' => wp_date('Y-m-d', time() - DAY_IN_SECONDS)]);
    survey_check('date_to yesterday excludes fixture', !$has_fixture($d));
    $d = $list(['date_from' => '2000-01-01', 'date_to' => '2099-01-01']);
    survey_check('wide date range includes fixture', $has_fixture($d));

    // field_filters: JSON-array element match, exact miss, unknown key.
    $d = $list(['field_filters' => ['test_filter_q' => 'PickA']]);
    survey_check('field_filters JSON element matches', $has_fixture($d));
    $d = $list(['field_filters' => ['test_filter_q' => 'PickZ']]);
    survey_check('field_filters miss excludes', !$has_fixture($d));
    $d = $list(['field_filters' => ['bogus_key' => 'PickA']]);
    survey_check('field_filters unknown key ignored', $has_fixture($d));

    // /survey-field-values: JSON elements counted individually; 404 on
    // a key with neither a live field nor stored answers.
    $req = new WP_REST_Request('GET', '/rcmi/v1/survey-field-values');
    $req->set_param('field_key', 'test_filter_q');
    $fv = rcmi_tickets_handle_survey_field_values($req);
    $fv_map = [];
    foreach ($fv->get_data()['values'] as $v) {
        $fv_map[$v['value']] = $v['count'];
    }
    survey_check('field-values explodes JSON elements',
        ($fv_map['PickA'] ?? 0) === 1 && ($fv_map['PickB'] ?? 0) === 1);

    $req = new WP_REST_Request('GET', '/rcmi/v1/survey-field-values');
    $req->set_param('field_key', 'no_such_key');
    survey_check('field-values unknown key 404s',
        is_wp_error(rcmi_tickets_handle_survey_field_values($req)));
} finally {
    $wpdb->delete($wpdb->prefix . 'rcmi_survey_answers', ['response_id' => $flt['response_id']], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_survey_responses', ['id' => $flt['response_id']], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_tickets', ['id' => $flt['ticket_id']], ['%d']);
    $wpdb->delete($wpdb->prefix . 'rcmi_survey_fields', ['id' => $flt['field_id']], ['%d']);
    update_user_meta($author_id, 'rcmi_department', $dept_saved);
}

// ── 10. Settings sanitization ──────────────────────────────

echo "Settings sanitization\n";

// intro/thank-you come from a rich text editor — the PUT handler must
// keep the safe subset (p/strong/em/a/lists) and strip script tags.
$saved_survey_settings = get_option('rcmi_tickets_survey_settings', null);
$saved_uid = get_current_user_id();
$settings_admin = get_users(['role' => 'administrator', 'number' => 1]);
if ($settings_admin) {
    wp_set_current_user((int) $settings_admin[0]->ID);
}
try {
    $req = new WP_REST_Request('PUT', '/rcmi/v1/survey-settings');
    $req->set_header('Content-Type', 'application/json');
    $req->set_body(wp_json_encode([
        'intro_text'     => '<p><strong>Hi</strong> <a href="https://x.test">x</a></p><script>alert(1)</script>',
        'thank_you_text' => 'Thanks<script>alert(2)</script>',
    ]));
    $res = rcmi_tickets_handle_survey_settings_update($req);
    $body = $res->get_data();
    survey_check('settings PUT keeps allowed html in intro_text',
        strpos($body['intro_text'], '<p><strong>Hi</strong>') !== false
        && strpos($body['intro_text'], '<a href="https://x.test">x</a>') !== false);
    survey_check('settings PUT strips <script> from intro_text',
        strpos($body['intro_text'], '<script') === false);
    survey_check('settings PUT strips <script> from thank_you_text',
        strpos($body['thank_you_text'], '<script') === false
        && strpos($body['thank_you_text'], 'Thanks') !== false);

    // Survey title — editable, sanitized, defaulted.
    survey_check('title present in GET settings',
        array_key_exists('title', $body) && $body['title'] !== '');
    survey_check('missing/blank stored title → default',
        rcmi_tickets_survey_default_settings()['title'] === 'How did we do?');

    $req = new WP_REST_Request('PUT', '/rcmi/v1/survey-settings');
    $req->set_header('Content-Type', 'application/json');
    $req->set_body(wp_json_encode(['title' => 'Tell us how it went <b>now</b>']));
    $body = rcmi_tickets_handle_survey_settings_update($req)->get_data();
    survey_check('title roundtrip via PUT', $body['title'] === 'Tell us how it went now');
    survey_check('title HTML stripped by sanitize_text_field',
        strpos($body['title'], '<') === false);
    survey_check('getter returns custom title',
        rcmi_tickets_survey_settings()['title'] === 'Tell us how it went now');

    // Whitespace-only title normalizes back to the default.
    $req = new WP_REST_Request('PUT', '/rcmi/v1/survey-settings');
    $req->set_header('Content-Type', 'application/json');
    $req->set_body(wp_json_encode(['title' => '   ']));
    rcmi_tickets_handle_survey_settings_update($req);
    survey_check('empty title → default', rcmi_tickets_survey_settings()['title'] === 'How did we do?');

    // Partial update without a title key preserves the stored title.
    $req = new WP_REST_Request('PUT', '/rcmi/v1/survey-settings');
    $req->set_header('Content-Type', 'application/json');
    $req->set_body(wp_json_encode(['title' => 'Partial-keep title']));
    rcmi_tickets_handle_survey_settings_update($req);
    $req = new WP_REST_Request('PUT', '/rcmi/v1/survey-settings');
    $req->set_header('Content-Type', 'application/json');
    $req->set_body(wp_json_encode(['max_reminders' => 2]));
    $body = rcmi_tickets_handle_survey_settings_update($req)->get_data();
    survey_check('partial PUT preserves title', $body['title'] === 'Partial-keep title');

    // The tokenized public GET exposes the title too.
    $wpdb->insert($wpdb->prefix . 'rcmi_tickets', [
        'author_id' => $author_id, 'title' => 'Survey title test ticket',
        'status' => 'Completed', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
    ]);
    $title_tid = (int) $wpdb->insert_id;
    $req = new WP_REST_Request('GET', '/rcmi/v1/survey/' . $title_tid);
    $req->set_param('id', $title_tid); // direct handler call — route param not parsed from the path
    $req->set_param('token', rcmi_tickets_survey_token($title_tid));
    $body = rcmi_tickets_handle_survey_get($req)->get_data();
    survey_check('public GET exposes title', ($body['title'] ?? '') === 'Partial-keep title');
    $wpdb->delete($wpdb->prefix . 'rcmi_tickets', ['id' => $title_tid], ['%d']);
} finally {
    if ($saved_survey_settings === null) {
        delete_option('rcmi_tickets_survey_settings');
    } else {
        update_option('rcmi_tickets_survey_settings', $saved_survey_settings);
    }
    wp_set_current_user($saved_uid);
}

// ── Summary ────────────────────────────────────────────────

$errors = $GLOBALS['survey_errors'] ?? [];
if ($errors) {
    fwrite(STDERR, "\n" . count($errors) . " check(s) failed:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
printf("\nSurvey check passed: %d assertions.%s", $GLOBALS['survey_pass'] ?? 0, "\n");
