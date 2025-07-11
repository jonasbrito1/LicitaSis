<?php
// ===========================================
// ARQUIVO: search_produtos.php
// Sistema de busca de produtos para autocomplete
// ===========================================

require_once('db.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (isset($_GET['query'])) {
    $query = trim($_GET['query']);

    if (strlen($query) >= 2) {
        try {
            // Query otimizada para buscar produtos baseada na estrutura real da tabela
            $sql = "SELECT 
                        p.id, 
                        p.codigo,
                        p.nome, 
                        p.unidade,
                        p.categoria,
                        p.fornecedor,
                        p.observacao,
                        p.preco_unitario,
                        p.preco_venda,
                        p.custo_total,
                        p.estoque_atual,
                        p.estoque_minimo,
                        p.controla_estoque,
                        p.margem_lucro,
                        p.total_impostos,
                        p.icms,
                        p.irpj,
                        p.cofins,
                        p.csll,
                        p.pis_pasep,
                        p.ipi
                    FROM produtos p 
                    WHERE 
                        p.nome LIKE :query 
                        OR p.codigo LIKE :query 
                        OR p.observacao LIKE :query
                        OR p.categoria LIKE :query
                    ORDER BY 
                        CASE 
                            WHEN p.codigo = :exact_query THEN 1
                            WHEN p.nome LIKE :starts_query THEN 2
                            WHEN p.codigo LIKE :starts_query THEN 3
                            ELSE 4
                        END,
                        p.nome ASC
                    LIMIT 15";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':query', '%' . $query . '%', PDO::PARAM_STR);
            $stmt->bindValue(':exact_query', $query, PDO::PARAM_STR);
            $stmt->bindValue(':starts_query', $query . '%', PDO::PARAM_STR);
            $stmt->execute();

            $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formata os dados para o frontend
            $results = array_map(function($produto) {
                // Determina o preço a ser usado (prioriza preco_venda depois preco_unitario)
                $preco_sugerido = 0;
                if (!empty($produto['preco_venda']) && $produto['preco_venda'] > 0) {
                    $preco_sugerido = floatval($produto['preco_venda']);
                } elseif (!empty($produto['preco_unitario']) && $produto['preco_unitario'] > 0) {
                    $preco_sugerido = floatval($produto['preco_unitario']);
                }

                // Calcula margem se há custo
                $margem_calculada = 0;
                if (!empty($produto['custo_total']) && $produto['custo_total'] > 0 && $preco_sugerido > 0) {
                    $margem_calculada = (($preco_sugerido - $produto['custo_total']) / $preco_sugerido) * 100;
                }

                // Status do estoque
                $status_estoque = 'ok';
                if (!empty($produto['controla_estoque']) && $produto['controla_estoque'] == 1) {
                    $estoque_atual = floatval($produto['estoque_atual'] ?: 0);
                    $estoque_minimo = intval($produto['estoque_minimo'] ?: 0);
                    
                    if ($estoque_atual <= 0) {
                        $status_estoque = 'sem_estoque';
                    } elseif ($estoque_atual <= $estoque_minimo) {
                        $status_estoque = 'estoque_baixo';
                    }
                }

                return [
                    'id' => intval($produto['id']),
                    'codigo' => $produto['codigo'] ?: '',
                    'nome' => $produto['nome'] ?: '',
                    'unidade' => $produto['unidade'] ?: 'UN',
                    'categoria' => $produto['categoria'] ?: '',
                    'fornecedor' => $produto['fornecedor'] ?: '',
                    'observacao' => $produto['observacao'] ?: '',
                    'preco_unitario' => $preco_sugerido,
                    'preco_venda' => floatval($produto['preco_venda'] ?: 0),
                    'custo_total' => floatval($produto['custo_total'] ?: 0),
                    'margem_lucro' => floatval($produto['margem_lucro'] ?: 0),
                    'margem_calculada' => $margem_calculada,
                    'estoque_atual' => floatval($produto['estoque_atual'] ?: 0),
                    'estoque_minimo' => intval($produto['estoque_minimo'] ?: 0),
                    'controla_estoque' => boolval($produto['controla_estoque']),
                    'status_estoque' => $status_estoque,
                    'total_impostos' => floatval($produto['total_impostos'] ?: 0),
                    'impostos' => [
                        'icms' => floatval($produto['icms'] ?: 0),
                        'irpj' => floatval($produto['irpj'] ?: 0),
                        'cofins' => floatval($produto['cofins'] ?: 0),
                        'csll' => floatval($produto['csll'] ?: 0),
                        'pis_pasep' => floatval($produto['pis_pasep'] ?: 0),
                        'ipi' => floatval($produto['ipi'] ?: 0)
                    ]
                ];
            }, $produtos);

            echo json_encode([
                'success' => true,
                'products' => $results,
                'total' => count($results),
                'query' => $query
            ]);

        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro na busca de produtos: ' . $e->getMessage(),
                'query' => $query
            ]);
        }
    } else {
        echo json_encode([
            'success' => true,
            'products' => [],
            'total' => 0,
            'message' => 'Query muito curta (mínimo 2 caracteres)'
        ]);
    }
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Parâmetro query não fornecido'
    ]);
}
?>