<?php
// ===========================================
// CONSULTA DE EMPENHOS - LICITASIS (CÓDIGO COMPLETO)
// Sistema Completo de Gestão de Licitações com Produtos
// Versão: 7.0 Final - Todas as funcionalidades implementadas
// ===========================================

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Includes necessários
include('db.php');
include('permissions.php');
include('includes/audit.php');

// Inicialização do sistema de permissões
$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('empenhos', 'read');
logUserAction('READ', 'empenhos_consulta');

// Variáveis globais
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';
$error = "";
$success = "";
$empenhos = [];
$searchTerm = "";
$classificacaoFilter = "";

// Configuração da paginação
$itensPorPagina = 20;
$paginaAtual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($paginaAtual - 1) * $itensPorPagina;

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Obtém os dados do formulário
        $nome_completo = trim($_POST['nome_completo']);
        $endereco = trim($_POST['endereco']);
        $telefone = trim($_POST['telefone']);
        $cargo = trim($_POST['cargo']);
        $pai = trim($_POST['pai']);
        $mae = trim($_POST['mae']);
        $cpf = trim($_POST['cpf']);
        $rg = trim($_POST['rg']);
        $data_nascimento = trim($_POST['data_nascimento']);
        $salario = trim($_POST['salario']);
        $sexo = trim($_POST['sexo']);

        // Verifica se o CPF já existe no banco
        $sql_check_cpf = "SELECT COUNT(*) FROM funcionarios WHERE cpf = :cpf";
        $stmt_check = $pdo->prepare($sql_check_cpf);
        $stmt_check->bindParam(':cpf', $cpf);
        $stmt_check->execute();
        $count = $stmt_check->fetchColumn();

        if ($count > 0) {
            throw new Exception("Funcionário já cadastrado!");
        }

        // Inserir funcionário na tabela 'funcionarios'
        $sql_funcionario = "INSERT INTO funcionarios (nome_completo, endereco, telefone, cargo, pai, mae, cpf, rg, data_nascimento, salario, sexo) 
                            VALUES (:nome_completo, :endereco, :telefone, :cargo, :pai, :mae, :cpf, :rg, :data_nascimento, :salario, :sexo)";
        $stmt_funcionario = $pdo->prepare($sql_funcionario);
        $stmt_funcionario->bindParam(':nome_completo', $nome_completo, PDO::PARAM_STR);
        $stmt_funcionario->bindParam(':endereco', $endereco, PDO::PARAM_STR);
        $stmt_funcionario->bindParam(':telefone', $telefone, PDO::PARAM_STR);
        $stmt_funcionario->bindParam(':cargo', $cargo, PDO::PARAM_STR);
        $stmt_funcionario->bindParam(':pai', $pai, PDO::PARAM_STR);
        $stmt_funcionario->bindParam(':mae', $mae, PDO::PARAM_STR);
        $stmt_funcionario->bindParam(':cpf', $cpf, PDO::PARAM_STR);
        $stmt_funcionario->bindParam(':rg', $rg, PDO::PARAM_STR);
        $stmt_funcionario->bindParam(':data_nascimento', $data_nascimento, PDO::PARAM_STR);
        $stmt_funcionario->bindParam(':salario', $salario, PDO::PARAM_STR);
        $stmt_funcionario->bindParam(':sexo', $sexo, PDO::PARAM_STR);
        $stmt_funcionario->execute();

        $success = true;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

