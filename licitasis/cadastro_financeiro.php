<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

// Inicializa a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = false;

require_once('db.php');

// Consulta para obter todos os clientes cadastrados na tabela "clientes"
$sql_clientes = "SELECT id, nome_orgaos, uasg FROM clientes";
$stmt_clientes = $pdo->prepare($sql_clientes);
$stmt_clientes->execute();
$clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);

// Consulta para obter todas as transportadoras cadastradas
$sql_transportadoras = "SELECT id, nome FROM transportadora ORDER BY nome ASC";
$stmt_transportadoras = $pdo->prepare($sql_transportadoras);
$stmt_transportadoras->execute();
$transportadoras = $stmt_transportadoras->fetchAll(PDO::FETCH_ASSOC);

// Consulta para obter todos os empenhos para sugestões
$sql_empenhos = "SELECT numero, cliente_uasg, cliente_nome FROM empenhos";
$stmt_empenhos = $pdo->prepare($sql_empenhos);
$stmt_empenhos->execute();
$empenhos = $stmt_empenhos->fetchAll(PDO::FETCH_ASSOC);

// Endpoint para buscar dados do empenho via AJAX
if (isset($_GET['get_empenho_data'])) {
    $empenho_numero = $_GET['get_empenho_data'];
    
    try {
        $sql = "SELECT e.numero, e.cliente_uasg, e.cliente_nome, c.id as cliente_id 
                FROM empenhos e 
                LEFT JOIN clientes c ON e.cliente_uasg = c.uasg 
                WHERE e.numero = :numero LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':numero', $empenho_numero);
        $stmt->execute();
        
        $empenho_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($empenho_data) {
            echo json_encode($empenho_data);
        } else {
            echo json_encode(['error' => 'Empenho não encontrado']);
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar dados do empenho: ' . $e->getMessage()]);
    }
    
    exit();
}

// Endpoint para buscar sugestões de empenho via AJAX
if (isset($_GET['search_empenho'])) {
    $search_term = $_GET['search_empenho'];
    
    try {
        $sql = "SELECT numero FROM empenhos WHERE numero LIKE :search_term LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':search_term', "%$search_term%");
        $stmt->execute();
        
        $suggestions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode($suggestions);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar sugestões: ' . $e->getMessage()]);
    }
    
    exit();
}

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Obtém os dados do formulário
        $empenho = isset($_POST['empenho']) ? trim($_POST['empenho']) : null;
        $cliente_uasg = isset($_POST['cliente_uasg']) ? trim($_POST['cliente_uasg']) : null;
        $cliente_id = isset($_POST['cliente_id']) ? trim($_POST['cliente_id']) : null; // Cliente selecionado no select
        
        // Se os produtos ou observações não forem enviados, inicializa como arrays vazios
        $produtosArray = isset($_POST['produto']) ? $_POST['produto'] : [];  // Array de produtos
        $observacaoArray = isset($_POST['observacao']) ? $_POST['observacao'] : []; // Array de observações
        
        $produtos = implode(",", $produtosArray); // Converte o array em string
        $observacao = implode(",", $observacaoArray); // Converte o array em string
        
        $transportadora = isset($_POST['transportadora']) ? trim($_POST['transportadora']) : null;
        $pregao = isset($_POST['pregao']) ? trim($_POST['pregao']) : null;
        $comprovante = isset($_FILES['comprovante']['name']) ? $_FILES['comprovante']['name'] : null; // Arquivo de upload
        $nf = isset($_POST['nf']) ? trim($_POST['nf']) : null;
        $data = isset($_POST['data']) ? trim($_POST['data']) : null;
        $valor = isset($_POST['valor']) ? trim($_POST['valor']) : null;
        $tipo = isset($_POST['tipo']) ? trim($_POST['tipo']) : null;

        // Processa o upload do comprovante se ele existir
        if ($comprovante) {
            $targetDir = "uploads/";
            $targetFile = $targetDir . basename($comprovante);
            move_uploaded_file($_FILES['comprovante']['tmp_name'], $targetFile);
        }

        // Insere os dados financeiros na tabela 'financeiro'
        $sql_financeiro = "INSERT INTO financeiro (empenho, cliente_uasg, produto, transportadora, observacao, pregao, comprovante, nf, data, valor, tipo) 
                   VALUES (:empenho, :cliente_uasg, :produto, :transportadora, :observacao, :pregao, :comprovante, :nf, :data, :valor, :tipo)";
