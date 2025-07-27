<?php
// ===========================================
// CÓDIGO COMPLETO PARA CONSULTA DE PRODUTOS
// Versão com categorias funcionando corretamente
// ===========================================

session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Conexão com o banco de dados
require_once('db.php');
require_once('permissions.php');

// Inicializa o gerenciador de permissões
$permissionManager = initPermissions($pdo);

// Verifica permissão para visualizar produtos
$permissionManager->requirePermission('produtos', 'view');

// SISTEMA DE PERMISSÕES SIMPLIFICADO
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';
$canDelete = $isAdmin; // Apenas administradores podem excluir
$canEdit = $isAdmin;   // Apenas administradores podem editar
$canCreate = $isAdmin; // Apenas administradores podem criar

// Função de auditoria simplificada
if (!function_exists('logUserAction')) {
    function logUserAction($action, $table, $recordId = null, $data = null) {
        error_log("AUDIT: User {$_SESSION['user']['id']} performed $action on $table" . ($recordId ? " ID $recordId" : ""));
    }
}

// Verifica se a coluna categoria_id existe na tabela produtos
try {
    $checkColumn = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'categoria_id'");
    $hasCategoria_id = $checkColumn->rowCount() > 0;
    
    if (!$hasCategoria_id) {
        // Se não existe categoria_id, verifica se existe categoria
        $checkOldColumn = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'categoria'");
        $hasCategoria = $checkOldColumn->rowCount() > 0;
        
        if ($hasCategoria) {
            error_log("AVISO: Tabela produtos usa 'categoria' ao invés de 'categoria_id'. Considere migrar para usar IDs.");
        }
    }
} catch (PDOException $e) {
    error_log("Erro ao verificar estrutura da tabela produtos: " . $e->getMessage());
    $hasCategoria_id = false;
}

// Função para determinar qual campo de categoria usar
function getCategoriaField($pdo) {
    static $categoriaField = null;
    
    if ($categoriaField === null) {
        try {
            $checkColumn = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'categoria_id'");
            if ($checkColumn->rowCount() > 0) {
                $categoriaField = 'categoria_id';
            } else {
                $categoriaField = 'categoria';
            }
        } catch (PDOException $e) {
            $categoriaField = 'categoria'; // fallback
        }
    }
    
    return $categoriaField;
}

// Função CORRIGIDA para obter configuração de JOIN para categoria
function getCategoriaJoinConfig($pdo) {
    $categoriaField = getCategoriaField($pdo);
    
    if ($categoriaField === 'categoria_id') {
        return [
            'join' => 'LEFT JOIN categorias c ON p.categoria_id = c.id',
            'select' => 'c.id as categoria_id, c.nome as categoria_nome',
            'field' => 'categoria_id'
        ];
    } else {
        return [
            'join' => 'LEFT JOIN categorias c ON p.categoria = c.nome',
            'select' => 'p.categoria as categoria_nome, c.id as categoria_id',
            'field' => 'categoria'
        ];
    }
}

// Inicializa variáveis
// PARÂMETROS DE FILTRO E ORDENAÇÃO
$filtro_nome = $_GET['filtro_nome'] ?? '';
$filtro_codigo = $_GET['filtro_codigo'] ?? '';
$filtro_categoria = $_GET['filtro_categoria'] ?? '';
$filtro_fornecedor = $_GET['filtro_fornecedor'] ?? '';
$filtro_estoque = $_GET['filtro_estoque'] ?? '';
$filtro_preco_min = $_GET['filtro_preco_min'] ?? '';
$filtro_preco_max = $_GET['filtro_preco_max'] ?? '';

// PARÂMETROS DE ORDENAÇÃO
$ordem_campo = $_GET['ordem_campo'] ?? 'nome';
$ordem_direcao = $_GET['ordem_direcao'] ?? 'ASC';

// VALIDAÇÃO DOS PARÂMETROS DE ORDENAÇÃO
$campos_ordenacao_validos = [
    'nome' => 'p.nome',
    'codigo' => 'p.codigo', 
    'preco_unitario' => 'p.preco_unitario',
    'preco_venda' => 'p.preco_venda',
    'estoque_atual' => 'p.estoque_atual',
    'created_at' => 'p.created_at',
    'categoria' => 'c.nome',
    'fornecedor' => 'f.nome'
];

$campo_ordenacao = $campos_ordenacao_validos[$ordem_campo] ?? 'p.nome';
$direcao_ordenacao = strtoupper($ordem_direcao) === 'DESC' ? 'DESC' : 'ASC';

// Inicializa variáveis
$error = "";
$success = "";
$produtos = [];
$searchTerm = $_GET['search'] ?? '';
$totalProdutos = 0;
$produtosPorPagina = 20;
$paginaAtual = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($paginaAtual - 1) * $produtosPorPagina;

// ENDPOINT PARA DEBUG DA ESTRUTURA (adicionar no início do arquivo)
if (isset($_GET['debug_estrutura'])) {
    try {
        $stmt = $pdo->query("DESCRIBE produtos");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Estrutura da tabela produtos:</h3>";
        foreach ($columns as $column) {
            echo "Campo: {$column['Field']} - Tipo: {$column['Type']} - Null: {$column['Null']}<br>";
        }
        
        // Verifica dados de exemplo
        $stmt = $pdo->query("SELECT id, codigo, nome, categoria, categoria_id FROM produtos LIMIT 5");
        $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Exemplos de dados:</h3>";
        foreach ($samples as $sample) {
            echo "ID: {$sample['id']}, Código: {$sample['codigo']}, Nome: {$sample['nome']}, ";
            echo "Categoria: " . ($sample['categoria'] ?? 'NULL') . ", ";
            echo "Categoria_ID: " . ($sample['categoria_id'] ?? 'NULL') . "<br>";
        }
        
    } catch (PDOException $e) {
        echo "Erro: " . $e->getMessage();
    }
    exit();
}

// NOVA FUNCIONALIDADE: Busca de categorias AJAX
if (isset($_GET['get_categorias_ajax'])) {
    header('Content-Type: application/json');
    
    try {
        $sql = "SELECT id, nome 
                FROM categorias 
                WHERE status = 'ativo' 
                ORDER BY nome ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($categorias);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar categorias: ' . $e->getMessage()]);
        exit();
    }
}

// NOVA FUNCIONALIDADE: Busca de fornecedores AJAX
if (isset($_GET['search_fornecedores_ajax'])) {
    header('Content-Type: application/json');
    
    try {
        $query = isset($_GET['query']) ? trim($_GET['query']) : '';
        
        if (strlen($query) < 1) {
            // Se não há query, retorna os 10 primeiros fornecedores
            $sql = "SELECT id, codigo, nome, cnpj, telefone, email 
                    FROM fornecedores 
                    ORDER BY nome ASC 
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
        } else {
            // Busca fornecedores com o termo
            $sql = "SELECT id, codigo, nome, cnpj, telefone, email 
                    FROM fornecedores 
                    WHERE nome LIKE :query 
                    OR codigo LIKE :query 
                    OR cnpj LIKE :query
                    ORDER BY nome ASC 
                    LIMIT 15";
            $stmt = $pdo->prepare($sql);
            $searchParam = "%$query%";
            $stmt->bindParam(':query', $searchParam);
        }
        
        $stmt->execute();
        $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($fornecedores);
        exit();
        
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar fornecedores: ' . $e->getMessage()]);
        exit();
    }
}

// FUNÇÃO PARA VERIFICAR SE UMA TABELA EXISTE
function tableExists($pdo, $tableName) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Processa exclusão de produto - VERSÃO CORRIGIDA
if (isset($_GET['delete_product_id']) && $canDelete) {
    $id = (int)$_GET['delete_product_id'];
    try {
        // LISTA DE POSSÍVEIS NOMES DE TABELAS RELACIONADAS
        $possibleTables = [
            'vendas' => ['vendas_items', 'venda_produtos', 'itens_venda', 'produto_venda'],
            'compras' => ['compras_items', 'produto_compra', 'itens_compra', 'compra_produtos'],
            'empenhos' => ['empenho_produtos', 'empenhos_items', 'produto_empenho']
        ];

        // VERIFICA DEPENDÊNCIAS EM TABELAS EXISTENTES
        $dependencies = [];
        $totalDependencies = 0;

        // Verifica vendas
        foreach ($possibleTables['vendas'] as $table) {
            if (tableExists($pdo, $table)) {
                try {
                    $checkSql = "SELECT COUNT(*) as count FROM `$table` WHERE produto_id = :id";
                    $checkStmt = $pdo->prepare($checkSql);
                    $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
                    $checkStmt->execute();
                    $count = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    if ($count > 0) {
                        $dependencies['vendas'] = $count;
                        $totalDependencies += $count;
                        break;
                    }
                } catch (PDOException $e) {
                    continue;
                }
            }
        }

        // Verifica compras
        foreach ($possibleTables['compras'] as $table) {
            if (tableExists($pdo, $table)) {
                try {
                    $checkSql = "SELECT COUNT(*) as count FROM `$table` WHERE produto_id = :id";
                    $checkStmt = $pdo->prepare($checkSql);
                    $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
                    $checkStmt->execute();
                    $count = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    if ($count > 0) {
                        $dependencies['compras'] = $count;
                        $totalDependencies += $count;
                        break;
                    }
                } catch (PDOException $e) {
                    continue;
                }
            }
        }

        // Verifica empenhos
        foreach ($possibleTables['empenhos'] as $table) {
            if (tableExists($pdo, $table)) {
                try {
                    $checkSql = "SELECT COUNT(*) as count FROM `$table` WHERE produto_id = :id";
                    $checkStmt = $pdo->prepare($checkSql);
                    $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
                    $checkStmt->execute();
                    $count = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    if ($count > 0) {
                        $dependencies['empenhos'] = $count;
                        $totalDependencies += $count;
                        break;
                    }
                } catch (PDOException $e) {
                    continue;
                }
            }
        }

        // SE HÁ DEPENDÊNCIAS, IMPEDE A EXCLUSÃO
        if ($totalDependencies > 0) {
            $errorMessages = [];
            if (isset($dependencies['vendas'])) {
                $errorMessages[] = $dependencies['vendas'] . " venda(s)";
            }
            if (isset($dependencies['compras'])) {
                $errorMessages[] = $dependencies['compras'] . " compra(s)";
            }
            if (isset($dependencies['empenhos'])) {
                $errorMessages[] = $dependencies['empenhos'] . " empenho(s)";
            }
            
            $error = "Não é possível excluir este produto pois existem " . implode(", ", $errorMessages) . " associadas.";
        } else {
            // SE NÃO HÁ DEPENDÊNCIAS, PODE EXCLUIR
            
            // Busca dados do produto para auditoria
            $produtoStmt = $pdo->prepare("SELECT * FROM produtos WHERE id = :id");
            $produtoStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $produtoStmt->execute();
            $produtoData = $produtoStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($produtoData) {
                // Remove a imagem se existir
                if (!empty($produtoData['imagem']) && file_exists($produtoData['imagem'])) {
                    unlink($produtoData['imagem']);
                }
                
                // Exclui o produto
                $sql = "DELETE FROM produtos WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
                
                // Registra auditoria
                logUserAction('DELETE', 'produtos', $id, $produtoData);
                
                $success = "Produto excluído com sucesso!";
                header("Location: consulta_produto.php?success=" . urlencode($success));
                exit();
            } else {
                $error = "Produto não encontrado.";
            }
        }
    } catch (PDOException $e) {
        $error = "Erro ao excluir o produto: " . $e->getMessage();
        error_log("Erro ao excluir produto ID $id: " . $e->getMessage());
    }
} elseif (isset($_GET['delete_product_id']) && !$canDelete) {
    $error = "Você não tem permissão para excluir produtos.";
}

