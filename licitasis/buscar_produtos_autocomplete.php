<?php
require_once('db.php');

if (isset($_GET['query'])) {
    $query = $_GET['query'];
    try {
        $sql = "SELECT id, nome, preco_unitario, observacao FROM produtos WHERE nome LIKE :query ORDER BY nome LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':query', '%' . $query . '%', PDO::PARAM_STR);
        $stmt->execute();

        $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($produtos);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar produtos: ' . $e->getMessage()]);
    }
}
?>
