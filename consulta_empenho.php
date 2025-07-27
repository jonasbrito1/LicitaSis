<?php
// ===========================================
// CONSULTA DE EMPENHOS - LICITASIS (CÓDIGO CORRIGIDO)
// Sistema Completo de Gestão de Licitações com Produtos
// Versão: 7.1 Corrigida - Todas as funcionalidades implementadas
// ===========================================

// Inicia buffer para capturar output indesejado
// Detecção mais robusta de requisições AJAX
$isAjaxRequest = (
    $_SERVER['REQUEST_METHOD'] == 'POST' && 
    (
        isset($_POST['update_empenho']) || 
        isset($_POST['update_classificacao']) || 
        isset($_POST['delete_empenho_id']) ||
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    )
);

// Se for requisição AJAX, configura headers e limpa buffer
if ($isAjaxRequest) {
    // Limpa qualquer output anterior
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Inicia novo buffer limpo
    ob_start();
    
    // Define headers JSON obrigatórios
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    // Desabilita qualquer output HTML
    ini_set('html_errors', 0);
} else {
    // Para requisições normais, inicia buffer normal
    ob_start();
}

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Includes necessários
include('db.php');
include('permissions.php');
include('includes/audit.php');

// Inicialização do sistema de permissões
$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('empenhos', 'read');
logUserAction('READ', 'empenhos_consulta');

// Variáveis globais
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';
$error = "";
$success = "";
$empenhos = [];
$searchTerm = "";
$classificacaoFilter = "";

// Configuração da paginação
$itensPorPagina = 20;
$paginaAtual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($paginaAtual - 1) * $itensPorPagina;

