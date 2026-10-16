<?php
/**
 * NfceFiscal.php
 *
 * Model responsável por PREPARAR os dados fiscais da NFC-e (modelo 65)
 * nas tabelas nfce_* e dfe_* a partir de uma venda.
 *
 * A transmissão para a SEFAZ é feita POSTERIORMENTE pela DLL Tecnospeed,
 * que lê as tabelas preenchidas por esta classe.
 *
 * Replica a lógica do ERP VB6 (CONTROLFACIL).
 *
 * Fluxo:
 *   Electron fecha venda
 *     → PHP grava venda/receber/recebido/recebido_formas
 *     → NfceFiscal::prepararTudo() popula as tabelas fiscais
 *     → DLL Tecnospeed transmite
 */

class NfceFiscal extends model {

    // ============================================================
    // 1. CONSTANTES
    // ============================================================

    // --- Fiscal ---
    const MODELO_NFCE             = 65;
    const VERSAO_NFCE             = '4.00';
    const AMBIENTE_PRODUCAO       = 1;
    const AMBIENTE_HOMOLOGACAO    = 2;
    const TIPO_EMISSAO_NORMAL     = 1;
    const TIPO_EMISSAO_CONTINGENCIA = 9;

    // --- Indicadores da NF-e ---
    const IND_PRESENCA_PRESENCIAL    = 1;
    const IND_INTERMEDIADOR_NAO      = 0;
    const IND_DESTINO_INTERNO        = 1;
    const IND_FINAL_CONSUMIDOR       = 'sim';

    // --- Localização ---
    const CODIGO_UF_MT         = 51;
    const CODIGO_PAIS_BRASIL   = 1058;
    const NOME_PAIS_BRASIL     = 'BRASIL';
    const MUNICIPIO_PADRAO_MT  = 5103403; // Cuiabá/MT (fallback)

    // --- Status em tab_nfce.status_nfce ---
    const STATUS_PENDENTE      = 0;
    const STATUS_VALIDADA      = 1;
    const STATUS_PROCESSAMENTO = 2;
    const STATUS_AUTORIZADA    = 3;
    const STATUS_REJEITADA     = 4;
    const STATUS_DENEGADA      = 5;
    const STATUS_CANCELADA     = 6;
    const STATUS_INUTILIZADA   = 7;
    const STATUS_CONTINGENCIA  = 8;

    // --- Tipos de operação ---
    const TIPO_OPERACAO_APP = 'APP';

    // --- Códigos de forma de pagamento NF-e (tPag) ---
    const TPAG_DINHEIRO = '01';
    const TPAG_CREDITO  = '03';
    const TPAG_DEBITO   = '04';
    const TPAG_PIX      = '17';
    const TPAG_OUTROS   = '99';

    // --- CRT (Código de Regime Tributário) ---
    const CRT_SIMPLES_NACIONAL       = 1;
    const CRT_SIMPLES_EXCESSO        = 2;
    const CRT_REGIME_NORMAL          = 3;

    // ============================================================
    // 2. PROPRIEDADES
    // ============================================================

    private $debug      = true;
    private $totaisTemp = [];
    private $logPath;
    private $ambienteNfce = self::AMBIENTE_HOMOLOGACAO;   // ← NOVO

    public function __construct() {
        parent::__construct();
        $this->logPath = __DIR__ . '/../logs/nfce_fiscal.log';
    }

    // ============================================================
    // 3. MÉTODO PRINCIPAL
    // ============================================================

