<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\ConfigRepo;

/**
 * Los módulos: lo que se enciende según el giro, encima del núcleo común.
 *
 * EL NÚCLEO Y LOS MÓDULOS
 *
 * Caja, Ventas, Clientes, Cobranza, Corte, Gastos, Productos y Reportes los
 * tiene toda empresa. Lo que sigue depende del negocio: una tienda lleva
 * inventario y compras; un despacho, cotizaciones e igualas; un spa, agenda
 * y paquetes. La configuración inicial los enciende según el giro, y el
 * administrador los cambia en Ajustes → Módulos.
 *
 * QUÉ MÓDULOS INCLUYE CADA PLAN
 *
 * Lo dice `config/planes.php` (`modulos` en cada plan), no el código: los
 * precios y lo que entra en cada uno se cambian ahí. Un plan sin esa lista
 * incluye todos (así se comportaba todo antes), y la prueba gratis también:
 * es para probar. Un módulo que el plan no incluye se ve con candado.
 *
 * CÓMO SE GUARDA
 *
 * `modulo.<clave>` = si | no en lf_ajustes. Inventario y Comisiones ya
 * tenían su propia llave (`inventario.activo`, `seccion.comisiones`): se
 * respetan para no tener dos interruptores para lo mismo.
 */
final class Modulos
{
    /**
     *   giros     los que lo traen encendido en la configuración inicial
     *   requiere  otro módulo sin el cual no tiene sentido
     *   ruta      su sección del menú (null si vive dentro de otra)
     */
    const CATALOGO = [
        'inventario'   => ['nombre' => 'Inventario', 'grupo' => 'Comercio', 'icono' => 'bolsa', 'ruta' => '/inventario',
                           'desc' => 'Existencias por sucursal. Caja las descuenta al cobrar y te avisa cuando algo se acaba.',
                           'giros' => ['tienda']],
        'compras'      => ['nombre' => 'Compras a proveedor', 'grupo' => 'Comercio', 'icono' => 'camion', 'ruta' => '/compras',
                           'desc' => 'Haces el pedido, lo recibes y la mercancía entra sola al inventario.',
                           'giros' => ['tienda'], 'requiere' => 'inventario'],
        'envios'       => ['nombre' => 'Envíos a domicilio', 'grupo' => 'Comercio', 'icono' => 'camion', 'ruta' => '/envios',
                           'desc' => 'Cotizas con DHL, FedEx, Estafeta y las demás, imprimes la guía y rastreas el paquete, sin salir de la venta.',
                           'giros' => []],
        'mercadolibre' => ['nombre' => 'Mercado Libre', 'grupo' => 'Comercio', 'icono' => 'bolsa', 'ruta' => '/mercadolibre',
                           'desc' => 'Conecta tu cuenta de vendedor: lo que vendes allá se descuenta de tus existencias, y lo que vendes aquí se actualiza allá.',
                           'giros' => [], 'requiere' => 'inventario'],
        'tiendanube'   => ['nombre' => 'Tienda Nube', 'grupo' => 'Comercio', 'icono' => 'bolsa', 'ruta' => '/tiendanube',
                           'desc' => 'Conecta tu tienda en línea: lo que vendes allá se descuenta de tus existencias, y lo que vendes aquí se actualiza allá.',
                           'giros' => [], 'requiere' => 'inventario'],
        'cotizaciones' => ['nombre' => 'Cotizaciones', 'grupo' => 'Servicios', 'icono' => 'doc', 'ruta' => '/cotizaciones',
                           'desc' => 'Presupuestos que imprimes o mandas, y que se vuelven venta con un clic.',
                           'giros' => ['profesional']],
        'igualas'      => ['nombre' => 'Igualas', 'grupo' => 'Servicios', 'icono' => 'repetir', 'ruta' => '/igualas',
                           'desc' => 'Cobros que se repiten cada mes: la venta se genera sola y entra a Cobranza.',
                           'giros' => ['profesional']],
        'seguimiento'  => ['nombre' => 'Seguimiento de trabajos', 'grupo' => 'Servicios', 'icono' => 'tablero', 'ruta' => '/seguimiento',
                           'desc' => 'Cada venta con su estado (pendiente, en proceso, entregado) y quién la lleva.',
                           'giros' => ['profesional']],
        'expediente'   => ['nombre' => 'Expediente de clientes', 'grupo' => 'Servicios', 'icono' => 'cliente', 'ruta' => null,
                           'desc' => 'Notas, documentos y datos importantes de cada cliente, en su ficha.',
                           'giros' => ['profesional', 'belleza']],
        'agenda'       => ['nombre' => 'Agenda de citas', 'grupo' => 'Salud y belleza', 'icono' => 'agenda', 'ruta' => '/agenda',
                           'desc' => 'Citas por especialista con la duración de cada servicio. Se cobran en Caja.',
                           'giros' => ['belleza']],
        'paquetes'     => ['nombre' => 'Paquetes de sesiones', 'grupo' => 'Salud y belleza', 'icono' => 'paquete', 'ruta' => null,
                           'desc' => 'Vende 10 sesiones y se descuentan solas cada vez que el cliente viene.',
                           'giros' => ['belleza']],
        'comisiones'   => ['nombre' => 'Comisiones', 'grupo' => 'Equipo', 'icono' => 'comi', 'ruta' => '/comisiones',
                           'desc' => 'Lo que le toca a cada colaborador, calculado sobre lo cobrado.',
                           'giros' => ['profesional', 'belleza']],
    ];

