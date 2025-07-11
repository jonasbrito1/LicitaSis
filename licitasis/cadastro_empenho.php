<?php 
session_start();
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$error = "";  // Inicializando a variável $error
$success = false; // Inicializa como false

// Conexão com o banco de dados
require_once('db.php');

// Processamento do formulário de cadastro
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Captura os dados do formulário
        $numero = isset($_POST['numero']) ? trim($_POST['numero']) : null;
        $cliente_uasg = isset($_POST['cliente_uasg']) ? trim($_POST['cliente_uasg']) : null;
        $cliente_nome = isset($_POST['cliente_nome']) ? trim($_POST['cliente_nome']) : null;
        $produtos = isset($_POST['produto']) ? $_POST['produto'] : [];
        $produto_ids = isset($_POST['produto_id']) ? $_POST['produto_id'] : [];
        $quantidades = isset($_POST['quantidade']) ? $_POST['quantidade'] : [];
        $valores_unitarios = isset($_POST['valor_unitario']) ? $_POST['valor_unitario'] : [];
        $pregao = isset($_POST['pregao']) ? trim($_POST['pregao']) : null;
        $upload = isset($_FILES['upload']['name']) && !empty($_FILES['upload']['name']) ? $_FILES['upload']['name'] : null;
        $valor_total_empenho = 0; // Inicializa o valor total do empenho
        $classificacao = isset($_POST['classificacao']) ? trim($_POST['classificacao']) : 'Pendente';

        // Validação básica dos campos obrigatórios
        if (empty($numero) || empty($cliente_uasg) || empty($cliente_nome) || empty($produtos)) {
            throw new Exception("Preencha todos os campos obrigatórios.");
        }

        // Validação adicional dos produtos
        foreach ($produtos as $index => $produto_nome) {
            if (empty($produto_nome) || empty($quantidades[$index]) || empty($valores_unitarios[$index])) {
                throw new Exception("Todos os campos de produto devem ser preenchidos.");
            }
        }

        // Processa o upload do arquivo, se enviado
        if ($upload) {
            $targetDir = "uploads/";
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true); // Cria o diretório, se não existir
            }
            $targetFile = $targetDir . basename($_FILES["upload"]["name"]);
            if (!move_uploaded_file($_FILES["upload"]["tmp_name"], $targetFile)) {
                throw new Exception("Erro ao fazer upload do arquivo.");
            }
        }

        // VALIDAÇÃO ATUALIZADA: Verifica se o número do empenho já existe para a mesma UASG
        $sqlCheck = "SELECT cliente_nome FROM empenhos WHERE numero = :numero AND cliente_uasg = :cliente_uasg LIMIT 1";
        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->bindParam(':numero', $numero, PDO::PARAM_STR);
        $stmtCheck->bindParam(':cliente_uasg', $cliente_uasg, PDO::PARAM_STR);
        $stmtCheck->execute();
        $checkResult = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($checkResult) {
            throw new Exception("O número do empenho '{$numero}' já existe para a UASG '{$cliente_uasg}' - Cliente: {$checkResult['cliente_nome']}. Use um número diferente ou verifique se este empenho já foi cadastrado.");
        }

        // Calcula o valor total do empenho antes de inserir
        foreach ($quantidades as $index => $quantidade) {
            $valor_unitario = isset($valores_unitarios[$index]) ? floatval($valores_unitarios[$index]) : 0;
            $quantidade = intval($quantidade);
            $valor_total_empenho += ($quantidade * $valor_unitario);
        }

        // Inicia uma transação
        $pdo->beginTransaction();

        // Insere o empenho na tabela "empenhos" - versão conservadora
        $sql = "INSERT INTO empenhos (numero, cliente_uasg, cliente_nome, valor_total_empenho, classificacao, pregao)
                VALUES (:numero, :cliente_uasg, :cliente_nome, :valor_total_empenho, :classificacao, :pregao)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':numero', $numero, PDO::PARAM_STR);
        $stmt->bindParam(':cliente_uasg', $cliente_uasg, PDO::PARAM_STR);
        $stmt->bindParam(':cliente_nome', $cliente_nome, PDO::PARAM_STR);
        $stmt->bindParam(':valor_total_empenho', $valor_total_empenho, PDO::PARAM_STR);
        $stmt->bindParam(':classificacao', $classificacao, PDO::PARAM_STR);
        $stmt->bindParam(':pregao', $pregao, PDO::PARAM_STR);
        $stmt->execute();

        $empenho_id = $pdo->lastInsertId(); // Obtém o ID do empenho inserido

        // Insere os produtos na tabela "empenho_produtos"
        foreach ($produtos as $index => $produto_nome) {
            $quantidade = isset($quantidades[$index]) ? intval($quantidades[$index]) : 0;
            $valor_unitario = isset($valores_unitarios[$index]) ? floatval($valores_unitarios[$index]) : 0;
            $produto_id = isset($produto_ids[$index]) && !empty($produto_ids[$index]) ? intval($produto_ids[$index]) : null;

            // Calcula o valor total do produto
            $valor_total_produto = $quantidade * $valor_unitario;

            // Se temos um produto_id válido, verifica se existe na tabela produtos
            if ($produto_id) {
                $sql_produto = "SELECT id, preco_unitario, observacao FROM produtos WHERE id = :produto_id";
                $stmt_produto = $pdo->prepare($sql_produto);
                $stmt_produto->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
                $stmt_produto->execute();
                $produto_info = $stmt_produto->fetch(PDO::FETCH_ASSOC);

                if ($produto_info) {
                    $descricao_produto = $produto_info['observacao'];
                } else {
                    // Se o produto não existe mais na tabela produtos, usa valores padrão
                    $descricao_produto = $produto_nome;
                    $produto_id = null; // Reset para null se não existe
                }
            } else {
                // Se não temos produto_id, é um produto novo digitado manualmente
                $descricao_produto = $produto_nome;
                $produto_id = null;
            }

            // Insere o produto na tabela "empenho_produtos"
            $sql_produto_insert = "INSERT INTO empenho_produtos (empenho_id, produto_id, quantidade, valor_unitario, valor_total, descricao_produto)
                                  VALUES (:empenho_id, :produto_id, :quantidade, :valor_unitario, :valor_total, :descricao_produto)";
            $stmt_produto_insert = $pdo->prepare($sql_produto_insert);
            $stmt_produto_insert->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
            $stmt_produto_insert->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
            $stmt_produto_insert->bindParam(':quantidade', $quantidade, PDO::PARAM_INT);
            $stmt_produto_insert->bindParam(':valor_unitario', $valor_unitario, PDO::PARAM_STR);
            $stmt_produto_insert->bindParam(':valor_total', $valor_total_produto, PDO::PARAM_STR);
            $stmt_produto_insert->bindParam(':descricao_produto', $descricao_produto, PDO::PARAM_STR);
            $stmt_produto_insert->execute();
        }

        // Commit da transação
        $pdo->commit();

        $success = true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack(); // Rollback se ocorrer algum erro
        }
        $error = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Erro ao cadastrar o empenho: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Empenho - Licita Sis</title>
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
            max-width: 1200px;
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

        /* Mensagem de validação em tempo real */
        .validation-message {
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            margin-top: 0.5rem;
            font-size: 0.9rem;
            font-weight: 500;
            display: none;
            animation: slideInDown 0.3s ease;
        }

        .validation-message.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .validation-message.info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        @keyframes slideInDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Formulário */
        form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
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
            display: block;
            font-size: 0.95rem;
        }

        input, select, textarea {
            width: 100%;
            padding: 0.875rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        }

        input[readonly], select[disabled], textarea[readonly] {
            background: var(--light-gray);
            color: var(--medium-gray);
            cursor: not-allowed;
        }

        input[type="file"] {
            padding: 0.5rem;
            background: var(--light-gray);
            border: 1px dashed var(--border-color);
        }

        /* Produtos */
        #produtos-container {
            margin-top: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .produto-item {
            background: var(--light-gray);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
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

        .remove-produto-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .remove-produto-btn:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        #addProdutoBtn {
            background: var(--success-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            padding: 0.875rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        #addProdutoBtn:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        /* Sugestões de produtos */
        .suggestions-container {
            position: absolute;
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            background: white;
            z-index: 1001;
            width: 100%;
            border-radius: 0 0 6px 6px;
            box-shadow: var(--shadow);
            margin-top: -2px;
        }

        .suggestion-item {
            padding: 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition);
            font-size: 0.95rem;
        }

        .suggestion-item:hover {
            background: var(--light-gray);
            color: var(--primary-color);
        }

        .suggestion-item:last-child {
            border-bottom: none;
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
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        button:hover {
            background: #009d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
        }

        button[type="submit"] {
            background: var(--primary-color);
        }

        button[type="submit"]:hover {
            background: #1e6e2e;
            box-shadow: 0 4px 12px rgba(45, 137, 62, 0.3);
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
        }

        /* Scrollbar personalizada */
        .suggestions-container::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .suggestions-container::-webkit-scrollbar-track {
            background: var(--light-gray);
            border-radius: 4px;
        }

        .suggestions-container::-webkit-scrollbar-thumb {
            background: var(--medium-gray);
            border-radius: 4px;
        }

        .suggestions-container::-webkit-scrollbar-thumb:hover {
            background: var(--dark-gray);
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
    <h2>Cadastro de Empenho</h2>

    <?php if ($error): ?>
        <div class="error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success">
            <i class="fas fa-check-circle"></i> Empenho cadastrado com sucesso!
        </div>
        <script>
            // Limpa o formulário após sucesso
            setTimeout(function() {
                window.location.href = 'cadastro_empenho.php';
            }, 2000);
        </script>
    <?php endif; ?>

    <form action="cadastro_empenho.php" method="POST" enctype="multipart/form-data">
        <div class="form-row">
            <div class="form-group">
                <label for="numero"><i class="fas fa-hashtag"></i> Número do empenho:</label>
                <input type="text" id="numero" name="numero" required value="<?php echo $success ? '' : (isset($_POST['numero']) ? htmlspecialchars($_POST['numero']) : ''); ?>">
                <div class="validation-message" id="numero-validation"></div>
            </div>
            
            <div class="form-group">
                <label for="cliente_uasg"><i class="fas fa-building"></i> UASG:</label>
                <input type="text" id="cliente_uasg" name="cliente_uasg" placeholder="Digite a UASG" onblur="fetchClientData()" required value="<?php echo $success ? '' : (isset($_POST['cliente_uasg']) ? htmlspecialchars($_POST['cliente_uasg']) : ''); ?>">
            </div>
        </div>
        
        <div class="form-group">
            <label for="cliente_nome"><i class="fas fa-user"></i> Nome do Cliente:</label>
            <input type="text" id="cliente_nome" name="cliente_nome" placeholder="Digite o nome do cliente" required value="<?php echo $success ? '' : (isset($_POST['cliente_nome']) ? htmlspecialchars($_POST['cliente_nome']) : ''); ?>">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="pregao"><i class="fas fa-gavel"></i> Pregão:</label>
                <input type="text" id="pregao" name="pregao" value="<?php echo $success ? '' : (isset($_POST['pregao']) ? htmlspecialchars($_POST['pregao']) : ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="classificacao"><i class="fas fa-tag"></i> Classificação:</label>
                <select name="classificacao" id="classificacao" required>
                    <option value="Faturada" <?php echo (isset($_POST['classificacao']) && $_POST['classificacao'] == 'Faturada') ? 'selected' : ''; ?>>Faturada</option>
                    <option value="Comprada" <?php echo (isset($_POST['classificacao']) && $_POST['classificacao'] == 'Comprada') ? 'selected' : ''; ?>>Comprada</option>
                    <option value="Entregue" <?php echo (isset($_POST['classificacao']) && $_POST['classificacao'] == 'Entregue') ? 'selected' : ''; ?>>Entregue</option>
                    <option value="Liquidada" <?php echo (isset($_POST['classificacao']) && $_POST['classificacao'] == 'Liquidada') ? 'selected' : ''; ?>>Liquidada</option>
                    <option value="Pendente" <?php echo (!isset($_POST['classificacao']) || $_POST['classificacao'] == 'Pendente') ? 'selected' : ''; ?>>Pendente</option>
                    <option value="Devolucao" <?php echo (isset($_POST['classificacao']) && $_POST['classificacao'] == 'Devolucao') ? 'selected' : ''; ?>>Devolução</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label for="upload"><i class="fas fa-file-upload"></i> Upload de Documento:</label>
            <input type="file" id="upload" name="upload">
        </div>

        <h3><i class="fas fa-shopping-cart"></i> Produtos</h3>
        <div id="produtos-container">
            <!-- Os campos serão adicionados dinamicamente aqui -->
        </div>

        <button type="button" id="addProdutoBtn">
            <i class="fas fa-plus"></i> Adicionar Produto
        </button>

        <div class="form-group">
            <label for="valor_total_empenho"><i class="fas fa-money-bill-wave"></i> Valor Total do Empenho:</label>
            <input type="number" step="0.01" id="valor_total_empenho" name="valor_total_empenho" required readonly value="0.00" />
        </div>

        <div class="btn-container">
            <button type="submit">
                <i class="fas fa-save"></i> Cadastrar Empenho
            </button>
        </div>
    </form>
</div>

<script>
// NOVA FUNÇÃO: Validação em tempo real de duplicação
function validateEmpenhoUnique() {
    const numero = document.getElementById('numero').value.trim();
    const uasg = document.getElementById('cliente_uasg').value.trim();
    const validationMsg = document.getElementById('numero-validation');
    const numeroInput = document.getElementById('numero');

    if (numero && uasg) {
        // Mostra mensagem de verificação
        validationMsg.className = 'validation-message info';
        validationMsg.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando disponibilidade...';
        validationMsg.style.display = 'block';

        fetch(`check_empenho_duplicate.php?numero=${encodeURIComponent(numero)}&uasg=${encodeURIComponent(uasg)}`)
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    numeroInput.style.borderColor = "var(--danger-color)";
                    validationMsg.className = 'validation-message warning';
                    validationMsg.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${data.message || 'Este número de empenho já existe para esta UASG.'}`;
                    validationMsg.style.display = 'block';
                } else {
                    numeroInput.style.borderColor = "var(--success-color)";
                    validationMsg.className = 'validation-message info';
                    validationMsg.innerHTML = '<i class="fas fa-check-circle"></i> Número disponível para esta UASG';
                    validationMsg.style.display = 'block';
                    
                    // Remove a mensagem após 3 segundos se estiver tudo ok
                    setTimeout(() => {
                        validationMsg.style.display = 'none';
                        numeroInput.style.borderColor = "";
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Erro ao verificar duplicação:', error);
                validationMsg.className = 'validation-message warning';
                validationMsg.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Erro ao verificar disponibilidade';
                validationMsg.style.display = 'block';
                
                setTimeout(() => {
                    validationMsg.style.display = 'none';
                }, 3000);
            });
    } else {
        validationMsg.style.display = 'none';
        numeroInput.style.borderColor = "";
    }
}

function fetchClientData() {
    const uasgInput = document.getElementById('cliente_uasg');
    const clienteNomeInput = document.getElementById('cliente_nome');
    const uasg = uasgInput.value.trim();

    if (uasg === "") {
        clienteNomeInput.value = "";
        return;
    }

    // Feedback visual durante a busca
    clienteNomeInput.value = "Buscando...";
    clienteNomeInput.style.opacity = "0.7";

    // Faz a requisição para buscar os dados do cliente com base na UASG
    fetch(`fetch_cliente_data.php?uasg=${encodeURIComponent(uasg)}`)
    .then(response => response.json())
    .then(data => {
        clienteNomeInput.style.opacity = "1";
        if (data.success) {
            clienteNomeInput.value = data.nome_orgaos;
            clienteNomeInput.style.borderColor = "var(--success-color)";
            setTimeout(() => {
                clienteNomeInput.style.borderColor = "";
            }, 2000);
            
            // NOVA FUNCIONALIDADE: Valida o empenho após buscar cliente
            setTimeout(validateEmpenhoUnique, 500);
        } else {
            clienteNomeInput.value = "";
            uasgInput.style.borderColor = "var(--danger-color)";
            setTimeout(() => {
                uasgInput.style.borderColor = "";
            }, 2000);
            alert("UASG não encontrada no sistema.");
        }
    })
    .catch(error => {
        console.error('Erro ao buscar os dados do cliente:', error);
        clienteNomeInput.value = "";
        clienteNomeInput.style.opacity = "1";
        alert("Erro ao buscar dados do cliente. Tente novamente.");
    });
}

function updateProductTotal(inputElement) {
    var container = inputElement.closest('.produto-item');
    var quantidadeInput = container.querySelector('input[name="quantidade[]"]');
    var valorUnitarioInput = container.querySelector('input[name="valor_unitario[]"]');
    var valorTotalInput = container.querySelector('input[name="valor_total[]"]');

    var quantidade = parseFloat(quantidadeInput.value) || 0;
    var precoUnitario = parseFloat(valorUnitarioInput.value) || 0;
    var valorTotalProduto = quantidade * precoUnitario;

    valorTotalInput.value = valorTotalProduto.toFixed(2);
    updateTotal();
}

function updateTotal() {
    var produtos = document.querySelectorAll('.produto-item');
    var totalEmpenho = 0;

    produtos.forEach(function(produto) {
        var valorTotalInput = produto.querySelector('input[name="valor_total[]"]');
        var valorTotalProduto = parseFloat(valorTotalInput.value) || 0;
        totalEmpenho += valorTotalProduto;
    });

    document.getElementById('valor_total_empenho').value = totalEmpenho.toFixed(2);
}

function removeProduto(button) {
    var container = button.closest('.produto-item');
    
    // Animação de remoção
    container.style.opacity = "0";
    container.style.transform = "translateX(20px)";
    
    setTimeout(() => {
        container.remove();
        updateTotal();
      
        // Renumera os produtos restantes
        var produtos = document.querySelectorAll('.produto-item');
        produtos.forEach(function(produto, index) {
            var titulo = produto.querySelector('.produto-title');
            if (titulo) {
                titulo.textContent = `Produto ${index + 1}`;
            }
        });
    }, 300);
}

document.getElementById('addProdutoBtn').addEventListener('click', function() {
    var container = document.getElementById('produtos-container');
    var produtoCount = container.children.length + 1;

    var newProduto = document.createElement('div');
    newProduto.className = 'produto-item';
    newProduto.style.opacity = "0";
    newProduto.style.transform = "translateY(20px)";
    
    newProduto.innerHTML = `
        <div class="produto-header">
            <div class="produto-title">Produto ${produtoCount}</div>
            <button type="button" class="remove-produto-btn" onclick="removeProduto(this)">
                <i class="fas fa-trash-alt"></i> Remover
            </button>
        </div>
        
        <div class="form-group">
            <label><i class="fas fa-box"></i> Produto:</label>
            <div style="position: relative;">
                <input type="text" name="produto[]" placeholder="Digite o nome do produto" oninput="fetchProductSuggestions(this)" autocomplete="off" required>
                <div class="produto-suggestions suggestions-container" style="display: none;"></div>
            </div>
        </div>

        <input type="hidden" name="produto_id[]">

        <div class="form-row">
            <div class="form-group">
                <label><i class="fas fa-sort-numeric-up"></i> Quantidade:</label>
                <input type="number" name="quantidade[]" min="1" value="1" oninput="updateProductTotal(this)" required>
            </div>

            <div class="form-group">
                <label><i class="fas fa-dollar-sign"></i> Valor Unitário:</label>
                <input type="number" name="valor_unitario[]" min="0.01" step="0.01" value="0.00" oninput="updateProductTotal(this)" required>
            </div>
        </div>

        <div class="form-group">
            <label><i class="fas fa-calculator"></i> Valor Total:</label>
            <input type="text" name="valor_total[]" value="0.00" readonly>
        </div>
    `;

    container.appendChild(newProduto);
    
    // Animação de entrada
    setTimeout(() => {
        newProduto.style.opacity = "1";
        newProduto.style.transform = "translateY(0)";
    }, 10);
});

function fetchProductSuggestions(inputElement) {
    const query = inputElement.value.trim();
    const suggestionsContainer = inputElement.nextElementSibling;

    if (query.length > 0) {
        // Feedback visual durante a busca
        suggestionsContainer.innerHTML = '<div class="suggestion-item"><i class="fas fa-spinner fa-spin"></i> Buscando produtos...</div>';
        suggestionsContainer.style.display = 'block';
        
        fetch(`search_produtos.php?query=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                suggestionsContainer.innerHTML = '';

                if (data.length > 0) {
                    data.forEach(produto => {
                        const suggestionItem = document.createElement('div');
                        suggestionItem.classList.add('suggestion-item');
                        suggestionItem.innerHTML = `<i class="fas fa-box"></i> ${produto.nome}`;

                        suggestionItem.onclick = function() {
                            inputElement.value = produto.nome;
                            suggestionsContainer.innerHTML = '';
                            suggestionsContainer.style.display = 'none';

                            const produtoContainer = inputElement.closest('.produto-item');
                            const produtoIdInput = produtoContainer.querySelector('input[name="produto_id[]"]');
                            const valorUnitarioInput = produtoContainer.querySelector('input[name="valor_unitario[]"]');

                            produtoIdInput.value = produto.id;
                            valorUnitarioInput.value = parseFloat(produto.preco_unitario || 0).toFixed(2);
                          
                            // Destaque visual para o preço atualizado
                            valorUnitarioInput.style.backgroundColor = "#d4edda";
                            setTimeout(() => {
                                valorUnitarioInput.style.backgroundColor = "";
                            }, 1000);
                            
                            updateProductTotal(valorUnitarioInput);
                        };

                        suggestionsContainer.appendChild(suggestionItem);
                    });
                } else {
                    const noResult = document.createElement('div');
                    noResult.classList.add('suggestion-item');
                    noResult.innerHTML = '<i class="fas fa-exclamation-circle"></i> Nenhum produto encontrado';
                    noResult.style.fontStyle = 'italic';
                    noResult.style.color = 'var(--medium-gray)';
                    suggestionsContainer.appendChild(noResult);
                }
            })
            .catch(error => {
                console.error('Erro ao buscar os produtos:', error);
                suggestionsContainer.innerHTML = '<div class="suggestion-item" style="color: var(--danger-color);"><i class="fas fa-exclamation-triangle"></i> Erro ao buscar produtos</div>';
            });
    } else {
        suggestionsContainer.innerHTML = '';
        suggestionsContainer.style.display = 'none';
    }
}

// Fecha as sugestões ao clicar fora
document.addEventListener('click', (event) => {
    const suggestionsContainers = document.querySelectorAll('.produto-suggestions');
    suggestionsContainers.forEach(container => {
        if (!event.target.closest('.produto-suggestions') && !event.target.matches('input[name="produto[]"]')) {
            container.style.display = 'none';
        }
    });
});

// NOVA FUNCIONALIDADE: Event listeners para validação em tempo real
document.addEventListener('DOMContentLoaded', function() {
    const numeroInput = document.getElementById('numero');
    const uasgInput = document.getElementById('cliente_uasg');
    
    // Valida quando o usuário sai do campo número
    numeroInput.addEventListener('blur', validateEmpenhoUnique);
    
    // Valida quando o usuário digita no campo número (com debounce)
    let numeroTimeout;
    numeroInput.addEventListener('input', function() {
        clearTimeout(numeroTimeout);
        numeroTimeout = setTimeout(validateEmpenhoUnique, 1000); // Aguarda 1 segundo após parar de digitar
    });
    
    // Valida quando muda a UASG
    uasgInput.addEventListener('blur', function() {
        setTimeout(validateEmpenhoUnique, 100);
    });
});

// Validação do formulário
document.querySelector('form').addEventListener('submit', function(event) {
    var produtos = document.querySelectorAll('input[name="produto[]"]');
    var valid = true;

    if (produtos.length === 0) {
        alert("Adicione pelo menos um produto.");
        event.preventDefault();
        return;
    }

    produtos.forEach(function(produto) {
        if (produto.value.trim() === "") {
            valid = false;
            produto.style.borderColor = "var(--danger-color)";
            setTimeout(() => {
                produto.style.borderColor = "";
            }, 3000);
        }
    });

    var quantidades = document.querySelectorAll('input[name="quantidade[]"]');
    quantidades.forEach(function(quantidade) {
        if (quantidade.value <= 0) {
            valid = false;
            quantidade.style.borderColor = "var(--danger-color)";
            setTimeout(() => {
                quantidade.style.borderColor = "";
            }, 3000);
        }
    });

    var valoresUnitarios = document.querySelectorAll('input[name="valor_unitario[]"]');
    valoresUnitarios.forEach(function(valor) {
        if (parseFloat(valor.value) <= 0) {
            valid = false;
            valor.style.borderColor = "var(--danger-color)";
            setTimeout(() => {
                valor.style.borderColor = "";
            }, 3000);
        }
    });

    // NOVA VALIDAÇÃO: Verifica se há mensagem de duplicação ativa
    const validationMsg = document.getElementById('numero-validation');
    if (validationMsg.style.display === 'block' && validationMsg.classList.contains('warning')) {
        valid = false;
        alert("Existe um problema com o número do empenho. Verifique a mensagem de validação.");
        document.getElementById('numero').focus();
    }

    if (!valid) {
        event.preventDefault();
        alert("Por favor, corrija os campos destacados em vermelho.");
        
        // Scroll para o primeiro campo com erro
        const firstError = document.querySelector('input[style*="border-color: var(--danger-color)"]');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
});
</script>

</body>
</html>