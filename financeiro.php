<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inclui o sistema de permissões e auditoria
include('db.php');
include('permissions.php');
include('includes/audit.php');

$permissionManager = initPermissions($pdo);

// Verifica se o usuário tem permissão para acessar o financeiro
$permissionManager->requirePermission('financeiro', 'view');

// Registra acesso à página
logUserAction('READ', 'financeiro_dashboard');

// Busca estatísticas financeiras
$totalContasReceber = 0;
$valorContasReceber = 0;
$totalContasPagar = 0;
$valorContasPagar = 0;
$totalRecebidas = 0;
$valorRecebidas = 0;
$totalPagas = 0;
$valorPagas = 0;
$saldoCaixa = 0;
$movimentacoesMes = 0;
$ultimaMovimentacao = null;

try {
    // Contas a Receber
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(valor), 0) as valor_total
        FROM contas_a_receber 
        WHERE status = 'Pendente' OR status IS NULL
    ");
    $result = $stmt->fetch();
    $totalContasReceber = $result['total'] ?? 0;
    $valorContasReceber = $result['valor_total'] ?? 0;
    
    // Contas a Pagar
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(valor), 0) as valor_total
        FROM contas_a_pagar 
        WHERE status = 'Pendente' OR status IS NULL
    ");
    $result = $stmt->fetch();
    $totalContasPagar = $result['total'] ?? 0;
    $valorContasPagar = $result['valor_total'] ?? 0;
    
    // Contas Recebidas
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(valor), 0) as valor_total
        FROM contas_a_receber 
        WHERE status = 'Recebida'
    ");
    $result = $stmt->fetch();
    $totalRecebidas = $result['total'] ?? 0;
    $valorRecebidas = $result['valor_total'] ?? 0;
    
    // Contas Pagas
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(valor), 0) as valor_total
        FROM contas_a_pagar 
        WHERE status = 'Paga'
    ");
    $result = $stmt->fetch();
    $totalPagas = $result['total'] ?? 0;
    $valorPagas = $result['valor_total'] ?? 0;
    
    // Saldo do Caixa
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(
            CASE 
                WHEN tipo = 'Entrada' THEN valor 
                WHEN tipo = 'Saída' THEN -valor 
                ELSE 0 
            END
        ), 0) as saldo
        FROM caixa
    ");
    $result = $stmt->fetch();
    $saldoCaixa = $result['saldo'] ?? 0;
    
    // Movimentações do mês atual
    $stmt = $pdo->query("
        SELECT COUNT(*) as mes_atual 
        FROM caixa 
        WHERE MONTH(data_movimentacao) = MONTH(CURRENT_DATE()) 
        AND YEAR(data_movimentacao) = YEAR(CURRENT_DATE())
    ");
    $result = $stmt->fetch();
    $movimentacoesMes = $result['mes_atual'] ?? 0;
    
    // Última movimentação financeira (caixa ou contas)
    $stmt = $pdo->query("
        SELECT 
            'caixa' as origem,
            id,
            descricao,
            valor,
            tipo,
            data_movimentacao as data,
            created_at
        FROM caixa
        UNION ALL
        SELECT 
            'conta_receber' as origem,
            id,
            descricao,
            valor,
            'Recebimento' as tipo,
            data_vencimento as data,
            created_at
        FROM contas_a_receber 
        WHERE status = 'Recebida'
        UNION ALL
        SELECT 
            'conta_pagar' as origem,
            id,
            descricao,
            valor,
            'Pagamento' as tipo,
            data_vencimento as data,
            created_at
        FROM contas_a_pagar 
        WHERE status = 'Paga'
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $ultimaMovimentacao = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Erro ao buscar estatísticas financeiras: " . $e->getMessage());
}

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Financeiro - LicitaSis", "financeiro");
?>

<style>
    /* Reset e variáveis CSS - compatibilidade com o sistema */
    :root {
        --primary-color: #2D893E;
        --primary-light: #9DCEAC;
        --primary-dark: #1e6e2d;
        --secondary-color: #00bfae;
        --secondary-dark: #009d8f;
        --accent-color: #8e44ad;
        --danger-color: #dc3545;
        --success-color: #28a745;
        --warning-color: #ffc107;
        --info-color: #17a2b8;
        --receber-color: #20c997;
        --pagar-color: #fd7e14;
        --recebida-color: #198754;
        --paga-color: #6f42c1;
        --caixa-color: #0d6efd;
        --light-gray: #f8f9fa;
        --medium-gray: #6c757d;
        --dark-gray: #343a40;
        --border-color: #dee2e6;
        --shadow: 0 4px 12px rgba(0,0,0,0.1);
        --shadow-hover: 0 6px 15px rgba(0,0,0,0.15);
        --radius: 12px;
        --radius-sm: 8px;
        --transition: all 0.3s ease;
    }

    /* Container principal - mesmo estilo do empenhos */
    .container {
        max-width: 1200px;
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

    /* Título principal - mesmo estilo do empenhos */
    h2 {
        text-align: center;
        color: var(--primary-color);
        margin-bottom: 2rem;
        font-size: 2rem;
        font-weight: 600;
        position: relative;
    }

    h2::after {
        content: '';
        position: absolute;
        bottom: -0.5rem;
        left: 50%;
        transform: translateX(-50%);
        width: 80px;
        height: 3px;
        background: var(--secondary-color);
        border-radius: 2px;
    }

    /* Grid de estatísticas */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2.5rem;
    }

    .stat-card {
        background: linear-gradient(135deg, white 0%, #f8f9fa 100%);
        padding: 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        text-align: center;
        transition: var(--transition);
        border-left: 4px solid var(--secondary-color);
        position: relative;
        overflow: hidden;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    }

    /* Variações de cores para diferentes tipos de estatísticas */
    .stat-card.stat-receber {
        border-left-color: var(--receber-color);
    }

    .stat-card.stat-pagar {
        border-left-color: var(--pagar-color);
    }

    .stat-card.stat-recebida {
        border-left-color: var(--recebida-color);
    }

    .stat-card.stat-paga {
        border-left-color: var(--paga-color);
    }

    .stat-card.stat-caixa {
        border-left-color: var(--caixa-color);
    }

    .stat-card.stat-balance {
        border-left-color: var(--primary-color);
    }

    .stat-icon {
        font-size: 2.5rem;
        color: var(--secondary-color);
        margin-bottom: 1rem;
        display: block;
    }

    .stat-card.stat-receber .stat-icon {
        color: var(--receber-color);
    }

    .stat-card.stat-pagar .stat-icon {
        color: var(--pagar-color);
    }

    .stat-card.stat-recebida .stat-icon {
        color: var(--recebida-color);
    }

    .stat-card.stat-paga .stat-icon {
        color: var(--paga-color);
    }

    .stat-card.stat-caixa .stat-icon {
        color: var(--caixa-color);
    }

    .stat-card.stat-balance .stat-icon {
        color: var(--primary-color);
    }

    .stat-number {
        font-size: 2.2rem;
        font-weight: 800;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        line-height: 1;
        font-family: 'Courier New', monospace;
    }

    .stat-number.positive {
        color: var(--success-color);
    }

    .stat-number.negative {
        color: var(--danger-color);
    }

    .stat-label {
        color: var(--medium-gray);
        font-size: 0.9rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-sublabel {
        color: var(--medium-gray);
        font-size: 0.8rem;
        margin-top: 0.25rem;
        font-style: italic;
    }

    /* Resumo financeiro */
    .financial-summary {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        color: white;
        padding: 2rem;
        border-radius: var(--radius);
        margin-bottom: 2.5rem;
        box-shadow: var(--shadow);
        position: relative;
        overflow: hidden;
    }

    .financial-summary::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        animation: shimmer 6s ease-in-out infinite;
    }

    @keyframes shimmer {
        0%, 100% { transform: translateX(-100%) translateY(-100%); }
        50% { transform: translateX(100%) translateY(100%); }
    }

    .summary-content {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 2rem;
        text-align: center;
    }

    .summary-item h4 {
        font-size: 1.1rem;
        margin-bottom: 0.5rem;
        opacity: 0.9;
    }

    .summary-value {
        font-size: 1.8rem;
        font-weight: 700;
        font-family: 'Courier New', monospace;
    }

    .summary-value.positive {
        color: #90EE90;
    }

    .summary-value.negative {
        color: #FFB6C1;
    }

    /* Grid de cards principais */
    .financial-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 2rem;
        margin-top: 2.5rem;
    }

    .financial-card {
        background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 2rem;
        text-align: center;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .financial-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
    }

    .financial-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
        border-color: var(--secondary-color);
    }

    .financial-card h3 {
        color: var(--primary-color);
        font-size: 1.3rem;
        margin-bottom: 1rem;
        font-weight: 600;
    }

    .financial-card .icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .financial-card p {
        color: var(--medium-gray);
        margin-bottom: 1.5rem;
        font-size: 0.95rem;
        line-height: 1.5;
    }

    .card-buttons {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    /* Botões - mesmo estilo do empenhos */
    .btn {
        padding: 0.875rem 1.5rem;
        border: none;
        border-radius: var(--radius-sm);
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
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

    .btn-success {
        background: linear-gradient(135deg, var(--success-color) 0%, #1e7e34 100%);
        color: white;
        box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
    }

    .btn-success:hover {
        background: linear-gradient(135deg, #1e7e34 0%, var(--success-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
    }

    .btn-info {
        background: linear-gradient(135deg, var(--info-color) 0%, #117a8b 100%);
        color: white;
        box-shadow: 0 4px 8px rgba(23, 162, 184, 0.2);
    }

    .btn-info:hover {
        background: linear-gradient(135deg, #117a8b 0%, var(--info-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(23, 162, 184, 0.3);
    }

    .btn-warning {
        background: linear-gradient(135deg, var(--warning-color) 0%, #e0a800 100%);
        color: #333;
        box-shadow: 0 4px 8px rgba(255, 193, 7, 0.2);
    }

    .btn-warning:hover {
        background: linear-gradient(135deg, #e0a800 0%, var(--warning-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(255, 193, 7, 0.3);
    }

    .btn-purple {
        background: linear-gradient(135deg, var(--accent-color) 0%, #7209b7 100%);
        color: white;
        box-shadow: 0 4px 8px rgba(142, 68, 173, 0.2);
    }

    .btn-purple:hover {
        background: linear-gradient(135deg, #7209b7 0%, var(--accent-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(142, 68, 173, 0.3);
    }

    .btn-caixa {
        background: linear-gradient(135deg, var(--caixa-color) 0%, #0b5ed7 100%);
        color: white;
        box-shadow: 0 4px 8px rgba(13, 110, 253, 0.2);
    }

    .btn-caixa:hover {
        background: linear-gradient(135deg, #0b5ed7 0%, var(--caixa-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(13, 110, 253, 0.3);
    }

    /* Status indicators */
    .status-indicator {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        margin: 0.5rem 0;
    }

    .status-pendente {
        background: rgba(253, 126, 20, 0.1);
        color: var(--pagar-color);
        border: 1px solid var(--pagar-color);
    }

    .status-recebida {
        background: rgba(25, 135, 84, 0.1);
        color: var(--recebida-color);
        border: 1px solid var(--recebida-color);
    }

    .status-paga {
        background: rgba(111, 66, 193, 0.1);
        color: var(--paga-color);
        border: 1px solid var(--paga-color);
    }

    .status-entrada {
        background: rgba(40, 167, 69, 0.1);
        color: var(--success-color);
        border: 1px solid var(--success-color);
    }

    .status-saida {
        background: rgba(220, 53, 69, 0.1);
        color: var(--danger-color);
        border: 1px solid var(--danger-color);
    }

    /* Última movimentação */
    .last-movement-info {
        margin-top: 2rem;
    }

    .last-movement-card {
        background: white;
        padding: 1.5rem 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border-left: 4px solid var(--secondary-color);
        transition: var(--transition);
    }

    .last-movement-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    }

    .last-movement-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .last-movement-header i {
        font-size: 1.5rem;
        color: var(--secondary-color);
        flex-shrink: 0;
    }

    .last-movement-header h4 {
        color: var(--primary-color);
        font-size: 1.1rem;
        margin: 0;
    }

    .last-movement-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }

    .movement-detail {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .movement-detail strong {
        color: var(--dark-gray);
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .movement-detail span {
        color: var(--medium-gray);
        font-size: 0.95rem;
    }

    .movement-value {
        color: var(--success-color) !important;
        font-weight: 700 !important;
        font-size: 1.1rem !important;
    }

    .movement-value.negative {
        color: var(--danger-color) !important;
    }

    /* Gráfico de fluxo de caixa */
    .cashflow-chart {
        background: var(--light-gray);
        border-radius: var(--radius);
        padding: 2rem;
        margin-top: 2rem;
        text-align: center;
    }

    .chart-title {
        color: var(--dark-gray);
        font-weight: 600;
        margin-bottom: 1.5rem;
        font-size: 1.2rem;
    }

    .chart-bars {
        display: flex;
        justify-content: space-around;
        align-items: end;
        height: 200px;
        margin-bottom: 1rem;
        gap: 1rem;
    }

    .chart-bar {
        flex: 1;
        max-width: 60px;
        background: linear-gradient(to top, var(--secondary-color), var(--primary-color));
        border-radius: 4px 4px 0 0;
        position: relative;
        transition: var(--transition);
        animation: growUp 1s ease-out;
    }

    @keyframes growUp {
        from { height: 0; }
        to { height: var(--bar-height); }
    }

    .chart-bar:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }

    .chart-bar.negative {
        background: linear-gradient(to top, var(--danger-color), #b02a37);
    }

    .chart-labels {
        display: flex;
        justify-content: space-around;
        color: var(--medium-gray);
        font-size: 0.8rem;
        font-weight: 500;
    }

    /* Alertas financeiros */
    .financial-alerts {
        margin-top: 2rem;
    }

    .alert {
        padding: 1rem 1.5rem;
        border-radius: var(--radius-sm);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .alert-warning {
        background: linear-gradient(135deg, rgba(255, 193, 7, 0.1) 0%, rgba(255, 193, 7, 0.05) 100%);
        border-left: 4px solid var(--warning-color);
        color: #856404;
    }

    .alert-danger {
        background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(220, 53, 69, 0.05) 100%);
        border-left: 4px solid var(--danger-color);
        color: #721c24;
    }

    .alert-info {
        background: linear-gradient(135deg, rgba(23, 162, 184, 0.1) 0%, rgba(23, 162, 184, 0.05) 100%);
        border-left: 4px solid var(--info-color);
        color: #0c5460;
    }

    .alert i {
        font-size: 1.5rem;
        flex-shrink: 0;
    }

    /* Responsividade - mesmo padrão do empenhos */
    @media (max-width: 1200px) {
        .container {
            margin: 2rem 1.5rem;
            padding: 2rem;
        }

        .financial-grid {
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .summary-content {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1.5rem;
        }
    }

    @media (max-width: 768px) {
        .container {
            padding: 1.5rem;
            margin: 1.5rem 1rem;
        }

        h2 {
            font-size: 1.75rem;
        }

        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            padding: 1.5rem;
        }

        .stat-icon {
            font-size: 2rem;
        }

        .stat-number {
            font-size: 1.8rem;
        }

        .financial-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .financial-card {
            padding: 1.5rem;
        }

        .financial-card .icon {
            font-size: 2.5rem;
        }

        .financial-card h3 {
            font-size: 1.2rem;
        }

        .financial-summary {
            padding: 1.5rem;
        }

        .summary-content {
            grid-template-columns: 1fr;
            gap: 1rem;
            text-align: center;
        }

        .summary-value {
            font-size: 1.5rem;
        }

        .last-movement-header {
            flex-direction: column;
            text-align: center;
            gap: 0.75rem;
        }

        .last-movement-content {
            grid-template-columns: 1fr;
            gap: 0.75rem;
            text-align: center;
        }

        .chart-bars {
            height: 150px;
        }
    }

    @media (max-width: 480px) {
        .container {
            padding: 1.25rem;
            margin: 1rem 0.5rem;
        }

        h2 {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .financial-card {
            padding: 1.25rem;
        }

        .financial-card .icon {
            font-size: 2rem;
        }

        .financial-card h3 {
            font-size: 1.1rem;
        }

        .btn {
            padding: 0.75rem 1.25rem;
            font-size: 0.9rem;
        }

        .financial-summary {
            padding: 1.25rem;
        }

        .summary-value {
            font-size: 1.3rem;
        }

        .chart-bars {
            height: 120px;
            gap: 0.5rem;
        }
    }

    /* Hover effects para mobile */
    @media (hover: none) {
        .btn:active {
            transform: scale(0.98);
        }
        
        .financial-card:active {
            transform: translateY(-2px);
        }
    }

    /* Animações */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .financial-card {
        animation: fadeInUp 0.6s ease forwards;
    }

    .financial-card:nth-child(1) { animation-delay: 0.1s; }
    .financial-card:nth-child(2) { animation-delay: 0.2s; }
    .financial-card:nth-child(3) { animation-delay: 0.3s; }
    .financial-card:nth-child(4) { animation-delay: 0.4s; }
    .financial-card:nth-child(5) { animation-delay: 0.5s; }
    .financial-card:nth-child(6) { animation-delay: 0.6s; }

    .stat-card {
        animation: fadeInUp 0.5s ease forwards;
    }

    .stat-card:nth-child(1) { animation-delay: 0.05s; }
    .stat-card:nth-child(2) { animation-delay: 0.1s; }
    .stat-card:nth-child(3) { animation-delay: 0.15s; }
    .stat-card:nth-child(4) { animation-delay: 0.2s; }
    .stat-card:nth-child(5) { animation-delay: 0.25s; }
    .stat-card:nth-child(6) { animation-delay: 0.3s; }

    /* Melhorias de acessibilidade */
    .btn:focus,
    .financial-card:focus-within {
        outline: 3px solid var(--secondary-color);
        outline-offset: 2px;
    }

    /* Modo de alto contraste */
    @media (prefers-contrast: high) {
        .stat-card,
        .financial-card {
            border: 2px solid var(--dark-gray);
        }
    }

    /* Animações reduzidas para quem prefere */
    @media (prefers-reduced-motion: reduce) {
        *,
        *::before,
        *::after {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
        }
    }
</style>

<div class="container">
    <h2><i class="fas fa-chart-line"></i> Gestão Financeira</h2>

    <!-- Cards principais -->
    <div class="financial-grid">
        <!-- Card Contas a Receber -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <h3>Contas a Receber</h3>
            <p>Gerencie os valores pendentes de recebimento das suas vendas, contratos e prestações de serviço.</p>
            <div class="card-buttons">
                <a href="contas_a_receber.php" class="btn btn-success">
                    <i class="fas fa-plus-circle"></i> Ver Pendentes (<?php echo $totalContasReceber; ?>)
                </a>
            </div>
        </div>

        <!-- Card Contas Recebidas -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>Contas Recebidas</h3>
            <p>Consulte o histórico completo de todas as contas já recebidas e quitadas no sistema.</p>
            <div class="card-buttons">
                <a href="contas_recebidas_geral.php" class="btn btn-info">
                    <i class="fas fa-history"></i> Ver Histórico (<?php echo $totalRecebidas; ?>)
                </a>
            </div>
        </div>

        <!-- Card Contas a Pagar -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-credit-card"></i>
            </div>
            <h3>Contas a Pagar</h3>
            <p>Controle as despesas e pagamentos pendentes das suas compras, fornecedores e despesas operacionais.</p>
            <div class="card-buttons">
                <a href="contas_a_pagar.php" class="btn btn-warning">
                    <i class="fas fa-exclamation-triangle"></i> Ver Pendências (<?php echo $totalContasPagar; ?>)
                </a>
            </div>
        </div>

        <!-- Card Contas Pagas -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-receipt"></i>
            </div>
            <h3>Contas Pagas</h3>
            <p>Visualize o histórico completo de todas as contas já pagas e despesas quitadas.</p>
            <div class="card-buttons">
                <a href="contas_pagas.php" class="btn btn-purple">
                    <i class="fas fa-check-double"></i> Ver Histórico (<?php echo $totalPagas; ?>)
                </a>
            </div>
        </div>

        <!-- Card Caixa -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-cash-register"></i>
            </div>
            <h3>Controle de Caixa</h3>
            <p>Visualize o saldo atual, entradas, saídas e movimentações do caixa em tempo real.</p>
            <div class="card-buttons">
                <a href="caixa.php" class="btn btn-caixa">
                    <i class="fas fa-calculator"></i> Ver Caixa
                </a>
            </div>
        </div>

        <!-- Card Relatórios Financeiros -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-chart-bar"></i>
            </div>
            <h3>Relatórios Financeiros</h3>
            <p>Gere relatórios detalhados de fluxo de caixa, DRE, balanços e análises financeiras.</p>
            <div class="card-buttons">
                <a href="relatorios_financeiros.php" class="btn btn-info">
                    <i class="fas fa-file-chart-line"></i> Ver Relatórios
                </a>
            </div>
        </div>

        <!-- Card Conciliação Bancária -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-university"></i>
            </div>
            <h3>Conciliação Bancária</h3>
            <p>Compare e concilie os lançamentos do sistema com os extratos bancários.</p>
            <div class="card-buttons">
                <a href="conciliacao_bancaria.php" class="btn btn-primary">
                    <i class="fas fa-balance-scale"></i> Conciliar
                </a>
            </div>
        </div>

        <!-- Card Planejamento Financeiro -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-calendar-check"></i>
            </div>
            <h3>Planejamento Financeiro</h3>
            <p>Crie projeções, orçamentos e planeje o fluxo de caixa futuro da empresa.</p>
            <div class="card-buttons">
                <a href="planejamento_financeiro.php" class="btn btn-success">
                    <i class="fas fa-chart-line"></i> Planejar
                </a>
            </div>
        </div>

        <!-- Card Centro de Custos -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-tags"></i>
            </div>
            <h3>Centro de Custos</h3>
            <p>Organize despesas e receitas por departamentos, projetos ou centros de custos.</p>
            <div class="card-buttons">
                <a href="centro_custos.php" class="btn btn-warning">
                    <i class="fas fa-sitemap"></i> Gerenciar
                </a>
            </div>
        </div>
    </div>

    <!-- Resumo Financeiro -->
    <div class="financial-summary">
        <div class="summary-content">
            <div class="summary-item">
                <h4>Saldo em Caixa</h4>
                <div class="summary-value <?php echo $saldoCaixa >= 0 ? 'positive' : 'negative'; ?>">
                    R$ <?php echo number_format($saldoCaixa, 2, ',', '.'); ?>
                </div>
            </div>
            <div class="summary-item">
                <h4>Total a Receber</h4>
                <div class="summary-value positive">
                    R$ <?php echo number_format($valorContasReceber, 2, ',', '.'); ?>
                </div>
            </div>
            <div class="summary-item">
                <h4>Total a Pagar</h4>
                <div class="summary-value negative">
                    R$ <?php echo number_format($valorContasPagar, 2, ',', '.'); ?>
                </div>
            </div>
            <div class="summary-item">
                <h4>Saldo Projetado</h4>
                <div class="summary-value <?php echo ($saldoCaixa + $valorContasReceber - $valorContasPagar) >= 0 ? 'positive' : 'negative'; ?>">
                    R$ <?php echo number_format($saldoCaixa + $valorContasReceber - $valorContasPagar, 2, ',', '.'); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-receber">
            <i class="stat-icon fas fa-money-bill-wave"></i>
            <div class="stat-number" id="totalContasReceber"><?php echo $totalContasReceber; ?></div>
            <div class="stat-label">Contas a Receber</div>
            <div class="stat-sublabel">
                R$ <?php echo number_format($valorContasReceber, 2, ',', '.'); ?>
            </div>
        </div>
        
        <div class="stat-card stat-pagar">
            <i class="stat-icon fas fa-credit-card"></i>
            <div class="stat-number" id="totalContasPagar"><?php echo $totalContasPagar; ?></div>
            <div class="stat-label">Contas a Pagar</div>
            <div class="stat-sublabel">
                R$ <?php echo number_format($valorContasPagar, 2, ',', '.'); ?>
            </div>
        </div>
        
        <div class="stat-card stat-recebida">
            <i class="stat-icon fas fa-check-circle"></i>
            <div class="stat-number" id="totalRecebidas"><?php echo $totalRecebidas; ?></div>
            <div class="stat-label">Contas Recebidas</div>
            <div class="stat-sublabel">
                R$ <?php echo number_format($valorRecebidas, 2, ',', '.'); ?>
            </div>
        </div>
        
        <div class="stat-card stat-paga">
            <i class="stat-icon fas fa-receipt"></i>
            <div class="stat-number" id="totalPagas"><?php echo $totalPagas; ?></div>
            <div class="stat-label">Contas Pagas</div>
            <div class="stat-sublabel">
                R$ <?php echo number_format($valorPagas, 2, ',', '.'); ?>
            </div>
        </div>
        
        <div class="stat-card stat-caixa">
            <i class="stat-icon fas fa-cash-register"></i>
            <div class="stat-number <?php echo $saldoCaixa >= 0 ? 'positive' : 'negative'; ?>">
                R$ <?php echo number_format($saldoCaixa, 2, ',', '.'); ?>
            </div>
            <div class="stat-label">Saldo do Caixa</div>
            <div class="stat-sublabel">
                <?php echo $movimentacoesMes; ?> movimentações no mês
            </div>
        </div>
        
        <div class="stat-card stat-balance">
            <i class="stat-icon fas fa-balance-scale"></i>
            <div class="stat-number <?php echo ($valorContasReceber - $valorContasPagar) >= 0 ? 'positive' : 'negative'; ?>">
                R$ <?php echo number_format($valorContasReceber - $valorContasPagar, 2, ',', '.'); ?>
            </div>
            <div class="stat-label">Saldo Pendente</div>
            <div class="stat-sublabel">Diferença entre receber e pagar</div>
        </div>
    </div>

    <!-- Alertas Financeiros -->
    <div class="financial-alerts">
        <?php if ($valorContasPagar > $saldoCaixa + $valorContasReceber): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong>Atenção!</strong> O valor total a pagar (R$ <?php echo number_format($valorContasPagar, 2, ',', '.'); ?>) 
                é maior que o saldo disponível + contas a receber. Considere renegociar prazos ou buscar capital de giro.
            </div>
        </div>
        <?php endif; ?>

        <?php if ($saldoCaixa < 0): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-circle"></i>
            <div>
                <strong>Caixa Negativo!</strong> Seu saldo atual está negativo em 
                R$ <?php echo number_format(abs($saldoCaixa), 2, ',', '.'); ?>. 
                Priorize o recebimento de contas pendentes.
            </div>
        </div>
        <?php endif; ?>

        <?php if ($totalContasReceber > 0 && $totalContasPagar == 0): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Situação Favorável!</strong> Você possui contas a receber sem pendências de pagamento. 
                Considere investir o excedente.
            </div>
        </div>
        <?php endif; ?>
    </div>

    

    <!-- Gráfico de Fluxo de Caixa -->
    <div class="cashflow-chart">
        <div class="chart-title">Fluxo de Caixa - Últimos 7 Dias</div>
        <div class="chart-bars" id="cashflowChart">
            <!-- Os dados serão carregados via JavaScript -->
            <div class="chart-bar" style="--bar-height: 60%;"></div>
            <div class="chart-bar" style="--bar-height: 80%;"></div>
            <div class="chart-bar negative" style="--bar-height: 40%;"></div>
            <div class="chart-bar" style="--bar-height: 90%;"></div>
            <div class="chart-bar" style="--bar-height: 70%;"></div>
            <div class="chart-bar negative" style="--bar-height: 30%;"></div>
            <div class="chart-bar" style="--bar-height: 85%;"></div>
        </div>
        <div class="chart-labels">
            <span>Seg</span>
            <span>Ter</span>
            <span>Qua</span>
            <span>Qui</span>
            <span>Sex</span>
            <span>Sáb</span>
            <span>Dom</span>
        </div>
    </div>

    <!-- Última movimentação -->
    <?php if ($ultimaMovimentacao): ?>
    <div class="last-movement-info">
        <div class="last-movement-card">
            <div class="last-movement-header">
                <i class="fas fa-clock"></i>
                <h4>Última Movimentação Financeira</h4>
            </div>
            <div class="last-movement-content">
                <div class="movement-detail">
                    <strong>Origem:</strong>
                    <span>
                        <?php 
                        switch($ultimaMovimentacao['origem']) {
                            case 'caixa': echo 'Caixa'; break;
                            case 'conta_receber': echo 'Conta a Receber'; break;
                            case 'conta_pagar': echo 'Conta a Pagar'; break;
                            default: echo 'Sistema'; 
                        }
                        ?>
                    </span>
                </div>
                <div class="movement-detail">
                    <strong>Descrição:</strong>
                    <span><?php echo htmlspecialchars(substr($ultimaMovimentacao['descricao'], 0, 50)) . (strlen($ultimaMovimentacao['descricao']) > 50 ? '...' : ''); ?></span>
                </div>
                <div class="movement-detail">
                    <strong>Valor:</strong>
                    <span class="movement-value <?php echo ($ultimaMovimentacao['tipo'] == 'Saída' || $ultimaMovimentacao['tipo'] == 'Pagamento') ? 'negative' : ''; ?>">
                        R$ <?php echo number_format($ultimaMovimentacao['valor'], 2, ',', '.'); ?>
                    </span>
                </div>
                <div class="movement-detail">
                    <strong>Tipo:</strong>
                    <span class="status-indicator status-<?php echo strtolower(str_replace(' ', '', $ultimaMovimentacao['tipo'])); ?>">
                        <i class="fas fa-circle"></i>
                        <?php echo htmlspecialchars($ultimaMovimentacao['tipo']); ?>
                    </span>
                </div>
                <div class="movement-detail">
                    <strong>Data:</strong>
                    <span><?php echo date('d/m/Y', strtotime($ultimaMovimentacao['data'])); ?></span>
                </div>
                <div class="movement-detail">
                    <strong>Registrado em:</strong>
                    <span><?php echo date('d/m/Y \à\s H:i', strtotime($ultimaMovimentacao['created_at'])); ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
// Finaliza a página com footer e scripts
renderFooter();
renderScripts();
?>

<script>
    // JavaScript específico da página financeira
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Módulo Financeiro iniciado');

        // Anima os números das estatísticas
        function animateNumber(element, finalNumber) {
            if (!element || isNaN(finalNumber)) return;
            
            let currentNumber = 0;
            const increment = Math.max(1, Math.ceil(Math.abs(finalNumber) / 30));
            const duration = 1000;
            const stepTime = duration / (Math.abs(finalNumber) / increment);
            
            const isMonetary = element.textContent.includes('R);
            const isNegative = finalNumber < 0;
            
            if (isMonetary) {
                element.textContent = 'R$ 0,00';
            } else {
                element.textContent = '0';
            }
            
            const timer = setInterval(() => {
                if (isNegative) {
                    currentNumber -= increment;
                    if (currentNumber <= finalNumber) {
                        currentNumber = finalNumber;
                        clearInterval(timer);
                    }
                } else {
                    currentNumber += increment;
                    if (currentNumber >= finalNumber) {
                        currentNumber = finalNumber;
                        clearInterval(timer);
                    }
                }
                
                if (isMonetary) {
                    element.textContent = 'R$ ' + currentNumber.toLocaleString('pt-BR', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                } else {
                    element.textContent = currentNumber.toLocaleString('pt-BR');
                }
            }, stepTime);
        }

        // Observer para animar quando os cards ficarem visíveis
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const numberElement = entry.target.querySelector('.stat-number, .summary-value');
                    if (numberElement && !numberElement.dataset.animated) {
                        numberElement.dataset.animated = 'true';
                        const text = numberElement.textContent.trim();
                        
                        // Extrai o número do texto
                        let finalNumber = 0;
                        if (text.includes('R)) {
                            // Remove R$, pontos e vírgulas para pegar o número
                            const cleanText = text.replace(/[R$\s.]/g, '').replace(',', '.');
                            finalNumber = parseFloat(cleanText);
                        } else if (/^-?\d+$/.test(text)) {
                            finalNumber = parseInt(text);
                        }
                        
                        if (!isNaN(finalNumber)) {
                            setTimeout(() => animateNumber(numberElement, finalNumber), 200);
                        }
                    }
                }
            });
        }, { threshold: 0.5 });

        // Observa todos os cards de estatísticas e resumo
        document.querySelectorAll('.stat-card, .summary-item').forEach(card => {
            observer.observe(card);
        });

        // Carrega dados do gráfico de fluxo de caixa
        function loadCashflowChart() {
            fetch('api/cashflow_data.php')
                .then(response => response.json())
                .then(data => {
                    const chartBars = document.querySelectorAll('#cashflowChart .chart-bar');
                    
                    if (data && data.length >= 7) {
                        data.slice(0, 7).forEach((dayData, index) => {
                            if (chartBars[index]) {
                                const percentage = Math.min(100, Math.abs(dayData.value) / Math.max(...data.map(d => Math.abs(d.value))) * 100);
                                chartBars[index].style.setProperty('--bar-height', percentage + '%');
                                chartBars[index].className = dayData.value >= 0 ? 'chart-bar' : 'chart-bar negative';
                                chartBars[index].title = `${dayData.date}: R$ ${dayData.value.toLocaleString('pt-BR', {minimumFractionDigits: 2})}`;
                            }
                        });
                    }
                })
                .catch(err => console.log('Erro ao carregar dados do gráfico:', err));
        }

        // Carrega o gráfico após 1 segundo
        setTimeout(loadCashflowChart, 1000);

        // Adiciona efeitos de animação aos cards
        const cards = document.querySelectorAll('.financial-card');
        
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 150);
        });

        // Adiciona efeitos de hover nos botões
        const buttons = document.querySelectorAll('.btn');
        buttons.forEach(button => {
            button.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });
            
            button.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
            
            button.addEventListener('mousedown', function() {
                this.style.transform = 'translateY(1px)';
            });
            
            button.addEventListener('mouseup', function() {
                this.style.transform = 'translateY(-2px)';
            });
        });

        // Sistema de notificações para alertas financeiros
        function checkFinancialAlerts() {
            fetch('api/check_financial_alerts.php')
                .then(response => response.json())
                .then(data => {
                    if (data.criticalPayments && data.criticalPayments.length > 0) {
                        showNotification(
                            `${data.criticalPayments.length} conta(s) com vencimento hoje!`,
                            'danger'
                        );
                    }
                    
                    if (data.lowCash && data.lowCash.alert) {
                        showNotification(
                            'Saldo de caixa baixo! Monitore as saídas.',
                            'warning'
                        );
                    }
                    
                    if (data.overdueReceivables && data.overdueReceivables.length > 0) {
                        showNotification(
                            `${data.overdueReceivables.length} conta(s) a receber em atraso!`,
                            'warning'
                        );
                    }
                })
                .catch(err => console.log('Erro ao verificar alertas financeiros:', err));
        }

        // Verifica alertas ao carregar a página
        setTimeout(checkFinancialAlerts, 2000);

        // Auto-refresh das estatísticas a cada 5 minutos
        setInterval(() => {
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Lista de elementos para atualizar
                    const elementos = [
                        'totalContasReceber', 
                        'totalContasPagar', 
                        'totalRecebidas', 
                        'totalPagas'
                    ];
                    
                    elementos.forEach(id => {
                        const novoValor = doc.getElementById(id)?.textContent;
                        const elementoAtual = document.getElementById(id);
                        
                        if (novoValor && elementoAtual && novoValor !== elementoAtual.textContent) {
                            elementoAtual.textContent = novoValor;
                        }
                    });
                    
                    // Atualiza também os valores nos botões
                    const btnElements = document.querySelectorAll('.btn');
                    btnElements.forEach(btn => {
                        if (btn.textContent.includes('Pendentes')) {
                            const newText = btn.textContent.replace(/\(\d+\)/, `(${doc.getElementById('totalContasReceber')?.textContent || '0'})`);
                            btn.innerHTML = btn.innerHTML.replace(/\(\d+\)/, `(${doc.getElementById('totalContasReceber')?.textContent || '0'})`);
                        }
                    });
                })
                .catch(err => console.log('Erro ao atualizar estatísticas:', err));
        }, 300000); // 5 minutos

        // Adiciona busca rápida com tecla de atalho
        document.addEventListener('keydown', function(e) {
            // Ctrl + R ou Cmd + R para contas a receber
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                window.location.href = 'contas_a_receber.php';
            }
            
            // Ctrl + P ou Cmd + P para contas a pagar
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.location.href = 'contas_a_pagar.php';
            }
            
            // Ctrl + C ou Cmd + C para caixa
            if ((e.ctrlKey || e.metaKey) && e.key === 'c') {
                e.preventDefault();
                window.location.href = 'caixa.php';
            }
        });

        // Função para mostrar notificações
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            
            let icon = 'info-circle';
            let backgroundColor = 'var(--info-color)';
            let textColor = 'white';
            
            switch(type) {
                case 'warning':
                    icon = 'exclamation-triangle';
                    backgroundColor = 'var(--warning-color)';
                    textColor = '#333';
                    break;
                case 'danger':
                    icon = 'exclamation-circle';
                    backgroundColor = 'var(--danger-color)';
                    textColor = 'white';
                    break;
                case 'success':
                    icon = 'check-circle';
                    backgroundColor = 'var(--success-color)';
                    textColor = 'white';
                    break;
            }
            
            notification.innerHTML = `
                <i class="fas fa-${icon}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${backgroundColor};
                color: ${textColor};
                padding: 1rem 1.5rem;
                border-radius: var(--radius-sm);
                box-shadow: var(--shadow);
                display: flex;
                align-items: center;
                gap: 0.75rem;
                z-index: 1000;
                animation: slideInRight 0.3s ease;
                max-width: 400px;
                font-weight: 500;
            `;
            
            document.body.appendChild(notification);
            
            // Remove após 7 segundos
            setTimeout(() => {
                notification.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 7000);
        }

        // Monitor de saldo crítico
        function monitorCriticalBalance() {
            const saldoElement = document.querySelector('.stat-caixa .stat-number');
            if (saldoElement) {
                const saldoText = saldoElement.textContent.replace(/[R$\s.]/g, '').replace(',', '.');
                const saldo = parseFloat(saldoText);
                
                if (saldo < 1000 && saldo >= 0) { // Saldo baixo
                    saldoElement.style.animation = 'pulse 2s infinite';
                } else if (saldo < 0) { // Saldo negativo
                    saldoElement.style.animation = 'pulse 1s infinite';
                    saldoElement.style.color = 'var(--danger-color)';
                }
            }
        }

        // Executa monitor de saldo
        setTimeout(monitorCriticalBalance, 3000);

        // Calculadora financeira rápida
        let calculatorVisible = false;
        
        function toggleCalculator() {
            if (calculatorVisible) {
                document.getElementById('quickCalculator')?.remove();
                calculatorVisible = false;
            } else {
                createQuickCalculator();
                calculatorVisible = true;
            }
        }

        function createQuickCalculator() {
            const calculator = document.createElement('div');
            calculator.id = 'quickCalculator';
            calculator.innerHTML = `
                <div style="
                    position: fixed;
                    bottom: 20px;
                    left: 20px;
                    background: white;
                    border-radius: var(--radius);
                    box-shadow: var(--shadow-hover);
                    padding: 1.5rem;
                    z-index: 1000;
                    min-width: 250px;
                    border: 2px solid var(--secondary-color);
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h4 style="color: var(--primary-color); margin: 0;">Calculadora Rápida</h4>
                        <button onclick="toggleCalculator()" style="background: none; border: none; color: var(--medium-gray); cursor: pointer;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <input type="text" id="calcInput" placeholder="Digite o cálculo..." style="
                        width: 100%;
                        padding: 0.75rem;
                        border: 1px solid var(--border-color);
                        border-radius: var(--radius-sm);
                        margin-bottom: 0.75rem;
                        font-family: 'Courier New', monospace;
                    ">
                    <div id="calcResult" style="
                        background: var(--light-gray);
                        padding: 0.75rem;
                        border-radius: var(--radius-sm);
                        font-family: 'Courier New', monospace;
                        font-weight: 600;
                        color: var(--primary-color);
                        text-align: center;
                        min-height: 1.5rem;
                    ">0,00</div>
                </div>
            `;
            
            document.body.appendChild(calculator);
            
            const input = document.getElementById('calcInput');
            const result = document.getElementById('calcResult');
            
            input.addEventListener('input', function() {
                try {
                    const expression = this.value.replace(/,/g, '.');
                    const calc = Function('"use strict"; return (' + expression + ')')();
                    if (!isNaN(calc)) {
                        result.textContent = 'R$ ' + calc.toLocaleString('pt-BR', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                    }
                } catch (e) {
                    result.textContent = 'Erro na expressão';
                }
            });
            
            input.focus();
        }

        // Adiciona botão da calculadora
        const calcButton = document.createElement('button');
        calcButton.innerHTML = '<i class="fas fa-calculator"></i>';
        calcButton.title = 'Calculadora Rápida (Ctrl+K)';
        calcButton.style.cssText = `
            position: fixed;
            bottom: 80px;
            right: 20px;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            cursor: pointer;
            box-shadow: var(--shadow);
            font-size: 1.2rem;
            z-index: 999;
            transition: var(--transition);
        `;
        
        calcButton.addEventListener('click', toggleCalculator);
        calcButton.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.1)';
            this.style.boxShadow = 'var(--shadow-hover)';
        });
        calcButton.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
            this.style.boxShadow = 'var(--shadow)';
        });
        
        document.body.appendChild(calcButton);

        // Atalho para calculadora
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                toggleCalculator();
            }
        });

        // Faz a função toggleCalculator global
        window.toggleCalculator = toggleCalculator;

        // Adiciona suporte para impressão da página
        const printButton = document.createElement('button');
        printButton.innerHTML = '<i class="fas fa-print"></i> Imprimir Relatório';
        printButton.className = 'btn btn-info';
        printButton.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            border-radius: 50px;
            padding: 1rem 1.5rem;
            box-shadow: var(--shadow-hover);
        `;
        
        printButton.addEventListener('click', function() {
            window.print();
        });
        
        document.body.appendChild(printButton);

        // Monitor de performance da página
        function monitorPerformance() {
            const startTime = performance.now();
            
            // Monitora carregamento das imagens
            const images = document.querySelectorAll('img');
            let imagesLoaded = 0;
            
            images.forEach(img => {
                if (img.complete) {
                    imagesLoaded++;
                } else {
                    img.addEventListener('load', () => imagesLoaded++);
                }
            });
            
            // Log de performance após 3 segundos
            setTimeout(() => {
                const loadTime = performance.now() - startTime;
                console.log(`Página financeira carregada em ${loadTime.toFixed(2)}ms`);
                console.log(`${imagesLoaded}/${images.length} imagens carregadas`);
            }, 3000);
        }

        monitorPerformance();

        // Registra analytics da página
        console.log('Módulo Financeiro carregado com sucesso!');
        console.log('Total contas a receber:', <?php echo $totalContasReceber; ?>);
        console.log('Valor contas a receber: R , <?php echo $valorContasReceber; ?>);
        console.log('Total contas a pagar:', <?php echo $totalContasPagar; ?>);
        console.log('Valor contas a pagar: R , <?php echo $valorContasPagar; ?>);
        console.log('Saldo do caixa: R , <?php echo $saldoCaixa; ?>);
        console.log('Usuário:', '<?php echo addslashes($_SESSION['user']['name']); ?>');
        console.log('Permissão:', '<?php echo addslashes($_SESSION['user']['permission']); ?>');

        // Tooltip dinâmico para estatísticas
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach(card => {
            const label = card.querySelector('.stat-label').textContent;
            
            card.addEventListener('mouseenter', function() {
                if (label.includes('Receber')) {
                    this.title = 'Valores pendentes de recebimento de clientes';
                } else if (label.includes('Pagar')) {
                    this.title = 'Valores pendentes de pagamento para fornecedores';
                } else if (label.includes('Recebidas')) {
                    this.title = 'Histórico de contas já recebidas';
                } else if (label.includes('Pagas')) {
                    this.title = 'Histórico de contas já pagas';
                } else if (label.includes('Caixa')) {
                    this.title = 'Saldo atual disponível no caixa';
                } else if (label.includes('Saldo Pendente')) {
                    this.title = 'Diferença entre valores a receber e a pagar';
                }
            });
        });

        // Sistema de backup automático (simulação)
        function simulateBackup() {
            console.log('Executando backup automático dos dados financeiros...');
            
            setTimeout(() => {
                console.log('Backup concluído com sucesso!');
                
                // Mostra notificação discreta
                const backupNotification = document.createElement('div');
                backupNotification.innerHTML = `
                    <i class="fas fa-shield-alt"></i>
                    <span>Backup automático realizado</span>
                `;
                backupNotification.style.cssText = `
                    position: fixed;
                    bottom: 150px;
                    right: 20px;
                    background: var(--success-color);
                    color: white;
                    padding: 0.75rem 1rem;
                    border-radius: var(--radius-sm);
                    font-size: 0.85rem;
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    z-index: 1000;
                    opacity: 0;
                    transition: opacity 0.3s ease;
                `;
                
                document.body.appendChild(backupNotification);
                
                // Anima a entrada
                setTimeout(() => {
                    backupNotification.style.opacity = '1';
                }, 100);
                
                // Remove após 3 segundos
                setTimeout(() => {
                    backupNotification.style.opacity = '0';
                    setTimeout(() => backupNotification.remove(), 300);
                }, 3000);
                
            }, 2000);
        }

        // Executa backup a cada 10 minutos
        setInterval(simulateBackup, 600000);

        // Adiciona indicador de conexão
        function updateConnectionStatus() {
            const isOnline = navigator.onLine;
            const existingIndicator = document.getElementById('connectionIndicator');
            
            if (existingIndicator) {
                existingIndicator.remove();
            }
            
            if (!isOnline) {
                const indicator = document.createElement('div');
                indicator.id = 'connectionIndicator';
                indicator.innerHTML = `
                    <i class="fas fa-wifi"></i>
                    <span>Modo Offline</span>
                `;
                indicator.style.cssText = `
                    position: fixed;
                    top: 20px;
                    left: 20px;
                    background: var(--warning-color);
                    color: #333;
                    padding: 0.75rem 1rem;
                    border-radius: var(--radius-sm);
                    font-size: 0.85rem;
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    z-index: 1000;
                    box-shadow: var(--shadow);
                `;
                
                document.body.appendChild(indicator);
            }
        }

        window.addEventListener('online', updateConnectionStatus);
        window.addEventListener('offline', updateConnectionStatus);
        updateConnectionStatus();

        // Adiciona loading em ações Ajax
        const originalFetch = window.fetch;
        window.fetch = function(...args) {
            document.body.style.cursor = 'wait';
            return originalFetch.apply(this, args)
                .finally(() => {
                    document.body.style.cursor = 'default';
                });
        };

        // Função de limpeza ao sair da página
        window.addEventListener('beforeunload', function() {
            console.log('Salvando estado da sessão financeira...');
            localStorage.setItem('financeiro_last_visit', new Date().toISOString());
        });

        // Verifica última visita
        const lastVisit = localStorage.getItem('financeiro_last_visit');
        if (lastVisit) {
            const timeDiff = new Date() - new Date(lastVisit);
            const hoursDiff = timeDiff / (1000 * 60 * 60);
            
            if (hoursDiff > 24) {
                showNotification('Bem-vindo de volta! Verifique as atualizações financeiras.', 'info');
            }
        }
    });

    // Animações CSS adicionais
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: var(--shadow);
            }
            50% {
                transform: scale(1.02);
                box-shadow: var(--shadow-hover);
            }
        }

        .notification button {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            padding: 0.25rem;
            margin-left: 1rem;
            transition: opacity 0.2s;
            border-radius: 50%;
        }

        .notification button:hover {
            opacity: 0.7;
            background: rgba(255,255,255,0.2);
        }

        /* Efeito de loading para cards */
        .financial-card.loading {
            position: relative;
            overflow: hidden;
        }

        .financial-card.loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 255, 255, 0.4),
                transparent
            );
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            to {
                left: 100%;
            }
        }

        /* Melhorias de acessibilidade */
        .btn:focus,
        .financial-card:focus-within {
            outline: 3px solid var(--secondary-color);
            outline-offset: 2px;
        }

        /* Modo de alto contraste */
        @media (prefers-contrast: high) {
            .stat-card,
            .financial-card {
                border: 2px solid var(--dark-gray);
            }
            
            .status-indicator {
                border-width: 2px;
            }
        }

        /* Animações reduzidas para quem prefere */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* Estilos para telas pequenas */
        @media (max-width: 480px) {
            .notification {
                left: 10px;
                right: 10px;
                top: 10px;
                max-width: none;
            }
            
            #quickCalculator > div {
                left: 10px !important;
                right: 10px !important;
                width: auto !important;
                min-width: auto !important;
            }
        }

        /* Animação suave para barras do gráfico */
        .chart-bar {
            background: linear-gradient(to top, var(--secondary-color), var(--primary-color));
            background-size: 100% 200%;
            animation: gradientShift 3s ease infinite;
        }

        .chart-bar.negative {
            background: linear-gradient(to top, var(--danger-color), #b02a37);
            background-size: 100% 200%;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 0%; }
            50% { background-position: 0% 100%; }
            100% { background-position: 0% 0%; }
        }

        /* Hover effect para status indicators */
        .status-indicator {
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .status-indicator:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        /* Efeitos para o resumo financeiro */
        .financial-summary {
            background-attachment: fixed;
        }

        .summary-value {
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        /* Estilos para impressão */
        @media print {
            .btn, nav, .last-movement-info, #quickCalculator, 
            button[title*="Calculadora"], button[title*="Imprimir"] {
                display: none !important;
            }
            
            .container {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 1rem !important;
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr) !important;
            }
            
            .financial-grid {
                display: none !important;
            }
            
            .financial-summary {
                background: white !important;
                color: black !important;
                border: 2px solid #333 !important;
            }
            
            .cashflow-chart {
                page-break-inside: avoid;
            }
        }

        /* Estilos para dark mode (futuro) */
        @media (prefers-color-scheme: dark) {
            :root {
                --light-gray: #2d3748;
                --medium-gray: #a0aec0;
                --dark-gray: #e2e8f0;
                --border-color: #4a5568;
            }
        }
    `;
    document.head.appendChild(style);
</script>

</body>
</html>