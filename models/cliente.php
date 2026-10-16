<?php
class Cliente extends model{
    //Listar todos os clientes cadastrados no servidor;
  /*  public function listar(){
      try{
          $array =  array();

          $sql ="SELECT c.codigo,
                        c.razao,
                        c.fantasia,
                        c.tipo,
                        c.cpf,
                        c.rg,
                        c.telefone,
                        c.celular,
                        c.endereco,
                        c.bairro,
                        m.codigo AS cidade,
                        c.uf,
                        c.cep,
                        c.contato,
                        c.email
                        FROM clientes c
                        left JOIN municipios m
                        ON m.codigo= c.cidade where cliente_fornecedor='C' and status=1;";

          $sql = $this->db->query($sql);
          if($sql->rowCount()>0){
            $array=$sql->fetChAll();
          }
          return $array;
        }catch(Exception $e){
            $file = fopen("logs/listarCliente.txt","w");
            echo fwrite($file,"Erro ao Buscar Cliente venda: ".$e);
            fclose($file);
        }
    }*/
    //Listar todos os clientes cadastrados no servidor;
    public function listarConsulta($empresa, $desc){
      try{
          $array =  array();

          $sql ="SELECT c.codigo,
                        c.razao,
                        c.fantasia,
                        c.tipo,
                        c.cpf,
                        c.rg,
                        c.telefone,
                        c.celular,
                        c.endereco,
                        c.bairro,
                        m.codigo AS cidade,
                        c.uf,
                        c.cep,
                        c.contato,
                        c.email
                        FROM clientes c
                        left JOIN municipios m
                        ON m.codigo= c.cidade where cliente_fornecedor='C' and 
                        status=1 and 
                        empresa=$empresa and 
                        (razao like '%$desc%' or 
                        fantasia like '%$desc%');";


          //echo $sql;
          $sql = $this->db->query($sql);

          if($sql->rowCount()>0){
            $array=$sql->fetChAll();
          }

          return $array;

        }catch(Exception $e){
            $file = fopen("logs/listarCliente.txt","w");
            echo fwrite($file,"Erro ao Buscar Cliente venda: ".$e);
            fclose($file);
        }
    }
    //Adicionar um novo cadastro de clientes ao Servidor
    public function addCliente($empresa, $razao, $fantasia, $tipo, $cpf, 
                               $rg, $telefone, $endereco, $bairro, $cidade,  
                               $email,$contato){
      try{
          $array =  array();
          //Seleciona o ultimo código de cliente usado no servidor e add +1;
          $sql="select max(codigo) as cod from clientes";
          $sql=$this->db->query($sql);
          if($sql->rowCount()>0){
            $array=$sql->fetCh();
            $cod = $array['cod']+1;//Código gerado pra inserir no servidor;
          }
          //Verifica o Tipo de cadastro e concatena com caracteres - tipo 1= Fisica e tipo 2 = Juridica
          if($tipo==2){
            $cpfcnpj=substr($cpf,0,2).'.'.substr($cpf,2,3).'.'.substr($cpf,5,3).'/'.substr($cpf,8,4).'-'.substr($cpf,12,2);
          }else{
            $cpfcnpj=substr($cpf,0,3).'.'.substr($cpf,3,3).'.'.substr($cpf,6,3).'-'.substr($cpf,9,2);
          }
          $this->db->query("INSERT INTO clientes SET empresa='$empresa', 
                                                     codigo='$cod', 
                                                     razao='$razao', 
                                                     fantasia='$fantasia',  
                                                     tipo='$tipo',  
                                                     cpf='$cpfcnpj',  
                                                     rg='$rg', 
                                                     telefone='$telefone', 
                                                     endereco='$endereco',
                                                      bairro='$bairro', 
                                                      cidade='$cidade',
                                                      email='$email', 
                                                      contato='$contato'");

        }catch(Exception $e){
            $file = fopen("logs/addCliente.txt","w");
            echo fwrite($file,"Erro ao gravar cliente: ".$e);
            fclose($file);
        }
      }
  public function editCliente($cliente, $empresa, $razao, $fantasia,$tipo, $cpf, $rg, $telefone, $endereco, $bairro, $cidade, $email,$contato){
    try{
      
     //Verifica o Tipo de cadastro e concatena com caracteres - tipo 1= Fisica e tipo 2 = Juridica
      if($tipo==2){
        $cpfcnpj=substr($cpf,0,2).'.'.substr($cpf,2,3).'.'.substr($cpf,5,3).'/'.substr($cpf,8,4).'-'.substr($cpf,12,2);
      }else{
        $cpfcnpj=substr($cpf,0,3).'.'.substr($cpf,3,3).'.'.substr($cpf,6,3).'-'.substr($cpf,9,2);
      }
      $this->db->query("update clientes SET   razao='$razao', 
                                              fantasia='$fantasia', 
                                              tipo='$tipo', 
                                              cpf='$cpfcnpj', 
                                              rg='$rg', 
                                              telefone='$telefone', 
                                              endereco='$endereco', 
                                              bairro='$bairro', 
                                              cidade='$cidade',
                                              email='$email', contato='$contato' where empresa=$empresa and codigo=$cliente");
   
    }catch(Exception $e){
        $file = fopen("logs/addCliente.txt","w");
        echo fwrite($file,"Erro ao gravar cliente: ".$e);
        fclose($file);
    }
  }
  }
?>
