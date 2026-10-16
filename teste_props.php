<?php
header('Content-Type: application/json; charset=utf-8');
$obj = new COM('NFCex.spdNFCeX');

$resultado = ['props' => []];

$props = [
    // Certificado
    'TipoCertificado', 'NomeCertificado', 'Certificado', 'CertificadoDigital',
    'Repositorio', 'SenhaCertificado', 'Senha', 'PINCODE', 'PinCode',
    'ArquivoCertificado', 'CaminhoCertificado',
    // Ambiente e emissor
    'Ambiente', 'UF', 'CNPJ', 'VersaoManual', 'Versao', 'VersaoSchema',
    'ValidarEsquema', 'ValidarEsquemaAntesEnvio',
    // Servidores SEFAZ
    'ArquivoServidoresHom', 'ArquivoServidoresProd',
    'ConexaoSegura', 'TimeOut',
    // Software House (confirmação)
    'CNPJSoftwareHouse', 'Token',
];

foreach ($props as $p) {
    try {
        $v = $obj->$p;
        $resultado['props'][$p] = ['existe' => true, 'valor' => (string)$v];
    } catch (Throwable $t) {
        $resultado['props'][$p] = ['existe' => false];
    }
}

$propsEnc = ['Enconding','Encoding','CharSet','CharacterSet','Codificacao','XMLEncoding','Encode'];
foreach ($propsEnc as $p) {
    try { $resultado['encoding'][$p] = ['existe'=>true,'valor'=>(string)$obj->$p]; }
    catch (Throwable $t) { $resultado['encoding'][$p] = ['existe'=>false]; }
}

// Métodos de certificado
$metodos = [
    'ConfigurarCertificado', 'CarregarCertificado', 'SetCertificado',
    'ConfigurarSoftwareHouse',
];
foreach ($metodos as $m) {
    $resultado['metodos'][$m] = method_exists($obj, $m);
}

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);