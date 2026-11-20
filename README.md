# Access Factory Management — Belongings In / Out

Native PHP + MySQL site for recording **personnel passing the main gate** with
belongings, tools, documents, etc. Minimalist, compact, input-first.

No stock is kept — the log counts **entries and quantities per category per
period**.

## Personnel flow

1. **Record on entry / exit** — personnel (or the gate officer on their behalf)
   logs every crossing on the *Input* page: who, direction (IN/OUT), category,
   belongings, quantity, gate, time.
2. **Verify against the record on exit / return** — the record sits in the
   *Verify* queue. When that person leaves or returns, the record is checked
   against their **opposite crossing record**, which is shown side by side
   (`Check against (opposite crossing)`), then marked ✓ verified with the
   verifier's name and time.
3. **Remark when something is off** — every Verify row has an optional
   *remark* field next to the button (dropdown presets + free text). It is for
   situations like **belongings taken out but not returned**: pick
   `Belongings not returned` (or type your own, e.g. `Partially returned`,
   `Item missing / damaged`) and it is stored with the verification, shown in
   the Data Log under the status, and exported to Excel. Undo clears it.

## Menu

| Menu | Who | What it does |
|------|-----|--------------|
| Dashboard | everyone | IN/OUT today, pending verifications, 7-day chart, totals by category |
| **Record entry** | everyone | **input-only page** — one compact form plus a live "open records" panel |
| **Verify queue** | everyone | the verification queue: grouped by person, counterpart record beside each row, optional remark, Verify / Undo |
| **Documents out** | everyone | record a document leaving with a **fast scan / photo**, stored compressed; leaders approve or reject it |
| **Visitors** | everyone | check **visitors / contractor workers** in and out: company, ID card, host, badge, on-site status |
| Summary | everyone | **day / week / month / custom period** totals for belongings **and documents**, by category, by personnel type, by day, top personnel |
| Help | everyone | this role guide inside the app: what your role can do, each role's steps, correcting mistakes, quick reference |
| Data Log | admin | full belongings record list, filters (incl. person type), Excel export & import, delete |
| Document Log | admin | full document take-out list, filters, Excel export |
| Categories | admin | add / rename / hide the categories used for totals |
| Users & roles | admin | create accounts, assign role (admin / leader / personnel), disable accounts — with the capability matrix and last-admin protection |

## How to use it — by role

