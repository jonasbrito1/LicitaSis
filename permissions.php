<?php
/**
 * Sistema de Gestão de Permissões - Corrigido e Atualizado
 * Arquivo: permissions.php
 */

class PermissionManager {
    private $pdo;
    private $user;
    
    public function __construct($pdo, $user = null) {
        $this->pdo = $pdo;
        $this->user = $user ?? $_SESSION['user'] ?? null;
    }
    
    /**
     * Verifica se o usuário está logado
     */
    public function checkLogin() {
        if (!$this->user) {
            header("Location: login.php");
            exit();
        }
    }
    
    /**
     * Verifica se o usuário tem permissão para acessar uma página
     */
    public function hasPagePermission($page, $action = 'view') {
        if (!$this->user) {
            return false;
        }
        
        $permission = $this->user['permission'];
        
        // Administrador tem acesso total
        if ($permission === 'Administrador') {
            return true;
        }
        
        // Atividades são exclusivas para administradores
        if ($page === 'atividades') {
            return $permission === 'Administrador';
        }
        
        // Tenta consultar no banco de dados
        try {
            $stmt = $this->pdo->prepare("
                SELECT can_view, can_edit, can_create, can_delete 
                FROM page_permissions 
                WHERE permission_level = :permission AND page_name = :page
            ");
            $stmt->execute([
                ':permission' => $permission,
                ':page' => $page
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                switch ($action) {
                    case 'view':
                        return $result['can_view'];
                    case 'edit':
                        return $result['can_edit'];
                    case 'create':
                        return $result['can_create'];
                    case 'delete':
                        return $result['can_delete'];
                    default:
                        return false;
                }
            }
        } catch (Exception $e) {
            // Se der erro na consulta, usa permissões padrão
        }
        
        // Permissões padrão se não houver na tabela
        return $this->getDefaultPermissions($permission, $page, $action);
    }
    
    /**
     * Permissões padrão do sistema
     */
    private function getDefaultPermissions($permission, $page, $action) {
        $defaultPermissions = [
            'Usuario_Nivel_1' => [
                'view' => ['clientes', 'produtos', 'empenhos', 'compras', 'vendas', 'fornecedores'],
                'edit' => [],
                'create' => [],
                'delete' => []
            ],
            'Usuario_Nivel_2' => [
                'view' => ['clientes', 'produtos', 'empenhos', 'compras', 'vendas', 'fornecedores', 'financeiro', 'transportadoras'],
                'edit' => ['clientes', 'produtos', 'empenhos', 'compras', 'vendas', 'fornecedores'],
                'create' => ['clientes', 'produtos', 'empenhos', 'compras', 'vendas', 'fornecedores'],
                'delete' => ['produtos']
            ],
            'Usuario_Nivel_3' => [
                'view' => ['clientes', 'produtos', 'empenhos', 'compras', 'vendas', 'fornecedores', 'financeiro', 'transportadoras'],
                'edit' => ['clientes', 'produtos', 'empenhos', 'compras', 'vendas', 'fornecedores', 'transportadoras'],
                'create' => ['clientes', 'produtos', 'empenhos', 'compras', 'vendas', 'fornecedores', 'transportadoras'],
                'delete' => ['produtos', 'fornecedores']
            ],
            'Investidor' => [
                'view' => ['financeiro'],
                'edit' => [],
                'create' => [],
                'delete' => []
            ]
        ];
        
        if (isset($defaultPermissions[$permission][$action])) {
            return in_array($page, $defaultPermissions[$permission][$action]);
        }
        
        return false;
    }
    
    /**
     * Redireciona se não tiver permissão
     */
    public function requirePermission($page, $action = 'view') {
        if (!$this->hasPagePermission($page, $action)) {
            $_SESSION['error'] = 'Você não tem permissão para acessar esta funcionalidade.';
            header("Location: index.php");
            exit();
        }
    }
    
    /**
     * Verifica se é administrador
     */
    public function isAdmin() {
        return $this->user && $this->user['permission'] === 'Administrador';
    }
    
    /**
     * Verifica se é investidor
     */
    public function isInvestor() {
        return $this->user && $this->user['permission'] === 'Investidor';
    }
    
    /**
     * Verifica se é usuário nível 1
     */
    public function isUserLevel1() {
        return $this->user && $this->user['permission'] === 'Usuario_Nivel_1';
    }
    
    /**
     * Verifica se é usuário nível 2
     */
    public function isUserLevel2() {
        return $this->user && $this->user['permission'] === 'Usuario_Nivel_2';
    }
    
    /**
     * Verifica se é usuário nível 3
     */
    public function isUserLevel3() {
        return $this->user && $this->user['permission'] === 'Usuario_Nivel_3';
    }
    
    /**
     * Obtém as páginas que o usuário pode acessar
     */
    public function getAccessiblePages() {
        if (!$this->user) {
            return [];
        }
        
        $permission = $this->user['permission'];
        
        // Administrador tem acesso a tudo
        if ($permission === 'Administrador') {
            return [
                'clientes', 'produtos', 'empenhos', 'financeiro', 
                'transportadoras', 'fornecedores', 'vendas', 'compras', 
                'usuarios', 'funcionarios', 'atividades'
            ];
        }
        
        // Investidor tem acesso limitado
        if ($permission === 'Investidor') {
            return ['financeiro'];
        }
        
        // Tenta consultar no banco
        try {
            $stmt = $this->pdo->prepare("
                SELECT page_name 
                FROM page_permissions 
                WHERE permission_level = :permission AND can_view = 1
            ");
            $stmt->execute([':permission' => $permission]);
            
            $pages = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $pages[] = $row['page_name'];
            }
            
            if (!empty($pages)) {
                return $pages;
            }
        } catch (Exception $e) {
            // Se der erro, usa permissões padrão
        }
        
        // Retorna permissões padrão
        $defaultPages = [
            'Usuario_Nivel_1' => ['clientes', 'produtos', 'empenhos', 'compras', 'vendas', 'fornecedores'],
            'Usuario_Nivel_2' => ['clientes', 'produtos', 'empenhos', 'compras', 'vendas', 'fornecedores', 'financeiro', 'transportadoras'],
            'Usuario_Nivel_3' => ['clientes', 'produtos', 'empenhos', 'compras', 'vendas', 'fornecedores', 'financeiro', 'transportadoras']
        ];
        
        return $defaultPages[$permission] ?? [];
    }
    
    /**
     * Gera o menu de navegação baseado nas permissões
     */
    public function generateNavigationMenu() {
        $accessiblePages = $this->getAccessiblePages();
        $menu = '';

        // Dashboard sempre disponível (exceto para investidores)
        if (!$this->isInvestor()) {
            $menu .= '<a href="index.php"><i class="fas fa-home"></i> Início</a>';
        }

        // Clientes
        if (in_array('clientes', $accessiblePages)) {
            $menu .= '<a href="clientes.php"><i class="fas fa-users"></i> Clientes</a>';
        }

        // Produtos
        if (in_array('produtos', $accessiblePages)) {
            $menu .= '<a href="produtos.php"><i class="fas fa-box-open"></i> Produtos</a>';
        }

        // Empenhos
        if (in_array('empenhos', $accessiblePages)) {
            $menu .= '<a href="consulta_empenho.php"><i class="fas fa-file-contract"></i> Empenhos</a>';
        }
        
        // Fornecedores
        if (in_array('fornecedores', $accessiblePages)) {
            $menu .= '<a href="fornecedores.php"><i class="fas fa-industry"></i> Fornecedores</a>';
        }

        // Compras
        if (in_array('compras', $accessiblePages)) {
            $menu .= '<a href="compras.php"><i class="fas fa-shopping-basket"></i> Compras</a>';
        }

        // Vendas
        if (in_array('vendas', $accessiblePages)) {
            $menu .= '<a href="vendas.php"><i class="fas fa-shopping-cart"></i> Vendas</a>';
        }

        // Financeiro
        if (in_array('financeiro', $accessiblePages)) {
            $menu .= '<a href="financeiro.php"><i class="fas fa-dollar-sign"></i> Financeiro</a>';
        }

        // Transportadoras
        if (in_array('transportadoras', $accessiblePages)) {
            $menu .= '<a href="transportadoras.php"><i class="fas fa-truck"></i> Transportadoras</a>';
        }

        // Menu administrativo (só para admins)
        if ($this->isAdmin()) {
            $menu .= '<div class="dropdown">
                        <a href="#" class="dropbtn"><i class="fas fa-cog"></i> Administração <i class="fas fa-chevron-down"></i></a>
                        <div class="dropdown-content">
                            <a href="usuario.php"><i class="fas fa-users-cog"></i> Usuários</a>
                            <a href="funcionarios.php"><i class="fas fa-user-tie"></i> Funcionários</a>
                            <a href="atividades.php"><i class="fas fa-tasks"></i> Atividades</a>
                        </div>
                      </div>';
        }

        // Link de logout
        $menu .= '<a href="logout.php" class="logout-link">
                    <i class="fas fa-sign-out-alt"></i> Sair
                  </a>';

        return $menu;
    }
    
    /**
     * Obtém o nome amigável da permissão
     */
    public function getPermissionName($permission) {
        $names = [
            'Administrador' => 'Administrador',
            'Usuario_Nivel_1' => 'Usuário Nível 1',
            'Usuario_Nivel_2' => 'Usuário Nível 2',
            'Usuario_Nivel_3' => 'Usuário Nível 3',
            'Investidor' => 'Investidor'
        ];
        
        return $names[$permission] ?? $permission;
    }
    
    /**
     * Verifica se pode mostrar um botão de ação
     */
    public function canShowButton($page, $action) {
        return $this->hasPagePermission($page, $action);
    }
    
    /**
     * Gera classe CSS para botões baseado em permissões
     */
    public function getButtonClass($page, $action, $baseClass = 'btn') {
        if (!$this->hasPagePermission($page, $action)) {
            return $baseClass . ' disabled';
        }
        return $baseClass;
    }
    
    /**
     * Inicializa permissões básicas na tabela se não existirem
     */
    public function initializeDefaultPermissions() {
        try {
            // Verifica se já existem permissões cadastradas
            $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM page_permissions");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0) {
                // Insere permissões padrão
                $defaultPermissions = [
                    // Usuario_Nivel_1 - Apenas visualização
                    ['Usuario_Nivel_1', 'clientes', 1, 0, 0, 0],
                    ['Usuario_Nivel_1', 'produtos', 1, 0, 0, 0],
                    ['Usuario_Nivel_1', 'empenhos', 1, 0, 0, 0],
                    ['Usuario_Nivel_1', 'compras', 1, 0, 0, 0],
                    ['Usuario_Nivel_1', 'vendas', 1, 0, 0, 0],
                    ['Usuario_Nivel_1', 'fornecedores', 1, 0, 0, 0],
                    
                    // Usuario_Nivel_2 - Visualização e edição limitada
                    ['Usuario_Nivel_2', 'clientes', 1, 1, 1, 0],
                    ['Usuario_Nivel_2', 'produtos', 1, 1, 1, 1],
                    ['Usuario_Nivel_2', 'empenhos', 1, 1, 1, 0],
                    ['Usuario_Nivel_2', 'compras', 1, 1, 1, 0],
                    ['Usuario_Nivel_2', 'vendas', 1, 1, 1, 0],
                    ['Usuario_Nivel_2', 'fornecedores', 1, 1, 1, 0],
                    ['Usuario_Nivel_2', 'financeiro', 1, 0, 0, 0],
                    ['Usuario_Nivel_2', 'transportadoras', 1, 1, 1, 0],
                    
                    // Usuario_Nivel_3 - Acesso avançado
                    ['Usuario_Nivel_3', 'clientes', 1, 1, 1, 0],
                    ['Usuario_Nivel_3', 'produtos', 1, 1, 1, 1],
                    ['Usuario_Nivel_3', 'empenhos', 1, 1, 1, 0],
                    ['Usuario_Nivel_3', 'compras', 1, 1, 1, 0],
                    ['Usuario_Nivel_3', 'vendas', 1, 1, 1, 0],
                    ['Usuario_Nivel_3', 'fornecedores', 1, 1, 1, 1],
                    ['Usuario_Nivel_3', 'financeiro', 1, 1, 0, 0],
                    ['Usuario_Nivel_3', 'transportadoras', 1, 1, 1, 1],
                    
                    // Investidor - Apenas financeiro
                    ['Investidor', 'financeiro', 1, 0, 0, 0],
                ];
                
                $sql = "INSERT INTO page_permissions (permission_level, page_name, can_view, can_edit, can_create, can_delete) 
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $this->pdo->prepare($sql);
                
                foreach ($defaultPermissions as $permission) {
                    $stmt->execute($permission);
                }
            }
        } catch (Exception $e) {
            // Ignora erro se a tabela não existir ainda
            error_log("Erro ao inicializar permissões: " . $e->getMessage());
        }
    }
    
    /**
     * Cria as tabelas necessárias se não existirem
     */
    public function createTablesIfNotExist() {
        try {
            // Tabela de permissões de páginas
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS page_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    permission_level VARCHAR(50) NOT NULL,
                    page_name VARCHAR(100) NOT NULL,
                    can_view BOOLEAN DEFAULT FALSE,
                    can_edit BOOLEAN DEFAULT FALSE,
                    can_create BOOLEAN DEFAULT FALSE,
                    can_delete BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_permission_page (permission_level, page_name)
                )
            ");
            
            // Tabela de atividades (para o sistema de TODO)
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS activities (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    page_name VARCHAR(100) NOT NULL,
                    activity_description TEXT NOT NULL,
                    is_completed BOOLEAN DEFAULT FALSE,
                    created_by INT NOT NULL,
                    created_at DATETIME NOT NULL,
                    completed_at DATETIME NULL,
                    INDEX idx_page_name (page_name),
                    INDEX idx_completed (is_completed),
                    INDEX idx_created_by (created_by)
                )
            ");
            
        } catch (Exception $e) {
            error_log("Erro ao criar tabelas: " . $e->getMessage());
        }
    }
}

