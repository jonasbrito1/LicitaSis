<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

$error = ""; // Inicializa a variável $error
$success = false; // Variável para controlar o sucesso do cadastro

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        require_once('db.php'); // Inclui a conexão com o banco de dados

        // Obtém os dados do formulário
        $codigo = isset($_POST['codigo']) ? trim($_POST['codigo']) : null;
        $cnpj = isset($_POST['cnpj']) ? trim($_POST['cnpj']) : null;
        $nome = isset($_POST['nome']) ? trim($_POST['nome']) : null;
        $endereco = isset($_POST['endereco']) ? trim($_POST['endereco']) : null;
        $telefone = isset($_POST['telefone']) ? trim($_POST['telefone']) : null;
        $email = isset($_POST['email']) ? trim($_POST['email']) : null;
        $observacoes = isset($_POST['observacoes']) ? trim($_POST['observacoes']) : null;

        // Insere a transportadora no banco de dados
        $sql = "INSERT INTO transportadora (codigo, cnpj, nome, endereco, telefone, email, observacoes) 
                VALUES (:codigo, :cnpj, :nome, :endereco, :telefone, :email, :observacoes)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':codigo', $codigo, PDO::PARAM_STR);
        $stmt->bindParam(':cnpj', $cnpj, PDO::PARAM_STR);
        $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
        $stmt->bindParam(':endereco', $endereco, PDO::PARAM_STR);
        $stmt->bindParam(':telefone', $telefone, PDO::PARAM_STR);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':observacoes', $observacoes, PDO::PARAM_STR);

        $stmt->execute(); // Executa a inserção

        $success = true; // Cadastro bem-sucedido
    } catch (Exception $e) {
        $error = $e->getMessage(); // Caso ocorra algum erro, captura a mensagem
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Transportadora - LicitaSis</title>
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
            --border-color: #dee2e6;
            --shadow: 0 4px 12px rgba(0,0,0,0.1);
            --radius: 12px;
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
            color: #343a40;
            line-height: 1.6;
        }

        /* Header */
        header {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
            padding: 0.8rem 0;
            text-align: center;
            box-shadow: var(--shadow);
            width: 100%;
            position: relative;
            z-index: 100;
        }

        .logo {
            max-width: 160px;
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
            position: relative;
            z-index: 99;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
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
            border-radius: 0 0 var(--radius-sm) var(--radius-sm);
            overflow: hidden;
        }

        .dropdown-content a {
            display: block;
            padding: 0.875rem 1.25rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: left;
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
            margin: 2.5rem auto;
            padding: 2.5rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
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

        /* Formulário */
        form {
            margin-top: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
            font-weight: 600;
            font-size: 0.95rem;
        }

        input, textarea {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
            background-color: #f9f9f9;
        }

        input:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.2);
            background-color: white;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        /* Botões */
        .btn-container {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
        }

        button {
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
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
            min-width: 180px;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        button:hover {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        /* Mensagens de erro e sucesso */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--radius);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-error {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border-left: 4px solid var(--danger-color);
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .container {
                padding: 1.5rem;
                margin: 1.5rem auto;
            }

            h2 {
                font-size: 1.5rem;
            }

            button {
                width: 100%;
                max-width: 300px;
            }
        }

        @media (max-width: 480px) {
            header {
                padding: 0.6rem 0;
            }

            .logo {
                max-width: 120px;
            }

            .container {
                padding: 1.25rem;
                margin: 1rem auto;
            }

            h2 {
                font-size: 1.3rem;
            }

            label {
                font-size: 0.9rem;
            }

            input, textarea {
                padding: 0.75rem 0.875rem;
                font-size: 0.95rem;
            }

            button {
                padding: 0.7rem 1rem;
                font-size: 0.9rem;
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
    <h2>Cadastro de Transportadora</h2>

    <?php if ($error) { echo "<div class='alert alert-error'><i class='fas fa-exclamation-circle'></i> $error</div>"; } ?>
    <?php if ($success) { echo "<div class='alert alert-success'><i class='fas fa-check-circle'></i> Transportadora cadastrada com sucesso!</div>"; } ?>

    <form action="cadastro_transportadoras.php" method="POST">
        <div class="form-group">
            <label for="codigo"><i class="fas fa-barcode"></i> Código:</label>
            <input type="text" id="codigo" name="codigo" required placeholder="Digite o código da transportadora">
        </div>

        <div class="form-group">
            <label for="cnpj"><i class="fas fa-id-card"></i> CNPJ:</label>
            <input type="text" id="cnpj" name="cnpj" required oninput="limitarCNPJ(event)" onblur="consultarCNPJ()" placeholder="Digite o CNPJ">
        </div>

        <div class="form-group">
            <label for="nome"><i class="fas fa-building"></i> Nome:</label>
            <input type="text" id="nome" name="nome" required placeholder="Digite o nome da transportadora">
        </div>

        <div class="form-group">
            <label for="endereco"><i class="fas fa-map-marker-alt"></i> Endereço Completo:</label>
            <input type="text" id="endereco" name="endereco" placeholder="Digite o endereço completo">
        </div>

        <div class="form-group">
            <label for="telefone"><i class="fas fa-phone"></i> Telefone:</label>
            <input type="tel" id="telefone" name="telefone" placeholder="Digite o telefone">
        </div>

        <div class="form-group">
            <label for="email"><i class="fas fa-envelope"></i> E-mail:</label>
            <input type="email" id="email" name="email" placeholder="Digite o e-mail">
        </div>

        <div class="form-group">
            <label for="observacoes"><i class="fas fa-comment-alt"></i> Observações:</label>
            <textarea id="observacoes" name="observacoes" placeholder="Informações adicionais sobre a transportadora"></textarea>
        </div>

        <div class="btn-container">
            <button type="submit">
                <i class="fas fa-save"></i> Cadastrar Transportadora
            </button>
        </div>
    </form>
</div>

<div id="successModal" style="display: none;">
    <div class="modal-content">
        <h3>Transportadora cadastrada com sucesso!</h3>
        <button class="btn-close" onclick="closeModal()">Fechar</button>
    </div>
</div>

<script>
    function limitarCNPJ(event) {
        const input = event.target;
        let value = input.value.replace(/\D/g, ''); // Remove caracteres não numéricos
        if (value.length <= 14) {
            input.value = value; // Limita a quantidade de caracteres para o CNPJ
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

    function openModal() {
        document.getElementById('successModal').style.display = 'block';
    }

    function closeModal() {
        document.getElementById('successModal').style.display = 'none';
        window.location.href = 'cadastro_transportadoras.php'; // Redireciona para a página de cadastro novamente
    }

    window.onload = function() {
        <?php if ($success) { echo "openModal();"; } ?>
    }
</script>

</body>
</html>