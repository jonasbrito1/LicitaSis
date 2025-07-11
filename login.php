<?php
session_start(); // Inicia a sessão

// Inclui os arquivos necessários
include('db.php');  
include('function.php');

// Inclui o sistema de auditoria se existir
if (file_exists('includes/audit.php')) {
    include('includes/audit.php');
}

// Variáveis de erro e sucesso
$error = "";
$success = "";

// Função para obter IP do cliente (apenas se não estiver definida no audit.php)
if (!function_exists('getClientIP')) {
    function getClientIP() {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}

// Função para verificar tentativas suspeitas (apenas se não existir)
if (!function_exists('checkSuspiciousActivity')) {
    function checkSuspiciousActivity($email) {
        global $pdo;
        
        try {
            // Verifica se existe tabela de auditoria
            $stmt = $pdo->query("SHOW TABLES LIKE 'audit_log'");
            if (!$stmt->fetch()) {
                return false; // Se não existe tabela, não há como verificar
            }
            
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as attempts 
                FROM audit_log 
                WHERE action = 'ACCESS_DENIED' 
                AND details LIKE ? 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 300 SECOND)
            ");
            $stmt->execute(['%"email":"' . $email . '"%']);
            $result = $stmt->fetch();
            
            return $result['attempts'] >= 5; // 5 tentativas em 5 minutos
        } catch (Exception $e) {
            return false; // Em caso de erro, permite continuar
        }
    }
}

