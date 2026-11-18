<?php
require __DIR__.'/conf.php';
require __DIR__.'/lib/recovery.php';
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$_SESSION['recovery_csrf'] ??= bin2hex(random_bytes(24));
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($_SESSION['recovery_csrf'], $_POST['csrf'] ?? '')) $error = t('Reload the page and try again.');
    elseif ($token !== '') {
        $password = $_POST['password'] ?? '';
        if (strlen($password)<8 || $password !== ($_POST['confirm'] ?? '')) $error = t('Use at least 8 characters and matching passwords.');
        else {
            $conn->begin_transaction();
            try {
                $hash = hash('sha256',$token);
                $stmt = $conn->prepare('SELECT r.user_id FROM cf_password_resets r JOIN cf_users u ON u.id=r.user_id WHERE r.token_hash=? AND r.expires_at>UTC_TIMESTAMP() FOR UPDATE');
                $stmt->bind_param('s',$hash); $stmt->execute(); $reset = $stmt->get_result()->fetch_assoc();
                if (!$reset) throw new RuntimeException('Invalid or expired link.');
                $newHash = password_hash($password,PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE cf_users SET password_hash=? WHERE id=?'); $stmt->bind_param('si',$newHash,$reset['user_id']); $stmt->execute();
                $stmt = $conn->prepare('DELETE FROM cf_password_resets WHERE user_id=?'); $stmt->bind_param('i',$reset['user_id']); $stmt->execute();
                $conn->commit(); $message = t('Password updated. You can now log in.');
            } catch (Throwable $e) { $conn->rollback(); error_log((string)$e); $error = t('Invalid or expired link.'); }
        }
    } else {
        $message = t('If the account exists, a password reset link will be sent.');
        $email = trim($_POST['email'] ?? '');
        // A short session cooldown also applies to unknown addresses.
        if (time()-(int)($_SESSION['recovery_requested'] ?? 0)>=60) {
            $_SESSION['recovery_requested'] = time();
            try {
                $stmt = $conn->prepare('SELECT id,email FROM cf_users WHERE email=?'); $stmt->bind_param('s',$email); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc();
                if ($user) {
                    $settings = mailSettings($conn);
                    if ($settings['base_url']==='') throw new RuntimeException('Configure the public application URL in SMTP preferences.');
                    $tokenValue = bin2hex(random_bytes(32)); $hash = hash('sha256',$tokenValue);
                    // At most one email per account per minute, across sessions.
                    $stmt = $conn->prepare('INSERT INTO cf_password_resets (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR)) ON DUPLICATE KEY UPDATE token_hash=IF(created_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE),VALUES(token_hash),token_hash), expires_at=IF(created_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE),VALUES(expires_at),expires_at), created_at=IF(created_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE),UTC_TIMESTAMP(),created_at)');
                    $stmt->bind_param('is',$user['id'],$hash); $stmt->execute();
                    if ($stmt->affected_rows>0) sendRecoveryMail($settings,$user['email'],rtrim($settings['base_url'],'/').'/recover.php?token='.rawurlencode($tokenValue));
                }
            } catch (Throwable $e) { error_log('Ctrl Fric recovery: '.$e->getMessage()); }
        }
    }
}
?>
<!doctype html><html lang="<?= htmlspecialchars($language); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title><?= t('Reset Password'); ?></title><script src="res/theme.js?v=1.5.3"></script><link rel="icon" href="res/favicon.svg"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css"><link rel="stylesheet" href="res/style.css?v=1.5.3"></head><body><main class="small-form"><h1><?= t('Reset Password'); ?></h1>
<?php if ($message): ?><p class="success"><?= htmlspecialchars($message); ?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error); ?></p><?php endif; ?>
<?php if (!$message): ?><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['recovery_csrf']); ?>">
<?php if ($token !== ''): ?><label><?= t('New Password'); ?><input type="password" name="password" minlength="8" autocomplete="new-password" required></label><label><?= t('Confirm New Password'); ?><input type="password" name="confirm" minlength="8" autocomplete="new-password" required></label><?php else: ?><label>Email<input type="email" name="email" autocomplete="email" required></label><?php endif; ?><button><?= t('Reset Password'); ?></button></form><?php endif; ?><a href="index.php"><?= t('Login'); ?></a></main><?php printFooter(); ?></body></html>
