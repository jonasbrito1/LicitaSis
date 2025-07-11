<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inicializa a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financeiro - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Reset e variáveis CSS */
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
            max-width: 1200px;
            margin: 2.5rem auto;
            padding: 2.5rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
            animation: fadeIn 0.5s ease;
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

        /* Grid de Botões */
        .financial-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin-top: 2.5rem;
        }

        .financial-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            text-align: center;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .financial-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        }

        .financial-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
            border-color: var(--secondary-color);
        }

        .financial-card h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .financial-card .icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .financial-card p {
            color: var(--medium-gray);
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .card-buttons {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
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

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color) 0%, #1e7e34 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #1e7e34 0%, var(--success-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color) 0%, #e0a800 100%);
            color: #333;
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.2);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #e0a800 0%, var(--warning-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(255, 193, 7, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info-color) 0%, #117a8b 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(23, 162, 184, 0.2);
        }

        .btn-info:hover {
            background: linear-gradient(135deg, #117a8b 0%, var(--info-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(23, 162, 184, 0.3);
        }

        .btn-caixa {
            background: linear-gradient(135deg, #6f42c1 0%, #5a2d91 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(111, 66, 193, 0.2);
        }

        .btn-caixa:hover {
            background: linear-gradient(135deg, #5a2d91 0%, #6f42c1 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(111, 66, 193, 0.3);
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 2rem 1.5rem;
                padding: 2rem;
            }

            .financial-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 1.5rem;
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
                margin: 1.5rem 1rem;
            }

            h2 {
                font-size: 1.75rem;
            }

            .financial-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .financial-card {
                padding: 1.5rem;
            }

            .financial-card .icon {
                font-size: 2.5rem;
            }

            .financial-card h3 {
                font-size: 1.2rem;
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
                margin: 1rem 0.5rem;
            }

            h2 {
                font-size: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .financial-card {
                padding: 1.25rem;
            }

            .financial-card .icon {
                font-size: 2rem;
            }

            .financial-card h3 {
                font-size: 1.1rem;
            }

            .btn {
                padding: 0.75rem 1.25rem;
                font-size: 0.9rem;
            }
        }

        /* Hover effects para mobile */
        @media (hover: none) {
            .btn:active {
                transform: scale(0.98);
            }
            
            .financial-card:active {
                transform: translateY(-2px);
            }
        }
    </style>
</head>
<body>

<header>
    <a href="index.php">
        <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
    </a>
    <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
        <i class="fas fa-bars"></i>
    </button>
</header>

<nav>
    <div class="dropdown">
        <a href="clientes.php">Clientes</a>
        <div class="dropdown-content">
            <a href="cadastrar_clientes.php">Inserir Clientes</a>
            <a href="consultar_clientes.php">Consultar Clientes</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="produtos.php">Produtos</a>
        <div class="dropdown-content">
            <a href="cadastro_produto.php">Inserir Produto</a>
            <a href="consulta_produto.php">Consultar Produtos</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="empenhos.php">Empenhos</a>
        <div class="dropdown-content">
            <a href="cadastro_empenho.php">Inserir Empenho</a>
            <a href="consulta_empenho.php">Consultar Empenho</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="financeiro.php">Financeiro</a>
        <div class="dropdown-content">
            <a href="contas_a_receber.php">Contas a Receber</a>
            <a href="contas_recebidas_geral.php">Contas Recebidas</a>
            <a href="contas_a_pagar.php">Contas a Pagar</a>
            <a href="contas_pagas.php">Contas Pagas</a>
            <a href="caixa.php">Caixa</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="transportadoras.php">Transportadoras</a>
        <div class="dropdown-content">
            <a href="cadastro_transportadoras.php">Inserir Transportadora</a>
            <a href="consulta_transportadoras.php">Consultar Transportadora</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="fornecedores.php">Fornecedores</a>
        <div class="dropdown-content">
            <a href="cadastro_fornecedores.php">Inserir Fornecedor</a>
            <a href="consulta_fornecedores.php">Consultar Fornecedor</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="vendas.php">Vendas</a>
        <div class="dropdown-content">
            <a href="cadastro_vendas.php">Inserir Venda</a>
            <a href="consulta_vendas.php">Consultar Venda</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="compras.php">Compras</a>
        <div class="dropdown-content">
            <a href="cadastro_compras.php">Inserir Compras</a>
            <a href="consulta_compras.php">Consultar Compras</a>
        </div>
    </div>

    <?php if ($isAdmin): ?>
        <div class="dropdown">
            <a href="usuario.php">Usuários</a>
                <div class="dropdown-content">
                    <a href="signup.php">Inserir Novo Usuário</a>
                    <a href="consulta_usuario.php">Consultar Usuário</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Exibe o link para o cadastro de funcionários apenas para administradores -->
    <?php if ($isAdmin): ?>
        <div class="dropdown">
            <a href="funcionarios.php">Funcionários</a>
                <div class="dropdown-content">
                    <a href="cadastro_funcionario.php">Inserir Novo Funcionário</a>
                    <a href="consulta_funcionario.php">Consultar Funcionário</a>
            </div>
        </div> 
    <?php endif; ?>
</nav>

<div class="container">
    <h2><i class="fas fa-chart-line"></i> Gestão Financeira</h2>

    <div class="financial-grid">
        <!-- Card Contas a Receber -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <h3>Contas a Receber</h3>
            <p>Gerencie os valores pendentes de recebimento das suas vendas e contratos.</p>
            <div class="card-buttons">
                <a href="contas_a_receber.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Ver Contas Pendentes
                </a>
            </div>
        </div>

        <!-- Card Contas Recebidas -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>Contas Recebidas</h3>
            <p>Consulte o histórico de todas as contas já recebidas e quitadas.</p>
            <div class="card-buttons">
                <a href="contas_recebidas_geral.php" class="btn btn-success">
                    <i class="fas fa-search"></i> Ver Histórico
                </a>
            </div>
        </div>

        <!-- Card Contas a Pagar -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-credit-card"></i>
            </div>
            <h3>Contas a Pagar</h3>
            <p>Controle as despesas e pagamentos pendentes das suas compras.</p>
            <div class="card-buttons">
                <a href="contas_a_pagar.php" class="btn btn-warning">
                    <i class="fas fa-exclamation-triangle"></i> Ver Pendências
                </a>
            </div>
        </div>

        <!-- Card Contas Pagas -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-receipt"></i>
            </div>
            <h3>Contas Pagas</h3>
            <p>Visualize o histórico completo de todas as contas já pagas.</p>
            <div class="card-buttons">
                <a href="contas_pagas.php" class="btn btn-info">
                    <i class="fas fa-history"></i> Ver Histórico
                </a>
            </div>
        </div>
        <!-- Card Caixa - NOVO -->
        <div class="financial-card">
            <div class="icon">
                <i class="fas fa-cash-register"></i>
            </div>
            <h3>Controle de Caixa</h3>
            <p>Visualize o saldo atual, entradas e saídas do caixa em tempo real.</p>
            <div class="card-buttons">
                <a href="caixa.php" class="btn btn-primary">
                    <i class="fas fa-calculator"></i> Ver Caixa
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    // Toggle menu mobile
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

    // Adiciona efeitos de animação aos cards
    document.addEventListener('DOMContentLoaded', function() {
        const cards = document.querySelectorAll('.financial-card');
        
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 150);
        });
    });

    // Adiciona efeitos de hover nos botões
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
        });
        
        button.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
        
        button.addEventListener('mousedown', function() {
            this.style.transform = 'translateY(1px)';
        });
        
        button.addEventListener('mouseup', function() {
            this.style.transform = 'translateY(-2px)';
        });
    });

    console.log('Sistema Financeiro carregado com sucesso!');
</script>

</body>
</html>