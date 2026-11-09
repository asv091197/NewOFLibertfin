<?php
session_start();
?>
<!DOCTYPE html>
<html lang="es-mx">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carrito de Compras | Libertyfin</title>
    <meta name="description" content="Revisa y confirma los productos seleccionados para tu suscripción a Libertyfin. Pago seguro con pasarela de pago.">
    <link rel="canonical" href="https://libertyfin.com.mx/Carrito/mi-carrito.php">
    <meta name="robots" content="noindex, follow">

    <!-- Open Graph -->
    <meta property="og:title" content="Carrito de Compras | Libertyfin">
    <meta property="og:description" content="Revisa y confirma los productos seleccionados para tu suscripción a Libertyfin.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://libertyfin.com.mx/Carrito/mi-carrito.php">
    <meta property="og:image" content="https://libertyfin.com.mx/images/Libertyfin.webp">
    <meta property="og:locale" content="es_MX">
    <meta property="og:site_name" content="Libertyfin">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Carrito de Compras | Libertyfin">
    <meta name="twitter:description" content="Revisa y confirma los productos seleccionados para tu suscripción.">
    <meta name="twitter:image" content="https://libertyfin.com.mx/images/Libertyfin.webp">

    <!-- Schema JSON-LD -->
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "Organization",
            "name": "Libertyfin",
            "url": "https://libertyfin.com.mx",
            "logo": "https://libertyfin.com.mx/images/Libertyfin.webp",
            "contactPoint": {
                "@type": "ContactPoint",
                "telephone": "+52-56-1106-2657",
                "contactType": "customer support",
                "areaServed": "MX",
                "availableLanguage": "Spanish"
            }
        }
    </script>

    <!-- Preconnect y Preload -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>

    <!-- CSS -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'" crossorigin="anonymous">
    <noscript>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
    </noscript>

    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    </noscript>

    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'" crossorigin="anonymous">
    <noscript>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </noscript>

    <!-- Estilos personalizados -->
    <link rel="stylesheet" href="../css/main.css">

    <style>
        /* Estilos específicos del carrito */
        .cart-container {
            background-color: #fff;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            margin-top: 2rem;
            margin-bottom: 2rem;
        }

        .cart-table thead th {
            border-bottom: 2px solid #27ae60;
            color: #2c3e50;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }

        .cart-table tbody td {
            vertical-align: middle;
            padding: 1.5rem 0.5rem;
            border-bottom: 1px solid #e9ecef;
        }

        .product-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }

        .product-sku {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .price-display {
            font-weight: 700;
            color: #27ae60;
            font-size: 1.2rem;
        }

        .total-display {
            font-weight: 800;
            color: #2c3e50;
            font-size: 1.3rem;
        }

        .cart-summary {
            background-color: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            font-size: 1.1rem;
        }

        .summary-row.total {
            border-top: 2px solid #dee2e6;
            margin-top: 1rem;
            padding-top: 1rem;
            font-weight: 800;
            font-size: 1.5rem;
            color: #2c3e50;
        }

        .btn-pagar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            border: none;
            border-radius: 50px;
            padding: 15px 40px;
            font-weight: 700;
            font-size: 1.3rem;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(46, 204, 113, 0.3);
            border: 2px solid transparent;
        }

        .btn-pagar:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px rgba(46, 204, 113, 0.4);
        }

        .btn-pagar-paypal {
            background: linear-gradient(135deg, #0070ba, #1546a0);
            box-shadow: 0 8px 20px rgba(0, 112, 186, 0.3);
            margin-top: 15px;
        }

        .btn-pagar-paypal:hover {
            box-shadow: 0 12px 25px rgba(0, 112, 186, 0.4);
        }

        .btn-transferencia {
            background: linear-gradient(135deg, #1a4d8c, #2c6db5);
            box-shadow: 0 8px 20px rgba(26, 77, 140, 0.3);
            margin-top: 15px;
        }

        .btn-transferencia:hover {
            box-shadow: 0 12px 25px rgba(26, 77, 140, 0.4);
        }

        .cantidad-control {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .cantidad-control .btn-cantidad {
            background-color: #f1f3f4;
            border: none;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #2c3e50;
            transition: 0.2s;
            cursor: pointer;
        }

        .cantidad-control .btn-cantidad:hover {
            background-color: #27ae60;
            color: white;
        }

        .cantidad-control span {
            font-weight: 600;
            min-width: 30px;
            text-align: center;
        }

        .remove-item {
            color: #dc3545;
            background: none;
            border: none;
            font-size: 1.2rem;
            opacity: 0.5;
            transition: 0.2s;
            cursor: pointer;
        }

        .remove-item:hover {
            opacity: 1;
        }

        .btn-seguir-comprando {
            background-color: #6c757d;
            border: none;
            border-radius: 50px;
            padding: 10px 25px;
            font-weight: 600;
            color: white;
            text-decoration: none;
            display: inline-block;
            transition: 0.3s;
        }

        .btn-seguir-comprando:hover {
            background-color: #5a6268;
            color: white;
        }

        .metodos-pago {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }

        .badge-paypal {
            background-color: #ffc439;
            color: #2c2e2f;
            padding: 5px 15px;
            border-radius: 25px;
            font-weight: 600;
        }

        /* Estilos para el modal de transferencia */
        .modal-content {
            border-radius: 20px;
            border: none;
        }

        .clabe-container code {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            word-break: break-all;
            font-size: 1.2rem;
            font-weight: bold;
            letter-spacing: 2px;
        }

        #copiar-clabe {
            transition: all 0.3s ease;
        }

        #copiar-clabe:hover {
            transform: scale(1.05);
        }

        .modal-header {
            border-radius: 20px 20px 0 0;
        }

        @media (max-width: 768px) {
            .cart-table thead {
                display: none;
            }

            .cart-table tbody tr {
                display: block;
                border: 1px solid #e9ecef;
                border-radius: 10px;
                margin-bottom: 1rem;
                padding: 1rem;
            }

            .cart-table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: none;
                padding: 0.5rem 0;
            }

            .cart-table tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #6c757d;
                margin-right: 1rem;
            }
        }

        /* Loader */
        .loader {
            border: 3px solid #f3f3f3;
            border-radius: 50%;
            border-top: 3px solid #27ae60;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 10px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }

        /* Notificaciones */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 9999;
            display: none;
            animation: slideIn 0.3s ease;
        }

        .notification.success {
            border-left: 4px solid #27ae60;
        }

        .notification.error {
            border-left: 4px solid #dc3545;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
</head>

<body>

    <!-- Placeholder para el Navbar (será inyectado por shared.js) -->
    <div id="lf-nav-placeholder"></div>

    <!-- Notificación -->
    <div id="notification" class="notification">
        <span id="notification-message"></span>
    </div>

    <!-- Contenido principal -->
    <main class="container my-5">
        <div class="cart-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 style="color: #2c3e50; font-weight: 700;">Carrito de Compras (<span id="cart-count">0</span>)</h1>
                <a href="preciosprueba" class="btn-seguir-comprando">
                    <i class="fas fa-arrow-left me-2"></i>Seguir Comprando
                </a>
            </div>

            <!-- Tabla de productos -->
            <div class="table-responsive">
                <table class="table cart-table">
                    <thead>
                        <tr>
                            <th scope="col">PRODUCTO</th>
                            <th scope="col">PRECIO</th>
                            <th scope="col">CANTIDAD</th>
                            <th scope="col">TOTAL</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody id="cart-items">
                        <!-- Los productos se insertarán aquí dinámicamente -->
                    </tbody>
                </table>
            </div>

            <!-- Mensaje si el carrito está vacío -->
            <div id="empty-cart-message" class="text-center py-5">
                <i class="fas fa-shopping-cart fa-4x text-muted mb-3"></i>
                <h3 class="text-muted">Tu carrito está vacío</h3>
                <p class="text-muted">Agrega algunos planes desde la página principal.</p>
                <a href="preciosprueba" class="btn btn-custom mt-3">Ver Planes</a>
            </div>

            <!-- Resumen del carrito (oculto por defecto) -->
            <div id="cart-summary" class="cart-summary" style="display: none;">
                <div class="summary-row">
                    <span>Subtotal</span>
                    <span id="subtotal">$ 0.00</span>
                </div>
                <div class="summary-row">
                    <span>IVA (16%)</span>
                    <span id="iva">$ 0.00</span>
                </div>
                <div class="summary-row total">
                    <span>Total (IVA incluido)</span>
                    <span id="total">MXNS 0.00</span>
                </div>
            </div>

            <!-- Botones de pago (ocultos por defecto) -->
            <div id="pay-buttons" class="text-center mt-4" style="display: none;">
                <!-- Botón de Pago en Línea (Pagadetodo) -->
                <button class="btn-pagar" id="pago-en-linea-btn">
                    <i class="fas fa-credit-card me-2"></i>Pago en Línea
                </button>

                <!-- Botón de Transferencia SPEI -->
                <button class="btn-pagar btn-transferencia" id="transferencia-btn">
                    <i class="fas fa-exchange-alt me-2"></i>Pagar por Transferencia (SPEI)
                </button>

                <!-- Botón de PayPal -->
                <form action="procesar-pago-paypal.php" method="POST" id="paypal-form" style="display: inline-block; width: 100%; margin-top: 15px;">
                    <input type="hidden" name="carrito_data" id="carrito-data">
                    <input type="hidden" name="total" id="total-hidden">
                    <input type="hidden" name="referencia" id="referencia-hidden">
                    <button type="submit" class="btn-pagar btn-pagar-paypal" id="paypal-button">
                        <i class="fab fa-paypal me-2"></i>Pagar con PayPal
                    </button>
                </form>

                <div class="metodos-pago">
                    <span class="badge-paypal">
                        <i class="fas fa-lock me-1"></i>Pago 100% seguro
                    </span>
                </div>

                <p class="text-muted mt-3 small">
                    Al hacer clic en "Pago en Línea" serás redirigido a la pasarela de pago.<br>
                    Con PayPal serás redirigido a PayPal para completar tu pago de forma segura.<br>
                    Con Transferencia SPEI obtendrás una CLABE para realizar tu pago.
                </p>
            </div>
        </div>
    </main>

    <!-- Placeholder para el Footer (será inyectado por shared.js) -->
    <div id="lf-footer-placeholder"></div>

   <!-- Modal para mostrar CLABE de transferencia -->
<div class="modal fade" id="modalTransferencia" tabindex="-1" aria-labelledby="modalTransferenciaLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #1a4d8c, #2c6db5); color: white;">
                <h5 class="modal-title" id="modalTransferenciaLabel">
                    <i class="fas fa-exchange-alt me-2"></i>Datos para Transferencia SPEI
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="transferencia-loading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Generando CLABE...</span>
                    </div>
                    <p class="mt-2">Generando CLABE bancaria...</p>
                </div>
                <div id="transferencia-contenido" style="display: none;">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Realiza tu transferencia a la siguiente cuenta CLABE:
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-body text-center">
                            <h6 class="text-muted mb-2">CLABE Interbancaria</h6>
                            <div class="clabe-container" style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 10px;">
                                <code id="clabe-generada" style="font-size: 1.4rem; font-weight: bold; letter-spacing: 2px;">Cargando...</code>
                            </div>
                            <button class="btn btn-sm btn-outline-primary" id="copiar-clabe">
                                <i class="fas fa-copy me-1"></i>Copiar CLABE
                            </button>

                            <!-- Campos de Beneficiario y Entidad -->
                            <div class="row mt-4 pt-2 border-top">
                                <div class="col-12 col-md-6 mb-3">
                                    <div class="text-start">
                                        <small class="text-muted">
                                            <i class="fas fa-user me-1"></i>Beneficiario:
                                        </small>
                                        <p class="fw-bold mb-0" id="beneficiario-nombre" style="font-size: 1rem; color: #2c3e50;">
                                            OPERACIONES Y MULTISERVICIOS IDEAS, SA. DE C.V. (GRUPO IDEAS)
                                        </p>
                                    </div>
                                </div>
                                <div class="col-12 col-md-6 mb-3">
                                    <div class="text-start">
                                        <small class="text-muted">
                                            <i class="fas fa-university me-1"></i>Entidad bancaria:
                                        </small>
                                        <p class="fw-bold mb-0" id="entidad-bancaria" style="font-size: 1rem; color: #2c3e50;">
                                            STP (Sistema de Transferencias y Pagos)
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <!-- Fin de Beneficiario y Entidad -->
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h6 class="font-weight-bold mb-3">Información de la transferencia:</h6>
                            <div class="row">
                                <div class="col-6">
                                    <small class="text-muted">Monto a pagar:</small>
                                    <p class="font-weight-bold" id="monto-modal" style="font-size: 1.2rem; color: #27ae60;">$ 0.00</p>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Referencia:</small>
                                    <p class="font-weight-bold" id="referencia-modal" style="font-family: monospace;">-</p>
                                </div>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">Concepto:</small>
                                <p id="concepto-modal">Pago Libertyfin</p>
                            </div>
                            <hr>
                            <div class="alert alert-warning small">
                                <i class="fas fa-clock me-2"></i>
                                <strong>Importante:</strong> La CLABE expira en 24 horas. Realiza tu transferencia dentro de este periodo.
                            </div>
                            <div class="alert alert-secondary small">
                                <i class="fas fa-check-circle me-2"></i>
                                Una vez realizada la transferencia, envía el comprobante a 
                                <strong>soporte@libertyfin.com.mx</strong> para confirmar tu pago.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" defer></script>
    
    <!-- shared.js - Inyecta navbar y footer dinámicamente -->
    <script src="../js/shared.js"></script>

    <!-- Script principal del carrito -->
    <script>
        // DEFINICIÓN DE PLANES (coincide exactamente con preciosprueba.html)
        const PLANES = {
            'Básico': {
                nombre: 'Básico',
                precioMensual: 299,
                precioAnual: 239,
                descripcion: '1 caja registradora · 100 productos · Pago en efectivo',
                usuarios: 1,
                sku: 'BAS-001'
            },
            'Profesional': {
                nombre: 'Profesional',
                precioMensual: 599,
                precioAnual: 479,
                descripcion: '2 cajas registradoras · 500 productos · Pago en efectivo',
                usuarios: 4,
                sku: 'PRO-002'
            },
            'Empresarial': {
                nombre: 'Empresarial',
                precioMensual: 999,
                precioAnual: 799,
                descripcion: '3 cajas registradoras · 1 sucursal · 500 productos · Pasarela de pago · SPEI / PayPal',
                usuarios: 6,
                sku: 'EMP-003'
            },
            'Empresarial Plus': {
                nombre: 'Empresarial Plus',
                precioMensual: 1499,
                precioAnual: 1199,
                descripcion: '10 cajas registradoras · 3 sucursales · Productos ilimitados · Pasarela de pago · SPEI / PayPal · Tarjeta de crédito · 500 CFDI / Timbres',
                usuarios: 10,
                sku: 'EMP-004'
            }
        };

        // Configuración de tipo de pago (mensual por defecto, pero se puede cambiar)
        let tipoPago = 'mensual'; // 'mensual' o 'anual'

        document.addEventListener('DOMContentLoaded', function() {
            // Elementos del DOM
            const cartItems = document.getElementById('cart-items');
            const emptyMessage = document.getElementById('empty-cart-message');
            const summaryDiv = document.getElementById('cart-summary');
            const payButtons = document.getElementById('pay-buttons');
            const cartCount = document.getElementById('cart-count');
            const notification = document.getElementById('notification');
            const notificationMessage = document.getElementById('notification-message');

            // Obtener parámetros de la URL (indexprueba.html envía producto, precio, descripcion)
            const urlParams = new URLSearchParams(window.location.search);
            const producto = urlParams.get('producto');
            const precio = urlParams.get('precio');
            const descripcion = urlParams.get('descripcion');
            const planKey = urlParams.get('plan'); // Podemos pasar 'Basico', 'Profesional', etc.
            
            // Obtener carrito de localStorage o inicializar vacío
            let carrito = JSON.parse(localStorage.getItem('libertyfin_carrito')) || [];

            // Función para mostrar notificación
            function showNotification(message, type = 'success') {
                notification.className = 'notification ' + type;
                notificationMessage.textContent = message;
                notification.style.display = 'block';
                
                setTimeout(() => {
                    notification.style.display = 'none';
                }, 3000);
            }

            // Función para obtener precio según tipo de pago
            function getPrecioPlan(nombrePlan, esAnual = false) {
                const plan = PLANES[nombrePlan];
                if (!plan) return null;
                return esAnual ? plan.precioAnual : plan.precioMensual;
            }

            // Función para obtener descripción completa del plan
            function getDescripcionPlan(nombrePlan) {
                const plan = PLANES[nombrePlan];
                return plan ? plan.descripcion : 'Plan Libertyfin';
            }

            // Si hay un producto en la URL, agregarlo al carrito
            if (producto && precio) {
                const precioNumerico = parseFloat(precio);
                
                // Buscar el plan en nuestra definición
                let planNombre = producto;
                let planInfo = PLANES[producto];
                
                // Si no se encuentra por nombre exacto, intentar mapear
                if (!planInfo) {
                    if (producto.includes('Básico') || producto.includes('Basico')) planNombre = 'Básico';
                    else if (producto.includes('Profesional')) planNombre = 'Profesional';
                    else if (producto.includes('Empresarial Plus') || producto.includes('Plus')) planNombre = 'Empresarial Plus';
                    else if (producto.includes('Empresarial')) planNombre = 'Empresarial';
                    planInfo = PLANES[planNombre];
                }
                
                if (isNaN(precioNumerico) || precioNumerico <= 0) {
                    showNotification('Precio no válido', 'error');
                } else if (planInfo) {
                    const existingProductIndex = carrito.findIndex(item => item.producto === planInfo.nombre);
                    
                    // Determinar si es anual (precio más bajo sugiere anual)
                    const esAnual = precioNumerico === planInfo.precioAnual;
                    const tipoPagoPlan = esAnual ? 'anual' : 'mensual';

                    if (existingProductIndex >= 0) {
                        carrito[existingProductIndex].cantidad += 1;
                        showNotification(`${planInfo.nombre} agregado al carrito`, 'success');
                    } else {
                        carrito.push({
                            producto: planInfo.nombre,
                            precio: precioNumerico,
                            precioMensual: planInfo.precioMensual,
                            precioAnual: planInfo.precioAnual,
                            descripcion: getDescripcionPlan(planInfo.nombre),
                            cantidad: 1,
                            sku: planInfo.sku,
                            tipoPago: tipoPagoPlan,
                            usuarios: planInfo.usuarios
                        });
                        showNotification(`${planInfo.nombre} agregado al carrito`, 'success');
                    }

                    localStorage.setItem('libertyfin_carrito', JSON.stringify(carrito));
                    window.history.replaceState({}, document.title, window.location.pathname);
                } else {
                    // Fallback genérico si no se encuentra el plan
                    const existingProductIndex = carrito.findIndex(item => item.producto === producto);
                    if (existingProductIndex >= 0) {
                        carrito[existingProductIndex].cantidad += 1;
                    } else {
                        carrito.push({
                            producto: producto,
                            precio: precioNumerico,
                            descripcion: descripcion || 'Plan Libertyfin',
                            cantidad: 1,
                            sku: 'PLAN-' + Date.now()
                        });
                    }
                    localStorage.setItem('libertyfin_carrito', JSON.stringify(carrito));
                    showNotification('Producto agregado al carrito', 'success');
                    window.history.replaceState({}, document.title, window.location.pathname);
                }
            }

            // Función para sincronizar carrito con PHP
            function sincronizarCarritoConPHP() {
                return new Promise((resolve, reject) => {
                    const carritoDataInput = document.getElementById('carrito-data');
                    if (carritoDataInput) {
                        carritoDataInput.value = JSON.stringify(carrito);
                    }

                    fetch('sincronizar-carrito.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            carrito: carrito
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log('Carrito sincronizado:', data);
                        resolve(data);
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        reject(error);
                    });
                });
            }

            // Función para formatear moneda
            function formatMoney(amount) {
                return amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            }

            // Función para obtener fecha de expiración (24 horas después)
            function getExpirationDate() {
                const fecha = new Date();
                fecha.setHours(fecha.getHours() + 24);
                return fecha.toISOString().slice(0, 19).replace('T', ' ');
            }

            // Función para renderizar el carrito
            function renderCarrito() {
                cartItems.innerHTML = '';

                if (carrito.length === 0) {
                    emptyMessage.style.display = 'block';
                    summaryDiv.style.display = 'none';
                    payButtons.style.display = 'none';
                    cartCount.textContent = '0';
                } else {
                    emptyMessage.style.display = 'none';
                    summaryDiv.style.display = 'block';
                    payButtons.style.display = 'block';
                    cartCount.textContent = carrito.length;

                    carrito.forEach((item, index) => {
                        const precioNumerico = parseFloat(item.precio) || 0;
                        const cantidadNumerica = parseInt(item.cantidad) || 1;
                        const totalItem = precioNumerico * cantidadNumerica;
                        
                        // Mostrar tipo de pago si es un plan reconocido
                        let tipoPagoTexto = '';
                        if (item.tipoPago) {
                            tipoPagoTexto = `<span class="badge bg-info" style="font-size: 10px; margin-left: 5px;">${item.tipoPago === 'anual' ? 'Pago Anual' : 'Pago Mensual'}</span>`;
                        }

                        const fila = document.createElement('tr');
                        fila.innerHTML = `
                            <td data-label="PRODUCTO">
                                <div class="product-title">${escapeHtml(item.producto)} ${tipoPagoTexto}</div>
                                <div class="product-sku">${escapeHtml(item.descripcion || 'Plan Libertyfin')}</div>
                            </td>
                            <td data-label="PRECIO">
                                <span class="price-display">$ ${formatMoney(precioNumerico)}</span>
                            </td>
                            <td data-label="CANTIDAD">
                                <div class="cantidad-control">
                                    <button class="btn-cantidad" data-index="${index}" data-action="decrease">−</button>
                                    <span class="cantidad-span">${cantidadNumerica}</span>
                                    <button class="btn-cantidad" data-index="${index}" data-action="increase">+</button>
                                </div>
                            </td>
                            <td data-label="TOTAL">
                                <span class="total-display">$ ${formatMoney(totalItem)}</span>
                            </td>
                            <tr>
                                <button class="remove-item" data-index="${index}" aria-label="Eliminar producto">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </td>
                        `;
                        cartItems.appendChild(fila);
                    });

                    actualizarTotales();
                }
            }

            // Función para escapar HTML
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // Función para actualizar totales
            function actualizarTotales() {
                let subtotal = 0;
                carrito.forEach(item => {
                    const precio = parseFloat(item.precio) || 0;
                    const cantidad = parseInt(item.cantidad) || 1;
                    subtotal += precio * cantidad;
                });

                const iva = subtotal * 0.16;
                const total = subtotal + iva;

                document.getElementById('subtotal').textContent = '$ ' + formatMoney(subtotal);
                document.getElementById('iva').textContent = '$ ' + formatMoney(iva);
                document.getElementById('total').textContent = 'MXNS ' + formatMoney(total).replace('.', ',');
                document.getElementById('total-hidden').value = total;
                
                const referencia = 'REF-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5);
                document.getElementById('referencia-hidden').value = referencia;
            }

            // Función para generar CLABE de transferencia
            // Función para generar CLABE de transferencia - VERSIÓN CORREGIDA
function generarCLABETransferencia() {
    return new Promise((resolve, reject) => {
        let total = 0;
        let productos = [];
        
        // Calcular total y preparar productos
        carrito.forEach(item => {
            const precio = parseFloat(item.precio) || 0;
            const cantidad = parseInt(item.cantidad) || 1;
            total += precio * cantidad;
            productos.push({
                nombre: item.producto,
                precio: precio,
                cantidad: cantidad,
                sku: item.sku || '',
                descripcion: item.descripcion || ''
            });
        });
        
        const iva = total * 0.16;
        const totalConIva = total + iva;
        const referencia = 'SPEI-' + Date.now() + '-' + Math.random().toString(36).substr(2, 8).toUpperCase();
        
        // Obtener datos del cliente
        const customerEmail = localStorage.getItem('cliente_email') || 'cliente@libertyfin.com.mx';
        const customerName = localStorage.getItem('cliente_nombre') || 'Cliente Libertyfin';
        
        // ============================================
        // GENERAR ACCOUNT DE MÁXIMO 15 DÍGITOS
        // ============================================
        function generarAccountValido() {
            // Opción 1: Usar ID de usuario si existe (debe ser numérico y <= 15 dígitos)
            const userId = localStorage.getItem('usuario_id') || sessionStorage.getItem('usuario_id');
            if (userId) {
                const numId = parseInt(userId);
                if (!isNaN(numId) && numId > 0) {
                    const idStr = numId.toString();
                    if (idStr.length <= 15) {
                        return idStr;
                    }
                    // Si es más largo, tomar los últimos 15 dígitos
                    return idStr.slice(-15);
                }
            }
            
            // Opción 2: Generar hash numérico del email (máximo 15 dígitos)
            const email = localStorage.getItem('cliente_email') || 'cliente@libertyfin.com.mx';
            let hash = 0;
            for (let i = 0; i < email.length; i++) {
                hash = ((hash << 5) - hash) + email.charCodeAt(i);
                hash = hash & hash; // Convertir a 32bit
            }
            // Asegurar que sea positivo y máximo 15 dígitos
            const account = Math.abs(hash) % 900000000000000 + 100000000000000;
            return account.toString();
        }
        
        const account = generarAccountValido();
        
        console.log('Account generado:', account, 'longitud:', account.length);
        
        // Validar que Account tenga máximo 15 dígitos
        if (account.length > 15) {
            console.error('Account excede 15 dígitos:', account);
            // Truncar a 15 dígitos
            const accountTruncado = account.slice(-15);
            console.log('Account truncado a 15 dígitos:', accountTruncado);
            // Reasignar
            account = accountTruncado;
        }
        
        // Construir datos para enviar
        const datosClabe = {
            Description: "Pago Libertyfin",
            CustomerEmail: customerEmail,
            CustomerName: customerName,
            Account: account,
            ExpirationDate: getExpirationDate(),
            MontoTotal: totalConIva,
            IVA: iva,
            Productos: productos
        };
        
        console.log('Enviando datos:', datosClabe);
        
        localStorage.setItem('referencia_spei', referencia);
        localStorage.setItem('monto_spei', totalConIva);
        localStorage.setItem('productos_spei', JSON.stringify(productos));
        localStorage.setItem('account_spei', account);
        
        const url = '../Service/generar_clabe.php';
        
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(datosClabe)
        })
        .then(response => response.text())
        .then(text => {
            console.log('Respuesta RAW:', text);
            
            try {
                const data = JSON.parse(text);
                
                // Verificar si hay error
                if (data.error) {
                    reject(new Error(data.error));
                    return;
                }
                
                if (data.success && data.clabe) {
                    resolve({
                        clabe: data.clabe,
                        referencia: referencia,
                        monto: totalConIva,
                        descripcion: datosClabe.Description,
                        id: data.id,
                        reutilizada: data.reutilizada || false
                    });
                } else {
                    reject(new Error(data.error || 'No se encontró CLABE en la respuesta'));
                }
            } catch (e) {
                console.error('Error parseando JSON:', e);
                reject(new Error('Respuesta no es JSON válido: ' + text));
            }
        })
        .catch(error => {
            console.error('Error en fetch:', error);
            reject(new Error('Error de conexión: ' + error.message));
        });
    });
}

            // Event listeners para botones del carrito
            document.addEventListener('click', function(e) {
                if (e.target.closest('.btn-cantidad[data-action="increase"]')) {
                    const btn = e.target.closest('.btn-cantidad');
                    const index = btn.getAttribute('data-index');
                    carrito[index].cantidad += 1;
                    localStorage.setItem('libertyfin_carrito', JSON.stringify(carrito));
                    renderCarrito();
                    sincronizarCarritoConPHP();
                }

                if (e.target.closest('.btn-cantidad[data-action="decrease"]')) {
                    const btn = e.target.closest('.btn-cantidad');
                    const index = btn.getAttribute('data-index');
                    if (carrito[index].cantidad > 1) {
                        carrito[index].cantidad -= 1;
                    } else {
                        carrito.splice(index, 1);
                    }
                    localStorage.setItem('libertyfin_carrito', JSON.stringify(carrito));
                    renderCarrito();
                    sincronizarCarritoConPHP();
                }

                if (e.target.closest('.remove-item')) {
                    const btn = e.target.closest('.remove-item');
                    const index = btn.getAttribute('data-index');
                    carrito.splice(index, 1);
                    localStorage.setItem('libertyfin_carrito', JSON.stringify(carrito));
                    renderCarrito();
                    sincronizarCarritoConPHP();
                    showNotification('Producto eliminado del carrito', 'success');
                }
            });

            // Botón Pago en Línea
            const pagoEnLineaBtn = document.getElementById('pago-en-linea-btn');
            if (pagoEnLineaBtn) {
                pagoEnLineaBtn.addEventListener('click', function() {
                    const btn = this;
                    const originalText = btn.innerHTML;

                    let totalCarrito = 0;
                    carrito.forEach(item => {
                        totalCarrito += (parseFloat(item.precio) || 0) * (parseInt(item.cantidad) || 1);
                    });

                    if (totalCarrito <= 0) {
                        showNotification('El carrito está vacío', 'error');
                        return;
                    }

                    btn.innerHTML = '<span class="loader"></span> Generando link...';
                    btn.disabled = true;

                    let descripcion = carrito.length === 1 ? carrito[0].producto :
                        (carrito.length > 1 ? carrito[0].producto + ' y más' : 'Pago Libertyfin');

                    const datos = {
                        monto: totalCarrito,
                        descripcion: descripcion,
                        referencia: 'REF-' + Date.now()
                    };

                    fetch('../Service/generar_link_pago_ideas.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(datos)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.location.href = data.url;
                        } else {
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                            showNotification('Error: ' + (data.error || 'Error desconocido'), 'error');
                        }
                    })
                    .catch(error => {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        showNotification('Error de conexión', 'error');
                    });
                });
            }

            // Botón Transferencia SPEI
            const transferenciaBtn = document.getElementById('transferencia-btn');
            if (transferenciaBtn) {
                transferenciaBtn.addEventListener('click', async function() {
                    if (carrito.length === 0) {
                        showNotification('El carrito está vacío', 'error');
                        return;
                    }
                    
                    const modalElement = document.getElementById('modalTransferencia');
                    const modal = new bootstrap.Modal(modalElement);
                    modal.show();
                    
                    const loadingDiv = document.getElementById('transferencia-loading');
                    const contenidoDiv = document.getElementById('transferencia-contenido');
                    loadingDiv.style.display = 'block';
                    contenidoDiv.style.display = 'none';
                    
                    try {
                        const resultado = await generarCLABETransferencia();
                        
                        document.getElementById('clabe-generada').textContent = resultado.clabe;
                        document.getElementById('monto-modal').textContent = '$ ' + formatMoney(resultado.monto);
                        document.getElementById('referencia-modal').textContent = resultado.referencia;
                        document.getElementById('concepto-modal').textContent = resultado.descripcion;
                        
                        const copyBtn = document.getElementById('copiar-clabe');
                        copyBtn.onclick = function() {
                            navigator.clipboard.writeText(resultado.clabe).then(() => {
                                showNotification('CLABE copiada al portapapeles', 'success');
                            }).catch(() => {
                                showNotification('Error al copiar CLABE', 'error');
                            });
                        };
                        
                        localStorage.setItem('pago_spei_pendiente', JSON.stringify({
                            clabe: resultado.clabe,
                            referencia: resultado.referencia,
                            monto: resultado.monto,
                            fecha: new Date().toISOString(),
                            productos: carrito
                        }));
                        
                        loadingDiv.style.display = 'none';
                        contenidoDiv.style.display = 'block';
                        showNotification('CLABE generada exitosamente', 'success');
                        
                    } catch (error) {
                        console.error('Error:', error);
                        loadingDiv.style.display = 'none';
                        contenidoDiv.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Error al generar CLABE: ${error.message}<br>
                                Por favor, intenta de nuevo más tarde o utiliza otro método de pago.
                            </div>
                        `;
                        contenidoDiv.style.display = 'block';
                        showNotification('Error al generar CLABE', 'error');
                    }
                });
            }

            // Botón "Ya realicé el pago"
            const confirmarPagoBtn = document.getElementById('confirmar-pago-btn');
            if (confirmarPagoBtn) {
                confirmarPagoBtn.addEventListener('click', function() {
                    const pagoInfo = localStorage.getItem('pago_spei_pendiente');
                    if (pagoInfo) {
                        sessionStorage.setItem('pago_confirmacion', pagoInfo);
                        window.location.href = 'confirmar-pago-spei.php';
                    } else {
                        showNotification('No hay información de pago pendiente', 'error');
                    }
                });
            }

            // Formulario PayPal
            const paypalForm = document.getElementById('paypal-form');
            if (paypalForm) {
                paypalForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    if (carrito.length === 0) {
                        showNotification('El carrito está vacío', 'error');
                        return;
                    }

                    const btn = document.getElementById('paypal-button');
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<span class="loader"></span> Conectando con PayPal...';
                    btn.disabled = true;

                    let total = 0;
                    carrito.forEach(item => {
                        total += (parseFloat(item.precio) || 0) * (parseInt(item.cantidad) || 1);
                    });

                    const iva = total * 0.16;
                    const totalConIva = total + iva;
                    
                    document.getElementById('total-hidden').value = totalConIva;
                    document.getElementById('carrito-data').value = JSON.stringify(carrito);

                    sincronizarCarritoConPHP().then(() => {
                        setTimeout(() => {
                            this.submit();
                        }, 500);
                    }).catch(error => {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        showNotification('Error al procesar el carrito', 'error');
                    });
                });
            }

            // Inicializar
            renderCarrito();
            sincronizarCarritoConPHP();
        });
    </script>
</body>
</html>