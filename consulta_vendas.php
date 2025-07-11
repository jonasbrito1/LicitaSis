<?php
// ===========================================
// CONSULTA DE VENDAS - LICITASIS (CÓDIGO COMPLETO)
// Sistema Completo de Gestão de Licitações com Produtos
// Versão: 7.0 Final - Todas as funcionalidades implementadas
// ===========================================

session_start();
ob_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Includes necessários
require_once('db.php');
include('permissions.php');
include('includes/audit.php');

// Inicialização do sistema de permissões
$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('vendas', 'read');
logUserAction('READ', 'vendas_consulta');

// Variáveis globais
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';
$error = "";
$success = "";
$vendas = [];
$searchTerm = "";
$classificacaoFilter = "";
$statusPagamentoFilter = "";

// Configuração da paginação
$itensPorPagina = 20;
$paginaAtual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($paginaAtual - 1) * $itensPorPagina;

// ===========================================
// PROCESSAMENTO AJAX - ATUALIZAÇÃO COMPLETA DA VENDA
// ===========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_venda'])) {
    header('Content-Type: application/json');
    
    if (!$permissionManager->hasPagePermission('vendas', 'edit')) {
        echo json_encode(['error' => 'Sem permissão para editar vendas']);
        exit();
    }
    
    $response = ['success' => false];
    
    try {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            throw new Exception("ID da venda inválido.");
        }

        $pdo->beginTransaction();
        
        // Busca dados antigos para auditoria
        $stmt_old = $pdo->prepare("SELECT * FROM vendas WHERE id = ?");
        $stmt_old->execute([$id]);
        $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);

        if (!$old_data) {
            throw new Exception("Venda não encontrada.");
        }

        // Coleta e sanitiza os dados
        $dados = [
            'numero' => trim(filter_input(INPUT_POST, 'numero', FILTER_SANITIZE_STRING)),
            'nf' => trim(filter_input(INPUT_POST, 'nf', FILTER_SANITIZE_STRING)),
            'cliente_nome' => trim(filter_input(INPUT_POST, 'cliente_nome', FILTER_SANITIZE_STRING)),
            'cliente_uasg' => trim(filter_input(INPUT_POST, 'cliente_uasg', FILTER_SANITIZE_STRING)),
            'pregao' => trim(filter_input(INPUT_POST, 'pregao', FILTER_SANITIZE_STRING)),
            'classificacao' => trim(filter_input(INPUT_POST, 'classificacao', FILTER_SANITIZE_STRING)),
            'status_pagamento' => trim(filter_input(INPUT_POST, 'status_pagamento', FILTER_SANITIZE_STRING)),
            'observacao' => trim(filter_input(INPUT_POST, 'observacao', FILTER_SANITIZE_STRING)),
            'transportadora' => filter_input(INPUT_POST, 'transportadora', FILTER_VALIDATE_INT),
            'data' => filter_input(INPUT_POST, 'data', FILTER_SANITIZE_STRING),
            'data_vencimento' => filter_input(INPUT_POST, 'data_vencimento', FILTER_SANITIZE_STRING),
            'valor_total' => str_replace(',', '.', filter_input(INPUT_POST, 'valor_total'))
        ];

        // Validações básicas
        $classificacoes_validas = ['Pendente', 'Faturada', 'Comprada', 'Entregue', 'Liquidada', 'Devolucao'];
        if (!empty($dados['classificacao']) && !in_array($dados['classificacao'], $classificacoes_validas)) {
            throw new Exception("Classificação inválida.");
        }

        $status_pagamento_validos = ['Não Recebido', 'Recebido'];
        if (!empty($dados['status_pagamento']) && !in_array($dados['status_pagamento'], $status_pagamento_validos)) {
            throw new Exception("Status de pagamento inválido.");
        }

        // Converte datas para formato MySQL
        if (!empty($dados['data'])) {
            $data_obj = DateTime::createFromFormat('Y-m-d', $dados['data']);
            if (!$data_obj) {
                throw new Exception("Data inválida.");
            }
            $dados['data'] = $data_obj->format('Y-m-d');
        } else {
            $dados['data'] = null;
        }

        if (!empty($dados['data_vencimento'])) {
            $venc_obj = DateTime::createFromFormat('Y-m-d', $dados['data_vencimento']);
            if (!$venc_obj) {
                throw new Exception("Data de vencimento inválida.");
            }
            $dados['data_vencimento'] = $venc_obj->format('Y-m-d');
        } else {
            $dados['data_vencimento'] = null;
        }

        // Atualiza a venda
        $sql = "UPDATE vendas SET 
                numero = :numero,
                nf = :nf,
                cliente = :cliente_nome,
                cliente_uasg = :cliente_uasg,
                pregao = :pregao,
                classificacao = :classificacao,
                status_pagamento = :status_pagamento,
                observacao = :observacao,
                transportadora = :transportadora,
                `data` = :data,
                data_vencimento = :data_vencimento,
                valor_total = :valor_total
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $dados['id'] = $id;
        
        if (!$stmt->execute($dados)) {
            throw new Exception("Erro ao atualizar a venda no banco de dados.");
        }

        logUserAction('UPDATE', 'vendas', $id, [
            'old' => $old_data,
            'new' => $dados
        ]);

        $pdo->commit();
        $response['success'] = true;
        $response['message'] = "Venda atualizada com sucesso!";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $response['error'] = $e->getMessage();
    }

    echo json_encode($response);
    exit();
}

