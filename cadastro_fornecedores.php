<?php 
// ===========================================
// CADASTRO DE FORNECEDORES - LICITASIS v7.0
// Sistema Completo de Gestão de Licitações
// Versão Melhorada com Design Responsivo e Funcionalidades Avançadas
// ===========================================

session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inclusão dos arquivos necessários
include('db.php');
include('permissions.php');
include('includes/audit.php');

// Inicialização do sistema de permissões
$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('fornecedores', 'create');
logUserAction('READ', 'fornecedores_cadastro');

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = $permissionManager->isAdmin();

$error = "";
$success = false;

// Processa o formulário quando enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Captura e sanitiza os dados do formulário
        $codigo = trim($_POST['codigo']);
        $nome = trim($_POST['nome']);
        $endereco = trim($_POST['endereco']) ?? null;
        $telefone = trim($_POST['telefone']) ?? null;
        $email = trim($_POST['email']) ?? null;
        $observacoes = trim($_POST['observacoes']) ?? null;

        // Captura tipo de pessoa
        $tipo_pessoa = trim($_POST['tipo_pessoa']) ?? 'PJ';
        
        // Captura documentos
        $cnpj = null;
        $cpf = null;
        
        if ($tipo_pessoa === 'PF') {
            $cpf = trim($_POST['cpf']) ?? null;
            $documento = $cpf;
            $documento_tipo = 'CPF';
            
            if (empty($cpf)) {
                throw new Exception("CPF é obrigatório para Pessoa Física.");
            }
            
            // Validação básica de CPF
            $cpf_limpo = preg_replace('/\D/', '', $cpf);
            if (strlen($cpf_limpo) !== 11) {
                throw new Exception("CPF deve ter 11 dígitos.");
            }
            
        } else {
            $cnpj = trim($_POST['cnpj']) ?? null;
            $documento = $cnpj;
            $documento_tipo = 'CNPJ';
            
            if (empty($cnpj)) {
                throw new Exception("CNPJ é obrigatório para Pessoa Jurídica.");
            }
            
            // Validação básica de CNPJ
            $cnpj_limpo = preg_replace('/\D/', '', $cnpj);
            if (strlen($cnpj_limpo) !== 14) {
                throw new Exception("CNPJ deve ter 14 dígitos.");
            }
        }

        // Validações básicas
        if (empty($codigo) || empty($nome)) {
            throw new Exception("Os campos Código e Nome são obrigatórios.");
        }

        // Validação de e-mail se fornecido
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("E-mail inválido.");
        }

        // Inicia transação
        $pdo->beginTransaction();

        // Verifica duplicatas de documento
        if ($tipo_pessoa === 'PF') {
            $sql_check_documento = "SELECT COUNT(*) FROM fornecedores WHERE cpf = :documento AND cpf IS NOT NULL";
        } else {
            $sql_check_documento = "SELECT COUNT(*) FROM fornecedores WHERE cnpj = :documento AND cnpj IS NOT NULL";
        }
        
        $stmt_check_documento = $pdo->prepare($sql_check_documento);
        $stmt_check_documento->bindParam(':documento', $documento);
        $stmt_check_documento->execute();

        if ($stmt_check_documento->fetchColumn() > 0) {
            throw new Exception("Já existe um fornecedor cadastrado com este {$documento_tipo}!");
        }

        // Verifica nome duplicado
        $sql_check_nome = "SELECT COUNT(*) FROM fornecedores WHERE UPPER(TRIM(nome)) = UPPER(TRIM(:nome))";
        $stmt_check_nome = $pdo->prepare($sql_check_nome);
        $stmt_check_nome->bindParam(':nome', $nome);
        $stmt_check_nome->execute();

        if ($stmt_check_nome->fetchColumn() > 0) {
            throw new Exception("Já existe um fornecedor cadastrado com este nome!");
        }

        // Verifica se o código já existe
        $sql_check_codigo = "SELECT COUNT(*) FROM fornecedores WHERE codigo = :codigo";
        $stmt_check_codigo = $pdo->prepare($sql_check_codigo);
        $stmt_check_codigo->bindParam(':codigo', $codigo);
        $stmt_check_codigo->execute();
        $count_codigo = $stmt_check_codigo->fetchColumn();

        if ($count_codigo > 0) {
            throw new Exception("Código do fornecedor já existe!");
        }

        // Realiza o cadastro do fornecedor no banco de dados
        $sql = "INSERT INTO fornecedores (codigo, tipo_pessoa, cnpj, cpf, nome, endereco, telefone, email, observacoes) 
                VALUES (:codigo, :tipo_pessoa, :cnpj, :cpf, :nome, :endereco, :telefone, :email, :observacoes)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':codigo', $codigo, PDO::PARAM_STR);
        $stmt->bindParam(':tipo_pessoa', $tipo_pessoa, PDO::PARAM_STR);
        $stmt->bindParam(':cnpj', $cnpj, PDO::PARAM_STR);
        $stmt->bindParam(':cpf', $cpf, PDO::PARAM_STR);
        $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
        $stmt->bindParam(':endereco', $endereco, PDO::PARAM_STR);
        $stmt->bindParam(':telefone', $telefone, PDO::PARAM_STR);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':observacoes', $observacoes, PDO::PARAM_STR);

        if (!$stmt->execute()) {
            throw new Exception("Erro ao cadastrar o fornecedor.");
        }

        $fornecedor_id = $pdo->lastInsertId();

        // Commit da transação
        $pdo->commit();

        // Registra auditoria
        logUserAction('CREATE', 'fornecedores', $fornecedor_id, [
            'codigo' => $codigo,
            'tipo_pessoa' => $tipo_pessoa,
            'cnpj' => $cnpj,
            'cpf' => $cpf,
            'nome' => $nome,
            'endereco' => $endereco,
            'telefone' => $telefone,
            'email' => $email,
            'observacoes' => $observacoes
        ]);

        $success = true;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Erro ao cadastrar o fornecedor: " . $e->getMessage();
    }
}

