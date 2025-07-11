<?php
session_start();
ob_start();

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inclui o sistema de permissões e auditoria
include('db.php');
include('permissions.php');

// Função de auditoria simplificada (caso o sistema de auditoria não esteja disponível)
if (!function_exists('logAudit')) {
    function logAudit($pdo, $userId, $action, $table, $recordId, $newData = null, $oldData = null) {
        try {
            // Verifica se a tabela de auditoria existe
            $checkTable = $pdo->query("SHOW TABLES LIKE 'audit_log'");
            if ($checkTable->rowCount() == 0) {
                // Se não existe tabela de auditoria, apenas loga no error_log
                error_log("AUDIT: User $userId performed $action on $table ID $recordId");
                return;
            }
            
            // Se existe, insere o log
            $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, old_data, new_data, created_at) 
                    VALUES (:user_id, :action, :table_name, :record_id, :old_data, :new_data, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':action' => $action,
                ':table_name' => $table,
                ':record_id' => $recordId,
                ':old_data' => $oldData ? json_encode($oldData) : null,
                ':new_data' => $newData ? json_encode($newData) : null
            ]);
        } catch (Exception $e) {
            // Se falhar, apenas loga no error_log
            error_log("AUDIT ERROR: " . $e->getMessage());
            error_log("AUDIT: User $userId performed $action on $table ID $recordId");
        }
    }
}

$permissionManager = initPermissions($pdo);

// Verifica se o usuário tem permissão para acessar contas a pagar
$permissionManager->requirePermission('financeiro', 'view');

$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";
$contas_a_pagar = [];
$searchTerm = "";
$totalContas = 0;
$contasPorPagina = 20;
$paginaAtual = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($paginaAtual - 1) * $contasPorPagina;

require_once('db.php');

// Criação da tabela contas_pagar se não existir
function criarTabelaContasPagar($pdo) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS contas_pagar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            status_pagamento VARCHAR(20) DEFAULT 'Pendente',
            data_pagamento DATE NULL,
            observacao_pagamento TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_compra_id (compra_id),
            INDEX idx_status (status_pagamento),
            UNIQUE KEY unique_compra (compra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql);
        
        // Sincroniza com compras existentes
        $syncSql = "INSERT IGNORE INTO contas_pagar (compra_id, status_pagamento)
                    SELECT id, 'Pendente' FROM compras
                    WHERE id NOT IN (SELECT compra_id FROM contas_pagar)";
        $pdo->exec($syncSql);
        
        return true;
    } catch (Exception $e) {
        error_log("Erro ao criar/sincronizar tabela contas_pagar: " . $e->getMessage());
        return false;
    }
}

// Cria/sincroniza a tabela
criarTabelaContasPagar($pdo);

// Endpoint AJAX para validar senha do setor financeiro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validate_financial_password'])) {
    $senha_inserida = $_POST['financial_password'] ?? '';
    $senha_padrao = 'Licitasis@2025'; // Senha padrão do setor financeiro
    
    if ($senha_inserida === $senha_padrao) {
        echo json_encode(['success' => true, 'message' => 'Senha validada com sucesso']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Senha incorreta para o setor financeiro']);
    }
    exit();
}

// Endpoint AJAX para atualizar status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && $permissionManager->hasPagePermission('financeiro', 'edit')) {
    $id = intval($_POST['id'] ?? 0);
    $novo_status = $_POST['status_pagamento'] ?? '';
    $data_pagamento = $_POST['data_pagamento'] ?? null;
    $observacao = $_POST['observacao_pagamento'] ?? '';
    $senha_financeiro = $_POST['financial_password'] ?? '';

    if ($id > 0 && in_array($novo_status, ['Pendente', 'Pago', 'Concluido'])) {
        // Se está alterando para "Pago" ou "Concluido", valida a senha do setor financeiro
        if (in_array($novo_status, ['Pago', 'Concluido'])) {
            $senha_padrao = 'Licitasis@2025';
            if ($senha_financeiro !== $senha_padrao) {
                echo json_encode(['success' => false, 'error' => 'Senha do setor financeiro incorreta']);
                exit();
            }
        }

        try {
            // Busca dados antigos para auditoria
            $oldDataStmt = $pdo->prepare("SELECT * FROM contas_pagar WHERE id = :id");
            $oldDataStmt->bindParam(':id', $id);
            $oldDataStmt->execute();
            $oldData = $oldDataStmt->fetch(PDO::FETCH_ASSOC);
            
            // Para status Pendente, limpa a data de pagamento
            if ($novo_status === 'Pendente') {
                $data_pagamento = null;
            } else if (empty($data_pagamento)) {
                // Para outros status, usa a data atual se não fornecida
                $data_pagamento = date('Y-m-d');
            } else {
                // Valida se a data não é futura
                $hoje = date('Y-m-d');
                if ($data_pagamento > $hoje) {
                    echo json_encode(['success' => false, 'error' => 'A data de pagamento não pode ser uma data futura']);
                    exit();
                }
            }
            
            // Atualiza observação para incluir a data
            if ($novo_status !== 'Pendente' && $data_pagamento) {
                $observacao = "Status alterado para $novo_status em " . date('d/m/Y', strtotime($data_pagamento));
                if (!empty($_POST['observacao_pagamento'])) {
                    $observacao = $_POST['observacao_pagamento'];
                }
            }
            
            $sql = "UPDATE contas_pagar SET 
                    status_pagamento = :status, 
                    data_pagamento = :data_pagamento, 
                    observacao_pagamento = :observacao,
                    updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':status', $novo_status);
            $stmt->bindValue(':data_pagamento', $data_pagamento);
            $stmt->bindValue(':observacao', $observacao);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            // Busca dados novos para auditoria
            $newDataStmt = $pdo->prepare("SELECT * FROM contas_pagar WHERE id = :id");
            $newDataStmt->bindParam(':id', $id);
            $newDataStmt->execute();
            $newData = $newDataStmt->fetch(PDO::FETCH_ASSOC);
            
            // Registra auditoria com informação adicional sobre autorização financeira
            $auditInfo = $newData;
            if (in_array($novo_status, ['Pago', 'Concluido'])) {
                $auditInfo['financial_auth'] = 'Autorizado com senha do setor financeiro';
                $auditInfo['authorized_by'] = $_SESSION['user']['id'];
                $auditInfo['authorization_time'] = date('Y-m-d H:i:s');
                $auditInfo['payment_date_set'] = $data_pagamento;
            }
            
            logAudit($pdo, $_SESSION['user']['id'], 'UPDATE', 'contas_pagar', $id, $auditInfo, $oldData);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Status atualizado com sucesso',
                'data_pagamento' => $data_pagamento ? date('d/m/Y', strtotime($data_pagamento)) : null,
                'status' => $novo_status
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    }
    exit();
}

function buscarProdutosCompra($compra_id, $pdo) {
    try {
        $sql = "SELECT pc.*, p.nome 
                FROM produto_compra pc
                JOIN produtos p ON pc.produto_id = p.id
                WHERE pc.compra_id = :compra_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':compra_id', $compra_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Busca com filtro de pesquisa e paginação
try {
    if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
        $searchTerm = trim($_GET['search']);
        
        // Conta total de resultados
        $countSql = "SELECT COUNT(*) as total FROM contas_pagar cp
                     INNER JOIN compras c ON cp.compra_id = c.id
                     WHERE (cp.status_pagamento IN ('Pendente', 'Pago') OR cp.status_pagamento IS NULL)
                     AND (c.numero_nf LIKE :searchTerm OR c.fornecedor LIKE :searchTerm OR cp.status_pagamento LIKE :searchTerm)";
        $countStmt = $pdo->prepare($countSql);
        $searchParam = "%$searchTerm%";
        $countStmt->bindValue(':searchTerm', $searchParam);
        $countStmt->execute();
        $totalContas = $countStmt->fetch()['total'];
        
        // Busca contas com paginação
        $sql = "SELECT cp.id, cp.status_pagamento, cp.data_pagamento, cp.observacao_pagamento,
                       c.id as compra_id, c.numero_nf, c.fornecedor, c.valor_total, c.data, c.numero_empenho,
                       c.link_pagamento, c.comprovante_pagamento, c.observacao, c.frete
                FROM contas_pagar cp
                INNER JOIN compras c ON cp.compra_id = c.id
                WHERE (cp.status_pagamento IN ('Pendente', 'Pago') OR cp.status_pagamento IS NULL)
                AND (c.numero_nf LIKE :searchTerm OR c.fornecedor LIKE :searchTerm OR cp.status_pagamento LIKE :searchTerm)
                ORDER BY cp.status_pagamento ASC, c.data DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', $searchParam);
        $stmt->bindValue(':limit', $contasPorPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $contas_a_pagar = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Conta total de contas a pagar
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM contas_pagar WHERE status_pagamento IN ('Pendente', 'Pago')");
        $totalContas = $countStmt->fetch()['total'];
        
        // Busca todas as contas com paginação
        $sql = "SELECT cp.id, cp.status_pagamento, cp.data_pagamento, cp.observacao_pagamento,
                       c.id as compra_id, c.numero_nf, c.fornecedor, c.valor_total, c.data, c.numero_empenho,
                       c.link_pagamento, c.comprovante_pagamento, c.observacao, c.frete
                FROM contas_pagar cp
                INNER JOIN compras c ON cp.compra_id = c.id
                WHERE cp.status_pagamento IN ('Pendente', 'Pago')
                ORDER BY cp.status_pagamento ASC, c.data DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $contasPorPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $contas_a_pagar = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error = "Erro ao buscar contas a pagar: " . $e->getMessage();
}

// Calcula informações de paginação
$totalPaginas = ceil($totalContas / $contasPorPagina);

// Processa requisição AJAX para dados da conta
if (isset($_GET['get_conta_id'])) {
    $conta_id = intval($_GET['get_conta_id']);
    try {
        $sql = "SELECT cp.*, c.numero_nf, c.fornecedor, c.valor_total, c.data, c.numero_empenho,
                       c.link_pagamento, c.comprovante_pagamento, c.observacao, c.frete
                FROM contas_pagar cp
                INNER JOIN compras c ON cp.compra_id = c.id
                WHERE cp.id = :conta_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':conta_id', $conta_id, PDO::PARAM_INT);
        $stmt->execute();
        $conta = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($conta) {
            $conta['produtos'] = buscarProdutosCompra($conta['compra_id'], $pdo);
        }
        
        echo json_encode($conta);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => "Erro ao buscar detalhes da conta: " . $e->getMessage()]);
        exit();
    }
}

