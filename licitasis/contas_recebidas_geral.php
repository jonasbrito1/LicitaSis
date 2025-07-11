<?php
session_start();
ob_start();

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";
$contas_recebidas = [];
$searchTerm = "";

require_once('db.php');

// Endpoint AJAX para atualizar status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $id = intval($_POST['id'] ?? 0);
    $novo_status = $_POST['status_pagamento'] ?? '';

    if ($id > 0 && in_array($novo_status, ['Não Recebido', 'Recebido'])) {
        try {
            $sql = "UPDATE vendas SET status_pagamento = :status WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':status', $novo_status);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    }
    exit();
}

function buscarProdutosVenda($venda_id, $pdo) {
    try {
        $sql = "SELECT vp.*, p.nome 
                FROM venda_produtos vp
                JOIN produtos p ON vp.produto_id = p.id
                WHERE vp.venda_id = :venda_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':venda_id', $venda_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Busca com filtro de pesquisa
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    try {
        $sql = "SELECT v.id, v.nf, v.cliente_uasg, c.nome_orgaos as cliente_nome, v.valor_total, v.status_pagamento, v.data_vencimento 
        FROM vendas v
        LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
        WHERE v.status_pagamento = 'Recebido'
        AND (v.nf LIKE :searchTerm OR c.nome_orgaos LIKE :searchTerm OR v.cliente_uasg LIKE :searchTerm)
        ORDER BY v.data_vencimento DESC, v.nf ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        $contas_recebidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    try {
        $sql = "SELECT v.id, v.nf, v.cliente_uasg, c.nome_orgaos as cliente_nome, v.valor_total, v.status_pagamento, v.data_vencimento 
                FROM vendas v
                LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
                WHERE v.status_pagamento = 'Recebido'
                ORDER BY v.data_vencimento DESC, v.nf ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $contas_recebidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar todas as contas recebidas: " . $e->getMessage();
    }
}

// Endpoint AJAX para buscar detalhes da venda - DEVE SER DEPOIS DAS CONSULTAS PRINCIPAIS
if (isset($_GET['get_venda_id'])) {
    $venda_id = intval($_GET['get_venda_id']);
    try {
        $sql = "SELECT v.*, c.nome_orgaos as cliente_nome, t.nome as transportadora_nome, 
                e.numero as empenho_numero 
                FROM vendas v
                LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
                LEFT JOIN transportadora t ON v.transportadora = t.id
                LEFT JOIN empenhos e ON v.empenho_id = e.id
                WHERE v.id = :venda_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':venda_id', $venda_id, PDO::PARAM_INT);
        $stmt->execute();
        $venda = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($venda) {
            $venda['produtos'] = buscarProdutosVenda($venda_id, $pdo);
            echo json_encode($venda);
        } else {
            echo json_encode(['error' => "Venda não encontrada"]);
        }
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => "Erro ao buscar detalhes da venda: " . $e->getMessage()]);
        exit();
    }
}

