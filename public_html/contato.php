<!-- contato.php -->
<?php include('header.php'); ?>

<section class="contato">
    <div class="contato-header">
        <h1>Entre em Contato</h1>
        <p>Se você tem dúvidas ou deseja saber mais sobre nossos produtos, preencha o formulário abaixo.</p>
    </div>

    <form action="enviar_contato.php" method="POST" class="contact-form">
        <label for="name">Nome:</label>
        <input type="text" id="name" name="name" required>

        <label for="email">E-mail:</label>
        <input type="email" id="email" name="email" required>

        <label for="message">Mensagem:</label>
        <textarea id="message" name="message" required></textarea>

        <button type="submit" class="cta-button">Enviar Mensagem</button>
    </form>
</section>

<?php include('footer.php'); ?>
