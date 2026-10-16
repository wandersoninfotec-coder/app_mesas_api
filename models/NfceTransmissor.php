<?php
/**
 * NfceTransmissor.php
 *
 * Chama o componente TecnoSpeed via COM para:
 *   1. ConverterLoteParaXml(TXT) → XML
 *   2. AssinarNota(XML)          → XML assinado
 *   3. EnviarNFSincrono(lote, XMLassinado, false) → retorno SEFAZ
 *   4. Gravar retorno em tab_nfce (chave, protocolo, status, XML)
 *
 * Requer:
 *   - PHP Windows + php_com_dotnet
 *   - Componente TecnoSpeed instalado/registrado
 *   - Certificado A1 acessível
 */

class NfceTransmissor extends model {

    const PROGID_COMPONENTE = 'NFCex.spdNFCeX';
    const LK_TXT_DATA_SET   = 1;   // ← confirma que é exatamente isso
    const LOTE_NFCE         = '1';


    // Status em tab_nfce
    const STATUS_PENDENTE    = 0;
    const STATUS_VALIDADA    = 1;
    const STATUS_AUTORIZADA  = 3;
    const STATUS_REJEITADA   = 4;
    const STATUS_CONTINGENCIA= 8;

    // SEFAZ
    const CSTAT_AUTORIZADA   = '100';
    const CSTAT_DUPLICIDADE  = '204';

    private $empresa;
    private $numeroNfce;
    private $com = null;
    private $versaoEsquema;
    private $logPath;
    private $debug = true;

    private $cnpjEmitente = '';
    private $razaoEmitente = '';
    private $ufEmitente = '';

    public function __construct() {
        parent::__construct();
        $this->logPath = __DIR__ . '/../logs/nfce_transmissor.log';
    }


