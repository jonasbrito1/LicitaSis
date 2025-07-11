<?php
// ===========================================
// ARQUIVO: buscar_produtos.php
// Busca produtos para integração com o sistema de empenhos
// Compatível com buscar_produtos_autocomplete.php
// ===========================================

session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não logado']);
    exit();
}

// Conexão com o banco de dados
require_once('db.php');

// Headers para resposta JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

// Função para registrar log de acesso
function logAccess($action, $details = '') {
    if (function_exists('logUserAction')) {
        logUserAction($action, $details);
    }
    error_log("buscar_produtos.php - {$action}: {$details}");
}

try {
    // Parâmetros de entrada
    $termo = isset($_GET['termo']) ? trim($_GET['termo']) : '';
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    $todos = isset($_GET['todos']) && $_GET['todos'] == '1';
    $categoria = isset($_GET['categoria']) ? trim($_GET['categoria']) : '';
    $apenas_disponiveis = isset($_GET['apenas_disponiveis']) && $_GET['apenas_disponiveis'] == '1';
    
    // Log da consulta
    logAccess('SEARCH_REQUEST', "Termo: '{$termo}', Categoria: '{$categoria}', Limit: {$limit}");
    
    $response = ['success' => false, 'produtos' => []];
    
    // Verifica se deve fazer a busca
    if ($todos || strlen($termo) >= 2 || !empty($categoria)) {
        
        // Query base
        $sql = "SELECT 
                    p.id,
                    p.codigo,
                    p.nome,
                    COALESCE(p.unidade, p.und, 'UN') as unidade,
                    p.fornecedor,
                    p.categoria,
                    p.observacao,
                    p.preco_unitario,
                    p.preco_venda,
                    p.custo_total,
                    p.margem_lucro,
                    p.icms,
                    p.irpj,
                    p.cofins,
                    p.csll,
                    p.pis_pasep,
                    p.ipi,
                    p.total_impostos,
                    p.estoque_atual,
                    p.estoque_minimo,
                    p.controla_estoque,
                    p.created_at,
                    p.updated_at,
                    -- Preço sugerido (prioriza preço de venda)
                    CASE 
                        WHEN p.preco_venda > 0 THEN p.preco_venda
                        ELSE p.preco_unitario
                    END as preco_sugerido,
                    -- Verifica se está com estoque baixo
                    CASE 
                        WHEN p.controla_estoque = 1 AND p.estoque_atual <= p.estoque_minimo THEN 1
                        ELSE 0
                    END as estoque_baixo,
                    -- Verifica se está disponível
                    CASE 
                        WHEN p.controla_estoque = 0 OR p.estoque_atual > 0 THEN 1
                        ELSE 0
                    END as disponivel,
                    -- Score de relevância para ordenação
                    CASE 
                        WHEN p.nome LIKE CONCAT(:termo_exato, '%') THEN 100
                        WHEN p.codigo = :termo_exato THEN 95
                        WHEN p.nome LIKE CONCAT('%', :termo_exato, '%') THEN 80
                        WHEN p.codigo LIKE CONCAT('%', :termo_exato, '%') THEN 75
                        WHEN p.categoria LIKE CONCAT('%', :termo_exato, '%') THEN 60
                        WHEN p.observacao LIKE CONCAT('%', :termo_exato, '%') THEN 50
                        ELSE 10
                    END as relevancia
                FROM produtos p 
                WHERE 1=1";
        
        $params = [':termo_exato' => $termo];
        
        // Filtros condicionais
        if (!$todos && !empty($termo)) {
            $sql .= " AND (
                p.nome LIKE :termo OR 
                p.codigo LIKE :termo OR 
                p.categoria LIKE :termo OR
                p.fornecedor LIKE :termo OR
                p.observacao LIKE :termo
            )";
            $params[':termo'] = "%{$termo}%";
        }
        
        if (!empty($categoria)) {
            $sql .= " AND p.categoria = :categoria";
            $params[':categoria'] = $categoria;
        }
        
        if ($apenas_disponiveis) {
            $sql .= " AND (p.controla_estoque = 0 OR p.estoque_atual > 0)";
        }
        
        // Ordenação otimizada
        $sql .= " ORDER BY 
                    disponivel DESC,
                    relevancia DESC,
                    p.nome ASC";
        
        // Limit para performance
        if (!$todos) {
            $sql .= " LIMIT :limit";
            $params[':limit'] = $limit;
        }
        
        // Executa a query
        $stmt = $pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            if ($key === ':limit') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
        }
        
        $stmt->execute();
        $produtos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Processa produtos para o frontend
        $produtos = [];
        
        foreach ($produtos_raw as $produto) {
            
            // Valores formatados
            $preco_unitario = floatval($produto['preco_unitario']);
            $preco_venda = floatval($produto['preco_venda']);
            $preco_sugerido = floatval($produto['preco_sugerido']);
            $custo_total = floatval($produto['custo_total']);
            $estoque_atual = floatval($produto['estoque_atual']);
            $estoque_minimo = intval($produto['estoque_minimo']);
            
            // Calcula informações adicionais
            $margem_real = 0;
            if ($custo_total > 0 && $preco_sugerido > 0) {
                $margem_real = (($preco_sugerido - $custo_total) / $preco_sugerido) * 100;
            }
            
            // Status e alertas
            $alertas = [];
            $classe_status = 'disponivel';
            
            if ($produto['controla_estoque'] == 1) {
                if ($produto['disponivel'] == 0) {
                    $alertas[] = 'Sem estoque';
                    $classe_status = 'sem_estoque';
                } elseif ($produto['estoque_baixo'] == 1) {
                    $alertas[] = 'Estoque baixo';
                    $classe_status = 'estoque_baixo';
                }
            }
            
            if ($preco_sugerido <= 0) {
                $alertas[] = 'Sem preço definido';
            }
            
            // Monta objeto do produto
            $produto_processado = [
                // Identificação
                'id' => intval($produto['id']),
                'codigo' => $produto['codigo'] ?: '',
                'nome' => $produto['nome'] ?: 'Produto sem nome',
                'categoria' => $produto['categoria'] ?: '',
                'fornecedor' => $produto['fornecedor'] ?: '',
                'observacao' => $produto['observacao'] ?: '',
                'unidade' => $produto['unidade'],
                
                // Preços
                'preco_unitario' => $preco_unitario,
                'preco_venda' => $preco_venda,
                'preco_sugerido' => $preco_sugerido,
                'custo_total' => $custo_total,
                'margem_lucro' => floatval($produto['margem_lucro']),
                'margem_real' => $margem_real,
                
                // Impostos
                'icms' => floatval($produto['icms']),
                'irpj' => floatval($produto['irpj']),
                'cofins' => floatval($produto['cofins']),
                'csll' => floatval($produto['csll']),
                'pis_pasep' => floatval($produto['pis_pasep']),
                'ipi' => floatval($produto['ipi']),
                'total_impostos' => floatval($produto['total_impostos']),
                
                // Estoque
                'estoque_atual' => $estoque_atual,
                'estoque_minimo' => $estoque_minimo,
                'controla_estoque' => boolval($produto['controla_estoque']),
                'estoque_baixo' => boolval($produto['estoque_baixo']),
                'disponivel' => boolval($produto['disponivel']),
                
                // Status
                'alertas' => $alertas,
                'tem_alertas' => !empty($alertas),
                'classe_status' => $classe_status,
                'relevancia' => intval($produto['relevancia']),
                
                // Formatação para exibição
                'preco_formatado' => 'R$ ' . number_format($preco_sugerido, 2, ',', '.'),
                'custo_formatado' => 'R$ ' . number_format($custo_total, 2, ',', '.'),
                'margem_formatada' => number_format($margem_real, 1, ',', '.') . '%',
                'estoque_formatado' => number_format($estoque_atual, 2, ',', '.') . ' ' . $produto['unidade'],
                
                // Para autocomplete
                'label' => $produto['nome'] . 
                    ($produto['codigo'] ? " [{$produto['codigo']}]" : '') .
                    " - R$ " . number_format($preco_sugerido, 2, ',', '.'),
                
                'value' => $produto['nome'],
                
                // Dados para formulário
                'form_data' => [
                    'produto_id' => intval($produto['id']),
                    'nome' => $produto['nome'],
                    'codigo' => $produto['codigo'],
                    'valor_unitario' => $preco_sugerido,
                    'descricao_produto' => $produto['observacao'] ?: $produto['nome'],
                    'unidade' => $produto['unidade']
                ],
                
                // Metadados
                'created_at' => $produto['created_at'],
                'updated_at' => $produto['updated_at']
            ];
            
            $produtos[] = $produto_processado;
        }
        
        $response['success'] = true;
        $response['produtos'] = $produtos;
        $response['total'] = count($produtos);
        $response['termo_busca'] = $termo;
        $response['categoria_filtro'] = $categoria;
        
        // Estatísticas se busca completa
        if ($todos || count($produtos) > 5) {
            $disponiveis = array_filter($produtos, function($p) { return $p['disponivel']; });
            $com_alertas = array_filter($produtos, function($p) { return $p['tem_alertas']; });
            
            $response['estatisticas'] = [
                'total_produtos' => count($produtos),
                'produtos_disponiveis' => count($disponiveis),
                'produtos_com_alertas' => count($com_alertas),
                'produtos_sem_estoque' => count($produtos) - count($disponiveis)
            ];
        }
        
        logAccess('SEARCH_SUCCESS', "Retornados: " . count($produtos) . " produtos");
        
    } else {
        $response['message'] = 'Termo de busca muito curto ou parâmetros insuficientes';
        $response['min_chars'] = 2;
        logAccess('SEARCH_INSUFFICIENT_PARAMS', "Termo: '{$termo}', Categoria: '{$categoria}'");
    }
    
} catch (PDOException $e) {
    logAccess('ERROR_DATABASE', "Erro de banco: " . $e->getMessage());
    error_log("Erro de banco em buscar_produtos.php: " . $e->getMessage());
    
    http_response_code(500);
    $response = [
        'success' => false,
        'error' => 'Erro de banco de dados',
        'message' => 'Erro ao consultar produtos'
    ];
    
} catch (Exception $e) {
    logAccess('ERROR_GENERAL', "Erro geral: " . $e->getMessage());
    error_log("Erro geral em buscar_produtos.php: " . $e->getMessage());
    
    http_response_code(500);
    $response = [
        'success' => false,
        'error' => 'Erro interno do servidor',
        'message' => $e->getMessage()
    ];
}

// Retorna resposta JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>