// Inclui o header do sistema
include('includes/header_template.php');
renderHeader("Cadastro de Fornecedor - LicitaSis", "fornecedores");
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Fornecedor - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        /* ===========================================
           VARIÁVEIS CSS E RESET
           =========================================== */
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

        /* ===========================================
           LAYOUT PRINCIPAL
           =========================================== */
        .container {
            max-width: 1000px;
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

        /* ===========================================
           ALERTAS E MENSAGENS
           =========================================== */
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

        /* ===========================================
           FORMULÁRIO
           =========================================== */
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

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        /* Estados de validação */
        .form-control.is-valid {
            border-color: var(--success-color);
            background: rgba(40, 167, 69, 0.05);
        }

        .form-control.is-invalid {
            border-color: var(--danger-color);
            background: rgba(220, 53, 69, 0.05);
        }

        .form-control.loading {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Texto de ajuda */
        .form-text {
            font-size: 0.85rem;
            color: var(--medium-gray);
            margin-top: 0.25rem;
            display: block;
        }

        /* ===========================================
           BOTÕES
           =========================================== */
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

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* ===========================================
           MODAL
           =========================================== */
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

        .modal-buttons .btn {
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

        /* ===========================================
           UTILITÁRIOS
           =========================================== */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            display: none;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid var(--border-color);
            border-top: 4px solid var(--secondary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Toast notifications */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--success-color);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            z-index: 1001;
            animation: slideInRight 0.3s ease;
            font-weight: 500;
            min-width: 300px;
            max-width: 400px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .toast.error {
            background: var(--danger-color);
        }

        .toast.warning {
            background: var(--warning-color);
            color: #333;
        }

        .toast.info {
            background: var(--info-color);
        }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }

        /* ===========================================
           RESPONSIVIDADE
           =========================================== */
        @media (max-width: 1200px) {
            .container {
                margin: 2rem 1.5rem;
                padding: 2rem;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin: 1.5rem 1rem;
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

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .modal-buttons {
                flex-direction: column;
            }

            .modal-buttons .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .container {
                margin: 1rem 0.5rem;
                padding: 1.25rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .form-control {
                padding: 0.75rem 0.875rem;
                font-size: 0.95rem;
            }

            .btn {
                padding: 0.875rem 1.5rem;
                font-size: 0.95rem;
            }

            .modal-content {
                width: 100%;
                margin: 0;
                border-radius: 0;
                max-height: 100vh;
            }

            .modal-header {
                border-radius: 0;
            }
        }

        @media (max-width: 360px) {
            .container {
                padding: 1rem;
                margin: 0.75rem 0.25rem;
            }

            h2 {
                font-size: 1.3rem;
            }

            .form-control {
                padding: 0.7rem 0.8rem;
                font-size: 0.9rem;
            }

            .btn {
                padding: 0.75rem 1.25rem;
                font-size: 0.9rem;
            }
        }

        /* ===========================================
           TEMAS E PERSONALIZAÇÕES
           =========================================== */
        .form-section {
            background: linear-gradient(135deg, var(--light-gray) 0%, #f1f3f4 100%);
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .form-section h3 {
            color: var(--primary-color);
            margin: 0 0 1.5rem 0;
            font-size: 1.3rem;
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-section h3 i {
            color: var(--secondary-color);
        }

        /* ===========================================
           VALIDAÇÃO VISUAL
           =========================================== */
        .validation-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1rem;
            opacity: 0;
            transition: var(--transition);
        }

        .form-control.is-valid + .validation-icon {
            opacity: 1;
            color: var(--success-color);
        }

        .form-control.is-invalid + .validation-icon {
            opacity: 1;
            color: var(--danger-color);
        }

        /* Campos com ícones de validação */
        .input-with-validation {
            position: relative;
        }

        .input-with-validation .form-control {
            padding-right: 2.5rem;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>
        <i class="fas fa-truck-loading"></i>
        Cadastro de Fornecedor
    </h2>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form class="form-container" action="cadastro_fornecedores.php" method="POST" onsubmit="return validarFormulario()">
        
        <!-- Seção: Informações Básicas -->
        <div class="form-section">
            <h3>
                <i class="fas fa-info-circle"></i>
                Informações Básicas
            </h3>
            
           <div class="form-row">
    <div class="form-group">
        <label for="tipo_pessoa" class="required">
            <i class="fas fa-user-tag"></i>
            Tipo de Pessoa
        </label>
        <select id="tipo_pessoa" 
                name="tipo_pessoa" 
                class="form-control" 
                required
                onchange="alterarTipoPessoa(this.value)">
            <option value="PJ" <?php echo (!isset($_POST['tipo_pessoa']) || $_POST['tipo_pessoa'] === 'PJ') ? 'selected' : ''; ?>>
                Pessoa Jurídica (CNPJ)
            </option>
            <option value="PF" <?php echo (isset($_POST['tipo_pessoa']) && $_POST['tipo_pessoa'] === 'PF') ? 'selected' : ''; ?>>
                Pessoa Física (CPF)
            </option>
        </select>
    </div>

    <div class="form-group input-with-validation">
        <label for="codigo" class="required">
            <i class="fas fa-barcode"></i>
            Código do Fornecedor
        </label>
        <input type="text" 
               id="codigo" 
               name="codigo" 
               class="form-control" 
               placeholder="Digite o código único do fornecedor"
               value="<?php echo $success ? '' : (isset($_POST['codigo']) ? htmlspecialchars($_POST['codigo']) : ''); ?>"
               required
               onblur="validarCodigo(this)">
        <i class="validation-icon fas fa-check-circle"></i>
        <small class="form-text">Código único para identificação interna</small>
    </div>
</div>

<div class="form-row">
    <div class="form-group input-with-validation">
        <label for="nome" class="required">
            <i class="fas fa-building" id="icon-nome"></i>
            <span id="label-nome">Nome do Fornecedor</span>
        </label>
        <input type="text" 
               id="nome" 
               name="nome" 
               class="form-control" 
               placeholder="Nome ou razão social completa"
               value="<?php echo $success ? '' : (isset($_POST['nome']) ? htmlspecialchars($_POST['nome']) : ''); ?>"
               required
               onblur="validarNome(this)">
        <i class="validation-icon fas fa-check-circle"></i>
    </div>

    <!-- Campo CNPJ (visível para PJ) -->
    <div class="form-group input-with-validation" id="campo-cnpj">
        <label for="cnpj" class="required">
            <i class="fas fa-id-card"></i>
            CNPJ
        </label>
        <input type="text" 
               id="cnpj" 
               name="cnpj" 
               class="form-control" 
               placeholder="00.000.000/0000-00"
               value="<?php echo $success ? '' : (isset($_POST['cnpj']) ? htmlspecialchars($_POST['cnpj']) : ''); ?>"
               maxlength="18"
               oninput="aplicarMascaraCNPJ(this)"
               onblur="validarCNPJCompleto(this)">
        <i class="validation-icon fas fa-check-circle"></i>
        <small class="form-text">Será consultado automaticamente na Receita Federal</small>
    </div>

    <!-- Campo CPF (oculto por padrão, visível para PF) -->
    <div class="form-group input-with-validation" id="campo-cpf" style="display: none;">
        <label for="cpf" class="required">
            <i class="fas fa-id-card"></i>
            CPF
        </label>
        <input type="text" 
               id="cpf" 
               name="cpf" 
               class="form-control" 
               placeholder="000.000.000-00"
               value="<?php echo $success ? '' : (isset($_POST['cpf']) ? htmlspecialchars($_POST['cpf']) : ''); ?>"
               maxlength="14"
               oninput="aplicarMascaraCPF(this)"
               onblur="validarCPFCompleto(this)">
        <i class="validation-icon fas fa-check-circle"></i>
        <small class="form-text">Para teste, use CPFs válidos: 11144477735, 12345678909, 98765432100</small>
    </div>
</div>

            <div class="form-row">
                <div class="form-group input-with-validation">
                    <label for="email">
                        <i class="fas fa-envelope"></i>
                        E-mail
                    </label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           class="form-control" 
                           placeholder="email@fornecedor.com.br"
                           value="<?php echo $success ? '' : (isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''); ?>"
                           onblur="validarEmail(this)">
                    <i class="validation-icon fas fa-check-circle"></i>
                </div>
            </div>
        </div>

        <!-- Seção: Informações de Contato -->
        <div class="form-section">
            <h3>
                <i class="fas fa-address-book"></i>
                Informações de Contato
            </h3>
            
            <div class="form-row">
                <div class="form-group input-with-validation">
                    <label for="telefone">
                        <i class="fas fa-phone"></i>
                        Telefone
                    </label>
                    <input type="tel" 
                           id="telefone" 
                           name="telefone" 
                           class="form-control" 
                           placeholder="(00) 00000-0000"
                           value="<?php echo $success ? '' : (isset($_POST['telefone']) ? htmlspecialchars($_POST['telefone']) : ''); ?>"
                           oninput="aplicarMascaraTelefone(this)">
                    <i class="validation-icon fas fa-check-circle"></i>
                </div>

                <div class="form-group">
                    <label for="endereco">
                        <i class="fas fa-map-marker-alt"></i>
                        Endereço Completo
                    </label>
                    <input type="text" 
                           id="endereco" 
                           name="endereco" 
                           class="form-control" 
                           placeholder="Rua, número, bairro, cidade - UF"
                           value="<?php echo $success ? '' : (isset($_POST['endereco']) ? htmlspecialchars($_POST['endereco']) : ''); ?>">
                    <small class="form-text">Endereço será preenchido automaticamente via consulta CNPJ</small>
                </div>
            </div>
        </div>

        <!-- Seção: Observações -->
        <div class="form-section">
            <h3>
                <i class="fas fa-comment-alt"></i>
                Informações Adicionais
            </h3>
            
            <div class="form-group">
                <label for="observacoes">
                    <i class="fas fa-sticky-note"></i>
                    Observações
                </label>
                <textarea id="observacoes" 
                          name="observacoes" 
                          class="form-control" 
                          rows="4"
                          placeholder="Informações adicionais sobre o fornecedor, condições de pagamento, especialidades, etc."><?php echo $success ? '' : (isset($_POST['observacoes']) ? htmlspecialchars($_POST['observacoes']) : ''); ?></textarea>
            </div>
        </div>

        <!-- Botões de Ação -->
        <div class="btn-container">
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-save"></i>
                Cadastrar Fornecedor
            </button>
            <button type="reset" class="btn btn-secondary" onclick="limparFormulario()">
                <i class="fas fa-undo"></i>
                Limpar Campos
            </button>
        </div>
    </form>
</div>

<!-- Modal de sucesso -->
<div id="successModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-check-circle"></i>
                Fornecedor Cadastrado!
            </h3>
        </div>
        <div class="modal-body">
            <p>O fornecedor foi cadastrado com sucesso no sistema.</p>
            <p>Deseja acessar a página de consulta de fornecedores?</p>
            <div class="modal-buttons">
                <button class="btn btn-primary" onclick="goToConsulta()">
                    <i class="fas fa-search"></i>
                    Sim, Ver Fornecedores
                </button>
                <button class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-plus"></i>
                    Cadastrar Outro
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Loading overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="spinner"></div>
</div>

<script>
// ===========================================
// SISTEMA COMPLETO DE CADASTRO DE FORNECEDORES
// JavaScript Completo - LicitaSis v7.0
// ===========================================

// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let cnpjConsultaTimeout = null;

// ===========================================
// MÁSCARAS E FORMATAÇÃO
// ===========================================

/**
 * Aplica máscara de CNPJ
 */
function aplicarMascaraCNPJ(input) {
    let value = input.value.replace(/\D/g, '');
    
    if (value.length <= 14) {
        value = value.replace(/^(\d{2})(\d)/, '$1.$2');
        value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
        value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
        value = value.replace(/(\d{4})(\d)/, '$1-$2');
        input.value = value;
        
        // Consulta CNPJ automaticamente se tiver 14 dígitos
        if (value.replace(/\D/g, '').length === 14) {
            consultarCNPJ(value);
        }
    }
}

/**
 * Aplica máscara de telefone
 */
function aplicarMascaraTelefone(input) {
    let value = input.value.replace(/\D/g, '');
    
    if (value.length <= 11) {
        if (value.length <= 10) {
            value = value.replace(/^(\d{2})(\d)/, '($1) $2');
            value = value.replace(/(\d{4})(\d)/, '$1-$2');
        } else {
            value = value.replace(/^(\d{2})(\d)/, '($1) $2');
            value = value.replace(/(\d{5})(\d)/, '$1-$2');
        }
        input.value = value;
    }
}

// ===========================================
// VALIDAÇÕES
// ===========================================

/**
 * Valida código do fornecedor
 */
/**
 * Valida código do fornecedor
 */
function validarCodigo(input) {
    const codigo = input.value.trim();
    
    if (!codigo) {
        setValidationState(input, false, 'Código é obrigatório');
        return false;
    }
    
    if (codigo.length < 2) {
        setValidationState(input, false, 'Código deve ter pelo menos 2 caracteres');
        return false;
    }
    
    if (codigo.length > 20) {
        setValidationState(input, false, 'Código deve ter no máximo 20 caracteres');
        return false;
    }
    
    // Verifica se código já existe
    verificarCodigoExistente(codigo, input);
    
    return true;
}

/**
 * Valida nome do fornecedor
 */
function validarNome(input) {
    const nome = input.value.trim();
    
    if (!nome) {
        setValidationState(input, false, 'Nome é obrigatório');
        return false;
    }
    
    if (nome.length < 3) {
        setValidationState(input, false, 'Nome deve ter pelo menos 3 caracteres');
        return false;
    }
    
    setValidationState(input, true, 'Nome válido');
    return true;
}

/**
 * Valida CNPJ completo
 */
/**
 * Valida CNPJ completo
 */
function validarCNPJCompleto(input) {
    const cnpj = input.value.replace(/\D/g, '');
    const tipoPessoa = document.getElementById('tipo_pessoa').value;
    
    // Se for pessoa física, CNPJ não é obrigatório
    if (tipoPessoa === 'PF') {
        if (!cnpj) {
            setValidationState(input, null, '');
            return true;
        }
    }
    
    // Se for pessoa jurídica e estiver vazio
    if (tipoPessoa === 'PJ' && !cnpj) {
        setValidationState(input, false, 'CNPJ é obrigatório para Pessoa Jurídica');
        return false;
    }
    
    // Se tem valor, valida
    if (cnpj) {
        if (cnpj.length !== 14) {
            setValidationState(input, false, 'CNPJ deve ter 14 dígitos');
            return false;
        }
        
        if (!validarDigitosCNPJ(cnpj)) {
            setValidationState(input, false, 'CNPJ inválido');
            return false;
        }
        
        // Verifica se CNPJ já existe
        verificarDocumentoExistente(cnpj, 'CNPJ', input);
        setValidationState(input, true, 'CNPJ válido');
    }
    
    return true;
}

/**
 * Valida dígitos verificadores do CNPJ
 */
function validarDigitosCNPJ(cnpj) {
    // Elimina CNPJs inválidos conhecidos
    if (/^(\d)\1+$/.test(cnpj)) return false;
    
    // Validação do primeiro dígito verificador
    let tamanho = cnpj.length - 2;
    let numeros = cnpj.substring(0, tamanho);
    let digitos = cnpj.substring(tamanho);
    let soma = 0;
    let pos = tamanho - 7;
    
    for (let i = tamanho; i >= 1; i--) {
        soma += numeros.charAt(tamanho - i) * pos--;
        if (pos < 2) pos = 9;
    }
    
    let resultado = soma % 11 < 2 ? 0 : 11 - soma % 11;
    if (resultado != digitos.charAt(0)) return false;
    
    // Validação do segundo dígito verificador
    tamanho = tamanho + 1;
    numeros = cnpj.substring(0, tamanho);
    soma = 0;
    pos = tamanho - 7;
    
    for (let i = tamanho; i >= 1; i--) {
        soma += numeros.charAt(tamanho - i) * pos--;
        if (pos < 2) pos = 9;
    }
    
    resultado = soma % 11 < 2 ? 0 : 11 - soma % 11;
    if (resultado != digitos.charAt(1)) return false;
    
    return true;
}

/**
 * Valida e-mail
 */
function validarEmail(input) {
    const email = input.value.trim();
    
    if (!email) {
        setValidationState(input, null, '');
        return true; // E-mail é opcional
    }
    
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (!emailRegex.test(email)) {
        setValidationState(input, false, 'E-mail inválido');
        return false;
    }
    
    setValidationState(input, true, 'E-mail válido');
    return true;
}

/**
 * Define estado visual de validação
 */
function setValidationState(input, isValid, message) {
    input.classList.remove('is-valid', 'is-invalid');
    
    if (isValid === true) {
        input.classList.add('is-valid');
    } else if (isValid === false) {
        input.classList.add('is-invalid');
        if (message) {
            showToast(message, 'error', 3000);
        }
    }
}

// ===========================================
// CONSULTA DE CNPJ
// ===========================================

/**
 * Consulta CNPJ na Receita Federal
 */
function consultarCNPJ(cnpj) {
    const cnpjLimpo = cnpj.replace(/\D/g, '');
    
    if (cnpjLimpo.length !== 14) return;
    
    // Limpa timeout anterior
    if (cnpjConsultaTimeout) {
        clearTimeout(cnpjConsultaTimeout);
    }
    
    // Debounce de 500ms
    cnpjConsultaTimeout = setTimeout(() => {
        realizarConsultaCNPJ(cnpjLimpo);
    }, 500);
}

/**
 * Realiza a consulta CNPJ
 */
function realizarConsultaCNPJ(cnpj) {
    const cnpjInput = document.getElementById('cnpj');
    const nomeInput = document.getElementById('nome');
    const enderecoInput = document.getElementById('endereco');
    const telefoneInput = document.getElementById('telefone');
    const emailInput = document.getElementById('email');
    
    // Mostra loading
    cnpjInput.classList.add('loading');
    showToast('Consultando CNPJ na Receita Federal...', 'info', 2000);
    
    // Simulação de consulta - implementar integração real com API
    fetch(`consultar_cnpj.php?cnpj=${cnpj}`)
        .then(response => response.json())
        .then(data => {
            cnpjInput.classList.remove('loading');
            
            if (data.status === "OK") {
                // Preenche campos automaticamente
                if (data.nome && !nomeInput.value) {
                    nomeInput.value = data.nome;
                    setValidationState(nomeInput, true, 'Nome preenchido automaticamente');
                }
                
                if (data.endereco && !enderecoInput.value) {
                    const enderecoCompleto = `${data.logradouro}, ${data.numero || 'S/N'} - ${data.bairro} - ${data.municipio}/${data.uf}`;
                    enderecoInput.value = enderecoCompleto;
                }
                
                if (data.telefone && !telefoneInput.value) {
                    telefoneInput.value = data.telefone;
                    aplicarMascaraTelefone(telefoneInput);
                }
                
                if (data.email && !emailInput.value) {
                    emailInput.value = data.email;
                    validarEmail(emailInput);
                }
                
                setValidationState(cnpjInput, true, 'CNPJ consultado com sucesso');
                showToast('Dados preenchidos automaticamente via consulta CNPJ', 'success');
                
            } else {
                setValidationState(cnpjInput, false, 'CNPJ não encontrado na Receita Federal');
                showToast('CNPJ não encontrado ou inválido', 'warning');
            }
        })
        .catch(error => {
            cnpjInput.classList.remove('loading');
            console.error('Erro na consulta CNPJ:', error);
            showToast('Erro ao consultar CNPJ. Preencha os dados manualmente.', 'warning');
        });
}

/**
 * Verifica se código já existe
 */
/**
 * Verifica se código já existe
 */
function verificarCodigoExistente(codigo, input) {
    // Por enquanto, só valida local até implementar o backend
    if (codigo && codigo.length >= 2) {
        setValidationState(input, true, 'Código válido');
    }
    
    // Implementar depois com o backend
    /*
    fetch(`verificar_codigo_fornecedor.php?codigo=${encodeURIComponent(codigo)}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Erro na resposta do servidor');
            }
            return response.json();
        })
        .then(data => {
            if (data.existe) {
                setValidationState(input, false, 'Código já existe para outro fornecedor');
            } else {
                setValidationState(input, true, 'Código disponível');
            }
        })
        .catch(error => {
            console.error('Erro ao verificar código:', error);
            // Em caso de erro, assume que está ok
            setValidationState(input, true, 'Código válido');
        });
    */
}

// ===========================================
// VALIDAÇÃO DO FORMULÁRIO
// ===========================================

/**
 * Valida todo o formulário antes do envio
 */
/**
 * Valida todo o formulário antes do envio
 */
function validarFormulario() {
    let isValid = true;
    let firstErrorField = null;
    
    // Campos obrigatórios
    const codigoInput = document.getElementById('codigo');
    const nomeInput = document.getElementById('nome');
    const cnpjInput = document.getElementById('cnpj');
    const cpfInput = document.getElementById('cpf');
    const emailInput = document.getElementById('email');
    const tipoPessoa = document.getElementById('tipo_pessoa').value;
    
    // Valida código
    if (!validarCodigo(codigoInput)) {
        isValid = false;
        if (!firstErrorField) firstErrorField = codigoInput;
    }
    
    // Valida nome
    if (!validarNome(nomeInput)) {
        isValid = false;
        if (!firstErrorField) firstErrorField = nomeInput;
    }
    
    // Valida documento baseado no tipo de pessoa
    if (tipoPessoa === 'PF') {
        // Pessoa Física - valida apenas CPF
        if (!cpfInput.value.trim()) {
            setValidationState(cpfInput, false, 'CPF é obrigatório para Pessoa Física');
            isValid = false;
            if (!firstErrorField) firstErrorField = cpfInput;
        } else if (!validarCPFCompleto(cpfInput)) {
            isValid = false;
            if (!firstErrorField) firstErrorField = cpfInput;
        }
    } else {
        // Pessoa Jurídica - valida apenas CNPJ
        if (!cnpjInput.value.trim()) {
            setValidationState(cnpjInput, false, 'CNPJ é obrigatório para Pessoa Jurídica');
            isValid = false;
            if (!firstErrorField) firstErrorField = cnpjInput;
        } else if (!validarCNPJCompleto(cnpjInput)) {
            isValid = false;
            if (!firstErrorField) firstErrorField = cnpjInput;
        }
    }
    
    // Valida e-mail se preenchido
    if (emailInput.value && !validarEmail(emailInput)) {
        isValid = false;
        if (!firstErrorField) firstErrorField = emailInput;
    }
    
    if (!isValid) {
        showToast('Por favor, corrija os campos destacados em vermelho.', 'error');
        if (firstErrorField) {
            firstErrorField.focus();
        }
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

// ===========================================
// FUNÇÕES DE MODAL E NAVEGAÇÃO
// ===========================================

/**
 * Abre o modal de sucesso
 */
function openModal() {
    const modal = document.getElementById('successModal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        setTimeout(() => {
            const primaryBtn = document.querySelector('.modal-buttons .btn-primary');
            if (primaryBtn) primaryBtn.focus();
        }, 300);
    }
}

/**
 * Fecha o modal e limpa o formulário
 */
function closeModal() {
    const modal = document.getElementById('successModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    
    limparFormulario();
    
    setTimeout(() => {
        const codigoInput = document.getElementById('codigo');
        if (codigoInput) codigoInput.focus();
    }, 100);
}

/**
 * Navega para a página de consulta
 */
function goToConsulta() {
    document.getElementById('successModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    window.location.href = 'consulta_fornecedores.php?success=' + encodeURIComponent('Fornecedor cadastrado com sucesso!');
}

/**
 * Limpa todos os campos do formulário
 */
function limparFormulario() {
    const form = document.querySelector('form');
    if (form) {
        form.reset();
    }
    
    // Remove classes de validação
    document.querySelectorAll('.form-control').forEach(input => {
        input.classList.remove('is-valid', 'is-invalid', 'loading');
    });
    
    // Reabilita botão se necessário
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Cadastrar Fornecedor';
    }
}

// ===========================================
// SISTEMA DE NOTIFICAÇÕES TOAST
// ===========================================

/**
 * Exibe notificação toast
 */
function showToast(message, type = 'info', duration = 4000) {
    // Remove toast existente se houver
    const existingToast = document.getElementById('toast');
    if (existingToast) {
        existingToast.remove();
    }
    
    const toast = document.createElement('div');
    toast.id = 'toast';
    toast.className = `toast ${type}`;
    
    let icon;
    switch(type) {
        case 'success':
            icon = 'check-circle';
            break;
        case 'error':
            icon = 'exclamation-circle';
            break;
        case 'warning':
            icon = 'exclamation-triangle';
            break;
        default:
            icon = 'info-circle';
    }
    
    toast.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; color: inherit; font-size: 1.2rem; cursor: pointer; padding: 0; margin-left: auto;">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    document.body.appendChild(toast);
    
    // Remove automaticamente
    setTimeout(() => {
        if (toast.parentElement) {
            toast.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 300);
        }
    }, duration);
}

// ===========================================
// FUNÇÕES UTILITÁRIAS
// ===========================================

/**
 * Formata valor monetário
 */
function formatarMoeda(valor) {
    return 'R$ ' + parseFloat(valor || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

/**
 * Remove caracteres não numéricos
 */
function apenasNumeros(str) {
    return str.replace(/\D/g, '');
}

/**
 * Capitaliza primeira letra de cada palavra
 */
function capitalizarPalavras(str) {
    return str.replace(/\w\S*/g, (txt) => {
        return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();
    });
}

// ===========================================
// AUTO-SAVE (RASCUNHO)
// ===========================================

/**
 * Salva rascunho automaticamente
 */
function autoSaveForm() {
    try {
        const formData = new FormData(document.querySelector('form'));
        const data = {};
        
        for (let [key, value] of formData.entries()) {
            data[key] = value;
        }
        
        localStorage.setItem('fornecedor_draft', JSON.stringify(data));
    } catch (error) {
        console.error('Erro no auto-save:', error);
    }
}

/**
 * Carrega rascunho salvo
 */
function loadDraft() {
    const draft = localStorage.getItem('fornecedor_draft');
    if (!draft) return;
    
    try {
        const data = JSON.parse(draft);
        
        if (confirm('Há um rascunho salvo. Deseja carregá-lo?')) {
            Object.keys(data).forEach(key => {
                const input = document.querySelector(`[name="${key}"]`);
                if (input && data[key]) {
                    input.value = data[key];
                    
                    // Aplica validações e máscaras
                    if (key === 'cnpj') {
                        aplicarMascaraCNPJ(input);
                    } else if (key === 'telefone') {
                        aplicarMascaraTelefone(input);
                    }
                }
            });
            
            showToast('Rascunho carregado com sucesso!', 'success');
        }
    } catch (error) {
        console.error('Erro ao carregar rascunho:', error);
        localStorage.removeItem('fornecedor_draft');
    }
}

/**
 * Limpa rascunho salvo
 */
function clearDraft() {
    localStorage.removeItem('fornecedor_draft');
}

// ===========================================
// INICIALIZAÇÃO E EVENT LISTENERS
// ===========================================

/**
 * Inicialização quando a página carrega
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 LicitaSis - Sistema de Cadastro de Fornecedores carregado');
    
    // Event listeners para validação em tempo real
    const codigoInput = document.getElementById('codigo');
    if (codigoInput) {
        codigoInput.addEventListener('blur', function() {
            validarCodigo(this);
        });
    }
    
    const nomeInput = document.getElementById('nome');
    if (nomeInput) {
        nomeInput.addEventListener('blur', function() {
            validarNome(this);
        });
        
        // Capitaliza automaticamente
        nomeInput.addEventListener('input', function() {
            const cursorPos = this.selectionStart;
            this.value = capitalizarPalavras(this.value);
            this.setSelectionRange(cursorPos, cursorPos);
        });
    }
    
    const cnpjInput = document.getElementById('cnpj');
    if (cnpjInput) {
        cnpjInput.addEventListener('input', function() {
            aplicarMascaraCNPJ(this);
        });
        
        cnpjInput.addEventListener('blur', function() {
            validarCNPJCompleto(this);
        });
    }
    
    const telefoneInput = document.getElementById('telefone');
    if (telefoneInput) {
        telefoneInput.addEventListener('input', function() {
            aplicarMascaraTelefone(this);
        });
    }
    
    const emailInput = document.getElementById('email');
    if (emailInput) {
        emailInput.addEventListener('blur', function() {
            validarEmail(this);
        });
    }
    
    // Auto-save a cada 30 segundos
    setInterval(autoSaveForm, 30000);
    
    // Carrega draft após 2 segundos
    setTimeout(loadDraft, 2000);
    
    // Foca no primeiro campo
    setTimeout(() => {
        const codigoInput = document.getElementById('codigo');
        if (codigoInput) {
            codigoInput.focus();
        }
    }, 200);
    
    console.log('✅ Todos os event listeners inicializados');
});

// Event listener para CPF
// Event listener para CPF
    // Event listener para CPF
    const cpfInput = document.getElementById('cpf');
    if (cpfInput) {
        cpfInput.addEventListener('input', function() {
            aplicarMascaraCPF(this);
        });
        
        cpfInput.addEventListener('blur', function() {
            validarCPFCompleto(this);
        });
    }

    // Event listener para tipo de pessoa
    const tipoPessoaSelect = document.getElementById('tipo_pessoa');
    if (tipoPessoaSelect) {
        tipoPessoaSelect.addEventListener('change', function() {
            alterarTipoPessoa(this.value);
        });
        
        // Configura estado inicial
        setTimeout(() => {
            alterarTipoPessoa(tipoPessoaSelect.value);
        }, 100);
    }

// ===========================================
// EVENT LISTENERS GLOBAIS
// ===========================================

// Fecha modal ao clicar fora dele
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
            document.querySelector('form')?.submit();
        }
    }
    
    if (e.key === 'Escape') {
        const modal = document.getElementById('successModal');
        if (modal && modal.style.display === 'block') {
            closeModal();
        }
    }
});

// ===========================================
// VERIFICAÇÃO DE SUCESSO (PHP)
// ===========================================

window.onload = function() {
    <?php if ($success): ?>
        openModal();
        clearDraft(); // Limpa rascunho após sucesso
    <?php endif; ?>
}

// ===========================================
// DEBUG E MONITORAMENTO
// ===========================================

// Monitoramento de erros
window.addEventListener('error', function(e) {
    console.error('Erro JavaScript capturado:', e.error);
});

// Função para debug
function debugSystem() {
    console.log('🔍 Debug do Sistema:', {
        codigo: !!document.getElementById('codigo'),
        nome: !!document.getElementById('nome'),
        cnpj: !!document.getElementById('cnpj'),
        email: !!document.getElementById('email'),
        telefone: !!document.getElementById('telefone'),
        endereco: !!document.getElementById('endereco'),
        modal: !!document.getElementById('successModal')
    });
}

// Expõe função de debug globalmente
window.debugSystem = debugSystem;

// ===========================================
// LOG FINAL
// ===========================================

console.log('🚀 Sistema de Cadastro de Fornecedores LicitaSis v7.0 carregado:', {
    versao: '7.0 Completa',
    funcionalidades: [
        '✅ Validação em tempo real',
        '✅ Máscaras automáticas',
        '✅ Consulta CNPJ automática',
        '✅ Auto-save de rascunhos',
        '✅ Notificações toast',
        '✅ Validação completa de CNPJ',
        '✅ Interface responsiva',
        '✅ Atalhos de teclado',
        '✅ Error handling',
        '✅ Modal de sucesso',
        '✅ Header template integrado'
    ],
    compatibilidade: 'Navegadores modernos',
    acessibilidade: 'Suporte a teclado e screen readers',
    performance: 'Otimizado com debounce'
});

// ===========================================
// FUNÇÕES PARA PESSOA FÍSICA/JURÍDICA
// ===========================================

/**
 * Altera campos baseado no tipo de pessoa
 */
/**
 * Altera campos baseado no tipo de pessoa
 */
function alterarTipoPessoa(tipo) {
    const campoCNPJ = document.getElementById('campo-cnpj');
    const campoCPF = document.getElementById('campo-cpf');
    const iconNome = document.getElementById('icon-nome');
    const labelNome = document.getElementById('label-nome');
    const inputNome = document.getElementById('nome');
    const cnpjInput = document.getElementById('cnpj');
    const cpfInput = document.getElementById('cpf');
    
    if (tipo === 'PF') {
        // Pessoa Física
        campoCNPJ.style.display = 'none';
        campoCPF.style.display = 'block';
        iconNome.className = 'fas fa-user';
        labelNome.textContent = 'Nome Completo';
        inputNome.placeholder = 'Nome completo da pessoa física';
        
        // Gerencia required attributes
        cnpjInput.removeAttribute('required');
        cpfInput.setAttribute('required', 'required');
        
        // Limpa validações e valor do CNPJ
        cnpjInput.classList.remove('is-valid', 'is-invalid', 'loading');
        cnpjInput.value = '';
        
    } else {
        // Pessoa Jurídica
        campoCNPJ.style.display = 'block';
        campoCPF.style.display = 'none';
        iconNome.className = 'fas fa-building';
        labelNome.textContent = 'Nome do Fornecedor';
        inputNome.placeholder = 'Nome ou razão social completa';
        
        // Gerencia required attributes
        cpfInput.removeAttribute('required');
        cnpjInput.setAttribute('required', 'required');
        
        // Limpa validações e valor do CPF
        cpfInput.classList.remove('is-valid', 'is-invalid');
        cpfInput.value = '';
    }
    
    // Atualiza as labels dos campos para refletir se são obrigatórios ou não
    const cnpjLabel = document.querySelector('label[for="cnpj"]');
    const cpfLabel = document.querySelector('label[for="cpf"]');
    
    if (cnpjLabel) {
        cnpjLabel.classList.toggle('required', tipo === 'PJ');
    }
    if (cpfLabel) {
        cpfLabel.classList.toggle('required', tipo === 'PF');
    }
}

/**
 * Aplica máscara de CPF
 */
function aplicarMascaraCPF(input) {
    let value = input.value.replace(/\D/g, '');
    
    if (value.length <= 11) {
        value = value.replace(/^(\d{3})(\d)/, '$1.$2');
        value = value.replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3');
        value = value.replace(/\.(\d{3})(\d)/, '.$1-$2');
        input.value = value;
    }
}

/**
 * Valida CPF completo
 */
/**
 * Valida CPF completo
 */
function validarCPFCompleto(input) {
    const cpf = input.value.replace(/\D/g, '');
    const tipoPessoa = document.getElementById('tipo_pessoa').value;
    
    // Se for pessoa jurídica, CPF não é obrigatório
    if (tipoPessoa === 'PJ') {
        if (!cpf) {
            setValidationState(input, null, '');
            return true;
        }
    }
    
    // Se for pessoa física e estiver vazio
    if (tipoPessoa === 'PF' && !cpf) {
        setValidationState(input, false, 'CPF é obrigatório para Pessoa Física');
        return false;
    }
    
    // Se tem valor, valida
    if (cpf) {
        if (cpf.length !== 11) {
            setValidationState(input, false, 'CPF deve ter 11 dígitos');
            return false;
        }
        
        if (!validarDigitosCPF(cpf)) {
            setValidationState(input, false, 'CPF inválido');
            return false;
        }
        
        // Verifica se CPF já existe
        verificarDocumentoExistente(cpf, 'CPF', input);
        setValidationState(input, true, 'CPF válido');
    }
    
    return true;
}

/**
 * Valida dígitos verificadores do CPF
 */
/**

/**
 * Verifica se documento já existe
 */
function verificarDocumentoExistente(documento, tipo, input) {
    // Por enquanto, só valida local até implementar o backend
    if (documento && ((tipo === 'CPF' && documento.length === 11) || (tipo === 'CNPJ' && documento.length === 14))) {
        setValidationState(input, true, `${tipo} válido`);
    }
    
    // Implementar depois com o backend
    /*
    fetch(`verificar_documento_fornecedor.php?documento=${encodeURIComponent(documento)}&tipo=${tipo}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Erro na resposta do servidor');
            }
            return response.json();
        })
        .then(data => {
            if (data.existe) {
                setValidationState(input, false, `${tipo} já cadastrado para outro fornecedor`);
            } else {
                setValidationState(input, true, `${tipo} disponível`);
            }
        })
        .catch(error => {
            console.error('Erro ao verificar documento:', error);
            // Em caso de erro, assume que está ok
            setValidationState(input, true, `${tipo} válido`);
        });
    */
}
</script>

</body>
</html>