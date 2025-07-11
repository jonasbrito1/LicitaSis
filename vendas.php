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

// Verifica se o usuário tem permissão para acessar vendas
$permissionManager->requirePermission('vendas', 'view');

// Registra acesso à página
logUserAction('READ', 'vendas_dashboard');

// Busca estatísticas de vendas
$totalVendas = 0;
$vendasPendentes = 0;
$vendasFaturadas = 0;
$vendasEntregues = 0;
$vendasCanceladas = 0;
$valorTotalVendas = 0;
$valorPendente = 0;
$vendasDoMes = 0;
$ultimaVenda = null;

try {
    // Total de vendas
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM vendas");
    $result = $stmt->fetch();
    $totalVendas = $result['total'] ?? 0;
    
    // Vendas por status
    $stmt = $pdo->query("
        SELECT 
            status,
            COUNT(*) as quantidade,
            SUM(valor_total) as valor_status
        FROM vendas 
        GROUP BY status
    ");
    $statusData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($statusData as $data) {
        switch ($data['status']) {
            case 'Pendente':
                $vendasPendentes = $data['quantidade'];
                $valorPendente = $data['valor_status'] ?? 0;
                break;
            case 'Faturada':
                $vendasFaturadas = $data['quantidade'];
                break;
            case 'Entregue':
                $vendasEntregues = $data['quantidade'];
                break;
            case 'Cancelada':
                $vendasCanceladas = $data['quantidade'];
                break;
        }
    }
    
    // Valor total das vendas
    $stmt = $pdo->query("SELECT SUM(valor_total) as valor_total FROM vendas WHERE status != 'Cancelada'");
    $result = $stmt->fetch();
    $valorTotalVendas = $result['valor_total'] ?? 0;
    
    // Vendas do mês atual
    $stmt = $pdo->query("
        SELECT COUNT(*) as mes_atual 
        FROM vendas 
        WHERE MONTH(data_venda) = MONTH(CURRENT_DATE()) 
        AND YEAR(data_venda) = YEAR(CURRENT_DATE())
        AND status != 'Cancelada'
    ");
    $result = $stmt->fetch();
    $vendasDoMes = $result['mes_atual'] ?? 0;
    
    // Última venda
    $stmt = $pdo->query("
        SELECT 
            numero_venda, 
            cliente_nome,
            valor_total,
            status,
            data_venda,
            created_at 
        FROM vendas 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $ultimaVenda = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Erro ao buscar estatísticas de vendas: " . $e->getMessage());
}

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Vendas - LicitaSis", "vendas");
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
        --pending-color: #fd7e14;
        --faturado-color: #007bff;
        --entregue-color: #20c997;
        --cancelado-color: #dc3545;
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

    /* Container principal */
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

    /* Título principal */
    h2 {
        text-align: center;
        color: var(--primary-color);
        margin-bottom: 2rem;
        font-size: 2rem;
        font-weight: 600;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1rem;
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

    h2 i {
        color: var(--secondary-color);
        font-size: 1.8rem;
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
    .stat-card.stat-total {
        border-left-color: var(--primary-color);
    }

    .stat-card.stat-pending {
        border-left-color: var(--pending-color);
    }

    .stat-card.stat-faturado {
        border-left-color: var(--faturado-color);
    }

    .stat-card.stat-entregue {
        border-left-color: var(--entregue-color);
    }

    .stat-card.stat-cancelado {
        border-left-color: var(--cancelado-color);
    }

    .stat-card.stat-value {
        border-left-color: var(--warning-color);
    }

    .stat-icon {
        font-size: 2.5rem;
        color: var(--secondary-color);
        margin-bottom: 1rem;
        display: block;
    }

    .stat-card.stat-pending .stat-icon {
        color: var(--pending-color);
    }

    .stat-card.stat-faturado .stat-icon {
        color: var(--faturado-color);
    }

    .stat-card.stat-entregue .stat-icon {
        color: var(--entregue-color);
    }

    .stat-card.stat-cancelado .stat-icon {
        color: var(--cancelado-color);
    }

    .stat-card.stat-value .stat-icon {
        color: var(--warning-color);
    }

    .stat-number {
        font-size: 2.2rem;
        font-weight: 800;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        line-height: 1;
        font-family: 'Courier New', monospace;
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

    /* Grid de cards principais */
    .vendas-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 2rem;
        margin-top: 2.5rem;
    }

    .venda-card {
        background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 2rem;
        text-align: center;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .venda-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
    }

    .venda-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
        border-color: var(--secondary-color);
    }

    .venda-card h3 {
        color: var(--primary-color);
        font-size: 1.3rem;
        margin-bottom: 1rem;
        font-weight: 600;
    }

    .venda-card .icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .venda-card p {
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

    /* Botões */
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

    /* Progress bars para visualização de status */
    .progress-container {
        margin-top: 1.5rem;
        background: var(--light-gray);
        border-radius: 10px;
        padding: 1.5rem;
    }

    .progress-title {
        color: var(--dark-gray);
        font-weight: 600;
        margin-bottom: 1rem;
        text-align: center;
    }

    .progress-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.75rem;
    }

    .progress-item:last-child {
        margin-bottom: 0;
    }

    .progress-label {
        font-size: 0.9rem;
        color: var(--dark-gray);
        font-weight: 500;
    }

    .progress-value {
        font-size: 0.9rem;
        color: var(--medium-gray);
        font-weight: 600;
    }

    .progress-bar {
        width: 100%;
        height: 8px;
        background: var(--border-color);
        border-radius: 4px;
        overflow: hidden;
        margin-top: 0.5rem;
    }

    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
        border-radius: 4px;
        transition: width 1s ease;
        width: 0;
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
        color: var(--pending-color);
        border: 1px solid var(--pending-color);
    }

    .status-faturada {
        background: rgba(0, 123, 255, 0.1);
        color: var(--faturado-color);
        border: 1px solid var(--faturado-color);
    }

    .status-entregue {
        background: rgba(32, 201, 151, 0.1);
        color: var(--entregue-color);
        border: 1px solid var(--entregue-color);
    }

    .status-cancelada {
        background: rgba(220, 53, 69, 0.1);
        color: var(--cancelado-color);
        border: 1px solid var(--cancelado-color);
    }

    /* Última venda */
    .last-venda-info {
        margin-top: 2rem;
    }

    .last-venda-card {
        background: white;
        padding: 1.5rem 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border-left: 4px solid var(--secondary-color);
        transition: var(--transition);
    }

    .last-venda-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    }

    .last-venda-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .last-venda-header i {
        font-size: 1.5rem;
        color: var(--secondary-color);
        flex-shrink: 0;
    }

    .last-venda-header h4 {
        color: var(--primary-color);
        font-size: 1.1rem;
        margin: 0;
    }

    .last-venda-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }

    .venda-detail {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .venda-detail strong {
        color: var(--dark-gray);
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .venda-detail span {
        color: var(--medium-gray);
        font-size: 0.95rem;
    }

    .venda-number {
        color: var(--primary-color) !important;
        font-weight: 700 !important;
        font-size: 1.1rem !important;
    }

    .venda-value {
        color: var(--success-color) !important;
        font-weight: 700 !important;
        font-size: 1.1rem !important;
    }

    /* Responsividade */
    @media (max-width: 1200px) {
        .container {
            margin: 2rem 1.5rem;
            padding: 2rem;
        }

        .vendas-grid {
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
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
            flex-direction: column;
            gap: 0.5rem;
        }

        h2 i {
            font-size: 1.5rem;
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

        .vendas-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .venda-card {
            padding: 1.5rem;
        }

        .venda-card .icon {
            font-size: 2.5rem;
        }

        .venda-card h3 {
            font-size: 1.2rem;
        }

        .last-venda-header {
            flex-direction: column;
            text-align: center;
            gap: 0.75rem;
        }

        .last-venda-content {
            grid-template-columns: 1fr;
            gap: 0.75rem;
            text-align: center;
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

        .venda-card {
            padding: 1.25rem;
        }

        .venda-card .icon {
            font-size: 2rem;
        }

        .venda-card h3 {
            font-size: 1.1rem;
        }

        .btn {
            padding: 0.75rem 1.25rem;
            font-size: 0.9rem;
        }

        .last-venda-card {
            padding: 1.25rem 1.5rem;
        }
    }

    @media (max-width: 360px) {
        .container {
            padding: 1rem;
            margin: 0.75rem 0.25rem;
        }

        h2 {
            font-size: 1.3rem;
        }

        .stat-card {
            padding: 1.25rem;
        }

        .stat-number {
            font-size: 1.5rem;
        }

        .venda-card {
            padding: 1rem;
        }

        .btn {
            padding: 0.6rem 1rem;
            font-size: 0.85rem;
        }
    }
</style>

<div class="container">
    <h2>
        <i class="fas fa-shopping-cart"></i>
        Vendas
    </h2>

    
    <!-- Cards principais -->
    <div class="vendas-grid">
        <!-- Card Cadastrar Venda -->
        <?php if ($permissionManager->hasPagePermission('vendas', 'create')): ?>
        <div class="venda-card">
            <div class="icon">
                <i class="fas fa-plus-circle"></i>
            </div>
            <h3>Cadastrar Venda</h3>
            <p>Registre novas vendas com todas as informações necessárias para controle e acompanhamento dos processos comerciais.</p>
            <div class="card-buttons">
                <a href="cadastro_vendas.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Nova Venda
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Card Consultar Vendas -->
        <div class="venda-card">
            <div class="icon">
                <i class="fas fa-search"></i>
            </div>
            <h3>Consultar Vendas</h3>
            <p>Visualize, edite e gerencie todas as vendas cadastradas. Acesse informações detalhadas, histórico e documentos anexos.</p>
            <div class="card-buttons">
                <a href="consulta_vendas.php" class="btn btn-success">
                    <i class="fas fa-search"></i> Ver Vendas (<?php echo $totalVendas; ?>)
                </a>
            </div>
        </div>

        <!-- Card Vendas Pendentes -->
        <div class="venda-card">
            <div class="icon">
                <i class="fas fa-clock"></i>
            </div>
            <h3>Vendas Pendentes</h3>
            <p>Acompanhe vendas que aguardam processamento, aprovação ou próximas etapas do fluxo de trabalho.</p>
            <div class="card-buttons">
                <a href="vendas_pendentes.php" class="btn btn-warning">
                    <i class="fas fa-clock"></i> Ver Pendentes (<?php echo $vendasPendentes; ?>)
                </a>
            </div>
        </div>

        <!-- Card Vendas Entregues -->
        <div class="venda-card">
            <div class="icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>Vendas Entregues</h3>
            <p>Consulte o histórico de vendas finalizadas e entregues. Acesse relatórios de conclusão e documentação final.</p>
            <div class="card-buttons">
                <a href="vendas_entregues.php" class="btn btn-success">
                    <i class="fas fa-check-circle"></i> Ver Entregues (<?php echo $vendasEntregues; ?>)
                </a>
            </div>
        </div>

        <!-- Card Status e Workflow -->
        <div class="venda-card">
            <div class="icon">
                <i class="fas fa-tasks"></i>
            </div>
            <h3>Gestão de Status</h3>
            <p>Controle o fluxo de status das vendas: Pendente → Faturada → Entregue. Atualize classificações e acompanhe o progresso.</p>
            <div class="card-buttons">
                <a href="status_vendas.php" class="btn btn-info">
                    <i class="fas fa-clipboard-check"></i> Gerenciar Status
                </a>
            </div>
        </div>

        <!-- Card Relatórios -->
        <div class="venda-card">
            <div class="icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <h3>Relatórios Gerenciais</h3>
            <p>Gere relatórios detalhados de vendas por período, cliente, produto e análises financeiras para tomada de decisão.</p>
            <div class="card-buttons">
                <a href="relatorio_vendas.php" class="btn btn-warning">
                    <i class="fas fa-file-chart-line"></i> Ver Relatórios
                </a>
            </div>
        </div>

        <!-- Card Comissões -->
        <div class="venda-card">
            <div class="icon">
                <i class="fas fa-percentage"></i>
            </div>
            <h3>Controle de Comissões</h3>
            <p>Gerencie comissões de vendedores, calcule valores devidos e acompanhe pagamentos realizados.</p>
            <div class="card-buttons">
                <a href="comissoes_vendas.php" class="btn btn-purple">
                    <i class="fas fa-calculator"></i> Gerenciar Comissões
                </a>
            </div>
        </div>

        <!-- Card Clientes -->
        <div class="venda-card">
            <div class="icon">
                <i class="fas fa-users"></i>
            </div>
            <h3>Gestão de Clientes</h3>
            <p>Acesse informações dos clientes, histórico de compras e análise de relacionamento comercial.</p>
            <div class="card-buttons">
                <a href="clientes_vendas.php" class="btn btn-info">
                    <i class="fas fa-user-tie"></i> Ver Clientes
                </a>
            </div>
        </div>

        <!-- Card Produtos -->
        <div class="venda-card">
            <div class="icon">
                <i class="fas fa-box"></i>
            </div>
            <h3>Produtos e Estoque</h3>
            <p>Controle produtos vendidos, estoque disponível e análise de performance de vendas por produto.</p>
            <div class="card-buttons">
                <a href="produtos_vendas.php" class="btn btn-success">
                    <i class="fas fa-boxes"></i> Gerenciar Produtos
                </a>
            </div>
        </div>

        <!-- Card Metas -->
        <div class="venda-card">
            <div class="icon">
                <i class="fas fa-target"></i>
            </div>
            <h3>Metas de Vendas</h3>
            <p>Defina metas mensais e anuais, acompanhe o progresso da equipe e monitore o alcance dos objetivos.</p>
            <div class="card-buttons">
                <a href="metas_vendas.php" class="btn btn-warning">
                    <i class="fas fa-bullseye"></i> Gerenciar Metas
                </a>
            </div>
        </div>

        <!-- Card Financeiro -->
        <div class="venda-card">
            <div class="icon">
                <i class="fas fa-dollar-sign"></i>
            </div>
            <h3>Controle Financeiro</h3>
            <p>Acompanhe valores de vendas, recebimentos, inadimplência e análise de fluxo de caixa das vendas.</p>
            <div class="card-buttons">
                <a href="financeiro_vendas.php" class="btn btn-success">
                    <i class="fas fa-money-bill-wave"></i> Controle Financeiro
                </a>
            </div>
        </div>

        <!-- Card Auditoria -->
        <?php if ($permissionManager->hasPagePermission('vendas', 'audit')): ?>
        <div class="venda-card">
            <div class="icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h3>Auditoria e Logs</h3>
            <p>Visualize o histórico completo de alterações, logs de sistema e trilha de auditoria para compliance e controle interno.</p>
            <div class="card-buttons">
                <a href="auditoria_vendas.php" class="btn btn-danger">
                    <i class="fas fa-history"></i> Ver Auditoria
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <i class="stat-icon fas fa-chart-line"></i>
            <div class="stat-number" id="totalVendas"><?php echo $totalVendas; ?></div>
            <div class="stat-label">Total de Vendas</div>
        </div>
        
        <div class="stat-card stat-pending">
            <i class="stat-icon fas fa-clock"></i>
            <div class="stat-number" id="vendasPendentes"><?php echo $vendasPendentes; ?></div>
            <div class="stat-label">Pendentes</div>
            <div class="stat-sublabel">
                R$ <?php echo number_format($valorPendente, 2, ',', '.'); ?>
            </div>
        </div>
        
        <div class="stat-card stat-faturado">
            <i class="stat-icon fas fa-file-invoice"></i>
            <div class="stat-number" id="vendasFaturadas"><?php echo $vendasFaturadas; ?></div>
            <div class="stat-label">Faturadas</div>
        </div>
        
        <div class="stat-card stat-entregue">
            <i class="stat-icon fas fa-truck"></i>
            <div class="stat-number" id="vendasEntregues"><?php echo $vendasEntregues; ?></div>
            <div class="stat-label">Entregues</div>
        </div>
        
        <div class="stat-card stat-cancelado">
            <i class="stat-icon fas fa-times-circle"></i>
            <div class="stat-number" id="vendasCanceladas"><?php echo $vendasCanceladas; ?></div>
            <div class="stat-label">Canceladas</div>
        </div>
        
        <div class="stat-card stat-value">
            <i class="stat-icon fas fa-dollar-sign"></i>
            <div class="stat-number">
                R$ <?php echo number_format($valorTotalVendas, 2, ',', '.'); ?>
            </div>
            <div class="stat-label">Valor Total</div>
            <div class="stat-sublabel">
                <?php echo $vendasDoMes; ?> no mês atual
            </div>
        </div>
    </div>

    <!-- Progress bars para visualização de status -->
    <div class="progress-container">
        <div class="progress-title">Distribuição de Status das Vendas</div>
        
        <?php if ($totalVendas > 0): ?>
        <div class="progress-item">
            <span class="progress-label">Pendentes</span>
            <span class="progress-value"><?php echo round(($vendasPendentes / $totalVendas) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($vendasPendentes / $totalVendas) * 100; ?>" style="background: var(--pending-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Faturadas</span>
            <span class="progress-value"><?php echo round(($vendasFaturadas / $totalVendas) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($vendasFaturadas / $totalVendas) * 100; ?>" style="background: var(--faturado-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Entregues</span>
            <span class="progress-value"><?php echo round(($vendasEntregues / $totalVendas) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($vendasEntregues / $totalVendas) * 100; ?>" style="background: var(--entregue-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Canceladas</span>
            <span class="progress-value"><?php echo round(($vendasCanceladas / $totalVendas) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($vendasCanceladas / $totalVendas) * 100; ?>" style="background: var(--cancelado-color);"></div>
        </div>
        <?php else: ?>
        <p style="text-align: center; color: var(--medium-gray); font-style: italic;">
            Nenhuma venda cadastrada ainda
        </p>
        <?php endif; ?>
    </div>


    <!-- Última venda cadastrada -->
    <?php if ($ultimaVenda): ?>
    <div class="last-venda-info">
        <div class="last-venda-card">
            <div class="last-venda-header">
                <i class="fas fa-clock"></i>
                <h4>Última Venda Cadastrada</h4>
            </div>
            <div class="last-venda-content">
                <div class="venda-detail">
                    <strong>Número da Venda:</strong>
                    <span class="venda-number"><?php echo htmlspecialchars($ultimaVenda['numero_venda']); ?></span>
                </div>
                <div class="venda-detail">
                    <strong>Cliente:</strong>
                    <span><?php echo htmlspecialchars($ultimaVenda['cliente_nome']); ?></span>
                </div>
                <div class="venda-detail">
                    <strong>Valor:</strong>
                    <span class="venda-value">R$ <?php echo number_format($ultimaVenda['valor_total'], 2, ',', '.'); ?></span>
                </div>
                <div class="venda-detail">
                    <strong>Status:</strong>
                    <span class="status-indicator status-<?php echo strtolower($ultimaVenda['status']); ?>">
                        <i class="fas fa-circle"></i>
                        <?php echo htmlspecialchars($ultimaVenda['status']); ?>
                    </span>
                </div>
                <div class="venda-detail">
                    <strong>Data da Venda:</strong>
                    <span><?php echo date('d/m/Y', strtotime($ultimaVenda['data_venda'])); ?></span>
                </div>
                <div class="venda-detail">
                    <strong>Cadastrado em:</strong>
                    <span><?php echo date('d/m/Y \à\s H:i', strtotime($ultimaVenda['created_at'])); ?></span>
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
// JavaScript específico da página de vendas
document.addEventListener('DOMContentLoaded', function() {
    console.log('Módulo de Vendas iniciado');

    // Anima os números das estatísticas
    function animateNumber(element, finalNumber) {
        if (!element || isNaN(finalNumber)) return;
        
        let currentNumber = 0;
        const increment = Math.max(1, Math.ceil(finalNumber / 30));
        const duration = 1000;
        const stepTime = duration / (finalNumber / increment);
        
        const isMonetary = element.textContent.includes('R);
        
        if (isMonetary) {
            element.textContent = 'R$ 0,00';
        } else {
            element.textContent = '0';
        }
        
        const timer = setInterval(() => {
            currentNumber += increment;
            if (currentNumber >= finalNumber) {
                currentNumber = finalNumber;
                clearInterval(timer);
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

    // Anima as barras de progresso
    function animateProgressBars() {
        const progressBars = document.querySelectorAll('.progress-fill');
        
        progressBars.forEach((bar, index) => {
            const percentage = parseFloat(bar.dataset.percentage);
            
            setTimeout(() => {
                bar.style.width = percentage + '%';
            }, index * 200);
        });
    }

    // Observer para animar quando os cards ficarem visíveis
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const numberElement = entry.target.querySelector('.stat-number');
                if (numberElement && !numberElement.dataset.animated) {
                    numberElement.dataset.animated = 'true';
                    const text = numberElement.textContent.trim();
                    
                    // Extrai o número do texto
                    let finalNumber = 0;
                    if (text.includes('R)) {
                        // Remove R$, pontos e vírgulas para pegar o número
                        finalNumber = parseFloat(text.replace(/[R$\s.]/g, '').replace(',', '.'));
                    } else if (/^\d+$/.test(text)) {
                        finalNumber = parseInt(text);
                    }
                    
                    if (!isNaN(finalNumber) && finalNumber > 0) {
                        setTimeout(() => animateNumber(numberElement, finalNumber), 200);
                    }
                }
            }
        });
    }, { threshold: 0.5 });

    // Observer para as barras de progresso
    const progressObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !entry.target.dataset.animated) {
                entry.target.dataset.animated = 'true';
                animateProgressBars();
            }
        });
    }, { threshold: 0.3 });

    // Observa todos os cards de estatísticas
    document.querySelectorAll('.stat-card').forEach(card => {
        observer.observe(card);
    });

    // Observa o container de progresso
    const progressContainer = document.querySelector('.progress-container');
    if (progressContainer) {
        progressObserver.observe(progressContainer);
    }

    // Adiciona efeitos de animação aos cards
    const cards = document.querySelectorAll('.venda-card');
    
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Adiciona efeito hover nos botões
    document.querySelectorAll('.btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px) scale(1.02)';
        });
        
        btn.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });

    // Adiciona funcionalidade de tooltip para estatísticas
    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            const label = this.querySelector('.stat-label').textContent;
            const number = this.querySelector('.stat-number').textContent;
            
            // Pode adicionar tooltips personalizados aqui
            this.setAttribute('title', `${label}: ${number}`);
        });
    });

    // Monitora cliques nos cards para analytics
    document.querySelectorAll('.venda-card a').forEach(link => {
        link.addEventListener('click', function(e) {
            const cardTitle = this.closest('.venda-card').querySelector('h3').textContent;
            console.log(`Navegação para: ${cardTitle} - ${this.href}`);
            
            // Aqui você pode adicionar tracking de analytics
            // gtag('event', 'click', { 'event_category': 'vendas', 'event_label': cardTitle });
        });
    });

    // Atualiza automaticamente as estatísticas a cada 5 minutos
    setInterval(function() {
        // Pode implementar uma chamada AJAX para atualizar estatísticas em tempo real
        console.log('Verificando atualizações de estatísticas...');
    }, 300000); // 5 minutos

    // Registra analytics da página
    console.log('Módulo de Vendas carregado com sucesso!');
    console.log('Total de vendas:', <?php echo $totalVendas; ?>);
    console.log('Vendas pendentes:', <?php echo $vendasPendentes; ?>);
    console.log('Vendas faturadas:', <?php echo $vendasFaturadas; ?>);
    console.log('Vendas entregues:', <?php echo $vendasEntregues; ?>);
    console.log('Vendas canceladas:', <?php echo $vendasCanceladas; ?>);
    console.log('Valor total: R$ <?php echo number_format($valorTotalVendas, 2, ',', '.'); ?>');
    
    // Verifica se há alertas importantes
    if (<?php echo $vendasPendentes; ?> > 10) {
        console.warn('Atenção: Muitas vendas pendentes aguardando processamento!');
    }
    
    if (<?php echo $vendasCanceladas; ?> > 5) {
        console.warn('Atenção: Alto número de vendas canceladas!');
    }
});

// Função para exportar dados das vendas
function exportarDadosVendas() {
    const dados = {
        totalVendas: <?php echo $totalVendas; ?>,
        vendasPendentes: <?php echo $vendasPendentes; ?>,
        vendasFaturadas: <?php echo $vendasFaturadas; ?>,
        vendasEntregues: <?php echo $vendasEntregues; ?>,
        vendasCanceladas: <?php echo $vendasCanceladas; ?>,
        valorTotal: <?php echo $valorTotalVendas; ?>,
        dataExportacao: new Date().toISOString()
    };
    
    const dataStr = JSON.stringify(dados, null, 2);
    const dataBlob = new Blob([dataStr], { type: 'application/json' });
    
    const link = document.createElement('a');
    link.href = URL.createObjectURL(dataBlob);
    link.download = 'estatisticas_vendas_' + new Date().toISOString().split('T')[0] + '.json';
    link.click();
}

// Função para imprimir relatório resumido
function imprimirResumo() {
    const printWindow = window.open('', '_blank');
    const resumoHTML = `
        <html>
            <head>
                <title>Resumo de Vendas - LicitaSis</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .header { text-align: center; margin-bottom: 30px; }
                    .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
                    .stat-item { border: 1px solid #ddd; padding: 15px; text-align: center; }
                    .stat-number { font-size: 24px; font-weight: bold; color: #2D893E; }
                    .stat-label { color: #666; margin-top: 5px; }
                    @media print { body { margin: 0; } }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>Resumo de Vendas</h1>
                    <p>Gerado em: ${new Date().toLocaleDateString('pt-BR')} às ${new Date().toLocaleTimeString('pt-BR')}</p>
                </div>
                <div class="stats">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $totalVendas; ?></div>
                        <div class="stat-label">Total de Vendas</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $vendasPendentes; ?></div>
                        <div class="stat-label">Vendas Pendentes</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $vendasFaturadas; ?></div>
                        <div class="stat-label">Vendas Faturadas</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $vendasEntregues; ?></div>
                        <div class="stat-label">Vendas Entregues</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $vendasCanceladas; ?></div>
                        <div class="stat-label">Vendas Canceladas</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">R$ <?php echo number_format($valorTotalVendas, 2, ',', '.'); ?></div>
                        <div class="stat-label">Valor Total</div>
                    </div>
                </div>
            </body>
        </html>
    `;
    
    printWindow.document.write(resumoHTML);
    printWindow.document.close();
    printWindow.print();
}

// Event listeners para funções utilitárias
document.addEventListener('keydown', function(e) {
    // Ctrl + E para exportar dados
    if (e.ctrlKey && e.key === 'e') {
        e.preventDefault();
        exportarDadosVendas();
    }
    
    // Ctrl + P para imprimir resumo
    if (e.ctrlKey && e.key === 'p') {
        e.preventDefault();
        imprimirResumo();
    }
});
</script>

</body>
</html>