<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

// Extrair o nome do usuário da sessão
$user = $_SESSION['user']; // Dados do usuário logado
$user_name = $user['name']; // O nome do usuário logado
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema - Licita Sis</title>

    <!-- Estilo CSS -->
    <style>
        /* Estilos gerais */
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            overflow-x: hidden;
        }

        header {
            background: linear-gradient(to right, rgb(68, 112, 50), #00bfae);
            color: white;
            text-align: center;
            padding: 40px 20px;  /* Ajustando a altura do header */
            margin: 20px auto;
            width: 80%;
            font-size: 24px;
            font-weight: bold;
            animation: fadeIn 2s ease-in-out;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            border-radius: 10px; /* Suaviza as bordas do container */
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .header-logo {
            max-width: 60%; /* Ajusta o tamanho da logo */
            height: auto;
            display: inline-block;
            margin-top: 220px; /* Move a logo para baixo */
        }

        /* Mensagem de Boas-Vindas */
        .welcome-message {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            color: #006d56;
            margin-top: 0px;
        }

        /* Ajuste para centralizar o conteúdo da página */
        .container {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            text-align: center;
            position: relative;
            width: 100%;
            margin-top: 50px;
        }

        /* Seções de opções */
        .option-container {
            display: flex;
            justify-content: space-evenly;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 40px;
            width: 100%;
            padding: 0 20px;
        }

        .option-card {
            width: 30%;
            background-color: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: all 0.3s ease-in-out;
            opacity: 0;
            animation: fadeIn 1.5s ease-in-out forwards;
        }

        .option-card:nth-child(1) { animation-delay: 0.2s; }
        .option-card:nth-child(2) { animation-delay: 0.4s; }
        .option-card:nth-child(3) { animation-delay: 0.6s; }
        .option-card:nth-child(4) { animation-delay: 0.8s; }
        .option-card:nth-child(5) { animation-delay: 1s; }
        .option-card:nth-child(6) { animation-delay: 1.2s; }
        .option-card:nth-child(7) { animation-delay: 1.4s; }

        .option-card:hover {
            background-color: #f1f1f1;
            transform: translateY(-5px);
        }

        .option-card h3 {
            color: #006d56;
            font-size: 18px;
            margin-bottom: 20px;
        }

        .option-card a {
            background-color: #00bfae;
            padding: 10px;
            color: white;
            border-radius: 5px;
            text-decoration: none;
            font-size: 14px;
            margin-top: 10px;
        }

        .option-card a:hover {
            background-color: #009d8f;
        }

        /* Estilos responsivos */
        @media (max-width: 1024px) {
            .option-card {
                width: 48%; /* Para telas de tamanho médio, as opções ocupam 48% da largura */
            }
        }

        @media (max-width: 768px) {
            .option-card {
                width: 100%; /* Para telas pequenas, as opções ocupam 100% da largura */
                margin-bottom: 20px;
            }
        }

        @media (max-width: 480px) {
            .option-card {
                width: 100%; /* Para telas muito pequenas, as opções ocupam 100% da largura */
                margin-bottom: 20px;
            }
        }

        a.logout-btn {
            display: block;
            text-align: center;
            padding: 10px 20px;
            background-color: #00bfae;
            color: white;
            border-radius: 5px;
            text-decoration: none;
            font-size: 16px;
            margin-top: 20px;
        }

        a.logout-btn:hover {
            background-color: #009d8f;
        }

        /* Footer */
        footer {
            background: linear-gradient(to right, rgb(68, 112, 50), #00bfae);
            color: white;
            text-align: center;
            padding: 15px 0;
            position: sticky;
            bottom: 0;
            width: 100%;
            opacity: 0;
            animation: fadeInFooter 1.5s ease-in-out forwards;
            animation-delay: 1.6s;
        }

        footer p {
            margin: 0;
        }

        footer a {
            color: white;
            text-decoration: none;
            font-weight: bold;
        }

        footer a:hover {
            text-decoration: underline;
        }

        @keyframes fadeInFooter {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
    </style>
</head>
<body>

<header>
    <div>
        <img src="../public_html/assets/images/logolicitasis2.png" alt="Licita Sis Logo" class="header-logo">
    </div>
</header>

<!-- Mensagem de Boas-Vindas -->
<div class="welcome-message">
    Bem-vindo(a) ao LICITA SIS, <?php echo $user_name; ?>!
</div>

<div class="container">
    <!-- Seções de opções -->
    <div class="option-container">
        <!-- Seção de Clientes -->
        <div class="option-card">
            <h3>Clientes</h3>
            <a href="clientes.php">Ir para Clientes</a>
        </div>

        <!-- Seção de Produtos -->
        <div class="option-card">
            <h3>Produtos</h3>
            <a href="produtos.php">Ir para Produtos</a>
        </div>

        <!-- Seção de Fornecedores -->
        <div class="option-card">
            <h3>Fornecedores</h3>
            <a href="fornecedores.php">Ir para Fornecedores</a>
        </div>

        <!-- Seção de Transportadoras -->
        <div class="option-card">
            <h3>Transportadoras</h3>
            <a href="transportadoras.php">Ir para Transportadoras</a>
        </div>

        <!-- Seção de Empenho -->
        <div class="option-card">
            <h3>Empenho</h3>
            <a href="empenhos.php">Ir para Empenho</a>
        </div>

        <!-- Seção de Faturamento -->
        <div class="option-card">
            <h3>Faturamento</h3>
            <a href="faturamentos.php">Ir para Faturamento</a>
        </div>

        <!-- Seção de Financeiro -->
        <div class="option-card">
            <h3>Financeiro</h3>
            <a href="financeiro.php">Ir para Financeiro</a>
        </div>
    </div>
</div>

<!-- Footer -->
<footer>
    <p>&copy; 2025 Licita Sis - Todos os direitos reservados</p>
    <p>Desenvolvido por <a href="https://seu-portfolio.com" target="_blank">Jonas Pacheco</a></p>
</footer>

</body>
</html>
