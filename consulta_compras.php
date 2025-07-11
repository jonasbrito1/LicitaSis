<?php
// ===========================================
// CONSULTA DE COMPRAS - LICITASIS (CÓDIGO COMPLETO COM DATA DE VENCIMENTO)
// Sistema Completo de Gestão de Licitações
// Versão: 8.0 Final com Data de Vencimento
// ===========================================

session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Conexão com o banco de dados
require_once('db.php');

// Verifica se $pdo foi definido corretamente
if (!isset($pdo)) {
    die("Erro: Conexão com o banco de dados não foi estabelecida. Verifique o arquivo db.php");
}

// Incluir permissões se existir
$permissionManager = null;
if (file_exists('permissions.php')) {
    include('permissions.php');
    if (function_exists('initPermissions')) {
        $permissionManager = initPermissions($pdo);
    }
}

// Definir a variável $isAdmin
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";
$compras = [];
$searchTerm = "";
$fornecedorFilter = "";

// Configuração da paginação
$itensPorPagina = 20;
$paginaAtual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($paginaAtual - 1) * $itensPorPagina;

// Função para converter data MySQL para formato brasileiro
function formatarDataBrasil($data) {
    if (empty($data) || $data === '0000-00-00') return '';
    
    $timestamp = strtotime($data);
    return $timestamp ? date('d/m/Y', $timestamp) : '';
}

// Função para converter data brasileira para formato MySQL
function converterDataParaMySQL($data) {
    if (empty($data)) return null;
    
    // Se já está no formato Y-m-d, retorna como está
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        return $data;
    }
    
    // Se está no formato d/m/Y, converte
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $data)) {
        $partes = explode('/', $data);
        return $partes[2] . '-' . $partes[1] . '-' . $partes[0];
    }
    
    return null;
}

// ===========================================
// PROCESSAMENTO AJAX - OBTER DADOS DA COMPRA
// ===========================================
if (isset($_GET['get_compra_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $id = filter_input(INPUT_GET, 'get_compra_id', FILTER_VALIDATE_INT);
        
        if (!$id || $id <= 0) {
            throw new Exception('ID da compra inválido');
        }

        // Consulta principal da compra COM DATA DE VENCIMENTO
        $sql = "SELECT 
                c.*,
                DATE_FORMAT(c.data, '%Y-%m-%d') as data_iso,
                DATE_FORMAT(c.data, '%d/%m/%Y') as data_formatada,
                DATE_FORMAT(c.data_pagamento_compra, '%Y-%m-%d') as data_pagamento_compra_iso,
                DATE_FORMAT(c.data_pagamento_compra, '%d/%m/%Y') as data_pagamento_compra_formatada,
                DATE_FORMAT(c.data_pagamento_frete, '%Y-%m-%d') as data_pagamento_frete_iso,
                DATE_FORMAT(c.data_pagamento_frete, '%d/%m/%Y') as data_pagamento_frete_formatada,
                DATE_FORMAT(c.data_vencimento, '%Y-%m-%d') as data_vencimento_iso,
                DATE_FORMAT(c.data_vencimento, '%d/%m/%Y') as data_vencimento_formatada,
                DATE_FORMAT(c.created_at, '%d/%m/%Y %H:%i') as data_cadastro_formatada
                FROM compras c 
                WHERE c.id = :id";
                
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $compra = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$compra) {
            throw new Exception('Compra não encontrada no banco de dados');
        }

        // Busca produtos relacionados à compra
        $produtos = [];
        try {
            $sql_produtos = "SELECT pc.*, p.nome as produto_nome, p.codigo as produto_codigo
                            FROM produto_compra pc 
                            LEFT JOIN produtos p ON pc.produto_id = p.id 
                            WHERE pc.compra_id = :compra_id
                            ORDER BY pc.id";
            $stmt_produtos = $pdo->prepare($sql_produtos);
            $stmt_produtos->bindParam(':compra_id', $id, PDO::PARAM_INT);
            $stmt_produtos->execute();
            $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Se a tabela não existir, produtos fica vazio
        }

        // Busca informações do fornecedor
        $fornecedor_info = null;
        if (!empty($compra['fornecedor'])) {
            try {
                $sql_fornecedor = "SELECT * FROM fornecedores WHERE nome LIKE :nome LIMIT 1";
                $stmt_fornecedor = $pdo->prepare($sql_fornecedor);
                $fornecedor_like = '%' . $compra['fornecedor'] . '%';
                $stmt_fornecedor->bindParam(':nome', $fornecedor_like);
                $stmt_fornecedor->execute();
                $fornecedor_info = $stmt_fornecedor->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Continua sem info do fornecedor se houver erro
            }
        }

        // Monta resposta completa COM DADOS DE VENCIMENTO
        $response = [
            'success' => true,
            'id' => (int)$compra['id'],
            'numero_nf' => $compra['numero_nf'] ?? '',
            'fornecedor' => $compra['fornecedor'] ?? '',
            'valor_total' => (float)($compra['valor_total'] ?? 0),
            'frete' => (float)($compra['frete'] ?? 0),
            'link_pagamento' => $compra['link_pagamento'] ?? '',
            'numero_empenho' => $compra['numero_empenho'] ?? '',
            'observacao' => $compra['observacao'] ?? '',
            'data' => $compra['data_iso'] ?? null,
            'data_formatada' => $compra['data_formatada'] ?? 'N/A',
            'data_pagamento_compra' => $compra['data_pagamento_compra_iso'] ?? null,
            'data_pagamento_compra_formatada' => $compra['data_pagamento_compra_formatada'] ?? 'N/A',
            'data_pagamento_frete' => $compra['data_pagamento_frete_iso'] ?? null,
            'data_pagamento_frete_formatada' => $compra['data_pagamento_frete_formatada'] ?? 'N/A',
            'data_vencimento' => $compra['data_vencimento_iso'] ?? null, // NOVO CAMPO
            'data_vencimento_formatada' => $compra['data_vencimento_formatada'] ?? 'N/A', // NOVO CAMPO
            'data_cadastro' => $compra['data_cadastro_formatada'] ?? 'N/A',
            'created_at' => $compra['created_at'] ?? null,
            'produtos' => $produtos,
            'fornecedor_info' => $fornecedor_info,
            'produto' => $compra['produto'] ?? '',
            'quantidade' => (int)($compra['quantidade'] ?? 0),
            'valor_unitario' => (float)($compra['valor_unitario'] ?? 0)
        ];
        
        echo json_encode($response);
        exit();
        
    } catch (Exception $e) {
        $error_response = [
            'success' => false,
            'error' => $e->getMessage()
        ];
        
        echo json_encode($error_response);
        exit();
    }
}

