<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inclui o sistema de permissões e auditoria (se existir)
include('db.php');
if (file_exists('permissions.php')) {
    include('permissions.php');
    $permissionManager = initPermissions($pdo);
    // Verifica se o usuário tem permissão para acessar vendas
    $permissionManager->requirePermission('vendas', 'view');
}

if (file_exists('includes/audit.php')) {
    include('includes/audit.php');
    // Registra acesso à página
    logUserAction('READ', 'vendas_produto_dashboard');
}

// Verifica se o usuário é administrador
$isAdmin = (isset($_SESSION['user']) && $_SESSION['user']['permission'] === 'Administrador');

$error = "";
$success = "";
$sales = [];
$produto_id = isset($_GET['produto_id']) ? intval($_GET['produto_id']) : 0;
$produto_nome = '';
$produto_info = null;

// Busca informações completas do produto
if ($produto_id > 0) {
    try {
        $sql = "SELECT p.*, 
               f.nome as fornecedor_nome
        FROM produtos p
        LEFT JOIN fornecedores f ON p.fornecedor = f.id
        WHERE p.id = :produto_id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
        $stmt->execute();
        $produto_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($produto_info) {
            $produto_nome = htmlspecialchars($produto_info['nome']);
        } else {
            $error = "Produto não encontrado.";
        }
    } catch (PDOException $e) {
        $error = "Erro ao buscar produto: " . $e->getMessage();
        error_log("Erro ao buscar produto ID $produto_id: " . $e->getMessage());
    }
} else {
    $error = "ID do produto não informado ou inválido.";
}

