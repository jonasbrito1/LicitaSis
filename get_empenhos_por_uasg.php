<?php
// ===========================================
// ARQUIVO: get_empenhos_por_uasg.php
// Lista empenhos existentes para uma UASG
// ===========================================
?>
<?php
require_once('db.php');

header('Content-Type: application/json');

if (isset($_GET['cliente_uasg'])) {
    $cliente_uasg = trim($_GET['cliente_uasg']);

    try {
        $sql = "SELECT 
                    id, 
                    numero, 
                    data,
                    valor_total_empenho,
                    classificacao,
                    pregao,
                    created_at,
                    DATEDIFF(CURDATE(), COALESCE(data, DATE(created_at))) as dias_desde_empenho
                FROM empenhos 
                WHERE cliente_uasg = :cliente_uasg 
                ORDER BY created_at DESC
                LIMIT 20";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cliente_uasg', $cliente_uasg, PDO::PARAM_STR);
        $stmt->execute();

        $empenhos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($empenhos) {
            // Calcula estatísticas
            $total_empenhos = count($empenhos);
            $valor_total = array_sum(array_column($empenhos, 'valor_total_empenho'));
            $em_atraso = array_filter($empenhos, function($e) {
                return in_array($e['classificacao'], ['Pendente', 'Faturado']) && $e['dias_desde_empenho'] > 30;
            });
            
            echo json_encode([
                'success' => true,
                'empenhos' => $empenhos,
                'estatisticas' => [
                    'total_empenhos' => $total_empenhos,
                    'valor_total' => $valor_total,
                    'em_atraso' => count($em_atraso),
                    'valor_medio' => $total_empenhos > 0 ? $valor_total / $total_empenhos : 0
                ]
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'empenhos' => [],
                'message' => 'Nenhum empenho encontrado para esta UASG',
                'estatisticas' => [
                    'total_empenhos' => 0,
                    'valor_total' => 0,
                    'em_atraso' => 0,
                    'valor_medio' => 0
                ]
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao buscar empenhos: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'error' => 'UASG não informada'
    ]);
}
?>