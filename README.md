# Ctrl-Fric

<img src="./res/favicon.svg" alt="Ctrl-Fric logo" width="200"/>

A simple and intuitive tool for tracking personal expenses and managing your savings.

## Because Chaos Has Its Limits (While Order Doesn't)

In a world that feels increasingly out of control, there's something beautifully rebellious about keeping track of everything. While the economy fluctuates and global events swirl around us, here's one corner of existence you can master with obsessive precision.

*Ctrl-Fric* - While everything else may be falling apart, at least your spreadsheet will be perfect.

### Features

- **Self-Hostable Financial Manager**: Own your own data
- **Multiple Users and Accounts**: No backend control panel. Impose your obsessions only on yourself
- **Add Expenses & Income**: Easily record your financial transactions
- **Dashboard**: View comprehensive statistics and insights through interactive tabs
- **Profile Management**: Customize your categories and personal settings
- **Data Export**: Download your financial data for external analysis

### Installation

- Import `schema.sql` into your MySQL or MariaDB database
- Configure your database access in `db.conf.example.php` and rename it to `db.conf.php`
- The first registered user will be the admin

### Migration

Back up your database, replace the application files while keeping `db.conf.php`, then open `migrate.php` in your browser and sign in as an administrator. The migration preserves existing data and can be run again safely.

### Changelog

- **1.5.3**: English source comments, migration page and concise README
- **1.5.2**: Compact search aligned with the date filters
- **1.5.1**: Checkboxes before dates and full-width advanced filters
- **1.5.0**: Advanced search, bulk actions and editable automatic exchange rates
