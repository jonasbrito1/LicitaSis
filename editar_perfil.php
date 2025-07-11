<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inclui o sistema de permissões e auditoria
include('db.php');
include('permissions.php');
include('includes/audit.php');

$permissionManager = initPermissions($pdo);

$error = "";
$success = "";
$user_id = $_SESSION['user']['id'];

// Busca dados atuais do usuário
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        header("Location: logout.php");
        exit();
    }
} catch (Exception $e) {
    $error = "Erro ao carregar dados do usuário.";
}

// Processamento do formulário
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        // Atualização de dados pessoais
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        
        if (empty($name) || empty($email)) {
            $error = "Nome e e-mail são obrigatórios!";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "E-mail inválido!";
        } else {
            // Verifica se o e-mail já existe (exceto o próprio usuário)
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user_id]);
            
            if ($stmt->fetch()) {
                $error = "Este e-mail já está sendo usado por outro usuário!";
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                    if ($stmt->execute([$name, $email, $user_id])) {
                        // Atualiza a sessão
                        $_SESSION['user']['name'] = $name;
                        $_SESSION['user']['email'] = $email;
                        $userData['name'] = $name;
                        $userData['email'] = $email;
                        
                        // Log da ação
                        logUserAction('PROFILE_UPDATE', 'users', $user_id, [
                            'old_name' => $userData['name'],
                            'new_name' => $name,
                            'old_email' => $userData['email'],
                            'new_email' => $email
                        ]);
                        
                        $success = "Dados pessoais atualizados com sucesso!";
                    } else {
                        $error = "Erro ao atualizar dados pessoais!";
                    }
                } catch (Exception $e) {
                    $error = "Erro no banco de dados: " . $e->getMessage();
                }
            }
        }
    } 
    elseif ($action === 'change_password') {
        // Alteração de senha
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "Todos os campos de senha são obrigatórios!";
        } elseif (strlen($new_password) < 6) {
            $error = "A nova senha deve ter pelo menos 6 caracteres!";
        } elseif ($new_password !== $confirm_password) {
            $error = "A confirmação da senha não confere!";
        } elseif (!password_verify($current_password, $userData['password'])) {
            $error = "Senha atual incorreta!";
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                
                if ($stmt->execute([$hashed_password, $user_id])) {
                    // Log da ação
                    logUserAction('PASSWORD_CHANGE', 'users', $user_id, [
                        'change_time' => date('Y-m-d H:i:s'),
                        'ip_address' => getClientIP()
                    ]);
                    
                    $success = "Senha alterada com sucesso!";
                    
                    // Limpa os campos de senha
                    $_POST = [];
                } else {
                    $error = "Erro ao alterar senha!";
                }
            } catch (Exception $e) {
                $error = "Erro no banco de dados: " . $e->getMessage();
            }
        }
    }
}

// Inclui o template de header
include('includes/header_template.php');
startPage("Editar Perfil - LicitaSis", "perfil");
?>

<style>
    /* Estilos específicos da página de perfil */
    .profile-container {
        max-width: 800px;
        margin: 0 auto;
    }

    .profile-header {
        text-align: center;
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
        color: white;
        padding: 2rem;
        border-radius: var(--radius) var(--radius) 0 0;
        margin: -2.5rem -2.5rem 2rem -2.5rem;
    }

    .profile-avatar {
        width: 100px;
        height: 100px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem;
        font-size: 3rem;
        color: white;
        border: 4px solid rgba(255,255,255,0.3);
    }

    .profile-name {
        font-size: 1.5rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
    }

    .profile-permission {
        background: rgba(255,255,255,0.2);
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.9rem;
        display: inline-block;
    }

    .form-sections {
        display: grid;
        gap: 2rem;
    }

    .form-section {
        background: var(--light-gray);
        padding: 2rem;
        border-radius: var(--radius);
        border-left: 4px solid var(--secondary-color);
    }

    .form-section h3 {
        color: var(--primary-color);
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .password-strength {
        margin-top: 0.5rem;
        font-size: 0.8rem;
    }

    .strength-bar {
        height: 4px;
        background: var(--border-color);
        border-radius: 2px;
        margin-top: 0.25rem;
        overflow: hidden;
    }

    .strength-fill {
        height: 100%;
        width: 0%;
        transition: all 0.3s ease;
        border-radius: 2px;
    }

    .strength-weak { background: var(--danger-color); }
    .strength-fair { background: var(--warning-color); }
    .strength-good { background: var(--success-color); }
    .strength-strong { background: var(--primary-color); }

    .security-info {
        background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
        border: 1px solid #90caf9;
        border-radius: var(--radius);
        padding: 1.5rem;
        margin: 2rem 0;
        border-left: 4px solid #2196f3;
    }

    .security-info h4 {
        color: #1976d2;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .security-tips {
        list-style: none;
        padding: 0;
    }

    .security-tips li {
        margin: 0.5rem 0;
        color: #1565c0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .security-tips li:before {
        content: "✓";
        background: #4caf50;
        color: white;
        border-radius: 50%;
        width: 16px;
        height: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: bold;
    }

    .last-login {
        background: white;
        padding: 1rem;
        border-radius: var(--radius);
        border: 1px solid var(--border-color);
        margin-top: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--medium-gray);
        font-size: 0.9rem;
    }

    .btn-save {
        width: 100%;
        margin-top: 1rem;
    }

    .form-divider {
        border: none;
        height: 1px;
        background: var(--border-color);
        margin: 2rem 0;
    }

    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }

        .profile-header {
            margin: -1.5rem -1.5rem 2rem -1.5rem;
            padding: 1.5rem;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            font-size: 2.5rem;
        }

        .profile-name {
            font-size: 1.25rem;
        }

        .form-section {
            padding: 1.5rem;
        }
    }

    /* Animações */
    .form-section {
        animation: slideInUp 0.5s ease;
    }

    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Efeitos de foco nos campos */
    .form-control:focus {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 191, 174, 0.2);
    }
