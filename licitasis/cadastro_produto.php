<?php
session_start();

require_once('db.php');

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

// Inicializa a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";

// Verifica se o formulário foi enviado para cadastrar o produto
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Recebe os dados do formulário
    $codigo = $_POST['codigo'];
    $nome = $_POST['nome'];
    $und = $_POST['und'];
    $fornecedor = $_POST['fornecedor'];
    $observacao = $_POST['observacao'];
    $preco_unitario = $_POST['preco_unitario'];

    // Verifica se o código ou o nome do produto já existe no banco
    $sql_check_produto = "SELECT COUNT(*) FROM produtos WHERE codigo = :codigo OR nome = :nome";
    $stmt_check_produto = $pdo->prepare($sql_check_produto);
    $stmt_check_produto->bindParam(':codigo', $codigo);
    $stmt_check_produto->bindParam(':nome', $nome);
    $stmt_check_produto->execute();
    $count_produto = $stmt_check_produto->fetchColumn();
    
    if ($count_produto > 0) {
        $error = "Produto já cadastrado com o mesmo código ou nome!"; // Se o produto já existir, exibe mensagem de erro
    } else {
        // Processamento da imagem
        if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] == 0) {
            // Definindo as variáveis
            $imagemTmp = $_FILES['imagem']['tmp_name'];
            $imagemNome = $_FILES['imagem']['name'];
            $imagemExtensao = pathinfo($imagemNome, PATHINFO_EXTENSION);
            $imagemNovoNome = uniqid() . "." . $imagemExtensao;
            $imagemDest = "uploads" . $imagemNovoNome; // Diretório onde as imagens serão salvas

            // Verificar se a extensão da imagem é válida
            $extensoesPermitidas = ["jpg", "jpeg", "png", "gif"];
            if (!in_array(strtolower($imagemExtensao), $extensoesPermitidas)) {
                $error = "Somente imagens JPG, JPEG, PNG e GIF são permitidas.";
            } else {
                // Move a imagem para o diretório de uploads
                if (move_uploaded_file($imagemTmp, $imagemDest)) {
                    // Realiza o cadastro do produto no banco de dados
                    try {
                        $sql = "INSERT INTO produtos (codigo, nome, und, fornecedor, imagem, preco_unitario, observacao) 
                                VALUES (:codigo, :nome, :und, :fornecedor, :imagem, :preco_unitario, :observacao)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->bindParam(':codigo', $codigo);
                        $stmt->bindParam(':nome', $nome);
                        $stmt->bindParam(':und', $und);
                        $stmt->bindParam(':fornecedor', $fornecedor);
                        $stmt->bindParam(':imagem', $imagemDest); // Armazena o caminho da imagem
                        $stmt->bindParam(':preco_unitario', $preco_unitario);
                        $stmt->bindParam(':observacao', $observacao);

                        if ($stmt->execute()) {
                            $success = "Produto cadastrado com sucesso!";
                        } else {
                            $error = "Erro ao cadastrar o produto.";
                        }
                    } catch (PDOException $e) {
                        $error = "Erro na consulta: " . $e->getMessage();
                    }
                } else {
                    $error = "Erro ao fazer upload da imagem.";
                }
            }
        } else {
            $error = "Por favor, selecione uma imagem.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Produto - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Reset e variáveis CSS */
        :root {
            --primary-color: #2D893E;
            --primary-light: #9DCEAC;
            --primary-dark: #1e6e2d;
            --secondary-color: #00bfae;
            --secondary-dark: #009d8f;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --light-gray: #f8f9fa;
            --medium-gray: #6c757d;
            --dark-gray: #343a40;
            --border-color: #dee2e6;
            --shadow: 0 4px 12px rgba(0,0,0,0.1);
            --shadow-hover: 0 6px 15px rgba(0,0,0,0.15);
            --radius: 12px;
            --radius-sm: 8px;
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

        .container:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-5px);
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

        input, select, textarea {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background-color: #f9f9f9;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.2);
            background-color: white;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        /* Estilo para o input de arquivo */
        input[type="file"] {
            padding: 0.6rem 1rem;
            background-color: #f9f9f9;
            cursor: pointer;
        }

        input[type="file"]::-webkit-file-upload-button {
            background-color: var(--secondary-color);
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius-sm);
            margin-right: 1rem;
            cursor: pointer;
            transition: var(--transition);
        }

        input[type="file"]::-webkit-file-upload-button:hover {
            background-color: var(--secondary-dark);
        }

        /* Preview da imagem */
        .image-preview-container {
            margin: 1rem 0;
            text-align: center;
        }

        #imagemPreview {
            max-width: 200px;
            max-height: 200px;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow);
            object-fit: contain;
            background-color: #f9f9f9;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
        }

        /* Botões */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
        }

        button {
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
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
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        button:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0, 191, 174, 0.2);
        }

        /* Mensagens de erro e sucesso */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--radius-sm);
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

        /* Menu mobile */
        .mobile-menu-btn {
            display: none;
            background: transparent;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            position: absolute;
            right: 1rem;
            top: 0.5rem;
            z-index: 1001;
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 2rem 1.5rem;
                padding: 2rem;
            }
            
            nav {
                justify-content: flex-start;
                padding: 0 1rem;
            }
            
            nav a {
                padding: 0.75rem 0.75rem;
                font-size: 0.9rem;
            }
            
            .dropdown-content {
                min-width: 180px;
            }
        }

        @media (max-width: 992px) {
            .container {
                max-width: 90%;
            }
            
            .btn-container {
                gap: 1rem;
            }
            
            button {
                min-width: 160px;
                padding: 0.75rem 1.25rem;
            }
        }

        @media (max-width: 768px) {
            .logo {
                max-width: 140px;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            nav {
                flex-direction: column;
                align-items: center;
                padding: 0;
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.5s ease;
            }
            
            nav.active {
                max-height: 1000px;
            }
            
            .dropdown {
                width: 100%;
            }
            
            nav a {
                width: 100%;
                padding: 0.75rem 1rem;
                border-bottom: 1px solid rgba(255,255,255,0.1);
            }
            
            .dropdown-content {
                position: static;
                box-shadow: none;
                width: 100%;
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.3s ease;
            }
            
            .dropdown.active .dropdown-content {
                max-height: 500px;
                display: block;
            }
            
            .dropdown-content a {
                padding-left: 2rem;
                background: rgba(0,0,0,0.1);
            }
            
            .container {
                padding: 1.5rem;
                margin: 1.5rem auto;
            }
            
            h2 {
                font-size: 1.5rem;
            }
            
            .btn-container {
                flex-direction: column;
                align-items: center;
            }
            
            button {
                width: 100%;
                max-width: 300px;
            }
            
            .image-preview-container {
                margin: 0.75rem 0;
            }
            
            #imagemPreview {
                max-width: 150px;
                max-height: 150px;
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
                border-radius: var(--radius-sm);
            }
            
            h2 {
                font-size: 1.3rem;
                margin-bottom: 1.5rem;
            }
            
            h2::after {
                width: 60px;
                height: 2px;
            }
            
            label {
                font-size: 0.9rem;
            }
            
            input, select, textarea {
                padding: 0.75rem 0.875rem;
                font-size: 0.95rem;
            }
            
            .btn-container {
                margin-top: 1.5rem;
                gap: 0.75rem;
            }
            
            button {
                padding: 0.7rem 1rem;
                font-size: 0.9rem;
            }
            
            .mobile-menu-btn {
                font-size: 1.3rem;
                right: 0.75rem;
                top: 0.4rem;
            }
            
            #imagemPreview {
                max-width: 120px;
                max-height: 120px;
            }
        }

        @media (max-width: 360px) {
            .logo {
                max-width: 100px;
            }
            
            .container {
                padding: 1rem;
                margin: 0.75rem auto;
            }
            
            h2 {
                font-size: 1.2rem;
            }
            
            input, select, textarea {
                padding: 0.7rem 0.8rem;
                font-size: 0.9rem;
            }
            
            button {
                padding: 0.6rem 0.8rem;
                font-size: 0.85rem;
            }
            
            #imagemPreview {
                max-width: 100px;
                max-height: 100px;
            }
        }
    </style>
