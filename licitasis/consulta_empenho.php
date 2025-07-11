<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';
require_once('db.php');

$error = "";
$success = "";
$empenhos = [];
$searchTerm = "";

// Verifica se há uma solicitação para obter dados do empenho (AJAX)
if (isset($_GET['get_empenho_id'])) {
    $id = $_GET['get_empenho_id'];
    try {
        // Consulta para buscar os dados completos do empenho
        $sql = "SELECT e.* FROM empenhos e WHERE e.id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $empenho = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($empenho) {
            // Busca os produtos associados ao empenho
            $sql_produtos = "SELECT ep.*, p.nome AS produto_nome 
                           FROM empenho_produtos ep 
                           LEFT JOIN produtos p ON ep.produto_id = p.id 
                           WHERE ep.empenho_id = :id";
            $stmt_produtos = $pdo->prepare($sql_produtos);
            $stmt_produtos->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt_produtos->execute();
            $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

            // Adiciona os produtos ao array do empenho
            $empenho['produtos'] = $produtos;
            echo json_encode($empenho);
        } else {
            echo json_encode(['error' => 'Nenhum empenho encontrado.']);
        }
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar empenho: ' . $e->getMessage()]);
        exit();
    }
}

// Verifica se há uma solicitação de exclusão
if (isset($_POST['delete_empenho_id'])) { 
    $id = $_POST['delete_empenho_id'];

    try {
        $pdo->beginTransaction();

        // Deletar os produtos associados ao empenho
        $sql = "DELETE FROM empenho_produtos WHERE empenho_id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        // Deletar o empenho
        $sql = "DELETE FROM empenhos WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Empenho excluído com sucesso!']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['error' => 'Erro ao excluir o empenho: ' . $e->getMessage()]);
    }
    exit();
}

// Verifica se há uma solicitação de atualização da classificação (AJAX)
if (isset($_POST['update_classificacao'])) {
    $id = $_POST['empenho_id'];
    $classificacao = $_POST['classificacao'];

    try {
        $sql = "UPDATE empenhos SET classificacao = :classificacao WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':classificacao', $classificacao, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode(['success' => true, 'message' => 'Classificação atualizada com sucesso!']);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao atualizar classificação: ' . $e->getMessage()]);
    }
    exit();
}

// Verifica se há uma solicitação de atualização completa do empenho
if (isset($_POST['update_empenho'])) {
    $id = $_POST['id'];
    $numero = $_POST['numero'];
    $cliente_uasg = $_POST['cliente_uasg'];
    $cliente_nome = $_POST['cliente_nome'];
    $pregao = isset($_POST['pregao']) ? $_POST['pregao'] : '';
    $valor_total_empenho = $_POST['valor_total_empenho'];
    $classificacao = $_POST['classificacao'];

    try {
        $pdo->beginTransaction();

        // Atualiza a tabela 'empenhos'
        $sql = "UPDATE empenhos SET 
                numero = :numero,
                cliente_uasg = :cliente_uasg,
                cliente_nome = :cliente_nome,
                pregao = :pregao,
                valor_total_empenho = :valor_total_empenho,
                classificacao = :classificacao
                WHERE id = :id";
      
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':numero', $numero, PDO::PARAM_STR);
        $stmt->bindParam(':cliente_uasg', $cliente_uasg, PDO::PARAM_STR);
        $stmt->bindParam(':cliente_nome', $cliente_nome, PDO::PARAM_STR);
        $stmt->bindParam(':pregao', $pregao, PDO::PARAM_STR);
        $stmt->bindParam(':valor_total_empenho', $valor_total_empenho, PDO::PARAM_STR);
        $stmt->bindParam(':classificacao', $classificacao, PDO::PARAM_STR);
        $stmt->execute();

        // Atualiza os produtos se foram enviados
        if (isset($_POST['produto_quantidade']) && isset($_POST['produto_valor_unitario'])) {
            $quantidades = $_POST['produto_quantidade'];
            $valores_unitarios = $_POST['produto_valor_unitario'];
            $produto_ids = $_POST['produto_id'];

            // Remove todos os produtos atuais do empenho
            $sql_delete = "DELETE FROM empenho_produtos WHERE empenho_id = :id";
            $stmt_delete = $pdo->prepare($sql_delete);
            $stmt_delete->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt_delete->execute();

            // Reinsere os produtos atualizados
            foreach ($quantidades as $index => $quantidade) {
                if (!empty($quantidade) && !empty($valores_unitarios[$index])) {
                    $valor_unitario = floatval($valores_unitarios[$index]);
                    $quantidade = intval($quantidade);
                    $valor_total_produto = $quantidade * $valor_unitario;
                    $produto_id = isset($produto_ids[$index]) ? intval($produto_ids[$index]) : null;
                    $descricao = isset($_POST['produto_descricao'][$index]) ? $_POST['produto_descricao'][$index] : '';

                    $sql_insert = "INSERT INTO empenho_produtos (empenho_id, produto_id, quantidade, valor_unitario, valor_total, descricao_produto) 
                                   VALUES (:empenho_id, :produto_id, :quantidade, :valor_unitario, :valor_total, :descricao_produto)";
                    $stmt_insert = $pdo->prepare($sql_insert);
                    $stmt_insert->bindParam(':empenho_id', $id, PDO::PARAM_INT);
                    $stmt_insert->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
                    $stmt_insert->bindParam(':quantidade', $quantidade, PDO::PARAM_INT);
                    $stmt_insert->bindParam(':valor_unitario', $valor_unitario, PDO::PARAM_STR);
                    $stmt_insert->bindParam(':valor_total', $valor_total_produto, PDO::PARAM_STR);
                    $stmt_insert->bindParam(':descricao_produto', $descricao, PDO::PARAM_STR);
                    $stmt_insert->execute();
                }
            }
        }

        $pdo->commit();
        $success = "Empenho atualizado com sucesso!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Erro ao atualizar o empenho: " . $e->getMessage();
    }
}

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];

    try {
        // Consulta para buscar por número ou nome do cliente
        $sql = "SELECT e.numero, e.cliente_nome, e.valor_total_empenho, e.classificacao, e.id
                FROM empenhos e
                WHERE e.numero LIKE :searchTerm OR e.cliente_nome LIKE :searchTerm
                ORDER BY e.cliente_nome ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        $empenhos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    try {
        // Consulta para mostrar todos os empenhos
        $sql = "SELECT e.numero, e.cliente_nome, e.valor_total_empenho, e.classificacao, e.id
                FROM empenhos e
                ORDER BY e.cliente_nome ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $empenhos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar todos os empenhos: " . $e->getMessage();
    }
}

