<?php
session_start();
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit();
}

include('db.php');

// IMPORTANTE: Garantir que só retorna JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    $empenho_id = intval($_GET['empenho_id'] ?? 0);
    
    if (!$empenho_id) {
        throw new Exception('ID do empenho é obrigatório');
    }
    
    // Busca produtos do empenho
    $sql = "SELECT 
        ep.*,
        COALESCE(p.nome, ep.descricao_produto, 'Produto sem nome') AS produto_nome,
        p.codigo AS produto_codigo,
        p.categoria AS produto_categoria,
        p.unidade AS produto_unidade,
        COALESCE(p.custo_total, 0) AS custo_unitario,
        COALESCE(ep.quantidade * ep.valor_unitario, 0) as valor_total,
        CASE 
            WHEN p.custo_total > 0 AND ep.valor_unitario > 0 THEN 
                ((ep.valor_unitario - p.custo_total) / ep.valor_unitario) * 100
            ELSE 0 
        END as margem_lucro_calculada
        FROM empenho_produtos ep 
        LEFT JOIN produtos p ON ep.produto_id = p.id 
        WHERE ep.empenho_id = ?
        ORDER BY ep.id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empenho_id]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcula estatísticas
    $quantidade_total_itens = 0;
    $valor_total_produtos = 0;
    
    foreach ($produtos as &$produto) {
        $quantidade_total_itens += intval($produto['quantidade']);
        $valor_total_produtos += floatval($produto['valor_total']);
        
        // Formata valores para exibição
        $produto['valor_unitario'] = floatval($produto['valor_unitario']);
        $produto['quantidade'] = intval($produto['quantidade']);
        $produto['valor_total'] = floatval($produto['valor_total']);
    }
    
    $estatisticas = [
        'total_produtos' => count($produtos),
        'quantidade_total_itens' => $quantidade_total_itens,
        'valor_total_produtos' => $valor_total_produtos,
        'valor_total_produtos_formatado' => 'R$ ' . number_format($valor_total_produtos, 2, ',', '.')
    ];
    
    echo json_encode([
        'success' => true,
        'produtos' => $produtos,
        'estatisticas' => $estatisticas
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
exit(); // IMPORTANTE: Terminar execução aqui
?>