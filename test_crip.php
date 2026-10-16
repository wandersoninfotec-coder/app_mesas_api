<?php
require_once 'lib/Criptografia.php';

$senha = "w31d48s38";
$cripto = Criptografia::encriptar($senha);
$esperado = "014280061200588012000062400672013800061200672";

echo "Senha original: $senha\n";
echo "Criptografada: $cripto\n";
echo "Esperada no banco: $esperado\n";
echo "Bateu? " . ($cripto === $esperado ? "SIM ✅" : "NÃO ❌") . "\n";
?>