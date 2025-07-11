<?php
// ===========================================
// CADASTRO DE CLIENTES - LICITASIS
// Versão corrigida sem erros de headers
// ===========================================

// IMPORTANTE: Não pode haver NENHUMA saída antes desta linha
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inclui o sistema de permissões e auditoria
include('db.php');
include('permissions.php');
include('includes/audit.php');

$permissionManager = initPermissions($pdo);

// Verifica se o usuário tem permissão para cadastrar clientes
$permissionManager->requirePermission('clientes', 'create');

// Registra acesso à página
logUserAction('READ', 'clientes_cadastro');

// Inicialização das variáveis
$error = "";
$success = false;



// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Obtém os dados do formulário
        $cnpj = trim($_POST['cnpj'] ?? '');
        $nome_orgaos = trim($_POST['nome_orgaos'] ?? '');
        $uasg = trim($_POST['uasg'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');

        // Verifica se o campo 'uasg' foi preenchido
        if (empty($uasg)) {
            $error = "O campo UASG é obrigatório!";
        } elseif (empty($nome_orgaos)) {
            $error = "O campo Nome do Órgão é obrigatório!";
        } else {
            // Concatena múltiplos telefones
            $telefones = '';
            if (isset($_POST['telefone']) && is_array($_POST['telefone'])) {
                $telefones = implode(' / ', array_filter(array_map('trim', $_POST['telefone'])));
            }

            // Concatena múltiplos emails
            $emails = '';
            if (isset($_POST['email']) && is_array($_POST['email'])) {
                $emails = implode(' / ', array_filter(array_map('trim', $_POST['email'])));
            }

            // Valida CNPJ se preenchido
          if (!empty($cnpj)) {
    $cnpj_numeros = preg_replace('/[^0-9]/', '', $cnpj);
    if (strlen($cnpj_numeros) != 14) {
        $error = "CNPJ deve conter exatamente 14 dígitos!";
    } else {
        // Verifica se o CNPJ já existe
        $stmt_check_cnpj = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE cnpj = :cnpj");
        $stmt_check_cnpj->bindParam(':cnpj', $cnpj);
        $stmt_check_cnpj->execute();
        
        if ($stmt_check_cnpj->fetchColumn() > 0) {
            $error = "Este CNPJ já está cadastrado no sistema!";
        }
    }
}

            // Verifica se o CNPJ ou a UASG já existe no banco
            if (empty($error)) {
                $sql_check_cliente = "SELECT COUNT(*) FROM clientes WHERE (cnpj = :cnpj AND :cnpj != '') OR uasg = :uasg";
                $stmt_check_cliente = $pdo->prepare($sql_check_cliente);
                $stmt_check_cliente->bindParam(':cnpj', $cnpj);
                $stmt_check_cliente->bindParam(':uasg', $uasg);
                $stmt_check_cliente->execute();
                $count_cliente = $stmt_check_cliente->fetchColumn();

                if ($count_cliente > 0) {
                    $error = "CNPJ ou UASG já cadastrados no sistema!";
                }
            }
        }

        // Se não houver erro, realiza o cadastro
        if (empty($error)) {
            // Inserir cliente na tabela 'clientes'
            $sql_cliente = "INSERT INTO clientes (cnpj, nome_orgaos, uasg, endereco, observacoes, telefone, email, created_at) 
                            VALUES (:cnpj, :nome_orgaos, :uasg, :endereco, :observacoes, :telefone, :email, NOW())";
            $stmt_cliente = $pdo->prepare($sql_cliente);
            $stmt_cliente->bindParam(':cnpj', $cnpj, PDO::PARAM_STR);
            $stmt_cliente->bindParam(':nome_orgaos', $nome_orgaos, PDO::PARAM_STR);
            $stmt_cliente->bindParam(':uasg', $uasg, PDO::PARAM_STR);
            $stmt_cliente->bindParam(':endereco', $endereco, PDO::PARAM_STR);
            $stmt_cliente->bindParam(':observacoes', $observacoes, PDO::PARAM_STR);
            $stmt_cliente->bindParam(':telefone', $telefones, PDO::PARAM_STR);
            $stmt_cliente->bindParam(':email', $emails, PDO::PARAM_STR);
            
            if ($stmt_cliente->execute()) {
                $cliente_id = $pdo->lastInsertId();

                // Registra auditoria
                logUserAction('CREATE', 'clientes', $cliente_id, [
                    'cnpj' => $cnpj,
                    'nome_orgaos' => $nome_orgaos,
                    'uasg' => $uasg,
                    'endereco' => $endereco,
                    'telefone' => $telefones,
                    'email' => $emails
                ]);

                $success = true;
            } else {
                $error = "Erro ao salvar cliente no banco de dados!";
            }
        }
    } catch (Exception $e) {
        $error = "Erro ao cadastrar cliente: " . $e->getMessage();
        error_log("Erro no cadastro de cliente: " . $e->getMessage());
    }
}

