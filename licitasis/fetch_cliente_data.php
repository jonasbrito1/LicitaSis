<?php
require_once('db.php');

if (isset($_GET['uasg'])) {
    $uasg = trim($_GET['uasg']);

    try {
        // Consulta para buscar o cliente com base na UASG
        $sql = "SELECT nome_orgaos, cnpj FROM clientes WHERE uasg = :uasg LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':uasg', $uasg, PDO::PARAM_STR);
        $stmt->execute();
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cliente) {
            echo json_encode(['success' => true, 'nome_orgaos' => $cliente['nome_orgaos'], 'cnpj' => $cliente['cnpj']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'UASG não encontrada.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao buscar os dados: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'UASG não fornecida.']);
}