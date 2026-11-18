<?php
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__.'/conf.php';
require_once __DIR__.'/lib/entry-filters.php';
$_SESSION['entries_csrf'] ??= bin2hex(random_bytes(24));


if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Fetch filter dates | default = this month
$filter_start_date = budgetPeriod(date('Y-m-d'), $month_start_day)[0];
$filter_end_date   = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['start_date'], $_GET['end_date'])) {
    $filter_start_date = requestString($_GET,'start_date');
    $filter_end_date = requestString($_GET,'end_date');
    if (!validDate($filter_start_date) || !validDate($filter_end_date) || $filter_start_date > $filter_end_date) {
        $filter_start_date = budgetPeriod(date('Y-m-d'), $month_start_day)[0];
        $filter_end_date = date('Y-m-d');
    }
}


$filter_category=requestString($_GET,'category');
if ($filter_category !== '' && isset($_GET['all_dates'])) {
    $range = $conn->prepare('SELECT MIN(date) AS first_date, MAX(date) AS last_date FROM cf_entries WHERE user_id = ? AND category = ?');
    $range->bind_param('is', $_SESSION['user_id'], $filter_category);
    $range->execute();
    $dates = $range->get_result()->fetch_assoc();
    $filter_start_date = $dates['first_date'] ?? date('Y-m-d');
    $filter_end_date = $dates['last_date'] ?? date('Y-m-d');
}
// The same validated filters serve the initial page and paginated JSON results.
try {$entry_filters=entryFilters($_GET,$filter_start_date,$filter_end_date);}
catch(InvalidArgumentException $e) {$search_error=t($e->getMessage());$entry_filters=entryFilters([], $filter_start_date,$filter_end_date);}
if (isset($_GET['all_dates'])) {$entry_filters['start_date']=$filter_start_date;$entry_filters['end_date']=$filter_end_date;}
[$entry_where,$entry_types,$entry_params]=entryWhere($entry_filters,(int)$_SESSION['user_id']);
$stmt=$conn->prepare('SELECT * FROM cf_entries WHERE '.$entry_where.' ORDER BY '.entryOrder($entry_filters).' LIMIT 21');
$stmt->bind_param($entry_types,...$entry_params);$stmt->execute();$entry_results=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$has_more_entries=count($entry_results)>20;$entries=array_slice($entry_results,0,20);
$filterOptions=['category'=>$categories,'macrocategory'=>$macrocategories,'currency'=>array_keys($currency_catalog)];
$stmt=$conn->prepare('SELECT DISTINCT category,macrocategory,currency FROM cf_entries WHERE user_id=?');$stmt->bind_param('i',$_SESSION['user_id']);$stmt->execute();
foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $optionRow) foreach($filterOptions as $key=>$unused) if(is_string($optionRow[$key]??null)&&$optionRow[$key]!==''&&!in_array($optionRow[$key],$filterOptions[$key],true)) $filterOptions[$key][]=$optionRow[$key];

// Fetch current balance
$query = "SELECT 
    (SELECT SUM(".generateCurrencyCases().") FROM cf_entries WHERE user_id = ? AND type = 'Income' AND date BETWEEN ? AND ?) as total_income,
    (SELECT SUM(".generateCurrencyCases().") FROM cf_entries WHERE user_id = ? AND type = 'Expense' AND date BETWEEN ? AND ?) as total_expenses";
$stmt = $conn->prepare($query);
$stmt->bind_param('ississ', $_SESSION['user_id'], $filter_start_date, $filter_end_date, $_SESSION['user_id'], $filter_start_date, $filter_end_date);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
$total_income = $totals['total_income'] ?? 0;
$total_expenses = $totals['total_expenses'] ?? 0;
$current_balance = $total_income - $total_expenses;

if (isset($_GET['all_dates']) || ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['start_date'], $_GET['end_date']))) {
    $filter_start_date_minimum = $filter_start_date;
    
} else {
    $filter_start_date_minimum = monthAnchor((new DateTimeImmutable($filter_start_date))->modify('first day of this month')->modify('-6 months'), $month_start_day)->format('Y-m-d');
    
}


// Fetch timespan summary expenses (savings graph)
$query = "SELECT ".periodMonthSql()." as month, 
                SUM(CASE WHEN type = 'Expense' THEN ".generateCurrencyCases()." ELSE 0 END) as expenses,
                SUM(CASE WHEN type = 'Income' THEN ".generateCurrencyCases()." ELSE 0 END) as income,
                SUM(CASE WHEN type = 'Expense' THEN ".generateCurrencyCases()." * -1 ELSE ".generateCurrencyCases()." END) as total
          FROM (
                SELECT * FROM cf_entries 
                WHERE user_id = ? AND type = 'Income' AND date BETWEEN ? AND ? 
                UNION ALL 
                SELECT * FROM cf_entries 
                WHERE user_id = ? AND type = 'Expense' AND date BETWEEN ? AND ? 
          ) as combined 
          GROUP BY month 
          ORDER BY month";
$stmt = $conn->prepare($query);
$stmt->bind_param('ississ', $_SESSION['user_id'], $filter_start_date_minimum, $filter_end_date, $_SESSION['user_id'], $filter_start_date_minimum, $filter_end_date);
$stmt->execute();
$timespan_summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$max_timespan_summary = !empty($timespan_summary) ? max([max(array_column($timespan_summary, 'expenses')), max(array_column($timespan_summary, 'income'))]) : 0;