// ===========================================
// PROCESSAMENTO AJAX - ATUALIZAÇÃO DE COMPRA
// ===========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_compra'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false];
    
    try {
        $pdo->beginTransaction();
        
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            throw new Exception("ID da compra inválido.");
        }

        $dados = [
            'fornecedor' => trim(filter_input(INPUT_POST, 'fornecedor', FILTER_SANITIZE_STRING)),
            'numero_nf' => trim(filter_input(INPUT_POST, 'numero_nf', FILTER_SANITIZE_STRING)),
            'valor_total' => str_replace(',', '.', filter_input(INPUT_POST, 'valor_total')),
            'frete' => str_replace(',', '.', filter_input(INPUT_POST, 'frete')),
            'link_pagamento' => trim(filter_input(INPUT_POST, 'link_pagamento', FILTER_SANITIZE_URL)),
            'numero_empenho' => trim(filter_input(INPUT_POST, 'numero_empenho', FILTER_SANITIZE_STRING)),
            'observacao' => trim(filter_input(INPUT_POST, 'observacao', FILTER_SANITIZE_STRING)),
            'data' => filter_input(INPUT_POST, 'data', FILTER_SANITIZE_STRING),
            'data_pagamento_compra' => filter_input(INPUT_POST, 'data_pagamento_compra', FILTER_SANITIZE_STRING),
            'data_pagamento_frete' => filter_input(INPUT_POST, 'data_pagamento_frete', FILTER_SANITIZE_STRING),
            'data_vencimento' => filter_input(INPUT_POST, 'data_vencimento', FILTER_SANITIZE_STRING) // NOVO CAMPO
        ];

        // Converte datas para formato MySQL
        $dados['data'] = converterDataParaMySQL($dados['data']);
        $dados['data_pagamento_compra'] = converterDataParaMySQL($dados['data_pagamento_compra']);
        $dados['data_pagamento_frete'] = converterDataParaMySQL($dados['data_pagamento_frete']);
        $dados['data_vencimento'] = converterDataParaMySQL($dados['data_vencimento']); // NOVO CAMPO

        // Atualiza a compra COM DATA DE VENCIMENTO
        $sql = "UPDATE compras SET 
                fornecedor = :fornecedor, 
                numero_nf = :numero_nf, 
                valor_total = :valor_total, 
                frete = :frete, 
                link_pagamento = :link_pagamento, 
                numero_empenho = :numero_empenho, 
                observacao = :observacao, 
                data = :data,
                data_pagamento_compra = :data_pagamento_compra,
                data_pagamento_frete = :data_pagamento_frete,
                data_vencimento = :data_vencimento
                WHERE id = :id";

        $dados['id'] = $id;
        $stmt = $pdo->prepare($sql);
        
        if (!$stmt->execute($dados)) {
            throw new Exception("Erro ao atualizar a compra no banco de dados.");
        }

        $pdo->commit();
        $response['success'] = true;
        $response['message'] = "Compra atualizada com sucesso!";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $response['error'] = $e->getMessage();
    }

    echo json_encode($response);
    exit();
}

// ===========================================
// PROCESSAMENTO AJAX - EXCLUSÃO DE COMPRA
// ===========================================
if (isset($_POST['delete_compra_id'])) {
    header('Content-Type: application/json');
    
    $id = $_POST['delete_compra_id'];

    try {
        $pdo->beginTransaction();

        // Busca dados da compra para auditoria
        $stmt_compra = $pdo->prepare("SELECT * FROM compras WHERE id = :id");
        $stmt_compra->bindParam(':id', $id);
        $stmt_compra->execute();
        $compra_data = $stmt_compra->fetch(PDO::FETCH_ASSOC);

        if (!$compra_data) {
            throw new Exception("Compra não encontrada.");
        }

        // Exclui produtos da compra se a tabela existir
        try {
            $sql = "DELETE FROM produto_compra WHERE compra_id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Exception $e) {
            // Se a tabela não existir, continua
        }

        // Exclui a compra
        $sql = "DELETE FROM compras WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new Exception("Nenhuma compra foi excluída. Verifique se o ID está correto.");
        }

        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Compra excluída com sucesso!']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => 'Erro ao excluir a compra: ' . $e->getMessage()]);
    }
    exit();
}

// ===========================================
// CONSULTA PRINCIPAL COM FILTROS E PAGINAÇÃO
// ===========================================
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$fornecedorFilter = isset($_GET['fornecedor']) ? trim($_GET['fornecedor']) : '';

try {
    $params = [];
    $whereConditions = [];
    
    if (!empty($searchTerm)) {
        $whereConditions[] = "(c.numero_nf LIKE :searchTerm OR c.fornecedor LIKE :searchTerm OR c.produto LIKE :searchTerm OR c.numero_empenho LIKE :searchTerm)";
        $params[':searchTerm'] = "%$searchTerm%";
    }
    
    if (!empty($fornecedorFilter)) {
        $whereConditions[] = "c.fornecedor LIKE :fornecedor";
        $params[':fornecedor'] = "%$fornecedorFilter%";
    }
    
    $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

    // Consulta para contar total de registros
    $sqlCount = "SELECT COUNT(*) as total FROM compras c $whereClause";
    $stmtCount = $pdo->prepare($sqlCount);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $totalRegistros = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPaginas = ceil($totalRegistros / $itensPorPagina);

    // Consulta principal com paginação COM DATA DE VENCIMENTO
    $sql = "SELECT 
        c.id,
        c.numero_nf, 
        c.fornecedor, 
        c.valor_total, 
        c.frete,
        c.numero_empenho,
        c.data,
        c.data_vencimento,
        c.created_at,
        DATE_FORMAT(c.data, '%d/%m/%Y') as data_formatada,
        DATE_FORMAT(c.data_vencimento, '%d/%m/%Y') as data_vencimento_formatada,
        DATE_FORMAT(c.created_at, '%d/%m/%Y') as data_cadastro
    FROM compras c 
    $whereClause
    ORDER BY c.id DESC 
    LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $itensPorPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Erro na consulta do banco de dados: " . $e->getMessage();
    $compras = [];
    $totalRegistros = 0;
    $totalPaginas = 0;
}