// ===========================================
// PROCESSAMENTO AJAX - ATUALIZAÇÃO COMPLETA DO EMPENHO - CORRIGIDO
// ===========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_empenho'])) {
    
    if (!$permissionManager->hasPagePermission('empenhos', 'edit')) {
        echo json_encode(['error' => 'Sem permissão para editar empenhos'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    $response = ['success' => false];
    
    try {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            throw new Exception("ID do empenho inválido.");
        }

        $pdo->beginTransaction();
        
        // Busca dados antigos para auditoria
        $stmt_old = $pdo->prepare("SELECT * FROM empenhos WHERE id = ?");
        $stmt_old->execute([$id]);
        $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);

        if (!$old_data) {
            throw new Exception("Empenho não encontrado.");
        }

        // Coleta e sanitiza os dados
        $dados = [
            'numero' => trim(filter_input(INPUT_POST, 'numero', FILTER_SANITIZE_STRING)),
            'cliente_nome' => trim(filter_input(INPUT_POST, 'cliente_nome', FILTER_SANITIZE_STRING)),
            'cliente_uasg' => trim(filter_input(INPUT_POST, 'cliente_uasg', FILTER_SANITIZE_STRING)),
            'pregao' => trim(filter_input(INPUT_POST, 'pregao', FILTER_SANITIZE_STRING)),
            'classificacao' => trim(filter_input(INPUT_POST, 'classificacao', FILTER_SANITIZE_STRING)),
            'prioridade' => trim(filter_input(INPUT_POST, 'prioridade', FILTER_SANITIZE_STRING)),
            'observacao' => trim(filter_input(INPUT_POST, 'observacao', FILTER_SANITIZE_STRING)),
            'cnpj' => trim(filter_input(INPUT_POST, 'cnpj', FILTER_SANITIZE_STRING)),
            'data' => filter_input(INPUT_POST, 'data', FILTER_SANITIZE_STRING),
            'valor_total_empenho' => str_replace(',', '.', filter_input(INPUT_POST, 'valor_total_empenho') ?: '0')
        ];

        // Validações básicas
        $classificacoes_validas = ['Pendente', 'Faturado', 'Entregue', 'Liquidado', 'Pago', 'Cancelado'];
        if (!empty($dados['classificacao']) && !in_array($dados['classificacao'], $classificacoes_validas)) {
            throw new Exception("Classificação inválida.");
        }

        // Converte data para formato MySQL
        if (!empty($dados['data'])) {
            $data_obj = DateTime::createFromFormat('Y-m-d', $dados['data']);
            if (!$data_obj) {
                throw new Exception("Data inválida.");
            }
            $dados['data'] = $data_obj->format('Y-m-d');
        } else {
            $dados['data'] = null;
        }

        // Atualiza o empenho
        $sql = "UPDATE empenhos SET 
                numero = :numero,
                cliente_nome = :cliente_nome,
                cliente_uasg = :cliente_uasg,
                pregao = :pregao,
                classificacao = :classificacao,
                prioridade = :prioridade,
                observacao = :observacao,
                cnpj = :cnpj,
                `data` = :data,
                valor_total_empenho = :valor_total_empenho
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $dados['id'] = $id;
        
        if (!$stmt->execute($dados)) {
            throw new Exception("Erro ao atualizar o empenho no banco de dados.");
        }

        // Processa upload de arquivo se houver
        if (isset($_FILES['upload']) && $_FILES['upload']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/empenhos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileInfo = pathinfo($_FILES['upload']['name']);
            $extension = strtolower($fileInfo['extension']);
            
            if (!in_array($extension, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'])) {
                throw new Exception("Tipo de arquivo não permitido. Use PDF, DOC, DOCX, JPG ou PNG.");
            }

            $newFileName = 'empenho_' . $id . '_' . uniqid() . '.' . $extension;
            $uploadFile = $uploadDir . $newFileName;

            if (!move_uploaded_file($_FILES['upload']['tmp_name'], $uploadFile)) {
                throw new Exception("Erro ao fazer upload do arquivo.");
            }

            $stmt_upload = $pdo->prepare("UPDATE empenhos SET upload = ? WHERE id = ?");
            $stmt_upload->execute([$uploadFile, $id]);
            
            // Remove arquivo anterior se existir
            if (!empty($old_data['upload']) && file_exists($old_data['upload'])) {
                unlink($old_data['upload']);
            }
        }

        if (function_exists('logUserAction')) {
            logUserAction('UPDATE', 'empenhos', $id, [
                'old' => $old_data,
                'new' => $dados
            ]);
        }

        $pdo->commit();
        $response['success'] = true;
        $response['message'] = "Empenho atualizado com sucesso!";
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $response['error'] = $e->getMessage();
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// ===========================================
// PROCESSAMENTO AJAX - ATUALIZAÇÃO DE CLASSIFICAÇÃO
// ===========================================
if (isset($_POST['update_classificacao'])) {
    header('Content-Type: application/json');
    
    $id = $_POST['empenho_id'];
    $classificacao = $_POST['classificacao'];
    $classificacoes_validas = ['Pendente', 'Faturado', 'Entregue', 'Liquidado', 'Pago', 'Cancelado', 'Vendido'];

    
    if (!in_array($classificacao, $classificacoes_validas)) {
        echo json_encode(['error' => 'Classificação inválida']);
        exit();
    }

    try {
        // Busca valor anterior para auditoria
        $stmt_old = $pdo->prepare("SELECT classificacao FROM empenhos WHERE id = :id");
        $stmt_old->bindParam(':id', $id);
        $stmt_old->execute();
        $old_classificacao = $stmt_old->fetchColumn();

        // Atualiza classificação
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

// ===========================================
// PROCESSAMENTO AJAX - OBTER DADOS DO EMPENHO (CORRIGIDO)
// ===========================================
if (isset($_GET['get_empenho_id'])) {
    header('Content-Type: application/json');
    
    try {
        $id = filter_input(INPUT_GET, 'get_empenho_id', FILTER_VALIDATE_INT);
        
        if (!$id) {
            throw new Exception('ID do empenho inválido');
        }
 
        $sql_venda = "SELECT id, numero FROM vendas WHERE empenho_id = :id LIMIT 1";
        $stmt_venda = $pdo->prepare($sql_venda);
        $stmt_venda->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_venda->execute();
        $venda_convertida = $stmt_venda->fetch(PDO::FETCH_ASSOC);
        
        // Consulta principal do empenho - CORRIGIDA para buscar TODOS os campos
        $sql = "SELECT 
                e.id,
                e.numero,
                e.cliente_uasg,
                e.produto,
                e.produto2,
                e.item,
                e.observacao,
                e.pregao,
                e.upload,
                e.pesquisa,
                e.`data`,
                e.prioridade,
                e.created_at,
                e.cliente_nome,
                e.valor_total,
                e.classificacao,
                e.valor_total_empenho,
                e.cnpj,
                CASE 
                    WHEN e.`data` IS NOT NULL AND e.`data` != '0000-00-00' THEN DATE_FORMAT(e.`data`, '%Y-%m-%d')
                    ELSE NULL
                END as data_iso,
                CASE 
                    WHEN e.`data` IS NOT NULL AND e.`data` != '0000-00-00' THEN DATE_FORMAT(e.`data`, '%d/%m/%Y')
                    ELSE 'N/A'
                END as data_formatada,
                DATE_FORMAT(e.created_at, '%d/%m/%Y %H:%i') as data_cadastro_formatada,
                CASE 
                    WHEN e.`data` IS NOT NULL AND e.`data` != '0000-00-00' THEN DATEDIFF(CURDATE(), e.`data`)
                    ELSE DATEDIFF(CURDATE(), DATE(e.created_at))
                END as dias_desde_empenho,
                CASE 
                    WHEN COALESCE(e.classificacao, 'Pendente') IN ('Pendente', 'Faturado') AND 
                         CASE 
                            WHEN e.`data` IS NOT NULL AND e.`data` != '0000-00-00' THEN DATEDIFF(CURDATE(), e.`data`)
                            ELSE DATEDIFF(CURDATE(), DATE(e.created_at))
                         END > 30 THEN 1
                    ELSE 0
                END as em_atraso
                FROM empenhos e 
                WHERE e.id = :id";
                
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $empenho = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$empenho) {
            throw new Exception('Empenho não encontrado');
        }

        // Calcula dias de atraso se estiver em atraso
        $empenho['dias_atraso'] = 0;
        if ($empenho['em_atraso'] && $empenho['dias_desde_empenho'] > 30) {
            $empenho['dias_atraso'] = $empenho['dias_desde_empenho'] - 30;
        }

        // Busca produtos relacionados ao empenho
        // Busca produtos relacionados ao empenho
        $sql_produtos = "SELECT 
            ep.*,
            ep.produto_id,
            COALESCE(p.nome, ep.descricao_produto, 'Produto sem nome') AS produto_nome,
            p.codigo AS produto_codigo,
            p.observacao AS produto_observacao,
            p.categoria AS produto_categoria,
            p.unidade AS produto_unidade,
            COALESCE(p.custo_total, 0) AS custo_unitario,
            COALESCE(p.preco_unitario, ep.valor_unitario, 0) AS preco_unitario_produto,
            COALESCE(p.preco_venda, p.preco_unitario, ep.valor_unitario, 0) AS preco_venda,
            p.margem_lucro,
            COALESCE(p.total_impostos, 0) as total_impostos,
            p.icms, p.irpj, p.cofins, p.csll, p.pis_pasep, p.ipi,
            p.estoque_atual, p.controla_estoque
            FROM empenho_produtos ep 
            LEFT JOIN produtos p ON ep.produto_id = p.id 
            WHERE ep.empenho_id = :id
            ORDER BY ep.id";
        
        $stmt_produtos = $pdo->prepare($sql_produtos);
        $stmt_produtos->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_produtos->execute();
        $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

        // CÁLCULO SIMPLIFICADO DA LUCRATIVIDADE - CORRIGIDO
        $valor_total_venda = 0;
        $valor_total_custo = 0;
        
        foreach ($produtos as &$produto) {
            $quantidade = floatval($produto['quantidade'] ?? 0);
            $valor_unitario_venda = floatval($produto['valor_unitario'] ?? 0);
            $custo_unitario = floatval($produto['custo_unitario'] ?? 0);
            
            // Cálculos simples por produto
            $valor_venda_produto = $quantidade * $valor_unitario_venda;
            $valor_custo_produto = $quantidade * $custo_unitario;
            
            // Lucro simples = Venda - Custo
            $lucro_produto = $valor_venda_produto - $valor_custo_produto;
            
            // Margem de lucro
            $margem_lucro = $valor_venda_produto > 0 ? ($lucro_produto / $valor_venda_produto) * 100 : 0;
            
            // Adiciona informações calculadas ao produto
            $produto['valor_venda_total'] = $valor_venda_produto;
            $produto['valor_custo_total'] = $valor_custo_produto;
            $produto['lucro_total'] = $lucro_produto;
            $produto['margem_lucro'] = $margem_lucro;
            
            // Totalizadores
            $valor_total_venda += $valor_venda_produto;
            $valor_total_custo += $valor_custo_produto;
        }

        // Cálculos gerais do empenho - SIMPLIFICADOS
        $lucro_total_geral = $valor_total_venda - $valor_total_custo;
        $margem_lucro_geral = $valor_total_venda > 0 ? ($lucro_total_geral / $valor_total_venda) * 100 : 0;

        // Busca informações do cliente se UASG estiver preenchida
        $cliente_info = null;
        if (!empty($empenho['cliente_uasg'])) {
            $sql_cliente = "SELECT * FROM clientes WHERE uasg = :uasg LIMIT 1";
            $stmt_cliente = $pdo->prepare($sql_cliente);
            $stmt_cliente->bindParam(':uasg', $empenho['cliente_uasg']);
            $stmt_cliente->execute();
            $cliente_info = $stmt_cliente->fetch(PDO::FETCH_ASSOC);
        }

        // Monta resposta completa
        $response = [
            // Dados básicos do empenho
            'id' => $empenho['id'],
            'numero' => $empenho['numero'] ?? '',
            'cliente_nome' => $empenho['cliente_nome'] ?? '',
            'cliente_uasg' => $empenho['cliente_uasg'] ?? '',
            'pregao' => $empenho['pregao'] ?? '',
            'classificacao' => $empenho['classificacao'] ?? 'Pendente',
            'prioridade' => $empenho['prioridade'] ?? 'Normal',
            'observacao' => $empenho['observacao'] ?? '',
            'cnpj' => $empenho['cnpj'] ?? '',
            'data' => $empenho['data_iso'], // Para o input date
            'data_formatada' => $empenho['data_formatada'],
            'data_cadastro' => $empenho['data_cadastro_formatada'],
            'created_at' => $empenho['created_at'],
            'valor_total_empenho' => floatval($empenho['valor_total_empenho'] ?? $empenho['valor_total'] ?? 0),
            'valor_total' => floatval($empenho['valor_total'] ?? 0),
            'upload' => $empenho['upload'],
            'venda_convertida' => $venda_convertida,
            'ja_convertido' => (bool)$venda_convertida,
            // Produtos
            'produtos' => $produtos,
            
            // Cálculos de lucratividade SIMPLIFICADOS
            'valor_total_venda' => $valor_total_venda,
            'valor_total_custo' => $valor_total_custo,
            'lucro_total_geral' => $lucro_total_geral,
            'margem_lucro_geral' => $margem_lucro_geral,
            
            // Informações de prazo
            'dias_desde_empenho' => $empenho['dias_desde_empenho'],
            'em_atraso' => (bool)$empenho['em_atraso'],
            'dias_atraso' => $empenho['dias_atraso'],
            
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
// PROCESSAMENTO AJAX - EXCLUSÃO DE EMPENHO
// ===========================================
if (isset($_POST['delete_empenho_id'])) { 
    header('Content-Type: application/json');
    
    if (!$permissionManager->hasPagePermission('empenhos', 'delete')) {
        echo json_encode(['error' => 'Sem permissão para excluir empenhos']);
        exit();
    }
    
    $id = $_POST['delete_empenho_id'];

    try {
        $pdo->beginTransaction();

        // Busca dados do empenho para auditoria
        $stmt_empenho = $pdo->prepare("SELECT * FROM empenhos WHERE id = :id");
        $stmt_empenho->bindParam(':id', $id);
        $stmt_empenho->execute();
        $empenho_data = $stmt_empenho->fetch(PDO::FETCH_ASSOC);

        if (!$empenho_data) {
            throw new Exception("Empenho não encontrado.");
        }

        // Exclui produtos do empenho
        $sql = "DELETE FROM empenho_produtos WHERE empenho_id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        // Exclui o empenho
        $sql = "DELETE FROM empenhos WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new Exception("Nenhum empenho foi excluído. Verifique se o ID está correto.");
        }

        $stmt_venda = $pdo->prepare("SELECT id FROM vendas WHERE empenho_id = :id");
        $stmt_venda->bindParam(':id', $id);
        $stmt_venda->execute();
         if ($stmt_venda->rowCount() > 0) {
            throw new Exception("Não é possível excluir este empenho pois já foi convertido em venda. Use a função de reversão se necessário.");
        }

        $pdo->commit();
        
        // Registra auditoria
        logUserAction('DELETE', 'empenhos', $id, $empenho_data);
        
        echo json_encode(['success' => true, 'message' => 'Empenho excluído com sucesso!']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => 'Erro ao excluir o empenho: ' . $e->getMessage()]);
    }
    exit();
}

// ===========================================
// CONSULTA PRINCIPAL COM FILTROS E PAGINAÇÃO (CORRIGIDA)
// ===========================================
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$classificacaoFilter = isset($_GET['classificacao']) ? trim($_GET['classificacao']) : '';

// Determina ordenação ANTES da construção da query
$ordenarPor = isset($_GET['ordenar']) ? trim($_GET['ordenar']) : 'id';
$direcao = isset($_GET['direcao']) && $_GET['direcao'] === 'desc' ? 'DESC' : 'ASC';

$camposOrdenacao = [
    'numero' => 'e.numero',
    'cliente' => 'e.cliente_nome', 
    'valor' => 'e.valor_total_empenho',
    'lucro' => 'lucro_total_valor',
    'prioridade' => 'e.prioridade',
    'classificacao' => 'e.classificacao',
    'data' => 'e.data',
    'status' => 'em_atraso',
    'margem' => 'margem_lucro_percentual',
    'id' => 'e.id'
];

$campoOrdenacao = isset($camposOrdenacao[$ordenarPor]) ? $camposOrdenacao[$ordenarPor] : 'e.id';

// Se não há ordenação específica, usa padrão
if ($ordenarPor === 'id') {
    $direcao = 'DESC'; // Mais recentes primeiro
}

try {
    // Parâmetros para consulta
    $params = [];
    $whereConditions = [];
    
    // Condições de filtro
    if (!empty($searchTerm)) {
        $whereConditions[] = "(e.numero LIKE :searchTerm OR COALESCE(e.cliente_nome, '') LIKE :searchTerm OR COALESCE(e.cliente_uasg, '') LIKE :searchTerm)";
        $params[':searchTerm'] = "%$searchTerm%";
    }
    
    if (!empty($classificacaoFilter)) {
        $whereConditions[] = "COALESCE(e.classificacao, 'Pendente') = :classificacao";
        $params[':classificacao'] = $classificacaoFilter;
    }
    
    $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

    // Consulta para contar total de registros
    $sqlCount = "SELECT COUNT(*) as total FROM empenhos e $whereClause";
    $stmtCount = $pdo->prepare($sqlCount);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $totalRegistros = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPaginas = ceil($totalRegistros / $itensPorPagina);

    // Consulta principal com paginação - CORRIGIDA
    $sql = "SELECT 
        e.id,
        COALESCE(e.numero, '') as numero, 
        COALESCE(e.cliente_nome, 'Cliente não informado') as cliente_nome, 
        COALESCE(e.valor_total_empenho, e.valor_total, 0) as valor_total_empenho, 
        COALESCE(e.classificacao, 'Pendente') as classificacao, 
        e.created_at, 
        COALESCE(e.pregao, '') as pregao,
        e.`data`,
        COALESCE(e.cliente_uasg, '') as cliente_uasg,
        COALESCE(e.observacao, '') as observacao,
        COALESCE(e.prioridade, 'Normal') as prioridade,
        
        -- Cálculo do lucro total do empenho
        COALESCE((
            SELECT SUM(
                (ep.quantidade * COALESCE(ep.valor_unitario, 0)) - 
                (ep.quantidade * COALESCE(p.custo_total, 0))
            )
            FROM empenho_produtos ep 
            LEFT JOIN produtos p ON ep.produto_id = p.id 
            WHERE ep.empenho_id = e.id
        ), 0) as lucro_total_valor,
        
        -- Cálculo da margem de lucro
        CASE 
            WHEN COALESCE((
                SELECT SUM(ep.quantidade * COALESCE(ep.valor_unitario, 0))
                FROM empenho_produtos ep 
                WHERE ep.empenho_id = e.id
            ), 0) > 0 THEN 
            (
                COALESCE((
                    SELECT SUM(
                        (ep.quantidade * COALESCE(ep.valor_unitario, 0)) - 
                        (ep.quantidade * COALESCE(p.custo_total, 0))
                    )
                    FROM empenho_produtos ep 
                    LEFT JOIN produtos p ON ep.produto_id = p.id 
                    WHERE ep.empenho_id = e.id
                ), 0) / 
                COALESCE((
                    SELECT SUM(ep.quantidade * COALESCE(ep.valor_unitario, 0))
                    FROM empenho_produtos ep 
                    WHERE ep.empenho_id = e.id
                ), 1)
            ) * 100
            ELSE 0
        END as margem_lucro_percentual,
        
        CASE 
            WHEN e.`data` IS NOT NULL AND e.`data` != '0000-00-00' THEN DATEDIFF(CURDATE(), e.`data`)
            ELSE DATEDIFF(CURDATE(), DATE(e.created_at))
        END as dias_desde_empenho,
        CASE 
            WHEN COALESCE(e.classificacao, 'Pendente') IN ('Pendente', 'Faturado') AND 
                 CASE 
                    WHEN e.`data` IS NOT NULL AND e.`data` != '0000-00-00' THEN DATEDIFF(CURDATE(), e.`data`)
                    ELSE DATEDIFF(CURDATE(), DATE(e.created_at))
                 END > 30 THEN 1
            ELSE 0
        END as em_atraso,
        CASE 
            WHEN e.`data` IS NOT NULL AND e.`data` != '0000-00-00' THEN DATE_FORMAT(e.`data`, '%d/%m/%Y')
            ELSE 'N/A'
        END as data_formatada
    FROM empenhos e 
    $whereClause
    ORDER BY {$campoOrdenacao} {$direcao}
    LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $itensPorPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $empenhos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Erro na consulta: " . $e->getMessage();
    $empenhos = [];
}

function getClassificacaoIcon($classificacao) {
    $icons = [
        'Pendente' => 'clock',
        'Faturado' => 'file-invoice-dollar',
        'Entregue' => 'truck',
        'Liquidado' => 'calculator',
        'Pago' => 'check-circle',
        'Cancelado' => 'times-circle',
        'Vendido' => 'shopping-cart'
    ];
    return $icons[$classificacao] ?? 'tag';
}


// ===========================================
// CÁLCULO DE ESTATÍSTICAS (CORRIGIDO)
// ===========================================
try {
    // Valor total geral
    $sqlTotal = "SELECT SUM(COALESCE(valor_total_empenho, valor_total, 0)) AS total_geral FROM empenhos";
    $stmtTotal = $pdo->prepare($sqlTotal);
    $stmtTotal->execute();
    $totalGeral = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total_geral'] ?? 0;
    
    // Estatísticas por classificação
    $sqlStats = "SELECT 
                    COALESCE(classificacao, 'Pendente') as classificacao, 
                    COUNT(*) as quantidade, 
                    SUM(COALESCE(valor_total_empenho, valor_total, 0)) as valor_total 
                 FROM empenhos 
                 GROUP BY COALESCE(classificacao, 'Pendente')
                 ORDER BY quantidade DESC";
    $stmtStats = $pdo->prepare($sqlStats);
    $stmtStats->execute();
    $estatisticas = $stmtStats->fetchAll(PDO::FETCH_ASSOC);
    
    // Empenhos em atraso - CORRIGIDO
    $sqlAtrasos = "SELECT COUNT(*) as empenhos_atrasados 
                   FROM empenhos 
                   WHERE COALESCE(classificacao, 'Pendente') IN ('Pendente', 'Faturado') AND 
                         CASE 
                             WHEN `data` IS NOT NULL AND `data` != '0000-00-00' THEN DATEDIFF(CURDATE(), `data`)
                             ELSE DATEDIFF(CURDATE(), DATE(created_at))
                         END > 30";
    $stmtAtrasos = $pdo->prepare($sqlAtrasos);
    $stmtAtrasos->execute();
    $empenhosAtrasados = $stmtAtrasos->fetch(PDO::FETCH_ASSOC)['empenhos_atrasados'] ?? 0;
    
} catch (PDOException $e) {
    $error = "Erro ao calcular estatísticas: " . $e->getMessage();
    $totalGeral = 0;
    $estatisticas = [];
    $empenhosAtrasados = 0;
}

// Inclui o header do sistema
include('includes/header_template.php');
renderHeader("Consulta de Empenhos - LicitaSis", "empenhos");
?>


<!DOCTYPE html>
<html lang="pt-BR">
<script src="produtos-autocomplete.js"></script>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Consulta de Empenhos - LicitaSis</title>
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
            --faturado-color: #007bff;
            --entregue-color: #20c997;
            --liquidado-color: #6f42c1;
            --pago-color: #28a745;
            --cancelado-color: #dc3545;
             --vendido-color: #17a2b8;
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

        .stat-item.atraso {
            border-left: 4px solid var(--danger-color);
        }

        .stat-item.atraso .stat-number {
            color: var(--danger-color);
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

        /* Estatísticas navegáveis */
        .stat-navegavel {
            cursor: pointer;
            border: 2px solid transparent;
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
            transform: translateY(-8px) scale(1.08);
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
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

        .stat-nav-text {
            position: absolute;
            bottom: 0.5rem;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.75rem;
            color: var(--secondary-color);
            opacity: 0;
            transition: var(--transition);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-navegavel:hover .stat-nav-text {
            opacity: 1;
            transform: translateX(-50%) translateY(-2px);
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
           INDICADORES DE LUCRO (VALOR)
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

        /* Estados visuais por classificação */
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
        .classificacao-select[value="Vendido"] { 
            background: rgba(23, 162, 184, 0.1); 
            color: var(--info-color);
            border-color: var(--info-color);
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
           BADGES DE MARGEM DE LUCRO
           =========================================== */
        .margem-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            min-width: 80px;
            justify-content: center;
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
            background: rgba(23, 162, 184, 0.1);
            color: var(--info-color);
            border: 1px solid var(--info-color);
        }

        .margem-badge.neutra {
            background: rgba(108, 117, 125, 0.1);
            color: var(--medium-gray);
            border: 1px solid var(--medium-gray);
        }

        /* ===========================================
           SEÇÕES DE DETALHES DO MODAL
           =========================================== */
        .empenho-details {
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
           GESTÃO DE PRODUTOS NO MODAL
           =========================================== */
        .produtos-header {
            animation: slideInDown 0.3s ease;
        }

        .produtos-table-container {
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .produtos-table {
            margin: 0;
            width: 100%;
            border-collapse: collapse;
        }

        .produtos-table tbody tr:hover {
            background: rgba(0, 191, 174, 0.05) !important;
            transform: translateX(2px);
        }

        .btn-action {
            transition: all 0.2s ease;
            border: none;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
        }

        .btn-action:hover {
            transform: scale(1.1);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .btn-edit {
            background: var(--warning-color);
            color: white;
        }

        .btn-edit:hover {
            background: #e0a800;
        }

        .btn-remove {
            background: var(--danger-color);
            color: white;
        }

        .btn-remove:hover {
            background: #c82333;
        }

        #formProdutoContainer {
            animation: slideInUp 0.3s ease;
        }

        #catalogoModal {
            animation: fadeIn 0.3s ease;
        }

        #produtoSuggestions {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border: 2px solid var(--secondary-color) !important;
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

        /* Campo de arquivo customizado */
        input[type="file"].form-control {
            padding: 0.5rem;
            border: 2px dashed var(--border-color);
            background: var(--light-gray);
            cursor: pointer;
        }

        input[type="file"].form-control:hover {
            border-color: var(--secondary-color);
            background: rgba(0, 191, 174, 0.05);
        }

        /* Estados de validação */
        .form-control.is-invalid {
            border-color: var(--danger-color);
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
        }

        .form-control.is-valid {
            border-color: var(--success-color);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        /* Botões do formulário */
        .modal-buttons {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--border-color);
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
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

        .status-badge.vendido {
            background: rgba(23, 162, 184, 0.1);
            color: var(--info-color);
            border: 1px solid var(--info-color);
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

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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

            .numero-empenho {
                padding: 0.25rem 0.5rem;
            }

            .classificacao-select {
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

            .produtos-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .produtos-actions {
                display: flex;
                gap: 0.5rem;
                justify-content: center;
            }
            
            .produtos-table th,
            .produtos-table td {
                padding: 0.5rem 0.25rem;
                font-size: 0.85rem;
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

            .numero-empenho {
                font-size: 0.8rem;
                padding: 0.25rem 0.4rem;
            }

            .classificacao-select {
                min-width: 100px;
                padding: 0.4rem;
                font-size: 0.75rem;
            }

            .produtos-table th,
            .produtos-table td {
                padding: 0.5rem;
                font-size: 0.8rem;
            }
        }

        /* ===========================================
   BOTÃO NOVO EMPENHO
   =========================================== */
.novo-empenho-container {
    background: linear-gradient(135deg, rgba(40, 167, 69, 0.1) 0%, rgba(32, 201, 151, 0.1) 100%);
    padding: 1.5rem;
    border-radius: var(--radius);
    border: 2px dashed var(--success-color);
    margin: 2rem 0;
}

.btn-novo-empenho {
    background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
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
    box-shadow: 0 6px 20px rgba(40, 167, 69, 0.3);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.btn-novo-empenho::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    transition: left 0.6s;
}

.btn-novo-empenho:hover::before {
    left: 100%;
}

.btn-novo-empenho:hover {
    background: linear-gradient(135deg, #20c997 0%, var(--success-color) 100%);
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 10px 30px rgba(40, 167, 69, 0.4);
    text-decoration: none;
    color: white;
}

.btn-novo-empenho i {
    font-size: 1.3rem;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

/* Responsividade */
@media (max-width: 768px) {
    .btn-novo-empenho {
        width: 100%;
        justify-content: center;
        padding: 1.2rem;
        font-size: 1rem;
    }
    
    .novo-empenho-container {
        margin: 1.5rem 0;
        padding: 1rem;
    }
}

@media (max-width: 480px) {
    .btn-novo-empenho span {
        font-size: 0.9rem;
    }
}

/* ===========================================
   PRIORIDADES
   =========================================== */
.prioridade-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
}

.prioridade-urgente {
    background: rgba(220, 53, 69, 0.1);
    color: var(--danger-color);
    border: 1px solid var(--danger-color);
    animation: pulse-urgent 2s infinite;
}

.prioridade-alta {
    background: rgba(255, 193, 7, 0.1);
    color: var(--warning-color);
    border: 1px solid var(--warning-color);
}

.prioridade-normal {
    background: rgba(108, 117, 125, 0.1);
    color: var(--medium-gray);
    border: 1px solid var(--medium-gray);
}

@keyframes pulse-urgent {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

/* ===========================================
   ORDENAÇÃO DA TABELA
   =========================================== */
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
    </style>
</head>
<body>

<div class="container">
    <h2>
        <i class="fas fa-file-invoice-dollar"></i>
        Consulta de Empenhos
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
            <div class="stat-nav-text">Ver detalhes</div>
        </div>
        <?php endforeach; ?>
        
        <!-- Indicador de atrasos -->
        <div class="stat-item stat-navegavel atraso" onclick="navegarParaDetalhes('atraso')">
            <div class="stat-number"><?php echo $empenhosAtrasados ?? 0; ?></div>
            <div class="stat-label">Em Atraso</div>
            <div class="stat-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-nav-text">Ver detalhes</div>
        </div>
    </div>

<div class="novo-empenho-container" style="margin-bottom: 2rem; text-align: center;">
    <a href="cadastro_empenho.php" class="btn btn-success btn-novo-empenho">
        <i class="fas fa-plus-circle"></i>
        <span>Incluir Novo Empenho</span>
    </a>
</div>
    <?php endif; ?>

    <!-- ===========================================
         VALOR TOTAL GERAL
         =========================================== -->
    <?php if (isset($totalGeral)): ?>
        <div class="total-geral">
            <div>
                <i class="fas fa-calculator"></i>
                <span>Valor Total de Empenhos</span>
            </div>
            <strong>R$ <?php echo number_format($totalGeral, 2, ',', '.'); ?></strong>
        </div>
    <?php endif; ?>

    <!-- ===========================================
         FILTROS AVANÇADOS
         =========================================== -->
    <div class="filters-container">
        <form action="consulta_empenho.php" method="GET" id="filtersForm">
            <div class="filters-row">
                <div class="search-group">
                    <label for="search">Buscar por:</label>
                    <input type="text" 
                           name="search" 
                           id="search" 
                           class="search-input"
                           placeholder="Número, cliente ou UASG..." 
                           value="<?php echo htmlspecialchars($searchTerm ?? ''); ?>"
                           autocomplete="off">
                </div>
                
                <!-- Filtro por classificação -->
                <div class="search-group">
                    <label for="classificacao">Classificação:</label>
                    <select name="classificacao" id="classificacao" class="filter-select">
                        <option value="">Todas as classificações</option>
                        <option value="Pendente" <?php echo $classificacaoFilter === 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
                        <option value="Faturado" <?php echo $classificacaoFilter === 'Faturado' ? 'selected' : ''; ?>>Faturado</option>
                        <option value="Entregue" <?php echo $classificacaoFilter === 'Entregue' ? 'selected' : ''; ?>>Entregue</option>
                        <option value="Liquidado" <?php echo $classificacaoFilter === 'Liquidado' ? 'selected' : ''; ?>>Liquidado</option>
                        <option value="Pago" <?php echo $classificacaoFilter === 'Pago' ? 'selected' : ''; ?>>Pago</option>
                        <option value="Cancelado" <?php echo $classificacaoFilter === 'Cancelado' ? 'selected' : ''; ?>>Cancelado</option>
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
         TABELA DE EMPENHOS
         =========================================== -->
    <?php if (count($empenhos) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th onclick="ordenarTabela('numero')" style="cursor: pointer;" title="Clique para ordenar">
                            <i class="fas fa-hashtag"></i> Número 
                            <i class="fas fa-sort sort-icon" id="sort-numero"></i>
                        </th>
                        <th onclick="ordenarTabela('cliente')" style="cursor: pointer;" title="Clique para ordenar">
                            <i class="fas fa-building"></i> Cliente 
                            <i class="fas fa-sort sort-icon" id="sort-cliente"></i>
                        </th>
                        <th onclick="ordenarTabela('valor')" style="cursor: pointer;" title="Clique para ordenar">
                            <i class="fas fa-dollar-sign"></i> Valor 
                            <i class="fas fa-sort sort-icon" id="sort-valor"></i>
                        </th>
                        <th onclick="ordenarTabela('lucro')" style="cursor: pointer;" title="Clique para ordenar">
                            <i class="fas fa-dollar-sign"></i> Lucro R$ 
                            <i class="fas fa-sort sort-icon" id="sort-lucro"></i>
                        </th>
                        <th onclick="ordenarTabela('prioridade')" style="cursor: pointer;" title="Clique para ordenar">
                            <i class="fas fa-exclamation"></i> Prioridade 
                            <i class="fas fa-sort sort-icon" id="sort-prioridade"></i>
                        </th>
                        <th onclick="ordenarTabela('classificacao')" style="cursor: pointer;" title="Clique para ordenar">
                            <i class="fas fa-tags"></i> Classificação 
                            <i class="fas fa-sort sort-icon" id="sort-classificacao"></i>
                        </th>
                        <th onclick="ordenarTabela('data')" style="cursor: pointer;" title="Clique para ordenar">
                            <i class="fas fa-calendar"></i> Data Empenho 
                            <i class="fas fa-sort sort-icon" id="sort-data"></i>
                        </th>
                        <th onclick="ordenarTabela('status')" style="cursor: pointer;" title="Clique para ordenar">
                            <i class="fas fa-clock"></i> Status Prazo 
                            <i class="fas fa-sort sort-icon" id="sort-status"></i>
                        </th>
                        <th onclick="ordenarTabela('margem')" style="cursor: pointer;" title="Clique para ordenar">
                            <i class="fas fa-percentage"></i> Margem 
                            <i class="fas fa-sort sort-icon" id="sort-margem"></i>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($empenhos as $empenho): ?>
                        <tr <?php if ($empenho['em_atraso']): ?>class="em-atraso"<?php endif; ?>>
                            <td>
                                <span class="numero-empenho" onclick="openModal(<?php echo $empenho['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                    <?php echo htmlspecialchars($empenho['numero'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($empenho['cliente_nome'] ?? 'N/A'); ?></td>
                            <td>
                                <strong>R$ <?php echo isset($empenho['valor_total_empenho']) ? number_format($empenho['valor_total_empenho'], 2, ',', '.') : 'N/A'; ?></strong>
                            </td>
                            <td>
                                <?php 
                                $lucroTotal = floatval($empenho['lucro_total_valor'] ?? 0);
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
                                <?php 
                                $prioridade = $empenho['prioridade'] ?? 'Normal';
                                $prioridadeClass = '';
                                $prioridadeIcon = '';
                                
                                switch($prioridade) {
                                    case 'Urgente':
                                        $prioridadeClass = 'prioridade-urgente';
                                        $prioridadeIcon = 'fas fa-fire';
                                        break;
                                    case 'Alta':
                                        $prioridadeClass = 'prioridade-alta';
                                        $prioridadeIcon = 'fas fa-exclamation-triangle';
                                        break;
                                    default:
                                        $prioridadeClass = 'prioridade-normal';
                                        $prioridadeIcon = 'fas fa-minus';
                                }
                                ?>
                                <span class="prioridade-badge <?php echo $prioridadeClass; ?>">
                                    <i class="<?php echo $prioridadeIcon; ?>"></i>
                                    <?php echo $prioridade; ?>
                                </span>
                            </td>
                            <td>
                                <select class="classificacao-select" 
                                        data-empenho-id="<?php echo $empenho['id']; ?>" 
                                        onchange="updateClassificacao(this)"
                                        value="<?php echo $empenho['classificacao'] ?? ''; ?>">
                                    <option value="">Selecionar</option>
                                    <option value="Pendente" <?php echo $empenho['classificacao'] === 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
                                    <option value="Faturado" <?php echo $empenho['classificacao'] === 'Faturado' ? 'selected' : ''; ?>>Faturado</option>
                                    <option value="Entregue" <?php echo $empenho['classificacao'] === 'Entregue' ? 'selected' : ''; ?>>Entregue</option>
                                    <option value="Liquidado" <?php echo $empenho['classificacao'] === 'Liquidado' ? 'selected' : ''; ?>>Liquidado</option>
                                    <option value="Pago" <?php echo $empenho['classificacao'] === 'Pago' ? 'selected' : ''; ?>>Pago</option>
                                    <option value="Cancelado" <?php echo $empenho['classificacao'] === 'Cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                                    <option value="Vendido" <?php echo $empenho['classificacao'] === 'Vendido' ? 'selected' : ''; ?>>Vendido</option>
                                </select>
                            </td>
                            <td>
                                <strong><?php echo $empenho['data'] && $empenho['data'] != '0000-00-00' ? date('d/m/Y', strtotime($empenho['data'])) : 'N/A'; ?></strong>
                                <br>
                                <small style="color: var(--medium-gray);">
                                    <?php echo $empenho['dias_desde_empenho'] ?? 0; ?> dias
                                </small>
                            </td>
                            <td>
                                <?php if ($empenho['em_atraso']): ?>
                                    <span class="atraso-indicator em-atraso">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <?php echo ($empenho['dias_desde_empenho'] - 30); ?> dias atraso
                                    </span>
                                <?php else: ?>
                                    <span class="atraso-indicator no-prazo">
                                        <i class="fas fa-check-circle"></i>
                                        No prazo
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $margemLucro = floatval($empenho['margem_lucro_percentual'] ?? 0);
                                $margemClass = '';
                                
                                if ($margemLucro >= 20) {
                                    $margemClass = 'alta';
                                } elseif ($margemLucro >= 10) {
                                    $margemClass = 'media';  
                                } elseif ($margemLucro > 0) {
                                    $margemClass = 'baixa';
                                } else {
                                    $margemClass = 'neutra';
                                }
                                ?>
                                <span class="margem-badge <?php echo $margemClass; ?>">
                                    <i class="fas fa-percentage"></i>
                                    <?php echo number_format($margemLucro, 1, ',', '.'); ?>%
                                </span>
                            </td>
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
                <?php echo $totalRegistros; ?> empenhos
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
            <p>Nenhum empenho encontrado.</p>
            <small>Tente ajustar os filtros ou cadastre um novo empenho.</small>
        </div>
    <?php endif; ?>
</div>

<!-- ===========================================
     MODAL DE DETALHES DO EMPENHO (CORRIGIDO)
     =========================================== -->
<div id="empenhoModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-file-invoice-dollar"></i> 
                Detalhes do Empenho
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
            <!-- Botões serão inseridos dinamicamente pelo JavaScript -->
        </div>
    </div>
</div>

<script>
// ===========================================
// SISTEMA COMPLETO DE CONSULTA DE EMPENHOS - JAVASCRIPT CORRIGIDO
// LicitaSis v7.1 - Todas as correções implementadas
// ===========================================

// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let currentEmpenhoId = null;
let currentEmpenhoData = null;
let currentEditingProduct = null;
let produtosEmpenho = [];
let modoEdicaoAtivo = false;
let modoGestãoProdutos = false;
let produtosAlterados = false;

// Variáveis específicas do autocomplete
window.produtosSugeridos = [];
let isVisible = false;
let searchTimeout = null;

// ===========================================
// FUNÇÕES PRINCIPAIS DO MODAL
// ===========================================

/**
 * Abre o modal com detalhes completos do empenho
 * @param {number} empenhoId - ID do empenho
 */
function openModal(empenhoId) {
    console.log('🔍 Abrindo modal para empenho ID:', empenhoId);
    
    currentEmpenhoId = empenhoId;
    const modal = document.getElementById('empenhoModal');
    const modalBody = document.getElementById('modalBody');
    const modalFooter = document.getElementById('modalFooter');
    
    // Mostra o modal
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Resetar estado
    resetModalState();
    
    // Mostra loading
    modalBody.innerHTML = `
        <div class="loading-spinner" style="text-align: center; padding: 4rem;">
            <div style="width: 50px; height: 50px; border: 4px solid var(--border-color); border-top: 4px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
            <p style="margin-top: 1.5rem; color: var(--medium-gray); font-size: 1.1rem;">Carregando detalhes do empenho...</p>
        </div>
    `;
    modalFooter.style.display = 'none';
    
    // Busca dados do empenho
    const url = `consulta_empenho.php?get_empenho_id=${empenhoId}&t=${Date.now()}`;
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
            console.log('✅ Dados do empenho recebidos:', data);
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            currentEmpenhoData = data;
            renderEmpenhoDetailsComplete(data);
            modalFooter.style.display = 'flex';
            
            console.log('✅ Modal renderizado com sucesso para empenho:', data.numero);
        })
        .catch(error => {
            console.error('❌ Erro ao carregar empenho:', error);
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 4rem; color: var(--danger-color);">
                    <i class="fas fa-exclamation-triangle" style="font-size: 4rem; margin-bottom: 1.5rem; opacity: 0.8;"></i>
                    <h3 style="margin-bottom: 1rem; color: var(--danger-color);">Erro ao carregar empenho</h3>
                    <p style="color: var(--medium-gray); margin-bottom: 2rem; font-size: 1.1rem;">${error.message}</p>
                    <div style="display: flex; gap: 1rem; justify-content: center;">
                        <button class="btn btn-warning" onclick="openModal(${empenhoId})" style="margin: 0;">
                            <i class="fas fa-redo"></i> Tentar Novamente
                        </button>
                        <button class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Fechar
                        </button>
                    </div>
                </div>
            `;
        });
}

/**
 * Reseta o estado do modal
 */
function resetModalState() {
    modoEdicaoAtivo = false;
    modoGestãoProdutos = false;
    produtosAlterados = false;
    currentEditingProduct = null;
    produtosEmpenho = [];
    
    // Remove qualquer formulário ativo
    const editForm = document.getElementById('empenhoEditForm');
    if (editForm) {
        editForm.style.display = 'none';
    }
    
    const viewMode = document.getElementById('empenhoViewMode');
    if (viewMode) {
        viewMode.style.display = 'block';
    }
}

/**
 * Renderiza os detalhes completos do empenho no modal - VERSÃO CORRIGIDA
 * @param {Object} empenho - Dados do empenho
 */
function renderEmpenhoDetailsComplete(empenho) {
    console.log('🎨 Renderizando detalhes completos do empenho:', empenho);
    
    const modalBody = document.getElementById('modalBody');
    const modalFooter = document.getElementById('modalFooter');
    
    // Prepara datas
    const dataFormatada = empenho.data_cadastro || 'N/A';
    const dataEmpenho = empenho.data || '';
    const dataEmpenhoDisplay = empenho.data_formatada || 'N/A';
    
    // Determina classe da margem de lucratividade
    let margemClass = 'neutra';
    const margemLucro = empenho.margem_lucro_geral || 0;
    if (margemLucro >= 20) margemClass = 'alta';
    else if (margemLucro >= 10) margemClass = 'media';
    else if (margemLucro > 0) margemClass = 'baixa';

    modalBody.innerHTML = `
        <div class="empenho-details">
            
            <!-- VISUALIZAÇÃO NORMAL (inicialmente visível) -->
            <div id="empenhoViewMode">
                
                <!-- 1. Informações Básicas -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-info-circle"></i>
                        Informações Básicas
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Número do Empenho</div>
                                <div class="detail-value highlight">${empenho.numero || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">
                                    <span class="status-badge ${(empenho.classificacao || '').toLowerCase()}">
                                        <i class="fas fa-${getStatusIcon(empenho.classificacao)}"></i>
                                        ${empenho.classificacao || 'Não definido'}
                                    </span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data do Empenho</div>
                                <div class="detail-value highlight">${dataEmpenhoDisplay}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Dias desde o Empenho</div>
                                <div class="detail-value">${empenho.dias_desde_empenho || 0} dias</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status de Prazo</div>
                                <div class="detail-value">
                                    ${empenho.em_atraso ? 
                                        `<span class="atraso-indicator em-atraso"><i class="fas fa-exclamation-triangle"></i> ${empenho.dias_atraso} dias de atraso</span>` :
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
                                <div class="detail-value">${empenho.pregao || 'Não informado'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Prioridade</div>
                                <div class="detail-value">${empenho.prioridade || 'Normal'}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Informações do Cliente -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-building"></i>
                        Informações do Cliente
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Nome do Cliente</div>
                                <div class="detail-value highlight">${empenho.cliente_nome || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">UASG</div>
                                <div class="detail-value">${empenho.cliente_uasg || 'N/A'}</div>
                            </div>
                            ${empenho.cnpj ? `
                            <div class="detail-item">
                                <div class="detail-label">CNPJ</div>
                                <div class="detail-value">${empenho.cnpj}</div>
                            </div>
                            ` : ''}
                            ${empenho.cliente_info ? `
                            <div class="detail-item">
                                <div class="detail-label">Endereço</div>
                                <div class="detail-value">${empenho.cliente_info.endereco || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Telefone</div>
                                <div class="detail-value">${empenho.cliente_info.telefone || 'N/A'}</div>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                </div>

                <!-- 3. Produtos do Empenho -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-shopping-cart"></i>
                        Produtos do Empenho
                        <div style="margin-left: auto;">
                            <span id="contadorProdutos" style="background: rgba(0, 191, 174, 0.1); color: var(--secondary-color); padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.85rem; font-weight: 600;">
                                0 produtos
                            </span>
                        </div>
                    </div>
                    <div class="detail-content">
                        <div id="produtosVisualizacaoContainer">
                            <div style="text-align: center; padding: 3rem; color: var(--medium-gray);">
                                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                <p style="font-size: 1.1rem;">Carregando produtos...</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. Análise de Lucratividade -->
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
                                R$ ${parseFloat(empenho.valor_total_venda || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                            </div>
                            <div class="lucratividade-label">Valor de Venda</div>
                        </div>
                        
                        <div class="lucratividade-item">
                            <div class="lucratividade-valor negativo">
                                R$ ${parseFloat(empenho.valor_total_custo || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                            </div>
                            <div class="lucratividade-label">Custo Total</div>
                        </div>
                        
                        <div class="lucratividade-item">
                            <div class="lucratividade-valor ${(empenho.lucro_total_geral || 0) >= 0 ? 'positivo' : 'negativo'}">
                                R$ ${parseFloat(empenho.lucro_total_geral || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                            </div>
                            <div class="lucratividade-label">Lucro Total</div>
                        </div>
                        
                        <div class="lucratividade-item">
                            <div class="lucratividade-valor ${(empenho.margem_lucro_geral || 0) >= 0 ? 'positivo' : 'negativo'}">
                                ${(empenho.margem_lucro_geral || 0).toFixed(2)}%
                            </div>
                            <div class="lucratividade-label">Margem de Lucro</div>
                        </div>
                    </div>
                </div>

                <!-- 5. Observações e Documentos -->
                ${empenho.observacao || empenho.upload ? `
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-file-alt"></i>
                        Observações e Documentos
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            ${empenho.observacao ? `
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Observações</div>
                                <div class="detail-value" style="white-space: pre-wrap; background: var(--light-gray); padding: 1rem; border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">${empenho.observacao}</div>
                            </div>
                            ` : ''}
                            ${empenho.upload ? `
                            <div class="detail-item">
                                <div class="detail-label">Arquivo Anexo</div>
                                <div class="detail-value">
                                    <a href="${empenho.upload}" target="_blank" class="arquivo-link">
                                        <i class="fas fa-file-pdf"></i>
                                        Ver Arquivo
                                    </a>
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                </div>
                ` : ''}
            </div>

            <!-- FORMULÁRIO DE EDIÇÃO INTEGRADO (inicialmente oculto) -->
            <form id="empenhoEditForm" style="display: none;" enctype="multipart/form-data">
                <input type="hidden" name="id" value="${empenho.id}">
                <input type="hidden" name="update_empenho" value="1">
                
                <!-- Cabeçalho do Modo Edição -->
                <div class="detail-section" style="border: 2px solid var(--warning-color); background: linear-gradient(135deg, var(--warning-color), #e0a800); color: white; border-radius: var(--radius); margin-bottom: 2rem;">
                    <div class="detail-header" style="background: none; color: white; border: none;">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <i class="fas fa-edit" style="font-size: 1.5rem;"></i>
                            <div>
                                <h3 style="margin: 0; color: white;">Modo de Edição Ativo</h3>
                                <small style="opacity: 0.9;">Editando empenho: ${empenho.numero || 'N/A'}</small>
                            </div>
                        </div>
                        <button type="button" class="btn btn-light btn-sm" onclick="cancelarEdicaoCompleta()" style="margin-left: auto;">
                            <i class="fas fa-times"></i> Cancelar Edição
                        </button>
                    </div>
                </div>

                <!-- SEÇÃO 1: Informações Básicas do Empenho -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-info-circle"></i>
                        Informações Básicas do Empenho
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Número do Empenho *</div>
                                <input type="text" name="numero" class="form-control" value="${empenho.numero || ''}" required>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <select name="classificacao" class="form-control">
                                    <option value="">Selecionar</option>
                                    <option value="Pendente" ${empenho.classificacao === 'Pendente' ? 'selected' : ''}>Pendente</option>
                                    <option value="Faturado" ${empenho.classificacao === 'Faturado' ? 'selected' : ''}>Faturado</option>
                                    <option value="Entregue" ${empenho.classificacao === 'Entregue' ? 'selected' : ''}>Entregue</option>
                                    <option value="Liquidado" ${empenho.classificacao === 'Liquidado' ? 'selected' : ''}>Liquidado</option>
                                    <option value="Pago" ${empenho.classificacao === 'Pago' ? 'selected' : ''}>Pago</option>
                                    <option value="Cancelado" ${empenho.classificacao === 'Cancelado' ? 'selected' : ''}>Cancelado</option>
                                    <option value="Vendido" ${empenho.classificacao === 'Vendido' ? 'selected' : ''}>Vendido</option>
                                </select>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Data do Empenho</div>
                                <input type="date" name="data" class="form-control" value="${dataEmpenho}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Pregão</div>
                                <input type="text" name="pregao" class="form-control" value="${empenho.pregao || ''}">
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Prioridade</div>
                                <select name="prioridade" class="form-control">
                                    <option value="Normal" ${empenho.prioridade === 'Normal' ? 'selected' : ''}>Normal</option>
                                    <option value="Alta" ${empenho.prioridade === 'Alta' ? 'selected' : ''}>Alta</option>
                                    <option value="Urgente" ${empenho.prioridade === 'Urgente' ? 'selected' : ''}>Urgente</option>
                                </select>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Valor Total</div>
                                <input type="number" name="valor_total_empenho" class="form-control" step="0.01" min="0" value="${empenho.valor_total_empenho || ''}">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SEÇÃO 2: Informações do Cliente -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-building"></i>
                        Informações do Cliente
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item">
    <div class="detail-item">
    <div class="detail-label">Nome do Cliente *</div>
    <div class="autocomplete-container-cliente">
        <div style="position: relative;">
            <input type="text" 
                   name="cliente_nome" 
                   id="clienteNomeEdit"
                   class="form-control" 
                   value="${empenho.cliente_nome || ''}" 
                   placeholder="Digite o nome do cliente, UASG ou CNPJ..."
                   autocomplete="off"
                   required
                   data-autocomplete="true"
                   data-suggestions-id="clienteEditSuggestions">
            <div id="clienteEditSuggestions" class="suggestions-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 2px solid var(--secondary-color); border-top: none; border-radius: 0 0 var(--radius-sm) var(--radius-sm); max-height: 300px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.15);"></div>
        </div>
        <div id="clienteInfoContainer" style="display: none; margin-top: 1rem;"></div>
    </div>
</div>
                            <div class="detail-item">
    <div class="detail-label">UASG</div>
    <input type="text" name="cliente_uasg" id="clienteUasgEdit" class="form-control" value="${empenho.cliente_uasg || ''}">
</div>
<div class="detail-item">
    <div class="detail-label">CNPJ</div>
    <input type="text" name="cnpj" id="clienteCnpjEdit" class="form-control" value="${empenho.cnpj || ''}" maxlength="18">
</div>
                        </div>
                    </div>
                </div>

                <!-- SEÇÃO 3: GESTÃO INTEGRADA DE PRODUTOS -->
                <div class="detail-section">
                    <div class="detail-header" style="background: linear-gradient(135deg, var(--success-color), #218838); color: white;">
                        <i class="fas fa-shopping-cart"></i>
                        Gestão de Produtos do Empenho
                        <div style="margin-left: auto; display: flex; gap: 0.5rem; align-items: center;">
                            <span id="contadorProdutosEdit" style="background: rgba(255,255,255,0.2); padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.85rem; font-weight: 600;">
                                0 produtos
                            </span>
                            <button type="button" class="btn btn-light btn-sm" onclick="toggleGestãoProdutos()" id="toggleProdutosBtn">
                                <i class="fas fa-cogs"></i> Gerenciar
                            </button>
                        </div>
                    </div>
                    <div class="detail-content">
                        <!-- Container dos produtos na edição -->
                        <div id="produtosEdicaoContainer">
                            <div style="text-align: center; padding: 3rem; color: var(--medium-gray);">
                                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                <p style="font-size: 1.1rem;">Carregando produtos...</p>
                            </div>
                        </div>
                        
                        <!-- Formulário de produto INTEGRADO -->
                        <div id="formProdutoContainer" style="display: none; margin-top: 2rem; padding: 2rem; background: linear-gradient(135deg, #f8f9fa, #e9ecef); border-radius: var(--radius); border: 2px solid var(--success-color); position: relative;">
                            <div style="position: absolute; top: -1px; left: -1px; right: -1px; height: 4px; background: linear-gradient(90deg, var(--success-color), var(--secondary-color)); border-radius: var(--radius) var(--radius) 0 0;"></div>
                            
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
                                <h5 style="margin: 0; color: var(--primary-color); display: flex; align-items: center; gap: 0.75rem; font-size: 1.2rem;">
                                    <i class="fas fa-plus-circle"></i>
                                    <span id="formProdutoTitle">Adicionar Produto</span>
                                </h5>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="cancelarFormularioProduto()">
                                    <i class="fas fa-times"></i> Fechar
                                </button>
                            </div>
                            
                            <form id="formProduto">
                                <input type="hidden" id="produtoIndex" value="">
                                <input type="hidden" id="produtoId" value="">
                                <input type="hidden" id="empenhoIdProduto" value="${empenho.id || ''}">
                                
                                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                                    <div class="autocomplete-container">
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--medium-gray); text-transform: uppercase; font-size: 0.85rem;">
                                            Produto *
                                        </label>
                                        <div style="position: relative;">
                                            <input type="text" 
                                                   id="produtoNome" 
                                                   placeholder="Digite o nome do produto ou código..." 
                                                   required
                                                   autocomplete="off"
                                                   style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.95rem; transition: var(--transition);">
                                            <div id="produtoSuggestions" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 2px solid var(--secondary-color); border-top: none; border-radius: 0 0 var(--radius-sm) var(--radius-sm); max-height: 350px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.15);"></div>
                                        </div>
                                        <div id="produtoInfoContainer" style="display: none; margin-top: 1rem;"></div>
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--medium-gray); text-transform: uppercase; font-size: 0.85rem;">
                                            Quantidade *
                                        </label>
                                        <input type="number" 
                                               id="produtoQuantidade" 
                                               placeholder="0" 
                                               min="1" 
                                               step="1" 
                                               required
                                               style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.95rem; transition: var(--transition);">
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--medium-gray); text-transform: uppercase; font-size: 0.85rem;">
                                            Valor Unitário *
                                        </label>
                                        <input type="number" 
                                               id="produtoValorUnitario" 
                                               placeholder="0,00" 
                                               min="0" 
                                               step="0.01" 
                                               required
                                               style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.95rem; transition: var(--transition);">
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--medium-gray); text-transform: uppercase; font-size: 0.85rem;">
                                            Valor Total
                                        </label>
                                        <input type="text" 
                                               id="produtoValorTotal" 
                                               readonly 
                                               style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.95rem; background: var(--light-gray); color: var(--primary-color); font-weight: 600;">
                                    </div>
                                </div>
                                
                                <div style="margin-bottom: 2rem;">
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--medium-gray); text-transform: uppercase; font-size: 0.85rem;">
                                        Descrição Adicional
                                    </label>
                                    <textarea id="produtoDescricao" 
                                              placeholder="Informações adicionais sobre o produto..." 
                                              rows="3"
                                              style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.95rem; resize: vertical; transition: var(--transition);"></textarea>
                                </div>
                                
                                <div style="display: flex; gap: 1rem; justify-content: flex-end; border-top: 2px solid var(--border-color); padding-top: 1.5rem;">
                                    <button type="button" class="btn btn-secondary" onclick="cancelarFormularioProduto()">
                                        <i class="fas fa-times"></i> Cancelar
                                    </button>
                                    <button type="submit" class="btn btn-success" id="salvarProdutoBtn">
                                        <i class="fas fa-save"></i> Salvar Produto
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- SEÇÃO 4: Observações e Documentos -->
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-file-alt"></i>
                        Observações e Documentos
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Observações</div>
                                <textarea name="observacao" class="form-control" rows="4" placeholder="Observações sobre o empenho...">${empenho.observacao || ''}</textarea>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Arquivo Anexo</div>
                                <input type="file" name="upload" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                ${empenho.upload ? `<small style="color: var(--info-color); margin-top: 0.5rem; display: block;"><i class="fas fa-paperclip"></i> Arquivo atual: <a href="${empenho.upload}" target="_blank">Ver arquivo</a></small>` : ''}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Botões de ação do formulário de edição - ORDEM CORRIGIDA -->
                <div style="margin-top: 3rem; padding: 2rem; background: var(--light-gray); border-radius: var(--radius); border-left: 4px solid var(--success-color); display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-success" id="salvarEdicaoBtn" style="min-width: 180px;">
                        <i class="fas fa-save"></i> Salvar Todas as Alterações
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="cancelarEdicaoCompleta()" style="min-width: 140px;">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-danger" onclick="confirmarExclusaoEdicao()" id="excluirEdicaoBtn" style="min-width: 140px;">
                        <i class="fas fa-trash"></i> Excluir
                    </button>
                </div>
            </form>
        </div>
    `;

    // Renderiza botões do footer na ordem correta
    modalFooter.innerHTML = `
        ${currentEmpenhoData && currentEmpenhoData.ja_convertido ? '' : `
            <button class="btn btn-success" onclick="converterEmVenda()" id="venderBtn">
                <i class="fas fa-shopping-cart"></i> Vender
            </button>
        `}
        <button class="btn btn-warning" onclick="editarEmpenhoCompleto()" id="editarBtn">
            <i class="fas fa-edit"></i> Editar
        </button>
        <button class="btn btn-primary" onclick="imprimirEmpenho()">
            <i class="fas fa-print"></i> Imprimir
        </button>
        <button class="btn btn-danger" onclick="confirmarExclusao()" id="excluirBtn">
            <i class="fas fa-trash"></i> Excluir
        </button>
        <button class="btn btn-secondary" onclick="closeModal()">
            <i class="fas fa-times"></i> Fechar
        </button>
    `;

  configurarEventListenersModal();

// Backup com timeout caso necessário
setTimeout(() => {
    configurarEventListenersModal();
    console.log('🔧 Event listeners configurados com timeout de backup');
}, 200);

// Carrega os produtos do empenho
carregarProdutosEmpenhoCompleto(empenho.id);
    
    // Atualiza a visibilidade do botão "Vender"
    updateVenderButtonVisibility();
    
    console.log('✅ Detalhes completos do empenho renderizados com sucesso');
}

/**
 * Fecha o modal
 */
function closeModal() {
    const modal = document.getElementById('empenhoModal');
    
    // Verifica se está em modo de edição
    if (modoEdicaoAtivo || produtosAlterados) {
        const confirmClose = confirm(
            'Você está editando o empenho.\n\n' +
            'Tem certeza que deseja fechar sem salvar as alterações?\n\n' +
            'As alterações não salvas serão perdidas.'
        );
        
        if (!confirmClose) {
            return; // Não fecha o modal
        }
    }
    
    // Fecha o modal
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Limpa dados
    currentEmpenhoId = null;
    currentEmpenhoData = null;
    resetModalState();
    
    console.log('✅ Modal fechado');
}

// ===========================================
// GESTÃO DE PRODUTOS
// ===========================================

/**
 * Carrega produtos para ambos os modos (visualização e edição)
 */
function carregarProdutosEmpenhoCompleto(empenhoId) {
    console.log('🛒 Carregando produtos completos do empenho:', empenhoId);
    
    const produtosVisualizacao = document.getElementById('produtosVisualizacaoContainer');
    const produtosEdicao = document.getElementById('produtosEdicaoContainer');
    
    // Mostra loading em ambos os containers
    const loadingHtml = `
        <div style="text-align: center; padding: 3rem; color: var(--medium-gray);">
            <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 1rem;"></i>
            <p style="font-size: 1.1rem;">Carregando produtos...</p>
        </div>
    `;
    
    if (produtosVisualizacao) produtosVisualizacao.innerHTML = loadingHtml;
    if (produtosEdicao) produtosEdicao.innerHTML = loadingHtml;
    
    fetch(`get_produtos_empenho.php?empenho_id=${empenhoId}&t=${Date.now()}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('📦 Produtos recebidos:', data);
            
            if (data.success) {
                produtosEmpenho = data.produtos || [];
                
                // Renderiza para visualização
                renderizarProdutosVisualizacao(data);
                
                // Renderiza para edição se está em modo edição
                if (modoEdicaoAtivo) {
                    renderizarProdutosEdicao(data);
                }
                
                // Atualiza contador
                atualizarContadorProdutos();
                
            } else {
                throw new Error(data.error || 'Erro ao carregar produtos');
            }
        })
        .catch(error => {
            console.error('❌ Erro ao carregar produtos:', error);
            const errorHtml = `
                <div style="text-align: center; padding: 3rem; color: var(--danger-color);">
                    <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                    <p style="font-size: 1.1rem; margin-bottom: 1rem;">Erro ao carregar produtos</p>
                    <p style="color: var(--medium-gray); margin-bottom: 1.5rem;">${error.message}</p>
                    <button class="btn btn-primary btn-sm" onclick="carregarProdutosEmpenhoCompleto(${empenhoId})">
                        <i class="fas fa-redo"></i> Tentar Novamente
                    </button>
                </div>
            `;
            if (produtosVisualizacao) produtosVisualizacao.innerHTML = errorHtml;
            if (produtosEdicao) produtosEdicao.innerHTML = errorHtml;
        });
}

/**
 * Handler específico para clique direto no botão salvar (fallback)
 */
function handleSalvarClickModal(event) {
    console.log('🖱️ Clique direto detectado no botão salvar do modal');
    event.preventDefault();
    event.stopPropagation();
    
    const form = document.getElementById('empenhoEditForm');
    if (form) {
        console.log('📝 Disparando submit do formulário via clique do botão...');
        
        // Cria e dispara evento de submit
        const submitEvent = new Event('submit', { 
            bubbles: true, 
            cancelable: true 
        });
        
        // Chama diretamente a função se o evento não funcionar
        setTimeout(() => {
            salvarEdicaoCompleta({
                preventDefault: () => {},
                stopPropagation: () => {},
                target: form
            });
        }, 50);
        
        form.dispatchEvent(submitEvent);
    } else {
        console.error('❌ Formulário não encontrado no clique do botão');
        showToast('Erro: Formulário não encontrado', 'error');
    }
}

/**
 * Configura event listeners específicos para campos de produto
 */
function configurarEventListenersProduto() {
    const quantidadeInput = document.getElementById('produtoQuantidade');
    const valorUnitarioInput = document.getElementById('produtoValorUnitario');
    
    if (quantidadeInput) {
        quantidadeInput.removeEventListener('input', calcularValorTotalProduto);
        quantidadeInput.addEventListener('input', calcularValorTotalProduto);
        
        quantidadeInput.removeEventListener('blur', validarQuantidade);
        quantidadeInput.addEventListener('blur', validarQuantidade);
    }
    
    if (valorUnitarioInput) {
        valorUnitarioInput.removeEventListener('input', calcularValorTotalProduto);
        valorUnitarioInput.addEventListener('input', calcularValorTotalProduto);
        
        valorUnitarioInput.removeEventListener('blur', validarValorUnitario);
        valorUnitarioInput.addEventListener('blur', validarValorUnitario);
    }
}

/**
 * Valida campo quantidade
 */
function validarQuantidade() {
    if (!this.value || this.value === '0') {
        this.value = '1';
        calcularValorTotalProduto();
    }
}

/**
 * Valida campo valor unitário
 */
function validarValorUnitario() {
    let valor = parseFloat(this.value);
    if (!isNaN(valor) && valor >= 0) {
        this.value = valor.toFixed(2);
        calcularValorTotalProduto();
    }
}

/**
 * Renderiza produtos no modo visualização
 */
function renderizarProdutosVisualizacao(data) {
    const container = document.getElementById('produtosVisualizacaoContainer');
    if (!container) return;

    const produtos = data.produtos || [];
    const estatisticas = data.estatisticas || {};

    if (produtos.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 4rem; color: var(--medium-gray); background: var(--light-gray); border-radius: var(--radius-sm); border: 2px dashed var(--border-color);">
                <i class="fas fa-shopping-cart" style="font-size: 3rem; margin-bottom: 1.5rem; color: var(--secondary-color);"></i>
                <h4 style="margin-bottom: 1rem; color: var(--primary-color);">Nenhum produto cadastrado</h4>
                <p style="margin-bottom: 1.5rem;">Use o botão "Editar" para incluir produtos</p>
                <button class="btn btn-warning" onclick="editarEmpenhoCompleto()">
                    <i class="fas fa-plus"></i> Adicionar Produtos
                </button>
            </div>
        `;
        return;
    }

    let html = `
        <div style="margin-bottom: 1.5rem; padding: 1.5rem; background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%); border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h4 style="margin: 0; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-boxes"></i>
                        Resumo dos Produtos
                    </h4>
                    <div style="margin-top: 0.5rem; display: flex; gap: 2rem; flex-wrap: wrap;">
                        <span style="color: var(--medium-gray);"><strong>Total:</strong> ${produtos.length} produtos</span>
                        <span style="color: var(--medium-gray);"><strong>Itens:</strong> ${estatisticas.quantidade_total_itens || 0}</span>
                        <span style="color: var(--success-color); font-weight: 600;"><strong>Valor:</strong> ${estatisticas.valor_total_produtos_formatado || 'R$ 0,00'}</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="border: 1px solid var(--border-color); border-radius: var(--radius-sm); overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: linear-gradient(135deg, var(--secondary-color), var(--secondary-dark)); color: white;">
                        <th style="padding: 1rem; text-align: left; font-weight: 600;">Produto</th>
                        <th style="padding: 1rem; text-align: center; font-weight: 600;">Qtd</th>
                        <th style="padding: 1rem; text-align: right; font-weight: 600;">Valor Unit.</th>
                        <th style="padding: 1rem; text-align: right; font-weight: 600;">Valor Total</th>
                        <th style="padding: 1rem; text-align: right; font-weight: 600;">Margem</th>
                    </tr>
                </thead>
                <tbody>
    `;

    produtos.forEach((produto, index) => {
        const valorUnitario = parseFloat(produto.valor_unitario || 0);
        const quantidade = parseInt(produto.quantidade || 0);
        const valorTotal = parseFloat(produto.valor_total || 0);
        const margemLucro = parseFloat(produto.margem_lucro_calculada || 0);
        
        // Determina classe da margem
        let margemClass = 'neutra';
        if (margemLucro >= 20) margemClass = 'alta';
        else if (margemLucro >= 10) margemClass = 'media';
        else if (margemLucro > 0) margemClass = 'baixa';
        
        html += `
            <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;" 
                onmouseover="this.style.background='rgba(0, 191, 174, 0.05)'" 
                onmouseout="this.style.background='white'">
                <td style="padding: 1rem;">
                    <div>
                        <strong style="color: var(--primary-color); font-size: 1rem;">
    ${produto.produto_id ? 
        `<a href="consulta_produto.php?id=${produto.produto_id}" target="_blank" style="color: var(--secondary-color); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem;" title="Ver detalhes do produto">
            <i class="fas fa-external-link-alt" style="font-size: 0.8rem;"></i>
            ${produto.produto_nome || produto.descricao_produto || 'Produto sem nome'}
        </a>` :
        (produto.produto_nome || produto.descricao_produto || 'Produto sem nome')
    }
</strong>
                        ${produto.produto_codigo ? `<br><small style="color: var(--medium-gray); display: flex; align-items: center; gap: 0.25rem; margin-top: 0.25rem;"><i class="fas fa-barcode"></i> ${produto.produto_codigo}</small>` : ''}
                        ${produto.produto_categoria ? `<br><small style="color: var(--info-color); display: flex; align-items: center; gap: 0.25rem; margin-top: 0.25rem;"><i class="fas fa-tag"></i> ${produto.produto_categoria}</small>` : ''}
                        ${produto.produto_observacao ? `<br><small style="color: var(--medium-gray); margin-top: 0.25rem; font-style: italic;">${produto.produto_observacao}</small>` : ''}
                    </div>
                </td>
                <td style="padding: 1rem; text-align: center;">
                    <span style="background: var(--info-color); color: white; padding: 0.5rem 0.75rem; border-radius: 20px; font-weight: 600; font-size: 0.9rem;">
                        ${quantidade.toLocaleString('pt-BR')}
                    </span>
                    ${produto.produto_unidade ? `<br><small style="color: var(--medium-gray); margin-top: 0.25rem;">${produto.produto_unidade}</small>` : ''}
                </td>
                <td style="padding: 1rem; text-align: right;">
                    <span style="font-weight: 600; color: var(--success-color); font-size: 1rem;">
                        R$ ${valorUnitario.toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                    </span>
                </td>
                <td style="padding: 1rem; text-align: right;">
                    <span style="font-weight: 700; color: var(--primary-color); font-size: 1.1rem;">
                        R$ ${valorTotal.toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                    </span>
                </td>
                <td style="padding: 1rem; text-align: right;">
                    <span class="margem-badge ${margemClass}" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600;">
                        <i class="fas fa-percentage"></i>
                        ${margemLucro.toFixed(1)}%
                    </span>
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
                <tfoot>
                    <tr style="background: linear-gradient(135deg, var(--light-gray), #e9ecef); font-weight: 700; border-top: 2px solid var(--border-color);">
                        <td colspan="3" style="padding: 1.5rem; text-align: right; font-size: 1.1rem;">
                            <strong style="color: var(--primary-color);">TOTAL GERAL:</strong>
                        </td>
                        <td style="padding: 1.5rem; text-align: right; color: var(--primary-color); font-size: 1.2rem;">
                            <strong>R$ ${(estatisticas.valor_total_produtos || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</strong>
                        </td>
                        <td style="padding: 1.5rem;"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    `;

    container.innerHTML = html;
}

/**
 * Renderiza produtos no modo edição
 */
function renderizarProdutosEdicao(data) {
    const container = document.getElementById('produtosEdicaoContainer');
    if (!container) return;

    const produtos = data.produtos || [];
    const estatisticas = data.estatisticas || {};

    if (produtos.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 3rem; color: var(--medium-gray); background: rgba(40, 167, 69, 0.1); border-radius: var(--radius-sm); border: 2px dashed var(--success-color);">
                <i class="fas fa-shopping-cart" style="font-size: 2.5rem; margin-bottom: 1rem; color: var(--success-color);"></i>
                <h4 style="margin-bottom: 1rem; color: var(--success-color);">Nenhum produto cadastrado</h4>
                <p style="margin-bottom: 1.5rem;">Clique em "Gerenciar" para adicionar produtos</p>
                <button class="btn btn-success" onclick="toggleGestãoProdutos()" style="display: ${modoGestãoProdutos ? 'none' : 'inline-flex'};">
                    <i class="fas fa-plus"></i> Começar a Adicionar Produtos
                </button>
            </div>
        `;
        return;
    }

    let html = `
        <div style="margin-bottom: 1.5rem; padding: 1.5rem; background: rgba(40, 167, 69, 0.1); border-radius: var(--radius-sm); border: 1px solid var(--success-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h4 style="margin: 0; color: var(--success-color); display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-cogs"></i>
                        Produtos em Edição
                    </h4>
                    <div style="margin-top: 0.5rem; display: flex; gap: 2rem; flex-wrap: wrap;">
                        <span style="color: var(--medium-gray);"><strong>Total:</strong> ${produtos.length} produtos</span>
                        <span style="color: var(--medium-gray);"><strong>Itens:</strong> ${estatisticas.quantidade_total_itens || 0}</span>
                        <span style="color: var(--success-color); font-weight: 600;"><strong>Valor:</strong> ${estatisticas.valor_total_produtos_formatado || 'R$ 0,00'}</span>
                    </div>
                </div>
                <div style="display: ${modoGestãoProdutos ? 'flex' : 'none'}; gap: 0.5rem; flex-wrap: wrap;">
                    <button type="button" class="btn btn-success btn-sm" onclick="abrirFormularioProduto()">
                        <i class="fas fa-plus"></i> Adicionar
                    </button>
                    <button type="button" class="btn btn-info btn-sm" onclick="buscarProdutosCatalogo()">
                        <i class="fas fa-search"></i> Catálogo
                    </button>
                </div>
            </div>
        </div>
        
        <div style="border: 1px solid var(--border-color); border-radius: var(--radius-sm); overflow: hidden; max-height: 500px; overflow-y: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: linear-gradient(135deg, var(--success-color), #218838); color: white; position: sticky; top: 0; z-index: 10;">
                        <th style="padding: 1rem; text-align: left; font-weight: 600;">Produto</th>
                        <th style="padding: 1rem; text-align: center; font-weight: 600;">Qtd</th>
                        <th style="padding: 1rem; text-align: right; font-weight: 600;">Valor Unit.</th>
                        <th style="padding: 1rem; text-align: right; font-weight: 600;">Valor Total</th>
                        ${modoGestãoProdutos ? '<th style="padding: 1rem; text-align: center; font-weight: 600;">Ações</th>' : ''}
                    </tr>
                </thead>
                <tbody>
    `;

    produtos.forEach((produto, index) => {
        const valorUnitario = parseFloat(produto.valor_unitario || 0);
        const quantidade = parseInt(produto.quantidade || 0);
        const valorTotal = parseFloat(produto.valor_total || 0);
        
        html += `
            <tr style="border-bottom: 1px solid var(--border-color); ${modoGestãoProdutos ? 'background: rgba(40, 167, 69, 0.02);' : ''} transition: background 0.2s;" 
                onmouseover="this.style.background='rgba(40, 167, 69, 0.08)'" 
                onmouseout="this.style.background='${modoGestãoProdutos ? 'rgba(40, 167, 69, 0.02)' : 'white'}'">
                <td style="padding: 1rem;">
                    <div>
                        <strong style="color: var(--primary-color); font-size: 1rem;">
                            ${produto.produto_nome || produto.descricao_produto || 'Produto sem nome'}
                        </strong>
                        ${produto.produto_codigo ? `<br><small style="color: var(--medium-gray); display: flex; align-items: center; gap: 0.25rem; margin-top: 0.25rem;"><i class="fas fa-barcode"></i> ${produto.produto_codigo}</small>` : ''}
                        ${produto.produto_categoria ? `<br><small style="color: var(--info-color); display: flex; align-items: center; gap: 0.25rem; margin-top: 0.25rem;"><i class="fas fa-tag"></i> ${produto.produto_categoria}</small>` : ''}
                    </div>
                </td>
                <td style="padding: 1rem; text-align: center;">
                    <span style="background: var(--info-color); color: white; padding: 0.5rem 0.75rem; border-radius: 20px; font-weight: 600; font-size: 0.9rem;">
                        ${quantidade.toLocaleString('pt-BR')}
                    </span>
                </td>
                <td style="padding: 1rem; text-align: right;">
                    <span style="font-weight: 600; color: var(--success-color); font-size: 1rem;">
                        R$ ${valorUnitario.toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                    </span>
                </td>
                <td style="padding: 1rem; text-align: right;">
                    <span style="font-weight: 700; color: var(--primary-color); font-size: 1.1rem;">
                        R$ ${valorTotal.toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                    </span>
                </td>
                ${modoGestãoProdutos ? `
                <td style="padding: 1rem; text-align: center;">
                    <div style="display: flex; gap: 0.5rem; justify-content: center;">
                        <button class="btn-action btn-edit" onclick="editarProduto(${index})" title="Editar produto" style="background: var(--warning-color); color: white; border: none; padding: 0.5rem; border-radius: 6px; cursor: pointer; transition: transform 0.2s;">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn-action btn-remove" onclick="removerProduto(${index})" title="Remover produto" style="background: var(--danger-color); color: white; border: none; padding: 0.5rem; border-radius: 6px; cursor: pointer; transition: transform 0.2s;">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
                ` : ''}
            </tr>
        `;
    });

    html += `
                </tbody>
                <tfoot>
                    <tr style="background: linear-gradient(135deg, var(--light-gray), #e9ecef); font-weight: 700; border-top: 2px solid var(--border-color);">
                        <td colspan="${modoGestãoProdutos ? '4' : '3'}" style="padding: 1.5rem; text-align: right; font-size: 1.1rem;">
                            <strong style="color: var(--primary-color);">TOTAL GERAL:</strong>
                        </td>
                        <td style="padding: 1.5rem; text-align: right; color: var(--primary-color); font-size: 1.2rem;">
                            <strong>R$ ${(estatisticas.valor_total_produtos || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</strong>
                        </td>
                        ${modoGestãoProdutos ? '<td style="padding: 1.5rem;"></td>' : ''}
                    </tr>
                </tfoot>
            </table>
        </div>
    `;

    container.innerHTML = html;
}

/**
 * Atualiza contador de produtos
 */
function atualizarContadorProdutos() {
    const contadorView = document.getElementById('contadorProdutos');
    const contadorEdit = document.getElementById('contadorProdutosEdit');
    const total = produtosEmpenho.length;
    const texto = `${total} ${total === 1 ? 'produto' : 'produtos'}`;
    
    if (contadorView) contadorView.textContent = texto;
    if (contadorEdit) contadorEdit.textContent = texto;
}

// ===========================================
// FUNÇÕES DE EDIÇÃO CORRIGIDAS
// ===========================================

/**
 * Ativa o modo de edição completo - CORRIGIDO para ativar produtos automaticamente
 */
function editarEmpenhoCompleto() {
    console.log('🖊️ ATIVANDO MODO DE EDIÇÃO COMPLETO - DEBUG ATIVO');
    
    try {
        const viewMode = document.getElementById('empenhoViewMode');
        const editForm = document.getElementById('empenhoEditForm');
        const editarBtn = document.getElementById('editarBtn');
        
        console.log('Elementos encontrados:', {
            viewMode: !!viewMode,
            editForm: !!editForm,
            editarBtn: !!editarBtn
        });
        
        if (viewMode) viewMode.style.display = 'none';
        if (editForm) editForm.style.display = 'block';
        if (editarBtn) editarBtn.style.display = 'none';
        
        modoEdicaoAtivo = true;
        modoGestãoProdutos = true;
        
        // Força configuração dos event listeners
        setTimeout(() => {
            try {
                configurarEventListenersModal();
                
                // Verifica se os elementos do autocomplete existem antes de inicializar
                const clienteInput = document.getElementById('clienteNomeEdit');
                const suggestionsContainer = document.getElementById('clienteEditSuggestions');
                
                console.log('Elementos do autocomplete:', {
                    clienteInput: !!clienteInput,
                    suggestionsContainer: !!suggestionsContainer
                });
                
                if (clienteInput && suggestionsContainer) {
                    initClienteAutoCompleteEdit();
                    console.log('✅ Autocomplete inicializado com sucesso');
                } else {
                    console.warn('⚠️ Elementos do autocomplete não encontrados, tentando novamente...');
                    
                    // Tenta novamente após mais um tempo
                    setTimeout(() => {
                        const clienteInput2 = document.getElementById('clienteNomeEdit');
                        const suggestionsContainer2 = document.getElementById('clienteEditSuggestions');
                        
                        if (clienteInput2 && suggestionsContainer2) {
                            initClienteAutoCompleteEdit();
                            console.log('✅ Autocomplete inicializado na segunda tentativa');
                        } else {
                            console.error('❌ Elementos do autocomplete ainda não encontrados');
                        }
                    }, 500);
                }
                
                console.log('🔧 Event listeners e autocomplete configurados');
            } catch (error) {
                console.error('❌ Erro ao configurar listeners:', error);
            }
        }, 200);
        
        // Recarrega produtos no modo edição
        carregarProdutosEmpenhoCompleto(currentEmpenhoId);
        
        // Atualiza botão de gestão
        const toggleBtn = document.getElementById('toggleProdutosBtn');
        if (toggleBtn) {
            toggleBtn.innerHTML = '<i class="fas fa-eye"></i> Visualizar';
            toggleBtn.className = 'btn btn-info btn-sm';
        }
        
        showToast('Modo de edição ativado - Gestão de produtos habilitada automaticamente', 'info', 5000);
        
    } catch (error) {
        console.error('❌ Erro na função editarEmpenhoCompleto:', error);
        showToast('Erro ao ativar modo de edição: ' + error.message, 'error');
    }
}

/**
 * Adiciona a função editarEmpenho que estava faltando (apontando para a função completa)
 */
function editarEmpenho() {
    editarEmpenhoCompleto();
}

/**
 * Cancela a edição completa
 */
function cancelarEdicaoCompleta() {
    const confirmCancel = confirm(
        'Tem certeza que deseja cancelar a edição?\n\n' +
        'Todas as alterações não salvas serão perdidas.\n\n' +
        'Isso inclui alterações no empenho e nos produtos.'
    );
    
    if (!confirmCancel) return;
    
    const viewMode = document.getElementById('empenhoViewMode');
    const editForm = document.getElementById('empenhoEditForm');
    const editarBtn = document.getElementById('editarBtn');
    
    if (viewMode) viewMode.style.display = 'block';
    if (editForm) editForm.style.display = 'none';
    if (editarBtn) editarBtn.style.display = 'inline-flex';
    
    modoEdicaoAtivo = false;
    modoGestãoProdutos = false;
    produtosAlterados = false;
    
    // Recarrega produtos no modo visualização
    carregarProdutosEmpenhoCompleto(currentEmpenhoId);
    
    showToast('Edição cancelada', 'info');
}

/**
 * Ativa/Desativa o modo de gestão de produtos
 */
function toggleGestãoProdutos() {
    modoGestãoProdutos = !modoGestãoProdutos;
    const toggleBtn = document.getElementById('toggleProdutosBtn');
    
    if (modoGestãoProdutos) {
        if (toggleBtn) {
            toggleBtn.innerHTML = '<i class="fas fa-eye"></i> Visualizar';
            toggleBtn.className = 'btn btn-info btn-sm';
        }
        showToast('Modo de gestão de produtos ativado', 'info');
    } else {
        if (toggleBtn) {
            toggleBtn.innerHTML = '<i class="fas fa-cogs"></i> Gerenciar';
            toggleBtn.className = 'btn btn-light btn-sm';
        }
        
        // Fecha formulário se aberto
        const formContainer = document.getElementById('formProdutoContainer');
        if (formContainer) formContainer.style.display = 'none';
        
        showToast('Modo de visualização ativado', 'info');
    }
    
    // Re-renderiza os produtos
    const data = {
        produtos: produtosEmpenho,
        estatisticas: calcularEstatisticasLocais()
    };
    renderizarProdutosEdicao(data);
}

/**
 * Salva todas as alterações do empenho - CORRIGIDO
 */
function salvarEdicaoCompleta(event) {
    console.log('💾 INICIANDO SALVAMENTO DO EMPENHO');
    
    if (event && event.preventDefault) {
        event.preventDefault();
    }
    if (event && event.stopPropagation) {
        event.stopPropagation();
    }
    
    const form = event.target || document.getElementById('empenhoEditForm');
    if (!form) {
        console.error('❌ ERRO: Formulário não encontrado!');
        showToast('Erro: Formulário não encontrado', 'error');
        return;
    }
    
    // Validação de campos obrigatórios
    const camposObrigatorios = form.querySelectorAll('input[required], select[required]');
    let camposVazios = [];

    camposObrigatorios.forEach(campo => {
        if (campo.offsetParent !== null && !campo.value.trim()) {
            camposVazios.push(campo.name || campo.id);
        }
    });
    
    if (camposVazios.length > 0) {
        console.warn('⚠️ Campos obrigatórios vazios:', camposVazios);
        showToast(`Preencha os campos obrigatórios: ${camposVazios.join(', ')}`, 'warning');
        return;
    }
    
    const formData = new FormData(form);
    const submitBtn = document.getElementById('salvarEdicaoBtn');
    
    // Desabilita o botão e mostra loading
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    }
    
    showToast('Salvando alterações...', 'info', 2000);
    
    // CORREÇÃO: Usar a mesma página com identificação AJAX
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(response => {
        console.log('📡 Status da resposta:', response.status);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('Resposta não-JSON recebida:', text.substring(0, 500));
                throw new Error('Resposta inválida do servidor');
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('✅ Dados recebidos:', data);
        
        if (data.success) {
            showToast('Empenho atualizado com sucesso!', 'success');
            
            produtosAlterados = false;
            
            // Recarrega o modal após 1.5 segundos
            setTimeout(() => {
                console.log('🔄 Recarregando modal...');
                openModal(currentEmpenhoId);
                
                // Atualiza a página se necessário
                setTimeout(() => {
                    if (window.location.href.includes('consulta_empenho.php')) {
                        window.location.reload();
                    }
                }, 2000);
            }, 1500);
            
        } else {
            throw new Error(data.error || 'Erro ao salvar empenho');
        }
    })
    .catch(error => {
        console.error('❌ Erro ao salvar empenho:', error);
        showToast('Erro ao salvar: ' + error.message, 'error');
    })
    .finally(() => {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Salvar Todas as Alterações';
        }
    });
}

// ===========================================
// GESTÃO DE PRODUTOS - FORMULÁRIO
// ===========================================

/**
 * Abre o formulário de produto - VERSÃO FINAL OTIMIZADA
 */
function abrirFormularioProduto() {
    const formContainer = document.getElementById('formProdutoContainer');
    const formTitle = document.getElementById('formProdutoTitle');
    const form = document.getElementById('formProduto');
    
    if (formContainer) formContainer.style.display = 'block';
    if (formTitle) formTitle.textContent = 'Adicionar Produto';
    if (form) form.reset();
    
    currentEditingProduct = null;
    document.getElementById('produtoIndex').value = '';
    document.getElementById('produtoId').value = '';
    
    // Limpa informações anteriores
    limparInformacoesProduto();
    
    // Inicializa autocomplete se não foi inicializado ainda
    const produtoNomeInput = document.getElementById('produtoNome');
    if (produtoNomeInput && !produtoNomeInput.dataset.autocompleteInit) {
        initProdutoAutoComplete();
        produtoNomeInput.dataset.autocompleteInit = 'true';
        console.log('🔍 Autocomplete inicializado para novo formulário');
    }
    
    // Foca no campo do produto
    setTimeout(() => {
        if (produtoNomeInput) {
            produtoNomeInput.focus();
            produtoNomeInput.placeholder = 'Digite pelo menos 2 caracteres para buscar...';
        }
    }, 100);
    
    console.log('📝 Formulário de produto aberto com autocomplete ativo');
}

/**
 * Cancela o formulário de produto
 */
function cancelarFormularioProduto() {
    const formContainer = document.getElementById('formProdutoContainer');
    if (formContainer) formContainer.style.display = 'none';
    currentEditingProduct = null;
    limparInformacoesProduto();
}

/**
 * Edita um produto existente
 */
function editarProduto(index) {
    const produto = produtosEmpenho[index];
    if (!produto) return;

    currentEditingProduct = index;
    const formContainer = document.getElementById('formProdutoContainer');
    const formTitle = document.getElementById('formProdutoTitle');
    
    if (formContainer) formContainer.style.display = 'block';
    if (formTitle) formTitle.textContent = 'Editar Produto';
    
    document.getElementById('produtoIndex').value = index;
    document.getElementById('produtoId').value = produto.id || '';
    document.getElementById('produtoNome').value = produto.produto_nome || produto.descricao_produto || '';
    document.getElementById('produtoQuantidade').value = produto.quantidade || '';
    document.getElementById('produtoValorUnitario').value = parseFloat(produto.valor_unitario || 0).toFixed(2);
    document.getElementById('produtoDescricao').value = produto.descricao_produto || '';
    
    calcularValorTotalProduto();
}

/**
 * Remove um produto - CORRIGIDO para funcionar corretamente
 */
function removerProduto(index) {
    const produto = produtosEmpenho[index];
    if (!produto) return;

    const confirmacao = confirm(
        `Tem certeza que deseja remover o produto?\n\n` +
        `${produto.produto_nome || produto.descricao_produto}\n` +
        `Quantidade: ${produto.quantidade}\n` +
        `Valor: R$ ${parseFloat(produto.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}\n\n` +
        `Esta alteração será permanente!`
    );

    if (confirmacao) {
        // Desabilita botões para evitar cliques múltiplos
        const removeButtons = document.querySelectorAll('.btn-remove');
        removeButtons.forEach(btn => btn.disabled = true);

        // Remove do servidor se tem ID
        if (produto.id) {
            const formData = new FormData();
            formData.append('action', 'remove');
            formData.append('empenho_id', currentEmpenhoId);
            formData.append('produto_id', produto.id);

            fetch('gerenciar_produtos_empenho.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove do array local
                    produtosEmpenho.splice(index, 1);
                    produtosAlterados = true;
                    
                    // Re-renderiza
                    const dados = {
                        produtos: produtosEmpenho,
                        estatisticas: calcularEstatisticasLocais()
                    };
                    renderizarProdutosEdicao(dados);
                    renderizarProdutosVisualizacao(dados);
                    atualizarContadorProdutos();
                    
                    showToast('Produto removido com sucesso!', 'success');
                } else {
                    throw new Error(data.error || 'Erro ao remover produto');
                }
            })
            .catch(error => {
                console.error('Erro ao remover produto:', error);
                showToast('Erro ao remover produto: ' + error.message, 'error');
            })
            .finally(() => {
                // Reabilita botões
                const removeButtons = document.querySelectorAll('.btn-remove');
                removeButtons.forEach(btn => btn.disabled = false);
            });
        } else {
            // Se não tem ID (produto novo), apenas remove do array local
            produtosEmpenho.splice(index, 1);
            produtosAlterados = true;
            
            const dados = {
                produtos: produtosEmpenho,
                estatisticas: calcularEstatisticasLocais()
            };
            renderizarProdutosEdicao(dados);
            renderizarProdutosVisualizacao(dados);
            atualizarContadorProdutos();
            
            showToast('Produto removido (lembre-se de salvar as alterações)', 'warning');
        }
    }
}

/**
 * Salva o produto (adicionar ou editar) - CORRIGIDO
 */
function salvarProduto(event) {
    event.preventDefault();

    const formData = {
        produto_id: document.getElementById('produtoId')?.value || null,
        nome: document.getElementById('produtoNome')?.value.trim(),
        quantidade: parseInt(document.getElementById('produtoQuantidade')?.value) || 0,
        valor_unitario: parseFloat(document.getElementById('produtoValorUnitario')?.value) || 0,
        descricao_produto: document.getElementById('produtoDescricao')?.value.trim(),
        empenho_id: currentEmpenhoId
    };

    // Validações
    if (!formData.nome) {
        showToast('Nome do produto é obrigatório', 'error');
        return;
    }
    
    if (formData.quantidade <= 0) {
        showToast('Quantidade deve ser maior que zero', 'error');
        return;
    }
    
    if (formData.valor_unitario <= 0) {
        showToast('Valor unitário deve ser maior que zero', 'error');
        return;
    }

    formData.valor_total = formData.quantidade * formData.valor_unitario;

    // Desabilita o botão
    const submitBtn = document.getElementById('salvarProdutoBtn');
    const originalText = submitBtn?.innerHTML;
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    }

    // Determina se é edição ou adição
    const isEditing = currentEditingProduct !== null;

    // Salva no servidor
    const serverFormData = new FormData();
    serverFormData.append('action', isEditing ? 'edit' : 'add');
    serverFormData.append('empenho_id', currentEmpenhoId);
    
    if (isEditing && produtosEmpenho[currentEditingProduct]?.id) {
        serverFormData.append('produto_id', produtosEmpenho[currentEditingProduct].id);
    }
    
    Object.keys(formData).forEach(key => {
        if (formData[key] !== null && formData[key] !== undefined) {
            serverFormData.append(key, formData[key]);
        }
    });

    fetch('gerenciar_produtos_empenho.php', {
        method: 'POST',
        body: serverFormData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Atualiza array local
            if (isEditing) {
                produtosEmpenho[currentEditingProduct] = {
                    ...produtosEmpenho[currentEditingProduct],
                    ...formData,
                    produto_nome: formData.nome,
                    id: data.produto_empenho_id || produtosEmpenho[currentEditingProduct].id
                };
            } else {
                produtosEmpenho.push({
                    id: data.produto_empenho_id || Date.now(),
                    ...formData,
                    produto_nome: formData.nome
                });
            }

            produtosAlterados = true;

            // Re-renderiza
            const dados = {
                produtos: produtosEmpenho,
                estatisticas: calcularEstatisticasLocais()
            };
            renderizarProdutosEdicao(dados);
            renderizarProdutosVisualizacao(dados);
            atualizarContadorProdutos();

            cancelarFormularioProduto();
            showToast(`Produto ${isEditing ? 'atualizado' : 'adicionado'} com sucesso!`, 'success');
        } else {
            throw new Error(data.error || 'Erro ao salvar produto');
        }
    })
    .catch(error => {
        console.error('Erro ao salvar produto:', error);
        showToast('Erro ao salvar produto: ' + error.message, 'error');
    })
    .finally(() => {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    });
}

/**
 * Calcula o valor total do produto
 */
function calcularValorTotalProduto() {
    const quantidade = parseFloat(document.getElementById('produtoQuantidade')?.value) || 0;
    const valorUnitario = parseFloat(document.getElementById('produtoValorUnitario')?.value) || 0;
    const valorTotal = quantidade * valorUnitario;
    
    const valorTotalInput = document.getElementById('produtoValorTotal');
    if (valorTotalInput) {
        valorTotalInput.value = 'R$ ' + valorTotal.toLocaleString('pt-BR', {minimumFractionDigits: 2});
    }
}

// ===========================================
// FUNÇÕES DE AÇÃO DO EMPENHO
// ===========================================

/**
 * Converte empenho em venda
 */
function converterEmVenda() {
    if (!currentEmpenhoData) return;
    
    // Verifica se já foi convertido
    if (currentEmpenhoData.ja_convertido) {
        showToast('Este empenho já foi convertido em venda', 'warning');
        return;
    }
    
    const confirmMessage = 
        `Converter empenho em venda?\n\n` +
        `Empenho: ${currentEmpenhoData.numero || 'N/A'}\n` +
        `Cliente: ${currentEmpenhoData.cliente_nome || 'N/A'}\n` +
        `Valor: R$ ${parseFloat(currentEmpenhoData.valor_total_empenho || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}\n\n` +
        `Esta ação criará uma nova venda baseada neste empenho.`;
    
    if (confirm(confirmMessage)) {
        const venderBtn = document.getElementById('venderBtn');
        if (venderBtn) {
            venderBtn.disabled = true;
            venderBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Convertendo...';
        }
        
        fetch('converter_empenho_venda.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `empenho_id=${currentEmpenhoId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Empenho convertido em venda com sucesso!', 'success');
                
                // Atualiza dados locais
                currentEmpenhoData.ja_convertido = true;
                currentEmpenhoData.venda_convertida = {
                    id: data.venda_id,
                    numero: data.venda_numero
                };
                
                updateVenderButtonVisibility();
                
                // Oferece para abrir a venda
                setTimeout(() => {
                    if (confirm('Deseja visualizar a venda criada?')) {
                        window.open(`consulta_vendas.php?venda_id=${data.venda_id}`, '_blank');
                    }
                }, 1500);
                
            } else {
                throw new Error(data.error || 'Erro ao converter empenho');
            }
        })
        .catch(error => {
            console.error('Erro ao converter empenho:', error);
            showToast('Erro ao converter: ' + error.message, 'error');
        })
        .finally(() => {
            if (venderBtn) {
                venderBtn.disabled = false;
                venderBtn.innerHTML = '<i class="fas fa-shopping-cart"></i> Vender';
            }
        });
    }
}

/**
 * Atualiza visibilidade do botão "Vender"
 */
function updateVenderButtonVisibility() {
    const venderBtn = document.getElementById('venderBtn');
    if (!venderBtn || !currentEmpenhoData) return;
    
    if (currentEmpenhoData.ja_convertido) {
        venderBtn.style.display = 'none';
        
        // Adiciona informação sobre a venda convertida
        const modalFooter = document.getElementById('modalFooter');
        if (modalFooter && !document.getElementById('vendaInfo')) {
            const vendaInfo = document.createElement('div');
            vendaInfo.id = 'vendaInfo';
            vendaInfo.style.cssText = `
                background: rgba(40, 167, 69, 0.1);
                color: var(--success-color);
                padding: 0.75rem 1rem;
                border-radius: var(--radius-sm);
                border: 1px solid var(--success-color);
                margin-right: auto;
                display: flex;
                align-items: center;
                gap: 0.5rem;
                font-weight: 600;
            `;
            vendaInfo.innerHTML = `
                <i class="fas fa-check-circle"></i>
                Convertido em venda: #${currentEmpenhoData.venda_convertida?.numero || 'N/A'}
            `;
            modalFooter.insertBefore(vendaInfo, modalFooter.firstChild);
        }
    } else {
        venderBtn.style.display = 'inline-flex';
        
        // Remove informação sobre venda se existir
        const vendaInfo = document.getElementById('vendaInfo');
        if (vendaInfo) {
            vendaInfo.remove();
        }
    }
}

/**
 * Exclui empenho
 */
function excluirEmpenho() {
    if (!currentEmpenhoId) return;
    
    const excluirBtn = document.getElementById('excluirBtn') || document.getElementById('excluirEdicaoBtn');
    if (excluirBtn) {
        excluirBtn.disabled = true;
        excluirBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';
    }
    
    fetch('consulta_empenho.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `delete_empenho_id=${currentEmpenhoId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Empenho excluído com sucesso!', 'success');
            
            // Fecha o modal
            closeModal();
            
            // Recarrega a página após um breve delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
            
        } else {
            throw new Error(data.error || 'Erro ao excluir empenho');
        }
    })
    .catch(error => {
        console.error('Erro ao excluir empenho:', error);
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
 * Confirma exclusão
 */
function confirmarExclusao() {
    if (!currentEmpenhoData) return;
    
    const confirmMessage = 
        `⚠️ ATENÇÃO: EXCLUSÃO PERMANENTE ⚠️\n\n` +
        `Tem certeza que deseja EXCLUIR permanentemente este empenho?\n\n` +
        `Empenho: ${currentEmpenhoData.numero || 'N/A'}\n` +
        `Cliente: ${currentEmpenhoData.cliente_nome || 'N/A'}\n` +
        `Valor: R$ ${parseFloat(currentEmpenhoData.valor_total_empenho || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}\n\n` +
        `⚠️ Esta ação NÃO PODE ser desfeita!\n` +
        `⚠️ Todos os produtos relacionados também serão excluídos!\n\n` +
        `Digite "CONFIRMAR" para prosseguir:`;
    
    const confirmacao = prompt(confirmMessage);
    
    if (confirmacao === 'CONFIRMAR') {
        excluirEmpenho();
    } else if (confirmacao !== null) {
        showToast('Exclusão cancelada - confirmação incorreta', 'warning');
    }
}

/**
 * Confirma exclusão durante a edição
 */
function confirmarExclusaoEdicao() {
    confirmarExclusao(); // Usa a mesma função
}

/**
 * Imprime empenho
 */
function imprimirEmpenho() {
    if (!currentEmpenhoId) return;
    
    const printUrl = `imprimir_empenho.php?id=${currentEmpenhoId}`;
    window.open(printUrl, '_blank', 'width=800,height=600');
}

// ===========================================
// FUNÇÕES DE CLASSIFICAÇÃO E FILTROS
// ===========================================

/**
 * Atualiza classificação do empenho via AJAX
 */
function updateClassificacao(selectElement) {
    const empenhoId = selectElement.dataset.empenhoId;
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
    
    fetch('consulta_empenho.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `update_classificacao=1&empenho_id=${empenhoId}&classificacao=${encodeURIComponent(novaClassificacao)}`
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
    let url = 'consulta_empenho.php?';
    
    switch(tipo) {
        case 'atraso':
            // Implementar filtro para empenhos em atraso
            showToast('Filtrando empenhos em atraso...', 'info');
            url += 'filtro=atraso';
            break;
        case 'Pendente':
        case 'Faturado':
        case 'Entregue':
        case 'Liquidado':
        case 'Pago':
        case 'Cancelado':
        case 'Vendido':
            url += `classificacao=${encodeURIComponent(tipo)}`;
            break;
        default:
            showToast('Filtro não implementado: ' + tipo, 'warning');
            return;
    }
    
    window.location.href = url;
}

// ===========================================
// FUNÇÕES UTILITÁRIAS
// ===========================================

/**
 * Obtém ícone para status/classificação
 */
function getStatusIcon(status) {
    const icons = {
        'Pendente': 'clock',
        'Faturado': 'file-invoice-dollar',
        'Entregue': 'truck',
        'Liquidado': 'calculator',
        'Pago': 'check-circle',
        'Cancelado': 'times-circle',
        'Vendido': 'shopping-cart'
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
 * Calcula estatísticas locais dos produtos
 */
function calcularEstatisticasLocais() {
    const totalProdutos = produtosEmpenho.length;
    let quantidadeTotalItens = 0;
    let valorTotalProdutos = 0;

    produtosEmpenho.forEach(produto => {
        quantidadeTotalItens += parseInt(produto.quantidade || 0);
        valorTotalProdutos += parseFloat(produto.valor_total || (produto.quantidade * produto.valor_unitario) || 0);
    });

    return {
        total_produtos: totalProdutos,
        quantidade_total_itens: quantidadeTotalItens,
        valor_total_produtos: valorTotalProdutos,
        valor_total_produtos_formatado: 'R$ ' + valorTotalProdutos.toLocaleString('pt-BR', {minimumFractionDigits: 2})
    };
}

/**
 * Limpa informações anteriores
 */
function limparInformacoesProduto() {
    const infoContainer = document.getElementById('produtoInfoContainer');
    if (infoContainer) {
        infoContainer.style.display = 'none';
        infoContainer.innerHTML = '';
    }
}

/**
 * Busca produtos no catálogo (função placeholder)
 */
function buscarProdutosCatalogo() {
    showToast('Função de catálogo será implementada em breve', 'info');
}

// ===========================================
// CONFIGURAÇÃO DE EVENT LISTENERS
// ===========================================

/**
 * Configura event listeners específicos do modal após renderização
 */
function configurarEventListenersModal() {
    console.log('🔧 Configurando event listeners específicos do modal...');
    
    // Formulário principal de edição - COM VERIFICAÇÃO DE EXISTÊNCIA
    const editForm = document.getElementById('empenhoEditForm');
    if (editForm) {
        console.log('✅ Formulário encontrado, configurando event listener...');
        
        // Remove qualquer event listener anterior
        editForm.removeEventListener('submit', salvarEdicaoCompleta);
        
        // Adiciona novo event listener
        editForm.addEventListener('submit', salvarEdicaoCompleta);
        
        console.log('✅ Event listener do formulário de edição configurado');
    } else {
        console.warn('⚠️ Formulário empenhoEditForm não encontrado');
    }

    // Event listener direto no botão de salvar como FALLBACK
    const salvarBtn = document.getElementById('salvarEdicaoBtn');
    if (salvarBtn) {
        console.log('✅ Botão salvar encontrado, configurando fallback...');
        
        // Remove listener anterior
        salvarBtn.removeEventListener('click', handleSalvarClickModal);
        
        // Adiciona listener de clique direto
        salvarBtn.addEventListener('click', handleSalvarClickModal);
        
        console.log('✅ Event listener fallback do botão salvar configurado');
    } else {
        console.warn('⚠️ Botão salvarEdicaoBtn não encontrado');
    }

    // Formulário de produto
    const formProduto = document.getElementById('formProduto');
    if (formProduto) {
        formProduto.removeEventListener('submit', salvarProduto);
        formProduto.addEventListener('submit', salvarProduto);
        console.log('✅ Event listener do formulário de produto configurado');
    }

    // Event listeners para campos de produto
    configurarEventListenersProduto();
    
    console.log('✅ Todos os event listeners do modal configurados');
}

/**
 * Configura todos os event listeners do sistema
 */
function configurarEventListeners() {
    console.log('🔧 Configurando event listeners do sistema...');
    
    // Remove event listeners anteriores para evitar duplicação
    const editForm = document.getElementById('empenhoEditForm');
    if (editForm) {
        // Remove event listener anterior se existir
        editForm.removeEventListener('submit', salvarEdicaoCompleta);
        // Adiciona novo event listener
        editForm.addEventListener('submit', salvarEdicaoCompleta);
        console.log('✅ Event listener do formulário de edição configurado');
    }

    // Formulário de produto
    const formProduto = document.getElementById('formProduto');
    if (formProduto) {
        formProduto.removeEventListener('submit', salvarProduto);
        formProduto.addEventListener('submit', salvarProduto);
        console.log('✅ Event listener do formulário de produto configurado');
    }

    // Máscara para CNPJ no modo edição
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

    // Event listeners para campos de produto
    const quantidadeInput = document.getElementById('produtoQuantidade');
    const valorUnitarioInput = document.getElementById('produtoValorUnitario');
    
    if (quantidadeInput) {
        quantidadeInput.addEventListener('input', calcularValorTotalProduto);
        quantidadeInput.addEventListener('blur', function() {
            if (!this.value || this.value === '0') {
                this.value = '1';
                calcularValorTotalProduto();
            }
        });
    }
    
    if (valorUnitarioInput) {
        valorUnitarioInput.addEventListener('input', calcularValorTotalProduto);
        valorUnitarioInput.addEventListener('blur', function() {
            let valor = parseFloat(this.value);
            if (!isNaN(valor) && valor >= 0) {
                this.value = valor.toFixed(2);
                calcularValorTotalProduto();
            }
        });
    }

    // Inicializa autocomplete para produtos se o campo existir
    const produtoNomeInput = document.getElementById('produtoNome');
    if (produtoNomeInput) {
        initProdutoAutoComplete();
        console.log('🔍 Autocomplete inicializado para campo de produto');
    }

    // Inicializa autocomplete para clientes se o campo existir
    const clienteNomeInput = document.getElementById('clienteNomeEdit');
    if (clienteNomeInput) {
        initClienteAutoComplete();
        console.log('🔍 Autocomplete inicializado para campo de cliente');
    
    
    console.log('✅ Event listeners configurados com sucesso');
}
    
    console.log('✅ Event listeners configurados com sucesso');
}
function configurarBotaoSalvarFallback() {
    const salvarBtn = document.getElementById('salvarEdicaoBtn');
    if (salvarBtn) {
        salvarBtn.removeEventListener('click', handleSalvarClick);
        salvarBtn.addEventListener('click', handleSalvarClick);
        console.log('✅ Event listener fallback do botão salvar configurado');
    }
}

/**
 * Handler direto para clique no botão salvar
 */
function handleSalvarClick(event) {
    console.log('🖱️ Clique direto no botão salvar detectado');
    event.preventDefault();
    
    const form = document.getElementById('empenhoEditForm');
    if (form) {
        // Dispara o evento de submit do formulário
        const submitEvent = new Event('submit', { 
            bubbles: true, 
            cancelable: true 
        });
        form.dispatchEvent(submitEvent);
    } else {
        console.error('❌ Formulário não encontrado');
        showToast('Erro: Formulário não encontrado', 'error');
    }
}
/**
 * Abre o formulário de produto - VERSÃO ATUALIZADA
 */
function abrirFormularioProduto() {
    const formContainer = document.getElementById('formProdutoContainer');
    const formTitle = document.getElementById('formProdutoTitle');
    const form = document.getElementById('formProduto');
    
    if (formContainer) formContainer.style.display = 'block';
    if (formTitle) formTitle.textContent = 'Adicionar Produto';
    if (form) form.reset();
    
    currentEditingProduct = null;
    document.getElementById('produtoIndex').value = '';
    document.getElementById('produtoId').value = '';
    
    // Limpa informações anteriores
    limparInformacoesProduto();
    
    // Inicializa autocomplete se não foi inicializado ainda
    const produtoNomeInput = document.getElementById('produtoNome');
    if (produtoNomeInput && !produtoNomeInput.dataset.autocompleteInit) {
        initProdutoAutoComplete();
        produtoNomeInput.dataset.autocompleteInit = 'true';
        console.log('🔍 Autocomplete inicializado para novo formulário');
    }
    
    // Foca no campo do produto
    setTimeout(() => {
        if (produtoNomeInput) {
            produtoNomeInput.focus();
            produtoNomeInput.placeholder = 'Digite o nome do produto ou código...';
        }
    }, 100);
    
    console.log('📝 Formulário de produto aberto com autocomplete ativo');
}

// ===========================================
// SISTEMA DE ORDENAÇÃO DA TABELA
// ===========================================

let currentSort = {
    column: null,
    direction: 'asc'
};

/**
 * Ordena a tabela por coluna
 */
function ordenarTabela(coluna) {
    console.log('🔄 Ordenando tabela por:', coluna);
    
    // Determina direção da ordenação
    if (currentSort.column === coluna) {
        currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
    } else {
        currentSort.direction = 'asc';
    }
    
    currentSort.column = coluna;
    
    // Atualiza ícones de ordenação
    atualizarIconesOrdenacao(coluna, currentSort.direction);
    
    // Obtém parâmetros atuais da URL
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('ordenar', coluna);
    urlParams.set('direcao', currentSort.direction);
    urlParams.delete('pagina'); // Reset para primeira página
    
    // Recarrega a página com nova ordenação
    window.location.href = window.location.pathname + '?' + urlParams.toString();
}

/**
 * Atualiza ícones de ordenação
 */
function atualizarIconesOrdenacao(colunaAtiva, direcao) {
    // Remove classes de todos os ícones
    document.querySelectorAll('.sort-icon').forEach(icon => {
        icon.className = 'fas fa-sort sort-icon';
    });
    
    // Adiciona classe ao ícone ativo
    const iconAtivo = document.getElementById('sort-' + colunaAtiva);
    if (iconAtivo) {
        if (direcao === 'asc') {
            iconAtivo.className = 'fas fa-sort-up sort-icon sort-asc';
        } else {
            iconAtivo.className = 'fas fa-sort-down sort-icon sort-desc';
        }
    }
}

// Inicializa ordenação baseada na URL
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const ordenar = urlParams.get('ordenar');
    const direcao = urlParams.get('direcao') || 'asc';
    
    if (ordenar) {
        currentSort.column = ordenar;
        currentSort.direction = direcao;
        atualizarIconesOrdenacao(ordenar, direcao);
    }
});


// ===========================================
// INICIALIZAÇÃO DO SISTEMA
// ===========================================

/**
 * Inicialização quando a página carrega
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 LicitaSis - Sistema Completo de Consulta de Empenhos carregado');
    
    // Inicializa event listeners para classificação na tabela principal
    document.querySelectorAll('.classificacao-select').forEach(select => {
        select.addEventListener('change', function() {
            updateClassificacao(this);
        });
        
        // Armazena valor inicial
        select.dataset.valorAnterior = select.value;
    });
    
    // Event listener para fechar modal com ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const modal = document.getElementById('empenhoModal');
            if (modal && modal.style.display === 'block') {
                closeModal();
            }
        }
    });
    
    // Event listener para clicar fora do modal
    const modal = document.getElementById('empenhoModal');
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
    
    // Event listener para o filtro de classificação
    const classificacaoSelect = document.getElementById('classificacao');
    if (classificacaoSelect) {
        classificacaoSelect.addEventListener('change', function() {
            const form = document.getElementById('filtersForm');
            if (form) form.submit();
        });
    }
    
    // Adiciona listeners para estatísticas navegáveis
    document.querySelectorAll('.stat-navegavel').forEach(stat => {
        stat.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            }
        });
        
        // Torna focável para acessibilidade
        stat.setAttribute('tabindex', '0');
        stat.setAttribute('role', 'button');
    });
    
    console.log('✅ Todos os event listeners da página inicializados');
});

// ===========================================
// EXPORTA FUNÇÕES GLOBAIS
// ===========================================

// Torna as principais funções globalmente acessíveis
window.openModal = openModal;
window.closeModal = closeModal;
window.ordenarTabela = ordenarTabela;
window.atualizarIconesOrdenacao = atualizarIconesOrdenacao;
window.editarEmpenho = editarEmpenho;
window.editarEmpenhoCompleto = editarEmpenhoCompleto;
window.cancelarEdicaoCompleta = cancelarEdicaoCompleta;
window.toggleGestãoProdutos = toggleGestãoProdutos;
window.abrirFormularioProduto = abrirFormularioProduto;
window.cancelarFormularioProduto = cancelarFormularioProduto;
window.editarProduto = editarProduto;
window.removerProduto = removerProduto;
window.converterEmVenda = converterEmVenda;
window.confirmarExclusao = confirmarExclusao;
window.confirmarExclusaoEdicao = confirmarExclusaoEdicao;
window.imprimirEmpenho = imprimirEmpenho;
window.updateClassificacao = updateClassificacao;
window.limparFiltros = limparFiltros;
window.navegarParaDetalhes = navegarParaDetalhes;
window.buscarProdutosCatalogo = buscarProdutosCatalogo;

// Marca o sistema como carregado
window.licitasisLoaded = true;

// Log final
console.log('🎉 LicitaSis - Sistema Completo de Consulta de Empenhos totalmente carregado e funcional!');

// Notifica que o sistema está pronto
document.dispatchEvent(new CustomEvent('licitasisReady', {
    detail: {
        version: '7.1',
        features: ['empenhos', 'produtos', 'autocomplete', 'modal', 'filtros', 'edicao_integrada'],
        timestamp: new Date().toISOString()
    }
}));

// ===========================================
// SISTEMA DE AUTOCOMPLETE PARA PRODUTOS
// ===========================================

/**
 * Inicializa o autocomplete para produtos - VERSÃO OTIMIZADA
 */
function initProdutoAutoComplete() {
    const inputElement = document.getElementById('produtoNome');
    const suggestionsContainer = document.getElementById('produtoSuggestions');
    
    if (!inputElement || !suggestionsContainer) {
        console.warn('Elementos do autocomplete não encontrados');
        return;
    }

    let isVisible = false;
    let selectedIndex = -1;
    
    // Inicializa variável global
    window.produtosSugeridos = [];
    
    // Event listeners otimizados
    inputElement.addEventListener('input', function(e) {
        clearTimeout(searchTimeout);
        const termo = e.target.value.trim();
        
        if (termo.length < 2) {
            hideSuggestions();
            limparProdutoSelecionado();
            return;
        }
        
        // Mostra indicador de loading visual
        inputElement.style.backgroundImage = "url('data:image/svg+xml;charset=utf-8,%3Csvg xmlns%3D%22http%3A//www.w3.org/2000/svg%22 width%3D%2216%22 height%3D%2216%22 viewBox%3D%220 0 16 16%22%3E%3Cpath fill%3D%22%23999%22 d%3D%22M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0zM8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2z%22/%3E%3C/svg%3E')";
        inputElement.style.backgroundRepeat = 'no-repeat';
        inputElement.style.backgroundPosition = 'right 10px center';
        inputElement.style.backgroundSize = '16px';
        
        searchTimeout = setTimeout(() => {
            buscarProdutos(termo);
        }, 250); // Reduzido para 250ms para maior responsividade
    });
    
    inputElement.addEventListener('keydown', function(e) {
        if (!isVisible) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const termo = this.value.trim();
                if (termo.length >= 2) {
                    buscarProdutoExato(termo);
                }
            }
            return;
        }
        
        // Navegação por teclado nas sugestões
        const suggestions = suggestionsContainer.querySelectorAll('.suggestion-item');
        
        switch(e.key) {
            case 'ArrowDown':
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, suggestions.length - 1);
                updateSelection(suggestions);
                break;
            case 'ArrowUp':
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                updateSelection(suggestions);
                break;
            case 'Enter':
                e.preventDefault();
                if (selectedIndex >= 0 && suggestions[selectedIndex]) {
                    suggestions[selectedIndex].click();
                } else {
                    buscarProdutoExato(this.value.trim());
                }
                break;
            case 'Escape':
                e.preventDefault();
                hideSuggestions();
                break;
        }
    });
    
    inputElement.addEventListener('blur', function() {
        // Delay para permitir clique na sugestão
        setTimeout(() => {
            hideSuggestions();
        }, 200);
    });
    
    inputElement.addEventListener('focus', function() {
        const termo = this.value.trim();
        if (termo.length >= 2 && window.produtosSugeridos.length > 0) {
            mostrarSugestoes(window.produtosSugeridos);
        }
    });
    
    // Função para atualizar seleção visual
    function updateSelection(suggestions) {
        suggestions.forEach((item, index) => {
            if (index === selectedIndex) {
                item.style.background = 'var(--secondary-color)';
                item.style.color = 'white';
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.style.background = 'white';
                item.style.color = 'inherit';
            }
        });
    }
    
    // Busca produtos otimizada
    function buscarProdutos(termo) {
        console.log('🔍 Buscando produtos para termo:', termo);
        
        fetch(`buscar_produtos_autocomplete.php?termo=${encodeURIComponent(termo)}&limit=8&t=${Date.now()}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('📦 Resposta da busca:', data);
                
                if (data.success && data.produtos && data.produtos.length > 0) {
                    window.produtosSugeridos = data.produtos;
                    mostrarSugestoes(data.produtos);
                } else {
                    hideSuggestions();
                    if (data.error) {
                        console.error('Erro na busca:', data.error);
                    }
                }
            })
            .catch(error => {
                console.error('Erro ao buscar produtos:', error);
                hideSuggestions();
                if (navigator.onLine) {
                    showToast('Erro ao buscar produtos. Tente novamente.', 'error', 3000);
                } else {
                    showToast('Sem conexão com a internet', 'warning');
                }
            })
            .finally(() => {
                // Remove loading visual
                inputElement.style.backgroundImage = '';
            });
    }
    
    // Busca produto exato otimizada
    function buscarProdutoExato(termo) {
        if (!termo || termo.length < 2) return;
        
        inputElement.disabled = true;
        inputElement.placeholder = '🔍 Buscando produto...';
        inputElement.style.backgroundImage = "url('data:image/svg+xml;charset=utf-8,%3Csvg xmlns%3D%22http%3A//www.w3.org/2000/svg%22 width%3D%2216%22 height%3D%2216%22 viewBox%3D%220 0 16 16%22%3E%3Cpath fill%3D%22%23007bff%22 d%3D%22M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0zM8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2z%22/%3E%3C/svg%3E')";
        inputElement.style.backgroundRepeat = 'no-repeat';
        inputElement.style.backgroundPosition = 'right 10px center';
        
        fetch(`buscar_produtos_autocomplete.php?termo=${encodeURIComponent(termo)}&exato=1&limit=3&t=${Date.now()}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.produtos && data.produtos.length > 0) {
                    // Busca produto com correspondência exata primeiro
                    let produto = data.produtos.find(p => 
                        p.codigo.toLowerCase() === termo.toLowerCase() ||
                        p.nome.toLowerCase() === termo.toLowerCase()
                    );
                    
                    // Se não encontrou exato, pega o primeiro resultado
                    if (!produto) {
                        produto = data.produtos[0];
                    }
                    
                    preencherFormularioProdutoCompleto(produto);
                    hideSuggestions();
                } else {
                    showToast(`❌ Produto "${termo}" não encontrado`, 'warning', 4000);
                }
            })
            .catch(error => {
                console.error('Erro ao buscar produto:', error);
                showToast('Erro ao buscar produto: ' + error.message, 'error');
            })
            .finally(() => {
                inputElement.disabled = false;
                inputElement.placeholder = 'Digite o nome do produto ou código...';
                inputElement.style.backgroundImage = '';
            });
    }
    
    // Mostra sugestões otimizada
    function mostrarSugestoes(produtos) {
        if (!produtos || produtos.length === 0) {
            hideSuggestions();
            return;
        }
        
        selectedIndex = -1; // Reset seleção
        
        let html = '';
        produtos.forEach((produto, index) => {
            const precoFormatado = parseFloat(produto.preco_sugerido || 0)
                .toLocaleString('pt-BR', {minimumFractionDigits: 2});
            
            // Indicadores de status otimizados
            let statusIndicators = '';
            let statusClass = '';
            
            if (produto.tem_alertas && produto.status) {
                const alertasHtml = produto.status.map(status => {
                    if (status.includes('Sem estoque')) {
                        statusClass = 'sem-estoque';
                        return '<span style="color: var(--danger-color); font-size: 0.7rem; font-weight: 600;">🚫 SEM ESTOQUE</span>';
                    } else if (status.includes('baixo')) {
                        statusClass = 'estoque-baixo';
                        return '<span style="color: var(--warning-color); font-size: 0.7rem; font-weight: 600;">⚠️ ESTOQUE BAIXO</span>';
                    } else if (status.includes('preço')) {
                        return '<span style="color: var(--warning-color); font-size: 0.7rem; font-weight: 600;">💰 SEM PREÇO</span>';
                    }
                    return '';
                }).join(' ');
                statusIndicators = alertasHtml;
            }
            
            // Badge da margem de lucro
            let margemBadge = '';
            if (produto.margem_lucro > 0) {
                const margemClass = produto.margem_lucro >= 20 ? 'alta' : 
                                 produto.margem_lucro >= 10 ? 'media' : 'baixa';
                const margemColor = produto.margem_lucro >= 20 ? 'var(--success-color)' : 
                                  produto.margem_lucro >= 10 ? 'var(--warning-color)' : 'var(--info-color)';
                margemBadge = `<span style="background: ${margemColor}; color: white; padding: 2px 6px; border-radius: 8px; font-size: 0.7rem; font-weight: 600;">${produto.margem_lucro.toFixed(1)}%</span>`;
            }
            
            html += `
                <div class="suggestion-item" 
                     onclick="selecionarProduto(${produto.id})" 
                     data-index="${index}"
                     style="
                        padding: 0.75rem; 
                        cursor: pointer; 
                        border-bottom: 1px solid var(--border-color); 
                        transition: all 0.2s ease;
                        ${statusClass === 'sem-estoque' ? 'opacity: 0.7; border-left: 3px solid var(--danger-color);' : ''}
                        ${statusClass === 'estoque-baixo' ? 'border-left: 3px solid var(--warning-color);' : ''}
                     "
                     onmouseover="this.style.background='var(--light-gray)'; selectedIndex=${index};" 
                     onmouseout="this.style.background='white';">
                    
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.25rem;">
                        <div style="font-weight: 600; color: var(--primary-color); flex: 1;">
                            ${produto.nome}
                        </div>
                        ${margemBadge}
                    </div>
                    
                    <div style="font-size: 0.85rem; color: var(--medium-gray); margin-bottom: 0.5rem;">
                        <span><i class="fas fa-barcode" style="width: 12px;"></i> ${produto.codigo || 'S/C'}</span>
                        ${produto.categoria ? ` | <i class="fas fa-tag" style="width: 12px;"></i> ${produto.categoria}` : ''}
                        ${produto.unidade ? ` | <i class="fas fa-ruler" style="width: 12px;"></i> ${produto.unidade}` : ''}
                        ${produto.fornecedor_nome ? ` | <i class="fas fa-building" style="width: 12px;"></i> ${produto.fornecedor_nome}` : ''}
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                        <span style="color: var(--success-color); font-weight: 700; font-size: 1rem;">
                            ${precoFormatado > 0 ? `R$ ${precoFormatado}` : 'Sem preço'}
                        </span>
                        
                        ${produto.controla_estoque ? 
                            `<span style="
                                color: ${produto.sem_estoque ? 'var(--danger-color)' : produto.estoque_baixo ? 'var(--warning-color)' : 'var(--success-color)'}; 
                                font-size: 0.8rem; 
                                font-weight: 600;
                                display: flex; 
                                align-items: center; 
                                gap: 0.25rem;
                            ">
                                <i class="fas fa-boxes"></i> ${produto.estoque_formatado}
                            </span>` : 
                            '<span style="color: var(--medium-gray); font-size: 0.8rem;">Estoque não controlado</span>'
                        }
                    </div>
                    
                    ${statusIndicators ? `<div style="margin-top: 0.5rem; display: flex; gap: 0.5rem;">${statusIndicators}</div>` : ''}
                </div>
            `;
        });
        
        // Adiciona footer com estatísticas se houver muitos resultados
        if (produtos.length >= 5) {
            const comEstoque = produtos.filter(p => !p.controla_estoque || p.estoque_atual > 0).length;
            const comPreco = produtos.filter(p => p.preco_sugerido > 0).length;
            
            html += `
                <div style="padding: 0.5rem 0.75rem; background: var(--light-gray); border-top: 1px solid var(--border-color); font-size: 0.8rem; color: var(--medium-gray); text-align: center;">
                    📊 ${produtos.length} produtos encontrados | ${comEstoque} com estoque | ${comPreco} com preço
                </div>
            `;
        }
        
        suggestionsContainer.innerHTML = html;
        suggestionsContainer.style.display = 'block';
        isVisible = true;
        
        console.log(`✅ Mostrando ${produtos.length} sugestões de produtos`);
    }
    
    // Esconde sugestões
    function hideSuggestions() {
        if (suggestionsContainer) {
            suggestionsContainer.style.display = 'none';
        }
        isVisible = false;
        selectedIndex = -1;
    }
    
    console.log('✅ Autocomplete de produtos inicializado com navegação por teclado');
}

/**
 * Seleciona produto no autocomplete
 */
window.selecionarProduto = function(produtoId) {
    console.log('🔍 Selecionando produto ID:', produtoId);
    
    // Busca o produto nos dados já carregados primeiro
    const produtoExistente = window.produtosSugeridos.find(p => p.id == produtoId);
    
    if (produtoExistente) {
        // Usa dados já disponíveis
        preencherFormularioProdutoCompleto(produtoExistente);
        hideSuggestionsGlobal();
        return;
    }
    
    // Se não encontrou nos dados carregados, busca no servidor
    fetch(`buscar_produtos_autocomplete.php?produto_id=${produtoId}&t=${Date.now()}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('📦 Dados do produto recebidos:', data);
            
            if (data.success && data.produto) {
                preencherFormularioProdutoCompleto(data.produto);
                hideSuggestionsGlobal();
            } else {
                throw new Error(data.error || 'Produto não encontrado');
            }
        })
        .catch(error => {
            console.error('❌ Erro ao buscar produto:', error);
            showToast('Erro ao carregar produto: ' + error.message, 'error');
            hideSuggestionsGlobal();
        });
};

/**
 * Preenche formulário com produto selecionado
 */
function preencherFormularioProdutoCompleto(produto) {
    console.log('📝 Preenchendo formulário com produto:', produto);
    
    try {
        // Limpa informações anteriores
        limparInformacoesProduto();
        
        // Preenche campos básicos
        const produtoIdField = document.getElementById('produtoId');
        const produtoNomeField = document.getElementById('produtoNome');
        const valorUnitarioField = document.getElementById('produtoValorUnitario');
        const descricaoField = document.getElementById('produtoDescricao');
        
        if (produtoIdField) produtoIdField.value = produto.id || '';
        if (produtoNomeField) produtoNomeField.value = produto.nome || '';
        
        // Determina o melhor preço disponível
        let precoFinal = 0;
        if (produto.preco_sugerido && produto.preco_sugerido > 0) {
            precoFinal = produto.preco_sugerido;
            console.log('💰 Usando preço sugerido:', precoFinal);
        } else if (produto.preco_venda && produto.preco_venda > 0) {
            precoFinal = produto.preco_venda;
            console.log('💰 Usando preço de venda:', precoFinal);
        } else if (produto.preco_unitario && produto.preco_unitario > 0) {
            precoFinal = produto.preco_unitario;
            console.log('💰 Usando preço unitário:', precoFinal);
        }
        
        if (valorUnitarioField) {
            valorUnitarioField.value = parseFloat(precoFinal).toFixed(2);
        }
        
        // Preenche descrição
        if (descricaoField) {
            descricaoField.value = produto.observacao || produto.descricao || '';
        }

        // Mostra informações detalhadas do produto
        mostrarInformacoesProdutoCompletas(produto);
        
        // Define quantidade padrão e calcula total
        const quantidadeField = document.getElementById('produtoQuantidade');
        if (quantidadeField) {
            if (!quantidadeField.value || quantidadeField.value === '0') {
                quantidadeField.value = '1';
            }
        }
        
        // Calcula valor total
        calcularValorTotalProduto();
        
        // Foca na quantidade para facilitar a edição
        setTimeout(() => {
            if (quantidadeField) {
                quantidadeField.focus();
                quantidadeField.select();
            }
        }, 100);
        
        // Feedback visual melhorado
        showToast(`✅ Produto "${produto.nome}" selecionado`, 'success', 2000);
        
        // Verifica alertas importantes
        verificarAlertasProduto(produto, precoFinal);
        
        console.log('✅ Formulário preenchido com sucesso');
        
    } catch (error) {
        console.error('❌ Erro ao preencher formulário:', error);
        showToast('Erro ao preencher formulário do produto', 'error');
    }
}

/**
 * Verifica alertas do produto
 */
function verificarAlertasProduto(produto, precoFinal) {
    // Alerta de estoque
    if (produto.controla_estoque) {
        if (produto.estoque_atual <= 0 || produto.sem_estoque) {
            showToast(`⚠️ ATENÇÃO: Produto sem estoque!`, 'warning', 4000);
        } else if (produto.estoque_baixo || produto.estoque_atual <= 5) {
            showToast(`⚠️ Estoque baixo: ${produto.estoque_atual} unidades`, 'warning', 3000);
        }
    }
    
    // Alerta de preço
    if (precoFinal <= 0) {
        showToast(`⚠️ ATENÇÃO: Produto sem preço definido - verifique o valor!`, 'warning', 4000);
    }
    
    // Alerta de custo vs preço (margem negativa)
    if (produto.custo_total && precoFinal > 0 && produto.custo_total > precoFinal) {
        const margem = ((precoFinal - produto.custo_total) / precoFinal * 100).toFixed(1);
        showToast(`⚠️ Margem negativa: ${margem}% - Preço menor que o custo!`, 'warning', 5000);
    }
}

/**
 * limparProdutoSelecionado
 */
function limparProdutoSelecionado() {
    const produtoIdField = document.getElementById('produtoId');
    if (produtoIdField) {
        produtoIdField.value = '';
    }
    
    limparInformacoesProduto();
}

/**
 * Esconde sugestões globalmente
 */
function hideSuggestionsGlobal() {
    const suggestionsContainer = document.getElementById('produtoSuggestions');
    if (suggestionsContainer) {
        suggestionsContainer.style.display = 'none';
    }
    isVisible = false;
}

/**
 * Mostra informações detalhadas do produto
 */
function mostrarInformacoesProdutoCompletas(produto) {
    let infoContainer = document.getElementById('produtoInfoContainer');
    if (!infoContainer) return;

    let infoHtml = '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px 16px;">';
    
    // Informações básicas
    if (produto.codigo) {
        infoHtml += `
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-barcode" style="color: var(--secondary-color); width: 16px;"></i>
                <span style="color: var(--medium-gray);">Código:</span>
                <strong style="color: var(--primary-color);">${produto.codigo}</strong>
            </div>
        `;
    }
    
    if (produto.categoria) {
        infoHtml += `
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-tag" style="color: var(--info-color); width: 16px;"></i>
                <span style="color: var(--medium-gray);">Categoria:</span>
                <strong style="color: var(--info-color);">${produto.categoria}</strong>
            </div>
        `;
    }
    
    if (produto.fornecedor_nome) {
        infoHtml += `
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-building" style="color: var(--medium-gray); width: 16px;"></i>
                <span style="color: var(--medium-gray);">Fornecedor:</span>
                <strong style="color: var(--primary-color);">${produto.fornecedor_nome}</strong>
            </div>
        `;
    }

    if (produto.unidade) {
        infoHtml += `
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-ruler" style="color: var(--medium-gray); width: 16px;"></i>
                <span style="color: var(--medium-gray);">Unidade:</span>
                <strong>${produto.unidade}</strong>
            </div>
        `;
    }
    
    // Informações de estoque
    if (produto.controla_estoque) {
        const estoqueColor = produto.sem_estoque ? 'var(--danger-color)' : 
                            produto.estoque_baixo ? 'var(--warning-color)' : 'var(--success-color)';
        const estoqueIcon = produto.sem_estoque ? 'fa-times-circle' : 
                           produto.estoque_baixo ? 'fa-exclamation-triangle' : 'fa-check-circle';
        
        infoHtml += `
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fas ${estoqueIcon}" style="color: ${estoqueColor}; width: 16px;"></i>
                <span style="color: var(--medium-gray);">Estoque:</span>
                <strong style="color: ${estoqueColor};">
                    ${produto.estoque_formatado || (produto.estoque_atual + ' ' + (produto.unidade || 'UN'))}
                </strong>
            </div>
        `;
    }
    
    // Informações financeiras
    if (produto.custo_total && produto.custo_total > 0) {
        infoHtml += `
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-dollar-sign" style="color: var(--warning-color); width: 16px;"></i>
                <span style="color: var(--medium-gray);">Custo:</span>
                <strong style="color: var(--warning-color);">
                    R$ ${parseFloat(produto.custo_total).toLocaleString('pt-BR', {minimumFractionDigits: 2})}
                </strong>
            </div>
        `;
    }
    
    if (produto.margem_lucro && produto.margem_lucro > 0) {
        infoHtml += `
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-percentage" style="color: var(--success-color); width: 16px;"></i>
                <span style="color: var(--medium-gray);">Margem:</span>
                <strong style="color: var(--success-color);">
                    ${parseFloat(produto.margem_lucro).toFixed(1)}%
                </strong>
            </div>
        `;
    }
    
    infoHtml += '</div>'; // Fecha grid
    
    // Alertas importantes
    if (produto.status && produto.status.length > 0) {
        infoHtml += `<div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-color);">`;
        infoHtml += `<div style="display: flex; flex-wrap: wrap; gap: 6px;">`;
        
        produto.status.forEach(alerta => {
            const alertaColor = alerta.includes('Sem estoque') ? 'var(--danger-color)' : 'var(--warning-color)';
            infoHtml += `
                <span style="
                    background: ${alertaColor};
                    color: white;
                    padding: 4px 8px;
                    border-radius: 12px;
                    font-size: 0.75rem;
                    font-weight: 600;
                    display: flex;
                    align-items: center;
                    gap: 4px;
                ">
                    <i class="fas fa-exclamation-triangle"></i> ${alerta}
                </span>
            `;
        });
        
        infoHtml += `</div></div>`;
    }
    
    infoContainer.innerHTML = infoHtml;
    infoContainer.style.display = 'block';
    
    // Anima a entrada das informações
    infoContainer.style.opacity = '0';
    infoContainer.style.transform = 'translateY(-10px)';
    
    setTimeout(() => {
        infoContainer.style.transition = 'all 0.3s ease';
        infoContainer.style.opacity = '1';
        infoContainer.style.transform = 'translateY(0)';
    }, 50);
}

// ===========================================
// PREVENÇÃO DE PERDA DE DADOS E MANIPULADORES GLOBAIS
// ===========================================

/**
 * Previne perda de dados ao sair da página
 */
window.addEventListener('beforeunload', function(e) {
    if (modoEdicaoAtivo || produtosAlterados) {
        e.preventDefault();
        e.returnValue = 'Existem alterações não salvas. Tem certeza que deseja sair?';
        return e.returnValue;
    }
});

/**
 * Event listeners para status de conexão
 */
window.addEventListener('online', function() {
    showToast('Conexão restaurada', 'success');
});

window.addEventListener('offline', function() {
    showToast('Sem conexão com a internet', 'warning');
});

/**
 * Manipulador global de erros
 */
window.addEventListener('error', function(e) {
    console.error('Erro JavaScript:', e.error);
    if (!navigator.onLine) {
        showToast('Erro: Verifique sua conexão com a internet', 'error');
    } else {
        showToast('Ocorreu um erro inesperado. Tente recarregar a página.', 'error');
    }
});

/**
 * Manipulador para promises rejeitadas
 */
window.addEventListener('unhandledrejection', function(e) {
    console.error('Promise rejeitada:', e.reason);
    showToast('Erro ao processar requisição', 'error');
});

// ===========================================
// SISTEMA DE AUTOCOMPLETE PARA CLIENTES
// ===========================================

let clienteSearchTimeout = null;
let clienteSuggestionsVisible = false;
let selectedClienteIndex = -1;
let clientesSugeridos = [];

/**
 * Inicializa o autocomplete para clientes
 */
function initClienteAutoComplete() {
    const inputElement = document.getElementById('clienteNomeEdit');
    const suggestionsContainer = document.getElementById('clienteSuggestions');
    
    if (!inputElement || !suggestionsContainer) {
        console.warn('Elementos do autocomplete de cliente não encontrados');
        return;
    }

    let selectedIndex = -1;
    
    // Event listeners
    inputElement.addEventListener('input', function(e) {
        clearTimeout(clienteSearchTimeout);
        const termo = e.target.value.trim();
        
        if (termo.length < 2) {
            hideClienteSuggestions();
            limparClienteSelecionado();
            return;
        }
        
        // Mostra indicador de loading
        inputElement.style.backgroundImage = "url('data:image/svg+xml;charset=utf-8,%3Csvg xmlns%3D%22http%3A//www.w3.org/2000/svg%22 width%3D%2216%22 height%3D%2216%22 viewBox%3D%220 0 16 16%22%3E%3Cpath fill%3D%22%23999%22 d%3D%22M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0zM8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2z%22/%3E%3C/svg%3E')";
        inputElement.style.backgroundRepeat = 'no-repeat';
        inputElement.style.backgroundPosition = 'right 10px center';
        inputElement.style.backgroundSize = '16px';
        
        clienteSearchTimeout = setTimeout(() => {
            buscarClientes(termo);
        }, 300);
    });
    
    inputElement.addEventListener('keydown', function(e) {
        if (!clienteSuggestionsVisible) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const termo = this.value.trim();
                if (termo.length >= 2) {
                    buscarClienteExato(termo);
                }
            }
            return;
        }
        
        const suggestions = suggestionsContainer.querySelectorAll('.cliente-suggestion-item');
        
        switch(e.key) {
            case 'ArrowDown':
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, suggestions.length - 1);
                updateClienteSelection(suggestions);
                break;
            case 'ArrowUp':
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                updateClienteSelection(suggestions);
                break;
            case 'Enter':
                e.preventDefault();
                if (selectedIndex >= 0 && suggestions[selectedIndex]) {
                    suggestions[selectedIndex].click();
                }
                break;
            case 'Escape':
                e.preventDefault();
                hideClienteSuggestions();
                break;
        }
    });
    
    inputElement.addEventListener('blur', function() {
        setTimeout(() => {
            hideClienteSuggestions();
        }, 200);
    });
    
    inputElement.addEventListener('focus', function() {
        const termo = this.value.trim();
        if (termo.length >= 2 && clientesSugeridos.length > 0) {
            mostrarClienteSuggestions(clientesSugeridos);
        }
    });

    /**
 * Inicializa o autocomplete para clientes no modo de edição
 */
function initClienteAutoCompleteEdit() {
    const inputElement = document.getElementById('clienteNomeEdit');
    const suggestionsContainer = document.getElementById('clienteEditSuggestions');
    
    if (!inputElement || !suggestionsContainer) {
        console.warn('Elementos do autocomplete de edição não encontrados');
        return;
    }

    let isVisible = false;
    let selectedIndex = -1;
    let clientesSugeridos = [];
    let searchTimeout = null;
    
    console.log('🔍 Inicializando autocomplete de cliente para edição');
    
    // Remove listeners anteriores se existirem
    inputElement.removeEventListener('input', handleEditClienteInput);
    inputElement.removeEventListener('keydown', handleEditClienteKeydown);
    inputElement.removeEventListener('focus', handleEditClienteFocus);
    inputElement.removeEventListener('blur', handleEditClienteBlur);
    
    // Event listeners
    inputElement.addEventListener('input', handleEditClienteInput);
    inputElement.addEventListener('keydown', handleEditClienteKeydown);
    inputElement.addEventListener('focus', handleEditClienteFocus);
    inputElement.addEventListener('blur', handleEditClienteBlur);
    
    function handleEditClienteInput(e) {
        clearTimeout(searchTimeout);
        const termo = e.target.value.trim();
        
        if (termo.length < 2) {
            hideEditSuggestions();
            return;
        }
        
        inputElement.style.backgroundImage = "url('data:image/svg+xml;charset=utf-8,%3Csvg xmlns%3D%22http%3A//www.w3.org/2000/svg%22 width%3D%2216%22 height%3D%2216%22 viewBox%3D%220 0 16 16%22%3E%3Cpath fill%3D%22%23999%22 d%3D%22M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0zM8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2z%22/%3E%3C/svg%3E')";
        inputElement.style.backgroundRepeat = 'no-repeat';
        inputElement.style.backgroundPosition = 'right 10px center';
        inputElement.style.backgroundSize = '16px';
        
        searchTimeout = setTimeout(() => {
            buscarClientesEdit(termo);
        }, 250);
    }
    
    function handleEditClienteKeydown(e) {
        if (!isVisible) return;
        
        const suggestions = suggestionsContainer.querySelectorAll('.cliente-suggestion-item');
        
        switch(e.key) {
            case 'ArrowDown':
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, suggestions.length - 1);
                updateEditSelection(suggestions);
                break;
            case 'ArrowUp':
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                updateEditSelection(suggestions);
                break;
            case 'Enter':
                e.preventDefault();
                if (selectedIndex >= 0 && suggestions[selectedIndex]) {
                    suggestions[selectedIndex].click();
                }
                break;
            case 'Escape':
                e.preventDefault();
                hideEditSuggestions();
                break;
        }
    }
    
    function handleEditClienteFocus(e) {
        const termo = e.target.value.trim();
        if (termo.length >= 2 && clientesSugeridos.length > 0) {
            mostrarEditSugestoes(clientesSugeridos);
        }
    }
    
    function handleEditClienteBlur(e) {
        setTimeout(() => {
            hideEditSuggestions();
        }, 200);
    }
    
    function updateEditSelection(suggestions) {
        suggestions.forEach((item, index) => {
            if (index === selectedIndex) {
                item.style.background = 'var(--secondary-color)';
                item.style.color = 'white';
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.style.background = 'white';
                item.style.color = 'inherit';
            }
        });
    }
    
    function buscarClientesEdit(termo) {
        console.log('🔍 Buscando clientes para edição, termo:', termo);
        
        fetch(`buscar_clientes_autocomplete.php?termo=${encodeURIComponent(termo)}&limit=8&t=${Date.now()}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('📦 Resposta da busca de clientes:', data);
                
                if (data.success && data.clientes && data.clientes.length > 0) {
                    clientesSugeridos = data.clientes;
                    mostrarEditSugestoes(data.clientes);
                } else {
                    hideEditSuggestions();
                }
            })
            .catch(error => {
                console.error('Erro ao buscar clientes:', error);
                hideEditSuggestions();
            })
            .finally(() => {
                inputElement.style.backgroundImage = '';
            });
    }
    
    function mostrarEditSugestoes(clientes) {
        if (!clientes || clientes.length === 0) {
            hideEditSuggestions();
            return;
        }
        
        selectedIndex = -1;
        
        let html = '';
        clientes.forEach((cliente, index) => {
            html += `
                <div class="cliente-suggestion-item" 
                     onclick="selecionarClienteEdit(${index})" 
                     data-index="${index}"
                     style="padding: 0.75rem; cursor: pointer; border-bottom: 1px solid var(--border-color); transition: all 0.2s ease;"
                     onmouseover="this.style.background='var(--light-gray)';" 
                     onmouseout="this.style.background='white';">
                    
                    <div style="font-weight: 600; color: var(--primary-color); margin-bottom: 0.25rem;">
                        ${cliente.nome_orgaos || 'Cliente sem nome'}
                    </div>
                    
                    <div style="font-size: 0.85rem; color: var(--medium-gray); display: flex; gap: 1rem; flex-wrap: wrap;">
                        <span><i class="fas fa-hashtag" style="width: 12px;"></i> UASG: ${cliente.uasg || 'N/A'}</span>
                        ${cliente.cnpj ? `<span><i class="fas fa-id-card" style="width: 12px;"></i> ${formatCNPJ(cliente.cnpj)}</span>` : ''}
                        ${cliente.telefone ? `<span><i class="fas fa-phone" style="width: 12px;"></i> ${formatPhone(cliente.telefone)}</span>` : ''}
                    </div>
                </div>
            `;
        });
        
        suggestionsContainer.innerHTML = html;
        suggestionsContainer.style.display = 'block';
        isVisible = true;
        
        console.log(`✅ Mostrando ${clientes.length} sugestões de clientes para edição`);
    }
    
    function hideEditSuggestions() {
        if (suggestionsContainer) {
            suggestionsContainer.style.display = 'none';
        }
        isVisible = false;
        selectedIndex = -1;
    }
    
    // Torna a função de seleção disponível globalmente
    window.selecionarClienteEdit = function(index) {
        const cliente = clientesSugeridos[index];
        if (!cliente) return;
        
        console.log('✅ Cliente selecionado para edição:', cliente);
        
        // Preenche o campo de nome
        inputElement.value = cliente.nome_orgaos || '';
        
        // Preenche UASG
        const uasgField = document.getElementById('clienteUasgEdit');
        if (uasgField) {
            uasgField.value = cliente.uasg || '';
        }
        
        // Preenche CNPJ
        const cnpjField = document.getElementById('clienteCnpjEdit');
        if (cnpjField && cliente.cnpj) {
            cnpjField.value = cliente.cnpj;
        }
        
        hideEditSuggestions();
        showToast(`Cliente selecionado: ${cliente.nome_orgaos}`, 'success', 2000);
    };
    
    console.log('✅ Autocomplete de cliente para edição inicializado');
}
    
    function updateClienteSelection(suggestions) {
        suggestions.forEach((item, index) => {
            if (index === selectedIndex) {
                item.style.background = 'var(--secondary-color)';
                item.style.color = 'white';
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.style.background = 'white';
                item.style.color = 'inherit';
            }
        });
    }
    
    console.log('✅ Autocomplete de clientes inicializado');
}

/**
 * Busca clientes
 */
function buscarClientes(termo) {
    console.log('🔍 Buscando clientes para termo:', termo);
    
    fetch(`buscar_clientes_autocomplete.php?termo=${encodeURIComponent(termo)}&limit=8&t=${Date.now()}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.clientes && data.clientes.length > 0) {
                clientesSugeridos = data.clientes;
                mostrarClienteSuggestions(data.clientes);
            } else {
                hideClienteSuggestions();
            }
        })
        .catch(error => {
            console.error('Erro ao buscar clientes:', error);
            hideClienteSuggestions();
        })
        .finally(() => {
            const inputElement = document.getElementById('clienteNomeEdit');
            if (inputElement) {
                inputElement.style.backgroundImage = '';
            }
        });
}

