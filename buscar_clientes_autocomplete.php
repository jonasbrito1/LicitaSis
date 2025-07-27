<?php
session_start();

// Debug inicial
error_log("=== BUSCAR CLIENTES AUTOCOMPLETE DEBUG ===");
error_log("REQUEST: " . print_r($_REQUEST, true));

if (!isset($_SESSION['user'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Usuário não autenticado'], JSON_UNESCAPED_UNICODE);
    exit();
}

require_once('db.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

try {
    $termo = trim($_GET['termo'] ?? '');
    $limit = min(max((int)($_GET['limit'] ?? 8), 1), 20);
    
    error_log("Termo de busca: '$termo', Limit: $limit");
    
    if (strlen($termo) < 2) {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    $termoBusca = "%$termo%";
    
    $sql = "SELECT 
                id, uasg, cnpj, nome_orgaos, telefone, email, endereco, telefone2, email2
            FROM clientes 
            WHERE (nome_orgaos LIKE ? OR uasg LIKE ? OR cnpj LIKE ?)
            ORDER BY nome_orgaos ASC
            LIMIT ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$termoBusca, $termoBusca, $termoBusca, $limit]);
    
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Clientes encontrados: " . count($clientes));
    
    // Formata os dados para o frontend
    $clientesFormatados = [];
    
    foreach ($clientes as $cliente) {
        // Formata CNPJ
        $cnpjFormatado = '';
        if ($cliente['cnpj']) {
            $cnpjNumeros = preg_replace('/\D/', '', $cliente['cnpj']);
            if (strlen($cnpjNumeros) === 14) {
                $cnpjFormatado = preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpjNumeros);
            } else {
                $cnpjFormatado = $cliente['cnpj'];
            }
        }
        
        // Formata telefone
        $telefoneFormatado = '';
        if ($cliente['telefone']) {
            $telefoneNumeros = preg_replace('/\D/', '', $cliente['telefone']);
            if (strlen($telefoneNumeros) === 11) {
                $telefoneFormatado = preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $telefoneNumeros);
            } elseif (strlen($telefoneNumeros) === 10) {
                $telefoneFormatado = preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $telefoneNumeros);
            } else {
                $telefoneFormatado = $cliente['telefone'];
            }
        }
        
        // Cria o objeto cliente formatado
        $clienteFormatado = [
            'id' => $cliente['id'],
            'nome' => $cliente['nome_orgaos'],
            'uasg' => $cliente['uasg'],
            'cnpj' => $cliente['cnpj'],
            'cnpj_formatado' => $cnpjFormatado,
            'telefone' => $cliente['telefone'],
            'telefone_formatado' => $telefoneFormatado,
            'telefone2' => $cliente['telefone2'] ?? '',
            'email' => $cliente['email'] ?? '',
            'email2' => $cliente['email2'] ?? '',
            'endereco' => $cliente['endereco'] ?? '',
            // Campo display usado pelo JavaScript para preencher o input
            'display' => $cliente['nome_orgaos'] . ' (' . $cliente['uasg'] . ')'
        ];
        
        $clientesFormatados[] = $clienteFormatado;
    }
    
    error_log("Clientes formatados: " . count($clientesFormatados));
    
    // Retorna apenas o array de clientes (sem wrapper)
    echo json_encode($clientesFormatados, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erro na busca de clientes: " . $e->getMessage());
    echo json_encode(['error' => 'Erro interno: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>