<?php
// ===========================================
// DETALHES DE EMPENHOS POR CLASSIFICAÇÃO
// Sistema LicitaSis - Versão Completa e Corrigida
// Versão: 2.0 FINAL - Sistema Completo de Detalhes
// ===========================================
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Includes necessários
require_once('db.php');
require_once('permissions.php');
require_once('includes/audit.php');

// Inicialização do sistema de permissões
$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('empenhos', 'read');
logUserAction('READ', 'empenhos_detalhes');

// Função auxiliar para evitar problemas com htmlspecialchars
function safe_htmlspecialchars($value) {
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ===========================================
// PROCESSAMENTO DE PARÂMETROS DA URL
// ===========================================
$classificacao = isset($_GET['classificacao']) ? trim($_GET['classificacao']) : '';
$tipo = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$orderBy = isset($_GET['orderby']) ? trim($_GET['orderby']) : 'created_at';
$orderDir = isset($_GET['orderdir']) && $_GET['orderdir'] === 'ASC' ? 'ASC' : 'DESC';

// Configuração da paginação
$itensPorPagina = 15;
$paginaAtual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($paginaAtual - 1) * $itensPorPagina;

// ===========================================
// DETERMINAÇÃO DO TÍTULO E FILTROS DA PÁGINA
// ===========================================
$pageTitle = '';
$pageIcon = '';
$pageDescription = '';
$whereConditions = [];
$params = [];

if ($tipo === 'atraso') {
    $pageTitle = 'Empenhos em Atraso';
    $pageIcon = 'exclamation-triangle';
    $pageDescription = 'Empenhos com mais de 30 dias pendentes ou faturados';
    $whereConditions[] = "COALESCE(e.classificacao, 'Pendente') IN ('Pendente', 'Faturado') AND
        CASE
            WHEN e.data IS NOT NULL AND e.data != '0000-00-00' THEN DATEDIFF(CURDATE(), e.data)
            ELSE DATEDIFF(CURDATE(), DATE(e.created_at))
        END > 30";
} elseif ($classificacao) {
    $pageTitle = "Empenhos - " . safe_htmlspecialchars($classificacao);
    $pageIcon = getClassificacaoIcon($classificacao);
    $pageDescription = "Todos os empenhos com status: " . safe_htmlspecialchars($classificacao);
    $whereConditions[] = "COALESCE(e.classificacao, 'Pendente') = :classificacao";
    $params[':classificacao'] = $classificacao;
} else {
    // Redirecionamento se não há parâmetros válidos
    header("Location: consulta_empenho.php");
    exit();
}

// Filtro adicional por busca
if ($search) {
    $whereConditions[] = "(e.numero LIKE :search OR e.cliente_nome LIKE :search OR e.cliente_uasg LIKE :search OR e.pregao LIKE :search)";
    $params[':search'] = "%$search%";
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// ===========================================
// FUNÇÃO PARA ÍCONES DAS CLASSIFICAÇÕES
// ===========================================
function getClassificacaoIcon($classificacao) {
    $icons = [
        'Pendente' => 'clock',
        'Faturado' => 'file-invoice-dollar',
        'Entregue' => 'truck',
        'Liquidado' => 'calculator',
        'Pago' => 'check-circle',
        'Cancelado' => 'times-circle'
    ];
    return $icons[$classificacao] ?? 'list-alt';
}

// ===========================================
// PROCESSAMENTO DE AÇÕES (AJAX)
// ===========================================

// Processa busca de empenho específico para modal
if (isset($_GET['get_empenho_id'])) {
    $empenhoId = intval($_GET['get_empenho_id']);
    try {
        $stmt = $pdo->prepare("
            SELECT e.*, 
                CASE 
                    WHEN e.data IS NOT NULL AND e.data != '0000-00-00' THEN DATEDIFF(CURDATE(), e.data)
                    ELSE DATEDIFF(CURDATE(), DATE(e.created_at))
                END as dias_desde_empenho,
                CASE 
                    WHEN COALESCE(e.classificacao, 'Pendente') IN ('Pendente', 'Faturado') AND 
                         CASE 
                            WHEN e.data IS NOT NULL AND e.data != '0000-00-00' THEN DATEDIFF(CURDATE(), e.data)
                            ELSE DATEDIFF(CURDATE(), DATE(e.created_at))
                         END > 30 THEN 1
                    ELSE 0
                END as em_atraso
            FROM empenhos e 
            WHERE e.id = :id
        ");
        $stmt->bindParam(':id', $empenhoId);
        $stmt->execute();
        $empenho = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($empenho) {
            $empenho['data_formatada'] = $empenho['data'] && $empenho['data'] != '0000-00-00' 
                ? date('d/m/Y', strtotime($empenho['data'])) 
                : 'N/A';
            $empenho['dias_atraso'] = $empenho['em_atraso'] ? ($empenho['dias_desde_empenho'] - 30) : 0;
            
            header('Content-Type: application/json');
            echo json_encode($empenho);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Empenho não encontrado']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// ===========================================
// CONSULTA PRINCIPAL COM ESTATÍSTICAS
// ===========================================
$error = '';
$empenhos = [];
$totalRegistros = 0;
$totalPaginas = 0;

try {
    // Conta total de registros para paginação
    $sqlCount = "SELECT COUNT(*) as total FROM empenhos e $whereClause";
    $stmtCount = $pdo->prepare($sqlCount);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $totalRegistros = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPaginas = ceil($totalRegistros / $itensPorPagina);

    // Validação da ordenação
    $allowedOrderBy = ['numero', 'cliente_nome', 'valor_total_empenho', 'data', 'created_at', 'classificacao', 'dias_desde_empenho'];
    if (!in_array($orderBy, $allowedOrderBy)) {
        $orderBy = 'created_at';
    }

    // Consulta os empenhos com paginação e ordenação
    $sql = "SELECT 
            e.id, 
            e.numero, 
            e.cliente_nome, 
            e.cliente_uasg,
            e.pregao,
            e.observacao,
            e.cnpj,
            e.prioridade,
            e.upload,
            COALESCE(e.valor_total_empenho, e.valor_total, 0) as valor_total_empenho, 
            COALESCE(e.classificacao, 'Pendente') as classificacao,
            e.created_at, 
            e.data,
            CASE 
                WHEN e.data IS NOT NULL AND e.data != '0000-00-00' THEN DATEDIFF(CURDATE(), e.data)
                ELSE DATEDIFF(CURDATE(), DATE(e.created_at))
            END as dias_desde_empenho,
            CASE 
                WHEN e.data IS NOT NULL AND e.data != '0000-00-00' THEN DATE_FORMAT(e.data, '%d/%m/%Y')
                ELSE 'N/A'
            END as data_formatada,
            DATE_FORMAT(e.created_at, '%d/%m/%Y %H:%i') as data_cadastro,
            
            -- Cálculo do lucro total simplificado
            COALESCE((
                SELECT SUM(
                    (ep.quantidade * COALESCE(ep.valor_unitario, 0)) - 
                    (ep.quantidade * COALESCE(p.custo_total, 0))
                )
                FROM empenho_produtos ep 
                LEFT JOIN produtos p ON ep.produto_id = p.id 
                WHERE ep.empenho_id = e.id
            ), 0) as lucro_total_valor,
            
            -- Contador de produtos
            COALESCE((
                SELECT COUNT(*)
                FROM empenho_produtos ep 
                WHERE ep.empenho_id = e.id
            ), 0) as total_produtos,
            
            -- Valor total de custo
            COALESCE((
                SELECT SUM(ep.quantidade * COALESCE(p.custo_total, 0))
                FROM empenho_produtos ep 
                LEFT JOIN produtos p ON ep.produto_id = p.id 
                WHERE ep.empenho_id = e.id
            ), 0) as valor_total_custo,
            
            -- Indicador de atraso
            CASE 
                WHEN COALESCE(e.classificacao, 'Pendente') IN ('Pendente', 'Faturado') AND 
                     CASE 
                        WHEN e.data IS NOT NULL AND e.data != '0000-00-00' THEN DATEDIFF(CURDATE(), e.data)
                        ELSE DATEDIFF(CURDATE(), DATE(e.created_at))
                     END > 30 THEN 1
                ELSE 0
            END as em_atraso
            
            FROM empenhos e 
            $whereClause
            ORDER BY $orderBy $orderDir
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $itensPorPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $empenhos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcula estatísticas gerais
    $valorTotal = array_sum(array_column($empenhos, 'valor_total_empenho'));
    $lucroTotal = array_sum(array_column($empenhos, 'lucro_total_valor'));
    $custoTotal = array_sum(array_column($empenhos, 'valor_total_custo'));
    $totalProdutos = array_sum(array_column($empenhos, 'total_produtos'));
    $empenhosAtrasados = array_sum(array_column($empenhos, 'em_atraso'));

    // Calcula margem de lucro geral
    $margemLucroGeral = $valorTotal > 0 ? (($lucroTotal / $valorTotal) * 100) : 0;

} catch (PDOException $e) {
    $error = "Erro na consulta: " . $e->getMessage();
    $empenhos = [];
    $valorTotal = 0;
    $lucroTotal = 0;
    $custoTotal = 0;
    $totalProdutos = 0;
    $empenhosAtrasados = 0;
    $margemLucroGeral = 0;
}

// ===========================================
// ESTATÍSTICAS ADICIONAIS POR PERÍODO
// ===========================================
try {
    // Empenhos do mês atual
    $sqlMesAtual = "SELECT COUNT(*) as total, SUM(COALESCE(valor_total_empenho, valor_total, 0)) as valor
                    FROM empenhos e
                    WHERE MONTH(e.created_at) = MONTH(CURDATE())
                    AND YEAR(e.created_at) = YEAR(CURDATE())
                    " . ($whereClause ? " AND " . str_replace("WHERE ", "", $whereClause) : "");
    
    $stmtMes = $pdo->prepare($sqlMesAtual);
    foreach ($params as $key => $value) {
        if ($key !== ':limit' && $key !== ':offset') {
            $stmtMes->bindValue($key, $value);
        }
    }
    $stmtMes->execute();
    $estatisticasMes = $stmtMes->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $estatisticasMes = ['total' => 0, 'valor' => 0];
}

// Inclui o header do sistema se existir
if (file_exists('includes/header_template.php')) {
    include('includes/header_template.php');
    renderHeader($pageTitle . " - LicitaSis", "empenhos");
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - LicitaSis</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
    /* ===========================================
       VARIÁVEIS CSS E RESET (PADRÃO LICITASIS)
       =========================================== */
    :root {
        --primary-color: #2D893E;
        --primary-light: #9DCEAC;
        --primary-dark: #1e6e2d;
        --secondary-color: #00bfae;
        --secondary-dark: #009d8f;
        --danger-color: #dc3545;
        --success-color: #28a745;
        --warning-color: #ffc107;
        --info-color: #17a2b8;
        --light-gray: #f8f9fa;
        --medium-gray: #6c757d;
        --dark-gray: #343a40;
        --border-color: #dee2e6;
        --shadow: 0 4px 12px rgba(0,0,0,0.1);
        --shadow-hover: 0 6px 15px rgba(0,0,0,0.15);
        --radius: 12px;
        --radius-sm: 8px;
        --transition: all 0.3s ease;
        
        /* Cores específicas para status */
        --pendente-color: #fd7e14;
        --faturado-color: #007bff;
        --entregue-color: #20c997;
        --liquidado-color: #6f42c1;
        --pago-color: #28a745;
        --cancelado-color: #dc3545;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        min-height: 100vh;
        line-height: 1.6;
        color: var(--dark-gray);
    }

    /* ===========================================
       LAYOUT PRINCIPAL
       =========================================== */
    .container {
        max-width: 1400px;
        margin: 2.5rem auto;
        padding: 2.5rem;
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        transition: var(--transition);
        animation: fadeIn 0.5s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .container:hover {
        box-shadow: var(--shadow-hover);
        transform: translateY(-2px);
    }

    /* ===========================================
       CABEÇALHO DA PÁGINA
       =========================================== */
    .page-header {
        text-align: center;
        margin-bottom: 2rem;
        position: relative;
    }

    .breadcrumb {
        text-align: left;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .page-header h1 {
        color: var(--primary-color);
        font-size: 2.2rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1rem;
        margin-bottom: 1rem;
        position: relative;
    }

    .page-header h1::after {
        content: '';
        position: absolute;
        bottom: -0.5rem;
        left: 50%;
        transform: translateX(-50%);
        width: 100px;
        height: 3px;
        background: var(--secondary-color);
        border-radius: 2px;
    }

    .page-header h1 i {
        color: var(--secondary-color);
        font-size: 2rem;
    }

    .page-subtitle {
        color: var(--medium-gray);
        font-size: 1.1rem;
        margin-top: 1rem;
        font-weight: 500;
    }

    .page-description {
        color: var(--medium-gray);
        font-size: 0.95rem;
        margin-top: 0.5rem;
        font-style: italic;
    }

    /* ===========================================
       BOTÕES
       =========================================== */
    .btn {
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: var(--radius-sm);
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
        position: relative;
        overflow: hidden;
    }

    .btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
        transition: left 0.5s;
    }

    .btn:hover::before {
        left: 100%;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
        color: white;
        box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
    }

    .btn-primary:hover {
        background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
    }

    .btn-secondary {
        background: linear-gradient(135deg, var(--medium-gray) 0%, #5a6268 100%);
        color: white;
        box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
    }

    .btn-secondary:hover {
        background: linear-gradient(135deg, #5a6268 0%, var(--medium-gray) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(108, 117, 125, 0.3);
    }

    .btn-warning {
        background: linear-gradient(135deg, var(--warning-color) 0%, #e0a800 100%);
        color: #212529;
        box-shadow: 0 4px 8px rgba(255, 193, 7, 0.2);
    }

    .btn-warning:hover {
        background: linear-gradient(135deg, #e0a800 0%, var(--warning-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(255, 193, 7, 0.3);
    }

    .btn-success {
        background: linear-gradient(135deg, var(--success-color) 0%, #218838 100%);
        color: white;
        box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
    }

    .btn-success:hover {
        background: linear-gradient(135deg, #218838 0%, var(--success-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
    }

    .btn-danger {
        background: linear-gradient(135deg, var(--danger-color) 0%, #c82333 100%);
        color: white;
        box-shadow: 0 4px 8px rgba(220, 53, 69, 0.2);
    }

    .btn-danger:hover {
        background: linear-gradient(135deg, #c82333 0%, var(--danger-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(220, 53, 69, 0.3);
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
    }

    /* ===========================================
       RESUMO/ESTATÍSTICAS
       =========================================== */
    .resumo-detalhes {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
        padding: 1.5rem;
        background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
        border-radius: var(--radius);
        border-left: 4px solid var(--secondary-color);
    }

    .stat-item {
        text-align: center;
        padding: 1.5rem;
        background: white;
        border-radius: var(--radius-sm);
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: var(--transition);
        border-left: 4px solid transparent;
        position: relative;
        overflow: hidden;
    }

    .stat-item::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(45deg, rgba(0,191,174,0.05), rgba(45,137,62,0.05));
        transition: left 0.6s ease;
    }

    .stat-item:hover::before {
        left: 100%;
    }

    .stat-item:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow);
        border-left-color: var(--secondary-color);
    }

    .stat-number {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
    }

    .stat-label {
        font-size: 0.9rem;
        color: var(--medium-gray);
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .stat-item.destaque {
        border-left-color: var(--warning-color);
        background: linear-gradient(135deg, rgba(255, 193, 7, 0.05) 0%, rgba(255, 193, 7, 0.1) 100%);
    }

    .stat-item.destaque .stat-number {
        color: var(--warning-color);
    }

    .stat-item.lucro-positivo {
        border-left-color: var(--success-color);
        background: linear-gradient(135deg, rgba(40, 167, 69, 0.05) 0%, rgba(40, 167, 69, 0.1) 100%);
    }

    .stat-item.lucro-positivo .stat-number {
        color: var(--success-color);
    }

    .stat-item.lucro-negativo {
        border-left-color: var(--danger-color);
        background: linear-gradient(135deg, rgba(220, 53, 69, 0.05) 0%, rgba(220, 53, 69, 0.1) 100%);
    }

    .stat-item.lucro-negativo .stat-number {
        color: var(--danger-color);
    }

    /* ===========================================
       FILTROS E ORDENAÇÃO
       =========================================== */
    .filtros-ordenacao {
        display: grid;
        grid-template-columns: 1fr auto auto;
        gap: 1rem;
        align-items: end;
        margin-bottom: 2rem;
        padding: 1.5rem;
        background: white;
        border-radius: var(--radius);
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid var(--border-color);
    }

    .search-group {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .search-group label {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--medium-gray);
        text-transform: uppercase;
    }

    .search-input {
        padding: 0.75rem 1rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-sm);
        font-size: 1rem;
        transition: var(--transition);
        background: white;
    }

    .search-input:focus {
        outline: none;
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
    }

    .ordenacao-group {
        display: flex;
        gap: 0.5rem;
        align-items: end;
    }

    .select-ordenacao {
        padding: 0.75rem 1rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-sm);
        background: white;
        font-size: 0.9rem;
        transition: var(--transition);
        cursor: pointer;
    }

    .select-ordenacao:focus {
        outline: none;
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
    }

    /* ===========================================
       CARDS DE EMPENHOS
       =========================================== */
    .empenhos-detalhados {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .empenho-card {
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        transition: var(--transition);
        overflow: hidden;
        border: 1px solid var(--border-color);
        position: relative;
        animation: cardFadeIn 0.5s ease forwards;
        opacity: 0;
        transform: translateY(20px);
    }

    .empenho-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    }

    .empenho-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
        border-color: var(--secondary-color);
    }

    .empenho-card.em-atraso {
        border-left: 4px solid var(--danger-color);
        background: linear-gradient(135deg, rgba(220, 53, 69, 0.01) 0%, rgba(220, 53, 69, 0.03) 100%);
    }

    .empenho-card.em-atraso::before {
        background: linear-gradient(90deg, var(--danger-color), #ff6b6b);
    }

    @keyframes cardFadeIn {
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .empenho-card:nth-child(1) { animation-delay: 0.1s; }
    .empenho-card:nth-child(2) { animation-delay: 0.2s; }
    .empenho-card:nth-child(3) { animation-delay: 0.3s; }
    .empenho-card:nth-child(4) { animation-delay: 0.4s; }
    .empenho-card:nth-child(5) { animation-delay: 0.5s; }
    .empenho-card:nth-child(6) { animation-delay: 0.6s; }

    .empenho-header {
        padding: 1.5rem 1.5rem 1rem;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
    }

    .empenho-header h3 {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--primary-color);
        margin: 0;
        flex: 1;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .empenho-numero {
        color: var(--secondary-color);
        text-decoration: none;
        transition: var(--transition);
        cursor: pointer;
    }

    .empenho-numero:hover {
        color: var(--primary-color);
        text-decoration: underline;
    }

    .empenho-body {
        padding: 0 1.5rem 1rem;
    }

    .empenho-body p {
        margin-bottom: 0.75rem;
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .empenho-body strong {
        color: var(--dark-gray);
        font-weight: 600;
        min-width: 80px;
    }

    .empenho-body i {
        color: var(--secondary-color);
        width: 16px;
        text-align: center;
    }

    .empenho-actions {
        padding: 1rem 1.5rem 1.5rem;
        border-top: 1px solid var(--border-color);
        background: var(--light-gray);
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
    }

    /* ===========================================
       STATUS BADGES
       =========================================== */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-badge.pendente {
        background: rgba(253, 126, 20, 0.1);
        color: var(--pendente-color);
        border: 1px solid var(--pendente-color);
    }

    .status-badge.faturado {
        background: rgba(0, 123, 255, 0.1);
        color: var(--faturado-color);
        border: 1px solid var(--faturado-color);
    }

    .status-badge.entregue {
        background: rgba(32, 201, 151, 0.1);
        color: var(--entregue-color);
        border: 1px solid var(--entregue-color);
    }

    .status-badge.liquidado {
        background: rgba(111, 66, 193, 0.1);
        color: var(--liquidado-color);
        border: 1px solid var(--liquidado-color);
    }

    .status-badge.pago {
        background: rgba(40, 167, 69, 0.1);
        color: var(--pago-color);
        border: 1px solid var(--pago-color);
    }

    .status-badge.cancelado {
        background: rgba(220, 53, 69, 0.1);
        color: var(--cancelado-color);
        border: 1px solid var(--cancelado-color);
    }

    /* ===========================================
       INDICADORES ESPECIAIS
       =========================================== */
    .valor-highlight {
        color: var(--success-color);
        font-weight: 700;
        font-size: 1.1rem;
    }

    .lucro-positive {
        color: var(--success-color);
        font-weight: 700;
    }

    .lucro-negative {
        color: var(--danger-color);
        font-weight: 700;
    }

    .lucro-neutral {
        color: var(--medium-gray);
        font-weight: 600;
    }

    .atraso-indicator {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        background: rgba(220, 53, 69, 0.1);
        color: var(--danger-color);
        border: 1px solid var(--danger-color);
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.7; }
        100% { opacity: 1; }
    }

    .prioridade-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.25rem 0.75rem;
        border-radius: 15px;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .prioridade-badge.normal {
        background: rgba(108, 117, 125, 0.1);
        color: var(--medium-gray);
        border: 1px solid var(--medium-gray);
    }

    .prioridade-badge.alta {
        background: rgba(255, 193, 7, 0.1);
        color: var(--warning-color);
        border: 1px solid var(--warning-color);
    }

    .prioridade-badge.urgente {
        background: rgba(220, 53, 69, 0.1);
        color: var(--danger-color);
        border: 1px solid var(--danger-color);
        animation: pulse 2s infinite;
    }

    /* ===========================================
       PAGINAÇÃO
       =========================================== */
    .pagination-container {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 1rem;
        margin: 2rem 0;
        padding: 1.5rem;
        background: var(--light-gray);
        border-radius: var(--radius);
    }

    .pagination {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }

    .page-btn {
        padding: 0.5rem 1rem;
        border: 2px solid var(--border-color);
        background: white;
        color: var(--primary-color);
        border-radius: var(--radius-sm);
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        font-weight: 600;
        min-width: 40px;
        text-align: center;
    }

    .page-btn:hover {
        border-color: var(--secondary-color);
        background: var(--secondary-color);
        color: white;
        transform: translateY(-2px);
    }

    .page-btn.active {
        background: var(--secondary-color);
        color: white;
        border-color: var(--secondary-color);
    }

    .page-btn.disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }

    .pagination-info {
        color: var(--medium-gray);
        font-size: 0.9rem;
        font-weight: 500;
    }

    /* ===========================================
       MENSAGEM SEM RESULTADOS
       =========================================== */
    .no-results {
        text-align: center;
        color: var(--medium-gray);
        font-style: italic;
        padding: 4rem 2rem;
        font-size: 1.1rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 1rem;
        background: var(--light-gray);
        border-radius: var(--radius);
        border: 2px dashed var(--border-color);
        grid-column: 1 / -1;
    }

    .no-results i {
        font-size: 3rem;
        color: var(--secondary-color);
    }

    /* ===========================================
       ALERTAS
       =========================================== */
    .alert {
        padding: 1rem 1.5rem;
        border-radius: var(--radius);
        margin-bottom: 2rem;
        font-weight: 500;
        text-align: center;
        animation: slideInDown 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
    }

    .alert-danger {
        background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
        color: #721c24;
        border: 1px solid #f5c6cb;
        border-left: 4px solid var(--danger-color);
    }

    .alert-success {
        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
        color: #155724;
        border: 1px solid #c3e6cb;
        border-left: 4px solid var(--success-color);
    }

    .alert-info {
        background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
        color: #0c5460;
        border: 1px solid #bee5eb;
        border-left: 4px solid var(--info-color);
    }

    @keyframes slideInDown {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    /* ===========================================
       ARQUIVO LINK
       =========================================== */
    .arquivo-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--secondary-color);
        text-decoration: none;
        padding: 0.25rem 0.75rem;
        border: 1px solid var(--secondary-color);
        border-radius: var(--radius-sm);
        transition: var(--transition);
        font-size: 0.85rem;
    }

    .arquivo-link:hover {
        background: var(--secondary-color);
        color: white;
        transform: translateY(-1px);
    }

    /* ===========================================
       RESPONSIVIDADE
       =========================================== */
    @media (max-width: 1200px) {
        .container {
            margin: 2rem 1.5rem;
            padding: 2rem;
        }

        .empenhos-detalhados {
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 1rem;
        }

        .filtros-ordenacao {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .ordenacao-group {
            justify-content: center;
        }
    }

    @media (max-width: 768px) {
        .container {
            margin: 1.5rem 1rem;
            padding: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.8rem;
            flex-direction: column;
            gap: 0.5rem;
        }

        .breadcrumb {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .resumo-detalhes {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .empenhos-detalhados {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .empenho-actions {
            flex-direction: column;
        }

        .btn {
            width: 100%;
            justify-content: center;
        }

        .pagination-container {
            flex-direction: column;
            gap: 1rem;
        }
    }

    @media (max-width: 480px) {
        .container {
            margin: 1rem 0.5rem;
            padding: 1.25rem;
        }

        .page-header h1 {
            font-size: 1.5rem;
        }

        .resumo-detalhes {
            grid-template-columns: 1fr;
        }

        .empenho-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 1rem;
        }

        .stat-number {
            font-size: 1.5rem;
        }

        .empenho-body p {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.25rem;
        }
    }
    </style>
</head>
<body>
    <div class="container">
        <!-- ===========================================
             CABEÇALHO DA PÁGINA
             =========================================== -->
        <div class="page-header">
            <div class="breadcrumb">
                <a href="consulta_empenho.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar para Consulta
                </a>
                
                <a href="index.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-home"></i> Início
                </a>
                
                <?php if ($permissionManager->hasPagePermission('empenhos', 'create')): ?>
                <a href="cadastro_empenho.php" class="btn btn-success btn-sm">
                    <i class="fas fa-plus"></i> Novo Empenho
                </a>
                <?php endif; ?>
            </div>
            
            <h1>
                <i class="fas fa-<?php echo $pageIcon; ?>"></i>
                <?php echo $pageTitle; ?>
            </h1>
            
            <p class="page-subtitle">
                <?php echo $totalRegistros; ?> empenhos encontrados
                <?php if ($search): ?>
                    | Busca: "<?php echo safe_htmlspecialchars($search); ?>"
                <?php endif; ?>
            </p>
            
            <p class="page-description">
                <?php echo $pageDescription; ?>
            </p>
        </div>

        <!-- ===========================================
             MENSAGENS DE FEEDBACK
             =========================================== -->
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo safe_htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($tipo === 'atraso' && $totalRegistros > 0): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                Atenção: Estes empenhos estão há mais de 30 dias pendentes ou faturados e requerem atenção especial.
            </div>
        <?php endif; ?>

        <!-- ===========================================
             RESUMO ESTATÍSTICO AVANÇADO
             =========================================== -->
        <div class="resumo-detalhes">
            <div class="stat-item">
                <div class="stat-number"><?php echo $totalRegistros; ?></div>
                <div class="stat-label">Total de Empenhos</div>
            </div>
            
            <div class="stat-item destaque">
                <div class="stat-number">
                    R$ <?php echo number_format($valorTotal, 2, ',', '.'); ?>
                </div>
                <div class="stat-label">Valor Total</div>
            </div>

            <div class="stat-item <?php echo $lucroTotal >= 0 ? 'lucro-positivo' : 'lucro-negativo'; ?>">
                <div class="stat-number">
                    R$ <?php echo number_format($lucroTotal, 2, ',', '.'); ?>
                </div>
                <div class="stat-label">Lucro Total</div>
            </div>

            <?php if ($valorTotal > 0): ?>
            <div class="stat-item <?php echo $margemLucroGeral >= 15 ? 'lucro-positivo' : ($margemLucroGeral >= 5 ? '' : 'lucro-negativo'); ?>">
                <div class="stat-number">
                    <?php echo number_format($margemLucroGeral, 2, ',', '.'); ?>%
                </div>
                <div class="stat-label">Margem de Lucro</div>
            </div>
            <?php endif; ?>

            <div class="stat-item">
                <div class="stat-number"><?php echo $totalProdutos; ?></div>
                <div class="stat-label">Total de Produtos</div>
            </div>

            <?php if ($empenhosAtrasados > 0): ?>
            <div class="stat-item lucro-negativo">
                <div class="stat-number"><?php echo $empenhosAtrasados; ?></div>
                <div class="stat-label">Em Atraso</div>
            </div>
            <?php endif; ?>

            <!-- Estatísticas do mês atual -->
            <div class="stat-item">
                <div class="stat-number"><?php echo $estatisticasMes['total'] ?? 0; ?></div>
                <div class="stat-label">Este Mês</div>
            </div>

            <div class="stat-item">
                <div class="stat-number">
                    R$ <?php echo number_format($estatisticasMes['valor'] ?? 0, 0, ',', '.'); ?>
                </div>
                <div class="stat-label">Valor Mês Atual</div>
            </div>
        </div>

        <!-- ===========================================
             FILTROS E ORDENAÇÃO
             =========================================== -->
        <div class="filtros-ordenacao">
            <div class="search-group">
                <label for="search">Buscar nos resultados:</label>
                <input type="text" 
                       id="search" 
                       class="search-input"
                       placeholder="Número, cliente, UASG ou pregão..." 
                       value="<?php echo safe_htmlspecialchars($search); ?>"
                       onkeyup="filtrarResultados(this.value)">
            </div>
            
            <div class="ordenacao-group">
                <select id="orderBy" class="select-ordenacao" onchange="alterarOrdenacao()">
                    <option value="created_at" <?php echo $orderBy === 'created_at' ? 'selected' : ''; ?>>Data de Cadastro</option>
                    <option value="numero" <?php echo $orderBy === 'numero' ? 'selected' : ''; ?>>Número</option>
                    <option value="cliente_nome" <?php echo $orderBy === 'cliente_nome' ? 'selected' : ''; ?>>Cliente</option>
                    <option value="valor_total_empenho" <?php echo $orderBy === 'valor_total_empenho' ? 'selected' : ''; ?>>Valor</option>
                    <option value="data" <?php echo $orderBy === 'data' ? 'selected' : ''; ?>>Data Empenho</option>
                    <option value="dias_desde_empenho" <?php echo $orderBy === 'dias_desde_empenho' ? 'selected' : ''; ?>>Dias Empenho</option>
                </select>
                
                <button class="btn btn-secondary btn-sm" onclick="alternarDirecao()">
                    <i class="fas fa-sort-amount-<?php echo $orderDir === 'ASC' ? 'up' : 'down'; ?>"></i>
                    <?php echo $orderDir === 'ASC' ? 'Crescente' : 'Decrescente'; ?>
                </button>
            </div>
            
            <div>
                <button class="btn btn-warning" onclick="exportarDados()">
                    <i class="fas fa-download"></i> Exportar CSV
                </button>
            </div>
        </div>

        <!-- ===========================================
             LISTA DETALHADA DOS EMPENHOS
             =========================================== -->
        <div class="empenhos-detalhados" id="empenhosContainer">
            <?php if (count($empenhos) > 0): ?>
                <?php foreach ($empenhos as $empenho): ?>
                <div class="empenho-card <?php echo $empenho['em_atraso'] ? 'em-atraso' : ''; ?>" 
                     data-numero="<?php echo safe_htmlspecialchars($empenho['numero']); ?>"
                     data-cliente="<?php echo safe_htmlspecialchars($empenho['cliente_nome']); ?>"
                     data-uasg="<?php echo safe_htmlspecialchars($empenho['cliente_uasg']); ?>"
                     data-pregao="<?php echo safe_htmlspecialchars($empenho['pregao']); ?>">
                    
                    <div class="empenho-header">
                        <h3>
                            <span class="empenho-numero" onclick="abrirDetalhes(<?php echo $empenho['id']; ?>)">
                                <?php echo safe_htmlspecialchars($empenho['numero']); ?>
                            </span>
                        </h3>
                        
                        <div style="display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end;">
                            <span class="status-badge <?php echo strtolower($empenho['classificacao']); ?>">
                                <i class="fas fa-<?php echo getClassificacaoIcon($empenho['classificacao']); ?>"></i>
                                <?php echo $empenho['classificacao']; ?>
                            </span>
                            
                            <?php if ($empenho['prioridade'] && $empenho['prioridade'] !== 'Normal'): ?>
                            <span class="prioridade-badge <?php echo strtolower($empenho['prioridade']); ?>">
                                <i class="fas fa-<?php echo $empenho['prioridade'] === 'Urgente' ? 'exclamation' : 'arrow-up'; ?>"></i>
                                <?php echo $empenho['prioridade']; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="empenho-body">
                        <p>
                            <i class="fas fa-building"></i>
                            <strong>Cliente:</strong> 
                            <?php echo safe_htmlspecialchars($empenho['cliente_nome']); ?>
                        </p>
                        
                        <?php if ($empenho['cliente_uasg']): ?>
                        <p>
                            <i class="fas fa-id-card"></i>
                            <strong>UASG:</strong> 
                            <?php echo safe_htmlspecialchars($empenho['cliente_uasg']); ?>
                        </p>
                        <?php endif; ?>

                        <p>
                            <i class="fas fa-dollar-sign"></i>
                            <strong>Valor:</strong> 
                            <span class="valor-highlight">
                                R$ <?php echo number_format($empenho['valor_total_empenho'], 2, ',', '.'); ?>
                            </span>
                        </p>

                        <?php if ($empenho['lucro_total_valor'] != 0): ?>
                        <p>
                            <i class="fas fa-chart-line"></i>
                            <strong>Lucro:</strong> 
                            <span class="<?php echo $empenho['lucro_total_valor'] >= 0 ? 'lucro-positive' : 'lucro-negative'; ?>">
                                R$ <?php echo number_format($empenho['lucro_total_valor'], 2, ',', '.'); ?>
                            </span>
                        </p>
                        <?php endif; ?>

                        <p>
                            <i class="fas fa-calendar"></i>
                            <strong>Data Empenho:</strong> 
                            <?php echo $empenho['data_formatada']; ?>
                        </p>
                        
                        <p>
                            <i class="fas fa-clock"></i>
                            <strong>Tempo:</strong> 
                            <?php echo $empenho['dias_desde_empenho']; ?> dias desde o empenho
                        </p>

                        <?php if ($empenho['em_atraso']): ?>
                        <p>
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Status:</strong>
                            <span class="atraso-indicator">
                                <i class="fas fa-exclamation-triangle"></i>
                                <?php echo ($empenho['dias_desde_empenho'] - 30); ?> dias de atraso
                            </span>
                        </p>
                        <?php endif; ?>

                        <?php if ($empenho['pregao']): ?>
                        <p>
                            <i class="fas fa-gavel"></i>
                            <strong>Pregão:</strong> 
                            <?php echo safe_htmlspecialchars($empenho['pregao']); ?>
                        </p>
                        <?php endif; ?>

                        <p>
                            <i class="fas fa-shopping-cart"></i>
                            <strong>Produtos:</strong> 
                            <?php echo $empenho['total_produtos']; ?> item(ns)
                        </p>

                        <p>
                            <i class="fas fa-calendar-plus"></i>
                            <strong>Cadastrado:</strong> 
                            <?php echo $empenho['data_cadastro']; ?>
                        </p>

                        <?php if ($empenho['observacao']): ?>
                        <p>
                            <i class="fas fa-comment"></i>
                            <strong>Obs:</strong> 
                            <small><?php echo safe_htmlspecialchars(substr($empenho['observacao'], 0, 100)); ?>
                            <?php if (strlen($empenho['observacao']) > 100): ?>...<?php endif; ?>
                            </small>
                        </p>
                        <?php endif; ?>

                        <?php if ($empenho['upload']): ?>
                        <p>
                            <i class="fas fa-paperclip"></i>
                            <strong>Arquivo:</strong>
                            <a href="<?php echo safe_htmlspecialchars($empenho['upload']); ?>" target="_blank" class="arquivo-link">
                                <i class="fas fa-eye"></i> Ver arquivo
                            </a>
                        </p>
                        <?php endif; ?>
                    </div>
                    
                   
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-results">
                    <i class="fas fa-search"></i>
                    <p>Nenhum empenho encontrado para este filtro.</p>
                    <small>Tente ajustar os critérios de busca ou volte para a consulta principal.</small>
                    <div style="margin-top: 1rem;">
                        <a href="consulta_empenho.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Voltar para Consulta
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===========================================
             PAGINAÇÃO
             =========================================== -->
        <?php if ($totalPaginas > 1): ?>
        <div class="pagination-container">
            <div class="pagination-info">
                Mostrando <?php echo (($paginaAtual - 1) * $itensPorPagina + 1); ?> a 
                <?php echo min($paginaAtual * $itensPorPagina, $totalRegistros); ?> de 
                <?php echo $totalRegistros; ?> empenhos
            </div>
            
            <div class="pagination">
                <!-- Botão Anterior -->
                <?php if ($paginaAtual > 1): ?>
                    <?php 
                    $prevParams = $_GET;
                    $prevParams['pagina'] = $paginaAtual - 1;
                    ?>
                    <a href="?<?php echo http_build_query($prevParams); ?>" class="page-btn">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php else: ?>
                    <span class="page-btn disabled">
                        <i class="fas fa-chevron-left"></i>
                    </span>
                <?php endif; ?>

                <!-- Números das páginas -->
                <?php
                $inicio = max(1, $paginaAtual - 2);
                $fim = min($totalPaginas, $paginaAtual + 2);
                
                if ($inicio > 1): 
                    $firstParams = $_GET;
                    $firstParams['pagina'] = 1;
                ?>
                    <a href="?<?php echo http_build_query($firstParams); ?>" class="page-btn">1</a>
                    <?php if ($inicio > 2): ?>
                        <span class="page-btn disabled">...</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $inicio; $i <= $fim; $i++): 
                    $pageParams = $_GET;
                    $pageParams['pagina'] = $i;
                ?>
                    <a href="?<?php echo http_build_query($pageParams); ?>" 
                       class="page-btn <?php echo $i == $paginaAtual ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($fim < $totalPaginas): ?>
                    <?php if ($fim < $totalPaginas - 1): ?>
                        <span class="page-btn disabled">...</span>
                    <?php endif; ?>
                    <?php 
                    $lastParams = $_GET;
                    $lastParams['pagina'] = $totalPaginas;
                    ?>
                    <a href="?<?php echo http_build_query($lastParams); ?>" class="page-btn"><?php echo $totalPaginas; ?></a>
                <?php endif; ?>

                <!-- Botão Próximo -->
                <?php if ($paginaAtual < $totalPaginas): ?>
                    <?php 
                    $nextParams = $_GET;
                    $nextParams['pagina'] = $paginaAtual + 1;
                    ?>
                    <a href="?<?php echo http_build_query($nextParams); ?>" class="page-btn">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="page-btn disabled">
                        <i class="fas fa-chevron-right"></i>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===========================================
             BOTÕES DE AÇÃO ADICIONAIS
             =========================================== -->
        <div style="text-align: center; margin-top: 2rem; padding-top: 2rem; border-top: 2px solid var(--border-color);">
            <a href="consulta_empenho.php" class="btn btn-secondary" style="margin-right: 1rem;">
                <i class="fas fa-arrow-left"></i> Voltar para Consulta
            </a>
            
            <button class="btn btn-warning" onclick="exportarDados()" style="margin-right: 1rem;">
                <i class="fas fa-download"></i> Exportar CSV
            </button>
            
            <button class="btn btn-primary" onclick="imprimirRelatorio()">
                <i class="fas fa-print"></i> Imprimir Relatório
            </button>
        </div>
    </div>

    <script>
        // ===========================================
        // VARIÁVEIS GLOBAIS
        // ===========================================
        let currentOrderBy = '<?php echo $orderBy; ?>';
        let currentOrderDir = '<?php echo $orderDir; ?>';
        let originalEmpenhos = [];

        // ===========================================
        // FUNÇÕES DE INTERAÇÃO
        // ===========================================

        /**
         * Abre detalhes do empenho no modal da página principal
         */
        function abrirDetalhes(empenhoId) {
            // Abre na página principal com o modal
            window.open(`consulta_empenho.php?modal=${empenhoId}`, '_blank');
        }

        /**
         * Edita empenho (redireciona para página de edição)
         */
        function editarEmpenho(empenhoId) {
            // Redireciona para página de edição ou abre modal
            window.location.href = `editar_empenho.php?id=${empenhoId}`;
        }

        /**
         * Imprime empenho específico
         */
        function imprimirEmpenho(empenhoId) {
            // Busca dados do empenho
            fetch(`detalhes_empenhos.php?get_empenho_id=${empenhoId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        showToast('Erro ao carregar dados do empenho: ' + data.error, 'error');
                        return;
                    }
                    
                    const printWindow = window.open('', '_blank');
                    const printContent = `
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <title>Empenho ${data.numero}</title>
                            <style>
                                body { font-family: Arial, sans-serif; margin: 2rem; color: #333; }
                                .header { text-align: center; margin-bottom: 2rem; border-bottom: 2px solid #333; padding-bottom: 1rem; }
                                .section { margin-bottom: 2rem; page-break-inside: avoid; }
                                .section h3 { background: #f0f0f0; padding: 0.5rem; margin-bottom: 1rem; border-left: 4px solid #2D893E; }
                                .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
                                .item { margin-bottom: 0.5rem; }
                                .label { font-weight: bold; color: #2D893E; }
                                .value { margin-left: 1rem; }
                                .destaque { color: #2D893E; font-weight: bold; }
                                .atraso { color: #dc3545; font-weight: bold; }
                                @media print { body { margin: 0; } }
                            </style>
                        </head>
                        <body>
                            <div class="header">
                                <h1>EMPENHO Nº ${data.numero}</h1>
                                <p><strong>Data de Impressão:</strong> ${new Date().toLocaleDateString('pt-BR')} às ${new Date().toLocaleTimeString('pt-BR')}</p>
                            </div>
                            
                            <div class="section">
                                <h3>📋 Informações Básicas</h3>
                                <div class="grid">
                                    <div class="item"><span class="label">Status:</span><span class="value">${data.classificacao}</span></div>
                                    <div class="item"><span class="label">Prioridade:</span><span class="value">${data.prioridade || 'Normal'}</span></div>
                                    <div class="item"><span class="label">Data Empenho:</span><span class="value">${data.data_formatada}</span></div>
                                    <div class="item"><span class="label">Valor Total:</span><span class="value destaque">R$ ${parseFloat(data.valor_total_empenho || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</span></div>
                                </div>
                            </div>
                            
                            <div class="section">
                                <h3>🏢 Cliente</h3>
                                <div class="grid">
                                    <div class="item"><span class="label">Nome:</span><span class="value">${data.cliente_nome}</span></div>
                                    <div class="item"><span class="label">UASG:</span><span class="value">${data.cliente_uasg || 'N/A'}</span></div>
                                    ${data.cnpj ? `<div class="item"><span class="label">CNPJ:</span><span class="value">${data.cnpj}</span></div>` : ''}
                                </div>
                            </div>
                            
                            ${data.em_atraso ? `
                            <div class="section">
                                <h3 class="atraso">⚠️ Situação de Prazo</h3>
                                <p class="atraso">EMPENHO EM ATRASO: ${data.dias_atraso} dias além do prazo normal</p>
                                <p><strong>Ação recomendada:</strong> Verificar status junto ao cliente e atualizar classificação.</p>
                            </div>
                            ` : ''}
                            
                            <div class="section">
                                <h3>ℹ️ Observações</h3>
                                <p><strong>Sistema:</strong> LicitaSis - Sistema de Gestão de Licitações</p>
                                <p><strong>Relatório gerado em:</strong> ${new Date().toLocaleString('pt-BR')}</p>
                            </div>
                        </body>
                        </html>
                    `;
                    
                    printWindow.document.write(printContent);
                    printWindow.document.close();
                    printWindow.print();
                })
                .catch(error => {
                    console.error('Erro ao imprimir empenho:', error);
                    showToast('Erro ao imprimir empenho.', 'error');
                });
        }

        /**
         * Exporta dados para CSV
         */
        function exportarDados() {
            const empenhos = [];
            const cards = document.querySelectorAll('.empenho-card:not([style*="display: none"])');
            
            cards.forEach(card => {
                const numero = card.querySelector('h3 .empenho-numero').textContent.trim();
                const textos = card.querySelectorAll('.empenho-body p');
                
                let cliente = '', valor = '', lucro = '', data = '', status = '', produtos = '';
                textos.forEach(p => {
                    const texto = p.textContent;
                    if (texto.includes('Cliente:')) cliente = texto.replace('Cliente:', '').trim();
                    if (texto.includes('Valor:')) valor = texto.replace('Valor:', '').trim();
                    if (texto.includes('Lucro:')) lucro = texto.replace('Lucro:', '').trim();
                    if (texto.includes('Data Empenho:')) data = texto.replace('Data Empenho:', '').trim();
                    if (texto.includes('Produtos:')) produtos = texto.replace('Produtos:', '').trim();
                });

                const statusBadge = card.querySelector('.status-badge');
                status = statusBadge ? statusBadge.textContent.trim() : 'N/A';

                empenhos.push({
                    numero: numero,
                    cliente: cliente,
                    valor: valor,
                    lucro: lucro,
                    data: data,
                    status: status,
                    produtos: produtos
                });
            });

            const headers = ['Número', 'Cliente', 'Valor', 'Lucro', 'Data Empenho', 'Status', 'Produtos'];
            const csvContent = [
                headers.join(','),
                ...empenhos.map(row => [
                    `"${row.numero}"`,
                    `"${row.cliente}"`,
                    `"${row.valor}"`,
                    `"${row.lucro}"`,
                    `"${row.data}"`,
                    `"${row.status}"`,
                    `"${row.produtos}"`
                ].join(','))
            ].join('\n');

            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `empenhos_<?php echo strtolower(str_replace(' ', '_', $pageTitle)); ?>_${new Date().toISOString().split('T')[0]}.csv`;
            link.click();
            
            showToast('Dados exportados com sucesso!', 'success');
        }

        /**
         * Imprime relatório completo
         */
        function imprimirRelatorio() {
            const printWindow = window.open('', '_blank');
            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title><?php echo $pageTitle; ?> - Relatório</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 2rem; color: #333; }
                        .header { text-align: center; margin-bottom: 2rem; border-bottom: 2px solid #333; padding-bottom: 1rem; }
                        .resumo { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem; }
                        .stat { text-align: center; padding: 1rem; background: #f0f0f0; border-radius: 8px; }
                        .empenho { margin-bottom: 1.5rem; padding: 1rem; border: 1px solid #ddd; border-radius: 8px; page-break-inside: avoid; }
                        .empenho h4 { margin: 0 0 0.5rem 0; color: #2D893E; }
                        .empenho p { margin: 0.25rem 0; }
                        .empenho.atraso { border-left: 4px solid #dc3545; background: rgba(220, 53, 69, 0.05); }
                        @media print { body { margin: 0; } }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1><?php echo $pageTitle; ?></h1>
                        <p><strong>Data do Relatório:</strong> ${new Date().toLocaleDateString('pt-BR')} às ${new Date().toLocaleTimeString('pt-BR')}</p>
                        <p><strong>Total de Empenhos:</strong> <?php echo $totalRegistros; ?></p>
                        <p><strong>Descrição:</strong> <?php echo $pageDescription; ?></p>
                    </div>
                    
                    <div class="resumo">
                        <div class="stat">
                            <h3><?php echo $totalRegistros; ?></h3>
                            <p>Total de Empenhos</p>
                        </div>
                        <div class="stat">
                            <h3>R$ <?php echo number_format($valorTotal, 2, ',', '.'); ?></h3>
                            <p>Valor Total</p>
                        </div>
                        <div class="stat">
                            <h3>R$ <?php echo number_format($lucroTotal, 2, ',', '.'); ?></h3>
                            <p>Lucro Total</p>
                        </div>
                        <div class="stat">
                            <h3><?php echo number_format($margemLucroGeral, 2, ',', '.'); ?>%</h3>
                            <p>Margem de Lucro</p>
                        </div>
                    </div>

                    <h2>Detalhes dos Empenhos</h2>
                    ${Array.from(document.querySelectorAll('.empenho-card:not([style*="display: none"])')).map(card => {
                        const numero = card.querySelector('h3 .empenho-numero').textContent;
                        const status = card.querySelector('.status-badge').textContent.trim();
                        const textos = Array.from(card.querySelectorAll('.empenho-body p')).map(p => p.textContent).join('<br>');
                        const isAtraso = card.classList.contains('em-atraso') ? ' atraso' : '';
                        return `<div class="empenho${isAtraso}">
                            <h4>${numero} - ${status}</h4>
                            <div>${textos}</div>
                        </div>`;
                    }).join('')}
                    
                    <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #ddd; text-align: center; color: #666;">
                        <p><strong>Sistema:</strong> LicitaSis - Sistema de Gestão de Licitações</p>
                        <p><strong>Gerado em:</strong> ${new Date().toLocaleString('pt-BR')}</p>
                    </div>
                </body>
                </html>
            `;
            
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.print();
        }

        // ===========================================
        // FUNÇÕES DE FILTRO E ORDENAÇÃO
        // ===========================================

        /**
         * Filtra resultados em tempo real
         */
        function filtrarResultados(searchTerm) {
            const cards = document.querySelectorAll('.empenho-card');
            const searchLower = searchTerm.toLowerCase();
            let visibleCount = 0;

            cards.forEach(card => {
                const numero = card.dataset.numero?.toLowerCase() || '';
                const cliente = card.dataset.cliente?.toLowerCase() || '';
                const uasg = card.dataset.uasg?.toLowerCase() || '';
                const pregao = card.dataset.pregao?.toLowerCase() || '';

                const matches = numero.includes(searchLower) || 
                               cliente.includes(searchLower) || 
                               uasg.includes(searchLower) || 
                               pregao.includes(searchLower);

                if (matches) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            // Atualiza contador de resultados filtrados
            updateFilteredCount(visibleCount);
        }

        /**
         * Atualiza contador de resultados filtrados
         */
        function updateFilteredCount(count) {
            let countElement = document.getElementById('filtered-count');
            if (!countElement) {
                countElement = document.createElement('div');
                countElement.id = 'filtered-count';
                countElement.style.cssText = `
                    background: var(--info-color);
                    color: white;
                    padding: 0.5rem 1rem;
                    border-radius: var(--radius-sm);
                    margin: 1rem 0;
                    text-align: center;
                    font-weight: 600;
                `;
                document.querySelector('.empenhos-detalhados').insertAdjacentElement('beforebegin', countElement);
            }

            const searchInput = document.getElementById('search');
            if (searchInput.value.trim()) {
                countElement.innerHTML = `
                    <i class="fas fa-filter"></i>
                    Mostrando ${count} empenhos filtrados de <?php echo $totalRegistros; ?>
                `;
                countElement.style.display = 'block';
            } else {
                countElement.style.display = 'none';
            }
        }

        /**
         * Altera ordenação
         */
        function alterarOrdenacao() {
            const newOrderBy = document.getElementById('orderBy').value;
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.set('orderby', newOrderBy);
            currentUrl.searchParams.set('orderdir', currentOrderDir);
            currentUrl.searchParams.delete('pagina'); // Reset para primeira página
            window.location.href = currentUrl.toString();
        }

        /**
         * Alterna direção da ordenação
         */
        function alternarDirecao() {
            const newDirection = currentOrderDir === 'ASC' ? 'DESC' : 'ASC';
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.set('orderdir', newDirection);
            currentUrl.searchParams.delete('pagina'); // Reset para primeira página
            window.location.href = currentUrl.toString();
        }

        // ===========================================
        // FUNÇÕES UTILITÁRIAS
        // ===========================================

        /**
         * Exibe notificação toast
         */
        function showToast(message, type = 'info') {
            // Remove toast anterior se existir
            const existingToast = document.querySelector('.toast');
            if (existingToast) {
                existingToast.remove();
            }

            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            
            let backgroundColor, textColor, icon;
            switch(type) {
                case 'success':
                    backgroundColor = 'var(--success-color)';
                    textColor = 'white';
                    icon = 'check-circle';
                    break;
                case 'error':
                    backgroundColor = 'var(--danger-color)';
                    textColor = 'white';
                    icon = 'exclamation-circle';
                    break;
                case 'warning':
                    backgroundColor = 'var(--warning-color)';
                    textColor = '#333';
                    icon = 'exclamation-triangle';
                    break;
                default:
                    backgroundColor = 'var(--info-color)';
                    textColor = 'white';
                    icon = 'info-circle';
            }
            
            toast.innerHTML = `
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <i class="fas fa-${icon}"></i>
                    <span>${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: inherit; cursor: pointer; padding: 0.25rem; margin-left: 1rem; border-radius: 50%; transition: opacity 0.2s;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${backgroundColor};
                color: ${textColor};
                padding: 1rem 1.5rem;
                border-radius: var(--radius);
                box-shadow: var(--shadow);
                z-index: 1001;
                animation: slideInRight 0.3s ease;
                font-weight: 500;
                min-width: 300px;
                max-width: 400px;
            `;

            document.body.appendChild(toast);

            // Remove automaticamente após 4 segundos
            setTimeout(() => {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        /**
         * Limpa filtro de busca
         */
        function limparBusca() {
            document.getElementById('search').value = '';
            filtrarResultados('');
        }

        /**
         * Volta ao topo da página
         */
        function voltarAoTopo() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // ===========================================
        // INICIALIZAÇÃO E EVENT LISTENERS
        // ===========================================

        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Inicializando Sistema de Detalhes de Empenhos...');
            
            // Auto-focus no campo de pesquisa se vazio
            const searchInput = document.getElementById('search');
            if (searchInput && !searchInput.value) {
                setTimeout(() => searchInput.focus(), 500);
            }
            
            // Feedback visual para campo de pesquisa
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    if (this.value.length > 0) {
                        this.style.borderColor = 'var(--success-color)';
                        this.style.backgroundColor = 'rgba(40, 167, 69, 0.05)';
                    } else {
                        this.style.borderColor = 'var(--border-color)';
                        this.style.backgroundColor = 'white';
                    }
                });
            }
            
            // Adiciona contador de resultados se houver busca ativa
            if (searchInput && searchInput.value) {
                updateFilteredCount(document.querySelectorAll('.empenho-card').length);
            }
            
            // Adiciona botão de voltar ao topo se página for longa
            if (document.body.scrollHeight > window.innerHeight * 2) {
                addScrollToTopButton();
            }
            
            // Adiciona atalhos de teclado
            setupKeyboardShortcuts();
            
            // Inicializa sistema de animações
            initializeAnimations();
            
            console.log('✅ Sistema de Detalhes de Empenhos inicializado com sucesso!');
        });

        /**
         * Adiciona botão de voltar ao topo
         */
        function addScrollToTopButton() {
            const button = document.createElement('button');
            button.innerHTML = '<i class="fas fa-arrow-up"></i>';
            button.className = 'scroll-to-top';
            button.onclick = voltarAoTopo;
            button.style.cssText = `
                position: fixed;
                bottom: 2rem;
                right: 2rem;
                width: 50px;
                height: 50px;
                border-radius: 50%;
                background: var(--secondary-color);
                color: white;
                border: none;
                cursor: pointer;
                font-size: 1.2rem;
                box-shadow: var(--shadow);
                transition: var(--transition);
                z-index: 1000;
                opacity: 0;
                visibility: hidden;
            `;
            
            button.addEventListener('mouseenter', function() {
                this.style.background = 'var(--secondary-dark)';
                this.style.transform = 'scale(1.1)';
            });
            
            button.addEventListener('mouseleave', function() {
                this.style.background = 'var(--secondary-color)';
                this.style.transform = 'scale(1)';
            });
            
            document.body.appendChild(button);
            
            // Mostra/esconde baseado no scroll
            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 300) {
                    button.style.opacity = '1';
                    button.style.visibility = 'visible';
                } else {
                    button.style.opacity = '0';
                    button.style.visibility = 'hidden';
                }
            });
        }

        /**
         * Configura atalhos de teclado
         */
        function setupKeyboardShortcuts() {
            document.addEventListener('keydown', function(event) {
                // Ctrl + F para focar na busca
                if ((event.ctrlKey || event.metaKey) && event.key === 'f') {
                    event.preventDefault();
                    document.getElementById('search').focus();
                }
                
                // ESC para limpar busca
                if (event.key === 'Escape') {
                    limparBusca();
                }
                
                // Ctrl + E para exportar
                if ((event.ctrlKey || event.metaKey) && event.key === 'e') {
                    event.preventDefault();
                    exportarDados();
                }
                
                // Ctrl + P para imprimir
                if ((event.ctrlKey || event.metaKey) && event.key === 'p') {
                    event.preventDefault();
                    imprimirRelatorio();
                }
                
                // Ctrl + B para voltar
                if ((event.ctrlKey || event.metaKey) && event.key === 'b') {
                    event.preventDefault();
                    window.location.href = 'consulta_empenho.php';
                }
            });
        }

        /**
         * Inicializa sistema de animações
         */
        function initializeAnimations() {
            // Observador de interseção para animações on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);

            // Observa todos os cards de empenho
            document.querySelectorAll('.empenho-card').forEach(card => {
                observer.observe(card);
            });
        }

        // ===========================================
        // ESTILOS ADICIONAIS PARA TOAST E ANIMAÇÕES
        // ===========================================
        const additionalStyles = document.createElement('style');
        additionalStyles.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
            
            .scroll-to-top:hover {
                transform: translateY(-2px) scale(1.1) !important;
                box-shadow: var(--shadow-hover) !important;
            }
            
            .empenho-card.hidden {
                display: none !important;
            }
            
            .search-input:focus {
                animation: focusPulse 0.5s ease;
            }
            
            @keyframes focusPulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.02); }
                100% { transform: scale(1); }
            }
        `;
        document.head.appendChild(additionalStyles);

        // ===========================================
        // CONSOLE LOG PARA DEBUGGING
        // ===========================================
        console.log('📊 Sistema de Detalhes de Empenhos LicitaSis carregado:', {
            versao: '2.0 FINAL',
            data_versao: new Date().toLocaleString('pt-BR'),
            pagina: '<?php echo $pageTitle; ?>',
            total_empenhos: <?php echo $totalRegistros; ?>,
            valor_total: 'R$ <?php echo number_format($valorTotal, 2, ',', '.'); ?>',
            lucro_total: 'R$ <?php echo number_format($lucroTotal, 2, ',', '.'); ?>',
            margem_lucro: '<?php echo number_format($margemLucroGeral, 2, ',', '.'); ?>%',
            empenhos_atrasados: <?php echo $empenhosAtrasados; ?>,
            funcionalidades: [
                '✅ Visualização detalhada em cards responsivos',
                '✅ Filtro em tempo real',
                '✅ Ordenação dinâmica',
                '✅ Exportação para CSV',
                '✅ Impressão de relatórios',
                '✅ Paginação otimizada',
                '✅ Integração com modal de detalhes',
                '✅ Animações suaves',
                '✅ Atalhos de teclado',
                '✅ Responsividade completa',
                '✅ Toast notifications',
                '✅ Scroll to top',
                '✅ Sistema de permissões integrado'
            ]
        });
    </script>
</body>
</html>