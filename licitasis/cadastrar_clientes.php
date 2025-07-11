<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

// Inicializa a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

// Inicialização das variáveis
$error = "";
$success = false; // Inicializa como false

// Conexão com o banco de dados
require_once('db.php');

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Obtém os dados do formulário
        $cnpj = trim($_POST['cnpj']);
        $nome_orgaos = trim($_POST['nome_orgaos']);
        $uasg = trim($_POST['uasg']);  // Ajustado para garantir que o campo UASG seja tratado
        $endereco = trim($_POST['endereco']) ?? null;
        $observacoes = trim($_POST['observacoes']) ?? null;

        // Verifica se o campo 'uasg' foi preenchido
        if (empty($uasg)) {
            $error = "O campo UASG é obrigatório!";  // Mensagem de erro caso UASG esteja vazio
        } else {
            // Concatena múltiplos telefones
            $telefones = implode(' / ', array_filter(array_map('trim', $_POST['telefone'] ?? [])));

            // Concatena múltiplos emails
            $emails = implode(' / ', array_filter(array_map('trim', $_POST['email'] ?? [])));

            // Verifica se o CNPJ ou a UASG já existe no banco
            $sql_check_cliente = "SELECT COUNT(*) FROM clientes WHERE cnpj = :cnpj OR uasg = :uasg";
            $stmt_check_cliente = $pdo->prepare($sql_check_cliente);
            $stmt_check_cliente->bindParam(':cnpj', $cnpj);
            $stmt_check_cliente->bindParam(':uasg', $uasg);
            $stmt_check_cliente->execute();
            $count_cliente = $stmt_check_cliente->fetchColumn();

            if ($count_cliente > 0) {
                $error = "CNPJ ou UASG já cadastrados!"; // Se o CNPJ ou UASG já existir, exibe mensagem de erro
            }
        }

        // Se não houver erro, realiza o cadastro
        if (empty($error)) {
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

            $success = true; // Cadastro bem-sucedido
        }
    } catch (Exception $e) {
        $error = $e->getMessage(); // Exibe qualquer erro que ocorra durante o processo
    }
}

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Cliente - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Reset e variáveis CSS */
        :root {
            --primary-color: #2D893E;
            --primary-light: #9DCEAC;
            --secondary-color: #00bfae;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --light-gray: #f8f9fa;
            --medium-gray: #6c757d;
            --dark-gray: #343a40;
            --border-color: #dee2e6;
            --shadow: 0 2px 10px rgba(0,0,0,0.1);
            --radius: 8px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: var(--dark-gray);
            line-height: 1.6;
        }

        /* Header */
        header {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
            padding: 0.5rem 0;
            text-align: center;
            box-shadow: var(--shadow);
        }

        .logo {
            max-width: 140px;
            height: auto;
            transition: var(--transition);
        }

        .logo:hover {
            transform: scale(1.05);
        }

        /* Navigation */
        nav {
            background: var(--primary-color);
            padding: 0;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        nav a {
            color: white;
            padding: 0.75rem 1rem;
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            display: inline-block;
            transition: var(--transition);
            border-bottom: 3px solid transparent;
        }

        nav a:hover {
            background: rgba(255,255,255,0.1);
            border-bottom-color: var(--secondary-color);
            transform: translateY(-1px);
        }

        .dropdown {
            display: inline-block;
            position: relative;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            background: var(--primary-color);
            min-width: 200px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            z-index: 1000;
            border-radius: 0 0 var(--radius) var(--radius);
            overflow: hidden;
        }

        .dropdown-content a {
            display: block;
            padding: 0.875rem 1.25rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .dropdown-content a:last-child {
            border-bottom: none;
        }

        .dropdown:hover .dropdown-content {
            display: block;
            animation: fadeInDown 0.3s ease;
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Container principal */
        .container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        h2 {
            text-align: center;
            color: var(--primary-color);
            margin-bottom: 2rem;
            font-size: 1.8rem;
            font-weight: 600;
            position: relative;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: -0.5rem;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 3px;
            background: var(--secondary-color);
            border-radius: 2px;
        }

        /* Mensagens de feedback */
        .error, .success {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            text-align: center;
            animation: slideInDown 0.3s ease;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        @keyframes slideInDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Formulário */
        form {
            display: grid;
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        label {
            display: block;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        input, textarea, select {
            width: 100%;
            padding: 0.875rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
        }

        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* Campos dinâmicos */
        .dynamic-fields {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .field-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .add-field-btn {
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            font-size: 1.25rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            align-self: flex-start;
            margin-top: 0.5rem;
        }

        .add-field-btn:hover {
            background: #009d8f;
            transform: scale(1.1);
        }

        /* Botões */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        button {
            padding: 0.875rem 1.5rem;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        button:hover {
            background: #009d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
        }

        button[type="button"] {
            background: var(--medium-gray);
        }

        button[type="button"]:hover {
            background: var(--dark-gray);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideInUp 0.3s ease;
            overflow: hidden;
        }

        @keyframes slideInUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem;
            position: relative;
            text-align: center;
        }

        .modal-header h3 {
            margin: 0;
            color: white;
            font-size: 1.5rem;
        }

        .modal-body {
            padding: 2rem;
            text-align: center;
        }

        .btn-close {
            margin-top: 1.5rem;
            padding: 0.75rem 1.5rem;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-close:hover {
            background: #009d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 1rem;
                padding: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .logo {
                max-width: 120px;
            }

            nav {
                padding: 0.5rem 0;
                overflow-x: auto;
                white-space: nowrap;
            }

            nav a {
                padding: 0.625rem 0.75rem;
                font-size: 0.85rem;
                margin: 0 0.25rem;
            }

            .dropdown-content {
                min-width: 180px;
            }

            .container {
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .btn-container {
                flex-direction: column;
            }

            button {
                width: 100%;
                margin-bottom: 0.5rem;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
        }

        @media (max-width: 480px) {
            .logo {
                max-width: 100px;
            }

            nav a {
                padding: 0.5rem 0.625rem;
                font-size: 0.8rem;
                margin: 0 0.125rem;
            }

            .container {
                padding: 1.25rem;
                margin: 0.5rem;
            }

            h2 {
                font-size: 1.25rem;
            }

            input, select, textarea {
                padding: 0.75rem;
                font-size: 0.9rem;
            }

            .modal-header {
                padding: 1rem;
            }

            .modal-body {
                padding: 1.5rem 1rem;
            }
        }
    </style>
</head>
<body>

<header>
    <a href="index.php">
        <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
    </a>
</header>

<nav>
    <div class="dropdown">
        <a href="clientes.php">Clientes</a>
        <div class="dropdown-content">
            <a href="cadastrar_clientes.php">Inserir Clientes</a>
            <a href="consultar_clientes.php">Consultar Clientes</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="produtos.php">Produtos</a>
        <div class="dropdown-content">
            <a href="cadastro_produto.php">Inserir Produto</a>
            <a href="consulta_produto.php">Consultar Produtos</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="empenhos.php">Empenhos</a>
        <div class="dropdown-content">
            <a href="cadastro_empenho.php">Inserir Empenho</a>
            <a href="consulta_empenho.php">Consultar Empenho</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="financeiro.php">Financeiro</a>
        <div class="dropdown-content">
            <a href="contas_a_receber.php">Contas a Receber</a>
            <a href="contas_recebidas_geral.php">Contas Recebidas</a>
            <a href="contas_a_pagar.php">Contas a Pagar</a>
            <a href="contas_pagas.php">Contas Pagas</a>
            <a href="caixa.php">Caixa</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="transportadoras.php">Transportadoras</a>
        <div class="dropdown-content">
            <a href="cadastro_transportadoras.php">Inserir Transportadora</a>
            <a href="consulta_transportadoras.php">Consultar Transportadora</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="fornecedores.php">Fornecedores</a>
        <div class="dropdown-content">
            <a href="cadastro_fornecedores.php">Inserir Fornecedor</a>
            <a href="consulta_fornecedores.php">Consultar Fornecedor</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="vendas.php">Vendas</a>
        <div class="dropdown-content">
            <a href="cadastro_vendas.php">Inserir Venda</a>
            <a href="consulta_vendas.php">Consultar Venda</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="compras.php">Compras</a>
        <div class="dropdown-content">
            <a href="cadastro_compras.php">Inserir Compras</a>
            <a href="consulta_compras.php">Consultar Compras</a>
        </div>
    </div>

    <?php if ($isAdmin): ?>
        <div class="dropdown">
            <a href="usuario.php">Usuários</a>
                <div class="dropdown-content">
                    <a href="signup.php">Inserir Novo Usuário</a>
                    <a href="consulta_usuario.php">Consultar Usuário</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Exibe o link para o cadastro de funcionários apenas para administradores -->
    <?php if ($isAdmin): ?>
        <div class="dropdown">
            <a href="funcionarios.php">Funcionários</a>
                <div class="dropdown-content">
                    <a href="cadastro_funcionario.php">Inserir Novo Funcionário</a>
                    <a href="consulta_funcionario.php">Consultar Funcionário</a>
            </div>
        </div> 
    <?php endif; ?>
</nav>

<div class="container">
    <h2>Cadastro de Cliente</h2>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form action="cadastrar_clientes.php" method="POST" onsubmit="return validarFormulario()">
        <div class="form-group">
            <label for="cnpj">CNPJ:</label>
            <div class="field-container">
                <input type="text" id="cnpj" name="cnpj" placeholder="Digite o CNPJ" oninput="limitarCNPJ(event)" onblur="consultarCNPJ()">
                <button type="button" class="action-button" onclick="consultarCNPJ()">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>

        <div class="form-group">
            <label for="nome_orgaos">Nome do Órgão:</label>
            <input type="text" id="nome_orgaos" name="nome_orgaos" placeholder="Nome do órgão">
        </div>

        <div class="form-group">
            <label for="uasg">UASG:</label>
            <input type="text" id="uasg" name="uasg" placeholder="Digite o código UASG">
        </div>

        <div class="form-group">
            <label for="endereco">Endereço:</label>
            <input type="text" id="endereco" name="endereco" placeholder="Endereço completo">
        </div>

        <div class="form-group">
            <label for="telefone">Telefone:</label>
            <div class="dynamic-fields" id="telefoneFields">
                <input type="tel" id="telefone" name="telefone[]" placeholder="Telefone principal">
            </div>
            <button type="button" class="add-field-btn" onclick="addTelefoneField()">
                <i class="fas fa-plus"></i>
            </button>
        </div>

        <div class="form-group">
            <label for="email">E-mail:</label>
            <div class="dynamic-fields" id="emailFields">
                <input type="email" id="email" name="email[]" placeholder="E-mail principal">
            </div>
            <button type="button" class="add-field-btn" onclick="addEmailField()">
                <i class="fas fa-plus"></i>
            </button>
        </div>

        <div class="form-group">
            <label for="observacoes">Observações:</label>
            <textarea id="observacoes" name="observacoes" placeholder="Observações adicionais"></textarea>
        </div>

        <div class="btn-container">
            <button type="submit">
                <i class="fas fa-save"></i> Cadastrar Cliente
            </button>
            <button type="reset" style="background-color: var(--medium-gray);">
                <i class="fas fa-undo"></i> Limpar Campos
            </button>
        </div>
    </form>
</div>

<!-- Modal de sucesso -->
<div id="successModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-check-circle"></i> Sucesso</h3>
        </div>
        <div class="modal-body">
            <p>Cliente cadastrado com sucesso!</p>
            <button class="btn-close" onclick="closeModal()">Fechar</button>
        </div>
    </div>
</div>

<script>
    let telefoneCount = 1;
    let emailCount = 1;

    // Função para adicionar campo de telefone dinamicamente
    function addTelefoneField() {
        if (telefoneCount < 10) {
            telefoneCount++;
            const container = document.getElementById('telefoneFields');
            const newField = document.createElement('input');
            newField.type = 'tel';
            newField.name = 'telefone[]';
            newField.placeholder = 'Telefone ' + telefoneCount;
            container.appendChild(newField);
        } else {
            alert('Máximo de 10 telefones permitidos.');
        }
    }

    // Função para adicionar campo de e-mail dinamicamente
    function addEmailField() {
        if (emailCount < 10) {
            emailCount++;
            const container = document.getElementById('emailFields');
            const newField = document.createElement('input');
            newField.type = 'email';
            newField.name = 'email[]';
            newField.placeholder = 'E-mail ' + emailCount;
            container.appendChild(newField);
        } else {
            alert('Máximo de 10 e-mails permitidos.');
        }
    }

    // Função para limitar e formatar o CNPJ
    function limitarCNPJ(event) {
        let cnpj = event.target.value.replace(/\D/g, '');
        
        if (cnpj.length > 14) {
            cnpj = cnpj.substring(0, 14);
        }
        
        // Formata o CNPJ (XX.XXX.XXX/XXXX-XX)
        if (cnpj.length > 12) {
            cnpj = cnpj.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, "$1.$2.$3/$4-$5");
        } else if (cnpj.length > 8) {
            cnpj = cnpj.replace(/^(\d{2})(\d{3})(\d{3})(\d{0,4})/, "$1.$2.$3/$4");
        } else if (cnpj.length > 5) {
            cnpj = cnpj.replace(/^(\d{2})(\d{3})(\d{0,3})/, "$1.$2.$3");
        } else if (cnpj.length > 2) {
            cnpj = cnpj.replace(/^(\d{2})(\d{0,3})/, "$1.$2");
        }
        
        event.target.value = cnpj;
    }

    // Função para consultar o CNPJ via API da Receita Federal
    function consultarCNPJ() {
        const cnpj = document.getElementById("cnpj").value.replace(/[^\d]/g, ''); // Remove caracteres não numéricos
        if (cnpj.length === 14) {
            // Mostrar indicador de carregamento ou mensagem
            document.getElementById("nome_orgaos").value = "Consultando...";
            document.getElementById("endereco").value = "Consultando...";
            
            fetch(`consultar_cnpj.php?cnpj=${cnpj}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === "OK") {
                        // Preenche os campos com os dados retornados
                        document.getElementById("nome_orgaos").value = data.nome;
                        document.getElementById("endereco").value = `${data.logradouro}, ${data.numero} - ${data.bairro} - ${data.municipio}`;
                        
                        // Preenche o telefone principal se existir
                        if (data.telefone) {
                            document.getElementById("telefone").value = data.telefone;
                        }
                        
                        // Preenche o email principal se existir
                        if (data.email) {
                            document.getElementById("email").value = data.email;
                        }
                    } else {
                        // Limpa os campos se o CNPJ não for encontrado
                        document.getElementById("nome_orgaos").value = "";
                        document.getElementById("endereco").value = "";
                        alert("CNPJ não encontrado ou inválido.");
                    }
                })
                .catch(error => {
                    // Limpa os campos em caso de erro
                    document.getElementById("nome_orgaos").value = "";
                    document.getElementById("endereco").value = "";
                    alert("Erro ao consultar o CNPJ.");
                    console.error("Erro na consulta: ", error);
                });
        } else {
            alert("Por favor, digite um CNPJ válido com 14 dígitos.");
        }
    }

    // Função para abrir e fechar o modal de sucesso
    function openModal() {
        document.getElementById('successModal').style.display = 'block';
        document.body.style.overflow = 'hidden'; // Previne scroll da página
    }

    function closeModal() {
        document.getElementById('successModal').style.display = 'none';
        document.body.style.overflow = 'auto'; // Restaura o scroll
        window.location.href = 'cadastrar_clientes.php'; // Redireciona para a página de cadastro novamente
    }

    // Função para validar o formulário
    function validarFormulario() {
        const uasg = document.getElementById("uasg").value.trim(); // Obtém o valor do campo UASG

        if (!uasg) { // Verifica se o campo UASG está vazio
            alert("O campo UASG é obrigatório!"); // Exibe uma mensagem de alerta
            return false; // Impede o envio do formulário
        }

        return true; // Permite o envio do formulário se o campo estiver preenchido
    }

    // Inicializa o modal se o cadastro foi bem-sucedido
    window.onload = function() {
        <?php if ($success) { echo "openModal();"; } ?>
    }

    // Fecha modal ao clicar fora dele
    window.onclick = function(event) {
        const modal = document.getElementById('successModal');
        if (event.target == modal) {
            closeModal();
        }
    }

    // Tecla ESC para fechar modal
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
</script>

</body>
</html>