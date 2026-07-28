# GASF Email CRM — Specification

A lightweight shared-inbox CRM for `info@germantampabay.com`, delivered as a module
inside the GASF-Utilities plugin. Approved volunteers sign in on germantampabay.com,
see inbound mail, and reply from a web form with an AI-drafted starting point.

Status: **design agreed, not yet implemented**
Volume: ~4 emails/week, a handful of users. Every decision below is sized for that.

---

## 1. Prerequisites (must happen before any code runs)

### 1.1 Convert info@ from alias to shared mailbox

Today `info@germantampabay.com` is an **alias on a personal mailbox**. Mail lands in a
human's mailbox. Pointing app-only Graph at that would give the CRM read access to
that person's entire mailbox — unacceptable.

A **shared mailbox** is the correct object. Not a room/equipment mailbox (rejects
external senders by default via `RequireSenderAuthenticationEnabled`, and carries
calendar-booking behavior), and not a Teams resource account (no mailbox at all).

```powershell
# Alias must be removed first — the address cannot exist in two places
Set-Mailbox -Identity <owner>@germantampabay.com `
  -EmailAddresses @{remove="info@germantampabay.com"}

New-Mailbox -Shared -Name "GASF Info" -PrimarySmtpAddress info@germantampabay.com

# Optional: mount it in the owner's Outlook
Add-MailboxPermission -Identity info@germantampabay.com `
  -User <owner>@germantampabay.com -AccessRights FullAccess
```

Free, no license, 50GB cap, no interactive sign-in. Existing history stays in the
owner's mailbox; the CRM starts clean at cutover.

### 1.2 Entra app registration (mail access)

Application permissions, admin consent required:

| Permission | Type | Why |
|---|---|---|
| `Mail.ReadWrite` | Application | Read inbound mail, mark read, move |
| `Mail.Send` | Application | Send replies as info@ |

**Then scope it.** Application permissions are tenant-wide by default.

`-PolicyScopeGroupId` will **not** accept the shared mailbox directly — its
underlying account is disabled, so Exchange rejects it with "the identity of the
policy scope is not a security principal." Scope to a mail-enabled security
group containing the mailbox instead:

```powershell
New-DistributionGroup -Name "GASF CRM Scope" -Alias gasf-crm-scope `
  -PrimarySmtpAddress gasf-crm-scope@germantampabay.com `
  -Type Security -Members info@germantampabay.com

Set-DistributionGroup -Identity gasf-crm-scope@germantampabay.com `
  -HiddenFromAddressListsEnabled $true

New-ApplicationAccessPolicy -AppId <app-id> `
  -PolicyScopeGroupId gasf-crm-scope@germantampabay.com `
  -AccessRight RestrictAccess -Description "GASF Email CRM"
```

That group is the control surface: adding a second mailbox to the CRM later
means adding it as a member, with no policy change.

Verify against both mailboxes before going live — the second must be `Denied`:

```powershell
Test-ApplicationAccessPolicy -Identity info@germantampabay.com -AppId <app-id>
Test-ApplicationAccessPolicy -Identity michael@germantampabay.com -AppId <app-id>
```

**Re-run both checks after ANY later change to the app registration, the scope
group's membership, or the policy itself.** Consent edits and group changes can
silently widen access to every mailbox in the tenant, and nothing will tell you
— the app keeps working either way, which is exactly the problem.

**Provisioned values** (IDs are not secrets; the client secret is, and lives only
in `wp_options`):

| | |
|---|---|
| Tenant ID | `2b3975ec-1f38-480d-8983-0fb9cd9b6739` |
| Graph client ID | `4b7d62c4-e3cb-487d-8024-679dd9109e36` |
| Scope group | `gasf-crm-scope@germantampabay.com` |
| Verified | `Granted` on info@, `Denied` on michael@ |

### 1.3 OAuth clients (user sign-in)

- **Google** — OAuth 2.0 Web client, redirect `https://germantampabay.com/email/auth/google/callback`
- **Microsoft** — Entra app, **multi-tenant + personal accounts**, redirect
  `https://germantampabay.com/email/auth/microsoft/callback`

Apple sign-in is **out of scope** (requires Apple Developer Program at $99/yr plus a
client-secret JWT regenerated every ≤6 months; admin approval already gates access,
so Google + Microsoft is sufficient).

This is a **separate app registration** from 1.2. Do not reuse.

### 1.4 Anthropic API key

Claude Haiku for reply drafting. Stored in WordPress options alongside existing
GASF-Utilities keys. Never in the repo.

---

## 2. Placement

- Module in `modules/`, gated by `gasf_site_enable_email_crm` like every other module
- Front-end page at `germantampabay.com/email` — **unlinked**, not in any menu, `noindex`
- Rendered by a page template, not wp-admin (volunteers sign in with Google/Microsoft
  and should never land in the WordPress dashboard)
- REST API namespace `gasf/v1/crm/*`, all routes capability-gated
- Admin approval screen lives in the existing GASF-Utilities settings tab

---

## 3. Data model

Custom tables, `{prefix}gasf_crm_`:

**`threads`** — one row per Graph `conversationId`
```
id, conversation_id (unique), subject, participants (json),
status ENUM('new','claimed','addressed'),
locked_by (user_id, null), locked_at (datetime, null),
first_received_at, last_message_at, last_status_change_at
```

**`messages`** — one row per Graph message
```
id, thread_id (fk), graph_message_id (unique), direction ENUM('in','out'),
from_addr, from_name, to_addrs (json), sent_at,
body_html (sanitized), body_preview, has_attachments,
sent_by_user_id (null for inbound)
```

**`users`** — extends WordPress users, not a replacement
```
wp_user_id, provider ENUM('google','microsoft'), provider_sub (unique per provider),
status ENUM('pending','approved','denied'), approved_by, approved_at, notify_email
```

