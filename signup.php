<?php
// =====================================================
// SIGNUP.PHP - SISTEMA UNIFICADO COM E-MAIL
// LicitaSis - Sistema de Gestão de Licitações
// =====================================================

session_start();

// Inclui os arquivos necessários
require_once 'db.php';
require_once 'permissions.php';

// =====================================================
// CONFIGURAÇÕES DE E-MAIL - ALTERE AQUI
// =====================================================
$EMAIL_CONFIG = [
    // ⚠️ CONFIGURE SUAS CREDENCIAIS AQUI
    'smtp_enabled' => true, // false para usar mail() do PHP
    'smtp_host' => 'mail.licitasis.com', // smtp.gmail.com, smtp-mail.outlook.com, smtp.hostinger.com
    'smtp_port' => 587, // 587 para TLS, 465 para SSL
    'smtp_security' => 'tls', // 'tls' ou 'ssl'
    'smtp_username' => 'licitasis@licitasis.com', // ⚠️ ALTERE AQUI
    'smtp_password' => 'My*8UEkC8&V--w*@', // ⚠️ ALTERE AQUI (senha de app para Gmail)
    
    // Informações do remetente
    'from_email' => 'noreply@licitasis.com.br',
    'from_name' => 'LicitaSis - Sistema de Licitações',
    
    // Configurações gerais
    'charset' => 'UTF-8',
    'timeout' => 30,
    'debug' => false, // true para debug SMTP
    'log_emails' => true, // salvar log no banco
];

// =====================================================
// CLASSE DE E-MAIL INTEGRADA
// =====================================================
class EmailSender {
    private $config;
    private $pdo;
    
    public function __construct($config, $pdo = null) {
        $this->config = $config;
        $this->pdo = $pdo;
    }
    
    /**
     * Envia e-mail de boas-vindas
     */
    public function sendWelcomeEmail($userEmail, $userName, $userPassword, $userPermission = '', $createdBy = '') {
        $subject = "Bem-vindo ao LicitaSis - Suas credenciais de acesso";
        $body = $this->getWelcomeEmailTemplate($userName, $userEmail, $userPassword, $userPermission, $createdBy);
        
        return $this->sendEmail($userEmail, $userName, $subject, $body);
    }
    
