<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Usuário não logado']);
    exit();
}

// Conexão com o banco de dados
require_once('db.php');

// Função para registrar log
function logClienteAccess($action, $details = '') {
    if (function_exists('logUserAction')) {
        logUserAction($action, $details);
    }
    error_log("fetch_cliente_data.php - {$action}: {$details}");
}

// Verifica se o parâmetro UASG foi fornecido
if (!isset($_GET['uasg']) || empty(trim($_GET['uasg']))) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'UASG é obrigatória'
    ]);
    exit();
}

$uasg = trim($_GET['uasg']);

// Validação básica da UASG
if (!preg_match('/^[0-9]{6}$/', $uasg)) {
    echo json_encode([
        'success' => false,
        'message' => 'UASG deve conter exatamente 6 dígitos numéricos',
        'uasg_fornecida' => $uasg
    ]);
    exit();
}

try {
    // Log da consulta
    logClienteAccess('FETCH_CLIENT', "UASG: {$uasg}");
    
    // Consulta principal para buscar dados do cliente
    $sql = "SELECT 
                c.id,
                c.uasg,
                c.nome_orgaos,
                c.cnpj,
                c.email,
                c.telefone,
                c.endereco,
                c.cidade,
                c.estado,
                c.cep,
                c.responsavel,
                c.cargo_responsavel,
                c.created_at,
                c.updated_at,
                COUNT(e.id) as total_empenhos,
                COALESCE(SUM(e.valor_total_empenho), 0) as valor_total_empenhos
            FROM clientes c
            LEFT JOIN empenhos e ON c.uasg = e.cliente_uasg
            WHERE c.uasg = :uasg
            GROUP BY c.id
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':uasg', $uasg, PDO::PARAM_STR);
    $stmt->execute();
    
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cliente) {
        // Cliente não encontrado - busca em uma possível tabela de UASG conhecidas
        $sqlUasgLookup = "SELECT uasg, nome_orgao FROM uasg_lookup WHERE uasg = :uasg LIMIT 1";
        
        try {
            $stmtLookup = $pdo->prepare($sqlUasgLookup);
            $stmtLookup->bindParam(':uasg', $uasg, PDO::PARAM_STR);
            $stmtLookup->execute();
            $uasgInfo = $stmtLookup->fetch(PDO::FETCH_ASSOC);
            
            if ($uasgInfo) {
                logClienteAccess('UASG_FOUND_LOOKUP', "UASG {$uasg} encontrada em lookup: {$uasgInfo['nome_orgao']}");
                
                echo json_encode([
                    'success' => false,
                    'message' => 'UASG encontrada mas cliente não cadastrado',
                    'uasg_info' => [
                        'uasg' => $uasgInfo['uasg'],
                        'nome_sugerido' => $uasgInfo['nome_orgao']
                    ],
                    'sugestao' => 'Deseja cadastrar este cliente?'
                ]);
                exit();
            }
        } catch (PDOException $e) {
            // Tabela de lookup pode não existir
            error_log("Tabela uasg_lookup não existe ou erro: " . $e->getMessage());
        }
        
        logClienteAccess('CLIENT_NOT_FOUND', "UASG: {$uasg}");
        
        echo json_encode([
            'success' => false,
            'message' => 'UASG não encontrada no sistema',
            'uasg_consultada' => $uasg,
            'sugestao' => 'Verifique se a UASG está correta ou cadastre um novo cliente'
        ]);
        exit();
    }
    
    // Cliente encontrado - formatar dados para resposta
    $response = [
        'success' => true,
        'uasg' => $cliente['uasg'],
        'nome_orgaos' => $cliente['nome_orgaos'],
        'cnpj' => $cliente['cnpj'],
        'email' => $cliente['email'],
        'telefone' => $cliente['telefone'],
        'endereco_completo' => [
            'endereco' => $cliente['endereco'],
            'cidade' => $cliente['cidade'],
            'estado' => $cliente['estado'],
            'cep' => $cliente['cep']
        ],
        'responsavel' => [
            'nome' => $cliente['responsavel'],
            'cargo' => $cliente['cargo_responsavel']
        ],
        'estatisticas' => [
            'total_empenhos' => intval($cliente['total_empenhos']),
            'valor_total_empenhos' => floatval($cliente['valor_total_empenhos']),
            'valor_total_formatado' => 'R$ ' . number_format(floatval($cliente['valor_total_empenhos']), 2, ',', '.')
        ],
        'datas' => [
            'cadastrado_em' => $cliente['created_at'],
            'atualizado_em' => $cliente['updated_at'],
            'cadastrado_em_formatado' => $cliente['created_at'] ? date('d/m/Y H:i', strtotime($cliente['created_at'])) : null,
            'atualizado_em_formatado' => $cliente['updated_at'] ? date('d/m/Y H:i', strtotime($cliente['updated_at'])) : null
        ]
    ];
    
    // Busca últimos empenhos do cliente para contexto adicional
    if ($cliente['total_empenhos'] > 0) {
        $sqlUltimosEmpenhos = "SELECT 
                                   numero, 
                                   valor_total_empenho, 
                                   classificacao,
                                   created_at
                               FROM empenhos 
                               WHERE cliente_uasg = :uasg 
                               ORDER BY created_at DESC 
                               LIMIT 3";
        
        $stmtUltimos = $pdo->prepare($sqlUltimosEmpenhos);
        $stmtUltimos->bindParam(':uasg', $uasg, PDO::PARAM_STR);
        $stmtUltimos->execute();
        $ultimosEmpenhos = $stmtUltimos->fetchAll(PDO::FETCH_ASSOC);
        
        $response['ultimos_empenhos'] = array_map(function($empenho) {
            return [
                'numero' => $empenho['numero'],
                'valor' => floatval($empenho['valor_total_empenho']),
                'valor_formatado' => 'R$ ' . number_format(floatval($empenho['valor_total_empenho']), 2, ',', '.'),
                'classificacao' => $empenho['classificacao'],
                'data' => $empenho['created_at'],
                'data_formatada' => date('d/m/Y', strtotime($empenho['created_at']))
            ];
        }, $ultimosEmpenhos);
    }
    
    // Verifica se há pendências ou alertas para este cliente
    $alertas = [];
    
    // Alerta: Cliente sem email
    if (empty($cliente['email'])) {
        $alertas[] = [
            'tipo' => 'warning',
            'message' => 'Cliente não possui email cadastrado'
        ];
    }
    
    // Alerta: Cliente sem telefone
    if (empty($cliente['telefone'])) {
        $alertas[] = [
            'tipo' => 'warning',
            'message' => 'Cliente não possui telefone cadastrado'
        ];
    }
    
    // Alerta: CNPJ inválido
    if (!empty($cliente['cnpj']) && !validarCNPJ($cliente['cnpj'])) {
        $alertas[] = [
            'tipo' => 'error',
            'message' => 'CNPJ cadastrado é inválido'
        ];
    }
    
    // Alerta: Cliente inativo (sem empenhos há muito tempo)
    if ($cliente['total_empenhos'] > 0) {
        $sqlUltimoEmpenho = "SELECT created_at FROM empenhos WHERE cliente_uasg = :uasg ORDER BY created_at DESC LIMIT 1";
        $stmtUltimo = $pdo->prepare($sqlUltimoEmpenho);
        $stmtUltimo->bindParam(':uasg', $uasg, PDO::PARAM_STR);
        $stmtUltimo->execute();
        $ultimoEmpenho = $stmtUltimo->fetch(PDO::FETCH_ASSOC);
        
        if ($ultimoEmpenho) {
            $diasSemEmpenho = (strtotime('now') - strtotime($ultimoEmpenho['created_at'])) / (60 * 60 * 24);
            
            if ($diasSemEmpenho > 365) {
                $alertas[] = [
                    'tipo' => 'info',
                    'message' => 'Cliente sem empenhos há mais de 1 ano'
                ];
            } elseif ($diasSemEmpenho > 180) {
                $alertas[] = [
                    'tipo' => 'info',
                    'message' => 'Cliente sem empenhos há mais de 6 meses'
                ];
            }
        }
    }
    
    if (!empty($alertas)) {
        $response['alertas'] = $alertas;
    }
    
    // Log de sucesso
    logClienteAccess('CLIENT_FOUND', "UASG: {$uasg}, Cliente: {$cliente['nome_orgaos']}, Empenhos: {$cliente['total_empenhos']}");
    
    // Headers
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    logClienteAccess('ERROR', "Erro de banco: " . $e->getMessage());
    error_log("Erro ao buscar dados do cliente UASG {$uasg}: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno do servidor',
        'details' => 'Erro de banco de dados. Consulte os logs.',
        'uasg_consultada' => $uasg
    ]);
    
} catch (Exception $e) {
    logClienteAccess('ERROR', "Erro geral: " . $e->getMessage());
    error_log("Erro geral ao buscar cliente UASG {$uasg}: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno do servidor',
        'details' => $e->getMessage(),
        'uasg_consultada' => $uasg
    ]);
}

