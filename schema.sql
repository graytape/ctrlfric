-- Fresh installations only. Existing tables and data are preserved.
CREATE TABLE IF NOT EXISTS cf_users (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(255) NOT NULL,
 email VARCHAR(255) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 is_admin TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cf_user_settings (
 user_id INT NOT NULL PRIMARY KEY,
 categories LONGTEXT NULL,
 macrocategories LONGTEXT NULL,
 language VARCHAR(10) NOT NULL DEFAULT 'en-US',
 currency CHAR(3) NOT NULL DEFAULT 'EUR',
 exchange_rate_date DATE NULL,
 exchange_rate DECIMAL(20,10) NULL,
 rate_currency CHAR(3) NULL,
 currency_preferences LONGTEXT NULL,
 category_tag_filter TINYINT(1) NOT NULL DEFAULT 0,
 hover_entry_actions TINYINT(1) NOT NULL DEFAULT 0,
 category_budgets LONGTEXT NULL,
 net_category_expenses TINYINT(1) NOT NULL DEFAULT 0,
 goals_enabled TINYINT(1) NOT NULL DEFAULT 0,
 monthly_budget DECIMAL(14,2) NOT NULL DEFAULT 0,
 month_start_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY (user_id) REFERENCES cf_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cf_entries (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 description TEXT NOT NULL,
 type ENUM('Expense','Income') NOT NULL,
 date DATE NOT NULL,
 amount DECIMAL(14,2) NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'EUR',
 exchange_rate_date DATE NULL,
 exchange_rate DECIMAL(20,10) NULL,
 rate_currency CHAR(3) NULL,
 category VARCHAR(255) NOT NULL DEFAULT '',
 macrocategory VARCHAR(255) NOT NULL DEFAULT '',
 source TEXT NULL,
 note TEXT NULL,
 INDEX user_date (user_id, date),
 FOREIGN KEY (user_id) REFERENCES cf_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cf_app_settings (
 setting_key VARCHAR(80) PRIMARY KEY,
 setting_value LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cf_password_resets (
 user_id INT PRIMARY KEY,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
