<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

// Inicialização das variáveis
$error = "";
$success = false; // Inicializa como false

// Conexão com o banco de dados
require_once('../includes/db.php');

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Obtém os dados do formulário
        $cnpj = trim($_POST['cnpj']);
        $nome_orgaos = trim($_POST['nome_orgaos']);
        $uasg = trim($_POST['uasg']) ?? null;
        $endereco = trim($_POST['endereco']) ?? null;
        $observacoes = trim($_POST['observacoes']) ?? null;
        $telefones = implode(' / ', array_filter(array_map('trim', $_POST['telefone'] ?? []))); // Concatena telefones
        $emails = implode(' / ', array_filter(array_map('trim', $_POST['email'] ?? []))); // Concatena emails

        // Verifica se CNPJ e Nome do Órgão estão preenchidos
        if (empty($cnpj) || empty($nome_orgaos)) {
            throw new Exception("Os campos CNPJ e Nome do Órgão são obrigatórios.");
        }

        // Verifica se o CNPJ já existe no banco
        $sql_check_cnpj = "SELECT COUNT(*) FROM clientes WHERE cnpj = :cnpj";
        $stmt_check = $pdo->prepare($sql_check_cnpj);
        $stmt_check->bindParam(':cnpj', $cnpj);
        $stmt_check->execute();
        $count = $stmt_check->fetchColumn();

        if ($count > 0) {
            throw new Exception("Cliente já cadastrado!"); // Se o CNPJ já existir, exibe mensagem de erro
        }

        // Inserir cliente na tabela 'clientes'
        $sql_cliente = "INSERT INTO clientes (cnpj, nome_orgaos, uasg, endereco, observacoes, telefone, email) 
                        VALUES (:cnpj, :nome_orgaos, :uasg, :endereco, :observacoes, :telefone, :email)";
        $stmt_cliente = $pdo->prepare($sql_cliente);
        $stmt_cliente->bindParam(':cnpj', $cnpj, PDO::PARAM_STR);
        $stmt_cliente->bindParam(':nome_orgaos', $nome_orgaos, PDO::PARAM_STR);
        $stmt_cliente->bindParam(':uasg', $uasg, PDO::PARAM_STR);
        $stmt_cliente->bindParam(':endereco', $endereco, PDO::PARAM_STR);
        $stmt_cliente->bindParam(':observacoes', $observacoes, PDO::PARAM_STR);
        $stmt_cliente->bindParam(':telefone', $telefones, PDO::PARAM_STR);
        $stmt_cliente->bindParam(':email', $emails, PDO::PARAM_STR);
        $stmt_cliente->execute();
        $cliente_id = $pdo->lastInsertId(); // Pega o ID do cliente inserido

        // Caso tenha mais telefones ou emails a serem cadastrados, inserir nas tabelas correspondentes
        foreach ($_POST['telefone'] as $telefone) {
            if (!empty($telefone)) {
                $sql_telefone = "INSERT INTO clientes_telefones (cliente_id, telefone) VALUES (:cliente_id, :telefone)";
                $stmt_telefone = $pdo->prepare($sql_telefone);
                $stmt_telefone->bindParam(':cliente_id', $cliente_id, PDO::PARAM_INT);
                $stmt_telefone->bindParam(':telefone', $telefone, PDO::PARAM_STR);
                $stmt_telefone->execute();
            }
        }

        foreach ($_POST['email'] as $email) {
            if (!empty($email)) {
                $sql_email = "INSERT INTO clientes_emails (cliente_id, email) VALUES (:cliente_id, :email)";
                $stmt_email = $pdo->prepare($sql_email);
                $stmt_email->bindParam(':cliente_id', $cliente_id, PDO::PARAM_INT);
                $stmt_email->bindParam(':email', $email, PDO::PARAM_STR);
                $stmt_email->execute();
            }
        }

        $success = true; // Cadastro bem-sucedido
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
    <title>Cadastro de Cliente - ComBraz</title>
    <script>
        let telefoneCount = 1;
        let emailCount = 1;

        // Função para adicionar campo de telefone dinamicamente
        function addTelefoneField() {
            if (telefoneCount < 10) {
                telefoneCount++;
                const newField = document.createElement('input');
                newField.type = 'tel';
                newField.name = 'telefone[]';
                newField.placeholder = 'Telefone ' + telefoneCount;
                document.getElementById('telefoneFields').appendChild(newField);
            }
        }

        // Função para adicionar campo de e-mail dinamicamente
        function addEmailField() {
            if (emailCount < 10) {
                emailCount++;
                const newField = document.createElement('input');
                newField.type = 'email';
                newField.name = 'email[]';
                newField.placeholder = 'E-mail ' + emailCount;
                document.getElementById('emailFields').appendChild(newField);
            }
        }

        // Função para consultar CNPJ via API da Receita Federal
        function consultarCNPJ() {
            const cnpj = document.getElementById("cnpj").value.replace(/[^\d]/g, ''); // Remove caracteres não numéricos
            if (cnpj.length === 14) {
                fetch(`consultar_cnpj.php?cnpj=${cnpj}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === "OK") {
                            document.getElementById("nome_orgaos").value = data.nome;
                            document.getElementById("endereco").value = `${data.logradouro}, ${data.numero} - ${data.bairro} - ${data.municipio}`;
                            document.getElementById("telefone").value = data.telefone || '';  // Preenche o telefone se existir
                            document.getElementById("email").value = data.email || '';        // Preenche o e-mail se existir
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

        // Função para abrir e fechar o modal de sucesso
        function openModal() {
            document.getElementById('successModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('successModal').style.display = 'none';
            window.location.href = 'cadastrar_clientes.php'; // Redireciona para a página de cadastro novamente
        }

        window.onload = function() {
            <?php if ($success) { echo "openModal();"; } ?>
        }
    </script>
    <style>
        /* Remover rolagem da página */
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
            height: auto;
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
        }

        .content-footer a:hover {
            background-color: #009d8f;
        }

        /* Estilos para rolagem da tabela */
        .table-container {
            max-height: 300px;
            overflow-y: auto;
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
    <h2>Cadastro de Cliente</h2>

    <?php if ($error) { echo "<p class='error' style='color: red; text-align: center;'>$error</p>"; } ?>

    <form action="cadastrar_clientes.php" method="POST">
        <label for="cnpj">CNPJ:</label>
        <input type="text" id="cnpj" name="cnpj" required oninput="limitarCNPJ(event)" onblur="consultarCNPJ()">

        <label for="nome_orgaos">Nome do Órgão:</label>
        <input type="text" id="nome_orgaos" name="nome_orgaos" required>

        <label for="uasg">UASG:</label>
        <input type="text" id="uasg" name="uasg">

        <label for="endereco">Endereço:</label>
        <input type="text" id="endereco" name="endereco">

        <label for="telefone">Telefone:</label>
        <input type="tel" id="telefone" name="telefone[]">

        <div id="telefoneFields"></div>
        <button type="button" onclick="addTelefoneField()">+</button>

        <label for="email">E-mail:</label>
        <input type="email" id="email" name="email[]">

        <div id="emailFields"></div>
        <button type="button" onclick="addEmailField()">+</button>

        <label for="observacoes">Observações:</label>
        <textarea id="observacoes" name="observacoes"></textarea>

        <div class="btn-container">
            <button type="submit">Cadastrar Cliente</button>
            <button type="reset">Limpar</button>
        </div>
    </form>

    <div class="content-footer">
        <a href="consultar_clientes.php">Ir para página de Consulta de Clientes</a>
    </div>
</div>

<!-- Modal de sucesso -->
<div id="successModal">
    <div class="modal-content">
        <h3>Cliente cadastrado com sucesso!</h3>
        <button class="btn-close" onclick="closeModal()">Fechar</button>
    </div>
</div>

</body>
</html>
