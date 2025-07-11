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
        switch ($_POST['action']) {
            case 'add_activity':
                $stmt = $pdo->prepare("
                    INSERT INTO activities (page_name, activity_description, created_by, created_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $_POST['page_name'],
                    $_POST['description'],
                    $_SESSION['user']['id']
                ]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                break;
                
            case 'toggle_activity':
                $isCompleted = $_POST['completed'] == 'true' ? 1 : 0;
                $completedAt = $isCompleted ? 'NOW()' : 'NULL';
                
                $stmt = $pdo->prepare("
                    UPDATE activities 
                    SET is_completed = ?, completed_at = $completedAt 
                    WHERE id = ?
                ");
                $stmt->execute([$isCompleted, $_POST['id']]);
                echo json_encode(['success' => true]);
                break;
                
            case 'delete_activity':
                $stmt = $pdo->prepare("DELETE FROM activities WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                echo json_encode(['success' => true]);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Inicia a página
startPage("Gestão de Atividades - LicitaSis", "atividades");

// Cria a tabela se não existir
try {
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
} catch (Exception $e) {
    // Ignora se a tabela já existir
}

// Lista de páginas do sistema - ATUALIZADA com os novos módulos
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

    .activity-text {
        margin: 0 0 0.5rem 0;
        line-height: 1.6;
        color: var(--dark-gray);
    }

    .activity-meta {
        font-size: 0.85rem;
        color: #6c757d;
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .activity-actions {
        display: flex;
        gap: 0.5rem;
    }

    .btn-sm {
        padding: 0.4rem 0.8rem;
        font-size: 0.8rem;
    }

    .btn-danger {
        background: linear-gradient(135deg, #dc3545, #c82333);
        color: white;
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

    .new-module-badge {
        background: linear-gradient(135deg, #ff6b6b, #ee5a52);
        color: white;
        padding: 0.3rem 0.8rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
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

        .activity-item {
            flex-direction: column;
            gap: 0.75rem;
            padding: 1rem;
        }

        .activity-actions {
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
    .activity-checkbox:focus {
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
        
        .new-module-badge {
            animation: none;
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
        
        .form-control {
            border-width: 2px;
        }
    }
</style>

<div class="main-content">
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-tasks"></i> Gestão de Atividades</h1>
            <p>Sistema de controle de tarefas de desenvolvimento organizadas por módulos</p>
        </div>

        <div class="activities-container">
            <?php 
            $pageCounter = 1;
            foreach ($systemPages as $pageKey => $pageTitle): 
                // Busca atividades da página
                $stmt = $pdo->prepare("
                    SELECT a.*, u.name as creator_name 
                    FROM activities a 
                    LEFT JOIN users u ON a.created_by = u.id 
                    WHERE a.page_name = ? 
                    ORDER BY a.is_completed ASC, a.created_at DESC
                ");
                $stmt->execute([$pageKey]);
                $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $pendingActivities = array_filter($activities, function($a) { return !$a['is_completed']; });
                $completedActivities = array_filter($activities, function($a) { return $a['is_completed']; });
                
                $totalActivities = count($activities);
                $completedCount = count($completedActivities);
                $progressPercent = $totalActivities > 0 ? ($completedCount / $totalActivities) * 100 : 0;
                
                // Verifica se é um módulo novo (removido para todos)
                $isNewModule = false;
            ?>
            
            <div class="page-section" data-page="<?php echo $pageKey; ?>">
                <div class="page-section-header">
                    <div class="page-title">
                        <span class="page-icon">
                            <?php if ($pageKey === 'pregoes'): ?>
                                11
                            <?php elseif ($pageKey === 'investidores'): ?>
                                12
                            <?php else: ?>
                                <?php echo $pageCounter; ?>
                            <?php endif; ?>
                        </span>
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
                            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
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
                                <div class="activity-item" id="activity-<?php echo $activity['id']; ?>">
                                    <input 
                                        type="checkbox" 
                                        class="activity-checkbox"
                                        onchange="toggleActivity(<?php echo $activity['id']; ?>, this.checked)"
                                        <?php echo $activity['is_completed'] ? 'checked' : ''; ?>
                                        aria-label="Marcar atividade como concluída"
                                    >
                                    <div class="activity-content">
                                        <p class="activity-text"><?php echo nl2br(htmlspecialchars($activity['activity_description'])); ?></p>
                                        <div class="activity-meta">
                                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($activity['creator_name']); ?></span>
                                            <span><i class="fas fa-calendar"></i> <?php echo date('d/m/Y H:i', strtotime($activity['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="activity-actions">
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
                            <div class="activity-item completed" id="activity-<?php echo $activity['id']; ?>">
                                <input 
                                    type="checkbox" 
                                    class="activity-checkbox"
                                    checked
                                    onchange="toggleActivity(<?php echo $activity['id']; ?>, this.checked)"
                                    aria-label="Desmarcar atividade como concluída"
                                >
                                <div class="activity-content">
                                    <p class="activity-text"><?php echo nl2br(htmlspecialchars($activity['activity_description'])); ?></p>
                                    <div class="activity-meta">
                                        <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($activity['creator_name']); ?></span>
                                        <span><i class="fas fa-calendar"></i> Criada: <?php echo date('d/m/Y', strtotime($activity['created_at'])); ?></span>
                                        <?php if ($activity['completed_at']): ?>
                                            <span><i class="fas fa-check"></i> Concluída: <?php echo date('d/m/Y H:i', strtotime($activity['completed_at'])); ?></span>
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
// Função para toggle do formulário de adicionar atividade
function toggleAddForm(pageKey) {
    const form = document.getElementById(`form-${pageKey}`);
    const isVisible = form.style.display !== 'none';
    
    form.style.display = isVisible ? 'none' : 'block';
    
    if (!isVisible) {
        const textarea = document.getElementById(`description-${pageKey}`);
        textarea.focus();
        // Move cursor para o final
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    }
}

// Função para adicionar nova atividade
function addActivity(event, pageKey) {
    event.preventDefault();
    
    const description = document.getElementById(`description-${pageKey}`).value.trim();
    if (!description) {
        alert('Por favor, descreva a atividade antes de adicionar.');
        return;
    }
    
    // Desabilita o botão de submit temporariamente
    const submitButton = event.target.querySelector('button[type="submit"]');
    const originalText = submitButton.innerHTML;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adicionando...';
    submitButton.disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'add_activity');
    formData.append('page_name', pageKey);
    formData.append('description', description);
    
    fetch('atividades.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Limpa o formulário e o esconde
            document.getElementById(`description-${pageKey}`).value = '';
            toggleAddForm(pageKey);
            
            // Recarrega a página para mostrar a nova atividade
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
        // Restaura o botão
        submitButton.innerHTML = originalText;
        submitButton.disabled = false;
    });
}

// Função para marcar/desmarcar atividade como concluída
function toggleActivity(activityId, isCompleted) {
    const checkbox = document.querySelector(`#activity-${activityId} .activity-checkbox`);
    const originalChecked = checkbox.checked;
    
    // Feedback visual imediato
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
            // Recarrega a página para atualizar as estatísticas
            location.reload();
        } else {
            alert('Erro ao atualizar atividade: ' + (data.error || 'Erro desconhecido'));
            // Reverte o checkbox
            checkbox.checked = originalChecked;
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao atualizar atividade. Tente novamente.');
        // Reverte o checkbox
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
    
    // Feedback visual
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
            // Animação de saída
            activityElement.style.transform = 'translateX(-100%)';
            setTimeout(() => {
                activityElement.remove();
                // Recarrega para atualizar estatísticas se necessário
                location.reload();
            }, 300);
        } else {
            alert('Erro ao excluir atividade: ' + (data.error || 'Erro desconhecido'));
            // Restaura estado
            activityElement.style.opacity = '1';
            deleteButton.innerHTML = originalText;
            deleteButton.disabled = false;
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao excluir atividade. Tente novamente.');
        // Restaura estado
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

// Função para melhorar a experiência com teclado
function initKeyboardNavigation() {
    document.addEventListener('keydown', function(e) {
        // ESC para fechar formulários abertos
        if (e.key === 'Escape') {
            const openForms = document.querySelectorAll('.activity-form[style*="block"]');
            openForms.forEach(form => {
                const pageKey = form.id.replace('form-', '');
                toggleAddForm(pageKey);
            });
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
    });
}

// Função para lazy loading de estatísticas
function updateProgressBars() {
    const progressBars = document.querySelectorAll('.progress-fill');
    progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
            bar.style.width = width;
        }, 500);
    });
}

// Função para salvar estado dos módulos expandidos
function saveModuleStates() {
    const expandedModules = [];
    document.querySelectorAll('.activities-list[style*="block"]').forEach(list => {
        const pageKey = list.id.replace('completed-', '');
        expandedModules.push(pageKey);
    });
    
    try {
        sessionStorage.setItem('expandedModules', JSON.stringify(expandedModules));
    } catch (e) {
        console.warn('Não foi possível salvar o estado dos módulos');
    }
}

// Função para restaurar estado dos módulos
function restoreModuleStates() {
    try {
        const expandedModules = JSON.parse(sessionStorage.getItem('expandedModules') || '[]');
        expandedModules.forEach(pageKey => {
            const completedList = document.getElementById(`completed-${pageKey}`);
            if (completedList) {
                toggleCompletedActivities(pageKey);
            }
        });
    } catch (e) {
        console.warn('Não foi possível restaurar o estado dos módulos');
    }
}

// Função para otimizar performance em dispositivos móveis
function optimizeMobilePerformance() {
    if (window.innerWidth <= 768) {
        // Reduz animações em dispositivos móveis
        const style = document.createElement('style');
        style.textContent = `
            .page-section:hover { transform: none; }
            .activity-item:hover { transform: none; }
            .btn:hover { transform: none; }
        `;
        document.head.appendChild(style);
    }
}

// Função para adicionar tooltips informativos
function initTooltips() {
    const progressBars = document.querySelectorAll('.progress-bar');
    progressBars.forEach(bar => {
        const section = bar.closest('.page-section');
        const pageTitle = section.querySelector('.page-title').textContent.trim();
        bar.title = `Progresso das atividades do módulo ${pageTitle}`;
    });
}

// Inicialização quando o DOM estiver carregado
document.addEventListener('DOMContentLoaded', function() {
    // Animações de entrada
    animatePageSections();
    
    // Navegação por teclado
    initKeyboardNavigation();
    
    // Atualiza barras de progresso com animação
    updateProgressBars();
    
    // Restaura estado dos módulos expandidos
    restoreModuleStates();
    
    // Otimizações para mobile
    optimizeMobilePerformance();
    
    // Tooltips
    initTooltips();
    
    // Salva estado ao sair da página
    window.addEventListener('beforeunload', saveModuleStates);
    
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

// Função para exportar atividades (funcionalidade extra)
function exportActivities(pageKey) {
    const activities = [];
    const section = document.querySelector(`[data-page="${pageKey}"]`);
    const activityItems = section.querySelectorAll('.activity-item');
    
    activityItems.forEach(item => {
        const text = item.querySelector('.activity-text').textContent;
        const meta = item.querySelector('.activity-meta').textContent;
        const completed = item.classList.contains('completed');
        
        activities.push({
            text: text.trim(),
            meta: meta.trim(),
            completed: completed
        });
    });
    
    const pageTitle = section.querySelector('.page-title').textContent.replace('Novo', '').trim();
    const dataStr = JSON.stringify({ module: pageTitle, activities }, null, 2);
    const dataBlob = new Blob([dataStr], { type: 'application/json' });
    
    const link = document.createElement('a');
    link.href = URL.createObjectURL(dataBlob);
    link.download = `atividades_${pageKey}_${new Date().toISOString().split('T')[0]}.json`;
    link.click();
}

// Função para modo escuro (toggle)
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    const isDark = document.body.classList.contains('dark-mode');
    
    try {
        localStorage.setItem('darkMode', isDark);
    } catch (e) {
        console.warn('Não foi possível salvar preferência de tema');
    }
}

// Carrega preferência de tema
try {
    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark-mode');
    }
} catch (e) {
    console.warn('Não foi possível carregar preferência de tema');
}
</script>

<?php
// Finaliza a página
endPage(true);
?>