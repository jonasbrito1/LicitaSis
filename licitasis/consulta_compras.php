<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";
$compras = [];
$searchTerm = "";

// Conexão com o banco de dados
require_once('db.php');

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    
    // Consulta ao banco de dados para pesquisar compras por número de NF, fornecedor ou produto
    try {
        $sql = "SELECT * FROM compras WHERE numero_nf LIKE :searchTerm OR fornecedor LIKE :searchTerm OR produto LIKE :searchTerm";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        
        $compras = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    // Consulta para mostrar todas as compras
    try {
        $sql = "SELECT * FROM compras ORDER BY data DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $compras = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar todas as compras: " . $e->getMessage();
    }
}

// Função para buscar os detalhes da compra e seus produtos
if (isset($_GET['get_compra_id'])) {
    $compra_id = $_GET['get_compra_id'];
    try {
        // Busca os dados da compra
        $sql = "SELECT * FROM compras WHERE id = :compra_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':compra_id', $compra_id, PDO::PARAM_INT);
        $stmt->execute();
        $compra = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verifica se a tabela produto_compra existe
        $tabela_existe = false;
        try {
            $pdo->query("SELECT 1 FROM produto_compra LIMIT 1");
            $tabela_existe = true;
        } catch (Exception $e) {
            $tabela_existe = false;
        }
        
        // Busca os produtos relacionados à compra se a tabela existir
        $produtos = [];
        if ($tabela_existe) {
            $sql_produtos = "SELECT pc.*, p.nome as produto_nome 
                            FROM produto_compra pc 
                            LEFT JOIN produtos p ON pc.produto_id = p.id 
                            WHERE pc.compra_id = :compra_id";
            $stmt_produtos = $pdo->prepare($sql_produtos);
            $stmt_produtos->bindValue(':compra_id', $compra_id, PDO::PARAM_INT);
            $stmt_produtos->execute();
            $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Retorna os dados da compra e seus produtos como JSON
        echo json_encode(['compra' => $compra, 'produtos' => $produtos]);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => "Erro ao buscar detalhes da compra: " . $e->getMessage()]);
        exit();
    }
}

// Função para excluir a compra
if (isset($_GET['delete_compra_id'])) {
    $compra_id = $_GET['delete_compra_id'];
    try {
        $pdo->beginTransaction();
        
        // Verifica se a tabela produto_compra existe
        $tabela_existe = false;
        try {
            $pdo->query("SELECT 1 FROM produto_compra LIMIT 1");
            $tabela_existe = true;
        } catch (Exception $e) {
            $tabela_existe = false;
        }
        
        // Exclui os produtos relacionados à compra se a tabela existir
        if ($tabela_existe) {
            $sql_delete_produtos = "DELETE FROM produto_compra WHERE compra_id = :compra_id";
            $stmt_delete_produtos = $pdo->prepare($sql_delete_produtos);
            $stmt_delete_produtos->bindValue(':compra_id', $compra_id, PDO::PARAM_INT);
            $stmt_delete_produtos->execute();
        }
        
        // Exclui a compra
        $sql_delete_compra = "DELETE FROM compras WHERE id = :compra_id";
        $stmt_delete_compra = $pdo->prepare($sql_delete_compra);
        $stmt_delete_compra->bindValue(':compra_id', $compra_id, PDO::PARAM_INT);
        $stmt_delete_compra->execute();
        
        $pdo->commit();
        $success = "Compra excluída com sucesso!";
        header("Location: consulta_compras.php?success=" . urlencode($success));
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Erro ao excluir a compra: " . $e->getMessage();
    }
}

