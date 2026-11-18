<?php
function entryFilters(array $request,string $start,string $end): array {
    $filters=[];
    foreach(['query','category','macrocategory','type','currency','amount_min','amount_max','amount_mode','source','has_note','sort'] as $key) $filters[$key]=trim(requestString($request,$key));
    $filters['start_date']=requestString($request,'start_date',$start);
    $filters['end_date']=requestString($request,'end_date',$end);
    if (!validDate($filters['start_date']) || !validDate($filters['end_date']) || $filters['start_date']>$filters['end_date']) throw new InvalidArgumentException('Invalid date range.');
    foreach(['amount_min','amount_max'] as $key) {
        $filters[$key]=str_replace(',','.',$filters[$key]);
        if ($filters[$key]!=='' && (!is_numeric($filters[$key]) || !is_finite((float)$filters[$key]) || (float)$filters[$key]<0)) throw new InvalidArgumentException('Invalid amount range.');
    }
    if ($filters['amount_min']!=='' && $filters['amount_max']!=='' && (float)$filters['amount_min']>=(float)$filters['amount_max']) throw new InvalidArgumentException('Invalid amount range.');
    return $filters;
}
function entryWhere(array $filters,int $uid): array {
    $where=['user_id=?','date BETWEEN ? AND ?'];$types='iss';$params=[$uid,$filters['start_date'],$filters['end_date']];
    foreach(['category','macrocategory','currency','source'] as $field) if ($filters[$field]!=='') {$where[]="$field=?";$types.='s';$params[]=$filters[$field];}
    if (in_array($filters['type'],['Expense','Income'],true)) {$where[]='type=?';$types.='s';$params[]=$filters['type'];}
    if ($filters['query']!=='') {
        $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$filters['query']).'%';$terms=[];
        foreach(['description','category','macrocategory','note','source','CAST(amount AS CHAR)'] as $field) {$terms[]="$field LIKE ? ESCAPE '!'";$types.='s';$params[]=$term;}
        $where[]='('.implode(' OR ',$terms).')';
    }
    $value=$filters['amount_mode']==='original' ? 'amount' : generateCurrencyCases();
    foreach(['amount_min'=>'>','amount_max'=>'<'] as $key=>$operator) if ($filters[$key]!=='') {$where[]="$value $operator ?";$types.='d';$params[]=(float)$filters[$key];}
    if ($filters['has_note']==='1') $where[]="COALESCE(note,'')<>''";
    return [implode(' AND ',$where),$types,$params];
}
function entryOrder(array $filters): string {
    switch($filters['sort']) {
        case 'oldest':return 'date ASC,id ASC';
        case 'amount_desc':return generateCurrencyCases().' DESC,id DESC';
        case 'amount_asc':return generateCurrencyCases().' ASC,id DESC';
        default:return 'date DESC,id DESC';
    }
}
