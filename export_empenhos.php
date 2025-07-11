<?php
// ===========================================
// ARQUIVO: export_empenhos.php
// Exportação de dados de empenhos
// ===========================================
?>
<?php
require_once('db.php');
require_once('permissions.php');

session_start();

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('empenhos', 'read');

$format = $_GET['format'] ?? 'csv';
$filters = $_GET['filters'] ?? '';

try {
    // Constrói a query baseada nos filtros
    $whereConditions = [];
    $params = [];
    
    if (!empty($filters)) {
        $filterData = json_decode($filters, true);
        
        if (!empty($filterData['search'])) {
            $whereConditions[] = "(e.numero LIKE :search OR e.cliente_nome LIKE :search OR e.pregao LIKE :search)";
            $params[':search'] = "%{$filterData['search']}%";
        }
        
        if (!empty($filterData['classificacao'])) {
            $whereConditions[] = "e.classificacao = :classificacao";
            $params[':classificacao'] = $filterData['classificacao'];
        }
        
        if (!empty($filterData['data_inicio'])) {
            $whereConditions[] = "e.data >= :data_inicio";
            $params[':data_inicio'] = $filterData['data_inicio'];
        }
        
        if (!empty($filterData['data_fim'])) {
            $whereConditions[] = "e.data <= :data_fim";
            $params[':data_fim'] = $filterData['data_fim'];
        }
    }
    
    $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
    
    $sql = "SELECT 
                e.numero,
                e.cliente_uasg,
                e.cliente_nome,
                e.data,
                e.valor_total_empenho,
                e.classificacao,
                e.pregao,
                e.prioridade,
                e.observacao,
                e.created_at,
                DATEDIFF(CURDATE(), COALESCE(e.data, DATE(e.created_at))) as dias_desde_empenho
            FROM empenhos e 
            $whereClause
            ORDER BY e.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $empenhos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="empenhos_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // BOM para UTF-8
        fwrite($output, "\xEF\xBB\xBF");
        
        // Cabeçalhos
        fputcsv($output, [
            'Número',
            'UASG',
            'Cliente',
            'Data Empenho',
            'Valor Total',
            'Classificação',
            'Pregão',
            'Prioridade',
            'Dias desde Empenho',
            'Data Cadastro',
            'Observações'
        ], ';');
        
        // Dados
        foreach ($empenhos as $empenho) {
            fputcsv($output, [
                $empenho['numero'],
                $empenho['cliente_uasg'],
                $empenho['cliente_nome'],
                $empenho['data'] ? date('d/m/Y', strtotime($empenho['data'])) : '',
                'R$ ' . number_format($empenho['valor_total_empenho'], 2, ',', '.'),
                $empenho['classificacao'],
                $empenho['pregao'],
                $empenho['prioridade'],
                $empenho['dias_desde_empenho'],
                date('d/m/Y H:i', strtotime($empenho['created_at'])),
                $empenho['observacao']
            ], ';');
        }
        
        fclose($output);
        
    } elseif ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="empenhos_' . date('Y-m-d') . '.json"');
        
        echo json_encode([
            'exported_at' => date('Y-m-d H:i:s'),
            'total_records' => count($empenhos),
            'filters' => $filterData ?? null,
            'data' => $empenhos
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Formato não suportado']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro na exportação: ' . $e->getMessage()]);
}
?>