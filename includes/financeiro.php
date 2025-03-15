<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

$error = "";
$success = "";
$transacoes = [];
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';  // Inicializa a variável $searchTerm

// Conexão com o banco de dados
require_once('../includes/db.php');

// Verifica se a pesquisa foi realizada
if (!empty($searchTerm)) {
    try {
        $sql = "SELECT * FROM transacoes WHERE descricao LIKE :searchTerm OR categoria LIKE :searchTerm";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        
        $transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    try {
        $sql = "SELECT * FROM transacoes ORDER BY data DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar transações: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão Financeira - Licita Sis</title>
    <style>
        /* Estilos gerais */
        html, body {
            height: 100%;
            margin: 0;
            overflow: hidden;
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
            background-color:rgb(215, 212, 212);
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
            white-space: nowrap;
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
        }

        .content-footer a:hover {
            background-color: #009d8f;
        }

        /* Responsividade */
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

            .btn-container {
                flex-direction: column;
            }

            button {
                width: 100%;
                margin-bottom: 10px;
            }

            .content-footer a {
                width: 100%;
                text-align: center;
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
    <h2>Gestão Financeira - Consultar Transações</h2>

    <?php if ($error) { echo "<p class='error'>$error</p>"; } ?>
    <?php if ($success) { echo "<p class='success'>$success</p>"; } ?>

    <!-- Formulário de pesquisa -->
    <form action="financeiro.php" method="GET">
        <div class="search-bar">
            <label for="search">Pesquisar por Descrição ou Categoria:</label>
            <input type="text" name="search" id="search" placeholder="Digite Descrição ou Categoria" value="<?php echo htmlspecialchars($searchTerm); ?>">
        </div>

        <div class="btn-container">
            <button type="submit">Pesquisar</button>
            <button type="submit" name="show_all" value="1">Mostrar Todas as Transações</button>
            <button type="submit" name="clear_search" value="1" class="clear-btn">Limpar Pesquisa</button>
        </div>
    </form>

    <!-- Exibe os resultados da pesquisa, se houver -->
    <?php if (count($transacoes) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Descrição</th>
                    <th>Categoria</th>
                    <th>Valor</th>
                    <th>Data</th>
                    <th>Tipo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transacoes as $transacao): ?>
                    <tr>
                        <td data-label="ID"><?php echo htmlspecialchars($transacao['id']); ?></td>
                        <td data-label="Descrição"><?php echo htmlspecialchars($transacao['descricao']); ?></td>
                        <td data-label="Categoria"><?php echo htmlspecialchars($transacao['categoria']); ?></td>
                        <td data-label="Valor">R$ <?php echo number_format(htmlspecialchars($transacao['valor']), 2, ',', '.'); ?></td>
                        <td data-label="Data"><?php echo htmlspecialchars($transacao['data']); ?></td>
                        <td data-label="Tipo"><?php echo htmlspecialchars($transacao['tipo']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($searchTerm): ?>
        <p>Nenhuma transação encontrada.</p>
    <?php endif; ?>

    <!-- Botão para download das transações em XLSX -->
    <div class="btn-container">
        <a href="/download_xlsx_vendas?search=<?php echo urlencode($searchTerm); ?>">
            <button type="button">Download XLSX (Transações)</button>
        </a>
    </div>

    <!-- Link para a página de cadastro de transações -->
    <div class="content-footer">
        <a href="cadastro_transacoes.php">Ir para página de Cadastro de Transações</a>
    </div>

</div>

</body>
</html>
