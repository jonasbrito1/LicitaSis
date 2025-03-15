<?php 
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

$error = "";
$success = "";
$fornecedores = [];
$searchTerm = "";

// Conexão com o banco de dados
require_once('../includes/db.php');

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    
    // Consulta ao banco de dados para pesquisar fornecedores por código, nome ou telefone
    try {
        $sql = "SELECT * FROM fornecedores WHERE codigo LIKE :searchTerm OR nome LIKE :searchTerm OR telefone LIKE :searchTerm";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        
        $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    // Consulta para mostrar todos os fornecedores
    try {
        $sql = "SELECT * FROM fornecedores ORDER BY nome ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar todos os fornecedores: " . $e->getMessage();
    }
}

// Limpa a pesquisa ao resetar a página
if (isset($_GET['clear_search'])) {
    header("Location: consulta_fornecedores.php");
    exit();
}

// Função para atualizar o fornecedor
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_fornecedor'])) {
    $id = $_POST['id'];
    $codigo = $_POST['codigo'];
    $nome = $_POST['nome'];
    $cnpj = $_POST['cnpj'];
    $endereco = $_POST['endereco'];
    $telefone = $_POST['telefone'];
    $email = $_POST['email'];
    $observacoes = $_POST['observacoes'];

    try {
        // Atualiza os dados do fornecedor na tabela 'fornecedores'
        $sql = "UPDATE fornecedores SET codigo = :codigo, nome = :nome, cnpj = :cnpj, endereco = :endereco, telefone = :telefone, email = :email, observacoes = :observacoes WHERE id = :id";
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

        // Após a atualização, redireciona para a página de consulta de fornecedores
        header("Location: consulta_fornecedores.php?success=Fornecedor atualizado com sucesso!");
        exit();
    } catch (PDOException $e) {
        // Se houver erro, retorna a mensagem de erro em JSON
        echo json_encode(['error' => 'Erro ao atualizar o fornecedor: ' . $e->getMessage()]);
        exit();
    }
}

// Verifica se foi feita uma requisição AJAX para pegar os dados do fornecedor
if (isset($_GET['get_fornecedor_id'])) {
    $id = $_GET['get_fornecedor_id'];
    try {
        $sql = "SELECT * FROM fornecedores WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode($fornecedor); // Retorna os dados do fornecedor em formato JSON
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
    <title>Consulta de Fornecedores - ComBraz</title>
    <style>
        /* Estilos gerais */
        html, body {
            height: 100%;
            margin: 0;
            overflow: auto;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
        }

        header {
            background-color: rgb(157, 206, 173); 
            padding: 10px 0;
            text-align: center;
            color: white;
            width: 100%;
            box-sizing: border-box;
        }

        .logo {
            max-width: 180px;
            height: auto;
        }

        nav {
            background-color: #2D893E;
            padding: 10px;
            text-align: center;
        }

        nav a {
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            font-size: 16px;
            margin: 0 10px;
            border-radius: 5px;
        }

        nav a:hover {
            background-color: #009d8f;
        }

        .container {
            max-width: 600px;
            margin: 50px auto;
            background-color: rgb(215, 212, 212);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(240, 240, 240, 0.1);
            color: #2D893E;
            box-sizing: border-box;
            height: auto;
            position: relative;
            overflow-y: auto;
        }

        h2 {
            text-align: center;
            color: #2D893E;
            margin-bottom: 30px;
            font-size: 1.8em;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: white;
            overflow-x: auto;
            display: block;
        }

        table th, table td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
            white-space: nowrap;
        }

        table th {
            background-color: #00bfae;
            color: white;
        }

        .table-container {
            max-height: 400px;
            overflow-y: auto;
            width: 100%;
            overflow-x: auto; /* Rolagem horizontal */
        }

        /* Estilos do Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            padding-top: 60px;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            height: auto;
            max-height: 70%;
            overflow-y: auto;
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

        /* Estilização do campo de pesquisa */
        .search-bar {
            display: flex;
            flex-direction: row;
            align-items: center;
            margin-bottom: 20px;
        }

        .search-bar input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            margin-right: 10px;
            transition: all 0.3s;
        }

        .search-bar input:focus {
            border-color: #00bfae;
            outline: none;
        }

        /* Estilização dos botões */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }

        .btn-container button {
            padding: 12px 20px;
            font-size: 16px;
            background-color: #00bfae;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .btn-container button:hover {
            background-color: #009d8f;
        }

        /* Responsividade da tabela */
        @media (max-width: 768px) {
            table, th, td {
                display: block;
                width: 100%;
            }

            table th {
                text-align: center;
            }

            table td {
                padding: 10px;
                text-align: left;
                border: 1px solid #ddd;
                display: block;
                width: 100%;
            }

            table td:before {
                content: attr(data-label);
                font-weight: bold;
                display: inline-block;
                width: 100%;
            }
        }
    </style>
