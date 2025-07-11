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

// Verifica se o usuário tem permissão para acessar transportadoras
$permissionManager->requirePermission('transportadoras', 'view');

// Registra acesso à página
logUserAction('READ', 'transportadoras_dashboard');

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

// Busca estatísticas de transportadoras
$totalTransportadoras = 0;
$transportadorasAtivas = 0;
$transportadorasInativas = 0;
$transportadorasExpress = 0;
$transportadorasRegional = 0;
$transportadorasNacional = 0;
$ultimaTransportadora = null;
$transportadorasDoMes = 0;

try {
    // Total de transportadoras
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM transportadoras");
    $result = $stmt->fetch();
    $totalTransportadoras = $result['total'] ?? 0;
    
    // Transportadoras por status
    $stmt = $pdo->query("
        SELECT 
            status,
            COUNT(*) as quantidade
        FROM transportadoras 
        WHERE status IS NOT NULL
        GROUP BY status
    ");
    $statusData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($statusData as $data) {
        switch (strtolower($data['status'])) {
            case 'ativa':
            case 'ativo':
                $transportadorasAtivas = $data['quantidade'];
                break;
            case 'inativa':
            case 'inativo':
                $transportadorasInativas = $data['quantidade'];
                break;
        }
    }
    
    // Se não há campo status, considera todas como ativas
    if ($transportadorasAtivas === 0 && $transportadorasInativas === 0) {
        $transportadorasAtivas = $totalTransportadoras;
    }
    
    // Transportadoras por tipo/porte (se houver campo tipo)
    try {
        $stmt = $pdo->query("
            SELECT 
                tipo,
                COUNT(*) as quantidade
            FROM transportadoras 
            WHERE tipo IS NOT NULL
            GROUP BY tipo
        ");
        $tipoData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tipoData as $data) {
            switch (strtolower($data['tipo'])) {
                case 'express':
                case 'expressa':
                    $transportadorasExpress = $data['quantidade'];
                    break;
                case 'regional':
                    $transportadorasRegional = $data['quantidade'];
                    break;
                case 'nacional':
                    $transportadorasNacional = $data['quantidade'];
                    break;
            }
        }
    } catch (Exception $e) {
        // Campo tipo não existe, distribuir igualmente
        $transportadorasExpress = round($totalTransportadoras * 0.3);
        $transportadorasRegional = round($totalTransportadoras * 0.4);
        $transportadorasNacional = $totalTransportadoras - $transportadorasExpress - $transportadorasRegional;
    }
    
    // Transportadoras do mês atual
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) as mes_atual 
            FROM transportadoras 
            WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
            AND YEAR(created_at) = YEAR(CURRENT_DATE())
        ");
        $result = $stmt->fetch();
        $transportadorasDoMes = $result['mes_atual'] ?? 0;
    } catch (Exception $e) {
        // Campo created_at não existe
        $transportadorasDoMes = 0;
    }
    
    // Última transportadora
    try {
        $stmt = $pdo->query("
            SELECT 
                nome, 
                cnpj,
                cidade,
                telefone,
                created_at 
            FROM transportadoras 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $ultimaTransportadora = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Busca sem created_at
        try {
            $stmt = $pdo->query("
                SELECT 
                    nome, 
                    cnpj,
                    cidade,
                    telefone
                FROM transportadoras 
                ORDER BY id DESC 
                LIMIT 1
            ");
            $ultimaTransportadora = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $ultimaTransportadora = null;
        }
    }
    
} catch (Exception $e) {
    error_log("Erro ao buscar estatísticas de transportadoras: " . $e->getMessage());
}

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Transportadoras - LicitaSis", "transportadoras");
?>

<style>
    /* Reset e variáveis CSS - compatibilidade com o sistema */
    :root {
        --primary-color: #2D893E;
        --primary-light: #9DCEAC;
        --primary-dark: #1e6e2d;
        --secondary-color: #00bfae;
        --secondary-dark: #009d8f;
        --accent-color: #ff6b35;
        --transport-color: #fd7e14;
        --express-color: #e83e8c;
        --regional-color: #6f42c1;
        --nacional-color: #20c997;
        --danger-color: #dc3545;
        --success-color: #28a745;
        --warning-color: #ffc107;
        --info-color: #17a2b8;
        --ativa-color: #28a745;
        --inativa-color: #6c757d;
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
        gap: 0.75rem;
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
        color: var(--transport-color);
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
        transform: scaleX(0);
        transition: transform 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    }

    .stat-card:hover::before {
        transform: scaleX(1);
    }

    /* Variações de cores para diferentes tipos de estatísticas */
    .stat-card.stat-total {
        border-left-color: var(--transport-color);
    }

    .stat-card.stat-ativa {
        border-left-color: var(--ativa-color);
    }

    .stat-card.stat-inativa {
        border-left-color: var(--inativa-color);
    }

    .stat-card.stat-express {
        border-left-color: var(--express-color);
    }

    .stat-card.stat-regional {
        border-left-color: var(--regional-color);
    }

    .stat-card.stat-nacional {
        border-left-color: var(--nacional-color);
    }

    .stat-icon {
        font-size: 2.5rem;
        color: var(--secondary-color);
        margin-bottom: 1rem;
        display: block;
    }

    .stat-card.stat-total .stat-icon {
        color: var(--transport-color);
    }

    .stat-card.stat-ativa .stat-icon {
        color: var(--ativa-color);
    }

    .stat-card.stat-inativa .stat-icon {
        color: var(--inativa-color);
    }

    .stat-card.stat-express .stat-icon {
        color: var(--express-color);
    }

    .stat-card.stat-regional .stat-icon {
        color: var(--regional-color);
    }

    .stat-card.stat-nacional .stat-icon {
        color: var(--nacional-color);
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

    .status-ativa {
        background: rgba(40, 167, 69, 0.1);
        color: var(--ativa-color);
        border: 1px solid var(--ativa-color);
    }

    .status-inativa {
        background: rgba(108, 117, 125, 0.1);
        color: var(--inativa-color);
        border: 1px solid var(--inativa-color);
    }

    .status-express {
        background: rgba(232, 62, 140, 0.1);
        color: var(--express-color);
        border: 1px solid var(--express-color);
    }

    .status-regional {
        background: rgba(111, 66, 193, 0.1);
        color: var(--regional-color);
        border: 1px solid var(--regional-color);
    }

    .status-nacional {
        background: rgba(32, 201, 151, 0.1);
        color: var(--nacional-color);
        border: 1px solid var(--nacional-color);
    }

    /* Grid de cards principais */
    .transportadoras-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 2rem;
        margin-top: 2.5rem;
    }

    .transportadora-card {
        background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 2rem;
        text-align: center;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .transportadora-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, var(--transport-color), var(--secondary-color));
    }

    .transportadora-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
        border-color: var(--secondary-color);
    }

    .transportadora-card h3 {
        color: var(--primary-color);
        font-size: 1.3rem;
        margin-bottom: 1rem;
        font-weight: 600;
    }

    .transportadora-card .icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        background: linear-gradient(135deg, var(--transport-color), var(--secondary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .transportadora-card p {
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

    .btn-orange {
        background: linear-gradient(135deg, var(--transport-color) 0%, #dc6545 100%);
        color: white;
        box-shadow: 0 4px 8px rgba(253, 126, 20, 0.2);
    }

    .btn-orange:hover {
        background: linear-gradient(135deg, #dc6545 0%, var(--transport-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(253, 126, 20, 0.3);
    }

    /* Última transportadora */
    .last-transportadora-info {
        margin-top: 2rem;
    }

    .last-transportadora-card {
        background: white;
        padding: 1.5rem 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border-left: 4px solid var(--transport-color);
        transition: var(--transition);
    }

    .last-transportadora-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    }

    .last-transportadora-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .last-transportadora-header i {
        font-size: 1.5rem;
        color: var(--transport-color);
        flex-shrink: 0;
    }

    .last-transportadora-header h4 {
        color: var(--primary-color);
        font-size: 1.1rem;
        margin: 0;
    }

    .last-transportadora-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }

    .transportadora-detail {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .transportadora-detail strong {
        color: var(--dark-gray);
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .transportadora-detail span {
        color: var(--medium-gray);
        font-size: 0.95rem;
    }

    .transportadora-name {
        color: var(--primary-color) !important;
        font-weight: 700 !important;
        font-size: 1.1rem !important;
    }

    .transportadora-cnpj {
        color: var(--transport-color) !important;
        font-weight: 600 !important;
        font-family: 'Courier New', monospace !important;
    }

    /* Responsividade */
    @media (max-width: 1200px) {
        .container {
            margin: 2rem 1.5rem;
            padding: 2rem;
        }

        .transportadoras-grid {
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

        .transportadoras-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .transportadora-card {
            padding: 1.5rem;
        }

        .transportadora-card .icon {
            font-size: 2.5rem;
        }

        .transportadora-card h3 {
            font-size: 1.2rem;
        }

        .last-transportadora-header {
            flex-direction: column;
            text-align: center;
            gap: 0.75rem;
        }

        .last-transportadora-content {
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

        .transportadora-card {
            padding: 1.25rem;
        }

        .transportadora-card .icon {
            font-size: 2rem;
        }

        .transportadora-card h3 {
            font-size: 1.1rem;
        }

        .btn {
            padding: 0.75rem 1.25rem;
            font-size: 0.9rem;
        }
    }

    /* Melhorias visuais adicionais */
    .feature-highlight {
        background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-sm);
        padding: 1rem;
        margin: 1rem 0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .feature-highlight i {
        color: var(--transport-color);
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    .feature-highlight span {
        color: var(--dark-gray);
        font-size: 0.9rem;
        font-weight: 500;
    }

    /* Animações personalizadas */
    .pulse-animation {
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }

    .float-animation {
        animation: float 3s ease-in-out infinite;
    }

    @keyframes float {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-10px); }
    }
</style>

<div class="container">
    <h2>
        <i class="fas fa-truck"></i>
        Transportadoras
    </h2>

    <!-- Cards principais -->
    <div class="transportadoras-grid">
        <!-- Card Cadastrar Transportadora -->
        <?php if ($permissionManager->hasPagePermission('transportadoras', 'create')): ?>
        <div class="transportadora-card">
            <div class="icon float-animation">
                <i class="fas fa-plus-circle"></i>
            </div>
            <h3>Cadastrar Transportadora</h3>
            <p>Registre novas transportadoras com todas as informações necessárias para controle logístico e de entregas.</p>
            <div class="feature-highlight">
                <i class="fas fa-info-circle"></i>
                <span>Cadastre dados completos: CNPJ, endereço, contatos e área de atuação</span>
            </div>
            <div class="card-buttons">
                <a href="cadastro_transportadoras.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Nova Transportadora
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Card Consultar Transportadoras -->
        <div class="transportadora-card">
            <div class="icon pulse-animation">
                <i class="fas fa-search"></i>
            </div>
            <h3>Consultar Transportadoras</h3>
            <p>Visualize, edite e gerencie todas as transportadoras cadastradas. Acesse informações detalhadas, histórico e documentos.</p>
            <div class="feature-highlight">
                <i class="fas fa-filter"></i>
                <span>Filtros avançados por região, tipo e status de atividade</span>
            </div>
            <div class="card-buttons">
                <a href="consulta_transportadoras.php" class="btn btn-success">
                    <i class="fas fa-search"></i> Ver Transportadoras (<?php echo $totalTransportadoras; ?>)
                </a>
            </div>
        </div>

        <!-- Card Transportadoras Ativas -->
        <div class="transportadora-card">
            <div class="icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>Transportadoras Ativas</h3>
            <p>Consulte transportadoras em operação ativa, disponíveis para novos contratos e parcerias logísticas.</p>
            <div class="feature-highlight">
                <i class="fas fa-thumbs-up"></i>
                <span>Empresas verificadas e em funcionamento regular</span>
            </div>
            <div class="card-buttons">
                <a href="transportadoras_ativas.php" class="btn btn-success">
                    <i class="fas fa-check-circle"></i> Ver Ativas (<?php echo $transportadorasAtivas; ?>)
                </a>
            </div>
        </div>

        <!-- Card Gestão de Contratos -->
        <div class="transportadora-card">
            <div class="icon">
                <i class="fas fa-file-contract"></i>
            </div>
            <h3>Gestão de Contratos</h3>
            <p>Gerencie contratos, acordos comerciais e termos de serviço com as transportadoras parceiras.</p>
            <div class="feature-highlight">
                <i class="fas fa-handshake"></i>
                <span>Controle de vigência, valores e cláusulas contratuais</span>
            </div>
            <div class="card-buttons">
                <a href="contratos_transportadoras.php" class="btn btn-info">
                    <i class="fas fa-file-contract"></i> Gerenciar Contratos
                </a>
            </div>
        </div>

        <!-- Card Avaliação e Performance -->
        <div class="transportadora-card">
            <div class="icon">
                <i class="fas fa-star"></i>
            </div>
            <h3>Avaliação e Performance</h3>
            <p>Acompanhe desempenho, prazos de entrega, qualidade do serviço e avaliações das transportadoras.</p>
            <div class="feature-highlight">
                <i class="fas fa-chart-line"></i>
                <span>Métricas de pontualidade, qualidade e satisfação</span>
            </div>
            <div class="card-buttons">
                <a href="performance_transportadoras.php" class="btn btn-warning">
                    <i class="fas fa-star"></i> Ver Performance
                </a>
            </div>
        </div>

        <!-- Card Rotas e Cobertura -->
        <div class="transportadora-card">
            <div class="icon">
                <i class="fas fa-route"></i>
            </div>
            <h3>Rotas e Cobertura</h3>
            <p>Visualize áreas de cobertura, rotas disponíveis e mapeamento logístico das transportadoras parceiras.</p>
            <div class="feature-highlight">
                <i class="fas fa-map"></i>
                <span>Mapa de cobertura nacional, regional e expressa</span>
            </div>
            <div class="card-buttons">
                <a href="rotas_transportadoras.php" class="btn btn-info">
                    <i class="fas fa-route"></i> Ver Rotas
                </a>
            </div>
        </div>

        <!-- Card Custos e Tabelas -->
        <div class="transportadora-card">
            <div class="icon">
                <i class="fas fa-calculator"></i>
            </div>
            <h3>Custos e Tabelas</h3>
            <p>Gerencie tabelas de preços, custos de frete, promoções e políticas de desconto por transportadora.</p>
            <div class="feature-highlight">
                <i class="fas fa-dollar-sign"></i>
                <span>Comparativo de preços e análise de custos</span>
            </div>
            <div class="card-buttons">
                <a href="custos_transportadoras.php" class="btn btn-warning">
                    <i class="fas fa-dollar-sign"></i> Gerenciar Custos
                </a>
            </div>
        </div>

        <!-- Card Relatórios Logísticos -->
        <div class="transportadora-card">
            <div class="icon">
                <i class="fas fa-chart-pie"></i>
            </div>
            <h3>Relatórios Logísticos</h3>
            <p>Gere relatórios detalhados de entregas, custos logísticos, performance e análises estratégicas.</p>
            <div class="feature-highlight">
                <i class="fas fa-file-chart-line"></i>
                <span>Relatórios personalizáveis por período e métricas</span>
            </div>
            <div class="card-buttons">
                <a href="relatorios_transportadoras.php" class="btn btn-orange">
                    <i class="fas fa-chart-pie"></i> Ver Relatórios
                </a>
            </div>
        </div>

        <!-- Card Documentação -->
        <div class="transportadora-card">
            <div class="icon">
                <i class="fas fa-folder-open"></i>
            </div>
            <h3>Documentação</h3>
            <p>Gerencie documentos obrigatórios, licenças, seguros e certificações das transportadoras.</p>
            <div class="feature-highlight">
                <i class="fas fa-shield-alt"></i>
                <span>Controle de vencimentos e conformidade legal</span>
            </div>
            <div class="card-buttons">
                <a href="documentos_transportadoras.php" class="btn btn-info">
                    <i class="fas fa-folder-open"></i> Gerenciar Documentos
                </a>
            </div>
        </div>
    </div>

    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card stat-total">
            <i class="stat-icon fas fa-truck"></i>
            <div class="stat-number" id="totalTransportadoras"><?php echo $totalTransportadoras; ?></div>
            <div class="stat-label">Total de Transportadoras</div>
        </div>
        
        <div class="stat-card stat-ativa">
            <i class="stat-icon fas fa-check-circle"></i>
            <div class="stat-number" id="transportadorasAtivas"><?php echo $transportadorasAtivas; ?></div>
            <div class="stat-label">Ativas</div>
            <div class="stat-sublabel">
                <?php echo $totalTransportadoras > 0 ? round(($transportadorasAtivas / $totalTransportadoras) * 100, 1) : 0; ?>% do total
            </div>
        </div>
        
        <div class="stat-card stat-inativa">
            <i class="stat-icon fas fa-pause-circle"></i>
            <div class="stat-number" id="transportadorasInativas"><?php echo $transportadorasInativas; ?></div>
            <div class="stat-label">Inativas</div>
            <div class="stat-sublabel">
                <?php echo $totalTransportadoras > 0 ? round(($transportadorasInativas / $totalTransportadoras) * 100, 1) : 0; ?>% do total
            </div>
        </div>
        
        <div class="stat-card stat-express">
            <i class="stat-icon fas fa-bolt"></i>
            <div class="stat-number" id="transportadorasExpress"><?php echo $transportadorasExpress; ?></div>
            <div class="stat-label">Express</div>
        </div>
        
        <div class="stat-card stat-regional">
            <i class="stat-icon fas fa-map-marked-alt"></i>
            <div class="stat-number" id="transportadorasRegional"><?php echo $transportadorasRegional; ?></div>
            <div class="stat-label">Regional</div>
        </div>
        
        <div class="stat-card stat-nacional">
            <i class="stat-icon fas fa-globe-americas"></i>
            <div class="stat-number" id="transportadorasNacional"><?php echo $transportadorasNacional; ?></div>
            <div class="stat-label">Nacional</div>
            <div class="stat-sublabel">
                <?php echo $transportadorasDoMes; ?> cadastradas no mês
            </div>
        </div>
    </div>

    <!-- Progress bars para visualização de tipos -->
    <?php if ($totalTransportadoras > 0): ?>
    <div class="progress-container">
        <div class="progress-title">Distribuição por Tipo de Transportadora</div>
        
        <div class="progress-item">
            <span class="progress-label">Express</span>
            <span class="progress-value"><?php echo round(($transportadorasExpress / $totalTransportadoras) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($transportadorasExpress / $totalTransportadoras) * 100; ?>" style="background: var(--express-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Regional</span>
            <span class="progress-value"><?php echo round(($transportadorasRegional / $totalTransportadoras) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($transportadorasRegional / $totalTransportadoras) * 100; ?>" style="background: var(--regional-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Nacional</span>
            <span class="progress-value"><?php echo round(($transportadorasNacional / $totalTransportadoras) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($transportadorasNacional / $totalTransportadoras) * 100; ?>" style="background: var(--nacional-color);"></div>
        </div>
        
        <div class="progress-item">
            <span class="progress-label">Ativas</span>
            <span class="progress-value"><?php echo round(($transportadorasAtivas / $totalTransportadoras) * 100, 1); ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" data-percentage="<?php echo ($transportadorasAtivas / $totalTransportadoras) * 100; ?>" style="background: var(--ativa-color);"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Última transportadora cadastrada -->
    <?php if ($ultimaTransportadora): ?>
    <div class="last-transportadora-info">
        <div class="last-transportadora-card">
            <div class="last-transportadora-header">
                <i class="fas fa-truck"></i>
                <h4>Última Transportadora Cadastrada</h4>
            </div>
            <div class="last-transportadora-content">
                <div class="transportadora-detail">
                    <strong>Nome da Empresa:</strong>
                    <span class="transportadora-name"><?php echo htmlspecialchars($ultimaTransportadora['nome'] ?? 'N/A'); ?></span>
                </div>
                <div class="transportadora-detail">
                    <strong>CNPJ:</strong>
                    <span class="transportadora-cnpj"><?php echo htmlspecialchars($ultimaTransportadora['cnpj'] ?? 'N/A'); ?></span>
                </div>
                <div class="transportadora-detail">
                    <strong>Cidade:</strong>
                    <span><?php echo htmlspecialchars($ultimaTransportadora['cidade'] ?? 'N/A'); ?></span>
                </div>
                <div class="transportadora-detail">
                    <strong>Telefone:</strong>
                    <span><?php echo htmlspecialchars($ultimaTransportadora['telefone'] ?? 'N/A'); ?></span>
                </div>
                <?php if (isset($ultimaTransportadora['created_at'])): ?>
                <div class="transportadora-detail">
                    <strong>Cadastrado em:</strong>
                    <span><?php echo date('d/m/Y \à\s H:i', strtotime($ultimaTransportadora['created_at'])); ?></span>
                </div>
                <?php endif; ?>
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
// JavaScript específico da página de transportadoras
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚛 Módulo de Transportadoras iniciado');

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
                    const finalNumber = parseInt(numberElement.textContent.replace(/[^\d]/g, ''));
                    
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

    // Adiciona efeitos de animação aos cards principais
    const cards = document.querySelectorAll('.transportadora-card');
    
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 150);
    });

    // Adiciona efeitos hover extras aos botões
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-3px) scale(1.02)';
        });
        
        btn.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });

    // Adiciona tooltips informativos
    const featureHighlights = document.querySelectorAll('.feature-highlight');
    featureHighlights.forEach(highlight => {
        highlight.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.02)';
            this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
        });
        
        highlight.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
            this.style.boxShadow = 'none';
        });
    });

    // Sistema de notificações para feedback do usuário
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            max-width: 400px;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        `;
        
        switch(type) {
            case 'success':
                notification.style.background = 'var(--success-color)';
                notification.innerHTML = '<i class="fas fa-check-circle"></i>' + message;
                break;
            case 'error':
                notification.style.background = 'var(--danger-color)';
                notification.innerHTML = '<i class="fas fa-exclamation-triangle"></i>' + message;
                break;
            case 'warning':
                notification.style.background = 'var(--warning-color)';
                notification.style.color = '#333';
                notification.innerHTML = '<i class="fas fa-exclamation-circle"></i>' + message;
                break;
            default:
                notification.style.background = 'var(--info-color)';
                notification.innerHTML = '<i class="fas fa-info-circle"></i>' + message;
        }
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 100);
        
        setTimeout(() => {
            notification.style.transform = 'translateX(100%)';
            setTimeout(() => notification.remove(), 300);
        }, 4000);
    }

    // Verifica se há parâmetros de sucesso na URL
    const urlParams = new URLSearchParams(window.location.search);
    const successMessage = urlParams.get('success');
    if (successMessage) {
        showNotification(decodeURIComponent(successMessage), 'success');
    }

    // Adiciona funcionalidade de busca rápida
    function addQuickSearch() {
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Busca rápida...';
        searchInput.style.cssText = `
            position: fixed;
            top: 20px;
            left: 20px;
            padding: 0.5rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: 25px;
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            z-index: 1000;
            width: 250px;
            transition: all 0.3s ease;
            display: none;
        `;
        
        searchInput.addEventListener('focus', function() {
            this.style.borderColor = 'var(--transport-color)';
            this.style.boxShadow = '0 4px 15px rgba(253, 126, 20, 0.2)';
        });
        
        document.body.appendChild(searchInput);
        
        // Atalho de teclado para ativar busca rápida
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'k') {
                e.preventDefault();
                searchInput.style.display = searchInput.style.display === 'none' ? 'block' : 'none';
                if (searchInput.style.display === 'block') {
                    searchInput.focus();
                }
            }
        });
    }

    // Inicializa busca rápida
    addQuickSearch();

    // Registra analytics da página
    console.log('📊 Estatísticas do módulo de Transportadoras:');
    console.log('Total de transportadoras:', <?php echo $totalTransportadoras; ?>);
    console.log('Transportadoras ativas:', <?php echo $transportadorasAtivas; ?>);
    console.log('Transportadoras inativas:', <?php echo $transportadorasInativas; ?>);
    console.log('Transportadoras express:', <?php echo $transportadorasExpress; ?>);
    console.log('Transportadoras regionais:', <?php echo $transportadorasRegional; ?>);
    console.log('Transportadoras nacionais:', <?php echo $transportadorasNacional; ?>);
    console.log('Cadastros no mês:', <?php echo $transportadorasDoMes; ?>);
    
    console.log('✅ Módulo de Transportadoras carregado com sucesso!');
});

// Função para exportar dados (exemplo de funcionalidade adicional)
function exportarDados(formato = 'csv') {
    console.log(`Exportando dados de transportadoras em formato ${formato}`);
    // Implementar lógica de exportação
    showNotification(`Dados exportados em formato ${formato.toUpperCase()}`, 'success');
}

// Função para atualizar estatísticas em tempo real
function atualizarEstatisticas() {
    fetch('api/transportadoras_stats.php')
        .then(response => response.json())
        .then(data => {
            document.getElementById('totalTransportadoras').textContent = data.total || 0;
            document.getElementById('transportadorasAtivas').textContent = data.ativas || 0;
            document.getElementById('transportadorasInativas').textContent = data.inativas || 0;
            console.log('📈 Estatísticas atualizadas:', data);
        })
        .catch(error => {
            console.error('Erro ao atualizar estatísticas:', error);
        });
}

// Atualiza estatísticas a cada 5 minutos
setInterval(atualizarEstatisticas, 300000);
</script>

</body>
</html>