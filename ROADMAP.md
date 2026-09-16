# RCMI Tickets Roadmap

This roadmap supersedes the completed root-level v1 implementation plan as the source of pending work. The original plan remains the historical implementation record.

## Current milestone: Stabilization

- [x] Centralize the persistence manifest for plugin-owned tables, options, transients, roles, capabilities, and uploads.
- [x] Make the opt-in uninstall clean all plugin-owned persistence and ticket uploads while preserving WordPress users and pages.
- [x] Add a non-destructive regression check for persistence-manifest parity.
- [x] Install fail-closed Apache/IIS guards and local-development routing protection for ticket attachments.
- [x] Route valid public view tokens through the protected attachment download endpoint.
- [ ] Add committed REST regression coverage for permissions, status transitions, approvals, public tokens, comments, attachments, and migrations.
- [ ] Verify that ticket attachments cannot be fetched directly on IIS; otherwise move them outside the public web root or add an enforced server deny rule.
- [ ] Define and verify token hashing, expiration, rotation, and revocation for public ticket and approval links.
- [ ] Run end-to-end acceptance tests for managers, requestors, approvers, assignees, public submitters, and SSO login/logout.
- [ ] Document the current REST API, 14-table schema, status workflow, deployment procedure, migration procedure, and rollback procedure.

## Release gates

- `php tests/check-persistence-manifest.php`
- Raw ticket-upload URLs return 404, including duplicate-slash, encoded, and dot-segment path variants.
- PHP syntax checks pass for every changed PHP file.
- `npm run build` succeeds from `app/` when frontend source changes.
- Permission-matrix and REST regression tests pass.
- Public-token and SSO flows pass end to end.
- Keyboard, mobile, and accessibility checks pass for affected UI.
