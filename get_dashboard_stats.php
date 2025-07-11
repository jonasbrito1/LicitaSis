<?php
// ===========================================
// ARQUIVO: get_dashboard_stats.php
// Estatísticas para dashboard do sistema
// ===========================================
?>
<?php
require_once('db.php');

header('Content-Type: application/json');

try {
    // Estatísticas gerais
    $stats = [];
    
    // Total de empenhos
    $sql = "SELECT COUNT(*) as total FROM empenhos";
    $stmt = $pdo->query($sql);
    $stats['total_empenhos'] = $stmt->fetchColumn();
    
    // Valor total
    $sql = "SELECT SUM(valor_total_empenho) as total FROM empenhos";
    $stmt = $pdo->query($sql);
    $stats['valor_total'] = floatval($stmt->fetchColumn());
    
    // Empenhos por classificação
    $sql = "SELECT classificacao, COUNT(*) as quantidade, SUM(valor_total_empenho) as valor 
            FROM empenhos 
            GROUP BY classificacao";
    $stmt = $pdo->query($sql);
    $stats['por_classificacao'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Empenhos em atraso
    $sql = "SELECT COUNT(*) as atrasados 
            FROM empenhos 
            WHERE classificacao IN ('Pendente', 'Faturado') 
            AND DATEDIFF(CURDATE(), COALESCE(data, DATE(created_at))) > 30";
    $stmt = $pdo->query($sql);
    $stats['empenhos_atrasados'] = $stmt->fetchColumn();
    
    // Empenhos recentes (últimos 30 dias)
    $sql = "SELECT COUNT(*) as recentes 
            FROM empenhos 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $stmt = $pdo->query($sql);
    $stats['empenhos_recentes'] = $stmt->fetchColumn();
    
    // Top 5 clientes por valor
    $sql = "SELECT 
                cliente_nome, 
                cliente_uasg,
                COUNT(*) as total_empenhos,
                SUM(valor_total_empenho) as valor_total
            FROM empenhos 
            GROUP BY cliente_uasg, cliente_nome
            ORDER BY valor_total DESC 
            LIMIT 5";
    $stmt = $pdo->query($sql);
    $stats['top_clientes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Empenhos por mês (últimos 12 meses)
    $sql = "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as mes,
                COUNT(*) as quantidade,
                SUM(valor_total_empenho) as valor
            FROM empenhos 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY mes ASC";
    $stmt = $pdo->query($sql);
    $stats['por_mes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Total de clientes ativos
    $sql = "SELECT COUNT(DISTINCT cliente_uasg) as clientes_ativos FROM empenhos";
    $stmt = $pdo->query($sql);
    $stats['clientes_ativos'] = $stmt->fetchColumn();
    
    // Total de produtos cadastrados
    $sql = "SELECT COUNT(*) as total_produtos FROM produtos";
    $stmt = $pdo->query($sql);
    $stats['total_produtos'] = $stmt->fetchColumn();
    
    // Produtos mais utilizados
    $sql = "SELECT 
                p.nome,
                p.codigo,
                COUNT(*) as frequencia,
                SUM(ep.quantidade) as quantidade_total,
                SUM(ep.valor_total) as valor_total
            FROM empenho_produtos ep
            LEFT JOIN produtos p ON ep.produto_id = p.id
            WHERE ep.produto_id IS NOT NULL
            GROUP BY ep.produto_id
            ORDER BY frequencia DESC
            LIMIT 10";
    $stmt = $pdo->query($sql);
    $stats['produtos_mais_usados'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao gerar estatísticas: ' . $e->getMessage()
    ]);
}
?>