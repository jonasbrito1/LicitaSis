<?php
session_start();

// Inclui o sistema de permissões
include('db.php');
include('permissions.php');

// Inicializa o gerenciador de permissões
$permissionManager = initPermissions($pdo);

// Verifica se o usuário tem permissão para criar usuários
$permissionManager->requirePermission('usuarios', 'create');

$error = "";
$success = "";
$createdEmail = "";
$createdPassword = "";
$createdPermission = "";

$isAdmin = $permissionManager->isAdmin();

// Inclui o PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

// Função para gerar senha aleatória
function generateRandomPassword() {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%';
    $password = '';
    for ($i = 0; $i < 12; $i++) {
        $randomIndex = rand(0, strlen($characters) - 1);
        $password .= $characters[$randomIndex];
    }
    return $password;
}

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $permission = trim($_POST['permission']);
    $passwordOption = $_POST['password_option'];
    $customPassword = isset($_POST['custom_password']) ? trim($_POST['custom_password']) : '';

    // Verifica se os campos obrigatórios não estão vazios
    if (empty($name) || empty($email) || empty($permission)) {
        $error = "Nome, e-mail e permissão são obrigatórios!";
    } 
    // Verifica se a permissão selecionada é válida
    elseif (!in_array($permission, ['Administrador', 'Usuario_Nivel_1', 'Usuario_Nivel_2', 'Investidor'])) {
        $error = "Permissão inválida!";
    }
    // Verifica senha personalizada se foi selecionada
    elseif ($passwordOption === 'custom' && (empty($customPassword) || strlen($customPassword) < 6)) {
        $error = "A senha personalizada deve ter pelo menos 6 caracteres!";
    }
    // Verifica se o e-mail já está registrado
    else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $error = "Este e-mail já está registrado!";
        } else {
            // Define a senha com base na opção escolhida
            if ($passwordOption === 'custom') {
                $generatedPassword = $customPassword;
            } else {
                $generatedPassword = generateRandomPassword();
            }

            // Hash da senha antes de armazenar
            $hashedPassword = password_hash($generatedPassword, PASSWORD_BCRYPT);

            // Insere o novo usuário no banco de dados
            $sql = "INSERT INTO users (name, email, password, permission) VALUES (:name, :email, :password, :permission)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':permission', $permission);

            if ($stmt->execute()) {
                $success = "Cadastro realizado com sucesso!";
                $createdEmail = $email;
                $createdPassword = $generatedPassword;
                $createdPermission = $permission;

                // Envia o e-mail ao usuário com as informações de login
                try {
                    $mail = new PHPMailer(true);

                    // Configuração do servidor SMTP
                    $mail->isSMTP();
                    $mail->Host = 'mail.combraz.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'jon@combraz.com';
                    $mail->Password = '^V$[k]2r^0(9';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;

                    // Remetente e destinatário
                    $mail->setFrom('jon@combraz.com', 'LicitaSis');
                    $mail->addAddress($email, $name);

                    // Conteúdo do e-mail
                    $permissionName = $permissionManager->getPermissionName($permission);
                    $mail->isHTML(true);
                    $mail->Subject = 'Credenciais de Acesso - LicitaSis';
                    $mail->Body = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f9f9f9; padding: 20px;'>
                            <div style='background: linear-gradient(135deg, #2D893E 0%, #4CAC74 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                                <h1 style='color: white; margin: 0; font-size: 24px;'>LicitaSis</h1>
                                <p style='color: #e8f5e8; margin: 10px 0 0 0;'>Sistema de Gestão de Licitações</p>
                            </div>
                            <div style='background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);'>
                                <h2 style='color: #2D893E; margin-bottom: 20px;'>Olá, $name!</h2>
                                <p style='color: #666; line-height: 1.6; margin-bottom: 25px;'>
                                    Seu cadastro foi realizado com sucesso no sistema LicitaSis. 
                                    Abaixo estão suas credenciais de acesso:
                                </p>
                                <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #2D893E; margin-bottom: 25px;'>
                                    <p style='margin: 8px 0; color: #333;'><strong>Nome:</strong> $name</p>
                                    <p style='margin: 8px 0; color: #333;'><strong>Email:</strong> $email</p>
                                    <p style='margin: 8px 0; color: #333;'><strong>Senha:</strong> $generatedPassword</p>
                                    <p style='margin: 8px 0; color: #333;'><strong>Nível de Acesso:</strong> $permissionName</p>
                                </div>
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='https://www.combraz.com/includes/login.php' 
                                       style='background: linear-gradient(135deg, #00bfae 0%, #009d8f 100%); 
                                              color: white; 
                                              padding: 15px 30px; 
                                              text-decoration: none; 
                                              border-radius: 8px; 
                                              font-weight: bold; 
                                              display: inline-block;
                                              box-shadow: 0 4px 15px rgba(0,191,174,0.3);'>
                                        Acessar Sistema
                                    </a>
                                </div>
                                <p style='color: #888; font-size: 12px; line-height: 1.4; margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px;'>
                                    Por segurança, recomendamos que você altere sua senha no primeiro acesso.
                                    <br>Este é um e-mail automático, não responda.
                                </p>
                            </div>
                        </div>
                    ";

                    // Envia o e-mail
                    if($mail->send()) {
                        $success .= " E-mail enviado com sucesso!";
                    } else {
                        $error = "Usuário criado, mas falha ao enviar o e-mail.";
                    }
                } catch (Exception $e) {
                    $error = "Usuário criado, mas erro ao enviar o e-mail: {$mail->ErrorInfo}";
                }
            } else {
                $error = "Erro ao realizar o cadastro!";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <title>Cadastro de Usuários - LicitaSis</title>
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

        /* User info */
        .user-info {
            position: absolute;
            top: 50%;
            right: 1rem;
            transform: translateY(-50%);
            color: white;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255,255,255,0.1);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            backdrop-filter: blur(10px);
        }

        .permission-badge {
            background: var(--secondary-color);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Navigation */
        nav {
            background: var(--primary-color);
            padding: 0;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            position: relative;
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
            left: 1rem;
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
            max-width: 600px;
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
        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-gray);
            font-size: 0.95rem;
        }

        input, select {
            width: 100%;
            padding: 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
            background: white;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
            transform: translateY(-2px);
        }

        /* Permission Select Styling */
        .permission-select {
            position: relative;
        }

        .permission-option {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
        }

        .permission-description {
            font-size: 0.85rem;
            color: var(--medium-gray);
            margin-top: 0.25rem;
            font-style: italic;
        }

        /* Password Option Styles */
        .password-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .password-option {
            position: relative;
        }

        .password-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .password-option label {
            display: block;
            padding: 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
            background: white;
            margin-bottom: 0;
            font-weight: 500;
        }

        .password-option input[type="radio"]:checked + label {
            border-color: var(--secondary-color);
            background: linear-gradient(135deg, var(--secondary-color) 0%, #009d8f 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 191, 174, 0.3);
        }

        .custom-password-field {
            display: none;
            animation: slideDown 0.3s ease;
        }

        .custom-password-field.show {
            display: block;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
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

        /* Generated Password Display */
        .generated-info {
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            padding: 1.5rem;
            border-radius: var(--radius);
            border-left: 4px solid var(--secondary-color);
            margin-top: 1rem;
        }

        .generated-info h4 {
            color: var(--primary-color);
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .generated-info p {
            margin: 0.5rem 0;
            color: var(--medium-gray);
        }

        .generated-info strong {
            color: var(--dark-gray);
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

        /* Responsividade */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
            }

            .user-info {
                position: static;
                transform: none;
                margin-top: 1rem;
                justify-content: center;
                font-size: 0.8rem;
            }

            .container {
                padding: 1.5rem;
                margin: 1.5rem;
            }

            h2 {
                font-size: 1.75rem;
            }

            .password-options {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }

            .password-option label {
                padding: 0.8rem;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 1.25rem;
                margin: 1rem;
            }

            h2 {
                font-size: 1.5rem;
                margin-bottom: 1.5rem;
            }

            input, select, .btn {
                padding: 0.9rem;
                font-size: 0.95rem;
            }

            .password-option label {
                padding: 0.7rem;
                font-size: 0.85rem;
            }

            .user-info {
                font-size: 0.75rem;
                padding: 0.3rem 0.8rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
            <i class="fas fa-bars"></i>
        </button>
        
        <a href="index.php">
            <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
        </a>
        
        <div class="user-info">
            <i class="fas fa-user"></i>
            <span><?php echo htmlspecialchars($_SESSION['user']['name']); ?></span>
            <span class="permission-badge">
                <?php echo $permissionManager->getPermissionName($_SESSION['user']['permission']); ?>
            </span>
        </div>
    </header>

    <nav>
        <div class="nav-container" id="navContainer">
            <?php echo $permissionManager->generateNavigationMenu(); ?>
        </div>
    </nav>

    <div class="container">
        <a href="usuario.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Voltar para Usuários
        </a>

        <?php if ($error): ?>
            <div class="message error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="message success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
            <?php if ($createdEmail && $createdPassword): ?>
                <div class="generated-info">
                    <h4><i class="fas fa-info-circle"></i> Informações do Usuário Criado</h4>
                    <p><strong>Nome:</strong> <?php echo htmlspecialchars($_POST['name'] ?? ''); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($createdEmail); ?></p>
                    <p><strong>Senha:</strong> <?php echo htmlspecialchars($createdPassword); ?></p>
                    <p><strong>Nível de Acesso:</strong> <?php echo $permissionManager->getPermissionName($createdPermission); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <h2>Cadastro de Usuários</h2>

        <form action="signup.php" method="POST" id="signupForm">
            <div class="form-group">
                <label for="name"><i class="fas fa-user"></i> Nome Completo *</label>
                <input type="text" id="name" name="name" required 
                       placeholder="Digite o nome completo do usuário"
                       value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="email"><i class="fas fa-envelope"></i> E-mail *</label>
                <input type="email" id="email" name="email" required 
                       placeholder="usuario@exemplo.com"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="permission"><i class="fas fa-shield-alt"></i> Nível de Acesso *</label>
                <select id="permission" name="permission" required>
                    <option value="">Selecione o nível de acesso</option>
                    <option value="Administrador" <?php echo (isset($_POST['permission']) && $_POST['permission'] === 'Administrador') ? 'selected' : ''; ?>>
                        Administrador - Acesso total ao sistema
                    </option>
                    <option value="Usuario_Nivel_1" <?php echo (isset($_POST['permission']) && $_POST['permission'] === 'Usuario_Nivel_1') ? 'selected' : ''; ?>>
                        Usuário Nível 1 - Apenas visualização
                    </option>
                    <option value="Usuario_Nivel_2" <?php echo (isset($_POST['permission']) && $_POST['permission'] === 'Usuario_Nivel_2') ? 'selected' : ''; ?>>
                        Usuário Nível 2 - Consulta e edição (exceto usuários/funcionários)
                    </option>
                    <option value="Investidor" <?php echo (isset($_POST['permission']) && $_POST['permission'] === 'Investidor') ? 'selected' : ''; ?>>
                        Investidor - Apenas página de investimentos
                    </option>
                </select>
            </div>

            <div class="form-group">
                <label><i class="fas fa-key"></i> Opção de Senha</label>
                <div class="password-options">
                    <div class="password-option">
                        <input type="radio" id="random_password" name="password_option" value="random" 
                               <?php echo (!isset($_POST['password_option']) || $_POST['password_option'] === 'random') ? 'checked' : ''; ?>>
                        <label for="random_password">
                            <i class="fas fa-dice"></i><br>
                            Gerar Aleatória
                        </label>
                    </div>
                    <div class="password-option">
                        <input type="radio" id="custom_password_option" name="password_option" value="custom"
                               <?php echo (isset($_POST['password_option']) && $_POST['password_option'] === 'custom') ? 'checked' : ''; ?>>
                        <label for="custom_password_option">
                            <i class="fas fa-edit"></i><br>
                            Senha Personalizada
                        </label>
                    </div>
                </div>
            </div>

            <div class="form-group custom-password-field" id="customPasswordField">
                <label for="custom_password"><i class="fas fa-lock"></i> Digite a Senha (mínimo 6 caracteres)</label>
                <input type="password" id="custom_password" name="custom_password" 
                       placeholder="Digite uma senha segura"
                       value="<?php echo htmlspecialchars($_POST['custom_password'] ?? ''); ?>">
                <div id="password-strength" style="margin-top: 0.5rem; font-size: 0.8rem;"></div>
            </div>

            <button type="submit" class="btn">
                <i class="fas fa-user-plus"></i> Cadastrar Usuário
            </button>
        </form>
    </div>

    <script>
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

        // Controla a exibição do campo de senha personalizada
        function toggleCustomPasswordField() {
            const customOption = document.getElementById('custom_password_option');
            const customField = document.getElementById('customPasswordField');
            const customPasswordInput = document.getElementById('custom_password');
            
            if (customOption.checked) {
                customField.classList.add('show');
                customPasswordInput.required = true;
            } else {
                customField.classList.remove('show');
                customPasswordInput.required = false;
                customPasswordInput.value = '';
            }
        }

        // Event listeners para as opções de senha
        document.getElementById('random_password').addEventListener('change', toggleCustomPasswordField);
        document.getElementById('custom_password_option').addEventListener('change', toggleCustomPasswordField);

        // Verificador de força da senha
        document.getElementById('custom_password').addEventListener('input', function() {
            const password = this.value;
            const strengthDiv = document.getElementById('password-strength');
            
            if (password.length === 0) {
                strengthDiv.innerHTML = '';
                return;
            }
            
            let strength = 0;
            let feedback = [];
            
            if (password.length >= 6) strength++;
            else feedback.push('pelo menos 6 caracteres');
            
            if (/[a-z]/.test(password)) strength++;
            else feedback.push('letras minúsculas');
            
            if (/[A-Z]/.test(password)) strength++;
            else feedback.push('letras maiúsculas');
            
            if (/[0-9]/.test(password)) strength++;
            else feedback.push('números');
            
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            else feedback.push('caracteres especiais');
            
            const colors = ['#dc3545', '#fd7e14', '#ffc107', '#28a745', '#20c997'];
            const labels = ['Muito Fraca', 'Fraca', 'Regular', 'Boa', 'Muito Boa'];
            
            strengthDiv.innerHTML = `
                <div style="color: ${colors[strength-1]}; font-weight: 600;">
                    Força: ${labels[strength-1] || 'Muito Fraca'}
                </div>
                ${feedback.length > 0 ? `<div style="color: #6c757d;">Adicione: ${feedback.join(', ')}</div>` : ''}
            `;
        });

        // Form validation
        document.getElementById('signupForm').addEventListener('submit', function(e) {
            const customOption = document.getElementById('custom_password_option');
            const customPassword = document.getElementById('custom_password');
            
            if (customOption.checked && customPassword.value.length < 6) {
                e.preventDefault();
                alert('A senha personalizada deve ter pelo menos 6 caracteres!');
                customPassword.focus();
                return false;
            }
        });

        // Fecha o menu móvel quando clicar fora dele
        document.addEventListener('click', function(event) {
            const navContainer = document.getElementById('navContainer');
            const menuToggle = document.querySelector('.mobile-menu-toggle');
            
            if (navContainer && menuToggle && !navContainer.contains(event.target) && !menuToggle.contains(event.target)) {
                navContainer.classList.remove('active');
                document.querySelector('.mobile-menu-toggle i').className = 'fas fa-bars';
            }
        });

        // Fecha o menu móvel ao redimensionar a tela
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                const navContainer = document.getElementById('navContainer');
                if (navContainer) {
                    navContainer.classList.remove('active');
                    document.querySelector('.mobile-menu-toggle i').className = 'fas fa-bars';
                }
            }
        });

        // Inicializa o estado correto do campo de senha personalizada
        document.addEventListener('DOMContentLoaded', function() {
            toggleCustomPasswordField();
            
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

        // Validação em tempo real do e-mail
        document.getElementById('email').addEventListener('blur', function() {
            const email = this.value;
            if (email && !isValidEmail(email)) {
                this.style.borderColor = '#dc3545';
                this.style.boxShadow = '0 0 0 3px rgba(220, 53, 69, 0.1)';
            } else {
                this.style.borderColor = '';
                this.style.boxShadow = '';
            }
        });

        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

        // Adiciona descrições dinâmicas para os níveis de permissão
        document.getElementById('permission').addEventListener('change', function() {
            const descriptions = {
                'Administrador': 'Acesso completo a todas as funcionalidades do sistema, incluindo gestão de usuários e funcionários.',
                'Usuario_Nivel_1': 'Acesso apenas para visualização de dados. Não pode editar, criar ou excluir informações.',
                'Usuario_Nivel_2': 'Pode consultar e editar dados do sistema, exceto usuários e funcionários.',
                'Investidor': 'Acesso exclusivo à seção de investimentos do sistema.'
            };
            
            const selectedOption = this.value;
            let existingDesc = document.getElementById('permission-desc');
            
            if (existingDesc) {
                existingDesc.remove();
            }
            
            if (selectedOption && descriptions[selectedOption]) {
                const desc = document.createElement('div');
                desc.id = 'permission-desc';
                desc.className = 'permission-description';
                desc.style.marginTop = '0.5rem';
                desc.style.padding = '0.5rem';
                desc.style.backgroundColor = '#f8f9fa';
                desc.style.borderRadius = '4px';
                desc.style.fontSize = '0.85rem';
                desc.style.color = '#6c757d';
                desc.innerHTML = '<i class="fas fa-info-circle"></i> ' + descriptions[selectedOption];
                
                this.parentElement.appendChild(desc);
            }
        });
    </script>

</body>
</html>