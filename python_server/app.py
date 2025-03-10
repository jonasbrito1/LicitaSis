from flask import Flask, send_file, request, render_template
import pandas as pd
import pymysql
from io import BytesIO
import logging

app = Flask(__name__)

# Configuração do logging
logging.basicConfig(level=logging.DEBUG)  # Configura o nível de log para DEBUG

# Configuração do banco de dados
def get_db_connection():
    connection = pymysql.connect(
        host='localhost',  # Isso é para o XAMPP
        user='root',
        password='',
        database='combraz',
        charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor
    )
    return connection

    # Rota para download de Excel (produtos)
@app.route('/download_xlsx_produtos', methods=['GET'])
def download_xlsx_produtos():
    search_term = request.args.get('search', '')
    
    # Log da URL acessada
    app.logger.debug(f"Download XLSX solicitado para produtos com o termo de pesquisa: {search_term}")
    
    # Conectar ao banco de dados
    connection = get_db_connection()
    try:
        with connection.cursor() as cursor:
            if search_term:
                query = """
                    SELECT * FROM produtos 
                    WHERE codigo LIKE %s OR nome LIKE %s
                """
                cursor.execute(query, ('%' + search_term + '%', '%' + search_term + '%'))
            else:
                query = "SELECT * FROM produtos ORDER BY nome ASC"
                cursor.execute(query)

            products = cursor.fetchall()
            
            # Log do número de registros encontrados
            app.logger.debug(f"Produtos encontrados: {len(products)}")
    except Exception as e:
        app.logger.error(f"Erro ao acessar o banco de dados: {str(e)}")
    finally:
        connection.close()

    # Alterar a ordem das colunas para o formato desejado
    column_order = ['codigo', 'nome', 'und', 'fornecedor', 'imagem', 'observacao']
    products = [{col: product[col] for col in column_order} for product in products]

    # Criando DataFrame e gerando o arquivo Excel
    df = pd.DataFrame(products)

    # Converter os nomes das colunas para maiúsculo
    df.columns = [col.upper() for col in df.columns]

    output = BytesIO()
    with pd.ExcelWriter(output, engine='xlsxwriter') as writer:
        df.to_excel(writer, index=False, sheet_name='Produtos')
    output.seek(0)

    # Log do arquivo gerado
    app.logger.debug("Arquivo XLSX de produtos gerado com sucesso.")

    return send_file(output, as_attachment=True, download_name="produtos.xlsx", mimetype="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")

    # Rota para consulta de clientes
@app.route('/consulta_clientes', methods=['GET', 'POST'])
def consulta_clientes():
    clientes = []
    search_term = request.args.get('search', '')

    # Conectar ao banco de dados e executar a pesquisa
    connection = get_db_connection()
    try:
        with connection.cursor() as cursor:
            if search_term:
                query = """
                    SELECT * FROM clientes 
                    WHERE uasg LIKE %s OR nome_orgaos LIKE %s OR cnpj LIKE %s
                """
                cursor.execute(query, ('%' + search_term + '%', '%' + search_term + '%', '%' + search_term + '%'))
            else:
                query = "SELECT * FROM clientes ORDER BY nome_orgaos ASC"
                cursor.execute(query)

            clientes = cursor.fetchall()
    finally:
        connection.close()

    return render_template('consulta_clientes.html', clientes=clientes, search_term=search_term)

# Rota para download de Excel (clientes)
@app.route('/download_xlsx_clientes', methods=['GET'])
def download_xlsx_clientes():
    search_term = request.args.get('search', '')
    
    # Log da URL acessada
    app.logger.debug(f"Download XLSX solicitado para clientes com o termo de pesquisa: {search_term}")
    
    # Conectar ao banco de dados
    connection = get_db_connection()
    try:
        with connection.cursor() as cursor:
            if search_term:
                query = """
                    SELECT * FROM clientes 
                    WHERE uasg LIKE %s OR nome_orgaos LIKE %s OR cnpj LIKE %s
                """
                cursor.execute(query, ('%' + search_term + '%', '%' + search_term + '%', '%' + search_term + '%'))
            else:
                query = "SELECT * FROM clientes ORDER BY nome_orgaos ASC"
                cursor.execute(query)

            clientes = cursor.fetchall()
            
            # Log do número de registros encontrados
            app.logger.debug(f"Clientes encontrados: {len(clientes)}")
    except Exception as e:
        app.logger.error(f"Erro ao acessar o banco de dados: {str(e)}")
    finally:
        connection.close()

    # Alterar a ordem das colunas para o formato desejado
    column_order = ['uasg', 'cnpj', 'nome_orgaos', 'endereco', 'telefone', 'telefone2', 'email', 'email2', 'observacoes']
    clientes = [{col: cliente[col] for col in column_order} for cliente in clientes]

    # Criando DataFrame e gerando o arquivo Excel
    df = pd.DataFrame(clientes)

    # Converter os nomes das colunas para maiúsculo
    df.columns = [col.upper() for col in df.columns]

    output = BytesIO()
    with pd.ExcelWriter(output, engine='xlsxwriter') as writer:
        df.to_excel(writer, index=False, sheet_name='Clientes')
    output.seek(0)

    # Log do arquivo gerado
    app.logger.debug("Arquivo XLSX de clientes gerado com sucesso.")

    return send_file(output, as_attachment=True, download_name="clientes.xlsx", mimetype="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")

if __name__ == '__main__':
    app.run(debug=True)

