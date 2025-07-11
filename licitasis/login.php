<?php
session_start(); // Inicia a sessão

// Inclui os arquivos db.php e function.php
include('db.php');  
include('function.php');

// Variáveis de erro e sucesso
$error = "";
$success = "";

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

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
                'permission' => $user['permission'], // Atribui a permissão ao usuário
            ];
            // Redireciona para o index após iniciar a sessão
            header("Location: index.php");
            exit();  // Certifica-se de que o script não continua executando após o redirecionamento
        } else {
            $error = "Senha incorreta!";
        }
    } else {
        $error = "Usuário não encontrado!";
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

    <!-- Estilo CSS -->
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
    <!-- Logo LicitaSis centralizada -->
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
            
            <!-- Formulário de login -->
            <form action="login.php" method="POST">
                <div class="form-group">
                    <label for="email">E-mail</label>
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" placeholder="Digite seu e-mail" required autocomplete="email">
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Senha</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Digite sua senha" required autocomplete="current-password">
                        <i class="fas fa-lock"></i>
                    </div>
                </div>
                
                <button type="submit" class="login-btn">
                    <i class="fas fa-sign-in-alt"></i>
                    Entrar
                </button>
            </form>
            
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
    
    // Toggle password visibility
    document.addEventListener('DOMContentLoaded', function() {
        const passwordInput = document.getElementById('password');
        const passwordWrapper = passwordInput.parentElement;
        
        const toggleIcon = document.createElement('i');
        toggleIcon.className = 'fas fa-eye';
        toggleIcon.style.position = 'absolute';
        toggleIcon.style.right = '1rem';
        toggleIcon.style.top = '50%';
        toggleIcon.style.transform = 'translateY(-50%)';
        toggleIcon.style.color = 'var(--text-muted)';
        toggleIcon.style.cursor = 'pointer';
        toggleIcon.style.transition = 'var(--transition)';
        
        passwordWrapper.appendChild(toggleIcon);
        
        toggleIcon.addEventListener('click', function() {
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                this.className = 'fas fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                this.className = 'fas fa-eye';
            }
        });
        
        toggleIcon.addEventListener('mouseover', function() {
            this.style.color = 'var(--primary-color)';
        });
        
        toggleIcon.addEventListener('mouseout', function() {
            this.style.color = 'var(--text-muted)';
        });
    });
</script>

</body>
</html>