<?php
session_start();

$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Conexão com o banco de dados
require_once('db.php');

// Função auxiliar para evitar problemas com htmlspecialchars
function safe_htmlspecialchars($value) {
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Verifica se o parâmetro cliente_uasg foi enviado
$cliente_uasg = isset($_GET['cliente_uasg']) ? $_GET['cliente_uasg'] : null;
$nome_cliente = '';
$vendas = [];
$error = '';

if ($cliente_uasg) {
    // Busca o nome do cliente
    try {
        $sql = "SELECT nome_orgaos FROM clientes WHERE uasg = :cliente_uasg";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cliente_uasg', $cliente_uasg);
        $stmt->execute();
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
        $nome_cliente = $cliente ? $cliente['nome_orgaos'] : '';
    } catch (PDOException $e) {
        $error = "Erro ao buscar cliente: " . $e->getMessage();
    }

    // Consulta as vendas relacionadas ao cliente
    try {
    $sql = "SELECT v.id AS venda_id, vp.produto_id, p.nome AS produto_nome, 
            vp.quantidade, vp.valor_unitario, vp.valor_total, 
            v.status_pagamento, v.data_vencimento
            FROM vendas v
            JOIN venda_produtos vp ON v.id = vp.venda_id
            LEFT JOIN produtos p ON vp.produto_id = p.id
            WHERE v.cliente_uasg = :cliente_uasg
            ORDER BY v.data_vencimento DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':cliente_uasg', $cliente_uasg, PDO::PARAM_STR);
    $stmt->execute();

    $vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Erro ao consultar vendas: " . $e->getMessage();
}
}

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendas do Cliente - LicitaSis</title>
        <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Reset e variáveis CSS */
        :root {
            --primary-color: #2D893E;
            --primary-light: #9DCEAC;
            --secondary-color: #00bfae;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-gray: #f8f9fa;
            --medium-gray: #6c757d;
            --dark-gray: #343a40;
            --border-color: #dee2e6;
            --shadow: 0 2px 10px rgba(0,0,0,0.1);
            --radius: 8px;
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
            border-radius: 0 0 var(--radius) var(--radius);
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
            max-width: 1400px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .page-header {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            padding: 2rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .page-header h2 {
            color: white;
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .page-header .client-info {
            font-size: 1.1rem;
            opacity: 0.95;
        }

        .page-header .client-info strong {
            font-weight: 600;
        }

        /* Mensagens */
        .error {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            text-align: center;
            animation: slideInDown 0.3s ease;
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Estatísticas */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--light-gray), white);
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
            transition: var(--transition);
            border: 2px solid transparent;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }

        .stat-card.total i { color: var(--info-color); }
        .stat-card.revenue i { color: var(--success-color); }
        .stat-card.pending i { color: var(--warning-color); }
        .stat-card.received i { color: var(--success-color); }

        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .stat-card h3 {
            color: var(--medium-gray);
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .stat-card .value {
            color: var(--dark-gray);
            font-size: 1.8rem;
            font-weight: 700;
        }

        /* Filtros */
        .filters-container {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            font-weight: 600;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 0.625rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: var(--transition);
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
            background: var(--secondary-color);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
            white-space: nowrap;
        }

        table th i {
            margin-right: 0.5rem;
        }

        table tr:hover {
            background: var(--light-gray);
        }

        .status-badge {
            display: inline-block;
            padding: 0.375rem 0.875rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .status-badge.recebido {
            background: var(--success-color);
            color: white;
        }

        .status-badge.pendente {
            background: var(--warning-color);
            color: var(--dark-gray);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--medium-gray);
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state p {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
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
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .action-button:hover {
            background: #009d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
        }

        .action-button.secondary {
            background: var(--medium-gray);
        }

        .action-button.secondary:hover {
            background: var(--dark-gray);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .container {
                margin: 1rem;
                padding: 1.5rem;
            }

            .page-header {
                padding: 1.5rem;
            }

            .page-header h2 {
                font-size: 1.5rem;
            }

            .stats-container {
                grid-template-columns: 1fr 1fr;
                gap: 1rem;
            }

            .filters-container {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
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
        }

        @media (max-width: 480px) {
            .container {
                padding: 1rem;
                margin: 0.5rem;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .page-header h2 {
                font-size: 1.25rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-card .value {
                font-size: 1.5rem;
            }
        }

        /* Animação de entrada */
        .fade-in {
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Print styles */
        @media print {
            nav, .btn-container, header, .filters-container {
                display: none !important;
            }
            
            .container {
                margin: 0;
                box-shadow: none;
            }
            
            .page-header {
                background: none;
                color: var(--dark-gray);
                border: 1px solid var(--border-color);
            }
            
            table {
                font-size: 12pt;
            }
        }
    </style>
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

    <!-- Exibe o link para o cadastro de funcionários apenas para administradores -->
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

<div class="container fade-in">
    <div class="page-header">
        <h2><i class="fas fa-shopping-cart"></i> Consulta de Vendas</h2>
        <div class="client-info">
            <i class="fas fa-building"></i> Cliente UASG: <strong><?php echo safe_htmlspecialchars($cliente_uasg); ?></strong>
            <?php if($nome_cliente): ?>
                - <strong><?php echo safe_htmlspecialchars($nome_cliente); ?></strong>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($error) && $error): ?>
        <div class="error">
            <i class="fas fa-exclamation-circle"></i> <?php echo safe_htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($vendas)): ?>
        <!-- Estatísticas -->
        <div class="stats-container">
            <div class="stat-card total">
                <i class="fas fa-chart-line"></i>
                <h3>Total de Vendas</h3>
                <div class="value"><?php echo count($vendas); ?></div>
            </div>
            <div class="stat-card revenue">
                <i class="fas fa-dollar-sign"></i>
                <h3>Valor Total</h3>
                <div class="value">
                    R$ <?php 
                        $total = array_sum(array_column($vendas, 'valor_total'));
                        echo number_format($total, 2, ',', '.'); 
                    ?>
                </div>
            </div>
            <div class="stat-card pending">
                <i class="fas fa-clock"></i>
                <h3>Pendentes</h3>
                <div class="value">
                    <?php 
                        $pendentes = array_filter($vendas, function($v) { 
                            return isset($v['status_pagamento']) && $v['status_pagamento'] != 'Recebido'; 
                        });
                        echo count($pendentes); 
                    ?>
                </div>
            </div>
            <div class="stat-card received">
                <i class="fas fa-check-circle"></i>
                <h3>Recebidas</h3>
                <div class="value">
                    <?php 
                        $recebidas = array_filter($vendas, function($v) { 
                            return isset($v['status_pagamento']) && $v['status_pagamento'] == 'Recebido'; 
                        });
                        echo count($recebidas); 
                    ?>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filters-container">
            <div class="filter-group">
                <label for="filterStatus">Filtrar por Status:</label>
                <select id="filterStatus" onchange="filterTable()">
                    <option value="">Todos</option>
                    <option value="Recebido">Recebido</option>
                    <option value="Pendente">Pendente</option>
                </select>
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

        <div class="table-container">
            <table id="vendasTable">
                <thead>
                    <tr>
                        <th><i class="fas fa-hashtag"></i> Número</th>
                        <th><i class="fas fa-building"></i> Cliente</th>
                        <th><i class="fas fa-box"></i> Produto</th>
                        <th><i class="fas fa-truck"></i> Transportadora</th>
                        <th><i class="fas fa-dollar-sign"></i> Valor Total</th>
                        <th><i class="fas fa-calendar"></i> Data</th>
                        <th><i class="fas fa-info-circle"></i> Status</th>
                        <th><i class="fas fa-comment"></i> Observação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vendas as $venda): ?>
                        <tr>
                            <td><?php echo safe_htmlspecialchars($venda['numero'] ?? ''); ?></td>
                            <td><?php echo safe_htmlspecialchars($venda['cliente'] ?? ''); ?></td>
                            <td><?php echo safe_htmlspecialchars($venda['produto_nome'] ?? $venda['produto'] ?? ''); ?></td>
                            <td><?php echo safe_htmlspecialchars($venda['transportadora_nome'] ?? $venda['transportadora'] ?? ''); ?></td>
                            <td style="font-weight: 600; color: var(--success-color);">
                                R$ <?php echo number_format((float)($venda['valor_total'] ?? 0), 2, ',', '.'); ?>
                            </td>
                            <td data-date="<?php echo safe_htmlspecialchars($venda['data'] ?? ''); ?>">
                                <?php echo isset($venda['data']) && $venda['data'] ? date('d/m/Y', strtotime($venda['data'])) : '-'; ?>
                            </td>
                            <td>
                                <?php if (isset($venda['status_pagamento']) && $venda['status_pagamento'] == 'Recebido'): ?>
                                    <span class="status-badge recebido">
                                        <i class="fas fa-check"></i> Recebido
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge pendente">
                                        <i class="fas fa-clock"></i> Pendente
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo safe_htmlspecialchars($venda['observacao'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p>Nenhuma venda encontrada para este cliente.</p>
            <p style="font-size: 1rem; color: var(--medium-gray);">
                Verifique se existem vendas cadastradas para o cliente UASG: <?php echo safe_htmlspecialchars($cliente_uasg); ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="btn-container">
        <a href="consultar_clientes.php" class="action-button secondary">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <?php if (!empty($vendas)): ?>
            <button onclick="window.print()" class="action-button">
                <i class="fas fa-print"></i> Imprimir
            </button>
            <a href="consulta_contas_receber.php?cliente_uasg=<?php echo urlencode($cliente_uasg ?? ''); ?>" class="action-button">
                <i class="fas fa-file-invoice-dollar"></i> Contas a Receber
            </a>
        <?php endif; ?>
    </div>
</div>

<script>
function filterTable() {
    var statusFilter = document.getElementById('filterStatus').value;
    var dateFilter = document.getElementById('filterDate').value;
    var searchInput = document.getElementById('searchInput').value.toLowerCase();
    var table = document.getElementById('vendasTable');
    var tr = table.getElementsByTagName('tr');

    for (var i = 1; i < tr.length; i++) {
        var td = tr[i].getElementsByTagName('td');
        var showRow = true;

        // Filtro de status
        if (statusFilter) {
            var status = td[6].textContent || td[6].innerText;
            if (!status.includes(statusFilter)) {
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
</script>

</body>
</html>