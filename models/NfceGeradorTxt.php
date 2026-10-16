<?php
/**
 * NfceGeradorTxt.php
 *
 * Gera o arquivo TXT no formato TecnoSpeed (chave=valor por linha)
 * a partir das tabelas nfce_* e dfe_*, replicando a lógica do VB6.
 *
 * Estrutura do TXT:
 *   INCLUIR
 *   <identificação>
 *   <emitente>
 *   <destinatário>
 *   INCLUIRITEM / <impostos> / SALVARITEM   (repetido por item)
 *   <totais>
 *   INCLUIRPARTE=YA / <formas> / SALVARPARTE=YA  (repetido por tPag)
 *   vTroco_YA09=... (se > 0)
 *   infCpl_Z03=...
 *   <resp técnico>
 *   SALVAR
 */

class NfceGeradorTxt extends model {

    const LK_TXT_DATASET  = 1;
    const VERSAO_ESQUEMA  = 'pl_009_V4';   // ajuste conforme seu VB6

    private $empresa;
    private $numeroNfce;
    private $versaoXml;
    private $ambiente;      // 1=prod, 2=homolog
    private $serie;
    private $crt;
    private $pastaBase;
    private $linhas = [];
    private $debug = true;
    private $logPath;

    // Acumuladores da Reforma Tributária
    private $bcMonoRet61  = 0;
    private $vIcmsMono61  = 0;

    public function __construct() {
        parent::__construct();
        $this->logPath = __DIR__ . '/../logs/nfce_txt.log';
    }

    /**
     * Gera o TXT e retorna o caminho absoluto.
     * @return array{sucesso: bool, caminho?: string, mensagem: string}
     */
    public function gerar($empresa, $numeroNfce) {
        try {
            $this->empresa    = (int)$empresa;
            $this->numeroNfce = (int)$numeroNfce;

            $this->carregarConfiguracoes();
            $this->montarLinhas();

            $caminho = $this->salvarArquivo();

            $this->log("TXT gerado: $caminho");
            return ['sucesso' => true, 'caminho' => $caminho, 'mensagem' => 'TXT gerado'];

        } catch (Throwable $t) {
            $this->log("ERRO: " . $t->getMessage());
            return ['sucesso' => false, 'mensagem' => 'Erro ao gerar TXT: ' . $t->getMessage()];
        }
    }

    // ============================================================
    // CONFIGURAÇÃO
    // ============================================================