try {
    $sqlTotal = "SELECT SUM(valor_total) AS total_geral FROM vendas WHERE status_pagamento = 'Recebido'";
    $stmtTotal = $pdo->prepare($sqlTotal);
    $stmtTotal->execute();
    $totalGeralRecebidas = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total_geral'];
} catch (PDOException $e) {
    $error = "Erro ao calcular o total de contas recebidas: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Contas Recebidas - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
<style>
    :root {
        --primary-color: #2D893E;
        --primary-light: #9DCEAC;
        --primary-dark: #1e6e2d;
        --secondary-color: #00bfae;
        --secondary-dark: #009d8f;
        --danger-color: #dc3545;
        --success-color: #28a745;
        --warning-color: #ffc107;
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
        margin: 0; padding: 0; box-sizing: border-box;
    }
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        color: var(--dark-gray);
        min-height: 100vh;
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
    .container {
        max-width: 1200px;
        margin: 2.5rem auto;
        padding: 2.5rem;
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        transition: var(--transition);
    }
    .container:hover {
        box-shadow: var(--shadow-hover);
        transform: translateY(-5px);
    }
    h2 {
        text-align: center;
        color: var(--primary-color);
        margin-bottom: 2rem;
        font-size: 1.8rem;
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
    .alert {
        padding: 1rem;
        margin-bottom: 1.5rem;
        border-radius: var(--radius-sm);
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .alert-error {
        background-color: rgba(220, 53, 69, 0.1);
        color: var(--danger-color);
        border-left: 4px solid var(--danger-color);
    }
    .alert-success {
        background-color: rgba(40, 167, 69, 0.1);
        color: var(--success-color);
        border-left: 4px solid var(--success-color);
    }
    .search-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1.5rem;
        justify-content: center;
    }
    .search-bar input[type="text"] {
        flex: 1 1 300px;
        padding: 0.875rem 1rem;
        border: 1px solid var(--border-color);
        border-radius: var(--radius-sm);
        font-size: 1rem;
        transition: var(--transition);
        background-color: #f9f9f9;
    }
    .search-bar input[type="text"]:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.2);
        background-color: white;
    }
    .search-bar button {
        padding: 0.875rem 1.5rem;
        background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
        color: white;
        border: none;
        border-radius: var(--radius-sm);
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        min-width: 120px;
        box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
    }
    .search-bar button:hover {
        background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
    }
    .table-container {
        overflow-x: auto;
        border-radius: var(--radius-sm);
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    table {
        width: 100%;
        border-collapse: collapse;
        background-color: white;
        font-size: 0.95rem;
    }
    table th, table td {
        padding: 0.875rem 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
        vertical-align: middle;
    }
    table th {
        background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
        color: white;
        font-weight: 600;
        position: sticky;
        top: 0;
        z-index: 10;
    }
    table tr:last-child td {
        border-bottom: none;
    }
    table tr:hover {
        background-color: rgba(0, 191, 174, 0.05);
    }
    table a {
        color: var(--primary-color);
        text-decoration: none;
        font-weight: 500;
        transition: var(--transition);
        display: inline-block;
    }
    table a:hover {
        color: var(--secondary-color);
        transform: translateY(-1px);
    }
    select.status-select {
        padding: 0.4rem 0.6rem;
        border-radius: var(--radius-sm);
        border: 1px solid var(--border-color);
        font-size: 0.9rem;
        cursor: pointer;
        transition: var(--transition);
        background-color: #f9f9f9;
    }
    select.status-select:hover, select.status-select:focus {
        border-color: var(--primary-color);
        background-color: white;
        outline: none;
        box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.2);
    }
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0; top: 0;
        width: 100%; height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        overflow: auto;
        animation: fadeIn 0.3s ease;
    }
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    .modal-content {
        background-color: white;
        margin: 5% auto;
        padding: 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow-hover);
        width: 90%;
        max-width: 700px;
        position: relative;
        animation: slideIn 0.3s ease;
    }
    @keyframes slideIn {
        from { transform: translateY(-50px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    .close {
        position: absolute;
        top: 1rem;
        right: 1.5rem;
        color: var(--medium-gray);
        font-size: 1.8rem;
        font-weight: bold;
        cursor: pointer;
        transition: var(--transition);
    }
    .close:hover {
        color: var(--dark-gray);
        transform: scale(1.1);
    }
    .modal h2 {
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-color);
    }
    form#vendaForm {
        display: flex;
        flex-wrap: wrap;
        gap: 1.5rem;
    }
    form#vendaForm > div {
        flex: 1 1 45%;
        display: flex;
        flex-direction: column;
    }
    form#vendaForm label {
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
    }
    form#vendaForm input {
        padding: 0.875rem 1rem;
        border: 1px solid var(--border-color);
        border-radius: var(--radius-sm);
        font-size: 1rem;
        background-color: #f9f9f9;
        transition: var(--transition);
    }
    form#vendaForm input:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.2);
        background-color: white;
    }
    form#vendaForm input[readonly] {
        background-color: #e9ecef;
        cursor: not-allowed;
    }
    form#vendaForm button {
        margin-top: 1.5rem;
        padding: 0.875rem 1.5rem;
        background: var(--primary-color);
        color: white;
        border: none;
        border-radius: var(--radius-sm);
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        align-self: flex-start;
    }
    form#vendaForm button:hover {
        background: var(--primary-dark);
    }

    /* Modal de Confirmação */
    .confirmation-modal {
        display: none;
        position: fixed;
        z-index: 2000;
        left: 0; top: 0;
        width: 100%; height: 100%;
        background-color: rgba(0, 0, 0, 0.6);
        overflow: auto;
        animation: fadeIn 0.3s ease;
    }

    .confirmation-modal-content {
        background-color: white;
        margin: 10% auto;
        padding: 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow-hover);
        width: 90%;
        max-width: 500px;
        position: relative;
        animation: slideIn 0.3s ease;
        border-top: 5px solid var(--warning-color);
    }

    .confirmation-modal h3 {
        color: var(--warning-color);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .confirmation-info {
        background-color: #f8f9fa;
        padding: 1rem;
        border-radius: var(--radius-sm);
        margin: 1rem 0;
        border-left: 4px solid var(--primary-color);
    }

    .confirmation-info p {
        margin: 0.5rem 0;
        font-size: 0.95rem;
    }

    .confirmation-info strong {
        color: var(--primary-color);
    }

    .confirmation-buttons {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 1.5rem;
    }

    .btn-confirm {
        background: var(--success-color);
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: var(--radius-sm);
        cursor: pointer;
        font-weight: 600;
        transition: var(--transition);
    }

    .btn-confirm:hover {
        background: #218838;
        transform: translateY(-1px);
    }

    .btn-cancel {
        background: var(--medium-gray);
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: var(--radius-sm);
        cursor: pointer;
        font-weight: 600;
        transition: var(--transition);
    }

    .btn-cancel:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }

    /* Responsividade */
    @media (max-width: 992px) {
        form#vendaForm > div {
            flex: 1 1 100%;
        }
        .search-bar {
            flex-direction: column;
            align-items: stretch;
        }
        .search-bar input[type="text"] {
            flex: 1 1 100%;
        }
        .search-bar button {
            width: 100%;
        }
        .confirmation-buttons {
            flex-direction: column;
        }
        .confirmation-buttons button {
            width: 100%;
        }
    }
    @media (max-width: 480px) {
        .modal-content, .confirmation-modal-content {
            padding: 1rem;
            margin: 10% 1rem;
        }
        h2 {
            font-size: 1.4rem;
        }
    }
