<!-- index.php -->
<?php include('header.php'); ?>

<!-- Definindo a integração com o CSS -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Combraz</title>

    <!-- Integrando corretamente o arquivo CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<section class="hero">
    <div class="hero-content">
        <h1>Móveis com design que fazem a diferença</h1>
        <p>Conheça a nossa linha de móveis planejados, cadeiras, mesas, poltronas, andaimes, pallets e muito mais.</p>
        <a href="catalogo.php" class="cta-button">Veja o nosso catálogo</a>
    </div>
    <div class="hero-image">
        <img src="assets/images/3.png" alt="Móveis e Escritório">
    </div>
</section>

<section class="company-introduction">
    <div class="intro-content">
        <h2>Conheça a nossa Empresa</h2>
        <p>Com mais de 20 anos de experiência no mercado de móveis planejados, a ComBraz oferece a melhor qualidade em móveis para sua casa e escritório.</p>
        <a href="sobre.php" class="cta-button">Saiba mais</a>
    </div>
    <div class="intro-image">
        <img src="assets/images/factory-image.jpg" alt="Fábrica de móveis">
    </div>
</section>

<!-- Vinculando o script.js ao final do arquivo, antes do fechamento da tag body -->
<script src="assets/js/script.js"></script>

<?php include('footer.php'); ?>
