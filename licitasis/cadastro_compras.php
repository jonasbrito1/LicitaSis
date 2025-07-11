<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = false;

// Conexão com o banco de dados
require_once('db.php');

$fornecedores = [];
try {
    $stmt = $pdo->query("SELECT id, nome FROM fornecedores ORDER BY nome ASC");
    $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Erro ao buscar fornecedores: " . $e->getMessage();
}

// Buscar todos os produtos cadastrados
$produtos = [];
try {
    $sql = "SELECT id, nome, preco_unitario FROM produtos ORDER BY nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Erro ao buscar produtos: " . $e->getMessage();
}

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();
        
        $fornecedor_id = trim($_POST['fornecedor']);
        $numero_nf = trim($_POST['numero_nf']);
        $frete = trim($_POST['frete']);
        $link_pagamento = trim($_POST['link_pagamento']);
        $numero_empenho = trim($_POST['numero_empenho']);
        $observacao = trim($_POST['observacao']) ?? null;
        $data = trim($_POST['data']);
        $valor_total_compra = trim($_POST['valor_total_compra']);
        
        // Buscar o nome do fornecedor pelo ID
        $stmt_fornecedor = $pdo->prepare("SELECT nome FROM fornecedores WHERE id = :id");
        $stmt_fornecedor->bindParam(':id', $fornecedor_id, PDO::PARAM_INT);
        $stmt_fornecedor->execute();
        $fornecedor_data = $stmt_fornecedor->fetch(PDO::FETCH_ASSOC);
        $fornecedor_nome = $fornecedor_data ? $fornecedor_data['nome'] : '';
        
        // Inicializa a variável para o nome do arquivo
        $comprovante_pagamento = null;
        
        // Verifica se um arquivo foi enviado
        if (isset($_FILES['comprovante_pagamento']) && $_FILES['comprovante_pagamento']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            $filename = $_FILES['comprovante_pagamento']['name'];
            $filetype = pathinfo($filename, PATHINFO_EXTENSION);
            
            // Verifica se a extensão do arquivo é permitida
            if (in_array(strtolower($filetype), $allowed)) {
                // Cria um nome único para o arquivo
                $new_filename = uniqid('comprovante_') . '.' . $filetype;
                $upload_dir = 'uploads/comprovantes/';
                
                // Cria o diretório se não existir
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Move o arquivo para o diretório de uploads
                if (move_uploaded_file($_FILES['comprovante_pagamento']['tmp_name'], $upload_dir . $new_filename)) {
                    $comprovante_pagamento = $upload_dir . $new_filename;
                } else {
                    throw new Exception("Erro ao fazer upload do comprovante.");
                }
            } else {
                throw new Exception("Tipo de arquivo não permitido. Apenas JPG, JPEG, PNG e PDF são aceitos.");
            }
        }

        // Obter o primeiro produto para preencher os campos obrigatórios da tabela compras
        $primeiro_produto_id = isset($_POST['produto_id'][0]) ? $_POST['produto_id'][0] : null;
        $primeiro_produto_nome = "";
        $primeira_quantidade = isset($_POST['produto_quantidade'][0]) ? $_POST['produto_quantidade'][0] : 0;
        $primeiro_valor_unitario = isset($_POST['produto_valor_unitario'][0]) ? $_POST['produto_valor_unitario'][0] : 0;
        $primeiro_valor_total = isset($_POST['produto_valor_total'][0]) ? $_POST['produto_valor_total'][0] : 0;
        
        if ($primeiro_produto_id) {
            $stmt_produto = $pdo->prepare("SELECT nome FROM produtos WHERE id = :id");
            $stmt_produto->bindParam(':id', $primeiro_produto_id, PDO::PARAM_INT);
            $stmt_produto->execute();
            $produto = $stmt_produto->fetch(PDO::FETCH_ASSOC);
            if ($produto) {
                $primeiro_produto_nome = $produto['nome'];
            }
        }

        // Insere a compra principal no banco de dados
        $sql = "INSERT INTO compras (fornecedor, numero_nf, produto, quantidade, valor_unitario, valor_total, frete, link_pagamento, numero_empenho, observacao, data, comprovante_pagamento) 
                VALUES (:fornecedor, :numero_nf, :produto, :quantidade, :valor_unitario, :valor_total, :frete, :link_pagamento, :numero_empenho, :observacao, :data, :comprovante_pagamento)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':fornecedor', $fornecedor_nome, PDO::PARAM_STR);
        $stmt->bindParam(':numero_nf', $numero_nf, PDO::PARAM_STR);
        $stmt->bindParam(':produto', $primeiro_produto_nome, PDO::PARAM_STR);
        $stmt->bindParam(':quantidade', $primeira_quantidade, PDO::PARAM_INT);
        $stmt->bindParam(':valor_unitario', $primeiro_valor_unitario, PDO::PARAM_STR);
        $stmt->bindParam(':valor_total', $valor_total_compra, PDO::PARAM_STR);
        $stmt->bindParam(':frete', $frete, PDO::PARAM_STR);
        $stmt->bindParam(':link_pagamento', $link_pagamento, PDO::PARAM_STR);
        $stmt->bindParam(':numero_empenho', $numero_empenho, PDO::PARAM_STR);
        $stmt->bindParam(':observacao', $observacao, PDO::PARAM_STR);
        $stmt->bindParam(':data', $data, PDO::PARAM_STR);
        $stmt->bindParam(':comprovante_pagamento', $comprovante_pagamento, PDO::PARAM_STR);

        // Executa a consulta
        if ($stmt->execute()) {
            $compra_id = $pdo->lastInsertId();
            
            // Processa os produtos da compra
            if (isset($_POST['produto_id']) && is_array($_POST['produto_id'])) {
                $produto_ids = $_POST['produto_id'];
                $quantidades = $_POST['produto_quantidade'];
                $valores_unitarios = $_POST['produto_valor_unitario'];
                $valores_totais = $_POST['produto_valor_total'];
                
                // Verifica se a tabela produto_compra existe
                try {
                    $pdo->query("SELECT 1 FROM produto_compra LIMIT 1");
                    $tabela_existe = true;
                } catch (Exception $e) {
                    $tabela_existe = false;
                }
                
                // Cria a tabela produto_compra se não existir
                if (!$tabela_existe) {
                    $sql_criar_tabela = "CREATE TABLE produto_compra (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        compra_id INT NOT NULL,
                        produto_id INT NOT NULL,
                        quantidade INT NOT NULL,
                        valor_unitario DECIMAL(10,2) NOT NULL,
                        valor_total DECIMAL(10,2) NOT NULL,
                        FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
                        FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE RESTRICT
                    )";
                    $pdo->exec($sql_criar_tabela);
                }
                
                // Insere cada produto da compra
                $sql_produto = "INSERT INTO produto_compra (compra_id, produto_id, quantidade, valor_unitario, valor_total) 
                                VALUES (:compra_id, :produto_id, :quantidade, :valor_unitario, :valor_total)";
                $stmt_produto = $pdo->prepare($sql_produto);
                
                foreach ($produto_ids as $index => $produto_id) {
                    if (empty($produto_id)) continue;
                    
                    $stmt_produto->bindParam(':compra_id', $compra_id, PDO::PARAM_INT);
                    $stmt_produto->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
                    $stmt_produto->bindParam(':quantidade', $quantidades[$index], PDO::PARAM_INT);
                    $stmt_produto->bindParam(':valor_unitario', $valores_unitarios[$index], PDO::PARAM_STR);
                    $stmt_produto->bindParam(':valor_total', $valores_totais[$index], PDO::PARAM_STR);
                    $stmt_produto->execute();
                }
            }
            
            $pdo->commit();
            $success = true;
        } else {
            throw new Exception("Erro ao cadastrar compra.");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Compras - LicitaSis</title>
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

        h3 {
            color: var(--primary-color);
            margin: 1.5rem 0 1rem;
            font-size: 1.3rem;
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }

        /* Formulário */
        form {
            display: grid;
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        input, select, textarea {
            padding: 0.875rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
            background: white;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        }

        input[readonly] {
            background: var(--light-gray);
            color: var(--medium-gray);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        .file-input-container {
            position: relative;
            margin-bottom: 1rem;
        }

        .file-input-label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.875rem;
            background: var(--light-gray);
            border: 2px dashed var(--border-color);
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
        }

        .file-input-label:hover {
            background: #e9ecef;
            border-color: var(--secondary-color);
        }

        .file-input-label i {
            margin-right: 0.5rem;
        }

        input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-name {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: var(--medium-gray);
            text-align: center;
        }

        /* Produtos */
        .produtos-section {
            margin-top: 1.5rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            background: var(--light-gray);
        }

        .produto-item {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1rem;
            position: relative;
            transition: var(--transition);
        }

        .produto-item:hover {
            box-shadow: var(--shadow);
            border-color: var(--secondary-color);
        }

        .produto-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .produto-title {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .remove-produto {
            background: var(--danger-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .remove-produto:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        .add-produto {
            background: var(--success-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            padding: 0.875rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            width: 100%;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .add-produto:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .add-produto i {
            margin-right: 0.5rem;
        }

        .valor-total-section {
            background: var(--light-gray);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-top: 1.5rem;
        }

        .valor-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--primary-color);
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
            min-width: 150px;
        }

        button:hover {
            background: #009d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
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
        }

        .modal-header h3 {
            margin: 0;
            color: white;
            font-size: 1.5rem;
            border-bottom: none;
        }

        .close {
            color: white;
            float: right;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            position: absolute;
            right: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .close:hover {
            transform: translateY(-50%) scale(1.1);
            color: #ffdddd;
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            padding: 1rem 2rem;
            text-align: center;
            border-top: 1px solid var(--border-color);
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
            }

            nav a {
                padding: 0.75rem 0.5rem;
                font-size: 0.85rem;
                margin: 0 0.25rem;
            }

            .dropdown-content {
                min-width: 160px;
            }

            .container {
                margin: 1rem;
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .btn-container {
                flex-direction: column;
            }

            button {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .logo {
                max-width: 100px;
            }

            nav a {
                padding: 0.625rem 0.375rem;
                font-size: 0.8rem;
                margin: 0 0.125rem;
            }

            .container {
                margin: 0.5rem;
                padding: 1rem;
            }

            h2 {
                font-size: 1.25rem;
            }

            input, select, textarea {
                padding: 0.75rem;
                font-size: 0.9rem;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
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
    <h2>Cadastro de Compras</h2>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form action="cadastro_compras.php" method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label for="fornecedor">Fornecedor:</label>
            <select id="fornecedor" name="fornecedor" required>
                <option value="">Selecione um fornecedor</option>
                <?php foreach ($fornecedores as $fornecedor): ?>
                    <option value="<?php echo $fornecedor['id']; ?>"><?php echo htmlspecialchars($fornecedor['nome']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="numero_nf">Número de NF:</label>
                <input type="text" id="numero_nf" name="numero_nf" required>
            </div>
            <div class="form-group">
                <label for="data">Data:</label>
                <input type="date" id="data" name="data" required>
            </div>
        </div>

        <!-- Seção de Produtos -->
        <h3>Produtos</h3>
        <div class="produtos-section">
            <div id="produtos-container">
                <!-- Os produtos serão adicionados aqui dinamicamente -->
            </div>
            
            <button type="button" class="add-produto" onclick="adicionarProduto()">
                <i class="fas fa-plus"></i> Adicionar Produto
            </button>
        </div>

        <!-- Valor Total da Compra -->
        <div class="valor-total-section">
            <div class="valor-total-row">
                <span>Valor Total da Compra:</span>
                <span>R$ <input type="text" id="valor_total_compra" name="valor_total_compra" value="0.00" readonly style="width: 120px; text-align: right; font-weight: bold;"></span>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="frete">Frete (R$):</label>
                <input type="number" id="frete" name="frete" step="0.01" min="0" value="0.00" oninput="calcularValorTotalCompra()">
            </div>
            <div class="form-group">
                <label for="numero_empenho">Número de Empenho:</label>
                <input type="text" id="numero_empenho" name="numero_empenho">
            </div>
        </div>

        <div class="form-group">
            <label for="link_pagamento">Link para pagamento:</label>
            <input type="url" id="link_pagamento" name="link_pagamento" placeholder="https://...">
        </div>

        <div class="form-group">
            <label for="comprovante_pagamento">Comprovante de Pagamento:</label>
            <div class="file-input-container">
                <label for="comprovante_pagamento" class="file-input-label">
                    <i class="fas fa-upload"></i> Selecionar arquivo
                </label>
                <input type="file" id="comprovante_pagamento" name="comprovante_pagamento" accept=".jpg,.jpeg,.png,.pdf" onchange="updateFileName(this)">
                <div class="file-name" id="file-name">Nenhum arquivo selecionado</div>
            </div>
            <small style="color: var(--medium-gray);">Formatos aceitos: JPG, JPEG, PNG, PDF. Tamanho máximo: 5MB</small>
        </div>

        <div class="form-group">
            <label for="observacao">Observação:</label>
            <textarea id="observacao" name="observacao" placeholder="Informações adicionais sobre a compra..."></textarea>
        </div>

        <div class="btn-container">
            <button type="submit">Cadastrar Compra</button>
        </div>
    </form>
</div>

<!-- Modal de Sucesso -->
<?php if ($success): ?>
    <div id="successModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Compra Cadastrada com Sucesso!</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p style="text-align: center; margin-bottom: 1rem;">
                    Os dados da compra foram registrados no sistema.
                </p>
            </div>
            <div class="modal-footer">
                <button onclick="resetPage()">Fechar</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    // Contador para identificar produtos
    let produtoCounter = 0;
    
    // Produtos disponíveis
    const produtosDisponiveis = <?php echo json_encode($produtos); ?>;
    
    // Adiciona um produto ao formulário
    function adicionarProduto() {
        produtoCounter++;
        const produtosContainer = document.getElementById('produtos-container');
        
        const produtoDiv = document.createElement('div');
        produtoDiv.className = 'produto-item';
        produtoDiv.id = `produto-${produtoCounter}`;
        
        let produtosOptions = '<option value="">Selecione o Produto</option>';
        produtosDisponiveis.forEach(produto => {
            produtosOptions += `<option value="${produto.id}" data-preco="${produto.preco_unitario}">${produto.nome}</option>`;
        });
        
        produtoDiv.innerHTML = `
            <div class="produto-header">
                <div class="produto-title">Produto ${produtoCounter}</div>
                <button type="button" class="remove-produto" onclick="removerProduto(${produtoCounter})">Remover</button>
            </div>
            
            <div class="form-group">
                <label for="produto_id_${produtoCounter}">Produto:</label>
                <select id="produto_id_${produtoCounter}" name="produto_id[]" required onchange="atualizarProdutoInfo(${produtoCounter})">
                    ${produtosOptions}
                </select>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="produto_quantidade_${produtoCounter}">Quantidade:</label>
                    <input type="number" id="produto_quantidade_${produtoCounter}" name="produto_quantidade[]" min="1" value="1" oninput="calcularValorTotalProduto(${produtoCounter})">
                </div>
                <div class="form-group">
                    <label for="produto_valor_unitario_${produtoCounter}">Valor Unitário:</label>
                    <input type="number" id="produto_valor_unitario_${produtoCounter}" name="produto_valor_unitario[]" step="0.01" value="0.00" oninput="calcularValorTotalProduto(${produtoCounter})">
                </div>
            </div>
            
            <div class="form-group">
                <label for="produto_valor_total_${produtoCounter}">Valor Total:</label>
                <input type="text" id="produto_valor_total_${produtoCounter}" name="produto_valor_total[]" value="0.00" readonly>
            </div>
        `;
        
        produtosContainer.appendChild(produtoDiv);
    }
    
    // Remove um produto do formulário
    function removerProduto(id) {
        const produtoDiv = document.getElementById(`produto-${id}`);
        if (produtoDiv) {
            produtoDiv.remove();
            calcularValorTotalCompra();
        }
    }
    
    // Atualiza as informações do produto quando selecionado
    function atualizarProdutoInfo(id) {
        const produtoSelect = document.getElementById(`produto_id_${id}`);
        const selectedOption = produtoSelect.options[produtoSelect.selectedIndex];
        const precoUnitario = parseFloat(selectedOption.getAttribute('data-preco')) || 0;
        
        // Preenche o valor unitário com o preço do produto selecionado
        document.getElementById(`produto_valor_unitario_${id}`).value = precoUnitario.toFixed(2);
        
        // Atualiza o valor total do produto
        calcularValorTotalProduto(id);
    }
    
    // Calcula o valor total de um produto
    function calcularValorTotalProduto(id) {
        const quantidade = parseFloat(document.getElementById(`produto_quantidade_${id}`).value) || 0;
        const valorUnitario = parseFloat(document.getElementById(`produto_valor_unitario_${id}`).value) || 0;
        const valorTotal = quantidade * valorUnitario;
        
        // Preenche o campo de valor total do produto
        document.getElementById(`produto_valor_total_${id}`).value = valorTotal.toFixed(2);
        
        // Recalcula o valor total da compra
        calcularValorTotalCompra();
    }
    
    // Calcula o valor total da compra
    function calcularValorTotalCompra() {
        let valorTotal = 0;
        
        // Soma os valores totais de todos os produtos
        const valoresProdutos = document.querySelectorAll('input[name="produto_valor_total[]"]');
        valoresProdutos.forEach(input => {
            valorTotal += parseFloat(input.value) || 0;
        });
        
        // Adiciona o valor do frete
        const frete = parseFloat(document.getElementById('frete').value) || 0;
        valorTotal += frete;
        
        // Atualiza o campo de valor total da compra
        document.getElementById('valor_total_compra').value = valorTotal.toFixed(2);
    }
    
    // Atualiza o nome do arquivo selecionado
    function updateFileName(input) {
        const fileName = input.files[0] ? input.files[0].name : "Nenhum arquivo selecionado";
        document.getElementById('file-name').textContent = fileName;
    }
    
    // Exibe o modal de sucesso
    <?php if ($success): ?>
        document.getElementById("successModal").style.display = "block";
    <?php endif; ?>
    
    // Fecha o modal e reseta a página
    function closeModal() {
        document.getElementById("successModal").style.display = "none";
        resetPage();
    }
    
    function resetPage() {
        window.location = "cadastro_compras.php";
    }
    
    // Fecha o modal ao clicar fora dele
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
    
    // Adiciona o primeiro produto ao carregar a página
    document.addEventListener('DOMContentLoaded', function() {
        adicionarProduto();
    });
</script>

</body>
</html>