/**
 * Mostra sugestões de clientes
 */
function mostrarClienteSuggestions(clientes) {
    const suggestionsContainer = document.getElementById('clienteSuggestions');
    if (!suggestionsContainer || !clientes || clientes.length === 0) {
        hideClienteSuggestions();
        return;
    }
    
    selectedClienteIndex = -1;
    
    let html = '';
    clientes.forEach((cliente, index) => {
        html += `
            <div class="cliente-suggestion-item" 
                 onclick="selecionarCliente(${cliente.id})" 
                 data-index="${index}"
                 style="
                    padding: 0.75rem; 
                    cursor: pointer; 
                    border-bottom: 1px solid var(--border-color); 
                    transition: all 0.2s ease;
                 "
                 onmouseover="this.style.background='var(--light-gray)'; selectedClienteIndex=${index};" 
                 onmouseout="this.style.background='white';">
                
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.25rem;">
                    <div style="font-weight: 600; color: var(--primary-color); flex: 1;">
                        ${cliente.nome}
                    </div>
                </div>
                
                <div style="font-size: 0.85rem; color: var(--medium-gray); margin-bottom: 0.5rem;">
                    <span><i class="fas fa-hashtag" style="width: 12px;"></i> UASG: ${cliente.uasg || 'N/A'}</span>
                    ${cliente.cnpj_formatado ? ` | <i class="fas fa-id-card" style="width: 12px;"></i> ${cliente.cnpj_formatado}` : ''}
                </div>
                
                ${cliente.endereco ? `
                <div style="font-size: 0.8rem; color: var(--medium-gray); display: flex; align-items: center; gap: 0.25rem;">
                    <i class="fas fa-map-marker-alt" style="color: var(--info-color);"></i>
                    ${cliente.endereco_resumido || cliente.endereco}
                </div>
                ` : ''}
                
                ${cliente.telefone_formatado ? `
                <div style="font-size: 0.8rem; color: var(--medium-gray); margin-top: 0.25rem; display: flex; align-items: center; gap: 0.25rem;">
                    <i class="fas fa-phone" style="color: var(--success-color);"></i>
                    ${cliente.telefone_formatado}
                </div>
                ` : ''}
            </div>
        `;
    });
    
    suggestionsContainer.innerHTML = html;
    suggestionsContainer.style.display = 'block';
    clienteSuggestionsVisible = true;
    
    console.log(`✅ Mostrando ${clientes.length} sugestões de clientes`);
}

