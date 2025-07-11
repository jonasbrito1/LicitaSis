<?php
session_start();

// Verifica se o usuário está logado
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

// Verifica se o usuário tem permissão para acessar clientes
$permissionManager->requirePermission('clientes', 'view');

// Inicializa variáveis
$error = "";
$success = "";
$clientes = [];
$searchTerm = "";
$totalClientes = 0;
$clientesPorPagina = 20;
$paginaAtual = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($paginaAtual - 1) * $clientesPorPagina;

// NOVOS PARÂMETROS DE ORDENAÇÃO E FILTROS
$orderBy = isset($_GET['order_by']) ? $_GET['order_by'] : 'nome_orgaos';
$orderDir = isset($_GET['order_dir']) && $_GET['order_dir'] === 'desc' ? 'DESC' : 'ASC';
$filterCnpj = isset($_GET['filter_cnpj']) ? trim($_GET['filter_cnpj']) : '';
$filterEmail = isset($_GET['filter_email']) ? trim($_GET['filter_email']) : '';
$filterDate = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';

// Campos permitidos para ordenação
$allowedOrderFields = ['id', 'uasg', 'nome_orgaos', 'cnpj', 'email', 'telefone', 'endereco', 'created_at', 'updated_at'];
if (!in_array($orderBy, $allowedOrderFields)) {
    $orderBy = 'nome_orgaos';
}

// Processa exclusão de cliente
if (isset($_GET['delete_client_id']) && $permissionManager->hasPagePermission('clientes', 'delete')) {
    $id = (int)$_GET['delete_client_id'];
    try {
        // Verifica se o cliente tem vendas ou empenhos associados
        $checkSql = "SELECT 
                        (SELECT COUNT(*) FROM vendas WHERE cliente_uasg = (SELECT uasg FROM clientes WHERE id = :id)) as vendas,
                        (SELECT COUNT(*) FROM empenhos WHERE cliente_uasg = (SELECT uasg FROM clientes WHERE id = :id)) as empenhos";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->bindParam(':id', $id);
        $checkStmt->execute();
        $dependencies = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($dependencies['vendas'] > 0 || $dependencies['empenhos'] > 0) {
            $error = "Não é possível excluir este cliente pois existem vendas ou empenhos associados.";
        } else {
            // Busca dados do cliente para auditoria
            $clienteStmt = $pdo->prepare("SELECT * FROM clientes WHERE id = :id");
            $clienteStmt->bindParam(':id', $id);
            $clienteStmt->execute();
            $clienteData = $clienteStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($clienteData) {
                // Exclui o cliente
                $sql = "DELETE FROM clientes WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':id', $id);
                $stmt->execute();
                
                // Registra auditoria
                logAudit($pdo, $_SESSION['user']['id'], 'DELETE', 'clientes', $id, null, $clienteData);
                
                $success = "Cliente excluído com sucesso!";
                header("Location: consultar_clientes.php?success=" . urlencode($success));
                exit();
            } else {
                $error = "Cliente não encontrado.";
            }
        }
    } catch (PDOException $e) {
        $error = "Erro ao excluir o cliente: " . $e->getMessage();
        error_log("Erro ao excluir cliente ID $id: " . $e->getMessage());
    }
}

// Processa atualização de cliente
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_client']) && $permissionManager->hasPagePermission('clientes', 'edit')) {
    $id = (int)$_POST['id'];
    $uasg = trim($_POST['uasg']);
    $cnpj = trim($_POST['cnpj']);
    $nome_orgaos = trim($_POST['nome_orgaos']);
    $endereco = trim($_POST['endereco']);
    $telefones = array_filter($_POST['telefone'], 'trim'); // Remove telefones vazios
    $telefone = implode('/', $telefones);
    $email = trim($_POST['email']);
    $observacoes = trim($_POST['observacoes']);

    // Validações
    if (empty($uasg) || empty($nome_orgaos)) {
        $error = "UASG e Nome do Órgão são campos obrigatórios.";
    } else {
        try {
            // Verifica se já existe outro cliente com mesmo UASG
            $checkStmt = $pdo->prepare("SELECT id FROM clientes WHERE uasg = :uasg AND id != :id");
            $checkStmt->bindParam(':uasg', $uasg);
            $checkStmt->bindParam(':id', $id);
            $checkStmt->execute();
            
            if ($checkStmt->fetch()) {
                $error = "Já existe um cliente cadastrado com esta UASG.";
            } else {
                // Busca dados antigos para auditoria
                $oldDataStmt = $pdo->prepare("SELECT * FROM clientes WHERE id = :id");
                $oldDataStmt->bindParam(':id', $id);
                $oldDataStmt->execute();
                $oldData = $oldDataStmt->fetch(PDO::FETCH_ASSOC);
                
                // Atualiza o cliente
                $sql = "UPDATE clientes SET 
                        uasg = :uasg, 
                        cnpj = :cnpj, 
                        nome_orgaos = :nome_orgaos, 
                        endereco = :endereco, 
                        telefone = :telefone, 
                        email = :email, 
                        observacoes = :observacoes,
                        updated_at = NOW()
                        WHERE id = :id";
                
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':uasg', $uasg);
                $stmt->bindParam(':cnpj', $cnpj);
                $stmt->bindParam(':nome_orgaos', $nome_orgaos);
                $stmt->bindParam(':endereco', $endereco);
                $stmt->bindParam(':telefone', $telefone);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':observacoes', $observacoes);
                $stmt->bindParam(':id', $id);
                $stmt->execute();
                
                // Busca dados novos para auditoria
                $newDataStmt = $pdo->prepare("SELECT * FROM clientes WHERE id = :id");
                $newDataStmt->bindParam(':id', $id);
                $newDataStmt->execute();
                $newData = $newDataStmt->fetch(PDO::FETCH_ASSOC);
                
                // Registra auditoria
                logAudit($pdo, $_SESSION['user']['id'], 'UPDATE', 'clientes', $id, $newData, $oldData);
                
                $success = "Cliente atualizado com sucesso!";
                header("Location: consultar_clientes.php?success=" . urlencode($success));
                exit();
            }
        } catch (PDOException $e) {
            $error = "Erro ao atualizar o cliente: " . $e->getMessage();
            error_log("Erro ao atualizar cliente ID $id: " . $e->getMessage());
        }
    }
}

