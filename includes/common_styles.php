<?php
/**
 * Estilos CSS Comuns para LicitaSis
 * Arquivo: includes/common_styles.php
 */
?>
<style>
    /* Reset e variáveis CSS */
    :root {
        --primary-color: #2D893E;
        --primary-light: #9DCEAC;
        --secondary-color: #00bfae;
        --danger-color: #dc3545;
        --success-color: #28a745;
        --warning-color: #ffc107;
        --light-gray: #f8f9fa;
        --medium-gray: #6c757d;
        --dark-gray: #343a40;
        --border-color: #dee2e6;
        --shadow: 0 2px 10px rgba(0,0,0,0.1);
        --radius: 8px;
        --transition: all 0.3s ease;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    html, body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        min-height: 100vh;
        color: var(--dark-gray);
        line-height: 1.6;
        display: flex;
        flex-direction: column;
    }

    /* Header */
    header {
        background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
        padding: 0.5rem 0;
        text-align: center;
        box-shadow: var(--shadow);
        position: relative;
    }

    .logo {
        max-width: 140px;
        height: auto;
        transition: var(--transition);
    }

    .logo:hover {
        transform: scale(1.05);
    }

    /* User info */
    .user-info {
        position: absolute;
        top: 50%;
        right: 1rem;
        transform: translateY(-50%);
        color: white;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(255,255,255,0.1);
        padding: 0.5rem 1rem;
        border-radius: 20px;
        backdrop-filter: blur(10px);
        transition: var(--transition);
        cursor: pointer;
    }

    .user-info:hover {
        background: rgba(255,255,255,0.2);
        transform: translateY(-50%) scale(1.05);
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }

    .user-info i {
        color: var(--secondary-color);
    }

    .permission-badge {
        background: var(--secondary-color);
        color: white;
        padding: 0.2rem 0.5rem;
        border-radius: 10px;
        font-size: 0.7rem;
        font-weight: 600;
        transition: var(--transition);
    }

    .user-info:hover .permission-badge {
        background: white;
        color: var(--secondary-color);
    }

    /* Navigation */
    nav {
        background: var(--primary-color);
        padding: 0;
        text-align: center;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        position: relative;
    }

    nav a {
        color: white;
        padding: 0.75rem 1rem;
        text-decoration: none;
        font-size: 0.95rem;
        font-weight: 500;
        display: inline-block;
        transition: var(--transition);
        border-bottom: 3px solid transparent;
    }

    nav a:hover {
        background: rgba(255,255,255,0.1);
        border-bottom-color: var(--secondary-color);
        transform: translateY(-1px);
    }

    .dropdown {
        display: inline-block;
        position: relative;
    }

    .dropdown-content {
        display: none;
        position: absolute;
        background: var(--primary-color);
        min-width: 200px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        z-index: 1000;
        border-radius: 0 0 var(--radius) var(--radius);
        overflow: hidden;
    }

    .dropdown-content a {
        display: block;
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }

    .dropdown-content a:last-child {
        border-bottom: none;
    }

    .dropdown:hover .dropdown-content {
        display: block;
        animation: fadeInDown 0.3s ease;
    }

    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Mobile Menu */
    .mobile-menu-toggle {
        display: none;
        background: none;
        border: none;
        color: white;
        font-size: 1.5rem;
        padding: 0.5rem;
        cursor: pointer;
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
    }

    .nav-container {
        display: flex;
        justify-content: center;
        align-items: center;
        flex-wrap: wrap;
    }

    /* Container principal padrão */
    .container {
        max-width: 1000px;
        margin: 2rem auto;
        padding: 2.5rem;
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        animation: fadeIn 0.5s ease;
        flex: 1;
    }

    .main-content {
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Títulos padrão */
    h1, h2 {
        text-align: center;
        color: var(--primary-color);
        margin-bottom: 2rem;
        font-weight: 600;
        position: relative;
    }

    h1 {
        font-size: 2.5rem;
    }

    h2 {
        font-size: 2rem;
    }

    h1::after, h2::after {
        content: '';
        position: absolute;
        bottom: -0.5rem;
        left: 50%;
        transform: translateX(-50%);
        width: 80px;
        height: 3px;
        background: var(--secondary-color);
        border-radius: 2px;
    }

    /* Botões padrão */
    .btn {
        padding: 1rem 2rem;
        font-size: 1rem;
        font-weight: 600;
        border: none;
        border-radius: var(--radius);
        cursor: pointer;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        text-decoration: none;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--secondary-color) 0%, #009d8f 100%);
        color: white;
    }

    .btn-secondary {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
        color: white;
    }

    .btn-danger {
        background: linear-gradient(135deg, var(--danger-color) 0%, #c82333 100%);
        color: white;
    }

    .btn-success {
        background: linear-gradient(135deg, var(--success-color) 0%, #218838 100%);
        color: white;
    }

    .btn-warning {
        background: linear-gradient(135deg, var(--warning-color) 0%, #e0a800 100%);
        color: var(--dark-gray);
    }

    .btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 12px rgba(0,0,0,0.2);
    }

    .btn:active {
        transform: translateY(-1px);
    }

    .btn-disabled {
        background: var(--medium-gray) !important;
        cursor: not-allowed !important;
        opacity: 0.6;
    }

    .btn-disabled:hover {
        transform: none !important;
        box-shadow: none !important;
    }

    /* Formulários padrão */
    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: var(--dark-gray);
        font-size: 0.95rem;
    }

    .form-control {
        width: 100%;
        padding: 1rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius);
        font-size: 1rem;
        transition: var(--transition);
        background: white;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        transform: translateY(-2px);
    }

    /* Mensagens */
    .message {
        padding: 1rem;
        border-radius: var(--radius);
        margin-bottom: 1.5rem;
        font-weight: 500;
        text-align: center;
        animation: slideInDown 0.5s ease;
    }

    .message.error {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
        color: white;
        border: 1px solid #ff5252;
    }

    .message.success {
        background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
        color: white;
        border: 1px solid #51cf66;
    }

    .message.warning {
        background: linear-gradient(135deg, var(--warning-color) 0%, #e0a800 100%);
        color: var(--dark-gray);
        border: 1px solid var(--warning-color);
    }

    .message.info {
        background: linear-gradient(135deg, #74c0fc 0%, #339af0 100%);
        color: white;
        border: 1px solid #339af0;
    }

    @keyframes slideInDown {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Tabelas padrão */
    .table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: var(--radius);
        overflow: hidden;
        box-shadow: var(--shadow);
        margin: 1rem 0;
    }

    .table th,
    .table td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
    }

    .table th {
        background: var(--light-gray);
        font-weight: 600;
        color: var(--dark-gray);
    }

    .table tbody tr:hover {
        background: rgba(0, 191, 174, 0.05);
    }

    /* Cards */
    .card {
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 2rem;
        margin: 1rem 0;
        transition: var(--transition);
    }

    .card:hover {
        box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }

    .card-header {
        background: var(--light-gray);
        margin: -2rem -2rem 2rem -2rem;
        padding: 1.5rem 2rem;
        border-radius: var(--radius) var(--radius) 0 0;
        border-bottom: 1px solid var(--border-color);
    }

    .card-title {
        color: var(--primary-color);
        font-size: 1.25rem;
        font-weight: 600;
        margin: 0;
    }

    /* Footer padrão */
    footer {
        background: var(--primary-color);
        color: white;
        padding: 1rem 0;
        text-align: center;
        font-size: 0.9rem;
        margin-top: auto;
    }

    footer p {
        color: rgba(255, 255, 255, 0.8);
        margin: 0.25rem 0;
    }

    footer a {
        color: white;
        text-decoration: none;
        font-weight: 600;
        transition: var(--transition);
    }

    footer a:hover {
        color: var(--secondary-color);
        text-decoration: underline;
    }

    /* Responsividade */
    @media (max-width: 1200px) {
        .container {
            margin: 1.5rem;
        }
    }

    @media (max-width: 768px) {
        header {
            position: relative;
        }

        .mobile-menu-toggle {
            display: block;
        }

        .user-info {
            position: static;
            transform: none;
            margin-top: 1rem;
            justify-content: center;
            font-size: 0.8rem;
        }

        .logo {
            max-width: 120px;
        }

        .nav-container {
            display: none;
            flex-direction: column;
            width: 100%;
            position: absolute;
            top: 100%;
            left: 0;
            background: var(--primary-color);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .nav-container.active {
            display: flex;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dropdown {
            width: 100%;
        }

        nav a {
            padding: 0.875rem 1.5rem;
            font-size: 0.85rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            width: 100%;
            text-align: left;
        }

        .dropdown-content {
            position: static;
            display: none;
            box-shadow: none;
            border-radius: 0;
            background: rgba(0,0,0,0.2);
        }

        .dropdown:hover .dropdown-content {
            display: block;
        }

        .dropdown-content a {
            padding-left: 2rem;
            font-size: 0.8rem;
        }

        .container {
            padding: 1.5rem;
        }

        h1 {
            font-size: 2rem;
        }

        h2 {
            font-size: 1.75rem;
        }

        .btn {
            padding: 0.875rem 1.5rem;
            font-size: 0.9rem;
        }

        .table th,
        .table td {
            padding: 0.75rem;
        }

        .card {
            padding: 1.5rem;
        }
    }

    @media (max-width: 480px) {
        .logo {
            max-width: 100px;
        }

        nav a {
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
        }

        .dropdown-content a {
            padding-left: 1.5rem;
            font-size: 0.75rem;
        }

        .container {
            padding: 1.25rem;
            margin: 1rem;
        }

        h1 {
            font-size: 1.75rem;
        }

        h2 {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .btn {
            padding: 0.75rem 1.25rem;
            font-size: 0.85rem;
        }

        .user-info {
            font-size: 0.75rem;
            padding: 0.3rem 0.8rem;
        }

        .table th,
        .table td {
            padding: 0.5rem;
            font-size: 0.85rem;
        }

        .card {
            padding: 1.25rem;
        }

        footer {
            font-size: 0.8rem;
            padding: 0.75rem 0;
        }
    }

    /* Hover effects para mobile */
    @media (hover: none) {
        .btn:active {
            transform: scale(0.98);
        }
        
        .card:hover {
            transform: none;
        }
    }

    /* Utilitários */
    .text-center { text-align: center; }
    .text-left { text-align: left; }
    .text-right { text-align: right; }

    .mt-1 { margin-top: 0.5rem; }
    .mt-2 { margin-top: 1rem; }
    .mt-3 { margin-top: 1.5rem; }
    .mt-4 { margin-top: 2rem; }

    .mb-1 { margin-bottom: 0.5rem; }
    .mb-2 { margin-bottom: 1rem; }
    .mb-3 { margin-bottom: 1.5rem; }
    .mb-4 { margin-bottom: 2rem; }

    .p-1 { padding: 0.5rem; }
    .p-2 { padding: 1rem; }
    .p-3 { padding: 1.5rem; }
    .p-4 { padding: 2rem; }

    .d-none { display: none; }
    .d-block { display: block; }
    .d-flex { display: flex; }
    .d-inline-block { display: inline-block; }

    .flex-column { flex-direction: column; }
    .align-center { align-items: center; }
    .justify-center { justify-content: center; }
    .justify-between { justify-content: space-between; }

    .w-100 { width: 100%; }
    .h-100 { height: 100%; }

    .text-primary { color: var(--primary-color); }
    .text-secondary { color: var(--secondary-color); }
    .text-danger { color: var(--danger-color); }
    .text-success { color: var(--success-color); }
    .text-warning { color: var(--warning-color); }
    .text-muted { color: var(--medium-gray); }

    .bg-primary { background-color: var(--primary-color); }
    .bg-secondary { background-color: var(--secondary-color); }
    .bg-light { background-color: var(--light-gray); }
    .bg-white { background-color: white; }

    .shadow { box-shadow: var(--shadow); }
    .rounded { border-radius: var(--radius); }

    /* Loading spinner */
    .spinner {
        border: 3px solid #f3f3f3;
        border-top: 3px solid var(--secondary-color);
        border-radius: 50%;
        width: 30px;
        height: 30px;
        animation: spin 1s linear infinite;
        margin: 0 auto;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .loading {
        display: none;
        text-align: center;
        margin: 1rem 0;
    }

    /* Badge */
    .badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
        font-weight: 600;
        border-radius: 10px;
        text-align: center;
        white-space: nowrap;
        vertical-align: baseline;
    }

    .badge-primary { background-color: var(--primary-color); color: white; }
    .badge-secondary { background-color: var(--secondary-color); color: white; }
    .badge-success { background-color: var(--success-color); color: white; }
    .badge-danger { background-color: var(--danger-color); color: white; }
    .badge-warning { background-color: var(--warning-color); color: var(--dark-gray); }
    .badge-light { background-color: var(--light-gray); color: var(--dark-gray); }

    /* Progress bar */
    .progress {
        height: 1rem;
        background-color: var(--light-gray);
        border-radius: var(--radius);
        overflow: hidden;
        box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
    }

    .progress-bar {
        height: 100%;
        background: linear-gradient(135deg, var(--secondary-color) 0%, #009d8f 100%);
        transition: width 0.3s ease;
    }

    /* Accordion */
    .accordion {
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        overflow: hidden;
    }

    .accordion-item {
        border-bottom: 1px solid var(--border-color);
    }

    .accordion-item:last-child {
        border-bottom: none;
    }

    .accordion-header {
        background: var(--light-gray);
        padding: 1rem;
        cursor: pointer;
        font-weight: 600;
        transition: var(--transition);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .accordion-header:hover {
        background: #e9ecef;
    }

    .accordion-content {
        padding: 1rem;
        display: none;
        background: white;
    }

    .accordion-content.active {
        display: block;
        animation: fadeIn 0.3s ease;
    }

    /* Tooltip */
    .tooltip {
        position: relative;
        display: inline-block;
    }

    .tooltip .tooltiptext {
        visibility: hidden;
        width: 200px;
        background-color: var(--dark-gray);
        color: white;
        text-align: center;
        border-radius: var(--radius);
        padding: 0.5rem;
        position: absolute;
        z-index: 1001;
        bottom: 125%;
        left: 50%;
        margin-left: -100px;
        opacity: 0;
        transition: opacity 0.3s;
        font-size: 0.8rem;
    }

    .tooltip .tooltiptext::after {
        content: "";
        position: absolute;
        top: 100%;
        left: 50%;
        margin-left: -5px;
        border-width: 5px;
        border-style: solid;
        border-color: var(--dark-gray) transparent transparent transparent;
    }

    .tooltip:hover .tooltiptext {
        visibility: visible;
        opacity: 1;
    }

    /* Modal básico */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        animation: fadeIn 0.3s ease;
    }

    .modal-content {
        background-color: white;
        margin: 5% auto;
        padding: 2rem;
        border-radius: var(--radius);
        width: 90%;
        max-width: 600px;
        position: relative;
        animation: slideInDown 0.3s ease;
    }

    .modal-close {
        position: absolute;
        right: 1rem;
        top: 1rem;
        font-size: 1.5rem;
        cursor: pointer;
        color: var(--medium-gray);
        transition: var(--transition);
    }

    .modal-close:hover {
        color: var(--danger-color);
    }
</style>