<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Incluir arquivos necessários
include('db.php');
include('permissions.php');
include('includes/audit.php');

$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('compras', 'create');
logUserAction('READ', 'compras_cadastro');

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = false;

// Buscar fornecedores
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

// Função para converter data brasileira para formato MySQL
function converterDataBrasil($data) {
    if (empty($data)) return null;
    
    // Se já está no formato Y-m-d, retorna como está
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        return $data;
    }
    
    // Se está no formato d/m/Y, converte
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $data)) {
        $partes = explode('/', $data);
        return $partes[2] . '-' . $partes[1] . '-' . $partes[0];
    }
    
    return null;
}

// Função para converter data MySQL para formato brasileiro
function converterDataMysql($data) {
    if (empty($data) || $data === '0000-00-00') return '';
    
    $timestamp = strtotime($data);
    return $timestamp ? date('d/m/Y', $timestamp) : '';
}

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Recebe os dados do formulário
        $fornecedor_id = trim($_POST['fornecedor']);
        $numero_nf = trim($_POST['numero_nf']);
        $frete = str_replace(',', '.', trim($_POST['frete']));
        $link_pagamento = trim($_POST['link_pagamento']);
        $numero_empenho = trim($_POST['numero_empenho']);
        $observacao = trim($_POST['observacao']) ?? null;
        $data = trim($_POST['data']);
        $data_pagamento_compra = converterDataBrasil(trim($_POST['data_pagamento_compra'] ?? ''));
        $data_pagamento_frete = converterDataBrasil(trim($_POST['data_pagamento_frete'] ?? ''));
        $valor_total_compra = str_replace(',', '.', trim($_POST['valor_total_compra']));
        
        // Validações básicas
        if (empty($fornecedor_id) || empty($numero_nf) || empty($data)) {
            throw new Exception("Preencha todos os campos obrigatórios.");
        }

        // Validar se há produtos
        if (!isset($_POST['produto_id']) || !is_array($_POST['produto_id']) || empty(array_filter($_POST['produto_id']))) {
            throw new Exception("É necessário adicionar pelo menos um produto.");
        }

        // Buscar o nome do fornecedor pelo ID
        $stmt_fornecedor = $pdo->prepare("SELECT nome FROM fornecedores WHERE id = :id");
        $stmt_fornecedor->bindParam(':id', $fornecedor_id, PDO::PARAM_INT);
        $stmt_fornecedor->execute();
        $fornecedor_data = $stmt_fornecedor->fetch(PDO::FETCH_ASSOC);
        
        if (!$fornecedor_data) {
            throw new Exception("Fornecedor não encontrado.");
        }
        
        $fornecedor_nome = $fornecedor_data['nome'];
        
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

        // Processar produtos
        $produto_ids = array_filter($_POST['produto_id']);
        $quantidades = $_POST['produto_quantidade'];
        $valores_unitarios = $_POST['produto_valor_unitario'];
        $valores_totais = $_POST['produto_valor_total'];

        if (empty($produto_ids)) {
            throw new Exception("É necessário selecionar pelo menos um produto.");
        }

        // Obter dados do primeiro produto para a tabela compras (compatibilidade)
        $primeiro_produto_id = $produto_ids[0];
        $primeiro_produto_nome = "";
        $primeira_quantidade = (int)$quantidades[0];
        $primeiro_valor_unitario = str_replace(',', '.', $valores_unitarios[0]);
        
        // Buscar nome do primeiro produto
        $stmt_produto = $pdo->prepare("SELECT nome FROM produtos WHERE id = :id");
        $stmt_produto->bindParam(':id', $primeiro_produto_id, PDO::PARAM_INT);
        $stmt_produto->execute();
        $produto = $stmt_produto->fetch(PDO::FETCH_ASSOC);
        
        if (!$produto) {
            throw new Exception("Produto não encontrado.");
        }
        
        $primeiro_produto_nome = $produto['nome'];

        // Verificar se as colunas de data de pagamento existem
        try {
            $check_columns = $pdo->query("SHOW COLUMNS FROM compras LIKE 'data_pagamento_%'");
            $columns_exist = $check_columns->rowCount() >= 2;
        } catch (Exception $e) {
            $columns_exist = false;
        }

        // SQL INSERT com ou sem as novas colunas dependendo da estrutura
        if ($columns_exist) {
            $sql = "INSERT INTO compras 
                    (fornecedor, numero_nf, produto, quantidade, valor_unitario, valor_total, frete, link_pagamento, numero_empenho, observacao, data, data_pagamento_compra, data_pagamento_frete, comprovante_pagamento) 
                    VALUES 
                    (:fornecedor, :numero_nf, :produto, :quantidade, :valor_unitario, :valor_total, :frete, :link_pagamento, :numero_empenho, :observacao, :data, :data_pagamento_compra, :data_pagamento_frete, :comprovante_pagamento)";
        } else {
            $sql = "INSERT INTO compras 
                    (fornecedor, numero_nf, produto, quantidade, valor_unitario, valor_total, frete, link_pagamento, numero_empenho, observacao, data, comprovante_pagamento) 
                    VALUES 
                    (:fornecedor, :numero_nf, :produto, :quantidade, :valor_unitario, :valor_total, :frete, :link_pagamento, :numero_empenho, :observacao, :data, :comprovante_pagamento)";
        }
        
        $stmt = $pdo->prepare($sql);
        
        // Bind dos parâmetros básicos
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
        
        // Bind das novas colunas se existirem
        if ($columns_exist) {
            $stmt->bindParam(':data_pagamento_compra', $data_pagamento_compra, PDO::PARAM_STR);
            $stmt->bindParam(':data_pagamento_frete', $data_pagamento_frete, PDO::PARAM_STR);
        }

        // Executa a consulta
        if (!$stmt->execute()) {
            throw new Exception("Erro ao cadastrar compra na tabela principal.");
        }
        
        $compra_id = $pdo->lastInsertId();
        
        // Verificar se a tabela produto_compra existe
        try {
            $pdo->query("SELECT 1 FROM produto_compra LIMIT 1");
            $tabela_existe = true;
        } catch (Exception $e) {
            $tabela_existe = false;
        }
        
        // Criar a tabela produto_compra se não existir
        if (!$tabela_existe) {
            $sql_criar_tabela = "CREATE TABLE produto_compra (
                id INT AUTO_INCREMENT PRIMARY KEY,
                compra_id INT NOT NULL,
                produto_id INT NOT NULL,
                quantidade INT NOT NULL,
                valor_unitario DECIMAL(10,2) NOT NULL,
                valor_total DECIMAL(10,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
                FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE RESTRICT
            )";
            
            if (!$pdo->exec($sql_criar_tabela)) {
                throw new Exception("Erro ao criar tabela produto_compra.");
            }
        }
        
        // Inserir produtos na tabela produto_compra
        $sql_produto = "INSERT INTO produto_compra (compra_id, produto_id, quantidade, valor_unitario, valor_total) 
                        VALUES (:compra_id, :produto_id, :quantidade, :valor_unitario, :valor_total)";
        $stmt_produto = $pdo->prepare($sql_produto);
        
        $produtos_inseridos = 0;
        
        foreach ($produto_ids as $index => $produto_id) {
            if (empty($produto_id)) continue;
            
            // Validar se o produto existe
            $stmt_check = $pdo->prepare("SELECT id FROM produtos WHERE id = :id");
            $stmt_check->bindParam(':id', $produto_id, PDO::PARAM_INT);
            $stmt_check->execute();
            
            if (!$stmt_check->fetch()) {
                throw new Exception("Produto com ID {$produto_id} não encontrado.");
            }
            
            // Processar valores
            $quantidade_produto = (int)$quantidades[$index];
            $valor_unitario_produto = str_replace(',', '.', $valores_unitarios[$index]);
            $valor_total_produto = str_replace(',', '.', $valores_totais[$index]);
            
            // Validações
            if ($quantidade_produto <= 0) {
                throw new Exception("Quantidade deve ser maior que zero para todos os produtos.");
            }
            
            if ($valor_unitario_produto <= 0) {
                throw new Exception("Valor unitário deve ser maior que zero para todos os produtos.");
            }
            
            // Inserir produto
            $stmt_produto->bindParam(':compra_id', $compra_id, PDO::PARAM_INT);
            $stmt_produto->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
            $stmt_produto->bindParam(':quantidade', $quantidade_produto, PDO::PARAM_INT);
            $stmt_produto->bindParam(':valor_unitario', $valor_unitario_produto, PDO::PARAM_STR);
            $stmt_produto->bindParam(':valor_total', $valor_total_produto, PDO::PARAM_STR);
            
            if (!$stmt_produto->execute()) {
                throw new Exception("Erro ao inserir produto na compra.");
            }
            
            $produtos_inseridos++;
        }
        
        if ($produtos_inseridos == 0) {
            throw new Exception("Nenhum produto foi inserido na compra.");
        }
        
        $pdo->commit();

        // Registra auditoria
        logUserAction('CREATE', 'compras', $compra_id, [
            'fornecedor' => $fornecedor_nome,
            'numero_nf' => $numero_nf,
            'data' => $data,
            'data_pagamento_compra' => $data_pagamento_compra,
            'data_pagamento_frete' => $data_pagamento_frete,
            'valor_total_compra' => $valor_total_compra,
            'produtos_count' => $produtos_inseridos,
            'upload' => $comprovante_pagamento ? 'sim' : 'não'
        ]);

        $success = true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Erro ao cadastrar compra: " . $e->getMessage();
        
        // Log do erro para debug
        error_log("Erro no cadastro de compras: " . $e->getMessage());
        error_log("Dados POST: " . print_r($_POST, true));
    }
}

