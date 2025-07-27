# SCRIPT COMPLETO - ANÁLISE FUNCIONAL DO PROJETO
# Execute no diretório do projeto

Write-Host "=== ANALISANDO SISTEMA DE LICITAÇÕES ===" -ForegroundColor Magenta
Write-Host "Iniciando análise profunda do projeto..." -ForegroundColor Green

$doc = @()
$doc += "# 📋 Sistema de Gestão de Licitações - Análise Completa"
$doc += ""
$doc += "**Análise gerada em:** $(Get-Date -Format 'dd/MM/yyyy HH:mm:ss')"
$doc += "**Diretório:** $(Split-Path -Leaf (Get-Location))"
$doc += ""

# 1. INFORMAÇÕES BÁSICAS DO PROJETO
Write-Host "1. Coletando informações básicas..." -ForegroundColor Yellow
$doc += "## 🏢 Informações do Projeto"
$doc += ""

# Detectar tipo de projeto
$tiposProjeto = @()
if (Test-Path "artisan") { $tiposProjeto += "Laravel Framework" }
if (Test-Path "package.json") { $tiposProjeto += "Node.js Application" }
if (Test-Path "composer.json") { $tiposProjeto += "PHP Application" }
if (Test-Path "docker-compose.yml") { $tiposProjeto += "Dockerized Application" }

$doc += "**Tipo de Projeto:** $($tiposProjeto -join ', ')"
$doc += ""

# 2. ESTRUTURA DE ARQUIVOS COM ANÁLISE
Write-Host "2. Mapeando estrutura e analisando arquivos..." -ForegroundColor Yellow
$doc += "## 📁 Estrutura de Arquivos e Funcionalidades"
$doc += ""

$estrutura = @{}
$funcionalidades = @()

# Mapear estrutura por pastas importantes
$pastasImportantes = @("app", "resources", "routes", "database", "public", "config", "src", "components", "views", "controllers", "models", "modules")

