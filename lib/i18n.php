<?php
function supportedLanguages(): array {return ['en-US'=>'English','it-IT'=>'Italiano','fr-FR'=>'Français','es-ES'=>'Español','de-DE'=>'Deutsch'];}
function normalizeLanguage($value): string {return is_string($value) && isset(supportedLanguages()[$value]) ? $value : 'en-US';}
function categoryPresets(): array {static $presets;return $presets ??= require __DIR__.'/../res/category-presets.php';}
function defaultCategoryConfig(string $language): array {return categoryPresets()[normalizeLanguage($language)];}
function loadTranslations(string $language): array {
    $english=require __DIR__.'/../res/en-US.php';
    $translations=[];
    foreach ([$language, 'preferences-'.$language] as $name) {
        $file=__DIR__.'/../res/'.$name.'.php';
        if ($language!=='en-US' && is_file($file)) {$loaded=require $file;if(is_array($loaded)) $translations=array_replace($translations,$loaded);}
    }
    return array_replace($english,$translations);
}
function requestString(array $request,string $key,string $default=''): string {return isset($request[$key]) && is_string($request[$key]) ? $request[$key] : $default;}
function translateDefaultCategories(mysqli $conn,int $uid,array $settings,string $language): void {
    $target=defaultCategoryConfig($language);$updates=[];$maps=[];
    foreach (['categories','macrocategories'] as $field) {
        $saved=json_decode($settings[$field] ?? '',true);
        if (!is_array($saved) || array_filter($saved,static fn($v)=>!is_string($v))) continue;
        foreach (categoryPresets() as $preset) {
            if (count($saved)!==count($preset[$field]) || array_diff($saved,$preset[$field]) || array_diff($preset[$field],$saved)) continue;
            $map=array_combine($preset[$field],$target[$field]);
            $updates[$field]=array_map(static fn($name)=>$map[$name],$saved);
            $maps[$field]=$map; break;
        }
    }
    if (!$updates) return;
    $categories=json_encode($updates['categories'] ?? json_decode($settings['categories'] ?? '[]',true),JSON_UNESCAPED_UNICODE);
    $macros=json_encode($updates['macrocategories'] ?? json_decode($settings['macrocategories'] ?? '[]',true),JSON_UNESCAPED_UNICODE);
    $budgets=decodeCategoryBudgets($settings['category_budgets'] ?? '');$translated=[];
    foreach($budgets as $name=>$limit) $translated[$maps['categories'][$name] ?? $name]=$limit;
    $budgetJson=json_encode((object)$translated,JSON_UNESCAPED_UNICODE);
    $stmt=$conn->prepare('UPDATE cf_user_settings SET categories=?,macrocategories=?,category_budgets=? WHERE user_id=?');
    $stmt->bind_param('sssi',$categories,$macros,$budgetJson,$uid);$stmt->execute();
    foreach($maps as $field=>$map) {
        $column=$field==='categories' ? 'category' : 'macrocategory';$cases=[];$params=[];
        foreach($map as $from=>$to) {$cases[]='WHEN BINARY ? THEN ?';$params[]=$from;$params[]=$to;}
        $params[]=$uid;
        $stmt=$conn->prepare('UPDATE cf_entries SET '.$column.'=CASE BINARY '.$column.' '.implode(' ',$cases).' ELSE '.$column.' END WHERE user_id=?');
        $types=str_repeat('s',count($params)-1).'i';$stmt->bind_param($types,...$params);$stmt->execute();
    }
}
