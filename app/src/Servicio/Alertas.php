<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\ConfigRepo;
use PDO;

/**
 * Lo que hay que avisar del inventario: se acabó, queda poco, caduca.
 *
 * POR QUÉ NO ES SOLO UNA CONSULTA
 *
 * El aviso sale en el encabezado de TODA la aplicación, así que se
 * calcula en cada carga de página. Una consulta pesada ahí se paga
 * cincuenta veces al día por cada persona que usa el sistema. Por eso es
 * UNA consulta agregada —no una por tipo de aviso— y el resultado se
 * guarda unos minutos en la sesión.
 *
 * TRES NIVELES, Y EL DE EN MEDIO ES EL QUE IMPORTA
 *
 *   no           no avisa. Una empresa que compra bajo pedido no tiene
 *                nada que avisar, y un aviso que no aplica enseña a
 *                ignorar los que sí.
 *   avisar       se puede cerrar y no vuelve en toda la sesión. Para una
 *                tienda donde siempre falta algo, un aviso que no se
 *                puede quitar se vuelve paisaje en dos días: se deja de
 *                leer, y entonces ya no avisa de nada.
 *   obligatoria  no se cierra hasta que el problema se resuelva.
 *
 * El caso que el nivel «obligatoria» atiende es el del producto que no
 * puede faltar. El que «avisar» atiende es todo lo demás.
 */
final class Alertas
{
    /** Cuánto vale el resultado antes de volver a preguntarle a la base. */
    const SEGUNDOS_CACHE = 180;

    private $db;
    private $cfg;

    public function __construct(PDO $db, $cfg = null)
    {
        $this->db  = $db;
        $this->cfg = is_array($cfg) ? $cfg : (new ConfigRepo($db))->inventario();
    }

    public function nivelStock()     { return (string)($this->cfg['alerta_stock'] ?? 'avisar'); }
    public function nivelCaducidad() { return (string)($this->cfg['alerta_caducidad'] ?? 'avisar'); }
    public function diasCaducidad()  { return (int)($this->cfg['dias_caducidad'] ?? ConfigRepo::DIAS_CADUCIDAD); }

