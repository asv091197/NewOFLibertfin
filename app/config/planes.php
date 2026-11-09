<?php
/**
 * Planes que se pueden contratar desde Mi cuenta → Plan.
 *
 *   clave      minúsculas, números, - y _ (se guarda en empresas.plan)
 *   nombre     como lo ve el cliente
 *   precio     por MES, en pesos. El anual se calcula solo con el descuento.
 *   usuarios   cuántos usuarios incluye (informativo)
 *   popular    true marca la tarjeta como "Más popular"
 *   grupos     características, agrupadas por título
 *   modulos    los módulos que incluye (ver ServicioModulos). Sin esta llave,
 *              el plan incluye todos. La prueba gratis siempre los incluye todos.
 *              Inventario y Comisiones van en todos los planes.
 *
 * `descuento_anual` es el % que se descuenta al pagar los 12 meses juntos.
 *
 * `cuenta` son los datos a donde el cliente hace la transferencia: LLÉNALOS
 * antes de dar el acceso a clientes, o la pantalla de pago saldrá sin ellos.
 */
return [
    'descuento_anual' => 20,

    'planes' => [
        'basico' => [
            'nombre'   => 'Básico',
            'precio'   => 299,
            'usuarios' => 1,
            'grupos'   => [
                'Punto de venta' => ['1 caja registradora', '100 productos', 'Pago en efectivo'],
                'Módulos'        => ['Cotizaciones', 'Expediente de clientes'],
            ],
            'modulos'  => ['cotizaciones', 'expediente'],
        ],
        'profesional' => [
            'nombre'   => 'Profesional',
            'precio'   => 599,
            'usuarios' => 4,
            'grupos'   => [
                'Punto de venta' => ['2 cajas registradoras', '500 productos', 'Pago en efectivo'],
                'Módulos'        => ['Todos los de tu giro: agenda, igualas, compras y más'],
            ],
            'modulos'  => ['cotizaciones', 'expediente', 'igualas', 'seguimiento', 'agenda', 'paquetes', 'compras'],
        ],
        'empresarial' => [
            'nombre'   => 'Empresarial',
            'precio'   => 999,
            'usuarios' => 6,
            'popular'  => true,
            'grupos'   => [
                'Punto de venta' => ['3 cajas registradoras', '1 sucursal', '500 productos'],
                'Pagos'          => ['Pasarela de pago', 'SPEI'],
                'Módulos'        => ['Todos los de tu giro'],
            ],
            'modulos'  => ['cotizaciones', 'expediente', 'igualas', 'seguimiento', 'agenda', 'paquetes', 'compras'],
        ],
        'empresarial-plus' => [
            'nombre'   => 'Empresarial Plus',
            'precio'   => 1499,
            'usuarios' => 10,
            'grupos'   => [
                'Punto de venta' => ['10 cajas registradoras', '3 sucursales', 'Productos ilimitados'],
                'Pagos'          => ['Pasarela de pago', 'SPEI', 'Tarjeta de crédito'],
                'Facturación'    => ['500 CFDI / Timbres'],
                'Módulos'        => ['Todos los de tu giro'],
            ],
            'modulos'  => ['cotizaciones', 'expediente', 'igualas', 'seguimiento', 'agenda', 'paquetes', 'compras'],
        ],
    ],

    'cuenta' => [
        'banco'   => '',
        'titular' => '',
        'clabe'   => '',
    ],
];
