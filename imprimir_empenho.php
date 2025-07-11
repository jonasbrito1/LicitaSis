<?php
session_start();
require_once('db.php');

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$empenho_id = intval($_GET['id'] ?? 0);

if (!$empenho_id) {
    echo "ID do empenho não informado";
    exit();
}

try {
    // Busca dados do empenho
    $sql = "SELECT * FROM empenhos WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empenho_id]);
    $empenho = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$empenho) {
        echo "Empenho não encontrado";
        exit();
    }
    
    // Busca produtos
    $sql = "SELECT ep.*, p.nome as produto_nome, p.codigo as produto_codigo
            FROM empenho_produtos ep
            LEFT JOIN produtos p ON ep.produto_id = p.id
            WHERE ep.empenho_id = ?
            ORDER BY ep.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empenho_id]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Empenho <?php echo htmlspecialchars($empenho['numero']); ?></title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .info { margin-bottom: 20px; }
            .info strong { color: #333; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            .total { font-weight: bold; background-color: #f9f9f9; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="no-print">
            <button onclick="window.print()">Imprimir</button>
            <button onclick="window.close()">Fechar</button>
        </div>
        
        <div class="header">
            <h1>EMPENHO</h1>
            <h2><?php echo htmlspecialchars($empenho['numero']); ?></h2>
        </div>
        
        <div class="info">
            <p><strong>Cliente:</strong> <?php echo htmlspecialchars($empenho['cliente_nome']); ?></p>
            <p><strong>UASG:</strong> <?php echo htmlspecialchars($empenho['cliente_uasg'] ?? 'N/A'); ?></p>
            <p><strong>Data:</strong> <?php echo $empenho['data'] ? date('d/m/Y', strtotime($empenho['data'])) : 'N/A'; ?></p>
            <p><strong>Pregão:</strong> <?php echo htmlspecialchars($empenho['pregao'] ?? 'N/A'); ?></p>
            <p><strong>Status:</strong> <?php echo htmlspecialchars($empenho['classificacao'] ?? 'Pendente'); ?></p>
        </div>
        
        <?php if ($produtos): ?>
        <table>
            <thead>
                <tr>
                    <th>Produto</th>
                    <th>Quantidade</th>
                    <th>Valor Unitário</th>
                    <th>Valor Total</th>
                </tr>
            </thead>
            <tbody>
                <?php $total_geral = 0; ?>
                <?php foreach ($produtos as $produto): ?>
                    <?php $total_geral += $produto['valor_total']; ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($produto['produto_nome'] ?: $produto['descricao_produto']); ?>
                            <?php if ($produto['produto_codigo']): ?>
                                <br><small>Código: <?php echo htmlspecialchars($produto['produto_codigo']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format($produto['quantidade'], 0, ',', '.'); ?></td>
                        <td>R$ <?php echo number_format($produto['valor_unitario'], 2, ',', '.'); ?></td>
                        <td>R$ <?php echo number_format($produto['valor_total'], 2, ',', '.'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total">
                    <td colspan="3"><strong>TOTAL GERAL</strong></td>
                    <td><strong>R$ <?php echo number_format($total_geral, 2, ',', '.'); ?></strong></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php if ($empenho['observacao']): ?>
        <div style="margin-top: 30px;">
            <strong>Observações:</strong><br>
            <?php echo nl2br(htmlspecialchars($empenho['observacao'])); ?>
        </div>
        <?php endif; ?>
        
        <div style="margin-top: 50px; text-align: center; font-size: 12px; color: #666;">
            Emitido em <?php echo date('d/m/Y H:i'); ?> - LicitaSis
        </div>
    </body>
    </html>
    <?php
    
} catch (Exception $e) {
    echo "Erro ao gerar impressão: " . $e->getMessage();
}
?>