// Função para atualizar a compra
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_compra'])) {
    try {
        $pdo->beginTransaction();
        
        $id = $_POST['id'];
        $fornecedor = $_POST['fornecedor'];
        $numero_nf = $_POST['numero_nf'];
        $valor_total = $_POST['valor_total'];
        $frete = $_POST['frete'];
        $link_pagamento = $_POST['link_pagamento'];
        $numero_empenho = $_POST['numero_empenho'];
        $observacao = $_POST['observacao'];
        $data = $_POST['data'];

        // Verificar quais colunas existem na tabela compras
        $stmt = $pdo->prepare("DESCRIBE compras");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Construir a query dinamicamente com base nas colunas existentes
        $sql = "UPDATE compras SET 
                fornecedor = :fornecedor, 
                numero_nf = :numero_nf, 
                valor_total = :valor_total, 
                frete = :frete, 
                link_pagamento = :link_pagamento, 
                numero_empenho = :numero_empenho, 
                observacao = :observacao, 
                data = :data";
        
        // Adicionar colunas opcionais se existirem na tabela
        if (in_array('produto', $columns)) {
            $sql .= ", produto = :produto";
        }
        if (in_array('quantidade', $columns)) {
            $sql .= ", quantidade = :quantidade";
        }
        if (in_array('valor_unitario', $columns)) {
            $sql .= ", valor_unitario = :valor_unitario";
        }
        
        $sql .= " WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':fornecedor', $fornecedor);
        $stmt->bindParam(':numero_nf', $numero_nf);
        $stmt->bindParam(':valor_total', $valor_total);
        $stmt->bindParam(':frete', $frete);
        $stmt->bindParam(':link_pagamento', $link_pagamento);
        $stmt->bindParam(':numero_empenho', $numero_empenho);
        $stmt->bindParam(':observacao', $observacao);
        $stmt->bindParam(':data', $data);
        $stmt->bindParam(':id', $id);
        
        // Bind dos parâmetros opcionais se existirem na tabela
        if (in_array('produto', $columns)) {
            $produto = isset($_POST['produto']) ? $_POST['produto'] : '';
            $stmt->bindParam(':produto', $produto);
        }
        if (in_array('quantidade', $columns)) {
            $quantidade = isset($_POST['quantidade']) ? $_POST['quantidade'] : 0;
            $stmt->bindParam(':quantidade', $quantidade);
        }
        if (in_array('valor_unitario', $columns)) {
            $valor_unitario = isset($_POST['valor_unitario']) ? $_POST['valor_unitario'] : 0;
            $stmt->bindParam(':valor_unitario', $valor_unitario);
        }
        
        $stmt->execute();
        
        // Verifica se a tabela produto_compra existe
        $tabela_existe = false;
        try {
            $pdo->query("SELECT 1 FROM produto_compra LIMIT 1");
            $tabela_existe = true;
        } catch (Exception $e) {
            // Se a tabela não existir, vamos criá-la
            $sql_create_table = "CREATE TABLE IF NOT EXISTS produto_compra (
                id INT AUTO_INCREMENT PRIMARY KEY,
                compra_id INT NOT NULL,
                produto_id INT NOT NULL,
                quantidade DECIMAL(10,2) NOT NULL,
                valor_unitario DECIMAL(10,2) NOT NULL,
                valor_total DECIMAL(10,2) NOT NULL,
                FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
                FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
            )";
            $pdo->exec($sql_create_table);
            $tabela_existe = true;
        }
        
        if ($tabela_existe) {
            // Remove os produtos existentes
            $sql_delete = "DELETE FROM produto_compra WHERE compra_id = :compra_id";
            $stmt_delete = $pdo->prepare($sql_delete);
            $stmt_delete->bindParam(':compra_id', $id);
            $stmt_delete->execute();
            
            // Insere os produtos atualizados
            if (isset($_POST['produto_id']) && is_array($_POST['produto_id'])) {
                $produto_ids = $_POST['produto_id'];
                $quantidades = $_POST['produto_quantidade'];
                $valores_unitarios = $_POST['produto_valor_unitario'];
                $valores_totais = $_POST['produto_valor_total'];
                
                $sql_insert = "INSERT INTO produto_compra (compra_id, produto_id, quantidade, valor_unitario, valor_total) 
                              VALUES (:compra_id, :produto_id, :quantidade, :valor_unitario, :valor_total)";
                $stmt_insert = $pdo->prepare($sql_insert);
                
                foreach ($produto_ids as $index => $produto_id) {
                    if (empty($produto_id)) continue;
                    
                    $quantidade = $quantidades[$index];
                    $valor_unitario = $valores_unitarios[$index];
                    $valor_total = $valores_totais[$index];
                    
                    $stmt_insert->bindParam(':compra_id', $id);
                    $stmt_insert->bindParam(':produto_id', $produto_id);
                    $stmt_insert->bindParam(':quantidade', $quantidade);
                    $stmt_insert->bindParam(':valor_unitario', $valor_unitario);
                    $stmt_insert->bindParam(':valor_total', $valor_total);
                    $stmt_insert->execute();
                }
            }
        }
        
        $pdo->commit();
        $success = "Compra atualizada com sucesso!";
        header("Location: consulta_compras.php?success=" . urlencode($success));
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Erro ao atualizar a compra: " . $e->getMessage();
    }
}

