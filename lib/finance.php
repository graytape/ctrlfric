<?php
class BasicCurrencyFormatter {
    private string $language;
    public function __construct(string $language='en-US') {$this->language=$language;}
    public function format($amount): string {
        $decimal=$this->language==='en-US' ? '.' : ',';
        $group=$this->language==='en-US' ? ',' : ($this->language==='fr-FR' ? "\u{202F}" : '.');
        return number_format((float)$amount,2,$decimal,$group);
    }
    public function formatCurrency($amount,$currency): string {return $this->format($amount).' '.$currency;}
}

function monthAnchor(DateTimeImmutable $month, int $day): DateTimeImmutable {
    return $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min($day, (int)$month->format('t')))->setTime(0, 0);
}
function budgetPeriod(string $date, int $day): array {
    $value = new DateTimeImmutable($date);
    $month = $value->modify('first day of this month')->setTime(0, 0);
    $start = monthAnchor($month, $day);
    if ($value < $start) { $month = $month->modify('-1 month'); $start = monthAnchor($month, $day); }
    $end = monthAnchor($month->modify('+1 month'), $day)->modify('-1 day');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}
// Label a period by the month in which it starts; clamp days 29–31 to month-end.
function periodMonthSql(): string {
    global $month_start_day;
    $day = (int)$month_start_day;
    return "DATE_FORMAT(CASE WHEN DAY(date) >= LEAST($day, DAY(LAST_DAY(date))) THEN date ELSE DATE_SUB(date, INTERVAL 1 MONTH) END, '%Y-%m')";
}
function categoryExpenseSql(): string {
    global $user_settings;
    $value = generateCurrencyCases();
    return !empty($user_settings['net_category_expenses'])
        ? "GREATEST(0, SUM(CASE WHEN type = 'Expense' THEN $value WHEN type = 'Income' THEN -($value) ELSE 0 END))"
        : "SUM(CASE WHEN type = 'Expense' THEN $value ELSE 0 END)";
}
function validDate(string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}
function decodeCategoryBudgets(string $json): array {
    $data = json_decode($json, true);
    if (!is_array($data)) return [];
    return array_filter($data, static function ($limit) {
        return is_numeric($limit) && is_finite((float)$limit) && (float)$limit > 0;
    });
}
function categoryBudgetStatuses(mysqli $conn, int $uid, string $date, int $day, array $limits): array {
    if (!$limits) return [];
    [$start, $end] = budgetPeriod($date, $day);
    $stmt = $conn->prepare("SELECT category, SUM(" . generateCurrencyCases() . ") AS spent FROM cf_entries WHERE user_id = ? AND type = 'Expense' AND date BETWEEN ? AND ? GROUP BY category");
    $stmt->bind_param('iss', $uid, $start, $end);
    $stmt->execute();
    $statuses = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $category = $row['category'];
        if (!isset($limits[$category])) continue;
        $spent = round((float)$row['spent'], 2);
        $limit = (float)$limits[$category];
        if ($spent > $limit) $statuses[] = ['category' => $category, 'start' => $start, 'end' => $end, 'spent' => $spent, 'limit' => $limit];
    }
    return $statuses;
}

// Rate: one unit of the entry currency equals this many units of rate_currency.
function parseSpecificRate($value) {
    if (!is_string($value)) return false;
    $value = str_replace(',', '.', trim($value));
    if ($value === '') return null;
    return is_numeric($value) && is_finite((float)$value) && (float)$value >= 0.0000000001 && (float)$value <= 9999999999 ? (float)$value : false;
}
