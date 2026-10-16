<?php
  class Produto extends model{



    public function listarConsulta($empresa, $desc){
      try{
          $array =  array();

          $e=new Empresa();

          $controlaEstoque=$e->controlaEstoque($empresa);

          $sql=$this->db->prepare("SELECT
                    p.referencia,
                    g.descricao as grupo,
                    m.descricao as marca,
                    p.descricao,
                    p.estoque,
                    p.vista,
                    p.prazo,
                    p.codigobarra,
                    p.venda_unidade,
                    p.preco_promocao,
					g.titulo_image_busca_app as titulo_image_busca_app
                  FROM produtos p
                    INNER JOIN marca m
                    ON (p.marca = m.codigo AND p.empresa=m.empresa)
                    INNER JOIN grupos g
                    ON (p.grupo = g.codigo AND p.empresa=g.empresa)  where p.empresa=:empresa and p.status=:status and
                    p.tipo_cadastro=:tipo_cadastro and
				    p.exporta_app=:exporta_app and
                    p.tipo_item=:tipo_item and p.estoque>:estoque and (p.referencia like :descricao or
                                                                        p.descricao like :descricao or
                                                                        p.reforiginal like :descricao or
                                                                        p.ref_fabricante like :descricao or
    																	g.descricao like :descricao or
                                                                        p.codigobarra like :descricao)");

          $sql->bindValue(':empresa', $empresa);
          $sql->bindValue(':status',1);
          $sql->bindValue(':tipo_cadastro','PRODUTO');
		  $sql->bindValue(':exporta_app','sim');
          $sql->bindValue(':tipo_item', 0);

          if ($controlaEstoque==1){//Controla Estoque
             $sql->bindValue(':estoque',0);
          }else{ //Não controla estoque;
             $sql->bindValue(':estoque',-99999999);
          }
          $sql->bindValue(':descricao', "%$desc%");

          $sql->execute();

         // print_r($sql);

          if($sql->rowCount()>0){
            $array=$sql->fetChAll();
          }//fim do if
          return ($array);


        }catch(Exception $e){
            $file = fopen("logs/listarProduto.txt","w");
            echo fwrite($file,"Erro ao listar produto: ".$e);
            fclose($file);
        }
    }//fim do listar com Consulta

        /**
     * Busca EXATA por codigobarra ou referencia.
     * Retorna 1 produto ou null.
     */
    public function buscarExato($empresa, $codigo) {
        try {
            $codigo = trim((string)$codigo);
            if ($codigo === '') return null;

            $e = new Empresa();
            $controlaEstoque = $e->controlaEstoque($empresa);

            $sql = $this->db->prepare("SELECT
                    p.referencia,
                    g.descricao AS grupo,
                    m.descricao AS marca,
                    p.descricao,
                    p.estoque,
                    p.vista,
                    p.prazo,
                    p.preco_promocao,
                    p.codigobarra,
                    p.venda_unidade,
                    g.titulo_image_busca_app AS titulo_image_busca_app
                FROM produtos p
                INNER JOIN marca m ON (p.marca = m.codigo AND p.empresa = m.empresa)
                INNER JOIN grupos g ON (p.grupo = g.codigo AND p.empresa = g.empresa)
                WHERE p.empresa = :empresa
                  AND p.status = 1
                  AND p.tipo_cadastro = 'PRODUTO'
                  AND p.exporta_app = 'sim'
                  AND p.tipo_item = 0
                  AND p.estoque > :estoque
                  AND (p.codigobarra = :codigo OR p.referencia = :codigo)
                LIMIT 1");

            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':estoque', $controlaEstoque == 1 ? 0 : -99999999);
            $sql->bindValue(':codigo', $codigo);
            $sql->execute();

            if ($sql->rowCount() > 0) {
                return $sql->fetch(PDO::FETCH_ASSOC);
            }
            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Busca por código de balança (EAN-13 com prefixo).
     * $layout = [
     *   'prefixo'       => '2',      // primeiro dígito(s) que identifica balança
     *   'tamPrefixo'    => 1,        // quantos dígitos do prefixo
     *   'tamCodigo'     => 6,        // quantos dígitos do código do produto
     *   'tamValor'      => 5,        // quantos dígitos do peso/valor
     *   'temDV'         => true,     // tem dígito verificador no fim?
     *   'tipo'          => 'peso',   // 'peso' (gramas) ou 'valor' (centavos)
     * ]
     */
    public function buscarPorBalança($empresa, $codigoBarras, $layout = []) {
        $cfg = array_merge([
            'prefixo'    => '2',
            'tamPrefixo' => 1,
            'tamCodigo'  => 6,
            'tamValor'   => 5,
            'temDV'      => true,
            'tipo'       => 'peso',
        ], $layout);

        $codigoBarras = preg_replace('/\D/', '', $codigoBarras);
        $tamEsperado = $cfg['tamPrefixo'] + $cfg['tamCodigo'] + $cfg['tamValor'] + ($cfg['temDV'] ? 1 : 0);
        if (strlen($codigoBarras) !== $tamEsperado) return null;

        // Valida prefixo
        $prefixo = substr($codigoBarras, 0, $cfg['tamPrefixo']);
        if ($prefixo !== $cfg['prefixo']) return null;

        // Extrai código do produto e valor
        $inicioCod = $cfg['tamPrefixo'];
        $codigoProduto = substr($codigoBarras, $inicioCod, $cfg['tamCodigo']);
        $valorBruto    = (int)substr($codigoBarras, $inicioCod + $cfg['tamCodigo'], $cfg['tamValor']);

        // Busca o produto pela referência ou código
        $produto = $this->buscarExato($empresa, $codigoProduto);
        if (!$produto) return null;

        $quantidade  = null;
        $valorTotal  = null;

        if ($cfg['tipo'] === 'peso') {
            $quantidade = $valorBruto / 1000;   // gramas → kg
            $precoUnit  = (float)$produto['vista'];
            $valorTotal = round($quantidade * $precoUnit, 2);
        } else { // 'valor' — centavos
            $valorTotal = $valorBruto / 100;
            $precoUnit  = (float)$produto['vista'];
            $quantidade = $precoUnit > 0 ? round($valorTotal / $precoUnit, 3) : 0;
        }

        return [
            'produto'     => $produto,
            'quantidade'  => $quantidade,
            'valor_total' => $valorTotal,
            'tipo'        => $cfg['tipo'],
        ];
    }

    //Lista os grupos cadastrados para busca no app
      public function listarGrupos($empresa){
              $array= array();
              $sql=$this->db->prepare("SELECT
                                          g.codigo,
                                          g.descricao,
                                          g.titulo_image_busca_app
                                        FROM grupos g
                                        WHERE g.empresa =:empresa AND g.exporta_app=:exporta_app");
                $sql->bindValue(':empresa',$empresa);
          $sql->bindValue(':exporta_app','sim');

                $sql->execute();

                if($sql->rowCount()>0){
                  $array=$sql->fetchAll();
                }
                return $array;
      } //fim do listarGrupos

          /**
       * 🔍 Busca dados fiscais COMPLETOS de um produto para NFC-e
       *
       * Retorna:
       *  - Dados do produto (descricao, ncm, cest, cst, etc)
       *  - Dados do CFOP aplicado (cst_icms, aliquota, reducao, etc)
       *  - Dados da Reforma Tributária (CST_IBS_CBS, cClassTrib, alíquotas)
       *
       * @param int    $empresa
       * @param int    $referencia    Código do produto
       * @param string $codigoCfop    Formato: "5.102-1" (vem do vendas_item)
       * @return array|null
       */
      public function buscarDadosFiscais($empresa, $referencia, $codigoCfop = null) {
          try {
              // Parse do CFOP (formato: "5.102-1")
              $cfopCodigo = null;
              $cfopIndice = null;
              if (!empty($codigoCfop) && strpos($codigoCfop, '-') !== false) {
                  $partes = explode('-', $codigoCfop);
                  $cfopCodigo = trim($partes[0]);
                  $cfopIndice = isset($partes[1]) ? (int)trim($partes[1]) : 0;
              }

              // Busca produto + unidade + grupo + marca
              $sql = $this->db->prepare("SELECT
                      p.referencia,
                      p.reforiginal,
                      p.descricao,
                      p.unidade,
                      p.qtd_unidade,
                      p.codigobarra,
                      p.codigobarra_trib,
                      p.ncm,
                      p.codigo_cest,
                      p.cst,
                      p.cfop,
                      p.indice_cfop,
                      p.cfop_simples,
                      p.origem,
                      p.aliquota_icms,
                      p.reducao_bcicsm,
                      p.mod_bcicms,
                      p.pis_sittributaria,
                      p.pis_aliquota,
                      p.cofins_sittributaria,
                      p.cofins_aliquota,
                      p.pTotTribAprox,
                      p.pTotTribAprox_estadual,
                      p.pTotTribAprox_municipal,
                      p.produto_controla_estoque,
                      p.produto_cozinha,
                      p.exporta_app,
                      p.genero,
                      p.tipo_item,
                      p.status,
                      p.tipo_cadastro,
                      p.unidade_compra,
                      u.sigla AS unidade_sigla,
                      uc.sigla AS unidade_compra_sigla,
                      g.descricao AS grupo_descricao,
                      m.descricao AS marca_descricao,
                      p.CST_IBS_CBS,
                      p.cclasstrib,
                      p.pRedIBS,
                      p.pRedCBS,
                      p.pRedIBSMun,
                      p.TributacaoRegular,
                      p.CreditoPresumido,
                      p.ReducaoBaseCalculo,
                      p.AliquotaIBSUF,
                      p.AliquotaIBSMun,
                      p.AliquotaCBS,
                      p.Tributacao_monofasica,
                      p.Tributacao_monofasica_diferimento,
                      p.Tributacao_monofasica_retencao,
                      p.CSTIS,
                      p.cClassTribIS,
                      p.vBCIS,
                      p.pIS,
                      p.adRemIS_Opc,
                      p.uTrib_Opc,
                      p.qTrib_Opc,
                      p.vIS,
                      p.nbs
                  FROM produtos p
                  LEFT JOIN tabprodmedida u  ON u.codigo = p.unidade
                  LEFT JOIN tabprodmedida uc ON uc.codigo = p.unidade_compra
                  LEFT JOIN grupos g ON g.empresa = p.empresa AND g.codigo = p.grupo
                  LEFT JOIN marca m  ON m.empresa = p.empresa AND m.codigo = p.marca
                  WHERE p.empresa = :empresa AND p.referencia = :referencia
                  LIMIT 1");

              $sql->bindValue(':empresa', $empresa);
              $sql->bindValue(':referencia', $referencia);
              $sql->execute();

              if ($sql->rowCount() == 0) {
                  return null;
              }

              $produto = $sql->fetch(PDO::FETCH_ASSOC);

              // Busca regras do CFOP
              $cfop = null;
              if ($cfopCodigo !== null) {
                  $sqlCfop = $this->db->prepare("SELECT
                          cfop_codigo,
                          cfop_indice,
                          cfop_descricao,
                          cfop_regime_empresa,
                          cfop_aliq_icms,
                          cfop_cst_icms,
                          cfop_cst_cson,
                          cfop_aliq_reducao_icms,
                          cfop_modbc_icms,
                          cfop_aliq_credSN,
                          cfop_aliq_icmsst,
                          cfop_aliq_reducao_icmsst,
                          cfop_aliq_mva,
                          cfop_modbc_icmsst,
                          cfop_cst_pis,
                          cfop_cst_cofins,
                          cfop_aliq_pis,
                          cfop_aliq_cofins,
                          cfop_informacoes_adicionais_docfiscal,
                          flag_cfop_usacreditosimples_observacoes,
                          flag_cfop_substributaria,
                          adRemICMS,
                          pDif_Opc
                      FROM tab_cfop
                      WHERE empresa = :empresa
                        AND cfop_codigo = :cfop_codigo
                        AND cfop_indice = :cfop_indice
                      LIMIT 1");

                  $sqlCfop->bindValue(':empresa', $empresa);
                  $sqlCfop->bindValue(':cfop_codigo', $cfopCodigo);
                  $sqlCfop->bindValue(':cfop_indice', $cfopIndice);
                  $sqlCfop->execute();

                  if ($sqlCfop->rowCount() > 0) {
                      $cfop = $sqlCfop->fetch(PDO::FETCH_ASSOC);
                  }
              }

              return [
                  'produto' => $produto,
                  'cfop'    => $cfop,
              ];

          } catch (Exception $e) {
              $file = fopen("logs/buscarDadosFiscais.txt", "w");
              fwrite($file, "Erro: " . $e->getMessage() . PHP_EOL);
              fclose($file);
              return null;
          }
      }

   public function buscarRtcPorNcm($ncm, $empresa = 1) {
        try {
            // Passo 1: NCM → CST + cClassTrib
            $sql = $this->db->prepare("
                SELECT cst_ibs_cbs, cclass_trib
                FROM dfe_ncm_nbs_ibs_cbs
                WHERE ncm_nbs_ibs_cbs = :ncm
                AND (inicio_vigencia_ncm_nbs_ibs_cbs IS NULL
                    OR inicio_vigencia_ncm_nbs_ibs_cbs <= CURDATE())
                AND (terminino_vigencia_ncm_nbs_ibs_cbs IS NULL
                    OR terminino_vigencia_ncm_nbs_ibs_cbs >= CURDATE())
                LIMIT 1
            ");
            $sql->bindValue(':ncm', $ncm);
            $sql->execute();
            $ncmRow = $sql->fetch(PDO::FETCH_ASSOC);

            if (!$ncmRow) {
                error_log("buscarRtcPorNcm({$ncm}): NCM nao encontrado ou fora de vigencia");
                return null;
            }

            $ncmNorm = [];
            foreach ($ncmRow as $k => $v) {
                $ncmNorm[strtolower($k)] = $v;
            }

            $cst        = $ncmNorm['cst_ibs_cbs'];
            $cclassTrib = $ncmNorm['cclass_trib'];

            // Passo 2: alíquotas por CST + cClassTrib
            $sql = $this->db->prepare("
                SELECT
                    CST_IBS_CBS, cclasstrib,
                    AliquotaIBSUF, AliquotaIBSMun, AliquotaCBS,
                    pRedIBS, pRedCBS, pRedIBSMun,
                    TributacaoRegular, ReducaoBaseCalculo, CreditoPresumido,
                    Tributacao_monofasica,
                    Tributacao_monofasica_diferimento,
                    Tributacao_monofasica_retencao
                FROM tab_tipooperacao_rtc
                WHERE empresa     = :empresa
                AND CST_IBS_CBS = :cst
                AND cclasstrib  = :cclass
                LIMIT 1
            ");
            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':cst',    $cst);
            $sql->bindValue(':cclass', $cclassTrib);
            $sql->execute();
            $rtc = $sql->fetch(PDO::FETCH_ASSOC);

            if (!$rtc) {
                error_log("buscarRtcPorNcm({$ncm}): CST={$cst}/cClass={$cclassTrib} sem aliquota em tab_tipooperacao_rtc");
                return [
                    'cst_ibs_cbs'    => $cst,
                    'cclass_trib'    => $cclassTrib,
                    'aliquotaibsuf'  => 0,
                    'aliquotaibsmun' => 0,
                    'aliquotacbs'    => 0,
                    'predibs'        => 0,
                    'predcbs'        => 0,
                    'predibsmun'     => 0,
                ];
            }

            $normalizado = [];
            foreach ($rtc as $k => $v) {
                $normalizado[strtolower($k)] = $v;
            }
            $normalizado['cst_ibs_cbs'] = $cst;
            $normalizado['cclass_trib'] = $cclassTrib;

            return $normalizado;

        } catch (Throwable $t) {
            error_log("buscarRtcPorNcm ERRO: " . $t->getMessage());
            return null;
        }
    }

 
    public function buscarRtcPadrao($empresa) {
          try {
              $sql = $this->db->prepare("SELECT
                      CST_IBS_CBS,
                      cclasstrib,
                      pRedIBS,
                      pRedCBS,
                      pRedIBSMun,
                      TributacaoRegular,
                      CreditoPresumido,
                      ReducaoBaseCalculo,
                      AliquotaIBSUF,
                      AliquotaIBSMun,
                      AliquotaCBS,
                      Tributacao_monofasica,
                      Tributacao_monofasica_diferimento,
                      Tributacao_monofasica_retencao
                  FROM tab_tipooperacao_rtc
                  WHERE empresa = :empresa AND codigo = 1
                  LIMIT 1");

              $sql->bindValue(':empresa', $empresa);
              $sql->execute();

              if ($sql->rowCount() > 0) {
                  return $sql->fetch(PDO::FETCH_ASSOC);
              }
              return null;

          } catch (Exception $e) {
              return null;
          }
      }

      


  }//fim do model
 ?>