// Processa atualização de produto via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_product'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    $response = ['success' => false];
    
    try {
        if (!$canEdit) {
            throw new Exception("Sem permissão para editar produtos.");
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            throw new Exception("ID do produto inválido.");
        }

        $pdo->beginTransaction();
        
        // Busca dados antigos
        $stmtOld = $pdo->prepare("SELECT * FROM produtos WHERE id = ?");
        $stmtOld->execute([$id]);
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        if (!$oldData) {
            throw new Exception("Produto não encontrado.");
        }

        // Coleta e sanitiza os dados
        $dados = [
            'nome' => trim(filter_input(INPUT_POST, 'nome', FILTER_SANITIZE_STRING)),
            'und' => trim(filter_input(INPUT_POST, 'und', FILTER_SANITIZE_STRING)),
            'categoria' => filter_input(INPUT_POST, 'categoria', FILTER_VALIDATE_INT),
            'fornecedor' => filter_input(INPUT_POST, 'fornecedor', FILTER_VALIDATE_INT),
            'observacao' => trim(filter_input(INPUT_POST, 'observacao', FILTER_SANITIZE_STRING)),
            'preco_unitario' => str_replace(',', '.', filter_input(INPUT_POST, 'preco_unitario')),
            'estoque_minimo' => filter_input(INPUT_POST, 'estoque_minimo', FILTER_VALIDATE_INT),
            'icms' => str_replace(',', '.', filter_input(INPUT_POST, 'icms')),
            'irpj' => str_replace(',', '.', filter_input(INPUT_POST, 'irpj')),
            'cofins' => str_replace(',', '.', filter_input(INPUT_POST, 'cofins')),
            'csll' => str_replace(',', '.', filter_input(INPUT_POST, 'csll')),
            'pis_pasep' => str_replace(',', '.', filter_input(INPUT_POST, 'pis_pasep')),
            'ipi' => str_replace(',', '.', filter_input(INPUT_POST, 'ipi')),
            'margem_lucro' => str_replace(',', '.', filter_input(INPUT_POST, 'margem_lucro')),
            'total_impostos' => str_replace(',', '.', filter_input(INPUT_POST, 'total_impostos_valor')),
            'custo_total' => str_replace(',', '.', filter_input(INPUT_POST, 'custo_total_valor')),
            'preco_venda' => str_replace(',', '.', filter_input(INPUT_POST, 'preco_venda_valor'))
        ];

        // Validações básicas
        if (empty($dados['nome']) || empty($dados['und'])) {
            throw new Exception("Nome e Unidade são campos obrigatórios.");
        }

        // Obtém configuração da categoria
        $categoriaConfig = getCategoriaJoinConfig($pdo);
        $categoriaField = $categoriaConfig['field'];

        // Atualiza o produto
        $sql = "UPDATE produtos SET 
                nome = :nome,
                und = :und,
                $categoriaField = :categoria,
                fornecedor = :fornecedor,
                observacao = :observacao,
                preco_unitario = :preco_unitario,
                estoque_minimo = :estoque_minimo,
                icms = :icms,
                irpj = :irpj,
                cofins = :cofins,
                csll = :csll,
                pis_pasep = :pis_pasep,
                ipi = :ipi,
                margem_lucro = :margem_lucro,
                total_impostos = :total_impostos,
                custo_total = :custo_total,
                preco_venda = :preco_venda,
                updated_at = NOW()
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $dados['id'] = $id;
        
        if (!$stmt->execute($dados)) {
            throw new Exception("Erro ao atualizar o produto no banco de dados.");
        }

        // Processa upload de imagem se houver
        if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/produtos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileInfo = pathinfo($_FILES['imagem']['name']);
            $extension = strtolower($fileInfo['extension']);
            
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                throw new Exception("Tipo de arquivo não permitido.");
            }

            $newFileName = uniqid('prod_') . '.' . $extension;
            $uploadFile = $uploadDir . $newFileName;

            if (!move_uploaded_file($_FILES['imagem']['tmp_name'], $uploadFile)) {
                throw new Exception("Erro ao fazer upload da imagem.");
            }

            $stmtImg = $pdo->prepare("UPDATE produtos SET imagem = ? WHERE id = ?");
            $stmtImg->execute([$uploadFile, $id]);
            
            if (!empty($oldData['imagem']) && file_exists($oldData['imagem'])) {
                unlink($oldData['imagem']);
            }
        }

        logUserAction('UPDATE', 'produtos', $id, [
            'old' => $oldData,
            'new' => $dados
        ]);

        $pdo->commit();
        $response['success'] = true;
        $response['message'] = "Produto atualizado com sucesso!";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $response['error'] = $e->getMessage();
    }

    die(json_encode($response));
}

