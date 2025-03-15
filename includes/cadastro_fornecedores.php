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
        $codigo = trim($_POST['codigo']);
        $cnpj = trim($_POST['cnpj']);
        $nome = trim($_POST['nome']);
        $endereco = trim($_POST['endereco']) ?? null;
        $telefone = trim($_POST['telefone']) ?? null;
        $email = trim($_POST['email']) ?? null;
        $observacoes = trim($_POST['observacoes']) ?? null;

        // Verifica se Nome e Código estão preenchidos
        if (empty($codigo) || empty($nome)) {
            throw new Exception("Os campos Código e Nome são obrigatórios.");
        }

        // Insere no banco de dados
        $sql = "INSERT INTO fornecedores (codigo, cnpj, nome, endereco, telefone, email, observacoes) 
                VALUES (:codigo, :cnpj, :nome, :endereco, :telefone, :email, :observacoes)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':codigo', $codigo, PDO::PARAM_STR);
        $stmt->bindParam(':cnpj', $cnpj, PDO::PARAM_STR);
        $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
        $stmt->bindParam(':endereco', $endereco, PDO::PARAM_STR);
        $stmt->bindParam(':telefone', $telefone, PDO::PARAM_STR);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':observacoes', $observacoes, PDO::PARAM_STR);

        if ($stmt->execute()) {
            $success = true;
        } else {
            throw new Exception("Erro ao cadastrar o fornecedor.");
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
    <title>Cadastro de Fornecedor - ComBraz</title>
    <script>
        function limitarCNPJ(event) {
            const input = event.target;
            let value = input.value.replace(/\D/g, '');
            if (value.length <= 14) {
                input.value = value;
            }
        }

        function consultarCNPJ() {
            const cnpj = document.getElementById("cnpj").value;
            if (cnpj.length === 14) {
                fetch(`consultar_cnpj.php?cnpj=${cnpj}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === "OK") {
                            document.getElementById("nome").value = data.nome;
                            document.getElementById("endereco").value = data.logradouro + ", " + data.numero + " - " + data.bairro + " - " + data.municipio;
                            document.getElementById("telefone").value = data.telefone;
                            document.getElementById("email").value = data.email || '';
                        } else {
                            alert("CNPJ não encontrado ou inválido.");
                        }
                    })
                    .catch(error => {
                        alert("Erro ao consultar o CNPJ.");
                        console.error("Erro na consulta: ", error);
                    });
            }
        }

        // Função para abrir e fechar o modal
        function openModal() {
            document.getElementById('successModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('successModal').style.display = 'none';
            window.location.href = 'cadastro_fornecedores.php'; // Redireciona para a página de cadastro novamente
        }

        window.onload = function() {
            <?php if ($success) { echo "openModal();"; } ?>
        }
    </script>
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

        input, textarea {
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

        #successModal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            text-align: center;
        }

        .modal-content {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            width: 300px;
            margin-top: 200px;
            display: inline-block;
        }

        .btn-close {
            padding: 10px 20px;
            background-color: red;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-close:hover {
            background-color: darkred;
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
            .content-footer a {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>

<header>
    <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo" class="logo">
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
    <h2>Cadastro de Fornecedor</h2>

    <?php if ($error) { echo "<p class='error' style='color: red; text-align: center;'>$error</p>"; } ?>

    <form action="cadastro_fornecedores.php" method="POST">
        <label for="codigo">Código do Fornecedor:</label>
        <input type="text" id="codigo" name="codigo" required>

        <label for="cnpj">CNPJ:</label>
        <input type="text" id="cnpj" name="cnpj" required oninput="limitarCNPJ(event)" onblur="consultarCNPJ()">

        <label for="nome">Nome:</label>
        <input type="text" id="nome" name="nome" required>

        <label for="endereco">Endereço Completo:</label>
        <input type="text" id="endereco" name="endereco">

        <label for="telefone">Telefone:</label>
        <input type="tel" id="telefone" name="telefone">

        <label for="email">E-mail:</label>
        <input type="email" id="email" name="email">

        <label for="observacoes">Observações:</label>
        <textarea id="observacoes" name="observacoes"></textarea>

        <div class="btn-container">
            <button type="submit">Cadastrar Fornecedor</button>
            <button type="reset">Limpar</button>
        </div>
    </form>

    <div class="content-footer">
        <a href="consulta_fornecedores.php">Ir para página de Consulta de Fornecedores</a>
    </div>
</div>

<div id="successModal">
    <div class="modal-content">
        <h3>Fornecedor cadastrado com sucesso!</h3>
        <button class="btn-close" onclick="closeModal()">Fechar</button>
    </div>
</div>

</body>
</html>
