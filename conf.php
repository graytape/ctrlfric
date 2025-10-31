<?php
require 'db.conf.php';


$categories = [

			// abitare
			'affitto',
			'bollette',
			'tasse',
			'alloggi',

			// vita
			'spostamenti',
			'vestiti',
			'salute',
			'varie',

			// spese
			'cibo',
			'cartoleria',
			'prestiti',
			'regali',
			'spese casa',
			'telefono',
			'domini web',

			// formazione
			'cultura',
			'svago',
			'tecnologia',
			'servizi',
			'viaggi',
			'libri',
			'università',

			// entrate
			'saldo',
			'lavori',
			'rimborsi',
			'rendite',
			'subaffitto',
		   	'fortuna',
		   	'altro',

			// borse di studio
			'borsa dottorato',
			'fondi PhD',
			'borsa IISS',

			// welfare
			'DIS-coll'

		   ];


$macrocategories = [

			'abitare',
			'vita',
			'spese',
			'formazione',
			'entrate',
			'borse di studio',
			'welfare'

		   ];



$categories = [

			// abitare
			'affitto',
			'bollette',
			'tasse',
			'alloggi',

			// vita
			'spostamenti',
			'vestiti',
			'salute',
			'varie',

			// spese
			'cibo',
			'cartoleria',
			'prestiti',
			'regali',
			'spese casa',
			'telefono',

			// formazione
			'cultura',
			'svago',
			'tecnologia',
			'servizi',
			'viaggi',
			'libri',
			'università',

			// entrate
			'saldo',
			'borse di studio',
			'lavoro',
			'rendite'

		   ];


$macrocategories = [

			'abitare',
			'vita',
			'spese',
			'formazione',
			'entrate',
			'borse di studio',
			'welfare'

		   ];

$currencies = [
		'euro' => [
				'name'   => 'euro',
				'symbol' => '€',
				'code'   => 'EUR',
		],
		'dollars' => [
				'name'   => 'dollars',
				'symbol' => '$',
				'code'   => 'USD',
		],
		'pounds' => [
				'name'   => 'pounds',
				'symbol' => '£',
				'code'   => 'GBP',
		],
		'shekalim' => [
				'name'   => 'shekel',
				'symbol' => '₪',
				'code'   => 'ILS',
		],
];


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
$exrate = $exchange_rates[$_SESSION['currency']??"EUR"];


$default = [
	'language' => 'en-US',
	'currency' => 'EUR',
];




// retrieve saved categories
$query = "SELECT * FROM User_settings WHERE user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$user_settings = $stmt->get_result()->fetch_assoc();


$categories      = !empty($user_settings['categories']) ? json_decode($user_settings['categories']) : $categories;
$macrocategories = !empty($user_settings['macrocategories']) ? json_decode($user_settings['macrocategories']) : $macrocategories;
$language        = $user_settings['language'] ?? "en-US";
if (file_exists("res/$language.php")) {include "res/$language.php";}

// Number Formatter
setlocale(LC_ALL, $language.'.UTF-8');
\Locale::setDefault($language);
$number_formatter = new \NumberFormatter($language, \NumberFormatter::CURRENCY);
$_SESSION['currency'] = $user_settings['currency'] ?? "EUR";



function generateCurrencyCases() {
    global $exchange_rates;

    $conversion_cases = [];
    $target_currency = $_SESSION['currency'] ?? "EUR";
    foreach ($exchange_rates[$target_currency] as $currency => $rate) {
        $conversion[] = "WHEN '$currency' THEN amount * $rate";
    }
	return "(CASE currency " . implode(' ', $conversion) . " END)";
          
}


function formatDate($date, $format = 0) {
	global $language;
 
    $fmt = new \IntlDateFormatter($language, IntlDateFormatter::FULL, IntlDateFormatter::FULL);
	// See: https://unicode-org.github.io/icu/userguide/format_parse/datetime/#datetime-format-syntax for pattern syntax
    switch ($format) {
    	case '3':
			$fmt->setPattern('dd/MM/yyyy HH:mm:s'); 
    		//return 11/11/2024 HH:mm:ss
    		break;
    	case '2':
			$fmt->setPattern('dd/MM/yyyy'); 
    		//return 11/11/2024)
    		break;
    	case '1':
			$fmt->setPattern('dd MMM yyyy'); 
    		//return 11 Nov 2024
    		break;
    	case '0':
			$fmt->setPattern('MMM yyyy'); 
    		//return Nov 2024
    		break;
    	default:
			$fmt->setPattern('MMM yyyy'); 
    		//return Nov 2024
    		break;
    }
	return $fmt->format(new \DateTime($date . "-01")); 
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
	echo "<div class=\"menubar\">";
	echo "<div class=\"logo\">";
		include 'res/logo.html';
	echo "</div>";
	echo "<ul>".
			"<li><a href=\"dashboard.php\">".t('Dashboard')."</a></li>".
			"<li><a href=\"index.php\">".t('Add')."</a></li>".
			"<li><a href=\"import.php\">".t('Import')."</a></li>".
			"<li><a href=\"profile.php\">".t('Profile')."</a></li>".
			"<li><a href=\"index.php?logout=true\">".t('Logout')."</a></li>".
		"</ul>";
	echo "</div>";
}

function fillMissingLabels(&$chartData) {
    $existingLabels = $chartData['labels'];
    $completeLabels = [];
    
    // Trova il range di date completo
    $startDate = new DateTime($existingLabels[0]??"");
    $endDate = new DateTime(end($existingLabels)??"");
    
    while ($startDate <= $endDate) {
        $completeLabels[] = $startDate->format('Y-m');
        $startDate->modify('+1 month');
    }
    
    $chartData['labels'] = $completeLabels;
    
    // Riempie i dataset con valori zero per le etichette mancanti
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