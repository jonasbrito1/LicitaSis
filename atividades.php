<?php
session_start();

// Inclui o sistema de permissões
include('db.php');
include('permissions.php');

// Inicializa o gerenciador de permissões
$permissionManager = initPermissions($pdo);

// Verifica se é administrador
if (!$permissionManager->isAdmin()) {
    $_SESSION['error'] = 'Acesso negado. Esta funcionalidade é exclusiva para administradores.';
    header("Location: index.php");
    exit();
}

// Inclui o template de header
include('includes/header_template.php');

// Processa requisições AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        error_log("Ação recebida: " . $_POST['action']); // Debug
        
        switch ($_POST['action']) {
            case 'add_activity':
                // Verifica se a coluna priority existe antes de usá-la
                $stmt = $pdo->prepare("SHOW COLUMNS FROM activities LIKE 'priority'");
                $stmt->execute();
                $hasPriorityColumn = $stmt->rowCount() > 0;
                
                if ($hasPriorityColumn) {
                    $stmt = $pdo->prepare("
                        INSERT INTO activities (page_name, activity_description, priority, created_by, created_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $_POST['page_name'],
                        $_POST['description'],
                        $_POST['priority'] ?? 'media',
                        $_SESSION['user']['id']
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO activities (page_name, activity_description, created_by, created_at) 
                        VALUES (?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $_POST['page_name'],
                        $_POST['description'],
                        $_SESSION['user']['id']
                    ]);
                }
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                break;
                
            case 'toggle_activity':
                $isCompleted = $_POST['completed'] == 'true' ? 1 : 0;
                
                if ($isCompleted) {
                    // Marca como concluída e define data de conclusão
                    $stmt = $pdo->prepare("
                        UPDATE activities 
                        SET is_completed = 1, completed_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([$_POST['id']]);
                } else {
                    // Marca como não concluída e remove data de conclusão
                    $stmt = $pdo->prepare("
                        UPDATE activities 
                        SET is_completed = 0, completed_at = NULL 
                        WHERE id = ?
                    ");
                    $stmt->execute([$_POST['id']]);
                }
                
                echo json_encode(['success' => true]);
                break;
                
            case 'delete_activity':
                $stmt = $pdo->prepare("DELETE FROM activities WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                echo json_encode(['success' => true]);
                break;

            case 'update_priority':
                // Verifica se a coluna priority existe antes de usá-la
                $stmt = $pdo->prepare("SHOW COLUMNS FROM activities LIKE 'priority'");
                $stmt->execute();
                $hasPriorityColumn = $stmt->rowCount() > 0;
                
                if ($hasPriorityColumn) {
                    $stmt = $pdo->prepare("UPDATE activities SET priority = ? WHERE id = ?");
                    $stmt->execute([$_POST['priority'], $_POST['id']]);
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Coluna de prioridade não existe']);
                }
                break;

            case 'migrate_old_activities':
                // Migração de atividades antigas - marca completed_at para atividades concluídas sem data
                $stmt = $pdo->prepare("
                    UPDATE activities 
                    SET completed_at = created_at 
                    WHERE is_completed = 1 AND completed_at IS NULL
                ");
                $affected = $stmt->execute();
                echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
                break;
        }
    } catch (Exception $e) {
        error_log("Erro na ação AJAX: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Processa requisição para atividades de hoje
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_today_activities') {
    header('Content-Type: application/json');
    
    try {
        // Verifica se coluna priority existe
        $stmt = $pdo->prepare("SHOW COLUMNS FROM activities LIKE 'priority'");
        $stmt->execute();
        $hasPriorityColumn = $stmt->rowCount() > 0;
        
        $selectFields = "a.id, a.page_name, a.activity_description, a.is_completed, a.created_by, a.created_at, a.completed_at";
        if ($hasPriorityColumn) {
            $selectFields .= ", a.priority";
        }
        
        $stmt = $pdo->prepare("
            SELECT $selectFields, u.name as creator_name 
            FROM activities a 
            LEFT JOIN users u ON a.created_by = u.id 
            WHERE a.is_completed = 1 
            AND (
                (a.completed_at IS NOT NULL AND DATE(a.completed_at) = CURDATE()) OR
                (a.completed_at IS NULL AND DATE(a.created_at) = CURDATE())
            )
            ORDER BY COALESCE(a.completed_at, a.created_at) DESC
        ");
        $stmt->execute();
        $todayActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Adiciona priority padrão se a coluna não existir
        if (!$hasPriorityColumn) {
            foreach ($todayActivities as &$activity) {
                $activity['priority'] = 'media';
            }
        }
        
        echo json_encode(['success' => true, 'activities' => $todayActivities]);
    } catch (Exception $e) {
        error_log("Erro ao buscar atividades de hoje: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Processa requisição para histórico de atividades
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json');
    
    try {
        // Verifica se a coluna priority existe
        $stmt = $pdo->prepare("SHOW COLUMNS FROM activities LIKE 'priority'");
        $stmt->execute();
        $hasPriorityColumn = $stmt->rowCount() > 0;
        
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        // BUSCA TODAS AS ATIVIDADES CONCLUÍDAS (incluindo antigas sem completed_at)
        $whereClause = "WHERE a.is_completed = 1";
        $params = [];
        
        // Filtro por módulo
        if (!empty($_GET['module']) && $_GET['module'] !== 'all') {
            $whereClause .= " AND a.page_name = ?";
            $params[] = $_GET['module'];
        }
        
        // Filtro por prioridade (apenas se a coluna existir)
        if ($hasPriorityColumn && !empty($_GET['priority']) && $_GET['priority'] !== 'all') {
            $whereClause .= " AND a.priority = ?";
            $params[] = $_GET['priority'];
        }
        
        // Filtro por período (baseado em completed_at OU created_at para atividades antigas)
        if (!empty($_GET['period'])) {
            switch ($_GET['period']) {
                case 'week':
                    $whereClause .= " AND (
                        (a.completed_at IS NOT NULL AND a.completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) OR
                        (a.completed_at IS NULL AND a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))
                    )";
                    break;
                case 'month':
                    $whereClause .= " AND (
                        (a.completed_at IS NOT NULL AND a.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) OR
                        (a.completed_at IS NULL AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))
                    )";
                    break;
                case 'quarter':
                    $whereClause .= " AND (
                        (a.completed_at IS NOT NULL AND a.completed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) OR
                        (a.completed_at IS NULL AND a.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY))
                    )";
                    break;
            }
        }
        
        // Seleciona campos baseado na existência da coluna priority
        $selectFields = "a.id, a.page_name, a.activity_description, a.is_completed, a.created_by, a.created_at, a.completed_at";
        if ($hasPriorityColumn) {
            $selectFields .= ", a.priority";
        }
        
        // Busca atividades (ordena por completed_at se existir, senão por created_at)
        $stmt = $pdo->prepare("
            SELECT $selectFields, u.name as creator_name 
            FROM activities a 
            LEFT JOIN users u ON a.created_by = u.id 
            $whereClause
            ORDER BY COALESCE(a.completed_at, a.created_at) DESC 
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Adiciona priority padrão se a coluna não existir
        if (!$hasPriorityColumn) {
            foreach ($activities as &$activity) {
                $activity['priority'] = 'media';
            }
        }
        
        // Conta total de registros
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM activities a 
            $whereClause
        ");
        $countStmt->execute($params);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        error_log("Histórico: Encontrados $total registros, página $page"); // Debug
        
        echo json_encode([
            'success' => true, 
            'activities' => $activities,
            'total' => $total,
            'page' => $page,
            'hasMore' => ($offset + $limit) < $total,
            'hasPriorityColumn' => $hasPriorityColumn
        ]);
    } catch (Exception $e) {
        error_log("Erro no histórico de atividades: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Inicia a página
startPage("Gestão de Atividades - LicitaSis", "atividades");

// Cria/atualiza a tabela e migra dados antigos se necessário
try {
    // Primeiro, cria a tabela básica se não existir
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            page_name VARCHAR(100) NOT NULL,
            activity_description TEXT NOT NULL,
            is_completed BOOLEAN DEFAULT FALSE,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            INDEX idx_page_name (page_name),
            INDEX idx_completed (is_completed)
        )
    ");
    
    // Verifica se a coluna priority existe, se não, adiciona
    $stmt = $pdo->prepare("SHOW COLUMNS FROM activities LIKE 'priority'");
    $stmt->execute();
    $hasPriorityColumn = $stmt->rowCount() > 0;
    
    if (!$hasPriorityColumn) {
        try {
            $pdo->exec("ALTER TABLE activities ADD COLUMN priority ENUM('baixa', 'media', 'alta') DEFAULT 'media' AFTER activity_description");
            $pdo->exec("ALTER TABLE activities ADD INDEX idx_priority (priority)");
            $hasPriorityColumn = true;
            error_log("Coluna priority adicionada com sucesso");
        } catch (Exception $e) {
            error_log("Erro ao adicionar coluna priority: " . $e->getMessage());
            $hasPriorityColumn = false;
        }
    }
    
    // Migração de dados antigos: atualiza completed_at para atividades concluídas sem data
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM activities 
        WHERE is_completed = 1 AND completed_at IS NULL
    ");
    $stmt->execute();
    $oldActivities = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($oldActivities > 0) {
        error_log("Encontradas $oldActivities atividades antigas para migrar");
        // Atualiza atividades antigas concluídas sem data de conclusão
        $stmt = $pdo->prepare("
            UPDATE activities 
            SET completed_at = created_at 
            WHERE is_completed = 1 AND completed_at IS NULL
        ");
        $stmt->execute();
        error_log("Migração de atividades antigas concluída: " . $stmt->rowCount() . " registros atualizados");
    }
    
} catch (Exception $e) {
    error_log("Erro ao criar/atualizar tabela activities: " . $e->getMessage());
    $hasPriorityColumn = false;
}

// Obtém filtros atuais
$currentPriorityFilter = $_GET['priority_filter'] ?? 'all';
$currentModuleFilter = $_GET['module_filter'] ?? 'all';

// Lista de páginas do sistema
$systemPages = [
    'clientes' => 'Cadastro de Clientes',
    'produtos' => 'Cadastro de Produtos', 
    'empenhos' => 'Gestão de Empenhos',
    'fornecedores' => 'Cadastro de Fornecedores',
    'compras' => 'Gestão de Compras',
    'vendas' => 'Gestão de Vendas',
    'financeiro' => 'Módulo Financeiro',
    'transportadoras' => 'Cadastro de Transportadoras',
    'usuarios' => 'Gestão de Usuários',
    'funcionarios' => 'Gestão de Funcionários',
    'pregoes' => 'Gestão de Pregões',
    'investidores' => 'Gestão de Investidores',
    'relatorios' => 'Sistema de Relatórios',
    'dashboard' => 'Dashboard Principal',
    'sistema' => 'Configurações do Sistema'
];

// Define cores e ícones para prioridades
$priorityConfig = [
    'alta' => ['color' => '#dc3545', 'bg' => '#f8d7da', 'icon' => 'fas fa-exclamation-triangle', 'label' => 'Alta'],
    'media' => ['color' => '#fd7e14', 'bg' => '#fdecd8', 'icon' => 'fas fa-minus-circle', 'label' => 'Média'],
    'baixa' => ['color' => '#28a745', 'bg' => '#d4edda', 'icon' => 'fas fa-check-circle', 'label' => 'Baixa']
];
?>

<style>
    .main-content {
        padding: 2rem 1rem;
        min-height: calc(100vh - 140px);
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    }

    .container {
        max-width: 1400px;
        margin: 0 auto;
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        overflow: hidden;
    }

    .page-header {
        background: linear-gradient(135deg, var(--primary-color) 0%, #1e6b2e 100%);
        color: white;
        padding: 2rem;
        text-align: center;
    }

    .page-header h1 {
        font-size: 2.5rem;
        font-weight: 700;
        margin: 0 0 0.5rem 0;
        text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        color: white;
    }

    .page-header p {
        font-size: 1.2rem;
        opacity: 0.9;
        margin: 0;
    }

    .filters-section {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        padding: 1.5rem 2rem;
        border-bottom: 2px solid #e9ecef;
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        align-items: center;
        justify-content: space-between;
    }

    .filters-group {
        display: flex;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .filter-item {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .filter-item label {
        font-size: 0.8rem;
        font-weight: 600;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .filter-select {
        padding: 0.5rem 1rem;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        background: white;
        min-width: 120px;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }

    .filter-select:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.1);
    }

    .history-btn {
        background: linear-gradient(135deg, #1410e6ff, #0f33ffff);
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .history-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(18, 255, 101, 0.3);
    }

    .activities-container {
        padding: 2rem;
    }

    .page-section {
        background: #f8f9fa;
        border-radius: 12px;
        margin-bottom: 2rem;
        overflow: hidden;
        border: 1px solid #e9ecef;
        transition: all 0.3s ease;
    }

    .page-section:hover {
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }

    .page-section-header {
        background: linear-gradient(135deg, #ffffff 0%, #f1f3f4 100%);
        padding: 1.5rem 2rem;
        border-bottom: 2px solid var(--primary-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .page-title {
        font-size: 1.4rem;
        font-weight: 600;
        color: var(--primary-color);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .page-title .page-icon {
        background: var(--primary-color);
        color: white;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        font-weight: 600;
    }

    .page-actions {
        display: flex;
        gap: 0.75rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .btn {
        padding: 0.6rem 1.2rem;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.3s ease;
        font-size: 0.9rem;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color), #1e6b2e);
        color: white;
    }

    .btn-secondary {
        background: linear-gradient(135deg, #6c757d, #5a6268);
        color: white;
    }

    .btn-outline {
        background: transparent;
        border: 2px solid var(--primary-color);
        color: var(--primary-color);
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .btn-outline:hover {
        background: var(--primary-color);
        color: white;
    }

    .page-content {
        padding: 2rem;
    }

    .activity-form {
        background: white;
        padding: 1.5rem;
        border-radius: 8px;
        margin-bottom: 2rem;
        border: 2px dashed #e9ecef;
        transition: all 0.3s ease;
    }

    .activity-form:hover {
        border-color: var(--primary-color);
        background: #f8f9fa;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 1rem;
        align-items: end;
    }

    .form-group {
        margin-bottom: 1rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: var(--dark-gray);
    }

    .form-control {
        width: 100%;
        padding: 0.75rem;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        font-size: 1rem;
        transition: all 0.3s ease;
        box-sizing: border-box;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.1);
    }

    .priority-select {
        min-width: 120px;
    }

    .activities-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .activity-item {
        background: white;
        padding: 1.25rem;
        border-radius: 8px;
        border: 1px solid #e9ecef;
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        transition: all 0.3s ease;
        position: relative;
    }

    .activity-item:hover {
        box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        transform: translateX(5px);
    }

    .activity-item.completed {
        background: #f8f9fa;
        opacity: 0.8;
    }

    .activity-item.completed .activity-text {
        text-decoration: line-through;
        color: #6c757d;
    }

    .activity-item.priority-alta {
        border-left: 4px solid #dc3545;
    }

    .activity-item.priority-media {
        border-left: 4px solid #fd7e14;
    }

    .activity-item.priority-baixa {
        border-left: 4px solid #28a745;
    }

    .activity-checkbox {
        width: 20px;
        height: 20px;
        margin-top: 2px;
        cursor: pointer;
        accent-color: var(--primary-color);
    }

    .activity-content {
        flex: 1;
    }

    .activity-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.5rem;
        gap: 1rem;
    }

    .activity-text {
        margin: 0;
        line-height: 1.6;
        color: var(--dark-gray);
    }

    .priority-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .priority-badge.alta {
        background: #f8d7da;
        color: #dc3545;
    }

    .priority-badge.media {
        background: #fdecd8;
        color: #fd7e14;
    }

    .priority-badge.baixa {
        background: #d4edda;
        color: #28a745;
    }

    .activity-meta {
        font-size: 0.85rem;
        color: #6c757d;
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        margin-top: 0.5rem;
    }

    .activity-actions {
        display: flex;
        gap: 0.5rem;
        flex-direction: column;
        align-items: flex-end;
    }

    .btn-sm {
        padding: 0.4rem 0.8rem;
        font-size: 0.8rem;
    }

    .btn-danger {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: white;
    }

    .priority-select-inline {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
        border: 1px solid #e9ecef;
        border-radius: 4px;
        background: white;
        min-width: 80px;
    }

    .stats-bar {
        background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
        padding: 1rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
        border-top: 1px solid #e9ecef;
    }

    .stat-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 600;
        color: var(--primary-color);
    }

    .empty-state {
        text-align: center;
        padding: 3rem 2rem;
        color: #6c757d;
    }

    .empty-state i {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    .progress-bar {
        background: #e9ecef;
        border-radius: 10px;
        overflow: hidden;
        height: 8px;
        margin: 0.5rem 0;
    }

    .progress-fill {
        background: linear-gradient(90deg, var(--primary-color), #1e6b2e);
        height: 100%;
        transition: width 0.3s ease;
    }

    .toggle-completed {
        font-size: 0.9rem;
        color: var(--primary-color);
        cursor: pointer;
        text-decoration: underline;
    }

    .toggle-completed:hover {
        color: #1e6b2e;
    }

    /* Modal para histórico */
    .history-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1001;
        backdrop-filter: blur(5px);
    }

    .history-modal-content {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        max-width: 1000px;
        width: 95%;
        max-height: 90vh;
        overflow: hidden;
        animation: modalSlideIn 0.3s ease;
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translate(-50%, -60%);
        }
        to {
            opacity: 1;
            transform: translate(-50%, -50%);
        }
    }

    .history-modal-header {
        background: linear-gradient(135deg, #6f42c1, #5a2d91);
        color: white;
        padding: 1.5rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .history-modal-header h2 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .history-modal-close {
        background: none;
        border: none;
        color: white;
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 50%;
        transition: background 0.3s ease;
    }

    .history-modal-close:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .history-filters {
        background: #f8f9fa;
        padding: 1rem 2rem;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: center;
    }

    .history-modal-body {
        padding: 2rem;
        max-height: 60vh;
        overflow-y: auto;
    }

    .history-item {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 1.25rem;
        margin-bottom: 1rem;
        transition: all 0.3s ease;
    }

    .history-item:hover {
        background: #e9ecef;
        transform: translateX(5px);
    }

    .load-more-btn {
        width: 100%;
        padding: 1rem;
        background: linear-gradient(135deg, #6f42c1, #5a2d91);
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-top: 1rem;
    }

    .load-more-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(111, 66, 193, 0.3);
    }

    .load-more-btn:disabled {
        background: #6c757d;
        cursor: not-allowed;
        transform: none;
    }

    .migration-notice {
        background: linear-gradient(135deg, #fff3cd, #ffeaa7);
        border: 1px solid #ffc107;
        border-radius: 8px;
        padding: 1rem 1.5rem;
        margin: 1rem 2rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .migration-notice .notice-text {
        flex: 1;
        color: #856404;
        font-weight: 500;
    }

    .migration-notice .migrate-btn {
        background: #ffc107;
        color: #212529;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .migration-notice .migrate-btn:hover {
        background: #e0a800;
        transform: translateY(-2px);
    }

    /* Botão atividades de hoje */
    .today-activities-btn {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 1000;
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        border: none;
        padding: 1rem 1.5rem;
        border-radius: 50px;
        font-weight: 600;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.9rem;
    }

    .today-activities-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        background: linear-gradient(135deg, #20c997, #28a745);
    }

    .today-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1001;
        backdrop-filter: blur(5px);
    }

    .today-modal-content {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        max-width: 800px;
        width: 90%;
        max-height: 80vh;
        overflow: hidden;
        animation: modalSlideIn 0.3s ease;
    }

    .today-modal-header {
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        padding: 1.5rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .today-modal-header h2 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .today-modal-close {
        background: none;
        border: none;
        color: white;
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 50%;
        transition: background 0.3s ease;
    }

    .today-modal-close:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .today-modal-body {
        padding: 2rem;
        max-height: 60vh;
        overflow-y: auto;
    }

    .today-activity-item {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 1.25rem;
        margin-bottom: 1rem;
        transition: all 0.3s ease;
    }

    .today-activity-item:hover {
        background: #e9ecef;
        transform: translateX(5px);
    }

    .today-activity-module {
        background: var(--primary-color);
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.8rem;
        font-weight: 600;
        display: inline-block;
        margin-bottom: 0.75rem;
    }

    .today-activity-text {
        margin: 0 0 0.75rem 0;
        line-height: 1.6;
        color: var(--dark-gray);
        font-size: 1rem;
    }

    .today-activity-meta {
        font-size: 0.85rem;
        color: #6c757d;
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .today-stats {
        background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
        padding: 1rem 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
        border-top: 1px solid #e9ecef;
    }

    .today-empty-state {
        text-align: center;
        padding: 3rem 2rem;
        color: #6c757d;
    }

    .today-empty-state i {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.5;
        color: #28a745;
    }

    /* Responsividade */
    @media (max-width: 1200px) {
        .activities-container {
            padding: 1.5rem;
        }
        
        .page-section-header {
            padding: 1.25rem 1.5rem;
        }
        
        .page-content {
            padding: 1.5rem;
        }
    }

    @media (max-width: 768px) {
        .main-content {
            padding: 1rem 0.5rem;
        }

        .page-header {
            padding: 1.5rem 1rem;
        }

        .page-header h1 {
            font-size: 2rem;
        }

        .page-header p {
            font-size: 1rem;
        }

        .filters-section {
            padding: 1rem;
            flex-direction: column;
            align-items: stretch;
        }

        .filters-group {
            justify-content: space-between;
        }

        .migration-notice {
            margin: 1rem;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .activities-container {
            padding: 1rem;
        }

        .page-section-header {
            padding: 1rem;
            flex-direction: column;
            align-items: flex-start;
            gap: 1rem;
        }

        .page-title {
            font-size: 1.2rem;
        }

        .page-title .page-icon {
            width: 35px;
            height: 35px;
            font-size: 1rem;
        }

        .page-actions {
            width: 100%;
            justify-content: space-between;
        }

        .page-content {
            padding: 1rem;
        }

        .activity-form {
            padding: 1rem;
        }

        .form-row {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .activity-item {
            flex-direction: column;
            gap: 0.75rem;
            padding: 1rem;
        }

        .activity-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .activity-actions {
            flex-direction: row;
            align-self: flex-end;
        }

        .stats-bar {
            padding: 1rem;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .stat-item {
            font-size: 0.9rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        .btn-sm {
            padding: 0.35rem 0.7rem;
            font-size: 0.75rem;
        }

        .today-activities-btn {
            top: 10px;
            right: 10px;
            padding: 0.8rem 1.2rem;
            font-size: 0.8rem;
        }

        .today-modal-content,
        .history-modal-content {
            width: 95%;
            max-height: 90vh;
        }

        .today-modal-header,
        .history-modal-header {
            padding: 1rem 1.5rem;
        }

        .today-modal-header h2,
        .history-modal-header h2 {
            font-size: 1.3rem;
        }

        .today-modal-body,
        .history-modal-body {
            padding: 1.5rem;
        }

        .history-filters {
            padding: 1rem;
            flex-direction: column;
            align-items: stretch;
        }
    }

    @media (max-width: 480px) {
        .main-content {
            padding: 0.5rem 0.25rem;
        }

        .page-header {
            padding: 1rem 0.75rem;
        }

        .page-header h1 {
            font-size: 1.75rem;
        }

        .activities-container {
            padding: 0.75rem;
        }

        .page-section-header {
            padding: 0.75rem;
        }

        .page-title {
            font-size: 1.1rem;
        }

        .page-content {
            padding: 0.75rem;
        }

        .activity-form {
            padding: 0.75rem;
        }

        .activity-item {
            padding: 0.75rem;
        }

        .stats-bar {
            padding: 0.75rem;
        }

        .form-control {
            padding: 0.6rem;
            font-size: 0.9rem;
        }

        .page-actions {
            flex-direction: column;
            width: 100%;
            align-items: stretch;
        }

        .btn, .btn-sm {
            width: 100%;
            justify-content: center;
        }

        .today-activities-btn {
            position: static;
            margin: 1rem;
            width: calc(100% - 2rem);
            justify-content: center;
        }

        .today-modal-content,
        .history-modal-content {
            width: 98%;
            max-height: 95vh;
        }

        .today-modal-header,
        .history-modal-header {
            padding: 1rem;
        }

        .today-modal-body,
        .history-modal-body {
            padding: 1rem;
        }

        .today-activity-meta {
            flex-direction: column;
            gap: 0.5rem;
        }

        .today-stats {
            padding: 1rem;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.75rem;
        }
    }

    /* Animações */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .page-section {
        animation: fadeInUp 0.5s ease forwards;
    }

    .activity-item {
        animation: fadeInUp 0.3s ease forwards;
    }

    /* Estados de hover melhorados */
    .page-section:hover .page-title .page-icon {
        transform: scale(1.1);
        transition: transform 0.3s ease;
    }

    .activity-item:hover .activity-checkbox {
        transform: scale(1.1);
        transition: transform 0.3s ease;
    }

    /* Focus states melhorados */
    .btn:focus,
    .form-control:focus,
    .activity-checkbox:focus,
    .filter-select:focus,
    .priority-select-inline:focus {
        outline: 2px solid var(--primary-color);
        outline-offset: 2px;
    }

    /* Estados de loading */
    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

    /* Melhorias de acessibilidade */
    @media (prefers-reduced-motion: reduce) {
        .page-section,
        .activity-item,
        .btn,
        .page-title .page-icon,
        .activity-checkbox {
            animation: none;
            transition: none;
        }
    }

    /* Alto contraste */
    @media (prefers-contrast: high) {
        .page-section {
            border: 2px solid #000;
        }
        
        .btn-outline {
            border-width: 3px;
        }
        
        .form-control,
        .filter-select {
            border-width: 2px;
        }
    }
</style>

<div class="main-content">
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-tasks"></i> Gestão de Atividades</h1>
            <p>Sistema de controle de tarefas de desenvolvimento organizadas por módulos com controle de prioridades</p>
        </div>

        <!-- Seção de filtros -->
        <div class="filters-section">
            <div class="filters-group">
                <?php if ($hasPriorityColumn): ?>
                <div class="filter-item">
                    <label>Prioridade</label>
                    <select class="filter-select" id="priorityFilter" onchange="applyFilters()">
                        <option value="all" <?php echo $currentPriorityFilter === 'all' ? 'selected' : ''; ?>>Todas</option>
                        <option value="alta" <?php echo $currentPriorityFilter === 'alta' ? 'selected' : ''; ?>>Alta</option>
                        <option value="media" <?php echo $currentPriorityFilter === 'media' ? 'selected' : ''; ?>>Média</option>
                        <option value="baixa" <?php echo $currentPriorityFilter === 'baixa' ? 'selected' : ''; ?>>Baixa</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filter-item">
                    <label>Módulo</label>
                    <select class="filter-select" id="moduleFilter" onchange="applyFilters()">
                        <option value="all" <?php echo $currentModuleFilter === 'all' ? 'selected' : ''; ?>>Todos</option>
                        <?php foreach ($systemPages as $key => $title): ?>
                        <option value="<?php echo $key; ?>" <?php echo $currentModuleFilter === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button class="history-btn" onclick="showHistory()">
                <i class="fas fa-history"></i>
                Histórico de Atividades
            </button>
        </div>

        <!-- Botão para atividades de hoje -->
        <button class="today-activities-btn" onclick="showTodayActivities()">
            <i class="fas fa-calendar-check"></i>
            Atividades de Hoje
        </button>

        <!-- Modal das atividades de hoje -->
        <div class="today-modal" id="todayModal">
            <div class="today-modal-content">
                <div class="today-modal-header">
                    <h2>
                        <i class="fas fa-calendar-check"></i>
                        Atividades Concluídas Hoje
                        <span id="todayDate"></span>
                    </h2>
                    <button class="today-modal-close" onclick="closeTodayModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="today-modal-body" id="todayModalBody">
                    <!-- Conteúdo será carregado via JavaScript -->
                </div>
                <div class="today-stats" id="todayStats" style="display: none;">
                    <!-- Estatísticas serão carregadas via JavaScript -->
                </div>
            </div>
        </div>

        <!-- Modal do histórico -->
        <div class="history-modal" id="historyModal">
            <div class="history-modal-content">
                <div class="history-modal-header">
                    <h2>
                        <i class="fas fa-history"></i>
                        Histórico de Atividades
                    </h2>
                    <button class="history-modal-close" onclick="closeHistoryModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="history-filters">
                    <div class="filter-item">
                        <label>Módulo</label>
                        <select class="filter-select" id="historyModuleFilter">
                            <option value="all">Todos</option>
                            <?php foreach ($systemPages as $key => $title): ?>
                            <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($hasPriorityColumn): ?>
                    <div class="filter-item">
                        <label>Prioridade</label>
                        <select class="filter-select" id="historyPriorityFilter">
                            <option value="all">Todas</option>
                            <option value="alta">Alta</option>
                            <option value="media">Média</option>
                            <option value="baixa">Baixa</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="filter-item">
                        <label>Período</label>
                        <select class="filter-select" id="historyPeriodFilter">
                            <option value="">Todos</option>
                            <option value="week">Última semana</option>
                            <option value="month">Último mês</option>
                            <option value="quarter">Últimos 3 meses</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" onclick="loadHistory()">
                        <i class="fas fa-search"></i>
                        Filtrar
                    </button>
                </div>
                <div class="history-modal-body" id="historyModalBody">
                    <!-- Conteúdo será carregado via JavaScript -->
                </div>
            </div>
        </div>

        <div class="activities-container">
            <?php 
            $pageCounter = 1;
            foreach ($systemPages as $pageKey => $pageTitle): 
                // Constrói WHERE clause para filtros
                $whereClause = "WHERE a.page_name = ?";
                $params = [$pageKey];
                
                if ($currentPriorityFilter !== 'all' && $hasPriorityColumn) {
                    $whereClause .= " AND a.priority = ?";
                    $params[] = $currentPriorityFilter;
                }
                
                // Seleciona campos baseado na existência da coluna priority
                $selectFields = "a.id, a.page_name, a.activity_description, a.is_completed, a.created_by, a.created_at, a.completed_at";
                if ($hasPriorityColumn) {
                    $selectFields .= ", a.priority";
                }
                
                // Busca atividades da página com filtros
                $orderBy = "ORDER BY a.is_completed ASC, a.created_at DESC";
                if ($hasPriorityColumn) {
                    $orderBy = "ORDER BY 
                        CASE a.priority 
                            WHEN 'alta' THEN 1 
                            WHEN 'media' THEN 2 
                            WHEN 'baixa' THEN 3 
                        END,
                        a.is_completed ASC, 
                        a.created_at DESC";
                }
                
                $stmt = $pdo->prepare("
                    SELECT $selectFields, u.name as creator_name 
                    FROM activities a 
                    LEFT JOIN users u ON a.created_by = u.id 
                    $whereClause
                    $orderBy
                ");
                $stmt->execute($params);
                $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Adiciona priority padrão se a coluna não existir
                if (!$hasPriorityColumn) {
                    foreach ($activities as &$activity) {
                        $activity['priority'] = 'media';
                    }
                }
                
                $pendingActivities = array_filter($activities, function($a) { return !$a['is_completed']; });
                $completedActivities = array_filter($activities, function($a) { return $a['is_completed']; });
                
                $totalActivities = count($activities);
                $completedCount = count($completedActivities);
                $progressPercent = $totalActivities > 0 ? ($completedCount / $totalActivities) * 100 : 0;
                
                // Pula módulos sem atividades quando há filtros aplicados
                if (($currentPriorityFilter !== 'all' || $currentModuleFilter !== 'all') && empty($activities)) {
                    continue;
                }
                
                // Se há filtro de módulo específico, mostra apenas esse módulo
                if ($currentModuleFilter !== 'all' && $currentModuleFilter !== $pageKey) {
                    continue;
                }
            ?>
            
            <div class="page-section" data-page="<?php echo $pageKey; ?>">
                <div class="page-section-header">
                    <div class="page-title">
                        <span class="page-icon"><?php echo $pageCounter; ?></span>
                        <?php echo htmlspecialchars($pageTitle); ?>
                    </div>
                    <div class="page-actions">
                        <span class="toggle-completed" onclick="toggleCompletedActivities('<?php echo $pageKey; ?>')">
                            <i class="fas fa-eye"></i> Ver concluídas (<?php echo $completedCount; ?>)
                        </span>
                        <button class="btn btn-outline btn-sm" onclick="toggleAddForm('<?php echo $pageKey; ?>')">
                            <i class="fas fa-plus"></i> Nova Atividade
                        </button>
                    </div>
                </div>

                <div class="page-content">
                    <!-- Formulário para adicionar atividade -->
                    <div class="activity-form" id="form-<?php echo $pageKey; ?>" style="display: none;">
                        <form onsubmit="addActivity(event, '<?php echo $pageKey; ?>')">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="description-<?php echo $pageKey; ?>">Descrição da Atividade:</label>
                                    <textarea 
                                        class="form-control" 
                                        id="description-<?php echo $pageKey; ?>" 
                                        rows="3" 
                                        placeholder="Descreva a atividade a ser realizada..."
                                        required
                                    ></textarea>
                                </div>
                                <?php if ($hasPriorityColumn): ?>
                                <div class="form-group">
                                    <label for="priority-<?php echo $pageKey; ?>">Prioridade:</label>
                                    <select class="form-control priority-select" id="priority-<?php echo $pageKey; ?>">
                                        <option value="baixa">Baixa</option>
                                        <option value="media" selected>Média</option>
                                        <option value="alta">Alta</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1rem;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Adicionar
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="toggleAddForm('<?php echo $pageKey; ?>')">
                                    <i class="fas fa-times"></i> Cancelar
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Lista de atividades pendentes -->
                    <div class="activities-list" id="activities-<?php echo $pageKey; ?>">
                        <?php if (empty($pendingActivities)): ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <p>Nenhuma atividade pendente para este módulo</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($pendingActivities as $activity): ?>
                                <div class="activity-item priority-<?php echo $activity['priority']; ?>" id="activity-<?php echo $activity['id']; ?>">
                                    <input 
                                        type="checkbox" 
                                        class="activity-checkbox"
                                        onchange="toggleActivity(<?php echo $activity['id']; ?>, this.checked)"
                                        <?php echo $activity['is_completed'] ? 'checked' : ''; ?>
                                        aria-label="Marcar atividade como concluída"
                                    >
                                    <div class="activity-content">
                                        <div class="activity-header">
                                            <p class="activity-text"><?php echo nl2br(htmlspecialchars($activity['activity_description'])); ?></p>
                                            <span class="priority-badge <?php echo $activity['priority']; ?>">
                                                <i class="<?php echo $priorityConfig[$activity['priority']]['icon']; ?>"></i>
                                                <?php echo $priorityConfig[$activity['priority']]['label']; ?>
                                            </span>
                                        </div>
                                        <div class="activity-meta">
                                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($activity['creator_name']); ?></span>
                                            <span><i class="fas fa-calendar"></i> <?php echo date('d/m/Y H:i', strtotime($activity['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="activity-actions">
                                        <?php if ($hasPriorityColumn): ?>
                                        <select class="priority-select-inline" onchange="updatePriority(<?php echo $activity['id']; ?>, this.value)">
                                            <option value="baixa" <?php echo $activity['priority'] === 'baixa' ? 'selected' : ''; ?>>Baixa</option>
                                            <option value="media" <?php echo $activity['priority'] === 'media' ? 'selected' : ''; ?>>Média</option>
                                            <option value="alta" <?php echo $activity['priority'] === 'alta' ? 'selected' : ''; ?>>Alta</option>
                                        </select>
                                        <?php endif; ?>
                                        <button class="btn btn-danger btn-sm" onclick="deleteActivity(<?php echo $activity['id']; ?>)" aria-label="Excluir atividade">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Lista de atividades concluídas (oculta por padrão) -->
                    <div class="activities-list" id="completed-<?php echo $pageKey; ?>" style="display: none;">
                        <?php foreach ($completedActivities as $activity): ?>
                            <div class="activity-item completed priority-<?php echo $activity['priority']; ?>" id="activity-<?php echo $activity['id']; ?>">
                                <input 
                                    type="checkbox" 
                                    class="activity-checkbox"
                                    checked
                                    onchange="toggleActivity(<?php echo $activity['id']; ?>, this.checked)"
                                    aria-label="Desmarcar atividade como concluída"
                                >
                                <div class="activity-content">
                                    <div class="activity-header">
                                        <p class="activity-text"><?php echo nl2br(htmlspecialchars($activity['activity_description'])); ?></p>
                                        <span class="priority-badge <?php echo $activity['priority']; ?>">
                                            <i class="<?php echo $priorityConfig[$activity['priority']]['icon']; ?>"></i>
                                            <?php echo $priorityConfig[$activity['priority']]['label']; ?>
                                        </span>
                                    </div>
                                    <div class="activity-meta">
                                        <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($activity['creator_name']); ?></span>
                                        <span><i class="fas fa-calendar"></i> Criada: <?php echo date('d/m/Y', strtotime($activity['created_at'])); ?></span>
                                        <?php if ($activity['completed_at']): ?>
                                            <span><i class="fas fa-check"></i> Concluída: <?php echo date('d/m/Y H:i', strtotime($activity['completed_at'])); ?></span>
                                        <?php else: ?>
                                            <span><i class="fas fa-check"></i> Concluída: (data não registrada)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="activity-actions">
                                    <button class="btn btn-danger btn-sm" onclick="deleteActivity(<?php echo $activity['id']; ?>)" aria-label="Excluir atividade">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Barra de estatísticas -->
                    <?php if ($totalActivities > 0): ?>
                    <div class="stats-bar">
                        <div class="stat-item">
                            <i class="fas fa-list"></i>
                            Total: <?php echo $totalActivities; ?>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-check"></i>
                            Concluídas: <?php echo $completedCount; ?>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-clock"></i>
                            Pendentes: <?php echo count($pendingActivities); ?>
                        </div>
                        <?php if ($hasPriorityColumn): ?>
                        <?php
                        // Estatísticas por prioridade para atividades pendentes
                        $altaPendentes = count(array_filter($pendingActivities, function($a) { return $a['priority'] === 'alta'; }));
                        $mediaPendentes = count(array_filter($pendingActivities, function($a) { return $a['priority'] === 'media'; }));
                        $baixaPendentes = count(array_filter($pendingActivities, function($a) { return $a['priority'] === 'baixa'; }));
                        ?>
                        <div class="stat-item" style="color: #dc3545;">
                            <i class="fas fa-exclamation-triangle"></i>
                            Alta: <?php echo $altaPendentes; ?>
                        </div>
                        <div class="stat-item" style="color: #fd7e14;">
                            <i class="fas fa-minus-circle"></i>
                            Média: <?php echo $mediaPendentes; ?>
                        </div>
                        <div class="stat-item" style="color: #28a745;">
                            <i class="fas fa-check-circle"></i>
                            Baixa: <?php echo $baixaPendentes; ?>
                        </div>
                        <?php endif; ?>
                        <div style="flex: 1; max-width: 200px; min-width: 150px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                                <span style="font-size: 0.8rem; color: #6c757d;">Progresso</span>
                                <span style="font-size: 0.8rem; font-weight: 600;"><?php echo round($progressPercent); ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $progressPercent; ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php 
            $pageCounter++;
            endforeach; ?>
        </div>
    </div>
</div>

<script>
// Variáveis globais
let currentHistoryPage = 1;
let hasMoreHistory = true;
let historyLoading = false;

// Função para aplicar filtros
function applyFilters() {
    const priorityFilter = document.getElementById('priorityFilter');
    const moduleFilter = document.getElementById('moduleFilter');
    
    const params = new URLSearchParams();
    
    if (priorityFilter && priorityFilter.value !== 'all') {
        params.append('priority_filter', priorityFilter.value);
    }
    if (moduleFilter && moduleFilter.value !== 'all') {
        params.append('module_filter', moduleFilter.value);
    }
    
    window.location.href = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
}

// Função para toggle do formulário de adicionar atividade
function toggleAddForm(pageKey) {
    const form = document.getElementById(`form-${pageKey}`);
    const isVisible = form.style.display !== 'none';
    
    form.style.display = isVisible ? 'none' : 'block';
    
    if (!isVisible) {
        const textarea = document.getElementById(`description-${pageKey}`);
        textarea.focus();
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    }
}

// Função para adicionar nova atividade
function addActivity(event, pageKey) {
    event.preventDefault();
    
    const description = document.getElementById(`description-${pageKey}`).value.trim();
    const priorityField = document.getElementById(`priority-${pageKey}`);
    const priority = priorityField ? priorityField.value : 'media';
    
    if (!description) {
        alert('Por favor, descreva a atividade antes de adicionar.');
        return;
    }
    
    const submitButton = event.target.querySelector('button[type="submit"]');
    const originalText = submitButton.innerHTML;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adicionando...';
    submitButton.disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'add_activity');
    formData.append('page_name', pageKey);
    formData.append('description', description);
    formData.append('priority', priority);
    
    fetch('atividades.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById(`description-${pageKey}`).value = '';
            if (priorityField) {
                priorityField.value = 'media';
            }
            toggleAddForm(pageKey);
            location.reload();
        } else {
            alert('Erro ao adicionar atividade: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao adicionar atividade. Tente novamente.');
    })
    .finally(() => {
        submitButton.innerHTML = originalText;
        submitButton.disabled = false;
    });
}

// Função para atualizar prioridade
function updatePriority(activityId, newPriority) {
    const formData = new FormData();
    formData.append('action', 'update_priority');
    formData.append('id', activityId);
    formData.append('priority', newPriority);
    
    fetch('atividades.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Erro ao atualizar prioridade: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao atualizar prioridade. Tente novamente.');
    });
}

// Função para marcar/desmarcar atividade como concluída
function toggleActivity(activityId, isCompleted) {
    const checkbox = document.querySelector(`#activity-${activityId} .activity-checkbox`);
    const originalChecked = checkbox.checked;
    
    checkbox.disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'toggle_activity');
    formData.append('id', activityId);
    formData.append('completed', isCompleted);
    
    fetch('atividades.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Erro ao atualizar atividade: ' + (data.error || 'Erro desconhecido'));
            checkbox.checked = originalChecked;
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao atualizar atividade. Tente novamente.');
        checkbox.checked = originalChecked;
    })
    .finally(() => {
        checkbox.disabled = false;
    });
}

// Função para deletar atividade
function deleteActivity(activityId) {
    if (!confirm('Tem certeza que deseja excluir esta atividade?')) return;
    
    const activityElement = document.getElementById(`activity-${activityId}`);
    const deleteButton = activityElement.querySelector('.btn-danger');
    const originalText = deleteButton.innerHTML;
    
    deleteButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    deleteButton.disabled = true;
    activityElement.style.opacity = '0.5';
    
    const formData = new FormData();
    formData.append('action', 'delete_activity');
    formData.append('id', activityId);
    
    fetch('atividades.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            activityElement.style.transform = 'translateX(-100%)';
            setTimeout(() => {
                activityElement.remove();
                location.reload();
            }, 300);
        } else {
            alert('Erro ao excluir atividade: ' + (data.error || 'Erro desconhecido'));
            activityElement.style.opacity = '1';
            deleteButton.innerHTML = originalText;
            deleteButton.disabled = false;
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao excluir atividade. Tente novamente.');
        activityElement.style.opacity = '1';
        deleteButton.innerHTML = originalText;
        deleteButton.disabled = false;
    });
}

// Função para mostrar/esconder atividades concluídas
function toggleCompletedActivities(pageKey) {
    const completedList = document.getElementById(`completed-${pageKey}`);
    const toggleButton = document.querySelector(`[data-page="${pageKey}"] .toggle-completed`);
    
    const isVisible = completedList.style.display !== 'none';
    
    completedList.style.display = isVisible ? 'none' : 'block';
    
    const icon = toggleButton.querySelector('i');
    const textNode = Array.from(toggleButton.childNodes).find(node => 
        node.nodeType === Node.TEXT_NODE && node.textContent.trim()
    );
    
    if (isVisible) {
        icon.className = 'fas fa-eye';
        if (textNode) {
            textNode.textContent = textNode.textContent.replace('Ocultar', 'Ver');
        }
    } else {
        icon.className = 'fas fa-eye-slash';
        if (textNode) {
            textNode.textContent = textNode.textContent.replace('Ver', 'Ocultar');
        }
    }
}

// Função para mostrar atividades de hoje
function showTodayActivities() {
    const modal = document.getElementById('todayModal');
    const modalBody = document.getElementById('todayModalBody');
    const todayStats = document.getElementById('todayStats');
    const dateElement = document.getElementById('todayDate');
    
    const today = new Date();
    const dateStr = today.toLocaleDateString('pt-BR', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
    dateElement.textContent = `- ${dateStr}`;
    
    modalBody.innerHTML = `
        <div style="text-align: center; padding: 2rem;">
            <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--primary-color);"></i>
            <p style="margin-top: 1rem; color: #6c757d;">Carregando atividades...</p>
        </div>
    `;
    todayStats.style.display = 'none';
    modal.style.display = 'block';
    
    fetch('atividades.php?action=get_today_activities')
        .then(response => response.json())
        .then(data => {
            console.log('Atividades de hoje:', data); // Debug
            if (data.success) {
                displayTodayActivities(data.activities);
            } else {
                modalBody.innerHTML = `
                    <div class="today-empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Erro ao carregar atividades: ${data.error || 'Erro desconhecido'}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            modalBody.innerHTML = `
                <div class="today-empty-state">
                    <i class="fas fa-wifi"></i>
                    <p>Erro de conexão. Tente novamente.</p>
                </div>
            `;
        });
}

// Função para exibir as atividades de hoje
function displayTodayActivities(activities) {
    const modalBody = document.getElementById('todayModalBody');
    const todayStats = document.getElementById('todayStats');
    
    if (activities.length === 0) {
        modalBody.innerHTML = `
            <div class="today-empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>Nenhuma atividade concluída hoje</h3>
                <p>Que tal marcar algumas atividades como concluídas?</p>
            </div>
        `;
        todayStats.style.display = 'none';
        return;
    }
    
    const moduleMap = <?php echo json_encode($systemPages); ?>;
    const priorityConfig = <?php echo json_encode($priorityConfig); ?>;
    
    let htmlContent = '';
    
    activities.forEach(activity => {
        const moduleName = moduleMap[activity.page_name] || activity.page_name;
        
        // Determina data/hora de conclusão
        let completedTime = '';
        if (activity.completed_at) {
            try {
                completedTime = new Date(activity.completed_at).toLocaleTimeString('pt-BR', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                completedTime = 'horário não disponível';
            }
        } else {
            completedTime = 'horário não registrado';
        }
        
        const priority = activity.priority || 'media';
        const priorityInfo = priorityConfig[priority];
        
        htmlContent += `
            <div class="today-activity-item">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem;">
                    <div class="today-activity-module">${moduleName}</div>
                    <span class="priority-badge ${priority}">
                        <i class="${priorityInfo.icon}"></i>
                        ${priorityInfo.label}
                    </span>
                </div>
                <p class="today-activity-text">${activity.activity_description.replace(/\n/g, '<br>')}</p>
                <div class="today-activity-meta">
                    <span><i class="fas fa-user"></i> ${activity.creator_name || 'Usuário desconhecido'}</span>
                    <span><i class="fas fa-check"></i> Concluída às ${completedTime}</span>
                </div>
            </div>
        `;
    });
    
    modalBody.innerHTML = htmlContent;
    
    const modulesCount = new Set(activities.map(a => a.page_name)).size;
    const priorityCounts = {
        alta: activities.filter(a => (a.priority || 'media') === 'alta').length,
        media: activities.filter(a => (a.priority || 'media') === 'media').length,
        baixa: activities.filter(a => (a.priority || 'media') === 'baixa').length
    };
    
    todayStats.innerHTML = `
        <div class="stat-item">
            <i class="fas fa-check-circle"></i>
            Total: ${activities.length} atividade${activities.length !== 1 ? 's' : ''}
        </div>
        <div class="stat-item">
            <i class="fas fa-layer-group"></i>
            ${modulesCount} módulo${modulesCount !== 1 ? 's' : ''}
        </div>
        <div class="stat-item" style="color: #dc3545;">
            <i class="fas fa-exclamation-triangle"></i>
            Alta: ${priorityCounts.alta}
        </div>
        <div class="stat-item" style="color: #fd7e14;">
            <i class="fas fa-minus-circle"></i>
            Média: ${priorityCounts.media}
        </div>
        <div class="stat-item" style="color: #28a745;">
            <i class="fas fa-check-circle"></i>
            Baixa: ${priorityCounts.baixa}
        </div>
        <div class="stat-item">
            <i class="fas fa-calendar-day"></i>
            Hoje: ${new Date().toLocaleDateString('pt-BR')}
        </div>
    `;
    todayStats.style.display = 'flex';
}

// Função para fechar modal de hoje
function closeTodayModal() {
    document.getElementById('todayModal').style.display = 'none';
}

// Função para mostrar histórico
function showHistory() {
    const modal = document.getElementById('historyModal');
    modal.style.display = 'block';
    
    // Reset filtros e página
    currentHistoryPage = 1;
    hasMoreHistory = true;
    document.getElementById('historyModuleFilter').value = 'all';
    
    const priorityFilter = document.getElementById('historyPriorityFilter');
    if (priorityFilter) {
        priorityFilter.value = 'all';
    }
    
    document.getElementById('historyPeriodFilter').value = '';
    
    loadHistory();
}

// Função para carregar histórico
function loadHistory(append = false) {
    if (historyLoading) return;
    
    historyLoading = true;
    const modalBody = document.getElementById('historyModalBody');
    
    if (!append) {
        currentHistoryPage = 1;
        modalBody.innerHTML = `
            <div style="text-align: center; padding: 2rem;">
                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #6f42c1;"></i>
                <p style="margin-top: 1rem; color: #6c757d;">Carregando histórico...</p>
            </div>
        `;
    }
    
    const module = document.getElementById('historyModuleFilter').value;
    const priorityFilter = document.getElementById('historyPriorityFilter');
    const priority = priorityFilter ? priorityFilter.value : 'all';
    const period = document.getElementById('historyPeriodFilter').value;
    
    const params = new URLSearchParams({
        action: 'get_history',
        page: currentHistoryPage,
        module: module,
        priority: priority,
        period: period
    });
    
    console.log('Carregando histórico com parâmetros:', params.toString()); // Debug
    
    fetch(`atividades.php?${params}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Resposta do histórico:', data); // Debug
            
            if (data.success) {
                displayHistory(data.activities, data.total, append);
                hasMoreHistory = data.hasMore;
                
                // Se não há coluna priority, oculta o filtro de prioridade
                if (data.hasPriorityColumn === false) {
                    const priorityFilterItem = document.getElementById('historyPriorityFilter');
                    if (priorityFilterItem) {
                        const filterItem = priorityFilterItem.closest('.filter-item');
                        if (filterItem) {
                            filterItem.style.display = 'none';
                        }
                    }
                }
            } else {
                let errorMessage = data.error || 'Erro desconhecido';
                
                // Trata especificamente erro de coluna inexistente
                if (errorMessage.includes('priority') || errorMessage.includes('Unknown column')) {
                    errorMessage = 'Banco de dados sendo atualizado. Recarregue a página.';
                }
                
                modalBody.innerHTML = `
                    <div style="text-align: center; padding: 2rem; color: #6c757d;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                        <p>Erro ao carregar histórico: ${errorMessage}</p>
                        <button onclick="loadHistory()" style="margin-top: 1rem; padding: 0.5rem 1rem; background: #6f42c1; color: white; border: none; border-radius: 4px; cursor: pointer;">
                            <i class="fas fa-retry"></i> Tentar novamente
                        </button>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Erro na requisição:', error);
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: #6c757d;">
                    <i class="fas fa-wifi" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                    <p>Erro de conexão. Verifique sua internet e tente novamente.</p>
                    <button onclick="loadHistory()" style="margin-top: 1rem; padding: 0.5rem 1rem; background: #6f42c1; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        <i class="fas fa-retry"></i> Tentar novamente
                    </button>
                </div>
            `;
        })
        .finally(() => {
            historyLoading = false;
        });
}

// Função para exibir histórico
function displayHistory(activities, total, append = false) {
    const modalBody = document.getElementById('historyModalBody');
    const moduleMap = <?php echo json_encode($systemPages); ?>;
    const priorityConfig = <?php echo json_encode($priorityConfig); ?>;
    
    if (!append) {
        modalBody.innerHTML = '';
    }
    
    // Remove botão "Carregar mais" se existir
    const existingLoadMore = modalBody.querySelector('.load-more-btn');
    if (existingLoadMore) {
        existingLoadMore.remove();
    }
    
    if (activities.length === 0 && !append) {
        modalBody.innerHTML = `
            <div style="text-align: center; padding: 3rem 2rem; color: #6c757d;">
                <i class="fas fa-history" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                <h3>Nenhuma atividade encontrada</h3>
                <p>Não há atividades concluídas com os filtros selecionados.</p>
            </div>
        `;
        return;
    }
    
    activities.forEach(activity => {
        const moduleName = moduleMap[activity.page_name] || activity.page_name;
        
        // Verifica se completed_at existe e não é nulo
        let completedDateStr = 'Data não informada';
        let completedTimeStr = '';
        
        if (activity.completed_at) {
            try {
                const completedDate = new Date(activity.completed_at);
                if (!isNaN(completedDate.getTime())) {
                    completedDateStr = completedDate.toLocaleDateString('pt-BR');
                    completedTimeStr = completedDate.toLocaleTimeString('pt-BR', {
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                }
            } catch (e) {
                console.warn('Erro ao processar data de conclusão:', e);
            }
        } else {
            // Se não tem completed_at, usa created_at como fallback
            try {
                const createdDate = new Date(activity.created_at);
                if (!isNaN(createdDate.getTime())) {
                    completedDateStr = createdDate.toLocaleDateString('pt-BR') + ' (estimado)';
                }
            } catch (e) {
                console.warn('Erro ao processar data de criação:', e);
            }
        }
        
        // Verifica created_at
        let createdDateStr = 'Data não informada';
        if (activity.created_at) {
            try {
                const createdDate = new Date(activity.created_at);
                if (!isNaN(createdDate.getTime())) {
                    createdDateStr = createdDate.toLocaleDateString('pt-BR');
                }
            } catch (e) {
                console.warn('Erro ao processar data de criação:', e);
            }
        }
        
        const priority = activity.priority || 'media';
        const priorityInfo = priorityConfig[priority] || priorityConfig['media'];
        
        const historyItem = document.createElement('div');
        historyItem.className = 'history-item';
        historyItem.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem;">
                <div style="background: var(--primary-color); color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600;">${moduleName}</div>
                <span class="priority-badge ${priority}">
                    <i class="${priorityInfo.icon}"></i>
                    ${priorityInfo.label}
                </span>
            </div>
            <p style="margin: 0 0 0.75rem 0; line-height: 1.6; color: var(--dark-gray); font-size: 1rem;">${(activity.activity_description || '').replace(/\n/g, '<br>')}</p>
            <div style="font-size: 0.85rem; color: #6c757d; display: flex; gap: 1rem; flex-wrap: wrap;">
                <span><i class="fas fa-user"></i> ${activity.creator_name || 'Usuário não informado'}</span>
                <span><i class="fas fa-calendar"></i> Criada: ${createdDateStr}</span>
                <span><i class="fas fa-check"></i> Concluída: ${completedDateStr}${completedTimeStr ? ' às ' + completedTimeStr : ''}</span>
            </div>
        `;
        modalBody.appendChild(historyItem);
    });
    
    // Adiciona botão "Carregar mais" se houver mais atividades
    if (hasMoreHistory) {
        const loadMoreBtn = document.createElement('button');
        loadMoreBtn.className = 'load-more-btn';
        loadMoreBtn.innerHTML = '<i class="fas fa-chevron-down"></i> Carregar mais atividades';
        loadMoreBtn.onclick = () => {
            currentHistoryPage++;
            loadHistory(true);
        };
        modalBody.appendChild(loadMoreBtn);
    }
    
    // Mostra total de atividades no início
    if (!append && total > 0) {
        const totalInfo = document.createElement('div');
        totalInfo.style.cssText = 'background: #e3f2fd; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; text-align: center; color: var(--primary-color); font-weight: 600;';
        totalInfo.innerHTML = `<i class="fas fa-info-circle"></i> Total de ${total} atividade${total !== 1 ? 's' : ''} concluída${total !== 1 ? 's' : ''} encontrada${total !== 1 ? 's' : ''}`;
        modalBody.insertBefore(totalInfo, modalBody.firstChild);
    }
}

// Função para fechar modal de histórico
function closeHistoryModal() {
    document.getElementById('historyModal').style.display = 'none';
}

// Função para melhorar a experiência com teclado
function initKeyboardNavigation() {
    document.addEventListener('keydown', function(e) {
        // ESC para fechar formulários abertos e modais
        if (e.key === 'Escape') {
            const openForms = document.querySelectorAll('.activity-form[style*="block"]');
            openForms.forEach(form => {
                const pageKey = form.id.replace('form-', '');
                toggleAddForm(pageKey);
            });
            
            // Fecha modais se estiverem abertos
            const todayModal = document.getElementById('todayModal');
            if (todayModal && todayModal.style.display === 'block') {
                closeTodayModal();
            }
            
            const historyModal = document.getElementById('historyModal');
            if (historyModal && historyModal.style.display === 'block') {
                closeHistoryModal();
            }
        }
        
        // Ctrl/Cmd + Enter para adicionar atividade rapidamente
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            const activeElement = document.activeElement;
            if (activeElement && activeElement.tagName === 'TEXTAREA') {
                const form = activeElement.closest('form');
                if (form) {
                    form.dispatchEvent(new Event('submit'));
                }
            }
        }
        
        // Ctrl/Cmd + T para abrir atividades de hoje
        if ((e.ctrlKey || e.metaKey) && e.key === 't') {
            e.preventDefault();
            showTodayActivities();
        }
        
        // Ctrl/Cmd + H para abrir histórico
        if ((e.ctrlKey || e.metaKey) && e.key === 'h') {
            e.preventDefault();
            showHistory();
        }
    });
}

// Função para animação de entrada suave
function animatePageSections() {
    const sections = document.querySelectorAll('.page-section');
    sections.forEach((section, index) => {
        section.style.animationDelay = `${index * 0.1}s`;
        section.style.opacity = '0';
        section.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            section.style.transition = 'all 0.5s ease';
            section.style.opacity = '1';
            section.style.transform = 'translateY(0)';
        }, index * 100);
    });
}

// Função para otimizar performance em dispositivos móveis
function optimizeMobilePerformance() {
    if (window.innerWidth <= 768) {
        const style = document.createElement('style');
        style.textContent = `
            .page-section:hover { transform: none; }
            .activity-item:hover { transform: none; }
            .btn:hover { transform: none; }
        `;
        document.head.appendChild(style);
    }
}

// Fechar modais clicando fora
document.addEventListener('click', function(e) {
    const todayModal = document.getElementById('todayModal');
    if (e.target === todayModal) {
        closeTodayModal();
    }
    
    const historyModal = document.getElementById('historyModal');
    if (e.target === historyModal) {
        closeHistoryModal();
    }
});

// Inicialização quando o DOM estiver carregado
document.addEventListener('DOMContentLoaded', function() {
    // Animações de entrada
    animatePageSections();
    
    // Navegação por teclado
    initKeyboardNavigation();
    
    // Otimizações para mobile
    optimizeMobilePerformance();
    
    // Auto-resize para textareas
    const textareas = document.querySelectorAll('textarea.form-control');
    textareas.forEach(textarea => {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    });
    
    // Feedback de conectividade
    window.addEventListener('online', function() {
        console.log('Conexão restaurada');
    });
    
    window.addEventListener('offline', function() {
        alert('Conexão perdida. Algumas funcionalidades podem não estar disponíveis.');
    });
});
</script>

<?php
// Finaliza a página
endPage(true);
?>