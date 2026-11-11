<?php
/**
 * Run from WordPress root:
 * php wp-cli.phar eval-file wp-content/plugins/rcmi-tickets/tests/check-survey-export.php
 *
 * Uses transactional fixtures only; no settings changes, emails, or retained rows.
 */
if (!function_exists('rcmi_tickets_build_survey_export')) {
    throw new RuntimeException('Run this check through WP-CLI with the plugin active.');
}
function se_check($label, $condition) {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $GLOBALS['se_pass'] = ($GLOBALS['se_pass'] ?? 0) + 1;
    echo "ok: $label\n";
}
function se_parse($export) {
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, substr($export['csv'], 3));
    rewind($stream);
    $headers = fgetcsv($stream, 0, ',', '"', '');
    $rows = [];
    while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        se_check('CSV row aligns with headers', count($values) === count($headers));
        $rows[] = array_combine($headers, $values);
    }
    fclose($stream);
    return [$headers, $rows];
}
global $wpdb;
$manager = get_user_by('login', 'ticketmgr1');
if (!$manager) {
    throw new RuntimeException('The ticketmgr1 test user is required.');
}
$old_user = get_current_user_id();
$marker = 'csvcheck_' . wp_generate_password(10, false, false);
$insert = function ($table, $values) use ($wpdb) {
    if (!$wpdb->insert($wpdb->prefix . $table, $values)) {
        throw new RuntimeException('Fixture insert failed: ' . $wpdb->last_error);
    }
    return (int) $wpdb->insert_id;
};
foreach (['rcmi_tickets', 'rcmi_form_fields', 'rcmi_form_answers', 'rcmi_survey_fields',
    'rcmi_survey_responses', 'rcmi_survey_answers', 'rcmi_ticket_assignees',
    'rcmi_ticket_tags', 'rcmi_ticket_tag_map'] as $table) {
    $engine = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $wpdb->prefix . $table), ARRAY_A);
    if (($engine['Engine'] ?? '') !== 'InnoDB') {
        throw new RuntimeException('Transactional fixtures require InnoDB: ' . $table);
    }
}
$wpdb->query('START TRANSACTION');
try {
    wp_set_current_user($manager->ID);
    $now = current_time('mysql');
    $field = function ($table, $key, $label, $type) use ($insert, $now) {
        return $insert($table, [
            'field_key' => $key, 'label' => $label, 'type' => $type,
            'required' => 0, 'sort_order' => 9999, 'config' => '{}',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $ticket_field = $field('rcmi_form_fields', $marker . '_ticket', $marker . ' Choice', 'checkbox');
    $cascade_field = $field('rcmi_form_fields', $marker . '_cascade', $marker . ' Path', 'cascade');
    $section_field = $field('rcmi_form_fields', $marker . '_section', $marker . ' Section', 'section');
    $due_field = $field('rcmi_form_fields', $marker . '_due', 'Due Date', 'date');
    $survey_field = $field('rcmi_survey_fields', $marker . '_survey', $marker . ' Choice', 'matrix');
    $duplicate_field = $field('rcmi_survey_fields', $marker . '_dup', $marker . ' Choice', 'text');
    $ticket_ids = [];
    $response_ids = [];
    foreach (['A', 'B', 'Orphan'] as $index => $suffix) {
        $ticket_ids[] = $ticket_id = $insert('rcmi_tickets', [
            'title' => $marker . ' ' . $suffix, 'author_id' => $manager->ID,
            'status' => 'Completed', 'due_date' => '2026-12-01',
            'description' => '<p>Description, with "quotes"</p>',
            'description_text' => "Description, with \"quotes\"\nand newline",
            'archived' => $index === 1 ? 1 : 0, 'updated_by' => $manager->ID,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $response_ids[] = $response_id = $insert('rcmi_survey_responses', [
            'ticket_id' => $ticket_id, 'respondent_user_id' => $manager->ID,
            'respondent_email' => $marker . '@example.test',
            'submitted_at' => '2026-11-' . (10 + $index) . ' 12:00:00',
        ]);
        $insert('rcmi_form_answers', ['ticket_id' => $ticket_id, 'field_id' => $ticket_field,
            'value' => wp_json_encode(['ticket ' . $suffix, 'second'])]);
        $insert('rcmi_form_answers', ['ticket_id' => $ticket_id, 'field_id' => $cascade_field,
            'value' => wp_json_encode(['Parent', 'Child'])]);
        $insert('rcmi_form_answers', ['ticket_id' => $ticket_id, 'field_id' => $due_field,
            'value' => '2026-12-01']);
        $insert('rcmi_survey_answers', [
            'response_id' => $response_id, 'field_id' => $survey_field,
            'field_key' => $marker . '_survey', 'label' => $marker . ' Choice',
            'type' => 'matrix', 'value' => wp_json_encode(['Service' => 'Great ' . $suffix]),
        ]);
        $insert('rcmi_survey_answers', [
            'response_id' => $response_id, 'field_id' => $duplicate_field,
            'field_key' => $marker . '_dup', 'label' => $marker . ' Choice',
            'type' => 'text', 'value' => $index === 0 ? '=1+1' : 'safe',
        ]);
        $insert('rcmi_ticket_assignees', ['ticket_id' => $ticket_id, 'user_id' => $manager->ID]);
    }
    $tag = $insert('rcmi_ticket_tags', ['name' => $marker . ' tag', 'slug' => $marker]);
    $insert('rcmi_ticket_tag_map', ['ticket_id' => $ticket_ids[0], 'tag_id' => $tag]);
    // Only a fixture ticket is deleted: its response must still export with blank ticket columns.
    $wpdb->delete($wpdb->prefix . 'rcmi_tickets', ['id' => $ticket_ids[2]], ['%d']);
    $params = ['search' => $marker, 'sort' => 'ticket_id', 'order' => 'asc', 'per_page' => 1];
    $survey = rcmi_tickets_build_survey_export($params);
    [$survey_headers, $survey_rows] = se_parse($survey);
    $combined = rcmi_tickets_build_survey_export(array_merge($params, ['scope' => 'survey_ticket']));
    [$headers, $rows] = se_parse($combined);
    se_check('both exports include every filtered response, not just the page', count($rows) === 3 && count($survey_rows) === 3);
    se_check('both exports keep the same response order', array_column($rows, 'Response ID') === array_column($survey_rows, 'Response ID'));
    se_check('default scope is survey only', !in_array('Ticket Status', $survey_headers, true));
    se_check('shared identity columns remain first', array_slice($headers, 0, 8) === array_slice($survey_headers, 0, 8));
    se_check('ticket details and answers precede survey questions',
        array_search('Ticket Status', $headers, true) === 8
        && array_search('Ticket: ' . $marker . ' Path', $headers, true) < array_search($marker . ' Choice', $headers, true));
    se_check('survey questions remain in order after ticket columns',
        array_slice($headers, count($headers) - count($survey_headers) + 8) === array_slice($survey_headers, 8));
    se_check('no duplicate column headers', count($headers) === count(array_unique(array_map('strtolower', $headers))));
    se_check('shared ticket ID appears once', count(array_filter($headers, function ($h) { return $h === 'Ticket ID'; })) === 1);
    se_check('no duplicate requestor identity columns', !in_array('Requestor Email', $headers, true) && !in_array('Requestor Department', $headers, true));
    se_check('ticket form answer joins to the correct response', $rows[0]['Ticket: ' . $marker . ' Choice'] === 'ticket A; second' && $rows[1]['Ticket: ' . $marker . ' Choice'] === 'ticket B; second');
    se_check('cascade formatting preserved', $rows[0]['Ticket: ' . $marker . ' Path'] === 'Parent › Child');
    se_check('matrix answer formatting preserved', $rows[0][$marker . ' Choice'] === 'Service: Great A');
    se_check('same-label independent questions retain separate answers', $rows[0][$marker . ' Choice (2)'] === "'=1+1");
    se_check('spreadsheet formulas neutralized', strpos($combined['csv'], "'=1+1") !== false);
    se_check('description quotes and newlines round-trip', $rows[0]['Description'] === "Description, with \"quotes\"\nand newline");
    se_check('metadata and tags included', $rows[0]['Tags'] === $marker . ' tag' && $rows[1]['Archived'] === 'Yes' && $rows[0]['Assignee'] === $manager->display_name);
    se_check('sections and reserved due-date answer not duplicated', !in_array('Ticket: ' . $marker . ' Section', $headers, true) && !in_array('Ticket: Due Date', $headers, true));
    se_check('orphaned response retained with blank ticket columns', $rows[2]['Ticket ID'] === (string) $ticket_ids[2] && $rows[2]['Ticket Status'] === '' && $rows[2]['Ticket: ' . $marker . ' Choice'] === '');
    se_check('no security tokens or private paths exported', !preg_match('/token_hash|file_path|revision_token|view_token/i', implode(',', $headers)));
    se_check('BOM and scope-specific filenames', substr($combined['csv'], 0, 3) === "\xEF\xBB\xBF" && strpos($combined['filename'], 'survey-and-tickets-') === 0 && strpos($survey['filename'], 'survey-responses-') === 0);
    foreach (['survey', 'survey_ticket'] as $scope) {
        $filtered = rcmi_tickets_build_survey_export(array_merge($params, [
            'scope' => $scope, 'date_from' => '2026-11-11', 'date_to' => '2026-11-11',
            'author_id' => $manager->ID, 'field_filters' => [$marker . '_dup' => 'safe'],
        ]));
        [, $filtered_rows] = se_parse($filtered);
        se_check($scope . ' respects combined date, author and answer filters', count($filtered_rows) === 1 && $filtered_rows[0]['Ticket ID'] === (string) $ticket_ids[1]);
    }
    [, $empty] = se_parse(rcmi_tickets_build_survey_export(['scope' => 'survey_ticket', 'search' => $marker . '_missing']));
    se_check('empty filter returns headers only', $empty === []);
    $request = new WP_REST_Request('GET', '/rcmi/v1/survey-responses/export');
    $request->set_param('scope', 'invalid');
    se_check('invalid scope rejected', rest_do_request($request)->get_status() === 400);
    foreach ([0, (get_user_by('login', 'ticketuser1')->ID ?? 0)] as $user_id) {
        wp_set_current_user($user_id);
        foreach (['survey', 'survey_ticket'] as $scope) {
            $request = new WP_REST_Request('GET', '/rcmi/v1/survey-responses/export');
            $request->set_param('scope', $scope);
            se_check('export scope denied to non-manager', in_array(rest_do_request($request)->get_status(), [401, 403], true));
        }
    }
    printf("Survey-export check passed: %d assertions.\n", $GLOBALS['se_pass'] ?? 0);
} finally {
    $wpdb->query('ROLLBACK');
    wp_set_current_user($old_user);
}
