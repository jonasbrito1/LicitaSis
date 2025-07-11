<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// ===========================================
// CORREÇÃO CRÍTICA: ENDPOINT AJAX ISOLADO
// DEVE SER PROCESSADO ANTES DE QUALQUER OUTPUT
// ===========================================
if (isset($_GET['get_conta_id'])) {
    // CRÍTICO: Limpa qualquer output anterior
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // CRÍTICO: Força headers antes de qualquer coisa
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    
    $conta_id = intval($_GET['get_conta_id']);
    
    // Valida entrada
    if ($conta_id <= 0) {
        echo json_encode([
            'error' => 'ID de conta inválido',
            'debug' => 'ID recebido: ' . $_GET['get_conta_id']
        ]);
        exit();
    }
    
    try {
        // Includes necessários apenas para o endpoint
        require_once('db.php');
        
        // Verifica conexão
        if (!isset($pdo)) {
            echo json_encode(['error' => 'Erro de conexão com banco de dados']);
            exit();
        }
        
        // CORREÇÃO: Query simplificada sem restrições de status
        $sql = "SELECT cp.*, c.fornecedor, c.numero_nf, c.valor_total, c.data, c.numero_empenho, 
                       c.link_pagamento, c.comprovante_pagamento, c.observacao as observacao_compra, c.frete
                FROM contas_pagar cp 
                INNER JOIN compras c ON cp.compra_id = c.id 
                WHERE cp.id = ?";
        
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            echo json_encode([
                'error' => 'Erro ao preparar consulta: ' . implode(', ', $pdo->errorInfo())
            ]);
            exit();
        }
        
        $stmt->execute([$conta_id]);
        $conta = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$conta) {
            echo json_encode([
                'error' => 'Conta não encontrada',
                'debug' => [
                    'conta_id' => $conta_id,
                    'sql' => $sql,
                    'rowCount' => $stmt->rowCount()
                ]
            ]);
            exit();
        }
        
        // Busca produtos (com tratamento de erro)
        $produtos = [];
        try {
            // Verifica se tabela existe
            $checkTable = $pdo->query("SHOW TABLES LIKE 'produto_compra'");
            if ($checkTable->rowCount() > 0) {
                $sql_produtos = "SELECT pc.*, p.nome as produto_nome 
                                FROM produto_compra pc 
                                LEFT JOIN produtos p ON pc.produto_id = p.id 
                                WHERE pc.compra_id = ?";
                $stmt_produtos = $pdo->prepare($sql_produtos);
                if ($stmt_produtos) {
                    $stmt_produtos->execute([$conta['compra_id']]);
                    $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        } catch (Exception $e) {
            // Se der erro nos produtos, continua sem eles
            $produtos = [];
        }
        
        // Resposta de sucesso
        $response = [
            'success' => true,
            'conta' => $conta,
            'produtos' => $produtos,
            'debug' => [
                'conta_id' => $conta_id,
                'compra_id' => $conta['compra_id'],
                'produtos_count' => count($produtos),
                'status_pagamento' => $conta['status_pagamento'],
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit();
        
    } catch (PDOException $e) {
        echo json_encode([
            'error' => 'Erro de banco de dados: ' . $e->getMessage(),
            'debug' => [
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ]);
        exit();
    } catch (Exception $e) {
        echo json_encode([
            'error' => 'Erro inesperado: ' . $e->getMessage(),
            'debug' => [
                'type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ]);
        exit();
    }
}

// Resto do código só executa se NÃO for uma requisição AJAX
// ===========================================

// Includes necessários na ordem correta
require_once('db.php');
include('permissions.php');
include('includes/audit.php');

// Inicialização do sistema de permissões
$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('financeiro', 'read');
logUserAction('READ', 'contas_pagas_consulta');

// Inclui o header do sistema
include('includes/header_template.php');
renderHeader("Contas Pagas - LicitaSis", "financeiro");

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";
$contas = [];
$searchTerm = "";

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    
    // Consulta com filtro de pesquisa - apenas contas pagas
    try {
        $sql = "SELECT cp.*, c.fornecedor, c.numero_nf, c.valor_total, c.data, c.numero_empenho, 
                       c.link_pagamento, c.comprovante_pagamento, c.observacao as observacao_compra, c.frete
                FROM contas_pagar cp 
                INNER JOIN compras c ON cp.compra_id = c.id 
                WHERE cp.status_pagamento IN ('Pago', 'Concluido')
                   AND (c.numero_nf LIKE :searchTerm 
                        OR c.fornecedor LIKE :searchTerm 
                        OR cp.status_pagamento LIKE :searchTerm)
                ORDER BY cp.data_pagamento DESC, c.data DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        
        $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    // Consulta para mostrar todas as contas pagas
    try {
        $sql = "SELECT cp.*, c.fornecedor, c.numero_nf, c.valor_total, c.data, c.numero_empenho, 
                       c.link_pagamento, c.comprovante_pagamento, c.observacao as observacao_compra, c.frete
                FROM contas_pagar cp 
                INNER JOIN compras c ON cp.compra_id = c.id 
                WHERE cp.status_pagamento IN ('Pago', 'Concluido')
                ORDER BY cp.data_pagamento DESC, c.data DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar contas pagas: " . $e->getMessage();
    }
}

// Função para calcular totais das contas pagas
try {
    // Total de contas pagas por status
    $sqlTotalPago = "SELECT SUM(c.valor_total) AS total_pago 
                     FROM contas_pagar cp 
                     INNER JOIN compras c ON cp.compra_id = c.id 
                     WHERE cp.status_pagamento = 'Pago'";
    $stmtTotalPago = $pdo->prepare($sqlTotalPago);
    $stmtTotalPago->execute();
    $totalPago = $stmtTotalPago->fetch(PDO::FETCH_ASSOC)['total_pago'];
    
    // Total de contas concluídas
    $sqlTotalConcluido = "SELECT SUM(c.valor_total) AS total_concluido 
                         FROM contas_pagar cp 
                         INNER JOIN compras c ON cp.compra_id = c.id 
                         WHERE cp.status_pagamento = 'Concluido'";
    $stmtTotalConcluido = $pdo->prepare($sqlTotalConcluido);
    $stmtTotalConcluido->execute();
    $totalConcluido = $stmtTotalConcluido->fetch(PDO::FETCH_ASSOC)['total_concluido'];
    
    // Total geral de contas pagas
    $totalGeralPago = ($totalPago ?? 0) + ($totalConcluido ?? 0);
    
    // Contagem de contas
    $sqlCount = "SELECT 
                    COUNT(*) as total_contas,
                    SUM(CASE WHEN cp.status_pagamento = 'Pago' THEN 1 ELSE 0 END) as contas_pagas,
                    SUM(CASE WHEN cp.status_pagamento = 'Concluido' THEN 1 ELSE 0 END) as contas_concluidas
                 FROM contas_pagar cp 
                 WHERE cp.status_pagamento IN ('Pago', 'Concluido')";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute();
    $contadores = $stmtCount->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Erro ao calcular totais: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contas Pagas - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ===========================================
           VARIÁVEIS CSS E RESET
           =========================================== */
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
            /* Cores específicas para status de contas pagas */
            --pago-color: #28a745;
            --concluido-color: #17a2b8;
            --pendente-color: #fd7e14;
            --paid-bg: #d4edda;
            --completed-bg: #d1ecf1;
            --pending-bg: #fff3cd;
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

        /* ===========================================
           HEADER
           =========================================== */
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

        /* ===========================================
           NAVIGATION
           =========================================== */
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

        /* ===========================================
           LAYOUT PRINCIPAL
           =========================================== */
        .container {
            max-width: 1400px;
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
            color: var(--success-color);
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

        /* ===========================================
           ALERTAS E MENSAGENS
           =========================================== */
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

        /* ===========================================
           CARDS DE RESUMO
           =========================================== */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: linear-gradient(135deg, white 0%, var(--light-gray) 100%);
            border-radius: var(--radius);
            padding: 2rem;
            text-align: center;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border-left: 4px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--secondary-color), var(--success-color));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-hover);
        }

        .summary-card:hover::before {
            transform: scaleX(1);
        }

        .summary-card.total {
            border-left-color: var(--success-color);
        }

        .summary-card.pago {
            border-left-color: var(--info-color);
        }

        .summary-card.concluido {
            border-left-color: var(--primary-color);
        }

        .summary-card.count {
            border-left-color: var(--secondary-color);
        }

        .summary-card h4 {
            font-size: 0.95rem;
            color: var(--medium-gray);
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .summary-card .value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--success-color);
            margin-bottom: 0.5rem;
        }

        .summary-card .count-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--success-color);
        }

        .summary-card .icon {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            font-size: 2rem;
            opacity: 0.1;
            transition: var(--transition);
        }

        .summary-card:hover .icon {
            opacity: 0.3;
            transform: scale(1.1);
        }

        /* ===========================================
           BARRA DE PESQUISA
           =========================================== */
        .search-container {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
        }

        .search-bar {
            display: flex;
            gap: 1rem;
            align-items: end;
        }

        .search-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .search-group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--medium-gray);
            text-transform: uppercase;
        }

        .search-bar input {
            flex: 1;
            padding: 0.875rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background: white;
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
            transform: translateY(-1px);
        }

        .search-bar button {
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, var(--success-color) 0%, #218838 100%);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-bar button:hover {
            background: linear-gradient(135deg, #218838 0%, var(--success-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
        }

        /* ===========================================
           TABELA
           =========================================== */
        .table-container {
            overflow-x: auto;
            margin-bottom: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            background: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
        }

        table th, 
        table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.95rem;
        }

        table th {
            background: linear-gradient(135deg, var(--success-color) 0%, #218838 100%);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.85rem;
        }

        table th i {
            margin-right: 0.5rem;
        }

        table tbody tr {
            transition: var(--transition);
        }

        table tbody tr:hover {
            background: linear-gradient(135deg, var(--paid-bg) 0%, #c3e6cb 100%);
            transform: scale(1.01);
        }

        table tbody tr:nth-child(even) {
            background: rgba(212, 237, 218, 0.3);
        }

        table tbody tr:nth-child(even):hover {
            background: linear-gradient(135deg, var(--paid-bg) 0%, #c3e6cb 100%);
        }

        /* ===========================================
           ELEMENTOS ESPECÍFICOS DA TABELA
           =========================================== */
        .numero-nf {
            cursor: pointer;
            color: var(--success-color);
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            background: rgba(40, 167, 69, 0.1);
        }

        .numero-nf:hover {
            color: var(--primary-color);
            background: rgba(45, 137, 62, 0.1);
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.2);
        }

        .numero-nf i {
            font-size: 0.8rem;
        }

        /* ===========================================
           STATUS BADGES
           =========================================== */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.pago {
            background: rgba(40, 167, 69, 0.1);
            color: var(--pago-color);
            border: 1px solid var(--pago-color);
        }

        .status-badge.concluido {
            background: rgba(23, 162, 184, 0.1);
            color: var(--concluido-color);
            border: 1px solid var(--concluido-color);
        }

        .status-badge.pendente {
            background: rgba(253, 126, 20, 0.1);
            color: var(--pendente-color);
            border: 1px solid var(--pendente-color);
        }

        /* ===========================================
           MODAL
           =========================================== */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
            overflow-y: auto;
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 95%;
            max-width: 1200px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: slideIn 0.3s ease;
            max-height: 95vh;
            overflow-y: auto;
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--success-color), var(--primary-color));
            color: white;
            padding: 1.5rem 2rem;
            border-radius: var(--radius) var(--radius) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: white;
            border-bottom: none;
        }

        .close {
            color: white;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.1);
        }

        .modal-body {
            padding: 2rem;
            max-height: 70vh;
            overflow-y: auto;
        }

        /* ===========================================
           SEÇÕES DE DETALHES DO MODAL
           =========================================== */
        .conta-details {
            display: grid;
            gap: 2rem;
        }

        .detail-section {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            overflow: hidden;
        }

        .detail-header {
            background: var(--light-gray);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            color: var(--success-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .detail-content {
            padding: 1.5rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .detail-label {
            font-weight: 600;
            color: var(--medium-gray);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            color: var(--dark-gray);
            font-size: 1rem;
            font-weight: 500;
        }

        .detail-value.highlight {
            color: var(--success-color);
            font-weight: 700;
            font-size: 1.1rem;
        }

        .detail-value.money {
            color: var(--success-color);
            font-weight: 700;
            font-size: 1.1rem;
        }

        /* ===========================================
           STATUS DE PAGAMENTO (READONLY)
           =========================================== */
        .status-section {
            background: linear-gradient(135deg, var(--paid-bg) 0%, #c3e6cb 100%);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-top: 1.5rem;
            border-left: 4px solid var(--success-color);
        }

        .status-section h4 {
            color: var(--success-color);
            margin-bottom: 1rem;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* ===========================================
           SEÇÃO DE PRODUTOS NO MODAL
           =========================================== */
        .produtos-section {
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .produto-item {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        .produto-item:hover {
            box-shadow: var(--shadow);
            border-color: var(--success-color);
            transform: translateY(-2px);
        }

        .produto-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .produto-title {
            font-weight: 600;
            color: var(--success-color);
            font-size: 1.1rem;
        }

        /* ===========================================
           COMPROVANTE DE PAGAMENTO
           =========================================== */
        .comprovante-container {
            margin-top: 1rem;
        }

        .comprovante-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: var(--light-gray);
            border-radius: var(--radius-sm);
            color: var(--success-color);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            border: 2px solid var(--border-color);
        }

        .comprovante-link:hover {
            background: var(--success-color);
            color: white;
            border-color: var(--success-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2);
        }

        .comprovante-link i {
            font-size: 1.2rem;
        }

        /* ===========================================
           BOTÕES
           =========================================== */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
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

        .btn-secondary {
            background: linear-gradient(135deg, var(--medium-gray) 0%, #5a6268 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268 0%, var(--medium-gray) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(108, 117, 125, 0.3);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(45, 137, 62, 0.2);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(45, 137, 62, 0.3);
        }

        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        /* ===========================================
           DEBUG E ERRO
           =========================================== */
        .debug-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: var(--radius);
            padding: 1rem;
            margin-top: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            color: #495057;
            max-height: 200px;
            overflow-y: auto;
        }

        .error-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--danger-color);
        }

        .error-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--danger-color);
        }

        .error-state h3 {
            color: var(--danger-color);
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }

        .error-state p {
            color: var(--medium-gray);
            margin-bottom: 1.5rem;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }

        /* ===========================================
           LOADING E UTILITÁRIOS
           =========================================== */
        .loading {
            opacity: 0.6;
            pointer-events: none;
            position: relative;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 20px;
            border: 2px solid var(--border-color);
            border-top-color: var(--success-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .no-results {
            text-align: center;
            color: var(--medium-gray);
            font-style: italic;
            padding: 4rem 2rem;
            font-size: 1.1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            background: var(--light-gray);
            border-radius: var(--radius);
            border: 2px dashed var(--border-color);
        }

        .no-results i {
            font-size: 3rem;
            color: var(--success-color);
        }

        /* ===========================================
           RESPONSIVIDADE
           =========================================== */
        @media (max-width: 1200px) {
            .container {
                margin: 2rem 1.5rem;
                padding: 2rem;
            }

            .modal-content {
                width: 98%;
                margin: 1% auto;
            }

            .search-bar {
                flex-direction: column;
                gap: 1rem;
            }

            .search-bar > * {
                width: 100%;
            }

            .summary-cards {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

            .container {
                margin: 1.5rem 1rem;
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.75rem;
                flex-direction: column;
                gap: 0.5rem;
            }

            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .btn-container {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .modal-header {
                padding: 1rem 1.5rem;
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .modal-body {
                padding: 1.5rem;
            }

            table th, table td {
                padding: 0.75rem 0.5rem;
                font-size: 0.85rem;
            }

            .numero-nf {
                padding: 0.25rem 0.5rem;
                font-size: 0.85rem;
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
                margin: 1rem 0.5rem;
                padding: 1.25rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .summary-cards {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 100%;
                margin: 0;
                border-radius: 0;
                max-height: 100vh;
            }

            .modal-header {
                border-radius: 0;
            }

            table {
                font-size: 0.8rem;
            }

            table th, table td {
                padding: 0.5rem 0.25rem;
                min-width: 100px;
            }

            .numero-nf {
                font-size: 0.8rem;
                padding: 0.25rem 0.4rem;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>
        <i class="fas fa-check-circle"></i>
        Contas Pagas
    </h2>

    <!-- ===========================================
         MENSAGENS DE FEEDBACK
         =========================================== -->
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($_GET['success']); ?>
        </div>
    <?php endif; ?>

    <!-- ===========================================
         CARDS DE RESUMO
         =========================================== -->
    <div class="summary-cards">
        <div class="summary-card total">
            <h4>Total Pago Geral</h4>
            <div class="value">R$ <?php echo number_format($totalGeralPago ?? 0, 2, ',', '.'); ?></div>
            <i class="fas fa-coins icon"></i>
        </div>
        <div class="summary-card pago">
            <h4>Status: Pago</h4>
            <div class="value">R$ <?php echo number_format($totalPago ?? 0, 2, ',', '.'); ?></div>
            <i class="fas fa-credit-card icon"></i>
        </div>
        <div class="summary-card concluido">
            <h4>Status: Concluído</h4>
            <div class="value">R$ <?php echo number_format($totalConcluido ?? 0, 2, ',', '.'); ?></div>
            <i class="fas fa-check-double icon"></i>
        </div>
        <div class="summary-card count">
            <h4>Total de Contas</h4>
            <div class="count-value"><?php echo $contadores['total_contas'] ?? 0; ?></div>
            <i class="fas fa-file-invoice icon"></i>
        </div>
    </div>

    <!-- ===========================================
         BARRA DE PESQUISA
         =========================================== -->
    <div class="search-container">
        <form action="contas_pagas.php" method="GET">
            <div class="search-bar">
                <div class="search-group">
                    <label for="search">Buscar por:</label>
                    <input type="text" 
                           name="search" 
                           id="search" 
                           placeholder="Número da NF, Fornecedor ou Status..." 
                           value="<?php echo htmlspecialchars($searchTerm); ?>"
                           autocomplete="off">
                </div>
                <button type="submit">
                    <i class="fas fa-search"></i> 
                    Pesquisar
                </button>
            </div>
        </form>
    </div>

    <!-- ===========================================
         TABELA DE CONTAS PAGAS
         =========================================== -->
    <?php if (count($contas) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th><i class="fas fa-file-invoice"></i> Número da NF</th>
                        <th><i class="fas fa-building"></i> Fornecedor</th>
                        <th><i class="fas fa-dollar-sign"></i> Valor Total</th>
                        <th><i class="fas fa-calendar"></i> Data da Compra</th>
                        <th><i class="fas fa-tags"></i> Status</th>
                        <th><i class="fas fa-calendar-check"></i> Data de Pagamento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contas as $conta): ?>
                        <tr>
                            <td>
                                <span class="numero-nf" onclick="openModal(<?php echo $conta['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                    <?php echo htmlspecialchars($conta['numero_nf']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($conta['fornecedor']); ?></td>
                            <td>
                                <strong style="color: var(--success-color);">
                                    R$ <?php echo number_format($conta['valor_total'], 2, ',', '.'); ?>
                                </strong>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($conta['data'])); ?></td>
                            <td>
                                <span class="status-badge <?php echo strtolower($conta['status_pagamento']); ?>">
                                    <?php
                                    $icons = [
                                        'Pago' => 'check-circle',
                                        'Concluido' => 'check-double',
                                        'Pendente' => 'clock'
                                    ];
                                    $icon = $icons[$conta['status_pagamento']] ?? 'tag';
                                    ?>
                                    <i class="fas fa-<?php echo $icon; ?>"></i>
                                    <?php echo $conta['status_pagamento']; ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                    if ($conta['data_pagamento']) {
                                        echo '<strong style="color: var(--success-color);">' . date('d/m/Y', strtotime($conta['data_pagamento'])) . '</strong>';
                                    } else {
                                        echo '<span style="color: var(--medium-gray);">-</span>';
                                    }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <!-- ===========================================
             MENSAGEM SEM RESULTADOS
             =========================================== -->
        <div class="no-results">
            <i class="fas fa-check-circle"></i>
            <p>Nenhuma conta paga encontrada.</p>
            <small>Tente ajustar os filtros ou verifique se há pagamentos registrados.</small>
        </div>
    <?php endif; ?>
</div>

<!-- ===========================================
     MODAL DE DETALHES DA CONTA PAGA
     =========================================== -->
<div id="contaModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-check-circle"></i> 
                Detalhes da Conta
            </h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="loading-spinner" style="text-align: center; padding: 3rem;">
                <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--success-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da conta...</p>
            </div>
        </div>
    </div>
</div>

<script>
// ===========================================
// SISTEMA COMPLETO DE CONTAS PAGAS
// JavaScript Corrigido com Debug Avançado - v9.0
// ===========================================

// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let currentContaId = null;
let currentContaData = null;

// ===========================================
// FUNÇÕES DE CONTROLE DO MODAL
// ===========================================

/**
 * Abre o modal com detalhes da conta
 * CORREÇÃO FINAL: Debug avançado para identificar problemas
 * @param {number} contaId - ID da conta
 */
function openModal(contaId) {
    console.log('🔍 Abrindo modal para conta ID:', contaId);
    
    currentContaId = contaId;
    const modal = document.getElementById('contaModal');
    const modalBody = modal.querySelector('.modal-body');
    
    // Mostra o modal
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Mostra loading
    modalBody.innerHTML = `
        <div class="loading-spinner" style="text-align: center; padding: 3rem;">
            <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--success-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
            <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da conta...</p>
        </div>
    `;
    
    // CORREÇÃO: URL com parâmetros de cache-busting e debug
    const baseUrl = window.location.pathname;
    const url = `${baseUrl}?get_conta_id=${contaId}&_t=${Date.now()}&debug=1`;
    console.log('📡 URL da requisição:', url);
    
    fetch(url, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        cache: 'no-cache'
    })
        .then(response => {
            console.log('📡 Resposta recebida:');
            console.log('  - Status:', response.status, response.statusText);
            console.log('  - Headers:', Object.fromEntries(response.headers.entries()));
            
            // CORREÇÃO: Verifica content-type de forma mais robusta
            const contentType = response.headers.get('content-type') || '';
            console.log('  - Content-Type:', contentType);
            
            if (!contentType.includes('application/json')) {
                console.error('❌ Content-Type não é JSON:', contentType);
                
                // Lê o conteúdo como texto para debug
                return response.text().then(text => {
                    console.error('❌ Conteúdo recebido (primeiros 1000 chars):');
                    console.error(text.substring(0, 1000));
                    
                    // Tenta detectar se é HTML
                    if (text.includes('<!DOCTYPE') || text.includes('<html')) {
                        throw new Error('Resposta é uma página HTML. Verifique se não há redirecionamento ou erro no PHP.');
                    } else if (text.includes('Fatal error') || text.includes('Parse error')) {
                        throw new Error('Erro fatal no PHP: ' + text.substring(0, 200));
                    } else if (text.includes('Warning:') || text.includes('Notice:')) {
                        throw new Error('Warning/Notice no PHP: ' + text.substring(0, 200));
                    } else {
                        throw new Error('Resposta não é JSON válido. Conteúdo: ' + text.substring(0, 100));
                    }
                });
            }
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return response.json();
        })
        .then(data => {
            console.log('✅ Dados JSON recebidos:', data);
            
            if (data.error) {
                throw new Error(data.error + (data.debug ? ' | Debug: ' + JSON.stringify(data.debug) : ''));
            }
            
            if (!data.success) {
                throw new Error('Resposta indica falha: ' + JSON.stringify(data));
            }
            
            if (!data.conta) {
                throw new Error('Dados da conta não encontrados na resposta');
            }
            
            currentContaData = data;
            renderContaDetails(data);
            
            console.log('✅ Modal renderizado com sucesso para conta:', data.conta.numero_nf);
        })
        .catch(error => {
            console.error('❌ Erro completo:', error);
            
            modalBody.innerHTML = `
                <div class="error-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Erro ao carregar conta</h3>
                    <p><strong>Detalhes do erro:</strong><br>${error.message}</p>
                    
                    <div class="debug-info">
                        <strong>Debug Info:</strong><br>
                        • ID da conta: ${contaId}<br>
                        • URL: ${url}<br>
                        • Timestamp: ${new Date().toLocaleString()}<br>
                        • User Agent: ${navigator.userAgent.substring(0, 100)}...
                    </div>
                    
                    <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; margin-top: 2rem;">
                        <button class="btn btn-primary" onclick="openModal(${contaId})" style="margin: 0;">
                            <i class="fas fa-redo"></i> Tentar Novamente
                        </button>
                        <button class="btn btn-secondary" onclick="closeModal()" style="margin: 0;">
                            <i class="fas fa-times"></i> Fechar
                        </button>
                        <button class="btn btn-secondary" onclick="debugEndpoint(${contaId})" style="margin: 0;">
                            <i class="fas fa-bug"></i> Debug Avançado
                        </button>
                    </div>
                </div>
            `;
        });
}

/**
 * Função de debug avançado
 */
function debugEndpoint(contaId) {
    console.log('🐛 Iniciando debug avançado...');
    
    const debugUrl = `${window.location.pathname}?get_conta_id=${contaId}&debug=1&_t=${Date.now()}`;
    
    // Teste 1: Fetch básico
    console.log('🧪 Teste 1: Fetch simples');
    fetch(debugUrl)
        .then(response => response.text())
        .then(text => {
            console.log('📝 Resposta em texto bruto:');
            console.log(text);
            
            // Análise do conteúdo
            if (text.includes('{"success"') || text.includes('{"error"')) {
                console.log('✅ Parece ser JSON válido');
                try {
                    const parsed = JSON.parse(text);
                    console.log('✅ JSON parseado com sucesso:', parsed);
                } catch (e) {
                    console.log('❌ Erro ao parsear JSON:', e.message);
                }
            } else {
                console.log('❌ Não parece ser JSON');
                console.log('Primeiros 500 caracteres:', text.substring(0, 500));
            }
        })
        .catch(error => {
            console.error('❌ Erro no debug:', error);
        });
    
    // Teste 2: Verificar se existe endpoint
    console.log('🧪 Teste 2: Verificando página base');
    fetch(window.location.pathname)
        .then(response => {
            console.log('📄 Página base - Status:', response.status);
            console.log('📄 Página base - Headers:', Object.fromEntries(response.headers.entries()));
        });
}

/**
 * Renderiza os detalhes completos da conta no modal
 * @param {Object} data - Dados da conta
 */
function renderContaDetails(data) {
    console.log('🎨 Renderizando detalhes da conta:', data);
    
    const modalBody = document.querySelector('#contaModal .modal-body');
    const conta = data.conta;
    const produtos = data.produtos || [];
    
    // Prepara datas
    const dataCompra = conta.data ? new Date(conta.data).toLocaleDateString('pt-BR') : 'N/A';
    const dataPagamento = conta.data_pagamento ? new Date(conta.data_pagamento).toLocaleDateString('pt-BR') : null;
    
    // Determina se é uma conta paga ou a pagar baseado no status
    const isPaid = ['Pago', 'Concluido'].includes(conta.status_pagamento);
    const statusColor = getStatusColor(conta.status_pagamento);
    const statusIcon = getStatusIcon(conta.status_pagamento);
    
    modalBody.innerHTML = `
        <div class="conta-details">
            <!-- Debug Info (só aparece se tiver debug) -->
            
            
            <!-- Informações da Compra -->
            <div class="detail-section">
                <div class="detail-header">
                    <i class="fas fa-shopping-cart"></i>
                    Informações da Compra
                </div>
                <div class="detail-content">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Número da NF</div>
                            <div class="detail-value highlight">${conta.numero_nf || 'N/A'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Fornecedor</div>
                            <div class="detail-value highlight">${conta.fornecedor || 'N/A'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Data da Compra</div>
                            <div class="detail-value">${dataCompra}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Valor Total</div>
                            <div class="detail-value money">R$ ${parseFloat(conta.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Número de Empenho</div>
                            <div class="detail-value">${conta.numero_empenho || 'N/A'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Frete</div>
                            <div class="detail-value">${conta.frete ? 'R$ ' + parseFloat(conta.frete).toLocaleString('pt-BR', {minimumFractionDigits: 2}) : 'N/A'}</div>
                        </div>
                    </div>
                    
                    ${conta.observacao_compra ? `
                    <div style="margin-top: 1.5rem;">
                        <div class="detail-label">Observação da Compra</div>
                        <div class="detail-value">${conta.observacao_compra}</div>
                    </div>
                    ` : ''}
                    
                    ${conta.link_pagamento ? `
                    <div style="margin-top: 1.5rem;">
                        <div class="detail-label">Link para Pagamento</div>
                        <div class="detail-value">
                            <a href="${conta.link_pagamento}" target="_blank" class="comprovante-link">
                                <i class="fas fa-external-link-alt"></i>
                                Acessar Link de Pagamento
                            </a>
                        </div>
                    </div>
                    ` : ''}
                </div>
            </div>

            <!-- Produtos da Compra -->
            ${produtos.length > 0 ? `
            <div class="detail-section">
                <div class="detail-header">
                    <i class="fas fa-box"></i>
                    Produtos da Compra (${produtos.length})
                </div>
                <div class="detail-content">
                    <div class="produtos-section">
                        ${produtos.map((produto, index) => `
                            <div class="produto-item">
                                <div class="produto-header">
                                    <div class="produto-title">Produto ${index + 1}</div>
                                </div>
                                
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <div class="detail-label">Nome do Produto</div>
                                        <div class="detail-value">${produto.produto_nome || produto.nome || 'Nome não informado'}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Quantidade</div>
                                        <div class="detail-value">${produto.quantidade || 0}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Valor Unitário</div>
                                        <div class="detail-value money">R$ ${parseFloat(produto.valor_unitario || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Valor Total</div>
                                        <div class="detail-value money">R$ ${parseFloat(produto.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            </div>
            ` : ''}

            <!-- Informações de Pagamento -->
            <div class="detail-section">
                <div class="detail-header">
                    <i class="fas fa-${statusIcon}" style="color: ${statusColor};"></i>
                    ${isPaid ? 'Informações de Pagamento Realizadas' : 'Status de Pagamento'}
                </div>
                <div class="detail-content">
                    <div class="status-section" style="background: ${getStatusBgColor(conta.status_pagamento)}; border-left: 4px solid ${statusColor};">
                        <h4><i class="fas fa-${statusIcon}" style="color: ${statusColor};"></i> Status do Pagamento</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Status Atual</div>
                                <div class="detail-value">
                                    <span class="status-badge ${conta.status_pagamento.toLowerCase()}" style="background: ${getStatusBgColor(conta.status_pagamento)}; color: ${statusColor}; border: 1px solid ${statusColor};">
                                        <i class="fas fa-${statusIcon}"></i>
                                        ${conta.status_pagamento}
                                    </span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data de Pagamento</div>
                                <div class="detail-value ${isPaid ? 'highlight' : ''}">${dataPagamento || (isPaid ? 'Não informada' : 'Não pago')}</div>
                            </div>
                        </div>
                        
                        ${conta.observacao_pagamento ? `
                        <div style="margin-top: 1.5rem;">
                            <div class="detail-label">Observação do Pagamento</div>
                            <div class="detail-value">${conta.observacao_pagamento}</div>
                        </div>
                        ` : ''}
                        
                        ${conta.comprovante_pagamento ? `
                        <div class="comprovante-container" style="margin-top: 1.5rem;">
                            <div class="detail-label">Comprovante de Pagamento</div>
                            <a href="${conta.comprovante_pagamento}" class="comprovante-link" target="_blank">
                                <i class="fas fa-file-alt"></i>
                                Ver Comprovante
                            </a>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="btn-container">
                        <button class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Fechar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    console.log('✅ Detalhes da conta renderizados com sucesso');
}

/**
 * Funções auxiliares para status
 */
function getStatusColor(status) {
    const colors = {
        'Pendente': '#fd7e14',
        'Pago': '#28a745',
        'Concluido': '#17a2b8'
    };
    return colors[status] || '#6c757d';
}

function getStatusIcon(status) {
    const icons = {
        'Pendente': 'clock',
        'Pago': 'check-circle',
        'Concluido': 'check-double'
    };
    return icons[status] || 'tag';
}

function getStatusBgColor(status) {
    const bgColors = {
        'Pendente': 'rgba(253, 126, 20, 0.1)',
        'Pago': 'rgba(40, 167, 69, 0.1)',
        'Concluido': 'rgba(23, 162, 184, 0.1)'
    };
    return bgColors[status] || 'rgba(108, 117, 125, 0.1)';
}

/**
 * Fecha o modal
 */
function closeModal() {
    const modal = document.getElementById('contaModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Limpa dados
    currentContaId = null;
    currentContaData = null;
    
    console.log('✅ Modal fechado');
}

// ===========================================
// INICIALIZAÇÃO E EVENT LISTENERS
// ===========================================

document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 LicitaSis - Sistema de Contas Pagas v9.0 carregado!');
    console.log('🔧 Versão com debug avançado para resolução de problemas');
    console.log('📊 Total de contas na página: <?php echo count($contas); ?>');
    
    // Event listener para fechar modal com ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const modal = document.getElementById('contaModal');
            if (modal && modal.style.display === 'block') {
                closeModal();
            }
        }
    });
    
    // Event listener para clicar fora do modal
    const modal = document.getElementById('contaModal');
    if (modal) {
        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    }
    
    // Auto-submit do formulário de pesquisa com delay
    const searchInput = document.getElementById('search');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const form = this.closest('form');
                if (form) form.submit();
            }, 800);
        });
    }
    
    // Adiciona hover effects nos cards de resumo
    const summaryCards = document.querySelectorAll('.summary-card');
    summaryCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-8px) scale(1.02)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });
    
    console.log('✅ Sistema inicializado com sucesso');
});

console.log('🎯 Sistema de Contas Pagas v9.0 - Pronto para debug e resolução de problemas!');
</script>

</body>
</html>

<?php
// ===========================================
// LOG DE DEPURAÇÃO E INFORMAÇÕES
// ===========================================
error_log("=== CONTAS PAGAS v9.0 - DEBUG VERSION ===");
error_log("✅ Endpoint AJAX isolado no início do arquivo");
error_log("✅ Headers JSON forçados com ob_clean()"); 
error_log("✅ Query SQL simplificada sem restrições");
error_log("✅ Validação robusta de entrada");
error_log("✅ Tratamento de erros completo");
error_log("✅ Debug avançado no JavaScript");
error_log("✅ Total de contas carregadas: " . count($contas));
error_log("✅ Sistema de depuração ativo");
error_log("===========================================");
?>