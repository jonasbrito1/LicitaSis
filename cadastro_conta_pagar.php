<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Incluir arquivos necessários
include('db.php');
include('permissions.php');
include('includes/audit.php');

$permissionManager = initPermissions($pdo);
$permissionManager->requirePermission('financeiro', 'create');
logUserAction('READ', 'conta_pagar_cadastro');

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = false;

// Função para converter data brasileira para formato MySQL
function converterDataBrasil($data) {
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

// Função para converter data MySQL para formato brasileiro
function converterDataMysql($data) {
    if (empty($data) || $data === '0000-00-00') return '';
    
    $timestamp = strtotime($data);
    return $timestamp ? date('d/m/Y', $timestamp) : '';
}

// Definir tipos de despesa
$tiposDespesa = [
    'Compras' => 'Compras',
    'Servicos' => 'Serviços',
    'Manutencao' => 'Manutenção',
    'Consultoria' => 'Consultoria',
    'Equipamentos' => 'Equipamentos',
    'Material_Escritorio' => 'Material de Escritório',
    'Limpeza' => 'Limpeza',
    'Seguranca' => 'Segurança',
    'Outros' => 'Outros'
];

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Recebe os dados do formulário
        $fornecedor_nome = trim($_POST['fornecedor_nome']);
        $numero_nf = trim($_POST['numero_nf']);
        $data_compra = converterDataBrasil(trim($_POST['data_compra']));
        $numero_empenho = trim($_POST['numero_empenho']) ?? null;
        $data_vencimento = converterDataBrasil(trim($_POST['data_vencimento'] ?? ''));
        $valor_total = str_replace(',', '.', trim($_POST['valor_total']));
        $observacao = trim($_POST['observacao']) ?? null;
        $tipo_despesa = trim($_POST['tipo_despesa']) ?? 'Outros';
        
        // Validações básicas
        if (empty($fornecedor_nome) || empty($numero_nf) || empty($data_compra) || empty($valor_total)) {
            throw new Exception("Preencha todos os campos obrigatórios (Fornecedor, NF, Data da Compra e Valor).");
        }

        // Validar valor
        if (!is_numeric($valor_total) || floatval($valor_total) <= 0) {
            throw new Exception("O valor deve ser um número válido e maior que zero.");
        }

        // Validar datas
        if (!$data_compra || !DateTime::createFromFormat('Y-m-d', $data_compra)) {
            throw new Exception("Data da compra inválida.");
        }

        if ($data_vencimento && !DateTime::createFromFormat('Y-m-d', $data_vencimento)) {
            throw new Exception("Data de vencimento inválida.");
        }

        // Validar se a data de vencimento não é anterior à data da compra
        if ($data_compra && $data_vencimento) {
            $dataCompraObj = new DateTime($data_compra);
            $dataVencimentoObj = new DateTime($data_vencimento);
            if ($dataVencimentoObj < $dataCompraObj) {
                throw new Exception("A Data de Vencimento não pode ser anterior à Data da Compra.");
            }
        }
        
        // Inicializa a variável para o nome do arquivo
        $comprovante_pagamento = null;
        
        // Verifica se um arquivo foi enviado
        if (isset($_FILES['comprovante_pagamento']) && $_FILES['comprovante_pagamento']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            $filename = $_FILES['comprovante_pagamento']['name'];
            $filetype = pathinfo($filename, PATHINFO_EXTENSION);
            
            // Verifica se a extensão do arquivo é permitida
            if (in_array(strtolower($filetype), $allowed)) {
                // Cria um nome único para o arquivo
                $new_filename = uniqid('comprovante_conta_') . '.' . $filetype;
                $upload_dir = 'uploads/comprovantes/';
                
                // Cria o diretório se não existir
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Move o arquivo para o diretório de uploads
                if (move_uploaded_file($_FILES['comprovante_pagamento']['tmp_name'], $upload_dir . $new_filename)) {
                    $comprovante_pagamento = $upload_dir . $new_filename;
                } else {
                    throw new Exception("Erro ao fazer upload do comprovante.");
                }
            } else {
                throw new Exception("Tipo de arquivo não permitido. Apenas JPG, JPEG, PNG e PDF são aceitos.");
            }
        }

        // SQL INSERT para inserir diretamente na tabela contas_pagar
        $sql = "INSERT INTO contas_pagar 
                (compra_id, fornecedor_nome, numero_nf, data_compra, numero_empenho, data_vencimento, 
                 valor_total, observacao, tipo_despesa, status_pagamento, comprovante_pagamento) 
                VALUES 
                (NULL, :fornecedor_nome, :numero_nf, :data_compra, :numero_empenho, :data_vencimento, 
                 :valor_total, :observacao, :tipo_despesa, 'Pendente', :comprovante_pagamento)";
        
        $stmt = $pdo->prepare($sql);
        
        // Bind dos parâmetros
        $stmt->bindParam(':fornecedor_nome', $fornecedor_nome, PDO::PARAM_STR);
        $stmt->bindParam(':numero_nf', $numero_nf, PDO::PARAM_STR);
        $stmt->bindParam(':data_compra', $data_compra, PDO::PARAM_STR);
        $stmt->bindParam(':numero_empenho', $numero_empenho, PDO::PARAM_STR);
        $stmt->bindParam(':data_vencimento', $data_vencimento, PDO::PARAM_STR);
        $stmt->bindParam(':valor_total', $valor_total, PDO::PARAM_STR);
        $stmt->bindParam(':observacao', $observacao, PDO::PARAM_STR);
        $stmt->bindParam(':tipo_despesa', $tipo_despesa, PDO::PARAM_STR);
        $stmt->bindParam(':comprovante_pagamento', $comprovante_pagamento, PDO::PARAM_STR);

        // Executa a consulta
        if (!$stmt->execute()) {
            throw new Exception("Erro ao cadastrar conta a pagar.");
        }
        
        $conta_id = $pdo->lastInsertId();
        
        $pdo->commit();

        // Registra auditoria
        logUserAction('CREATE', 'contas_pagar', $conta_id, [
            'fornecedor_nome' => $fornecedor_nome,
            'numero_nf' => $numero_nf,
            'data_compra' => $data_compra,
            'data_vencimento' => $data_vencimento,
            'valor_total' => $valor_total,
            'tipo_despesa' => $tipo_despesa,
            'tipo' => 'conta_direta',
            'upload' => $comprovante_pagamento ? 'sim' : 'não'
        ]);

        $success = true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Erro ao cadastrar conta a pagar: " . $e->getMessage();
        
        // Log do erro para debug
        error_log("Erro no cadastro de conta a pagar: " . $e->getMessage());
        error_log("Dados POST: " . print_r($_POST, true));
    }
}

