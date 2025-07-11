<?php
/**
 * Sistema de Gestão de Permissões
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
        
        // Consulta no banco de dados
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
        
        if (!$result) {
            return false;
        }
        
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
    
    /**
     * Redireciona se não tiver permissão
     */
    public function requirePermission($page, $action = 'view') {
        if (!$this->hasPagePermission($page, $action)) {
            header("Location: access_denied.php");
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
     * Verifica se é investidor
     */
    public function isInvestor() {
        return $this->user && $this->user['permission'] === 'Investidor';
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
                'usuarios', 'funcionarios', 'investimentos'
            ];
        }
        
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
        
        return $pages;
    }
    
    /**
     * Gera o menu de navegação baseado nas permissões
     */
    public function generateNavigationMenu() {
        $accessiblePages = $this->getAccessiblePages();
        $menu = '';
        
        // Clientes
        if (in_array('clientes', $accessiblePages)) {
            $menu .= '
            <div class="dropdown">
                <a href="clientes.php">Clientes</a>
                <div class="dropdown-content">';
            
            if ($this->hasPagePermission('clientes', 'create')) {
                $menu .= '<a href="cadastrar_clientes.php">Inserir Clientes</a>';
            }
            if ($this->hasPagePermission('clientes', 'view')) {
                $menu .= '<a href="consultar_clientes.php">Consultar Clientes</a>';
            }
            
            $menu .= '</div></div>';
        }
        
        // Produtos
        if (in_array('produtos', $accessiblePages)) {
            $menu .= '
            <div class="dropdown">
                <a href="produtos.php">Produtos</a>
                <div class="dropdown-content">';
            
            if ($this->hasPagePermission('produtos', 'create')) {
                $menu .= '<a href="cadastro_produto.php">Inserir Produto</a>';
            }
            if ($this->hasPagePermission('produtos', 'view')) {
                $menu .= '<a href="consulta_produto.php">Consultar Produtos</a>';
            }
            
            $menu .= '</div></div>';
        }
        
        // Empenhos
        if (in_array('empenhos', $accessiblePages)) {
            $menu .= '
            <div class="dropdown">
                <a href="empenhos.php">Empenhos</a>
                <div class="dropdown-content">';
            
            if ($this->hasPagePermission('empenhos', 'create')) {
                $menu .= '<a href="cadastro_empenho.php">Inserir Empenho</a>';
            }
            if ($this->hasPagePermission('empenhos', 'view')) {
                $menu .= '<a href="consulta_empenho.php">Consultar Empenho</a>';
            }
            
            $menu .= '</div></div>';
        }
        
        // Financeiro
        if (in_array('financeiro', $accessiblePages)) {
            $menu .= '
            <div class="dropdown">
                <a href="financeiro.php">Financeiro</a>
                <div class="dropdown-content">
                    <a href="contas_a_receber.php">Contas a Receber</a>
                    <a href="contas_recebidas_geral.php">Contas Recebidas</a>
                    <a href="contas_a_pagar.php">Contas a Pagar</a>
                    <a href="contas_pagas.php">Contas Pagas</a>
                    <a href="caixa.php">Caixa</a>
                </div>
            </div>';
        }
        
        // Transportadoras
        if (in_array('transportadoras', $accessiblePages)) {
            $menu .= '
            <div class="dropdown">
                <a href="transportadoras.php">Transportadoras</a>
                <div class="dropdown-content">';
            
            if ($this->hasPagePermission('transportadoras', 'create')) {
                $menu .= '<a href="cadastro_transportadoras.php">Inserir Transportadora</a>';
            }
            if ($this->hasPagePermission('transportadoras', 'view')) {
                $menu .= '<a href="consulta_transportadoras.php">Consultar Transportadora</a>';
            }
            
            $menu .= '</div></div>';
        }
        
        // Fornecedores
        if (in_array('fornecedores', $accessiblePages)) {
            $menu .= '
            <div class="dropdown">
                <a href="fornecedores.php">Fornecedores</a>
                <div class="dropdown-content">';
            
            if ($this->hasPagePermission('fornecedores', 'create')) {
                $menu .= '<a href="cadastro_fornecedores.php">Inserir Fornecedor</a>';
            }
            if ($this->hasPagePermission('fornecedores', 'view')) {
                $menu .= '<a href="consulta_fornecedores.php">Consultar Fornecedor</a>';
            }
            
            $menu .= '</div></div>';
        }
        
        // Vendas
        if (in_array('vendas', $accessiblePages)) {
            $menu .= '
            <div class="dropdown">
                <a href="vendas.php">Vendas</a>
                <div class="dropdown-content">';
            
            if ($this->hasPagePermission('vendas', 'create')) {
                $menu .= '<a href="cadastro_vendas.php">Inserir Venda</a>';
            }
            if ($this->hasPagePermission('vendas', 'view')) {
                $menu .= '<a href="consulta_vendas.php">Consultar Venda</a>';
            }
            
            $menu .= '</div></div>';
        }
        
        // Compras
        if (in_array('compras', $accessiblePages)) {
            $menu .= '
            <div class="dropdown">
                <a href="compras.php">Compras</a>
                <div class="dropdown-content">';
            
            if ($this->hasPagePermission('compras', 'create')) {
                $menu .= '<a href="cadastro_compras.php">Inserir Compras</a>';
            }
            if ($this->hasPagePermission('compras', 'view')) {
                $menu .= '<a href="consulta_compras.php">Consultar Compras</a>';
            }
            
            $menu .= '</div></div>';
        }
        
        // Usuários (apenas Admin)
        if (in_array('usuarios', $accessiblePages)) {
            $menu .= '
            <div class="dropdown">
                <a href="usuario.php">Usuários</a>
                <div class="dropdown-content">
                    <a href="signup.php">Inserir Novo Usuário</a>
                    <a href="consulta_usuario.php">Consultar Usuário</a>
                </div>
            </div>';
        }
        
        // Funcionários (apenas Admin)
        if (in_array('funcionarios', $accessiblePages)) {
            $menu .= '
            <div class="dropdown">
                <a href="funcionarios.php">Funcionários</a>
                <div class="dropdown-content">
                    <a href="cadastro_funcionario.php">Inserir Novo Funcionário</a>
                    <a href="consulta_funcionario.php">Consultar Funcionário</a>
                </div>
            </div>';
        }
        
        // Investimentos
        if (in_array('investimentos', $accessiblePages)) {
            $menu .= '
            <div class="dropdown">
                <a href="investimentos.php">Investimentos</a>
                <div class="dropdown-content">
                    <a href="cadastro_investimento.php">Inserir Investimento</a>
                    <a href="consulta_investimento.php">Consultar Investimentos</a>
                    <a href="relatorio_investimento.php">Relatórios</a>
                </div>
            </div>';
        }
        
        // Link de logout
        $menu .= '<a href="logout.php" style="border-left: 2px solid rgba(255,255,255,0.3);">
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
            'Investidor' => 'Investidor'
        ];
        
        return $names[$permission] ?? $permission;
    }
}

// Função auxiliar para incluir o sistema de permissões em qualquer página
function initPermissions($pdo = null) {
    global $permissionManager;
    
    if (!$pdo) {
        include_once('db.php');
    }
    
    $permissionManager = new PermissionManager($pdo);
    $permissionManager->checkLogin();
    
    return $permissionManager;
}

?>