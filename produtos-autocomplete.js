/**
 * =============================================
 * PRODUTOS AUTOCOMPLETE - VERSÃO CORRIGIDA
 * Sistema de autocomplete para produtos com tratamento robusto de erros
 * LicitaSis - Sistema de Gestão de Licitações
 * =============================================
 */

class ProdutosAutoComplete {
    constructor(options = {}) {
        // Configurações padrão
        this.config = {
            inputElement: options.inputElement || null,
            searchUrl: options.searchUrl || 'buscar_produtos_autocomplete.php',
            detailsUrl: options.detailsUrl || 'get_produto_details.php',
            minChars: options.minChars || 2,
            searchDelay: options.searchDelay || 300,
            maxResults: options.maxResults || 10,
            showCategories: options.showCategories !== false,
            showPrices: options.showPrices !== false,
            showStock: options.showStock !== false,
            placeholder: options.placeholder || 'Digite o nome ou código do produto...',
            
            // Callbacks
            onSelect: options.onSelect || function() {},
            onError: options.onError || function() {},
            onSearch: options.onSearch || function() {},
            onClear: options.onClear || function() {}
        };

        // Estados internos
        this.isVisible = false;
        this.selectedIndex = -1;
        this.currentResults = [];
        this.searchTimeout = null;
        this.currentRequest = null;

        // Elementos DOM
        this.inputElement = this.config.inputElement;
        this.suggestionsList = null;

        // Inicialização
        this.init();
    }

    /**
     * Inicializa o autocomplete
     */
    init() {
        if (!this.inputElement) {
            console.error('ProdutosAutoComplete: inputElement é obrigatório');
            return false;
        }

        try {
            this.createSuggestionsList();
            this.attachEventListeners();
            this.setupInputPlaceholder();
            
            console.log('✅ ProdutosAutoComplete inicializado com sucesso');
            return true;
        } catch (error) {
            console.error('❌ Erro ao inicializar ProdutosAutoComplete:', error);
            this.config.onError(error);
            return false;
        }
    }

    /**
     * Cria o elemento de lista de sugestões
     */
    createSuggestionsList() {
        // Remove lista existente se houver
        const existingList = document.getElementById(`${this.inputElement.id}_suggestions`);
        if (existingList) {
            existingList.remove();
        }

        // Cria nova lista
        this.suggestionsList = document.createElement('div');
        this.suggestionsList.id = `${this.inputElement.id}_suggestions`;
        this.suggestionsList.className = 'autocomplete-suggestions';
        this.suggestionsList.style.cssText = `
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid var(--secondary-color, #00bfae);
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        `;

        // Insere após o input
        const inputParent = this.inputElement.parentNode;
        if (inputParent.style.position !== 'relative') {
            inputParent.style.position = 'relative';
        }
        inputParent.appendChild(this.suggestionsList);
    }

    /**
     * Configura placeholder do input
     */
    setupInputPlaceholder() {
        if (!this.inputElement.placeholder) {
            this.inputElement.placeholder = this.config.placeholder;
        }
        
        this.inputElement.setAttribute('autocomplete', 'off');
        this.inputElement.setAttribute('spellcheck', 'false');
    }

    /**
     * Anexa event listeners
     */
    attachEventListeners() {
        // Input - busca com delay
        this.inputElement.addEventListener('input', (e) => {
            this.handleInput(e.target.value.trim());
        });

        // Teclas de navegação
        this.inputElement.addEventListener('keydown', (e) => {
            this.handleKeydown(e);
        });

        // Foco - mostra sugestões se houver
        this.inputElement.addEventListener('focus', () => {
            if (this.currentResults.length > 0) {
                this.showSuggestions();
            }
        });

        // Blur - esconde sugestões com delay
        this.inputElement.addEventListener('blur', () => {
            setTimeout(() => this.hideSuggestions(), 150);
        });

        // Clique fora - esconde sugestões
        document.addEventListener('click', (e) => {
            if (!this.inputElement.contains(e.target) && 
                !this.suggestionsList.contains(e.target)) {
                this.hideSuggestions();
            }
        });
    }

    /**
     * Manipula entrada de texto
     */
    handleInput(value) {
        // Limpa timeout anterior
        if (this.searchTimeout) {
            clearTimeout(this.searchTimeout);
        }

        // Cancela request anterior
        if (this.currentRequest) {
            this.currentRequest.abort();
            this.currentRequest = null;
        }

        // Reset estados
        this.selectedIndex = -1;

        if (value.length < this.config.minChars) {
            this.hideSuggestions();
            this.currentResults = [];
            
            if (value.length === 0) {
                this.config.onClear();
            }
            return;
        }

        // Busca com delay
        this.searchTimeout = setTimeout(() => {
            this.searchProducts(value);
        }, this.config.searchDelay);
    }

