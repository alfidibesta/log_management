<?php
require_once __DIR__ . '/../src/helpers.php';
require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'save') {
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            flash('Category name is required.', 'err');
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?")->execute([$name, $id]);
                    flash('Category renamed.');
                } else {
                    $pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$name]);
                    flash('Category added.');
                }
            } catch (PDOException $e) {
                flash('That category already exists.', 'err');
            }
        }
        header('Location: categories.php');
        exit;
    }

    if ($action === 'toggle') {
        $pdo->prepare("UPDATE categories SET active = 1 - active WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
        flash('Category status changed.');
        header('Location: categories.php');
        exit;
    }
}

$cats = $pdo->query("SELECT c.*,
        (SELECT COUNT(*) FROM logs l WHERE l.category_id = c.id) AS entries
      FROM categories c ORDER BY c.active DESC, c.name")->fetchAll();

$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $st->execute([$editId]);
    $editing = $st->fetch() ?: null;
}

page_header('Categories', 'categories.php');
?>
<div class="card">
  <div class="card-head">
    <h2><?= $editing ? 'Rename category' : 'Add category' ?></h2>
    <?php if ($editing): ?><a class="btn btn-ghost btn-sm" href="categories.php">Cancel</a><?php endif; ?>
    <span class="filters-summary">used to total the log — e.g. Tools, Documents, Personal Belongings</span>
  </div>
  <div class="card-body">
    <form method="post" class="form-row">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editing ? 'save' : 'create' ?>">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <label class="field grow">Name
        <input type="text" name="name" required maxlength="60" placeholder="e.g. Tools &amp; Equipment"
               value="<?= e($editing['name'] ?? '') ?>">
      </label>
      <button class="btn btn-primary" type="submit"><?= $editing ? 'Save' : 'Add category' ?></button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <h2>All categories</h2>
    <span class="filters-summary"><?= count($cats) ?> records</span>
  </div>
  <div class="card-body flush">
    <?php if (!$cats): ?>
      <div class="empty">No categories yet — add the first one above.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Name</th><th class="num">Entries logged</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td><strong><?= e($c['name']) ?></strong></td>
            <td class="num"><?= (int) $c['entries'] ?></td>
            <td>
              <?php if ($c['active']): ?>
                <span class="badge badge-in">active</span>
              <?php else: ?>
                <span class="badge badge-neutral">hidden</span>
              <?php endif; ?>
            </td>
            <td class="row-actions">
              <a class="btn btn-ghost btn-xs" href="categories.php?edit=<?= (int) $c['id'] ?>">Rename</a>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn-ghost btn-xs" type="submit">
                  <?= $c['active'] ? 'Hide' : 'Show' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint" style="margin:12px 14px">Categories with logged entries are hidden from the Input form
        instead of deleted, so past records keep their totals.</p>
    <?php endif; ?>
  </div>
</div>
<?php page_footer(); ?>
