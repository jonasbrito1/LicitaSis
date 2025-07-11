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
$success = "";
$financeiros = [];
$searchTerm = "";

// Conexão com o banco de dados
require_once('db.php');

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];

    // Consulta ao banco de dados para pesquisar registros financeiros por UASG ou Empenho
    try {
        $sql = "SELECT * FROM financeiro WHERE cliente_uasg LIKE :searchTerm OR empenho LIKE :searchTerm";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();

        $financeiros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    // Consulta para mostrar todos os registros financeiros ao carregar a página
    try {
        $sql = "SELECT * FROM financeiro ORDER BY empenho ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $financeiros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar todos os registros financeiros: " . $e->getMessage();
    }
}

// Limpa a pesquisa ao resetar a página
if (isset($_GET['clear_search'])) {
    header("Location: consulta_financeiro.php");
    exit();
}

// Verifica se foi feita uma requisição AJAX para pegar os dados financeiros
if (isset($_GET['get_financeiro_id'])) {
    $id = $_GET['get_financeiro_id'];
    try {
        $sql = "SELECT * FROM financeiro WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $financeiro = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode($financeiro); // Retorna os dados financeiros em formato JSON
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar registro financeiro: ' . $e->getMessage()]);
        exit();
    }
}

// Função para editar o registro financeiro
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_financeiro'])) {
    $id = $_POST['id'];
    $empenho = $_POST['empenho'];
    $cliente_uasg = $_POST['cliente_uasg'];
    $produto = $_POST['produto'];
    $transportadora = $_POST['transportadora'];
    $observacao = $_POST['observacao'];
    $pregao = $_POST['pregao'];
    $comprovante = $_POST['comprovante'];
    $nf = $_POST['nf'];
    $data = $_POST['data'];
    $valor = $_POST['valor'];
    $tipo = $_POST['tipo'];

    try {
        // Atualizando os dados financeiros no banco
        $sql = "UPDATE financeiro SET empenho = :empenho, cliente_uasg = :cliente_uasg, produto = :produto, transportadora = :transportadora, observacao = :observacao, pregao = :pregao, comprovante = :comprovante, nf = :nf, data = :data, valor = :valor, tipo = :tipo WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':empenho', $empenho);
        $stmt->bindParam(':cliente_uasg', $cliente_uasg);
        $stmt->bindParam(':produto', $produto);
        $stmt->bindParam(':transportadora', $transportadora);
        $stmt->bindParam(':observacao', $observacao);
        $stmt->bindParam(':pregao', $pregao);
        $stmt->bindParam(':comprovante', $comprovante);
        $stmt->bindParam(':nf', $nf);
        $stmt->bindParam(':data', $data);
        $stmt->bindParam(':valor', $valor);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->execute();

        // Redireciona para a consulta de financeiro com a mensagem de sucesso
        header("Location: consulta_financeiro.php?success=Empenho atualizado com sucesso!");
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao atualizar o empenho: ' . $e->getMessage()]);
    }
}