    /**
     * Manipula teclas de navegação
     */
    handleKeydown(e) {
        if (!this.isVisible || this.currentResults.length === 0) {
            return;
        }

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.selectedIndex = Math.min(
                    this.selectedIndex + 1, 
                    this.currentResults.length - 1
                );
                this.updateSelection();
                break;

            case 'ArrowUp':
                e.preventDefault();
                this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
                this.updateSelection();
                break;

            case 'Enter':
                e.preventDefault();
                if (this.selectedIndex >= 0) {
                    this.selectProduct(this.currentResults[this.selectedIndex]);
                }
                break;

            case 'Escape':
                this.hideSuggestions();
                break;
        }
    }

    /**
     * Busca produtos via AJAX
     */
    async searchProducts(termo) {
        try {
            // Callback de início de busca
            this.config.onSearch(termo);

            // Mostra loading
            this.showLoading();

            // URL com parâmetros
            const url = new URL(this.config.searchUrl, window.location.origin);
            url.searchParams.set('termo', termo);
            url.searchParams.set('limit', this.config.maxResults.toString());
            url.searchParams.set('t', Date.now().toString()); // Cache bust

            console.log('🔍 Buscando produtos:', url.toString());

            // Cria AbortController para cancelamento
            const controller = new AbortController();
            this.currentRequest = controller;

            // Faz a requisição
            const response = await fetch(url.toString(), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                signal: controller.signal
            });

            // Verifica se a requisição foi cancelada
            if (controller.signal.aborted) {
                return;
            }

            // Verifica status HTTP
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            // Verifica se é JSON válido
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Resposta não é JSON válido');
            }

            // Parse do JSON
            const data = await response.json();

            // Limpa referência da request
            this.currentRequest = null;

            // Processa resposta
            if (data.success) {
                this.currentResults = data.produtos || [];
                this.renderSuggestions();
                
                console.log(`✅ Encontrados ${this.currentResults.length} produtos para "${termo}"`);
            } else {
                console.warn('⚠️ Busca sem resultados:', data.message || data.error);
                this.showNoResults(data.message || 'Nenhum produto encontrado');
            }

        } catch (error) {
            // Não mostra erro se foi cancelamento
            if (error.name === 'AbortError') {
                console.log('🚫 Busca cancelada');
                return;
            }

            console.error('❌ Erro na busca de produtos:', error);
            
            // Mostra mensagem de erro
            this.showError(error.message);
            
            // Callback de erro
            this.config.onError(error);
        }
    }

    /**
     * Mostra loading na lista de sugestões
     */
    showLoading() {
        this.suggestionsList.innerHTML = `
            <div style="padding: 1rem; text-align: center; color: var(--medium-gray, #6c757d);">
                <div style="display: inline-block; width: 20px; height: 20px; border: 2px solid var(--border-color, #dee2e6); border-top: 2px solid var(--secondary-color, #00bfae); border-radius: 50%; animation: spin 1s linear infinite;"></div>
                <span style="margin-left: 0.5rem;">Buscando produtos...</span>
            </div>
        `;
        this.showSuggestions();
    }

    /**
     * Mostra mensagem de erro
     */
    showError(message) {
        this.suggestionsList.innerHTML = `
            <div style="padding: 1rem; text-align: center; color: var(--danger-color, #dc3545);">
                <i class="fas fa-exclamation-triangle" style="margin-right: 0.5rem;"></i>
                <strong>Erro:</strong> ${this.escapeHtml(message)}
                <div style="margin-top: 0.5rem; font-size: 0.85rem; color: var(--medium-gray, #6c757d);">
                    Tente novamente ou verifique sua conexão
                </div>
            </div>
        `;
        this.showSuggestions();
    }

    /**
     * Mostra mensagem de nenhum resultado
     */
    showNoResults(message) {
        this.suggestionsList.innerHTML = `
            <div style="padding: 1rem; text-align: center; color: var(--medium-gray, #6c757d);">
                <i class="fas fa-search" style="margin-right: 0.5rem;"></i>
                ${this.escapeHtml(message)}
                <div style="margin-top: 0.5rem; font-size: 0.85rem;">
                    Verifique a grafia ou tente termos mais genéricos
                </div>
            </div>
        `;
        this.showSuggestions();
    }

    /**
     * Renderiza sugestões de produtos
     */
    renderSuggestions() {
        if (this.currentResults.length === 0) {
            this.showNoResults('Nenhum produto encontrado');
            return;
        }

        let html = '';
        
        this.currentResults.forEach((produto, index) => {
            const isSelected = index === this.selectedIndex;
            
            // Monta informações do produto
            const nome = this.escapeHtml(produto.nome || 'Produto sem nome');
            const codigo = produto.codigo ? this.escapeHtml(produto.codigo) : '';
            const categoria = produto.categoria ? this.escapeHtml(produto.categoria) : '';
            const preco = produto.display_price || 'R$ 0,00';
            const fornecedor = produto.fornecedor ? this.escapeHtml(produto.fornecedor) : '';
            
            // Status do produto
            let statusHtml = '';
            if (produto.status && produto.status.length > 0) {
                const statusClass = produto.disponivel ? 'warning' : 'danger';
                statusHtml = `
                    <div style="margin-top: 4px;">
                        ${produto.status.map(status => `
                            <span style="
                                background: var(--${statusClass}-color, #ffc107);
                                color: ${statusClass === 'danger' ? 'white' : '#212529'};
                                padding: 2px 6px;
                                border-radius: 10px;
                                font-size: 0.7rem;
                                font-weight: 600;
                                margin-right: 4px;
                            ">${this.escapeHtml(status)}</span>
                        `).join('')}
                    </div>
                `;
            }

            // Estoque (se disponível e controla estoque)
            let estoqueHtml = '';
            if (this.config.showStock && produto.controla_estoque) {
                const estoqueColor = produto.disponivel ? 'var(--success-color, #28a745)' : 'var(--danger-color, #dc3545)';
                estoqueHtml = `
                    <div style="font-size: 0.8rem; color: ${estoqueColor}; margin-top: 2px;">
                        <i class="fas fa-boxes"></i> ${produto.estoque_formatado || 'N/A'}
                    </div>
                `;
            }

            html += `
                <div class="autocomplete-item" 
                     data-index="${index}"
                     style="
                         padding: 0.75rem;
                         border-bottom: 1px solid var(--border-color, #dee2e6);
                         cursor: pointer;
                         transition: background-color 0.2s;
                         background: ${isSelected ? 'var(--light-gray, #f8f9fa)' : 'white'};
                         border-left: ${isSelected ? '3px solid var(--secondary-color, #00bfae)' : '3px solid transparent'};
                     "
                     onmouseover="this.style.background='var(--light-gray, #f8f9fa)'"
                     onmouseout="this.style.background='${isSelected ? 'var(--light-gray, #f8f9fa)' : 'white'}'"
                     onclick="window.produtoAutoCompleteInstance?.selectProductByIndex(${index})">
                    
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: var(--primary-color, #2D893E); margin-bottom: 2px;">
                                ${nome}
                                ${codigo ? `<span style="color: var(--medium-gray, #6c757d); font-weight: normal; margin-left: 8px;">[${codigo}]</span>` : ''}
                            </div>
                            
                            ${categoria && this.config.showCategories ? `
                                <div style="font-size: 0.85rem; color: var(--info-color, #17a2b8); margin-bottom: 2px;">
                                    <i class="fas fa-tag"></i> ${categoria}
                                </div>
                            ` : ''}
                            
                            ${fornecedor ? `
                                <div style="font-size: 0.8rem; color: var(--medium-gray, #6c757d);">
                                    <i class="fas fa-building"></i> ${fornecedor}
                                </div>
                            ` : ''}
                            
                            ${statusHtml}
                            ${estoqueHtml}
                        </div>
                        
                        ${this.config.showPrices ? `
                            <div style="text-align: right; margin-left: 1rem;">
                                <div style="font-weight: 700; color: var(--success-color, #28a745); font-size: 1rem;">
                                    ${preco}
                                </div>
                                ${produto.unidade ? `
                                    <div style="font-size: 0.75rem; color: var(--medium-gray, #6c757d);">
                                        por ${this.escapeHtml(produto.unidade)}
                                    </div>
                                ` : ''}
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
        });

        // Footer com informações
        html += `
            <div style="padding: 0.5rem 0.75rem; background: var(--light-gray, #f8f9fa); border-top: 1px solid var(--border-color, #dee2e6); font-size: 0.8rem; color: var(--medium-gray, #6c757d); text-align: center;">
                ${this.currentResults.length} produto${this.currentResults.length !== 1 ? 's' : ''} encontrado${this.currentResults.length !== 1 ? 's' : ''}
                <span style="margin-left: 1rem;">Use ↑↓ para navegar, Enter para selecionar</span>
            </div>
        `;

        this.suggestionsList.innerHTML = html;
        this.showSuggestions();
    }

    /**
     * Atualiza seleção visual
     */
    updateSelection() {
        const items = this.suggestionsList.querySelectorAll('.autocomplete-item');
        
        items.forEach((item, index) => {
            const isSelected = index === this.selectedIndex;
            item.style.background = isSelected ? 'var(--light-gray, #f8f9fa)' : 'white';
            item.style.borderLeft = isSelected ? '3px solid var(--secondary-color, #00bfae)' : '3px solid transparent';
        });

        // Scroll para item selecionado
        if (this.selectedIndex >= 0 && items[this.selectedIndex]) {
            items[this.selectedIndex].scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }
    }

    /**
     * Seleciona produto por índice
     */
    selectProductByIndex(index) {
        if (index >= 0 && index < this.currentResults.length) {
            this.selectProduct(this.currentResults[index]);
        }
    }

    /**
     * Seleciona um produto
     */
    selectProduct(produto) {
        console.log('✅ Produto selecionado:', produto.nome);
        
        // Preenche o input
        this.inputElement.value = produto.nome;
        
        // Esconde sugestões
        this.hideSuggestions();
        
        // Callback de seleção
        this.config.onSelect(produto);
        
        // Reset estados
        this.selectedIndex = -1;
        this.currentResults = [];
    }

    /**
     * Mostra lista de sugestões
     */
    showSuggestions() {
        this.suggestionsList.style.display = 'block';
        this.isVisible = true;
    }

    /**
     * Esconde lista de sugestões
     */
    hideSuggestions() {
        this.suggestionsList.style.display = 'none';
        this.isVisible = false;
        this.selectedIndex = -1;
    }

    /**
     * Limpa resultados e esconde sugestões
     */
    clear() {
        this.currentResults = [];
        this.hideSuggestions();
        this.inputElement.value = '';
        this.config.onClear();
    }

    /**
     * Destroy - remove event listeners e elementos
     */
    destroy() {
        // Remove event listeners
        this.inputElement.removeEventListener('input', this.handleInput);
        this.inputElement.removeEventListener('keydown', this.handleKeydown);
        this.inputElement.removeEventListener('focus', this.showSuggestions);
        this.inputElement.removeEventListener('blur', this.hideSuggestions);

        // Remove elemento de sugestões
        if (this.suggestionsList && this.suggestionsList.parentNode) {
            this.suggestionsList.parentNode.removeChild(this.suggestionsList);
        }

        // Cancela requests pendentes
        if (this.currentRequest) {
            this.currentRequest.abort();
        }

        // Limpa timeouts
        if (this.searchTimeout) {
            clearTimeout(this.searchTimeout);
        }

        console.log('🗑️ ProdutosAutoComplete destruído');
    }

    /**
     * Busca detalhes completos de um produto
     */
    async getProductDetails(productId) {
        try {
            const url = new URL(this.config.detailsUrl, window.location.origin);
            url.searchParams.set('id', productId.toString());
            url.searchParams.set('t', Date.now().toString());

            const response = await fetch(url.toString());
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();
            
            if (data.success) {
                return data.produto;
            } else {
                throw new Error(data.error || 'Produto não encontrado');
            }

        } catch (error) {
            console.error('Erro ao buscar detalhes do produto:', error);
            this.config.onError(error);
            throw error;
        }
    }

    /**
     * Escapa HTML para segurança
     */
    escapeHtml(text) {
        if (typeof text !== 'string') return '';
        
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Getters para estado
     */
    get hasResults() {
        return this.currentResults.length > 0;
    }

    get selectedProduct() {
        return this.selectedIndex >= 0 ? this.currentResults[this.selectedIndex] : null;
    }
}

// Registra instância global para uso nos event handlers inline
window.produtoAutoCompleteInstance = null;

// CSS adicional para animações
const autocompleteStyles = `
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .autocomplete-suggestions {
        font-family: inherit;
    }
    
    .autocomplete-item {
        transition: all 0.2s ease !important;
    }
    
    .autocomplete-item:hover {
        transform: translateX(2px);
    }
    
    .autocomplete-suggestions::-webkit-scrollbar {
        width: 6px;
    }
    
    .autocomplete-suggestions::-webkit-scrollbar-track {
        background: #f1f1f1;
    }
    
    .autocomplete-suggestions::-webkit-scrollbar-thumb {
        background: var(--secondary-color, #00bfae);
        border-radius: 3px;
    }
    
    .autocomplete-suggestions::-webkit-scrollbar-thumb:hover {
        background: var(--primary-color, #2D893E);
    }
`;

// Injeta CSS se ainda não foi injetado
if (!document.getElementById('autocomplete-styles')) {
    const styleSheet = document.createElement('style');
    styleSheet.id = 'autocomplete-styles';
    styleSheet.textContent = autocompleteStyles;
    document.head.appendChild(styleSheet);
}

// Exporta para uso global
window.ProdutosAutoComplete = ProdutosAutoComplete;