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

// Verifica se o usuário tem permissão para acessar empenhos
$permissionManager->requirePermission('empenhos', 'view');

// Registra acesso à página
logUserAction('READ', 'empenhos_dashboard');

// Busca estatísticas de empenhos
$totalEmpenhos = 0;
$empenhosPendentes = 0;
$empenhosFaturados = 0;
$empenhosEntregues = 0;
$empenhosLiquidados = 0;
$empenhosPagos = 0;
$empenhosCancelados = 0;
$valorTotalEmpenhos = 0;
$valorPendente = 0;
$ultimoEmpenho = null;

try {
    // Total de empenhos
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM empenhos");
    $result = $stmt->fetch();
    $totalEmpenhos = $result['total'] ?? 0;
    
    // Empenhos por status
    $stmt = $pdo->query("
        SELECT 
            classificacao,
            COUNT(*) as quantidade,
            SUM(valor_total_empenho) as valor_status
        FROM empenhos 
        GROUP BY classificacao
    ");
    $statusData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($statusData as $data) {
        switch ($data['classificacao']) {
            case 'Pendente':
                $empenhosPendentes = $data['quantidade'];
                $valorPendente = $data['valor_status'] ?? 0;
                break;
            case 'Faturado':
                $empenhosFaturados = $data['quantidade'];
                break;
            case 'Entregue':
                $empenhosEntregues = $data['quantidade'];
                break;
            case 'Liquidado':
                $empenhosLiquidados = $data['quantidade'];
                break;
            case 'Pago':
                $empenhosPagos = $data['quantidade'];
                break;
            case 'Cancelado':
                $empenhosCancelados = $data['quantidade'];
                break;
        }
    }
    
    // Valor total dos empenhos
    $stmt = $pdo->query("SELECT SUM(valor_total_empenho) as valor_total FROM empenhos");
    $result = $stmt->fetch();
    $valorTotalEmpenhos = $result['valor_total'] ?? 0;
    
    // Empenhos do mês atual
    $stmt = $pdo->query("
        SELECT COUNT(*) as mes_atual 
        FROM empenhos 
        WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
        AND YEAR(created_at) = YEAR(CURRENT_DATE())
    ");
    $result = $stmt->fetch();
    $empenhosDoMes = $result['mes_atual'] ?? 0;
    
    // Último empenho
    $stmt = $pdo->query("
        SELECT 
            numero, 
            cliente_nome,
            valor_total_empenho,
            classificacao,
            created_at 
        FROM empenhos 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $ultimoEmpenho = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Erro ao buscar estatísticas de empenhos: " . $e->getMessage());
}

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Empenhos - LicitaSis", "empenhos");
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
        --liquidado-color: #6f42c1;
        --pago-color: #28a745;
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

    .stat-card.stat-faturado {
        border-left-color: var(--faturado-color);
    }

    .stat-card.stat-entregue {
        border-left-color: var(--entregue-color);
    }

    .stat-card.stat-liquidado {
        border-left-color: var(--liquidado-color);
    }

    .stat-card.stat-pago {
        border-left-color: var(--pago-color);
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

    .stat-card.stat-liquidado .stat-icon {
        color: var(--liquidado-color);
    }

    .stat-card.stat-pago .stat-icon {
        color: var(--pago-color);
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

    .status-faturado {
        background: rgba(0, 123, 255, 0.1);
        color: var(--faturado-color);
        border: 1px solid var(--faturado-color);
    }

    .status-entregue {
        background: rgba(32, 201, 151, 0.1);
        color: var(--entregue-color);
        border: 1px solid var(--entregue-color);
    }

    .status-liquidado {
        background: rgba(111, 66, 193, 0.1);
        color: var(--liquidado-color);
        border: 1px solid var(--liquidado-color);
    }

    .status-pago {
        background: rgba(40, 167, 69, 0.1);
        color: var(--pago-color);
        border: 1px solid var(--pago-color);
    }

    .status-cancelado {
        background: rgba(220, 53, 69, 0.1);
        color: var(--cancelado-color);
        border: 1px solid var(--cancelado-color);
    }

    /* Grid de cards principais */
    .empenhos-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 2rem;
        margin-top: 2.5rem;
    }

    .empenho-card {
        background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 2rem;
        text-align: center;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .empenho-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
    }

    .empenho-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
        border-color: var(--secondary-color);
    }

    .empenho-card h3 {
        color: var(--primary-color);
        font-size: 1.3rem;
        margin-bottom: 1rem;
        font-weight: 600;
    }

    .empenho-card .icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .empenho-card p {
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

    /* Último empenho */
    .last-empenho-info {
        margin-top: 2rem;
    }

    .last-empenho-card {
        background: white;
        padding: 1.5rem 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border-left: 4px solid var(--secondary-color);
        transition: var(--transition);
    }

    .last-empenho-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    }

    .last-empenho-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .last-empenho-header i {
        font-size: 1.5rem;
        color: var(--secondary-color);
        flex-shrink: 0;
    }

    .last-empenho-header h4 {
        color: var(--primary-color);
        font-size: 1.1rem;
        margin: 0;
    }

    .last-empenho-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }

    .empenho-detail {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .empenho-detail strong {
        color: var(--dark-gray);
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .empenho-detail span {
        color: var(--medium-gray);
        font-size: 0.95rem;
    }

    .empenho-number {
        color: var(--primary-color) !important;
        font-weight: 700 !important;
        font-size: 1.1rem !important;
    }

    .empenho-value {
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

        .empenhos-grid {
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

        .empenhos-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .empenho-card {
            padding: 1.5rem;
        }

        .empenho-card .icon {
            font-size: 2.5rem;
        }

        .empenho-card h3 {
            font-size: 1.2rem;
        }

        .last-empenho-header {
            flex-direction: column;
            text-align: center;
            gap: 0.75rem;
        }

        .last-empenho-content {
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

        .empenho-card {
            padding: 1.25rem;
        }

        .empenho-card .icon {
            font-size: 2rem;
        }

        .empenho-card h3 {
            font-size: 1.1rem;
        }

        .btn {
            padding: 0.75rem 1.25rem;
            font-size: 0.9rem;
        }
    }
</style>

<div class="container">
    <h2><i class="fas fa-file-contract"></i> Empenhos</h2>

    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <i class="stat-icon fas fa-file-alt"></i>
            <div class="stat-number" id="totalEmpenhos"><?php echo $totalEmpenhos; ?></div>
            <div class="stat-label">Total de Empenhos</div>
        </div>
        
        <div class="stat-card stat-pending">
            <i class="stat-icon fas fa-clock"></i>
            <div class="stat-number" id="empenhosPendentes"><?php echo $empenhosPendentes; ?></div>
            <div class="stat-label">Pendentes</div>
            <div class="stat-sublabel">
                R$ <?php echo number_format($valorPendente, 2, ',', '.'); ?>
            </div>
        </div>
        
        <div class="stat-card stat-faturado">
            <i class="stat-icon fas fa-file-invoice"></i>
            <div class="stat-number" id="empenhosFaturados"><?php echo $empenhosFaturados; ?></div>
            <div class="stat-label">Faturados</div>
        </div>
        
        <div class="stat-card stat-entregue">
            <i class="stat-icon fas fa-truck"></i>
            <div class="stat-number" id="empenhosEntregues"><?php echo $empenhosEntregues; ?></div>
            <div class="stat-label">Entregues</div>
        </div>
        
        <div class="stat-card stat-liquidado">
            <i class="stat-icon fas fa-calculator"></i>
            <div class="stat-number" id="empenhosLiquidados"><?php echo $empenhosLiquidados; ?></div>
            <div class="stat-label">Liquidados</div>
        </div>
        
        <div class="stat-card stat-pago">
            <i class="stat-icon fas fa-check-circle"></i>
            <div class="stat-number" id="empenhosPagos"><?php echo $empenhosPagos; ?></div>
            <div class="stat-label">Pagos</div>
        </div>
        
        <div class="stat-card stat-cancelado">
            <i class="stat-icon fas fa-times-circle"></i>
            <div class="stat-number" id="empenhosCancelados"><?php echo $empenhosCancelados; ?></div>
            <div class="stat-label">Cancelados</div>
        </div>
        
        <div class="stat-card stat-value">
            <i class="stat-icon fas fa-dollar-sign"></i>
            <div class="stat-number">
                R$ <?php echo number_format($valorTotalEmpenhos, 2, ',', '.'); ?>
            </div>
            <div class="stat-label">Valor Total</div>
            <div class="stat-sublabel">
                <?php echo $empenhosDoMes ?? 0; ?> no mês atual
            </div>
        </div>
    </div>

    <!-- Progress bars para visualização de status -->
    <div class="progress-container">
        <div class="progress-title">Distribuição de Status dos Empenhos</div>
        
        <?php if ($totalEmpenhos > 0): ?>
        <div class="progress-item">
            <span class="progress-label">Pendentes</span>
            <span class="progress-value"><?php echo round(($empenhosPendentes / $totalEmpenhos) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($empenhosPendentes / $totalEmpenhos) * 100; ?>" style="background: var(--pending-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Faturados</span>
            <span class="progress-value"><?php echo round(($empenhosFaturados / $totalEmpenhos) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($empenhosFaturados / $totalEmpenhos) * 100; ?>" style="background: var(--faturado-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Entregues</span>
            <span class="progress-value"><?php echo round(($empenhosEntregues / $totalEmpenhos) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($empenhosEntregues / $totalEmpenhos) * 100; ?>" style="background: var(--entregue-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Liquidados</span>
            <span class="progress-value"><?php echo round(($empenhosLiquidados / $totalEmpenhos) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($empenhosLiquidados / $totalEmpenhos) * 100; ?>" style="background: var(--liquidado-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Pagos</span>
            <span class="progress-value"><?php echo round(($empenhosPagos / $totalEmpenhos) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($empenhosPagos / $totalEmpenhos) * 100; ?>" style="background: var(--pago-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Cancelados</span>
            <span class="progress-value"><?php echo round(($empenhosCancelados / $totalEmpenhos) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($empenhosCancelados / $totalEmpenhos) * 100; ?>" style="background: var(--cancelado-color);"></div>
        </div>
        <?php else: ?>
        <p style="text-align: center; color: var(--medium-gray); font-style: italic;">
            Nenhum empenho cadastrado ainda
        </p>
        <?php endif; ?>
    </div>

    <!-- Cards principais -->
    <div class="empenhos-grid">
        <!-- Card Cadastrar Empenho -->
        <?php if ($permissionManager->hasPagePermission('empenhos', 'create')): ?>
        <div class="empenho-card">
            <div class="icon">
                <i class="fas fa-plus-circle"></i>
            </div>
            <h3>Cadastrar Empenho</h3>
            <p>Registre novos empenhos com todas as informações necessárias para controle e acompanhamento das licitações e contratos.</p>
            <div class="card-buttons">
                <a href="cadastro_empenho.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Novo Empenho
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Card Consultar Empenhos -->
        <div class="empenho-card">
            <div class="icon">
                <i class="fas fa-search"></i>
            </div>
            <h3>Consultar Empenhos</h3>
            <p>Visualize, edite e gerencie todos os empenhos cadastrados. Acesse informações detalhadas, histórico e documentos anexos.</p>
            <div class="card-buttons">
                <a href="consulta_empenho.php" class="btn btn-success">
                    <i class="fas fa-search"></i> Ver Empenhos (<?php echo $totalEmpenhos; ?>)
                </a>
            </div>
        </div>

        <!-- Card Empenhos Pendentes -->
        <div class="empenho-card">
            <div class="icon">
                <i class="fas fa-clock"></i>
            </div>
            <h3>Empenhos Pendentes</h3>
            <p>Acompanhe empenhos que aguardam processamento, aprovação ou próximas etapas do fluxo de trabalho.</p>
            <div class="card-buttons">
                <a href="empenhos_pendentes.php" class="btn btn-warning">
                    <i class="fas fa-clock"></i> Ver Pendentes (<?php echo $empenhosPendentes; ?>)
                </a>
            </div>
        </div>

        <!-- Card Empenhos Pagos -->
        <div class="empenho-card">
            <div class="icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>Empenhos Pagos</h3>
            <p>Consulte o histórico de empenhos finalizados e pagos. Acesse relatórios de conclusão e documentação final.</p>
            <div class="card-buttons">
                <a href="empenhos_pagos.php" class="btn btn-success">
                    <i class="fas fa-check-circle"></i> Ver Pagos (<?php echo $empenhosPagos; ?>)
                </a>
            </div>
        </div>

        <!-- Card Status e Workflow -->
        <div class="empenho-card">
            <div class="icon">
                <i class="fas fa-tasks"></i>
            </div>
            <h3>Gestão de Status</h3>
            <p>Controle o fluxo de status dos empenhos: Pendente → Faturado → Entregue → Liquidado → Pago. Atualize classificações facilmente.</p>
            <div class="card-buttons">
                <a href="status_empenhos.php" class="btn btn-info">
                    <i class="fas fa-clipboard-check"></i> Gerenciar Status
                </a>
            </div>
        </div>

        <!-- Card Relatórios -->
        <div class="empenho-card">
            <div class="icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <h3>Relatórios Gerenciais</h3>
            <p>Gere relatórios detalhados de empenhos por período, fornecedor, status e análises financeiras para tomada de decisão.</p>
            <div class="card-buttons">
                <a href="relatorio_empenhos.php" class="btn btn-warning">
                    <i class="fas fa-file-chart-line"></i> Ver Relatórios
                </a>
            </div>
        </div>

        <!-- Card Documentos -->
        <div class="empenho-card">
            <div class="icon">
                <i class="fas fa-file-pdf"></i>
            </div>
            <h3>Documentos e Anexos</h3>
            <p>Gerencie documentos relacionados aos empenhos, contratos, notas de empenho e demais anexos do processo licitatório.</p>
            <div class="card-buttons">
                <a href="documentos_empenhos.php" class="btn btn-purple">
                    <i class="fas fa-folder-open"></i> Gerenciar Documentos
                </a>
            </div>
        </div>

        <!-- Card Controle Financeiro -->
        <div class="empenho-card">
            <div class="icon">
                <i class="fas fa-calculator"></i>
            </div>
            <h3>Controle Financeiro</h3>
            <p>Acompanhe valores empenhados, liquidados e pagos. Controle o orçamento e monitore a execução financeira dos empenhos.</p>
            <div class="card-buttons">
                <a href="financeiro_empenhos.php" class="btn btn-success">
                    <i class="fas fa-dollar-sign"></i> Controle Financeiro
                </a>
            </div>
        </div>

        <!-- Card Auditoria -->
        <?php if ($permissionManager->hasPagePermission('empenhos', 'audit')): ?>
        <div class="empenho-card">
            <div class="icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h3>Auditoria e Logs</h3>
            <p>Visualize o histórico completo de alterações, logs de sistema e trilha de auditoria para compliance e controle interno.</p>
            <div class="card-buttons">
                <a href="auditoria_empenhos.php" class="btn btn-warning">
                    <i class="fas fa-history"></i> Ver Auditoria
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Último empenho cadastrado -->
    <?php if ($ultimoEmpenho): ?>
    <div class="last-empenho-info">
        <div class="last-empenho-card">
            <div class="last-empenho-header">
                <i class="fas fa-clock"></i>
                <h4>Último Empenho Cadastrado</h4>
            </div>
            <div class="last-empenho-content">
                <div class="empenho-detail">
                    <strong>Número do Empenho:</strong>
                    <span class="empenho-number"><?php echo htmlspecialchars($ultimoEmpenho['numero']); ?></span>
                </div>
                <div class="empenho-detail">
                    <strong>Cliente:</strong>
                    <span><?php echo htmlspecialchars($ultimoEmpenho['cliente_nome']); ?></span>
                </div>
                <div class="empenho-detail">
                    <strong>Valor:</strong>
                    <span class="empenho-value">R$ <?php echo number_format($ultimoEmpenho['valor_total_empenho'], 2, ',', '.'); ?></span>
                </div>
                <div class="empenho-detail">
                    <strong>Status:</strong>
                    <span class="status-indicator status-<?php echo strtolower($ultimoEmpenho['classificacao']); ?>">
                        <i class="fas fa-circle"></i>
                        <?php echo htmlspecialchars($ultimoEmpenho['classificacao']); ?>
                    </span>
                </div>
                <div class="empenho-detail">
                    <strong>Cadastrado em:</strong>
                    <span><?php echo date('d/m/Y \à\s H:i', strtotime($ultimoEmpenho['created_at'])); ?></span>
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
// JavaScript específico da página de empenhos
document.addEventListener('DOMContentLoaded', function() {
    console.log('Módulo de Empenhos iniciado');

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
    const cards = document.querySelectorAll('.empenho-card');
    
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
    console.log('Módulo de Empenhos carregado com sucesso!');
    console.log('Total de empenhos:', <?php echo $totalEmpenhos; ?>);
    console.log('Empenhos pendentes:', <?php echo $empenhosPendentes; ?>);
    console.log('Empenhos faturados:', <?php echo $empenhosFaturados; ?>);
    console.log('Empenhos entregues:', <?php echo $empenhosEntregues; ?>);
    console.log('Empenhos liquidados:', <?php echo $empenhosLiquidados; ?>);
    console.log('Empenhos pagos:', <?php echo $empenhosPagos; ?>);
    console.log('Empenhos cancelados:', <?php echo $empenhosCancelados; ?>);
    console.log('Valor total: R, <?php echo number_format($valorTotalEmpenhos, 2, ',', '.'); ?>);
});
</script>

</body>
</html>