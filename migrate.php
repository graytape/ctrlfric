<?php
const CF_MIGRATION_PAGE = true;
require __DIR__.'/bootstrap.php';
require __DIR__.'/lib/migrate-db.php';
$_SESSION['migration_csrf'] ??= bin2hex(random_bytes(24));
$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['migration_csrf'], $_POST['csrf'] ?? '')) throw new RuntimeException('Reload the page and try again.');
        $tables = array_column($conn->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0);
        $candidates = array_values(array_filter($tables, static fn($t) => in_array(strtolower($t), ['users','cf_users'], true)));
        if (count($candidates) !== 1) throw new RuntimeException('The users table is missing or ambiguous. For a new installation, import schema.sql using your hosting database panel.');
        $stmt = $conn->prepare('SELECT password_hash, is_admin FROM `'.$candidates[0].'` WHERE email = ?');
        $email = trim($_POST['email'] ?? '');
        $stmt->bind_param('s', $email); $stmt->execute(); $admin = $stmt->get_result()->fetch_assoc();
        if (!$admin || !$admin['is_admin'] || !password_verify($_POST['password'] ?? '', $admin['password_hash'])) throw new RuntimeException('Invalid administrator credentials.');
        migrateDatabase($conn);
        $message = 'Migration completed. The cf_ tables and new columns are ready.';
    } catch (Throwable $e) { error_log((string)$e); $error = 'Migration failed: '.$e->getMessage(); }
}
$tables = array_column($conn->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ctrl-Fric Migration</title><link rel="stylesheet" href="res/style.css?v=1.5.3"></head><body>
<main class="small-form migration-card"><h1>Database Migration</h1><p>Back up your database before proceeding. Existing data is preserved; tables are renamed with the prefix <code>cf_</code>.</p>
<?php if ($message): ?><p class="success"><?= htmlspecialchars($message); ?></p><a href="index.php">Open Ctrl-Fric</a><?php else: ?>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error); ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['migration_csrf']); ?>"><label for="migration-email">Administrator email</label><input id="migration-email" type="email" name="email" autocomplete="username" required><label for="migration-password">Password</label><input id="migration-password" type="password" name="password" autocomplete="current-password" required><button>Start migration</button></form>
<?php endif; ?><p><small>Existing tables: <?= htmlspecialchars(implode(', ', array_filter($tables, static fn($t)=>str_starts_with($t,'cf_') || in_array(strtolower($t), ['users','user_settings','entries'],true)))); ?></small></p></main></body></html>
