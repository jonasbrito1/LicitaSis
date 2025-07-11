<?php
// ===========================================
// ARQUIVO: get_produto_details.php
// Obtém detalhes completos de um produto específico
// Sistema: LicitaSis - Gestão de Licitações
// ===========================================

// Configuração de erro
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Limpa buffer
if (ob_get_level()) {
    ob_clean();
}

// Inicia sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Função para resposta JSON
function jsonResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// Função de log
function logError($message, $context = []) {
    $logMessage = "[" . date('Y-m-d H:i:s') . "] get_produto_details.php: {$message}";
    if (!empty($context)) {
        $logMessage .= " - Context: " . json_encode($context);
    }
    error_log($logMessage);
}

try {
    // Verifica usuário logado
    if (!isset($_SESSION['user'])) {
        jsonResponse([
            'success' => false,
            'error' => 'Usuário não logado'
        ], 401);
    }

    // Conexão com banco
    require_once('db.php');
    
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception('Conexão com banco indisponível');
    }

    // Parâmetros
    $produto_id = null;
    $codigo = null;

    // Busca por ID ou código
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $produto_id = intval($_GET['id']);
    } elseif (isset($_GET['codigo']) && !empty($_GET['codigo'])) {
        $codigo = trim($_GET['codigo']);
    } else {
        jsonResponse([
            'success' => false,
            'error' => 'ID ou código do produto não fornecido',
            'message' => 'Informe o ID ou código do produto'
        ]);
    }

    // Query para buscar produto
    if ($produto_id) {
        $sql = "SELECT * FROM produtos WHERE id = :id LIMIT 1";
        $param = ':id';
        $value = $produto_id;
        $valueType = PDO::PARAM_INT;
    } else {
        $sql = "SELECT * FROM produtos WHERE codigo = :codigo LIMIT 1";
        $param = ':codigo';
        $value = $codigo;
        $valueType = PDO::PARAM_STR;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue($param, $value, $valueType);
    $stmt->execute();
    
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$produto) {
        $criterio = $produto_id ? "ID {$produto_id}" : "código '{$codigo}'";
        jsonResponse([
            'success' => false,
            'error' => 'Produto não encontrado',
            'message' => "Produto com {$criterio} não foi encontrado"
        ], 404);
    }

    // Processa dados do produto
    $preco_unitario = floatval($produto['preco_unitario'] ?? 0);
    $preco_venda = floatval($produto['preco_venda'] ?? 0);
    $custo_total = floatval($produto['custo_total'] ?? 0);
    $estoque_atual = floatval($produto['estoque_atual'] ?? 0);
    $estoque_minimo = floatval($produto['estoque_minimo'] ?? 0);
    $controla_estoque = boolval($produto['controla_estoque'] ?? 0);

    // Preço sugerido
    $preco_sugerido = $preco_venda > 0 ? $preco_venda : $preco_unitario;

    // Margem de lucro
    $margem_lucro = 0;
    if ($custo_total > 0 && $preco_sugerido > 0) {
        $margem_lucro = (($preco_sugerido - $custo_total) / $preco_sugerido) * 100;
    }

    // Status e alertas
    $status = [];
    $disponivel = true;

    if ($controla_estoque) {
        if ($estoque_atual <= 0) {
            $status[] = 'Sem estoque';
            $disponivel = false;
        } elseif ($estoque_atual <= $estoque_minimo) {
            $status[] = 'Estoque baixo';
        }
    }

    if ($preco_sugerido <= 0) {
        $status[] = 'Sem preço definido';
    }

    if ($custo_total <= 0) {
        $status[] = 'Custo não definido';
    }

    // Monta resposta completa
    $produto_detalhado = [
        // Identificação
        'id' => intval($produto['id']),
        'codigo' => $produto['codigo'] ?? '',
        'nome' => $produto['nome'] ?? 'Produto sem nome',
        'categoria' => $produto['categoria'] ?? '',
        'fornecedor' => $produto['fornecedor'] ?? '',
        'observacao' => $produto['observacao'] ?? '',
        'unidade' => $produto['unidade'] ?? $produto['und'] ?? 'UN',

        // Preços
        'preco_unitario' => $preco_unitario,
        'preco_venda' => $preco_venda,
        'preco_sugerido' => $preco_sugerido,
        'custo_total' => $custo_total,
        'margem_lucro' => floatval($produto['margem_lucro'] ?? 0),
        'margem_real' => $margem_lucro,

        // Impostos
        'icms' => floatval($produto['icms'] ?? 0),
        'irpj' => floatval($produto['irpj'] ?? 0),
        'cofins' => floatval($produto['cofins'] ?? 0),
        'csll' => floatval($produto['csll'] ?? 0),
        'pis_pasep' => floatval($produto['pis_pasep'] ?? 0),
        'ipi' => floatval($produto['ipi'] ?? 0),
        'total_impostos' => floatval($produto['total_impostos'] ?? 0),

        // Estoque
        'estoque_atual' => $estoque_atual,
        'estoque_minimo' => $estoque_minimo,
        'controla_estoque' => $controla_estoque,
        'disponivel' => $disponivel,
        'estoque_baixo' => $controla_estoque && $estoque_atual <= $estoque_minimo,

        // Status
        'status' => $status,
        'tem_alertas' => !empty($status),

        // Formatação
        'preco_formatado' => 'R$ ' . number_format($preco_sugerido, 2, ',', '.'),
        'custo_formatado' => 'R$ ' . number_format($custo_total, 2, ',', '.'),
        'margem_formatada' => number_format($margem_lucro, 1, ',', '.') . '%',
        'estoque_formatado' => number_format($estoque_atual, 2, ',', '.') . ' ' . ($produto['unidade'] ?? 'UN'),

        // Metadados
        'created_at' => $produto['created_at'] ?? null,
        'updated_at' => $produto['updated_at'] ?? null,

        // Para formulários
        'form_data' => [
            'produto_id' => intval($produto['id']),
            'nome' => $produto['nome'] ?? '',
            'codigo' => $produto['codigo'] ?? '',
            'valor_unitario' => $preco_sugerido,
            'descricao_produto' => $produto['observacao'] ?? $produto['nome'] ?? '',
            'unidade' => $produto['unidade'] ?? 'UN'
        ]
    ];

    logError("Produto encontrado", ['id' => $produto['id'], 'nome' => $produto['nome']]);

    jsonResponse([
        'success' => true,
        'produto' => $produto_detalhado,
        'message' => 'Produto encontrado com sucesso'
    ]);

} catch (PDOException $e) {
    logError("Erro de banco", [
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
    
    jsonResponse([
        'success' => false,
        'error' => 'Erro de banco de dados',
        'message' => 'Erro ao consultar produto'
    ], 500);

} catch (Exception $e) {
    logError("Erro geral", [
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
    
    jsonResponse([
        'success' => false,
        'error' => 'Erro interno',
        'message' => 'Erro inesperado ao buscar produto'
    ], 500);
}
?>