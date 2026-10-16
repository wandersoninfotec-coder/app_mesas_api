<?php
class configController extends controller{

  public function getTimer(){
    $timer=array();

    $conf = new Conf();
    $timer=$conf->getTimer();

    header("Content-Type: application/json");
    echo json_encode($timer['datahorasincronizacao']);
  }
}
?>