foreach ($pasta in $pastasImportantes) {
    if (Test-Path $pasta) {
        $doc += "### 📂 $pasta/"
        $arquivos = Get-ChildItem $pasta -Recurse -File | Where-Object { $_.Extension -in @(".php", ".js", ".vue", ".html", ".blade.php") }
        
        foreach ($arquivo in $arquivos) {
            $relativePath = $arquivo.FullName.Replace((Get-Location).Path, "").TrimStart("\")
            $doc += "- $relativePath"
            
            # Analisar funcionalidade baseada no nome e localização
            $nomeArquivo = $arquivo.BaseName.ToLower()
            if ($nomeArquivo -match "cliente|customer") { $funcionalidades += "Gestão de Clientes" }
            if ($nomeArquivo -match "fornecedor|supplier|vendor") { $funcionalidades += "Gestão de Fornecedores" }
            if ($nomeArquivo -match "produto|product|item") { $funcionalidades += "Gestão de Produtos" }
            if ($nomeArquivo -match "licitac|tender|bidding") { $funcionalidades += "Processos Licitatórios" }
            if ($nomeArquivo -match "usuario|user|auth") { $funcionalidades += "Sistema de Usuários" }
            if ($nomeArquivo -match "funcionario|employee|staff") { $funcionalidades += "Gestão de Funcionários" }
            if ($nomeArquivo -match "proposta|proposal|bid") { $funcionalidades += "Sistema de Propostas" }
            if ($nomeArquivo -match "contrato|contract") { $funcionalidades += "Gestão de Contratos" }
            if ($nomeArquivo -match "relatorio|report") { $funcionalidades += "Sistema de Relatórios" }
            if ($nomeArquivo -match "dashboard|painel") { $funcionalidades += "Dashboard/Painel" }
        }
        $doc += ""
    }
}

# 3. FUNCIONALIDADES IDENTIFICADAS
Write-Host "3. Identificando funcionalidades..." -ForegroundColor Yellow
$funcionalidadesUnicas = $funcionalidades | Sort-Object | Get-Unique
$doc += "## ⚙️ Funcionalidades Identificadas"
$doc += ""
foreach ($func in $funcionalidadesUnicas) {
    $doc += "- ✅ **$func**"
}
$doc += ""

# 4. ANÁLISE DO BACKEND (Laravel/PHP)
Write-Host "4. Analisando backend..." -ForegroundColor Yellow
if (Test-Path "composer.json") {
    $doc += "## 🐘 Backend - PHP/Laravel"
    $doc += ""
    
    # Ler composer.json
    try {
        $composer = Get-Content "composer.json" | ConvertFrom-Json
        $doc += "### Dependências PHP:"
        if ($composer.require) {
            $composer.require.PSObject.Properties | ForEach-Object {
                $doc += "- **$($_.Name)**: $($_.Value)"
            }
        }
        $doc += ""
    } catch {}
    
    # Analisar Controllers
    if (Test-Path "app/Http/Controllers") {
        $doc += "### Controllers Encontrados:"
        $controllers = Get-ChildItem "app/Http/Controllers" -Recurse -Filter "*.php"
        foreach ($controller in $controllers) {
            $nomeController = $controller.BaseName
            $doc += "- **$nomeController**"
            
            # Ler conteúdo para identificar métodos
            try {
                $conteudo = Get-Content $controller.FullName -Raw
                $metodos = [regex]::Matches($conteudo, "public function (\w+)")
                if ($metodos.Count -gt 0) {
                    $metodosNomes = $metodos | ForEach-Object { $_.Groups[1].Value }
                    $doc += "  - Métodos: $($metodosNomes -join ', ')"
                }
            } catch {}
        }
        $doc += ""
    }
    
    # Analisar Models
    if (Test-Path "app/Models") {
        $doc += "### Models Encontrados:"
        $models = Get-ChildItem "app/Models" -Filter "*.php"
        foreach ($model in $models) {
            $doc += "- **$($model.BaseName)**"
        }
        $doc += ""
    }
    
    # Analisar Migrations
    if (Test-Path "database/migrations") {
        $doc += "### Estrutura do Banco (Migrations):"
        $migrations = Get-ChildItem "database/migrations" -Filter "*.php" | Sort-Object Name
        foreach ($migration in $migrations) {
            $nomeLimpo = $migration.Name -replace '^\d{4}_\d{2}_\d{2}_\d{6}_', ''
            $nomeLimpo = $nomeLimpo -replace '\.php$', ''
            $doc += "- $nomeLimpo"
        }
        $doc += ""
    }
}

# 5. ANÁLISE DO FRONTEND
Write-Host "5. Analisando frontend..." -ForegroundColor Yellow
if (Test-Path "package.json") {
    $doc += "## 🎨 Frontend - JavaScript"
    $doc += ""
    
    try {
        $package = Get-Content "package.json" | ConvertFrom-Json
        
        # Identificar framework frontend
        $frameworkFrontend = @()
        if ($package.dependencies.vue) { $frameworkFrontend += "Vue.js" }
        if ($package.dependencies.react) { $frameworkFrontend += "React" }
        if ($package.dependencies.angular) { $frameworkFrontend += "Angular" }
        if ($package.dependencies."@inertiajs/vue3") { $frameworkFrontend += "Inertia.js (Vue)" }
        
        $doc += "### Framework Frontend: $($frameworkFrontend -join ', ')"
        $doc += ""
        
        $doc += "### Dependências Frontend:"
        if ($package.dependencies) {
            $package.dependencies.PSObject.Properties | ForEach-Object {
                $doc += "- **$($_.Name)**: $($_.Value)"
            }
        }
        $doc += ""
        
        $doc += "### Scripts de Build:"
        if ($package.scripts) {
            $package.scripts.PSObject.Properties | ForEach-Object {
                $doc += "- **$($_.Name)**: \`$($_.Value)\`"
            }
        }
        $doc += ""
    } catch {}
    
    # Analisar componentes Vue/React
    $componentePaths = @("resources/js/components", "src/components", "resources/js/Pages")
    foreach ($path in $componentePaths) {
        if (Test-Path $path) {
            $doc += "### Componentes em $path:"
            $componentes = Get-ChildItem $path -Recurse -Filter "*.vue", "*.jsx", "*.tsx"
            foreach ($comp in $componentes) {
                $doc += "- **$($comp.BaseName)**"
            }
            $doc += ""
        }
    }
}

# 6. ANÁLISE DE ROTAS E APIs
Write-Host "6. Analisando rotas e APIs..." -ForegroundColor Yellow
if (Test-Path "routes") {
    $doc += "## 🛣️ Rotas e Endpoints"
    $doc += ""
    
    $routeFiles = Get-ChildItem "routes" -Filter "*.php"
    foreach ($routeFile in $routeFiles) {
        $doc += "### $($routeFile.Name)"
        try {
            $conteudo = Get-Content $routeFile.FullName -Raw
            
            # Extrair rotas
            $patterns = @(
                "Route::get\s*\(\s*['""]([^'""]+)['""].*?([^,\)]+)",
                "Route::post\s*\(\s*['""]([^'""]+)['""].*?([^,\)]+)",
                "Route::put\s*\(\s*['""]([^'""]+)['""].*?([^,\)]+)",
                "Route::delete\s*\(\s*['""]([^'""]+)['""].*?([^,\)]+)"
            )
            
            foreach ($pattern in $patterns) {
                $matches = [regex]::Matches($conteudo, $pattern)
                foreach ($match in $matches) {
                    $method = ($pattern -split "::")[1] -split "\s" | Select-Object -First 1
                    $path = $match.Groups[1].Value
                    $action = $match.Groups[2].Value.Trim()
                    $doc += "- **$($method.ToUpper())** \`$path\` → $action"
                }
            }
        } catch {}
        $doc += ""
    }
}

# 7. CONFIGURAÇÕES E AMBIENTE
Write-Host "7. Analisando configurações..." -ForegroundColor Yellow
$doc += "## ⚙️ Configurações do Sistema"
$doc += ""

if (Test-Path ".env.example") {
    $doc += "### Variáveis de Ambiente Necessárias:"
    $envContent = Get-Content ".env.example"
    $secoes = @{}
    $secaoAtual = "Geral"
    
    foreach ($linha in $envContent) {
        if ($linha.StartsWith("#") -and $linha.Length -gt 1) {
            $secaoAtual = $linha.Substring(1).Trim()
            $secoes[$secaoAtual] = @()
        } elseif ($linha.Contains("=") -and -not $linha.StartsWith("#")) {
            $varName = ($linha -split "=")[0]
            if (-not $secoes[$secaoAtual]) { $secoes[$secaoAtual] = @() }
            $secoes[$secaoAtual] += $varName
        }
    }
    
    foreach ($secao in $secoes.Keys) {
        if ($secoes[$secao].Count -gt 0) {
            $doc += "#### $secao:"
            foreach ($var in $secoes[$secao]) {
                $doc += "- $var"
            }
        }
    }
    $doc += ""
}

# 8. INTEGRAÇÃO E DOCKER
Write-Host "8. Verificando integrações..." -ForegroundColor Yellow
if (Test-Path "docker-compose.yml") {
    $doc += "## 🐳 Configuração Docker"
    $doc += ""
    $doc += "### docker-compose.yml encontrado"
    try {
        $dockerContent = Get-Content "docker-compose.yml"
        $services = $dockerContent | Where-Object { $_ -match "^\s*\w+:" -and $_ -notmatch "version:|services:" }
        $doc += "### Serviços configurados:"
        foreach ($service in $services) {
            $serviceName = $service.Trim().TrimEnd(":")
            $doc += "- **$serviceName**"
        }
    } catch {}
    $doc += ""
}

# 9. FLUXO DO SISTEMA
Write-Host "9. Mapeando fluxo do sistema..." -ForegroundColor Yellow
$doc += "## 🔄 Fluxo do Sistema"
$doc += ""
$doc += "### Arquitetura Identificada:"

if (Test-Path "artisan") {
    $doc += "- **Padrão MVC** (Laravel)"
    $doc += "- **Frontend/Backend Separados** ou **Monolítico**"
}

if (Test-Path "resources/js/app.js") {
    $doc += "- **SPA (Single Page Application)** ou **Híbrido**"
}

$doc += ""
$doc += "### Possível Fluxo de Dados:"
$doc += "1. **Usuário** acessa o sistema via navegador"
$doc += "2. **Rotas** direcionam para controllers apropriados"
$doc += "3. **Controllers** processam requisições e interagem com **Models**"
$doc += "4. **Models** fazem operações no banco de dados"
$doc += "5. **Views/Componentes** renderizam a resposta para o usuário"
$doc += ""

# 10. INSTRUÇÕES DE SETUP
Write-Host "10. Gerando instruções de setup..." -ForegroundColor Yellow
$doc += "## 🚀 Como Executar o Sistema"
$doc += ""
$doc += "### Pré-requisitos:"
$doc += "- PHP >= 8.0"
$doc += "- Composer"
$doc += "- Node.js >= 16"
$doc += "- MySQL ou PostgreSQL"
if (Test-Path "docker-compose.yml") { $doc += "- Docker (opcional)" }
$doc += ""

$doc += "### Instalação:"
$doc += "\`\`\`bash"
$doc += "# 1. Clone o repositório"
$doc += "git clone <repositorio>"
$doc += "cd $(Split-Path -Leaf (Get-Location))"
$doc += ""
$doc += "# 2. Instalar dependências PHP"
$doc += "composer install"
$doc += ""
$doc += "# 3. Instalar dependências Node.js"
$doc += "npm install"
$doc += ""
$doc += "# 4. Configurar ambiente"
$doc += "cp .env.example .env"
$doc += "php artisan key:generate"
$doc += ""
$doc += "# 5. Configurar banco de dados no .env"
$doc += "# Editar DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD"
$doc += ""
$doc += "# 6. Executar migrations"
$doc += "php artisan migrate"
$doc += ""
$doc += "# 7. (Opcional) Executar seeders"
$doc += "php artisan db:seed"
$doc += ""
$doc += "# 8. Compilar assets"
$doc += "npm run build"
$doc += ""
$doc += "# 9. Iniciar servidor"
$doc += "php artisan serve"
$doc += "\`\`\`"
$doc += ""

if (Test-Path "docker-compose.yml") {
    $doc += "### Usando Docker:"
    $doc += "\`\`\`bash"
    $doc += "docker-compose up -d"
    $doc += "\`\`\`"
    $doc += ""
}

# SALVAR ARQUIVO FINAL
Write-Host "11. Salvando análise completa..." -ForegroundColor Yellow
$outputFile = "ANALISE-SISTEMA-LICITACOES.md"
$doc | Out-File -FilePath $outputFile -Encoding UTF8

Write-Host ""
Write-Host "=== ANÁLISE CONCLUÍDA ===" -ForegroundColor Green
Write-Host "Arquivo gerado: $outputFile" -ForegroundColor Cyan
Write-Host "Total de linhas: $($doc.Count)" -ForegroundColor Yellow
Write-Host ""
Write-Host "PRÓXIMO PASSO:" -ForegroundColor Magenta
Write-Host "1. Abra o arquivo $outputFile" -ForegroundColor White
Write-Host "2. Copie TODO o conteúdo" -ForegroundColor White
Write-Host "3. Cole no chat para criar o README profissional" -ForegroundColor White
Write-Host ""

# Mostrar preview
Write-Host "=== PREVIEW DOS PRIMEIROS RESULTADOS ===" -ForegroundColor Blue
$doc[0..20] | ForEach-Object { Write-Host $_ -ForegroundColor Gray }
Write-Host "..." -ForegroundColor Gray
Write-Host ""
Write-Host "Arquivo completo salvo em: $outputFile" -ForegroundColor Green