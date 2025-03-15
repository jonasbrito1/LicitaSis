<?php 
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

$error = "";
$success = "";
$transportadoras = [];
$searchTerm = "";

// Conexão com o banco de dados
require_once('../includes/db.php');

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    
    // Consulta ao banco de dados para pesquisar transportadoras por código, nome ou telefone
    try {
        $sql = "SELECT * FROM transportadora WHERE codigo LIKE :searchTerm OR nome LIKE :searchTerm OR cnpj LIKE :searchTerm";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        
        $transportadoras = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} elseif (isset($_GET['show_all'])) {
    // Consulta para mostrar todas as transportadoras
    try {
        $sql = "SELECT * FROM transportadora ORDER BY nome ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $transportadoras = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar todas as transportadoras: " . $e->getMessage();
    }
}

// Limpa a pesquisa ao resetar a página
if (isset($_GET['clear_search'])) {
    header("Location: consulta_transportadoras.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Transportadoras - ComBraz</title>
    <style>
        /* Estilos para garantir a rolagem vertical na página */
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
            background-color:rgb(215, 212, 212);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(240, 240, 240, 0.1);
            color: #2D893E;
            box-sizing: border-box;
            height: auto;
            padding-bottom: 20px; /* Para evitar o conteúdo se sobrepor à rolagem */
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

        /* Estilos para a tabela com rolagem vertical */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: white;
            overflow-x: auto;
            display: block; /* Permite rolagem horizontal */
            max-height: 400px; /* Limita a altura da tabela e permite a rolagem */
            overflow-y: auto; /* Permite rolagem vertical */
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
    <h2>Consulta de Transportadoras</h2>

    <?php if ($error) { echo "<p class='error'>$error</p>"; } ?>
    <?php if ($success) { echo "<p class='success'>$success</p>"; } ?>

    <form action="consulta_transportadoras.php" method="GET">
        <div class="search-bar">
            <label for="search">Pesquisar por Código, CNPJ ou Nome:</label>
            <input type="text" name="search" id="search" placeholder="Digite Código, CNPJ ou Nome" value="<?php echo htmlspecialchars($searchTerm); ?>">
        </div>

        <div class="btn-container">
            <button type="submit">Pesquisar</button>
            <button type="submit" name="show_all" value="1">Mostrar Todas as Transportadoras</button>
            <button type="submit" name="clear_search" value="1">Limpar Pesquisa</button>
        </div>
    </form>

    <?php if (count($transportadoras) > 0): ?>
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
                <?php foreach ($transportadoras as $transportadora): ?>
                    <tr>
                        <td data-label="Código"><?php echo htmlspecialchars($transportadora['codigo']); ?></td>
                        <td data-label="Nome"><?php echo htmlspecialchars($transportadora['nome']); ?></td>
                        <td data-label="CNPJ"><?php echo htmlspecialchars($transportadora['cnpj']); ?></td>
                        <td data-label="Endereço"><?php echo htmlspecialchars($transportadora['endereco']); ?></td>
                        <td data-label="Telefone">
                            <a href="https://wa.me/<?php echo str_replace(['(', ')', '-', ' '], '', htmlspecialchars($transportadora['telefone'])); ?>" target="_blank">
                                <?php echo htmlspecialchars($transportadora['telefone']); ?>
                            </a>
                        </td>
                        <td data-label="E-mail">
                            <a href="mailto:<?php echo htmlspecialchars($transportadora['email']); ?>">
                                <?php echo htmlspecialchars($transportadora['email']); ?>
                            </a>
                        </td>
                        <td data-label="Observações"><?php echo htmlspecialchars($transportadora['observacoes']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="btn-container">
            <a href="http://127.0.0.1:5000/download_xlsx_transportadoras?search=<?php echo urlencode($searchTerm); ?>">
                <button type="button">Download XLSX (Planilha)</button>
            </a>
        </div>
    <?php elseif ($searchTerm): ?>
        <p>Nenhuma transportadora encontrada.</p>
    <?php endif; ?>

    <div class="content-footer">
        <a href="cadastro_transportadoras.php">Ir para página de Cadastro de Transportadoras</a>
    </div>
</div>

</body>
</html>
