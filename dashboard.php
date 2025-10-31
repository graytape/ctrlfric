<?php
mb_internal_encoding('UTF-8');
header('Content-Type: text/html; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require 'conf.php';


if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Fetch filter dates | default = this month
$filter_start_date = date('Y-m-01');
$filter_end_date   = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['start_date'], $_GET['end_date'])) {
    $filter_start_date = $_GET['start_date'];
    $filter_end_date = $_GET['end_date'];
}


// Fetch timespan entries
$query = "SELECT * FROM Entries WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date DESC, id DESC LIMIT 20";
$stmt = $conn->prepare($query);
$stmt->bind_param('iss', $_SESSION['user_id'], $filter_start_date, $filter_end_date);
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);


// Fetch current balance
$query = "SELECT 
    (SELECT SUM(".generateCurrencyCases().") FROM Entries WHERE user_id = ? AND type = 'Income' AND date BETWEEN ? AND ?) as total_income,
    (SELECT SUM(".generateCurrencyCases().") FROM Entries WHERE user_id = ? AND type = 'Expense' AND date BETWEEN ? AND ?) as total_expenses";
$stmt = $conn->prepare($query);
$stmt->bind_param('ississ', $_SESSION['user_id'], $filter_start_date, $filter_end_date, $_SESSION['user_id'], $filter_start_date, $filter_end_date);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
$total_income = $totals['total_income'] ?? 0;
$total_expenses = $totals['total_expenses'] ?? 0;
$current_balance = $total_income - $total_expenses;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['start_date'], $_GET['end_date'])) {
    $filter_start_date_minimum = $filter_start_date;
    $filter_end_date = $filter_end_date;
} else {
    $filter_start_date_minimum = date('Y-m-01', strtotime('-6 month'));
    $filter_end_date = $filter_end_date;
}


// Fetch timespan summary expenses (savings graph)
$query = "SELECT DATE_FORMAT(date, '%Y-%m') as month, 
                SUM(CASE WHEN type = 'Expense' THEN ".generateCurrencyCases()." ELSE 0 END) as expenses,
                SUM(CASE WHEN type = 'Income' THEN ".generateCurrencyCases()." ELSE 0 END) as income,
                SUM(CASE WHEN type = 'Expense' THEN ".generateCurrencyCases()." * -1 ELSE ".generateCurrencyCases()." END) as total
          FROM (
                SELECT * FROM Entries 
                WHERE user_id = ? AND type = 'Income' AND date BETWEEN ? AND ? 
                UNION ALL 
                SELECT * FROM Entries 
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
$query = "SELECT DATE_FORMAT(date, '%Y-%m') as month, 
                SUM(CASE WHEN type = 'Expense' THEN ".generateCurrencyCases()." ELSE 0 END) as expenses,
                SUM(CASE WHEN type = 'Income' THEN ".generateCurrencyCases()." ELSE 0 END) as income,
                SUM(CASE WHEN type = 'Expense' THEN ".generateCurrencyCases()." * -1 ELSE ".generateCurrencyCases()." END) as total
          FROM (
                SELECT * FROM Entries 
                WHERE user_id = ? AND type = 'Income' AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 12 MONTH)
                UNION ALL 
                SELECT * FROM Entries 
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
                SELECT * FROM Entries 
                WHERE user_id = ? AND type = 'Income' AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 10 YEAR)
                UNION ALL 
                SELECT * FROM Entries 
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
$query = "SELECT category, SUM(".generateCurrencyCases().") as total FROM Entries WHERE user_id = ? AND type = 'Expense' AND date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC LIMIT 10 ";
$stmt = $conn->prepare($query);
$stmt->bind_param('iss', $_SESSION['user_id'], $filter_start_date, $filter_end_date);
$stmt->execute();
$category_totals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch income categories for timespan period (income by categories)
$query = "SELECT category, SUM(".generateCurrencyCases().") as total 
          FROM Entries 
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
    DATE_FORMAT(date, '%Y-%m') as month,
    category,
    SUM(".generateCurrencyCases().") as total
FROM Entries 
WHERE user_id = ? 
    AND type = 'Expense'
    AND date BETWEEN ? AND ?
GROUP BY DATE_FORMAT(date, '%Y-%m'), category
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
// Crate a dataset for each category
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

// Create a dataset for top 10 categories
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
// Icomes by category in the last ten years
// Get categories per month in the last 10 years
$query = "SELECT 
    DATE_FORMAT(date, '%Y-%m') as month,
    category,
    SUM(".generateCurrencyCases().") as total
FROM Entries 
WHERE user_id = ? 
    AND type = 'Income'
    AND date BETWEEN ? AND ?
GROUP BY DATE_FORMAT(date, '%Y-%m'), category
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

// Crate a dataset for each category
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

