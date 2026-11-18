<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/finance.php';
require_once __DIR__ . '/lib/currencies.php';
require_once __DIR__ . '/lib/i18n.php';


$exchange_rates = [
   'EUR' => [
       'EUR' => 1,
       'USD' => 0.96,
       'GBP' => 1.21,
       'ILS' => 0.26,
   ],
   'USD' => [
       'EUR' => 1.04,
       'USD' => 1,
       'GBP' => 1.26,
       'ILS' => 0.27,
   ],
   'GBP' => [
       'EUR' => 0.83,
       'USD' => 0.79,
       'GBP' => 1,
       'ILS' => 0.21,
   ],
   'ILS' => [
       'EUR' => 3.85,
       'USD' => 3.70,
       'GBP' => 4.76,
       'ILS' => 1,
   ],
];



$default = [
	'language' => 'en-US',
	'currency' => 'EUR',
];




// Resolve the language before creating default categories or repairing preferences.
if (empty($_SESSION['user_id']) && isset($_GET['lang']) && is_string($_GET['lang']) && isset(supportedLanguages()[$_GET['lang']])) $_SESSION['ui_language']=$_GET['lang'];
$language=normalizeLanguage($_SESSION['ui_language'] ?? $default['language']);
$user_settings=[];
if (!empty($_SESSION['user_id'])) {
    $uid=(int)$_SESSION['user_id'];
    $stmt=$conn->prepare('SELECT id FROM cf_users WHERE id=?');$stmt->bind_param('i',$uid);$stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {$_SESSION=[];header('Location: index.php');exit;}
    $stmt=$conn->prepare('SELECT * FROM cf_user_settings WHERE user_id=?');$stmt->bind_param('i',$uid);$stmt->execute();
    $user_settings=$stmt->get_result()->fetch_assoc() ?: [];
    $language=normalizeLanguage($user_settings['language'] ?? $_SESSION['language'] ?? $language);
    $preset=defaultCategoryConfig($language);
    ensureUserSettings($conn,$uid,$preset['categories'],$preset['macrocategories'],$language);
    $stmt=$conn->prepare('SELECT * FROM cf_user_settings WHERE user_id=?');$stmt->bind_param('i',$uid);$stmt->execute();
    $user_settings=$stmt->get_result()->fetch_assoc() ?: [];
}
$translations=loadTranslations($language);
$preset=defaultCategoryConfig($language);
$categories=$preset['categories'];$macrocategories=$preset['macrocategories'];
foreach (['categories','macrocategories'] as $key) {
    $saved=json_decode($user_settings[$key] ?? '',true);
    if (is_array($saved)) ${$key}=array_values(array_filter($saved,'is_string'));
}
$currency_catalog=currencyCatalog();
$_SESSION['currency'] = isset($currency_catalog[$user_settings['currency'] ?? '']) ? $user_settings['currency'] : 'EUR';
$currency_preferences=currencyPreferences($user_settings,$_SESSION['currency'],$exchange_rates);
$currencies=array_intersect_key($currency_catalog,array_flip($currency_preferences['enabled']));
$exrate=$currency_preferences['rates'];
// Legacy rates remain stable for pre-1.3 movements without snapshots.
// Extend conversion support for newly supported currencies using configured cross rates.
foreach ($currency_catalog as $targetCode=>$unused) {
    $exchange_rates[$targetCode] ??= [$targetCode=>1];
    $targetRate=defaultCurrencyRate($currency_preferences,$targetCode);
    if ($targetRate) foreach ($currency_preferences['rates'] as $sourceCode=>$sourceRate) {
        if (!isset($exchange_rates[$targetCode][$sourceCode]) && is_numeric($sourceRate) && $sourceRate>0) $exchange_rates[$targetCode][$sourceCode]=$sourceRate/$targetRate;
    }
}
$number_formatter = class_exists('NumberFormatter')
    ? new NumberFormatter($language, NumberFormatter::CURRENCY) : new BasicCurrencyFormatter($language);
$month_start_day = max(1, min(31, (int)($user_settings['month_start_day'] ?? 1)));

function generateCurrencyCases() {
    global $exchange_rates;
    $target = $_SESSION['currency'] ?? 'EUR';
    $general = []; $specific = [];
    foreach ($exchange_rates[$target] as $currency => $rate) {
        $general[] = "WHEN '$currency' THEN amount * $rate";
        $specific[] = "WHEN '$currency' THEN amount * exchange_rate * $rate";
    }
    return "(CASE WHEN exchange_rate > 0 AND rate_currency IS NOT NULL THEN (CASE rate_currency ".implode(' ', $specific)." ELSE amount END) ELSE (CASE currency ".implode(' ', $general)." ELSE amount END) END)";
}

