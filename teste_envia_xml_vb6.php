<?php
header('Content-Type: text/plain; charset=utf-8');

$caminhoXml = 'C:\\WDS Comercial\\NFCeGerada\\2026\\09\\29\\6014-assinado.xml';
if (!file_exists($caminhoXml)) { exit("Arquivo nao existe\n"); }

$xmlAssinado = file_get_contents($caminhoXml);
echo "Enviando XML do VB6 (6014) via COM do PHP...\n";
echo "Tamanho: " . strlen($xmlAssinado) . " bytes\n\n";

try {
    $com = new COM('NFCex.spdNFCeX');
    $base = 'C:\\WDS Comercial\\';
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
    $com->ValidarEsquemaAntesEnvio = false;
    $com->TipoCertificado = 0;
    $com->CaminhoCertificado = 'C:\\WDS Comercial\\certificado\\cliente.pfx';
    $com->SenhaCertificado = '123';
    $com->ArquivoServidoresHom = $base . 'nfceServidoresHom.ini';
    $com->ArquivoServidoresProd = $base . 'nfceServidoresProd.ini';

    $retorno = $com->EnviarNFSincrono('1', $xmlAssinado, false);
    echo "Retorno:\n" . $retorno . "\n\n";

    if (preg_match('/<cStat>(\d+)<\/cStat>/', $retorno, $m)) echo "cStat: " . $m[1] . "\n";
    if (preg_match('/<xMotivo>([^<]*)<\/xMotivo>/', $retorno, $m)) echo "xMotivo: " . $m[1] . "\n";
} catch (Throwable $t) {
    echo "ERRO: " . $t->getMessage() . "\n";
}