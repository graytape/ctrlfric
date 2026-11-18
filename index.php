<?php
include 'conf.php';


// logout
if (!empty($_GET['logout']) && $_GET['logout']=="true") {
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    if (!empty($_SESSION['registration_success'])) {
        $success_message = t('Account created successfully');
        unset($_SESSION['registration_success']);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        // Handle login, account creation, or password reset
        $action = $_POST['action'];
        
        if ($action === 'login') {
            $email = requestString($_POST,'email');
            $password = requestString($_POST,'password');
            // Authenticate user
            $query = "SELECT id, language, password_hash FROM cf_users LEFT JOIN cf_user_settings ON id=user_id WHERE email = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();

            if ($result && password_verify($password, $result['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $result['id'];
                $_SESSION['language'] = $result['language'] ?? 'en-US';
                $query = "UPDATE cf_users SET updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param('s', $result['id']);
                $stmt->execute();
                header('Location: index.php');
                exit;
            } else {
                $error_message = t('Invalid email or password.');
            }
        } elseif ($action === 'create_account') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $plainPassword = $_POST['password'] ?? '';
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $plainPassword === '') {
                $error_message = t('Please enter a name, a valid email and a password.');
            } else {
                $conn->begin_transaction();
                try {
                    $password = password_hash($plainPassword, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare('INSERT INTO cf_users (name, email, password_hash) VALUES (?, ?, ?)');
                    $stmt->bind_param('sss', $name, $email, $password);
                    $stmt->execute();
                    $new_user_id = (int)$conn->insert_id;
                    ensureUserSettings($conn, $new_user_id, $categories, $macrocategories, $language);
                    if ($new_user_id === 1) $conn->query('UPDATE cf_users SET is_admin = 1 WHERE id = 1');
                    $conn->commit();
                    $_SESSION['registration_success'] = true;
                    header('Location: index.php');
                    exit;
                } catch (mysqli_sql_exception $e) {
                    $conn->rollback();
                    error_log((string)$e);
                    $error_message = $e->getCode() === 1062 ? t('An account with this email already exists.') : t('Account creation failed. Please try again.');
                }
            }
        }


    }
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars($language); ?>">
    <head>
        <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css">
    <script src="res/theme.js?v=1.5.3"></script>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="res/favicon.svg?v=1.5.3" sizes="any" type="image/svg+xml">
        <link rel="stylesheet" href="res/style.css?v=1.5.3">
        <title><?= t('Login'); ?></title>
    </head>
    <body>

        <form method="POST" class="small-form">
        <?php if (isset($success_message)): ?>
            <div class="success"><?= $success_message; ?></div>
        <?php elseif (isset($error_message)): ?>
            <div class="error"><?= $error_message; ?></div>
        <?php endif; ?>
        <div class="logo-home"><?php include 'res/logo_black.html'; ?></div>

            <label for="email">Email:</label>
            <input type="email" name="email" required>
            <label for="password"><?= t('Password'); ?>:</label>
            <input type="password" name="password" required>
            <button class="w100" type="submit" name="action" value="login"><?= t('Login'); ?></button>
            <a class="recovery-link" href="recover.php"><?= t('Forgot password?'); ?></a>
        <nav class="login-languages" aria-label="<?= t('Language'); ?>"><?php foreach(supportedLanguages() as $code=>$name): ?><a href="?lang=<?= $code; ?>" <?= $code===$language?'aria-current="page"':''; ?>><?= $name; ?></a> <?php endforeach; ?></nav>
        </form>
        <form method="POST" class="small-form">
            <h2><?= t('Create Account'); ?></h2>
            <label for="name"><?= t('Name'); ?>:</label>
            <input type="text" name="name" required>
            <label for="email">Email:</label>
            <input type="email" name="email" required>
            <label for="password"><?= t('Password'); ?>:</label>
            <input type="password" name="password" required>
            <button class="w100" type="submit" name="action" value="create_account"><?= t('Create Account'); ?></button>
        </form>

    <?php printFooter(); ?>
</body>
    </html>
    <?php
    exit;
}

// Determine if the form is for expenses or incomes
$is_income_form = ($_POST['type'] ?? '') === 'Income' || (isset($_GET['form']) && $_GET['form'] === 'income');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $type = $_POST['type'] ?? '';
    $amount = $_POST['amount'] ?? '';
    $currency = $_POST['currency'] ?? '';
    $category = trim($_POST['category'] ?? '');
    $macrocategory = trim($_POST['macrocategory'] ?? '');
    $source = trim($_POST['source'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $rateError='';
    try { [$specificRate,$rateCurrency,$rateDate]=newEntryRate($currency,$currency_preferences,($_POST['rate_override'] ?? '1')==='1' ? ($_POST['exchange_rate'] ?? '') : ''); }
    catch (Throwable $e) { error_log((string)$e); $rateError=t($e->getMessage()); $specificRate=false; }
    if ($specificRate === false || !validDate($date) || $description === '' || !in_array($type, ['Expense', 'Income'], true)
        || !is_numeric($amount) || (float)$amount <= 0 || !isset($exchange_rates[$currency])) {
        $error_message = $rateError ?: t('Please check the date, amount, currency and description.');
    } else {
        $stmt = $conn->prepare('INSERT INTO cf_entries (user_id, description, type, date, amount, currency, category, macrocategory, source, note, exchange_rate, rate_currency, exchange_rate_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssdsssssdss', $_SESSION['user_id'], $description, $type, $date, $amount, $currency, $category, $macrocategory, $source, $note, $specificRate, $rateCurrency, $rateDate);
        $stmt->execute();
        $_SESSION['entry_success'] = true;
        $_SESSION['entry_budget_date'] = $date;
        header('Location: index.php' . ($type === 'Income' ? '?form=income' : ''));
        exit;
    }
}
if (!empty($_SESSION['entry_success'])) {
    $success_message = t('Inserted correctly');
    unset($_SESSION['entry_success']);
}
$goalDate = $_SESSION['entry_budget_date'] ?? date('Y-m-d');
unset($_SESSION['entry_budget_date']);
$goalStatuses = categoryBudgetStatuses($conn, (int)$_SESSION['user_id'], $goalDate, $month_start_day, decodeCategoryBudgets($user_settings['category_budgets'] ?? ''));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($language); ?>">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css">
    <script src="res/theme.js?v=1.5.3"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="res/favicon.svg?v=1.5.3" sizes="any" type="image/svg+xml">
    <link rel="stylesheet" href="res/style.css?v=1.5.3">
    <title><?= t('Add Transaction'); ?></title>
</head>
<body class="<?= $is_income_form ? 'starry' : 'munch'; ?>">
    <?php printMenu(); ?>
    
    <h1><?= t('Add Transaction'); ?></h1>
    <?php foreach ($goalStatuses as $goalStatus): ?>
    <div class="budget-warning" role="alert" >
        <button type="button" class="dismiss-warning" aria-label="<?= t('Dismiss'); ?>" onclick="this.parentElement.remove()">×</button>
        <strong><?= htmlspecialchars($goalStatus['category']); ?>: <?= t('Monthly spending limit exceeded'); ?></strong><br>
        <?= formatDate($goalStatus['start'], 2); ?> – <?= formatDate($goalStatus['end'], 2); ?><br>
        <?= t('Spent'); ?>: <?= $number_formatter->formatCurrency($goalStatus['spent'], $_SESSION['currency']); ?> / <?= $number_formatter->formatCurrency($goalStatus['limit'], $_SESSION['currency']); ?>
    </div>
    <?php endforeach; ?>

    <form method="POST" class="small-form">
        <?php if (isset($success_message)): ?>
            <div class="success"><?= $success_message; ?></div>
        <?php elseif (isset($error_message)): ?>
            <div class="error"><?= $error_message; ?></div>
        <?php endif; ?>

        <div class="toggle-container" style="width: 100%; margin: 0 0 20px 0;">
            <a href="#" class="toggle-button expense <?= !$is_income_form ? 'active' : ''; ?>" data-type="Expense" onclick="switchType(event, 'Expense')">
                <?= t('Expense'); ?>
            </a>
            <a href="#" class="toggle-button income <?= $is_income_form ? 'active' : ''; ?>" data-type="Income" onclick="switchType(event, 'Income')">
                <?= t('Income'); ?>
            </a>
        </div>
        <input type="hidden" name="type" id="typeInput" value="<?= $is_income_form ? 'Income' : 'Expense'; ?>">

        <label for="date"><?= t('Date'); ?>:</label>
        <input type="date" name="date" value="<?= htmlspecialchars($_POST['date'] ?? date('Y-m-d')); ?>" required>

        <label for="category"><?= t('Category'); ?>:</label>
        <select name="category" required>
            <?php foreach ($categories as $category): ?>
                <option value="<?= htmlspecialchars($category); ?>" <?= ($_POST['category'] ?? '')===$category ? 'selected' : ''; ?>><?= htmlspecialchars($category); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="macrocategory"><?= t('Macrocategory'); ?>:</label>
        <select name="macrocategory" required>
            <?php foreach ($macrocategories as $macrocategory): ?>
                <option value="<?= htmlspecialchars($macrocategory); ?>" <?= ($_POST['macrocategory'] ?? '')===$macrocategory ? 'selected' : ''; ?>><?= htmlspecialchars($macrocategory); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="description"><?= t('Description'); ?>:</label>
        <input type="text" name="description" value="<?= htmlspecialchars($_POST['description'] ?? ''); ?>" required>

        <div class="add-amount-row">
            <div class="amount-field">
                <label for="amount"><?= t('Amount'); ?>:</label>
                <input type="number" step="0.01" min="0.01" name="amount" value="<?= htmlspecialchars($_POST['amount'] ?? ''); ?>" required>
            </div>
            <div class="currency-field">
                <label for="currency"><?= t('Currency'); ?>:</label>
                <select name="currency" required>
                    <?php foreach ($currencies as $currency): ?>
                        <option value="<?= $currency['code']; ?>" <?= $currency['code']==($_POST['currency'] ?? $_SESSION['currency'])?"selected":"" ?> >
                            <?= $currency['code']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <input type="hidden" name="rate_override" id="rate-override" value="<?= ($_POST['rate_override'] ?? '0')==='1' ? '1' : '0'; ?>">
        <div class="entry-rate-row" id="entry-rate-row" hidden>
            <input type="number" id="exchange_rate" name="exchange_rate" value="<?= htmlspecialchars($_POST['exchange_rate'] ?? ''); ?>" step="any" min="0.0000000001" max="9999999999" aria-label="<?= t('Exchange rate'); ?>" placeholder="<?= t('Optional'); ?>">
            <small id="entry-rate-description"></small>
        </div>
        <script>window.entryRateConfig = <?= scriptJson(['currency'=>$_SESSION['currency'], 'language'=>$language, 'rates'=>$exrate,'auto'=>$currency_preferences['auto'],'loading'=>t('Loading exchange rate…'),'failure'=>t('Rates unavailable. Please try again.'),'published'=>t('Rate date')]); ?>;</script>
        <script src="res/entry-rate.js?v=1.5.3" defer></script>
        <label for="source"><?= t('Source'); ?>:</label>
        <input type="text" name="source" value="<?= htmlspecialchars($_POST['source'] ?? ''); ?>">

        <label for="note"><?= t('Note'); ?>:</label>
        <input type="text" name="note" value="<?= htmlspecialchars($_POST['note'] ?? ''); ?>">

        <button class="w100" type="submit" id="submitButton"><?= t($is_income_form ? 'Add Income' : 'Add Expense'); ?></button>
    </form>

    <script>
    function switchType(event, type) {
        event.preventDefault();
        
        document.querySelectorAll('.toggle-button').forEach(button => {
            button.classList.toggle('active', button.dataset.type === type);
        });
        document.body.classList.toggle('munch', type === 'Expense');
        document.body.classList.toggle('starry', type === 'Income');

        // Update hidden type input
        document.getElementById('typeInput').value = type;
        
        // Update submit button text
        const submitButton = document.getElementById('submitButton');
        submitButton.textContent = type === 'Income' ? <?= scriptJson(t('Add Income')); ?> : <?= scriptJson(t('Add Expense')); ?>;
    }
    </script>
<?php printFooter(); ?>
</body>
</html>