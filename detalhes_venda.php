<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inclui o sistema de permissões e auditoria (se existir)
include('db.php');
if (file_exists('permissions.php')) {
    include('permissions.php');
    $permissionManager = initPermissions($pdo);
    // Verifica se o usuário tem permissão para acessar vendas
    $permissionManager->requirePermission('vendas', 'view');
}

if (file_exists('includes/audit.php')) {
    include('includes/audit.php');
    // Registra acesso à página
    logUserAction('READ', 'detalhes_venda');
}

// Verifica se o usuário é administrador
$isAdmin = (isset($_SESSION['user']) && $_SESSION['user']['permission'] === 'Administrador');

$error = "";
$success = "";
$venda_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Variáveis para armazenar os dados
$venda_info = null;
$cliente_info = null;
$produtos_venda = [];
$totais = [
    'quantidade_total' => 0,
    'valor_total' => 0,
    'impostos_total' => 0,
    'desconto_total' => 0
];

if ($venda_id <= 0) {
    $error = "ID da venda não informado ou inválido.";
} else {
    try {
        // Primeiro, vamos verificar quais colunas existem na tabela clientes
        $columns_query = "SHOW COLUMNS FROM clientes";
        $columns_result = $pdo->query($columns_query);
        $available_columns = [];
        while ($row = $columns_result->fetch(PDO::FETCH_ASSOC)) {
            $available_columns[] = $row['Field'];
        }
        
        // Monta a query baseada nas colunas disponíveis
        $cliente_fields = ['c.nome_orgaos', 'c.uasg'];
        
        // Adiciona colunas opcionais se existirem
        $optional_columns = ['cnpj', 'endereco', 'cidade', 'estado', 'cep', 'telefone', 'email', 'responsavel'];
        foreach ($optional_columns as $column) {
            if (in_array($column, $available_columns)) {
                $cliente_fields[] = "c.$column";
            }
        }
        
        $cliente_fields_str = implode(', ', $cliente_fields);
        
        // Busca informações da venda
        $sql_venda = "SELECT 
                        v.*,
                        $cliente_fields_str
                      FROM vendas v
                      LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
                      WHERE v.id = :venda_id";
        
        $stmt = $pdo->prepare($sql_venda);
        $stmt->bindParam(':venda_id', $venda_id, PDO::PARAM_INT);
        $stmt->execute();
        $venda_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$venda_info) {
            $error = "Venda não encontrada.";
        } else {
            // Busca produtos da venda
            $sql_produtos = "SELECT 
                                vp.*,
                                p.codigo,
                                p.nome as produto_nome,
                                p.und,
                                p.categoria,
                                p.fornecedor,
                                p.preco_unitario as preco_cadastrado,
                                p.icms,
                                p.irpj,
                                p.cofins,
                                p.csll,
                                p.pis_pasep,
                                p.ipi,
                                p.margem_lucro,
                                p.total_impostos,
                                p.custo_total,
                                p.preco_venda
                             FROM venda_produtos vp
                             LEFT JOIN produtos p ON vp.produto_id = p.id
                             WHERE vp.venda_id = :venda_id
                             ORDER BY p.nome";
            
            $stmt = $pdo->prepare($sql_produtos);
            $stmt->bindParam(':venda_id', $venda_id, PDO::PARAM_INT);
            $stmt->execute();
            $produtos_venda = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calcula totais
            foreach ($produtos_venda as $produto) {
                $totais['quantidade_total'] += intval($produto['quantidade'] ?? 0);
                $totais['valor_total'] += floatval($produto['valor_total'] ?? 0);
                
                // Calcula impostos baseado na quantidade
                $valor_impostos = floatval($produto['total_impostos'] ?? 0) * intval($produto['quantidade'] ?? 0);
                $totais['impostos_total'] += $valor_impostos;
            }
            
            // Busca informações adicionais do cliente se disponível
            if (!empty($venda_info['cliente_uasg'])) {
                $cliente_info = [];
                
                // Mapeia apenas as colunas que existem
                $cliente_mapping = [
                    'nome_orgaos' => 'nome_orgaos',
                    'cnpj' => 'cnpj', 
                    'endereco' => 'endereco',
                    'cidade' => 'cidade',
                    'estado' => 'estado', 
                    'cep' => 'cep',
                    'telefone' => 'telefone',
                    'email' => 'email',
                    'uasg' => 'uasg',
                    'responsavel' => 'responsavel'
                ];
                
                foreach ($cliente_mapping as $key => $column) {
                    if (in_array($column, $available_columns)) {
                        $cliente_info[$key] = $venda_info[$column] ?? null;
                    }
                }
                
                // Garante que pelo menos nome_orgaos e uasg existam
                $cliente_info['nome_orgaos'] = $cliente_info['nome_orgaos'] ?? $venda_info['nome_orgaos'] ?? null;
                $cliente_info['uasg'] = $cliente_info['uasg'] ?? $venda_info['uasg'] ?? $venda_info['cliente_uasg'] ?? null;
            }
        }
        
    } catch (PDOException $e) {
        $error = "Erro ao buscar dados: " . $e->getMessage();
    }
}