    // ============================================================
    // MÉTODO PRINCIPAL
    // ============================================================
    public function transmitir($empresa, $numeroNfce, $caminhoTxt, $contingencia = false) {
        try {
            $this->empresa    = (int)$empresa;
            $this->numeroNfce = (int)$numeroNfce;

            $this->carregarConfig();

            $this->log("=== INÍCIO TRANSMISSÃO ===");
            $this->log("Empresa: {$this->empresa} | NFC-e: {$this->numeroNfce}");
            $this->log("TXT: $caminhoTxt");

            if (!file_exists($caminhoTxt)) {
                return $this->erro("TXT não encontrado: $caminhoTxt");
            }

            // 1. Instanciar componente
            if (!$this->inicializarCom()) {
                return $this->erro("Falha ao instanciar componente TecnoSpeed");
            }

            // 2. Converter TXT → XML (passa CAMINHO do arquivo, igual ao VB6)
            $caminhoTxt = str_replace('/', '\\', $caminhoTxt);
            $xml = $this->com->ConverterLoteParaXml($caminhoTxt, self::LK_TXT_DATA_SET, $this->versaoEsquema);
            if (empty($xml)) {
                return $this->erro("ConverterLoteParaXml retornou vazio");
            }

            // LOG — inspecionar XML pré-assinatura
            $hexPre = implode(' ', str_split(bin2hex(substr($xml, 0, 40)), 2));
            $this->log("XML pre-assinar: " . strlen($xml) . " bytes");
            $this->log("XML pre-assinar primeiros 40 bytes HEX: " . $hexPre);
            $this->log("XML pre-assinar primeiros 100 chars: " . substr($xml, 0, 100));

            // 3. Assinar o XML (SEM adicionar <xml> — VB6 não usa e SEFAZ aceita)
            $xmlAssinado = $this->com->AssinarNota($xml);
            if (empty($xmlAssinado)) {
                return $this->erro("AssinarNota retornou vazio");
            }

            // LOG — inspecionar XML pós-assinatura
            $hexPos = implode(' ', str_split(bin2hex(substr($xmlAssinado, 0, 40)), 2));
            $this->log("XML pos-assinar: " . strlen($xmlAssinado) . " bytes");
            $this->log("XML pos-assinar primeiros 40 bytes HEX: " . $hexPos);
            $this->log("XML assinado (" . strlen($xmlAssinado) . " bytes)");

            // 4. Extrair chave de acesso
            $chave = $this->extrairChave($xmlAssinado);
            if (empty($chave)) {
                return $this->erro("Não foi possível extrair chave de acesso do XML");
            }
            $this->log("Chave: $chave");

             // ============ MODO CONTINGÊNCIA ============
            if ($contingencia) {
                $this->log("⚠️ MODO CONTINGÊNCIA — XML será assinado mas NÃO transmitido");

                // Salva em disco (pasta do dia + XmlDestinatario)
                $pastaDia = 'C:\\WDS Comercial\\NFCeGerada\\' . date('Y') . '\\' . date('m') . '\\' . date('d');
                if (!is_dir($pastaDia)) @mkdir($pastaDia, 0777, true);
                file_put_contents($pastaDia . '\\' . $this->numeroNfce . '-assinado.xml', $xmlAssinado);

                // Atualiza banco: status = 8 (contingência), grava XML assinado + chave
                $sql = $this->db->prepare("UPDATE tab_nfce
                    SET xml_assinado = :xml,
                        chave_acesso  = :chave,
                        status_nfce   = 8
                    WHERE emp_codigo = :emp AND numero_nfce = :num");
                $sql->bindValue(':xml', $xmlAssinado);
                $sql->bindValue(':chave', $chave);
                $sql->bindValue(':emp', $this->empresa);
                $sql->bindValue(':num', $this->numeroNfce);
                $sql->execute();

                $this->log("NFC-e em contingência salva (status=8)");

                return [
                    'sucesso'      => true,
                    'status'       => 8,
                    'cStat'        => '',
                    'protocolo'    => '',
                    'chave'        => $chave,
                    'serie'        => $this->serie ?? 1,
                    'ambiente'     => $this->ambiente ?? 2,
                    'contingencia' => true,
                    'mensagem'     => 'Emitida em contingência — aguardando transmissão',
                ];
            }

            // ============ MODO NORMAL ============
            // 5. Gravar XML assinado e marcar status validada
            $this->gravarXmlAssinado($xmlAssinado, $chave);

            $pastaXml = 'C:\\WDS Comercial\\NFCeGerada\\' . date('Y') . '\\' . date('m') . '\\' . date('d');
            if (!is_dir($pastaXml)) @mkdir($pastaXml, 0777, true);
            $arquivoAssinado = $pastaXml . '\\' . $this->numeroNfce . '-assinado.xml';
            file_put_contents($arquivoAssinado, $xmlAssinado);
            $this->log("XML assinado salvo em disco: $arquivoAssinado");

            // 6. Transmitir para SEFAZ
            $xmlRetorno = $this->com->EnviarNFSincrono(self::LOTE_NFCE, $xmlAssinado, false);
            if (empty($xmlRetorno)) {
                return $this->erro("EnviarNFSincrono retornou vazio");
            }
            $this->log("Retorno SEFAZ recebido (" . strlen($xmlRetorno) . " bytes)");

            // 7. Salvar retorno em disco (igual ao VB6)
            $arquivoRetorno = $pastaXml . '\\' . $this->numeroNfce . '-autorizado_ret.xml';
            file_put_contents($arquivoRetorno, $xmlRetorno);
            $this->log("Retorno SEFAZ salvo em disco: $arquivoRetorno");

            // 8. Interpretar retorno
            return $this->tratarRetorno($xmlRetorno, $xmlAssinado);

        } catch (Throwable $t) {
            $this->log("ERRO FATAL: " . $t->getMessage());
            $this->log("Arquivo: " . $t->getFile() . ":" . $t->getLine());
            return $this->erro("Erro ao transmitir: " . $t->getMessage());
        } finally {
            $this->com = null;
        }
    }

    // ============================================================
    // CONFIGURAÇÃO
    // ============================================================

    private function carregarConfig() {
        // Versão do esquema (já existente)
        $sql = $this->db->prepare("SELECT pnfce_versao_esquema, pnfce_ambiente
                                FROM param_nfce WHERE emp_codigo = :emp LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->execute();
        $p = $sql->fetch(PDO::FETCH_ASSOC);

        if ($p) {
            $this->versaoEsquema = $p['pnfce_versao_esquema'] ?? 'pl_010d';
            $this->ambiente      = (int)($p['pnfce_ambiente'] ?? 2);
        }

        // ✅ Dados do emitente (CNPJ, razão, UF) — vem da tabempresa
        try {
            $sql = $this->db->prepare("SELECT cnpj, razao, estado
                                    FROM tabempresa WHERE codigo = :emp LIMIT 1");
            $sql->bindValue(':emp', $this->empresa);
            $sql->execute();
            $emp = $sql->fetch(PDO::FETCH_ASSOC);

            if ($emp) {
                $this->cnpjEmitente  = preg_replace('/\D/', '', $emp['cnpj'] ?? '');
                $this->razaoEmitente = $emp['razao'] ?? '';
                $this->ufEmitente    = $emp['estado'] ?? 'MT';
            }
        } catch (Throwable $t) {
            $this->log("Aviso: não carregou emitente de tabempresa: " . $t->getMessage());
        }

        $this->log("Config: esquema={$this->versaoEsquema} ambiente={$this->ambiente} CNPJ={$this->cnpjEmitente} UF={$this->ufEmitente}");
    }

   private function inicializarCom() {
        if (!class_exists('COM')) {
            $this->log("ERRO: extensão COM não disponível");
            return false;
        }

        try {
            $this->com = new COM(self::PROGID_COMPONENTE);
            $this->log("Componente instanciado: " . self::PROGID_COMPONENTE);

            $base   = 'C:\\WDS Comercial\\';
            $versao = 'vm60';

            // Diretórios
            $this->com->DiretorioTemplates       = $base . 'Templates\\'   . $versao . '\\';
            $this->com->DiretorioEsquemas        = $base . 'Esquemas\\'    . $versao . '\\';
            $this->com->DiretorioLog             = $base . 'NFCeGerada\\Log\\';
            $this->com->DiretorioLogErro         = $base . 'NFCeGerada\\Erros\\';
            $this->com->DiretorioTemporario      = $base . 'NFCeGerada\\Temp\\';
            $this->com->DiretorioXmlDestinatario = $base . 'NFCeGerada\\XmlDestinatario\\';

            // Software House (licença TecnoSpeed)
            $this->com->ConfigurarSoftwareHouse('14234584000174', '6f8547fdd9dff39473e2cb42a7568907');
            $this->log("Software House configurada");

            // Ambiente / Emitente
            $this->com->Ambiente = 2;
            $this->com->UF       = 'MT';
            $this->com->CNPJ     = '58717761000149';
            $this->com->ValidarEsquemaAntesEnvio = false;

            // Certificado
            $this->com->TipoCertificado    = 0;
            $this->com->CaminhoCertificado = 'C:\\WDS Comercial\\certificado\\cliente.pfx';
            $this->com->SenhaCertificado   = '123';
            $this->com->NomeCertificado    = 'CN=TRIGOS MT PAES ARTESANAIS LTDA:58717761000149, OU=videoconferencia, OU=RFB e-CNPJ A1, OU=Secretaria da Receita Federal do Brasil - RFB, OU=03643376000102, L=RONDONOPOLIS, S=MT, O=ICP-Brasil, C=BR';

            // Servidores SEFAZ
            $this->com->ArquivoServidoresHom  = $base . 'nfceServidoresHom.ini';
            $this->com->ArquivoServidoresProd = $base . 'nfceServidoresProd.ini';

            // Garante pastas de saída
            foreach (['Log','Erros','Temp','XmlDestinatario'] as $p) {
                $d = $base . 'NFCeGerada\\' . $p;
                if (!is_dir($d)) @mkdir($d, 0777, true);
            }

            $this->log("Configurado: Ambiente={$this->com->Ambiente} UF={$this->com->UF} CNPJ={$this->com->CNPJ}");
            $this->log("Certificado: " . $this->com->CaminhoCertificado);
            $this->log("Templates: " . $this->com->DiretorioTemplates);

            return true;

        } catch (Throwable $t) {
            $this->log("ERRO: " . $t->getMessage());
            $this->log("Arquivo: " . $t->getFile() . ":" . $t->getLine());
            return false;
        }
    }

    private function carregarParametrosFiscais() {
        $config = [
            'uf'              => 'MT',
            'cnpj'            => '',
            'ambiente'        => 2,
            'versao'          => '4.00',
            'esquema'         => 'pl_010d',
            'validaesquema'   => false,
            'tipoCertificado' => 0,
            'caminhoCert'     => '',
            'senhaCert'       => '',
            'nomeCert'        => '',
            'pastaBase'       => 'C:\\WDS Comercial\\',
            'versaoTemplate'  => 'vm60',
            'arquivoHom'      => '',
            'arquivoProd'     => '',
        ];

        // 1. Lê do banco (param_nfce)
        $sql = $this->db->prepare("SELECT * FROM param_nfce WHERE emp_codigo = :emp LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->execute();
        $row = $sql->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (!empty($row['pnfce_versao']))                   $config['versao']        = $row['pnfce_versao'];
            if (!empty($row['pnfce_versao_esquema']))           $config['esquema']       = $row['pnfce_versao_esquema'];
            if (isset($row['pnfce_ambiente']))                  $config['ambiente']      = (int)$row['pnfce_ambiente'];
            if (!empty($row['pnfce_pasta_arquivos']))           $config['pastaBase']     = $row['pnfce_pasta_arquivos'];
            $config['validaesquema'] = (strtolower($row['pnfce_validaesquema_antesenvio'] ?? '') === 'sim');
        }

        // 2. Lê do arquivo INI (nfceconfig.ini)
        $iniPath = $config['pastaBase'] . 'nfceconfig.ini';
        if (file_exists($iniPath)) {
            $ini = parse_ini_file($iniPath, true);
            if (isset($ini['NFCE'])) {
                $n = $ini['NFCE'];
                if (!empty($n['UF']))                 $config['uf']              = $n['UF'];
                if (!empty($n['CNPJ']))               $config['cnpj']            = $n['CNPJ'];
                if (isset($n['Ambiente']))            $config['ambiente']        = (int)$n['Ambiente'];
                if (!empty($n['TipoCertificado']))    $config['tipoCertificado'] = ($n['TipoCertificado'] === 'File') ? 0 : 1;
                if (!empty($n['NomeCertificado']))    $config['nomeCert']        = $n['NomeCertificado'];
                if (!empty($n['CaminhoCertificado'])) $config['caminhoCert']     = $n['CaminhoCertificado'];
                if (!empty($n['Senha']))              $config['senhaCert']       = $n['Senha'];
                if (!empty($n['ArquivoServidoresHom']))  $config['arquivoHom']   = $config['pastaBase'] . $n['ArquivoServidoresHom'];
                if (!empty($n['ArquivoServidoresProd'])) $config['arquivoProd']  = $config['pastaBase'] . $n['ArquivoServidoresProd'];
            }
        }

        // 3. Fallbacks específicos (não estão no INI nem no banco)
        if (empty($config['caminhoCert'])) {
            $config['caminhoCert'] = $config['pastaBase'] . 'certificado\\cliente.pfx';
        }
        if (empty($config['arquivoHom'])) {
            $config['arquivoHom'] = $config['pastaBase'] . 'nfceServidoresHom.ini';
        }
        if (empty($config['arquivoProd'])) {
            $config['arquivoProd'] = $config['pastaBase'] . 'nfceServidoresProd.ini';
        }

        $this->log("Config carregada: UF={$config['uf']} Ambiente={$config['ambiente']} Esquema={$config['esquema']}");
        return $config;
    }
    // ============================================================
    // PERSISTÊNCIA
    // ============================================================

    private function gravarXmlAssinado($xmlAssinado, $chave) {
        $sql = $this->db->prepare("UPDATE tab_nfce
            SET xml_assinado = :xml,
                chave_acesso  = :chave,
                status_nfce   = :status
            WHERE emp_codigo = :emp AND numero_nfce = :num");
        $sql->bindValue(':xml', $xmlAssinado);
        $sql->bindValue(':chave', $chave);
        $sql->bindValue(':status', self::STATUS_VALIDADA);
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $this->log("XML assinado gravado, status=" . self::STATUS_VALIDADA);
    }

    private function tratarRetorno($xmlRetorno, $xmlAssinado) {
        $cStatLote   = $this->getTag('cStat',   $xmlRetorno);
        $xMotivoLote = $this->getTag('xMotivo', $xmlRetorno);

        $cStat   = $cStatLote;
        $xMotivo = $xMotivoLote;
        $nProt   = '';
        $digVal  = '';

        if (preg_match('/<protNFe[^>]*>(.*?)<\/protNFe>/s', $xmlRetorno, $m)) {
            $prot = $m[1];
            if (preg_match('/<cStat>(\d+)<\/cStat>/', $prot, $mm))       $cStat   = $mm[1];
            if (preg_match('/<xMotivo>([^<]*)<\/xMotivo>/', $prot, $mm)) $xMotivo = $mm[1];
            if (preg_match('/<nProt>(\d+)<\/nProt>/', $prot, $mm))       $nProt   = $mm[1];
            if (preg_match('/<digVal>([^<]*)<\/digVal>/', $prot, $mm))   $digVal  = $mm[1];
        }

        $this->log("Retorno: cStatLote=$cStatLote | cStat=$cStat | xMotivo=$xMotivo | nProt=$nProt");

        if ($cStat === self::CSTAT_AUTORIZADA) {
            $this->atualizarAutorizada($nProt, $digVal, $xmlRetorno);
            $this->salvarXmlAutorizado($xmlRetorno, $nProt, $xmlAssinado);

            // Pega a chave e a série para devolver ao frontend
            $chave = '';
            if (preg_match('/Id="NFe(\d{44})"/', $xmlAssinado, $m)) $chave = $m[1];

            return [
                'sucesso'       => true,
                'status'        => self::STATUS_AUTORIZADA,
                'cStat'         => $cStat,
                'protocolo'     => $nProt,
                'chave'         => $chave,
                'serie'         => $this->serie ?? 1,
                'ambiente'      => $this->ambiente ?? 2,
                'autorizada_em' => date('Y-m-d H:i:s'),
                'mensagem'      => $xMotivo,
            ];
        }

        if ($cStat === self::CSTAT_DUPLICIDADE) {
            $this->atualizarStatus(self::STATUS_REJEITADA, $cStat, $xMotivo);
            return [
                'sucesso'   => false,
                'status'    => self::STATUS_REJEITADA,
                'cStat'     => $cStat,
                'mensagem'  => "Duplicidade: $xMotivo",
            ];
        }

        $this->atualizarStatus(self::STATUS_REJEITADA, $cStat, $xMotivo);
        return [
            'sucesso'   => false,
            'status'    => self::STATUS_REJEITADA,
            'cStat'     => $cStat,
            'mensagem'  => "SEFAZ ($cStat): $xMotivo",
        ];
    }

    private function salvarXmlAutorizado($xmlRetorno, $nProt, $xmlAssinado) {
        // Extrai o <protNFe>...</protNFe> do retorno
        if (!preg_match('/<protNFe\b[^>]*>.*?<\/protNFe>/s', $xmlRetorno, $mp)) {
            $this->log("Aviso: <protNFe> nao encontrado no retorno");
            return;
        }
        $protNFe = $mp[0];

        // Monta o nfeProc (XML destinatário) = <NFe> + <protNFe>
        $nfeProc = '<?xml version="1.0" encoding="UTF-8"?>'
                . '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'
                . $xmlAssinado
                . $protNFe
                . '</nfeProc>';

        $chave = '';
        if (preg_match('/Id="NFe(\d{44})"/', $xmlAssinado, $mm)) $chave = $mm[1];

        $pastaDia = 'C:\\WDS Comercial\\NFCeGerada\\' . date('Y') . '\\' . date('m') . '\\' . date('d');
        if (!is_dir($pastaDia)) @mkdir($pastaDia, 0777, true);

        // 1) <numero>-autorizado.xml na pasta do dia
        $arquivoAutorizado = $pastaDia . '\\' . $this->numeroNfce . '-autorizado.xml';
        file_put_contents($arquivoAutorizado, $nfeProc);
        $this->log("XML autorizado (nfeProc) salvo: $arquivoAutorizado");

        // 2) <chave>-nfce.xml em XmlDestinatario
        if (!empty($chave)) {
            $pastaDest = 'C:\\WDS Comercial\\NFCeGerada\\XmlDestinatario';
            if (!is_dir($pastaDest)) @mkdir($pastaDest, 0777, true);
            $arquivoDest = $pastaDest . '\\' . $chave . '-nfce.xml';
            file_put_contents($arquivoDest, $nfeProc);
            $this->log("XML destinatario salvo: $arquivoDest");
        }

        // ============ 2. GRAVA NA COLUNA tab_nfce.xml_autorizado ============
        try {
            $sql = $this->db->prepare("UPDATE tab_nfce
                SET xml_autorizado = :xml
                WHERE emp_codigo = :emp AND numero_nfce = :num");
            $sql->bindValue(':xml', $nfeProc);
            $sql->bindValue(':emp', $this->empresa);
            $sql->bindValue(':num', $this->numeroNfce);
            $sql->execute();

            $this->log("XML autorizado gravado em tab_nfce.xml_autorizado (" . strlen($nfeProc) . " bytes)");
        } catch (Throwable $t) {
            $this->log("ERRO ao gravar xml_autorizado no banco: " . $t->getMessage());
        }
    
    }

    private function atualizarAutorizada($nProt, $digVal, $xmlRetorno) {
        $sql = $this->db->prepare("UPDATE tab_nfce
            SET status_nfce                = :status,
                nro_protocolo_autorizacao  = :prot,
                nro_digval_transmissao     = :dig,
                xml_autorizado_retorno     = :xml
            WHERE emp_codigo = :emp AND numero_nfce = :num");
        $sql->bindValue(':status', self::STATUS_AUTORIZADA);
        $sql->bindValue(':prot', $nProt);
        $sql->bindValue(':dig', $digVal);
        $sql->bindValue(':xml', $xmlRetorno);
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $this->log("Status atualizado: AUTORIZADA ($nProt)");
    }

    private function atualizarStatus($status, $cStat, $xMotivo) {
        $sql = $this->db->prepare("UPDATE tab_nfce
            SET status_nfce = :status
            WHERE emp_codigo = :emp AND numero_nfce = :num");
        $sql->bindValue(':status', $status);
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $this->log("Status atualizado: $status ($cStat $xMotivo)");
    }

    // ============================================================
    // PARSE DE XML
    // ============================================================

    private function extrairChave($xml) {
        // Procura id="NFe<44digitos>"
        if (preg_match('/Id="NFe(\d{44})"/', $xml, $m)) {
            return $m[1];
        }
        return '';
    }

    private function extrairProtocolo($xml) {
        if (preg_match('/<nProt>(\d+)<\/nProt>/', $xml, $m)) {
            return $m[1];
        }
        return '';
    }

    private function getTag($tag, $xml) {
        if (preg_match('/<' . preg_quote($tag) . '>([^<]*)<\/' . preg_quote($tag) . '>/', $xml, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function erro($mensagem) {
        $this->log("ERRO: $mensagem");
        return ['sucesso' => false, 'mensagem' => $mensagem];
    }

    private function log($msg) {
        if (!$this->debug) return;
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        @file_put_contents($this->logPath, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
    }

    /**
     * Consulta o status do serviço da SEFAZ.
     * Retorna array com:
     *   - online (bool)   → true se cStat=107
     *   - cStat (string)
     *   - xMotivo (string)
     *   - tMed (int)      → tempo médio de resposta em segundos
     *   - mensagem (string) — em caso de erro na consulta
     */
    public function detectarStatusSefaz() {

         // ⚠️ DESCOMENTE PARA TESTAR CONTINGÊNCIA
        //return ['online' => false, 'cStat' => '108', 'xMotivo' => 'Teste', 'tMed' => 0, 'mensagem' => 'TESTE EM HOMOLOGACAO'];
        try {
            if (!$this->inicializarCom()) {
                return [
                    'online'   => false,
                    'cStat'    => '',
                    'xMotivo'  => 'Falha ao instanciar componente',
                    'tMed'     => 0,
                    'mensagem' => 'Falha ao instanciar componente TecnoSpeed',
                ];
            }

            $retorno = $this->com->StatusDoServico;
            $this->log("StatusDoServico: " . substr($retorno, 0, 200));

            $cStat   = '';
            $xMotivo = '';
            $tMed    = 0;

            if (preg_match('/<cStat>(\d+)<\/cStat>/', $retorno, $m))     $cStat   = $m[1];
            if (preg_match('/<xMotivo>([^<]*)<\/xMotivo>/', $retorno, $m)) $xMotivo = $m[1];
            if (preg_match('/<tMed>(\d+)<\/tMed>/', $retorno, $m))         $tMed    = (int)$m[1];

            // cStat 107 = Serviço em Operação
            $online = ($cStat === '107');

            return [
                'online'   => $online,
                'cStat'    => $cStat,
                'xMotivo'  => $xMotivo,
                'tMed'     => $tMed,
                'mensagem' => $xMotivo,
            ];

        } catch (Throwable $t) {
            $this->log("detectarStatusSefaz ERRO: " . $t->getMessage());
            return [
                'online'   => false,
                'cStat'    => '',
                'xMotivo'  => 'Erro na consulta',
                'tMed'     => 0,
                'mensagem' => 'Erro: ' . $t->getMessage(),
            ];
        } finally {
            $this->com = null;
        }
    }

    /**
     * Reprocessa uma NFC-e em contingência: pega o XML já assinado (do banco)
     * e transmite direto, sem regerar TXT nem assinar de novo.
     */
    public function transmitirXmlAssinado($empresa, $numeroNfce, $xmlAssinado) {
        try {
            $this->empresa    = (int)$empresa;
            $this->numeroNfce = (int)$numeroNfce;

            $this->carregarConfig();

            $this->log("=== REPROCESSANDO CONTINGÊNCIA #{$numeroNfce} ===");

            if (empty($xmlAssinado)) {
                return $this->erro("XML assinado vazio");
            }

            if (!$this->inicializarCom()) {
                return $this->erro("Falha ao instanciar componente");
            }

            // Transmite direto
            $xmlRetorno = $this->com->EnviarNFSincrono(self::LOTE_NFCE, $xmlAssinado, false);
            if (empty($xmlRetorno)) {
                return $this->erro("EnviarNFSincrono retornou vazio");
            }
            $this->log("Retorno SEFAZ recebido (" . strlen($xmlRetorno) . " bytes)");

            // Salva retorno em disco
            $pastaDia = 'C:\\WDS Comercial\\NFCeGerada\\' . date('Y') . '\\' . date('m') . '\\' . date('d');
            if (!is_dir($pastaDia)) @mkdir($pastaDia, 0777, true);
            file_put_contents($pastaDia . '\\' . $this->numeroNfce . '-autorizado_ret.xml', $xmlRetorno);

            // Trata retorno (atualiza banco com status 3 ou 4)
            return $this->tratarRetorno($xmlRetorno, $xmlAssinado);

        } catch (Throwable $t) {
            $this->log("ERRO no reprocessamento: " . $t->getMessage());
            return $this->erro("Erro: " . $t->getMessage());
        } finally {
            $this->com = null;
        }
    }

    /**
     * Cancela uma NFC-e autorizada.
     * @return array{sucesso: bool, cStat?: string, xMotivo?: string, protocolo?: string, mensagem: string}
     */
    public function cancelar($empresa, $numeroNfce, $justificativa, $usuario = '') {
        try {
            $this->empresa    = (int)$empresa;
            $this->numeroNfce = (int)$numeroNfce;
            $this->carregarConfig();

             // ✅ Sanitiza a justificativa: remove acentos problemáticos e bytes inválidos
            $justificativa = $this->sanitizarTexto($justificativa);

            // Validações
            if (strlen(trim($justificativa)) < 15) {
                return $this->erro("Justificativa deve ter no mínimo 15 caracteres");
            }

            // Busca dados da NFC-e
            $sql = $this->db->prepare("SELECT chave_acesso, nro_protocolo_autorizacao, status_nfce,
                                            data_emissao, hora_emissao
                                    FROM tab_nfce
                                    WHERE emp_codigo = :emp AND numero_nfce = :num LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':num', $numeroNfce);
            $sql->execute();
            $nfce = $sql->fetch(PDO::FETCH_ASSOC);

            if (!$nfce) return $this->erro("NFC-e não encontrada");
            if ((int)$nfce['status_nfce'] !== 3) return $this->erro("Só é possível cancelar NFC-e com status Autorizada (3)");
            if (empty($nfce['chave_acesso']) || empty($nfce['nro_protocolo_autorizacao'])) {
                return $this->erro("Chave ou protocolo não encontrados");
            }

            // Verifica prazo (30 minutos — padrão NFC-e MT)
            $dataAut = $nfce['data_emissao'] . ' ' . $nfce['hora_emissao'];
            $diffMin = (time() - strtotime($dataAut)) / 60;
            if ($diffMin > 30) {
                return $this->erro("Prazo de cancelamento expirado (" . round($diffMin) . " min). Limite: 30 minutos.");
            }

            if (!$this->inicializarCom()) return $this->erro("Falha ao instanciar componente");

            // Monta parâmetros (conforme VB6)
            $dhEvento = date('Y-m-d\TH:i:s');
            $fuso     = '-04:00';
            $nroLote  = '1';

            $this->log("=== CANCELAMENTO NFC-e #$numeroNfce ===");
            $this->log("Chave: {$nfce['chave_acesso']} | Protocolo: {$nfce['nro_protocolo_autorizacao']}");

            // Chama o componente
            $retorno = $this->com->CancelarNF(
                $nfce['chave_acesso'],
                $nfce['nro_protocolo_autorizacao'],
                $justificativa,
                $dhEvento,
                1,
                $fuso,
                $nroLote
            );

            if (empty($retorno)) return $this->erro("CancelarNF retornou vazio");

            $this->log("Retorno cancelamento: " . substr($retorno, 0, 400));

            // ✅ Extrai cStat do LOTE (raiz)
            $cStatLote   = '';
            $xMotivoLote = '';
            if (preg_match('/<cStat>(\d+)<\/cStat>/', $retorno, $m))       $cStatLote   = $m[1];
            if (preg_match('/<xMotivo>([^<]*)<\/xMotivo>/', $retorno, $m)) $xMotivoLote = $m[1];

            // ✅ Extrai cStat do EVENTO (dentro de <retEvento>)
            $cStat   = $cStatLote;
            $xMotivo = $xMotivoLote;
            $nProt   = '';

            if (preg_match('/<retEvento\b[^>]*>(.*?)<\/retEvento>/s', $retorno, $m)) {
                $retEv = $m[1];
                if (preg_match('/<cStat>(\d+)<\/cStat>/', $retEv, $mm))       $cStat   = $mm[1];
                if (preg_match('/<xMotivo>([^<]*)<\/xMotivo>/', $retEv, $mm)) $xMotivo = $mm[1];
                if (preg_match('/<nProt>(\d+)<\/nProt>/', $retEv, $mm))       $nProt   = $mm[1];
            }

            $this->log("Retorno: cStatLote=$cStatLote | cStatEvento=$cStat | xMotivo=$xMotivo | nProt=$nProt");

            // 135 = Evento registrado e vinculado a NF-e | 155 = Cancelamento fora de prazo
            $sucesso = in_array($cStat, ['135', '155']);

            if ($sucesso) {
                // ============ PASSO 1: Atualiza status (crítico) ============
                try {
                    $sql = $this->db->prepare("UPDATE tab_nfce
                        SET status_nfce = 6
                        WHERE emp_codigo = :emp AND numero_nfce = :num");
                    $sql->bindValue(':emp', $empresa, PDO::PARAM_INT);
                    $sql->bindValue(':num', $numeroNfce, PDO::PARAM_INT);
                    $sql->execute();
                    $linhas = $sql->rowCount();
                    $this->log("UPDATE status_nfce=6 | rowCount=$linhas");

                    if ($linhas === 0) {
                        $this->log("AVISO: Nenhuma linha afetada no UPDATE de status (WHERE não bateu)");
                    }
                } catch (Throwable $t) {
                    $this->log("ERRO no UPDATE de status: " . $t->getMessage());
                }

                // ============ PASSO 2: Grava os XMLs de cancelamento ============
                // Monta o procEventoNFe (XML destinatário) = <evento> + <retEvento>
                $xmlCancelado    = $this->montarProcEventoCancelamento($retorno, $nfce, $justificativa, $dhEvento);
                $xmlCanceladoRet = $retorno;   // retorno cru da SEFAZ

                $this->salvarEmDisco($numeroNfce, '-cancelado',     $xmlCancelado);
                $this->salvarEmDisco($numeroNfce, '-cancelado_ret', $xmlCanceladoRet);

                try {
                    $sql = $this->db->prepare("UPDATE tab_nfce
                        SET xml_cancelado     = :xml_cancelado,
                            xml_cancelado_ret = :xml_cancelado_ret
                        WHERE emp_codigo = :emp AND numero_nfce = :num");
                    $sql->bindValue(':xml_cancelado',     $xmlCancelado);
                    $sql->bindValue(':xml_cancelado_ret', $xmlCanceladoRet);
                    $sql->bindValue(':emp', $empresa, PDO::PARAM_INT);
                    $sql->bindValue(':num', $numeroNfce, PDO::PARAM_INT);
                    $sql->execute();
                    $linhas = $sql->rowCount();
                    $this->log("UPDATE xml_cancelado + xml_cancelado_ret | rowCount=$linhas");
                } catch (Throwable $t) {
                    $this->log("ERRO no UPDATE dos XMLs de cancelamento: " . $t->getMessage());
                }

                $this->log("NFC-e cancelada com sucesso. cStat=$cStat");
            }

            return [
                'sucesso'   => $sucesso,
                'cStat'     => $cStat,          // 135 (evento)
                'xMotivo'   => $xMotivo,        // "Evento registrado..."
                'protocolo' => $nProt,
                'mensagem'  => $sucesso ? 'Cancelamento homologado' : "SEFAZ ($cStat): $xMotivo",
            ];  

        } catch (Throwable $t) {
            $this->log("ERRO cancelamento: " . $t->getMessage());
            return $this->erro("Erro: " . $t->getMessage());
        } finally {
            $this->com = null;
        }
    }

    /**
     * Sanitiza texto para enviar ao componente/SEFAZ.
     * Remove acentos (translitera), normaliza espaços, remove bytes inválidos.
     * O schema da SEFAZ exige string sem caracteres especiais de encoding.
     */
    private function sanitizarTexto($texto) {
        $texto = trim((string)$texto);

        // 1. Translitera acentos comuns (UTF-8 → ASCII)
        $acentos = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n',
            'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A',
            'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
            'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
            'Ó'=>'O','Ò'=>'O','Õ'=>'O','Ô'=>'O','Ö'=>'O',
            'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
            'Ç'=>'C','Ñ'=>'N',
        ];
        $texto = str_replace(array_keys($acentos), array_values($acentos), $texto);

        // 2. Remove bytes não-ASCII (segurança)
        $texto = preg_replace('/[^\x20-\x7E]/', '', $texto);

        // 3. Colapsa espaços duplos
        $texto = preg_replace('/\s+/', ' ', $texto);

        return trim($texto);
    }

    /**
     * Inutiliza faixa de numeração de NFC-e.
     * @return array{sucesso: bool, cStat?: string, xMotivo?: string, protocolo?: string, mensagem: string, ja_feito?: bool}
     */
    public function inutilizar($empresa, $numeroIni, $numeroFim, $justificativa, $serie = 1, $ano = null) {
        try {
            $this->empresa    = (int)$empresa;
            $this->numeroNfce = (int)$numeroIni;
            $this->carregarConfig();

            // ✅ Sanitiza a justificativa (remove acentos e bytes inválidos)
            $justificativa = $this->sanitizarTexto($justificativa);

            if (strlen(trim($justificativa)) < 15) {
                return $this->erro("Justificativa deve ter no mínimo 15 caracteres");
            }
            if ($numeroIni > $numeroFim) {
                return $this->erro("Número inicial maior que o final");
            }

            // CNPJ do emitente (propriedade carregada no carregarConfig)
            $cnpj = $this->cnpjEmitente;
            if (empty($cnpj)) {
                return $this->erro("CNPJ do emitente não configurado");
            }

            if (!$this->inicializarCom()) {
                return $this->erro("Falha ao instanciar componente");
            }

            // Ano com 2 dígitos (conforme VB6: Mid(Year(Date), 3, 4))
            $anoInut = $ano ?? date('y');

            $this->log("=== INUTILIZAÇÃO NFC-e {$numeroIni}-{$numeroFim} (série $serie, ano $anoInut) ===");

            // Chama o componente
            $retorno = $this->com->InutilizarNF(
                '',                  // primeiro parâmetro vazio (conforme VB6)
                $anoInut,
                $cnpj,
                '65',                // modelo NFC-e
                (string)$serie,
                (string)$numeroIni,
                (string)$numeroFim,
                $justificativa
            );

            if (empty($retorno)) {
                return $this->erro("InutilizarNF retornou vazio");
            }

            $this->log("Retorno inutilização: " . substr($retorno, 0, 400));

            // ✅ Extrai cStat do LOTE (raiz)
            $cStatLote   = '';
            $xMotivoLote = '';
            if (preg_match('/<cStat>(\d+)<\/cStat>/', $retorno, $m))       $cStatLote   = $m[1];
            if (preg_match('/<xMotivo>([^<]*)<\/xMotivo>/', $retorno, $m)) $xMotivoLote = $m[1];

            // ✅ Extrai cStat do retInutNFe (retorno real da inutilização)
            $cStat   = $cStatLote;
            $xMotivo = $xMotivoLote;
            $nProt   = '';

            if (preg_match('/<retInutNFe\b[^>]*>(.*?)<\/retInutNFe>/s', $retorno, $m)) {
                $retInut = $m[1];
                if (preg_match('/<cStat>(\d+)<\/cStat>/', $retInut, $mm))       $cStat   = $mm[1];
                if (preg_match('/<xMotivo>([^<]*)<\/xMotivo>/', $retInut, $mm)) $xMotivo = $mm[1];
                if (preg_match('/<nProt>(\d+)<\/nProt>/', $retInut, $mm))       $nProt   = $mm[1];
            }

            $this->log("Retorno: cStatLote=$cStatLote | cStatInut=$cStat | xMotivo=$xMotivo | nProt=$nProt");

            // Salva retorno em disco (pasta do dia)
            $pastaDia = 'C:\\WDS Comercial\\NFCeGerada\\' . date('Y') . '\\' . date('m') . '\\' . date('d');
            if (!is_dir($pastaDia)) @mkdir($pastaDia, 0777, true);
            $arquivoInut = $pastaDia . '\\' . $numeroIni . '-' . $numeroFim . '-inutilizado.xml';
            file_put_contents($arquivoInut, $retorno);
            $this->log("XML inutilização salvo em disco: $arquivoInut");

            // ============================================================
            // TRATAMENTO ESPECIAL DE cStat
            // ============================================================

            // ✅ 563 = já existe pedido de inutilização igual → marca como inutilizada localmente
            if ($cStat === '563') {
                $this->log("cStat 563: Faixa {$numeroIni}-{$numeroFim} já inutilizada na SEFAZ. Marcando localmente.");
                try {
                    $sql = $this->db->prepare("UPDATE tab_nfce
                        SET status_nfce = 7
                        WHERE emp_codigo = :emp AND numero_nfce BETWEEN :ini AND :fim");
                    $sql->bindValue(':emp', $empresa);
                    $sql->bindValue(':ini', $numeroIni);
                    $sql->bindValue(':fim', $numeroFim);
                    $sql->execute();
                    $this->log("cStat 563: " . $sql->rowCount() . " linhas atualizadas para status=7");
                } catch (Throwable $t) {
                    $this->log("Erro ao marcar 563: " . $t->getMessage());
                }
                return [
                    'sucesso'   => true,
                    'cStat'     => $cStat,
                    'xMotivo'   => $xMotivo,
                    'protocolo' => $nProt,
                    'mensagem'  => "Faixa já inutilizada na SEFAZ (marcada localmente)",
                    'ja_feito'  => true,
                ];
            }

            // ✅ 241 = existe NF-e utilizada na faixa → marca como autorizada localmente
            if ($cStat === '241') {
                $this->log("cStat 241: Faixa {$numeroIni}-{$numeroFim} já tem NF-e na SEFAZ. Marcando como autorizada localmente.");
                try {
                    $sql = $this->db->prepare("UPDATE tab_nfce
                        SET status_nfce = 3
                        WHERE emp_codigo = :emp AND numero_nfce BETWEEN :ini AND :fim
                        AND status_nfce IN (0, 1, 4, 5)");
                    $sql->bindValue(':emp', $empresa);
                    $sql->bindValue(':ini', $numeroIni);
                    $sql->bindValue(':fim', $numeroFim);
                    $sql->execute();
                    $this->log("cStat 241: " . $sql->rowCount() . " linhas atualizadas para status=3");
                } catch (Throwable $t) {
                    $this->log("Erro ao marcar 241: " . $t->getMessage());
                }
                return [
                    'sucesso'   => true,
                    'cStat'     => $cStat,
                    'xMotivo'   => $xMotivo,
                    'protocolo' => $nProt,
                    'mensagem'  => "Faixa já usada na SEFAZ (marcada localmente como autorizada)",
                    'ja_feito'  => true,
                ];
            }

            // ============================================================
            // CASO NORMAL
            // ============================================================

            // 102 = Inutilização homologada
            $sucesso = ($cStat === '102');

            if ($sucesso) {
                // Marca os números da faixa como inutilizados
                try {
                    $sql = $this->db->prepare("UPDATE tab_nfce
                        SET status_nfce = 7
                        WHERE emp_codigo = :emp AND numero_nfce BETWEEN :ini AND :fim");
                    $sql->bindValue(':emp', $empresa);
                    $sql->bindValue(':ini', $numeroIni);
                    $sql->bindValue(':fim', $numeroFim);
                    $sql->execute();
                    $this->log("Faixa {$numeroIni}-{$numeroFim} marcada como inutilizada (" . $sql->rowCount() . " linhas)");
                } catch (Throwable $t) {
                    $this->log("Erro ao marcar faixa como inutilizada: " . $t->getMessage());
                }
            }

            return [
                'sucesso'   => $sucesso,
                'cStat'     => $cStat,
                'xMotivo'   => $xMotivo,
                'protocolo' => $nProt,
                'mensagem'  => $sucesso ? "Inutilização homologada" : "SEFAZ ($cStat): $xMotivo",
            ];

        } catch (Throwable $t) {
            $this->log("ERRO inutilização: " . $t->getMessage());
            return $this->erro("Erro: " . $t->getMessage());
        } finally {
            $this->com = null;
        }
    }

    /**
     * Salva XML em disco na pasta do dia. Cria a pasta se não existir.
     * @return string Caminho do arquivo salvo
     */
    private function salvarEmDisco($numeroNfce, $sufixo, $conteudo) {
        $pastaDia = 'C:\\WDS Comercial\\NFCeGerada\\' . date('Y') . '\\' . date('m') . '\\' . date('d');
        if (!is_dir($pastaDia)) @mkdir($pastaDia, 0777, true);

        $arquivo = $pastaDia . '\\' . $numeroNfce . $sufixo . '.xml';
        file_put_contents($arquivo, $conteudo);
        $this->log("Arquivo salvo em disco: $arquivo");

        return $arquivo;
    }

    /**
     * Monta o XML destinatário do cancelamento (procEventoNFe).
     * Formato: <procEventoNFe><evento>...</evento><retEvento>...</retEvento></procEventoNFe>
     *
     * A SEFAZ às vezes devolve o evento e o retorno juntos, outras só o retorno.
     * Este método garante o formato completo.
     */
    private function montarProcEventoCancelamento($retorno, $nfce, $justificativa, $dhEvento) {
        $chave     = $nfce['chave_acesso'];
        $protocolo = $nfce['nro_protocolo_autorizacao'];

        // Tenta extrair o <retEvento> do retorno da SEFAZ
        $retEvento = '';
        if (preg_match('/<retEvento\b[^>]*>.*?<\/retEvento>/s', $retorno, $m)) {
            $retEvento = $m[0];
        } else {
            // Se não veio, usa o retorno inteiro como bloco
            $retEvento = $retorno;
        }

        // Monta o <evento> (o componente às vezes devolve apenas o <retEvento>)
        $evento = '';
        if (preg_match('/<evento\b[^>]*>.*?<\/evento>/s', $retorno, $m)) {
            $evento = $m[0];
        } else {
            // ✅ Dados do emitente (centralizados em carregarConfig)
            $cnpjEmitente = $this->cnpjEmitente;
            $ufEmitente   = $this->ufEmitente;
            $tpAmb = $this->ambiente;   // 1=prod, 2=homolog

            // ✅ Código IBGE da UF (MT=51, MS=50, GO=52, etc.)
            $codigoUf = $this->obterCodigoUf($ufEmitente);

            // ✅ ID do evento: ID + tpEvento + chave + nSeqEvento (01)
            $idEvento = 'ID110111' . $chave . '01';

            // ✅ Data/hora com fuso (evita duplicar se já vier com fuso)
            $dhEventoFmt = $dhEvento;
            if (strpos($dhEventoFmt, '-04:00') === false && strpos($dhEventoFmt, '+') === false) {
                $dhEventoFmt .= '-04:00';
            }

            $evento = '<evento versao="1.00" xmlns="http://www.portalfiscal.inf.br/nfe">'
                    . '<infEvento Id="' . $idEvento . '">'
                    . '<cOrgao>' . $codigoUf . '</cOrgao>'
                    . '<tpAmb>' . $tpAmb . '</tpAmb>'
                    . '<CNPJ>' . $cnpjEmitente . '</CNPJ>'
                    . '<chNFe>' . $chave . '</chNFe>'
                    . '<dhEvento>' . $dhEventoFmt . '</dhEvento>'
                    . '<tpEvento>110111</tpEvento>'
                    . '<nSeqEvento>1</nSeqEvento>'
                    . '<verEvento>1.00</verEvento>'
                    . '<detEvento versao="1.00">'
                    . '<descEvento>Cancelamento</descEvento>'
                    . '<nProt>' . $protocolo . '</nProt>'
                    . '<xJust>' . htmlspecialchars($justificativa, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</xJust>'
                    . '</detEvento>'
                    . '</infEvento>'
                    . '</evento>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<procEventoNFe versao="1.00" xmlns="http://www.portalfiscal.inf.br/nfe">'
            . $evento
            . $retEvento
            . '</procEventoNFe>';
    }

    /**
     * Converte sigla UF para código IBGE.
     */
    private function obterCodigoUf($uf) {
        $mapa = [
            'AC' => 12, 'AL' => 27, 'AP' => 16, 'AM' => 13, 'BA' => 29,
            'CE' => 23, 'DF' => 53, 'ES' => 32, 'GO' => 52, 'MA' => 21,
            'MT' => 51, 'MS' => 50, 'MG' => 31, 'PA' => 15, 'PB' => 25,
            'PR' => 41, 'PE' => 26, 'PI' => 22, 'RJ' => 33, 'RN' => 24,
            'RS' => 43, 'RO' => 11, 'RR' => 14, 'SC' => 42, 'SP' => 35,
            'SE' => 28, 'TO' => 17,
        ];
        return $mapa[strtoupper($uf)] ?? 51;   // default MT
    }

    /**
     * Reprocessa NFC-e rejeitadas do mês atual (regerando o XML com dados atuais)
     * + inutiliza as anteriores (se parametrizado).
     *
     * @param int  $empresa
     * @param bool $forcarInutilizacao  Ignora pnfce_inutilizar_auto (útil para teste)
     * @return array
     */
    public function reprocessarRejeitadas($empresa, $forcarInutilizacao = false, $limiteFaixas = 30) {
        $this->empresa = (int)$empresa;

        // ✅ Aumenta limites para lote grande (evita timeout)
        @set_time_limit(1800);
        @ini_set('max_execution_time', 1800);
        @ini_set('memory_limit', '512M');

        // ✅ Verifica status da SEFAZ antes de começar
        $statusSefaz = $this->detectarStatusSefaz();
        if (!$statusSefaz['online']) {
            return [
                'sucesso'      => false,
                'sefaz_online' => false,
                'mensagem'     => "SEFAZ offline ({$statusSefaz['cStat']} {$statusSefaz['xMotivo']}). Abortado.",
                'status_sefaz' => $statusSefaz,
            ];
        }

        // ✅ Carrega parâmetro de inutilização
        $sql = $this->db->prepare("SELECT pnfce_inutilizar_auto, pnfce_inutilizar_dia
                                FROM param_nfce WHERE emp_codigo = :emp LIMIT 1");
        $sql->bindValue(':emp', $empresa);
        $sql->execute();
        $param = $sql->fetch(PDO::FETCH_ASSOC) ?: [];
        $inutAtivo = $forcarInutilizacao || (($param['pnfce_inutilizar_auto'] ?? 'N') === 'S');
        $diaInut   = (int)($param['pnfce_inutilizar_dia'] ?? 1);

        // ============================================================
        // ETAPA 1 — Retransmitir rejeitadas do MÊS ATUAL (regerando XML)
        // ============================================================
        $primeiroDiaMes = date('Y-m-01');
        $this->log("=== REPROCESSAMENTO — ETAPA 1 (rejeitadas do mês) ===");

        $sql = $this->db->prepare("
            SELECT t.numero_nfce, t.data_emissao, v.venda
            FROM tab_nfce t
            INNER JOIN vendas v ON v.empresa = t.emp_codigo AND v.numero_nfce = t.numero_nfce
            WHERE t.emp_codigo = :emp
            AND t.status_nfce = 4
            AND t.data_emissao >= :primeiro
            AND v.status = 1
            AND (v.nfe = 0 OR v.nfe IS NULL)
            ORDER BY t.numero_nfce ASC
        ");
        $sql->bindValue(':emp', $empresa);
        $sql->bindValue(':primeiro', $primeiroDiaMes);
        $sql->execute();
        $rejeitadasMes = $sql->fetchAll(PDO::FETCH_ASSOC);

        $this->log("Rejeitadas do mês atual: " . count($rejeitadasMes));

        require_once __DIR__ . '/NfceFiscal.php';
        require_once __DIR__ . '/NfceGeradorTxt.php';

        $aut = 0; $rej = 0; $detalhes = [];
        $totalRejeitadasMes = count($rejeitadasMes);

        foreach ($rejeitadasMes as $i => $nfce) {
            $num   = (int)$nfce['numero_nfce'];
            $venda = (int)$nfce['venda'];
            $pos   = $i + 1;

            $this->log("--- [$pos/$totalRejeitadasMes] Reprocessando NFC-e #$num (venda $venda) ---");

            try {
                // 1. Repopula tabelas fiscais com dados ATUAIS
                $fiscal = new NfceFiscal();
                $prep = $fiscal->prepararTudo($empresa, $venda, 'FACILIT');
                if (!$prep['sucesso']) {
                    $detalhes[] = ['numero' => $num, 'resultado' => 'FALHA_PREP', 'motivo' => $prep['mensagem']];
                    $rej++;
                    continue;
                }

                // 2. Regenera o TXT
                $gerador = new NfceGeradorTxt();
                $txt = $gerador->gerar($empresa, $prep['numero_nfce']);
                if (!$txt['sucesso']) {
                    $detalhes[] = ['numero' => $num, 'resultado' => 'FALHA_TXT', 'motivo' => $txt['mensagem']];
                    $rej++;
                    continue;
                }

                // 3. Transmite (modo normal)
                $transmissor = new NfceTransmissor();
                $trans = $transmissor->transmitir($empresa, $prep['numero_nfce'], $txt['caminho'], false);

                if ($trans['sucesso']) {
                    $aut++;
                    $detalhes[] = ['numero' => $num, 'resultado' => 'AUTORIZADA', 'protocolo' => $trans['protocolo'] ?? ''];
                    $this->log("NFC-e #$num AUTORIZADA");
                } else {
                    $rej++;
                    $detalhes[] = [
                        'numero'    => $num,
                        'resultado' => 'REJEITADA',
                        'cStat'     => $trans['cStat'] ?? '',
                        'motivo'    => $trans['mensagem'] ?? '',
                    ];
                    $this->log("NFC-e #$num ainda REJEITADA: " . ($trans['mensagem'] ?? ''));
                }
            } catch (Throwable $t) {
                $rej++;
                $detalhes[] = ['numero' => $num, 'resultado' => 'ERRO', 'motivo' => $t->getMessage()];
                $this->log("ERRO NFC-e #$num: " . $t->getMessage());
            }
        }

        // ============================================================
        // ETAPA 2 — Inutilizar notas de MESES ANTERIORES
        // ============================================================
        $inutResultado = [
            'total'   => 0,
            'sucesso' => 0,
            'falhas'  => 0,
            'faixas'  => [],
            'ativo'   => $inutAtivo,
        ];

        if (!$inutAtivo) {
            $this->log("Inutilização desativada (pnfce_inutilizar_auto = 'N')");
            $inutResultado['mensagem'] = "Inutilização desativada";

        } else {
            $diaHoje = (int)date('j');

            // Só roda no dia configurado (ou se forçado)
            $podeRodar = $forcarInutilizacao
                        || ($diaInut === 0)
                        || ($diaHoje === $diaInut);

            if (!$podeRodar) {
                $this->log("Inutilização programada para dia $diaInut — hoje é $diaHoje. Pulando.");
                $inutResultado['mensagem'] = "Inutilização programada para dia $diaInut";

            } else {
                $this->log("=== REPROCESSAMENTO — ETAPA 2 (inutilizar anteriores) ===");

                $sql = $this->db->prepare("
                    SELECT t.numero_nfce, t.status_nfce
                    FROM tab_nfce t
                    INNER JOIN vendas v ON v.empresa = t.emp_codigo AND v.numero_nfce = t.numero_nfce
                    WHERE t.emp_codigo = :emp
                    AND t.status_nfce IN (0, 1, 4, 5)
                    AND t.data_emissao < :primeiro
                    AND v.status = 1
                    AND (v.nfe = 0 OR v.nfe IS NULL)
                    ORDER BY t.numero_nfce ASC
                ");
                $sql->bindValue(':emp', $empresa);
                $sql->bindValue(':primeiro', $primeiroDiaMes);
                $sql->execute();
                $rows = $sql->fetchAll(PDO::FETCH_ASSOC);

                $numeros = array_map('intval', array_column($rows, 'numero_nfce'));
                $inutResultado['total'] = count($numeros);

                $this->log("Números a inutilizar: " . count($numeros));

                if (count($numeros) > 0) {
                    $faixas = $this->agruparFaixas($numeros);
                    $totalFaixas = count($faixas);

                    // ✅ Limita quantidade por execução para evitar timeout
                    if ($limiteFaixas > 0 && $totalFaixas > $limiteFaixas) {
                        $this->log("Limitando a $limiteFaixas faixas (de $totalFaixas)");
                        $faixas = array_slice($faixas, 0, $limiteFaixas);
                    }

                    $this->log("Faixas a processar: " . count($faixas) . " de $totalFaixas");

                    foreach ($faixas as $i => $faixa) {
                        $pos = $i + 1;
                        $this->log("[$pos/" . count($faixas) . "] Inutilizando faixa {$faixa['ini']}-{$faixa['fim']}...");

                        try {
                            $res = $this->inutilizar(
                                $empresa,
                                $faixa['ini'],
                                $faixa['fim'],
                                'Notas nao transmitidas no prazo regulamentar',
                                1
                            );

                            if ($res['sucesso']) {
                                $inutResultado['sucesso'] += ($faixa['fim'] - $faixa['ini'] + 1);
                                $inutResultado['faixas'][] = [
                                    'ini'       => $faixa['ini'],
                                    'fim'       => $faixa['fim'],
                                    'resultado' => 'OK',
                                    'protocolo' => $res['protocolo'] ?? '',
                                ];
                            } else {
                                $inutResultado['falhas'] += ($faixa['fim'] - $faixa['ini'] + 1);
                                $inutResultado['faixas'][] = [
                                    'ini'       => $faixa['ini'],
                                    'fim'       => $faixa['fim'],
                                    'resultado' => 'FALHA',
                                    'motivo'    => $res['mensagem'] ?? '',
                                ];
                            }
                        } catch (Throwable $t) {
                            $inutResultado['falhas'] += ($faixa['fim'] - $faixa['ini'] + 1);
                            $inutResultado['faixas'][] = [
                                'ini'       => $faixa['ini'],
                                'fim'       => $faixa['fim'],
                                'resultado' => 'ERRO',
                                'motivo'    => $t->getMessage(),
                            ];
                        }
                    }

                    // Se sobrou faixa além do limite, sinaliza
                    $faltam = max(0, $totalFaixas - count($faixas));
                    if ($faltam > 0) {
                        $inutResultado['faltam_processar'] = $faltam;
                        $this->log("Faltam $faltam faixas para processar em outra execução");
                    }
                }
            }
        }

        return [
            'sucesso'        => true,
            'sefaz_online'   => true,
            'retransmitidas' => [
                'total'            => $totalRejeitadasMes,
                'autorizadas'      => $aut,
                'ainda_rejeitadas' => $rej,
                'detalhes'         => $detalhes,
            ],
            'inutilizadas' => $inutResultado,
            'status_sefaz' => $statusSefaz,
        ];
    }

    /**
     * Agrupa números em faixas contíguas.
     * Ex: [6035,6036,6037,6040,6041,6045] → [[6035,6037],[6040,6041],[6045,6045]]
     */
    private function agruparFaixas(array $numeros) {
        if (empty($numeros)) return [];
        sort($numeros);
        $faixas = [];
        $ini = $fim = $numeros[0];
        foreach ($numeros as $i => $n) {
            if ($i === 0) continue;
            if ($n == $fim + 1) {
                $fim = $n;
            } else {
                $faixas[] = ['ini' => $ini, 'fim' => $fim];
                $ini = $fim = $n;
            }
        }
        $faixas[] = ['ini' => $ini, 'fim' => $fim];
        return $faixas;
    }
}