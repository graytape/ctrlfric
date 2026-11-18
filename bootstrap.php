<?php
// Load error handling before the database connection, also with older db.conf.php files.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$show_errors = false;
set_exception_handler(function (Throwable $error) {
    global $show_errors;
    error_log((string)$error);
    http_response_code(500);
    $message = $show_errors ? $error->getMessage() : 'Unable to complete the request. Please try again or contact the administrator.';
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'get_data.php') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => $message]);
    } else {
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    }
});
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/db.conf.php';
ini_set('display_errors', !empty($show_errors) ? '1' : '0');
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+00:00'");
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function ensureUserSettings(mysqli $conn, int $uid, array $categories, array $macrocategories, string $language = 'en-US'): void {
    $categoryJson = json_encode($categories, JSON_UNESCAPED_UNICODE);
    $macroJson = json_encode($macrocategories, JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare("INSERT INTO cf_user_settings (user_id, categories, macrocategories, language, currency)
        VALUES (?, ?, ?, ?, 'EUR') ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)");
    $stmt->bind_param('isss', $uid, $categoryJson, $macroJson, $language);
    $stmt->execute();
}

if (!defined('CF_MIGRATION_PAGE')) {
    $tables = array_column($conn->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0);
    $ready = !array_diff(['cf_users','cf_user_settings','cf_entries','cf_app_settings','cf_password_resets'], $tables);
    if ($ready) {
        $cols = array_column($conn->query('SHOW COLUMNS FROM cf_entries')->fetch_all(MYSQLI_ASSOC), 'Field');
        $settingsCols = array_column($conn->query('SHOW COLUMNS FROM cf_user_settings')->fetch_all(MYSQLI_ASSOC), 'Field');
        $ready = !array_diff(['exchange_rate','rate_currency','exchange_rate_date'], $cols) && !array_diff(['currency_preferences','category_tag_filter','hover_entry_actions','category_budgets','net_category_expenses','month_start_day'], $settingsCols);
    }
    if (!$ready) { header('Location: migrate.php'); exit; }
}
