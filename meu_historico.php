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
$user_id = $_SESSION['user']['id'];

// Parâmetros de filtro
$limit = $_GET['limit'] ?? 50;
$action_filter = $_GET['action'] ?? '';

// Busca o histórico do usuário
$activities = getUserAuditHistory($user_id, $limit);

// Filtra por ação se especificado
if ($action_filter && $activities) {
    $activities = array_filter($activities, function($activity) use ($action_filter) {
        return $activity['action'] === $action_filter;
    });
}

// Inclui o template de header
include('includes/header_template.php');
startPage("Meu Histórico - LicitaSis", "historico");
?>

<style>
    /* Estilos específicos da página de histórico */
    .activity-container {
        max-width: 1200px;
        margin: 0 auto;
    }

    .activity-header {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
        color: white;
        padding: 2rem;
        border-radius: var(--radius);
        margin-bottom: 2rem;
        text-align: center;
    }

    .activity-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin: 2rem 0;
    }

    .stat-card {
        background: white;
        padding: 1.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        text-align: center;
        border-left: 4px solid var(--secondary-color);
    }

    .stat-number {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
    }

    .stat-label {
        color: var(--medium-gray);
        font-size: 0.9rem;
    }

    .filters {
        background: white;
        padding: 1.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        margin-bottom: 2rem;
    }

    .filters h3 {
        color: var(--primary-color);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .filter-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        align-items: end;
    }

    .activity-list {
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .activity-item {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-color);
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 1rem;
        align-items: center;
        transition: var(--transition);
    }

    .activity-item:hover {
        background: var(--light-gray);
    }

    .activity-item:last-child {
        border-bottom: none;
    }

    .activity-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }

    .activity-icon.login { background: rgba(40, 167, 69, 0.1); color: var(--success-color); }
    .activity-icon.logout { background: rgba(108, 117, 125, 0.1); color: var(--medium-gray); }
    .activity-icon.update { background: rgba(255, 193, 7, 0.1); color: var(--warning-color); }
    .activity-icon.create { background: rgba(40, 167, 69, 0.1); color: var(--success-color); }
    .activity-icon.delete { background: rgba(220, 53, 69, 0.1); color: var(--danger-color); }
    .activity-icon.access-denied { background: rgba(220, 53, 69, 0.1); color: var(--danger-color); }
    .activity-icon.profile { background: rgba(0, 191, 174, 0.1); color: var(--secondary-color); }
    .activity-icon.password { background: rgba(255, 193, 7, 0.1); color: var(--warning-color); }

    .activity-content {
        flex: 1;
    }

    .activity-title {
        font-weight: 600;
        color: var(--dark-gray);
        margin-bottom: 0.25rem;
    }

    .activity-description {
        color: var(--medium-gray);
        font-size: 0.9rem;
        margin-bottom: 0.25rem;
    }

    .activity-meta {
        font-size: 0.8rem;
        color: var(--medium-gray);
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .activity-time {
        text-align: right;
        color: var(--medium-gray);
        font-size: 0.9rem;
    }

    .activity-date {
        font-weight: 600;
    }

    .no-activities {
        padding: 3rem;
        text-align: center;
        color: var(--medium-gray);
    }

    .load-more {
        text-align: center;
        margin: 2rem 0;
    }

    .badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
        font-weight: 600;
        border-radius: 10px;
        text-align: center;
    }

    .badge-success { background: rgba(40, 167, 69, 0.1); color: var(--success-color); }
    .badge-warning { background: rgba(255, 193, 7, 0.1); color: var(--warning-color); }
    .badge-danger { background: rgba(220, 53, 69, 0.1); color: var(--danger-color); }
    .badge-info { background: rgba(0, 191, 174, 0.1); color: var(--secondary-color); }
    .badge-secondary { background: rgba(108, 117, 125, 0.1); color: var(--medium-gray); }

    @media (max-width: 768px) {
        .activity-item {
            grid-template-columns: auto 1fr;
            gap: 1rem;
        }

        .activity-time {
            grid-column: 1 / -1;
            text-align: left;
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid var(--border-color);
        }

        .activity-meta {
            flex-direction: column;
            gap: 0.25rem;
        }

        .filter-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="main-content">
    <div class="container activity-container">
        
        <!-- Header -->
        <div class="activity-header">
            <h1><i class="fas fa-history"></i> Meu Histórico de Atividades</h1>
            <p>Acompanhe todas as suas ações no sistema</p>
        </div>

        <!-- Link voltar -->
        <a href="editar_perfil.php" class="btn btn-secondary mb-3">
            <i class="fas fa-arrow-left"></i> Voltar ao Perfil
        </a>

        <!-- Estatísticas -->
        <?php 
        $stats = [
            'total' => count($activities),
            'logins' => count(array_filter($activities, fn($a) => $a['action'] === 'LOGIN')),
            'updates' => count(array_filter($activities, fn($a) => in_array($a['action'], ['UPDATE', 'PROFILE_UPDATE', 'PASSWORD_CHANGE']))),
            'last_activity' => $activities ? $activities[0]['created_at'] : null
        ];
        ?>

        <div class="activity-stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total de Atividades</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['logins']; ?></div>
                <div class="stat-label">Acessos ao Sistema</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['updates']; ?></div>
                <div class="stat-label">Atualizações</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php echo $stats['last_activity'] ? date('d/m', strtotime($stats['last_activity'])) : '-'; ?>
                </div>
                <div class="stat-label">Última Atividade</div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filters">
            <h3><i class="fas fa-filter"></i> Filtros</h3>
            <form method="GET" class="filter-row">
                <div class="form-group">
                    <label for="action">Tipo de Ação:</label>
                    <select id="action" name="action" class="form-control">
                        <option value="">Todas as ações</option>
                        <option value="LOGIN" <?php echo $action_filter === 'LOGIN' ? 'selected' : ''; ?>>Login</option>
                        <option value="LOGOUT" <?php echo $action_filter === 'LOGOUT' ? 'selected' : ''; ?>>Logout</option>
                        <option value="PROFILE_UPDATE" <?php echo $action_filter === 'PROFILE_UPDATE' ? 'selected' : ''; ?>>Atualização de Perfil</option>
                        <option value="PASSWORD_CHANGE" <?php echo $action_filter === 'PASSWORD_CHANGE' ? 'selected' : ''; ?>>Alteração de Senha</option>
                        <option value="UPDATE" <?php echo $action_filter === 'UPDATE' ? 'selected' : ''; ?>>Atualizações</option>
                        <option value="CREATE" <?php echo $action_filter === 'CREATE' ? 'selected' : ''; ?>>Criações</option>
                        <option value="DELETE" <?php echo $action_filter === 'DELETE' ? 'selected' : ''; ?>>Exclusões</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="limit">Quantidade:</label>
                    <select id="limit" name="limit" class="form-control">
                        <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 registros</option>
                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 registros</option>
                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 registros</option>
                        <option value="200" <?php echo $limit == 200 ? 'selected' : ''; ?>>200 registros</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>

        <!-- Lista de atividades -->
        <div class="activity-list">
            <?php if (empty($activities)): ?>
                <div class="no-activities">
                    <i class="fas fa-history" style="font-size: 3rem; color: var(--border-color); margin-bottom: 1rem;"></i>
                    <h3>Nenhuma atividade encontrada</h3>
                    <p>Suas atividades no sistema aparecerão aqui.</p>
                </div>
            <?php else: ?>
                <?php foreach ($activities as $activity): ?>
                    <?php
                    $iconClass = '';
                    $badgeClass = '';
                    
                    switch ($activity['action']) {
                        case 'LOGIN':
                            $iconClass = 'login';
                            $badgeClass = 'badge-success';
                            $title = 'Acesso ao Sistema';
                            $description = 'Login realizado com sucesso';
                            break;
                        case 'LOGOUT':
                            $iconClass = 'logout';
                            $badgeClass = 'badge-secondary';
                            $title = 'Saída do Sistema';
                            $description = 'Logout realizado';
                            break;
                        case 'PROFILE_UPDATE':
                            $iconClass = 'profile';
                            $badgeClass = 'badge-info';
                            $title = 'Perfil Atualizado';
                            $description = 'Dados pessoais alterados';
                            break;
                        case 'PASSWORD_CHANGE':
                            $iconClass = 'password';
                            $badgeClass = 'badge-warning';
                            $title = 'Senha Alterada';
                            $description = 'Senha de acesso modificada';
                            break;
                        case 'UPDATE':
                            $iconClass = 'update';
                            $badgeClass = 'badge-warning';
                            $title = 'Atualização';
                            $description = 'Registro atualizado: ' . ($activity['table_name'] ?? 'sistema');
                            break;
                        case 'CREATE':
                            $iconClass = 'create';
                            $badgeClass = 'badge-success';
                            $title = 'Criação';
                            $description = 'Novo registro criado: ' . ($activity['table_name'] ?? 'sistema');
                            break;
                        case 'DELETE':
                            $iconClass = 'delete';
                            $badgeClass = 'badge-danger';
                            $title = 'Exclusão';
                            $description = 'Registro excluído: ' . ($activity['table_name'] ?? 'sistema');
                            break;
                        case 'ACCESS_DENIED':
                            $iconClass = 'access-denied';
                            $badgeClass = 'badge-danger';
                            $title = 'Acesso Negado';
                            $description = 'Tentativa de acesso não autorizado';
                            break;
                        default:
                            $iconClass = 'update';
                            $badgeClass = 'badge-info';
                            $title = formatAuditAction($activity['action']);
                            $description = 'Ação realizada no sistema';
                    }
                    
                    $details = $activity['details'] ? json_decode($activity['details'], true) : null;
                    ?>
                    
                    <div class="activity-item">
                        <div class="activity-icon <?php echo $iconClass; ?>">
                            <i class="<?php echo getAuditActionIcon($activity['action']); ?>"></i>
                        </div>
                        
                        <div class="activity-content">
                            <div class="activity-title">
                                <?php echo $title; ?>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php echo formatAuditAction($activity['action']); ?>
                                </span>
                            </div>
                            <div class="activity-description"><?php echo $description; ?></div>
                            <div class="activity-meta">
                                <?php if ($activity['ip_address']): ?>
                                    <span><i class="fas fa-map-marker-alt"></i> IP: <?php echo $activity['ip_address']; ?></span>
                                <?php endif; ?>
                                <?php if ($activity['table_name']): ?>
                                    <span><i class="fas fa-database"></i> <?php echo ucfirst($activity['table_name']); ?></span>
                                <?php endif; ?>
                                <?php if ($activity['record_id']): ?>
                                    <span><i class="fas fa-hashtag"></i> ID: <?php echo $activity['record_id']; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="activity-time">
                            <div class="activity-date">
                                <?php echo date('d/m/Y', strtotime($activity['created_at'])); ?>
                            </div>
                            <div>
                                <?php echo date('H:i:s', strtotime($activity['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Botão para carregar mais -->
        <?php if (count($activities) >= $limit): ?>
        <div class="load-more">
            <a href="?limit=<?php echo $limit + 50; ?>&action=<?php echo $action_filter; ?>" class="btn btn-secondary">
                <i class="fas fa-plus"></i> Carregar Mais Atividades
            </a>
        </div>
        <?php endif; ?>

        <!-- Informações adicionais -->
        <div class="security-info mt-4">
            <h4><i class="fas fa-info-circle"></i> Sobre o Histórico</h4>
            <ul class="security-tips">
                <li>Todas as suas ações no sistema são registradas automaticamente</li>
                <li>Os registros incluem data, hora e endereço IP</li>
                <li>Este histórico ajuda a manter a segurança da sua conta</li>
                <li>Em caso de atividade suspeita, entre em contato com o administrador</li>
                <li>Os logs são mantidos por questões de auditoria e segurança</li>
            </ul>
        </div>

    </div>
</div>

<?php
endPage(true, "
    // JavaScript específico da página de histórico
    document.addEventListener('DOMContentLoaded', function() {
        // Anima os itens de atividade
        const activityItems = document.querySelectorAll('.activity-item');
        activityItems.forEach((item, index) => {
            item.style.opacity = '0';
            item.style.transform = 'translateX(-20px)';
            item.style.transition = 'all 0.5s ease';
            
            setTimeout(() => {
                item.style.opacity = '1';
                item.style.transform = 'translateX(0)';
            }, index * 50);
        });

        // Efeito hover nos itens
        activityItems.forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.transform = 'translateX(5px)';
                this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
            });
            
            item.addEventListener('mouseleave', function() {
                this.style.transform = 'translateX(0)';
                this.style.boxShadow = 'none';
            });
        });

        // Contador animado para estatísticas
        const statNumbers = document.querySelectorAll('.stat-number');
        statNumbers.forEach(element => {
            const text = element.textContent;
            if (/^\d+$/.test(text)) {
                const finalNumber = parseInt(text);
                let currentNumber = 0;
                const increment = Math.ceil(finalNumber / 20);
                
                const timer = setInterval(() => {
                    currentNumber += increment;
                    if (currentNumber >= finalNumber) {
                        currentNumber = finalNumber;
                        clearInterval(timer);
                    }
                    element.textContent = currentNumber;
                }, 50);
            }
        });

        // Auto-submit do formulário de filtros quando mudar
        const filterForm = document.querySelector('.filters form');
        const filterInputs = filterForm.querySelectorAll('select');
        
        filterInputs.forEach(input => {
            input.addEventListener('change', function() {
                // Adiciona um pequeno delay para UX
                setTimeout(() => {
                    filterForm.submit();
                }, 100);
            });
        });

        // Tooltip para IPs
        const ipElements = document.querySelectorAll('.activity-meta span');
        ipElements.forEach(element => {
            if (element.textContent.includes('IP:')) {
                element.title = 'Endereço IP de onde a ação foi realizada';
                element.style.cursor = 'help';
            }
        });

        // Realça atividades recentes (últimas 24h)
        const now = new Date();
        const oneDayAgo = new Date(now.getTime() - 24 * 60 * 60 * 1000);
        
        activityItems.forEach(item => {
            const timeElement = item.querySelector('.activity-time');
            const dateText = timeElement.textContent.trim();
            
            // Extrai a data do elemento
            const dateMatch = dateText.match(/(\d{2})\/(\d{2})\/(\d{4})/);
            if (dateMatch) {
                const itemDate = new Date(dateMatch[3], dateMatch[2] - 1, dateMatch[1]);
                
                if (itemDate > oneDayAgo) {
                    item.style.borderLeft = '4px solid var(--success-color)';
                    item.style.backgroundColor = 'rgba(40, 167, 69, 0.02)';
                    
                    // Adiciona badge 'Recente'
                    const title = item.querySelector('.activity-title');
                    if (title && !title.querySelector('.badge-recent')) {
                        const recentBadge = document.createElement('span');
                        recentBadge.className = 'badge badge-success badge-recent';
                        recentBadge.innerHTML = '<i class=\"fas fa-clock\"></i> Recente';
                        recentBadge.style.marginLeft = '0.5rem';
                        title.appendChild(recentBadge);
                    }
                }
            }
        });

        // Smooth scroll para links de ancoragem
        document.querySelectorAll('a[href^=\"#\"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Detecta se há muitas atividades e sugere filtros
        if (activityItems.length > 50) {
            const suggestion = document.createElement('div');
            suggestion.className = 'message info';
            suggestion.innerHTML = '<i class=\"fas fa-lightbulb\"></i> Muitas atividades encontradas. Use os filtros para encontrar o que procura mais facilmente.';
            
            const filtersSection = document.querySelector('.filters');
            filtersSection.parentNode.insertBefore(suggestion, filtersSection.nextSibling);
            
            // Remove a sugestão após 10 segundos
            setTimeout(() => {
                suggestion.style.transition = 'opacity 0.5s ease';
                suggestion.style.opacity = '0';
                setTimeout(() => suggestion.remove(), 500);
            }, 10000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + F para focar no filtro
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('action').focus();
            }
            
            // Esc para limpar filtros
            if (e.key === 'Escape') {
                const currentUrl = new URL(window.location);
                currentUrl.searchParams.delete('action');
                currentUrl.searchParams.set('limit', '50');
                window.location.href = currentUrl.toString();
            }
        });

        // Adiciona indicação visual para atividades críticas
        activityItems.forEach(item => {
            const badge = item.querySelector('.badge-danger');
            if (badge) {
                item.style.borderLeft = '4px solid var(--danger-color)';
                item.style.backgroundColor = 'rgba(220, 53, 69, 0.02)';
                
                // Adiciona ícone de alerta
                const icon = item.querySelector('.activity-icon');
                icon.style.border = '2px solid var(--danger-color)';
                icon.style.animation = 'pulse 2s infinite';
            }
        });

        // Adiciona animação de pulse para atividades críticas
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); }
                100% { transform: scale(1); }
            }
        `;
        document.head.appendChild(style);
    });
");
?>