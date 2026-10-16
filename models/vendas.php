<?php
class Vendas extends model{

  Public function listarMesas(){
    $mesas = array();

    $sql=$this->db->prepare("select mesa_codigo, mesa_descricao from mesas_comanda");
    $sql->execute();

    if($sql->rowCount()>0){
        $mesas=$sql->fetChAll();
      }//fim do if
      return $mesas;
}

//MesaLivre
public function verificaMesaLivre($empresa, $mesa){

  $sql=$this->db->prepare("SELECT
                              v.empresa,
                              v.mesa_comanda,
                              v.status,
                              v.venda,
                              v.vendedor,
                              v.total
                          FROM vendas v
                          WHERE v.empresa = :empresa
                              AND v.mesa_comanda = :mesa_comanda
                              AND (v.status = :status
                                      OR ISNULL(v.status))");
  $sql->bindValue(':empresa', $empresa);
  $sql->bindValue(':mesa_comanda', $mesa);
  $sql->bindValue(':status', 0);
  $sql->execute();

  if($sql->rowCount()>0){
    return 1;//ocupado
  }else{
    return 2;//Livre
  }//fim do if


}

public function listar($empresa, $mesa){
    $dados=array();

    $sql=$this->db->prepare("SELECT
                                v.empresa,
                                v.mesa_comanda,
                                v.status,
                                v.venda,
                                v.vendedor,
                                v.total
                            FROM vendas v
                            WHERE v.empresa = :empresa
                                AND v.mesa_comanda = :mesa_comanda
                                AND (v.status = :status
                                        OR ISNULL(v.status))");
    $sql->bindValue(':empresa', $empresa);
    $sql->bindValue(':mesa_comanda', $mesa);
    $sql->bindValue(':status', 0);
    $sql->execute();

    if($sql->rowCount()>0){
        $dados=$sql->fetch(PDO::FETCH_ASSOC);
    }else{
        $dados=array("empresa"=>"$empresa",
                    "mesa_comanda"=>"$mesa",
                    "status"=>"0",
                    "venda"=>"0",
                    "vendedor"=>"0",
                    "total"=>"0");
    }//fim do if

    return $dados;
}
  public function imprimir($mesa){

    $sql=$this->db->prepare("update vendas set imprime_cozinha=:imprimir, imprime_cozinha_hora=CURTIME()
                             where mesa_comanda=:mesa and empresa=1
                             and status=0 AND origem <>:origem and origem<>:orig");

    $sql->bindValue(':imprimir', "sim");
    $sql->bindValue(':mesa', $mesa);
    $sql->bindValue(':origem', 'MESAS');
    $sql->bindValue(':orig', 'PDV');
    $sql->execute();

  }

  public function jaImpresso($venda){

    $sql=$this->db->prepare("update vendas set imprime_cozinha=:imprimir where venda=:venda and empresa=1 and status=0 ");

    $sql->bindValue(':imprimir', "nao");
    $sql->bindValue(':venda', $venda);
    $sql->execute();

  }

  //public function getVendedor($mesa){
 //   $sql="select vendedor as v from vendas where empresa=1 and mesa_comanda='$mesa' and status=0;"
   // $sql=$this->db->query($sql);

   // if($sql->rowCount()>0){
   //   $array=$sql->fetCh();
   //   $vendedor=$array['v'];
  //  }
  //  return $vendedor;
  //}

  //Buscando cfop;
  public function codigoCFOP($referencia){
    try{
        $sql="select cfop as c from produtos where referencia = '$referencia'";
        $sql=$this->db->query($sql);

        if($sql->rowCount()>0){
          $array=$sql->fetCh();
          $cfop=$array['c'];
        }
        return $cfop;

    }catch(Exception $e){
        $file = fopen("logs/buscaCFOP.txt","w");
        echo fwrite($file,"Erro ao buscar CFOP ".$e);
        fclose($file);
    }
  }

  public function getSequncialCozinha(){
    try{
      //Seleciona o sequencial e acrescenta +1;
      $sql="select max(seq_cozinha) as cod from vendas_item";
      $sql=$this->db->query($sql);

      if($sql->rowCount()>0){
        $array=$sql->fetCh();
        $cod = $array['cod']+1;//Código gerado pra inserir no servidor;
      }
      return $cod; //retorna o código da venda no servidor
    }catch(Exception $e){
        $file = fopen("logs/addVenda.txt","w");
        echo fwrite($file,"Erro ao gravar venda: ".$e);
        fclose($file);
    }
  }

  public function codigoNovaVenda(){
    try{
      //Seleciona o ultima venda efetuada no servidor e acrescenta +1;
      $sql="select max(venda) as cod from vendas";
      $sql=$this->db->query($sql);

      if($sql->rowCount()>0){
        $array=$sql->fetCh();
        $cod = $array['cod']+1;//Código gerado pra inserir no servidor;
      }
      return $cod; //retorna o código da venda no servidor
    }catch(Exception $e){
        $file = fopen("logs/addVenda.txt","w");
        echo fwrite($file,"Erro ao gravar venda: ".$e);
        fclose($file);
    }
  }

  //Sequencial kardex
  private function codigoKardex($empresa){

        $sql="SELECT MAX(krd_sequencial) as cod FROM kardex_produtos WHERE emp_codigo='$empresa'";
        $sql=$this->db->query($sql);

        if($sql->rowCount()>0){
          $array=$sql->fetCh();
          $cod = $array['cod']+1;//Código gerado pra inserir no servidor;
        }
        return $cod; //retorna o código da venda no servido
    }

  public function codigoGrupo($produto){
    try{
        //Seleciona o código do grupo;
        $sql="SELECT p.grupo as grupo FROM produtos p LEFT JOIN vendas_item vi ON vi.grupo=p.grupo WHERE p.referencia='$produto' LIMIT 1";
        $sql=$this->db->query($sql);

        if($sql->rowCount()>0){
          $array=$sql->fetCh();
          $cod = $array['grupo'];//Código do grupo;
        }
        return $cod; //retorna o código do grupo;
      }catch(Exception $e){
        $file = fopen("logs/buscaCodigoGrupo.txt","w");
        echo fwrite($file,"Erro ao buscar Codigo do Grupo: ".$e);
        fclose($file);
      }
  }

  public function codigoItem($empresa, $venda){
    $item=array();

    $sql=$this->db->prepare("select max(item) as cod from vendas_item where empresa=:empresa and venda=:venda");
    $sql->bindValue(':empresa',$empresa);
    $sql->bindValue(':venda', $venda);
    $sql->execute();

    if($sql->rowCount()>0){
      $item=$sql->fetCh();
      $cod = $item['cod']+1;//Código gerado pra inserir no servidor;
    }

    return $cod;

  }


  public function pTribAprox($empresa, $referencia){
    $valor=array();

        $sql=$this->db->prepare("select pTotTribAprox as valor from produtos where empresa=:empresa and venda=:referencia");
        $sql->bindValue(':empresa',$empresa);
        $sql->bindValue(':referencia', $referencia);
        $sql->execute();

        if($sql->rowCount()>0){
          $valor=$sql->fetCh();
          $cod = $valor['valor'];//Código gerado pra inserir no servidor;
        }else{
          $cod=0;
        }

        return $cod;
  }

  public function addVenda($empresa, $venda, $vendedor, $mesa_comanda){

        try{
           $sql=$this->db->prepare("INSERT INTO vendas SET
                                          empresa='$empresa',
                                          venda='$venda',
                                          cliente='1',
                                          doccli='0',
                                          data=NOW(),
                                          vendedor='$vendedor',
                                          forma_pgto='1',
                                          moeda_pgto='1',
                                          STATUS='0',
                                          hora_venda=CURTIME() ,
                                          usuario='1',
                                          caixa='1',
                                          origem='APP',
                                          mesa_comanda='$mesa_comanda',
                                          codigotipomovimento='1'");
          $sql->execute();

         }catch(Exception $e){
           $file = fopen("logs/addVenda.txt","w");
           echo fwrite($file,"Erro ao gravar venda: ".$e);
           fclose($file);
         }
    }//fim do addVenda
    //Cancela Venda
    public function cancelarVenda($empresa, $venda){
      $sql=$this->db->prepare("UPDATE vendas SET STATUS = 2,
                                          data_cancelado = NOW(),
                                          hora_cancelado = CURTIME(),
                                          usuario_cancelamento = 0,
                                          motivo_cancelamento = 'Cancelamento APP'
                                          WHERE empresa = :empresa
                                            AND venda = :venda");
      $sql->bindValue(':empresa', $empresa);
      $sql->bindValue(':venda', $venda);

      $sql->execute();
    }

    //Lista itens da venda
    public function itemListar($empresa, $venda){
            $array= array();
            $sql=$this->db->prepare("SELECT
                                        vi.empresa,
                                        vi.venda,
                                        vi.item,
                                        vi.grupo,
                                        vi.referencia,
                                        p.descricao,
                                        vi.quantidade,
                                        vi.preco,
                                        vi.cancelado,
                                        vi.vendedor,
                                        vi.total_bruto,
                                        vi.total_liquido,
                                        vi.observacao
                                      FROM vendas_item vi
                                        LEFT JOIN produtos p
                                          ON (vi.empresa = p.empresa
                                              AND vi.referencia = p.referencia)
                                      WHERE vi.empresa =:empresa
                                          AND vi.venda = :venda
                                          AND vi.cancelado = :cancelado");
              $sql->bindValue(':empresa',$empresa);
              $sql->bindValue(':venda', $venda);
              $sql->bindValue(':cancelado', 0);

              $sql->execute();

              if($sql->rowCount()>0){
                $array=$sql->fetchAll();
              }
              return $array;
    }

public function addItem($empresa, $venda, $item, $grupo, $referencia, $quantidade, $preco,
                         $total_liquido, $total_bruto, $cancelado, $vendedor, $valor_aprop_tributos, $cfop,$obs,$seq){
      try{

          $dados=array();

          $sql=$this->db->prepare("select estoque, custo from produtos where empresa=:empresa and referencia=:referencia");
          $sql->bindValue(':empresa', $empresa);
          $sql->bindValue(':referencia', $referencia);

          $sql->execute();
          if($sql->rowCount()>0){
            $dados=$sql->fetch(PDO::FETCH_ASSOC);
          }
          $estoque=$dados['estoque'];
          $custo=$dados['custo'];
          //Adiciona os itens da venda;
          $sql=$this->db->prepare("INSERT INTO vendas_item
                                  SET empresa='$empresa',
                                   venda='$venda',
                                   item='$item',
                                   grupo='$grupo',
                                   referencia='$referencia',
                                   quantidade='$quantidade',
                                   preco='$preco',
                                   qtde_estoque_anterior='$estoque',
                                   custo='$custo',
                                   total_liquido='$total_liquido',
                                   total_bruto='$total_bruto',
                                   cancelado='$cancelado',
                                   vTotTribAprox='$valor_aprop_tributos',
                                   codigo_cfop='$cfop',
                                   vendedor='$vendedor',
                                   hora_lancamento=CURTIME(),
                                   observacao='$obs',
                                   venda_mesa_comanda='sim' , seq_cozinha='$seq'");
          $sql->execute();

          $this->somaTotalVenda($empresa, $venda);
          $this->processarEstoque($empresa,$venda,$referencia,$vendedor, $item,0);

        }catch(Exception $e){
          $file = fopen("logs/addItem.txt","w");
          echo fwrite($file,"Erro ao gravar itens da venda: ".$e);
          fclose($file);
        }
  }//fim do addItem
  //Soma total da Venda
  public function somaTotalVenda($empresa, $venda){
    $sql=$this->db->prepare("UPDATE vendas v
                              SET v.total = (SELECT
                              SUM(total_liquido)
                              FROM vendas_item
                              WHERE empresa = v.empresa
                              AND venda = v.venda AND cancelado<>:cancelado)
                              WHERE v.empresa = :empresa
                              AND v.venda = :venda");

    $sql->bindValue(':empresa', $empresa);
    $sql->bindValue(':venda', $venda);
    $sql->bindValue(':cancelado', 1);

    $sql->execute();
  }

 //PROCESSAR ESTOQUE
 private function processarEstoque($empresa, $venda, $referencia, $vendedor, $item, $requisicao, $origemEstorno = 'PEDIDO_MESA_APP') {

      $array = array();

      $sql = $this->db->prepare("SELECT
                                  vi.empresa, vi.venda, vi.item, vi.grupo,
                                  vi.referencia, vi.quantidade, vi.preco, vi.custo,
                                  vi.qtde_estoque_anterior AS qtd_anterior,
                                  vi.prod_lote AS lote
                                FROM vendas_item vi
                                WHERE vi.empresa = :empresa
                                  AND vi.venda = :venda
                                  AND vi.referencia = :referencia
                                  AND vi.item = :item");

      $sql->bindValue(':empresa', $empresa);
      $sql->bindValue(':venda', $venda);
      $sql->bindValue(':referencia', $referencia);
      $sql->bindValue(':item', $item);
      $sql->execute();

      if ($sql->rowCount() > 0) {
          $array = $sql->fetch(PDO::FETCH_ASSOC);

          $custo_movimentado = $array['quantidade'] * $array['custo'];

          if ($requisicao == 0) {
              // ✅ BAIXA
              $sql = $this->db->prepare("UPDATE produtos
                  SET data_ultima_venda = NOW(),
                      qtde_ultima_venda = :quv,
                      nro_ultima_venda = :nuv,
                      estoque = estoque - :qtdv
                  WHERE empresa = :empresa AND referencia = :referencia");
              $sql->bindValue(':quv', $array['qtd_anterior']);
              $sql->bindValue(':nuv', $venda);
              $sql->bindValue(':qtdv', $array['quantidade']);
              $sql->bindValue(':empresa', $empresa);
              $sql->bindValue(':referencia', $referencia);
              $sql->execute();

              $this->gravaKardex(
                  $empresa, $venda, $origemEstorno, "VENDAS", $referencia,
                  $array['qtd_anterior'], $array['custo'], $array['quantidade'],
                  $custo_movimentado, $vendedor
              );

          } else {
              // ✅ ESTORNO (devolve)
              $sql = $this->db->prepare("UPDATE produtos
                  SET data_ultima_venda = NOW(),
                      qtde_ultima_venda = :quv,
                      nro_ultima_venda = :nuv,
                      estoque = estoque + :qtdv
                  WHERE empresa = :empresa AND referencia = :referencia");
              $sql->bindValue(':quv', $array['qtd_anterior']);
              $sql->bindValue(':nuv', $venda);
              $sql->bindValue(':qtdv', $array['quantidade']);
              $sql->bindValue(':empresa', $empresa);
              $sql->bindValue(':referencia', $referencia);
              $sql->execute();

              $this->gravaKardex(
                  $empresa, $venda, $origemEstorno, "VENDAS_CANCELAMENTO", $referencia,
                  $array['qtd_anterior'], $array['custo'], $array['quantidade'],
                  $custo_movimentado, $vendedor
              );
          }
      }
  }

  /**
   * Estorna o estoque de todos os itens não-cancelados de uma venda.
   * Usado quando a mesa/venda é cancelada.
   *
   * @return int Nº de itens estornados
   */
  public function estornarEstoqueVenda($empresa, $venda, $vendedor = 1) {
      $sql = $this->db->prepare("SELECT item, referencia
                                FROM vendas_item
                                WHERE empresa = :empresa
                                  AND venda = :venda
                                  AND (cancelado = 0 OR cancelado IS NULL)");
      $sql->bindValue(':empresa', $empresa);
      $sql->bindValue(':venda', $venda);
      $sql->execute();
      $itens = $sql->fetchAll(PDO::FETCH_ASSOC);

      $total = 0;
      foreach ($itens as $item) {
          // $requisicao = 1 → ENTRADA (devolve ao estoque)
          // $origemEstorno → identifica no kardex que foi cancelamento
          $this->processarEstoque(
              $empresa,
              $venda,
              $item['referencia'],
              $vendedor,
              $item['item'],
              1,
              'CANCELAMENTO_MESA_APP'
          );

          $total++;

      }

      return $total;
  }

  //Movimenta tabela Kardex
  private function gravaKardex($empresa, $venda, $origem, $tipo, $produto, $qtd_anterior,
                              $custo_anterior, $qtd_movimentado, $custo_movimentado,$vendedor){

     $sequencial=$this->codigoKardex($empresa);
     $sql=$this->db->prepare("INSERT INTO kardex_produtos SET
                                            emp_codigo='$empresa',
                                            krd_data_movimento=NOW(),
                                            krd_sequencial='$sequencial',
                                            krd_numero_movimento='$venda',
                                            krd_origem_movimento='$origem',
                                            krd_tipo_movimento='$tipo',
                                            krd_ref_produto='$produto',
                                            krd_qtde_anterior='$qtd_anterior',
                                            krd_custo_anterior='$custo_anterior',
                                            krd_qtde_movimentada='$qtd_movimentado',
                                            krd_custo_movimentado='$custo_movimentado',
                                            usu_codigo='$vendedor',
                                            krd_hora_movimento=CURTIME()");
      $sql->execute();
      
   }

  //Cancelar item da venda;
  public function itemcancelar($empresa, $venda, $item){
    $viref=array();
    $sql=$this->db->prepare(" UPDATE vendas_item SET
                              cancelado=1 WHERE
                              empresa=:empresa AND
                              venda=:venda AND
                              item=:item ");
    $sql->bindValue(':empresa',$empresa);
    $sql->bindValue(':venda', $venda);
    $sql->bindValue(':item',$item);

    $sql->execute();

    // Recalcula total da venda
    $this->recalcularTotalVenda($empresa, $venda);

    $this->somaTotalVenda($empresa, $venda);

    $sql=$this->db->prepare('select referencia, vendedor from vendas_item where empresa=:empresa and venda=:venda and item=:item');
    $sql->bindValue(':empresa',$empresa);
    $sql->bindValue(':venda', $venda);
    $sql->bindValue(':item',$item);

    $sql->execute();

    if($sql->rowCount()>0){
      $vi=$sql->fetch(PDO::FETCH_ASSOC);
    }
    $this->processarEstoque($empresa,$venda,$vi['referencia'],$vi['vendedor'],$item,1);
  }
}//fim da classe
?>