Attachments are **not mirrored to disk** — listed from Graph metadata and streamed
on demand through an authenticated proxy route.

---

## 4. Authentication & approval

1. User hits `/email`, unauthenticated → sign-in page, two buttons
2. Standard OIDC authorization-code flow with PKCE and `state`
3. On first successful callback: create a WordPress user, record `provider` +
   `provider_sub`, set status `pending`. **No capabilities granted.**
4. Pending users see "Your account is awaiting approval." Nothing else.
5. Admin approves in the settings tab → status `approved`, `gasf_crm_agent`
   capability granted
6. Approved users can reach the inbox

Identity is keyed on `provider` + `provider_sub`, **never on email address** — email
is mutable at the provider and is not a safe primary key. A Google account and a
Microsoft account with the same address are two distinct identities.

Session handling uses WordPress's own auth cookies. All state-changing REST calls
require a nonce.

---

## 5. Mail sync

**Hourly** system cron (not WP-Cron, which only fires on page traffic):

```
0 * * * * cd /path/to/wp && wp gasf-crm sync --quiet
```

Per run:
1. Graph delta query on the shared mailbox Inbox, skipping Junk
2. For each new message, upsert `threads` on `conversationId` and insert into `messages`
3. **Inbound message on an `addressed` thread → status back to `new`** (reopen rule)
4. Also pull Sent Items — if someone replied directly from Outlook instead of the
   CRM, the thread still gets marked `addressed`. The CRM must never disagree with
   the mailbox.
5. Release locks older than 15 minutes
6. Fire notifications for threads that entered `new`

Graph change notifications (webhooks) are deliberately **not** used — subscriptions
expire in under 3 days and need renewal machinery that isn't worth it for 4
emails/week. Revisit only if volume grows by an order of magnitude.

---

## 6. Thread lifecycle

```
        ┌──────────────── inbound reply ────────────────┐
        │                                               │
      NEW ──── user opens (lock) ────> CLAIMED ────> ADDRESSED
        ^                                │
        └──── lock expires / released ────┘
```

- Opening a thread sets `locked_by` + `locked_at`. Others see it read-only with
  "Michael is replying to this" and cannot send.
- Lock auto-expires after 15 minutes of inactivity, refreshed while the compose box
  has focus.
- Sending a reply → `addressed`, lock cleared.
- Threads are never deleted from the mailbox. CRM status is separate metadata.

---

## 7. Reply flow

Replies are sent **as info@germantampabay.com**, with the author's name in the
signature. The users' Google/Apple identities have no relationship to the M365
tenant and cannot send on their own behalf.

Use Graph `createReply` then patch and send, rather than composing a fresh message —
this preserves `In-Reply-To` / `References` so the thread doesn't fragment in the
recipient's client. The sent copy lands in the shared mailbox's Sent Items, so
Outlook and the CRM stay consistent.

v1 replies are text/simple HTML. **No outbound attachments** — add later if asked.

---

## 8. AI draft (Claude Haiku)

**On demand via a button**, not automatically on open — avoids drafting replies to
spam and keeps cost at effectively zero.

Corpus, assembled server-side:

1. **Site content** — pages, posts, and events read **directly from the WordPress
   database**. The module runs on the site, so there is no crawler to write or break,
   and content is always current.
2. **Past replies** — previously sent replies from the CRM, as few-shot tone
   examples. This corpus is thin at launch; a one-time export of historical info@
   replies from the owner's mailbox is worth doing to seed it.

Compiled into a cached context document, rebuilt weekly on cron, injected via prompt
caching. **No vector database** — the entire club site fits comfortably in context at
this scale, and a retrieval layer would be pure overhead.

The draft lands in the compose box as editable text. It is never sent automatically.

---

## 9. Notifications

**OPEN ITEM — pending decision.**

WhatsApp was the original request. It requires, on the sending side: a WhatsApp
Business Account, a dedicated phone number not registered to a personal WhatsApp,
Meta business verification, and a **pre-approved message template** for every message
shape (business-initiated messages outside a 24-hour window cannot be free-form).
Recipients being ordinary WhatsApp users is not the obstacle — the sender
infrastructure is.

For ~4 emails/week that is substantial setup and per-message cost for a trickle.

Options:
| Channel | Setup | Notes |
|---|---|---|
| **Email** (default for v1) | None | Works immediately |
| **SMS via Twilio** | Account + number | No template approval, any phone, ~fractions of a cent |
| **WhatsApp** | WABA + verification + templates | Only if the setup is done |

Built as a **pluggable channel interface** — `send_notification($user, $thread)`
dispatching to registered handlers — so WhatsApp drops in later without touching
anything else. Email ships as the v1 default until decided otherwise.

---

## 10. Security

- Application Access Policy scoping the Graph app to one mailbox (§1.2) — the single
  most important control here
- All secrets in WordPress options, nothing in the repo, matching existing module
  convention
- Inbound HTML sanitized before storage and again before render; no remote image
  loading by default
- All REST routes capability-gated on `gasf_crm_agent`; approval status re-checked
  server-side on every request, never trusted from the client
- `/email` served `noindex, nofollow`
- OIDC: PKCE, `state` validation, `nonce` validation, issuer and audience checks
- Rate limit the draft endpoint per user

---

## 11. Out of scope for v1

Apple sign-in · outbound attachments · full-text search · labels/tags ·
per-user assignment (shared queue only) · mobile app · reply templates library ·
multiple mailboxes

---

## 12. Open questions

1. **Notification channel** — email / email + SMS / WhatsApp (§9)
2. Can historical info@ replies be exported to seed the AI tone corpus? (§8)
3. Who are the initial approved users, and who holds admin rights?
