<?php
function migrateDatabase(mysqli $conn): void {
// Rename the legacy tables together. Never merge two installations silently.
$tables = array_column($conn->query('SHOW TABLES')->fetch_all(MYSQLI_NUM), 0);
$renames = [];
foreach (['Users'=>'cf_users', 'User_settings'=>'cf_user_settings', 'Entries'=>'cf_entries'] as $old=>$new) {
    $legacy = array_values(array_filter($tables, static fn($name) => strtolower($name) === strtolower($old)));
    if ($legacy && in_array($new, $tables, true)) throw new RuntimeException('Both legacy and cf_ tables exist. Resolve the duplicate tables before upgrading.');
    if ($legacy) $renames[] = '`'.$legacy[0].'` TO `'.$new.'`';
}
if ($renames) $conn->query('RENAME TABLE '.implode(', ', $renames));
$conn->query("CREATE TABLE IF NOT EXISTS cf_app_settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value LONGTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Legacy user IDs may be UNSIGNED/BIGINT, or the parent table may use MyISAM.
// Recovery tokens have an application-managed lifecycle, independent of parent engine.
$idColumn = $conn->query("SHOW COLUMNS FROM cf_users WHERE Field='id'")->fetch_assoc();
$idType = $idColumn['Type'] ?? '';
if (!preg_match('/^(tinyint|smallint|mediumint|int|bigint)(\([0-9]+\))?( unsigned)?( zerofill)?$/iD', $idType)) {
    throw new RuntimeException('Unsupported cf_users.id type: '.$idType);
}
$conn->query("CREATE TABLE IF NOT EXISTS cf_password_resets (user_id $idType PRIMARY KEY, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$entryColumns = array_column($conn->query('SHOW COLUMNS FROM cf_entries')->fetch_all(MYSQLI_ASSOC), 'Field');
foreach (['exchange_rate_date'=>'DATE NULL', 'exchange_rate'=>'DECIMAL(20,10) NULL', 'rate_currency'=>'CHAR(3) NULL'] as $column=>$definition) {
    if (!in_array($column, $entryColumns, true)) {
        try { $conn->query("ALTER TABLE cf_entries ADD COLUMN `$column` $definition"); }
        catch (mysqli_sql_exception $e) { if ($e->getCode() !== 1060) throw $e; }
    }
}

// Non-destructive, idempotent upgrade. Existing installations need ALTER permission once.
$columns = array_column($conn->query('SHOW COLUMNS FROM cf_user_settings')->fetch_all(MYSQLI_ASSOC), 'Field');
foreach ([
    'currency_preferences' => 'LONGTEXT NULL',
    'category_tag_filter' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'hover_entry_actions' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'category_budgets' => 'LONGTEXT NULL',
    'net_category_expenses' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'goals_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'monthly_budget' => 'DECIMAL(14,2) NOT NULL DEFAULT 0',
    'month_start_day' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1'
] as $column => $definition) {
    if (!in_array($column, $columns, true)) {
        try { $conn->query("ALTER TABLE cf_user_settings ADD COLUMN `$column` $definition"); }
        catch (mysqli_sql_exception $e) { if ($e->getCode() !== 1060) throw $e; }
    }
}

}