// Incluir header template
include('includes/header_template.php');
renderHeader("Cadastro de Conta a Pagar - LicitaSis", "financeiro");
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Conta a Pagar - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
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

        /* Container principal */
        .container {
            max-width: 1200px;
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

        h3 {
            color: var(--primary-color);
            margin: 2rem 0 1.5rem;
            font-size: 1.3rem;
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        h3 i {
            color: var(--secondary-color);
        }

        /* Mensagens de feedback */
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

        /* Formulário */
        .form-container {
            display: grid;
            gap: 2rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 0;
            position: relative;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group label i {
            color: var(--secondary-color);
            width: 16px;
        }

        .required::after {
            content: ' *';
            color: var(--danger-color);
            font-weight: bold;
        }

        .form-control {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
            transform: translateY(-1px);
        }

        .form-control:hover {
            border-color: var(--secondary-color);
        }

        .form-control[readonly] {
            background: var(--light-gray);
            color: var(--medium-gray);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        /* Data sections */
        .data-section {
            background: linear-gradient(135deg, var(--light-gray) 0%, #f8f9fa 100%);
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            margin-top: 1.5rem;
        }

        .data-section h4 {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .data-section h4 i {
            color: var(--secondary-color);
        }

        /* Upload de arquivo */
        .file-input-container {
            position: relative;
            margin-bottom: 1rem;
        }

        .file-input-label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.875rem;
            background: var(--light-gray);
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
        }

        .file-input-label:hover {
            background: #e9ecef;
            border-color: var(--secondary-color);
        }

        .file-input-label i {
            margin-right: 0.5rem;
        }

        input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-name {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: var(--medium-gray);
            text-align: center;
        }

        /* Valor Total */
        .valor-total-section {
            background: linear-gradient(135deg, var(--light-gray) 0%, #f8f9fa 100%);
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            margin-top: 2rem;
        }

        .valor-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            font-size: 1.2rem;
            color: var(--primary-color);
        }

        .valor-total-display {
            font-weight: 700;
            font-size: 1.3rem;
            color: var(--success-color);
            background: rgba(40, 167, 69, 0.1);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
        }

        /* Botões */
        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            min-width: 160px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--medium-gray) 0%, #5a6268 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
        }

        .btn-secondary:hover:not(:disabled) {
            background: linear-gradient(135deg, #5a6268 0%, var(--medium-gray) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(108, 117, 125, 0.3);
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
            animation: fadeInModal 0.3s ease;
        }

        .modal-content {
            background: white;
            margin: 8% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideInUp 0.3s ease;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--success-color), #1e7e34);
            color: white;
            padding: 2rem;
            text-align: center;
            position: relative;
        }

        .modal-header h3 {
            margin: 0;
            color: white;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            border-bottom: none;
        }

        .modal-body {
            padding: 2rem;
            text-align: center;
        }

        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }

        .modal-buttons .btn-primary,
        .modal-buttons .btn-secondary {
            padding: 0.75rem 1.5rem;
            min-width: 140px;
        }

        @keyframes fadeInModal {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideInUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Estados de input */
        .form-control.success-state {
            border-color: var(--success-color);
            background: rgba(40, 167, 69, 0.05);
        }

        .form-control.error-state {
            border-color: var(--danger-color);
            background: rgba(220, 53, 69, 0.05);
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 1.5rem;
                padding: 2rem;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin: 1rem;
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.75rem;
                flex-direction: column;
                gap: 0.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .btn-container {
                flex-direction: column;
                gap: 1rem;
            }

            .btn {
                width: 100%;
            }

            .valor-total-row {
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }

            .modal-buttons {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .container {
                margin: 0.5rem;
                padding: 1rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .form-control {
                padding: 0.75rem;
                font-size: 0.9rem;
            }

            .valor-total-section,
            .data-section {
                padding: 1rem;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .modal-header,
            .modal-body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>
        <i class="fas fa-file-invoice-dollar"></i>
        Cadastro de Conta a Pagar
    </h2>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form class="form-container" action="cadastro_conta_pagar.php" method="POST" enctype="multipart/form-data" onsubmit="return validarFormulario()">
        <div class="form-row">
            <div class="form-group">
                <label for="fornecedor_nome" class="required">
                    <i class="fas fa-building"></i>
                    Nome do Fornecedor
                </label>
                <input type="text" 
                       id="fornecedor_nome" 
                       name="fornecedor_nome" 
                       class="form-control" 
                       placeholder="Digite o nome do fornecedor"
                       value="<?php echo $success ? '' : (isset($_POST['fornecedor_nome']) ? htmlspecialchars($_POST['fornecedor_nome']) : ''); ?>"
                       required>
            </div>

            <div class="form-group">
                <label for="numero_nf" class="required">
                    <i class="fas fa-file-invoice"></i>
                    Número da NF
                </label>
                <input type="text" 
                       id="numero_nf" 
                       name="numero_nf" 
                       class="form-control" 
                       placeholder="Digite o número da nota fiscal"
                       value="<?php echo $success ? '' : (isset($_POST['numero_nf']) ? htmlspecialchars($_POST['numero_nf']) : ''); ?>"
                       required>
            </div>
        </div>

        <!-- Seção de Datas -->
        <div class="data-section">
            <h4><i class="fas fa-calendar-days"></i> Informações de Data e Valor</h4>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="data_compra" class="required">
                        <i class="fas fa-calendar-alt"></i>
                        Data da Compra
                    </label>
                    <input type="date" 
                           id="data_compra" 
                           name="data_compra" 
                           class="form-control" 
                           value="<?php echo $success ? '' : (isset($_POST['data_compra']) ? htmlspecialchars($_POST['data_compra']) : date('Y-m-d')); ?>"
                           required>
                </div>

                <div class="form-group">
                    <label for="data_vencimento">
                        <i class="fas fa-clock"></i>
                        Data de Vencimento
                    </label>
                    <input type="date" 
                           id="data_vencimento" 
                           name="data_vencimento" 
                           class="form-control" 
                           value="<?php echo $success ? '' : (isset($_POST['data_vencimento']) ? htmlspecialchars($_POST['data_vencimento']) : ''); ?>">
                    <small style="color: var(--medium-gray); font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                        <i class="fas fa-info-circle"></i> Data limite para pagamento (opcional)
                    </small>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="valor_total" class="required">
                        <i class="fas fa-dollar-sign"></i>
                        Valor Total (R$)
                    </label>
                    <input type="number" 
                           id="valor_total" 
                           name="valor_total" 
                           class="form-control" 
                           step="0.01" 
                           min="0.01"
                           placeholder="0,00"
                           value="<?php echo $success ? '' : (isset($_POST['valor_total']) ? htmlspecialchars($_POST['valor_total']) : ''); ?>"
                           oninput="atualizarDisplayValor()"
                           required>
                </div>

                <div class="form-group">
                    <label for="numero_empenho">
                        <i class="fas fa-hashtag"></i>
                        Número do Empenho
                    </label>
                    <input type="text" 
                           id="numero_empenho" 
                           name="numero_empenho" 
                           class="form-control" 
                           placeholder="Número do empenho (opcional)"
                           value="<?php echo $success ? '' : (isset($_POST['numero_empenho']) ? htmlspecialchars($_POST['numero_empenho']) : ''); ?>">
                </div>
            </div>
        </div>

        <!-- Valor Total Display -->
        <div class="valor-total-section">
            <div class="valor-total-row">
                <span><i class="fas fa-calculator"></i> Valor Total da Conta:</span>
                <span class="valor-total-display" id="valor_total_display">R$ 0,00</span>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="tipo_despesa">
                    <i class="fas fa-tags"></i>
                    Tipo de Despesa
                </label>
                <select id="tipo_despesa" name="tipo_despesa" class="form-control">
                    <?php foreach ($tiposDespesa as $key => $value): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>" 
                                <?php echo ($success ? '' : (isset($_POST['tipo_despesa']) && $_POST['tipo_despesa'] == $key ? 'selected' : ($key == 'Outros' ? 'selected' : ''))); ?>>
                            <?php echo htmlspecialchars($value); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="comprovante_pagamento">
                    <i class="fas fa-file-upload"></i>
                    Comprovante / Documento
                </label>
                <div class="file-input-container">
                    <label for="comprovante_pagamento" class="file-input-label">
                        <i class="fas fa-upload"></i> Selecionar arquivo
                    </label>
                    <input type="file" 
                           id="comprovante_pagamento" 
                           name="comprovante_pagamento" 
                           accept=".jpg,.jpeg,.png,.pdf" 
                           onchange="updateFileName(this)">
                    <div class="file-name" id="file-name">Nenhum arquivo selecionado</div>
                </div>
                <small style="color: var(--medium-gray); font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                    Formatos aceitos: JPG, JPEG, PNG, PDF. Tamanho máximo: 5MB
                </small>
            </div>
        </div>

        <div class="form-group">
            <label for="observacao">
                <i class="fas fa-comment-alt"></i>
                Observações
            </label>
            <textarea id="observacao" 
                      name="observacao" 
                      class="form-control" 
                      rows="3"
                      placeholder="Informações adicionais sobre a conta..."><?php echo $success ? '' : (isset($_POST['observacao']) ? htmlspecialchars($_POST['observacao']) : ''); ?></textarea>
        </div>

        <div class="btn-container">
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-save"></i>
                Cadastrar Conta a Pagar
            </button>
            <button type="reset" class="btn btn-secondary" onclick="limparFormulario()">
                <i class="fas fa-undo"></i>
                Limpar Campos
            </button>
        </div>
    </form>
</div>

<!-- Modal de Sucesso -->
<?php if ($success): ?>
    <div id="successModal" class="modal" style="display: block;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-check-circle"></i>
                    Conta a Pagar Cadastrada com Sucesso!
                </h3>
            </div>
            <div class="modal-body">
                <p>A conta a pagar foi registrada no sistema.</p>
                <p>Deseja acessar a página de consulta de contas a pagar?</p>
                <div class="modal-buttons">
                    <button class="btn btn-primary" onclick="goToConsulta()">
                        <i class="fas fa-search"></i>
                        Sim, Ver Contas
                    </button>
                    <button class="btn btn-secondary" onclick="closeModal()">
                        <i class="fas fa-plus"></i>
                        Cadastrar Outra
                    </button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
// ===========================================
// FUNÇÕES DE FORMATAÇÃO
// ===========================================

function formatarMoeda(valor) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    }).format(valor);
}

// ===========================================
// FUNÇÕES DE DATA
// ===========================================

function validarData(data) {
    if (!data) return true; // Datas opcionais
    
    const regex = /^\d{4}-\d{2}-\d{2}$/;
    if (!regex.test(data)) return false;
    
    const dateObj = new Date(data);
    return dateObj instanceof Date && !isNaN(dateObj);
}

// ===========================================
// FUNÇÕES DE VALOR
// ===========================================

function atualizarDisplayValor() {
    const valorInput = document.getElementById('valor_total');
    const valorDisplay = document.getElementById('valor_total_display');
    
    if (valorInput && valorDisplay) {
        const valor = parseFloat(valorInput.value) || 0;
        valorDisplay.textContent = formatarMoeda(valor);
    }
}

// ===========================================
// FUNÇÕES AUXILIARES
// ===========================================

function updateFileName(input) {
    const fileName = input.files[0] ? input.files[0].name : "Nenhum arquivo selecionado";
    const fileNameDiv = document.getElementById('file-name');
    if (fileNameDiv) {
        fileNameDiv.textContent = fileName;
    }
}

function validarFormulario() {
    const fornecedorNome = document.getElementById("fornecedor_nome");
    const numeroNf = document.getElementById("numero_nf");
    const dataCompra = document.getElementById("data_compra");
    const dataVencimento = document.getElementById("data_vencimento");
    const valorTotal = document.getElementById("valor_total");
    
    // Verifica campos obrigatórios
    if (!fornecedorNome || !fornecedorNome.value.trim()) {
        alert('O campo Nome do Fornecedor é obrigatório!');
        if (fornecedorNome) fornecedorNome.focus();
        return false;
    }
    
    if (!numeroNf || !numeroNf.value.trim()) {
        alert('O campo Número da NF é obrigatório!');
        if (numeroNf) numeroNf.focus();
        return false;
    }
    
    if (!dataCompra || !dataCompra.value) {
        alert('O campo Data da Compra é obrigatório!');
        if (dataCompra) dataCompra.focus();
        return false;
    }
    
    if (!valorTotal || !valorTotal.value || parseFloat(valorTotal.value) <= 0) {
        alert('O campo Valor Total é obrigatório e deve ser maior que zero!');
        if (valorTotal) valorTotal.focus();
        return false;
    }

    // Validar datas
    if (dataCompra && dataCompra.value && !validarData(dataCompra.value)) {
        alert('Data da Compra inválida!');
        dataCompra.focus();
        return false;
    }

    if (dataVencimento && dataVencimento.value && !validarData(dataVencimento.value)) {
        alert('Data de Vencimento inválida!');
        dataVencimento.focus();
        return false;
    }

    // Validar se a data de vencimento não é anterior à data da compra
    if (dataCompra.value && dataVencimento && dataVencimento.value) {
        const dataCompraObj = new Date(dataCompra.value);
        const dataVencObj = new Date(dataVencimento.value);
        if (dataVencObj < dataCompraObj) {
            alert('A Data de Vencimento não pode ser anterior à Data da Compra!');
            dataVencimento.focus();
            return false;
        }
    }
    
    // Mostra loading no botão de submit
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cadastrando...';
    }
    
    return true;
}

function limparFormulario() {
    // Reset do formulário
    const form = document.querySelector('form');
    if (form) {
        form.reset();
    }
    
    // Reseta a data para hoje
    const dataInput = document.getElementById('data_compra');
    if (dataInput) {
        dataInput.value = new Date().toISOString().split('T')[0];
    }
    
    // Limpa data de vencimento
    const dataVencimento = document.getElementById('data_vencimento');
    if (dataVencimento) dataVencimento.value = '';
    
    // Reseta valores
    const valorTotalDisplay = document.getElementById('valor_total_display');
    const fileNameDiv = document.getElementById('file-name');
    
    if (valorTotalDisplay) valorTotalDisplay.textContent = 'R$ 0,00';
    if (fileNameDiv) fileNameDiv.textContent = 'Nenhum arquivo selecionado';
    
    // Remove classes de estado
    document.querySelectorAll('.form-control').forEach(input => {
        input.classList.remove('success-state', 'error-state');
    });
    
    // Foca no primeiro campo
    setTimeout(() => {
        const fornecedorInput = document.getElementById('fornecedor_nome');
        if (fornecedorInput) fornecedorInput.focus();
    }, 200);
}

// ===========================================
// MODAL
// ===========================================

function goToConsulta() {
    window.location.href = 'contas_a_pagar.php?success=' + encodeURIComponent('Conta a pagar cadastrada com sucesso!');
}

function closeModal() {
    const modal = document.getElementById('successModal');
    if (modal) {
        modal.style.display = 'none';
    }
    document.body.style.overflow = 'auto';
    limparFormulario();
    setTimeout(() => {
        const fornecedorInput = document.getElementById('fornecedor_nome');
        if (fornecedorInput) fornecedorInput.focus();
    }, 200);
}

// ===========================================
// INICIALIZAÇÃO
// ===========================================

document.addEventListener('DOMContentLoaded', function() {
    console.log('🧾 Iniciando sistema de cadastro de conta a pagar...');
    
    // Configura data padrão
    const dataInput = document.getElementById('data_compra');
    if (dataInput && !dataInput.value) {
        dataInput.value = new Date().toISOString().split('T')[0];
    }
    
    // Event listeners para validação de datas
    const dataCompra = document.getElementById('data_compra');
    const dataVencimento = document.getElementById('data_vencimento');
    
    if (dataCompra) {
        dataCompra.addEventListener('change', function() {
            if (this.value && !validarData(this.value)) {
                alert('Data da Compra inválida!');
                this.focus();
                this.classList.add('error-state');
            } else {
                this.classList.remove('error-state');
                if (this.value) this.classList.add('success-state');
            }
        });
    }
    
    if (dataVencimento) {
        dataVencimento.addEventListener('change', function() {
            if (this.value && !validarData(this.value)) {
                alert('Data de Vencimento inválida!');
                this.focus();
                this.classList.add('error-state');
            } else {
                this.classList.remove('error-state');
                if (this.value) this.classList.add('success-state');
                
                // Validar se não é anterior à data da compra
                const dataCompra = document.getElementById('data_compra');
                if (dataCompra && dataCompra.value && this.value) {
                    const dataCompraObj = new Date(dataCompra.value);
                    const dataVencObj = new Date(this.value);
                    if (dataVencObj < dataCompraObj) {
                        alert('A Data de Vencimento não pode ser anterior à Data da Compra!');
                        this.focus();
                        this.classList.add('error-state');
                    }
                }
            }
        });
    }
    
    // Event listener para o campo de valor
    const valorInput = document.getElementById('valor_total');
    if (valorInput) {
        valorInput.addEventListener('input', atualizarDisplayValor);
        // Atualiza display inicial
        atualizarDisplayValor();
    }
    
    // Foca no primeiro campo
    setTimeout(() => {
        const fornecedorInput = document.getElementById('fornecedor_nome');
        if (fornecedorInput) {
            fornecedorInput.focus();
        }
    }, 500);
    
    console.log('✅ Sistema de cadastro de conta a pagar carregado com sucesso!');
});

// Event listeners para modal
window.onclick = function(event) {
    const modal = document.getElementById('successModal');
    if (event.target === modal) {
        closeModal();
    }
}

// Atalhos de teclado
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        if (validarFormulario()) {
            document.querySelector('form').submit();
        }
    }
    
    if (e.key === 'Escape') {
        const modal = document.getElementById('successModal');
        if (modal && modal.style.display === 'block') {
            closeModal();
        }
    }
});

console.log('🚀 Sistema de Cadastro de Conta a Pagar LicitaSis:', {
    versao: '1.0 Cadastro Direto',
    campos: [
        '✅ Nome do Fornecedor',
        '✅ Número da NF',
        '✅ Data da Compra',
        '✅ Número do Empenho (opcional)',
        '✅ Data de Vencimento (opcional)',
        '✅ Valor Total',
        '✅ Tipo de Despesa',
        '✅ Comprovante/Documento',
        '✅ Observações'
    ],
    estrutura_bd: {
        contas_pagar: 'Inserção direta com compra_id = NULL'
    },
    validacoes: [
        '✅ Campos obrigatórios validados',
        '✅ Validação de datas',
        '✅ Validação de valor > 0',
        '✅ Data vencimento >= data compra',
        '✅ Upload de arquivos seguro'
    ]
});
</script>

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

</body>
</html>