// Create a dataset for top 10 categories
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="res/style.css?<?= rand(3,300); ?>">
    <link rel="icon" href="res/favicon.svg" sizes="any" type="image/svg+xml">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>

    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>

    <title><?= t('View Expenses and Incomes'); ?></title>
</head>
<body>
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

            <div>
                <!-- timespan -->
                <form method="GET" class="filter-group">
                    <label for="start_date"><?= t('Start Date'); ?>:</label>
                    <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($filter_start_date); ?>" required>
                    <label for="end_date"><?= t('End Date'); ?>:</label>
                    <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($filter_end_date); ?>" required>
                    <button type="submit"><?= t('Apply'); ?></button>
                </form>

                <!-- search bar -->
                <form method="GET" class="">
                    <div class="search-container">
                        <input type="text" id="searchInput" placeholder="<?= t('Search transactions...'); ?>">
                        <button id="searchButton" type="button"><i class="fa fa-search icon"></i><?= t('Search'); ?></button>
                    </div>
                </form>
            </div>

        </div>


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
                            <tr data-id="<?= $entry['id']; ?>" data-type="<?= $entry['type'] == 'Expense' ? "Expense" : "Income" ?>">
                                <td>
                                    <span class="display-value"><?= formatDate($entry['date'], 2); ?></span>
                                    <input type="date" class="edit-value hidden date" value="<?= $entry['date']; ?>">
                                    <input type="text" class="edit-value hidden source" value="<?= htmlspecialchars($entry['source']??""); ?>" placeholder="source">
                                </td>
                                <td>
                                    <span class="display-value tag-category"><?= htmlspecialchars($entry['category'], 2); ?></span>
                                    <span class="display-value tag-macrocategory"><?= htmlspecialchars($entry['macrocategory'], 2); ?></span>
                                    <select class="edit-value hidden category" value="<?= htmlspecialchars($entry['category']); ?>">
                                         <?php foreach ($categories as $category): ?>
                                            <option value="<?= $category; ?>" <?= ($category==$entry['category'])?"selected":""; ?>><?= $category; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select class="edit-value hidden macrocategory" value="<?= htmlspecialchars($entry['macrocategory']); ?>">
                                         <?php foreach ($macrocategories as $macrocategory): ?>
                                            <option value="<?= $macrocategory; ?>" <?= ($macrocategory==$entry['macrocategory'])?"selected":""; ?>><?= $macrocategory; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <span class="display-value"><?= htmlspecialchars($entry['description']); ?></span>
                                    <input type="text" class="edit-value hidden description" value="<?= htmlspecialchars($entry['description']); ?>" placeholder="description">
                                    <span class="display-value"><?= (!empty($entry['note'])) ? "<i class=\"fa fa-info-circle info-note\" title=\"".htmlspecialchars($entry['note'])."\"></i>":""; ?></span>
                                    <input type="text" class="edit-value hidden note" value="<?= htmlspecialchars($entry['note']??""); ?>" placeholder="note">
                                </td>
                                <?php if ($entry['type'] == 'Expense'): ?>
                                    <td></td><td class="expense-amount">
                                            <span class="display-value negative" data-currency="<?= $entry['currency']; ?>">
                                                <?= '-' . $number_formatter->formatCurrency($entry['amount'], $entry['currency']); ?>
                                            </span>
                                            <input type="number" step="0.01" class="edit-value hidden amount" value="<?= $entry['amount']; ?>">
                                            <select class="edit-value hidden currency">
                                                <?php foreach ($currencies as $currency): ?>
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
                                                <?php foreach ($currencies as $currency): ?>
                                                    <option value="<?= $currency['code']; ?>" <?= $entry['currency']==$currency['code']?"selected":""; ?> >
                                                        <?= $currency['code']; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                    </td><td></td>
                                <?php endif; ?>
                                <td class="actions">
                                    <button class="edit-btn" title="<?= t('Edit'); ?>"><i class="fa fa-edit"></i></button>
                                    <button class="delete-btn" title="<?= t('Delete'); ?>"><i class="fa fa-trash"></i></button>
                                    <button class="save-btn hidden" title="<?= t('Save'); ?>"><i class="fa fa-check"></i></button>
                                    <button class="cancel-btn hidden" title="<?= t('Cancel'); ?>"><i class="fa fa-times"></i></button>
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
                <button class="w100 mb10" id="load-more-entries"><?= t('Load More'); ?></button>
        


        <!-- Right: Graphs and Summary -->

            </div><div class="charts-container">
                        <div class="chart-title"><?= t("Savings"); ?></div>
                        <div class="chart">
                            <canvas id="savingsChart" style="max-height: 200px;"></canvas>
                        </div>
                        <div class="chart-title"><?= t("Expenses by category"); ?></div>
                        <div class="chart">
                            <canvas id="categoriesChart" style="max-height: 300px;"></canvas>
                        </div>
                        <div class="chart-title"><?= t("Expenses"); ?></div>
                        <div class="chart">
                            <canvas id="expensesDonutChart" style="max-height: 300px;"></canvas>
                        </div>
                        <div class="chart-title"><?= t("Incomes"); ?></div>
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
                                            <div class="vert-graph-filler negative" style="height:<?= ($summary['expenses']/$max_monthly_summary*100); ?>%;"></div>
                                        </div>
                                        <div class="monthly-ex-vertgraph-incomes">
                                            <div class="vert-graph-filler positive" style="height:<?= ($summary['income']/$max_monthly_summary*100); ?>%;"></div>
                                        </div>
                                        <div class="<?= $summary['total'] >= 0 ? 'positive' : 'negative'; ?>">
                                                <b><?= $number_formatter->formatCurrency($summary['total'], $_SESSION['currency']); ?></b>
                                        </div>
                                    </td>
                                </div>
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
                                            <div class="vert-graph-filler negative" style="height:<?= ($summary['expenses']/$max_yearly_summary*100); ?>%;"></div>
                                        </div>
                                        <div class="monthly-ex-vertgraph-incomes">
                                            <div class="vert-graph-filler positive" style="height:<?= ($summary['income']/$max_yearly_summary*100); ?>%;"></div>
                                        </div>
                                        <div class="<?= $summary['total'] >= 0 ? 'positive' : 'negative'; ?>">
                                                <b><?= $number_formatter->format($summary['total']); ?></b>
                                        </div>
                                    </td>
                                </div>
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
    

    <div class="footer"  style="width:100%;">
        <\> with <i class="fa fa-credit-card"></i> by <a href="https://graytape.org">graytape.org</a>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const savingsCtx = document.getElementById('savingsChart').getContext('2d');
            const expensesDonutCtx = document.getElementById('expensesDonutChart').getContext('2d');
            const incomesDonutCtx = document.getElementById('incomesDonutChart');
            const categoriesCtx = document.getElementById('categoriesChart').getContext('2d');
            const expensesByCatInTime = document.getElementById('expensesCategoryTrendsChart').getContext('2d');
            const incomesByCatInTime = document.getElementById('incomesCategoryTrendsChart').getContext('2d');


            new Chart(savingsCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode(array_column($timespan_summary, 'month')); ?>,
                    datasets: [{
                        label: 'Savings',
                        data: <?= json_encode(array_column($timespan_summary, 'total')); ?>,
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
                                //text: 'Mese'
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
                    labels: <?= json_encode(array_column($category_totals, 'category')); ?>,
                    datasets: [{
                        label: 'Expenses by Category',
                        data: <?= json_encode(array_column($category_totals, 'total')); ?>,
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


            new Chart(expensesDonutCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_column($category_totals, 'category')); ?>,
                    datasets: [{
                        data: <?= json_encode(array_column($category_totals, 'total')); ?>,
                        backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40']
                    }]
                },
                options: {
                    plugins: {
                        datalabels: {
                            formatter: (value, context) => {
                                const dataset = context.chart.data.datasets[0].data;
                                const total = dataset.reduce((acc, val) => eval(acc) + eval(val), 0);
                                const percentage = ((value / total) * 100).toFixed(0);
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



             // Income Categories Donut Chart
            new Chart(incomesDonutCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_column($income_categories, 'category')); ?>,
                    datasets: [{
                        data: <?= json_encode(array_column($income_categories, 'total')); ?>,
                        backgroundColor: ['#4BC0C0', '#FF6384', '#FFCE56', '#36A2EB', '#9966FF', '#FF9F40']
                    }]
                },
                options: {
                    plugins: {
                        datalabels: {
                            formatter: (value, ctx) => {
                                const total = ctx.chart.data.datasets[0].data.reduce((a, b) => eval(a) + eval(b), 0);
                                const percentage = ((value / total) * 100).toFixed(1);
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
                data: <?= json_encode($Incomes_bycat_chartData); ?>,
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
                                //text: 'Mese'
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
                //data: <?= json_encode($Expenses_bycat_chartData); ?>,
                data: {
                    labels: <?= json_encode($Expenses_bycat_chartData['labels']); ?>, 
                    datasets: <?= json_encode($Expenses_bycat_chartData['datasets']); ?>
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
                                //text: 'Mese'
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
        var categories      = <?= json_encode($categories); ?>;
        var macrocategories = <?= json_encode($macrocategories); ?>;
        var currencies      = <?= json_encode($currencies); ?>;
        var exchange_rates  = <?= json_encode($exchange_rates); ?>;
        var t               = <?= json_encode($translations); ?>;
        var language        = "<?= $language; ?>";
        var main_currency   = "<?= $_SESSION['currency']; ?>";
    </script>
    <script src="res/script.js"></script>

</body>
</html>