// NOVA LÓGICA DE BUSCA E FILTROS
try {
    $whereConditions = [];
    $params = [];
    
    // Busca geral
    if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
        $searchTerm = trim($_GET['search']);
        $whereConditions[] = "(nome_orgaos LIKE :searchTerm 
                             OR uasg LIKE :searchTerm 
                             OR cnpj LIKE :searchTerm 
                             OR email LIKE :searchTerm 
                             OR endereco LIKE :searchTerm 
                             OR telefone LIKE :searchTerm)";
        $params[':searchTerm'] = "%$searchTerm%";
    }
    
    // Filtro por CNPJ
    if (!empty($filterCnpj)) {
        $whereConditions[] = "cnpj LIKE :filterCnpj";
        $params[':filterCnpj'] = "%$filterCnpj%";
    }
    
    // Filtro por E-mail
    if (!empty($filterEmail)) {
        $whereConditions[] = "email LIKE :filterEmail";
        $params[':filterEmail'] = "%$filterEmail%";
    }
    
    // Filtro por data
    if (!empty($filterDate)) {
        switch ($filterDate) {
            case 'today':
                $whereConditions[] = "DATE(created_at) = CURDATE()";
                break;
            case 'week':
                $whereConditions[] = "created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
                break;
            case 'month':
                $whereConditions[] = "created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
                break;
            case 'year':
                $whereConditions[] = "created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
                break;
        }
    }
    
    $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
    
    // Conta total de resultados
    $countSql = "SELECT COUNT(*) as total FROM clientes $whereClause";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalClientes = $countStmt->fetch()['total'];
    
    // Busca clientes com paginação e ordenação
    $sql = "SELECT * FROM clientes $whereClause 
            ORDER BY $orderBy $orderDir 
            LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $clientesPorPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Erro ao buscar clientes: " . $e->getMessage();
    error_log("Erro na consulta de clientes: " . $e->getMessage());
}

// Calcula informações de paginação
$totalPaginas = ceil($totalClientes / $clientesPorPagina);

// Processa requisição AJAX para dados do cliente - ATUALIZADA
if (isset($_GET['get_cliente_id'])) {
    $id = (int)$_GET['get_cliente_id'];
    try {
        $sql = "SELECT * FROM clientes WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cliente) {
            // Busca estatísticas detalhadas do cliente específico
            $statsStmt = $pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM vendas WHERE cliente_uasg = :uasg) as total_vendas,
                    (SELECT COUNT(*) FROM empenhos WHERE cliente_uasg = :uasg) as total_empenhos,
                    (SELECT COALESCE(SUM(valor_total), 0) FROM vendas WHERE cliente_uasg = :uasg) as valor_vendas,
                    (SELECT COALESCE(SUM(valor_total_empenho), 0) FROM empenhos WHERE cliente_uasg = :uasg) as valor_empenhos,
                    (SELECT COUNT(DISTINCT numero) FROM vendas WHERE cliente_uasg = :uasg) as vendas_unicas,
                    (SELECT COUNT(DISTINCT numero) FROM empenhos WHERE cliente_uasg = :uasg) as empenhos_unicos,
                    (SELECT DATE(MAX(created_at)) FROM vendas WHERE cliente_uasg = :uasg) as ultima_venda,
                    (SELECT DATE(MAX(created_at)) FROM empenhos WHERE cliente_uasg = :uasg) as ultimo_empenho
            ");
            $statsStmt->bindParam(':uasg', $cliente['uasg']);
            $statsStmt->execute();
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
            
            $cliente['stats'] = $stats;
            echo json_encode($cliente);
        } else {
            echo json_encode(['error' => 'Cliente não encontrado']);
        }
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar cliente: ' . $e->getMessage()]);
        exit();
    }
}

// Processa mensagens de URL
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// FUNÇÕES AUXILIARES PARA ORDENAÇÃO
function buildUrl($newParams = []) {
    $currentParams = $_GET;
    unset($currentParams['page']); // Remove página atual
    $params = array_merge($currentParams, $newParams);
    return 'consultar_clientes.php?' . http_build_query($params);
}

function getSortUrl($field) {
    global $orderBy, $orderDir;
    $newDir = ($orderBy === $field && $orderDir === 'ASC') ? 'desc' : 'asc';
    return buildUrl(['order_by' => $field, 'order_dir' => $newDir]);
}

function getSortIcon($field) {
    global $orderBy, $orderDir;
    if ($orderBy === $field) {
        return $orderDir === 'ASC' ? 'fas fa-sort-up' : 'fas fa-sort-down';
    }
    return 'fas fa-sort';
}

// Inclui o template de header
include('includes/header_template.php');
startPage("Consultar Clientes - LicitaSis", "clientes");
?>

