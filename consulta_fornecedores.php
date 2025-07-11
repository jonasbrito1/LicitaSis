<?php 
// ===========================================
// CONSULTA DE FORNECEDORES - LICITASIS (CÓDIGO CORRIGIDO)
// Sistema Completo de Gestão de Licitações
// Versão: 2.0 - Integrado com sistema de permissões
// ===========================================

session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Includes necessários
require_once('db.php');
require_once('permissions.php');

// Inicialização do sistema de permissões
$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('fornecedores', 'view');

// Verificação de auditoria se existir
if (file_exists('includes/audit.php')) {
    include('includes/audit.php');
    if (function_exists('logUserAction')) {
        logUserAction('READ', 'fornecedores_consulta');
    }
}

// Permissão de administrador
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";
$fornecedores = [];
$searchTerm = "";
$statusFilter = "";

// Configuração da paginação
$itensPorPagina = 20;
$paginaAtual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($paginaAtual - 1) * $itensPorPagina;

// Processamento de mensagens da URL
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// ===========================================
// PROCESSAMENTO AJAX - ATUALIZAÇÃO DO FORNECEDOR
// ===========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fornecedor'])) {
    header('Content-Type: application/json');
    
    if (!$permissionManager->hasPagePermission('fornecedores', 'edit')) {
        echo json_encode(['error' => 'Sem permissão para editar fornecedores']);
        exit();
    }
    
    $response = ['success' => false];
    
    try {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            throw new Exception("ID do fornecedor inválido.");
        }

        $pdo->beginTransaction();
        
        // Busca dados antigos para auditoria
        $stmt_old = $pdo->prepare("SELECT * FROM fornecedores WHERE id = ?");
        $stmt_old->execute([$id]);
        $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);

        if (!$old_data) {
            throw new Exception("Fornecedor não encontrado.");
        }

        // Coleta e sanitiza os dados
        $dados = [
            'codigo' => trim(filter_input(INPUT_POST, 'codigo', FILTER_SANITIZE_STRING)),
            'nome' => trim(filter_input(INPUT_POST, 'nome', FILTER_SANITIZE_STRING)),
            'cnpj' => trim(filter_input(INPUT_POST, 'cnpj', FILTER_SANITIZE_STRING)),
            'endereco' => trim(filter_input(INPUT_POST, 'endereco', FILTER_SANITIZE_STRING)),
            'telefone' => trim(filter_input(INPUT_POST, 'telefone', FILTER_SANITIZE_STRING)),
            'email' => trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL)),
            'observacoes' => trim(filter_input(INPUT_POST, 'observacoes', FILTER_SANITIZE_STRING))
        ];

        // Validações básicas
        if (empty($dados['nome'])) {
            throw new Exception("Nome do fornecedor é obrigatório.");
        }

        if (!empty($dados['email']) && !filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email inválido.");
        }

        // Atualiza o fornecedor
        $sql = "UPDATE fornecedores SET 
                codigo = :codigo,
                nome = :nome,
                cnpj = :cnpj,
                endereco = :endereco,
                telefone = :telefone,
                email = :email,
                observacoes = :observacoes
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $dados['id'] = $id;
        
        if (!$stmt->execute($dados)) {
            throw new Exception("Erro ao atualizar o fornecedor no banco de dados.");
        }

        // Registra auditoria se disponível
        if (function_exists('logUserAction')) {
            logUserAction('UPDATE', 'fornecedores', $id, [
                'old' => $old_data,
                'new' => $dados
            ]);
        }

        $pdo->commit();
        $response['success'] = true;
        $response['message'] = "Fornecedor atualizado com sucesso!";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $response['error'] = $e->getMessage();
    }

    echo json_encode($response);
    exit();
}

// ===========================================
// PROCESSAMENTO AJAX - OBTER DADOS DO FORNECEDOR
// ===========================================
if (isset($_GET['get_fornecedor_id'])) {
    header('Content-Type: application/json');
    
    try {
        $id = filter_input(INPUT_GET, 'get_fornecedor_id', FILTER_VALIDATE_INT);
        
        if (!$id) {
            throw new Exception('ID do fornecedor inválido');
        }
        
        $sql = "SELECT 
                f.*,
                DATE_FORMAT(f.created_at, '%d/%m/%Y %H:%i') as data_cadastro_formatada
                FROM fornecedores f 
                WHERE f.id = :id";
                
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fornecedor) {
            throw new Exception('Fornecedor não encontrado');
        }

        // Busca estatísticas relacionadas (empenhos)
        $sql_stats = "SELECT 
            COUNT(DISTINCT e.id) as total_empenhos,
            COALESCE(SUM(e.valor_total_empenho), 0) as valor_total_empenhos
            FROM empenhos e 
            WHERE e.cnpj = :cnpj OR e.cliente_nome LIKE :nome";
        
        $stmt_stats = $pdo->prepare($sql_stats);
        $stmt_stats->bindParam(':cnpj', $fornecedor['cnpj']);
        $stmt_stats->bindValue(':nome', '%' . $fornecedor['nome'] . '%');
        $stmt_stats->execute();
        $estatisticas = $stmt_stats->fetch(PDO::FETCH_ASSOC);

        $fornecedor['estatisticas'] = $estatisticas;
        
        echo json_encode($fornecedor);
        exit();
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
}