    /** Los que tienen su propia llave desde antes. Y los que van en todo plan. */
    const LLAVE_PROPIA = ['inventario' => ['inventario.activo', 'si', 'no'], 'comisiones' => ['seccion.comisiones', '1', '0']];

    /**
     * Los que no dependen del plan.
     *
     * `envios` entra aquí porque la lista de módulos de cada plan vive
     * en `config/planes.php`, y ese archivo no se toca desde el código:
     * trae los precios de cada quien. Un módulo nuevo que no esté en esa
     * lista sale con candado hasta en el plan más caro, que es justo lo
     * contrario de lo que se quería.
     *
     * Cuando se decida en qué planes entra, se agrega a `modulos` en
     * `config/planes.php` y se quita de aquí.
     */
     const EN_TODO_PLAN = ['inventario', 'comisiones', 'envios', 'mercadolibre', 'tiendanube'];

    private $cfg;
    private static $plan = null;   // [clave_plan => modulos|null] por petición

    public function __construct($cfg) { $this->cfg = $cfg; }

    /** El de la empresa de la sesión. */
    public static function deSesion()
    {
        return new self(new ConfigRepo(\LibertyFin\Datos\Conexion::de($_SESSION['empresa_db'])));
    }

    /** ¿Lo encendió la empresa? (Sin mirar el plan.) */
    public function encendido($k)
    {
        if (!isset(self::CATALOGO[$k])) return false;
        if (isset(self::LLAVE_PROPIA[$k])) {
            list($llave, $si) = self::LLAVE_PROPIA[$k];
            if ($k === 'comisiones') return $this->cfg->seccionActiva('comisiones');
            return (string)$this->cfg->valorDe($llave, '') === $si;
        }
        return (string)$this->cfg->valorDe('modulo.' . $k, 'no') === 'si';
    }

    /** ¿Se puede usar? Encendido, incluido en el plan y con lo que requiere. */
    public function activo($k)
    {
        if (!$this->encendido($k) || !self::enPlan($k)) return false;
        $req = self::CATALOGO[$k]['requiere'] ?? null;
        return !$req || $this->activo($req);
    }

    /** Los que se pueden usar: [clave => true]. */
    public function activos()
    {
        $r = [];
        foreach (self::CATALOGO as $k => $_) if ($this->activo($k)) $r[$k] = true;
        return $r;
    }