// Calcula totais
try {
    $sqlTotalGeral = "SELECT SUM(c.valor_total) AS total_geral FROM contas_pagar cp
                      INNER JOIN compras c ON cp.compra_id = c.id
                      WHERE cp.status_pagamento IN ('Pendente', 'Pago')";
    $stmtTotalGeral = $pdo->prepare($sqlTotalGeral);
    $stmtTotalGeral->execute();
    $totalGeralPagar = $stmtTotalGeral->fetch(PDO::FETCH_ASSOC)['total_geral'];
    
    $sqlTotalPendente = "SELECT SUM(c.valor_total) AS total_pendente FROM contas_pagar cp
                         INNER JOIN compras c ON cp.compra_id = c.id
                         WHERE cp.status_pagamento = 'Pendente'";
    $stmtTotalPendente = $pdo->prepare($sqlTotalPendente);
    $stmtTotalPendente->execute();
    $totalPendente = $stmtTotalPendente->fetch(PDO::FETCH_ASSOC)['total_pendente'];
    
    $sqlTotalPago = "SELECT SUM(c.valor_total) AS total_pago FROM contas_pagar cp
                     INNER JOIN compras c ON cp.compra_id = c.id
                     WHERE cp.status_pagamento IN ('Pago', 'Concluido')";
    $stmtTotalPago = $pdo->prepare($sqlTotalPago);
    $stmtTotalPago->execute();
    $totalPago = $stmtTotalPago->fetch(PDO::FETCH_ASSOC)['total_pago'];
} catch (PDOException $e) {
    $error = "Erro ao calcular os totais de contas a pagar: " . $e->getMessage();
}

// Processa mensagens de URL
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// Inclui o template de header
include('includes/header_template.php');
startPage("Contas a Pagar - LicitaSis", "financeiro");
?>

