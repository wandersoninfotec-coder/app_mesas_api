<?php
class produtoController extends controller{
 /* public function listar(){

    $array= array();

    $p= new Produto();
    $array=$p->listar();

    header("Content-Type: application/json");
    echo json_encode($array);
  }//fim do listar*/

  //Listar Produtos Cadastrados
  public function listarConsulta(){
       header('Content-Type: application/json; charset=utf-8');
       $json = file_get_contents('php://input');

       $j=json_decode($json);

       //$j= file_get_contents("C:/xampp/htdocs/WDSRestaurante/json/listaProdConsulta.json");
       //$j=json_decode($j);

        $desc=$j->texto;
        $empresa=$j->empresa;

        $array= array();
    
        $p= new Produto();
        $array=$p->listarConsulta($empresa,$desc);

       header("Content-Type: application/json");
       echo json_encode($array);
      }//fim do listar
	  
	 
  //Listar Grupos Cadastrados
  public function listarGrupos(){
       header('Content-Type: application/json; charset=utf-8');
       $json = file_get_contents('php://input');

       $j=json_decode($json);

       //$j= file_get_contents("C:/xampp/htdocs/WDSRestaurante/json/listaProdConsulta.json");
       //$j=json_decode($j);
        
        $empresa=$j->empresa;

        $array= array();
    
        $p= new Produto();
        $array=$p->listarGrupos($empresa);

       header("Content-Type: application/json");
       echo json_encode($array);
      }//fim do listar grupos
	  
	  
	  
	  
	  
	  
	  
	  
	  
	  
	  
	  
	  
	  
	  
	  
	  
	  
	  
	  
}//fim da classe
?>