</style>

<div class="main-content">
    <div class="container profile-container">
        
        <!-- Header do perfil -->
        <div class="profile-header">
            <div class="profile-avatar">
                <i class="fas fa-user"></i>
            </div>
            <div class="profile-name"><?php echo htmlspecialchars($userData['name']); ?></div>
            <div class="profile-permission">
                <?php echo $permissionManager->getPermissionName($userData['permission']); ?>
            </div>
        </div>

        <!-- Link voltar -->
        <a href="index.php" class="btn btn-secondary mb-3">
            <i class="fas fa-arrow-left"></i> Voltar ao Início
        </a>

        <h2>Editar Perfil</h2>

        <!-- Mensagens -->
        <?php if ($error): ?>
            <div class="message error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="message success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <div class="form-sections">
            <!-- Seção: Dados Pessoais -->
            <div class="form-section">
                <h3><i class="fas fa-user-edit"></i> Dados Pessoais</h3>
                
                <form method="POST" id="profileForm">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Nome Completo:</label>
                            <input type="text" id="name" name="name" class="form-control" 
                                   value="<?php echo htmlspecialchars($userData['name']); ?>" 
                                   required maxlength="255">
                        </div>
                        
                        <div class="form-group">
                            <label for="email">E-mail:</label>
                            <input type="email" id="email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($userData['email']); ?>" 
                                   required maxlength="255">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Nível de Permissão:</label>
                        <input type="text" class="form-control" 
                               value="<?php echo $permissionManager->getPermissionName($userData['permission']); ?>" 
                               readonly style="background: #f8f9fa;">
                        <small class="text-muted">
                            <i class="fas fa-info-circle"></i> 
                            Para alterar seu nível de permissão, entre em contato com o administrador.
                        </small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-save">
                        <i class="fas fa-save"></i> Salvar Dados Pessoais
                    </button>
                </form>
            </div>

            <hr class="form-divider">

            <!-- Seção: Alterar Senha -->
            <div class="form-section">
                <h3><i class="fas fa-key"></i> Alterar Senha</h3>
                
                <form method="POST" id="passwordForm">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label for="current_password">Senha Atual:</label>
                        <input type="password" id="current_password" name="current_password" 
                               class="form-control" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_password">Nova Senha:</label>
                            <input type="password" id="new_password" name="new_password" 
                                   class="form-control" required minlength="6">
                            <div class="password-strength" id="passwordStrength"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirmar Nova Senha:</label>
                            <input type="password" id="confirm_password" name="confirm_password" 
                                   class="form-control" required minlength="6">
                            <div id="passwordMatch" style="margin-top: 0.5rem; font-size: 0.8rem;"></div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-warning btn-save" id="changePasswordBtn" disabled>
                        <i class="fas fa-shield-alt"></i> Alterar Senha
                    </button>
                </form>
            </div>
        </div>

        <!-- Informações de Segurança -->
        <div class="security-info">
            <h4><i class="fas fa-shield-alt"></i> Dicas de Segurança</h4>
            <ul class="security-tips">
                <li>Use uma senha com pelo menos 8 caracteres</li>
                <li>Inclua letras maiúsculas, minúsculas, números e símbolos</li>
                <li>Não compartilhe sua senha com outras pessoas</li>
                <li>Altere sua senha regularmente</li>
                <li>Use senhas diferentes para cada sistema</li>
            </ul>
            
            <div class="text-center mt-3">
                <a href="meu_historico.php" class="btn btn-secondary">
                    <i class="fas fa-history"></i> Ver Meu Histórico de Atividades
                </a>
            </div>
        </div>

        <!-- Informação do último login -->
        <?php if (isset($userData['last_login'])): ?>
        <div class="last-login">
            <i class="fas fa-clock"></i>
            <span>Último acesso: <?php echo date('d/m/Y H:i', strtotime($userData['last_login'])); ?></span>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php
