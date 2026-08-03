<?php
/**
 * Deactivation handler for RCMI Tickets.
 *
 * Per ticket-plan.md Task 3: deactivation does NOT drop tables (that's
 * uninstall.php's job, behind a confirmation constant — Task 13). We only
 * clear the schema version option so a re-activation re-runs dbDelta
 * cleanly. Table data is preserved for the owner to review.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deactivation cleanup. Called from rcmi_tickets_deactivate() in the
 * bootstrap. Role removal is handled separately by
 * rcmi_tickets_remove_roles() (class-roles.php).
 */
function rcmi_tickets_deactivate_schema() {
    delete_option('rcmi_tickets_db_version');
}
