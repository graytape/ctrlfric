<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'conf.php';


// logout
if (!empty($_GET['logout']) && $_GET['logout']=="true") {
    session_destroy();
    header('Location: index.php');
}

if (!isset($_SESSION['user_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        // Handle login, account creation, or password reset
        $action = $_POST['action'];
        
        if ($action === 'login') {
            $email = $_POST['email'];
            $password = $_POST['password'];
            // Authenticate user
            $query = "SELECT id, language, password_hash FROM Users LEFT JOIN User_settings ON id=user_id WHERE email = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();

            if ($result && password_verify($password, $result['password_hash'])) {
                $_SESSION['user_id'] = $result['id'];
                $_SESSION['language'] = $result['language'];
                $query = "UPDATE Users SET updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param('s', $result['id']);
                $stmt->execute();
                header('Location: index.php');
                exit;
            } else {
                $error_message = t('Invalid email or password.');
            }
        } elseif ($action === 'create_account') {
            $name = $_POST['name'];
            $email = $_POST['email'];
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            // Create new user
            $query = "INSERT INTO Users (name, email, password_hash) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('sss', $name, $email, $password);

            $new_user_id = $stmt->insert_id;

            // send email
            if ($stmt->execute()) {
                $success_message = t('Account created successfully');
                mail($email, t('Account created successfully - Ctrl Fric'), t('Your account on Ctrl Fric has been created successfully'));
            } else {
                $error_message = t('Error');
            }
            
            // set first user as admin
            if ($new_user_id == "1") {
                $stmt = $conn->prepare("UPDATE Users SET is_admin = 1 WHERE id = 1");
                $stmt->execute();
            }

            // set user settings
            $categories      = json_encode($categories);
            $macrocategories = json_encode($macrocategories);
            
            $query = "INSERT INTO User_settings (user_id, categories, macrocategories, language, currency) 
                      VALUES (?, ?, ?, ?, ?) 
                      ON DUPLICATE KEY UPDATE categories = ?, macrocategories = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('issssss', $new_user_id, $categories, $macrocategories, $default['language'], $default['currency'], $categories, $macrocategories);
            $stmt->execute();

        } elseif ($action === 'reset_password') {
            $email = $_POST['email'];
            // Placeholder for password reset functionality
            $success_message = t('Password reset instructions sent to your email.');
        } 

    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="favicon.svg" sizes="any" type="image/svg+xml">
        <link rel="stylesheet" href="res/style.css?<?= rand(3,300); ?>">
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
            <label for="password">Password:</label>
            <input type="password" name="password" required>
            <button class="w100" type="submit" name="action" value="login"><?= t('Login'); ?></button>
        </form>
        <form method="POST" class="small-form">
            <h2><?= t('Create Account'); ?></h2>
            <label for="name">Name:</label>
            <input type="text" name="name" required>
            <label for="email">Email:</label>
            <input type="email" name="email" required>
            <label for="password">Password:</label>
            <input type="password" name="password" required>
            <button class="w100" type="submit" name="action" value="create_account"><?= t('Create Account'); ?></button>
        </form>
        <form method="POST" class="small-form hidden">
            <h2><?= t('Reset Password'); ?></h2>
            <label for="email">Email:</label>
            <input type="email" name="email" required>
            <button class="w100" type="submit" name="action" value="reset_password"><?= t('Reset Password'); ?></button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// Determine if the form is for expenses or incomes
$is_income_form = isset($_GET['form']) && $_GET['form'] === 'income';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Insert entry into database
        $date          = $_POST['date'];
        $description   = $_POST['description'];
        $type          = $_POST['type'];
        $amount        = $_POST['amount'];
        $currency      = $_POST['currency'];
        $category      = $_POST['category'];
        $macrocategory = $_POST['macrocategory'];
        $source        = $_POST['source'];
        $note          = $_POST['note'];

        $query = "INSERT INTO Entries (user_id, description, type, date, amount, currency, category, macrocategory, source, note) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('isssdsssss', $_SESSION['user_id'], $description, $type, $date, $amount, $currency, $category, $macrocategory, $source, $note);
        $stmt->execute();

        if ($stmt) {
            $success_message = t("Inserted correctly");
        } else {
            $error_message = t("Problem!!");
        }
        
        //header('Location: index.php');
        //exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="favicon.svg" sizes="any" type="image/svg+xml">
    <link rel="stylesheet" href="res/style.css">
    <title><?= t('Add Transaction'); ?></title>
</head>
<body class="munch">
    <?php printMenu(); ?>
    
    <h1><?= t('Add Transaction'); ?></h1>

    <form method="POST" class="small-form">
        <?php if (isset($success_message)): ?>
            <div class="success"><?= $success_message; ?></div>
        <?php elseif (isset($error_message)): ?>
            <div class="error"><?= $error_message; ?></div>
        <?php endif; ?>

        <div class="toggle-container" style="width: 100%; margin: 0 0 20px 0;">
            <a href="#" class="toggle-button expense active" data-type="Expense" onclick="switchType(event, 'Expense')">
                <?= t('Expense'); ?>
            </a>
            <a href="#" class="toggle-button income" data-type="Income" onclick="switchType(event, 'Income')">
                <?= t('Income'); ?>
            </a>
        </div>
        <input type="hidden" name="type" id="typeInput" value="Expense">

        <label for="date"><?= t('Date'); ?>:</label>
        <input type="date" name="date" value="<?= date('Y-m-d'); ?>" required>

        <label for="category"><?= t('Category'); ?>:</label>
        <select name="category" required>
            <?php foreach ($categories as $category): ?>
                <option value="<?= htmlspecialchars($category); ?>"><?= htmlspecialchars($category); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="macrocategory"><?= t('Macrocategory'); ?>:</label>
        <select name="macrocategory" required>
            <?php foreach ($macrocategories as $macrocategory): ?>
                <option value="<?= htmlspecialchars($macrocategory); ?>"><?= htmlspecialchars($macrocategory); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="description"><?= t('Description'); ?>:</label>
        <input type="text" name="description" required>

        <div class="add-amount-row">
            <div class="amount-field">
                <label for="amount"><?= t('Amount'); ?>:</label>
                <input type="number" step="0.01" name="amount" required>
            </div>
            <div class="currency-field">
                <label for="currency"><?= t('Currency'); ?>:</label>
                <select name="currency" required>
                    <?php foreach ($currencies as $currency): ?>
                        <option value="<?= $currency['code']; ?>" <?= $currency['code']==$_SESSION['currency']?"selected":"" ?> >
                            <?= $currency['code']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <label for="source"><?= t('Source'); ?>:</label>
        <input type="text" name="source" value="">

        <label for="note"><?= t('Note'); ?>:</label>
        <input type="text" name="note" value="">

        <button class="w100" type="submit" id="submitButton"><?= t('Add Expense'); ?></button>
    </form>

    <script>
    function switchType(event, type) {
        event.preventDefault();
        
        // Update toggle buttons
        document.querySelectorAll('.toggle-button').forEach(button => {
            button.classList.remove('active');
            document.querySelectorAll('body')[0].classList.remove('munch');
        });
        event.target.classList.add('active');
        if (event.target.classList.contains('expense')) {
            document.querySelectorAll('body')[0].classList.add('munch');
        }
        
        // Update hidden type input
        document.getElementById('typeInput').value = type;
        
        // Update submit button text
        const submitButton = document.getElementById('submitButton');
        submitButton.textContent = type === 'Income' ? '<?= t('Add Income'); ?>' : '<?= t('Add Expense'); ?>';
    }
    </script>
</body>
</html>