// Fetch monthly summary expenses (monthly summary)
$query = "SELECT ".periodMonthSql()." as month, 
                SUM(CASE WHEN type = 'Expense' THEN ".generateCurrencyCases()." ELSE 0 END) as expenses,
                SUM(CASE WHEN type = 'Income' THEN ".generateCurrencyCases()." ELSE 0 END) as income,
                SUM(CASE WHEN type = 'Expense' THEN ".generateCurrencyCases()." * -1 ELSE ".generateCurrencyCases()." END) as total
          FROM (
                SELECT * FROM cf_entries 
                WHERE user_id = ? AND type = 'Income' AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 12 MONTH)
                UNION ALL 
                SELECT * FROM cf_entries 
                WHERE user_id = ? AND type = 'Expense' AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 12 MONTH)
          ) as combined 
          GROUP BY month 
          ORDER BY month";
$stmt = $conn->prepare($query);
$stmt->bind_param('ii', $_SESSION['user_id'], $_SESSION['user_id']);
$stmt->execute();
$monthly_summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$max_monthly_summary = !empty($monthly_summary) ? max([max(array_column($monthly_summary, 'expenses')), max(array_column($monthly_summary, 'income'))]) : 0;


// Fetch yearly summary expenses (yearly summary)
$query = "SELECT DATE_FORMAT(date, '%Y') as year, 
                SUM(CASE WHEN type = 'Expense' THEN ".generateCurrencyCases()." ELSE 0 END) as expenses,
                SUM(CASE WHEN type = 'Income' THEN ".generateCurrencyCases()." ELSE 0 END) as income,
                SUM(CASE WHEN type = 'Expense' THEN ".generateCurrencyCases()." * -1 ELSE ".generateCurrencyCases()." END) as total
          FROM (
                SELECT * FROM cf_entries 
                WHERE user_id = ? AND type = 'Income' AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 10 YEAR)
                UNION ALL 
                SELECT * FROM cf_entries 
                WHERE user_id = ? AND type = 'Expense' AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 10 YEAR)
          ) as combined 
          GROUP BY year 
          ORDER BY year";
$stmt = $conn->prepare($query);
$stmt->bind_param('ii', $_SESSION['user_id'], $_SESSION['user_id']);
$stmt->execute();
$yearly_summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$max_yearly_summary = !empty($yearly_summary) ? max([max(array_column($yearly_summary, 'expenses')), max(array_column($yearly_summary, 'income'))]) : 0;


// Fetch expenses categories for timespan
$query = "SELECT category, ".categoryExpenseSql()." as total FROM cf_entries WHERE user_id = ? AND date BETWEEN ? AND ? GROUP BY category HAVING total > 0 ORDER BY total DESC LIMIT 10 ";
$stmt = $conn->prepare($query);
$stmt->bind_param('iss', $_SESSION['user_id'], $filter_start_date, $filter_end_date);
$stmt->execute();
$category_totals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Allocate each category's net proportionally to its expense macrocategories.
if (!empty($user_settings['net_category_expenses'])) {
    $query = "SELECT e.macrocategory, SUM(e.gross * c.net / NULLIF(c.gross, 0)) AS total
        FROM (SELECT category, macrocategory, SUM(".generateCurrencyCases().") AS gross
              FROM cf_entries WHERE user_id = ? AND type = 'Expense' AND date BETWEEN ? AND ?
              GROUP BY category, macrocategory) AS e
        JOIN (SELECT category, ".categoryExpenseSql()." AS net,
                     SUM(CASE WHEN type = 'Expense' THEN ".generateCurrencyCases()." ELSE 0 END) AS gross
              FROM cf_entries WHERE user_id = ? AND date BETWEEN ? AND ? GROUP BY category) AS c
        ON e.category = c.category
        GROUP BY e.macrocategory HAVING total > 0 ORDER BY total DESC LIMIT 10";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ississ', $_SESSION['user_id'], $filter_start_date, $filter_end_date, $_SESSION['user_id'], $filter_start_date, $filter_end_date);
} else {
    $query = "SELECT macrocategory, SUM(".generateCurrencyCases().") AS total FROM cf_entries WHERE user_id = ? AND type = 'Expense' AND date BETWEEN ? AND ? GROUP BY macrocategory ORDER BY total DESC LIMIT 10";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('iss', $_SESSION['user_id'], $filter_start_date, $filter_end_date);
}
$stmt->execute();
$macrocategory_totals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch income categories for timespan period (income by categories)
$query = "SELECT category, SUM(".generateCurrencyCases().") as total 
          FROM cf_entries 
          WHERE user_id = ? 
          AND type = 'Income' 
          AND date BETWEEN ? AND ? 
          GROUP BY category 
          ORDER BY total DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param('iss', $_SESSION['user_id'], $filter_start_date, $filter_end_date);
$stmt->execute();
$income_categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);



// Category line colors
$colors = [
    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
    '#9966FF', '#FF9F40', '#FF6384', '#36A2EB',
    '#4BC0C0', '#9966FF', '#FF9F40', '#FFCE56'
];

/////////////////////////////////////////////////////////////////////////
// Expenses categories per month in the last 10 years
$query = "SELECT 
    ".periodMonthSql()." as month,
    category,
    ".categoryExpenseSql()." as total
FROM cf_entries 
WHERE user_id = ? 
    AND date BETWEEN ? AND ?