// Função para registrar tentativa de login (apenas se não existir)
if (!function_exists('logLoginAttempt')) {
    function logLoginAttempt($email, $success, $error_message = null) {
        global $pdo;
        
        try {
            // Verifica se existe tabela de auditoria
            $stmt = $pdo->query("SHOW TABLES LIKE 'audit_log'");
            if (!$stmt->fetch()) {
                // Cria a tabela se não existir
                $createTable = "
                    CREATE TABLE IF NOT EXISTS audit_log (
                        id INT(11) AUTO_INCREMENT PRIMARY KEY,
                        user_id INT(11),
                        user_name VARCHAR(255),
                        action ENUM('CREATE', 'READ', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'ACCESS_DENIED', 'PROFILE_UPDATE', 'PASSWORD_CHANGE') NOT NULL,
                        table_name VARCHAR(100),
                        record_id INT(11),
                        details TEXT,
                        ip_address VARCHAR(45),
                        user_agent TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_user_id (user_id),
                        INDEX idx_action (action),
                        INDEX idx_created_at (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci
                ";
                $pdo->exec($createTable);
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO audit_log 
                (user_id, user_name, action, table_name, details, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $details = [
                'email' => $email,
                'success' => $success,
                'error_message' => $error_message,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            return $stmt->execute([
                $success && isset($_SESSION['user']) ? $_SESSION['user']['id'] : null,
                $success && isset($_SESSION['user']) ? $_SESSION['user']['name'] : $email,
                $success ? 'LOGIN' : 'ACCESS_DENIED',
                'users',
                json_encode($details),
                getClientIP(),
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("Erro no log de login: " . $e->getMessage());
            return false;
        }
    }
}

// Função para atualizar último login (apenas se não existir)
if (!function_exists('updateLastLogin')) {
    function updateLastLogin($user_id) {
        global $pdo;
        
        try {
            // Verifica se a coluna last_login existe
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_login'");
            if (!$stmt->fetch()) {
                // Adiciona a coluna se não existir
                $pdo->exec("ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL");
            }
            
            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            return $stmt->execute([$user_id]);
        } catch (Exception $e) {
            error_log("Erro ao atualizar último login: " . $e->getMessage());
            return false;
        }
    }
}

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Validações básicas
    if (empty($email) || empty($password)) {
        $error = "E-mail e senha são obrigatórios!";
        logLoginAttempt($email, false, "Campos vazios");
    } 
    // Verifica se há tentativas suspeitas
    elseif (checkSuspiciousActivity($email)) {
        $error = "Muitas tentativas de login falhadas. Tente novamente em alguns minutos.";
        logLoginAttempt($email, false, "Bloqueado por tentativas excessivas");
    }
    else {
        // Verifica se o email existe no banco de dados
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Se o email existir, verifica a senha
            if (password_verify($password, $user['password'])) {
                // Se a senha for válida, inicia a sessão
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'permission' => $user['permission'],
                ];
                $_SESSION['login_time'] = time(); // Para calcular duração da sessão
                
                // Atualiza último login
                updateLastLogin($user['id']);
                
                // Registra login bem-sucedido
                logLoginAttempt($email, true);
                
                // Redireciona para o index após iniciar a sessão
                header("Location: index.php");
                exit();
            } else {
                $error = "Senha incorreta!";
                logLoginAttempt($email, false, "Senha incorreta");
            }
        } else {
            $error = "Usuário não encontrado!";
            logLoginAttempt($email, false, "Usuário não encontrado");
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
    <title>Login - LicitaSis</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        /* Variáveis de cores - mantendo o branding original */
        :root {
            --primary-color: #2D893E;
            --primary-dark: #1e6e2d;
            --primary-light: #9DCEAC;
            --secondary-color: #00bfae;
            --secondary-dark: #009d8f;
            --text-light: #fff;
            --text-dark: #333;
            --text-muted: #6c757d;
            --bg-light: #f5f7fa;
            --bg-card: #fff;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --shadow-hover: 0 6px 15px rgba(0, 0, 0, 0.15);
            --border-radius: 12px;
            --border-radius-sm: 8px;
            --transition: all 0.3s ease;
            --error-color: #dc3545;
            --success-color: #28a745;
            --warning-color: #ffc107;
        }

        /* Reset e estilos base */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            color: var(--text-dark);
            line-height: 1.6;
        }

        /* Header */
        header {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
            padding: 1rem 0;
            text-align: center;
            box-shadow: var(--shadow);
            width: 100%;
        }

        .logo-header {
            max-width: 160px;
            height: auto;
            transition: var(--transition);
        }

        .logo-header:hover {
            transform: scale(1.05);
        }

        /* Contêiner principal */
        .main-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
        }

        /* Contêiner de login */
        .login-container {
            background-color: var(--bg-card);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 400px;
            transition: var(--transition);
            overflow: hidden;
        }

        .login-container:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-5px);
        }

        /* Cabeçalho do login */
        .login-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            padding: 2rem;
            text-align: center;
            color: var(--text-light);
            position: relative;
        }

        .login-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-light), var(--secondary-color));
            animation: gradient 3s ease infinite;
            background-size: 200% 200%;
        }

        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .login-logo {
            width: 70px;
            height: 70px;
            background-color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .login-logo i {
            color: var(--primary-color);
            font-size: 2rem;
        }

        .login-header h2 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        /* Corpo do login */
        .login-body {
            padding: 2rem;
        }

        /* Mensagens de erro */
        .error {
            color: var(--error-color);
            background-color: rgba(220, 53, 69, 0.1);
            border-left: 4px solid var(--error-color);
            padding: 0.8rem 1rem;
            border-radius: var(--border-radius-sm);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: slideInDown 0.3s ease;
        }

        @keyframes slideInDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Formulário */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            transition: var(--transition);
        }

        .input-wrapper input:focus + i {
            color: var(--primary-color);
        }

        .form-group input {
            width: 100%;
            padding: 0.9rem 1rem 0.9rem 2.5rem;
            border: 1px solid #ddd;
            border-radius: var(--border-radius-sm);
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.2);
            transform: translateY(-2px);
        }

        /* Toggle password visibility */
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            cursor: pointer;
            transition: var(--transition);
            z-index: 10;
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        /* Botão de login */
        .login-btn {
            width: 100%;
            padding: 0.9rem;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: var(--text-light);
            border: none;
            border-radius: var(--border-radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.3);
            position: relative;
            overflow: hidden;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.4);
        }

        .login-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0, 191, 174, 0.3);
        }

        .login-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Loading state */
        .loading {
            display: none;
        }

        .login-btn.loading {
            pointer-events: none;
        }

        .login-btn.loading .loading {
            display: inline-block;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Rodapé do login */
        .login-footer {
            margin-top: 1.5rem;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }

        .logo-footer {
            max-width: 120px;
            margin-top: 1rem;
            opacity: 0.9;
            transition: var(--transition);
        }

        .logo-footer:hover {
            opacity: 1;
            transform: scale(1.05);
        }

        /* Security info */
        .security-notice {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border: 1px solid #90caf9;
            border-radius: var(--border-radius-sm);
            padding: 1rem;
            margin-top: 1rem;
            font-size: 0.85rem;
            color: #1565c0;
            text-align: center;
        }

        /* Caps Lock warning */
        .caps-warning {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--warning-color);
            color: var(--text-dark);
            padding: 0.5rem;
            border-radius: 0 0 var(--border-radius-sm) var(--border-radius-sm);
            font-size: 0.8rem;
            text-align: center;
            z-index: 10;
            animation: slideInDown 0.3s ease;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .main-container {
                padding: 1.5rem;
            }
            
            .login-header {
                padding: 1.5rem;
            }
            
            .login-body {
                padding: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .main-container {
                padding: 1rem;
            }
            
            .login-container {
                border-radius: var(--border-radius-sm);
            }
            
            .login-header {
                padding: 1.25rem;
            }
            
            .login-logo {
                width: 60px;
                height: 60px;
            }
            
            .login-logo i {
                font-size: 1.5rem;
            }
            
            .login-header h2 {
                font-size: 1.5rem;
            }
            
            .login-body {
                padding: 1.25rem;
            }
            
            .form-group input {
                padding: 0.8rem 1rem 0.8rem 2.3rem;
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body>

<header>
    <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo-header">
</header>

<div class="main-container">
    <div class="login-container">
        <div class="login-header">
            <div class="login-logo">
                <i class="fas fa-user-lock"></i>
            </div>
            <h2>Acesso ao Sistema</h2>
            <p>Entre com suas credenciais para continuar</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form action="login.php" method="POST" id="loginForm">
                <div class="form-group">
                    <label for="email">E-mail</label>
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" placeholder="Digite seu e-mail" 
                               required autocomplete="email" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Senha</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Digite sua senha" 
                               required autocomplete="current-password">
                        <i class="fas fa-lock"></i>
                        <span class="password-toggle" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </span>
                    </div>
                </div>
                
                <button type="submit" class="login-btn" id="loginBtn">
                    <i class="fas fa-sign-in-alt"></i>
                    <span class="btn-text">Entrar</span>
                    <i class="fas fa-spinner loading"></i>
                </button>
            </form>
            
            <div class="security-notice">
                <i class="fas fa-shield-alt"></i>
                Suas atividades são monitoradas por questões de segurança
            </div>
            
            <div class="login-footer">
                <p>LicitaSis - Sistema de Gestão de Licitações</p>
                <img src="../public_html/assets/images/Logo_novo.png" alt="Logo ComBraz" class="logo-footer">
            </div>
        </div>
    </div>
</div>

<script>
    // Animação de entrada
    document.addEventListener('DOMContentLoaded', function() {
        const container = document.querySelector('.login-container');
        container.style.opacity = '0';
        container.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            container.style.opacity = '1';
            container.style.transform = 'translateY(0)';
        }, 100);
        
        // Focus no primeiro campo
        setTimeout(() => {
            document.getElementById('email').focus();
        }, 600);
    });
    
    // Toggle password visibility
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.getElementById('toggleIcon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.className = 'fas fa-eye-slash';
        } else {
            passwordInput.type = 'password';
            toggleIcon.className = 'fas fa-eye';
        }
    }
    
    // Efeito de foco nos campos
    const inputs = document.querySelectorAll('input');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.style.transition = 'var(--transition)';
            this.parentElement.style.transform = 'translateY(-2px)';
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.style.transform = 'translateY(0)';
        });
    });

    // Form submission com loading
    document.getElementById('loginForm').addEventListener('submit', function() {
        const btn = document.getElementById('loginBtn');
        btn.classList.add('loading');
        btn.disabled = true;
        
        // Remove loading se houver erro (fallback)
        setTimeout(() => {
            if (btn.classList.contains('loading')) {
                btn.classList.remove('loading');
                btn.disabled = false;
            }
        }, 10000);
    });

    // Validação em tempo real do email
    document.getElementById('email').addEventListener('blur', function() {
        const email = this.value;
        if (email && !isValidEmail(email)) {
            this.style.borderColor = 'var(--error-color)';
            this.style.boxShadow = '0 0 0 3px rgba(220, 53, 69, 0.2)';
        } else {
            this.style.borderColor = '';
            this.style.boxShadow = '';
        }
    });

    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // Caps Lock detection
    document.getElementById('password').addEventListener('keydown', function(e) {
        if (e.getModifierState && e.getModifierState('CapsLock')) {
            showCapsLockWarning(true);
        } else {
            showCapsLockWarning(false);
        }
    });

    function showCapsLockWarning(show) {
        let warning = document.getElementById('capsLockWarning');
        
        if (show && !warning) {
            warning = document.createElement('div');
            warning.id = 'capsLockWarning';
            warning.className = 'caps-warning';
            warning.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Caps Lock está ativado';
            
            const passwordWrapper = document.getElementById('password').parentElement;
            passwordWrapper.style.position = 'relative';
            passwordWrapper.appendChild(warning);
        } else if (!show && warning) {
            warning.remove();
        }
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Enter para submit
        if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') {
            e.preventDefault();
            document.getElementById('loginForm').submit();
        }
        
        // Escape para limpar campos
        if (e.key === 'Escape') {
            document.getElementById('email').value = '';
            document.getElementById('password').value = '';
            document.getElementById('email').focus();
        }
    });

    // Remove error message after 5 seconds
    const errorMsg = document.querySelector('.error');
    if (errorMsg) {
        setTimeout(() => {
            errorMsg.style.transition = 'opacity 0.5s ease';
            errorMsg.style.opacity = '0';
            setTimeout(() => errorMsg.remove(), 500);
        }, 5000);
    }

    // Prevenção básica de spam de submit
    let isSubmitting = false;
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        if (isSubmitting) {
            e.preventDefault();
            return false;
        }
        isSubmitting = true;
        
        // Reset após 3 segundos
        setTimeout(() => {
            isSubmitting = false;
        }, 3000);
    });
</script>

</body>
</html>