function formatDate($date, $format = 0) {
    global $language;
    if (!$date || str_starts_with($date, '0000-')) return '—';
    if (preg_match('/^\d{4}-\d{2}$/', $date)) $date .= '-01';
    try { $parsed = new DateTimeImmutable($date); } catch (Exception $e) { return '—'; }
    if (class_exists('IntlDateFormatter')) {
        $fmt = new IntlDateFormatter($language, IntlDateFormatter::NONE, IntlDateFormatter::NONE);
        $fmt->setPattern([0 => 'MMM yyyy', 1 => 'dd MMM yyyy', 2 => 'dd/MM/yyyy', 3 => 'dd/MM/yyyy HH:mm:ss'][$format] ?? 'MMM yyyy');
        return $fmt->format($parsed);
    }
    $months=[
        'en-US'=>['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
        'it-IT'=>['gen','feb','mar','apr','mag','giu','lug','ago','set','ott','nov','dic'],
        'fr-FR'=>['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'],
        'es-ES'=>['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'],
        'de-DE'=>['Jan.','Feb.','März','Apr.','Mai','Juni','Juli','Aug.','Sept.','Okt.','Nov.','Dez.']
    ];
    if ($format===2 || $format===3) return $parsed->format($format===2 ? 'd/m/Y' : 'd/m/Y H:i:s');
    $month=$months[normalizeLanguage($language)][(int)$parsed->format('n')-1];
    return ($format===1 ? $parsed->format('d').' ' : '').$month.' '.$parsed->format('Y');
}

function scriptJson($value): string {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
}
function t($key) {
    global $translations;
    return $translations[$key] ?? $key;
}
function floatvalue($val){
            $val = str_replace(",",".",$val);
            $val = preg_replace('/\.(?=.*\.)/', '', $val);
            return floatval($val);
}
function printMenu() {
    echo '<div class="menubar"><a class="logo" href="dashboard.php" aria-label="Ctrl Fric">';
    include __DIR__ . '/res/logo.html';
    echo '</a><ul>';
    foreach (['dashboard.php' => 'Dashboard', 'index.php' => 'Add'] as $url => $label) {
        echo '<li><a href="'.$url.'">'.t($label).'</a></li>';
    }
    echo '<li><a class="menu-icon" href="profile.php" aria-label="'.t('Preferences').'" title="'.t('Preferences').'"><i class="fa-solid fa-gear" aria-hidden="true"></i></a></li>';
    echo '<li><button class="menu-icon" type="button" id="theme-toggle" aria-label="'.t('Dark mode').'" title="'.t('Dark mode').'" aria-pressed="false"><i class="fa-solid fa-moon" aria-hidden="true"></i></button></li>';
    echo '<li><a class="menu-icon" href="index.php?logout=true" aria-label="'.t('Logout').'" title="'.t('Logout').'"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i></a></li>';
    echo '</ul></div>';
}

function printFooter() {
    $version = htmlspecialchars(trim(file_get_contents(__DIR__ . '/VERSION')));
    echo '<footer class="app-footer">Ctrl Fric · v'.$version.'<br>&lt;\\&gt; with <i class="fa-solid fa-credit-card" aria-hidden="true"></i> by <a href="https://graytape.org">graytape.org</a></footer>';
}

function fillMissingLabels(&$chartData) {
    $existingLabels = $chartData['labels'];
    if (!$existingLabels) return;
    $completeLabels = [];
    
    // Find the complete date range.
    $startDate = new DateTime($existingLabels[0]??"");
    $endDate = new DateTime(end($existingLabels)??"");
    
    while ($startDate <= $endDate) {
        $completeLabels[] = $startDate->format('Y-m');
        $startDate->modify('+1 month');
    }
    
    $chartData['labels'] = $completeLabels;
    
    // Fill datasets with zeros for missing labels.
    foreach ($chartData['datasets'] as &$dataset) {
        $filledData = array_fill(0, count($completeLabels), 0);
        
        foreach ($existingLabels as $index => $label) {
            $newIndex = array_search($label, $completeLabels);
            if ($newIndex !== false) {
                $filledData[$newIndex] = $dataset['data'][$index];
            }
        }
        
        $dataset['data'] = $filledData;
    }
}


?>