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
include('includes/audit.php');

$permissionManager = initPermissions($pdo);

// Verifica se o usuário tem permissão para acessar empenhos
$permissionManager->requirePermission('empenhos', 'view');

// Registra acesso à página
logUserAction('READ', 'empenhos_cliente_detalhes');

$error = "";
$success = "";

// Pegamos o uasg do cliente que foi passado via GET
$cliente_uasg = isset($_GET['cliente_uasg']) ? $_GET['cliente_uasg'] : '';

if (empty($cliente_uasg)) {
    header("Location: consultar_clientes.php");
    exit();
}

// NOVA FUNCIONALIDADE: Verifica se há uma solicitação de atualização da classificação (AJAX)
if (isset($_POST['update_classificacao'])) {
    $id = $_POST['empenho_id'];
    $classificacao = $_POST['classificacao'];

    // Valida se a classificação é válida (baseado na estrutura da tabela)
    $classificacoes_validas = ['Pendente', 'Faturado', 'Entregue', 'Liquidado', 'Pago', 'Cancelado'];
    
    if (!in_array($classificacao, $classificacoes_validas)) {
        echo json_encode(['error' => 'Classificação inválida']);
        exit();
    }

    // Verifica permissão
    if (!$permissionManager->hasPagePermission('empenhos', 'edit')) {
        echo json_encode(['error' => 'Sem permissão para editar empenhos']);
        exit();
    }

    try {
        // Busca dados antigos para auditoria
        $stmt_old = $pdo->prepare("SELECT classificacao FROM empenhos WHERE id = :id");
        $stmt_old->bindParam(':id', $id);
        $stmt_old->execute();
        $old_classificacao = $stmt_old->fetchColumn();

        // Atualiza a classificação
        $sql = "UPDATE empenhos SET classificacao = :classificacao WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':classificacao', $classificacao, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        // Registra auditoria
        logUserAction('UPDATE', 'empenhos', $id, [
            'campo' => 'classificacao',
            'valor_anterior' => $old_classificacao,
            'valor_novo' => $classificacao
        ]);

        echo json_encode(['success' => true, 'message' => 'Classificação atualizada com sucesso!']);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao atualizar classificação: ' . $e->getMessage()]);
    }
    exit();
}

// Função auxiliar para evitar problemas com htmlspecialchars
function safe_htmlspecialchars($value) {
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Buscamos os dados do cliente
$cliente = null;
try {
    $sql_cliente = "SELECT * FROM clientes WHERE uasg = :uasg";
    $stmt_cliente = $pdo->prepare($sql_cliente);
    $stmt_cliente->bindParam(':uasg', $cliente_uasg);
    $stmt_cliente->execute();
    
    $cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);
    
    if (!$cliente) {
        throw new Exception("Cliente não encontrado.");
    }
} catch (PDOException $e) {
    $error = "Erro ao buscar cliente: " . $e->getMessage();
    error_log("Erro ao buscar cliente: " . $e->getMessage());
}

// Buscamos os empenhos associados a esse cliente
$empenhos = [];
$total_empenhos = 0;
$valor_total_empenhos = 0;
$empenhos_pendentes = 0;
$empenhos_faturados = 0;
$empenhos_entregues = 0;
$empenhos_liquidados = 0;
$empenhos_pagos = 0;
$empenhos_cancelados = 0;

try {
    // Busca empenhos com produtos relacionados
    $sql_empenhos = "SELECT 
                    e.id AS empenho_id,
                    e.numero,
                    e.cliente_uasg,
                    e.cliente_nome,
                    e.valor_total_empenho,
                    e.classificacao,
                    e.pregao,
                    e.created_at AS data_empenho,
                    e.upload,
                    COALESCE(ep.id, 0) AS empenho_produto_id,
                    COALESCE(ep.produto_id, 0) AS produto_id,
                    COALESCE(ep.quantidade, 0) AS quantidade,
                    COALESCE(ep.valor_unitario, 0) AS valor_unitario,
                    COALESCE(ep.valor_total, 0) AS valor_produto,
                    COALESCE(ep.descricao_produto, 'Produto não especificado') AS descricao_produto,
                    COALESCE(p.nome, ep.descricao_produto) AS produto_nome,
                    COALESCE(p.codigo, '') AS produto_codigo,
                    COALESCE(p.observacao, '') AS produto_observacao
                   FROM empenhos e
                   LEFT JOIN empenho_produtos ep ON e.id = ep.empenho_id
                   LEFT JOIN produtos p ON ep.produto_id = p.id
                   WHERE e.cliente_uasg = :uasg
                   ORDER BY e.created_at DESC, e.id DESC, ep.id ASC";
    
    $stmt_empenhos = $pdo->prepare($sql_empenhos);
    $stmt_empenhos->bindParam(':uasg', $cliente_uasg);
    $stmt_empenhos->execute();
    
    $empenhos_raw = $stmt_empenhos->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupa os produtos por empenho
    $empenhos_agrupados = [];
    foreach ($empenhos_raw as $row) {
        $empenho_id = $row['empenho_id'];
        
        if (!isset($empenhos_agrupados[$empenho_id])) {
            $empenhos_agrupados[$empenho_id] = [
                'empenho_id' => $row['empenho_id'],
                'numero' => $row['numero'],
                'cliente_uasg' => $row['cliente_uasg'],
                'cliente_nome' => $row['cliente_nome'],
                'valor_total_empenho' => $row['valor_total_empenho'],
                'classificacao' => $row['classificacao'],
                'pregao' => $row['pregao'],
                'data_empenho' => $row['data_empenho'],
                'upload' => $row['upload'],
                'produtos' => []
            ];
        }
        
        // Adiciona o produto se existir
        if ($row['empenho_produto_id'] > 0) {
            $empenhos_agrupados[$empenho_id]['produtos'][] = [
                'produto_id' => $row['produto_id'],
                'produto_nome' => $row['produto_nome'],
                'produto_codigo' => $row['produto_codigo'],
                'produto_observacao' => $row['produto_observacao'],
                'descricao_produto' => $row['descricao_produto'],
                'quantidade' => $row['quantidade'],
                'valor_unitario' => $row['valor_unitario'],
                'valor_total' => $row['valor_produto']
            ];
        }
    }
    
    // Converte para array indexado
    $empenhos = array_values($empenhos_agrupados);
    $total_empenhos = count($empenhos);
    
    // Calcula estatísticas
    foreach ($empenhos as $empenho) {
        $valor_total_empenhos += floatval($empenho['valor_total_empenho'] ?? 0);
        
        switch ($empenho['classificacao']) {
            case 'Pendente':
                $empenhos_pendentes++;
                break;
            case 'Faturado':
                $empenhos_faturados++;
                break;
            case 'Entregue':
                $empenhos_entregues++;
                break;
            case 'Liquidado':
                $empenhos_liquidados++;
                break;
            case 'Pago':
                $empenhos_pagos++;
                break;
            case 'Cancelado':
                $empenhos_cancelados++;
                break;
        }
    }
    
} catch (PDOException $e) {
    $error = "Erro ao buscar empenhos: " . $e->getMessage();
    error_log("Erro ao buscar empenhos: " . $e->getMessage());
}

