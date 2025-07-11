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

// Verifica se o usuário tem permissão para acessar produtos
$permissionManager->requirePermission('produtos', 'view');

// Registra acesso à página
logUserAction('READ', 'produtos_dashboard');

// Busca estatísticas de produtos com cálculo CORRETO de estoque
$totalProdutos = 0;
$produtosAtivos = 0;
$totalFornecedores = 0;
$valorTotalEstoque = 0;
$quantidadeTotalEstoque = 0;
$produtosEstoqueBaixo = 0;
$produtosSemEstoque = 0;
$ultimoCadastro = null;

try {
    // Total de produtos
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM produtos");
    $result = $stmt->fetch();
    $totalProdutos = $result['total'] ?? 0;
    
    // Produtos cadastrados este mês
    $stmt = $pdo->query("
        SELECT COUNT(*) as ativos 
        FROM produtos 
        WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
        AND YEAR(created_at) = YEAR(CURRENT_DATE())
    ");
    $result = $stmt->fetch();
    $produtosAtivos = $result['ativos'] ?? 0;
    
    // Total de fornecedores ativos
    $stmt = $pdo->query("SELECT COUNT(DISTINCT fornecedor) as total FROM produtos WHERE fornecedor IS NOT NULL AND fornecedor != ''");
    $result = $stmt->fetch();
    $totalFornecedores = $result['total'] ?? 0;
    
    // BUSCA DADOS DE ESTOQUE USANDO A VIEW CRIADA
    $stmt = $pdo->query("
        SELECT 
            id,
            nome,
            codigo,
            preco_unitario,
            estoque_atual,
            estoque_minimo,
            valor_estoque,
            status_estoque,
            controla_estoque
        FROM view_estoque_produtos 
        WHERE controla_estoque = TRUE
    ");
    
    $produtos_estoque = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcula estatísticas baseadas no estoque real
    foreach ($produtos_estoque as $produto) {
        $estoque_atual = floatval($produto['estoque_atual']);
        $valor_estoque = floatval($produto['valor_estoque']);
        $estoque_minimo = floatval($produto['estoque_minimo']);
        $status_estoque = $produto['status_estoque'];
        
        // Soma quantidade total em estoque
        $quantidadeTotalEstoque += $estoque_atual;
        
        // Soma valor total do estoque
        $valorTotalEstoque += $valor_estoque;
        
        // Conta produtos com problemas de estoque
        switch ($status_estoque) {
            case 'SEM_ESTOQUE':
                $produtosSemEstoque++;
                break;
            case 'ESTOQUE_BAIXO':
                $produtosEstoqueBaixo++;
                break;
        }
    }
    
    // Último cadastro
    $stmt = $pdo->query("
        SELECT nome, created_at 
        FROM produtos 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $ultimoCadastro = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Erro ao buscar estatísticas de produtos: " . $e->getMessage());
    // Em caso de erro, inicializa variáveis
    $valorTotalEstoque = 0;
    $quantidadeTotalEstoque = 0;
    $produtosEstoqueBaixo = 0;
    $produtosSemEstoque = 0;
}

// Busca produtos que precisam de atenção
try {
    $produtosAtencao = [];
    $stmt = $pdo->query("
        SELECT 
            id,
            codigo,
            nome,
            estoque_atual,
            estoque_minimo,
            status_estoque
        FROM view_estoque_produtos 
        WHERE status_estoque IN ('SEM_ESTOQUE', 'ESTOQUE_BAIXO')
        AND controla_estoque = TRUE
        ORDER BY 
            CASE status_estoque 
                WHEN 'SEM_ESTOQUE' THEN 1 
                WHEN 'ESTOQUE_BAIXO' THEN 2 
                ELSE 3 
            END,
            estoque_atual ASC
        LIMIT 10
    ");
    $produtosAtencao = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Erro ao buscar produtos que precisam de atenção: " . $e->getMessage());
    $produtosAtencao = [];
}

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Produtos - LicitaSis", "produtos");
?>

<style>
    /* Variáveis CSS - mesmo padrão */
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

    /* Grid de estatísticas ATUALIZADO */
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
        box-shadow: var(--shadow-hover);
    }

    /* Cores específicas para cada tipo de estatística */
    .stat-card.stat-produtos { border-left-color: var(--primary-color); }
    .stat-card.stat-novos { border-left-color: var(--success-color); background: linear-gradient(135deg, #f0fff4 0%, #c6f6d5 100%); }
    .stat-card.stat-fornecedores { border-left-color: var(--info-color); background: linear-gradient(135deg, #e6f7ff 0%, #bae7ff 100%); }
    .stat-card.stat-estoque-valor { border-left-color: #ffd700; background: linear-gradient(135deg, #fffbf0 0%, #fef5e7 100%); }
    .stat-card.stat-estoque-qtd { border-left-color: var(--secondary-color); background: linear-gradient(135deg, #e6fffa 0%, #b2f5ea 100%); }
    .stat-card.stat-baixo { border-left-color: var(--danger-color); background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%); }
    .stat-card.stat-sem-estoque { border-left-color: #dc3545; background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%); animation: pulse-danger 3s infinite; }

    @keyframes pulse-danger {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.02); }
    }

    .stat-icon {
        font-size: 2.5rem;
        color: var(--secondary-color);
        margin-bottom: 1rem;
        display: block;
    }

    .stat-card.stat-produtos .stat-icon { color: var(--primary-color); }
    .stat-card.stat-novos .stat-icon { color: var(--success-color); }
    .stat-card.stat-fornecedores .stat-icon { color: var(--info-color); }
    .stat-card.stat-estoque-valor .stat-icon { color: #ffd700; }
    .stat-card.stat-estoque-qtd .stat-icon { color: var(--secondary-color); }
    .stat-card.stat-baixo .stat-icon { color: var(--danger-color); }
    .stat-card.stat-sem-estoque .stat-icon { color: var(--danger-color); }

    .stat-number {
        font-size: 2.2rem;
        font-weight: 800;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        line-height: 1;
        font-family: 'Courier New', monospace;
    }

    .stat-card.stat-produtos .stat-number { color: var(--primary-color); }
    .stat-card.stat-novos .stat-number { color: var(--success-color); }
    .stat-card.stat-fornecedores .stat-number { color: var(--info-color); }
    .stat-card.stat-estoque-valor .stat-number { color: #b8860b; }
    .stat-card.stat-estoque-qtd .stat-number { color: var(--secondary-dark); }
    .stat-card.stat-baixo .stat-number { color: var(--danger-color); }
    .stat-card.stat-sem-estoque .stat-number { color: var(--danger-color); }

    .stat-label {
        color: var(--medium-gray);
        font-size: 0.9rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-sublabel {
        font-size: 0.75rem;
        color: var(--medium-gray);
        margin-top: 0.5rem;
        opacity: 0.8;
    }

    /* Alerta de estoque */
    .estoque-alert {
        background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
        border: 1px solid var(--danger-color);
        border-radius: var(--radius);
        padding: 1.5rem;
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        animation: pulse-alert 2s infinite;
    }

    @keyframes pulse-alert {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.9; }
    }

    .estoque-alert i {
        font-size: 2rem;
        color: var(--danger-color);
    }

    .estoque-alert .alert-content {
        flex: 1;
    }

    .estoque-alert .alert-title {
        color: var(--danger-color);
        font-weight: 600;
        margin-bottom: 0.5rem;
        font-size: 1.1rem;
    }

    .estoque-alert .alert-text {
        color: var(--dark-gray);
        font-size: 0.95rem;
        line-height: 1.5;
    }

    /* Lista de produtos com problemas de estoque */
    .produtos-atencao {
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        margin-bottom: 2rem;
        overflow: hidden;
    }

    .produtos-atencao-header {
        background: linear-gradient(135deg, var(--danger-color), #c82333);
        color: white;
        padding: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .produtos-atencao-header h3 {
        margin: 0;
        font-size: 1.3rem;
        font-weight: 600;
        color: white;
    }

    .produtos-atencao-lista {
        max-height: 300px;
        overflow-y: auto;
    }

    .produto-atencao-item {
        display: flex;
        align-items: center;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border-color);
        transition: var(--transition);
    }

    .produto-atencao-item:hover {
        background: var(--light-gray);
    }

    .produto-atencao-item:last-child {
        border-bottom: none;
    }

    .produto-info {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .produto-codigo {
        font-weight: 600;
        color: var(--primary-color);
        font-family: 'Courier New', monospace;
    }

    .produto-nome {
        color: var(--dark-gray);
        font-size: 0.95rem;
    }

    .produto-estoque {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-left: 1rem;
    }

    .estoque-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .estoque-badge.sem-estoque {
        background: var(--danger-color);
        color: white;
    }

    .estoque-badge.estoque-baixo {
        background: var(--warning-color);
        color: var(--dark-gray);
    }

    .estoque-numeros {
        font-family: 'Courier New', monospace;
        font-size: 0.9rem;
        color: var(--medium-gray);
    }

    /* Grid de cards principais */
    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 2rem;
        margin-top: 2.5rem;
    }

    .product-card {
        background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 2rem;
        text-align: center;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .product-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
    }

    .product-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
        border-color: var(--secondary-color);
    }

    .product-card h3 {
        color: var(--primary-color);
        font-size: 1.3rem;
        margin-bottom: 1rem;
        font-weight: 600;
    }

    .product-card .icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .product-card p {
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

    /* Último produto */
    .last-product-info {
        margin-top: 2rem;
    }

    .last-product-card {
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

    .last-product-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-hover);
    }

    .last-product-card i {
        font-size: 1.5rem;
        color: var(--secondary-color);
        flex-shrink: 0;
    }

    .last-product-content {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        flex: 1;
    }

    .last-product-content strong {
        color: var(--primary-color);
        font-size: 0.95rem;
    }

    .product-name {
        color: var(--dark-gray);
        font-weight: 600;
        font-size: 1.1rem;
    }

    .product-date {
        color: var(--medium-gray);
        font-size: 0.9rem;
    }

    /* Responsividade */
    @media (max-width: 1200px) {
        .container {
            margin: 2rem;
            padding: 2rem;
        }
    }

    @media (max-width: 768px) {
        .container {
            margin: 1.5rem;
            padding: 1.5rem;
        }

        h2 {
            font-size: 1.8rem;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .products-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .product-card {
            padding: 1.5rem;
        }

        .last-product-card {
            flex-direction: column;
            text-align: center;
            gap: 0.75rem;
        }

        .estoque-alert {
            flex-direction: column;
            text-align: center;
        }

        .produto-atencao-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .produto-estoque {
            margin-left: 0;
        }
    }

    @media (max-width: 480px) {
        .container {
            padding: 1.25rem;
            margin: 1rem;
        }

        h2 {
            font-size: 1.5rem;
        }

        .stats-grid {
            grid-template-columns: 1fr;
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
    }

    /* Animações */
    .stat-card {
        animation: fadeInUp 0.5s ease forwards;
        opacity: 0;
    }

    .stat-card:nth-child(1) { animation-delay: 0.05s; }
    .stat-card:nth-child(2) { animation-delay: 0.1s; }
    .stat-card:nth-child(3) { animation-delay: 0.15s; }
    .stat-card:nth-child(4) { animation-delay: 0.2s; }
    .stat-card:nth-child(5) { animation-delay: 0.25s; }
    .stat-card:nth-child(6) { animation-delay: 0.3s; }
    .stat-card:nth-child(7) { animation-delay: 0.35s; }

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
</style>

<div class="container">
    <h2><i class="fas fa-box"></i> Gestão de Produtos</h2>

    <!-- Alertas de estoque crítico -->
    <?php if ($produtosSemEstoque > 0 || $produtosEstoqueBaixo > 0): ?>
        <div class="estoque-alert">
            <i class="fas fa-exclamation-triangle"></i>
            <div class="alert-content">
                <div class="alert-title">Atenção: Problemas de Estoque Detectados!</div>
                <div class="alert-text">
                    <?php if ($produtosSemEstoque > 0): ?>
                        <strong><?php echo $produtosSemEstoque; ?> produto(s) sem estoque</strong>
                        <?php if ($produtosEstoqueBaixo > 0): ?>
                            e <strong><?php echo $produtosEstoqueBaixo; ?> produto(s) com estoque baixo</strong>
                        <?php endif; ?>
                    <?php else: ?>
                        <strong><?php echo $produtosEstoqueBaixo; ?> produto(s) com estoque baixo</strong>
                    <?php endif; ?>
                    necessitam de atenção imediata.
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Estatísticas ATUALIZADAS com controle de estoque -->
    <div class="stats-grid">
        <div class="stat-card stat-produtos">
            <i class="stat-icon fas fa-boxes"></i>
            <div class="stat-number" id="totalProdutos"><?php echo $totalProdutos; ?></div>
            <div class="stat-label">Total de Produtos</div>
            <div class="stat-sublabel">Cadastrados no sistema</div>
        </div>
        
        <div class="stat-card stat-novos">
            <i class="stat-icon fas fa-plus-circle"></i>
            <div class="stat-number" id="produtosAtivos"><?php echo $produtosAtivos; ?></div>
            <div class="stat-label">Novos este Mês</div>
            <div class="stat-sublabel">Produtos cadastrados</div>
        </div>
        
        <div class="stat-card stat-fornecedores">
            <i class="stat-icon fas fa-truck"></i>
            <div class="stat-number" id="totalFornecedores"><?php echo $totalFornecedores; ?></div>
            <div class="stat-label">Fornecedores</div>
            <div class="stat-sublabel">Parceiros cadastrados</div>
        </div>
        
        <div class="stat-card stat-estoque-qtd">
            <i class="stat-icon fas fa-warehouse"></i>
            <div class="stat-number" id="quantidadeEstoque"><?php echo number_format($quantidadeTotalEstoque, 0, ',', '.'); ?></div>
            <div class="stat-label">Total em Estoque</div>
            <div class="stat-sublabel">Quantidade atual real</div>
        </div>
        
        <div class="stat-card stat-estoque-valor">
            <i class="stat-icon fas fa-dollar-sign"></i>
            <div class="stat-number" id="valorEstoque">
                R$ <?php echo number_format($valorTotalEstoque, 2, ',', '.'); ?>
            </div>
            <div class="stat-label">Valor em Estoque</div>
            <div class="stat-sublabel">Valor total calculado</div>
        </div>
        
        <div class="stat-card stat-baixo <?php echo $produtosEstoqueBaixo > 0 ? 'pulsing' : ''; ?>">
            <i class="stat-icon fas fa-exclamation-triangle"></i>
            <div class="stat-number" id="estoqueBaixo"><?php echo $produtosEstoqueBaixo; ?></div>
            <div class="stat-label">Estoque Baixo</div>
            <div class="stat-sublabel">Abaixo do mínimo</div>
        </div>
        
        <div class="stat-card stat-sem-estoque <?php echo $produtosSemEstoque > 0 ? 'pulsing' : ''; ?>">
            <i class="stat-icon fas fa-ban"></i>
            <div class="stat-number" id="semEstoque"><?php echo $produtosSemEstoque; ?></div>
            <div class="stat-label">Sem Estoque</div>
            <div class="stat-sublabel">Produtos zerados</div>
        </div>
    </div>

    <!-- Lista de produtos que precisam de atenção -->
    <?php if (!empty($produtosAtencao)): ?>
        <div class="produtos-atencao">
            <div class="produtos-atencao-header">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Produtos que Precisam de Atenção</h3>
            </div>
            <div class="produtos-atencao-lista">
                <?php foreach ($produtosAtencao as $produto): ?>
                    <div class="produto-atencao-item">
                        <div class="produto-info">
                            <div class="produto-codigo"><?php echo htmlspecialchars($produto['codigo']); ?></div>
                            <div class="produto-nome"><?php echo htmlspecialchars($produto['nome']); ?></div>
                        </div>
                        <div class="produto-estoque">
                            <span class="estoque-badge <?php echo $produto['status_estoque'] == 'SEM_ESTOQUE' ? 'sem-estoque' : 'estoque-baixo'; ?>">
                                <?php echo $produto['status_estoque'] == 'SEM_ESTOQUE' ? 'SEM ESTOQUE' : 'ESTOQUE BAIXO'; ?>
                            </span>
                            <div class="estoque-numeros">
                                Atual: <?php echo number_format($produto['estoque_atual'], 2, ',', '.'); ?> |
                                Mín: <?php echo number_format($produto['estoque_minimo'], 2, ',', '.'); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Cards principais de funcionalidades -->
    <div class="products-grid">
        <!-- Card Cadastrar Produto -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-plus-circle"></i>
            </div>
            <h3>Cadastrar Produto</h3>
            <p>Adicione novos produtos ao sistema com controle completo de estoque, preços e impostos para licitações.</p>
            <div class="card-buttons">
                <a href="cadastro_produto.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Novo Produto
                </a>
            </div>
        </div>

        <!-- Card Consultar Produtos -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-search"></i>
            </div>
            <h3>Consultar Produtos</h3>
            <p>Visualize, edite e gerencie todos os produtos cadastrados. Acesse informações detalhadas, histórico e situação de estoque.</p>
            <div class="card-buttons">
                <a href="consulta_produto.php" class="btn btn-success">
                    <i class="fas fa-search"></i> Ver Produtos (<?php echo $totalProdutos; ?>)
                </a>
            </div>
        </div>

        <!-- Card Controle de Estoque -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-warehouse"></i>
            </div>
            <h3>Controle de Estoque</h3>
            <p>Gerencie movimentações de estoque com controle automático de entradas e saídas baseado em vendas e compras.</p>
            <div class="card-buttons">
                <a href="estoque_produtos.php" class="btn btn-info">
                    <i class="fas fa-clipboard-list"></i> Gerenciar Estoque
                </a>
                <?php if ($produtosEstoqueBaixo > 0 || $produtosSemEstoque > 0): ?>
                    <a href="produtos_estoque_critico.php" class="btn btn-danger">
                        <i class="fas fa-exclamation-triangle"></i> 
                        Ver Críticos (<?php echo ($produtosEstoqueBaixo + $produtosSemEstoque); ?>)
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card Movimentações -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-exchange-alt"></i>
            </div>
            <h3>Movimentações</h3>
            <p>Acompanhe todas as entradas e saídas de produtos com histórico completo e auditoria de movimentações de estoque.</p>
            <div class="card-buttons">
                <a href="movimentacoes_estoque.php" class="btn btn-info">
                    <i class="fas fa-history"></i> Ver Movimentações
                </a>
            </div>
        </div>

        <!-- Card Relatórios -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-chart-bar"></i>
            </div>
            <h3>Relatórios</h3>
            <p>Gere relatórios detalhados de estoque, movimentações, vendas e análises de desempenho por período.</p>
            <div class="card-buttons">
                <a href="relatorio_produtos.php" class="btn btn-warning">
                    <i class="fas fa-file-chart-line"></i> Ver Relatórios
                </a>
            </div>
        </div>

        <!-- Card Categorias -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-tags"></i>
            </div>
            <h3>Categorias</h3>
            <p>Organize seus produtos em categorias para facilitar a gestão, relatórios e controle de estoque segmentado.</p>
            <div class="card-buttons">
                <a href="categorias_produtos.php" class="btn btn-success">
                    <i class="fas fa-folder-open"></i> Gerenciar Categorias
                </a>
            </div>
        </div>
    </div>

    <!-- Último produto cadastrado -->
    <?php if ($ultimoCadastro): ?>
        <div class="last-product-info">
            <div class="last-product-card">
                <i class="fas fa-clock"></i>
                <div class="last-product-content">
                    <strong>Último produto cadastrado:</strong>
                    <span class="product-name"><?php echo htmlspecialchars($ultimoCadastro['nome']); ?></span>
                    <span class="product-date"><?php echo date('d/m/Y \à\s H:i', strtotime($ultimoCadastro['created_at'])); ?></span>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// JavaScript para funcionalidades de estoque
document.addEventListener('DOMContentLoaded', function() {
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

    // Observer para animar quando os cards ficarem visíveis
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const numberElement = entry.target.querySelector('.stat-number');
                if (numberElement && !numberElement.dataset.animated) {
                    numberElement.dataset.animated = 'true';
                    const text = numberElement.textContent.trim();
                    
                    let finalNumber = 0;
                    if (text.includes('R)) {
                        finalNumber = parseFloat(text.replace(/[R$\s.]/g, '').replace(',', '.'));
                    } else if (/^\d+[\d.,]*$/.test(text.replace(/\s/g, ''))) {
                        finalNumber = parseInt(text.replace(/[.,\s]/g, ''));
                    }
                    
                    if (!isNaN(finalNumber) && finalNumber > 0) {
                        setTimeout(() => animateNumber(numberElement, finalNumber), 200);
                    }
                }
            }
        });
    }, { threshold: 0.5 });

    // Observa todos os cards de estatísticas
    document.querySelectorAll('.stat-card').forEach(card => {
        observer.observe(card);
    });

    // Sistema de notificações para alertas de estoque
    function checkAndShowStockAlerts() {
        const semEstoque = <?php echo $produtosSemEstoque; ?>;
        const estoqueBaixo = <?php echo $produtosEstoqueBaixo; ?>;
        
        if (semEstoque > 0) {
            setTimeout(() => {
                showNotification(
                    `URGENTE! ${semEstoque} produto(s) sem estoque. Ação imediata necessária!`,
                    'danger',
                    () => window.location.href = 'produtos_estoque_critico.php'
                );
            }, 2000);
        } else if (estoqueBaixo > 0) {
            setTimeout(() => {
                showNotification(
                    `Atenção! ${estoqueBaixo} produto(s) com estoque baixo. Clique para verificar.`,
                    'warning',
                    () => window.location.href = 'produtos_estoque_critico.php'
                );
            }, 3000);
        }
    }

    // Verifica alertas de estoque ao carregar a página
    checkAndShowStockAlerts();

    // Função para mostrar notificações melhorada
    function showNotification(message, type = 'info', clickAction = null) {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        
        const iconMap = {
            'danger': 'exclamation-triangle',
            'warning': 'exclamation-triangle',
            'success': 'check-circle',
            'info': 'info-circle'
        };
        
        notification.innerHTML = `
            <i class="fas fa-${iconMap[type] || 'info-circle'}"></i>
            <span>${message}</span>
            <button onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        const colors = {
            'danger': 'var(--danger-color)',
            'warning': 'var(--warning-color)',
            'success': 'var(--success-color)',
            'info': 'var(--info-color)'
        };
        
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${colors[type] || colors.info};
            color: ${type === 'warning' ? '#333' : 'white'};
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            z-index: 1000;
            animation: slideInRight 0.3s ease;
            max-width: 400px;
            cursor: ${clickAction ? 'pointer' : 'default'};
            border-left: 4px solid ${type === 'danger' ? '#b91c1c' : type === 'warning' ? '#d97706' : '#059669'};
        `;
        
        if (clickAction) {
            notification.addEventListener('click', function(e) {
                if (e.target.tagName !== 'BUTTON' && !e.target.closest('button')) {
                    clickAction();
                }
            });
        }
        
        document.body.appendChild(notification);
        
        // Remove após 8 segundos
        setTimeout(() => {
            notification.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => notification.remove(), 300);
        }, 8000);
    }

    // Auto-refresh das estatísticas a cada 2 minutos
    setInterval(() => {
        fetch(window.location.href)
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                // Atualiza estatísticas
                const elementos = [
                    'totalProdutos', 
                    'produtosAtivos', 
                    'totalFornecedores',
                    'quantidadeEstoque',
                    'valorEstoque',
                    'estoqueBaixo',
                    'semEstoque'
                ];
                
                elementos.forEach(id => {
                    const novoValor = doc.getElementById(id)?.textContent;
                    const elementoAtual = document.getElementById(id);
                    
                    if (novoValor && elementoAtual && novoValor !== elementoAtual.textContent) {
                        elementoAtual.textContent = novoValor;
                        
                        // Animação de mudança
                        elementoAtual.style.background = '#90EE90';
                        setTimeout(() => {
                            elementoAtual.style.background = '';
                        }, 1000);
                    }
                });
            })
            .catch(err => console.log('Erro ao atualizar estatísticas:', err));
    }, 120000); // 2 minutos

    // Atalhos de teclado para navegação rápida
    document.addEventListener('keydown', function(e) {
        // Ctrl + N para novo produto
        if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
            e.preventDefault();
            window.location.href = 'cadastro_produto.php';
        }
        
        // Ctrl + K para busca de produtos
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            window.location.href = 'consulta_produto.php';
        }
        
        // Ctrl + E para estoque
        if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
            e.preventDefault();
            window.location.href = 'estoque_produtos.php';
        }
        
        // Ctrl + M para movimentações
        if ((e.ctrlKey || e.metaKey) && e.key === 'm') {
            e.preventDefault();
            window.location.href = 'movimentacoes_estoque.php';
        }
    });

    // Sistema de monitoramento de estoque em tempo real
    function monitorarEstoque() {
        const produtosCriticos = document.querySelectorAll('.produto-atencao-item');
        
        produtosCriticos.forEach((item, index) => {
            item.style.animation = `fadeInUp 0.4s ease ${index * 0.1}s both`;
        });
        
        // Verifica se há produtos sem estoque
        const semEstoque = <?php echo $produtosSemEstoque; ?>;
        const estoqueBaixo = <?php echo $produtosEstoqueBaixo; ?>;
        
        if (semEstoque > 0) {
            // Adiciona classe especial aos cards críticos
            document.querySelectorAll('.stat-sem-estoque').forEach(card => {
                card.classList.add('critical-alert');
            });
            
            // Mostra indicador visual no título
            document.title = `(${semEstoque}) SEM ESTOQUE - Produtos - LicitaSis`;
        } else if (estoqueBaixo > 0) {
            document.title = `(${estoqueBaixo}) BAIXO - Produtos - LicitaSis`;
        }
    }

    // Inicializa monitoramento
    monitorarEstoque();

    // Função para destacar produtos críticos
    function destacarProdutosCriticos() {
        const produtosCriticos = document.querySelectorAll('.produto-atencao-item');
        
        produtosCriticos.forEach(item => {
            const badge = item.querySelector('.estoque-badge');
            
            if (badge.classList.contains('sem-estoque')) {
                item.style.borderLeft = '4px solid var(--danger-color)';
                item.style.background = 'linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%)';
            } else if (badge.classList.contains('estoque-baixo')) {
                item.style.borderLeft = '4px solid var(--warning-color)';
                item.style.background = 'linear-gradient(135deg, #fffbf0 0%, #fef5e7 100%)';
            }
        });
    }

    destacarProdutosCriticos();

    // Registra analytics da página
    console.log('=== DASHBOARD DE PRODUTOS COM ESTOQUE ===');
    console.log('Total de produtos:', <?php echo $totalProdutos; ?>);
    console.log('Produtos novos este mês:', <?php echo $produtosAtivos; ?>);
    console.log('Total de fornecedores:', <?php echo $totalFornecedores; ?>);
    console.log('Quantidade total em estoque:', <?php echo $quantidadeTotalEstoque; ?>);
    console.log('Valor total em estoque: R, <?php echo number_format($valorTotalEstoque, 2, ',', '.'); ?>);
    console.log('Produtos com estoque baixo:', <?php echo $produtosEstoqueBaixo; ?>);
    console.log('Produtos sem estoque:', <?php echo $produtosSemEstoque; ?>);
    console.log('Produtos que precisam atenção:', <?php echo count($produtosAtencao); ?>);
    console.log('==========================================');

    // Funcionalidade de busca rápida (Ctrl+F)
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            
            // Cria modal de busca rápida
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.6);
                z-index: 2000;
                display: flex;
                align-items: center;
                justify-content: center;
            `;
            
            modal.innerHTML = `
                <div style="background: white; padding: 2rem; border-radius: 12px; width: 90%; max-width: 500px;">
                    <h3 style="margin-bottom: 1rem; color: var(--primary-color);">
                        <i class="fas fa-search"></i> Busca Rápida de Produtos
                    </h3>
                    <input type="text" id="quickSearch" placeholder="Digite o código ou nome do produto..." 
                           style="width: 100%; padding: 1rem; border: 2px solid var(--border-color); border-radius: 8px; font-size: 1rem;">
                    <div style="margin-top: 1rem; display: flex; gap: 1rem; justify-content: flex-end;">
                        <button onclick="this.closest('.modal').remove()" 
                                style="padding: 0.75rem 1.5rem; background: var(--medium-gray); color: white; border: none; border-radius: 8px; cursor: pointer;">
                            Cancelar
                        </button>
                        <button onclick="buscarProduto()" 
                                style="padding: 0.75rem 1.5rem; background: var(--primary-color); color: white; border: none; border-radius: 8px; cursor: pointer;">
                            Buscar
                        </button>
                    </div>
                </div>
            `;
            
            modal.className = 'modal';
            document.body.appendChild(modal);
            
            // Foca no campo de busca
            setTimeout(() => {
                document.getElementById('quickSearch').focus();
            }, 100);
            
            // Busca ao pressionar Enter
            document.getElementById('quickSearch').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    buscarProduto();
                }
            });
            
            // Fecha modal ao clicar fora
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }
    });

    // Função de busca rápida
    window.buscarProduto = function() {
        const termo = document.getElementById('quickSearch').value.trim();
        if (termo) {
            window.location.href = `consulta_produto.php?search=${encodeURIComponent(termo)}`;
        }
    };

    // Sistema de backup automático de estatísticas
    const estatisticas = {
        timestamp: new Date().toISOString(),
        totalProdutos: <?php echo $totalProdutos; ?>,
        quantidadeEstoque: <?php echo $quantidadeTotalEstoque; ?>,
        valorEstoque: <?php echo $valorTotalEstoque; ?>,
        produtosEstoqueBaixo: <?php echo $produtosEstoqueBaixo; ?>,
        produtosSemEstoque: <?php echo $produtosSemEstoque; ?>
    };
    
    // Salva no localStorage para comparação futura
    localStorage.setItem('estatisticasEstoque', JSON.stringify(estatisticas));
    
    // Compara com dados anteriores
    const estatisticasAnteriores = localStorage.getItem('estatisticasEstoqueAnterior');
    if (estatisticasAnteriores) {
        const anterior = JSON.parse(estatisticasAnteriores);
        
        // Verifica mudanças significativas
        if (estatisticas.produtosSemEstoque > anterior.produtosSemEstoque) {
            showNotification(
                `Alerta: ${estatisticas.produtosSemEstoque - anterior.produtosSemEstoque} produto(s) adicional(is) ficaram sem estoque!`,
                'danger'
            );
        }
        
        if (estatisticas.produtosEstoqueBaixo > anterior.produtosEstoqueBaixo) {
            showNotification(
                `Atenção: ${estatisticas.produtosEstoqueBaixo - anterior.produtosEstoqueBaixo} produto(s) adicional(is) com estoque baixo!`,
                'warning'
            );
        }
    }
    
    // Salva estatísticas atuais como anteriores para próxima verificação
    localStorage.setItem('estatisticasEstoqueAnterior', JSON.stringify(estatisticas));
});

// CSS adicional para animações e melhorias visuais
const additionalCSS = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }

    .notification {
        transition: all 0.3s ease;
    }

    .notification button {
        background: none;
        border: none;
        color: inherit;
        cursor: pointer;
        padding: 0.25rem;
        margin-left: 1rem;
        transition: opacity 0.2s;
        border-radius: 4px;
    }

    .notification button:hover {
        opacity: 0.7;
        background: rgba(255,255,255,0.2);
    }

    .critical-alert {
        animation: pulse-critical 1.5s infinite;
    }

    @keyframes pulse-critical {
        0%, 100% { 
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }
        50% { 
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.6);
            transform: translateY(-3px);
        }
    }

    /* Melhorias de acessibilidade */
    .btn:focus,
    .product-card:focus-within,
    .stat-card:focus-within {
        outline: 3px solid var(--secondary-color);
        outline-offset: 2px;
    }

    /* Modo de alto contraste */
    @media (prefers-contrast: high) {
        .stat-card,
        .product-card,
        .produtos-atencao {
            border: 2px solid var(--dark-gray);
        }
    }

    /* Animações reduzidas para quem prefere */
    @media (prefers-reduced-motion: reduce) {
        *,
        *::before,
        *::after {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
        }
    }

    .modal {
        animation: fadeIn 0.3s ease;
    }

    .modal > div {
        animation: slideInUp 0.3s ease;
    }

    @keyframes slideInUp {
        from {
            transform: translateY(50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
`;

// Adicionar CSS ao documento
const style = document.createElement('style');
style.textContent = additionalCSS;
document.head.appendChild(style);
</script>

<?php
// Finaliza a página
if (function_exists('renderFooter')) {
    renderFooter();
}
if (function_exists('renderScripts')) {
    renderScripts();
}
?>

</body>
</html>