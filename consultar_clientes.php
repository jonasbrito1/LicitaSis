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
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$totalClientes = 0;
$clientesPorPagina = 20;
$paginaAtual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($paginaAtual - 1) * $clientesPorPagina;

// PARÂMETROS DE ORDENAÇÃO E FILTROS EXPANDIDOS
$orderBy = isset($_GET['ordenar']) ? $_GET['ordenar'] : 'nome_orgaos';
$orderDir = isset($_GET['direcao']) && $_GET['direcao'] === 'desc' ? 'DESC' : 'ASC';
$filterCnpj = isset($_GET['filter_cnpj']) ? trim($_GET['filter_cnpj']) : '';
$filterCpf = isset($_GET['filter_cpf']) ? trim($_GET['filter_cpf']) : '';
$filterNomePessoa = isset($_GET['filter_nome_pessoa']) ? trim($_GET['filter_nome_pessoa']) : '';
$filterEmail = isset($_GET['filter_email']) ? trim($_GET['filter_email']) : '';
$filterDate = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';

// Campos permitidos para ordenação expandidos
$allowedOrderFields = ['id', 'uasg', 'nome_orgaos', 'nome_pessoa', 'cnpj', 'cpf', 'rg', 'tipo_pessoa', 'email', 'telefone', 'created_at', 'nome_principal', 'documento_principal'];
if (!in_array($orderBy, $allowedOrderFields)) {
    $orderBy = 'nome_orgaos';
}