// ===========================================
// PROCESSAMENTO AJAX - EXCLUSÃO DE FORNECEDOR
// ===========================================
if (isset($_POST['delete_fornecedor_id'])) { 
    header('Content-Type: application/json');
    
    if (!$permissionManager->hasPagePermission('fornecedores', 'delete')) {
        echo json_encode(['error' => 'Sem permissão para excluir fornecedores']);
        exit();
    }
    
    $id = $_POST['delete_fornecedor_id'];

    try {
        $pdo->beginTransaction();

        // Busca dados do fornecedor para auditoria
        $stmt_fornecedor = $pdo->prepare("SELECT * FROM fornecedores WHERE id = :id");
        $stmt_fornecedor->bindParam(':id', $id);
        $stmt_fornecedor->execute();
        $fornecedor_data = $stmt_fornecedor->fetch(PDO::FETCH_ASSOC);

        if (!$fornecedor_data) {
            throw new Exception("Fornecedor não encontrado.");
        }

        // Verifica se há empenhos relacionados
        $stmt_check = $pdo->prepare("SELECT COUNT(*) as total FROM empenhos WHERE cnpj = :cnpj");
        $stmt_check->bindParam(':cnpj', $fornecedor_data['cnpj']);
        $stmt_check->execute();
        $empenhos_relacionados = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];

        if ($empenhos_relacionados > 0) {
            throw new Exception("Não é possível excluir este fornecedor pois existem {$empenhos_relacionados} empenho(s) relacionado(s).");
        }

        // Exclui o fornecedor
        $sql = "DELETE FROM fornecedores WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new Exception("Nenhum fornecedor foi excluído. Verifique se o ID está correto.");
        }

        // Registra auditoria se disponível
        if (function_exists('logUserAction')) {
            logUserAction('DELETE', 'fornecedores', $id, $fornecedor_data);
        }

        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Fornecedor excluído com sucesso!']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => 'Erro ao excluir o fornecedor: ' . $e->getMessage()]);
    }
    exit();
}

// ===========================================
// CONSULTA PRINCIPAL COM FILTROS E PAGINAÇÃO
// ===========================================
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

try {
    // Parâmetros para consulta
    $params = [];
    $whereConditions = [];
    
    // Condições de filtro
    if (!empty($searchTerm)) {
        $whereConditions[] = "(f.codigo LIKE :searchTerm OR f.cnpj LIKE :searchTerm OR f.nome LIKE :searchTerm OR f.email LIKE :searchTerm)";
        $params[':searchTerm'] = "%$searchTerm%";
    }
    
    if (!empty($statusFilter)) {
        if ($statusFilter === 'ativo') {
            $whereConditions[] = "f.email IS NOT NULL AND f.email != ''";
        } elseif ($statusFilter === 'inativo') {
            $whereConditions[] = "(f.email IS NULL OR f.email = '')";
        }
    }
    
    $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

    // Consulta para contar total de registros
    $sqlCount = "SELECT COUNT(*) as total FROM fornecedores f $whereClause";
    $stmtCount = $pdo->prepare($sqlCount);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $totalRegistros = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPaginas = ceil($totalRegistros / $itensPorPagina);

    // Consulta principal com paginação
    $sql = "SELECT 
        f.id,
        f.codigo,
        f.nome,
        f.cnpj,
        f.endereco,
        f.telefone,
        f.email,
        f.observacoes,
        f.created_at,
        DATE_FORMAT(f.created_at, '%d/%m/%Y') as data_cadastro,
        CASE 
            WHEN f.email IS NOT NULL AND f.email != '' THEN 'Ativo'
            ELSE 'Inativo'
        END as status
    FROM fornecedores f 
    $whereClause
    ORDER BY f.nome ASC 
    LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $itensPorPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Erro na consulta: " . $e->getMessage();
    $fornecedores = [];
}

// ===========================================
// CÁLCULO DE ESTATÍSTICAS
// ===========================================
try {
    // Total de fornecedores
    $sqlTotal = "SELECT COUNT(*) AS total_fornecedores FROM fornecedores";
    $stmtTotal = $pdo->prepare($sqlTotal);
    $stmtTotal->execute();
    $totalFornecedores = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total_fornecedores'] ?? 0;
    
    // Fornecedores ativos (com email)
    $sqlAtivos = "SELECT COUNT(*) as fornecedores_ativos FROM fornecedores WHERE email IS NOT NULL AND email != ''";
    $stmtAtivos = $pdo->prepare($sqlAtivos);
    $stmtAtivos->execute();
    $fornecedoresAtivos = $stmtAtivos->fetch(PDO::FETCH_ASSOC)['fornecedores_ativos'] ?? 0;
    
    // Fornecedores inativos
    $fornecedoresInativos = $totalFornecedores - $fornecedoresAtivos;
    
    // Fornecedores com CNPJ
    $sqlCnpj = "SELECT COUNT(*) as com_cnpj FROM fornecedores WHERE cnpj IS NOT NULL AND cnpj != ''";
    $stmtCnpj = $pdo->prepare($sqlCnpj);
    $stmtCnpj->execute();
    $fornecedoresComCnpj = $stmtCnpj->fetch(PDO::FETCH_ASSOC)['com_cnpj'] ?? 0;
    
} catch (PDOException $e) {
    $error = "Erro ao calcular estatísticas: " . $e->getMessage();
    $totalFornecedores = 0;
    $fornecedoresAtivos = 0;
    $fornecedoresInativos = 0;
    $fornecedoresComCnpj = 0;
}