/**
 * Função auxiliar para incluir o sistema de permissões em qualquer página
 */
function initPermissions($pdo = null) {
    if (!$pdo) {
        // Se não foi passado o PDO, tenta incluir o arquivo de conexão
        if (file_exists('db.php')) {
            include_once('db.php');
        } else {
            throw new Exception("Conexão com banco de dados não encontrada");
        }
    }
    
    $permissionManager = new PermissionManager($pdo);
    $permissionManager->checkLogin();
    
    // Cria tabelas se necessário
    $permissionManager->createTablesIfNotExist();
    
    // Inicializa permissões padrão se necessário
    $permissionManager->initializeDefaultPermissions();
    
    return $permissionManager;
}

/**
 * Função para verificar se está logado sem redirecionar
 */
function isLoggedIn() {
    return isset($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

/**
 * Função para obter usuário atual
 */
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

/**
 * Função para fazer logout
 */
function doLogout() {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

/**
 * Middleware simples para verificar autenticação
 */
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * CSS adicional para o sistema de navegação com dropdown
 */
function addNavigationCSS() {
    return '
    <style>
        nav {
            position: relative;
        }
        
        nav a {
            display: inline-block;
            color: white;
            text-decoration: none;
            padding: 0.75rem 1rem;
            margin: 0 0.25rem;
            border-radius: 4px;
            transition: all 0.3s ease;
            font-weight: 500;
            border-bottom: 3px solid transparent;
        }
        
        nav a:hover {
            background: rgba(255,255,255,0.1);
            border-bottom-color: var(--secondary-color);
            transform: translateY(-1px);
        }
        
        nav a.logout-link {
            background: rgba(220, 53, 69, 0.2);
            margin-left: 1rem;
        }
        
        nav a.logout-link:hover {
            background: rgba(220, 53, 69, 0.4);
        }
        
        /* Dropdown styles */
        .dropdown {
            position: relative;
            display: inline-block;
        }
        
        .dropdown-content {
            display: none;
            position: absolute;
            background: var(--primary-color);
            min-width: 200px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            z-index: 1000;
            border-radius: 0 0 8px 8px;
            overflow: hidden;
            top: 100%;
            left: 0;
        }
        
        .dropdown-content a {
            display: block;
            padding: 0.875rem 1.25rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin: 0;
            border-radius: 0;
        }
        
        .dropdown-content a:last-child {
            border-bottom: none;
        }
        
        .dropdown:hover .dropdown-content {
            display: block;
            animation: fadeInDown 0.3s ease;
        }
        
        @keyframes fadeInDown {
            from { 
                opacity: 0; 
                transform: translateY(-10px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        .dropbtn {
            background: none !important;
            border: none;
            cursor: pointer;
        }
        
        .btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        @media (max-width: 768px) {
            nav {
                padding: 0.5rem 0;
            }
            
            nav a {
                display: block;
                margin: 0.25rem;
                text-align: center;
            }
            
            .dropdown {
                width: 100%;
            }
            
            .dropdown-content {
                position: static;
                display: none;
                box-shadow: none;
                border-radius: 0;
                background: rgba(0,0,0,0.2);
            }
            
            .dropdown:hover .dropdown-content {
                display: block;
            }
            
            .dropdown-content a {
                padding-left: 2rem;
                font-size: 0.9rem;
            }
        }
    </style>';
}

?>