// Processa busca e paginação - VERSÃO CORRIGIDA
// Processa busca, filtros e paginação - VERSÃO COM FILTROS
try {
    // Obtém configuração da categoria
    $categoriaConfig = getCategoriaJoinConfig($pdo);
    $categoriaJoin = $categoriaConfig['join'];
    $categoriaSelect = $categoriaConfig['select'];

    // CONSTRUÇÃO DA QUERY COM FILTROS
    $where_conditions = [];
    $params = [];

    // Filtro por busca geral
    if (!empty($searchTerm)) {
        $where_conditions[] = "(p.nome LIKE :searchTerm OR p.codigo LIKE :searchTerm OR COALESCE(c.nome, p.categoria, '') LIKE :searchTerm OR f.nome LIKE :searchTerm)";
        $params[':searchTerm'] = "%{$searchTerm}%";
    }

    // Filtros específicos
    if (!empty($filtro_nome)) {
        $where_conditions[] = "p.nome LIKE :filtro_nome";
        $params[':filtro_nome'] = "%{$filtro_nome}%";
    }

    if (!empty($filtro_codigo)) {
        $where_conditions[] = "p.codigo LIKE :filtro_codigo";
        $params[':filtro_codigo'] = "%{$filtro_codigo}%";
    }

    if (!empty($filtro_categoria)) {
        $where_conditions[] = "p.categoria_id = :filtro_categoria";
        $params[':filtro_categoria'] = $filtro_categoria;
    }

    if (!empty($filtro_fornecedor)) {
        $where_conditions[] = "p.fornecedor = :filtro_fornecedor";
        $params[':filtro_fornecedor'] = $filtro_fornecedor;
    }

    if (!empty($filtro_estoque)) {
        switch ($filtro_estoque) {
            case 'baixo':
                $where_conditions[] = "p.controla_estoque = 1 AND p.estoque_atual <= p.estoque_minimo";
                break;
            case 'zerado':
                $where_conditions[] = "p.controla_estoque = 1 AND p.estoque_atual = 0";
                break;
            case 'disponivel':
                $where_conditions[] = "p.controla_estoque = 1 AND p.estoque_atual > p.estoque_minimo";
                break;
            case 'nao_controla':
                $where_conditions[] = "p.controla_estoque = 0";
                break;
        }
    }

    if (!empty($filtro_preco_min)) {
        $where_conditions[] = "p.preco_unitario >= :filtro_preco_min";
        $params[':filtro_preco_min'] = floatval($filtro_preco_min);
    }

    if (!empty($filtro_preco_max)) {
        $where_conditions[] = "p.preco_unitario <= :filtro_preco_max";
        $params[':filtro_preco_max'] = floatval($filtro_preco_max);
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    // CONSULTA DE CONTAGEM
    $countSql = "SELECT COUNT(*) as total FROM produtos p
                 LEFT JOIN fornecedores f ON p.fornecedor = f.id
                 $categoriaJoin
                 $where_clause";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalProdutos = $countStmt->fetch()['total'];

    // CONSULTA PRINCIPAL COM ORDENAÇÃO
    $sql = "SELECT p.*, 
                   f.nome as fornecedor_nome, 
                   $categoriaSelect,
                   COALESCE(c.nome, p.categoria, 'Sem categoria') as categoria_display,
                   COALESCE(p.preco_venda, p.preco_unitario) as preco_exibicao 
            FROM produtos p
            LEFT JOIN fornecedores f ON p.fornecedor = f.id
            $categoriaJoin
            $where_clause
            ORDER BY $campo_ordenacao $direcao_ordenacao 
            LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindParam(':limit', $produtosPorPagina, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Erro ao buscar produtos: " . $e->getMessage();
    error_log("Erro na consulta de produtos: " . $e->getMessage());
}

$totalPaginas = ceil($totalProdutos / $produtosPorPagina);

// Processa requisição AJAX para dados do produto - VERSÃO CORRIGIDA
if (isset($_GET['get_produto_id'])) {
    $id = (int)$_GET['get_produto_id'];
    try {
        // Obtém configuração da categoria
        $categoriaConfig = getCategoriaJoinConfig($pdo);
        $categoriaJoin = $categoriaConfig['join'];
        $categoriaSelect = $categoriaConfig['select'];

        $sql = "SELECT p.*, 
                       f.nome as fornecedor_nome, 
                       $categoriaSelect,
                       COALESCE(c.nome, p.categoria, '') as categoria_display,
                       p.icms, p.irpj, p.cofins, p.csll, p.pis_pasep, p.ipi, 
                       p.margem_lucro, p.total_impostos, p.custo_total, p.preco_venda
                FROM produtos p
                LEFT JOIN fornecedores f ON p.fornecedor = f.id
                $categoriaJoin
                WHERE p.id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($produto) {
            // BUSCA ESTATÍSTICAS COM VERIFICAÇÃO DE TABELAS
            $possibleTables = [
                'vendas' => ['vendas_items', 'venda_produtos', 'itens_venda', 'produto_venda'],
                'compras' => ['compras_items', 'produto_compra', 'itens_compra', 'compra_produtos']
            ];

            $stats = [
                'total_vendas' => 0,
                'total_compras' => 0,
                'qtd_vendida' => 0,
                'qtd_comprada' => 0
            ];

            // Busca vendas em tabelas possíveis
            foreach ($possibleTables['vendas'] as $table) {
                if (tableExists($pdo, $table)) {
                    try {
                        $statsStmt = $pdo->prepare("
                            SELECT 
                                COUNT(*) as total_vendas,
                                COALESCE(SUM(quantidade), 0) as qtd_vendida
                            FROM `$table` 
                            WHERE produto_id = :id
                        ");
                        $statsStmt->bindParam(':id', $id, PDO::PARAM_INT);
                        $statsStmt->execute();
                        $vendaStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($vendaStats && $vendaStats['total_vendas'] > 0) {
                            $stats['total_vendas'] = $vendaStats['total_vendas'];
                            $stats['qtd_vendida'] = $vendaStats['qtd_vendida'];
                            break;
                        }
                    } catch (PDOException $e) {
                        continue;
                    }
                }
            }

            // Busca compras em tabelas possíveis
            foreach ($possibleTables['compras'] as $table) {
                if (tableExists($pdo, $table)) {
                    try {
                        $statsStmt = $pdo->prepare("
                            SELECT 
                                COUNT(*) as total_compras,
                                COALESCE(SUM(quantidade), 0) as qtd_comprada
                            FROM `$table` 
                            WHERE produto_id = :id
                        ");
                        $statsStmt->bindParam(':id', $id, PDO::PARAM_INT);
                        $statsStmt->execute();
                        $compraStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($compraStats && $compraStats['total_compras'] > 0) {
                            $stats['total_compras'] = $compraStats['total_compras'];
                            $stats['qtd_comprada'] = $compraStats['qtd_comprada'];
                            break;
                        }
                    } catch (PDOException $e) {
                        continue;
                    }
                }
            }
            
            $produto['stats'] = $stats;
            echo json_encode($produto);
        } else {
            echo json_encode(['error' => 'Produto não encontrado']);
        }
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar produto: ' . $e->getMessage()]);
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

include('includes/header_template.php');
renderHeader("Consulta de Produtos - LicitaSis", "produtos");
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Produtos - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
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

/* Reset básico */
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
.products-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1rem;
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
    box-shadow: var(--shadow);
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
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
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
}

table {
    width: 100%;
    border-collapse: collapse;
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
    z-index: 10;
}

table tbody tr {
    transition: var(--transition);
}

table tbody tr:hover {
    background: var(--light-gray);
}

.product-link {
    color: var(--secondary-color);
    text-decoration: none;
    font-weight: 600;
    transition: var(--transition);
}

.product-link:hover {
    color: var(--primary-color);
    text-decoration: underline;
}

/* Preço formatado */
.price {
    font-weight: 600;
    color: var(--success-color);
    font-family: 'Courier New', monospace;
}

/* Badge de categoria */
.category-badge {
    background: var(--primary-light);
    color: var(--primary-color);
    padding: 0.25rem 0.5rem;
    border-radius: var(--radius);
    font-size: 0.8rem;
    font-weight: 600;
}

/* Botões de ação na tabela */
.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.btn-sm {
    padding: 0.5rem 0.875rem;
    font-size: 0.875rem;
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
    display: flex;
    align-items: center;
    gap: 0.75rem;
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
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.95rem;
}

.form-group label i {
    color: var(--secondary-color);
    width: 16px;
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

select.form-control {
    cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M10.293 3.293L6 7.586 1.707 3.293A1 1 0 00.293 4.707l5 5a1 1 0 001.414 0l5-5a1 1 0 10-1.414-1.414z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 1rem center;
    background-size: 1rem;
    padding-right: 2.5rem;
}

/* Seções do formulário */
.form-section {
    margin: 2rem 0;
    padding: 1.5rem;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: var(--radius);
    border-left: 4px solid var(--secondary-color);
}

.form-section-title {
    color: var(--primary-color);
    font-size: 1.2rem;
    font-weight: 600;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.impostos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.imposto-item {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.imposto-item label {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--primary-color);
}

.resumo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 2px solid var(--border-color);
}

.resumo-item {
    background: white;
    padding: 1rem;
    border-radius: var(--radius);
    border: 1px solid var(--border-color);
    text-align: center;
}

.resumo-item .label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--medium-gray);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.resumo-item .valor {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--success-color);
    font-family: 'Courier New', monospace;
}

/* Campo de preço com ícone de moeda */
.price-input-wrapper {
    position: relative;
}

.price-input-wrapper::before {
    content: "R$";
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--medium-gray);
    font-weight: 600;
    pointer-events: none;
}

.price-input {
    padding-left: 3rem !important;
}

/* Estatísticas do produto */
.product-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: linear-gradient(135deg, var(--light-gray), #fff);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    padding: 1.5rem;
    text-align: center;
    transition: var(--transition);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

.stat-number {
    font-size: 2rem;
    font-weight: 800;
    color: var(--primary-color);
    margin-bottom: 0.5rem;
    font-family: 'Courier New', monospace;
}

.stat-label {
    color: var(--medium-gray);
    font-size: 0.9rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Preview da imagem no modal */
.image-preview-modal {
    text-align: center;
    margin-bottom: 1.5rem;
}

.image-preview-modal img {
    max-width: 300px;
    max-height: 300px;
    object-fit: contain;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    background: var(--light-gray);
}

.no-image {
    width: 300px;
    height: 200px;
    background: var(--light-gray);
    border: 2px dashed var(--border-color);
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--medium-gray);
    font-size: 0.9rem;
    margin: 0 auto;
    flex-direction: column;
    gap: 0.5rem;
}

/* Campo de arquivo customizado */
.file-input-wrapper {
    position: relative;
    overflow: hidden;
    display: inline-block;
    width: 100%;
}

.file-input {
    position: absolute;
    left: -9999px;
}

.file-input-label {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 1rem;
    background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
    border: 2px dashed var(--border-color);
    border-radius: var(--radius);
    cursor: pointer;
    transition: var(--transition);
    color: var(--dark-gray);
    font-weight: 500;
}

.file-input-label:hover {
    background: linear-gradient(135deg, #e9ecef 0%, var(--light-gray) 100%);
    border-color: var(--secondary-color);
    transform: translateY(-2px);
}

.file-input-label i {
    font-size: 1.5rem;
    color: var(--secondary-color);
}

/* Sistema de busca de fornecedores */
.fornecedor-search-container {
    position: relative;
}

.fornecedor-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid var(--secondary-color);
    border-radius: 0 0 var(--radius) var(--radius);
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    z-index: 1000;
    max-height: 300px;
    overflow-y: auto;
    display: none;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from { 
        opacity: 0; 
        transform: translateY(-10px); 
    }
    to { 
        opacity: 1; 
        transform: translateY(0); 
    }
}

.fornecedor-suggestion-item {
    padding: 1rem;
    cursor: pointer;
    border-bottom: 1px solid var(--border-color);
    transition: var(--transition);
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.fornecedor-suggestion-item:hover {
    background: var(--light-gray);
    padding-left: 1.5rem;
}

.fornecedor-suggestion-item:last-child {
    border-bottom: none;
}

.fornecedor-nome {
    font-weight: 600;
    color: var(--primary-color);
    font-size: 1rem;
}

.fornecedor-detalhes {
    font-size: 0.85rem;
    color: var(--medium-gray);
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}

.fornecedor-detalhes span {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.fornecedor-detalhes i {
    width: 12px;
    color: var(--secondary-color);
}

/* Estado de carregamento */
.loading-item {
    padding: 1.5rem;
    text-align: center;
    color: var(--medium-gray);
    font-style: italic;
}

.loading-item i {
    font-size: 1.2rem;
    margin-right: 0.5rem;
    color: var(--secondary-color);
}

/* Quando não há resultados */
.no-fornecedores {
    padding: 2rem;
    text-align: center;
    color: var(--medium-gray);
}

.no-fornecedores i {
    font-size: 2rem;
    margin-bottom: 1rem;
    color: var(--border-color);
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

/* Responsividade */
@media (max-width: 1200px) {
    .products-container {
        margin: 0 1rem;
    }
}

@media (max-width: 768px) {
    .page-header {
        padding: 1.5rem;
    }

    .page-header h1 {
        font-size: 1.8rem;
        flex-direction: column;
        gap: 0.5rem;
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

    .action-buttons {
        justify-content: center;
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

    table th, table td {
        padding: 0.75rem 0.5rem;
        font-size: 0.9rem;
    }

    .fornecedor-suggestions {
        max-height: 250px;
    }

    .fornecedor-detalhes {
        flex-direction: column;
        gap: 0.25rem;
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

    .product-stats {
        grid-template-columns: 1fr;
    }

    .stat-number {
        font-size: 1.5rem;
    }

    .image-preview-modal img, .no-image {
        max-width: 250px;
        max-height: 250px;
    }

    .impostos-grid, .resumo-grid {
        grid-template-columns: 1fr;
    }
}

/* Animações */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.table-container, .controls-bar, .results-info {
    animation: fadeInUp 0.6s ease forwards;
}

.table-container { animation-delay: 0.1s; }
.controls-bar { animation-delay: 0.05s; }
.results-info { animation-delay: 0.15s; }

/* Scrollbar personalizada */
.table-responsive::-webkit-scrollbar, 
.modal-body::-webkit-scrollbar, 
.fornecedor-suggestions::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.table-responsive::-webkit-scrollbar-track, 
.modal-body::-webkit-scrollbar-track, 
.fornecedor-suggestions::-webkit-scrollbar-track {
    background: var(--light-gray);
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb, 
.modal-body::-webkit-scrollbar-thumb, 
.fornecedor-suggestions::-webkit-scrollbar-thumb {
    background: var(--medium-gray);
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb:hover, 
.modal-body::-webkit-scrollbar-thumb:hover, 
.fornecedor-suggestions::-webkit-scrollbar-thumb:hover {
    background: var(--dark-gray);
}

/* Estados especiais */
.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none !important;
}

.form-control.editable {
    border-color: var(--info-color);
    background: rgba(23, 162, 184, 0.05);
}

.form-control.error {
    border-color: var(--danger-color);
    background: rgba(220, 53, 69, 0.05);
}

.form-control.success {
    border-color: var(--success-color);
    background: rgba(40, 167, 69, 0.05);
}

/* Melhorias visuais */
.modal-content {
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.page-header {
    background-attachment: fixed;
}

.btn {
    position: relative;
    overflow: hidden;
}

.btn::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    transition: width 0.6s, height 0.6s;
}

.btn:active::before {
    width: 300px;
    height: 300px;
}

/* Efeitos especiais para o sistema de fornecedores */
.fornecedor-search-container input:focus + input + .fornecedor-suggestions {
    border-color: var(--primary-color);
}

.fornecedor-suggestion-item.selected {
    background: var(--primary-light);
    color: var(--primary-color);
    font-weight: 600;
}

/* Indicadores visuais */
.loading-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid var(--border-color);
    border-radius: 50%;
    border-top-color: var(--secondary-color);
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Tooltips simples */
[title] {
    position: relative;
}

[title]:hover::after {
    content: attr(title);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: var(--dark-gray);
    color: white;
    padding: 0.5rem 0.75rem;
    border-radius: var(--radius);
    font-size: 0.8rem;
    white-space: nowrap;
    z-index: 1001;
    opacity: 0;
    animation: fadeIn 0.3s ease forwards;
}

/* Estados de validação do formulário */
.form-group.has-error .form-control {
    border-color: var(--danger-color);
    box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
}

.form-group.has-success .form-control {
    border-color: var(--success-color);
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
}

.form-group.has-warning .form-control {
    border-color: var(--warning-color);
    box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.1);
}

/* Melhorias para acessibilidade */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* Focus visible para navegação por teclado */
.btn:focus-visible,
.form-control:focus-visible,
.modal-close:focus-visible {
    outline: 2px solid var(--secondary-color);
    outline-offset: 2px;
}

/* Melhorias para impressão */
@media print {
    .controls-bar,
    .pagination-container,
    .modal,
    .btn {
        display: none !important;
    }
    
    .page-header {
        background: none !important;
        color: var(--dark-gray) !important;
    }
    
    table th {
        background: none !important;
        color: var(--dark-gray) !important;
        border: 1px solid var(--dark-gray) !important;
    }
    
    table td {
        border: 1px solid var(--medium-gray) !important;
    }
}

/* Estados de carregamento específicos */
.btn.loading {
    pointer-events: none;
    position: relative;
}

.btn.loading::after {
    content: '';
    position: absolute;
    width: 16px;
    height: 16px;
    margin: auto;
    border: 2px solid transparent;
    border-top-color: currentColor;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    top: 0;
    left: 0;
    bottom: 0;
    right: 0;
}

/* Transições suaves para elementos dinâmicos */
.product-stats,
.image-preview-modal,
.fornecedor-suggestions {
    transition: all 0.3s ease;
}

/* Destacar campos obrigatórios */
.form-group label.required::after {
    content: ' *';
    color: var(--danger-color);
    font-weight: bold;
}

/* Estilos para mensagens de validação inline */
.validation-message {
    font-size: 0.85rem;
    margin-top: 0.25rem;
    padding: 0.5rem;
    border-radius: var(--radius);
    display: none;
}

.validation-message.error {
    background: rgba(220, 53, 69, 0.1);
    color: var(--danger-color);
    border: 1px solid rgba(220, 53, 69, 0.2);
}

.validation-message.success {
    background: rgba(40, 167, 69, 0.1);
    color: var(--success-color);
    border: 1px solid rgba(40, 167, 69, 0.2);
}

.validation-message.warning {
    background: rgba(255, 193, 7, 0.1);
    color: #856404;
    border: 1px solid rgba(255, 193, 7, 0.2);
}

/* Otimizações para dispositivos touch */
@media (hover: none) and (pointer: coarse) {
    .btn {
        min-height: 44px;
        min-width: 44px;
    }
    
    .form-control {
        min-height: 44px;
    }
    
    .fornecedor-suggestion-item {
        min-height: 50px;
        padding: 1.25rem 1rem;
    }
    
    table th,
    table td {
        padding: 1.25rem 1rem;
    }
}

/* Garantir que o tema sempre permaneça claro */
body {
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%) !important;
    color: var(--dark-gray) !important;
}

/* Forçar tema claro mesmo em modo escuro do sistema */
@media (prefers-color-scheme: dark) {
    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%) !important;
        color: var(--dark-gray) !important;
    }
    
    .table-container,
    .controls-bar,
    .results-info,
    .modal-content,
    .fornecedor-suggestions {
        background: white !important;
        color: var(--dark-gray) !important;
    }
    
    .form-control[readonly] {
        background: var(--light-gray) !important;
        color: var(--medium-gray) !important;
    }
    
    .page-header {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%) !important;
        color: white !important;
    }
}

/* Animações de entrada para elementos */
.fade-in {
    opacity: 0;
    animation: fadeIn 0.5s ease forwards;
}

.slide-in-left {
    transform: translateX(-30px);
    opacity: 0;
    animation: slideInLeft 0.5s ease forwards;
}

.slide-in-right {
    transform: translateX(30px);
    opacity: 0;
    animation: slideInRight 0.5s ease forwards;
}

@keyframes slideInLeft {
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slideInRight {
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* Pulse effect para elementos importantes */
.pulse {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(0, 191, 174, 0.7);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(0, 191, 174, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(0, 191, 174, 0);
    }
}

/* Melhorias finais de performance */
* {
    will-change: auto;
}

.modal,
.fornecedor-suggestions,
.btn:hover {
    will-change: transform, opacity;
}

/* Garantir que o layout não quebre em telas muito pequenas */
@media (max-width: 320px) {
    .page-header h1 {
        font-size: 1.3rem;
    }
    
    .controls-bar,
    .modal-body {
        padding: 1rem;
    }
    
    .form-row {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .btn {
        padding: 0.75rem 1rem;
        font-size: 0.9rem;
    }
    
    .modal-content {
        width: 95%;
        margin: 0.5rem auto;
    }
}
/* Estilos para filtros */
.filters-container {
    margin-bottom: 2rem;
    animation: slideDown 0.3s ease;
}

.filters-card {
    background: white;
    padding: 2rem;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    border-left: 4px solid var(--info-color);
}

.filters-card h4 {
    color: var(--info-color);
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-group label {
    font-weight: 600;
    color: var(--primary-color);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
}

.filter-group label i {
    color: var(--info-color);
    width: 16px;
}

.filters-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    justify-content: center;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border-color);
}

/* Estilos para headers ordenáveis */
.sortable-header {
    cursor: pointer;
    user-select: none;
    position: relative;
    transition: var(--transition);
}

.sortable-header:hover {
    background: rgba(255, 255, 255, 0.1) !important;
    transform: translateY(-1px);
}

.sort-indicator {
    margin-left: 0.5rem;
    opacity: 0.6;
    font-size: 0.8rem;
}

.sortable-header:hover .sort-indicator {
    opacity: 1;
}

/* Responsividade para filtros */
@media (max-width: 768px) {
    .filters-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .filters-buttons {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
}

    </style>
</head>
<body>

<div class="main-content">
    <div class="container products-container">
        
        <!-- Header da página -->
        <div class="page-header">
            <h1><i class="fas fa-box-open"></i> Consultar Produtos</h1>
            <p>Visualize, edite e gerencie todos os produtos cadastrados no sistema</p>
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

        <!-- Barra de controles -->
      <!-- Barra de controles -->
<div class="controls-bar">
    <!-- Busca Geral -->
    <form class="search-form" action="consulta_produto.php" method="GET" id="searchForm">
        <input type="text" 
               name="search" 
               class="search-input"
               placeholder="Busca geral por Código, Nome, Categoria ou Fornecedor..." 
               value="<?php echo htmlspecialchars($searchTerm); ?>"
               autocomplete="off">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Buscar
        </button>
        <button type="button" class="btn btn-info" onclick="toggleFilters()">
            <i class="fas fa-filter"></i> Filtros
        </button>
        <?php if ($searchTerm || $filtro_nome || $filtro_codigo || $filtro_categoria || $filtro_fornecedor || $filtro_estoque || $filtro_preco_min || $filtro_preco_max): ?>
            <a href="consulta_produto.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Limpar
            </a>
        <?php endif; ?>
        
        <!-- Preserve filter parameters -->
        <?php foreach (['filtro_nome', 'filtro_codigo', 'filtro_categoria', 'filtro_fornecedor', 'filtro_estoque', 'filtro_preco_min', 'filtro_preco_max', 'ordem_campo', 'ordem_direcao'] as $param): ?>
            <?php if (!empty($_GET[$param])): ?>
                <input type="hidden" name="<?php echo $param; ?>" value="<?php echo htmlspecialchars($_GET[$param]); ?>">
            <?php endif; ?>
        <?php endforeach; ?>
    </form>
    
    <?php if ($canCreate): ?>
        <a href="cadastro_produto.php" class="btn btn-success">
            <i class="fas fa-plus"></i> Novo Produto
        </a>
    <?php endif; ?>
</div>

<!-- Filtros Avançados -->
<div class="filters-container" id="filtersContainer" style="display: none;">
    <div class="filters-card">
        <h4><i class="fas fa-filter"></i> Filtros Avançados</h4>
        <form action="consulta_produto.php" method="GET" id="filtersForm">
            <!-- Preserve search parameter -->
            <?php if ($searchTerm): ?>
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>">
            <?php endif; ?>
            
            <div class="filters-grid">
                <div class="filter-group">
                    <label for="filtro_nome"><i class="fas fa-tag"></i> Nome do Produto</label>
                    <input type="text" name="filtro_nome" id="filtro_nome" 
                           class="form-control" placeholder="Nome do produto..."
                           value="<?php echo htmlspecialchars($filtro_nome); ?>">
                </div>
                
                <div class="filter-group">
                    <label for="filtro_codigo"><i class="fas fa-barcode"></i> Código</label>
                    <input type="text" name="filtro_codigo" id="filtro_codigo" 
                           class="form-control" placeholder="Código do produto..."
                           value="<?php echo htmlspecialchars($filtro_codigo); ?>">
                </div>
                
                <div class="filter-group">
                    <label for="filtro_categoria"><i class="fas fa-tags"></i> Categoria</label>
                    <select name="filtro_categoria" id="filtro_categoria" class="form-control">
                        <option value="">Todas as categorias</option>
                        <?php
                        try {
                            $stmt = $pdo->query("SELECT id, nome FROM categorias WHERE status = 'ativo' ORDER BY nome ASC");
                            $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach ($categorias as $categoria) {
                                $selected = ($filtro_categoria == $categoria['id']) ? 'selected' : '';
                                echo "<option value='" . $categoria['id'] . "' " . $selected . ">" . 
                                     htmlspecialchars($categoria['nome']) . "</option>";
                            }
                        } catch (PDOException $e) {
                            error_log("Erro ao buscar categorias para filtro: " . $e->getMessage());
                        }
                        ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filtro_fornecedor"><i class="fas fa-truck"></i> Fornecedor</label>
                    <select name="filtro_fornecedor" id="filtro_fornecedor" class="form-control">
                        <option value="">Todos os fornecedores</option>
                        <?php
                        try {
                            $stmt = $pdo->query("SELECT id, nome FROM fornecedores ORDER BY nome ASC");
                            $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach ($fornecedores as $fornecedor) {
                                $selected = ($filtro_fornecedor == $fornecedor['id']) ? 'selected' : '';
                                echo "<option value='" . $fornecedor['id'] . "' " . $selected . ">" . 
                                     htmlspecialchars($fornecedor['nome']) . "</option>";
                            }
                        } catch (PDOException $e) {
                            error_log("Erro ao buscar fornecedores para filtro: " . $e->getMessage());
                        }
                        ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filtro_estoque"><i class="fas fa-warehouse"></i> Situação do Estoque</label>
                    <select name="filtro_estoque" id="filtro_estoque" class="form-control">
                        <option value="">Todos os produtos</option>
                        <option value="baixo" <?php echo ($filtro_estoque == 'baixo') ? 'selected' : ''; ?>>Estoque baixo</option>
                        <option value="zerado" <?php echo ($filtro_estoque == 'zerado') ? 'selected' : ''; ?>>Estoque zerado</option>
                        <option value="disponivel" <?php echo ($filtro_estoque == 'disponivel') ? 'selected' : ''; ?>>Estoque disponível</option>
                        <option value="nao_controla" <?php echo ($filtro_estoque == 'nao_controla') ? 'selected' : ''; ?>>Não controla estoque</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filtro_preco_min"><i class="fas fa-dollar-sign"></i> Preço Mínimo</label>
                    <input type="number" name="filtro_preco_min" id="filtro_preco_min" 
                           class="form-control" step="0.01" min="0" placeholder="0.00"
                           value="<?php echo htmlspecialchars($filtro_preco_min); ?>">
                </div>
                
                <div class="filter-group">
                    <label for="filtro_preco_max"><i class="fas fa-dollar-sign"></i> Preço Máximo</label>
                    <input type="number" name="filtro_preco_max" id="filtro_preco_max" 
                           class="form-control" step="0.01" min="0" placeholder="0.00"
                           value="<?php echo htmlspecialchars($filtro_preco_max); ?>">
                </div>
                
                <div class="filter-group">
                    <label for="ordem_campo"><i class="fas fa-sort"></i> Ordenar por</label>
                    <select name="ordem_campo" id="ordem_campo" class="form-control">
                        <option value="nome" <?php echo ($ordem_campo == 'nome') ? 'selected' : ''; ?>>Nome</option>
                        <option value="codigo" <?php echo ($ordem_campo == 'codigo') ? 'selected' : ''; ?>>Código</option>
                        <option value="preco_unitario" <?php echo ($ordem_campo == 'preco_unitario') ? 'selected' : ''; ?>>Preço Unitário</option>
                        <option value="preco_venda" <?php echo ($ordem_campo == 'preco_venda') ? 'selected' : ''; ?>>Preço de Venda</option>
                        <option value="estoque_atual" <?php echo ($ordem_campo == 'estoque_atual') ? 'selected' : ''; ?>>Estoque</option>
                        <option value="created_at" <?php echo ($ordem_campo == 'created_at') ? 'selected' : ''; ?>>Data de Cadastro</option>
                        <option value="categoria" <?php echo ($ordem_campo == 'categoria') ? 'selected' : ''; ?>>Categoria</option>
                        <option value="fornecedor" <?php echo ($ordem_campo == 'fornecedor') ? 'selected' : ''; ?>>Fornecedor</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="ordem_direcao"><i class="fas fa-sort-amount-down"></i> Direção</label>
                    <select name="ordem_direcao" id="ordem_direcao" class="form-control">
                        <option value="ASC" <?php echo ($ordem_direcao == 'ASC') ? 'selected' : ''; ?>>Crescente (A-Z, 0-9)</option>
                        <option value="DESC" <?php echo ($ordem_direcao == 'DESC') ? 'selected' : ''; ?>>Decrescente (Z-A, 9-0)</option>
                    </select>
                </div>
            </div>
            
            <div class="filters-buttons">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Aplicar Filtros
                </button>
                <button type="button" class="btn btn-secondary" onclick="clearFilters()">
                    <i class="fas fa-eraser"></i> Limpar Filtros
                </button>
                <button type="button" class="btn btn-info" onclick="toggleFilters()">
                    <i class="fas fa-eye-slash"></i> Ocultar Filtros
                </button>
            </div>
        </form>
    </div>
</div>

        <!-- Informações de resultados -->
        <?php if ($totalProdutos > 0): ?>
            <div class="results-info">
                <div class="results-count">
                    <?php if ($searchTerm): ?>
                        Encontrados <strong><?php echo $totalProdutos; ?></strong> produto(s) 
                        para "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>"
                    <?php else: ?>
                        Total de <strong><?php echo $totalProdutos; ?></strong> produto(s) cadastrado(s)
                    <?php endif; ?>
                    
                    <?php if ($totalPaginas > 1): ?>
                        - Página <strong><?php echo $paginaAtual; ?></strong> de <strong><?php echo $totalPaginas; ?></strong>
                    <?php endif; ?>
                </div>
                
                <?php if ($totalProdutos > $produtosPorPagina): ?>
                    <div>
                        Mostrando <?php echo ($offset + 1); ?>-<?php echo min($offset + $produtosPorPagina, $totalProdutos); ?> 
                        de <?php echo $totalProdutos; ?> resultados (<?php echo $produtosPorPagina; ?> por página)
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Tabela de produtos -->
        <?php if (count($produtos) > 0): ?>
            <div class="table-container">
                <div class="table-responsive">
                    <table>
                        <thead>
    <tr>
        <th class="sortable-header" onclick="changeOrder('codigo')">
            <i class="fas fa-barcode"></i> Código
            <?php if ($ordem_campo == 'codigo'): ?>
                <i class="fas fa-sort-<?php echo $ordem_direcao == 'ASC' ? 'up' : 'down'; ?> sort-indicator"></i>
            <?php else: ?>
                <i class="fas fa-sort sort-indicator"></i>
            <?php endif; ?>
        </th>
        <th class="sortable-header" onclick="changeOrder('nome')">
            <i class="fas fa-tag"></i> Nome
            <?php if ($ordem_campo == 'nome'): ?>
                <i class="fas fa-sort-<?php echo $ordem_direcao == 'ASC' ? 'up' : 'down'; ?> sort-indicator"></i>
            <?php else: ?>
                <i class="fas fa-sort sort-indicator"></i>
            <?php endif; ?>
        </th>
        <th class="sortable-header" onclick="changeOrder('categoria')">
            <i class="fas fa-tags"></i> Categoria
            <?php if ($ordem_campo == 'categoria'): ?>
                <i class="fas fa-sort-<?php echo $ordem_direcao == 'ASC' ? 'up' : 'down'; ?> sort-indicator"></i>
            <?php else: ?>
                <i class="fas fa-sort sort-indicator"></i>
            <?php endif; ?>
        </th>
        <th class="sortable-header" onclick="changeOrder('preco_venda')">
            <i class="fas fa-dollar-sign"></i> Preço de Venda
            <?php if ($ordem_campo == 'preco_venda'): ?>
                <i class="fas fa-sort-<?php echo $ordem_direcao == 'ASC' ? 'up' : 'down'; ?> sort-indicator"></i>
            <?php else: ?>
                <i class="fas fa-sort sort-indicator"></i>
            <?php endif; ?>
        </th>
        <th><i class="fas fa-cogs"></i> Ações</th>
    </tr>
</thead>
                        <tbody>
                            <?php foreach ($produtos as $produto): ?>
                                <tr>
                                    <td>
                                        <a href="javascript:void(0);" 
                                           onclick="openModal(<?php echo $produto['id']; ?>)" 
                                           class="product-link">
                                            <?php echo htmlspecialchars($produto['codigo']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($produto['nome']); ?></td>
                                    <td>
    <?php if (!empty($produto['categoria_display'])): ?>
        <span class="category-badge">
            <?php echo htmlspecialchars($produto['categoria_display']); ?>
        </span>
    <?php else: ?>
        <span style="color: var(--medium-gray); font-style: italic;">Sem categoria</span>
    <?php endif; ?>
</td>
                                   <td>
    <span class="price" title="Preço com impostos e margem de lucro">
        R$ <?php echo number_format($produto['preco_exibicao'], 2, ',', '.'); ?>
    </span>
    <?php if ($produto['preco_venda'] != $produto['preco_unitario']): ?>
        <small style="display: block; color: var(--medium-gray); font-size: 0.8rem;">
            Unit.: R$ <?php echo number_format($produto['preco_unitario'], 2, ',', '.'); ?>
        </small>
    <?php endif; ?>
</td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="consultar_compras_produto.php?produto_id=<?php echo $produto['id']; ?>" 
                                               class="btn btn-info btn-sm" title="Ver Compras">
                                                <i class="fas fa-sign-in-alt"></i>
                                            </a>
                                            <a href="consultar_vendas_produto.php?produto_id=<?php echo $produto['id']; ?>" 
                                               class="btn btn-warning btn-sm" title="Ver Vendas">
                                                <i class="fas fa-sign-out-alt"></i>
                                            </a>
                                            <button onclick="openModal(<?php echo $produto['id']; ?>)" 
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
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">
                                <i class="fas fa-angle-double-left"></i> Primeira
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $paginaAtual - 1])); ?>">
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
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor;
                        
                        if ($fim < $totalPaginas): ?>
                            <span>...</span>
                        <?php endif; ?>

                        <?php if ($paginaAtual < $totalPaginas): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $paginaAtual + 1])); ?>">
                                Próxima <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPaginas])); ?>">
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
                <i class="fas fa-box-open"></i>
                <h3>Nenhum produto encontrado</h3>
                <?php if ($searchTerm): ?>
                    <p>Não foram encontrados produtos com os termos de busca "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>".</p>
                    <a href="consulta_produto.php" class="btn btn-primary">
                        <i class="fas fa-list"></i> Ver Todos os Produtos
                    </a>
                <?php else: ?>
                    <p>Ainda não há produtos cadastrados no sistema.</p>
                    <?php if ($canCreate): ?>
                        <a href="cadastro_produto.php" class="btn btn-success">
                            <i class="fas fa-plus"></i> Cadastrar Primeiro Produto
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- Modal de Detalhes/Edição -->
<div id="productModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-box-open"></i> Detalhes do Produto</h3>
            <span class="modal-close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <!-- Estatísticas do produto -->
            <div class="product-stats" id="productStats" style="display: none;">
                <!-- Estatísticas serão inseridas via JavaScript -->
            </div>

            <!-- Formulário de detalhes/edição -->
            <form method="POST" action="consulta_produto.php" id="productForm" enctype="multipart/form-data">
                <input type="hidden" name="id" id="product_id">
                <input type="hidden" name="update_product" value="1">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="codigo"><i class="fas fa-barcode"></i> Código *</label>
                        <input type="text" name="codigo" id="codigo" class="form-control" readonly required>
                    </div>
                    
                    <div class="form-group">
                        <label for="nome"><i class="fas fa-tag"></i> Nome *</label>
                        <input type="text" name="nome" id="nome" class="form-control" readonly required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="und"><i class="fas fa-box"></i> Unidade *</label>
                        <input type="text" name="und" id="und" class="form-control" readonly required>
                    </div>
                    
                    <div class="form-group">
    <label for="categoria">
        <i class="fas fa-tags"></i> 
        Categoria
    </label>
    <select id="categoria" name="categoria" class="form-control" disabled>
        <option value="">Selecione uma categoria</option>
        <!-- As opções serão carregadas via JavaScript -->
    </select>
</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="preco_unitario"><i class="fas fa-dollar-sign"></i> Preço Unitário *</label>
                        <div class="price-input-wrapper">
                            <input type="number" name="preco_unitario" id="preco_unitario" 
                                   class="form-control price-input" step="0.01" min="0" readonly required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="estoque_minimo"><i class="fas fa-layer-group"></i> Estoque Mínimo</label>
                        <input type="number" name="estoque_minimo" id="estoque_minimo" 
                               class="form-control" min="0" readonly>
                    </div>
                </div>
                
                <!-- Sistema de busca de fornecedores -->
                <div class="form-group">
                    <label for="fornecedor_search"><i class="fas fa-truck"></i> Fornecedor</label>
                    <div class="fornecedor-search-container">
                        <input type="text" 
                               name="fornecedor_search" 
                               id="fornecedor_search" 
                               class="form-control"
                               placeholder="Digite para pesquisar fornecedor..."
                               autocomplete="off"
                               readonly>
                        <input type="hidden" name="fornecedor" id="fornecedor" value="">
                        
                        <!-- Container de sugestões de fornecedores -->
                        <div class="fornecedor-suggestions" id="fornecedor_suggestions" style="display: none;">
                            <!-- Sugestões serão inseridas dinamicamente aqui -->
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="observacao"><i class="fas fa-sticky-note"></i> Observações</label>
                    <textarea name="observacao" id="observacao" class="form-control" readonly rows="4"></textarea>
                </div>

                <div class="form-section impostos-section">
                    <h4 class="form-section-title">
                        <i class="fas fa-receipt"></i> Impostos e Tributação
                    </h4>
                    
                    <div class="impostos-grid">
                        <div class="imposto-item">
                            <label for="icms">ICMS (%)</label>
                            <input type="number" id="icms" name="icms" class="form-control" 
                                   step="0.01" min="0" max="100" readonly>
                        </div>
                        
                        <div class="imposto-item">
                            <label for="irpj">IRPJ (%)</label>
                            <input type="number" id="irpj" name="irpj" class="form-control" 
                                   step="0.01" min="0" max="100" readonly>
                        </div>
                        
                        <div class="imposto-item">
                            <label for="cofins">COFINS (%)</label>
                            <input type="number" id="cofins" name="cofins" class="form-control" 
                                   step="0.01" min="0" max="100" readonly>
                        </div>
                        
                        <div class="imposto-item">
                            <label for="csll">CSLL (%)</label>
                            <input type="number" id="csll" name="csll" class="form-control" 
                                   step="0.01" min="0" max="100" readonly>
                        </div>
                        
                        <div class="imposto-item">
                            <label for="pis_pasep">PIS/PASEP (%)</label>
                            <input type="number" id="pis_pasep" name="pis_pasep" class="form-control" 
                                   step="0.01" min="0" max="100" readonly>
                        </div>
                        
                        <div class="imposto-item">
                            <label for="ipi">IPI (%)</label>
                            <input type="number" id="ipi" name="ipi" class="form-control" 
                                   step="0.01" min="0" max="100" readonly>
                        </div>
                    </div>

                    <div class="resumo-grid">
                        <div class="resumo-item">
                            <div class="label">Margem de Lucro (%)</div>
                            <input type="number" id="margem_lucro" name="margem_lucro" 
                                   class="form-control" step="0.01" min="0" readonly>
                        </div>
                        
                        <div class="resumo-item">
                            <div class="label">Total Impostos</div>
                            <div class="valor" id="totalImpostos">R$ 0,00</div>
                            <input type="hidden" name="total_impostos_valor" id="total_impostos_valor">
                        </div>
                        
                        <div class="resumo-item">
                            <div class="label">Custo Total</div>
                            <div class="valor" id="custoTotal">R$ 0,00</div>
                            <input type="hidden" name="custo_total_valor" id="custo_total_valor">
                        </div>
                        
                        <div class="resumo-item">
                            <div class="label">Preço de Venda</div>
                            <div class="valor" id="precoVenda">R$ 0,00</div>
                            <input type="hidden" name="preco_venda_valor" id="preco_venda_valor">
                        </div>
                    </div>
                </div>

                <!-- Preview de imagem -->
                <div class="form-group">
                    <label><i class="fas fa-image"></i> Imagem do Produto</label>
                    <div class="image-preview-modal" id="imagePreview"></div>
                    
                    <!-- Campo de upload de imagem (visível apenas na edição) -->
                    <div id="imageUploadGroup" style="display: none;">
                        <div class="file-input-wrapper">
                            <input type="file" name="imagem" id="imagem" class="file-input" 
                                   accept="image/jpeg,image/png,image/gif,image/webp">
                            <label for="imagem" class="file-input-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                Clique para selecionar uma nova imagem
                            </label>
                        </div>
                    </div>
                </div>

                <div class="modal-buttons">
                    <button type="submit" name="update_product" id="saveBtn" class="btn btn-success" style="display: none;">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                    
                    <?php if ($canEdit): ?>
                        <button type="button" class="btn btn-primary" id="editBtn" onclick="enableEditing()">
                            <i class="fas fa-edit"></i> Editar
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($canDelete): ?>
                        <button type="button" class="btn btn-danger" id="deleteBtn" onclick="openDeleteModal()">
                            <i class="fas fa-trash-alt"></i> Excluir
                        </button>
                    <?php endif; ?>
                    
                    <a href="#" id="verComprasBtn" class="btn btn-info">
                        <i class="fas fa-sign-in-alt"></i> Ver Compras
                    </a>
                    
                    <a href="#" id="verVendasBtn" class="btn btn-warning">
                        <i class="fas fa-sign-out-alt"></i> Ver Vendas
                    </a>
                    <a href="#" id="verEmpenhosBtn" class="btn btn-info">
                        <i class="fas fa-file-invoice-dollar"></i> Ver Empenhos
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
                    Tem certeza que deseja excluir este produto?
                </p>
                <p style="color: var(--danger-color); font-weight: 600;">
                    <i class="fas fa-warning"></i> Esta ação não pode ser desfeita.
                </p>
                <p style="color: var(--medium-gray); font-size: 0.9rem; margin-top: 1rem;">
                    O produto só pode ser excluído se não possuir vendas ou compras associadas.
                </p>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="btn btn-danger" onclick="deleteProduct()">
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
// Variáveis globais
let editingMode = false;
let currentProductId = null;
let categorias = [];

// Função para buscar fornecedores
function fetchFornecedorSuggestions(inputElement) {
    const query = inputElement.value.trim();
    const suggestionsContainer = inputElement.nextElementSibling.nextElementSibling;

    if (query.length > 0) {
        suggestionsContainer.innerHTML = '<div class="loading-item"><i class="fas fa-spinner fa-spin"></i> Buscando fornecedores...</div>';
        suggestionsContainer.style.display = 'block';
        
        fetch(`consulta_produto.php?search_fornecedores_ajax=1&query=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                suggestionsContainer.innerHTML = '';

                if (data.length > 0) {
                    data.forEach(fornecedor => {
                        const suggestionItem = document.createElement('div');
                        suggestionItem.classList.add('fornecedor-suggestion-item');
                        
                        let detalhes = [];
                        if (fornecedor.codigo) detalhes.push(`<span><i class="fas fa-barcode"></i> ${fornecedor.codigo}</span>`);
                        if (fornecedor.cnpj) detalhes.push(`<span><i class="fas fa-id-card"></i> ${fornecedor.cnpj}</span>`);
                        if (fornecedor.telefone) detalhes.push(`<span><i class="fas fa-phone"></i> ${fornecedor.telefone}</span>`);
                        if (fornecedor.email) detalhes.push(`<span><i class="fas fa-envelope"></i> ${fornecedor.email}</span>`);
                        
                        suggestionItem.innerHTML = `
                            <div class="fornecedor-nome">${fornecedor.nome}</div>
                            <div class="fornecedor-detalhes">${detalhes.join('')}</div>
                        `;

                        suggestionItem.onclick = function() {
                            inputElement.value = fornecedor.nome;
                            suggestionsContainer.innerHTML = '';
                            suggestionsContainer.style.display = 'none';

                            const fornecedorIdInput = inputElement.nextElementSibling;
                            fornecedorIdInput.value = fornecedor.id;
                            
                            inputElement.style.backgroundColor = "#d4edda";
                            setTimeout(() => {
                                inputElement.style.backgroundColor = "";
                            }, 1000);
                        };

                        suggestionsContainer.appendChild(suggestionItem);
                    });
                } else {
                    const noResult = document.createElement('div');
                    noResult.classList.add('no-fornecedores');
                    noResult.innerHTML = '<i class="fas fa-exclamation-circle"></i><p>Nenhum fornecedor encontrado</p>';
                    suggestionsContainer.appendChild(noResult);
                }
                
                suggestionsContainer.style.display = 'block';
            })
            .catch(error => {
                console.error('Erro ao buscar fornecedores:', error);
                suggestionsContainer.innerHTML = '<div class="loading-item" style="color: var(--danger-color);"><i class="fas fa-exclamation-triangle"></i> Erro ao buscar fornecedores</div>';
            });
    } else {
        suggestionsContainer.innerHTML = '';
        suggestionsContainer.style.display = 'none';
    }
}

// Função para formatar moeda
function formatarMoeda(valor) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    }).format(valor);
}

// Função para carregar categorias via AJAX de forma síncrona
async function carregarCategoriasSync() {
    try {
        const response = await fetch('consulta_produto.php?get_categorias_ajax=1');
        const data = await response.json();
        
        const select = document.getElementById('categoria');
        if (!select) return [];
        
        select.innerHTML = '<option value="">Selecione uma categoria</option>';
        
        if (data && Array.isArray(data)) {
            categorias = data; // Armazena na variável global
            data.forEach(categoria => {
                const option = document.createElement('option');
                option.value = categoria.id;
                option.textContent = categoria.nome;
                select.appendChild(option);
            });
        }
        
        return data;
    } catch (error) {
        console.error('Erro ao carregar categorias:', error);
        return [];
    }
}

// Função para carregar categorias via AJAX (versão assíncrona)
function carregarCategorias() {
    fetch('consulta_produto.php?get_categorias_ajax=1')
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('categoria');
            if (!select) return;
            
            select.innerHTML = '<option value="">Selecione uma categoria</option>';
            
            if (data && Array.isArray(data)) {
                categorias = data; // Armazena na variável global
                data.forEach(categoria => {
                    const option = document.createElement('option');
                    option.value = categoria.id;
                    option.textContent = categoria.nome;
                    select.appendChild(option);
                });
            }
        })
        .catch(error => console.error('Erro ao carregar categorias:', error));
}

// Função CORRIGIDA para definir categoria selecionada
function setarCategoriaSelecionada(data) {
    const selectCategoria = document.getElementById('categoria');
    if (!selectCategoria) return;
    
    console.log('Dados recebidos para categoria:', {
        categoria_id: data.categoria_id,
        categoria_nome: data.categoria_nome,
        categoria_display: data.categoria_display,
        categoria: data.categoria
    });
    
    // Primeiro, tenta por ID se disponível
    if (data.categoria_id && data.categoria_id !== '0' && data.categoria_id !== '') {
        selectCategoria.value = data.categoria_id;
        console.log('Categoria definida por ID:', data.categoria_id);
        return;
    }
    
    // Se não tem ID, busca pelo nome
    const nomeCategoria = data.categoria_display || data.categoria_nome || data.categoria;
    if (nomeCategoria && nomeCategoria !== 'Sem categoria') {
        for (let option of selectCategoria.options) {
            if (option.textContent.trim() === nomeCategoria.trim()) {
                selectCategoria.value = option.value;
                console.log('Categoria definida por nome:', nomeCategoria, 'ID:', option.value);
                return;
            }
        }
    }
    
    console.log('Categoria não encontrada, mantendo vazio');
    selectCategoria.value = '';
    
    // Debug: mostra qual categoria foi selecionada
    console.log('Categoria selecionada final:', {
        categoria_id: data.categoria_id,
        categoria_nome: data.categoria_nome,
        categoria: data.categoria,
        valor_selecionado: selectCategoria.value
    });
}

// Função para carregar dados do produto no formulário
function loadProductData(data) {
    const form = document.getElementById('productForm');
    if (!form) return;
    
    // Carrega dados básicos
    form.querySelector('#product_id').value = data.id || '';
    form.querySelector('#codigo').value = data.codigo || '';
    form.querySelector('#nome').value = data.nome || '';
    form.querySelector('#und').value = data.und || '';
    
    // Carrega fornecedor
    const fornecedorSearchInput = form.querySelector('#fornecedor_search');
    if (fornecedorSearchInput) {
        fornecedorSearchInput.value = data.fornecedor_nome || '';
    }
    form.querySelector('#fornecedor').value = data.fornecedor || '';
    
    // Carrega preços e valores
    form.querySelector('#preco_unitario').value = data.preco_unitario ? parseFloat(data.preco_unitario).toFixed(2) : '';
    form.querySelector('#estoque_minimo').value = data.estoque_minimo || '';
    form.querySelector('#observacao').value = data.observacao || '';

    // Carrega impostos
    form.querySelector('#icms').value = data.icms || '0';
    form.querySelector('#irpj').value = data.irpj || '0';
    form.querySelector('#cofins').value = data.cofins || '0';
    form.querySelector('#csll').value = data.csll || '0';
    form.querySelector('#pis_pasep').value = data.pis_pasep || '0';
    form.querySelector('#ipi').value = data.ipi || '0';
    form.querySelector('#margem_lucro').value = data.margem_lucro || '0';

    // Carrega valores calculados
    form.querySelector('#total_impostos_valor').value = data.total_impostos || '0';
    form.querySelector('#custo_total_valor').value = data.custo_total || '0';
    form.querySelector('#preco_venda_valor').value = data.preco_venda || '0';

    // Atualiza displays dos valores
    document.getElementById('totalImpostos').textContent = formatarMoeda(data.total_impostos || 0);
    document.getElementById('custoTotal').textContent = formatarMoeda(data.custo_total || 0);
    document.getElementById('precoVenda').textContent = formatarMoeda(data.preco_venda || 0);

    // Carrega categoria (tratamento especial)
    carregarCategoriasSync().then(() => {
        setarCategoriaSelecionada(data);
    });

    // Carrega imagem
    loadProductImage(data.imagem);
    
    // Carrega estatísticas
    if (data.stats) {
        updateProductStats(data.stats);
    }
    
    // Atualiza botões de ação
    updateActionButtons(data.id);
}

// Função para abrir o modal
function openModal(id) {
    currentProductId = id;
    const modal = document.getElementById('productModal');
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    editingMode = false;
    resetForm();

    fetch(`consulta_produto.php?get_produto_id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Erro: ' + data.error);
                return;
            }

            // Usa a nova função de carregamento de dados
            loadProductData(data);
        })
        .catch(error => {
            console.error('Erro ao carregar produto:', error);
            alert('Erro ao carregar dados do produto.');
        });
}