    /**
     * Função principal para enviar e-mail
     */
    public function sendEmail($to, $toName, $subject, $body) {
        try {
            if ($this->config['smtp_enabled'] && $this->canUsePHPMailer()) {
                return $this->sendViaSMTP($to, $toName, $subject, $body);
            } else {
                return $this->sendViaNativeMail($to, $toName, $subject, $body);
            }
        } catch (Exception $e) {
            $this->logError("Erro ao enviar e-mail: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verifica se PHPMailer está disponível
     */
    private function canUsePHPMailer() {
        // Tenta carregar PHPMailer de diferentes formas
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return true;
        }
        
        // Composer
        if (file_exists('vendor/autoload.php')) {
            require_once 'vendor/autoload.php';
            return class_exists('PHPMailer\PHPMailer\PHPMailer');
        }
        
        // Manual
        if (file_exists('includes/PHPMailer/src/PHPMailer.php')) {
            require_once 'includes/PHPMailer/src/Exception.php';
            require_once 'includes/PHPMailer/src/PHPMailer.php';
            require_once 'includes/PHPMailer/src/SMTP.php';
            return true;
        }
        
        return false;
    }
    
    /**
     * Envia via SMTP (PHPMailer)
     */
    private function sendViaSMTP($to, $toName, $subject, $body) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Configuração SMTP
            $mail->isSMTP();
            $mail->Host = $this->config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['smtp_username'];
            $mail->Password = $this->config['smtp_password'];
            
            if ($this->config['smtp_security'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            $mail->Port = $this->config['smtp_port'];
            $mail->Timeout = $this->config['timeout'];
            
            // Debug
            if ($this->config['debug']) {
                $mail->SMTPDebug = 2;
                $mail->Debugoutput = 'html';
            }
            
            // Configuração do e-mail
            $mail->CharSet = $this->config['charset'];
            $mail->isHTML(true);
            $mail->setFrom($this->config['from_email'], $this->config['from_name']);
            $mail->addAddress($to, $toName);
            
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace('<br>', "\n", $body));
            
            $result = $mail->send();
            
            if ($result) {
                $this->logEmail($to, $subject, 'SMTP', 'Sucesso');
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->logError("Erro SMTP: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Envia via mail() nativo do PHP
     */
    private function sendViaNativeMail($to, $toName, $subject, $body) {
        $headers = [];
        $headers[] = 'From: ' . $this->config['from_name'] . ' <' . $this->config['from_email'] . '>';
        $headers[] = 'Reply-To: ' . $this->config['from_email'];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-type: text/html; charset=' . $this->config['charset'];
        $headers[] = 'X-Mailer: PHP/' . phpversion();
        
        $result = mail($to, $subject, $body, implode("\r\n", $headers));
        
        if ($result) {
            $this->logEmail($to, $subject, 'PHP_MAIL', 'Sucesso');
            return true;
        } else {
            $this->logError("Falha no envio via mail() para: $to");
            return false;
        }
    }
    
    /**
     * Template HTML responsivo para e-mail de boas-vindas
     */
    private function getWelcomeEmailTemplate($userName, $userEmail, $userPassword, $userPermission, $createdBy) {
        $currentDate = date('d/m/Y H:i');
        $loginUrl = $this->getSystemUrl() . '/login.php';
        $permissionText = $this->translatePermission($userPermission);
        
        return "
        <!DOCTYPE html>
        <html lang='pt-BR'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Bem-vindo ao LicitaSis</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    background: #f8f9fa;
                    margin: 0; 
                    padding: 20px; 
                }
                .container { 
                    max-width: 600px; 
                    margin: 0 auto; 
                    background: white; 
                    border-radius: 12px; 
                    overflow: hidden;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                }
                .header { 
                    background: linear-gradient(135deg, #2D893E 0%, #9DCEAC 100%); 
                    color: white; 
                    padding: 40px 30px; 
                    text-align: center; 
                }
                .header h1 { 
                    font-size: 28px; 
                    margin-bottom: 10px; 
                    font-weight: 700;
                }
                .header p { 
                    font-size: 16px; 
                    opacity: 0.9; 
                }
                .content { 
                    padding: 40px 30px; 
                }
                .welcome-message { 
                    font-size: 20px; 
                    margin-bottom: 25px; 
                    color: #2D893E;
                    font-weight: 600;
                }
                .credentials-box { 
                    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); 
                    border: 2px solid #00bfae; 
                    border-radius: 10px; 
                    padding: 25px; 
                    margin: 25px 0; 
                }
                .credentials-title { 
                    font-size: 18px; 
                    font-weight: 700; 
                    color: #2D893E; 
                    margin-bottom: 20px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .credential-item { 
                    display: flex; 
                    justify-content: space-between; 
                    align-items: center;
                    margin: 15px 0; 
                    padding: 12px 0;
                    border-bottom: 1px solid #dee2e6;
                }
                .credential-label { 
                    font-weight: 600; 
                    color: #6c757d; 
                    font-size: 14px;
                }
                .credential-value { 
                    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; 
                    background: white;
                    padding: 8px 12px;
                    border-radius: 6px;
                    border: 1px solid #dee2e6;
                    color: #495057;
                    font-size: 14px;
                }
                .password-highlight {
                    background: #fff3cd !important;
                    border-color: #ffc107 !important;
                    font-weight: bold;
                    color: #856404 !important;
                }
                .instructions { 
                    background: #d1ecf1; 
                    border-left: 4px solid #17a2b8; 
                    padding: 20px; 
                    margin: 25px 0; 
                    border-radius: 0 8px 8px 0;
                }
                .instructions h3 { 
                    color: #0c5460; 
                    margin-bottom: 15px; 
                    font-size: 16px;
                }
                .instructions ol { 
                    margin-left: 20px; 
                    color: #0c5460;
                }
                .instructions li { 
                    margin: 8px 0; 
                }
                .login-button { 
                    display: inline-block; 
                    background: linear-gradient(135deg, #00bfae 0%, #009d8f 100%); 
                    color: white; 
                    padding: 15px 30px; 
                    text-decoration: none; 
                    border-radius: 8px; 
                    font-weight: 600; 
                    margin: 20px 0;
                    transition: transform 0.3s ease;
                    font-size: 16px;
                }
                .login-button:hover { 
                    transform: translateY(-2px);
                    box-shadow: 0 5px 15px rgba(0, 191, 174, 0.3);
                }
                .security-notice { 
                    background: #f8d7da; 
                    border: 1px solid #f5c6cb; 
                    border-radius: 8px; 
                    padding: 20px; 
                    margin: 25px 0; 
                    border-left: 4px solid #dc3545;
                }
                .security-notice h3 { 
                    color: #721c24; 
                    margin-bottom: 15px; 
                    font-size: 16px;
                }
                .security-notice ul { 
                    margin-left: 20px;
                    color: #721c24; 
                }
                .security-notice li { 
                    margin: 8px 0; 
                }
                .footer { 
                    background: #343a40; 
                    color: #adb5bd; 
                    padding: 25px 30px; 
                    text-align: center; 
                    font-size: 14px;
                }
                .footer a { 
                    color: #00bfae; 
                    text-decoration: none; 
                }
                .system-info { 
                    background: #f8f9fa; 
                    border-radius: 8px; 
                    padding: 20px; 
                    margin: 20px 0; 
                    font-size: 14px; 
                    color: #6c757d;
                    border: 1px solid #dee2e6;
                }
                .emoji { font-size: 20px; margin-right: 8px; }
                
                /* Responsividade */
                @media (max-width: 600px) { 
                    .container { margin: 10px; border-radius: 8px; }
                    .content { padding: 25px 20px; }
                    .header { padding: 30px 20px; }
                    .header h1 { font-size: 24px; }
                    .credential-item { 
                        flex-direction: column; 
                        align-items: flex-start;
                        gap: 8px; 
                    }
                    .credential-value { 
                        width: 100%; 
                        word-break: break-all;
                    }
                    .login-button {
                        display: block;
                        text-align: center;
                        width: 100%;
                    }
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1><span class='emoji'>🎉</span>Bem-vindo ao LicitaSis!</h1>
                    <p>Sistema de Gestão de Licitações</p>
                </div>
                
                <div class='content'>
                    <div class='welcome-message'>
                        Olá, <strong>$userName</strong>!
                    </div>
                    
                    <p>Sua conta foi criada com sucesso no <strong>LicitaSis</strong>. Agora você tem acesso ao nosso sistema completo de gestão de licitações com todas as ferramentas necessárias para otimizar seus processos.</p>
                    
                    <div class='credentials-box'>
                        <div class='credentials-title'>
                            <span class='emoji'>🔐</span>Suas Credenciais de Acesso
                        </div>
                        
                        <div class='credential-item'>
                            <span class='credential-label'>E-mail de Login:</span>
                            <span class='credential-value'>$userEmail</span>
                        </div>
                        
                        <div class='credential-item'>
                            <span class='credential-label'>Senha Temporária:</span>
                            <span class='credential-value password-highlight'>$userPassword</span>
                        </div>
                        
                        <div class='credential-item'>
                            <span class='credential-label'>Nível de Acesso:</span>
                            <span class='credential-value'>$permissionText</span>
                        </div>
                    </div>
                    
                    <div class='instructions'>
                        <h3><span class='emoji'>📋</span>Como Acessar o Sistema:</h3>
                        <ol>
                            <li>Clique no botão \"Acessar Sistema\" abaixo</li>
                            <li>Digite seu e-mail e senha fornecidos acima</li>
                            <li>Explore as funcionalidades disponíveis para seu nível</li>
                            <li><strong>Recomendamos alterar sua senha</strong> no primeiro acesso</li>
                            <li>Configure seu perfil na seção \"Minha Conta\"</li>
                        </ol>
                    </div>
                    
                    <div style='text-align: center;'>
                        <a href='$loginUrl' class='login-button'>
                            <span class='emoji'>🚀</span>Acessar Sistema
                        </a>
                    </div>
                    
                    <div class='security-notice'>
                        <h3><span class='emoji'>🔒</span>Importante - Segurança</h3>
                        <ul>
                            <li><strong>Mantenha suas credenciais seguras</strong> - Nunca compartilhe com terceiros</li>
                            <li><strong>Altere sua senha</strong> - Recomendamos trocar no primeiro acesso</li>
                            <li><strong>Sempre faça logout</strong> - Saia do sistema ao terminar o uso</li>
                            <li><strong>Reporte problemas</strong> - Entre em contato se detectar atividades suspeitas</li>
                            <li><strong>Use conexão segura</strong> - Sempre acesse via HTTPS</li>
                        </ul>
                    </div>
                    
                    <div class='system-info'>
                        <strong><span class='emoji'>📊</span>Informações da Conta:</strong><br>
                        • <strong>Data de Criação:</strong> $currentDate<br>
                        • <strong>Criada por:</strong> $createdBy<br>
                        • <strong>Status:</strong> Ativa e Verificada<br>
                        • <strong>Nível de Acesso:</strong> $permissionText<br>
                        • <strong>ID da Sessão:</strong> " . substr(session_id(), 0, 8) . "...
                    </div>
                    
                    <p>Se você tiver dúvidas sobre o uso do sistema ou precisar de suporte técnico, nossa equipe está disponível através do e-mail <strong>suporte@licitasis.com.br</strong> ou pelo telefone <strong>(11) 9999-9999</strong>.</p>
                    
                    <p style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6;'>
                        Obrigado por fazer parte do LicitaSis! Estamos aqui para ajudar você a otimizar seus processos licitatórios.<br><br>
                        <strong>Equipe LicitaSis</strong><br>
                        <em>Inovação em Gestão de Licitações</em>
                    </p>
                </div>
                
                <div class='footer'>
                    <p><strong>LicitaSis</strong> - Sistema de Gestão de Licitações</p>
                    <p>Este é um e-mail automático, não responda a esta mensagem.</p>
                    <p>© " . date('Y') . " LicitaSis. Todos os direitos reservados.</p>
                    <p><a href='#'>Política de Privacidade</a> | <a href='#'>Termos de Uso</a></p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Traduz permissão para texto amigável
     */
    private function translatePermission($permission) {
        $permissions = [
            'Administrador' => 'Administrador - Acesso Total ao Sistema',
            'Usuario_Nivel_1' => 'Usuário Nível 1 - Visualização e Consultas',
            'Usuario_Nivel_2' => 'Usuário Nível 2 - Edição e Cadastros Limitados',
            'Usuario_Nivel_3' => 'Usuário Nível 3 - Acesso Avançado e Relatórios',
            'Investidor' => 'Investidor - Acesso a Dados Financeiros e Relatórios'
        ];
        
        return $permissions[$permission] ?? $permission;
    }
    
    /**
     * Obtém URL do sistema
     */
    private function getSystemUrl() {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host;
    }
    
    /**
     * Registra log de e-mail no banco
     */
    private function logEmail($to, $subject, $method, $status) {
        if (!$this->config['log_emails'] || !$this->pdo) return;
        
        try {
            $sql = "INSERT INTO email_log (recipient, subject, method, status, sent_at, ip_address) 
                    VALUES (:recipient, :subject, :method, :status, NOW(), :ip_address)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'recipient' => $to,
                'subject' => $subject,
                'method' => $method,
                'status' => $status,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
        } catch (Exception $e) {
            error_log("Erro ao registrar log de e-mail: " . $e->getMessage());
        }
    }
    
    /**
     * Registra erro no log
     */
    private function logError($message) {
        error_log("EmailSender: " . $message);
        
        if (!$this->pdo) return;
        
        try {
            $sql = "INSERT INTO email_log (recipient, subject, method, status, error_message, sent_at) 
                    VALUES ('system', 'ERROR', 'SYSTEM', 'Erro', :error_message, NOW())";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['error_message' => $message]);
            
        } catch (Exception $e) {
            // Log silencioso
        }
    }
    
    /**
     * Testa configuração de e-mail
     */
    public function testConfiguration($testEmail = null) {
        $testEmail = $testEmail ?: $this->config['smtp_username'];
        
        $subject = "✅ Teste de Configuração - LicitaSis";
        $body = "
        <div style='font-family: Arial, sans-serif; padding: 20px; background: #f8f9fa;'>
            <div style='max-width: 500px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);'>
                <h2 style='color: #2D893E; text-align: center;'>✅ Teste de E-mail</h2>
                <p>Se você recebeu este e-mail, a configuração está funcionando corretamente!</p>
                <hr style='margin: 20px 0; border: none; border-top: 1px solid #dee2e6;'>
                <p><strong>Data/Hora:</strong> " . date('d/m/Y H:i:s') . "</p>
                <p><strong>Método:</strong> " . ($this->canUsePHPMailer() ? 'SMTP (PHPMailer)' : 'PHP Mail') . "</p>
                <p><strong>Servidor:</strong> " . $this->config['smtp_host'] . "</p>
                <p><strong>Sistema:</strong> LicitaSis v2.0</p>
            </div>
        </div>";
        
        return $this->sendEmail($testEmail, 'Teste', $subject, $body);
    }
}

// =====================================================
// VERIFICAÇÕES E VALIDAÇÕES
// =====================================================

// Verifica se o usuário está logado
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    header('Location: login.php');
    exit;
}

// Inicializa o gerenciador de permissões
$permissionManager = new PermissionManager($pdo);

// Verifica se o usuário tem permissão para acessar usuários com ação create
if (!$permissionManager->hasPagePermission('usuarios', 'create')) {
    $_SESSION['error'] = 'Você não tem permissão para acessar esta página.';
    header('Location: dashboard.php');
    exit;
}

$error = "";
$success = false;
$createdEmail = "";
$createdPassword = "";
$createdPermission = "";
$createdName = "";
$emailSent = false;
$emailError = "";

$isAdmin = $permissionManager->isAdmin();
$currentUser = $_SESSION['user'];

// =====================================================
// FUNÇÕES AUXILIARES
// =====================================================

// Função para gerar senha aleatória segura
function generateRandomPassword($length = 12) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%&*';
    $password = '';
    $charactersLength = strlen($characters);
    
    for ($i = 0; $i < $length; $i++) {
        $randomIndex = random_int(0, $charactersLength - 1);
        $password .= $characters[$randomIndex];
    }
    return $password;
}

// Função para registrar log de auditoria
function logAuditAction($pdo, $userId, $userName, $action, $tableName, $recordId, $details) {
    try {
        $sql = "INSERT INTO audit_log (user_id, user_name, action, table_name, record_id, details, ip_address, user_agent) 
                VALUES (:user_id, :user_name, :action, :table_name, :record_id, :details, :ip_address, :user_agent)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'user_name' => $userName,
            'action' => $action,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'details' => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Desconhecido',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido'
        ]);
    } catch (Exception $e) {
        error_log("Erro ao registrar auditoria: " . $e->getMessage());
    }
}

// Função para validar força da senha
function validatePasswordStrength($password) {
    $score = 0;
    $feedback = [];
    
    if (strlen($password) >= 8) $score++;
    else $feedback[] = 'pelo menos 8 caracteres';
    
    if (preg_match('/[a-z]/', $password)) $score++;
    else $feedback[] = 'letras minúsculas';
    
    if (preg_match('/[A-Z]/', $password)) $score++;
    else $feedback[] = 'letras maiúsculas';
    
    if (preg_match('/[0-9]/', $password)) $score++;
    else $feedback[] = 'números';
    
    if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;
    else $feedback[] = 'caracteres especiais';
    
    return ['score' => $score, 'feedback' => $feedback];
}

// =====================================================
// PROCESSAMENTO DO FORMULÁRIO
// =====================================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $permission = trim($_POST['permission']);
        $passwordOption = $_POST['password_option'];
        $customPassword = isset($_POST['custom_password']) ? trim($_POST['custom_password']) : '';
        $sendWelcomeEmail = isset($_POST['send_welcome_email']);

        // Validações básicas
        if (empty($name) || empty($email) || empty($permission)) {
            throw new Exception("Nome, e-mail e permissão são obrigatórios!");
        }

        // Valida e-mail
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Por favor, insira um e-mail válido!");
        }
        
        // Verifica se a permissão selecionada é válida
        $validPermissions = ['Administrador', 'Usuario_Nivel_1', 'Usuario_Nivel_2', 'Usuario_Nivel_3', 'Investidor'];
        if (!in_array($permission, $validPermissions)) {
            throw new Exception("Permissão inválida!");
        }
        
        // Apenas administradores podem criar outros administradores
        if ($permission === 'Administrador' && !$isAdmin) {
            throw new Exception("Apenas administradores podem criar outros administradores!");
        }
        
        // Verifica senha personalizada se foi selecionada
        if ($passwordOption === 'custom') {
            if (empty($customPassword) || strlen($customPassword) < 6) {
                throw new Exception("A senha personalizada deve ter pelo menos 6 caracteres!");
            }
            
            $passwordValidation = validatePasswordStrength($customPassword);
            if ($passwordValidation['score'] < 3) {
                throw new Exception("Senha muito fraca! Adicione: " . implode(', ', $passwordValidation['feedback']));
            }
        }
        
        // Verifica se o e-mail já está registrado
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser) {
            throw new Exception("Este e-mail já está registrado no sistema!");
        }

        // Define a senha com base na opção escolhida
        if ($passwordOption === 'custom') {
            $generatedPassword = $customPassword;
        } else {
            $generatedPassword = generateRandomPassword();
        }

        // Hash da senha antes de armazenar
        $hashedPassword = password_hash($generatedPassword, PASSWORD_BCRYPT);

        // Inicia transação
        $pdo->beginTransaction();

        // Insere o novo usuário no banco de dados
        $sql = "INSERT INTO users (name, email, password, permission) 
                VALUES (:name, :email, :password, :permission)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':permission', $permission);