<style>
    /* Variáveis CSS */
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
        --shadow: 0 2px 10px rgba(0,0,0,0.1);
        --shadow-hover: 0 4px 20px rgba(0,0,0,0.15);
        --radius: 8px;
        --transition: all 0.3s ease;
        /* Cores específicas para status */
        --pendente-color: #fd7e14;
        --pago-color: #28a745;
        --concluido-color: #17a2b8;
    }

    * {
        margin: 0; 
        padding: 0; 
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        color: var(--dark-gray);
        min-height: 100vh;
        line-height: 1.6;
    }

    /* Container principal */
    .payables-container {
        max-width: 1400px;
        margin: 0 auto;
    }

    /* Header da página */
    .page-header {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
        color: white;
        padding: 2rem;
        border-radius: var(--radius);
        margin-bottom: 2rem;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .page-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 100%;
        height: 100%;
        background: rgba(255,255,255,0.1);
        border-radius: 50%;
        transform: rotate(45deg);
    }

    .page-header h1 {
        font-size: 2.2rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        color: white;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        position: relative;
        z-index: 1;
    }

    .page-header p {
        font-size: 1.1rem;
        opacity: 0.9;
        margin: 0;
        position: relative;
        z-index: 1;
    }

    /* Cards de resumo */
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
        background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
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
        border-left-color: var(--info-color);
    }

    .summary-card.pendente {
        border-left-color: var(--warning-color);
    }

    .summary-card.pago {
        border-left-color: var(--success-color);
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
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        font-family: 'Courier New', monospace;
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

    /* Mensagens de feedback */
    .alert {
        padding: 1rem 1.5rem;
        border-radius: var(--radius);
        margin-bottom: 1.5rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        animation: slideInDown 0.3s ease;
    }

    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    @keyframes slideInDown {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    /* Barra de controles */
    .controls-bar {
        background: white;
        padding: 1.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        margin-bottom: 2rem;
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        align-items: center;
        justify-content: space-between;
    }

    .search-form {
        display: flex;
        gap: 1rem;
        align-items: center;
        flex: 1;
        min-width: 300px;
    }

    .search-input {
        flex: 1;
        padding: 0.875rem 1rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius);
        font-size: 1rem;
        transition: var(--transition);
        background-color: #f9f9f9;
    }

    .search-input:focus {
        outline: none;
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        background-color: white;
    }

    .btn {
        padding: 0.875rem 1.5rem;
        border: none;
        border-radius: var(--radius);
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        white-space: nowrap;
    }

    .btn-primary {
        background: var(--secondary-color);
        color: white;
    }

    .btn-primary:hover {
        background: var(--secondary-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
    }

    .btn-secondary {
        background: var(--medium-gray);
        color: white;
    }

    .btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
    }

    .btn-success {
        background: var(--success-color);
        color: white;
    }

    .btn-success:hover {
        background: #218838;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
    }

    .btn-warning {
        background: var(--warning-color);
        color: var(--dark-gray);
    }

    .btn-warning:hover {
        background: #e0a800;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
    }

    .btn-danger {
        background: var(--danger-color);
        color: white;
    }

    .btn-danger:hover {
        background: #c82333;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
    }

    /* Informações de resultados */
    .results-info {
        background: white;
        padding: 1rem 1.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        margin-bottom: 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .results-count {
        color: var(--medium-gray);
        font-weight: 500;
    }

    .results-count strong {
        color: var(--primary-color);
    }

    /* Tabela */
    .table-container {
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .table-responsive {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    table th, table td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
        vertical-align: middle;
    }

    table th {
        background: var(--secondary-color);
        color: white;
        font-weight: 600;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    table tbody tr {
        transition: var(--transition);
    }

    table tbody tr:hover {
        background: var(--light-gray);
    }

    .nf-link {
        color: var(--secondary-color);
        text-decoration: none;
        font-weight: 600;
        transition: var(--transition);
        cursor: pointer;
    }

    .nf-link:hover {
        color: var(--primary-color);
        text-decoration: underline;
    }

    .status-select {
        padding: 0.5rem 0.75rem;
        border-radius: var(--radius);
        border: 2px solid var(--border-color);
        font-size: 0.9rem;
        cursor: pointer;
        transition: var(--transition);
        background-color: #f9f9f9;
        font-weight: 500;
        min-width: 120px;
    }

    .status-select:hover, .status-select:focus {
        border-color: var(--primary-color);
        background-color: white;
        outline: none;
        box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.1);
    }

    .status-select.status-pendente {
        background: rgba(253, 126, 20, 0.1);
        color: var(--pendente-color);
        border-color: var(--pendente-color);
    }

    .status-select.status-pago {
        background: rgba(40, 167, 69, 0.1);
        color: var(--pago-color);
        border-color: var(--pago-color);
    }

    .status-select.status-concluido {
        background: rgba(23, 162, 184, 0.1);
        color: var(--concluido-color);
        border-color: var(--concluido-color);
    }

    .status-badge {
        padding: 0.4rem 0.8rem;
        border-radius: var(--radius);
        font-size: 0.85rem;
        font-weight: 600;
        display: inline-block;
        text-align: center;
        min-width: 90px;
    }

    .status-badge.status-pendente {
        background: rgba(253, 126, 20, 0.1);
        color: var(--pendente-color);
        border: 1px solid rgba(253, 126, 20, 0.3);
    }

    .status-badge.status-pago {
        background: rgba(40, 167, 69, 0.1);
        color: var(--pago-color);
        border: 1px solid rgba(40, 167, 69, 0.3);
    }

    .status-badge.status-concluido {
        background: rgba(23, 162, 184, 0.1);
        color: var(--concluido-color);
        border: 1px solid rgba(23, 162, 184, 0.3);
    }

    /* Paginação */
    .pagination-container {
        display: flex;
        justify-content: center;
        margin-top: 2rem;
    }

    .pagination {
        display: flex;
        list-style: none;
        padding: 0;
        margin: 0;
        gap: 0.25rem;
        align-items: center;
    }

    .pagination a, .pagination span {
        padding: 0.75rem 1rem;
        text-decoration: none;
        color: var(--medium-gray);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        transition: var(--transition);
        font-weight: 500;
    }

    .pagination a:hover {
        background: var(--secondary-color);
        color: white;
        border-color: var(--secondary-color);
    }

    .pagination .current {
        background: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }

    .pagination .disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Estado vazio */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        color: var(--medium-gray);
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
    }

    .empty-state i {
        font-size: 4rem;
        margin-bottom: 1.5rem;
        color: var(--border-color);
    }

    .empty-state h3 {
        color: var(--dark-gray);
        margin-bottom: 1rem;
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
        overflow-y: auto;
        animation: fadeIn 0.3s ease;
    }

    .modal-content {
        background: white;
        margin: 2rem auto;
        padding: 0;
        border-radius: var(--radius);
        width: 90%;
        max-width: 1000px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        animation: slideInUp 0.3s ease;
        overflow: hidden;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes slideInUp {
        from { transform: translateY(50px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .modal-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 1.5rem 2rem;
        position: relative;
    }

    .modal-header h3 {
        margin: 0;
        color: white;
        font-size: 1.4rem;
        font-weight: 600;
    }

    .modal-close {
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
        line-height: 1;
    }

    .modal-close:hover {
        transform: translateY(-50%) scale(1.1);
        color: #ffdddd;
    }

    .modal-body {
        padding: 2rem;
        max-height: 70vh;
        overflow-y: auto;
    }

    /* Seções de detalhes */
    .detail-section {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        overflow: hidden;
        margin-bottom: 1.5rem;
    }

    .detail-header {
        background: var(--light-gray);
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border-color);
        font-weight: 600;
        color: var(--primary-color);
        display: flex;
        align-items: center;
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
        color: var(--primary-color);
        font-weight: 700;
        font-size: 1.1rem;
    }

    .detail-value.money {
        color: var(--success-color);
        font-weight: 700;
        font-size: 1.1rem;
    }

    /* Formulário do modal */
    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        display: block;
        font-size: 0.95rem;
    }

    .form-control {
        width: 100%;
        padding: 0.875rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius);
        font-size: 1rem;
        transition: var(--transition);
        background-color: #f9f9f9;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
    }

    .form-control[readonly] {
        background: var(--light-gray);
        color: var(--medium-gray);
        cursor: not-allowed;
    }

    /* Modal de Confirmação */
    .confirmation-modal {
        display: none;
        position: fixed;
        z-index: 2000;
        left: 0; 
        top: 0;
        width: 100%; 
        height: 100%;
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
        animation: slideInUp 0.3s ease;
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
        background-color: var(--light-gray);
        padding: 1rem;
        border-radius: var(--radius);
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

    /* Campo de data no modal de confirmação */
    #confirmPaymentDateGroup {
        animation: slideInDown 0.3s ease;
    }

    #confirmPaymentDateGroup input[type="date"] {
        transition: var(--transition);
    }

    #confirmPaymentDateGroup input[type="date"]:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.1);
        background-color: white;
    }

    #confirmPaymentDateGroup input[type="date"]:invalid {
        border-color: var(--danger-color);
        box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
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
        border-radius: var(--radius);
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
        border-radius: var(--radius);
        cursor: pointer;
        font-weight: 600;
        transition: var(--transition);
    }

    .btn-cancel:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }

    /* Modal de Autenticação Financeira */
    .financial-auth-modal {
        display: none;
        position: fixed;
        z-index: 3000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(8px);
        overflow: auto;
        animation: fadeIn 0.3s ease;
    }

    .financial-auth-content {
        background-color: white;
        margin: 15% auto;
        padding: 0;
        border-radius: var(--radius);
        box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        width: 90%;
        max-width: 450px;
        position: relative;
        animation: slideInUp 0.3s ease;
        overflow: hidden;
        border-top: 5px solid var(--warning-color);
    }

    .financial-auth-header {
        background: linear-gradient(135deg, var(--warning-color), #ff8f00);
        color: var(--dark-gray);
        padding: 1.5rem 2rem;
        text-align: center;
        position: relative;
    }

    .financial-auth-header h3 {
        margin: 0;
        font-size: 1.3rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .financial-auth-header .security-icon {
        font-size: 1.5rem;
        color: var(--dark-gray);
    }

    .financial-auth-body {
        padding: 2rem;
    }

    .security-notice {
        background: linear-gradient(135deg, #fff3cd, #ffeaa7);
        border: 2px solid var(--warning-color);
        border-radius: var(--radius);
        padding: 1rem;
        margin-bottom: 1.5rem;
        text-align: center;
        position: relative;
    }

    .security-notice::before {
        content: '\f3ed';
        font-family: 'Font Awesome 5 Free';
        font-weight: 900;
        position: absolute;
        top: -10px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--warning-color);
        color: var(--dark-gray);
        padding: 0.5rem;
        border-radius: 50%;
        font-size: 1.2rem;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .security-notice h4 {
        color: var(--dark-gray);
        margin: 0.5rem 0;
        font-size: 1rem;
        font-weight: 600;
    }

    .security-notice p {
        color: var(--dark-gray);
        margin: 0;
        font-size: 0.9rem;
        opacity: 0.8;
    }

    .password-input-group {
        position: relative;
        margin-bottom: 1.5rem;
    }

    .password-input-group label {
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        display: block;
        font-size: 0.95rem;
    }

    .payment-date-group {
        position: relative;
        margin-bottom: 1.5rem;
    }

    .payment-date-group label {
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        display: block;
        font-size: 0.95rem;
    }

    .payment-date-group input[type="date"] {
        width: 100%;
        padding: 1rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius);
        font-size: 1rem;
        transition: var(--transition);
        background-color: #f9f9f9;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .payment-date-group input[type="date"]:focus {
        outline: none;
        border-color: var(--warning-color);
        box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.1);
        background-color: white;
    }

    .password-input-wrapper {
        position: relative;
    }

    .password-input {
        width: 100%;
        padding: 1rem 3rem 1rem 1rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius);
        font-size: 1rem;
        transition: var(--transition);
        background-color: #f9f9f9;
        font-family: monospace;
        letter-spacing: 2px;
    }

    .password-input:focus {
        outline: none;
        border-color: var(--warning-color);
        box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.1);
        background-color: white;
    }

    .password-toggle {
        position: absolute;
        right: 1rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: var(--medium-gray);
        cursor: pointer;
        font-size: 1.1rem;
        transition: var(--transition);
    }

    .password-toggle:hover {
        color: var(--primary-color);
    }

    .auth-buttons {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 1.5rem;
    }

    .btn-auth-confirm {
        background: var(--success-color);
        color: white;
        border: none;
        padding: 0.875rem 1.5rem;
        border-radius: var(--radius);
        cursor: pointer;
        font-weight: 600;
        transition: var(--transition);
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-auth-confirm:hover:not(:disabled) {
        background: #218838;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
    }

    .btn-auth-confirm:disabled {
        background: var(--medium-gray);
        cursor: not-allowed;
        opacity: 0.6;
    }

    .btn-auth-cancel {
        background: var(--danger-color);
        color: white;
        border: none;
        padding: 0.875rem 1.5rem;
        border-radius: var(--radius);
        cursor: pointer;
        font-weight: 600;
        transition: var(--transition);
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-auth-cancel:hover {
        background: #c82333;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
    }

    .auth-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
        border-radius: var(--radius);
        padding: 0.75rem 1rem;
        margin-top: 1rem;
        font-size: 0.9rem;
        display: none;
        animation: shake 0.5s ease-in-out;
    }

    @keyframes shake {
        0%, 20%, 40%, 60%, 80% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
    }

    .loading-spinner {
        display: none;
        width: 20px;
        height: 20px;
        border: 2px solid transparent;
        border-top: 2px solid currentColor;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* Responsividade */
    @media (max-width: 1200px) {
        .payables-container {
            padding: 1.5rem 1rem;
        }
    }

    @media (max-width: 768px) {
        .page-header {
            padding: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.8rem;
        }

        .summary-cards {
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .controls-bar {
            flex-direction: column;
            align-items: stretch;
        }

        .search-form {
            min-width: auto;
        }

        .results-info {
            flex-direction: column;
            text-align: center;
        }

        .form-row {
            grid-template-columns: 1fr;
        }

        .modal-content {
            margin: 1rem;
            width: calc(100% - 2rem);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .confirmation-buttons, .auth-buttons {
            flex-direction: column;
        }

        .confirmation-buttons button, .auth-buttons button {
            width: 100%;
        }

        table th, table td {
            padding: 0.75rem 0.5rem;
            font-size: 0.9rem;
        }

        .financial-auth-content {
            margin: 10% auto;
            width: 95%;
        }

        .financial-auth-body {
            padding: 1.5rem;
        }
    }

    @media (max-width: 480px) {
        .page-header {
            padding: 1rem;
        }

        .page-header h1 {
            font-size: 1.5rem;
        }

        .summary-cards {
            grid-template-columns: 1fr;
        }

        .controls-bar, .results-info {
            padding: 1rem;
        }

        .modal-header, .financial-auth-header {
            padding: 1rem;
        }

        .modal-header h3, .financial-auth-header h3 {
            font-size: 1.2rem;
        }

        .btn {
            width: 100%;
            justify-content: center;
        }

        .confirmation-modal-content, .financial-auth-content {
            margin: 5% 1rem;
            padding: 1.5rem;
        }
    }

    /* Animações */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .table-container, .controls-bar, .results-info, .summary-cards {
        animation: fadeInUp 0.6s ease forwards;
    }

    .table-container { animation-delay: 0.2s; }
    .controls-bar { animation-delay: 0.05s; }
    .results-info { animation-delay: 0.15s; }
    .summary-cards { animation-delay: 0.1s; }

    /* Scrollbar personalizada */
    .table-responsive::-webkit-scrollbar, .modal-body::-webkit-scrollbar, .financial-auth-body::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    .table-responsive::-webkit-scrollbar-track, .modal-body::-webkit-scrollbar-track, .financial-auth-body::-webkit-scrollbar-track {
        background: var(--light-gray);
        border-radius: 4px;
    }

    .table-responsive::-webkit-scrollbar-thumb, .modal-body::-webkit-scrollbar-thumb, .financial-auth-body::-webkit-scrollbar-thumb {
        background: var(--medium-gray);
        border-radius: 4px;
    }

    .table-responsive::-webkit-scrollbar-thumb:hover, .modal-body::-webkit-scrollbar-thumb:hover, .financial-auth-body::-webkit-scrollbar-thumb:hover {
        background: var(--dark-gray);
    }

    /* Utilitários */
    .text-center { text-align: center; }
    .mb-0 { margin-bottom: 0; }
    .mt-1 { margin-top: 0.5rem; }
    .font-weight-bold { font-weight: 600; }

    /* Indicadores visuais para urgência */
    .urgent-payment {
        background: rgba(220, 53, 69, 0.05);
        border-left: 4px solid var(--danger-color);
    }

    .due-soon {
        background: rgba(255, 193, 7, 0.05);
        border-left: 4px solid var(--warning-color);
    }
</style>

<body>

<!-- Container principal com layout padrão do sistema -->
<div class="main-content">
    <div class="container payables-container">
    
    <!-- Header da página -->
    <div class="page-header">
        <h1><i class="fas fa-credit-card"></i> Contas a Pagar</h1>
        <p>Gerencie e acompanhe todas as contas pendentes de pagamento</p>
    </div>

    <!-- Cards de resumo -->
    <div class="summary-cards">
        <div class="summary-card total">
            <h4>Total Geral</h4>
            <div class="value">R$ <?php echo number_format($totalGeralPagar ?? 0, 2, ',', '.'); ?></div>
            <i class="fas fa-calculator icon"></i>
        </div>
        <div class="summary-card pendente">
            <h4>Contas Pendentes</h4>
            <div class="value">R$ <?php echo number_format($totalPendente ?? 0, 2, ',', '.'); ?></div>
            <i class="fas fa-clock icon"></i>
        </div>
        <div class="summary-card pago">
            <h4>Contas Pagas</h4>
            <div class="value">R$ <?php echo number_format($totalPago ?? 0, 2, ',', '.'); ?></div>
            <i class="fas fa-check-circle icon"></i>
        </div>
    </div>

    <!-- Mensagens de feedback -->
    <?php if ($error): ?>
        <div class="alert alert-danger">
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

    <!-- Barra de controles -->
    <div class="controls-bar">
        <form class="search-form" action="contas_a_pagar.php" method="GET">
            <input type="text" 
                   name="search" 
                   class="search-input"
                   placeholder="Pesquisar por NF, Fornecedor ou Status..." 
                   value="<?php echo htmlspecialchars($searchTerm); ?>"
                   autocomplete="off">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Pesquisar
            </button>
            <?php if ($searchTerm): ?>
                <a href="contas_a_pagar.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Limpar
                </a>
            <?php endif; ?>
        </form>
        
        <?php if ($permissionManager->hasPagePermission('compras', 'create')): ?>
            <a href="cadastro_compras.php" class="btn btn-success">
                <i class="fas fa-plus"></i> Nova Compra
            </a>
        <?php endif; ?>
    </div>

    <!-- Informações de resultados -->
    <?php if ($totalContas > 0): ?>
        <div class="results-info">
            <div class="results-count">
                <?php if ($searchTerm): ?>
                    Encontradas <strong><?php echo $totalContas; ?></strong> conta(s) a pagar 
                    para "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>"
                <?php else: ?>
                    Total de <strong><?php echo $totalContas; ?></strong> conta(s) a pagar
                <?php endif; ?>
                
                <?php if ($totalPaginas > 1): ?>
                    - Página <strong><?php echo $paginaAtual; ?></strong> de <strong><?php echo $totalPaginas; ?></strong>
                <?php endif; ?>
            </div>
            
            <?php if ($totalContas > $contasPorPagina): ?>
                <div>
                    Mostrando <?php echo ($offset + 1); ?>-<?php echo min($offset + $contasPorPagina, $totalContas); ?> 
                    de <?php echo $totalContas; ?> resultados
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Tabela de contas a pagar -->
    <?php if (count($contas_a_pagar) > 0): ?>
        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><i class="fas fa-file-invoice"></i> NF</th>
                            <th><i class="fas fa-building"></i> Fornecedor</th>
                            <th><i class="fas fa-dollar-sign"></i> Valor Total</th>
                            <th><i class="fas fa-calendar"></i> Data da Compra</th>
                            <th><i class="fas fa-tasks"></i> Status</th>
                            <th><i class="fas fa-calendar-check"></i> Data de Pagamento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contas_a_pagar as $conta): 
                            $dataCompra = new DateTime($conta['data']);
                            $hoje = new DateTime();
                            $diasAteVencimento = $hoje->diff($dataCompra)->days;
                            
                            $rowClass = '';
                            if ($conta['status_pagamento'] === 'Pendente' && $diasAteVencimento > 30) {
                                $rowClass = 'urgent-payment';
                            } elseif ($conta['status_pagamento'] === 'Pendente' && $diasAteVencimento > 15) {
                                $rowClass = 'due-soon';
                            }
                        ?>
                            <tr data-id="<?php echo $conta['id']; ?>" class="<?php echo $rowClass; ?>">
                                <td>
                                    <span class="nf-link" onclick="openModal(<?php echo $conta['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                        <?php echo htmlspecialchars($conta['numero_nf']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($conta['fornecedor']); ?></td>
                                <td class="font-weight-bold">R$ <?php echo number_format($conta['valor_total'], 2, ',', '.'); ?></td>
                                <td><?php echo $dataCompra->format('d/m/Y'); ?></td>
                                <td>
                                    <?php if ($permissionManager->hasPagePermission('financeiro', 'edit')): ?>
                                        <select class="status-select" 
                                                data-id="<?php echo $conta['id']; ?>" 
                                                data-nf="<?php echo htmlspecialchars($conta['numero_nf']); ?>"
                                                data-fornecedor="<?php echo htmlspecialchars($conta['fornecedor']); ?>"
                                                data-valor="<?php echo number_format($conta['valor_total'], 2, ',', '.'); ?>"
                                                data-data="<?php echo $dataCompra->format('d/m/Y'); ?>"
                                                data-status-atual="<?php echo $conta['status_pagamento']; ?>">
                                            <option value="Pendente" <?php if ($conta['status_pagamento'] === 'Pendente') echo 'selected'; ?>>Pendente</option>
                                            <option value="Pago" <?php if ($conta['status_pagamento'] === 'Pago') echo 'selected'; ?>>Pago</option>
                                            <option value="Concluido" <?php if ($conta['status_pagamento'] === 'Concluido') echo 'selected'; ?>>Concluído</option>
                                        </select>
                                    <?php else: ?>
                                        <span class="status-badge <?php echo 'status-' . strtolower($conta['status_pagamento']); ?>">
                                            <?php
                                            $icons = [
                                                'Pendente' => 'clock',
                                                'Pago' => 'check-circle',
                                                'Concluido' => 'check-double'
                                            ];
                                            $icon = $icons[$conta['status_pagamento']] ?? 'tag';
                                            ?>
                                            <i class="fas fa-<?php echo $icon; ?>"></i>
                                            <?php echo $conta['status_pagamento']; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                        if ($conta['data_pagamento']) {
                                            $dataPagamento = new DateTime($conta['data_pagamento']);
                                            $hoje = new DateTime();
                                            $isToday = $dataPagamento->format('Y-m-d') === $hoje->format('Y-m-d');
                                            
                                            echo '<strong style="color: var(--success-color);">' . $dataPagamento->format('d/m/Y') . '</strong>';
                                            
                                            if ($isToday) {
                                                echo '<br><small style="color: var(--info-color); font-size: 0.75rem;"><i class="fas fa-clock"></i> Hoje</small>';
                                            }
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
        </div>

        <!-- Paginação -->
        <?php if ($totalPaginas > 1): ?>
            <div class="pagination-container">
                <div class="pagination">
                    <?php if ($paginaAtual > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">
                            <i class="fas fa-angle-double-left"></i> Primeira
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $paginaAtual - 1])); ?>">
                            <i class="fas fa-angle-left"></i> Anterior
                        </a>
                    <?php else: ?>
                        <span class="disabled">
                            <i class="fas fa-angle-double-left"></i> Primeira
                        </span>
                        <span class="disabled">
                            <i class="fas fa-angle-left"></i> Anterior
                        </span>
                    <?php endif; ?>

                    <?php
                    $inicio = max(1, $paginaAtual - 2);
                    $fim = min($totalPaginas, $paginaAtual + 2);
                    
                    if ($inicio > 1): ?>
                        <span>...</span>
                    <?php endif;
                    
                    for ($i = $inicio; $i <= $fim; $i++): ?>
                        <?php if ($i == $paginaAtual): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor;
                    
                    if ($fim < $totalPaginas): ?>
                        <span>...</span>
                    <?php endif; ?>

                    <?php if ($paginaAtual < $totalPaginas): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $paginaAtual + 1])); ?>">
                            Próxima <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPaginas])); ?>">
                            Última <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled">
                            Próxima <i class="fas fa-angle-right"></i>
                        </span>
                        <span class="disabled">
                            Última <i class="fas fa-angle-double-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- Estado vazio -->
        <div class="empty-state">
            <i class="fas fa-credit-card"></i>
            <h3>Nenhuma conta a pagar encontrada</h3>
            <?php if ($searchTerm): ?>
                <p>Não foram encontradas contas a pagar com os termos de busca "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>".</p>
                <a href="contas_a_pagar.php" class="btn btn-primary">
                    <i class="fas fa-list"></i> Ver Todas as Contas
                </a>
            <?php else: ?>
                <p>Parabéns! Não há contas pendentes de pagamento no momento.</p>
                <?php if ($permissionManager->hasPagePermission('compras', 'create')): ?>
                    <a href="cadastro_compras.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> Registrar Nova Compra
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Modal de Detalhes da Conta -->
<div id="contaModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="fas fa-credit-card"></i> Detalhes da Conta a Pagar</h3>
            <span class="modal-close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="loading-spinner" style="text-align: center; padding: 3rem;">
                <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da conta...</p>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Confirmação para Pagamento -->
