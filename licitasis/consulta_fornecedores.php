<?php 
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

// Permissão de administrador
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";
$fornecedores = [];
$searchTerm = "";

// Conexão com o banco de dados
require_once('db.php');

// Pesquisa
if (isset($_GET['search']) && $_GET['search'] !== "") {
    $searchTerm = $_GET['search'];
    try {
        $sql = "SELECT * FROM fornecedores WHERE codigo LIKE :search OR cnpj LIKE :search OR nome LIKE :search";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':search', "%{$searchTerm}%");
        $stmt->execute();
        $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    try {
        $sql = "SELECT * FROM fornecedores ORDER BY nome ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar fornecedores: " . $e->getMessage();
    }
}

// Limpar busca
if (isset($_GET['clear_search'])) {
    header("Location: consulta_fornecedores.php");
    exit();
}

// Atualizar fornecedor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fornecedor'])) {
    $id = $_POST['id'];
    $codigo = $_POST['codigo'];
    $nome = $_POST['nome'];
    $cnpj = $_POST['cnpj'];
    $endereco = $_POST['endereco'];
    $telefone = $_POST['telefone'];
    $email = $_POST['email'];
    $observacoes = $_POST['observacoes'];
    try {
        $sql = "UPDATE fornecedores 
                SET codigo = :codigo, nome = :nome, cnpj = :cnpj, endereco = :endereco, telefone = :telefone, email = :email, observacoes = :observacoes 
                WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':codigo', $codigo);
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':cnpj', $cnpj);
        $stmt->bindParam(':endereco', $endereco);
        $stmt->bindParam(':telefone', $telefone);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':observacoes', $observacoes);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        header("Location: consulta_fornecedores.php?success=Fornecedor atualizado com sucesso!");
        exit();
    } catch (PDOException $e) {
        header("Location: consulta_fornecedores.php?error=" . urlencode("Erro ao atualizar fornecedor: " . $e->getMessage()));
        exit();
    }
}

// Excluir fornecedor
if (isset($_GET['delete_fornecedor_id'])) {
    $id = $_GET['delete_fornecedor_id'];
    try {
        $sql = "DELETE FROM fornecedores WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        header("Location: consulta_fornecedores.php?success=Fornecedor excluído com sucesso!");
        exit();
    } catch (PDOException $e) {
        header("Location: consulta_fornecedores.php?error=" . urlencode("Erro ao excluir fornecedor: " . $e->getMessage()));
        exit();
    }
}

