<?php
/**
 * Help — the role guide, inside the app.
 *
 * The page is readable by everyone who is signed in; the card at the top
 * shows what the current account may do, and every role section stays
 * available so people know who to hand a task to.
 */
require_once __DIR__ . '/../src/helpers.php';
require_login();

$u        = current_user();
$role     = (string) ($u['role'] ?? 'personnel');
$roleName = role_label($role);

page_header('Help', 'help.php');
?>
<div class="card">
  <div class="card-head">
    <h2>How to use this site</h2>
    <span class="badge badge-accent">Signed in as <?= e($roleName) ?></span>
  </div>
  <div class="card-body">
    <p class="muted" style="margin:0">
      There are two paths, and you can start on either one:
      <strong>belongings</strong> — record the crossing, then verify it against
      the opposite one — and <strong>documents out</strong> — record the
      take-out, then a leader approves it. This page explains each role's part
      in both.
    </p>
    <div class="help-nav">
      <a class="chip" href="#everyone">Everyone</a>
      <a class="chip" href="#personnel">Personnel / gate officer</a>
      <a class="chip" href="#leader">Leader / supervisor</a>
      <a class="chip" href="#admin">Admin</a>
      <a class="chip" href="#fixes">Correcting mistakes</a>
      <a class="chip" href="#tasks">Quick reference</a>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>What your role can do</h2>
    <span class="role role-<?= e($role) ?>" style="font-size:11px"><?= e($roleName) ?></span>
  </div>
  <div class="card-body">
    <?php foreach (capability_groups() as $group => $caps): ?>
      <div class="section-title"><?= e($group) ?></div>
      <ul class="caps">
        <?php foreach ($caps as $cap => $label): ?>
          <li class="<?= can($cap) ? 'yes' : '' ?>"><?= can($cap) ? '✓' : '—' ?> <?= e($label) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
    <p class="muted" style="margin:10px 0 0;font-size:12px">
      This is the live configuration — an admin can change it on
      <?php if (can('manage_users')): ?>
        <a href="users.php">Users &amp; roles</a>.
      <?php else: ?>
        Users &amp; roles. If you need something that is not ticked, ask an admin.
      <?php endif; ?>
      A page your role cannot open answers <strong>403</strong> and lists the
      pages it can open instead — it never leaves you on a dead end.
    </p>
  </div>
</div>

<div class="card help" id="everyone">
  <div class="card-head"><h2>Everyone — signing in and shared pages</h2></div>
  <div class="card-body">
    <ol>
      <li><strong>Sign in</strong> at the login page. On a fresh installation the
        page is a <em>create admin account</em> form instead; the first account
        is always an admin.</li>
      <li><strong>Dashboard</strong> is the starting screen: who is on site,
        documents awaiting approval, today's IN/OUT, the 7-day chart, totals by
        category, and the two gate paths (belongings, documents out).</li>
      <li><strong>Gate kiosk</strong> is the desk screen: today's visitors, who
        is on site and what still needs verifying, refreshing itself. Its top
        bar opens only <em>Visitors</em>, <em>Record entry</em> and
        <em>Documents out</em>, each without the sidebar; <strong>Exit
        kiosk</strong> brings the normal menu back.</li>
      <li><strong>Visitors</strong> — check a visitor or contractor worker in
        <em>before</em> they walk through, and out when they leave. The row's
        <strong>Belongings</strong> and <strong>Documents</strong> shortcuts
        pre-fill the forms and tag the record as Visitor / Contractor.</li>
      <li><strong>Summary</strong> — pick a day, week, month or custom period and
        press <strong>Apply period</strong>; the two export buttons download that
        period as Excel.</li>
      <li>The working pages refresh every few seconds, so an entry recorded in
        one window appears in another without a manual reload.</li>
    </ol>
  </div>
</div>

