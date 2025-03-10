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
} elseif (isset($_GET['show_all'])) {
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
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Fornecedores - ComBraz</title>
    <style>
        /* Estilos e layout */
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
            background-color: rgb(157, 206, 173); /* Fundo verde claro */
            padding: 10px 0;
            text-align: center;
            color: white;
            width: 100%;
            box-sizing: border-box;
        }

        /* Ajuste responsivo da logo */
        .logo {
            max-width: 180px;  /* Ajusta a largura máxima da logo */
            height: auto;
        }

        /* Menu de navegação */
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
            max-width: 1000px;
            margin: 50px auto;
            background-color:rgb(215, 212, 212);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(240, 240, 240, 0.1);
            color: #2D893E;
            box-sizing: border-box;
            height: auto;
            position: relative;
            overflow-y: auto; /* Adiciona a rolagem apenas ao container */
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

        .content-footer {
            display: flex;
            justify-content: center;  /* Centraliza o link */
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
    </style>
</head>
<body>

<header>
    <img src="../public_html/assets/images/licitasis.png" alt="Logo LicitaSis" class="logo">
</header>

<!-- Menu de navegação -->
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
            <button type="submit" name="show_all" value="1">Mostrar Todos os Fornecedores</button>
            <button type="submit" name="clear_search" value="1" class="clear-btn">Limpar Pesquisa</button>
        </div>
    </form>

    <!-- Exibe os resultados da pesquisa, se houver -->
    <?php if (count($fornecedores) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Nome</th>
                    <th>CNPJ</th>
                    <th>Endereço Completo</th>
                    <th>Telefone</th>
                    <th>E-mail</th>
                    <th>Observações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fornecedores as $fornecedor): ?>
                    <tr>
                        <td data-label="Código"><?php echo htmlspecialchars($fornecedor['codigo']); ?></td>
                        <td data-label="Nome"><?php echo htmlspecialchars($fornecedor['nome']); ?></td>
                        <td data-label="CNPJ"><?php echo htmlspecialchars($fornecedor['cnpj']); ?></td>
                        <td data-label="Endereço Completo"><?php echo htmlspecialchars($fornecedor['endereco']); ?></td>
                        <td data-label="Telefone">
                            <a href="https://wa.me/<?php echo str_replace(['(', ')', '-', ' '], '', htmlspecialchars($fornecedor['telefone'])); ?>" target="_blank">
                                <?php echo htmlspecialchars($fornecedor['telefone']); ?>
                            </a>
                        </td>
                        <td data-label="E-mail">
                            <a href="mailto:<?php echo htmlspecialchars($fornecedor['email']); ?>">
                                <?php echo htmlspecialchars($fornecedor['email']); ?>
                            </a>
                        </td>
                        <td data-label="Observações"><?php echo htmlspecialchars($fornecedor['observacoes']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Botão de Download XLSX -->
        <div class="btn-container">
            <a href="http://127.0.0.1:5000/download_xlsx_fornecedores?search=<?php echo urlencode($searchTerm); ?>">
                <button type="button">Download XLSX (Planilha)</button>
            </a>
        </div>

    <?php elseif ($searchTerm): ?>
        <p>Nenhum fornecedor encontrado.</p>
    <?php endif; ?>

    <!-- Link para a página de cadastro de fornecedores -->
    <div class="content-footer">
        <a href="cadastro_fornecedores.php">Ir para página de Cadastro de Fornecedores</a>
    </div>

</div>

</body>
</html>
