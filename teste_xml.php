<?php
header('Content-Type: text/plain; charset=utf-8');

// Ajuste as credenciais conforme seu ambiente
$host = '127.0.0.1';
$db   = 'controlfacil';   // ← nome do banco
$user = 'root';
$pass = 'w31d48s38';                // ← senha do MySQL (XAMPP padrão é vazio)

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $t) {
    echo "Erro conexao: " . $t->getMessage() . "\n";
    exit;
}

$sql = $pdo->prepare("SELECT xml_assinado FROM tab_nfce WHERE emp_codigo=1 AND numero_nfce=6009");
$sql->execute();
$xml = $sql->fetchColumn();

if (!$xml) {
    echo "XML assinado nao encontrado no banco\n";
    exit;
}

echo "Tamanho: " . strlen($xml) . "\n\n";

echo "Primeiros 40 bytes em HEX:\n";
for ($i = 0; $i < 40; $i++) {
    if (isset($xml[$i])) echo sprintf("%02X ", ord($xml[$i]));
}
echo "\n\n";

echo "Primeiros 200 chars:\n";
echo substr($xml, 0, 200) . "\n\n";

if (preg_match('/<\?xml[^>]*\?>/', $xml, $m)) {
    echo "Declaracao XML: " . $m[0] . "\n";
} else {
    echo "SEM declaracao <?xml ?>\n";
}

$enc = mb_detect_encoding($xml, ['UTF-8', 'ISO-8859-1', 'ASCII'], true);
echo "Encoding detectado: $enc\n";

if (preg_match('/[^\x00-\x7F]/', $xml, $m, PREG_OFFSET_CAPTURE)) {
    $offset = $m[0][1];
    echo "\nPrimeiro byte nao-ASCII no offset $offset:\n";
    for ($i = max(0, $offset - 2); $i < min(strlen($xml), $offset + 6); $i++) {
        echo sprintf("%02X ", ord($xml[$i]));
    }
    echo "\n\nContexto:\n";
    echo substr($xml, max(0, $offset-30), 60) . "\n";
} else {
    echo "\nNenhum byte nao-ASCII encontrado (XML so tem ASCII)\n";
}