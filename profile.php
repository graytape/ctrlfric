<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require 'conf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Fetch current user data
$query = "SELECT * FROM Users LEFT JOIN User_settings ON Users.id = User_settings.user_id WHERE Users.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$success_message = '';
$error_message = '';

// Handle password change
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (password_verify($current_password, $user['password_hash'])) {
        if ($new_password === $confirm_password) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $query = "UPDATE Users SET password_hash = ? WHERE id = ?";
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

// Handle language preference
if (isset($_POST['update_language'])) {
    $language = $_POST['language'];
    
    // Insert or update user settings
    $query = "INSERT INTO User_settings (user_id, language) 
              VALUES (?, ?) 
              ON DUPLICATE KEY UPDATE language = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('iss', $_SESSION['user_id'], $language, $language);
    
    if ($stmt->execute()) {
        $success_message      = t('Language preference updated');
        $user['language']     = $language;
        $_SESSION['language'] = $language;
    } else {
        $error_message = t('Error updating language preference');
    }
}

// Handle currency preference
if (isset($_POST['update_currency'])) {
    $currency = $_POST['currency'];
    
    // Insert or update user settings
    $query = "INSERT INTO User_settings (user_id, currency) 
              VALUES (?, ?) 
              ON DUPLICATE KEY UPDATE currency = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('iss', $_SESSION['user_id'], $currency, $currency);
    
    if ($stmt->execute()) {
        $success_message      = t('Currency preference updated');
        $user['currency']     = $currency;
        $_SESSION['currency'] = $currency;
    } else {
        $error_message = t('Error updating currency preference');
    }
}

// Handle categories update
if (isset($_POST['update_categories'])) {
    $categories = json_encode($_POST['categories']);
    $macrocategories = json_encode($_POST['macrocategories']);
    
    $query = "INSERT INTO User_settings (user_id, categories, macrocategories) 
              VALUES (?, ?, ?) 
              ON DUPLICATE KEY UPDATE categories = ?, macrocategories = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('issss', $_SESSION['user_id'], $categories, $macrocategories, $categories, $macrocategories);
    
    if ($stmt->execute()) {
        $success_message = t('Categories updated successfully');

        $categories      = $_POST['categories'];
        $macrocategories = $_POST['macrocategories'];

    } else {
        $error_message = t('Error updating categories');
    }
}

// Admin: Handle user management
if ($user['is_admin'] && isset($_POST['admin_action'])) {
    $target_user_id = $_POST['target_user_id'];
    
    switch ($_POST['admin_action']) {
        case 'delete_user':
            $query = "DELETE FROM Users WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('i', $target_user_id);
            if ($stmt->execute()) {
                $success_message = t('User deleted successfully');
            } else {
                $error_message = t('Error deleting user');
            }
            break;
            
        case 'reset_password':
            $new_password = bin2hex(random_bytes(8)); // Generate random password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $query = "UPDATE Users SET password_hash = ? WHERE id = ?";
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
    ob_clean(); // Clear any previous output buffers
    
    $query = "SELECT * FROM Entries WHERE user_id = ? ORDER BY date ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="ctrlfric_backup_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Type', 'Category', 'Macrocategory', 'Description', 'Out', 'In', 'Currency', 'Source', 'Note']);
    
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
            $row['note']
        ]);
    }
    fclose($output);
    exit;
}

