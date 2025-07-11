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

// Verifica se o usuário tem permissão para acessar fornecedores
$permissionManager->requirePermission('fornecedores', 'view');

// Registra acesso à página
logUserAction('READ', 'fornecedores_dashboard');

// Busca estatísticas de fornecedores
$totalFornecedores = 0;
$fornecedoresAtivos = 0;
$fornecedoresInativos = 0;
$fornecedoresBloqueados = 0;
$fornecedoresNacionais = 0;
$fornecedoresInternacionais = 0;
$totalCompras = 0;
$fornecedoresNovos = 0;
$ultimoFornecedor = null;

try {
    // Total de fornecedores
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM fornecedores");
    $result = $stmt->fetch();
    $totalFornecedores = $result['total'] ?? 0;
    
    // Fornecedores por status
    $stmt = $pdo->query("
        SELECT 
            status,
            COUNT(*) as quantidade
        FROM fornecedores 
        GROUP BY status
    ");
    $statusData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($statusData as $data) {
        switch ($data['status']) {
            case 'Ativo':
                $fornecedoresAtivos = $data['quantidade'];
                break;
            case 'Inativo':
                $fornecedoresInativos = $data['quantidade'];
                break;
            case 'Bloqueado':
                $fornecedoresBloqueados = $data['quantidade'];
                break;
        }
    }
    
    // Fornecedores por tipo
    $stmt = $pdo->query("
        SELECT 
            CASE 
                WHEN LENGTH(REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', '')) = 14 THEN 'Nacional'
                ELSE 'Internacional'
            END as tipo,
            COUNT(*) as quantidade
        FROM fornecedores 
        GROUP BY tipo
    ");
    $tipoData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($tipoData as $data) {
        if ($data['tipo'] == 'Nacional') {
            $fornecedoresNacionais = $data['quantidade'];
        } else {
            $fornecedoresInternacionais = $data['quantidade'];
        }
    }
    
    // Total de compras realizadas
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT fornecedor_id) as total_com_compras 
        FROM compras
    ");
    $result = $stmt->fetch();
    $totalCompras = $result['total_com_compras'] ?? 0;
    
    // Fornecedores novos (últimos 30 dias)
    $stmt = $pdo->query("
        SELECT COUNT(*) as novos 
        FROM fornecedores 
        WHERE created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
    ");
    $result = $stmt->fetch();
    $fornecedoresNovos = $result['novos'] ?? 0;
    
    // Último fornecedor cadastrado
    $stmt = $pdo->query("
        SELECT 
            id,
            razao_social,
            nome_fantasia,
            cnpj,
            status,
            created_at 
        FROM fornecedores 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $ultimoFornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Erro ao buscar estatísticas de fornecedores: " . $e->getMessage());
}

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Fornecedores - LicitaSis", "fornecedores");
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
        --active-color: #28a745;
        --inactive-color: #6c757d;
        --blocked-color: #dc3545;
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

    .stat-card.stat-active {
        border-left-color: var(--active-color);
    }

    .stat-card.stat-inactive {
        border-left-color: var(--inactive-color);
    }

    .stat-card.stat-blocked {
        border-left-color: var(--blocked-color);
    }

    .stat-card.stat-national {
        border-left-color: var(--info-color);
    }

    .stat-card.stat-international {
        border-left-color: var(--warning-color);
    }

    .stat-card.stat-purchases {
        border-left-color: var(--accent-color);
    }

    .stat-card.stat-new {
        border-left-color: var(--success-color);
    }

    .stat-icon {
        font-size: 2.5rem;
        color: var(--secondary-color);
        margin-bottom: 1rem;
        display: block;
    }

    .stat-card.stat-active .stat-icon {
        color: var(--active-color);
    }

    .stat-card.stat-inactive .stat-icon {
        color: var(--inactive-color);
    }

    .stat-card.stat-blocked .stat-icon {
        color: var(--blocked-color);
    }

    .stat-card.stat-national .stat-icon {
        color: var(--info-color);
    }

    .stat-card.stat-international .stat-icon {
        color: var(--warning-color);
    }

    .stat-card.stat-purchases .stat-icon {
        color: var(--accent-color);
    }

    .stat-card.stat-new .stat-icon {
        color: var(--success-color);
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

    .status-ativo {
        background: rgba(40, 167, 69, 0.1);
        color: var(--active-color);
        border: 1px solid var(--active-color);
    }

    .status-inativo {
        background: rgba(108, 117, 125, 0.1);
        color: var(--inactive-color);
        border: 1px solid var(--inactive-color);
    }

    .status-bloqueado {
        background: rgba(220, 53, 69, 0.1);
        color: var(--blocked-color);
        border: 1px solid var(--blocked-color);
    }

    /* Grid de cards principais */
    .fornecedores-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 2rem;
        margin-top: 2.5rem;
    }

    .fornecedor-card {
        background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 2rem;
        text-align: center;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .fornecedor-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
    }

    .fornecedor-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
        border-color: var(--secondary-color);
    }

    .fornecedor-card h3 {
        color: var(--primary-color);
        font-size: 1.3rem;
        margin-bottom: 1rem;
        font-weight: 600;
    }

    .fornecedor-card .icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .fornecedor-card p {
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

    /* Último fornecedor */
    .last-fornecedor-info {
        margin-top: 2rem;
    }

    .last-fornecedor-card {
        background: white;
        padding: 1.5rem 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border-left: 4px solid var(--secondary-color);
        transition: var(--transition);
    }

    .last-fornecedor-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    }

    .last-fornecedor-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .last-fornecedor-header i {
        font-size: 1.5rem;
        color: var(--secondary-color);
        flex-shrink: 0;
    }

    .last-fornecedor-header h4 {
        color: var(--primary-color);
        font-size: 1.1rem;
        margin: 0;
    }

    .last-fornecedor-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }

    .fornecedor-detail {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .fornecedor-detail strong {
        color: var(--dark-gray);
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .fornecedor-detail span {
        color: var(--medium-gray);
        font-size: 0.95rem;
    }

    .fornecedor-name {
        color: var(--primary-color) !important;
        font-weight: 700 !important;
        font-size: 1.1rem !important;
    }

    .fornecedor-cnpj {
        color: var(--info-color) !important;
        font-weight: 600 !important;
    }

    /* Responsividade */
    @media (max-width: 1200px) {
        .container {
            margin: 2rem 1.5rem;
            padding: 2rem;
        }

        .fornecedores-grid {
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

        .fornecedores-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .fornecedor-card {
            padding: 1.5rem;
        }

        .fornecedor-card .icon {
            font-size: 2.5rem;
        }

        .fornecedor-card h3 {
            font-size: 1.2rem;
        }

        .last-fornecedor-header {
            flex-direction: column;
            text-align: center;
            gap: 0.75rem;
        }

        .last-fornecedor-content {
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

        .fornecedor-card {
            padding: 1.25rem;
        }

        .fornecedor-card .icon {
            font-size: 2rem;
        }

        .fornecedor-card h3 {
            font-size: 1.1rem;
        }

        .btn {
            padding: 0.75rem 1.25rem;
            font-size: 0.9rem;
        }
    }
</style>

<div class="container">
    <h2><i class="fas fa-truck"></i> Fornecedores</h2>

    <!-- Cards principais -->
    <div class="fornecedores-grid">
        <!-- Card Cadastrar Fornecedor -->
        <?php if ($permissionManager->hasPagePermission('fornecedores', 'create')): ?>
        <div class="fornecedor-card">
            <div class="icon">
                <i class="fas fa-plus-circle"></i>
            </div>
            <h3>Cadastrar Fornecedor</h3>
            <p>Registre novos fornecedores com todas as informações necessárias para processos licitatórios e gestão de compras.</p>
            <div class="card-buttons">
                <a href="cadastro_fornecedores.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Novo Fornecedor
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Card Consultar Fornecedores -->
        <div class="fornecedor-card">
            <div class="icon">
                <i class="fas fa-search"></i>
            </div>
            <h3>Consultar Fornecedores</h3>
            <p>Visualize, edite e gerencie todos os fornecedores cadastrados. Acesse informações detalhadas, histórico e documentos.</p>
            <div class="card-buttons">
                <a href="consulta_fornecedores.php" class="btn btn-success">
                    <i class="fas fa-search"></i> Ver Fornecedores (<?php echo $totalFornecedores; ?>)
                </a>
            </div>
        </div>

        <!-- Card Fornecedores Ativos -->
        <div class="fornecedor-card">
            <div class="icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>Fornecedores Ativos</h3>
            <p>Acompanhe fornecedores ativos e aptos para participar de processos licitatórios e realizar vendas.</p>
            <div class="card-buttons">
                <a href="fornecedores_ativos.php" class="btn btn-success">
                    <i class="fas fa-check-circle"></i> Ver Ativos (<?php echo $fornecedoresAtivos; ?>)
                </a>
            </div>
        </div>

        <!-- Card Avaliação de Fornecedores -->
        <div class="fornecedor-card">
            <div class="icon">
                <i class="fas fa-star"></i>
            </div>
            <h3>Avaliação de Fornecedores</h3>
            <p>Avalie o desempenho dos fornecedores quanto a qualidade, prazo de entrega e atendimento. Mantenha histórico de avaliações.</p>
            <div class="card-buttons">
                <a href="avaliacao_fornecedores.php" class="btn btn-warning">
                    <i class="fas fa-star"></i> Avaliar Fornecedores
                </a>
            </div>
        </div>

        <!-- Card Documentação -->
        <div class="fornecedor-card">
            <div class="icon">
                <i class="fas fa-file-alt"></i>
            </div>
            <h3>Documentação</h3>
            <p>Gerencie certidões, contratos sociais, procurações e demais documentos necessários para habilitação em licitações.</p>
            <div class="card-buttons">
                <a href="documentos_fornecedores.php" class="btn btn-info">
                    <i class="fas fa-folder-open"></i> Gerenciar Documentos
                </a>
            </div>
        </div>

        <!-- Card Histórico de Compras -->
        <div class="fornecedor-card">
            <div class="icon">
                <i class="fas fa-history"></i>
            </div>
            <h3>Histórico de Compras</h3>
            <p>Visualize o histórico completo de compras realizadas com cada fornecedor, valores, produtos e condições negociadas.</p>
            <div class="card-buttons">
                <a href="historico_compras_fornecedor.php" class="btn btn-purple">
                    <i class="fas fa-history"></i> Ver Histórico
                </a>
            </div>
        </div>

        <!-- Card Relatórios -->
        <div class="fornecedor-card">
            <div class="icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <h3>Relatórios Gerenciais</h3>
            <p>Gere relatórios detalhados de fornecedores por categoria, região, volume de compras e análises de desempenho.</p>
            <div class="card-buttons">
                <a href="relatorio_fornecedores.php" class="btn btn-warning">
                    <i class="fas fa-file-chart-line"></i> Ver Relatórios
                </a>
            </div>
        </div>

        <!-- Card Bloqueados/Suspensos -->
        <div class="fornecedor-card">
            <div class="icon">
                <i class="fas fa-ban"></i>
            </div>
            <h3>Bloqueados/Suspensos</h3>
            <p>Gerencie fornecedores bloqueados ou suspensos temporariamente. Controle penalidades e restrições aplicadas.</p>
            <div class="card-buttons">
                <a href="fornecedores_bloqueados.php" class="btn btn-danger">
                    <i class="fas fa-ban"></i> Ver Bloqueados (<?php echo $fornecedoresBloqueados; ?>)
                </a>
            </div>
        </div>

        <!-- Card Certificações -->
        <div class="fornecedor-card">
            <div class="icon">
                <i class="fas fa-certificate"></i>
            </div>
            <h3>Certificações e Qualificações</h3>
            <p>Controle certificações técnicas, ISO, qualificações específicas e registros profissionais dos fornecedores.</p>
            <div class="card-buttons">
                <a href="certificacoes_fornecedores.php" class="btn btn-info">
                    <i class="fas fa-certificate"></i> Gerenciar Certificações
                </a>
            </div>
        </div>

        <!-- Card Importar/Exportar -->
        <div class="fornecedor-card">
            <div class="icon">
                <i class="fas fa-file-import"></i>
            </div>
            <h3>Importar/Exportar</h3>
            <p>Importe fornecedores em massa via planilha ou exporte dados para análise externa e integração com outros sistemas.</p>
            <div class="card-buttons">
                <a href="importar_fornecedores.php" class="btn btn-purple">
                    <i class="fas fa-file-import"></i> Importar/Exportar
                </a>
            </div>
        </div>

        <!-- Card Comunicação -->
        <div class="fornecedor-card">
            <div class="icon">
                <i class="fas fa-envelope"></i>
            </div>
            <h3>Comunicação</h3>
            <p>Envie comunicados, solicitações de cotação e notificações em massa para grupos de fornecedores selecionados.</p>
            <div class="card-buttons">
                <a href="comunicacao_fornecedores.php" class="btn btn-success">
                    <i class="fas fa-envelope"></i> Comunicar
                </a>
            </div>
        </div>

        <!-- Card Auditoria -->
        <?php if ($permissionManager->hasPagePermission('fornecedores', 'audit')): ?>
        <div class="fornecedor-card">
            <div class="icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h3>Auditoria e Logs</h3>
            <p>Visualize o histórico completo de alterações, logs de sistema e trilha de auditoria para compliance e controle interno.</p>
            <div class="card-buttons">
                <a href="auditoria_fornecedores.php" class="btn btn-warning">
                    <i class="fas fa-history"></i> Ver Auditoria
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <i class="stat-icon fas fa-building"></i>
            <div class="stat-number" id="totalFornecedores"><?php echo $totalFornecedores; ?></div>
            <div class="stat-label">Total de Fornecedores</div>
        </div>
        
        <div class="stat-card stat-active">
            <i class="stat-icon fas fa-check-circle"></i>
            <div class="stat-number" id="fornecedoresAtivos"><?php echo $fornecedoresAtivos; ?></div>
            <div class="stat-label">Ativos</div>
        </div>
        
        <div class="stat-card stat-inactive">
            <i class="stat-icon fas fa-pause-circle"></i>
            <div class="stat-number" id="fornecedoresInativos"><?php echo $fornecedoresInativos; ?></div>
            <div class="stat-label">Inativos</div>
        </div>
        
        <div class="stat-card stat-blocked">
            <i class="stat-icon fas fa-ban"></i>
            <div class="stat-number" id="fornecedoresBloqueados"><?php echo $fornecedoresBloqueados; ?></div>
            <div class="stat-label">Bloqueados</div>
        </div>
        
        <div class="stat-card stat-national">
            <i class="stat-icon fas fa-flag"></i>
            <div class="stat-number" id="fornecedoresNacionais"><?php echo $fornecedoresNacionais; ?></div>
            <div class="stat-label">Nacionais</div>
        </div>
        
        <div class="stat-card stat-international">
            <i class="stat-icon fas fa-globe"></i>
            <div class="stat-number" id="fornecedoresInternacionais"><?php echo $fornecedoresInternacionais; ?></div>
            <div class="stat-label">Internacionais</div>
        </div>
        
        <div class="stat-card stat-purchases">
            <i class="stat-icon fas fa-shopping-cart"></i>
            <div class="stat-number" id="totalCompras"><?php echo $totalCompras; ?></div>
            <div class="stat-label">Com Compras</div>
        </div>
        
        <div class="stat-card stat-new">
            <i class="stat-icon fas fa-sparkles"></i>
            <div class="stat-number" id="fornecedoresNovos"><?php echo $fornecedoresNovos; ?></div>
            <div class="stat-label">Novos (30 dias)</div>
        </div>
    </div>

    <!-- Progress bars para visualização de status -->
    <div class="progress-container">
        <div class="progress-title">Distribuição de Status dos Fornecedores</div>
        
        <?php if ($totalFornecedores > 0): ?>
        <div class="progress-item">
            <span class="progress-label">Ativos</span>
            <span class="progress-value"><?php echo round(($fornecedoresAtivos / $totalFornecedores) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($fornecedoresAtivos / $totalFornecedores) * 100; ?>" style="background: var(--active-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Inativos</span>
            <span class="progress-value"><?php echo round(($fornecedoresInativos / $totalFornecedores) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($fornecedoresInativos / $totalFornecedores) * 100; ?>" style="background: var(--inactive-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Bloqueados</span>
            <span class="progress-value"><?php echo round(($fornecedoresBloqueados / $totalFornecedores) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($fornecedoresBloqueados / $totalFornecedores) * 100; ?>" style="background: var(--blocked-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Fornecedores Nacionais</span>
            <span class="progress-value"><?php echo round(($fornecedoresNacionais / $totalFornecedores) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($fornecedoresNacionais / $totalFornecedores) * 100; ?>" style="background: var(--info-color);"></div>
        </div>
        <?php else: ?>
        <p style="text-align: center; color: var(--medium-gray); font-style: italic;">
            Nenhum fornecedor cadastrado ainda
        </p>
        <?php endif; ?>
    </div>


    <!-- Último fornecedor cadastrado -->
    <?php if ($ultimoFornecedor): ?>
    <div class="last-fornecedor-info">
        <div class="last-fornecedor-card">
            <div class="last-fornecedor-header">
                <i class="fas fa-clock"></i>
                <h4>Último Fornecedor Cadastrado</h4>
            </div>
            <div class="last-fornecedor-content">
                <div class="fornecedor-detail">
                    <strong>Razão Social:</strong>
                    <span class="fornecedor-name"><?php echo htmlspecialchars($ultimoFornecedor['razao_social']); ?></span>
                </div>
                <div class="fornecedor-detail">
                    <strong>Nome Fantasia:</strong>
                    <span><?php echo htmlspecialchars($ultimoFornecedor['nome_fantasia'] ?: 'Não informado'); ?></span>
                </div>
                <div class="fornecedor-detail">
                    <strong>CNPJ:</strong>
                    <span class="fornecedor-cnpj"><?php echo htmlspecialchars($ultimoFornecedor['cnpj']); ?></span>
                </div>
                <div class="fornecedor-detail">
                    <strong>Status:</strong>
                    <span class="status-indicator status-<?php echo strtolower($ultimoFornecedor['status']); ?>">
                        <i class="fas fa-circle"></i>
                        <?php echo htmlspecialchars($ultimoFornecedor['status']); ?>
                    </span>
                </div>
                <div class="fornecedor-detail">
                    <strong>Cadastrado em:</strong>
                    <span><?php echo date('d/m/Y \à\s H:i', strtotime($ultimoFornecedor['created_at'])); ?></span>
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
// JavaScript específico da página de fornecedores
document.addEventListener('DOMContentLoaded', function() {
    console.log('Módulo de Fornecedores iniciado');

    // Anima os números das estatísticas
    function animateNumber(element, finalNumber) {
        if (!element || isNaN(finalNumber)) return;
        
        let currentNumber = 0;
        const increment = Math.max(1, Math.ceil(finalNumber / 30));
        const duration = 1000;
        const stepTime = duration / (finalNumber / increment);
        
        element.textContent = '0';
        
        const timer = setInterval(() => {
            currentNumber += increment;
            if (currentNumber >= finalNumber) {
                currentNumber = finalNumber;
                clearInterval(timer);
            }
            
            element.textContent = currentNumber.toLocaleString('pt-BR');
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
                    const finalNumber = parseInt(numberElement.textContent);
                    
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
    const cards = document.querySelectorAll('.fornecedor-card');
    
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
    console.log('Módulo de Fornecedores carregado com sucesso!');
    console.log('Total de fornecedores:', <?php echo $totalFornecedores; ?>);
    console.log('Fornecedores ativos:', <?php echo $fornecedoresAtivos; ?>);
    console.log('Fornecedores inativos:', <?php echo $fornecedoresInativos; ?>);
    console.log('Fornecedores bloqueados:', <?php echo $fornecedoresBloqueados; ?>);
    console.log('Fornecedores nacionais:', <?php echo $fornecedoresNacionais; ?>);
    console.log('Fornecedores internacionais:', <?php echo $fornecedoresInternacionais; ?>);
    console.log('Fornecedores com compras:', <?php echo $totalCompras; ?>);
    console.log('Fornecedores novos (30 dias):', <?php echo $fornecedoresNovos; ?>);
});
</script>

</body>
</html>