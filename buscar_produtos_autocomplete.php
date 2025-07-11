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
    $termo = isset($_GET['termo']) ? trim($_GET['termo']) : '';
    $produto_id = isset($_GET['produto_id']) ? intval($_GET['produto_id']) : 0;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    
    if ($produto_id) {
        // Busca produto específico por ID
        $sql = "SELECT * FROM produtos WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$produto_id]);
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($produto) {
            echo json_encode([
                'success' => true,
                'produto' => $produto
            ]);
        } else {
            throw new Exception('Produto não encontrado');
        }
        
    } else {
        // Busca produtos por termo
        if (empty($termo) || strlen($termo) < 2) {
            echo json_encode([
                'success' => true,
                'produtos' => []
            ]);
            exit();
        }
        
        $sql = "SELECT 
            id, codigo, nome, unidade, categoria, 
            preco_unitario, preco_venda, custo_total, 
            estoque_atual, controla_estoque, estoque_minimo, 
            observacao, fornecedor
            FROM produtos 
            WHERE (nome LIKE ? OR codigo LIKE ?) 
            ORDER BY 
                CASE 
                    WHEN codigo = ? THEN 1 
                    WHEN nome LIKE ? THEN 2 
                    WHEN codigo LIKE ? THEN 3 
                    ELSE 4 
                END, nome ASC 
            LIMIT ?";
            
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            "%$termo%", "%$termo%", 
            $termo, "$termo%", "$termo%", 
            $limit
        ]);
        
        $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Processa os produtos
        foreach ($produtos as &$produto) {
            $produto['preco_sugerido'] = floatval($produto['preco_venda'] ?: $produto['preco_unitario']);
            $produto['estoque_formatado'] = number_format($produto['estoque_atual'], 0, ',', '.');
            $produto['estoque_baixo'] = $produto['controla_estoque'] && $produto['estoque_atual'] <= $produto['estoque_minimo'];
            $produto['sem_estoque'] = $produto['controla_estoque'] && $produto['estoque_atual'] <= 0;
            
            $produto['status'] = [];
            if ($produto['estoque_baixo']) {
                $produto['status'][] = 'Estoque baixo';
            }
            if ($produto['sem_estoque']) {
                $produto['status'][] = 'Sem estoque';
            }
            
            $produto['unidade_display'] = $produto['unidade'] ?: 'UN';
        }
        
        echo json_encode([
            'success' => true,
            'produtos' => $produtos,
            'total' => count($produtos)
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
exit(); // IMPORTANTE: Terminar execução aqui
?>