// Incluir header template
include('includes/header_template.php');
renderHeader("Cadastro de Compras - LicitaSis", "compras");
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Compras - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #2D893E;
            --primary-light: #9DCEAC;
            --primary-dark: #1e6e2d;
            --secondary-color: #00bfae;
            --secondary-dark: #009d8f;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
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
            transition: var(--transition);
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .container:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

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

        h2 i {
            color: var(--secondary-color);
            font-size: 1.8rem;
        }

        h3 {
            color: var(--primary-color);
            margin: 2rem 0 1.5rem;
            font-size: 1.3rem;
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        h3 i {
            color: var(--secondary-color);
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
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 0;
            position: relative;
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

        .required::after {
            content: ' *';
            color: var(--danger-color);
            font-weight: bold;
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

        .form-control[readonly] {
            background: var(--light-gray);
            color: var(--medium-gray);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        /* Data sections */
        .data-section {
            background: linear-gradient(135deg, var(--light-gray) 0%, #f8f9fa 100%);
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            margin-top: 1.5rem;
        }

        .data-section h4 {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .data-section h4 i {
            color: var(--secondary-color);
        }

        /* Upload de arquivo */
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
            border-radius: var(--radius-sm);
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
            margin-top: 2rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            background: linear-gradient(135deg, var(--light-gray) 0%, #f8f9fa 100%);
        }

        .produto-item {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 1.5rem;
            position: relative;
            transition: var(--transition);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .produto-item:hover {
            box-shadow: var(--shadow);
            border-color: var(--secondary-color);
            transform: translateY(-2px);
        }

        .produto-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .produto-title {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .produto-title i {
            color: var(--secondary-color);
        }

        .remove-produto-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            padding: 0.5rem 1rem;
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
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }

        .add-produto-btn {
            background: var(--success-color);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            padding: 1rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            width: 100%;
            margin-top: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .add-produto-btn:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        /* Valor Total */
        .valor-total-section {
            background: linear-gradient(135deg, var(--light-gray) 0%, #f8f9fa 100%);
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            margin-top: 2rem;
        }

        .valor-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            font-size: 1.2rem;
            color: var(--primary-color);
        }

        .valor-total-display {
            font-weight: 700;
            font-size: 1.3rem;
            color: var(--success-color);
            background: rgba(40, 167, 69, 0.1);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
        }

        /* Botões */
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
            animation: fadeInModal 0.3s ease;
        }

        .modal-content {
            background: white;
            margin: 8% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideInUp 0.3s ease;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--success-color), #1e7e34);
            color: white;
            padding: 2rem;
            text-align: center;
            position: relative;
        }

        .modal-header h3 {
            margin: 0;
            color: white;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            border-bottom: none;
        }

        .modal-body {
            padding: 2rem;
            text-align: center;
        }

        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }

        .modal-buttons .btn-primary,
        .modal-buttons .btn-secondary {
            padding: 0.75rem 1.5rem;
            min-width: 140px;
        }

        @keyframes fadeInModal {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideInUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Estados de input */
        .form-control.success-state {
            border-color: var(--success-color);
            background: rgba(40, 167, 69, 0.05);
        }

        .form-control.error-state {
            border-color: var(--danger-color);
            background: rgba(220, 53, 69, 0.05);
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 1.5rem;
                padding: 2rem;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin: 1rem;
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.75rem;
                flex-direction: column;
                gap: 0.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .btn-container {
                flex-direction: column;
                gap: 1rem;
            }

            .btn {
                width: 100%;
            }

            .valor-total-row {
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }

            .modal-buttons {
                flex-direction: column;
            }

            .produto-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .remove-produto-btn {
                align-self: flex-end;
            }
        }

        @media (max-width: 480px) {
            .container {
                margin: 0.5rem;
                padding: 1rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .form-control {
                padding: 0.75rem;
                font-size: 0.9rem;
            }

            .produtos-section,
            .valor-total-section,
            .data-section {
                padding: 1rem;
            }

            .produto-item {
                padding: 1rem;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .modal-header,
            .modal-body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>
        <i class="fas fa-shopping-cart"></i>
        Cadastro de Compras
    </h2>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form class="form-container" action="cadastro_compras.php" method="POST" enctype="multipart/form-data" onsubmit="return validarFormulario()">
        <div class="form-row">
            <div class="form-group">
                <label for="fornecedor" class="required">
                    <i class="fas fa-truck"></i>
                    Fornecedor
                </label>
                <select id="fornecedor" name="fornecedor" class="form-control" required>
                    <option value="">Selecione um fornecedor</option>
                    <?php foreach ($fornecedores as $fornecedor): ?>
                        <option value="<?php echo $fornecedor['id']; ?>" 
                                <?php echo ($success ? '' : (isset($_POST['fornecedor']) && $_POST['fornecedor'] == $fornecedor['id'] ? 'selected' : '')); ?>>
                            <?php echo htmlspecialchars($fornecedor['nome']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="numero_nf" class="required">
                    <i class="fas fa-file-invoice"></i>
                    Número da NF
                </label>
                <input type="text" 
                       id="numero_nf" 
                       name="numero_nf" 
                       class="form-control" 
                       placeholder="Digite o número da nota fiscal"
                       value="<?php echo $success ? '' : (isset($_POST['numero_nf']) ? htmlspecialchars($_POST['numero_nf']) : ''); ?>"
                       required>
            </div>
        </div>

        <!-- Seção de Datas -->
        <div class="data-section">
            <h4><i class="fas fa-calendar-days"></i> Datas da Compra</h4>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="data" class="required">
                        <i class="fas fa-calendar-alt"></i>
                        Data da Compra
                    </label>
                    <input type="date" 
                           id="data" 
                           name="data" 
                           class="form-control" 
                           value="<?php echo $success ? '' : (isset($_POST['data']) ? htmlspecialchars($_POST['data']) : date('Y-m-d')); ?>"
                           required>
                </div>

                <div class="form-group">
                    <label for="numero_empenho">
                        <i class="fas fa-hashtag"></i>
                        Número do Empenho
                    </label>
                    <input type="text" 
                           id="numero_empenho" 
                           name="numero_empenho" 
                           class="form-control" 
                           placeholder="Número do empenho (opcional)"
                           value="<?php echo $success ? '' : (isset($_POST['numero_empenho']) ? htmlspecialchars($_POST['numero_empenho']) : ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="data_pagamento_compra">
                        <i class="fas fa-money-check-alt"></i>
                        Data de Pagamento da Compra
                    </label>
                    <input type="date" 
                           id="data_pagamento_compra" 
                           name="data_pagamento_compra" 
                           class="form-control" 
                           value="<?php echo $success ? '' : (isset($_POST['data_pagamento_compra']) ? htmlspecialchars($_POST['data_pagamento_compra']) : ''); ?>">
                    <small style="color: var(--medium-gray); font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                        <i class="fas fa-info-circle"></i> Deixe em branco se ainda não foi pago
                    </small>
                </div>

                <div class="form-group">
                    <label for="data_pagamento_frete">
                        <i class="fas fa-shipping-fast"></i>
                        Data de Pagamento do Frete
                    </label>
                    <input type="date" 
                           id="data_pagamento_frete" 
                           name="data_pagamento_frete" 
                           class="form-control" 
                           value="<?php echo $success ? '' : (isset($_POST['data_pagamento_frete']) ? htmlspecialchars($_POST['data_pagamento_frete']) : ''); ?>">
                    <small style="color: var(--medium-gray); font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                        <i class="fas fa-info-circle"></i> Deixe em branco se não houve frete ou ainda não foi pago
                    </small>
                </div>
            </div>
        </div>

        <h3><i class="fas fa-box"></i> Produtos</h3>
        <div class="produtos-section">
            <div id="produtos-container">
                <!-- Os produtos serão adicionados dinamicamente aqui -->
            </div>

            <button type="button" class="add-produto-btn" id="addProdutoBtn">
                <i class="fas fa-plus"></i>
                Adicionar Produto
            </button>
        </div>

        <!-- Valor Total da Compra -->
        <div class="valor-total-section">
            <div class="valor-total-row">
                <span><i class="fas fa-calculator"></i> Valor Total da Compra:</span>
                <span class="valor-total-display" id="valor_total_display">R$ 0,00</span>
                <input type="hidden" id="valor_total_compra" name="valor_total_compra" value="0.00">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="frete">
                    <i class="fas fa-shipping-fast"></i>
                    Frete (R$)
                </label>
                <input type="number" 
                       id="frete" 
                       name="frete" 
                       class="form-control" 
                       step="0.01" 
                       min="0" 
                       value="<?php echo $success ? '0.00' : (isset($_POST['frete']) ? htmlspecialchars($_POST['frete']) : '0.00'); ?>"
                       oninput="calcularValorTotalCompra()">
            </div>

            <div class="form-group">
                <label for="link_pagamento">
                    <i class="fas fa-link"></i>
                    Link para Pagamento
                </label>
                <input type="url" 
                       id="link_pagamento" 
                       name="link_pagamento" 
                       class="form-control" 
                       placeholder="https://..."
                       value="<?php echo $success ? '' : (isset($_POST['link_pagamento']) ? htmlspecialchars($_POST['link_pagamento']) : ''); ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="comprovante_pagamento">
                <i class="fas fa-file-upload"></i>
                Comprovante de Pagamento
            </label>
            <div class="file-input-container">
                <label for="comprovante_pagamento" class="file-input-label">
                    <i class="fas fa-upload"></i> Selecionar arquivo
                </label>
                <input type="file" 
                       id="comprovante_pagamento" 
                       name="comprovante_pagamento" 
                       accept=".jpg,.jpeg,.png,.pdf" 
                       onchange="updateFileName(this)">
                <div class="file-name" id="file-name">Nenhum arquivo selecionado</div>
            </div>
            <small style="color: var(--medium-gray); font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                Formatos aceitos: JPG, JPEG, PNG, PDF. Tamanho máximo: 5MB
            </small>
        </div>

        <div class="form-group">
            <label for="observacao">
                <i class="fas fa-comment-alt"></i>
                Observações
            </label>
            <textarea id="observacao" 
                      name="observacao" 
                      class="form-control" 
                      rows="3"
                      placeholder="Informações adicionais sobre a compra..."><?php echo $success ? '' : (isset($_POST['observacao']) ? htmlspecialchars($_POST['observacao']) : ''); ?></textarea>
        </div>

        <div class="btn-container">
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-save"></i>
                Cadastrar Compra
            </button>
            <button type="reset" class="btn btn-secondary" onclick="limparFormulario()">
                <i class="fas fa-undo"></i>
                Limpar Campos
            </button>
        </div>
    </form>
</div>

<!-- Modal de Sucesso -->
<?php if ($success): ?>
    <div id="successModal" class="modal" style="display: block;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-check-circle"></i>
                    Compra Cadastrada com Sucesso!
                </h3>
            </div>
            <div class="modal-body">
                <p>Os dados da compra foram registrados no sistema.</p>
                <p>Deseja acessar a página de consulta de compras?</p>
                <div class="modal-buttons">
                    <button class="btn btn-primary" onclick="goToConsulta()">
                        <i class="fas fa-search"></i>
                        Sim, Ver Compras
                    </button>
                    <button class="btn btn-secondary" onclick="closeModal()">
                        <i class="fas fa-plus"></i>
                        Cadastrar Outra
                    </button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let produtoCounter = 0;
const produtosDisponiveis = <?php echo json_encode($produtos); ?>;

// ===========================================
// FUNÇÕES DE FORMATAÇÃO
// ===========================================

function formatarMoeda(valor) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    }).format(valor);
}

function formatarNumero(valor) {
    return new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(valor);
}

// ===========================================
// FUNÇÕES DE DATA
// ===========================================

function formatarDataBrasil(data) {
    if (!data) return '';
    const partes = data.split('-');
    if (partes.length === 3) {
        return `${partes[2]}/${partes[1]}/${partes[0]}`;
    }
    return data;
}

function converterDataParaMySQL(data) {
    if (!data) return '';
    if (data.includes('/')) {
        const partes = data.split('/');
        if (partes.length === 3) {
            return `${partes[2]}-${partes[1]}-${partes[0]}`;
        }
    }
    return data;
}

function validarData(data) {
    if (!data) return true; // Datas de pagamento são opcionais
    
    const regex = /^\d{4}-\d{2}-\d{2}$/;
    if (!regex.test(data)) return false;
    
    const dateObj = new Date(data);
    return dateObj instanceof Date && !isNaN(dateObj);
}

// ===========================================
// GERENCIAMENTO DE PRODUTOS
// ===========================================

function adicionarProduto() {
    produtoCounter++;
    const produtosContainer = document.getElementById('produtos-container');
    
    const produtoDiv = document.createElement('div');
    produtoDiv.className = 'produto-item';
    produtoDiv.id = `produto-${produtoCounter}`;
    produtoDiv.style.opacity = "0";
    produtoDiv.style.transform = "translateY(20px)";
    
    let produtosOptions = '<option value="">Selecione o Produto</option>';
    produtosDisponiveis.forEach(produto => {
        produtosOptions += `<option value="${produto.id}" data-preco="${produto.preco_unitario}">${produto.nome}</option>`;
    });
    
    produtoDiv.innerHTML = `
        <div class="produto-header">
            <div class="produto-title">
                <i class="fas fa-box"></i>
                <span>Produto ${produtoCounter}</span>
            </div>
            <button type="button" class="remove-produto-btn" onclick="removerProduto(${produtoCounter})">
                <i class="fas fa-trash-alt"></i> Remover
            </button>
        </div>
        
        <div class="form-group">
            <label for="produto_id_${produtoCounter}" class="required">
                <i class="fas fa-tag"></i>
                Produto
            </label>
            <select id="produto_id_${produtoCounter}" name="produto_id[]" class="form-control" required onchange="atualizarProdutoInfo(${produtoCounter})">
                ${produtosOptions}
            </select>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="produto_quantidade_${produtoCounter}" class="required">
                    <i class="fas fa-sort-numeric-up"></i>
                    Quantidade
                </label>
                <input type="number" 
                       id="produto_quantidade_${produtoCounter}" 
                       name="produto_quantidade[]" 
                       class="form-control" 
                       min="1" 
                       value="1" 
                       required
                       oninput="calcularValorTotalProduto(${produtoCounter})">
            </div>
            <div class="form-group">
                <label for="produto_valor_unitario_${produtoCounter}" class="required">
                    <i class="fas fa-dollar-sign"></i>
                    Valor Unitário
                </label>
                <input type="number" 
                       id="produto_valor_unitario_${produtoCounter}" 
                       name="produto_valor_unitario[]" 
                       class="form-control" 
                       step="0.01" 
                       min="0.01"
                       value="0.00" 
                       required
                       oninput="calcularValorTotalProduto(${produtoCounter})">
            </div>
        </div>
        
        <div class="form-group">
            <label for="produto_valor_total_${produtoCounter}">
                <i class="fas fa-calculator"></i>
                Valor Total
            </label>
            <input type="text" 
                   id="produto_valor_total_${produtoCounter}" 
                   name="produto_valor_total[]" 
                   class="form-control" 
                   value="0.00" 
                   readonly>
        </div>
    `;
    
    produtosContainer.appendChild(produtoDiv);
    
    // Animação de entrada
    setTimeout(() => {
        produtoDiv.style.transition = 'all 0.3s ease';
        produtoDiv.style.opacity = "1";
        produtoDiv.style.transform = "translateY(0)";
    }, 10);

    // Foca no select do produto
    setTimeout(() => {
        document.getElementById(`produto_id_${produtoCounter}`).focus();
    }, 300);
}

function removerProduto(id) {
    const produtoDiv = document.getElementById(`produto-${id}`);
    if (produtoDiv) {
        // Verifica se é o último produto
        const totalProdutos = document.querySelectorAll('.produto-item').length;
        if (totalProdutos === 1) {
            alert('É necessário manter pelo menos um produto na compra.');
            return;
        }
        
        if (confirm('Tem certeza que deseja remover este produto?')) {
            produtoDiv.style.opacity = "0";
            produtoDiv.style.transform = "translateX(20px)";
            
            setTimeout(() => {
                produtoDiv.remove();
                calcularValorTotalCompra();
            }, 300);
        }
    }
}

function atualizarProdutoInfo(id) {
    const produtoSelect = document.getElementById(`produto_id_${id}`);
    const selectedOption = produtoSelect.options[produtoSelect.selectedIndex];
    const precoUnitario = parseFloat(selectedOption.getAttribute('data-preco')) || 0;
    
    // Preenche o valor unitário com o preço do produto selecionado
    const valorUnitarioInput = document.getElementById(`produto_valor_unitario_${id}`);
    if (valorUnitarioInput && precoUnitario > 0) {
        valorUnitarioInput.value = precoUnitario.toFixed(2);
    }
    
    // Atualiza o valor total do produto
    calcularValorTotalProduto(id);
    
    // Remove estado de erro se existir
    produtoSelect.classList.remove('error-state');
    produtoSelect.classList.add('success-state');
    setTimeout(() => {
        produtoSelect.classList.remove('success-state');
    }, 2000);
}

function calcularValorTotalProduto(id) {
    const quantidadeInput = document.getElementById(`produto_quantidade_${id}`);
    const valorUnitarioInput = document.getElementById(`produto_valor_unitario_${id}`);
    const valorTotalInput = document.getElementById(`produto_valor_total_${id}`);
    
    if (!quantidadeInput || !valorUnitarioInput || !valorTotalInput) return;
    
    const quantidade = parseFloat(quantidadeInput.value) || 0;
    const valorUnitario = parseFloat(valorUnitarioInput.value) || 0;
    const valorTotal = quantidade * valorUnitario;
    
    // Preenche o campo de valor total do produto (formato americano para o backend)
    valorTotalInput.value = valorTotal.toFixed(2);
    
    // Recalcula o valor total da compra
    calcularValorTotalCompra();
}

function calcularValorTotalCompra() {
    let valorTotal = 0;
    
    // Soma os valores totais de todos os produtos
    const valoresProdutos = document.querySelectorAll('input[name="produto_valor_total[]"]');
    valoresProdutos.forEach(input => {
        const valor = parseFloat(input.value) || 0;
        valorTotal += valor;
    });
    
    // Adiciona o valor do frete
    const freteInput = document.getElementById('frete');
    const frete = freteInput ? parseFloat(freteInput.value) || 0 : 0;
    valorTotal += frete;
    
    // Atualiza o campo hidden para envio ao backend (formato americano)
    const valorTotalInput = document.getElementById('valor_total_compra');
    if (valorTotalInput) {
        valorTotalInput.value = valorTotal.toFixed(2);
    }
    
    // Atualiza o display visual para o usuário (formato brasileiro)
    const valorTotalDisplay = document.getElementById('valor_total_display');
    if (valorTotalDisplay) {
        valorTotalDisplay.textContent = formatarMoeda(valorTotal);
    }
}

// ===========================================
// FUNÇÕES AUXILIARES
// ===========================================

function updateFileName(input) {
    const fileName = input.files[0] ? input.files[0].name : "Nenhum arquivo selecionado";
    const fileNameDiv = document.getElementById('file-name');
    if (fileNameDiv) {
        fileNameDiv.textContent = fileName;
    }
}

function validarFormulario() {
    const fornecedor = document.getElementById("fornecedor");
    const numeroNf = document.getElementById("numero_nf");
    const data = document.getElementById("data");
    const dataPagamentoCompra = document.getElementById("data_pagamento_compra");
    const dataPagamentoFrete = document.getElementById("data_pagamento_frete");
    
    // Verifica campos obrigatórios
    if (!fornecedor || !fornecedor.value) {
        alert('Selecione um fornecedor!');
        if (fornecedor) fornecedor.focus();
        return false;
    }
    
    if (!numeroNf || !numeroNf.value.trim()) {
        alert('O campo Número da NF é obrigatório!');
        if (numeroNf) numeroNf.focus();
        return false;
    }
    
    if (!data || !data.value) {
        alert('O campo Data da Compra é obrigatório!');
        if (data) data.focus();
        return false;
    }

    // Validar datas de pagamento (opcionais, mas se preenchidas devem ser válidas)
    if (dataPagamentoCompra && dataPagamentoCompra.value && !validarData(dataPagamentoCompra.value)) {
        alert('Data de Pagamento da Compra inválida!');
        dataPagamentoCompra.focus();
        return false;
    }

    if (dataPagamentoFrete && dataPagamentoFrete.value && !validarData(dataPagamentoFrete.value)) {
        alert('Data de Pagamento do Frete inválida!');
        dataPagamentoFrete.focus();
        return false;
    }

    // Validar se a data de pagamento não é anterior à data da compra
    if (data.value && dataPagamentoCompra && dataPagamentoCompra.value) {
        const dataCompra = new Date(data.value);
        const dataPagamento = new Date(dataPagamentoCompra.value);
        if (dataPagamento < dataCompra) {
            alert('A Data de Pagamento da Compra não pode ser anterior à Data da Compra!');
            dataPagamentoCompra.focus();
            return false;
        }
    }

    // Verifica se há pelo menos um produto
    const produtos = document.querySelectorAll('select[name="produto_id[]"]');
    if (produtos.length === 0) {
        alert('Adicione pelo menos um produto à compra!');
        const addBtn = document.getElementById('addProdutoBtn');
        if (addBtn) addBtn.focus();
        return false;
    }

    // Valida cada produto
    let valid = true;
    let firstError = null;
    
    produtos.forEach(function(produto, index) {
        if (!produto.value) {
            valid = false;
            produto.classList.add('error-state');
            if (!firstError) firstError = produto;
            setTimeout(() => {
                produto.classList.remove('error-state');
            }, 3000);
        }
    });

    const quantidades = document.querySelectorAll('input[name="produto_quantidade[]"]');
    quantidades.forEach(function(quantidade) {
        const valor = parseInt(quantidade.value) || 0;
        if (valor <= 0) {
            valid = false;
            quantidade.classList.add('error-state');
            if (!firstError) firstError = quantidade;
            setTimeout(() => {
                quantidade.classList.remove('error-state');
            }, 3000);
        }
    });

    const valoresUnitarios = document.querySelectorAll('input[name="produto_valor_unitario[]"]');
    valoresUnitarios.forEach(function(valor) {
        const valorNum = parseFloat(valor.value) || 0;
        if (valorNum <= 0) {
            valid = false;
            valor.classList.add('error-state');
            if (!firstError) firstError = valor;
            setTimeout(() => {
                valor.classList.remove('error-state');
            }, 3000);
        }
    });

    if (!valid) {
        alert('Por favor, corrija os campos destacados em vermelho.');
        if (firstError) firstError.focus();
        return false;
    }
    
    // Mostra loading no botão de submit
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cadastrando...';
    }
    
    return true;
}

function limparFormulario() {
    // Reset do formulário
    const form = document.querySelector('form');
    if (form) {
        form.reset();
    }
    
    // Limpa container de produtos
    const container = document.getElementById('produtos-container');
    if (container) {
        container.innerHTML = '';
    }
    
    produtoCounter = 0;
    
    // Reseta a data para hoje
    const dataInput = document.getElementById('data');
    if (dataInput) {
        dataInput.value = new Date().toISOString().split('T')[0];
    }
    
    // Limpa datas de pagamento
    const dataPagamentoCompra = document.getElementById('data_pagamento_compra');
    const dataPagamentoFrete = document.getElementById('data_pagamento_frete');
    if (dataPagamentoCompra) dataPagamentoCompra.value = '';
    if (dataPagamentoFrete) dataPagamentoFrete.value = '';
    
    // Reseta valores
    const valorTotalInput = document.getElementById('valor_total_compra');
    const valorTotalDisplay = document.getElementById('valor_total_display');
    const freteInput = document.getElementById('frete');
    const fileNameDiv = document.getElementById('file-name');
    
    if (valorTotalInput) valorTotalInput.value = '0.00';
    if (valorTotalDisplay) valorTotalDisplay.textContent = 'R$ 0,00';
    if (freteInput) freteInput.value = '0.00';
    if (fileNameDiv) fileNameDiv.textContent = 'Nenhum arquivo selecionado';
    
    // Remove classes de estado
    document.querySelectorAll('.form-control').forEach(input => {
        input.classList.remove('success-state', 'error-state');
    });
    
    // Adiciona primeiro produto
    setTimeout(() => {
        adicionarProduto();
    }, 100);
}

// ===========================================
// MODAL
// ===========================================

function goToConsulta() {
    window.location.href = 'consulta_compras.php?success=' + encodeURIComponent('Compra cadastrada com sucesso!');
}

function closeModal() {
    const modal = document.getElementById('successModal');
    if (modal) {
        modal.style.display = 'none';
    }
    document.body.style.overflow = 'auto';
    limparFormulario();
    setTimeout(() => {
        const fornecedorSelect = document.getElementById('fornecedor');
        if (fornecedorSelect) fornecedorSelect.focus();
    }, 200);
}

// ===========================================
// INICIALIZAÇÃO
// ===========================================

document.addEventListener('DOMContentLoaded', function() {
    console.log('🛒 Iniciando sistema de cadastro de compras...');
    
    // Configura data padrão
    const dataInput = document.getElementById('data');
    if (dataInput && !dataInput.value) {
        dataInput.value = new Date().toISOString().split('T')[0];
    }
    
    // Configura o botão de adicionar produto
    const addProdutoBtn = document.getElementById('addProdutoBtn');
    if (addProdutoBtn) {
        addProdutoBtn.addEventListener('click', function(e) {
            e.preventDefault();
            adicionarProduto();
        });
        
        // Adiciona o primeiro produto automaticamente
        setTimeout(() => {
            addProdutoBtn.click();
        }, 300);
    }
    
    // Event listener para o campo frete
    const freteInput = document.getElementById('frete');
    if (freteInput) {
        freteInput.addEventListener('input', calcularValorTotalCompra);
    }
    
    // Event listeners para validação de datas
    const dataPagamentoCompra = document.getElementById('data_pagamento_compra');
    const dataPagamentoFrete = document.getElementById('data_pagamento_frete');
    
    if (dataPagamentoCompra) {
        dataPagamentoCompra.addEventListener('change', function() {
            if (this.value && !validarData(this.value)) {
                alert('Data de Pagamento da Compra inválida!');
                this.focus();
                this.classList.add('error-state');
            } else {
                this.classList.remove('error-state');
                if (this.value) this.classList.add('success-state');
            }
        });
    }
    
    if (dataPagamentoFrete) {
        dataPagamentoFrete.addEventListener('change', function() {
            if (this.value && !validarData(this.value)) {
                alert('Data de Pagamento do Frete inválida!');
                this.focus();
                this.classList.add('error-state');
            } else {
                this.classList.remove('error-state');
                if (this.value) this.classList.add('success-state');
            }
        });
    }
    
    // Foca no primeiro campo
    setTimeout(() => {
        const fornecedorSelect = document.getElementById('fornecedor');
        if (fornecedorSelect) {
            fornecedorSelect.focus();
        }
    }, 500);
    
    console.log('✅ Sistema de cadastro de compras carregado com sucesso!');
    console.log('📊 Produtos disponíveis:', produtosDisponiveis.length);
});

// Event listeners para modal
window.onclick = function(event) {
    const modal = document.getElementById('successModal');
    if (event.target === modal) {
        closeModal();
    }
}

// Atalhos de teclado
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        if (validarFormulario()) {
            document.querySelector('form').submit();
        }
    }
    
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        adicionarProduto();
    }
    
    if (e.key === 'Escape') {
        const modal = document.getElementById('successModal');
        if (modal && modal.style.display === 'block') {
            closeModal();
        }
    }
});

console.log('🚀 Sistema de Cadastro de Compras LicitaSis v3.0:', {
    versao: '3.0 Datas de Pagamento',
    novosCampos: [
        '✅ Data de Pagamento da Compra',
        '✅ Data de Pagamento do Frete',
        '✅ Validação de datas',
        '✅ Compatibilidade com estrutura atual',
        '✅ Interface melhorada',
        '✅ Seção de datas organizada'
    ],
    estrutura_bd: {
        compras: 'Tabela principal com dados básicos + datas de pagamento',
        produto_compra: 'Tabela relacional para múltiplos produtos'
    },
    validacoes: [
        '✅ Datas de pagamento opcionais',
        '✅ Formato de data brasileiro suportado',
        '✅ Validação de data de pagamento >= data da compra',
        '✅ Campos bem organizados e separados'
    ]
});
</script>

<?php
// ===========================================
// FINALIZAÇÃO DA PÁGINA
// ===========================================
if (function_exists('renderFooter')) {
    renderFooter();
}
if (function_exists('renderScripts')) {
    renderScripts();
}
?>

</body>
</html>