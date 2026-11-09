<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;

/**
 * Estado de la suscripción: el aviso que sale en todo el sistema y el
 * bloqueo cuando ya venció.
 *
 * El vencimiento vive en la base PRINCIPAL y se pregunta en cada pantalla.
 * Se guarda en la sesión y se vuelve a leer cada diez minutos: es un dato
 * que cambia una vez al año y no vale una consulta por página.
 *
 * VENCIDA es la excepción: mientras esté vencida se vuelve a leer cada
 * minuto, para que en cuanto se apruebe un pago el acceso regrese solo,
 * sin pedir que cierren sesión y vuelvan a entrar.
 */
final class Suscripcion
{
    /** Desde cuántos días antes de vencer se empieza a avisar. */
    const AVISAR_DESDE = 15;
    const REVISAR_CADA = 600;   // segundos
    const REVISAR_VENCIDA = 60; // segundos

    /**
     * Días que faltan (negativo si ya venció) y fecha, o null si no hay
     * dato o esta sesión no es de una empresa.
     * @return array|null ['dias' => int, 'fecha' => 'd/m/Y']
     */
    public static function estado()
    {
        if (empty($_SESSION['empresa_id'])) return null;

        $c = $_SESSION['lf_suscr'] ?? null;
        $vencidaAntes = $c && !empty($c['venc']) && strtotime($c['venc']) < strtotime('today');
        $cada = $vencidaAntes ? self::REVISAR_VENCIDA : self::REVISAR_CADA;

        if (!$c || (time() - (int)($c['t'] ?? 0)) > $cada) {
            $c = ['t' => time(), 'venc' => null];
            try {
                $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
                $st = $principal->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = ?");
                $st->execute([(int)$_SESSION['empresa_id']]);
                $v = $st->fetchColumn();
                $c['venc'] = $v ?: null;
            } catch (\Throwable $e) {
                // Sin el dato no se avisa ni se bloquea: equivocarse aquí
                // sacaría a una empresa al corriente de su propio sistema.
                error_log('[LibertyFin] suscripcion: ' . $e->getMessage());
            }
            $_SESSION['lf_suscr'] = $c;
        }
        if (empty($c['venc'])) return null;

        $ts = strtotime($c['venc']);
        if (!$ts) return null;
        return ['dias' => (int)floor(($ts - strtotime('today')) / 86400),
                'fecha' => date('d/m/Y', $ts)];
    }

    /** ¿Ya venció? Aplica a cualquier rol de empresa; no a los de plataforma. */
    public static function vencida()
    {
        if (Permisos::esPlataforma($_SESSION['usuario_rol'] ?? '')) return false;
        $e = self::estado();
        return $e !== null && $e['dias'] < 0;
    }

    /**
     * El aviso de "vence pronto", solo para quien puede pagar. Un cajero
     * con el aviso no puede actuar y solo se preocupa.
     * @return array|null
     */
    public static function aviso()
    {
        if (!Permisos::puede('editar.empresa')) return null;
        $e = self::estado();
        return ($e !== null && $e['dias'] <= self::AVISAR_DESDE) ? $e : null;
    }
}
