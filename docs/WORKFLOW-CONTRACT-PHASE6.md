# GASF CRM Workflow Contract (Phase 6)

Status: **active implementation contract**

Phase 6 closes remaining duplicate-action gaps by extending operation-ID
idempotency to operator workflow controls and upload-style mutations that are
most exposed to retries and double-clicks.

---

## 1. Scope

1. Thread workflow controls (`takeover`, case-owner/state/exception actions,
   `addressed`, `ignore`, `restore`).
2. Photo uploader (`/crm/photos/upload`) where network retries are expected.
3. Flyer event creation (`/crm/photos/events/create`) where repeated clicks can
   produce duplicate calendar rows without a request-level guard.

---

## 2. Required behavior

1. Covered routes consume `op_id` and must not replay side effects on duplicate
   submissions.
2. UI sends stable `op_id` values per user action attempt.
3. Upload retries for in-flight operations are treated as retryable from the UI
   perspective, not as hard failures.

---

## 3. Acceptance criteria

1. Repeated clicks on thread workflow actions do not produce duplicate audit
   effects.
2. A repeated uploader submission for the same in-flight `op_id` eventually
   settles without creating duplicate library records.
3. Flyer event creation is replay-safe under retried requests.