// Calcular o valor total de todos os empenhos
try {
    $sqlTotal = "SELECT SUM(valor_total_empenho) AS total_geral FROM empenhos";
    $stmtTotal = $pdo->prepare($sqlTotal);
    $stmtTotal->execute();
    $totalGeral = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total_geral'];
} catch (PDOException $e) {
    $error = "Erro ao calcular o total de empenhos: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Empenhos - Licita Sis</title>
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
            max-width: 1200px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
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

        h3 {
            color: var(--primary-color);
            margin: 1.5rem 0 1rem;
            font-size: 1.3rem;
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }

        /* Mensagens de feedback */
        .error, .success {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            text-align: center;
            animation: slideInDown 0.3s ease;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        /* Total geral */
        .total-geral {
            text-align: right;
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--radius);
            border-left: 4px solid var(--secondary-color);
        }

        /* Barra de pesquisa */
        .search-bar {
            display: flex;
            margin-bottom: 2rem;
            gap: 1rem;
        }

        .search-bar input {
            flex: 1;
            padding: 0.875rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        }

        .search-bar button {
            padding: 0.875rem 1.5rem;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-bar button:hover {
            background: #009d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
        }

        /* Tabela */
        .table-container {
            overflow-x: auto;
            margin-bottom: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        table th, table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        table th {
            background: var(--secondary-color);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        table tr:hover {
            background: var(--light-gray);
        }

        table a {
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        table a:hover {
            color: var(--primary-color);
            text-decoration: underline;
        }

        .classificacao-select {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            background: white;
            min-width: 120px;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .classificacao-select:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 2px rgba(0, 191, 174, 0.2);
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
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
            overflow-y: auto;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 90%;
            max-width: 800px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideInUp 0.3s ease;
            overflow: hidden;
        }

        @keyframes slideInUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem;
            position: relative;
        }

        .modal-header h3 {
            margin: 0;
            color: white;
            font-size: 1.5rem;
            border-bottom: none;
        }

        .close {
            color: white;
            float: right;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            position: absolute;
            right: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .close:hover {
            transform: translateY(-50%) scale(1.1);
            color: #ffdddd;
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            padding: 1rem 2rem;
            text-align: center;
            border-top: 1px solid var(--border-color);
        }

        /* Formulário do modal */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            display: block;
            font-size: 0.95rem;
        }

        input, select, textarea {
            width: 100%;
            padding: 0.875rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        }

        input[readonly], select[disabled], textarea[readonly] {
            background: var(--light-gray);
            color: var(--medium-gray);
            cursor: not-allowed;
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* Produtos no modal */
        .produtos-section {
            margin-top: 1.5rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            background: var(--light-gray);
        }

        .produto-item {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1rem;
            position: relative;
            transition: var(--transition);
        }

        .produto-item:hover {
            box-shadow: var(--shadow);
            border-color: var(--secondary-color);
        }

        .produto-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .produto-title {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .remove-produto {
            background: var(--danger-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .remove-produto:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        .add-produto {
            background: var(--success-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            padding: 0.875rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            width: 100%;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .add-produto:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .add-produto i {
            margin-right: 0.5rem;
        }

        /* Sugestões de produtos */
        .suggestions-container {
            position: absolute;
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            background: white;
            z-index: 1001;
            width: 100%;
            border-radius: 0 0 6px 6px;
            box-shadow: var(--shadow);
            margin-top: -2px;
        }

        .suggestion-item {
            padding: 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition);
            font-size: 0.95rem;
        }

        .suggestion-item:hover {
            background: var(--light-gray);
            color: var(--primary-color);
        }

        .suggestion-item:last-child {
            border-bottom: none;
        }

        /* Botões */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        button {
            padding: 0.875rem 1.5rem;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            min-width: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        button:hover {
            background: #009d8f;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
        }

        button.btn-danger {
            background: var(--danger-color);
        }

        button.btn-danger:hover {
            background: #c82333;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        button.btn-warning {
            background: var(--warning-color);
            color: var(--dark-gray);
        }

        button.btn-warning:hover {
            background: #e0a800;
            box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
        }

        /* Estados de carregamento */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .spinner {
            border: 3px solid var(--border-color);
            border-top: 3px solid var(--secondary-color);
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 1rem auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .no-results {
            text-align: center;
            color: var(--medium-gray);
            font-style: italic;
            padding: 3rem;
            font-size: 1.1rem;
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 1rem;
                padding: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .logo {
                max-width: 120px;
            }

            nav {
                padding: 0.5rem 0;
            }

            nav a {
                padding: 0.75rem 0.5rem;
                font-size: 0.85rem;
                margin: 0 0.25rem;
            }

            .dropdown-content {
                min-width: 160px;
            }

            .container {
                margin: 1rem;
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .btn-container {
                flex-direction: column;
            }

            button {
                width: 100%;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
        }

        @media (max-width: 480px) {
            .logo {
                max-width: 100px;
            }

            nav a {
                padding: 0.625rem 0.375rem;
                font-size: 0.8rem;
                margin: 0 0.125rem;
            }

            .container {
                margin: 0.5rem;
                padding: 1rem;
            }

            h2 {
                font-size: 1.25rem;
            }

            input, select, textarea {
                padding: 0.75rem;
                font-size: 0.9rem;
            }

            .modal-header {
                padding: 1rem;
            }

            .modal-body {
                padding: 1.5rem 1rem;
            }

            .search-bar {
                flex-direction: column;
            }
        }

        /* Scrollbar personalizada */
        .table-container::-webkit-scrollbar,
        .modal-body::-webkit-scrollbar,
        .suggestions-container::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track,
        .modal-body::-webkit-scrollbar-track,
        .suggestions-container::-webkit-scrollbar-track {
            background: var(--light-gray);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb,
        .modal-body::-webkit-scrollbar-thumb,
        .suggestions-container::-webkit-scrollbar-thumb {
            background: var(--medium-gray);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb:hover,
        .modal-body::-webkit-scrollbar-thumb:hover,
        .suggestions-container::-webkit-scrollbar-thumb:hover {
            background: var(--dark-gray);
        }
        
        /* Estilo para o número do empenho clicável */
        .numero-empenho {
            cursor: pointer;
            color: var(--secondary-color);
            font-weight: 600;
            transition: var(--transition);
        }
        
        .numero-empenho:hover {
            color: var(--primary-color);
            text-decoration: underline;
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
    <h2>Consulta de Empenhos</h2>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
  
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (isset($totalGeral)): ?>
        <div class="total-geral">
            <span>Valor Total Geral de Empenhos: R$ <?php echo number_format($totalGeral, 2, ',', '.'); ?></span>
        </div>
    <?php endif; ?>

    <form action="consulta_empenho.php" method="GET">
        <div class="search-bar">
            <input type="text" 
                   name="search" 
                   id="search" 
                   placeholder="Digite o número do empenho ou nome do cliente..." 
                   value="<?php echo htmlspecialchars($searchTerm ?? ''); ?>"
                   autocomplete="off">
            <button type="submit"><i class="fas fa-search"></i> Pesquisar</button>
        </div>
    </form>

    <?php if (count($empenhos) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Número</th>
                        <th>Nome do Cliente</th>
                        <th>Valor do Empenho</th>
                        <th>Classificação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($empenhos as $empenho): ?>
                        <tr>
                            <td>
                                <span class="numero-empenho" onclick="openModal(<?php echo $empenho['id']; ?>)">
                                    <?php echo htmlspecialchars($empenho['numero'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($empenho['cliente_nome'] ?? 'N/A'); ?></td>
                            <td>R$ <?php echo isset($empenho['valor_total_empenho']) ? number_format($empenho['valor_total_empenho'], 2, ',', '.') : 'N/A'; ?></td>
                            <td>
                                <select class="classificacao-select" 
                                        data-empenho-id="<?php echo $empenho['id']; ?>" 
                                        onchange="updateClassificacao(this)"
                                        title="Altere a classificação">
                                    <option value="">Selecionar</option>
                                    <option value="Faturada" <?php echo $empenho['classificacao'] === 'Faturada' ? 'selected' : ''; ?>>Faturada</option>
                                    <option value="Comprada" <?php echo $empenho['classificacao'] === 'Comprada' ? 'selected' : ''; ?>>Comprada</option>
                                    <option value="Entregue" <?php echo $empenho['classificacao'] === 'Entregue' ? 'selected' : ''; ?>>Entregue</option>
                                    <option value="Liquidada" <?php echo $empenho['classificacao'] === 'Liquidada' ? 'selected' : ''; ?>>Liquidada</option>
                                    <option value="Pendente" <?php echo $empenho['classificacao'] === 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
                                    <option value="Devolucao" <?php echo $empenho['classificacao'] === 'Devolucao' ? 'selected' : ''; ?>>Devolução</option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="no-results">
            <p>📋 Nenhum empenho encontrado.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Modal de Detalhes do Empenho -->
<div id="empenhoModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-file-invoice-dollar"></i> Detalhes do Empenho</h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="consulta_empenho.php" id="empenhoForm">
                <input type="hidden" name="update_empenho" value="1">
                <input type="hidden" name="id" id="empenho_id">

                <div class="form-row">
                    <div class="form-group">
                        <label for="numero">Número do Empenho:</label>
                        <input type="text" name="numero" id="numero" readonly>
                    </div>
                    <div class="form-group">
                        <label for="cliente_uasg">UASG:</label>
                        <input type="text" name="cliente_uasg" id="cliente_uasg" readonly>
                    </div>
                </div>

                <div class="form-group">
                    <label for="cliente_nome">Nome do Cliente:</label>
                    <input type="text" name="cliente_nome" id="cliente_nome" readonly>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="pregao">Pregão:</label>
                        <input type="text" name="pregao" id="pregao" readonly>
                    </div>
                    <div class="form-group">
                        <label for="valor_total_empenho">Valor Total do Empenho:</label>
                        <input type="number" step="0.01" name="valor_total_empenho" id="valor_total_empenho" readonly>
                    </div>
                </div>

                <div class="form-group">
                    <label for="modal_classificacao">Classificação:</label>
                    <select name="classificacao" id="modal_classificacao" disabled>
                        <option value="">Selecionar</option>
                        <option value="Faturada">Faturada</option>
                        <option value="Comprada">Comprada</option>
                        <option value="Entregue">Entregue</option>
                        <option value="Liquidada">Liquidada</option>
                        <option value="Pendente">Pendente</option>
                        <option value="Devolucao">Devolução</option>
                    </select>
                </div>

                <h3><i class="fas fa-shopping-cart"></i> Produtos</h3>
                <div id="produtos-container" class="produtos-section">
                    <!-- Os produtos serão carregados aqui dinamicamente -->
                </div>
              
                <div class="btn-container">
                    <button type="button" id="editBtn" class="btn-warning" onclick="enableEditing()">
                        <i class="fas fa-edit"></i> Editar
                    </button>
                    <button type="submit" id="saveBtn" style="display: none;">
                        <i class="fas fa-save"></i> Salvar
                    </button>
                    <button type="button" class="btn-danger" id="deleteBtn" onclick="confirmDelete()">
                        <i class="fas fa-trash-alt"></i> Excluir
                    </button>
                    <button type="button" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal de Confirmação de Exclusão -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle"></i> Confirmar Exclusão</h3>
            <span class="close" onclick="closeDeleteModal()">&times;</span>
        </div>
        <div class="modal-body">
            <p style="text-align: center; margin-bottom: 2rem;">
                Tem certeza que deseja excluir este empenho?<br>
                <strong style="color: var(--danger-color);">Esta ação não pode ser desfeita.</strong>
            </p>
            <div class="btn-container">
                <button type="button" class="btn-danger" onclick="deleteEmpenho()" id="confirmDeleteBtn">
                    <i class="fas fa-trash-alt"></i> Sim, Excluir
                </button>
                <button type="button" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentEmpenhoId = null;

function openModal(id) {
    currentEmpenhoId = id;
    var modal = document.getElementById("empenhoModal");
    modal.style.display = "block";
    document.body.style.overflow = "hidden"; // Previne scroll da página

    // Adiciona loading
    document.getElementById('produtos-container').innerHTML = '<div class="spinner"></div>';

    fetch('consulta_empenho.php?get_empenho_id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Erro: ' + data.error);
                return;
            }

            // Preenche os campos básicos do empenho
            document.getElementById('empenho_id').value = data.id || '';
            document.getElementById('numero').value = data.numero || '';
            document.getElementById('cliente_uasg').value = data.cliente_uasg || '';
            document.getElementById('cliente_nome').value = data.cliente_nome || '';
            document.getElementById('pregao').value = data.pregao || '';
            document.getElementById('valor_total_empenho').value = data.valor_total_empenho || '';
            document.getElementById('modal_classificacao').value = data.classificacao || '';

            // Preenche os produtos
            var produtosContainer = document.getElementById('produtos-container');
            produtosContainer.innerHTML = '';

            if (data.produtos && data.produtos.length > 0) {
                data.produtos.forEach((produto, index) => {
                    adicionarProdutoExistente(produto, index);
                });
            } else {
                produtosContainer.innerHTML = '<p class="no-results"><i class="fas fa-box-open"></i> Nenhum produto cadastrado para este empenho.</p>';
            }
        })
        .catch(error => {
            console.error('Erro ao buscar os dados do empenho:', error);
            alert('Erro ao carregar os dados do empenho.');
        });
}

function adicionarProdutoExistente(produto, index) {
    var produtosContainer = document.getElementById('produtos-container');
  
    var produtoDiv = document.createElement('div');
    produtoDiv.className = 'produto-item';
  
    produtoDiv.innerHTML = `
        <div class="produto-header">
            <div class="produto-title">Produto ${index + 1}</div>
            <button type="button" class="remove-produto" onclick="removeProduto(this)" style="display: none;">
                <i class="fas fa-trash-alt"></i> Remover
            </button>
        </div>
      
        <input type="hidden" name="produto_id[]" value="${produto.produto_id || ''}">
      
        <div class="form-group">
            <label>Nome do Produto:</label>
            <div style="position: relative;">
                <input type="text" name="produto_descricao[]" value="${produto.produto_nome || produto.descricao_produto || ''}" readonly oninput="fetchProductSuggestions(this)">
                <div class="suggestions-container"></div>
            </div>
        </div>
      
        <div class="form-row">
            <div class="form-group">
                <label>Quantidade:</label>
                <input type="number" name="produto_quantidade[]" value="${produto.quantidade || ''}" readonly oninput="updateProductTotal(this)">
            </div>
            <div class="form-group">
                <label>Valor Unitário:</label>
                <input type="number" step="0.01" name="produto_valor_unitario[]" value="${produto.valor_unitario || ''}" readonly oninput="updateProductTotal(this)">
            </div>
        </div>
      
        <div class="form-group">
            <label>Valor Total:</label>
            <input type="number" step="0.01" name="produto_valor_total[]" value="${produto.valor_total || ''}" readonly>
        </div>
    `;
  
    produtosContainer.appendChild(produtoDiv);
}

function addNewProduct() {
    var produtosContainer = document.getElementById('produtos-container');
  
    // Remove mensagem de "nenhum produto" se existir
    var noResults = produtosContainer.querySelector('.no-results');
    if (noResults) {
        noResults.remove();
    }
  
    var productCount = produtosContainer.querySelectorAll('.produto-item').length + 1;

    var produtoDiv = document.createElement('div');
    produtoDiv.className = 'produto-item';
  
    produtoDiv.innerHTML = `
        <div class="produto-header">
            <div class="produto-title">Produto ${productCount}</div>
            <button type="button" class="remove-produto" onclick="removeProduto(this)">
                <i class="fas fa-trash-alt"></i> Remover
            </button>
        </div>
      
        <input type="hidden" name="produto_id[]" value="">
      
        <div class="form-group">
            <label>Nome do Produto:</label>
            <div style="position: relative;">
                <input type="text" name="produto_descricao[]" placeholder="Digite o nome do produto..." oninput="fetchProductSuggestions(this)" autocomplete="off" required>
                <div class="suggestions-container"></div>
            </div>
        </div>
      
        <div class="form-row">
            <div class="form-group">
                <label>Quantidade:</label>
                <input type="number" name="produto_quantidade[]" min="1" value="1" oninput="updateProductTotal(this)" required>
            </div>
            <div class="form-group">
                <label>Valor Unitário:</label>
                <input type="number" step="0.01" name="produto_valor_unitario[]" min="0.01" value="0.00" oninput="updateProductTotal(this)" required>
            </div>
        </div>
      
        <div class="form-group">
            <label>Valor Total:</label>
            <input type="number" step="0.01" name="produto_valor_total[]" value="0.00" readonly>
        </div>
    `;

    produtosContainer.appendChild(produtoDiv);
  
    // Foca no campo de nome do produto
    produtoDiv.querySelector('input[name="produto_descricao[]"]').focus();
}

function removeProduto(button) {
    var produtoDiv = button.closest('.produto-item');
    var produtosContainer = document.getElementById('produtos-container');
  
    // Confirmação antes de remover
    if (confirm('Tem certeza que deseja remover este produto?')) {
        produtoDiv.remove();
        updateTotalEmpenho();
      
        // Renumera os produtos restantes
        var produtos = produtosContainer.querySelectorAll('.produto-item');
        produtos.forEach((produto, index) => {
            produto.querySelector('.produto-title').textContent = `Produto ${index + 1}`;
        });
      
        // Se não há mais produtos, mostra mensagem
        if (produtos.length === 0) {
            produtosContainer.innerHTML = '<p class="no-results"><i class="fas fa-box-open"></i> Nenhum produto cadastrado.</p>';
        }
    }
}

function updateProductTotal(element) {
    var produtoDiv = element.closest('.produto-item');
    var quantidade = parseFloat(produtoDiv.querySelector('input[name="produto_quantidade[]"]').value) || 0;
    var valorUnitario = parseFloat(produtoDiv.querySelector('input[name="produto_valor_unitario[]"]').value) || 0;
    var valorTotal = quantidade * valorUnitario;

    produtoDiv.querySelector('input[name="produto_valor_total[]"]').value = valorTotal.toFixed(2);
    updateTotalEmpenho();
}

function updateTotalEmpenho() {
    var produtoTotals = document.querySelectorAll('input[name="produto_valor_total[]"]');
    var total = 0;

    produtoTotals.forEach(input => {
        total += parseFloat(input.value) || 0;
    });

    document.getElementById('valor_total_empenho').value = total.toFixed(2);
}

function fetchProductSuggestions(inputElement) {
    const query = inputElement.value.trim();
    const suggestionsContainer = inputElement.nextElementSibling;

    if (query.length > 0) {
        fetch(`search_produtos.php?query=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                suggestionsContainer.innerHTML = '';

                if (data.length > 0) {
                    data.forEach(produto => {
                        const suggestionItem = document.createElement('div');
                        suggestionItem.classList.add('suggestion-item');
                        suggestionItem.textContent = produto.nome;

                        suggestionItem.onclick = function() {
                            inputElement.value = produto.nome;
                            suggestionsContainer.innerHTML = '';
                            suggestionsContainer.style.display = 'none';

                            const produtoContainer = inputElement.closest('.produto-item');
                            const produtoIdInput = produtoContainer.querySelector('input[name="produto_id[]"]');
                            const valorUnitarioInput = produtoContainer.querySelector('input[name="produto_valor_unitario[]"]');

                            produtoIdInput.value = produto.id;
                            valorUnitarioInput.value = parseFloat(produto.preco_unitario || 0).toFixed(2);
                          
                            updateProductTotal(valorUnitarioInput);
                        };

                        suggestionsContainer.appendChild(suggestionItem);
                    });
                } else {
                    const noResult = document.createElement('div');
                    noResult.classList.add('suggestion-item');
                    noResult.innerHTML = '<i class="fas fa-exclamation-circle"></i> Nenhum produto encontrado';
                    noResult.style.fontStyle = 'italic';
                    noResult.style.color = 'var(--medium-gray)';
                    suggestionsContainer.appendChild(noResult);
                }

                suggestionsContainer.style.display = 'block';
            })
            .catch(error => {
                console.error('Erro ao buscar os produtos:', error);
                suggestionsContainer.innerHTML = '<div class="suggestion-item" style="color: var(--danger-color);"><i class="fas fa-exclamation-triangle"></i> Erro ao buscar produtos</div>';
                suggestionsContainer.style.display = 'block';
            });
    } else {
        suggestionsContainer.innerHTML = '';
        suggestionsContainer.style.display = 'none';
    }
}

function enableEditing() {
    // Habilita edição dos campos
    document.getElementById('numero').readOnly = false;
    document.getElementById('cliente_uasg').readOnly = false;
    document.getElementById('cliente_nome').readOnly = false;
    document.getElementById('pregao').readOnly = false;
    document.getElementById('valor_total_empenho').readOnly = false;
    document.getElementById('modal_classificacao').disabled = false;

    // Habilita edição dos produtos
    var produtoInputs = document.querySelectorAll('#produtos-container input');
    produtoInputs.forEach(input => {
        if (input.name !== 'produto_id[]') {
            input.readOnly = false;
        }
    });

    // Mostra botões de remover produtos existentes
    var removeButtons = document.querySelectorAll('.remove-produto');
    removeButtons.forEach(button => {
        button.style.display = 'flex';
    });

    // Adiciona o botão para adicionar novos produtos
    var produtosContainer = document.getElementById('produtos-container');
  
    // Remove o botão existente se houver
    const existingBtn = document.getElementById('addProdutoBtn');
    if (existingBtn) {
        existingBtn.remove();
    }
  
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'add-produto';
    addBtn.id = 'addProdutoBtn';
    addBtn.innerHTML = '<i class="fas fa-plus"></i> Adicionar Produto';
    addBtn.onclick = addNewProduct;
    produtosContainer.appendChild(addBtn);

    // Alterna botões
    document.getElementById('editBtn').style.display = 'none';
    document.getElementById('saveBtn').style.display = 'flex';
}

function disableEditing() {
    // Desabilita edição dos campos
    document.getElementById('numero').readOnly = true;
    document.getElementById('cliente_uasg').readOnly = true;
    document.getElementById('cliente_nome').readOnly = true;
    document.getElementById('pregao').readOnly = true;
    document.getElementById('valor_total_empenho').readOnly = true;
    document.getElementById('modal_classificacao').disabled = true;

    // Desabilita edição dos produtos
    var produtoInputs = document.querySelectorAll('#produtos-container input');
    produtoInputs.forEach(input => {
        input.readOnly = true;
    });

    // Esconde botões de remover produtos
    var removeButtons = document.querySelectorAll('.remove-produto');
    removeButtons.forEach(button => {
        button.style.display = 'none';
    });

    // Remove o botão para adicionar novos produtos
    const addBtn = document.getElementById('addProdutoBtn');
    if (addBtn) {
        addBtn.remove();
    }

    // Alterna botões
    document.getElementById('editBtn').style.display = 'flex';
    document.getElementById('saveBtn').style.display = 'none';
}

function closeModal() {
    document.getElementById("empenhoModal").style.display = "none";
    document.body.style.overflow = "auto"; // Restaura scroll da página
    disableEditing(); // Reset do formulário
}

function confirmDelete() {
    document.getElementById('deleteModal').style.display = 'block';
    document.body.style.overflow = "hidden";
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
    document.body.style.overflow = "auto";
}

function deleteEmpenho() {
    if (!currentEmpenhoId) {
        alert('Erro: ID do empenho não encontrado.');
        return;
    }

    // Adiciona loading no botão
    var deleteBtn = document.getElementById('confirmDeleteBtn');
    var originalText = deleteBtn.innerHTML;
    deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';
    deleteBtn.disabled = true;

    var formData = new FormData();
    formData.append('delete_empenho_id', currentEmpenhoId);

    fetch('consulta_empenho.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Empenho excluído com sucesso!');
            closeDeleteModal();
            closeModal();
            location.reload(); // Recarrega a página para atualizar a lista
        } else {
            alert('❌ Erro ao excluir empenho: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        console.error('Erro ao excluir empenho:', error);
        alert('❌ Erro ao excluir empenho.');
    })
    .finally(() => {
        deleteBtn.innerHTML = originalText;
        deleteBtn.disabled = false;
    });
}


function updateClassificacao(selectElement) {
    var empenhoId = selectElement.getAttribute('data-empenho-id');
    var classificacao = selectElement.value;
    var originalValue = selectElement.defaultValue;

    // Feedback visual
    selectElement.classList.add('loading');

    var formData = new FormData();
    formData.append('update_classificacao', '1');
    formData.append('empenho_id', empenhoId);
    formData.append('classificacao', classificacao);


    fetch('consulta_empenho.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            selectElement.defaultValue = classificacao;
            // Opcional: mostrar toast de sucesso
        } else {
            alert('❌ Erro ao atualizar classificação: ' + (data.error || 'Erro desconhecido'));
            selectElement.value = originalValue;
        }
    })
    .catch(error => {
        console.error('Erro ao atualizar classificação:', error);
        alert('❌ Erro ao atualizar classificação.');
        selectElement.value = originalValue;
    })
    .finally(() => {
        selectElement.classList.remove('loading');
    });
}

function addNewProduct() {
    var produtosContainer = document.getElementById('produtos-container');
    
    // Remove mensagem de "nenhum produto" se existir
    var noResults = produtosContainer.querySelector('.no-results');
    if (noResults) {
        noResults.remove();
    }
    
    var productCount = produtosContainer.querySelectorAll('.produto-item').length + 1;

    var produtoDiv = document.createElement('div');
    produtoDiv.className = 'produto-item';
    produtoDiv.innerHTML = `
        <button type="button" class="remove-produto-btn" onclick="removeProduto(this)">❌ Remover</button>
        <h4>🛍️ Produto ${productCount}</h4>
        <input type="hidden" name="produto_id[]" value="">
        
        <div class="form-group">
            <label>Nome do Produto:</label>
            <div style="position: relative;">
                <input type="text" name="produto_descricao[]" placeholder="Digite o nome do produto..." oninput="fetchProductSuggestions(this)" autocomplete="off" required>
                <div class="suggestions-container"></div>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Quantidade:</label>
                <input type="number" name="produto_quantidade[]" min="1" value="1" oninput="updateProductTotal(this)" required>
            </div>
            <div class="form-group">
                <label>Valor Unitário:</label>
                <input type="number" step="0.01" name="produto_valor_unitario[]" min="0.01" value="0.00" oninput="updateProductTotal(this)" required>
            </div>
        </div>
        
        <div class="form-group">
            <label>Valor Total:</label>
            <input type="number" step="0.01" name="produto_valor_total[]" value="0.00" readonly>
        </div>
    `;

    produtosContainer.appendChild(produtoDiv);
    
    // Renumera todos os produtos
    renumberProducts();
    
    // Foca no campo de nome do produto
    produtoDiv.querySelector('input[name="produto_descricao[]"]').focus();
}

function removeProduto(button) {
    var produtoDiv = button.closest('.produto-item');
    var produtosContainer = document.getElementById('produtos-container');
    
    // Confirmação antes de remover
    if (confirm('Tem certeza que deseja remover este produto?')) {
        produtoDiv.remove();
        updateTotalEmpenho();
        renumberProducts();
        
        // Se não há mais produtos, mostra mensagem
        if (produtosContainer.querySelectorAll('.produto-item').length === 0) {
            produtosContainer.innerHTML = '<p class="no-results">📦 Nenhum produto cadastrado.</p>';
        }
    }
}

function renumberProducts() {
    var produtos = document.querySelectorAll('#produtos-container .produto-item');
    produtos.forEach(function(produto, index) {
        var titulo = produto.querySelector('h4');
        if (titulo) {
            titulo.textContent = '🛍️ Produto ' + (index + 1);
        }
    });
}

function fetchProductSuggestions(inputElement) {
    const query = inputElement.value.trim();
    const suggestionsContainer = inputElement.nextElementSibling;

    if (query.length > 0) {
        fetch(`search_produtos.php?query=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                suggestionsContainer.innerHTML = '';

                if (data.length > 0) {
                    data.forEach(produto => {
                        const suggestionItem = document.createElement('div');
                        suggestionItem.classList.add('suggestion-item');
                        suggestionItem.textContent = produto.nome;

                        suggestionItem.onclick = function() {
                            inputElement.value = produto.nome;
                            suggestionsContainer.innerHTML = '';
                            suggestionsContainer.style.display = 'none';

                            const produtoContainer = inputElement.closest('.produto-item');
                            const produtoIdInput = produtoContainer.querySelector('input[name="produto_id[]"]');
                            const valorUnitarioInput = produtoContainer.querySelector('input[name="produto_valor_unitario[]"]');

                            produtoIdInput.value = produto.id;
                            valorUnitarioInput.value = parseFloat(produto.preco_unitario || 0).toFixed(2);
                            
                            updateProductTotal(valorUnitarioInput);
                        };

                        suggestionsContainer.appendChild(suggestionItem);
                    });
                } else {
                    const noResult = document.createElement('div');
                    noResult.classList.add('suggestion-item');
                    noResult.textContent = '📦 Nenhum produto encontrado';
                    noResult.style.fontStyle = 'italic';
                    noResult.style.color = 'var(--medium-gray)';
                    suggestionsContainer.appendChild(noResult);
                }

                suggestionsContainer.style.display = 'block';
            })
            .catch(error => {
                console.error('Erro ao buscar os produtos:', error);
                suggestionsContainer.innerHTML = '<div class="suggestion-item" style="color: var(--danger-color);">❌ Erro ao buscar produtos</div>';
                suggestionsContainer.style.display = 'block';
            });
    } else {
        suggestionsContainer.innerHTML = '';
        suggestionsContainer.style.display = 'none';
    }
}

// Fecha as sugestões ao clicar fora
document.addEventListener('click', (event) => {
    const suggestionsContainers = document.querySelectorAll('.suggestions-container');
    suggestionsContainers.forEach(container => {
        if (!event.target.closest('.suggestions-container') && !event.target.matches('input[name="produto_descricao[]"]')) {
            container.style.display = 'none';
        }
    });
});

// Fecha modal ao clicar fora dele
window.onclick = function(event) {
    var editModal = document.getElementById('editModal');
    var deleteModal = document.getElementById('deleteModal');
    
    if (event.target == editModal) {
        closeModal();
    }
    if (event.target == deleteModal) {
        closeDeleteModal();
    }
}

// Tecla ESC para fechar modais
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeModal();
        closeDeleteModal();
    }
});

// Prevenção de perda de dados
window.addEventListener('beforeunload', function(event) {
    var isEditing = document.getElementById('saveBtn').style.display !== 'none';
    if (isEditing) {
        event.preventDefault();
        event.returnValue = '';
        return 'Você tem alterações não salvas. Tem certeza que deseja sair?';
    }
});
</script>

</body>
</html>