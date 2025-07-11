<?php
/**
 * Template de Header Padronizado para LicitaSis
 * Arquivo: includes/header_template.php
 * 
 * Este arquivo contém o header, user-info e navegação padronizados
 * que devem ser incluídos em todas as páginas do sistema
 */

// Verifica se as variáveis necessárias estão definidas
if (!isset($permissionManager)) {
    die('Erro: Sistema de permissões não inicializado. Inclua permissions.php antes deste arquivo.');
}

if (!isset($_SESSION['user'])) {
    die('Erro: Usuário não está logado.');
}

// Função para renderizar o header completo
function renderHeader($pageTitle = "LicitaSis", $currentPage = "", $includeFooter = true) {
    global $permissionManager;
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($pageTitle); ?></title>
        <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        
        <?php include('includes/common_styles.php'); ?>
        
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
            <a href="editar_perfil.php" style="color: inherit; text-decoration: none; display: flex; align-items: center; gap: 0.5rem; transition: var(--transition);">
                <i class="fas fa-user"></i>
                <span><?php echo htmlspecialchars($_SESSION['user']['name']); ?></span>
                <span class="permission-badge">
                    <?php echo $permissionManager->getPermissionName($_SESSION['user']['permission']); ?>
                </span>
            </a>
        </div>
    </header>

    <nav>
        <div class="nav-container" id="navContainer">
            <?php echo $permissionManager->generateNavigationMenu(); ?>
        </div>
    </nav>

    <?php
}

// Função para renderizar apenas os estilos CSS (caso não queira usar arquivo externo)
function renderStyles() {
    ?>
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

        /* Container principal padrão */
        .container {
            max-width: 1000px;
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

            .user-info {
                position: static;
                transform: none;
                margin-top: 1rem;
                justify-content: center;
                font-size: 0.8rem;
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
                animation: slideDown 0.3s ease;
            }

            @keyframes slideDown {
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

            .user-info {
                font-size: 0.75rem;
                padding: 0.3rem 0.8rem;
            }
        }
    </style>
    <?php
}

// Função para renderizar o JavaScript padrão
function renderScripts() {
    ?>
    <script>
        function toggleMobileMenu() {
            const navContainer = document.getElementById('navContainer');
            const menuToggle = document.querySelector('.mobile-menu-toggle i');
            
            navContainer.classList.toggle('active');
            
            // Muda o ícone do menu
            if (navContainer.classList.contains('active')) {
                menuToggle.className = 'fas fa-times';
            } else {
                menuToggle.className = 'fas fa-bars';
            }
        }

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

        // Marca o item de menu ativo
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = '<?php echo basename($_SERVER['PHP_SELF'], '.php'); ?>';
            const menuItems = document.querySelectorAll('nav a');
            
            menuItems.forEach(item => {
                const href = item.getAttribute('href');
                if (href && href.includes(currentPage)) {
                    item.style.backgroundColor = 'rgba(255,255,255,0.1)';
                    item.style.borderBottomColor = 'var(--secondary-color)';
                }
            });
        });
    </script>
    <?php
}

// Função para renderizar o footer
function renderFooter() {
    ?>
    <footer style="background: var(--primary-color); color: white; padding: 1rem 0; text-align: center; font-size: 0.9rem; margin-top: auto;">
        <p style="color: rgba(255, 255, 255, 0.8); margin: 0.25rem 0;">&copy; 2025 LicitaSis - Todos os direitos reservados.</p>
        <p style="color: rgba(255, 255, 255, 0.8); margin: 0.25rem 0;">
            Desenvolvido por 
            <a href="https://i9script.com" target="_blank" style="color: white; text-decoration: none; font-weight: 600; transition: var(--transition);">
                i9Script Technology
            </a>
        </p>
    </footer>
    <?php
}

// Função completa para renderizar a página inteira
function renderFullPage($pageTitle = "LicitaSis", $currentPage = "", $content = "", $includeFooter = true, $additionalCSS = "", $additionalJS = "") {
    global $permissionManager;
    
    renderHeader($pageTitle, $currentPage, $includeFooter);
    renderStyles();
    
    if ($additionalCSS) {
        echo "<style>$additionalCSS</style>";
    }
    ?>
    
    <div class="main-content">
        <?php echo $content; ?>
    </div>
    
    <?php
    if ($includeFooter) {
        renderFooter();
    }
    
    renderScripts();
    
    if ($additionalJS) {
        echo "<script>$additionalJS</script>";
    }
    ?>
    
    </body>
    </html>
    <?php
}

// Função simples para páginas que só precisam do header
function startPage($pageTitle = "LicitaSis", $currentPage = "") {
    renderHeader($pageTitle, $currentPage);
    renderStyles();
}

// Função para finalizar a página
function endPage($includeFooter = true, $additionalJS = "") {
    if ($includeFooter) {
        renderFooter();
    }
    
    renderScripts();
    
    if ($additionalJS) {
        echo "<script>$additionalJS</script>";
    }
    ?>
    </body>
    </html>
    <?php
}
?>