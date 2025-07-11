<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inclui o sistema de permissões e auditoria
require_once('db.php');
include('permissions.php');
include('includes/audit.php');

$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('produtos', 'view');

// Registra acesso à página
logUserAction('READ', 'empenhos_produto_detalhes');

// Inicializa a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";
$empenhos = [];
$produto_id = isset($_GET['produto_id']) ? (int)$_GET['produto_id'] : 0;
$produto = null;

// Função auxiliar para evitar problemas com htmlspecialchars
function safe_htmlspecialchars($value) {
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Busca informações completas do produto
if ($produto_id) {
    try {
        $sql = "SELECT * FROM produtos WHERE id = :produto_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
        $stmt->execute();
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$produto) {
            $error = "Produto não encontrado.";
        }
    } catch (PDOException $e) {
        $error = "Erro ao buscar produto: " . $e->getMessage();
        error_log("Erro ao buscar produto: " . $e->getMessage());
    }
}

// Busca empenhos relacionados ao produto
if ($produto_id && !$error) {
    try {
        // Primeiro, verifica quais colunas existem na tabela clientes
        $columns_check = $pdo->query("SHOW COLUMNS FROM clientes");
        $available_columns = [];
        while ($col = $columns_check->fetch(PDO::FETCH_ASSOC)) {
            $available_columns[] = $col['Field'];
        }
        
        // Monta a consulta baseada nas colunas disponíveis
        $cliente_fields = "c.nome_orgaos as cliente_nome_completo";
        
        // Adiciona campos opcionais se existirem
        if (in_array('responsavel', $available_columns)) {
            $cliente_fields .= ", c.responsavel as cliente_responsavel";
        }
        if (in_array('telefone', $available_columns)) {
            $cliente_fields .= ", c.telefone as cliente_telefone";
        }
        if (in_array('email', $available_columns)) {
            $cliente_fields .= ", c.email as cliente_email";
        }
        
        $sql = "SELECT 
                    e.id AS empenho_id,
                    e.numero,
                    e.cliente_nome,
                    e.cliente_uasg,
                    e.valor_total_empenho,
                    e.classificacao,
                    e.pregao,
                    e.created_at AS data_empenho,
                    e.upload,
                    ep.id AS empenho_produto_id,
                    ep.quantidade,
                    ep.valor_unitario,
                    ep.valor_total as valor_total_item,
                    ep.descricao_produto,
                    {$cliente_fields}
                FROM empenhos e
                INNER JOIN empenho_produtos ep ON e.id = ep.empenho_id
                LEFT JOIN clientes c ON e.cliente_uasg = c.uasg
                WHERE ep.produto_id = :produto_id
                ORDER BY e.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
        $stmt->execute();
        $empenhos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
        error_log("Erro na consulta de empenhos: " . $e->getMessage());
    }
}

// Calcula estatísticas
 $totalEmpenhos = count($empenhos);
$totalQuantidade = 0;
$totalValor = 0;
$totalClientes = 0;
$empenhos_pendentes = 0;
$empenhos_faturados = 0;
$empenhos_comprados = 0;
$empenhos_entregues = 0;
$empenhos_liquidados = 0;
$empenhos_devolucao = 0;

if ($totalEmpenhos > 0) {
    $clientes = [];
    foreach ($empenhos as $empenho) {
        $totalQuantidade += $empenho['quantidade'];
        $totalValor += $empenho['valor_total_item'];
        $clientes[$empenho['cliente_uasg']] = true;
        
        // Conta classificações
        switch ($empenho['classificacao']) {
            case 'Pendente':
                $empenhos_pendentes++;
                break;
            case 'Faturada':
                $empenhos_faturados++;
                break;
            case 'Comprada':
                $empenhos_comprados++;
                break;
            case 'Entregue':
                $empenhos_entregues++;
                break;
            case 'Liquidada':
                $empenhos_liquidados++;
                break;
            case 'Devolucao':
                $empenhos_devolucao++;
                break;
        }
    }
    $totalClientes = count($clientes);
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
            logAudit($pdo, $_SESSION['user']['id'], 'DELETE', 'empenhos', $empenho_id, null, $empenho_data);
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'message' => 'Empenho excluído com sucesso!']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Erro ao excluir empenho: ' . $e->getMessage()]);
        }
        exit();
    }
}