// Função para habilitar edição
function enableEditing() {
    editingMode = true;
    
    // Habilita o select de categoria
    const selectCategoria = document.getElementById('categoria');
    if (selectCategoria) {
        selectCategoria.disabled = false;
        selectCategoria.classList.add('editable');
    }
    
    const form = document.getElementById('productForm');
    const inputs = form.querySelectorAll('input:not([type="hidden"]):not([id="codigo"]), textarea, select');
    
    inputs.forEach(input => {
        input.readOnly = false;
        input.disabled = false;
        input.classList.add('editable');
    });

    // Habilita cálculo de impostos em tempo real
    document.querySelectorAll('.impostos-grid input, .resumo-grid input').forEach(input => {
        input.readOnly = false;
        input.disabled = false;
        input.addEventListener('input', calcularImpostos);
    });

    // Mostra opções de edição
    document.getElementById('imageUploadGroup').style.display = 'block';
    document.getElementById('saveBtn').style.display = 'inline-flex';
    document.getElementById('editBtn').style.display = 'none';
    
    // Habilita busca de fornecedores
    const fornecedorSearchInput = document.getElementById('fornecedor_search');
    if (fornecedorSearchInput) {
        fornecedorSearchInput.readOnly = false;
        fornecedorSearchInput.removeEventListener('input', handleFornecedorInput);
        fornecedorSearchInput.addEventListener('input', handleFornecedorInput);
    }
}

