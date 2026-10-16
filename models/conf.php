<?php
class Conf extends model{

  public function getTimer(){
    $Timer= array();

    $sql=$this->db->prepare("SELECT DATE_FORMAT(CURRENT_TIMESTAMP  , '%d/%c/%Y %H:%i:%s') AS datahorasincronizacao ");
    //$sql=$this->db->prepare("SELECT CURRENT_TIMESTAMP as datahorasincronizacao ");

    $sql->execute();

    if($sql->rowCount()>0){
      $Timer=$sql->fetch();
      $Timer['datahorasincronizacao']=explode(',', $Timer['datahorasincronizacao']);
    }//fim do if
    return $Timer;
  }
}
