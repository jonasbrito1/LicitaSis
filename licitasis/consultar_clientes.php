<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

// Inicializa a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

// Conexão com o banco de dados
require_once('db.php');

$error = "";
$success = "";
$clientes = [];
$searchTerm = "";

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    
    // Consulta ao banco de dados para pesquisar clientes por Nome ou UASG
    try {
        $sql = "SELECT * FROM clientes WHERE nome_orgaos LIKE :searchTerm OR uasg LIKE :searchTerm";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    // Consulta para mostrar todos os clientes ao carregar a página
    try {
        $sql = "SELECT * FROM clientes ORDER BY nome_orgaos ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar todos os clientes: " . $e->getMessage();
    }
}

// Limpa a pesquisa ao resetar a página
if (isset($_GET['clear_search'])) {
    header("Location: consultar_clientes.php");
    exit();
}

// Função para excluir o cliente
if (isset($_GET['delete_client_id'])) {
    $id = $_GET['delete_client_id'];
    try {
        // Exclui o cliente do banco de dados
        $sql = "DELETE FROM clientes WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $success = "Cliente excluído com sucesso!";
        header("Location: consultar_clientes.php?success=$success");
        exit();
    } catch (PDOException $e) {
        $error = "Erro ao excluir o cliente: " . $e->getMessage();
    }
}

// Função para atualizar o cliente
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_client'])) {
    $id = $_POST['id'];
    $uasg = $_POST['uasg'];
    $cnpj = $_POST['cnpj'];
    $nome_orgaos = $_POST['nome_orgaos'];
    $endereco = $_POST['endereco'];
    $telefone = implode('/', $_POST['telefone']); // Concatena os telefones com "/"
    $email = $_POST['email'];
    $observacoes = $_POST['observacoes'];

    try {
        // Atualiza os dados do cliente na tabela 'clientes'
        $sql = "UPDATE clientes SET uasg = :uasg, cnpj = :cnpj, nome_orgaos = :nome_orgaos, endereco = :endereco, telefone = :telefone, email = :email, observacoes = :observacoes WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':uasg', $uasg);
        $stmt->bindParam(':cnpj', $cnpj);
        $stmt->bindParam(':nome_orgaos', $nome_orgaos);
        $stmt->bindParam(':endereco', $endereco);
        $stmt->bindParam(':telefone', $telefone);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':observacoes', $observacoes);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $success = "Cliente atualizado com sucesso!";
        // Redireciona para a página de consulta de clientes
        header("Location: consultar_clientes.php?success=$success");
        exit();
    } catch (PDOException $e) {
        $error = "Erro ao atualizar o cliente: " . $e->getMessage();
    }
}

