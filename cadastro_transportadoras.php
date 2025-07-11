<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

include('db.php');
include('permissions.php');
include('includes/audit.php');

$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('transportadoras', 'create');
logUserAction('READ', 'transportadoras_cadastro');

$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';
$error = "";
$success = false;

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        require_once('db.php');

        // Obtém os dados do formulário
        $codigo = isset($_POST['codigo']) ? trim($_POST['codigo']) : null;
        $cnpj = isset($_POST['cnpj']) ? trim($_POST['cnpj']) : null;
        $nome = isset($_POST['nome']) ? trim($_POST['nome']) : null;
        $endereco = isset($_POST['endereco']) ? trim($_POST['endereco']) : null;
        $telefone = isset($_POST['telefone']) ? trim($_POST['telefone']) : null;
        $email = isset($_POST['email']) ? trim($_POST['email']) : null;
        $observacoes = isset($_POST['observacoes']) ? trim($_POST['observacoes']) : null;

        // Validação básica dos campos obrigatórios
        if (empty($codigo) || empty($cnpj) || empty($nome)) {
            throw new Exception("Preencha todos os campos obrigatórios: Código, CNPJ e Nome.");
        }

        // Validação do CNPJ
        $cnpj_limpo = preg_replace('/[^0-9]/', '', $cnpj);
        if (strlen($cnpj_limpo) !== 14) {
            throw new Exception("CNPJ deve ter exatamente 14 dígitos.");
        }

        // Validação de email se fornecido
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email fornecido não é válido.");
        }

        // Verifica se o código já existe
        $sqlCheck = "SELECT nome FROM transportadora WHERE codigo = :codigo LIMIT 1";
        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->bindParam(':codigo', $codigo, PDO::PARAM_STR);
        $stmtCheck->execute();
        $checkResult = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($checkResult) {
            throw new Exception("O código '{$codigo}' já existe para a transportadora: {$checkResult['nome']}. Use um código diferente.");
        }

        // Verifica se o CNPJ já existe
        $sqlCheckCNPJ = "SELECT nome FROM transportadora WHERE cnpj = :cnpj LIMIT 1";
        $stmtCheckCNPJ = $pdo->prepare($sqlCheckCNPJ);
        $stmtCheckCNPJ->bindParam(':cnpj', $cnpj_limpo, PDO::PARAM_STR);
        $stmtCheckCNPJ->execute();
        $checkCNPJResult = $stmtCheckCNPJ->fetch(PDO::FETCH_ASSOC);

        if ($checkCNPJResult) {
            throw new Exception("O CNPJ '{$cnpj}' já está cadastrado para a transportadora: {$checkCNPJResult['nome']}.");
        }

        // Insere a transportadora no banco de dados
        $sql = "INSERT INTO transportadora (codigo, cnpj, nome, endereco, telefone, email, observacoes) 
                VALUES (:codigo, :cnpj, :nome, :endereco, :telefone, :email, :observacoes)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':codigo', $codigo, PDO::PARAM_STR);
        $stmt->bindParam(':cnpj', $cnpj_limpo, PDO::PARAM_STR);
        $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
        $stmt->bindParam(':endereco', $endereco, PDO::PARAM_STR);
        $stmt->bindParam(':telefone', $telefone, PDO::PARAM_STR);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':observacoes', $observacoes, PDO::PARAM_STR);

        $stmt->execute();

        // Registra auditoria
        logUserAction('CREATE', 'transportadoras', $pdo->lastInsertId(), [
            'codigo' => $codigo,
            'cnpj' => $cnpj_limpo,
            'nome' => $nome,
            'endereco' => $endereco,
            'telefone' => $telefone,
            'email' => $email
        ]);

        $success = true;
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = "Erro ao cadastrar a transportadora: " . $e->getMessage();
    }
}

