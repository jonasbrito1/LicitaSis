<?php
session_start();

// Verifica se o usuário está logado e se é administrador
if (isset($_SESSION['user']) && $_SESSION['user']['permission'] === 'Administrador') {
    $isAdmin = true;
} else {
    $isAdmin = false;
}

$error = "";
$success = "";
$products = [];
$searchTerm = "";

// Conexão com o banco de dados
require_once('db.php');

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];

    // Consulta ao banco de dados para pesquisar produtos por código ou nome
    try {
        $sql = "SELECT * FROM produtos WHERE codigo LIKE :searchTerm OR nome LIKE :searchTerm ORDER BY nome ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();

        // Busca todos os produtos que correspondem ao termo de pesquisa
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    // Exibe todos os produtos cadastrados caso não haja pesquisa
    try {
        $sql = "SELECT * FROM produtos ORDER BY nome ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        // Busca todos os produtos cadastrados
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
}

// Verifica se os dados do produto foram enviados para atualização
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_product'])) {
    $id = $_POST['id'];
    $codigo = $_POST['codigo'];
    $nome = $_POST['nome'];
    $unidade = $_POST['unidade'];
    $fornecedor = $_POST['fornecedor'];
    $observacao = $_POST['observacao'];

    try {
        // Atualiza o produto no banco de dados
        $sql = "UPDATE produtos SET codigo = :codigo, nome = :nome, und = :unidade, fornecedor = :fornecedor, observacao = :observacao WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':codigo', $codigo);
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':unidade', $unidade);
        $stmt->bindParam(':fornecedor', $fornecedor);
        $stmt->bindParam(':observacao', $observacao);
        $stmt->execute();

        $success = "Produto atualizado com sucesso!";
        header("Location: consulta_produto.php?success=$success"); // Redireciona de volta para a página de consulta
        exit();
    } catch (PDOException $e) {
        $error = "Erro ao atualizar o produto: " . $e->getMessage();
    }
}

// Verifica se foi feita uma requisição para excluir o produto
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_product'])) {
    $id = $_POST['id']; // Recupera o ID do produto a ser excluído

    try {
        // Deleta o produto do banco de dados
        $sql = "DELETE FROM produtos WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $success = "Produto excluído com sucesso!";
        header("Location: consulta_produto.php?success=$success"); // Redireciona de volta para a página de consulta
        exit();
    } catch (PDOException $e) {
        $error = "Erro ao excluir o produto: " . $e->getMessage();
    }
}

// Limpa a pesquisa ao resetar a página
if (isset($_GET['clear_search'])) {
    header("Location: consulta_produto.php");
    exit();
}

// Verifica se foi feita uma requisição AJAX para pegar os dados do produto
if (isset($_GET['get_product_id'])) {
    $id = $_GET['get_product_id'];
    try {
        $sql = "SELECT * FROM produtos WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode($product); // Retorna os dados do produto em formato JSON
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar produto: ' . $e->getMessage()]);
        exit();
    }
}