// Função para calcular o total geral das compras
try {
    $sqlTotal = "SELECT SUM(valor_total) AS total_geral FROM compras";
    $stmtTotal = $pdo->prepare($sqlTotal);
    $stmtTotal->execute();
    $totalGeral = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total_geral'];
} catch (PDOException $e) {
    $error = "Erro ao calcular o total de compras: " . $e->getMessage();
}

// Buscar todos os produtos cadastrados para o modal de edição
$produtos_cadastrados = [];
try {
    $sql = "SELECT id, nome, preco_unitario FROM produtos ORDER BY nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $produtos_cadastrados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Erro ao buscar produtos: " . $e->getMessage();
}

// Buscar todos os fornecedores para o modal de edição
$fornecedores = [];
try {
    $stmt = $pdo->query("SELECT id, nome FROM fornecedores ORDER BY nome ASC");
    $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Erro ao buscar fornecedores: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Compras - LicitaSis</title>
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

        /* Comprovante de pagamento */
        .comprovante-container {
            margin-top: 1rem;
        }

        .comprovante-link {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: var(--light-gray);
            border-radius: var(--radius);
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        .comprovante-link:hover {
            background: var(--primary-light);
            color: white;
        }

        .comprovante-link i {
            margin-right: 0.5rem;
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
    <h2>Consulta de Compras</h2>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="success"><?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>

    <?php if (isset($totalGeral)): ?>
        <div class="total-geral">
            <span>Valor Total Geral de Compras: R$ <?php echo number_format($totalGeral, 2, ',', '.'); ?></span>
        </div>
    <?php endif; ?>

    <form action="consulta_compras.php" method="GET">
        <div class="search-bar">
            <input type="text" name="search" id="search" placeholder="Pesquisar por Número da NF, Fornecedor ou Produto" value="<?php echo htmlspecialchars($searchTerm); ?>">
            <button type="submit"><i class="fas fa-search"></i> Pesquisar</button>
        </div>
    </form>

    <?php if (count($compras) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Número da NF</th>
                        <th>Fornecedor</th>
                        <th>Valor Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($compras as $compra): ?>
                        <tr>
                            <td>
                                <a href="javascript:void(0);" onclick="openModal(<?php echo $compra['id']; ?>)">
                                    <?php echo htmlspecialchars($compra['numero_nf']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($compra['fornecedor']); ?></td>
                            <td>R$ <?php echo number_format($compra['valor_total'], 2, ',', '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="text-align: center; margin-top: 2rem; color: var(--medium-gray);">Nenhuma compra encontrada.</p>
    <?php endif; ?>
</div>

<!-- Modal de Detalhes da Compra -->
<div id="compraModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Detalhes da Compra</h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="compraForm" method="POST" action="consulta_compras.php">
                <input type="hidden" name="id" id="compra_id">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="fornecedor">Fornecedor:</label>
                        <input type="text" name="fornecedor" id="fornecedor" readonly>
                    </div>
                    <div class="form-group">
                        <label for="numero_nf">Número da NF:</label>
                        <input type="text" name="numero_nf" id="numero_nf" readonly>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="data">Data:</label>
                        <input type="date" name="data" id="data" readonly>
                    </div>
                    <div class="form-group">
                        <label for="frete">Frete (R$):</label>
                        <input type="number" name="frete" id="frete" step="0.01" readonly>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="numero_empenho">Número de Empenho:</label>
                        <input type="text" name="numero_empenho" id="numero_empenho" readonly>
                    </div>
                    <div class="form-group">
                        <label for="link_pagamento">Link para Pagamento:</label>
                        <input type="url" name="link_pagamento" id="link_pagamento" readonly>
                    </div>
                </div>
                
                <!-- Produtos da compra -->
                <h3>Produtos</h3>
                <div id="produtos-container" class="produtos-section">
                    <!-- Os produtos serão adicionados aqui dinamicamente -->
                </div>
                
                <!-- Valor Total da Compra -->
                <div class="form-group">
                    <label for="valor_total">Valor Total da Compra:</label>
                    <input type="text" name="valor_total" id="valor_total" readonly>
                </div>
                
                <div class="form-group">
                    <label for="observacao">Observação:</label>
                    <textarea name="observacao" id="observacao" readonly></textarea>
                </div>
                
                <!-- Comprovante de Pagamento -->
                <div class="form-group">
                    <label>Comprovante de Pagamento:</label>
                    <div id="comprovante-container" class="comprovante-container">
                        <!-- Link para o comprovante será adicionado aqui -->
                    </div>
                </div>
                
                <div class="btn-container">
                    <button type="button" id="editBtn" class="btn-warning" onclick="enableEditing()">
                        <i class="fas fa-edit"></i> Editar
                    </button>
                    <button type="submit" name="update_compra" id="saveBtn" style="display: none;">
                        <i class="fas fa-save"></i> Salvar
                    </button>
                    <button type="button" class="btn-danger" onclick="confirmDelete()">
                        <i class="fas fa-trash-alt"></i> Excluir
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
            <h3>Confirmar Exclusão</h3>
            <span class="close" onclick="closeDeleteModal()">&times;</span>
        </div>
        <div class="modal-body">
            <p style="text-align: center; margin-bottom: 2rem;">
                Tem certeza que deseja excluir esta compra? Esta ação não pode ser desfeita.
            </p>
            <div class="btn-container">
                <button type="button" class="btn-danger" onclick="deleteCompra()">
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
    // Produtos disponíveis para edição
    const produtosCadastrados = <?php echo json_encode($produtos_cadastrados); ?>;
    
    // Contador para identificar produtos
    let produtoCounter = 0;
    
    // Função para abrir o modal e carregar os dados da compra
    function openModal(id) {
        // Limpa o container de produtos
        document.getElementById('produtos-container').innerHTML = '';
        produtoCounter = 0;
        
        // Exibe o modal
        document.getElementById('compraModal').style.display = 'block';
        
        // Busca os dados da compra
        fetch('consulta_compras.php?get_compra_id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert(data.error);
                    return;
                }
                
                const compra = data.compra;
                const produtos = data.produtos || [];
                
                // Preenche os campos do formulário
                document.getElementById('compra_id').value = compra.id;
                document.getElementById('fornecedor').value = compra.fornecedor;
                document.getElementById('numero_nf').value = compra.numero_nf;
                document.getElementById('data').value = compra.data;
                document.getElementById('frete').value = compra.frete;
                document.getElementById('numero_empenho').value = compra.numero_empenho || '';
                document.getElementById('link_pagamento').value = compra.link_pagamento || '';
                document.getElementById('valor_total').value = compra.valor_total;
                document.getElementById('observacao').value = compra.observacao || '';
                
                // Verifica se há comprovante de pagamento
                const comprovanteContainer = document.getElementById('comprovante-container');
                if (compra.comprovante_pagamento) {
                    comprovanteContainer.innerHTML = `
                        <a href="${compra.comprovante_pagamento}" class="comprovante-link" target="_blank">
                            <i class="fas fa-file-alt"></i> Ver Comprovante
                        </a>
                    `;
                } else {
                    comprovanteContainer.innerHTML = '<span>Nenhum comprovante anexado</span>';
                }
                
                // Se houver produtos na tabela produto_compra, exibe-os
                if (produtos.length > 0) {
                    produtos.forEach(produto => {
                        adicionarProdutoExistente(produto);
                    });
                } 
                // Se não houver produtos na tabela produto_compra, exibe o produto da tabela compras
                else if (compra.produto) {
                    const produtoItem = {
                        produto_nome: compra.produto,
                        quantidade: compra.quantidade || 0,
                        valor_unitario: compra.valor_unitario || 0,
                        valor_total: (compra.quantidade || 0) * (compra.valor_unitario || 0)
                    };
                    adicionarProdutoExistente(produtoItem);
                }
                // Se não houver produtos em nenhum lugar, adicione um produto vazio
                else if (produtos.length === 0 && !compra.produto) {
                    adicionarProduto();
                    // Oculta o botão de remover para o primeiro produto
                    const removeBtn = document.querySelector('.remove-produto');
                    if (removeBtn) {
                        removeBtn.style.display = 'none';
                    }
                }
                
                // Reseta o formulário para modo de visualização
                disableEditing();
            })
            .catch(error => {
                console.error('Erro ao buscar dados da compra:', error);
                alert('Erro ao buscar dados da compra. Por favor, tente novamente.');
            });
    }
    
    // Função para adicionar um produto existente ao modal
    function adicionarProdutoExistente(produto) {
        produtoCounter++;
        const produtosContainer = document.getElementById('produtos-container');
        
        const produtoDiv = document.createElement('div');
        produtoDiv.className = 'produto-item';
        produtoDiv.id = `produto-${produtoCounter}`;
        
        // Prepara as opções de produtos para o select
        let produtosOptions = '<option value="">Selecione o Produto</option>';
        produtosCadastrados.forEach(p => {
            const selected = produto.produto_id && p.id == produto.produto_id ? 'selected' : '';
            produtosOptions += `<option value="${p.id}" data-preco="${p.preco_unitario}" ${selected}>${p.nome}</option>`;
        });
        
        // Se o produto não tem ID (veio da tabela compras), adiciona uma opção com o nome
        if (!produto.produto_id && produto.produto_nome) {
            // Tenta encontrar o produto pelo nome
            const produtoEncontrado = produtosCadastrados.find(p => 
                p.nome && produto.produto_nome && 
                p.nome.toLowerCase() === produto.produto_nome.toLowerCase()
            );
            
            if (produtoEncontrado) {
                // Se encontrou o produto pelo nome, seleciona-o
                produtosOptions = produtosOptions.replace(
                    `value="${produtoEncontrado.id}"`, 
                    `value="${produtoEncontrado.id}" selected`
                );
            } else {
                // Se não encontrou, adiciona uma opção com o nome
                produtosOptions += `<option value="" selected>${produto.produto_nome || 'Produto sem nome'}</option>`;
            }
        }
        
        // Garantir que os valores existam ou usar valores padrão
        const quantidade = produto.quantidade || 0;
        const valorUnitario = produto.valor_unitario || 0;
        const valorTotal = produto.valor_total || (quantidade * valorUnitario);
        
        produtoDiv.innerHTML = `
            <div class="produto-header">
                <div class="produto-title">Produto ${produtoCounter}</div>
                <button type="button" class="remove-produto" onclick="removerProduto(${produtoCounter})" style="display: none;">Remover</button>
            </div>
            
            <div class="form-group">
                <label for="produto_id_${produtoCounter}">Produto:</label>
                <select id="produto_id_${produtoCounter}" name="produto_id[]" disabled onchange="atualizarProdutoInfo(${produtoCounter})">
                    ${produtosOptions}
                </select>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="produto_quantidade_${produtoCounter}">Quantidade:</label>
                    <input type="number" id="produto_quantidade_${produtoCounter}" name="produto_quantidade[]" min="1" value="${quantidade}" readonly oninput="calcularValorTotalProduto(${produtoCounter})">
                </div>
                <div class="form-group">
                    <label for="produto_valor_unitario_${produtoCounter}">Valor Unitário:</label>
                    <input type="number" id="produto_valor_unitario_${produtoCounter}" name="produto_valor_unitario[]" step="0.01" value="${valorUnitario}" readonly oninput="calcularValorTotalProduto(${produtoCounter})">
                </div>
            </div>
            
            <div class="form-group">
                <label for="produto_valor_total_${produtoCounter}">Valor Total:</label>
                <input type="text" id="produto_valor_total_${produtoCounter}" name="produto_valor_total[]" value="${valorTotal}" readonly>
            </div>
        `;
        
        produtosContainer.appendChild(produtoDiv);
    }
    
    // Função para adicionar um novo produto ao formulário (modo de edição)
    function adicionarProduto() {
        produtoCounter++;
        const produtosContainer = document.getElementById('produtos-container');
        
        const produtoDiv = document.createElement('div');
        produtoDiv.className = 'produto-item';
        produtoDiv.id = `produto-${produtoCounter}`;
        
        let produtosOptions = '<option value="">Selecione o Produto</option>';
        produtosCadastrados.forEach(produto => {
            produtosOptions += `<option value="${produto.id}" data-preco="${produto.preco_unitario}">${produto.nome}</option>`;
        });
        
        produtoDiv.innerHTML = `
            <div class="produto-header">
                <div class="produto-title">Produto ${produtoCounter}</div>
                <button type="button" class="remove-produto" onclick="removerProduto(${produtoCounter})">Remover</button>
            </div>
            
            <div class="form-group">
                <label for="produto_id_${produtoCounter}">Produto:</label>
                <select id="produto_id_${produtoCounter}" name="produto_id[]" required onchange="atualizarProdutoInfo(${produtoCounter})">
                    ${produtosOptions}
                </select>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="produto_quantidade_${produtoCounter}">Quantidade:</label>
                    <input type="number" id="produto_quantidade_${produtoCounter}" name="produto_quantidade[]" min="1" value="1" oninput="calcularValorTotalProduto(${produtoCounter})">
                </div>
                <div class="form-group">
                    <label for="produto_valor_unitario_${produtoCounter}">Valor Unitário:</label>
                    <input type="number" id="produto_valor_unitario_${produtoCounter}" name="produto_valor_unitario[]" step="0.01" value="0.00" oninput="calcularValorTotalProduto(${produtoCounter})">
                </div>
            </div>
            
            <div class="form-group">
                <label for="produto_valor_total_${produtoCounter}">Valor Total:</label>
                <input type="text" id="produto_valor_total_${produtoCounter}" name="produto_valor_total[]" value="0.00" readonly>
            </div>
        `;
        
        produtosContainer.appendChild(produtoDiv);
    }
    
    // Função para remover um produto do formulário
    function removerProduto(id) {
        const produtoDiv = document.getElementById(`produto-${id}`);
        if (produtoDiv) {
            produtoDiv.remove();
            calcularValorTotalCompra();
        }
    }
    
    // Função para atualizar as informações do produto quando selecionado
    function atualizarProdutoInfo(id) {
        const produtoSelect = document.getElementById(`produto_id_${id}`);
        const selectedOption = produtoSelect.options[produtoSelect.selectedIndex];
        const precoUnitario = parseFloat(selectedOption.getAttribute('data-preco')) || 0;
        
        // Preenche o valor unitário com o preço do produto selecionado
        document.getElementById(`produto_valor_unitario_${id}`).value = precoUnitario.toFixed(2);
        
        // Atualiza o valor total do produto
        calcularValorTotalProduto(id);
    }
    
    // Função para calcular o valor total de um produto
    function calcularValorTotalProduto(id) {
        const quantidade = parseFloat(document.getElementById(`produto_quantidade_${id}`).value) || 0;
        const valorUnitario = parseFloat(document.getElementById(`produto_valor_unitario_${id}`).value) || 0;
        const valorTotal = quantidade * valorUnitario;
        
        // Preenche o campo de valor total do produto
        document.getElementById(`produto_valor_total_${id}`).value = valorTotal.toFixed(2);
        
        // Recalcula o valor total da compra
        calcularValorTotalCompra();
    }
    
    // Função para calcular o valor total da compra
    function calcularValorTotalCompra() {
        let valorTotal = 0;
        
        // Soma os valores totais de todos os produtos
        const valoresProdutos = document.querySelectorAll('input[name="produto_valor_total[]"]');
        valoresProdutos.forEach(input => {
            valorTotal += parseFloat(input.value) || 0;
        });
        
        // Adiciona o valor do frete
        const frete = parseFloat(document.getElementById('frete').value) || 0;
        valorTotal += frete;
        
        // Atualiza o campo de valor total da compra
        document.getElementById('valor_total').value = valorTotal.toFixed(2);
    }
    
    // Função para habilitar a edição dos campos
    function enableEditing() {
        // Habilita os campos do formulário
        const inputs = document.querySelectorAll('#compraForm input:not([type="hidden"]), #compraForm select, #compraForm textarea');
        inputs.forEach(input => {
            input.removeAttribute('readonly');
            input.removeAttribute('disabled');
        });
        
        // Exibe o botão de salvar e oculta o botão de editar
        document.getElementById('saveBtn').style.display = 'inline-block';
        document.getElementById('editBtn').style.display = 'none';
        
        // Exibe os botões de remover produto
        const removeBtns = document.querySelectorAll('.remove-produto');
        removeBtns.forEach(btn => {
            btn.style.display = 'inline-block';
        });
        
        // Adiciona o botão para adicionar novos produtos
        const produtosContainer = document.getElementById('produtos-container');
        
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
        addBtn.onclick = adicionarProduto;
        produtosContainer.appendChild(addBtn);
    }
    
    // Função para desabilitar a edição dos campos
    function disableEditing() {
        // Desabilita os campos do formulário
        const inputs = document.querySelectorAll('#compraForm input:not([type="hidden"]), #compraForm select, #compraForm textarea');
        inputs.forEach(input => {
            input.setAttribute('readonly', true);
            if (input.tagName === 'SELECT') {
                input.setAttribute('disabled', true);
            }
        });
        
        // Oculta o botão de salvar e exibe o botão de editar
        document.getElementById('saveBtn').style.display = 'none';
        document.getElementById('editBtn').style.display = 'inline-block';
        
        // Oculta os botões de remover produto
        const removeBtns = document.querySelectorAll('.remove-produto');
        removeBtns.forEach(btn => {
            btn.style.display = 'none';
        });
        
        // Remove o botão para adicionar novos produtos
        const addBtn = document.getElementById('addProdutoBtn');
        if (addBtn) {
            addBtn.remove();
        }
    }
    
    // Função para fechar o modal
    function closeModal() {
        document.getElementById('compraModal').style.display = 'none';
    }
    
    // Função para abrir o modal de confirmação de exclusão
    function confirmDelete() {
        document.getElementById('deleteModal').style.display = 'block';
    }
    
    // Função para fechar o modal de confirmação de exclusão
    function closeDeleteModal() {
        document.getElementById('deleteModal').style.display = 'none';
    }
    
    // Função para excluir a compra
    function deleteCompra() {
        const compraId = document.getElementById('compra_id').value;
        window.location.href = 'consulta_compras.php?delete_compra_id=' + compraId;
    }
    
    // Fecha os modais ao clicar fora deles
    window.onclick = function(event) {
        const compraModal = document.getElementById('compraModal');
        const deleteModal = document.getElementById('deleteModal');
        
        if (event.target === compraModal) {
            closeModal();
        }
        
        if (event.target === deleteModal) {
            closeDeleteModal();
        }
    }
    
    // Fecha os modais ao pressionar a tecla ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
            closeDeleteModal();
        }
    });
</script>

</body>
</html>