// Inclui o template de header se existir
if (file_exists('includes/header_template.php')) {
    include('includes/header_template.php');
    renderHeader("Detalhes da Venda #$venda_id - LicitaSis", "vendas");
} else {
    // Header básico se não existir o template
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Detalhes da Venda #<?php echo $venda_id; ?> - LicitaSis</title>
        <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    </head>
    <body>
    
    <header>
        <a href="index.php">
            <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
        </a>
    </header>

    <nav>
        <div class="dropdown">
            <a href="clientes.php">Clientes</a>
            <div class="dropdown-content">
                <a href="cadastrar_clientes.php">Inserir Clientes</a>
                <a href="consultar_clientes.php">Consultar Clientes</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="produtos.php">Produtos</a>
            <div class="dropdown-content">
                <a href="cadastro_produto.php">Inserir Produto</a>
                <a href="consulta_produto.php">Consultar Produtos</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="empenhos.php">Empenhos</a>
            <div class="dropdown-content">
                <a href="cadastro_empenho.php">Inserir Empenho</a>
                <a href="consulta_empenho.php">Consultar Empenho</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="financeiro.php">Financeiro</a>
            <div class="dropdown-content">
                <a href="contas_a_receber.php">Contas a Receber</a>
                <a href="contas_recebidas_geral.php">Contas Recebidas</a>
                <a href="contas_a_pagar.php">Contas a Pagar</a>
                <a href="contas_pagas.php">Contas Pagas</a>
                <a href="caixa.php">Caixa</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="transportadoras.php">Transportadoras</a>
            <div class="dropdown-content">
                <a href="cadastro_transportadoras.php">Inserir Transportadora</a>
                <a href="consulta_transportadoras.php">Consultar Transportadora</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="fornecedores.php">Fornecedores</a>
            <div class="dropdown-content">
                <a href="cadastro_fornecedores.php">Inserir Fornecedor</a>
                <a href="consulta_fornecedores.php">Consultar Fornecedor</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="vendas.php">Vendas</a>
            <div class="dropdown-content">
                <a href="cadastro_vendas.php">Inserir Venda</a>
                <a href="consulta_vendas.php">Consultar Venda</a>
            </div>
        </div>
        <div class="dropdown">
            <a href="compras.php">Compras</a>
            <div class="dropdown-content">
                <a href="cadastro_compras.php">Inserir Compras</a>
                <a href="consulta_compras.php">Consultar Compras</a>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <div class="dropdown">
                <a href="usuario.php">Usuários</a>
                    <div class="dropdown-content">
                        <a href="signup.php">Inserir Novo Usuário</a>
                        <a href="consulta_usuario.php">Consultar Usuário</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <div class="dropdown">
                <a href="funcionarios.php">Funcionários</a>
                    <div class="dropdown-content">
                        <a href="cadastro_funcionario.php">Inserir Novo Funcionário</a>
                        <a href="consulta_funcionario.php">Consultar Funcionário</a>
                </div>
            </div> 
        <?php endif; ?>
    </nav>
    <?php
}
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
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    html, body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        min-height: 100vh;
        color: var(--dark-gray);
        line-height: 1.6;
    }

    /* Header - mesmo estilo do sistema */
    header {
        background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
        padding: 0.5rem 0;
        text-align: center;
        box-shadow: var(--shadow);
    }

    .logo {
        max-width: 140px;
        height: auto;
        transition: var(--transition);
    }

    .logo:hover {
        transform: scale(1.05);
    }

    /* Navigation - mesmo estilo do sistema */
    nav {
        background: var(--primary-color);
        padding: 0;
        text-align: center;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }

    nav a {
        color: white;
        padding: 0.75rem 1rem;
        text-decoration: none;
        font-size: 0.95rem;
        font-weight: 500;
        display: inline-block;
        transition: var(--transition);
        border-bottom: 3px solid transparent;
    }

    nav a:hover {
        background: rgba(255,255,255,0.1);
        border-bottom-color: var(--secondary-color);
        transform: translateY(-1px);
    }

    .dropdown {
        display: inline-block;
        position: relative;
    }

    .dropdown-content {
        display: none;
        position: absolute;
        background: var(--primary-color);
        min-width: 200px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        z-index: 1000;
        border-radius: 0 0 var(--radius-sm) var(--radius-sm);
        overflow: hidden;
    }

    .dropdown-content a {
        display: block;
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }

    .dropdown-content a:last-child {
        border-bottom: none;
    }

    .dropdown:hover .dropdown-content {
        display: block;
        animation: fadeInDown 0.3s ease;
    }

    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Container principal */
    .container {
        max-width: 1400px;
        margin: 2rem auto;
        padding: 0 1rem;
    }

    .page-header {
        background: white;
        padding: 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        margin-bottom: 2rem;
        text-align: center;
        animation: fadeIn 0.5s ease;
    }

    .page-header h1 {
        color: var(--primary-color);
        font-size: 2.2rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        position: relative;
    }

    .page-header h1::after {
        content: '';
        position: absolute;
        bottom: -0.5rem;
        left: 50%;
        transform: translateX(-50%);
        width: 100px;
        height: 3px;
        background: var(--secondary-color);
        border-radius: 2px;
    }

    .page-header .venda-info {
        color: var(--medium-gray);
        font-size: 1.1rem;
        margin-top: 1rem;
    }

    /* Mensagens */
    .error, .success {
        padding: 1rem 1.5rem;
        border-radius: var(--radius-sm);
        margin-bottom: 1.5rem;
        font-weight: 500;
        text-align: center;
        animation: slideInDown 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    @keyframes slideInDown {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Layout em grid */
    .content-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
        margin-bottom: 2rem;
    }

    .info-card {
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 2rem;
        transition: var(--transition);
        animation: fadeInUp 0.6s ease forwards;
    }

    .info-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
    }

    .info-card h3 {
        color: var(--primary-color);
        font-size: 1.4rem;
        font-weight: 600;
        margin-bottom: 1.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid var(--border-color);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .info-item {
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border-color);
    }

    .info-item:last-child {
        border-bottom: none;
    }

    .info-label {
        font-weight: 600;
        color: var(--medium-gray);
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.25rem;
    }

    .info-value {
        color: var(--dark-gray);
        font-size: 1rem;
        font-weight: 500;
    }

    .info-value.currency {
        color: var(--success-color);
        font-family: 'Courier New', monospace;
        font-weight: 600;
    }

    .info-value.status {
        font-weight: 600;
    }

    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-weight: 600;
    }

    .status-recebido {
        background: rgba(40, 167, 69, 0.1);
        color: var(--success-color);
    }

    .status-pendente {
        background: rgba(255, 193, 7, 0.1);
        color: var(--warning-color);
    }

    .status-cancelado {
        background: rgba(220, 53, 69, 0.1);
        color: var(--danger-color);
    }

    /* Card de produtos full width */
    .produtos-card {
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 2rem;
        margin-bottom: 2rem;
        animation: fadeInUp 0.8s ease forwards;
    }

    .produtos-card h3 {
        color: var(--primary-color);
        font-size: 1.4rem;
        font-weight: 600;
        margin-bottom: 1.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid var(--border-color);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    /* Tabela de produtos */
    .table-container {
        overflow-x: auto;
        border-radius: var(--radius-sm);
        box-shadow: var(--shadow);
        background: white;
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
        background: linear-gradient(135deg, var(--secondary-color), var(--secondary-dark));
        color: white;
        font-weight: 600;
        position: sticky;
        top: 0;
        white-space: nowrap;
        font-size: 0.9rem;
    }

    table th i {
        margin-right: 0.5rem;
    }

    table tr {
        transition: var(--transition);
    }

    table tr:hover {
        background: var(--light-gray);
    }

    table td {
        font-size: 0.95rem;
    }

    table td.currency {
        font-weight: 600;
        color: var(--success-color);
        font-family: 'Courier New', monospace;
    }

    table td.center {
        text-align: center;
    }

    /* Card de totais */
    .totais-card {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        color: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 2rem;
        margin-bottom: 2rem;
        animation: fadeInUp 1s ease forwards;
    }

    .totais-card h3 {
        color: white;
        font-size: 1.4rem;
        font-weight: 600;
        margin-bottom: 1.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid rgba(255,255,255,0.3);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .totais-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
    }

    .total-item {
        text-align: center;
        padding: 1rem;
        background: rgba(255,255,255,0.1);
        border-radius: var(--radius-sm);
        transition: var(--transition);
    }

    .total-item:hover {
        background: rgba(255,255,255,0.2);
        transform: translateY(-2px);
    }

    .total-label {
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
        opacity: 0.8;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 500;
    }

    .total-value {
        font-size: 1.8rem;
        font-weight: 700;
        font-family: 'Courier New', monospace;
    }

    /* Botões */
    .btn-container {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 2rem;
        flex-wrap: wrap;
    }

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

    .btn-secondary {
        background: linear-gradient(135deg, var(--medium-gray) 0%, var(--dark-gray) 100%);
        color: white;
        box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
    }

    .btn-secondary:hover {
        background: linear-gradient(135deg, var(--dark-gray) 0%, var(--medium-gray) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(108, 117, 125, 0.3);
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

    .btn-warning {
        background: linear-gradient(135deg, var(--warning-color) 0%, #e0a800 100%);
        color: #333;
        box-shadow: 0 4px 8px rgba(255, 193, 7, 0.2);
    }

    .btn-warning:hover {
        background: linear-gradient(135deg, #e0a800 0%, var(--warning-color) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(255, 193, 7, 0.3);
    }

    /* Responsividade */
    @media (max-width: 1200px) {
        .container {
            padding: 0 1rem;
        }

        .content-grid {
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
    }

    @media (max-width: 768px) {
        .page-header {
            padding: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.8rem;
        }

        .info-card {
            padding: 1.5rem;
        }

        .info-grid {
            grid-template-columns: 1fr;
        }

        .produtos-card {
            padding: 1.5rem;
        }

        .totais-card {
            padding: 1.5rem;
        }

        .totais-grid {
            grid-template-columns: 1fr;
        }

        table {
            font-size: 0.875rem;
        }

        table th, table td {
            padding: 0.75rem 0.5rem;
        }

        .btn-container {
            flex-direction: column;
        }

        .btn {
            width: 100%;
        }
    }

    @media (max-width: 480px) {
        .page-header {
            padding: 1.25rem;
        }

        .page-header h1 {
            font-size: 1.5rem;
        }

        .info-card, .produtos-card, .totais-card {
            padding: 1.25rem;
        }

        .total-value {
            font-size: 1.5rem;
        }

        .btn {
            padding: 0.75rem 1.25rem;
            font-size: 0.9rem;
        }
    }

    /* Print styles */
    @media print {
        nav, .btn-container, header {
            display: none !important;
        }
        
        .container {
            margin: 0;
            padding: 0;
        }
        
        .info-card, .produtos-card, .totais-card {
            box-shadow: none;
            border: 1px solid var(--border-color);
            break-inside: avoid;
        }
        
        table {
            font-size: 10pt;
        }

        .page-header h1::after {
            display: none;
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

    .info-card:nth-child(1) { animation-delay: 0.1s; }
    .info-card:nth-child(2) { animation-delay: 0.2s; }
    .produtos-card { animation-delay: 0.4s; }
    .totais-card { animation-delay: 0.6s; }
</style>

<div class="container">
        <div class="page-header">
        <h1><i class="fas fa-file-invoice-dollar"></i> Detalhes da Venda #<?php echo $venda_id; ?></h1>
        <div class="venda-info">
            <?php if ($venda_info): ?>
                <i class="fas fa-calendar"></i> 
                Data: <?php echo date('d/m/Y', strtotime($venda_info['data'])); ?> | 
                <i class="fas fa-info-circle"></i> 
                Status: <?php echo htmlspecialchars($venda_info['status_pagamento'] ?? 'Não informado'); ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($venda_info): ?>
        <!-- Grid de informações da venda e cliente -->
        <div class="content-grid">
            <!-- Informações da Venda -->
            <div class="info-card">
                <h3><i class="fas fa-shopping-cart"></i> Informações da Venda</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">ID da Venda</div>
                        <div class="info-value">#<?php echo $venda_info['id']; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Data da Venda</div>
                        <div class="info-value">
                            <?php 
                                if (!empty($venda_info['data']) && $venda_info['data'] !== '0000-00-00') {
                                    echo date('d/m/Y', strtotime($venda_info['data'])); 
                                } else {
                                    echo 'Data não informada';
                                }
                            ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Status do Pagamento</div>
                        <div class="info-value status">
                            <?php 
                                $status = $venda_info['status_pagamento'] ?? 'Não informado';
                                if ($status === 'Recebido'): 
                            ?>
                                <span class="status-badge status-recebido">
                                    <i class="fas fa-check-circle"></i> Recebido
                                </span>
                            <?php elseif ($status === 'Pendente'): ?>
                                <span class="status-badge status-pendente">
                                    <i class="fas fa-clock"></i> Pendente
                                </span>
                            <?php elseif ($status === 'Cancelado'): ?>
                                <span class="status-badge status-cancelado">
                                    <i class="fas fa-times-circle"></i> Cancelado
                                </span>
                            <?php else: ?>
                                <span class="status-badge status-pendente">
                                    <i class="fas fa-question-circle"></i> <?php echo htmlspecialchars($status); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Observações</div>
                        <div class="info-value">
                            <?php echo !empty($venda_info['observacoes']) ? htmlspecialchars($venda_info['observacoes']) : 'Nenhuma observação'; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Cadastrado em</div>
                        <div class="info-value">
                            <?php 
                                if (!empty($venda_info['created_at'])) {
                                    echo date('d/m/Y H:i', strtotime($venda_info['created_at'])); 
                                } else {
                                    echo 'Data não disponível';
                                }
                            ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Última Atualização</div>
                        <div class="info-value">
                            <?php 
                                if (!empty($venda_info['updated_at'])) {
                                    echo date('d/m/Y H:i', strtotime($venda_info['updated_at'])); 
                                } else {
                                    echo 'Nunca atualizado';
                                }
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Informações do Cliente -->
            <div class="info-card">
                <h3><i class="fas fa-building"></i> Informações do Cliente</h3>
                <?php if ($cliente_info): ?>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Nome do Órgão</div>
                            <div class="info-value">
                                <?php echo !empty($cliente_info['nome_orgaos']) ? htmlspecialchars($cliente_info['nome_orgaos']) : 'Não informado'; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">UASG</div>
                            <div class="info-value">
                                <?php echo !empty($cliente_info['uasg']) ? htmlspecialchars($cliente_info['uasg']) : 'Não informado'; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">CNPJ</div>
                            <div class="info-value">
                                <?php 
                                    if (!empty($cliente_info['cnpj'])) {
                                        // Formata CNPJ se necessário
                                        $cnpj = preg_replace('/\D/', '', $cliente_info['cnpj']);
                                        if (strlen($cnpj) === 14) {
                                            echo substr($cnpj, 0, 2) . '.' . 
                                                 substr($cnpj, 2, 3) . '.' . 
                                                 substr($cnpj, 5, 3) . '/' . 
                                                 substr($cnpj, 8, 4) . '-' . 
                                                 substr($cnpj, 12, 2);
                                        } else {
                                            echo htmlspecialchars($cliente_info['cnpj']);
                                        }
                                    } else {
                                        echo 'Não informado';
                                    }
                                ?>
                            </div>
                        </div>
                        <?php if (isset($cliente_info['responsavel'])): ?>
                        <div class="info-item">
                            <div class="info-label">Responsável</div>
                            <div class="info-value">
                                <?php echo !empty($cliente_info['responsavel']) ? htmlspecialchars($cliente_info['responsavel']) : 'Não informado'; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($cliente_info['telefone'])): ?>
                        <div class="info-item">
                            <div class="info-label">Telefone</div>
                            <div class="info-value">
                                <?php echo !empty($cliente_info['telefone']) ? htmlspecialchars($cliente_info['telefone']) : 'Não informado'; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($cliente_info['email'])): ?>
                        <div class="info-item">
                            <div class="info-label">E-mail</div>
                            <div class="info-value">
                                <?php echo !empty($cliente_info['email']) ? htmlspecialchars($cliente_info['email']) : 'Não informado'; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($cliente_info['endereco']) || isset($cliente_info['cidade']) || isset($cliente_info['estado']) || isset($cliente_info['cep'])): ?>
                        <div class="info-item" style="grid-column: 1 / -1;">
                            <div class="info-label">Endereço Completo</div>
                            <div class="info-value">
                                <?php 
                                    $endereco_completo = '';
                                    if (!empty($cliente_info['endereco'])) $endereco_completo .= $cliente_info['endereco'];
                                    if (!empty($cliente_info['cidade'])) $endereco_completo .= (!empty($endereco_completo) ? ', ' : '') . $cliente_info['cidade'];
                                    if (!empty($cliente_info['estado'])) $endereco_completo .= (!empty($endereco_completo) ? ' - ' : '') . $cliente_info['estado'];
                                    if (!empty($cliente_info['cep'])) $endereco_completo .= (!empty($endereco_completo) ? ' - CEP: ' : 'CEP: ') . $cliente_info['cep'];
                                    
                                    echo !empty($endereco_completo) ? htmlspecialchars($endereco_completo) : 'Endereço não informado';
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="info-item">
                        <div class="info-label">Cliente</div>
                        <div class="info-value">
                            UASG: <?php echo htmlspecialchars($venda_info['cliente_uasg'] ?? 'Não informado'); ?>
                        </div>
                    </div>
                    <p style="color: var(--medium-gray); text-align: center; margin-top: 1rem;">
                        <i class="fas fa-info-circle"></i> Informações detalhadas do cliente não disponíveis
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card de Produtos -->
        <div class="produtos-card">
            <h3><i class="fas fa-boxes"></i> Produtos da Venda</h3>
            <?php if (count($produtos_venda) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fas fa-barcode"></i> Código</th>
                                <th><i class="fas fa-box"></i> Produto</th>
                                <th><i class="fas fa-weight"></i> Unidade</th>
                                <th><i class="fas fa-truck"></i> Fornecedor</th>
                                <th><i class="fas fa-hashtag"></i> Qtd.</th>
                                <th><i class="fas fa-dollar-sign"></i> Valor Unit.</th>
                                <th><i class="fas fa-calculator"></i> Valor Total</th>
                                <th><i class="fas fa-percentage"></i> Impostos</th>
                                <th><i class="fas fa-tags"></i> Categoria</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($produtos_venda as $produto): ?>
                                <tr>
                                    <td>
                                        <?php echo !empty($produto['codigo']) ? htmlspecialchars($produto['codigo']) : 'N/A'; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo !empty($produto['produto_nome']) ? htmlspecialchars($produto['produto_nome']) : 'Produto não encontrado'; ?></strong>
                                        <?php if (!empty($produto['observacao'])): ?>
                                            <br><small style="color: var(--medium-gray);"><?php echo htmlspecialchars($produto['observacao']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="center">
                                        <?php echo !empty($produto['und']) ? htmlspecialchars($produto['und']) : 'UN'; ?>
                                    </td>
                                    <td>
                                        <?php echo !empty($produto['fornecedor']) ? htmlspecialchars($produto['fornecedor']) : 'Não informado'; ?>
                                    </td>
                                    <td class="center">
                                        <?php echo number_format(intval($produto['quantidade'] ?? 0), 0, ',', '.'); ?>
                                    </td>
                                    <td class="currency">
                                        R$ <?php echo number_format(floatval($produto['valor_unitario'] ?? 0), 2, ',', '.'); ?>
                                    </td>
                                    <td class="currency">
                                        <strong>R$ <?php echo number_format(floatval($produto['valor_total'] ?? 0), 2, ',', '.'); ?></strong>
                                    </td>
                                    <td class="center">
                                        <?php 
                                            $impostos = [
                                                'ICMS' => floatval($produto['icms'] ?? 0),
                                                'IRPJ' => floatval($produto['irpj'] ?? 0),
                                                'COFINS' => floatval($produto['cofins'] ?? 0),
                                                'CSLL' => floatval($produto['csll'] ?? 0),
                                                'PIS' => floatval($produto['pis_pasep'] ?? 0),
                                                'IPI' => floatval($produto['ipi'] ?? 0)
                                            ];
                                            
                                            $impostos_ativos = array_filter($impostos, function($valor) { return $valor > 0; });
                                            
                                            if (!empty($impostos_ativos)) {
                                                echo '<small>';
                                                foreach ($impostos_ativos as $nome => $valor) {
                                                    echo $nome . ': ' . number_format($valor, 2, ',', '.') . '%<br>';
                                                }
                                                echo '</small>';
                                            } else {
                                                echo '<small style="color: var(--medium-gray);">Sem impostos</small>';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo !empty($produto['categoria']) ? htmlspecialchars($produto['categoria']) : 'Não categorizado'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 3rem; color: var(--medium-gray);">
                    <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                    <p>Nenhum produto encontrado para esta venda.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Card de Totais -->
        <div class="totais-card">
            <h3><i class="fas fa-calculator"></i> Resumo Financeiro</h3>
            <div class="totais-grid">
                <div class="total-item">
                    <div class="total-label">Quantidade Total</div>
                    <div class="total-value"><?php echo number_format($totais['quantidade_total'], 0, ',', '.'); ?></div>
                </div>
                <div class="total-item">
                    <div class="total-label">Valor Total</div>
                    <div class="total-value">R$ <?php echo number_format($totais['valor_total'], 2, ',', '.'); ?></div>
                </div>
                <div class="total-item">
                    <div class="total-label">Total de Impostos</div>
                    <div class="total-value">R$ <?php echo number_format($totais['impostos_total'], 2, ',', '.'); ?></div>
                </div>
                <div class="total-item">
                    <div class="total-label">Valor Líquido</div>
                    <div class="total-value">R$ <?php echo number_format($totais['valor_total'] - $totais['impostos_total'], 2, ',', '.'); ?></div>
                </div>
            </div>
        </div>

    <?php endif; ?>

    <!-- Botões de Ação -->
    <div class="btn-container">
        <a href="consulta_produto.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Voltar para Vendas
        </a>
        
        <?php if ($venda_info): ?>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Imprimir
            </button>
            
            <a href="exportar_venda.php?id=<?php echo $venda_id; ?>" class="btn btn-success">
                <i class="fas fa-file-excel"></i> Exportar Excel
            </a>
            
            <?php if ($isAdmin): ?>
                <a href="editar_venda.php?id=<?php echo $venda_id; ?>" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Editar Venda
                </a>
            <?php endif; ?>
            
            <a href="gerar_pdf_venda.php?id=<?php echo $venda_id; ?>" class="btn btn-primary" target="_blank">
                <i class="fas fa-file-pdf"></i> Gerar PDF
            </a>
        <?php endif; ?>
    </div>
</div>

<?php
// Finaliza a página com footer e scripts se o template existir
if (function_exists('renderFooter')) {
    renderFooter();
}

if (function_exists('renderScripts')) {
    renderScripts();
}
?>

<script>
    // JavaScript específico da página de detalhes da venda
    document.addEventListener('DOMContentLoaded', function() {
        // Anima os valores dos totais
        function animateNumber(element, finalNumber) {
            if (!element || isNaN(finalNumber)) return;
            
            let currentNumber = 0;
            const increment = Math.max(1, Math.ceil(finalNumber / 30));
            const duration = 1000;
            const stepTime = duration / (finalNumber / increment);
            
            const isMonetary = element.textContent.includes('R);
            
            if (isMonetary) {
                element.textContent = 'R$ 0,00';
            } else {
                element.textContent = '0';
            }
            
            const timer = setInterval(() => {
                currentNumber += increment;
                if (currentNumber >= finalNumber) {
                    currentNumber = finalNumber;
                    clearInterval(timer);
                }
                
                if (isMonetary) {
                    element.textContent = 'R$ ' + currentNumber.toLocaleString('pt-BR', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                } else {
                    element.textContent = currentNumber.toLocaleString('pt-BR');
                }
            }, stepTime);
        }

        // Observer para animar quando os totais ficarem visíveis
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const numberElement = entry.target.querySelector('.total-value');
                    if (numberElement && !numberElement.dataset.animated) {
                        numberElement.dataset.animated = 'true';
                        const text = numberElement.textContent.trim();
                        
                        // Extrai o número do texto
                        let finalNumber = 0;
                        if (text.includes('R)) {
                            // Remove R$, pontos e vírgulas para pegar o número
                            finalNumber = parseFloat(text.replace(/[R$\s.]/g, '').replace(',', '.'));
                        } else if (/^\d+$/.test(text.replace(/[.,]/g, ''))) {
                            finalNumber = parseInt(text.replace(/[.,]/g, ''));
                        }
                        
                        if (!isNaN(finalNumber) && finalNumber > 0) {
                            setTimeout(() => animateNumber(numberElement, finalNumber), 200);
                        }
                    }
                }
            });
        }, { threshold: 0.5 });

        // Observa todos os totais
        document.querySelectorAll('.total-item').forEach(item => {
            observer.observe(item);
        });

        // Adiciona efeitos de hover nos botões
        const buttons = document.querySelectorAll('.btn');
        buttons.forEach(button => {
            button.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });
            
            button.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
            
            button.addEventListener('mousedown', function() {
                this.style.transform = 'translateY(1px)';
            });
            
            button.addEventListener('mouseup', function() {
                this.style.transform = 'translateY(-2px)';
            });
        });

        // Funcionalidade de busca rápida na tabela de produtos
        function addQuickSearch() {
            const table = document.querySelector('table');
            if (table) {
                const searchContainer = document.createElement('div');
                searchContainer.style.cssText = `
                    margin-bottom: 1rem;
                    display: flex;
                    justify-content: flex-end;
                    gap: 0.5rem;
                `;
                
                const searchInput = document.createElement('input');
                searchInput.type = 'text';
                searchInput.placeholder = 'Buscar produtos...';
                searchInput.style.cssText = `
                    padding: 0.5rem 1rem;
                    border: 1px solid var(--border-color);
                    border-radius: var(--radius-sm);
                    font-size: 0.9rem;
                    width: 250px;
                `;
                
                const clearButton = document.createElement('button');
                clearButton.innerHTML = '<i class="fas fa-times"></i>';
                clearButton.style.cssText = `
                    padding: 0.5rem 0.75rem;
                    background: var(--medium-gray);
                    color: white;
                    border: none;
                    border-radius: var(--radius-sm);
                    cursor: pointer;
                `;
                
                searchContainer.appendChild(searchInput);
                searchContainer.appendChild(clearButton);
                
                const tableContainer = document.querySelector('.table-container');
                tableContainer.parentNode.insertBefore(searchContainer, tableContainer);
                
                // Funcionalidade de busca
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = document.querySelectorAll('table tbody tr');
                    
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(searchTerm) ? '' : 'none';
                    });
                });
                
                // Limpar busca
                clearButton.addEventListener('click', function() {
                    searchInput.value = '';
                    const rows = document.querySelectorAll('table tbody tr');
                    rows.forEach(row => row.style.display = '');
                });
            }
        }

        // Adiciona busca se houver produtos
        <?php if (count($produtos_venda) > 0): ?>
            addQuickSearch();
        <?php endif; ?>

        // Adiciona confirmação para ações importantes
        const exportButton = document.querySelector('a[href*="exportar_venda"]');
        if (exportButton) {
            exportButton.addEventListener('click', function(e) {
                if (!confirm('Deseja exportar os dados desta venda para Excel?')) {
                    e.preventDefault();
                }
            });
        }

        const editButton = document.querySelector('a[href*="editar_venda"]');
        if (editButton) {
            editButton.addEventListener('click', function(e) {
                if (!confirm('Deseja editar esta venda? Esta ação pode afetar relatórios e controles financeiros.')) {
                    e.preventDefault();
                }
            });
        }

        // Adiciona atalhos de teclado
        document.addEventListener('keydown', function(e) {
            // Ctrl + P para imprimir
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            
            // Ctrl + B para voltar
            if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
                e.preventDefault();
                window.location.href = 'consultar_vendas_produto.php';
            }
            
            // Escape para limpar busca
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('input[placeholder*="Buscar"]');
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.dispatchEvent(new Event('input'));
                }
            }
        });

        // Tooltip dinâmico para impostos
        const impostoCells = document.querySelectorAll('table td:nth-child(8)');
        impostoCells.forEach(cell => {
            cell.addEventListener('mouseenter', function() {
                this.title = 'Percentuais de impostos aplicados a este produto';
            });
        });

        // Registra analytics da página
        console.log('Página de Detalhes da Venda carregada com sucesso!');
        console.log('Venda ID:', <?php echo $venda_id; ?>);
        console.log('Total de produtos:', <?php echo count($produtos_venda); ?>);
        console.log('Valor total da venda: R, <?php echo number_format($totais['valor_total'], 2, ',', '.'); ?>);
        console.log('Usuário:', '<?php echo addslashes($_SESSION['user']['name'] ?? 'N/A'); ?>');
        console.log('Permissão:', '<?php echo addslashes($_SESSION['user']['permission'] ?? 'N/A'); ?>');

        // Função para mostrar notificações
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? 'var(--success-color)' : type === 'warning' ? 'var(--warning-color)' : 'var(--info-color)'};
                color: ${type === 'warning' ? '#333' : 'white'};
                padding: 1rem 1.5rem;
                border-radius: var(--radius-sm);
                box-shadow: var(--shadow);
                display: flex;
                align-items: center;
                gap: 0.75rem;
                z-index: 1000;
                animation: slideInRight 0.3s ease;
                max-width: 400px;
            `;
            
            document.body.appendChild(notification);
            
            // Remove após 5 segundos
            setTimeout(() => {
                notification.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }

        // Verifica se há parâmetros de sucesso na URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('success')) {
            showNotification('Operação realizada com sucesso!', 'success');
        }
        if (urlParams.get('error')) {
            showNotification('Ocorreu um erro na operação.', 'warning');
        }

        // Auto-save da posição do scroll para quando voltar à página
        window.addEventListener('beforeunload', function() {
            localStorage.setItem('detalhes_venda_scroll', window.scrollY);
        });

        // Restaura posição do scroll
        const savedScroll = localStorage.getItem('detalhes_venda_scroll');
        if (savedScroll) {
            window.scrollTo(0, parseInt(savedScroll));
            localStorage.removeItem('detalhes_venda_scroll');
        }
    });

    // Animações CSS adicionais
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .notification button {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            padding: 0.25rem;
            margin-left: 1rem;
            transition: opacity 0.2s;
        }

        .notification button:hover {
            opacity: 0.7;
        }

        /* Melhorias de acessibilidade */
        .btn:focus,
        .info-card:focus-within,
        table tr:focus-within {
            outline: 3px solid var(--secondary-color);
            outline-offset: 2px;
        }

        /* Modo de alto contraste */
        @media (prefers-contrast: high) {
            .info-card,
            .produtos-card,
            .totais-card {
                border: 2px solid var(--dark-gray);
            }
        }

        /* Animações reduzidas para quem prefere */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* Indicador de carregamento */
        .loading {
            position: relative;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 255, 255, 0.4),
                transparent
            );
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            to {
                left: 100%;
            }
        }

        /* Highlight para valores importantes */
        .highlight-value {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
        }

        /* Estilo para observações longas */
        .observacao-expandida {
            max-height: 60px;
            overflow: hidden;
            transition: max-height 0.3s ease;
            cursor: pointer;
        }

        .observacao-expandida.expanded {
            max-height: none;
        }

        .observacao-expandida::after {
            content: ' (clique para expandir)';
            color: var(--secondary-color);
            font-size: 0.85rem;
            font-style: italic;
        }

        .observacao-expandida.expanded::after {
            content: ' (clique para recolher)';
        }

        /* Efeito de destaque para status */
        .status-badge {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(40, 167, 69, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(40, 167, 69, 0);
            }
        }

        .status-pendente {
            animation: pulse 2s infinite;
        }

        .status-pendente {
            animation-name: pulsePendente;
        }

        @keyframes pulsePendente {
            0% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(255, 193, 7, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0);
            }
        }

        /* Tooltip customizado */
        .tooltip {
            position: relative;
            cursor: help;
        }

        .tooltip::before {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--dark-gray);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .tooltip::after {
            content: '';
            position: absolute;
            bottom: 115%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: var(--dark-gray);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .tooltip:hover::before,
        .tooltip:hover::after {
            opacity: 1;
            visibility: visible;
        }

        /* Responsividade melhorada para dispositivos muito pequenos */
        @media (max-width: 360px) {
            .page-header h1 {
                font-size: 1.25rem;
            }
            
            .info-card, .produtos-card, .totais-card {
                padding: 1rem;
            }
            
            .total-value {
                font-size: 1.25rem;
            }
            
            table th, table td {
                padding: 0.5rem 0.25rem;
                font-size: 0.8rem;
            }
        }
    `;
    document.head.appendChild(style);
</script>

</body>
</html>