// ===========================================
// CÁLCULO DE ESTATÍSTICAS
// ===========================================
try {
    // Valor total geral
    $sqlTotal = "SELECT SUM(valor_total) AS total_geral FROM compras";
    $stmtTotal = $pdo->prepare($sqlTotal);
    $stmtTotal->execute();
    $totalGeral = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total_geral'] ?? 0;
    
    // Compras recentes (últimos 30 dias)
    $sqlRecentes = "SELECT COUNT(*) as compras_recentes 
                   FROM compras 
                   WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $stmtRecentes = $pdo->prepare($sqlRecentes);
    $stmtRecentes->execute();
    $comprasRecentes = $stmtRecentes->fetch(PDO::FETCH_ASSOC)['compras_recentes'] ?? 0;
    
    // Total de fornecedores únicos
    $sqlFornecedores = "SELECT COUNT(DISTINCT fornecedor) as total_fornecedores 
                       FROM compras 
                       WHERE fornecedor IS NOT NULL AND fornecedor != ''";
    $stmtFornecedores = $pdo->prepare($sqlFornecedores);
    $stmtFornecedores->execute();
    $totalFornecedores = $stmtFornecedores->fetch(PDO::FETCH_ASSOC)['total_fornecedores'] ?? 0;

    // Compras com vencimento próximo (próximos 7 dias)
    $sqlVencimento = "SELECT COUNT(*) as compras_vencendo 
                     FROM compras 
                     WHERE data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                     AND data_vencimento IS NOT NULL";
    $stmtVencimento = $pdo->prepare($sqlVencimento);
    $stmtVencimento->execute();
    $comprasVencendo = $stmtVencimento->fetch(PDO::FETCH_ASSOC)['compras_vencendo'] ?? 0;
    
} catch (PDOException $e) {
    $error = "Erro ao calcular estatísticas: " . $e->getMessage();
    $totalGeral = 0;
    $comprasRecentes = 0;
    $totalFornecedores = 0;
    $comprasVencendo = 0;
}

// Buscar fornecedores para filtros
$fornecedores = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT fornecedor FROM compras WHERE fornecedor IS NOT NULL AND fornecedor != '' ORDER BY fornecedor ASC");
    $fornecedores = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $fornecedores = [];
}

