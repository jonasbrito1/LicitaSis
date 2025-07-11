<?php
// Conexão com o banco de dados
require_once('db.php');

// Verifica se o parâmetro uasg foi enviado
if (isset($_GET['uasg'])) {
    $uasg = $_GET['uasg'];

    // Busca os dados do cliente com base no UASG
    $sql = "SELECT * FROM clientes WHERE uasg = :uasg LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':uasg', $uasg);
    $stmt->execute();

    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cliente) {
        // Se o cliente for encontrado, retorna o nome do órgão e o cliente completo
        echo json_encode($cliente);
    } else {
        // Se não encontrar, retorna um array vazio
        echo json_encode([]);
    }
}
?>
