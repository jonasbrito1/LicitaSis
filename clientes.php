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

// Verifica se o usuário tem permissão para acessar clientes
$permissionManager->requirePermission('clientes', 'view');

// Registra acesso à página
logUserAction('READ', 'clientes_dashboard');

// Busca estatísticas de clientes
$totalClientes = 0;
$clientesAtivos = 0;
$ultimoCadastro = null;
$valorTotalVendas = 0; // NOVO
$clientesComEmpenhos = 0; // NOVO

try {
    // Total de clientes
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clientes");
    $result = $stmt->fetch();
    $totalClientes = $result['total'] ?? 0;
    
    // Clientes cadastrados este mês
    $stmt = $pdo->query("
        SELECT COUNT(*) as ativos 
        FROM clientes 
        WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
        AND YEAR(created_at) = YEAR(CURRENT_DATE())
    ");
    $result = $stmt->fetch();
    $clientesAtivos = $result['ativos'] ?? 0;
    
    // NOVO: Valor total de vendas dos clientes
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(valor_total), 0) as total_vendas 
        FROM vendas 
        WHERE cliente_uasg IS NOT NULL 
        AND cliente_uasg != ''
    ");
    $result = $stmt->fetch();
    $valorTotalVendas = $result['total_vendas'] ?? 0;
    
    // NOVO: Número de clientes com empenhos
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT cliente_uasg) as clientes_empenhos 
        FROM empenhos 
        WHERE cliente_uasg IS NOT NULL 
        AND cliente_uasg != ''
    ");
    $result = $stmt->fetch();
    $clientesComEmpenhos = $result['clientes_empenhos'] ?? 0;
    
    // Último cadastro
    $stmt = $pdo->query("
        SELECT nome_orgaos, created_at 
        FROM clientes 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $ultimoCadastro = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Erro ao buscar estatísticas de clientes: " . $e->getMessage());
}

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Clientes - LicitaSis", "clientes");
?>

