<?php
function currencyCatalog(): array {
    $names = ['EUR'=>'Euro','USD'=>'US dollar','GBP'=>'Pound sterling','ILS'=>'Israeli shekel','CHF'=>'Swiss franc','CAD'=>'Canadian dollar','AUD'=>'Australian dollar','JPY'=>'Japanese yen','CNY'=>'Chinese yuan','HKD'=>'Hong Kong dollar','SGD'=>'Singapore dollar','NZD'=>'New Zealand dollar','SEK'=>'Swedish krona','NOK'=>'Norwegian krone','DKK'=>'Danish krone','PLN'=>'Polish zloty','CZK'=>'Czech koruna','HUF'=>'Hungarian forint','RON'=>'Romanian leu','INR'=>'Indian rupee','BRL'=>'Brazilian real','MXN'=>'Mexican peso','ZAR'=>'South African rand','KRW'=>'South Korean won','TRY'=>'Turkish lira','AED'=>'UAE dirham','SAR'=>'Saudi riyal','THB'=>'Thai baht','PHP'=>'Philippine peso','IDR'=>'Indonesian rupiah','MYR'=>'Malaysian ringgit','TWD'=>'Taiwan dollar'];
    $out = [];
    foreach ($names as $code=>$name) $out[$code] = ['code'=>$code,'name'=>$name,'symbol'=>$code];
    return $out;
}
function currencyPreferences(array $settings, string $base, array $legacy): array {
    $saved = json_decode($settings['currency_preferences'] ?? '', true);
    $saved = is_array($saved) ? $saved : [];
    $selection=$saved['enabled'] ?? ['EUR','USD','GBP','ILS'];
    $selection=is_array($selection) ? array_filter($selection,'is_string') : ['EUR','USD','GBP','ILS'];
    $enabled = array_values(array_intersect(array_keys(currencyCatalog()), $selection));
    if (!in_array($base,$enabled,true)) $enabled[]=$base;
    $rates = ($saved['base'] ?? $base)===$base ? ($saved['rates'] ?? ($legacy[$base] ?? [])) : [];
    $dates = ($saved['base'] ?? $base)===$base ? ($saved['dates'] ?? []) : [];
    // Rebase defaults if a configured quote for the new reference exists.
    if (($saved['base'] ?? $base)!==$base && is_array($saved['rates'] ?? null) && is_numeric($saved['rates'][$base] ?? null) && (float)$saved['rates'][$base]>0) {
        foreach ($saved['rates'] as $code=>$rate) $rates[$code]=(float)$rate/(float)$saved['rates'][$base];
    }
    $rates=is_array($rates) ? array_filter($rates,static fn($v)=>is_numeric($v)&&is_finite((float)$v)&&(float)$v>0) : [];
    $dates=is_array($dates) ? array_filter($dates,'is_string') : [];
    $rates[$base]=1;
    return ['base'=>$base,'enabled'=>$enabled,'auto'=>!empty($saved['auto']),'rates'=>$rates,'dates'=>$dates];
}
function defaultCurrencyRate(array $preferences, string $source): ?float {
    $rate = $preferences['rates'][$source] ?? null;
    return is_numeric($rate) && is_finite((float)$rate) && (float)$rate>0 ? (float)$rate : null;
}
function frankfurterRequest(string $path): array {
    $url='https://api.frankfurter.dev/v2/'.$path;
    if (function_exists('curl_init')) {
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'CtrlFric/1.4.0']);
        $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        if ($body===false || $status!==200) throw new RuntimeException('Rates unavailable. Please try again.');
    } else {
        $context=stream_context_create(['http'=>['timeout'=>12,'follow_location'=>0,'ignore_errors'=>true,'header'=>"Accept: application/json\r\nUser-Agent: CtrlFric/1.4.0\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $body=@file_get_contents($url,false,$context);
        if ($body===false || !preg_match('/\s200\s/',$http_response_header[0] ?? '')) throw new RuntimeException('Rates unavailable. Please try again.');
    }
    $data=json_decode($body,true);
    if (!is_array($data)) throw new RuntimeException('Rates unavailable. Please try again.');
    return $data;
}
function frankfurterRates(string $base, array $sources, ?callable $transport=null): array {
    $catalog=currencyCatalog();
    if (!isset($catalog[$base]) || array_diff($sources,array_keys($catalog))) throw new InvalidArgumentException('Invalid currency.');
    $sources=array_values(array_unique($sources));
    $foreign=array_values(array_diff($sources,[$base]));
    $out=['rates'=>[$base=>1.0],'dates'=>[]];
    if (!$foreign) return $out;
    $transport=$transport ?? 'frankfurterRequest';
    // API gives base -> quote; our inputs give one source unit -> account base.
    $rows=$transport('rates?'.http_build_query(['base'=>$base,'quotes'=>implode(',',$foreign)]));
    foreach ($rows as $row) {
        if (!is_array($row) || ($row['base'] ?? '')!==$base || !in_array($row['quote'] ?? '',$foreign,true)) continue;
        $value=$row['rate'] ?? null; $date=$row['date'] ?? '';
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value<=0 || !is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) continue;
        $rate=1/(float)$value;
        if ($rate<0.0000000001 || $rate>9999999999) continue;
        $out['rates'][$row['quote']]=$rate; $out['dates'][$row['quote']]=$date;
    }
    if (array_diff($foreign,array_keys($out['rates']))) throw new RuntimeException('Some rates are unavailable. No rates were changed.');
    return $out;
}
function newEntryRate(string $source, array $preferences, $override='', ?callable $transport=null): array {
    if (!in_array($source,$preferences['enabled'],true)) throw new RuntimeException('Enable this currency in Preferences first.');
    $base=$preferences['base'];
    if ($source===$base) return [1.0,$base,null];
    $manual=parseSpecificRate($override);
    if ($manual===false) throw new RuntimeException('Please enter a valid exchange rate.');
    if ($manual!==null) return [roundCurrencyRate($manual),$base,null];
    if ($preferences['auto']) {
        $fresh=frankfurterRates($base,[$source],$transport);
        return [roundCurrencyRate($fresh['rates'][$source]),$base,$fresh['dates'][$source]];
    }
    $rate=parseSpecificRate($override);
    if ($rate===false) throw new RuntimeException('Please enter a valid exchange rate.');
    $rate=$rate ?? defaultCurrencyRate($preferences,$source);
    if ($rate===null) throw new RuntimeException('Set an exchange rate in Preferences or enable automatic rates.');
    return [roundCurrencyRate($rate),$base,$rate===defaultCurrencyRate($preferences,$source) ? ($preferences['dates'][$source] ?? null) : null];
}

function roundCurrencyRate(float $rate): float { $rounded=round($rate,3);return $rounded>0 ? $rounded : $rate; }
function displayCurrencyRate($rate): string {if(!is_numeric($rate)) return '';return (float)$rate>=0.0005 ? number_format((float)$rate,3,'.','') : rtrim(rtrim(sprintf('%.10F',(float)$rate),'0'),'.');}