function handleFornecedorInput(e) {
    if (editingMode) {
        fetchFornecedorSuggestions(e.target);
    }
}

// Função para fechar modal
function closeModal() {
    const modal = document.getElementById('productModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    editingMode = false;
    
    const suggestionsContainer = document.getElementById('fornecedor_suggestions');
    if (suggestionsContainer) {
        suggestionsContainer.style.display = 'none';
    }
}

// Função para calcular impostos
function calcularImpostos() {
    const precoBase = parseFloat(document.getElementById('preco_unitario').value) || 0;
    
    const icms = parseFloat(document.getElementById('icms').value) || 0;
    const irpj = parseFloat(document.getElementById('irpj').value) || 0;
    const cofins = parseFloat(document.getElementById('cofins').value) || 0;
    const csll = parseFloat(document.getElementById('csll').value) || 0;
    const pisPasep = parseFloat(document.getElementById('pis_pasep').value) || 0;
    const ipi = parseFloat(document.getElementById('ipi').value) || 0;
    const margemLucro = parseFloat(document.getElementById('margem_lucro').value) || 0;

    const totalImpostos = precoBase * (icms + irpj + cofins + csll + pisPasep + ipi) / 100;
    const custoTotal = precoBase + totalImpostos;
    const precoVenda = custoTotal * (1 + (margemLucro / 100));

    document.getElementById('total_impostos_valor').value = totalImpostos.toFixed(2);
    document.getElementById('custo_total_valor').value = custoTotal.toFixed(2);
    document.getElementById('preco_venda_valor').value = precoVenda.toFixed(2);

    document.getElementById('totalImpostos').textContent = formatarMoeda(totalImpostos);
    document.getElementById('custoTotal').textContent = formatarMoeda(custoTotal);
    document.getElementById('precoVenda').textContent = formatarMoeda(precoVenda);
}

// Função para salvar alterações
function saveChanges() {
    const form = document.getElementById('productForm');
    const formData = new FormData(form);

    calcularImpostos();

    formData.append('total_impostos_valor', document.getElementById('total_impostos_valor').value);
    formData.append('custo_total_valor', document.getElementById('custo_total_valor').value);
    formData.append('preco_venda_valor', document.getElementById('preco_venda_valor').value);
    formData.append('update_product', '1');

    document.getElementById('saveBtn').disabled = true;
    document.getElementById('saveBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

    fetch('consulta_produto.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Produto atualizado com sucesso!');
            window.location.reload();
        } else {
            throw new Error(data.error || 'Erro ao atualizar produto');
        }
    })
    .catch(error => {
        alert('Erro ao salvar alterações: ' + error.message);
        document.getElementById('saveBtn').disabled = false;
        document.getElementById('saveBtn').innerHTML = '<i class="fas fa-save"></i> Salvar Alterações';
    });
}