        if ($stmt->execute()) {
            $newUserId = $pdo->lastInsertId();
            
            // Log de auditoria
            logAuditAction(
                $pdo,
                $currentUser['id'],
                $currentUser['name'],
                'CREATE',
                'users',
                $newUserId,
                "Usuário criado: $name ($email) - Permissão: $permission"
            );

            // ✅ ENVIO DE E-MAIL DE BOAS-VINDAS
            if ($sendWelcomeEmail) {
                try {
                    $emailSender = new EmailSender($EMAIL_CONFIG, $pdo);
                    $emailSent = $emailSender->sendWelcomeEmail(
                        $email, 
                        $name, 
                        $generatedPassword, 
                        $permission, 
                        $currentUser['name']
                    );
                    
                    if (!$emailSent) {
                        $emailError = 'Não foi possível enviar o e-mail de boas-vindas. Verifique as configurações SMTP.';
                    }
                } catch (Exception $emailException) {
                    $emailError = 'Erro ao enviar e-mail: ' . $emailException->getMessage();
                    error_log("Erro no envio de e-mail: " . $emailException->getMessage());
                }
            }

            // Commit da transação
            $pdo->commit();

            $success = true;
            $createdName = $name;
            $createdEmail = $email;
            $createdPassword = $generatedPassword;
            $createdPermission = $permission;

        } else {
            $pdo->rollback();
            throw new Exception("Erro ao realizar o cadastro!");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollback();
        }
        $error = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollback();
        }
        $error = "Erro ao realizar o cadastro: " . $e->getMessage();
    }
}

