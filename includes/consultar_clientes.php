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
    
    // Consulta ao banco de dados para pesquisar clientes por UASG, Nome ou CNPJ
    try {
        $sql = "SELECT * FROM clientes WHERE uasg LIKE :searchTerm OR nome_orgaos LIKE :searchTerm OR cnpj LIKE :searchTerm";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} elseif (isset($_GET['show_all'])) {
    // Consulta para mostrar todos os clientes
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

        // Verifica se o email já existe na tabela 'clientes' antes de inserir
        $sql_check_email = "SELECT COUNT(*) FROM clientes WHERE email = :email AND id != :id";
        $stmt_check_email = $pdo->prepare($sql_check_email);
        $stmt_check_email->bindParam(':email', $email);
        $stmt_check_email->bindParam(':id', $id);
        $stmt_check_email->execute();
        $email_exists = $stmt_check_email->fetchColumn();

        if ($email_exists == 0) {
            // Se o email não existir, atualiza a coluna email
            $sql_update_email = "UPDATE clientes SET email = :email WHERE id = :id";
            $stmt_update_email = $pdo->prepare($sql_update_email);
            $stmt_update_email->bindParam(':email', $email);
            $stmt_update_email->bindParam(':id', $id);
            $stmt_update_email->execute();
        }

        $success = "Cliente atualizado com sucesso!";
    } catch (PDOException $e) {
        $error = "Erro ao atualizar o cliente: " . $e->getMessage();
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
        /* Estilos e layout existentes */
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
            max-width: 100%;
            margin: 50px auto;
            background-color:rgb(215, 212, 212);
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
            padding: 8px;
            text-align: left;
            white-space: nowrap;
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
            overflow-x: auto;
        }

        .content-footer {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        .content-footer a {
            background-color: #00bfae;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            font-size: 16px;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .content-footer a:hover {
            background-color: #009d8f;
        }

        .btn-container {
            display: flex;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .btn-container button {
            width: auto;
            padding: 12px 30px;
            font-size: 16px;
            background-color: #00bfae;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
        }

        .btn-container button:hover {
            background-color: #009d8f;
        }

        /* Estilos de busca */
        input[type="text"], input[type="email"], textarea {
            width: 100%;
            padding: 12px;
            margin-right: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            box-sizing: border-box;
        }

        .search-bar {
            display: flex;
            flex-direction: column;
            margin-bottom: 20px;
        }

        .search-bar label {
            margin-bottom: 10px;
            font-weight: bold;
        }

    </style>
</head>
<body>

<header>
    <img src="../public_html/assets/images/licitasis.png" alt="Logo LicitaSis" class="logo">
</header>

<nav>
    <a href="sistema.php">Início</a>
    <a href="clientes.php">Clientes</a>
    <a href="produtos.php">Produtos</a>
    <a href="financeiro.php">Financeiro</a>
    <a href="transportadoras.php">Transportadoras</a>
    <a href="fornecedores.php">Fornecedores</a>
    <a href="faturamentos.php">Faturamento</a>
</nav>

<div class="container">
    <h2>Consulta de Clientes</h2>

    <?php if ($error) { echo "<p class='error'>$error</p>"; } ?>
    <?php if ($success) { echo "<p class='success'>$success</p>"; } ?>

    <!-- Formulário de pesquisa -->
    <form action="consultar_clientes.php" method="GET">
        <div class="search-bar">
            <label for="search">Pesquisar por Uasg, Nome ou CNPJ:</label>
            <input type="text" name="search" id="search" placeholder="Digite Uasg, Nome ou CNPJ" value="<?php echo htmlspecialchars($searchTerm); ?>">
        </div>

        <div class="btn-container">
            <button type="submit">Pesquisar</button>
            <button type="submit" name="show_all" value="1">Mostrar Todos os Clientes</button>
            <button type="submit" name="clear_search" value="1" class="clear-btn">Limpar Pesquisa</button>
        </div>
    </form>

    <!-- Exibe os resultados da pesquisa, se houver -->
    <?php if (count($clientes) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Uasg</th>
                        <th>CNPJ</th>
                        <th>Nome do Órgão</th>
                        <th>Endereço Completo</th>
                        <th>Telefone</th>
                        <th>E-mail</th>
                        <th>Observações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $cliente): ?>
                        <tr id="cliente-<?php echo $cliente['id']; ?>">
                            <td data-label="Uasg"><?php echo htmlspecialchars($cliente['uasg']); ?></td>
                            <td data-label="CNPJ"><?php echo htmlspecialchars($cliente['cnpj']); ?></td>
                            <td data-label="Nome do Órgão"><?php echo htmlspecialchars($cliente['nome_orgaos']); ?></td>
                            <td data-label="Endereço Completo"><?php echo htmlspecialchars($cliente['endereco']); ?></td>
                            <td data-label="Telefone">
                                <?php 
                                    $telefones = explode('/', $cliente['telefone']);
                                    foreach ($telefones as $telefone) {
                                        $telefone = trim($telefone);
                                        echo "<a href='https://wa.me/" . str_replace(['(', ')', '-', ' '], '', $telefone) . "' target='_blank'>$telefone</a><br>";
                                    }
                                ?>
                            </td>
                            <td data-label="E-mail">
                                <?php 
                                    $emails = explode('/', $cliente['email']);
                                    foreach ($emails as $email) {
                                        $email = trim($email);
                                        echo "<a href='mailto:$email'>$email</a><br>";
                                    }
                                ?>
                            </td>
                            <td data-label="Observações"><?php echo htmlspecialchars($cliente['observacoes']); ?></td>
                            <td data-label="Ação">
                                <!-- Botão de Editar -->
                                <button onclick="editClient(<?php echo $cliente['id']; ?>)">Editar</button>
                            </td>
                        </tr>

                        <!-- Formulário de Edição -->
                        <tr class="edit-form" id="edit-form-<?php echo $cliente['id']; ?>" style="display:none;">
                            <form method="POST" action="consultar_clientes.php">
                                <input type="hidden" name="id" value="<?php echo $cliente['id']; ?>">
                                <td><input type="text" name="uasg" value="<?php echo htmlspecialchars($cliente['uasg']); ?>"></td>
                                <td><input type="text" name="cnpj" value="<?php echo htmlspecialchars($cliente['cnpj']); ?>"></td>
                                <td><input type="text" name="nome_orgaos" value="<?php echo htmlspecialchars($cliente['nome_orgaos']); ?>"></td>
                                <td><input type="text" name="endereco" value="<?php echo htmlspecialchars($cliente['endereco']); ?>"></td>
                                <td><input type="text" name="telefone" value="<?php echo htmlspecialchars($cliente['telefone']); ?>"></td>
                                <td><input type="text" name="email" value="<?php echo htmlspecialchars($cliente['email']); ?>"></td>
                                <td><textarea name="observacoes"><?php echo htmlspecialchars($cliente['observacoes']); ?></textarea></td>
                                <td><button type="submit" name="update_client">Salvar</button></td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($searchTerm): ?>
        <p>Nenhum cliente encontrado.</p>
    <?php endif; ?>

    <div class="content-footer">
        <a href="cadastrar_clientes.php">Ir para página de Cadastro de Clientes</a>
    </div>

</div>

<script>
    // Função para mostrar o formulário de edição e esconder a linha original
    function editClient(id) {
        var form = document.getElementById("edit-form-" + id);
        var row = document.getElementById("cliente-" + id);
        
        form.style.display = "table-row";
        row.style.display = "none";
    }
</script>

</body>
</html>
