<?php
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = new PDO("mysql:dbname=controlfacil;host=127.0.0.1;charset=latin1", 'root', 'w31d48s38');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $t) {
    die("Erro conexao: " . $t->getMessage() . "\n");
}

$sql = $pdo->query("SELECT numero_nfce, xml_autorizado FROM tab_nfce 
                    WHERE emp_codigo=1 AND status_nfce=3 AND xml_autorizado IS NOT NULL 
                    ORDER BY numero_nfce DESC LIMIT 1");
$row = $sql->fetch(PDO::FETCH_ASSOC);
if (!$row) { die("Nenhuma NFC-e autorizada\n"); }

$num = $row['numero_nfce'];
$xml = $row['xml_autorizado'];
echo "NFC-e: $num (" . strlen($xml) . " bytes)\n\n";

$com = new COM('NFCex.spdNFCeX');
$base = 'C:\\WDS Comercial\\';

// ⚠️ ORDEM IMPORTA: servidores ANTES de tudo
$com->ArquivoServidoresHom  = $base . 'nfceServidoresHom.ini';
$com->ArquivoServidoresProd = $base . 'nfceServidoresProd.ini';

// Confere o que foi setado
echo "ArquivoServidoresHom:  [" . $com->ArquivoServidoresHom . "]\n";
echo "ArquivoServidoresProd: [" . $com->ArquivoServidoresProd . "]\n";
echo "Arquivo existe (Hom)? " . (file_exists($base . 'nfceServidoresHom.ini') ? 'SIM' : 'NAO') . "\n";
echo "Arquivo existe (Prod)? " . (file_exists($base . 'nfceServidoresProd.ini') ? 'SIM' : 'NAO') . "\n\n";

// Demais configs
$com->DiretorioTemplates       = $base . 'Templates\\vm60\\';
$com->DiretorioEsquemas        = $base . 'Esquemas\\vm60\\';
$com->DiretorioLog             = $base . 'NFCeGerada\\Log\\';
$com->DiretorioLogErro         = $base . 'NFCeGerada\\Erros\\';
$com->DiretorioTemporario      = $base . 'NFCeGerada\\Temp\\';
$com->DiretorioXmlDestinatario = $base . 'NFCeGerada\\XmlDestinatario\\';
$com->ConfigurarSoftwareHouse('14234584000174', '6f8547fdd9dff39473e2cb42a7568907');
$com->Ambiente = 2;
$com->UF = 'MT';
$com->CNPJ = '58717761000149';
$com->TipoCertificado = 0;
$com->CaminhoCertificado = $base . 'certificado\\cliente.pfx';
$com->SenhaCertificado = '123';

// Token
$sql = $pdo->query("SELECT pnfce_idtoken_nfce, pnfce_token_nfce FROM param_nfce WHERE emp_codigo=1 LIMIT 1");
$param = $sql->fetch(PDO::FETCH_ASSOC);
$com->IdTokenNFCe = $param['pnfce_idtoken_nfce'] ?? '';
$com->TokenNFCe   = $param['pnfce_token_nfce'] ?? '';

echo "=== Tentando imprimir ===\n";
try {
    $com->ImprimirDanfce('1', $xml, '', '');
    echo "OK\n";
} catch (Throwable $t) {
    echo "FALHOU: " . $t->getMessage() . "\n";
}