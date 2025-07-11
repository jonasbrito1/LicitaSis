<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user'])) {
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit();
}

include('db.php');

$termo = trim($_GET['termo'] ?? '');

if (empty($termo) || strlen($termo) < 2) {
    echo json_encode([]);
    exit();
}

try {
    // Busca por nome, CNPJ ou UASG
    $sql = "SELECT id, uasg, cnpj, nome_orgaos, telefone, email, endereco 
            FROM clientes 
            WHERE nome_orgaos LIKE :termo 
               OR cnpj LIKE :termo 
               OR uasg LIKE :termo 
            ORDER BY nome_orgaos ASC 
            LIMIT 10";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':termo', "%$termo%", PDO::PARAM_STR);
    $stmt->execute();
    
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formata os resultados
    $resultados = [];
    foreach ($clientes as $cliente) {
        $resultados[] = [
            'id' => $cliente['id'],
            'nome' => $cliente['nome_orgaos'],
            'cnpj' => $cliente['cnpj'],
            'uasg' => $cliente['uasg'],
            'telefone' => $cliente['telefone'],
            'email' => $cliente['email'],
            'endereco' => $cliente['endereco'],
            'display' => $cliente['nome_orgaos'] . ' (' . $cliente['uasg'] . ')'
        ];
    }
    
    echo json_encode($resultados, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Erro na busca: ' . $e->getMessage()]);
}
?>