// Inclui o header do sistema se existir
if (file_exists('includes/header_template.php')) {
    include('includes/header_template.php');
    renderHeader("Consulta de Compras - LicitaSis", "compras");
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Compras - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
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
            padding: 1rem;
            background: white;
            border-radius: var(--radius-sm);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
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

        .stat-item:hover .stat-icon {
            opacity: 0.8;
            transform: scale(1.2);
        }

        .stat-item.urgent {
            border-left: 4px solid var(--danger-color);
        }

        .stat-item.urgent .stat-number {
            color: var(--danger-color);
        }

        .total-geral {
            text-align: right;
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            border-radius: var(--radius);
            border-left: 4px solid var(--secondary-color);
            box-shadow: 0 2px 8px rgba(0, 191, 174, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .total-geral i {
            color: var(--secondary-color);
            font-size: 1.5rem;
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
            grid-template-columns: 1fr auto auto auto;
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

        /* ===========================================
           ELEMENTOS ESPECÍFICOS DA TABELA
           =========================================== */
        .numero-nf {
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

        .numero-nf:hover {
            color: var(--primary-color);
            background: rgba(45, 137, 62, 0.1);
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0, 191, 174, 0.2);
        }

        .numero-nf i {
            font-size: 0.8rem;
        }

        .vencimento-status {
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            font-weight: 600;
            text-align: center;
        }

        .vencimento-em-dia {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
        }

        .vencimento-proximo {
            background: rgba(255, 193, 7, 0.1);
            color: #e0a800;
        }

        .vencimento-vencido {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
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
        .compra-details {
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

        .detail-value.money {
            color: var(--success-color);
            font-weight: 700;
            font-size: 1.1rem;
        }

        .detail-value.vencimento {
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            text-align: center;
        }

        .detail-value.vencimento.em-dia {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
        }

        .detail-value.vencimento.proximo {
            background: rgba(255, 193, 7, 0.1);
            color: #e0a800;
        }

        .detail-value.vencimento.vencido {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
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

        select.form-control {
            cursor: pointer;
        }

        /* ===========================================
           UTILITÁRIOS
           =========================================== */
        .arquivo-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--secondary-color);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border: 1px solid var(--secondary-color);
            border-radius: var(--radius-sm);
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .arquivo-link:hover {
            background: var(--secondary-color);
            color: white;
            transform: translateY(-1px);
        }

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
            border-top-color: var(--secondary-color);
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

            .total-geral {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
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

            .numero-nf {
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
        <i class="fas fa-shopping-cart"></i>
        Consulta de Compras
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
         ESTATÍSTICAS ATUALIZADAS
         =========================================== -->
    <div class="stats-container">
        <div class="stat-item">
            <div class="stat-number"><?php echo count($compras); ?></div>
            <div class="stat-label">Compras Listadas</div>
            <div class="stat-icon">
                <i class="fas fa-list" style="color: var(--secondary-color);"></i>
            </div>
        </div>
        
        <div class="stat-item">
            <div class="stat-number"><?php echo $comprasRecentes; ?></div>
            <div class="stat-label">Compras Recentes (30 dias)</div>
            <div class="stat-icon">
                <i class="fas fa-calendar" style="color: var(--info-color);"></i>
            </div>
        </div>
        
        <div class="stat-item">
            <div class="stat-number"><?php echo $totalFornecedores; ?></div>
            <div class="stat-label">Fornecedores Únicos</div>
            <div class="stat-icon">
                <i class="fas fa-building" style="color: var(--warning-color);"></i>
            </div>
        </div>

        <!-- NOVA ESTATÍSTICA - Compras com vencimento próximo -->
        <div class="stat-item <?php echo $comprasVencendo > 0 ? 'urgent' : ''; ?>">
            <div class="stat-number"><?php echo $comprasVencendo ?? 0; ?></div>
            <div class="stat-label">Vencendo em 7 dias</div>
            <div class="stat-icon">
                <i class="fas fa-clock" style="color: <?php echo $comprasVencendo > 0 ? 'var(--danger-color)' : 'var(--success-color)'; ?>;"></i>
            </div>
        </div>
        
        <div class="stat-item">
            <div class="stat-number"><?php echo $totalRegistros ?? 0; ?></div>
            <div class="stat-label">Total de Compras</div>
            <div class="stat-icon">
                <i class="fas fa-shopping-bag" style="color: var(--primary-color);"></i>
            </div>
        </div>
    </div>

    <!-- ===========================================
         VALOR TOTAL GERAL
         =========================================== -->
    <?php if (isset($totalGeral)): ?>
        <div class="total-geral">
            <div>
                <i class="fas fa-calculator"></i>
                <span>Valor Total Geral de Compras</span>
            </div>
            <strong>R$ <?php echo number_format($totalGeral, 2, ',', '.'); ?></strong>
        </div>
    <?php endif; ?>

    <!-- ===========================================
         FILTROS
         =========================================== -->
    <div class="filters-container">
        <form action="consulta_compras.php" method="GET" id="filtersForm">
            <div class="filters-row">
                <div class="search-group">
                    <label for="search">Buscar por:</label>
                    <input type="text" 
                           name="search" 
                           id="search" 
                           class="search-input"
                           placeholder="NF, fornecedor, produto ou empenho..." 
                           value="<?php echo htmlspecialchars($searchTerm ?? ''); ?>"
                           autocomplete="off">
                </div>
                
                <!-- Filtro por fornecedor -->
                <?php if (!empty($fornecedores)): ?>
                <div class="search-group">
                    <label for="fornecedor">Fornecedor:</label>
                    <select name="fornecedor" id="fornecedor" class="filter-select">
                        <option value="">Todos os fornecedores</option>
                        <?php foreach ($fornecedores as $fornecedor): ?>
                            <option value="<?php echo htmlspecialchars($fornecedor); ?>" 
                                    <?php echo $fornecedorFilter === $fornecedor ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($fornecedor); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
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

    <!-- ===========================================
         TABELA DE COMPRAS COM DATA DE VENCIMENTO
         =========================================== -->
    <?php if (count($compras) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th><i class="fas fa-file-invoice"></i> Número NF</th>
                        <th><i class="fas fa-building"></i> Fornecedor</th>
                        <th><i class="fas fa-dollar-sign"></i> Valor Total</th>
                        <th><i class="fas fa-truck"></i> Frete</th>
                        <th><i class="fas fa-hashtag"></i> N° Empenho</th>
                        <th><i class="fas fa-calendar"></i> Data</th>
                        <th><i class="fas fa-clock"></i> Vencimento</th>
                        <th><i class="fas fa-calendar-plus"></i> Cadastro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($compras as $compra): ?>
                        <?php
                        // Determina status do vencimento
                        $statusVencimento = '';
                        $classVencimento = '';
                        if (!empty($compra['data_vencimento']) && $compra['data_vencimento'] !== '0000-00-00') {
                            $hoje = new DateTime();
                            $vencimento = new DateTime($compra['data_vencimento']);
                            $diferenca = $hoje->diff($vencimento);
                            
                            if ($vencimento < $hoje) {
                                $statusVencimento = 'Vencido';
                                $classVencimento = 'vencimento-vencido';
                            } elseif ($diferenca->days <= 7) {
                                $statusVencimento = 'Próximo';
                                $classVencimento = 'vencimento-proximo';
                            } else {
                                $statusVencimento = 'Em dia';
                                $classVencimento = 'vencimento-em-dia';
                            }
                        }
                        ?>
                        <tr>
                            <td>
                                <span class="numero-nf" 
                                      data-compra-id="<?php echo $compra['id']; ?>"
                                      title="Clique para ver detalhes da compra">
                                    <i class="fas fa-eye"></i>
                                    <?php echo htmlspecialchars($compra['numero_nf'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($compra['fornecedor'] ?? 'N/A'); ?></td>
                            <td>
                                <strong style="color: var(--success-color);">
                                    R$ <?php echo number_format($compra['valor_total'] ?? 0, 2, ',', '.'); ?>
                                </strong>
                            </td>
                            <td>
                                R$ <?php echo number_format($compra['frete'] ?? 0, 2, ',', '.'); ?>
                            </td>
                            <td><?php echo htmlspecialchars($compra['numero_empenho'] ?? 'N/A'); ?></td>
                            <td>
                                <strong><?php echo $compra['data_formatada'] ?? 'N/A'; ?></strong>
                            </td>
                            <td>
                                <?php if (!empty($compra['data_vencimento_formatada']) && $compra['data_vencimento_formatada'] !== 'N/A'): ?>
                                    <div>
                                        <strong><?php echo $compra['data_vencimento_formatada']; ?></strong>
                                        <?php if ($statusVencimento): ?>
                                            <div class="vencimento-status <?php echo $classVencimento; ?>">
                                                <?php echo $statusVencimento; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--medium-gray); font-style: italic;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $compra['data_cadastro'] ?? 'N/A'; ?></td>
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
                <?php echo $totalRegistros; ?> compras
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
            <p>Nenhuma compra encontrada.</p>
            <small>Tente ajustar os filtros ou cadastre uma nova compra.</small>
        </div>
    <?php endif; ?>
</div>

<!-- ===========================================
     MODAL DE DETALHES DA COMPRA
     =========================================== -->
<div id="compraModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-shopping-cart"></i> 
                Detalhes da Compra
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
            <button class="btn btn-warning" onclick="editarCompra()" id="editarBtn">
                <i class="fas fa-edit"></i> Editar
            </button>
            <button class="btn btn-danger" onclick="confirmarExclusao()" id="excluirBtn">
                <i class="fas fa-trash"></i> Excluir
            </button>
            <button class="btn btn-primary" onclick="imprimirCompra()">
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
// SISTEMA COMPLETO DE CONSULTA DE COMPRAS COM DATA DE VENCIMENTO
// JavaScript Completo - LicitaSis v8.0 FINAL
// ===========================================

// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let currentCompraId = null;
let currentCompraData = null;
let isEditingCompra = false;

// ===========================================
// FUNÇÕES DE CONTROLE DO MODAL
// ===========================================

/**
 * Abre o modal com detalhes da compra
 * @param {number} compraId - ID da compra
 */
function openModal(compraId) {
    console.log('🔍 Abrindo modal para compra ID:', compraId);
    
    if (!compraId || isNaN(compraId)) {
        console.error('❌ ID da compra inválido:', compraId);
        showToast('Erro: ID da compra inválido', 'error');
        return;
    }
    
    currentCompraId = compraId;
    const modal = document.getElementById('compraModal');
    const modalBody = document.getElementById('modalBody');
    const modalFooter = document.getElementById('modalFooter');
    
    if (!modal) {
        console.error('❌ Modal não encontrado no DOM');
        showToast('Erro: Modal não encontrado', 'error');
        return;
    }
    
    // Mostra o modal
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Mostra loading
    modalBody.innerHTML = `
        <div class="loading-spinner" style="text-align: center; padding: 3rem;">
            <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
            <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da compra...</p>
        </div>
    `;
    modalFooter.style.display = 'none';
    
    // Busca dados da compra
    const url = `?get_compra_id=${compraId}&t=${Date.now()}`;
    console.log('📡 Fazendo requisição para:', url);
    
    fetch(url, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/json'
        },
        cache: 'no-cache'
    })
        .then(response => {
            console.log('📡 Resposta recebida:', response.status, response.statusText);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return response.json();
        })
        .then(data => {
            console.log('✅ Dados da compra recebidos:', data);
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            if (!data.id) {
                throw new Error('Dados da compra incompletos');
            }
            
            currentCompraData = data;
            renderCompraDetails(data);
            modalFooter.style.display = 'flex';
            
            console.log('✅ Modal renderizado com sucesso para compra:', data.numero_nf);
        })
        .catch(error => {
            console.error('❌ Erro ao carregar compra:', error);
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 3rem; color: var(--danger-color);">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                    <p style="font-size: 1.1rem; margin-bottom: 1rem;">Erro ao carregar compra</p>
                    <p style="color: var(--medium-gray); margin-bottom: 1.5rem;">${error.message}</p>
                    <div>
                        <button class="btn btn-warning" onclick="openModal(${compraId})" style="margin: 0.5rem;">
                            <i class="fas fa-redo"></i> Tentar Novamente
                        </button>
                        <button class="btn btn-secondary" onclick="closeModal()" style="margin: 0.5rem;">
                            <i class="fas fa-times"></i> Fechar
                        </button>
                    </div>
                </div>
            `;
            showToast('Erro ao carregar dados da compra', 'error');
        });
}

/**
 * Renderiza os detalhes completos da compra no modal COM DATA DE VENCIMENTO
 * @param {Object} compra - Dados da compra
 */
function renderCompraDetails(compra) {
    console.log('🎨 Renderizando detalhes da compra:', compra);
    
    const modalBody = document.getElementById('modalBody');
    
    const dataFormatada = compra.data_cadastro || 'N/A';
    const dataCompra = compra.data || '';
    const dataCompraDisplay = compra.data_formatada || 'N/A';
    const dataPagamentoCompra = compra.data_pagamento_compra || '';
    const dataPagamentoCompraDisplay = compra.data_pagamento_compra_formatada || 'N/A';
    const dataPagamentoFrete = compra.data_pagamento_frete || '';
    const dataPagamentoFreteDisplay = compra.data_pagamento_frete_formatada || 'N/A';
    const dataVencimento = compra.data_vencimento || ''; // NOVO CAMPO
    const dataVencimentoDisplay = compra.data_vencimento_formatada || 'N/A'; // NOVO CAMPO

    // Determina status do vencimento
    let statusVencimento = '';
    let classVencimento = '';
    let textoVencimento = dataVencimentoDisplay;
    
    if (dataVencimento && dataVencimento !== '0000-00-00') {
        const hoje = new Date();
        const vencimento = new Date(dataVencimento);
        const diferenca = Math.ceil((vencimento - hoje) / (1000 * 60 * 60 * 24));
        
        if (diferenca < 0) {
            statusVencimento = `Vencido há ${Math.abs(diferenca)} dia(s)`;
            classVencimento = 'vencido';
        } else if (diferenca <= 7) {
            statusVencimento = diferenca === 0 ? 'Vence hoje!' : `Vence em ${diferenca} dia(s)`;
            classVencimento = 'proximo';
        } else {
            statusVencimento = 'Em dia';
            classVencimento = 'em-dia';
        }
        
        textoVencimento = `${dataVencimentoDisplay} - ${statusVencimento}`;
    }

    modalBody.innerHTML = `
        <div class="compra-details">
            <!-- Formulário de Edição (inicialmente oculto) -->
            <form id="compraEditForm" style="display: none;">
                <input type="hidden" name="id" value="${compra.id}">
                <input type="hidden" name="update_compra" value="1">
                
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-edit"></i>
                        Editar Informações da Compra
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Número da NF *</div>
                                <input type="text" name="numero_nf" class="form-control" value="${compra.numero_nf || ''}" required>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Fornecedor *</div>
                                <input type="text" name="fornecedor" class="form-control" value="${compra.fornecedor || ''}" required>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data da Compra</div>
                                <input type="date" name="data" class="form-control" value="${dataCompra}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Número do Empenho</div>
                                <input type="text" name="numero_empenho" class="form-control" value="${compra.numero_empenho || ''}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Valor Total</div>
                                <input type="number" name="valor_total" class="form-control" step="0.01" min="0" value="${compra.valor_total || ''}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Frete</div>
                                <input type="number" name="frete" class="form-control" step="0.01" min="0" value="${compra.frete || ''}">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- NOVA SEÇÃO DE DATAS -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-calendar-alt"></i>
                        Datas de Pagamento e Vencimento
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Data de Pagamento da Compra</div>
                                <input type="date" name="data_pagamento_compra" class="form-control" value="${dataPagamentoCompra}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data de Pagamento do Frete</div>
                                <input type="date" name="data_pagamento_frete" class="form-control" value="${dataPagamentoFrete}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data de Vencimento</div>
                                <input type="date" name="data_vencimento" class="form-control" value="${dataVencimento}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-link"></i>
                        Informações Adicionais
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Link para Pagamento</div>
                                <input type="url" name="link_pagamento" class="form-control" value="${compra.link_pagamento || ''}">
                            </div>
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Observações</div>
                                <textarea name="observacao" class="form-control" rows="4">${compra.observacao || ''}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 2px solid var(--border-color); display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-success" id="salvarBtn">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="cancelarEdicao()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-danger" onclick="confirmarExclusaoEdicao()">
                        <i class="fas fa-trash"></i> Excluir Compra
                    </button>
                </div>
            </form>

            <!-- Visualização Normal (inicialmente visível) -->
            <div id="compraViewMode">
                <!-- Informações Básicas -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-info-circle"></i>
                        Informações Básicas
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Número da NF</div>
                                <div class="detail-value highlight">${compra.numero_nf || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Fornecedor</div>
                                <div class="detail-value highlight">${compra.fornecedor || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data da Compra</div>
                                <div class="detail-value">${dataCompraDisplay}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Número do Empenho</div>
                                <div class="detail-value">${compra.numero_empenho || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data de Cadastro</div>
                                <div class="detail-value">${dataFormatada}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- NOVA SEÇÃO DE DATAS -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-calendar-alt"></i>
                        Datas de Pagamento e Vencimento
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Data de Pagamento da Compra</div>
                                <div class="detail-value">${dataPagamentoCompraDisplay}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data de Pagamento do Frete</div>
                                <div class="detail-value">${dataPagamentoFreteDisplay}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data de Vencimento</div>
                                <div class="detail-value vencimento ${classVencimento}">${textoVencimento}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Valores Financeiros -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-dollar-sign"></i>
                        Valores Financeiros
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Valor Total</div>
                                <div class="detail-value money">R$ ${parseFloat(compra.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Frete</div>
                                <div class="detail-value">R$ ${parseFloat(compra.frete || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Total com Frete</div>
                                <div class="detail-value money">R$ ${(parseFloat(compra.valor_total || 0) + parseFloat(compra.frete || 0)).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Produtos da Compra -->
                ${compra.produtos && compra.produtos.length > 0 ? `
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-shopping-cart"></i>
                        Produtos da Compra (${compra.produtos.length})
                    </div>
                    <div class="detail-content">
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: var(--secondary-color); color: white;">
                                        <th style="padding: 0.75rem; text-align: left;">Produto</th>
                                        <th style="padding: 0.75rem; text-align: center;">Qtd</th>
                                        <th style="padding: 0.75rem; text-align: right;">Valor Unit.</th>
                                        <th style="padding: 0.75rem; text-align: right;">Valor Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${compra.produtos.map(produto => `
                                        <tr style="border-bottom: 1px solid var(--border-color);">
                                            <td style="padding: 0.75rem;">
                                                <strong>${produto.produto_nome || 'Produto sem nome'}</strong>
                                                ${produto.produto_codigo ? `<br><small style="color: var(--medium-gray);">Código: ${produto.produto_codigo}</small>` : ''}
                                            </td>
                                            <td style="padding: 0.75rem; text-align: center;">${produto.quantidade || 0}</td>
                                            <td style="padding: 0.75rem; text-align: right;">R$ ${parseFloat(produto.valor_unitario || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                                            <td style="padding: 0.75rem; text-align: right; font-weight: 600; color: var(--primary-color);">R$ ${parseFloat(produto.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Produto direto da tabela compras -->
                ${(!compra.produtos || compra.produtos.length === 0) && compra.produto ? `
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-shopping-cart"></i>
                        Produto da Compra
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Produto</div>
                                <div class="detail-value">${compra.produto}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Quantidade</div>
                                <div class="detail-value">${compra.quantidade || 0}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Valor Unitário</div>
                                <div class="detail-value">R$ ${parseFloat(compra.valor_unitario || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                            </div>
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Informações do Fornecedor -->
                ${compra.fornecedor_info ? `
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-building"></i>
                        Informações do Fornecedor
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Endereço</div>
                                <div class="detail-value">${compra.fornecedor_info.endereco || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Telefone</div>
                                <div class="detail-value">${compra.fornecedor_info.telefone || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Email</div>
                                <div class="detail-value">${compra.fornecedor_info.email || 'N/A'}</div>
                            </div>
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Observações e Links -->
                ${compra.observacao || compra.link_pagamento ? `
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-file-alt"></i>
                        Observações e Links
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            ${compra.observacao ? `
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Observações</div>
                                <div class="detail-value">${compra.observacao}</div>
                            </div>
                            ` : ''}
                            ${compra.link_pagamento ? `
                            <div class="detail-item">
                                <div class="detail-label">Link para Pagamento</div>
                                <div class="detail-value">
                                    <a href="${compra.link_pagamento}" target="_blank" class="arquivo-link">
                                        <i class="fas fa-external-link-alt"></i>
                                        Acessar Link
                                    </a>
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                </div>
                ` : ''}
            </div>
        </div>
    `;

    // Adiciona event listener para o formulário de edição
    const editForm = document.getElementById('compraEditForm');
    if (editForm) {
        editForm.addEventListener('submit', salvarEdicaoCompra);
    }
    
    console.log('✅ Detalhes da compra renderizados com sucesso');
}

/**
 * Fecha o modal
 */
function closeModal() {
    if (isEditingCompra) {
        const confirmClose = confirm(
            'Você está editando a compra.\n\n' +
            'Tem certeza que deseja fechar sem salvar as alterações?\n\n' +
            'As alterações não salvas serão perdidas.'
        );
        
        if (!confirmClose) {
            return;
        }
    }
    
    const modal = document.getElementById('compraModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    currentCompraId = null;
    currentCompraData = null;
    isEditingCompra = false;
    
    console.log('✅ Modal fechado');
}

// ===========================================
// FUNÇÕES DE EDIÇÃO DA COMPRA
// ===========================================

/**
 * Ativa o modo de edição da compra
 */
function editarCompra() {
    console.log('🖊️ Ativando modo de edição da compra');
    
    const viewMode = document.getElementById('compraViewMode');
    const editForm = document.getElementById('compraEditForm');
    const editarBtn = document.getElementById('editarBtn');
    
    if (viewMode) viewMode.style.display = 'none';
    if (editForm) editForm.style.display = 'block';
    if (editarBtn) editarBtn.style.display = 'none';
    
    isEditingCompra = true;
    
    showToast('Modo de edição ativado', 'info');
}

/**
 * Cancela a edição da compra
 */
function cancelarEdicao() {
    const confirmCancel = confirm(
        'Tem certeza que deseja cancelar a edição?\n\n' +
        'Todas as alterações não salvas serão perdidas.'
    );
    
    if (confirmCancel) {
        const viewMode = document.getElementById('compraViewMode');
        const editForm = document.getElementById('compraEditForm');
        const editarBtn = document.getElementById('editarBtn');
        
        if (viewMode) viewMode.style.display = 'block';
        if (editForm) editForm.style.display = 'none';
        if (editarBtn) editarBtn.style.display = 'inline-flex';
        
        isEditingCompra = false;
        
        showToast('Edição cancelada', 'info');
    }
}

/**
 * Salva a edição da compra
 */
function salvarEdicaoCompra(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    const submitBtn = document.getElementById('salvarBtn');
    
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    }
    
    fetch('?', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Compra atualizada com sucesso!', 'success');
            
            setTimeout(() => {
                openModal(currentCompraId);
            }, 1000);
            
        } else {
            throw new Error(data.error || 'Erro ao salvar compra');
        }
    })
    .catch(error => {
        console.error('Erro ao salvar compra:', error);
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
    if (!currentCompraData) return;
    
    const confirmMessage = 
        `⚠️ ATENÇÃO: EXCLUSÃO PERMANENTE ⚠️\n\n` +
        `Tem certeza que deseja EXCLUIR permanentemente esta compra?\n\n` +
        `NF: ${currentCompraData.numero_nf || 'N/A'}\n` +
        `Fornecedor: ${currentCompraData.fornecedor || 'N/A'}\n` +
        `Valor: R$ ${parseFloat(currentCompraData.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}\n\n` +
        `⚠️ Esta ação NÃO PODE ser desfeita!\n\n` +
        `Digite "CONFIRMAR" para prosseguir:`;
    
    const confirmacao = prompt(confirmMessage);
    
    if (confirmacao === 'CONFIRMAR') {
        excluirCompra();
    } else if (confirmacao !== null) {
        showToast('Exclusão cancelada - confirmação incorreta', 'warning');
    }
}

/**
 * Exclui compra
 */
function excluirCompra() {
    if (!currentCompraId) return;
    
    const excluirBtn = document.getElementById('excluirBtn');
    if (excluirBtn) {
        excluirBtn.disabled = true;
        excluirBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';
    }
    
    const formData = new FormData();
    formData.append('delete_compra_id', currentCompraId);
    
    fetch('?', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Compra excluída com sucesso!', 'success');
            
            closeModal();
            
            setTimeout(() => {
                window.location.reload();
            }, 1500);
            
        } else {
            throw new Error(data.error || 'Erro ao excluir compra');
        }
    })
    .catch(error => {
        console.error('Erro ao excluir compra:', error);
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
    if (!currentCompraData) return;
    
    const confirmMessage = 
        `⚠️ ATENÇÃO: EXCLUSÃO PERMANENTE ⚠️\n\n` +
        `Tem certeza que deseja EXCLUIR permanentemente esta compra?\n\n` +
        `NF: ${currentCompraData.numero_nf || 'N/A'}\n` +
        `Fornecedor: ${currentCompraData.fornecedor || 'N/A'}\n` +
        `Valor: R$ ${parseFloat(currentCompraData.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}\n\n` +
        `⚠️ Esta ação NÃO PODE ser desfeita!\n\n` +
        `Digite "CONFIRMAR" para prosseguir:`;
    
    const confirmacao = prompt(confirmMessage);
    
    if (confirmacao === 'CONFIRMAR') {
        excluirCompra();
    } else if (confirmacao !== null) {
        showToast('Exclusão cancelada - confirmação incorreta', 'warning');
    }
}

/**
 * Imprime compra
 */
function imprimirCompra() {
    if (!currentCompraId) return;
    
    const printUrl = `imprimir_compra.php?id=${currentCompraId}`;
    window.open(printUrl, '_blank', 'width=800,height=600');
}

// ===========================================
// FUNÇÕES DE FILTROS
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

// ===========================================
// UTILITÁRIOS
// ===========================================

/**
 * Sistema de notificações toast
 */
function showToast(message, type = 'info', duration = 4000) {
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
    
    setTimeout(() => {
        toast.style.transform = 'translateX(0)';
    }, 100);
    
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

/**
 * Debounce para otimizar pesquisas
 */
function debounce(func, delay) {
    let timeoutId;
    return function (...args) {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => func.apply(this, args), delay);
    };
}

// ===========================================
// INICIALIZAÇÃO E EVENT LISTENERS
// ===========================================

/**
 * Inicialização quando a página carrega
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 LicitaSis - Sistema de Consulta de Compras com Data de Vencimento carregado');
    
    // Configura event listeners nos links de NF
    const numeroNFs = document.querySelectorAll('.numero-nf');
    console.log('📋 Links de NF encontrados:', numeroNFs.length);
    
    numeroNFs.forEach((link, index) => {
        const compraId = link.getAttribute('data-compra-id');
        console.log(`🔗 Configurando listener para compra ID: ${compraId}`);
        
        link.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('👆 Click capturado via addEventListener');
            const id = this.getAttribute('data-compra-id');
            console.log('🆔 Compra ID:', id);
            
            if (id && !isNaN(id)) {
                openModal(parseInt(id));
            } else {
                console.error('❌ ID inválido:', id);
                showToast('Erro: ID da compra inválido', 'error');
            }
        });
        
        link.style.cursor = 'pointer';
        link.title = `Clique para ver detalhes da compra ID: ${compraId}`;
    });
    
    // Event listener para fechar modal com clique fora
    const modal = document.getElementById('compraModal');
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
        const debouncedSearch = debounce(() => {
            const form = document.getElementById('filtersForm');
            if (form) form.submit();
        }, 800);
        
        searchInput.addEventListener('input', debouncedSearch);
    }
    
    // Event listener para select de fornecedor
    const fornecedorSelect = document.getElementById('fornecedor');
    if (fornecedorSelect) {
        fornecedorSelect.addEventListener('change', function() {
            const form = document.getElementById('filtersForm');
            if (form) form.submit();
        });
    }
    
    // Event listener para ESC fechar modal
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const modal = document.getElementById('compraModal');
            if (modal && modal.style.display === 'block') {
                if (isEditingCompra) {
                    cancelarEdicao();
                } else {
                    closeModal();
                }
            }
        }
    });
    
    // Torna as funções disponíveis globalmente
    window.openModal = openModal;
    window.closeModal = closeModal;
    window.editarCompra = editarCompra;
    window.excluirCompra = excluirCompra;
    window.confirmarExclusao = confirmarExclusao;
    window.imprimirCompra = imprimirCompra;
    window.limparFiltros = limparFiltros;
    window.showToast = showToast;
    window.cancelarEdicao = cancelarEdicao;
    window.confirmarExclusaoEdicao = confirmarExclusaoEdicao;
    
    console.log('✅ Todas as funções expostas globalmente');
    console.log('✅ Todos os event listeners configurados');
    console.log('🎯 Sistema pronto para uso!');
    
    // Teste final das funções principais
    setTimeout(() => {
        console.log('🧪 TESTE FINAL - Verificando funções:');
        console.log('- openModal:', typeof openModal);
        console.log('- closeModal:', typeof closeModal);
        console.log('- Modal existe:', !!document.getElementById('compraModal'));
        
        // Conta quantos links de NF existem
        const links = document.querySelectorAll('.numero-nf');
        console.log(`📊 Total de ${links.length} links de compras encontrados`);
        
        if (links.length > 0) {
            console.log('✅ Sistema pronto para uso!');
            console.log('💡 Para testar, clique em qualquer número de NF na tabela');
        } else {
            console.log('⚠️ Nenhum link de compra encontrado - verifique se há dados na tabela');
        }
    }, 1000);
});

/**
 * Cleanup quando a página é descarregada
 */
window.addEventListener('beforeunload', function(event) {
    if (isEditingCompra) {
        const message = 'Você tem alterações não salvas. Tem certeza que deseja sair?';
        event.returnValue = message;
        return message;
    }
});

// ===========================================
// FUNÇÃO DE TESTE PARA DEBUG
// ===========================================

/**
 * Função de teste para verificar se o modal funciona
 */
function testeModal() {
    console.log('🧪 Testando modal...');
    const modal = document.getElementById('compraModal');
    const modalBody = document.getElementById('modalBody');
    
    if (modal && modalBody) {
        modal.style.display = 'block';
        modalBody.innerHTML = `
            <div style="text-align: center; padding: 3rem;">
                <h3 style="color: var(--success-color);">✅ TESTE DO MODAL</h3>
                <p>Modal funcionando perfeitamente!</p>
                <p style="margin-top: 1rem; color: var(--medium-gray);">
                    O sistema está configurado corretamente.
                </p>
                <button onclick="closeModal()" style="margin-top: 1.5rem; padding: 0.75rem 1.5rem; background: var(--primary-color); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">
                    <i class="fas fa-times"></i> Fechar Teste
                </button>
            </div>
        `;
        document.getElementById('modalFooter').style.display = 'none';
        console.log('✅ Modal de teste aberto com sucesso');
        showToast('Modal testado com sucesso!', 'success');
    } else {
        console.error('❌ Modal não encontrado');
        alert('❌ Modal não encontrado no DOM');
    }
}

/**
 * Função para testar AJAX
 */
function testeAjax() {
    console.log('🧪 Testando AJAX...');
    
    const testUrl = `?get_compra_id=999999&debug=1&t=${Date.now()}`;
    
    fetch(testUrl)
        .then(response => {
            console.log('📡 Status da resposta:', response.status);
            return response.text();
        })
        .then(data => {
            console.log('📄 Resposta recebida:', data.substring(0, 200) + '...');
            
            try {
                const json = JSON.parse(data);
                console.log('✅ JSON válido:', json);
                showToast('✅ AJAX funcionando - resposta JSON válida', 'success');
            } catch (e) {
                console.log('⚠️ Resposta não é JSON (normal para ID inexistente)');
                showToast('⚠️ AJAX funcionando mas ID de teste não existe (normal)', 'warning');
            }
        })
        .catch(error => {
            console.error('❌ Erro no AJAX:', error);
            showToast('❌ Erro no AJAX: ' + error.message, 'error');
        });
}

/**
 * Exporta funções para o escopo global
 */
window.LicitaSisCompras = {
    openModal,
    closeModal,
    editarCompra,
    excluirCompra,
    showToast,
    limparFiltros,
    testeModal,
    testeAjax
};

// Torna as funções de teste disponíveis globalmente
window.testeModal = testeModal;
window.testeAjax = testeAjax;

console.log('🎉 Sistema LicitaSis - Consulta de Compras v8.0 FINAL com Data de Vencimento carregado com sucesso!');
console.log('🔧 Para debug: digite "testeModal()" ou "testeAjax()" no console');
console.log('📅 Nova funcionalidade: Data de Vencimento implementada com sucesso!');
</script>

<!-- ===========================================
     INFORMAÇÕES DE DEBUG (REMOVER EM PRODUÇÃO)
     =========================================== -->
<!-- 
SISTEMA DE CONSULTA DE COMPRAS - LICITASIS
Versão: 8.0 Final com Data de Vencimento
Data: <?php echo date('Y-m-d H:i:s'); ?>

STATUS DO SISTEMA:
- PHP Version: <?php echo PHP_VERSION; ?>
- PDO Disponível: <?php echo class_exists('PDO') ? 'SIM' : 'NÃO'; ?>
- Conexão PDO: <?php echo isset($pdo) && $pdo ? 'ATIVA' : 'INATIVA'; ?>
- Total de Compras: <?php echo count($compras); ?>
- Registros Totais: <?php echo $totalRegistros ?? 0; ?>
- Compras Vencendo: <?php echo $comprasVencendo ?? 0; ?>

FUNCIONALIDADES IMPLEMENTADAS:
✅ Modal responsivo completo
✅ Sistema AJAX para buscar/editar/excluir
✅ Paginação avançada
✅ Filtros dinâmicos
✅ Data de Vencimento (NOVO)
✅ Status de vencimento automático
✅ Alertas de vencimento próximo
✅ Responsividade mobile/tablet/desktop
✅ Notificações toast
✅ Validações de formulário
✅ Tratamento de erros
✅ Debug integrado

TESTES DISPONÍVEIS:
- testeModal(): Testa se o modal abre/fecha
- testeAjax(): Testa se as requisições AJAX funcionam
- openModal(ID): Abre modal para uma compra específica

ATALHOS DE TECLADO:
- ESC: Fecha modal ou cancela edição
- Ctrl+F: Foca no campo de pesquisa (navegador)

NOVOS CAMPOS:
✅ data_vencimento: Data de vencimento da compra
✅ Status automático: Em dia / Próximo / Vencido
✅ Estatística de compras vencendo
✅ Indicadores visuais na tabela
✅ Formulário de edição atualizado
-->

</body>
</html>

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