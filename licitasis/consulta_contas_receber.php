<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

// Conexão com o banco de dados
require_once('db.php');

// Obtém o UASG do cliente a partir da URL
$cliente_uasg = $_GET['cliente_uasg'] ?? null;
$error = "";
$vendas = [];
$nome_cliente = "";

// Função para atualizar o status de pagamento
if (isset($_GET['atualizar_status']) && isset($_GET['venda_id'])) {
    $venda_id = $_GET['venda_id'];
    try {
        // Obtém o status atual
        $sql = "SELECT status_pagamento FROM vendas WHERE id = :venda_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':venda_id', $venda_id, PDO::PARAM_INT);
        $stmt->execute();
        $venda = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($venda['status_pagamento'] == 'Recebido') {
            $novo_status = 'Não Recebido';
        } else {
            $novo_status = 'Recebido';
        }

        // Atualiza o status de pagamento
        $sql = "UPDATE vendas SET status_pagamento = :novo_status WHERE id = :venda_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':venda_id', $venda_id, PDO::PARAM_INT);
        $stmt->bindValue(':novo_status', $novo_status, PDO::PARAM_STR);
        $stmt->execute();

        // Atualiza a página
        header("Location: ".$_SERVER['PHP_SELF']."?cliente_uasg=$cliente_uasg");
        exit();

    } catch (PDOException $e) {
        $error = "Erro ao atualizar status: " . $e->getMessage();
    }
}

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

        // Consulta as vendas daquele cliente
        $sql = "SELECT v.id, v.produto, v.valor_total AS valor, v.status_pagamento, v.data_vencimento,
                p.nome as produto_nome
                FROM vendas v
                LEFT JOIN produtos p ON v.produto = p.id
                WHERE v.cliente_uasg = :cliente_uasg
                ORDER BY v.status_pagamento DESC, v.data_vencimento ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cliente_uasg', $cliente_uasg);
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
<title>Contas a Receber - <?php echo htmlspecialchars($cliente_uasg ?? ''); ?></title>
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

        @keyframes slideInDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Estatísticas */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        .stat-card.received i { color: var(--success-color); }
        .stat-card.pending i { color: var(--warning-color); }
        .stat-card.overdue i { color: var(--danger-color); }

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
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            text-align: center;
            min-width: 120px;
        }

        .status-badge.recebido {
            background: var(--success-color);
            color: white;
        }

        .status-badge.recebido:hover {
            background: #218838;
            transform: scale(1.05);
        }

        .status-badge.pendente {
            background: var(--danger-color);
            color: white;
        }

        .status-badge.pendente:hover {
            background: #c82333;
            transform: scale(1.05);
        }

        /* Valor com status */
        .valor-recebido {
            color: var(--success-color);
            font-weight: 600;
        }

        .valor-pendente {
            color: var(--danger-color);
            font-weight: 600;
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

        .action-button.info {
            background: var(--info-color);
        }

        .action-button.info:hover {
            background: #138496;
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
        }

        /* Data vencida */
        .overdue {
            color: var(--danger-color);
            font-weight: 600;
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

            table {
                font-size: 0.875rem;
            }

            table th, table td {
                padding: 0.75rem 0.5rem;
            }

            .status-badge {
                min-width: 100px;
                font-size: 0.8rem;
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

            .page-header .client-info {
                font-size: 0.95rem;
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
            nav, .btn-container, header, .action-column {
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
            
            .status-badge {
                border: 1px solid var(--border-color);
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
        <h2><i class="fas fa-file-invoice-dollar"></i> Contas a Receber</h2>
        <div class="client-info">
<i class="fas fa-building"></i> Cliente UASG: <strong><?php echo htmlspecialchars($cliente_uasg ?? ''); ?></strong>
            <?php if($nome_cliente): ?>
                - <strong><?php echo htmlspecialchars($nome_cliente); ?></strong>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="error">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <?php if (count($vendas) > 0): ?>
        <!-- Estatísticas -->
        <div class="stats-container">
            <div class="stat-card total">
                <i class="fas fa-chart-bar"></i>
                <h3>Total de Vendas</h3>
                <div class="value"><?php echo count($vendas); ?></div>
            </div>
            <div class="stat-card received">
                <i class="fas fa-check-circle"></i>
                <h3>Valor Recebido</h3>
                <div class="value">
                    R$ <?php 
                        $recebido = array_sum(array_column(array_filter($vendas, function($v) {
                            return $v['status_pagamento'] == 'Recebido';
                        }), 'valor'));
                        echo number_format($recebido, 2, ',', '.'); 
                    ?>
                </div>
            </div>
            <div class="stat-card pending">
                <i class="fas fa-clock"></i>
                <h3>Valor Pendente</h3>
                <div class="value">
                    R$ <?php 
                        $pendente = array_sum(array_column(array_filter($vendas, function($v) {
                            return $v['status_pagamento'] != 'Recebido';
                        }), 'valor'));
                        echo number_format($pendente, 2, ',', '.'); 
                    ?>
                </div>
            </div>
            <div class="stat-card overdue">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Vencidas</h3>
                <div class="value">
                    <?php 
                        $vencidas = array_filter($vendas, function($v) {
                            return $v['status_pagamento'] != 'Recebido' && strtotime($v['data_vencimento']) < time();
                        });
                        echo count($vencidas); 
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
                        <th><i class="fas fa-calendar-alt"></i> Vencimento</th>
                        <th class="action-column"><i class="fas fa-cogs"></i> Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vendas as $venda): 
                        $isOverdue = $venda['status_pagamento'] != 'Recebido' && strtotime($venda['data_vencimento']) < time();
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($venda['produto_nome'] ?? $venda['produto']); ?></td>
                            <td class="<?php echo $venda['status_pagamento'] == 'Recebido' ? 'valor-recebido' : 'valor-pendente'; ?>">
                                R$ <?php echo number_format($venda['valor'], 2, ',', '.'); ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $venda['status_pagamento'] == 'Recebido' ? 'recebido' : 'pendente'; ?>">
                                    <?php if ($venda['status_pagamento'] == 'Recebido'): ?>
                                        <i class="fas fa-check"></i> Recebido
                                    <?php else: ?>
                                        <i class="fas fa-times"></i> Não Recebido
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td class="<?php echo $isOverdue ? 'overdue' : ''; ?>">
                                <?php echo date('d/m/Y', strtotime($venda['data_vencimento'])); ?>
                                <?php if ($isOverdue): ?>
                                    <i class="fas fa-exclamation-circle" title="Vencida"></i>
                                <?php endif; ?>
                            </td>
                            <td class="action-column">
                                <a href="?cliente_uasg=<?php echo $cliente_uasg; ?>&atualizar_status=true&venda_id=<?php echo $venda['id']; ?>" 
                                   class="status-badge <?php echo $venda['status_pagamento'] == 'Recebido' ? 'pendente' : 'recebido'; ?>"
                                   title="Clique para alterar o status">
                                    <?php if ($venda['status_pagamento'] == 'Recebido'): ?>
                                        <i class="fas fa-undo"></i> Marcar Pendente
                                    <?php else: ?>
                                        <i class="fas fa-check"></i> Marcar Recebido
                                    <?php endif; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: bold; background: var(--light-gray);">
                        <td>Total</td>
                        <td colspan="4">
                            R$ <?php echo number_format(array_sum(array_column($vendas, 'valor')), 2, ',', '.'); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Resumo Financeiro -->
        <div style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius); margin-top: 2rem;">
            <h3 style="color: var(--primary-color); margin-bottom: 1rem;">
                <i class="fas fa-chart-pie"></i> Resumo Financeiro
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div>
                    <strong>Total Geral:</strong> 
                    R$ <?php echo number_format(array_sum(array_column($vendas, 'valor')), 2, ',', '.'); ?>
                </div>
                <div>
                    <strong style="color: var(--success-color);">Recebido:</strong> 
                    R$ <?php echo number_format($recebido, 2, ',', '.'); ?>
                    (<?php echo $vendas ? round(($recebido / array_sum(array_column($vendas, 'valor'))) * 100, 1) : 0; ?>%)
                </div>
                <div>
                    <strong style="color: var(--danger-color);">Pendente:</strong> 
                    R$ <?php echo number_format($pendente, 2, ',', '.'); ?>
                    (<?php echo $vendas ? round(($pendente / array_sum(array_column($vendas, 'valor'))) * 100, 1) : 0; ?>%)
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p>Nenhuma venda encontrada para este cliente.</p>
            <p style="font-size: 1rem; color: var(--medium-gray);">
                Verifique se existem vendas cadastradas para o cliente UASG: <?php echo htmlspecialchars($cliente_uasg ?? ''); ?>
        </div>
    <?php endif; ?>

    <div class="btn-container">
        <a href="consultar_clientes.php" class="action-button secondary">
            <i class="fas fa-arrow-left"></i> Voltar para Clientes
        </a>
        <?php if (count($vendas) > 0): ?>
            <a href="contas_recebidas.php?cliente_uasg=<?php echo $cliente_uasg; ?>" class="action-button info">
                <i class="fas fa-check-double"></i> Ver Contas Recebidas
            </a>
            <button onclick="window.print()" class="action-button">
                <i class="fas fa-print"></i> Imprimir Extrato
            </button>
        <?php endif; ?>
    </div>
</div>

<script>
// Confirmação antes de alterar status
document.querySelectorAll('a[href*="atualizar_status"]').forEach(link => {
    link.addEventListener('click', function(e) {
        const isRecebido = this.classList.contains('recebido');
        const action = isRecebido ? 'marcar como RECEBIDO' : 'marcar como NÃO RECEBIDO';
        
        if (!confirm(`Tem certeza que deseja ${action} este pagamento?`)) {
            e.preventDefault();
        }
    });
});

// Auto-refresh a cada 5 minutos
setTimeout(function() {
    location.reload();
}, 300000);
</script>

</body>
</html>