$stmt_financeiro = $pdo->prepare($sql_financeiro);
$stmt_financeiro->bindParam(':empenho', $empenho, PDO::PARAM_STR);
$stmt_financeiro->bindParam(':cliente_uasg', $cliente_uasg, PDO::PARAM_STR);
// Removida a linha que vinculava cliente_id
$stmt_financeiro->bindParam(':produto', $produtos, PDO::PARAM_STR);
$stmt_financeiro->bindParam(':transportadora', $transportadora, PDO::PARAM_STR);
$stmt_financeiro->bindParam(':observacao', $observacao, PDO::PARAM_STR);
$stmt_financeiro->bindParam(':pregao', $pregao, PDO::PARAM_STR);
$stmt_financeiro->bindParam(':comprovante', $comprovante, PDO::PARAM_STR);
$stmt_financeiro->bindParam(':nf', $nf, PDO::PARAM_STR);
$stmt_financeiro->bindParam(':data', $data, PDO::PARAM_STR);
$stmt_financeiro->bindParam(':valor', $valor, PDO::PARAM_STR);
$stmt_financeiro->bindParam(':tipo', $tipo, PDO::PARAM_STR);
$stmt_financeiro->execute();


        $success = true; // Cadastro bem-sucedido
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro Financeiro - LicitaSis</title>
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
            max-width: 900px;
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

        /* Formulário */
        form {
            display: grid;
            grid-gap: 1.5rem;
        }

        label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--primary-dark);
        }

        input, select, textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.2);
            outline: none;
        }

        /* Autocomplete suggestions */
        .autocomplete-container {
            position: relative;
        }

        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 200px;
            overflow-y: auto;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 0 0 var(--radius-sm) var(--radius-sm);
            box-shadow: var(--shadow);
            z-index: 1000;
            display: none;
        }

        .autocomplete-suggestion {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition);
        }

        .autocomplete-suggestion:last-child {
            border-bottom: none;
        }

        .autocomplete-suggestion:hover {
            background-color: var(--light-gray);
        }

        /* Botões */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 1rem;
        }

        button {
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
            min-width: 200px;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        button:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        button:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0, 191, 174, 0.2);
        }

        /* Mensagens */
        .error, .success {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            font-weight: 500;
            text-align: center;
        }

        .error {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        .success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(40, 167, 69, 0.2);
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

        /* Modal de sucesso */
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
        }

        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow-hover);
            width: 80%;
            max-width: 500px;
            text-align: center;
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal h3 {
            color: var(--success-color);
            margin-bottom: 1rem;
        }

        .modal p {
            margin-bottom: 1.5rem;
        }

        .modal-btn {
            padding: 0.75rem 1.25rem;
            background: var(--success-color);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }

        .modal-btn:hover {
            background: #218838;
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
                max-width: 90%;
            }
            
            .btn-container {
                gap: 1rem;
            }
            
            button {
                min-width: 180px;
                padding: 0.75rem 1.25rem;
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
                padding: 1.5rem;
                margin: 1.5rem auto;
            }
            
            h2 {
                font-size: 1.5rem;
            }
            
            .btn-container {
                flex-direction: column;
                align-items: center;
            }
            
            button {
                width: 100%;
                max-width: 300px;
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
                padding: 1.25rem;
                margin: 1rem auto;
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
            
            label {
                font-size: 0.9rem;
            }
            
            input, select, textarea {
                padding: 0.7rem 0.9rem;
                font-size: 0.9rem;
            }
            
            .btn-container {
                margin-top: 1.5rem;
                gap: 0.75rem;
            }
            
            button {
                padding: 0.7rem 1rem;
                font-size: 0.9rem;
            }
            
            .mobile-menu-btn {
                font-size: 1.3rem;
                right: 0.75rem;
                top: 0.4rem;
            }
            
            .modal-content {
                width: 90%;
                padding: 1.5rem;
            }
        }

        @media (max-width: 360px) {
            .logo {
                max-width: 100px;
            }
            
            .container {
                padding: 1rem;
                margin: 0.75rem auto;
            }
            
            h2 {
                font-size: 1.2rem;
            }
            
            button {
                padding: 0.6rem 0.8rem;
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
    <h2>Cadastro Financeiro</h2>

    <?php if ($error) { echo "<p class='error'>$error</p>"; } ?>
    <?php if ($success) { echo "<p class='success'>Cadastro realizado com sucesso!</p>"; } ?>

    <form action="cadastro_financeiro.php" method="POST" enctype="multipart/form-data">
        <div class="autocomplete-container">
            <label for="empenho">Empenho:</label>
            <input type="text" id="empenho" name="empenho" autocomplete="off">
            <div id="empenho-suggestions" class="autocomplete-suggestions"></div>
        </div>

        <div>
            <label for="cliente_uasg">Cliente (UASG):</label>
            <input type="text" id="cliente_uasg" name="cliente_uasg" readonly>
        </div>

        <div>
            <label for="cliente_id">Nome do Cliente:</label>
            <select name="cliente_id" id="cliente_id">
                <option value="">Selecione um Cliente</option>
                <?php foreach ($clientes as $cliente): ?>
                    <option value="<?= $cliente['id']; ?>" data-uasg="<?= $cliente['uasg']; ?>"><?= $cliente['nome_orgaos']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="transportadora">Transportadora:</label>
            <select name="transportadora" id="transportadora">
                <option value="">Selecione uma Transportadora</option>
                <?php foreach ($transportadoras as $transportadora): ?>
                    <option value="<?= $transportadora['nome']; ?>"><?= $transportadora['nome']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="pregao">Pregão:</label>
            <input type="text" id="pregao" name="pregao">
        </div>

        <div>
            <label for="comprovante">Comprovante (Upload):</label>
            <input type="file" id="comprovante" name="comprovante">
        </div>

        <div>
            <label for="nf">NF:</label>
            <input type="text" id="nf" name="nf">
        </div>

        <div>
            <label for="data">Data:</label>
            <input type="date" id="data" name="data">
        </div>

        <div>
            <label for="valor">Valor:</label>
            <input type="number" id="valor" name="valor" step="0.01">
        </div>

        <div>
            <label for="tipo">Tipo:</label>
            <select id="tipo" name="tipo">
                <option value="Receita">Receita</option>
                <option value="Despesa">Despesa</option>
            </select>
        </div>

        <div class="btn-container">
            <button type="submit">
                <i class="fas fa-save"></i> Cadastrar
            </button>
        </div>
    </form>
</div>

<!-- Modal de Sucesso -->
<div id="successModal" class="modal">
    <div class="modal-content">
        <h3><i class="fas fa-check-circle"></i> Cadastro Realizado</h3>
        <p>O registro financeiro foi cadastrado com sucesso!</p>
        <button class="modal-btn" onclick="closeSuccessModal()">OK</button>
    </div>
</div>

<script>
// Armazena os dados dos empenhos para uso no autocomplete
const empenhos = <?php echo json_encode($empenhos); ?>;
const clientesData = <?php echo json_encode($clientes); ?>;

// Função para mostrar sugestões de empenho enquanto o usuário digita
document.getElementById('empenho').addEventListener('input', function() {
    const inputValue = this.value.trim();
    const suggestionsContainer = document.getElementById('empenho-suggestions');
    
    // Limpa as sugestões anteriores
    suggestionsContainer.innerHTML = '';
    
    if (inputValue.length < 2) {
        suggestionsContainer.style.display = 'none';
        return;
    }
    
    // Faz uma requisição AJAX para buscar sugestões
    fetch(`cadastro_financeiro.php?search_empenho=${inputValue}`)
        .then(response => response.json())
        .then(suggestions => {
            if (suggestions.length > 0) {
                suggestionsContainer.style.display = 'block';
                
                suggestions.forEach(suggestion => {
                    const div = document.createElement('div');
                    div.className = 'autocomplete-suggestion';
                    div.textContent = suggestion;
                    div.addEventListener('click', function() {
                        document.getElementById('empenho').value = suggestion;
                        suggestionsContainer.style.display = 'none';
                        
                        // Busca os dados do empenho selecionado
                        fetchEmpenhoData(suggestion);
                    });
                    
                    suggestionsContainer.appendChild(div);
                });
            } else {
                suggestionsContainer.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Erro ao buscar sugestões:', error);
            suggestionsContainer.style.display = 'none';
        });
});

// Função para buscar os dados do empenho e preencher os campos
function fetchEmpenhoData(empenhoNumero) {
    fetch(`cadastro_financeiro.php?get_empenho_data=${empenhoNumero}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error(data.error);
                return;
            }
            
            // Preenche os campos com os dados do empenho
            document.getElementById('cliente_uasg').value = data.cliente_uasg || '';
            
            // Seleciona o cliente no select
            const clienteSelect = document.getElementById('cliente_id');
            if (data.cliente_id) {
                for (let i = 0; i < clienteSelect.options.length; i++) {
                    if (clienteSelect.options[i].value == data.cliente_id) {
                        clienteSelect.options[i].selected = true;
                        break;
                    }
                }
            } else if (data.cliente_nome) {
                // Se não tiver cliente_id mas tiver cliente_nome, tenta encontrar pelo nome
                for (let i = 0; i < clienteSelect.options.length; i++) {
                    if (clienteSelect.options[i].text === data.cliente_nome) {
                        clienteSelect.options[i].selected = true;
                        break;
                    }
                }
            }
        })
        .catch(error => {
            console.error('Erro ao buscar dados do empenho:', error);
        });
}

// Evento para quando o usuário seleciona um cliente no select
document.getElementById('cliente_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const uasg = selectedOption.getAttribute('data-uasg');
    
    if (uasg) {
        document.getElementById('cliente_uasg').value = uasg;
    }
});

// Evento para quando o usuário termina de digitar o empenho (blur)
document.getElementById('empenho').addEventListener('blur', function() {
    const empenhoValue = this.value.trim();
    
    if (empenhoValue) {
        fetchEmpenhoData(empenhoValue);
    }
});

// Fecha as sugestões quando o usuário clica fora
document.addEventListener('click', function(e) {
    const suggestionsContainer = document.getElementById('empenho-suggestions');
    const empenhoInput = document.getElementById('empenho');
    
    if (e.target !== empenhoInput && e.target !== suggestionsContainer) {
        suggestionsContainer.style.display = 'none';
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
    
    // Mostra o modal de sucesso se o cadastro foi bem-sucedido
    <?php if ($success): ?>
    document.getElementById('successModal').style.display = 'block';
    <?php endif; ?>
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

// Função para fechar o modal de sucesso
function closeSuccessModal() {
    document.getElementById('successModal').style.display = 'none';
    window.location.href = 'consulta_financeiro.php'; // Redireciona para a página de consulta
}
</script>

</body>
</html>