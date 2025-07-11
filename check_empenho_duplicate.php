<?php
session_start();
header('Content-Type: application/json');

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit();
}

// Conexão com o banco de dados
require_once('db.php');

$numero = isset($_GET['numero']) ? trim($_GET['numero']) : '';
$uasg = isset($_GET['uasg']) ? trim($_GET['uasg']) : '';

// Se algum campo estiver vazio, retorna que não existe
if (empty($numero) || empty($uasg)) {
    echo json_encode(['exists' => false]);
    exit();
}

try {
    // Verifica se já existe empenho com o mesmo número e UASG
    $sql = "SELECT cliente_nome FROM empenhos WHERE numero = :numero AND cliente_uasg = :cliente_uasg LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':numero', $numero, PDO::PARAM_STR);
    $stmt->bindParam(':cliente_uasg', $uasg, PDO::PARAM_STR);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo json_encode([
            'exists' => true,
            'cliente_nome' => $result['cliente_nome'],
            'message' => "Empenho {$numero} já existe para a UASG {$uasg} - Cliente: {$result['cliente_nome']}"
        ]);
    } else {
        echo json_encode(['exists' => false]);
    }
    
} catch (PDOException $e) {
    error_log("Erro ao verificar duplicação de empenho: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
?>