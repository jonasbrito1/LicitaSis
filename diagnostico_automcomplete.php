<?php
// ===========================================
// DIAGNÓSTICO DO SISTEMA DE AUTOCOMPLETE
// Use este arquivo para diagnosticar problemas
// ===========================================

session_start();

// Headers
header('Content-Type: text/html; charset=utf-8');

// Função para verificar arquivos
function verificarArquivo($caminho) {
    if (file_exists($caminho)) {
        return ['status' => 'OK', 'tamanho' => filesize($caminho), 'permissao' => substr(sprintf('%o', fileperms($caminho)), -4)];
    } else {
        return ['status' => 'ERRO', 'erro' => 'Arquivo não encontrado'];
    }
}

// Função para testar conexão com banco
function testarBanco() {
    try {
        $caminhos_db = ['db.php', '../db.php', './includes/db.php'];
        $db_encontrado = false;
        
        foreach ($caminhos_db as $caminho) {
            if (file_exists($caminho)) {
                require_once($caminho);
                $db_encontrado = true;
                break;
            }
        }
        
        if (!$db_encontrado) {
            return ['status' => 'ERRO', 'erro' => 'Arquivo db.php não encontrado'];
        }
        
        if (!isset($pdo) || !$pdo instanceof PDO) {
            return ['status' => 'ERRO', 'erro' => 'Variável $pdo não definida ou inválida'];
        }
        
        // Testa query simples
        $stmt = $pdo->query("SELECT 1 as teste");
        $result = $stmt->fetch();
        
        if ($result && $result['teste'] == 1) {
            return ['status' => 'OK', 'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)];
        } else {
            return ['status' => 'ERRO', 'erro' => 'Query de teste falhou'];
        }
        
    } catch (Exception $e) {
        return ['status' => 'ERRO', 'erro' => $e->getMessage()];
    }
}

// Função para testar tabela produtos
function testarTabelaProdutos() {
    try {
        $caminhos_db = ['db.php', '../db.php', './includes/db.php'];
        foreach ($caminhos_db as $caminho) {
            if (file_exists($caminho)) {
                require_once($caminho);
                break;
            }
        }
        
        if (!isset($pdo)) {
            return ['status' => 'ERRO', 'erro' => 'Conexão com banco não disponível'];
        }
        
        // Verifica se tabela existe
        $stmt = $pdo->query("SHOW TABLES LIKE 'produtos'");
        if ($stmt->rowCount() == 0) {
            return ['status' => 'ERRO', 'erro' => 'Tabela produtos não existe'];
        }
        
        // Conta registros
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM produtos");
        $result = $stmt->fetch();
        
        // Verifica estrutura da tabela
        $stmt = $pdo->query("DESCRIBE produtos");
        $colunas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return [
            'status' => 'OK', 
            'total_produtos' => $result['total'],
            'colunas' => $colunas
        ];
        
    } catch (Exception $e) {
        return ['status' => 'ERRO', 'erro' => $e->getMessage()];
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico do Sistema de Autocomplete</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .status-ok { color: #28a745; font-weight: bold; }
        .status-erro { color: #dc3545; font-weight: bold; }
        .status-aviso { color: #ffc107; font-weight: bold; }
        .section {
            margin: 20px 0;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f9f9f9;
        }
        .section h3 {
            margin-top: 0;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        table th, table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        table th {
            background: #f0f0f0;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 5px;
        }
        .btn:hover {
            background: #0056b3;
        }
        .log-box {
            background: #1e1e1e;
            color: #00ff00;
            padding: 15px;
            border-radius: 5px;
            font-family: monospace;
            max-height: 300px;
            overflow-y: auto;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Diagnóstico do Sistema de Autocomplete</h1>
        <p>Este diagnóstico verifica se todos os componentes necessários estão funcionando corretamente.</p>
        
        <!-- VERIFICAÇÃO DE SESSÃO -->
        <div class="section">
            <h3>🔐 Verificação de Sessão</h3>
            <?php if (isset($_SESSION['user'])): ?>
                <p><span class="status-ok">✅ OK</span> - Usuário logado: <?= htmlspecialchars($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? 'Usuário') ?></p>
            <?php else: ?>
                <p><span class="status-erro">❌ ERRO</span> - Usuário não está logado</p>
                <p><strong>Solução:</strong> Faça login no sistema antes de usar o autocomplete.</p>
            <?php endif; ?>
        </div>

        <!-- VERIFICAÇÃO DE ARQUIVOS -->
        <div class="section">
            <h3>📁 Verificação de Arquivos</h3>
            <table>
                <tr>
                    <th>Arquivo</th>
                    <th>Status</th>
                    <th>Detalhes</th>
                </tr>
                <?php
                $arquivos = [
                    'buscar_produtos_autocomplete.php' => 'Arquivo principal de busca',
                    'db.php' => 'Conexão com banco',
                    'consulta_empenho.php' => 'Página principal',
                    'gerenciar_produtos_empenho.php' => 'API de gestão de produtos'
                ];
                
                foreach ($arquivos as $arquivo => $descricao) {
                    $resultado = verificarArquivo($arquivo);
                    echo "<tr>";
                    echo "<td>{$arquivo}<br><small>{$descricao}</small></td>";
                    
                    if ($resultado['status'] == 'OK') {
                        echo "<td><span class='status-ok'>✅ OK</span></td>";
                        echo "<td>Tamanho: " . number_format($resultado['tamanho']) . " bytes<br>Permissão: {$resultado['permissao']}</td>";
                    } else {
                        echo "<td><span class='status-erro'>❌ ERRO</span></td>";
                        echo "<td>{$resultado['erro']}</td>";
                    }
                    echo "</tr>";
                }
                ?>
            </table>
        </div>

        <!-- VERIFICAÇÃO DE BANCO -->
        <div class="section">
            <h3>🗄️ Verificação de Banco de Dados</h3>
            <?php
            $banco = testarBanco();
            if ($banco['status'] == 'OK'):
            ?>
                <p><span class="status-ok">✅ OK</span> - Conexão com banco funcionando</p>
                <p><strong>Driver:</strong> <?= $banco['driver'] ?></p>
            <?php else: ?>
                <p><span class="status-erro">❌ ERRO</span> - <?= $banco['erro'] ?></p>
                <p><strong>Soluções possíveis:</strong></p>
                <ul>
                    <li>Verificar se o arquivo db.php existe</li>
                    <li>Verificar credenciais do banco de dados</li>
                    <li>Verificar se o servidor MySQL/MariaDB está rodando</li>
                </ul>
            <?php endif; ?>
        </div>

        <!-- VERIFICAÇÃO DE TABELA PRODUTOS -->
        <div class="section">
            <h3>📦 Verificação da Tabela Produtos</h3>
            <?php
            $produtos = testarTabelaProdutos();
            if ($produtos['status'] == 'OK'):
            ?>
                <p><span class="status-ok">✅ OK</span> - Tabela produtos encontrada</p>
                <p><strong>Total de produtos:</strong> <?= number_format($produtos['total_produtos']) ?></p>
                
                <?php if ($produtos['total_produtos'] == 0): ?>
                    <p><span class="status-aviso">⚠️ AVISO</span> - Nenhum produto cadastrado</p>
                    <p><strong>Solução:</strong> Cadastre alguns produtos para testar o autocomplete.</p>
                <?php endif; ?>
                
                <details>
                    <summary>Ver estrutura da tabela</summary>
                    <div class="log-box">
                        <?php foreach ($produtos['colunas'] as $coluna): ?>
                            <?= htmlspecialchars($coluna) ?><br>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php else: ?>
                <p><span class="status-erro">❌ ERRO</span> - <?= $produtos['erro'] ?></p>
            <?php endif; ?>
        </div>

        <!-- TESTE DE REQUISIÇÃO AJAX -->
        <div class="section">
            <h3>🌐 Teste de Requisição AJAX</h3>
            <p>Clique no botão abaixo para testar a requisição AJAX do autocomplete:</p>
            <button onclick="testarAjax()" class="btn">🧪 Testar Busca</button>
            <div id="resultado-ajax" style="margin-top: 10px;"></div>
        </div>

        <!-- CONFIGURAÇÕES DO SERVIDOR -->
        <div class="section">
            <h3>⚙️ Configurações do Servidor</h3>
            <table>
                <tr>
                    <th>Configuração</th>
                    <th>Valor</th>
                </tr>
                <tr>
                    <td>PHP Version</td>
                    <td><?= PHP_VERSION ?></td>
                </tr>
                <tr>
                    <td>Session Status</td>
                    <td><?= session_status() == PHP_SESSION_ACTIVE ? 'Ativa' : 'Inativa' ?></td>
                </tr>
                <tr>
                    <td>JSON Support</td>
                    <td><?= function_exists('json_encode') ? '✅ OK' : '❌ Não disponível' ?></td>
                </tr>
                <tr>
                    <td>PDO Support</td>
                    <td><?= class_exists('PDO') ? '✅ OK' : '❌ Não disponível' ?></td>
                </tr>
                <tr>
                    <td>Error Reporting</td>
                    <td><?= error_reporting() ?></td>
                </tr>
                <tr>
                    <td>Display Errors</td>
                    <td><?= ini_get('display_errors') ? 'On' : 'Off' ?></td>
                </tr>
            </table>
        </div>

        <!-- INSTRUÇÕES DE CORREÇÃO -->
        <div class="section">
            <h3>🔧 Instruções de Correção</h3>
            
            <h4>Se o erro "erro ao buscar produto. tente novamente" aparece:</h4>
            <ol>
                <li><strong>Verifique se está logado:</strong> O sistema requer autenticação</li>
                <li><strong>Verifique os arquivos:</strong> Todos os arquivos PHP devem existir e ter permissões corretas</li>
                <li><strong>Verifique o banco:</strong> Conexão e tabela produtos devem estar funcionando</li>
                <li><strong>Verifique o console do navegador:</strong> Pressione F12 e veja se há erros JavaScript</li>
                <li><strong>Teste a URL diretamente:</strong> Acesse buscar_produtos_autocomplete.php?termo=teste no navegador</li>
            </ol>

            <h4>Problemas comuns e soluções:</h4>
            <ul>
                <li><strong>404 - Arquivo não encontrado:</strong> Verifique se buscar_produtos_autocomplete.php existe</li>
                <li><strong>500 - Erro interno:</strong> Verifique logs de erro do PHP</li>
                <li><strong>401 - Não autorizado:</strong> Faça login novamente</li>
                <li><strong>JSON inválido:</strong> Verifique se não há caracteres extras antes do &lt;?php</li>
            </ul>
        </div>

        <!-- LINKS ÚTEIS -->
        <div class="section">
            <h3>🔗 Links Úteis</h3>
            <a href="buscar_produtos_autocomplete.php?termo=teste" target="_blank" class="btn">🧪 Testar API Diretamente</a>
            <a href="consulta_empenho.php" class="btn">📋 Voltar ao Sistema</a>
            
            <?php if (function_exists('phpinfo')): ?>
                <a href="?phpinfo=1" class="btn">ℹ️ Ver PHP Info</a>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function testarAjax() {
        const resultDiv = document.getElementById('resultado-ajax');
        resultDiv.innerHTML = '<p>🔄 Testando...</p>';
        
        fetch('buscar_produtos_autocomplete.php?termo=teste&limit=3', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Cache-Control': 'no-cache'
            },
            credentials: 'same-origin'
        })
        .then(response => {
            console.log('Response:', response);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Resposta não é JSON válida');
            }
            
            return response.json();
        })
        .then(data => {
            console.log('Data:', data);
            
            if (data.success) {
                resultDiv.innerHTML = `
                    <p><span style="color: green; font-weight: bold;">✅ SUCESSO</span></p>
                    <p>Produtos encontrados: ${data.produtos ? data.produtos.length : 0}</p>
                    <details>
                        <summary>Ver resposta completa</summary>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    </details>
                `;
            } else {
                resultDiv.innerHTML = `
                    <p><span style="color: red; font-weight: bold;">❌ ERRO</span></p>
                    <p>Erro: ${data.error || 'Erro desconhecido'}</p>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            resultDiv.innerHTML = `
                <p><span style="color: red; font-weight: bold;">❌ ERRO DE CONEXÃO</span></p>
                <p>Erro: ${error.message}</p>
                <p><strong>Possíveis causas:</strong></p>
                <ul>
                    <li>Arquivo buscar_produtos_autocomplete.php não encontrado</li>
                    <li>Erro de sintaxe no PHP</li>
                    <li>Problema na conexão com banco</li>
                    <li>Sessão expirada</li>
                </ul>
            `;
        });
    }
    </script>
</body>
</html>

<?php
// Exibe PHP Info se solicitado
if (isset($_GET['phpinfo']) && function_exists('phpinfo')):
    echo '<hr><h2>PHP Information</h2>';
    phpinfo();
endif;
?>