// Função para excluir o empenho
if (isset($_GET['delete_financeiro_id'])) {
    $id = $_GET['delete_financeiro_id'];
    try {
        $sql = "DELETE FROM financeiro WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        // Redireciona para a consulta de financeiro com a mensagem de sucesso
        header("Location: consulta_financeiro.php?success=Cadastro excluído com sucesso!");
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao excluir o cadastro: ' . $e->getMessage()]);
        exit();
    }
}

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta Financeiro - LicitaSis</title>
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

        /* Formulário de pesquisa */
        .search-bar {
            display: flex;
            flex-direction: column;
            margin-bottom: 1.5rem;
        }

        .search-bar label {
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--primary-dark);
        }

        .search-bar input {
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
        }

        .search-bar input:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.2);
            outline: none;
        }

        /* Tabela */
        .table-container {
            margin-top: 1.5rem;
            overflow-x: auto;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        table th, table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border: 1px solid var(--border-color);
        }

        table th {
            background: var(--secondary-color);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        table tr:nth-child(even) {
            background-color: var(--light-gray);
        }

        table tr:hover {
            background-color: rgba(0, 191, 174, 0.05);
        }

        table a {
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        table a:hover {
            color: var(--secondary-dark);
            text-decoration: underline;
        }

        /* Botões */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .action-button {
            padding: 0.75rem 1.25rem;
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
            min-width: 150px;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        .action-button:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        .action-button:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0, 191, 174, 0.2);
        }

        .delete-button {
            background: linear-gradient(135deg, #ff6b6b 0%, #dc3545 100%);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.2);
        }

        .delete-button:hover {
            background: linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%);
            box-shadow: 0 6px 12px rgba(220, 53, 69, 0.3);
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
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow-hover);
            width: 80%;
            max-width: 600px;
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .close {
            color: var(--medium-gray);
            float: right;
            font-size: 1.75rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
        }

        .close:hover {
            color: var(--dark-gray);
        }

        .modal h2 {
            margin-top: 0.5rem;
        }

        .modal form {
            display: grid;
            grid-gap: 1rem;
            margin-top: 1.5rem;
        }

        .modal label {
            font-weight: 500;
            margin-bottom: 0.25rem;
            color: var(--primary-dark);
        }

        .modal input, .modal textarea, .modal select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
        }

        .modal input:focus, .modal textarea:focus, .modal select:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.2);
            outline: none;
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
            
            .action-button {
                min-width: 130px;
                padding: 0.75rem 1rem;
            }
            
            .modal-content {
                width: 90%;
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
            
            .action-button {
                width: 100%;
                max-width: 300px;
            }
            
            .search-bar {
                flex-direction: column;
            }
            
            .search-bar input {
                margin-bottom: 1rem;
            }
            
            .modal-content {
                margin: 10% auto;
                width: 95%;
                padding: 1.25rem;
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
            
            .search-bar label {
                font-size: 0.9rem;
            }
            
            .search-bar input {
                padding: 0.7rem 0.9rem;
                font-size: 0.9rem;
            }
            
            .btn-container {
                margin-top: 1.25rem;
                gap: 0.75rem;
            }
            
            .action-button {
                padding: 0.7rem 1rem;
                font-size: 0.9rem;
            }
            
            .mobile-menu-btn {
                font-size: 1.3rem;
                right: 0.75rem;
                top: 0.4rem;
            }
            
            .modal-content {
                margin: 15% auto;
                padding: 1rem;
            }
            
            .modal h2 {
                font-size: 1.2rem;
            }
            
            .modal label {
                font-size: 0.9rem;
            }
            
            .modal input, .modal textarea, .modal select {
                padding: 0.6rem 0.8rem;
                font-size: 0.9rem;
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
            
            .action-button {
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
    <h2>Consulta de Registros Financeiros</h2>

    <?php if ($error) { echo "<p class='error'>$error</p>"; } ?>
    <?php if (isset($_GET['success'])) { echo "<p class='success'>{$_GET['success']}</p>"; } ?>

    <form action="consulta_financeiro.php" method="GET">
        <div class="search-bar">
            <label for="search">Pesquisar por Empenho ou UASG:</label>
            <input type="text" name="search" id="search" placeholder="Digite o Empenho ou UASG" value="<?php echo htmlspecialchars($searchTerm); ?>">
        </div>

        <div class="btn-container">
            <button type="submit" class="action-button">
                <i class="fas fa-search"></i> Pesquisar
            </button>
            <a href="consulta_financeiro.php" class="action-button">
                <i class="fas fa-sync-alt"></i> Limpar
            </a>
        </div>
    </form>

    <?php if (count($financeiros) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>NF</th>
                        <th>Cliente (UASG)</th>
                        <th>Valor</th>
                        <th>Tipo</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($financeiros as $financeiro): ?>
                        <tr>
                            <td>
                                <a href="javascript:void(0);" onclick="openModal(<?php echo $financeiro['id']; ?>)">
                                    <?php echo htmlspecialchars($financeiro['nf']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($financeiro['cliente_uasg']); ?></td>
                            <td>R$ <?php echo number_format($financeiro['valor'], 2, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars($financeiro['tipo']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($financeiro['data'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="error">Nenhum registro encontrado.</p>
    <?php endif; ?>
</div>

<!-- Modal de Edição e Exclusão -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h2>Detalhes do Registro Financeiro</h2>
        <form method="POST" action="consulta_financeiro.php">
            <input type="hidden" name="id" id="financeiro_id">
            
            <div>
                <label for="empenho">Empenho:</label>
                <input type="text" name="empenho" id="empenho" readonly>
            </div>
            
            <div>
                <label for="cliente_uasg">Cliente (UASG):</label>
                <input type="text" name="cliente_uasg" id="cliente_uasg" readonly>
            </div>
            
            <div>
                <label for="produto">Produto(s):</label>
                <input type="text" name="produto" id="produto" readonly>
            </div>
            
            <div>
                <label for="transportadora">Transportadora:</label>
                <input type="text" name="transportadora" id="transportadora" readonly>
            </div>
            
            <div>
                <label for="observacao">Observação:</label>
                <textarea name="observacao" id="observacao" readonly></textarea>
            </div>
            
            <div>
                <label for="pregao">Pregão:</label>
                <input type="text" name="pregao" id="pregao" readonly>
            </div>
            
            <div>
                <label for="comprovante">Comprovante:</label>
                <input type="text" name="comprovante" id="comprovante" readonly>
            </div>
            
            <div>
                <label for="nf">NF:</label>
                <input type="text" name="nf" id="nf" readonly>
            </div>
            
            <div>
                <label for="data">Data:</label>
                <input type="date" name="data" id="data" readonly>
            </div>
            
            <div>
                <label for="valor">Valor:</label>
                <input type="number" name="valor" id="valor" step="0.01" readonly>
            </div>
            
            <div>
                <label for="tipo">Tipo:</label>
                <select name="tipo" id="tipo" disabled>
                    <option value="Receita">Receita</option>
                    <option value="Despesa">Despesa</option>
                </select>
            </div>

            <div class="btn-container">
                <button type="button" id="editBtn" class="action-button" onclick="enableEditing()">
                    <i class="fas fa-edit"></i> Editar
                </button>
                <button type="submit" name="update_financeiro" id="saveBtn" class="action-button" style="display: none;">
                    <i class="fas fa-save"></i> Salvar
                </button>
                <a href="#" id="deleteBtn" class="action-button delete-button" onclick="confirmDelete()">
                    <i class="fas fa-trash-alt"></i> Excluir
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Modal de Confirmação de Exclusão -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <span class="close" onclick="closeDeleteModal()">&times;</span>
        <h2>Confirmar Exclusão</h2>
        <p>Tem certeza que deseja excluir este registro financeiro? Esta ação não pode ser desfeita.</p>
        <div class="btn-container">
            <button type="button" class="action-button" onclick="closeDeleteModal()">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <a href="#" id="confirmDeleteBtn" class="action-button delete-button">
                <i class="fas fa-trash-alt"></i> Confirmar Exclusão
            </a>
        </div>
    </div>
</div>

<script>
// Função para abrir o modal e carregar os dados do registro financeiro
function openModal(id) {
    // Faz uma requisição AJAX para obter os dados do registro financeiro
    fetch(`consulta_financeiro.php?get_financeiro_id=${id}`)
        .then(response => response.json())
        .then(data => {
            // Preenche os campos do formulário com os dados obtidos
            document.getElementById('financeiro_id').value = data.id;
            document.getElementById('empenho').value = data.empenho;
            document.getElementById('cliente_uasg').value = data.cliente_uasg;
            document.getElementById('produto').value = data.produto;
            document.getElementById('transportadora').value = data.transportadora;
            document.getElementById('observacao').value = data.observacao;
            document.getElementById('pregao').value = data.pregao;
            document.getElementById('comprovante').value = data.comprovante;
            document.getElementById('nf').value = data.nf;
            document.getElementById('data').value = data.data;
            document.getElementById('valor').value = data.valor;
            
            // Seleciona o tipo correto no select
            const tipoSelect = document.getElementById('tipo');
            for (let i = 0; i < tipoSelect.options.length; i++) {
                if (tipoSelect.options[i].value === data.tipo) {
                    tipoSelect.options[i].selected = true;
                    break;
                }
            }
            
            // Exibe o modal
            document.getElementById('editModal').style.display = 'block';
        })
        .catch(error => {
            console.error('Erro ao obter dados do registro financeiro:', error);
            alert('Erro ao carregar os dados do registro financeiro. Por favor, tente novamente.');
        });
}

// Função para fechar o modal
function closeModal() {
    document.getElementById('editModal').style.display = 'none';
    
    // Reseta o formulário e desabilita a edição
    const form = document.querySelector('#editModal form');
    form.reset();
    disableEditing();
}

// Função para habilitar a edição dos campos
function enableEditing() {
    // Remove o atributo readonly dos campos
    document.getElementById('empenho').removeAttribute('readonly');
    document.getElementById('cliente_uasg').removeAttribute('readonly');
    document.getElementById('produto').removeAttribute('readonly');
    document.getElementById('transportadora').removeAttribute('readonly');
    document.getElementById('observacao').removeAttribute('readonly');
    document.getElementById('pregao').removeAttribute('readonly');
    document.getElementById('comprovante').removeAttribute('readonly');
    document.getElementById('nf').removeAttribute('readonly');
    document.getElementById('data').removeAttribute('readonly');
    document.getElementById('valor').removeAttribute('readonly');
    document.getElementById('tipo').removeAttribute('disabled');
    
    // Esconde o botão de editar e mostra o botão de salvar
    document.getElementById('editBtn').style.display = 'none';
    document.getElementById('saveBtn').style.display = 'inline-flex';
}

// Função para desabilitar a edição dos campos
function disableEditing() {
    // Adiciona o atributo readonly aos campos
    document.getElementById('empenho').setAttribute('readonly', true);
    document.getElementById('cliente_uasg').setAttribute('readonly', true);
    document.getElementById('produto').setAttribute('readonly', true);
    document.getElementById('transportadora').setAttribute('readonly', true);
    document.getElementById('observacao').setAttribute('readonly', true);
    document.getElementById('pregao').setAttribute('readonly', true);
    document.getElementById('comprovante').setAttribute('readonly', true);
    document.getElementById('nf').setAttribute('readonly', true);
    document.getElementById('data').setAttribute('readonly', true);
    document.getElementById('valor').setAttribute('readonly', true);
    document.getElementById('tipo').setAttribute('disabled', true);
    
    // Mostra o botão de editar e esconde o botão de salvar
    document.getElementById('editBtn').style.display = 'inline-flex';
    document.getElementById('saveBtn').style.display = 'none';
}

// Função para confirmar a exclusão
function confirmDelete() {
    const id = document.getElementById('financeiro_id').value;
    document.getElementById('confirmDeleteBtn').href = `consulta_financeiro.php?delete_financeiro_id=${id}`;
    document.getElementById('deleteModal').style.display = 'block';
}

// Função para fechar o modal de confirmação de exclusão
function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

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

// Fecha o modal ao clicar fora dele
window.onclick = function(event) {
    const editModal = document.getElementById('editModal');
    const deleteModal = document.getElementById('deleteModal');
    
    if (event.target === editModal) {
        closeModal();
    }
    
    if (event.target === deleteModal) {
        closeDeleteModal();
    }
};
</script>

</body>
</html>