// CONSULTA CORRIGIDA - Usando a estrutura real da tabela venda_produtos
if ($produto_id > 0 && !$error) {
    try {
        // Primeiro, verifica se existe a tabela vendas para fazer JOIN
        $checkVendasTable = $pdo->query("SHOW TABLES LIKE 'vendas'");
        $hasVendasTable = $checkVendasTable->rowCount() > 0;
        
        if ($hasVendasTable) {
            // Se existe a tabela vendas, faz JOIN completo
            $sql = "SELECT 
                vp.id,
                vp.venda_id,
                vp.quantidade,
                vp.valor_unitario,
                vp.valor_total,
                vp.observacao,
                vp.created_at,
                v.data,
                v.nf as numero_nota,      -- CAMPO NF DA TABELA VENDAS
                v.status_pagamento,       -- STATUS DA TABELA VENDAS
                v.observacao as venda_observacoes,    
                c.nome_orgaos,
                c.cnpj as cliente_cnpj
            FROM venda_produtos vp
            LEFT JOIN vendas v ON vp.venda_id = v.id
            LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
            WHERE vp.produto_id = :produto_id
            ORDER BY COALESCE(v.data, vp.created_at) DESC";
        } else {
            // Se não existe tabela vendas, consulta apenas venda_produtos
            $sql = "SELECT 
                vp.id,
                vp.venda_id,
                vp.quantidade,
                vp.valor_unitario,
                vp.valor_total,
                vp.observacao,
                vp.created_at,
                'N/A' as data,
                'N/A' as numero_nota,
                'Registrado' as status_pagamento,
                '' as venda_observacoes,
                'N/A' as nome_orgaos,
                'N/A' as cliente_cnpj
            FROM venda_produtos vp
            WHERE vp.produto_id = :produto_id
            ORDER BY vp.created_at DESC";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
        $stmt->execute();

        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log da consulta para debug
        error_log("Consulta vendas produto ID $produto_id: " . count($sales) . " registros encontrados");
        
    } catch (PDOException $e) {
        $error = "Erro na consulta de vendas: " . $e->getMessage();
        error_log("Erro na consulta vendas produto ID $produto_id: " . $e->getMessage());
    }
}

// Inclui o template de header se existir
if (file_exists('includes/header_template.php')) {
    include('includes/header_template.php');
    renderHeader("Vendas do Produto - LicitaSis", "vendas");
} else {
    // Header básico se não existir o template
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Vendas do Produto - LicitaSis</title>
        <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    </head>
    <body>
    
    <header>
        <a href="index.php">
            <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
        </a>
    </header>

    <nav>
        <div class="dropdown">
            <a href="clientes.php">Clientes</a>
            <div class="dropdown-content">
                <a href="cadastrar_clientes.php">Inserir Clientes</a>
                <a href="consultar_clientes.php">Consultar Clientes</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="produtos.php">Produtos</a>
            <div class="dropdown-content">
                <a href="cadastro_produto.php">Inserir Produto</a>
                <a href="consulta_produto.php">Consultar Produtos</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="empenhos.php">Empenhos</a>
            <div class="dropdown-content">
                <a href="cadastro_empenho.php">Inserir Empenho</a>
                <a href="consulta_empenho.php">Consultar Empenho</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="financeiro.php">Financeiro</a>
            <div class="dropdown-content">
                <a href="contas_a_receber.php">Contas a Receber</a>
                <a href="contas_recebidas_geral.php">Contas Recebidas</a>
                <a href="contas_a_pagar.php">Contas a Pagar</a>
                <a href="contas_pagas.php">Contas Pagas</a>
                <a href="caixa.php">Caixa</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="transportadoras.php">Transportadoras</a>
            <div class="dropdown-content">
                <a href="cadastro_transportadoras.php">Inserir Transportadora</a>
                <a href="consulta_transportadoras.php">Consultar Transportadora</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="fornecedores.php">Fornecedores</a>
            <div class="dropdown-content">
                <a href="cadastro_fornecedores.php">Inserir Fornecedor</a>
                <a href="consulta_fornecedores.php">Consultar Fornecedor</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="vendas.php">Vendas</a>
            <div class="dropdown-content">
                <a href="cadastro_vendas.php">Inserir Venda</a>
                <a href="consulta_vendas.php">Consultar Venda</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="compras.php">Compras</a>
            <div class="dropdown-content">
                <a href="cadastro_compras.php">Inserir Compras</a>
                <a href="consulta_compras.php">Consultar Compras</a>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <div class="dropdown">
                <a href="usuario.php">Usuários</a>
                    <div class="dropdown-content">
                        <a href="signup.php">Inserir Novo Usuário</a>
                        <a href="consulta_usuario.php">Consultar Usuário</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <div class="dropdown">
                <a href="funcionarios.php">Funcionários</a>
                    <div class="dropdown-content">
                        <a href="cadastro_funcionario.php">Inserir Novo Funcionário</a>
                        <a href="consulta_funcionario.php">Consultar Funcionário</a>
                </div>
            </div> 
        <?php endif; ?>
    </nav>
    <?php
}
?>

<style>
    /* Reset e variáveis CSS */
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

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    html, body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        min-height: 100vh;
        color: var(--dark-gray);
        line-height: 1.6;
    }

    /* Header */
    header {
        background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
        padding: 0.5rem 0;
        text-align: center;
        box-shadow: var(--shadow);
    }

    .logo {
        max-width: 140px;
        height: auto;
        transition: var(--transition);
    }

    .logo:hover {
        transform: scale(1.05);
    }

    /* Navigation */
    nav {
        background: var(--primary-color);
        padding: 0;
        text-align: center;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }

    nav a {
        color: white;
        padding: 0.75rem 1rem;
        text-decoration: none;
        font-size: 0.95rem;
        font-weight: 500;
        display: inline-block;
        transition: var(--transition);
        border-bottom: 3px solid transparent;
    }

    nav a:hover {
        background: rgba(255,255,255,0.1);
        border-bottom-color: var(--secondary-color);
        transform: translateY(-1px);
    }

    .dropdown {
        display: inline-block;
        position: relative;
    }

    .dropdown-content {
        display: none;
        position: absolute;
        background: var(--primary-color);
        min-width: 200px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        z-index: 1000;
        border-radius: 0 0 var(--radius-sm) var(--radius-sm);
        overflow: hidden;
    }

    .dropdown-content a {
        display: block;
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }

    .dropdown-content a:last-child {
        border-bottom: none;
    }

    .dropdown:hover .dropdown-content {
        display: block;
        animation: fadeInDown 0.3s ease;
    }

    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
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
    .page-header {
        text-align: center;
        margin-bottom: 2.5rem;
        padding-bottom: 1.5rem;
        border-bottom: 2px solid var(--border-color);
    }

    .page-header h2 {
        color: var(--primary-color);
        font-size: 2rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        position: relative;
    }

    .page-header h2::after {
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

    .product-info {
        color: var(--medium-gray);
        font-size: 1.1rem;
        margin-top: 1rem;
        padding: 1.5rem;
        background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
        border-radius: var(--radius-sm);
        border-left: 4px solid var(--secondary-color);
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
    }

    .product-info-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: white;
        padding: 1rem;
        border-radius: var(--radius-sm);
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .product-info-item i {
        color: var(--secondary-color);
        width: 20px;
    }

    .product-info-item strong {
        color: var(--dark-gray);
        font-weight: 600;
    }

    /* Mensagens */
    .error, .success {
        padding: 1rem 1.5rem;
        border-radius: var(--radius-sm);
        margin-bottom: 1.5rem;
        font-weight: 500;
        text-align: center;
        animation: slideInDown 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    @keyframes slideInDown {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    /* Estatísticas */
    .stats-container {
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
        box-shadow: var(--shadow-hover);
    }

    .stat-icon {
        font-size: 2.5rem;
        margin-bottom: 1rem;
        display: block;
    }

    .stat-card.sales .stat-icon { color: var(--info-color); }
    .stat-card.revenue .stat-icon { color: var(--success-color); }
    .stat-card.quantity .stat-icon { color: var(--warning-color); }
    .stat-card.average .stat-icon { color: var(--danger-color); }

    .stat-number {
        font-size: 2.2rem;
        font-weight: 800;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        line-height: 1;
        font-family: 'Courier New', monospace;
    }

    .stat-card.sales .stat-number { color: var(--info-color); }
    .stat-card.revenue .stat-number { color: var(--success-color); }
    .stat-card.quantity .stat-number { color: var(--warning-color); }
    .stat-card.average .stat-number { color: var(--danger-color); }

    .stat-label {
        color: var(--medium-gray);
        font-size: 0.9rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Filtros */
    .filters-container {
        background: linear-gradient(135deg, var(--light-gray), white);
        padding: 2rem;
        border-radius: var(--radius);
        margin-bottom: 2rem;
        box-shadow: var(--shadow);
        border-left: 4px solid var(--secondary-color);
    }

    .filters-title {
        color: var(--primary-color);
        font-size: 1.2rem;
        font-weight: 600;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
    }

    .filter-group label {
        font-weight: 600;
        color: var(--dark-gray);
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .filter-group select,
    .filter-group input {
        padding: 0.875rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-sm);
        font-size: 0.95rem;
        transition: var(--transition);
        background: white;
    }

    .filter-group select:focus,
    .filter-group input:focus {
        outline: none;
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
    }

    /* Tabela */
    .table-container {
        overflow-x: auto;
        margin-bottom: 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        background: white;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background: white;
    }

    table th, table td {
        padding: 1.25rem 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
    }

    table th {
        background: linear-gradient(135deg, var(--secondary-color), var(--secondary-dark));
        color: white;
        font-weight: 600;
        position: sticky;
        top: 0;
        white-space: nowrap;
        font-size: 0.95rem;
    }

    table th i {
        margin-right: 0.5rem;
    }

    table tr {
        transition: var(--transition);
    }

    table tr:hover {
        background: var(--light-gray);
        transform: scale(1.01);
    }

    table td {
        font-size: 0.95rem;
    }

    table td.currency {
        font-weight: 600;
        color: var(--success-color);
        font-family: 'Courier New', monospace;
    }

    /* Status badges */
    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-weight: 600;
    }

    .status-recebido {
        color: var(--success-color);
        background: rgba(40, 167, 69, 0.1);
    }

    .status-pendente {
        color: var(--warning-color);
        background: rgba(255, 193, 7, 0.1);
    }

    .status-cancelado {
        color: var(--danger-color);
        background: rgba(220, 53, 69, 0.1);
    }

    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        color: var(--medium-gray);
        background: var(--light-gray);
        border-radius: var(--radius);
        margin: 2rem 0;
    }

    .empty-state i {
        font-size: 5rem;
        margin-bottom: 1rem;
        opacity: 0.3;
        color: var(--secondary-color);
    }

    .empty-state p {
        font-size: 1.2rem;
        margin-bottom: 0.5rem;
        font-weight: 500;
    }

    .empty-state .subtitle {
        font-size: 1rem;
        color: var(--medium-gray);
        margin-top: 0.5rem;
    }

    /* Botões */
    .btn-container {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 2rem;
        flex-wrap: wrap;
    }

    .action-button {
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

    .action-button::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
        transition: left 0.5s;
    }

    .action-button:hover::before {
        left: 100%;
    }

    .action-button {
        background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
        color: white;
        box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
    }

    .action-button:hover {
        background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
    }

    .action-button.secondary {
        background: linear-gradient(135deg, var(--medium-gray) 0%, var(--dark-gray) 100%);
        box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
    }

    .action-button.secondary:hover {
        background: linear-gradient(135deg, var(--dark-gray) 0%, var(--medium-gray) 100%);
        box-shadow: 0 6px 12px rgba(108, 117, 125, 0.3);
    }

    /* Responsividade */
    @media (max-width: 1200px) {
        .container {
            margin: 2rem 1.5rem;
            padding: 2rem;
        }
    }

    @media (max-width: 768px) {
        .container {
            padding: 1.5rem;
            margin: 1.5rem 1rem;
        }

        .page-header h2 {
            font-size: 1.75rem;
        }

        .stats-container {
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

        .filters-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        table {
            font-size: 0.875rem;
        }

        table th, table td {
            padding: 0.75rem 0.5rem;
        }

        .btn-container {
            flex-direction: column;
        }

        .action-button {
            width: 100%;
        }

        .product-info {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .container {
            padding: 1.25rem;
            margin: 1rem 0.5rem;
        }

        .page-header h2 {
            font-size: 1.5rem;
        }

        .stats-container {
            grid-template-columns: 1fr;
        }

        .stat-card {
            padding: 1rem;
        }

        .stat-number {
            font-size: 1.5rem;
        }

        .action-button {
            padding: 0.75rem 1.25rem;
            font-size: 0.9rem;
        }
    }

    /* Print styles */
    @media print {
        nav, .btn-container, header, .filters-container {
            display: none !important;
        }
        
        .container {
            margin: 0;
            box-shadow: none;
            padding: 1rem;
        }
        
        table {
            font-size: 12pt;
        }

        .page-header h2::after {
            display: none;
        }
    }

    /* Animações de entrada */
    .fade-in {
        animation: fadeIn 0.5s ease;
    }

    .stats-container .stat-card {
        animation: fadeInUp 0.5s ease forwards;
    }

    .stats-container .stat-card:nth-child(1) { animation-delay: 0.1s; }
    .stats-container .stat-card:nth-child(2) { animation-delay: 0.2s; }
    .stats-container .stat-card:nth-child(3) { animation-delay: 0.3s; }
    .stats-container .stat-card:nth-child(4) { animation-delay: 0.4s; }

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
</style>

<div class="container fade-in">
    <div class="page-header">
        <h2><i class="fas fa-shopping-cart"></i> Vendas Relacionadas ao Produto</h2>
        
        <?php if ($produto_info): ?>
        <div class="product-info">
            <div class="product-info-item">
                <i class="fas fa-box"></i>
                <div>
                    <strong>Produto:</strong> <?php echo $produto_nome; ?>
                    <small style="display: block; color: var(--medium-gray);">ID: <?php echo $produto_id; ?></small>
                </div>
            </div>
            <div class="product-info-item">
                <i class="fas fa-barcode"></i>
                <div>
                    <strong>Código:</strong> <?php echo htmlspecialchars($produto_info['codigo']); ?>
                </div>
            </div>
            <div class="product-info-item">
    <i class="fas fa-warehouse"></i>
    <div>
        <strong>Estoque Atual:</strong> 
        <?php echo isset($produto_info['estoque_atual']) ? number_format($produto_info['estoque_atual'], 2, ',', '.') : 'N/A'; ?>
        <?php if (isset($produto_info['estoque_minimo']) && $produto_info['estoque_minimo'] > 0): ?>
            <small style="display: block; color: var(--medium-gray);">
                Mín: <?php echo number_format($produto_info['estoque_minimo'], 2, ',', '.'); ?>
            </small>
        <?php endif; ?>
    </div>
</div>
            <div class="product-info-item">
                <i class="fas fa-dollar-sign"></i>
                <div>
                    <strong>Preço Venda:</strong> R$ <?php echo number_format($produto_info['preco_venda'] ?? $produto_info['preco_unitario'], 2, ',', '.'); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if (count($sales) > 0): ?>
        <!-- Estatísticas -->
        <div class="stats-container">
            <div class="stat-card sales">
                <i class="stat-icon fas fa-chart-line"></i>
                <div class="stat-number"><?php echo count($sales); ?></div>
                <div class="stat-label">Total de Vendas</div>
            </div>
            <div class="stat-card revenue">
                <i class="stat-icon fas fa-dollar-sign"></i>
                <div class="stat-number">
                    R$ <?php 
                        $total = 0;
                        foreach ($sales as $sale) {
                            $total += floatval($sale['valor_total'] ?? 0);
                        }
                        echo number_format($total, 2, ',', '.'); 
                    ?>
                </div>
                <div class="stat-label">Receita Total</div>
            </div>
            <div class="stat-card quantity">
                <i class="stat-icon fas fa-boxes"></i>
                <div class="stat-number">
                    <?php 
                        $totalQty = 0;
                        foreach ($sales as $sale) {
                            $totalQty += intval($sale['quantidade'] ?? 0);
                        }
                        echo number_format($totalQty, 0, ',', '.'); 
                    ?>
                </div>
                <div class="stat-label">Quantidade Total</div>
            </div>
            <div class="stat-card average">
                <i class="stat-icon fas fa-calculator"></i>
                <div class="stat-number">
                    R$ <?php 
                        $precoMedio = ($totalQty != 0) ? $total / $totalQty : 0;
                        echo number_format($precoMedio, 2, ',', '.'); 
                    ?>
                </div>
                <div class="stat-label">Preço Médio</div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filters-container">
    <div class="filters-title">
        <i class="fas fa-filter"></i> Filtros de Pesquisa
    </div>
    <div class="filters-grid">
        <div class="filter-group">
            <label for="filterClient">Filtrar por Cliente:</label>
            <select id="filterClient" onchange="filterTable()">
                <option value="">Todos os clientes</option>
                <?php 
                    $clientesUnicos = array_unique(array_filter(array_column($sales, 'nome_orgaos')));
                    sort($clientesUnicos);
                    foreach ($clientesUnicos as $cliente): 
                        if ($cliente != 'N/A'):
                ?>
                    <option value="<?php echo htmlspecialchars($cliente); ?>">
                        <?php echo htmlspecialchars($cliente); ?>
                    </option>
                <?php 
                        endif;
                    endforeach; 
                ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="filterStatus">Filtrar por Status:</label>
            <select id="filterStatus" onchange="filterTable()">
                <option value="">Todos os status</option>
                <?php 
                    $statusUnicos = array_unique(array_filter(array_column($sales, 'status_pagamento')));
                    foreach ($statusUnicos as $status): 
                        if ($status != 'N/A'):
                ?>
                    <option value="<?php echo htmlspecialchars($status); ?>">
                        <?php echo htmlspecialchars(ucfirst($status)); ?>
                    </option>
                <?php 
                        endif;
                    endforeach; 
                ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="filterNF">Filtrar por NF:</label>
            <input type="text" id="filterNF" placeholder="Digite o número da NF..." onkeyup="filterTable()">
        </div>
        <div class="filter-group">
            <label for="filterDate">Filtrar por Data:</label>
            <input type="month" id="filterDate" onchange="filterTable()">
        </div>
        <div class="filter-group">
            <label for="searchInput">Buscar:</label>
            <input type="text" id="searchInput" placeholder="Buscar em todos os campos..." onkeyup="filterTable()">
        </div>
    </div>
</div>

        <div class="table-container">
            <table id="vendasTable">
                <thead>
    <tr>
        <th><i class="fas fa-file-invoice"></i> Nota Fiscal</th>
        <th><i class="fas fa-building"></i> Cliente</th>
        <th><i class="fas fa-boxes"></i> Quantidade</th>
        <th><i class="fas fa-tag"></i> Valor Unitário</th>
        <th><i class="fas fa-money-check-alt"></i> Valor Total</th>
        <th><i class="fas fa-calendar"></i> Data</th>
        <th><i class="fas fa-info-circle"></i> Status</th>
        <th><i class="fas fa-cogs"></i> Ações</th>
    </tr>
</thead>
                
<tbody>
    <?php foreach ($sales as $sale): ?>
        <tr>
            <td>
                <?php if (!empty($sale['numero_nota']) && $sale['numero_nota'] != 'N/A'): ?>
                    <strong style="color: var(--primary-color);"><?php echo htmlspecialchars($sale['numero_nota']); ?></strong>
                <?php else: ?>
                    <span style="color: var(--medium-gray);">Não informada</span>
                <?php endif; ?>
                <?php if (!empty($sale['venda_id']) && $sale['venda_id'] != 'N/A'): ?>
                    <br><small style="color: var(--medium-gray);">ID Venda: #<?php echo htmlspecialchars($sale['venda_id']); ?></small>
                <?php endif; ?>
            </td>
            <td data-client="<?php echo htmlspecialchars($sale['nome_orgaos'] ?? ''); ?>">
                <?php echo htmlspecialchars($sale['nome_orgaos'] ?? 'Não informado'); ?>
                <?php if (!empty($sale['cliente_cnpj']) && $sale['cliente_cnpj'] != 'N/A'): ?>
                    <br><small style="color: var(--medium-gray);">CNPJ: <?php echo htmlspecialchars($sale['cliente_cnpj']); ?></small>
                <?php endif; ?>
            </td>
            <td style="text-align: center;">
                <strong><?php echo number_format(intval($sale['quantidade'] ?? 0), 0, ',', '.'); ?></strong>
            </td>
            <td class="currency">
                R$ <?php echo number_format(floatval($sale['valor_unitario'] ?? 0), 2, ',', '.'); ?>
            </td>
            <td class="currency">
                <strong>R$ <?php echo number_format(floatval($sale['valor_total'] ?? 0), 2, ',', '.'); ?></strong>
            </td>
            <td data-date="<?php echo $sale['data'] ?? $sale['created_at'] ?? ''; ?>">
                <?php 
                    $data = $sale['data'] ?? $sale['created_at'] ?? null;
                    if (!empty($data) && $data !== 'N/A' && $data !== '0000-00-00') {
                        echo date('d/m/Y', strtotime($data)); 
                    } else {
                        echo '<span style="color: var(--medium-gray);">Data não informada</span>';
                    }
                ?>
            </td>
            <td data-status="<?php echo htmlspecialchars($sale['status_pagamento'] ?? ''); ?>">
                <?php 
                    $status = $sale['status_pagamento'] ?? 'Registrado';
                    $statusClass = '';
                    $statusIcon = '';
                    
                    switch(strtolower($status)) {
                        case 'recebido':
                        case 'pago':
                        case 'concluido':
                        case 'finalizado':
                            $statusClass = 'status-recebido';
                            $statusIcon = 'fas fa-check-circle';
                            break;
                        case 'não recebido':
                        case 'pendente':
                        case 'em_andamento':
                        case 'em andamento':
                        case 'registrado':
                            $statusClass = 'status-pendente';
                            $statusIcon = 'fas fa-clock';
                            break;
                        case 'cancelado':
                        case 'cancelada':
                            $statusClass = 'status-cancelado';
                            $statusIcon = 'fas fa-times-circle';
                            break;
                        default:
                            $statusClass = 'status-pendente';
                            $statusIcon = 'fas fa-info-circle';
                    }
                ?>
                <span class="status-badge <?php echo $statusClass; ?>">
                    <i class="<?php echo $statusIcon; ?>"></i> 
                    <?php echo htmlspecialchars(ucfirst($status)); ?>
                </span>
            </td>
            <td>
                <?php if (!empty($sale['venda_id']) && $sale['venda_id'] != 'N/A'): ?>
                    <a href="detalhes_venda.php?id=<?php echo intval($sale['venda_id']); ?>" 
                       class="action-button" 
                       style="padding: 0.5rem 0.75rem; font-size: 0.85rem;"
                       title="Ver detalhes da venda">
                        <i class="fas fa-eye"></i>
                    </a>
                <?php else: ?>
                    <span style="color: var(--medium-gray); font-size: 0.85rem;">N/A</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</tbody>
            </table>
        </div>

        <!-- Análise de Vendas -->
        <div class="analysis-card" style="background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); color: white; padding: 2rem; border-radius: var(--radius); margin-top: 2rem; box-shadow: var(--shadow);">
            <h3 style="color: white; margin-bottom: 1.5rem; font-size: 1.4rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-chart-line"></i> Análise de Vendas
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: var(--radius-sm); transition: var(--transition);">
                    <div style="font-size: 0.9rem; margin-bottom: 0.5rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500;">Ticket Médio</div>
                    <div style="font-size: 1.6rem; font-weight: 700; font-family: 'Courier New', monospace;">
                        R$ <?php 
                            $ticketMedio = (count($sales) != 0) ? $total / count($sales) : 0;
                            echo number_format($ticketMedio, 2, ',', '.'); 
                        ?>
                    </div>
                </div>
                <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: var(--radius-sm); transition: var(--transition);">
                    <div style="font-size: 0.9rem; margin-bottom: 0.5rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500;">Maior Venda</div>
                    <div style="font-size: 1.6rem; font-weight: 700; font-family: 'Courier New', monospace;">
                        R$ <?php 
                            $valores = array_column($sales, 'valor_total');
                            $valores = array_filter($valores, function($v) { return $v > 0; });
                            echo !empty($valores) ? number_format(max($valores), 2, ',', '.') : '0,00'; 
                        ?>
                    </div>
                </div>
                <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: var(--radius-sm); transition: var(--transition);">
                    <div style="font-size: 0.9rem; margin-bottom: 0.5rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500;">Menor Venda</div>
                    <div style="font-size: 1.6rem; font-weight: 700; font-family: 'Courier New', monospace;">
                        R$ <?php echo !empty($valores) ? number_format(min($valores), 2, ',', '.') : '0,00'; ?>
                    </div>
                </div>
                <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: var(--radius-sm); transition: var(--transition);">
                    <div style="font-size: 0.9rem; margin-bottom: 0.5rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500;">Clientes Únicos</div>
                    <div style="font-size: 1.6rem; font-weight: 700; font-family: 'Courier New', monospace;">
                        <?php 
                            $clientesUnicos = array_unique(array_filter(array_column($sales, 'nome_orgaos'), function($c) { return $c != 'N/A' && !empty($c); }));
                            echo count($clientesUnicos); 
                        ?>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p>Nenhuma venda encontrada para este produto.</p>
            <p class="subtitle">
                Verifique se existem vendas cadastradas ou se o produto está correto.
            </p>
        </div>
    <?php endif; ?>

    <div class="btn-container">
        <a href="consulta_produto.php" class="action-button secondary">
            <i class="fas fa-arrow-left"></i> Voltar para Produtos
        </a>
        <?php if (count($sales) > 0): ?>
            <button onclick="window.print()" class="action-button">
                <i class="fas fa-print"></i> Imprimir Relatório
            </button>
            <button onclick="exportToCSV()" class="action-button">
                <i class="fas fa-file-csv"></i> Exportar CSV
            </button>
            <a href="exportar_vendas_produto.php?produto_id=<?php echo $produto_id; ?>" class="action-button">
                <i class="fas fa-file-excel"></i> Exportar Excel
            </a>
        <?php endif; ?>
        <?php if ($produto_id > 0): ?>
            <a href="detalhes_produto.php?id=<?php echo $produto_id; ?>" class="action-button">
                <i class="fas fa-info-circle"></i> Detalhes do Produto
            </a>
        <?php endif; ?>
    </div>
</div>

<?php
// Finaliza a página com footer e scripts se o template existir
if (function_exists('renderFooter')) {
    renderFooter();
}

if (function_exists('renderScripts')) {
    renderScripts();
}
?>

<script>
// JavaScript específico da página de vendas do produto
document.addEventListener('DOMContentLoaded', function() {
    console.log('Sistema de Vendas do Produto carregado!');
    console.log('Produto ID:', <?php echo $produto_id; ?>);
    console.log('Total de vendas encontradas:', <?php echo count($sales); ?>);
    
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
                    } else if (/^\d+$/.test(text.replace(/[.,]/g, ''))) {
                        finalNumber = parseInt(text.replace(/[.,]/g, ''));
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

    // Adiciona efeitos de hover nos botões
    const buttons = document.querySelectorAll('.action-button');
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
});

// Função de filtro da tabela
function filterTable() {
    var clientFilter = document.getElementById('filterClient').value;
    var statusFilter = document.getElementById('filterStatus').value;
    var nfFilter = document.getElementById('filterNF').value.toLowerCase();
    var dateFilter = document.getElementById('filterDate').value;
    var searchInput = document.getElementById('searchInput').value.toLowerCase();
    var table = document.getElementById('vendasTable');
    var tr = table.getElementsByTagName('tr');

    for (var i = 1; i < tr.length; i++) {
        var td = tr[i].getElementsByTagName('td');
        var showRow = true;

        // Filtro de cliente
        if (clientFilter) {
            var client = td[1].getAttribute('data-client') || '';
            if (client !== clientFilter) {
                showRow = false;
            }
        }

        // Filtro de status
        if (statusFilter && showRow) {
            var status = td[6].getAttribute('data-status') || '';
            if (status !== statusFilter) {
                showRow = false;
            }
        }

        // Filtro de NF
        if (nfFilter && showRow) {
            var nfText = td[0].textContent.toLowerCase();
            if (!nfText.includes(nfFilter)) {
                showRow = false;
            }
        }

        // Filtro de data
        if (dateFilter && showRow) {
            var rowDate = td[5].getAttribute('data-date');
            if (rowDate) {
                var rowMonth = rowDate.substring(0, 7);
                if (rowMonth !== dateFilter) {
                    showRow = false;
                }
            }
        }

        // Busca geral
        if (searchInput && showRow) {
            var textContent = tr[i].textContent || tr[i].innerText;
            if (!textContent.toLowerCase().includes(searchInput)) {
                showRow = false;
            }
        }

        tr[i].style.display = showRow ? '' : 'none';
    }
}

// Função de exportação para CSV
function exportToCSV() {
    var table = document.getElementById('vendasTable');
    var rows = table.querySelectorAll('tr');
    var csv = [];
    
    // Cabeçalho personalizado
    csv.push([
        '"Nota Fiscal"',
        '"Cliente"',
        '"Quantidade"',
        '"Valor Unitário"',
        '"Valor Total"',
        '"Data"',
        '"Status"'
    ].join(','));
    
    // Dados das linhas
    for (var i = 1; i < rows.length; i++) {
        if (rows[i].style.display !== 'none') { // Só exporta linhas visíveis
            var row = [], cols = rows[i].querySelectorAll('td');
            
            for (var j = 0; j < cols.length - 1; j++) { // -1 para excluir coluna de ações
                var data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/(\s\s)/gm, ' ');
                data = data.replace(/"/g, '""');
                row.push('"' + data + '"');
            }
            
            csv.push(row.join(','));
        }
    }
    
    var csv_string = csv.join('\n');
    var filename = 'vendas_produto_<?php echo $produto_id; ?>_' + new Date().toLocaleDateString().replace(/\//g, '-') + '.csv';
    var link = document.createElement('a');
    link.style.display = 'none';
    link.setAttribute('target', '_blank');
    link.setAttribute('href', 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv_string));
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    // Notificação de sucesso
    showNotification('Arquivo CSV exportado com sucesso!', 'success');
}

// Função para mostrar notificações
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? 'var(--success-color)' : type === 'warning' ? 'var(--warning-color)' : 'var(--info-color)'};
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
    `;
    
    document.body.appendChild(notification);
    
    // Remove após 5 segundos
    setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}

// Adiciona atalhos de teclado
document.addEventListener('keydown', function(e) {
    // Ctrl + P para imprimir
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        window.print();
    }
    
    // Ctrl + B para voltar
    if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
        e.preventDefault();
        window.location.href = 'consulta_produto.php';
    }
    
    // Escape para limpar filtros
    if (e.key === 'Escape') {
        document.getElementById('filterClient').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterDate').value = '';
        document.getElementById('searchInput').value = '';
        filterTable();
    }
});

// CSS adicional para animações
const additionalCSS = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }

    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
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
`;

// Adiciona CSS ao documento
const style = document.createElement('style');
style.textContent = additionalCSS;
document.head.appendChild(style);
</script>

</body>
</html>