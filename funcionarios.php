<?php
// ===========================================
// FUNCIONARIOS - LICITASIS (CÓDIGO COMPLETO)
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


// Inclui o header do sistema
include('includes/header_template.php');
renderHeader("Consulta de Empenhos - LicitaSis", "empenhos");
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Funcionários - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
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
        }

        .logo {
            max-width: 140px;
            height: auto;
            transition: var(--transition);
        }

        .logo:hover {
            transform: scale(1.05);
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

        /* Botões */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 2.5rem;
        }

        .btn-container a {
            background: linear-gradient(135deg, var(--secondary-color) 0%, #009d8f 100%);
            color: white;
            padding: 1rem 2rem;
            text-decoration: none;
            font-size: 1rem;
            font-weight: 600;
            border-radius: var(--radius);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 200px;
            box-shadow: 0 4px 6px rgba(0, 191, 174, 0.2);
        }

        .btn-container a:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        .btn-container a:active {
            transform: translateY(1px);
        }

        .btn-container a i {
            margin-right: 0.5rem;
            font-size: 1.1rem;
        }

        /* Animações */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .container {
            animation: fadeIn 0.5s ease;
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

            h2 {
                font-size: 1.75rem;
            }

            .btn-container {
                flex-direction: column;
                gap: 1rem;
            }

            .btn-container a {
                width: 100%;
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

            .btn-container a {
                padding: 0.875rem 1.5rem;
                font-size: 0.9rem;
            }
        }

        /* Hover effects para mobile */
        @media (hover: none) {
            .btn-container a:active {
                transform: scale(0.98);
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Funcionários</h2>
    
    <div class="btn-container">
        <a href="cadastro_funcionario.php"><i class="fas fa-user-plus"></i> Inserir Funcionário</a>
        <a href="consulta_funcionario.php"><i class="fas fa-search"></i> Consultar Funcionários</a>
    </div>
</div>

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

    // Adiciona efeitos de hover nos botões
    const buttons = document.querySelectorAll('.btn-container a');
    buttons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-3px)';
        });
        
        button.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
        
        button.addEventListener('mousedown', function() {
            this.style.transform = 'translateY(1px)';
        });
        
        button.addEventListener('mouseup', function() {
            this.style.transform = 'translateY(-3px)';
        });
    });
</script>

</body>
</html>