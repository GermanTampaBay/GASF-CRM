# GASF CRM Workflow Contract (Phase 1)

Status: **active design contract**

This contract defines the canonical workflow model for the next platform pass.
It is implementation-agnostic and is intended to drive database schema, API
shape, UI behavior, and integration boundaries.

---

## 1. Scope

This phase defines:

- Core entities and their required fields
- Canonical workflow states
- Transition guards and invariants
- Shared triage and ownership semantics for a 3-admin team
- Audit and exception requirements

This phase does not define UI layout, component design, or migration scripts.

---

## 2. Core workflow principles

1. **Case-first workflow**: inbound work is tracked as cases, not loose mailbox
   rows.
2. **Ownership is advisory, never exclusive**: any approved admin may triage or
   act on any case at any time.
3. **State transitions are explicit**: all workflow progression is validated by
   one service layer.
4. **No silent failure**: every operational failure becomes an exception item
   with owner, reason, and next action.
5. **One timeline per case**: email, photo actions, consent decisions, and
   integration outcomes are all journaled in one event stream.

---

## 3. Entity contract

## 3.1 Case

Represents one inbound request and all related work.

Required fields:

- `case_id` (immutable identifier)
- `source_type` (`email`, `manual`, `system`)
- `source_key` (provider message/thread identifier when applicable)
- `state` (from case state machine)
- `priority` (`normal`, `urgent`)
- `owner_user_id` (nullable, advisory)
- `owner_claimed_at` (nullable)
- `last_activity_at`
- `created_at`
- `updated_at`
- `closed_at` (nullable)

## 3.2 Message

Represents inbound or outbound communication attached to a case.

Required fields:

- `message_id`
- `case_id`
- `direction` (`inbound`, `outbound`)
- `channel` (`email`)
- `provider_message_id` (nullable for manual/internal notes)
- `from_identity`
- `to_identities`
- `subject`
- `body_html_sanitized`
- `received_or_sent_at`

## 3.3 PhotoItem

Represents a photo candidate in the workflow.

Required fields:

- `photo_id`
- `case_id`
- `storage_ref`
- `state` (from photo state machine)
- `exif_scrub_state` (`pending`, `clean`, `failed`)
- `consent_state` (`unknown`, `limited`, `refused`, `allowed`)
- `backup_state` (`pending`, `synced`, `failed`)
- `published_at` (nullable)
- `created_at`
- `updated_at`

## 3.4 Task (exception and operational work)

Represents actionable non-happy-path work, including failures.

Required fields:

- `task_id`
- `case_id` (nullable for global/system tasks)
- `type` (`exception`, `follow_up`, `review`)
- `state` (`open`, `in_progress`, `resolved`)
- `reason_code`
- `details_json`
- `owner_user_id` (nullable)
- `due_at` (nullable)
- `created_at`
- `updated_at`
- `resolved_at` (nullable)

## 3.5 TimelineEvent

Immutable event stream for audit and troubleshooting.

Required fields:

- `event_id`
- `case_id`
- `event_type`
- `actor_type` (`user`, `system`, `integration`)
- `actor_id` (nullable)
- `payload_json`
- `created_at`

---

## 4. Case state machine

Case states:

- `new`: inbound work not yet picked up
- `active`: being worked
- `waiting_external`: waiting on sender or third-party response
- `blocked`: cannot proceed without intervention
- `ready_to_publish`: all checks pass, waiting final publication action
- `resolved`: work completed
- `cancelled`: intentionally closed without resolution

Transition rules:

- `new -> active` on first meaningful operator action
- `active -> waiting_external` when outbound request requires response
- `waiting_external -> active` on new inbound response
- `active -> blocked` on operational/policy blocker
- `blocked -> active` when blocker is cleared
- `active -> ready_to_publish` when all attached items satisfy gates
- `ready_to_publish -> resolved` on successful publish/close
- `active -> resolved` allowed for non-photo cases
- `any_open_state -> cancelled` only with operator reason

Invariants:

- `resolved` and `cancelled` are terminal.
- Terminal cases cannot receive destructive edits; only additive timeline
  events and reopen actions.
- Every transition writes a `TimelineEvent`.

---

## 5. Photo state machine

Photo states:

- `ingested`
- `screened`
- `tagged`
- `consent_cleared`
- `published`
- `archived`
- `rejected`

Transition rules:

- `ingested -> screened` after content checks
- `screened -> tagged` after person/place/event tagging step
- `tagged -> consent_cleared` only if consent policy is satisfied
- `consent_cleared -> published` only if backup and policy gates pass
- `published -> archived` on archive operation
- `screened|tagged|consent_cleared -> rejected` with reason

Invariants:

- Publish is impossible unless `consent_state = allowed` and
  `exif_scrub_state = clean`.
- Face suggestions never imply a tag; only explicit operator confirmation may
  create person tags.
- Every photo transition is journaled in the case timeline.

---

## 6. Shared triage and ownership contract

Ownership rules:

- `owner_user_id` is a coordination marker, not an access lock.
- Any approved admin can act on any non-terminal case.
- Takeover is always allowed and must be auditable.
- Ownership can be cleared manually (`release`) or automatically on inactivity.

Inactivity policy:

- If no owner activity occurs for the configured timeout window, the case is
  auto-released to unowned `active`.
- Auto-release writes a timeline event with prior owner and timeout reason.

Operational queues:

- `unassigned`: `new` cases with no owner
- `active`: work in progress (owned or unowned)
- `waiting_external`
- `blocked`
- `ready_to_publish`
- `exceptions` (derived from open exception tasks)

This model ensures an "owned" email can always be triaged by another admin when
the original owner is unavailable.

---

## 7. Guardrails for service/API layer

Required behavior:

- All mutations route through one workflow service (no direct table mutation in
  route handlers).
- Service enforces state transition legality.
- Service enforces permission checks on every action.
- Service emits timeline events transactionally with domain changes.
- Failed integration actions create/update `Task(type=exception)` records.

Prohibited behavior:

- Route-level shortcut updates that bypass workflow service.
- Silent no-op success responses on failed transitions or failed side effects.
- Implicit state changes without timeline entries.

---

## 8. Phase 1 acceptance criteria

Phase 1 is complete when:

1. Canonical entities and state machines are approved.
2. Ownership semantics are approved (advisory owner, non-exclusive access).
3. Queue definitions are approved.
4. Transition and invariant rules are approved.
5. This contract is treated as the implementation source of truth for Phase 2.

