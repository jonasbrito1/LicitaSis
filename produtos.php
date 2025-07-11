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

// Busca estatísticas de produtos com cálculo correto de estoque
$totalProdutos = 0;
$produtosAtivos = 0;
$totalFornecedores = 0;
$valorTotalEstoque = 0;
$quantidadeTotalEstoque = 0;
$produtosEstoqueBaixo = 0;
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
    
    // CÁLCULO CORRETO DO ESTOQUE USANDO A FÓRMULA: Estoque = Inicial + Entradas - Saídas
    $stmt = $pdo->query("
        SELECT 
            p.id,
            p.nome,
            p.preco_unitario,
            COALESCE(p.estoque_inicial, 0) as estoque_inicial,
            p.estoque_minimo,
            COALESCE(entradas.total_entradas, 0) as total_entradas,
            COALESCE(saidas.total_saidas, 0) as total_saidas,
            (COALESCE(p.estoque_inicial, 0) + COALESCE(entradas.total_entradas, 0) - COALESCE(saidas.total_saidas, 0)) as estoque_atual
        FROM produtos p
        LEFT JOIN (
            SELECT 
                produto_id, 
                SUM(quantidade) as total_entradas
            FROM produto_compra 
            GROUP BY produto_id
        ) entradas ON p.id = entradas.produto_id
        LEFT JOIN (
            SELECT 
                produto_id, 
                SUM(quantidade) as total_saidas
            FROM venda_produtos 
            GROUP BY produto_id
        ) saidas ON p.id = saidas.produto_id
    ");
    
    $produtos_estoque = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcula estatísticas baseadas no estoque real
    foreach ($produtos_estoque as $produto) {
        $estoque_atual = floatval($produto['estoque_atual']);
        $preco_unitario = floatval($produto['preco_unitario']);
        $estoque_minimo = floatval($produto['estoque_minimo']);
        
        // Soma quantidade total em estoque
        $quantidadeTotalEstoque += $estoque_atual;
        
        // Soma valor total do estoque (quantidade * preço unitário)
        $valorTotalEstoque += ($estoque_atual * $preco_unitario);
        
        // Conta produtos com estoque baixo (abaixo do mínimo)
        if ($estoque_minimo > 0 && $estoque_atual <= $estoque_minimo) {
            $produtosEstoqueBaixo++;
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
}

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Produtos - LicitaSis", "produtos");
?>

<style>
    /* Reset e variáveis CSS - compatibilidade com o sistema */
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

    /* Container principal - mesmo estilo do financeiro e clientes */
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

    /* Título principal - mesmo estilo do financeiro e clientes */
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

    /* Grid de estatísticas - ATUALIZADO COM NOVAS ESTATÍSTICAS */
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

    /* Cores específicas para cada tipo de estatística */
    .stat-card.stat-produtos {
        border-left-color: var(--primary-color);
    }

    .stat-card.stat-novos {
        border-left-color: var(--success-color);
        background: linear-gradient(135deg, #f0fff4 0%, #c6f6d5 100%);
    }

    .stat-card.stat-fornecedores {
        border-left-color: var(--info-color);
        background: linear-gradient(135deg, #e6f7ff 0%, #bae7ff 100%);
    }

    .stat-card.stat-estoque-valor {
        border-left-color: #ffd700;
        background: linear-gradient(135deg, #fffbf0 0%, #fef5e7 100%);
    }

    .stat-card.stat-estoque-qtd {
        border-left-color: var(--secondary-color);
        background: linear-gradient(135deg, #e6fffa 0%, #b2f5ea 100%);
    }

    .stat-card.stat-baixo {
        border-left-color: var(--danger-color);
        background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
        animation: pulse-warning 3s infinite;
    }

    @keyframes pulse-warning {
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

    /* Grid de cards principais - mesmo estilo do financeiro e clientes */
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

    /* Botões - mesmo estilo do financeiro e clientes */
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
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
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

    /* Alertas especiais para estoque baixo */
    .alerta-estoque-baixo {
        background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
        border: 1px solid var(--danger-color);
        border-radius: var(--radius);
        padding: 1rem;
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        animation: pulse-alert 2s infinite;
    }

    @keyframes pulse-alert {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.8; }
    }

    .alerta-estoque-baixo i {
        font-size: 1.5rem;
        color: var(--danger-color);
    }

    .alerta-estoque-baixo .alerta-content {
        flex: 1;
    }

    .alerta-estoque-baixo .alerta-title {
        color: var(--danger-color);
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .alerta-estoque-baixo .alerta-text {
        color: var(--dark-gray);
        font-size: 0.9rem;
    }

    /* Responsividade - mesmo padrão do financeiro e clientes */
    @media (max-width: 1200px) {
        .container {
            margin: 2rem 1.5rem;
            padding: 2rem;
        }

        .products-grid {
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        .products-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .product-card {
            padding: 1.5rem;
        }

        .product-card .icon {
            font-size: 2.5rem;
        }

        .product-card h3 {
            font-size: 1.2rem;
        }

        .last-product-card {
            flex-direction: column;
            text-align: center;
            gap: 0.75rem;
        }

        .last-product-content {
            align-items: center;
        }

        .alerta-estoque-baixo {
            flex-direction: column;
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

        .product-card {
            padding: 1.25rem;
        }

        .product-card .icon {
            font-size: 2rem;
        }

        .product-card h3 {
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
        
        .product-card:active {
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

    .product-card {
        animation: fadeInUp 0.6s ease forwards;
    }

    .product-card:nth-child(1) { animation-delay: 0.1s; }
    .product-card:nth-child(2) { animation-delay: 0.2s; }
    .product-card:nth-child(3) { animation-delay: 0.3s; }
    .product-card:nth-child(4) { animation-delay: 0.4s; }
    .product-card:nth-child(5) { animation-delay: 0.5s; }
    .product-card:nth-child(6) { animation-delay: 0.6s; }

    .stat-card {
        animation: fadeInUp 0.5s ease forwards;
    }

    .stat-card:nth-child(1) { animation-delay: 0.05s; }
    .stat-card:nth-child(2) { animation-delay: 0.1s; }
    .stat-card:nth-child(3) { animation-delay: 0.15s; }
    .stat-card:nth-child(4) { animation-delay: 0.2s; }
    .stat-card:nth-child(5) { animation-delay: 0.25s; }
    .stat-card:nth-child(6) { animation-delay: 0.3s; }
</style>

<div class="container">
    <h2><i class="fas fa-box"></i> Gestão de Produtos</h2>

    <!-- Alerta de estoque baixo -->
    <?php if ($produtosEstoqueBaixo > 0): ?>
    <div class="alerta-estoque-baixo">
        <i class="fas fa-exclamation-triangle"></i>
        <div class="alerta-content">
            <div class="alerta-title">Atenção: Produtos com Estoque Baixo!</div>
            <div class="alerta-text">
                <?php echo $produtosEstoqueBaixo; ?> produto(s) estão com estoque abaixo do mínimo configurado. 
                Acesse o controle de estoque para verificar.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Cards principais -->
    <div class="products-grid">
        <!-- Card Cadastrar Produto -->
        <?php if ($permissionManager->hasPagePermission('produtos', 'create')): ?>
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-plus-circle"></i>
            </div>
            <h3>Cadastrar Produto</h3>
            <p>Adicione novos produtos ao sistema com informações completas incluindo estoque inicial, preços e dados para licitações.</p>
            <div class="card-buttons">
                <a href="cadastro_produto.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Novo Produto
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Card Consultar Produtos -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-search"></i>
            </div>
            <h3>Consultar Produtos</h3>
            <p>Visualize, edite e gerencie todos os produtos cadastrados. Acesse informações detalhadas, histórico e controle de estoque.</p>
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
            <p>Gerencie entradas e saídas de produtos. Monitore níveis de estoque atual baseado na fórmula: Estoque = Inicial + Entradas - Saídas.</p>
            <div class="card-buttons">
                <a href="estoque_produtos.php" class="btn btn-info">
                    <i class="fas fa-clipboard-list"></i> Gerenciar Estoque
                </a>
                <?php if ($produtosEstoqueBaixo > 0): ?>
                <a href="produtos_estoque_baixo.php" class="btn btn-warning">
                    <i class="fas fa-exclamation-triangle"></i> Ver Críticos (<?php echo $produtosEstoqueBaixo; ?>)
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card Relatórios -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-chart-bar"></i>
            </div>
            <h3>Relatórios de Produtos</h3>
            <p>Gere relatórios detalhados sobre movimentação de estoque, vendas, análises de desempenho e controle financeiro por período.</p>
            <div class="card-buttons">
                <a href="relatorio_produtos.php" class="btn btn-warning">
                    <i class="fas fa-file-chart-line"></i> Ver Relatórios
                </a>
            </div>
        </div>

        <!-- Card Movimentações de Estoque -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-exchange-alt"></i>
            </div>
            <h3>Movimentações</h3>
            <p>Acompanhe todas as entradas e saídas de produtos, histórico completo de movimentações e auditoria de estoque.</p>
            <div class="card-buttons">
                <a href="movimentacoes_estoque.php" class="btn btn-info">
                    <i class="fas fa-history"></i> Ver Movimentações
                </a>
            </div>
        </div>

        <!-- Card Importar/Exportar -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-file-import"></i>
            </div>
            <h3>Importar/Exportar</h3>
            <p>Importe produtos em massa via Excel ou CSV e exporte dados de estoque para análises externas e backup do sistema.</p>
            <div class="card-buttons">
                <a href="importar_produtos.php" class="btn btn-primary">
                    <i class="fas fa-file-upload"></i> Importar/Exportar
                </a>
            </div>
        </div>

        <!-- Card Categorias -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-tags"></i>
            </div>
            <h3>Categorias</h3>
            <p>Organize seus produtos em categorias para facilitar a busca, relatórios segmentados e melhor organização do catálogo.</p>
            <div class="card-buttons">
                <a href="categorias_produtos.php" class="btn btn-success">
                    <i class="fas fa-folder-open"></i> Gerenciar Categorias
                </a>
            </div>
        </div>

        <!-- Card Análise de Estoque -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-analytics"></i>
            </div>
            <h3>Análise de Estoque</h3>
            <p>Dashboard analítico com gráficos de estoque, produtos mais vendidos, giro de estoque e indicadores de performance.</p>
            <div class="card-buttons">
                <a href="dashboard_estoque.php" class="btn btn-info">
                    <i class="fas fa-chart-pie"></i> Dashboard Analytics
                </a>
            </div>
        </div>

        <!-- Card Ajustes de Estoque -->
        <div class="product-card">
            <div class="icon">
                <i class="fas fa-wrench"></i>
            </div>
            <h3>Ajustes de Estoque</h3>
            <p>Realize ajustes manuais de estoque para corrigir divergências, perdas, avarias ou ajustes de inventário.</p>
            <div class="card-buttons">
                <a href="ajustes_estoque.php" class="btn btn-warning">
                    <i class="fas fa-tools"></i> Fazer Ajustes
                </a>
            </div>
        </div>
    </div>

    <!-- Estatísticas ATUALIZADAS com cálculo correto de estoque -->
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
            <div class="stat-sublabel">Quantidade de produtos</div>
        </div>
        
        <div class="stat-card stat-estoque-valor">
            <i class="stat-icon fas fa-dollar-sign"></i>
            <div class="stat-number" id="valorEstoque">
                R$ <?php echo number_format($valorTotalEstoque, 2, ',', '.'); ?>
            </div>
            <div class="stat-label">Valor em Estoque</div>
            <div class="stat-sublabel">Baseado nos preços unitários</div>
        </div>
        
        <div class="stat-card stat-baixo <?php echo $produtosEstoqueBaixo > 0 ? 'pulsing' : ''; ?>">
            <i class="stat-icon fas fa-exclamation-triangle"></i>
            <div class="stat-number" id="estoqueBaixo"><?php echo $produtosEstoqueBaixo; ?></div>
            <div class="stat-label">Estoque Crítico</div>
            <div class="stat-sublabel">Produtos abaixo do mínimo</div>
        </div>
    </div>

    <!-- Resumo de Estoque por Status -->
    <div class="estoque-resumo" style="margin-top: 2.5rem;">
        <h3 style="color: var(--primary-color); margin-bottom: 1.5rem; text-align: center;">
            <i class="fas fa-chart-pie"></i> Resumo do Estoque
        </h3>
        <div class="resumo-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
            <?php
            // Calcula estatísticas adicionais de estoque
            $produtos_sem_estoque = 0;
            $produtos_estoque_normal = 0;
            $produtos_estoque_alto = 0;
            
            foreach ($produtos_estoque as $produto) {
                $estoque_atual = floatval($produto['estoque_atual']);
                $estoque_minimo = floatval($produto['estoque_minimo']);
                
                if ($estoque_atual <= 0) {
                    $produtos_sem_estoque++;
                } elseif ($estoque_minimo > 0 && $estoque_atual <= $estoque_minimo) {
                    // Já contado em $produtosEstoqueBaixo
                } elseif ($estoque_minimo > 0 && $estoque_atual > ($estoque_minimo * 3)) {
                    $produtos_estoque_alto++;
                } else {
                    $produtos_estoque_normal++;
                }
            }
            ?>
            
            <div class="resumo-card" style="background: linear-gradient(135deg, #f0fff4 0%, #c6f6d5 100%); padding: 1.5rem; border-radius: var(--radius); border-left: 4px solid var(--success-color); text-align: center;">
                <i class="fas fa-check-circle" style="font-size: 2rem; color: var(--success-color); margin-bottom: 0.5rem;"></i>
                <div style="font-size: 1.8rem; font-weight: 700; color: var(--success-color); margin-bottom: 0.5rem;">
                    <?php echo $produtos_estoque_normal; ?>
                </div>
                <div style="font-size: 0.9rem; color: var(--medium-gray); text-transform: uppercase; letter-spacing: 0.5px;">
                    Estoque Normal
                </div>
            </div>
            
            <div class="resumo-card" style="background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%); padding: 1.5rem; border-radius: var(--radius); border-left: 4px solid var(--danger-color); text-align: center;">
                <i class="fas fa-exclamation-triangle" style="font-size: 2rem; color: var(--danger-color); margin-bottom: 0.5rem;"></i>
                <div style="font-size: 1.8rem; font-weight: 700; color: var(--danger-color); margin-bottom: 0.5rem;">
                    <?php echo $produtosEstoqueBaixo; ?>
                </div>
                <div style="font-size: 0.9rem; color: var(--medium-gray); text-transform: uppercase; letter-spacing: 0.5px;">
                    Estoque Crítico
                </div>
            </div>
            
            <div class="resumo-card" style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 1.5rem; border-radius: var(--radius); border-left: 4px solid var(--medium-gray); text-align: center;">
                <i class="fas fa-ban" style="font-size: 2rem; color: var(--medium-gray); margin-bottom: 0.5rem;"></i>
                <div style="font-size: 1.8rem; font-weight: 700; color: var(--medium-gray); margin-bottom: 0.5rem;">
                    <?php echo $produtos_sem_estoque; ?>
                </div>
                <div style="font-size: 0.9rem; color: var(--medium-gray); text-transform: uppercase; letter-spacing: 0.5px;">
                    Sem Estoque
                </div>
            </div>
            
            <div class="resumo-card" style="background: linear-gradient(135deg, #e6f7ff 0%, #bae7ff 100%); padding: 1.5rem; border-radius: var(--radius); border-left: 4px solid var(--info-color); text-align: center;">
                <i class="fas fa-arrow-up" style="font-size: 2rem; color: var(--info-color); margin-bottom: 0.5rem;"></i>
                <div style="font-size: 1.8rem; font-weight: 700; color: var(--info-color); margin-bottom: 0.5rem;">
                    <?php echo $produtos_estoque_alto; ?>
                </div>
                <div style="font-size: 0.9rem; color: var(--medium-gray); text-transform: uppercase; letter-spacing: 0.5px;">
                    Estoque Alto
                </div>
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

    <!-- Botões de Ação Rápida -->
    <div class="acoes-rapidas" style="margin-top: 2rem; text-align: center;">
        <h3 style="color: var(--primary-color); margin-bottom: 1.5rem;">
            <i class="fas fa-bolt"></i> Ações Rápidas
        </h3>
        <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
            <a href="cadastro_produto.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Novo Produto
            </a>
            <a href="consulta_produto.php" class="btn btn-success">
                <i class="fas fa-search"></i> Buscar Produto
            </a>
            <a href="estoque_produtos.php" class="btn btn-info">
                <i class="fas fa-warehouse"></i> Ver Estoque
            </a>
            <?php if ($produtosEstoqueBaixo > 0): ?>
            <a href="produtos_estoque_baixo.php" class="btn btn-warning">
                <i class="fas fa-exclamation-triangle"></i> Estoque Crítico
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Finaliza a página com footer e scripts
renderFooter();
renderScripts();
?>

<script>
    // JavaScript específico da página de produtos com controle de estoque
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
                        
                        // Extrai o número do texto
                        let finalNumber = 0;
                        if (text.includes('R)) {
                            // Remove R$, pontos e vírgulas para pegar o número
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

        // Adiciona efeitos de animação aos cards
        const cards = document.querySelectorAll('.product-card');
        
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 150);
        });

        // Adiciona efeitos de hover nos botões
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

        // Tooltip para estatísticas com informações de estoque
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach(card => {
            const label = card.querySelector('.stat-label').textContent;
            
            card.addEventListener('mouseenter', function() {
                if (label.includes('Total de Produtos')) {
                    this.title = 'Total de produtos cadastrados no sistema';
                } else if (label.includes('Novos')) {
                    this.title = 'Produtos cadastrados no mês atual';
                } else if (label.includes('Fornecedores')) {
                    this.title = 'Número de fornecedores diferentes cadastrados';
                } else if (label.includes('Total em Estoque')) {
                    this.title = 'Quantidade total de produtos em estoque (Inicial + Entradas - Saídas)';
                } else if (label.includes('Valor em Estoque')) {
                    this.title = 'Valor total do estoque baseado nos preços unitários';
                } else if (label.includes('Estoque Crítico')) {
                    this.title = 'Produtos com estoque abaixo do mínimo configurado';
                }
            });
        });

        // Auto-refresh das estatísticas a cada 5 minutos
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
                        'estoqueBaixo'
                    ];
                    
                    elementos.forEach(id => {
                        const novoValor = doc.getElementById(id)?.textContent;
                        const elementoAtual = document.getElementById(id);
                        
                        if (novoValor && elementoAtual && novoValor !== elementoAtual.textContent) {
                            elementoAtual.textContent = novoValor;
                            
                            // Atualiza também os números nos botões
                            if (id === 'totalProdutos') {
                                const btnVerProdutos = document.querySelector('.btn-success');
                                if (btnVerProdutos) {
                                    btnVerProdutos.innerHTML = 
                                        '<i class="fas fa-search"></i> Ver Produtos (' + novoValor + ')';
                                }
                            }
                            
                            if (id === 'estoqueBaixo') {
                                const btnEstoqueBaixo = document.querySelector('.btn-warning');
                                if (btnEstoqueBaixo && btnEstoqueBaixo.textContent.includes('Críticos')) {
                                    btnEstoqueBaixo.innerHTML = 
                                        '<i class="fas fa-exclamation-triangle"></i> Ver Críticos (' + novoValor + ')';
                                }
                            }
                        }
                    });
                })
                .catch(err => console.log('Erro ao atualizar estatísticas:', err));
        }, 300000); // 5 minutos

        // Adiciona busca rápida com tecla de atalho
        document.addEventListener('keydown', function(e) {
            // Ctrl + K ou Cmd + K para abrir busca rápida
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                window.location.href = 'consulta_produto.php';
            }
            
            // Ctrl + N para novo produto
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'cadastro_produto.php';
            }
            
            // Ctrl + E para estoque
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                window.location.href = 'estoque_produtos.php';
            }
        });

        // Sistema de notificações para alertas de estoque baixo
        function checkAndShowStockAlerts() {
            const estoqueBaixo = <?php echo $produtosEstoqueBaixo; ?>;
            
            if (estoqueBaixo > 0) {
                setTimeout(() => {
                    showNotification(
                        `Atenção! ${estoqueBaixo} produto(s) com estoque crítico. Clique para verificar.`,
                        'warning',
                        () => window.location.href = 'produtos_estoque_baixo.php'
                    );
                }, 3000);
            }
        }

        // Verifica estoque baixo ao carregar a página
        checkAndShowStockAlerts();

        // Função para mostrar notificações melhorada
        function showNotification(message, type = 'info', clickAction = null) {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'warning' ? 'exclamation-triangle' : type === 'success' ? 'check-circle' : 'info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'warning' ? 'var(--warning-color)' : type === 'success' ? 'var(--success-color)' : 'var(--info-color)'};
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

        // Registra analytics da página
        console.log('=== MÓDULO DE PRODUTOS - DASHBOARD ===');
        console.log('Total de produtos:', <?php echo $totalProdutos; ?>);
        console.log('Produtos novos este mês:', <?php echo $produtosAtivos; ?>);
        console.log('Total de fornecedores:', <?php echo $totalFornecedores; ?>);
        console.log('Quantidade total em estoque:', <?php echo $quantidadeTotalEstoque; ?>);
        console.log('Valor total em estoque: R, <?php echo number_format($valorTotalEstoque, 2, ',', '.'); ?>);
        console.log('Produtos com estoque crítico:', <?php echo $produtosEstoqueBaixo; ?>);
        console.log('Usuário:', '<?php echo addslashes($_SESSION['user']['name'] ?? 'N/A'); ?>');
        console.log('Permissão:', '<?php echo addslashes($_SESSION['user']['permission'] ?? 'N/A'); ?>');
        console.log('========================================');

        // Indicador de carregamento para ações Ajax
        const originalFetch = window.fetch;
        window.fetch = function(...args) {
            document.body.style.cursor = 'wait';
            return originalFetch.apply(this, args)
                .finally(() => {
                    document.body.style.cursor = 'default';
                });
        };

        // Adiciona efeitos visuais especiais para estoque crítico
        const estoqueBaixo = <?php echo $produtosEstoqueBaixo; ?>;
        if (estoqueBaixo > 0) {
            const criticalCard = document.querySelector('.stat-baixo');
            if (criticalCard) {
                criticalCard.style.animation = 'pulse-warning 2s infinite';
            }
        }

        // Contador de produtos em tempo real
        function updateProductCount() {
            const productCards = document.querySelectorAll('.product-card').length;
            console.log(`Interface carregada com ${productCards} cards de funcionalidades`);
        }

        updateProductCount();
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
        }

        .notification button:hover {
            opacity: 0.7;
        }

        .notification:hover {
            transform: translateX(-5px);
        }

        /* Efeito de loading para cards */
        .product-card.loading {
            position: relative;
            overflow: hidden;
        }

        .product-card.loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 255, 255, 0.4),
                transparent
            );
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            to {
                left: 100%;
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
            .resumo-card {
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

        /* Responsividade para resumo de estoque */
        @media (max-width: 768px) {
            .resumo-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) !important;
            }
        }

        @media (max-width: 480px) {
            .resumo-grid {
                grid-template-columns: 1fr !important;
            }
            
            .acoes-rapidas div {
                flex-direction: column !important;
            }
            
            .acoes-rapidas .btn {
                width: 100% !important;
            }
        }
    `;

    // Adicionar CSS ao documento
    const style = document.createElement('style');
    style.textContent = additionalCSS;
    document.head.appendChild(style);
</script> 

</body>
</html>