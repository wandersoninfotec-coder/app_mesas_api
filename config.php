<?php
require 'environment.php';

global $config;
$config = array();
if(ENVIRONMENT == 'development'){
  $config['dbname']='controlfacil';
  $config['host']='127.0.0.1';
  $config['dbuser']='root';
  $config['dbpass']='w31d48s38';
  $config['char_set'] = 'utf8';
}else{
  $config['dbname']='controlfacil';
  $config['host']='168.168.100.14';
  $config['dbuser']='root';
  $config['dbpass']='w31d48s38';
  $config['char_set'] = 'utf8';
}
?>
