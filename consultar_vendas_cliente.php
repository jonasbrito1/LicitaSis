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

// Verifica se o usuário tem permissão para acessar vendas
$permissionManager->requirePermission('vendas', 'view');

// Registra acesso à página
logUserAction('READ', 'vendas_cliente_detalhes');

$error = "";
$success = "";

// Pegamos o uasg do cliente que foi passado via GET
$cliente_uasg = isset($_GET['cliente_uasg']) ? $_GET['cliente_uasg'] : '';

if (empty($cliente_uasg)) {
    header("Location: consultar_clientes.php");
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

// Buscamos as vendas associadas a esse cliente
$vendas = [];
$total_vendas = 0;
$valor_total_vendas = 0;
$lucro_total = 0;
$vendas_pendentes = 0;
$vendas_recebidas = 0;

try {
    // Primeiro, vamos verificar a estrutura das tabelas disponíveis
    $sql_check_tables = "SHOW TABLES LIKE '%transport%'";
    $stmt_check = $pdo->prepare($sql_check_tables);
    $stmt_check->execute();
    $transport_tables = $stmt_check->fetchAll(PDO::FETCH_COLUMN);
    
    // Verifica qual nome de tabela usar
    $transportadora_table = 'transportadora';
    if (in_array('transportadoras', $transport_tables)) {
        $transportadora_table = 'transportadoras';
    }
    
    // Também vamos verificar a estrutura da tabela vendas
    $sql_vendas_structure = "DESCRIBE vendas";
    $stmt_vendas_structure = $pdo->prepare($sql_vendas_structure);
    $stmt_vendas_structure->execute();
    $vendas_columns = $stmt_vendas_structure->fetchAll(PDO::FETCH_ASSOC);
    
    // Verifica a estrutura da tabela venda_produtos se ela existir
    $venda_produtos_columns = [];
    $has_valor_custo = false;
    try {
        $sql_vp_structure = "DESCRIBE venda_produtos";
        $stmt_vp_structure = $pdo->prepare($sql_vp_structure);
        $stmt_vp_structure->execute();
        $vp_columns_info = $stmt_vp_structure->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($vp_columns_info as $column) {
            $venda_produtos_columns[] = $column['Field'];
            if (strtolower($column['Field']) == 'valor_custo') {
                $has_valor_custo = true;
            }
        }
    } catch (PDOException $e) {
        // Tabela venda_produtos não existe
        $venda_produtos_columns = [];
    }
    
    // Verifica a estrutura da tabela produtos se ela existir
    $produtos_columns = [];
    $has_descricao = false;
    $has_codigo = false;
    try {
        $sql_produtos_structure = "DESCRIBE produtos";
        $stmt_produtos_structure = $pdo->prepare($sql_produtos_structure);
        $stmt_produtos_structure->execute();
        $produtos_columns_info = $stmt_produtos_structure->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($produtos_columns_info as $column) {
            $produtos_columns[] = $column['Field'];
            if (strtolower($column['Field']) == 'descricao') {
                $has_descricao = true;
            }
            if (strtolower($column['Field']) == 'codigo') {
                $has_codigo = true;
            }
        }
    } catch (PDOException $e) {
        // Tabela produtos não existe
        $produtos_columns = [];
    }
    
    // Mapeia os nomes das colunas que podem variar
    $data_column = 'data_venda';
    $numero_column = 'numero_venda';
    $nf_column = 'numero_nf';
    $transportadora_column = 'transportadora_id';
    
    foreach ($vendas_columns as $column) {
        $col_name = strtolower($column['Field']);
        
        if (in_array($col_name, ['data', 'data_venda', 'date', 'created_at'])) {
            $data_column = $column['Field'];
        }
        if (in_array($col_name, ['numero', 'numero_venda', 'number'])) {
            $numero_column = $column['Field'];
        }
        if (in_array($col_name, ['numero_nf', 'nf', 'nota_fiscal', 'numero_nota_fiscal'])) {
            $nf_column = $column['Field'];
        }
        if (in_array($col_name, ['transportadora_id', 'transportadora', 'transport_id'])) {
            $transportadora_column = $column['Field'];
        }
    }
    
    // Verifica se a coluna numero_nf existe
    $has_nf_column = false;
    foreach ($vendas_columns as $column) {
        if (in_array(strtolower($column['Field']), ['numero_nf', 'nf', 'nota_fiscal', 'numero_nota_fiscal'])) {
            $has_nf_column = true;
            break;
        }
    }
    
    // Se não existir a coluna NF, usa um valor vazio
    $nf_select = $has_nf_column ? "v.{$nf_column}" : "''";
    
    // Monta a query baseada nas colunas disponíveis
    $valor_custo_field = $has_valor_custo ? 'vp.valor_custo' : '0';
    $produto_codigo_field = $has_codigo ? 'p.codigo' : "''";
    $produto_descricao_field = $has_descricao ? 'p.descricao' : "''";
    $lucro_calculation = "vp.valor_total - ({$valor_custo_field} * vp.quantidade)";
    $percentual_calculation = "CASE WHEN vp.valor_total > 0 THEN (({$lucro_calculation}) / vp.valor_total * 100) ELSE 0 END";
    
    $sql_vendas = "SELECT 
                    v.id AS venda_id,
                    v.{$numero_column} AS numero_venda,
                    v.{$data_column} AS data_venda,
                    {$nf_select} AS numero_nf,
                    v.valor_total,
                    COALESCE(v.status_pagamento, 'Pendente') AS status_pagamento,
                    COALESCE(v.observacao, '') AS observacao,
                    v.{$transportadora_column} AS transportadora_id,
                    c.nome_orgaos AS cliente_nome,
                    c.uasg AS cliente_uasg,
                    COALESCE(c.cnpj, '') AS cliente_cnpj,
                    COALESCE(t.nome, '') AS transportadora_nome,
                    COALESCE(t.cnpj, '') AS transportadora_cnpj,
                    COALESCE(t.telefone, '') AS transportadora_telefone,
                    COALESCE(vp.produto_id, 0) AS produto_id,
                    COALESCE(vp.quantidade, 1) AS quantidade,
                    COALESCE(vp.valor_unitario, 0) AS valor_unitario,
                    COALESCE(vp.valor_total, 0) AS valor_produto,
                    COALESCE({$valor_custo_field}, 0) AS valor_custo,
                    COALESCE(p.nome, 'Produto não identificado') AS produto_nome,
                    COALESCE({$produto_codigo_field}, '') AS produto_codigo,
                    COALESCE({$produto_descricao_field}, '') AS produto_descricao,
                    ({$lucro_calculation}) AS lucro_produto,
                    ({$percentual_calculation}) AS percentual_lucro
                   FROM vendas v
                   INNER JOIN clientes c ON v.cliente_uasg = c.uasg
                   LEFT JOIN {$transportadora_table} t ON v.{$transportadora_column} = t.id
                   LEFT JOIN venda_produtos vp ON v.id = vp.venda_id
                   LEFT JOIN produtos p ON vp.produto_id = p.id
                   WHERE v.cliente_uasg = :uasg
                   ORDER BY v.{$data_column} DESC, v.id DESC";
    
    $stmt_vendas = $pdo->prepare($sql_vendas);
    $stmt_vendas->bindParam(':uasg', $cliente_uasg);
    $stmt_vendas->execute();
    
    $vendas = $stmt_vendas->fetchAll(PDO::FETCH_ASSOC);
    $total_vendas = count($vendas);
    
    // Calcula estatísticas
    foreach ($vendas as $venda) {
        $valor_total_vendas += floatval($venda['valor_total'] ?? 0);
        $lucro_total += floatval($venda['lucro_produto'] ?? 0);
        
        if (($venda['status_pagamento'] ?? '') == 'Recebido') {
            $vendas_recebidas++;
        } else {
            $vendas_pendentes++;
        }
    }
    
} catch (PDOException $e) {
    $error = "Erro ao buscar vendas: " . $e->getMessage();
    error_log("Erro ao buscar vendas: " . $e->getMessage());
    
    // Tenta uma consulta mais simples como fallback
    try {
        // Verifica se existe a tabela venda_produtos
        $sql_check_vp = "SHOW TABLES LIKE 'venda_produtos'";
        $stmt_check_vp = $pdo->prepare($sql_check_vp);
        $stmt_check_vp->execute();
        $vp_exists = $stmt_check_vp->rowCount() > 0;
        
        if ($vp_exists) {
            // Verifica novamente se valor_custo existe na tabela venda_produtos
            $has_valor_custo_fallback = false;
            try {
                $sql_check_custo = "SHOW COLUMNS FROM venda_produtos LIKE 'valor_custo'";
                $stmt_check_custo = $pdo->prepare($sql_check_custo);
                $stmt_check_custo->execute();
                $has_valor_custo_fallback = $stmt_check_custo->rowCount() > 0;
            } catch (PDOException $e) {
                $has_valor_custo_fallback = false;
            }
            
            // Verifica se numero_nf existe na tabela vendas
            $has_nf_fallback = false;
            try {
                $sql_check_nf = "SHOW COLUMNS FROM vendas LIKE '%nf%'";
                $stmt_check_nf = $pdo->prepare($sql_check_nf);
                $stmt_check_nf->execute();
                $has_nf_fallback = $stmt_check_nf->rowCount() > 0;
            } catch (PDOException $e) {
                $has_nf_fallback = false;
            }
            
            $nf_select_fallback = $has_nf_fallback ? "v.numero_nf" : "''";
            
            // Verifica colunas da tabela produtos para o fallback
            $has_codigo_fallback = false;
            $has_descricao_fallback = false;
            try {
                $sql_check_produtos = "SHOW COLUMNS FROM produtos";
                $stmt_check_produtos = $pdo->prepare($sql_check_produtos);
                $stmt_check_produtos->execute();
                $produtos_cols = $stmt_check_produtos->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($produtos_cols as $col) {
                    if (strtolower($col['Field']) == 'codigo') {
                        $has_codigo_fallback = true;
                    }
                    if (strtolower($col['Field']) == 'descricao') {
                        $has_descricao_fallback = true;
                    }
                }
            } catch (PDOException $e) {
                $has_codigo_fallback = false;
                $has_descricao_fallback = false;
            }
            
            $valor_custo_field_fallback = $has_valor_custo_fallback ? 'vp.valor_custo' : '0';
            $produto_codigo_field_fallback = $has_codigo_fallback ? 'p.codigo' : "''";
            $produto_descricao_field_fallback = $has_descricao_fallback ? 'p.descricao' : "''";
            $lucro_calculation_fallback = "COALESCE(vp.valor_total, 0) - ({$valor_custo_field_fallback} * COALESCE(vp.quantidade, 1))";
            $percentual_calculation_fallback = "CASE WHEN COALESCE(vp.valor_total, 0) > 0 THEN (({$lucro_calculation_fallback}) / COALESCE(vp.valor_total, 1) * 100) ELSE 0 END";
            
            // Consulta simplificada sem transportadora
            $sql_vendas_simple = "SELECT 
                            v.id AS venda_id,
                            {$nf_select_fallback} AS numero_nf,
                            COALESCE(v.valor_total, 0) AS valor_total,
                            COALESCE(v.status_pagamento, 'Pendente') AS status_pagamento,
                            COALESCE(v.observacao, '') AS observacao,
                            c.nome_orgaos AS cliente_nome,
                            c.uasg AS cliente_uasg,
                            COALESCE(c.cnpj, '') AS cliente_cnpj,
                            COALESCE(vp.produto_id, 0) AS produto_id,
                            COALESCE(vp.quantidade, 1) AS quantidade,
                            COALESCE(vp.valor_unitario, 0) AS valor_unitario,
                            COALESCE(vp.valor_total, 0) AS valor_produto,
                            COALESCE({$valor_custo_field_fallback}, 0) AS valor_custo,
                            COALESCE(p.nome, 'Produto não identificado') AS produto_nome,
                            COALESCE({$produto_codigo_field_fallback}, '') AS produto_codigo,
                            COALESCE({$produto_descricao_field_fallback}, '') AS produto_descricao,
                            ({$lucro_calculation_fallback}) AS lucro_produto,
                            ({$percentual_calculation_fallback}) AS percentual_lucro,
                            COALESCE(v.created_at, NOW()) AS data_venda,
                            '' AS transportadora_nome,
                            '' AS transportadora_cnpj,
                            '' AS transportadora_telefone
                           FROM vendas v
                           INNER JOIN clientes c ON v.cliente_uasg = c.uasg
                           LEFT JOIN venda_produtos vp ON v.id = vp.venda_id
                           LEFT JOIN produtos p ON vp.produto_id = p.id
                           WHERE v.cliente_uasg = :uasg
                           ORDER BY v.id DESC";
            
            $stmt_vendas_simple = $pdo->prepare($sql_vendas_simple);
            $stmt_vendas_simple->bindParam(':uasg', $cliente_uasg);
            $stmt_vendas_simple->execute();
            
            $vendas = $stmt_vendas_simple->fetchAll(PDO::FETCH_ASSOC);
            $total_vendas = count($vendas);
            
            // Calcula estatísticas
            foreach ($vendas as $venda) {
                $valor_total_vendas += floatval($venda['valor_total'] ?? 0);
                $lucro_total += floatval($venda['lucro_produto'] ?? 0);
                
                if (($venda['status_pagamento'] ?? '') == 'Recebido') {
                    $vendas_recebidas++;
                } else {
                    $vendas_pendentes++;
                }
            }
            
            $error = ""; // Remove o erro se a consulta simplificada funcionou
        } else {
            // Se não existe venda_produtos, faz uma consulta só com vendas
            $has_nf_only = false;
            try {
                $sql_check_nf_only = "SHOW COLUMNS FROM vendas LIKE '%nf%'";
                $stmt_check_nf_only = $pdo->prepare($sql_check_nf_only);
                $stmt_check_nf_only->execute();
                $has_nf_only = $stmt_check_nf_only->rowCount() > 0;
            } catch (PDOException $e) {
                $has_nf_only = false;
            }
            
            $nf_select_only = $has_nf_only ? "v.numero_nf" : "''";
            
            $sql_vendas_only = "SELECT 
                            v.id AS venda_id,
                            {$nf_select_only} AS numero_nf,
                            COALESCE(v.valor_total, 0) AS valor_total,
                            COALESCE(v.status_pagamento, 'Pendente') AS status_pagamento,
                            COALESCE(v.observacao, '') AS observacao,
                            c.nome_orgaos AS cliente_nome,
                            c.uasg AS cliente_uasg,
                            COALESCE(c.cnpj, '') AS cliente_cnpj,
                            0 AS produto_id,
                            1 AS quantidade,
                            COALESCE(v.valor_total, 0) AS valor_unitario,
                            COALESCE(v.valor_total, 0) AS valor_produto,
                            0 AS valor_custo,
                            'Venda sem detalhamento de produto' AS produto_nome,
                            '' AS produto_codigo,
                            '' AS produto_descricao,
                            COALESCE(v.valor_total, 0) AS lucro_produto,
                            100 AS percentual_lucro,
                            COALESCE(v.created_at, NOW()) AS data_venda,
                            '' AS transportadora_nome,
                            '' AS transportadora_cnpj,
                            '' AS transportadora_telefone
                           FROM vendas v
                           INNER JOIN clientes c ON v.cliente_uasg = c.uasg
                           WHERE v.cliente_uasg = :uasg
                           ORDER BY v.id DESC";
            
            $stmt_vendas_only = $pdo->prepare($sql_vendas_only);
            $stmt_vendas_only->bindParam(':uasg', $cliente_uasg);
            $stmt_vendas_only->execute();
            
            $vendas = $stmt_vendas_only->fetchAll(PDO::FETCH_ASSOC);
            $total_vendas = count($vendas);
            
            // Calcula estatísticas
            foreach ($vendas as $venda) {
                $valor_total_vendas += floatval($venda['valor_total'] ?? 0);
                $lucro_total += floatval($venda['lucro_produto'] ?? 0);
                
                if (($venda['status_pagamento'] ?? '') == 'Recebido') {
                    $vendas_recebidas++;
                } else {
                    $vendas_pendentes++;
                }
            }
            
            $error = ""; // Remove o erro se a consulta funcionou
        }
    } catch (PDOException $e2) {
        $error .= " | Erro na consulta simplificada: " . $e2->getMessage();
        error_log("Erro na consulta simplificada: " . $e2->getMessage());
    }
}

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Vendas do Cliente - LicitaSis", "vendas");
?>

<style>
    /* Reset e variáveis CSS - mesmo padrão do cliente empenhos */
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

    /* Cards de informações do cliente */
    .client-info-section {
        margin-bottom: 2.5rem;
    }

    .client-info-card {
        background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 2rem;
        position: relative;
        overflow: hidden;
        transition: var(--transition);
    }

    .client-info-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
    }

    .client-info-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-hover);
    }

    .client-info-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-color);
    }

    .client-info-header i {
        font-size: 1.5rem;
        color: var(--secondary-color);
    }

    .client-info-header h3 {
        color: var(--primary-color);
        font-size: 1.4rem;
        margin: 0;
        font-weight: 600;
    }

    .client-details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5rem;
    }

    .detail-item {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .detail-label {
        color: var(--medium-gray);
        font-size: 0.9rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .detail-value {
        color: var(--dark-gray);
        font-size: 1.1rem;
        font-weight: 500;
        padding: 0.75rem;
        background: var(--light-gray);
        border-radius: var(--radius-sm);
        border-left: 3px solid var(--secondary-color);
    }

    /* Estatísticas das vendas */
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

    /* Seção de vendas */
    .vendas-section {
        margin-bottom: 2.5rem;
    }

    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid var(--border-color);
    }

    .section-title {
        display: flex;
        align-items: center;
        gap: 1rem;
        color: var(--primary-color);
        font-size: 1.5rem;
        font-weight: 600;
        margin: 0;
    }

    .section-title i {
        color: var(--secondary-color);
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

    /* Tabela de vendas - Versão simplificada */
    .table-container {
        background: white;
        border-radius: var(--radius);
        overflow: hidden;
        box-shadow: var(--shadow);
        border: 1px solid var(--border-color);
    }

    .table-responsive {
        overflow-x: auto;
    }

    table {width: 100%;
        border-collapse: collapse;
        font-size: 0.95rem;
    }

    table th {
        background: linear-gradient(135deg, var(--secondary-color), var(--secondary-dark));
        color: white;
        padding: 1rem;
        text-align: left;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border: none;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    table td {
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
        color: var(--dark-gray);
        transition: var(--transition);
    }

    table tbody tr {
        transition: var(--transition);
    }

    table tbody tr:hover {
        background-color: #f8f9fa;
        transform: scale(1.01);
    }

    table tbody tr:last-child td {
        border-bottom: none;
    }

    /* NF clicável */
    .nf-clickable {
        cursor: pointer;
        color: var(--primary-color);
        font-weight: 700;
        font-size: 1.1rem;
        text-decoration: none;
        transition: var(--transition);
        display: inline-block;
    }

    .nf-clickable:hover {
        color: var(--primary-dark);
        text-decoration: underline;
        transform: scale(1.05);
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

    .status-badge.recebido {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .status-badge.pendente {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffeaa7;
    }

    /* Percentual de lucro */
    .lucro-positivo {
        color: var(--success-color);
        font-weight: 700;
    }

    .lucro-negativo {
        color: var(--danger-color);
        font-weight: 700;
    }

    .lucro-neutro {
        color: var(--medium-gray);
        font-weight: 600;
    }

    /* Modal de detalhes da venda */
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

    .modal-detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .modal-detail-item {
        background: var(--light-gray);
        padding: 1rem;
        border-radius: var(--radius-sm);
        border-left: 3px solid var(--secondary-color);
    }

    .modal-detail-label {
        color: var(--medium-gray);
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .modal-detail-value {
        color: var(--dark-gray);
        font-size: 1.1rem;
        font-weight: 500;
    }

    /* Formulário de edição no modal */
    .edit-form {
        display: none;
        background: white;
        border-radius: var(--radius-sm);
        padding: 1.5rem;
        border: 2px solid var(--secondary-color);
        margin-top: 1rem;
    }

    .edit-form.active {
        display: block;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .form-group {
        display: flex;
        flex-direction: column;
    }

    .form-group label {
        color: var(--medium-gray);
        font-size: 0.9rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        padding: 0.75rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-sm);
        font-size: 1rem;
        transition: var(--transition);
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
    }

    .form-group textarea {
        resize: vertical;
        min-height: 80px;
    }

    /* Botões do modal */
    .modal-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 2rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border-color);
        gap: 1rem;
        flex-wrap: wrap;
    }

    .modal-actions-left {
        display: flex;
        gap: 0.75rem;
    }

    .modal-actions-right {
        display: flex;
        gap: 0.75rem;
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

    /* Responsividade */
    @media (max-width: 1200px) {
        .container {
            margin: 2rem 1.5rem;
            padding: 2rem;
        }

        .client-details-grid {
            grid-template-columns: 1fr;
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

        .section-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 1rem;
        }

        .table-container {
            font-size: 0.85rem;
        }

        table th,
        table td {
            padding: 0.75rem 0.5rem;
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

        .modal-detail-grid,
        .form-grid {
            grid-template-columns: 1fr;
        }

        .modal-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .modal-actions-left,
        .modal-actions-right {
            justify-content: center;
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

        .client-info-card,
        .stat-card {
            padding: 1.25rem;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        table th,
        table td {
            padding: 0.5rem;
            font-size: 0.8rem;
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
</style>

<div class="container">
    <!-- Cabeçalho da página -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-shopping-cart"></i>
            <?php echo safe_htmlspecialchars($cliente['nome_orgaos'] ?? 'Cliente não encontrado'); ?>
        </h1>
        <p class="page-subtitle">Vendas realizadas para este cliente</p>
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
    <!-- Informações do cliente -->
    <div class="client-info-section">
        
    </div>

    <!-- Estatísticas das vendas -->
    <div class="stats-section">
        <div class="stats-grid">
            <div class="stat-card">
                <i class="stat-icon fas fa-shopping-cart"></i>
                <div class="stat-number" id="totalVendas"><?php echo $total_vendas; ?></div>
                <div class="stat-label">Total de Vendas</div>
            </div>
            
            <div class="stat-card">
                <i class="stat-icon fas fa-money-bill-wave"></i>
                <div class="stat-number">R$ <?php echo number_format($valor_total_vendas, 2, ',', '.'); ?></div>
                <div class="stat-label">Valor Total</div>
            </div>
            
            <div class="stat-card">
                <i class="stat-icon fas fa-chart-line"></i>
                <div class="stat-number">R$ <?php echo number_format($lucro_total, 2, ',', '.'); ?></div>
                <div class="stat-label">Lucro Total</div>
            </div>
            
            <div class="stat-card">
                <i class="stat-icon fas fa-clock"></i>
                <div class="stat-number"><?php echo $vendas_pendentes; ?></div>
                <div class="stat-label">Vendas Pendentes</div>
            </div>
            
            <div class="stat-card">
                <i class="stat-icon fas fa-check-circle"></i>
                <div class="stat-number"><?php echo $vendas_recebidas; ?></div>
                <div class="stat-label">Vendas Recebidas</div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="filters-container">
        <div class="filter-group">
            <label for="filterStatus">Filtrar por Status:</label>
            <select id="filterStatus" onchange="filterTable()">
                <option value="">Todos</option>
                <option value="Recebido">Recebido</option>
                <option value="Pendente">Pendente</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="filterDate">Filtrar por Data:</label>
            <input type="month" id="filterDate" onchange="filterTable()">
        </div>
        <div class="filter-group">
            <label for="searchInput">Buscar:</label>
            <input type="text" id="searchInput" placeholder="Buscar por NF, cliente, valor..." onkeyup="filterTable()">
        </div>
    </div>

    <!-- Lista de vendas -->
    <div class="vendas-section">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-list-alt"></i>
                Histórico de Vendas
            </h2>
        </div>

        <?php if (count($vendas) > 0): ?>
            <div class="table-container">
                <div class="table-responsive">
                    <table id="vendasTable">
                        <thead>
                            <tr>
                                <th><i class="fas fa-file-invoice"></i> NF</th>
                                <th><i class="fas fa-dollar-sign"></i> Valor Total</th>
                                <th><i class="fas fa-percentage"></i> % Lucro</th>
                                <th><i class="fas fa-calendar"></i> Data</th>
                                <th><i class="fas fa-info-circle"></i> Status</th>
                                <th><i class="fas fa-cog"></i> Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vendas as $venda): ?>
                                <tr>
                                    <td>
                                        <span class="nf-clickable" onclick="openVendaModal(<?php echo $venda['venda_id']; ?>)">
                                            <?php 
                                            $nf = safe_htmlspecialchars($venda['numero_nf'] ?? '');
                                            echo $nf ?: 'Sem NF'; 
                                            ?>
                                        </span>
                                        <br>
                                        <small style="color: var(--medium-gray);">
                                            <?php echo safe_htmlspecialchars($venda['cliente_nome']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span style="color: var(--success-color); font-weight: 600;">
                                            R$ <?php echo number_format(floatval($venda['valor_total']), 2, ',', '.'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $percentual = floatval($venda['percentual_lucro']);
                                        $classe_lucro = 'lucro-neutro';
                                        if ($percentual > 0) {
                                            $classe_lucro = 'lucro-positivo';
                                        } elseif ($percentual < 0) {
                                            $classe_lucro = 'lucro-negativo';
                                        }
                                        ?>
                                        <span class="<?php echo $classe_lucro; ?>">
                                            <?php echo number_format($percentual, 2, ',', '.'); ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime($venda['data_venda'])); ?>
                                    </td>
                                    <td>
                                        <?php if ($venda['status_pagamento'] == 'Recebido'): ?>
                                            <span class="status-badge recebido">
                                                <i class="fas fa-check"></i> Recebido
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge pendente">
                                                <i class="fas fa-clock"></i> Pendente
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button onclick="openVendaModal(<?php echo $venda['venda_id']; ?>)" 
                                               class="btn btn-sm btn-info" title="Ver detalhes">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="table-container">
                <div class="empty-state">
                    <i class="fas fa-shopping-cart"></i>
                    <h3>Nenhuma venda encontrada</h3>
                    <p>Este cliente ainda não possui vendas cadastradas no sistema.</p>
                    <?php if ($permissionManager->hasPagePermission('vendas', 'create')): ?>
                        <a href="cadastro_vendas.php?cliente_uasg=<?php echo urlencode($cliente_uasg); ?>" 
                           class="btn btn-primary">
                            <i class="fas fa-plus"></i> Cadastrar Primeira Venda
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
        
        <?php if (!$error && $cliente && $permissionManager->hasPagePermission('vendas', 'create')): ?>
            <a href="cadastro_vendas.php?cliente_uasg=<?php echo urlencode($cliente_uasg); ?>" 
               class="btn btn-success">
                <i class="fas fa-plus"></i> Nova Venda
            </a>
        <?php endif; ?>
        
        <?php if (count($vendas) > 0): ?>
            <button onclick="window.print()" class="btn btn-info">
                <i class="fas fa-print"></i> Imprimir
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de detalhes da venda -->
<div id="vendaModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-shopping-cart"></i> Detalhes da Venda</h3>
            <span class="close" onclick="closeVendaModal()">&times;</span>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- Conteúdo será carregado via JavaScript -->
        </div>
    </div>
</div>

<?php
// Finaliza a página com footer e scripts
renderFooter();
renderScripts();
?>

<script>
    // Dados das vendas para uso no JavaScript
const vendasData = <?php echo json_encode($vendas); ?>;
let currentVendaId = null;
let editMode = false;

// Função para abrir o modal da venda
function openVendaModal(vendaId) {
    const venda = vendasData.find(v => v.venda_id == vendaId);
    if (!venda) {
        showNotification('Venda não encontrada', 'error');
        return;
    }

    currentVendaId = vendaId;
    editMode = false;

    const modalBody = document.getElementById('modalBody');
    
    // Calcula valores para exibição
    const lucroValor = parseFloat(venda.lucro_produto) || 0;
    const percentualLucro = parseFloat(venda.percentual_lucro) || 0;

    // Monta a estrutura do modal
    modalBody.innerHTML = `
        <div id="viewMode">
            ${buildVendaDetails(venda, lucroValor, percentualLucro)}
        </div>
        <div id="editMode" class="edit-form">
            ${buildEditForm(venda)}
        </div>
        ${buildModalActions()}
    `;

    // Exibe o modal
    document.getElementById('vendaModal').style.display = 'block';
}
// Função para fechar o modal
function closeVendaModal() {
    document.getElementById('vendaModal').style.display = 'none';
}

// Função para construir os detalhes da venda
function buildVendaDetails(venda, lucroValor, percentualLucro) {
    return `
        <div class="modal-detail-grid">
            <div class="modal-detail-item">
                <div class="modal-detail-label">Número NF</div>
                <div class="modal-detail-value">${venda.numero_nf || 'Sem NF'}</div>
            </div>
            <div class="modal-detail-item">
                <div class="modal-detail-label">Data da Venda</div>
                <div class="modal-detail-value">${formatDate(venda.data_venda)}</div>
            </div>
            <div class="modal-detail-item">
                <div class="modal-detail-label">Valor Total</div>
                <div class="modal-detail-value">R$ ${formatMoney(venda.valor_total)}</div>
            </div>
            <div class="modal-detail-item">
                <div class="modal-detail-label">Lucro</div>
                <div class="modal-detail-value">
                    <span class="${lucroValor > 0 ? 'lucro-positivo' : 'lucro-negativo'}">
                        R$ ${formatMoney(lucroValor)} (${formatNumber(percentualLucro)}%)
                    </span>
                </div>
            </div>
            <div class="modal-detail-item">
                <div class="modal-detail-label">Status</div>
                <div class="modal-detail-value">
                    <span class="status-badge ${venda.status_pagamento.toLowerCase()}">
                        <i class="fas fa-${venda.status_pagamento === 'Recebido' ? 'check' : 'clock'}"></i>
                        ${venda.status_pagamento}
                    </span>
                </div>
            </div>
            ${venda.transportadora_nome ? `
                <div class="modal-detail-item">
                    <div class="modal-detail-label">Transportadora</div>
                    <div class="modal-detail-value">${venda.transportadora_nome}</div>
                </div>
            ` : ''}
        </div>

        <div class="produtos-section">
            <h4><i class="fas fa-box"></i> Produtos</h4>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <th>Quantidade</th>
                            <th>Valor Unit.</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>${venda.produto_nome}</td>
                            <td>${venda.quantidade}</td>
                            <td>R$ ${formatMoney(venda.valor_unitario)}</td>
                            <td>R$ ${formatMoney(venda.valor_produto)}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        ${venda.observacao ? `
            <div class="observacao-section">
                <h4><i class="fas fa-comment-alt"></i> Observações</h4>
                <p>${venda.observacao}</p>
            </div>
        ` : ''}
    `;
}

// Função para construir o formulário de edição
function buildEditForm(venda) {
    return `
        <form id="editVendaForm">
            <div class="form-grid">
                <div class="form-group">
                    <label>Número NF</label>
                    <input type="text" name="numero_nf" value="${venda.numero_nf || ''}" />
                </div>
                <div class="form-group">
                    <label>Status do Pagamento</label>
                    <select name="status_pagamento">
                        <option value="Pendente" ${venda.status_pagamento === 'Pendente' ? 'selected' : ''}>Pendente</option>
                        <option value="Recebido" ${venda.status_pagamento === 'Recebido' ? 'selected' : ''}>Recebido</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Observação</label>
                    <textarea name="observacao">${venda.observacao || ''}</textarea>
                </div>
            </div>
        </form>
    `;
}

// Função para construir as ações do modal
function buildModalActions() {
    return `
        <div class="modal-actions">
            <div class="modal-actions-left">
                <button onclick="toggleEditMode()" class="btn btn-warning">
                    <i class="fas fa-edit"></i> ${editMode ? 'Cancelar Edição' : 'Editar'}
                </button>
            </div>
            <div class="modal-actions-right">
                ${editMode ? `
                    <button onclick="saveVenda()" class="btn btn-success">
                        <i class="fas fa-save"></i> Salvar
                    </button>
                ` : ''}
                <button onclick="closeVendaModal()" class="btn btn-danger">
                    <i class="fas fa-times"></i> Fechar
                </button>
            </div>
        </div>
    `;
}

// Função para salvar alterações da venda
async function saveVenda() {
    const form = document.getElementById('editVendaForm');
    const formData = new FormData(form);
    formData.append('venda_id', currentVendaId);

    try {
        // Mostra indicador de loading
        const saveButton = document.querySelector('.btn-success');
        if (saveButton) {
            saveButton.disabled = true;
            saveButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
        }

        // Alterado para usar update_venda.php
        const response = await fetch('update_venda.php', {
            method: 'POST',
            body: formData
        });

        // Debug da resposta
        console.log('Resposta bruta:', await response.clone().text());

        const result = await response.json();
        console.log('Resultado:', result);

        if (result.success) {
            showNotification('Venda atualizada com sucesso!', 'success');
            
            // Atualiza os dados da venda no array vendasData
            const vendaIndex = vendasData.findIndex(v => v.venda_id == currentVendaId);
            if (vendaIndex !== -1) {
                vendasData[vendaIndex] = {
                    ...vendasData[vendaIndex],
                    ...result.data
                };
            }

            // Atualiza a visualização sem recarregar a página
            editMode = false;
            openVendaModal(currentVendaId);
            
            // Atualiza a linha na tabela
            updateTableRow(currentVendaId, result.data);
        } else {
            throw new Error(result.message || 'Erro ao atualizar venda');
        }
    } catch (error) {
        showNotification(error.message, 'error');
        console.error('Erro:', error);
    } finally {
        // Restaura o botão
        const saveButton = document.querySelector('.btn-success');
        if (saveButton) {
            saveButton.disabled = false;
            saveButton.innerHTML = '<i class="fas fa-save"></i> Salvar';
        }
    }
}

// Funções auxiliares de formatação
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('pt-BR');
}

function formatMoney(value) {
    return parseFloat(value).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatNumber(value) {
    return parseFloat(value).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// Função para alternar modo de edição
function toggleEditMode() {
    editMode = !editMode;
    document.getElementById('viewMode').style.display = editMode ? 'none' : 'block';
    document.getElementById('editMode').style.display = editMode ? 'block' : 'none';
    document.querySelector('.modal-actions').outerHTML = buildModalActions();
}


// Função auxiliar para atualizar a linha na tabela
function updateTableRow(vendaId, newData) {
    const row = document.querySelector(`tr[data-venda-id="${vendaId}"]`);
    if (row) {
        // Atualiza NF
        const nfCell = row.querySelector('.nf-clickable');
        if (nfCell) {
            nfCell.textContent = newData.numero_nf || 'Sem NF';
        }

        // Atualiza Status
        const statusCell = row.querySelector('td:nth-child(5)');
        if (statusCell) {
            statusCell.innerHTML = `
                <span class="status-badge ${newData.status_pagamento.toLowerCase()}">
                    <i class="fas fa-${newData.status_pagamento === 'Recebido' ? 'check' : 'clock'}"></i>
                    ${newData.status_pagamento}
                </span>
            `;
        }
    }
}

// Função para notificações
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        ${message}
        <button onclick="this.parentElement.remove()">&times;</button>
    `;
    
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 5000);
}

// Inicialização
document.addEventListener('DOMContentLoaded', function() {
    // Anima os números das estatísticas
    const statNumbers = document.querySelectorAll('.stat-number');
    statNumbers.forEach(element => {
        if (element.textContent.includes('R$')) return;
        const finalNumber = parseInt(element.textContent);
        if (!isNaN(finalNumber)) {
            animateNumber(element, finalNumber);
        }
    });

    // Adiciona event listeners globais
    window.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeVendaModal();
        }
    });

    window.addEventListener('click', function(event) {
        const modal = document.getElementById('vendaModal');
        if (event.target === modal) {
            closeVendaModal();
        }
    });

    // Inicializa filtros
    initializeFilters();
});

// Função para inicializar filtros
function initializeFilters() {
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterDate').value = '';
    document.getElementById('searchInput').value = '';
}

// Função para animar números
function animateNumber(element, finalNumber) {
    if (finalNumber === 0) return;
    
    let currentNumber = 0;
    const increment = Math.max(1, Math.ceil(finalNumber / 30));
    const duration = 1000;
    const stepTime = duration / (finalNumber / increment);
    
    const timer = setInterval(() => {
        currentNumber += increment;
        if (currentNumber >= finalNumber) {
            currentNumber = finalNumber;
            clearInterval(timer);
        }
        element.textContent = currentNumber.toLocaleString('pt-BR');
    }, stepTime);
}
</script>
</html>