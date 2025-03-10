<?php 
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

$error = "";
$success = "";
$faturamentos = [];
$searchTerm = "";

// Conexão com o banco de dados
require_once('../includes/db.php');

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    
    // Consulta ao banco de dados para pesquisar faturamentos por número, cliente ou produto
    try {
        $sql = "SELECT * FROM faturamentos WHERE numero LIKE :searchTerm OR cliente_uasg LIKE :searchTerm OR produto LIKE :searchTerm";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        
        $faturamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} elseif (isset($_GET['show_all'])) {
    // Consulta para mostrar todos os faturamentos
    try {
        $sql = "SELECT * FROM faturamentos ORDER BY numero ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $faturamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar todos os faturamentos: " . $e->getMessage();
    }
}

// Limpa a pesquisa ao resetar a página
if (isset($_GET['clear_search'])) {
    header("Location: consulta_faturamento.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Faturamento - Licita Sis</title>
    <style>
        /* Adiciona rolagem vertical à página */
        html, body {
            height: 100%;
            margin: 0;
            overflow: auto; /* Permite rolagem na página inteira */
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            flex-direction: column;
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
            max-width: 1000px;
            margin: 50px auto;
            background-color: rgb(215, 212, 212);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(240, 240, 240, 0.1);
            color: #2D893E;
            box-sizing: border-box;
            overflow-y: auto; /* Adiciona rolagem dentro do container */
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

        .btn-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 30px;
        }

        .btn-container a button {
            width: auto;
            padding: 12px 30px;
            font-size: 16px;
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
            max-height: 400px; /* Limita a altura da tabela e permite rolagem */
            overflow-y: auto; /* Permite rolagem vertical na tabela */
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
    <h2>Consulta de Faturamentos</h2>

    <!-- Exibe a mensagem de erro ou sucesso -->
    <?php if ($error) { echo "<p class='error'>$error</p>"; } ?>
    <?php if ($success) { echo "<p class='success'>$success</p>"; } ?>

    <!-- Formulário de pesquisa -->
    <form action="consulta_faturamento.php" method="GET">
        <div class="search-bar">
            <label for="search">Pesquisar por Número, Cliente ou Produto:</label>
            <input type="text" name="search" id="search" placeholder="Digite Número, Cliente ou Produto" value="<?php echo htmlspecialchars($searchTerm); ?>">
        </div>

        <div class="btn-container">
            <button type="submit">Pesquisar</button>
            <button type="submit" name="show_all" value="1">Mostrar Todos os Faturamentos</button>
            <button type="submit" name="clear_search" value="1" class="clear-btn">Limpar Pesquisa</button>
        </div>
    </form>

    <!-- Exibe os resultados da pesquisa, se houver -->
    <?php if (count($faturamentos) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Número</th>
                    <th>Cliente (UASG)</th>
                    <th>Produto</th>
                    <th>Item</th>
                    <th>Transportadora</th>
                    <th>Observações</th>
                    <th>Pregão</th>
                    <th>Comprovante</th>
                    <th>Nota Fiscal</th>
                    <th>Data</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($faturamentos as $faturamento): ?>
                    <tr>
                        <td data-label="Número"><?php echo htmlspecialchars($faturamento['numero']); ?></td>
                        <td data-label="Cliente (UASG)"><?php echo htmlspecialchars($faturamento['cliente_uasg']); ?></td>
                        <td data-label="Produto"><?php echo htmlspecialchars($faturamento['produto']); ?></td>
                        <td data-label="Item"><?php echo htmlspecialchars($faturamento['item']); ?></td>
                        <td data-label="Transportadora"><?php echo htmlspecialchars($faturamento['transportadora']); ?></td>
                        <td data-label="Observações"><?php echo htmlspecialchars($faturamento['observacao']); ?></td>
                        <td data-label="Pregão"><?php echo htmlspecialchars($faturamento['pregao']); ?></td>
                        <td data-label="Comprovante">
                            <a href="../uploads/<?php echo htmlspecialchars($faturamento['upload']); ?>" target="_blank">Download</a>
                        </td>
                        <td data-label="Nota Fiscal">
                            <a href="../uploads/nf/<?php echo htmlspecialchars($faturamento['nf']); ?>" target="_blank">Download</a>
                        </td>
                        <td data-label="Data"><?php echo htmlspecialchars($faturamento['data']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="btn-container">
            <a href="http://127.0.0.1:5000/download_xlsx_faturamentos?search=<?php echo urlencode($searchTerm); ?>">
                <button type="button">Download XLSX (Planilha)</button>
            </a>
        </div>
    <?php elseif ($searchTerm): ?>
        <p>Nenhum faturamento encontrado.</p>
    <?php endif; ?>

    <div class="content-footer">
        <a href="cadastro_faturamento.php">Ir para página de Cadastro de Faturamento</a>
    </div>

</div>

</body>
</html>
