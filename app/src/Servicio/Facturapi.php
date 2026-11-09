<?php
namespace LibertyFin\Servicio;

/**
 * Cliente HTTP de Facturapi.io (el PAC).
 *
 * Facturapi no tiene endpoint separado para sandbox: el modo lo determina
 * la clave (sk_test_... vs sk_live_...). Por eso aquí solo se guarda la
 * clave y el host es siempre el mismo.
 *
 * CURLOPT_SSL_VERIFYPEER va en true. Si el bundle de CA del servidor está
 * viejo esto va a fallar con un error opaco — probar contra una factura
 * real en sandbox antes de confiar en producción.
 */
final class Facturapi
{
    private string $base = 'https://www.facturapi.io/v2/';
    private string $clave;

    public function __construct(string $clave)
    {
        $this->clave = $clave;
    }

    /**
     * @return array{body:string, json:?array, http:int, error:string}
     */
    public function peticion(string $ruta, string $metodo = 'GET', ?array $payload = null): array
    {
        $ch = curl_init($this->base . $ruta);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->clave],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            // /xml y /pdf responden con redirect al binario real.
            CURLOPT_FOLLOWLOCATION => true,
        ];
        if ($metodo === 'POST') {
            $opts[CURLOPT_POST]         = true;
            $opts[CURLOPT_POSTFIELDS]   = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);
        $body  = curl_exec($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);

        return [
            'body'  => (string) $body,
            'json'  => json_decode((string) $body, true) ?: null,
            'http'  => $http,
            'error' => $error,
        ];
    }

    /**
     * El cliente con la llave de config/integraciones.php (la de prueba en
     * sandbox), o null si no hay llave.
     */
    public static function deConfig(): ?self
    {
        $fac = Integraciones::de('facturapi');
        $clave = !empty($fac['sandbox']) ? trim((string) ($fac['llave_prueba'] ?? '')) : '';
        if ($clave === '') $clave = trim((string) ($fac['llave'] ?? ''));
        return $clave === '' ? null : new self($clave);
    }

    /**
     * Busca en el catálogo del SAT (c_ClaveProdServ) por descripción.
     *
     * @return array<array{clave:string, descripcion:string}>
     */
    public function buscarClavesProducto(string $q, int $limite = 10): array
    {
        $res = $this->peticion('catalogs/products?' . http_build_query(['q' => $q, 'limit' => $limite]));
        if ($res['error'] !== '' || $res['http'] !== 200) {
            throw new \RuntimeException($res['error'] ?: 'HTTP ' . $res['http'] . ': ' . mb_substr($res['body'], 0, 200));
        }
        $r = [];
        foreach ($res['json']['data'] ?? [] as $c) {
            $r[] = ['clave' => (string) ($c['key'] ?? ''), 'descripcion' => (string) ($c['description'] ?? '')];
        }
        return $r;
    }

    public function crearFactura(array $payload): array
    {
        return $this->peticion('invoices', 'POST', $payload);
    }

        /**
     * Descarga el XML o PDF de una factura ya timbrada.
     *
     * Facturapi responde con un 302 al binario real; el FOLLOWLOCATION
     * que ya está puesto en peticion() lo sigue solo. El `body` que
     * devuelve es el binario, no JSON.
     *
     * @return array{body:string, json:?array, http:int, error:string}
     */
    public function descargarFactura(string $facturapiId, string $tipo): array
    {
        $tipo = $tipo === 'xml' ? 'xml' : 'pdf';
        return $this->peticion("invoices/{$facturapiId}/{$tipo}", 'GET');
    }
}