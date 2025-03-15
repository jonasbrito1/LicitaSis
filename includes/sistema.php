<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Início - LicitaSis</title>
    <style>
        /* Estilos gerais */
        html, body {
            height: 100%;
            margin: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden; /* Remover rolagem vertical */
            font-size: 16px; /* Ajusta a fonte base para 100% */
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            flex-direction: column;
            justify-content: space-between; /* Garante que o conteúdo se ajusta à tela */
        }

        header {
            background-color: rgb(157, 206, 173);
            padding: 20px 0;
            text-align: center;
            color: white;
            width: 100%;
            box-sizing: border-box;
        }

        .logo {
            max-width: 180px;
            height: auto;
        }

        nav {
            background-color: #2D893E;
            padding: 15px;
            text-align: center;
        }

        nav a {
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            font-size: 16px;
            margin: 0 10px;
            border-radius: 5px;
            display: inline-block;
        }

        nav a:hover {
            background-color: #009d8f;
        }

        .container {
            max-width: 100%;
            margin: 50px auto;
            padding: 30px;
            text-align: center;
            color: #2D893E;
            flex-grow: 1; /* Garante que o conteúdo central ocupe o restante da tela */
            box-sizing: border-box; /* Garante que o conteúdo ocupe corretamente a largura da tela */
        }

        h2 {
            font-size: 2.5em;
            color: #2D893E;
            margin-bottom: 30px;
        }

        /* Estilo do botão Sair */
        .logout-button {
            background-color: rgb(43, 192, 90);
            color: white;
            padding: 12px 20px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 20px;
        }

        .logout-button:hover {
            background-color: rgb(252, 86, 15);
        }

        /* Footer */
        footer {
            background-color: #2D893E;
            color: white;
            padding: 0px 0;
            text-align: center;
            font-size: 14px;
            width: 100%;
            position: relative;
            bottom: 0;
            box-sizing: border-box;
        }

        footer a {
            color:rgb(165, 255, 241);
            text-decoration: none;
        }

        footer a:hover {
            text-decoration: underline;
        }

        footer .developer {
            font-weight: bold;
            color: white;
        }

        /* Responsividade */
        @media screen and (max-width: 768px) {
            nav a {
                padding: 10px 15px;
                font-size: 14px;
            }

            h2 {
                font-size: 2em;
            }

            footer {
                font-size: 12px;
            }

            .logout-button {
                padding: 10px 15px;
                font-size: 14px;
            }
        }

    </style>
</head>
<body>

<header>
    <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
</header>

<nav>
    <a href="sistema.php">Início</a>
    <a href="clientes.php">Clientes</a>
    <a href="produtos.php">Produtos</a>
    <a href="empenhos.php">Empenhos</a>
    <a href="financeiro.php">Financeiro</a>
    <a href="transportadoras.php">Transportadoras</a>
    <a href="fornecedores.php">Fornecedores</a>
    <a href="vendas.php">Vendas</a>
</nav>

<div class="container">
    <h2>Bem-vindo ao LicitaSis!</h2>
    <p>Sistema de gestão de licitações.</p>

    <!-- Botão de Logout -->
    <form action="logout.php" method="POST">
        <button type="submit" class="logout-button">Sair</button>
    </form>
</div>

<!-- Footer -->
<footer>
    <p>&copy; 2025 LicitaSis - Todos os direitos reservados.</p>
    <p>Desenvolvido por <span class="developer"><a href="https://portfolio.com" target="_blank">Jonas Pacheco</a></span></p>
</footer>

</body>
</html>
