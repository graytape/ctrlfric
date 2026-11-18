<?php
require __DIR__.'/conf.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if (empty($_SESSION['user_id'])) {http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit;}
try {
    $source=$_GET['currency'] ?? '';
    if (!is_string($source) || !in_array($source,$currency_preferences['enabled'],true)) throw new RuntimeException('Invalid currency.');
    $fresh=frankfurterRates($_SESSION['currency'],[$source]);
    echo json_encode(['rate'=>$fresh['rates'][$source],'date'=>$fresh['dates'][$source] ?? null,'base'=>$source,'quote'=>$_SESSION['currency']]);
} catch (Throwable $e) {error_log((string)$e); http_response_code(503); echo json_encode(['error'=>t('Rates unavailable. Please try again.')]);}