// Inclui o header do sistema se existir
if (file_exists('includes/header_template.php')) {
    include('includes/header_template.php');
    if (function_exists('renderHeader')) {
        renderHeader("Consulta de Fornecedores - LicitaSis", "fornecedores");
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Fornecedores - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
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

        .alert-error {
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
           ESTATÍSTICAS
           =========================================== */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: var(--light-gray);
            border-radius: var(--radius);
        }

        .stat-item {
            text-align: center;
            padding: 1.5rem;
            background: white;
            border-radius: var(--radius-sm);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .stat-item.stat-navegavel::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, rgba(0,191,174,0.1), rgba(45,137,62,0.1));
            transition: left 0.6s ease;
        }

        .stat-item.stat-navegavel:hover::before {
            left: 100%;
        }

        .stat-item.stat-navegavel:hover {
            transform: translateY(-8px) scale(1.08);
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--medium-gray);
            text-transform: uppercase;
            font-weight: 600;
        }

        .stat-icon {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 1.5rem;
            opacity: 0.3;
            transition: var(--transition);
        }

        .stat-navegavel:hover .stat-icon {
            opacity: 0.8;
            transform: scale(1.2);
        }

        /* ===========================================
           FILTROS
           =========================================== */
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
            min-width: 140px;
            font-size: 0.9rem;
            transition: var(--transition);
            cursor: pointer;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
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

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
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

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color) 0%, #e0a800 100%);
            color: #212529;
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.2);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #e0a800 0%, var(--warning-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(255, 193, 7, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color) 0%, #c82333 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.2);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #c82333 0%, var(--danger-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(220, 53, 69, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color) 0%, #218838 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #218838 0%, var(--success-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
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
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
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
            background: linear-gradient(135deg, var(--light-gray) 0%, #f1f3f4 100%);
            transform: scale(1.01);
        }

        table tbody tr:nth-child(even) {
            background: rgba(248, 249, 250, 0.5);
        }

        table tbody tr:nth-child(even):hover {
            background: linear-gradient(135deg, var(--light-gray) 0%, #f1f3f4 100%);
        }

        .fornecedor-link {
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

        .fornecedor-link:hover {
            color: var(--primary-color);
            background: rgba(45, 137, 62, 0.1);
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0, 191, 174, 0.2);
        }

        .fornecedor-link i {
            font-size: 0.8rem;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.ativo {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }

        .status-badge.inativo {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }

        /* ===========================================
           PAGINAÇÃO
           =========================================== */
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
            max-width: 900px;
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
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
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

        .modal-footer {
            padding: 1.5rem 2rem;
            background: var(--light-gray);
            border-radius: 0 0 var(--radius) var(--radius);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* ===========================================
           SEÇÕES DE DETALHES DO MODAL
           =========================================== */
        .fornecedor-details {
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

        /* ===========================================
           FORMULÁRIO DE EDIÇÃO NO MODAL
           =========================================== */
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        }

        .form-control:disabled {
            background: var(--light-gray);
            color: var(--medium-gray);
            cursor: not-allowed;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-control.is-invalid {
            border-color: var(--danger-color);
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
        }

        .form-control.is-valid {
            border-color: var(--success-color);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        .modal-buttons {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--border-color);
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
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
            color: var(--secondary-color);
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

            .filters-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .filters-row > * {
                width: 100%;
            }
        }

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

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }

            .pagination-container {
                flex-direction: column;
                gap: 1rem;
            }

            .table-container {
                font-size: 0.85rem;
            }

            table th, table td {
                padding: 0.75rem 0.5rem;
            }

            .fornecedor-link {
                padding: 0.25rem 0.5rem;
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

            .modal-footer {
                padding: 1rem 1.5rem;
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .container {
                margin: 1rem 0.5rem;
                padding: 1.25rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .stats-container {
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

            .fornecedor-link {
                font-size: 0.8rem;
                padding: 0.25rem 0.4rem;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>
        <i class="fas fa-truck"></i>
        Consulta de Fornecedores
    </h2>

    <!-- ===========================================
         MENSAGENS DE FEEDBACK
         =========================================== -->
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

    <!-- ===========================================
         ESTATÍSTICAS NAVEGÁVEIS
         =========================================== -->
    <div class="stats-container">
        <div class="stat-item stat-navegavel" onclick="navegarParaDetalhes('todos')">
            <div class="stat-number"><?php echo $totalFornecedores; ?></div>
            <div class="stat-label">Total de Fornecedores</div>
            <div class="stat-icon">
                <i class="fas fa-truck"></i>
            </div>
        </div>
        
        <div class="stat-item stat-navegavel" onclick="navegarParaDetalhes('ativo')">
            <div class="stat-number"><?php echo $fornecedoresAtivos; ?></div>
            <div class="stat-label">Fornecedores Ativos</div>
            <div class="stat-icon">
                <i class="fas fa-check-circle"></i>
            </div>
        </div>
        
        <div class="stat-item stat-navegavel" onclick="navegarParaDetalhes('inativo')">
            <div class="stat-number"><?php echo $fornecedoresInativos; ?></div>
            <div class="stat-label">Fornecedores Inativos</div>
            <div class="stat-icon">
                <i class="fas fa-times-circle"></i>
            </div>
        </div>
        
        <div class="stat-item stat-navegavel">
            <div class="stat-number"><?php echo $fornecedoresComCnpj; ?></div>
            <div class="stat-label">Com CNPJ Cadastrado</div>
            <div class="stat-icon">
                <i class="fas fa-id-card"></i>
            </div>
        </div>
    </div>

    <!-- ===========================================
         FILTROS AVANÇADOS
         =========================================== -->
    <div class="filters-container">
        <form action="consulta_fornecedores.php" method="GET" id="filtersForm">
            <div class="filters-row">
                <div class="search-group">
                    <label for="search">Buscar por:</label>
                    <input type="text" 
                           name="search" 
                           id="search" 
                           class="search-input"
                           placeholder="Nome, código, CNPJ ou email..." 
                           value="<?php echo htmlspecialchars($searchTerm ?? ''); ?>"
                           autocomplete="off">
                </div>
                
                <!-- Filtro por status -->
                <div class="search-group">
                    <label for="status">Status:</label>
                    <select name="status" id="status" class="filter-select">
                        <option value="">Todos os status</option>
                        <option value="ativo" <?php echo $statusFilter === 'ativo' ? 'selected' : ''; ?>>Ativos</option>
                        <option value="inativo" <?php echo $statusFilter === 'inativo' ? 'selected' : ''; ?>>Inativos</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> 
                    Filtrar
                </button>
                
                <button type="button" class="btn btn-secondary" onclick="limparFiltros()">
                    <i class="fas fa-undo"></i> 
                    Limpar
                </button>

                <a href="cadastro_fornecedores.php" class="btn btn-success">
                    <i class="fas fa-plus"></i> 
                    Novo Fornecedor
                </a>
            </div>
        </form>
    </div>

    <!-- ===========================================
         TABELA DE FORNECEDORES
         =========================================== -->
    <?php if (count($fornecedores) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th><i class="fas fa-id-card"></i> CNPJ</th>
                        <th><i class="fas fa-building"></i> Nome</th>
                        <th><i class="fas fa-envelope"></i> Email</th>
                        <th><i class="fas fa-phone"></i> Telefone</th>
                        <th><i class="fas fa-tags"></i> Status</th>
                        <th><i class="fas fa-calendar"></i> Cadastro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fornecedores as $fornecedor): ?>
                        <tr>
                            <td>
                                <span class="fornecedor-link" onclick="openModal(<?php echo $fornecedor['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                    <?php echo htmlspecialchars($fornecedor['cnpj'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($fornecedor['nome'] ?? 'N/A'); ?></strong>
                                <?php if ($fornecedor['codigo']): ?>
                                    <br><small style="color: var(--medium-gray);">Código: <?php echo htmlspecialchars($fornecedor['codigo']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($fornecedor['email'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($fornecedor['telefone'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="status-badge <?php echo strtolower($fornecedor['status']); ?>">
                                    <i class="fas fa-<?php echo $fornecedor['status'] === 'Ativo' ? 'check-circle' : 'times-circle'; ?>"></i>
                                    <?php echo $fornecedor['status']; ?>
                                </span>
                            </td>
                            <td><?php echo $fornecedor['data_cadastro']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ===========================================
             PAGINAÇÃO
             =========================================== -->
        <?php if ($totalPaginas > 1): ?>
        <div class="pagination-container">
            <div class="pagination-info">
                Mostrando <?php echo (($paginaAtual - 1) * $itensPorPagina + 1); ?> a 
                <?php echo min($paginaAtual * $itensPorPagina, $totalRegistros); ?> de 
                <?php echo $totalRegistros; ?> fornecedores
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

    <?php else: ?>
        <!-- ===========================================
             MENSAGEM SEM RESULTADOS
             =========================================== -->
        <div class="no-results">
            <i class="fas fa-search"></i>
            <p>Nenhum fornecedor encontrado.</p>
            <small>Tente ajustar os filtros ou cadastre um novo fornecedor.</small>
            <a href="cadastro_fornecedores.php" class="btn btn-success" style="margin-top: 1rem;">
                <i class="fas fa-plus"></i> Cadastrar Primeiro Fornecedor
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- ===========================================
     MODAL DE DETALHES DO FORNECEDOR
     =========================================== -->
<div id="fornecedorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-truck"></i> 
                Detalhes do Fornecedor
            </h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body" id="modalBody">
            <div class="loading-spinner" style="text-align: center; padding: 3rem;">
                <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes...</p>
            </div>
        </div>
        <div class="modal-footer" id="modalFooter" style="display: none;">
            <button class="btn btn-warning" onclick="editarFornecedor()" id="editarBtn">
                <i class="fas fa-edit"></i> Editar
            </button>
            
            <button class="btn btn-danger" onclick="confirmarExclusao()" id="excluirBtn">
                <i class="fas fa-trash"></i> Excluir
            </button>
            
            <button class="btn btn-primary" onclick="imprimirFornecedor()">
                <i class="fas fa-print"></i> Imprimir
            </button>
            
            <button class="btn btn-secondary" onclick="closeModal()">
                <i class="fas fa-times"></i> Fechar
            </button>
        </div>
    </div>
</div>

<script>
// ===========================================
// SISTEMA COMPLETO DE CONSULTA DE FORNECEDORES
// JavaScript Completo - LicitaSis v1.0
// ===========================================

// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let currentFornecedorId = null;
let currentFornecedorData = null;
let isEditingFornecedor = false;

// ===========================================
// FUNÇÕES DE CONTROLE DO MODAL
// ===========================================

/**
 * Abre o modal com detalhes do fornecedor
 * @param {number} fornecedorId - ID do fornecedor
 */
function openModal(fornecedorId) {
    console.log('🔍 Abrindo modal para fornecedor ID:', fornecedorId);
    
    currentFornecedorId = fornecedorId;
    const modal = document.getElementById('fornecedorModal');
    const modalBody = document.getElementById('modalBody');
    const modalFooter = document.getElementById('modalFooter');
    
    // Mostra o modal
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Resetar estado do modal
    resetModalState();
    
    // Mostra loading
    modalBody.innerHTML = `
        <div class="loading-spinner" style="text-align: center; padding: 3rem;">
            <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
            <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes do fornecedor...</p>
        </div>
    `;
    modalFooter.style.display = 'none';
    
    // Busca dados do fornecedor
    const url = `consulta_fornecedores.php?get_fornecedor_id=${fornecedorId}&t=${Date.now()}`;
    console.log('📡 Fazendo requisição para:', url);
    
    fetch(url)
        .then(response => {
            console.log('📡 Resposta recebida:', response.status, response.statusText);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('✅ Dados do fornecedor recebidos:', data);
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            currentFornecedorData = data;
            renderFornecedorDetails(data);
            modalFooter.style.display = 'flex';
            
            console.log('✅ Modal renderizado com sucesso para fornecedor:', data.nome);
        })
        .catch(error => {
            console.error('❌ Erro ao carregar fornecedor:', error);
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 3rem; color: var(--danger-color);">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                    <p style="font-size: 1.1rem; margin-bottom: 1rem;">Erro ao carregar fornecedor</p>
                    <p style="color: var(--medium-gray);">${error.message}</p>
                    <button class="btn btn-warning" onclick="openModal(${fornecedorId})" style="margin: 1rem 0.5rem;">
                        <i class="fas fa-redo"></i> Tentar Novamente
                    </button>
                    <button class="btn btn-secondary" onclick="closeModal()">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                </div>
            `;
        });
}

/**
 * Reseta o estado do modal
 */
function resetModalState() {
    const editForm = document.getElementById('fornecedorEditForm');
    if (editForm) {
        editForm.style.display = 'none';
    }
    
    const viewMode = document.getElementById('fornecedorViewMode');
    if (viewMode) {
        viewMode.style.display = 'block';
    }
    
    isEditingFornecedor = false;
}

/**
 * Renderiza os detalhes completos do fornecedor no modal
 * @param {Object} fornecedor - Dados do fornecedor
 */
function renderFornecedorDetails(fornecedor) {
    console.log('🎨 Renderizando detalhes do fornecedor:', fornecedor);
    
    const modalBody = document.getElementById('modalBody');
    
    // Prepara datas
    const dataFormatada = fornecedor.data_cadastro_formatada || 'N/A';
    const status = fornecedor.email && fornecedor.email !== '' ? 'Ativo' : 'Inativo';
    const statusClass = status === 'Ativo' ? 'ativo' : 'inativo';

    modalBody.innerHTML = `
        <div class="fornecedor-details">
            <!-- Formulário de Edição (inicialmente oculto) -->
            <form id="fornecedorEditForm" style="display: none;">
                <input type="hidden" name="id" value="${fornecedor.id}">
                <input type="hidden" name="update_fornecedor" value="1">
                
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-edit"></i>
                        Editar Informações do Fornecedor
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Código</div>
                                <input type="text" name="codigo" class="form-control" value="${fornecedor.codigo || ''}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Nome do Fornecedor *</div>
                                <input type="text" name="nome" class="form-control" value="${fornecedor.nome || ''}" required>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">CNPJ</div>
                                <input type="text" name="cnpj" class="form-control" value="${fornecedor.cnpj || ''}" maxlength="18">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Telefone</div>
                                <input type="text" name="telefone" class="form-control" value="${fornecedor.telefone || ''}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Email</div>
                                <input type="email" name="email" class="form-control" value="${fornecedor.email || ''}">
                            </div>
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Endereço</div>
                                <input type="text" name="endereco" class="form-control" value="${fornecedor.endereco || ''}">
                            </div>
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Observações</div>
                                <textarea name="observacoes" class="form-control" rows="4">${fornecedor.observacoes || ''}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-buttons">
                    <button type="submit" class="btn btn-success" id="salvarBtn">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="cancelarEdicao()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-danger" onclick="confirmarExclusaoEdicao()" id="excluirEdicaoBtn">
                        <i class="fas fa-trash"></i> Excluir Fornecedor
                    </button>
                </div>
            </form>

            <!-- Visualização Normal (inicialmente visível) -->
            <div id="fornecedorViewMode">
                <!-- Informações Básicas -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-info-circle"></i>
                        Informações Básicas
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Nome do Fornecedor</div>
                                <div class="detail-value highlight">${fornecedor.nome || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Código</div>
                                <div class="detail-value">${fornecedor.codigo || 'Não informado'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">CNPJ</div>
                                <div class="detail-value">${fornecedor.cnpj || 'Não informado'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">
                                    <span class="status-badge ${statusClass}">
                                        <i class="fas fa-${status === 'Ativo' ? 'check-circle' : 'times-circle'}"></i>
                                        ${status}
                                    </span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data de Cadastro</div>
                                <div class="detail-value">${dataFormatada}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Informações de Contato -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-address-book"></i>
                        Informações de Contato
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Email</div>
                                <div class="detail-value">${fornecedor.email || 'Não informado'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Telefone</div>
                                <div class="detail-value">${fornecedor.telefone || 'Não informado'}</div>
                            </div>
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Endereço</div>
                                <div class="detail-value">${fornecedor.endereco || 'Não informado'}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estatísticas Relacionadas -->
                ${fornecedor.estatisticas ? `
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-chart-bar"></i>
                        Estatísticas
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Total de Empenhos</div>
                                <div class="detail-value highlight">${fornecedor.estatisticas.total_empenhos || 0}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Valor Total em Empenhos</div>
                                <div class="detail-value highlight">R$ ${parseFloat(fornecedor.estatisticas.valor_total_empenhos || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                            </div>
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Observações -->
                ${fornecedor.observacoes ? `
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-file-alt"></i>
                        Observações
                    </div>
                    <div class="detail-content">
                        <div class="detail-value">${fornecedor.observacoes}</div>
                    </div>
                </div>
                ` : ''}
            </div>
        </div>
    `;

    // Adiciona event listener para o formulário de edição
    const editForm = document.getElementById('fornecedorEditForm');
    if (editForm) {
        editForm.addEventListener('submit', salvarEdicaoFornecedor);
    }

    // Adiciona máscara para CNPJ
    const cnpjInput = editForm ? editForm.querySelector('input[name="cnpj"]') : null;
    if (cnpjInput) {
        cnpjInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length <= 14) {
                value = value.replace(/^(\d{2})(\d)/, '$1.$2');
                value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
                value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
                value = value.replace(/(\d{4})(\d)/, '$1-$2');
                e.target.value = value;
            }
        });
    }
    
    console.log('✅ Detalhes do fornecedor renderizados com sucesso');
}

/**
 * Fecha o modal
 */
function closeModal() {
    // Verifica se está em modo de edição
    const editForm = document.getElementById('fornecedorEditForm');
    const isEditing = editForm && editForm.style.display !== 'none';
    
    if (isEditing) {
        const confirmClose = confirm(
            'Você está editando o fornecedor.\n\n' +
            'Tem certeza que deseja fechar sem salvar as alterações?\n\n' +
            'As alterações não salvas serão perdidas.'
        );
        
        if (!confirmClose) {
            return; // Não fecha o modal
        }
    }
    
    // Fecha o modal
    const modal = document.getElementById('fornecedorModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Limpa dados
    currentFornecedorId = null;
    currentFornecedorData = null;
    isEditingFornecedor = false;
    
    // Reseta o modal para o próximo uso
    resetModalState();
    
    console.log('✅ Modal fechado');
}

// ===========================================
// FUNÇÕES DE EDIÇÃO DO FORNECEDOR
// ===========================================

/**
 * Ativa o modo de edição do fornecedor
 */
function editarFornecedor() {
    console.log('🖊️ Ativando modo de edição do fornecedor');
    
    const viewMode = document.getElementById('fornecedorViewMode');
    const editForm = document.getElementById('fornecedorEditForm');
    const editarBtn = document.getElementById('editarBtn');
    
    if (viewMode) viewMode.style.display = 'none';
    if (editForm) editForm.style.display = 'block';
    if (editarBtn) editarBtn.style.display = 'none';
    
    isEditingFornecedor = true;
    
    showToast('Modo de edição ativado', 'info');
}

/**
 * Cancela a edição do fornecedor
 */
function cancelarEdicao() {
    const confirmCancel = confirm(
        'Tem certeza que deseja cancelar a edição?\n\n' +
        'Todas as alterações não salvas serão perdidas.'
    );
    
    if (confirmCancel) {
        const viewMode = document.getElementById('fornecedorViewMode');
        const editForm = document.getElementById('fornecedorEditForm');
        const editarBtn = document.getElementById('editarBtn');
        
        if (viewMode) viewMode.style.display = 'block';
        if (editForm) editForm.style.display = 'none';
        if (editarBtn) editarBtn.style.display = 'inline-flex';
        
        isEditingFornecedor = false;
        
        showToast('Edição cancelada', 'info');
    }
}

/**
 * Salva a edição do fornecedor
 */
function salvarEdicaoFornecedor(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    const submitBtn = document.getElementById('salvarBtn');
    
    // Desabilita o botão e mostra loading
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    }
    
    fetch('consulta_fornecedores.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Fornecedor atualizado com sucesso!', 'success');
            
            // Recarrega os dados do modal
            setTimeout(() => {
                openModal(currentFornecedorId);
            }, 1000);
            
        } else {
            throw new Error(data.error || 'Erro ao salvar fornecedor');
        }
    })
    .catch(error => {
        console.error('Erro ao salvar fornecedor:', error);
        showToast('Erro ao salvar: ' + error.message, 'error');
    })
    .finally(() => {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Salvar Alterações';
        }
    });
}

/**
 * Confirma exclusão durante a edição
 */
function confirmarExclusaoEdicao() {
    if (!currentFornecedorData) return;
    
    const confirmMessage = 
        `⚠️ ATENÇÃO: EXCLUSÃO PERMANENTE ⚠️\n\n` +
        `Tem certeza que deseja EXCLUIR permanentemente este fornecedor?\n\n` +
        `Fornecedor: ${currentFornecedorData.nome || 'N/A'}\n` +
        `CNPJ: ${currentFornecedorData.cnpj || 'N/A'}\n\n` +
        `⚠️ Esta ação NÃO PODE ser desfeita!\n\n` +
        `Digite "CONFIRMAR" para prosseguir:`;
    
    const confirmacao = prompt(confirmMessage);
    
    if (confirmacao === 'CONFIRMAR') {
        excluirFornecedor();
    } else if (confirmacao !== null) {
        showToast('Exclusão cancelada - confirmação incorreta', 'warning');
    }
}

// ===========================================
// FUNÇÕES DE AÇÃO DO FORNECEDOR
// ===========================================

/**
 * Exclui fornecedor
 */
function excluirFornecedor() {
    if (!currentFornecedorId) return;
    
    const excluirBtn = document.getElementById('excluirBtn') || document.getElementById('excluirEdicaoBtn');
    if (excluirBtn) {
        excluirBtn.disabled = true;
        excluirBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';
    }
    
    fetch('consulta_fornecedores.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `delete_fornecedor_id=${currentFornecedorId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Fornecedor excluído com sucesso!', 'success');
            
            // Fecha o modal
            closeModal();
            
            // Recarrega a página após um breve delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
            
        } else {
            throw new Error(data.error || 'Erro ao excluir fornecedor');
        }
    })
    .catch(error => {
        console.error('Erro ao excluir fornecedor:', error);
        showToast('Erro ao excluir: ' + error.message, 'error');
    })
    .finally(() => {
        if (excluirBtn) {
            excluirBtn.disabled = false;
            excluirBtn.innerHTML = '<i class="fas fa-trash"></i> Excluir';
        }
    });
}

/**
 * Confirma exclusão (modo visualização)
 */
function confirmarExclusao() {
    if (!currentFornecedorData) return;
    
    const confirmMessage = 
        `⚠️ ATENÇÃO: EXCLUSÃO PERMANENTE ⚠️\n\n` +
        `Tem certeza que deseja EXCLUIR permanentemente este fornecedor?\n\n` +
        `Fornecedor: ${currentFornecedorData.nome || 'N/A'}\n` +
        `CNPJ: ${currentFornecedorData.cnpj || 'N/A'}\n\n` +
        `⚠️ Esta ação NÃO PODE ser desfeita!\n\n` +
        `Digite "CONFIRMAR" para prosseguir:`;
    
    const confirmacao = prompt(confirmMessage);
    
    if (confirmacao === 'CONFIRMAR') {
        excluirFornecedor();
    } else if (confirmacao !== null) {
        showToast('Exclusão cancelada - confirmação incorreta', 'warning');
    }
}

/**
 * Imprime fornecedor
 */
function imprimirFornecedor() {
    if (!currentFornecedorId) return;
    
    const printUrl = `imprimir_fornecedor.php?id=${currentFornecedorId}`;
    window.open(printUrl, '_blank', 'width=800,height=600');
}

// ===========================================
// FUNÇÕES DE FILTROS E NAVEGAÇÃO
// ===========================================

/**
 * Limpa todos os filtros
 */
function limparFiltros() {
    const form = document.getElementById('filtersForm');
    if (form) {
        form.reset();
        form.submit();
    }
}

/**
 * Navega para detalhes baseado em estatísticas
 */
function navegarParaDetalhes(tipo) {
    let url = 'consulta_fornecedores.php?';
    
    switch(tipo) {
        case 'todos':
            url = 'consulta_fornecedores.php';
            break;
        case 'ativo':
            url += 'status=ativo';
            break;
        case 'inativo':
            url += 'status=inativo';
            break;
        default:
            showToast('Filtro não implementado: ' + tipo, 'warning');
            return;
    }
    
    window.location.href = url;
}

// ===========================================
// UTILITÁRIOS
// ===========================================

/**
 * Sistema de notificações toast
 */
function showToast(message, type = 'info', duration = 4000) {
    // Remove toast existente se houver
    const existingToast = document.getElementById('toast');
    if (existingToast) {
        existingToast.remove();
    }
    
    const toast = document.createElement('div');
    toast.id = 'toast';
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        padding: 1rem 1.5rem;
        border-radius: var(--radius);
        color: white;
        font-weight: 600;
        font-size: 0.95rem;
        max-width: 400px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        transform: translateX(100%);
        transition: transform 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    `;
    
    // Define cor baseada no tipo
    let backgroundColor, icon;
    switch(type) {
        case 'success':
            backgroundColor = 'var(--success-color)';
            icon = 'fas fa-check-circle';
            break;
        case 'error':
            backgroundColor = 'var(--danger-color)';
            icon = 'fas fa-exclamation-triangle';
            break;
        case 'warning':
            backgroundColor = 'var(--warning-color)';
            icon = 'fas fa-exclamation-circle';
            break;
        default:
            backgroundColor = 'var(--info-color)';
            icon = 'fas fa-info-circle';
    }
    
    toast.style.background = backgroundColor;
    toast.innerHTML = `
        <i class="${icon}"></i>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; color: white; font-size: 1.2rem; cursor: pointer; padding: 0; margin-left: auto;">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    document.body.appendChild(toast);
    
    // Anima entrada
    setTimeout(() => {
        toast.style.transform = 'translateX(0)';
    }, 100);
    
    // Remove automaticamente
    setTimeout(() => {
        if (toast.parentElement) {
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 300);
        }
    }, duration);
}

// ===========================================
// INICIALIZAÇÃO E EVENT LISTENERS
// ===========================================

/**
 * Inicialização quando a página carrega
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 LicitaSis - Sistema de Consulta de Fornecedores carregado');
    
    // Event listener para fechar modal com ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const modal = document.getElementById('fornecedorModal');
            
            if (modal && modal.style.display === 'block') {
                closeModal();
            }
        }
    });
    
    // Event listener para clicar fora do modal
    const modal = document.getElementById('fornecedorModal');
    if (modal) {
        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    }
    
    // Auto-submit do formulário de filtros com delay
    const searchInput = document.getElementById('search');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const form = document.getElementById('filtersForm');
                if (form) form.submit();
            }, 800); // Delay de 800ms
        });
    }
    
    // Inicializa tooltips se necessário
    initializeTooltips();
    
    // Animação de entrada
    const container = document.querySelector('.container');
    if (container) {
        container.style.opacity = '0';
        container.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            container.style.opacity = '1';
            container.style.transform = 'translateY(0)';
        }, 100);
    }
    
    console.log('✅ Todos os event listeners inicializados');
});

/**
 * Inicializa tooltips para elementos que precisam
 */
function initializeTooltips() {
    // Implementação básica de tooltips
    document.querySelectorAll('[title]').forEach(element => {
        element.addEventListener('mouseenter', function(e) {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = this.title;
            tooltip.style.cssText = `
                position: absolute;
                background: rgba(0,0,0,0.8);
                color: white;
                padding: 0.5rem 0.75rem;
                border-radius: 4px;
                font-size: 0.8rem;
                z-index: 10000;
                pointer-events: none;
                max-width: 200px;
                word-wrap: break-word;
            `;
            
            document.body.appendChild(tooltip);
            
            const rect = this.getBoundingClientRect();
            tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
            tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
            
            this.setAttribute('data-original-title', this.title);
            this.removeAttribute('title');
            
            this.addEventListener('mouseleave', function() {
                tooltip.remove();
                this.title = this.getAttribute('data-original-title');
                this.removeAttribute('data-original-title');
            }, { once: true });
        });
    });
}

// Adiciona estilos CSS dinâmicos
const dynamicStyles = document.createElement('style');
dynamicStyles.textContent = `
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .tooltip {
        animation: fadeIn 0.2s ease;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    /* Variáveis CSS para compatibilidade */
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
`;
document.head.appendChild(dynamicStyles);

// ===========================================
// FUNÇÕES AUXILIARES PARA COMPATIBILIDADE
// ===========================================

/**
 * Formatação de moeda brasileira
 */
function formatarMoeda(valor) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    }).format(valor);
}

/**
 * Formatação de data brasileira
 */
function formatarData(data) {
    if (!data) return 'N/A';
    
    try {
        const date = new Date(data);
        return date.toLocaleDateString('pt-BR');
    } catch {
        return 'Data inválida';
    }
}

/**
 * Máscara para CNPJ
 */
function aplicarMascaraCNPJ(input) {
    if (!input) return;
    
    input.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        
        if (value.length <= 14) {
            value = value.replace(/^(\d{2})(\d)/, '$1.$2');
            value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
            value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
            value = value.replace(/(\d{4})(\d)/, '$1-$2');
            e.target.value = value;
        }
    });
}

/**
 * Debounce para otimizar performance
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Validação de email
 */
function validarEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

/**
 * Validação de CNPJ
 */
function validarCNPJ(cnpj) {
    cnpj = cnpj.replace(/[^\d]+/g, '');
    
    if (cnpj.length !== 14) return false;
    
    // Elimina CNPJs inválidos conhecidos
    if (/^(\d)\1+$/.test(cnpj)) return false;
    
    // Validação dos dígitos verificadores
    let tamanho = cnpj.length - 2;
    let numeros = cnpj.substring(0, tamanho);
    let digitos = cnpj.substring(tamanho);
    let soma = 0;
    let pos = tamanho - 7;
    
    for (let i = tamanho; i >= 1; i--) {
        soma += numeros.charAt(tamanho - i) * pos--;
        if (pos < 2) pos = 9;
    }
    
    let resultado = soma % 11 < 2 ? 0 : 11 - soma % 11;
    if (resultado != digitos.charAt(0)) return false;
    
    tamanho = tamanho + 1;
    numeros = cnpj.substring(0, tamanho);
    soma = 0;
    pos = tamanho - 7;
    
    for (let i = tamanho; i >= 1; i--) {
        soma += numeros.charAt(tamanho - i) * pos--;
        if (pos < 2) pos = 9;
    }
    
    resultado = soma % 11 < 2 ? 0 : 11 - soma % 11;
    if (resultado != digitos.charAt(1)) return false;
    
    return true;
}

console.log('✅ Sistema de Consulta de Fornecedores totalmente carregado e funcional!');
</script>

</body>
</html>