<div class="card help" id="personnel">
  <div class="card-head">
    <h2>Personnel / gate officer</h2>
    <?php if ($role === 'personnel'): ?><span class="badge badge-accent">Your role</span><?php endif; ?>
  </div>
  <div class="card-body">
    <p>Keep the gate log true: record each crossing when it happens, and verify
      it when the person comes back.</p>

    <h3>When someone enters or leaves</h3>
    <ol>
      <li>Open <a href="input.php">Record entry</a>.</li>
      <li>Start typing the <strong>name</strong> (known people are suggested),
        then choose department, <strong>direction</strong> (IN = entering the
        factory, OUT = leaving), category, belongings / item, quantity, gate and
        an optional note.</li>
      <li>The <strong>time is set by the server</strong> when you save — nothing
        to type and no back-dating. Check the direction before saving.</li>
      <li>Press <strong>Save</strong>. The crossing appears in the <em>Open
        records</em> panel and in the Verify queue at once.</li>
      <li>For a visitor or contractor, check them in on
        <a href="visitors.php">Visitors</a> first, then use the row's
        <strong>Belongings</strong> / <strong>Documents</strong> shortcut.</li>
    </ol>

    <h3>When the person returns (or leaves again)</h3>
    <ol>
      <li>Open <a href="verify.php">Verify queue</a>. Each row shows the record
        and its <strong>opposite crossing</strong> side by side under
        <em>Check against (opposite crossing)</em>.</li>
      <li>Compare what went out with what is coming back. If it matches, press
        <strong>Verify</strong>; your name and the time are stored.</li>
      <li>If something is off, pick a <strong>remark</strong> preset —
        <em>Belongings not returned</em>, <em>Partially returned</em>,
        <em>Item missing / damaged</em> — or type your own, then press
        <strong>Verify</strong>. It shows under the status in Data Log and in the
        Excel export.</li>
      <li>Pressed the wrong row? <strong>Undo</strong> puts it back in the queue
        and clears the verification and remark.</li>
    </ol>

    <h3>Also yours by default</h3>
    <ul>
      <li><a href="documents.php">Documents out</a> — record a document leaving,
        with a fast scan, a photo or an attached PDF. Leaders approve it; you
        cannot approve document take-outs.</li>
      <li><a href="visitors.php">Visitors</a> — check people in and out.</li>
      <li>Deleting records, exporting data, changing categories and managing
        accounts stay with admins.</li>
    </ul>
    <p><strong>Shift handover:</strong> the Verify queue only lists what still
      needs checking, so leave it truthful — verify what you checked, and use a
      remark rather than leaving an exception silently open.</p>
  </div>
</div>

<div class="card help" id="leader">
  <div class="card-head">
    <h2>Leader / supervisor</h2>
    <?php if ($role === 'leader'): ?><span class="badge badge-accent">Your role</span><?php endif; ?>
  </div>
  <div class="card-body">
    <p>Leaders do everything gate officers do — record, verify, visitors — and
      additionally clear document take-outs.</p>
    <ol>
      <li>The dashboard card <strong>Documents awaiting approval</strong> and the
        <a href="documents.php?status=pending">Documents out</a> menu show the
        pending queue.</li>
      <li>Review the request and its scan or PDF, then press <strong>Approve</strong> or
        <strong>Reject</strong>, with a short note if useful. The approver's name
        and the time are stored in the list.</li>
      <li>You cannot approve a request <strong>you</strong> created — another
        leader or an admin must. <strong>Undo</strong> puts an approved or
        rejected request back into the queue.</li>
      <li>Keep the Verify queue moving at peak times; leaders verify exactly like
        gate officers.</li>
    </ol>
    <p class="muted">By default leaders cannot delete records, export/import
      Excel, change categories or manage accounts. An admin can grant any of
      those on Users &amp; roles.</p>
  </div>
</div>

