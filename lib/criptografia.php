<?php
/**
 * Criptografia compatível com o sistema VB6 legado do ERP
 * Traduzida fielmente da função Criptografia() do VB6
 */
class Criptografia {

    /**
     * Criptografa uma string
     */
    public static function encriptar($texto) {
        $texto = strtolower($texto);
        $resultado = '';
        $len = strlen($texto);

        for ($i = 0; $i < $len; $i++) {
            $caracter = ord($texto[$i]) * 12 + 100000;
            $caracterStr = (string)$caracter;
            $caracterStr = substr($caracterStr, 1); // Remove primeiro dígito
            $resultado .= $caracterStr;
        }

        return $resultado;
    }

    /**
     * Descriptografa uma string
     */
    public static function decriptar($texto) {
        $resultado = '';
        $len = strlen($texto);

        for ($i = 0; $i < $len; $i += 5) {
            $caracter = '1' . substr($texto, $i, 5);
            $valor = (intval($caracter) - 100000) / 12;
            $resultado .= chr($valor);
        }

        return $resultado;
    }

    /**
     * Verifica senha
     */
    public static function verificarSenha($senhaDigitada, $senhaBanco) {
        return self::encriptar($senhaDigitada) === $senhaBanco;
    }
}
?>