<?php
// ===========================================
// ARQUIVO: backup_system.php
// Sistema de backup automático
// ===========================================
?>
<?php
require_once('db.php');

// Configurações de backup
$backupDir = 'backups/';
$maxBackups = 30; // Manter últimos 30 backups

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

function createBackup($pdo, $backupDir) {
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "licitasis_backup_{$timestamp}.sql";
    $filepath = $backupDir . $filename;
    
    // Configurações do banco (ajustar conforme necessário)
    $host = 'localhost';
    $database = 'licitasis_licitasis';
    $username = 'root'; // Ajustar
    $password = ''; // Ajustar
    
    $command = "mysqldump --host={$host} --user={$username} --password={$password} --single-transaction --routines --triggers {$database} > {$filepath}";
    
    exec($command, $output, $returnVar);
    
    if ($returnVar === 0 && file_exists($filepath)) {
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath)
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Falha ao criar backup'
        ];
    }
}

function cleanOldBackups($backupDir, $maxBackups) {
    $files = glob($backupDir . 'licitasis_backup_*.sql');
    
    if (count($files) > $maxBackups) {
        // Ordena por data de modificação
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Remove arquivos mais antigos
        $filesToRemove = array_slice($files, 0, count($files) - $maxBackups);
        foreach ($filesToRemove as $file) {
            unlink($file);
        }
        
        return count($filesToRemove);
    }
    
    return 0;
}

// Executa backup se chamado diretamente
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    $result = createBackup($pdo, $backupDir);
    $cleaned = cleanOldBackups($backupDir, $maxBackups);
    
    header('Content-Type: application/json');
    echo json_encode([
        'backup' => $result,
        'cleaned_files' => $cleaned,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>