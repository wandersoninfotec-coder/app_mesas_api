<?php
class vendasController extends controller{

  public function __construct() {
        parent::__construct();
    }
    //Verifica se mesa esta Livre
    public function verificaMesaLivre(){

    header('Content-Type: application/json; charset=utf-8');
    $json = file_get_contents('php://input');
   // $json=  file_get_contents("http://localhost/WDSRestaurante/json/mesalivre.json");


    $j=json_decode($json);

    $empresa=$j->empresa ;
    $mesa=$j->mesa;

    $v= new Vendas();
    $ocupado=$v->verificaMesaLivre($empresa, $mesa);

    header("Content-Type: application/json");
    echo json_encode($ocupado);

  }//fim do listar

  public function listar(){

    $array= array();

    $v= new Vendas();
    $mesas=$v->listarMesas();

    foreach($mesas as $a){

      $array[]=$v->listar(1, $a['mesa_codigo']);

      //Pegar o Vendedor aqui;
     // $vendedor=$v->getVendedor($a['mesa_codigo']);

      $v->imprimir($a['mesa_codigo']);

    }

    header("Content-Type: application/json");
    echo json_encode($array);

  }//fim do listar

    public function add(){
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $v = json_decode($json);

        // ⚠️ Aceita tanto "mesa" quanto "mesa_comanda"
        $empresa = $v->empresa ?? 1;
        $vendedor = $v->vendedor ?? 1;
        $mesa_comanda = $v->mesa_comanda ?? $v->mesa ?? 0;

        if (!$mesa_comanda) {
            http_response_code(400);
            echo json_encode(['erro' => 'Mesa não informada']);
            return;
        }

        $venda = new Vendas();
        $novaVenda = $venda->codigoNovaVenda();

        $venda->addVenda($empresa, $novaVenda, $vendedor, $mesa_comanda);

        header("Content-Type: application/json");
        echo json_encode($novaVenda);
    }

public function cancelar(){
  //$json=  file_get_contents("http://localhost/WDSRestaurante/json/venda.json");
          header('Content-Type: application/json; charset=utf-8');
          $json = file_get_contents('php://input');

          $v=json_decode($json);

          $empresa=$v->empresa ;
          $venda=$v->venda;

          $vendas = new Vendas();

          $vendas->cancelarVenda($empresa,$venda);
}

  //Adicionando Itens na venda;
  Public function itemadd(){
     //Trabalhando com Itens da venda;
      //$json=  file_get_contents("http://localhost/WDSRestaurante/json/venda.json");
      header('Content-Type: application/json; charset=utf-8');
      $json = file_get_contents('php://input');

      $vi=json_decode($json);

      $referencia=$vi->referencia;
      $descricao=$vi->descricao;
      $empresa=$vi->empresa;
      $desconto=$vi->valor_desconto_item;
      $preco=$vi->preco;
      $quantidade=$vi->quantidade;
      $total_liquido=$vi->total_liquido;
      $total_bruto=$vi->total_bruto;
      $cancelado=$vi->cancelado;
      $vendas=$vi->venda;
      $vendedor=$vi->vendedor;
      $obs=$vi->observacao;

      $venda=new Vendas();
      //Recuperndo grupo do produto;
      $grupo=$venda->codigoGrupo($referencia);
      //Recuperando CFOP padrão no cadastro de produtos;
      $cfop=$venda->codigoCFOP($referencia);
      //Buscando o novo sequencial do item disponível na venda;
      $item=$venda->codigoItem($empresa, $vendas);
      //Buscando valor dos tributos disponíveis no cadastro de produtos;
      $pTribAprox=$venda->pTribAprox($empresa, $referencia);
      //Gravando item da venda;

      //Possibilita só imprimir quando sair da tela de lancamento e voltas às mesas
      $venda->jaImpresso($vendas);

      $seq=$venda->getSequncialCozinha();
      $venda->addItem($empresa, $vendas, $item, $grupo, $referencia, $quantidade, $preco,
                      $total_liquido, $total_bruto, $cancelado, $vendedor, $pTribAprox, $cfop, $obs, $seq);
  }

  public function itemlistar(){

         //$json=  file_get_contents("http://localhost/WDSRestaurante/json/venda.json");
         header('Content-Type: application/json; charset=utf-8');
         $json = file_get_contents('php://input');

          $vi=json_decode($json);

          $empresa=$vi->empresa;
          $venda=$vi->venda;

          $array= array();

          $v = new Vendas();
          $array=$v->itemListar($empresa, $venda);

          header("Content-Type: application/json");
          echo json_encode($array);
  }

  public function itemcancelar(){
    //$json=  file_get_contents("http://localhost/WDSRestaurante/json/venda.json");
    header('Content-Type: application/json; charset=utf-8');
    $json = file_get_contents('php://input');

    $vi=json_decode($json);

    $empresa=$vi->empresa;
    $venda=$vi->venda;
    $item=$vi->item;

    $array= array();

    $v = new Vendas();

    //Possibilita só imprimir quando sair da tela de lancamento e voltas às mesas
    $v->jaImpresso($venda);


    $array=$v->itemcancelar($empresa, $venda, $item);

    header("Content-Type: application/json");
    echo json_encode($array);
  }
}
?>
