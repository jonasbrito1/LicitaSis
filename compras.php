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

// Verifica se o usuário tem permissão para acessar compras
$permissionManager->requirePermission('compras', 'view');

// Registra acesso à página
logUserAction('READ', 'compras_dashboard');

// Busca estatísticas de compras
$totalCompras = 0;
$comprasPendentes = 0;
$comprasAprovadas = 0;
$comprasEntregues = 0;
$comprasRecebidas = 0;
$comprasPagas = 0;
$comprasCanceladas = 0;
$valorTotalCompras = 0;
$valorPendente = 0;
$ultimaCompra = null;

try {
    // Total de compras
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM compras");
    $result = $stmt->fetch();
    $totalCompras = $result['total'] ?? 0;
    
    // Compras por status
    $stmt = $pdo->query("
        SELECT 
            status,
            COUNT(*) as quantidade,
            SUM(valor_total) as valor_status
        FROM compras 
        GROUP BY status
    ");
    $statusData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($statusData as $data) {
        switch ($data['status']) {
            case 'Pendente':
                $comprasPendentes = $data['quantidade'];
                $valorPendente = $data['valor_status'] ?? 0;
                break;
            case 'Aprovada':
                $comprasAprovadas = $data['quantidade'];
                break;
            case 'Entregue':
                $comprasEntregues = $data['quantidade'];
                break;
            case 'Recebida':
                $comprasRecebidas = $data['quantidade'];
                break;
            case 'Paga':
                $comprasPagas = $data['quantidade'];
                break;
            case 'Cancelada':
                $comprasCanceladas = $data['quantidade'];
                break;
        }
    }
    
    // Valor total das compras
    $stmt = $pdo->query("SELECT SUM(valor_total) as valor_total FROM compras");
    $result = $stmt->fetch();
    $valorTotalCompras = $result['valor_total'] ?? 0;
    
    // Compras do mês atual
    $stmt = $pdo->query("
        SELECT COUNT(*) as mes_atual 
        FROM compras 
        WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
        AND YEAR(created_at) = YEAR(CURRENT_DATE())
    ");
    $result = $stmt->fetch();
    $comprasDoMes = $result['mes_atual'] ?? 0;
    
    // Última compra
    $stmt = $pdo->query("
        SELECT 
            numero_pedido, 
            fornecedor_nome,
            valor_total,
            status,
            created_at 
        FROM compras 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $ultimaCompra = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Erro ao buscar estatísticas de compras: " . $e->getMessage());
}

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Compras - LicitaSis", "compras");
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
        --aprovada-color: #007bff;
        --entregue-color: #20c997;
        --recebida-color: #6f42c1;
        --paga-color: #28a745;
        --cancelada-color: #dc3545;
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
    .stat-card.stat-total {
        border-left-color: var(--primary-color);
    }

    .stat-card.stat-pending {
        border-left-color: var(--pending-color);
    }

    .stat-card.stat-aprovada {
        border-left-color: var(--aprovada-color);
    }

    .stat-card.stat-entregue {
        border-left-color: var(--entregue-color);
    }

    .stat-card.stat-recebida {
        border-left-color: var(--recebida-color);
    }

    .stat-card.stat-paga {
        border-left-color: var(--paga-color);
    }

    .stat-card.stat-cancelada {
        border-left-color: var(--cancelada-color);
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

    .stat-card.stat-aprovada .stat-icon {
        color: var(--aprovada-color);
    }

    .stat-card.stat-entregue .stat-icon {
        color: var(--entregue-color);
    }

    .stat-card.stat-recebida .stat-icon {
        color: var(--recebida-color);
    }

    .stat-card.stat-paga .stat-icon {
        color: var(--paga-color);
    }

    .stat-card.stat-cancelada .stat-icon {
        color: var(--cancelada-color);
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

    .status-aprovada {
        background: rgba(0, 123, 255, 0.1);
        color: var(--aprovada-color);
        border: 1px solid var(--aprovada-color);
    }

    .status-entregue {
        background: rgba(32, 201, 151, 0.1);
        color: var(--entregue-color);
        border: 1px solid var(--entregue-color);
    }

    .status-recebida {
        background: rgba(111, 66, 193, 0.1);
        color: var(--recebida-color);
        border: 1px solid var(--recebida-color);
    }

    .status-paga {
        background: rgba(40, 167, 69, 0.1);
        color: var(--paga-color);
        border: 1px solid var(--paga-color);
    }

    .status-cancelada {
        background: rgba(220, 53, 69, 0.1);
        color: var(--cancelada-color);
        border: 1px solid var(--cancelada-color);
    }

    /* Grid de cards principais */
    .compras-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 2rem;
        margin-top: 2.5rem;
    }

    .compra-card {
        background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 2rem;
        text-align: center;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .compra-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
    }

    .compra-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
        border-color: var(--secondary-color);
    }

    .compra-card h3 {
        color: var(--primary-color);
        font-size: 1.3rem;
        margin-bottom: 1rem;
        font-weight: 600;
    }

    .compra-card .icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .compra-card p {
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

    /* Última compra */
    .last-compra-info {
        margin-top: 2rem;
    }

    .last-compra-card {
        background: white;
        padding: 1.5rem 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border-left: 4px solid var(--secondary-color);
        transition: var(--transition);
    }

    .last-compra-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    }

    .last-compra-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .last-compra-header i {
        font-size: 1.5rem;
        color: var(--secondary-color);
        flex-shrink: 0;
    }

    .last-compra-header h4 {
        color: var(--primary-color);
        font-size: 1.1rem;
        margin: 0;
    }

    .last-compra-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }

    .compra-detail {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .compra-detail strong {
        color: var(--dark-gray);
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .compra-detail span {
        color: var(--medium-gray);
        font-size: 0.95rem;
    }

    .compra-number {
        color: var(--primary-color) !important;
        font-weight: 700 !important;
        font-size: 1.1rem !important;
    }

    .compra-value {
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

        .compras-grid {
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

        .compras-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .compra-card {
            padding: 1.5rem;
        }

        .compra-card .icon {
            font-size: 2.5rem;
        }

        .compra-card h3 {
            font-size: 1.2rem;
        }

        .last-compra-header {
            flex-direction: column;
            text-align: center;
            gap: 0.75rem;
        }

        .last-compra-content {
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

        .compra-card {
            padding: 1.25rem;
        }

        .compra-card .icon {
            font-size: 2rem;
        }

        .compra-card h3 {
            font-size: 1.1rem;
        }

        .btn {
            padding: 0.75rem 1.25rem;
            font-size: 0.9rem;
        }
    }
</style>

<div class="container">
    <h2><i class="fas fa-shopping-cart"></i> Compras</h2>

    <!-- Cards principais -->
    <div class="compras-grid">
        <!-- Card Cadastrar Compra -->
        <?php if ($permissionManager->hasPagePermission('compras', 'create')): ?>
        <div class="compra-card">
            <div class="icon">
                <i class="fas fa-plus-circle"></i>
            </div>
            <h3>Cadastrar Compra</h3>
            <p>Registre novas compras com informações completas de fornecedores, produtos, valores e prazos de entrega.</p>
            <div class="card-buttons">
                <a href="cadastro_compras.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Nova Compra
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Card Consultar Compras -->
        <div class="compra-card">
            <div class="icon">
                <i class="fas fa-search"></i>
            </div>
            <h3>Consultar Compras</h3>
            <p>Visualize, edite e gerencie todas as compras realizadas. Acompanhe status, valores e histórico completo.</p>
            <div class="card-buttons">
                <a href="consulta_compras.php" class="btn btn-success">
                    <i class="fas fa-search"></i> Ver Compras (<?php echo $totalCompras; ?>)
                </a>
            </div>
        </div>

        <!-- Card Compras Pendentes -->
        <div class="compra-card">
            <div class="icon">
                <i class="fas fa-clock"></i>
            </div>
            <h3>Compras Pendentes</h3>
            <p>Acompanhe compras que aguardam aprovação, confirmação de fornecedores ou processamento.</p>
            <div class="card-buttons">
                <a href="compras_pendentes.php" class="btn btn-warning">
                    <i class="fas fa-clock"></i> Ver Pendentes (<?php echo $comprasPendentes; ?>)
                </a>
            </div>
        </div>

        <!-- Card Compras Aprovadas -->
        <div class="compra-card">
            <div class="icon">
                <i class="fas fa-check"></i>
            </div>
            <h3>Compras Aprovadas</h3>
            <p>Visualize compras aprovadas que aguardam entrega dos fornecedores e recebimento de produtos.</p>
            <div class="card-buttons">
                <a href="compras_aprovadas.php" class="btn btn-info">
                    <i class="fas fa-check"></i> Ver Aprovadas (<?php echo $comprasAprovadas; ?>)
                </a>
            </div>
        </div>

        <!-- Card Gestão de Fornecedores -->
        <div class="compra-card">
            <div class="icon">
                <i class="fas fa-truck"></i>
            </div>
            <h3>Gestão de Fornecedores</h3>
            <p>Gerencie relacionamento com fornecedores, avalie performance e acompanhe histórico de compras.</p>
            <div class="card-buttons">
                <a href="fornecedores.php" class="btn btn-purple">
                    <i class="fas fa-building"></i> Gerenciar Fornecedores
                </a>
            </div>
        </div>

        <!-- Card Recebimento -->
        <div class="compra-card">
            <div class="icon">
                <i class="fas fa-box-open"></i>
            </div>
            <h3>Recebimento de Produtos</h3>
            <p>Controle o recebimento de mercadorias, conferência de qualidade e atualização de status de entrega.</p>
            <div class="card-buttons">
                <a href="recebimento_compras.php" class="btn btn-success">
                    <i class="fas fa-clipboard-check"></i> Gerenciar Recebimentos
                </a>
            </div>
        </div>

        <!-- Card Relatórios -->
        <div class="compra-card">
            <div class="icon">
                <i class="fas fa-chart-pie"></i>
            </div>
            <h3>Relatórios de Compras</h3>
            <p>Gere relatórios detalhados por período, fornecedor, categoria de produtos e análises de gastos.</p>
            <div class="card-buttons">
                <a href="relatorio_compras.php" class="btn btn-warning">
                    <i class="fas fa-file-chart-pie"></i> Ver Relatórios
                </a>
            </div>
        </div>

        <!-- Card Controle de Estoque -->
        <div class="compra-card">
            <div class="icon">
                <i class="fas fa-warehouse"></i>
            </div>
            <h3>Controle de Estoque</h3>
            <p>Integração com estoque para controle automático de entrada de produtos e gestão de inventário.</p>
            <div class="card-buttons">
                <a href="estoque_compras.php" class="btn btn-info">
                    <i class="fas fa-boxes"></i> Controlar Estoque
                </a>
            </div>
        </div>

        <!-- Card Auditoria -->
        <?php if ($permissionManager->hasPagePermission('compras', 'audit')): ?>
        <div class="compra-card">
            <div class="icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h3>Auditoria e Logs</h3>
            <p>Visualize histórico completo de alterações, aprovações e trilha de auditoria para controle interno.</p>
            <div class="card-buttons">
                <a href="auditoria_compras.php" class="btn btn-warning">
                    <i class="fas fa-history"></i> Ver Auditoria
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <i class="stat-icon fas fa-shopping-cart"></i>
            <div class="stat-number" id="totalCompras"><?php echo $totalCompras; ?></div>
            <div class="stat-label">Total de Compras</div>
        </div>
        
        <div class="stat-card stat-pending">
            <i class="stat-icon fas fa-clock"></i>
            <div class="stat-number" id="comprasPendentes"><?php echo $comprasPendentes; ?></div>
            <div class="stat-label">Pendentes</div>
            <div class="stat-sublabel">
                R$ <?php echo number_format($valorPendente, 2, ',', '.'); ?>
            </div>
        </div>
        
        <div class="stat-card stat-aprovada">
            <i class="stat-icon fas fa-check"></i>
            <div class="stat-number" id="comprasAprovadas"><?php echo $comprasAprovadas; ?></div>
            <div class="stat-label">Aprovadas</div>
        </div>
        
        <div class="stat-card stat-entregue">
            <i class="stat-icon fas fa-truck"></i>
            <div class="stat-number" id="comprasEntregues"><?php echo $comprasEntregues; ?></div>
            <div class="stat-label">Entregues</div>
        </div>
        
        <div class="stat-card stat-recebida">
            <i class="stat-icon fas fa-box-open"></i>
            <div class="stat-number" id="comprasRecebidas"><?php echo $comprasRecebidas; ?></div>
            <div class="stat-label">Recebidas</div>
        </div>
        
        <div class="stat-card stat-paga">
            <i class="stat-icon fas fa-check-circle"></i>
            <div class="stat-number" id="comprasPagas"><?php echo $comprasPagas; ?></div>
            <div class="stat-label">Pagas</div>
        </div>
        
        <div class="stat-card stat-cancelada">
            <i class="stat-icon fas fa-times-circle"></i>
            <div class="stat-number" id="comprasCanceladas"><?php echo $comprasCanceladas; ?></div>
            <div class="stat-label">Canceladas</div>
        </div>
        
        <div class="stat-card stat-value">
            <i class="stat-icon fas fa-dollar-sign"></i>
            <div class="stat-number">
                R$ <?php echo number_format($valorTotalCompras, 2, ',', '.'); ?>
            </div>
            <div class="stat-label">Valor Total</div>
            <div class="stat-sublabel">
                <?php echo $comprasDoMes ?? 0; ?> no mês atual
            </div>
        </div>
    </div>

    <!-- Progress bars para visualização de status -->
    <div class="progress-container">
        <div class="progress-title">Distribuição de Status das Compras</div>
        
        <?php if ($totalCompras > 0): ?>
        <div class="progress-item">
            <span class="progress-label">Pendentes</span>
            <span class="progress-value"><?php echo round(($comprasPendentes / $totalCompras) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($comprasPendentes / $totalCompras) * 100; ?>" style="background: var(--pending-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Aprovadas</span>
            <span class="progress-value"><?php echo round(($comprasAprovadas / $totalCompras) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($comprasAprovadas / $totalCompras) * 100; ?>" style="background: var(--aprovada-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Entregues</span>
            <span class="progress-value"><?php echo round(($comprasEntregues / $totalCompras) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($comprasEntregues / $totalCompras) * 100; ?>" style="background: var(--entregue-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Recebidas</span>
            <span class="progress-value"><?php echo round(($comprasRecebidas / $totalCompras) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($comprasRecebidas / $totalCompras) * 100; ?>" style="background: var(--recebida-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Pagas</span>
            <span class="progress-value"><?php echo round(($comprasPagas / $totalCompras) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($comprasPagas / $totalCompras) * 100; ?>" style="background: var(--paga-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Canceladas</span>
            <span class="progress-value"><?php echo round(($comprasCanceladas / $totalCompras) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($comprasCanceladas / $totalCompras) * 100; ?>" style="background: var(--cancelada-color);"></div>
        </div>
        <?php else: ?>
        <p style="text-align: center; color: var(--medium-gray); font-style: italic;">
            Nenhuma compra cadastrada ainda
        </p>
        <?php endif; ?>
    </div>

    <!-- Última compra cadastrada -->
    <?php if ($ultimaCompra): ?>
    <div class="last-compra-info">
        <div class="last-compra-card">
            <div class="last-compra-header">
                <i class="fas fa-clock"></i>
                <h4>Última Compra Cadastrada</h4>
            </div>
            <div class="last-compra-content">
                <div class="compra-detail">
                    <strong>Número do Pedido:</strong>
                    <span class="compra-number"><?php echo htmlspecialchars($ultimaCompra['numero_pedido']); ?></span>
                </div>
                <div class="compra-detail">
                    <strong>Fornecedor:</strong>
                    <span><?php echo htmlspecialchars($ultimaCompra['fornecedor_nome']); ?></span>
                </div>
                <div class="compra-detail">
                    <strong>Valor:</strong>
                    <span class="compra-value">R$ <?php echo number_format($ultimaCompra['valor_total'], 2, ',', '.'); ?></span>
                </div>
                <div class="compra-detail">
                    <strong>Status:</strong>
                    <span class="status-indicator status-<?php echo strtolower($ultimaCompra['status']); ?>">
                        <i class="fas fa-circle"></i>
                        <?php echo htmlspecialchars($ultimaCompra['status']); ?>
                    </span>
                </div>
                <div class="compra-detail">
                    <strong>Cadastrada em:</strong>
                    <span><?php echo date('d/m/Y \à\s H:i', strtotime($ultimaCompra['created_at'])); ?></span>
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
// JavaScript específico da página de compras
document.addEventListener('DOMContentLoaded', function() {
    console.log('Módulo de Compras iniciado');

    // Anima os números das estatísticas
    function animateNumber(element, finalNumber) {
        if (!element || isNaN(finalNumber)) return;
        
        let currentNumber = 0;
        const increment = Math.max(1, Math.ceil(finalNumber / 30));
        const duration = 1000;
        const stepTime = duration / (finalNumber / increment);
        
        const isMonetary = element.textContent.includes('R$');
        
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
                    if (text.includes('R$')) {
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
    const cards = document.querySelectorAll('.compra-card');
    
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 150);
    });

    // Registra analytics da página
    console.log('Módulo de Compras carregado com sucesso!');
    console.log('Total de compras:', <?php echo $totalCompras; ?>);
    console.log('Compras pendentes:', <?php echo $comprasPendentes; ?>);
    console.log('Compras aprovadas:', <?php echo $comprasAprovadas; ?>);
    console.log('Compras entregues:', <?php echo $comprasEntregues; ?>);
    console.log('Compras recebidas:', <?php echo $comprasRecebidas; ?>);
    console.log('Compras pagas:', <?php echo $comprasPagas; ?>);
    console.log('Compras canceladas:', <?php echo $comprasCanceladas; ?>);
    console.log('Valor total: R$ <?php echo number_format($valorTotalCompras, 2, ',', '.'); ?>');
});
</script>

</body>
</html>