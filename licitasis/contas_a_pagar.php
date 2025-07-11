<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";
$contas = [];
$searchTerm = "";

// Conexão com o banco de dados
require_once('db.php');

// Função para criar a tabela contas_pagar se não existir
function criarTabelaContasPagar($pdo) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS contas_pagar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            status_pagamento ENUM('Pendente', 'Pago', 'Concluido') DEFAULT 'Pendente',
            data_pagamento DATE NULL,
            observacao_pagamento TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
            UNIQUE KEY unique_compra (compra_id)
        )";
        $pdo->exec($sql);
    } catch (Exception $e) {
        // Tabela já existe ou erro na criação
    }
}

// Cria a tabela se não existir
criarTabelaContasPagar($pdo);

// Função para sincronizar compras com contas a pagar
function sincronizarContasPagar($pdo) {
    try {
        // Insere compras que não estão em contas_pagar
        $sql = "INSERT IGNORE INTO contas_pagar (compra_id, status_pagamento) 
                SELECT id, 'Pendente' FROM compras 
                WHERE id NOT IN (SELECT compra_id FROM contas_pagar)";
        $pdo->exec($sql);
    } catch (Exception $e) {
        // Erro na sincronização
    }
}

// Sincroniza as compras
sincronizarContasPagar($pdo);

// Função para atualizar status de pagamento
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    try {
        $conta_id = $_POST['conta_id'];
        $status = $_POST['status_pagamento'];
        $data_pagamento = !empty($_POST['data_pagamento']) ? $_POST['data_pagamento'] : null;
        $observacao = !empty($_POST['observacao_pagamento']) ? $_POST['observacao_pagamento'] : null;
        
        $sql = "UPDATE contas_pagar SET 
                status_pagamento = :status, 
                data_pagamento = :data_pagamento, 
                observacao_pagamento = :observacao 
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':data_pagamento', $data_pagamento);
        $stmt->bindParam(':observacao', $observacao);
        $stmt->bindParam(':id', $conta_id);
        
        if ($stmt->execute()) {
            $success = "Status de pagamento atualizado com sucesso!";
            header("Location: contas_a_pagar.php?success=" . urlencode($success));
            exit();
        }
    } catch (PDOException $e) {
        $error = "Erro ao atualizar status: " . $e->getMessage();
    }
}

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    
    // Consulta com filtro de pesquisa
    try {
        $sql = "SELECT cp.*, c.fornecedor, c.numero_nf, c.valor_total, c.data, c.numero_empenho, 
                       c.link_pagamento, c.comprovante_pagamento, c.observacao as observacao_compra, c.frete
                FROM contas_pagar cp 
                INNER JOIN compras c ON cp.compra_id = c.id 
                WHERE c.numero_nf LIKE :searchTerm 
                   OR c.fornecedor LIKE :searchTerm 
                   OR cp.status_pagamento LIKE :searchTerm
                ORDER BY c.data DESC, cp.status_pagamento ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        
        $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    // Consulta para mostrar todas as contas a pagar
    try {
        $sql = "SELECT cp.*, c.fornecedor, c.numero_nf, c.valor_total, c.data, c.numero_empenho, 
                       c.link_pagamento, c.comprovante_pagamento, c.observacao as observacao_compra, c.frete
                FROM contas_pagar cp 
                INNER JOIN compras c ON cp.compra_id = c.id 
                ORDER BY c.data DESC, cp.status_pagamento ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar contas a pagar: " . $e->getMessage();
    }
}

