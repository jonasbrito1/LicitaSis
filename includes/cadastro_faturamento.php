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
        $item = trim($_POST['item']);
        $transportadora = trim($_POST['transportadora']);
        $observacao = trim($_POST['observacao']) ?? null;
        $pregao = trim($_POST['pregao']) ?? null;
        $upload = $_FILES['upload']['name'] ?? null;
        $nf = $_FILES['nf']['name'] ?? null;
        $data = trim($_POST['data']);

        // Verifica se Número, Cliente UASG e Produto estão preenchidos
        if (empty($numero) || empty($cliente_uasg) || empty($produto)) {
            throw new Exception("Os campos Número, Cliente UASG e Produto são obrigatórios.");
        }

        // Verifica se o upload existe e move para o diretório correto
        if ($upload) {
            $targetDir = "../uploads/";
            $targetFile = $targetDir . basename($upload);
            move_uploaded_file($_FILES['upload']['tmp_name'], $targetFile);
        }

        // Verifica o upload do arquivo de NF
        if ($nf) {
            $targetDirNF = "../uploads/nf/";
            $targetFileNF = $targetDirNF . basename($nf);
            move_uploaded_file($_FILES['nf']['tmp_name'], $targetFileNF);
        }

        // Insere no banco de dados
        $sql = "INSERT INTO faturamentos (numero, cliente_uasg, produto, item, transportadora, observacao, pregão, upload, nf, data) 
                VALUES (:numero, :cliente_uasg, :produto, :item, :transportadora, :observacao, :pregao, :upload, :nf, :data)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':numero', $numero, PDO::PARAM_STR);
        $stmt->bindParam(':cliente_uasg', $cliente_uasg, PDO::PARAM_STR);
        $stmt->bindParam(':produto', $produto, PDO::PARAM_STR);
        $stmt->bindParam(':item', $item, PDO::PARAM_STR);
        $stmt->bindParam(':transportadora', $transportadora, PDO::PARAM_STR);
        $stmt->bindParam(':observacao', $observacao, PDO::PARAM_STR);
        $stmt->bindParam(':pregao', $pregao, PDO::PARAM_STR);
        $stmt->bindParam(':upload', $upload, PDO::PARAM_STR);
        $stmt->bindParam(':nf', $nf, PDO::PARAM_STR);
        $stmt->bindParam(':data', $data, PDO::PARAM_STR);

        // Executa a consulta
        if ($stmt->execute()) {
            $success = true;
        } else {
            throw new Exception("Erro ao cadastrar o faturamento.");
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
    <title>Cadastro de Faturamento - Licita Sis</title>
    <style>
        /* Estilos e layout */
        html, body {
            height: 100%;
            margin: 0;
            overflow: auto; /* Permite rolagem na página inteira */
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
            background-color: rgb(215, 212, 212);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(240, 240, 240, 0.1);
            color: #2D893E;
            height: 70vh; /* Ajusta a altura */
            overflow-y: auto; /* Adiciona rolagem dentro do container */
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

        /* Estilos do Modal (Popup) */
        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgb(0, 0, 0);
            background-color: rgba(0, 0, 0, 0.4);
            padding-top: 60px;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 400px; /* Ajuste para largura fixa */
        }

        .modal-header {
            font-size: 20px;
            font-weight: bold;
            color: #2D893E;
            margin-bottom: 15px;
        }

        .modal-footer {
            text-align: center;
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
    <h2>Cadastro de Faturamento</h2>

    <?php if ($error) { echo "<p class='error' style='color: red; text-align: center;'>$error</p>"; } ?>

    <form action="cadastro_faturamento.php" method="POST" enctype="multipart/form-data">
        <label for="numero">Número:</label>
        <input type="text" id="numero" name="numero" required>

        <label for="cliente_uasg">Cliente (UASG):</label>
        <input type="text" id="cliente_uasg" name="cliente_uasg" required>

        <label for="produto">Produto:</label>
        <input type="text" id="produto" name="produto" required>

        <label for="item">Item:</label>
        <input type="text" id="item" name="item" required>

        <label for="transportadora">Transportadora:</label>
        <input type="text" id="transportadora" name="transportadora" required>

        <label for="observacao">Observação:</label>
        <textarea id="observacao" name="observacao"></textarea>

        <label for="pregao">Pregão:</label>
        <input type="text" id="pregao" name="pregao">

        <label for="upload">Comprovante (Upload):</label>
        <input type="file" id="upload" name="upload">

        <label for="nf">Nota Fiscal (Upload):</label>
        <input type="file" id="nf" name="nf">

        <label for="data">Data:</label>
        <input type="date" id="data" name="data" required>

        <div class="btn-container">
            <button type="submit">Cadastrar Faturamento</button>
            <button type="reset">Limpar</button>
        </div>
    </form>

    <div class="content-footer">
        <a href="consulta_faturamento.php">Ir para página de Consulta de Faturamento</a>
    </div>
</div>

<!-- Modal de Sucesso -->
<?php if ($success) { ?>
    <div id="successModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                Faturamento Cadastrado com Sucesso!
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-footer">
                <button onclick="resetPage()">Fechar</button>
            </div>
        </div>
    </div>
    <script>
        // Exibe o modal de sucesso
        document.getElementById("successModal").style.display = "block";

        // Fecha o modal e reseta a página
        function closeModal() {
            document.getElementById("successModal").style.display = "none";
            resetPage();
        }

        // Função para resetar a página
        function resetPage() {
            window.location = "cadastro_faturamento.php"; // Redireciona para a página de cadastro novamente
        }
    </script>
<?php } ?>

</body>
</html>