</style>
</head>
<body>

<header>
    <a href="index.php">
        <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo" />
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
    <h2>Contas Recebidas</h2>

    <?php if (isset($totalGeralRecebidas)): ?>
        <div class="alert alert-success" style="text-align: center;">
            <i class="fas fa-dollar-sign"></i>
            <strong>Total Geral de Contas Recebidas: R$ <?php echo number_format($totalGeralRecebidas, 2, ',', '.'); ?></strong>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <form action="" method="GET" class="search-bar">
        <input type="text" name="search" id="search" placeholder="Pesquisar por NF, cliente ou UASG" value="<?php echo htmlspecialchars($searchTerm); ?>" />
        <button type="submit"><i class="fas fa-search"></i> Pesquisar</button>
    </form>

    <?php if (count($contas_recebidas) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>NF</th>
                        <th>Cliente</th>
                        <th>Valor Total</th>
                        <th>Status de Pagamento</th>
                        <th>Data de Vencimento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contas_recebidas as $conta): ?>
                        <tr data-id="<?php echo $conta['id']; ?>">
                            <td>
                                <a href="javascript:void(0);" onclick="openModal(<?php echo $conta['id']; ?>)">
                                    <?php echo htmlspecialchars($conta['nf']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($conta['cliente_nome']); ?></td>
                            <td>R$ <?php echo number_format($conta['valor_total'], 2, ',', '.'); ?></td>
                            <td>
                                <select class="status-select" data-id="<?php echo $conta['id']; ?>" 
                                        data-nf="<?php echo htmlspecialchars($conta['nf']); ?>"
                                        data-cliente="<?php echo htmlspecialchars($conta['cliente_nome']); ?>"
                                        data-valor="<?php echo number_format($conta['valor_total'], 2, ',', '.'); ?>"
                                        data-vencimento="<?php echo date('d/m/Y', strtotime($conta['data_vencimento'])); ?>">
                                    <option value="Não Recebido" <?php if ($conta['status_pagamento'] === 'Não Recebido') echo 'selected'; ?>>Não Recebido</option>
                                    <option value="Recebido" <?php if ($conta['status_pagamento'] === 'Recebido') echo 'selected'; ?>>Recebido</option>
                                </select>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($conta['data_vencimento'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($searchTerm): ?>
        <div class="alert alert-error" style="text-align: center;">
            <i class="fas fa-info-circle"></i>
            Nenhuma conta recebida encontrada para o termo pesquisado.
        </div>
    <?php else: ?>
        <div class="alert alert-error" style="text-align: center;">
            <i class="fas fa-info-circle"></i>
            Nenhuma conta recebida cadastrada no sistema.
        </div>
    <?php endif; ?>
</div>

<!-- Modal para visualizar detalhes da conta recebida -->
<div id="editModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-describedby="modalDesc">
    <div class="modal-content">
        <button class="close" aria-label="Fechar modal" onclick="closeModal()">&times;</button>
        <h2 id="modalTitle">Detalhes da Conta Recebida</h2>
        <form id="vendaForm">
            <input type="hidden" name="id" id="venda_id" />
            
            <div>
                <label for="nf">Nota Fiscal:</label>
                <input type="text" name="nf" id="nf" readonly />
            </div>
            <div>
                <label for="cliente_uasg">UASG:</label>
                <input type="text" name="cliente_uasg" id="cliente_uasg" readonly />
            </div>
            <div>
                <label for="cliente_nome">Nome do Cliente:</label>
                <input type="text" name="cliente_nome" id="cliente_nome" readonly />
            </div>
            <div>
                <label for="valor_total">Valor Total:</label>
                <input type="text" name="valor_total" id="valor_total" readonly />
            </div>
            <div>
                <label for="status_pagamento">Status de Pagamento:</label>
                <input type="text" name="status_pagamento" id="status_pagamento" readonly />
            </div>
            <div>
                <label for="data_vencimento">Data de Vencimento:</label>
                <input type="text" name="data_vencimento" id="data_vencimento" readonly />
            </div>

            <button type="button" onclick="closeModal()">Fechar</button>
        </form>
    </div>
</div>

<!-- Modal de Confirmação para alterar para Não Recebido -->
<div id="confirmationModal" class="confirmation-modal" role="dialog" aria-modal="true">
    <div class="confirmation-modal-content">
        <h3><i class="fas fa-exclamation-triangle"></i> Confirmar Alteração de Status</h3>
        <p>Deseja realmente alterar esta conta para <strong>NÃO RECEBIDA</strong>?</p>
        
        <div class="confirmation-info">
            <p><strong>NF:</strong> <span id="confirm-nf"></span></p>
            <p><strong>Cliente:</strong> <span id="confirm-cliente"></span></p>
            <p><strong>Valor:</strong> R$ <span id="confirm-valor"></span></p>
            <p><strong>Vencimento:</strong> <span id="confirm-vencimento"></span></p>
        </div>
        
        <p style="color: var(--warning-color); font-size: 0.9rem; margin-top: 1rem;">
            <i class="fas fa-info-circle"></i> Esta conta será removida da lista de contas recebidas e retornará para a lista de contas a receber.
        </p>
        
        <div class="confirmation-buttons">
            <button type="button" class="btn-cancel" onclick="closeConfirmationModal()">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="button" class="btn-confirm" onclick="confirmNotReceived()">
                <i class="fas fa-undo"></i> Confirmar Alteração
            </button>
        </div>
    </div>
</div>

<script>
    let currentSelectElement = null;
    let currentContaData = {};

    function openModal(id) {
        var modal = document.getElementById("editModal");
        modal.style.display = "block";

        // Busca os detalhes da venda no mesmo arquivo
        fetch(window.location.pathname + '?get_venda_id=' + id)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erro HTTP: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if(data.error){
                    alert('Erro: ' + data.error);
                    closeModal();
                    return;
                }
                
                // Preenche os campos do modal
                document.getElementById('venda_id').value = data.id || '';
                document.getElementById('nf').value = data.nf || '';
                document.getElementById('cliente_uasg').value = data.cliente_uasg || '';
                document.getElementById('cliente_nome').value = data.cliente_nome || '';
                document.getElementById('valor_total').value = data.valor_total ? 'R$ ' + parseFloat(data.valor_total).toFixed(2).replace('.', ',') : '';
                document.getElementById('status_pagamento').value = data.status_pagamento || '';
                document.getElementById('data_vencimento').value = data.data_vencimento ? new Date(data.data_vencimento).toLocaleDateString('pt-BR') : '';
            })
            .catch(error => {
                console.error('Erro ao abrir o modal:', error);
                alert('Erro ao carregar os detalhes da conta recebida: ' + error.message);
                closeModal();
            });
    }

    function closeModal() {
        var modal = document.getElementById("editModal");
        modal.style.display = "none";
    }

    function openConfirmationModal(selectElement) {
        currentSelectElement = selectElement;
        
        // Captura os dados da conta
        currentContaData = {
            id: selectElement.dataset.id,
            nf: selectElement.dataset.nf,
            cliente: selectElement.dataset.cliente,
            valor: selectElement.dataset.valor,
            vencimento: selectElement.dataset.vencimento
        };

        // Preenche os dados no modal de confirmação
        document.getElementById('confirm-nf').textContent = currentContaData.nf;
        document.getElementById('confirm-cliente').textContent = currentContaData.cliente;
        document.getElementById('confirm-valor').textContent = currentContaData.valor;
        document.getElementById('confirm-vencimento').textContent = currentContaData.vencimento;

        // Exibe o modal
        document.getElementById('confirmationModal').style.display = 'block';
    }

    function closeConfirmationModal() {
        // Volta o select para o valor anterior
        if (currentSelectElement) {
            currentSelectElement.value = 'Recebido';
        }
        
        document.getElementById('confirmationModal').style.display = 'none';
        currentSelectElement = null;
        currentContaData = {};
    }

    function confirmNotReceived() {
        if (!currentSelectElement || !currentContaData.id) {
            alert('Erro interno. Tente novamente.');
            closeConfirmationModal();
            return;
        }

        const id = currentContaData.id;
        const status = 'Não Recebido';

        fetch(window.location.pathname, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                update_status: '1',
                id: id,
                status_pagamento: status
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Erro HTTP: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Remove a linha da tabela com animação
                const row = document.querySelector(`tr[data-id='${id}']`);
                if (row) {
                    row.style.transition = 'all 0.3s ease';
                    row.style.opacity = '0';
                    row.style.transform = 'translateX(-100%)';
                    setTimeout(() => row.remove(), 300);
                }
                
                closeConfirmationModal();
                
                // Mostra mensagem de sucesso
                showSuccessMessage('Conta alterada para "Não Recebida" com sucesso!');
            } else {
                alert('Erro ao atualizar status: ' + (data.error || 'Erro desconhecido'));
                closeConfirmationModal();
            }
        })
        .catch(error => {
            console.error('Erro na comunicação:', error);
            alert('Erro na comunicação com o servidor: ' + error.message);
            closeConfirmationModal();
        });
    }

    function showSuccessMessage(message) {
        // Cria e exibe uma mensagem de sucesso temporária
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-success';
        alertDiv.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
        alertDiv.style.position = 'fixed';
        alertDiv.style.top = '20px';
        alertDiv.style.right = '20px';
        alertDiv.style.zIndex = '3000';
        alertDiv.style.minWidth = '300px';
        alertDiv.style.animation = 'slideInRight 0.3s ease';
        
        document.body.appendChild(alertDiv);
        
        setTimeout(() => {
            alertDiv.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.parentNode.removeChild(alertDiv);
                }
            }, 300);
        }, 3000);
    }

    // Fecha o modal ao clicar fora dele
    window.onclick = function(event) {
        var modal = document.getElementById("editModal");
        var confirmModal = document.getElementById("confirmationModal");
        
        if (event.target == modal) {
            closeModal();
        }
        if (event.target == confirmModal) {
            closeConfirmationModal();
        }
    }

    // Event listener para os selects de status
    document.querySelectorAll('.status-select').forEach(select => {
        select.addEventListener('change', function() {
            const newStatus = this.value;
            const previousStatus = this.dataset.previousValue || 'Recebido';
            
            if (newStatus === 'Não Recebido' && previousStatus !== 'Não Recebido') {
                // Abre modal de confirmação para alteração para Não Recebido
                openConfirmationModal(this);
            } else if (newStatus === 'Recebido') {
                // Atualização direta para "Recebido" (não deve acontecer normalmente nesta tela)
                updateStatus(this.dataset.id, newStatus, this);
            }
            
            // Armazena o valor atual para próxima comparação
            this.dataset.previousValue = newStatus;
        });
        
        // Inicializa o valor anterior
        select.dataset.previousValue = select.value;
    });

    function updateStatus(id, status, selectElement) {
        fetch(window.location.pathname, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                update_status: '1',
                id: id,
                status_pagamento: status
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Erro HTTP: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                selectElement.dataset.previousValue = status;
                if (status === 'Não Recebido') {
                    const row = document.querySelector(`tr[data-id='${id}']`);
                    if (row) {
                        row.style.transition = 'all 0.3s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(-100%)';
                        setTimeout(() => row.remove(), 300);
                    }
                    showSuccessMessage('Conta alterada para "Não Recebida" com sucesso!');
                }
            } else {
                alert('Erro ao atualizar status: ' + (data.error || 'Erro desconhecido'));
                selectElement.value = selectElement.dataset.previousValue || 'Recebido';
            }
        })
        .catch(error => {
            console.error('Erro na comunicação:', error);
            alert('Erro na comunicação com o servidor: ' + error.message);
            selectElement.value = selectElement.dataset.previousValue || 'Recebido';
        });
    }

    // Adiciona animações CSS
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);
</script>

</body>
</html>