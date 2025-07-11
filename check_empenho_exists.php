<?php
// ===========================================
// ARQUIVO: check_empenho_exists.php
// Verifica se empenho já existe para uma UASG
// ===========================================
?>
<?php
require_once('db.php');

header('Content-Type: application/json');

if (isset($_GET['numero']) && isset($_GET['uasg'])) {
    $numero = trim($_GET['numero']);
    $uasg = trim($_GET['uasg']);
    
    try {
        $sql = "SELECT id, cliente_nome, created_at 
                FROM empenhos 
                WHERE numero = :numero AND cliente_uasg = :uasg 
                LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':numero', $numero, PDO::PARAM_STR);
        $stmt->bindParam(':uasg', $uasg, PDO::PARAM_STR);
        $stmt->execute();
        
        $empenho = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($empenho) {
            echo json_encode([
                'exists' => true,
                'cliente_nome' => $empenho['cliente_nome'],
                'created_at' => $empenho['created_at'],
                'message' => "Empenho {$numero} já existe para a UASG {$uasg}"
            ]);
        } else {
            echo json_encode([
                'exists' => false,
                'message' => 'Empenho disponível'
            ]);
        }
        
    } catch (PDOException $e) {
        echo json_encode([
            'error' => true,
            'message' => 'Erro ao verificar empenho: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'error' => true,
        'message' => 'Parâmetros insuficientes'
    ]);
}
?>