<style>
    /* Reset e variáveis CSS - compatibilidade com o sistema financeiro */
    :root {
        --primary-color: #2D893E;
        --primary-light: #9DCEAC;
        --primary-dark: #1e6e2d;
        --secondary-color: #00bfae;
        --secondary-dark: #009d8f;
        --danger-color: #dc3545;
        --success-color: #28a745;
        --warning-color: #ffc107;
        --info-color: #17a2b8;
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

    /* Container principal - mesmo estilo do financeiro */
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

    /* Título principal - mesmo estilo do financeiro */
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

    /* Grid de estatísticas - atualizado para 5 cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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

    .stat-icon {
        font-size: 2.5rem;
        color: var(--secondary-color);
        margin-bottom: 1rem;
        display: block;
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

    /* Grid de cards principais - mesmo estilo do financeiro */
    .clients-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 2rem;
        margin-top: 2.5rem;
    }

    .client-card {
        background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 2rem;
        text-align: center;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .client-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
    }

    .client-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
        border-color: var(--secondary-color);
    }

    .client-card h3 {
        color: var(--primary-color);
        font-size: 1.3rem;
        margin-bottom: 1rem;
        font-weight: 600;
    }

    .client-card .icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .client-card p {
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

    /* Botões - mesmo estilo do financeiro */
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

    /* Último cliente */
    .last-client-info {
        margin-top: 2rem;
    }

    .last-client-card {
        background: white;
        padding: 1.5rem 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border-left: 4px solid var(--secondary-color);
        display: flex;
        align-items: center;
        gap: 1rem;
        transition: var(--transition);
    }

    .last-client-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    }

    .last-client-card i {
        font-size: 1.5rem;
        color: var(--secondary-color);
        flex-shrink: 0;
    }

    .last-client-content {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        flex: 1;
    }

    .last-client-content strong {
        color: var(--primary-color);
        font-size: 0.95rem;
    }

    .client-name {
        color: var(--dark-gray);
        font-weight: 600;
        font-size: 1.1rem;
    }

    .client-date {
        color: var(--medium-gray);
        font-size: 0.9rem;
    }

    /* Responsividade - atualizada para 5 cards */
    @media (max-width: 1400px) {
        .container {
            margin: 2rem 1.5rem;
            padding: 2rem;
        }

        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }

        .clients-grid {
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

        .clients-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .client-card {
            padding: 1.5rem;
        }

        .client-card .icon {
            font-size: 2.5rem;
        }

        .client-card h3 {
            font-size: 1.2rem;
        }

        .last-client-card {
            flex-direction: column;
            text-align: center;
            gap: 0.75rem;
        }

        .last-client-content {
            align-items: center;
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
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }

        /* Para mobile, empilha o último card se necessário */
        .stat-card:last-child {
            grid-column: 1 / -1;
        }

        .client-card {
            padding: 1.25rem;
        }

        .client-card .icon {
            font-size: 2rem;
        }

        .client-card h3 {
            font-size: 1.1rem;
        }

        .btn {
            padding: 0.75rem 1.25rem;
            font-size: 0.9rem;
        }
    }

    /* Hover effects para mobile */
    @media (hover: none) {
        .btn:active {
            transform: scale(0.98);
        }
        
        .client-card:active {
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

    .client-card {
        animation: fadeInUp 0.6s ease forwards;
    }

    .client-card:nth-child(1) { animation-delay: 0.1s; }
    .client-card:nth-child(2) { animation-delay: 0.2s; }
    .client-card:nth-child(3) { animation-delay: 0.3s; }
    .client-card:nth-child(4) { animation-delay: 0.4s; }

    .stat-card {
        animation: fadeInUp 0.5s ease forwards;
    }

    .stat-card:nth-child(1) { animation-delay: 0.05s; }
    .stat-card:nth-child(2) { animation-delay: 0.1s; }
    .stat-card:nth-child(3) { animation-delay: 0.15s; }
    .stat-card:nth-child(4) { animation-delay: 0.2s; }
    .stat-card:nth-child(5) { animation-delay: 0.25s; }
</style>

<div class="container">
    <h2><i class="fas fa-users"></i> Clientes</h2>

    <!-- Estatísticas - Atualizada com 5 cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <i class="stat-icon fas fa-users"></i>
            <div class="stat-number" id="totalClientes"><?php echo $totalClientes; ?></div>
            <div class="stat-label">Total de Clientes</div>
        </div>
        
        <div class="stat-card">
            <i class="stat-icon fas fa-user-plus"></i>
            <div class="stat-number" id="clientesAtivos"><?php echo $clientesAtivos; ?></div>
            <div class="stat-label">Novos este Mês</div>
        </div>
        
        <!-- NOVO: Card Valor Total de Vendas -->
        <div class="stat-card">
            <i class="stat-icon fas fa-dollar-sign"></i>
            <div class="stat-number" id="valorTotalVendas">
                <?php echo 'R$ ' . number_format($valorTotalVendas, 2, ',', '.'); ?>
            </div>
            <div class="stat-label">Valor Total Vendas</div>
        </div>
        
        <!-- NOVO: Card Clientes com Empenhos -->
        <div class="stat-card">
            <i class="stat-icon fas fa-file-invoice-dollar"></i>
            <div class="stat-number" id="clientesComEmpenhos"><?php echo $clientesComEmpenhos; ?></div>
            <div class="stat-label">Clientes c/ Empenhos</div>
        </div>
        
        <div class="stat-card">
            <i class="stat-icon fas fa-calendar"></i>
            <div class="stat-number">
                <?php echo $ultimoCadastro ? date('d/m/Y', strtotime($ultimoCadastro['created_at'])) : '-'; ?>
            </div>
            <div class="stat-label">Último Cadastro</div>
        </div>
    </div>

    <!-- Cards principais -->
    <div class="clients-grid">
        <!-- Card Cadastrar Cliente -->
        <?php if ($permissionManager->hasPagePermission('clientes', 'create')): ?>
        <div class="client-card">
            <div class="icon">
                <i class="fas fa-user-plus"></i>
            </div>
            <h3>Cadastrar Cliente</h3>
            <p>Adicione novos clientes e órgãos ao sistema com todas as informações necessárias para licitações e contratos.</p>
            <div class="card-buttons">
                <a href="cadastrar_clientes.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Novo Cliente
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Card Consultar Clientes -->
        <div class="client-card">
            <div class="icon">
                <i class="fas fa-search"></i>
            </div>
            <h3>Consultar Clientes</h3>
            <p>Visualize, edite e gerencie todos os clientes cadastrados. Acesse informações detalhadas e histórico completo.</p>
            <div class="card-buttons">
                <a href="consultar_clientes.php" class="btn btn-success">
                    <i class="fas fa-search"></i> Ver Clientes (<?php echo $totalClientes; ?>)
                </a>
            </div>
        </div>

        <!-- Card Relatórios -->
        <div class="client-card">
            <div class="icon">
                <i class="fas fa-chart-bar"></i>
            </div>
            <h3>Relatórios de Clientes</h3>
            <p>Gere relatórios detalhados sobre clientes, vendas e histórico de licitações por período específico.</p>
            <div class="card-buttons">
                <a href="relatorio_clientes.php" class="btn btn-info">
                    <i class="fas fa-file-chart-line"></i> Ver Relatórios
                </a>
            </div>
        </div>

        <!-- Card Dashboard -->
        <div class="client-card">
            <div class="icon">
                <i class="fas fa-tachometer-alt"></i>
            </div>
            <h3>Dashboard Clientes</h3>
            <p>Visualize estatísticas avançadas, métricas e análises dos seus clientes em tempo real com gráficos interativos.</p>
            <div class="card-buttons">
                <a href="dashboard_clientes.php" class="btn btn-warning">
                    <i class="fas fa-chart-line"></i> Ver Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Último cliente cadastrado -->
    <?php if ($ultimoCadastro): ?>
    <div class="last-client-info">
        <div class="last-client-card">
            <i class="fas fa-clock"></i>
            <div class="last-client-content">
                <strong>Último cliente cadastrado:</strong>
                <span class="client-name"><?php echo htmlspecialchars($ultimoCadastro['nome_orgaos']); ?></span>
                <span class="client-date"><?php echo date('d/m/Y \à\s H:i', strtotime($ultimoCadastro['created_at'])); ?></span>
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
    // JavaScript específico da página de clientes
    document.addEventListener('DOMContentLoaded', function() {
        // Anima os números das estatísticas - ATUALIZADA
        function animateNumber(element, finalNumber, isMonetary = false) {
            if (finalNumber === 0) return;
            
            let currentNumber = 0;
            const increment = Math.max(1, Math.ceil(finalNumber / 30));
            const duration = 1000;
            const stepTime = duration / (finalNumber / increment);
            
            element.textContent = isMonetary ? 'R$ 0,00' : '0';
            
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

        // Observer para animar quando os cards ficarem visíveis - ATUALIZADO
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const numberElement = entry.target.querySelector('.stat-number');
                    if (numberElement && !numberElement.dataset.animated) {
                        numberElement.dataset.animated = 'true';
                        const text = numberElement.textContent.trim();
                        
                        // Verifica se é valor monetário
                        if (text.includes('R$')) {
                            const numericValue = parseFloat(text.replace(/[R$\.\s]/g, '').replace(',', '.'));
                            if (!isNaN(numericValue)) {
                                setTimeout(() => animateNumber(numberElement, numericValue, true), 200);
                            }
                        }
                        // Só anima se for um número (não é data)
                        else if (/^\d+$/.test(text)) {
                            const finalNumber = parseInt(text);
                            if (!isNaN(finalNumber)) {
                                setTimeout(() => animateNumber(numberElement, finalNumber, false), 200);
                            }
                        }
                    }
                }
            });
        }, { threshold: 0.5 });

        // Observa todos os cards de estatísticas
        document.querySelectorAll('.stat-card').forEach(card => {
            observer.observe(card);
        });

        // Adiciona efeitos de animação aos cards - mesmo estilo do financeiro
        const cards = document.querySelectorAll('.client-card');
        
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 150);
        });

        // Adiciona efeitos de hover nos botões - mesmo estilo do financeiro
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

        // Tooltip para estatísticas - ATUALIZADO
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach(card => {
            const label = card.querySelector('.stat-label').textContent;
            
            card.addEventListener('mouseenter', function() {
                if (label.includes('Total de Clientes')) {
                    this.title = 'Total de clientes cadastrados no sistema';
                } else if (label.includes('Novos')) {
                    this.title = 'Clientes cadastrados no mês atual';
                } else if (label.includes('Valor Total')) {
                    this.title = 'Soma de todas as vendas dos clientes';
                } else if (label.includes('Empenhos')) {
                    this.title = 'Quantidade de clientes que possuem empenhos';
                } else if (label.includes('Último')) {
                    this.title = 'Data do último cliente cadastrado';
                }
            });
        });

        // Auto-refresh das estatísticas a cada 5 minutos - ATUALIZADO
        setInterval(() => {
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Atualiza estatísticas
                    const newTotal = doc.getElementById('totalClientes')?.textContent;
                    const newAtivos = doc.getElementById('clientesAtivos')?.textContent;
                    const newVendas = doc.getElementById('valorTotalVendas')?.textContent;
                    const newEmpenhos = doc.getElementById('clientesComEmpenhos')?.textContent;
                    
                    if (newTotal && newTotal !== document.getElementById('totalClientes').textContent) {
                        document.getElementById('totalClientes').textContent = newTotal;
                        document.querySelector('.btn-success').innerHTML = 
                            '<i class="fas fa-search"></i> Ver Clientes (' + newTotal + ')';
                    }
                    
                    if (newAtivos && newAtivos !== document.getElementById('clientesAtivos').textContent) {
                        document.getElementById('clientesAtivos').textContent = newAtivos;
                    }
                    
                    if (newVendas && newVendas !== document.getElementById('valorTotalVendas').textContent) {
                        document.getElementById('valorTotalVendas').textContent = newVendas;
                    }
                    
                    if (newEmpenhos && newEmpenhos !== document.getElementById('clientesComEmpenhos').textContent) {
                        document.getElementById('clientesComEmpenhos').textContent = newEmpenhos;
                    }
                })
                .catch(err => console.log('Erro ao atualizar estatísticas:', err));
        }, 300000); // 5 minutos

        // Registra analytics da página - ATUALIZADO
        console.log('Módulo de Clientes carregado com sucesso!');
        console.log('Total de clientes:', <?php echo $totalClientes; ?>);
        console.log('Clientes novos este mês:', <?php echo $clientesAtivos; ?>);
        console.log('Valor total vendas: R$', <?php echo number_format($valorTotalVendas, 2, '.', ''); ?>);
        console.log('Clientes com empenhos:', <?php echo $clientesComEmpenhos; ?>);
        console.log('Usuário:', '<?php echo addslashes($_SESSION['user']['name']); ?>');
        console.log('Permissão:', '<?php echo addslashes($_SESSION['user']['permission']); ?>');
    });
</script>

</body>
</html>