// Função para buscar os detalhes da conta e seus produtos
if (isset($_GET['get_conta_id'])) {
    $conta_id = $_GET['get_conta_id'];
    try {
        // Busca os dados da conta a pagar e da compra
        $sql = "SELECT cp.*, c.fornecedor, c.numero_nf, c.valor_total, c.data, c.numero_empenho, 
                       c.link_pagamento, c.comprovante_pagamento, c.observacao as observacao_compra, c.frete
                FROM contas_pagar cp 
                INNER JOIN compras c ON cp.compra_id = c.id 
                WHERE cp.id = :conta_id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':conta_id', $conta_id, PDO::PARAM_INT);
        $stmt->execute();
        $conta = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$conta) {
            echo json_encode(['error' => 'Conta não encontrada']);
            exit();
        }
        
        // Verifica se a tabela produto_compra existe
        $tabela_existe = false;
        try {
            $pdo->query("SELECT 1 FROM produto_compra LIMIT 1");
            $tabela_existe = true;
        } catch (Exception $e) {
            $tabela_existe = false;
        }
        
        // Busca os produtos relacionados à compra se a tabela existir
        $produtos = [];
        if ($tabela_existe) {
            $sql_produtos = "SELECT pc.*, p.nome as produto_nome 
                            FROM produto_compra pc 
                            LEFT JOIN produtos p ON pc.produto_id = p.id 
                            WHERE pc.compra_id = :compra_id";
            $stmt_produtos = $pdo->prepare($sql_produtos);
            $stmt_produtos->bindValue(':compra_id', $conta['compra_id'], PDO::PARAM_INT);
            $stmt_produtos->execute();
            $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Retorna os dados da conta e seus produtos como JSON
        echo json_encode(['conta' => $conta, 'produtos' => $produtos]);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => "Erro ao buscar detalhes da conta: " . $e->getMessage()]);
        exit();
    }
}