    /**
     * Enciende o apaga. Encender uno que requiere otro enciende también ese;
     * apagar uno apaga los que dependen de él.
     * @return array las claves que cambiaron
     */
    public function poner($k, $on)
    {
        if (!isset(self::CATALOGO[$k])) throw new \InvalidArgumentException('Ese módulo no existe.');
        if ($on && !self::enPlan($k)) {
            throw new \InvalidArgumentException('Tu plan no incluye ' . self::CATALOGO[$k]['nombre'] . '.');
        }
        $cambios = [];
        $this->escribir($k, $on, $cambios);
        if ($on && ($req = self::CATALOGO[$k]['requiere'] ?? null) && !$this->encendido($req)) {
            $this->escribir($req, true, $cambios);
        }
        if (!$on) {
            foreach (self::CATALOGO as $otro => $m) {
                if (($m['requiere'] ?? null) === $k && $this->encendido($otro)) $this->escribir($otro, false, $cambios);
            }
        }
        return $cambios;
    }

    private function escribir($k, $on, array &$cambios)
    {
        if ($this->encendido($k) === (bool)$on) return;
        if (isset(self::LLAVE_PROPIA[$k])) {
            list($llave, $si, $no) = self::LLAVE_PROPIA[$k];
            $this->cfg->guardar($llave, $on ? $si : $no);
        } else {
            $this->cfg->guardar('modulo.' . $k, $on ? 'si' : 'no');
            // Desde cuándo: el seguimiento no debe llenarse con todo el historial.
            if ($on && (string)$this->cfg->valorDe('modulo.' . $k . '.desde', '') === '') {
                $this->cfg->guardar('modulo.' . $k . '.desde', date('Y-m-d'));
            }
        }
        $cambios[] = $k;
    }

    /** Desde cuándo está encendido (Y-m-d), o hoy si no se sabe. */
    public function desde($k)
    {
        return (string)$this->cfg->valorDe('modulo.' . $k . '.desde', '') ?: date('Y-m-d');
    }

    // ════════════════════════════════════════════════════════
    //  EL PLAN
    // ════════════════════════════════════════════════════════

    /** ¿El plan de la empresa incluye este módulo? */
    public static function enPlan($k, $plan = null)
    {
        if (in_array($k, self::EN_TODO_PLAN, true)) return true;
        $plan = strtolower((string)($plan ?? ($_SESSION['empresa_plan'] ?? 'prueba')));
        if ($plan === '' || $plan === 'prueba') return true;          // la prueba es para probar
        $lista = self::listaDelPlan($plan);
        return $lista === null || in_array($k, $lista, true);         // sin lista: todos
    }

    /** Los planes (nombres) que sí lo incluyen, para el candado. */
    public static function planesCon($k)
    {
        $c = \LibertyFin\Datos\PlanRepo::catalogo();
        $r = [];
        foreach (($c['planes'] ?? []) as $p) {
            if (!isset($p['modulos']) || in_array($k, (array)$p['modulos'], true)) $r[] = $p['nombre'];
        }
        return $r;
    }

    /** null = el plan no limita (o no existe en el catálogo). */
    private static function listaDelPlan($plan)
    {
        if (self::$plan === null) {
            self::$plan = [];
            $c = \LibertyFin\Datos\PlanRepo::catalogo();
            foreach (($c['planes'] ?? []) as $clave => $p) {
                self::$plan[$clave] = isset($p['modulos']) ? (array)$p['modulos'] : null;
            }
        }
        return self::$plan[$plan] ?? null;
    }

    /**
     * El candado de una página de módulo: si no está activo, la página
     * dice por qué en vez de un 404 sin explicación. Devuelve true si pasa.
     */
    public static function exigir($k)
    {
        try {
            $m = self::deSesion();
            if ($m->activo($k)) return true;
        } catch (\Throwable $e) { /* sin base al día: apagado */ }
        \LibertyFin\Vista\Plantilla::pagina('parciales/modulo-apagado', [
            'titulo' => self::CATALOGO[$k]['nombre'] ?? 'Módulo', 'icono' => self::CATALOGO[$k]['icono'] ?? 'serv',
            'subtitulo' => 'Módulo apagado', 'modulo' => $k,
            'enPlan' => self::enPlan($k), 'planes' => self::planesCon($k),
        ]);
        return false;
    }
}