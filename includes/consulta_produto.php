<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

$error = "";
$success = "";

// Inclui a conexão com o banco de dados
include('../includes/db.php');

// Variáveis de pesquisa
$searchTerm = "";
$products = [];

// Verifica se o formulário de pesquisa foi enviado
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];

    // Consulta ao banco de dados para pesquisar produtos por código ou nome
    try {
        $sql = "SELECT * FROM produtos WHERE codigo LIKE :searchTerm OR nome LIKE :searchTerm";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        
        // Busca todos os produtos que correspondem ao termo de pesquisa
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
}

// Verifica se o botão de "Mostrar todos os produtos" foi clicado
if (isset($_GET['show_all'])) {
    try {
        // Consulta para trazer todos os produtos
        $sql = "SELECT * FROM produtos";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        // Busca todos os produtos cadastrados
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
}

// Limpa a pesquisa ao resetar a página
if (isset($_GET['clear_search'])) {
    header("Location: consulta_produto.php");
    exit();
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
    <title>Consulta de Produtos - ComBraz</title>
    <style>
        /* Remover rolagem da página */
        html, body {
            height: 100%;
            margin: 0;
            overflow-y: auto; /* Permite rolagem vertical */
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
        }

        header {
            background-color: rgb(157, 206, 173);
            color: white;
            text-align: center;
            padding: 20px;
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
            box-sizing: border-box;
            height: auto;
            position: relative;
            overflow-x: auto; /* Permite a rolagem horizontal */
        }

        h2 {
            text-align: center;
            color: #2D893E;
            margin-bottom: 30px;
            font-size: 1.8em;
        }

        label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            box-sizing: border-box;
        }

        button {
            width: 48%;
            padding: 12px;
            background-color: #00bfae;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin-right: 4%;
        }

        button:hover {
            background-color: #009d8f;
        }

        /* Centralização e estilização do botão de download XLSX */
        .btn-container {
            display: flex;
            justify-content: center; /* Centraliza o botão */
            align-items: center;
            margin-top: 30px;
        }

        .btn-container a button {
            white-space: nowrap; /* Garante que o texto não quebre em múltiplas linhas */
            width: auto; /* Ajusta a largura do botão automaticamente */
            padding: 12px 30px; /* Aumenta o espaçamento do botão */
            font-size: 16px; /* Mantém o texto legível */
        }

        .error, .success {
            text-align: center;
            font-size: 16px;
        }

        .error {
            color: red;
        }

        .success {
            color: green;
        }

        /* Tabela com largura maior */
        table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            overflow-x: auto;
            display: block;
            margin-top: 20px;
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
            position: sticky; /* Torna o cabeçalho fixo */
            top: 0; /* Fixa o cabeçalho no topo */
            z-index: 1; /* Garante que o cabeçalho fique sobre os dados da tabela */
        }

        .clear-btn {
            background-color: red;
            color: white;
            padding: 12px;
            border-radius: 5px;
            cursor: pointer;
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
            text-align: center;
        }

        .content-footer a:hover {
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

        /* Adiciona rolagem horizontal à tabela */
        table {
            overflow-x: auto;
        }

        /* Adiciona rolagem vertical à tabela */
        .table-container {
            max-height: 400px;
            overflow-y: auto;
            width: 100%;
        }
    </style>
</head>
<body>

<header>
    <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
</header>

<!-- Menu de navegação -->
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
    <h2>Consulta de Produtos</h2>

    <!-- Exibe a mensagem de erro ou sucesso -->
    <?php if ($error) { echo "<p class='error'>$error</p>"; } ?>
    <?php if ($success) { echo "<p class='success'>$success</p>"; } ?>

    <!-- Formulário de pesquisa -->
    <form action="consulta_produto.php" method="GET">
        <div class="search-bar">
            <label for="search">Pesquisar por Código ou Nome:</label>
            <input type="text" name="search" id="search" placeholder="Digite Código ou Nome" value="<?php echo htmlspecialchars($searchTerm); ?>">
        </div>

        <div class="btn-container">
            <button type="submit">Pesquisar</button>
            <button type="submit" name="show_all" value="1">Mostrar Todos os Produtos</button>
            <button type="submit" name="clear_search" value="1" class="clear-btn">Limpar Pesquisa</button>
        </div>
    </form>

    <!-- Exibe os resultados da pesquisa, se houver -->
    <?php if (count($products) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nome</th>
                        <th>Unidade</th>
                        <th>Fornecedor</th>
                        <th>Imagem</th>
                        <th>Observação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td data-label="Código"><?php echo htmlspecialchars($product['codigo']); ?></td>
                            <td data-label="Nome"><?php echo htmlspecialchars($product['nome']); ?></td>
                            <td data-label="Unidade"><?php echo htmlspecialchars($product['und']); ?></td>
                            <td data-label="Fornecedor"><?php echo htmlspecialchars($product['fornecedor']); ?></td>
                            <td data-label="Imagem">
                                <a href="<?php echo htmlspecialchars($product['imagem']); ?>" download>
                                    <img src="<?php echo htmlspecialchars($product['imagem']); ?>" alt="Imagem do Produto" width="50">
                                </a>
                            </td>
                            <td data-label="Observação"><?php echo htmlspecialchars($product['observacao']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Botão de Download XLSX -->
        <div class="btn-container">
            <a href="http://127.0.0.1:5000/download_xlsx_produtos?search=<?php echo urlencode($searchTerm); ?>">
                <button type="button">Download XLSX (Planilha)</button>
            </a>
        </div>

    <?php elseif ($searchTerm): ?>
        <p>Nenhum produto encontrado.</p>
    <?php endif; ?>

    <!-- Link para a página de cadastro de produtos -->
    <div class="content-footer">
        <a href="cadastro_produto.php">Ir para página de Cadastro de Produtos</a>
    </div>

</div>

</body>
</html>