<div class="card help" id="admin">
  <div class="card-head">
    <h2>Admin</h2>
    <?php if ($role === 'admin'): ?><span class="badge badge-accent">Your role</span><?php endif; ?>
  </div>
  <div class="card-body">
    <p>Admins keep the site usable and the data clean. The admin role always
      holds every capability, so the site cannot be locked out.</p>

    <h3>Accounts and permissions — Users &amp; roles</h3>
    <ol>
      <li><strong>Create an account</strong> with username, full name, password
        and role: gate officers get <em>personnel</em>, supervisors
        <em>leader</em>; use <em>admin</em> sparingly.</li>
      <li><strong>Change a role</strong> or <strong>disable</strong> an account
        from its row. Disabled accounts cannot sign in; past records keep their
        name. Changes apply to signed-in users on their next click.</li>
      <li><strong>Edit permissions</strong>: tick or untick Leader and Personnel
        capabilities, press <strong>Save permissions</strong>, and it applies on
        the next request. <strong>Restore defaults</strong> brings back the
        standard matrix.</li>
      <li>The <strong>last active admin</strong> cannot be demoted or disabled.
        Create a second admin before changing your own account.</li>
    </ol>

    <h3>Data and housekeeping</h3>
    <ul>
      <li><a href="log.php">Data Log</a> — the full belongings history with
        filters. Delete a wrong or duplicate record here, export the current
        filter to Excel, or import a filled template (imported rows are marked
        verified; bad rows are reported with their spreadsheet line number).</li>
      <li><a href="doclog.php">Document Log</a> — every document take-out with
        its approval status, filters and Excel export.</li>
      <li><a href="documents.php">Documents out</a> → <strong>Free up old
        scans</strong> — removes scans older than 90 days in batches while
        keeping the document records. Use it when <em>Scans stored</em> grows;
        the page lists 25 take-outs per page.</li>
      <li><a href="categories.php">Categories</a> — add, rename or hide the
        categories used for entry and totals. Hiding keeps old records and
        reports intact.</li>
      <li><a href="visitors.php">Visitors</a> — check people out, and delete a
        visit created by mistake. Belongings and document records already saved
        against that name stay in the logs.</li>
    </ul>
  </div>
</div>

<div class="card" id="fixes">
  <div class="card-head"><h2>Correcting mistakes</h2></div>
  <div class="card-body flush">
    <table>
      <thead><tr><th>Mistake</th><th>Fix</th></tr></thead>
      <tbody>
        <tr><td>Entry saved with the wrong direction or item</td>
          <td>Delete it on <a href="log.php">Data Log</a> (admin), then record it again — records are not edited in place.</td></tr>
        <tr><td>Entry verified by accident</td>
          <td><strong>Undo</strong> on <a href="verify.php">Verify queue</a>, then verify it correctly.</td></tr>
        <tr><td>Wrong remark on a verification</td>
          <td><strong>Undo</strong>, then verify again with the right remark.</td></tr>
        <tr><td>Document approved by mistake</td>
          <td><strong>Undo</strong> on <a href="documents.php">Documents out</a> and let the right leader decide.</td></tr>
        <tr><td>Visitor still shown on site</td>
          <td><strong>Check out</strong> the visit on <a href="visitors.php">Visitors</a>.</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="card" id="tasks">
  <div class="card-head"><h2>Quick reference</h2></div>
  <div class="card-body flush">
    <table>
      <thead><tr><th>I need to…</th><th>Page</th><th>By default</th></tr></thead>
      <tbody>
        <tr><td>Record someone entering / leaving</td><td>Record entry</td><td>everyone</td></tr>
        <tr><td>Check a record against the opposite crossing</td><td>Verify queue</td><td>everyone</td></tr>
        <tr><td>Note that belongings were not returned</td><td>Verify queue → remark</td><td>everyone</td></tr>
        <tr><td>Record a document going out</td><td>Documents out</td><td>everyone</td></tr>
        <tr><td>Attach a PDF to a document take-out</td><td>Documents out → PDF file</td><td>everyone</td></tr>
        <tr><td>Approve / reject a document take-out</td><td>Documents out</td><td>leader, admin</td></tr>
        <tr><td>Check a visitor in or out</td><td>Visitors</td><td>everyone</td></tr>
        <tr><td>See totals for a day / week / month</td><td>Summary</td><td>everyone</td></tr>
        <tr><td>Delete or export records, import Excel</td><td>Data Log</td><td>admin</td></tr>
        <tr><td>Export document history</td><td>Document Log</td><td>admin</td></tr>
        <tr><td>Add or hide categories</td><td>Categories</td><td>admin</td></tr>
        <tr><td>Create accounts, change roles or permissions</td><td>Users &amp; roles</td><td>admin</td></tr>
        <tr><td>Free up space used by old scans</td><td>Documents out</td><td>admin</td></tr>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
