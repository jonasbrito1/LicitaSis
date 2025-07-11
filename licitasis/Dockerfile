# Use a imagem oficial do PHP com Apache
FROM php:8.2-apache

# Instalar dependências necessárias
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Ativar mod_rewrite do Apache
RUN a2enmod rewrite

# Copiar os arquivos do projeto para dentro do container
COPY . /var/www/html/

# Expor a porta 80 (padrão do Apache)
EXPOSE 80
