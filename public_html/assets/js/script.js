// script.js

// Selecionando o ícone do hambúrguer e o menu de navegação
const hamburgerMenu = document.getElementById('hamburger-menu');
const navLinks = document.querySelector('.nav-links');

// Quando o ícone de hambúrguer for clicado, alterna a visibilidade do menu
hamburgerMenu.addEventListener('click', () => {
    navLinks.classList.toggle('show'); // Adiciona ou remove a classe 'show' do menu
});
