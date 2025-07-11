<?php
session_start();
ob_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

// Definir variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";
$vendas = []; 
$searchTerm = "";

// Conexão com o banco de dados
require_once('db.php');

// Inicializa a variável $venda para evitar avisos de variável indefinida
$venda = null;

// Função para buscar os produtos de uma venda
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

// Buscar transportadoras para o select no formulário de edição
function buscarTransportadoras() {
    global $pdo;
    try {
        $sql = "SELECT id, nome FROM transportadora ORDER BY nome";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

$transportadoras = buscarTransportadoras();

// Função para buscar os detalhes de uma venda específica
if (isset($_GET['get_venda_id'])) {
    $venda_id = $_GET['get_venda_id'];
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

        $venda = $stmt->fetch(PDO::FETCH_ASSOC); // Retorna os dados da venda
        
        // Buscar os produtos associados a esta venda
        $venda['produtos'] = buscarProdutosVenda($venda_id, $pdo);
        
        echo json_encode($venda); // Retorna os dados como JSON
        exit();
    } catch (PDOException $e) {
        $error = "Erro ao buscar detalhes da venda: " . $e->getMessage();
        echo json_encode(['error' => $error]);
        exit();
    }
}

// Atualiza a classificação de uma venda via AJAX
if (isset($_POST['update_classificacao'])) {
    $venda_id = $_POST['venda_id'];
    $nova_classificacao = $_POST['classificacao'];

    try {
        $sql = "UPDATE vendas SET classificacao = :classificacao WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':classificacao', $nova_classificacao, PDO::PARAM_STR);
        $stmt->bindParam(':id', $venda_id, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode(['success' => true, 'message' => 'Classificação atualizada com sucesso!']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar a classificação: ' . $e->getMessage()]);
    }
    exit();
}

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];

    try {
        $sql = "SELECT v.id, v.numero, v.nf, c.nome_orgaos as cliente_nome, v.valor_total, v.classificacao 
                FROM vendas v
                LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
                WHERE v.numero LIKE :searchTerm OR v.cliente_uasg LIKE :searchTerm OR c.nome_orgaos LIKE :searchTerm";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();

        $vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    // Consulta para mostrar todas as vendas
    try {
        $sql = "SELECT v.id, v.nf, v.cliente_uasg, c.nome_orgaos as cliente_nome, v.valor_total, 
        IFNULL(v.classificacao, 'Pendente') as classificacao, v.status_pagamento
        FROM vendas v
        LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
        ORDER BY v.data DESC, v.nf ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar todas as vendas: " . $e->getMessage();
    }
}

// Função para excluir uma venda
if (isset($_GET['delete_venda_id'])) {
    $venda_id = $_GET['delete_venda_id'];
    try {
        // Primeiro excluir registros relacionados na tabela venda_produtos
        $sql = "DELETE FROM venda_produtos WHERE venda_id = :venda_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':venda_id', $venda_id, PDO::PARAM_INT);
        $stmt->execute();
        
        // Depois excluir a venda
        $sql = "DELETE FROM vendas WHERE id = :venda_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':venda_id', $venda_id, PDO::PARAM_INT);
        $stmt->execute();

        $success = "Venda excluída com sucesso!";
        header("Location: consulta_vendas.php?success=$success");
        exit();
    } catch (PDOException $e) {
        $error = "Erro ao excluir a venda: " . $e->getMessage();
    }
}

// Função para buscar todos os produtos
function buscarTodosProdutos() {
    global $pdo;
    try {
        $sql = "SELECT id, nome, preco_unitario, codigo FROM produtos ORDER BY nome";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

$todos_produtos = buscarTodosProdutos();

// Função para atualizar uma venda - ATUALIZADO PARA TODOS OS CAMPOS
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_venda'])) {
    $id = $_POST['id'];
    $numero = $_POST['numero'];
    $nf = $_POST['nf'];
    $cliente_uasg = $_POST['cliente_uasg'];
    $cliente = $_POST['cliente_nome'];
    $transportadora = $_POST['transportadora_id']; // Usando o ID oculto da transportadora
    $observacao = $_POST['observacao'];
    $pregao = $_POST['pregao'];
    $classificacao = $_POST['classificacao'];
    
    // Formatar datas
    $data = null;
    if (!empty($_POST['data'])) {
        $data_parts = explode('/', $_POST['data']);
        if (count($data_parts) == 3) {
            $data = $data_parts[2] . '-' . $data_parts[1] . '-' . $data_parts[0]; // Formato MySQL YYYY-MM-DD
        }
    }
    
    $data_vencimento = null;
    if (!empty($_POST['data_vencimento'])) {
        $venc_parts = explode('/', $_POST['data_vencimento']);
        if (count($venc_parts) == 3) {
            $data_vencimento = $venc_parts[2] . '-' . $venc_parts[1] . '-' . $venc_parts[0]; // Formato MySQL YYYY-MM-DD
        }
    }

    try {
        // Iniciar transação
        $pdo->beginTransaction();
        
        // Atualizar a venda principal
        $sql = "UPDATE vendas SET 
                numero = :numero, 
                nf = :nf, 
                cliente_uasg = :cliente_uasg, 
                cliente = :cliente, 
                transportadora = :transportadora, 
                observacao = :observacao, 
                pregao = :pregao, 
                data = :data, 
                data_vencimento = :data_vencimento, 
                classificacao = :classificacao 
                WHERE id = :id";
                
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':numero', $numero);
        $stmt->bindParam(':nf', $nf);
        $stmt->bindParam(':cliente_uasg', $cliente_uasg);
        $stmt->bindParam(':cliente', $cliente);
        $stmt->bindParam(':transportadora', $transportadora);
        $stmt->bindParam(':observacao', $observacao);
        $stmt->bindParam(':pregao', $pregao);
        $stmt->bindParam(':data', $data);
        $stmt->bindParam(':data_vencimento', $data_vencimento);
        $stmt->bindParam(':classificacao', $classificacao);
        $stmt->bindParam(':id', $id);

        $stmt->execute();

        // Atualizar produtos existentes
        if (isset($_POST['produto_id']) && is_array($_POST['produto_id'])) {
            $produto_ids = $_POST['produto_id'];
            $venda_produto_ids = $_POST['venda_produto_id'] ?? [];
            $quantidades = $_POST['produto_quantidade'];
            $valores_unitarios = $_POST['produto_valor_unitario'];
            $valores_totais = $_POST['produto_valor_total'];
            $observacoes_produto = $_POST['produto_observacao'] ?? [];
            
            // Atualizar produtos existentes
            for ($i = 0; $i < count($venda_produto_ids); $i++) {
                if (empty($venda_produto_ids[$i])) continue; // Pular produtos sem ID (novos)
                
                $quantidade = $quantidades[$i];
                $valor_unitario = str_replace(',', '.', str_replace('R$ ', '', $valores_unitarios[$i]));
                $valor_total = str_replace(',', '.', str_replace('R$ ', '', $valores_totais[$i]));
                $observacao_produto = $observacoes_produto[$i] ?? '';
                
                $sql_update = "UPDATE venda_produtos SET 
                              quantidade = :quantidade,
                              valor_unitario = :valor_unitario,
                              valor_total = :valor_total,
                              observacao = :observacao
                              WHERE id = :id";
                              
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->bindParam(':quantidade', $quantidade);
                $stmt_update->bindParam(':valor_unitario', $valor_unitario);
                $stmt_update->bindParam(':valor_total', $valor_total);
                $stmt_update->bindParam(':observacao', $observacao_produto);
                $stmt_update->bindParam(':id', $venda_produto_ids[$i]);
                $stmt_update->execute();
            }
            
            // Inserir novos produtos
            for ($i = count($venda_produto_ids); $i < count($produto_ids); $i++) {
                if (empty($produto_ids[$i])) continue; // Pular produtos não selecionados
                
                $produto_id = $produto_ids[$i];
                $quantidade = $quantidades[$i];
                $valor_unitario = str_replace(',', '.', str_replace('R$ ', '', $valores_unitarios[$i]));
                $valor_total = str_replace(',', '.', str_replace('R$ ', '', $valores_totais[$i]));
                $observacao_produto = $observacoes_produto[$i] ?? '';
                
                $sql_insert = "INSERT INTO venda_produtos 
                              (venda_id, produto_id, quantidade, valor_unitario, valor_total, observacao) 
                              VALUES 
                              (:venda_id, :produto_id, :quantidade, :valor_unitario, :valor_total, :observacao)";
                              
                $stmt_insert = $pdo->prepare($sql_insert);
                $stmt_insert->bindParam(':venda_id', $id);
                $stmt_insert->bindParam(':produto_id', $produto_id);
                $stmt_insert->bindParam(':quantidade', $quantidade);
                $stmt_insert->bindParam(':valor_unitario', $valor_unitario);
                $stmt_insert->bindParam(':valor_total', $valor_total);
                $stmt_insert->bindParam(':observacao', $observacao_produto);
                $stmt_insert->execute();
            }
            
            // Recalcular e atualizar o valor total da venda
            $sql_total = "SELECT SUM(valor_total) as total FROM venda_produtos WHERE venda_id = :venda_id";
            $stmt_total = $pdo->prepare($sql_total);
            $stmt_total->bindParam(':venda_id', $id);
            $stmt_total->execute();
            $novo_total = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Atualizar o valor total na tabela de vendas
            $sql_update_total = "UPDATE vendas SET valor_total = :valor_total WHERE id = :id";
            $stmt_update_total = $pdo->prepare($sql_update_total);
            $stmt_update_total->bindParam(':valor_total', $novo_total);
            $stmt_update_total->bindParam(':id', $id);
            $stmt_update_total->execute();
        }
        
        // Finalizar transação
        $pdo->commit();

        $success = "Venda atualizada com sucesso!";
        header("Location: consulta_vendas.php?success=$success");
        exit();
    } catch (PDOException $e) {
        // Em caso de erro, reverter a transação
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Erro ao atualizar a venda: " . $e->getMessage();
    }
}

// Buscar todos os produtos para o modal de adição
function buscarProdutos() {
    global $pdo;
    try {
        $sql = "SELECT id, nome, preco_unitario, codigo FROM produtos ORDER BY nome";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

$produtos = buscarProdutos();

// Calcula o total geral das vendas
try {
    $sqlTotal = "SELECT SUM(valor_total) AS total_geral FROM vendas";
    $stmtTotal = $pdo->prepare($sqlTotal);
    $stmtTotal->execute();
    $totalGeral = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total_geral'];
} catch (PDOException $e) {
    $error = "Erro ao calcular o total de vendas: " . $e->getMessage();
}

if (isset($_POST['update_status_pagamento'])) {
    $venda_id = $_POST['venda_id'];
    $novo_status = $_POST['status_pagamento'];

    try {
        $sql = "UPDATE vendas SET status_pagamento = :status_pagamento WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':status_pagamento', $novo_status, PDO::PARAM_STR);
        $stmt->bindParam(':id', $venda_id, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode(['success' => true, 'message' => 'Status de pagamento atualizado com sucesso!']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar o status de pagamento: ' . $e->getMessage()]);
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Consulta de Vendas - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
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
            padding: 0.8rem 0;
            text-align: center;
            box-shadow: var(--shadow);
            width: 100%;
            position: relative;
            z-index: 100;
        }

        .logo {
            max-width: 160px;
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
            z-index: 99;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
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
            border-radius: 0 0 var(--radius-sm) var(--radius-sm);
            overflow: hidden;
        }

        .dropdown-content a {
            display: block;
            padding: 0.875rem 1.25rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: left;
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

        /* Mensagens de erro e sucesso */
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

        /* Barra de pesquisa */
        .search-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
            margin-bottom: 2rem;
        }

        .search-bar input[type="text"] {
            flex: 1 1 300px;
            max-width: 400px;
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

        /* Tabela de resultados */
        .table-container {
            overflow-x: auto;
            margin-top: 1.5rem;
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
            white-space: nowrap;
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

        select.classificacao-select {
            padding: 0.4rem 0.6rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            background-color: white;
            color: var(--dark-gray);
        }

        select.classificacao-select:hover {
            border-color: var(--primary-color);
            box-shadow: 0 0 5px rgba(45, 137, 62, 0.3);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
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
            max-height: 80vh;
            overflow-y: auto;
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

        form {
            display: grid;
            gap: 1rem;
        }

        form label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.3rem;
            display: block;
        }

        form input[type="text"],
        form select,
        form textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background-color: #f9f9f9;
            color: var(--dark-gray);
        }

        form input[readonly],
        form select:disabled,
        form textarea[readonly] {
            background-color: #e9ecef;
            color: var(--medium-gray);
            cursor: not-allowed;
        }

        form input[type="text"]:focus:not([readonly]),
        form select:focus:not(:disabled),
        form textarea:focus:not([readonly]) {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.2);
            background-color: white;
        }

        /* Grid para campos duplos */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Produtos no modal */
        .produto-item {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: var(--radius-sm);
            margin-bottom: 10px;
            border: 1px solid #e0e0e0;
        }

        .produto-titulo {
            font-weight: bold;
            margin-bottom: 10px;
            color: var(--primary-color);
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 5px;
        }

        /* Botões */
        button, .btn-container button {
            padding: 0.875rem 1.5rem;
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
            background: var(--secondary-color);
            color: white;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        button:hover, .btn-container button:hover {
            background: var(--secondary-dark);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
            transform: translateY(-2px);
        }

        #editBtn {
            background: var(--primary-color);
            box-shadow: 0 4px 8px rgba(45, 137, 62, 0.2);
        }

        #editBtn:hover {
            background: var(--primary-dark);
            box-shadow: 0 6px 12px rgba(45, 137, 62, 0.3);
        }

        #deleteBtn {
            background: var(--danger-color);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.2);
        }

        #deleteBtn:hover {
            background: #bd2130;
            box-shadow: 0 6px 12px rgba(220, 53, 69, 0.3);
        }

        #saveBtn {
            background: var(--success-color);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
        }

        #saveBtn:hover {
            background: #1e7e34;
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
        }

        .add-produto-btn {
            background-color: var(--primary-color);
            color: white;
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 8px rgba(45, 137, 62, 0.2);
            transition: var(--transition);
        }

        .add-produto-btn:hover {
            background-color: var(--primary-dark);
            box-shadow: 0 6px 12px rgba(45, 137, 62, 0.3);
            transform: translateY(-2px);
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 2rem 1.5rem;
                padding: 2rem;
            }
        }

        @media (max-width: 992px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            nav {
                justify-content: flex-start;
                padding: 0 1rem;
            }

            nav a {
                padding: 0.75rem 0.75rem;
                font-size: 0.9rem;
            }

            .dropdown-content {
                min-width: 180px;
            }

            .container {
                padding: 1.5rem;
            }

            .search-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .search-bar input[type="text"] {
                width: 100%;
                max-width: none;
            }

            .search-bar button {
                width: 100%;
                margin-left: 0;
            }

            .modal-content {
                width: 95%;
                padding: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            header {
                padding: 0.6rem 0;
            }

            .logo {
                max-width: 120px;
            }

            .container {
                padding: 1rem;
                margin: 1rem 0.5rem;
                border-radius: var(--radius-sm);
            }

            h2 {
                font-size: 1.3rem;
                margin-bottom: 1.5rem;
            }

            .search-bar input[type="text"] {
                font-size: 0.95rem;
            }

            .search-bar button {
                font-size: 0.9rem;
            }
        }

        @media (max-width: 360px) {
            .logo {
                max-width: 100px;
            }

            .container {
                padding: 0.875rem;
                margin: 0.75rem 0.375rem;
            }

            h2 {
                font-size: 1.2rem;
            }

            .search-bar input[type="text"] {
                font-size: 0.9rem;
            }

            .search-bar button {
                font-size: 0.85rem;
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
    <h2>Consulta de Vendas</h2>

    <?php if (isset($totalGeral)): ?>
        <div class="alert alert-success" style="text-align: center;">
            <i class="fas fa-dollar-sign"></i>
            <strong>Total Geral de Vendas: R$ <?php echo number_format($totalGeral, 2, ',', '.'); ?></strong>
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

    <form action="consulta_vendas.php" method="GET">
        <div class="search-bar">
            <input type="text" name="search" id="search" placeholder="Pesquisar por número, cliente ou UASG" value="<?php echo htmlspecialchars($searchTerm); ?>" />
            <button type="submit"><i class="fas fa-search"></i> Pesquisar</button>
        </div>
    </form>

    <?php if (count($vendas) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>NF</th>
                        <th>UASG</th>
                        <th>Cliente</th>
                        <th>Valor total</th>
                        <th>Classificação</th>
                        <th>Status Pagamento</th>

                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vendas as $venda): ?>
                        <tr>
                            <td>
                                <a href="javascript:void(0);" onclick="openModal(<?php echo $venda['id']; ?>)">
                                <?php echo htmlspecialchars($venda['nf'] ?? 'N/A'); ?>
                                </a>
                            </td> 
                            <td><?php echo htmlspecialchars($venda['cliente_uasg'] ?? 'N/A'); ?></td> <!-- UASG -->
                                                       
                            <td><?php echo htmlspecialchars($venda['cliente_nome'] ?? 'N/A'); ?></td>
                            <td>R$ <?php echo isset($venda['valor_total']) ? number_format($venda['valor_total'], 2, ',', '.') : 'N/A'; ?></td>
                            <td>
                                <select class="classificacao-select" data-venda-id="<?php echo $venda['id']; ?>" onchange="atualizarClassificacao(this)">
                                    <option value="Faturada" <?php echo $venda['classificacao'] === 'Faturada' ? 'selected' : ''; ?>>Faturada</option>
                                    <option value="Comprada" <?php echo $venda['classificacao'] === 'Comprada' ? 'selected' : ''; ?>>Comprada</option>
                                    <option value="Entregue" <?php echo $venda['classificacao'] === 'Entregue' ? 'selected' : ''; ?>>Entregue</option>
                                    <option value="Liquidada" <?php echo $venda['classificacao'] === 'Liquidada' ? 'selected' : ''; ?>>Liquidada</option>
                                    <option value="Pendente" <?php echo $venda['classificacao'] === 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
                                    <option value="Devolucao" <?php echo $venda['classificacao'] === 'Devolucao' ? 'selected' : ''; ?>>Devolução</option>
                                </select>
                            </td>
                            <td>
                                <select class="status-pagamento-select" data-venda-id="<?php echo $venda['id']; ?>" onchange="atualizarStatusPagamento(this)">
                                    <option value="Não Recebido" <?php echo ($venda['status_pagamento'] === 'Não Recebido' || !$venda['status_pagamento']) ? 'selected' : ''; ?>>Não Recebido</option>
                                    <option value="Recebido" <?php echo $venda['status_pagamento'] === 'Recebido' ? 'selected' : ''; ?>>Recebido</option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($searchTerm): ?>
        <div class="alert alert-error" style="text-align: center;">
            <i class="fas fa-info-circle"></i>
            Nenhuma venda encontrada para o termo pesquisado.
        </div>
    <?php else: ?>
        <div class="alert alert-error" style="text-align: center;">
            <i class="fas fa-info-circle"></i>
            Nenhuma venda cadastrada no sistema.
        </div>
    <?php endif; ?>
</div>

<!-- Modal para visualizar detalhes da venda -->
<div id="editModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-describedby="modalDesc">
    <div class="modal-content">
        <button class="close" aria-label="Fechar modal" onclick="closeModal()">&times;</button>
        <h2 id="modalTitle">Detalhes da Venda</h2>
        <form method="POST" action="consulta_vendas.php" id="vendaForm">
            <input type="hidden" name="id" id="venda_id" />
            <input type="hidden" name="transportadora_id" id="transportadora_id" />
            
            <div class="grid-2">
                
                <div>
                    <label for="nf">Nota Fiscal:</label>
                    <input type="text" name="nf" id="nf" readonly />
                </div>
                <div>
                    <label for="cliente_uasg">UASG:</label>
                    <input type="text" name="cliente_uasg" id="cliente_uasg" readonly />
                </div>
            </div>
            
            <div class="grid-2">
                <div>
                    <label for="empenho_numero">Número de Empenho:</label>
                    <input type="text" name="empenho_numero" id="empenho_numero" readonly />
                </div>
                <div>
                    <label for="cliente_nome">Nome do Cliente:</label>
                    <input type="text" name="cliente_nome" id="cliente_nome" readonly />
                </div>
            </div>
            
            <div class="grid-2">
                
                <div>
                    <label for="valor_total">Valor Total:</label>
                    <input type="text" name="valor_total" id="valor_total" readonly />
                </div>

                <div>
                    <label for="transportadora">Transportadora:</label>
                    <select name="transportadora" id="transportadora" disabled>
                        <?php foreach ($transportadoras as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="grid-2">
                
                <div>
                    <label for="data">Data da Venda:</label>
                    <input type="text" name="data" id="data" readonly />
                </div>
                <div>
                    <label for="pregao">Pregão:</label>
                    <input type="text" name="pregao" id="pregao" readonly />
                </div>
            </div>
            
            <div class="grid-2">
                
                <div>
                    <label for="data_vencimento">Data de Vencimento:</label>
                    <input type="text" name="data_vencimento" id="data_vencimento" readonly />
                </div>
            </div>
            
            <label for="observacao">Observação:</label>
            <textarea name="observacao" id="observacao" readonly></textarea>

            <div>
                    <label for="numero">NF:</label>
                    <input type="text" name="numero" id="numero" readonly />
                </div>
            
            <label for="classificacao">Classificação:</label>
            <select name="classificacao" id="classificacao" disabled>
                <option value="Faturada">Faturada</option>
                <option value="Comprada">Comprada</option>
                <option value="Entregue">Entregue</option>
                <option value="Liquidada">Liquidada</option>
                <option value="Pendente">Pendente</option>
                <option value="Devolucao">Devolução</option>
            </select>
            
            <h3>Produtos da Venda</h3>
            <div id="produtos-container">
                <!-- Os produtos serão inseridos aqui via JavaScript -->
            </div>
            
            <button type="button" id="addProdutoBtn" class="add-produto-btn" style="display: none;">+ Adicionar Produto</button>
            
            <div class="btn-container" style="display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap;">
                <button type="submit" name="update_venda" id="saveBtn" style="display: none;">Salvar</button>
                <button type="button" id="editBtn" onclick="enableEditing()">Editar</button>
                <button type="button" id="deleteBtn" onclick="openDeleteModal()">Excluir</button>
                <button type="button" onclick="closeModal()">Fechar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal para confirmação de exclusão -->
<div id="deleteModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle" aria-describedby="deleteModalDesc">
    <div class="modal-content" style="max-width: 400px;">
        <button class="close" aria-label="Fechar modal" onclick="closeDeleteModal()">&times;</button>
        <h2 id="deleteModalTitle">Deseja realmente excluir essa venda?</h2>
        <p id="deleteModalDesc">Esta ação não pode ser desfeita.</p>
        <div class="btn-container" style="display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap;">
            <button type="button" onclick="deleteVenda()" style="background-color: var(--danger-color); color: white;">Sim, excluir</button>
            <button type="button" onclick="closeDeleteModal()">Cancelar</button>
        </div>
    </div>
</div>

<script>
    // Função para abrir o modal e carregar os dados da venda
    function openModal(id) {
        var modal = document.getElementById("editModal");
        modal.style.display = "block"; // Abre o modal

        // Limpa os produtos antes de carregar novos
        document.getElementById('produtos-container').innerHTML = '';

        // Mostra mensagem de carregamento
        document.getElementById('produtos-container').innerHTML = '<p>Carregando produtos...</p>';

        fetch('consulta_vendas.php?get_venda_id=' + id)
            .then(response => response.json())
            .then(data => {
                // Preenche os campos do modal com os dados da venda
                document.getElementById('venda_id').value = data.id;
                document.getElementById('numero').value = data.numero || '';
                document.getElementById('cliente_uasg').value = data.cliente_uasg || '';
                document.getElementById('cliente_nome').value = data.cliente_nome || '';

                // Guarda o ID da transportadora em um campo oculto
                document.getElementById('transportadora_id').value = data.transportadora || '';

                // Seleciona a transportadora correta no select
                const transportadoraSelect = document.getElementById('transportadora');
                for (let i = 0; i < transportadoraSelect.options.length; i++) {
                    if (transportadoraSelect.options[i].value == data.transportadora) {
                        transportadoraSelect.selectedIndex = i;
                        break;
                    }
                }

                document.getElementById('observacao').value = data.observacao || '';
                document.getElementById('pregao').value = data.pregao || '';
                document.getElementById('nf').value = data.nf || '';
                document.getElementById('data').value = formatarData(data.data) || '';
                document.getElementById('data_vencimento').value = formatarData(data.data_vencimento) || '';
                document.getElementById('valor_total').value = formatarValor(data.valor_total) || '';
                document.getElementById('empenho_numero').value = data.empenho_numero || '';

                // Define a classificação
                if (data.classificacao) {
                    document.getElementById('classificacao').value = data.classificacao;
                } else {
                    document.getElementById('classificacao').value = 'Pendente';
                }

                // Preenche os produtos da venda
                preencherProdutos(data.produtos || []);

                // Resetar estado do modal para visualização
                disableEditing();
            })
            .catch(error => {
                console.error('Erro ao abrir o modal:', error);
                document.getElementById('produtos-container').innerHTML = '<p>Erro ao carregar produtos.</p>';
            });
    }

    // Função para preencher os produtos no modal
    function preencherProdutos(produtos) {
        const produtosContainer = document.getElementById('produtos-container');
        produtosContainer.innerHTML = ''; // Limpar os produtos anteriores

        if (produtos.length === 0) {
            produtosContainer.innerHTML = '<p>Nenhum produto encontrado para esta venda.</p>';
            return;
        }

        produtos.forEach((produto, index) => {
            // Cria um elemento div para cada produto
            const produtoDiv = document.createElement('div');
            produtoDiv.className = 'produto-item';

            // Conteúdo HTML para o produto com campos de formulário
            produtoDiv.innerHTML = `
                <div class="produto-titulo">Produto ${index + 1}: ${produto.nome || 'Sem nome'}</div>
                <input type="hidden" name="venda_produto_id[]" value="${produto.id || ''}">
                <input type="hidden" name="produto_id[]" value="${produto.produto_id || ''}">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <label for="produto_quantidade_${index}">Quantidade:</label>
                        <input type="number" id="produto_quantidade_${index}" name="produto_quantidade[]" value="${produto.quantidade || ''}" readonly min="1" step="1" onchange="calcularTotalProduto(${index})">
                    </div>
                    <div>
                        <label for="produto_valor_unitario_${index}">Valor Unitário:</label>
                        <input type="text" id="produto_valor_unitario_${index}" name="produto_valor_unitario[]" value="${formatarValor(produto.valor_unitario) || ''}" readonly onchange="calcularTotalProduto(${index})">
                    </div>
                </div>
                <div>
                    <label for="produto_valor_total_${index}">Valor Total:</label>
                    <input type="text" id="produto_valor_total_${index}" name="produto_valor_total[]" value="${formatarValor(produto.valor_total) || ''}" readonly>
                </div>
                <div>
                    <label for="produto_observacao_${index}">Observação:</label>
                    <input type="text" id="produto_observacao_${index}" name="produto_observacao[]" value="${produto.observacao || ''}" readonly>
                </div>
            `;

            // Adiciona o div do produto ao container
            produtosContainer.appendChild(produtoDiv);
        });
    }

    // Função para adicionar um novo produto ao formulário
    function adicionarNovoProduto() {
        const produtosContainer = document.getElementById('produtos-container');
        const index = document.querySelectorAll('.produto-item').length;

        // Criar um novo div para o produto
        const novoProdutoDiv = document.createElement('div');
        novoProdutoDiv.className = 'produto-item';

        // HTML para o novo produto
        novoProdutoDiv.innerHTML = `
            <div class="produto-titulo">Novo Produto</div>
            <input type="hidden" name="venda_produto_id[]" value="">
            <div style="display: grid; grid-template-columns: 1fr; gap: 10px;">
                <div>
                    <label for="produto_id_${index}">Produto:</label>
                    <select id="produto_id_${index}" name="produto_id[]" onchange="carregarDadosProduto(this, ${index})">
                        <option value="">Selecione um produto</option>
                        <?php foreach($todos_produtos as $p): ?>
                        <option value="<?php echo $p['id']; ?>" data-preco="<?php echo $p['preco_unitario']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <div>
                    <label for="produto_quantidade_${index}">Quantidade:</label>
                    <input type="number" id="produto_quantidade_${index}" name="produto_quantidade[]" value="1" min="1" step="1" onchange="calcularTotalProduto(${index})">
                </div>
                <div>
                    <label for="produto_valor_unitario_${index}">Valor Unitário:</label>
                    <input type="text" id="produto_valor_unitario_${index}" name="produto_valor_unitario[]" value="0,00" onchange="calcularTotalProduto(${index})">
                </div>
            </div>
            <div>
                <label for="produto_valor_total_${index}">Valor Total:</label>
                <input type="text" id="produto_valor_total_${index}" name="produto_valor_total[]" value="0,00" readonly>
            </div>
            <div>
                <label for="produto_observacao_${index}">Observação:</label>
                <input type="text" id="produto_observacao_${index}" name="produto_observacao[]" value="">
            </div>
            <button type="button" onclick="removerProduto(this)" style="background-color: var(--danger-color); margin-top: 10px; color: white; border-radius: var(--radius-sm); padding: 0.5rem 1rem; border: none; cursor: pointer;">Remover</button>
        `;

        // Adicionar o novo produto ao container
        produtosContainer.appendChild(novoProdutoDiv);
    }

    // Função para remover um produto
    function removerProduto(botao) {
        const produtoDiv = botao.closest('.produto-item');
        produtoDiv.remove();
    }

    // Função para carregar os dados de um produto selecionado
    function carregarDadosProduto(select, index) {
        const opcaoSelecionada = select.options[select.selectedIndex];
        const precoUnitario = opcaoSelecionada.getAttribute('data-preco');

        if (precoUnitario) {
            document.getElementById(`produto_valor_unitario_${index}`).value = formatarValor(precoUnitario);
            calcularTotalProduto(index);
        }
    }

    // Função para calcular o valor total de um produto
    function calcularTotalProduto(index) {
        const quantidade = parseFloat(document.getElementById(`produto_quantidade_${index}`).value) || 0;
        let valorUnitario = document.getElementById(`produto_valor_unitario_${index}`).value;

        // Remover R$ e converter vírgula para ponto
        valorUnitario = valorUnitario.replace('R$', '').trim();
        valorUnitario = valorUnitario.replace(/\./g, '').replace(',', '.');
        valorUnitario = parseFloat(valorUnitario) || 0;

        const valorTotal = quantidade * valorUnitario;
        document.getElementById(`produto_valor_total_${index}`).value = formatarValor(valorTotal);
    }

    // Função para formatar data no formato dd/mm/aaaa
    function formatarData(dataStr) {
        if (!dataStr) return '';

        const data = new Date(dataStr);
        if (isNaN(data.getTime())) return dataStr; // Retorna o valor original se não for uma data válida

        const dia = String(data.getDate()).padStart(2, '0');
        const mes = String(data.getMonth() + 1).padStart(2, '0'); // Mês começa do zero
        const ano = data.getFullYear();

        return `${dia}/${mes}/${ano}`;
    }

    // Função para formatar valor em R$
    function formatarValor(valor) {
        if (valor === null || valor === undefined) return '';

        return 'R$ ' + parseFloat(valor).toFixed(2).replace('.', ',');
    }

    // Função para fechar o modal
    function closeModal() {
        var modal = document.getElementById("editModal");
        modal.style.display = "none"; // Fecha o modal

        // Resetar o formulário
        document.querySelector("#vendaForm").reset();
        document.getElementById('produtos-container').innerHTML = '';
        disableEditing();
    }

    // Função para abrir o modal de exclusão
    function openDeleteModal() {
        var deleteModal = document.getElementById("deleteModal");
        deleteModal.style.display = "block"; // Abre o modal de exclusão
    }

    // Função para fechar o modal de exclusão
    function closeDeleteModal() {
        var deleteModal = document.getElementById("deleteModal");
        deleteModal.style.display = "none"; // Fecha o modal de exclusão
    }

    // Função para excluir a venda
    function deleteVenda() {
        var vendaId = document.getElementById('venda_id').value;
        window.location.href = 'consulta_vendas.php?delete_venda_id=' + vendaId; // Redireciona para excluir
    }

    // Função para habilitar o modo de edição de todos os campos
    function enableEditing() {
        // Habilitar todos os campos para edição
        document.getElementById('numero').removeAttribute('readonly');
        document.getElementById('nf').removeAttribute('readonly');
        document.getElementById('cliente_uasg').removeAttribute('readonly');
        document.getElementById('cliente_nome').removeAttribute('readonly');
        document.getElementById('transportadora').removeAttribute('disabled');
        document.getElementById('observacao').removeAttribute('readonly');
        document.getElementById('pregao').removeAttribute('readonly');
        document.getElementById('data').removeAttribute('readonly');
        document.getElementById('data_vencimento').removeAttribute('readonly');
        document.getElementById('classificacao').removeAttribute('disabled');

        // Habilitar campos de produtos
        const produtosContainer = document.getElementById('produtos-container');
        const campoProdutos = produtosContainer.querySelectorAll('input[readonly], select[disabled]');

        campoProdutos.forEach(campo => {
            campo.removeAttribute('readonly');
            campo.removeAttribute('disabled');
        });

        // Mostrar o botão para adicionar novos produtos
        document.getElementById('addProdutoBtn').style.display = 'block';
        document.getElementById('addProdutoBtn').addEventListener('click', adicionarNovoProduto);

        // Exibe o botão de salvar e esconde o botão de editar
        document.getElementById('saveBtn').style.display = 'inline-block';
        document.getElementById('editBtn').style.display = 'none';
    }

    // Função para desabilitar edição (modo visualização)
    function disableEditing() {
        document.getElementById('numero').setAttribute('readonly', true);
        document.getElementById('nf').setAttribute('readonly', true);
        document.getElementById('cliente_uasg').setAttribute('readonly', true);
        document.getElementById('cliente_nome').setAttribute('readonly', true);
        document.getElementById('transportadora').setAttribute('disabled', true);
        document.getElementById('observacao').setAttribute('readonly', true);
        document.getElementById('pregao').setAttribute('readonly', true);
        document.getElementById('data').setAttribute('readonly', true);
        document.getElementById('data_vencimento').setAttribute('readonly', true);
        document.getElementById('classificacao').setAttribute('disabled', true);

        const produtosContainer = document.getElementById('produtos-container');
        const campoProdutos = produtosContainer.querySelectorAll('input, select');

        campoProdutos.forEach(campo => {
            campo.setAttribute('readonly', true);
            campo.setAttribute('disabled', true);
        });

        document.getElementById('addProdutoBtn').style.display = 'none';
        document.getElementById('saveBtn').style.display = 'none';
        document.getElementById('editBtn').style.display = 'inline-block';
    }

    // Função para atualizar a classificação diretamente na tabela
    function atualizarClassificacao(selectElement) {
        const vendaId = selectElement.getAttribute('data-venda-id');
        const novaClassificacao = selectElement.value;

        // Realiza requisição AJAX para atualizar a classificação
        fetch('consulta_vendas.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `update_classificacao=1&venda_id=${vendaId}&classificacao=${novaClassificacao}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Classificação atualizada com sucesso!');
            } else {
                alert('Erro ao atualizar classificação: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro ao atualizar a classificação. Verifique o console para mais detalhes.');
        });
    }

    // Adicionar listener para fechar modal com tecla ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
            closeDeleteModal();
        }
    });

    // Ao carregar a página, adicionamos o evento de atualização para todos os selects de classificação
    document.addEventListener('DOMContentLoaded', function() {
        const classificacaoSelects = document.querySelectorAll('.classificacao-select');
        classificacaoSelects.forEach(select => {
            select.addEventListener('change', function() {
                atualizarClassificacao(this);
            });
        });
    });

    function atualizarStatusPagamento(selectElement) {
    const vendaId = selectElement.getAttribute('data-venda-id');
    const novoStatus = selectElement.value;

    fetch('consulta_vendas.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `update_status_pagamento=1&venda_id=${vendaId}&status_pagamento=${novoStatus}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Status de pagamento atualizado com sucesso!');
        } else {
            alert('Erro ao atualizar status: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao atualizar o status de pagamento.');
    });
}
</script>

</body>
</html>