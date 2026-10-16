<?php
header('Content-Type: text/plain; charset=utf-8');

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
    $com->TipoCertificado = 0;
    $com->CaminhoCertificado = 'C:\\WDS Comercial\\certificado\\cliente.pfx';
    $com->SenhaCertificado = '123';
    $com->ArquivoServidoresHom = $base . 'nfceServidoresHom.ini';

    echo "=== Teste: StatusDoServico ===\n\n";
    $inicio = microtime(true);

    try {
        $retorno = $com->StatusDoServico;
        $tempo = round((microtime(true) - $inicio) * 1000);

        echo "OK ({$tempo}ms)\n";
        echo "Tamanho: " . strlen($retorno) . " bytes\n\n";
        echo "Conteudo:\n";
        echo $retorno . "\n\n";

        // Parse
        if (preg_match('/<cStat>(\d+)<\/cStat>/', $retorno, $m)) {
            echo "cStat: " . $m[1] . "\n";
        }
        if (preg_match('/<xMotivo>([^<]*)<\/xMotivo>/', $retorno, $m)) {
            echo "xMotivo: " . $m[1] . "\n";
        }
        if (preg_match('/<tMed>(\d+)<\/tMed>/', $retorno, $m)) {
            echo "Tempo medio SEFAZ: " . $m[1] . " segundos\n";
        }
    } catch (Throwable $t) {
        echo "FALHOU: " . $t->getMessage() . "\n";
    }

} catch (Throwable $t) {
    echo "ERRO FATAL: " . $t->getMessage() . "\n";
}