// Processa exclusão de empenho
if (isset($_POST['action']) && $_POST['action'] == 'delete' && $permissionManager->hasPagePermission('empenhos', 'delete')) {
    $empenho_id = isset($_POST['empenho_id']) ? intval($_POST['empenho_id']) : 0;
    
    if ($empenho_id > 0) {
        try {
            $pdo->beginTransaction();
            
            // Busca dados do empenho para auditoria
            $stmt_empenho = $pdo->prepare("SELECT * FROM empenhos WHERE id = :id");
            $stmt_empenho->bindParam(':id', $empenho_id);
            $stmt_empenho->execute();
            $empenho_data = $stmt_empenho->fetch(PDO::FETCH_ASSOC);
            
            // Deleta produtos do empenho
            $stmt_delete_produtos = $pdo->prepare("DELETE FROM empenho_produtos WHERE empenho_id = :empenho_id");
            $stmt_delete_produtos->bindParam(':empenho_id', $empenho_id);
            $stmt_delete_produtos->execute();
            
            // Deleta o empenho
            $stmt_delete_empenho = $pdo->prepare("DELETE FROM empenhos WHERE id = :id");
            $stmt_delete_empenho->bindParam(':id', $empenho_id);
            $stmt_delete_empenho->execute();
            
            // Registra auditoria
            logUserAction('DELETE', 'empenhos', $empenho_id, $empenho_data);
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Empenho excluído com sucesso!']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Erro ao excluir empenho: ' . $e->getMessage()]);
        }
        exit();
    }
}

// Processa atualização de empenho (básica)
if (isset($_POST['action']) && $_POST['action'] == 'update' && $permissionManager->hasPagePermission('empenhos', 'edit')) {
    $empenho_id = isset($_POST['empenho_id']) ? intval($_POST['empenho_id']) : 0;
    
    if ($empenho_id > 0) {
        try {
            // Dados a serem atualizados
            $classificacao = $_POST['classificacao'] ?? 'Pendente';
            $pregao = $_POST['pregao'] ?? '';
            
            // Busca dados antigos para auditoria
            $stmt_old = $pdo->prepare("SELECT * FROM empenhos WHERE id = :id");
            $stmt_old->bindParam(':id', $empenho_id);
            $stmt_old->execute();
            $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);
            
            // Atualiza o empenho
            $stmt_update = $pdo->prepare("UPDATE empenhos SET classificacao = :classificacao, pregao = :pregao WHERE id = :id");
            $stmt_update->bindParam(':classificacao', $classificacao);
            $stmt_update->bindParam(':pregao', $pregao);
            $stmt_update->bindParam(':id', $empenho_id);
            $stmt_update->execute();
            
            // Busca dados novos para auditoria
            $stmt_new = $pdo->prepare("SELECT * FROM empenhos WHERE id = :id");
            $stmt_new->bindParam(':id', $empenho_id);
            $stmt_new->execute();
            $new_data = $stmt_new->fetch(PDO::FETCH_ASSOC);
            
            // Registra auditoria
            logUserAction('UPDATE', 'empenhos', $empenho_id, [
                'old_data' => $old_data,
                'new_data' => $new_data
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Empenho atualizado com sucesso!']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao atualizar empenho: ' . $e->getMessage()]);
        }
        exit();
    }
}

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Empenhos do Cliente - LicitaSis", "empenhos");
?>