<style>
    /* Variáveis CSS */
    :root {
        --primary-color: #2D893E;
        --primary-light: #9DCEAC;
        --secondary-color: #00bfae;
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

    /* Container principal */
    .clients-container {
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
    }

    .search-input:focus {
        outline: none;
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
    }

    /* NOVOS ESTILOS PARA FILTROS */
    .filters-section {
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        margin-bottom: 1.5rem;
        overflow: hidden;
    }

    .filters-toggle {
        background: var(--light-gray);
        border: none;
        width: 100%;
        padding: 1rem 1.5rem;
        text-align: left;
        font-weight: 600;
        color: var(--primary-color);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: var(--transition);
    }

    .filters-toggle:hover {
        background: #e9ecef;
    }

    .filters-content {
        padding: 1.5rem;
        border-top: 1px solid var(--border-color);
        display: none;
    }

    .filters-content.show {
        display: block;
    }

    .filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .filter-group label {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--dark-gray);
    }

    .filter-input, .filter-select {
        padding: 0.75rem;
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        font-size: 0.9rem;
        transition: var(--transition);
    }

    .filter-input:focus, .filter-select:focus {
        outline: none;
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 2px rgba(0, 191, 174, 0.1);
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
        background: #009d8f;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
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

    .btn-info {
        background: var(--info-color);
        color: white;
    }

    .btn-info:hover {
        background: #138496;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
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

    .btn-sm {
        padding: 0.5rem 0.875rem;
        font-size: 0.875rem;
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
        -webkit-overflow-scrolling: touch;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1200px;
    }

    table th, table td {
        padding: 0.75rem 0.5rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
        font-size: 0.9rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    table th {
        background: var(--secondary-color);
        color: white;
        font-weight: 600;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    /* Ajustes de largura específicos para cada coluna */
    table th:nth-child(1), table td:nth-child(1) { /* UASG */
        min-width: 80px;
        max-width: 100px;
    }

    table th:nth-child(2), table td:nth-child(2) { /* Nome do Órgão */
        min-width: 200px;
        max-width: 300px;
        white-space: normal;
    }

    table th:nth-child(3), table td:nth-child(3) { /* CNPJ */
        min-width: 140px;
        max-width: 160px;
    }

    table th:nth-child(4), table td:nth-child(4) { /* E-mail */
        min-width: 180px;
        max-width: 250px;
    }

    table th:nth-child(5), table td:nth-child(5) { /* Telefone */
        min-width: 130px;
        max-width: 150px;
    }

    table th:nth-child(6), table td:nth-child(6) { /* Endereço */
        min-width: 150px;
        max-width: 200px;
    }

    table th:nth-child(7), table td:nth-child(7) { /* Cadastrado */
        min-width: 100px;
        max-width: 120px;
    }

    table th:nth-child(8), table td:nth-child(8) { /* Atualizado */
        min-width: 100px;
        max-width: 120px;
    }

    table th:nth-child(9), table td:nth-child(9) { /* Ações */
        min-width: 120px;
        white-space: nowrap;
    }

    /* NOVOS ESTILOS PARA ORDENAÇÃO */
    .sortable-header {
        cursor: pointer;
        user-select: none;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
        color: white;
        position: relative;
    }

    .sortable-header:hover {
        background: rgba(255, 255, 255, 0.1);
        color: white;
    }

    .sortable-header:hover::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.1);
        pointer-events: none;
    }

    .sort-icon {
        font-size: 0.8rem;
        opacity: 0.7;
    }

    table tbody tr {
        transition: var(--transition);
        animation: fadeInRow 0.3s ease-in-out;
    }

    table tbody tr:hover {
        background: var(--light-gray);
    }

    @keyframes fadeInRow {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Destaque da coluna ordenada */
    table.sorted-by-1 th:nth-child(1),
    table.sorted-by-1 td:nth-child(1),
    table.sorted-by-2 th:nth-child(2),
    table.sorted-by-2 td:nth-child(2),
    table.sorted-by-3 th:nth-child(3),
    table.sorted-by-3 td:nth-child(3),
    table.sorted-by-4 th:nth-child(4),
    table.sorted-by-4 td:nth-child(4),
    table.sorted-by-5 th:nth-child(5),
    table.sorted-by-5 td:nth-child(5),
    table.sorted-by-6 th:nth-child(6),
    table.sorted-by-6 td:nth-child(6),
    table.sorted-by-7 th:nth-child(7),
    table.sorted-by-7 td:nth-child(7),
    table.sorted-by-8 th:nth-child(8),
    table.sorted-by-8 td:nth-child(8) {
        background-color: rgba(0, 191, 174, 0.05);
    }

    .client-link {
        color: var(--secondary-color);
        text-decoration: none;
        font-weight: 600;
        transition: var(--transition);
    }

    .client-link:hover {
        color: var(--primary-color);
        text-decoration: underline;
    }

    /* Estilo para texto muted */
    .text-muted {
        color: var(--medium-gray);
        font-style: italic;
    }

    /* Estilo para link de e-mail */
    .email-link {
        color: var(--secondary-color);
        text-decoration: none;
        transition: var(--transition);
    }

    .email-link:hover {
        color: var(--primary-color);
        text-decoration: underline;
    }

    /* Container de telefone na tabela */
    .phone-display {
        display: flex;
        align-items: center;
        gap: 0.25rem;
        justify-content: space-between;
    }

    .whatsapp-icon {
        color: #25D366;
        text-decoration: none;
        font-size: 0.9rem;
        transition: var(--transition);
        flex-shrink: 0;
    }

    .whatsapp-icon:hover {
        color: #128C7E;
        transform: scale(1.1);
    }

    .phone-count {
        background: var(--primary-color);
        color: white;
        font-size: 0.7rem;
        padding: 0.1rem 0.3rem;
        border-radius: 10px;
        font-weight: 600;
        flex-shrink: 0;
    }

    /* Botões de ação na tabela */
    .action-buttons {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
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

    textarea.form-control {
        resize: vertical;
        min-height: 100px;
    }

    /* Telefone container */
    .phone-container {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.5rem;
    }

    .phone-container .form-control {
        flex: 1;
    }

    .whatsapp-link {
        flex-shrink: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: var(--radius);
        background: #25D366;
        color: white;
        text-decoration: none;
        transition: var(--transition);
    }

    .whatsapp-link:hover {
        background: #128C7E;
        transform: scale(1.1);
    }

    .phone-remove {
        background: var(--danger-color);
        color: white;
        border: none;
        border-radius: var(--radius);
        width: 32px;
        height: 32px;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .phone-remove:hover {
        background: #c82333;
        transform: scale(1.1);
    }

    /* Estatísticas detalhadas do cliente - ATUALIZADA */
    .client-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .stat-card {
        background: linear-gradient(135deg, var(--light-gray), #fff);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 1.25rem;
        text-align: center;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
        cursor: help;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-hover);
    }

    .stat-number {
        font-size: 1.6rem;
        font-weight: 800;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        font-family: 'Courier New', monospace;
        line-height: 1;
    }

    .stat-label {
        color: var(--medium-gray);
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Indicador de status do cliente */
    .client-status {
        position: absolute;
        top: 10px;
        right: 10px;
        padding: 0.25rem 0.5rem;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .client-status.active {
        background: var(--success-color);
        color: white;
    }

    .client-status.inactive {
        background: var(--medium-gray);
        color: white;
    }

    /* Botões do modal */
    .modal-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        justify-content: center;
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--border-color);
    }

    /* Estilos para toast notifications */
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }

    /* Responsividade das estatísticas do modal */
    @media (max-width: 768px) {
        .client-stats {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        
        .stat-card {
            padding: 1rem;
        }
        
        .stat-number {
            font-size: 1.3rem;
        }
        
        .stat-label {
            font-size: 0.75rem;
        }
    }

    @media (max-width: 480px) {
        .client-stats {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
        }
        
        .stat-card {
            padding: 0.75rem;
        }
        
        .stat-number {
            font-size: 1.1rem;
        }
    }

    /* Responsividade da tabela expandida */
    @media (max-width: 1400px) {
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        table {
            min-width: 1200px;
        }
    }

    @media (max-width: 1200px) {
        .clients-container {
            margin: 0 1rem;
        }
        
        table th, table td {
            padding: 0.5rem 0.25rem;
            font-size: 0.85rem;
        }
        
        table th:nth-child(6), table td:nth-child(6) { /* Endereço */
            display: none;
        }
    }

    @media (max-width: 992px) {
        table th:nth-child(4), table td:nth-child(4), /* E-mail */
        table th:nth-child(8), table td:nth-child(8) { /* Atualizado */
            display: none;
        }
    }

    @media (max-width: 768px) {
        .page-header {
            padding: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.8rem;
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

        table th:nth-child(3), table td:nth-child(3), /* CNPJ */
        table th:nth-child(5), table td:nth-child(5), /* Telefone */
        table th:nth-child(7), table td:nth-child(7) { /* Cadastrado */
            display: none;
        }
        
        table th:nth-child(2), table td:nth-child(2) { /* Nome do Órgão */
            max-width: 200px;
        }

        .action-buttons {
            justify-content: center;
            flex-direction: column;
            gap: 0.25rem;
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

        .modal-buttons {
            flex-direction: column;
        }

        .btn {
            width: 100%;
            justify-content: center;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
    }

    @media (max-width: 480px) {
        .page-header {
            padding: 1rem;
        }

        .page-header h1 {
            font-size: 1.5rem;
        }

        .controls-bar, .results-info {
            padding: 1rem;
        }

        table {
            min-width: 350px;
        }
        
        table th, table td {
            padding: 0.375rem 0.25rem;
            font-size: 0.8rem;
        }
        
        table th:nth-child(2), table td:nth-child(2) { /* Nome do Órgão */
            max-width: 150px;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
        }

        .modal-header {
            padding: 1rem;
        }

        .modal-header h3 {
            font-size: 1.2rem;
        }
        
        .action-buttons .btn-sm {
            padding: 0.2rem 0.4rem;
        }
        
        .action-buttons .btn-sm i {
            font-size: 0.7rem;
        }
    }

    /* Garante que o link do cabeçalho herde o fundo e cor do th */
    table th .sortable-header {
        background: inherit !important;
        color: inherit !important;
        width: 100%;
        display: flex;
        padding: 0;
        align-items: center;
        justify-content: flex-start;
        min-height: 100%;
    }

    /* Melhoria na definição dos cabeçalhos ordenáveis */
    .sortable-header {
        cursor: pointer;
        user-select: none;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
        color: white !important;
        position: relative;
        width: 100%;
        padding: 0;
        margin: 0;
        min-height: 100%;
        justify-content: flex-start;
    }

    .sortable-header:hover,
    .sortable-header:focus,
    .sortable-header:active {
        background: rgba(255, 255, 255, 0.1) !important;
        color: white !important;
        text-decoration: none;
    }

    /* CORREÇÃO: Ajusta o comportamento do th com links */
    table th {
        background: var(--secondary-color) !important;
        color: white !important;
        font-weight: 600;
        position: sticky;
        top: 0;
        z-index: 10;
        text-align: left;
        vertical-align: middle;
        padding: 0.75rem 0.5rem;
    }

    /* CORREÇÃO: Remove herança problemática */
    table th .sortable-header {
        background: transparent !important;
        color: white !important;
        width: 100%;
        display: flex;
        padding: 0.75rem 0.5rem;
        margin: -0.75rem -0.5rem;
        align-items: center;
        justify-content: flex-start;
        min-height: 100%;
    }
</style>

<div class="main-content">
    <div class="container clients-container">
        
        <!-- Header da página -->
        <div class="page-header">
            <h1><i class="fas fa-search"></i> Consultar Clientes</h1>
            <p>Visualize, edite e gerencie todos os clientes cadastrados no sistema</p>
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

        <!-- Barra de controles principal -->
        <div class="controls-bar">
            <form class="search-form" action="consultar_clientes.php" method="GET">
                <!-- Preserva filtros ativos -->
                <?php if (!empty($filterCnpj)): ?>
                    <input type="hidden" name="filter_cnpj" value="<?php echo htmlspecialchars($filterCnpj); ?>">
                <?php endif; ?>
                <?php if (!empty($filterEmail)): ?>
                    <input type="hidden" name="filter_email" value="<?php echo htmlspecialchars($filterEmail); ?>">
                <?php endif; ?>
                <?php if (!empty($filterDate)): ?>
                    <input type="hidden" name="filter_date" value="<?php echo htmlspecialchars($filterDate); ?>">
                <?php endif; ?>
                <?php if ($orderBy !== 'nome_orgaos'): ?>
                    <input type="hidden" name="order_by" value="<?php echo htmlspecialchars($orderBy); ?>">
                <?php endif; ?>
                <?php if ($orderDir !== 'ASC'): ?>
                    <input type="hidden" name="order_dir" value="<?php echo strtolower($orderDir); ?>">
                <?php endif; ?>
                
                <input type="text" 
                       name="search" 
                       class="search-input"
                       placeholder="Pesquisar por Nome, UASG, CNPJ, E-mail, Telefone ou Endereço..." 
                       value="<?php echo htmlspecialchars($searchTerm); ?>"
                       autocomplete="off">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Pesquisar
                </button>
                <?php if ($searchTerm): ?>
                    <a href="<?php echo buildUrl(['search' => '']); ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpar
                    </a>
                <?php endif; ?>
            </form>
            
            <?php if ($permissionManager->hasPagePermission('clientes', 'create')): ?>
                <a href="cadastrar_clientes.php" class="btn btn-success">
                    <i class="fas fa-plus"></i> Novo Cliente
                </a>
            <?php endif; ?>
        </div>

        <!-- Seção de filtros avançados -->
        <div class="filters-section">
            <button type="button" class="filters-toggle" onclick="toggleFilters()">
                <span><i class="fas fa-filter"></i> Filtros Avançados</span>
                <i class="fas fa-chevron-down" id="filterIcon"></i>
            </button>
            
            <div class="filters-content" id="filtersContent">
                <form action="consultar_clientes.php" method="GET">
                    <!-- Preserva busca e ordenação -->
                    <?php if (!empty($searchTerm)): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>">
                    <?php endif; ?>
                    <?php if ($orderBy !== 'nome_orgaos'): ?>
                        <input type="hidden" name="order_by" value="<?php echo htmlspecialchars($orderBy); ?>">
                    <?php endif; ?>
                    <?php if ($orderDir !== 'ASC'): ?>
                        <input type="hidden" name="order_dir" value="<?php echo strtolower($orderDir); ?>">
                    <?php endif; ?>
                    
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label for="filter_cnpj">Filtrar por CNPJ:</label>
                            <input type="text" 
                                   id="filter_cnpj" 
                                   name="filter_cnpj" 
                                   class="filter-input"
                                   placeholder="Digite o CNPJ..."
                                   value="<?php echo htmlspecialchars($filterCnpj); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="filter_email">Filtrar por E-mail:</label>
                            <input type="text" 
                                   id="filter_email" 
                                   name="filter_email" 
                                   class="filter-input"
                                   placeholder="Digite o e-mail..."
                                   value="<?php echo htmlspecialchars($filterEmail); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="filter_date">Filtrar por Data de Cadastro:</label>
                            <select id="filter_date" name="filter_date" class="filter-select">
                                <option value="">Todas as datas</option>
                                <option value="today" <?php echo $filterDate === 'today' ? 'selected' : ''; ?>>Hoje</option>
                                <option value="week" <?php echo $filterDate === 'week' ? 'selected' : ''; ?>>Última semana</option>
                                <option value="month" <?php echo $filterDate === 'month' ? 'selected' : ''; ?>>Último mês</option>
                                <option value="year" <?php echo $filterDate === 'year' ? 'selected' : ''; ?>>Último ano</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Aplicar Filtros
                        </button>
                        <a href="consultar_clientes.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Limpar Filtros
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Informações de resultados -->
        <?php if ($totalClientes > 0): ?>
            <div class="results-info">
                <div class="results-count">
                    <?php if ($searchTerm || $filterCnpj || $filterEmail || $filterDate): ?>
                        Encontrados <strong><?php echo $totalClientes; ?></strong> cliente(s) 
                        <?php if ($searchTerm): ?>
                            para "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>"
                        <?php endif; ?>
                        <?php if ($filterCnpj || $filterEmail || $filterDate): ?>
                            com filtros aplicados
                        <?php endif; ?>
                    <?php else: ?>
                        Total de <strong><?php echo $totalClientes; ?></strong> cliente(s) cadastrado(s)
                    <?php endif; ?>
                    
                    <?php if ($totalPaginas > 1): ?>
                        - Página <strong><?php echo $paginaAtual; ?></strong> de <strong><?php echo $totalPaginas; ?></strong>
                    <?php endif; ?>
                    
                    - Ordenado por <strong><?php echo ucfirst(str_replace('_', ' ', $orderBy)); ?></strong> 
                    (<strong><?php echo $orderDir === 'ASC' ? 'Crescente' : 'Decrescente'; ?></strong>)
                </div>
                
                <?php if ($totalClientes > $clientesPorPagina): ?>
                    <div>
                        Mostrando <?php echo ($offset + 1); ?>-<?php echo min($offset + $clientesPorPagina, $totalClientes); ?> 
                        de <?php echo $totalClientes; ?> resultados
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Tabela de clientes com ordenação completa -->
        <?php if (count($clientes) > 0): ?>
            <div class="table-container">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>
                                    <a href="<?php echo getSortUrl('uasg'); ?>" class="sortable-header">
                                        <i class="fas fa-hashtag"></i> UASG
                                        <i class="<?php echo getSortIcon('uasg'); ?> sort-icon"></i>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?php echo getSortUrl('nome_orgaos'); ?>" class="sortable-header">
                                        <i class="fas fa-building"></i> Nome do Órgão
                                        <i class="<?php echo getSortIcon('nome_orgaos'); ?> sort-icon"></i>
                                    </a>
                                </th>
                                <th><i class="fas fa-cogs"></i> Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clientes as $cliente): ?>
                                <tr>
                                    <td>
                                        <a href="javascript:void(0);" 
                                           onclick="openModal(<?php echo $cliente['id']; ?>)" 
                                           class="client-link">
                                            <?php echo htmlspecialchars($cliente['uasg']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($cliente['nome_orgaos']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="consultar_vendas_cliente.php?cliente_uasg=<?php echo urlencode($cliente['uasg']); ?>" 
                                               class="btn btn-info btn-sm" title="Ver Vendas">
                                                <i class="fas fa-shopping-cart"></i>
                                            </a>
                                            <a href="cliente_empenho.php?cliente_uasg=<?php echo urlencode($cliente['uasg']); ?>" 
                                               class="btn btn-warning btn-sm" title="Ver Empenhos">
                                                <i class="fas fa-file-invoice-dollar"></i>
                                            </a>
                                            <button onclick="openModal(<?php echo $cliente['id']; ?>)" 
                                                    class="btn btn-primary btn-sm" title="Ver Detalhes">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
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
                            <a href="<?php echo buildUrl(['page' => 1]); ?>">
                                <i class="fas fa-angle-double-left"></i> Primeira
                            </a>
                            <a href="<?php echo buildUrl(['page' => $paginaAtual - 1]); ?>">
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
                                <a href="<?php echo buildUrl(['page' => $i]); ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor;
                        
                        if ($fim < $totalPaginas): ?>
                            <span>...</span>
                        <?php endif; ?>

                        <?php if ($paginaAtual < $totalPaginas): ?>
                            <a href="<?php echo buildUrl(['page' => $paginaAtual + 1]); ?>">
                                Próxima <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="<?php echo buildUrl(['page' => $totalPaginas]); ?>">
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
                <i class="fas fa-users"></i>
                <h3>Nenhum cliente encontrado</h3>
                <?php if ($searchTerm || $filterCnpj || $filterEmail || $filterDate): ?>
                    <p>Não foram encontrados clientes com os critérios de busca especificados.</p>
                    <a href="consultar_clientes.php" class="btn btn-primary">
                        <i class="fas fa-list"></i> Ver Todos os Clientes
                    </a>
                <?php else: ?>
                    <p>Ainda não há clientes cadastrados no sistema.</p>
                    <?php if ($permissionManager->hasPagePermission('clientes', 'create')): ?>
                        <a href="cadastrar_clientes.php" class="btn btn-success">
                            <i class="fas fa-plus"></i> Cadastrar Primeiro Cliente
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- Modal de Detalhes/Edição -->
<div id="clientModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user"></i> Detalhes do Cliente</h3>
            <span class="modal-close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <!-- Estatísticas detalhadas do cliente - ATUALIZADA -->
            <div class="client-stats" id="clientStats" style="display: none;">
                <div class="stat-card">
                    <i class="fas fa-shopping-cart" style="font-size: 1.5rem; color: var(--info-color); margin-bottom: 0.5rem;"></i>
                    <div class="stat-number" id="statVendas">0</div>
                    <div class="stat-label">Total de Vendas</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-file-invoice-dollar" style="font-size: 1.5rem; color: var(--warning-color); margin-bottom: 0.5rem;"></i>
                    <div class="stat-number" id="statEmpenhos">0</div>
                    <div class="stat-label">Total de Empenhos</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-dollar-sign" style="font-size: 1.5rem; color: var(--success-color); margin-bottom: 0.5rem;"></i>
                    <div class="stat-number" id="statValorVendas">R$ 0,00</div>
                    <div class="stat-label">Valor em Vendas</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-coins" style="font-size: 1.5rem; color: var(--primary-color); margin-bottom: 0.5rem;"></i>
                    <div class="stat-number" id="statValorEmpenhos">R$ 0,00</div>
                    <div class="stat-label">Valor em Empenhos</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-calendar-alt" style="font-size: 1.5rem; color: var(--secondary-color); margin-bottom: 0.5rem;"></i>
                    <div class="stat-number" id="statUltimaVenda">-</div>
                    <div class="stat-label">Última Venda</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-clock" style="font-size: 1.5rem; color: var(--medium-gray); margin-bottom: 0.5rem;"></i>
                    <div class="stat-number" id="statUltimoEmpenho">-</div>
                    <div class="stat-label">Último Empenho</div>
                </div>
            </div>

            <form method="POST" action="consultar_clientes.php" id="clientForm">
                <input type="hidden" name="id" id="client_id">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="uasg"><i class="fas fa-hashtag"></i> UASG *</label>
                        <input type="text" name="uasg" id="uasg" class="form-control" readonly required>
                    </div>
                    
                    <div class="form-group">
                        <label for="cnpj"><i class="fas fa-id-card"></i> CNPJ</label>
                        <input type="text" name="cnpj" id="cnpj" class="form-control" readonly>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="nome_orgaos"><i class="fas fa-building"></i> Nome do Órgão *</label>
                    <input type="text" name="nome_orgaos" id="nome_orgaos" class="form-control" readonly required>
                </div>
                
                <div class="form-group">
                    <label for="endereco"><i class="fas fa-map-marker-alt"></i> Endereço</label>
                    <input type="text" name="endereco" id="endereco" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Telefones</label>
                    <div id="phoneContainer">
                        <!-- Telefones serão adicionados dinamicamente -->
                    </div>
                    <button type="button" id="addPhoneBtn" class="btn btn-secondary btn-sm" style="display: none;" onclick="addPhoneField()">
                        <i class="fas fa-plus"></i> Adicionar Telefone
                    </button>
                </div>
                
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> E-mail</label>
                    <input type="email" name="email" id="email" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label for="observacoes"><i class="fas fa-sticky-note"></i> Observações</label>
                    <textarea name="observacoes" id="observacoes" class="form-control" readonly rows="4"></textarea>
                </div>

                <div class="modal-buttons">
                    <button type="submit" name="update_client" id="saveBtn" class="btn btn-success" style="display: none;">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                    
                    <?php if ($permissionManager->hasPagePermission('clientes', 'edit')): ?>
                        <button type="button" class="btn btn-primary" id="editBtn" onclick="enableEditing()">
                            <i class="fas fa-edit"></i> Editar
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($permissionManager->hasPagePermission('clientes', 'delete')): ?>
                        <button type="button" class="btn btn-danger" id="deleteBtn" onclick="openDeleteModal()">
                            <i class="fas fa-trash-alt"></i> Excluir
                        </button>
                    <?php endif; ?>
                    
                    <a href="#" id="verVendasBtn" class="btn btn-info">
                        <i class="fas fa-shopping-cart"></i> Ver Vendas
                    </a>
                    
                    <a href="#" id="verEmpenhosBtn" class="btn btn-warning">
                        <i class="fas fa-file-invoice-dollar"></i> Ver Empenhos
                    </a>
                    
                    <a href="#" id="extratoBtn" class="btn btn-secondary">
                        <i class="fas fa-file-invoice"></i> Extrato
                    </a>
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
            <span class="modal-close" onclick="closeDeleteModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div style="text-align: center; margin-bottom: 2rem;">
                <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: var(--danger-color); margin-bottom: 1rem;"></i>
                <p style="font-size: 1.1rem; margin-bottom: 1rem;">
                    Tem certeza que deseja excluir este cliente?
                </p>
                <p style="color: var(--danger-color); font-weight: 600;">
                    <i class="fas fa-warning"></i> Esta ação não pode ser desfeita.
                </p>
                <p style="color: var(--medium-gray); font-size: 0.9rem; margin-top: 1rem;">
                    O cliente só pode ser excluído se não possuir vendas ou empenhos associados.
                </p>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="btn btn-danger" onclick="deleteClient()">
                    <i class="fas fa-trash-alt"></i> Sim, Excluir
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Variáveis JavaScript definidas do PHP
const phpData = {
    filterCnpj: <?php echo json_encode(!empty($filterCnpj)); ?>,
    filterEmail: <?php echo json_encode(!empty($filterEmail)); ?>,
    filterDate: <?php echo json_encode(!empty($filterDate)); ?>,
    orderBy: <?php echo json_encode($orderBy); ?>,
    totalClientes: <?php echo (int)$totalClientes; ?>,
    paginaAtual: <?php echo (int)$paginaAtual; ?>,
    totalPaginas: <?php echo (int)$totalPaginas; ?>,
    orderDir: <?php echo json_encode($orderDir); ?>
};
</script>

<?php
// JavaScript separado para ser passado para endPage
$javascript = <<<JS
// JavaScript para funcionalidades da página
document.addEventListener('DOMContentLoaded', function() {
    // Função para alternar filtros
    window.toggleFilters = function() {
        const content = document.getElementById('filtersContent');
        const icon = document.getElementById('filterIcon');
        
        if (content.classList.contains('show')) {
            content.classList.remove('show');
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
        } else {
            content.classList.add('show');
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');
        }
    };

    // Mostra filtros se algum estiver ativo
    if (phpData.filterCnpj || phpData.filterEmail || phpData.filterDate) {
        toggleFilters();
    }

    // Função para abrir o modal e carregar os dados do cliente
    window.openModal = function(id) {
        const modal = document.getElementById('clientModal');
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';

        // Limpa o formulário
        resetForm();

        fetch('consultar_clientes.php?get_cliente_id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert('Erro: ' + data.error);
                    return;
                }

                // Preenche os campos básicos
                document.getElementById('client_id').value = data.id;
                document.getElementById('uasg').value = data.uasg || '';
                document.getElementById('cnpj').value = data.cnpj || '';
                document.getElementById('nome_orgaos').value = data.nome_orgaos || '';
                document.getElementById('endereco').value = data.endereco || '';
                document.getElementById('email').value = data.email || '';
                document.getElementById('observacoes').value = data.observacoes || '';

                // Processa telefones
                loadPhones(data.telefone || '');

                // Atualiza estatísticas detalhadas
                if (data.stats) {
                    updateStats(data.stats);
                }

                // Atualiza links de ação
                updateActionLinks(data.uasg);
            })
            .catch(error => {
                console.error('Erro ao buscar dados do cliente:', error);
                alert('Erro ao carregar os dados do cliente.');
            });
    };

    // Função para carregar telefones
    function loadPhones(phoneString) {
        const container = document.getElementById('phoneContainer');
        container.innerHTML = '';

        if (phoneString) {
            const phones = phoneString.split('/').filter(phone => phone.trim());
            phones.forEach(phone => addPhoneField(phone.trim()));
        }

        if (container.children.length === 0) {
            addPhoneField('');
        }
    }

    // Função para adicionar campo de telefone
    window.addPhoneField = function(phone = '') {
        const container = document.getElementById('phoneContainer');
        const phoneDiv = document.createElement('div');
        phoneDiv.className = 'phone-container';
        
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'telefone[]';
        input.value = phone;
        input.className = 'form-control';
        input.readOnly = true;
        input.placeholder = '(00) 00000-0000';
        
        const whatsappLink = document.createElement('a');
        whatsappLink.href = phone ? 'https://wa.me/55' + phone.replace(/\\\\D/g, '') : '#';
        whatsappLink.target = '_blank';
        whatsappLink.className = 'whatsapp-link';
        whatsappLink.innerHTML = '<i class=\"fab fa-whatsapp\"></i>';
        whatsappLink.title = 'Abrir no WhatsApp';
        
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'phone-remove';
        removeBtn.innerHTML = '<i class=\"fas fa-times\"></i>';
        removeBtn.title = 'Remover telefone';
        removeBtn.style.display = 'none';
        removeBtn.onclick = function() {
            phoneDiv.remove();
        };
        
        phoneDiv.appendChild(input);
        phoneDiv.appendChild(whatsappLink);
        phoneDiv.appendChild(removeBtn);
        container.appendChild(phoneDiv);

        // Atualiza link do WhatsApp quando o telefone muda
        input.addEventListener('input', function() {
            const cleanPhone = this.value.replace(/\\\\D/g, '');
            whatsappLink.href = cleanPhone ? 'https://wa.me/55' + cleanPhone : '#';
        });
    };

    // Função para atualizar estatísticas detalhadas - ATUALIZADA
    function updateStats(stats) {
        const statsContainer = document.getElementById('clientStats');
        const modalHeader = document.querySelector('.modal-header');
        let statusIndicator = modalHeader.querySelector('.client-status');

        if (!statusIndicator) {
            statusIndicator = document.createElement('div');
            statusIndicator.className = 'client-status';
            modalHeader.appendChild(statusIndicator);
        }
        
        if (stats && (stats.total_vendas > 0 || stats.total_empenhos > 0)) {
            // Total de Vendas
            document.getElementById('statVendas').textContent = stats.total_vendas || 0;
            
            // Total de Empenhos
            document.getElementById('statEmpenhos').textContent = stats.total_empenhos || 0;
            
            // Valor em Vendas
            const valorVendas = parseFloat(stats.valor_vendas) || 0;
            document.getElementById('statValorVendas').textContent = valorVendas.toLocaleString('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            });
            
            // Valor em Empenhos
            const valorEmpenhos = parseFloat(stats.valor_empenhos) || 0;
            document.getElementById('statValorEmpenhos').textContent = valorEmpenhos.toLocaleString('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            });
            
            // Última Venda
            if (stats.ultima_venda) {
                const dataVenda = new Date(stats.ultima_venda + 'T00:00:00');
                document.getElementById('statUltimaVenda').textContent = dataVenda.toLocaleDateString('pt-BR');
            } else {
                document.getElementById('statUltimaVenda').textContent = '-';
            }
            
            // Último Empenho
            if (stats.ultimo_empenho) {
                const dataEmpenho = new Date(stats.ultimo_empenho + 'T00:00:00');
                document.getElementById('statUltimoEmpenho').textContent = dataEmpenho.toLocaleDateString('pt-BR');
            } else {
                document.getElementById('statUltimoEmpenho').textContent = '-';
            }
            
            // Status do cliente
            statusIndicator.textContent = 'Cliente Ativo';
            statusIndicator.className = 'client-status active';
            
        } else {
            // Se não há vendas nem empenhos, ainda mostra as estatísticas zeradas
            document.getElementById('statVendas').textContent = '0';
            document.getElementById('statEmpenhos').textContent = '0';
            document.getElementById('statValorVendas').textContent = 'R$ 0,00';
            document.getElementById('statValorEmpenhos').textContent = 'R$ 0,00';
            document.getElementById('statUltimaVenda').textContent = '-';
            document.getElementById('statUltimoEmpenho').textContent = '-';
            
            // Status do cliente
            statusIndicator.textContent = 'Sem Movimentação';
            statusIndicator.className = 'client-status inactive';
        }
        
        // Mostra o container de estatísticas
        statsContainer.style.display = 'grid';
        
        // Adiciona tooltips informativos
        addStatsTooltips();
        
        // Adiciona animação aos cards
        setTimeout(() => {
            const statCards = statsContainer.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.3s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        }, 100);
    }

    // Adiciona tooltips informativos às estatísticas
    function addStatsTooltips() {
        const tooltips = {
            'statVendas': 'Quantidade total de vendas realizadas para este cliente',
            'statEmpenhos': 'Quantidade total de empenhos registrados para este cliente',
            'statValorVendas': 'Valor total em reais de todas as vendas deste cliente',
            'statValorEmpenhos': 'Valor total em reais de todos os empenhos deste cliente',
            'statUltimaVenda': 'Data da venda mais recente registrada',
            'statUltimoEmpenho': 'Data do empenho mais recente registrado'
        };
        
        Object.keys(tooltips).forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                const card = element.closest('.stat-card');
                if (card) {
                    card.title = tooltips[id];
                    card.style.cursor = 'help';
                }
            }
        });
    }

    // Função para atualizar links de ação
    function updateActionLinks(uasg) {
        document.getElementById('verVendasBtn').href = 'consultar_vendas_cliente.php?cliente_uasg=' + encodeURIComponent(uasg);
        document.getElementById('verEmpenhosBtn').href = 'cliente_empenho.php?cliente_uasg=' + encodeURIComponent(uasg);
        document.getElementById('extratoBtn').href = 'consulta_contas_receber.php?cliente_uasg=' + encodeURIComponent(uasg);
    }

    // Função para habilitar edição
    window.enableEditing = function() {
        const inputs = document.querySelectorAll('#clientForm input:not([type=hidden]), #clientForm textarea');
        inputs.forEach(input => {
            if (input.name !== 'cnpj') { // CNPJ não pode ser editado
                input.readOnly = false;
            }
        });

        // Mostra botões de ação na edição
        document.querySelectorAll('.phone-remove').forEach(btn => {
            btn.style.display = 'flex';
        });
        
        document.getElementById('addPhoneBtn').style.display = 'inline-flex';
        document.getElementById('saveBtn').style.display = 'inline-flex';
        document.getElementById('editBtn').style.display = 'none';
    };

    // Função para resetar formulário
    function resetForm() {
        const form = document.getElementById('clientForm');
        form.reset();
        
        const inputs = document.querySelectorAll('#clientForm input:not([type=hidden]), #clientForm textarea');
        inputs.forEach(input => {
            input.readOnly = true;
        });
        
        document.getElementById('phoneContainer').innerHTML = '';
        document.getElementById('addPhoneBtn').style.display = 'none';
        document.getElementById('saveBtn').style.display = 'none';
        document.getElementById('editBtn').style.display = 'inline-flex';
        document.getElementById('clientStats').style.display = 'none';
        
        // Remove status indicator se existir
        const statusIndicator = document.querySelector('.client-status');
        if (statusIndicator) {
            statusIndicator.remove();
        }
        
        document.querySelectorAll('.phone-remove').forEach(btn => {
            btn.style.display = 'none';
        });
    }

    // Função para fechar modal
    window.closeModal = function() {
        const modal = document.getElementById('clientModal');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        resetForm();
    };

    // Modal de exclusão
    window.openDeleteModal = function() {
        const deleteModal = document.getElementById('deleteModal');
        deleteModal.style.display = 'block';
        window.clientToDelete = document.getElementById('client_id').value;
    };

    window.closeDeleteModal = function() {
        const deleteModal = document.getElementById('deleteModal');
        deleteModal.style.display = 'none';
        delete window.clientToDelete;
    };

    window.deleteClient = function() {
        if (window.clientToDelete) {
            window.location.href = 'consultar_clientes.php?delete_client_id=' + window.clientToDelete;
        }
    };

    // JavaScript adicional para funcionalidades de ordenação
    function updateSortedColumnClass() {
        const table = document.querySelector('table');
        if (!table) return;
        
        // Remove classes antigas
        table.className = table.className.replace(/sorted-by-\\\\d+/g, '');
        
        // Mapeia campos para índices de coluna
        const fieldToColumnMap = {
            'uasg': 1,
            'nome_orgaos': 2,
            'cnpj': 3,
            'email': 4,
            'telefone': 5,
            'endereco': 6,
            'created_at': 7,
            'updated_at': 8
        };
        
        const currentOrderBy = phpData.orderBy;
        const columnIndex = fieldToColumnMap[currentOrderBy];
        
        if (columnIndex) {
            table.classList.add('sorted-by-' + columnIndex);
        }
    }

    // Tooltip para valores truncados
    function initializeTooltips() {
        document.querySelectorAll('td[title]').forEach(cell => {
            cell.addEventListener('mouseenter', function(e) {
                const title = this.getAttribute('title');
                const content = this.textContent.trim();
                
                if (title && title !== content && title.length > content.length) {
                    this.style.position = 'relative';
                    this.style.overflow = 'visible';
                }
            });
        });
    }

    // Função para copiar dados da célula
    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                showToast('Copiado para a área de transferência!', 'success');
            }).catch(() => {
                fallbackCopyTextToClipboard(text);
            });
        } else {
            fallbackCopyTextToClipboard(text);
        }
    }

    function fallbackCopyTextToClipboard(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.top = '0';
        textArea.style.left = '0';
        textArea.style.position = 'fixed';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            document.execCommand('copy');
            showToast('Copiado para a área de transferência!', 'success');
        } catch (err) {
            console.error('Falha ao copiar: ', err);
        }
        
        document.body.removeChild(textArea);
    }

    // Sistema de toast simples para feedback
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: \${type === 'success' ? 'var(--success-color)' : 'var(--info-color)'};
            color: white;
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            z-index: 10000;
            animation: slideInRight 0.3s ease;
        `;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 3000);
    }

    // Adiciona eventos de clique duplo para copiar dados
    function initializeCopyOnDoubleClick() {
        document.querySelectorAll('td:not(:last-child)').forEach(cell => {
            cell.addEventListener('dblclick', function(e) {
                e.preventDefault();
                const text = this.textContent.trim();
                if (text && text !== '-') {
                    copyToClipboard(text);
                }
            });
            
            cell.style.cursor = 'pointer';
            cell.title = (cell.title || '') + (cell.title ? ' | ' : '') + 'Duplo clique para copiar';
        });
    }

    // Função para destacar linha ao passar o mouse
    function initializeRowHighlight() {
        document.querySelectorAll('tbody tr').forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
                this.style.transform = 'translateY(-1px)';
                this.style.zIndex = '1';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.boxShadow = '';
                this.style.transform = '';
                this.style.zIndex = '';
            });
        });
    }

    // Fecha modais ao clicar fora
    window.onclick = function(event) {
        const clientModal = document.getElementById('clientModal');
        const deleteModal = document.getElementById('deleteModal');
        
        if (event.target === clientModal) {
            closeModal();
        }
        if (event.target === deleteModal) {
            closeDeleteModal();
        }
    };

    // Tecla ESC para fechar modais
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
            closeDeleteModal();
        }
    });

    // Validação do formulário
    document.getElementById('clientForm').addEventListener('submit', function(e) {
        const uasg = document.getElementById('uasg').value.trim();
        const nomeOrgao = document.getElementById('nome_orgaos').value.trim();
        
        if (!uasg || !nomeOrgao) {
            e.preventDefault();
            alert('UASG e Nome do Órgão são campos obrigatórios.');
            return;
        }
    });

    // Inicializa funcionalidades
    updateSortedColumnClass();
    initializeTooltips();
    initializeCopyOnDoubleClick();
    initializeRowHighlight();

    // Auto-foco na pesquisa
    const searchInput = document.querySelector('.search-input');
    if (searchInput && !searchInput.value) {
        searchInput.focus();
    }

    // Animação dos cards da tabela
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

    // Log de informações do sistema
    console.log('=== CONSULTA DE CLIENTES CARREGADA ===');
    console.log('Total de clientes:', phpData.totalClientes);
    console.log('Página atual:', phpData.paginaAtual, 'de', phpData.totalPaginas);
    console.log('Ordenação:', phpData.orderBy, phpData.orderDir);
    console.log('Filtros ativos:', {
        cnpj: phpData.filterCnpj,
        email: phpData.filterEmail,
        date: phpData.filterDate
    });
    console.log('Funcionalidades inicializadas:');
    console.log('- Sistema de ordenação ✓');
    console.log('- Filtros avançados ✓');
    console.log('- Modal de detalhes com estatísticas ✓');
    console.log('- Tooltips informativos ✓');
    console.log('- Cópia por duplo clique ✓');
    console.log('- Animações e transições ✓');
    console.log('- Responsividade ✓');
    console.log('=====================================');
});
JS;

// Chama a função endPage com o JavaScript
endPage(false, $javascript);
?>