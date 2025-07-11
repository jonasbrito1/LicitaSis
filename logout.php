<?php
session_start();

// Inclui os arquivos necessários
include('db.php');

// Inclui o sistema de auditoria se existir
if (file_exists('includes/audit.php')) {
    include('includes/audit.php');
}

$sessionDuration = null;

// Registra o logout se o usuário estiver logado
if (isset($_SESSION['user'])) {
    // Calcula duração da sessão se disponível
    $sessionDuration = isset($_SESSION['login_time']) ? (time() - $_SESSION['login_time']) : null;
    
    // Registra o logout na auditoria (se a função existir)
    if (function_exists('logLogout')) {
        logLogout();
    } else {
        // Fallback: registra logout manualmente se não houver sistema de auditoria
        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_log 
                (user_id, user_name, action, table_name, details, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $details = [
                'logout_time' => date('Y-m-d H:i:s'),
                'session_duration' => $sessionDuration
            ];
            
            $stmt->execute([
                $_SESSION['user']['id'],
                $_SESSION['user']['name'],
                'LOGOUT',
                'users',
                json_encode($details),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            // Silenciosamente falha se não conseguir registrar
            error_log("Erro ao registrar logout: " . $e->getMessage());
        }
    }
}

// Limpa todos os cookies de sessão ANTES de destruir a sessão
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Limpa todas as variáveis de sessão
$_SESSION = array();

// Destroi a sessão
session_destroy();

// Inicia uma nova sessão limpa (para evitar o erro de regenerate_id)
session_start();

// Agora podemos regenerar o ID da sessão com segurança
session_regenerate_id(true);

// Limpa a nova sessão também
$_SESSION = array();
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #2D893E;
            --primary-light: #9DCEAC;
            --secondary-color: #00bfae;
            --success-color: #28a745;
            --light-gray: #f8f9fa;
            --medium-gray: #6c757d;
            --dark-gray: #343a40;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --radius: 12px;
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
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: var(--dark-gray);
        }

        .logout-container {
            background: white;
            padding: 3rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
            max-width: 500px;
            width: 90%;
            animation: fadeIn 0.6s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logout-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--success-color), #20c997);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            color: white;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        h1 {
            color: var(--primary-color);
            font-size: 2rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .logout-message {
            color: var(--medium-gray);
            font-size: 1.1rem;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .session-info {
            background: var(--light-gray);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border-left: 4px solid var(--secondary-color);
        }

        .session-info p {
            margin: 0.5rem 0;
            color: var(--medium-gray);
            font-size: 0.9rem;
        }

        .btn {
            background: linear-gradient(135deg, var(--secondary-color), #009d8f);
            color: white;
            padding: 1rem 2rem;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.3);
            margin: 0.5rem;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--medium-gray), #545b62);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
        }

        .btn-secondary:hover {
            box-shadow: 0 6px 12px rgba(108, 117, 125, 0.4);
        }

        .security-notice {
            background: linear-gradient(135deg, #e8f5e8, #d4edda);
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 2rem;
            font-size: 0.85rem;
            color: #155724;
        }

        .logo-footer {
            margin-top: 2rem;
            max-width: 120px;
            opacity: 0.8;
            transition: var(--transition);
        }

        .logo-footer:hover {
            opacity: 1;
            transform: scale(1.05);
        }

        .countdown {
            font-size: 0.9rem;
            color: var(--medium-gray);
            margin-top: 1rem;
        }

        /* Status de sucesso */
        .success-badge {
            background: var(--success-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .logout-container {
                padding: 2rem;
                margin: 1rem;
            }

            h1 {
                font-size: 1.5rem;
            }

            .logout-message {
                font-size: 1rem;
            }

            .btn {
                padding: 0.875rem 1.5rem;
                font-size: 0.9rem;
                width: 100%;
                margin: 0.25rem 0;
            }
        }
    </style>
</head>
<body>

<div class="logout-container">
    <div class="logout-icon">
        <i class="fas fa-check"></i>
    </div>
    
    <div class="success-badge">
        <i class="fas fa-shield-check"></i>
        Logout Seguro Realizado
    </div>
    
    <h1>Sessão Encerrada</h1>
    
    <p class="logout-message">
        Você foi desconectado com sucesso do sistema LicitaSis. 
        Sua sessão foi encerrada e seus dados estão protegidos.
    </p>

    <?php if (isset($sessionDuration) && $sessionDuration !== null): ?>
    <div class="session-info">
        <p><strong><i class="fas fa-info-circle"></i> Informações da Sessão:</strong></p>
        <p><i class="fas fa-clock"></i> Duração: <?php echo gmdate('H:i:s', $sessionDuration); ?></p>
        <p><i class="fas fa-calendar"></i> Encerrada em: <?php echo date('d/m/Y H:i:s'); ?></p>
    </div>
    <?php endif; ?>

    <div>
        <a href="login.php" class="btn">
            <i class="fas fa-sign-in-alt"></i>
            Fazer Login Novamente
        </a>
        
        <a href="https://www.combraz.com" class="btn btn-secondary">
            <i class="fas fa-home"></i>
            Ir para Site Principal
        </a>
    </div>

    <div class="security-notice">
        <i class="fas fa-shield-alt"></i>
        <strong>Segurança:</strong> Por questões de segurança, sempre faça logout ao usar computadores compartilhados.
        Feche também todas as abas do navegador relacionadas ao sistema.
    </div>

    <div class="countdown" id="countdown">
        Redirecionamento automático para login em <span id="timer">30</span> segundos...
        <br><small>Clique em qualquer lugar para cancelar</small>
    </div>

    <img src="../public_html/assets/images/Logo_novo.png" alt="Logo ComBraz" class="logo-footer">
</div>

<script>
    // Contador regressivo para redirecionamento
    let timeLeft = 30;
    let countdownActive = true;
    const timerElement = document.getElementById('timer');
    const countdownElement = document.getElementById('countdown');
    
    const countdown = setInterval(() => {
        if (!countdownActive) {
            clearInterval(countdown);
            return;
        }
        
        timeLeft--;
        timerElement.textContent = timeLeft;
        
        if (timeLeft <= 0) {
            clearInterval(countdown);
            window.location.href = 'login.php';
        }
    }, 1000);

    // Limpa storage local e session storage por segurança
    try {
        if (typeof(Storage) !== "undefined") {
            localStorage.clear();
            sessionStorage.clear();
        }
    } catch(e) {
        // Silenciosamente falha se não conseguir limpar storage
        console.log('Storage cleanup failed:', e);
    }

    // Remove dados de autocomplete e histórico
    try {
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    } catch(e) {
        console.log('History cleanup failed:', e);
    }

    // Previne volta com botão do navegador
    window.addEventListener('popstate', function(event) {
        event.preventDefault();
        window.location.href = 'login.php';
    });

    // Adiciona entrada no histórico para prevenir volta
    try {
        window.history.pushState(null, null, window.location.href);
    } catch(e) {
        console.log('History push failed:', e);
    }

    // Animação de entrada
    document.addEventListener('DOMContentLoaded', function() {
        // Animação do ícone
        const icon = document.querySelector('.logout-icon i');
        setTimeout(() => {
            icon.style.transform = 'scale(1.2)';
            setTimeout(() => {
                icon.style.transform = 'scale(1)';
            }, 200);
        }, 500);

        // Efeito de digitação na mensagem
        const message = document.querySelector('.logout-message');
        const originalText = message.textContent;
        message.textContent = '';
        
        let i = 0;
        const typeWriter = setInterval(() => {
            if (i < originalText.length) {
                message.textContent += originalText.charAt(i);
                i++;
            } else {
                clearInterval(typeWriter);
            }
        }, 30);
    });

    // Adiciona efeitos hover nos botões
    document.querySelectorAll('.btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            if (!this.style.transform.includes('scale')) {
                this.style.transform = 'translateY(-2px) scale(1.02)';
            }
        });
        
        btn.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
        
        btn.addEventListener('mousedown', function() {
            this.style.transform = 'translateY(0) scale(0.98)';
        });
        
        btn.addEventListener('mouseup', function() {
            this.style.transform = 'translateY(-2px) scale(1.02)';
        });
    });

    // Pausa o countdown se o usuário interagir
    document.addEventListener('click', function() {
        countdownActive = false;
        clearInterval(countdown);
        countdownElement.innerHTML = '<small style="color: var(--medium-gray);">Redirecionamento automático cancelado</small>';
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Enter ou Espaço para ir para login
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            window.location.href = 'login.php';
        }
        
        // Escape para parar countdown
        if (e.key === 'Escape') {
            countdownActive = false;
            clearInterval(countdown);
            countdownElement.innerHTML = '<small style="color: var(--medium-gray);">Redirecionamento cancelado - pressione Enter para login</small>';
        }
    });

    // Previne cache da página
    window.addEventListener('beforeunload', function() {
        // Força limpeza final
        try {
            if (typeof(Storage) !== "undefined") {
                localStorage.clear();
                sessionStorage.clear();
            }
        } catch(e) {
            // Silenciosamente falha
        }
    });

    // Disable right-click context menu na página de logout (opcional)
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        return false;
    });

    // Log da página visitada (sem dados sensíveis)
    console.log('Logout page loaded at:', new Date().toISOString());
    
    // Força reload se a página for acessada via back button
    if (performance.navigation.type === 2) {
        window.location.reload();
    }
</script>

</body>
</html>