</head>
<body>

<header>
    <a href="index.php">
        <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
    </a>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
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
    <h2>Cadastro de Produto</h2>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <form action="cadastro_produto.php" method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label for="codigo"><i class="fas fa-barcode"></i> Código:</label>
            <input type="text" id="codigo" name="codigo" required placeholder="Digite o código do produto">
        </div>

        <div class="form-group">
            <label for="nome"><i class="fas fa-tag"></i> Nome do Produto:</label>
            <input type="text" id="nome" name="nome" required placeholder="Digite o nome do produto">
        </div>

        <div class="form-group">
            <label for="und"><i class="fas fa-ruler"></i> Unidade de Medida:</label>
            <input type="text" id="und" name="und" required placeholder="Ex: UN, KG, CX">
        </div>

        <div class="form-group">
            <label for="fornecedor"><i class="fas fa-truck"></i> Fornecedor:</label>
            <select name="fornecedor" id="fornecedor" required>
                <option value="">Selecione um Fornecedor</option>
                <?php
                // Buscando os fornecedores cadastrados no banco de dados
                $sql = "SELECT id, nome FROM fornecedores";
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Populando as opções do select com os fornecedores
                foreach ($fornecedores as $fornecedor) {
                    echo "<option value='" . $fornecedor['id'] . "'>" . $fornecedor['nome'] . "</option>";
                }
                ?>
            </select>
        </div>

        <div class="form-group">
            <label for="preco_unitario"><i class="fas fa-dollar-sign"></i> Preço Unitário:</label>
            <input type="number" id="preco_unitario" name="preco_unitario" step="0.01" min="0" required placeholder="0.00">
        </div>

        <div class="form-group">
            <label for="imagem"><i class="fas fa-image"></i> Imagem do Produto:</label>
            <input type="file" id="imagem" name="imagem" accept="image/*" onchange="previewImagem(event)" required>
        </div>

        <div class="image-preview-container">
            <img id="imagemPreview" alt="Preview da Imagem" style="display: none;">
        </div>

        <div class="form-group">
            <label for="observacao"><i class="fas fa-comment-alt"></i> Observação:</label>
            <textarea id="observacao" name="observacao" placeholder="Informações adicionais sobre o produto"></textarea>
        </div>

        <div class="btn-container">
            <button type="submit">
                <i class="fas fa-save"></i> Cadastrar Produto
            </button>
        </div>
    </form>
