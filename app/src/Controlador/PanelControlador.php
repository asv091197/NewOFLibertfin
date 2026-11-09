<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\PanelRepo;
use LibertyFin\Datos\VentaRepo;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

final class PanelControlador
{
    public function index()
    {
        // Primer ingreso del administrador de una empresa nueva: antes del
        // Panel, las preguntas de la configuración inicial.
        if (!empty($_SESSION['lf_bienvenida'])) { header('Location: /bienvenida'); exit; }
        $db    = Conexion::de($_SESSION['empresa_db']);
        // Las igualas del mes, si ya toca (una vez al día; ver Servicio\Igualas).
        \LibertyFin\Servicio\Igualas::alDia($db);
        $panel = new PanelRepo($db);
        $ventas= new VentaRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        $resumen = $ventas->resumen($desde, $hasta);
        $meses   = $panel->porMes(7);
        $hoy     = $panel->hoy();
        $prom    = $panel->promedioDiario(30);
        $viejo   = $panel->saldoViejo(30);
        $comis   = $panel->comisiones($desde, $hasta);
        $movs    = $panel->movimientos(6);

        $cobrado = (float)($resumen['cobrado'] ?? 0);
        $vendido = (float)($resumen['vendido'] ?? 0);

        Plantilla::pagina('panel/index', [
            'titulo'    => 'Panel',
            'icono'     => 'panel',
            'subtitulo' => Fechas::rotulo($desde, $hasta),
            'saludo'    => self::saludo(),
            'resumen'   => $resumen,
            // Lo que ENTRÓ en el periodo, igual que «Entraron» de Reportes:
            // incluye lo que llegó de ventas anteriores. `cobrado` (arriba)
            // es solo lo de las ventas del periodo y sirve para el avance.
            'entro'     => (new \LibertyFin\Datos\ReporteRepo($db))->entro($desde, $hasta),
            'avance'    => Dinero::pct($cobrado, $vendido),
            'alCorriente' => self::alCorriente($cobrado, $vendido, $viejo),
            'sintesis'  => self::sintesis($cobrado, $vendido, $resumen, $viejo),
            'meses'     => $meses,
            'hoy'       => $hoy,
            'promedio'  => $prom,
            'viejo'     => $viejo,
            'comisiones'=> $comis,
            'movimientos'=> $movs,
            'faltantes' => self::faltantes($db),
            'desde'     => $desde,
            'hasta'     => $hasta,
        ]);
    }

    /**
     * Cuántos productos se acabaron o están bajo su mínimo, en la sucursal
     * de quien entra. Null si Inventario está apagado o no le toca verlo.
     */
    private static function faltantes($db)
    {
        if (!\LibertyFin\Dominio\Permisos::puede('ver.inventario')) return null;
        try {
            $inv = new \LibertyFin\Servicio\Inventario($db);
            if (!$inv->activo()) return null;
            return $inv->resumen($inv->sucursalActual());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Qué tan al corriente está la cobranza, de 0 a 100.
     *
     * Antes se llamaba "salud" y nadie entendía qué medía. Es esto:
     * el porcentaje cobrado, castigado por la parte del saldo que lleva
     * más de 30 días sin un abono.
     *
     *     al_corriente = %cobrado x (1 - rancio x 0.35)
     *
     * Dos empresas al 70% cobrado no están igual: si a una el saldo se le
     * está añejando y a la otra no, la primera está peor. El 0.35 es una
     * decisión, no una verdad: castiga el saldo viejo sin que domine.
     */
    private static function alCorriente($cobrado, $vendido, array $viejo)
    {
        if ($vendido <= 0) return 0;
        $pctCobrado = min(100, ($cobrado / $vendido) * 100);
        $saldo      = max(0, $vendido - $cobrado);
        $rancio     = $saldo > 0 ? min(1, (float)$viejo['monto'] / $saldo) : 0;
        return (int)round($pctCobrado * (1 - $rancio * 0.35));
    }

    /** La frase del encabezado. Dice qué pasa, no adjetivos. */
    private static function sintesis($cobrado, $vendido, array $r, array $viejo)
    {
        $pct   = Dinero::pct($cobrado, $vendido);
        $saldo = max(0, $vendido - $cobrado);
        if ($vendido <= 0) {
            return ['tono' => 'Sin ventas en el periodo', 'detalle' => 'Nada que cobrar todavía.'];
        }
        if ($pct >= 90)      $tono = 'va muy bien';
        elseif ($pct >= 70)  $tono = 'va por buen camino';
        elseif ($pct >= 45)  $tono = 'va a medias';
        else                 $tono = 'está atrasada';

        $det = 'Quedan ' . Dinero::pesos($saldo) . ' abiertos en '
             . (int)($r['con_saldo'] ?? 0) . ' ventas.';
        if ((float)$viejo['monto'] > 0) {
            $det .= ' De eso, ' . Dinero::pesos($viejo['monto']) . ' en '
                 . (int)$viejo['ventas'] . ' ventas lleva más de 30 días sin movimiento.';
        }
        return ['tono' => $tono, 'detalle' => $det, 'pct' => $pct];
    }

    private static function saludo()
    {
        $h = (int)date('G');
        $m = $h < 12 ? 'Buenos días' : ($h < 19 ? 'Buenas tardes' : 'Buenas noches');
        $n = $_SESSION['usuario_nombre'] ?? '';
        $n = $n ? ', ' . explode(' ', trim($n))[0] : '';
        return $m . $n;
    }

}
