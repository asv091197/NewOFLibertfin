<?php
// sincronizar-carrito.php
session_start();

// Permitir CORS si es necesario
header('Content-Type: application/json');

// Recibir datos JSON
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (isset($data['carrito']) && is_array($data['carrito'])) {
    // Limpiar y validar datos
    $carritoLimpio = [];
    foreach ($data['carrito'] as $item) {
        if (isset($item['producto'], $item['precio'], $item['cantidad'])) {
            $carritoLimpio[] = [
                'producto' => strip_tags($item['producto']),
                'precio' => floatval($item['precio']),
                'descripcion' => isset($item['descripcion']) ? strip_tags($item['descripcion']) : '',
                'cantidad' => intval($item['cantidad']),
                'sku' => isset($item['sku']) ? strip_tags($item['sku']) : 'PLAN-' . uniqid()
            ];
        }
    }
    
    $_SESSION['carrito'] = $carritoLimpio;
    echo json_encode([
        'success' => true, 
        'message' => 'Carrito sincronizado',
        'count' => count($carritoLimpio)
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Datos inválidos'
    ]);
}
?>