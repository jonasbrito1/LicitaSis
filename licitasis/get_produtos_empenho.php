<?php
// Conexão com o banco de dados
require_once('db.php');

// Verifica se o parâmetro 'empenho_id' foi passado na URL
if (isset($_GET['empenho_id']) && is_numeric($_GET['empenho_id'])) {
    $empenhoId = $_GET['empenho_id'];

    try {
        // Consulta para buscar os produtos da venda vinculada ao empenho
        $sql = "SELECT p.nome AS produto, p.preco_unitario, v.quantidade, v.valor_total, p.observacao
                FROM produtos p
                INNER JOIN vendas v ON v.codigo_produto = p.codigo
                WHERE v.empenho_id = :empenho_id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':empenho_id', $empenhoId, PDO::PARAM_INT);
        $stmt->execute();
        
        $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($produtos) > 0) {
            echo json_encode(['produtos' => $produtos]);
        } else {
            echo json_encode(['produtos' => [], 'message' => 'Nenhum produto encontrado para este empenho.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar produtos: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'ID de empenho inválido.']);
}
?>
