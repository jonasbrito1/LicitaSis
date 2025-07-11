<?php
// Incluir a conexão com o banco de dados
require_once('db.php');

// Verifica se o parâmetro 'uasg' foi enviado
if (isset($_GET['cliente_uasg'])) {
    $cliente_uasg = $_GET['cliente_uasg'];

    try {
        // Consulta todos os empenhos relacionados a essa UASG
        $sql = "SELECT id, numero FROM empenhos WHERE cliente_uasg = :cliente_uasg";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':cliente_uasg', $cliente_uasg, PDO::PARAM_STR);
        $stmt->execute();

        // Busca todos os empenhos encontrados
        $empenhos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($empenhos) {
            // Retorna os empenhos em formato JSON
            echo json_encode($empenhos);
        } else {
            // Caso não encontre nenhum empenho, retorna um array de erro
            echo json_encode(['error' => 'Nenhum empenho encontrado para a UASG informada']);
        }
    } catch (PDOException $e) {
        // Se ocorrer erro na consulta, retorna o erro em JSON
        echo json_encode(['error' => 'Erro ao buscar empenhos: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'UASG não informada']);
}
?>