    /**
     * Prepara toda a estrutura fiscal da NFC-e para uma venda.
     * NÃO transmite — apenas popula as tabelas que a DLL Tecnospeed lerá.
     *
     * @return array{sucesso: bool, numero_nfce?: int, mensagem: string}
     */
    public function prepararTudo($empresa, $venda, $tipoOperacao = 'APP', $tipoEmissaoForcado = null, $cpfNfceAvulso = '') {
        try {
            $this->log("=== INICIANDO PREPARAÇÃO NFC-e ===");
            $this->log("Empresa: $empresa | Venda: $venda | Tipo: $tipoOperacao | tpEmis forçado: " . ($tipoEmissaoForcado ?? 'auto'));

            // 3.1 — Validar venda
            $vendaDados = $this->buscarVenda($empresa, $venda);
            if (!$vendaDados) {
                return $this->erro('Venda não encontrada');
            }
            if ((int)$vendaDados['status'] === 2) {
                return $this->erro('Venda cancelada não pode emitir NFC-e');
            }

            // 3.2 — Obter/criar número NFC-e e validar se já foi emitida
            $numeroNfce = $this->obterOuCriarNumeroNfce($empresa, $venda);
            if (!$numeroNfce) {
                return $this->erro('Falha ao gerar número NFC-e');
            }
            $this->log("Número NFC-e: $numeroNfce");

            // ✅ Só permite repopular se for rejeitada (4) ou contingência (8)
            $statusAtual = $this->buscarStatusNfce($empresa, $numeroNfce);
            if ($statusAtual !== null && !in_array($statusAtual, [self::STATUS_REJEITADA, self::STATUS_CONTINGENCIA], true)) {
                return $this->erro(
                    "NFC-e nº $numeroNfce está com status $statusAtual — " .
                    "só pode ser repopulada se rejeitada (4) ou em contingência (8)"
                );
            }

            $config = $this->carregarConfiguracoes($empresa);

            // ✅ Se veio tipo de emissão forçado (9 = contingência), sobrescreve
            if ($tipoEmissaoForcado !== null) {
                $config['tipo_emissao'] = (int)$tipoEmissaoForcado;
                $this->log("tpEmis sobrescrito para: {$config['tipo_emissao']}");

                if ((int)$tipoEmissaoForcado === 9) {
                    $config['dhCont'] = date('Y-m-d\TH:i:s') . '-04:00';
                    $config['xJust']  = 'Falha de comunicacao com a SEFAZ';
                    $this->log("Contingência: dhCont={$config['dhCont']} xJust={$config['xJust']}");
                }
            }
            
            $this->ambienteNfce = (int)$config['ambiente'];   // ← NOVO
            $emitente = $this->carregarEmitente($empresa);
            if (!$emitente) {
                return $this->erro('Dados da empresa não encontrados');
            }
            $cliente = $this->carregarCliente($empresa, $vendaDados['cliente']);

                        // ============================================================
            // ✅ CPF na nota — validação server-side (defesa em profundidade)
            //    Regra: vendas >= R$ 1.000 exigem CPF/CNPJ (NT 2024.001)
            //    Fontes: cliente cadastrado (prioridade) OU cpf avulso (fallback)
            // ============================================================
            $cpfNfceAvulso = $this->soNumeros($cpfNfceAvulso);

            $totalVenda = (float)$vendaDados['total'];

            $clienteTemDoc = ($cliente
                              && (int)$cliente['codigo'] !== 1
                              && (!empty($cliente['cpf']) || !empty($cliente['cnpj'])));

            $cpfAvulsoValido = (strlen($cpfNfceAvulso) === 11);

            if ($totalVenda >= 1000.00 && !$clienteTemDoc && !$cpfAvulsoValido) {
                return $this->erro(
                    'CPF/CNPJ obrigatório para vendas a partir de R$ 1.000,00 (NT 2024.001). ' .
                    'Informe o CPF ou selecione um cliente cadastrado.'
                );
            }

            $this->log("CPF check: total=$totalVenda clienteTemDoc=" . ($clienteTemDoc ? 'S' : 'N')
                     . " cpfAvulso=" . ($cpfAvulsoValido ? 'S' : 'N'));

            // 3.5 — Transação
            $this->db->beginTransaction();
            try {
                $this->limparTabelasNfce($empresa, $numeroNfce);
                $this->criarCabecalhoTabNfce($empresa, $numeroNfce, $vendaDados);
                $this->criarIdentificacao($empresa, $numeroNfce, $vendaDados, $config);
                $this->criarEmitente($empresa, $numeroNfce, $emitente);
                $this->criarDestinatario($empresa, $numeroNfce, $cliente, $cpfNfceAvulso);

                $resultadoItens = $this->processarItens($empresa, $numeroNfce, $venda, $vendaDados);
                if (!$resultadoItens['sucesso']) {
                    throw new Exception($resultadoItens['mensagem']);
                }

                $this->calcularTotais($empresa, $numeroNfce);
                $this->criarFormasPagamento($empresa, $numeroNfce, $vendaDados);
                // ✅ Sincroniza TEF (se houver) para nfce_tef + enriquece nfce_totais_formapgto
                $this->sincronizarTefParaNfce($empresa, $numeroNfce, $venda);
                $this->criarInformacoesComplementares($empresa, $numeroNfce, $vendaDados);
                $this->vincularNfceVenda($empresa, $venda, $numeroNfce);

                $this->db->commit();

                $this->log("=== PREPARAÇÃO CONCLUÍDA COM SUCESSO ===");

                return [
                    'sucesso'     => true,
                    'numero_nfce' => $numeroNfce,
                    'mensagem'    => 'NFC-e preparada para emissão',
                ];

            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $this->log("ERRO na transação: " . get_class($e) . " — "
                         . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
                throw $e;
            }

        } catch (Throwable $e) {
            $this->log("ERRO GERAL: " . get_class($e) . " — "
                     . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
            return $this->erro('Erro ao preparar NFC-e: ' . $e->getMessage());
        }
    }



   

    // ============================================================
    // 4. BUSCA DE DADOS
    // ============================================================

    private function buscarVenda($empresa, $venda) {
        $sql = $this->db->prepare("SELECT v.venda, v.empresa, v.cliente, v.vendedor,
                                          v.total, v.desconto, v.status, v.forma_pgto,
                                          v.mesa_comanda, v.origem, v.data, v.hora_venda
                                   FROM vendas v
                                   WHERE v.empresa = :empresa AND v.venda = :venda");
        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':venda', $venda);
        $sql->execute();

        return $sql->rowCount() > 0 ? $sql->fetch(PDO::FETCH_ASSOC) : null;
    }

    private function buscarStatusNfce($empresa, $numeroNfce) {
        try {
            $sql = $this->db->prepare("SELECT status_nfce FROM tab_nfce
                                       WHERE emp_codigo = :empresa AND numero_nfce = :numero
                                       LIMIT 1");
            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':numero', $numeroNfce);
            $sql->execute();
            $row = $sql->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['status_nfce'] : null;
        } catch (Exception $e) {
            $this->log("Aviso ao buscar status NFC-e: " . $e->getMessage());
            return null;
        }
    }

    private function obterOuCriarNumeroNfce($empresa, $venda) {
        // Reaproveita número já vinculado à venda
        $sql = $this->db->prepare("SELECT numero_nfce FROM vendas
                                   WHERE empresa = :empresa AND venda = :venda");
        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':venda', $venda);
        $sql->execute();
        $row = $sql->fetch(PDO::FETCH_ASSOC);

        if ($row && (int)$row['numero_nfce'] > 0) {
            return (int)$row['numero_nfce'];
        }

        // Gera o próximo
        $sql = $this->db->prepare("SELECT COALESCE(MAX(numero_nfce), 0) + 1 AS proximo
                                   FROM tab_nfce WHERE emp_codigo = :empresa");
        $sql->bindValue(':empresa', $empresa);
        $sql->execute();
        $row = $sql->fetch(PDO::FETCH_ASSOC);
        return (int)$row['proximo'];
    }

    private function carregarConfiguracoes($empresa) {
        $config = [
            'versao'       => self::VERSAO_NFCE,
            'ambiente'     => self::AMBIENTE_HOMOLOGACAO,
            'serie'        => 1,
            'tipo_emissao' => self::TIPO_EMISSAO_NORMAL,
            'dhCont'       => null,
            'xJust'        => null,
        ];

        try {
            $sql = $this->db->prepare("SELECT * FROM param_nfce WHERE emp_codigo = :empresa LIMIT 1");
            $sql->bindValue(':empresa', $empresa);
            $sql->execute();

            if ($sql->rowCount() > 0) {
                $row = $sql->fetch(PDO::FETCH_ASSOC);
                $config['versao']       = $row['pnfce_versao']       ?? self::VERSAO_NFCE;
                $config['ambiente']     = (int)($row['pnfce_ambiente']     ?? self::AMBIENTE_HOMOLOGACAO);
                $config['serie']        = (int)($row['pnfce_serie']        ?? 1);
                $config['tipo_emissao'] = (int)($row['pnfce_tipoemissao']  ?? self::TIPO_EMISSAO_NORMAL);
                $config['dhCont']       = $row['pnfce_dhCont']       ?? null;
                $config['xJust']        = $row['pnfce_xJust_Cont']   ?? null;
            }
        } catch (Exception $e) {
            $this->log("Aviso: usando config padrão: " . $e->getMessage());
        }

        return $config;
    }

    private function carregarEmitente($empresa) {
        $sql = $this->db->prepare("SELECT
                                      e.razao, e.fantasia, e.cnpj, e.insc_est,
                                      e.endereco, e.endereco_nro, e.bairro,
                                      e.cidade, e.estado, e.cep, e.fone,
                                      e.crt, m.nome AS nome_municipio
                                   FROM tabempresa e
                                   LEFT JOIN municipios m ON m.codigo = e.cidade
                                   WHERE e.codigo = :empresa");
        $sql->bindValue(':empresa', $empresa);
        $sql->execute();

        return $sql->rowCount() > 0 ? $sql->fetch(PDO::FETCH_ASSOC) : null;
    }

    private function carregarCliente($empresa, $cliente) {
        if (!$cliente || (int)$cliente <= 1) {
            return [
                'codigo' => 1,
                'razao' => 'CONSUMIDOR FINAL',
                'cnpj' => '', 'cpf' => '',
                'endereco' => '', 'endereco_nro' => '', 'bairro' => '',
                'cidade' => '', 'uf' => '', 'cep' => '',
                'telefone' => '', 'email' => '', 'tipo' => 1,
                'nome_municipio' => '',
            ];
        }

        $sql = $this->db->prepare("SELECT
                                      c.codigo, c.razao, c.cpf, c.tipo,
                                      c.endereco, c.endereco_nro, c.bairro,
                                      c.cidade, c.uf, c.cep, c.telefone, c.email,
                                      m.nome AS nome_municipio
                                   FROM clientes c
                                   LEFT JOIN municipios m ON m.codigo = c.cidade
                                   WHERE c.empresa = :empresa AND c.codigo = :cliente");
        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':cliente', $cliente);
        $sql->execute();

        if ($sql->rowCount() === 0) {
            return null;
        }

        $row = $sql->fetch(PDO::FETCH_ASSOC);

        // Schema só tem `cpf` (armazena CPF ou CNPJ). Separa pelo tamanho.
        $doc      = $this->soNumeros($row['cpf'] ?? '');
        $row['cpf']  = (strlen($doc) === 11) ? $doc : '';
        $row['cnpj'] = (strlen($doc) === 14) ? $doc : '';

        return $row;
    }

    // ============================================================
    // 5. LIMPEZA / CABEÇALHO
    // ============================================================

    private function limparTabelasNfce($empresa, $numeroNfce) {
        // Tabelas chaveadas por (empresa, nfe)
        $tabelasEmpNfe = [
            'dfe_gibsuf', 'dfe_gibsmun', 'dfe_gcbs',
            'dfe_class_fiscal', 'dfe_is',
        ];

        // Tabelas chaveadas por (emp_codigo, numero_nfce)
        $tabelasEmpNumero = [
            'tab_nfce', 'nfce_identificacao', 'nfce_emitente',
            'nfce_destinatario', 'nfce_produtos', 'nfce_produtos_impostos_icms',
            'nfce_totais', 'nfce_totais_formapgto', 'nfce_informacoescpl',
            'dfe_gibstotal', 'dfe_gcbstotal', 'dfe_gibscredpres',
        ];

        foreach ($tabelasEmpNfe as $tabela) {
            $this->deleteSeguro(
                "DELETE FROM $tabela WHERE empresa = :emp AND nfe = :nfe",
                [':emp' => $empresa, ':nfe' => $numeroNfce],
                $tabela
            );
        }

        foreach ($tabelasEmpNumero as $tabela) {
            $this->deleteSeguro(
                "DELETE FROM $tabela WHERE emp_codigo = :emp AND numero_nfce = :nfe",
                [':emp' => $empresa, ':nfe' => $numeroNfce],
                $tabela
            );
        }
    }

    private function deleteSeguro($sql, $params, $tabela) {
        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
        } catch (Exception $e) {
            $this->log("Aviso ao limpar $tabela: " . $e->getMessage());
        }
    }

    private function criarCabecalhoTabNfce($empresa, $numeroNfce, $vendaDados) {
        $sql = $this->db->prepare("INSERT INTO tab_nfce
            (emp_codigo, numero_nfce, data_emissao, hora_emissao, status_nfce, cli_codigo)
            VALUES (:empresa, :numero, :data, :hora, :status, :cliente)");
        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':numero', $numeroNfce);
        $sql->bindValue(':data', date('Y-m-d'));
        $sql->bindValue(':hora', date('H:i:s'));
        $sql->bindValue(':status', self::STATUS_PENDENTE);
        $sql->bindValue(':cliente', $vendaDados['cliente']);
        $sql->execute();

        $this->log("tab_nfce criada");
    }

    private function criarIdentificacao($empresa, $numeroNfce, $vendaDados, $config) {
        $sql = $this->db->prepare("INSERT INTO nfce_identificacao
            (emp_codigo, ide_nfe, ide_natoperacao, ide_indpag, ide_modelo_documento,
            ide_serie_documento, numero_nfce, ide_dEmi, ide_dSaiEnt, ide_hSaiEnt,
            ide_tpnf, ide_tpEmis, ide_tpAmb, ide_cUF, ide_cMunFG, ide_finNFe,
            ide_tpimp, ide_indfinal, ide_indpres, ide_versao, ide_dhCont, ide_xJust_Cont,
            ide_indIntermed_Opc)
            VALUES
            (:empresa, :nfe, :natop, 0, :modelo,
            :serie, :numero, :demi, :dsai, :hsai,
            1, :tpemis, :tpamb, :cuf, :cmun, 1,
            4, :indfinal, :indpres, :versao, :dhcont, :xjust,
            :indintermed)");

        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':nfe', $numeroNfce);
        $sql->bindValue(':natop', 'VENDA DE MERCADORIA DENTRO DO ESTADO');
        $sql->bindValue(':modelo', self::MODELO_NFCE);
        $sql->bindValue(':serie', $config['serie']);
        $sql->bindValue(':numero', $numeroNfce);
        $sql->bindValue(':demi', date('Y-m-d'));
        $sql->bindValue(':dsai', date('Y-m-d'));
        $sql->bindValue(':hsai', date('H:i:s'));
        $sql->bindValue(':tpemis', $config['tipo_emissao']);
        $sql->bindValue(':tpamb', $config['ambiente']);
        $sql->bindValue(':cuf', self::CODIGO_UF_MT);
        $sql->bindValue(':cmun', $this->obterCodigoMunicipio($empresa));
        $sql->bindValue(':indfinal', self::IND_FINAL_CONSUMIDOR);   // = 1
        $sql->bindValue(':indpres', self::IND_PRESENCA_PRESENCIAL); // = 1
        $sql->bindValue(':versao', $config['versao']);
        $sql->bindValue(':dhcont', $config['dhCont']);
        $sql->bindValue(':xjust', $config['xJust']);
        $sql->bindValue(':indintermed', self::IND_INTERMEDIADOR_NAO); // = 0
        $sql->execute();

        $this->log("nfce_identificacao criada");
    }

    private function criarEmitente($empresa, $numeroNfce, $emit) {
        $sql = $this->db->prepare("INSERT INTO nfce_emitente
            (emp_codigo, numero_nfce, emi_cnpj, emi_ie, emi_xNome, emi_xFant,
             emi_fone, emi_cep, emi_xLgr, emi_nro, emi_xBairro, emi_cMun,
             emi_xMun, emi_uf, emi_pais, emi_xPais, emi_crt)
            VALUES
            (:empresa, :numero, :cnpj, :ie, :xnome, :xfant,
             :fone, :cep, :xlgr, :nro, :xbairro, :cmun,
             :xmun, :uf, :pais, :xpais, :crt)");

        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':numero', $numeroNfce);
        $sql->bindValue(':cnpj', $this->soNumeros($emit['cnpj']));
        $sql->bindValue(':ie', $this->soNumeros($emit['insc_est']));
        $sql->bindValue(':xnome', $emit['razao']);
        $sql->bindValue(':xfant', $emit['fantasia']);
        $sql->bindValue(':fone', $this->soNumeros($emit['fone']));
        $sql->bindValue(':cep', $this->soNumeros($emit['cep']));
        $sql->bindValue(':xlgr', $emit['endereco']);
        $sql->bindValue(':nro', $emit['endereco_nro']);
        $sql->bindValue(':xbairro', $emit['bairro']);
        $sql->bindValue(':cmun', $emit['cidade']);
        $sql->bindValue(':xmun', $emit['nome_municipio']);
        $sql->bindValue(':uf', $emit['estado']);
        $sql->bindValue(':pais', self::CODIGO_PAIS_BRASIL);
        $sql->bindValue(':xpais', self::NOME_PAIS_BRASIL);
        $sql->bindValue(':crt', (int)$emit['crt']);
        $sql->execute();

        $this->log("nfce_emitente criada");
    }

    private function criarDestinatario($empresa, $numeroNfce, $cli, $cpfAvulso = '') {
        // ---- Decide a fonte do documento ----
        $doc        = '';
        $nome       = 'CONSUMIDOR FINAL';
        $temCadastro = ($cli && (int)$cli['codigo'] !== 1);

        // Prioridade 1: cliente cadastrado com CPF/CNPJ
        if ($temCadastro) {
            $doc  = !empty($cli['cnpj']) ? $cli['cnpj'] : ($cli['cpf'] ?? '');
            $nome = $cli['razao'] ?: 'CONSUMIDOR FINAL';
        }

        // Prioridade 2: CPF avulso
        if (empty($doc) && !empty($cpfAvulso)) {
            $doc  = $cpfAvulso;
            $nome = 'CONSUMIDOR FINAL';
        }

        if (empty($doc)) {
            $this->log("Destinatário: sem documento — Consumidor Final sem identificação");
            return;
        }

        // ---- Normaliza CPF (11) / CNPJ (14) ----
        $cpf  = '';
        $cnpj = '';
        $docNumeros = $this->soNumeros($doc);
        if (strlen($docNumeros) === 11) {
            $cpf = $docNumeros;
        } elseif (strlen($docNumeros) === 14) {
            $cnpj = $docNumeros;
        } else {
            $this->log("Destinatário: documento com tamanho inválido ($docNumeros) — pulando");
            return;
        }

        // ============================================================
        // ✅ FIX cStat 598 — em homologação, xNome do destinatário é OBRIGATÓRIO
        //    com o texto padronizado pela SEFAZ. Vale para CPF avulso E cadastro.
        // ============================================================
        if ($this->ambienteNfce === self::AMBIENTE_HOMOLOGACAO) {
            $nome = 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';
        }

        // ============================================================
        // ✅ FIX cStat 215 — só envia endereço se o cliente TEM cadastro real.
        //    Para CPF avulso, campos ficam NULL (TecnoSpeed omite <enderDest>).
        // ============================================================
        if ($temCadastro) {
            $endereco = $cli['endereco']      ?? '';
            $nro      = $cli['endereco_nro']  ?? '';
            $bairro   = $cli['bairro']        ?? '';
            $cmun     = $cli['cidade']        ?? 0;
            $xmun     = $cli['nome_municipio']?? '';
            $uf       = $cli['uf']            ?? 'MT';
            $cep      = $this->soNumeros($cli['cep'] ?? '');
            $fone     = $this->soNumeros($cli['telefone'] ?? '');
            $pais     = self::CODIGO_PAIS_BRASIL;   
            $xpais    = self::NOME_PAIS_BRASIL; 
        } else {
            $endereco = null;
            $nro      = null;
            $bairro   = null;
            $cmun     = null;
            $xmun     = null;
            $uf       = null;
            $cep      = null;
            $fone     = null;
            $pais     = null;   
            $xpais    = null;   
        }

        $sql = $this->db->prepare("INSERT INTO nfce_destinatario
            (emp_codigo, numero_nfce, dest_cpf, dest_cnpj, dest_xNome, dest_fone,
             dest_cep, dest_xLgr, dest_nro, dest_xBairro, dest_cMun, dest_xMun,
             dest_uf, dest_pais, dest_xPais)
            VALUES
            (:empresa, :numero, :cpf, :cnpj, :xnome, :fone,
             :cep, :xlgr, :nro, :xbairro, :cmun, :xmun,
             :uf, :pais, :xpais)");

        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':numero',  $numeroNfce);
        $sql->bindValue(':cpf',     $cpf);
        $sql->bindValue(':cnpj',    $cnpj);
        $sql->bindValue(':xnome',   $nome);
        $sql->bindValue(':fone',    $fone, $fone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $sql->bindValue(':cep',     $cep,  $cep  === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $sql->bindValue(':xlgr',    $endereco, $endereco === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $sql->bindValue(':nro',     $nro,      $nro      === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $sql->bindValue(':xbairro', $bairro,   $bairro   === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $sql->bindValue(':cmun',    $cmun,     $cmun     === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $sql->bindValue(':xmun',    $xmun,     $xmun     === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $sql->bindValue(':uf',      $uf,       $uf       === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $sql->bindValue(':pais',    $pais,  $pais  === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $sql->bindValue(':xpais',   $xpais, $xpais === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $sql->execute();

        $tipo = $cpf ? "CPF=$cpf" : "CNPJ=$cnpj";
        $this->log("nfce_destinatario criada ($tipo, xNome=$nome)");
    }

    private function criarInformacoesComplementares($empresa, $numeroNfce, $vendaDados) {
        $obs = 'Vendedor: ' . ($vendaDados['vendedor'] ?? '');

        $sql = $this->db->prepare("INSERT INTO nfce_informacoescpl
            (emp_codigo, numero_nfce, inf_infcpl_contribuinte)
            VALUES (:empresa, :numero, :obs)");
        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':numero', $numeroNfce);
        $sql->bindValue(':obs', $obs);
        $sql->execute();

        $this->log("nfce_informacoescpl criada");
    }

    private function vincularNfceVenda($empresa, $venda, $numeroNfce) {
        $sql = $this->db->prepare("UPDATE vendas SET numero_nfce = :numero
                                   WHERE empresa = :empresa AND venda = :venda");
        $sql->bindValue(':numero', $numeroNfce);
        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':venda', $venda);
        $sql->execute();

        $this->log("vendas.numero_nfce = $numeroNfce");
    }

    // ============================================================
    // 6. PROCESSAMENTO DOS ITENS
    // ============================================================

    private function processarItens($empresa, $numeroNfce, $venda, $vendaDados) {
        $itens = $this->buscarItensVenda($empresa, $venda);
        if (empty($itens)) {
            return $this->erro('Venda sem itens');
        }

        // 6.1 — Distribuir desconto do cabeçalho entre os itens
        $descontoCabecalho = (float)($vendaDados['desconto'] ?? 0);
        if ($descontoCabecalho > 0) {
            $itens = $this->distribuirDescontoCabecalho($itens, $descontoCabecalho);
            $this->log("Desconto do cabeçalho distribuído: R$ $descontoCabecalho");
        }

        // 6.2 — Preparar contexto fiscal
        $emitente     = $this->carregarEmitente($empresa);
        $crt          = (int)$emitente['crt'];
        $reformaAtiva = $this->isReformaAtiva($empresa);
        $this->log("CRT: $crt | Reforma ativa: " . ($reformaAtiva ? 'SIM' : 'NAO'));

        require_once __DIR__ . '/Produto.php';
        $produtoModel = new Produto();

        // 6.3 — Acumuladores
        $totais = [
            'vProd' => 0, 'vDesc' => 0, 'vFrete' => 0, 'vSeguro' => 0,
            'vOutras' => 0, 'vBC' => 0, 'vICMS' => 0, 'vPIS' => 0,
            'vCOFINS' => 0, 'vTotTrib' => 0,
        ];

        $sequencial = 0;

        foreach ($itens as $item) {
            $sequencial++;

            $dadosFiscais = $produtoModel->buscarDadosFiscais(
                $empresa,
                $item['referencia'],
                $item['codigo_cfop']
            );

            if (!$dadosFiscais) {
                return $this->erro("Produto {$item['referencia']} sem cadastro fiscal");
            }

            $prod = $dadosFiscais['produto'];
            $cfop = $dadosFiscais['cfop'];

            $this->log("--- Item $sequencial: {$prod['descricao']} ---");

            $impostos = $this->calcularImpostosItem($empresa, $item, $prod, $cfop, $crt);

            $this->inserirProdutoNfce($empresa, $numeroNfce, $sequencial, $item, $prod, $cfop, $impostos);
            $this->inserirImpostosIcms($empresa, $numeroNfce, $sequencial, $prod, $impostos);

            if ($reformaAtiva) {
                $this->processarReformaTributaria($empresa, $numeroNfce, $sequencial, $item, $prod, $impostos);
            }

            // Acumular
            $totais['vProd']    += $impostos['vProd'];
            $totais['vDesc']    += $impostos['vDesc'];
            $totais['vFrete']   += $impostos['vFrete'];
            $totais['vSeguro']  += $impostos['vSeguro'];
            $totais['vOutras']  += $impostos['vOutras'];
            $totais['vBC']      += $impostos['vBC'];
            $totais['vICMS']    += $impostos['vICMS'];
            $totais['vPIS']     += $impostos['vPIS'];
            $totais['vCOFINS']  += $impostos['vCOFINS'];
            $totais['vTotTrib'] += $impostos['vTotTrib'];
        }

        $this->totaisTemp = $totais;
        $this->log("=== Total de $sequencial itens processados ===");

        return ['sucesso' => true];
    }

    private function buscarItensVenda($empresa, $venda) {
        $sql = $this->db->prepare("SELECT
                vi.item, vi.referencia, vi.quantidade, vi.preco,
                vi.total_bruto, vi.total_liquido,
                vi.valor_desconto_item, vi.perc_desconto_item,
                vi.vFrete, vi.vSeguro, vi.vOutras, vi.vTotTribAprox,
                vi.vTotTribAprox_estadual, vi.vTotTribAprox_municipal,
                vi.codigo_cfop, vi.observacao, vi.cancelado,
                vi.prod_lote, vi.seq_cozinha
            FROM vendas_item vi
            WHERE vi.empresa = :empresa
              AND vi.venda = :venda
              AND vi.cancelado = 0
              AND vi.quantidade > 0
            ORDER BY vi.item ASC");

        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':venda', $venda);
        $sql->execute();

        return $sql->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Distribui o desconto do cabeçalho proporcionalmente ao vProd dos itens.
     * O último item recebe a diferença para eliminar sobra de centavos.
     */
    private function distribuirDescontoCabecalho(array $itens, $descontoCabecalho) {
        $somaProd = 0;
        foreach ($itens as &$item) {
            $item['_descCabecalho'] = 0;
            $item['_vProdBase']     = $this->calcularVProdItem($item);
            $somaProd += $item['_vProdBase'];
        }
        unset($item);

        if ($somaProd <= 0) {
            return $itens;
        }

        $distribuido = 0;
        $count = count($itens);
        $i = 0;

        foreach ($itens as &$item) {
            $i++;
            if ($i === $count) {
                $item['_descCabecalho'] = round($descontoCabecalho - $distribuido, 2);
            } else {
                $parcela = round($descontoCabecalho * ($item['_vProdBase'] / $somaProd), 2);
                $item['_descCabecalho'] = $parcela;
                $distribuido += $parcela;
            }
        }
        unset($item);

        return $itens;
    }

    /**
     * Calcula o vProd de um item, preferindo o valor já gravado pelo ERP.
     */
    private function calcularVProdItem($item) {
        if (isset($item['total_bruto']) && (float)$item['total_bruto'] > 0) {
            return round((float)$item['total_bruto'], 2);
        }
        return round((float)$item['quantidade'] * (float)$item['preco'], 2);
    }

    private function isReformaAtiva($empresa) {
        try {
            $sql = $this->db->prepare("SELECT COUNT(*) AS total FROM produtos
                WHERE empresa = :empresa
                  AND CST_IBS_CBS IS NOT NULL
                  AND CST_IBS_CBS <> ''
                LIMIT 1");
            $sql->bindValue(':empresa', $empresa);
            $sql->execute();
            $row = $sql->fetch(PDO::FETCH_ASSOC);
            return ((int)$row['total'] > 0);
        } catch (Exception $e) {
            return false;
        }
    }

    // ============================================================
    // 7. CÁLCULO DE IMPOSTOS POR ITEM
    // ============================================================

    private function calcularImpostosItem($empresa, $item, $prod, $cfop, $crt) {
        $quantidade = (float)$item['quantidade'];
        $preco      = (float)$item['preco'];

        // vProd: usa total_bruto se disponível (fonte de verdade)
        $vProd = $this->calcularVProdItem($item);

        // vDesc: soma desconto do item + parcela do desconto do cabeçalho
        $vDescItem     = (float)($item['valor_desconto_item'] ?? 0);
        $vDescCabecalho = (float)($item['_descCabecalho'] ?? 0);
        $vDesc         = round($vDescItem + $vDescCabecalho, 2);

        $vFrete  = (float)($item['vFrete']  ?? 0);
        $vSeguro = (float)($item['vSeguro'] ?? 0);
        $vOutras = (float)($item['vOutras'] ?? 0);
        $vTotTrib = (float)($item['vTotTribAprox'] ?? 0);

        $impostos = [
            'vProd' => $vProd, 'vDesc' => $vDesc,
            'vFrete' => $vFrete, 'vSeguro' => $vSeguro,
            'vOutras' => $vOutras, 'vTotTrib' => $vTotTrib,
            'vBC' => 0, 'vICMS' => 0, 'pICMS' => 0, 'pRedBC' => 0,
            'pCredSN' => 0, 'vCredICMSSN' => 0,
            'cst' => '', 'origem' => (int)$prod['origem'], 'modBC' => 3,
            'vPIS' => 0, 'vCOFINS' => 0,
            'cstPIS' => '', 'cstCOFINS' => '',
            'pPIS' => 0, 'pCOFINS' => 0,
            'vBCPIS' => 0, 'vBCCOFINS' => 0,
        ];

        // 7.1 — Definição de CST/CSOSN
        if ($crt === self::CRT_SIMPLES_NACIONAL || $crt === self::CRT_SIMPLES_EXCESSO) {
            $impostos['cst']    = $cfop['cfop_cst_cson']           ?? '';
            $impostos['pICMS']  = (float)($cfop['cfop_aliq_icms']          ?? 0);
            $impostos['pRedBC'] = (float)($cfop['cfop_aliq_reducao_icms']  ?? 0);
            $impostos['pCredSN']= (float)($cfop['cfop_aliq_credSN']        ?? 0);
        } else {
            $impostos['cst']    = $cfop['cfop_cst_icms']            ?? $prod['cst'];
            $impostos['pICMS']  = (float)($cfop['cfop_aliq_icms']          ?? $prod['aliquota_icms']);
            $impostos['pRedBC'] = (float)($cfop['cfop_aliq_reducao_icms']  ?? $prod['reducao_bcicsm']);
        }

        // 7.2 — Base ICMS
        $vBCBase = $vProd - $vDesc + $vFrete + $vOutras;

        switch ($impostos['cst']) {
            case '00': case '10': case '70':
                $impostos['vBC']   = round($vBCBase, 2);
                $impostos['vICMS'] = round($impostos['vBC'] * $impostos['pICMS'] / 100, 2);
                break;

            case '20': case '90':
                if ($impostos['pRedBC'] > 0) {
                    $vReducao = round(($vProd - $vDesc) * $impostos['pRedBC'] / 100, 2);
                    $impostos['vBC'] = round($vBCBase - $vReducao, 2);
                } else {
                    $impostos['vBC'] = round($vBCBase, 2);
                }
                $impostos['vICMS'] = round($impostos['vBC'] * $impostos['pICMS'] / 100, 2);
                break;

            case '101': case '201': case '900':
                if ($impostos['pCredSN'] > 0) {
                    $baseCredito = $vProd - $vDesc + $vFrete + $vOutras + $vSeguro;
                    $impostos['vCredICMSSN'] = round($baseCredito * $impostos['pCredSN'] / 100, 2);
                }
                if ($impostos['cst'] === '900' && $impostos['pICMS'] > 0) {
                    if ($impostos['pRedBC'] > 0) {
                        $vReducao = round(($vProd - $vDesc) * $impostos['pRedBC'] / 100, 2);
                        $impostos['vBC'] = round($vBCBase - $vReducao, 2);
                    } else {
                        $impostos['vBC'] = round($vBCBase, 2);
                    }
                    $impostos['vICMS'] = round($impostos['vBC'] * $impostos['pICMS'] / 100, 2);
                }
                break;

            // '02', '04', '06', '08', '15', '30', '40', '41', '50', '51', '53',
            // '60', '90', '102', '103', '300', '400', '500' → isentos/ST/NT → vBC=0
            default:
                break;
        }

        // 7.3 — PIS / COFINS
        $cstPIS     = $cfop['cfop_cst_pis']     ?? $prod['pis_sittributaria'];
        $cstCOFINS  = $cfop['cfop_cst_cofins']  ?? $prod['cofins_sittributaria'];
        $pPIS       = (float)($cfop['cfop_aliq_pis']    ?? $prod['pis_aliquota']);
        $pCOFINS    = (float)($cfop['cfop_aliq_cofins'] ?? $prod['cofins_aliquota']);

        $impostos['cstPIS']    = $cstPIS;
        $impostos['cstCOFINS'] = $cstCOFINS;
        $impostos['pPIS']      = $pPIS;
        $impostos['pCOFINS']   = $pCOFINS;

        $basePISCOFINS = $vProd - $vDesc + $vFrete + $vOutras;

        if ($this->pisCofinsTributado($cstPIS)) {
            $impostos['vBCPIS'] = round($basePISCOFINS, 2);
            $impostos['vPIS']   = round($impostos['vBCPIS'] * $pPIS / 100, 2);
        }
        if ($this->pisCofinsTributado($cstCOFINS)) {
            $impostos['vBCCOFINS'] = round($basePISCOFINS, 2);
            $impostos['vCOFINS']   = round($impostos['vBCCOFINS'] * $pCOFINS / 100, 2);
        }

        return $impostos;
    }

    private function pisCofinsTributado($cst) {
        $tributados = [
            '01','02','49','50','51','52','53','54','55','56',
            '60','61','62','63','64','65','66','67',
            '70','71','72','73','74','75','98','99',
        ];
        return in_array($cst, $tributados, true);
    }

    // ============================================================
    // 8. INSERÇÃO — PRODUTOS E IMPOSTOS
    // ============================================================

    private function inserirProdutoNfce($empresa, $numeroNfce, $sequencial, $item, $prod, $cfop, $impostos) {
        $ean = trim($prod['codigobarra'] ?? '');
        if (strlen($ean) < 8) $ean = 'SEM GTIN';

        $eanTrib = trim($prod['codigobarra_trib'] ?? '');
        if (strlen($eanTrib) < 8) $eanTrib = $ean;

        $uCom   = $prod['unidade_sigla'] ?? 'UN';
        $qCom   = (float)$item['quantidade'];
        $vUnCom = (float)$item['preco'];
        $vProd  = $impostos['vProd'];

        $uTrib    = $prod['unidade_compra_sigla'] ?? $uCom;
        $qTrib    = $qCom;
        $vUnTrib  = $vUnCom;

        if (!empty($prod['qtd_unidade']) && $prod['qtd_unidade'] > 0) {
            $qTrib   = $qCom * (float)$prod['qtd_unidade'];
            $vUnTrib = $qTrib > 0 ? round($vProd / $qTrib, 4) : $vUnCom;
        }

        $cfopCod = $cfop['cfop_codigo'] ?? $prod['cfop'];
        $cfopCod = str_replace('.', '', $cfopCod);

        $sql = $this->db->prepare("INSERT INTO nfce_produtos
            (emp_codigo, numero_nfce, prod_item, prod_cprod, prod_cEAN, prod_xProd,
             prod_NCM, prod_cfop, prod_uCom, prod_qcom, prod_vUnCom, prod_vProd,
             prod_cEANTrib, prod_uTrib, prod_qTrib, prod_vUnTrib,
             prod_vOutro, prod_vFrete, prod_vSeg, prod_vDesc, vTotTrib,
             prod_CEST, codigo_cfop)
            VALUES
            (:empresa, :numero, :seq, :cprod, :cean, :xprod,
             :ncm, :cfop, :ucom, :qcom, :vuncom, :vprod,
             :ceantrib, :utrib, :qtrib, :vuntrib,
             :voutro, :vfrete, :vseg, :vdesc, :vtot,
             :cest, :codigo_cfop)");

        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':numero', $numeroNfce);
        $sql->bindValue(':seq', $sequencial);
        $sql->bindValue(':cprod', $prod['referencia']);
        $sql->bindValue(':cean', $ean);

        $xProd = $prod['descricao'];

        // Em homologação, força o texto exigido pela SEFAZ
        if ($this->ambienteNfce === self::AMBIENTE_HOMOLOGACAO) {
            $xProd = 'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';
        }

        $sql->bindValue(':xprod', $xProd);

        $sql->bindValue(':ncm', $this->soNumeros($prod['ncm']));
        $sql->bindValue(':cfop', $cfopCod);
        $sql->bindValue(':ucom', $uCom);
        $sql->bindValue(':qcom', $qCom);
        $sql->bindValue(':vuncom', $vUnCom);
        $sql->bindValue(':vprod', $vProd);
        $sql->bindValue(':ceantrib', $eanTrib);
        $sql->bindValue(':utrib', $uTrib);
        $sql->bindValue(':qtrib', $qTrib);
        $sql->bindValue(':vuntrib', $vUnTrib);
        $sql->bindValue(':voutro', $impostos['vOutras']);
        $sql->bindValue(':vfrete', $impostos['vFrete']);
        $sql->bindValue(':vseg', $impostos['vSeguro']);
        $sql->bindValue(':vdesc', $impostos['vDesc']);
        $sql->bindValue(':vtot', $impostos['vTotTrib']);
        $sql->bindValue(':cest', $prod['codigo_cest'] ?? '');
        $sql->bindValue(':codigo_cfop', $item['codigo_cfop'] ?? '');
        $sql->execute();
    }

    private function inserirImpostosIcms($empresa, $numeroNfce, $sequencial, $prod, $imp) {
        $sql = $this->db->prepare("INSERT INTO nfce_produtos_impostos_icms
            (emp_codigo, numero_nfce, prod_item, prod_cprod, vTotTrib,
             prod_icms_cst, prod_icms_origem, prod_icms_modBC, prod_icms_vBC,
             prod_icms_pICMS, prod_icms_vICMS, prod_icms_pRedBC,
             prod_icms_cst_pis, prod_icms_cst_cofins,
             prod_icms_aliquota_pis, prod_icms_aliquota_cofins,
             prod_icms_valor_pis, prod_icms_valor_cofins,
             prod_icms_valor_pis_bc, prod_icms_valor_cofins_bc,
             prod_icms_pCredSN, prod_icms_vCredICMSSN)
            VALUES
            (:empresa, :numero, :seq, :cprod, :vtot,
             :cst, :origem, :modbc, :vbc,
             :picms, :vicms, :predbc,
             :cstpis, :cstcofins,
             :ppis, :pcofins,
             :vpis, :vcofins,
             :vbcpis, :vbccofins,
             :pcredsn, :vcredicmssn)");

        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':numero', $numeroNfce);
        $sql->bindValue(':seq', $sequencial);
        $sql->bindValue(':cprod', $prod['referencia']);
        $sql->bindValue(':vtot', $imp['vTotTrib']);
        $sql->bindValue(':cst', $imp['cst']);
        $sql->bindValue(':origem', $imp['origem']);
        $sql->bindValue(':modbc', $imp['modBC']);
        $sql->bindValue(':vbc', $imp['vBC']);
        $sql->bindValue(':picms', $imp['pICMS']);
        $sql->bindValue(':vicms', $imp['vICMS']);
        $sql->bindValue(':predbc', $imp['pRedBC']);
        $sql->bindValue(':cstpis', $imp['cstPIS']);
        $sql->bindValue(':cstcofins', $imp['cstCOFINS']);
        $sql->bindValue(':ppis', $imp['pPIS']);
        $sql->bindValue(':pcofins', $imp['pCOFINS']);
        $sql->bindValue(':vpis', $imp['vPIS']);
        $sql->bindValue(':vcofins', $imp['vCOFINS']);
        $sql->bindValue(':vbcpis', $imp['vBCPIS']);
        $sql->bindValue(':vbccofins', $imp['vBCCOFINS']);
        $sql->bindValue(':pcredsn', $imp['pCredSN']);
        $sql->bindValue(':vcredicmssn', $imp['vCredICMSSN']);
        $sql->execute();
    }

    // ============================================================
    // 9. REFORMA TRIBUTÁRIA — IBS / CBS / IS
    // ============================================================

    private function processarReformaTributaria($empresa, $numeroNfce, $sequencial, $item, $prod, $imp) {
        require_once __DIR__ . '/Produto.php';
        $produtoModel = new Produto();

        // ✅ Normaliza case das chaves do $prod (evita bug pibsuf/CST_IBS_CBS)
        $prodNorm = [];

        $this->log("Item $sequencial: prod tem CST_IBS_CBS=[" . ($prod['CST_IBS_CBS'] ?? $prod['cst_ibs_cbs'] ?? 'VAZIO') . "] cclasstrib=[" . ($prod['cclasstrib'] ?? 'VAZIO') . "] ncm=[" . ($prod['ncm'] ?? $prod['NCM'] ?? 'VAZIO') . "]");
      
        foreach ($prod as $k => $v) {
            $prodNorm[strtolower($k)] = $v;
        }

        // Log de diagnóstico — mostra o que chegou
        $this->log("Item $sequencial: prod tem CST_IBS_CBS=[" . ($prodNorm['cst_ibs_cbs'] ?? 'VAZIO') . "] cclasstrib=[" . ($prodNorm['cclasstrib'] ?? 'VAZIO') . "] ncm=[" . ($prodNorm['ncm'] ?? 'VAZIO') . "]");

        $rtc    = null;
        $origem = '';

        // ============================================================
        // 9.1 — PRIORIDADE 1: Cadastro do PRODUTO
        // ============================================================
        if (!empty($prodNorm['cst_ibs_cbs']) && !empty($prodNorm['cclasstrib'])) {
            $rtc = [
                'CST_IBS_CBS'    => $prodNorm['cst_ibs_cbs'],
                'cclasstrib'     => $prodNorm['cclasstrib'],
                'pRedIBS'        => (float)($prodNorm['predibs']    ?? 0),
                'pRedCBS'        => (float)($prodNorm['predcbs']    ?? 0),
                'pRedIBSMun'     => (float)($prodNorm['predibsmun'] ?? 0),
                'AliquotaIBSUF'  => (float)($prodNorm['aliquotaibsuf']  ?? 0),
                'AliquotaIBSMun' => (float)($prodNorm['aliquotaibsmun'] ?? 0),
                'AliquotaCBS'    => (float)($prodNorm['aliquotacbs']    ?? 0),
            ];
            $origem = 'produto';
        }

        // ============================================================
        // 9.2 — PRIORIDADE 2: NCM (dfe_ncm_nbs_ibs_cbs + tab_tipooperacao_rtc)
        // ============================================================
        if (!$rtc && !empty($prodNorm['ncm'])) {
            $ncmRtc = $produtoModel->buscarRtcPorNcm($prodNorm['ncm'], $empresa);
            if ($ncmRtc) {
                $rtc = [
                    'CST_IBS_CBS'    => $ncmRtc['cst_ibs_cbs'],
                    'cclasstrib'     => $ncmRtc['cclass_trib'],
                    'pRedIBS'        => (float)($ncmRtc['predibs']    ?? 0),
                    'pRedCBS'        => (float)($ncmRtc['predcbs']    ?? 0),
                    'pRedIBSMun'     => (float)($ncmRtc['predibsmun'] ?? 0),
                    'AliquotaIBSUF'  => (float)($ncmRtc['aliquotaibsuf']  ?? 0),
                    'AliquotaIBSMun' => (float)($ncmRtc['aliquotaibsmun'] ?? 0),
                    'AliquotaCBS'    => (float)($ncmRtc['aliquotacbs']    ?? 0),
                ];
                $origem = 'ncm';
            }
        }

        // ============================================================
        // 9.3 — PRIORIDADE 3: Padrão da empresa
        // ============================================================
        if (!$rtc) {
            $padrao = $produtoModel->buscarRtcPadrao($empresa);
            if ($padrao) {
                $padraoNorm = [];
                foreach ($padrao as $k => $v) {
                    $padraoNorm[strtolower($k)] = $v;
                }
                $rtc = [
                    'CST_IBS_CBS'    => $padraoNorm['cst_ibs_cbs'] ?? '',
                    'cclasstrib'     => $padraoNorm['cclasstrib']  ?? '',
                    'pRedIBS'        => (float)($padraoNorm['predibs']    ?? 0),
                    'pRedCBS'        => (float)($padraoNorm['predcbs']    ?? 0),
                    'pRedIBSMun'     => (float)($padraoNorm['predibsmun'] ?? 0),
                    'AliquotaIBSUF'  => (float)($padraoNorm['aliquotaibsuf']  ?? 0),
                    'AliquotaIBSMun' => (float)($padraoNorm['aliquotaibsmun'] ?? 0),
                    'AliquotaCBS'    => (float)($padraoNorm['aliquotacbs']    ?? 0),
                ];
                $origem = 'padrao';
            }
        }

        if (!$rtc) {
            $this->log("Item $sequencial: Reforma sem dados");
            return;
        }

        // ✅ Aplica alíquotas 2026 (força mínimo quando vier zerado)
        $anoAtual = (int)date('Y');
        if ($anoAtual === 2026) {
            if ($rtc['AliquotaIBSUF'] < 0.10) $rtc['AliquotaIBSUF'] = 0.10;
            if ($rtc['AliquotaCBS']   < 0.90) $rtc['AliquotaCBS']   = 0.90;
            if ($rtc['AliquotaIBSMun'] > 0)   $rtc['AliquotaIBSMun'] = 0.00;
        }

        $this->log("Item $sequencial: RTC origem=$origem CST={$rtc['CST_IBS_CBS']} cClass={$rtc['cclasstrib']} pIBSUF={$rtc['AliquotaIBSUF']} pCBS={$rtc['AliquotaCBS']} pRedIBS={$rtc['pRedIBS']}");

        // ... (resto do método permanece igual — cálculo vIBSUF, INSERTs, etc.)

        $vProdBruto = $imp['vProd'];
        $dBCIBSCBS  = round($vProdBruto - $imp['vICMS'] - $imp['vCOFINS'] - $imp['vPIS'], 2);

        // IBS UF
        $pIBSUF  = (float)$rtc['AliquotaIBSUF'];
        $pRedIBS = (float)$rtc['pRedIBS'];
        if ($pRedIBS > 0) {
            $pAliqEfet = $pIBSUF * (1 - $pRedIBS / 100);
            $vIBSUF    = round($dBCIBSCBS * $pAliqEfet / 100, 2);
        } else {
            $pAliqEfet = 0;
            $vIBSUF    = round($dBCIBSCBS * $pIBSUF / 100, 2);
        }

        // IBS Municipal
        $pIBSMun    = (float)$rtc['AliquotaIBSMun'];
        $pRedIBSMun = (float)$rtc['pRedIBSMun'];
        if ($pRedIBSMun > 0) {
            $pAliqEfetMun = $pIBSMun * (1 - $pRedIBSMun / 100);
            $vIBSMun      = round($dBCIBSCBS * $pAliqEfetMun / 100, 2);
        } else {
            $pAliqEfetMun = 0;
            $vIBSMun      = round($dBCIBSCBS * $pIBSMun / 100, 2);
        }

        // CBS
        $pCBS    = (float)$rtc['AliquotaCBS'];
        $pRedCBS = (float)$rtc['pRedCBS'];
        if ($pRedCBS > 0) {
            $pAliqEfetCBS = $pCBS * (1 - $pRedCBS / 100);
            $vCBS         = round($dBCIBSCBS * $pAliqEfetCBS / 100, 2);
        } else {
            $pAliqEfetCBS = 0;
            $vCBS         = round($dBCIBSCBS * $pCBS / 100, 2);
        }

        // 9.4 — dfe_gibsuf
        $sql = $this->db->prepare("INSERT INTO dfe_gibsuf
            (empresa, nfe, produto, pIBSUF, pDif_Opc, vDif_Opc, vDevTrib_Opc,
             pRedAliq_Opc, pAliqEfet_Opc, vIBSUF, sequencial, modelo, serie, bc_ibscbs)
            VALUES
            (:empresa, :nfe, :produto, :pibsuf, 0, 0, 0,
             :pred, :paliqefet, :vibsuf, :seq, 65, :serie, :bc)");
        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':nfe', $numeroNfce);
        $sql->bindValue(':produto', $prod['referencia']);
        $sql->bindValue(':pibsuf', $pIBSUF);
        $sql->bindValue(':pred', $pRedIBS);
        $sql->bindValue(':paliqefet', $pAliqEfet);
        $sql->bindValue(':vibsuf', $vIBSUF);
        $sql->bindValue(':seq', $sequencial);
        $sql->bindValue(':serie', 1);
        $sql->bindValue(':bc', $dBCIBSCBS);
        $sql->execute();

        // 9.5 — dfe_gibsmun
        $sql = $this->db->prepare("INSERT INTO dfe_gibsmun
            (empresa, nfe, produto, pIBSMun, pDif_Opc, vDif_Opc, vDevTrib_Opc,
             pRedAliq_Opc, pAliqEfet_Opc, vIBSMun, sequencial, modelo, serie, bc_ibscbs)
            VALUES
            (:empresa, :nfe, :produto, :pibsmun, 0, 0, 0,
             :pred, :paliqefet, :vibsmun, :seq, 65, :serie, :bc)");
        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':nfe', $numeroNfce);
        $sql->bindValue(':produto', $prod['referencia']);
        $sql->bindValue(':pibsmun', $pIBSMun);
        $sql->bindValue(':pred', $pRedIBSMun);
        $sql->bindValue(':paliqefet', $pAliqEfetMun);
        $sql->bindValue(':vibsmun', $vIBSMun);
        $sql->bindValue(':seq', $sequencial);
        $sql->bindValue(':serie', 1);
        $sql->bindValue(':bc', $dBCIBSCBS);
        $sql->execute();

        // 9.6 — dfe_gcbs
        $sql = $this->db->prepare("INSERT INTO dfe_gcbs
            (empresa, nfe, produto, pCBS, pDif_Opc, vDif_Opc, vDevTrib_Opc,
             pRedAliq_Opc, pAliqEfet_Opc, vCBS, sequencial, modelo, serie, bc_ibscbs)
            VALUES
            (:empresa, :nfe, :produto, :pcbs, 0, 0, 0,
             :pred, :paliqefet, :vcbs, :seq, 65, :serie, :bc)");
        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':nfe', $numeroNfce);
        $sql->bindValue(':produto', $prod['referencia']);
        $sql->bindValue(':pcbs', $pCBS);
        $sql->bindValue(':pred', $pRedCBS);
        $sql->bindValue(':paliqefet', $pAliqEfetCBS);
        $sql->bindValue(':vcbs', $vCBS);
        $sql->bindValue(':seq', $sequencial);
        $sql->bindValue(':serie', 1);
        $sql->bindValue(':bc', $dBCIBSCBS);
        $sql->execute();

        // 9.7 — dfe_class_fiscal
        $sql = $this->db->prepare("INSERT INTO dfe_class_fiscal
            (empresa, nfe, item, produto, modelo, serie, cst, cclasstrib)
            VALUES
            (:empresa, :nfe, :item, :produto, 65, :serie, :cst, :cclasstrib)");
        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':nfe', $numeroNfce);
        $sql->bindValue(':item', $sequencial);
        $sql->bindValue(':produto', $prod['referencia']);
        $sql->bindValue(':serie', 1);
        $sql->bindValue(':cst', $rtc['CST_IBS_CBS']);
        $sql->bindValue(':cclasstrib', $rtc['cclasstrib']);
        $sql->execute();

        $this->log("  RTC: CST={$rtc['CST_IBS_CBS']} cClass={$rtc['cclasstrib']} " .
                   "vIBSUF=$vIBSUF vIBSMun=$vIBSMun vCBS=$vCBS");
    }

    // ============================================================
    // 10. TOTAIS
    // ============================================================

    private function calcularTotais($empresa, $numeroNfce) {
        $t = $this->totaisTemp;

        $vProd    = round($t['vProd']    ?? 0, 2);
        $vDesc    = round($t['vDesc']    ?? 0, 2);
        $vFrete   = round($t['vFrete']   ?? 0, 2);
        $vSeguro  = round($t['vSeguro']  ?? 0, 2);
        $vOutras  = round($t['vOutras']  ?? 0, 2);
        $vBC      = round($t['vBC']      ?? 0, 2);
        $vICMS    = round($t['vICMS']    ?? 0, 2);
        $vPIS     = round($t['vPIS']     ?? 0, 2);
        $vCOFINS  = round($t['vCOFINS']  ?? 0, 2);
        $vTotTrib = round($t['vTotTrib'] ?? 0, 2);

        $vNF = round($vProd - $vDesc + $vFrete + $vSeguro + $vOutras, 2);

        $rtcTotal = $this->somarTotaisReforma($empresa, $numeroNfce);
        $vIBS     = $rtcTotal['vIBS']    ?? 0;
        $vIBSUF   = $rtcTotal['vIBSUF']  ?? 0;
        $vIBSMun  = $rtcTotal['vIBSMun'] ?? 0;
        $vCBS     = $rtcTotal['vCBS']    ?? 0;
        $bcIBS    = $rtcTotal['bcIBS']   ?? 0;

        // 10.1 — nfce_totais
        $sql = $this->db->prepare("INSERT INTO nfce_totais
            (emp_codigo, numero_nfce,
            tot_icms_vBC, tot_icms_vICMS, tot_icms_vBCST, tot_icms_vST,
            tot_icms_vProd, tot_icms_vFrete, tot_icms_vSeg, tot_icms_Desc,
            tot_icms_vII, tot_icms_vIPI, tot_icms_vIPIDevol,
            tot_icms_vPIS, tot_icms_vCOFINS, tot_icms_vOutro, tot_icms_vNF,
            vTotTrib,
            tot_icms_vFCP, tot_icms_vFCPST, tot_icms_vFCPSTRet)
            VALUES
            (:empresa, :numero,
            :vbc, :vicms, 0, 0,
            :vprod, :vfrete, :vseguro, :vdesc,
            0, 0, 0,
            :vpis, :vcofins, :voutro, :vnf,
            :vtot,
            0, 0, 0)");

        $sql->bindValue(':empresa',  $empresa);
        $sql->bindValue(':numero',   $numeroNfce);
        $sql->bindValue(':vbc',      $vBC);
        $sql->bindValue(':vicms',    $vICMS);
        $sql->bindValue(':vprod',    $vProd);
        $sql->bindValue(':vfrete',   $vFrete);
        $sql->bindValue(':vseguro',  $vSeguro);
        $sql->bindValue(':vdesc',    $vDesc);
        $sql->bindValue(':vpis',     $vPIS);
        $sql->bindValue(':vcofins',  $vCOFINS);
        $sql->bindValue(':voutro',   $vOutras);
        $sql->bindValue(':vnf',      $vNF);
        $sql->bindValue(':vtot',     $vTotTrib);
        $sql->execute();

        $this->log("nfce_totais: vProd=$vProd vNF=$vNF vDesc=$vDesc vICMS=$vICMS");

        // ✅ Totais da Reforma — SEMPRE gravar (SEFAZ exige IBSCBSTot se houver item com IBS/CBS)
        //    Mesmo zerados (caso de redução 100%), os totais são obrigatórios
        $this->inserirTotaisReforma($empresa, $numeroNfce, $vIBS, $vIBSUF, $vIBSMun, $vCBS);
    }

    private function inserirTotaisReforma($empresa, $numeroNfce, $vIBS, $vIBSUF, $vIBSMun, $vCBS) {
        // --- IBS ---
        try {
            $sql = $this->db->prepare("INSERT INTO dfe_gibstotal
                (empresa, nfe,
                vDif_UF, vDevTrib_UF, vIBSUF_UF,
                vDif_Mun, vDevTrib_Mun, vIBS_Mun,
                vIBS, vCredPres, vCredPresCondSus,
                modelo, serie)
                VALUES
                (:empresa, :nfe,
                0, 0, :vibsuf,
                0, 0, :vibsmun,
                :vibs, 0, 0,
                65, 1)");

            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':nfe',     $numeroNfce);
            $sql->bindValue(':vibsuf',  $vIBSUF);
            $sql->bindValue(':vibsmun', $vIBSMun);
            $sql->bindValue(':vibs',    $vIBS);
            $sql->execute();

            $this->log("dfe_gibstotal: vIBS=$vIBS vIBSUF_UF=$vIBSUF vIBS_Mun=$vIBSMun");
        } catch (Exception $e) {
            $this->log("Aviso dfe_gibstotal: " . $e->getMessage());
        }

        // --- CBS ---
        try {
            $sql = $this->db->prepare("INSERT INTO dfe_gcbstotal
                (empresa, nfe,
                vDif, vDevTrib, vCBS,
                vCredPres, vCredPresCondSus,
                modelo, serie)
                VALUES
                (:empresa, :nfe,
                0, 0, :vcbs,
                0, 0,
                65, 1)");

            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':nfe',     $numeroNfce);
            $sql->bindValue(':vcbs',    $vCBS);
            $sql->execute();

            $this->log("dfe_gcbstotal: vCBS=$vCBS");
        } catch (Exception $e) {
            $this->log("Aviso dfe_gcbstotal: " . $e->getMessage());
        }
    }
    private function somarTotaisReforma($empresa, $numeroNfce) {
        $result = [
            'vIBS' => 0, 'vIBSUF' => 0, 'vIBSMun' => 0,
            'vCBS' => 0, 'vIS' => 0,
            'bcIBS' => 0, 'bcIS' => 0,
        ];

        $result['vIBSUF']  = $this->somarCampo('dfe_gibsuf', 'vIBSUF',   $empresa, $numeroNfce);
        $result['bcIBS']   = $this->somarCampo('dfe_gibsuf', 'bc_ibscbs', $empresa, $numeroNfce);
        $result['vIBSMun'] = $this->somarCampo('dfe_gibsmun', 'vIBSMun', $empresa, $numeroNfce);
        $result['vCBS']    = $this->somarCampo('dfe_gcbs',   'vCBS',     $empresa, $numeroNfce);
        $result['vIS']     = $this->somarCampo('dfe_is',     'vIS',      $empresa, $numeroNfce);
        $result['bcIS']    = $this->somarCampo('dfe_is',     'vBCIS',    $empresa, $numeroNfce);

        $result['vIBS'] = round($result['vIBSUF'] + $result['vIBSMun'], 2);

        return $result;
    }

    private function somarCampo($tabela, $campo, $empresa, $numeroNfce) {
        try {
            $sql = $this->db->prepare("SELECT COALESCE(SUM($campo), 0) AS total
                                       FROM $tabela
                                       WHERE empresa = :empresa AND nfe = :nfe");
            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':nfe', $numeroNfce);
            $sql->execute();
            $row = $sql->fetch(PDO::FETCH_ASSOC);
            return round((float)$row['total'], 2);
        } catch (Exception $e) {
            return 0;
        }
    }

    // ============================================================
    // 11. FORMAS DE PAGAMENTO
    // ============================================================

    private function criarFormasPagamento($empresa, $numeroNfce, $vendaDados) {
        $config    = $this->carregarConfiguracoes($empresa);
        $versaoXml = $config['versao'] ?? self::VERSAO_NFCE;
        $this->log("Formas - Versão XML: $versaoXml");

        // 11.1 — Buscar formas recebidas (agrupadas por tPag)
        $sql = $this->db->prepare("SELECT
                rf.parcela,
                rf.ordem,
                rf.sequencial,
                rf.forma_pgto,
                tf.descricao,
                SUM(rf.valor_recebido) AS valor_total,
                tf.nfce_formapgto_vinculada,
                rec.valor_troco
            FROM recebido_formas rf
            INNER JOIN tab_formapagamento tf
                ON rf.forma_pgto = tf.codigo
            INNER JOIN recebido rec
                ON rec.empresa = rf.empresa
                AND rec.cliente = rf.cliente
                AND rec.documento = rf.documento
                AND rec.ordem = rf.ordem
                AND rec.parcela = rf.parcela
            WHERE rf.empresa = :empresa
            AND rf.cliente = :cliente
            AND rf.documento = :documento
            AND rf.parcela = 0
            GROUP BY tf.nfce_formapgto_vinculada, tf.descricao
            ORDER BY rf.sequencial");

        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':cliente', $vendaDados['cliente']);
        $sql->bindValue(':documento', $vendaDados['venda']);
        $sql->execute();
        $formas = $sql->fetchAll(PDO::FETCH_ASSOC);

        if (empty($formas)) {
            $this->log("AVISO: Nenhuma forma encontrada em recebido_formas");
            return;
        }

        $this->log("Formas encontradas: " . count($formas));

        // 11.2 — Troco (único, vai na última linha)
        $trocoTotal = 0;
        foreach ($formas as $f) {
            if (!empty($f['valor_troco']) && (float)$f['valor_troco'] > 0) {
                $trocoTotal = round((float)$f['valor_troco'], 2);
                break;
            }
        }

        // 11.3 — Inserir cada forma
        $total = count($formas);
        $idx   = 0;
        $somaValores = 0;

        foreach ($formas as $f) {
            $idx++;

            $valorRecebido = round((float)$f['valor_total'], 2);

            // tot_fp_codigo é INT — converte '01' → 1, '17' → 17, '99' → 99
            $codigoFp = (int)($f['nfce_formapgto_vinculada'] ?? 99);
            if ($codigoFp <= 0) $codigoFp = 99;

            $descricaoFp = $f['descricao'] ?? '';

            // Regra VB6: 4.00 → valor cheio; <4.00 → valor - troco
            $vPag = ($versaoXml === self::VERSAO_NFCE)
                ? $valorRecebido
                : round($valorRecebido - $trocoTotal, 2);

            $trocoAplicado = ($idx === $total) ? $trocoTotal : 0;

            try {
                $sqlIns = $this->db->prepare("INSERT INTO nfce_totais_formapgto
                    (emp_codigo, numero_nfce, tot_fp_codigo,
                    tot_fp_descricao, tot_fp_valor, tot_fp_vTroco)
                    VALUES
                    (:empresa, :numero, :codigo,
                    :descricao, :valor, :troco)");

                $sqlIns->bindValue(':empresa',   $empresa);
                $sqlIns->bindValue(':numero',    $numeroNfce);
                $sqlIns->bindValue(':codigo',    $codigoFp);
                $sqlIns->bindValue(':descricao', $descricaoFp);
                $sqlIns->bindValue(':valor',     $vPag);
                $sqlIns->bindValue(':troco',     $trocoAplicado);
                $sqlIns->execute();

                $this->log("  tot_fp_codigo=$codigoFp vPag=$vPag troco=$trocoAplicado ({$descricaoFp})");
                $somaValores += $vPag;

            } catch (Exception $e) {
                $this->log("Erro ao inserir forma (com troco): " . $e->getMessage());
                // Fallback: sem troco
                try {
                    $sqlIns = $this->db->prepare("INSERT INTO nfce_totais_formapgto
                        (emp_codigo, numero_nfce, tot_fp_codigo,
                        tot_fp_descricao, tot_fp_valor)
                        VALUES
                        (:empresa, :numero, :codigo, :descricao, :valor)");
                    $sqlIns->bindValue(':empresa',   $empresa);
                    $sqlIns->bindValue(':numero',    $numeroNfce);
                    $sqlIns->bindValue(':codigo',    $codigoFp);
                    $sqlIns->bindValue(':descricao', $descricaoFp);
                    $sqlIns->bindValue(':valor',     $vPag);
                    $sqlIns->execute();
                } catch (Exception $e2) {
                    $this->log("Erro crítico forma: " . $e2->getMessage());
                }
            }
        }

        // 11.4 — Conferência (aviso, não bloqueia)
        $totalVenda = round((float)$vendaDados['total'], 2) + $trocoTotal;
        if (abs($somaValores - $totalVenda) > 0.01) {
            $this->log("⚠️ ATENÇÃO: Soma formas ($somaValores) ≠ Total venda ($totalVenda)");
        }
    }            
   
    private function sincronizarTefParaNfce($empresa, $numeroNfce, $venda) {
        try {
            $sql = $this->db->prepare("SELECT codigo, forma_pagto, credenciadora, bandeira_cartao,
                                              codigo_transacao, valor, troco, tipo,
                                              NSU, data_c, hora_c, tipo_finan, modalidade,
                                              rede_adquirente, ordem, qtd_parcelas,
                                              id_terminal, codigo_controle
                                       FROM vendas_tef
                                       WHERE empresa = :emp AND venda = :venda
                                         AND (cancelado = 0 OR cancelado IS NULL)
                                       ORDER BY ordem ASC");
            $sql->bindValue(':emp',   $empresa);
            $sql->bindValue(':venda', $venda);
            $sql->execute();
            $tefs = $sql->fetchAll(PDO::FETCH_ASSOC);

            if (empty($tefs)) {
                $this->log("TEF: nenhum registro em vendas_tef para venda $venda");
                return;
            }

            $this->log("TEF: " . count($tefs) . " registro(s) encontrado(s) para venda $venda");

            foreach ($tefs as $t) {
                try {
                    
                    $codTef = $t['codigo'];

                    // Atenção: coluna é forma_pgto em nfce_tef (não forma_pagto)
                    $fp = (int)$t['forma_pagto'];

                    // tipo em nfce_tef espelha vendas_tef (char(1), sempre '1')
                    $tipo = '1';

                    $ins = $this->db->prepare("INSERT INTO nfce_tef
                        (empresa, nfce, codigo, forma_pagto, credenciadora,
                         bandeira_cartao, codigo_transacao, valor, troco, tipo)
                        VALUES
                        (:emp, :nfce, :cod, :fp, :cred,
                         :band, :ct, :valor, :troco, :tipo)");
                    $ins->bindValue(':emp',   $empresa);
                    $ins->bindValue(':nfce',  $numeroNfce);
                    $ins->bindValue(':cod',   $codTef);
                    $ins->bindValue(':fp',    $fp, PDO::PARAM_INT);
                    $ins->bindValue(':cred',  isset($t['credenciadora'])    ? $t['credenciadora']    : '');
                    $ins->bindValue(':band',  isset($t['bandeira_cartao'])  ? $t['bandeira_cartao']  : '');
                    $ins->bindValue(':ct',    isset($t['codigo_transacao']) ? $t['codigo_transacao'] : '');
                    $ins->bindValue(':valor', (float)$t['valor']);
                    $ins->bindValue(':troco', (float)$t['troco']);
                    $ins->bindValue(':tipo',  $tipo);
                    $ins->execute();

                    $this->log("nfce_tef: INSERT ok nfce=$numeroNfce cod=$codTef fp=$fp");
                } catch (Throwable $e) {
                    $this->log("Erro nfce_tef (cod=" . (isset($codTef) ? $codTef : '?') . "): "
                             . get_class($e) . " — " . $e->getMessage()
                             . " @ " . $e->getFile() . ":" . $e->getLine());
                }
            }

            // (b) Enriquecer nfce_totais_formapgto com <card>
            $this->aplicarCartaoEmFormapgto($empresa, $numeroNfce, $tefs);

        } catch (Throwable $e) {
            $this->log("Erro sincronizarTefParaNfce: " . get_class($e) . " — "
                     . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
        }
    }

        /**
     * Devolve o SQL com os placeholders substituídos pelos valores,
     * para log/manual. NÃO usar para executar — só para debug.
     */
    private function sqlLogavel($sql, $params) {
        $out = $sql;
        // Ordena por tamanho decrescente para evitar :emp casar dentro de :empresa
        $keys = array_keys($params);
        usort($keys, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        foreach ($keys as $k) {
            $v = $params[$k];
            if ($v === null) {
                $rep = 'NULL';
            } elseif (is_int($v) || is_float($v)) {
                $rep = (string)$v;
            } elseif (is_bool($v)) {
                $rep = $v ? '1' : '0';
            } else {
                $rep = "'" . str_replace("'", "''", (string)$v) . "'";
            }
            $out = str_replace($k, $rep, $out);
        }
        return $out;
    }


    private function aplicarCartaoEmFormapgto($empresa, $numeroNfce, $tefs) {
        // ⚠️ Ajustar nomes se o SHOW COLUMNS retornar diferente
        $candidatas = ['tot_fp_tpIntegra', 'tot_fp_CNPJ', 'tot_fp_tBand', 'tot_fp_cAut'];
        $cols = $this->colunasExistentes('nfce_totais_formapgto', $candidatas);

        if (empty($cols)) {
            $this->log("TEF: nfce_totais_formapgto sem colunas de cartão — pulando enriquecimento");
            return;
        }

        foreach ($tefs as $t) {
            $fp = (int)$t['forma_pagto'];  // não forma_pgto

            $set    = [];
            $params = [':emp' => $empresa, ':nfce' => $numeroNfce, ':fp' => $fp];

            if (in_array('tot_fp_tpIntegra', $cols, true)) {
                $set[] = 'tot_fp_tpIntegra = :tpIntegra';
                $params[':tpIntegra'] = 1; // 1 = integrado
            }
            if (in_array('tot_fp_CNPJ', $cols, true)) {
                $set[] = 'tot_fp_CNPJ = :cnpj';
                $params[':cnpj'] = $this->soNumeros($t['credenciadora'] ?? '');
            }
            if (in_array('tot_fp_tBand', $cols, true)) {
                $set[] = 'tot_fp_tBand = :tband';
                $params[':tband'] = $t['bandeira_cartao'] ?? '';
            }
            if (in_array('tot_fp_cAut', $cols, true)) {
                $set[] = 'tot_fp_cAut = :caut';
                $params[':caut'] = $t['codigo_transacao'] ?? '';
            }

            if (empty($set)) return;

            $sql = "UPDATE nfce_totais_formapgto SET " . implode(', ', $set) . "
                    WHERE emp_codigo = :emp AND numero_nfce = :nfce AND tot_fp_codigo = :fp";

            try {
                $stmt = $this->db->prepare($sql);
                foreach ($params as $k => $v) $stmt->bindValue($k, $v);
                $stmt->execute();
                $this->log("TEF: cartão aplicado em fp=$fp (" . $stmt->rowCount() . " linha(s))");
            } catch (Exception $e) {
                $this->log("Erro aplicar cartão fp=$fp: " . $e->getMessage());
            }
        }
    }

    private function colunasExistentes($tabela, $candidatas) {
        $ok = [];
        foreach ($candidatas as $col) {
            try {
                $sql = $this->db->prepare("SHOW COLUMNS FROM $tabela LIKE :col");
                $sql->bindValue(':col', $col);
                $sql->execute();
                if ($sql->rowCount() > 0) $ok[] = $col;
            } catch (Exception $e) {
                // coluna não existe — segue
            }
        }
        return $ok;
    }

    // ============================================================
    // 12. HELPERS
    // ============================================================

    private function obterCodigoMunicipio($empresa) {
        $sql = $this->db->prepare("SELECT cidade FROM tabempresa WHERE codigo = :empresa");
        $sql->bindValue(':empresa', $empresa);
        $sql->execute();
        $row = $sql->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['cidade'] : self::MUNICIPIO_PADRAO_MT;
    }

    private function soNumeros($texto) {
        return preg_replace('/\D/', '', (string)$texto);
    }

    private function erro($mensagem) {
        return ['sucesso' => false, 'mensagem' => $mensagem];
    }

    public function testarConexaoDb() {
        $resultado = ['db_existe' => false, 'tipo_db' => null, 'teste_query' => null];
        try {
            if (isset($this->db)) {
                $resultado['db_existe'] = true;
                $resultado['tipo_db']   = get_class($this->db);
                $sql = $this->db->query("SELECT 1 AS teste");
                $row = $sql->fetch(PDO::FETCH_ASSOC);
                $resultado['teste_query'] = $row['teste'] ?? null;
            }
        } catch (Throwable $t) {
            $resultado['erro'] = $t->getMessage();
        }
        return $resultado;
    }

    // ============================================================
    // 13. LOG
    // ============================================================

    private function log($msg) {
        if (!$this->debug) return;
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        @file_put_contents($this->logPath, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
    }

}