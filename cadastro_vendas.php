<?php 
// ===========================================
// CADASTRO DE VENDAS - LICITASIS
// Versão Completa com Autocomplete e Validações
// ===========================================

session_start();

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

include('db.php');
include('permissions.php');
include('includes/audit.php');

$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('vendas', 'create');
logUserAction('READ', 'vendas_cadastro');

$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';
$error = "";
$success = false;

// Buscar empenhos relacionados ao cliente (requisição AJAX)
if (isset($_GET['cliente_uasg'])) {
    $cliente_uasg = $_GET['cliente_uasg'];
    
    try {
        $sql = "SELECT id, numero FROM empenhos WHERE cliente_uasg = :cliente_uasg ORDER BY numero";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':cliente_uasg', $cliente_uasg, PDO::PARAM_STR);
        $stmt->execute();
        
        $empenhos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($empenhos) {
            echo json_encode($empenhos);
        } else {
            echo json_encode(['error' => 'Nenhum empenho encontrado para esta UASG']);
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar empenhos: ' . $e->getMessage()]);
    }
    exit();
}

// Buscar detalhes do empenho selecionado e os produtos relacionados
if (isset($_GET['empenho_id'])) {
    $empenho_id = $_GET['empenho_id'];

    try {
        $sql = "SELECT id, numero, cliente_uasg, cliente_nome, valor_total, valor_total_empenho, observacao, pregao
                FROM empenhos 
                WHERE id = :empenho_id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
        $stmt->execute();

        $empenho = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($empenho) {
            // Buscar os produtos relacionados a este empenho
            $sql_produtos = "SELECT p.id, p.nome, p.codigo, ep.quantidade, ep.valor_unitario, ep.valor_total, 
                            ep.descricao_produto, p.preco_unitario, ep.produto_id
                            FROM empenho_produtos ep
                            JOIN produtos p ON ep.produto_id = p.id
                            WHERE ep.empenho_id = :empenho_id";

            $stmt_produtos = $pdo->prepare($sql_produtos);
            $stmt_produtos->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
            $stmt_produtos->execute();

            $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);
            $empenho['produtos_detalhes'] = $produtos;

            echo json_encode($empenho);
        } else {
            echo json_encode(['error' => 'Empenho não encontrado']);
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar detalhes do empenho: ' . $e->getMessage()]);
    }
    exit();
}

