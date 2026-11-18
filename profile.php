<?php
require_once __DIR__.'/conf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Fetch current user data
$query = "SELECT * FROM cf_users LEFT JOIN cf_user_settings ON cf_users.id = cf_user_settings.user_id WHERE cf_users.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {header('Location: index.php');exit;}
$is_admin=!empty($user['is_admin']);

require __DIR__.'/lib/recovery.php';
$_SESSION['smtp_csrf'] ??= bin2hex(random_bytes(24));
$success_message = '';
$error_message = '';

if ($is_admin && isset($_POST['save_smtp'])) {
    try {
        if (!hash_equals($_SESSION['smtp_csrf'], requestString($_POST,'csrf'))) throw new RuntimeException('Reload the page and try again.');
        $smtp = mailSettings($conn);
        foreach (['host','username','from','base_url','security'] as $field) $smtp[$field] = trim(requestString($_POST,'smtp_'.$field));
        $smtp['port'] = (int)($_POST['smtp_port'] ?? 587);
        if (($_POST['smtp_password'] ?? '') !== '') $smtp['password'] = $_POST['smtp_password'];
        if (isset($_POST['smtp_clear_password'])) $smtp['password'] = '';
        if (!filter_var($smtp['from'], FILTER_VALIDATE_EMAIL) || !in_array($smtp['security'], ['tls','ssl','none'],true) || $smtp['port']<1 || $smtp['port']>65535
            || ($smtp['host'] !== '' && !preg_match('/^[a-zA-Z0-9.-]+$/D', $smtp['host']))
            || !filter_var($smtp['base_url'],FILTER_VALIDATE_URL) || !in_array(parse_url($smtp['base_url'],PHP_URL_SCHEME),['https','http'],true)
            || parse_url($smtp['base_url'],PHP_URL_QUERY) || parse_url($smtp['base_url'],PHP_URL_FRAGMENT) || parse_url($smtp['base_url'],PHP_URL_USER)
            || ($smtp['username'] !== '' && $smtp['security']==='none')) throw new RuntimeException('Please check the SMTP settings and public application URL.');
        $json = json_encode($smtp,JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare("INSERT INTO cf_app_settings (setting_key,setting_value) VALUES ('smtp',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $stmt->bind_param('s',$json); $stmt->execute(); $success_message = t('Preferences saved');
    } catch (Throwable $e) { error_log((string)$e); $error_message = t('Please check the SMTP settings and public application URL.'); }
}
$smtp = $is_admin ? mailSettings($conn) : [];
$category_budgets = decodeCategoryBudgets($user_settings['category_budgets'] ?? '');

if (!empty($_SESSION['profile_saved'])) {
    $success_message = t($_SESSION['profile_saved']);
    unset($_SESSION['profile_saved']);
}
$_SESSION['currencies_csrf'] ??= bin2hex(random_bytes(24));
if (isset($_POST['currency_action'])) {
    try {
        if (!hash_equals($_SESSION['currencies_csrf'],requestString($_POST,'csrf'))) throw new RuntimeException('Reload the page and try again.');
        $selected=$_POST['enabled_currencies'] ?? [];
        if (!is_array($selected) || array_filter($selected,static fn($v)=>!is_string($v)) || array_diff($selected,array_keys($currency_catalog))) throw new RuntimeException('Invalid currency.');
        $base=$_SESSION['currency']; $selected=array_values(array_unique(array_merge($selected,[$base])));
        $next=$currency_preferences; $next['enabled']=$selected; $next['auto']=isset($_POST['automatic_rates']);
        if ($_POST['currency_action']==='refresh') {
            $fresh=frankfurterRates($base,$selected);
            $next['rates']=array_replace($next['rates'],$fresh['rates']);
            $next['dates']=array_replace($next['dates'],$fresh['dates']);
        } elseif (!$next['auto']) {
            $submitted=$_POST['currency_rates'] ?? [];
            if (!is_array($submitted)) throw new RuntimeException('Please enter a valid exchange rate.');
            foreach ($selected as $code) {
                if ($code===$base) continue;
                $rate=parseSpecificRate($submitted[$code] ?? '');
                if ($rate===false || $rate===null) throw new RuntimeException('Set a positive default rate for each active currency.');
                if ($rate!==defaultCurrencyRate($next,$code)) unset($next['dates'][$code]);
                $next['rates'][$code]=$rate;
            }
        }
        $next['rates'][$base]=1;
        $json=json_encode($next,JSON_THROW_ON_ERROR);
        $stmt=$conn->prepare('UPDATE cf_user_settings SET currency_preferences=? WHERE user_id=?');
        $stmt->bind_param('si',$json,$_SESSION['user_id']); $stmt->execute();
        $_SESSION['profile_saved']=$_POST['currency_action']==='refresh' ? 'Exchange rates updated.' : 'Preferences saved';
        header('Location: profile.php#currencies'); exit;
    } catch (Throwable $e) {error_log((string)$e); $error_message=t($e->getMessage());}
}
// One atomic save for all general preferences.
if (isset($_POST['save_preferences'])) {
    $day = filter_var($_POST['month_start_day'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31]]);
    $newLanguage = requestString($_POST,'language');
    $newCurrency = requestString($_POST,'currency');
    $tagFilter = isset($_POST['category_tag_filter']) ? 1 : 0;
    $hoverActions = isset($_POST['hover_entry_actions']) ? 1 : 0;
    $net = isset($_POST['net_category_expenses']) ? 1 : 0;
    if ($day === false || !in_array($newLanguage, array_keys(supportedLanguages()), true) || !isset($exchange_rates[$newCurrency])) {
        $error_message = t('Please check your preferences.');
    } else {
        $conn->begin_transaction();
        try {
        translateDefaultCategories($conn,(int)$_SESSION['user_id'],$user_settings,$newLanguage);
        $stmt = $conn->prepare('UPDATE cf_user_settings SET language = ?, currency = ?, month_start_day = ?, net_category_expenses = ?, category_tag_filter = ?, hover_entry_actions = ? WHERE user_id = ?');
        $stmt->bind_param('ssiiiii', $newLanguage, $newCurrency, $day, $net, $tagFilter, $hoverActions, $_SESSION['user_id']);
        $stmt->execute();
        $conn->commit();
        $_SESSION['profile_saved'] = 'Preferences saved';
        header('Location: profile.php');
        exit;
        } catch (Throwable $e) {$conn->rollback();error_log((string)$e);$error_message=t('Please check your preferences.');}
    }
}
// Handle password change
if (isset($_POST['change_password'])) {
    $current_password = requestString($_POST,'current_password');
    $new_password = requestString($_POST,'new_password');
    $confirm_password = requestString($_POST,'confirm_password');
    
    if (password_verify($current_password, ($user['password_hash'] ?? ''))) {
        if ($new_password !== '' && $new_password === $confirm_password) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $query = "UPDATE cf_users SET password_hash = ? WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('si', $hashed_password, $_SESSION['user_id']);
            if ($stmt->execute()) {
                $success_message = t('Password successfully updated');
            } else {
                $error_message = t('Error updating password');
            }
        } else {
            $error_message = t('New passwords do not match');
        }
    } else {
        $error_message = t('Current password is incorrect');
    }
}