// Busca dados para edição via AJAX
if (isset($_GET['get_fornecedor_id'])) {
    $id = $_GET['get_fornecedor_id'];
    try {
        $sql = "SELECT * FROM fornecedores WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar fornecedor: ' . $e->getMessage()]);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Fornecedores - LicitaSis</title>
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
            justify-content: center;
            margin-bottom: 20px;
        }

        .search-bar input {
            width: 50%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
        }

        .search-bar button {
            padding: 10px;
            background-color: #00bfae;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            margin-left: 10px;
        }

        .search-bar button:hover {
            background-color: #009d8f;
        }

        /* Tabela de resultados */
        .table-container {
            overflow-y: auto;
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
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .modal-content form {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .modal-content input, .modal-content textarea {
            width: 100%;
            padding: 12px;
            border-radius: 5px;
            border: 1px solid #ddd;
            font-size: 16px;
            box-sizing: border-box;
        }

        .modal-content button {
            padding: 12px 20px;
            font-size: 16px;
            background-color: #00bfae;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .modal-content button:hover {
            background-color: #009d8f;
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
        }

        @media (max-width: 992px) {
            .container {
                padding: 1.5rem;
            }
            
            .search-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-bar input {
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
                padding: 1.5rem;
                margin: 1.5rem auto;
            }
            
            h2 {
                font-size: 1.5rem;
            }
            
            .search-bar {
                flex-direction: column;
            }
            
            .search-bar input {
                width: 100%;
            }
            
            .modal-content {
                padding: 1.5rem;
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
            
            .search-bar input {
                padding: 0.75rem 0.875rem;
                font-size: 0.95rem;
            }
            
            .search-bar button {
                padding: 0.7rem 1rem;
                font-size: 0.9rem;
            }
            
            .modal-content {
                padding: 1rem;
            }
        }

        @media (max-width: 360px) {
            .logo {
                max-width: 100px;
            }
            
            .container {
                padding: 0.875rem;
                margin: 0.75rem auto;
            }
            
            h2 {
                font-size: 1.2rem;
            }
            
            .search-bar input {
                padding: 0.7rem 0.8rem;
                font-size: 0.9rem;
            }
            
            .search-bar button {
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
    <h2>Consulta de Fornecedores</h2>

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
        <form method="GET" action="consulta_fornecedores.php">
            <div class="search-bar">
                <input type="text" name="search" placeholder="Código, CNPJ ou Nome" value="<?= htmlspecialchars($searchTerm) ?>">
                <button type="submit">Pesquisar</button>
                <?php if ($searchTerm): ?>
                    <button type="submit" name="clear_search">Limpar</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (count($fornecedores) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>CNPJ</th>
                        <th>Nome</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fornecedores as $f): ?>
                        <tr>
                            <td>
                                <a href="javascript:void(0)" onclick="openModal(<?= $f['id'] ?>)"><?= htmlspecialchars($f['cnpj']) ?></a>
                            </td>
                            <td><?= htmlspecialchars($f['nome']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-error" style="text-align: center;">
            <i class="fas fa-info-circle"></i>
            Nenhum fornecedor encontrado.
        </div>
    <?php endif; ?>
</div>

<!-- Modal de Edição -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h2>Editar Fornecedor</h2>
        <form method="POST" action="consulta_fornecedores.php">
            <input type="hidden" name="id" id="fornecedor_id">
            <label>Código:<input type="text" name="codigo" id="codigo" readonly></label>
            <label>Nome:<input type="text" name="nome" id="nome" readonly></label>
            <label>CNPJ:<input type="text" name="cnpj" id="cnpj" readonly></label>
            <label>Endereço:<input type="text" name="endereco" id="endereco" readonly></label>
            <label>Telefone:<input type="text" name="telefone" id="telefone" readonly></label>
            <label>E-mail:<input type="email" name="email" id="email" readonly></label>
            <label>Observações:<textarea name="observacoes" id="observacoes" readonly></textarea></label>
            <button type="submit" name="update_fornecedor" id="saveBtn" style="display:none;">Salvar</button>
            <div class="action-buttons">
                <button type="button" id="editBtn" onclick="enableEditing()">Editar</button>
                <button type="button" id="deleteBtn" onclick="openDeleteModal()">Excluir</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de Confirmação de Exclusão -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeDeleteModal()">&times;</span>
        <h2>Deseja realmente excluir este fornecedor?</h2>
        <div class="action-buttons">
            <button type="button" onclick="deleteFornecedor()">Sim, excluir</button>
            <button type="button" onclick="closeDeleteModal()">Cancelar</button>
        </div>
    </div>
</div>

<script>
// Função para abrir o modal e carregar os dados do fornecedor
function openModal(id) {
    fetch(`consulta_fornecedores.php?get_fornecedor_id=${id}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('fornecedor_id').value = data.id;
            document.getElementById('codigo').value = data.codigo;
            document.getElementById('nome').value = data.nome;
            document.getElementById('cnpj').value = data.cnpj;
            document.getElementById('endereco').value = data.endereco;
            document.getElementById('telefone').value = data.telefone;
            document.getElementById('email').value = data.email;
            document.getElementById('observacoes').value = data.observacoes;
            document.getElementById('saveBtn').style.display = 'none';
            document.getElementById('editBtn').style.display = 'inline-block';
            document.getElementById('editModal').style.display = 'block';
        });
}

// Função para habilitar a edição no modal
function enableEditing() {
    document.querySelectorAll('#editModal input, #editModal textarea')
        .forEach(i => i.removeAttribute('readonly')); // Remover a restrição readonly
    document.getElementById('saveBtn').style.display = 'inline-block'; // Exibe o botão Salvar
    document.getElementById('editBtn').style.display = 'none'; // Esconde o botão Editar
}

// Função para fechar o modal
function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}

// Função para abrir o modal de exclusão
function openDeleteModal() {
    document.getElementById('deleteModal').style.display = 'block';
    window.fornecedorToDelete = document.getElementById('fornecedor_id').value;
}

// Função para excluir o fornecedor
function deleteFornecedor() {
    window.location.href = `consulta_fornecedores.php?delete_fornecedor_id=${window.fornecedorToDelete}`;
}

// Função para fechar o modal de exclusão
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

// Fecha o modal quando o usuário clica no "X"
document.querySelector('.close').onclick = function() {
    closeModal();
}

// Fecha o modal ao pressionar a tecla "Esc"
document.addEventListener('keydown', function(event) {
    if (event.key === "Escape") {
        closeModal();
    }
});
</script>

</body>
</html>