// Inclui o template de header APÓS todo o processamento
include('includes/header_template.php');
renderHeader("Cadastro de Cliente - LicitaSis", "clientes");
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Cliente - LicitaSis</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        /* Reset e variáveis CSS - compatibilidade com o sistema */
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

        h2 i {
            color: var(--secondary-color);
            font-size: 1.8rem;
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

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }

        /* Campo com botão de ação */
        .input-group {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .input-group .form-control {
            flex: 1;
        }

        .action-button {
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            font-size: 1rem;
        }

        .action-button:hover {
            background: var(--secondary-dark);
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.3);
        }

        .action-button:disabled {
            background: var(--medium-gray);
            cursor: not-allowed;
            transform: none;
        }

        /* Campos dinâmicos */
        .dynamic-fields {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .field-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .field-container .form-control {
            flex: 1;
        }

        .remove-field-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .remove-field-btn:hover {
            background: #c82333;
            transform: scale(1.05);
        }

        .add-field-btn {
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            font-size: 1.25rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            margin-top: 0.5rem;
            align-self: flex-start;
        }

        .add-field-btn:hover {
            background: var(--secondary-dark);
            transform: scale(1.1);
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.3);
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

        /* Campo obrigatório */
        .required::after {
            content: ' *';
            color: var(--danger-color);
            font-weight: bold;
        }

        /* Loading state */
        .loading .form-control {
            background: var(--light-gray);
            cursor: wait;
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

        @keyframes fadeInModal {
            from { opacity: 0; }
            to { opacity: 1; }
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

        @keyframes slideInUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
        }

        .modal-header i {
            font-size: 2rem;
        }

        .modal-body {
            padding: 2rem;
            text-align: center;
        }

        .modal-body p {
            font-size: 1.1rem;
            color: var(--dark-gray);
            margin-bottom: 1rem;
        }

        .modal-body p:nth-child(2) {
            color: var(--medium-gray);
            font-size: 0.95rem;
            margin-bottom: 2rem;
        }

        /* Botões do modal */
        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .modal-buttons .btn-primary,
        .modal-buttons .btn-secondary {
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 140px;
            justify-content: center;
        }

        .modal-buttons .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        .modal-buttons .btn-primary:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        .modal-buttons .btn-secondary {
            background: linear-gradient(135deg, var(--medium-gray) 0%, #5a6268 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
        }

        .modal-buttons .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268 0%, var(--medium-gray) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(108, 117, 125, 0.3);
        }

        /* Responsividade */
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
                margin: 5% auto;
                width: 95%;
            }

            .modal-header {
                padding: 1.5rem;
            }

            .modal-body {
                padding: 1.5rem;
            }

            .modal-buttons {
                flex-direction: column;
            }
            
            .modal-buttons .btn-primary,
            .modal-buttons .btn-secondary {
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
                padding: 0.75rem;
                font-size: 0.9rem;
            }

            .action-button {
                width: 40px;
                height: 40px;
            }

            .add-field-btn {
                width: 35px;
                height: 35px;
                font-size: 1rem;
            }
        }

        /* Animações de entrada */
        .form-group {
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }

        .form-group:nth-child(1) { animation-delay: 0.1s; }
        .form-group:nth-child(2) { animation-delay: 0.2s; }
        .form-group:nth-child(3) { animation-delay: 0.3s; }
        .form-group:nth-child(4) { animation-delay: 0.4s; }
        .form-group:nth-child(5) { animation-delay: 0.5s; }
        .form-group:nth-child(6) { animation-delay: 0.6s; }
        .form-group:nth-child(7) { animation-delay: 0.7s; }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Hover effects para mobile */
        @media (hover: none) {
            .btn:active {
                transform: scale(0.98);
            }
            
            .form-control:focus {
                transform: none;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>
        <i class="fas fa-user-plus"></i>
        Cadastro de Cliente
    </h2>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form class="form-container" action="cadastrar_clientes.php" method="POST" onsubmit="return validarFormulario()">
        
        <div class="form-row">
            <div class="form-group">
                <label for="cnpj">
                    <i class="fas fa-id-card"></i>
                    CNPJ
                </label>
                <div class="input-group">
                    <input type="text" 
                           id="cnpj" 
                           name="cnpj" 
                           class="form-control" 
                           placeholder="XX.XXX.XXX/XXXX-XX" 
                           value="<?php echo isset($_POST['cnpj']) ? htmlspecialchars($_POST['cnpj']) : ''; ?>"
                           oninput="limitarCNPJ(event)" 
                           onblur="consultarCNPJ()">
                    <button type="button" class="action-button" onclick="consultarCNPJ()" title="Consultar CNPJ">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="uasg" class="required">
                    <i class="fas fa-hashtag"></i>
                    UASG
                </label>
                <input type="text" 
                       id="uasg" 
                       name="uasg" 
                       class="form-control" 
                       placeholder="Digite o código UASG"
                       value="<?php echo isset($_POST['uasg']) ? htmlspecialchars($_POST['uasg']) : ''; ?>"
                       required>
            </div>
        </div>

        <div class="form-group">
            <label for="nome_orgaos" class="required">
                <i class="fas fa-building"></i>
                Nome do Órgão
            </label>
            <input type="text" 
                   id="nome_orgaos" 
                   name="nome_orgaos" 
                   class="form-control" 
                   placeholder="Nome completo do órgão"
                   value="<?php echo isset($_POST['nome_orgaos']) ? htmlspecialchars($_POST['nome_orgaos']) : ''; ?>"
                   required>
        </div>

        <div class="form-group">
            <label for="endereco">
                <i class="fas fa-map-marker-alt"></i>
                Endereço
            </label>
            <input type="text" 
                   id="endereco" 
                   name="endereco" 
                   class="form-control" 
                   placeholder="Endereço completo"
                   value="<?php echo isset($_POST['endereco']) ? htmlspecialchars($_POST['endereco']) : ''; ?>">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="telefone">
                    <i class="fas fa-phone"></i>
                    Telefone(s)
                </label>
                <div class="dynamic-fields" id="telefoneFields">
                    <div class="field-container">
                        <input type="tel" 
                               id="telefone" 
                               name="telefone[]" 
                               class="form-control" 
                               placeholder="(00) 00000-0000">
                    </div>
                </div>
                <button type="button" class="add-field-btn" onclick="addTelefoneField()" title="Adicionar telefone">
                    <i class="fas fa-plus"></i>
                </button>
            </div>

            <div class="form-group">
                <label for="email">
                    <i class="fas fa-envelope"></i>
                    E-mail(s)
                </label>
                <div class="dynamic-fields" id="emailFields">
                    <div class="field-container">
                        <input type="email" 
                               id="email" 
                               name="email[]" 
                               class="form-control" 
                               placeholder="email@exemplo.com">
                    </div>
                </div>
                <button type="button" class="add-field-btn" onclick="addEmailField()" title="Adicionar e-mail">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
        </div>

        <div class="form-group">
            <label for="observacoes">
                <i class="fas fa-sticky-note"></i>
                Observações
            </label>
            <textarea id="observacoes" 
                      name="observacoes" 
                      class="form-control" 
                      placeholder="Observações adicionais sobre o cliente..."
                      rows="4"><?php echo isset($_POST['observacoes']) ? htmlspecialchars($_POST['observacoes']) : ''; ?></textarea>
        </div>

        <div class="btn-container">
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-save"></i>
                Cadastrar Cliente
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
                Cliente Cadastrado!
            </h3>
        </div>
        <div class="modal-body">
            <p>O cliente foi cadastrado com sucesso no sistema.</p>
            <p>Deseja acessar a página de consulta de clientes?</p>
            <div class="modal-buttons">
                <button class="btn-primary" onclick="goToConsulta()">
                    <i class="fas fa-search"></i>
                    Sim, Ver Clientes
                </button>
                <button class="btn-secondary" onclick="closeModal()">
                    <i class="fas fa-plus"></i>
                    Cadastrar Outro
                </button>
            </div>
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
            const fieldContainer = document.createElement('div');
            fieldContainer.className = 'field-container';
            
            const newField = document.createElement('input');
            newField.type = 'tel';
            newField.name = 'telefone[]';
            newField.className = 'form-control';
            newField.placeholder = `Telefone ${telefoneCount}`;
            
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-field-btn';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.title = 'Remover telefone';
            removeBtn.onclick = function() {
                fieldContainer.remove();
                telefoneCount--;
            };
            
            fieldContainer.appendChild(newField);
            fieldContainer.appendChild(removeBtn);
            container.appendChild(fieldContainer);
            
            // Aplica formatação ao novo campo
            newField.addEventListener('input', function() {
                formatTelefone(this);
            });
            
            // Foca no novo campo
            newField.focus();
        } else {
            showToast('Máximo de 10 telefones permitidos.', 'warning');
        }
    }

    // Função para adicionar campo de email dinamicamente
    function addEmailField() {
        if (emailCount < 10) {
            emailCount++;
            const container = document.getElementById('emailFields');
            const fieldContainer = document.createElement('div');
            fieldContainer.className = 'field-container';
            
            const newField = document.createElement('input');
            newField.type = 'email';
            newField.name = 'email[]';
            newField.className = 'form-control';
            newField.placeholder = `E-mail ${emailCount}`;
            
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-field-btn';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.title = 'Remover e-mail';
            removeBtn.onclick = function() {
                fieldContainer.remove();
                emailCount--;
            };
            
            fieldContainer.appendChild(newField);
            fieldContainer.appendChild(removeBtn);
            container.appendChild(fieldContainer);
            
            // Foca no novo campo
            newField.focus();
        } else {
            showToast('Máximo de 10 e-mails permitidos.', 'warning');
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

    // Função para consultar o CNPJ via API
    function consultarCNPJ() {
        const cnpj = document.getElementById("cnpj").value.replace(/[^\d]/g, '');
        const nomeOrgaoField = document.getElementById("nome_orgaos");
        const enderecoField = document.getElementById("endereco");
        const telefoneField = document.getElementById("telefone");
        const emailField = document.getElementById("email");
        
        if (cnpj.length === 14) {
            // Mostra indicador de carregamento
            nomeOrgaoField.value = "Consultando...";
            enderecoField.value = "Consultando...";
            nomeOrgaoField.classList.add('loading');
            enderecoField.classList.add('loading');
            
            // Desabilita o botão temporariamente
            const btnConsultar = document.querySelector('.action-button');
            btnConsultar.disabled = true;
            btnConsultar.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            // Faz a requisição para a API de consulta de CNPJ
            fetch(`consultar_cnpj.php?cnpj=${cnpj}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Erro na requisição');
                    }
                    return response.json();
                })
                .then(data => {
                    // Verifica se a consulta foi bem-sucedida
                    if (data.status === 'OK' && !data.error) {
                        // Preenche o campo nome com a razão social
                        nomeOrgaoField.value = data.nome || data.fantasia || '';
                        
                        // Monta o endereço completo
                        let endereco = '';
                        if (data.logradouro) {
                            endereco = data.logradouro;
                            if (data.numero && data.numero !== 'S/N') {
                                endereco += ', ' + data.numero;
                            }
                            if (data.complemento) {
                                endereco += ', ' + data.complemento;
                            }
                            if (data.bairro) {
                                endereco += ' - ' + data.bairro;
                            }
                            if (data.municipio) {
                                endereco += ' - ' + data.municipio;
                            }
                            if (data.uf) {
                                endereco += '/' + data.uf;
                            }
                            if (data.cep) {
                                endereco += ' - CEP: ' + data.cep;
                            }
                        }
                        enderecoField.value = endereco;
                        
                        // Preenche telefone se existir e estiver válido
                        if (data.telefone && data.telefone.trim() !== '') {
                            telefoneField.value = formatarTelefoneRecebido(data.telefone);
                        }
                        
                        // Preenche email se existir e estiver válido
                        if (data.email && data.email.trim() !== '' && isValidEmail(data.email)) {
                            emailField.value = data.email;
                        }
                        
                        // Mostra informações adicionais na mensagem de sucesso
                        let mensagem = 'Dados do CNPJ carregados com sucesso!';
                        if (data.situacao) {
                            mensagem += ` Situação: ${data.situacao}`;
                        }
                        
                        showToast(mensagem, 'success');
                        
                        // Foca no próximo campo obrigatório (UASG)
                        setTimeout(() => {
                            document.getElementById('uasg').focus();
                        }, 500);
                        
                    } else {
                        // Limpa os campos se o CNPJ não for encontrado
                        nomeOrgaoField.value = "";
                        enderecoField.value = "";
                        
                        let errorMessage = 'CNPJ não encontrado ou inválido.';
                        if (data.message) {
                            errorMessage = data.message;
                        } else if (data.error) {
                            errorMessage = data.error;
                        }
                        
                        showToast(errorMessage, 'warning');
                    }
                })
                .catch(error => {
                    // Limpa os campos em caso de erro na requisição
                    nomeOrgaoField.value = "";
                    enderecoField.value = "";
                    
                    console.error("Erro na consulta CNPJ: ", error);
                    showToast('Erro ao consultar CNPJ. Verifique sua conexão com a internet.', 'error');
                })
                .finally(() => {
                    // Remove indicadores de carregamento
                    nomeOrgaoField.classList.remove('loading');
                    enderecoField.classList.remove('loading');
                    btnConsultar.disabled = false;
                    btnConsultar.innerHTML = '<i class="fas fa-search"></i>';
                });
            
        } else if (cnpj.length > 0) {
            showToast('Digite um CNPJ válido com 14 dígitos.', 'warning');
        }
    }

    // Função auxiliar para formatar telefone recebido da API
    function formatarTelefoneRecebido(telefone) {
        // Remove todos os caracteres não numéricos
        let numbers = telefone.replace(/\D/g, '');
        
        // Se tem 11 dígitos (celular com 9)
        if (numbers.length === 11) {
            return numbers.replace(/^(\d{2})(\d{5})(\d{4})$/, "($1) $2-$3");
        }
        // Se tem 10 dígitos (fixo)
        else if (numbers.length === 10) {
            return numbers.replace(/^(\d{2})(\d{4})(\d{4})$/, "($1) $2-$3");
        }
        // Retorna o telefone original se não conseguir formatar
        return telefone;
    }

    // Função para mostrar notificações toast
    function showToast(message, type = 'info') {
        // Remove toast anterior se existir
        const existingToast = document.querySelector('.toast');
        if (existingToast) {
            existingToast.remove();
        }

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                <i class="fas fa-${getToastIcon(type)}"></i>
                <span>${message}</span>
            </div>
        `;

        // Estilos do toast
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${getToastColor(type)};
            color: white;
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            z-index: 1001;
            animation: slideInRight 0.3s ease;
            font-weight: 500;
            min-width: 300px;
            max-width: 400px;
        `;

        document.body.appendChild(toast);

        // Remove após 4 segundos
        setTimeout(() => {
            toast.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    function getToastIcon(type) {
        const icons = {
            success: 'check-circle',
            error: 'exclamation-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        return icons[type] || 'info-circle';
    }

    function getToastColor(type) {
        const colors = {
            success: 'var(--success-color)',
            error: 'var(--danger-color)',
            warning: 'var(--warning-color)',
            info: 'var(--info-color)'
        };
        return colors[type] || 'var(--info-color)';
    }

    // Animações CSS para toast
    const toastStyles = document.createElement('style');
    toastStyles.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        .toast-content {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
    `;
    document.head.appendChild(toastStyles);

    // Função para ir para consulta de clientes
    function goToConsulta() {
        document.getElementById('successModal').style.display = 'none';
        document.body.style.overflow = 'auto';
        
        // Limpa dados temporários
        localStorage.removeItem('clienteForm');
        
        // Redireciona para consulta
        window.location.href = 'consultar_clientes.php?success=' + encodeURIComponent('Cliente cadastrado com sucesso!');
    }

    // Função para fechar modal e continuar cadastrando
    function closeModal() {
        document.getElementById('successModal').style.display = 'none';
        document.body.style.overflow = 'auto';
        
        // Limpa o formulário para novo cadastro
        limparFormulario();
        
        // Foca no primeiro campo
        setTimeout(() => {
            document.getElementById('cnpj').focus();
        }, 100);
        
        showToast('Formulário limpo. Pronto para novo cadastro!', 'info');
    }

    // Função para limpar formulário
    function limparFormulario() {
        document.querySelector('form').reset();
        telefoneCount = 1;
        emailCount = 1;
        
        // Remove campos extras
        const telefoneContainer = document.getElementById('telefoneFields');
        const emailContainer = document.getElementById('emailFields');
        
        telefoneContainer.innerHTML = `
            <div class="field-container">
                <input type="tel" id="telefone" name="telefone[]" class="form-control" placeholder="(00) 00000-0000">
            </div>
        `;
        
        emailContainer.innerHTML = `
            <div class="field-container">
                <input type="email" id="email" name="email[]" class="form-control" placeholder="email@exemplo.com">
            </div>
        `;
        
        // Limpa dados temporários
        localStorage.removeItem('clienteForm');
        
        // Reaplica formatação de telefone
        applyTelefoneFormatting();
        
        // Remove estilos de validação
        document.querySelectorAll('.form-control').forEach(input => {
            input.style.borderColor = '';
        });
    }

    // Função para validar o formulário
    function validarFormulario() {
        const uasg = document.getElementById("uasg").value.trim();
        const nomeOrgao = document.getElementById("nome_orgaos").value.trim();
        
        // Verifica campos obrigatórios
        if (!uasg) {
            showToast('O campo UASG é obrigatório!', 'error');
            document.getElementById("uasg").focus();
            return false;
        }
        
        if (!nomeOrgao) {
            showToast('O campo Nome do Órgão é obrigatório!', 'error');
            document.getElementById("nome_orgaos").focus();
            return false;
        }
        
        // Validação adicional do CNPJ se preenchido
        const cnpj = document.getElementById("cnpj").value.replace(/[^\d]/g, '');
        if (cnpj && cnpj.length !== 14) {
            showToast('CNPJ deve ter 14 dígitos!', 'error');
            document.getElementById("cnpj").focus();
            return false;
        }
        
        // Validação de e-mails
        const emails = document.querySelectorAll('input[name="email[]"]');
        for (let email of emails) {
            if (email.value && !isValidEmail(email.value)) {
                showToast('Digite um e-mail válido!', 'error');
                email.focus();
                return false;
            }
        }
        
        // Mostra loading no botão de submit
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cadastrando...';
        
        return true;
    }

    // Função para validar e-mail
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // Função para formatar telefone
    function formatTelefone(input) {
        let telefone = input.value.replace(/\D/g, '');
        
        if (telefone.length > 11) {
            telefone = telefone.substring(0, 11);
        }
        
        if (telefone.length > 10) {
            telefone = telefone.replace(/^(\d{2})(\d{5})(\d{4})$/, "($1) $2-$3");
        } else if (telefone.length > 6) {
            telefone = telefone.replace(/^(\d{2})(\d{4})(\d{0,4})$/, "($1) $2-$3");
        } else if (telefone.length > 2) {
            telefone = telefone.replace(/^(\d{2})(\d{0,5})$/, "($1) $2");
        }
        
        input.value = telefone;
    }

    // Aplicar formatação nos campos de telefone
    function applyTelefoneFormatting() {
        document.addEventListener('input', function(e) {
            if (e.target.type === 'tel') {
                formatTelefone(e.target);
            }
        });
    }

    // Inicialização quando a página carrega
    document.addEventListener('DOMContentLoaded', function() {
        // Aplica formatação de telefone
        applyTelefoneFormatting();
        
        // Foca no primeiro campo
        document.getElementById('cnpj').focus();
        
        // Adiciona validação em tempo real
        const form = document.querySelector('form');
        const inputs = form.querySelectorAll('input[required]');
        
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                if (!this.value.trim()) {
                    this.style.borderColor = 'var(--danger-color)';
                } else {
                    this.style.borderColor = 'var(--success-color)';
                }
            });
            
            input.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.style.borderColor = 'var(--success-color)';
                } else {
                    this.style.borderColor = 'var(--border-color)';
                }
            });
        });
        
        // Previne submit duplo
        form.addEventListener('submit', function() {
            setTimeout(() => {
                const submitBtn = document.getElementById('submitBtn');
                submitBtn.disabled = true;
            }, 100);
        });
        
        console.log('Cadastro de Clientes carregado com sucesso!');
    });

    // Inicializa o modal se o cadastro foi bem-sucedido
    window.onload = function() {
        <?php if ($success) { echo "openModal();"; } ?>
    }

    function openModal() {
        document.getElementById('successModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        // Foca no primeiro botão
        setTimeout(() => {
            document.querySelector('.modal-buttons .btn-primary').focus();
        }, 300);
    }

    // Fecha modal ao clicar fora dele
    window.onclick = function(event) {
        const modal = document.getElementById('successModal');
        if (event.target === modal) {
            closeModal();
        }
    }

    // Tecla ESC para fechar modal, Enter para ir para consulta
    document.addEventListener('keydown', function(event) {
        const modal = document.getElementById('successModal');
        if (modal.style.display === 'block') {
            if (event.key === 'Escape') {
                closeModal();
            } else if (event.key === 'Enter') {
                goToConsulta();
            }
        }
    });

    // Auto-save no localStorage (dados temporários)
    function autoSave() {
        const formData = new FormData(document.querySelector('form'));
        const data = {};
        
        for (let [key, value] of formData.entries()) {
            if (data[key]) {
                if (Array.isArray(data[key])) {
                    data[key].push(value);
                } else {
                    data[key] = [data[key], value];
                }
            } else {
                data[key] = value;
            }
        }
        
        localStorage.setItem('clienteForm', JSON.stringify(data));
    }

    // Auto-save a cada 30 segundos
    setInterval(autoSave, 30000);

    async function verificarCNPJExistente(cnpj) {
    if (!cnpj) return;
    
    const cnpjLimpo = cnpj.replace(/[^\d]/g, '');
    if (cnpjLimpo.length !== 14) return;

    try {
        const response = await fetch('verificar_cnpj.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `cnpj=${cnpjLimpo}`
        });

        const data = await response.json();
        
        if (data.exists) {
            showToast('Este CNPJ já está cadastrado no sistema!', 'error');
            document.getElementById('cnpj').style.borderColor = 'var(--danger-color)';
            document.getElementById('submitBtn').disabled = true;
        } else {
            document.getElementById('cnpj').style.borderColor = 'var(--success-color)';
            document.getElementById('submitBtn').disabled = false;
        }
    } catch (error) {
        console.error('Erro ao verificar CNPJ:', error);
    }
}

// Modifique o evento de input do CNPJ:
document.getElementById('cnpj').addEventListener('blur', function() {
    verificarCNPJExistente(this.value);
});
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