// Names, order and limits are saved together; each pair travels in one row.
if (isset($_POST['update_categories'])) {
    $names = $_POST['categories'] ?? [];
    $limits = $_POST['category_limits'] ?? [];
    $macros = $_POST['macrocategories'] ?? [];
    $newCategories = [];
    $newBudgets = [];
    $newMacros = [];
    if (!is_array($names) || !is_array($limits) || !is_array($macros) || count($names) !== count($limits)) {
        $error_message = t('Please check the category names and limits.');
    } else {
        foreach (array_values($names) as $index => $name) {
            $raw = array_values($limits)[$index];
            if (!is_string($name) || !is_string($raw)) { $error_message = t('Please check the category names and limits.'); break; }
            $name = trim($name);
            $raw = str_replace(',', '.', trim($raw));
            if ($raw === '') $raw = '0';
            if (!is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0 || (float)$raw > 999999999999.99
                || ($name === '' && (float)$raw > 0) || in_array($name, $newCategories, true)) {
                $error_message = t('Use unique category names and limits of zero or more.'); break;
            }
            if ($name === '') continue;
            $newCategories[] = $name;
            $amount = round((float)$raw, 2);
            if ($amount > 0) $newBudgets[$name] = $amount;
        }
        foreach ($macros as $macro) {
            if (!is_string($macro)) { $error_message = t('Please check the category names and limits.'); break; }
            $macro = trim($macro);
            if ($macro !== '' && !in_array($macro, $newMacros, true)) $newMacros[] = $macro;
        }
    }
    if ($error_message === '') {
        $categoryJson = json_encode($newCategories, JSON_UNESCAPED_UNICODE);
        $macroJson = json_encode($newMacros, JSON_UNESCAPED_UNICODE);
        $budgetJson = json_encode((object)$newBudgets, JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare('UPDATE cf_user_settings SET categories = ?, macrocategories = ?, category_budgets = ? WHERE user_id = ?');
        $stmt->bind_param('sssi', $categoryJson, $macroJson, $budgetJson, $_SESSION['user_id']);
        $stmt->execute();
        $_SESSION['profile_saved'] = 'Categories updated successfully';
        header('Location: profile.php#categories');
        exit;
    }
}

// Admin: Handle user management
if ($is_admin && isset($_POST['admin_action'])) {
    $target_user_id = (int)($_POST['target_user_id'] ?? 0);
    if ($target_user_id === (int)$_SESSION['user_id']) { http_response_code(400); exit('Invalid target'); }
    
    switch ($_POST['admin_action']) {
        case 'delete_user':
            $conn->begin_transaction();
            try {
                foreach (['cf_password_resets' => 'user_id', 'cf_entries' => 'user_id', 'cf_user_settings' => 'user_id', 'cf_users' => 'id'] as $table => $key) {
                    $stmt = $conn->prepare("DELETE FROM `$table` WHERE `$key` = ?");
                    $stmt->bind_param('i', $target_user_id);
                    $stmt->execute();
                }
                $conn->commit();
                $success_message = t('User deleted successfully');
            } catch (Throwable $e) {
                $conn->rollback();
                error_log((string)$e);
                $error_message = t('Error deleting user');
            }
            break;
            
        case 'reset_password':
            $new_password = bin2hex(random_bytes(8)); // Generate random password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $query = "UPDATE cf_users SET password_hash = ? WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('si', $hashed_password, $target_user_id);
            if ($stmt->execute()) {
                $success_message = t('Password reset to: ') . $new_password;
            } else {
                $error_message = t('Error resetting password');
            }
            break;
    }
}

// Export data to CSV
if (isset($_POST['export_data'])) {
    if (ob_get_level()) ob_clean(); // Clear any previous output buffers
    
    $query = "SELECT * FROM cf_entries WHERE user_id = ? ORDER BY date ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="ctrlfric_backup_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Type', 'Category', 'Macrocategory', 'Description', 'Out', 'In', 'Currency', 'Source', 'Note', 'Exchange rate', 'Rate currency', 'Rate date'], ',', '"', '');
    
    while ($row = $result->fetch_assoc()) {
        if (empty($row['date'])) {continue;}
        fputcsv($output, [
            $row['date'],
            $row['type'],
            $row['category'],
            $row['macrocategory'],
            $row['description'],
            $row['type']=='Expense'?$row['amount']:"",
            $row['type']=='Income'?$row['amount']:"",
            $row['currency'],
            $row['source'],
            $row['note'],
            $row['exchange_rate'],
            $row['rate_currency'],
            $row['exchange_rate_date']
        ], ',', '"', '');
    }
    fclose($output);
    exit;
}

require __DIR__ . '/lib/import-handler.php';

// Fetch all users if admin
$all_users = [];
if ($is_admin) {
    $query = "SELECT cf_users.*, (SELECT COUNT(*) FROM cf_entries WHERE cf_entries.user_id = cf_users.id) AS entry_count FROM cf_users WHERE id != ? ORDER BY id DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $all_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars($language); ?>">
<head>
    <meta charset="UTF-8">
    <script src="res/theme.js?v=1.5.3"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('Preferences'); ?></title>
    <link rel="icon" href="res/favicon.svg?v=1.5.3" sizes="any" type="image/svg+xml">
    <link rel="stylesheet" href="res/style.css?v=1.5.3">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css">

</head>
<body data-preference-panel="<?= isset($_POST['currency_action']) ? 'currencies' : (isset($_POST['save_smtp']) ? 'smtp' : (isset($_POST['change_password']) ? 'password' : (isset($_POST['admin_action']) ? 'users' : (isset($_POST['update_categories']) ? 'categories' : (isset($_FILES['csv_file']) ? 'import' : ''))))); ?>">
    <?php printMenu(); ?>
    
    <div class="profile-content">
        
        <?php if ($success_message): ?>
            <div class="success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="preferences-layout">
        <nav class="preferences-nav" aria-label="<?= t('Preferences'); ?>">
            <h1><?= t('Preferences'); ?></h1>
        <?php foreach (['general'=>'General','currencies'=>'Currencies','categories'=>'Categories','password'=>'Password','import'=>'Import','export'=>'Export Data','users'=>'User Management','smtp'=>'Email / SMTP'] as $key=>$label): if (in_array($key, ['users','smtp'],true) && !$is_admin) continue; ?>
            <a href="#<?= $key; ?>" data-panel="<?= $key; ?>"><?= t($label); ?></a>
        <?php endforeach; ?>
        </nav>
        <div class="preferences-panels">
        <section id="general" class="profile-section preferences-card preference-panel">
            <h2><?= t('General'); ?></h2>
            <form method="POST" class="preferences-form">
                <div class="preferences-grid">
                    <div>
                        <label for="language"><?= t('Language'); ?></label>
                        <select id="language" name="language">
                            <?php foreach (supportedLanguages() as $code => $label): ?>
                            <option value="<?= $code; ?>" <?= $language === $code ? 'selected' : ''; ?>><?= $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="currency"><?= t('Currency'); ?></label>
                        <select id="currency" name="currency">
                            <?php foreach ($currencies as $currency): ?>
                            <option value="<?= $currency['code']; ?>" <?= $_SESSION['currency'] === $currency['code'] ? 'selected' : ''; ?>><?= $currency['code']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="month_start_day"><?= t('First day of the accounting month'); ?></label>
                        <input type="number" id="month_start_day" name="month_start_day" min="1" max="31" required value="<?= (int)($user_settings['month_start_day'] ?? 1); ?>">
                    </div>
                </div>
                <p class="form-hint"><?= t('Days 29–31 use the last day in shorter months. Periods are labeled by their starting month.'); ?></p>
                <div class="net-preference">
                    <label class="check-label"><input type="checkbox" name="net_category_expenses" <?= !empty($user_settings['net_category_expenses']) ? 'checked' : ''; ?>> <?= t('Net expenses by category'); ?></label>
                    <p class="form-hint"><?= t('Subtract income in the same category from expense charts. Minimum zero; income and balances stay unchanged.'); ?></p>
                </div>
                <?php foreach (['category_tag_filter'=>'Click category tags to filter transactions','hover_entry_actions'=>'Show edit and delete actions on hover (desktop)'] as $setting=>$label): ?>
                <label class="check-label"><input type="checkbox" name="<?= $setting; ?>" <?= !empty($user_settings[$setting]) ? 'checked' : ''; ?>> <?= t($label); ?></label>
                <?php endforeach; ?>
                <div class="form-actions"><button type="submit" name="save_preferences"><?= t('Save preferences'); ?></button></div>
            </form>
        </section>

        <section id="currencies" class="profile-section preference-panel">
            <h2><?= t('Currencies'); ?></h2>
            <form method="post" id="currencies-form">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['currencies_csrf']); ?>">
                <label class="check-label currency-auto-label"><input type="checkbox" role="switch" class="currency-switch" id="automatic-rates" name="automatic_rates" <?= $currency_preferences['auto']?'checked':''; ?>><?= t('Fetch exchange rates automatically for each new entry'); ?></label>
                <p class="form-hint"><?= t('Rates are saved with each new entry. Changing defaults never changes earlier entries.'); ?></p>
                <p class="form-hint"><?= t('Frankfurter supplies the latest available daily rate. On weekends or holidays the publication date may be earlier.'); ?></p>
                <div class="currency-list">
                <?php foreach ($currency_catalog as $code=>$meta): $isBase=$code===$_SESSION['currency']; $enabled=in_array($code,$currency_preferences['enabled'],true); ?>
                    <div class="currency-setting-row" data-currency="<?= $code; ?>" <?= $enabled?'':'hidden'; ?>>
                        <div class="currency-name"><strong><?= $code; ?></strong> <span data-currency-name="<?= $code; ?>"><?= htmlspecialchars($meta['name']); ?></span></div>
                        <?php if ($isBase): ?><small><?= t('Account reference currency'); ?></small>
                        <?php else: ?>
                        <div class="currency-default"><label for="rate-<?= $code; ?>">1 <?= $code; ?> =</label><input id="rate-<?= $code; ?>" name="currency_rates[<?= $code; ?>]" type="number" step="any" min="0.0000000001" max="9999999999" value="<?= htmlspecialchars(displayCurrencyRate($currency_preferences['rates'][$code] ?? '')); ?>" <?= $currency_preferences['auto']||!$enabled?'disabled':''; ?>><span><?= htmlspecialchars($_SESSION['currency']); ?></span>
                        <?php if (isset($currency_preferences['dates'][$code])): ?><small><?= t('Rate date'); ?>: <?= htmlspecialchars($currency_preferences['dates'][$code]); ?></small><?php endif; ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
                <details class="currency-manager">
                    <summary><?= t('Manage currencies'); ?></summary>
                    <div class="currency-choices">
                    <?php foreach($currency_catalog as $code=>$meta): $isBase=$code===$_SESSION['currency']; ?>
                        <label class="currency-enable"><input type="checkbox" name="enabled_currencies[]" value="<?= $code; ?>" <?= in_array($code,$currency_preferences['enabled'],true)?'checked':''; ?> <?= $isBase?'disabled':''; ?>><strong><?= $code; ?></strong> <span data-currency-name="<?= $code; ?>"><?= htmlspecialchars($meta['name']); ?></span></label>
                    <?php endforeach; ?>
                    </div>
                </details>
                <div class="currency-actions"><button type="submit" name="currency_action" value="save"><?= t('Save currencies'); ?></button><button type="submit" name="currency_action" value="refresh" formnovalidate><?= t('Update latest exchange rates'); ?></button></div>
            </form>
        </section>
        <script src="res/currencies.js?v=1.5.3" defer></script>
        <!-- Categories Management Section -->
        <section id="categories" class="profile-section preference-panel">
            <h2><?php echo t('Manage Categories'); ?></h2>
            <form method="POST" class="form-group" id="categoriesForm">
                    <div>
                        <label><?php echo t('Categories'); ?>:</label>
                        <p class="form-hint category-hint"><?= t('Monthly limit beside each name. Zero = inactive. Drag the handle to reorder.'); ?> (<?= htmlspecialchars($_SESSION['currency']); ?>)</p>
                        <ul id="categoriesList">
                            <?php 
                            $user_categories = $categories;
                            foreach ($user_categories as $category): 
                            ?>
                                <li class="category-item">
                                    <button type="button" class="category-drag" aria-label="<?= t('Reorder'); ?>" title="<?= t('Reorder'); ?>">⠿</button>
                                    <div class="category-input-pair">
                                        <input type="text" class="profile-category-input" name="categories[]" aria-label="<?= t('Category'); ?>" value="<?= htmlspecialchars($category); ?>">
                                        <input type="number" class="category-limit-input" name="category_limits[]" min="0" max="999999999999.99" step="0.01" aria-label="<?= t('Monthly spending limit'); ?>" title="<?= t('Monthly spending limit'); ?> (<?= htmlspecialchars($_SESSION['currency']); ?>)" value="<?= htmlspecialchars((string)($category_budgets[$category] ?? 0)); ?>">
                                    </div>
                                    <button type="button" class="remove-category" aria-label="<?= t('Delete'); ?>">×</button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" id="addCategory" class="wauto"><i class="fa-solid fa-plus icon"></i><?php echo t('Add Category'); ?></button>
                    </div>

                    <div>
                        <label><?php echo t('Macrocategories'); ?>:</label><br>
                        <ul id="macrocategoriesList">
                            <?php 
                            $user_macrocategories = $macrocategories;
                            foreach ($user_macrocategories as $macrocategory): 
                            ?>
                                <li class="category-item">
                                    <button type="button" class="category-drag" aria-label="<?= t('Reorder'); ?>" title="<?= t('Reorder'); ?>">⠿</button>
                                    <input type="text" class="profile-category-input" name="macrocategories[]" value="<?php echo htmlspecialchars($macrocategory); ?>">
                                    <button type="button" class="remove-category" aria-label="<?= t('Delete'); ?>">×</button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" id="addMacrocategory" class="wauto"><i class="fa-solid fa-plus icon"></i><?php echo t('Add Macrocategory'); ?></button>
                    </div>
                
                <button type="submit" name="update_categories"><?php echo t('Save Categories'); ?></button>
            </form>
        </section>

        <!-- Password Change Section -->
            <section id="password" class="profile-section pwd preference-panel">
            <h3><?php echo t('Change Password'); ?></h3>
            <form method="POST" class="form-group small-form">
                <div>
                    <label for="current_password"><?php echo t('Current Password'); ?>:</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div>
                    <label for="new_password"><?php echo t('New Password'); ?>:</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>
                <div>
                    <label for="confirm_password"><?php echo t('Confirm New Password'); ?>:</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <button class="w100" type="submit" name="change_password"><?php echo t('Update Password'); ?></button>
            </form>
        </section>
        

<section id="import" class="profile-section preference-panel"><h2><?= t('Import CSV'); ?></h2>    <form method="POST" enctype="multipart/form-data" class="small-form">
        <div style="font-family: monospace; font-size: 9pt; border: 1px solid #ddd; padding: 5px; margin: 10px;">
            <b><?= t("Excel → Save in .csv"); ?></b>
            <br>
            <br>A = <?= t("Date"); ?> (dd/mm/yyyy)
            <br>B = <?= t("Category"); ?>
            <br>C = <?= t("Description"); ?>
            <br>D = <?= t("Expense"); ?>
            <br>E = <?= t("Income"); ?>
            <br>F = <?= t("Note"); ?>
        </div>
        <label for="csv_file"><?php echo t('Choose a CSV file to upload:'); ?></label>
        <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
        <button class="w100" type="submit"><?php echo t('Import'); ?></button>
    </form>
</section>
        <!-- Data Export Section -->
        <section id="export" class="profile-section preference-panel">
            <h2><?php echo t('Export Data'); ?></h2>
            <form method="POST" class="form-group">
                <button type="submit" class="wauto" name="export_data">
                <i class="fa-solid fa-file fa-2x"></i> <?php echo t('Download CSV'); ?></button>
            </form>
        </section>

        
    <!-- Admin Section -->
    <?php if ($is_admin): ?>
        <section id="users" class="profile-section admin-section preference-panel">
            <h2><?php echo t('User Management'); ?></h2>
            <table class="dash table">
                <thead>
                    <tr>
                        <th><?php echo t('Name'); ?></th>
                        <th><?php echo t('Email'); ?></th>
                        <th><?php echo t('Entries'); ?></th>
                        <th><?php echo t('Created'); ?></th>
                        <th><?php echo t('Last Access'); ?></th>
                        <th><?php echo t('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_users as $other_user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($other_user['name'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($other_user['email'] ?? '')); ?></td>
                        <td class="entry-count"><?php echo (int)$other_user['entry_count']; ?></td>
                        <td><?php echo formatDate($other_user['created_at'] ?? null, 2); ?></td>
                        <td><?php echo formatDate($other_user['updated_at'] ?? null, 2); ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="target_user_id" value="<?php echo $other_user['id']; ?>">
                                <button type="submit" name="admin_action" value="reset_password" 
                                        onclick="return confirm('<?php echo t('Reset password for this user?'); ?>')"><i class="fa-solid fa-key"></i></button>
                                <button type="submit" name="admin_action" value="delete_user" 
                                        onclick="return confirm('<?php echo t('Delete this user?'); ?>')"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
    <?php if ($is_admin): ?>
    <section id="smtp" class="profile-section preference-panel"><h2><?= t('Email / SMTP'); ?></h2>
    <form method="post" class="smtp-form"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['smtp_csrf']); ?>">
    <?php foreach (['base_url'=>'Public application URL (including subdirectory)','from'=>'Sender email','host'=>'SMTP host (empty = PHP mail)','port'=>'SMTP port','username'=>'SMTP username'] as $field=>$label): ?>
    <label><?= t($label); ?><input name="smtp_<?= $field; ?>" value="<?= htmlspecialchars((string)$smtp[$field]); ?>" <?= $field==='port' ? 'type="number" min="1" max="65535"' : ($field==='base_url' ? 'type="url"' : ($field==='from' ? 'type="email"' : 'type="text"')); ?> <?= in_array($field,['base_url','from'],true)?'required':''; ?>></label>
    <?php endforeach; ?>
    <label><?= t('Encryption'); ?><select name="smtp_security"><?php foreach (['tls'=>'STARTTLS','ssl'=>'TLS / SSL','none'=>'None'] as $value=>$label): ?><option value="<?= $value; ?>" <?= $smtp['security']===$value?'selected':''; ?>><?= $label; ?></option><?php endforeach; ?></select></label>
    <label><?= t('SMTP password (leave empty to keep)'); ?><input type="password" name="smtp_password" autocomplete="new-password" value=""></label>
    <label class="check-label"><input type="checkbox" name="smtp_clear_password"> <?= t('Remove saved SMTP password'); ?></label>
    <p class="form-hint"><?= t('If SMTP fails, PHP mail() is used. Delivery depends on your hosting mail configuration.'); ?></p>
    <button name="save_smtp"><?= t('Save preferences'); ?></button></form></section>
    <?php endif; ?>
    </div></div></div>


    <script src="res/preferences.js" defer></script>
    <script src="res/profile-categories.js" defer></script>
    <script>

        function addCategoryRow(listId, isCategory) {
            const row = document.createElement('li');
            row.className = 'category-item';
            const handle = document.createElement('button');
            handle.type = 'button'; handle.className = 'category-drag'; handle.textContent = '⠿';
            handle.setAttribute('aria-label', <?= scriptJson(t('Reorder')); ?>);
            row.append(handle);
            const name = document.createElement('input');
            name.type = 'text'; name.className = 'profile-category-input';
            name.name = isCategory ? 'categories[]' : 'macrocategories[]';
            name.setAttribute('aria-label', isCategory ? <?= scriptJson(t('Category')); ?> : <?= scriptJson(t('Macrocategory')); ?>);
            if (isCategory) {
                const pair = document.createElement('div'); pair.className = 'category-input-pair';
                const limit = document.createElement('input');
                Object.assign(limit, {type: 'number', name: 'category_limits[]', className: 'category-limit-input', min: '0', max: '999999999999.99', step: '0.01', value: '0'});
                limit.setAttribute('aria-label', <?= scriptJson(t('Monthly spending limit')); ?>);
                pair.append(name, limit); row.append(pair);
            } else row.append(name);
            const remove = document.createElement('button');
            remove.type = 'button'; remove.className = 'remove-category';
            remove.setAttribute('aria-label', <?= scriptJson(t('Delete')); ?>);
            remove.textContent = '×';
            row.append(remove);
            document.getElementById(listId).append(row);
            name.focus();
        }
        document.getElementById('addCategory').addEventListener('click', () => addCategoryRow('categoriesList', true));
        document.getElementById('addMacrocategory').addEventListener('click', () => addCategoryRow('macrocategoriesList', false));

        document.addEventListener('click', function(e) {
            if (e.target.closest('.remove-category')) {
                e.target.closest('.category-item').remove();
            }
        });
    </script>
<?php printFooter(); ?>
</body>
</html>