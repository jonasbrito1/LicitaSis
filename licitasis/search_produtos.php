<?php
// search_produtos.php
require_once('db.php'); // Conexão com o banco de dados

if (isset($_GET['query'])) {
    $query = $_GET['query'];

    // Prepara a consulta SQL para buscar produtos com base na query
    $sql = "SELECT id, nome, preco_unitario FROM produtos WHERE nome LIKE :query LIMIT 10"; // Limita a 10 resultados
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':query', '%' . $query . '%', PDO::PARAM_STR);
    $stmt->execute();

    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Retorna as sugestões como JSON
    echo json_encode($produtos);
}


?>
