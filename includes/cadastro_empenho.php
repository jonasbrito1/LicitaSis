<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

$error = "";
$success = false;

// Conexão com o banco de dados
require_once('../includes/db.php');

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $numero = trim($_POST['numero']);
        $cliente_uasg = trim($_POST['cliente_uasg']);
        $produto = trim($_POST['produto']);
        $produto2 = isset($_POST['produto2']) ? trim($_POST['produto2']) : null; // Produto 2 (agora é tratado se não existir)
        $item = trim($_POST['item']);
        $observacao = trim($_POST['observacao']) ?? null;
        $pregao = trim($_POST['pregao']) ?? null;
        $upload = $_FILES['upload']['name'] ?? null;
        $data = trim($_POST['data']);
        $prioridade = trim($_POST['prioridade']);

        // Verifica se Número e Cliente UASG estão preenchidos
        if (empty($numero) || empty($cliente_uasg)) {
            throw new Exception("Os campos Número e Cliente UASG são obrigatórios.");
        }

        // Verifica se o upload existe e move para o diretório correto
        if ($upload) {
            $targetDir = "../uploads/";
            $targetFile = $targetDir . basename($upload);
            move_uploaded_file($_FILES['upload']['tmp_name'], $targetFile);
        }

        // Insere no banco de dados
        $sql = "INSERT INTO empenhos (numero, cliente_uasg, produto, produto2, item, observacao, pregão, upload, data, prioridade) 
                VALUES (:numero, :cliente_uasg, :produto, :produto2, :item, :observacao, :pregao, :upload, :data, :prioridade)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':numero', $numero, PDO::PARAM_STR);
        $stmt->bindParam(':cliente_uasg', $cliente_uasg, PDO::PARAM_STR);
        $stmt->bindParam(':produto', $produto, PDO::PARAM_STR);
        $stmt->bindParam(':produto2', $produto2, PDO::PARAM_STR); // Produto 2
        $stmt->bindParam(':item', $item, PDO::PARAM_STR);
        $stmt->bindParam(':observacao', $observacao, PDO::PARAM_STR);
        $stmt->bindParam(':pregao', $pregao, PDO::PARAM_STR);
        $stmt->bindParam(':upload', $upload, PDO::PARAM_STR);
        $stmt->bindParam(':data', $data, PDO::PARAM_STR);
        $stmt->bindParam(':prioridade', $prioridade, PDO::PARAM_STR);

        // Executa a consulta
        if ($stmt->execute()) {
            $success = true;
        } else {
            throw new Exception("Erro ao cadastrar o empenho.");
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Empenho - Licita Sis</title>
    <style>
        /* Estilos e layout */
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

        .logo {
            max-width: 180px;
            height: auto;
        }

        .container {
            max-width: 900px;
            margin: 50px auto;
            background-color:rgb(215, 212, 212);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(240, 240, 240, 0.1);
            color: #2D893E;
            height: 70vh;
            overflow-y: auto;
        }

        h2 {
            text-align: center;
            color: #2D893E;
            margin-bottom: 20px;
            font-size: 1.8em;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }

        input, select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
            margin-bottom: 15px;
        }

        .btn-container {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
        }

        button {
            flex: 1;
            padding: 12px;
            background-color: #00bfae;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin: 0 10px;
        }

        button:hover {
            background-color: #009d8f;
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

        @media (max-width: 768px) {
            .content-footer a {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>

<header>
    <img src="../public_html/assets/images/licitasis.png" alt="Logo" class="logo">
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
    <h2>Cadastro de Empenho</h2>

    <?php if ($error) { echo "<p class='error' style='color: red; text-align: center;'>$error</p>"; } ?>

    <form action="cadastro_empenho.php" method="POST" enctype="multipart/form-data">
        <label for="numero">Número:</label>
        <input type="text" id="numero" name="numero" required>

        <label for="cliente_uasg">Cliente (UASG):</label>
        <input type="text" id="cliente_uasg" name="cliente_uasg" required>

        <label for="produto">Produto:</label>
        <input type="text" id="produto" name="produto" required>

        <label for="produto2">Produto 2:</label>
        <textarea id="produto2" name="produto2"></textarea> <!-- Produto 2 adicionado -->

        <label for="item">Item:</label>
        <input type="text" id="item" name="item" required>

        <label for="observacao">Observação:</label>
        <textarea id="observacao" name="observacao"></textarea>

        <label for="pregao">Pregão:</label>
        <input type="text" id="pregao" name="pregao">

        <label for="upload">Upload:</label>
        <input type="file" id="upload" name="upload">

        <label for="data">Data:</label>
        <input type="date" id="data" name="data" required>

        <label for="prioridade">Prioridade:</label>
        <select id="prioridade" name="prioridade">
            <option value="Alta">Alta</option>
            <option value="Média">Média</option>
            <option value="Baixa">Baixa</option>
        </select>

        <div class="btn-container">
            <button type="submit">Cadastrar Empenho</button>
            <button type="reset">Limpar</button>
        </div>
    </form>

    <div class="content-footer">
        <a href="consulta_empenho.php">Ir para página de Consulta de Empenho</a>
    </div>
</div>

</body>
</html>
