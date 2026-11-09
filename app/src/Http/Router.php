<?php
namespace LibertyFin\Http;

/**
 * Router mínimo con parámetros.
 *   /ventas/{id}  ->  ['Controlador', 'metodo']  y el id llega como argumento.
 *
 * Los parámetros solo aceptan dígitos: así un id no puede traer texto
 * que termine en una consulta.
 */
final class Router
{
    private $rutas = [];

    public function get($ruta, array $destino)  { $this->agregar('GET',  $ruta, $destino); }
    public function post($ruta, array $destino) { $this->agregar('POST', $ruta, $destino); }

    /**
     * DELETE existe por los avisos de Paga de Todo.
     *
     * La cancelación de un pago llega por DELETE o por POST «según la
     * configuración que haya definido el Emisor», dice su documentación.
     * Como el proveedor elige cuál, hay que atender los dos: si solo se
     * responde a uno, el día que cambien la casilla en su panel dejamos
     * de cancelar pagos sin que nadie toque una línea de código.
     */
    public function delete($ruta, array $destino) { $this->agregar('DELETE', $ruta, $destino); }

    private function agregar($metodo, $ruta, array $destino)
    {
        $nombres = [];
        $patron = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$nombres) {
            $nombres[] = $m[1];
            // `{k}` es el secreto de los avisos de Paga de Todo
            // (/pagadetodo/{k}/pago-referencia). Se genera con bin2hex,
            // así que trae letras: con solo dígitos la ruta nunca
            // coincidía, el aviso recibía un 404 y el proveedor revertía
            // el cobro. Es el único parámetro que no es un id.
            return $m[1] === 'k' ? '([A-Za-z0-9_-]+)' : '(\d+)';
        }, $ruta);
        $this->rutas[] = [
            'metodo'  => $metodo,
            'patron'  => '#^' . str_replace('/', '\/', $patron) . '$#',
            'nombres' => $nombres,
            'destino' => $destino,
            'ruta'    => $ruta,
        ];
    }

    /** @return array|null  ['destino' => [...], 'args' => [...]] */
    public function despachar($metodo, $uri)
    {
        $ruta = parse_url($uri, PHP_URL_PATH);
        $ruta = '/' . trim((string)$ruta, '/');
        foreach ($this->rutas as $r) {
            if ($r['metodo'] !== $metodo) continue;
            if (preg_match($r['patron'], $ruta, $m)) {
                array_shift($m);
                return ['destino' => $r['destino'],
                        'args'    => array_map(function ($v) {
                            return ctype_digit($v) ? (int)$v : $v;
                        }, $m),
                        'patron'  => $r['ruta']];
            }
        }
        return null;
    }
}
