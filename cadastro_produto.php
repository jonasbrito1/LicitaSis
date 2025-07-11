<?php
// ===========================================
// CADASTRO DE PRODUTOS COM CONTROLE DE ESTOQUE
// Sistema completo com impostos e gestão de estoque
// ===========================================

session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inclui o sistema de permissões e auditoria
require_once('db.php');
include('permissions.php');
include('includes/audit.php');

$permissionManager = initPermissions($pdo);

// Verifica se o usuário tem permissão para cadastrar produtos
$permissionManager->requirePermission('produtos', 'create');

// Registra acesso à página
logUserAction('READ', 'produtos_cadastro');

// Inicializa a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = false;

// Verifica se o formulário foi enviado para cadastrar o produto
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Recebe os dados do formulário
        $codigo = trim($_POST['codigo'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $und = trim($_POST['und'] ?? '');
        $fornecedor = trim($_POST['fornecedor'] ?? '');
        $observacao = trim($_POST['observacao'] ?? '');
        $preco_unitario = floatval($_POST['preco_unitario'] ?? 0);
        $categoria = trim($_POST['categoria'] ?? '');
        
        // CAMPOS DE ESTOQUE
        $estoque_inicial = floatval($_POST['estoque_inicial'] ?? 0);
        $estoque_minimo = floatval($_POST['estoque_minimo'] ?? 0);
        $controla_estoque = isset($_POST['controla_estoque']) ? 1 : 0;
        
        // Impostos
        $icms = floatval($_POST['icms'] ?? 0);
        $irpj = floatval($_POST['irpj'] ?? 0);
        $cofins = floatval($_POST['cofins'] ?? 0);
        $csll = floatval($_POST['csll'] ?? 0);
        $pis_pasep = floatval($_POST['pis_pasep'] ?? 0);
        $ipi = floatval($_POST['ipi'] ?? 0);
        $margem_lucro = floatval($_POST['margem_lucro'] ?? 0);
        
        // Valores calculados
        $total_impostos = floatval($_POST['total_impostos_valor'] ?? 0);
        $custo_total = floatval($_POST['custo_total_valor'] ?? 0);
        $preco_venda = floatval($_POST['preco_venda_valor'] ?? 0);

        // Validações básicas
        if (empty($codigo)) {
            $error = "O código do produto é obrigatório!";
        } elseif (empty($nome)) {
            $error = "O nome do produto é obrigatório!";
        } elseif (empty($und)) {
            $error = "A unidade de medida é obrigatória!";
        } elseif ($preco_unitario <= 0) {
            $error = "O preço unitário deve ser maior que zero!";
        } elseif ($estoque_inicial < 0) {
            $error = "O estoque inicial não pode ser negativo!";
        } elseif ($estoque_minimo < 0) {
            $error = "O estoque mínimo não pode ser negativo!";
        } else {
            // Verifica se o código ou o nome do produto já existe no banco
            $sql_check_produto = "SELECT COUNT(*) FROM produtos WHERE codigo = :codigo OR nome = :nome";
            $stmt_check_produto = $pdo->prepare($sql_check_produto);
            $stmt_check_produto->bindParam(':codigo', $codigo);
            $stmt_check_produto->bindParam(':nome', $nome);
            $stmt_check_produto->execute();
            $count_produto = $stmt_check_produto->fetchColumn();
            
            if ($count_produto > 0) {
                $error = "Produto já cadastrado com o mesmo código ou nome!";
            } else {
                // Processamento da imagem
                $imagemPath = null;
                if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] == 0) {
                    $imagemTmp = $_FILES['imagem']['tmp_name'];
                    $imagemNome = $_FILES['imagem']['name'];
                    $imagemExtensao = strtolower(pathinfo($imagemNome, PATHINFO_EXTENSION));
                    
                    // Verificar se a extensão da imagem é válida
                    $extensoesPermitidas = ["jpg", "jpeg", "png", "gif", "webp"];
                    if (!in_array($imagemExtensao, $extensoesPermitidas)) {
                        $error = "Somente imagens JPG, JPEG, PNG, GIF e WEBP são permitidas.";
                    } else {
                        // Verifica o tamanho do arquivo (máx 5MB)
                        if ($_FILES['imagem']['size'] > 5 * 1024 * 1024) {
                            $error = "A imagem não pode ter mais de 5MB.";
                        } else {
                            // Cria o diretório se não existir
                            $uploadDir = "uploads/produtos/";
                            if (!file_exists($uploadDir)) {
                                mkdir($uploadDir, 0777, true);
                            }
                            
                            // Gera nome único para a imagem
                            $imagemNovoNome = uniqid() . "_" . time() . "." . $imagemExtensao;
                            $imagemPath = $uploadDir . $imagemNovoNome;
                            
                            // Move a imagem para o diretório
                            if (!move_uploaded_file($imagemTmp, $imagemPath)) {
                                $error = "Erro ao fazer upload da imagem.";
                                $imagemPath = null;
                            }
                        }
                    }
                }
                
                // Se não houver erro, realiza o cadastro
                if (empty($error)) {
                    $pdo->beginTransaction();
                    
                    try {
                        // Prepara a query de inserção - ATUALIZADA COM CAMPOS DE ESTOQUE
                        $sql = "INSERT INTO produtos (
                            codigo, 
                            nome, 
                            und, 
                            fornecedor, 
                            imagem, 
                            preco_unitario,
                            observacao, 
                            categoria_id, 
                            estoque_inicial,
                            estoque_atual,
                            estoque_minimo, 
                            controla_estoque,
                            icms, 
                            irpj, 
                            cofins, 
                            csll, 
                            pis_pasep,
                            ipi, 
                            margem_lucro, 
                            total_impostos, 
                            custo_total, 
                            preco_venda, 
                            created_at
                        ) VALUES (
                            :codigo, 
                            :nome, 
                            :und, 
                            :fornecedor, 
                            :imagem, 
                            :preco_unitario,
                            :observacao, 
                            :categoria_id, 
                            :estoque_inicial,
                            :estoque_inicial,
                            :estoque_minimo,
                            :controla_estoque,
                            :icms, 
                            :irpj,
                            :cofins, 
                            :csll, 
                            :pis_pasep, 
                            :ipi, 
                            :margem_lucro,
                            :total_impostos, 
                            :custo_total, 
                            :preco_venda, 
                            NOW()
                        )";

                        $stmt = $pdo->prepare($sql);

                        // Bind all parameters correctly
                        $stmt->bindParam(':codigo', $codigo);
                        $stmt->bindParam(':nome', $nome);
                        $stmt->bindParam(':und', $und);
                        $stmt->bindParam(':fornecedor', $fornecedor);
                        $stmt->bindParam(':imagem', $imagemPath);
                        $stmt->bindParam(':preco_unitario', $preco_unitario);
                        $stmt->bindParam(':observacao', $observacao);
                        $stmt->bindParam(':categoria_id', $_POST['categoria']);
                        $stmt->bindParam(':estoque_inicial', $estoque_inicial);
                        $stmt->bindParam(':estoque_minimo', $estoque_minimo);
                        $stmt->bindParam(':controla_estoque', $controla_estoque);
                        $stmt->bindParam(':icms', $icms);
                        $stmt->bindParam(':irpj', $irpj);
                        $stmt->bindParam(':cofins', $cofins);
                        $stmt->bindParam(':csll', $csll);
                        $stmt->bindParam(':pis_pasep', $pis_pasep);
                        $stmt->bindParam(':ipi', $ipi);
                        $stmt->bindParam(':margem_lucro', $margem_lucro);
                        $stmt->bindParam(':total_impostos', $total_impostos);
                        $stmt->bindParam(':custo_total', $custo_total);
                        $stmt->bindParam(':preco_venda', $preco_venda);

                        if ($stmt->execute()) {
                            $produto_id = $pdo->lastInsertId();
                            
                            // REGISTRA MOVIMENTAÇÃO INICIAL DE ESTOQUE
                            if ($controla_estoque && $estoque_inicial > 0) {
                                $sql_movimentacao = "INSERT INTO movimentacoes_estoque 
                                    (produto_id, tipo, quantidade, quantidade_anterior, quantidade_atual, motivo, tipo_documento, usuario_id, data_movimentacao) 
                                    VALUES (:produto_id, 'INICIAL', :quantidade, 0, :quantidade, 'Estoque inicial do produto', 'INICIAL', :usuario_id, NOW())";
                                
                                $stmt_mov = $pdo->prepare($sql_movimentacao);
                                $stmt_mov->bindParam(':produto_id', $produto_id);
                                $stmt_mov->bindParam(':quantidade', $estoque_inicial);
                                $stmt_mov->bindParam(':usuario_id', $_SESSION['user']['id']);
                                $stmt_mov->execute();
                            }
                            
                            // Registra auditoria
                            logUserAction('CREATE', 'produtos', $produto_id, [
                                'codigo' => $codigo,
                                'nome' => $nome,
                                'preco_unitario' => $preco_unitario,
                                'estoque_inicial' => $estoque_inicial,
                                'estoque_minimo' => $estoque_minimo,
                                'controla_estoque' => $controla_estoque,
                                'fornecedor' => $fornecedor
                            ]);
                            
                            $pdo->commit();
                            $success = true;
                        } else {
                            throw new Exception("Erro ao cadastrar o produto.");
                        }
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Erro no banco de dados: " . $e->getMessage();
        error_log("Erro no cadastro de produto: " . $e->getMessage());
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        error_log("Erro no cadastro de produto: " . $e->getMessage());
    }
}

// Inclui o template de header APÓS todo o processamento
include('includes/header_template.php');
renderHeader("Cadastro de Produto - LicitaSis", "produtos");
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Produto - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        /* Variáveis CSS */
        :root {
            --primary-color: #2D893E;
            --primary-light: #9DCEAC;
            --secondary-color: #00bfae;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-gray: #f8f9fa;
            --medium-gray: #6c757d;
            --dark-gray: #343a40;
            --border-color: #dee2e6;
            --shadow: 0 4px 12px rgba(0,0,0,0.1);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.3s ease;
        }

        /* Reset básico */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            line-height: 1.6;
            color: var(--dark-gray);
        }

        /* Container principal */
        .container {
            max-width: 1200px;
            margin: 2.5rem auto;
            padding: 2.5rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Título principal */
        h2 {
            text-align: center;
            color: var(--primary-color);
            margin-bottom: 2rem;
            font-size: 2rem;
            font-weight: 600;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
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
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            font-weight: 500;
            text-align: center;
            animation: slideInDown 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-left: 4px solid var(--danger-color);
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 1px solid #c3e6cb;
            border-left: 4px solid var(--success-color);
        }

        @keyframes slideInDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Formulário */
        .form-container {
            display: grid;
            gap: 2rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .form-row-2 {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }

        .form-row-3 {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group label i {
            color: var(--secondary-color);
            width: 16px;
        }

        .form-control {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
            transform: translateY(-1px);
        }

        .form-control:hover {
            border-color: var(--secondary-color);
        }

        /* Checkbox especial para controla estoque */
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: linear-gradient(135deg, #e8f5e8 0%, #c3e6cb 100%);
            border-radius: var(--radius-sm);
            border: 1px solid var(--success-color);
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: var(--success-color);
            cursor: pointer;
        }

        .checkbox-wrapper label {
            margin-bottom: 0 !important;
            cursor: pointer;
            font-weight: 600;
            color: var(--success-color);
        }

        /* Seções do formulário */
        .form-section {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--secondary-color);
        }

        .form-section-title {
            font-size: 1.1rem;
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-section-title i {
            color: var(--secondary-color);
        }

        /* Seção de estoque especial */
        .estoque-section {
            background: linear-gradient(135deg, #e8f5e8 0%, #c3e6cb 100%);
            border-left-color: var(--success-color);
        }

        .estoque-section .form-section-title {
            color: var(--success-color);
        }

        .estoque-section .form-section-title i {
            color: var(--success-color);
        }

        /* Campo de preço com ícone de moeda */
        .price-input-wrapper {
            position: relative;
        }

        .price-input-wrapper::before {
            content: "R$";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--medium-gray);
            font-weight: 600;
            pointer-events: none;
        }

        .price-input {
            padding-left: 3rem !important;
        }

        /* Campo obrigatório */
        .required::after {
            content: ' *';
            color: var(--danger-color);
            font-weight: bold;
        }

        /* Resumo financeiro */
        .resumo-financeiro {
            background: linear-gradient(135deg, #e8f5e8 0%, #c3e6cb 100%);
            border-left-color: var(--success-color);
            margin-top: 1rem;
        }

        .resumo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .resumo-item {
            background: white;
            padding: 1rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--success-color);
            text-align: center;
        }

        .resumo-item .valor {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--success-color);
            margin-top: 0.5rem;
        }

        .resumo-item .label {
            font-size: 0.9rem;
            color: var(--medium-gray);
            font-weight: 500;
        }

        /* Botões principais */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            min-width: 160px;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--medium-gray) 0%, #5a6268 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
        }

        .btn-secondary:hover:not(:disabled) {
            background: linear-gradient(135deg, #5a6268 0%, var(--medium-gray) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(108, 117, 125, 0.3);
        }

        /* Informações de estoque */
        .estoque-info {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--success-color);
            margin-top: 1rem;
        }

        .estoque-info h4 {
            color: var(--success-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .estoque-alert {
            background: var(--info-color);
            color: white;
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .container {
                margin: 1.5rem;
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.75rem;
                flex-direction: column;
                gap: 0.5rem;
            }

            .form-row, .form-row-2, .form-row-3 {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .resumo-grid {
                grid-template-columns: 1fr;
            }

            .btn-container {
                flex-direction: column;
                gap: 1rem;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>
        <i class="fas fa-box-open"></i>
        Cadastro de Produto
        <span class="badge-new">Com Controle de Estoque</span>
    </h2>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form class="form-container" action="cadastro_produto.php" method="POST" enctype="multipart/form-data" onsubmit="return validarFormulario(event)">
        
        <!-- Seção: Informações Básicas -->
        <div class="form-section">
            <h3 class="form-section-title">
                <i class="fas fa-info-circle"></i>
                Informações Básicas
            </h3>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="codigo" class="required">
                        <i class="fas fa-barcode"></i>
                        Código do Produto
                    </label>
                    <input type="text" 
                           id="codigo" 
                           name="codigo" 
                           class="form-control" 
                           placeholder="Ex: PROD001"
                           value="<?php echo isset($_POST['codigo']) ? htmlspecialchars($_POST['codigo']) : ''; ?>"
                           required>
                </div>

                <div class="form-group">
                    <label for="categoria">
                        <i class="fas fa-tags"></i>
                        Categoria
                    </label>
                    <select id="categoria" name="categoria" class="form-control">
                        <option value="">Selecione uma categoria</option>
                        <?php
                        try {
                            $stmt = $pdo->query("SELECT id, nome FROM categorias WHERE status = 'ativo' ORDER BY nome ASC");
                            $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach ($categorias as $categoria) {
                                $selected = (isset($_POST['categoria']) && $_POST['categoria'] == $categoria['id']) ? 'selected' : '';
                                echo "<option value='" . $categoria['id'] . "' " . $selected . ">" . 
                                     htmlspecialchars($categoria['nome']) . "</option>";
                            }
                        } catch (PDOException $e) {
                            error_log("Erro ao buscar categorias: " . $e->getMessage());
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="nome" class="required">
                    <i class="fas fa-tag"></i>
                    Nome do Produto
                </label>
                <input type="text" 
                       id="nome" 
                       name="nome" 
                       class="form-control" 
                       placeholder="Digite o nome completo do produto"
                       value="<?php echo isset($_POST['nome']) ? htmlspecialchars($_POST['nome']) : ''; ?>"
                       required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="und" class="required">
                        <i class="fas fa-ruler"></i>
                        Unidade de Medida
                    </label>
                    <select id="und" name="und" class="form-control" required>
                        <option value="">Selecione a unidade</option>
                        <option value="UN" <?php echo (isset($_POST['und']) && $_POST['und'] == 'UN') ? 'selected' : ''; ?>>UN - Unidade</option>
                        <option value="CX" <?php echo (isset($_POST['und']) && $_POST['und'] == 'CX') ? 'selected' : ''; ?>>CX - Caixa</option>
                        <option value="PC" <?php echo (isset($_POST['und']) && $_POST['und'] == 'PC') ? 'selected' : ''; ?>>PC - Pacote</option>
                        <option value="KG" <?php echo (isset($_POST['und']) && $_POST['und'] == 'KG') ? 'selected' : ''; ?>>KG - Quilograma</option>
                        <option value="G" <?php echo (isset($_POST['und']) && $_POST['und'] == 'G') ? 'selected' : ''; ?>>G - Grama</option>
                        <option value="L" <?php echo (isset($_POST['und']) && $_POST['und'] == 'L') ? 'selected' : ''; ?>>L - Litro</option>
                        <option value="ML" <?php echo (isset($_POST['und']) && $_POST['und'] == 'ML') ? 'selected' : ''; ?>>ML - Mililitro</option>
                        <option value="M" <?php echo (isset($_POST['und']) && $_POST['und'] == 'M') ? 'selected' : ''; ?>>M - Metro</option>
                        <option value="M2" <?php echo (isset($_POST['und']) && $_POST['und'] == 'M2') ? 'selected' : ''; ?>>M² - Metro Quadrado</option>
                        <option value="M3" <?php echo (isset($_POST['und']) && $_POST['und'] == 'M3') ? 'selected' : ''; ?>>M³ - Metro Cúbico</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="fornecedor">
                        <i class="fas fa-truck"></i>
                        Fornecedor Principal
                    </label>
                    <select name="fornecedor" id="fornecedor" class="form-control">
                        <option value="">Selecione um Fornecedor</option>
                        <?php
                        try {
                            $sql = "SELECT id, nome FROM fornecedores ORDER BY nome ASC";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute();
                            $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            foreach ($fornecedores as $fornecedor) {
                                $selected = (isset($_POST['fornecedor']) && $_POST['fornecedor'] == $fornecedor['id']) ? 'selected' : '';
                                echo "<option value='" . $fornecedor['id'] . "' $selected>" . htmlspecialchars($fornecedor['nome']) . "</option>";
                            }
                        } catch (PDOException $e) {
                            error_log("Erro ao buscar fornecedores: " . $e->getMessage());
                        }
                        ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Seção: Controle de Estoque -->
        <div class="form-section estoque-section">
            <h3 class="form-section-title">
                <i class="fas fa-warehouse"></i>
                Controle de Estoque
            </h3>
            
            <div class="checkbox-wrapper">
                <input type="checkbox" 
                       id="controla_estoque" 
                       name="controla_estoque" 
                       <?php echo (isset($_POST['controla_estoque']) || !isset($_POST['codigo'])) ? 'checked' : ''; ?>>
                <label for="controla_estoque">
                    <i class="fas fa-check-circle"></i>
                    Este produto controla estoque
                </label>
            </div>

            <div class="form-row" id="estoque_fields">
                <div class="form-group">
                    <label for="estoque_inicial">
                        <i class="fas fa-box"></i>
                        Estoque Inicial
                    </label>
                    <input type="number" 
                           id="estoque_inicial" 
                           name="estoque_inicial" 
                           class="form-control" 
                           placeholder="0"
                           min="0"
                           step="0.01"
                           value="<?php echo isset($_POST['estoque_inicial']) ? htmlspecialchars($_POST['estoque_inicial']) : '0'; ?>">
                </div>
                
                <div class="form-group">
                    <label for="estoque_minimo">
                        <i class="fas fa-exclamation-triangle"></i>
                        Estoque Mínimo
                    </label>
                    <input type="number" 
                           id="estoque_minimo" 
                           name="estoque_minimo" 
                           class="form-control" 
                           placeholder="0"
                           min="0"
                           step="0.01"
                           value="<?php echo isset($_POST['estoque_minimo']) ? htmlspecialchars($_POST['estoque_minimo']) : '0'; ?>">
                </div>
            </div>

            <div class="estoque-info">
                <h4>
                    <i class="fas fa-info-circle"></i>
                    Informações sobre Controle de Estoque
                </h4>
                <ul style="margin-left: 1.5rem; line-height: 1.8;">
                    <li><strong>Estoque Inicial:</strong> Quantidade atual do produto em estoque</li>
                    <li><strong>Estoque Mínimo:</strong> Quando atingir esta quantidade, será exibido um alerta</li>
                    <li><strong>Controle Automático:</strong> O sistema atualizará automaticamente o estoque em vendas e compras</li>
                </ul>
            </div>

            <div class="estoque-alert">
                <i class="fas fa-lightbulb"></i>
                <span><strong>Dica:</strong> Marque "Controla estoque" apenas para produtos físicos. Serviços geralmente não precisam de controle de estoque.</span>
            </div>
        </div>

        <!-- Seção: Preços -->
        <div class="form-section">
            <h3 class="form-section-title">
                <i class="fas fa-dollar-sign"></i>
                Preços e Valores
            </h3>
            
            <div class="form-group">
                <label for="preco_unitario" class="required">
                    <i class="fas fa-money-check-alt"></i>
                    Preço Unitário Base
                </label>
                <div class="price-input-wrapper">
                    <input type="number" 
                           id="preco_unitario" 
                           name="preco_unitario" 
                           class="form-control price-input" 
                           step="0.01" 
                           min="0.01" 
                           placeholder="0.00"
                           value="<?php echo isset($_POST['preco_unitario']) ? htmlspecialchars($_POST['preco_unitario']) : ''; ?>"
                           oninput="calcularImpostos()"
                           required>
                </div>
            </div>

            <!-- Campos de impostos (mantidos do código original) -->
            <div class="impostos-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div class="form-group">
                    <label for="margem_lucro">Margem Lucro (%)</label>
                    <input type="number" id="margem_lucro" name="margem_lucro" class="form-control" 
                           step="0.01" min="0" placeholder="20.00"
                           value="<?php echo isset($_POST['margem_lucro']) ? htmlspecialchars($_POST['margem_lucro']) : '20.00'; ?>"
                           oninput="calcularImpostos()">
                </div>
                
                <div class="form-group">
                    <label for="icms">ICMS (%)</label>
                    <input type="number" id="icms" name="icms" class="form-control" 
                           step="0.01" min="0" max="100" placeholder="18.00"
                           value="<?php echo isset($_POST['icms']) ? htmlspecialchars($_POST['icms']) : '18.00'; ?>"
                           oninput="calcularImpostos()">
                </div>

                <div class="form-group">
                    <label for="irpj">IRPJ (%)</label>
                    <input type="number" id="irpj" name="irpj" class="form-control" 
                           step="0.01" min="0" max="100" placeholder="15.00"
                           value="<?php echo isset($_POST['irpj']) ? htmlspecialchars($_POST['irpj']) : '15.00'; ?>"
                           oninput="calcularImpostos()">
                </div>

                <div class="form-group">
                    <label for="cofins">COFINS (%)</label>
                    <input type="number" id="cofins" name="cofins" class="form-control" 
                           step="0.01" min="0" max="100" placeholder="7.60"
                           value="<?php echo isset($_POST['cofins']) ? htmlspecialchars($_POST['cofins']) : '7.60'; ?>"
                           oninput="calcularImpostos()">
                </div>

                <div class="form-group">
                    <label for="csll">CSLL (%)</label>
                    <input type="number" id="csll" name="csll" class="form-control" 
                           step="0.01" min="0" max="100" placeholder="9.00"
                           value="<?php echo isset($_POST['csll']) ? htmlspecialchars($_POST['csll']) : '9.00'; ?>"
                           oninput="calcularImpostos()">
                </div>

                <div class="form-group">
                    <label for="pis_pasep">PIS/PASEP (%)</label>
                    <input type="number" id="pis_pasep" name="pis_pasep" class="form-control" 
                           step="0.01" min="0" max="100" placeholder="1.65"
                           value="<?php echo isset($_POST['pis_pasep']) ? htmlspecialchars($_POST['pis_pasep']) : '1.65'; ?>"
                           oninput="calcularImpostos()">
                </div>

                <div class="form-group">
                    <label for="ipi">IPI (%)</label>
                    <input type="number" id="ipi" name="ipi" class="form-control" 
                           step="0.01" min="0" max="100" placeholder="0.00"
                           value="<?php echo isset($_POST['ipi']) ? htmlspecialchars($_POST['ipi']) : '0.00'; ?>"
                           oninput="calcularImpostos()">
                </div>
            </div>

            <!-- Resumo Financeiro -->
            <div class="form-section resumo-financeiro">
                <h4 class="form-section-title">
                    <i class="fas fa-calculator"></i>
                    Resumo Financeiro (Auto-calculado)
                </h4>
                
                <div class="resumo-grid">
                    <div class="resumo-item">
                        <div class="label">Valor Base</div>
                        <div class="valor" id="valorBase">R$ 0,00</div>
                    </div>

                    <div class="resumo-item">
                        <div class="label">Total de Impostos</div>
                        <div class="valor" id="totalImpostos">R$ 0,00</div>
                    </div>

                    <div class="resumo-item">
                        <div class="label">Custo Total</div>
                        <div class="valor" id="custoTotal">R$ 0,00</div>
                    </div>

                    <div class="resumo-item">
                        <div class="label">Preço de Venda</div>
                        <div class="valor" id="precoVenda">R$ 0,00</div>
                    </div>
                </div>

                <!-- Campos ocultos para envio -->
                <input type="hidden" id="total_impostos_valor" name="total_impostos_valor">
                <input type="hidden" id="custo_total_valor" name="custo_total_valor">
                <input type="hidden" id="preco_venda_valor" name="preco_venda_valor">
            </div>
        </div>

        <!-- Seção: Observações -->
        <div class="form-section">
            <h3 class="form-section-title">
                <i class="fas fa-comment-alt"></i>
                Observações
            </h3>
            
            <div class="form-group">
                <label for="observacao">
                    <i class="fas fa-sticky-note"></i>
                    Observações do Produto
                </label>
                <textarea id="observacao" 
                          name="observacao" 
                          class="form-control" 
                          placeholder="Informações adicionais sobre o produto..."
                          rows="4"><?php echo isset($_POST['observacao']) ? htmlspecialchars($_POST['observacao']) : ''; ?></textarea>
            </div>
        </div>

        <div class="btn-container">
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-save"></i>
                Cadastrar Produto
            </button>
            <button type="button" class="btn btn-secondary" onclick="limparFormulario()">
                <i class="fas fa-undo"></i>
                Limpar Campos
            </button>
        </div>
    </form>
</div>

<!-- Modal de sucesso -->
<div id="successModal" class="modal" style="display: none;">
    <div class="modal-content" style="background: white; margin: 15% auto; padding: 20px; border-radius: 10px; width: 80%; max-width: 500px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
        <div class="modal-header" style="background: var(--success-color); color: white; padding: 1rem; border-radius: 10px 10px 0 0; margin: -20px -20px 20px -20px;">
            <h3 style="margin: 0; color: white;"><i class="fas fa-check-circle"></i> Produto Cadastrado!</h3>
        </div>
        <div class="modal-body">
            <p>O produto foi cadastrado com sucesso no sistema.</p>
            <div id="resumoModal"></div>
            <p>O que deseja fazer agora?</p>
            <div style="display: flex; gap: 1rem; justify-content: center; margin-top: 1.5rem;">
                <button class="btn btn-primary" onclick="goToConsulta()">
                    <i class="fas fa-search"></i> Ver Produtos
                </button>
                <button class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-plus"></i> Cadastrar Outro
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Variáveis globais
let produtoData = {};

// Função para controlar visibilidade dos campos de estoque
function toggleEstoqueFields() {
    const controlaEstoque = document.getElementById('controla_estoque').checked;
    const estoqueFields = document.getElementById('estoque_fields');
    const estoqueInputs = estoqueFields.querySelectorAll('input');
    
    if (controlaEstoque) {
        estoqueFields.style.display = 'grid';
        estoqueInputs.forEach(input => {
            input.disabled = false;
        });
    } else {
        estoqueFields.style.display = 'none';
        estoqueInputs.forEach(input => {
            input.disabled = true;
            input.value = '0';
        });
    }
}

// Função para calcular impostos e custos
function calcularImpostos() {
    const precoBase = parseFloat(document.getElementById('preco_unitario').value) || 0;
    
    if (precoBase <= 0) {
        limparCalculos();
        return;
    }

    // Obtém as alíquotas dos impostos
    const icms = parseFloat(document.getElementById('icms').value) || 0;
    const irpj = parseFloat(document.getElementById('irpj').value) || 0;
    const cofins = parseFloat(document.getElementById('cofins').value) || 0;
    const csll = parseFloat(document.getElementById('csll').value) || 0;
    const pisPasep = parseFloat(document.getElementById('pis_pasep').value) || 0;
    const ipi = parseFloat(document.getElementById('ipi').value) || 0;
    const margemLucro = parseFloat(document.getElementById('margem_lucro').value) || 0;

    // Calcula os valores dos impostos
    const valorIcms = (precoBase * icms) / 100;
    const valorIrpj = (precoBase * irpj) / 100;
    const valorCofins = (precoBase * cofins) / 100;
    const valorCsll = (precoBase * csll) / 100;
    const valorPisPasep = (precoBase * pisPasep) / 100;
    const valorIpi = (precoBase * ipi) / 100;

    // Total de impostos
    const totalImpostos = valorIcms + valorIrpj + valorCofins + valorCsll + valorPisPasep + valorIpi;

    // Custo total (preço base + impostos)
    const custoTotal = precoBase + totalImpostos;

    // Preço de venda com margem de lucro
    const precoVenda = custoTotal * (1 + (margemLucro / 100));

    // Atualiza os campos de exibição
    document.getElementById('valorBase').textContent = formatarMoeda(precoBase);
    document.getElementById('totalImpostos').textContent = formatarMoeda(totalImpostos);
    document.getElementById('custoTotal').textContent = formatarMoeda(custoTotal);
    document.getElementById('precoVenda').textContent = formatarMoeda(precoVenda);

    // Atualiza campos ocultos
    document.getElementById('total_impostos_valor').value = totalImpostos.toFixed(2);
    document.getElementById('custo_total_valor').value = custoTotal.toFixed(2);
    document.getElementById('preco_venda_valor').value = precoVenda.toFixed(2);

    // Armazena dados para uso posterior
    produtoData.impostos = {
        icms: { aliquota: icms, valor: valorIcms },
        irpj: { aliquota: irpj, valor: valorIrpj },
        cofins: { aliquota: cofins, valor: valorCofins },
        csll: { aliquota: csll, valor: valorCsll },
        pisPasep: { aliquota: pisPasep, valor: valorPisPasep },
        ipi: { aliquota: ipi, valor: valorIpi }
    };

    produtoData.resumo = {
        precoBase: precoBase,
        totalImpostos: totalImpostos,
        custoTotal: custoTotal,
        margemLucro: margemLucro,
        precoVenda: precoVenda
    };
}

// Função para limpar cálculos
function limparCalculos() {
    document.getElementById('valorBase').textContent = 'R$ 0,00';
    document.getElementById('totalImpostos').textContent = 'R$ 0,00';
    document.getElementById('custoTotal').textContent = 'R$ 0,00';
    document.getElementById('precoVenda').textContent = 'R$ 0,00';
    
    document.getElementById('total_impostos_valor').value = '';
    document.getElementById('custo_total_valor').value = '';
    document.getElementById('preco_venda_valor').value = '';
}

// Função para formatar moeda
function formatarMoeda(valor) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    }).format(valor);
}

// Função para validar formulário
function validarFormulario(event) {
    event.preventDefault();
    
    const codigo = document.getElementById('codigo').value.trim();
    const nome = document.getElementById('nome').value.trim();
    const und = document.getElementById('und').value;
    const preco = parseFloat(document.getElementById('preco_unitario').value);
    const controlaEstoque = document.getElementById('controla_estoque').checked;
    const estoqueInicial = parseFloat(document.getElementById('estoque_inicial').value) || 0;
    const estoqueMinimo = parseFloat(document.getElementById('estoque_minimo').value) || 0;
    
    if (!codigo) {
        showToast('O código do produto é obrigatório!', 'error');
        document.getElementById('codigo').focus();
        return false;
    }
    
    if (!nome) {
        showToast('O nome do produto é obrigatório!', 'error');
        document.getElementById('nome').focus();
        return false;
    }
    
    if (!und) {
        showToast('Selecione uma unidade de medida!', 'error');
        document.getElementById('und').focus();
        return false;
    }
    
    if (!preco || preco <= 0) {
        showToast('O preço deve ser maior que zero!', 'error');
        document.getElementById('preco_unitario').focus();
        return false;
    }

    // Validações específicas de estoque
    if (controlaEstoque) {
        if (estoqueInicial < 0) {
            showToast('O estoque inicial não pode ser negativo!', 'error');
            document.getElementById('estoque_inicial').focus();
            return false;
        }
        
        if (estoqueMinimo < 0) {
            showToast('O estoque mínimo não pode ser negativo!', 'error');
            document.getElementById('estoque_minimo').focus();
            return false;
        }
        
        if (estoqueMinimo > estoqueInicial && estoqueInicial > 0) {
            if (!confirm('O estoque mínimo é maior que o estoque inicial. Isso gerará um alerta de estoque baixo imediatamente. Deseja continuar?')) {
                return false;
            }
        }
    }

    // Verifica se os cálculos foram feitos
    const custoTotal = parseFloat(document.getElementById('custo_total_valor').value);
    if (!custoTotal || custoTotal <= 0) {
        showToast('Erro nos cálculos. Verifique o preço base!', 'error');
        return false;
    }
    
    // Mostra loading no botão
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="loading-spinner"></span> Cadastrando...';
    
    // Submete o formulário real
    document.querySelector('form').submit();
    
    return true;
}

// Função para limpar formulário
function limparFormulario() {
    if (confirm('Deseja realmente limpar todos os campos?')) {
        document.querySelector('form').reset();
        limparCalculos();
        
        // Restaura valores padrão
        document.getElementById('icms').value = '18.00';
        document.getElementById('irpj').value = '15.00';
        document.getElementById('cofins').value = '7.60';
        document.getElementById('csll').value = '9.00';
        document.getElementById('pis_pasep').value = '1.65';
        document.getElementById('ipi').value = '0.00';
        document.getElementById('margem_lucro').value = '20.00';
        document.getElementById('estoque_inicial').value = '0';
        document.getElementById('estoque_minimo').value = '0';
        document.getElementById('controla_estoque').checked = true;
        
        // Atualiza visibilidade dos campos
        toggleEstoqueFields();
        
        showToast('Formulário limpo!', 'info');
        document.getElementById('codigo').focus();
    }
}

// Função para ir para consulta
function goToConsulta() {
    window.location.href = 'consulta_produto.php?success=' + encodeURIComponent('Produto cadastrado com sucesso!');
}

// Função para fechar modal
function closeModal() {
    document.getElementById('successModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    limparFormulario();
}

// Função para mostrar notificações toast
function showToast(message, type = 'info') {
    const existingToast = document.querySelector('.toast');
    if (existingToast) {
        existingToast.remove();
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const icons = {
        success: 'check-circle',
        error: 'exclamation-circle',
        warning: 'exclamation-triangle',
        info: 'info-circle'
    };
    
    toast.innerHTML = `
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-${icons[type] || 'info-circle'}"></i>
            <span>${message}</span>
        </div>
    `;

    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? 'var(--success-color)' : type === 'error' ? 'var(--danger-color)' : type === 'warning' ? 'var(--warning-color)' : 'var(--info-color)'};
        color: ${type === 'warning' ? '#333' : 'white'};
        padding: 1rem 1.5rem;
        border-radius: var(--radius-sm);
        box-shadow: var(--shadow);
        z-index: 1000;
        animation: slideInRight 0.3s ease;
        max-width: 400px;
    `;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// Event listeners principais
document.addEventListener('DOMContentLoaded', function() {
    console.log('Sistema de Cadastro de Produtos com Estoque carregado!');
    
    // Configura evento para controle de estoque
    document.getElementById('controla_estoque').addEventListener('change', toggleEstoqueFields);
    
    // Inicializa visibilidade dos campos
    toggleEstoqueFields();
    
    // Event listener para formulário
    const productForm = document.querySelector('form');
    if (productForm) {
        productForm.addEventListener('submit', validarFormulario);
    }
    
    // Event listener para ESC fechar modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('successModal');
            if (modal.style.display === 'block') {
                closeModal();
            }
        }
    });
    
    // Adiciona comandos de teclado
    document.addEventListener('keydown', function(e) {
        // Ctrl+S para salvar
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            document.querySelector('form').requestSubmit();
        }
        
        // Ctrl+L para limpar
        if ((e.ctrlKey || e.metaKey) && e.key === 'l') {
            e.preventDefault();
            limparFormulario();
        }
    });
    
    // Foca no primeiro campo
    document.getElementById('codigo').focus();
    
    // Calcula impostos inicial
    calcularImpostos();
    
    // Validação em tempo real para estoque
    document.getElementById('estoque_inicial').addEventListener('input', function() {
        const valor = parseFloat(this.value) || 0;
        if (valor < 0) {
            this.setCustomValidity('O estoque inicial não pode ser negativo');
        } else {
            this.setCustomValidity('');
        }
    });
    
    document.getElementById('estoque_minimo').addEventListener('input', function() {
        const valor = parseFloat(this.value) || 0;
        if (valor < 0) {
            this.setCustomValidity('O estoque mínimo não pode ser negativo');
        } else {
            this.setCustomValidity('');
        }
    });
});

// Mostra modal se sucesso
window.onload = function() {
    <?php if ($success): ?>
        // Prepara resumo para o modal
        const resumo = produtoData.resumo;
        if (resumo) {
            document.getElementById('resumoModal').innerHTML = `
                <div style="background: var(--light-gray); padding: 1rem; border-radius: 8px; margin: 1rem 0; text-align: left;">
                    <strong>Resumo do Produto:</strong><br>
                    <small>Preço Base: ${formatarMoeda(resumo.precoBase || 0)}</small><br>
                    <small>Total Impostos: ${formatarMoeda(resumo.totalImpostos || 0)}</small><br>
                    <small>Custo Total: ${formatarMoeda(resumo.custoTotal || 0)}</small><br>
                    <small>Preço Venda: ${formatarMoeda(resumo.precoVenda || 0)}</small><br>
                    <small>Estoque Inicial: ${document.getElementById('estoque_inicial').value || 0}</small><br>
                    <small>Controla Estoque: ${document.getElementById('controla_estoque').checked ? 'Sim' : 'Não'}</small>
                </div>
            `;
        }
        
        document.getElementById('successModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    <?php endif; ?>
}

// CSS para animações
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
    
    .loading-spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255,255,255,.3);
        border-radius: 50%;
        border-top-color: white;
        animation: spin 1s ease-in-out infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    .modal {
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.6);
        backdrop-filter: blur(5px);
    }
    
    .badge-new {
        background: var(--warning-color);
        color: var(--dark-gray);
        padding: 0.25rem 0.5rem;
        border-radius: var(--radius-sm);
        font-size: 0.75rem;
        font-weight: 600;
        margin-left: 0.5rem;
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }
`;
document.head.appendChild(style);
</script>

<?php
// Finaliza a página
if (function_exists('renderFooter')) {
    renderFooter();
}
if (function_exists('renderScripts')) {
    renderScripts();
}
?>

</body>
</html>