// Verifica se foi feita uma requisição AJAX para pegar os dados do cliente
if (isset($_GET['get_cliente_id'])) {
    $id = $_GET['get_cliente_id'];
    try {
        $sql = "SELECT * FROM clientes WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode($cliente); // Retorna os dados do cliente em formato JSON
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar cliente: ' . $e->getMessage()]);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Clientes - LicitaSis</title>
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
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Barra de pesquisa */
        .search-bar {
            display: flex;
            margin-bottom: 2rem;
            gap: 1rem;
            align-items: center;
        }

        .search-bar label {
            font-weight: 600;
            color: var(--primary-color);
            white-space: nowrap;
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

        /* Botões de ação */
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
        }

        .action-button:hover {
            background: #009d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
        }

        .action-button-vendas {
            background: var(--secondary-color);
            color: white;
            padding: 0.5rem 1rem;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            border-radius: var(--radius);
            transition: var(--transition);
            display: inline-block;
            text-align: center;
            width: 100%;
        }

        .action-button-vendas:hover {
            background: #009d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.3);
        }

        .action-button-empenhos {
            background: var(--warning-color);
            color: var(--dark-gray);
            padding: 0.5rem 1rem;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            border-radius: var(--radius);
            transition: var(--transition);
            display: inline-block;
            text-align: center;
            width: 100%;
        }

        .action-button-empenhos:hover {
            background: #e0a800;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3);
        }

        /* Botões no container */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
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

        /* Formulário do modal */
        .form-group {
            margin-bottom: 1.5rem;
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

        input[readonly], select[disabled], textarea[readonly] {
            background: var(--light-gray);
            color: var(--medium-gray);
            cursor: not-allowed;
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* Telefone container */
        #telefone-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .whatsapp-icon {
            width: 24px;
            height: 24px;
            transition: var(--transition);
        }

        .whatsapp-icon:hover {
            transform: scale(1.2);
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
                overflow-x: auto;
                white-space: nowrap;
            }

            nav a {
                padding: 0.625rem 0.75rem;
                font-size: 0.85rem;
                margin: 0 0.25rem;
            }

            .dropdown-content {
                min-width: 180px;
            }

            .container {
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .search-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .search-bar label {
                margin-bottom: 0.5rem;
            }

            .btn-container {
                flex-direction: column;
            }

            .action-button, 
            .action-button-vendas, 
            .action-button-empenhos {
                width: 100%;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
        }

        @media (max-width: 480px) {
            .logo {
                max-width: 100px;
            }

            nav a {
                padding: 0.5rem 0.625rem;
                font-size: 0.8rem;
                margin: 0 0.125rem;
            }

            .container {
                padding: 1.25rem;
                margin: 0.5rem;
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
        }

        /* Scrollbar personalizada */
        .table-container::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: var(--light-gray);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: var(--medium-gray);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: var(--dark-gray);
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
    <h2>Consulta de Clientes</h2>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
  
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form action="consultar_clientes.php" method="GET">
        <div class="search-bar">
            <label for="search">Pesquisar por Nome ou UASG:</label>
            <input type="text" 
                   name="search" 
                   id="search" 
                   placeholder="Digite o Nome ou UASG" 
                   value="<?php echo htmlspecialchars($searchTerm); ?>"
                   autocomplete="off">
            <button type="submit" class="action-button">
                <i class="fas fa-search"></i> Pesquisar
            </button>
        </div>
    </form>

    <?php if (count($clientes) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>UASG</th>
                        <th>Nome do Órgão</th>
                        <th>Vendas</th>
                        <th>Empenhos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $cliente): ?>
                        <tr>
                            <td>
                                <a href="javascript:void(0);" onclick="openModal(<?php echo $cliente['id']; ?>)">
                                    <?php echo htmlspecialchars($cliente['uasg']); ?>
                                </a>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($cliente['nome_orgaos']); ?>
                            </td>
                            <td>
                                <a href="consultar_vendas_cliente.php?cliente_uasg=<?php echo $cliente['uasg']; ?>" class="action-button-vendas">
                                    <i class="fas fa-shopping-cart"></i> Ver Vendas
                                </a>
                            </td>
                            <td>
                                <a href="cliente_empenho.php?cliente_uasg=<?php echo $cliente['uasg']; ?>" class="action-button-empenhos">
                                    <i class="fas fa-file-invoice-dollar"></i> Ver Empenhos
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="no-results" style="text-align: center; padding: 3rem; color: var(--medium-gray); font-style: italic;">
            <p><i class="fas fa-users"></i> Nenhum cliente encontrado.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Modal de Edição e Exclusão -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user"></i> Detalhes do Cliente</h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="consultar_clientes.php">
                <input type="hidden" name="id" id="client_id">
                
                <div class="form-group">
                    <label for="uasg">UASG:</label>
                    <input type="text" name="uasg" id="uasg" readonly>
                </div>
                
                <div class="form-group">
                    <label for="cnpj">CNPJ:</label>
                    <input type="text" name="cnpj" id="cnpj" readonly>
                </div>
                
                <div class="form-group">
                    <label for="nome_orgaos">Nome do Órgão:</label>
                    <input type="text" name="nome_orgaos" id="nome_orgaos" readonly>
                </div>
                
                <div class="form-group">
                    <label for="endereco">Endereço:</label>
                    <input type="text" name="endereco" id="endereco" readonly>
                </div>
                
                <div class="form-group">
                    <label for="telefone">Telefone:</label>
                    <div id="telefone-container">
                        <input type="text" name="telefone[]" id="telefone" readonly>
                        <a id="whatsapp-link" target="_blank" href="">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg" class="whatsapp-icon" alt="WhatsApp">
                        </a>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="email">E-mail:</label>
                    <input type="email" name="email" id="email" readonly>
                </div>
                
                <div class="form-group">
                    <label for="observacoes">Observações:</label>
                    <textarea name="observacoes" id="observacoes" readonly></textarea>
                </div>

                <div class="btn-container">
                    <button type="submit" name="update_client" id="saveBtn" style="display: none;">
                        <i class="fas fa-save"></i> Salvar
                    </button>
                    <button type="button" class="action-button" id="editBtn" onclick="enableEditing()">
                        <i class="fas fa-edit"></i> Editar
                    </button>
                    <button type="button" class="action-button btn-danger" id="deleteBtn" onclick="openDeleteModal()">
                        <i class="fas fa-trash-alt"></i> Excluir
                    </button>
                    <a href="consultar_vendas_cliente.php?cliente_uasg=" id="ver-vendas-btn" class="action-button">
                        <i class="fas fa-shopping-cart"></i> Ver Vendas
                    </a>
                    <a href="consulta_contas_receber.php?cliente_uasg=" id="extrato-btn" class="action-button">
                        <i class="fas fa-file-invoice-dollar"></i> Extrato
                    </a>
                    <a href="cliente_empenho.php?cliente_uasg=" id="ver-empenhos-btn" class="action-button">
                        <i class="fas fa-file-invoice"></i> Ver Empenhos
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal de Confirmação de Exclusão -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle"></i> Confirmar Exclusão</h3>
            <span class="close" onclick="closeDeleteModal()">&times;</span>
        </div>
        <div class="modal-body">
            <p style="text-align: center; margin-bottom: 2rem;">
                Tem certeza que deseja excluir este cliente?<br>
                <strong style="color: var(--danger-color);">Esta ação não pode ser desfeita.</strong>
            </p>
            <div class="btn-container">
                <button type="button" class="btn-danger" onclick="deleteClient()">
                    <i class="fas fa-trash-alt"></i> Sim, excluir
                </button>
                <button type="button" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Função para abrir o modal e carregar os dados do cliente
function openModal(id) {
    var modal = document.getElementById("editModal");
    modal.style.display = "block";
    document.body.style.overflow = "hidden"; // Previne scroll da página

    fetch('consultar_clientes.php?get_cliente_id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('client_id').value = data.id;
            document.getElementById('uasg').value = data.uasg;
            document.getElementById('cnpj').value = data.cnpj;
            document.getElementById('nome_orgaos').value = data.nome_orgaos;
            document.getElementById('endereco').value = data.endereco;
            document.getElementById('telefone').value = data.telefone.split('/')[0]; // Exibe o primeiro número de telefone
            document.getElementById('whatsapp-link').href = 'https://wa.me/55' + data.telefone.split('/')[0];
            document.getElementById('email').value = data.email;
            document.getElementById('observacoes').value = data.observacoes;

            // Limpa os telefones adicionais existentes
            var container = document.getElementById("telefone-container");
            var inputs = container.querySelectorAll('input');
            var links = container.querySelectorAll('a');
            
            // Mantém apenas o primeiro telefone e link
            for (var i = 1; i < inputs.length; i++) {
                inputs[i].remove();
            }
            for (var i = 1; i < links.length; i++) {
                links[i].remove();
            }

            // Adiciona os outros telefones
            var phones = data.telefone.split('/');
            phones.slice(1).forEach(function(phone) {
                addPhoneField(phone);
            });

            // Atualiza os links com o UASG
            document.getElementById('ver-vendas-btn').href = 'consultar_vendas_cliente.php?cliente_uasg=' + data.uasg;
            document.getElementById('extrato-btn').href = 'consulta_contas_receber.php?cliente_uasg=' + data.uasg;
            document.getElementById('ver-empenhos-btn').href = 'cliente_empenho.php?cliente_uasg=' + data.uasg;
        })
        .catch(error => {
            console.error('Erro ao buscar os dados do cliente:', error);
            alert('Erro ao carregar os dados do cliente.');
        });
}

// Função para adicionar novos campos de telefone
function addPhoneField(phone = '') {
    var container = document.getElementById("telefone-container");

    var inputField = document.createElement("input");
    inputField.type = "text";
    inputField.name = "telefone[]";
    inputField.value = phone;
    inputField.readOnly = true;
    
    var link = document.createElement("a");
    link.setAttribute("target", "_blank");
    link.setAttribute("href", "https://wa.me/55" + phone);

    var icon = document.createElement("img");
    icon.src = "https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg";
    icon.classList.add("whatsapp-icon");
    icon.alt = "WhatsApp";

    link.appendChild(icon);
    container.appendChild(inputField);
    container.appendChild(link);
}

// Função para abrir o modal de exclusão
function openDeleteModal() {
    var deleteModal = document.getElementById("deleteModal");
    deleteModal.style.display = "block";
    document.body.style.overflow = "hidden";

    // Armazenar o ID do cliente a ser excluído
    window.clientToDelete = document.getElementById('client_id').value;
}

// Função para fechar o modal de exclusão
function closeDeleteModal() {
    var deleteModal = document.getElementById("deleteModal");
    deleteModal.style.display = "none";
    document.body.style.overflow = "auto";
}

// Função para excluir o cliente
function deleteClient() {
    // Redireciona para a URL de exclusão com o ID do cliente
    window.location.href = 'consultar_clientes.php?delete_client_id=' + window.clientToDelete;
}

// Função para habilitar a edição dos campos
function enableEditing() {
    var inputs = document.querySelectorAll('#editModal input:not([type="hidden"]), #editModal textarea');
    inputs.forEach(input => {
        input.removeAttribute('readonly');
    });
    
    // Adiciona botão para adicionar telefone
    var container = document.getElementById("telefone-container");
    if (!document.getElementById('add-phone-btn')) {
        var addBtn = document.createElement("button");
        addBtn.type = "button";
        addBtn.id = "add-phone-btn";
        addBtn.className = "action-button";
        addBtn.innerHTML = '<i class="fas fa-plus"></i>';
        addBtn.style.padding = "0.5rem";
        addBtn.style.marginLeft = "0.5rem";
        addBtn.onclick = function() {
            addPhoneField('');
            // Torna o novo campo editável
            var newInput = container.querySelector('input[name="telefone[]"]:last-of-type');
            if (newInput) {
                newInput.readOnly = false;
                newInput.focus();
            }
        };
        container.appendChild(addBtn);
    }
    
    document.getElementById('saveBtn').style.display = 'inline-flex';
    document.getElementById('editBtn').style.display = 'none';
}

// Função para fechar o modal
function closeModal() {
    var modal = document.getElementById("editModal");
    modal.style.display = "none";
    document.body.style.overflow = "auto";
    
    // Reset do formulário
    var inputs = document.querySelectorAll('#editModal input:not([type="hidden"]), #editModal textarea');
    inputs.forEach(input => {
        input.setAttribute('readonly', true);
    });
    
    document.getElementById('saveBtn').style.display = 'none';
    document.getElementById('editBtn').style.display = 'inline-flex';
    
    // Remove o botão de adicionar telefone
    var addBtn = document.getElementById('add-phone-btn');
    if (addBtn) {
        addBtn.remove();
    }
}

// Fecha modal ao clicar fora dele
window.onclick = function(event) {
    var editModal = document.getElementById('editModal');
    var deleteModal = document.getElementById('deleteModal');
    
    if (event.target == editModal) {
        closeModal();
    }
    if (event.target == deleteModal) {
        closeDeleteModal();
    }
}

// Tecla ESC para fechar modais
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeModal();
        closeDeleteModal();
    }
});
</script>

</body>
</html>