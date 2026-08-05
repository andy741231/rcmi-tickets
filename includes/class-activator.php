<?php
/**
 * Database schema for RCMI Tickets.
 *
 * Creates the 7 custom tables defined in ticket-plan.md §3 via dbDelta.
 * Versioned via the `rcmi_tickets_db_version` option so schema upgrades
 * re-run dbDelta when the version constant is bumped.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Current schema version. Bump this when the schema changes; dbDelta will
 * re-run on the next admin request to bring tables up to date.
 */
if (!defined('RCMI_TICKETS_DB_VERSION')) {
    define('RCMI_TICKETS_DB_VERSION', '2');
}

/**
 * Build the dbDelta statements for all 7 tables.
 *
 * dbDelta is picky: each statement on its own line, two spaces after
 * PRIMARY KEY, lowercase `key` in index clauses, no trailing commas in
 * column lists inside KEY().
 *
 * @return string[] Map of table key => CREATE TABLE statement.
 */
function rcmi_tickets_schema_statements() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $prefix = $wpdb->prefix;

    return [
        'tickets' => "CREATE TABLE {$prefix}rcmi_tickets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            author_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NULL,
            description_text LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Received',
            priority VARCHAR(10) NOT NULL DEFAULT 'Medium',
            due_date DATE NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY priority (priority),
            KEY author_id (author_id),
            KEY created_at (created_at)
        ) {$charset_collate};",

        'assignees' => "CREATE TABLE {$prefix}rcmi_ticket_assignees (
            ticket_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (ticket_id, user_id)
        ) {$charset_collate};",

        'comments' => "CREATE TABLE {$prefix}rcmi_ticket_comments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            body LONGTEXT NULL,
            parent_id BIGINT UNSIGNED NULL,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            mentions TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_created (ticket_id, created_at),
            KEY parent_id (parent_id)
        ) {$charset_collate};",

        'reactions' => "CREATE TABLE {$prefix}rcmi_ticket_comment_reactions (
            comment_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(10) NOT NULL,
            PRIMARY KEY  (comment_id, user_id)
        ) {$charset_collate};",

        'attachments' => "CREATE TABLE {$prefix}rcmi_ticket_attachments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NULL,
            comment_id BIGINT UNSIGNED NULL,
            uploader_id BIGINT UNSIGNED NULL,
            file_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL DEFAULT '',
            size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id),
            KEY comment_id (comment_id)
        ) {$charset_collate};",

        'tags' => "CREATE TABLE {$prefix}rcmi_ticket_tags (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY name (name),
            UNIQUE KEY slug (slug)
        ) {$charset_collate};",

        'tag_map' => "CREATE TABLE {$prefix}rcmi_ticket_tag_map (
            ticket_id BIGINT UNSIGNED NOT NULL,
            tag_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (ticket_id, tag_id)
        ) {$charset_collate};",

        'approval_chains' => "CREATE TABLE {$prefix}rcmi_approval_chains (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            trigger_field_key VARCHAR(100) NULL,
            trigger_value VARCHAR(255) NULL,
            on_reject VARCHAR(20) NOT NULL DEFAULT 'restart',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY is_active (is_active)
        ) {$charset_collate};",

        'approval_steps' => "CREATE TABLE {$prefix}rcmi_approval_steps (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chain_id BIGINT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 1,
            approver_type VARCHAR(10) NOT NULL DEFAULT 'user',
            approver_user_id BIGINT UNSIGNED NULL,
            approver_role VARCHAR(100) NULL,
            name VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY chain_id (chain_id)
        ) {$charset_collate};",

        'ticket_approvals' => "CREATE TABLE {$prefix}rcmi_ticket_approvals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            chain_id BIGINT UNSIGNED NOT NULL,
            step_id BIGINT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 1,
            cycle INT UNSIGNED NOT NULL DEFAULT 1,
            approver_user_id BIGINT UNSIGNED NULL,
            approver_role VARCHAR(100) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            decided_at DATETIME NULL,
            decided_by BIGINT UNSIGNED NULL,
            comment TEXT NULL,
            token VARCHAR(64) NULL,
            token_expires DATETIME NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id),
            KEY chain_id (chain_id),
            KEY status (status)
        ) {$charset_collate};",

        'form_fields' => "CREATE TABLE {$prefix}rcmi_form_fields (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            field_key VARCHAR(100) NOT NULL,
            label VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'text',
            required TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 1,
            config TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY field_key (field_key)
        ) {$charset_collate};",

        'form_answers' => "CREATE TABLE {$prefix}rcmi_form_answers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            field_id BIGINT UNSIGNED NOT NULL,
            value TEXT NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id),
            KEY field_id (field_id)
        ) {$charset_collate};",
    ];
}

/**
 * Create / upgrade all ticket tables. Safe to call repeatedly — dbDelta
 * only applies diffs. Called on activation and on version bump.
 */
function rcmi_tickets_create_tables() {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    foreach (rcmi_tickets_schema_statements() as $statement) {
        dbDelta($statement);
    }

    update_option('rcmi_tickets_db_version', RCMI_TICKETS_DB_VERSION);
}

/**
 * Run schema upgrade if the stored version is behind the code version.
 * Hooked on admin_init so upgrades apply without a manual re-activation.
 */
function rcmi_tickets_maybe_upgrade_schema() {
    $installed = get_option('rcmi_tickets_db_version', '0');
    if (version_compare((string) $installed, RCMI_TICKETS_DB_VERSION, '<')) {
        rcmi_tickets_create_tables();
    }
}
add_action('admin_init', 'rcmi_tickets_maybe_upgrade_schema');