</head>
<body>

<header>
    <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
</header>

<nav>
    <a href="sistema.php">Início</a>
    <a href="clientes.php">Clientes</a>
    <a href="produtos.php">Produtos</a>
    <a href="empenhos.php">Empenhos</a>
    <a href="financeiro.php">Financeiro</a>
    <a href="transportadoras.php">Transportadoras</a>
    <a href="fornecedores.php">Fornecedores</a>
    <a href="vendas.php">Vendas</a>
</nav>

<div class="container">
    <h2>Consulta de Fornecedores</h2>

    <!-- Exibe a mensagem de erro ou sucesso -->
    <?php if ($error) { echo "<p class='error'>$error</p>"; } ?>
    <?php if ($success) { echo "<p class='success'>$success</p>"; } ?>

    <!-- Formulário de pesquisa -->
    <form action="consulta_fornecedores.php" method="GET">
        <div class="search-bar">
            <label for="search">Pesquisar por Código, CNPJ ou Nome:</label>
            <input type="text" name="search" id="search" placeholder="Digite Código, CNPJ ou Nome" value="<?php echo htmlspecialchars($searchTerm); ?>">
        </div>

        <div class="btn-container">
            <button type="submit">Pesquisar</button>
            <button type="submit" name="clear_search" value="1">Limpar Pesquisa</button>
            <a href="cadastro_fornecedores.php"><button type="button">Cadastro de Fornecedor</button></a>
        </div>
    </form>

    <!-- Exibe os resultados da pesquisa, se houver -->
    <?php if (count($fornecedores) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nome</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fornecedores as $fornecedor): ?>
                        <tr>
                            <td><a href="javascript:void(0);" onclick="openModal(<?php echo $fornecedor['id']; ?>)"><?php echo htmlspecialchars($fornecedor['codigo']); ?></a></td>
                            <td><a href="javascript:void(0);" onclick="openModal(<?php echo $fornecedor['id']; ?>)"><?php echo htmlspecialchars($fornecedor['nome']); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>Nenhum fornecedor encontrado.</p>
    <?php endif; ?>

</div>

<!-- Modal para Edição -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h2>Editar Fornecedor</h2>
        <form method="POST" action="consulta_fornecedores.php">
            <input type="hidden" name="id" id="fornecedor_id">
            <label for="codigo">Código:</label>
            <input type="text" name="codigo" id="codigo" readonly>
            <label for="nome">Nome:</label>
            <input type="text" name="nome" id="nome" readonly>
            <label for="cnpj">CNPJ:</label>
            <input type="text" name="cnpj" id="cnpj" readonly>
            <label for="endereco">Endereço:</label>
            <input type="text" name="endereco" id="endereco" readonly>
            <label for="telefone">Telefone:</label>
            <input type="text" name="telefone" id="telefone" readonly>
            <label for="email">E-mail:</label>
            <input type="email" name="email" id="email" readonly>
            <label for="observacoes">Observações:</label>
            <textarea name="observacoes" id="observacoes" readonly></textarea>
            <button type="submit" name="update_fornecedor" id="saveBtn" style="display: none;">Salvar</button>
            <button type="button" class="action-button" id="editBtn" onclick="enableEditing()">Editar</button>
        </form>
    </div>
</div>

<script>
// Função para abrir o modal e carregar os dados do fornecedor
function openModal(id) {
    var modal = document.getElementById("editModal");
    modal.style.display = "block";

    // Carregar os dados do fornecedor no formulário via requisição GET
    fetch('consulta_fornecedores.php?get_fornecedor_id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('fornecedor_id').value = data.id;
            document.getElementById('codigo').value = data.codigo;
            document.getElementById('nome').value = data.nome;
            document.getElementById('cnpj').value = data.cnpj;
            document.getElementById('endereco').value = data.endereco;
            document.getElementById('telefone').value = data.telefone;
            document.getElementById('email').value = data.email;
            document.getElementById('observacoes').value = data.observacoes;
        });
}

// Função para habilitar a edição no modal
function enableEditing() {
    var inputs = document.querySelectorAll('#editModal input, #editModal textarea');
    inputs.forEach(input => {
        input.removeAttribute('readonly'); // Remover a restrição readonly
    });
    document.getElementById('saveBtn').style.display = 'inline-block'; // Exibe o botão Salvar
    document.getElementById('editBtn').style.display = 'none'; // Esconde o botão Editar
}

// Função para fechar o modal
function closeModal() {
    var modal = document.getElementById("editModal");
    modal.style.display = "none";
}
</script>

</body>
</html>
