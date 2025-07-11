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
$movimentacoes = [];
$searchTerm = "";
$filterType = "";
$filterPeriod = "";

// Conexão com o banco de dados
require_once('db.php');

// Função para buscar movimentações de entrada (contas recebidas)
function buscarEntradasCaixa($pdo, $searchTerm = "", $filterPeriod = "") {
    try {
        $sql = "SELECT 
                    'entrada' as tipo_movimentacao,
                    v.id as referencia_id,
                    v.nf as documento,
                    c.nome_orgaos as descricao,
                    v.valor_total as valor,
                    v.data_vencimento as data_movimentacao,
                    'Venda - NF' as categoria,
                    v.status_pagamento
                FROM vendas v
                LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
                WHERE v.status_pagamento = 'Recebido'";
        
        $params = [];
        
        if (!empty($searchTerm)) {
            $sql .= " AND (v.nf LIKE :searchTerm OR c.nome_orgaos LIKE :searchTerm)";
            $params[':searchTerm'] = "%$searchTerm%";
        }
        
        if (!empty($filterPeriod)) {
            switch($filterPeriod) {
                case 'hoje':
                    $sql .= " AND DATE(v.data_vencimento) = CURDATE()";
                    break;
                case 'semana':
                    $sql .= " AND v.data_vencimento >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                    break;
                case 'mes':
                    $sql .= " AND v.data_vencimento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                    break;
                case 'trimestre':
                    $sql .= " AND v.data_vencimento >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
                    break;
            }
        }
        
        $sql .= " ORDER BY v.data_vencimento DESC";
        
        $stmt = $pdo->prepare($sql);
        foreach($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Função para buscar movimentações de saída (contas pagas)
function buscarSaidasCaixa($pdo, $searchTerm = "", $filterPeriod = "") {
    try {
        $sql = "SELECT 
                    'saida' as tipo_movimentacao,
                    cp.id as referencia_id,
                    c.numero_nf as documento,
                    c.fornecedor as descricao,
                    c.valor_total as valor,
                    cp.data_pagamento as data_movimentacao,
                    'Compra - NF' as categoria,
                    cp.status_pagamento
                FROM contas_pagar cp
                INNER JOIN compras c ON cp.compra_id = c.id
                WHERE cp.status_pagamento IN ('Pago', 'Concluido')";
        
        $params = [];
        
        if (!empty($searchTerm)) {
            $sql .= " AND (c.numero_nf LIKE :searchTerm OR c.fornecedor LIKE :searchTerm)";
            $params[':searchTerm'] = "%$searchTerm%";
        }
        
        if (!empty($filterPeriod)) {
            switch($filterPeriod) {
                case 'hoje':
                    $sql .= " AND DATE(cp.data_pagamento) = CURDATE()";
                    break;
                case 'semana':
                    $sql .= " AND cp.data_pagamento >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                    break;
                case 'mes':
                    $sql .= " AND cp.data_pagamento >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                    break;
                case 'trimestre':
                    $sql .= " AND cp.data_pagamento >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
                    break;
            }
        }
        
        $sql .= " ORDER BY cp.data_pagamento DESC";
        
        $stmt = $pdo->prepare($sql);
        foreach($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Processa filtros de pesquisa
if (isset($_GET['search'])) {
    $searchTerm = $_GET['search'];
}
if (isset($_GET['filter_type'])) {
    $filterType = $_GET['filter_type'];
}
if (isset($_GET['filter_period'])) {
    $filterPeriod = $_GET['filter_period'];
}

// Buscar movimentações baseadas nos filtros
$entradas = buscarEntradasCaixa($pdo, $searchTerm, $filterPeriod);
$saidas = buscarSaidasCaixa($pdo, $searchTerm, $filterPeriod);

// Combinar e ordenar todas as movimentações
$movimentacoes = array_merge($entradas, $saidas);

// Filtrar por tipo se especificado
if (!empty($filterType)) {
    $movimentacoes = array_filter($movimentacoes, function($mov) use ($filterType) {
        return $mov['tipo_movimentacao'] === $filterType;
    });
}

// Ordenar por data (mais recentes primeiro)
usort($movimentacoes, function($a, $b) {
    return strtotime($b['data_movimentacao']) - strtotime($a['data_movimentacao']);
});

// Calcular totais
try {
    // Total de entradas (contas recebidas)
    $sqlTotalEntradas = "SELECT SUM(valor_total) AS total_entradas 
                         FROM vendas 
                         WHERE status_pagamento = 'Recebido'";
    $stmtEntradas = $pdo->prepare($sqlTotalEntradas);
    $stmtEntradas->execute();
    $totalEntradas = $stmtEntradas->fetch(PDO::FETCH_ASSOC)['total_entradas'] ?? 0;
    
    // Total de saídas (contas pagas)
    $sqlTotalSaidas = "SELECT SUM(c.valor_total) AS total_saidas 
                       FROM contas_pagar cp 
                       INNER JOIN compras c ON cp.compra_id = c.id 
                       WHERE cp.status_pagamento IN ('Pago', 'Concluido')";
    $stmtSaidas = $pdo->prepare($sqlTotalSaidas);
    $stmtSaidas->execute();
    $totalSaidas = $stmtSaidas->fetch(PDO::FETCH_ASSOC)['total_saidas'] ?? 0;
    
    // Saldo atual do caixa
    $saldoAtual = $totalEntradas - $totalSaidas;
    
    // Contadores
    $totalMovimentacoes = count($movimentacoes);
    $totalMovEntradas = count($entradas);
    $totalMovSaidas = count($saidas);
    
} catch (PDOException $e) {
    $error = "Erro ao calcular totais: " . $e->getMessage();
}

// Endpoint AJAX para buscar detalhes de uma movimentação
if (isset($_GET['get_movimentacao_details'])) {
    $tipo = $_GET['tipo'] ?? '';
    $id = intval($_GET['id'] ?? 0);
    
    if ($tipo === 'entrada' && $id > 0) {
        try {
            $sql = "SELECT v.*, c.nome_orgaos as cliente_nome, c.endereco, c.telefone, c.email,
                           t.nome as transportadora_nome, e.numero as empenho_numero
                    FROM vendas v
                    LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
                    LEFT JOIN transportadora t ON v.transportadora = t.id
                    LEFT JOIN empenhos e ON v.empenho_id = e.id
                    WHERE v.id = :id AND v.status_pagamento = 'Recebido'";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $movimentacao = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($movimentacao) {
                echo json_encode($movimentacao);
            } else {
                echo json_encode(['error' => 'Movimentação não encontrada']);
            }
        } catch (PDOException $e) {
            echo json_encode(['error' => 'Erro ao buscar detalhes: ' . $e->getMessage()]);
        }
    } elseif ($tipo === 'saida' && $id > 0) {
        try {
            $sql = "SELECT cp.*, c.fornecedor, c.numero_nf, c.valor_total, c.data, c.numero_empenho,
                           c.link_pagamento, c.comprovante_pagamento, c.observacao as observacao_compra
                    FROM contas_pagar cp
                    INNER JOIN compras c ON cp.compra_id = c.id
                    WHERE cp.id = :id AND cp.status_pagamento IN ('Pago', 'Concluido')";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $movimentacao = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($movimentacao) {
                echo json_encode($movimentacao);
            } else {
                echo json_encode(['error' => 'Movimentação não encontrada']);
            }
        } catch (PDOException $e) {
            echo json_encode(['error' => 'Erro ao buscar detalhes: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['error' => 'Parâmetros inválidos']);
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caixa - LicitaSis</title>
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
            max-width: 1400px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .container:hover {
            box-shadow: var(--shadow-hover);
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

        /* Mensagens de feedback */
        .error, .success {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            font-weight: 500;
            text-align: center;
            animation: slideInDown 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .error {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border-left: 4px solid var(--danger-color);
        }

        .success {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        @keyframes slideInDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Cards de resumo do caixa */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            border-radius: var(--radius);
            padding: 2rem;
            text-align: center;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border-left: 5px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, transparent 0%, rgba(255,255,255,0.1) 50%, transparent 100%);
            transform: translateX(-100%);
            transition: transform 0.6s ease;
        }

        .summary-card:hover::before {
            transform: translateX(100%);
        }

        .summary-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-hover);
        }

        .summary-card.saldo-positivo {
            border-left-color: var(--success-color);
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
        }

        .summary-card.saldo-negativo {
            border-left-color: var(--danger-color);
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
        }

        .summary-card.entradas {
            border-left-color: var(--info-color);
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
        }

        .summary-card.saidas {
            border-left-color: var(--warning-color);
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
        }

        .summary-card.movimentacoes {
            border-left-color: var(--secondary-color);
            background: linear-gradient(135deg, #d7f4f0 0%, #a7f3d0 100%);
        }

        .summary-card h4 {
            font-size: 0.9rem;
            color: var(--medium-gray);
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .summary-card .value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .summary-card .count-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .summary-card .subtitle {
            font-size: 0.85rem;
            color: var(--medium-gray);
            font-weight: 500;
        }

        /* Filtros e pesquisa */
        .filters-container {
            background: var(--light-gray);
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }

        .filters-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .filter-group input,
        .filter-group select {
            padding: 0.875rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background: white;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        }

        .btn-filter {
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
            gap: 0.5rem;
            height: fit-content;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        .btn-filter:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        /* Tabela de movimentações */
        .table-container {
            overflow-x: auto;
            margin-bottom: 2rem;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow);
            background: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        table th, table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        table th {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        table tr:hover {
            background: var(--light-gray);
        }

        table a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        table a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        /* Badges de tipo de movimentação */
        .tipo-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .tipo-entrada {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(40, 167, 69, 0.2);
        }

        .tipo-saida {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.2);
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
            margin: 3% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 90%;
            max-width: 900px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideInUp 0.3s ease;
            overflow: hidden;
            max-height: 90vh;
            overflow-y: auto;
        }

        @keyframes slideInUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
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

        /* Formulário do modal */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-row-three {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
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
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        }

        input[readonly] {
            background: var(--light-gray);
            color: var(--medium-gray);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* Seção de detalhes */
        .details-section {
            background: var(--light-gray);
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            margin: 1.5rem 0;
            border-left: 4px solid var(--secondary-color);
        }

        .details-section h4 {
            color: var(--primary-color);
            margin-bottom: 1rem;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Estados vazios */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            margin: 2rem 0;
            background: var(--light-gray);
            border-radius: var(--radius);
            border: 2px dashed var(--border-color);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--medium-gray);
            margin-bottom: 1rem;
            display: block;
        }

        .empty-state h3 {
            color: var(--medium-gray);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--medium-gray);
            font-size: 0.95rem;
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 1rem;
                padding: 1.5rem;
            }
            
            .summary-cards {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
            
            .filters-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }

        @media (max-width: 768px) {
            .logo {
                max-width: 120px;
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

            .summary-cards {
                grid-template-columns: 1fr;
            }

            .form-row,
            .form-row-three {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .filters-row {
                grid-template-columns: 1fr;
            }

            .table-container {
                font-size: 0.85rem;
            }

            table th, table td {
                padding: 0.75rem 0.5rem;
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

            .summary-card {
                padding: 1.5rem;
            }

            .summary-card .value,
            .summary-card .count-value {
                font-size: 1.5rem;
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

            .btn-filter {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
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
    <h2>Controle de Caixa</h2>

    <?php if ($error): ?>
        <div class="error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- Cards de resumo do caixa -->
    <div class="summary-cards">
        <div class="summary-card <?php echo $saldoAtual >= 0 ? 'saldo-positivo' : 'saldo-negativo'; ?>">
            <h4>Saldo Atual do Caixa</h4>
            <div class="value">
                <?php if ($saldoAtual >= 0): ?>
                    <i class="fas fa-arrow-up" style="color: var(--success-color);"></i>
                <?php else: ?>
                    <i class="fas fa-arrow-down" style="color: var(--danger-color);"></i>
                <?php endif; ?>
                R$ <?php echo number_format(abs($saldoAtual), 2, ',', '.'); ?>
            </div>
            <div class="subtitle">
                <?php echo $saldoAtual >= 0 ? 'Saldo Positivo' : 'Saldo Negativo'; ?>
            </div>
        </div>
        
        <div class="summary-card entradas">
            <h4>Total de Entradas</h4>
            <div class="value">
                <i class="fas fa-plus-circle" style="color: var(--success-color);"></i>
                R$ <?php echo number_format($totalEntradas, 2, ',', '.'); ?>
            </div>
            <div class="subtitle"><?php echo $totalMovEntradas; ?> movimentação(ões)</div>
        </div>
        
        <div class="summary-card saidas">
            <h4>Total de Saídas</h4>
            <div class="value">
                <i class="fas fa-minus-circle" style="color: var(--danger-color);"></i>
                R$ <?php echo number_format($totalSaidas, 2, ',', '.'); ?>
            </div>
            <div class="subtitle"><?php echo $totalMovSaidas; ?> movimentação(ões)</div>
        </div>
        
        <div class="summary-card movimentacoes">
            <h4>Total de Movimentações</h4>
            <div class="count-value">
                <i class="fas fa-exchange-alt" style="color: var(--secondary-color);"></i>
                <?php echo $totalMovimentacoes; ?>
            </div>
            <div class="subtitle">Entradas + Saídas</div>
        </div>
    </div>

    <!-- Filtros de pesquisa -->
    <div class="filters-container">
        <form action="caixa.php" method="GET">
            <div class="filters-row">
                <div class="filter-group">
                    <label for="search">Pesquisar</label>
                    <input type="text" name="search" id="search" 
                           placeholder="Pesquisar por documento, descrição..." 
                           value="<?php echo htmlspecialchars($searchTerm); ?>">
                </div>
                
                <div class="filter-group">
                    <label for="filter_type">Tipo</label>
                    <select name="filter_type" id="filter_type">
                        <option value="">Todos</option>
                        <option value="entrada" <?php echo $filterType === 'entrada' ? 'selected' : ''; ?>>Entradas</option>
                        <option value="saida" <?php echo $filterType === 'saida' ? 'selected' : ''; ?>>Saídas</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filter_period">Período</label>
                    <select name="filter_period" id="filter_period">
                        <option value="">Todos</option>
                        <option value="hoje" <?php echo $filterPeriod === 'hoje' ? 'selected' : ''; ?>>Hoje</option>
                        <option value="semana" <?php echo $filterPeriod === 'semana' ? 'selected' : ''; ?>>Última Semana</option>
                        <option value="mes" <?php echo $filterPeriod === 'mes' ? 'selected' : ''; ?>>Último Mês</option>
                        <option value="trimestre" <?php echo $filterPeriod === 'trimestre' ? 'selected' : ''; ?>>Último Trimestre</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-filter"></i> Filtrar
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Tabela de movimentações -->
    <?php if (count($movimentacoes) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Documento</th>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Valor</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movimentacoes as $movimentacao): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($movimentacao['data_movimentacao'])); ?></td>
                            <td>
                                <span class="tipo-badge tipo-<?php echo $movimentacao['tipo_movimentacao']; ?>">
                                    <?php if ($movimentacao['tipo_movimentacao'] === 'entrada'): ?>
                                        <i class="fas fa-arrow-up"></i> Entrada
                                    <?php else: ?>
                                        <i class="fas fa-arrow-down"></i> Saída
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <a href="javascript:void(0);" onclick="openModal('<?php echo $movimentacao['tipo_movimentacao']; ?>', <?php echo $movimentacao['referencia_id']; ?>)">
                                    <?php echo htmlspecialchars($movimentacao['documento']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($movimentacao['descricao']); ?></td>
                            <td><?php echo htmlspecialchars($movimentacao['categoria']); ?></td>
                            <td style="font-weight: 600; color: <?php echo $movimentacao['tipo_movimentacao'] === 'entrada' ? 'var(--success-color)' : 'var(--danger-color)'; ?>">
                                <?php echo $movimentacao['tipo_movimentacao'] === 'entrada' ? '+' : '-'; ?> 
                                R$ <?php echo number_format($movimentacao['valor'], 2, ',', '.'); ?>
                            </td>
                            <td>
                                <button type="button" onclick="openModal('<?php echo $movimentacao['tipo_movimentacao']; ?>', <?php echo $movimentacao['referencia_id']; ?>)" 
                                        style="background: var(--info-color); color: white; border: none; padding: 0.5rem 1rem; border-radius: var(--radius-sm); cursor: pointer; transition: var(--transition);">
                                    <i class="fas fa-eye"></i> Ver Detalhes
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-cash-register"></i>
            <h3>Nenhuma movimentação encontrada</h3>
            <p>Não há movimentações de caixa que correspondam aos filtros aplicados.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Modal de Detalhes da Movimentação -->
<div id="movimentacaoModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modal-title">Detalhes da Movimentação</h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div id="modal-content-container">
                <!-- Conteúdo será carregado dinamicamente -->
            </div>
        </div>
    </div>
</div>

<script>
    // Função para abrir o modal e carregar os dados da movimentação
    function openModal(tipo, id) {
        document.getElementById('movimentacaoModal').style.display = 'block';
        document.getElementById('modal-content-container').innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--primary-color);"></i><p style="margin-top: 1rem;">Carregando detalhes...</p></div>';
        
        // Busca os dados da movimentação
        fetch(`caixa.php?get_movimentacao_details=1&tipo=${tipo}&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('modal-content-container').innerHTML = `
                        <div class="error" style="margin: 0;">
                            <i class="fas fa-exclamation-circle"></i>
                            ${data.error}
                        </div>
                    `;
                    return;
                }
                
                if (tipo === 'entrada') {
                    renderEntradaDetails(data);
                } else {
                    renderSaidaDetails(data);
                }
            })
            .catch(error => {
                console.error('Erro ao buscar detalhes:', error);
                document.getElementById('modal-content-container').innerHTML = `
                    <div class="error" style="margin: 0;">
                        <i class="fas fa-exclamation-circle"></i>
                        Erro ao carregar os detalhes da movimentação.
                    </div>
                `;
            });
    }
    
    // Função para renderizar detalhes de entrada (venda)
    function renderEntradaDetails(data) {
        document.getElementById('modal-title').innerHTML = '<i class="fas fa-arrow-up" style="color: var(--success-color);"></i> Detalhes da Entrada';
        
        const modalContent = `
            <div class="details-section">
                <h4><i class="fas fa-file-invoice"></i> Informações da Venda</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Nota Fiscal:</label>
                        <input type="text" value="${data.nf || ''}" readonly>
                    </div>
                    <div class="form-group">
                        <label>UASG do Cliente:</label>
                        <input type="text" value="${data.cliente_uasg || ''}" readonly>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Nome do Cliente:</label>
                        <input type="text" value="${data.cliente_nome || ''}" readonly>
                    </div>
                    <div class="form-group">
                        <label>Valor Total:</label>
                        <input type="text" value="R$ ${parseFloat(data.valor_total || 0).toFixed(2).replace('.', ',')}" readonly>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Data de Vencimento:</label>
                        <input type="text" value="${data.data_vencimento ? new Date(data.data_vencimento).toLocaleDateString('pt-BR') : ''}" readonly>
                    </div>
                    <div class="form-group">
                        <label>Status de Pagamento:</label>
                        <input type="text" value="${data.status_pagamento || ''}" readonly style="background: rgba(40, 167, 69, 0.1); color: var(--success-color); font-weight: 600;">
                    </div>
                </div>
                
                ${data.empenho_numero ? `
                <div class="form-group">
                    <label>Número do Empenho:</label>
                    <input type="text" value="${data.empenho_numero}" readonly>
                </div>
                ` : ''}
                
                ${data.transportadora_nome ? `
                <div class="form-group">
                    <label>Transportadora:</label>
                    <input type="text" value="${data.transportadora_nome}" readonly>
                </div>
                ` : ''}
                
                ${data.observacao ? `
                <div class="form-group">
                    <label>Observações:</label>
                    <textarea readonly>${data.observacao}</textarea>
                </div>
                ` : ''}
            </div>
        `;
        
        document.getElementById('modal-content-container').innerHTML = modalContent;
    }
    
    // Função para renderizar detalhes de saída (compra)
    function renderSaidaDetails(data) {
        document.getElementById('modal-title').innerHTML = '<i class="fas fa-arrow-down" style="color: var(--danger-color);"></i> Detalhes da Saída';
        
        const modalContent = `
            <div class="details-section">
                <h4><i class="fas fa-shopping-cart"></i> Informações da Compra</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Número da NF:</label>
                        <input type="text" value="${data.numero_nf || ''}" readonly>
                    </div>
                    <div class="form-group">
                        <label>Fornecedor:</label>
                        <input type="text" value="${data.fornecedor || ''}" readonly>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Data da Compra:</label>
                        <input type="text" value="${data.data ? new Date(data.data).toLocaleDateString('pt-BR') : ''}" readonly>
                    </div>
                    <div class="form-group">
                        <label>Valor Total:</label>
                        <input type="text" value="R$ ${parseFloat(data.valor_total || 0).toFixed(2).replace('.', ',')}" readonly>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Data de Pagamento:</label>
                        <input type="text" value="${data.data_pagamento ? new Date(data.data_pagamento).toLocaleDateString('pt-BR') : ''}" readonly>
                    </div>
                    <div class="form-group">
                        <label>Status de Pagamento:</label>
                        <input type="text" value="${data.status_pagamento || ''}" readonly style="background: rgba(220, 53, 69, 0.1); color: var(--danger-color); font-weight: 600;">
                    </div>
                </div>
                
                ${data.numero_empenho ? `
                <div class="form-group">
                    <label>Número do Empenho:</label>
                    <input type="text" value="${data.numero_empenho}" readonly>
                </div>
                ` : ''}
                
                ${data.link_pagamento ? `
                <div class="form-group">
                    <label>Link para Pagamento:</label>
                    <input type="url" value="${data.link_pagamento}" readonly>
                </div>
                ` : ''}
                
                ${data.comprovante_pagamento ? `
                <div class="form-group">
                    <label>Comprovante de Pagamento:</label>
                    <div style="margin-top: 0.5rem;">
                        <a href="${data.comprovante_pagamento}" target="_blank" style="display: inline-block; padding: 0.5rem 1rem; background: var(--info-color); color: white; text-decoration: none; border-radius: var(--radius-sm); transition: var(--transition);">
                            <i class="fas fa-file-alt"></i> Ver Comprovante
                        </a>
                    </div>
                </div>
                ` : ''}
                
                ${data.observacao_compra ? `
                <div class="form-group">
                    <label>Observações da Compra:</label>
                    <textarea readonly>${data.observacao_compra}</textarea>
                </div>
                ` : ''}
                
                ${data.observacao_pagamento ? `
                <div class="form-group">
                    <label>Observações do Pagamento:</label>
                    <textarea readonly>${data.observacao_pagamento}</textarea>
                </div>
                ` : ''}
            </div>
        `;
        
        document.getElementById('modal-content-container').innerHTML = modalContent;
    }
    
    // Função para fechar o modal
    function closeModal() {
        document.getElementById('movimentacaoModal').style.display = 'none';
    }
    
    // Fecha o modal ao clicar fora dele
    window.onclick = function(event) {
        const modal = document.getElementById('movimentacaoModal');
        if (event.target === modal) {
            closeModal();
        }
    }
    
    // Fecha o modal ao pressionar a tecla ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
    
    // Adiciona efeitos visuais aos botões
    document.addEventListener('DOMContentLoaded', function() {
        // Efeito hover nos cards de resumo
        const summaryCards = document.querySelectorAll('.summary-card');
        summaryCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });
        
        // Efeito hover nos botões de ação
        const actionButtons = document.querySelectorAll('button[onclick*="openModal"]');
        actionButtons.forEach(button => {
            button.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
                this.style.boxShadow = '0 4px 12px rgba(23, 162, 184, 0.3)';
            });
            
            button.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'none';
            });
        });
    });
</script>

</body>
</html>