// Função para resetar formulário
function resetForm() {
    const form = document.getElementById('productForm');
    form.reset();

    // Reseta select de categoria
    const selectCategoria = document.getElementById('categoria');
    if (selectCategoria) {
        selectCategoria.disabled = true;
        selectCategoria.classList.remove('editable');
        selectCategoria.value = '';
    }

    // Reseta todos os inputs
    const inputs = form.querySelectorAll('input:not([type="hidden"]), textarea, select');
    inputs.forEach(input => {
        input.readOnly = true;
        input.disabled = true;
        input.classList.remove('editable');
    });

    // Esconde elementos de edição
    document.getElementById('imageUploadGroup').style.display = 'none';
    document.getElementById('saveBtn').style.display = 'none';
    document.getElementById('editBtn').style.display = 'inline-flex';
    document.getElementById('imagePreview').innerHTML = '';
    document.getElementById('productStats').style.display = 'none';
    
    // Esconde sugestões de fornecedores
    const suggestionsContainer = document.getElementById('fornecedor_suggestions');
    if (suggestionsContainer) {
        suggestionsContainer.style.display = 'none';
    }
}

// Função para carregar imagem do produto
function loadProductImage(imagePath) {
    const container = document.getElementById('imagePreview');
    if (imagePath && imagePath.trim()) {
        container.innerHTML = `<img src="${imagePath}" alt="Imagem do produto" style="max-width: 300px; max-height: 300px; object-fit: contain;">`;
    } else {
        container.innerHTML = `<div class="no-image"><i class="fas fa-image"></i><p>Nenhuma imagem cadastrada</p></div>`;
    }
}