// Processa atualização de cliente
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_client']) && $permissionManager->hasPagePermission('clientes', 'edit')) {
    $id = (int)$_POST['id'];
    $tipo_pessoa = trim($_POST['tipo_pessoa']);
    $uasg = trim($_POST['uasg']);
    $cnpj = trim($_POST['cnpj'] ?? '');
    $cpf = trim($_POST['cpf'] ?? '');
    $rg = trim($_POST['rg'] ?? '');
    $nome_orgaos = trim($_POST['nome_orgaos'] ?? '');
    $nome_pessoa = trim($_POST['nome_pessoa'] ?? '');
    $endereco = trim($_POST['endereco']);
    $telefone = trim($_POST['telefone'] ?? '');
    $telefone2 = trim($_POST['telefone2'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $email2 = trim($_POST['email2'] ?? '');
    $observacoes = trim($_POST['observacoes']);

    // Validações
    if (empty($uasg)) {
        $error = "UASG é campo obrigatório.";
    } elseif ($tipo_pessoa === 'PJ' && empty($nome_orgaos)) {
        $error = "Nome do Órgão é obrigatório para Pessoa Jurídica.";
    } elseif ($tipo_pessoa === 'PF' && empty($nome_pessoa)) {
        $error = "Nome da Pessoa é obrigatório para Pessoa Física.";
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
                        tipo_pessoa = :tipo_pessoa,
                        uasg = :uasg, 
                        cnpj = :cnpj,
                        cpf = :cpf,
                        rg = :rg,
                        nome_orgaos = :nome_orgaos,
                        nome_pessoa = :nome_pessoa,
                        endereco = :endereco, 
                        telefone = :telefone,
                        telefone2 = :telefone2,
                        email = :email,
                        email2 = :email2,
                        observacoes = :observacoes,
                        updated_at = NOW()
                        WHERE id = :id";
                
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':tipo_pessoa', $tipo_pessoa);
                $stmt->bindParam(':uasg', $uasg);
                $stmt->bindParam(':cnpj', $tipo_pessoa === 'PJ' ? $cnpj : null);
                $stmt->bindParam(':cpf', $tipo_pessoa === 'PF' ? $cpf : null);
                $stmt->bindParam(':rg', $tipo_pessoa === 'PF' ? $rg : null);
                $stmt->bindParam(':nome_orgaos', $tipo_pessoa === 'PJ' ? $nome_orgaos : null);
                $stmt->bindParam(':nome_pessoa', $tipo_pessoa === 'PF' ? $nome_pessoa : null);
                $stmt->bindParam(':endereco', $endereco);
                $stmt->bindParam(':telefone', $telefone);
                $stmt->bindParam(':telefone2', $telefone2);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':email2', $email2);
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

// LÓGICA DE BUSCA E FILTROS EXPANDIDA
try {
    $whereConditions = [];
    $params = [];
    
    // Busca geral
    if (!empty($searchTerm)) {
        $whereConditions[] = "(nome_orgaos LIKE :searchTerm 
                             OR nome_pessoa LIKE :searchTerm
                             OR uasg LIKE :searchTerm 
                             OR cnpj LIKE :searchTerm 
                             OR cpf LIKE :searchTerm
                             OR email LIKE :searchTerm 
                             OR email2 LIKE :searchTerm
                             OR endereco LIKE :searchTerm 
                             OR telefone LIKE :searchTerm
                             OR telefone2 LIKE :searchTerm)";
        $params[':searchTerm'] = "%$searchTerm%";
    }
    
    // Filtro por CNPJ
    if (!empty($filterCnpj)) {
        $whereConditions[] = "cnpj LIKE :filterCnpj";
        $params[':filterCnpj'] = "%$filterCnpj%";
    }
    
    // Filtro por CPF
    if (!empty($filterCpf)) {
        $whereConditions[] = "cpf LIKE :filterCpf";
        $params[':filterCpf'] = "%$filterCpf%";
    }
    
    // Filtro por Nome da Pessoa
    if (!empty($filterNomePessoa)) {
        $whereConditions[] = "nome_pessoa LIKE :filterNomePessoa";
        $params[':filterNomePessoa'] = "%$filterNomePessoa%";
    }
    
    // Filtro por E-mail
    if (!empty($filterEmail)) {
        $whereConditions[] = "(email LIKE :filterEmail OR email2 LIKE :filterEmail)";
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
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalClientes = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPaginas = ceil($totalClientes / $clientesPorPagina);
    
    // Busca clientes com paginação e ordenação
    $sql = "SELECT id, tipo_pessoa, uasg, cnpj, cpf, rg, nome_orgaos, nome_pessoa, 
            telefone, telefone2, email, email2, endereco, observacoes, created_at, updated_at,
            CASE 
                WHEN tipo_pessoa = 'PF' THEN nome_pessoa 
                ELSE nome_orgaos 
            END as nome_principal,
            CASE 
                WHEN tipo_pessoa = 'PF' THEN cpf 
                ELSE cnpj 
            END as documento_principal
            FROM clientes $whereClause 
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

// Processa exclusão de cliente
if (isset($_GET['delete_client_id']) && $permissionManager->hasPagePermission('clientes', 'delete')) {
    $clienteId = (int)$_GET['delete_client_id'];
    $forceDelete = isset($_GET['force_delete']) && $_GET['force_delete'] === '1';
    
    try {
        // Busca dados do cliente antes de excluir para auditoria
        $clienteStmt = $pdo->prepare("SELECT * FROM clientes WHERE id = :id");
        $clienteStmt->bindParam(':id', $clienteId);
        $clienteStmt->execute();
        $clienteData = $clienteStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$clienteData) {
            $error = "Cliente não encontrado.";
        } else {
            // Verifica se o cliente possui vendas associadas
            $vendasStmt = $pdo->prepare("SELECT COUNT(*) as total FROM vendas WHERE cliente_uasg = :uasg");
            $vendasStmt->bindParam(':uasg', $clienteData['uasg']);
            $vendasStmt->execute();
            $totalVendas = $vendasStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Verifica se o cliente possui empenhos associados
            $empenhosStmt = $pdo->prepare("SELECT COUNT(*) as total FROM empenhos WHERE cliente_uasg = :uasg");
            $empenhosStmt->bindParam(':uasg', $clienteData['uasg']);
            $empenhosStmt->execute();
            $totalEmpenhos = $empenhosStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Se tem relações e não foi forçada a exclusão, mostra aviso
            if (($totalVendas > 0 || $totalEmpenhos > 0) && !$forceDelete) {
                $error = "ATENÇÃO: Este cliente possui " . 
                         ($totalVendas > 0 ? "$totalVendas venda(s)" : "") . 
                         ($totalVendas > 0 && $totalEmpenhos > 0 ? " e " : "") . 
                         ($totalEmpenhos > 0 ? "$totalEmpenhos empenho(s)" : "") . 
                         " associado(s). " .
                         "<br><br><strong>Consequências da exclusão:</strong>" .
                         "<br>• Os registros de vendas/empenhos permanecerão no sistema" .
                         "<br>• Mas não será possível identificar o cliente nas consultas" .
                         "<br>• Relatórios podem apresentar dados inconsistentes" .
                         "<br><br><a href='consultar_clientes.php?delete_client_id=$clienteId&force_delete=1' " .
                         "onclick='return confirm(\"CONFIRMAÇÃO FINAL: Tem absoluta certeza que deseja excluir este cliente e todas as suas associações?\")' " .
                         "style='background: #dc3545; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px;'>" .
                         "<i class='fas fa-exclamation-triangle'></i> SIM, EXCLUIR MESMO ASSIM</a>";
            } else {
                // Inicia transação para exclusão
                $pdo->beginTransaction();
                
                try {
                    // Se tem relações, primeiro atualiza/remove as referências
                    if ($totalVendas > 0) {
                        // Opção 1: Manter vendas mas marcar cliente como "EXCLUÍDO"
                        $updateVendasStmt = $pdo->prepare("UPDATE vendas SET cliente_uasg = CONCAT('EXCLUÍDO-', cliente_uasg) WHERE cliente_uasg = :uasg");
                        $updateVendasStmt->bindParam(':uasg', $clienteData['uasg']);
                        $updateVendasStmt->execute();
                        
                        // Opção 2: Comentar a linha acima e descomentar a linha abaixo para excluir vendas também
                        // $deleteVendasStmt = $pdo->prepare("DELETE FROM vendas WHERE cliente_uasg = :uasg");
                        // $deleteVendasStmt->bindParam(':uasg', $clienteData['uasg']);
                        // $deleteVendasStmt->execute();
                    }
                    
                    if ($totalEmpenhos > 0) {
                        // Opção 1: Manter empenhos mas marcar cliente como "EXCLUÍDO"
                        $updateEmpenhosStmt = $pdo->prepare("UPDATE empenhos SET cliente_uasg = CONCAT('EXCLUÍDO-', cliente_uasg) WHERE cliente_uasg = :uasg");
                        $updateEmpenhosStmt->bindParam(':uasg', $clienteData['uasg']);
                        $updateEmpenhosStmt->execute();
                        
                        // Opção 2: Comentar a linha acima e descomentar a linha abaixo para excluir empenhos também
                        // $deleteEmpenhosStmt = $pdo->prepare("DELETE FROM empenhos WHERE cliente_uasg = :uasg");
                        // $deleteEmpenhosStmt->bindParam(':uasg', $clienteData['uasg']);
                        // $deleteEmpenhosStmt->execute();
                    }
                    
                    // Exclui o cliente
                    $deleteStmt = $pdo->prepare("DELETE FROM clientes WHERE id = :id");
                    $deleteStmt->bindParam(':id', $clienteId);
                    $deleteStmt->execute();
                    
                    // Confirma transação
                    $pdo->commit();
                    
                    // Registra auditoria com informações das relações
                    $auditData = [
                        'cliente_excluido' => $clienteData,
                        'vendas_afetadas' => $totalVendas,
                        'empenhos_afetados' => $totalEmpenhos,
                        'acao' => $forceDelete ? 'EXCLUSAO_FORCADA' : 'EXCLUSAO_SIMPLES'
                    ];
                    
                    logAudit($pdo, $_SESSION['user']['id'], 'DELETE', 'clientes', $clienteId, null, $auditData);
                    
                    $success = "Cliente excluído com sucesso!" . 
                              ($totalVendas > 0 || $totalEmpenhos > 0 ? 
                               " As vendas/empenhos associados foram marcados como 'EXCLUÍDO' para manter histórico." : "");
                    
                    header("Location: consultar_clientes.php?success=" . urlencode($success));
                    exit();
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
        }
    } catch (PDOException $e) {
        $error = "Erro ao excluir o cliente: " . $e->getMessage();
        error_log("Erro ao excluir cliente ID $clienteId: " . $e->getMessage());
    }
}

// Processa requisição AJAX para dados do cliente
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
            
            // Garante que todos os campos estejam presentes
            $cliente['cnpj'] = $cliente['cnpj'] ?? '';
            $cliente['cpf'] = $cliente['cpf'] ?? '';
            $cliente['rg'] = $cliente['rg'] ?? '';
            $cliente['nome_orgaos'] = $cliente['nome_orgaos'] ?? '';
            $cliente['nome_pessoa'] = $cliente['nome_pessoa'] ?? '';
            $cliente['telefone'] = $cliente['telefone'] ?? '';
            $cliente['telefone2'] = $cliente['telefone2'] ?? '';
            $cliente['email'] = $cliente['email'] ?? '';
            $cliente['email2'] = $cliente['email2'] ?? '';
            $cliente['endereco'] = $cliente['endereco'] ?? '';
            $cliente['observacoes'] = $cliente['observacoes'] ?? '';
            
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
        min-width: 600px;
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

    /* Ajustes de largura específicos para cada coluna - SIMPLIFICADO */
    table th:nth-child(1), table td:nth-child(1) { /* UASG */
        min-width: 100px;
        max-width: 150px;
        width: 15%;
    }

    table th:nth-child(2), table td:nth-child(2) { /* Nome/Razão Social */
        min-width: 250px;
        white-space: normal;
        width: 60%;
    }

    table th:nth-child(3), table td:nth-child(3) { /* Ações */
        min-width: 180px;
        white-space: nowrap;
        width: 25%;
        text-align: center;
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

    /* Destaque da coluna ordenada - SIMPLIFICADO */
    table.sorted-by-1 th:nth-child(1),
    table.sorted-by-1 td:nth-child(1) {
        background-color: rgba(0, 191, 174, 0.05);
    }

    table.sorted-by-2 th:nth-child(2),
    table.sorted-by-2 td:nth-child(2) {
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

    /* Botões de ação na tabela */
    .action-buttons {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        justify-content: center;
    }

    /* Badges para tipo de pessoa */
    .badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
        font-weight: 700;
        line-height: 1;
        text-align: center;
        white-space: nowrap;
        vertical-align: baseline;
        border-radius: 0.25rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .badge-primary {
        color: white;
        background-color: var(--primary-color);
    }

    .badge-info {
        color: white;
        background-color: var(--info-color);
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

    /* Estatísticas detalhadas do cliente */
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

    /* Responsividade para 3 colunas */
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

        table {
            min-width: 500px;
        }
        
        table th:nth-child(1), table td:nth-child(1) { /* UASG */
            min-width: 80px;
            max-width: 100px;
        }
        
        table th:nth-child(2), table td:nth-child(2) { /* Nome */
            min-width: 200px;
        }
        
        table th:nth-child(3), table td:nth-child(3) { /* Ações */
            min-width: 150px;
        }

        .action-buttons {
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
            min-width: 400px;
        }
        
        table th:nth-child(1), table td:nth-child(1) { /* UASG */
            min-width: 70px;
            max-width: 80px;
            font-size: 0.85rem;
        }
        
        table th:nth-child(2), table td:nth-child(2) { /* Nome */
            min-width: 150px;
            font-size: 0.85rem;
        }
        
        table th:nth-child(3), table td:nth-child(3) { /* Ações */
            min-width: 120px;
        }

        .action-buttons .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
        }

        .modal-header {
            padding: 1rem;
        }

        .modal-header h3 {
            font-size: 1.2rem;
        }

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

    /* ===========================================
   ESTILOS ADICIONAIS PARA CONSULTA DE CLIENTES
   =========================================== */

/* Filtros no estilo empenhos */
.filters-container {
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
    border-radius: var(--radius);
    border: 1px solid var(--border-color);
}

.filters-row {
    display: grid;
    grid-template-columns: 1fr auto auto auto auto;
    gap: 1rem;
    align-items: end;
}

.search-group {
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

.search-input {
    padding: 0.75rem 1rem;
    border: 2px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-size: 1rem;
    transition: var(--transition);
    background: white;
}

.search-input:focus {
    outline: none;
    border-color: var(--secondary-color);
    box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
}

.filter-select {
    padding: 0.75rem 1rem;
    border: 2px solid var(--border-color);
    border-radius: var(--radius-sm);
    background: white;
    min-width: 160px;
    font-size: 0.9rem;
    transition: var(--transition);
    cursor: pointer;
}

.filter-select:focus {
    outline: none;
    border-color: var(--secondary-color);
    box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
}

/* Paginação estilo empenhos */
.pagination-container {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    margin: 2rem 0;
    padding: 1.5rem;
    background: var(--light-gray);
    border-radius: var(--radius);
}

.pagination {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.page-btn {
    padding: 0.5rem 1rem;
    border: 2px solid var(--border-color);
    background: white;
    color: var(--primary-color);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    font-weight: 600;
    min-width: 40px;
    text-align: center;
}

.page-btn:hover {
    border-color: var(--secondary-color);
    background: var(--secondary-color);
    color: white;
    transform: translateY(-2px);
}

.page-btn.active {
    background: var(--secondary-color);
    color: white;
    border-color: var(--secondary-color);
}

.page-btn:disabled,
.page-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.pagination-info {
    color: var(--medium-gray);
    font-size: 0.9rem;
    font-weight: 500;
}

/* Status badges como empenhos */
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

.status-badge.pendente {
    background: rgba(253, 126, 20, 0.1);
    color: var(--pendente-color);
    border: 1px solid var(--pendente-color);
}

.status-badge.pago {
    background: rgba(40, 167, 69, 0.1);
    color: var(--pago-color);
    border: 1px solid var(--pago-color);
}

/* Elementos interativos como empenhos */
.numero-empenho {
    cursor: pointer;
    color: var(--secondary-color);
    font-weight: 600;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    border-radius: var(--radius-sm);
    background: rgba(0, 191, 174, 0.1);
}

.numero-empenho:hover {
    color: var(--primary-color);
    background: rgba(45, 137, 62, 0.1);
    transform: scale(1.05);
    box-shadow: 0 2px 8px rgba(0, 191, 174, 0.2);
}

.numero-empenho i {
    font-size: 0.8rem;
}

/* Ordenação da tabela */
.sort-icon {
    opacity: 0.5;
    margin-left: 0.5rem;
    font-size: 0.8rem;
    transition: all 0.3s ease;
}

th:hover .sort-icon {
    opacity: 1;
    transform: scale(1.2);
}

.sort-asc {
    opacity: 1;
    color: var(--success-color);
    transform: rotate(0deg);
}

.sort-desc {
    opacity: 1;
    color: var(--danger-color);
    transform: rotate(180deg);
}

th[onclick] {
    transition: background 0.2s ease;
}

th[onclick]:hover {
    background: rgba(255, 255, 255, 0.1);
}

/* Responsividade para filtros */
@media (max-width: 1200px) {
    .filters-row {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .filters-row > * {
        width: 100%;
    }
}

/* ===========================================
   BOTÃO NOVO CLIENTE
   =========================================== */
.novo-cliente-container {
    background: linear-gradient(135deg, rgba(0, 191, 174, 0.1) 0%, rgba(45, 137, 62, 0.1) 100%);
    padding: 1.5rem;
    border-radius: var(--radius);
    border: 2px dashed var(--secondary-color);
    margin: 2rem 0;
}

.btn-novo-cliente {
    background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
    color: white;
    padding: 1rem 2rem;
    font-size: 1.1rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-radius: var(--radius);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    box-shadow: 0 6px 20px rgba(0, 191, 174, 0.3);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.btn-novo-cliente::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    transition: left 0.6s;
}

.btn-novo-cliente:hover::before {
    left: 100%;
}

.btn-novo-cliente:hover {
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 10px 30px rgba(0, 191, 174, 0.4);
    text-decoration: none;
    color: white;
}

.btn-novo-cliente i {
    font-size: 1.3rem;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

/* Paginação estilo empenhos - ATUALIZAÇÃO */
.pagination-container {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    margin: 2rem 0;
    padding: 1.5rem;
    background: var(--light-gray);
    border-radius: var(--radius);
}

.pagination {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.page-btn {
    padding: 0.5rem 1rem;
    border: 2px solid var(--border-color);
    background: white;
    color: var(--primary-color);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    font-weight: 600;
    min-width: 40px;
    text-align: center;
}

.page-btn:hover {
    border-color: var(--secondary-color);
    background: var(--secondary-color);
    color: white;
    transform: translateY(-2px);
}

.page-btn.active {
    background: var(--secondary-color);
    color: white;
    border-color: var(--secondary-color);
}

.page-btn:disabled,
.page-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.pagination-info {
    color: var(--medium-gray);
    font-size: 0.9rem;
    font-weight: 500;
}

/* Responsividade */
@media (max-width: 768px) {
    .btn-novo-cliente {
        width: 100%;
        justify-content: center;
        padding: 1.2rem;
        font-size: 1rem;
    }
    
    .novo-cliente-container {
        margin: 1.5rem 0;
        padding: 1rem;
    }
    
    .pagination-container {
        flex-direction: column;
        gap: 1rem;
    }
}

@media (max-width: 480px) {
    .btn-novo-cliente span {
        font-size: 0.9rem;
    }
    
    .page-btn {
        padding: 0.4rem 0.6rem;
        font-size: 0.85rem;
        min-width: 35px;
    }
}

/* Estilos para avisos de exclusão */
.deletion-warning {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    border: 2px solid #ffc107;
    border-radius: var(--radius);
    padding: 1rem;
    margin: 1rem 0;
    animation: warningPulse 2s infinite;
}

.deletion-danger {
    background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
    border: 2px solid #dc3545;
    border-radius: var(--radius);
    padding: 1rem;
    margin: 1rem 0;
    animation: dangerPulse 2s infinite;
}

@keyframes warningPulse {
    0%, 100% { border-color: #ffc107; box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.4); }
    50% { border-color: #e0a800; box-shadow: 0 0 0 5px rgba(255, 193, 7, 0.2); }
}

@keyframes dangerPulse {
    0%, 100% { border-color: #dc3545; box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
    50% { border-color: #c82333; box-shadow: 0 0 0 5px rgba(220, 53, 69, 0.2); }
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

        <!-- Botão Novo Cliente -->
<div class="novo-cliente-container" style="margin-bottom: 2rem; text-align: center;">
    <a href="cadastrar_clientes.php" class="btn btn-success btn-novo-cliente">
        <i class="fas fa-user-plus"></i>
        <span>Incluir Novo Cliente</span>
    </a>
</div>

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

       <!-- Filtros expandidos com estilo similar ao de empenhos -->
        <div class="filters-container">
            <form action="consultar_clientes.php" method="GET" id="filtersForm">
                <div class="filters-row">
                    <div class="search-group">
                        <label for="search">Buscar por:</label>
                        <input type="text" 
                               name="search" 
                               id="search" 
                               class="search-input"
                               placeholder="Nome, UASG, CNPJ, E-mail, Telefone ou Endereço..." 
                               value="<?php echo htmlspecialchars($searchTerm ?? ''); ?>"
                               autocomplete="off">
                    </div>
                    
                    <div class="search-group">
                        <label for="filter_cpf">CPF:</label>
                        <input type="text" 
                               name="filter_cpf" 
                               id="filter_cpf" 
                               class="filter-select"
                               placeholder="Digite o CPF..."
                               value="<?php echo htmlspecialchars($filterCpf ?? ''); ?>">
                    </div>
                    
                    <div class="search-group">
                        <label for="filter_nome_pessoa">Nome da Pessoa:</label>
                        <input type="text" 
                               name="filter_nome_pessoa" 
                               id="filter_nome_pessoa" 
                               class="filter-select"
                               placeholder="Nome da pessoa física..."
                               value="<?php echo htmlspecialchars($filterNomePessoa ?? ''); ?>">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> 
                        Filtrar
                    </button>
                    
                    <button type="button" class="btn btn-secondary" onclick="limparFiltros()">
                        <i class="fas fa-undo"></i> 
                        Limpar
                    </button>
                </div>
            </form>
        </div>

        <!-- Filtros avançados em seção separada -->
        <div class="filters-section">
            <button type="button" class="filters-toggle" onclick="toggleFilters()">
                <span><i class="fas fa-filter"></i> Filtros Avançados</span>
                <i class="fas fa-chevron-down" id="filterIcon"></i>
            </button>
            
            <div class="filters-content" id="filtersContent">
                <form action="consultar_clientes.php" method="GET">
                    <!-- Preserva busca principal -->
                    <?php if (!empty($searchTerm)): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>">
                    <?php endif; ?>
                    <?php if (!empty($filterCpf)): ?>
                        <input type="hidden" name="filter_cpf" value="<?php echo htmlspecialchars($filterCpf); ?>">
                    <?php endif; ?>
                    <?php if (!empty($filterNomePessoa)): ?>
                        <input type="hidden" name="filter_nome_pessoa" value="<?php echo htmlspecialchars($filterNomePessoa); ?>">
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

        <!-- Informações de resultados melhorada -->
        <?php if ($totalClientes > 0): ?>
            <div class="results-info">
                <div class="results-count">
                    <?php if ($searchTerm || $filterCpf || $filterNomePessoa || $filterCnpj || $filterEmail || $filterDate): ?>
                        Encontrados <strong><?php echo $totalClientes; ?></strong> cliente(s) 
                        <?php if ($searchTerm): ?>
                            para "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>"
                        <?php endif; ?>
                        <?php if ($filterCpf || $filterNomePessoa || $filterCnpj || $filterEmail || $filterDate): ?>
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

        <!-- Tabela de clientes simplificada -->
        <?php if (count($clientes) > 0): ?>
            <div class="table-container">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th onclick="ordenarTabela('uasg')" style="cursor: pointer;" title="Clique para ordenar">
                                    <i class="fas fa-hashtag"></i> UASG 
                                    <i class="fas fa-sort sort-icon" id="sort-uasg"></i>
                                </th>
                                <th onclick="ordenarTabela('nome')" style="cursor: pointer;" title="Clique para ordenar">
                                    <i class="fas fa-user"></i> Nome/Razão Social 
                                    <i class="fas fa-sort sort-icon" id="sort-nome"></i>
                                </th>
                                <th onclick="ordenarTabela('tipo')" style="cursor: pointer;" title="Clique para ordenar">
                                    <i class="fas fa-user-tag"></i> Tipo 
                                    <i class="fas fa-sort sort-icon" id="sort-tipo"></i>
                                </th>
                                <th onclick="ordenarTabela('documento')" style="cursor: pointer;" title="Clique para ordenar">
                                    <i class="fas fa-id-card"></i> CPF/CNPJ 
                                    <i class="fas fa-sort sort-icon" id="sort-documento"></i>
                                </th>
                                <th onclick="ordenarTabela('contato')" style="cursor: pointer;" title="Clique para ordenar">
                                    <i class="fas fa-phone"></i> Contato 
                                    <i class="fas fa-sort sort-icon" id="sort-contato"></i>
                                </th>
                                <th><i class="fas fa-cogs"></i> Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clientes as $cliente): ?>
                                <tr>
                                    <td>
                                        <span class="numero-empenho" onclick="openModal(<?php echo $cliente['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                            <?php echo htmlspecialchars($cliente['uasg']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div>
                                            <strong style="color: var(--primary-color); font-size: 1rem;">
                                                <?php echo htmlspecialchars($cliente['nome_principal']); ?>
                                            </strong>
                                            <?php if ($cliente['endereco']): ?>
                                                <br><small style="color: var(--medium-gray); display: flex; align-items: center; gap: 0.25rem; margin-top: 0.25rem;">
                                                    <i class="fas fa-map-marker-alt"></i> 
                                                    <?php echo htmlspecialchars(substr($cliente['endereco'], 0, 50) . (strlen($cliente['endereco']) > 50 ? '...' : '')); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($cliente['tipo_pessoa'] === 'PF'): ?>
                                            <span class="status-badge pendente">
                                                <i class="fas fa-user"></i>
                                                Pessoa Física
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge pago">
                                                <i class="fas fa-building"></i>
                                                Pessoa Jurídica
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <strong style="color: var(--success-color); font-size: 1rem;">
                                                <?php 
                                                if ($cliente['tipo_pessoa'] === 'PF' && $cliente['cpf']) {
                                                    echo htmlspecialchars($cliente['cpf']);
                                                } elseif ($cliente['tipo_pessoa'] === 'PJ' && $cliente['cnpj']) {
                                                    echo htmlspecialchars($cliente['cnpj']);
                                                } else {
                                                    echo 'Não informado';
                                                }
                                                ?>
                                            </strong>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <?php if ($cliente['telefone']): ?>
                                                <div style="display: flex; align-items: center; gap: 0.25rem; margin-bottom: 0.25rem;">
                                                    <i class="fas fa-phone" style="color: var(--success-color);"></i>
                                                    <span style="font-weight: 600;"><?php echo htmlspecialchars($cliente['telefone']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($cliente['email']): ?>
                                                <div style="display: flex; align-items: center; gap: 0.25rem;">
                                                    <i class="fas fa-envelope" style="color: var(--info-color);"></i>
                                                    <span style="font-size: 0.9rem; color: var(--medium-gray);"><?php echo htmlspecialchars($cliente['email']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!$cliente['telefone'] && !$cliente['email']): ?>
                                                <span style="color: var(--medium-gray); font-style: italic;">Não informado</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick="openModal(<?php echo $cliente['id']; ?>)" 
                                                    class="btn btn-primary btn-sm" title="Ver Detalhes">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <a href="consultar_vendas_cliente.php?cliente_uasg=<?php echo urlencode($cliente['uasg']); ?>" 
                                               class="btn btn-info btn-sm" title="Ver Vendas">
                                                <i class="fas fa-shopping-cart"></i>
                                            </a>
                                            <a href="cliente_empenho.php?cliente_uasg=<?php echo urlencode($cliente['uasg']); ?>" 
                                               class="btn btn-warning btn-sm" title="Ver Empenhos">
                                                <i class="fas fa-file-invoice-dollar"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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

        <!-- Paginação estilo empenhos -->
        <?php if ($totalPaginas > 1): ?>
        <div class="pagination-container">
            <div class="pagination-info">
                Mostrando <?php echo (($paginaAtual - 1) * $clientesPorPagina + 1); ?> a 
                <?php echo min($paginaAtual * $clientesPorPagina, $totalClientes); ?> de 
                <?php echo $totalClientes; ?> clientes
            </div>
            
            <div class="pagination">
                <!-- Botão Anterior -->
                <?php if ($paginaAtual > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $paginaAtual - 1])); ?>" class="page-btn">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php else: ?>
                    <span class="page-btn disabled">
                        <i class="fas fa-chevron-left"></i>
                    </span>
                <?php endif; ?>

                <!-- Números das páginas -->
                <?php
                $inicio = max(1, $paginaAtual - 2);
                $fim = min($totalPaginas, $paginaAtual + 2);
                
                if ($inicio > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>" class="page-btn">1</a>
                    <?php if ($inicio > 2): ?>
                        <span class="page-btn disabled">...</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $inicio; $i <= $fim; $i++): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>" 
                       class="page-btn <?php echo $i == $paginaAtual ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($fim < $totalPaginas): ?>
                    <?php if ($fim < $totalPaginas - 1): ?>
                        <span class="page-btn disabled">...</span>
                    <?php endif; ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $totalPaginas])); ?>" class="page-btn"><?php echo $totalPaginas; ?></a>
                <?php endif; ?>

                <!-- Botão Próximo -->
                <?php if ($paginaAtual < $totalPaginas): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $paginaAtual + 1])); ?>" class="page-btn">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="page-btn disabled">
                        <i class="fas fa-chevron-right"></i>
                    </span>
                <?php endif; ?>
            </div>
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
            <!-- Estatísticas detalhadas do cliente -->
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
                        <label for="tipo_pessoa"><i class="fas fa-user-tag"></i> Tipo de Pessoa</label>
                        <input type="text" name="tipo_pessoa_display" id="tipo_pessoa_display" class="form-control" readonly>
                        <input type="hidden" name="tipo_pessoa" id="tipo_pessoa">
                    </div>
                    
                    <div class="form-group">
                        <label for="uasg"><i class="fas fa-hashtag"></i> UASG *</label>
                        <input type="text" name="uasg" id="uasg" class="form-control" readonly required>
                    </div>
                </div>

                <div class="form-row">
                    <!-- Campos para Pessoa Jurídica -->
                    <div class="form-group pj-fields">
                        <label for="cnpj"><i class="fas fa-id-card"></i> CNPJ</label>
                        <input type="text" name="cnpj" id="cnpj" class="form-control" readonly>
                    </div>
                    
                    <div class="form-group pj-fields">
                        <label for="nome_orgaos"><i class="fas fa-building"></i> Nome do Órgão</label>
                        <input type="text" name="nome_orgaos" id="nome_orgaos" class="form-control" readonly>
                    </div>
                    
                    <!-- Campos para Pessoa Física -->
                    <div class="form-group pf-fields" style="display: none;">
                        <label for="cpf"><i class="fas fa-id-card"></i> CPF</label>
                        <input type="text" name="cpf" id="cpf" class="form-control" readonly>
                    </div>
                    
                    <div class="form-group pf-fields" style="display: none;">
                        <label for="nome_pessoa"><i class="fas fa-user"></i> Nome da Pessoa</label>
                        <input type="text" name="nome_pessoa" id="nome_pessoa" class="form-control" readonly>
                    </div>
                    
                    <div class="form-group pf-fields" style="display: none;">
                        <label for="rg"><i class="fas fa-address-card"></i> RG</label>
                        <input type="text" name="rg" id="rg" class="form-control" readonly>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="endereco"><i class="fas fa-map-marker-alt"></i> Endereço</label>
                    <input type="text" name="endereco" id="endereco" class="form-control" readonly>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Telefones</label>
                        <div id="phoneContainer">
                            <div class="phone-container">
                                <input type="text" name="telefone" id="telefone" class="form-control" readonly placeholder="Telefone principal">
                            </div>
                            <div class="phone-container" style="margin-top: 0.5rem;">
                                <input type="text" name="telefone2" id="telefone2" class="form-control" readonly placeholder="Telefone secundário">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> E-mails</label>
                        <div id="emailContainer">
                            <div class="email-container">
                                <input type="email" name="email" id="email" class="form-control" readonly placeholder="E-mail principal">
                            </div>
                            <div class="email-container" style="margin-top: 0.5rem;">
                                <input type="email" name="email2" id="email2" class="form-control" readonly placeholder="E-mail secundário">
                            </div>
                        </div>
                    </div>
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
    
    <!-- Informações das relações do cliente -->
    <div id="clientRelationsInfo" style="margin-top: 1rem;">
        <div id="noRelations" style="display: none;">
            <p style="color: var(--success-color); font-size: 0.9rem;">
                <i class="fas fa-check-circle"></i> 
                Este cliente não possui vendas ou empenhos associados.
            </p>
        </div>
        
        <div id="hasRelations" style="display: none;">
            <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 1rem; margin-top: 1rem;">
                <p style="color: #856404; font-weight: 600; margin: 0 0 0.5rem 0;">
                    <i class="fas fa-exclamation-triangle"></i> 
                    ATENÇÃO: Este cliente possui registros associados:
                </p>
                <ul id="relationsList" style="margin: 0.5rem 0 0 1.5rem; color: #856404; text-align: left;">
                </ul>
                <div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; padding: 0.75rem; margin-top: 1rem;">
                    <p style="color: #721c24; font-size: 0.85rem; margin: 0; font-weight: 600;">
                        <i class="fas fa-info-circle"></i> CONSEQUÊNCIAS DA EXCLUSÃO:
                    </p>
                    <ul style="color: #721c24; font-size: 0.8rem; margin: 0.5rem 0 0 1.2rem; text-align: left;">
                        <li>Os registros de vendas/empenhos serão mantidos no sistema</li>
                        <li>Mas serão marcados como "EXCLUÍDO" nas consultas</li>
                        <li>Relatórios podem apresentar dados inconsistentes</li>
                        <li>Não será possível recuperar a associação posteriormente</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
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
$javascript = <<<'JS'
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
                document.getElementById('tipo_pessoa').value = data.tipo_pessoa;
                document.getElementById('tipo_pessoa_display').value = data.tipo_pessoa === 'PF' ? 'Pessoa Física' : 'Pessoa Jurídica';
                document.getElementById('uasg').value = data.uasg || '';
                document.getElementById('endereco').value = data.endereco || '';
                document.getElementById('observacoes').value = data.observacoes || '';

                // Preenche campos baseado no tipo de pessoa
                if (data.tipo_pessoa === 'PF') {
                    // Mostra campos de PF
                    document.querySelectorAll('.pf-fields').forEach(field => field.style.display = 'block');
                    document.querySelectorAll('.pj-fields').forEach(field => field.style.display = 'none');
                    
                    document.getElementById('cpf').value = data.cpf || '';
                    document.getElementById('nome_pessoa').value = data.nome_pessoa || '';
                    document.getElementById('rg').value = data.rg || '';
                } else {
                    // Mostra campos de PJ
                    document.querySelectorAll('.pj-fields').forEach(field => field.style.display = 'block');
                    document.querySelectorAll('.pf-fields').forEach(field => field.style.display = 'none');
                    
                    document.getElementById('cnpj').value = data.cnpj || '';
                    document.getElementById('nome_orgaos').value = data.nome_orgaos || '';
                }

                // Preenche telefones
                document.getElementById('telefone').value = data.telefone || '';
                document.getElementById('telefone2').value = data.telefone2 || '';
                
                // Preenche e-mails
                document.getElementById('email').value = data.email || '';
                document.getElementById('email2').value = data.email2 || '';

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

    // Função para atualizar estatísticas detalhadas
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
            // CNPJ, CPF e UASG não podem ser editados
            if (input.name !== 'cnpj' && input.name !== 'cpf' && input.name !== 'uasg' && input.name !== 'tipo_pessoa_display') {
                input.readOnly = false;
            }
        });

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
        
        document.getElementById('saveBtn').style.display = 'none';
        document.getElementById('editBtn').style.display = 'inline-flex';
        document.getElementById('clientStats').style.display = 'none';
        
        // Remove status indicator se existir
        const statusIndicator = document.querySelector('.client-status');
        if (statusIndicator) {
            statusIndicator.remove();
        }
        
        // Oculta campos de PF e PJ
        document.querySelectorAll('.pf-fields').forEach(field => field.style.display = 'none');
        document.querySelectorAll('.pj-fields').forEach(field => field.style.display = 'none');
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
    
    // Busca e mostra relações do cliente
    const statsElement = document.getElementById('clientStats');
    if (statsElement && statsElement.style.display !== 'none') {
        const totalVendas = parseInt(document.getElementById('statVendas').textContent) || 0;
        const totalEmpenhos = parseInt(document.getElementById('statEmpenhos').textContent) || 0;
        
        const noRelationsDiv = document.getElementById('noRelations');
        const hasRelationsDiv = document.getElementById('hasRelations');
        const relationsList = document.getElementById('relationsList');
        const deleteBtn = document.querySelector('#deleteModal .btn-danger');
        
        if (totalVendas > 0 || totalEmpenhos > 0) {
            // Tem relações - mostra avisos
            noRelationsDiv.style.display = 'none';
            hasRelationsDiv.style.display = 'block';
            
            relationsList.innerHTML = '';
            if (totalVendas > 0) {
                relationsList.innerHTML += `<li><strong>${totalVendas}</strong> venda(s) registrada(s)</li>`;
            }
            if (totalEmpenhos > 0) {
                relationsList.innerHTML += `<li><strong>${totalEmpenhos}</strong> empenho(s) registrado(s)</li>`;
            }
            
            // Muda o botão para indicar exclusão forçada
            deleteBtn.className = 'btn btn-danger';
            deleteBtn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Excluir Mesmo Assim';
            deleteBtn.title = 'Excluir cliente e marcar registros associados como EXCLUÍDO';
            deleteBtn.style.background = '#dc3545';
            
        } else {
            // Não tem relações - exclusão normal
            noRelationsDiv.style.display = 'block';
            hasRelationsDiv.style.display = 'none';
            
            deleteBtn.className = 'btn btn-danger';
            deleteBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Sim, Excluir';
            deleteBtn.title = 'Excluir cliente';
            deleteBtn.style.background = '#dc3545';
        }
        
        deleteBtn.disabled = false;
    } else {
        // Se não tem estatísticas carregadas, assume exclusão normal
        document.getElementById('noRelations').style.display = 'block';
        document.getElementById('hasRelations').style.display = 'none';
    }
};

    window.closeDeleteModal = function() {
        const deleteModal = document.getElementById('deleteModal');
        deleteModal.style.display = 'none';
        delete window.clientToDelete;
    };

    window.deleteClient = function() {
    if (window.clientToDelete) {
        const totalVendas = parseInt(document.getElementById('statVendas').textContent) || 0;
        const totalEmpenhos = parseInt(document.getElementById('statEmpenhos').textContent) || 0;
        
        let confirmMessage = 'Tem certeza que deseja excluir este cliente?';
        
        if (totalVendas > 0 || totalEmpenhos > 0) {
            confirmMessage = `ATENÇÃO: Este cliente possui ${totalVendas} venda(s) e ${totalEmpenhos} empenho(s) associados.\n\n` +
                           `CONSEQUÊNCIAS:\n` +
                           `• Os registros serão mantidos mas marcados como "EXCLUÍDO"\n` +
                           `• Relatórios podem ficar inconsistentes\n` +
                           `• Não será possível recuperar a associação\n\n` +
                           `Tem CERTEZA ABSOLUTA que deseja continuar?`;
        }
        
        const confirmDelete = confirm(confirmMessage);
        if (confirmDelete) {
            // Se tem relações, pede confirmação adicional
            if (totalVendas > 0 || totalEmpenhos > 0) {
                const finalConfirm = confirm('ÚLTIMA CONFIRMAÇÃO:\n\nEsta é uma ação irreversível que pode afetar a integridade dos dados.\n\nContinuar mesmo assim?');
                if (!finalConfirm) {
                    return;
                }
            }
            
            // Mostra loading
            const deleteBtn = document.querySelector('#deleteModal .btn-danger');
            const originalText = deleteBtn.innerHTML;
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';
            deleteBtn.disabled = true;
            
            // Redireciona para exclusão
            window.location.href = 'consultar_clientes.php?delete_client_id=' + window.clientToDelete;
        }
    }
};

    // JavaScript adicional para funcionalidades de ordenação - SIMPLIFICADO
    function updateSortedColumnClass() {
        const table = document.querySelector('table');
        if (!table) return;
        
        // Remove classes antigas
        table.className = table.className.replace(/sorted-by-\d+/g, '');
        
        // Mapeia campos para índices de coluna - SIMPLIFICADO PARA 3 COLUNAS
        const fieldToColumnMap = {
            'uasg': 1,
            'nome_principal': 2,
            'nome_orgaos': 2,
            'nome_pessoa': 2
        };
        
        const currentOrderBy = phpData.orderBy;
        const columnIndex = fieldToColumnMap[currentOrderBy];
        
        if (columnIndex) {
            table.classList.add('sorted-by-' + columnIndex);
        }
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
            showToast('Copiado para a área de transferência!','success');
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
            background: ${type === 'success' ? 'var(--success-color)' : 'var(--info-color)'};
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
        const tipoPessoa = document.getElementById('tipo_pessoa').value;
        
        if (!uasg) {
            e.preventDefault();
            alert('UASG é campo obrigatório.');
            return;
        }

        if (tipoPessoa === 'PJ') {
            const nomeOrgao = document.getElementById('nome_orgaos').value.trim();
            if (!nomeOrgao) {
                e.preventDefault();
                alert('Nome do Órgão é obrigatório para Pessoa Jurídica.');
                return;
            }
        } else if (tipoPessoa === 'PF') {
            const nomePessoa = document.getElementById('nome_pessoa').value.trim();
            if (!nomePessoa) {
                e.preventDefault();
                alert('Nome da Pessoa é obrigatório para Pessoa Física.');
                return;
            }
        }
    });

    // Inicializa funcionalidades
    updateSortedColumnClass();
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
    console.log('- Tabela simplificada (3 colunas) ✓');
    console.log('- Paginação corrigida ✓');
    console.log('=====================================');
});


// Sistema de ordenação da tabela
let currentSort = {
    column: null,
    direction: 'asc'
};

function ordenarTabela(coluna) {
    console.log('🔄 Ordenando tabela por:', coluna);
    
    if (currentSort.column === coluna) {
        currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
    } else {
        currentSort.direction = 'asc';
    }
    
    currentSort.column = coluna;
    
    atualizarIconesOrdenacao(coluna, currentSort.direction);
    
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('ordenar', coluna);
    urlParams.set('direcao', currentSort.direction);
    urlParams.delete('pagina');
    
    window.location.href = window.location.pathname + '?' + urlParams.toString();
}

function atualizarIconesOrdenacao(colunaAtiva, direcao) {
    document.querySelectorAll('.sort-icon').forEach(icon => {
        icon.className = 'fas fa-sort sort-icon';
    });
    
    const iconAtivo = document.getElementById('sort-' + colunaAtiva);
    if (iconAtivo) {
        if (direcao === 'asc') {
            iconAtivo.className = 'fas fa-sort-up sort-icon sort-asc';
        } else {
            iconAtivo.className = 'fas fa-sort-down sort-icon sort-desc';
        }
    }
}

function limparFiltros() {
    const form = document.getElementById('filtersForm');
    if (form) {
        form.reset();
        form.submit();
    }
}

// Auto-submit dos filtros com delay
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const form = document.getElementById('filtersForm');
                if (form) form.submit();
            }, 800);
        });
    }
    
    // Event listeners para filtros específicos
    ['filter_cpf', 'filter_nome_pessoa'].forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            let timeout;
            input.addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    const form = document.getElementById('filtersForm');
                    if (form) form.submit();
                }, 1000);
            });
        }
    });
    
    // Inicializa ordenação baseada na URL
    const urlParams = new URLSearchParams(window.location.search);
    const ordenar = urlParams.get('ordenar');
    const direcao = urlParams.get('direcao') || 'asc';
    
    if (ordenar) {
        currentSort.column = ordenar;
        currentSort.direction = direcao;
        atualizarIconesOrdenacao(ordenar, direcao);
    }
});
JS;

// Chama a função endPage com o JavaScript
endPage(false, $javascript);


?>