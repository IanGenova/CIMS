# CIMS audit — 20 October 2026

## Scope

Reviewed the current local CIMS checkout, its API routes and selected high-risk workflows: branch access, deliveries, returns, users, and group permissions. Ran the PHP and JavaScript test suites and opened Deliveries, Users Management, and Group Permissions in the local browser. This is a targeted application audit, not a penetration test or a live Hostinger/payment-gateway test.

## Bugs found and fixed

| Severity | Finding | Correction |
| --- | --- | --- |
| High | The delivery sale picker used an unscoped SQL query and showed completed sales from other branches. | Limit it to the signed-in user's warehouses. |
| High | The sales-return picker showed sales from other branches. Return validation also accepted an outside-branch sale or purchase ID, allowing a restricted user to link a return to a source they could not access. | Scope the picker and check the source document's warehouse when validating returns. |
| Medium | Editing a user without a new password failed validation; omitting an avatar could also attempt to process a nonexistent upload. | Treat both inputs as optional and preserve the current values when absent. |
| Medium | Two simultaneous delivery requests could both pass the duplicate check before either inserted a delivery. | Lock the sale row and check for an existing delivery inside the same transaction. |
| Medium | Deleting a group still assigned to users hid it from Group Permissions while its permissions remained attached to those users. | Reject single and batch deletion of assigned groups. |
| Low | The delivery resource advertised `create` and `edit` API endpoints whose controller methods do not exist. | Register only implemented resource actions. |

## Verification

- Regression tests were added for branch-isolated delivery and return pickers, return source access, optional user fields, duplicate delivery rejection, and assigned-group deletion.
- Final PHP suite: **223 passed**.
- JavaScript suite: **77 passed**.
- Browser smoke test: Deliveries list and new-delivery dialog, Users Management, and Group Permissions loaded and displayed controls correctly. No production records were changed.
- `git diff --check` found no whitespace errors.

## Remaining risk

- PHP 8.5 emits numerous deprecation warnings from the Laravel 8-era dependency stack. Tests pass, but the runtime/dependency combination should be upgraded and retested before depending on PHP 8.5 in production.
- Xendit integration tests use mocked responses. A real sandbox transaction and webhook round-trip were not exercised because payment credentials are not configured locally.
- The browser smoke test covered three screens. It did not manually exercise every role, stock movement, checkout path, or mobile layout.