// Função para atualizar estatísticas do produto
function updateProductStats(stats) {
    const container = document.getElementById('productStats');
    container.innerHTML = `
        <div class="stat-card">
            <div class="stat-number">${stats.total_vendas || 0}</div>
            <div class="stat-label">Vendas</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">${stats.total_compras || 0}</div>
            <div class="stat-label">Compras</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">${(stats.qtd_comprada || 0) - (stats.qtd_vendida || 0)}</div>
            <div class="stat-label">Estoque Atual</div>
        </div>
    `;
    container.style.display = 'grid';
}

// Função para atualizar botões de ação
function updateActionButtons(productId) {
    const verComprasBtn = document.getElementById('verComprasBtn');
    const verVendasBtn = document.getElementById('verVendasBtn');
    const verEmpenhosBtn = document.getElementById('verEmpenhosBtn');
    
    if (verComprasBtn) verComprasBtn.href = `consultar_compras_produto.php?produto_id=${productId}`;
    if (verVendasBtn) verVendasBtn.href = `consultar_vendas_produto.php?produto_id=${productId}`;
    if (verEmpenhosBtn) verEmpenhosBtn.href = `consulta_empenhos_produto.php?produto_id=${productId}`;
}

// Funções para modal de exclusão
function openDeleteModal() {
    document.getElementById('deleteModal').style.display = 'block';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

function deleteProduct() {
    const productId = document.getElementById('product_id').value;
    if (productId) {
        window.location.href = `consulta_produto.php?delete_product_id=${productId}`;
    }
}

// Função para debug de categorias
function debugCategorias() {
    console.log('=== DEBUG CATEGORIAS ===');
    console.log('Categorias carregadas:', categorias);
    
    const selectCategoria = document.getElementById('categoria');
    if (selectCategoria) {
        console.log('Opções no select:');
        Array.from(selectCategoria.options).forEach((option, index) => {
            console.log(`  ${index}: value="${option.value}", text="${option.textContent}"`);
        });
        console.log('Valor selecionado:', selectCategoria.value);
    } else {
        console.log('Select de categoria não encontrado!');
    }
    console.log('=======================');
}

// Função para debug da estrutura da tabela produtos
function debugTabelaProdutos() {
    console.log('=== DEBUG ESTRUTURA PRODUTOS ===');
    fetch('consulta_produto.php?debug_estrutura=1')
        .then(response => response.text())
        .then(data => console.log('Estrutura da tabela produtos:', data))
        .catch(error => console.error('Erro ao verificar estrutura:', error));
}

// Event listeners principais
document.addEventListener('DOMContentLoaded', function() {
    console.log('Sistema de Consulta de Produtos carregado!');
    
    // Carrega categorias ao inicializar
    carregarCategorias();
    
    // Event listener para formulário
    const productForm = document.getElementById('productForm');
    if (productForm) {
        productForm.addEventListener('submit', function(e) {
            e.preventDefault();
            saveChanges();
        });
    }
    
    // Event listener para upload de imagem
    const inputImagem = document.getElementById('imagem');
    if (inputImagem) {
        inputImagem.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('imagePreview').innerHTML = `
                        <img src="${e.target.result}" alt="Preview" 
                             style="max-width: 300px; max-height: 300px; object-fit: contain;">
                    `;
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // Event listener para ESC fechar modais
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.style.display = 'none';
            });
            document.body.style.overflow = 'auto';
            
            const suggestionsContainer = document.getElementById('fornecedor_suggestions');
            if (suggestionsContainer) {
                suggestionsContainer.style.display = 'none';
            }
        }
    });
    
    // Event listener para fechar sugestões ao clicar fora
    document.addEventListener('click', function(e) {
        const fornecedorContainer = document.querySelector('.fornecedor-search-container');
        const suggestionsContainer = document.getElementById('fornecedor_suggestions');
        
        if (suggestionsContainer && fornecedorContainer && 
            !fornecedorContainer.contains(e.target)) {
            suggestionsContainer.style.display = 'none';
        }
    });

    // Adiciona comandos de debug (remover em produção)
    window.debugCategorias = debugCategorias;
    window.debugTabelaProdutos = debugTabelaProdutos;
    
    console.log('Event listeners configurados!');
});

