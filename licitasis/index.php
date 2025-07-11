<?php
session_start(); // Inicia a sessão
include('db.php');
include('permissions.php');


$permissionManager = initPermissions($pdo);

$permissionManager->requirePermission('usuarios', 'view');

$isAdmin = $permissionManager->isAdmin();


// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit(); // Garante que o código abaixo não será executado após o redirecionamento
}

// Verifica se o usuário tem permissão de administrador
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';
$isUser = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Usuário'; // Permissão de usuário
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">

    <title>Início - LicitaSis</title>
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
            display: flex;
            flex-direction: column;
        }

        /* Header */
        header {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
            padding: 0.5rem 0;
            text-align: center;
            box-shadow: var(--shadow);
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

        /* Container principal */
        .container {
            max-width: 1000px;
            margin: 2rem auto;
            padding: 2.5rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        h2 {
            text-align: center;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            font-size: 2.2rem;
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

        p {
            color: var(--medium-gray);
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }

        /* Botão de Logout */
        .logout-button {
            background: linear-gradient(135deg, var(--danger-color) 0%, #c82333 100%);
            color: white;
            padding: 0.875rem 1.75rem;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 6px rgba(220, 53, 69, 0.2);
        }

        .logout-button:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(220, 53, 69, 0.3);
        }

        .logout-button:active {
            transform: translateY(1px);
        }

        /* Footer */
        footer {
            background: var(--primary-color);
            color: white;
            padding: 1rem 0;
            text-align: center;
            font-size: 0.9rem;
            margin-top: auto;
        }

        footer p {
            color: rgba(255, 255, 255, 0.8);
            margin: 0.25rem 0;
        }

        footer a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        footer a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        .developer {
            font-weight: 600;
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

        .user-info i {
            color: var(--secondary-color);
        }

        .permission-badge {
            background: var(--secondary-color);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Info de permissão */
        .permission-info {
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            border-left: 4px solid var(--secondary-color);
        }

        .permission-info h3 {
            color: var(--primary-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .permission-info p {
            color: var(--medium-gray);
            line-height: 1.6;
        }

        /* Animações */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .container {
            animation: fadeIn 0.5s ease;
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .logo {
                max-width: 120px;
            }

            nav {
                padding: 0.5rem 0;
                overflow-x: auto;
                white-space: nowrap;
            }

            nav a {
                padding: 0.625rem 0.75rem;
                font-size: 0.85rem;
                margin: 0 0.25rem;
            }

            .dropdown-content {
                min-width: 180px;
            }

            .container {
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.75rem;
            }

            p {
                font-size: 1rem;
            }

            .logout-button {
                padding: 0.75rem 1.5rem;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .logo {
                max-width: 100px;
            }

            nav {
                padding: 0.375rem 0;
            }

            nav a {
                padding: 0.5rem 0.625rem;
                font-size: 0.8rem;
                margin: 0 0.125rem;
            }

            .container {
                padding: 1.25rem;
                margin: 1rem;
            }

            h2 {
                font-size: 1.5rem;
                margin-bottom: 1rem;
            }

            p {
                font-size: 0.9rem;
                margin-bottom: 1.5rem;
            }

            .logout-button {
                padding: 0.625rem 1.25rem;
                font-size: 0.85rem;
            }

            footer {
                font-size: 0.8rem;
                padding: 0.75rem 0;
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

<nav>
    <div class="nav-container" id="navContainer">
        <?php echo $permissionManager->generateNavigationMenu(); ?>
    </div>
</nav>

<div class="container">
    <h2>Bem-vindo(a) ao LicitaSis!</h2>
    <p>Sistema de gestão de licitações.</p>

    <!-- Botão de Logout -->
    <form action="logout.php" method="POST">
        <button type="submit" class="logout-button">
            <i class="fas fa-sign-out-alt"></i> Sair
        </button>
    </form>
</div>

<!-- Footer -->
<footer>
    <p>&copy; 2025 LicitaSis - Todos os direitos reservados.</p>
    <p>Desenvolvido por <span class="developer"><a href="https://linktr.ee/jonas_pacheco" target="_blank">Jonas Pacheco</a></span></p>
</footer>

</body>
</html>