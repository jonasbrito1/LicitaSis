<?php
// Conexão com o banco de dados
require_once('../includes/db.php');

if (isset($_GET['query'])) {
    $query = $_GET['query'];
    $sql = "SELECT * FROM clientes WHERE uasg LIKE :query OR pregao LIKE :query LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':query', "%$query%");
    $stmt->execute();

    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($clientes);
}