// ===========================================
// PROCESSAMENTO AJAX - ATUALIZAÇÃO DE CLASSIFICAÇÃO
// ===========================================
if (isset($_POST['update_classificacao'])) {
    header('Content-Type: application/json');
    
    $id = $_POST['venda_id'];
    $classificacao = $_POST['classificacao'];
    $classificacoes_validas = ['Pendente', 'Faturada', 'Comprada', 'Entregue', 'Liquidada', 'Devolucao'];

    if (!in_array($classificacao, $classificacoes_validas)) {
        echo json_encode(['error' => 'Classificação inválida']);
        exit();
    }

    try {
        // Busca valor anterior para auditoria
        $stmt_old = $pdo->prepare("SELECT classificacao FROM vendas WHERE id = :id");
        $stmt_old->bindParam(':id', $id);
        $stmt_old->execute();
        $old_classificacao = $stmt_old->fetchColumn();

        // Atualiza classificação
        $sql = "UPDATE vendas SET classificacao = :classificacao WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':classificacao', $classificacao, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        // Registra auditoria
        logUserAction('UPDATE', 'vendas', $id, [
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

// ===========================================
// PROCESSAMENTO AJAX - ATUALIZAÇÃO DE STATUS DE PAGAMENTO
// ===========================================
if (isset($_POST['update_status_pagamento'])) {
    header('Content-Type: application/json');
    
    $id = $_POST['venda_id'];
    $status = $_POST['status_pagamento'];
    $status_validos = ['Não Recebido', 'Recebido'];

    if (!in_array($status, $status_validos)) {
        echo json_encode(['error' => 'Status de pagamento inválido']);
        exit();
    }

    try {
        // Busca valor anterior para auditoria
        $stmt_old = $pdo->prepare("SELECT status_pagamento FROM vendas WHERE id = :id");
        $stmt_old->bindParam(':id', $id);
        $stmt_old->execute();
        $old_status = $stmt_old->fetchColumn();

        // Atualiza status
        $sql = "UPDATE vendas SET status_pagamento = :status_pagamento WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':status_pagamento', $status, PDO::PARAM_STR);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        // Registra auditoria
        logUserAction('UPDATE', 'vendas', $id, [
            'campo' => 'status_pagamento',
            'valor_anterior' => $old_status,
            'valor_novo' => $status
        ]);

        echo json_encode(['success' => true, 'message' => 'Status de pagamento atualizado com sucesso!']);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao atualizar status: ' . $e->getMessage()]);
    }
    exit();
}

// ===========================================
// PROCESSAMENTO AJAX - OBTER DADOS DA VENDA
// ===========================================
if (isset($_GET['get_venda_id'])) {
    header('Content-Type: application/json');
    
    try {
        $id = filter_input(INPUT_GET, 'get_venda_id', FILTER_VALIDATE_INT);
        
        if (!$id) {
            throw new Exception('ID da venda inválido');
        }
        
        // Consulta principal da venda
        $sql = "SELECT 
                v.id,
                v.numero,
                v.nf,
                v.cliente_uasg,
                v.cliente as cliente_nome,
                v.pregao,
                v.transportadora,
                v.observacao,
                v.`data`,
                v.data_vencimento,
                v.valor_total,
                v.classificacao,
                v.status_pagamento,
                v.created_at,
                v.empenho_id,
                t.nome as transportadora_nome,
                e.numero as empenho_numero,
                CASE 
                    WHEN v.`data` IS NOT NULL AND v.`data` != '0000-00-00' THEN DATE_FORMAT(v.`data`, '%Y-%m-%d')
                    ELSE NULL
                END as data_iso,
                CASE 
                    WHEN v.`data` IS NOT NULL AND v.`data` != '0000-00-00' THEN DATE_FORMAT(v.`data`, '%d/%m/%Y')
                    ELSE 'N/A'
                END as data_formatada,
                CASE 
                    WHEN v.data_vencimento IS NOT NULL AND v.data_vencimento != '0000-00-00' THEN DATE_FORMAT(v.data_vencimento, '%Y-%m-%d')
                    ELSE NULL
                END as data_vencimento_iso,
                CASE 
                    WHEN v.data_vencimento IS NOT NULL AND v.data_vencimento != '0000-00-00' THEN DATE_FORMAT(v.data_vencimento, '%d/%m/%Y')
                    ELSE 'N/A'
                END as data_vencimento_formatada,
                DATE_FORMAT(v.created_at, '%d/%m/%Y %H:%i') as data_cadastro_formatada,
                CASE 
                    WHEN v.data_vencimento IS NOT NULL AND v.data_vencimento != '0000-00-00' AND v.data_vencimento < CURDATE() AND v.status_pagamento != 'Recebido' THEN 1
                    ELSE 0
                END as em_atraso,
                CASE 
                    WHEN v.data_vencimento IS NOT NULL AND v.data_vencimento != '0000-00-00' AND v.data_vencimento < CURDATE() AND v.status_pagamento != 'Recebido' 
                    THEN DATEDIFF(CURDATE(), v.data_vencimento)
                    ELSE 0
                END as dias_atraso
                FROM vendas v 
                LEFT JOIN transportadora t ON v.transportadora = t.id
                LEFT JOIN empenhos e ON v.empenho_id = e.id
                WHERE v.id = :id";
                
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $venda = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$venda) {
            throw new Exception('Venda não encontrada');
        }

        // Busca produtos relacionados à venda
        $sql_produtos = "SELECT 
            vp.*,
            COALESCE(p.nome, 'Produto sem nome') AS produto_nome,
            p.codigo AS produto_codigo,
            p.observacao AS produto_observacao,
            p.categoria AS produto_categoria,
            p.unidade AS produto_unidade,
            COALESCE(p.custo_total, 0) AS custo_unitario,
            COALESCE(p.preco_unitario, vp.valor_unitario, 0) AS preco_unitario_produto,
            COALESCE(p.preco_venda, p.preco_unitario, vp.valor_unitario, 0) AS preco_venda,
            p.margem_lucro,
            COALESCE(p.total_impostos, 0) as total_impostos,
            p.icms, p.irpj, p.cofins, p.csll, p.pis_pasep, p.ipi,
            p.estoque_atual, p.controla_estoque
            FROM venda_produtos vp 
            LEFT JOIN produtos p ON vp.produto_id = p.id 
            WHERE vp.venda_id = :id
            ORDER BY vp.id";
        
        $stmt_produtos = $pdo->prepare($sql_produtos);
        $stmt_produtos->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_produtos->execute();
        $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

        // Cálculos de lucratividade
        $valor_total_venda = 0;
        $valor_total_custo = 0;
        
        foreach ($produtos as &$produto) {
            $quantidade = floatval($produto['quantidade'] ?? 0);
            $valor_unitario_venda = floatval($produto['valor_unitario'] ?? 0);
            $custo_unitario = floatval($produto['custo_unitario'] ?? 0);
            
            $valor_venda_produto = $quantidade * $valor_unitario_venda;
            $valor_custo_produto = $quantidade * $custo_unitario;
            
            $lucro_produto = $valor_venda_produto - $valor_custo_produto;
            $margem_lucro = $valor_venda_produto > 0 ? ($lucro_produto / $valor_venda_produto) * 100 : 0;
            
            $produto['valor_venda_total'] = $valor_venda_produto;
            $produto['valor_custo_total'] = $valor_custo_produto;
            $produto['lucro_total'] = $lucro_produto;
            $produto['margem_lucro'] = $margem_lucro;
            
            $valor_total_venda += $valor_venda_produto;
            $valor_total_custo += $valor_custo_produto;
        }

        $lucro_total_geral = $valor_total_venda - $valor_total_custo;
        $margem_lucro_geral = $valor_total_venda > 0 ? ($lucro_total_geral / $valor_total_venda) * 100 : 0;

        // Busca informações do cliente se UASG estiver preenchida
        $cliente_info = null;
        if (!empty($venda['cliente_uasg'])) {
            $sql_cliente = "SELECT * FROM clientes WHERE uasg = :uasg LIMIT 1";
            $stmt_cliente = $pdo->prepare($sql_cliente);
            $stmt_cliente->bindParam(':uasg', $venda['cliente_uasg']);
            $stmt_cliente->execute();
            $cliente_info = $stmt_cliente->fetch(PDO::FETCH_ASSOC);
        }

        // Monta resposta completa
        $response = [
            // Dados básicos da venda
            'id' => $venda['id'],
            'numero' => $venda['numero'] ?? '',
            'nf' => $venda['nf'] ?? '',
            'cliente_nome' => $venda['cliente_nome'] ?? '',
            'cliente_uasg' => $venda['cliente_uasg'] ?? '',
            'pregao' => $venda['pregao'] ?? '',
            'classificacao' => $venda['classificacao'] ?? 'Pendente',
            'status_pagamento' => $venda['status_pagamento'] ?? 'Não Recebido',
            'observacao' => $venda['observacao'] ?? '',
            'transportadora' => $venda['transportadora'] ?? '',
            'transportadora_nome' => $venda['transportadora_nome'] ?? '',
            'data' => $venda['data_iso'],
            'data_formatada' => $venda['data_formatada'],
            'data_vencimento' => $venda['data_vencimento_iso'],
            'data_vencimento_formatada' => $venda['data_vencimento_formatada'],
            'data_cadastro' => $venda['data_cadastro_formatada'],
            'created_at' => $venda['created_at'],
            'valor_total' => floatval($venda['valor_total'] ?? 0),
            'empenho_id' => $venda['empenho_id'],
            'empenho_numero' => $venda['empenho_numero'],
            
            // Produtos
            'produtos' => $produtos,
            
            // Cálculos de lucratividade
            'valor_total_venda' => $valor_total_venda,
            'valor_total_custo' => $valor_total_custo,
            'lucro_total_geral' => $lucro_total_geral,
            'margem_lucro_geral' => $margem_lucro_geral,
            
            // Informações de prazo
            'em_atraso' => (bool)$venda['em_atraso'],
            'dias_atraso' => intval($venda['dias_atraso'] ?? 0),
            
            // Informações do cliente
            'cliente_info' => $cliente_info
        ];
        
        echo json_encode($response);
        exit();
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
}

// ===========================================
// PROCESSAMENTO AJAX - EXCLUSÃO DE VENDA
// ===========================================
if (isset($_POST['delete_venda_id'])) { 
    header('Content-Type: application/json');
    
    if (!$permissionManager->hasPagePermission('vendas', 'delete')) {
        echo json_encode(['error' => 'Sem permissão para excluir vendas']);
        exit();
    }
    
    $id = $_POST['delete_venda_id'];

    try {
        $pdo->beginTransaction();

        // Busca dados da venda para auditoria
        $stmt_venda = $pdo->prepare("SELECT * FROM vendas WHERE id = :id");
        $stmt_venda->bindParam(':id', $id);
        $stmt_venda->execute();
        $venda_data = $stmt_venda->fetch(PDO::FETCH_ASSOC);

        if (!$venda_data) {
            throw new Exception("Venda não encontrada.");
        }

        // Exclui produtos da venda
        $sql = "DELETE FROM venda_produtos WHERE venda_id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        // Exclui a venda
        $sql = "DELETE FROM vendas WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new Exception("Nenhuma venda foi excluída. Verifique se o ID está correto.");
        }

        $pdo->commit();
        
        // Registra auditoria
        logUserAction('DELETE', 'vendas', $id, $venda_data);
        
        echo json_encode(['success' => true, 'message' => 'Venda excluída com sucesso!']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => 'Erro ao excluir a venda: ' . $e->getMessage()]);
    }
    exit();
}

// ===========================================
// CONSULTA PRINCIPAL COM FILTROS E PAGINAÇÃO
// ===========================================
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$classificacaoFilter = isset($_GET['classificacao']) ? trim($_GET['classificacao']) : '';
$statusPagamentoFilter = isset($_GET['status_pagamento']) ? trim($_GET['status_pagamento']) : '';

try {
    // Parâmetros para consulta
    $params = [];
    $whereConditions = [];
    
    // Condições de filtro
    if (!empty($searchTerm)) {
        $whereConditions[] = "(v.numero LIKE :searchTerm OR v.nf LIKE :searchTerm OR COALESCE(v.cliente, '') LIKE :searchTerm OR COALESCE(v.cliente_uasg, '') LIKE :searchTerm)";
        $params[':searchTerm'] = "%$searchTerm%";
    }
    
    if (!empty($classificacaoFilter)) {
        $whereConditions[] = "COALESCE(v.classificacao, 'Pendente') = :classificacao";
        $params[':classificacao'] = $classificacaoFilter;
    }

    if (!empty($statusPagamentoFilter)) {
        $whereConditions[] = "COALESCE(v.status_pagamento, 'Não Recebido') = :status_pagamento";
        $params[':status_pagamento'] = $statusPagamentoFilter;
    }
    
    $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

    // Consulta para contar total de registros
    $sqlCount = "SELECT COUNT(*) as total FROM vendas v $whereClause";
    $stmtCount = $pdo->prepare($sqlCount);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $totalRegistros = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPaginas = ceil($totalRegistros / $itensPorPagina);

    // Consulta principal com paginação
    $sql = "SELECT 
        v.id,
        COALESCE(v.numero, '') as numero, 
        COALESCE(v.nf, '') as nf,
        COALESCE(v.cliente, 'Cliente não informado') as cliente_nome, 
        COALESCE(v.cliente_uasg, '') as cliente_uasg,
        COALESCE(v.valor_total, 0) as valor_total, 
        COALESCE(v.classificacao, 'Pendente') as classificacao, 
        COALESCE(v.status_pagamento, 'Não Recebido') as status_pagamento,
        v.created_at, 
        COALESCE(v.pregao, '') as pregao,
        v.`data`,
        v.data_vencimento,
        COALESCE(v.observacao, '') as observacao,
        
        -- Cálculo do lucro total da venda
        COALESCE((
            SELECT SUM(
                (vp.quantidade * COALESCE(vp.valor_unitario, 0)) - 
                (vp.quantidade * COALESCE(p.custo_total, 0))
            )
            FROM venda_produtos vp 
            LEFT JOIN produtos p ON vp.produto_id = p.id 
            WHERE vp.venda_id = v.id
        ), 0) as lucro_total_valor,
        
        CASE 
            WHEN v.data_vencimento IS NOT NULL AND v.data_vencimento != '0000-00-00' AND v.data_vencimento < CURDATE() AND v.status_pagamento != 'Recebido' THEN 1
            ELSE 0
        END as em_atraso,
        CASE 
            WHEN v.data_vencimento IS NOT NULL AND v.data_vencimento != '0000-00-00' AND v.data_vencimento < CURDATE() AND v.status_pagamento != 'Recebido' 
            THEN DATEDIFF(CURDATE(), v.data_vencimento)
            ELSE 0
        END as dias_atraso,
        CASE 
            WHEN v.`data` IS NOT NULL AND v.`data` != '0000-00-00' THEN DATE_FORMAT(v.`data`, '%d/%m/%Y')
            ELSE 'N/A'
        END as data_formatada,
        CASE 
            WHEN v.data_vencimento IS NOT NULL AND v.data_vencimento != '0000-00-00' THEN DATE_FORMAT(v.data_vencimento, '%d/%m/%Y')
            ELSE 'N/A'
        END as data_vencimento_formatada,
        DATE_FORMAT(v.created_at, '%d/%m/%Y') as data_cadastro
    FROM vendas v 
    $whereClause
    ORDER BY v.id DESC 
    LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $itensPorPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Erro na consulta: " . $e->getMessage();
    $vendas = [];
}

// ===========================================
// FUNÇÃO PARA ÍCONES DE CLASSIFICAÇÃO
// ===========================================
function getClassificacaoIcon($classificacao) {
    $icons = [
        'Pendente' => 'clock',
        'Faturada' => 'file-invoice-dollar',
        'Comprada' => 'shopping-cart',
        'Entregue' => 'truck',
        'Liquidada' => 'check-circle',
        'Devolucao' => 'undo'
    ];
    return $icons[$classificacao] ?? 'tag';
}

// ===========================================
// BUSCAR TRANSPORTADORAS
// ===========================================
function buscarTransportadoras() {
    global $pdo;
    try {
        $sql = "SELECT id, nome FROM transportadora ORDER BY nome";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

$transportadoras = buscarTransportadoras();

// ===========================================
// CÁLCULO DE ESTATÍSTICAS
// ===========================================
try {
    // Valor total geral
    $sqlTotal = "SELECT SUM(COALESCE(valor_total, 0)) AS total_geral FROM vendas";
    $stmtTotal = $pdo->prepare($sqlTotal);
    $stmtTotal->execute();
    $totalGeral = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total_geral'] ?? 0;
    
    // Estatísticas por classificação
    $sqlStats = "SELECT 
                    COALESCE(classificacao, 'Pendente') as classificacao, 
                    COUNT(*) as quantidade, 
                    SUM(COALESCE(valor_total, 0)) as valor_total 
                 FROM vendas 
                 GROUP BY COALESCE(classificacao, 'Pendente')
                 ORDER BY quantidade DESC";
    $stmtStats = $pdo->prepare($sqlStats);
    $stmtStats->execute();
    $estatisticas = $stmtStats->fetchAll(PDO::FETCH_ASSOC);
    
    // Vendas em atraso
    $sqlAtrasos = "SELECT COUNT(*) as vendas_atrasadas 
                   FROM vendas 
                   WHERE data_vencimento IS NOT NULL 
                   AND data_vencimento != '0000-00-00' 
                   AND data_vencimento < CURDATE() 
                   AND status_pagamento != 'Recebido'";
    $stmtAtrasos = $pdo->prepare($sqlAtrasos);
    $stmtAtrasos->execute();
    $vendasAtrasadas = $stmtAtrasos->fetch(PDO::FETCH_ASSOC)['vendas_atrasadas'] ?? 0;
    
    // Valor pendente de recebimento
    $sqlPendente = "SELECT SUM(COALESCE(valor_total, 0)) AS valor_pendente 
                    FROM vendas 
                    WHERE status_pagamento != 'Recebido'";
    $stmtPendente = $pdo->prepare($sqlPendente);
    $stmtPendente->execute();
    $valorPendente = $stmtPendente->fetch(PDO::FETCH_ASSOC)['valor_pendente'] ?? 0;
    
} catch (PDOException $e) {
    $error = "Erro ao calcular estatísticas: " . $e->getMessage();
    $totalGeral = 0;
    $estatisticas = [];
    $vendasAtrasadas = 0;
    $valorPendente = 0;
}

// Inclui o header do sistema
include('includes/header_template.php');
renderHeader("Consulta de Vendas - LicitaSis", "vendas");
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Vendas - LicitaSis</title>
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
            
            /* Cores específicas para status */
            --pendente-color: #fd7e14;
            --faturada-color: #007bff;
            --comprada-color: #6610f2;
            --entregue-color: #20c997;
            --liquidada-color: #28a745;
            --devolucao-color: #dc3545;
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
            cursor: pointer;
            border: 2px solid transparent;
        }

        .stat-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .stat-item.atraso {
            border-left: 4px solid var(--danger-color);
        }

        .stat-item.atraso .stat-number {
            color: var(--danger-color);
        }

        .stat-item.pendente {
            border-left: 4px solid var(--warning-color);
        }

        .stat-item.pendente .stat-number {
            color: var(--warning-color);
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

        .stat-navegavel::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, rgba(0,191,174,0.1), rgba(45,137,62,0.1));
            transition: left 0.6s ease;
        }

        .stat-navegavel:hover::before {
            left: 100%;
        }

        .stat-navegavel:hover {
            transform: translateY(-8px) scale(1.05);
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            border-color: var(--secondary-color);
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

        .btn-light {
            background: white;
            color: var(--primary-color);
            border: 2px solid var(--border-color);
        }

        .btn-light:hover {
            background: var(--light-gray);
            border-color: var(--secondary-color);
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

        /* Destaque para linhas em atraso */
        table tbody tr.em-atraso {
            border-left: 4px solid var(--danger-color);
            background: rgba(220, 53, 69, 0.02);
        }

        /* ===========================================
           INDICADORES DE LUCRO
           =========================================== */
        .lucro-indicador {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .lucro-indicador.lucro-alto {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }

        .lucro-indicador.lucro-positivo {
            background: rgba(32, 201, 151, 0.1);
            color: var(--entregue-color);
            border: 1px solid var(--entregue-color);
        }

        .lucro-indicador.lucro-neutro {
            background: rgba(108, 117, 125, 0.1);
            color: var(--medium-gray);
            border: 1px solid var(--medium-gray);
        }

        .lucro-indicador.lucro-negativo {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }

        /* ===========================================
           ELEMENTOS ESPECÍFICOS DA TABELA
           =========================================== */
        .numero-venda {
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

        .numero-venda:hover {
            color: var(--primary-color);
            background: rgba(45, 137, 62, 0.1);
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0, 191, 174, 0.2);
        }

        .numero-venda i {
            font-size: 0.8rem;
        }

        .classificacao-select, .status-select {
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

        .classificacao-select:focus, .status-select:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
            transform: translateY(-1px);
        }

        .classificacao-select:hover, .status-select:hover {
            border-color: var(--secondary-color);
        }

        /* Estados visuais por classificação */
        .classificacao-select[value="Pendente"] { 
            background: rgba(253, 126, 20, 0.1); 
            color: var(--pendente-color); 
            border-color: var(--pendente-color);
        }
        .classificacao-select[value="Faturada"] { 
            background: rgba(0, 123, 255, 0.1); 
            color: var(--faturada-color);
            border-color: var(--faturada-color);
        }
        .classificacao-select[value="Comprada"] { 
            background: rgba(102, 16, 242, 0.1); 
            color: var(--comprada-color);
            border-color: var(--comprada-color);
        }
        .classificacao-select[value="Entregue"] { 
            background: rgba(32, 201, 151, 0.1); 
            color: var(--entregue-color);
            border-color: var(--entregue-color);
        }
        .classificacao-select[value="Liquidada"] { 
            background: rgba(40, 167, 69, 0.1); 
            color: var(--liquidada-color);
            border-color: var(--liquidada-color);
        }
        .classificacao-select[value="Devolucao"] { 
            background: rgba(220, 53, 69, 0.1); 
            color: var(--devolucao-color);
            border-color: var(--devolucao-color);
        }

        /* Estados visuais por status de pagamento */
        .status-select[value="Não Recebido"] { 
            background: rgba(253, 126, 20, 0.1); 
            color: var(--warning-color);
            border-color: var(--warning-color);
        }
        .status-select[value="Recebido"] { 
            background: rgba(40, 167, 69, 0.1); 
            color: var(--success-color);
            border-color: var(--success-color);
        }

        .atraso-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .atraso-indicator.em-atraso {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }

        .atraso-indicator.no-prazo {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid var(--success-color);
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
        .venda-details {
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
            color: var(--primary-color);
            font-weight: 700;
            font-size: 1.1rem;
        }

        .detail-value.money {
            color: var(--success-color);
            font-weight: 700;
            font-size: 1.1rem;
        }

        /* ===========================================
           SEÇÃO DE LUCRATIVIDADE NO MODAL
           =========================================== */
        .lucratividade-section {
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .lucratividade-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .lucratividade-title {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .lucratividade-resumo {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .lucratividade-item {
            background: white;
            padding: 1rem;
            border-radius: var(--radius-sm);
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .lucratividade-valor {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .lucratividade-valor.positivo {
            color: var(--success-color);
        }

        .lucratividade-valor.negativo {
            color: var(--danger-color);
        }

        .lucratividade-valor.neutro {
            color: var(--medium-gray);
        }

        .lucratividade-valor.money {
            color: var(--success-color);
        }

        .lucratividade-label {
            font-size: 0.85rem;
            color: var(--medium-gray);
            text-transform: uppercase;
            font-weight: 600;
        }

        .margem-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .margem-badge.alta {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }

        .margem-badge.media {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
            border: 1px solid var(--warning-color);
        }

        .margem-badge.baixa {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid var(--danger-color);
        }

        .margem-badge.neutra {
            background: rgba(108, 117, 125, 0.1);
            color: var(--medium-gray);
            border: 1px solid var(--medium-gray);
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

        .status-badge.pendente {
            background: rgba(253, 126, 20, 0.1);
            color: var(--pendente-color);
            border: 1px solid var(--pendente-color);
        }

        .status-badge.faturada {
            background: rgba(0, 123, 255, 0.1);
            color: var(--faturada-color);
            border: 1px solid var(--faturada-color);
        }

        .status-badge.comprada {
            background: rgba(102, 16, 242, 0.1);
            color: var(--comprada-color);
            border: 1px solid var(--comprada-color);
        }

        .status-badge.entregue {
            background: rgba(32, 201, 151, 0.1);
            color: var(--entregue-color);
            border: 1px solid var(--entregue-color);
        }

        .status-badge.liquidada {
            background: rgba(40, 167, 69, 0.1);
            color: var(--liquidada-color);
            border: 1px solid var(--liquidada-color);
        }

        .status-badge.devolucao {
            background: rgba(220, 53, 69, 0.1);
            color: var(--devolucao-color);
            border: 1px solid var(--devolucao-color);
        }

        .status-badge.recebido {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid var(--success-color);
        }

        .status-badge.nao-recebido {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
            border: 1px solid var(--warning-color);
        }

        /* ===========================================
           UTILITÁRIOS
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

            .numero-venda {
                padding: 0.25rem 0.5rem;
            }

            .classificacao-select, .status-select {
                min-width: 120px;
                padding: 0.5rem;
                font-size: 0.8rem;
            }

            .lucratividade-resumo {
                grid-template-columns: 1fr;
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

            .numero-venda {
                font-size: 0.8rem;
                padding: 0.25rem 0.4rem;
            }

            .classificacao-select, .status-select {
                min-width: 100px;
                padding: 0.4rem;
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>
        <i class="fas fa-shopping-cart"></i>
        Consulta de Vendas
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

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- ===========================================
         ESTATÍSTICAS EXPANDIDAS NAVEGÁVEIS
         =========================================== -->
    <?php if (!empty($estatisticas)): ?>
    <div class="stats-container">
        <?php foreach ($estatisticas as $stat): ?>
        <div class="stat-item stat-navegavel <?php echo strtolower($stat['classificacao']); ?>" 
             onclick="navegarParaDetalhes('<?php echo $stat['classificacao']; ?>')">
            <div class="stat-number"><?php echo $stat['quantidade']; ?></div>
            <div class="stat-label"><?php echo htmlspecialchars($stat['classificacao']); ?></div>
            <div class="stat-icon">
                <i class="fas fa-<?php echo getClassificacaoIcon($stat['classificacao']); ?>"></i>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- Indicador de atrasos -->
        <div class="stat-item stat-navegavel atraso" onclick="navegarParaDetalhes('atraso')">
            <div class="stat-number"><?php echo $vendasAtrasadas ?? 0; ?></div>
            <div class="stat-label">Em Atraso</div>
            <div class="stat-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
        </div>

        <!-- Valor pendente -->
        <div class="stat-item stat-navegavel pendente" onclick="navegarParaDetalhes('pendente')">
            <div class="stat-number">R$ <?php echo number_format($valorPendente, 0, ',', '.'); ?></div>
            <div class="stat-label">Valor Pendente</div>
            <div class="stat-icon">
                <i class="fas fa-hourglass-half"></i>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===========================================
         VALOR TOTAL GERAL
         =========================================== -->
    <?php if (isset($totalGeral)): ?>
        <div class="total-geral">
            <div>
                <i class="fas fa-calculator"></i>
                <span>Valor Total Geral de Vendas</span>
            </div>
            <strong>R$ <?php echo number_format($totalGeral, 2, ',', '.'); ?></strong>
        </div>
    <?php endif; ?>

    <!-- ===========================================
         FILTROS AVANÇADOS
         =========================================== -->
    <div class="filters-container">
        <form action="consulta_vendas.php" method="GET" id="filtersForm">
            <div class="filters-row">
                <div class="search-group">
                    <label for="search">Buscar por:</label>
                    <input type="text" 
                           name="search" 
                           id="search" 
                           class="search-input"
                           placeholder="Número, NF, cliente ou UASG..." 
                           value="<?php echo htmlspecialchars($searchTerm ?? ''); ?>"
                           autocomplete="off">
                </div>
                
                <!-- Filtro por classificação -->
                <div class="search-group">
                    <label for="classificacao">Classificação:</label>
                    <select name="classificacao" id="classificacao" class="filter-select">
                        <option value="">Todas as classificações</option>
                        <option value="Pendente" <?php echo $classificacaoFilter === 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
                        <option value="Faturada" <?php echo $classificacaoFilter === 'Faturada' ? 'selected' : ''; ?>>Faturada</option>
                        <option value="Comprada" <?php echo $classificacaoFilter === 'Comprada' ? 'selected' : ''; ?>>Comprada</option>
                        <option value="Entregue" <?php echo $classificacaoFilter === 'Entregue' ? 'selected' : ''; ?>>Entregue</option>
                        <option value="Liquidada" <?php echo $classificacaoFilter === 'Liquidada' ? 'selected' : ''; ?>>Liquidada</option>
                        <option value="Devolucao" <?php echo $classificacaoFilter === 'Devolucao' ? 'selected' : ''; ?>>Devolução</option>
                    </select>
                </div>

                <!-- Filtro por status de pagamento -->
                <div class="search-group">
                    <label for="status_pagamento">Status Pagamento:</label>
                    <select name="status_pagamento" id="status_pagamento" class="filter-select">
                        <option value="">Todos os status</option>
                        <option value="Não Recebido" <?php echo $statusPagamentoFilter === 'Não Recebido' ? 'selected' : ''; ?>>Não Recebido</option>
                        <option value="Recebido" <?php echo $statusPagamentoFilter === 'Recebido' ? 'selected' : ''; ?>>Recebido</option>
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
            </div>
        </form>
    </div>

    <!-- ===========================================
         TABELA DE VENDAS
         =========================================== -->
    <?php if (count($vendas) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th><i class="fas fa-receipt"></i> NF</th>
                        <th><i class="fas fa-building"></i> Cliente</th>
                        <th><i class="fas fa-hashtag"></i> UASG</th>
                        <th><i class="fas fa-dollar-sign"></i> Valor</th>
                        <th><i class="fas fa-chart-line"></i> Lucro R$</th>
                        <th><i class="fas fa-tags"></i> Classificação</th>
                        <th><i class="fas fa-credit-card"></i> Pagamento</th>
                        <th><i class="fas fa-calendar"></i> Data Venda</th>
                        <th><i class="fas fa-calendar-plus"></i> Cadastro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vendas as $venda): ?>
                        <tr <?php if ($venda['em_atraso']): ?>class="em-atraso"<?php endif; ?>>
                            <td>
                                <span class="numero-venda" onclick="openModal(<?php echo $venda['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                    <?php echo htmlspecialchars($venda['nf'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($venda['cliente_nome'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($venda['cliente_uasg'] ?? 'N/A'); ?></td>
                            <td>
                                <strong>R$ <?php echo isset($venda['valor_total']) ? number_format($venda['valor_total'], 2, ',', '.') : 'N/A'; ?></strong>
                            </td>
                            <td>
                                <?php 
                                $lucroTotal = floatval($venda['lucro_total_valor'] ?? 0);
                                $lucroClass = '';
                                $lucroIcon = '';
                                
                                if ($lucroTotal > 1000) {
                                    $lucroClass = 'lucro-alto';
                                    $lucroIcon = 'fas fa-arrow-up';
                                } elseif ($lucroTotal > 0) {
                                    $lucroClass = 'lucro-positivo';
                                    $lucroIcon = 'fas fa-check';
                                } elseif ($lucroTotal == 0) {
                                    $lucroClass = 'lucro-neutro';
                                    $lucroIcon = 'fas fa-minus';
                                } else {
                                    $lucroClass = 'lucro-negativo';
                                    $lucroIcon = 'fas fa-times';
                                }
                                ?>
                                <span class="lucro-indicador <?php echo $lucroClass; ?>">
                                    <i class="<?php echo $lucroIcon; ?>"></i>
                                    R$ <?php echo number_format($lucroTotal, 2, ',', '.'); ?>
                                </span>
                            </td>
                            <td>
                                <select class="classificacao-select" 
                                        data-venda-id="<?php echo $venda['id']; ?>" 
                                        onchange="updateClassificacao(this)"
                                        value="<?php echo $venda['classificacao'] ?? ''; ?>">
                                    <option value="">Selecionar</option>
                                    <option value="Pendente" <?php echo $venda['classificacao'] === 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
                                    <option value="Faturada" <?php echo $venda['classificacao'] === 'Faturada' ? 'selected' : ''; ?>>Faturada</option>
                                    <option value="Comprada" <?php echo $venda['classificacao'] === 'Comprada' ? 'selected' : ''; ?>>Comprada</option>
                                    <option value="Entregue" <?php echo $venda['classificacao'] === 'Entregue' ? 'selected' : ''; ?>>Entregue</option>
                                    <option value="Liquidada" <?php echo $venda['classificacao'] === 'Liquidada' ? 'selected' : ''; ?>>Liquidada</option>
                                    <option value="Devolucao" <?php echo $venda['classificacao'] === 'Devolucao' ? 'selected' : ''; ?>>Devolução</option>
                                </select>
                            </td>
                            <td>
                                <select class="status-select" 
                                        data-venda-id="<?php echo $venda['id']; ?>" 
                                        onchange="updateStatusPagamento(this)"
                                        value="<?php echo $venda['status_pagamento'] ?? ''; ?>">
                                    <option value="Não Recebido" <?php echo ($venda['status_pagamento'] === 'Não Recebido' || !$venda['status_pagamento']) ? 'selected' : ''; ?>>Não Recebido</option>
                                    <option value="Recebido" <?php echo $venda['status_pagamento'] === 'Recebido' ? 'selected' : ''; ?>>Recebido</option>
                                </select>
                            </td>
                            <td>
                                <strong><?php echo $venda['data_formatada'] ?? 'N/A'; ?></strong>
                                <?php if ($venda['em_atraso']): ?>
                                    <br>
                                    <span class="atraso-indicator em-atraso">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <?php echo ($venda['dias_atraso'] ?? 0); ?> dias atraso
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $venda['data_cadastro']; ?></td>
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
                <?php echo $totalRegistros; ?> vendas
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
            <p>Nenhuma venda encontrada.</p>
            <small>Tente ajustar os filtros ou cadastre uma nova venda.</small>
        </div>
    <?php endif; ?>
</div>

<!-- ===========================================
     MODAL DE DETALHES DA VENDA
     =========================================== -->
<div id="vendaModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-shopping-cart"></i> 
                Detalhes da Venda
            </h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body" id="modalBody">
            <div class="loading-spinner" style="text-align: center; padding: 3rem;">
                <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da venda...</p>
            </div>
        </div>
        <div class="modal-footer" id="modalFooter" style="display: none;">
            <?php if ($permissionManager->hasPagePermission('vendas', 'edit')): ?>
            <button class="btn btn-warning" onclick="editarVenda()" id="editarBtn">
                <i class="fas fa-edit"></i> Editar
            </button>
            <?php endif; ?>
            
            <?php if ($permissionManager->hasPagePermission('vendas', 'delete')): ?>
            <button class="btn btn-danger" onclick="confirmarExclusao()" id="excluirBtn">
                <i class="fas fa-trash"></i> Excluir
            </button>
            <?php endif; ?>
            
            <button class="btn btn-primary" onclick="imprimirVenda()">
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
// SISTEMA COMPLETO DE CONSULTA DE VENDAS
// JavaScript Completo - LicitaSis v7.0
// ===========================================

// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let currentVendaId = null;
let currentVendaData = null;
let isEditingVenda = false;

// ===========================================
// FUNÇÕES DE CONTROLE DO MODAL
// ===========================================

/**
 * Abre o modal com detalhes da venda
 * @param {number} vendaId - ID da venda
 */
function openModal(vendaId) {
    console.log('🔍 Abrindo modal para venda ID:', vendaId);
    
    currentVendaId = vendaId;
    const modal = document.getElementById('vendaModal');
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
            <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da venda...</p>
        </div>
    `;
    modalFooter.style.display = 'none';
    
    // Busca dados da venda
    const url = `consulta_vendas.php?get_venda_id=${vendaId}&t=${Date.now()}`;
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
            console.log('✅ Dados da venda recebidos:', data);
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            // Valida e sanitiza os dados antes de usar
            currentVendaData = validarDadosVenda(data);
            renderVendaDetails(currentVendaData);
            modalFooter.style.display = 'flex';
            
            console.log('✅ Modal renderizado com sucesso para venda:', currentVendaData.nf);
        })
        .catch(error => {
            console.error('❌ Erro ao carregar venda:', error);
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 3rem; color: var(--danger-color);">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                    <p style="font-size: 1.1rem; margin-bottom: 1rem;">Erro ao carregar venda</p>
                    <p style="color: var(--medium-gray);">${error.message}</p>
                    <button class="btn btn-warning" onclick="openModal(${vendaId})" style="margin: 1rem 0.5rem;">
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
    const editForm = document.getElementById('vendaEditForm');
    if (editForm) {
        editForm.style.display = 'none';
    }
    
    const viewMode = document.getElementById('vendaViewMode');
    if (viewMode) {
        viewMode.style.display = 'block';
    }
    
    isEditingVenda = false;
}

/**
 * Renderiza os detalhes completos da venda no modal
 * @param {Object} venda - Dados da venda
 */
function renderVendaDetails(venda) {
    console.log('🎨 Renderizando detalhes da venda:', venda);
    
    const modalBody = document.getElementById('modalBody');
    
    // Prepara datas e valores com validações
    const dataFormatada = venda.data_cadastro || 'N/A';
    const dataVenda = venda.data || '';
    const dataVendaDisplay = venda.data_formatada || 'N/A';
    const dataVencimento = venda.data_vencimento || '';
    const dataVencimentoDisplay = venda.data_vencimento_formatada || 'N/A';
    
    // Garante que dias_atraso seja um número válido
    const diasAtraso = parseInt(venda.dias_atraso) || 0;
    const emAtraso = Boolean(venda.em_atraso);
    
    // Determina classe da margem de lucratividade
    let margemClass = 'neutra';
    const margemLucro = parseFloat(venda.margem_lucro_geral) || 0;
    if (margemLucro > 20) margemClass = 'alta';
    else if (margemLucro > 10) margemClass = 'media';
    else if (margemLucro < 0) margemClass = 'baixa';

    modalBody.innerHTML = `
        <div class="venda-details">
            <!-- Formulário de Edição (inicialmente oculto) -->
            <form id="vendaEditForm" style="display: none;">
                <input type="hidden" name="id" value="${venda.id}">
                <input type="hidden" name="update_venda" value="1">
                
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-edit"></i>
                        Editar Informações da Venda
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Número da Venda</div>
                                <input type="text" name="numero" class="form-control" value="${venda.numero || ''}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Nota Fiscal *</div>
                                <input type="text" name="nf" class="form-control" value="${venda.nf || ''}" required>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <select name="classificacao" class="form-control">
                                    <option value="">Selecionar</option>
                                    <option value="Pendente" ${venda.classificacao === 'Pendente' ? 'selected' : ''}>Pendente</option>
                                    <option value="Faturada" ${venda.classificacao === 'Faturada' ? 'selected' : ''}>Faturada</option>
                                    <option value="Comprada" ${venda.classificacao === 'Comprada' ? 'selected' : ''}>Comprada</option>
                                    <option value="Entregue" ${venda.classificacao === 'Entregue' ? 'selected' : ''}>Entregue</option>
                                    <option value="Liquidada" ${venda.classificacao === 'Liquidada' ? 'selected' : ''}>Liquidada</option>
                                    <option value="Devolucao" ${venda.classificacao === 'Devolucao' ? 'selected' : ''}>Devolução</option>
                                </select>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status Pagamento</div>
                                <select name="status_pagamento" class="form-control">
                                    <option value="Não Recebido" ${venda.status_pagamento === 'Não Recebido' ? 'selected' : ''}>Não Recebido</option>
                                    <option value="Recebido" ${venda.status_pagamento === 'Recebido' ? 'selected' : ''}>Recebido</option>
                                </select>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data da Venda</div>
                                <input type="date" name="data" class="form-control" value="${dataVenda}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data de Vencimento</div>
                                <input type="date" name="data_vencimento" class="form-control" value="${dataVencimento}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Pregão</div>
                                <input type="text" name="pregao" class="form-control" value="${venda.pregao || ''}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Valor Total</div>
                                <input type="number" name="valor_total" class="form-control" step="0.01" min="0" value="${venda.valor_total || ''}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-building"></i>
                        Informações do Cliente
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Nome do Cliente *</div>
                                <input type="text" name="cliente_nome" class="form-control" value="${venda.cliente_nome || ''}" required>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">UASG</div>
                                <input type="text" name="cliente_uasg" class="form-control" value="${venda.cliente_uasg || ''}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-truck"></i>
                        Logística e Observações
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Transportadora</div>
                                <select name="transportadora" class="form-control">
                                    <option value="">Selecionar transportadora</option>
                                    <?php foreach ($transportadoras as $t): ?>
                                    <option value="<?php echo $t['id']; ?>" ${venda.transportadora == <?php echo $t['id']; ?> ? 'selected' : ''}><?php echo htmlspecialchars($t['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Observações</div>
                                <textarea name="observacao" class="form-control" rows="4">${venda.observacao || ''}</textarea>
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
                        <i class="fas fa-trash"></i> Excluir Venda
                    </button>
                </div>
            </form>

            <!-- Visualização Normal (inicialmente visível) -->
            <div id="vendaViewMode">
                <!-- Análise de Lucratividade -->
                <div class="lucratividade-section">
                    <div class="lucratividade-header">
                        <div class="lucratividade-title">
                            <i class="fas fa-chart-line"></i>
                            Análise de Lucratividade
                        </div>
                        <div class="margem-badge ${margemClass}">
                            <i class="fas fa-percentage"></i>
                            ${margemLucro.toFixed(2)}% margem
                        </div>
                    </div>
                    
                    <div class="lucratividade-resumo">
                        <div class="lucratividade-item">
                            <div class="lucratividade-valor money">
                                R$ ${parseFloat(venda.valor_total_venda || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                            </div>
                            <div class="lucratividade-label">Valor de Venda</div>
                        </div>
                        
                        <div class="lucratividade-item">
                            <div class="lucratividade-valor negativo">
                                R$ ${parseFloat(venda.valor_total_custo || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                            </div>
                            <div class="lucratividade-label">Custo Total</div>
                        </div>
                        
                        <div class="lucratividade-item">
                            <div class="lucratividade-valor ${(venda.lucro_total_geral || 0) >= 0 ? 'positivo' : 'negativo'}">
                                R$ ${parseFloat(venda.lucro_total_geral || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                            </div>
                            <div class="lucratividade-label">Lucro Total</div>
                        </div>
                        
                        <div class="lucratividade-item">
                            <div class="lucratividade-valor ${(venda.margem_lucro_geral || 0) >= 0 ? 'positivo' : 'negativo'}">
                                ${(venda.margem_lucro_geral || 0).toFixed(2)}%
                            </div>
                            <div class="lucratividade-label">Margem de Lucro</div>
                        </div>
                    </div>
                </div>

                <!-- Informações Básicas -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-info-circle"></i>
                        Informações Básicas
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Número da Venda</div>
                                <div class="detail-value highlight">${venda.numero || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Nota Fiscal</div>
                                <div class="detail-value highlight">${venda.nf || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">
                                    <span class="status-badge ${(venda.classificacao || '').toLowerCase()}">
                                        <i class="fas fa-${getStatusIcon(venda.classificacao)}"></i>
                                        ${venda.classificacao || 'Não definido'}
                                    </span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status de Pagamento</div>
                                <div class="detail-value">
                                    <span class="status-badge ${venda.status_pagamento === 'Recebido' ? 'recebido' : 'nao-recebido'}">
                                        <i class="fas fa-${venda.status_pagamento === 'Recebido' ? 'check-circle' : 'clock'}"></i>
                                        ${venda.status_pagamento || 'Não Recebido'}
                                    </span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data da Venda</div>
                                <div class="detail-value highlight">${dataVendaDisplay}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data de Vencimento</div>
                                <div class="detail-value highlight">${dataVencimentoDisplay}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status de Prazo</div>
                                <div class="detail-value">
                                    ${emAtraso ? 
                                        `<span class="atraso-indicator em-atraso"><i class="fas fa-exclamation-triangle"></i> ${diasAtraso} dias de atraso</span>` :
                                        `<span class="atraso-indicator no-prazo"><i class="fas fa-check-circle"></i> No prazo</span>`
                                    }
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data de Cadastro</div>
                                <div class="detail-value">${dataFormatada}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Pregão</div>
                                <div class="detail-value">${venda.pregao || 'Não informado'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Valor Total</div>
                                <div class="detail-value money">R$ ${parseFloat(venda.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Informações do Cliente -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-building"></i>
                        Informações do Cliente
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Nome do Cliente</div>
                                <div class="detail-value highlight">${venda.cliente_nome || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">UASG</div>
                                <div class="detail-value">${venda.cliente_uasg || 'N/A'}</div>
                            </div>
                            ${venda.cliente_info ? `
                            <div class="detail-item">
                                <div class="detail-label">Endereço</div>
                                <div class="detail-value">${venda.cliente_info.endereco || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Telefone</div>
                                <div class="detail-value">${venda.cliente_info.telefone || 'N/A'}</div>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                </div>

                <!-- Produtos da Venda -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-shopping-cart"></i>
                        Produtos da Venda
                    </div>
                    <div class="detail-content">
                        <div id="produtosContainer">
                            ${renderProdutosVenda(venda.produtos || [])}
                        </div>
                    </div>
                </div>

                <!-- Logística e Observações -->
                ${venda.transportadora_nome || venda.observacao ? `
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-truck"></i>
                        Logística e Observações
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            ${venda.transportadora_nome ? `
                            <div class="detail-item">
                                <div class="detail-label">Transportadora</div>
                                <div class="detail-value">${venda.transportadora_nome}</div>
                            </div>
                            ` : ''}
                            ${venda.observacao ? `
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Observações</div>
                                <div class="detail-value">${venda.observacao}</div>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                </div>
                ` : ''}

                <!-- Empenho Relacionado -->
                ${venda.empenho_numero ? `
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-file-invoice-dollar"></i>
                        Empenho Relacionado
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Número do Empenho</div>
                                <div class="detail-value highlight">
                                    <a href="consulta_empenho.php?empenho_id=${venda.empenho_id}" target="_blank" style="color: var(--secondary-color); text-decoration: none;">
                                        <i class="fas fa-external-link-alt"></i>
                                        ${venda.empenho_numero}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                ` : ''}
            </div>
        </div>
    `;

    // Adiciona event listener para o formulário de edição
    const editForm = document.getElementById('vendaEditForm');
    if (editForm) {
        editForm.addEventListener('submit', salvarEdicaoVenda);
    }
    
    console.log('✅ Detalhes da venda renderizados com sucesso');
}

/**
 * Renderiza os produtos da venda
 */
function renderProdutosVenda(produtos) {
    if (!produtos || produtos.length === 0) {
        return `
            <div style="text-align: center; padding: 3rem; color: var(--medium-gray); background: var(--light-gray); border-radius: var(--radius-sm); border: 2px dashed var(--border-color);">
                <i class="fas fa-shopping-cart" style="font-size: 3rem; margin-bottom: 1rem; color: var(--secondary-color);"></i>
                <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">Nenhum produto cadastrado</p>
                <small>Esta venda não possui produtos registrados</small>
            </div>
        `;
    }

    let html = `
        <div class="produtos-table-container" style="max-height: 400px; overflow-y: auto;">
            <table class="produtos-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: var(--secondary-color); color: white; position: sticky; top: 0;">
                        <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Produto</th>
                        <th style="padding: 0.75rem; text-align: center;">Qtd</th>
                        <th style="padding: 0.75rem; text-align: right;">Valor Unit.</th>
                        <th style="padding: 0.75rem; text-align: right;">Valor Total</th>
                    </tr>
                </thead>
                <tbody>
    `;

    let valorTotalGeral = 0;
    let quantidadeTotalItens = 0;

    produtos.forEach((produto, index) => {
        // Validação dos valores para evitar erros
        const valorUnitario = parseFloat(produto.valor_unitario) || 0;
        const quantidade = parseInt(produto.quantidade) || 0;
        const valorTotal = parseFloat(produto.valor_total) || 0;
        
        valorTotalGeral += valorTotal;
        quantidadeTotalItens += quantidade;
        
        html += `
            <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;" 
                onmouseover="this.style.background='var(--light-gray)'" 
                onmouseout="this.style.background='white'">
                <td style="padding: 0.75rem;">
                    <div>
                        <strong style="color: var(--primary-color);">
                            ${produto.produto_nome || 'Produto sem nome'}
                        </strong>
                        ${produto.produto_codigo ? `<br><small style="color: var(--medium-gray);"><i class="fas fa-barcode"></i> ${produto.produto_codigo}</small>` : ''}
                        ${produto.produto_categoria ? `<br><small style="color: var(--info-color);"><i class="fas fa-tag"></i> ${produto.produto_categoria}</small>` : ''}
                        ${produto.produto_observacao ? `<br><small style="color: var(--medium-gray);">${produto.produto_observacao}</small>` : ''}
                    </div>
                </td>
                <td style="padding: 0.75rem; text-align: center;">
                    <span style="background: var(--info-color); color: white; padding: 0.25rem 0.5rem; border-radius: 12px; font-weight: 600;">
                        ${quantidade.toLocaleString('pt-BR')}
                    </span>
                    ${produto.produto_unidade ? `<br><small style="color: var(--medium-gray);">${produto.produto_unidade}</small>` : ''}
                </td>
                <td style="padding: 0.75rem; text-align: right; font-weight: 600; color: var(--success-color);">
                    R$ ${valorUnitario.toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                </td>
                <td style="padding: 0.75rem; text-align: right; font-weight: 700; color: var(--primary-color);">
                    R$ ${valorTotal.toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
                <tfoot>
                    <tr style="background: var(--light-gray); font-weight: 700;">
                        <td style="padding: 1rem; text-align: left;">
                            <strong>TOTAL (${produtos.length} produtos, ${quantidadeTotalItens} itens):</strong>
                        </td>
                        <td style="padding: 1rem; text-align: center;">
                            <strong>${quantidadeTotalItens}</strong>
                        </td>
                        <td style="padding: 1rem; text-align: right;">
                            <strong>-</strong>
                        </td>
                        <td style="padding: 1rem; text-align: right; color: var(--primary-color); font-size: 1.1rem;">
                            <strong>R$ ${valorTotalGeral.toLocaleString('pt-BR', {minimumFractionDigits: 2})}</strong>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    `;

    return html;
}

/**
 * Fecha o modal
 */
function closeModal() {
    // Verifica se está em modo de edição
    const editForm = document.getElementById('vendaEditForm');
    const isEditing = editForm && editForm.style.display !== 'none';
    
    if (isEditing) {
        const confirmClose = confirm(
            'Você está editando a venda.\n\n' +
            'Tem certeza que deseja fechar sem salvar as alterações?\n\n' +
            'As alterações não salvas serão perdidas.'
        );
        
        if (!confirmClose) {
            return; // Não fecha o modal
        }
    }
    
    // Fecha o modal
    const modal = document.getElementById('vendaModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Limpa dados
    currentVendaId = null;
    currentVendaData = null;
    isEditingVenda = false;
    
    // Reseta o modal para o próximo uso
    resetModalState();
    
    console.log('✅ Modal fechado');
}

// ===========================================
// FUNÇÕES DE EDIÇÃO DA VENDA
// ===========================================

/**
 * Ativa o modo de edição da venda
 */
function editarVenda() {
    console.log('🖊️ Ativando modo de edição da venda');
    
    const viewMode = document.getElementById('vendaViewMode');
    const editForm = document.getElementById('vendaEditForm');
    const editarBtn = document.getElementById('editarBtn');
    
    if (viewMode) viewMode.style.display = 'none';
    if (editForm) editForm.style.display = 'block';
    if (editarBtn) editarBtn.style.display = 'none';
    
    isEditingVenda = true;
    
    showToast('Modo de edição ativado', 'info');
}

/**
 * Cancela a edição da venda
 */
function cancelarEdicao() {
    const confirmCancel = confirm(
        'Tem certeza que deseja cancelar a edição?\n\n' +
        'Todas as alterações não salvas serão perdidas.'
    );
    
    if (confirmCancel) {
        const viewMode = document.getElementById('vendaViewMode');
        const editForm = document.getElementById('vendaEditForm');
        const editarBtn = document.getElementById('editarBtn');
        
        if (viewMode) viewMode.style.display = 'block';
        if (editForm) editForm.style.display = 'none';
        if (editarBtn) editarBtn.style.display = 'inline-flex';
        
        isEditingVenda = false;
        
        showToast('Edição cancelada', 'info');
    }
}

/**
 * Salva a edição da venda
 */
function salvarEdicaoVenda(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    const submitBtn = document.getElementById('salvarBtn');
    
    // Desabilita o botão e mostra loading
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    }
    
    fetch('consulta_vendas.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Venda atualizada com sucesso!', 'success');
            
            // Recarrega os dados do modal
            setTimeout(() => {
                openModal(currentVendaId);
            }, 1000);
            
        } else {
            throw new Error(data.error || 'Erro ao salvar venda');
        }
    })
    .catch(error => {
        console.error('Erro ao salvar venda:', error);
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
    if (!currentVendaData) return;
    
    const confirmMessage = 
        `⚠️ ATENÇÃO: EXCLUSÃO PERMANENTE ⚠️\n\n` +
        `Tem certeza que deseja EXCLUIR permanentemente esta venda?\n\n` +
        `NF: ${currentVendaData.nf || 'N/A'}\n` +
        `Cliente: ${currentVendaData.cliente_nome || 'N/A'}\n` +
        `Valor: R$ ${parseFloat(currentVendaData.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}\n\n` +
        `⚠️ Esta ação NÃO PODE ser desfeita!\n` +
        `⚠️ Todos os produtos relacionados também serão excluídos!\n\n` +
        `Digite "CONFIRMAR" para prosseguir:`;
    
    const confirmacao = prompt(confirmMessage);
    
    if (confirmacao === 'CONFIRMAR') {
        excluirVenda();
    } else if (confirmacao !== null) {
        showToast('Exclusão cancelada - confirmação incorreta', 'warning');
    }
}

// ===========================================
// FUNÇÕES DE AÇÃO DA VENDA
// ===========================================

/**
 * Exclui venda
 */
function excluirVenda() {
    if (!currentVendaId) return;
    
    const excluirBtn = document.getElementById('excluirBtn') || document.getElementById('excluirEdicaoBtn');
    if (excluirBtn) {
        excluirBtn.disabled = true;
        excluirBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';
    }
    
    fetch('consulta_vendas.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `delete_venda_id=${currentVendaId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Venda excluída com sucesso!', 'success');
            
            // Fecha o modal
            closeModal();
            
            // Recarrega a página após um breve delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
            
        } else {
            throw new Error(data.error || 'Erro ao excluir venda');
        }
    })
    .catch(error => {
        console.error('Erro ao excluir venda:', error);
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
    if (!currentVendaData) return;
    
    const confirmMessage = 
        `⚠️ ATENÇÃO: EXCLUSÃO PERMANENTE ⚠️\n\n` +
        `Tem certeza que deseja EXCLUIR permanentemente esta venda?\n\n` +
        `NF: ${currentVendaData.nf || 'N/A'}\n` +
        `Cliente: ${currentVendaData.cliente_nome || 'N/A'}\n` +
        `Valor: R$ ${parseFloat(currentVendaData.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}\n\n` +
        `⚠️ Esta ação NÃO PODE ser desfeita!\n` +
        `⚠️ Todos os produtos relacionados também serão excluídos!\n\n` +
        `Digite "CONFIRMAR" para prosseguir:`;
    
    const confirmacao = prompt(confirmMessage);
    
    if (confirmacao === 'CONFIRMAR') {
        excluirVenda();
    } else if (confirmacao !== null) {
        showToast('Exclusão cancelada - confirmação incorreta', 'warning');
    }
}

/**
 * Imprime venda
 */
function imprimirVenda() {
    if (!currentVendaId) return;
    
    const printUrl = `imprimir_venda.php?id=${currentVendaId}`;
    window.open(printUrl, '_blank', 'width=800,height=600');
}

// ===========================================
// FUNÇÕES DE CLASSIFICAÇÃO E FILTROS
// ===========================================

/**
 * Atualiza classificação da venda via AJAX
 */
function updateClassificacao(selectElement) {
    const vendaId = selectElement.dataset.vendaId;
    const novaClassificacao = selectElement.value;
    const classificacaoAnterior = selectElement.dataset.valorAnterior || selectElement.defaultValue;
    
    if (!novaClassificacao) {
        selectElement.value = classificacaoAnterior;
        return;
    }
    
    // Armazena valor anterior
    selectElement.dataset.valorAnterior = classificacaoAnterior;
    
    // Desabilita o select temporariamente
    selectElement.disabled = true;
    selectElement.style.opacity = '0.6';
    
    fetch('consulta_vendas.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `update_classificacao=1&venda_id=${vendaId}&classificacao=${encodeURIComponent(novaClassificacao)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(`Classificação atualizada para "${novaClassificacao}"`, 'success');
            
            // Atualiza o visual do select
            selectElement.dataset.valorAnterior = novaClassificacao;
            selectElement.setAttribute('value', novaClassificacao);
            
            // Aplica a cor correspondente à nova classificação
            selectElement.className = `classificacao-select ${novaClassificacao.toLowerCase()}`;
            
        } else {
            throw new Error(data.error || 'Erro ao atualizar classificação');
        }
    })
    .catch(error => {
        console.error('Erro ao atualizar classificação:', error);
        showToast('Erro: ' + error.message, 'error');
        
        // Reverte o valor
        selectElement.value = classificacaoAnterior;
    })
    .finally(() => {
        selectElement.disabled = false;
        selectElement.style.opacity = '1';
    });
}

/**
 * Atualiza status de pagamento da venda via AJAX
 */
function updateStatusPagamento(selectElement) {
    const vendaId = selectElement.dataset.vendaId;
    const novoStatus = selectElement.value;
    const statusAnterior = selectElement.dataset.valorAnterior || selectElement.defaultValue;
    
    if (!novoStatus) {
        selectElement.value = statusAnterior;
        return;
    }
    
    // Armazena valor anterior
    selectElement.dataset.valorAnterior = statusAnterior;
    
    // Desabilita o select temporariamente
    selectElement.disabled = true;
    selectElement.style.opacity = '0.6';
    
    fetch('consulta_vendas.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `update_status_pagamento=1&venda_id=${vendaId}&status_pagamento=${encodeURIComponent(novoStatus)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(`Status de pagamento atualizado para "${novoStatus}"`, 'success');
            
            // Atualiza o visual do select
            selectElement.dataset.valorAnterior = novoStatus;
            selectElement.setAttribute('value', novoStatus);
            
            // Aplica a cor correspondente ao novo status
            selectElement.className = `status-select ${novoStatus.toLowerCase().replace(' ', '-')}`;
            
        } else {
            throw new Error(data.error || 'Erro ao atualizar status');
        }
    })
    .catch(error => {
        console.error('Erro ao atualizar status:', error);
        showToast('Erro: ' + error.message, 'error');
        
        // Reverte o valor
        selectElement.value = statusAnterior;
    })
    .finally(() => {
        selectElement.disabled = false;
        selectElement.style.opacity = '1';
    });
}

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
    let url = 'consulta_vendas.php?';
    
    switch(tipo) {
        case 'atraso':
            // Implementar filtro para vendas em atraso
            showToast('Filtrando vendas em atraso...', 'info');
            url += 'filtro=atraso';
            break;
        case 'pendente':
            url += 'status_pagamento=Não Recebido';
            break;
        case 'Pendente':
        case 'Faturada':
        case 'Comprada':
        case 'Entregue':
        case 'Liquidada':
        case 'Devolucao':
            url += `classificacao=${encodeURIComponent(tipo)}`;
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
 * Obtém ícone para status/classificação
 */
function getStatusIcon(status) {
    const icons = {
        'Pendente': 'clock',
        'Faturada': 'file-invoice-dollar',
        'Comprada': 'shopping-cart',
        'Entregue': 'truck',
        'Liquidada': 'check-circle',
        'Devolucao': 'undo'
    };
    return icons[status] || 'tag';
}

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

/**
 * Formata valor monetário
 */
function formatarMoeda(valor) {
    return 'R$ ' + parseFloat(valor || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

/**
 * Formata data
 */
function formatarData(data) {
    if (!data || data === '0000-00-00') return 'N/A';
    
    try {
        const date = new Date(data);
        return date.toLocaleDateString('pt-BR');
    } catch {
        return 'Data inválida';
    }
}

/**
 * Valida e sanitiza dados da venda
 */
function validarDadosVenda(venda) {
    // Garante que campos numéricos sejam números válidos
    venda.dias_atraso = parseInt(venda.dias_atraso) || 0;
    venda.valor_total = parseFloat(venda.valor_total) || 0;
    venda.valor_total_venda = parseFloat(venda.valor_total_venda) || 0;
    venda.valor_total_custo = parseFloat(venda.valor_total_custo) || 0;
    venda.lucro_total_geral = parseFloat(venda.lucro_total_geral) || 0;
    venda.margem_lucro_geral = parseFloat(venda.margem_lucro_geral) || 0;
    
    // Garante que campos booleanos sejam boolean
    venda.em_atraso = Boolean(venda.em_atraso);
    
    // Garante que campos de string não sejam null
    venda.nf = venda.nf || '';
    venda.numero = venda.numero || '';
    venda.cliente_nome = venda.cliente_nome || '';
    venda.cliente_uasg = venda.cliente_uasg || '';
    venda.classificacao = venda.classificacao || 'Pendente';
    venda.status_pagamento = venda.status_pagamento || 'Não Recebido';
    venda.observacao = venda.observacao || '';
    venda.pregao = venda.pregao || '';
    venda.transportadora_nome = venda.transportadora_nome || '';
    
    // Garante que arrays existam
    venda.produtos = venda.produtos || [];
    
    return venda;
}

// ===========================================
// INICIALIZAÇÃO E EVENT LISTENERS
// ===========================================

/**
 * Inicialização quando a página carrega
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 LicitaSis - Sistema de Consulta de Vendas carregado');
    
    // Inicializa event listeners para classificação
    document.querySelectorAll('.classificacao-select').forEach(select => {
        select.addEventListener('change', function() {
            updateClassificacao(this);
        });
        
        // Armazena valor inicial
        select.dataset.valorAnterior = select.value;
    });

    // Inicializa event listeners para status de pagamento
    document.querySelectorAll('.status-select').forEach(select => {
        select.addEventListener('change', function() {
            updateStatusPagamento(this);
        });
        
        // Armazena valor inicial
        select.dataset.valorAnterior = select.value;
    });
    
    // Event listener para fechar modal com ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const modal = document.getElementById('vendaModal');
            
            if (modal && modal.style.display === 'block') {
                closeModal();
            }
        }
    });
    
    // Event listener para clicar fora do modal
    const modal = document.getElementById('vendaModal');
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
</script>
</body>
</html>