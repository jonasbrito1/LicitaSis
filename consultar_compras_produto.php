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
    // Verifica se o usuário tem permissão para acessar compras
    $permissionManager->requirePermission('compras', 'view');
}

if (file_exists('includes/audit.php')) {
    include('includes/audit.php');
    // Registra acesso à página
    logUserAction('READ', 'compras_produto_dashboard');
}

// Verifica se o usuário é administrador
$isAdmin = (isset($_SESSION['user']) && $_SESSION['user']['permission'] === 'Administrador');

$error = "";
$success = "";
$purchases = [];
$produto_id = isset($_GET['produto_id']) ? intval($_GET['produto_id']) : 0;
$produto_nome = '';
$produto_info = null;
$debug_info = [];

// Função para descobrir as colunas de uma tabela
function getTableColumns($pdo, $tableName) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tableName`");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $columns;
    } catch (PDOException $e) {
        return [];
    }
}

// Função para verificar se uma tabela existe
function tableExists($pdo, $tableName) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Busca informações completas do produto
if ($produto_id > 0) {
    try {
        $sql = "SELECT p.*, 
                       f.nome as fornecedor_nome,
                       f.cnpj as fornecedor_cnpj,
                       f.codigo as fornecedor_codigo,
                       f.telefone as fornecedor_telefone,
                       f.email as fornecedor_email
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

// CONSULTA CORRIGIDA - Descobre a estrutura das tabelas primeiro
if ($produto_id > 0 && !$error) {
    try {
        // Verifica se existe a tabela produto_compra
        $hasProdutoCompraTable = tableExists($pdo, 'produto_compra');
        $hasComprasTable = tableExists($pdo, 'compras');
        
        $debug_info[] = "Tabela produto_compra existe: " . ($hasProdutoCompraTable ? 'Sim' : 'Não');
        $debug_info[] = "Tabela compras existe: " . ($hasComprasTable ? 'Sim' : 'Não');
        
        if (!$hasProdutoCompraTable) {
            $error = "Tabela produto_compra não encontrada no sistema.";
        } else {
            // Descobre as colunas das tabelas
            $produtoCompraColumns = getTableColumns($pdo, 'produto_compra');
            $comprasColumns = $hasComprasTable ? getTableColumns($pdo, 'compras') : [];
            
            $debug_info[] = "Colunas produto_compra: " . implode(', ', $produtoCompraColumns);
            if ($hasComprasTable) {
                $debug_info[] = "Colunas compras: " . implode(', ', $comprasColumns);
            }
            
            // Consulta CORRIGIDA baseada na estrutura real das tabelas
            $sql = "SELECT 
                        pc.id,
                        pc.compra_id,
                        pc.quantidade,
                        pc.valor_unitario,
                        pc.valor_total,
                        -- Campos da tabela compras (estrutura real)
                        COALESCE(c.data, NOW()) as data_compra,
                        COALESCE(c.numero_nf, 'N/A') as numero_nota,
                        'Registrado' as status,
                        COALESCE(c.observacao, '') as observacoes,
                        -- Informações do fornecedor da compra
                        COALESCE(c.fornecedor, 'Fornecedor não informado') as fornecedor_compra,
                        -- Informações do fornecedor do produto (preferencial)
                        COALESCE(pf.nome, c.fornecedor, 'Fornecedor não informado') as fornecedor_nome,
                        COALESCE(pf.cnpj, 'N/A') as fornecedor_cnpj,
                        COALESCE(pf.codigo, 'N/A') as fornecedor_codigo,
                        COALESCE(pf.telefone, 'N/A') as fornecedor_telefone,
                        COALESCE(pf.email, 'N/A') as fornecedor_email,
                        -- Dados adicionais da compra
                        COALESCE(c.frete, 0) as frete,
                        COALESCE(c.numero_empenho, 'N/A') as numero_empenho,
                        COALESCE(c.link_pagamento, '') as link_pagamento,
                        COALESCE(c.comprovante_pagamento, '') as comprovante_pagamento,
                        COALESCE(c.created_at, NOW()) as data_registro
                FROM produto_compra pc
                INNER JOIN produtos p ON pc.produto_id = p.id
                LEFT JOIN fornecedores pf ON p.fornecedor = pf.id
                LEFT JOIN compras c ON pc.compra_id = c.id
                WHERE pc.produto_id = :produto_id
                ORDER BY COALESCE(c.data, c.created_at, pc.id) DESC";
            
            $debug_info[] = "Query montada: " . substr($sql, 0, 200) . "...";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
            $stmt->execute();
            $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $debug_info[] = "Registros encontrados: " . count($purchases);
            
            if (!empty($purchases)) {
                $success = "Dados carregados com sucesso! Encontradas " . count($purchases) . " compra(s).";
            }
        }
        
    } catch (PDOException $e) {
        $error = "Erro na consulta de compras: " . $e->getMessage();
        $debug_info[] = "Erro SQL: " . $e->getMessage();
        error_log("Erro na consulta compras produto ID $produto_id: " . $e->getMessage());
        
        // Consulta de fallback mais simples
        try {
            $sqlFallback = "SELECT 
                            pc.id,
                            pc.compra_id,
                            COALESCE(pc.quantidade, 1) as quantidade,
                            COALESCE(pc.valor_unitario, 0) as valor_unitario,
                            COALESCE(pc.valor_total, pc.valor_unitario * pc.quantidade, 0) as valor_total,
                            NOW() as data_compra,
                            'N/A' as numero_nota,
                            'Registrado' as status,
                            '' as observacoes,
                            COALESCE(pf.nome, 'Fornecedor não informado') as fornecedor_nome,
                            COALESCE(pf.cnpj, 'N/A') as fornecedor_cnpj,
                            COALESCE(pf.codigo, 'N/A') as fornecedor_codigo,
                            COALESCE(pf.telefone, 'N/A') as fornecedor_telefone,
                            COALESCE(pf.email, 'N/A') as fornecedor_email,
                            NOW() as data_registro
                        FROM produto_compra pc
                        LEFT JOIN produtos p ON pc.produto_id = p.id
                        LEFT JOIN fornecedores pf ON p.fornecedor = pf.id
                        WHERE pc.produto_id = :produto_id
                        ORDER BY pc.id DESC";
            
            $stmtFallback = $pdo->prepare($sqlFallback);
            $stmtFallback->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
            $stmtFallback->execute();
            $purchases = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($purchases)) {
                $error = ""; // Limpa o erro se conseguiu recuperar dados
                $success = "Dados recuperados com consulta simplificada (fallback).";
                $debug_info[] = "Fallback executado com sucesso";
            }
        } catch (PDOException $e2) {
            $debug_info[] = "Erro no fallback: " . $e2->getMessage();
            error_log("Erro no fallback: " . $e2->getMessage());
        }
    }
}

// Inclui o template de header se existir
if (file_exists('includes/header_template.php')) {
    include('includes/header_template.php');
    renderHeader("Compras do Produto - LicitaSis", "compras");
} else {
    // Header básico se não existir o template
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Compras do Produto - LicitaSis</title>
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

        <!-- Filtros -->
        <div class="filters-container">
            <div class="filters-title">
                <i class="fas fa-filter"></i> Filtros de Pesquisa
            </div>
            <div class="filters-grid">
                <div class="filter-group">
                    <label for="filterCompraId">Filtrar por ID da Compra:</label>
                    <select id="filterCompraId" onchange="filterTable()">
                        <option value="">Todas as compras</option>
                        <?php 
                            $comprasUnicas = array_unique(array_filter(array_column($purchases, 'compra_id')));
                            sort($comprasUnicas);
                            foreach ($comprasUnicas as $compraId): 
                                if ($compraId != 'N/A' && !empty($compraId)):
                        ?>
                            <option value="<?php echo htmlspecialchars($compraId); ?>">
                                Compra #<?php echo htmlspecialchars($compraId); ?>
                            </option>
                        <?php 
                                endif;
                            endforeach; 
                        ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filterFornecedor">Filtrar por Fornecedor:</label>
                    <select id="filterFornecedor" onchange="filterTable()">
                        <option value="">Todos os fornecedores</option>
                        <?php 
                            $fornecedoresUnicos = array_unique(array_filter(array_column($purchases, 'fornecedor_nome')));
                            $fornecedoresUnicos = array_filter($fornecedoresUnicos, function($f) { 
                                return $f != 'N/A' && $f != 'Fornecedor não informado' && !empty($f); 
                            });
                            sort($fornecedoresUnicos);
                            foreach ($fornecedoresUnicos as $fornecedor): 
                        ?>
                            <option value="<?php echo htmlspecialchars($fornecedor); ?>">
                                <?php echo htmlspecialchars($fornecedor); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filterValor">Filtrar por Valor:</label>
                    <select id="filterValor" onchange="filterTable()">
                        <option value="">Todos os valores</option>
                        <option value="0-100">R$ 0 - R$ 100</option>
                        <option value="100-500">R$ 100 - R$ 500</option>
                        <option value="500-1000">R$ 500 - R$ 1.000</option>
                        <option value="1000+">Acima de R$ 1.000</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="searchInput">Buscar:</label>
                    <input type="text" id="searchInput" placeholder="Buscar em todos os campos..." onkeyup="filterTable()">
                </div>
            </div>
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
        <div class="dropdown">
            <a href="licitacoes.php">Licitações</a>
            <div class="dropdown-content">
                <a href="cadastro_licitacao.php">Nova Licitação</a>
                <a href="consulta_licitacao.php">Consultar Licitações</a>
                <a href="processos_licitacao.php">Processos</a>
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
        /* CSS completo do sistema - Incluindo Header e Nav Original */
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

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .page-header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-color);
        }

        .page-header h2 {
            color: var(--primary-color);
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
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

        .error, .success {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            font-weight: 500;
            text-align: center;
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

        .debug-alert {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .debug-alert h4 {
            margin-bottom: 0.5rem;
            color: #856404;
        }

        .debug-alert ul {
            margin-left: 1rem;
        }

        .debug-alert li {
            margin-bottom: 0.25rem;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, white 0%, #f8f9fa 100%);
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
            transition: var(--transition);
            border-left: 4px solid var(--secondary-color);
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

        .stat-card.purchases .stat-icon { color: var(--info-color); }
        .stat-card.cost .stat-icon { color: var(--danger-color); }
        .stat-card.quantity .stat-icon { color: var(--warning-color); }
        .stat-card.suppliers .stat-icon { color: var(--success-color); }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            line-height: 1;
            font-family: 'Courier New', monospace;
        }

        .stat-card.purchases .stat-number { color: var(--info-color); }
        .stat-card.cost .stat-number { color: var(--danger-color); }
        .stat-card.quantity .stat-number { color: var(--warning-color); }
        .stat-card.suppliers .stat-number { color: var(--success-color); }

        .stat-label {
            color: var(--medium-gray);
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

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
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        table th {
            background: linear-gradient(135deg, var(--secondary-color), var(--secondary-dark));
            color: white;
            font-weight: 600;
            white-space: nowrap;
        }

        table th i {
            margin-right: 0.5rem;
        }

        table tr:hover {
            background: var(--light-gray);
        }

        table td.currency {
            font-weight: 600;
            color: var(--danger-color);
            font-family: 'Courier New', monospace;
        }

        .supplier-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .supplier-name {
            font-weight: 600;
            color: var(--dark-gray);
        }

        .supplier-details {
            font-size: 0.8rem;
            color: var(--medium-gray);
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }

        .supplier-details span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .supplier-details i {
            width: 12px;
            font-size: 0.75rem;
            color: var(--secondary-color);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-weight: 600;
        }

        .status-pago {
            color: var(--success-color);
            background: rgba(40, 167, 69, 0.1);
        }

        .status-pendente {
            color: var(--warning-color);
            background: rgba(255, 193, 7, 0.1);
        }

        .status-registrado {
            color: var(--info-color);
            background: rgba(23, 162, 184, 0.1);
        }

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

        .action-button.small {
            padding: 0.5rem 0.75rem;
            font-size: 0.85rem;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .container {
                padding: 1.5rem;
                margin: 1rem;
            }

            .page-header h2 {
                font-size: 1.5rem;
            }

            .stats-container {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .product-info {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 0.875rem;
            }

            table th, table td {
                padding: 0.5rem;
            }

            .btn-container {
                flex-direction: column;
            }

            .action-button {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="page-header">
        <h2><i class="fas fa-shopping-bag"></i> Compras do Produto</h2>
        
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
                    <strong>Código:</strong> <?php echo htmlspecialchars($produto_info['codigo'] ?? 'N/A'); ?>
                </div>
            </div>
            <div class="product-info-item">
                <i class="fas fa-warehouse"></i>
                <div>
                    <strong>Estoque Atual:</strong> 
                    <?php echo isset($produto_info['estoque_atual']) ? number_format($produto_info['estoque_atual'], 2, ',', '.') : 'N/A'; ?>
                </div>
            </div>
            <div class="product-info-item">
                <i class="fas fa-dollar-sign"></i>
                <div>
                    <strong>Preço Unit.:</strong> R$ <?php echo number_format($produto_info['preco_unitario'] ?? 0, 2, ',', '.'); ?>
                </div>
            </div>
            <?php if ($produto_info['fornecedor_nome']): ?>
            <div class="product-info-item">
                <i class="fas fa-industry"></i>
                <div>
                    <strong>Fornecedor:</strong> <?php echo htmlspecialchars($produto_info['fornecedor_nome']); ?>
                </div>
            </div>
            <?php endif; ?>
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

    <!-- Debug info -->
    <?php if (!empty($debug_info) && (isset($_GET['debug']) || $error)): ?>
        <div class="debug-alert">
            <h4><i class="fas fa-bug"></i> Informações de Debug:</h4>
            <ul>
                <?php foreach ($debug_info as $info): ?>
                    <li><?php echo htmlspecialchars($info); ?></li>
                <?php endforeach; ?>
            </ul>
            <small><strong>Dica:</strong> Para remover estas informações, remova o parâmetro ?debug=1 da URL</small>
        </div>
    <?php endif; ?>

    <?php if (count($purchases) > 0): ?>
        <!-- Estatísticas -->
        <div class="stats-container">
            <div class="stat-card purchases">
                <i class="stat-icon fas fa-receipt"></i>
                <div class="stat-number"><?php echo count($purchases); ?></div>
                <div class="stat-label">Total de Compras</div>
            </div>
            <div class="stat-card cost">
                <i class="stat-icon fas fa-coins"></i>
                <div class="stat-number">
                    R$ <?php 
                        $total = 0;
                        $totalFrete = 0;
                        foreach ($purchases as $purchase) {
                            $total += floatval($purchase['valor_total'] ?? 0);
                            $totalFrete += floatval($purchase['frete'] ?? 0);
                        }
                        echo number_format($total, 2, ',', '.'); 
                    ?>
                </div>
                <div class="stat-label">Custo Total (Produtos)</div>
            </div>
            <div class="stat-card quantity">
                <i class="stat-icon fas fa-cubes"></i>
                <div class="stat-number">
                    <?php 
                        $totalQty = 0;
                        foreach ($purchases as $purchase) {
                            $totalQty += intval($purchase['quantidade'] ?? 0);
                        }
                        echo number_format($totalQty, 0, ',', '.'); 
                    ?>
                </div>
                <div class="stat-label">Quantidade Total</div>
            </div>
            <div class="stat-card suppliers">
                <i class="stat-icon fas fa-truck"></i>
                <div class="stat-number">
                    R$ <?php echo number_format($totalFrete, 2, ',', '.'); ?>
                </div>
                <div class="stat-label">Total Frete</div>
            </div>
        </div>

        <div class="table-container">
            <table id="comprasTable">
                <thead>
                    <tr>
                        <th><i class="fas fa-hashtag"></i> ID Compra</th>
                        <th><i class="fas fa-file-invoice"></i> Nota Fiscal</th>
                        <th><i class="fas fa-industry"></i> Fornecedor</th>
                        <th><i class="fas fa-boxes"></i> Quantidade</th>
                        <th><i class="fas fa-tag"></i> Valor Unitário</th>
                        <th><i class="fas fa-money-check-alt"></i> Valor Total</th>
                        <th><i class="fas fa-truck"></i> Frete</th>
                        <th><i class="fas fa-calendar"></i> Data</th>
                        <th><i class="fas fa-cogs"></i> Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($purchases as $purchase): ?>
                        <tr>
                            <td>
                                <strong>#<?php echo htmlspecialchars($purchase['compra_id'] ?? 'N/A'); ?></strong>
                                <br><small style="color: var(--medium-gray);">Item ID: <?php echo htmlspecialchars($purchase['id']); ?></small>
                                <?php if (!empty($purchase['numero_empenho']) && $purchase['numero_empenho'] != 'N/A'): ?>
                                    <br><small style="color: var(--info-color);">
                                        <i class="fas fa-receipt"></i> Empenho: <?php echo htmlspecialchars($purchase['numero_empenho']); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($purchase['numero_nota'] ?? 'N/A'); ?></strong>
                                <?php if (!empty($purchase['link_pagamento'])): ?>
                                    <br><small>
                                        <a href="<?php echo htmlspecialchars($purchase['link_pagamento']); ?>" 
                                           target="_blank" 
                                           style="color: var(--secondary-color);">
                                            <i class="fas fa-external-link-alt"></i> Link Pagamento
                                        </a>
                                    </small>
                                <?php endif; ?>
                                <?php if (!empty($purchase['comprovante_pagamento'])): ?>
                                    <br><small style="color: var(--success-color);">
                                        <i class="fas fa-check-circle"></i> Comprovante: Sim
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="supplier-info">
                                    <div class="supplier-name">
                                        <?php echo htmlspecialchars($purchase['fornecedor_nome'] ?? 'Fornecedor não informado'); ?>
                                    </div>
                                  
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <strong><?php echo number_format(intval($purchase['quantidade'] ?? 0), 0, ',', '.'); ?></strong>
                            </td>
                            <td class="currency">
                                R$ <?php echo number_format(floatval($purchase['valor_unitario'] ?? 0), 2, ',', '.'); ?>
                            </td>
                            <td class="currency">
                                <strong>R$ <?php echo number_format(floatval($purchase['valor_total'] ?? 0), 2, ',', '.'); ?></strong>
                            </td>
                            <td class="currency">
                                <?php 
                                    $frete = floatval($purchase['frete'] ?? 0);
                                    if ($frete > 0): 
                                ?>
                                    <span style="color: var(--warning-color);">
                                        R$ <?php echo number_format($frete, 2, ',', '.'); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--medium-gray); font-size: 0.85rem;">Sem frete</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                    $data = $purchase['data_compra'] ?? $purchase['data_registro'] ?? null;
                                    if (!empty($data) && $data !== 'N/A' && $data !== '0000-00-00') {
                                        try {
                                            echo date('d/m/Y', strtotime($data)); 
                                        } catch (Exception $e) {
                                            echo '<span style="color: var(--medium-gray);">Data inválida</span>';
                                        }
                                    } else {
                                        echo '<span style="color: var(--medium-gray);">Data não informada</span>';
                                    }
                                ?>
                            </td>
                            <td>
                                <?php if (!empty($purchase['compra_id']) && $purchase['compra_id'] != 'N/A'): ?>
                                    <a href="detalhes_compra.php?id=<?php echo intval($purchase['compra_id']); ?>" 
                                       class="action-button small" 
                                       title="Ver detalhes da compra">
                                        <i class="fas fa-eye"></i> Ver
                                    </a>
                                    <?php if (!empty($purchase['link_pagamento'])): ?>
                                        <br>
                                        <a href="<?php echo htmlspecialchars($purchase['link_pagamento']); ?>" 
                                           target="_blank"
                                           class="action-button small secondary" 
                                           style="margin-top: 0.25rem;"
                                           title="Acessar link de pagamento">
                                            <i class="fas fa-credit-card"></i> Pagar
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: var(--medium-gray); font-size: 0.85rem;">N/A</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Análise de Preços -->
        <div style="background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); color: white; padding: 2rem; border-radius: var(--radius); margin-top: 2rem; box-shadow: var(--shadow);">
            <h3 style="color: white; margin-bottom: 1.5rem; font-size: 1.4rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-chart-line"></i> Análise de Compras
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.9rem; margin-bottom: 0.5rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500;">Preço Médio por Unidade</div>
                    <div style="font-size: 1.6rem; font-weight: 700; font-family: 'Courier New', monospace;">
                        R$ <?php 
                            $precoMedio = ($totalQty != 0) ? $total / $totalQty : 0;
                            echo number_format($precoMedio, 2, ',', '.'); 
                        ?>
                    </div>
                </div>
                <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.9rem; margin-bottom: 0.5rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500;">Maior Compra</div>
                    <div style="font-size: 1.6rem; font-weight: 700; font-family: 'Courier New', monospace;">
                        R$ <?php 
                            $valores = array_column($purchases, 'valor_total');
                            $valores = array_filter($valores, function($v) { return $v > 0; });
                            echo !empty($valores) ? number_format(max($valores), 2, ',', '.') : '0,00'; 
                        ?>
                    </div>
                </div>
                <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.9rem; margin-bottom: 0.5rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500;">Menor Compra</div>
                    <div style="font-size: 1.6rem; font-weight: 700; font-family: 'Courier New', monospace;">
                        R$ <?php echo !empty($valores) ? number_format(min($valores), 2, ',', '.') : '0,00'; ?>
                    </div>
                </div>
                <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.1); border-radius: var(--radius-sm);">
                    <div style="font-size: 0.9rem; margin-bottom: 0.5rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500;">Ticket Médio</div>
                    <div style="font-size: 1.6rem; font-weight: 700; font-family: 'Courier New', monospace;">
                        R$ <?php 
                            $ticketMedio = (count($purchases) != 0) ? $total / count($purchases) : 0;
                            echo number_format($ticketMedio, 2, ',', '.'); 
                        ?>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p>Nenhuma compra encontrada para este produto.</p>
            <p style="font-size: 1rem; color: var(--medium-gray); margin-top: 0.5rem;">
                Verifique se existem compras cadastradas na tabela produto_compra ou se o produto está correto.
            </p>
            <?php if (!empty($debug_info)): ?>
                <div style="margin-top: 1rem; padding: 1rem; background: rgba(255,255,255,0.5); border-radius: var(--radius-sm); text-align: left; font-size: 0.85rem;">
                    <strong>Informações técnicas:</strong>
                    <ul style="margin-left: 1rem; margin-top: 0.5rem;">
                        <?php foreach ($debug_info as $info): ?>
                            <li><?php echo htmlspecialchars($info); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="btn-container">
        <a href="consulta_produto.php" class="action-button secondary">
            <i class="fas fa-arrow-left"></i> Voltar para Produtos
        </a>
        
        <?php if (count($purchases) > 0): ?>
            <button onclick="window.print()" class="action-button">
                <i class="fas fa-print"></i> Imprimir Relatório
            </button>
            <button onclick="exportToCSV()" class="action-button">
                <i class="fas fa-file-csv"></i> Exportar CSV
            </button>
        <?php endif; ?>
        
        <?php if ($produto_id > 0): ?>
            <a href="detalhes_produto.php?id=<?php echo $produto_id; ?>" class="action-button">
                <i class="fas fa-info-circle"></i> Detalhes do Produto
            </a>
            <a href="cadastro_compras.php?produto_id=<?php echo $produto_id; ?>" class="action-button">
                <i class="fas fa-plus"></i> Nova Compra
            </a>
        <?php endif; ?>
        
        
    </div>
</div>

<script>
// JavaScript para funcionalidades da página
document.addEventListener('DOMContentLoaded', function() {
    console.log('Sistema de Compras do Produto carregado!');
    console.log('Produto ID:', <?php echo $produto_id; ?>);
    console.log('Total de compras encontradas:', <?php echo count($purchases); ?>);
    
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
    });
});

// Função de exportação para CSV
function exportToCSV() {
    var table = document.getElementById('comprasTable');
    if (!table) {
        showNotification('Tabela não encontrada!', 'error');
        return;
    }
    
    var rows = table.querySelectorAll('tr');
    var csv = [];
    
    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll('td, th');
        
        for (var j = 0; j < cols.length - 1; j++) { // -1 para excluir coluna de ações
            var data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/(\s\s)/gm, ' ');
            data = data.replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        
        csv.push(row.join(','));
    }
    
    var csv_string = csv.join('\n');
    var filename = 'compras_produto_<?php echo $produto_id; ?>_' + new Date().toLocaleDateString('pt-BR').replace(/\//g, '-') + '.csv';
    var link = document.createElement('a');
    link.style.display = 'none';
    link.setAttribute('target', '_blank');
    link.setAttribute('href', 'data:text/csv;charset=utf-8,%EF%BB%BF' + encodeURIComponent(csv_string));
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
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    const bgColor = type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8';
    
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${bgColor};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        z-index: 1000;
        animation: slideInRight 0.3s ease;
        max-width: 400px;
    `;
    
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
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
    document.head.appendChild(style);
    
    document.body.appendChild(notification);
    
    // Remove após 5 segundos
    setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}

// Atalhos de teclado
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
    
    // Ctrl + E para exportar
    if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
        e.preventDefault();
        if (document.getElementById('comprasTable')) {
            exportToCSV();
        }
    }
});

// Função para resolver problemas de estrutura de banco
function suggestDatabaseFix() {
    const suggestions = [
        "1. Verifique se a tabela 'produto_compra' existe no banco de dados",
        "2. Verifique se a tabela 'compras' existe (opcional, mas recomendada)",
        "3. Certifique-se de que existe pelo menos uma coluna de data na tabela 'compras'",
        "4. Verifique se os relacionamentos entre as tabelas estão corretos",
        "5. Execute o script SQL para criar as tabelas necessárias"
    ];
    
    console.log("=== SUGESTÕES PARA CORRIGIR PROBLEMAS DE BANCO ===");
    suggestions.forEach(suggestion => console.log(suggestion));
    
    return suggestions;
}

// Executa sugestões se houver erro
<?php if ($error): ?>
    console.error("Erro detectado: <?php echo addslashes($error); ?>");
    suggestDatabaseFix();
<?php endif; ?>

</script>

</body>
</html>