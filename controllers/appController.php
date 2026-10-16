<?php

class appController extends controller {

    public function __construct() {
        parent::__construct();
    }

    public function ping() {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'ok',
            'controller' => 'appController',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    public function login() {
        header('Content-Type: application/json; charset=utf-8');
        $rawInput = file_get_contents('php://input');
        $json = json_decode($rawInput);

        if ($json && is_object($json)) {
            $usuario = trim($json->usuario ?? '');
            $senha = trim($json->senha ?? '');
            $empresa = intval($json->empresa ?? 1);
        } else {
            $usuario = trim($_GET['usuario'] ?? $_POST['usuario'] ?? '');
            $senha = trim($_GET['senha'] ?? $_POST['senha'] ?? '');
            $empresa = intval($_GET['empresa'] ?? $_POST['empresa'] ?? 1);
        }

        if (empty($usuario) || empty($senha)) {
            http_response_code(400);
            echo json_encode(['erro' => 'Usuário e senha são obrigatórios']);
            return;
        }

        if (!class_exists('Usuario')) {
            echo json_encode(['erro' => 'Classe Usuario não carregada']);
            return;
        }

        try {
            $u = new Usuario();
            $user = $u->autenticar($usuario, $senha, $empresa);

            if (!is_array($user) || !isset($user['codigo'])) {
                http_response_code(401);
                $erro = is_array($user) && isset($user['erro'])
                    ? $user['erro']
                    : 'Usuário ou senha inválidos';
                echo json_encode(['erro' => $erro]);
                return;
            }

            // ============ BUSCA DADOS COMPLEMENTARES ============

            // 1. Nome do funcionário
            $nomeFuncionario = null;
            try {
                $nomeFuncionario = $u->buscarNomeFuncionario($user['empresa'], $user['codigo']);
            } catch (Exception $e) {}

            // 2. Perfil do usuário
            $perfil = 3; // padrão: vendas
            try {
                $sql = $this->db->prepare("SELECT perfil FROM usuarios
                                            WHERE empresa = :empresa AND nome = :nome LIMIT 1");
                $sql->bindValue(':empresa', $empresa);
                $sql->bindValue(':nome', $user['nome']);
                $sql->execute();
                if ($sql->rowCount() > 0) {
                    $row = $sql->fetch(PDO::FETCH_ASSOC);
                    if (!empty($row['perfil'])) $perfil = (int)$row['perfil'];
                }
            } catch (Exception $e) {}

            // 3. Permissões
            $permissoes = [
                'visualizarRelatorios'    => false,
                'cancelarVenda'           => false,
                'visualizarConfiguracoes' => false,
                // Caixa
                'visualizarCaixa'         => false,
                'lancarCaixa'             => false,
                'estornarCaixa'           => false,
                'excluirCaixa'            => false,
                'liberarCaixa'            => false,
                'verSaldosCaixa'          => false,
                'manutAberturaCaixa'      => false,
                'manutCentroCustoCaixa'   => false,
                'cadastrarContaCaixa'     => false,
                'abrirCaixa'              => false,
                'relatorioCaixa'          => false,
            ];

            $mapaPerm = [
                // Relatórios / Config (já existiam)
                ['chave' => 'visualizarRelatorios',    'rotina' => 'relvendasdiario',                  'campo' => 'temacesso'],
                ['chave' => 'cancelarVenda',           'rotina' => 'cancelarorcamento',                'campo' => 'executar'],
                ['chave' => 'visualizarConfiguracoes', 'rotina' => 'utilconfigsistema',                'campo' => 'temacesso'],

                // Caixa — rotinas reais
                ['chave' => 'visualizarCaixa',         'rotina' => 'caixamovimento',                   'campo' => 'temacesso'],
                ['chave' => 'lancarCaixa',             'rotina' => 'caixamovimento_novolacto',         'campo' => 'executar'],
                ['chave' => 'estornarCaixa',           'rotina' => 'caixamovimento_estornarlacto',     'campo' => 'executar'],
                ['chave' => 'excluirCaixa',            'rotina' => 'caixamovimento_excluirlacto',      'campo' => 'executar'],
                ['chave' => 'liberarCaixa',            'rotina' => 'caixamovimento_liberar',           'campo' => 'executar'],
                ['chave' => 'verSaldosCaixa',          'rotina' => 'caixamovimento_mostrarsaldos',     'campo' => 'temacesso'],
                ['chave' => 'manutAberturaCaixa',      'rotina' => 'caixamovimento_manutabertura',     'campo' => 'executar'],
                ['chave' => 'manutCentroCustoCaixa',   'rotina' => 'caixamovimento_manutcentrocusto',  'campo' => 'executar'],
                ['chave' => 'cadastrarContaCaixa',     'rotina' => 'caixacadastroconta',               'campo' => 'executar'],
                ['chave' => 'abrirCaixa',              'rotina' => 'caixaabertura',                    'campo' => 'executar'],
                ['chave' => 'relatorioCaixa',          'rotina' => 'relcaixa',                         'campo' => 'temacesso'],
            ];

            foreach ($mapaPerm as $p) {
                $col = $p['campo'];  // 'temacesso' ou 'executar'
                try {
                    $sql = $this->db->prepare("SELECT {$col} AS valor
                        FROM tab_modulos_perfil_acao
                        WHERE empresa = :empresa AND rotina = :rotina AND perfil = :perfil
                        LIMIT 1");
                    $sql->bindValue(':empresa', $empresa);
                    $sql->bindValue(':rotina',  $p['rotina']);
                    $sql->bindValue(':perfil',  $perfil);
                    $sql->execute();
                    if ($sql->rowCount() > 0) {
                        $row = $sql->fetch(PDO::FETCH_ASSOC);
                        $permissoes[$p['chave']] = !empty($row['valor']) && (int)$row['valor'] === 1;
                    }
                } catch (Throwable $e) {}
            }

            // 4. Desconto máximo (percentual)
            $descontoMaximo = 0;
            try {
                $sql = $this->db->prepare("SELECT desconto_vista FROM usuarios_desconto
                                            WHERE empresa = :empresa AND usuario = :usuario LIMIT 1");
                $sql->bindValue(':empresa', $empresa);
                $sql->bindValue(':usuario', $user['codigo']);
                $sql->execute();
                if ($sql->rowCount() > 0) {
                    $row = $sql->fetch(PDO::FETCH_ASSOC);
                    $descontoMaximo = (float)($row['desconto_vista'] ?? 0);
                }
            } catch (Exception $e) {}

            // ============ GERA TOKEN E RETORNA ============
            $token = base64_encode(
                $user['codigo'] . ':' . time() . ':' . md5($user['codigo'] . 'WDSRestauranteApp2026')
            );

            // 5. Modo PDV (mesas vs balcão)
            // param_sistema.usa_controle_mesas é char(1): 'S' = mesas, 'N' = balcão
            $usaControleMesas = true;   // fallback: modo mesas
            try {
                $sql = $this->db->prepare("SELECT usa_controle_mesas
                                            FROM param_sistema
                                            WHERE empresa = :empresa LIMIT 1");
                $sql->bindValue(':empresa', $empresa);
                $sql->execute();
                $row = $sql->fetch(PDO::FETCH_ASSOC);

                if ($row && isset($row['usa_controle_mesas'])) {
                    $v = strtoupper(trim((string)$row['usa_controle_mesas']));
                    // Aceita: '1', 'S', 'Y', 'T', 'TRUE' (case-insensitive)
                    $usaControleMesas = in_array($v, ['1', 'S', 'Y', 'T', 'TRUE'], true);
                }
            } catch (Throwable $e) {
                error_log("[LOGIN] Erro lendo usa_controle_mesas: " . $e->getMessage());
            }

            echo json_encode([
                'sucesso' => true,
                'token' => $token,
                'usuario' => [
                    'id' => (int)$user['codigo'],
                    'nome' => $user['nome'],
                    'empresa' => (int)$user['empresa'],
                    'perfil' => $perfil,
                    'perfilNome' => 'Garçom',
                    'nomeFuncionario' => $nomeFuncionario,
                    'permissoes' => $permissoes,
                    'descontoMaximo' => $descontoMaximo,
                    'usaControleMesas' => $usaControleMesas,
                ]
            ]);

        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode([
                'erro_fatal' => $t->getMessage(),
                'arquivo' => $t->getFile(),
                'linha' => $t->getLine(),
            ]);
        }
    }

    public function vendaAbrir() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa   = $dados->empresa ?? 1;
        $mesa      = $dados->mesa ?? $dados->mesa_comanda ?? 0;
        $vendedor  = $dados->vendedor ?? 1;
        $isBalcao = in_array((int)$mesa, [0, 9999], true);

        // Mesa obrigatória apenas quando NÃO é balcão
        if (!$isBalcao && !$mesa) {
            http_response_code(400);
            echo json_encode(['erro' => 'Mesa obrigatória']);
            return;
        }

        $v = new Vendas();

                $v = new Vendas();

        // Balcão: sempre cria venda nova
        if ($isBalcao) {
            $novaVenda = $v->codigoNovaVenda();
            if (!$novaVenda || $novaVenda <= 0) {
                http_response_code(500);
                echo json_encode(['erro' => 'Falha ao gerar código de venda']);
                return;
            }

            try {
                $v->addVenda($empresa, $novaVenda, $vendedor, (int)$mesa);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['erro' => 'Falha ao salvar venda: ' . $e->getMessage()]);
                return;
            }

           echo json_encode([
                'sucesso'   => true,
                'ja_aberta' => false,
                'venda'     => (int)$novaVenda,
                'mesa'      => (int)$mesa,     // ← devolve o mesmo que veio
                'total'     => 0,
                'mensagem'  => ((int)$mesa === 9999) ? 'Venda direta aberta' : 'Venda de balcão aberta'
            ]);

            return;
        }


        // 1. Verifica se a mesa tem venda aberta
        $vendaExistente = $v->listar($empresa, $mesa);

        if ($vendaExistente && isset($vendaExistente['venda'])
            && $vendaExistente['venda'] != '0'
            && $vendaExistente['venda'] != 0) {

            echo json_encode([
                'sucesso' => true,
                'ja_aberta' => true,
                'venda' => (int)$vendaExistente['venda'],
                'mesa' => (int)$mesa,
                'total' => (float)($vendaExistente['total'] ?? 0),
                'vendedor' => $vendaExistente['vendedor'] ?? null,
                'mensagem' => 'Mesa já possui venda aberta'
            ]);
            return;
        }

        // 2. Mesa livre — cria nova venda
        $novaVenda = $v->codigoNovaVenda();

        if (!$novaVenda || $novaVenda <= 0) {
            http_response_code(500);
            echo json_encode(['erro' => 'Falha ao gerar código de venda']);
            return;
        }

        try {
            $v->addVenda($empresa, $novaVenda, $vendedor, $mesa);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Falha ao salvar venda: ' . $e->getMessage()]);
            return;
        }

        // 3. Confirma que foi salva
        $conf = $v->listar($empresa, $mesa);

        echo json_encode([
            'sucesso' => true,
            'ja_aberta' => false,
            'venda' => (int)$novaVenda,
            'mesa' => (int)$mesa,
            'total' => 0,
            'confirmacao' => $conf,
            'mensagem' => 'Mesa aberta com sucesso'
        ]);
    }

    public function debugVenda() {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $sql = $this->db->query("SELECT COUNT(*) as total, MAX(venda) as max_venda FROM vendas");
            $infoVendas = $sql->fetch(PDO::FETCH_ASSOC);

            $v = new Vendas();
            $novaVenda = $v->codigoNovaVenda();

            echo json_encode([
                'info_tabela' => $infoVendas,
                'codigoNovaVenda_retornou' => $novaVenda,
                'tipo' => gettype($novaVenda)
            ], JSON_PRETTY_PRINT);
        } catch (Exception $e) {
            echo json_encode([
                'erro' => $e->getMessage(),
                'linha' => $e->getLine()
            ], JSON_PRETTY_PRINT);
        }
    }

        /**
    * Adiciona item com transação e lock (protege concorrência)
    * POST /app/itemAdicionarSeguro
    */
    public function itemAdicionarSeguro() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;
        $venda = $dados->venda ?? 0;
        $referencia = $dados->referencia ?? '';
        $quantidade = $dados->quantidade ?? 1;
        $preco = $dados->preco ?? 0;
        $vendedor = $dados->vendedor ?? 1;
        $observacao = $dados->observacao ?? '';

        if (!$venda || !$referencia) {
            http_response_code(400);
            echo json_encode(['erro' => 'Venda e referência obrigatórias']);
            return;
        }

        try {
            $this->db->beginTransaction();

            // 🔒 LOCK: bloqueia a venda para outras transações
            $lock = $this->db->prepare("SELECT venda, status FROM vendas
                WHERE empresa = :empresa AND venda = :venda
                FOR UPDATE");
            $lock->bindValue(':empresa', $empresa);
            $lock->bindValue(':venda', $venda);
            $lock->execute();

            $vendaDados = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$vendaDados) {
                $this->db->rollBack();
                http_response_code(404);
                echo json_encode(['erro' => 'Venda não encontrada']);
                return;
            }

            // 🚨 Se o PDV já fechou, rejeita
            if ($vendaDados['status'] != 0) {
                $this->db->rollBack();
                http_response_code(409);
                echo json_encode([
                    'erro' => 'Esta venda já foi fechada no PDV',
                    'status' => $vendaDados['status'],
                    'motivo' => 'venda_fechada'
                ]);
                return;
            }

            // Adiciona com segurança
            $v = new Vendas();

            $grupo = $v->codigoGrupo($referencia);
            $cfop = $v->codigoCFOP($referencia);
            $item = $v->codigoItem($empresa, $venda);
            $pTribAprox = $v->pTribAprox($empresa, $referencia);
            $seq = $v->getSequncialCozinha();

            $totalBruto = $preco * $quantidade;
            $totalLiquido = $totalBruto;

            $v->addItem(
                $empresa, $venda, $item, $grupo, $referencia,
                $quantidade, $preco, $totalLiquido, $totalBruto,
                0, $vendedor, $pTribAprox, $cfop, $observacao, $seq
            );

            $this->reconciliarPrecosProduto($empresa, $venda, $referencia);
            $this->recalcularTotalVenda($empresa, $venda); 

            $this->db->commit();

            echo json_encode([
                'sucesso' => true,
                'item' => $item,
                'mensagem' => 'Item adicionado com sucesso'
            ]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }

    /**
    * Verifica se uma venda ainda está aberta
    * POST /app/verificarStatus
    */
    public function verificarStatus() {
        //
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;
        $venda = $dados->venda ?? 0;

        if (!$venda) {
            echo json_encode(['erro' => 'Venda obrigatória']);
            return;
        }

        $sql = $this->db->prepare("SELECT status, total, mesa_comanda
                                FROM vendas
                                WHERE empresa = :empresa AND venda = :venda");
        $sql->bindValue(':empresa', $empresa);
        $sql->bindValue(':venda', $venda);
        $sql->execute();

        if ($sql->rowCount() === 0) {
            echo json_encode(['existe' => false]);
            return;
        }

        $row = $sql->fetch(PDO::FETCH_ASSOC);
        $status = intval($row['status']);

        echo json_encode([
            'existe' => true,
            'status' => $status,
            'aberta' => $status === 0,
            'fechada' => $status !== 0,
            'total' => floatval($row['total']),
            'mesa_comanda' => $row['mesa_comanda'],
        ]);
    }

    /**
    * Lista histórico de vendas (fechadas/canceladas)
    * POST /app/historicoListar
    */
    public function historicoListar() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;
        $periodo = $dados->periodo ?? 'hoje';
        $statusFiltro = $dados->status ?? 'todas';
        $mesaFiltro = $dados->mesa ?? null; // ✅ NOVO

        try {
            // Filtro de data
            $filtroData = '';
            switch ($periodo) {
                case 'hoje':
                    $filtroData = "AND DATE(v.data) = CURDATE()";
                    break;
                case '7dias':
                    $filtroData = "AND v.data >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                    break;
                case 'mes':
                    $filtroData = "AND MONTH(v.data) = MONTH(CURDATE())
                                AND YEAR(v.data) = YEAR(CURDATE())";
                    break;
                case 'todos':
                default:
                    $filtroData = "";
                    break;
            }

            // Filtro de status
            $filtroStatus = '';
            if ($statusFiltro === 'fechadas') {
                $filtroStatus = "AND v.status = 1";
            } elseif ($statusFiltro === 'canceladas') {
                $filtroStatus = "AND v.status = 2";
            } else {
                $filtroStatus = "AND v.status != 0";
            }

            // ✅ NOVO: filtro de mesa
            $filtroMesa = '';
            $paramsMesa = [];
            if (!empty($mesaFiltro)) {
                $filtroMesa = "AND v.mesa_comanda = :mesa";
                $paramsMesa[':mesa'] = $mesaFiltro;
            }

            $sql = $this->db->prepare("SELECT
                    v.venda, v.empresa, v.mesa_comanda, v.status, v.data, v.hora_venda,
                    v.vendedor, v.total, v.forma_pgto, v.origem,
                    m.mesa_descricao,
                    f.nome AS vendedor_nome
                FROM vendas v
                LEFT JOIN mesas_comanda m
                    ON v.mesa_comanda = m.mesa_codigo
                    AND v.empresa = m.empresa
                LEFT JOIN usuarios u
                    ON u.empresa = v.empresa
                    AND u.codigo = v.vendedor
                LEFT JOIN funcionarios f
                    ON f.empresa = u.empresa
                    AND f.`USER` = u.codigo
                WHERE v.empresa = :empresa
                    $filtroData
                    $filtroStatus
                    $filtroMesa
                ORDER BY v.venda DESC
                LIMIT 100");

            $sql->bindValue(':empresa', $empresa);
            foreach ($paramsMesa as $chave => $valor) {
                $sql->bindValue($chave, $valor);
            }
            $sql->execute();

            $vendas = $sql->fetchAll(PDO::FETCH_ASSOC);

            $resultado = array_map(function($v) {
                return [
                    'venda' => (int)$v['venda'],
                    'mesa' => $v['mesa_comanda'],
                    'mesa_descricao' => $v['mesa_descricao'] ?? ('Mesa ' . $v['mesa_comanda']),
                    'status' => (int)$v['status'],
                    'status_label' => $v['status'] == 1 ? 'Fechada' : 'Cancelada',
                    'data' => $v['data'],
                    'hora' => $v['hora_venda'],
                    'vendedor' => $v['vendedor'],
                    'total' => (float)$v['total'],
                    'forma_pgto' => (int)$v['forma_pgto'],
                    'origem' => $v['origem'] ?? '',
                    'vendedor_nome' => $v['vendedor_nome'] ?? null,
                ];
            }, $vendas);

            echo json_encode($resultado);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }

    /**
    * Detalhes de uma venda fechada
    * POST /app/historicoDetalhes
    */
    public function historicoDetalhes() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;
        $venda = $dados->venda ?? 0;

        if (!$venda) {
            http_response_code(400);
            echo json_encode(['erro' => 'Venda obrigatória']);
            return;
        }

        try {
            // 1. Cabeçalho (com vendedor_nome!)
            $sql = $this->db->prepare("SELECT
                    v.venda, v.mesa_comanda, v.status, v.data, v.hora_venda,
                    v.vendedor, v.total, v.forma_pgto, v.origem,
                    m.mesa_descricao,
                    f.nome AS vendedor_nome
                FROM vendas v
                LEFT JOIN mesas_comanda m
                    ON v.mesa_comanda = m.mesa_codigo
                    AND v.empresa = m.empresa
                LEFT JOIN funcionarios f
                        ON f.empresa = v.empresa
                        AND f.codigo = v.vendedor
                WHERE v.empresa = :empresa AND v.venda = :venda");

            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':venda', $venda);
            $sql->execute();

            if ($sql->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['erro' => 'Venda não encontrada']);
                return;
            }

            $cabecalho = $sql->fetch(PDO::FETCH_ASSOC);

            // 2. Itens da venda (query correta agora!)
            $sql = $this->db->prepare("SELECT
                    vi.item, vi.referencia, vi.quantidade, vi.preco,
                    vi.total_liquido, vi.cancelado, vi.observacao,
                    p.descricao
                FROM vendas_item vi
                LEFT JOIN produtos p
                    ON vi.empresa = p.empresa
                    AND vi.referencia = p.referencia
                WHERE vi.empresa = :empresa AND vi.venda = :venda
                ORDER BY vi.item");

            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':venda', $venda);
            $sql->execute();

            $itens = $sql->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'cabecalho' => [
                    'venda' => (int)$cabecalho['venda'],
                    'mesa' => $cabecalho['mesa_comanda'],
                    'mesa_descricao' => $cabecalho['mesa_descricao'] ?? ('Mesa ' . $cabecalho['mesa_comanda']),
                    'status' => (int)$cabecalho['status'],
                    'status_label' => $cabecalho['status'] == 1 ? 'Fechada' : 'Cancelada',
                    'data' => $cabecalho['data'],
                    'hora' => $cabecalho['hora_venda'],
                    'vendedor' => $cabecalho['vendedor'],
                    'total' => (float)$cabecalho['total'],
                    'forma_pgto' => (int)$cabecalho['forma_pgto'],
                    'origem' => $cabecalho['origem'] ?? '',
                    'vendedor_nome' => $cabecalho['vendedor_nome'] ?? null,
                ],
                'itens' => array_map(function($i) {
                    return [
                        'item' => (int)$i['item'],
                        'referencia' => $i['referencia'],
                        'descricao' => $i['descricao'] ?? $i['referencia'],
                        'quantidade' => (float)$i['quantidade'],
                        'preco' => (float)$i['preco'],
                        'total' => (float)$i['total_liquido'],
                        'cancelado' => (int)$i['cancelado'],
                        'observacao' => $i['observacao'] ?? '',
                    ];
                }, $itens),
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }

    /**
    * Busca dados da empresa (nome)
    * POST /app/dadosEmpresa
    * Body: { empresa: 1 }
    */
    public function dadosEmpresa() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;

        try {
            $sql = $this->db->prepare("SELECT codigo, razao AS nome, fantasia
                                FROM tabempresa
                                WHERE codigo = :empresa
                                LIMIT 1");

            $sql->bindValue(':empresa', $empresa);
            $sql->execute();

            if ($sql->rowCount() === 0) {
                echo json_encode([
                    'sucesso' => false,
                    'nome' => null,
                    'erro' => 'Empresa não encontrada'
                ]);
                return;
            }

            $row = $sql->fetch(PDO::FETCH_ASSOC);

             echo json_encode([
                'sucesso'  => true,
                'codigo'   => (int)$row['codigo'],
                'nome'     => $row['nome'],
                'fantasia' => !empty($row['fantasia']) ? $row['fantasia'] : $row['nome'],
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }

    /**
    * Retorna os dados necessários para finalizar uma venda:
    * - Caixa padrão (param_vendas)
    * - Lote de abertura (caixa_lote_abertura)
    * - Próximo sequencial (caixa_movimento)
    *
    * POST /app/dadosCaixa
    * Body: { empresa, caixa (opcional) }
    */

    public function dadosCaixa() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;
        $caixa = $dados->caixa ?? null;

        try {
            // 1. Se não foi passado caixa, busca o caixa padrão
            if (!$caixa) {
                $sql = $this->db->prepare("SELECT caixa_padrao
                                            FROM param_vendas
                                            WHERE empresa = :empresa
                                            LIMIT 1");
                $sql->bindValue(':empresa', $empresa);
                $sql->execute();

                if ($sql->rowCount() > 0) {
                    $row = $sql->fetch(PDO::FETCH_ASSOC);
                    $caixa = $row['caixa_padrao'];
                } else {
                    $caixa = '1'; // fallback
                }
            }

            // 2. Busca o lote de abertura do caixa
            $sql = $this->db->prepare("SELECT codigo
                                        FROM caixa_lote_abertura
                                        WHERE empresa = :empresa
                                        AND caixa_abertura = :caixa
                                        AND (data_fechamento = '' OR data_fechamento = '0000-00-00')
                                        ORDER BY codigo DESC
                                        LIMIT 1");
            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':caixa', $caixa);
            $sql->execute();

            $loteAbertura = 0;
            if ($sql->rowCount() > 0) {
                $row = $sql->fetch(PDO::FETCH_ASSOC);
                $loteAbertura = (int)$row['codigo'];
            }

            // 3. Busca o próximo sequencial do dia
            $sql = $this->db->prepare("SELECT COALESCE(MAX(sequencial), 0) + 1 AS proximo
                                        FROM caixa_movimento
                                        WHERE empresa = :empresa
                                        AND caixa = :caixa
                                        AND datalancamento = CURDATE()");
            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':caixa', $caixa);
            $sql->execute();

            $row = $sql->fetch(PDO::FETCH_ASSOC);
            $proximoSequencial = (int)$row['proximo'];

            echo json_encode([
                'sucesso' => true,
                'caixa' => $caixa,
                'lote_abertura' => $loteAbertura,
                'proximo_sequencial' => $proximoSequencial,
                'tem_caixa_aberto' => $loteAbertura > 0,
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }

    /**
    * Finaliza uma venda com uma ou mais formas de pagamento
    * POST /app/finalizarVenda
    * Body: {
    *   empresa, venda, usuario, cliente, valor_desconto,
    *   formas: [{ codigo, valor, valor_recebido }, ...]
    * }
    */
    public function finalizarVenda() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $origem = $dados->origem ?? 'FACILIT';   // FACILIT | FACILIT_APP | ERP2

        $empresa = $dados->empresa ?? 1;
        $venda = $dados->venda ?? 0;
        $usuario = $dados->usuario ?? 1;
        $cliente = $dados->cliente ?? 1;
        $valorDesconto = (float)($dados->valor_desconto ?? 0);
        $formas = $dados->formas ?? [];

        if (!$venda || empty($formas)) {
            http_response_code(400);
            echo json_encode(['erro' => 'Venda e formas são obrigatórias']);
            return;
        }

        try {
            // 1. Busca dados da venda
            $sql = $this->db->prepare("SELECT total, status FROM vendas
                                        WHERE empresa = :empresa AND venda = :venda");
            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':venda', $venda);
            $sql->execute();

            if ($sql->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['erro' => 'Venda não encontrada']);
                return;
            }

            $vendaDados = $sql->fetch(PDO::FETCH_ASSOC);

            if ($vendaDados['status'] != 0) {
                http_response_code(409);
                echo json_encode(['erro' => 'Esta venda já foi fechada']);
                return;
            }

            $totalVendaOriginal = (float)$vendaDados['total'];
            $totalVenda = max(0, $totalVendaOriginal - $valorDesconto);

            // 2. Valida se a soma das formas bate com o total
            $somaFormas = 0;
            foreach ($formas as $f) {
                $somaFormas += (float)($f->valor ?? 0);
            }

            if (abs($somaFormas - $totalVenda) > 0.01) {
                http_response_code(400);
                echo json_encode([
                    'erro' => 'A soma das formas (R$ ' . number_format($somaFormas, 2, ',', '.') . ') não bate com o total (R$ ' . number_format($totalVenda, 2, ',', '.') . ')'
                ]);
                return;
            }

            // 3. Busca caixa padrão
            $sql = $this->db->prepare("SELECT caixa_padrao FROM param_vendas
                                        WHERE empresa = :empresa LIMIT 1");
            $sql->bindValue(':empresa', $empresa);
            $sql->execute();
            $caixaPadrao = '1';
            if ($sql->rowCount() > 0) {
                $row = $sql->fetch(PDO::FETCH_ASSOC);
                $caixaPadrao = $row['caixa_padrao'] ?: '1';
            }

            // 4. Busca nome do cliente para o histórico
            $nomeCliente = 'CONSUMIDOR FINAL';
            if ($cliente > 1) {
                $sql = $this->db->prepare("SELECT razao FROM clientes
                                            WHERE empresa = :empresa AND codigo = :cliente LIMIT 1");
                $sql->bindValue(':empresa', $empresa);
                $sql->bindValue(':cliente', $cliente);
                $sql->execute();
                if ($sql->rowCount() > 0) {
                    $row = $sql->fetch(PDO::FETCH_ASSOC);
                    if (!empty($row['razao'])) $nomeCliente = $row['razao'];
                }
            }

            $hora = date('H:i:s');
            $dataHoje = date('Y-m-d');

            // ============ INICIA TRANSAÇÃO ============
            $this->db->beginTransaction();

            try {
                // 5. INSERT vendas_parcela (valor total)
                $sql = $this->db->prepare("INSERT INTO vendas_parcela
                    (empresa, venda, parcela, vencimento, valor, cond_pgto, docto, forma_pgto)
                    VALUES (:empresa, :venda, 0, :vencimento, :valor, 1, 1, :forma)");
                $sql->bindValue(':empresa', $empresa);
                $sql->bindValue(':venda', $venda);
                $sql->bindValue(':vencimento', $dataHoje);
                $sql->bindValue(':valor', $totalVenda);
                $sql->bindValue(':forma', (int)$formas[0]->codigo);
                $sql->execute();

                // 6. INSERT receber (valor total)
                $sql = $this->db->prepare("INSERT INTO receber
                    (empresa, cliente, documento, tpdoc, lancamento, parcela, vencimento,
                    valor, portador, saldo, centrocusto, comissao, operador, historico,
                    cond_pgto, valor_fatura, hora, valor_acrescimo_parcela,
                    perc_juros_tipo, tipo_juro, comissionado, data_previsao_baixa,
                    nr_controle, agrupado)
                    VALUES (:empresa, :cliente, :documento, '1', :lancamento, 0, :vencimento,
                            :valor, '1', :saldo, '1.01.002', 0, :operador, :historico,
                            1, :valor_fatura, :hora, 0, 0, 0, 0, :data_prev, '', 0)");
                $sql->bindValue(':empresa', $empresa);
                $sql->bindValue(':cliente', $cliente);
                $sql->bindValue(':documento', $venda);
                $sql->bindValue(':lancamento', $dataHoje);
                $sql->bindValue(':vencimento', $dataHoje);
                $sql->bindValue(':valor', $totalVenda);
                $sql->bindValue(':saldo', $totalVenda);
                $sql->bindValue(':operador', $usuario);
                $sql->bindValue(':historico', "VENDA:$venda ");
                $sql->bindValue(':valor_fatura', $totalVenda);
                $sql->bindValue(':hora', $hora);
                $sql->bindValue(':data_prev', $dataHoje);
                $sql->execute();

                // 7. INSERT recebido (valor total)
                $sql = $this->db->prepare("INSERT INTO recebido
                    (empresa, cliente, documento, parcela, ordem,
                    valor_original, valorareceber, caixa, historico, data_baixa, origem_recebimento)
                    VALUES (:empresa, :cliente, :documento, 0, 1,
                            :valor, :valor, :caixa, 'RECEBIMENTO APP', :data_baixa, :origem)");

                $sql->bindValue(':origem', $origem);            
                $sql->bindValue(':empresa', $empresa);
                $sql->bindValue(':cliente', $cliente);
                $sql->bindValue(':documento', $venda);
                $sql->bindValue(':valor', $totalVenda);
                $sql->bindValue(':caixa', $caixaPadrao);
                $sql->bindValue(':data_baixa', $dataHoje);
                $sql->execute();

                // 8. Loop das formas: recebido_formas + caixa_movimento
                $sequencialForma = 1;
                $trocoTotal = 0;

                foreach ($formas as $forma) {
                    $codForma = (int)$forma->codigo;
                    $valorForma = (float)$forma->valor;
                    $valorRecebidoForma = (float)($forma->valor_recebido ?? $valorForma);

                    // 8.1. Busca caixa dedicado (se houver)
                    $sql = $this->db->prepare("SELECT caixa_dedicado
                                                FROM tab_formapagamento
                                                WHERE codigo = :codigo LIMIT 1");
                    $sql->bindValue(':codigo', $codForma);
                    $sql->execute();

                    $caixaForma = $caixaPadrao;
                    if ($sql->rowCount() > 0) {
                        $row = $sql->fetch(PDO::FETCH_ASSOC);
                        if (!empty($row['caixa_dedicado'])) {
                            $caixaForma = $row['caixa_dedicado'];
                        }
                    }

                    // 8.2. Busca lote de abertura do caixa
                    $sql = $this->db->prepare("SELECT codigo
                                                FROM caixa_lote_abertura
                                                WHERE empresa = :empresa
                                                AND caixa_abertura = :caixa
                                                AND (data_fechamento = '' OR data_fechamento = '0000-00-00')
                                                ORDER BY codigo DESC LIMIT 1");
                    $sql->bindValue(':empresa', $empresa);
                    $sql->bindValue(':caixa', $caixaForma);
                    $sql->execute();

                    // 8.2. Garante lote aberto para o caixa (auto/manual)
                    $loteForma = $this->caixaGarantirLoteAberto($empresa, $caixaForma);
                    if ($sql->rowCount() > 0) {
                        $row = $sql->fetch(PDO::FETCH_ASSOC);
                        $loteForma = (int)$row['codigo'];
                    }

                    if ($loteForma === 0) {
                        throw new Exception("Nenhum caixa aberto encontrado para a forma $codForma (caixa $caixaForma). Abra o caixa no ERP.");
                    }

                    // 8.3. Busca próximo sequencial do caixa
                    $sql = $this->db->prepare("SELECT COALESCE(MAX(sequencial), 0) + 1 AS proximo
                                                FROM caixa_movimento
                                                WHERE empresa = :empresa
                                                AND caixa = :caixa
                                                AND datalancamento = :data");
                    $sql->bindValue(':empresa', $empresa);
                    $sql->bindValue(':caixa', $caixaForma);
                    $sql->bindValue(':data', $dataHoje);
                    $sql->execute();
                    $row = $sql->fetch(PDO::FETCH_ASSOC);
                    $sequencialCaixa = (int)$row['proximo'];

                    // 8.4. INSERT recebido_formas
                    $sql = $this->db->prepare("INSERT INTO recebido_formas
                        (empresa, cliente, documento, parcela, ordem, sequencial,
                        forma_pgto, valor_recebido, data_baixa, caixa, tipo_documento, econf)
                        VALUES (:empresa, :cliente, :documento, 0, 1, :sequencial,
                                :forma, :valor, :data_baixa, :caixa, 1, 'S')");
                    $sql->bindValue(':empresa', $empresa);
                    $sql->bindValue(':cliente', $cliente);
                    $sql->bindValue(':documento', $venda);
                    $sql->bindValue(':sequencial', $sequencialForma);
                    $sql->bindValue(':forma', $codForma);
                    $sql->bindValue(':valor', $valorRecebidoForma);
                    $sql->bindValue(':data_baixa', $dataHoje);
                    $sql->bindValue(':caixa', $caixaForma);
                    $sql->execute();

                    // 8.5. INSERT caixa_movimento
                    $historicoCaixa = "RECEBIMENTO DOCTO:$venda ORDEM: 1 CLIENTE:$cliente - $nomeCliente";
                    $sql = $this->db->prepare("INSERT INTO caixa_movimento
                        (empresa, caixa, datalancamento, sequencial, codigoloteabertura,
                        nrodocumento, historico, centrocusto, valor, debitocredito,
                        origem, nroorigem, parcela, usuario, forma_pgto, tipo_lancamento,
                        estornado, data_estornado, hora, obs)
                        VALUES (:empresa, :caixa, :data_lanc, :sequencial, :lote,
                                :documento, :historico, '1.01.002', :valor, 'C',
                                'APP', :venda, 0, :usuario, :forma, 'D',
                                0, '0000-00-00', :hora, '')");
                    $sql->bindValue(':empresa', $empresa);
                    $sql->bindValue(':caixa', $caixaForma);
                    $sql->bindValue(':data_lanc', $dataHoje);
                    $sql->bindValue(':sequencial', $sequencialCaixa);
                    $sql->bindValue(':lote', $loteForma);
                    $sql->bindValue(':documento', $venda);
                    $sql->bindValue(':historico', $historicoCaixa);
                    $sql->bindValue(':valor', $valorForma);
                    $sql->bindValue(':venda', $venda);
                    $sql->bindValue(':usuario', $usuario);
                    $sql->bindValue(':forma', $codForma);
                    $sql->bindValue(':hora', $hora);
                    $sql->execute();

                    // 8.6. Calcula troco (só dinheiro)
                    if ($codForma === 1 && $valorRecebidoForma > $valorForma) {
                        $trocoTotal += ($valorRecebidoForma - $valorForma);
                    }

                    $sequencialForma++;
                }

                // 9. UPDATE receber SET saldo = 0
                $sql = $this->db->prepare("UPDATE receber SET saldo = 0
                                            WHERE empresa = :empresa
                                            AND cliente = :cliente
                                            AND documento = :documento
                                            AND parcela = 0");
                $sql->bindValue(':empresa', $empresa);
                $sql->bindValue(':cliente', $cliente);
                $sql->bindValue(':documento', $venda);
                $sql->execute();

                // 10. UPDATE recebido_formas SET recebido = 1
                $sql = $this->db->prepare("UPDATE recebido_formas SET recebido = 1
                                            WHERE empresa = :empresa
                                            AND cliente = :cliente
                                            AND documento = :documento
                                            AND parcela = 0
                                            AND ordem = 1");
                $sql->bindValue(':empresa', $empresa);
                $sql->bindValue(':cliente', $cliente);
                $sql->bindValue(':documento', $venda);
                $sql->execute();

                // 11. UPDATE recebido
                $sql = $this->db->prepare("UPDATE recebido
                                            SET valor_recebido = :valor_recebido,
                                                valor_troco = :troco,
                                                tipo_baixa = 'TOTAL',
                                                usuario = :usuario,
                                                hora = :hora,
                                                origem_recebimento = 'APP'
                                            WHERE empresa = :empresa
                                            AND cliente = :cliente
                                            AND documento = :documento
                                            AND parcela = 0
                                            AND ordem = 1");
                $sql->bindValue(':valor_recebido', $totalVenda);
                $sql->bindValue(':troco', $trocoTotal);
                $sql->bindValue(':usuario', $usuario);
                $sql->bindValue(':hora', $hora);
                $sql->bindValue(':empresa', $empresa);
                $sql->bindValue(':cliente', $cliente);
                $sql->bindValue(':documento', $venda);
                $sql->execute();

                            // 12. UPDATE vendas SET status = 1, desconto, total e cliente
                $sql = $this->db->prepare("UPDATE vendas
                                            SET status = 1,
                                                forma_pgto = :forma,
                                                desconto = :desconto,
                                                total = :total_final,
                                                cliente = :cliente
                                            WHERE empresa = :empresa
                                            AND venda = :venda
                                            AND status = 0");
                $sql->bindValue(':forma', (int)$formas[0]->codigo);
                $sql->bindValue(':desconto', $valorDesconto);
                $sql->bindValue(':total_final', $totalVenda);
                $sql->bindValue(':cliente', $cliente);
                $sql->bindValue(':empresa', $empresa);
                $sql->bindValue(':venda', $venda);
                $sql->execute();

                if ($sql->rowCount() === 0) {
                    throw new Exception('Venda já foi fechada por outro usuário');
                }

                // ============ COMMIT ============
                $this->db->commit();

                echo json_encode([
                    'sucesso' => true,
                    'venda' => (int)$venda,
                    'total' => $totalVenda,
                    'troco' => $trocoTotal,
                    'formas' => count($formas),
                    'mensagem' => 'Venda finalizada com sucesso'
                ]);

            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'erro' => 'Erro ao finalizar: ' . $e->getMessage()
            ]);
        }
    }

    /**
    * Relatórios de vendas do período
    * POST /app/relatoriosVendas
    * Body: { empresa, periodo (hoje|7dias|mes|todos) }
    */
    public function relatoriosVendas() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;
        $periodo = $dados->periodo ?? 'hoje';

        try {
            // Filtro de data
            $filtroData = '';
            switch ($periodo) {
                case 'hoje':
                    $filtroData = "AND DATE(v.data) = CURDATE()";
                    break;
                case '7dias':
                    $filtroData = "AND v.data >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                    break;
                case 'mes':
                    $filtroData = "AND MONTH(v.data) = MONTH(CURDATE())
                                AND YEAR(v.data) = YEAR(CURDATE())";
                    break;
                case 'todos':
                default:
                    $filtroData = "";
                    break;
            }

            // ============ 1. RESUMO GERAL ============
            $sql = $this->db->prepare("SELECT
                    COUNT(*) as quantidade,
                    COALESCE(SUM(v.total), 0) as total
                FROM vendas v
                WHERE v.empresa = :empresa
                AND v.status = 1
                $filtroData");
            $sql->bindValue(':empresa', $empresa);
            $sql->execute();
            $resumo = $sql->fetch(PDO::FETCH_ASSOC);

            $quantidade = (int)$resumo['quantidade'];
            $total = (float)$resumo['total'];
            $ticketMedio = $quantidade > 0 ? $total / $quantidade : 0;

            // ============ 2. POR FORMA DE PAGAMENTO ============
            $sql = $this->db->prepare("SELECT
                    v.forma_pgto,
                    COUNT(*) as quantidade,
                    COALESCE(SUM(v.total), 0) as total
                FROM vendas v
                WHERE v.empresa = :empresa
                AND v.status = 1
                $filtroData
                GROUP BY v.forma_pgto
                ORDER BY total DESC");
            $sql->bindValue(':empresa', $empresa);
            $sql->execute();
            $porForma = $sql->fetchAll(PDO::FETCH_ASSOC);

            // Mapa dos nomes das formas
            $mapaFormas = [
                1 => ['nome' => 'Dinheiro', 'icone' => '💵'],
                4 => ['nome' => 'Crédito', 'icone' => '💳'],
                5 => ['nome' => 'Débito', 'icone' => '💳'],
                117 => ['nome' => 'PIX', 'icone' => '📱'],
            ];

            $formasFormatadas = array_map(function($f) use ($mapaFormas) {
                $cod = (int)$f['forma_pgto'];
                $info = $mapaFormas[$cod] ?? ['nome' => "Forma $cod", 'icone' => '❓'];
                return [
                    'codigo' => $cod,
                    'nome' => $info['nome'],
                    'icone' => $info['icone'],
                    'quantidade' => (int)$f['quantidade'],
                    'total' => (float)$f['total'],
                ];
            }, $porForma);

            // ============ 3. TOP 10 PRODUTOS ============
            $sql = $this->db->prepare("SELECT
                    vi.referencia,
                    p.descricao,
                    SUM(vi.quantidade) as quantidade,
                    SUM(vi.total_liquido) as total
                FROM vendas_item vi
                INNER JOIN vendas v ON v.empresa = vi.empresa AND v.venda = vi.venda
                LEFT JOIN produtos p ON p.empresa = vi.empresa AND p.referencia = vi.referencia
                WHERE v.empresa = :empresa
                AND v.status = 1
                AND vi.cancelado = 0
                $filtroData
                GROUP BY vi.referencia, p.descricao
                ORDER BY quantidade DESC
                LIMIT 10");
            $sql->bindValue(':empresa', $empresa);
            $sql->execute();
            $topProdutos = $sql->fetchAll(PDO::FETCH_ASSOC);

            $topFormatado = array_map(function($p) {
                return [
                    'referencia' => $p['referencia'],
                    'descricao' => $p['descricao'] ?? $p['referencia'],
                    'quantidade' => (float)$p['quantidade'],
                    'total' => (float)$p['total'],
                ];
            }, $topProdutos);

            // ============ 4. VENDAS POR DIA (para período > 1 dia) ============
            $porDia = [];
            if ($periodo === '7dias' || $periodo === 'mes' || $periodo === 'todos') {
                $sql = $this->db->prepare("SELECT
                        DATE(v.data) as dia,
                        COUNT(*) as quantidade,
                        COALESCE(SUM(v.total), 0) as total
                    FROM vendas v
                    WHERE v.empresa = :empresa
                    AND v.status = 1
                    $filtroData
                    GROUP BY DATE(v.data)
                    ORDER BY dia");
                $sql->bindValue(':empresa', $empresa);
                $sql->execute();
                $porDia = $sql->fetchAll(PDO::FETCH_ASSOC);

                $porDia = array_map(function($d) {
                    return [
                        'dia' => $d['dia'],
                        'quantidade' => (int)$d['quantidade'],
                        'total' => (float)$d['total'],
                    ];
                }, $porDia);
            }

            echo json_encode([
                'sucesso' => true,
                'periodo' => $periodo,
                'resumo' => [
                    'quantidade' => $quantidade,
                    'total' => $total,
                    'ticket_medio' => $ticketMedio,
                ],
                'por_forma' => $formasFormatadas,
                'top_produtos' => $topFormatado,
                'por_dia' => $porDia,
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }

        /**
    * Lista clientes (busca por nome/CPF/CNPJ)
    * POST /app/clientesListar
    * Body: { empresa, texto }
    */
    public function clientesListar() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;
        $texto = trim($dados->texto ?? '');

        try {
            // Se vazio, retorna os 30 primeiros clientes
            if ($texto === '') {
                $sql = $this->db->prepare("SELECT
                        c.codigo,
                        c.razao,
                        c.fantasia,
                        c.tipo,
                        c.cpf,
                        c.telefone,
                        c.celular,
                        c.endereco,
                        c.bairro,
                        c.cidade,
                        c.uf,
                        c.cep,
                        c.email
                    FROM clientes c
                    WHERE c.empresa = :empresa
                    AND c.cliente_fornecedor = 'C'
                    AND c.status = 1
                    ORDER BY c.razao
                    LIMIT 30");
            } else {
                $sql = $this->db->prepare("SELECT
                        c.codigo,
                        c.razao,
                        c.fantasia,
                        c.tipo,
                        c.cpf,
                        c.telefone,
                        c.celular,
                        c.endereco,
                        c.bairro,
                        c.cidade,
                        c.uf,
                        c.cep,
                        c.email
                    FROM clientes c
                    WHERE c.empresa = :empresa
                    AND c.cliente_fornecedor = 'C'
                    AND c.status = 1
                    AND (c.razao LIKE :texto
                        OR c.fantasia LIKE :texto
                        OR c.cpf LIKE :texto)
                    ORDER BY c.razao
                    LIMIT 30");
                $sql->bindValue(':texto', "%$texto%");
            }

            $sql->bindValue(':empresa', $empresa);
            $sql->execute();

            $clientes = $sql->fetchAll(PDO::FETCH_ASSOC);

            $resultado = array_map(function($c) {
                return [
                    'codigo' => (int)$c['codigo'],
                    'razao' => $c['razao'],
                    'fantasia' => $c['fantasia'] ?? $c['razao'],
                    'tipo' => (int)$c['tipo'],
                    'tipo_label' => $c['tipo'] == 2 ? 'Jurídica' : 'Física',
                    'cpf' => $c['cpf'] ?? '',
                    'telefone' => $c['telefone'] ?? $c['celular'] ?? '',
                    'endereco' => $c['endereco'] ?? '',
                    'bairro' => $c['bairro'] ?? '',
                    'cidade' => $c['cidade'] ?? '',
                    'uf' => $c['uf'] ?? '',
                    'cep' => $c['cep'] ?? '',
                    'email' => $c['email'] ?? '',
                ];
            }, $clientes);

            echo json_encode($resultado);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }

    /**
    * Cadastra novo cliente
    * POST /app/clienteCadastrar
    * Body: { empresa, razao, fantasia, tipo, cpf, telefone, endereco, bairro, cidade, uf, cep, email }
    */
    public function clienteCadastrar() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;
        $razao = trim($dados->razao ?? '');
        $fantasia = trim($dados->fantasia ?? '');
        $tipo = (int)($dados->tipo ?? 1);
        $cpf = preg_replace('/\D/', '', $dados->cpf ?? '');
        $telefone = trim($dados->telefone ?? '');
        $endereco = trim($dados->endereco ?? '');
        $endereco_nro = trim($dados->endereco_nro ?? '');   // ✅ ADICIONADO
        $bairro = trim($dados->bairro ?? '');
        $cidade = (int)($dados->cidade ?? 0);               // ✅ CAST para int (código IBGE)
        $uf = strtoupper(trim($dados->uf ?? ''));
        $email = trim($dados->email ?? '');

        // ✅ Formata o CEP (recebe 00000-000 ou só dígitos)
        $cepRaw = preg_replace('/\D/', '', $dados->cep ?? '');
        if (strlen($cepRaw) === 8) {
            $cepFormatado = substr($cepRaw, 0, 2) . '.' . substr($cepRaw, 2, 3) . '-' . substr($cepRaw, 5, 3);
        } else {
            $cepFormatado = trim($dados->cep ?? '');
        }

        if (empty($razao)) {
            http_response_code(400);
            echo json_encode(['erro' => 'Nome/Razão social é obrigatório']);
            return;
        }

        if (empty($cpf)) {
            http_response_code(400);
            echo json_encode(['erro' => 'CPF/CNPJ é obrigatório']);
            return;
        }

        try {
            // Verifica se já existe cliente com esse CPF
            $sql = $this->db->prepare("SELECT codigo, razao FROM clientes
                                        WHERE empresa = :empresa AND cpf = :cpf
                                        LIMIT 1");
            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':cpf', $cpf);
            $sql->execute();

            if ($sql->rowCount() > 0) {
                $existente = $sql->fetch(PDO::FETCH_ASSOC);
                http_response_code(409);
                echo json_encode([
                    'erro' => 'Já existe um cliente com este CPF/CNPJ',
                    'cliente_existente' => [
                        'codigo' => (int)$existente['codigo'],
                        'razao' => $existente['razao'],
                    ],
                ]);
                return;
            }

            // Gera próximo código
            $sql = $this->db->query("SELECT COALESCE(MAX(codigo), 0) + 1 AS proximo FROM clientes");
            $row = $sql->fetch(PDO::FETCH_ASSOC);
            $novoCodigo = (int)$row['proximo'];

            // Formata CPF/CNPJ
            if ($tipo == 2 && strlen($cpf) == 14) {
                $cpfFormatado = substr($cpf,0,2).'.'.substr($cpf,2,3).'.'.substr($cpf,5,3).'/'.substr($cpf,8,4).'-'.substr($cpf,12,2);
            } elseif (strlen($cpf) == 11) {
                $cpfFormatado = substr($cpf,0,3).'.'.substr($cpf,3,3).'.'.substr($cpf,6,3).'-'.substr($cpf,9,2);
            } else {
                $cpfFormatado = $cpf;
            }

                    // Insere
            $sql = $this->db->prepare("INSERT INTO clientes
                (empresa, codigo, razao, fantasia, tipo, cpf, telefone, endereco,
                endereco_nro, bairro, cidade, uf, cep, email, cliente_fornecedor, status,
                rg, data_cadastro, tipo_cliente_consumidor, ind_contribuinte_icms)
                VALUES
                (:empresa, :codigo, :razao, :fantasia, :tipo, :cpf, :telefone, :endereco,
                :endereco_nro, :bairro, :cidade, :uf, :cep, :email, 'C', 1,
                '', NOW(), 'Consumidor Final', 9)");

            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':codigo', $novoCodigo);
            $sql->bindValue(':razao', $razao);
            $sql->bindValue(':fantasia', $fantasia ?: $razao);
            $sql->bindValue(':tipo', $tipo);
            $sql->bindValue(':cpf', $cpfFormatado);
            $sql->bindValue(':telefone', $telefone);
            $sql->bindValue(':endereco', $endereco);
            $sql->bindValue(':endereco_nro', $endereco_nro);
            $sql->bindValue(':bairro', $bairro);
            $sql->bindValue(':cidade', $cidade);
            $sql->bindValue(':uf', $uf);
            $sql->bindValue(':cep', $cepFormatado);
            $sql->bindValue(':email', $email);
            $sql->execute();

            echo json_encode([
                'sucesso' => true,
                'cliente' => [
                    'codigo' => $novoCodigo,
                    'razao' => $razao,
                    'fantasia' => $fantasia ?: $razao,
                    'tipo' => $tipo,
                    'tipo_label' => $tipo == 2 ? 'Jurídica' : 'Física',
                    'cpf' => $cpfFormatado,
                    'telefone' => $telefone,
                    'endereco' => $endereco,
                    'endereco_nro' => $endereco_nro,
                    'bairro' => $bairro,
                    'cidade' => $cidade,
                    'uf' => $uf,
                    'cep' => $cepFormatado,   // ✅ era $cep, agora é $cepFormatado
                    'email' => $email,
                ],
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }


        /**
    * Busca municípios (para autocomplete de cidade)
    * POST /app/municipiosBuscar
    * Body: { texto }
    */
    public function municipiosBuscar() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $texto = trim($dados->texto ?? '');
        $uf = strtoupper(trim($dados->uf ?? ''));

        try {
            if ($texto === '') {
                echo json_encode([]);
                return;
            }

            if ($uf !== '') {
                $sql = $this->db->prepare("SELECT codigo, nome, uf
                                            FROM municipios
                                            WHERE (nome LIKE :texto OR codigo LIKE :texto)
                                            AND uf = :uf
                                            ORDER BY nome
                                            LIMIT 20");
                $sql->bindValue(':uf', $uf);
            } else {
                $sql = $this->db->prepare("SELECT codigo, nome, uf
                                            FROM municipios
                                            WHERE nome LIKE :texto OR codigo LIKE :texto
                                            ORDER BY nome
                                            LIMIT 20");
            }

            $sql->bindValue(':texto', "%$texto%");
            $sql->execute();

            $municipios = $sql->fetchAll(PDO::FETCH_ASSOC);

            $resultado = array_map(function($m) {
                return [
                    'codigo' => (int)$m['codigo'],
                    'nome' => $m['nome'],
                    'uf' => $m['uf'] ?? '',
                ];
            }, $municipios);

            echo json_encode($resultado);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }

     /**
     * Prepara NFC-e para emissão
     * POST /app/nfcePreparar
     * Body: { empresa, venda, tipo_operacao }
     */
    public function nfcePreparar() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;
        $venda = $dados->venda ?? 0;
        $tipoOperacao = $dados->tipo_operacao ?? 'APP';

            if (!$venda) {
                        // ===== DEBUG TEMPORÁRIO =====
                        echo json_encode([
                            'erro' => 'Venda obrigatória',
                            'debug_method' => $_SERVER['REQUEST_METHOD'] ?? '?',
                            'debug_content_type' => $_SERVER['CONTENT_TYPE'] ?? '?',
                            'debug_raw' => $json,
                            'debug_raw_len' => strlen($json ?? ''),
                            'debug_raw_hex' => bin2hex(substr($json ?? '', 0, 30)),
                            'debug_json_error' => json_last_error_msg(),
                            'debug_post' => $_POST,
                ]);
                return;
                // ============================
            }

        require_once __DIR__ . '/../models/NfceFiscal.php';

        try {
            $nfce = new NfceFiscal();
            $resultado = $nfce->prepararTudo($empresa, $venda, $tipoOperacao);
            echo json_encode($resultado);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }

    public function testeFiscal() {
        header('Content-Type: application/json; charset=utf-8');
        require_once __DIR__ . '/../models/Produto.php';
        
        $p = new Produto();
        $empresa = 1;
        $referencia = 61; // ⚠️ Troque por um produto real
        $codigoCfop = '5.102-1';
        
        $dados = $p->buscarDadosFiscais($empresa, $referencia, $codigoCfop);
        
        echo json_encode([
            'produto' => $dados['produto'] ?? null,
            'cfop' => $dados['cfop'] ?? null,
            'rtc_por_ncm' => $dados['produto'] ? $p->buscarRtcPorNcm($dados['produto']['ncm']) : null,
            'rtc_padrao' => $p->buscarRtcPadrao($empresa),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function diagMetodos() {
        header('Content-Type: application/json; charset=utf-8');

        require_once __DIR__ . '/../models/NfceFiscal.php';
        $nfce = new NfceFiscal();

        $metodos = get_class_methods($nfce);

        echo json_encode([
            'classe' => get_class($nfce),
            'arquivo_carregado' => (new ReflectionClass($nfce))->getFileName(),
            'total_metodos' => count($metodos),
            'tem_testarConexaoDb' => in_array('testarConexaoDb', $metodos),
            'metodos_publicos' => array_values(array_filter($metodos, function($m) use ($nfce) {
                return (new ReflectionMethod($nfce, $m))->isPublic();
            })),
        ], JSON_PRETTY_PRINT);
    }

        public function diagFiscal() {
        header('Content-Type: application/json; charset=utf-8');

        // limpa opcache por segurança
        if (function_exists('opcache_reset')) opcache_reset();

        $resultado = [
            'arquivo_existe' => file_exists(__DIR__ . '/../models/NfceFiscal.php'),
            'pasta_logs_existe' => is_dir(__DIR__ . '/../logs'),
            'logs_escrevivel' => is_writable(__DIR__ . '/../logs'),
        ];

        try {
            require_once __DIR__ . '/../models/NfceFiscal.php';
            $nfce = new NfceFiscal();
            $resultado['instancia_ok'] = true;

            $teste = $nfce->testarConexaoDb();
            $resultado['teste_interno'] = $teste;

        } catch (Throwable $t) {
            $resultado['erro'] = $t->getMessage();
            $resultado['arquivo'] = $t->getFile() . ':' . $t->getLine();
        }

        echo json_encode($resultado, JSON_PRETTY_PRINT);
    }

    /**
     * Emite NFC-e: prepara → gera TXT → transmite SEFAZ
     * POST /app/nfceEmitir
     * Body: { empresa, venda, tipo_operacao }
     */
    public function nfceEmitir() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa      = $dados->empresa ?? 1;
        $venda        = $dados->venda ?? 0;
        $tipoOperacao = $dados->tipo_operacao ?? 'FACILIT';
        $empresa      = $dados->empresa ?? 1;
        $venda        = $dados->venda ?? 0;
        $tipoOperacao = $dados->tipo_operacao ?? 'FACILIT';
        $cpfNfce      = isset($dados->cpf_nfce) ? preg_replace('/\D/', '', $dados->cpf_nfce) : '';

        if (!$venda) {
            http_response_code(400);
            echo json_encode(['erro' => 'Venda obrigatória']);
            return;
        }

        require_once __DIR__ . '/../models/NfceFiscal.php';
        require_once __DIR__ . '/../models/NfceGeradorTxt.php';
        require_once __DIR__ . '/../models/NfceTransmissor.php';

        try {
            // ✅ 1. Detecta status da SEFAZ antes de emitir
            $transmissor = new NfceTransmissor();
            $statusSefaz = $transmissor->detectarStatusSefaz();

            $contingencia = !$statusSefaz['online'];
            $tpEmisForcado = $contingencia ? 9 : null;

            if ($contingencia) {
                error_log("nfceEmitir: SEFAZ OFFLINE (cStat={$statusSefaz['cStat']} {$statusSefaz['xMotivo']}) — emitindo em CONTINGÊNCIA");
            } else {
                error_log("nfceEmitir: SEFAZ ONLINE (cStat={$statusSefaz['cStat']})");
            }

            // 2. Prepara tabelas fiscais (com tpEmis forçado se contingência)
            $fiscal = new NfceFiscal();
            $prep   = $fiscal->prepararTudo($empresa, $venda, $tipoOperacao, $tpEmisForcado, $cpfNfce);
            if (!$prep['sucesso']) {
                echo json_encode($prep);
                return;
            }
            $numeroNfce = $prep['numero_nfce'];

            // 3. Gera o TXT
            $gerador = new NfceGeradorTxt();
            $txt     = $gerador->gerar($empresa, $numeroNfce);
            if (!$txt['sucesso']) {
                echo json_encode($txt);
                return;
            }

            // 4. Transmite (ou só assina, se contingência)
            $transmissor = new NfceTransmissor();
            $trans = $transmissor->transmitir($empresa, $numeroNfce, $txt['caminho'], $contingencia);

            // 5. Monta resposta
            $resposta = [
                'sucesso'       => $trans['sucesso'],
                'numero_nfce'   => $numeroNfce,
                'serie'         => $trans['serie']     ?? 1,
                'chave'         => $trans['chave']     ?? null,
                'protocolo'     => $trans['protocolo'] ?? null,
                'cStat'         => $trans['cStat']     ?? null,
                'xMotivo'       => $trans['mensagem']  ?? null,
                'ambiente'      => $trans['ambiente']  ?? 2,
                'autorizada_em' => $trans['autorizada_em'] ?? null,
                'contingencia'  => $contingencia,
                'caminho_txt'   => $txt['caminho'],
                'status_sefaz'  => $statusSefaz,
                'transmissao'   => $trans,
                'nfce' => [
                    'sucesso'      => $trans['sucesso'],
                    'numero_nfce'  => $numeroNfce,
                    'chave'        => $trans['chave']     ?? null,
                    'protocolo'    => $trans['protocolo'] ?? null,
                    'cStat'        => $trans['cStat']     ?? null,
                    'mensagem'     => $trans['mensagem']  ?? null,
                    'contingencia' => $contingencia,
                ],
            ];

            echo json_encode($resposta);

        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'erro'    => $e->getMessage(),
                'arquivo' => $e->getFile(),
                'linha'   => $e->getLine(),
                'tipo'    => get_class($e),
            ]);
        }
    }

    /**
     * Reprocessa NFC-e emitidas em contingência (status_nfce = 8).
     * Chamado pelo Task Scheduler ou manualmente.
     * POST /app/nfceReprocessarContingencia
     * Body: { empresa, limite? }
     */
    public function nfceReprocessarContingencia() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;
        $limite  = (int)($dados->limite ?? 50);

        require_once __DIR__ . '/../models/NfceTransmissor.php';

        try {
            // Verifica status da SEFAZ antes de começar
            $transmissor = new NfceTransmissor();
            $statusSefaz = $transmissor->detectarStatusSefaz();

            if (!$statusSefaz['online']) {
                echo json_encode([
                    'sucesso'      => false,
                    'mensagem'     => "SEFAZ ainda offline ({$statusSefaz['cStat']} {$statusSefaz['xMotivo']}). Reprocessamento abortado.",
                    'status_sefaz' => $statusSefaz,
                ]);
                return;
            }

            // Busca NFC-e pendentes
            $sql = $this->db->prepare("SELECT numero_nfce, chave_acesso, xml_assinado
                                    FROM tab_nfce
                                    WHERE emp_codigo = :emp AND status_nfce = 8
                                    ORDER BY numero_nfce ASC
                                    LIMIT :lim");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':lim', $limite, PDO::PARAM_INT);
            $sql->execute();
            $pendentes = $sql->fetchAll(PDO::FETCH_ASSOC);

            if (empty($pendentes)) {
                echo json_encode([
                    'sucesso'      => true,
                    'total'        => 0,
                    'autorizadas'  => 0,
                    'rejeitadas'   => 0,
                    'mensagem'     => 'Nenhuma NFC-e em contingência pendente',
                    'status_sefaz' => $statusSefaz,
                ]);
                return;
            }

            $autorizadas = 0;
            $rejeitadas  = 0;
            $detalhes    = [];

            foreach ($pendentes as $p) {
                $num   = $p['numero_nfce'];
                $chave = $p['chave_acesso'];
                $xml   = $p['xml_assinado'];

                try {
                    // Reprocessa: chamar transmitir com o XML já assinado
                    // (não precisa regerar TXT nem assinar de novo)
                    $transmissor = new NfceTransmissor();
                    $resultado = $transmissor->transmitirXmlAssinado($empresa, $num, $xml);

                    if ($resultado['sucesso']) {
                        $autorizadas++;
                        $detalhes[] = ['numero' => $num, 'chave' => $chave, 'resultado' => 'AUTORIZADA'];
                    } else {
                        $rejeitadas++;
                        $detalhes[] = ['numero' => $num, 'chave' => $chave, 'resultado' => 'REJEITADA', 'motivo' => $resultado['mensagem']];
                    }
                } catch (Exception $e) {
                    $rejeitadas++;
                    $detalhes[] = ['numero' => $num, 'chave' => $chave, 'resultado' => 'ERRO', 'motivo' => $e->getMessage()];
                }
            }

            echo json_encode([
                'sucesso'      => true,
                'total'        => count($pendentes),
                'autorizadas'  => $autorizadas,
                'rejeitadas'   => $rejeitadas,
                'detalhes'     => $detalhes,
                'status_sefaz' => $statusSefaz,
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }

    /**
     * Consulta o status da SEFAZ — usado pelo frontend para mostrar alerta
     * POST /app/nfceStatusSefaz
     * Body: { empresa }
     */
    public function nfceStatusSefaz() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;

        require_once __DIR__ . '/../models/NfceTransmissor.php';

        try {
            $transmissor = new NfceTransmissor();
            $resultado = $transmissor->detectarStatusSefaz();

            // Conta quantas NFC-e em contingência estão pendentes
            $sql = $this->db->prepare("SELECT COUNT(*) FROM tab_nfce
                                    WHERE emp_codigo = :emp AND status_nfce = 8");
            $sql->bindValue(':emp', $empresa);
            $sql->execute();
            $pendentes = (int)$sql->fetchColumn();

            echo json_encode([
                'sucesso'    => true,
                'online'     => $resultado['online'],
                'cStat'      => $resultado['cStat'],
                'xMotivo'    => $resultado['xMotivo'],
                'tMed'       => $resultado['tMed'],
                'mensagem'   => $resultado['mensagem'],
                'contingencia_pendentes' => $pendentes,
                'consultado_em' => date('Y-m-d H:i:s'),
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }


    /**
     * Retorna todos os dados necessários para montar o cupom DANFCE.
     * POST /app/nfceDadosImpressao
     * Body: { empresa, numero_nfce }
     */
    public function nfceDadosImpressao() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa    = $dados->empresa     ?? 1;
        $numeroNfce = $dados->numero_nfce ?? 0;

        if (!$numeroNfce) {
            http_response_code(400);
            echo json_encode(['erro' => 'numero_nfce obrigatório']);
            return;
        }

        try {
            // 1. Cabeçalho
            $sql = $this->db->prepare("SELECT numero_nfce, status_nfce,
                                            chave_acesso, nro_protocolo_autorizacao,
                                            data_emissao, hora_emissao,
                                            xml_autorizado
                                    FROM tab_nfce
                                    WHERE emp_codigo = :emp AND numero_nfce = :num
                                    LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':num', $numeroNfce);
            $sql->execute();
            $nfce = $sql->fetch(PDO::FETCH_ASSOC);

            if (!$nfce) {
                http_response_code(404);
                echo json_encode(['erro' => 'NFC-e não encontrada']);
                return;
            }

            // 2. Emitente
            $sql = $this->db->prepare("SELECT emi_xNome, emi_xFant, emi_cnpj, emi_ie, emi_iest,
                                            emi_xLgr, emi_nro, emi_xBairro,
                                            emi_xMun, emi_uf, emi_cep, emi_fone
                                    FROM nfce_emitente
                                    WHERE emp_codigo = :emp AND numero_nfce = :num LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':num', $numeroNfce);
            $sql->execute();
            $emitente = $sql->fetch(PDO::FETCH_ASSOC);

            // 3. Destinatário
            $sql = $this->db->prepare("SELECT dest_xNome, dest_cpf, dest_cnpj,
                                            dest_xLgr, dest_nro, dest_xBairro,
                                            dest_xMun, dest_uf, dest_cep
                                    FROM nfce_destinatario
                                    WHERE emp_codigo = :emp AND numero_nfce = :num LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':num', $numeroNfce);
            $sql->execute();
            $dest = $sql->fetch(PDO::FETCH_ASSOC) ?: null;

            // 4. Itens
            $sql = $this->db->prepare("SELECT prod_item, prod_cprod, prod_xProd,
                                            prod_qcom, prod_uCom, prod_vUnCom, prod_vProd,
                                            prod_vDesc
                                    FROM nfce_produtos
                                    WHERE emp_codigo = :emp AND numero_nfce = :num
                                    ORDER BY prod_item ASC");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':num', $numeroNfce);
            $sql->execute();
            $itens = $sql->fetchAll(PDO::FETCH_ASSOC);

            // 5. Formas de pagamento
            $sql = $this->db->prepare("SELECT tot_fp_codigo, tot_fp_descricao,
                                            tot_fp_valor, tot_fp_vTroco
                                    FROM nfce_totais_formapgto
                                    WHERE emp_codigo = :emp AND numero_nfce = :num
                                    ORDER BY tot_fp_codigo ASC");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':num', $numeroNfce);
            $sql->execute();
            $formas = $sql->fetchAll(PDO::FETCH_ASSOC);

            // 6. Totais
            $sql = $this->db->prepare("SELECT tot_icms_vProd, tot_icms_Desc, tot_icms_vNF,
                                            tot_icms_vFrete, tot_icms_vSeg, tot_icms_vOutro
                                    FROM nfce_totais
                                    WHERE emp_codigo = :emp AND numero_nfce = :num LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':num', $numeroNfce);
            $sql->execute();
            $totais = $sql->fetch(PDO::FETCH_ASSOC);

            // 7. Info complementares
            $sql = $this->db->prepare("SELECT inf_infcpl_contribuinte FROM nfce_informacoescpl
                                    WHERE emp_codigo = :emp AND numero_nfce = :num LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':num', $numeroNfce);
            $sql->execute();
            $infoCpl = $sql->fetchColumn() ?: '';

            // 8. Ambiente
            $sql = $this->db->prepare("SELECT pnfce_ambiente FROM param_nfce WHERE emp_codigo = :emp LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->execute();
            $ambiente = (int)($sql->fetchColumn() ?: 2);

            // 9. QR Code
            $qrCodeUrl = $this->montarUrlQrCode($nfce, $emitente, $ambiente);

            // 10. Contingência
            $contingencia = (strpos($nfce['xml_autorizado'] ?? '', '<tpEmis>9</tpEmis>') !== false);

            // ✅ Sanitiza strings antes de montar o JSON
            $sanitize = function($arr) {
            if (!is_array($arr)) return $arr;
            $out = [];
            foreach ($arr as $k => $v) {
                if (is_string($v)) {
                    // Converte de latin1 para UTF-8 (o banco é latin1)
                    $v = @mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
                    if ($v === false) $v = '';
                    // Remove bytes inválidos residuais (segurança)
                    $v = @iconv('UTF-8', 'UTF-8//IGNORE', $v);
                }
                $out[$k] = $v;
            }
            return $out;
        };

            $emitente = $sanitize($emitente);
            $dest     = $sanitize($dest);
            $itens    = array_map($sanitize, $itens);
            $formas   = array_map($sanitize, $formas);
            $infoCpl  = @iconv('UTF-8', 'UTF-8//IGNORE', $infoCpl);

            $resposta = [
                'sucesso' => true,
                'nfce' => [
                    'numero'            => (int)$nfce['numero_nfce'],
                    'serie'             => 1,
                    'chave'             => $nfce['chave_acesso'],
                    'protocolo'         => $nfce['nro_protocolo_autorizacao'],
                    'data_emissao'      => $nfce['data_emissao'],
                    'hora_emissao'      => $nfce['hora_emissao'],
                    'data_autorizacao'  => $nfce['data_emissao'] . ' ' . $nfce['hora_emissao'],
                    'ambiente'          => $ambiente,
                    'status_nfce'       => (int)$nfce['status_nfce'],
                    'contingencia'      => $contingencia,
                ],
                'emitente' => $emitente,
                'destinatario' => $dest,
                'itens' => $itens,
                'formas_pagamento' => $formas,
                'totais' => $totais,
                'informacoes_complementares' => $infoCpl,
                'qr_code_url' => $qrCodeUrl,
            ];

            $jsonFinal = json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($jsonFinal === false) {
                error_log("json_encode falhou: " . json_last_error_msg());
            }
            echo $jsonFinal;

            } catch (Throwable $t) {
                error_log("nfceDadosImpressao FATAL: " . $t->getMessage() . " em " . $t->getFile() . ":" . $t->getLine());
                http_response_code(500);
                echo json_encode([
                    'erro' => 'Erro: ' . $t->getMessage(),
                    'arquivo' => $t->getFile(),
                    'linha' => $t->getLine(),
                ]);
            }

         
    }
    /**
     * Monta a URL do QR Code seguindo o padrão NFC-e 4.00.
     * Formato: https://www.sefaz.mt.gov.br/nfce/consultanfce?p=<chave>|2|<versao_qr>|<tpAmb>|<cIdToken>|<hash>
     * (URL simplificada — o cliente também pode consultar por chave direto)
     */
    private function montarUrlQrCode($nfce, $emitente, $ambiente) {
        if (empty($nfce['chave_acesso'])) return '';

        // URL padrão MT (consulta por chave). Idealmente incluir CSC + hash.
        // Por ora, usamos a URL de consulta por chave.
        $chave = $nfce['chave_acesso'];
        $urlBase = ($ambiente === 2)
            ? 'https://homologacao.sefaz.mt.gov.br/nfce/consultanfce'
            : 'https://www.sefaz.mt.gov.br/nfce/consultanfce';

        return $urlBase . '?chave=' . $chave;
    }
   
    //Listar NFC-es
   public function nfceListar() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa  = $dados->empresa  ?? 1;
        $dataIni  = $dados->data_ini ?? date('Y-m-d', strtotime('-7 days'));
        $dataFim  = $dados->data_fim ?? date('Y-m-d');
        $status   = $dados->status   ?? null;
        $limit    = min((int)($dados->limit ?? 100), 500);

        try {
            $where  = "WHERE t.emp_codigo = :emp AND t.data_emissao BETWEEN :di AND :df";
            $params = [':emp' => $empresa, ':di' => $dataIni, ':df' => $dataFim];

            if ($status !== null && $status !== '') {
                $where .= " AND t.status_nfce = :status";
                $params[':status'] = $status;
            }

            $sql = $this->db->prepare("
                SELECT
                    t.numero_nfce,
                    t.status_nfce,
                    t.chave_acesso,
                    t.nro_protocolo_autorizacao,
                    t.data_emissao,
                    t.hora_emissao,
                    COALESCE(tot.tot_icms_vNF, 0) AS total,
                    COALESCE(v.cliente, 1) AS codigocliente,
                    COALESCE(c.razao, 'CONSUMIDOR FINAL') AS nome
                FROM tab_nfce t
                LEFT JOIN nfce_totais tot
                    ON tot.emp_codigo = t.emp_codigo
                AND tot.numero_nfce = t.numero_nfce
                LEFT JOIN vendas v
                    ON v.empresa = t.emp_codigo
                AND v.numero_nfce = t.numero_nfce
                LEFT JOIN clientes c
                    ON c.empresa = v.empresa
                AND c.codigo = v.cliente
                LEFT JOIN nfce_identificacao ni
                    ON ni.emp_codigo = t.emp_codigo
                AND ni.numero_nfce = t.numero_nfce
                $where
                ORDER BY t.numero_nfce DESC
                LIMIT $limit
            ");

            foreach ($params as $k => $v) $sql->bindValue($k, $v);
            $sql->execute();
            $lista = $sql->fetchAll(PDO::FETCH_ASSOC);

            $statusMap = [
                0 => 'Digitada', 1 => 'Validada', 2 => 'Processamento',
                3 => 'Autorizado', 4 => 'Rejeitado', 5 => 'Denegada',
                6 => 'Cancelada', 7 => 'Inutilizada', 8 => 'Contingência',
            ];

            $resposta = array_map(function($n) use ($statusMap) {
                return [
                    'numero'       => (int)$n['numero_nfce'],
                    'serie'        => (int)($n['ide_serie_documento'] ?? 1),
                    'status'       => (int)$n['status_nfce'],
                    'status_label' => $statusMap[(int)$n['status_nfce']] ?? '?',
                    'chave'        => $n['chave_acesso'],
                    'protocolo'    => $n['nro_protocolo_autorizacao'],
                    'data'         => $n['data_emissao'],
                    'hora'         => $n['hora_emissao'],
                    'total'        => (float)$n['total'],
                    'cliente'      => (int)$n['codigocliente'],
                    'cliente_nome' => $n['nome'],
                ];
            }, $lista);

            echo json_encode(['sucesso' => true, 'total' => count($resposta), 'nfce' => $resposta], JSON_UNESCAPED_UNICODE);

        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => $t->getMessage()]);
        }
    }

    /**
     * Cancela NFC-e autorizada.
     * POST /app/nfceCancelar
     * Body: { empresa, numero_nfce, justificativa, usuario }
     */
    public function nfceCancelar() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa       = $dados->empresa       ?? 1;
        $numeroNfce    = $dados->numero_nfce   ?? 0;
        $justificativa = $dados->justificativa ?? '';
        $usuario       = $dados->usuario       ?? '';

        if (!$numeroNfce) {
            http_response_code(400);
            echo json_encode(['erro' => 'numero_nfce obrigatório']);
            return;
        }

        require_once __DIR__ . '/../models/NfceTransmissor.php';

        try {
            $transmissor = new NfceTransmissor();
            $resultado = $transmissor->cancelar($empresa, $numeroNfce, $justificativa, $usuario);
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $t->getMessage()]);
        }
    }

    /**
     * Inutiliza faixa de numeração.
     * POST /app/nfceInutilizar
     * Body: { empresa, numero_ini, numero_fim, serie?, justificativa }
     */
    public function nfceInutilizar() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa       = $dados->empresa       ?? 1;
        $numeroIni     = $dados->numero_ini    ?? 0;
        $numeroFim     = $dados->numero_fim    ?? 0;
        $serie         = $dados->serie         ?? 1;
        $justificativa = $dados->justificativa ?? '';

        if (!$numeroIni || !$numeroFim) {
            http_response_code(400);
            echo json_encode(['erro' => 'numero_ini e numero_fim obrigatórios']);
            return;
        }

        require_once __DIR__ . '/../models/NfceTransmissor.php';

        try {
            $transmissor = new NfceTransmissor();
            $resultado = $transmissor->inutilizar($empresa, $numeroIni, $numeroFim, $justificativa, $serie);
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $t->getMessage()]);
        }
    }

    /**
     * Diagnóstico de rejeição — traduz cStat em dica.
     * POST /app/nfceDiagnostico
     * Body: { empresa, numero_nfce }
     */
    public function nfceDiagnostico() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa    = $dados->empresa     ?? 1;
        $numeroNfce = $dados->numero_nfce ?? 0;

        try {
            // Busca o retorno da SEFAZ
            $sql = $this->db->prepare("SELECT xml_autorizado_retorno, status_nfce
                                    FROM tab_nfce
                                    WHERE emp_codigo = :emp AND numero_nfce = :num LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':num', $numeroNfce);
            $sql->execute();
            $nfce = $sql->fetch(PDO::FETCH_ASSOC);

            if (!$nfce) {
                echo json_encode(['erro' => 'NFC-e não encontrada']);
                return;
            }

            $cStat   = '';
            $xMotivo = '';
            $xml = $nfce['xml_autorizado_retorno'] ?? '';
            if (preg_match('/<cStat>(\d+)<\/cStat>/', $xml, $m))       $cStat   = $m[1];
            if (preg_match('/<xMotivo>([^<]*)<\/xMotivo>/', $xml, $m)) $xMotivo = $m[1];

            // Mapa cStat → dica
            $diagnosticos = [
                '539'  => ['categoria' => 'Duplicidade',    'dica' => 'Esta NFC-e já foi autorizada antes. Verifique a lista de notas autorizadas.', 'acao' => 'nenhuma'],
                '204'  => ['categoria' => 'Duplicidade',    'dica' => 'Número já utilizado. Gere uma nova venda.', 'acao' => 'inutilizar'],
                '215'  => ['categoria' => 'Schema XML',     'dica' => 'Falha no schema. Verifique formato de valores ou campos faltantes.', 'acao' => 'corrigir'],
                '402'  => ['categoria' => 'Encoding',       'dica' => 'Problema de codificação. Verifique acentuação dos dados.', 'acao' => 'corrigir'],
                '716'  => ['categoria' => 'Consumidor Final','dica' => 'NFC-e deve ser destinada a consumidor final (indFinal=1).', 'acao' => 'corrigir'],
                '1026' => ['categoria' => 'Reforma Trib.',  'dica' => 'Alíquota IBS UF incorreta. Verifique o cadastro fiscal do produto/NCM.', 'acao' => 'cadastrar_ncm'],
                '1027' => ['categoria' => 'Reforma Trib.',  'dica' => 'Alíquota CBS incorreta. Verifique o cadastro fiscal do produto/NCM.', 'acao' => 'cadastrar_ncm'],
                '778'  => ['categoria' => 'Cadastro',       'dica' => 'NCM do produto não cadastrado em dfe_ncm_nbs_ibs_cbs.', 'acao' => 'cadastrar_ncm'],
                '999'  => ['categoria' => 'Erro genérico',  'dica' => 'Erro não mapeado. Consulte a SEFAZ com a chave de acesso.', 'acao' => 'consultar'],
            ];

            $diag = $diagnosticos[$cStat] ?? ['categoria' => 'Não mapeado', 'dica' => 'Motivo não mapeado. Verifique o xMotivo na SEFAZ.', 'acao' => 'consultar'];

            echo json_encode([
                'sucesso'   => true,
                'numero'    => (int)$numeroNfce,
                'status'    => (int)$nfce['status_nfce'],
                'cStat'     => $cStat,
                'xMotivo'   => $xMotivo,
                'categoria' => $diag['categoria'],
                'dica'      => $diag['dica'],
                'acao'      => $diag['acao'],
            ], JSON_UNESCAPED_UNICODE);

        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => $t->getMessage()]);
        }
    }

    /**
     * Diagnóstico de produtos com problema cadastral.
     * POST /app/diagnosticoProdutos
     * Body: { empresa, dias_atras? (default 90) }
     */
    public function diagnosticoProdutos() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa   = $dados->empresa    ?? 1;
        $diasAtras = (int)($dados->dias_atras ?? 90);
        if ($diasAtras <= 0) $diasAtras = 90;

        try {
            // Subquery base: produtos com venda nos últimos N dias
            $subRecent = "
                SELECT DISTINCT vi.referencia
                FROM vendas_item vi
                INNER JOIN vendas v ON v.empresa = vi.empresa AND v.venda = vi.venda
                WHERE v.empresa = :emp_sub AND v.data >= DATE_SUB(CURDATE(), INTERVAL $diasAtras DAY)
            ";

            // ============ 1. SEM NCM ============
            $sql = $this->db->prepare("
                SELECT p.referencia, p.descricao, p.ncm, p.cfop
                FROM produtos p
                INNER JOIN ($subRecent) rec ON rec.referencia = p.referencia
                WHERE p.empresa = :emp AND p.status = 1
                AND (p.ncm IS NULL OR TRIM(p.ncm) = '')
                AND p.cfop IS NOT NULL AND TRIM(p.cfop) <> ''
                ORDER BY p.descricao
            ");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':emp_sub', $empresa);
            $sql->execute();
            $semNcm = $sql->fetchAll(PDO::FETCH_ASSOC);

            // ============ 2. SEM CFOP ============
           $sql = $this->db->prepare("
                SELECT p.referencia, p.descricao, p.ncm, p.cfop
                FROM produtos p
                INNER JOIN ($subRecent) rec ON rec.referencia = p.referencia
                WHERE p.empresa = :emp AND p.status = 1
                AND (p.cfop IS NULL OR TRIM(p.cfop) = '')
                AND p.ncm IS NOT NULL AND TRIM(p.ncm) <> ''
                ORDER BY p.descricao
            ");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':emp_sub', $empresa);
            $sql->execute();
            $semCfop = $sql->fetchAll(PDO::FETCH_ASSOC);

            // ============ 3. CFOP INVÁLIDO (não existe em tab_cfop) ============
            $sql = $this->db->prepare("
                SELECT p.referencia, p.descricao, p.ncm, p.cfop
                FROM produtos p
                INNER JOIN ($subRecent) rec ON rec.referencia = p.referencia
                WHERE p.empresa = :emp AND p.status = 1
                AND p.cfop IS NOT NULL AND TRIM(p.cfop) <> ''
                AND NOT EXISTS (
                    SELECT 1 FROM tab_cfop t
                    WHERE t.empresa = p.empresa
                        AND t.cfop_codigo = SUBSTRING_INDEX(p.cfop, '-', 1)
                        AND t.cfop_indice = SUBSTRING_INDEX(p.cfop, '-', -1)
                )
                ORDER BY p.descricao
            ");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':emp_sub', $empresa);
            $sql->execute();
            $cfopInvalido = $sql->fetchAll(PDO::FETCH_ASSOC);

            // ============ 4. SEM NCM E SEM CFOP (crítico) ============
            $sql = $this->db->prepare("
                SELECT p.referencia, p.descricao
                FROM produtos p
                INNER JOIN ($subRecent) rec ON rec.referencia = p.referencia
                WHERE p.empresa = :emp AND p.status = 1
                AND (p.ncm IS NULL OR TRIM(p.ncm) = '')
                AND (p.cfop IS NULL OR TRIM(p.cfop) = '')
                ORDER BY p.descricao
            ");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':emp_sub', $empresa);
            $sql->execute();
            $semAmbos = $sql->fetchAll(PDO::FETCH_ASSOC);

            // ============ 5. CFOPs inválidos agrupados (para diagnóstico rápido) ============
            $cfopsUnicos = [];
            foreach ($cfopInvalido as $p) {
                $cfop = $p['cfop'];
                if (!isset($cfopsUnicos[$cfop])) {
                    $cfopsUnicos[$cfop] = ['cfop' => $cfop, 'qtd_produtos' => 0];
                }
                $cfopsUnicos[$cfop]['qtd_produtos']++;
            }
            $cfopsUnicos = array_values($cfopsUnicos);

            echo json_encode([
                'sucesso' => true,
                'dias_atras' => $diasAtras,
                'resumo' => [
                    'sem_ncm'         => count($semNcm),
                    'sem_cfop'        => count($semCfop),
                    'cfop_invalido'   => count($cfopInvalido),
                    'sem_ambos'       => count($semAmbos),
                ],
                'sem_ncm'       => $semNcm,
                'sem_cfop'      => $semCfop,
                'cfop_invalido' => $cfopInvalido,
                'cfops_invalidos_unicos' => $cfopsUnicos,
                'sem_ambos'     => $semAmbos,
            ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => $t->getMessage()]);
        }
    }

    /**
     * Reprocessa rejeitadas do mês atual + inutiliza anteriores (se parametrizado).
     * POST /app/nfceReprocessarRejeitadas
     * Body: { empresa, forcar_inutilizacao? }
     */
    public function nfceReprocessarRejeitadas() {

        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa  = $dados->empresa              ?? 1;
        $forcar   = (bool)($dados->forcar_inutilizacao ?? false);

        require_once __DIR__ . '/../models/NfceTransmissor.php';

        try {
            $transmissor = new NfceTransmissor();
            $resultado = $transmissor->reprocessarRejeitadas($empresa, $forcar);
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $t->getMessage()]);
        }
    }

    /**
     * Lê os logs dos jobs e retorna o status + histórico.
     * POST /app/jobStatus
     * Body: { empresa, dias? (default 7) }
     */
    public function jobStatus() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);
        $dias = (int)($dados->dias ?? 7);
        if ($dias < 1 || $dias > 60) $dias = 7;

        $pastaLog = 'C:\\WDS Comercial\\NFCeGerada\\Log\\';
        $jobs = [
            'contingencia' => ['label' => 'Reprocessar Contingência', 'prefix' => 'contingencia_'],
            'rejeitadas'   => ['label' => 'Reprocessar Rejeitadas + Inutilização', 'prefix' => 'rejeitadas_'],
        ];

        $resultado = ['sucesso' => true, 'dias' => $dias, 'jobs' => []];

        foreach ($jobs as $chave => $info) {
            $historico = [];

            for ($i = 0; $i < $dias; $i++) {
                $data = date('Ymd', strtotime("-$i days"));
                $arquivo = $pastaLog . $info['prefix'] . $data . '.log';

                if (!file_exists($arquivo)) {
                    $historico[] = [
                        'data'      => date('Y-m-d', strtotime("-$i days")),
                        'executado' => false,
                    ];
                    continue;
                }

                $conteudo = @file_get_contents($arquivo);
                $json = @json_decode($conteudo, true);

                $historico[] = [
                    'data'      => date('Y-m-d', strtotime("-$i days")),
                    'executado' => true,
                    'tamanho'   => strlen($conteudo),
                    'sucesso'   => $json['sucesso'] ?? null,
                    'resumo'    => $this->resumirJob($chave, $json),
                    'arquivo'   => $arquivo,
                ];
            }

            $resultado['jobs'][$chave] = [
                'label'     => $info['label'],
                'ultima'    => $historico[0] ?? null,
                'historico' => $historico,
            ];
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Resume o JSON de retorno de cada job em 1 linha legível.
     */
    private function resumirJob($chave, $json) {
        if (!$json || !is_array($json)) return 'Sem dados';

        if ($chave === 'contingencia') {
            $total = $json['total'] ?? 0;
            $aut   = $json['autorizadas'] ?? 0;
            $rej   = $json['rejeitadas'] ?? 0;
            return "$total total • $aut autorizadas • $rej rejeitadas";
        }

        if ($chave === 'rejeitadas') {
            $r = $json['retransmitidas'] ?? [];
            $i = $json['inutilizadas'] ?? [];
            return sprintf(
                'Retransmitidas: %d (%d OK, %d rej) • Inutilizadas: %d (%d OK, %d falhas)',
                $r['total'] ?? 0,
                $r['autorizadas'] ?? 0,
                $r['ainda_rejeitadas'] ?? 0,
                $i['total'] ?? 0,
                $i['sucesso'] ?? 0,
                $i['falhas'] ?? 0
            );
        }

        return 'OK';
    }

    /**
     * Cancela uma venda aberta (mesa aberta).
     * POST /app/vendaCancelar
     * Body: { empresa, venda, usuario? }
     */
    public function vendaCancelar() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);

        $empresa = $dados->empresa ?? 1;
        $venda   = $dados->venda   ?? 0;
        $usuario = $dados->usuario ?? 1;

        if (!$venda) {
            http_response_code(400);
            echo json_encode(['erro' => 'Venda obrigatória']);
            return;
        }

        try {
            $this->db->beginTransaction();

            // 🔒 Lock
            $lock = $this->db->prepare("SELECT venda, status FROM vendas
                WHERE empresa = :empresa AND venda = :venda FOR UPDATE");
            $lock->bindValue(':empresa', $empresa);
            $lock->bindValue(':venda', $venda);
            $lock->execute();
            $vendaDados = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$vendaDados) {
                $this->db->rollBack();
                http_response_code(404);
                echo json_encode(['erro' => 'Venda não encontrada']);
                return;
            }

            if ((int)$vendaDados['status'] !== 0) {
                $this->db->rollBack();
                http_response_code(409);
                echo json_encode([
                    'erro' => 'Esta venda não está mais aberta',
                    'status' => (int)$vendaDados['status'],
                ]);
                return;
            }

            // ✅ 1. Estorna estoque de todos os itens NÃO cancelados
            $v = new Vendas();
            $itensEstornados = 0;
            try {
                $itensEstornados = $v->estornarEstoqueVenda($empresa, $venda, $usuario);
            } catch (Throwable $e) {
                // Loga mas continua — não trava o cancelamento por causa de estoque
                error_log("vendaCancelar: erro ao estornar estoque da venda $venda: " . $e->getMessage());
            }

            // ✅ 2. Marca itens como cancelados (preserva histórico)
            $sql = $this->db->prepare("UPDATE vendas_item SET cancelado = 1
                WHERE empresa = :empresa AND venda = :venda");
            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':venda', $venda);
            $sql->execute();
            $itensCancelados = $sql->rowCount();

            // ✅ 3. Cancela a venda
            $sql = $this->db->prepare("UPDATE vendas SET status = 2
                WHERE empresa = :empresa AND venda = :venda AND status = 0");
            $sql->bindValue(':empresa', $empresa);
            $sql->bindValue(':venda', $venda);
            $sql->execute();

            if ($sql->rowCount() === 0) {
                $this->db->rollBack();
                http_response_code(409);
                echo json_encode(['erro' => 'Venda já foi cancelada por outro usuário']);
                return;
            }

            $this->db->commit();

            echo json_encode([
                'sucesso'           => true,
                'venda'             => (int)$venda,
                'itens_cancelados'  => $itensCancelados,
                'itens_estornados'  => $itensEstornados,
                'mensagem'          => 'Mesa cancelada com sucesso',
            ]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['erro' => 'Erro: ' . $e->getMessage()]);
        }
    }
    
    //Verifica se o tef está ativo
    public function verificarTef() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);
        $empresa = $dados->empresa ?? 1;

        try {
            $sql = $this->db->prepare("SELECT usa_tef FROM param_sistema WHERE empresa = :emp LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->execute();
            $usaTef = strtoupper(trim($sql->fetchColumn() ?: 'N'));

            echo json_encode([
                'sucesso'  => true,
                'usa_tef'  => ($usaTef === 'S'),
            ]);
        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => $t->getMessage()]);
        }
    }

    /**
     * Retorna os parâmetros TEF para o frontend.
     * POST /app/tefParametros
     * Body: { empresa }
     */
    public function tefParametros() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $dados = json_decode($json);
        $empresa = $dados->empresa ?? 1;

        try {
            $sql = $this->db->prepare("SELECT * FROM param_tef WHERE empresa = :emp LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->execute();
            $param = $sql->fetch(PDO::FETCH_ASSOC);

            if (!$param) {
                echo json_encode(['sucesso' => false, 'erro' => 'param_tef não configurado']);
                return;
            }

            // Normaliza chaves pra minúsculo (case PDO)
            $norm = [];
            foreach ($param as $k => $v) $norm[strtolower($k)] = $v;

            echo json_encode([
                'sucesso'            => true,
                'sitef_ip'           => $norm['sitef_ip']          ?? '127.0.0.1',
                'store_id'           => $norm['store_id']          ?? '00000000',
                'terminal_id'        => $norm['terminal_id']       ?? 'REST0001',
                'max_cartoes'        => (int)($norm['max_cartoes'] ?? 1),
                'multiplos_cartoes'  => ($norm['multiplos_cartoes'] ?? 'N') === 'S',
                'via_reduzida'       => ($norm['via_reduzida'] ?? 'N') === 'S',
                'troco_maximo'       => (float)($norm['troco_maximo'] ?? 0),
                'cnpj_adquirente_pix'=> $norm['cnpj_adquirente_pix'] ?? '',
                'agent_url'          => 'https://127.0.0.1:8443/agente/clisitef',
            ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => $t->getMessage()]);
        }
    }

        /**
     * Grava uma transação TEF em vendas_tef.
     * Espelha fGravarVendasTef do VB6.
     * PK real: (empresa, venda, parcela, codigo, ordem)
     * Compatível com MySQL 4.1 (sem ON DUPLICATE, sem CTE).
     * POST /app/gravarVendasTef
     */
    public function gravarVendasTef() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $d = json_decode($json);

        $empresa        = isset($d->empresa)          ? $d->empresa          : 1;
        $venda          = isset($d->venda)            ? $d->venda            : 0;
        $parcela        = isset($d->parcela)          ? $d->parcela          : 0;
        $ordem          = isset($d->ordem)            ? $d->ordem            : 1;
        $codigo         = $venda;
        $formaPagto     = isset($d->forma_pgto)       ? $d->forma_pgto       : 0;

        if ((int)$formaPagto === 10) {
            $formaPagto = 17;
        }

        $credenciadora  = isset($d->credenciadora)    ? $d->credenciadora    : '';
        $bandeira       = isset($d->bandeira)         ? $d->bandeira         : '';
        $codigoTrans    = isset($d->codigo_transacao) ? $d->codigo_transacao : '';
        $valor          = isset($d->valor)            ? (float)$d->valor     : 0.0;
        $troco          = isset($d->troco)            ? (float)$d->troco     : 0.0;
        $tipo           = 1;
        $nsu            = isset($d->nsu)              ? $d->nsu              : '';
        $dataC          = isset($d->data_c)           ? $d->data_c           : '';
        $horaC          = isset($d->hora_c)           ? $d->hora_c           : '';
        $tipoFinan      = isset($d->tipo_finan)       ? $d->tipo_finan       : '';
        $modalidade     = isset($d->modalidade)       ? $d->modalidade       : '';
        $rede           = isset($d->rede_adquirente)  ? $d->rede_adquirente  : '';
        $qtdParcelas    = isset($d->qtd_parcelas)     ? (int)$d->qtd_parcelas : 1;
        $cancelado      = isset($d->cancelado)        ? (int)$d->cancelado   : 0;
        $idTerminal     = isset($d->id_terminal)      ? $d->id_terminal      : '';
        $codigoControle = isset($d->codigo_controle)  ? $d->codigo_controle  : '';

        if (!$venda) {
            http_response_code(400);
            echo json_encode(array('erro' => 'venda obrigatória'));
            return;
        }

        // codigo faz parte da PK (varchar 20). Nunca pode ser vazio na PK.
        if ($codigo === '' || $codigo === null) {
            $codigo = substr((string)$nsu, 0, 20);
        }
        if ($codigo === '') {
            $codigo = substr(uniqid('tef', true), 0, 20);
        }

        try {
            // 1) Existe linha com a PK EXATA? (5 colunas)
            $sel = $this->db->prepare("SELECT COUNT(*) AS n FROM vendas_tef
                WHERE empresa = :emp AND venda = :vend AND parcela = :parc
                  AND codigo = :cod AND ordem = :ordem");
            $sel->bindValue(':emp',   $empresa);
            $sel->bindValue(':vend',  $venda);
            $sel->bindValue(':parc',  $parcela);
            $sel->bindValue(':cod',   $codigo);
            $sel->bindValue(':ordem', $ordem);
            $sel->execute();
            $row  = $sel->fetch(PDO::FETCH_ASSOC);
            $existe = $row && (int)$row['n'] > 0;

            // 2) Antes de inserir, cancela qualquer OUTRA linha ativa
            //    no mesmo slot (emp, venda, ordem) — cartão trocado.
            if (!$existe) {
                $upd = $this->db->prepare("UPDATE vendas_tef
                    SET cancelado = 1
                    WHERE empresa = :emp AND venda = :vend
                      AND ordem = :ordem AND cancelado = 0");
                $upd->bindValue(':emp',   $empresa);
                $upd->bindValue(':vend',  $venda);
                $upd->bindValue(':ordem', $ordem);
                $upd->execute();
            }

            if ($existe) {
                $sql = $this->db->prepare("UPDATE vendas_tef SET
                    forma_pagto       = :forma,
                    credenciadora    = :cred,
                    bandeira_cartao  = :band,
                    codigo_transacao = :codtrans,
                    valor            = :valor,
                    troco            = :troco,
                    tipo             = :tipo,
                    NSU              = :nsu,
                    data_c           = :dataC,
                    hora_c           = :horaC,
                    tipo_finan       = :tipofin,
                    modalidade       = :mod,
                    rede_adquirente  = :rede,
                    qtd_parcelas     = :qtdp,
                    cancelado        = :cancel,
                    id_terminal      = :idterm,
                    codigo_controle  = :ctrl
                    WHERE empresa = :emp AND venda = :vend
                      AND parcela = :parc AND codigo = :cod AND ordem = :ordem");

                $sql->bindValue(':forma',    $formaPagto);
                $sql->bindValue(':cred',     $credenciadora);
                $sql->bindValue(':band',     $bandeira);
                $sql->bindValue(':codtrans', $codigoTrans);
                $sql->bindValue(':valor',    $valor);
                $sql->bindValue(':troco',    $troco);
                $sql->bindValue(':tipo',     $tipo);
                $sql->bindValue(':nsu',      $nsu);
                $sql->bindValue(':dataC',    $dataC);
                $sql->bindValue(':horaC',    $horaC);
                $sql->bindValue(':tipofin',  $tipoFinan);
                $sql->bindValue(':mod',      $modalidade);
                $sql->bindValue(':rede',     $rede);
                $sql->bindValue(':qtdp',     $qtdParcelas);
                $sql->bindValue(':cancel',   $cancelado);
                $sql->bindValue(':idterm',   $idTerminal);
                $sql->bindValue(':ctrl',     $codigoControle);
                $sql->bindValue(':emp',      $empresa);
                $sql->bindValue(':vend',     $venda);
                $sql->bindValue(':parc',     $parcela);
                $sql->bindValue(':cod',      $codigo);
                $sql->bindValue(':ordem',    $ordem);
                $sql->execute();
            } else {
                $sql = $this->db->prepare("INSERT INTO vendas_tef (
                    empresa, venda, parcela, codigo, forma_pagto,
                    credenciadora, bandeira_cartao, codigo_transacao,
                    valor, troco, tipo, NSU, data_c, hora_c,
                    tipo_finan, modalidade, rede_adquirente,
                    ordem, qtd_parcelas, cancelado, id_terminal, codigo_controle
                ) VALUES (
                    :emp, :vend, :parc, :cod, :forma,
                    :cred, :band, :codtrans,
                    :valor, :troco, :tipo, :nsu, :dataC, :horaC,
                    :tipofin, :mod, :rede,
                    :ordem, :qtdp, :cancel, :idterm, :ctrl
                )");

                $sql->bindValue(':emp',      $empresa);
                $sql->bindValue(':vend',     $venda);
                $sql->bindValue(':parc',     $parcela);
                $sql->bindValue(':cod',      $codigo);
                $sql->bindValue(':forma',    $formaPagto);
                $sql->bindValue(':cred',     $credenciadora);
                $sql->bindValue(':band',     $bandeira);
                $sql->bindValue(':codtrans', $codigoTrans);
                $sql->bindValue(':valor',    $valor);
                $sql->bindValue(':troco',    $troco);
                $sql->bindValue(':tipo',     $tipo);
                $sql->bindValue(':nsu',      $nsu);
                $sql->bindValue(':dataC',    $dataC);
                $sql->bindValue(':horaC',    $horaC);
                $sql->bindValue(':tipofin',  $tipoFinan);
                $sql->bindValue(':mod',      $modalidade);
                $sql->bindValue(':rede',     $rede);
                $sql->bindValue(':ordem',    $ordem);
                $sql->bindValue(':qtdp',     $qtdParcelas);
                $sql->bindValue(':cancel',   $cancelado);
                $sql->bindValue(':idterm',   $idTerminal);
                $sql->bindValue(':ctrl',     $codigoControle);
                $sql->execute();
            }

            echo json_encode(array(
                'sucesso'  => true,
                'venda'    => (int)$venda,
                'ordem'    => (int)$ordem,
                'codigo'   => $codigo,
                'acao'     => $existe ? 'update' : 'insert',
                'mensagem' => 'Transação TEF gravada',
            ));

        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(array(
                'erro'    => $t->getMessage(),
                'arquivo' => $t->getFile(),
                'linha'   => $t->getLine(),
                'tipo'    => get_class($t),
            ));
        }
    }

      /**
     * Grava comprovante TEF em vendas_tef_comprovante.
     * PK real: (empresa, venda, parcela, codigo, ordem)
     * Compatível com MySQL 4.1.
     * POST /app/gravarVendasTefComprovante
     */
    public function gravarVendasTefComprovante() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $d = json_decode($json);

        $empresa = isset($d->empresa) ? $d->empresa : 1;
        $venda   = isset($d->venda)   ? $d->venda   : 0;
        $parcela = isset($d->parcela) ? $d->parcela : 0;
        $ordem   = isset($d->ordem)   ? $d->ordem   : 1;
        $codigo  = $venda;

        $viaCompleta = $this->limparComprovante(isset($d->via_completa)       ? $d->via_completa       : '');
        $viaCliente  = $this->limparComprovante(isset($d->via_cliente)        ? $d->via_cliente        : '');
        $viaEstab    = $this->limparComprovante(isset($d->via_estabelecimento) ? $d->via_estabelecimento : '');

        if (!$venda) {
            http_response_code(400);
            echo json_encode(array('erro' => 'venda obrigatória'));
            return;
        }

        try {
            $sel = $this->db->prepare("SELECT COUNT(*) AS n FROM vendas_tef_comprovante
                WHERE empresa = :emp AND venda = :vend AND parcela = :parc
                  AND codigo = :cod AND ordem = :ordem");
            $sel->bindValue(':emp',   $empresa);
            $sel->bindValue(':vend',  $venda);
            $sel->bindValue(':parc',  $parcela);
            $sel->bindValue(':cod',   $codigo);
            $sel->bindValue(':ordem', $ordem);
            $sel->execute();
            $row    = $sel->fetch(PDO::FETCH_ASSOC);
            $existe = $row && (int)$row['n'] > 0;

            if ($existe) {
                // Coluna é via_cliente (minúsculo) — confere com SHOW COLUMNS.
                $sql = $this->db->prepare("UPDATE vendas_tef_comprovante SET
                    via_completa        = :vc,
                    via_cliente         = :vcli,
                    via_estabelecimento = :vest
                    WHERE empresa = :emp AND venda = :vend
                      AND parcela = :parc AND codigo = :cod AND ordem = :ordem");
                $sql->bindValue(':vc',    $viaCompleta);
                $sql->bindValue(':vcli',  $viaCliente);
                $sql->bindValue(':vest',  $viaEstab);
                $sql->bindValue(':emp',   $empresa);
                $sql->bindValue(':vend',  $venda);
                $sql->bindValue(':parc',  $parcela);
                $sql->bindValue(':cod',   $codigo);
                $sql->bindValue(':ordem', $ordem);
                $sql->execute();
            } else {
                $sql = $this->db->prepare("INSERT INTO vendas_tef_comprovante (
                    empresa, venda, parcela, codigo,
                    via_completa, via_cliente, via_estabelecimento, ordem
                ) VALUES (
                    :emp, :vend, :parc, :cod,
                    :vc, :vcli, :vest, :ordem
                )");
                $sql->bindValue(':emp',   $empresa);
                $sql->bindValue(':vend',  $venda);
                $sql->bindValue(':parc',  $parcela);
                $sql->bindValue(':cod',   $codigo);
                $sql->bindValue(':vc',    $viaCompleta);
                $sql->bindValue(':vcli',  $viaCliente);
                $sql->bindValue(':vest',  $viaEstab);
                $sql->bindValue(':ordem', $ordem);
                $sql->execute();
            }

            echo json_encode(array(
                'sucesso' => true,
                'venda'   => (int)$venda,
                'ordem'   => (int)$ordem,
                'codigo'  => $codigo,
                'acao'    => $existe ? 'update' : 'insert',
            ));

        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(array(
                'erro'    => $t->getMessage(),
                'arquivo' => $t->getFile(),
                'linha'   => $t->getLine(),
                'tipo'    => get_class($t),
            ));
        }
    }

    /**
     * Limpa quebras e caracteres especiais do comprovante TEF.
     * Espelha fRetCaracteresComprovante do VB6.
     */
        /**
     * Limpa quebras e caracteres especiais do comprovante TEF.
     * Espelha fRetCaracteresComprovante do VB6.
     */
    private function limparComprovante($texto) {
        $texto = (string)$texto;

        // Converte \n e \r literais (escape duplo vindo do JSON) em quebras reais.
        $texto = str_replace("\\n", "\n", $texto);
        $texto = str_replace("\\r", "\r", $texto);

        // Normaliza CRLF: remove \r quando seguido de \n.
        $texto = str_replace("\r\n", "\n", $texto);

        // Remove caracteres de controle (exceto \n).
        $texto = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F]/', '', $texto);

        return trim($texto);
    }

    public function cancelarVendasTef() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $d = json_decode($json);

        $empresa = isset($d->empresa) ? (int)$d->empresa : 1;
        $venda   = isset($d->venda)   ? (int)$d->venda   : 0;
        $ordem   = isset($d->ordem)   ? (int)$d->ordem   : 1;

        if (!$venda) {
            http_response_code(400);
            echo json_encode(array('erro' => 'venda obrigatória'));
            return;
        }

        try {
            // Debug: ver o que está no banco antes do UPDATE
            $chk = $this->db->prepare("SELECT empresa, venda, ordem, cancelado, NSU
                FROM vendas_tef
                WHERE empresa = :emp AND venda = :vend AND ordem = :ordem");
            $chk->bindValue(':emp',   $empresa, PDO::PARAM_INT);
            $chk->bindValue(':vend',  $venda,   PDO::PARAM_INT);
            $chk->bindValue(':ordem', $ordem,   PDO::PARAM_INT);
            $chk->execute();
            $antes = $chk->fetchAll(PDO::FETCH_ASSOC);

            $sql = $this->db->prepare("UPDATE vendas_tef
                SET cancelado = 1
                WHERE empresa = :emp AND venda = :vend
                  AND ordem = :ordem AND cancelado = 0");
            $sql->bindValue(':emp',   $empresa, PDO::PARAM_INT);
            $sql->bindValue(':vend',  $venda,   PDO::PARAM_INT);
            $sql->bindValue(':ordem', $ordem,   PDO::PARAM_INT);
            $sql->execute();

            $afetadas = $sql->rowCount();

            if ($afetadas === 0) {
                http_response_code(409);
                echo json_encode(array(
                    'erro'    => 'Nenhuma linha atualizada em vendas_tef',
                    'empresa' => $empresa,
                    'venda'   => $venda,
                    'ordem'   => $ordem,
                    'antes'   => $antes,
                ));
                return;
            }

            echo json_encode(array(
                'sucesso'         => true,
                'venda'           => $venda,
                'ordem'           => $ordem,
                'linhas_afetadas' => $afetadas,
            ));
        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(array(
                'erro'    => $t->getMessage(),
                'arquivo' => $t->getFile(),
                'linha'   => $t->getLine(),
                'tipo'    => get_class($t),
            ));
        }
    }

        /**
     * Lista movimentos de caixa do dia.
     * POST /app/caixaListar
     * Body: { empresa, caixa, data }
     */
    public function caixaListar() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $d    = json_decode($json);

        $empresa = isset($d->empresa) ? (int)$d->empresa : 1;
        $caixa   = isset($d->caixa)   ? (string)$d->caixa : '1';
        $data    = isset($d->data)    ? (string)$d->data  : date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            http_response_code(400);
            echo json_encode(['erro' => 'Data inválida']);
            return;
        }

        try {
            $sql = $this->db->prepare("
                SELECT
                    cm.datalancamento,
                    cm.sequencial         AS ordem,
                    cm.nrodocumento,
                    tf.descricao          AS formapgto,
                    cm.historico,
                    cm.valor,
                    cm.tipo_lancamento,
                    cm.debitocredito,
                    cm.usuario,
                    u.nome                AS usuario_nome,
                    cm.origem,
                    cm.hora,
                    cm.codigoloteabertura,
                    cm.estornado,
                    cm.obs
                FROM caixa_movimento cm
                  LEFT JOIN tab_formapagamento tf
                    ON (cm.forma_pgto = tf.codigo)
                  INNER JOIN usuarios u
                    ON (cm.usuario = u.codigo AND u.empresa = cm.empresa)
                WHERE cm.empresa = :empresa
                  AND cm.caixa = :caixa
                  AND cm.datalancamento = :data
                GROUP BY cm.sequencial
                ORDER BY cm.sequencial ASC
            ");
            $sql->bindValue(':empresa', $empresa, PDO::PARAM_INT);
            $sql->bindValue(':caixa',   $caixa);
            $sql->bindValue(':data',    $data);
            $sql->execute();
            $movimentos = $sql->fetchAll(PDO::FETCH_ASSOC);

            $entradas = 0.0;
            $saidas   = 0.0;
            foreach ($movimentos as &$m) {
                $m['ordem']         = (int)$m['ordem'];
                $m['valor']         = (float)$m['valor'];
                $m['usuario']       = (int)$m['usuario'];
                $m['estornado']     = (int)$m['estornado'];
                $m['usuario_label'] = $m['usuario'] . '-' . $m['usuario_nome'];

                if ($m['estornado'] === 1) continue;
                if ($m['debitocredito'] === 'C') $entradas += $m['valor'];
                elseif ($m['debitocredito'] === 'D') $saidas += $m['valor'];
            }
            unset($m);

            echo json_encode([
                'sucesso'    => true,
                'empresa'    => $empresa,
                'caixa'      => $caixa,
                'data'       => $data,
                'movimentos' => $movimentos,
                'rodape'     => [
                    'quantidade' => count($movimentos),
                    'entradas'   => round($entradas, 2),
                    'saidas'     => round($saidas, 2),
                    'saldo'      => round($entradas - $saidas, 2),
                ],
            ]);
        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode([
                'erro'    => $t->getMessage(),
                'arquivo' => $t->getFile(),
                'linha'   => $t->getLine(),
            ]);
        }
    }

        // ============================================================
    // CAIXA — Controle de abertura/fechamento
    // ============================================================

    /**
     * Retorna o status atual do caixa.
     * POST /app/caixaInfo
     * Body: { empresa, caixa }
     */
    public function caixaInfo() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $d    = json_decode($json);

        $empresa = isset($d->empresa) ? (int)$d->empresa : 1;
        $caixa   = isset($d->caixa)   ? (string)$d->caixa : '1';

        try {
            // Config do caixa
            $sql = $this->db->prepare("SELECT controla_abertura_fechamento, status, dono
                                        FROM contas WHERE empresa = :emp AND conta = :caixa LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':caixa', $caixa);
            $sql->execute();
            $conta = $sql->fetch(PDO::FETCH_ASSOC);

            if (!$conta) {
                echo json_encode(['sucesso' => false, 'erro' => "Caixa $caixa não cadastrado"]);
                return;
            }

            $controlaManual = ((int)$conta['controla_abertura_fechamento'] === 1);
            $ativo          = ((int)$conta['status'] === 1);
            $hoje           = date('Y-m-d');

            // Lote ATIVO
            $sql = $this->db->prepare("SELECT codigo, data_abertura, hora_abertura,
                                              valor_abertura, historico_abertura
                                        FROM caixa_lote_abertura
                                        WHERE empresa = :emp AND caixa_abertura = :caixa
                                          AND (data_fechamento = '' OR data_fechamento = '0000-00-00')
                                        ORDER BY codigo DESC LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':caixa', $caixa);
            $sql->execute();
            $lote = $sql->fetch(PDO::FETCH_ASSOC);

            $loteAtivo = null;
            if ($lote) {
                $saldoAtual = $this->caixaCalcularSaldoFechamento($empresa, $caixa, (int)$lote['codigo']);
                $ehDeHoje   = ($lote['data_abertura'] === $hoje);

                $loteAtivo = [
                    'codigo'         => (int)$lote['codigo'],
                    'data_abertura'  => $lote['data_abertura'],
                    'hora_abertura'  => $lote['hora_abertura'],
                    'valor_abertura' => (float)$lote['valor_abertura'],
                    'saldo_atual'    => $saldoAtual,
                    'historico'      => $lote['historico_abertura'],
                    'ehDeHoje'       => $ehDeHoje,
                    'dias_em_aberto' => (int)((strtotime($hoje) - strtotime($lote['data_abertura'])) / 86400),
                ];
            }

            // Último lote FECHADO (fallback quando não há lote ativo)
            $sugestaoValor = 0;
            if ($loteAtivo) {
                // ✅ Se tem lote ativo, a sugestão é o saldo atual dele
                $sugestaoValor = $loteAtivo['saldo_atual'];
            } else {
                // Sem lote ativo: pega o valor_fechamento do último lote fechado
                $sql = $this->db->prepare("SELECT valor_fechamento
                                            FROM caixa_lote_abertura
                                            WHERE empresa = :emp AND caixa_abertura = :caixa
                                              AND data_fechamento IS NOT NULL
                                              AND data_fechamento <> ''
                                              AND data_fechamento <> '0000-00-00'
                                            ORDER BY codigo DESC LIMIT 1");
                $sql->bindValue(':emp', $empresa);
                $sql->bindValue(':caixa', $caixa);
                $sql->execute();
                $ultimo = $sql->fetch(PDO::FETCH_ASSOC);
                if ($ultimo) {
                    $sugestaoValor = (float)$ultimo['valor_fechamento'];
                }
            }

            // Se lote ativo é de ontem em modo MANUAL → precisa fechar antes
            $precisaFecharOntem = ($controlaManual && $loteAtivo && !$loteAtivo['ehDeHoje']);

            echo json_encode([
                'sucesso'              => true,
                'caixa'                => $caixa,
                'controlaManual'       => $controlaManual,
                'ativo'                => $ativo,
                'loteAtivo'            => $loteAtivo,
                'sugestaoValorAbertura'=> $sugestaoValor,
                'precisaFecharOntem'   => $precisaFecharOntem,
            ]);

        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => $t->getMessage(), 'linha' => $t->getLine()]);
        }
    }

        /**
     * Retorna o valor_fechamento do último lote fechado do caixa.
     * Usado para herança no modo AUTO quando não há lote ativo.
     */
    private function caixaObterUltimoValorFechamento($empresa, $caixa) {
        $sql = $this->db->prepare("SELECT valor_fechamento
                                    FROM caixa_lote_abertura
                                    WHERE empresa = :emp
                                      AND caixa_abertura = :cx
                                      AND data_fechamento IS NOT NULL
                                      AND data_fechamento <> ''
                                      AND data_fechamento <> '0000-00-00'
                                    ORDER BY codigo DESC LIMIT 1");
        $sql->bindValue(':emp', (int)$empresa, PDO::PARAM_INT);
        $sql->bindValue(':cx',  (string)$caixa);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        return $r ? (float)$r['valor_fechamento'] : 0;
    }

        /**
     * Valida se o lote está aberto. Lança exceção se estiver fechado.
     */
    private function caixaValidarLoteAberto($empresa, $caixa, $codigoLote) {
        $sql = $this->db->prepare("SELECT codigo, data_abertura, data_fechamento
                                    FROM caixa_lote_abertura
                                    WHERE empresa = :emp
                                      AND caixa_abertura = :caixa
                                      AND codigo = :cod
                                    LIMIT 1");
        $sql->bindValue(':emp',   (int)$empresa, PDO::PARAM_INT);
        $sql->bindValue(':caixa', (string)$caixa);
        $sql->bindValue(':cod',   (int)$codigoLote, PDO::PARAM_INT);
        $sql->execute();
        $lote = $sql->fetch(PDO::FETCH_ASSOC);

        if (!$lote) {
            throw new Exception("Lote $codigoLote não encontrado.");
        }
        if (!empty($lote['data_fechamento']) && $lote['data_fechamento'] !== '0000-00-00') {
            $dataBr = implode('/', array_reverse(explode('-', $lote['data_fechamento'])));
            throw new Exception("Caixa fechado em $dataBr — somente visualização.");
        }
    }

    /**
     * Abre o caixa manualmente.
     * POST /app/caixaAbrir
     * Body: { empresa, caixa, usuario, valorAbertura, historico }
     */
    public function caixaAbrir() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $d    = json_decode($json);

        $empresa       = isset($d->empresa)       ? (int)$d->empresa       : 1;
        $caixa         = isset($d->caixa)         ? (string)$d->caixa      : '1';
        $usuario       = isset($d->usuario)       ? (int)$d->usuario       : 0;
        $valorAbertura = isset($d->valorAbertura) ? (float)$d->valorAbertura : 0;
        $historico     = isset($d->historico)     ? (string)$d->historico  : 'Abertura manual';

        try {
            // Verifica lote aberto
            $sql = $this->db->prepare("SELECT codigo, data_abertura FROM caixa_lote_abertura
                                        WHERE empresa = :emp AND caixa_abertura = :caixa
                                          AND (data_fechamento = '' OR data_fechamento = '0000-00-00')
                                        ORDER BY codigo DESC LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':caixa', $caixa);
            $sql->execute();
            $loteAberto = $sql->fetch(PDO::FETCH_ASSOC);

            if ($loteAberto) {
                $hoje    = date('Y-m-d');
                $deHoje  = ($loteAberto['data_abertura'] === $hoje);
                http_response_code(409);

                if ($deHoje) {
                    echo json_encode([
                        'erro' => "O caixa $caixa já está aberto hoje ({$loteAberto['data_abertura']}).",
                        'tipo' => 'ja_aberto_hoje',
                    ]);
                } else {
                    $dataBr = implode('/', array_reverse(explode('-', $loteAberto['data_abertura'])));
                    echo json_encode([
                        'erro' => "O caixa $caixa está aberto desde $dataBr (dia anterior). " .
                                  "Feche o caixa de $dataBr antes de abrir um novo.",
                        'tipo' => 'precisa_fechar_ontem',
                        'dataAberturaPendente' => $loteAberto['data_abertura'],
                    ]);
                }
                return;
            }

            $this->db->beginTransaction();
            $codigoLote = $this->caixaCriarLote($empresa, $caixa, $valorAbertura, $historico, $usuario);
            $this->db->commit();

            echo json_encode([
                'sucesso' => true,
                'caixa'   => $caixa,
                'codigo'  => $codigoLote,
            ]);

        } catch (Throwable $t) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['erro' => $t->getMessage()]);
        }
    }

    /**
     * Fecha o caixa ativo.
     * POST /app/caixaFechar
     * Body: { empresa, caixa, usuario, valorFechamento (opcional), historico }
     */
    public function caixaFechar() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $d    = json_decode($json);

        $empresa          = isset($d->empresa)   ? (int)$d->empresa   : 1;
        $caixa            = isset($d->caixa)     ? (string)$d->caixa  : '1';
        $usuario          = isset($d->usuario)   ? (int)$d->usuario   : 0;
        $valorInformado   = isset($d->valorFechamento) ? (float)$d->valorFechamento : null;
        $historico        = isset($d->historico) ? (string)$d->historico : 'Fechamento manual';

        try {
            $sql = $this->db->prepare("SELECT codigo FROM caixa_lote_abertura
                                        WHERE empresa = :emp AND caixa_abertura = :caixa
                                          AND (data_fechamento = '' OR data_fechamento = '0000-00-00')
                                        ORDER BY codigo DESC LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':caixa', $caixa);
            $sql->execute();
            $lote = $sql->fetch(PDO::FETCH_ASSOC);

            if (!$lote) {
                http_response_code(409);
                echo json_encode(['erro' => "Caixa $caixa não está aberto"]);
                return;
            }

            $codigoLote = (int)$lote['codigo'];

            // Se não informado, calcula
            $valorFechamento = $valorInformado !== null
                ? $valorInformado
                : $this->caixaCalcularSaldoFechamento($empresa, $caixa, $codigoLote);

            $this->db->beginTransaction();
            $this->caixaFecharLote($empresa, $caixa, $codigoLote, $valorFechamento, $historico, $usuario);
            $this->db->commit();

            echo json_encode([
                'sucesso'         => true,
                'caixa'           => $caixa,
                'codigo'          => $codigoLote,
                'valorFechamento' => $valorFechamento,
            ]);

        } catch (Throwable $t) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['erro' => $t->getMessage()]);
        }
    }

    // ============================================================
    // HELPERS PRIVADOS — Caixa
    // ============================================================

    /**
     * Garante que o caixa tem um lote aberto para hoje.
     * Modo AUTO: abre/fecha automaticamente.
     * Modo MANUAL: lança exceção se não tiver lote de hoje.
     * Retorna o codigo do lote.
     */
    private function caixaGarantirLoteAberto($empresa, $caixa) {
        // 1. Config
        $sql = $this->db->prepare("SELECT controla_abertura_fechamento, status
                                    FROM contas WHERE empresa = :emp AND conta = :caixa LIMIT 1");
        $sql->bindValue(':emp', $empresa);
        $sql->bindValue(':caixa', $caixa);
        $sql->execute();
        $conta = $sql->fetch(PDO::FETCH_ASSOC);

        if (!$conta) {
            throw new Exception("Caixa $caixa não cadastrado em contas");
        }

        $controlaManual = ((int)$conta['controla_abertura_fechamento'] === 1);

        // 2. Lote ativo
        $sql = $this->db->prepare("SELECT codigo, data_abertura, valor_abertura
                                    FROM caixa_lote_abertura
                                    WHERE empresa = :emp AND caixa_abertura = :caixa
                                      AND (data_fechamento = '' OR data_fechamento = '0000-00-00')
                                    ORDER BY codigo DESC LIMIT 1");
        $sql->bindValue(':emp', $empresa);
        $sql->bindValue(':caixa', $caixa);
        $sql->execute();
        $lote = $sql->fetch(PDO::FETCH_ASSOC);

        $hoje = date('Y-m-d');

        // 3. Sem lote ativo
        if (!$lote) {
            if ($controlaManual) {
                throw new Exception("Caixa $caixa fechado. Abra o caixa antes de vender.");
            }
            // AUTO: herda valor do último lote fechado
            $valorHeranca = $this->caixaObterUltimoValorFechamento($empresa, $caixa);
            return $this->caixaCriarLote($empresa, $caixa, $valorHeranca, 'Abertura automática', 0);
        }

        // 4. Lote de dia anterior
        if ($lote['data_abertura'] < $hoje) {
            if ($controlaManual) {
                throw new Exception("Caixa $caixa aberto em {$lote['data_abertura']}. Feche antes de vender hoje.");
            }
            // AUTO: fecha anterior + abre novo com saldo herdado
            $valorFech = $this->caixaCalcularSaldoFechamento($empresa, $caixa, (int)$lote['codigo']);
            $this->caixaFecharLote($empresa, $caixa, (int)$lote['codigo'], $valorFech, 'Fechamento automático', 0);
            return $this->caixaCriarLote($empresa, $caixa, $valorFech, 'Abertura automática', 0);
        }

        // 5. Lote do dia — retorna
        return (int)$lote['codigo'];
    }

    /**
     * Calcula: valor_abertura + entradas - saídas (do lote).
     * Fórmula confirmada pelo ERP VB6.
     */
    private function caixaCalcularSaldoFechamento($empresa, $caixa, $codigoLote) {
        $codigoLote = (int)$codigoLote;

        // 1. valor_abertura + data_abertura do lote
        $sql = $this->db->prepare("SELECT valor_abertura, data_abertura
                                    FROM caixa_lote_abertura
                                    WHERE empresa = :emp
                                    AND caixa_abertura = :cx
                                    AND codigo = :cod
                                    LIMIT 1");
        $sql->bindValue(':emp',  (int)$empresa, PDO::PARAM_INT);
        $sql->bindValue(':cx',   (string)$caixa);
        $sql->bindValue(':cod',  $codigoLote, PDO::PARAM_INT);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$r) return 0;

        $valorAbertura = (float)$r['valor_abertura'];
        $dataAbertura  = $r['data_abertura'];

        // 2. Movimentos — só a partir da data de abertura
        $sql = $this->db->prepare("SELECT
            COALESCE(SUM(CASE WHEN debitocredito = 'C' AND estornado = 0 THEN valor ELSE 0 END), 0) AS entradas,
            COALESCE(SUM(CASE WHEN debitocredito = 'D' AND estornado = 0 THEN valor ELSE 0 END), 0) AS saidas
            FROM caixa_movimento
            WHERE empresa = :emp
            AND caixa = :cx
            AND CAST(codigoloteabertura AS UNSIGNED) = :lote
            AND datalancamento >= :data_abertura");
        $sql->bindValue(':emp',  (int)$empresa, PDO::PARAM_INT);
        $sql->bindValue(':cx',   (string)$caixa);
        $sql->bindValue(':lote', $codigoLote, PDO::PARAM_INT);
        $sql->bindValue(':data_abertura', $dataAbertura);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);

        return round($valorAbertura + (float)$r['entradas'] - (float)$r['saidas'], 2);
    }
    /**
     * Cria um novo lote. Retorna o codigo.
     */
    private function caixaCriarLote($empresa, $caixa, $valorAbertura, $historico, $usuario) {
            // MAX global por empresa — nunca reutiliza código
        $sql = $this->db->prepare("SELECT COALESCE(MAX(codigo), 0) + 1 AS proximo
                                    FROM caixa_lote_abertura
                                    WHERE empresa = :emp");
        $sql->bindValue(':emp', (int)$empresa, PDO::PARAM_INT);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        $novoCodigo = (int)$r['proximo'];

        $sql = $this->db->prepare("INSERT INTO caixa_lote_abertura
            (empresa, codigo, caixa_abertura, usuario, data_abertura, hora_abertura,
             valor_abertura, historico_abertura, data_fechamento, hora_fechamento,
             valor_fechamento, historico_fechamento, usuario_abertura, usuario_fechamento)
            VALUES
            (:emp, :cod, :caixa, :usr, :data, :hora,
             :valor, :hist, '0000-00-00', '00:00:00',
             0, '', :usr_ab, 0)");

        $sql->bindValue(':emp',    $empresa);
        $sql->bindValue(':cod',    $novoCodigo);
        $sql->bindValue(':caixa',  $caixa);
        $sql->bindValue(':usr',    $usuario);
        $sql->bindValue(':data',   date('Y-m-d'));
        $sql->bindValue(':hora',   date('H:i:s'));
        $sql->bindValue(':valor',  $valorAbertura);
        $sql->bindValue(':hist',   substr($historico, 0, 150));
        $sql->bindValue(':usr_ab', $usuario);
        $sql->execute();

        return $novoCodigo;
    }

    /**
     * Fecha um lote.
     */
    private function caixaFecharLote($empresa, $caixa, $codigo, $valorFech, $historico, $usuario) {
        $sql = $this->db->prepare("UPDATE caixa_lote_abertura
            SET data_fechamento     = :data,
                hora_fechamento     = :hora,
                valor_fechamento    = :valor,
                historico_fechamento= :hist,
                usuario_fechamento  = :usr
            WHERE empresa = :emp AND caixa_abertura = :caixa AND codigo = :cod");

        $sql->bindValue(':emp',   $empresa);
        $sql->bindValue(':caixa', $caixa);
        $sql->bindValue(':cod',   $codigo);
        $sql->bindValue(':data',  date('Y-m-d'));
        $sql->bindValue(':hora',  date('H:i:s'));
        $sql->bindValue(':valor', $valorFech);
        $sql->bindValue(':hist',  substr($historico, 0, 150));
        $sql->bindValue(':usr',   $usuario);
        $sql->execute();
    }

        /**
     * Busca exata por código de barras / referência.
     * POST /app/produtoBuscarExato
     * Body: { empresa, codigo }
     */
    public function produtoBuscarExato() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $d = json_decode($json);

        $empresa = isset($d->empresa) ? (int)$d->empresa : 1;
        $codigo  = isset($d->codigo)  ? trim((string)$d->codigo) : '';

        if ($codigo === '') {
            echo json_encode(['sucesso' => false, 'erro' => 'Código vazio']);
            return;
        }

        require_once __DIR__ . '/../models/Produto.php';

        try {
            $p = new Produto();
            $produto = $p->buscarExato($empresa, $codigo);
            if ($produto) {
                echo json_encode(['sucesso' => true, 'produto' => $produto]);
            } else {
                echo json_encode(['sucesso' => false, 'erro' => 'Produto não encontrado']);
            }
        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => $t->getMessage()]);
        }
    }

    /**
     * Busca por código de balança (EAN-13 com prefixo).
     * POST /app/produtoBuscarBalança
     * Body: { empresa, codigo, layout? }
     */
    public function produtoBuscarBalança() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $d = json_decode($json);

        $empresa = isset($d->empresa) ? (int)$d->empresa : 1;
        $codigo  = isset($d->codigo)  ? trim((string)$d->codigo) : '';
        $layout  = isset($d->layout)  ? (array)$d->layout : [];

        require_once __DIR__ . '/../models/Produto.php';

        try {
            $p = new Produto();
            $resultado = $p->buscarPorBalança($empresa, $codigo, $layout);
            if ($resultado) {
                echo json_encode([
                    'sucesso'     => true,
                    'produto'     => $resultado['produto'],
                    'quantidade'  => $resultado['quantidade'],
                    'valor_total' => $resultado['valor_total'],
                    'tipo'        => $resultado['tipo'],
                ]);
            } else {
                echo json_encode(['sucesso' => false, 'erro' => 'Código de balança inválido']);
            }
        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => $t->getMessage()]);
        }
    }

        /**
     * Autoriza cancelamento via supervisor (usuário + senha).
     * Verifica se o supervisor tem permissão 'cancelarorcamento' em tab_modulos_perfil_acao.
     * POST /app/autorizarCancelamento
     */
    public function autorizarCancelamento() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $d = json_decode($json);

        $empresa = isset($d->empresa) ? (int)$d->empresa : 1;
        $usuario = isset($d->usuario) ? trim((string)$d->usuario) : '';
        $senha   = isset($d->senha)   ? trim((string)$d->senha)   : '';

        if ($usuario === '' || $senha === '') {
            http_response_code(400);
            echo json_encode(['autorizado' => false, 'erro' => 'Usuário e senha obrigatórios']);
            return;
        }

        try {
            require_once __DIR__ . '/../models/Usuario.php';
            $u = new Usuario();
            $user = $u->autenticar($usuario, $senha, $empresa);

            if (!is_array($user) || !isset($user['codigo'])) {
                http_response_code(401);
                echo json_encode(['autorizado' => false, 'erro' => 'Usuário ou senha inválidos']);
                return;
            }

            // Busca perfil
            $perfil = 0;
            $sql = $this->db->prepare("SELECT perfil FROM usuarios
                                        WHERE empresa = :emp AND codigo = :cod LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':cod', $user['codigo']);
            $sql->execute();
            if ($sql->rowCount() > 0) {
                $row = $sql->fetch(PDO::FETCH_ASSOC);
                if (!empty($row['perfil'])) $perfil = (int)$row['perfil'];
            }

            // Verifica permissão cancelarorcamento
            $temPermissao = false;
            $sql = $this->db->prepare("SELECT executar FROM tab_modulos_perfil_acao
                                        WHERE empresa = :emp
                                          AND rotina = 'cancelarorcamento'
                                          AND perfil = :perfil LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->bindValue(':perfil', $perfil);
            $sql->execute();
            if ($sql->rowCount() > 0) {
                $row = $sql->fetch(PDO::FETCH_ASSOC);
                $v = strtoupper(trim((string)($row['executar'] ?? '')));
                $temPermissao = in_array($v, ['1', 'S', 'Y', 'T', 'TRUE'], true);
            }

            if (!$temPermissao) {
                http_response_code(403);
                echo json_encode([
                    'autorizado' => false,
                    'erro' => 'Este usuário não tem permissão para cancelar vendas',
                ]);
                return;
            }

            // Busca nome do funcionário
            $nomeFuncionario = null;
            try { $nomeFuncionario = $u->buscarNomeFuncionario($empresa, $user['codigo']); } catch (Throwable $e) {}

            echo json_encode([
                'autorizado' => true,
                'supervisor' => [
                    'codigo'          => (int)$user['codigo'],
                    'nome'            => $user['nome'],
                    'nomeFuncionario' => $nomeFuncionario,
                    'perfil'          => $perfil,
                ],
            ]);

        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['autorizado' => false, 'erro' => $t->getMessage()]);
        }
    }

        /**
     * Retorna parâmetros de venda (promoção por qtd).
     * POST /app/paramVendas
     */
    public function paramVendas() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $d = json_decode($json);
        $empresa = isset($d->empresa) ? (int)$d->empresa : 1;

        try {
            $sql = $this->db->prepare("SELECT promocao_por_qtd, promo_qtde_min
                                        FROM param_vendas WHERE empresa = :emp LIMIT 1");
            $sql->bindValue(':emp', $empresa);
            $sql->execute();
            $row = $sql->fetch(PDO::FETCH_ASSOC);

            $ativo = false;
            $min = 0;
            if ($row) {
                $v = strtoupper(trim((string)($row['promocao_por_qtd'] ?? '')));
                $ativo = in_array($v, ['1','S','Y','T','TRUE'], true);
                $min = (int)($row['promo_qtde_min'] ?? 0);
            }

            echo json_encode([
                'sucesso'         => true,
                'promocaoPorQtd'  => $ativo,
                'promoQtdeMin'    => $min,
            ]);
        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => $t->getMessage()]);
        }
    }

        /**
     * Recalcula o preço de TODAS as linhas não canceladas de um produto
     * na venda, considerando promoção por quantidade.
     * Chamado após adicionar e após remover itens.
     */
    private function reconciliarPrecosProduto($empresa, $venda, $referencia) {
        // 1. Config de promoção
        $sql = $this->db->prepare("SELECT promocao_por_qtd, promo_qtde_min
                                    FROM param_vendas WHERE empresa = :emp LIMIT 1");
        $sql->bindValue(':emp', $empresa);
        $sql->execute();
        $param = $sql->fetch(PDO::FETCH_ASSOC);

        $promoAtiva = false;
        $promoMin = 0;
        if ($param) {
            $v = strtoupper(trim((string)($param['promocao_por_qtd'] ?? '')));
            $promoAtiva = in_array($v, ['1','S','Y','T','TRUE'], true);
            $promoMin = (int)($param['promo_qtde_min'] ?? 0);
        }

        // 2. Preços do produto
        $sql = $this->db->prepare("SELECT vista, preco_promocao
                                    FROM produtos
                                    WHERE empresa = :emp AND referencia = :ref LIMIT 1");
        $sql->bindValue(':emp', $empresa);
        $sql->bindValue(':ref', $referencia);
        $sql->execute();
        $prod = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$prod) return;

        $precoVista = (float)$prod['vista'];
        $precoPromo = (float)$prod['preco_promocao'];

        // 3. Soma qtd total não cancelada
        $sql = $this->db->prepare("SELECT COALESCE(SUM(quantidade), 0) AS total
                                    FROM vendas_item
                                    WHERE empresa = :emp AND venda = :vend
                                      AND referencia = :ref AND cancelado = 0");
        $sql->bindValue(':emp', $empresa);
        $sql->bindValue(':vend', $venda);
        $sql->bindValue(':ref', $referencia);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        $qtdTotal = (float)$r['total'];

        // 4. Decide o preço
        $precoFinal = $precoVista;
        if ($promoAtiva && $promoMin > 0 && $precoPromo > 0 && $qtdTotal >= $promoMin) {
            $precoFinal = $precoPromo;
        }

        // 5. Atualiza todas as linhas do produto
        $sql = $this->db->prepare("UPDATE vendas_item
            SET preco = :p1,
                total_bruto   = ROUND(quantidade * :p2, 2),
                total_liquido = ROUND(quantidade * :p3 - COALESCE(valor_desconto_item, 0), 2)
            WHERE empresa = :emp AND venda = :vend
              AND referencia = :ref AND cancelado = 0");
        $sql->bindValue(':p1', $precoFinal);
        $sql->bindValue(':p2', $precoFinal);
        $sql->bindValue(':p3', $precoFinal);
        $sql->bindValue(':emp', $empresa);
        $sql->bindValue(':vend', $venda);
        $sql->bindValue(':ref', $referencia);
        $sql->execute();

        // Recalcula o total da venda após a promoção
        $this->recalcularTotalVenda($empresa, $venda);
    }

        /**
     * Força reconciliação de preços de um produto na venda.
     * POST /app/reconciliarPrecos
     * Body: { empresa, venda, referencia }
     */
    public function reconciliarPrecos() {
        header('Content-Type: application/json; charset=utf-8');
        $json = file_get_contents('php://input');
        $d = json_decode($json);

        $empresa    = isset($d->empresa)    ? (int)$d->empresa : 1;
        $venda      = isset($d->venda)      ? (int)$d->venda   : 0;
        $referencia = isset($d->referencia) ? (string)$d->referencia : '';

        if (!$venda || !$referencia) {
            http_response_code(400);
            echo json_encode(['erro' => 'venda e referencia obrigatórios']);
            return;
        }

        try {
            $this->reconciliarPrecosProduto($empresa, $venda, $referencia);
            echo json_encode(['sucesso' => true]);
        } catch (Throwable $t) {
            http_response_code(500);
            echo json_encode(['erro' => $t->getMessage()]);
        }
    }

        /**
     * Recalcula vendas.total somando todos os itens não cancelados.
     * Chame sempre que adicionar/remover item ou aplicar promoção.
     */
    private function recalcularTotalVenda($empresa, $venda) {
        $sql = $this->db->prepare("SELECT COALESCE(SUM(total_liquido), 0) AS total
                                    FROM vendas_item
                                    WHERE empresa = :emp AND venda = :vend AND cancelado = 0");
        $sql->bindValue(':emp', $empresa);
        $sql->bindValue(':vend', $venda);
        $sql->execute();
        $r = $sql->fetch(PDO::FETCH_ASSOC);
        $total = round((float)$r['total'], 2);

        $upd = $this->db->prepare("UPDATE vendas SET total = :total
                                    WHERE empresa = :emp AND venda = :vend");
        $upd->bindValue(':total', $total);
        $upd->bindValue(':emp', $empresa);
        $upd->bindValue(':vend', $venda);
        $upd->execute();
    }

}
?>
