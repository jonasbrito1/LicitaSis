<?php
// ===========================================
// ARQUIVO: search_clientes.php
// Busca clientes por UASG ou nome com autocomplete
// ===========================================
?>
<?php
require_once('db.php');

header('Content-Type: application/json');

$query = isset($_GET['query']) ? trim($_GET['query']) : '';

if ($query !== '' && strlen($query) >= 2) {
    try {
        $sql = "SELECT uasg, nome_orgaos, cnpj, endereco, telefone 
                FROM clientes 
                WHERE uasg LIKE :query OR nome_orgaos LIKE :query 
                ORDER BY 
                    CASE 
                        WHEN uasg LIKE :exact_query THEN 1
                        WHEN nome_orgaos LIKE :starts_query THEN 2
                        ELSE 3
                    END,
                    nome_orgaos ASC
                LIMIT 10";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':query', "%$query%", PDO::PARAM_STR);
        $stmt->bindValue(':exact_query', $query, PDO::PARAM_STR);
        $stmt->bindValue(':starts_query', "$query%", PDO::PARAM_STR);
        $stmt->execute();
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($results);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro na busca: ' . $e->getMessage()]);
    }
} else {
    echo json_encode([]);
}
?>