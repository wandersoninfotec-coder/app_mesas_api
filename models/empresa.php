<?php
class Empresa extends model{

  public function controlaEstoque($empresa){


    $sql=$this->db->prepare("SELECT controla_estoque_processamento as cep FROM param_sistema WHERE empresa=:empresa ");

    $sql->bindValue(':empresa',$empresa);

    $sql->execute();

    if($sql->rowCount()>0){
      $controlaEstoque=$sql->fetch();
      $controlaEstoque=$controlaEstoque['cep'];
    }//fim do if
    return $controlaEstoque;
  }
}