<?php
// ===========================================
// VALIDATE_EMPENHO_DATA.PHP
// Validações completas de dados do empenho
// Sistema LicitaSis v4.0
// ===========================================

require_once('db.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $errors = [];
    $warnings = [];
    $info = [];
    
    try {
        // ===========================================
        // VALIDAÇÃO DE NÚMERO DO EMPENHO
        // ===========================================
        if (isset($data['numero']) && isset($data['uasg'])) {
            $numero = trim($data['numero']);
            $uasg = trim($data['uasg']);
            
            if (!empty($numero) && !empty($uasg)) {
                // Verifica se empenho já existe
                $sql = "SELECT id, cliente_nome, created_at FROM empenhos WHERE numero = :numero AND cliente_uasg = :uasg";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':numero', $numero);
                $stmt->bindParam(':uasg', $uasg);
                $stmt->execute();
                
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $errors[] = [
                        'field' => 'numero',
                        'message' => "Número do empenho já existe para esta UASG",
                        'details' => [
                            'cliente' => $existing['cliente_nome'],
                            'data_cadastro' => date('d/m/Y H:i', strtotime($existing['created_at']))
                        ]
                    ];
                }
                
                // Validação do formato do número
                if (strlen($numero) < 3) {
                    $warnings[] = [
                        'field' => 'numero',
                        'message' => "Número do empenho muito curto"
                    ];
                } elseif (strlen($numero) > 50) {
                    $errors[] = [
                        'field' => 'numero',
                        'message' => "Número do empenho muito longo (máx. 50 caracteres)"
                    ];
                }
                
                // Verifica se contém apenas caracteres válidos
                if (!preg_match('/^[A-Za-z0-9\-\/\_\.]+$/', $numero)) {
                    $warnings[] = [
                        'field' => 'numero',
                        'message' => "Número contém caracteres especiais"
                    ];
                }
            }
        }
        
        // ===========================================
        // VALIDAÇÃO DE UASG E CLIENTE
        // ===========================================
        if (isset($data['uasg'])) {
            $uasg = trim($data['uasg']);
            
            if (empty($uasg)) {
                $errors[] = [
                    'field' => 'uasg',
                    'message' => "UASG é obrigatória"
                ];
            } else {
                // Verifica se UASG existe no cadastro de clientes
                $sql = "SELECT nome_orgaos, cnpj, endereco, telefone FROM clientes WHERE uasg = :uasg";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':uasg', $uasg);
                $stmt->execute();
                
                $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($cliente) {
                    $info[] = [
                        'field' => 'cliente',
                        'message' => "Cliente encontrado no cadastro",
                        'data' => $cliente
                    ];
                } else {
                    $warnings[] = [
                        'field' => 'uasg',
                        'message' => "UASG não encontrada no cadastro de clientes"
                    ];
                }
                
                // Validação do formato da UASG
                if (!is_numeric($uasg)) {
                    $warnings[] = [
                        'field' => 'uasg',
                        'message' => "UASG deve conter apenas números"
                    ];
                } elseif (strlen($uasg) < 5 || strlen($uasg) > 6) {
                    $warnings[] = [
                        'field' => 'uasg',
                        'message' => "UASG deve ter 5 ou 6 dígitos"
                    ];
                }
            }
        }
        
        // ===========================================
        // VALIDAÇÃO DE DATA DO EMPENHO
        // ===========================================
        if (isset($data['data_empenho'])) {
            $data_empenho = $data['data_empenho'];
            $date = DateTime::createFromFormat('Y-m-d', $data_empenho);
            
            if (!$date || $date->format('Y-m-d') !== $data_empenho) {
                $errors[] = [
                    'field' => 'data_empenho',
                    'message' => "Data do empenho inválida"
                ];
            } else {
                $hoje = new DateTime();
                $diff = $hoje->diff($date);
                
                if ($date > $hoje) {
                    $warnings[] = [
                        'field' => 'data_empenho',
                        'message' => "Data do empenho é futura",
                        'details' => [
                            'dias_futuro' => $diff->days
                        ]
                    ];
                } elseif ($diff->days > 365) {
                    $warnings[] = [
                        'field' => 'data_empenho',
                        'message' => "Data do empenho é muito antiga (mais de 1 ano)",
                        'details' => [
                            'dias_passados' => $diff->days
                        ]
                    ];
                } elseif ($diff->days > 90) {
                    $info[] = [
                        'field' => 'data_empenho',
                        'message' => "Empenho com mais de 90 dias",
                        'details' => [
                            'dias_passados' => $diff->days
                        ]
                    ];
                }
                
                // Verifica se é dia útil (opcional)
                $dia_semana = $date->format('N'); // 1 = segunda, 7 = domingo
                if ($dia_semana > 5) {
                    $info[] = [
                        'field' => 'data_empenho',
                        'message' => "Data do empenho é final de semana"
                    ];
                }
            }
        }
        
        // ===========================================
        // VALIDAÇÃO DE PRODUTOS
        // ===========================================
        $total_produtos = 0;
        $valor_total = 0;
        
        if (isset($data['produtos']) && is_array($data['produtos'])) {
            $total_produtos = count($data['produtos']);
            
            if ($total_produtos === 0) {
                $errors[] = [
                    'field' => 'produtos',
                    'message' => "Pelo menos um produto deve ser adicionado"
                ];
            } else {
                foreach ($data['produtos'] as $index => $produto) {
                    $produto_num = $index + 1;
                    
                    // Validação do nome do produto
                    if (empty(trim($produto['nome'] ?? ''))) {
                        $errors[] = [
                            'field' => "produto_{$index}_nome",
                            'message' => "Nome do produto {$produto_num} é obrigatório"
                        ];
                    } else {
                        $nome_produto = trim($produto['nome']);
                        if (strlen($nome_produto) < 3) {
                            $warnings[] = [
                                'field' => "produto_{$index}_nome",
                                'message' => "Nome do produto {$produto_num} muito curto"
                            ];
                        } elseif (strlen($nome_produto) > 255) {
                            $errors[] = [
                                'field' => "produto_{$index}_nome",
                                'message' => "Nome do produto {$produto_num} muito longo (máx. 255 caracteres)"
                            ];
                        }
                    }
                    
                    // Validação da quantidade
                    $quantidade = floatval($produto['quantidade'] ?? 0);
                    if ($quantidade <= 0) {
                        $errors[] = [
                            'field' => "produto_{$index}_quantidade",
                            'message' => "Quantidade do produto {$produto_num} deve ser maior que zero"
                        ];
                    } elseif ($quantidade > 999999) {
                        $warnings[] = [
                            'field' => "produto_{$index}_quantidade",
                            'message' => "Quantidade do produto {$produto_num} muito alta"
                        ];
                    } elseif (fmod($quantidade, 1) !== 0.0 && $quantidade < 1) {
                        $info[] = [
                            'field' => "produto_{$index}_quantidade",
                            'message' => "Produto {$produto_num} com quantidade fracionária"
                        ];
                    }
                    
                    // Validação do valor unitário
                    $valor_unitario = floatval($produto['valor_unitario'] ?? 0);
                    if ($valor_unitario <= 0) {
                        $errors[] = [
                            'field' => "produto_{$index}_valor_unitario",
                            'message' => "Valor unitário do produto {$produto_num} deve ser maior que zero"
                        ];
                    } elseif ($valor_unitario > 1000000) {
                        $warnings[] = [
                            'field' => "produto_{$index}_valor_unitario",
                            'message' => "Valor unitário do produto {$produto_num} muito alto"
                        ];
                    } elseif ($valor_unitario < 0.01) {
                        $warnings[] = [
                            'field' => "produto_{$index}_valor_unitario",
                            'message' => "Valor unitário do produto {$produto_num} muito baixo"
                        ];
                    }
                    
                    $valor_produto = $quantidade * $valor_unitario;
                    $valor_total += $valor_produto;
                    
                    // Verifica se produto está cadastrado no sistema
                    if (!empty($produto['produto_id'])) {
                        $produto_id = intval($produto['produto_id']);
                        
                        $sql = "SELECT 
                                    nome, 
                                    preco_unitario, 
                                    preco_venda,
                                    custo_total,
                                    estoque_atual, 
                                    estoque_minimo,
                                    controla_estoque,
                                    categoria,
                                    unidade
                                FROM produtos 
                                WHERE id = :id";
                        $stmt = $pdo->prepare($sql);
                        $stmt->bindParam(':id', $produto_id);
                        $stmt->execute();
                        
                        $produto_db = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($produto_db) {
                            // Verifica controle de estoque
                            if ($produto_db['controla_estoque']) {
                                $estoque = floatval($produto_db['estoque_atual']);
                                if ($quantidade > $estoque) {
                                    if ($estoque <= 0) {
                                        $errors[] = [
                                            'field' => "produto_{$index}_estoque",
                                            'message' => "Produto '{$produto_db['nome']}' sem estoque disponível"
                                        ];
                                    } else {
                                        $warnings[] = [
                                            'field' => "produto_{$index}_estoque",
                                            'message' => "Produto '{$produto_db['nome']}': quantidade solicitada ({$quantidade}) maior que estoque disponível ({$estoque})"
                                        ];
                                    }
                                } elseif (($estoque - $quantidade) <= $produto_db['estoque_minimo']) {
                                    $info[] = [
                                        'field' => "produto_{$index}_estoque",
                                        'message' => "Produto '{$produto_db['nome']}' ficará com estoque baixo após este empenho"
                                    ];
                                }
                            }
                            
                            // Compara preços
                            $preco_cadastrado = floatval($produto_db['preco_venda'] ?: $produto_db['preco_unitario']);
                            if ($preco_cadastrado > 0) {
                                $diferenca_percentual = (($valor_unitario - $preco_cadastrado) / $preco_cadastrado) * 100;
                                
                                if (abs($diferenca_percentual) > 20) {
                                    $tipo_diferenca = $diferenca_percentual > 0 ? 'maior' : 'menor';
                                    $warnings[] = [
                                        'field' => "produto_{$index}_preco",
                                        'message' => "Preço do produto '{$produto_db['nome']}' {$diferenca_percentual:+.1f}% {$tipo_diferenca} que o cadastrado",
                                        'details' => [
                                            'preco_cadastrado' => $preco_cadastrado,
                                            'preco_informado' => $valor_unitario,
                                            'diferenca_percentual' => $diferenca_percentual
                                        ]
                                    ];
                                }
                            }
                            
                            // Análise de lucratividade
                            $custo_total = floatval($produto_db['custo_total']);
                            if ($custo_total > 0) {
                                $margem = (($valor_unitario - $custo_total) / $valor_unitario) * 100;
                                
                                if ($margem < 0) {
                                    $warnings[] = [
                                        'field' => "produto_{$index}_margem",
                                        'message' => "Produto '{$produto_db['nome']}' com margem negativa ({$margem:.1f}%)"
                                    ];
                                } elseif ($margem < 5) {
                                    $info[] = [
                                        'field' => "produto_{$index}_margem",
                                        'message' => "Produto '{$produto_db['nome']}' com margem baixa ({$margem:.1f}%)"
                                    ];
                                } elseif ($margem > 100) {
                                    $info[] = [
                                        'field' => "produto_{$index}_margem",
                                        'message' => "Produto '{$produto_db['nome']}' com margem muito alta ({$margem:.1f}%)"
                                    ];
                                }
                            }
                        } else {
                            $warnings[] = [
                                'field' => "produto_{$index}_id",
                                'message' => "Produto {$produto_num} não encontrado no cadastro"
                            ];
                        }
                    }
                }
                
                // Validação do valor total
                if ($valor_total > 10000000) { // R$ 10 milhões
                    $warnings[] = [
                        'field' => 'valor_total',
                        'message' => "Valor total do empenho muito alto: R$ " . number_format($valor_total, 2, ',', '.'),
                        'details' => ['valor_total' => $valor_total]
                    ];
                } elseif ($valor_total < 1) {
                    $errors[] = [
                        'field' => 'valor_total',
                        'message' => "Valor total do empenho deve ser maior que R$ 1,00"
                    ];
                } elseif ($valor_total > 1000000) { // R$ 1 milhão
                    $info[] = [
                        'field' => 'valor_total',
                        'message' => "Empenho de alto valor: R$ " . number_format($valor_total, 2, ',', '.'),
                        'details' => ['valor_total' => $valor_total]
                    ];
                }
                
                // Verifica duplicatas de produtos
                $nomes_produtos = array_map(function($p) { 
                    return strtolower(trim($p['nome'] ?? '')); 
                }, $data['produtos']);
                $duplicatas = array_count_values($nomes_produtos);
                
                foreach ($duplicatas as $nome => $count) {
                    if ($count > 1 && !empty($nome)) {
                        $warnings[] = [
                            'field' => 'produtos_duplicados',
                            'message' => "Produto '{$nome}' aparece {$count} vezes na lista"
                        ];
                    }
                }
            }
        }
        
        // ===========================================
        // VALIDAÇÃO DE CLASSIFICAÇÃO
        // ===========================================
        if (isset($data['classificacao'])) {
            $classificacoes_validas = ['Pendente', 'Faturado', 'Entregue', 'Liquidado', 'Pago', 'Cancelado'];
            if (!in_array($data['classificacao'], $classificacoes_validas)) {
                $errors[] = [
                    'field' => 'classificacao',
                    'message' => "Classificação inválida",
                    'details' => ['opcoes_validas' => $classificacoes_validas]
                ];
            }
        }
        
        // ===========================================
        // VALIDAÇÃO DE PRIORIDADE
        // ===========================================
        if (isset($data['prioridade'])) {
            $prioridades_validas = ['Normal', 'Alta', 'Urgente'];
            if (!in_array($data['prioridade'], $prioridades_validas)) {
                $warnings[] = [
                    'field' => 'prioridade',
                    'message' => "Prioridade inválida, usando 'Normal'",
                    'details' => ['opcoes_validas' => $prioridades_validas]
                ];
            }
        }
        
        // ===========================================
        // VALIDAÇÃO DE PREGÃO
        // ===========================================
        if (isset($data['pregao']) && !empty($data['pregao'])) {
            $pregao = trim($data['pregao']);
            
            // Verifica se já existe empenho com mesmo pregão e UASG
            if (isset($data['uasg']) && !empty($data['uasg'])) {
                $sql = "SELECT COUNT(*) as count FROM empenhos WHERE pregao = :pregao AND cliente_uasg = :uasg";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':pregao', $pregao);
                $stmt->bindParam(':uasg', $data['uasg']);
                $stmt->execute();
                
                $count = $stmt->fetchColumn();
                if ($count > 0) {
                    $info[] = [
                        'field' => 'pregao',
                        'message' => "Já existem {$count} empenho(s) com este pregão para esta UASG"
                    ];
                }
            }
        }
        
        // ===========================================
        // VALIDAÇÃO DE OBSERVAÇÕES
        // ===========================================
        if (isset($data['observacao']) && !empty($data['observacao'])) {
            $observacao = trim($data['observacao']);
            if (strlen($observacao) > 1000) {
                $warnings[] = [
                    'field' => 'observacao',
                    'message' => "Observação muito longa (máx. 1000 caracteres)"
                ];
            }
        }
        
        // ===========================================
        // ANÁLISES ESTATÍSTICAS COMPLEMENTARES
        // ===========================================
        
        // Análise de histórico do cliente
        if (isset($data['uasg']) && !empty($data['uasg'])) {
            $sql = "SELECT 
                        COUNT(*) as total_empenhos,
                        AVG(valor_total_empenho) as valor_medio,
                        MAX(valor_total_empenho) as valor_maximo,
                        MIN(created_at) as primeiro_empenho
                    FROM empenhos 
                    WHERE cliente_uasg = :uasg";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':uasg', $data['uasg']);
            $stmt->execute();
            
            $historico = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($historico && $historico['total_empenhos'] > 0) {
                $info[] = [
                    'field' => 'historico_cliente',
                    'message' => "Cliente possui histórico no sistema",
                    'details' => [
                        'total_empenhos' => $historico['total_empenhos'],
                        'valor_medio' => floatval($historico['valor_medio']),
                        'valor_maximo' => floatval($historico['valor_maximo']),
                        'cliente_desde' => $historico['primeiro_empenho']
                    ]
                ];
                
                // Compara valor atual com histórico
                if ($valor_total > 0 && $historico['valor_medio'] > 0) {
                    $diferenca_media = (($valor_total - $historico['valor_medio']) / $historico['valor_medio']) * 100;
                    
                    if (abs($diferenca_media) > 50) {
                        $tipo = $diferenca_media > 0 ? 'maior' : 'menor';
                        $info[] = [
                            'field' => 'comparacao_historica',
                            'message' => "Valor {$diferenca_media:+.1f}% {$tipo} que a média histórica do cliente"
                        ];
                    }
                }
            }
        }
        
        // ===========================================
        // GERA RESUMO FINAL
        // ===========================================
        $resumo = [
            'total_errors' => count($errors),
            'total_warnings' => count($warnings),
            'total_info' => count($info),
            'produtos_count' => $total_produtos,
            'valor_total' => $valor_total,
            'valor_total_formatado' => 'R$ ' . number_format($valor_total, 2, ',', '.'),
            'validacao_completa' => count($errors) === 0,
            'requer_atencao' => count($warnings) > 0,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Adiciona score de qualidade
        $score = 100;
        $score -= count($errors) * 25; // Erros reduzem muito o score
        $score -= count($warnings) * 5; // Warnings reduzem menos
        $score = max(0, $score);
        
        $resumo['quality_score'] = $score;
        $resumo['quality_level'] = $score >= 90 ? 'Excelente' : 
                                   ($score >= 70 ? 'Bom' : 
                                   ($score >= 50 ? 'Regular' : 'Precisa melhorar'));
        
        echo json_encode([
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info,
            'summary' => $resumo
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'valid' => false,
            'errors' => [
                [
                    'field' => 'database',
                    'message' => 'Erro de conexão com banco de dados',
                    'details' => ['error_code' => $e->getCode()]
                ]
            ],
            'warnings' => [],
            'info' => [],
            'summary' => [
                'total_errors' => 1,
                'total_warnings' => 0,
                'total_info' => 0,
                'validacao_completa' => false
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'valid' => false,
            'errors' => [
                [
                    'field' => 'system',
                    'message' => 'Erro interno do sistema',
                    'details' => ['message' => $e->getMessage()]
                ]
            ],
            'warnings' => [],
            'info' => [],
            'summary' => [
                'total_errors' => 1,
                'total_warnings' => 0,
                'total_info' => 0,
                'validacao_completa' => false
            ]
        ]);
    }
    
} else {
    http_response_code(405);
    echo json_encode([
        'valid' => false,
        'errors' => [
            [
                'field' => 'method',
                'message' => 'Método de requisição inválido. Use POST.'
            ]
        ],
        'warnings' => [],
        'info' => [],
        'summary' => [
            'total_errors' => 1,
            'total_warnings' => 0,
            'total_info' => 0,
            'validacao_completa' => false
        ]
    ]);
}
?>