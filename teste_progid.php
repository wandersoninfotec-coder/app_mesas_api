<?php
header('Content-Type: application/json; charset=utf-8');

if (!class_exists('COM')) {
    echo json_encode(['erro' => 'Extensão COM não habilitada']);
    exit;
}

$resultado = ['php_ok' => true];

try {
    $obj = new COM('NFCex.spdNFCeX');
    $resultado['instancia'] = 'OK';
    $resultado['classe']    = get_class($obj);
    unset($obj);
} catch (Throwable $t) {
    $resultado['instancia'] = 'FALHOU';
    $resultado['erro']      = $t->getMessage();
}

echo json_encode($resultado, JSON_PRETTY_PRINT);