    private function carregarConfiguracoes() {
        $sql = $this->db->prepare("SELECT * FROM param_nfce WHERE emp_codigo = :emp LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->execute();
        $row = $sql->fetch(PDO::FETCH_ASSOC);

        $this->versaoXml = $row['pnfce_versao']         ?? '4.00';
        $this->ambiente  = (int)($row['pnfce_ambiente'] ?? 2);
        $this->serie     = (int)($row['pnfce_serie']    ?? 1);
        $this->pastaBase = $row['pnfce_pasta_arquivos'] ?? 'C:\\WDS Comercial\\NFCeGerada';

        // CRT da empresa
        $sql = $this->db->prepare("SELECT crt FROM tabempresa WHERE codigo = :emp LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->execute();
        $this->crt = (int)($sql->fetchColumn() ?: 3);

        $this->log("Config: versao={$this->versaoXml} ambiente={$this->ambiente} serie={$this->serie} crt={$this->crt}");
    }

    // ============================================================
    // MONTAGEM
    // ============================================================

    private function montarLinhas() {
        $this->linhas = [];

        // Cabeçalho do arquivo
        $this->linhas[] = 'INCLUIR';

        $this->blocoIdentificacao();
        $this->blocoEmitente();
        $this->blocoDestinatario();
        $this->blocoProdutos();     // inclui INCLUIRITEM/SALVARITEM
        $this->blocoTotais();
        $this->blocoReformaTotal();
        $this->blocoFormasPagamento();
        $this->blocoTroco();
        $this->blocoInfoComplementares();
        $this->blocoRespTecnico();

        // Rodapé
        $this->linhas[] = 'SALVAR';
    }

    private function add($chave, $valor) {
        $this->linhas[] = $chave . '=' . $valor;
    }

    // ============================================================
    // BLOCO 1 — IDENTIFICAÇÃO
    // ============================================================

    private function blocoIdentificacao() {
        $sql = $this->db->prepare("SELECT * FROM nfce_identificacao
                                   WHERE emp_codigo = :emp AND numero_nfce = :num LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new Exception("nfce_identificacao não encontrada");

        // cNF: empresa + série + número + ambiente (conforme VB6)
        $cNF = $this->empresa . $r['ide_serie_documento'] . $this->numeroNfce . $r['ide_tpAmb'];

        $this->add('Id_A03', '');
        $this->add('versao_A02', trim($r['ide_versao']));
        $this->add('verProc_B27', '0');
        $this->add('cNF_B03', $cNF);
        $this->add('natOp_B04', $r['ide_natoperacao']);
        if ($this->versaoXml === '3.10') {
            $this->add('indPag_B05', $r['ide_indpag']);
        }
        $this->add('mod_B06', $r['ide_modelo_documento']);
        $this->add('serie_B07', $r['ide_serie_documento']);
        $this->add('nNF_B08', $r['numero_nfce']);
        $this->add('cUF_B02', $r['ide_cUF']);

        // dhEmi com fuso -04:00 (Cuiabá)
        $dhEmi = date('Y-m-d\TH:i:s') . '-04:00';
        $this->add('dhEmi_B09', $dhEmi);
        $this->add('tpNF_B11', $r['ide_tpnf']);
        $this->add('idDest_B11a', '1');
        $this->add('cMunFG_B12', $r['ide_cMunFG']);
        $this->add('tpImp_B21', $r['ide_tpimp']);
        $this->add('tpEmis_B22', $r['ide_tpEmis']);
        $this->add('cDV_B23', '');
        $this->add('tpAmb_B24', $r['ide_tpAmb']);
        $this->add('finNFe_B25', $r['ide_finNFe']);
        $this->add('indFinal_B25a', '1');
        $this->add('indPres_B25b', $r['ide_indpres'] ?: '1');
        $this->add('indIntermed_B25c', $r['ide_indIntermed_Opc'] ?? '0');

        if ((int)$r['ide_tpEmis'] === 9) {
            $this->add('dhCont_B28', $r['ide_dhCont']);
            $this->add('xJust_B29', trim($r['ide_xJust_Cont']));
        }
        $this->add('procEmi_B26', '0');
    }

    // ============================================================
    // BLOCO 2 — EMITENTE
    // ============================================================

    private function blocoEmitente() {
        $sql = $this->db->prepare("SELECT * FROM nfce_emitente
                                   WHERE emp_codigo = :emp AND numero_nfce = :num LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new Exception("nfce_emitente não encontrada");

        $this->add('CNPJ_C02', $r['emi_cnpj']);
        $this->add('CRT_C21', $r['emi_crt']);
        $this->add('xNome_C03', $r['emi_xNome']);
        $this->add('xFant_C04', $r['emi_xFant']);
        $this->add('xLgr_C06', $r['emi_xLgr']);
        $this->add('nro_C07', $r['emi_nro']);
        $this->add('xBairro_C09', $r['emi_xBairro']);
        $this->add('cMun_C10', $r['emi_cMun']);
        $this->add('xMun_C11', $r['emi_xMun']);
        $this->add('UF_C12', $r['emi_uf']);
        $this->add('CEP_C13', $r['emi_cep']);
        $this->add('cPais_C14', $r['emi_pais']);
        $this->add('xPais_C15', $r['emi_xPais']);
        $this->add('fone_C16', $r['emi_fone']);
        $this->add('IE_C17', $r['emi_ie']);
        if (!empty($r['emi_iest'])) {
            $this->add('IEST_C18', $r['emi_iest']);
        }
    }

    // ============================================================
    // BLOCO 3 — DESTINATÁRIO
    // ============================================================

    private function blocoDestinatario() {
        $sql = $this->db->prepare("SELECT * FROM nfce_destinatario
                                   WHERE emp_codigo = :emp AND numero_nfce = :num LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$r) return;   // consumidor final não gera

        if (!empty($r['dest_cnpj'])) $this->add('CNPJ_E02', $r['dest_cnpj']);
        if (!empty($r['dest_cpf']))  $this->add('CPF_E03',  $r['dest_cpf']);
        if (!empty($r['dest_xNome'])) $this->add('xNome_E04', $r['dest_xNome']);

        $this->add('indIEDest_E16a', '9');

        if (!empty($r['dest_xLgr']))   $this->add('xLgr_E06',   $r['dest_xLgr']);
        if (!empty($r['dest_nro']))    $this->add('nro_E07',    $r['dest_nro']);
        if (!empty($r['dest_xBairro']))$this->add('xBairro_E09',$r['dest_xBairro']);
        if (!empty($r['dest_cMun']))   $this->add('cMun_E10',   $r['dest_cMun']);
        if (!empty($r['dest_xMun']))   $this->add('xMun_E11',   $r['dest_xMun']);
        if (!empty($r['dest_uf']))     $this->add('UF_E12',     $r['dest_uf']);
        if (!empty($r['dest_cep']))    $this->add('CEP_E13',    $r['dest_cep']);
        if (!empty($r['dest_pais']))   $this->add('cPais_E14',  $r['dest_pais']);
        if (!empty($r['dest_xPais']))  $this->add('xPais_E15',  $r['dest_xPais']);
        if (!empty($r['dest_fone']))   $this->add('fone_E16',   $r['dest_fone']);
    }

    // ============================================================
    // BLOCO 4 — PRODUTOS
    // ============================================================

    private function blocoProdutos() {
        $sql = $this->db->prepare("SELECT * FROM nfce_produtos
                                WHERE emp_codigo = :emp AND numero_nfce = :num
                                ORDER BY prod_item ASC");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $itens = $sql->fetchAll(PDO::FETCH_ASSOC);

        if (empty($itens)) throw new Exception("Venda sem produtos em nfce_produtos");

        $seq = 1;
        foreach ($itens as $p) {
            $this->linhas[] = 'INCLUIRITEM';
            $this->add('nItem_H02', $seq);

            $this->add('cEAN_I03', $p['prod_cEAN']);
            $this->add('cProd_I02', $p['prod_cprod']);
            $this->add('xProd_I04', $p['prod_xProd']);
            $this->add('NCM_I05', $p['prod_NCM']);
            if (!empty($p['prod_CEST'])) {
                $this->add('CEST_I05c', $p['prod_CEST']);
            }
            $cfopLimpo = str_replace('.', '', $p['prod_cfop']);
            $this->add('CFOP_I08', $cfopLimpo);
            $this->add('uCom_I09', $p['prod_uCom']);

            // Quantidades e valores unitários: até 4 casas (TDec_1104v)
            $this->add('qCom_I10',     $this->fmtDec($p['prod_qcom'], 4));
            $this->add('vUnCom_I10a',  $this->fmtDec($p['prod_vUnCom'], 4));

            // Valor total bruto: 2 casas (TDec_1302)
            $this->add('vProd_I11',    $this->fmtDec($p['prod_vProd'], 2));

            $this->add('cEANTrib_I12', $p['prod_cEANTrib']);
            $this->add('uTrib_I13',    $p['prod_uTrib']);

            // Quantidade e valor unitário tributável: 4 casas
            $this->add('qTrib_I14',    $this->fmtDec($p['prod_qTrib'], 4));
            $this->add('vUnTrib_I14a', $this->fmtDec($p['prod_vUnTrib'], 4));

            // Valores monetários complementares: 2 casas
            if ((float)$p['prod_vOutro'] > 0) $this->add('vOutro_I17a', $this->fmtDec($p['prod_vOutro'], 2));
            if ((float)$p['prod_vFrete'] > 0) $this->add('vFrete_I15',  $this->fmtDec($p['prod_vFrete'], 2));
            $this->add('indTot_I17b', '1');
            if ((float)$p['vTotTrib'] > 0)   $this->add('vTotTrib_M02', $this->fmtDec($p['vTotTrib'], 2));
            if ((float)$p['prod_vDesc'] > 0) $this->add('vDesc_I17',    $this->fmtDec($p['prod_vDesc'], 2));

            $this->blocoImpostosItem($p);

            $this->linhas[] = 'SALVARITEM';
            $seq++;
        }
    }

    // ============================================================
    // BLOCO 5 — IMPOSTOS DO ITEM (ICMS + PIS + COFINS)
    // ============================================================

    private function blocoImpostosItem($prod) {
        $sql = $this->db->prepare("SELECT i.*, p.codigo_cfop
                                FROM nfce_produtos_impostos_icms i
                                INNER JOIN nfce_produtos p
                                    ON p.emp_codigo = i.emp_codigo
                                    AND p.numero_nfce = i.numero_nfce
                                    AND p.prod_item = i.prod_item
                                WHERE i.emp_codigo = :emp
                                    AND i.numero_nfce = :num
                                    AND i.prod_item = :item LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->bindValue(':item', $prod['prod_item']);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$r) return;

        // ============ ICMS ============
        if ($this->crt === 3 || $this->crt === 2) {
            $this->add('orig_N11', $r['prod_icms_origem']);
            $this->add('CST_N12', $r['prod_icms_cst']);

            if (in_array(trim($r['prod_icms_cst']), ['00','10','20','70'])) {
                $this->add('modBC_N13', $r['prod_icms_modBC']);
                $this->add('vBC_N15', $this->fmtDec($r['prod_icms_vBC']));
                $this->add('pICMS_N16', $this->fmtDec($r['prod_icms_pICMS']));
                $this->add('vICMS_N17', $this->fmtDec($r['prod_icms_vICMS']));

                if ($this->versaoXml === '4.00' && (float)$r['prod_icms_pFCP'] > 0) {
                    $this->add('pFCP_N17b', $this->fmtDec($r['prod_icms_pFCP']));
                    $this->add('vFCP_N17c', $this->fmtDec($r['prod_icms_vFCP']));
                }
            }
        } elseif ($this->crt === 1) {
            $this->add('orig_N11', $r['prod_icms_origem']);
            $this->add('CSOSN_N12a', $r['prod_icms_cst']);
        }

        // ============ PIS ============
        $cstPis = trim($r['prod_icms_cst_pis']);
        if (in_array($cstPis, ['01','02','04','06','49','50','51','52','53','54','55','56','60','61','62','63','64','65','66','67','70','71','72','73','74','75','98','99'])) {
            $this->add('CST_Q06', $cstPis);
            $this->add('vBC_Q07', $this->fmtDec($r['prod_icms_valor_pis_bc']));
            $this->add('pPIS_Q08', $this->fmtDec($r['prod_icms_aliquota_pis']));
            $this->add('vPIS_Q09', $this->fmtDec($r['prod_icms_valor_pis']));
        }

        // ============ COFINS ============
        $cstCofins = trim($r['prod_icms_cst_cofins']);
        if (in_array($cstCofins, ['01','02','04','06','49','50','51','52','53','54','55','56','60','61','62','63','64','65','66','67','70','71','72','73','74','75','98','99'])) {
            $this->add('CST_S06', $cstCofins);
            $this->add('vBC_S07', $this->fmtDec($r['prod_icms_valor_cofins_bc']));
            $this->add('pCOFINS_S08', $this->fmtDec($r['prod_icms_aliquota_cofins']));
            $this->add('vCOFINS_S11', $this->fmtDec($r['prod_icms_valor_cofins']));
        }

        // ============ REFORMA TRIBUTÁRIA (IBSCBS por item) ============
        $this->blocoReformaItem($prod['prod_item'], $prod['prod_cprod']);
    }

    /**
     * Bloco IBSCBS por item (Reforma Tributária) — replica VB6:
     * fBuscarImpostoReforma → CST_UB13, cClassTrib_UB14, gTributo
     */
    private function blocoReformaItem($seqItem, $codProduto) {
        // 1. Classificação fiscal (CST + cClassTrib)
        $sql = $this->db->prepare("SELECT cst, cclasstrib FROM dfe_class_fiscal
                                WHERE empresa = :emp AND nfe = :nfe
                                    AND item = :item AND modelo = 65 AND serie = :serie
                                LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':nfe', $this->numeroNfce);
        $sql->bindValue(':item', $seqItem);
        $sql->bindValue(':serie', $this->serie);
        $sql->execute();
        $class = $sql->fetch(PDO::FETCH_ASSOC);

        if (!$class) {
            $this->log("Item $seqItem: sem dfe_class_fiscal — pulando IBSCBS");
            return;
        }

        $this->add('CST_UB13', $class['cst']);
        $this->add('cClassTrib_UB14', $class['cclasstrib']);

        // 2. Base + alíquotas IBSUF
        $sql = $this->db->prepare("SELECT * FROM dfe_gibsuf
                                WHERE empresa = :emp AND nfe = :nfe
                                    AND produto = :prod AND sequencial = :item
                                    AND modelo = 65 LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':nfe', $this->numeroNfce);
        $sql->bindValue(':prod', $codProduto);
        $sql->bindValue(':item', $seqItem);
        $sql->execute();
        $ibsuf = $sql->fetch(PDO::FETCH_ASSOC);

        if ($ibsuf) {
            $this->log("Item $seqItem: DUMP ibsuf = " . json_encode($ibsuf));
            // ...
        }

        if ($ibsuf) {
            $this->add('vBC_UB16', $this->fmtDec($ibsuf['bc_ibscbs']));
            $this->add('pIBSUF_UB18', $this->fmtDec($ibsuf['pibsuf'])); 

            // Opcionais (só se > 0)
            if ((float)$ibsuf['pDif_Opc'] > 0)      $this->add('pDif_UB22', $this->fmtDec($ibsuf['pDif_Opc']));
            if ((float)$ibsuf['vDif_Opc'] > 0)      $this->add('vDif_UB23', $this->fmtDec($ibsuf['vDif_Opc']));
            if ((float)$ibsuf['vDevTrib_Opc'] > 0)  $this->add('vDevTrib_UB25', $this->fmtDec($ibsuf['vDevTrib_Opc']));
            if ((float)$ibsuf['pRedAliq_Opc'] > 0 || (float)$ibsuf['pAliqEfet_Opc'] > 0) {
                $this->add('pRedAliq_UB27', $this->fmtDec($ibsuf['pRedAliq_Opc']));
                $this->add('pAliqEfet_UB28', $this->fmtDec($ibsuf['pAliqEfet_Opc']));
            }

            $this->add('vIBSUF_UB35', $this->fmtDec($ibsuf['vIBSUF']));
        }

        // 3. IBS Municipal
        $sql = $this->db->prepare("SELECT * FROM dfe_gibsmun
                                WHERE empresa = :emp AND nfe = :nfe
                                    AND produto = :prod AND sequencial = :item
                                    AND modelo = 65 LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':nfe', $this->numeroNfce);
        $sql->bindValue(':prod', $codProduto);
        $sql->bindValue(':item', $seqItem);
        $sql->execute();
        $ibsmun = $sql->fetch(PDO::FETCH_ASSOC);

        if ($ibsmun) {
            $this->add('pIBSMun_UB37', $this->fmtDec($ibsmun['pibsmun'] ?? 0));

            if ((float)$ibsmun['pDif_Opc'] > 0)      $this->add('pDif_UB41', $this->fmtDec($ibsmun['pDif_Opc']));
            if ((float)$ibsmun['vDif_Opc'] > 0)      $this->add('vDif_UB42', $this->fmtDec($ibsmun['vDif_Opc']));
            if ((float)$ibsmun['vDevTrib_Opc'] > 0)  $this->add('vDevTrib_UB44', $this->fmtDec($ibsmun['vDevTrib_Opc']));
            if ((float)$ibsmun['pRedAliq_Opc'] > 0 || (float)$ibsmun['pAliqEfet_Opc'] > 0) {
                $this->add('pRedAliq_UB46', $this->fmtDec($ibsmun['pRedAliq_Opc']));
                $this->add('pAliqEfet_UB47', $this->fmtDec($ibsmun['pAliqEfet_Opc']));
            }

            $this->add('vIBSMun_UB54', $this->fmtDec($ibsmun['vIBSMun']));
        }

        // 4. CBS
        $sql = $this->db->prepare("SELECT * FROM dfe_gcbs
                                WHERE empresa = :emp AND nfe = :nfe
                                    AND produto = :prod AND sequencial = :item
                                    AND modelo = 65 LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':nfe', $this->numeroNfce);
        $sql->bindValue(':prod', $codProduto);
        $sql->bindValue(':item', $seqItem);
        $sql->execute();
        $cbs = $sql->fetch(PDO::FETCH_ASSOC);

        if ($cbs) {
            $this->add('pCBS_UB56', $this->fmtDec($cbs['pCBS']));

            if ((float)$cbs['pDif_Opc'] > 0)      $this->add('pDif_UB60', $this->fmtDec($cbs['pDif_Opc']));
            if ((float)$cbs['vDif_Opc'] > 0)      $this->add('vDif_UB61', $this->fmtDec($cbs['vDif_Opc']));
            if ((float)$cbs['vDevTrib_Opc'] > 0)  $this->add('vDevTrib_UB63', $this->fmtDec($cbs['vDevTrib_Opc']));
            if ((float)$cbs['pRedAliq_Opc'] > 0 || (float)$cbs['pAliqEfet_Opc'] > 0) {
                $this->add('pRedAliq_UB65', $this->fmtDec($cbs['pRedAliq_Opc']));
                $this->add('pAliqEfet_UB66', $this->fmtDec($cbs['pAliqEfet_Opc']));
            }

            $this->add('vCBS_UB67', $this->fmtDec($cbs['vCBS']));
        }

        // 5. vIBS (soma UF + Mun) — vem DEPOIS do CBS (ordem do VB6)
        $vIBS = 0;
        if ($ibsuf)  $vIBS += (float)$ibsuf['vIBSUF'];
        if ($ibsmun) $vIBS += (float)$ibsmun['vIBSMun'];
        $this->add('vIBS_UB54a', $this->fmtDec($vIBS));

        $this->log("Item $seqItem: IBSCBS -> CST={$class['cst']} cClass={$class['cclasstrib']} vIBS=" . $this->fmtDec($vIBS));
    }
    // ============================================================
    // BLOCO 6 — TOTAIS
    // ============================================================

    private function blocoTotais() {
        $sql = $this->db->prepare("SELECT * FROM nfce_totais
                                   WHERE emp_codigo = :emp AND numero_nfce = :num LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new Exception("nfce_totais não encontrada");

        $this->add('vBC_W03', $this->fmtDec($r['tot_icms_vBC']));
        $this->add('vICMS_W04', $this->fmtDec($r['tot_icms_vICMS']));
        $this->add('vICMSDeson_W04a', $this->fmtDec(0));
        $this->add('vBCST_W05', $this->fmtDec($r['tot_icms_vBCST']));
        $this->add('vST_W06', $this->fmtDec($r['tot_icms_vST']));
        $this->add('vProd_W07', $this->fmtDec($r['tot_icms_vProd']));
        $this->add('vFrete_W08', $this->fmtDec($r['tot_icms_vFrete']));
        $this->add('vSeg_W09', $this->fmtDec($r['tot_icms_vSeg']));
        $this->add('vDesc_W10', $this->fmtDec($r['tot_icms_Desc']));
        $this->add('vII_W11', $this->fmtDec($r['tot_icms_vII']));
        $this->add('vIPI_W12', $this->fmtDec($r['tot_icms_vIPI']));
        $this->add('vPIS_W13', $this->fmtDec($r['tot_icms_vPIS']));
        $this->add('vCOFINS_W14', $this->fmtDec($r['tot_icms_vCOFINS']));
        $this->add('vOutro_W15', $this->fmtDec($r['tot_icms_vOutro']));
        $this->add('vNF_W16', $this->fmtDec($r['tot_icms_vNF']));
        if ((float)$r['vTotTrib'] > 0) {
            $this->add('vTotTrib_W16a', $this->fmtDec($r['vTotTrib']));
        }

        $this->add('modFrete_X02', '9');

        if ($this->versaoXml === '4.00') {
            $this->add('vFCPUFDest_W04c', $this->fmtDec(0));
            $this->add('vICMSUFDest_W04e', $this->fmtDec(0));
            $this->add('vICMSUFRemet_W04g', $this->fmtDec(0));
            $this->add('vFCP_W04h', $this->fmtDec($r['tot_icms_vFCP'] ?? 0));
            $this->add('vFCPST_W06a', $this->fmtDec($r['tot_icms_vFCPST'] ?? 0));
            $this->add('vFCPSTRet_W06b', $this->fmtDec($r['tot_icms_vFCPSTRet'] ?? 0));
            $this->add('vIPIDevol_W12a', $this->fmtDec($r['tot_icms_vIPIDevol'] ?? 0));
            $this->add('qBCMonoRet_W06d1', $this->fmtDec(0));
            $this->add('vICMSMonoRet_W06e', $this->fmtDec(0));
        }
    }

    // ============================================================
    // BLOCO 7 — REFORMA TRIBUTÁRIA (totais IBS/CBS)
    // ============================================================

   private function blocoReformaTotal() {
        // IS (sempre, mesmo se 0)
        $sql = $this->db->prepare("SELECT COALESCE(SUM(vIS), 0) FROM dfe_is
                                WHERE empresa = :emp AND nfe = :num");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $vIS = (float)$sql->fetchColumn();
        $this->add('vIS_W33', $this->fmtDec($vIS));

        // IBS Total
        $sql = $this->db->prepare("SELECT * FROM dfe_gibstotal
                                WHERE empresa = :emp AND nfe = :num AND modelo = 65 LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $ibs = $sql->fetch(PDO::FETCH_ASSOC);

        // CBS Total
        $sql = $this->db->prepare("SELECT * FROM dfe_gcbstotal
                                WHERE empresa = :emp AND nfe = :num AND modelo = 65 LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $cbs = $sql->fetch(PDO::FETCH_ASSOC);

        // Base total
        $sql = $this->db->prepare("SELECT COALESCE(SUM(bc_ibscbs), 0) FROM dfe_gibsuf
                                WHERE empresa = :emp AND nfe = :num");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $bcTotal = (float)$sql->fetchColumn();

        $this->add('vBCIBSCBS_W35', $this->fmtDec($bcTotal));

        // ✅ IBS — SEMPRE escreve (mesmo zerado)
        $this->add('vDif_W38',             $this->fmtDec($ibs['vDif_UF']           ?? 0));
        $this->add('vDif_W43',             $this->fmtDec($ibs['vDif_Mun']          ?? 0));
        $this->add('vDevTrib_W39',         $this->fmtDec($ibs['vDevTrib_UF']       ?? 0));
        $this->add('vIBSUF_W41',           $this->fmtDec($ibs['vIBSUF_UF']         ?? 0));
        $this->add('vDevTrib_W44',         $this->fmtDec($ibs['vDevTrib_Mun']      ?? 0));
        $this->add('vIBSMun_W46',          $this->fmtDec($ibs['vIBS_Mun']          ?? 0));
        $this->add('vIBS_W47',             $this->fmtDec($ibs['vIBS']              ?? 0));
        $this->add('vCredPres_W48',        $this->fmtDec($ibs['vCredPres']         ?? 0));
        $this->add('vCredPresCondSus_W49', $this->fmtDec($ibs['vCredPresCondSus']  ?? 0));

        // ✅ CBS — SEMPRE escreve (mesmo zerado)
        $this->add('vDif_W53',              $this->fmtDec($cbs['vDif']              ?? 0));
        $this->add('vDevTrib_W54',          $this->fmtDec($cbs['vDevTrib']          ?? 0));
        $this->add('vCBS_W56',              $this->fmtDec($cbs['vCBS']              ?? 0));
        $this->add('vCredPres_W56a',        $this->fmtDec($cbs['vCredPres']         ?? 0));
        $this->add('vCredPresCondSus_W56b', $this->fmtDec($cbs['vCredPresCondSus']  ?? 0));

        // ✅ Monofásico — sempre (o componente espera)
        $this->add('vIBSMono_W58',         '0.00');
        $this->add('vCBSMono_W59',         '0.00');
        $this->add('vIBSMonoReten_W59a',   '0.00');
        $this->add('vCBSMonoReten_W59b',   '0.00');
        $this->add('vIBSMonoRet_W59c',     '0.00');
        $this->add('vCBSMonoRet_W59d',     '0.00');

        $this->log("Reforma total: bc={$bcTotal} vIBSUF=" . ($ibs['vIBSUF_UF'] ?? 0) . " vCBS=" . ($cbs['vCBS'] ?? 0));
    }

    // ============================================================
    // BLOCO 8 — FORMAS DE PAGAMENTO
    // ============================================================

    private function blocoFormasPagamento() {
        $sql = $this->db->prepare("SELECT * FROM nfce_totais_formapgto
                                   WHERE emp_codigo = :emp AND numero_nfce = :num
                                   ORDER BY tot_fp_codigo");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $formas = $sql->fetchAll(PDO::FETCH_ASSOC);

        foreach ($formas as $f) {
            $codigo = (int)$f['tot_fp_codigo'];

            $this->linhas[] = 'INCLUIRPARTE=YA';
            $this->add('tPag_YA02', str_pad($f['tot_fp_codigo'], 2, '0', STR_PAD_LEFT));
            $this->add('vPag_YA03', $this->fmtDec($f['tot_fp_valor']));

            // tPag=99 exige descrição
            if ($codigo === 99) {
                $this->add('xPag_YA02a', $f['tot_fp_descricao']);
            }

            // Cartão de Crédito (03), Débito (04) e PIX (17) exigem grupo <card>
            if (in_array($codigo, array(3, 4, 17), true)) {
                $card = $this->buscarCartaoTef($codigo);

                if ($card) {
                    // tpIntegra: 1 = integrado. Em vendas_tef.tipo é sempre '1'.
                    $tpIntegra = (isset($card['tipo']) && $card['tipo'] !== '')
                               ? $card['tipo'] : '1';

                    $this->add('tpIntegra_YA04a', $tpIntegra);
                    $this->add('CNPJ_YA05',    $this->soNumeros($card['credenciadora']));
                    $this->add('tBand_YA06',   $card['bandeira_cartao']);
                    $this->add('cAut_YA07',    $card['codigo_transacao']);
                    $this->add('CNPJReceb_YA07a', $this->cnpjEmitente());
                    $this->add('idTermPag_YA07b', $card['id_terminal']);
                   
                }
                // Se $card for null, mantém só tPag/vPag — o SEFAZ vai rejeitar 391.
                // Vale logar para investigar:
                else {
                    $this->log("blocoFormasPagamento: SEM <card> para tPag=$codigo (nfce=" . $this->numeroNfce . ")");
                }
            }

            $this->linhas[] = 'SALVARPARTE=YA';
        }
    }

    private function soNumeros($texto) {
        return preg_replace('/\D/', '', (string)$texto);
    }


    private function cnpjEmitente() {
        $sql = $this->db->prepare("SELECT cnpj FROM tabempresa WHERE codigo = :empresa");
        $sql->bindValue(':empresa', $this->empresa);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        return ($r && !empty($r['cnpj'])) ? $this->soNumeros($r['cnpj']) : '';
    }

        /**
     * Busca dados de cartão para a forma de pagamento SEFAZ (tPag).
     * Espelha o SELECT interno do fBuscarTotalNotaFormaPagamentoNFCe (VB6).
     */
    private function buscarCartaoTef($tPagSefaz) {
        $sql = $this->db->prepare("SELECT tn.tipo,
                                          tn.credenciadora,
                                          tn.bandeira_cartao,
                                          tn.codigo_transacao,
                                          tv.id_terminal
                                   FROM nfce_tef tn
                                   INNER JOIN vendas_tef tv
                                     ON tv.empresa = tn.empresa
                                    AND tv.codigo  = tn.codigo
                                   WHERE tn.empresa    = :emp
                                     AND tn.nfce       = :nfce
                                     AND tn.forma_pagto = :fp
                                   LIMIT 1");
        $sql->bindValue(':emp',  $this->empresa);
        $sql->bindValue(':nfce', $this->numeroNfce);
        $sql->bindValue(':fp',   (int)$tPagSefaz, PDO::PARAM_INT);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    }

    private function blocoTroco() {
        if ($this->versaoXml !== '4.00') return;

        $sql = $this->db->prepare("SELECT tot_fp_vTroco FROM nfce_totais_formapgto
                                   WHERE emp_codigo = :emp AND numero_nfce = :num
                                     AND tot_fp_vTroco > 0 LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $troco = (float)$sql->fetchColumn();
        if ($troco > 0) {
            $this->add('vTroco_YA09', $this->fmtDec($troco));
        }
    }

    // ============================================================
    // BLOCO 9 — INFO COMPLEMENTARES
    // ============================================================

    private function blocoInfoComplementares() {
        $sql = $this->db->prepare("SELECT inf_infcpl_contribuinte FROM nfce_informacoescpl
                                   WHERE emp_codigo = :emp AND numero_nfce = :num LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();
        $info = trim($sql->fetchColumn() ?: '');
        if ($info !== '') {
            $this->add('infCpl_Z03', $info);
        }
    }

    // ============================================================
    // BLOCO 10 — RESPONSÁVEL TÉCNICO
    // ============================================================

    private function blocoRespTecnico() {
        $sql = $this->db->prepare("SELECT CNPJ, xContato, email, fone, idCSRT, CSRT_Opc
                                   FROM param_sistema WHERE empresa = :emp LIMIT 1");
        $sql->bindValue(':emp', $this->empresa);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$r || empty($r['CNPJ'])) return;

        $this->add('CNPJ_ZD02', $r['CNPJ']);
        $this->add('xContato_ZD04', $r['xContato']);
        $this->add('email_ZD05', $r['email']);
        $this->add('fone_ZD06', $r['fone']);
        if (!empty($r['idCSRT'])) {
            $this->add('idCSRT_ZD08', $r['idCSRT']);
            $this->add('hashCSRT_ZD09', $r['CSRT_Opc']);
        }
    }

    // ============================================================
    // PERSISTÊNCIA
    // ============================================================

   private function salvarArquivo() {
        $ano = date('Y');
        $mes = date('m');
        $dia = date('d');

        $dir = $this->pastaBase . DIRECTORY_SEPARATOR . $ano
            . DIRECTORY_SEPARATOR . $mes
            . DIRECTORY_SEPARATOR . $dia;

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $nome = $this->numeroNfce . ' ' . date('dmY') . ' ' . date('Hi') . '.txt';
        $caminho = $dir . DIRECTORY_SEPARATOR . $nome;

        $conteudo = implode("\r\n", $this->linhas);

        // ✅ Sanitiza: translitera acentos e remove bytes não-ASCII
        //    Isso deixa o TXT idêntico ao que o componente espera (ASCII puro)
        $conteudo = $this->sanitizarAscii($conteudo);

        file_put_contents($caminho, $conteudo);

        // Grava caminho em tab_nfce
        $sql = $this->db->prepare("UPDATE tab_nfce SET caminho_txt = :c
                                WHERE emp_codigo = :emp AND numero_nfce = :num");
        $sql->bindValue(':c', $caminho);
        $sql->bindValue(':emp', $this->empresa);
        $sql->bindValue(':num', $this->numeroNfce);
        $sql->execute();

        return $caminho;
    }

    /**
     * Translitera caracteres acentuados para ASCII e remove bytes residuais.
     * Resolve o problema de dupla codificação (UTF-8 lido como latin1).
     */
    private function sanitizarAscii($texto) {
        // 1. Corrige sequências típicas de dupla codificação UTF-8→latin1
        $corrompidos = [
            'Ãƒ' => 'A', 'Ã£' => 'a', 'ÃÁ' => 'A', 'Ã¡' => 'a', 'ÃÀ' => 'A', 'Ã ' => 'a',
            'Ã‰' => 'E', 'Ã©' => 'e', 'Ãˆ' => 'E', 'Ã¨' => 'e',
            'Ã' => 'I', 'Ã' => 'i', 'ÃŒ' => 'I', 'Ã¬' => 'i',
            'Ã"O' => 'O', 'Ã³' => 'o', 'Ã’' => 'O', 'Ã²' => 'o', 'Ã•' => 'O', 'Ãµ' => 'o', 'Ã”' => 'O', 'Ã´' => 'o',
            'Ãš' => 'U', 'Ãº' => 'u', 'Ã™' => 'U', 'Ã¹' => 'u', 'Ãœ' => 'U', 'Ã¼' => 'u',
            'Ã‡' => 'C', 'Ã§' => 'c', 'Ã‘' => 'N', 'Ã±' => 'n',
            'â€œ' => '"', 'â€' => '"', 'â€˜' => "'", 'â€™' => "'", 'â€"' => '-', 'â€"' => '-',
        ];

        // 2. Transliteração direta de caracteres UTF-8 válidos (se houver)
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

        // Primeiro corrige corrompidos (mais longos), depois acentos simples
        $texto = str_replace(array_keys($corrompidos), array_values($corrompidos), $texto);
        $texto = str_replace(array_keys($acentos),     array_values($acentos),     $texto);

        // 3. Remove qualquer byte não-ASCII restante (segurança)
        $texto = preg_replace('/[^\x20-\x7E\r\n\t]/', '', $texto);

        return $texto;
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function fmtDec($valor, $casas = 2) {
        $v = (float)$valor;
        return number_format($v, $casas, '.', '');
    }

    private function log($msg) {
        if (!$this->debug) return;
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        @file_put_contents($this->logPath, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
    }
}