include('includes/header_template.php');
renderHeader("Cadastro de Transportadora - LicitaSis", "transportadoras");
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Transportadora - LicitaSis</title>
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
            max-width: 1000px;
            margin: 2.5rem auto;
            padding: 2.5rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
            animation: fadeIn 0.5s ease;
            position: relative;
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
            margin-bottom: 0.5rem;
            color: var(--primary-color);
            font-weight: 600;
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

        input, textarea {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background: white;
        }

        input:focus, textarea:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
            transform: translateY(-1px);
        }

        input:hover, textarea:hover {
            border-color: var(--secondary-color);
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        /* Estados de input */
        .form-control.loading {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        .form-control.success-state {
            border-color: var(--success-color);
            background: rgba(40, 167, 69, 0.05);
        }

        .form-control.error-state {
            border-color: var(--danger-color);
            background: rgba(220, 53, 69, 0.05);
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
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
            min-width: 180px;
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

        /* Mensagens de alerta */
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

        .alert-error {
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

        /* Modal styles */
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

        /* Input helpers */
        .input-helper {
            font-size: 0.85rem;
            color: var(--medium-gray);
            margin-top: 0.25rem;
            display: block;
        }

        .input-helper.error {
            color: var(--danger-color);
        }

        .input-helper.success {
            color: var(--success-color);
        }

        /* CNPJ validation indicator */
        .cnpj-status {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2rem;
        }

        .cnpj-status.loading {
            color: var(--info-color);
            animation: spin 1s linear infinite;
        }

        .cnpj-status.success {
            color: var(--success-color);
        }

        .cnpj-status.error {
            color: var(--danger-color);
        }

        @keyframes spin {
            0% { transform: translateY(-50%) rotate(0deg); }
            100% { transform: translateY(-50%) rotate(360deg); }
        }

        /* Responsive design */
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

            .modal-buttons {
                flex-direction: column;
            }

            nav {
                flex-direction: column;
            }

            nav a {
                padding: 0.6rem 1rem;
            }

            .dropdown-content {
                position: static;
                display: none;
                width: 100%;
                box-shadow: none;
                border-radius: 0;
            }

            .dropdown:hover .dropdown-content {
                display: block;
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
                font-size: 1.5rem;
            }

            input, textarea {
                padding: 0.75rem 0.875rem;
                font-size: 0.95rem;
            }

            .btn {
                padding: 0.875rem 1rem;
                font-size: 0.95rem;
            }
        }

        /* Animações de entrada para campos */
        .form-group {
            animation: slideInUp 0.3s ease;
            animation-fill-mode: both;
        }

        .form-group:nth-child(1) { animation-delay: 0.1s; }
        .form-group:nth-child(2) { animation-delay: 0.2s; }
        .form-group:nth-child(3) { animation-delay: 0.3s; }
        .form-group:nth-child(4) { animation-delay: 0.4s; }
        .form-group:nth-child(5) { animation-delay: 0.5s; }
        .form-group:nth-child(6) { animation-delay: 0.6s; }

        @keyframes slideInUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Efeitos de hover para inputs */
        .form-group:hover input,
        .form-group:hover textarea {
            border-color: var(--primary-light);
        }

        /* Loading state para botão */
        .btn.loading {
            position: relative;
            color: transparent;
        }

        .btn.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s linear infinite;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>
        <i class="fas fa-truck"></i>
        Cadastro de Transportadora
    </h2>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form class="form-container" action="cadastro_transportadoras.php" method="POST" onsubmit="return validarFormulario()">
        <div class="form-row">
            <div class="form-group">
                <label for="codigo" class="required">
                    <i class="fas fa-barcode"></i>
                    Código
                </label>
                <input type="text" 
                       id="codigo" 
                       name="codigo" 
                       class="form-control"
                       placeholder="Digite o código da transportadora"
                       value="<?php echo $success ? '' : (isset($_POST['codigo']) ? htmlspecialchars($_POST['codigo']) : ''); ?>"
                       required>
                <small class="input-helper">Código único para identificação da transportadora</small>
            </div>

            <div class="form-group">
                <label for="cnpj" class="required">
                    <i class="fas fa-id-card"></i>
                    CNPJ
                </label>
                <div style="position: relative;">
                    <input type="text" 
                           id="cnpj" 
                           name="cnpj" 
                           class="form-control"
                           placeholder="00.000.000/0000-00"
                           value="<?php echo $success ? '' : (isset($_POST['cnpj']) ? htmlspecialchars($_POST['cnpj']) : ''); ?>"
                           oninput="formatarCNPJ(this); validarCNPJTime(this)"
                           onblur="consultarCNPJ()"
                           maxlength="18"
                           required>
                    <span id="cnpj-status" class="cnpj-status"></span>
                </div>
                <small class="input-helper" id="cnpj-helper">Digite o CNPJ para buscar dados automaticamente</small>
            </div>
        </div>

        <div class="form-group">
            <label for="nome" class="required">
                <i class="fas fa-building"></i>
                Nome da Transportadora
            </label>
            <input type="text" 
                   id="nome" 
                   name="nome" 
                   class="form-control"
                   placeholder="Nome será preenchido automaticamente ou digite manualmente"
                   value="<?php echo $success ? '' : (isset($_POST['nome']) ? htmlspecialchars($_POST['nome']) : ''); ?>"
                   required>
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
                   placeholder="Endereço será preenchido automaticamente ou digite manualmente"
                   value="<?php echo $success ? '' : (isset($_POST['endereco']) ? htmlspecialchars($_POST['endereco']) : ''); ?>">
            <small class="input-helper">Rua, número, bairro, cidade - UF</small>
        </div>

        <div class="form-row">
            <div class="form-group">
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
                       oninput="formatarTelefone(this)">
                <small class="input-helper">Telefone principal para contato</small>
            </div>

            <div class="form-group">
                <label for="email">
                    <i class="fas fa-envelope"></i>
                    E-mail
                </label>
                <input type="email" 
                       id="email" 
                       name="email" 
                       class="form-control"
                       placeholder="contato@transportadora.com.br"
                       value="<?php echo $success ? '' : (isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''); ?>"
                       onblur="validarEmail(this)">
                <small class="input-helper">E-mail para comunicações e documentos</small>
            </div>
        </div>

        <div class="form-group">
            <label for="observacoes">
                <i class="fas fa-comment-alt"></i>
                Observações
            </label>
            <textarea id="observacoes" 
                      name="observacoes" 
                      class="form-control" 
                      rows="4"
                      placeholder="Informações adicionais sobre a transportadora, especialidades, restrições, etc."><?php echo $success ? '' : (isset($_POST['observacoes']) ? htmlspecialchars($_POST['observacoes']) : ''); ?></textarea>
            <small class="input-helper">Informações complementares sobre serviços, especialidades ou restrições</small>
        </div>

        <div class="btn-container">
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-save"></i>
                Cadastrar Transportadora
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
                Transportadora Cadastrada!
            </h3>
        </div>
        <div class="modal-body">
            <p>A transportadora foi cadastrada com sucesso no sistema.</p>
            <p>Deseja acessar a página de consulta de transportadoras?</p>
            <div class="modal-buttons">
                <button class="btn btn-primary" onclick="goToConsulta()">
                    <i class="fas fa-search"></i>
                    Ver Transportadoras
                </button>
                <button class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-plus"></i>
                    Cadastrar Outra
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let cnpjTimeout = null;
let validationCache = {};

// ===========================================
// FORMATAÇÃO DE CAMPOS
// ===========================================

function formatarCNPJ(input) {
    let value = input.value.replace(/\D/g, '');
    
    if (value.length <= 14) {
        value = value.replace(/^(\d{2})(\d)/, '$1.$2');
        value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
        value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
        value = value.replace(/(\d{4})(\d)/, '$1-$2');
        
        input.value = value;
    }
}

function formatarTelefone(input) {
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

function validarCNPJTime(input) {
    // Limpa timeout anterior
    if (cnpjTimeout) {
        clearTimeout(cnpjTimeout);
    }
    
    const cnpj = input.value.replace(/\D/g, '');
    const statusElement = document.getElementById('cnpj-status');
    const helper = document.getElementById('cnpj-helper');
    
    if (cnpj.length === 0) {
        statusElement.innerHTML = '';
        statusElement.className = 'cnpj-status';
        helper.textContent = 'Digite o CNPJ para buscar dados automaticamente';
        helper.className = 'input-helper';
        input.classList.remove('success-state', 'error-state');
        return;
    }
    
    if (cnpj.length < 14) {
        statusElement.innerHTML = '<i class="fas fa-clock"></i>';
        statusElement.className = 'cnpj-status loading';
        helper.textContent = 'Digite os 14 dígitos do CNPJ...';
        helper.className = 'input-helper';
        input.classList.remove('success-state', 'error-state');
        return;
    }
    
    if (cnpj.length === 14) {
        if (!validarCNPJAlgoritmo(cnpj)) {
            statusElement.innerHTML = '<i class="fas fa-times"></i>';
            statusElement.className = 'cnpj-status error';
            helper.textContent = 'CNPJ inválido. Verifique os dígitos.';
            helper.className = 'input-helper error';
            input.classList.add('error-state');
            input.classList.remove('success-state');
            return;
        }
        
        // CNPJ válido, agendar consulta
        statusElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        statusElement.className = 'cnpj-status loading';
        helper.textContent = 'Consultando CNPJ...';
        helper.className = 'input-helper';
        
        cnpjTimeout = setTimeout(() => {
            consultarCNPJAPI(cnpj);
        }, 800);
    }
}

function validarCNPJAlgoritmo(cnpj) {
    // Algoritmo de validação do CNPJ
    if (cnpj.length !== 14) return false;
    
    // Elimina CNPJs conhecidos como inválidos
    if (/^(\d)\1+$/.test(cnpj)) return false;
    
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

function consultarCNPJAPI(cnpj) {
    const statusElement = document.getElementById('cnpj-status');
    const helper = document.getElementById('cnpj-helper');
    const cnpjInput = document.getElementById('cnpj');
    
    // Verifica cache primeiro
    if (validationCache[cnpj]) {
        preencherDadosCNPJ(validationCache[cnpj]);
        return;
    }
    
    fetch(`consultar_cnpj.php?cnpj=${cnpj}`)
        .then(response => response.json())
        .then(data => {
            validationCache[cnpj] = data;
            
            if (data.status === "OK") {
                statusElement.innerHTML = '<i class="fas fa-check"></i>';
                statusElement.className = 'cnpj-status success';
                helper.textContent = 'CNPJ encontrado! Dados preenchidos automaticamente.';
                helper.className = 'input-helper success';
                cnpjInput.classList.add('success-state');
                cnpjInput.classList.remove('error-state');
                
                preencherDadosCNPJ(data);
                showToast('Dados da empresa encontrados e preenchidos!', 'success');
            } else {
                statusElement.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                statusElement.className = 'cnpj-status error';
                helper.textContent = 'CNPJ não encontrado na Receita Federal.';
                helper.className = 'input-helper error';
                cnpjInput.classList.add('error-state');
                cnpjInput.classList.remove('success-state');
                
                showToast('CNPJ não encontrado. Preencha os dados manualmente.', 'warning');
            }
        })
        .catch(error => {
            console.error('Erro ao consultar CNPJ:', error);
            statusElement.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
            statusElement.className = 'cnpj-status error';
            helper.textContent = 'Erro ao consultar CNPJ. Tente novamente.';
            helper.className = 'input-helper error';
            cnpjInput.classList.remove('success-state', 'error-state');
            
            showToast('Erro ao consultar CNPJ. Verifique a conexão.', 'error');
        });
}

function preencherDadosCNPJ(data) {
    const nomeInput = document.getElementById('nome');
    const enderecoInput = document.getElementById('endereco');
    const telefoneInput = document.getElementById('telefone');
    const emailInput = document.getElementById('email');
    
    if (data.nome && nomeInput) {
        nomeInput.value = data.nome;
        nomeInput.classList.add('success-state');
        setTimeout(() => nomeInput.classList.remove('success-state'), 3000);
    }
    
    if (data.logradouro && enderecoInput) {
        let endereco = data.logradouro;
        if (data.numero) endereco += ', ' + data.numero;
        if (data.bairro) endereco += ' - ' + data.bairro;
        if (data.municipio) endereco += ' - ' + data.municipio;
        if (data.uf) endereco += ' - ' + data.uf;
        
        enderecoInput.value = endereco;
        enderecoInput.classList.add('success-state');
        setTimeout(() => enderecoInput.classList.remove('success-state'), 3000);
    }
    
    if (data.telefone && telefoneInput) {
        telefoneInput.value = data.telefone;
        formatarTelefone(telefoneInput);
        telefoneInput.classList.add('success-state');
        setTimeout(() => telefoneInput.classList.remove('success-state'), 3000);
    }
    
    if (data.email && emailInput) {
        emailInput.value = data.email;
        emailInput.classList.add('success-state');
        setTimeout(() => emailInput.classList.remove('success-state'), 3000);
    }
}

function consultarCNPJ() {
    const cnpj = document.getElementById('cnpj').value.replace(/\D/g, '');
    if (cnpj.length === 14) {
        consultarCNPJAPI(cnpj);
    }
}

function validarEmail(input) {
    const email = input.value.trim();
    const helper = input.nextElementSibling;
    
    if (email === '') {
        input.classList.remove('success-state', 'error-state');
        helper.textContent = 'E-mail para comunicações e documentos';
        helper.className = 'input-helper';
        return;
    }
    
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (emailRegex.test(email)) {
        input.classList.add('success-state');
        input.classList.remove('error-state');
        helper.textContent = 'E-mail válido';
        helper.className = 'input-helper success';
    } else {
        input.classList.add('error-state');
        input.classList.remove('success-state');
        helper.textContent = 'E-mail inválido. Verifique o formato.';
        helper.className = 'input-helper error';
    }
}

// ===========================================
// VALIDAÇÃO DO FORMULÁRIO
// ===========================================

function validarFormulario() {
    const codigo = document.getElementById('codigo').value.trim();
    const cnpj = document.getElementById('cnpj').value.replace(/\D/g, '');
    const nome = document.getElementById('nome').value.trim();
    const email = document.getElementById('email').value.trim();
    
    let valid = true;
    let firstErrorField = null;
    
    // Validação do código
    if (!codigo) {
        markFieldError('codigo', 'O código é obrigatório!');
        if (!firstErrorField) firstErrorField = document.getElementById('codigo');
        valid = false;
    } else {
        markFieldSuccess('codigo');
    }
    
    // Validação do CNPJ
    if (!cnpj) {
        markFieldError('cnpj', 'O CNPJ é obrigatório!');
        if (!firstErrorField) firstErrorField = document.getElementById('cnpj');
        valid = false;
    } else if (cnpj.length !== 14) {
        markFieldError('cnpj', 'CNPJ deve ter 14 dígitos!');
        if (!firstErrorField) firstErrorField = document.getElementById('cnpj');
        valid = false;
    } else if (!validarCNPJAlgoritmo(cnpj)) {
        markFieldError('cnpj', 'CNPJ inválido!');
        if (!firstErrorField) firstErrorField = document.getElementById('cnpj');
        valid = false;
    } else {
        markFieldSuccess('cnpj');
    }
    
    // Validação do nome
    if (!nome) {
        markFieldError('nome', 'O nome da transportadora é obrigatório!');
        if (!firstErrorField) firstErrorField = document.getElementById('nome');
        valid = false;
    } else {
        markFieldSuccess('nome');
    }
    
    // Validação do email (se preenchido)
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        markFieldError('email', 'E-mail inválido!');
        if (!firstErrorField) firstErrorField = document.getElementById('email');
        valid = false;
    } else if (email) {
        markFieldSuccess('email');
    }
    
    if (!valid) {
        if (firstErrorField) {
            firstErrorField.focus();
            firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        showToast('Por favor, corrija os campos destacados em vermelho.', 'error');
        return false;
    }
    
    // Mostra loading no botão
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.classList.add('loading');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cadastrando...';
    
    return true;
}

function markFieldError(fieldId, message) {
    const field = document.getElementById(fieldId);
    const helper = field.nextElementSibling;
    
    field.classList.add('error-state');
    field.classList.remove('success-state');
    
    if (helper && helper.classList.contains('input-helper')) {
        helper.textContent = message;
        helper.className = 'input-helper error';
    }
}

function markFieldSuccess(fieldId) {
    const field = document.getElementById(fieldId);
    const helper = field.nextElementSibling;
    
    field.classList.add('success-state');
    field.classList.remove('error-state');
    
    if (helper && helper.classList.contains('input-helper')) {
        helper.classList.remove('error');
        helper.classList.add('success');
    }
}

// ===========================================
// MODAL E NAVEGAÇÃO
// ===========================================

function openModal() {
    const modal = document.getElementById('successModal');
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    setTimeout(() => {
        const primaryBtn = document.querySelector('.modal-buttons .btn-primary');
        if (primaryBtn) primaryBtn.focus();
    }, 300);
}

function closeModal() {
    const modal = document.getElementById('successModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    limparFormulario();
    
    setTimeout(() => {
        const codigoInput = document.getElementById('codigo');
        if (codigoInput) codigoInput.focus();
    }, 100);
}

function goToConsulta() {
    document.getElementById('successModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    window.location.href = 'consulta_transportadoras.php?success=' + encodeURIComponent('Transportadora cadastrada com sucesso!');
}

function limparFormulario() {
    const form = document.querySelector('form');
    if (form) form.reset();
    
    // Limpa estados visuais
    document.querySelectorAll('.form-control').forEach(input => {
        input.classList.remove('success-state', 'error-state');
    });
    
    document.querySelectorAll('.input-helper').forEach(helper => {
        helper.classList.remove('error', 'success');
    });
    
    // Limpa status do CNPJ
    const cnpjStatus = document.getElementById('cnpj-status');
    const cnpjHelper = document.getElementById('cnpj-helper');
    
    if (cnpjStatus) {
        cnpjStatus.innerHTML = '';
        cnpjStatus.className = 'cnpj-status';
    }
    
    if (cnpjHelper) {
        cnpjHelper.textContent = 'Digite o CNPJ para buscar dados automaticamente';
        cnpjHelper.className = 'input-helper';
    }
    
    // Limpa cache
    validationCache = {};
    
    // Reseta botão
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('loading');
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Cadastrar Transportadora';
    }
}

// ===========================================
// TOAST NOTIFICATIONS
// ===========================================

function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    let backgroundColor, textColor, icon;
    switch(type) {
        case 'success':
            backgroundColor = 'var(--success-color)';
            textColor = 'white';
            icon = 'check-circle';
            break;
        case 'error':
            backgroundColor = 'var(--danger-color)';
            textColor = 'white';
            icon = 'exclamation-circle';
            break;
        case 'warning':
            backgroundColor = 'var(--warning-color)';
            textColor = '#333';
            icon = 'exclamation-triangle';
            break;
        default:
            backgroundColor = 'var(--info-color)';
            textColor = 'white';
            icon = 'info-circle';
    }
    
    toast.innerHTML = `
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-${icon}"></i>
            <span>${message}</span>
        </div>
    `;
    
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${backgroundColor};
        color: ${textColor};
        padding: 1rem 1.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        z-index: 1001;
        animation: slideInRight 0.3s ease;
        font-weight: 500;
        min-width: 300px;
        max-width: 400px;
    `;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// ===========================================
// EVENT LISTENERS
// ===========================================

document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Sistema de Cadastro de Transportadoras carregado!');
    
    // Auto-focus no primeiro campo
    setTimeout(() => {
        const codigoInput = document.getElementById('codigo');
        if (codigoInput) codigoInput.focus();
    }, 300);
    
    // Validação em tempo real para campos específicos
    const codigoInput = document.getElementById('codigo');
    if (codigoInput) {
        codigoInput.addEventListener('blur', function() {
            if (this.value.trim()) {
                this.classList.add('success-state');
                this.classList.remove('error-state');
            }
        });
    }
    
    const nomeInput = document.getElementById('nome');
    if (nomeInput) {
        nomeInput.addEventListener('blur', function() {
            if (this.value.trim()) {
                this.classList.add('success-state');
                this.classList.remove('error-state');
            }
        });
    }
});

// Fecha modal ao clicar fora
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
    
    if (e.key === 'Escape') {
        const modal = document.getElementById('successModal');
        if (modal && modal.style.display === 'block') {
            closeModal();
        }
    }
});

// Estilos para animações de toast
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
`;
document.head.appendChild(toastStyles);

// Verifica se houve sucesso no cadastro
window.onload = function() {
    <?php if ($success): ?>
        openModal();
    <?php endif; ?>
}

console.log('✅ Sistema de Cadastro de Transportadoras LicitaSis:', {
    versao: '2.0 Melhorado',
    funcionalidades: [
        '✅ Validação de CNPJ em tempo real',
        '✅ Consulta automática de dados por CNPJ',
        '✅ Formatação automática de campos',
        '✅ Validação de email',
        '✅ Interface responsiva moderna',
        '✅ Toast notifications',
        '✅ Modal de sucesso interativo',
        '✅ Atalhos de teclado',
        '✅ Cache de consultas',
        '✅ Validação duplicada de CNPJ/código',
        '✅ Estados visuais de loading',
        '✅ Animações suaves'
    ],
    design: 'Moderno com gradientes e sombras',
    responsividade: 'Mobile-first design',
    acessibilidade: 'Suporte a teclado e foco',
    performance: 'Otimizado com debounce e cache'
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