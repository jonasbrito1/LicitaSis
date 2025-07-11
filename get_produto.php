<?php
require_once('db.php');

if (isset($_GET['codigo'])) {
    $codigo = $_GET['codigo'];

    try {
        // Busca o produto pelo código
        $sql = "SELECT nome FROM produtos WHERE codigo = :codigo LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':codigo', $codigo, PDO::PARAM_STR);
        $stmt->execute();

        $produto = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($produto) {
            echo json_encode($produto); // Retorna o nome do produto em formato JSON
        } else {
            echo json_encode(['nome' => 'Produto não encontrado']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Erro ao buscar produto']);
    }
}
?>
