<?php
class clienteController extends controller {

  public function __construct() {
        parent::__construct();
    }

 /* public function listar(){
    $array= array();

    $c = new Cliente();
    $array=$c->listar();

    header("Content-Type: application/json");
    echo json_encode($array);
  }*///fim da fuction listar

  public function listarConsulta(){
      header('Content-Type: application/json; charset=utf-8');
      $json = file_get_contents('php://input');

      $j=json_decode($json);
    
      $empresa=$j->empresa;
      $texto=$j->texto;
   
    $array= array();

    $c = new Cliente();
    $array=$c->listarConsulta($empresa,$texto);

    header("Content-Type: application/json");
    echo json_encode($array);
  }//fim da fuction listar

  public function add(){
      header('Content-Type: application/json; charset=utf-8');
      $json = file_get_contents('php://input');

        $j=json_decode($json);

        $empresa=$j->empresa;
        $razao=$j->razao;
        $fantasia=$j->fantasia;
        $tipo=$j->tipo;
        $cpf=$j->cpf;
        $rg=$j->rg;
        $telefone=$j->telefone;
        $endereco=$j->endereco;
        $bairro=$j->bairro;
        $cidade=$j->cidade;
        $email=$j->email;
        $contato=$j->contato;

        $c= new Cliente();
        $c->addCliente($empresa, $razao,$fantasia, $tipo, $cpf, $rg, $telefone, $endereco, $bairro, $cidade,$email, $contato);
    }//fim da function add

  public function edit(){
     header('Content-Type: application/json; charset=utf-8');
     $json = file_get_contents('php://input');

     $j=json_decode($json);

      $cliente=$j->codigo;
      $empresa=$j->empresa;
      $razao=$j->razao;
      $fantasia=$j->fantasia;
      $tipo=$j->tipo;
      $cpf=$j->cpf;
      $rg=$j->rg;
      $telefone=$j->telefone;
      $endereco=$j->endereco;
      $bairro=$j->bairro;
      $cidade=$j->cidade;
      $email=$j->email;
      $contato=$j->contato;

      $c=new Cliente();
      $c->editCliente($cliente, $empresa, $razao, $fantasia,$tipo, $cpf, $rg, $telefone, $endereco, $bairro, $cidade, $email,$contato);
  }
  }//fim da classe
?>