    /**
     * Los números del aviso, en UNA consulta.
     *
     * @return array ['agotados','bajos','caducados','por_caducar','dias']
     */
    public function contar($sucursalId)
    {
        $suc  = (int)$sucursalId;
        $dias = $this->diasCaducidad();

        // `dias_alerta_caducidad` del producto manda sobre el de Ajustes:
        // la leche avisa con 3 días y el aceite con 60, y un solo número
        // para los dos no sirve a ninguno.
        $f = $this->uno("
            SELECT
              SUM(CASE WHEN COALESCE(ps.stock,0) <= 0 THEN 1 ELSE 0 END) AS agotados,
              SUM(CASE WHEN COALESCE(ps.stock,0) > 0
                        AND COALESCE(ps.stock_minimo,0) > 0
                        AND ps.stock <= ps.stock_minimo THEN 1 ELSE 0 END) AS bajos,
              SUM(CASE WHEN p.es_perecedero = 1 AND p.fecha_caducidad IS NOT NULL
                        AND p.fecha_caducidad < CURDATE()
                        AND COALESCE(ps.stock,0) > 0 THEN 1 ELSE 0 END) AS caducados,
              SUM(CASE WHEN p.es_perecedero = 1 AND p.fecha_caducidad IS NOT NULL
                        AND p.fecha_caducidad >= CURDATE()
                        AND p.fecha_caducidad <= DATE_ADD(CURDATE(),
                              INTERVAL COALESCE(p.dias_alerta_caducidad, ?) DAY)
                        AND COALESCE(ps.stock,0) > 0 THEN 1 ELSE 0 END) AS por_caducar
            FROM productos p
            LEFT JOIN producto_sucursal ps ON ps.producto_id = p.id AND ps.sucursal_id = ?
            WHERE p.activo = 1 AND p.lleva_inventario = 1", [$dias, $suc]);

        return ['agotados'    => (int)($f['agotados'] ?? 0),
                'bajos'       => (int)($f['bajos'] ?? 0),
                'caducados'   => (int)($f['caducados'] ?? 0),
                'por_caducar' => (int)($f['por_caducar'] ?? 0),
                'dias'        => $dias];
    }

    /** Los productos de cada tipo de aviso, para la pantalla de Inventario. */
    public function detalle($sucursalId, $tope = 100)
    {
        $suc  = (int)$sucursalId;
        $dias = $this->diasCaducidad();
        return $this->todos("
            SELECT p.id, p.codigo, p.nombre, p.unidad_medida, p.fecha_caducidad,
                   COALESCE(ps.stock,0) AS existencia, COALESCE(ps.stock_minimo,0) AS minimo,
                   DATEDIFF(p.fecha_caducidad, CURDATE()) AS dias_para_caducar,
                   CASE
                     WHEN p.es_perecedero = 1 AND p.fecha_caducidad < CURDATE()
                          AND COALESCE(ps.stock,0) > 0 THEN 'caducado'
                     WHEN COALESCE(ps.stock,0) <= 0 THEN 'agotado'
                     WHEN p.es_perecedero = 1 AND p.fecha_caducidad IS NOT NULL
                          AND p.fecha_caducidad <= DATE_ADD(CURDATE(),
                                INTERVAL COALESCE(p.dias_alerta_caducidad, ?) DAY)
                          AND COALESCE(ps.stock,0) > 0 THEN 'por_caducar'
                     ELSE 'bajo'
                   END AS motivo
              FROM productos p
              LEFT JOIN producto_sucursal ps ON ps.producto_id = p.id AND ps.sucursal_id = ?
             WHERE p.activo = 1 AND p.lleva_inventario = 1
               AND (
                 COALESCE(ps.stock,0) <= 0
                 OR (COALESCE(ps.stock_minimo,0) > 0 AND COALESCE(ps.stock,0) <= ps.stock_minimo)
                 OR (p.es_perecedero = 1 AND p.fecha_caducidad IS NOT NULL
                     AND p.fecha_caducidad <= DATE_ADD(CURDATE(),
                           INTERVAL COALESCE(p.dias_alerta_caducidad, ?) DAY)
                     AND COALESCE(ps.stock,0) > 0)
               )
             ORDER BY FIELD(motivo,'caducado','agotado','por_caducar','bajo'), p.nombre
             LIMIT " . max(1, min(500, (int)$tope)), [$dias, $suc, $dias]);
    }

    /**
     * El aviso de arriba, listo para pintar — o null si no hay nada que
     * decir, los dos avisos están apagados, o ya lo cerraron.
     *
     * @return array|null ['texto','detalle','tipo','fija','url','huella']
     */
    public function aviso($sucursalId)
    {
        $nStock = $this->nivelStock();
        $nCad   = $this->nivelCaducidad();
        if ($nStock === 'no' && $nCad === 'no') return null;

        $c = $this->contarConCache($sucursalId);

        $stock = ($nStock !== 'no') ? $c['agotados'] + $c['bajos'] : 0;
        $cad   = ($nCad !== 'no')   ? $c['caducados'] + $c['por_caducar'] : 0;
        if ($stock === 0 && $cad === 0) return null;

        // Fija si CUALQUIERA de las dos que aplican es obligatoria.
        $fija = ($nStock === 'obligatoria' && $stock > 0)
             || ($nCad   === 'obligatoria' && $cad > 0);

        // La huella cambia cuando cambian los números: un aviso cerrado
        // vuelve a salir si la situación empeoró. Sin esto, cerrar «falta
        // 1 producto» escondería también «faltan 12» media hora después.
        $huella = md5(implode('|', [$sucursalId, $c['agotados'], $c['bajos'],
                                    $c['caducados'], $c['por_caducar']]));
        if (!$fija && ($_SESSION['lf_alerta_cerrada'] ?? '') === $huella) return null;

        $partes = [];
        if ($nStock !== 'no') {
            if ($c['agotados']) $partes[] = $c['agotados'] . ($c['agotados'] === 1 ? ' producto agotado' : ' productos agotados');
            if ($c['bajos'])    $partes[] = $c['bajos'] . ($c['bajos'] === 1 ? ' por acabarse' : ' por acabarse');
        }
        if ($nCad !== 'no') {
            if ($c['caducados'])   $partes[] = $c['caducados'] . ($c['caducados'] === 1 ? ' ya caducó' : ' ya caducaron');
            if ($c['por_caducar']) $partes[] = $c['por_caducar'] . ' por caducar';
        }

        // Rojo si hay algo que ya pasó —agotado o caducado—; amarillo si
        // solo es que se está acabando, que todavía da tiempo.
        $grave = ($nStock !== 'no' && $c['agotados'] > 0) || ($nCad !== 'no' && $c['caducados'] > 0);

        return [
            'texto'   => implode(' · ', $partes),
            'detalle' => $grave ? 'Revísalo antes de que te deje sin vender.' : 'Conviene pedir o mover mercancía.',
            'tipo'    => $grave ? 'danger' : 'warning',
            'fija'    => $fija,
            'url'     => '/inventario?t=alertas&s=' . (int)$sucursalId,
            'huella'  => $huella,
        ];
    }

    /**
     * Lo mismo que contar(), pero sin volver a la base en cada página.
     *
     * Tres minutos: suficiente para que una entrada registrada se vea
     * casi enseguida, y suficiente para no consultar cincuenta veces en
     * una tarde de trabajo normal.
     */
    private function contarConCache($sucursalId)
    {
        $llave = 'lf_alertas_' . (int)$sucursalId;
        $g = $_SESSION[$llave] ?? null;
        if (is_array($g) && (time() - ($g['en'] ?? 0)) < self::SEGUNDOS_CACHE) return $g['datos'];

        $datos = $this->contar($sucursalId);
        $_SESSION[$llave] = ['en' => time(), 'datos' => $datos];
        return $datos;
    }

    /** Se olvida lo guardado: lo llama quien mueve existencias. */
    public static function olvidar($sucursalId = null)
    {
        if ($sucursalId !== null) { unset($_SESSION['lf_alertas_' . (int)$sucursalId]); return; }
        foreach (array_keys($_SESSION) as $k) {
            if (strncmp((string)$k, 'lf_alertas_', 11) === 0) unset($_SESSION[$k]);
        }
    }

    /**
     * El aviso de la empresa en curso, para el encabezado.
     *
     * Devuelve null —sin tocar la base— cuando no hay empresa, cuando
     * Inventario está apagado, o si algo falla. Un aviso que revienta
     * tumbaría TODAS las pantallas, y el aviso no vale eso.
     */
    public static function delEncabezado()
    {
        if (empty($_SESSION['empresa_db']) || !empty($_SESSION['plataforma'])) return null;

        // Si la base todavía no tiene las columnas de esta versión, no se
        // pregunta nada. Esto corre en CADA carga de página: sin esta
        // puerta, una base a medio migrar escribía un renglón de error
        // por página —ciento sesenta en veinte minutos— y enterraba los
        // errores de verdad en el log.
        if (($_SESSION['lf_esquema'] ?? 0) < Migraciones::VERSION) return null;

        try {
            $db  = \LibertyFin\Datos\Conexion::de($_SESSION['empresa_db']);
            $cfg = (new ConfigRepo($db))->inventario();
            if (($cfg['activo'] ?? 'no') !== 'si') return null;
            $inv = new Inventario($db, $cfg);
            return (new self($db, $cfg))->aviso($inv->sucursalActual());
        } catch (\Throwable $e) {
            // Y si aun así falla, se dice UNA vez por sesión. El aviso no
            // vale una línea de log por página.
            if (empty($_SESSION['lf_alertas_rotas'])) {
                $_SESSION['lf_alertas_rotas'] = 1;
                error_log('[LibertyFin] aviso de inventario (no se vuelve a anotar en esta sesión): '
                    . $e->getMessage());
            }
            return null;
        }
    }

    private function todos($sql, array $p = [])
    { $st = $this->db->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }

    private function uno($sql, array $p = [])
    { $st = $this->db->prepare($sql); $st->execute($p); return $st->fetch(PDO::FETCH_ASSOC) ?: null; }
}