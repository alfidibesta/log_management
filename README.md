# Factory Gate Log — Belongings In / Out

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
| **Input (personnel)** | everyone | **input-only page** — one compact form plus a live "open records" panel |
| **Verify (personnel)** | everyone | the verification queue: grouped by person, counterpart record beside each row, optional remark, Verify / Undo |
| Summary | everyone | **day / week / month / custom period** totals, by category, by day, top personnel |
| Data Log | admin | full record list, filters, Excel export & import, delete |
| Categories | admin | add / rename / hide the categories used for totals |
| Users | admin | create accounts, set role (admin / personnel), disable accounts |

## Authority (roles)

- **admin** — everything: Data Log, categories, users, deleting records.
- **personnel** — the working pages: Input, Verify, Dashboard, Summary.

Both roles use Input/Verify (an admin is a superset); *Data Log, Categories and
Users* return 403 for personnel, in the UI and on the server. Every page behind
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

## Local development (this workspace)

```sh
.tools/php -S 0.0.0.0:3000 -t log-management/public
bash .tools/smoke.sh     # 86 end-to-end checks
```

It connects to a local MariaDB 10.11 through a unix socket using the
operating-system user (no password stored anywhere).