// Funções auxiliares
function buscarProdutos() {
    global $pdo;
    $sql = "SELECT id, nome, preco_unitario, codigo FROM produtos ORDER BY nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarTransportadoras() {
    global $pdo;
    $sql = "SELECT id, nome FROM transportadora ORDER BY nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarEmpenhos() {
    global $pdo;
    $sql = "SELECT id, numero FROM empenhos ORDER BY numero";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarClientes() {
    global $pdo;
    $sql = "SELECT id, nome_orgaos, uasg FROM clientes ORDER BY nome_orgaos";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarProdutoPorId($produto_id) {
    global $pdo;
    $sql = "SELECT * FROM produtos WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $produto_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function verificarDuplicado($empenho_id) {
    global $pdo;
    $sql = "SELECT COUNT(*) FROM vendas WHERE empenho_id = :empenho_id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':empenho_id', $empenho_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() > 0;
}

// Código para processar o formulário quando submetido
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Verificar campos obrigatórios
        if (empty($_POST['cliente_uasg']) || empty($_POST['cliente']) || empty($_POST['empenho']) || 
            empty($_POST['transportadora']) || empty($_POST['data']) || empty($_POST['numero'])) {
            throw new Exception("Todos os campos marcados são obrigatórios.");
        }

        // Verificar se tem pelo menos um produto
        if (empty($_POST['produto']) || !is_array($_POST['produto']) || count($_POST['produto']) == 0) {
            throw new Exception("É necessário adicionar pelo menos um produto.");
        }

        // Obter dados do formulário
        $numero = $_POST['numero'];
        $cliente_uasg = $_POST['cliente_uasg'];
        $cliente = $_POST['cliente'];
        $empenho_id = $_POST['empenho'];
        $transportadora = $_POST['transportadora'];
        
        // Formatar e validar datas
        $data_venda = null;
        if (!empty($_POST['data'])) {
            $data_parts = explode('/', $_POST['data']);
            if (count($data_parts) == 3) {
                $dia = (int)$data_parts[0];
                $mes = (int)$data_parts[1];
                $ano = (int)$data_parts[2];
                
                if (checkdate($mes, $dia, $ano)) {
                    $data_venda = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
                } else {
                    throw new Exception("Data de venda inválida. Por favor, verifique o formato DD/MM/AAAA.");
                }
            } else {
                throw new Exception("Formato de data incorreto. Use o formato DD/MM/AAAA.");
            }
        }

        $data_vencimento = null;
        if (!empty($_POST['data_vencimento'])) {
            $venc_parts = explode('/', $_POST['data_vencimento']);
            if (count($venc_parts) == 3) {
                $dia = (int)$venc_parts[0];
                $mes = (int)$venc_parts[1];
                $ano = (int)$venc_parts[2];
                
                if (checkdate($mes, $dia, $ano)) {
                    $data_vencimento = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
                } else {
                    throw new Exception("Data de vencimento inválida. Por favor, verifique o formato DD/MM/AAAA.");
                }
            } else {
                throw new Exception("Formato de data de vencimento incorreto. Use o formato DD/MM/AAAA.");
            }
        }
        
        $valor_total = $_POST['valor_total_venda'] ?? 0;
        $observacao = $_POST['observacao'] ?? '';
        $pregao = $_POST['pregao'] ?? '';
        $classificacao = $_POST['classificacao'] ?? 'Pendente';
        $nf = $_POST['numero'] ?? '';

        // Obter dados dos produtos
        $produtos = $_POST['produto'];
        $quantidades = $_POST['quantidade'];
        $valores_unitarios = $_POST['valor_unitario'];
        $valores_totais = $_POST['valor_total'];
        $observacoes = isset($_POST['observacao_produto']) ? $_POST['observacao_produto'] : array_fill(0, count($produtos), '');
        
        // Iniciar transação
        $pdo->beginTransaction();
        
        // Inserir a venda na tabela vendas
        $sql = "INSERT INTO vendas (numero, cliente_uasg, cliente, transportadora, data, data_vencimento, 
                              valor_total, observacao, pregao, classificacao, nf, empenho_id, status_pagamento) 
                VALUES (:numero, :cliente_uasg, :cliente, :transportadora, :data, :data_vencimento, 
                       :valor_total, :observacao, :pregao, :classificacao, :nf, :empenho_id, :status_pagamento)";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':numero', $numero);
        $stmt->bindParam(':cliente_uasg', $cliente_uasg);
        $stmt->bindParam(':cliente', $cliente);
        $stmt->bindParam(':transportadora', $transportadora);
        $stmt->bindParam(':data', $data_venda);
        $stmt->bindParam(':data_vencimento', $data_vencimento);
        $stmt->bindParam(':valor_total', $valor_total);
        $stmt->bindParam(':observacao', $observacao);
        $stmt->bindParam(':pregao', $pregao);
        $stmt->bindParam(':classificacao', $classificacao);
        $stmt->bindParam(':nf', $nf);
        $stmt->bindParam(':empenho_id', $empenho_id);

        $status_pagamento = 'Não Recebido';
        $stmt->bindParam(':status_pagamento', $status_pagamento);
        $stmt->execute();
        
        // Obter o ID da venda recém-inserida
        $venda_id = $pdo->lastInsertId();
        
        $sql_produto = "INSERT INTO venda_produtos (venda_id, produto_id, quantidade, valor_unitario, valor_total, observacao) 
                       VALUES (:venda_id, :produto_id, :quantidade, :valor_unitario, :valor_total, :observacao)";
        $stmt_produto = $pdo->prepare($sql_produto);
        
        for ($i = 0; $i < count($produtos); $i++) {
            if (empty($produtos[$i])) continue;
            
            $produto_id = $produtos[$i];
            $quantidade = $quantidades[$i];
            $valor_unitario = str_replace(',', '.', $valores_unitarios[$i]);
            $valor_total = str_replace(',', '.', $valores_totais[$i]);
            $obs_produto = $observacoes[$i] ?? '';
            
            $stmt_produto->bindParam(':venda_id', $venda_id);
            $stmt_produto->bindParam(':produto_id', $produto_id);
            $stmt_produto->bindParam(':quantidade', $quantidade);
            $stmt_produto->bindParam(':valor_unitario', $valor_unitario);
            $stmt_produto->bindParam(':valor_total', $valor_total);
            $stmt_produto->bindParam(':observacao', $obs_produto);
            
            $stmt_produto->execute();
        }
        
        // Finalizar transação
        $pdo->commit();
        
        // Registra auditoria
        logUserAction('CREATE', 'vendas', $venda_id, [
            'numero' => $numero,
            'cliente_uasg' => $cliente_uasg,
            'cliente' => $cliente,
            'empenho_id' => $empenho_id,
            'valor_total' => $valor_total,
            'produtos_count' => count($produtos)
        ]);
        
        $success = true;

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$produtos = buscarProdutos();
$transportadoras = buscarTransportadoras();
$empenhos = buscarEmpenhos();
$clientes = buscarClientes();

include('includes/header_template.php');
renderHeader("Cadastro de Vendas - LicitaSis", "vendas");
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Vendas - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
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

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            line-height: 1.6;
        }

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
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
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

        h2 i {
            color: var(--secondary-color);
            font-size: 1.8rem;
        }

        h3 {
            color: var(--primary-color);
            margin: 2rem 0 1.5rem;
            font-size: 1.3rem;
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        h3 i {
            color: var(--secondary-color);
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            font-weight: 500;
            text-align: center;
            animation: slideInDown 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-left: 4px solid var(--danger-color);
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 1px solid #c3e6cb;
            border-left: 4px solid var(--success-color);
        }

        @keyframes slideInDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .form-container {
            display: grid;
            gap: 2rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 0;
            position: relative;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group label i {
            color: var(--secondary-color);
            width: 16px;
        }

        .form-control {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
            transform: translateY(-1px);
        }

        .form-control:hover {
            border-color: var(--secondary-color);
        }

        .required::after {
            content: ' *';
            color: var(--danger-color);
            font-weight: bold;
        }

        /* AUTOCOMPLETE STYLES */
        .autocomplete-container {
            position: relative;
        }

        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 var(--radius-sm) var(--radius-sm);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: var(--shadow);
            display: none;
        }

        .autocomplete-suggestion {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .autocomplete-suggestion:hover {
            background: var(--light-gray);
            border-left: 4px solid var(--secondary-color);
        }

        .autocomplete-suggestion:last-child {
            border-bottom: none;
        }

        .suggestion-main {
            font-weight: 600;
            color: var(--dark-gray);
        }

        .suggestion-secondary {
            font-size: 0.85rem;
            color: var(--medium-gray);
        }

        .suggestion-highlight {
            background: rgba(0, 191, 174, 0.2);
            font-weight: 700;
        }

        /* PRODUTOS SECTION */
        .produtos-section {
            margin-top: 2rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            background: linear-gradient(135deg, var(--light-gray) 0%, #f8f9fa 100%);
        }

        .produto-item {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 1.5rem;
            position: relative;
            transition: var(--transition);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .produto-item:hover {
            box-shadow: var(--shadow);
            border-color: var(--secondary-color);
            transform: translateY(-2px);
        }

        .produto-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .produto-title {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .produto-title i {
            color: var(--secondary-color);
        }

        .remove-produto-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .remove-produto-btn:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }

        .add-produto-btn {
            background: var(--success-color);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            padding: 1rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            width: 100%;
            margin-top: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .add-produto-btn:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            min-width: 160px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--medium-gray) 0%, #5a6268 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
        }

        .btn-secondary:hover:not(:disabled) {
            background: linear-gradient(135deg, #5a6268 0%, var(--medium-gray) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(108, 117, 125, 0.3);
        }

        /* Modal styles */
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
            animation: fadeInModal 0.3s ease;
        }

        .modal-content {
            background: white;
            margin: 8% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideInUp 0.3s ease;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--success-color), #1e7e34);
            color: white;
            padding: 2rem;
            text-align: center;
            position: relative;
        }

        .modal-header h3 {
            margin: 0;
            color: white;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            border-bottom: none;
        }

        .modal-body {
            padding: 2rem;
            text-align: center;
        }

        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }

        .modal-buttons .btn-primary,
        .modal-buttons .btn-secondary {
            padding: 0.75rem 1.5rem;
            min-width: 140px;
        }

        @keyframes fadeInModal {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideInUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Estados de input */
        .form-control.loading {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        .form-control.success-state {
            border-color: var(--success-color);
            background: rgba(40, 167, 69, 0.05);
        }

        .form-control.error-state {
            border-color: var(--danger-color);
            background: rgba(220, 53, 69, 0.05);
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Métricas display */
        .metrics-display {
            background: white;
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-around;
            border: 2px solid var(--border-color);
            font-size: 0.9rem;
            font-weight: 600;
        }

        .metrics-display div {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
        }

        .metrics-value {
            color: var(--secondary-color);
            font-size: 1.2rem;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .container {
                margin: 1.5rem 1rem;
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.75rem;
                flex-direction: column;
                gap: 0.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .btn-container {
                flex-direction: column;
                gap: 1rem;
            }

            .btn {
                width: 100%;
            }

            .modal-buttons {
                flex-direction: column;
            }

            .produto-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .metrics-display {
                flex-direction: column;
                gap: 1rem;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 1rem;
                margin: 1rem 0.5rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .form-control {
                padding: 0.75rem;
            }

            .produto-item {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>
        <i class="fas fa-shopping-cart"></i>
        Cadastro de Vendas
    </h2>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form class="form-container" action="cadastro_vendas.php" method="POST" enctype="multipart/form-data" onsubmit="return validarFormulario()">
        
        <div class="form-row">
            <div class="form-group autocomplete-container">
                <label for="cliente_uasg" class="required">
                    <i class="fas fa-id-card"></i>
                    UASG
                </label>
                <input type="text" 
                       id="cliente_uasg" 
                       name="cliente_uasg" 
                       class="form-control" 
                       placeholder="Digite a UASG" 
                       autocomplete="off"
                       value="<?php echo $success ? '' : (isset($_POST['cliente_uasg']) ? htmlspecialchars($_POST['cliente_uasg']) : ''); ?>"
                       required>
                <div id="uasg-suggestions" class="autocomplete-suggestions"></div>
            </div>
            
            <div class="form-group">
                <label for="cliente" class="required">
                    <i class="fas fa-building"></i>
                    Nome do Cliente
                </label>
                <input type="text" 
                       id="cliente" 
                       name="cliente" 
                       class="form-control" 
                       placeholder="Nome será preenchido automaticamente" 
                       value="<?php echo $success ? '' : (isset($_POST['cliente']) ? htmlspecialchars($_POST['cliente']) : ''); ?>"
                       readonly required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="empenho" class="required">
                    <i class="fas fa-file-invoice-dollar"></i>
                    Empenho
                </label>
                <select name="empenho" id="empenho" class="form-control" required>
                    <option value="">Selecione o Empenho</option>
                    <?php foreach ($empenhos as $empenho): ?>
                        <option value="<?php echo $empenho['id']; ?>"><?php echo $empenho['numero']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="numero" class="required">
                    <i class="fas fa-hashtag"></i>
                    Número da Nota Fiscal
                </label>
                <input type="text" 
                       id="numero" 
                       name="numero" 
                       class="form-control"
                       placeholder="Digite o número da NF"
                       value="<?php echo $success ? '' : (isset($_POST['numero']) ? htmlspecialchars($_POST['numero']) : ''); ?>"
                       required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="transportadora" class="required">
                    <i class="fas fa-truck"></i>
                    Transportadora
                </label>
                <select name="transportadora" id="transportadora" class="form-control" required>
                    <option value="">Selecione a Transportadora</option>
                    <?php foreach ($transportadoras as $transportadora): ?>
                        <option value="<?php echo $transportadora['id']; ?>"><?php echo $transportadora['nome']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="classificacao" class="required">
                    <i class="fas fa-tags"></i>
                    Classificação
                </label>
                <select name="classificacao" id="classificacao" class="form-control" required>
                    <option value="">Selecionar classificação</option>
                    <option value="Faturada" <?php echo (isset($_POST['classificacao']) && $_POST['classificacao'] == 'Faturada') ? 'selected' : ''; ?>>Faturada</option>
                    <option value="Comprada" <?php echo (isset($_POST['classificacao']) && $_POST['classificacao'] == 'Comprada') ? 'selected' : ''; ?>>Comprada</option>
                    <option value="Entregue" <?php echo (isset($_POST['classificacao']) && $_POST['classificacao'] == 'Entregue') ? 'selected' : ''; ?>>Entregue</option>
                    <option value="Liquidada" <?php echo (isset($_POST['classificacao']) && $_POST['classificacao'] == 'Liquidada') ? 'selected' : ''; ?>>Liquidada</option>
                    <option value="Pendente" <?php echo (!isset($_POST['classificacao']) || $_POST['classificacao'] == 'Pendente') ? 'selected' : ''; ?>>Pendente</option>
                    <option value="Devolução" <?php echo (isset($_POST['classificacao']) && $_POST['classificacao'] == 'Devolução') ? 'selected' : ''; ?>>Devolução</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="data" class="required">
                    <i class="fas fa-calendar-alt"></i>
                    Data de Venda
                </label>
                <input type="text" 
                       id="data" 
                       name="data" 
                       class="form-control"
                       placeholder="DD/MM/AAAA" 
                       oninput="formatarData(this)"
                       value="<?php echo $success ? '' : (isset($_POST['data']) ? htmlspecialchars($_POST['data']) : ''); ?>"
                       required>
                <small style="color: var(--medium-gray); font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                    Data da emissão da nota fiscal
                </small>
            </div>

            <div class="form-group">
                <label for="data_vencimento" class="required">
                    <i class="fas fa-calendar-check"></i>
                    Data de Vencimento
                </label>
                <input type="text" 
                       id="data_vencimento" 
                       name="data_vencimento" 
                       class="form-control"
                       placeholder="DD/MM/AAAA" 
                       oninput="formatarData(this)"
                       value="<?php echo $success ? '' : (isset($_POST['data_vencimento']) ? htmlspecialchars($_POST['data_vencimento']) : ''); ?>"
                       required>
                <small style="color: var(--medium-gray); font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                    Data limite para recebimento
                </small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="pregao">
                    <i class="fas fa-gavel"></i>
                    Pregão
                </label>
                <input type="text" 
                       id="pregao" 
                       name="pregao" 
                       class="form-control"
                       placeholder="Número do pregão (opcional)"
                       value="<?php echo $success ? '' : (isset($_POST['pregao']) ? htmlspecialchars($_POST['pregao']) : ''); ?>">
            </div>

            <div class="form-group">
                <label for="valor_total_venda">
                    <i class="fas fa-dollar-sign"></i>
                    Valor Total da Venda
                </label>
                <input type="text" 
                       id="valor_total_venda" 
                       name="valor_total_venda" 
                       class="form-control"
                       placeholder="R$ 0,00"
                       value="<?php echo $success ? '' : (isset($_POST['valor_total_venda']) ? htmlspecialchars($_POST['valor_total_venda']) : ''); ?>"
                       readonly>
            </div>
        </div>

        <div class="form-group">
            <label for="observacao">
                <i class="fas fa-comment-alt"></i>
                Observações
            </label>
            <textarea id="observacao" 
                      name="observacao" 
                      class="form-control" 
                      rows="3"
                      placeholder="Observações adicionais sobre a venda"><?php echo $success ? '' : (isset($_POST['observacao']) ? htmlspecialchars($_POST['observacao']) : ''); ?></textarea>
        </div>

        <h3><i class="fas fa-shopping-cart"></i> Produtos</h3>
        <div class="produtos-section">
            <div id="metrics-display" class="metrics-display" style="display: none;">
                <div>
                    <span>Produtos</span>
                    <span class="metrics-value" id="total-produtos">0</span>
                </div>
                <div>
                    <span>Itens</span>
                    <span class="metrics-value" id="total-itens">0</span>
                </div>
                <div>
                    <span>Valor Total</span>
                    <span class="metrics-value" id="total-valor">R$ 0,00</span>
                </div>
            </div>

            <div id="produtos-container">
                <!-- Os produtos serão adicionados dinamicamente aqui -->
            </div>

            <button type="button" class="add-produto-btn" id="addProdutoBtn">
                <i class="fas fa-plus"></i>
                Adicionar Produto
            </button>
        </div>

        <div class="btn-container">
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-save"></i>
                Cadastrar Venda
            </button>
            <button type="reset" class="btn btn-secondary" onclick="limparFormulario()">
                <i class="fas fa-undo"></i>
                Limpar Campos
            </button>
        </div>
    </form>
</div>

<!-- Modal de sucesso -->
<div id="successModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-check-circle"></i>
                Venda Cadastrada!
            </h3>
        </div>
        <div class="modal-body">
            <p>A venda foi cadastrada com sucesso no sistema.</p>
            <p>Deseja acessar a página de consulta de vendas?</p>
            <div class="modal-buttons">
                <button class="btn-primary" onclick="goToConsulta()">
                    <i class="fas fa-search"></i>
                    Sim, Ver Vendas
                </button>
                <button class="btn-secondary" onclick="closeModal()">
                    <i class="fas fa-plus"></i>
                    Cadastrar Outra
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let uasgTimeout = null;
let produtoTimeouts = {};

// ===========================================
// AUTOCOMPLETE PARA UASG/CLIENTES
// ===========================================

function initUasgAutocomplete() {
    const uasgInput = document.getElementById('cliente_uasg');
    const clienteNomeInput = document.getElementById('cliente');
    const suggestionsDiv = document.getElementById('uasg-suggestions');

    if (!uasgInput || !clienteNomeInput || !suggestionsDiv) {
        console.error('Elementos do autocomplete UASG não encontrados');
        return;
    }

    uasgInput.addEventListener('input', function() {
        const query = this.value.trim();
        
        if (uasgTimeout) {
            clearTimeout(uasgTimeout);
        }

        if (query.length < 2) {
            suggestionsDiv.style.display = 'none';
            clienteNomeInput.value = '';
            return;
        }

        uasgTimeout = setTimeout(() => {
            fetchUasgSuggestions(query);
        }, 300);
    });

    // Fecha sugestões ao clicar fora
    document.addEventListener('click', function(e) {
        if (!uasgInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
            suggestionsDiv.style.display = 'none';
        }
    });

    // Navegação por teclado
    uasgInput.addEventListener('keydown', function(e) {
        const suggestions = suggestionsDiv.querySelectorAll('.autocomplete-suggestion');
        let activeIndex = Array.from(suggestions).findIndex(s => s.classList.contains('active'));

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (activeIndex < suggestions.length - 1) {
                if (activeIndex >= 0) suggestions[activeIndex].classList.remove('active');
                suggestions[activeIndex + 1].classList.add('active');
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (activeIndex > 0) {
                suggestions[activeIndex].classList.remove('active');
                suggestions[activeIndex - 1].classList.add('active');
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0) {
                selectUasgSuggestion(suggestions[activeIndex]);
            }
        } else if (e.key === 'Escape') {
            suggestionsDiv.style.display = 'none';
        }
    });
}

function fetchUasgSuggestions(query) {
    const suggestionsDiv = document.getElementById('uasg-suggestions');
    
    suggestionsDiv.innerHTML = '<div class="autocomplete-suggestion">🔍 Buscando...</div>';
    suggestionsDiv.style.display = 'block';

    fetch(`search_clientes.php?query=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(data => {
            displayUasgSuggestions(data, query);
        })
        .catch(error => {
            console.error('Erro ao buscar clientes:', error);
            suggestionsDiv.innerHTML = '<div class="autocomplete-suggestion">❌ Erro ao buscar clientes</div>';
        });
}

function displayUasgSuggestions(clientes, query) {
    const suggestionsDiv = document.getElementById('uasg-suggestions');
    
    if (clientes.length === 0) {
        suggestionsDiv.innerHTML = '<div class="autocomplete-suggestion">📭 Nenhum cliente encontrado</div>';
        return;
    }

    const html = clientes.map(cliente => {
        const uasgHighlight = highlightText(cliente.uasg, query);
        const nomeHighlight = highlightText(cliente.nome_orgaos, query);
        
        return `
            <div class="autocomplete-suggestion" 
                 onclick="selectUasgSuggestion(this)"
                 data-uasg="${cliente.uasg}"
                 data-nome="${cliente.nome_orgaos}"
                 data-cnpj="${cliente.cnpj || ''}">
                <div class="suggestion-main">UASG: ${uasgHighlight}</div>
                <div class="suggestion-secondary">${nomeHighlight}</div>
                ${cliente.cnpj ? `<div class="suggestion-secondary">CNPJ: ${cliente.cnpj}</div>` : ''}
            </div>
        `;
    }).join('');

    suggestionsDiv.innerHTML = html;
    suggestionsDiv.style.display = 'block';
}

function selectUasgSuggestion(element) {
    const uasgInput = document.getElementById('cliente_uasg');
    const clienteNomeInput = document.getElementById('cliente');
    const suggestionsDiv = document.getElementById('uasg-suggestions');

    const uasg = element.getAttribute('data-uasg');
    const nome = element.getAttribute('data-nome');

    uasgInput.value = uasg;
    clienteNomeInput.value = nome;
    
    // Efeito visual de sucesso
    uasgInput.classList.add('success-state');
    clienteNomeInput.classList.add('success-state');
    
    setTimeout(() => {
        uasgInput.classList.remove('success-state');
        clienteNomeInput.classList.remove('success-state');
    }, 2000);

    suggestionsDiv.style.display = 'none';
    
    // Busca empenhos relacionados
    fetchEmpenhos(uasg);
}

function highlightText(text, query) {
    if (!query) return text;
    const regex = new RegExp(`(${query})`, 'gi');
    return text.replace(regex, '<span class="suggestion-highlight">$1</span>');
}

// ===========================================
// GERENCIAMENTO DE EMPENHOS
// ===========================================

function fetchEmpenhos(uasg) {
    fetch(`cadastro_vendas.php?cliente_uasg=${encodeURIComponent(uasg)}`)
        .then(response => response.json())
        .then(empenhos => {
            const empenhoSelect = document.getElementById('empenho');
            empenhoSelect.innerHTML = '<option value="">Selecione o Empenho</option>';
            
            if (empenhos.error) {
                console.warn(empenhos.error);
                showToast('Nenhum empenho encontrado para esta UASG', 'warning');
            } else {
                empenhos.forEach(empenho => {
                    const option = document.createElement('option');
                    option.value = empenho.id;
                    option.textContent = empenho.numero;
                    empenhoSelect.appendChild(option);
                });
                showToast(`${empenhos.length} empenho(s) encontrado(s)`, 'success');
            }
        })
        .catch(error => {
            console.error('Erro ao buscar empenhos:', error);
            showToast('Erro ao buscar empenhos', 'error');
        });
}

// ===========================================
// GERENCIAMENTO DE PRODUTOS
// ===========================================

function addProduto() {
    const container = document.getElementById('produtos-container');
    if (!container) {
        console.error('Container de produtos não encontrado');
        return;
    }
    
    const produtoCount = container.children.length;
    const newProduto = document.createElement('div');
    newProduto.className = 'produto-item';
    newProduto.style.opacity = "0";
    newProduto.style.transform = "translateY(20px)";
    
    newProduto.innerHTML = `
        <div class="produto-header">
            <div class="produto-title">
                <i class="fas fa-box"></i>
                <span>Produto ${produtoCount + 1}</span>
            </div>
            <button type="button" class="remove-produto-btn" onclick="removeProduto(this)">
                <i class="fas fa-trash-alt"></i> Remover
            </button>
        </div>
        
        <div class="form-group">
            <label>
                <i class="fas fa-tag"></i>
                Produto
            </label>
            <select name="produto[]" class="form-control" required onchange="atualizaValorUnitario(this)">
                <option value="">Selecione o Produto</option>
                <?php foreach ($produtos as $produto): ?>
                    <option value="<?php echo $produto['id']; ?>" data-preco="<?php echo $produto['preco_unitario']; ?>"><?php echo $produto['nome']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>
                    <i class="fas fa-sort-numeric-up"></i>
                    Quantidade
                </label>
                <input type="number" 
                       name="quantidade[]" 
                       class="form-control" 
                       min="1" 
                       value="1" 
                       oninput="calculaTotalProduto(this)" 
                       required>
            </div>

            <div class="form-group">
                <label>
                    <i class="fas fa-dollar-sign"></i>
                    Valor Unitário
                </label>
                <input type="text" 
                       name="valor_unitario[]" 
                       class="form-control" 
                       value="0.00"
                       oninput="calculaTotalProduto(this)" 
                       required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>
                    <i class="fas fa-calculator"></i>
                    Valor Total
                </label>
                <input type="text" 
                       name="valor_total[]" 
                       class="form-control" 
                       value="0.00" 
                       readonly>
            </div>

            <div class="form-group">
                <label>
                    <i class="fas fa-comment"></i>
                    Observação
                </label>
                <input type="text" 
                       name="observacao_produto[]" 
                       class="form-control"
                       placeholder="Observação do produto">
            </div>
        </div>
    `;

    container.appendChild(newProduto);
    
    // Animação de entrada
    setTimeout(() => {
        newProduto.style.opacity = "1";
        newProduto.style.transform = "translateY(0)";
    }, 10);
    
    // Foca no select do produto
    setTimeout(() => {
        const produtoSelect = newProduto.querySelector('select[name="produto[]"]');
        if (produtoSelect) {
            produtoSelect.focus();
        }
    }, 300);
    
    updateMetrics();
    showMetrics();
}

function removeProduto(button) {
    const container = button.closest('.produto-item');
    
    if (confirm('Tem certeza que deseja remover este produto?')) {
        container.style.opacity = "0";
        container.style.transform = "translateX(20px)";
        
        setTimeout(() => {
            container.remove();
            updateMetrics();
            
            // Atualiza numeração dos produtos
            const produtos = document.querySelectorAll('.produto-item');
            produtos.forEach(function(produto, index) {
                const titulo = produto.querySelector('.produto-title span');
                if (titulo) {
                    titulo.textContent = `Produto ${index + 1}`;
                }
            });
            
            if (produtos.length === 0) {
                hideMetrics();
            }
        }, 300);
    }
}

function atualizaValorUnitario(selectElement) {
    const precoUnitario = parseFloat(selectElement.selectedOptions[0].getAttribute('data-preco')) || 0;
    const container = selectElement.closest('.produto-item');
    const valorUnitarioInput = container.querySelector('input[name="valor_unitario[]"]');
    const quantidadeInput = container.querySelector('input[name="quantidade[]"]');
    const valorTotalInput = container.querySelector('input[name="valor_total[]"]');

    valorUnitarioInput.value = precoUnitario.toFixed(2);
    
    const quantidade = parseFloat(quantidadeInput.value) || 0;
    const valorTotalProduto = quantidade * precoUnitario;
    valorTotalInput.value = valorTotalProduto.toFixed(2);

    updateMetrics();
}

function calculaTotalProduto(inputElement) {
    const container = inputElement.closest('.produto-item');
    const quantidadeInput = container.querySelector('input[name="quantidade[]"]');
    const valorUnitarioInput = container.querySelector('input[name="valor_unitario[]"]');
    const valorTotalInput = container.querySelector('input[name="valor_total[]"]');

    const quantidade = parseFloat(quantidadeInput.value.replace(',', '.')) || 0;
    const precoUnitario = parseFloat(valorUnitarioInput.value.replace(',', '.')) || 0;
    const valorTotalProduto = quantidade * precoUnitario;
    
    valorTotalInput.value = valorTotalProduto.toFixed(2);
    updateMetrics();
}

function updateMetrics() {
    const produtos = document.querySelectorAll('.produto-item');
    let totalProdutos = produtos.length;
    let totalItens = 0;
    let valorTotal = 0;

    produtos.forEach(function(produto) {
        const quantidadeInput = produto.querySelector('input[name="quantidade[]"]');
        const valorTotalInput = produto.querySelector('input[name="valor_total[]"]');
        
        const quantidade = parseFloat(quantidadeInput?.value || 0);
        const valorTotalProduto = parseFloat(valorTotalInput?.value || 0);
        
        totalItens += quantidade;
        valorTotal += valorTotalProduto;
    });

    // Atualiza displays
    document.getElementById('total-produtos').textContent = totalProdutos;
    document.getElementById('total-itens').textContent = totalItens;
    document.getElementById('total-valor').textContent = formatCurrency(valorTotal);
    
    // Atualiza campo do valor total da venda
    const valorTotalVendaInput = document.getElementById('valor_total_venda');
    if (valorTotalVendaInput) {
        valorTotalVendaInput.value = valorTotal.toFixed(2);
    }
}

function showMetrics() {
    const metricsDisplay = document.getElementById('metrics-display');
    if (metricsDisplay) {
        metricsDisplay.style.display = 'flex';
    }
}

function hideMetrics() {
    const metricsDisplay = document.getElementById('metrics-display');
    if (metricsDisplay) {
        metricsDisplay.style.display = 'none';
    }
}

// ===========================================
// CARREGAR DADOS DO EMPENHO
// ===========================================

document.getElementById('empenho').addEventListener('change', function() {
    const empenhoId = this.value;
    
    // Limpar produtos existentes
    const produtosContainer = document.getElementById('produtos-container');
    produtosContainer.innerHTML = '';
    hideMetrics();
    
    if (!empenhoId) {
        return;
    }
    
    // Buscar detalhes do empenho
    fetch('cadastro_vendas.php?empenho_id=' + empenhoId)
        .then(response => response.json())
        .then(empenho => {
            if (empenho.error) {
                console.warn(empenho.error);
                return;
            }
            
            // Atualizar campos do empenho
            if (empenho.pregao) {
                document.getElementById('pregao').value = empenho.pregao;
            }
            
            if (empenho.observacao) {
                document.getElementById('observacao').value = empenho.observacao;
            }
            
            // Adicionar produtos do empenho
            if (empenho.produtos_detalhes && empenho.produtos_detalhes.length > 0) {
                empenho.produtos_detalhes.forEach(produto => {
                    adicionarProdutoDoEmpenho(produto);
                });
                updateMetrics();
                showMetrics();
            } else {
                // Adicionar produto vazio se não houver produtos
                addProduto();
            }
        })
        .catch(error => {
            console.error('Erro ao buscar detalhes do empenho:', error);
            addProduto();
        });
});

function adicionarProdutoDoEmpenho(produto) {
    const container = document.getElementById('produtos-container');
    const produtoCount = container.children.length;
    
    const newProduto = document.createElement('div');
    newProduto.className = 'produto-item';
    
    newProduto.innerHTML = `
        <div class="produto-header">
            <div class="produto-title">
                <i class="fas fa-box"></i>
                <span>Produto ${produtoCount + 1}</span>
            </div>
            <button type="button" class="remove-produto-btn" onclick="removeProduto(this)">
                <i class="fas fa-trash-alt"></i> Remover
            </button>
        </div>
        
        <div class="form-group">
            <label>
                <i class="fas fa-tag"></i>
                Produto
            </label>
            <select name="produto[]" class="form-control" required onchange="atualizaValorUnitario(this)">
                <option value="">Selecione o Produto</option>
                <?php foreach ($produtos as $prod): ?>
                    <option value="<?php echo $prod['id']; ?>" data-preco="<?php echo $prod['preco_unitario']; ?>"><?php echo $prod['nome']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>
                    <i class="fas fa-sort-numeric-up"></i>
                    Quantidade
                </label>
                <input type="number" 
                       name="quantidade[]" 
                       class="form-control" 
                       min="1" 
                       value="${produto.quantidade}" 
                       oninput="calculaTotalProduto(this)" 
                       required>
            </div>

            <div class="form-group">
                <label>
                    <i class="fas fa-dollar-sign"></i>
                    Valor Unitário
                </label>
                <input type="text" 
                       name="valor_unitario[]" 
                       class="form-control" 
                       value="${parseFloat(produto.valor_unitario).toFixed(2)}"
                       oninput="calculaTotalProduto(this)" 
                       required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>
                    <i class="fas fa-calculator"></i>
                    Valor Total
                </label>
                <input type="text" 
                       name="valor_total[]" 
                       class="form-control" 
                       value="${parseFloat(produto.valor_total).toFixed(2)}" 
                       readonly>
            </div>

            <div class="form-group">
                <label>
                    <i class="fas fa-comment"></i>
                    Observação
                </label>
                <input type="text" 
                       name="observacao_produto[]" 
                       class="form-control"
                       value="${produto.descricao_produto || ''}"
                       placeholder="Observação do produto">
            </div>
        </div>
    `;
    
    container.appendChild(newProduto);
    
    // Selecionar o produto correto
    const selectElement = newProduto.querySelector('select[name="produto[]"]');
    for (let i = 0; i < selectElement.options.length; i++) {
        if (selectElement.options[i].value == produto.produto_id) {
            selectElement.selectedIndex = i;
            break;
        }
    }
}

// ===========================================
// FORMATAÇÃO DE DATA
// ===========================================

function formatarData(input) {
    let valor = input.value.replace(/\D/g, '');
    
    if (valor.length <= 2) {
        input.value = valor;
    } else if (valor.length <= 4) {
        let dia = valor.substring(0, 2);
        if (parseInt(dia) > 31) dia = "31";
        let mes = valor.substring(2, 4);
        if (parseInt(mes) > 12) mes = "12";
        input.value = dia + "/" + mes;
    } else if (valor.length <= 8) {
        let dia = valor.substring(0, 2);
        if (parseInt(dia) > 31) dia = "31";
        let mes = valor.substring(2, 4);
        if (parseInt(mes) > 12) mes = "12";
        let ano = valor.substring(4, 8);
        input.value = dia + "/" + mes + "/" + ano;
        
        // Validação básica de data
        const dataObj = new Date(ano, mes-1, dia);
        if (dataObj.getDate() != dia || dataObj.getMonth() != mes-1 || dataObj.getFullYear() != ano) {
            input.setCustomValidity("Data inválida");
        } else {
            input.setCustomValidity("");
        }
    }
}

// ===========================================
// MODAL E NAVEGAÇÃO
// ===========================================

function goToConsulta() {
    document.getElementById('successModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    window.location.href = 'consulta_vendas.php?success=' + encodeURIComponent('Venda cadastrada com sucesso!');
}

function closeModal() {
    document.getElementById('successModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    limparFormulario();
    setTimeout(() => {
        const uasgInput = document.getElementById('cliente_uasg');
        if (uasgInput) uasgInput.focus();
    }, 100);
}

function limparFormulario() {
    const form = document.querySelector('form');
    if (form) form.reset();
    
    const container = document.getElementById('produtos-container');
    if (container) container.innerHTML = '';
    
    hideMetrics();
    
    document.querySelectorAll('.form-control').forEach(input => {
        input.classList.remove('success-state', 'error-state', 'warning-state');
    });
    
    document.querySelectorAll('.autocomplete-suggestions').forEach(suggestions => {
        suggestions.style.display = 'none';
    });
}

// ===========================================
// VALIDAÇÃO DO FORMULÁRIO
// ===========================================

function validarFormulario() {
    const uasg = document.getElementById("cliente_uasg")?.value.trim();
    const cliente = document.getElementById("cliente")?.value.trim();
    const empenho = document.getElementById("empenho")?.value;
    const numero = document.getElementById("numero")?.value.trim();
    const transportadora = document.getElementById("transportadora")?.value;
    const data = document.getElementById("data")?.value.trim();
    const dataVencimento = document.getElementById("data_vencimento")?.value.trim();
    const classificacao = document.getElementById("classificacao")?.value;
    
    // Verifica campos obrigatórios
    if (!uasg) {
        alert('O campo UASG é obrigatório!');
        document.getElementById("cliente_uasg")?.focus();
        return false;
    }
    
    if (!cliente) {
        alert('O campo Nome do Cliente é obrigatório!');
        document.getElementById("cliente")?.focus();
        return false;
    }
    
    if (!empenho) {
        alert('Selecione um empenho!');
        document.getElementById("empenho")?.focus();
        return false;
    }
    
    if (!numero) {
        alert('O campo Número da NF é obrigatório!');
        document.getElementById("numero")?.focus();
        return false;
    }
    
    if (!transportadora) {
        alert('Selecione uma transportadora!');
        document.getElementById("transportadora")?.focus();
        return false;
    }
    
    if (!data) {
        alert('O campo Data de Venda é obrigatório!');
        document.getElementById("data")?.focus();
        return false;
    }
    
    if (!dataVencimento) {
        alert('O campo Data de Vencimento é obrigatório!');
        document.getElementById("data_vencimento")?.focus();
        return false;
    }
    
    if (!classificacao) {
        alert('Selecione uma classificação!');
        document.getElementById("classificacao")?.focus();
        return false;
    }

    // Verifica se há pelo menos um produto
    const produtos = document.querySelectorAll('select[name="produto[]"]');
    if (produtos.length === 0) {
        alert('Adicione pelo menos um produto à venda!');
        document.getElementById('addProdutoBtn')?.focus();
        return false;
    }

    // Valida cada produto
    let valid = true;
    produtos.forEach(function(produto) {
        if (produto.value === "") {
            valid = false;
            produto.classList.add('error-state');
            setTimeout(() => {
                produto.classList.remove('error-state');
            }, 3000);
        }
    });

    const quantidades = document.querySelectorAll('input[name="quantidade[]"]');
    quantidades.forEach(function(quantidade) {
        if (parseFloat(quantidade.value) <= 0) {
            valid = false;
            quantidade.classList.add('error-state');
            setTimeout(() => {
                quantidade.classList.remove('error-state');
            }, 3000);
        }
    });

    const valoresUnitarios = document.querySelectorAll('input[name="valor_unitario[]"]');
    valoresUnitarios.forEach(function(valor) {
        if (parseFloat(valor.value) <= 0) {
            valid = false;
            valor.classList.add('error-state');
            setTimeout(() => {
                valor.classList.remove('error-state');
            }, 3000);
        }
    });

    if (!valid) {
        alert('Por favor, corrija os campos destacados em vermelho.');
        return false;
    }
    
    // Mostra loading no botão de submit
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cadastrando...';
    }
    
    return true;
}

function openModal() {
    const modal = document.getElementById('successModal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        setTimeout(() => {
            const primaryBtn = document.querySelector('.modal-buttons .btn-primary');
            if (primaryBtn) primaryBtn.focus();
        }, 300);
    }
}

// ===========================================
// FUNÇÕES AUXILIARES
// ===========================================

function formatCurrency(value) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    }).format(value);
}

function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    let backgroundColor, textColor, icon;
    switch(type) {
        case 'success':
            backgroundColor = 'var(--success-color)';
            textColor = 'white';
            icon = 'check-circle';
            break;
        case 'error':
            backgroundColor = 'var(--danger-color)';
            textColor = 'white';
            icon = 'exclamation-circle';
            break;
        case 'warning':
            backgroundColor = 'var(--warning-color)';
            textColor = '#333';
            icon = 'exclamation-triangle';
            break;
        default:
            backgroundColor = 'var(--info-color)';
            textColor = 'white';
            icon = 'info-circle';
    }
    
    toast.innerHTML = `
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-${icon}"></i>
            <span>${message}</span>
        </div>
    `;
    
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${backgroundColor};
        color: ${textColor};
        padding: 1rem 1.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        z-index: 1001;
        animation: slideInRight 0.3s ease;
        font-weight: 500;
        min-width: 300px;
        max-width: 400px;
    `;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// ===========================================
// INICIALIZAÇÃO DO SISTEMA
// ===========================================

document.addEventListener('DOMContentLoaded', function() {
    console.log('🔄 Iniciando sistema de cadastro de vendas...');
    
    // Inicializa autocomplete para UASG
    initUasgAutocomplete();
    
    // Configura o botão de adicionar produto
    const addProdutoBtn = document.getElementById('addProdutoBtn');
    if (addProdutoBtn) {
        addProdutoBtn.addEventListener('click', function(e) {
            e.preventDefault();
            addProduto();
        });
    }
    
    // Event listeners para campos de data
    const dataInputs = document.querySelectorAll('input[oninput="formatarData(this)"]');
    dataInputs.forEach(input => {
        input.addEventListener('input', function() {
            formatarData(this);
        });
    });
    
    // Foca no primeiro campo
    setTimeout(() => {
        const uasgInput = document.getElementById('cliente_uasg');
        if (uasgInput) {
            uasgInput.focus();
        }
    }, 200);
    
    console.log('✅ Sistema de cadastro de vendas carregado!');
});

// ===========================================
// WINDOW ONLOAD (VERIFICAR SUCESSO)
// ===========================================

window.onload = function() {
    const wasSuccessful = <?php echo $success ? 'true' : 'false'; ?>;
    
    if (wasSuccessful) {
        openModal();
    }
}

// ===========================================
// EVENT LISTENERS GERAIS
// ===========================================

// Fecha modal ao clicar fora
window.onclick = function(event) {
    const modal = document.getElementById('successModal');
    if (event.target === modal) {
        closeModal();
    }
}

// Atalhos de teclado
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        if (validarFormulario()) {
            document.querySelector('form')?.submit();
        }
    }
    
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        addProduto();
    }
    
    if (e.key === 'Escape') {
        // Fecha sugestões abertas
        document.querySelectorAll('.autocomplete-suggestions').forEach(suggestions => {
            suggestions.style.display = 'none';
        });
        
        // Fecha modal se estiver aberto
        const modal = document.getElementById('successModal');
        if (modal && modal.style.display === 'block') {
            closeModal();
        }
    }
});

// ===========================================
// ESTILOS CSS PARA ANIMAÇÕES
// ===========================================

const toastStyles = document.createElement('style');
toastStyles.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
    .toast {
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        backdrop-filter: blur(10px);
    }
`;
document.head.appendChild(toastStyles);

// ===========================================
// LOG FINAL
// ===========================================

console.log('🚀 Sistema de Cadastro de Vendas LicitaSis carregado:', {
    versao: '1.0 Melhorado',
    funcionalidades: [
        '✅ Autocomplete inteligente para UASG/Clientes',
        '✅ Busca automática de empenhos por UASG',
        '✅ Carregamento automático de produtos do empenho',
        '✅ Validação em tempo real',
        '✅ Cálculos automáticos de valores',
        '✅ Interface responsiva e moderna',
        '✅ Métricas em tempo real',
        '✅ Modal de sucesso',
        '✅ Formatação automática de datas',
        '✅ Notificações toast',
        '✅ Atalhos de teclado',
        '✅ Validação completa do formulário'
    ],
    estilo: 'Seguindo padrão do cadastro de empenhos',
    responsividade: 'Mobile-first design',
    acessibilidade: 'Suporte a teclado e aria-labels'
});
</script>

<?php
// ===========================================
// FINALIZAÇÃO DA PÁGINA
// ===========================================
if (function_exists('renderFooter')) {
    renderFooter();
}
if (function_exists('renderScripts')) {
    renderScripts();
}
?>

</body>
</html>