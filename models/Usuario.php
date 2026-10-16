<?php
require_once 'lib/Criptografia.php';

class Usuario extends model {

    public function autenticar($nome, $senha, $empresa = 1) {
        try {
            if (!class_exists('Criptografia')) {
                return ['erro' => 'Classe Criptografia não carregada'];
            }

            $senhaCripto = Criptografia::encriptar($senha);

            $sql = $this->db->prepare("SELECT codigo, nome, status, empresa, senha
                                        FROM usuarios
                                        WHERE empresa = :empresa AND nome = :nome
                                        LIMIT 1");
            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':nome', strtoupper($nome));
            $sql->execute();

            if ($sql->rowCount() == 0) {
                return ['erro' => 'Usuário não encontrado'];
            }

            $user = $sql->fetch(PDO::FETCH_ASSOC);

            if ($user['senha'] !== $senhaCripto) {
                return ['erro' => 'Senha incorreta'];
            }

            if (isset($user['status']) && $user['status'] != 0 && $user['status'] != '0') {
                return ['erro' => 'Usuário inativo'];
            }

            unset($user['senha']);
            return $user;

        } catch (Throwable $t) {
            return ['erro' => 'Erro interno: ' . $t->getMessage()];
        }
    }

    /**
     * Verifica se um token é válido (para rotas protegidas)
     */
    public function validarToken($token) {
        try {
            $decoded = base64_decode($token);
            $parts = explode(':', $decoded);

            if (count($parts) !== 3) return null;

            $codigo = $parts[0];
            $timestamp = $parts[1];
            $hash = $parts[2];

            // Token expira em 24 horas
            if (time() - $timestamp > 86400) return null;

            // Valida o hash
            $hashEsperado = md5($codigo . 'chave_secreta_app');
            if ($hash !== $hashEsperado) return null;

            // Busca o usuário
            $sql = $this->db->prepare("SELECT codigo, nome, perfil, empresa FROM usuarios WHERE codigo = :codigo AND status = 1");
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
    * Busca o nome do funcionário vinculado ao usuário
    */
    public function buscarNomeFuncionario($empresa, $codigoUsuario) {
        try {
            // ⚠️ Se der erro de sintaxe no MySQL, troque f.USER por f.`USER`
            $sql = $this->db->prepare("SELECT f.nome
                                        FROM funcionarios f
                                        INNER JOIN usuarios u
                                            ON u.empresa = f.empresa
                                            AND f.USER = u.codigo
                                        WHERE u.empresa = :empresa
                                        AND u.codigo = :codigo
                                        LIMIT 1");
            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':codigo', $codigoUsuario);
            $sql->execute();

            if ($sql->rowCount() > 0) {
                $row = $sql->fetch(PDO::FETCH_ASSOC);
                return $row['nome'];
            }
            return null;
        } catch (Exception $e) {
            error_log("Erro ao buscar nome funcionário: " . $e->getMessage());
            return null;
        }
    }

    /**
    * Busca o código do funcionário vinculado ao usuário
    */
    public function buscarCodigoFuncionario($empresa, $codigoUsuario) {
        try {
            $sql = $this->db->prepare("SELECT f.codigo
                                        FROM funcionarios f
                                        INNER JOIN usuarios u
                                            ON u.empresa = f.empresa
                                            AND f.`USER` = u.codigo
                                        WHERE u.empresa = :empresa
                                        AND u.codigo = :codigo
                                        LIMIT 1");
            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':codigo', $codigoUsuario);
            $sql->execute();

            if ($sql->rowCount() > 0) {
                $row = $sql->fetch(PDO::FETCH_ASSOC);
                return (int)$row['codigo'];
            }
            return null;
        } catch (Exception $e) {
            error_log("Erro ao buscar código funcionário: " . $e->getMessage());
            return null;
        }
    }
}
?>