// Fetch all users if admin
$all_users = [];
if ($user['is_admin']) {
    $query = "SELECT * FROM Users LEFT JOIN User_settings ON id = user_id WHERE id != ? ORDER BY id DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $all_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

?>

<!DOCTYPE html>
<html lang="<?php echo $user['language'] ?? 'en-US'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('User Profile'); ?></title>
    <link rel="icon" href="res/favicon.svg" sizes="any" type="image/svg+xml">
    <link rel="stylesheet" href="res/style.css?<?= rand(3,300); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
</head>
<body>
    <?php printMenu(); ?>
    
    <div class="profile-content">
        <h1><?php echo t('Profile Settings'); ?></h1>
        
        <?php if ($success_message): ?>
            <div class="success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="flex">

        <!-- Language Preference Section -->
        <section class="profile-section lang">
            <h3><?php echo t('Language Preference'); ?></h3>
            <form method="POST" class="form-group small-form">
                <div>
                    <label for="language"><?php echo t('Select Language'); ?>:</label>
                    <select id="language" name="language">
                        <option value="en-US" <?php echo $language == 'en-US' ? 'selected' : ''; ?>>English</option>
                        <option value="it-IT" <?php echo $language == 'it-IT' ? 'selected' : ''; ?>>Italiano</option>
                        <option value="fr-FR" <?php echo $language == 'fr-FR' ? 'selected' : ''; ?>>Français</option>
                        <!-- Add more languages as needed -->
                    </select>
                </div>
                <button class="w100" type="submit" name="update_language"><?php echo t('Update Language'); ?></button>
            </form>
        </section>


        <!-- Currency Preference Section -->
        <section class="profile-section currency">
            <h3><?php echo t('Currency Preference'); ?></h3>
            <form method="POST" class="form-group small-form">
                <div>
                    <label for="currency"><?php echo t('Select Currency'); ?>:</label>
                    <select id="currency" name="currency" required>
                        <?php foreach ($currencies as $currency): ?>
                            <option value="<?php echo $currency['code']; ?>"
                                <?php echo ($currency['code']==($user['currency']??'EUR') ) ? 'selected' : ''; ?> >
                                <?php echo $currency['code']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="w100" type="submit" name="update_currency"><?php echo t('Update Currency'); ?></button>
            </form>
        </section>


        <!-- Password Change Section -->
            <section class="profile-section pwd">
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
        
        </div>

        <!-- Categories Management Section -->
        <section class="profile-section">
            <h2><?php echo t('Manage Categories'); ?></h2>
            <form method="POST" class="form-group" id="categoriesForm">
                    <div>
                        <label><?php echo t('Categories'); ?>:</label><br>
                        <ul id="categoriesList">
                            <?php 
                            $user_categories = $categories;
                            foreach ($user_categories as $category): 
                            ?>
                                <li class="category-item">
                                    <input type="text" class="profile-category-input" name="categories[]" value="<?php echo htmlspecialchars($category); ?>">
                                    <button type="button" class="remove-category"><i class="fa fa-trash"></i></button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" id="addCategory" class="wauto"><i class="fa fa-plus icon"></i><?php echo t('Add Category'); ?></button>
                    </div>

                    <div>
                        <label><?php echo t('Macrocategories'); ?>:</label><br>
                        <ul id="macrocategoriesList">
                            <?php 
                            $user_macrocategories = $macrocategories;
                            foreach ($user_macrocategories as $macrocategory): 
                            ?>
                                <li class="category-item">
                                    <input type="text" class="profile-category-input" name="macrocategories[]" value="<?php echo htmlspecialchars($macrocategory); ?>">
                                    <button type="button" class="remove-category"><i class="fa fa-trash"></i></button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" id="addMacrocategory" class="wauto"><i class="fa fa-plus icon"></i><?php echo t('Add Macrocategory'); ?></button>
                    </div>
                
                <button type="submit" name="update_categories"><?php echo t('Save Categories'); ?></button>
            </form>
        </section>

        <!-- Data Export Section -->
        <section class="profile-section">
            <h2><?php echo t('Export Data'); ?></h2>
            <form method="POST" class="form-group">
                <button type="submit" class="wauto" name="export_data">
                <i class="fa fa-file fa-2x"></i> <?php echo t('Download CSV'); ?></button>
            </form>
        </section>

        
    </div>


    <!-- Admin Section -->
    <?php if ($user['is_admin']): ?>
    <div class="content">
        <section class="profile-section admin-section">
            <h2><?php echo t('User Management'); ?></h2>
            <table class="dash table">
                <thead>
                    <tr>
                        <th><?php echo t('Name'); ?></th>
                        <th><?php echo t('Email'); ?></th>
                        <th><?php echo t('Language'); ?></th>
                        <th><?php echo t('Currency'); ?></th>
                        <th><?php echo t('Created'); ?></th>
                        <th><?php echo t('Last Access'); ?></th>
                        <th><?php echo t('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_users as $other_user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($other_user['name']); ?></td>
                        <td><?php echo htmlspecialchars($other_user['email']); ?></td>
                        <td><?php echo htmlspecialchars($other_user['language']??""); ?></td>
                        <td><?php echo htmlspecialchars($other_user['currency']??""); ?></td>
                        <td><?php echo formatDate($other_user['created_at'], 2); ?></td>
                        <td><?php echo formatDate($other_user['updated_at'], 2); ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="target_user_id" value="<?php echo $other_user['id']; ?>">
                                <button type="submit" name="admin_action" value="reset_password" 
                                        onclick="return confirm('<?php echo t('Reset password for this user?'); ?>')"><i class="fa fa-key"></i></button>
                                <button type="submit" name="admin_action" value="delete_user" 
                                        onclick="return confirm('<?php echo t('Delete this user?'); ?>')"><i class="fa fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>
    <?php endif; ?>


    <script>

        // enable categories sorting
        $(function() {
          $("#categoriesList").sortable();
          $("#macrocategoriesList").sortable();
        });


        document.getElementById('addCategory').addEventListener('click', function() {
            const container = document.getElementById('categoriesList');
            const div = document.createElement('div');
            div.className = 'category-item';
            div.innerHTML = `
                <input type="text" class="profile-category-input" name="categories[]" value="">
                <button type="button" class="remove-category"><i class="fa fa-trash"></i></button>
            `;
            container.appendChild(div);
        });

        document.getElementById('addMacrocategory').addEventListener('click', function() {
            const container = document.getElementById('macrocategoriesList');
            const div = document.createElement('div');
            div.className = 'category-item';
            div.innerHTML = `
                <input type="text" class="profile-category-input" name="macrocategories[]" value="">
                <button type="button" class="remove-category"><i class="fa fa-trash"></i></button>
            `;
            container.appendChild(div);
        });

        document.addEventListener('click', function(e) {
            if (e.target.closest('.remove-category')) {
                e.target.closest('.category-item').remove();
            }
        });
    </script>
</body>
</html>