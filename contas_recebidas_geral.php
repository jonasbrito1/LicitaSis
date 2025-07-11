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

// Verifica se o usuário tem permissão para acessar contas recebidas
$permissionManager->requirePermission('financeiro', 'view');

$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";
$contas_recebidas = [];
$searchTerm = "";
$totalContas = 0;
$contasPorPagina = 20;
$paginaAtual = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($paginaAtual - 1) * $contasPorPagina;

require_once('db.php');

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
    $senha_financeiro = $_POST['financial_password'] ?? '';

    if ($id > 0 && in_array($novo_status, ['Não Recebido', 'Recebido'])) {
        // Se está alterando para "Não Recebido", valida a senha do setor financeiro
        if ($novo_status === 'Não Recebido') {
            $senha_padrao = 'Licitasis@2025';
            if ($senha_financeiro !== $senha_padrao) {
                echo json_encode(['success' => false, 'error' => 'Senha do setor financeiro incorreta']);
                exit();
            }
        }

        try {
            // Busca dados antigos para auditoria
            $oldDataStmt = $pdo->prepare("SELECT * FROM vendas WHERE id = :id");
            $oldDataStmt->bindParam(':id', $id);
            $oldDataStmt->execute();
            $oldData = $oldDataStmt->fetch(PDO::FETCH_ASSOC);
            
            $sql = "UPDATE vendas SET status_pagamento = :status WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':status', $novo_status);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            // Busca dados novos para auditoria
            $newDataStmt = $pdo->prepare("SELECT * FROM vendas WHERE id = :id");
            $newDataStmt->bindParam(':id', $id);
            $newDataStmt->execute();
            $newData = $newDataStmt->fetch(PDO::FETCH_ASSOC);
            
            // Registra auditoria com informação adicional sobre autorização financeira
            $auditInfo = $newData;
            if ($novo_status === 'Não Recebido') {
                $auditInfo['financial_auth'] = 'Autorizado com senha do setor financeiro para reverter recebimento';
                $auditInfo['authorized_by'] = $_SESSION['user']['id'];
                $auditInfo['authorization_time'] = date('Y-m-d H:i:s');
            }
            
            logAudit($pdo, $_SESSION['user']['id'], 'UPDATE', 'vendas', $id, $auditInfo, $oldData);
            
            echo json_encode(['success' => true, 'message' => 'Status atualizado com sucesso']);
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

// Busca com filtro de pesquisa e paginação
try {
    if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
        $searchTerm = trim($_GET['search']);
        
        // Conta total de resultados
        $countSql = "SELECT COUNT(*) as total FROM vendas v
                     LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
                     WHERE v.status_pagamento = 'Recebido'
                     AND (v.nf LIKE :searchTerm OR c.nome_orgaos LIKE :searchTerm OR v.cliente_uasg LIKE :searchTerm)";
        $countStmt = $pdo->prepare($countSql);
        $searchParam = "%$searchTerm%";
        $countStmt->bindValue(':searchTerm', $searchParam);
        $countStmt->execute();
        $totalContas = $countStmt->fetch()['total'];
        
        // Busca contas com paginação
        $sql = "SELECT v.id, v.nf, v.cliente_uasg, c.nome_orgaos as cliente_nome, v.valor_total, v.status_pagamento, v.data_vencimento 
                FROM vendas v
                LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
                WHERE v.status_pagamento = 'Recebido'
                AND (v.nf LIKE :searchTerm OR c.nome_orgaos LIKE :searchTerm OR v.cliente_uasg LIKE :searchTerm)
                ORDER BY v.data_vencimento DESC, v.nf ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', $searchParam);
        $stmt->bindValue(':limit', $contasPorPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $contas_recebidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Conta total de contas recebidas
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM vendas WHERE status_pagamento = 'Recebido'");
        $totalContas = $countStmt->fetch()['total'];
        
        // Busca todas as contas com paginação
        $sql = "SELECT v.id, v.nf, v.cliente_uasg, c.nome_orgaos as cliente_nome, v.valor_total, v.status_pagamento, v.data_vencimento 
                FROM vendas v
                LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
                WHERE v.status_pagamento = 'Recebido'
                ORDER BY v.data_vencimento DESC, v.nf ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $contasPorPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $contas_recebidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error = "Erro ao buscar contas recebidas: " . $e->getMessage();
}

// Calcula informações de paginação
$totalPaginas = ceil($totalContas / $contasPorPagina);

// Endpoint AJAX para buscar detalhes da venda
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

// Calcula total geral recebido
try {
    $sqlTotal = "SELECT SUM(valor_total) AS total_geral FROM vendas WHERE status_pagamento = 'Recebido'";
    $stmtTotal = $pdo->prepare($sqlTotal);
    $stmtTotal->execute();
    $totalGeralRecebidas = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total_geral'];
} catch (PDOException $e) {
    $error = "Erro ao calcular o total de contas recebidas: " . $e->getMessage();
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
startPage("Contas Recebidas - LicitaSis", "financeiro");
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
    .received-container {
        max-width: 1400px;
        margin: 0 auto;
    }

    /* Header da página */
    .page-header {
        background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
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

    /* Total destacado */
    .total-card {
        background: linear-gradient(135deg, var(--info-color) 0%, #138496 100%);
        color: white;
        padding: 1.5rem 2rem;
        border-radius: var(--radius);
        margin-bottom: 2rem;
        text-align: center;
        box-shadow: var(--shadow);
        position: relative;
        overflow: hidden;
    }

    .total-card::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 100%;
        height: 100%;
        background: rgba(255,255,255,0.1);
        border-radius: 50%;
        transform: rotate(-45deg);
    }

    .total-amount {
        font-size: 2.5rem;
        font-weight: 800;
        margin-bottom: 0.5rem;
        position: relative;
        z-index: 1;
        font-family: 'Courier New', monospace;
    }

    .total-label {
        font-size: 1.1rem;
        opacity: 0.9;
        position: relative;
        z-index: 1;
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
        color: var(--success-color);
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
        background: var(--success-color);
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
        color: var(--success-color);
        text-decoration: none;
        font-weight: 600;
        transition: var(--transition);
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
    }

    .status-select:hover, .status-select:focus {
        border-color: var(--primary-color);
        background-color: white;
        outline: none;
        box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.1);
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
        background: var(--success-color);
        color: white;
        border-color: var(--success-color);
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
        max-width: 900px;
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
        background: linear-gradient(135deg, var(--success-color), var(--info-color));
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

    .confirmation-buttons {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 1.5rem;
    }

    .btn-confirm {
        background: var(--danger-color);
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: var(--radius);
        cursor: pointer;
        font-weight: 600;
        transition: var(--transition);
    }

    .btn-confirm:hover {
        background: #c82333;
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
        border-top: 5px solid var(--danger-color);
    }

    .financial-auth-header {
        background: linear-gradient(135deg, var(--danger-color), #ff4757);
        color: white;
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
        color: white;
    }

    .financial-auth-header .security-icon {
        font-size: 1.5rem;
        color: white;
    }

    .financial-auth-body {
        padding: 2rem;
    }

    .security-notice {
        background: linear-gradient(135deg, #f8d7da, #f5c6cb);
        border: 2px solid var(--danger-color);
        border-radius: var(--radius);
        padding: 1rem;
        margin-bottom: 1.5rem;
        text-align: center;
        position: relative;
    }

    .security-notice::before {
        content: '\f071';
        font-family: 'Font Awesome 5 Free';
        font-weight: 900;
        position: absolute;
        top: -10px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--danger-color);
        color: white;
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
        color: var(--danger-color);
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
        color: var(--danger-color);
        margin-bottom: 0.5rem;
        display: block;
        font-size: 0.95rem;
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
        border-color: var(--danger-color);
        box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
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
        color: var(--danger-color);
    }

    .auth-buttons {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 1.5rem;
    }

    .btn-auth-confirm {
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

    .btn-auth-confirm:hover:not(:disabled) {
        background: #c82333;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
    }

    .btn-auth-confirm:disabled {
        background: var(--medium-gray);
        cursor: not-allowed;
        opacity: 0.6;
    }

    .btn-auth-cancel {
        background: var(--medium-gray);
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
        background: #5a6268;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
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

    /* Estados da tabela */
    .status-nao-recebido {
        background: rgba(220, 53, 69, 0.1);
        color: var(--danger-color);
        font-weight: 600;
    }

    .status-recebido {
        background: rgba(40, 167, 69, 0.1);
        color: var(--success-color);
        font-weight: 600;
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

    .status-badge.status-nao-recebido {
        background: rgba(220, 53, 69, 0.1);
        color: var(--danger-color);
        border: 1px solid rgba(220, 53, 69, 0.3);
    }

    .status-badge.status-recebido {
        background: rgba(40, 167, 69, 0.1);
        color: var(--success-color);
        border: 1px solid rgba(40, 167, 69, 0.3);
    }

    /* Indicadores visuais para contas recebidas */
    .received-indicator {
        background: rgba(40, 167, 69, 0.05);
        border-left: 4px solid var(--success-color);
    }

    .recently-received {
        background: rgba(23, 162, 184, 0.05);
        border-left: 4px solid var(--info-color);
    }

    /* Responsividade */
    @media (max-width: 1200px) {
        .received-container {
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

        .total-amount {
            font-size: 2rem;
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

        .total-amount {
            font-size: 1.8rem;
        }

        .controls-bar, .results-info, .total-card {
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

    .table-container, .controls-bar, .results-info, .total-card {
        animation: fadeInUp 0.6s ease forwards;
    }

    .table-container { animation-delay: 0.2s; }
    .controls-bar { animation-delay: 0.05s; }
    .results-info { animation-delay: 0.15s; }
    .total-card { animation-delay: 0.1s; }

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
</style>

<!-- Container principal com layout padrão do sistema -->
<div class="main-content">
    <div class="container received-container">
        
        <!-- Header da página -->
        <div class="page-header">
            <h1><i class="fas fa-check-circle"></i> Contas Recebidas</h1>
            <p>Visualize e gerencie todas as contas que já foram recebidas</p>
        </div>

        <!-- Card do total geral -->
        <?php if (isset($totalGeralRecebidas) && $totalGeralRecebidas > 0): ?>
            <div class="total-card">
                <div class="total-amount">
                    R$ <?php echo number_format($totalGeralRecebidas, 2, ',', '.'); ?>
                </div>
                <div class="total-label">
                    <i class="fas fa-coins"></i> Total Geral de Contas Recebidas
                </div>
            </div>
        <?php endif; ?>

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
            <form class="search-form" action="contas_recebidas_geral.php" method="GET">
                <input type="text" 
                       name="search" 
                       class="search-input"
                       placeholder="Pesquisar por NF, Cliente ou UASG..." 
                       value="<?php echo htmlspecialchars($searchTerm); ?>"
                       autocomplete="off">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Pesquisar
                </button>
                <?php if ($searchTerm): ?>
                    <a href="contas_recebidas_geral.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpar
                    </a>
                <?php endif; ?>
            </form>
            
            <a href="contas_a_receber.php" class="btn btn-warning">
                <i class="fas fa-clock"></i> Contas Pendentes
            </a>
        </div>

        <!-- Informações de resultados -->
        <?php if ($totalContas > 0): ?>
            <div class="results-info">
                <div class="results-count">
                    <?php if ($searchTerm): ?>
                        Encontradas <strong><?php echo $totalContas; ?></strong> conta(s) recebida(s) 
                        para "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>"
                    <?php else: ?>
                        Total de <strong><?php echo $totalContas; ?></strong> conta(s) recebida(s)
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

        <!-- Tabela de contas recebidas -->
        <?php if (count($contas_recebidas) > 0): ?>
            <div class="table-container">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fas fa-file-invoice"></i> NF</th>
                                <th><i class="fas fa-building"></i> Cliente</th>
                                <th><i class="fas fa-dollar-sign"></i> Valor Total</th>
                                <th><i class="fas fa-tasks"></i> Status</th>
                                <th><i class="fas fa-calendar"></i> Data Vencimento</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contas_recebidas as $conta): 
                                $dataVencimento = new DateTime($conta['data_vencimento']);
                                $hoje = new DateTime();
                                $diasDesdeVencimento = $hoje->diff($dataVencimento)->days;
                                $recebidaRecentemente = $diasDesdeVencimento <= 30; // Consideramos recente se foi nos últimos 30 dias
                                
                                $rowClass = $recebidaRecentemente ? 'recently-received' : 'received-indicator';
                            ?>
                                <tr data-id="<?php echo $conta['id']; ?>" class="<?php echo $rowClass; ?>">
                                    <td>
                                        <a href="javascript:void(0);" 
                                           onclick="openModal(<?php echo $conta['id']; ?>)" 
                                           class="nf-link">
                                            <?php echo htmlspecialchars($conta['nf']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($conta['cliente_nome'] ?: 'Cliente não encontrado'); ?></td>
                                    <td class="font-weight-bold">R$ <?php echo number_format($conta['valor_total'], 2, ',', '.'); ?></td>
                                    <td>
                                        <?php if ($permissionManager->hasPagePermission('financeiro', 'edit')): ?>
                                            <select class="status-select" 
                                                    data-id="<?php echo $conta['id']; ?>" 
                                                    data-nf="<?php echo htmlspecialchars($conta['nf']); ?>"
                                                    data-cliente="<?php echo htmlspecialchars($conta['cliente_nome'] ?: 'Cliente não encontrado'); ?>"
                                                    data-valor="<?php echo number_format($conta['valor_total'], 2, ',', '.'); ?>"
                                                    data-vencimento="<?php echo $dataVencimento->format('d/m/Y'); ?>">
                                                <option value="Não Recebido" <?php if ($conta['status_pagamento'] === 'Não Recebido') echo 'selected'; ?>>Não Recebido</option>
                                                <option value="Recebido" <?php if ($conta['status_pagamento'] === 'Recebido') echo 'selected'; ?>>Recebido</option>
                                            </select>
                                        <?php else: ?>
                                            <span class="status-badge status-recebido">
                                                <i class="fas fa-check-circle"></i> Recebido
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $dataVencimento->format('d/m/Y'); ?>
                                        <?php if ($recebidaRecentemente): ?>
                                            <span style="color: var(--info-color); font-size: 0.8rem; display: block;">
                                                <i class="fas fa-clock"></i> Recebida recentemente
                                            </span>
                                        <?php endif; ?>
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
                <i class="fas fa-check-circle"></i>
                <h3>Nenhuma conta recebida encontrada</h3>
                <?php if ($searchTerm): ?>
                    <p>Não foram encontradas contas recebidas com os termos de busca "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>".</p>
                    <a href="contas_recebidas_geral.php" class="btn btn-primary">
                        <i class="fas fa-list"></i> Ver Todas as Contas Recebidas
                    </a>
                <?php else: ?>
                    <p>Ainda não há contas recebidas no sistema.</p>
                    <a href="contas_a_receber.php" class="btn btn-warning">
                        <i class="fas fa-clock"></i> Ver Contas Pendentes
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- Modal de Detalhes da Conta -->
<div id="editModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="fas fa-check-circle"></i> Detalhes da Conta Recebida</h3>
            <span class="modal-close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="vendaForm">
                <input type="hidden" name="id" id="venda_id" />
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="nf"><i class="fas fa-file-invoice"></i> Nota Fiscal</label>
                        <input type="text" name="nf" id="nf" class="form-control" readonly />
                    </div>
                    <div class="form-group">
                        <label for="cliente_uasg"><i class="fas fa-hashtag"></i> UASG</label>
                        <input type="text" name="cliente_uasg" id="cliente_uasg" class="form-control" readonly />
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="cliente_nome"><i class="fas fa-building"></i> Nome do Cliente</label>
                        <input type="text" name="cliente_nome" id="cliente_nome" class="form-control" readonly />
                    </div>
                    <div class="form-group">
                        <label for="valor_total"><i class="fas fa-dollar-sign"></i> Valor Total</label>
                        <input type="text" name="valor_total" id="valor_total" class="form-control" readonly />
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="status_pagamento"><i class="fas fa-tasks"></i> Status de Pagamento</label>
                        <input type="text" name="status_pagamento" id="status_pagamento" class="form-control" readonly />
                    </div>
                    <div class="form-group">
                        <label for="data_vencimento"><i class="fas fa-calendar"></i> Data de Vencimento</label>
                        <input type="text" name="data_vencimento" id="data_vencimento" class="form-control" readonly />
                    </div>
                </div>

                <div style="text-align: center; margin-top: 2rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal de Confirmação para alteração para Não Recebido -->
<div id="confirmationModal" class="confirmation-modal" role="dialog" aria-modal="true">
    <div class="confirmation-modal-content">
        <h3><i class="fas fa-exclamation-triangle"></i> Confirmar Reversão de Recebimento</h3>
        <p>Deseja realmente alterar esta conta para <strong>NÃO RECEBIDA</strong>?</p>
        
        <div class="confirmation-info">
            <p><strong>NF:</strong> <span id="confirm-nf"></span></p>
            <p><strong>Cliente:</strong> <span id="confirm-cliente"></span></p>
            <p><strong>Valor:</strong> R$ <span id="confirm-valor"></span></p>
            <p><strong>Vencimento:</strong> <span id="confirm-vencimento"></span></p>
        </div>
        
        <p style="color: var(--danger-color); font-size: 0.9rem; margin-top: 1rem;">
            <i class="fas fa-exclamation-triangle"></i> Esta conta será removida da lista de contas recebidas e retornará para a lista de contas a receber. Esta ação requer autenticação do setor financeiro.
        </p>
        
        <div class="confirmation-buttons">
            <button type="button" class="btn-cancel" onclick="closeConfirmationModal()">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="button" class="btn-confirm" onclick="requestFinancialAuth()">
                <i class="fas fa-undo"></i> Reverter Recebimento
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
                <p>Para reverter o recebimento de uma conta, é necessário inserir a senha do setor financeiro por questões de segurança.</p>
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

    document.addEventListener('DOMContentLoaded', function() {
        // Função para abrir o modal e carregar os dados da venda
        window.openModal = function(id) {
            const modal = document.getElementById("editModal");
            modal.style.display = "block";
            document.body.style.overflow = 'hidden';

            fetch('contas_recebidas_geral.php?get_venda_id=' + id)
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
                    console.error('Erro ao carregar dados:', error);
                    alert('Erro ao carregar os detalhes da conta recebida: ' + error.message);
                    closeModal();
                });
        };

        // Função para fechar o modal
        window.closeModal = function() {
            const modal = document.getElementById("editModal");
            modal.style.display = "none";
            document.body.style.overflow = 'auto';
        };

        // Função para abrir modal de confirmação
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
            document.body.style.overflow = 'hidden';
        }

        // Função para fechar modal de confirmação
        window.closeConfirmationModal = function() {
            // Volta o select para o valor anterior
            if (currentSelectElement) {
                currentSelectElement.value = 'Recebido';
            }
            
            document.getElementById('confirmationModal').style.display = 'none';
            document.body.style.overflow = 'auto';
            currentSelectElement = null;
            currentContaData = {};
        };

        // Função para solicitar autenticação financeira
        window.requestFinancialAuth = function() {
            document.getElementById('confirmationModal').style.display = 'none';
            document.getElementById('financialAuthModal').style.display = 'block';
            
            // Foca no campo de senha
            setTimeout(() => {
                document.getElementById('financialPassword').focus();
            }, 300);
        };

        // Função para fechar modal de autenticação financeira
        window.closeFinancialAuthModal = function() {
            document.getElementById('financialAuthModal').style.display = 'none';
            document.getElementById('financialPassword').value = '';
            document.getElementById('authError').style.display = 'none';
            document.body.style.overflow = 'auto';
            
            // Reseta o select para o valor original
            if (currentSelectElement) {
                currentSelectElement.value = 'Recebido';
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
            const confirmBtn = document.getElementById('confirmAuthBtn');
            const loadingSpinner = document.getElementById('authLoadingSpinner');
            const confirmIcon = document.getElementById('authConfirmIcon');
            const confirmText = document.getElementById('authConfirmText');
            const authError = document.getElementById('authError');
            
            if (!senha) {
                showAuthError('Por favor, digite a senha do setor financeiro.');
                return;
            }

            // Mostra loading
            confirmBtn.disabled = true;
            loadingSpinner.style.display = 'inline-block';
            confirmIcon.style.display = 'none';
            confirmText.textContent = 'Verificando...';
            authError.style.display = 'none';

            // Processa a atualização do status com autenticação
            updateStatusWithAuth(currentContaData.id, 'Não Recebido', senha);
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
            fetch('contas_recebidas_geral.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    update_status: '1',
                    id: id,
                    status_pagamento: status,
                    financial_password: senha
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erro HTTP: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                resetAuthButton();
                
                if (data.success) {
                    // Sucesso - remove a linha da tabela
                    const row = document.querySelector(`tr[data-id='${id}']`);
                    if (row) {
                        row.style.transition = 'all 0.5s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(-100%)';
                        setTimeout(() => row.remove(), 500);
                    }
                    
                    closeFinancialAuthModal();
                    showSuccessMessage('Conta alterada para "Não Recebida" com sucesso!');
                    
                    // Atualiza a página se não houver mais registros
                    setTimeout(() => {
                        const remainingRows = document.querySelectorAll('tbody tr').length;
                        if (remainingRows <= 1) {
                            window.location.reload();
                        }
                    }, 1000);
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
        <?php endif; ?>

        // Função para atualizar status diretamente (sem autenticação)
        function updateStatus(id, status, selectElement) {
            fetch('contas_recebidas_geral.php', {
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
                    showSuccessMessage('Status atualizado com sucesso!');
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

        // Enter para confirmar senha no modal de autenticação
        document.getElementById('financialPassword').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                confirmFinancialAuth();
            }
        });

        // Fecha modais ao clicar fora
        window.onclick = function(event) {
            const modal = document.getElementById("editModal");
            const confirmModal = document.getElementById("confirmationModal");
            const authModal = document.getElementById("financialAuthModal");
            
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

        // Tecla ESC para fechar modais
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
                closeConfirmationModal();
                closeFinancialAuthModal();
            }
        });

        // Auto-foco na pesquisa
        const searchInput = document.querySelector('.search-input');
        if (searchInput && !searchInput.value) {
            searchInput.focus();
        }

        // Animação das linhas da tabela
        const tableRows = document.querySelectorAll('tbody tr');
        tableRows.forEach((row, index) => {
            row.style.opacity = '0';
            row.style.transform = 'translateY(20px)';
            setTimeout(() => {
                row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                row.style.opacity = '1';
                row.style.transform = 'translateY(0)';
            }, index * 50);
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

        // Log de inicialização
        console.log('Contas Recebidas carregadas com sucesso!');
        console.log('Total de contas: <?php echo $totalContas; ?>');
        console.log('Página atual: <?php echo $paginaAtual; ?> de <?php echo $totalPaginas; ?>');
        <?php if (isset($totalGeralRecebidas)): ?>
        console.log('Total geral recebido: R$ <?php echo number_format($totalGeralRecebidas, 2, ",", "."); ?>');
        <?php endif; ?>
        console.log('Sistema de autenticação financeira ativo para reversão de recebimentos');

        // Adiciona tooltips aos elementos com indicadores visuais
        document.querySelectorAll('.received-indicator').forEach(row => {
            row.title = 'Conta recebida';
        });

        document.querySelectorAll('.recently-received').forEach(row => {
            row.title = 'Conta recebida recentemente';
        });

        // Atualização automática do status visual dos selects (apenas se existem)
        const statusSelects = document.querySelectorAll('.status-select');
        if (statusSelects.length > 0) {
            statusSelects.forEach(select => {
                const updateSelectStyle = () => {
                    if (select.value === 'Recebido') {
                        select.className = 'status-select status-recebido';
                    } else {
                        select.className = 'status-select status-nao-recebido';
                    }
                };
                
                updateSelectStyle();
                select.addEventListener('change', updateSelectStyle);
            });
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
                        document.getElementById('authError').style.display = 'none';
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
            
            // Ctrl/Cmd + R para contas a receber
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                window.location.href = 'contas_a_receber.php';
            }
        });

        // Feedback visual ao salvar (apenas para selects editáveis)
        const editableSelects = document.querySelectorAll('.status-select');
        if (editableSelects.length > 0) {
            editableSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.style.background = '#f8d7da';
                    this.style.borderColor = '#dc3545';
                    
                    setTimeout(() => {
                        this.style.background = '';
                        this.style.borderColor = '';
                    }, 2000);
                });
            });
        }

        // Performance: lazy loading para tabelas grandes
        if (tableRows.length > 50) {
            console.log('Tabela grande detectada. Implementando otimizações de performance...');
            
            tableRows.forEach((row, index) => {
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

        // Destaca contas recebidas recentemente
        setTimeout(() => {
            const recentRows = document.querySelectorAll('.recently-received');
            recentRows.forEach((row, index) => {
                setTimeout(() => {
                    row.style.animation = 'glow 2s ease-in-out infinite alternate';
                }, index * 100);
            });
        }, 1000);

        // Adiciona animação de brilho para contas recentes
        const glowStyle = document.createElement('style');
        glowStyle.textContent = `
            @keyframes glow {
                from { box-shadow: 0 0 5px rgba(23, 162, 184, 0.3); }
                to { box-shadow: 0 0 15px rgba(23, 162, 184, 0.6); }
            }
        `;
        document.head.appendChild(glowStyle);

        // Estatísticas de segurança
        console.log('Sistema de segurança financeira inicializado para reversão de recebimentos');
        console.log('Autenticação requerida para alterações de status para "Não Recebido"');
    });
</script>

</body>
</html>