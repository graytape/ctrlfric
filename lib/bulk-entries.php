<?php
function bulkEntries(mysqli $conn,int $uid,array $request): array {
    if (!hash_equals($_SESSION['entries_csrf'] ?? '',requestString($request,'csrf')) || empty($_SESSION['entries_csrf'])) throw new InvalidArgumentException('Reload the page and try again.');
    $ids=$request['ids'] ?? [];
    if (!is_array($ids) || !$ids || count($ids)>1000) throw new InvalidArgumentException('Select between 1 and 1000 entries.');
    foreach($ids as $id) if (!is_string($id) || !preg_match('/^[0-9]+$/D',$id) || (int)$id<=0) throw new InvalidArgumentException('Invalid selection.');
    $ids=array_values(array_unique(array_map('intval',$ids)));$operation=requestString($request,'operation');
    if (!in_array($operation,['delete','category','macrocategory','type','export'],true)) throw new InvalidArgumentException('Invalid action');
    $value=requestString($request,'value');global $categories,$macrocategories;
    if (($operation==='category' && !in_array($value,$categories,true)) || ($operation==='macrocategory' && !in_array($value,$macrocategories,true)) || ($operation==='type' && !in_array($value,['Expense','Income'],true))) throw new InvalidArgumentException('Invalid action');
    $marks=implode(',',array_fill(0,count($ids),'?'));$types='i'.str_repeat('i',count($ids));$params=array_merge([$uid],$ids);
    $conn->begin_transaction();
    try {
        $stmt=$conn->prepare("SELECT * FROM cf_entries WHERE user_id=? AND id IN ($marks) ORDER BY date DESC,id DESC FOR UPDATE");$stmt->bind_param($types,...$params);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($rows)!==count($ids)) throw new InvalidArgumentException('Invalid selection.');
        if ($operation==='delete') {$query="DELETE FROM cf_entries WHERE user_id=? AND id IN ($marks)";}
        elseif ($operation!=='export') {$query="UPDATE cf_entries SET $operation=? WHERE user_id=? AND id IN ($marks)";$types='s'.$types;array_unshift($params,$value);}
        if (isset($query)) {$stmt=$conn->prepare($query);$stmt->bind_param($types,...$params);$stmt->execute();}
        $conn->commit();return $rows;
    } catch (Throwable $e) {$conn->rollback();throw $e;}
}
