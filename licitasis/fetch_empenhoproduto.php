<?php
session_start();

// Conexão com o banco de dados
require_once('db.php');

// Buscar produtos relacionados ao empenho
if (isset($_GET['empenho_id'])) {
    $empenho_id = $_GET['empenho_id'];

    try {
        // Consultar produtos relacionados ao empenho
        $sql_produtos = "SELECT p.id, p.nome, p.codigo, ep.quantidade, ep.valor_unitario, ep.valor_total, ep.descricao_produto
                         FROM empenho_produtos ep
                         JOIN produtos p ON ep.produto_id = p.id
                         WHERE ep.empenho_id = :empenho_id";

        $stmt_produtos = $pdo->prepare($sql_produtos);
        $stmt_produtos->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
        $stmt_produtos->execute();

        $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

        if ($produtos) {
            // Retorna os produtos como JSON
            echo json_encode($produtos);
        } else {
            echo json_encode(['error' => 'Nenhum produto encontrado para este empenho']);
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar produtos: ' . $e->getMessage()]);
    }
    exit();
}
?>