// Função auxiliar para validar CNPJ
function validarCNPJ($cnpj) {
    // Remove caracteres não numéricos
    $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
    
    // Verifica se tem 14 dígitos
    if (strlen($cnpj) != 14) {
        return false;
    }
    
    // Verifica se não é uma sequência de números iguais
    if (preg_match('/(\d)\1{13}/', $cnpj)) {
        return false;
    }
    
    // Validação do algoritmo do CNPJ
    $soma = 0;
    $multiplicador = 5;
    
    for ($i = 0; $i < 12; $i++) {
        $soma += $cnpj[$i] * $multiplicador;
        $multiplicador = ($multiplicador == 2) ? 9 : $multiplicador - 1;
    }
    
    $resto = $soma % 11;
    $digito1 = ($resto < 2) ? 0 : 11 - $resto;
    
    if ($cnpj[12] != $digito1) {
        return false;
    }
    
    $soma = 0;
    $multiplicador = 6;
    
    for ($i = 0; $i < 13; $i++) {
        $soma += $cnpj[$i] * $multiplicador;
        $multiplicador = ($multiplicador == 2) ? 9 : $multiplicador - 1;
    }
    
    $resto = $soma % 11;
    $digito2 = ($resto < 2) ? 0 : 11 - $resto;
    
    return $cnpj[13] == $digito2;
}

// Função auxiliar para formatar CNPJ
function formatarCNPJ($cnpj) {
    $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
    
    if (strlen($cnpj) == 14) {
        return substr($cnpj, 0, 2) . '.' . 
               substr($cnpj, 2, 3) . '.' . 
               substr($cnpj, 5, 3) . '/' . 
               substr($cnpj, 8, 4) . '-' . 
               substr($cnpj, 12, 2);
    }
    
    return $cnpj;
}

// Função auxiliar para formatar telefone
function formatarTelefone($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    
    if (strlen($telefone) == 11) {
        return '(' . substr($telefone, 0, 2) . ') ' . 
               substr($telefone, 2, 5) . '-' . 
               substr($telefone, 7, 4);
    } elseif (strlen($telefone) == 10) {
        return '(' . substr($telefone, 0, 2) . ') ' . 
               substr($telefone, 2, 4) . '-' . 
               substr($telefone, 6, 4);
    }
    
    return $telefone;
}
?>