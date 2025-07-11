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
    $action = $_POST['action'] ?? '';
    $empenho_id = intval($_POST['empenho_id'] ?? 0);
    
    if (!$empenho_id) {
        throw new Exception('ID do empenho é obrigatório');
    }
    
    switch ($action) {
        case 'add':
            // Adicionar produto
            $produto_id = $_POST['produto_id'] ?? null;
            $nome = trim($_POST['nome'] ?? '');
            $quantidade = intval($_POST['quantidade'] ?? 0);
            $valor_unitario = floatval($_POST['valor_unitario'] ?? 0);
            $descricao_produto = trim($_POST['descricao_produto'] ?? '');
            
            if (empty($nome) || $quantidade <= 0 || $valor_unitario <= 0) {
                throw new Exception('Dados inválidos do produto');
            }
            
            $valor_total = $quantidade * $valor_unitario;
            
            $sql = "INSERT INTO empenho_produtos (empenho_id, produto_id, descricao_produto, quantidade, valor_unitario, valor_total) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$empenho_id, $produto_id, $nome, $quantidade, $valor_unitario, $valor_total]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Produto adicionado com sucesso',
                'produto_empenho_id' => $pdo->lastInsertId()
            ]);
            break;
            
        case 'edit':
            // Editar produto
            $produto_empenho_id = intval($_POST['produto_id'] ?? 0);
            $nome = trim($_POST['nome'] ?? '');
            $quantidade = intval($_POST['quantidade'] ?? 0);
            $valor_unitario = floatval($_POST['valor_unitario'] ?? 0);
            
            if (!$produto_empenho_id || empty($nome) || $quantidade <= 0 || $valor_unitario <= 0) {
                throw new Exception('Dados inválidos para edição');
            }
            
            $valor_total = $quantidade * $valor_unitario;
            
            $sql = "UPDATE empenho_produtos SET 
                    descricao_produto = ?, quantidade = ?, valor_unitario = ?, valor_total = ?
                    WHERE id = ? AND empenho_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nome, $quantidade, $valor_unitario, $valor_total, $produto_empenho_id, $empenho_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Produto atualizado com sucesso'
            ]);
            break;
            
        case 'remove':
            // Remover produto
            $produto_empenho_id = intval($_POST['produto_id'] ?? 0);
            
            if (!$produto_empenho_id) {
                throw new Exception('ID do produto é obrigatório');
            }
            
            $sql = "DELETE FROM empenho_produtos WHERE id = ? AND empenho_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$produto_empenho_id, $empenho_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Produto removido com sucesso'
            ]);
            break;
            
        default:
            throw new Exception('Ação não reconhecida');
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