// Processa atualização de empenho
if (isset($_POST['action']) && $_POST['action'] == 'update' && $permissionManager->hasPagePermission('empenhos', 'edit')) {
    $empenho_id = isset($_POST['empenho_id']) ? intval($_POST['empenho_id']) : 0;
    
    if ($empenho_id > 0) {
        try {
            // Dados a serem atualizados
            $classificacao = $_POST['classificacao'] ?? 'Pendente';
            $pregao = $_POST['pregao'] ?? '';
            $quantidade = floatval($_POST['quantidade'] ?? 0);
            $valor_unitario = floatval($_POST['valor_unitario'] ?? 0);
            $valor_total = $quantidade * $valor_unitario;
            
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
            
            // Atualiza os produtos do empenho (apenas o relacionado ao produto atual)
            $stmt_update_produto = $pdo->prepare("UPDATE empenho_produtos SET quantidade = :quantidade, valor_unitario = :valor_unitario, valor_total = :valor_total WHERE empenho_id = :empenho_id AND produto_id = :produto_id");
            $stmt_update_produto->bindParam(':quantidade', $quantidade);
            $stmt_update_produto->bindParam(':valor_unitario', $valor_unitario);
            $stmt_update_produto->bindParam(':valor_total', $valor_total);
            $stmt_update_produto->bindParam(':empenho_id', $empenho_id);
            $stmt_update_produto->bindParam(':produto_id', $produto_id);
            $stmt_update_produto->execute();
            
            // Recalcula o valor total do empenho
            $stmt_recalc = $pdo->prepare("SELECT SUM(valor_total) as novo_total FROM empenho_produtos WHERE empenho_id = :empenho_id");
            $stmt_recalc->bindParam(':empenho_id', $empenho_id);
            $stmt_recalc->execute();
            $novo_total = $stmt_recalc->fetch(PDO::FETCH_ASSOC)['novo_total'] ?? 0;
            
            // Atualiza valor total do empenho
            $stmt_update_total = $pdo->prepare("UPDATE empenhos SET valor_total_empenho = :novo_total WHERE id = :id");
            $stmt_update_total->bindParam(':novo_total', $novo_total);
            $stmt_update_total->bindParam(':id', $empenho_id);
            $stmt_update_total->execute();
            
            // Busca dados novos para auditoria
            $stmt_new = $pdo->prepare("SELECT * FROM empenhos WHERE id = :id");
            $stmt_new->bindParam(':id', $empenho_id);
            $stmt_new->execute();
            $new_data = $stmt_new->fetch(PDO::FETCH_ASSOC);
            
            // Registra auditoria
            logAudit($pdo, $_SESSION['user']['id'], 'UPDATE', 'empenhos', $empenho_id, $new_data, $old_data);
            
            echo json_encode(['success' => true, 'message' => 'Empenho atualizado com sucesso!']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao atualizar empenho: ' . $e->getMessage()]);
        }
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

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Empenhos do Produto - LicitaSis", "produtos");
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Empenhos do Produto - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
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
            color: var(--dark-gray);
            line-height: 1.6;
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

        /* Header da página */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
            padding: 2.5rem;
            border-radius: var(--radius);
            margin-bottom: 2.5rem;
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

        .page-header h2 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: white;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .page-header .product-info {
            font-size: 1.2rem;
            opacity: 0.95;
            margin-top: 0.75rem;
            position: relative;
            z-index: 1;
        }

        .page-header .product-info strong {
            color: white;
            font-weight: 600;
        }

        /* Mensagens de feedback */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius-sm);
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

        /* Informações do produto */
        .product-summary {
            background: linear-gradient(135deg, var(--light-gray), white);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 2.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            box-shadow: var(--shadow);
        }

        .product-detail {
            text-align: center;
            padding: 1rem;
            background: white;
            border-radius: var(--radius-sm);
            border-left: 4px solid var(--secondary-color);
        }

        .product-detail i {
            font-size: 1.8rem;
            color: var(--secondary-color);
            margin-bottom: 0.75rem;
            display: block;
        }

        .product-detail .label {
            font-size: 0.9rem;
            color: var(--medium-gray);
            font-weight: 600;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .product-detail .value {
            font-size: 1.2rem;
            color: var(--dark-gray);
            font-weight: 600;
        }

        /* Estatísticas */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
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
            box-shadow: var(--shadow-hover);
        }

        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            display: block;
        }

        .stat-card.empenhos i { color: var(--info-color); }
        .stat-card.valor i { color: var(--danger-color); }
        .stat-card.quantidade i { color: var(--warning-color); }
        .stat-card.clientes i { color: var(--success-color); }
        .stat-card.pendente i { color: var(--warning-color); }
        .stat-card.faturada i { color: var(--info-color); }
        .stat-card.entregue i { color: var(--secondary-color); }
        .stat-card.liquidada i { color: var(--success-color); }

        .stat-card h3 {
            color: var(--medium-gray);
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .value {
            color: var(--dark-gray);
            font-size: 1.8rem;
            font-weight: 700;
            font-family: 'Courier New', monospace;
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

        /* Lista de empenhos */
        .empenhos-list {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .empenho-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
            overflow: hidden;
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
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .empenho-title {
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .empenho-number {
            background: rgba(255,255,255,0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-weight: 700;
        }

        .classificacao-badge {
            padding: 0.75rem 1.25rem;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .classificacao-badge.faturada { background: var(--info-color); }
        .classificacao-badge.comprada { background: var(--warning-color); color: var(--dark-gray); }
        .classificacao-badge.entregue { background: var(--secondary-color); }
        .classificacao-badge.liquidada { background: var(--success-color); }
        .classificacao-badge.pendente { background: var(--medium-gray); }
        .classificacao-badge.devolucao { background: var(--danger-color); }

        .empenho-body {
            padding: 2rem;
        }

        .empenho-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .detail-item {
            background: var(--light-gray);
            padding: 1.25rem;
            border-radius: var(--radius-sm);
            border-left: 3px solid var(--secondary-color);
        }

        .detail-label {
            font-size: 0.85rem;
            color: var(--medium-gray);
            font-weight: 600;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 1.1rem;
            color: var(--dark-gray);
            font-weight: 600;
        }

        .detail-value.currency {
            color: var(--success-color);
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
        }

        .detail-value.highlight {
            color: var(--primary-color);
            font-size: 1.2rem;
        }

        /* Ações do empenho */
        .empenho-actions {
            padding: 1.5rem 2rem;
            background: var(--light-gray);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            flex-wrap: wrap;
            border-top: 1px solid var(--border-color);
        }

        /* Modal de detalhes/edição */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 95%;
            max-width: 1000px;
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
            padding: 2rem;
            border-radius: var(--radius) var(--radius) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.6rem;
            font-weight: 600;
        }

        .close {
            color: white;
            font-size: 2.2rem;
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
            padding: 2.5rem;
        }

        /* Tabela de empenhos (modo compacto) */
        .table-container {
            overflow-x: auto;
            margin-bottom: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            display: none;
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
            white-space: nowrap;
        }

        table th i {
            margin-right: 0.5rem;
        }

        table tr:hover {
            background: var(--light-gray);
        }

        table td.currency {
            font-weight: 600;
            color: var(--success-color);
            font-family: 'Courier New', monospace;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--medium-gray);
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            color: var(--dark-gray);
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
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
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            min-width: 140px;
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
            box-shadow: 0 2px 4px rgba(108, 117, 125, 0.2);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268 0%, var(--medium-gray) 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            min-width: 100px;
        }

        /* Toggle de visualização */
        .view-toggle {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--light-gray);
            border-radius: var(--radius);
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--medium-gray);
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--secondary-color);
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        /* Análise de preços */
        .price-analysis {
            background: linear-gradient(135deg, #e8f5e8 0%, #c3e6cb 100%);
            padding: 2rem;
            border-radius: var(--radius);
            margin-top: 2rem;
            border-left: 4px solid var(--success-color);
        }

        .price-analysis h3 {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.3rem;
        }

        .price-analysis-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
        }

        .price-analysis-item {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            text-align: center;
            border: 1px solid var(--success-color);
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.1);
        }

        .price-analysis-item .value {
            font-size: 1.4rem;
            font-weight: bold;
            color: var(--success-color);
            margin-top: 0.75rem;
            font-family: 'Courier New', monospace;
        }

        .price-analysis-item .label {
            font-size: 0.9rem;
            color: var(--medium-gray);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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

        /* Botões de ação da página */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2.5rem;
            flex-wrap: wrap;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
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

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 2rem;
                padding: 2rem;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin: 1.5rem;
                padding: 1.5rem;
            }

            .page-header {
                padding: 1.5rem;
            }

            .page-header h2 {
                font-size: 1.8rem;
                flex-direction: column;
                gap: 0.5rem;
            }

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .product-summary {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .filters-container {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }

            .empenho-header {
                flex-direction: column;
                text-align: center;
            }

            .empenho-details {
                grid-template-columns: 1fr;
            }

            .btn-container {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .view-toggle {
                flex-direction: column;
                text-align: center;
            }

            .price-analysis-grid {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 98%;
                margin: 1% auto;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 1rem;
                margin: 1rem;
            }

            .page-header {
                padding: 1rem;
            }

            .page-header h2 {
                font-size: 1.5rem;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 1.5rem;
            }

            .stat-card .value {
                font-size: 1.5rem;
            }

            .empenho-card {
                margin-bottom: 1rem;
            }

            .empenho-header {
                padding: 1rem;
            }

            .empenho-body {
                padding: 1rem;
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
        }

        /* Animações */
        .empenho-card {
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }

        .empenho-card:nth-child(1) { animation-delay: 0.1s; }
        .empenho-card:nth-child(2) { animation-delay: 0.2s; }
        .empenho-card:nth-child(3) { animation-delay: 0.3s; }
        .empenho-card:nth-child(4) { animation-delay: 0.4s; }
        .empenho-card:nth-child(5) { animation-delay: 0.5s; }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Estilo para impressão */
        @media print {
            .filters-container, .btn-container, .view-toggle, .empenho-actions {
                display: none !important;
            }
            
            .container {
                margin: 0;
                box-shadow: none;
                background: white;
            }
            
            .empenho-card {
                break-inside: avoid;
            }
            
            .page-header {
                background: white !important;
                color: black !important;
            }
        }

        /* Scrollbar personalizada */
        .table-container::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: var(--light-gray);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: var(--medium-gray);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: var(--dark-gray);
        }
    </style>
</head>
<body>

<div class="main-content">
    <div class="container">
        
        <!-- Header da página -->
        <div class="page-header">
            <h2><i class="fas fa-box"></i> Empenhos do Produto</h2>
            <div class="product-info">
                <i class="fas fa-tag"></i> <strong><?php echo safe_htmlspecialchars($produto['nome'] ?? 'Produto não encontrado'); ?></strong>
                <?php if ($produto && $produto['codigo']): ?>
                    | Código: <strong><?php echo safe_htmlspecialchars($produto['codigo']); ?></strong>
                <?php endif; ?>
                <?php if ($produto && $produto['categoria']): ?>
                    | Categoria: <strong><?php echo safe_htmlspecialchars($produto['categoria']); ?></strong>
                <?php endif; ?>
            </div>
        </div>

        <!-- Mensagens de feedback -->
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo safe_htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
      
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo safe_htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if (!$error && $produto): ?>
            <!-- Informações do produto -->
            <div class="product-summary">
                <div class="product-detail">
                    <i class="fas fa-barcode"></i>
                    <div class="label">Código</div>
                    <div class="value"><?php echo safe_htmlspecialchars($produto['codigo'] ?: 'Não informado'); ?></div>
                </div>
                
                <div class="product-detail">
                    <i class="fas fa-tags"></i>
                    <div class="label">Categoria</div>
                    <div class="value"><?php echo safe_htmlspecialchars($produto['categoria'] ?: 'Não informado'); ?></div>
                </div>
                
                <div class="product-detail">
                    <i class="fas fa-dollar-sign"></i>
                    <div class="label">Preço Unitário</div>
                    <div class="value">R$ <?php echo number_format($produto['preco_unitario'] ?? 0, 2, ',', '.'); ?></div>
                </div>
                
                <?php if ($totalEmpenhos > 0): ?>
                <div class="product-detail">
                    <i class="fas fa-calendar-plus"></i>
                    <div class="label">Primeiro Empenho</div>
                    <div class="value">
                        <?php 
                            $firstEmpenho = end($empenhos);
                            echo date('d/m/Y', strtotime($firstEmpenho['data_empenho'])); 
                        ?>
                    </div>
                </div>
                
                <div class="product-detail">
                    <i class="fas fa-calendar-check"></i>
                    <div class="label">Último Empenho</div>
                    <div class="value">
                        <?php 
                            $lastEmpenho = reset($empenhos);
                            echo date('d/m/Y', strtotime($lastEmpenho['data_empenho'])); 
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Estatísticas -->
            <div class="stats-container">
                <div class="stat-card empenhos">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <h3>Total de Empenhos</h3>
                    <div class="value"><?php echo $totalEmpenhos; ?></div>
                </div>
                <div class="stat-card valor">
                    <i class="fas fa-coins"></i>
                    <h3>Valor Total</h3>
                    <div class="value">
                        R$ <?php echo number_format($totalValor, 2, ',', '.'); ?>
                    </div>
                </div>
                <div class="stat-card quantidade">
                    <i class="fas fa-cubes"></i>
                    <h3>Quantidade Total</h3>
                    <div class="value">
                        <?php echo number_format($totalQuantidade, 0, ',', '.'); ?>
                    </div>
                </div>
                <div class="stat-card clientes">
                    <i class="fas fa-building"></i>
                    <h3>Clientes (UASG)</h3>
                    <div class="value"><?php echo $totalClientes; ?></div>
                </div>
                <div class="stat-card pendente">
                    <i class="fas fa-clock"></i>
                    <h3>Pendentes</h3>
                    <div class="value"><?php echo $empenhos_pendentes; ?></div>
                </div>
                <div class="stat-card faturada">
                    <i class="fas fa-file-invoice"></i>
                    <h3>Faturados</h3>
                    <div class="value"><?php echo $empenhos_faturados; ?></div>
                </div>
                <div class="stat-card entregue">
                    <i class="fas fa-truck"></i>
                    <h3>Entregues</h3>
                    <div class="value"><?php echo $empenhos_entregues; ?></div>
                </div>
                <div class="stat-card liquidada">
                    <i class="fas fa-check-circle"></i>
                    <h3>Liquidados</h3>
                    <div class="value"><?php echo $empenhos_liquidados; ?></div>
                </div>
            </div>

            <?php if ($totalEmpenhos > 0): ?>
                <!-- Toggle de visualização -->
                <div class="view-toggle">
                    <span>Visualização em Cards</span>
                    <label class="toggle-switch">
                        <input type="checkbox" id="viewToggle" onchange="toggleView()">
                        <span class="slider"></span>
                    </label>
                    <span>Visualização em Tabela</span>
                </div>

                <!-- Filtros -->
                <div class="filters-container">
                    <div class="filter-group">
                        <label for="filterClassificacao">Filtrar por Classificação:</label>
                        <select id="filterClassificacao" onchange="filterEmpenhos()">
                            <option value="">Todas as classificações</option>
                            <option value="Faturada">Faturada</option>
                            <option value="Comprada">Comprada</option>
                            <option value="Entregue">Entregue</option>
                            <option value="Liquidada">Liquidada</option>
                            <option value="Pendente">Pendente</option>
                            <option value="Devolucao">Devolução</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="filterCliente">Filtrar por Cliente:</label>
                        <select id="filterCliente" onchange="filterEmpenhos()">
                            <option value="">Todos os clientes</option>
                            <?php 
                                $clientesUnicos = array_unique(array_column($empenhos, 'cliente_nome'));
                                sort($clientesUnicos);
                                foreach ($clientesUnicos as $cliente): 
                            ?>
                                <option value="<?php echo safe_htmlspecialchars($cliente); ?>">
                                    <?php echo safe_htmlspecialchars($cliente); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="filterDate">Filtrar por Data:</label>
                        <input type="month" id="filterDate" onchange="filterEmpenhos()">
                    </div>
                    <div class="filter-group">
                        <label for="searchInput">Buscar:</label>
                        <input type="text" id="searchInput" placeholder="Buscar por número, cliente..." onkeyup="filterEmpenhos()">
                    </div>
                </div>

                <!-- Lista de empenhos (modo cards) -->
                <div class="empenhos-list" id="empenhosCards">
                    <?php foreach ($empenhos as $empenho): ?>
                        <div class="empenho-card" 
                             data-empenho='<?php echo json_encode($empenho); ?>'
                             data-classificacao="<?php echo safe_htmlspecialchars($empenho['classificacao']); ?>"
                             data-cliente="<?php echo safe_htmlspecialchars($empenho['cliente_nome']); ?>"
                             data-date="<?php echo $empenho['data_empenho']; ?>"
                             data-numero="<?php echo safe_htmlspecialchars($empenho['numero']); ?>">
                            
                            <div class="empenho-header">
                                <div class="empenho-title">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    Empenho 
                                    <span class="empenho-number"><?php echo safe_htmlspecialchars($empenho['numero']); ?></span>
                                </div>
                                <div class="classificacao-badge <?php echo strtolower($empenho['classificacao']); ?>">
                                    <i class="fas fa-<?php 
                                        echo match(strtolower($empenho['classificacao'])) {
                                            'faturada' => 'file-invoice',
                                            'comprada' => 'shopping-cart',
                                            'entregue' => 'truck',
                                            'liquidada' => 'check-circle',
                                            'pendente' => 'clock',
                                            'devolucao' => 'undo',
                                            default => 'info-circle'
                                        };
                                    ?>"></i>
                                    <?php echo safe_htmlspecialchars($empenho['classificacao']); ?>
                                </div>
                            </div>

                            <div class="empenho-body">
                                <div class="empenho-details">
                                    <div class="detail-item">
                                        <div class="detail-label">Cliente</div>
                                        <div class="detail-value highlight">
                                            <?php echo safe_htmlspecialchars($empenho['cliente_nome']); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">UASG</div>
                                        <div class="detail-value">
                                            <?php echo safe_htmlspecialchars($empenho['cliente_uasg']); ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($empenho['pregao'])): ?>
                                        <div class="detail-item">
                                            <div class="detail-label">Pregão</div>
                                            <div class="detail-value">
                                                <?php echo safe_htmlspecialchars($empenho['pregao']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="detail-item">
                                        <div class="detail-label">Quantidade</div>
                                        <div class="detail-value">
                                            <?php echo number_format($empenho['quantidade'], 0, ',', '.'); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Valor Unitário</div>
                                        <div class="detail-value currency">
                                            R$ <?php echo number_format($empenho['valor_unitario'], 2, ',', '.'); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Valor Total do Item</div>
                                        <div class="detail-value currency">
                                            R$ <?php echo number_format($empenho['valor_total_item'], 2, ',', '.'); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Valor Total do Empenho</div>
                                        <div class="detail-value currency highlight">
                                            R$ <?php echo number_format($empenho['valor_total_empenho'], 2, ',', '.'); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Data do Empenho</div>
                                        <div class="detail-value">
                                            <?php echo date('d/m/Y H:i', strtotime($empenho['data_empenho'])); ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($empenho['upload'])): ?>
                                        <div class="detail-item">
                                            <div class="detail-label">Arquivo Anexo</div>
                                            <div class="detail-value">
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

                                <?php if (!empty($empenho['descricao_produto'])): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Descrição do Produto</div>
                                        <div class="detail-value" style="font-size: 0.95rem; line-height: 1.4;">
                                            <?php echo safe_htmlspecialchars($empenho['descricao_produto']); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="empenho-actions">
                                <button onclick="openEmpenhoModal(<?php echo $empenho['empenho_id']; ?>, false)" 
                                        class="btn btn-info btn-sm" title="Ver Detalhes">
                                    <i class="fas fa-eye"></i> Detalhes
                                </button>
                                <?php if ($permissionManager->hasPagePermission('empenhos', 'edit')): ?>
                                <button onclick="openEmpenhoModal(<?php echo $empenho['empenho_id']; ?>, true)" 
                                        class="btn btn-warning btn-sm" title="Editar">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Tabela de empenhos (modo tabela) -->
                <div class="table-container" id="empenhosTable">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fas fa-hashtag"></i> Número</th>
                                <th><i class="fas fa-building"></i> Cliente</th>
                                <th><i class="fas fa-code"></i> UASG</th>
                                <th><i class="fas fa-cubes"></i> Quantidade</th>
                                <th><i class="fas fa-tag"></i> Valor Unitário</th>
                                <th><i class="fas fa-money-check-alt"></i> Valor Item</th>
                                <th><i class="fas fa-calendar"></i> Data</th>
                                <th><i class="fas fa-info-circle"></i> Status</th>
                                <th><i class="fas fa-cogs"></i> Ações</th>
                            </tr>
                        </thead>
                        <tbody id="empenhosTableBody">
                            <?php foreach ($empenhos as $empenho): ?>
                                <tr data-classificacao="<?php echo safe_htmlspecialchars($empenho['classificacao']); ?>"
                                    data-cliente="<?php echo safe_htmlspecialchars($empenho['cliente_nome']); ?>"
                                    data-date="<?php echo $empenho['data_empenho']; ?>"
                                    data-numero="<?php echo safe_htmlspecialchars($empenho['numero']); ?>">
                                    
                                    <td>
                                        <strong><?php echo safe_htmlspecialchars($empenho['numero']); ?></strong>
                                    </td>
                                    <td><?php echo safe_htmlspecialchars($empenho['cliente_nome']); ?></td>
                                    <td><?php echo safe_htmlspecialchars($empenho['cliente_uasg']); ?></td>
                                    <td><?php echo number_format($empenho['quantidade'], 0, ',', '.'); ?></td>
                                    <td class="currency">R$ <?php echo number_format($empenho['valor_unitario'], 2, ',', '.'); ?></td>
                                    <td class="currency">R$ <?php echo number_format($empenho['valor_total_item'], 2, ',', '.'); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($empenho['data_empenho'])); ?></td>
                                    <td>
                                        <span class="classificacao-badge <?php echo strtolower($empenho['classificacao']); ?>" 
                                              style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                                            <?php echo safe_htmlspecialchars($empenho['classificacao']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button onclick="openEmpenhoModal(<?php echo $empenho['empenho_id']; ?>, false)" 
                                                class="btn btn-info btn-sm" title="Ver Detalhes" style="margin-right: 0.25rem;">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($permissionManager->hasPagePermission('empenhos', 'edit')): ?>
                                        <button onclick="openEmpenhoModal(<?php echo $empenho['empenho_id']; ?>, true)" 
                                                class="btn btn-warning btn-sm" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Análise de Preços -->
                <div class="price-analysis">
                    <h3>
                        <i class="fas fa-chart-line"></i>
                        Análise de Preços
                    </h3>
                    <div class="price-analysis-grid">
                        <div class="price-analysis-item">
                            <div class="label">Preço Médio</div>
                            <div class="value">
                                R$ <?php 
                                    $precoMedio = ($totalQuantidade != 0) ? $totalValor / $totalQuantidade : 0;
                                    echo number_format($precoMedio, 2, ',', '.'); 
                                ?>
                            </div>
                        </div>
                        <div class="price-analysis-item">
                            <div class="label">Maior Preço Unitário</div>
                            <div class="value">
                                R$ <?php 
                                    $precos = array_column($empenhos, 'valor_unitario');
                                    echo number_format(max($precos), 2, ',', '.'); 
                                ?>
                            </div>
                        </div>
                        <div class="price-analysis-item">
                            <div class="label">Menor Preço Unitário</div>
                            <div class="value">
                                R$ <?php echo number_format(min($precos), 2, ',', '.'); ?>
                            </div>
                        </div>
                        <div class="price-analysis-item">
                            <div class="label">Variação de Preço</div>
                            <div class="value">
                                <?php 
                                    $variacao = max($precos) - min($precos);
                                    $percentual = min($precos) > 0 ? ($variacao / min($precos)) * 100 : 0;
                                    echo number_format($percentual, 1) . '%';
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Estado vazio -->
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>Nenhum Empenho Encontrado</h3>
                    <p>Este produto ainda não foi incluído em nenhum empenho.</p>
                    <p style="font-size: 1rem; color: var(--medium-gray);">
                        Verifique se existem empenhos cadastrados ou se o produto está correto.
                    </p>
                    <?php if ($permissionManager->hasPagePermission('empenhos', 'create')): ?>
                        <div style="margin-top: 1.5rem;">
                            <a href="cadastro_empenho.php?produto_id=<?php echo $produto_id; ?>" class="btn btn-success">
                                <i class="fas fa-plus"></i> Criar Primeiro Empenho
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Botões de ação -->
        <div class="btn-container">
            <a href="consulta_produto.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar para Produtos
            </a>
            
            <?php if ($totalEmpenhos > 0): ?>
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Imprimir Relatório
                </button>
                <button onclick="exportToCSV()" class="btn btn-success">
                    <i class="fas fa-file-csv"></i> Exportar CSV
                </button>
                <?php if ($permissionManager->hasPagePermission('empenhos', 'create')): ?>
                <a href="cadastro_empenho.php?produto_id=<?php echo $produto_id; ?>" class="btn btn-success">
                    <i class="fas fa-plus"></i> Novo Empenho
                </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal de detalhes do empenho -->
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

<?php
// Finaliza a página
if (function_exists('renderFooter')) {
    renderFooter();
}
if (function_exists('renderScripts')) {
    renderScripts();
}
?>

<script>
// Dados dos empenhos para uso no JavaScript
const empenhosData = <?php echo json_encode($empenhos); ?>;
const produtoData = <?php echo json_encode($produto); ?>;

// Variáveis globais para controle do modal
let currentEmpenhoId = null;
let editMode = false;

console.log('Dados carregados:', {
    empenhosData: empenhosData,
    produtoData: produtoData,
    totalEmpenhos: empenhosData.length
});

// Função para alternar entre visualização em cards e tabela
function toggleView() {
    const toggle = document.getElementById('viewToggle');
    const cardsView = document.getElementById('empenhosCards');
    const tableView = document.getElementById('empenhosTable');
    
    if (toggle.checked) {
        // Mostrar tabela
        cardsView.style.display = 'none';
        tableView.style.display = 'block';
    } else {
        // Mostrar cards
        cardsView.style.display = 'flex';
        tableView.style.display = 'none';
    }
}

// Função para filtrar empenhos
function filterEmpenhos() {
    const classificacaoFilter = document.getElementById('filterClassificacao').value;
    const clienteFilter = document.getElementById('filterCliente').value;
    const dateFilter = document.getElementById('filterDate').value;
    const searchInput = document.getElementById('searchInput').value.toLowerCase();
    
    // Filtrar cards
    const cards = document.querySelectorAll('.empenho-card');
    let visibleCards = 0;
    
    cards.forEach(card => {
        let showCard = true;
        
        // Filtro de classificação
        if (classificacaoFilter && card.getAttribute('data-classificacao') !== classificacaoFilter) {
            showCard = false;
        }
        
        // Filtro de cliente
        if (clienteFilter && card.getAttribute('data-cliente') !== clienteFilter) {
            showCard = false;
        }
        
        // Filtro de data
        if (dateFilter && showCard) {
            const cardDate = card.getAttribute('data-date');
            if (cardDate) {
                const cardMonth = cardDate.substring(0, 7);
                if (cardMonth !== dateFilter) {
                    showCard = false;
                }
            }
        }
        
        // Busca geral
        if (searchInput && showCard) {
            const cardText = card.textContent.toLowerCase();
            if (!cardText.includes(searchInput)) {
                showCard = false;
            }
        }
        
        card.style.display = showCard ? 'block' : 'none';
        if (showCard) visibleCards++;
    });
    
    // Filtrar tabela
    const tableRows = document.querySelectorAll('#empenhosTableBody tr');
    let visibleRows = 0;
    
    tableRows.forEach(row => {
        let showRow = true;
        
        // Filtro de classificação
        if (classificacaoFilter && row.getAttribute('data-classificacao') !== classificacaoFilter) {
            showRow = false;
        }
        
        // Filtro de cliente
        if (clienteFilter && row.getAttribute('data-cliente') !== clienteFilter) {
            showRow = false;
        }
        
        // Filtro de data
        if (dateFilter && showRow) {
            const rowDate = row.getAttribute('data-date');
            if (rowDate) {
                const rowMonth = rowDate.substring(0, 7);
                if (rowMonth !== dateFilter) {
                    showRow = false;
                }
            }
        }
        
        // Busca geral
        if (searchInput && showRow) {
            const rowText = row.textContent.toLowerCase();
            if (!rowText.includes(searchInput)) {
                showRow = false;
            }
        }
        
        row.style.display = showRow ? '' : 'none';
        if (showRow) visibleRows++;
    });
    
    // Atualiza contador de resultados
    console.log(`Mostrando ${Math.max(visibleCards, visibleRows)} de <?php echo $totalEmpenhos; ?> empenhos`);
}

// Função para abrir modal com detalhes do empenho
function openEmpenhoModal(empenhoId, startInEditMode = false) {
    console.log('Abrindo modal para empenho ID:', empenhoId);
    console.log('Dados disponíveis:', empenhosData);
    
    const empenho = empenhosData.find(e => e.empenho_id == empenhoId);
    
    if (!empenho) {
        console.error('Empenho não encontrado! ID:', empenhoId);
        alert('Empenho não encontrado!');
        return;
    }
    
    console.log('Empenho encontrado:', empenho);
    
    if (!empenho) {
        alert('Empenho não encontrado!');
        return;
    }
    
    currentEmpenhoId = empenhoId;
    editMode = false;
    
    const modalBody = document.getElementById('modalBody');
    
    modalBody.innerHTML = `
        <div id="viewMode" style="${startInEditMode ? 'display: none;' : ''}">
            <div class="modal-detail-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                <div class="modal-detail-item" style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
                    <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Número do Empenho</div>
                    <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.2rem; font-weight: 600;">${empenho.numero}</div>
                </div>
                <div class="modal-detail-item" style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
                    <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Cliente</div>
                    <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.2rem; font-weight: 600;">${empenho.cliente_nome}</div>
                </div>
                <div class="modal-detail-item" style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
                    <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">UASG</div>
                    <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.2rem; font-weight: 600;">${empenho.cliente_uasg}</div>
                </div>
                <div class="modal-detail-item" style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
                    <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Pregão</div>
                    <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.2rem; font-weight: 600;">${empenho.pregao || 'Não informado'}</div>
                </div>
                <div class="modal-detail-item" style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
                    <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Classificação</div>
                    <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.2rem; font-weight: 600;">
                    <span class="classificacao-badge ${empenho.classificacao.toLowerCase()}" style="font-size: 0.9rem; padding: 0.5rem 1rem;">
                            <i class="fas ${getStatusIcon(empenho.classificacao)}"></i> 
                            ${empenho.classificacao}
                        </span>
                    </div>
                </div>
                <div class="modal-detail-item" style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
                    <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Data do Empenho</div>
                    <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.2rem; font-weight: 600;">${new Date(empenho.data_empenho).toLocaleDateString('pt-BR')}</div>
                </div>
                <div class="modal-detail-item" style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
                    <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Quantidade</div>
                    <div class="modal-detail-value" style="color: var(--primary-color); font-size: 1.3rem; font-weight: 700;">
                        ${parseInt(empenho.quantidade).toLocaleString('pt-BR')}
                    </div>
                </div>
                <div class="modal-detail-item" style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
                    <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Valor Unitário</div>
                    <div class="modal-detail-value" style="color: var(--success-color); font-size: 1.3rem; font-weight: 700; font-family: 'Courier New', monospace;">
                        R$ ${parseFloat(empenho.valor_unitario).toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                    </div>
                </div>
                <div class="modal-detail-item" style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
                    <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Valor Total do Item</div>
                    <div class="modal-detail-value" style="color: var(--success-color); font-size: 1.3rem; font-weight: 700; font-family: 'Courier New', monospace;">
                        R$ ${parseFloat(empenho.valor_total_item).toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                    </div>
                </div>
                <div class="modal-detail-item" style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
                    <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Valor Total do Empenho</div>
                    <div class="modal-detail-value" style="color: var(--primary-color); font-size: 1.4rem; font-weight: 800; font-family: 'Courier New', monospace;">
                        R$ ${parseFloat(empenho.valor_total_empenho).toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                    </div>
                </div>
                ${empenho.upload ? `
                <div class="modal-detail-item" style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
                    <div class="modal-detail-label" style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Arquivo Anexo</div>
                    <div class="modal-detail-value" style="color: var(--dark-gray); font-size: 1.2rem; font-weight: 600;">
                        <a href="uploads/${empenho.upload}" target="_blank" class="arquivo-anexo">
                            <i class="fas fa-file-pdf"></i> Ver Arquivo
                        </a>
                    </div>
                </div>
                ` : ''}
            </div>
            
            ${empenho.descricao_produto ? `
            <div style="background: var(--light-gray); padding: 1.5rem; border-radius: var(--radius-sm); border-left: 4px solid var(--primary-color); margin-bottom: 1.5rem;">
                <div style="color: var(--medium-gray); font-size: 0.9rem; font-weight: 600; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Descrição do Produto</div>
                <div style="color: var(--dark-gray); font-size: 1rem; line-height: 1.6;">${empenho.descricao_produto}</div>
            </div>
            ` : ''}
        </div>

        <div id="editMode" class="edit-form" style="${startInEditMode ? '' : 'display: none;'}">
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
                        <option value="Faturada" ${empenho.classificacao === 'Faturada' ? 'selected' : ''}>Faturada</option>
                        <option value="Comprada" ${empenho.classificacao === 'Comprada' ? 'selected' : ''}>Comprada</option>
                        <option value="Entregue" ${empenho.classificacao === 'Entregue' ? 'selected' : ''}>Entregue</option>
                        <option value="Liquidada" ${empenho.classificacao === 'Liquidada' ? 'selected' : ''}>Liquidada</option>
                        <option value="Devolucao" ${empenho.classificacao === 'Devolucao' ? 'selected' : ''}>Devolução</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_quantidade">Quantidade</label>
                    <input type="number" id="edit_quantidade" name="quantidade" value="${empenho.quantidade}" class="form-control" min="0" step="1" required>
                </div>
                <div class="form-group">
                    <label for="edit_valor_unitario">Valor Unitário (R$)</label>
                    <input type="number" id="edit_valor_unitario" name="valor_unitario" value="${empenho.valor_unitario}" class="form-control" min="0" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Valor Total Calculado</label>
                    <input type="text" id="valor_total_calculado" class="form-control" readonly style="background: var(--light-gray); cursor: not-allowed; font-weight: bold; color: var(--success-color);">
                </div>
                
                <div style="background: var(--info-color); color: white; padding: 1rem; border-radius: var(--radius-sm); margin: 1.5rem 0;">
                    <p style="margin: 0; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-info-circle"></i> 
                        Ao alterar quantidade ou valor unitário, o valor total do empenho será recalculado automaticamente.
                    </p>
                </div>

                <div class="modal-actions" style="display: flex; justify-content: space-between; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                    <div class="modal-actions-left">
                        <button type="button" onclick="toggleEdit(false)" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                    <div class="modal-actions-right" style="display: flex; gap: 0.75rem;">
                        <?php if ($permissionManager->hasPagePermission('empenhos', 'delete')): ?>
                        <button type="button" onclick="confirmDeleteEmpenho()" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Excluir Empenho
                        </button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Salvar Alterações
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="modal-actions" id="viewActions" style="display: ${startInEditMode ? 'none' : 'flex'}; justify-content: center; gap: 1rem; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
            <button onclick="closeEmpenhoModal()" class="btn btn-secondary">
                <i class="fas fa-times"></i> Fechar
            </button>
            <?php if ($permissionManager->hasPagePermission('empenhos', 'edit')): ?>
            <button onclick="toggleEdit(true)" class="btn btn-warning">
                <i class="fas fa-edit"></i> Editar
            </button>
            <?php endif; ?>
        </div>
    `;
    
    document.getElementById('empenhoModal').style.display = 'block';
    
    // Se deve iniciar em modo de edição
    if (startInEditMode) {
        editMode = true;
        setupEditFormEvents();
    }
}

// Função para configurar eventos do formulário de edição
function setupEditFormEvents() {
    const quantidadeInput = document.getElementById('edit_quantidade');
    const valorUnitarioInput = document.getElementById('edit_valor_unitario');
    const valorTotalField = document.getElementById('valor_total_calculado');
    
    function calcularValorTotal() {
        const quantidade = parseFloat(quantidadeInput.value) || 0;
        const valorUnitario = parseFloat(valorUnitarioInput.value) || 0;
        const valorTotal = quantidade * valorUnitario;
        
        valorTotalField.value = `R$ ${valorTotal.toLocaleString('pt-BR', {minimumFractionDigits: 2})}`;
    }
    
    // Calcula valor inicial
    calcularValorTotal();
    
    // Adiciona eventos
    quantidadeInput.addEventListener('input', calcularValorTotal);
    valorUnitarioInput.addEventListener('input', calcularValorTotal);
}

// Função auxiliar para obter ícone do status
function getStatusIcon(classificacao) {
    const icons = {
        'Pendente': 'fa-clock',
        'Faturada': 'fa-file-invoice',
        'Comprada': 'fa-shopping-cart',
        'Entregue': 'fa-truck',
        'Liquidada': 'fa-check-circle',
        'Devolucao': 'fa-undo'
    };
    return icons[classificacao] || 'fa-question';
}

// Função para alternar entre modo visualização e edição
function toggleEdit(edit) {
    editMode = edit;
    const viewMode = document.getElementById('viewMode');
    const editModeEl = document.getElementById('editMode');
    const viewActions = document.getElementById('viewActions');
    
    if (edit) {
        viewMode.style.display = 'none';
        editModeEl.style.display = 'block';
        viewActions.style.display = 'none';
        setupEditFormEvents();
    } else {
        viewMode.style.display = 'block';
        editModeEl.style.display = 'none';
        viewActions.style.display = 'flex';
    }
}

// Função para salvar alterações do empenho
// Função para salvar alterações do empenho
function saveEmpenho(event) {
    event.preventDefault();
    
    // Validação
    const pregao = document.getElementById('edit_pregao').value;
    const classificacao = document.getElementById('edit_classificacao').value;
    const quantidade = parseFloat(document.getElementById('edit_quantidade').value);
    const valorUnitario = parseFloat(document.getElementById('edit_valor_unitario').value);
    
    if (!classificacao) {
        alert('Por favor, selecione uma classificação');
        return false;
    }
    
    if (quantidade <= 0) {
        alert('A quantidade deve ser maior que zero');
        return false;
    }
    
    if (valorUnitario <= 0) {
        alert('O valor unitário deve ser maior que zero');
        return false;
    }
    
    if (!currentEmpenhoId) {
        alert('Erro: ID do empenho não encontrado');
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
    
    // Corrigir a URL do fetch
    fetch(window.location.pathname + '?produto_id=<?php echo $produto_id; ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Empenho atualizado com sucesso!');
            location.reload();
        } else {
            alert('Erro ao atualizar empenho: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao salvar alterações. Tente novamente.');
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
    
    return false;
}
    

// Função para confirmar exclusão do empenho
function confirmDeleteEmpenho() {
    if (!confirm('Tem certeza de que deseja excluir este empenho?\n\nEsta ação não pode ser desfeita e removerá:\n- O empenho completo\n- Todos os produtos associados\n- Todas as informações relacionadas')) {
        return;
    }
    
    deleteEmpenho(currentEmpenhoId);
}

// Função para excluir empenho
function deleteEmpenho(empenhoId) {
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('empenho_id', empenhoId);
    
    // Corrigir a URL do fetch
    fetch(window.location.pathname + '?produto_id=<?php echo $produto_id; ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Empenho excluído com sucesso!');
            closeEmpenhoModal();
            location.reload();
        } else {
            alert('Erro ao excluir empenho: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao excluir empenho. Tente novamente.');
    });
}

// Função para fechar modal
function closeEmpenhoModal() {
    document.getElementById('empenhoModal').style.display = 'none';
    currentEmpenhoId = null;
    editMode = false;
}

// Função para exportar para CSV
function exportToCSV() {
    const isTableView = document.getElementById('viewToggle').checked;
    let csv = [];
    
    // Cabeçalho do CSV
    csv.push('"Número","Cliente","UASG","Pregão","Quantidade","Valor Unitário","Valor Item","Valor Empenho","Data","Status","Descrição"');
    
    // Filtra apenas os empenhos visíveis
    const visibleEmpenhos = empenhosData.filter((empenho, index) => {
        const card = document.querySelectorAll('.empenho-card')[index];
        return card && card.style.display !== 'none';
    });
    
    visibleEmpenhos.forEach(empenho => {
        const row = [
            `"${empenho.numero}"`,
            `"${empenho.cliente_nome}"`,
            `"${empenho.cliente_uasg}"`,
            `"${empenho.pregao || ''}"`,
            `"${parseInt(empenho.quantidade).toLocaleString('pt-BR')}"`,
            `"R$ ${parseFloat(empenho.valor_unitario).toLocaleString('pt-BR', {minimumFractionDigits: 2})}"`,
            `"R$ ${parseFloat(empenho.valor_total_item).toLocaleString('pt-BR', {minimumFractionDigits: 2})}"`,
            `"R$ ${parseFloat(empenho.valor_total_empenho).toLocaleString('pt-BR', {minimumFractionDigits: 2})}"`,
            `"${new Date(empenho.data_empenho).toLocaleDateString('pt-BR')}"`,
            `"${empenho.classificacao}"`,
            `"${empenho.descricao_produto || ''}"`
        ];
        
        csv.push(row.join(','));
    });
    
    const csvString = csv.join('\n');
    const filename = `empenhos_produto_${produtoData.nome}_${new Date().toLocaleDateString('pt-BR').replace(/\//g, '-')}.csv`;
    
    const link = document.createElement('a');
    link.style.display = 'none';
    link.setAttribute('target', '_blank');
    link.setAttribute('href', 'data:text/csv;charset=utf-8,' + encodeURIComponent(csvString));
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Função para animação de números
function animateNumbers() {
    const statNumbers = document.querySelectorAll('.stat-card .value');
    
    statNumbers.forEach(element => {
        const text = element.innerText.trim();
        
        // Verifica se é um valor monetário
        if (text.includes('R$')) {
            const finalValue = parseFloat(text.replace('R$', '').replace(/\./g, '').replace(',', '.'));
            if (!isNaN(finalValue) && finalValue > 0) {
                animateNumber(element, finalValue, true);
            }
        } else {
            // É um número simples
            const finalValue = parseInt(text.replace(/\D/g, ''));
            if (!isNaN(finalValue) && finalValue > 0) {
                animateNumber(element, finalValue, false);
            }
        }
    });
}

function animateNumber(element, finalValue, isCurrency = false) {
    let startValue = 0;
    const duration = 1500;
    const frameRate = 60;
    const totalFrames = duration / (1000 / frameRate);
    const increment = finalValue / totalFrames;
    let currentValue = startValue;
    let frame = 0;

    const animate = () => {
        frame++;
        currentValue += increment;

        if (frame <= totalFrames) {
            if (isCurrency) {
                element.innerText = `R$ ${currentValue.toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                })}`;
            } else {
                element.innerText = Math.floor(currentValue).toLocaleString('pt-BR');
            }
            requestAnimationFrame(animate);
        } else {
            if (isCurrency) {
                element.innerText = `R$ ${finalValue.toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                })}`;
            } else {
                element.innerText = Math.floor(finalValue).toLocaleString('pt-BR');
            }
        }
    };

    requestAnimationFrame(animate);
}

// Função para exportar para Excel
function exportarExcel() {
    // Cria uma tabela HTML com os dados
    let html = '<table border="1">';
    html += '<tr><th>Número</th><th>Cliente</th><th>UASG</th><th>Pregão</th><th>Quantidade</th><th>Valor Unitário</th><th>Valor Item</th><th>Valor Empenho</th><th>Data</th><th>Status</th><th>Descrição</th></tr>';
    
    empenhosData.forEach(empenho => {
        html += `<tr>
            <td>${empenho.numero}</td>
            <td>${empenho.cliente_nome}</td>
            <td>${empenho.cliente_uasg}</td>
            <td>${empenho.pregao || ''}</td>
            <td>${parseInt(empenho.quantidade).toLocaleString('pt-BR')}</td>
            <td>R$ ${parseFloat(empenho.valor_unitario).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
            <td>R$ ${parseFloat(empenho.valor_total_item).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
            <td>R$ ${parseFloat(empenho.valor_total_empenho).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
            <td>${new Date(empenho.data_empenho).toLocaleDateString('pt-BR')}</td>
            <td>${empenho.classificacao}</td>
            <td>${empenho.descricao_produto || ''}</td>
        </tr>`;
    });
    
    html += '</table>';
    
    // Cria um blob e faz o download
    const blob = new Blob(['\ufeff' + html], { type: 'application/vnd.ms-excel' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `empenhos_produto_${produtoData.nome}_${new Date().toISOString().split('T')[0]}.xls`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// Inicialização da página
document.addEventListener('DOMContentLoaded', function() {
    // Anima os números das estatísticas
    setTimeout(animateNumbers, 300);
    
    // Define visualização inicial como cards
    document.getElementById('empenhosCards').style.display = 'flex';
    const tableContainer = document.getElementById('empenhosTable');
    if (tableContainer) {
        tableContainer.style.display = 'none';
    }
    
    // Adiciona eventos de teclado para filtros
    document.addEventListener('keydown', function(e) {
        // Ctrl+F para focar na busca
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.focus();
            }
        }
        
        // ESC para limpar filtros
        if (e.key === 'Escape') {
            const modal = document.getElementById('empenhoModal');
            if (modal.style.display === 'block') {
                closeEmpenhoModal();
            } else {
                // Limpa filtros
                document.getElementById('filterClassificacao').value = '';
                document.getElementById('filterCliente').value = '';
                document.getElementById('filterDate').value = '';
                document.getElementById('searchInput').value = '';
                filterEmpenhos();
            }
        }
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
    
    console.log('Página de Empenhos do Produto carregada com sucesso!');
    console.log('Produto ID:', <?php echo $produto_id; ?>);
    console.log('Total de empenhos:', <?php echo $totalEmpenhos; ?>);
    console.log('Valor total:', <?php echo $totalValor; ?>);
});

// Fechar modal ao clicar fora dele
window.onclick = function(event) {
    const modal = document.getElementById('empenhoModal');
    if (event.target === modal) {
        closeEmpenhoModal();
    }
}

// Função para destacar termos de busca
function highlightSearchTerms(text, searchTerm) {
    if (!searchTerm) return text;
    
    const regex = new RegExp(`(${searchTerm})`, 'gi');
    return text.replace(regex, '<mark style="background-color: yellow; padding: 0.1rem 0.2rem; border-radius: 3px;">$1</mark>');
}

// Função para scroll suave até elemento
function scrollToElement(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.scrollIntoView({ 
            behavior: 'smooth',
            block: 'start'
        });
    }
}

// Toast notifications (função auxiliar)
function showToast(message, type = 'info') {
    // Esta função pode ser implementada para mostrar notificações
    console.log(`${type.toUpperCase()}: ${message}`);
}

// Função para atualizar estatísticas em tempo real
function updateStatsDisplay() {
    const visibleCards = document.querySelectorAll('.empenho-card:not([style*="display: none"])');
    const visibleCount = visibleCards.length;
    
    // Calcula estatísticas dos itens visíveis
    let visibleValorTotal = 0;
    let visibleQuantidadeTotal = 0;
    let visibleClientes = new Set();
    
    visibleCards.forEach(card => {
        const empenhoData = JSON.parse(card.dataset.empenho || '{}');
        if (empenhoData.valor_total_item) {
            visibleValorTotal += parseFloat(empenhoData.valor_total_item);
        }
        if (empenhoData.quantidade) {
            visibleQuantidadeTotal += parseInt(empenhoData.quantidade);
        }
        if (empenhoData.cliente_uasg) {
            visibleClientes.add(empenhoData.cliente_uasg);
        }
    });
    
    // Atualiza apenas se há filtros ativos
    const hasActiveFilters = document.getElementById('filterClassificacao').value ||
                            document.getElementById('filterCliente').value ||
                            document.getElementById('filterDate').value ||
                            document.getElementById('searchInput').value;
    
    if (hasActiveFilters && visibleCount !== <?php echo $totalEmpenhos; ?>) {
        console.log(`Filtrado: ${visibleCount} empenhos | Valor: R$ ${visibleValorTotal.toLocaleString('pt-BR', {minimumFractionDigits: 2})} | Quantidade: ${visibleQuantidadeTotal.toLocaleString('pt-BR')} | Clientes: ${visibleClientes.size}`);
    }
}

// Event listeners para os filtros
document.getElementById('filterClassificacao').addEventListener('change', updateStatsDisplay);
document.getElementById('filterCliente').addEventListener('change', updateStatsDisplay);
document.getElementById('filterDate').addEventListener('change', updateStatsDisplay);
document.getElementById('searchInput').addEventListener('input', updateStatsDisplay);
</script>

</body>
</html>