<?php
require_once('db.php');

$query = isset($_GET['query']) ? trim($_GET['query']) : '';

if ($query !== '') {
    $sql = "SELECT uasg, nome_orgaos, cnpj FROM clientes WHERE uasg LIKE :query OR nome_orgaos LIKE :query LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':query', "%$query%", PDO::PARAM_STR);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($results);
} else {
    echo json_encode([]);
}
?>