<div id="confirmationModal" class="confirmation-modal" role="dialog" aria-modal="true">
    <div class="confirmation-modal-content">
        <h3><i class="fas fa-exclamation-triangle"></i> Confirmar Alteração de Status</h3>
        <p>Deseja realmente alterar o status desta conta?</p>
        
        <div class="confirmation-info">
            <p><strong>NF:</strong> <span id="confirm-nf"></span></p>
            <p><strong>Fornecedor:</strong> <span id="confirm-fornecedor"></span></p>
            <p><strong>Valor:</strong> R$ <span id="confirm-valor"></span></p>
            <p><strong>Status Atual:</strong> <span id="confirm-status-atual"></span></p>
            <p><strong>Novo Status:</strong> <span id="confirm-novo-status"></span></p>
        </div>
        
        <!-- Campo de Data de Pagamento -->
        <div id="confirmPaymentDateGroup" style="display: none; margin: 1.5rem 0; padding: 1rem; background: var(--light-gray); border-radius: var(--radius); border-left: 4px solid var(--primary-color);">
            <label for="confirmPaymentDate" style="font-weight: 600; color: var(--primary-color); margin-bottom: 0.5rem; display: block; font-size: 0.95rem;">
                <i class="fas fa-calendar-alt"></i> Data do Pagamento
            </label>
            <input type="text" 
                   id="confirmPaymentDate" 
                   placeholder="dd/mm/aaaa"
                   style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius); font-size: 1rem; transition: var(--transition); background-color: white;"
                   maxlength="10"
                   oninput="formatDateBR(this)"
                   onblur="validateDateBR(this)">
            <small style="color: var(--medium-gray); font-size: 0.85rem; margin-top: 0.5rem; display: block;">
                <i class="fas fa-info-circle"></i> Se não informada, será usada a data atual (<?php echo date('d/m/Y'); ?>)
            </small>
        </div>
        
        <p style="color: var(--warning-color); font-size: 0.9rem; margin-top: 1rem;" id="auth-warning">
            <i class="fas fa-info-circle"></i> Esta ação requer autenticação do setor financeiro.
        </p>
        
        <div class="confirmation-buttons">
            <button type="button" class="btn-cancel" onclick="closeConfirmationModal()">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="button" class="btn-confirm" onclick="handleStatusConfirmation()">
                <i class="fas fa-check"></i> Confirmar
            </button>
        </div>
    </div>