</div>

<script>
    // Função para exibir o preview da imagem antes do envio
    function previewImagem(event) {
        var imagem = document.getElementById('imagemPreview');
        imagem.src = URL.createObjectURL(event.target.files[0]);
        imagem.style.display = 'block';  // Mostra a imagem após a seleção
    }

    // Toggle menu mobile
    document.getElementById('mobileMenuBtn').addEventListener('click', function() {
        const nav = document.getElementById('mainNav');
        nav.classList.toggle('active');
        
        // Alterna o ícone do botão
        const icon = this.querySelector('i');
        if (icon.classList.contains('fa-bars')) {
            icon.classList.remove('fa-bars');
            icon.classList.add('fa-times');
        } else {
            icon.classList.remove('fa-times');
            icon.classList.add('fa-bars');
        }
    });
    
    // Gerencia os dropdowns no mobile
    if (window.innerWidth <= 768) {
        const dropdowns = document.querySelectorAll('.dropdown');
        
        dropdowns.forEach(dropdown => {
            const link = dropdown.querySelector('a');
            
            link.addEventListener('click', function(e) {
                // Previne a navegação apenas no mobile
                if (window.innerWidth <= 768) {
                    e.preventDefault();
                    dropdown.classList.toggle('active');
                    
                    // Fecha outros dropdowns
                    dropdowns.forEach(otherDropdown => {
                        if (otherDropdown !== dropdown) {
                            otherDropdown.classList.remove('active');
                        }
                    });
                }
            });
        });
    }
    
    // Animação de entrada
    document.addEventListener('DOMContentLoaded', function() {
        const container = document.querySelector('.container');
        container.style.opacity = '0';
        container.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            container.style.opacity = '1';
            container.style.transform = 'translateY(0)';
        }, 100);
        
        // Formata o campo de preço para exibir como moeda
        const precoInput = document.getElementById('preco_unitario');
        precoInput.addEventListener('blur', function() {
            if (this.value) {
                this.value = parseFloat(this.value).toFixed(2);
            }
        });
    });
    
    // Ajusta o comportamento do menu em resize
    window.addEventListener('resize', function() {
        const nav = document.getElementById('mainNav');
        const mobileBtn = document.getElementById('mobileMenuBtn');
        const icon = mobileBtn.querySelector('i');
        
        if (window.innerWidth > 768) {
            nav.classList.remove('active');
            icon.classList.remove('fa-times');
            icon.classList.add('fa-bars');
            
            // Remove os event listeners dos dropdowns
            const dropdowns = document.querySelectorAll('.dropdown');
            dropdowns.forEach(dropdown => {
                dropdown.classList.remove('active');
            });
        }
    });
</script>

</body>
</html>