include('includes/header_template.php');
if (function_exists('renderHeader')) {
    renderHeader("Cadastro de Usuários - LicitaSis", "usuarios");
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Usuários - LicitaSis</title>
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
        }

        .container {
            max-width: 900px;
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

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            border: 2px solid transparent;
        }

        .back-btn:hover {
            color: var(--secondary-color);
            background: rgba(0, 191, 174, 0.1);
            border-color: var(--secondary-color);
            transform: translateX(-5px);
        }

        .permission-notice {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .email-config-notice {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            color: #856404;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            font-weight: 600;
        }

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
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
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

        .form-control.success-state {
            border-color: var(--success-color);
            background: rgba(40, 167, 69, 0.05);
        }

        .form-control.error-state {
            border-color: var(--danger-color);
            background: rgba(220, 53, 69, 0.05);
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .help-text {
            display: block;
            color: var(--medium-gray);
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        /* Password Options Styles */
        .password-section {
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            margin: 1.5rem 0;
        }

        .password-section h3 {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .password-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .password-option {
            position: relative;
        }

        .password-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .password-option label {
            display: block;
            padding: 1.5rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
            background: white;
            margin-bottom: 0;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .password-option label::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(0, 191, 174, 0.1), transparent);
            transition: left 0.5s;
        }

        .password-option input[type="radio"]:checked + label {
            border-color: var(--secondary-color);
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 191, 174, 0.3);
        }

        .password-option input[type="radio"]:checked + label::before {
            left: 100%;
        }

        .custom-password-field {
            display: none;
            animation: slideDown 0.3s ease;
        }

        .custom-password-field.show {
            display: block;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .password-strength {
            margin-top: 0.5rem;
            font-size: 0.85rem;
        }

        .strength-meter {
            height: 4px;
            background: var(--border-color);
            border-radius: 2px;
            margin: 0.5rem 0;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .strength-very-weak { background: #dc3545; width: 20%; }
        .strength-weak { background: #fd7e14; width: 40%; }
        .strength-medium { background: #ffc107; width: 60%; }
        .strength-strong { background: #28a745; width: 80%; }
        .strength-very-strong { background: #20c997; width: 100%; }

        .generated-info {
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            padding: 2rem;
            border-radius: var(--radius);
            border-left: 4px solid var(--secondary-color);
            margin-top: 1.5rem;
            animation: slideInUp 0.5s ease;
        }

        @keyframes slideInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .generated-info h4 {
            color: var(--primary-color);
            margin-bottom: 1rem;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .generated-info .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .generated-info .info-item {
            background: white;
            padding: 1rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
        }

        .generated-info .info-label {
            font-weight: 600;
            color: var(--medium-gray);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }

        .generated-info .info-value {
            color: var(--dark-gray);
            font-size: 1rem;
            word-break: break-word;
        }

        .email-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .email-status.success {
            color: var(--success-color);
        }

        .email-status.error {
            color: var(--danger-color);
        }

        .email-status.not-sent {
            color: var(--medium-gray);
        }

        .warning-text {
            margin-top: 1.5rem;
            padding: 1rem;
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid var(--warning-color);
            border-radius: var(--radius-sm);
            color: #856404;
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

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

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Modal styles */
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
            margin: 5% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 90%;
            max-width: 600px;
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
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }

        .modal-buttons .btn {
            padding: 0.75rem 1.5rem;
            min-width: 140px;
        }

        @keyframes fadeInModal {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .container {
                margin: 1.5rem 1rem;
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

            .password-options {
                grid-template-columns: 1fr;
            }

            .btn-container {
                flex-direction: column;
                gap: 1rem;
            }

            .btn {
                width: 100%;
            }

            .modal-buttons {
                flex-direction: column;
            }

            .generated-info .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <a href="usuario.php" class="back-btn">
        <i class="fas fa-arrow-left"></i>
        Voltar para Usuários
    </a>

    
    <div class="permission-notice">
        <i class="fas fa-shield-alt"></i>
        <strong>Criando novo usuário</strong> - Você está logado como <?php echo $permissionManager->getPermissionName($currentUser['permission']); ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <h2>
        <i class="fas fa-user-plus"></i>
        Cadastro de Usuários
    </h2>

    <form class="form-container" action="signup.php" method="POST" id="signupForm" onsubmit="return validarFormulario()">
        <div class="form-row">
            <div class="form-group">
                <label for="name" class="required">
                    <i class="fas fa-user"></i>
                    Nome Completo
                </label>
                <input type="text" 
                       id="name" 
                       name="name" 
                       class="form-control" 
                       placeholder="Digite o nome completo do usuário"
                       value="<?php echo $success ? '' : (isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''); ?>"
                       required>
                <small class="help-text">Nome que aparecerá no sistema</small>
            </div>

            <div class="form-group">
                <label for="email" class="required">
                    <i class="fas fa-envelope"></i>
                    E-mail
                </label>
                <input type="email" 
                       id="email" 
                       name="email" 
                       class="form-control" 
                       placeholder="usuario@exemplo.com"
                       value="<?php echo $success ? '' : (isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''); ?>"
                       required>
                <small class="help-text">E-mail para acesso ao sistema</small>
            </div>
        </div>

        <div class="form-group">
            <label for="permission" class="required">
                <i class="fas fa-shield-alt"></i>
                Nível de Acesso
            </label>
            <select id="permission" name="permission" class="form-control" required>
                <option value="">Selecione o nível de acesso</option>
                <?php if ($isAdmin): ?>
                <option value="Administrador" <?php echo (isset($_POST['permission']) && $_POST['permission'] === 'Administrador') ? 'selected' : ''; ?>>
                    Administrador - Acesso total ao sistema
                </option>
                <?php endif; ?>
                <option value="Usuario_Nivel_1" <?php echo (isset($_POST['permission']) && $_POST['permission'] === 'Usuario_Nivel_1') ? 'selected' : ''; ?>>
                    Usuário Nível 1 - Apenas visualização
                </option>
                <option value="Usuario_Nivel_2" <?php echo (isset($_POST['permission']) && $_POST['permission'] === 'Usuario_Nivel_2') ? 'selected' : ''; ?>>
                    Usuário Nível 2 - Consulta e edição limitada
                </option>
                <option value="Usuario_Nivel_3" <?php echo (isset($_POST['permission']) && $_POST['permission'] === 'Usuario_Nivel_3') ? 'selected' : ''; ?>>
                    Usuário Nível 3 - Acesso avançado
                </option>
                <option value="Investidor" <?php echo (isset($_POST['permission']) && $_POST['permission'] === 'Investidor') ? 'selected' : ''; ?>>
                    Investidor - Acesso a dados financeiros
                </option>
            </select>
            <div id="permission-desc" style="margin-top: 0.5rem; font-size: 0.85rem; color: var(--medium-gray);"></div>
        </div>

        <div class="password-section">
            <h3>
                <i class="fas fa-key"></i>
                Configuração de Senha
            </h3>

            <div class="password-options">
                <div class="password-option">
                    <input type="radio" 
                           id="random_password" 
                           name="password_option" 
                           value="random" 
                           <?php echo (!isset($_POST['password_option']) || $_POST['password_option'] === 'random') ? 'checked' : ''; ?>>
                    <label for="random_password">
                        <i class="fas fa-dice" style="font-size: 1.5rem; margin-bottom: 0.5rem;"></i><br>
                        <strong>Gerar Automaticamente</strong><br>
                        <small>Senha segura gerada pelo sistema</small>
                    </label>
                </div>
                
                <div class="password-option">
                    <input type="radio" 
                           id="custom_password_option" 
                           name="password_option" 
                           value="custom"
                           <?php echo (isset($_POST['password_option']) && $_POST['password_option'] === 'custom') ? 'checked' : ''; ?>>
                    <label for="custom_password_option">
                        <i class="fas fa-edit" style="font-size: 1.5rem; margin-bottom: 0.5rem;"></i><br>
                        <strong>Senha Personalizada</strong><br>
                        <small>Definir senha manualmente</small>
                    </label>
                </div>
            </div>

            <div class="form-group custom-password-field" id="customPasswordField">
                <label for="custom_password" class="required">
                    <i class="fas fa-lock"></i>
                    Digite a Senha
                </label>
                <input type="password" 
                       id="custom_password" 
                       name="custom_password" 
                       class="form-control" 
                       placeholder="Digite uma senha segura (mínimo 6 caracteres)"
                       value="<?php echo htmlspecialchars($_POST['custom_password'] ?? ''); ?>">
                
                <div class="password-strength" id="passwordStrength">
                    <div class="strength-meter">
                        <div class="strength-fill" id="strengthFill"></div>
                    </div>
                    <div id="strengthText"></div>
                    <div id="strengthFeedback" style="font-size: 0.8rem; color: var(--medium-gray);"></div>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer;">
                <input type="checkbox" 
                       id="send_welcome_email" 
                       name="send_welcome_email" 
                       style="transform: scale(1.2);"
                       <?php echo (isset($_POST['send_welcome_email']) && $_POST['send_welcome_email']) ? 'checked' : ''; ?>>
                <i class="fas fa-envelope" style="color: var(--secondary-color);"></i>
                <span>Enviar e-mail de boas-vindas com as credenciais de acesso</span>
            </label>
            <small class="help-text">Se marcado, um e-mail será enviado automaticamente com as informações de login</small>
        </div>

        <div class="btn-container">
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-user-plus"></i>
                Cadastrar Usuário
            </button>
            <button type="reset" class="btn btn-secondary" onclick="limparFormulario()">
                <i class="fas fa-undo"></i>
                Limpar Campos
            </button>
        </div>
    </form>
</div>

<?php if ($success): ?>
<div class="generated-info">
    <h4>
        <i class="fas fa-check-circle"></i>
        Usuário Cadastrado com Sucesso!
    </h4>
    
    <div class="info-grid">
        <div class="info-item">
            <div class="info-label">Nome</div>
            <div class="info-value"><?php echo htmlspecialchars($createdName); ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">E-mail</div>
            <div class="info-value"><?php echo htmlspecialchars($createdEmail); ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Senha</div>
            <div class="info-value" style="font-family: monospace; font-weight: bold;">
                <?php echo htmlspecialchars($createdPassword); ?>
            </div>
        </div>
        <div class="info-item">
            <div class="info-label">Nível de Acesso</div>
            <div class="info-value"><?php echo $permissionManager->getPermissionName($createdPermission); ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">E-mail de Boas-vindas</div>
            <div class="info-value">
                <?php if (isset($_POST['send_welcome_email']) && $_POST['send_welcome_email']): ?>
                    <?php if ($emailSent): ?>
                        <div class="email-status success">
                            <i class="fas fa-check-circle"></i>
                            <span>Enviado com sucesso</span>
                        </div>
                    <?php else: ?>
                        <div class="email-status error">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>Falha no envio</span>
                        </div>
                        <?php if ($emailError): ?>
                            <small style="color: var(--medium-gray); display: block; margin-top: 0.25rem;">
                                <?php echo htmlspecialchars($emailError); ?>
                            </small>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="email-status not-sent">
                        <i class="fas fa-minus-circle"></i>
                        <span>Não solicitado</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="warning-text">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>IMPORTANTE:</strong> Anote essas informações em local seguro! Esta é a única vez que a senha será exibida.
    </div>
</div>

<!-- Modal de sucesso -->
<div id="successModal" class="modal" style="display: block;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-check-circle"></i>
                Usuário Cadastrado!
            </h3>
        </div>
        <div class="modal-body">
            <p>O usuário <strong><?php echo htmlspecialchars($createdName); ?></strong> foi cadastrado com sucesso no sistema.</p>
            
            <?php if (isset($_POST['send_welcome_email']) && $_POST['send_welcome_email']): ?>
                <?php if ($emailSent): ?>
                    <p style="color: var(--success-color);">
                        <i class="fas fa-envelope"></i> 
                        E-mail de boas-vindas enviado para <strong><?php echo htmlspecialchars($createdEmail); ?></strong>
                    </p>
                <?php else: ?>
                    <p style="color: var(--warning-color);">
                        <i class="fas fa-exclamation-triangle"></i> 
                        E-mail de boas-vindas não pôde ser enviado. Verifique as configurações SMTP.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
            
            <p>As credenciais de acesso estão exibidas acima e devem ser compartilhadas com segurança.</p>
            
            <div class="modal-buttons">
                <button class="btn btn-primary" onclick="goToUsuarios()">
                    <i class="fas fa-users"></i>
                    Ver Usuários
                </button>
                <button class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-plus"></i>
                    Cadastrar Outro
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let strengthTimeout = null;

// ===========================================
// GERENCIAMENTO DE SENHAS
// ===========================================
function initPasswordOptions() {
    const randomOption = document.getElementById('random_password');
    const customOption = document.getElementById('custom_password_option');
    const customField = document.getElementById('customPasswordField');
    const customPasswordInput = document.getElementById('custom_password');
    
    function toggleCustomPasswordField() {
        if (customOption.checked) {
            customField.classList.add('show');
            customPasswordInput.required = true;
            setTimeout(() => customPasswordInput.focus(), 300);
        } else {
            customField.classList.remove('show');
            customPasswordInput.required = false;
            customPasswordInput.value = '';
            resetPasswordStrength();
        }
    }
    
    randomOption.addEventListener('change', toggleCustomPasswordField);
    customOption.addEventListener('change', toggleCustomPasswordField);
    
    // Inicializa o estado correto
    toggleCustomPasswordField();
    
    // Verificador de força da senha
    if (customPasswordInput) {
        customPasswordInput.addEventListener('input', function() {
            if (strengthTimeout) {
                clearTimeout(strengthTimeout);
            }
            
            strengthTimeout = setTimeout(() => {
                checkPasswordStrength(this.value);
            }, 300);
        });
    }
}

function checkPasswordStrength(password) {
    const strengthFill = document.getElementById('strengthFill');
    const strengthText = document.getElementById('strengthText');
    const strengthFeedback = document.getElementById('strengthFeedback');
    
    if (!strengthFill || !strengthText || !strengthFeedback) return;
    
    if (password.length === 0) {
        resetPasswordStrength();
        return;
    }
    
    let score = 0;
    let feedback = [];
    
    // Critérios de força
    if (password.length >= 8) score++;
    else feedback.push('pelo menos 8 caracteres');
    
    if (/[a-z]/.test(password)) score++;
    else feedback.push('letras minúsculas');
    
    if (/[A-Z]/.test(password)) score++;
    else feedback.push('letras maiúsculas');
    
    if (/[0-9]/.test(password)) score++;
    else feedback.push('números');
    
    if (/[^A-Za-z0-9]/.test(password)) score++;
    else feedback.push('caracteres especiais');
    
    // Penalidades
    if (password.length < 6) score = Math.max(0, score - 2);
    if (/(.)\1{2,}/.test(password)) score = Math.max(0, score - 1); // caracteres repetidos
    
    const strengthLevels = [
        { class: 'strength-very-weak', text: 'Muito Fraca', color: '#dc3545' },
        { class: 'strength-weak', text: 'Fraca', color: '#fd7e14' },
        { class: 'strength-medium', text: 'Regular', color: '#ffc107' },
        { class: 'strength-strong', text: 'Boa', color: '#28a745' },
        { class: 'strength-very-strong', text: 'Muito Forte', color: '#20c997' }
    ];
    
    const level = Math.min(score, strengthLevels.length - 1);
    const currentLevel = strengthLevels[level];
    
    strengthFill.className = `strength-fill ${currentLevel.class}`;
    strengthText.innerHTML = `
        <span style="color: ${currentLevel.color}; font-weight: 600;">
            Força: ${currentLevel.text}
        </span>
    `;
    
    if (feedback.length > 0) {
        strengthFeedback.innerHTML = `<strong>Adicione:</strong> ${feedback.join(', ')}`;
    } else {
        strengthFeedback.innerHTML = '<span style="color: var(--success-color);">✓ Senha forte!</span>';
    }
}

function resetPasswordStrength() {
    const strengthFill = document.getElementById('strengthFill');
    const strengthText = document.getElementById('strengthText');
    const strengthFeedback = document.getElementById('strengthFeedback');
    
    if (strengthFill) strengthFill.className = 'strength-fill';
    if (strengthText) strengthText.innerHTML = '';
    if (strengthFeedback) strengthFeedback.innerHTML = '';
}

// ===========================================
// DESCRIÇÕES DE PERMISSÕES
// ===========================================
function initPermissionDescriptions() {
    const permissionSelect = document.getElementById('permission');
    const permissionDesc = document.getElementById('permission-desc');
    
    if (!permissionSelect || !permissionDesc) return;
    
    const descriptions = {
        'Administrador': {
            text: 'Acesso completo a todas as funcionalidades do sistema, incluindo gestão de usuários e funcionários.',
            icon: 'fas fa-crown',
            color: 'var(--warning-color)'
        },
        'Usuario_Nivel_1': {
            text: 'Acesso apenas para visualização de dados. Não pode editar, criar ou excluir informações.',
            icon: 'fas fa-eye',
            color: 'var(--info-color)'
        },
        'Usuario_Nivel_2': {
            text: 'Pode consultar e editar dados do sistema, exceto usuários e funcionários.',
            icon: 'fas fa-edit',
            color: 'var(--primary-color)'
        },
        'Usuario_Nivel_3': {
            text: 'Acesso avançado com permissões estendidas, incluindo algumas operações administrativas.',
            icon: 'fas fa-user-cog',
            color: 'var(--secondary-color)'
        },
        'Investidor': {
            text: 'Acesso específico a dados financeiros e relatórios de investimento.',
            icon: 'fas fa-chart-line',
            color: 'var(--warning-color)'
        }
    };
    
    permissionSelect.addEventListener('change', function() {
        const selectedPermission = this.value;
        
        if (selectedPermission && descriptions[selectedPermission]) {
            const desc = descriptions[selectedPermission];
            permissionDesc.innerHTML = `
                <div style="
                    display: flex; 
                    align-items: center; 
                    gap: 0.5rem; 
                    padding: 0.75rem; 
                    background: rgba(0, 191, 174, 0.1); 
                    border-radius: var(--radius-sm);
                    border-left: 3px solid ${desc.color};
                ">
                    <i class="${desc.icon}" style="color: ${desc.color};"></i>
                    <span>${desc.text}</span>
                </div>
            `;
        } else {
            permissionDesc.innerHTML = '';
        }
    });
    
    // Mostra descrição inicial se há valor selecionado
    if (permissionSelect.value) {
        permissionSelect.dispatchEvent(new Event('change'));
    }
}

// ===========================================
// VALIDAÇÃO DO FORMULÁRIO
// ===========================================
function validarFormulario() {
    const name = document.getElementById('name')?.value.trim();
    const email = document.getElementById('email')?.value.trim();
    const permission = document.getElementById('permission')?.value;
    const customOption = document.getElementById('custom_password_option')?.checked;
    const customPassword = document.getElementById('custom_password')?.value;
    
    // Validação de campos obrigatórios
    if (!name) {
        showToast('O nome é obrigatório!', 'error');
        document.getElementById('name')?.focus();
        return false;
    }
    
    if (name.length < 2) {
        showToast('Nome deve ter pelo menos 2 caracteres!', 'error');
        document.getElementById('name')?.focus();
        return false;
    }
    
    if (!email) {
        showToast('O e-mail é obrigatório!', 'error');
        document.getElementById('email')?.focus();
        return false;
    }
    
    if (!isValidEmail(email)) {
        showToast('Por favor, insira um e-mail válido!', 'error');
        document.getElementById('email')?.focus();
        return false;
    }
    
    if (!permission) {
        showToast('Selecione um nível de acesso!', 'error');
        document.getElementById('permission')?.focus();
        return false;
    }
    
    // Validação de senha personalizada
    if (customOption) {
        if (!customPassword || customPassword.length < 6) {
            showToast('A senha personalizada deve ter pelo menos 6 caracteres!', 'error');
            document.getElementById('custom_password')?.focus();
            return false;
        }
        
        // Verifica força da senha
        const strengthValidation = validatePasswordStrengthForSubmit(customPassword);
        if (strengthValidation.score < 3) {
            showToast(`Senha muito fraca! Adicione: ${strengthValidation.feedback.join(', ')}`, 'error');
            document.getElementById('custom_password')?.focus();
            return false;
        }
    }
    
    // Mostra loading no botão
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cadastrando...';
    }
    
    return true;
}

function validatePasswordStrengthForSubmit(password) {
    let score = 0;
    let feedback = [];
    
    if (password.length >= 8) score++;
    else feedback.push('pelo menos 8 caracteres');
    
    if (/[a-z]/.test(password)) score++;
    else feedback.push('letras minúsculas');
    
    if (/[A-Z]/.test(password)) score++;
    else feedback.push('letras maiúsculas');
    
    if (/[0-9]/.test(password)) score++;
    else feedback.push('números');
    
    if (/[^A-Za-z0-9]/.test(password)) score++;
    else feedback.push('caracteres especiais');
    
    return { score, feedback };
}

function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

// ===========================================
// FUNÇÕES DO MODAL
// ===========================================
function goToUsuarios() {
    window.location.href = 'usuario.php?success=' + encodeURIComponent('Usuário cadastrado com sucesso!');
}

function closeModal() {
    const modal = document.getElementById('successModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    limparFormulario();
    
    setTimeout(() => {
        const nameInput = document.getElementById('name');
        if (nameInput) nameInput.focus();
    }, 100);
}

function limparFormulario() {
    const form = document.getElementById('signupForm');
    if (form) form.reset();
    
    // Reseta estados visuais
    document.querySelectorAll('.form-control').forEach(input => {
        input.classList.remove('success-state', 'error-state');
    });
    
    // Reseta força da senha
    resetPasswordStrength();
    
    // Reseta descrição de permissão
    const permissionDesc = document.getElementById('permission-desc');
    if (permissionDesc) {
        permissionDesc.innerHTML = '';
    }
    
    // Reseta opção de senha para automática
    const randomOption = document.getElementById('random_password');
    if (randomOption) {
        randomOption.checked = true;
        randomOption.dispatchEvent(new Event('change'));
    }
    
    showToast('Formulário limpo com sucesso!', 'info');
}

// ===========================================
// TOAST NOTIFICATIONS
// ===========================================
function showToast(message, type = 'info', duration = 4000) {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    let icon, backgroundColor;
    switch(type) {
        case 'success': 
            icon = 'check-circle'; 
            backgroundColor = '#28a745';
            break;
        case 'error': 
            icon = 'exclamation-circle'; 
            backgroundColor = '#dc3545';
            break;
        case 'warning': 
            icon = 'exclamation-triangle'; 
            backgroundColor = '#ffc107';
            break;
        default: 
            icon = 'info-circle';
            backgroundColor = '#17a2b8';
    }
    
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${backgroundColor};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 1001;
        animation: slideInRight 0.3s ease;
        font-weight: 500;
        min-width: 300px;
        max-width: 400px;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    `;
    
    toast.innerHTML = `<i class="fas fa-${icon}"></i><span>${message}</span>`;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// ===========================================
// INICIALIZAÇÃO COMPLETA
// ===========================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('🔄 Iniciando sistema de cadastro de usuários...');
    
    // Inicializa todas as funcionalidades
    initPasswordOptions();
    initPermissionDescriptions();
    
    // Remove mensagens de erro/sucesso após 7 segundos
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 7000);
    
    // Foca no primeiro campo
    setTimeout(() => {
        const nameInput = document.getElementById('name');
        if (nameInput) nameInput.focus();
    }, 300);
    
    console.log('✅ Sistema de cadastro de usuários carregado com sucesso!');
});

// ===========================================
// HANDLERS DE EVENTOS GLOBAIS
// ===========================================

// Fecha modal ao clicar fora
window.onclick = function(event) {
    const modal = document.getElementById('successModal');
    if (event.target === modal) {
        closeModal();
    }
}

// Atalhos de teclado
document.addEventListener('keydown', function(e) {
    // Ctrl+S para submeter
    if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        if (validarFormulario()) {
            document.getElementById('signupForm')?.submit();
        }
    }
    
    // Ctrl+R para limpar
    if (e.ctrlKey && e.key === 'r') {
        e.preventDefault();
        limparFormulario();
    }
    
    // Escape para fechar modal
    if (e.key === 'Escape') {
        const modal = document.getElementById('successModal');
        if (modal && modal.style.display === 'block') {
            closeModal();
        }
    }
});

// CSS para animações dos toasts
const toastStyles = document.createElement('style');
toastStyles.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(toastStyles);

console.log('🚀 LicitaSis - Sistema Unificado de Cadastro com E-mail carregado:', {
    versao: '3.0 Unificado',
    funcionalidades: [
        '✅ Sistema de e-mail integrado no próprio arquivo',
        '✅ Configuração simples no início do código',
        '✅ Suporte automático a PHPMailer e mail() nativo',
        '✅ Template de e-mail responsivo e moderno',
        '✅ Validação completa e feedback visual',
        '✅ Interface moderna e acessível',
        '✅ Log automático de e-mails enviados',
        '✅ Tratamento de erros robusto',
        '✅ Modal de sucesso com status do e-mail',
        '✅ Zero dependências externas (exceto PHPMailer opcional)'
    ],
    configuracao: 'Edite as variáveis $EMAIL_CONFIG no início do arquivo',
    performance: 'Sistema otimizado e responsivo',
    compatibilidade: 'Funciona com ou sem PHPMailer instalado'
});
</script>

<?php
// Finalização da página
if (function_exists('renderFooter')) {
    renderFooter();
}
?>

</body>
</html>