</div>

<!-- Modal de Autenticação Financeira -->
<div id="financialAuthModal" class="financial-auth-modal" role="dialog" aria-modal="true">
    <div class="financial-auth-content">
        <div class="financial-auth-header">
            <h3>
                <i class="fas fa-shield-alt security-icon"></i>
                Autenticação do Setor Financeiro
            </h3>
        </div>
        <div class="financial-auth-body">
            <div class="security-notice">
                <h4>Autorização Necessária</h4>
                <p>Para alterar o status de pagamento, é necessário inserir a senha do setor financeiro por questões de segurança.</p>
            </div>
            
            <div class="password-input-group">
                <label for="financialPassword">
                    <i class="fas fa-key"></i> Senha do Setor Financeiro
                </label>
                <div class="password-input-wrapper">
                    <input type="password" 
                           id="financialPassword" 
                           class="password-input"
                           placeholder="Digite a senha do setor financeiro"
                           autocomplete="off"
                           maxlength="50">
                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility()">
                        <i class="fas fa-eye" id="passwordToggleIcon"></i>
                    </button>
                </div>
            </div>

            <div class="payment-date-group" id="paymentDateGroup" style="display: none;">
                <label for="paymentDate">
                    <i class="fas fa-calendar-alt"></i> Data do Pagamento
                </label>
                <input type="date" 
                       id="paymentDate" 
                       class="form-control"
                       style="width: 100%; padding: 1rem; border: 2px solid var(--border-color); border-radius: var(--radius); font-size: 1rem; transition: var(--transition); background-color: #f9f9f9;">
                <small style="color: var(--medium-gray); font-size: 0.85rem; margin-top: 0.5rem; display: block;">
                    <i class="fas fa-info-circle"></i> Se não informada, será usada a data atual (<?php echo date('d/m/Y'); ?>)
                </small>
            </div>
            
            <div class="auth-error" id="authError">
                <i class="fas fa-exclamation-triangle"></i>
                <span id="authErrorMessage">Senha incorreta. Tente novamente.</span>
            </div>
            
            <div class="auth-buttons">
                <button type="button" class="btn-auth-cancel" onclick="closeFinancialAuthModal()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="button" class="btn-auth-confirm" id="confirmAuthBtn" onclick="confirmFinancialAuth()">
                    <span class="loading-spinner" id="authLoadingSpinner"></span>
                    <i class="fas fa-unlock" id="authConfirmIcon"></i>
                    <span id="authConfirmText">Autorizar</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    let currentSelectElement = null;
    let currentContaData = {};
    let currentContaId = null;

    document.addEventListener('DOMContentLoaded', function() {
        console.log('🚀 Sistema de Contas a Pagar carregado com sucesso!');
        console.log('🔑 Senha do setor financeiro: Licitasis@2025');
        console.log('💰 Total de contas: <?php echo $totalContas; ?>');
        console.log('📄 Página atual: <?php echo $paginaAtual; ?> de <?php echo $totalPaginas; ?>');

        // Função para abrir o modal e carregar os dados da conta
        window.openModal = function(id) {
            currentContaId = id;
            const modal = document.getElementById("contaModal");
            const modalBody = modal.querySelector('.modal-body');

            modal.style.display = "block";
            document.body.style.overflow = 'hidden';

            // Mostra loading
            modalBody.innerHTML = `
                <div class="loading-spinner" style="text-align: center; padding: 3rem;">
                    <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                    <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da conta...</p>
                </div>
            `;

            fetch('contas_a_pagar.php?get_conta_id=' + id)
                .then(response => response.json())
                .then(data => {
                    if(data.error){
                        alert('Erro: ' + data.error);
                        closeModal();
                        return;
                    }
                    renderContaDetails(data);
                })
                .catch(error => {
                    console.error('Erro ao carregar dados:', error);
                    alert('Erro ao carregar os detalhes da conta a pagar.');
                    closeModal();
                });
        };

        // Função para renderizar detalhes da conta
        function renderContaDetails(conta) {
            const modalBody = document.querySelector('#contaModal .modal-body');
            
            // Prepara datas
            const dataCompra = conta.data ? new Date(conta.data).toLocaleDateString('pt-BR') : 'N/A';
            const dataPagamento = conta.data_pagamento ? new Date(conta.data_pagamento).toLocaleDateString('pt-BR') : null;
            
            modalBody.innerHTML = `
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
                        
                        ${conta.observacao ? `
                        <div style="margin-top: 1.5rem;">
                            <div class="detail-label">Observação da Compra</div>
                            <div class="detail-value">${conta.observacao}</div>
                        </div>
                        ` : ''}
                        
                        ${conta.link_pagamento ? `
                        <div style="margin-top: 1.5rem;">
                            <div class="detail-label">Link para Pagamento</div>
                            <div class="detail-value">
                                <a href="${conta.link_pagamento}" target="_blank" style="color: var(--secondary-color); text-decoration: none;">
                                    <i class="fas fa-external-link-alt"></i>
                                    Acessar Link de Pagamento
                                </a>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                </div>

                ${conta.produtos && conta.produtos.length > 0 ? `
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-box"></i>
                        Produtos da Compra (${conta.produtos.length})
                    </div>
                    <div class="detail-content">
                        ${conta.produtos.map((produto, index) => `
                            <div style="background: var(--light-gray); padding: 1rem; border-radius: var(--radius); margin-bottom: 1rem;">
                                <div style="font-weight: 600; color: var(--primary-color); margin-bottom: 0.5rem;">Produto ${index + 1}</div>
                                
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <div class="detail-label">Nome do Produto</div>
                                        <div class="detail-value">${produto.nome || 'Nome não informado'}</div>
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
                ` : ''}

                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-credit-card"></i>
                        Informações de Pagamento
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Status Atual</div>
                                <div class="detail-value">
                                    <span class="status-badge status-${conta.status_pagamento.toLowerCase()}">
                                        <i class="fas fa-${getStatusIcon(conta.status_pagamento)}"></i>
                                        ${conta.status_pagamento}
                                    </span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data de Pagamento</div>
                                <div class="detail-value">${dataPagamento || 'Não pago'}</div>
                            </div>
                        </div>
                        
                        ${conta.observacao_pagamento ? `
                        <div style="margin-top: 1.5rem;">
                            <div class="detail-label">Observação do Pagamento</div>
                            <div class="detail-value">${conta.observacao_pagamento}</div>
                        </div>
                        ` : ''}
                        
                        ${conta.comprovante_pagamento ? `
                        <div style="margin-top: 1.5rem;">
                            <div class="detail-label">Comprovante de Pagamento</div>
                            <a href="${conta.comprovante_pagamento}" target="_blank" style="color: var(--secondary-color); text-decoration: none;">
                                <i class="fas fa-file-alt"></i>
                                Ver Comprovante
                            </a>
                        </div>
                        ` : ''}
                    </div>
                </div>

                <div style="text-align: center; margin-top: 2rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                </div>
            `;
        }

        function getStatusIcon(status) {
            const icons = {
                'Pendente': 'clock',
                'Pago': 'check-circle',
                'Concluido': 'check-double'
            };
            return icons[status] || 'tag';
        }

        // Função para fechar o modal
        window.closeModal = function() {
            const modal = document.getElementById("contaModal");
            modal.style.display = "none";
            document.body.style.overflow = 'auto';
            currentContaId = null;
        };

        // Função para abrir modal de confirmação
        function openConfirmationModal(selectElement, novoStatus) {
            currentSelectElement = selectElement;
            
            currentContaData = {
                id: selectElement.dataset.id,
                nf: selectElement.dataset.nf,
                fornecedor: selectElement.dataset.fornecedor,
                valor: selectElement.dataset.valor,
                data: selectElement.dataset.data,
                statusAtual: selectElement.dataset.statusAtual,
                novoStatus: novoStatus
            };

            document.getElementById('confirm-nf').textContent = currentContaData.nf;
            document.getElementById('confirm-fornecedor').textContent = currentContaData.fornecedor;
            document.getElementById('confirm-valor').textContent = currentContaData.valor;
            document.getElementById('confirm-status-atual').textContent = currentContaData.statusAtual;
            document.getElementById('confirm-novo-status').textContent = currentContaData.novoStatus;

            // Mostra/esconde campo de data e aviso de autenticação
            const paymentDateGroup = document.getElementById('confirmPaymentDateGroup');
            const authWarning = document.getElementById('auth-warning');
            
            if (novoStatus === 'Pago' || novoStatus === 'Concluido') {
                // Mostra campo de data
                paymentDateGroup.style.display = 'block';
                authWarning.style.display = 'block';
                
                // Define data atual como padrão no formato brasileiro
                const paymentDateInput = document.getElementById('confirmPaymentDate');
                paymentDateInput.value = getCurrentDateBR();
            } else {
                paymentDateGroup.style.display = 'none';
                authWarning.style.display = 'none';
                document.getElementById('confirmPaymentDate').value = '';
            }

            document.getElementById('confirmationModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        // Função para formatar data no padrão brasileiro
        window.formatDateBR = function(input) {
            let value = input.value.replace(/\D/g, ''); // Remove tudo que não é dígito
            
            if (value.length >= 2) {
                value = value.substring(0,2) + '/' + value.substring(2);
            }
            if (value.length >= 5) {
                value = value.substring(0,5) + '/' + value.substring(5,9);
            }
            
            input.value = value;
        };

        // Função para validar data brasileira
        window.validateDateBR = function(input) {
            const value = input.value;
            
            if (!value) return; // Campo vazio é válido (usa data atual)
            
            // Verifica formato dd/mm/aaaa
            const dateRegex = /^(\d{2})\/(\d{2})\/(\d{4})$/;
            const match = value.match(dateRegex);
            
            if (!match) {
                showDateError(input, 'Formato inválido. Use dd/mm/aaaa');
                return false;
            }
            
            const day = parseInt(match[1]);
            const month = parseInt(match[2]);
            const year = parseInt(match[3]);
            
            // Valida se a data é válida
            const date = new Date(year, month - 1, day);
            if (date.getDate() !== day || date.getMonth() !== month - 1 || date.getFullYear() !== year) {
                showDateError(input, 'Data inválida');
                return false;
            }
            
            // Verifica se não é data futura
            const today = new Date();
            today.setHours(23, 59, 59, 999); // Fim do dia atual
            
            if (date > today) {
                showDateError(input, 'A data não pode ser futura');
                return false;
            }
            
            // Data válida - remove erro se existir
            clearDateError(input);
            return true;
        };

        // Função para mostrar erro de data
        function showDateError(input, message) {
            // Remove erro anterior se existir
            clearDateError(input);
            
            // Adiciona classe de erro
            input.style.borderColor = 'var(--danger-color)';
            input.style.boxShadow = '0 0 0 3px rgba(220, 53, 69, 0.1)';
            
            // Cria elemento de erro
            const errorDiv = document.createElement('div');
            errorDiv.className = 'date-error';
            errorDiv.style.color = 'var(--danger-color)';
            errorDiv.style.fontSize = '0.8rem';
            errorDiv.style.marginTop = '0.25rem';
            errorDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${message}`;
            
            // Insere após o input
            input.parentNode.insertBefore(errorDiv, input.nextSibling);
        }

        // Função para limpar erro de data
        function clearDateError(input) {
            // Remove classe de erro
            input.style.borderColor = '';
            input.style.boxShadow = '';
            
            // Remove elemento de erro se existir
            const errorDiv = input.parentNode.querySelector('.date-error');
            if (errorDiv) {
                errorDiv.remove();
            }
        }

        // Função para converter data BR para formato ISO
        function datebrToISO(dateBR) {
            if (!dateBR) return '';
            
            const parts = dateBR.split('/');
            if (parts.length !== 3) return '';
            
            const day = parts[0].padStart(2, '0');
            const month = parts[1].padStart(2, '0');
            const year = parts[2];
            
            return `${year}-${month}-${day}`;
        }

        // Função para converter data ISO para formato BR
        function dateISOToBR(dateISO) {
            if (!dateISO) return '';
            
            const parts = dateISO.split('-');
            if (parts.length !== 3) return '';
            
            const year = parts[0];
            const month = parts[1];
            const day = parts[2];
            
            return `${day}/${month}/${year}`;
        }

        // Função para obter data atual no formato BR
        function getCurrentDateBR() {
            const today = new Date();
            const day = String(today.getDate()).padStart(2, '0');
            const month = String(today.getMonth() + 1).padStart(2, '0');
            const year = today.getFullYear();
            
            return `${day}/${month}/${year}`;
        }

        // Função para fechar modal de confirmação
        window.closeConfirmationModal = function() {
            if (currentSelectElement) {
                currentSelectElement.value = currentSelectElement.dataset.statusAtual || 'Pendente';
                updateSelectStyle(currentSelectElement);
            }
            
            // Limpa campos
            document.getElementById('confirmPaymentDate').value = '';
            document.getElementById('confirmPaymentDateGroup').style.display = 'none';
            
            document.getElementById('confirmationModal').style.display = 'none';
            document.body.style.overflow = 'auto';
            currentSelectElement = null;
            currentContaData = {};
        };

        // Função para processar confirmação de mudança de status
        window.handleStatusConfirmation = function() {
            if (!currentContaData.novoStatus) return;
            
            const novoStatus = currentContaData.novoStatus;
            
            // Se está mudando para Pago ou Concluído, requer autenticação
            if (novoStatus === 'Pago' || novoStatus === 'Concluido') {
                document.getElementById('confirmationModal').style.display = 'none';
                document.getElementById('financialAuthModal').style.display = 'block';
                
                // Foca no campo de senha
                setTimeout(() => {
                    document.getElementById('financialPassword').focus();
                }, 300);
            } else {
                // Para mudança para Pendente, não precisa de autenticação
                updateStatusDirect(currentContaData.id, novoStatus);
            }
        };

        // Função para fechar modal de autenticação financeira
        window.closeFinancialAuthModal = function() {
            document.getElementById('financialAuthModal').style.display = 'none';
            document.getElementById('financialPassword').value = '';
            document.getElementById('paymentDate').value = '';
            document.getElementById('authError').style.display = 'none';
            document.getElementById('paymentDateGroup').style.display = 'none';
            document.body.style.overflow = 'auto';
            
            // Reseta o select para o valor original
            if (currentSelectElement) {
                currentSelectElement.value = currentSelectElement.dataset.statusAtual || 'Pendente';
                updateSelectStyle(currentSelectElement);
            }
            
            currentSelectElement = null;
            currentContaData = {};
        };

        // Função para alternar visibilidade da senha
        window.togglePasswordVisibility = function() {
            const passwordInput = document.getElementById('financialPassword');
            const toggleIcon = document.getElementById('passwordToggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'fas fa-eye-slash';
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'fas fa-eye';
            }
        };

        // Função para confirmar autenticação financeira
        window.confirmFinancialAuth = function() {
            const senha = document.getElementById('financialPassword').value.trim();
            const dataPagamentoBR = document.getElementById('paymentDate').value;
            const confirmBtn = document.getElementById('confirmAuthBtn');
            const loadingSpinner = document.getElementById('authLoadingSpinner');
            const confirmIcon = document.getElementById('authConfirmIcon');
            const confirmText = document.getElementById('authConfirmText');
            const authError = document.getElementById('authError');
            
            if (!senha) {
                showAuthError('Por favor, digite a senha do setor financeiro.');
                return;
            }

            // Validação da data de pagamento se for necessária
            const novoStatus = currentContaData.novoStatus;
            if ((novoStatus === 'Pago' || novoStatus === 'Concluido') && dataPagamentoBR) {
                if (!validateDateBR(document.getElementById('paymentDate'))) {
                    showAuthError('Data de pagamento inválida.');
                    return;
                }
            }

            // Verifica se a senha está correta localmente primeiro
            const senhaCorreta = 'Licitasis@2025';
            if (senha !== senhaCorreta) {
                showAuthError('Senha incorreta. A senha deve ser: Licitasis@2025 (case-sensitive)');
                return;
            }

            // Mostra loading
            confirmBtn.disabled = true;
            loadingSpinner.style.display = 'inline-block';
            confirmIcon.style.display = 'none';
            confirmText.textContent = 'Verificando...';
            authError.style.display = 'none';

            // Atualiza status com autenticação
            updateStatusWithAuth(currentContaData.id, currentContaData.novoStatus, senha);
        };

        // Função para mostrar erro de autenticação
        function showAuthError(message) {
            const authError = document.getElementById('authError');
            const authErrorMessage = document.getElementById('authErrorMessage');
            
            authErrorMessage.textContent = message;
            authError.style.display = 'block';
            
            // Limpa o campo de senha
            document.getElementById('financialPassword').value = '';
            document.getElementById('financialPassword').focus();
        }

        // Função para resetar botão de confirmação
        function resetAuthButton() {
            const confirmBtn = document.getElementById('confirmAuthBtn');
            const loadingSpinner = document.getElementById('authLoadingSpinner');
            const confirmIcon = document.getElementById('authConfirmIcon');
            const confirmText = document.getElementById('authConfirmText');
            
            confirmBtn.disabled = false;
            loadingSpinner.style.display = 'none';
            confirmIcon.style.display = 'inline-block';
            confirmText.textContent = 'Autorizar';
        }

        // Função para atualizar status com autenticação
        function updateStatusWithAuth(id, status, senha) {
            // Pega a data de pagamento definida pelo usuário no formato brasileiro
            const dataPagamentoBR = document.getElementById('paymentDate').value;
            let dataPagamentoISO = '';
            
            if (dataPagamentoBR) {
                dataPagamentoISO = datebrToISO(dataPagamentoBR);
            } else {
                // Se não informada, usa data atual
                dataPagamentoISO = new Date().toISOString().split('T')[0];
            }
            
            fetch('contas_a_pagar.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    update_status: '1',
                    id: id,
                    status_pagamento: status,
                    data_pagamento: status === 'Pendente' ? '' : dataPagamentoISO,
                    observacao_pagamento: `Status alterado para ${status} em ${dataPagamentoBR || getCurrentDateBR()}`,
                    financial_password: senha
                })
            })
            .then(response => response.json())
            .then(data => {
                resetAuthButton();
                
                if (data.success) {
                    closeFinancialAuthModal();
                    updateSelectValue(currentSelectElement, status);
                    
                    // Atualiza a data de pagamento na linha da tabela
                    const row = document.querySelector(`tr[data-id='${id}']`);
                    if (row && status !== 'Pendente') {
                        const dataCells = row.querySelectorAll('td');
                        const dataCell = dataCells[dataCells.length - 1]; // Última coluna (Data de Pagamento)
                        if (dataCell) {
                            const dataFormatadaBR = dataPagamentoBR || getCurrentDateBR();
                            const isToday = dataFormatadaBR === getCurrentDateBR();
                            
                            dataCell.innerHTML = `<strong style="color: var(--success-color);">${dataFormatadaBR}</strong>`;
                            if (isToday) {
                                dataCell.innerHTML += '<br><small style="color: var(--info-color); font-size: 0.75rem;"><i class="fas fa-clock"></i> Hoje</small>';
                            }
                        }
                    }
                    
                    showSuccessMessage(`Status atualizado para ${status} com data ${dataPagamentoBR || getCurrentDateBR()}!`);
                    
                    // Atualiza a página após 2 segundos para refletir as mudanças
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    showAuthError(data.error || 'Erro ao processar solicitação');
                }
            })
            .catch(error => {
                resetAuthButton();
                console.error('Erro na comunicação:', error);
                showAuthError('Erro na comunicação com o servidor.');
            });
        }

        // Função para atualizar status diretamente (sem autenticação)
        function updateStatusDirect(id, status) {
            fetch('contas_a_pagar.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    update_status: '1',
                    id: id,
                    status_pagamento: status,
                    data_pagamento: '',
                    observacao_pagamento: status === 'Pendente' ? 'Status alterado para Pendente' : ''
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeConfirmationModal();
                    updateSelectValue(currentSelectElement, status);
                    showSuccessMessage('Status de pagamento atualizado com sucesso!');
                    
                    // Atualiza a página após 2 segundos para refletir as mudanças
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    alert('Erro ao atualizar status: ' + (data.error || 'Erro desconhecido'));
                    if (currentSelectElement) {
                        currentSelectElement.value = currentSelectElement.dataset.statusAtual || 'Pendente';
                        updateSelectStyle(currentSelectElement);
                    }
                }
            })
            .catch(error => {
                console.error('Erro na comunicação:', error);
                alert('Erro na comunicação com o servidor.');
                if (currentSelectElement) {
                    currentSelectElement.value = currentSelectElement.dataset.statusAtual || 'Pendente';
                    updateSelectStyle(currentSelectElement);
                }
            });
        }

        // Função para atualizar valor do select e estilo
        function updateSelectValue(selectElement, newStatus) {
            if (selectElement) {
                selectElement.value = newStatus;
                selectElement.dataset.statusAtual = newStatus;
                updateSelectStyle(selectElement);
            }
        }

        // Função para atualizar estilo visual do select baseado no status
        function updateSelectStyle(selectElement) {
            if (!selectElement) return;
            
            // Remove classes existentes
            selectElement.className = 'status-select';
            
            // Adiciona classe baseada no status
            const status = selectElement.value.toLowerCase();
            selectElement.classList.add(`status-${status}`);
        }

        // Função para mostrar mensagem de sucesso
        function showSuccessMessage(message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success';
            alertDiv.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
            alertDiv.style.position = 'fixed';
            alertDiv.style.top = '20px';
            alertDiv.style.right = '20px';
            alertDiv.style.zIndex = '4000';
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

        // Event listener para os selects de status (apenas se o usuário tem permissão de edição)
        <?php if ($permissionManager->hasPagePermission('financeiro', 'edit')): ?>
        const statusSelects = document.querySelectorAll('.status-select');
        if (statusSelects.length > 0) {
            console.log('✅ Permissões de edição detectadas, ativando event listeners para selects de status');
            
            statusSelects.forEach(select => {
                // Aplica estilo inicial
                updateSelectStyle(select);
                
                select.addEventListener('change', function() {
                    const novoStatus = this.value;
                    const statusAtual = this.dataset.statusAtual || 'Pendente';
                    
                    if (novoStatus !== statusAtual) {
                        openConfirmationModal(this, novoStatus);
                    }
                });
            });
        } else {
            console.log('ℹ️ Sem permissões de edição ou sem contas para editar');
        }
        <?php endif; ?>

        // Enter para confirmar senha no modal de autenticação
        document.getElementById('financialPassword').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                confirmFinancialAuth();
            }
        });

        // Event listener para fechar modal com ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
                closeConfirmationModal();
                closeFinancialAuthModal();
            }
        });

        // Event listener para clicar fora do modal
        window.onclick = function(event) {
            const modal = document.getElementById('contaModal');
            const confirmModal = document.getElementById('confirmationModal');
            const authModal = document.getElementById('financialAuthModal');
            
            if (event.target === modal) {
                closeModal();
            }
            if (event.target === confirmModal) {
                closeConfirmationModal();
            }
            if (event.target === authModal) {
                closeFinancialAuthModal();
            }
        };

        // Auto-submit do formulário de pesquisa com delay
        const searchInput = document.querySelector('.search-input');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    const form = this.closest('form');
                    if (form) form.submit();
                }, 800); // Delay de 800ms
            });
        }

        // Animação das linhas da tabela
        const tableRows = document.querySelectorAll('tbody tr');
        tableRows.forEach((row, index) => {
            row.style.opacity = '0';
            row.style.transform = 'translateY(20px)';
            setTimeout(() => {
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '1';
                row.style.transform = 'translateY(0)';
            }, index * 50);
        });

        // Efeito de entrada nos cards de resumo
        const cards = document.querySelectorAll('.summary-card');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            setTimeout(() => {
                card.style.transition = 'all 0.4s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100 + 200);
        });

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

        // Adiciona animações CSS dinamicamente
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

        // Auto-foco na pesquisa se não há termo
        if (searchInput && !searchInput.value) {
            searchInput.focus();
        }

        // Contador de tentativas de senha inválida
        let invalidPasswordAttempts = 0;
        const maxAttempts = 3;

        // Função modificada para rastrear tentativas
        function showAuthErrorWithAttempts(message) {
            invalidPasswordAttempts++;
            
            if (invalidPasswordAttempts >= maxAttempts) {
                showAuthError(`Muitas tentativas inválidas. Tente novamente em alguns segundos.`);
                
                // Bloqueia temporariamente
                document.getElementById('confirmAuthBtn').disabled = true;
                setTimeout(() => {
                    document.getElementById('confirmAuthBtn').disabled = false;
                    invalidPasswordAttempts = 0;
                }, 5000);
            } else {
                showAuthError(`${message} (${invalidPasswordAttempts}/${maxAttempts} tentativas)`);
            }
        }

        // Segurança adicional: limpa campo de senha quando modal é fechado
        const authModal = document.getElementById('financialAuthModal');
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                    if (authModal.style.display === 'none') {
                        document.getElementById('financialPassword').value = '';
                        document.getElementById('paymentDate').value = '';
                        document.getElementById('authError').style.display = 'none';
                        document.getElementById('paymentDateGroup').style.display = 'none';
                        invalidPasswordAttempts = 0;
                    }
                }
            });
        });

        observer.observe(authModal, { attributes: true });

        // Validação em tempo real do campo de senha
        document.getElementById('financialPassword').addEventListener('input', function() {
            const authError = document.getElementById('authError');
            if (authError.style.display === 'block') {
                authError.style.display = 'none';
            }
        });

        // Performance: lazy loading para tabelas grandes
        const tableRowsForLazy = document.querySelectorAll('tbody tr');
        if (tableRowsForLazy.length > 50) {
            console.log('📊 Tabela grande detectada. Implementando otimizações de performance...');
            
            tableRowsForLazy.forEach((row, index) => {
                if (index > 20) {
                    row.style.display = 'none';
                    row.dataset.lazyLoad = 'true';
                }
            });
            
            let visibleRows = 20;
            const loadMoreRows = () => {
                const hiddenRows = document.querySelectorAll('tr[data-lazy-load="true"]');
                for (let i = 0; i < Math.min(10, hiddenRows.length); i++) {
                    hiddenRows[i].style.display = '';
                    hiddenRows[i].removeAttribute('data-lazy-load');
                }
                visibleRows += 10;
            };
            
            window.addEventListener('scroll', () => {
                if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 1000) {
                    loadMoreRows();
                }
            });
        }

        // Adiciona tooltips aos elementos com indicadores visuais
        document.querySelectorAll('.urgent-payment').forEach(row => {
            row.title = 'Pagamento urgente - Mais de 30 dias desde a compra';
        });

        document.querySelectorAll('.due-soon').forEach(row => {
            row.title = 'Atenção - Mais de 15 dias desde a compra';
        });

        // Destaca contas críticas
        setTimeout(() => {
            const urgentRows = document.querySelectorAll('.urgent-payment');
            urgentRows.forEach((row, index) => {
                setTimeout(() => {
                    row.style.animation = 'pulse 2s infinite';
                }, index * 100);
            });
        }, 1000);

        // Adiciona animação de pulse para contas urgentes
        const pulseStyle = document.createElement('style');
        pulseStyle.textContent = `
            @keyframes pulse {
                0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
                70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
                100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
            }
        `;
        document.head.appendChild(pulseStyle);

        // Adiciona suporte a atalhos de teclado
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + F para focar na pesquisa
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('.search-input');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }
            
            // Ctrl/Cmd + N para nova compra
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                <?php if ($permissionManager->hasPagePermission('compras', 'create')): ?>
                window.location.href = 'cadastro_compras.php';
                <?php endif; ?>
            }
        });

        // Feedback visual ao salvar (apenas para selects editáveis)
        const editableSelects = document.querySelectorAll('.status-select');
        if (editableSelects.length > 0) {
            editableSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.style.background = '#fff3cd';
                    this.style.borderColor = '#ffc107';
                    
                    setTimeout(() => {
                        this.style.background = '';
                        this.style.borderColor = '';
                    }, 2000);
                });
            });
        }

        // Scrollbar personalizada para elementos que precisam
        const scrollableElements = document.querySelectorAll('.table-responsive, .modal-body, .financial-auth-body');
        scrollableElements.forEach(element => {
            element.style.scrollbarWidth = 'thin';
            element.style.scrollbarColor = 'var(--medium-gray) var(--light-gray)';
        });

        // Sistema de notificações para contas urgentes
        const urgentCount = document.querySelectorAll('.urgent-payment').length;
        const dueSoonCount = document.querySelectorAll('.due-soon').length;
        
        if (urgentCount > 0 || dueSoonCount > 0) {
            console.log(`⚠️ Atenção! ${urgentCount} conta(s) urgente(s) e ${dueSoonCount} conta(s) com atenção necessária`);
            
            // Mostra notificação visual se há contas urgentes
            if (urgentCount > 0) {
                setTimeout(() => {
                    const notification = document.createElement('div');
                    notification.className = 'alert alert-danger';
                    notification.innerHTML = `
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Atenção!</strong> Você tem ${urgentCount} conta(s) a pagar com mais de 30 dias.
                        <button onclick="this.parentElement.remove()" style="float: right; background: none; border: none; color: inherit; font-size: 1.2rem; cursor: pointer;">&times;</button>
                    `;
                    notification.style.position = 'fixed';
                    notification.style.top = '20px';
                    notification.style.left = '50%';
                    notification.style.transform = 'translateX(-50%)';
                    notification.style.zIndex = '4000';
                    notification.style.minWidth = '400px';
                    notification.style.animation = 'slideInDown 0.5s ease';
                    
                    document.body.appendChild(notification);
                    
                    // Remove automaticamente após 10 segundos
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.style.animation = 'slideInUp 0.5s ease reverse';
                            setTimeout(() => {
                                if (notification.parentNode) {
                                    notification.parentNode.removeChild(notification);
                                }
                            }, 500);
                        }
                    }, 10000);
                }, 2000);
            }
        }

        console.log('✅ Sistema de Contas a Pagar completamente inicializado');
        console.log('🔐 Sistema de autenticação financeira ativo');
        console.log('📊 Estatísticas:');
        console.log(`   - Total geral: R$ <?php echo number_format($totalGeralPagar ?? 0, 2, ",", "."); ?>`);
        console.log(`   - Pendentes: R$ <?php echo number_format($totalPendente ?? 0, 2, ",", "."); ?>`);
        console.log(`   - Pagas: R$ <?php echo number_format($totalPago ?? 0, 2, ",", "."); ?>`);
        console.log(`   - Contas urgentes: ${urgentCount}`);
        console.log(`   - Contas com atenção: ${dueSoonCount}`);
    });
</script>

</body>
</html>

<?php
// Log de conclusão bem-sucedida
error_log("LicitaSis - Página de Contas a Pagar carregada com sucesso");
error_log("LicitaSis - Permissões ativas: " . ($permissionManager ? 'Sistema completo' : 'Sistema básico'));
error_log("LicitaSis - Total de contas exibidas: " . count($contas_a_pagar));
error_log("LicitaSis - Total geral a pagar: R$ " . number_format($totalGeralPagar ?? 0, 2, ',', '.'));
error_log("LicitaSis - Sistema de autenticação financeira: ATIVO");
error_log("LicitaSis - MELHORADO: Campo de data de pagamento no modal de confirmação");
?>