<style>
    /* Reset e variáveis CSS - mesmo padrão do sistema */
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
        
        /* Cores específicas para cada status */
        --pendente-color: #fd7e14;
        --faturado-color: #007bff;
        --entregue-color: #20c997;
        --liquidado-color: #6f42c1;
        --pago-color: #28a745;
        --cancelado-color: #dc3545;
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
        color: var(--dark-gray);
    }

    /* Container principal */
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

    /* Cabeçalho da página */
    .page-header {
        text-align: center;
        margin-bottom: 2.5rem;
        padding-bottom: 1.5rem;
        border-bottom: 2px solid var(--border-color);
        position: relative;
    }

    .page-title {
        color: var(--primary-color);
        font-size: 2.2rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1rem;
    }

    .page-title i {
        color: var(--secondary-color);
        font-size: 2rem;
    }

    .page-subtitle {
        color: var(--medium-gray);
        font-size: 1.1rem;
        margin: 0;
    }

    /* Estatísticas dos empenhos */
    .stats-section {
        margin-bottom: 2.5rem;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
    }

    .stat-card {
        background: linear-gradient(135deg, white 0%, #f8f9fa 100%);
        padding: 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        text-align: center;
        transition: var(--transition);
        border-left: 4px solid var(--secondary-color);
        position: relative;
        overflow: hidden;
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
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    }

    .stat-icon {
        font-size: 2.5rem;
        color: var(--secondary-color);
        margin-bottom: 1rem;
        display: block;
    }

    .stat-number {
        font-size: 2.2rem;
        font-weight: 800;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        line-height: 1;
        font-family: 'Courier New', monospace;
    }

    .stat-label {
        color: var(--medium-gray);
        font-size: 0.9rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Filtros */
    .filters-container {
        background: var(--light-gray);
        padding: 1.5rem;
        border-radius: var(--radius);
        margin-bottom: 2rem;
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: end;
    }

    .filter-group {
        flex: 1;
        min-width: 200px;
    }

    .filter-group label {
        display: block;
        font-weight: 600;
        color: var(--dark-gray);
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    .filter-group select,
    .filter-group input {
        width: 100%;
        padding: 0.75rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-sm);
        font-size: 0.95rem;
        transition: var(--transition);
    }

    .filter-group select:focus,
    .filter-group input:focus {
        outline: none;
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
    }

    /* Cards de empenhos */
    .empenhos-container {
        display: grid;
        gap: 1.5rem;
    }

    .empenho-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
        transition: var(--transition);
        animation: slideInUp 0.4s ease;
    }

    .empenho-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-hover);
        border-color: var(--secondary-color);
    }

    @keyframes slideInUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .empenho-header {
        background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
        color: white;
        padding: 1.25rem 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .empenho-numero {
        font-size: 1.2rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .empenho-numero i {
        font-size: 1rem;
    }

    .empenho-data {
        font-size: 0.9rem;
        opacity: 0.9;
    }

    .empenho-body {
        padding: 1.5rem;
    }

    .empenho-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .empenho-info-item {
        background: var(--light-gray);
        padding: 1rem;
        border-radius: var(--radius-sm);
        border-left: 3px solid var(--secondary-color);
    }

    .empenho-info-label {
        color: var(--medium-gray);
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.25rem;
    }

    .empenho-info-value {
        color: var(--dark-gray);
        font-size: 1rem;
        font-weight: 500;
    }

    /* Select de classificação - cores por status */
    .classificacao-select {
        padding: 0.75rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-sm);
        background: white;
        min-width: 140px;
        font-size: 0.9rem;
        font-weight: 500;
        transition: var(--transition);
        cursor: pointer;
    }

    .classificacao-select:focus {
        outline: none;
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        transform: translateY(-1px);
    }

    .classificacao-select:hover {
        border-color: var(--secondary-color);
    }

    /* Estados de classificação - cores atualizadas */
    .classificacao-select[value="Pendente"] { 
        background: rgba(253, 126, 20, 0.1); 
        color: var(--pendente-color); 
        border-color: var(--pendente-color);
    }
    .classificacao-select[value="Faturado"] { 
        background: rgba(0, 123, 255, 0.1); 
        color: var(--faturado-color);
        border-color: var(--faturado-color);
    }
    .classificacao-select[value="Entregue"] { 
        background: rgba(32, 201, 151, 0.1); 
        color: var(--entregue-color);
        border-color: var(--entregue-color);
    }
    .classificacao-select[value="Liquidado"] { 
        background: rgba(111, 66, 193, 0.1); 
        color: var(--liquidado-color);
        border-color: var(--liquidado-color);
    }
    .classificacao-select[value="Pago"] { 
        background: rgba(40, 167, 69, 0.1); 
        color: var(--pago-color);
        border-color: var(--pago-color);
    }
    .classificacao-select[value="Cancelado"] { 
        background: rgba(220, 53, 69, 0.1); 
        color: var(--cancelado-color);
        border-color: var(--cancelado-color);
    }

    /* Status badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.875rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-badge.pendente {
        background: rgba(253, 126, 20, 0.1);
        color: var(--pendente-color);
        border: 1px solid var(--pendente-color);
    }

    .status-badge.faturado {
        background: rgba(0, 123, 255, 0.1);
        color: var(--faturado-color);
        border: 1px solid var(--faturado-color);
    }

    .status-badge.entregue {
        background: rgba(32, 201, 151, 0.1);
        color: var(--entregue-color);
        border: 1px solid var(--entregue-color);
    }

    .status-badge.liquidado {
        background: rgba(111, 66, 193, 0.1);
        color: var(--liquidado-color);
        border: 1px solid var(--liquidado-color);
    }

    .status-badge.pago {
        background: rgba(40, 167, 69, 0.1);
        color: var(--pago-color);
        border: 1px solid var(--pago-color);
    }

    .status-badge.cancelado {
        background: rgba(220, 53, 69, 0.1);
        color: var(--cancelado-color);
        border: 1px solid var(--cancelado-color);
    }

    /* Produtos do empenho */
    .produtos-section {
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--border-color);
    }

    .produtos-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .produtos-title i {
        color: var(--secondary-color);
    }

    .produtos-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
    }

    .produtos-table th {
        background: var(--light-gray);
        color: var(--dark-gray);
        padding: 0.75rem;
        text-align: left;
        font-weight: 600;
        border-bottom: 2px solid var(--border-color);
    }

    .produtos-table td {
        padding: 0.75rem;
        border-bottom: 1px solid var(--border-color);
    }

    .produtos-table tbody tr:hover {
        background: var(--light-gray);
    }

    .produtos-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Total do empenho */
    .empenho-total {
        background: var(--primary-color);
        color: white;
        padding: 1rem 1.5rem;
        font-size: 1.2rem;
        font-weight: 700;
        text-align: right;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .empenho-total-label {
        font-weight: 600;
        opacity: 0.9;
    }

    .empenho-total-value {
        font-size: 1.4rem;
    }

    /* Ações do empenho */
    .empenho-actions {
        padding: 1rem 1.5rem;
        background: var(--light-gray);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .empenho-actions-left {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .empenho-actions-right {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .empenho-classificacao-container {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .empenho-classificacao-label {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--medium-gray);
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
        background-color: rgba(0,0,0,0.5);
        animation: fadeIn 0.3s ease;
    }

    .modal-content {
        background-color: white;
        margin: 3% auto;
        padding: 0;
        border-radius: var(--radius);
        width: 95%;
        max-width: 900px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: var(--shadow-hover);
        animation: slideIn 0.3s ease;
    }

    @keyframes slideIn {
        from { transform: translateY(-50px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .modal-header {
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
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
    }

    .close {
        color: white;
        font-size: 2rem;
        font-weight: bold;
        cursor: pointer;
        line-height: 1;
        transition: var(--transition);
    }

    .close:hover {
        transform: scale(1.2);
        color: #ffcccc;
    }

    .modal-body {
        padding: 2rem;
    }

    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 3rem 2rem;
        color: var(--medium-gray);
    }

    .empty-state i {
        font-size: 4rem;
        color: var(--border-color);
        margin-bottom: 1rem;
    }

    .empty-state h3 {
        color: var(--medium-gray);
        margin-bottom: 0.5rem;
    }

    .empty-state p {
        margin-bottom: 1.5rem;
    }

    /* Botões */
    .btn {
        padding: 0.875rem 1.5rem;
        border: none;
        border-radius: var(--radius-sm);
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
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

    .btn-success {
        background: linear-gradient(135deg, var(--success-color) 0%, #1e7e34 100%);
        color: white;
        box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
    }

    .btn-success:hover {
        background: linear-gradient(135deg, #1e7e34 0%, var(--success-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
    }

    .btn-info {
        background: linear-gradient(135deg, var(--info-color) 0%, #117a8b 100%);
        color: white;
        box-shadow: 0 2px 4px rgba(23, 162, 184, 0.2);
    }

    .btn-info:hover {
        background: linear-gradient(135deg, #117a8b 0%, var(--info-color) 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(23, 162, 184, 0.3);
    }

    .btn-warning {
        background: linear-gradient(135deg, var(--warning-color) 0%, #e0a800 100%);
        color: #212529;
        box-shadow: 0 2px 4px rgba(255, 193, 7, 0.2);
    }

    .btn-warning:hover {
        background: linear-gradient(135deg, #e0a800 0%, var(--warning-color) 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3);
    }

    .btn-danger {
        background: linear-gradient(135deg, var(--danger-color) 0%, #c82333 100%);
        color: white;
        box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
    }

    .btn-danger:hover {
        background: linear-gradient(135deg, #c82333 0%, var(--danger-color) 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
    }

    .btn-secondary {
        background: linear-gradient(135deg, var(--medium-gray) 0%, #5a6268 100%);
        color: white;
        box-shadow: 0 2px 4px rgba(108, 117, 125, 0.2);
    }

    .btn-secondary:hover {
        background: linear-gradient(135deg, #5a6268 0%, var(--medium-gray) 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
    }

    .btn-sm {
        padding: 0.5rem 0.75rem;
        font-size: 0.85rem;
        border-radius: 6px;
    }

    /* Ações da página */
    .page-actions {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 2.5rem;
        padding-top: 2rem;
        border-top: 1px solid var(--border-color);
    }

    /* Loading spinner */
    .loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(255,255,255,.3);
        border-radius: 50%;
        border-top-color: #fff;
        animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    /* Mensagem de erro/sucesso */
    .alert {
        padding: 1rem 1.5rem;
        border-radius: var(--radius-sm);
        margin-bottom: 1.5rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .alert-error {
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }

    .alert-success {
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }

    .alert i {
        font-size: 1.2rem;
    }

    /* Arquivo anexo */
    .arquivo-anexo {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: var(--light-gray);
        border-radius: var(--radius-sm);
        font-size: 0.9rem;
        color: var(--secondary-color);
        text-decoration: none;
        transition: var(--transition);
    }

    .arquivo-anexo:hover {
        background: var(--secondary-color);
        color: white;
        transform: translateY(-1px);
    }

    /* Form groups */
    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        display: block;
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        font-size: 0.95rem;
    }

    .form-control {
        width: 100%;
        padding: 0.875rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-sm);
        font-size: 1rem;
        transition: var(--transition);
    }

    .form-control:focus {
        outline: none;
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
    }

    /* Responsividade */
    @media (max-width: 1200px) {
        .container {
            margin: 2rem 1.5rem;
            padding: 2rem;
        }
    }

    @media (max-width: 768px) {
        .container {
            padding: 1.5rem;
            margin: 1.5rem 1rem;
        }

        .page-title {
            font-size: 1.8rem;
            flex-direction: column;
            gap: 0.5rem;
        }

        .stats-grid {
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .filters-container {
            flex-direction: column;
        }

        .filter-group {
            width: 100%;
        }

        .stat-card {
            padding: 1.5rem;
        }

        .empenho-header {
            flex-direction: column;
            text-align: center;
        }

        .empenho-info-grid {
            grid-template-columns: 1fr;
        }

        .empenho-total {
            flex-direction: column;
            text-align: center;
            gap: 0.5rem;
        }

        .empenho-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .empenho-actions-left,
        .empenho-actions-right {
            justify-content: center;
        }

        .produtos-table {
            font-size: 0.8rem;
        }

        .produtos-table th,
        .produtos-table td {
            padding: 0.5rem;
        }

        .page-actions {
            flex-direction: column;
        }

        .btn {
            padding: 1rem;
        }

        .modal-content {
            width: 98%;
            margin: 1% auto;
        }
    }

    @media (max-width: 480px) {
        .container {
            padding: 1.25rem;
            margin: 1rem 0.5rem;
        }

        .page-title {
            font-size: 1.5rem;
        }

        .stat-card {
            padding: 1.25rem;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .empenho-card {
            font-size: 0.9rem;
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

        /* Responsividade da tabela de produtos */
        .produtos-table {
            display: block;
            overflow-x: auto;
        }

        .produtos-table thead {
            display: none;
        }

        .produtos-table tbody tr {
            display: block;
            margin-bottom: 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.5rem;
        }

        .produtos-table tbody td {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem;
            border: none;
        }

        .produtos-table tbody td::before {
            content: attr(data-label);
            font-weight: 600;
            color: var(--medium-gray);
        }
    }

    /* Print styles */
    @media print {
        .filters-container,
        .page-actions,
        .btn,
        .modal,
        .empenho-actions {
            display: none !important;
        }
        
        .container {
            margin: 0;
            box-shadow: none;
        }
        
        .empenho-card {
            break-inside: avoid;
            page-break-inside: avoid;
        }
    }
</style>

<div class="container">
    <!-- Cabeçalho da página -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-file-invoice-dollar"></i>
            <?php echo safe_htmlspecialchars($cliente['nome_orgaos'] ?? 'Cliente não encontrado'); ?>
        </h1>
        <p class="page-subtitle">Empenhos registrados para este cliente</p>
    </div>

    <!-- Mensagens de erro/sucesso -->
    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if (!$error && $cliente): ?>
    <!-- Estatísticas dos empenhos -->
    <div class="stats-section">
        <div class="stats-grid">
            <div class="stat-card">
                <i class="stat-icon fas fa-file-invoice-dollar"></i>
                <div class="stat-number" id="totalEmpenhos"><?php echo $total_empenhos; ?></div>
                <div class="stat-label">Total de Empenhos</div>
            </div>
            
            <div class="stat-card">
                <i class="stat-icon fas fa-money-bill-wave"></i>
                <div class="stat-number">R$ <?php echo number_format($valor_total_empenhos, 2, ',', '.'); ?></div>
                <div class="stat-label">Valor Total</div>
            </div>
            
            <div class="stat-card">
                <i class="stat-icon fas fa-clock"></i>
                <div class="stat-number"><?php echo $empenhos_pendentes; ?></div>
                <div class="stat-label">Pendentes</div>
            </div>
            
            <div class="stat-card">
                <i class="stat-icon fas fa-file-invoice"></i>
                <div class="stat-number"><?php echo $empenhos_faturados; ?></div>
                <div class="stat-label">Faturados</div>
            </div>
            
            <div class="stat-card">
                <i class="stat-icon fas fa-truck"></i>
                <div class="stat-number"><?php echo $empenhos_entregues; ?></div>
                <div class="stat-label">Entregues</div>
            </div>
            
            <div class="stat-card">
                <i class="stat-icon fas fa-check-circle"></i>
                <div class="stat-number"><?php echo $empenhos_liquidados; ?></div>
                <div class="stat-label">Liquidados</div>
            </div>
            
            <div class="stat-card">
                <i class="stat-icon fas fa-money-check-alt"></i>
                <div class="stat-number"><?php echo $empenhos_pagos; ?></div>
                <div class="stat-label">Pagos</div>
            </div>
            
            <div class="stat-card">
                <i class="stat-icon fas fa-times-circle"></i>
                <div class="stat-number"><?php echo $empenhos_cancelados; ?></div>
                <div class="stat-label">Cancelados</div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="filters-container">
        <div class="filter-group">
            <label for="filterStatus">Filtrar por Classificação:</label>
            <select id="filterStatus" onchange="filterEmpenhos()">
                <option value="">Todos</option>
                <option value="Pendente">Pendente</option>
                <option value="Faturado">Faturado</option>
                <option value="Entregue">Entregue</option>
                <option value="Liquidado">Liquidado</option>
                <option value="Pago">Pago</option>
                <option value="Cancelado">Cancelado</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="filterDate">Filtrar por Data:</label>
            <input type="month" id="filterDate" onchange="filterEmpenhos()">
        </div>
        <div class="filter-group">
            <label for="searchInput">Buscar:</label>
            <input type="text" id="searchInput" placeholder="Buscar em todos os campos..." onkeyup="filterEmpenhos()">
        </div>
    </div>

    <!-- Lista de empenhos -->
    <div class="empenhos-section">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-list-alt"></i>
                Histórico de Empenhos
            </h2>
        </div>

        <?php if (count($empenhos) > 0): ?>
            <div class="empenhos-container" id="empenhosContainer">
                <?php foreach ($empenhos as $empenho): ?>
                    <div class="empenho-card" data-empenho='<?php echo json_encode($empenho); ?>'>
                        <div class="empenho-header">
                            <div class="empenho-numero">
                                <i class="fas fa-hashtag"></i>
                                Empenho: <?php echo safe_htmlspecialchars($empenho['numero']); ?>
                            </div>
                            <div class="empenho-data">
                                <i class="fas fa-calendar"></i>
                                <?php echo date('d/m/Y', strtotime($empenho['data_empenho'])); ?>
                            </div>
                        </div>
                        
                        <div class="empenho-body">
                            <div class="empenho-info-grid">
                                <div class="empenho-info-item">
                                    <div class="empenho-info-label">Pregão</div>
                                    <div class="empenho-info-value">
                                        <?php echo safe_htmlspecialchars($empenho['pregao'] ?: 'Não informado'); ?>
                                    </div>
                                </div>
                                <div class="empenho-info-item">
                                    <div class="empenho-info-label">Status Atual</div>
                                    <div class="empenho-info-value">
                                        <?php 
                                        $classificacao = strtolower($empenho['classificacao']);
                                        ?>
                                        <span class="status-badge <?php echo $classificacao; ?>">
                                            <?php
                                            $icons = [
                                                'pendente' => 'fa-clock',
                                                'faturado' => 'fa-file-invoice',
                                                'entregue' => 'fa-truck',
                                                'liquidado' => 'fa-calculator',
                                                'pago' => 'fa-check-circle',
                                                'cancelado' => 'fa-times-circle'
                                            ];
                                            $icon = $icons[$classificacao] ?? 'fa-question';
                                            ?>
                                            <i class="fas <?php echo $icon; ?>"></i>
                                            <?php echo safe_htmlspecialchars($empenho['classificacao']); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php if ($empenho['upload']): ?>
                                <div class="empenho-info-item">
                                    <div class="empenho-info-label">Arquivo Anexo</div>
                                    <div class="empenho-info-value">
                                        <a href="uploads/<?php echo safe_htmlspecialchars($empenho['upload']); ?>" 
                                           target="_blank" 
                                           class="arquivo-anexo">
                                            <i class="fas fa-file-pdf"></i>
                                            Ver Arquivo
                                        </a>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($empenho['produtos'])): ?>
                            <div class="produtos-section">
                                <h4 class="produtos-title">
                                    <i class="fas fa-box"></i>
                                    Produtos do Empenho
                                </h4>
                                <table class="produtos-table">
                                    <thead>
                                        <tr>
                                            <th>Produto</th>
                                            <th>Código</th>
                                            <th>Quantidade</th>
                                            <th>Valor Unitário</th>
                                            <th>Valor Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($empenho['produtos'] as $produto): ?>
                                        <tr>
                                            <td data-label="Produto">
                                                <?php echo safe_htmlspecialchars($produto['produto_nome']); ?>
                                                <?php if ($produto['produto_observacao']): ?>
                                                    <br><small class="text-muted">
                                                        <?php echo safe_htmlspecialchars($produto['produto_observacao']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Código">
                                                <?php echo safe_htmlspecialchars($produto['produto_codigo'] ?: '-'); ?>
                                            </td>
                                            <td data-label="Quantidade">
                                                <?php echo number_format($produto['quantidade'], 0, ',', '.'); ?>
                                            </td>
                                            <td data-label="Valor Unitário">
                                                R$ <?php echo number_format($produto['valor_unitario'], 2, ',', '.'); ?>
                                            </td>
                                            <td data-label="Valor Total">
                                                R$ <?php echo number_format($produto['valor_total'], 2, ',', '.'); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="empenho-total">
                            <span class="empenho-total-label">Valor Total do Empenho:</span>
                            <span class="empenho-total-value">
                                R$ <?php echo number_format($empenho['valor_total_empenho'], 2, ',', '.'); ?>
                            </span>
                        </div>
                        
                        <div class="empenho-actions">
                            <div class="empenho-actions-left">
                                <?php if ($permissionManager->hasPagePermission('empenhos', 'edit')): ?>
                                <div class="empenho-classificacao-container">
                                    <span class="empenho-classificacao-label">Alterar Status:</span>
                                    <select class="classificacao-select" 
                                            data-empenho-id="<?php echo $empenho['empenho_id']; ?>" 
                                            onchange="updateClassificacao(this)"
                                            value="<?php echo $empenho['classificacao'] ?? ''; ?>"
                                            title="Altere a classificação">
                                        <option value="">Selecionar</option>
                                        <option value="Pendente" <?php echo $empenho['classificacao'] === 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
                                        <option value="Faturado" <?php echo $empenho['classificacao'] === 'Faturado' ? 'selected' : ''; ?>>Faturado</option>
                                        <option value="Entregue" <?php echo $empenho['classificacao'] === 'Entregue' ? 'selected' : ''; ?>>Entregue</option>
                                        <option value="Liquidado" <?php echo $empenho['classificacao'] === 'Liquidado' ? 'selected' : ''; ?>>Liquidado</option>
                                        <option value="Pago" <?php echo $empenho['classificacao'] === 'Pago' ? 'selected' : ''; ?>>Pago</option>
                                        <option value="Cancelado" <?php echo $empenho['classificacao'] === 'Cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="empenho-actions-right">
                                <button onclick="openEmpenhoModal(<?php echo $empenho['empenho_id']; ?>)" 
                                        class="btn btn-info btn-sm" title="Ver Detalhes">
                                    <i class="fas fa-eye"></i> Detalhes
                                </button>
                                <?php if ($permissionManager->hasPagePermission('empenhos', 'edit')): ?>
                                <button onclick="openEditModal(<?php echo $empenho['empenho_id']; ?>)" 
                                        class="btn btn-warning btn-sm" title="Editar">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <?php endif; ?>
                                <?php if ($permissionManager->hasPagePermission('empenhos', 'delete')): ?>
                                <button onclick="confirmDeleteEmpenho(<?php echo $empenho['empenho_id']; ?>)" 
                                        class="btn btn-danger btn-sm" title="Excluir">
                                    <i class="fas fa-trash"></i> Excluir
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empenho-card">
                <div class="empty-state">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <h3>Nenhum empenho encontrado</h3>
                    <p>Este cliente ainda não possui empenhos cadastrados no sistema.</p>
                    <?php if ($permissionManager->hasPagePermission('empenhos', 'create')): ?>
                        <a href="cadastro_empenho.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Cadastrar Primeiro Empenho
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Ações da página -->
    <div class="page-actions">
        <a href="consultar_clientes.php" class="btn btn-primary">
            <i class="fas fa-arrow-left"></i> Voltar para Clientes
        </a>
        
        <?php if (!$error && $cliente && $permissionManager->hasPagePermission('empenhos', 'create')): ?>
            <a href="cadastro_empenho.php" class="btn btn-success">
                <i class="fas fa-plus"></i> Novo Empenho
            </a>
        <?php endif; ?>
        
        <?php if (count($empenhos) > 0): ?>
            <button onclick="window.print()" class="btn btn-info">
                <i class="fas fa-print"></i> Imprimir
            </button>
            
            <button onclick="exportarExcel()" class="btn btn-secondary">
                <i class="fas fa-file-excel"></i> Exportar Excel
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de detalhes/edição do empenho -->
<div id="empenhoModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-file-invoice-dollar"></i> Detalhes do Empenho</h3>
            <span class="close" onclick="closeEmpenhoModal()">&times;</span>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- Conteúdo será carregado via JavaScript -->
        </div>
    </div>
</div>

<script>
// JavaScript específico da página de empenhos do cliente
document.addEventListener('DOMContentLoaded', function() {
    // Anima os números das estatísticas
    function animateNumber(element, finalNumber) {
        if (finalNumber === 0) return;
        
        let currentNumber = 0;
        const increment = Math.max(1, Math.ceil(finalNumber / 30));
        const duration = 1000;
        const stepTime = duration / (finalNumber / increment);
        
        element.textContent = '0';
        
        const timer = setInterval(() => {
            currentNumber += increment;
            if (currentNumber >= finalNumber) {
                currentNumber = finalNumber;
                clearInterval(timer);
            }
            element.textContent = currentNumber.toLocaleString('pt-BR');
        }, stepTime);
    }

    // Observer para animar quando os cards ficarem visíveis
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const numberElement = entry.target.querySelector('.stat-number');
                if (numberElement && !numberElement.dataset.animated) {
                    numberElement.dataset.animated = 'true';
                    const text = numberElement.textContent.trim();
                    
                    // Só anima se for um número (não é valor monetário)
                    if (/^\d+$/.test(text)) {
                        const finalNumber = parseInt(text);
                        if (!isNaN(finalNumber)) {
                            setTimeout(() => animateNumber(numberElement, finalNumber), 200);
                        }
                    }
                }
            }
        });
    }, { threshold: 0.5 });

    // Observa todos os cards de estatísticas
    document.querySelectorAll('.stat-card').forEach(card => {
        observer.observe(card);
    });

    // Animação dos cards de empenho
    const empenhoCards = document.querySelectorAll('.empenho-card');
    empenhoCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        setTimeout(() => {
            card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Aplica estados visuais aos selects de classificação
    document.querySelectorAll('.classificacao-select').forEach(select => {
        updateSelectStyles(select);
        select.addEventListener('change', function() {
            this.setAttribute('value', this.value);
            updateSelectStyles(this);
        });
    });
});

// Função para atualizar estilos visuais dos selects
function updateSelectStyles(selectElement) {
    const value = selectElement.value;
    selectElement.setAttribute('value', value);
}

// Dados dos empenhos para uso no JavaScript
const empenhosData = <?php echo json_encode($empenhos); ?>;

// Função para filtrar empenhos
function filterEmpenhos() {
    const statusFilter = document.getElementById('filterStatus').value;
    const dateFilter = document.getElementById('filterDate').value;
    const searchInput = document.getElementById('searchInput').value.toLowerCase();
    const cards = document.querySelectorAll('.empenho-card');

    let visibleCount = 0;
    cards.forEach(card => {
        const empenhoData = JSON.parse(card.dataset.empenho || '{}');
        let showCard = true;

        // Filtro de status
        if (statusFilter && empenhoData.classificacao !== statusFilter) {
            showCard = false;
        }

        // Filtro de data
        if (dateFilter && showCard) {
            const empenhoDate = new Date(empenhoData.data_empenho);
            const filterMonth = new Date(dateFilter + '-01');
            if (empenhoDate.getFullYear() !== filterMonth.getFullYear() || 
                empenhoDate.getMonth() !== filterMonth.getMonth()) {
                showCard = false;
            }
        }

        // Busca geral
        if (searchInput && showCard) {
            const searchableText = JSON.stringify(empenhoData).toLowerCase();
            if (!searchableText.includes(searchInput)) {
                showCard = false;
            }
        }

        card.style.display = showCard ? 'block' : 'none';
        if (showCard) visibleCount++;
    });

    console.log(`Mostrando ${visibleCount} de ${cards.length} empenhos`);
}

// Função para atualizar classificação via AJAX (seguindo padrão da consulta_empenho.php)
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

    fetch('cliente_empenho.php?cliente_uasg=<?php echo urlencode($cliente_uasg); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            selectElement.defaultValue = classificacao;
            selectElement.setAttribute('value', classificacao);
            updateSelectStyles(selectElement);
            showToast('Classificação atualizada com sucesso!', 'success');
            
            // Adiciona efeito visual de sucesso
            selectElement.style.transform = 'scale(1.05)';
            setTimeout(() => {
                selectElement.style.transform = '';
            }, 200);
            
            // Atualiza o badge de status no card
            updateStatusBadge(empenhoId, classificacao);
        } else {
            showToast('Erro ao atualizar classificação: ' + (data.error || 'Erro desconhecido'), 'error');
            selectElement.value = originalValue;
        }
    })
    .catch(error => {
        console.error('Erro ao atualizar classificação:', error);
        showToast('Erro ao atualizar classificação.', 'error');
        selectElement.value = originalValue;
    })
    .finally(() => {
        selectElement.classList.remove('loading');
    });
}

// Função para atualizar o badge de status no card
function updateStatusBadge(empenhoId, novaClassificacao) {
    const card = document.querySelector(`[data-empenho*='"empenho_id":${empenhoId}']`);
    if (card) {
        const statusBadge = card.querySelector('.status-badge');
        if (statusBadge) {
            // Remove classes antigas
            statusBadge.className = 'status-badge';
            
            // Adiciona nova classe
            statusBadge.classList.add(novaClassificacao.toLowerCase());
            
            // Atualiza ícone e texto
            const icons = {
                'Pendente': 'fa-clock',
                'Faturado': 'fa-file-invoice',
                'Entregue': 'fa-truck',
                'Liquidado': 'fa-calculator',
                'Pago': 'fa-check-circle',
                'Cancelado': 'fa-times-circle'
            };
            
            const icon = icons[novaClassificacao] || 'fa-question';
            statusBadge.innerHTML = `<i class="fas ${icon}"></i> ${novaClassificacao}`;
        }
    }
}

// Variáveis globais para controle do modal
let currentEmpenhoId = null;

// Função para abrir modal com detalhes do empenho
function openEmpenhoModal(empenhoId) {
    const empenho = empenhosData.find(e => e.empenho_id == empenhoId);
    
    if (!empenho) {
        showToast('Empenho não encontrado!', 'error');
        return;
    }
    
    currentEmpenhoId = empenhoId;
    const modalBody = document.getElementById('modalBody');
    
    // Monta o HTML dos produtos
    let produtosHtml = '';
    if (empenho.produtos && empenho.produtos.length > 0) {
        produtosHtml = `
            <h4 style="margin-top: 1.5rem; margin-bottom: 1rem; color: var(--primary-color);">
                <i class="fas fa-box"></i> Produtos do Empenho
            </h4>
            <table class="produtos-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th>Código</th>
                        <th>Quantidade</th>
                        <th>Valor Unitário</th>
                        <th>Valor Total</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        empenho.produtos.forEach(produto => {
            produtosHtml += `
                <tr>
                    <td>
                        ${produto.produto_nome}
                        ${produto.produto_observacao ? `<br><small style="color: var(--medium-gray);">${produto.produto_observacao}</small>` : ''}
                    </td>
                    <td>${produto.produto_codigo || '-'}</td>
                    <td>${parseInt(produto.quantidade).toLocaleString('pt-BR')}</td>
                    <td>R$ ${parseFloat(produto.valor_unitario).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                    <td>R$ ${parseFloat(produto.valor_total).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                </tr>
            `;
        });
        
        produtosHtml += '</tbody></table>';
    }
    
    modalBody.innerHTML = `
        <div class="modal-detail-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div class="modal-detail-item" style="background: var(--light-gray); padding: 1rem; border-radius: var(--radius-sm); border-left: 3px solid var(--secondary-color);">
                <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Número do Empenho</div>
                <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.1rem; font-weight: 500;">${empenho.numero}</div>
            </div>
            <div class="modal-detail-item" style="background: var(--light-gray); padding: 1rem; border-radius: var(--radius-sm); border-left: 3px solid var(--secondary-color);">
                <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Cliente</div>
                <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.1rem; font-weight: 500;">${empenho.cliente_nome}</div>
            </div>
            <div class="modal-detail-item" style="background: var(--light-gray); padding: 1rem; border-radius: var(--radius-sm); border-left: 3px solid var(--secondary-color);">
                <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">UASG</div>
                <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.1rem; font-weight: 500;">${empenho.cliente_uasg}</div>
            </div>
            <div class="modal-detail-item" style="background: var(--light-gray); padding: 1rem; border-radius: var(--radius-sm); border-left: 3px solid var(--secondary-color);">
                <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Pregão</div>
                <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.1rem; font-weight: 500;">${empenho.pregao || 'Não informado'}</div>
            </div>
            <div class="modal-detail-item" style="background: var(--light-gray); padding: 1rem; border-radius: var(--radius-sm); border-left: 3px solid var(--secondary-color);">
                <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Classificação</div>
                <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.1rem; font-weight: 500;">
                    <span class="status-badge ${empenho.classificacao.toLowerCase()}">
                        <i class="fas ${getStatusIcon(empenho.classificacao)}"></i> 
                        ${empenho.classificacao}
                    </span>
                </div>
            </div>
            <div class="modal-detail-item" style="background: var(--light-gray); padding: 1rem; border-radius: var(--radius-sm); border-left: 3px solid var(--secondary-color);">
                <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Data do Empenho</div>
                <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.1rem; font-weight: 500;">${new Date(empenho.data_empenho).toLocaleDateString('pt-BR')}</div>
            </div>
            <div class="modal-detail-item" style="background: var(--light-gray); padding: 1rem; border-radius: var(--radius-sm); border-left: 3px solid var(--secondary-color);">
                <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Valor Total</div>
                <div class="modal-detail-value" style="color: var(--success-color); font-size: 1.3rem; font-weight: 700;">
                    R$ ${parseFloat(empenho.valor_total_empenho).toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                </div>
            </div>
            ${empenho.upload ? `
            <div class="modal-detail-item" style="background: var(--light-gray); padding: 1rem; border-radius: var(--radius-sm); border-left: 3px solid var(--secondary-color);">
                <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Arquivo Anexo</div>
                <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.1rem; font-weight: 500;">
                    <a href="uploads/${empenho.upload}" target="_blank" class="arquivo-anexo">
                        <i class="fas fa-file-pdf"></i> Ver Arquivo
                    </a>
                </div>
            </div>
            ` : ''}
        </div>
        
        ${produtosHtml}
        
        <div class="modal-actions" style="display: flex; justify-content: center; gap: 1rem; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
            <button onclick="closeEmpenhoModal()" class="btn btn-secondary">
                <i class="fas fa-times"></i> Fechar
            </button>
            <?php if ($permissionManager->hasPagePermission('empenhos', 'edit')): ?>
            <button onclick="openEditModal(${empenho.empenho_id})" class="btn btn-warning">
                <i class="fas fa-edit"></i> Editar
            </button>
            <?php endif; ?>
            <button onclick="imprimirEmpenho()" class="btn btn-info">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    `;
    
    document.getElementById('empenhoModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Função para abrir modal de edição
function openEditModal(empenhoId) {
    const empenho = empenhosData.find(e => e.empenho_id == empenhoId);
    
    if (!empenho) {
        showToast('Empenho não encontrado!', 'error');
        return;
    }
    
    currentEmpenhoId = empenhoId;
    const modalBody = document.getElementById('modalBody');
    
    modalBody.innerHTML = `
        <form id="editEmpenhoForm" onsubmit="return saveEmpenho(event)">
            <div class="form-group">
                <label for="edit_numero">Número do Empenho</label>
                <input type="text" id="edit_numero" value="${empenho.numero}" class="form-control" readonly style="background: var(--light-gray); cursor: not-allowed;">
            </div>
            <div class="form-group">
                <label for="edit_pregao">Pregão</label>
                <input type="text" id="edit_pregao" name="pregao" value="${empenho.pregao || ''}" class="form-control">
            </div>
            <div class="form-group">
                <label for="edit_classificacao">Classificação</label>
                <select id="edit_classificacao" name="classificacao" class="form-control" required>
                    <option value="Pendente" ${empenho.classificacao === 'Pendente' ? 'selected' : ''}>Pendente</option>
                    <option value="Faturado" ${empenho.classificacao === 'Faturado' ? 'selected' : ''}>Faturado</option>
                    <option value="Entregue" ${empenho.classificacao === 'Entregue' ? 'selected' : ''}>Entregue</option>
                    <option value="Liquidado" ${empenho.classificacao === 'Liquidado' ? 'selected' : ''}>Liquidado</option>
                    <option value="Pago" ${empenho.classificacao === 'Pago' ? 'selected' : ''}>Pago</option>
                    <option value="Cancelado" ${empenho.classificacao === 'Cancelado' ? 'selected' : ''}>Cancelado</option>
                </select>
            </div>
            
            <div style="background: var(--light-gray); padding: 1rem; border-radius: var(--radius-sm); margin: 1.5rem 0;">
                <p style="color: var(--medium-gray); margin: 0; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> 
                    Para editar produtos, valores ou outras informações do empenho, use a página de consulta de empenhos.
                </p>
            </div>

            <div class="modal-actions" style="display: flex; justify-content: space-between; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                <div class="modal-actions-left">
                    <button type="button" onclick="closeEmpenhoModal()" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
                <div class="modal-actions-right" style="display: flex; gap: 0.75rem;">
                    <?php if ($permissionManager->hasPagePermission('empenhos', 'delete')): ?>
                    <button type="button" onclick="confirmDeleteEmpenho(${empenho.empenho_id})" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Excluir Empenho
                    </button>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                </div>
            </div>
        </form>
    `;
    
    document.getElementById('empenhoModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Função auxiliar para obter ícone do status
function getStatusIcon(classificacao) {
    const icons = {
        'Pendente': 'fa-clock',
        'Faturado': 'fa-file-invoice',
        'Entregue': 'fa-truck',
        'Liquidado': 'fa-calculator',
        'Pago': 'fa-check-circle',
        'Cancelado': 'fa-times-circle'
    };
    return icons[classificacao] || 'fa-question';
}

// Função para salvar alterações do empenho
function saveEmpenho(event) {
    event.preventDefault();
    
    const pregao = document.getElementById('edit_pregao').value;
    const classificacao = document.getElementById('edit_classificacao').value;
    
    if (!classificacao) {
        showToast('Por favor, selecione uma classificação', 'error');
        return false;
    }
    
    if (!currentEmpenhoId) {
        showToast('Erro: ID do empenho não encontrado', 'error');
        return false;
    }
    
    const form = document.getElementById('editEmpenhoForm');
    const formData = new FormData(form);
    formData.append('action', 'update');
    formData.append('empenho_id', currentEmpenhoId);
    
    // Mostra loading no botão
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="loading"></span> Salvando...';
    submitBtn.disabled = true;
    
    fetch('cliente_empenho.php?cliente_uasg=<?php echo urlencode($cliente_uasg); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Empenho atualizado com sucesso!', 'success');
            closeEmpenhoModal();
            setTimeout(() => location.reload(), 1000); // Recarrega a página para mostrar as alterações
        } else {
            showToast('Erro ao atualizar empenho: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        showToast('Erro ao salvar alterações. Tente novamente.', 'error');
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
    
    return false;
}

// Função para confirmar exclusão de empenho
function confirmDeleteEmpenho(empenhoId) {
    const empenho = empenhosData.find(e => e.empenho_id == empenhoId);
    
    if (!empenho) {
        showToast('Empenho não encontrado!', 'error');
        return;
    }
    
    const confirmacao = confirm(
        `Tem certeza que deseja excluir o empenho ${empenho.numero}?\n\n` +
        `Cliente: ${empenho.cliente_nome}\n` +
        `Valor: R$ ${parseFloat(empenho.valor_total_empenho || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}\n\n` +
        `Esta ação não pode ser desfeita!`
    );
    
    if (confirmacao) {
        deleteEmpenho(empenhoId);
    }
}

// Função para excluir empenho
function deleteEmpenho(empenhoId) {
    if (!empenhoId) {
        showToast('Erro: ID do empenho não encontrado.', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('empenho_id', empenhoId);

    fetch('cliente_empenho.php?cliente_uasg=<?php echo urlencode($cliente_uasg); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Empenho excluído com sucesso!', 'success');
            closeEmpenhoModal();
            setTimeout(() => location.reload(), 1000); // Recarrega a página para remover o empenho da lista
        } else {
            showToast('Erro ao excluir empenho: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        showToast('Erro ao excluir empenho. Tente novamente.', 'error');
    });
}

// Função para fechar modal
function closeEmpenhoModal() {
    document.getElementById('empenhoModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    currentEmpenhoId = null;
}

// Função para imprimir empenho
function imprimirEmpenho() {
    if (!currentEmpenhoId) {
        showToast('Erro: dados do empenho não encontrados.', 'error');
        return;
    }
    
    const empenho = empenhosData.find(e => e.empenho_id == currentEmpenhoId);
    if (!empenho) {
        showToast('Erro: dados do empenho não encontrados.', 'error');
        return;
    }
    
    // Cria uma nova janela para impressão
    const printWindow = window.open('', '_blank');
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Empenho ${empenho.numero}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 2rem; }
                .header { text-align: center; margin-bottom: 2rem; border-bottom: 2px solid #333; padding-bottom: 1rem; }
                .section { margin-bottom: 2rem; }
                .section h3 { background: #f0f0f0; padding: 0.5rem; margin-bottom: 1rem; }
                .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
                .item { margin-bottom: 0.5rem; }
                .label { font-weight: bold; }
                .value { margin-left: 1rem; }
                table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
                th, td { border: 1px solid #ddd; padding: 0.5rem; text-align: left; }
                th { background: #f0f0f0; }
                @media print { body { margin: 0; } }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>EMPENHO Nº ${empenho.numero}</h1>
                <p>Data de Impressão: ${new Date().toLocaleDateString('pt-BR')}</p>
            </div>
            
            <div class="section">
                <h3>Informações Básicas</h3>
                <div class="grid">
                    <div class="item"><span class="label">Número:</span><span class="value">${empenho.numero}</span></div>
                    <div class="item"><span class="label">Status:</span><span class="value">${empenho.classificacao}</span></div>
                    <div class="item"><span class="label">Data:</span><span class="value">${new Date(empenho.data_empenho).toLocaleDateString('pt-BR')}</span></div>
                    <div class="item"><span class="label">Pregão:</span><span class="value">${empenho.pregao || 'N/A'}</span></div>
                </div>
            </div>
            
            <div class="section">
                <h3>Cliente</h3>
                <div class="grid">
                    <div class="item"><span class="label">Nome:</span><span class="value">${empenho.cliente_nome}</span></div>
                    <div class="item"><span class="label">UASG:</span><span class="value">${empenho.cliente_uasg}</span></div>
                </div>
            </div>
            
            <div class="section">
                <h3>Valor</h3>
                <div class="item"><span class="label">Valor Total:</span><span class="value">R$ ${parseFloat(empenho.valor_total_empenho || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</span></div>
            </div>
            
            ${empenho.produtos && empenho.produtos.length > 0 ? `
            <div class="section">
                <h3>Produtos</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <th>Quantidade</th>
                            <th>Valor Unitário</th>
                            <th>Valor Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${empenho.produtos.map(produto => `
                            <tr>
                                <td>${produto.produto_nome || produto.descricao_produto}</td>
                                <td>${produto.quantidade}</td>
                                <td>R$ ${parseFloat(produto.valor_unitario || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                                <td>R$ ${parseFloat(produto.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            ` : ''}
        </body>
        </html>
    `;
    
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.print();
}

// Função para exportar para Excel
function exportarExcel() {
    // Cria uma tabela HTML com os dados
    let html = '<table>';
    html += '<tr><th>Número</th><th>Data</th><th>Pregão</th><th>Classificação</th><th>Valor Total</th><th>Produtos</th></tr>';
    
    empenhosData.forEach(empenho => {
        const produtos = empenho.produtos.map(p => `${p.produto_nome} (${p.quantidade}x)`).join('; ');
        html += `<tr>
            <td>${empenho.numero}</td>
            <td>${new Date(empenho.data_empenho).toLocaleDateString('pt-BR')}</td>
            <td>${empenho.pregao || ''}</td>
            <td>${empenho.classificacao}</td>
            <td>R$ ${parseFloat(empenho.valor_total_empenho).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
            <td>${produtos}</td>
        </tr>`;
    });
    
    html += '</table>';
    
    // Cria um blob e faz o download
    const blob = new Blob(['\ufeff' + html], { type: 'application/vnd.ms-excel' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `empenhos_${empenhosData[0]?.cliente_uasg || 'cliente'}_${new Date().toISOString().split('T')[0]}.xls`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// Função para mostrar notificações toast
function showToast(message, type = 'info') {
    // Remove toast anterior se existir
    const existingToast = document.querySelector('.toast');
    if (existingToast) {
        existingToast.remove();
    }

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
            <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: inherit; cursor: pointer; padding: 0.25rem; margin-left: 1rem; border-radius: 50%; transition: opacity 0.2s;">
                <i class="fas fa-times"></i>
            </button>
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

    // Remove após 4 segundos
    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// Animações CSS para toast
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
`;
document.head.appendChild(toastStyles);

// Fechar modal ao clicar fora dele
window.onclick = function(event) {
    const modal = document.getElementById('empenhoModal');
    if (event.target === modal) {
        closeEmpenhoModal();
    }
}

// Fechar modal com ESC
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeEmpenhoModal();
    }
});

// Adiciona atalhos de teclado
document.addEventListener('keydown', function(event) {
    // Ctrl + F para focar na busca
    if ((event.ctrlKey || event.metaKey) && event.key === 'f') {
        event.preventDefault();
        document.getElementById('searchInput').focus();
    }
});

// Auto-focus no campo de pesquisa se estiver vazio
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput && !searchInput.value) {
        setTimeout(() => {
            searchInput.focus();
        }, 500);
    }
});

// Registra analytics da página
console.log('Página de empenhos do cliente carregada com sucesso!');
console.log('Cliente UASG:', '<?php echo addslashes($cliente_uasg); ?>');
console.log('Total de empenhos:', <?php echo $total_empenhos; ?>);
console.log('Valor total:', <?php echo $valor_total_empenhos; ?>);

// Auto-refresh da página a cada 10 minutos para manter dados atualizados
setInterval(() => {
    // Só atualiza se não houver modal aberto
    if (document.getElementById('empenhoModal').style.display !== 'block') {
        console.log('Auto-refresh da página de empenhos do cliente');
        // Poderia recarregar, mas melhor apenas avisar que há dados novos
        const notification = document.createElement('div');
        notification.innerHTML = `
            <div style="position: fixed; bottom: 20px; right: 20px; background: var(--info-color); color: white; padding: 1rem; border-radius: var(--radius-sm); z-index: 1000; cursor: pointer;" onclick="location.reload()">
                <i class="fas fa-refresh"></i> Clique para atualizar dados
            </div>
        `;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (document.body.contains(notification)) {
                notification.remove();
            }
        }, 10000);
    }
}, 600000); // 10 minutos

// Prevenção de perda de dados não necessária aqui, mas mantém consistência
window.addEventListener('beforeunload', function(event) {
    // Só previne se o modal estiver aberto e houve mudanças
    if (document.getElementById('empenhoModal').style.display === 'block') {
        // Não previne, apenas log para debug
        console.log('Modal estava aberto ao sair da página');
    }
});
</script>

<?php
// Finaliza a página com footer e scripts
renderFooter();
renderScripts();
?>

</body>
</html>