<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Definindo a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

// Conexão com o banco de dados
require_once('db.php');

// Obtém o UASG do cliente a partir da URL
$cliente_uasg = $_GET['cliente_uasg'] ?? null;
$error = "";
$vendas = [];
$nome_cliente = "";

// Verifica se o UASG foi fornecido
if ($cliente_uasg) {
    try {
        // Busca o nome do cliente
        $sql = "SELECT nome_orgaos FROM clientes WHERE uasg = :cliente_uasg";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cliente_uasg', $cliente_uasg);
        $stmt->execute();
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
        $nome_cliente = $cliente ? $cliente['nome_orgaos'] : '';

        // Consulta as vendas com status "Recebido"
        $sql = "SELECT v.id, v.produto, v.valor_total AS valor, v.status_pagamento, v.data_vencimento
                FROM vendas v
                WHERE v.cliente_uasg = :cliente_uasg AND v.status_pagamento = 'Recebido'
                ORDER BY v.data_vencimento DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cliente_uasg', $cliente_uasg);
        $stmt->execute();
        
        $vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao consultar vendas recebidas: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contas Recebidas - <?php echo htmlspecialchars($cliente_uasg); ?></title>
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
            max-width: 1200px;
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
            text-align: center;
        }

        .page-header h2 {
            color: white;
            margin-bottom: 0.5rem;
            font-size: 1.8rem;
            font-weight: 600;
        }

        .page-header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* Mensagens de feedback */
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

        @keyframes slideInDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }

        .stat-card i {
            font-size: 2.5rem;
            color: var(--success-color);
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

        table tr:hover {
            background: var(--light-gray);
        }

        .status-badge {
            display: inline-block;
            padding: 0.375rem 0.875rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            background: var(--success-color);
            color: white;
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

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--medium-gray);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state p {
            font-size: 1.1rem;
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
        }

        @media (max-width: 480px) {
            .container {
                padding: 1rem;
                margin: 0.5rem;
            }

            .page-header h2 {
                font-size: 1.25rem;
            }

            .page-header p {
                font-size: 0.9rem;
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
        <h2><i class="fas fa-check-circle"></i> Contas Recebidas</h2>
        <p>Cliente UASG: <?php echo htmlspecialchars($cliente_uasg); ?> 
        <?php if($nome_cliente): ?>
            - <?php echo htmlspecialchars($nome_cliente); ?>
        <?php endif; ?>
        </p>
    </div>

    <?php if ($error): ?>
        <div class="error">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <?php if (count($vendas) > 0): ?>
        <!-- Estatísticas -->
        <div class="stats-container">
            <div class="stat-card">
                <i class="fas fa-file-invoice-dollar"></i>
                <h3>Total de Vendas Recebidas</h3>
                <div class="value"><?php echo count($vendas); ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-money-check-alt"></i>
                <h3>Valor Total Recebido</h3>
                <div class="value">
                    R$ <?php 
                        $total = array_sum(array_column($vendas, 'valor'));
                        echo number_format($total, 2, ',', '.'); 
                    ?>
                </div>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th><i class="fas fa-box"></i> Produto</th>
                        <th><i class="fas fa-dollar-sign"></i> Valor</th>
                        <th><i class="fas fa-info-circle"></i> Status</th>
                        <th><i class="fas fa-calendar-alt"></i> Data de Vencimento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vendas as $venda): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($venda['produto']); ?></td>
                            <td>R$ <?php echo number_format($venda['valor'], 2, ',', '.'); ?></td>
                            <td><span class="status-badge">Recebido</span></td>
                            <td><?php echo date('d/m/Y', strtotime($venda['data_vencimento'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p>Nenhuma venda recebida encontrada para este cliente.</p>
        </div>
    <?php endif; ?>

    <div class="btn-container">
        <a href="consultar_clientes.php" class="action-button secondary">
            <i class="fas fa-arrow-left"></i> Voltar para Clientes
        </a>
        <a href="consulta_contas_receber.php?cliente_uasg=<?php echo $cliente_uasg; ?>" class="action-button">
            <i class="fas fa-file-invoice"></i> Ver Todas as Contas
        </a>
    </div>
</div>

</body>
</html>