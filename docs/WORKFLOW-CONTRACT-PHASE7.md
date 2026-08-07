# Workflow Contract — Phase 7 (Replay Safety Completion)

Phase 7 closes the remaining duplicate-action gaps on high-impact workflow
writes that were still outside the Stage 6 pass.

## Scope

1. Add operation-id replay safety to remaining mutation-heavy volunteer actions:
   - Claude draft generation (`/crm/threads/{id}/draft`)
   - Manual mailbox sync (`/crm/sync`)
   - Photo library bulk tagging (`/crm/photos/bulk-tag`)
   - Person maintenance (`/crm/photos/person`)
   - Place maintenance (`/crm/photos/place`)
   - Bulk zip build (`/crm/photos/zip`)
2. Ensure the browser submits `op_id` for each covered action.
3. Keep duplicate responses shape-compatible with existing UI handlers.

## Acceptance Criteria

1. Retried POSTs with the same `op_id` do not execute side effects twice.
2. Expensive paths (draft generation and zip build) can return the original
   successful payload for duplicate retries where possible.
3. Existing volunteer UI flows continue to work without client rewrites.
4. Existing static checks pass.