/**
 * Seleciona cliente
 */
function selecionarCliente(clienteId) {
    console.log('🔍 Selecionando cliente ID:', clienteId);
    
    const clienteExistente = clientesSugeridos.find(c => c.id == clienteId);
    
    if (clienteExistente) {
        preencherFormularioCliente(clienteExistente);
        hideClienteSuggestions();
        return;
    }
    
    fetch(`buscar_clientes_autocomplete.php?cliente_id=${clienteId}&t=${Date.now()}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.cliente) {
                preencherFormularioCliente(data.cliente);
                hideClienteSuggestions();
            } else {
                throw new Error(data.error || 'Cliente não encontrado');
            }
        })
        .catch(error => {
            console.error('❌ Erro ao buscar cliente:', error);
            showToast('Erro ao carregar cliente: ' + error.message, 'error');
            hideClienteSuggestions();
        });
}

/**
 * Preenche formulário com cliente selecionado
 */
function preencherFormularioCliente(cliente) {
    console.log('📝 Preenchendo formulário com cliente:', cliente);
    
    try {
        const clienteNomeField = document.getElementById('clienteNomeEdit');
        const clienteUasgField = document.getElementById('clienteUasgEdit');
        const clienteCnpjField = document.getElementById('clienteCnpjEdit');
        
        if (clienteNomeField) clienteNomeField.value = cliente.nome || '';
        if (clienteUasgField) clienteUasgField.value = cliente.uasg || '';
        if (clienteCnpjField) clienteCnpjField.value = cliente.cnpj_formatado || cliente.cnpj || '';
        
        // Mostra informações do cliente
        mostrarInformacoesClienteCompletas(cliente);
        
        showToast(`✅ Cliente "${cliente.nome}" selecionado`, 'success', 2000);
        
        console.log('✅ Formulário preenchido com sucesso');
        
    } catch (error) {
        console.error('❌ Erro ao preencher formulário:', error);
        showToast('Erro ao preencher formulário do cliente', 'error');
    }
}

/**
 * Mostra informações detalhadas do cliente
 */
function mostrarInformacoesClienteCompletas(cliente) {
    let infoContainer = document.getElementById('clienteInfoContainer');
    if (!infoContainer) return;

    let infoHtml = '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px 16px; padding: 1rem; background: rgba(0, 191, 174, 0.05); border-radius: var(--radius-sm); border: 1px solid rgba(0, 191, 174, 0.2);">';
    
    if (cliente.uasg) {
        infoHtml += `
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-hashtag" style="color: var(--secondary-color); width: 16px;"></i>
                <span style="color: var(--medium-gray);">UASG:</span>
                <strong style="color: var(--primary-color);">${cliente.uasg}</strong>
            </div>
        `;
    }
    
    if (cliente.cnpj_formatado) {
        infoHtml += `
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-id-card" style="color: var(--info-color); width: 16px;"></i>
                <span style="color: var(--medium-gray);">CNPJ:</span>
                <strong style="color: var(--info-color);">${cliente.cnpj_formatado}</strong>
            </div>
        `;
    }
    
    if (cliente.telefone) {
        infoHtml += `
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-phone" style="color: var(--success-color); width: 16px;"></i>
                <span style="color: var(--medium-gray);">Telefone:</span>
                <strong style="color: var(--success-color);">${cliente.telefone}</strong>
            </div>
        `;
    }
    
    if (cliente.email) {
        infoHtml += `
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-envelope" style="color: var(--warning-color); width: 16px;"></i>
                <span style="color: var(--medium-gray);">E-mail:</span>
                <strong style="color: var(--warning-color);">${cliente.email}</strong>
            </div>
        `;
    }
    
    infoHtml += '</div>';
    
    if (cliente.endereco) {
        infoHtml += `
            <div style="margin-top: 0.75rem; padding: 0.75rem; background: var(--light-gray); border-radius: var(--radius-sm); border-left: 3px solid var(--info-color);">
                <div style="display: flex; align-items: flex-start; gap: 6px;">
                    <i class="fas fa-map-marker-alt" style="color: var(--info-color); margin-top: 2px;"></i>
                    <div>
                        <span style="color: var(--medium-gray); font-size: 0.85rem; font-weight: 600;">Endereço:</span>
                        <div style="color: var(--dark-gray); margin-top: 0.25rem;">${cliente.endereco}</div>
                    </div>
                </div>
            </div>
        `;
    }
    
    infoContainer.innerHTML = infoHtml;
    infoContainer.style.display = 'block';
    
    // Anima a entrada
    infoContainer.style.opacity = '0';
    infoContainer.style.transform = 'translateY(-10px)';
    
    setTimeout(() => {
        infoContainer.style.transition = 'all 0.3s ease';
        infoContainer.style.opacity = '1';
        infoContainer.style.transform = 'translateY(0)';
    }, 50);
}

/**
 * Esconde sugestões de clientes
 */
function hideClienteSuggestions() {
    const suggestionsContainer = document.getElementById('clienteSuggestions');
    if (suggestionsContainer) {
        suggestionsContainer.style.display = 'none';
    }
    clienteSuggestionsVisible = false;
    selectedClienteIndex = -1;
}

/**
 * Limpa cliente selecionado
 */
function limparClienteSelecionado() {
    const infoContainer = document.getElementById('clienteInfoContainer');
    if (infoContainer) {
        infoContainer.style.display = 'none';
        infoContainer.innerHTML = '';
    }
}

// Expor função globalmente
window.selecionarCliente = selecionarCliente;
</script>
</body>
</html>

<?php
// ===========================================
// FINALIZAÇÕES E LOGS DO SISTEMA
// ===========================================

// Log da página carregada
if (function_exists('logUserAction')) {
    logUserAction('PAGE_VIEW', 'consulta_empenhos', null, [
        'total_empenhos' => count($empenhos),
        'filtros' => [
            'search' => $searchTerm,
            'classificacao' => $classificacaoFilter
        ],
        'pagina' => $paginaAtual
    ]);
}

// Cleanup de sessão se necessário
if (isset($_SESSION['temp_data'])) {
    unset($_SESSION['temp_data']);
}
if (ob_get_level()) {
    ob_end_flush();
}

// Não incluir ?> no final para evitar whitespace issues