Every account has exactly one role: **admin**, **leader** or **personnel**.
The role decides which menu items appear and which actions the server accepts.
Defaults are in the [capability matrix](#authority-roles); an admin can change
the leader/personnel ticks, so a missing page may be deliberate. If a page
answers **403 Forbidden**, your role lacks that capability — ask an admin.

The same guide is built into the site: every signed-in user has a **Help**
entry in the sidebar (`help.php`), which first lists the capabilities their own
role currently holds and then shows these role-by-role steps.

### Everyone — signing in and the shared pages

1. **Sign in** at the login page. On a fresh installation the login page is a
   *create admin account* form instead; the first account is always an admin.
2. **Dashboard** is the starting screen: how many people are on site, how many
   documents await approval, today's IN/OUT, the 7-day chart, totals by
   category, and the **1 Record → 2 Verify → 3 Approve** guide.
3. **Visitors** — check a visitor or contractor worker in *before* they walk
   through, and check them out when they leave. Their row has **Belongings**
   and **Documents** shortcuts that pre-fill the two forms and tag the record
   `Visitor` / `Contractor`.
4. **Summary** — end-of-day, weekly or monthly totals for belongings and
   documents: by category, by person type, by day, top personnel. Pick the
   period and press **Apply period**; the **Export belongings** and
   **Export documents** buttons download that period as Excel.
5. The working pages refresh themselves every ~3 seconds, so a second window
   (for example *Record entry* open while you work in *Verify queue*) updates
   without reloading.

### Personnel / gate officer

Your job is to keep the gate log true: record each crossing **when it
happens**, and verify it when the person comes back.

**When someone enters or leaves**

1. Open **Record entry** (the input page).
2. Fill the form: start typing the **name** (known people are suggested),
   department, **direction** (`IN` = entering the factory, `OUT` = leaving),
   category, belongings / item, quantity, gate and an optional note.
3. The **time is set by the server** when you save — there is nothing to type
   and no way to back-date an entry. Check the direction before saving.
4. Press **Save**. The new crossing appears immediately in the *Open records*
   panel on the same page, and in the Verify queue on the other screen.
5. For a visitor or contractor, check them in on **Visitors** first, then use
   the row's **Belongings** / **Documents** shortcut so the record is tagged
   with their name and type.

**When the person returns (or leaves again)**

1. Open **Verify queue**. Each row shows the record plus the **opposite
   crossing** side by side under *Check against (opposite crossing)*.
2. Compare what went out with what is coming back. If everything matches,
   press **Verify**. The record stores your name and the verification time.
3. If something is off, choose a preset in the **remark** box next to the
   button — `Belongings not returned`, `Partially returned`,
   `Item missing / damaged` — or type your own, then press **Verify**. The
   remark appears under the status in Data Log and in the Excel export.
4. Pressed the wrong row? **Undo** puts the record back in the queue and clears
   the verification and remark.

**Also yours by default:** **Documents out** (record a document leaving, with
its scan) and **Visitors**. You cannot approve document take-outs, delete
records, export data, change categories or manage accounts — those return 403.

**Shift handover:** the Verify queue only lists what still needs checking, so
leave it truthful — verify what you checked, and use a remark rather than
leaving an exception silently open.

### Leader / supervisor

Leaders do everything personnel do — record entry, verify the queue, visitors —
and additionally **approve document take-outs**.

1. The dashboard card **Documents awaiting approval** and the **Documents out**
   menu (with its pending count) show the queue.
2. Open **Documents out**, review the request and its scan, then press
   **Approve** or **Reject** and add a short note if useful. The approver's name
   and the time are stored and shown in the list.
3. You cannot approve a request **you** created — another leader or an admin
   must. **Undo** puts an approved or rejected request back into the queue.
4. Keep the Verify queue moving during peak times; leaders verify exactly like
   personnel.

By default leaders cannot delete records, export/import Excel, change
categories or manage accounts. An admin can grant those capabilities on
*Users & roles* if a supervisor needs them.

### Admin

Admins keep the site usable and the data clean. Admin always holds every
capability and that cannot be removed, so the site can never be locked out.

**Accounts and permissions — Users & roles**

1. **Create an account:** username, full name, password, role. Give gate
   officers `personnel` and supervisors `leader`; use `admin` sparingly.
2. **Change a role** or **disable** an account from its row. Disabled accounts
   can no longer sign in; their past records keep their name. Changes apply to
   already signed-in users on their next click.
3. **Edit permissions:** tick or untick capabilities in the Leader and Personnel
   columns, press **Save permissions**, and it applies on the next request.
   **Restore defaults** brings back the standard matrix. The Admin column is
   fixed at full control.
4. The **last active admin** cannot be demoted or disabled. Create a second
   admin before changing your own account.

**Data and housekeeping**

- **Data Log** — the full belongings history with filters (date, person,
  direction, status, person type). Delete a wrong or duplicate record here,
  **export** the current filter to Excel, or **import** a filled template
  (imported rows are marked verified; bad rows are reported with their
  spreadsheet line number).
- **Document Log** — every document take-out with its approval status, filter
  and Excel export.
- **Documents out → Free up old scans** — removes scans older than 90 days in
  batches while keeping the document records. Use it when *Scans stored* grows;
  the page lists 25 take-outs per page so a large archive stays fast.
- **Categories** — add, rename or hide the categories used for entry and
  totals. Hiding keeps old records and reports intact.
- **Visitors** — check people out, and delete a visit that was created by
  mistake. Deleting a visit does not remove belongings or document records
  already saved against that name.

**Correcting mistakes**

| Mistake | Fix |
|---|---|
| Entry saved with the wrong direction or item | Delete it on **Data Log** (admin), then record it again — records are not edited in place |
| Entry verified by accident | **Undo** on *Verify queue*, then verify it correctly |
| Wrong remark on a verification | **Undo**, then verify again with the right remark |
| Document approved by mistake | **Undo** on *Documents out* and let the right leader decide |
| Visitor still shown on site | **Check out** the visit on *Visitors* |

### Quick reference

| I need to… | Page | By default |
|---|---|---|
| Record someone entering / leaving | Record entry | everyone |
| Check a record against the opposite crossing | Verify queue | everyone |
| Note that belongings were not returned | Verify queue → remark | everyone |
| Record a document going out | Documents out | everyone |
| Approve / reject a document take-out | Documents out | leader, admin |
| Check a visitor in or out | Visitors | everyone |
| See totals for a day / week / month | Summary | everyone |
| Delete or export records, import Excel | Data Log | admin |
| Export document history | Document Log | admin |
| Add or hide categories | Categories | admin |
| Create accounts, change roles or permissions | Users & roles | admin |
| Free up space used by old scans | Documents out | admin |

## Documents out — scan / photo + leader approval

A document that leaves the site (drawing, PO, invoice, report binder) is
recorded on **Documents out**:

1. **Add it with a fast scan or a photo.** *Fast scan (camera)* opens the
   phone camera (`capture`), the file input picks an existing photo. The
   browser downscales it to 1600 px and re-encodes it as JPEG until it fits
   **≤ 200 KB** (`assets/document-scan.js`), then sends only that small copy.
   Without JavaScript the plain upload still works — the server compresses it
   with **GD** (`src/documents.php`), shrinking dimensions and quality until
   the target size is reached, and refuses anything that stays huge.
2. **Wait for approval.** Every new request is *pending*. A **leader** (or an
   admin) approves or rejects it with an optional note; the approver and the
   time are stored and shown in the list. A leader cannot approve their own
   request — another leader or an admin must. *Undo* puts an approved or
   rejected request back into the queue.
3. **The scan stays small and private.** The compressed JPEG lives in the
   `documents` row (`MEDIUMBLOB`, ~100–200 KB), so there is nothing to upload
   into a web-reachable folder; it is served only to signed-in users through
   `document_photo.php?id=…`. The list shows the saved size per scan, the
   average KB per scan and the total MB stored.
4. **Storage stays bounded as files pile up.** The list shows **25 take-outs
   per page** (newest first) instead of loading every scan at once. An admin
   can press **Free up old scans**: scans older than **90 days** are removed in
   batches of 200 while the document record itself stays. The **Scans stored**
   stat always shows the current total size.

## Visitors & contractor workers

They do **not** get accounts — they are registered as visits on **Visitors**:

1. Reception/gate checks them in: name, type (*Visitor* or *Contractor worker*),
   company, ID card no., phone, host, purpose, badge no., time in. A second
   check-in with the same name while they are still on site is refused.
2. The row offers **Belongings** and **Documents** shortcuts that open the two
   input forms with their name (and type) already filled in, so whatever they
   carry in or take out is recorded against the visit and tagged
   `Visitor` / `Contractor`.
3. Reception clicks **Check out** when they leave; *Undo checkout* corrects a
   mistake. The page shows who is on site now, contractors on site, checked in
   today and checked out today. Admins can delete a visit.

Every belonging crossing and every document take-out carries a **person type**
(`employee` / `visitor` / `contractor`), so reports keep employees separate from
outside people. **Summary** has a combined table — gate passes, items out,
documents out, approved, pending — per person type, plus visitor counts for the
period. **Data Log** and **Document Log** can filter by person type and export
it as a `Type` column.

## Authority (roles)

Roles are capability-based: one map in `src/helpers.php` (`role_capabilities()`)
and every page and POST checks it through `can()` / `require_can()`, so the
rules live in one place and cannot drift from page to page.

| Capability | admin | leader | personnel |
|---|:--:|:--:|:--:|
| Record gate entries | ✓ | ✓ | ✓ |
| Verify the queue | ✓ | ✓ | ✓ |
| Approve document take-outs | ✓ | ✓ | — |
| Data logs, categories & export | ✓ | — | — |
| Users & role assignment | ✓ | — | — |

- **admin** — everything, including deleting records and managing accounts.
- **leader** — records and verifies, and approves or rejects document
  take-outs (never their own).
- **personnel** — records gate entries and verifies the queue.

Admins assign roles on **Users & roles**, which shows this matrix with the
Leader and Personnel columns editable: tick a capability, press **Save
permissions**, and it applies on the next request (stored as JSON in the
`settings` table). **Restore defaults** returns to the matrix above. The Admin
row is always full control, so the site cannot be locked out, and role changes
or disabling are refused for the **last active admin**. Every page behind
`login.php` is guarded, all POSTs carry a CSRF token, passwords use
`password_hash()`.

## Auto refresh (two open views stay in sync)

`assets/live.js` polls `state.php` every 2.5 s. The version changes whenever a
record is added, verified, unverified, deleted or a category changes; the page
then re-fetches its HTML fragment from `fragment.php?part=…` and swaps it into
the container marked `data-live`.

So: open *Input* in one window and *Verify* (or *Data Log*) in another — an
entry recorded in one appears in the other within a few seconds, no manual
reload. Fragment parts: `open` (person's open records), `verify` (queue),
`log` (admin data log). Both endpoints require a signed-in session.

## Excel export & import (admin → Data Log)

- **Export** downloads the current filter as `.xlsx`
  (`Date, Time, Person, Department, Category, Item, Qty, Direction, Note, Gate,
  Status, Verified by, Remark`). *Summary* exports the selected period.
- **Import** accepts an `.xlsx` with those columns (`Date, Person, Category,
  Item, Direction` are required; `Remark` is optional and is stored on the
  imported — already verified — row). Rows are validated line by line: bad dates,
  unknown categories or a direction other than IN/OUT are skipped and reported
  with their spreadsheet row number. Imported rows are marked verified.

The reader/writer is dependency-free (`src/excel.php`, ZipArchive + XML).

## First run

Open the site: if no accounts exist, the login page becomes a
**create-admin-account** form. Five categories and a week of sample gate
entries are seeded once automatically. Databases created with the older
`operator` role are migrated automatically on first request.

## Setup on your own hosting

Step-by-step instructions (Windows/XAMPP, macOS, shared hosting) are in
[INSTALL.md](INSTALL.md).

```sh
# 1. create the database
mysql -e "CREATE DATABASE log_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 2. point the app at it (env vars, no credentials in code)
LOG_DB_HOST=127.0.0.1 LOG_DB_PORT=3306 LOG_DB_NAME=log_management \
LOG_DB_USER=app LOG_DB_PASS=...   # read by config.php

# 3. put the contents of public/ in the web root
#    (config.php and src/ must stay OUTSIDE the web root)
```

Schema is created automatically on the first request (`src/db.php`).

## Local development

```sh
php -S 0.0.0.0:3000 -t public
```

The database connection comes from `config.php` (environment variables), so no
credentials live in the repository.
