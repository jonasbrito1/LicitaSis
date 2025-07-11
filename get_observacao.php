<?php
require_once('db.php');

if (isset($_GET['produto_id'])) {
    $produto_id = $_GET['produto_id'];

    try {
        $sql = "SELECT observacao FROM produtos WHERE id = :produto_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
        $stmt->execute();

        $produto = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($produto) {
            echo json_encode(['observacao' => $produto['observacao']]);
        } else {
            echo json_encode(['error' => 'Observação não encontrada']);
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar a observação: ' . $e->getMessage()]);
    }
}
?>