// Funções para filtros e ordenação
function toggleFilters() {
    const container = document.getElementById('filtersContainer');
    const isHidden = container.style.display === 'none';
    
    if (isHidden) {
        container.style.display = 'block';
        container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } else {
        container.style.display = 'none';
    }
}

function clearFilters() {
    // Limpa todos os campos de filtro
    document.getElementById('filtro_nome').value = '';
    document.getElementById('filtro_codigo').value = '';
    document.getElementById('filtro_categoria').value = '';
    document.getElementById('filtro_fornecedor').value = '';
    document.getElementById('filtro_estoque').value = '';
    document.getElementById('filtro_preco_min').value = '';
    document.getElementById('filtro_preco_max').value = '';
    
    // Redireciona para a página sem filtros
    window.location.href = 'consulta_produto.php';
}

function changeOrder(campo) {
    const urlParams = new URLSearchParams(window.location.search);
    const currentCampo = urlParams.get('ordem_campo') || 'nome';
    const currentDirecao = urlParams.get('ordem_direcao') || 'ASC';
    
    // Se já está ordenando por este campo, inverte a direção
    if (currentCampo === campo) {
        urlParams.set('ordem_direcao', currentDirecao === 'ASC' ? 'DESC' : 'ASC');
    } else {
        // Se é um novo campo, usa ASC como padrão
        urlParams.set('ordem_campo', campo);
        urlParams.set('ordem_direcao', 'ASC');
    }
    
    // Remove a página para voltar à primeira
    urlParams.delete('page');
    
    // Redireciona com os novos parâmetros
    window.location.href = 'consulta_produto.php?' + urlParams.toString();
}

// Event listeners para filtros
document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit do formulário de filtros quando há mudança
    const filterInputs = document.querySelectorAll('#filtersForm input, #filtersForm select');
    filterInputs.forEach(input => {
        if (input.type === 'text' || input.type === 'number') {
            // Para campos de texto, aguarda o usuário parar de digitar
            let timeout;
            input.addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    if (this.value.length === 0 || this.value.length >= 2) {
                        // Auto-submit após 500ms de inatividade
                        // document.getElementById('filtersForm').submit();
                    }
                }, 500);
            });
        }
    });
    
    // Mostra filtros se houver filtros ativos
    const hasFilters = <?php echo json_encode(!empty($filtro_nome) || !empty($filtro_codigo) || !empty($filtro_categoria) || !empty($filtro_fornecedor) || !empty($filtro_estoque) || !empty($filtro_preco_min) || !empty($filtro_preco_max)); ?>;
    if (hasFilters) {
        document.getElementById('filtersContainer').style.display = 'block';
    }
    
    // Adiciona indicadores visuais para campos com filtros ativos
    const activeFilters = {
        'filtro_nome': <?php echo json_encode($filtro_nome); ?>,
        'filtro_codigo': <?php echo json_encode($filtro_codigo); ?>,
        'filtro_categoria': <?php echo json_encode($filtro_categoria); ?>,
        'filtro_fornecedor': <?php echo json_encode($filtro_fornecedor); ?>,
        'filtro_estoque': <?php echo json_encode($filtro_estoque); ?>,
        'filtro_preco_min': <?php echo json_encode($filtro_preco_min); ?>,
        'filtro_preco_max': <?php echo json_encode($filtro_preco_max); ?>
    };
    
    Object.keys(activeFilters).forEach(filterId => {
        if (activeFilters[filterId]) {
            const element = document.getElementById(filterId);
            if (element) {
                element.style.borderColor = 'var(--success-color)';
                element.style.backgroundColor = 'rgba(40, 167, 69, 0.05)';
            }
        }
    });
});

// Adiciona às funções globais
Object.assign(window.SistemaLicitacoes, {
    toggleFilters,
    clearFilters,
    changeOrder
});

// Expõe funções principais para uso global
window.SistemaLicitacoes = window.SistemaLicitacoes || {};
Object.assign(window.SistemaLicitacoes, {
    openModal,
    closeModal,
    enableEditing,
    saveChanges,
    deleteProduct,
    formatarMoeda,
    calcularImpostos,
    carregarCategorias,
    debugCategorias,
    debugTabelaProdutos
});

console.log('JavaScript de Consulta de Produtos carregado com sucesso!');
</script>

</body>
</html>