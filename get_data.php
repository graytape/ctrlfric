<?php
require_once __DIR__.'/conf.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');exit(json_encode(['error'=>t('Unauthorized')]));
}



header('Content-Type: application/json; charset=UTF-8');
$action=requestString($_POST,'action',requestString($_GET,'action','fetch'));
require_once __DIR__.'/lib/entry-filters.php';
if (in_array($action,['fetch','search'],true)) {
    try {$filters=entryFilters($_GET,date('Y-m-01',strtotime('-1 year')),date('Y-m-d'));}
    catch (InvalidArgumentException $e) {http_response_code(400);exit(json_encode(['error'=>t($e->getMessage())]));}
}
if ($action==='bulk') {
    require __DIR__.'/lib/bulk-entries.php';
    try {
        $rows=bulkEntries($conn,(int)$_SESSION['user_id'],$_POST);
        if (requestString($_POST,'operation')==='export') {
            header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="ctrlfric-selection.csv"');
            $out=fopen('php://output','w');fputcsv($out,['Date','Type','Category','Macrocategory','Description','Out','In','Currency','Source','Note','Exchange rate','Rate currency','Rate date'],',','"','');
            foreach($rows as $row) fputcsv($out,[$row['date'],$row['type'],$row['category'],$row['macrocategory'],$row['description'],$row['type']==='Expense'?$row['amount']:'',$row['type']==='Income'?$row['amount']:'',$row['currency'],$row['source'],$row['note'],$row['exchange_rate'],$row['rate_currency'],$row['exchange_rate_date']],',','"','');
            fclose($out);exit;
        }
        echo json_encode(['success'=>true,'count'=>count($rows)]);exit;
    } catch (InvalidArgumentException $e) {http_response_code(400);exit(json_encode(['error'=>t($e->getMessage())]));}
}

switch ($action) {
    case 'delete':
        $id=filter_var(requestString($_POST,'id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
        $type=requestString($_POST,'type');
        
        if (!$id || !$type) {
            http_response_code(400);
            exit(json_encode(['error' => t('Missing parameters')]));
        }
        
        $query = "DELETE FROM cf_entries WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('ii', $id, $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => t('Delete failed')]);
        }
        break;

    case 'update':
        $id=filter_var(requestString($_POST,'id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
        $type=requestString($_POST,'type');
        $description=requestString($_POST,'description');
        $amount=requestString($_POST,'amount');
        $currency=requestString($_POST,'currency');
        $date=requestString($_POST,'date');
        $category=requestString($_POST,'category');
        $macrocategory=requestString($_POST,'macrocategory');
        $source=requestString($_POST,'source');
        $note=requestString($_POST,'note');
        $original=$conn->prepare('SELECT currency,exchange_rate,rate_currency,exchange_rate_date FROM cf_entries WHERE id=? AND user_id=?');
        $original->bind_param('ii',$id,$_SESSION['user_id']); $original->execute(); $old=$original->get_result()->fetch_assoc();
        if (!$old || (!in_array($currency,$currency_preferences['enabled'],true) && $currency!==$old['currency'])) {
            http_response_code(400); exit(json_encode(['error'=>t('Enable this currency in Preferences first.')]));
        }
        $specificRate = parseSpecificRate(requestString($_POST,'exchange_rate'));
        $rateCurrency = $specificRate === null ? null : requestString($_POST,'rate_currency',$_SESSION['currency']);

        
        if ($specificRate === false || ($specificRate !== null && !isset($exchange_rates[$rateCurrency])) || !$id || !in_array($type, ['Expense', 'Income'], true) || !$description || !is_numeric($amount) || (float)$amount <= 0 || !validDate($date ?? '') || !isset($exchange_rates[$currency ?? ''])) {
            http_response_code(400);
            exit(json_encode(['error' => t('Missing parameters')]));
        }
                
        $rateDate=($specificRate!==null && (float)$old['exchange_rate']===$specificRate && $old['rate_currency']===$rateCurrency) ? $old['exchange_rate_date'] : null;
        $query = "UPDATE cf_entries SET description = ?, amount = ?, currency =?, type = ?, date = ?, category = ?, macrocategory = ?, source = ?, note = ?, exchange_rate = ?, rate_currency = ?, exchange_rate_date = ? WHERE id = ? AND user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('sdsssssssdssii', $description, $amount, $currency, $type, $date, $category, $macrocategory, $source, $note, $specificRate, $rateCurrency, $rateDate, $id, $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'id'            => $id,
                    'description'   => $description,
                    'type'          => $type,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'date'          => $date,
                    'category'      => $category,
                    'macrocategory' => $macrocategory,
                    'source'        => $source,
                    'note'          => $note,
                    'exchange_rate' => $specificRate,
                    'rate_currency' => $rateCurrency
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => t('Update failed')]);
        }
        break;

    case 'fetch':
    case 'search':
        $offset=max(0,(int)requestString($_GET,'offset','0'));
        $limit=max(1,min(100,(int)requestString($_GET,'limit','10')));
        [$where,$types,$params]=entryWhere($filters,(int)$_SESSION['user_id']);
        $query='SELECT * FROM cf_entries WHERE '.$where.' ORDER BY '.entryOrder($filters).' LIMIT ? OFFSET ?';
        $types.='ii';$params[]=$limit+1;$params[]=$offset;
        $stmt=$conn->prepare($query);$stmt->bind_param($types,...$params);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['transactions'=>array_slice($rows,0,$limit),'hasMore'=>count($rows)>$limit]);break;

    default:
        http_response_code(400);
        echo json_encode(['error' => t('Invalid action')]);
}
?>