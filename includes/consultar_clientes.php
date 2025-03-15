<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

$error = "";
$success = "";
$clientes = [];
$searchTerm = "";

// Conexão com o banco de dados
require_once('../includes/db.php');

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

// Função para atualizar o cliente
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_client'])) {
    $id = $_POST['id'];
    $uasg = $_POST['uasg'];
    $cnpj = $_POST['cnpj'];
    $nome_orgaos = $_POST['nome_orgaos'];
    $endereco = $_POST['endereco'];
    $telefone = $_POST['telefone'];
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
    <title>Consulta de Clientes - ComBraz</title>
    <style>
        /* Estilos gerais */
        html, body {
            height: 100%;
            margin: 0;
            overflow-y: auto;
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
            max-width: 40%;
            margin: 50px auto;
            background-color: rgb(215, 212, 212);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(240, 240, 240, 0.1);
            color: #2D893E;
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
            table-layout: auto;
        }

        table th, table td {
            border: 1px solid #000;
            padding: 6px 10px;
            text-align: left;
            white-space: nowrap;
            margin: 0;
        }

        table th {
            background-color: #00bfae;
            color: white;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .table-container {
            max-height: 400px;
            overflow-y: auto;
            width: 100%;
            overflow-x: auto; /* Habilita a rolagem horizontal se necessário */
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
            overflow: auto;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px; /* Ajusta a largura */
            height: auto;
            max-height: 70%; /* Ajusta a altura */
            overflow-y: auto; /* Habilita a rolagem vertical se o conteúdo for maior que o modal */
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

        /* Estilos do botão de logout e outros */
        .action-button {
            padding: 12px 20px;
            font-size: 16px;
            background-color: #00bfae;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .action-button:hover {
            background-color: #009d8f;
        }

        .btn-container {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
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

        /* Responsividade */
        @media screen and (max-width: 768px) {
            .modal-content {
                width: 90%;
                padding: 15px;
            }

            .modal-content input, .modal-content textarea {
                font-size: 14px;
            }

            table th, table td {
                padding: 4px 8px;
            }

            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
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
    <h2>Consulta de Clientes</h2>

    <?php if ($error) { echo "<p class='error'>$error</p>"; } ?>
    <?php if ($success) { echo "<p class='success'>$success</p>"; } ?>

    <form action="consultar_clientes.php" method="GET">
        <div class="search-bar">
            <label for="search">Pesquisar por Nome ou UASG:</label>
            <input type="text" name="search" id="search" placeholder="Digite o Nome ou UASG" value="<?php echo htmlspecialchars($searchTerm); ?>">
        </div>

        <div class="btn-container">
            <button type="submit" class="action-button">Pesquisar</button>
            <button type="submit" name="clear_search" value="1" class="action-button">Limpar Pesquisa</button>
            <a href="cadastrar_clientes.php"><button type="button" class="action-button">Cadastro de Clientes</button></a>
        </div>
    </form>

    <!-- Exibe os resultados da pesquisa, se houver -->
    <?php if (count($clientes) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Nome do Órgão</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $cliente): ?>
                        <tr>
                            <td><a href="javascript:void(0);" onclick="openModal(<?php echo $cliente['id']; ?>)"><?php echo htmlspecialchars($cliente['nome_orgaos']); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>Nenhum cliente encontrado.</p>
    <?php endif; ?>
</div>

<!-- Modal para Edição -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h2>Editar Cliente</h2>
        <form method="POST" action="consultar_clientes.php">
            <input type="hidden" name="id" id="client_id">
            <label for="uasg">Uasg:</label>
            <input type="text" name="uasg" id="uasg" readonly>
            <label for="cnpj">CNPJ:</label>
            <input type="text" name="cnpj" id="cnpj" readonly>
            <label for="nome_orgaos">Nome do Órgão:</label>
            <input type="text" name="nome_orgaos" id="nome_orgaos" readonly>
            <label for="endereco">Endereço:</label>
            <input type="text" name="endereco" id="endereco" readonly>
            <label for="telefone">Telefone:</label>
            <input type="text" name="telefone" id="telefone" readonly>
            <label for="email">E-mail:</label>
            <input type="email" name="email" id="email" readonly>
            <label for="observacoes">Observações:</label>
            <textarea name="observacoes" id="observacoes" readonly></textarea>
            <button type="submit" name="update_client" id="saveBtn" style="display: none;">Salvar</button>
            <button type="button" class="action-button" id="editBtn" onclick="enableEditing()">Editar</button>
        </form>
    </div>
</div>

<script>
// Função para abrir o modal e carregar os dados do cliente
function openModal(id) {
    var modal = document.getElementById("editModal");
    modal.style.display = "block";

    // Carregar os dados do cliente no formulário via requisição GET
    fetch('consultar_clientes.php?get_cliente_id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('client_id').value = data.id;
            document.getElementById('uasg').value = data.uasg;
            document.getElementById('cnpj').value = data.cnpj;
            document.getElementById('nome_orgaos').value = data.nome_orgaos;
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