// Verifica se há uma mensagem de sucesso na URL
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Produtos - LicitaSis</title>
        <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
            padding: 0.8rem 0;
            text-align: center;
            box-shadow: var(--shadow);
            width: 100%;
            position: relative;
            z-index: 100;
        }

        .logo {
            max-width: 160px;
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
            position: relative;
            z-index: 99;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
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
            text-align: left;
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
        }

        .container:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-5px);
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

        /* Mensagens de erro e sucesso */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-error {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border-left: 4px solid var(--danger-color);
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        /* Barra de pesquisa */
        .search-container {
            margin-bottom: 2rem;
        }

        .search-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
            margin-bottom: 1rem;
        }

        .search-group {
            flex: 1;
            min-width: 250px;
        }

        .search-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
            font-weight: 600;
            font-size: 0.95rem;
        }

        .search-input {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background-color: #f9f9f9;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.2);
            background-color: white;
        }

        .search-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .search-btn {
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            min-width: 120px;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        .search-btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        .search-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0, 191, 174, 0.2);
        }

        .clear-btn {
            background: linear-gradient(135deg, var(--medium-gray) 0%, var(--dark-gray) 100%);
        }

        .clear-btn:hover {
            background: linear-gradient(135deg, var(--dark-gray) 0%, var(--medium-gray) 100%);
        }

        /* Tabela de resultados */
        .table-container {
            overflow-x: auto;
            margin-top: 1.5rem;
            border-radius: var(--radius-sm);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            font-size: 0.95rem;
        }

        table th, table td {
            padding: 0.875rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        table th {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        table tr:last-child td {
            border-bottom: none;
        }

        table tr:hover {
            background-color: rgba(0, 191, 174, 0.05);
        }

        table a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            display: inline-block;
        }

        table a:hover {
            color: var(--secondary-color);
            transform: translateY(-1px);
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
            background-color: rgba(0, 0, 0, 0.5);
            overflow: auto;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow-hover);
            width: 90%;
            max-width: 600px;
            position: relative;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .close {
            position: absolute;
            top: 1rem;
            right: 1.5rem;
            color: var(--medium-gray);
            font-size: 1.8rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
        }

        .close:hover {
            color: var(--dark-gray);
            transform: scale(1.1);
        }

        .modal h2 {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-form-group {
            margin-bottom: 1.25rem;
        }

        .modal-form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
            font-weight: 600;
            font-size: 0.95rem;
        }

        .modal-form-group input, 
        .modal-form-group textarea {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background-color: #f9f9f9;
        }

        .modal-form-group input:focus, 
        .modal-form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.2);
            background-color: white;
        }

        .modal-form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .modal-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1.5rem;
            justify-content: space-between;
        }

        .modal-btn {
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            min-width: 120px;
            flex: 1;
        }

        .edit-btn {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(45, 137, 62, 0.2);
        }

        .edit-btn:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(45, 137, 62, 0.3);
        }

        .save-btn {
            background: linear-gradient(135deg, var(--success-color) 0%, #1e7e34 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
        }

        .save-btn:hover {
            background: linear-gradient(135deg, #1e7e34 0%, var(--success-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
        }

        .delete-btn {
            background: linear-gradient(135deg, var(--danger-color) 0%, #bd2130 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.2);
        }

        .delete-btn:hover {
            background: linear-gradient(135deg, #bd2130 0%, var(--danger-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(220, 53, 69, 0.3);
        }

        .action-links {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1.5rem;
            width: 100%;
        }

        .action-link {
            flex: 1;
            padding: 0.75rem 1rem;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            text-decoration: none;
            text-align: center;
            border-radius: var(--radius-sm);
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .action-link:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        /* Menu mobile */
        .mobile-menu-btn {
            display: none;
            background: transparent;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            position: absolute;
            right: 1rem;
            top: 0.5rem;
            z-index: 1001;
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 2rem 1.5rem;
                padding: 2rem;
            }
            
            nav {
                justify-content: flex-start;
                padding: 0 1rem;
            }
            
            nav a {
                padding: 0.75rem 0.75rem;
                font-size: 0.9rem;
            }
            
            .dropdown-content {
                min-width: 180px;
            }
        }

        @media (max-width: 992px) {
            .container {
                padding: 1.5rem;
            }
            
            .search-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-group {
                width: 100%;
            }
            
            .modal-content {
                width: 95%;
                padding: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .logo {
                max-width: 140px;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            nav {
                flex-direction: column;
                align-items: center;
                padding: 0;
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.5s ease;
            }
            
            nav.active {
                max-height: 1000px;
            }
            
            .dropdown {
                width: 100%;
            }
            
            nav a {
                width: 100%;
                padding: 0.75rem 1rem;
                border-bottom: 1px solid rgba(255,255,255,0.1);
            }
            
            .dropdown-content {
                position: static;
                box-shadow: none;
                width: 100%;
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.3s ease;
            }
            
            .dropdown.active .dropdown-content {
                max-height: 500px;
                display: block;
            }
            
            .dropdown-content a {
                padding-left: 2rem;
                background: rgba(0,0,0,0.1);
            }
            
            .container {
                padding: 1.25rem;
                margin: 1.5rem 1rem;
            }
            
            h2 {
                font-size: 1.5rem;
            }
            
            .search-buttons {
                flex-direction: column;
            }
            
            .search-btn {
                width: 100%;
            }
            
            .modal-buttons {
                flex-direction: column;
            }
            
            .modal-btn {
                width: 100%;
            }
            
            .action-links {
                flex-direction: column;
            }
            
            table {
                font-size: 0.85rem;
            }
            
            table th, table td {
                padding: 0.75rem 0.5rem;
            }
        }

        @media (max-width: 480px) {
            header {
                padding: 0.6rem 0;
            }
            
            .logo {
                max-width: 120px;
            }
            
            .container {
                padding: 1rem;
                margin: 1rem 0.5rem;
                border-radius: var(--radius-sm);
            }
            
            h2 {
                font-size: 1.3rem;
                margin-bottom: 1.5rem;
            }
            
            h2::after {
                width: 60px;
                height: 2px;
            }
            
            .search-group label {
                font-size: 0.9rem;
            }
            
            .search-input {
                padding: 0.75rem 0.875rem;
                font-size: 0.95rem;
            }
            
            .search-btn {
                padding: 0.7rem 1rem;
                font-size: 0.9rem;
            }
            
            .mobile-menu-btn {
                font-size: 1.3rem;
                right: 0.75rem;
                top: 0.4rem;
            }
            
            .modal-content {
                padding: 1.25rem;
                margin: 10% auto;
            }
            
            .close {
                top: 0.75rem;
                right: 1.25rem;
                font-size: 1.5rem;
            }
            
            .modal h2 {
                font-size: 1.2rem;
                margin-bottom: 1.25rem;
            }
            
            .modal-form-group label {
                font-size: 0.9rem;
            }
            
            .modal-form-group input, 
            .modal-form-group textarea {
                padding: 0.7rem 0.875rem;
                font-size: 0.95rem;
            }
            
            .modal-btn {
                padding: 0.7rem 1rem;
                font-size: 0.9rem;
            }
            
            table {
                font-size: 0.8rem;
            }
            
            table th, table td {
                padding: 0.6rem 0.5rem;
            }
        }

        @media (max-width: 360px) {
            .logo {
                max-width: 100px;
            }
            
            .container {
                padding: 0.875rem;
                margin: 0.75rem 0.375rem;
            }
            
            h2 {
                font-size: 1.2rem;
            }
            
            .search-input {
                padding: 0.7rem 0.8rem;
                font-size: 0.9rem;
            }
            
            .search-btn {
                padding: 0.6rem 0.8rem;
                font-size: 0.85rem;
            }
            
            .modal-content {
                padding: 1rem;
            }
            
            table {
                font-size: 0.75rem;
            }
            
            table th, table td {
                padding: 0.5rem 0.375rem;
            }
        }
    </style>
</head>
<body>

<header>
    <a href="index.php">
        <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
    </a>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
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
    <h2>Consulta de Produtos</h2>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <div class="search-container">
        <form action="consulta_produto.php" method="GET">
            <div class="search-bar">
                <div class="search-group">
                    <label for="search"><i class="fas fa-search"></i> Pesquisar por Código ou Nome:</label>
                    <input type="text" name="search" id="search" class="search-input" placeholder="Digite o código ou nome do produto" value="<?php echo htmlspecialchars($searchTerm); ?>">
                </div>
            </div>
            
            <div class="search-buttons">
                <button type="submit" class="search-btn">
                    <i class="fas fa-search"></i> Pesquisar
                </button>
                <a href="consulta_produto.php" class="search-btn clear-btn">
                    <i class="fas fa-sync-alt"></i> Limpar
                </a>
            </div>
        </form>
    </div>

    <!-- Exibe os resultados da pesquisa, se houver -->
    <?php if (count($products) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nome</th>
                        
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <a href="javascript:void(0);" onclick="openProductModal(<?php echo $product['id']; ?>)">
                                    <?php echo htmlspecialchars($product['codigo']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($product['nome']); ?></td>
                            
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-error" style="text-align: center;">
            <i class="fas fa-info-circle"></i>
            Nenhum produto encontrado.
        </div>
    <?php endif; ?>
</div>

<!-- Modal de Exibição do Produto -->
<div id="productModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeProductModal()">&times;</span>
        <h2>Detalhes do Produto</h2>
        <form method="POST" action="consulta_produto.php">
            <input type="hidden" id="product_id" name="id">
            
            <div class="modal-form-group">
                <label for="codigo"><i class="fas fa-barcode"></i> Código:</label>
                <input type="text" id="codigo" name="codigo" readonly>
            </div>

            <div class="modal-form-group">
                <label for="nome"><i class="fas fa-tag"></i> Nome:</label>
                <input type="text" id="nome" name="nome" readonly>
            </div>

            <div class="modal-form-group">
                <label for="unidade"><i class="fas fa-ruler"></i> Unidade:</label>
                <input type="text" id="unidade" name="unidade" readonly>
            </div>

            <div class="modal-form-group">
                <label for="fornecedor"><i class="fas fa-truck"></i> Fornecedor:</label>
                <input type="text" id="fornecedor" name="fornecedor" readonly>
            </div>

            <div class="modal-form-group">
                <label for="observacao"><i class="fas fa-comment-alt"></i> Observação:</label>
                <textarea id="observacao" name="observacao" readonly></textarea>
            </div>

            <div class="modal-buttons">
                <button type="button" id="editProductBtn" class="modal-btn edit-btn" onclick="enableProductEditing()">
                    <i class="fas fa-edit"></i> Editar
                </button>
                <button type="submit" name="update_product" id="saveBtn" class="modal-btn save-btn" style="display: none;">
                    <i class="fas fa-save"></i> Salvar
                </button>
                <button type="submit" name="delete_product" id="deleteProductBtn" class="modal-btn delete-btn">
                    <i class="fas fa-trash-alt"></i> Excluir
                </button>
            </div>

            <div class="action-links">
                <a href="consulta_compras.php?produto_id=" id="entradaBtn" class="action-link">
                    <i class="fas fa-sign-in-alt"></i> Entrada
                </a>
                <a href="consulta_vendas.php?produto_id=" id="saidaBtn" class="action-link">
                    <i class="fas fa-sign-out-alt"></i> Saída
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// Função para abrir o modal e carregar os dados do produto
function openProductModal(id) {
    var modal = document.getElementById("productModal");
    modal.style.display = "block";

    // Atualiza os links de entrada e saída com o ID do produto
    document.getElementById('entradaBtn').href = 'consulta_compras.php?produto_id=' + id;
    document.getElementById('saidaBtn').href = 'consulta_vendas.php?produto_id=' + id;

    fetch('consulta_produto.php?get_product_id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('product_id').value = data.id;
            document.getElementById('codigo').value = data.codigo;
            document.getElementById('nome').value = data.nome;
            document.getElementById('unidade').value = data.und;
            document.getElementById('fornecedor').value = data.fornecedor;
            document.getElementById('observacao').value = data.observacao;
        })
        .catch(error => {
            console.error('Erro ao buscar dados do produto:', error);
            alert('Erro ao carregar os dados do produto. Por favor, tente novamente.');
        });
}

// Função para fechar o modal do produto
function closeProductModal() {
    var modal = document.getElementById("productModal");
    modal.style.display = "none";
    
    // Reseta o formulário e volta para o modo de visualização
    var inputs = document.querySelectorAll('#productModal input, #productModal textarea');
    inputs.forEach(input => {
        input.setAttribute('readonly', true);
    });
    document.getElementById('editProductBtn').style.display = 'inline-block';
    document.getElementById('saveBtn').style.display = 'none';
}

// Função para habilitar a edição dos campos no modal
function enableProductEditing() {
    var inputs = document.querySelectorAll('#productModal input, #productModal textarea');
    inputs.forEach(input => {
        if (input.id !== 'product_id') {
            input.removeAttribute('readonly'); // Permite a edição dos campos
        }
    });
    document.getElementById('editProductBtn').style.display = 'none'; // Esconde o botão Editar
    document.getElementById('saveBtn').style.display = 'inline-block'; // Mostra o botão Salvar
}

// Confirmação antes de excluir o produto
document.getElementById('deleteProductBtn').addEventListener('click', function(e) {
    if (!confirm('Tem certeza que deseja excluir este produto? Esta ação não pode ser desfeita.')) {
        e.preventDefault();
    }
});

// Toggle menu mobile
document.getElementById('mobileMenuBtn').addEventListener('click', function() {
    const nav = document.getElementById('mainNav');
    nav.classList.toggle('active');
    
    // Alterna o ícone do botão
    const icon = this.querySelector('i');
    if (icon.classList.contains('fa-bars')) {
        icon.classList.remove('fa-bars');
        icon.classList.add('fa-times');
    } else {
        icon.classList.remove('fa-times');
        icon.classList.add('fa-bars');
    }
});

// Gerencia os dropdowns no mobile
if (window.innerWidth <= 768) {
    const dropdowns = document.querySelectorAll('.dropdown');
    
    dropdowns.forEach(dropdown => {
        const link = dropdown.querySelector('a');
        
        link.addEventListener('click', function(e) {
            // Previne a navegação apenas no mobile
            if (window.innerWidth <= 768) {
                e.preventDefault();
                dropdown.classList.toggle('active');
                
                // Fecha outros dropdowns
                dropdowns.forEach(otherDropdown => {
                    if (otherDropdown !== dropdown) {
                        otherDropdown.classList.remove('active');
                    }
                });
            }
        });
    });
}

// Animação de entrada
document.addEventListener('DOMContentLoaded', function() {
    const container = document.querySelector('.container');
    container.style.opacity = '0';
    container.style.transform = 'translateY(20px)';
    
    setTimeout(() => {
        container.style.opacity = '1';
        container.style.transform = 'translateY(0)';
    }, 100);
});

// Ajusta o comportamento do menu em resize
window.addEventListener('resize', function() {
    const nav = document.getElementById('mainNav');
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const icon = mobileBtn.querySelector('i');
    
    if (window.innerWidth > 768) {
        nav.classList.remove('active');
        icon.classList.remove('fa-times');
        icon.classList.add('fa-bars');
        
        // Remove os event listeners dos dropdowns
        const dropdowns = document.querySelectorAll('.dropdown');
        dropdowns.forEach(dropdown => {
            dropdown.classList.remove('active');
        });
    }
});

// Fecha o modal quando o usuário clica fora dele
window.onclick = function(event) {
    var modal = document.getElementById('productModal');
    if (event.target == modal) {
        closeProductModal();
    }
}
</script>

</body>
</html>