GROUP BY ".periodMonthSql().", category
HAVING SUM(CASE WHEN type = 'Expense' THEN 1 ELSE 0 END) > 0
ORDER BY month, category";

$stmt = $conn->prepare($query);
$stmt->bind_param('iss', $_SESSION['user_id'], $filter_start_date_minimum, $filter_end_date);
$stmt->execute();
$result = $stmt->get_result();

// Organize data for chartJS
$chart_data = [];
$chart_categories = [];
$chart_months = [];
while ($row = $result->fetch_assoc()) {
    if (!in_array($row['category'], $chart_categories)) {
        $chart_categories[] = $row['category'];
    }
    if (!in_array($row['month'], $chart_months)) {
        $chart_months[] = $row['month'];
    }
    $chart_data[$row['month']][$row['category']] = $row['total'];
}
// Ensure all categories have a value (included 0)
foreach ($chart_months as $month) {
    foreach ($chart_categories as $category) {
        if (!isset($chart_data[$month][$category])) {
            $chart_data[$month][$category] = 0;
        }
    }
}
// Create a dataset for each category
$categorySums = [];
foreach ($chart_categories as $category) {
    $sum = 0;
    foreach ($chart_months as $month) {
        $sum += $chart_data[$month][$category];
    }
    $categorySums[$category] = $sum;
}

// Order categories (DESC)
arsort($categorySums);

// Slice most common categories
$topCategories = array_slice(array_keys($categorySums), 0, 8);

// Create a dataset for the top eight categories
$Expenses_bycat_chartData = [
    'labels' => $chart_months,
    'datasets' => []
];
foreach ($topCategories as $index => $category) {
    $categoryData = [];
    foreach ($chart_months as $month) {
        $categoryData[] = $chart_data[$month][$category];
    }
    
    $Expenses_bycat_chartData['datasets'][] = [
        'label'           => $category,
        'data'            => $categoryData,
        'borderColor'     => $colors[$index % count($colors)],
        'backgroundColor' => 'transparent',
        'tension'         => 0.4,
        'borderWidth'     => 2
    ];
}

fillMissingLabels($Expenses_bycat_chartData);


////////////////////////////////////////////////////////////////////
// Income by category in the last ten years
// Get categories per month in the last 10 years
$query = "SELECT 
    ".periodMonthSql()." as month,
    category,
    SUM(".generateCurrencyCases().") as total
FROM cf_entries 
WHERE user_id = ? 
    AND type = 'Income'
    AND date BETWEEN ? AND ?
GROUP BY ".periodMonthSql().", category
ORDER BY month, category";

$stmt = $conn->prepare($query);
$stmt->bind_param('iss', $_SESSION['user_id'], $filter_start_date_minimum, $filter_end_date);
$stmt->execute();
$result = $stmt->get_result();

// Organize data for chartJS
$chart_data = [];
$chart_categories = [];
$chart_months = [];
while ($row = $result->fetch_assoc()) {
    if (!in_array($row['category'], $chart_categories)) {
        $chart_categories[] = $row['category'];
    }
    if (!in_array($row['month'], $chart_months)) {
        $chart_months[] = $row['month'];
    }
    $chart_data[$row['month']][$row['category']] = $row['total'];
}
// Ensure all categories have a value (included 0)
foreach ($chart_months as $month) {
    foreach ($chart_categories as $category) {
        if (!isset($chart_data[$month][$category])) {
            $chart_data[$month][$category] = 0;
        }
    }
}

// Create a dataset for each category
$categorySums = [];
foreach ($chart_categories as $category) {
    $sum = 0;
    foreach ($chart_months as $month) {
        $sum += $chart_data[$month][$category];
    }
    $categorySums[$category] = $sum;
}

// Order categories (DESC)
arsort($categorySums);

// Slice most common categories
$topCategories = array_slice(array_keys($categorySums), 0, 8);

// Create a dataset for the top eight categories
$Incomes_bycat_chartData = [
    'labels' => $chart_months,
    'datasets' => []
];
foreach ($topCategories as $index => $category) {
    $categoryData = [];
    foreach ($chart_months as $month) {
        $categoryData[] = $chart_data[$month][$category];
    }
    
    $Incomes_bycat_chartData['datasets'][] = [
        'label'           => $category,
        'data'            => $categoryData,
        'borderColor'     => $colors[$index % count($colors)],
        'backgroundColor' => 'transparent',
        'tension'         => 0.4,
        'borderWidth'     => 2
    ];
}

fillMissingLabels($Incomes_bycat_chartData);




?>


<!DOCTYPE html>
<html lang="<?= htmlspecialchars($language); ?>">
<head>
    <meta charset="UTF-8">
    <script src="res/theme.js?v=1.5.3"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="res/style.css?v=1.5.3">
    <link rel="icon" href="res/favicon.svg?v=1.5.3" sizes="any" type="image/svg+xml">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css">
    <link rel="stylesheet" href="res/vendor/select2.min.css"><script src="res/vendor/jquery.min.js"></script><script src="res/vendor/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>

    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>

    <title><?= t('View Expenses and Incomes'); ?></title>