# Rota para consulta de empenhos
@app.route('/consulta_empenho', methods=['GET', 'POST'])
def consulta_empenho():
    empenhos = []
    search_term = request.args.get('search', '')

    # Conectar ao banco de dados e executar a pesquisa
    connection = get_db_connection()
    try:
        with connection.cursor() as cursor:
            if search_term:
                query = """
                    SELECT * FROM empenhos 
                    WHERE numero LIKE %s OR cliente_uasg LIKE %s OR produto LIKE %s
                """
                cursor.execute(query, ('%' + search_term + '%', '%' + search_term + '%', '%' + search_term + '%'))
            else:
                query = "SELECT * FROM empenhos ORDER BY numero ASC"
                cursor.execute(query)

            empenhos = cursor.fetchall()
    finally:
        connection.close()

    return render_template('consulta_empenho.html', empenhos=empenhos, search_term=search_term)

# Rota para download de Excel (empenhos)
@app.route('/download_xlsx_empenhos', methods=['GET'])
def download_xlsx_empenhos():
    search_term = request.args.get('search', '')
    
    # Log da URL acessada
    app.logger.debug(f"Download XLSX solicitado para empenhos com o termo de pesquisa: {search_term}")
    
    # Conectar ao banco de dados
    connection = get_db_connection()
    try:
        with connection.cursor() as cursor:
            if search_term:
                query = """
                    SELECT * FROM empenhos 
                    WHERE numero LIKE %s OR cliente_uasg LIKE %s OR produto LIKE %s
                """
                cursor.execute(query, ('%' + search_term + '%', '%' + search_term + '%', '%' + search_term + '%'))
            else:
                query = "SELECT * FROM empenhos ORDER BY numero ASC"
                cursor.execute(query)

            empenhos = cursor.fetchall()
            
            # Log do número de registros encontrados
            app.logger.debug(f"Empenhos encontrados: {len(empenhos)}")
    except Exception as e:
        app.logger.error(f"Erro ao acessar o banco de dados: {str(e)}")
    finally:
        connection.close()

    # Alterar a ordem das colunas para o formato desejado
    column_order = ['numero', 'cliente_uasg', 'produto', 'produto2', 'item', 'observacao', 'pregão', 'upload', 'data', 'prioridade']
    empenhos = [{col: empenho[col] for col in column_order} for empenho in empenhos]

    # Criando DataFrame e gerando o arquivo Excel
    df = pd.DataFrame(empenhos)

    # Converter os nomes das colunas para maiúsculo
    df.columns = [col.upper() for col in df.columns]

    output = BytesIO()
    with pd.ExcelWriter(output, engine='xlsxwriter') as writer:
        df.to_excel(writer, index=False, sheet_name='Empenhos')
    output.seek(0)

    # Log do arquivo gerado
    app.logger.debug("Arquivo XLSX de empenhos gerado com sucesso.")

    return send_file(output, as_attachment=True, download_name="empenhos.xlsx", mimetype="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")

# Rota para consulta de faturamentos
@app.route('/consulta_faturamento', methods=['GET', 'POST'])
def consulta_faturamento():
    faturamentos = []
    search_term = request.args.get('search', '')

    # Conectar ao banco de dados e executar a pesquisa
    connection = get_db_connection()
    try:
        with connection.cursor() as cursor:
            if search_term:
                query = """
                    SELECT * FROM faturamentos 
                    WHERE numero LIKE %s OR cliente_uasg LIKE %s OR produto LIKE %s
                """
                cursor.execute(query, ('%' + search_term + '%', '%' + search_term + '%', '%' + search_term + '%'))
            else:
                query = "SELECT * FROM faturamentos ORDER BY numero ASC"
                cursor.execute(query)

            faturamentos = cursor.fetchall()
    finally:
        connection.close()

    return render_template('consulta_faturamento.html', faturamentos=faturamentos, search_term=search_term)

# Rota para download de Excel (faturamentos)
@app.route('/download_xlsx_faturamentos', methods=['GET'])
def download_xlsx_faturamentos():
    search_term = request.args.get('search', '')
    
    # Log da URL acessada
    app.logger.debug(f"Download XLSX solicitado para faturamentos com o termo de pesquisa: {search_term}")
    
    # Conectar ao banco de dados
    connection = get_db_connection()
    try:
        with connection.cursor() as cursor:
            if search_term:
                query = """
                    SELECT * FROM faturamentos 
                    WHERE numero LIKE %s OR cliente_uasg LIKE %s OR produto LIKE %s
                """
                cursor.execute(query, ('%' + search_term + '%', '%' + search_term + '%', '%' + search_term + '%'))
            else:
                query = "SELECT * FROM faturamentos ORDER BY numero ASC"
                cursor.execute(query)

            faturamentos = cursor.fetchall()

            # Log do número de registros encontrados
            app.logger.debug(f"Faturamentos encontrados: {len(faturamentos)}")
    except Exception as e:
        app.logger.error(f"Erro ao acessar o banco de dados: {str(e)}")
    finally:
        connection.close()

    # Alterar a ordem das colunas para o formato desejado
    column_order = ['numero', 'cliente_uasg', 'produto', 'item', 'transportadora', 'observacao', 'pregao', 'upload', 'nf', 'data']
    faturamentos = [{col: faturamento[col] for col in column_order} for faturamento in faturamentos]

    # Criando DataFrame e gerando o arquivo Excel
    df = pd.DataFrame(faturamentos)

    # Converter os nomes das colunas para maiúsculo
    df.columns = [col.upper() for col in df.columns]

    output = BytesIO()
    with pd.ExcelWriter(output, engine='xlsxwriter') as writer:
        df.to_excel(writer, index=False, sheet_name='Faturamentos')
    output.seek(0)

    # Log do arquivo gerado
    app.logger.debug("Arquivo XLSX de faturamentos gerado com sucesso.")

    return send_file(output, as_attachment=True, download_name="faturamentos.xlsx", mimetype="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")

if __name__ == '__main__':
    app.run(debug=True)
