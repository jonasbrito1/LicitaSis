<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

// Inicializa a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

// Conexão com o banco de dados
require_once('db.php');

$error = "";
$success = "";

// Pegamos o uasg do cliente que foi passado via GET
$cliente_uasg = isset($_GET['cliente_uasg']) ? $_GET['cliente_uasg'] : '';

// Buscamos os dados do cliente
try {
    $sql_cliente = "SELECT * FROM clientes WHERE uasg = :uasg";
    $stmt_cliente = $pdo->prepare($sql_cliente);
    $stmt_cliente->bindParam(':uasg', $cliente_uasg);
    $stmt_cliente->execute();
    
    $cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);
    
    if (!$cliente) {
        throw new Exception("Cliente não encontrado.");
    }
} catch (PDOException $e) {
    $error = "Erro ao buscar cliente: " . $e->getMessage();
}

// Buscamos os empenhos associados a esse cliente
try {
    $sql_empenhos = "SELECT * FROM empenhos WHERE cliente_uasg = :uasg";
    $stmt_empenhos = $pdo->prepare($sql_empenhos);
    $stmt_empenhos->bindParam(':uasg', $cliente_uasg);
    $stmt_empenhos->execute();
    
    $empenhos = $stmt_empenhos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Erro ao buscar empenhos: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes do Cliente e Empenhos</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">

    <style>
        /* Estilos gerais */
        html, body {
            height: 100%;
            margin: 0;
            overflow-y: auto;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
        }

        header {
            background-color: rgb(157, 206, 173);
            padding: 10px 0;
            text-align: center;
            color: white;
            width: 100%;
        }

        nav {
            background-color: #2D893E;
            padding: 0px;
            text-align: center;
            position: relative;
        }

        nav a {
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            font-size: 16px;
            margin: 0 10px;
            border-radius: 0px;
            display: inline-block;
            height: 43%;
        }

        nav a:hover {
            background-color: rgb(76, 204, 116);
            padding-top: 12px; /* Ajuste para manter a altura proporcional ao nav */
            padding-bottom: 12px;
        }

        /* Estilo do Dropdown */
        .dropdown {
            display: inline-block;
            position: relative;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #2D893E;
            min-width: 160px;
            box-shadow: 0px 8px 16px rgba(0, 0, 0, 0.2);
            z-index: 1;
        }

        .dropdown-content a {
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            display: block;
        }

        .dropdown-content a:hover {
            background-color: rgb(76, 204, 116);
        }

        .dropdown:hover .dropdown-content {
            display: block;
        }

        .logo {
            max-width: 180px;
            height: auto;
        }

        .container {
            max-width: 80%;
            margin: 50px auto;
            background-color: rgb(215, 212, 212);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(240, 240, 240, 0.1);
            color: #2D893E;
        }

        h2 {
            text-align: center;
            color: #2D893E;
            margin-bottom: 30px;
            font-size: 1.8em;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: white;
            table-layout: auto;
        }

        table th, table td {
            border: 1px solid #000;
            padding: 6px 10px;
            text-align: left;
            white-space: nowrap;
            margin: 0;
        }

        table th {
            background-color: #00bfae;
            color: white;
        }

        .btn-container {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }

        .action-button {
            padding: 12px 20px;
            font-size: 16px;
            background-color: #00bfae;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .action-button:hover {
            background-color: #009d8f;
        }
    </style>
</head>
<body>

<header>
    <a href="index.php">
        <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
    </a>
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
    <h2>Detalhes do Cliente: <?php echo htmlspecialchars($cliente['nome_orgaos']); ?></h2>

    <?php if ($error): ?>
        <p class="error" style="color: red;"><?php echo $error; ?></p>
    <?php endif; ?>

    <h3>Informações do Cliente:</h3>
    <p><strong>UASG:</strong> <?php echo htmlspecialchars($cliente['uasg']); ?></p>
    <p><strong>CNPJ:</strong> <?php echo htmlspecialchars($cliente['cnpj']); ?></p>

    <h3>Empenhos:</h3>
    <?php if (count($empenhos) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Número do Empenho</th>
                    <th>Valor Total</th>
                    <th>Data</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($empenhos as $empenho): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($empenho['numero']); ?></td>
                        <td><?php echo number_format($empenho['valor_total'], 2, ',', '.'); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($empenho['data'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>Nenhum empenho encontrado para este cliente.</p>
    <?php endif; ?>

    <div class="btn-container">
        <a href="consultar_clientes.php" class="action-button">Voltar</a>
    </div>
</div>

</body>
</html>
