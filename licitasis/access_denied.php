<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inclui o sistema de permissões
include('db.php');
include('permissions.php');

$permissionManager = new PermissionManager($pdo);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso Negado - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-color: #2D893E;
            --primary-light: #9DCEAC;
            --secondary-color: #00bfae;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --light-gray: #f8f9fa;
            --medium-gray: #6c757d;
            --dark-gray: #343a40;
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

        /* Container principal */
        .container {
            max-width: 800px;
            margin: 4rem auto;
            padding: 3rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .access-denied-icon {
            font-size: 5rem;
            color: var(--danger-color);
            margin-bottom: 2rem;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        h1 {
            color: var(--danger-color);
            font-size: 2.5rem;
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .subtitle {
            color: var(--medium-gray);
            font-size: 1.2rem;
            margin-bottom: 2rem;
            font-weight: 500;
        }

        .permission-info {
            background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
            border: 1px solid #feb2b2;
            border-radius: var(--radius);
            padding: 2rem;
            margin: 2rem 0;
            border-left: 4px solid var(--danger-color);
        }

        .permission-info h3 {
            color: var(--danger-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .permission-details {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-top: 1rem;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }

        .current-permission {
            display: inline-block;
            background: var(--warning-color);
            color: var(--dark-gray);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            margin: 0.5rem;
        }

        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 2.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 1rem 2rem;
            text-decoration: none;
            font-size: 1rem;
            font-weight: 600;
            border-radius: var(--radius);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            min-width: 180px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color) 0%, #009d8f 100%);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.2);
        }

        .btn:active {
            transform: translateY(-1px);
        }

        .help-section {
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            border-radius: var(--radius);
            padding: 2rem;
            margin-top: 2rem;
            border-left: 4px solid var(--secondary-color);
        }

        .help-section h4 {
            color: var(--primary-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .help-list {
            text-align: left;
            max-width: 500px;
            margin: 0 auto;
        }

        .help-list li {
            margin: 0.5rem 0;
            color: var(--medium-gray);
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .container {
                margin: 2rem 1rem;
                padding: 2rem;
            }

            h1 {
                font-size: 2rem;
            }

            .subtitle {
                font-size: 1rem;
            }

            .access-denied-icon {
                font-size: 4rem;
            }

            .btn-container {
                flex-direction: column;
                align-items: center;
            }

            .btn {
                width: 100%;
                max-width: 300px;
            }

            .user-info {
                position: static;
                transform: none;
                margin-top: 1rem;
                justify-content: center;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 1.5rem;
            }

            h1 {
                font-size: 1.75rem;
            }

            .access-denied-icon {
                font-size: 3.5rem;
            }

            .permission-info,
            .help-section {
                padding: 1.5rem;
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

<div class="container">
    <div class="access-denied-icon">
        <i class="fas fa-ban"></i>
    </div>
    
    <h1>Acesso Negado</h1>
    <p class="subtitle">Você não possui permissão para acessar esta página</p>
    
    <div class="permission-info">
        <h3><i class="fas fa-shield-alt"></i> Informações de Permissão</h3>
        <div class="permission-details">
            <p><strong>Seu nível de acesso atual:</strong></p>
            <span class="current-permission">
                <?php echo $permissionManager->getPermissionName($_SESSION['user']['permission']); ?>
            </span>
            
            <div style="margin-top: 1.5rem;">
                <?php
                $permission = $_SESSION['user']['permission'];
                $descriptions = [
                    'Usuario_Nivel_1' => 'Você tem acesso apenas para visualização de dados. Não pode editar, criar ou excluir informações.',
                    'Usuario_Nivel_2' => 'Você pode consultar e editar dados do sistema, exceto usuários e funcionários.',
                    'Investidor' => 'Seu acesso está limitado à seção de investimentos do sistema.',
                    'Administrador' => 'Você possui acesso completo ao sistema.'
                ];
                
                echo '<p style="color: var(--medium-gray); font-style: italic;">';
                echo $descriptions[$permission] ?? 'Nível de permissão não reconhecido.';
                echo '</p>';
                ?>
            </div>
        </div>
    </div>
    
    <div class="btn-container">
        <a href="javascript:history.back()" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <a href="index.php" class="btn btn-primary">
            <i class="fas fa-home"></i> Página Inicial
        </a>
    </div>
    
    <div class="help-section">
        <h4><i class="fas fa-question-circle"></i> Precisa de Mais Acesso?</h4>
        <div class="help-list">
            <ul>
                <li>Entre em contato com o administrador do sistema</li>
                <li>Solicite uma revisão das suas permissões de acesso</li>
                <li>Verifique se você está na conta correta</li>
                <li>Consulte o manual do usuário para mais informações</li>
            </ul>
        </div>
    </div>
</div>

<script>
    // Adiciona efeitos de hover nos botões
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-3px)';
        });
        
        button.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
        
        button.addEventListener('mousedown', function() {
            this.style.transform = 'translateY(-1px)';
        });
        
        button.addEventListener('mouseup', function() {
            this.style.transform = 'translateY(-3px)';
        });
    });

    // Log da tentativa de acesso negado (para auditoria)
    console.log('Acesso negado registrado para usuário:', '<?php echo htmlspecialchars($_SESSION['user']['name']); ?>', 'em', new Date().toLocaleString());
</script>

</body>
</html>