// Inclui o header do sistema
include('includes/header_template.php');
renderHeader("Consulta de Empenhos - LicitaSis", "empenhos");
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <title>Cadastro de Funcionário - LicitaSis</title>
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
            position: relative;
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
            position: relative;
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

        /* Mobile Menu */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            padding: 0.5rem;
            cursor: pointer;
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .nav-container {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
        }

        /* Container principal */
        .container {
            max-width: 800px;
            margin: 2rem auto;
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

        h2 {
            text-align: center;
            color: var(--primary-color);
            margin-bottom: 2rem;
            font-size: 2rem;
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

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.95rem;
        }

        input, select, textarea {
            width: 100%;
            padding: 1rem;
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
            transform: translateY(-2px);
        }

        /* Button Styles */
        .btn {
            width: 100%;
            padding: 1.2rem;
            background: linear-gradient(135deg, var(--secondary-color) 0%, #009d8f 100%);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            margin-top: 1rem;
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

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 191, 174, 0.4);
        }

        .btn:active {
            transform: translateY(-1px);
        }

        /* Message Styles */
        .message {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            text-align: center;
            animation: slideInDown 0.5s ease;
        }

        .error {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
            border: 1px solid #ff5252;
        }

        .success {
            background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
            color: white;
            border: 1px solid #51cf66;
        }

        /* Back button */
        .back-btn {
            display: inline-block;
            margin-bottom: 1rem;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        .back-btn:hover {
            color: var(--secondary-color);
            transform: translateX(-5px);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 2rem;
            border-radius: var(--radius);
            width: 90%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.8);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal h3 {
            color: var(--success-color);
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }

        .modal-close-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            margin-top: 1rem;
        }

        .modal-close-btn:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        /* Loading animation */
        .loading {
            display: none;
            text-align: center;
            margin: 1rem 0;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--secondary-color);
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Input formatting helpers */
        .input-helper {
            font-size: 0.8rem;
            color: var(--medium-gray);
            margin-top: 0.25rem;
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            header {
                position: relative;
            }

            .mobile-menu-toggle {
                display: block;
            }

            .logo {
                max-width: 120px;
            }

            .nav-container {
                display: none;
                flex-direction: column;
                width: 100%;
                position: absolute;
                top: 100%;
                left: 0;
                background: var(--primary-color);
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            }

            .nav-container.active {
                display: flex;
                animation: slideDownNav 0.3s ease;
            }

            @keyframes slideDownNav {
                from {
                    opacity: 0;
                    transform: translateY(-20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .dropdown {
                width: 100%;
            }

            nav a {
                padding: 0.875rem 1.5rem;
                font-size: 0.85rem;
                border-bottom: 1px solid rgba(255,255,255,0.1);
                width: 100%;
                text-align: left;
            }

            .dropdown-content {
                position: static;
                display: none;
                box-shadow: none;
                border-radius: 0;
                background: rgba(0,0,0,0.2);
            }

            .dropdown:hover .dropdown-content {
                display: block;
            }

            .dropdown-content a {
                padding-left: 2rem;
                font-size: 0.8rem;
            }

            .container {
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.75rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .modal-content {
                width: 90%;
                margin: 20% auto;
                padding: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .logo {
                max-width: 100px;
            }

            nav a {
                padding: 0.75rem 1rem;
                font-size: 0.8rem;
            }

            .dropdown-content a {
                padding-left: 1.5rem;
                font-size: 0.75rem;
            }

            .container {
                padding: 1.25rem;
                margin: 1rem;
            }

            h2 {
                font-size: 1.5rem;
                margin-bottom: 1.5rem;
            }

            input, select, textarea, .btn {
                padding: 0.9rem;
                font-size: 0.95rem;
            }

            .form-grid {
                gap: 0.8rem;
            }

            .form-group {
                margin-bottom: 1rem;
            }

            .modal-content {
                margin: 30% auto;
                padding: 1.25rem;
            }
        }

        /* Hover effects para mobile */
        @media (hover: none) {
            .btn:active {
                transform: scale(0.98);
            }
        }
    </style>
</head>
<body>

    <div class="container">
        <a href="funcionarios.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Voltar para Funcionários
        </a>

        <?php if ($error): ?>
            <div class="message error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <h2>Cadastro de Funcionário</h2>

        <form action="cadastro_funcionario.php" method="POST" id="funcionarioForm">
            <div class="form-grid">
                <div class="form-group">
                    <label for="nome_completo"><i class="fas fa-user"></i> Nome Completo *</label>
                    <input type="text" id="nome_completo" name="nome_completo" required 
                           placeholder="Nome completo do funcionário"
                           value="<?php echo htmlspecialchars($_POST['nome_completo'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="cargo"><i class="fas fa-briefcase"></i> Cargo *</label>
                    <input type="text" id="cargo" name="cargo" required 
                           placeholder="Cargo do funcionário"
                           value="<?php echo htmlspecialchars($_POST['cargo'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="cpf"><i class="fas fa-id-card"></i> CPF *</label>
                    <input type="text" id="cpf" name="cpf" required 
                           placeholder="000.000.000-00"
                           oninput="formatCPF(this)"
                           value="<?php echo htmlspecialchars($_POST['cpf'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="rg"><i class="fas fa-id-card-alt"></i> RG *</label>
                    <input type="text" id="rg" name="rg" required 
                           placeholder="00.000.000-0"
                           oninput="formatRG(this)"
                           value="<?php echo htmlspecialchars($_POST['rg'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="telefone"><i class="fas fa-phone"></i> Telefone *</label>
                    <input type="tel" id="telefone" name="telefone" required 
                           placeholder="(00) 00000-0000"
                           oninput="formatTelefone(this)"
                           value="<?php echo htmlspecialchars($_POST['telefone'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="data_nascimento"><i class="fas fa-calendar-alt"></i> Data de Nascimento *</label>
                    <input type="date" id="data_nascimento" name="data_nascimento" required
                           value="<?php echo htmlspecialchars($_POST['data_nascimento'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="sexo"><i class="fas fa-venus-mars"></i> Sexo *</label>
                    <select id="sexo" name="sexo" required>
                        <option value="">Selecione o sexo</option>
                        <option value="Masculino" <?php echo (isset($_POST['sexo']) && $_POST['sexo'] === 'Masculino') ? 'selected' : ''; ?>>Masculino</option>
                        <option value="Feminino" <?php echo (isset($_POST['sexo']) && $_POST['sexo'] === 'Feminino') ? 'selected' : ''; ?>>Feminino</option>
                        <option value="Outro" <?php echo (isset($_POST['sexo']) && $_POST['sexo'] === 'Outro') ? 'selected' : ''; ?>>Outro</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="salario"><i class="fas fa-dollar-sign"></i> Salário *</label>
                    <input type="text" id="salario" name="salario" required 
                           placeholder="R$ 0.000,00"
                           oninput="formatSalario(this)"
                           value="<?php echo htmlspecialchars($_POST['salario'] ?? ''); ?>">
                </div>

                <div class="form-group full-width">
                    <label for="endereco"><i class="fas fa-map-marker-alt"></i> Endereço *</label>
                    <input type="text" id="endereco" name="endereco" required 
                           placeholder="Endereço completo"
                           value="<?php echo htmlspecialchars($_POST['endereco'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="pai"><i class="fas fa-male"></i> Nome do Pai</label>
                    <input type="text" id="pai" name="pai" 
                           placeholder="Nome do pai (opcional)"
                           value="<?php echo htmlspecialchars($_POST['pai'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="mae"><i class="fas fa-female"></i> Nome da Mãe</label>
                    <input type="text" id="mae" name="mae" 
                           placeholder="Nome da mãe (opcional)"
                           value="<?php echo htmlspecialchars($_POST['mae'] ?? ''); ?>">
                </div>
            </div>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Cadastrando funcionário...</p>
            </div>

            <button type="submit" class="btn" onclick="showLoading()">
                <i class="fas fa-user-plus"></i> Cadastrar Funcionário
            </button>
        </form>
    </div>

    <!-- Modal de sucesso -->
    <div id="successModal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-check-circle"></i> Sucesso!</h3>
            <p>Funcionário cadastrado com sucesso!</p>
            <button class="modal-close-btn" onclick="closeModal()">
                <i class="fas fa-times"></i> Fechar
            </button>
        </div>
    </div>

    <script>
        // Função para mostrar o modal de sucesso
        function openModal() {
            document.getElementById('successModal').style.display = 'block';
        }

        // Função para fechar o modal
        function closeModal() {
            document.getElementById('successModal').style.display = 'none';
            window.location.href = 'cadastro_funcionario.php';
        }

        // Função para mostrar loading
        function showLoading() {
            document.getElementById('loading').style.display = 'block';
        }

        // Formatação do CPF
        function formatCPF(input) {
            let cpf = input.value.replace(/\D/g, '');
            if (cpf.length <= 11) {
                cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
                cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
                cpf = cpf.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            }
            input.value = cpf;
        }

        // Formatação do RG
        function formatRG(input) {
            let rg = input.value.replace(/\D/g, '');
            if (rg.length <= 9) {
                rg = rg.replace(/(\d{2})(\d)/, '$1.$2');
                rg = rg.replace(/(\d{3})(\d)/, '$1.$2');
                rg = rg.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            }
            input.value = rg;
        }

        // Formatação do Telefone
        function formatTelefone(input) {
            let telefone = input.value.replace(/\D/g, '');
            if (telefone.length <= 11) {
                telefone = telefone.replace(/(\d{2})(\d)/, '($1) $2');
                telefone = telefone.replace(/(\d{4,5})(\d{4})$/, '$1-$2');
            }
            input.value = telefone;
        }

        // Formatação do Salário
        function formatSalario(input) {
            let valor = input.value.replace(/\D/g, '');
            if (valor.length > 0) {
                valor = valor.replace(/(\d)(\d{2})$/, '$1,$2');
                valor = valor.replace(/(?=(\d{3})+(\D))\B/g, '.');
                valor = 'R$ ' + valor;
            }
            input.value = valor;
        }

        // Menu mobile
        function toggleMobileMenu() {
            const navContainer = document.getElementById('navContainer');
            const menuToggle = document.querySelector('.mobile-menu-toggle i');
            
            navContainer.classList.toggle('active');
            
            if (navContainer.classList.contains('active')) {
                menuToggle.className = 'fas fa-times';
            } else {
                menuToggle.className = 'fas fa-bars';
            }
        }

        // Validação do formulário
        document.getElementById('funcionarioForm').addEventListener('submit', function(e) {
            const cpf = document.getElementById('cpf').value.replace(/\D/g, '');
            const rg = document.getElementById('rg').value.replace(/\D/g, '');
            
            if (cpf.length !== 11) {
                e.preventDefault();
                alert('CPF deve ter 11 dígitos!');
                document.getElementById('cpf').focus();
                return false;
            }
            
            if (rg.length < 7) {
                e.preventDefault();
                alert('RG deve ter pelo menos 7 dígitos!');
                document.getElementById('rg').focus();
                return false;
            }
        });

        // Validação de CPF em tempo real
        function validarCPF(cpf) {
            cpf = cpf.replace(/\D/g, '');
            if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
            
            let soma = 0;
            for (let i = 0; i < 9; i++) {
                soma += parseInt(cpf.charAt(i)) * (10 - i);
            }
            let resto = 11 - (soma % 11);
            if (resto === 10 || resto === 11) resto = 0;
            if (resto !== parseInt(cpf.charAt(9))) return false;
            
            soma = 0;
            for (let i = 0; i < 10; i++) {
                soma += parseInt(cpf.charAt(i)) * (11 - i);
            }
            resto = 11 - (soma % 11);
            if (resto === 10 || resto === 11) resto = 0;
            return resto === parseInt(cpf.charAt(10));
        }

        // Validação visual do CPF
        document.getElementById('cpf').addEventListener('blur', function() {
            const cpf = this.value.replace(/\D/g, '');
            if (cpf.length === 11) {
                if (!validarCPF(cpf)) {
                    this.style.borderColor = '#dc3545';
                    this.style.boxShadow = '0 0 0 3px rgba(220, 53, 69, 0.1)';
                } else {
                    this.style.borderColor = '#28a745';
                    this.style.boxShadow = '0 0 0 3px rgba(40, 167, 69, 0.1)';
                }
            }
        });

        // Fecha o menu móvel quando clicar fora dele
        document.addEventListener('click', function(event) {
            const navContainer = document.getElementById('navContainer');
            const menuToggle = document.querySelector('.mobile-menu-toggle');
            
            if (!navContainer.contains(event.target) && !menuToggle.contains(event.target)) {
                navContainer.classList.remove('active');
                document.querySelector('.mobile-menu-toggle i').className = 'fas fa-bars';
            }
        });

        // Fecha o menu móvel ao redimensionar a tela
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                const navContainer = document.getElementById('navContainer');
                navContainer.classList.remove('active');
                document.querySelector('.mobile-menu-toggle i').className = 'fas fa-bars';
            }
        });

        // Inicialização quando a página carrega
        window.addEventListener('load', function() {
            <?php if ($success) { echo "openModal();"; } ?>
            
            // Remove mensagens após 5 segundos
            setTimeout(function() {
                const messages = document.querySelectorAll('.message');
                messages.forEach(function(message) {
                    message.style.transition = 'opacity 0.5s ease';
                    message.style.opacity = '0';
                    setTimeout(function() {
                        message.remove();
                    }, 500);
                });
            }, 5000);
        });

        // Fecha modal ao clicar fora dele
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('successModal');
            if (event.target === modal) {
                closeModal();
            }
        });

        // Validação de idade mínima (16 anos)
        document.getElementById('data_nascimento').addEventListener('change', function() {
            const hoje = new Date();
            const nascimento = new Date(this.value);
            const idade = hoje.getFullYear() - nascimento.getFullYear();
            const mesAtual = hoje.getMonth();
            const mesNascimento = nascimento.getMonth();
            
            if (mesAtual < mesNascimento || (mesAtual === mesNascimento && hoje.getDate() < nascimento.getDate())) {
                idade--;
            }
            
            if (idade < 16) {
                alert('O funcionário deve ter pelo menos 16 anos!');
                this.value = '';
                this.focus();
            }
        });

        // Capitalizar nomes automaticamente
        function capitalizeName(input) {
            const words = input.value.toLowerCase().split(' ');
            const capitalizedWords = words.map(word => {
                if (word.length > 2) {
                    return word.charAt(0).toUpperCase() + word.slice(1);
                }
                return word;
            });
            input.value = capitalizedWords.join(' ');
        }

        // Aplicar capitalização nos campos de nome
        document.getElementById('nome_completo').addEventListener('blur', function() {
            capitalizeName(this);
        });

        document.getElementById('pai').addEventListener('blur', function() {
            capitalizeName(this);
        });

        document.getElementById('mae').addEventListener('blur', function() {
            capitalizeName(this);
        });

        // Limitar salário máximo
        document.getElementById('salario').addEventListener('input', function() {
            let valor = this.value.replace(/\D/g, '');
            if (parseInt(valor) > 10000000) { // 100.000,00
                this.value = 'R$ 100.000,00';
            } else {
                formatSalario(this);
            }
        });
    </script>

</body>
</html>