</head>
<body class="<?= !empty($user_settings['hover_entry_actions']) ? 'hover-entry-actions' : ''; ?>" data-category-filter="<?= !empty($user_settings['category_tag_filter']) ? '1' : '0'; ?>">
    <?php printMenu(); ?>
    <h1><?= t('Dashboard'); ?></h1>


    <div class="dashboard content">

        <!-- Dashboard Top Bar -->
        <div class="dash-topbar">

            <!-- balance -->
            <div class="balance-summary">    
                <b><?= ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['start_date'], $_GET['end_date'])) ? t('Timespan') : t('Current month'); ?></b>

                <div class="mini-amounts">
                    <span class="income-amount">+<?= $number_formatter->formatCurrency($total_income, $_SESSION['currency']); ?></span>
                    <span class="expense-amount">-<?= $number_formatter->formatCurrency($total_expenses, $_SESSION['currency']); ?></span>
                </div>
                <div class="current-balance <?= ($current_balance)>0 ? "positive" : "negative"; ?>">
                    <?= $number_formatter->formatCurrency($current_balance, $_SESSION['currency']); ?>
                </div>
            </div>

            <div class="dashboard-controls">
                <!-- timespan -->
                <form method="GET" class="filter-group">
                    <?php foreach($entry_filters as $key=>$value): if(in_array($key,['category','start_date','end_date'],true)) continue; ?><input type="hidden" name="<?= $key; ?>" value="<?= htmlspecialchars($value); ?>"><?php endforeach; ?>
                    <input type="hidden" name="category" value="<?= htmlspecialchars($filter_category); ?>"><label for="start_date"><?= t('Start Date'); ?>:</label>
                    <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($filter_start_date); ?>" required>
                    <label for="end_date"><?= t('End Date'); ?>:</label>
                    <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($filter_end_date); ?>" required>
                    <button type="submit"><?= t('Apply'); ?></button>
                </form>

                <form method="GET" id="transaction-search">
                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($filter_start_date); ?>"><input type="hidden" name="end_date" value="<?= htmlspecialchars($filter_end_date); ?>">
                    <div class="search-container">
                        <input type="search" name="query" id="searchInput" value="<?= htmlspecialchars($entry_filters['query']); ?>" placeholder="<?= t('Search transactions...'); ?>">
                        <div class="search-split"><button id="searchButton" type="submit"><i class="fa-solid fa-magnifying-glass icon"></i><?= t('Search'); ?></button><button type="button" id="advanced-toggle" aria-expanded="false" aria-controls="advanced-search" title="<?= t('Advanced search'); ?>" aria-label="<?= t('Advanced search'); ?>"><i class="fa-solid fa-caret-down" aria-hidden="true"></i></button></div>
                    </div>

                </form>
                <?php if(isset($search_error)): ?><p class="error"><?= htmlspecialchars($search_error); ?></p><?php endif; ?>
            </div>

                    <div id="advanced-search" hidden>
                        <div class="date-presets"><?php foreach(['last-month'=>'Last month','week'=>'1 week','month'=>'1 month','six-months'=>'6 months','year'=>'1 year'] as $key=>$label): ?><button type="button" data-period="<?= $key; ?>"><?= t($label); ?></button><?php endforeach; ?></div>
                        <div class="advanced-grid">
                        <?php foreach(['category'=>'Category','macrocategory'=>'Macrocategory','currency'=>'Currency'] as $key=>$label): ?><label><?= t($label); ?><select form="transaction-search" class="advanced-select" name="<?= $key; ?>"><option value=""><?= t('All'); ?></option><?php foreach($filterOptions[$key] as $value): ?><option value="<?= htmlspecialchars($value); ?>" <?= $entry_filters[$key]===$value?'selected':''; ?>><?= htmlspecialchars($value); ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
                        <label><?= t('Type'); ?><select form="transaction-search" name="type"><option value=""><?= t('All'); ?></option><option value="Expense" <?= $entry_filters['type']==='Expense'?'selected':''; ?>><?= t('Expense'); ?></option><option value="Income" <?= $entry_filters['type']==='Income'?'selected':''; ?>><?= t('Income'); ?></option></select></label>
                        <label><?= t('Amount greater than'); ?><input form="transaction-search" name="amount_min" type="number" step="any" min="0" value="<?= htmlspecialchars($entry_filters['amount_min']); ?>"></label>
                        <label><?= t('Amount less than'); ?><input form="transaction-search" name="amount_max" type="number" step="any" min="0" value="<?= htmlspecialchars($entry_filters['amount_max']); ?>"></label>
                        <label><?= t('Compare amounts in'); ?><select form="transaction-search" name="amount_mode"><option value="account"><?= htmlspecialchars($_SESSION['currency']); ?></option><option value="original" <?= $entry_filters['amount_mode']==='original'?'selected':''; ?>><?= t('Original currency'); ?></option></select></label>
                        <label><?= t('Source'); ?><input form="transaction-search" name="source" value="<?= htmlspecialchars($entry_filters['source']); ?>"></label>
                        <label><?= t('Sort'); ?><select form="transaction-search" name="sort"><?php foreach([''=>'Newest first','oldest'=>'Oldest first','amount_desc'=>'Largest amount first','amount_asc'=>'Smallest amount first'] as $value=>$label): ?><option value="<?= $value; ?>" <?= $entry_filters['sort']===$value?'selected':''; ?>><?= t($label); ?></option><?php endforeach; ?></select></label>
                        </div>
                        <label class="check-label"><input form="transaction-search" type="checkbox" name="has_note" value="1" <?= $entry_filters['has_note']==='1'?'checked':''; ?>><?= t('Only entries with notes'); ?></label>
                        <small><?= t('Filters apply to the transaction list.'); ?></small> <a href="dashboard.php"><?= t('Clear filter'); ?></a>
                    </div>
        </div>


        <?php if ($filter_category !== ''): ?>
        <p class="active-category-filter"><?= t('Category'); ?>: <strong><?= htmlspecialchars($filter_category); ?></strong> · <a href="dashboard.php"><?= t('Clear filter'); ?></a></p>
        <?php endif; ?>
        <!-- Left: Recent Transactions -->
        <div class="dash transactions"><div class="transactions-container">
                <table class="dash table">
                    <thead>
                        <tr>
                            <th><?= t('Date'); ?></th>
                            <th><?= t('Category'); ?></th>
                            <th><?= t('Description'); ?></th>
                            <th><?= t('Incomes'); ?></th>
                            <th><?= t('Expenses'); ?></th>
                            <th><?= t('Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="transactions-table tbody" data-offset="<?= count($entries);?>">
                        <?php foreach ($entries as $entry): ?>
                            <tr data-amount="<?= htmlspecialchars((string)$entry['amount']); ?>" data-exchange-rate="<?= htmlspecialchars((string)($entry['exchange_rate'] ?? '')); ?>" data-rate-currency="<?= htmlspecialchars($entry['rate_currency'] ?? ''); ?>" data-id="<?= $entry['id']; ?>" data-type="<?= $entry['type'] == 'Expense' ? "Expense" : "Income" ?>">
                                <td>
                                    <span class="display-value"><?= formatDate($entry['date'], 2); ?></span>
                                    <input type="date" class="edit-value hidden date" value="<?= $entry['date']; ?>">
                                    <input type="text" class="edit-value hidden source" value="<?= htmlspecialchars($entry['source']??""); ?>" placeholder="<?= t('Source'); ?>">
                                </td>
                                <td>
                                    <?php if (!empty($user_settings['category_tag_filter'])): ?>
                                    <a class="display-value tag-category" href="dashboard.php?<?= htmlspecialchars(http_build_query(['category' => $entry['category'], 'all_dates' => 1])); ?>"><?= htmlspecialchars((string)($entry['category'] ?? ''), 2); ?></a>
                                    <?php else: ?>
                                    <span class="display-value tag-category"><?= htmlspecialchars((string)($entry['category'] ?? ''), 2); ?></span>
                                    <?php endif; ?>
                                    <span class="display-value tag-macrocategory"><?= htmlspecialchars((string)($entry['macrocategory'] ?? ''), 2); ?></span>
                                    <select class="edit-value hidden category" value="<?= htmlspecialchars((string)($entry['category'] ?? '')); ?>">
                                         <?php foreach ($categories as $category): ?>
                                            <option value="<?= htmlspecialchars($category); ?>" <?= ($category==$entry['category'])?"selected":""; ?>><?= htmlspecialchars($category); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select class="edit-value hidden macrocategory" value="<?= htmlspecialchars((string)($entry['macrocategory'] ?? '')); ?>">
                                         <?php foreach ($macrocategories as $macrocategory): ?>
                                            <option value="<?= htmlspecialchars($macrocategory); ?>" <?= ($macrocategory==$entry['macrocategory'])?"selected":""; ?>><?= htmlspecialchars($macrocategory); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <span class="display-value"><?= htmlspecialchars((string)($entry['description'] ?? '')); ?></span>
                                    <input type="text" class="edit-value hidden description" value="<?= htmlspecialchars((string)($entry['description'] ?? '')); ?>" placeholder="<?= t('Description'); ?>">
                                    <span class="display-value"><?= (!empty($entry['note'])) ? "<i class=\"fa-solid fa-circle-info info-note\" title=\"".htmlspecialchars($entry['note'])."\"></i>":""; ?></span>
                                    <input type="text" class="edit-value hidden note" value="<?= htmlspecialchars($entry['note']??""); ?>" placeholder="<?= t('Note'); ?>">
                                </td>
                                <?php if ($entry['type'] == 'Expense'): ?>
                                    <td></td><td class="expense-amount">
                                            <span class="display-value negative" data-currency="<?= $entry['currency']; ?>">
                                                <?= '-' . $number_formatter->formatCurrency($entry['amount'], $entry['currency']); ?>
                                            </span>
                                            <input type="number" step="0.01" class="edit-value hidden amount" value="<?= $entry['amount']; ?>">
                                            <select class="edit-value hidden currency">
                                                <?php foreach ($currency_catalog as $currency): ?>
                                                    <option value="<?= $currency['code']; ?>" <?= $entry['currency']==$currency['code']?"selected":""; ?> >
                                                        <?= $currency['code']; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                    </td>
                                <?php else: ?>
                                    <td class="income-amount">
                                            <span class="display-value positive" data-currency="<?= $entry['currency']; ?>">
                                                <?= '+' . $number_formatter->formatCurrency($entry['amount'], $entry['currency']); ?>
                                            </span>
                                            <input type="number" step="0.01" class="edit-value hidden amount" value="<?= $entry['amount']; ?>">
                                            <select class="edit-value hidden currency">
                                                <?php foreach ($currency_catalog as $currency): ?>
                                                    <option value="<?= $currency['code']; ?>" <?= $entry['currency']==$currency['code']?"selected":""; ?> >
                                                        <?= $currency['code']; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                    </td><td></td>
                                <?php endif; ?>
                                <td class="actions">
                                    <button class="edit-btn" title="<?= t('Edit'); ?>"><i class="fa-solid fa-pen-to-square"></i></button>
                                    <button class="delete-btn" title="<?= t('Delete'); ?>"><i class="fa-solid fa-trash"></i></button>
                                    <button class="save-btn hidden" title="<?= t('Save'); ?>"><i class="fa-solid fa-check"></i></button>
                                    <button class="cancel-btn hidden" title="<?= t('Cancel'); ?>"><i class="fa-solid fa-xmark"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td><div class="total-transactions" id="total-incomes"></div></td>
                            <td><div class="total-transactions" id="total-expenses"></div></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                <div class="entries-toolbar">
                    <button id="load-more-entries" <?= $has_more_entries?'':'hidden'; ?>><?= t('Load More'); ?></button>
                    <button type="button" id="selection-toggle" class="selection-cog" title="<?= t('Select entries'); ?>" aria-label="<?= t('Select entries'); ?>" aria-pressed="false"><i class="fa-solid fa-gear"></i></button>
                </div>
                <div id="selection-tools" hidden>
                    <span id="selected-count" aria-live="polite">0</span><button type="button" id="select-all"><?= t('Select all'); ?></button><button type="button" id="select-none"><?= t('Clear selection'); ?></button>
                    <select id="bulk-operation" aria-label="<?= t('Actions'); ?>"><?php foreach(['export'=>'Export selected CSV','category'=>'Change category','macrocategory'=>'Change macrocategory','type'=>'Change type','delete'=>'Delete'] as $value=>$label): ?><option value="<?= $value; ?>"><?= t($label); ?></option><?php endforeach; ?></select>
                    <select id="bulk-value" hidden aria-label="<?= t('New value'); ?>"></select><button type="button" id="bulk-apply" disabled><?= t('Apply'); ?></button>
                </div>
        


        <!-- Right: Graphs and Summary -->

            </div><div class="charts-container">

                <!-- Savings -->
                <div class="chart-title"><?= t("Savings"); ?></div>
                <div class="chart">
                    <canvas id="savingsChart" style="max-height: 200px;"></canvas>
                </div>

                <!-- Expenses -->
                <div class="chart-title">
                    <?= t("Expenses by macrocategory"); ?>
                    <button id="toggleCategoryChart" style="float:right; margin-left: 10px; padding: 5px 10px 0px 10px; cursor: pointer;">
                        <?= t("Macro/Micro"); ?>
                    </button>
                </div>
                <div class="chart">
                    <canvas id="categoriesChart" style="max-height: 300px;"></canvas>
                    <canvas id="macroCategoriesChart" style="max-height: 300px; display: none;"></canvas>
                </div>
                <div class="chart-title"><?= t("Expenses"); ?></div>
                <div class="chart">
                    <canvas id="expensesDonutChart" style="max-height: 300px;"></canvas>
                </div>
                
                <!-- Incomes -->
                <div class="chart-title"><?= t("Incomes"); ?></div>
                <div class="chart">
                    <canvas id="incomesChart" style="max-height: 300px;"></canvas>
                </div>
                <div class="chart">
                    <canvas id="incomesDonutChart" style="max-height: 300px;"></canvas>
                </div>

            </div>


        </div>



        <div class="dash graphs">
            <!-- months -->
            <div class="monthly-summary">
                <h2><?= t('Monthly Summary'); ?></h2>
                <table class="dash table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <?php foreach ($monthly_summary as $summary): ?>
                                <th><?= formatDate($summary['month']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Spese</td>
                            <?php foreach ($monthly_summary as $summary): ?>
                                <td class="negative">
                                    <?= $number_formatter->formatCurrency($summary['expenses'], $_SESSION['currency']); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td>Entrate</td>
                            <?php foreach ($monthly_summary as $summary): ?>
                                <td class="positive">
                                    <?= $number_formatter->formatCurrency($summary['income'], $_SESSION['currency']); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td></td>
                            <?php foreach ($monthly_summary as $summary): ?>
                                <td>
                                    <div class="monthly-summary-vertgraph">
                                        <div class="monthly-ex-vertgraph-expenses">
                                            <div class="vert-graph-filler negative" style="height:<?= ($summary['expenses']/max(1, $max_monthly_summary)*100); ?>%;"></div>
                                        </div>
                                        <div class="monthly-ex-vertgraph-incomes">
                                            <div class="vert-graph-filler positive" style="height:<?= ($summary['income']/max(1, $max_monthly_summary)*100); ?>%;"></div>
                                        </div>
                                        <div class="<?= $summary['total'] >= 0 ? 'positive' : 'negative'; ?>">
                                                <b><?= $number_formatter->formatCurrency($summary['total'], $_SESSION['currency']); ?></b>
                                        </div>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>


<!-- months -->
            <div class="yearly-summary">
                <h2><?= t('Yearly Summary'); ?></h2>
                <table class="dash table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <?php foreach ($yearly_summary as $summary): ?>
                                <th><?= $summary['year']; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Spese</td>
                            <?php foreach ($yearly_summary as $summary): ?>
                                <td class="negative">
                                    <?= $number_formatter->formatCurrency($summary['expenses'], $_SESSION['currency']); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td>Entrate</td>
                            <?php foreach ($yearly_summary as $summary): ?>
                                <td class="positive">
                                    <?= $number_formatter->formatCurrency($summary['income'], $_SESSION['currency']); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td></td>
                            <?php foreach ($yearly_summary as $summary): ?>
                                <td>
                                    <div class="monthly-summary-vertgraph">
                                        <div class="monthly-ex-vertgraph-expenses">
                                            <div class="vert-graph-filler negative" style="height:<?= ($summary['expenses']/max(1, $max_yearly_summary)*100); ?>%;"></div>
                                        </div>
                                        <div class="monthly-ex-vertgraph-incomes">
                                            <div class="vert-graph-filler positive" style="height:<?= ($summary['income']/max(1, $max_yearly_summary)*100); ?>%;"></div>
                                        </div>
                                        <div class="<?= $summary['total'] >= 0 ? 'positive' : 'negative'; ?>">
                                                <b><?= $number_formatter->format($summary['total']); ?></b>
                                        </div>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p>
            <div class="chart-title"><?= t('Expenses by category summary'); ?></div>
            <div class="chart">
                <canvas id="expensesCategoryTrendsChart" height="600"></canvas>
            </div>
            <div class="chart-title"><?= t('Incomes by category summary'); ?></div>
            <div class="chart">
                <canvas id="incomesCategoryTrendsChart" height="600"></canvas>
            </div>

            

        </div>
    </div>
    




    <script>

        // toggle micro/macro categories chart
        document.getElementById('toggleCategoryChart').addEventListener('click', function() {
            const categoriesChart = document.getElementById('categoriesChart');
            const macroCategoriesChart = document.getElementById('macroCategoriesChart');
            if (categoriesChart.style.display === 'none') {
                categoriesChart.style.display = 'block';
                macroCategoriesChart.style.display = 'none';
            } else {
                categoriesChart.style.display = 'none';
                macroCategoriesChart.style.display = 'block';
            }
        });


        document.addEventListener('DOMContentLoaded', function() {
            const savingsCtx = document.getElementById('savingsChart').getContext('2d');
            const expensesDonutCtx = document.getElementById('expensesDonutChart').getContext('2d');
            const incomesCtx = document.getElementById('incomesChart').getContext('2d');
            const incomesDonutCtx = document.getElementById('incomesDonutChart');
            const categoriesCtx = document.getElementById('categoriesChart').getContext('2d');
            const macroCategoriesCtx = document.getElementById('macroCategoriesChart').getContext('2d');
            const expensesByCatInTime = document.getElementById('expensesCategoryTrendsChart').getContext('2d');
            const incomesByCatInTime = document.getElementById('incomesCategoryTrendsChart').getContext('2d');


            new Chart(savingsCtx, {
                type: 'line',
                data: {
                    labels: <?= scriptJson(array_column($timespan_summary, 'month')); ?>,
                    datasets: [{
                        label: <?= scriptJson(t('Savings')); ?>,
                        data: <?= scriptJson(array_column($timespan_summary, 'total')); ?>,
                        backgroundColor: function(context) {
                            const value = context.raw;
                            return value < 0 ? 'rgba(255, 99, 132, 0.5)' : 'rgba(75, 192, 192, 0.5)';
                        },
                        borderColor: function(context) {
                            const value = context.raw;
                            return value < 0 ? 'rgba(255, 99, 132, 1)' : 'rgba(75, 192, 192, 1)';
                        },
                        borderWidth: 1,
                        fill: {
                            target: 'origin',
                            above: 'rgba(75, 192, 192, 0.5)', // Above zero
                            below: 'rgba(255, 99, 132, 0.5)'  // Below zero
                        }
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },

                    scales: {
                        x: {
                            display: true,
                            title: {
                                //display: true,
                                //text: 'Month'
                            },
                            ticks: {
                                maxTicksLimit: 12,
                                callback: function(value, index, values) {
                                    // Show only MM/YYYY
                                    const date = new Date(this.getLabelForValue(value));
                                    return date.toLocaleDateString('it-IT', { 
                                        month: 'short', 
                                        year: '2-digit'
                                    });
                                }
                            }
                        },
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });


            new Chart(categoriesCtx, {
                type: 'bar',
                data: {
                    labels: <?= scriptJson(array_column($category_totals, 'category')); ?>,
                    datasets: [{
                        label: <?= scriptJson(t('Expenses by category')); ?>,
                        data: <?= scriptJson(array_column($category_totals, 'total')); ?>,
                        backgroundColor: '#36A2EB'
                        }]
                },
                options: {
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false // labels always visible (?)
                        },
                        datalabels: {
                            formatter: (value, context) => {
                                return eval(value).toLocaleString();
                            },
                            color: '#fff',
                            font: {
                                size: 10
                            }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });

            new Chart(macroCategoriesCtx, {
                type: 'bar',
                data: {
                    labels: <?= scriptJson(array_column($macrocategory_totals, 'macrocategory')); ?>,
                    datasets: [{
                        label: <?= scriptJson(t('Expenses by macrocategory')); ?>,
                        data: <?= scriptJson(array_column($macrocategory_totals, 'total')); ?>,
                        backgroundColor: '#36A2EB'
                        }]
                },
                options: {
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false // labels always visible (?)
                        },
                        datalabels: {
                            formatter: (value, context) => {
                                return eval(value).toLocaleString();
                            },
                            color: '#fff',
                            font: {
                                size: 10
                            }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });


            // expenses donut
            new Chart(expensesDonutCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= scriptJson(array_column($category_totals, 'category')); ?>,
                    datasets: [{
                        data: <?= scriptJson(array_column($category_totals, 'total')); ?>,
                        backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40']
                    }]
                },
                options: {
                    plugins: {
                        datalabels: {
                            formatter: (value, context) => {
                                const dataset = context.chart.data.datasets[0].data;
                                const total = dataset.reduce((acc, val) => Number(acc) + Number(val), 0);
                                const percentage = (total > 0 ? (value / total) * 100 : 0).toFixed(0);
                                return `${percentage}%`;
                            },
                            color: '#fff',
                            font: {
                                weight: 'bold',
                                size: 14
                            }
                        },
                        legend: {
                            position: 'left'
                        }
                    }
                },
                plugins: [ChartDataLabels] // Include plugin
            });


            // Incomes bar
            new Chart(incomesCtx, {
                type: 'bar',
                data: {
                    labels: <?= scriptJson(array_column($income_categories, 'category')); ?>,
                    datasets: [{
                        label: <?= scriptJson(t('Incomes by category summary')); ?>,
                        data: <?= scriptJson(array_column($income_categories, 'total')); ?>,
                        backgroundColor: '#36A2EB'
                        }]
                },
                options: {
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false // labels always visible (?)
                        },
                        datalabels: {
                            formatter: (value, context) => {
                                return eval(value).toLocaleString();
                            },
                            color: '#fff',
                            font: {
                                size: 10
                            }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });



             // Income Categories Donut Chart
            new Chart(incomesDonutCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= scriptJson(array_column($income_categories, 'category')); ?>,
                    datasets: [{
                        data: <?= scriptJson(array_column($income_categories, 'total')); ?>,
                        backgroundColor: ['#4BC0C0', '#FF6384', '#FFCE56', '#36A2EB', '#9966FF', '#FF9F40']
                    }]
                },
                options: {
                    plugins: {
                        datalabels: {
                            formatter: (value, ctx) => {
                                const total = ctx.chart.data.datasets[0].data.reduce((a, b) => Number(a) + Number(b), 0);
                                const percentage = (total > 0 ? (value / total) * 100 : 0).toFixed(1);
                                return `${percentage}%`;
                            },
                            color: '#fff',
                            font: {
                                weight: 'bold'
                            }
                        },
                        legend: {
                            position: 'left'
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });


            new Chart(incomesByCatInTime, {
                type: 'line',
                data: <?= scriptJson($Incomes_bycat_chartData); ?>,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    scales: {
                        x: {
                            display: true,
                            title: {
                                //display: true,
                                //text: 'Month'
                            },
                            ticks: {
                                maxTicksLimit: 12,
                                callback: function(value, index, values) {
                                    // Show only MM/YYYY
                                    const date = new Date(this.getLabelForValue(value));
                                    return date.toLocaleDateString('it-IT', { 
                                        month: 'short', 
                                        year: '2-digit'
                                    });
                                }
                            }
                        },
                        y: {
                            display: true,
                            title: {
                                display: true,
                                text: 'Entrate (€)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString('it-IT', { 
                                        style: 'currency', 
                                        currency: 'EUR' 
                                    });
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    label += new Intl.NumberFormat('it-IT', {
                                        style: 'currency',
                                        currency: 'EUR'
                                    }).format(context.parsed.y);
                                    return label;
                                }
                            }
                        }
                    }
                }
            });


            new Chart(expensesByCatInTime, {
                type: 'line',
                //data: <?= scriptJson($Expenses_bycat_chartData); ?>,
                data: {
                    labels: <?= scriptJson($Expenses_bycat_chartData['labels']); ?>, 
                    datasets: <?= scriptJson($Expenses_bycat_chartData['datasets']); ?>
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    scales: {
                        x: {
                            display: true,
                            title: {
                                //display: true,
                                //text: 'Month'
                            },
                            ticks: {
                                maxTicksLimit: 15,
                                callback: function(value, index, values) {
                                    // Show only MM/YYYY
                                    const date = new Date(this.getLabelForValue(value));
                                    return date.toLocaleDateString('it-IT', { 
                                        month: 'short', 
                                        year: '2-digit'
                                    });
                                }
                            }
                        },
                        y: {
                            display: true,
                            title: {
                                display: true,
                                text: 'Entrate (€)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString('it-IT', { 
                                        style: 'currency', 
                                        currency: 'EUR' 
                                    });
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    label += new Intl.NumberFormat('it-IT', {
                                        style: 'currency',
                                        currency: 'EUR'
                                    }).format(context.parsed.y);
                                    return label;
                                }
                            }
                        }
                    }
                }
            });


        });

    </script>
    <script type="text/javascript">
        var categories      = <?= scriptJson($categories); ?>;
        var macrocategories = <?= scriptJson($macrocategories); ?>;
        var currencies      = <?= scriptJson($currency_catalog); ?>;
        var exchange_rates  = <?= scriptJson($exchange_rates); ?>;
        var t               = <?= scriptJson($translations); ?>;
        var language        = "<?= $language; ?>";
        var entryFilters = <?= scriptJson($entry_filters); ?>;
        var entriesCsrf = <?= scriptJson($_SESSION['entries_csrf']); ?>;
        var main_currency   = "<?= $_SESSION['currency']; ?>";
    </script>
    <script src="res/script.js?v=1.5.3"></script><script src="res/dashboard-tools.js?v=1.5.3"></script>

<?php printFooter(); ?>
</body>
</html>