endPage(true, "
    // JavaScript específico da página de perfil
    document.addEventListener('DOMContentLoaded', function() {
        const newPasswordField = document.getElementById('new_password');
        const confirmPasswordField = document.getElementById('confirm_password');
        const passwordStrengthDiv = document.getElementById('passwordStrength');
        const passwordMatchDiv = document.getElementById('passwordMatch');
        const changePasswordBtn = document.getElementById('changePasswordBtn');

        // Verificador de força da senha
        function checkPasswordStrength(password) {
            let strength = 0;
            let feedback = [];
            
            if (password.length >= 6) strength++;
            else feedback.push('pelo menos 6 caracteres');
            
            if (/[a-z]/.test(password)) strength++;
            else feedback.push('letras minúsculas');
            
            if (/[A-Z]/.test(password)) strength++;
            else feedback.push('letras maiúsculas');
            
            if (/[0-9]/.test(password)) strength++;
            else feedback.push('números');
            
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            else feedback.push('caracteres especiais');
            
            const strengthLevels = ['Muito Fraca', 'Fraca', 'Regular', 'Boa', 'Muito Boa'];
            const strengthColors = ['strength-weak', 'strength-weak', 'strength-fair', 'strength-good', 'strength-strong'];
            const strengthWidths = [20, 40, 60, 80, 100];
            
            let strengthBar = '<div class=\"strength-bar\"><div class=\"strength-fill ' + strengthColors[strength-1] + '\" style=\"width: ' + strengthWidths[strength-1] + '%\"></div></div>';
            
            passwordStrengthDiv.innerHTML = 
                '<div style=\"color: ' + (strength >= 3 ? '#28a745' : strength >= 2 ? '#ffc107' : '#dc3545') + '; font-weight: 600;\">' +
                'Força: ' + (strengthLevels[strength-1] || 'Muito Fraca') + '</div>' +
                strengthBar +
                (feedback.length > 0 ? '<div style=\"color: #6c757d; margin-top: 0.25rem;\">Adicione: ' + feedback.join(', ') + '</div>' : '');
        }

        // Verificador de confirmação de senha
        function checkPasswordMatch() {
            const newPassword = newPasswordField.value;
            const confirmPassword = confirmPasswordField.value;
            
            if (confirmPassword === '') {
                passwordMatchDiv.innerHTML = '';
                return false;
            }
            
            if (newPassword === confirmPassword) {
                passwordMatchDiv.innerHTML = '<div style=\"color: #28a745; font-weight: 600;\"><i class=\"fas fa-check\"></i> Senhas coincidem</div>';
                return true;
            } else {
                passwordMatchDiv.innerHTML = '<div style=\"color: #dc3545; font-weight: 600;\"><i class=\"fas fa-times\"></i> Senhas não coincidem</div>';
                return false;
            }
        }

        // Habilita/desabilita botão de alterar senha
        function updatePasswordButton() {
            const currentPassword = document.getElementById('current_password').value;
            const newPassword = newPasswordField.value;
            const confirmPassword = confirmPasswordField.value;
            
            const isValid = currentPassword.length > 0 && 
                           newPassword.length >= 6 && 
                           confirmPassword.length >= 6 && 
                           newPassword === confirmPassword;
            
            changePasswordBtn.disabled = !isValid;
            changePasswordBtn.style.opacity = isValid ? '1' : '0.6';
        }

        // Event listeners
        newPasswordField.addEventListener('input', function() {
            if (this.value.length > 0) {
                checkPasswordStrength(this.value);
            } else {
                passwordStrengthDiv.innerHTML = '';
            }
            checkPasswordMatch();
            updatePasswordButton();
        });

        confirmPasswordField.addEventListener('input', function() {
            checkPasswordMatch();
            updatePasswordButton();
        });

        document.getElementById('current_password').addEventListener('input', updatePasswordButton);

        // Validação do formulário de dados pessoais
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();
            
            if (name.length < 2) {
                e.preventDefault();
                alert('O nome deve ter pelo menos 2 caracteres.');
                return false;
            }
            
            if (!isValidEmail(email)) {
                e.preventDefault();
                alert('Por favor, insira um e-mail válido.');
                return false;
            }
        });

        // Validação do formulário de senha
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            const newPassword = newPasswordField.value;
            const confirmPassword = confirmPasswordField.value;
            
            if (newPassword.length < 6) {
                e.preventDefault();
                alert('A nova senha deve ter pelo menos 6 caracteres.');
                return false;
            }
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('As senhas não coincidem.');
                return false;
            }
        });

        // Função auxiliar para validar e-mail
        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

        // Remove mensagens após 5 segundos
        setTimeout(function() {
            const messages = document.querySelectorAll('.message');
            messages.forEach(function(message) {
                message.style.transition = 'opacity 0.5s ease';
                message.style.opacity = '0';
                setTimeout(function() {
                    message.remove();
                }, 500);
            });
        }, 5000);

        // Anima os campos ao focar
        const inputs = document.querySelectorAll('.form-control');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'translateY(-2px)';
                this.parentElement.style.transition = 'transform 0.3s ease';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'translateY(0)';
            });
        });
    });
");
?>