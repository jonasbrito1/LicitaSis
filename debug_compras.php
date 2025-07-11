<?php
// =====================================================
// SCRIPT DE DEBUG PARA ESTRUTURA DA TABELA COMPRAS
// =====================================================

// Incluir conexão com o banco
include('db.php');

echo "<h1>🔍 Diagnóstico da Tabela Compras</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
    .error { background: #f8d7da; color: #721c24; }
    .success { background: #d4edda; color: #155724; }
    .info { background: #d1ecf1; color: #0c5460; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; }
    th { background: #f8f9fa; }
    code { background: #f8f9fa; padding: 2px 4px; border-radius: 3px; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 5px; overflow-x: auto; }
</style>";

try {
    // 1. Verificar se a tabela existe
    echo "<div class='section info'>";
    echo "<h2>📋 1. Verificação da Existência da Tabela</h2>";
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'compras'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        echo "<p class='success'>✅ Tabela 'compras' existe!</p>";
    } else {
        echo "<p class='error'>❌ Tabela 'compras' NÃO existe!</p>";
        exit();
    }
    echo "</div>";

    // 2. Estrutura completa da tabela
    echo "<div class='section'>";
    echo "<h2>🏗️ 2. Estrutura da Tabela</h2>";
    
    $stmt = $pdo->query("DESCRIBE compras");
    $structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Chave</th><th>Padrão</th><th>Extra</th></tr>";
    
    $hasQuantidadeAnterior = false;
    foreach ($structure as $column) {
        if ($column['Field'] === 'quantidade_anterior') {
            $hasQuantidadeAnterior = true;
        }
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($column['Field']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<p><strong>Campo 'quantidade_anterior':</strong> " . 
         ($hasQuantidadeAnterior ? "✅ PRESENTE" : "❌ AUSENTE") . "</p>";
    echo "</div>";

    // 3. Comando CREATE TABLE completo
    echo "<div class='section'>";
    echo "<h2>🔧 3. Comando CREATE TABLE</h2>";
    
    $stmt = $pdo->query("SHOW CREATE TABLE compras");
    $createTable = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<pre>" . htmlspecialchars($createTable['Create Table']) . "</pre>";
    echo "</div>";

    // 4. Verificar triggers
    echo "<div class='section'>";
    echo "<h2>⚡ 4. Triggers da Tabela</h2>";
    
    $stmt = $pdo->query("SHOW TRIGGERS LIKE 'compras'");
    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($triggers)) {
        echo "<p>✅ Nenhum trigger encontrado.</p>";
    } else {
        echo "<table>";
        echo "<tr><th>Nome</th><th>Evento</th><th>Tabela</th><th>Timing</th></tr>";
        foreach ($triggers as $trigger) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($trigger['Trigger']) . "</td>";
            echo "<td>" . htmlspecialchars($trigger['Event']) . "</td>";
            echo "<td>" . htmlspecialchars($trigger['Table']) . "</td>";
            echo "<td>" . htmlspecialchars($trigger['Timing']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    echo "</div>";

    // 5. Informações detalhadas das colunas
    echo "<div class='section'>";
    echo "<h2>📊 5. Informações Detalhadas das Colunas</h2>";
    
    $stmt = $pdo->query("
        SELECT 
            COLUMN_NAME,
            COLUMN_TYPE,
            IS_NULLABLE,
            COLUMN_DEFAULT,
            EXTRA,
            ORDINAL_POSITION
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'compras' 
        AND TABLE_SCHEMA = DATABASE()
        ORDER BY ORDINAL_POSITION
    ");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table>";
    echo "<tr><th>Posição</th><th>Nome</th><th>Tipo</th><th>Nulo</th><th>Padrão</th><th>Extra</th></tr>";
    
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($column['ORDINAL_POSITION']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($column['COLUMN_NAME']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($column['COLUMN_TYPE']) . "</td>";
        echo "<td>" . htmlspecialchars($column['IS_NULLABLE']) . "</td>";
        echo "<td>" . htmlspecialchars($column['COLUMN_DEFAULT'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($column['EXTRA']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";

    // 6. Verificar Foreign Keys
    echo "<div class='section'>";
    echo "<h2>🔗 6. Foreign Keys</h2>";
    
    $stmt = $pdo->query("
        SELECT 
            CONSTRAINT_NAME,
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_NAME = 'compras'
        AND TABLE_SCHEMA = DATABASE()
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($foreignKeys)) {
        echo "<p>✅ Nenhuma foreign key encontrada.</p>";
    } else {
        echo "<table>";
        echo "<tr><th>Constraint</th><th>Coluna</th><th>Tabela Referenciada</th><th>Coluna Referenciada</th></tr>";
        foreach ($foreignKeys as $fk) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($fk['CONSTRAINT_NAME']) . "</td>";
            echo "<td>" . htmlspecialchars($fk['COLUMN_NAME']) . "</td>";
            echo "<td>" . htmlspecialchars($fk['REFERENCED_TABLE_NAME']) . "</td>";
            echo "<td>" . htmlspecialchars($fk['REFERENCED_COLUMN_NAME']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    echo "</div>";

    // 7. Teste de INSERT simples
    echo "<div class='section info'>";
    echo "<h2>🧪 7. Teste de INSERT (Simulação)</h2>";
    
    // Montar SQL de INSERT baseado na estrutura atual
    $campos = [];
    $valores = [];
    $parametros = [];
    
    foreach ($structure as $column) {
        $campo = $column['Field'];
        
        // Pular campos auto-increment e timestamps automáticos
        if (strpos($column['Extra'], 'auto_increment') !== false) continue;
        if ($campo === 'created_at' && $column['Default'] === 'current_timestamp()') continue;
        if ($campo === 'updated_at') continue;
        
        $campos[] = $campo;
        $valores[] = ":$campo";
        
        // Definir valores de teste
        switch ($campo) {
            case 'fornecedor':
                $parametros[$campo] = 'Teste Fornecedor';
                break;
            case 'numero_nf':
                $parametros[$campo] = 'NF-TEST-001';
                break;
            case 'produto':
                $parametros[$campo] = 'Produto Teste';
                break;
            case 'quantidade':
                $parametros[$campo] = 1;
                break;
            case 'quantidade_anterior':
                $parametros[$campo] = 0;
                break;
            case 'valor_unitario':
            case 'valor_total':
            case 'frete':
                $parametros[$campo] = '10.00';
                break;
            case 'data':
                $parametros[$campo] = date('Y-m-d');
                break;
            default:
                $parametros[$campo] = null;
        }
    }
    
    $sqlTest = "INSERT INTO compras (" . implode(', ', $campos) . ") VALUES (" . implode(', ', $valores) . ")";
    
    echo "<h3>SQL que seria executado:</h3>";
    echo "<pre>" . htmlspecialchars($sqlTest) . "</pre>";
    
    echo "<h3>Parâmetros:</h3>";
    echo "<pre>" . print_r($parametros, true) . "</pre>";
    
    // Verificar se o SQL está correto (preparar apenas, não executar)
    try {
        $stmtTest = $pdo->prepare($sqlTest);
        echo "<p class='success'>✅ SQL válido - preparação bem-sucedida!</p>";
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro no SQL: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    echo "</div>";

    // 8. Resumo e recomendações
    echo "<div class='section info'>";
    echo "<h2>📋 8. Resumo e Recomendações</h2>";
    
    echo "<h3>Status:</h3>";
    echo "<ul>";
    echo "<li>Tabela 'compras': " . ($tableExists ? "✅ Existe" : "❌ Não existe") . "</li>";
    echo "<li>Campo 'quantidade_anterior': " . ($hasQuantidadeAnterior ? "✅ Presente" : "❌ Ausente") . "</li>";
    echo "<li>Total de colunas: " . count($structure) . "</li>";
    echo "<li>Triggers: " . (empty($triggers) ? "Nenhum" : count($triggers)) . "</li>";
    echo "<li>Foreign Keys: " . (empty($foreignKeys) ? "Nenhuma" : count($foreignKeys)) . "</li>";
    echo "</ul>";
    
    echo "<h3>Possíveis Causas do Erro:</h3>";
    echo "<ol>";
    if (!$hasQuantidadeAnterior) {
        echo "<li>✅ O campo 'quantidade_anterior' NÃO existe (confirmado)</li>";
        echo "<li>🔍 Verificar se há algum TRIGGER que está inserindo este campo</li>";
        echo "<li>🔍 Verificar se o código PHP está tentando inserir este campo em algum lugar</li>";
        echo "<li>🔍 Verificar se há alguma VIEW ou PROCEDURE envolvida</li>";
    } else {
        echo "<li>⚠️ O campo 'quantidade_anterior' existe mas pode ter constraint NOT NULL</li>";
    }
    echo "</ol>";
    
    echo "<h3>Próximos Passos:</h3>";
    echo "<ol>";
    echo "<li>Use o código PHP adaptativo que detecta automaticamente a estrutura</li>";
    echo "<li>Verifique os logs do servidor web para o erro exato</li>";
    echo "<li>Teste o INSERT manual com os dados acima</li>";
    if ($hasQuantidadeAnterior) {
        echo "<li>Configure valor padrão para 'quantidade_anterior' se necessário</li>";
    }
    echo "</ol>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div class='section error'>";
    echo "<h2>❌ Erro durante o diagnóstico</h2>";
    echo "<p><strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Arquivo:</strong> " . htmlspecialchars($e->getFile()) . "</p>";
    echo "<p><strong>Linha:</strong> " . htmlspecialchars($e->getLine()) . "</p>";
    echo "</div>";
}

echo "<div style='margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 5px;'>";
echo "<h3>💡 Como usar este diagnóstico:</h3>";
echo "<ol>";
echo "<li>Analise a estrutura da tabela na seção 2</li>";
echo "<li>Verifique se há triggers na seção 4</li>";
echo "<li>Use o SQL de teste da seção 7 para identificar o problema</li>";
echo "<li>Implemente o código PHP adaptativo baseado nos resultados</li>";
echo "</ol>";
echo "</div>";
?>