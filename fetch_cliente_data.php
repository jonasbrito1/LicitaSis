
<?php
// ===========================================
// ARQUIVO: fetch_cliente_data.php
// Busca dados completos do cliente por UASG
// ===========================================
?>
<?php
require_once('db.php');

header('Content-Type: application/json');

if (isset($_GET['uasg'])) {
    $uasg = trim($_GET['uasg']);

    try {
        $sql = "SELECT 
                    uasg, 
                    nome_orgaos, 
                    cnpj, 
                    endereco, 
                    telefone, 
                    telefone2, 
                    email, 
                    email2,
                    observacoes
                FROM clientes 
                WHERE uasg = :uasg 
                LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':uasg', $uasg, PDO::PARAM_STR);
        $stmt->execute();

        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cliente) {
            echo json_encode([
                'success' => true,
                'uasg' => $cliente['uasg'],
                'nome_orgaos' => $cliente['nome_orgaos'],
                'cnpj' => $cliente['cnpj'],
                'endereco' => $cliente['endereco'],
                'telefone' => $cliente['telefone'],
                'telefone2' => $cliente['telefone2'],
                'email' => $cliente['email'],
                'email2' => $cliente['email2'],
                'observacoes' => $cliente['observacoes']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Cliente não encontrado para a UASG informada'
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao buscar cliente: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'error' => 'UASG não informada'
    ]);
}
?>