// Função para calcular totais
try {
    // Total geral de contas a pagar
    $sqlTotalGeral = "SELECT SUM(c.valor_total) AS total_geral 
                      FROM contas_pagar cp 
                      INNER JOIN compras c ON cp.compra_id = c.id";
    $stmtTotalGeral = $pdo->prepare($sqlTotalGeral);
    $stmtTotalGeral->execute();
    $totalGeral = $stmtTotalGeral->fetch(PDO::FETCH_ASSOC)['total_geral'];
    
    // Total de contas pendentes
    $sqlTotalPendente = "SELECT SUM(c.valor_total) AS total_pendente 
                         FROM contas_pagar cp 
                         INNER JOIN compras c ON cp.compra_id = c.id 
                         WHERE cp.status_pagamento = 'Pendente'";
    $stmtTotalPendente = $pdo->prepare($sqlTotalPendente);
    $stmtTotalPendente->execute();
    $totalPendente = $stmtTotalPendente->fetch(PDO::FETCH_ASSOC)['total_pendente'];
    
    // Total de contas pagas
    $sqlTotalPago = "SELECT SUM(c.valor_total) AS total_pago 
                     FROM contas_pagar cp 
                     INNER JOIN compras c ON cp.compra_id = c.id 
                     WHERE cp.status_pagamento IN ('Pago', 'Concluido')";
    $stmtTotalPago = $pdo->prepare($sqlTotalPago);
    $stmtTotalPago->execute();
    $totalPago = $stmtTotalPago->fetch(PDO::FETCH_ASSOC)['total_pago'];
    
} catch (PDOException $e) {
    $error = "Erro ao calcular totais: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contas a Pagar - LicitaSis</title>
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
            --warning-color: #ff8c00;
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

        h2 {
            text-align: center;
            color: var(--primary-color);
            margin-bottom: 2rem;
            font-size: 1.8rem;
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

        h3 {
            color: var(--primary-color);
            margin: 1.5rem 0 1rem;
            font-size: 1.3rem;
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }

        /* Mensagens de feedback */
        .error, .success {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            text-align: center;
            animation: slideInDown 0.3s ease;
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
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Cards de resumo */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            border-radius: var(--radius);
            padding: 1.5rem;
            text-align: center;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border-left: 4px solid transparent;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .summary-card.total {
            border-left-color: var(--info-color);
        }

        .summary-card.pendente {
            border-left-color: var(--warning-color);
        }

        .summary-card.pago {
            border-left-color: var(--success-color);
        }

        .summary-card h4 {
            font-size: 0.9rem;
            color: var(--medium-gray);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .summary-card .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        /* Barra de pesquisa */
        .search-bar {
            display: flex;
            margin-bottom: 2rem;
            gap: 1rem;
        }

        .search-bar input {
            flex: 1;
            padding: 0.875rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        }

        .search-bar button {
            padding: 0.875rem 1.5rem;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }

        .search-bar button:hover {
            background: #009d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
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
        }

        table tr:hover {
            background: var(--light-gray);
        }

        table a {
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        table a:hover {
            color: var(--primary-color);
            text-decoration: underline;
        }

        /* Status badges */
        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pendente {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .status-pago {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .status-concluido {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
            overflow-y: auto;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 90%;
            max-width: 800px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideInUp 0.3s ease;
            overflow: hidden;
        }

        @keyframes slideInUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem;
            position: relative;
        }

        .modal-header h3 {
            margin: 0;
            color: white;
            font-size: 1.5rem;
            border-bottom: none;
        }

        .close {
            color: white;
            float: right;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            position: absolute;
            right: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .close:hover {
            transform: translateY(-50%) scale(1.1);
            color: #ffdddd;
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            padding: 1rem 2rem;
            text-align: center;
            border-top: 1px solid var(--border-color);
        }

        /* Formulário do modal */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            display: block;
            font-size: 0.95rem;
        }

        input, select, textarea {
            width: 100%;
            padding: 0.875rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        }

        input[readonly] {
            background: var(--light-gray);
            color: var(--medium-gray);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* Seção de produtos */
        .produtos-section {
            margin-top: 1.5rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            background: var(--light-gray);
        }

        .produto-item {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        .produto-item:hover {
            box-shadow: var(--shadow);
            border-color: var(--secondary-color);
        }

        .produto-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .produto-title {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        /* Comprovante de pagamento */
        .comprovante-container {
            margin-top: 1rem;
        }

        .comprovante-link {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: var(--light-gray);
            border-radius: var(--radius);
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        .comprovante-link:hover {
            background: var(--primary-light);
            color: white;
        }

        .comprovante-link i {
            margin-right: 0.5rem;
        }

        /* Botões */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        button {
            padding: 0.875rem 1.5rem;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            min-width: 150px;
        }

        button:hover {
            background: #009d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
        }

        button.btn-success {
            background: var(--success-color);
        }

        button.btn-success:hover {
            background: #218838;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 1rem;
                padding: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .logo {
                max-width: 120px;
            }

            nav {
                padding: 0.5rem 0;
            }

            nav a {
                padding: 0.75rem 0.5rem;
                font-size: 0.85rem;
                margin: 0 0.25rem;
            }

            .dropdown-content {
                min-width: 160px;
            }

            .container {
                margin: 1rem;
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .summary-cards {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .btn-container {
                flex-direction: column;
            }

            button {
                width: 100%;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .search-bar {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .logo {
                max-width: 100px;
            }

            nav a {
                padding: 0.625rem 0.375rem;
                font-size: 0.8rem;
                margin: 0 0.125rem;
            }

            .container {
                margin: 0.5rem;
                padding: 1rem;
            }

            h2 {
                font-size: 1.25rem;
            }

            input, select, textarea {
                padding: 0.75rem;
                font-size: 0.9rem;
            }

            .modal-header {
                padding: 1rem;
            }

            .modal-body {
                padding: 1.5rem 1rem;
            }

            table th, table td {
                padding: 0.75rem 0.5rem;
                font-size: 0.85rem;
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

<div class="container">
    <h2>Contas a Pagar</h2>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="success"><?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>

    <!-- Cards de resumo -->
    <div class="summary-cards">
        <div class="summary-card total">
            <h4>Total Geral</h4>
            <div class="value">R$ <?php echo number_format($totalGeral ?? 0, 2, ',', '.'); ?></div>
        </div>
        <div class="summary-card pendente">
            <h4>Contas Pendentes</h4>
            <div class="value">R$ <?php echo number_format($totalPendente ?? 0, 2, ',', '.'); ?></div>
        </div>
        <div class="summary-card pago">
            <h4>Contas Pagas</h4>
            <div class="value">R$ <?php echo number_format($totalPago ?? 0, 2, ',', '.'); ?></div>
        </div>
    </div>

    <form action="contas_a_pagar.php" method="GET">
        <div class="search-bar">
            <input type="text" name="search" id="search" placeholder="Pesquisar por Número da NF, Fornecedor ou Status" value="<?php echo htmlspecialchars($searchTerm); ?>">
            <button type="submit"><i class="fas fa-search"></i> Pesquisar</button>
        </div>
    </form>

    <?php if (count($contas) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Número da NF</th>
                        <th>Fornecedor</th>
                        <th>Valor Total</th>
                        <th>Data da Compra</th>
                        <th>Status</th>
                        <th>Data de Pagamento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contas as $conta): ?>
                        <tr>
                            <td>
                                <a href="javascript:void(0);" onclick="openModal(<?php echo $conta['id']; ?>)">
                                    <?php echo htmlspecialchars($conta['numero_nf']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($conta['fornecedor']); ?></td>
                            <td>R$ <?php echo number_format($conta['valor_total'], 2, ',', '.'); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($conta['data'])); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($conta['status_pagamento']); ?>">
                                    <?php echo $conta['status_pagamento']; ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                    if ($conta['data_pagamento']) {
                                        echo date('d/m/Y', strtotime($conta['data_pagamento']));
                                    } else {
                                        echo '-';
                                    }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="text-align: center; margin-top: 2rem; color: var(--medium-gray);">Nenhuma conta a pagar encontrada.</p>
    <?php endif; ?>
</div>

<!-- Modal de Detalhes da Conta a Pagar -->
<div id="contaModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Detalhes da Conta a Pagar</h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="contaForm" method="POST" action="contas_a_pagar.php">
                <input type="hidden" name="conta_id" id="conta_id">
                
                <h3>Informações da Compra</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="fornecedor">Fornecedor:</label>
                        <input type="text" name="fornecedor" id="fornecedor" readonly>
                    </div>
                    <div class="form-group">
                        <label for="numero_nf">Número da NF:</label>
                        <input type="text" name="numero_nf" id="numero_nf" readonly>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="data_compra">Data da Compra:</label>
                        <input type="date" name="data_compra" id="data_compra" readonly>
                    </div>
                    <div class="form-group">
                        <label for="valor_total">Valor Total:</label>
                        <input type="text" name="valor_total" id="valor_total" readonly>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="numero_empenho">Número de Empenho:</label>
                        <input type="text" name="numero_empenho" id="numero_empenho" readonly>
                    </div>
                    <div class="form-group">
                        <label for="link_pagamento">Link para Pagamento:</label>
                        <input type="url" name="link_pagamento" id="link_pagamento" readonly>
                    </div>
                </div>
                
                <!-- Produtos da compra -->
                <h3>Produtos</h3>
                <div id="produtos-container" class="produtos-section">
                    <!-- Os produtos serão adicionados aqui dinamicamente -->
                </div>
                
                <div class="form-group">
                    <label for="observacao_compra">Observação da Compra:</label>
                    <textarea name="observacao_compra" id="observacao_compra" readonly></textarea>
                </div>
                
                <!-- Comprovante de Pagamento -->
                <div class="form-group">
                    <label>Comprovante de Pagamento:</label>
                    <div id="comprovante-container" class="comprovante-container">
                        <!-- Link para o comprovante será adicionado aqui -->
                    </div>
                </div>
                
                <h3>Informações de Pagamento</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="status_pagamento">Status do Pagamento:</label>
                        <select name="status_pagamento" id="status_pagamento">
                            <option value="Pendente">Pendente</option>
                            <option value="Pago">Pago</option>
                            <option value="Concluido">Concluído</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="data_pagamento">Data do Pagamento:</label>
                        <input type="date" name="data_pagamento" id="data_pagamento">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="observacao_pagamento">Observação do Pagamento:</label>
                    <textarea name="observacao_pagamento" id="observacao_pagamento" placeholder="Informações sobre o pagamento..."></textarea>
                </div>
                
                <div class="btn-container">
                    <button type="submit" name="update_status" class="btn-success">
                        <i class="fas fa-save"></i> Atualizar Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Função para abrir o modal e carregar os dados da conta
    function openModal(id) {
        // Limpa o container de produtos
        document.getElementById('produtos-container').innerHTML = '';
        
        // Exibe o modal
        document.getElementById('contaModal').style.display = 'block';
        
        // Busca os dados da conta
        fetch('contas_a_pagar.php?get_conta_id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    return;
                }
                
                const conta = data.conta;
                const produtos = data.produtos || [];
                
                // Preenche os campos do formulário
                document.getElementById('conta_id').value = conta.id;
                document.getElementById('fornecedor').value = conta.fornecedor;
                document.getElementById('numero_nf').value = conta.numero_nf;
                document.getElementById('data_compra').value = conta.data;
                document.getElementById('valor_total').value = 'R$ ' + parseFloat(conta.valor_total).toLocaleString('pt-BR', {minimumFractionDigits: 2});
                document.getElementById('numero_empenho').value = conta.numero_empenho || '';
                document.getElementById('link_pagamento').value = conta.link_pagamento || '';
                document.getElementById('observacao_compra').value = conta.observacao_compra || '';
                
                // Preenche os campos de pagamento
                document.getElementById('status_pagamento').value = conta.status_pagamento;
                document.getElementById('data_pagamento').value = conta.data_pagamento || '';
                document.getElementById('observacao_pagamento').value = conta.observacao_pagamento || '';
                
                // Verifica se há comprovante de pagamento
                const comprovanteContainer = document.getElementById('comprovante-container');
                if (conta.comprovante_pagamento) {
                    comprovanteContainer.innerHTML = `
                        <a href="${conta.comprovante_pagamento}" class="comprovante-link" target="_blank">
                            <i class="fas fa-file-alt"></i> Ver Comprovante
                        </a>
                    `;
                } else {
                    comprovanteContainer.innerHTML = '<span>Nenhum comprovante anexado</span>';
                }
                
                // Exibe os produtos
                if (produtos.length > 0) {
                    produtos.forEach((produto, index) => {
                        adicionarProdutoExistente(produto, index + 1);
                    });
                } else {
                    // Se não houver produtos na tabela produto_compra, pode não ter produtos detalhados
                    document.getElementById('produtos-container').innerHTML = '<p style="text-align: center; color: var(--medium-gray);">Produtos não detalhados para esta compra.</p>';
                }
            })
            .catch(error => {
                console.error('Erro ao buscar dados da conta:', error);
                alert('Erro ao buscar dados da conta. Por favor, tente novamente.');
            });
    }
    
    // Função para adicionar um produto existente ao modal
    function adicionarProdutoExistente(produto, index) {
        const produtosContainer = document.getElementById('produtos-container');
        
        const produtoDiv = document.createElement('div');
        produtoDiv.className = 'produto-item';
        produtoDiv.id = `produto-${index}`;
        
        // Garantir que os valores existam ou usar valores padrão
        const quantidade = produto.quantidade || 0;
        const valorUnitario = produto.valor_unitario || 0;
        const valorTotal = produto.valor_total || (quantidade * valorUnitario);
        const nomeProduto = produto.produto_nome || 'Produto sem nome';
        
        produtoDiv.innerHTML = `
            <div class="produto-header">
                <div class="produto-title">Produto ${index}</div>
            </div>
            
            <div class="form-group">
                <label>Produto:</label>
                <input type="text" value="${nomeProduto}" readonly>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Quantidade:</label>
                    <input type="number" value="${quantidade}" readonly>
                </div>
                <div class="form-group">
                    <label>Valor Unitário:</label>
                    <input type="text" value="R$ ${parseFloat(valorUnitario).toFixed(2).replace('.', ',')}" readonly>
                </div>
            </div>
            
            <div class="form-group">
                <label>Valor Total:</label>
                <input type="text" value="R$ ${parseFloat(valorTotal).toFixed(2).replace('.', ',')}" readonly>
            </div>
        `;
        
        produtosContainer.appendChild(produtoDiv);
    }
    
    // Função para fechar o modal
    function closeModal() {
        document.getElementById('contaModal').style.display = 'none';
    }
    
    // Fecha o modal ao clicar fora dele
    window.onclick = function(event) {
        const contaModal = document.getElementById('contaModal');
        
        if (event.target === contaModal) {
            closeModal();
        }
    }
    
    // Fecha o modal ao pressionar a tecla ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    // Habilita/desabilita o campo de data de pagamento baseado no status
    document.getElementById('status_pagamento').addEventListener('change', function() {
        const dataPagamento = document.getElementById('data_pagamento');
        if (this.value === 'Pago' || this.value === 'Concluido') {
            dataPagamento.removeAttribute('readonly');
            if (!dataPagamento.value) {
                // Define a data atual se não há data definida
                const hoje = new Date().toISOString().split('T')[0];
                dataPagamento.value = hoje;
            }
        } else {
            dataPagamento.value = '';
        }
    });
    
    // Função para confirmar antes de marcar como pago ou concluído
    document.getElementById('contaForm').addEventListener('submit', function(e) {
        const status = document.getElementById('status_pagamento').value;
        const dataPagamento = document.getElementById('data_pagamento').value;
        
        if ((status === 'Pago' || status === 'Concluido') && !dataPagamento) {
            e.preventDefault();
            alert('Por favor, informe a data de pagamento para marcar como pago/concluído.');
            return false;
        }
        
        if (status === 'Pago') {
            const confirmacao = confirm('Tem certeza que deseja marcar esta conta como paga?');
            if (!confirmacao) {
                e.preventDefault();
                return false;
            }
        } else if (status === 'Concluido') {
            const confirmacao = confirm('Tem certeza que deseja marcar esta conta como concluída?');
            if (!confirmacao) {
                e.preventDefault();
                return false;
            }
        }
    });
</script>

</body>
</html>