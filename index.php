<?php
session_start();

// Inclui o sistema de permissões
include('db.php');
include('permissions.php');

// Inicializa o gerenciador de permissões
$permissionManager = initPermissions($pdo);

// Inclui o template de header
include('includes/header_template.php');

// Inicia a página
startPage("Início - LicitaSis", "index");
?>

<style>
    /* Estilos específicos para a página inicial */
    .main-content {
        padding: 2rem 1rem;
        min-height: calc(100vh - 140px);
    }

    .container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 2rem;
    }

    .welcome-container {
        text-align: center;
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        align-items: center;
        gap: 2rem;
    }

    .welcome-title {
        font-size: 2.5rem;
        font-weight: 700;
        color: var(--primary-color);
        margin: 0;
        text-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .welcome-text {
        color: var(--medium-gray);
        font-size: 1.2rem;
        margin: 0;
        font-weight: 500;
    }

    .permission-info {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        padding: 2rem;
        border-radius: 12px;
        margin: 0;
        border-left: 5px solid var(--secondary-color);
        max-width: 700px;
        width: 100%;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        border: 1px solid rgba(0,0,0,0.05);
    }

    .permission-info h3 {
        color: var(--primary-color);
        margin: 0 0 1rem 0;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        font-size: 1.3rem;
        font-weight: 600;
    }

    .permission-info p {
        color: var(--medium-gray);
        line-height: 1.7;
        margin: 0;
        font-size: 1rem;
    }

    /* Grid de estatísticas melhorado */
    .stats-container {
        width: 100%;
        max-width: 1200px;
        margin: 0 auto;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 2rem;
        margin: 0;
        width: 100%;
    }

    .stat-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        padding: 2rem 1.5rem;
        border-radius: 16px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        text-align: center;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid rgba(0,0,0,0.05);
        position: relative;
        overflow: hidden;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
        border-radius: 16px 16px 0 0;
    }

    .stat-card:hover {
        transform: translateY(-8px) scale(1.02);
        box-shadow: 0 15px 35px rgba(0,0,0,0.15);
    }

    .stat-card:nth-child(1)::before {
        background: linear-gradient(90deg, #4CAF50, #45a049);
    }

    .stat-card:nth-child(2)::before {
        background: linear-gradient(90deg, #2196F3, #1976D2);
    }

    .stat-card:nth-child(3)::before {
        background: linear-gradient(90deg, #FF9800, #F57C00);
    }

    .stat-card:nth-child(4)::before {
        background: linear-gradient(90deg, #9C27B0, #7B1FA2);
    }

    .stat-icon {
        font-size: 3rem;
        margin-bottom: 1.5rem;
        display: block;
        transition: all 0.3s ease;
    }

    .stat-card:nth-child(1) .stat-icon {
        color: #4CAF50;
    }

    .stat-card:nth-child(2) .stat-icon {
        color: #2196F3;
    }

    .stat-card:nth-child(3) .stat-icon {
        color: #FF9800;
    }

    .stat-card:nth-child(4) .stat-icon {
        color: #9C27B0;
    }

    .stat-card:hover .stat-icon {
        transform: scale(1.1);
    }

    .stat-number {
        font-size: 2.5rem;
        font-weight: 800;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        line-height: 1;
        display: block;
    }

    .stat-label {
        color: var(--medium-gray);
        font-size: 1rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin: 0;
    }

    /* Ações rápidas */
    .quick-actions {
        width: 100%;
        max-width: 800px;
        margin: 0 auto;
    }

    .quick-actions h3 {
        color: var(--primary-color);
        font-size: 1.5rem;
        font-weight: 600;
        margin-bottom: 1.5rem;
    }

    .actions-grid {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .btn {
        padding: 1rem 2rem;
        font-size: 1rem;
        font-weight: 600;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        text-decoration: none;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color), #0056b3);
        color: white;
    }

    .btn-secondary {
        background: linear-gradient(135deg, var(--secondary-color), #5a6268);
        color: white;
    }

    .btn-success {
        background: linear-gradient(135deg, #28a745, #1e7e34);
        color: white;
    }

    /* Botão de logout */
    .logout-button {
        background: linear-gradient(135deg, var(--danger-color) 0%, #c82333 100%);
        color: white;
        padding: 1rem 2.5rem;
        font-size: 1.1rem;
        font-weight: 600;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        box-shadow: 0 4px 6px rgba(220, 53, 69, 0.2);
        margin: 0 auto;
    }

    .logout-button:hover {
        background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(220, 53, 69, 0.3);
    }

    .logout-button:active {
        transform: translateY(1px);
    }

    /* Responsividade */
    @media (max-width: 1200px) {
        .container {
            padding: 0 1.5rem;
        }
        
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }
    }

    @media (max-width: 768px) {
        .container {
            padding: 0 1rem;
        }

        .welcome-title {
            font-size: 2rem;
        }

        .welcome-text {
            font-size: 1rem;
        }

        .permission-info {
            padding: 1.5rem;
        }

        .stats-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .stat-card {
            padding: 1.5rem 1rem;
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .stat-number {
            font-size: 2rem;
        }

        .actions-grid {
            flex-direction: column;
            align-items: center;
        }

        .btn {
            width: 100%;
            max-width: 300px;
            justify-content: center;
        }
    }

    @media (max-width: 480px) {
        .main-content {
            padding: 1rem 0.5rem;
        }

        .welcome-container {
            gap: 1.5rem;
        }

        .welcome-title {
            font-size: 1.8rem;
        }

        .stat-card {
            padding: 1.25rem 1rem;
        }

        .stat-icon {
            font-size: 2.2rem;
        }

        .stat-number {
            font-size: 1.8rem;
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

    .stat-card {
        animation: fadeInUp 0.6s ease forwards;
    }

    .stat-card:nth-child(1) { animation-delay: 0.1s; }
    .stat-card:nth-child(2) { animation-delay: 0.2s; }
    .stat-card:nth-child(3) { animation-delay: 0.3s; }
    .stat-card:nth-child(4) { animation-delay: 0.4s; }
</style>

<div class="main-content">
    <div class="container welcome-container">
        <!-- Informações de permissão baseadas no nível do usuário -->
        <?php 
        $userPermission = $_SESSION['user']['permission'];
        $permissionDescriptions = [
            'Administrador' => [
                'icon' => 'fas fa-crown',
                'title' => 'Acesso Administrativo',
                'description' => 'Você possui permissões de administrador com acesso completo a todas as funcionalidades do sistema, incluindo gestão de usuários e funcionários.'
            ],
            'Usuario_Nivel_1' => [
                'icon' => 'fas fa-eye',
                'title' => 'Acesso de Visualização',
                'description' => 'Você tem permissão para visualizar dados do sistema. Para edições ou criação de registros, entre em contato com o administrador.'
            ],
            'Usuario_Nivel_2' => [
                'icon' => 'fas fa-edit',
                'title' => 'Acesso de Edição',
                'description' => 'Você pode consultar e editar dados do sistema em todas as áreas, exceto gestão de usuários e funcionários.'
            ],
            'Investidor' => [
                'icon' => 'fas fa-chart-line',
                'title' => 'Portal do Investidor',
                'description' => 'Você tem acesso exclusivo à área de investimentos com relatórios e informações financeiras.'
            ]
        ];

        $currentPermission = $permissionDescriptions[$userPermission] ?? $permissionDescriptions['Usuario_Nivel_1'];
        ?>

        <div class="permission-info">
            <h3>
                <i class="<?php echo $currentPermission['icon']; ?>"></i> 
                <?php echo $currentPermission['title']; ?>
            </h3>
            <p><?php echo $currentPermission['description']; ?></p>
        </div>

        <h2 class="welcome-title">Bem-vindo(a) ao LicitaSis!</h2>
        <p class="welcome-text">Sistema de gestão de licitações.</p>

        <!-- Dashboard com estatísticas básicas (se tiver permissão) -->
        <?php if ($permissionManager->hasPagePermission('clientes', 'view') || 
                  $permissionManager->hasPagePermission('produtos', 'view') || 
                  $permissionManager->hasPagePermission('vendas', 'view')): ?>
        <div class="stats-container">
            <div class="stats-grid">
                <?php if ($permissionManager->hasPagePermission('clientes', 'view')): ?>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number">
                        <?php
                        try {
                            $stmt = $pdo->query("SELECT COUNT(*) as total FROM clientes");
                            $result = $stmt->fetch();
                            echo $result['total'] ?? '0';
                        } catch (Exception $e) {
                            echo '0';
                        }
                        ?>
                    </div>
                    <div class="stat-label">Clientes</div>
                </div>
                <?php endif; ?>

                <?php if ($permissionManager->hasPagePermission('produtos', 'view')): ?>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-number">
                        <?php
                        try {
                            $stmt = $pdo->query("SELECT COUNT(*) as total FROM produtos");
                            $result = $stmt->fetch();
                            echo $result['total'] ?? '0';
                        } catch (Exception $e) {
                            echo '0';
                        }
                        ?>
                    </div>
                    <div class="stat-label">Produtos</div>
                </div>
                <?php endif; ?>

                <?php if ($permissionManager->hasPagePermission('vendas', 'view')): ?>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="stat-number">
                        <?php
                        try {
                            $stmt = $pdo->query("SELECT COUNT(*) as total FROM vendas WHERE MONTH(data_venda) = MONTH(CURRENT_DATE()) AND YEAR(data_venda) = YEAR(CURRENT_DATE())");
                            $result = $stmt->fetch();
                            echo $result['total'] ?? '0';
                        } catch (Exception $e) {
                            echo '0';
                        }
                        ?>
                    </div>
                    <div class="stat-label">Vendas (Mês)</div>
                </div>
                <?php endif; ?>

                <?php if ($permissionManager->isAdmin()): ?>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="stat-number">
                        <?php
                        try {
                            $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
                            $result = $stmt->fetch();
                            echo $result['total'] ?? '0';
                        } catch (Exception $e) {
                            echo '0';
                        }
                        ?>
                    </div>
                    <div class="stat-label">Usuários</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Links rápidos baseados em permissões -->
        <?php if (!$permissionManager->isInvestor()): ?>
        <div class="quick-actions">
            <h3>Ações Rápidas</h3>
            <div class="actions-grid">
                <?php if ($permissionManager->hasPagePermission('clientes', 'create')): ?>
                    <a href="cadastrar_clientes.php" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Novo Cliente
                    </a>
                <?php endif; ?>
                
                <?php if ($permissionManager->hasPagePermission('produtos', 'create')): ?>
                    <a href="cadastro_produto.php" class="btn btn-secondary">
                        <i class="fas fa-plus"></i> Novo Produto
                    </a>
                <?php endif; ?>
                
                <?php if ($permissionManager->hasPagePermission('vendas', 'view')): ?>
                    <a href="consulta_vendas.php" class="btn btn-success">
                        <i class="fas fa-chart-bar"></i> Vendas
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Botão de Logout -->
        <form action="logout.php" method="POST">
            
        </form>
    </div>
</div>

<?php
// Finaliza a página com footer
endPage(true, "
    // JavaScript específico da página inicial
    document.addEventListener('DOMContentLoaded', function() {
        // Efeito de contagem nos números das estatísticas
        const statNumbers = document.querySelectorAll('.stat-number');
        
        const animateNumber = (element) => {
            const finalNumber = parseInt(element.textContent);
            if (finalNumber === 0) return;
            
            let currentNumber = 0;
            const increment = Math.max(1, Math.ceil(finalNumber / 50));
            const duration = 1500; // 1.5 segundos
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
        };
        
        // Observer para animar quando os cards ficarem visíveis
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const numberElement = entry.target.querySelector('.stat-number');
                    if (numberElement && !numberElement.dataset.animated) {
                        numberElement.dataset.animated = 'true';
                        setTimeout(() => animateNumber(numberElement), 200);
                    }
                }
            });
        }, { threshold: 0.5 });
        
        // Observa todos os cards de estatísticas
        document.querySelectorAll('.stat-card').forEach(card => {
            observer.observe(card);
        });
        
        // Efeito hover nos cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });
        
        // Smooth scroll para ações rápidas
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function(e) {
                // Adiciona um pequeno delay para feedback visual
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = '';
                }, 150);
            });
        });
    });
");
?>