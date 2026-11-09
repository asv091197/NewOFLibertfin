<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Dinero;
use PDO;

/**
 * Promociones: qué descuento le toca a cada renglón del ticket.
 *
 * DÓNDE QUEDA EL DESCUENTO
 *
 * En `venta_detalles.descuento`, el descuento de la línea que el sistema ya
 * sabía manejar y que nadie llenaba: Ticket lo resta del total y
 * Dominio\Comision de la base, así que la comisión sale sobre el precio con
 * descuento sin tocar una sola cuenta. Si se cobra menos, se comisiona
 * menos: la empresa no paga comisión sobre dinero que no entró.
 *
 * EL SERVIDOR DECIDE
 *
 * Caja pide aquí el descuento para enseñarlo mientras se arma el ticket, y
 * al cobrar se vuelve a calcular con lo que hay en la base. Si no coincide
 * con lo que el cajero vio (una promoción venció, otra venta agotó los
 * usos), no se cobra: se le dice que revise. Un total que cambia después
 * de confirmarlo es justo lo que no debe pasar en una caja.
 *
 * UNA PROMOCIÓN POR RENGLÓN, LA MEJOR PARA EL CLIENTE
 *
 * No se acumulan: dos promociones encima de lo mismo terminan regalando el
 * servicio, y nadie diseña una promoción pensando en la otra. Si a un
 * renglón le aplican dos, gana la que más descuenta. Un paquete toma sus
 * renglones solo si descuenta más que lo que darían por separado.
 *
 * UN PRECIO CAMBIADO A MANO NO LLEVA PROMOCIÓN
 *
 * Si el cajero escribió otro precio, ya decidió cuánto cobrar; descontar
 * encima sería un doble descuento. Los productos de precio libre (en cero
 * en el catálogo) sí la llevan, sobre lo que se capture.
 */
final class Promociones
{
    const TIPOS = [
        'porcentaje' => 'Porcentaje de descuento',
        'monto'      => 'Monto de descuento',
        'precio'     => 'Precio especial',
        'nxm'        => 'Lleva N, paga M',
        'paquete'    => 'Paquete a precio especial',
        // Mayoreo: desde N piezas, cada una a $X. Se guarda en lleva (N) y valor ($X).
        'volumen'    => 'Precio de mayoreo',
    ];

    const ALCANCES = ['todo' => 'Todo el ticket', 'areas' => 'Áreas', 'productos' => 'Productos'];

    /** ISO-8601, como date('N'): 1 = lunes. */
    const DIAS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    // ════════════════════════════════════════════════════════
    //  CAJA
    // ════════════════════════════════════════════════════════

    /**
     * El descuento de cada renglón, con las promociones que valen hoy.
     *
     * @param array $lineas  [['id','area','precio','lista','cantidad'], ...]
     *                       precio = el capturado; lista = el del catálogo.
     * @param array $opc     codigo => el que se escribió en Caja,
     *                       quitadas => ids que el cajero quitó.
     */
    public function paraTicket(array $lineas, array $opc = [])
    {
        $r = self::calcular($lineas, $this->vigentes(), $opc);
        // "No existe" es lo que calcular() alcanza a saber con las vigentes.
        // Aquí se mira la base para decir por qué no aplica un código que
        // sí existe: es lo primero que va a preguntar el cliente.
        if ($r['codigo']['estado'] === 'no_existe') {
            $r['codigo']['mensaje'] = $this->porQueNo($r['codigo']['texto']);
        }
        return $r;
    }

    /** Lo que se guarda al cobrar: cuántas veces y cuánto descontó cada una. */
    public function anotarUsos($ventaId, array $calculo, array $ctx = [])
    {
        if (empty($calculo['usadas'])) return;
        $st = $this->db->prepare("
            INSERT INTO lf_promocion_usos
                (promocion_id, venta_id, producto_id, cantidad, descuento, codigo, usuario_id, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        foreach ($calculo['usadas'] as $pid => $u) {
            foreach ($u['productos'] as $prod) {
                $st->execute([(int)$pid, (int)$ventaId, $prod['producto_id'] ?: null,
                              $prod['cantidad'], $prod['descuento'],
                              $u['codigo'] ?: null, $ctx['usuario_id'] ?? null]);
            }
        }
    }

    /**
     * Qué promoción tuvo cada producto de una venta: [producto_id => nombre].
     * Para el detalle y el ticket. Sin las tablas, nada (no rompe la venta).
     */
    public function deVenta($ventaId)
    {
        try {
            $st = $this->db->prepare("
                SELECT u.producto_id, p.nombre FROM lf_promocion_usos u
                INNER JOIN lf_promociones p ON p.id = u.promocion_id
                WHERE u.venta_id = ? ORDER BY u.id");
            $st->execute([(int)$ventaId]);
            $r = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $r[(int)$f['producto_id']] = $f['nombre'];
            return $r;
        } catch (\Throwable $e) { return []; }
    }

    /**
     * Las que pueden aplicar hoy: activas, dentro de su vigencia y su día, y
     * con usos disponibles. El código y la compra mínima dependen del
     * ticket: los revisa calcular().
     */
    public function vigentes($hoy = null)
    {
        $hoy = $hoy ?: date('Y-m-d');
        try {
            $st = $this->db->prepare("
                SELECT p.*, (SELECT COUNT(DISTINCT u.venta_id) FROM lf_promocion_usos u
                             WHERE u.promocion_id = p.id) AS usos
                FROM lf_promociones p
                WHERE p.activo = 1
                  AND (p.desde IS NULL OR p.desde <= ?)
                  AND (p.hasta IS NULL OR p.hasta >= ?)");
            $st->execute([$hoy, $hoy]);
            $promos = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Sin las tablas (la base todavía no está al día) Caja cobra
            // igual, sin promociones: nunca debe bloquear una venta.
            error_log('[LibertyFin] promociones vigentes: ' . $e->getMessage());
            return [];
        }
        $dia = (int)date('N', strtotime($hoy));
        $promos = array_values(array_filter($promos, function ($p) use ($dia) {
            if ($p['limite'] !== null && (int)$p['usos'] >= (int)$p['limite']) return false;
            return self::valeElDia($p['dias'], $dia);
        }));
        return $this->conObjetivos($promos);
    }

    /**
     * El descuento de cada renglón. PURA: sin base de datos.
     *
     * @return array [
     *   'lineas'    => [i => ['promo_id','nombre','descuento','quitada' => null|['id','nombre']]],
     *   'descuento' => total descontado,
     *   'codigo'    => ['texto','estado' => ''|'ok'|'no_aplica'|'no_existe','mensaje'],
     *   'usadas'    => [promo_id => ['nombre','codigo','descuento','productos' => [...]]],
     * ]
     */
    public static function calcular(array $lineas, array $promos, array $opc = [])
    {
        $codigo   = self::normalizarCodigo($opc['codigo'] ?? '');
        $quitadas = array_map('intval', (array)($opc['quitadas'] ?? []));

        $L = [];
        $bruto = 0.0;
        foreach (array_values($lineas) as $i => $l) {
            $precio = max(0, (float)($l['precio'] ?? 0));
            $lista  = max(0, (float)($l['lista'] ?? $precio));
            // Puede ser fracción (0.5 kg): Caja ya decidió si el producto la admite.
            $cant   = max(0.001, (float)($l['cantidad'] ?? 1));
            $L[$i] = [
                'id' => (int)($l['id'] ?? 0), 'area' => (int)($l['area'] ?? 0),
                'precio' => $precio, 'cantidad' => $cant,
                'bruto' => round($precio * $cant, 2),
                // Precio libre (lista en cero): la promoción va sobre lo
                // capturado. Con precio de catálogo, solo si no se cambió.
                'manual' => $lista > 0 && abs($precio - $lista) > 0.005,
            ];
            $bruto += $precio * $cant;
        }
        $bruto = round($bruto, 2);

        $res = ['lineas' => [], 'descuento' => 0.0,
                'codigo' => ['texto' => $codigo, 'estado' => '', 'mensaje' => ''],
                'usadas' => []];
        foreach ($L as $i => $l) {
            $res['lineas'][$i] = ['promo_id' => null, 'nombre' => '', 'descuento' => 0.0, 'quitada' => null];
        }

        // ── Cuáles valen para ESTE ticket ──
        $conCodigo = false;   // ¿alguna vigente tiene el código escrito?
        $faltaMinimo = null;  // la compra mínima que pide el código, si no se alcanza
        $aplican = [];
        foreach ($promos as $p) {
            $pc = self::normalizarCodigo($p['codigo'] ?? '');
            if ($pc !== '') {
                if ($pc !== $codigo) continue;          // cupón que no se capturó
                $conCodigo = true;
            }
            if ((float)$p['minimo'] > 0 && $bruto + 0.005 < (float)$p['minimo']) {
                if ($pc !== '') $faltaMinimo = (float)$p['minimo'];
                continue;
            }
            $p['_quitada'] = in_array((int)$p['id'], $quitadas, true);
            $aplican[] = $p;
        }

        // ── Lo que daría cada una, renglón por renglón ──
        // $cand[i] = [[promo, descuento], ...]
        $cand = [];
        foreach ($aplican as $p) {
            if ($p['tipo'] === 'paquete') continue;
            $partes = self::porRenglon($p, $L);
            foreach ($partes as $i => $d) {
                if ($d > 0.004) $cand[$i][] = [$p, round($d, 2)];
            }
        }

        // La mejor de cada renglón, entre las no quitadas. La mejor quitada
        // se recuerda para poder ofrecer "volver a aplicar".
        $mejor = [];
        foreach ($cand as $i => $cs) {
            usort($cs, function ($a, $b) {
                return ($b[1] <=> $a[1]) ?: ((int)$a[0]['id'] <=> (int)$b[0]['id']);
            });
            foreach ($cs as $c) {
                if ($c[0]['_quitada']) {
                    if (!$res['lineas'][$i]['quitada']) {
                        $res['lineas'][$i]['quitada'] = ['id' => (int)$c[0]['id'], 'nombre' => $c[0]['nombre']];
                    }
                    continue;
                }
                if (!isset($mejor[$i])) $mejor[$i] = $c;
            }
        }

        // ── Paquetes ──
        // Se prueban del que más descuenta al que menos; cada uno toma sus
        // renglones solo si mejora lo que darían sus promociones sueltas.
        $paquetes = [];
        foreach ($aplican as $p) {
            if ($p['tipo'] !== 'paquete') continue;
            $pq = self::paquete($p, $L);
            if ($pq) $paquetes[] = $pq;
        }
        usort($paquetes, function ($a, $b) { return $b['descuento'] <=> $a['descuento']; });
        $tomados = [];
        foreach ($paquetes as $pq) {
            $p = $pq['promo'];
            $choca = array_intersect(array_keys($pq['partes']), $tomados);
            if ($p['_quitada']) {
                foreach ($pq['partes'] as $i => $d) {
                    if (!$res['lineas'][$i]['quitada']) {
                        $res['lineas'][$i]['quitada'] = ['id' => (int)$p['id'], 'nombre' => $p['nombre']];
                    }
                }
                continue;
            }
            if ($choca) continue;
            $sueltas = 0.0;
            foreach ($pq['partes'] as $i => $d) $sueltas += isset($mejor[$i]) ? $mejor[$i][1] : 0;
            if ($pq['descuento'] <= $sueltas + 0.004) continue;
            foreach ($pq['partes'] as $i => $d) {
                $mejor[$i] = [$p, $d];
                $tomados[] = $i;
            }
        }

        // ── El resultado ──
        foreach ($mejor as $i => $c) {
            list($p, $d) = $c;
            $d = round(min($d, $L[$i]['bruto']), 2);
            if ($d <= 0.004) continue;
            $pid = (int)$p['id'];
            $res['lineas'][$i]['promo_id']  = $pid;
            $res['lineas'][$i]['nombre']    = $p['nombre'];
            $res['lineas'][$i]['descuento'] = $d;
            $res['descuento'] += $d;
            if (!isset($res['usadas'][$pid])) {
                $res['usadas'][$pid] = ['nombre' => $p['nombre'],
                    'codigo' => self::normalizarCodigo($p['codigo'] ?? ''),
                    'descuento' => 0.0, 'productos' => []];
            }
            $res['usadas'][$pid]['descuento'] = round($res['usadas'][$pid]['descuento'] + $d, 2);
            $res['usadas'][$pid]['productos'][] = ['producto_id' => $L[$i]['id'],
                'cantidad' => $L[$i]['cantidad'], 'descuento' => $d];
        }
        $res['descuento'] = round($res['descuento'], 2);

        // ── Qué decirle al cajero del código ──
        if ($codigo !== '') {
            $usado = 0.0;
            foreach ($res['usadas'] as $u) if ($u['codigo'] === $codigo) $usado += $u['descuento'];
            if ($usado > 0) {
                $res['codigo']['estado']  = 'ok';
                $res['codigo']['mensaje'] = 'Código aplicado: −' . Dinero::pesos($usado) . '.';
            } elseif ($faltaMinimo !== null) {
                $res['codigo']['estado']  = 'no_aplica';
                $res['codigo']['mensaje'] = 'Este código pide una compra de al menos '
                                          . Dinero::pesos($faltaMinimo) . '.';
            } elseif ($conCodigo) {
                $res['codigo']['estado']  = 'no_aplica';
                $res['codigo']['mensaje'] = 'El código es válido, pero no aplica a lo que lleva el '
                                          . 'ticket, o hay otra promoción que descuenta más.';
            } else {
                $res['codigo']['estado']  = 'no_existe';
                $res['codigo']['mensaje'] = 'Ese código no existe.';
            }
        }
        return $res;
    }

    /** Lo que descuenta una promoción (no paquete) en cada renglón. */
    private static function porRenglon(array $p, array $L)
    {
        $areas = $prods = [];
        foreach ($p['objetivos'] ?? [] as $o) {
            if ($o['tipo'] === 'area') $areas[(int)$o['ref_id']] = true;
            else $prods[(int)$o['ref_id']] = true;
        }
        $valor = (float)$p['valor'];
        $toca = [];
        foreach ($L as $i => $l) {
            if ($l['manual'] || $l['bruto'] <= 0) continue;
            $ok = $p['alcance'] === 'todo'
               || ($p['alcance'] === 'areas' && isset($areas[$l['area']]))
               || ($p['alcance'] === 'productos' && isset($prods[$l['id']]));
            if ($ok) $toca[$i] = $l;
        }
        if (!$toca) return [];

        // Un monto sobre TODO el ticket es "$200 menos en tu compra", no
        // $200 por cada producto: se reparte según lo que pesa cada uno.
        if ($p['tipo'] === 'monto' && $p['alcance'] === 'todo') {
            $suma = array_sum(array_column($toca, 'bruto'));
            return self::repartir(min($valor, $suma), $toca);
        }

        $out = [];
        foreach ($toca as $i => $l) {
            switch ($p['tipo']) {
                case 'porcentaje':
                    $out[$i] = $l['bruto'] * min(100, max(0, $valor)) / 100;
                    break;
                case 'monto':           // por pieza
                    $out[$i] = min($valor * $l['cantidad'], $l['bruto']);
                    break;
                case 'precio':
                    $out[$i] = $l['precio'] > $valor ? ($l['precio'] - $valor) * $l['cantidad'] : 0;
                    break;
                case 'nxm':
                    $lleva = (int)$p['lleva']; $paga = (int)$p['paga'];
                    if ($lleva < 2 || $paga < 1 || $paga >= $lleva) break;
                    $gratis = floor($l['cantidad'] / $lleva) * ($lleva - $paga);
                    $out[$i] = $gratis * $l['precio'];
                    break;
                case 'volumen':         // todas las piezas a $X, si llega al mínimo
                    if ($l['cantidad'] + 0.0005 >= max(2, (int)$p['lleva']) && $l['precio'] > $valor) {
                        $out[$i] = ($l['precio'] - $valor) * $l['cantidad'];
                    }
                    break;
            }
        }
        return $out;
    }

    /**
     * Un paquete: si el ticket trae todo lo que lo forma, cuántas veces y
     * cuánto descuenta, repartido entre sus renglones según su valor.
     */
    private static function paquete(array $p, array $L)
    {
        $piezas = [];
        foreach ($p['objetivos'] ?? [] as $o) {
            if ($o['tipo'] !== 'producto') continue;
            $piezas[(int)$o['ref_id']] = max(1, (int)$o['cantidad']);
        }
        if (count($piezas) < 2) return null;

        $veces = null; $donde = [];
        foreach ($piezas as $prod => $n) {
            $i = null;
            foreach ($L as $k => $l) if ($l['id'] === $prod && !$l['manual']) { $i = $k; break; }
            if ($i === null) return null;
            $donde[$prod] = $i;
            $v = (int)floor($L[$i]['cantidad'] / $n);
            $veces = $veces === null ? $v : min($veces, $v);
        }
        if ($veces < 1) return null;

        $normal = 0.0; $pesos = [];
        foreach ($piezas as $prod => $n) {
            $i = $donde[$prod];
            $pesos[$i] = ['bruto' => $L[$i]['precio'] * $n * $veces];
            $normal += $pesos[$i]['bruto'];
        }
        $desc = round($normal - (float)$p['valor'] * $veces, 2);
        if ($desc <= 0.004) return null;
        return ['promo' => $p, 'descuento' => $desc, 'partes' => self::repartir($desc, $pesos)];
    }

    /** Reparte un monto según el peso (bruto) de cada renglón, al centavo. */
    private static function repartir($monto, array $renglones)
    {
        $suma = 0.0;
        foreach ($renglones as $r) $suma += $r['bruto'];
        if ($suma <= 0 || $monto <= 0) return [];
        $out = []; $puesto = 0.0; $claves = array_keys($renglones); $ultima = end($claves);
        foreach ($renglones as $i => $r) {
            // El último se lleva el resto: así la suma da el monto exacto.
            $d = $i === $ultima ? round($monto - $puesto, 2) : round($monto * $r['bruto'] / $suma, 2);
            $out[$i] = $d;
            $puesto += $d;
        }
        return $out;
    }

    private static function valeElDia($dias, $dia)
    {
        $dias = trim((string)$dias);
        if ($dias === '') return true;
        return in_array((string)$dia, explode(',', $dias), true);
    }

    public static function normalizarCodigo($c)
    {
        return mb_strtoupper(preg_replace('/\s+/', '', (string)$c));
    }

    /** Por qué un código que no salió entre las vigentes no aplica hoy. */
    private function porQueNo($codigo)
    {
        try {
            $st = $this->db->prepare("SELECT * FROM lf_promociones WHERE UPPER(codigo) = ? LIMIT 1");
            $st->execute([$codigo]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $p = null; }
        if (!$p) return 'Ese código no existe.';
        $hoy = date('Y-m-d');
        if (!(int)$p['activo'])                        return 'Ese código está pausado.';
        if ($p['desde'] !== null && $p['desde'] > $hoy) return 'Ese código empieza el ' . date('d/m/Y', strtotime($p['desde'])) . '.';
        if ($p['hasta'] !== null && $p['hasta'] < $hoy) return 'Ese código venció el ' . date('d/m/Y', strtotime($p['hasta'])) . '.';
        if (!self::valeElDia($p['dias'], (int)date('N'))) return 'Ese código no aplica hoy: ' . self::diasTexto($p['dias']) . '.';
        return 'Ese código ya no tiene usos disponibles.';
    }

    // ════════════════════════════════════════════════════════
    //  ADMINISTRAR
    // ════════════════════════════════════════════════════════

    /** Todas, con sus objetivos, usos, lo descontado y su estado de hoy. */
    public function listar()
    {
        try {
            $promos = $this->db->query("
                SELECT p.*,
                       (SELECT COUNT(DISTINCT u.venta_id) FROM lf_promocion_usos u WHERE u.promocion_id = p.id) AS usos,
                       (SELECT COALESCE(SUM(u.descuento),0) FROM lf_promocion_usos u WHERE u.promocion_id = p.id) AS descontado
                FROM lf_promociones p
                ORDER BY p.activo DESC, COALESCE(p.hasta, '9999-12-31') DESC, p.id DESC")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] listar promociones: ' . $e->getMessage());
            return [];
        }
        $promos = $this->conObjetivos($promos, true);
        foreach ($promos as &$p) $p['estado'] = self::estadoDe($p);
        unset($p);
        return $promos;
    }

    public function una($id)
    {
        $st = $this->db->prepare("SELECT * FROM lf_promociones WHERE id = ?");
        $st->execute([(int)$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return null;
        return $this->conObjetivos([$p], true)[0];
    }

    /**
     * Vigente, programada, vencida, pausada o agotada: lo que importa al ver
     * la lista es si HOY se está aplicando, y si no, por qué.
     */
    public static function estadoDe(array $p, $hoy = null)
    {
        $hoy = $hoy ?: date('Y-m-d');
        if (!(int)$p['activo']) return ['clave' => 'pausada', 'texto' => 'Pausada'];
        if ($p['hasta'] !== null && $p['hasta'] < $hoy) return ['clave' => 'vencida', 'texto' => 'Vencida'];
        if ($p['limite'] !== null && (int)($p['usos'] ?? 0) >= (int)$p['limite']) {
            return ['clave' => 'agotada', 'texto' => 'Sin usos'];
        }
        if ($p['desde'] !== null && $p['desde'] > $hoy) {
            return ['clave' => 'programada', 'texto' => 'Empieza ' . date('d/m', strtotime($p['desde']))];
        }
        if (!self::valeElDia($p['dias'], (int)date('N', strtotime($hoy)))) {
            return ['clave' => 'hoy_no', 'texto' => 'Hoy no aplica'];
        }
        return ['clave' => 'vigente', 'texto' => 'Vigente'];
    }

    /**
     * Crea o actualiza. $d viene del formulario.
     * @throws \InvalidArgumentException con el mensaje para la pantalla
     */
    public function guardar(array $d, $usuario = '')
    {
        $id     = (int)($d['id'] ?? 0);
        $nombre = trim(preg_replace('/\s+/', ' ', (string)($d['nombre'] ?? '')));
        $tipo   = (string)($d['tipo'] ?? '');
        $alc    = (string)($d['alcance'] ?? 'productos');
        $valor  = (float)str_replace([',', '$'], '', (string)($d['valor'] ?? '0'));
        $lleva  = (int)($d['lleva'] ?? 0);
        $paga   = (int)($d['paga'] ?? 0);

        if ($nombre === '') throw new \InvalidArgumentException('Ponle un nombre a la promoción.');
        if (mb_strlen($nombre) > 120) $nombre = mb_substr($nombre, 0, 120);
        if (!isset(self::TIPOS[$tipo])) throw new \InvalidArgumentException('Elige el tipo de promoción.');
        if (!isset(self::ALCANCES[$alc])) $alc = 'productos';
        // Un precio especial y un paquete hablan de productos concretos: "el
        // precio especial de toda el área" no significa nada.
        if (in_array($tipo, ['precio', 'paquete', 'volumen'], true)) $alc = 'productos';
        if ($tipo === 'volumen') $lleva = (int)($d['desde_piezas'] ?? $lleva);
        if ($tipo === 'nxm' && $alc === 'todo') $alc = 'productos';

        switch ($tipo) {
            case 'porcentaje':
                if ($valor <= 0 || $valor > 100) throw new \InvalidArgumentException('El porcentaje va de 0.01 a 100.');
                break;
            case 'monto':
                if ($valor <= 0) throw new \InvalidArgumentException('El monto de descuento debe ser mayor a cero.');
                break;
            case 'precio':
                if ($valor < 0) throw new \InvalidArgumentException('El precio especial no puede ser negativo.');
                break;
            case 'paquete':
                if ($valor <= 0) throw new \InvalidArgumentException('Ponle precio al paquete.');
                break;
            case 'volumen':
                if ($lleva < 2) throw new \InvalidArgumentException('El mayoreo empieza desde 2 piezas o más.');
                if ($valor <= 0) throw new \InvalidArgumentException('Escribe el precio de mayoreo por pieza.');
                break;
            case 'nxm':
                if ($lleva < 2 || $paga < 1 || $paga >= $lleva) {
                    throw new \InvalidArgumentException('En «lleva N, paga M», N va de 2 en adelante y M es menor que N (por ejemplo, lleva 3 paga 2).');
                }
                $valor = 0;
                break;
        }
        if ($tipo !== 'nxm') { $paga = null; }
        if (!in_array($tipo, ['nxm', 'volumen'], true)) { $lleva = null; }

        // ── A qué aplica ──
        $objetivos = [];
        if ($tipo === 'paquete') {
            // Los marcados, con su cantidad. La cantidad de los no marcados
            // también llega (el campo está en la fila) y se ignora.
            $cuantos = (array)($d['paquete'] ?? []);
            foreach (array_unique(array_map('intval', (array)($d['productos'] ?? []))) as $prod) {
                $n = max(1, min(99, (int)($cuantos[$prod] ?? 1)));
                if ($prod > 0) $objetivos[] = ['producto', $prod, $n];
            }
            if (count($objetivos) < 2) {
                throw new \InvalidArgumentException('Un paquete lleva al menos dos productos distintos.');
            }
        } elseif ($alc === 'productos') {
            foreach (array_unique(array_map('intval', (array)($d['productos'] ?? []))) as $prod) {
                if ($prod > 0) $objetivos[] = ['producto', $prod, 1];
            }
            if (!$objetivos) throw new \InvalidArgumentException('Elige al menos un producto.');
        } elseif ($alc === 'areas') {
            foreach (array_unique(array_map('intval', (array)($d['areas'] ?? []))) as $a) {
                if ($a > 0) $objetivos[] = ['area', $a, 1];
            }
            if (!$objetivos) throw new \InvalidArgumentException('Elige al menos un área.');
        }

        // ── Cuándo ──
        $desde = self::fechaO($d['desde'] ?? '');
        $hasta = self::fechaO($d['hasta'] ?? '');
        if ($desde && $hasta && $hasta < $desde) {
            throw new \InvalidArgumentException('La fecha de fin es antes que la de inicio.');
        }
        $dias = array_values(array_intersect(array_map('strval', (array)($d['dias'] ?? [])),
                                             array_map('strval', array_keys(self::DIAS))));
        sort($dias);
        // Los siete días, o ninguno marcado, es lo mismo: todos.
        $dias = ($dias && count($dias) < 7) ? implode(',', $dias) : null;

        // ── Condiciones ──
        $codigo = self::normalizarCodigo($d['codigo'] ?? '');
        if ($codigo !== '') {
            if (!preg_match('/^[A-Z0-9_-]{3,30}$/u', $codigo)) {
                throw new \InvalidArgumentException('El código lleva de 3 a 30 letras, números, guion o guion bajo, sin espacios ni acentos.');
            }
            $st = $this->db->prepare("SELECT nombre FROM lf_promociones WHERE UPPER(codigo) = ? AND id <> ? LIMIT 1");
            $st->execute([$codigo, $id]);
            if ($otra = $st->fetchColumn()) {
                throw new \InvalidArgumentException('El código ' . $codigo . ' ya lo usa «' . $otra . '».');
            }
        }
        $minimo = max(0, (float)str_replace([',', '$'], '', (string)($d['minimo'] ?? '0')));
        $limite = trim((string)($d['limite'] ?? '')) === '' ? null : max(1, (int)$d['limite']);
        $nota   = mb_substr(trim((string)($d['nota'] ?? '')), 0, 255) ?: null;

        $fila = [$nombre, $tipo, round($valor, 4), $lleva, $paga, $alc, $desde, $hasta, $dias,
                 $codigo ?: null, round($minimo, 2), $limite, $nota];

        $this->db->beginTransaction();
        try {
            if ($id) {
                $st = $this->db->prepare("
                    UPDATE lf_promociones SET nombre=?, tipo=?, valor=?, lleva=?, paga=?, alcance=?,
                           desde=?, hasta=?, dias=?, codigo=?, minimo=?, limite=?, nota=?,
                           actualizado_en=NOW()
                    WHERE id = ?");
                $st->execute(array_merge($fila, [$id]));
                $this->db->prepare("DELETE FROM lf_promocion_objetivos WHERE promocion_id = ?")->execute([$id]);
            } else {
                $st = $this->db->prepare("
                    INSERT INTO lf_promociones (nombre, tipo, valor, lleva, paga, alcance, desde, hasta,
                           dias, codigo, minimo, limite, nota, activo, creado_en, creado_por)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),?)");
                $st->execute(array_merge($fila, [mb_substr((string)$usuario, 0, 160) ?: null]));
                $id = (int)$this->db->lastInsertId();
            }
            $st = $this->db->prepare("INSERT INTO lf_promocion_objetivos (promocion_id, tipo, ref_id, cantidad) VALUES (?,?,?,?)");
            foreach ($objetivos as $o) $st->execute([$id, $o[0], $o[1], $o[2]]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Pausa o reanuda. Devuelve cómo quedó (true = activa). */
    public function alternar($id)
    {
        $this->db->prepare("UPDATE lf_promociones SET activo = 1 - activo, actualizado_en = NOW() WHERE id = ?")
                 ->execute([(int)$id]);
        $st = $this->db->prepare("SELECT activo FROM lf_promociones WHERE id = ?");
        $st->execute([(int)$id]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Borra una que NUNCA se usó (una capturada mal). La que ya se usó se
     * pausa: borrarla dejaría ventas con un descuento que no dice de dónde
     * salió.
     */
    public function borrar($id)
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM lf_promocion_usos WHERE promocion_id = ?");
        $st->execute([(int)$id]);
        if ((int)$st->fetchColumn() > 0) {
            throw new \InvalidArgumentException('Esa promoción ya se usó en ventas: no se borra, se pausa.');
        }
        $this->db->prepare("DELETE FROM lf_promocion_objetivos WHERE promocion_id = ?")->execute([(int)$id]);
        $this->db->prepare("DELETE FROM lf_promociones WHERE id = ?")->execute([(int)$id]);
    }

    /**
     * Lo que Caja enseña ANTES de cobrar: qué productos tienen promoción
     * (la insignia de la tarjeta y el aviso al agregarlo) y lo necesario
     * para decir "te falta poco" (paquetes, lleva-paga, compra mínima).
     *
     * Las de CÓDIGO no salen: son para quien trae el código, y anunciarlas
     * en la tarjeta las regalaría a todos.
     *
     * @param array $vigentes   las de vigentes(), con objetivos
     * @param array $productos  los de Caja: id y categoria_id
     * @return array ['promos' => {id: {...}}, 'productos' => {producto: [ids]}, 'todo' => [ids]]
     */
    public static function paraCaja(array $vigentes, array $productos)
    {
        $porArea = [];
        foreach ($productos as $p) $porArea[(int)($p['categoria_id'] ?? 0)][] = (int)$p['id'];
        $out = ['promos' => [], 'productos' => [], 'todo' => []];
        foreach ($vigentes as $p) {
            if (self::normalizarCodigo($p['codigo'] ?? '') !== '') continue;
            $id = (int)$p['id'];
            $ids = []; $piezas = [];
            foreach ($p['objetivos'] ?? [] as $o) {
                if ($o['tipo'] === 'producto') {
                    $ids[] = (int)$o['ref_id'];
                    $piezas[(string)(int)$o['ref_id']] = max(1, (int)$o['cantidad']);
                } else {
                    foreach ($porArea[(int)$o['ref_id']] ?? [] as $pid) $ids[] = $pid;
                }
            }
            // "10% de descuento en compras desde $5,000.00 (hasta el 31/10)"
            $texto = self::describir($p);
            $minimo = (float)$p['minimo'] > 0 ? Dinero::pesos($p['minimo']) : '';
            $cond = [];
            if ($p['alcance'] === 'todo') {
                $texto = str_replace(' en la compra', '', $texto)
                       . ($minimo ? ' en compras desde ' . $minimo : ' en toda la compra');
            } elseif ($minimo) {
                $cond[] = 'compra mínima de ' . $minimo;
            }
            if (!empty($p['dias']))  $cond[] = 'solo ' . self::diasTexto($p['dias']);
            if (!empty($p['hasta'])) $cond[] = 'hasta el ' . date('d/m', strtotime($p['hasta']));
            $out['promos'][(string)$id] = [
                'nombre'   => $p['nombre'],
                'tipo'     => $p['tipo'],
                'alcance'  => $p['alcance'],
                'etiqueta' => self::etiqueta($p),
                'texto'    => $texto . ($cond ? ' (' . implode(', ', $cond) . ')' : ''),
                'valor'    => (float)$p['valor'],
                'lleva'    => (int)$p['lleva'],
                'paga'     => (int)$p['paga'],
                'minimo'   => (float)$p['minimo'],
                'piezas'   => $p['tipo'] === 'paquete' ? $piezas : [],
            ];
            if ($p['alcance'] === 'todo') { $out['todo'][] = $id; continue; }
            foreach (array_unique($ids) as $pid) $out['productos'][$pid][] = $id;
        }
        // Los paquetes al final: la insignia y el aviso dicen primero la que
        // aplica con ese producto solo; un paquete depende de otros.
        foreach ($out['productos'] as &$ids) {
            $sueltas = $paquetes = [];
            foreach ($ids as $pid) {
                if ($out['promos'][(string)$pid]['tipo'] === 'paquete') $paquetes[] = $pid; else $sueltas[] = $pid;
            }
            $ids = array_merge($sueltas, $paquetes);
        }
        unset($ids);
        // Las llaves son ids (desde 1): en el JSON salen como objeto, que es
        // lo que Caja lee. Vacío sale `[]`, y buscar en él tampoco falla.
        return $out;
    }

    /** La insignia corta de la tarjeta: "−20%", "−$500", "A $6,500", "3×2", "Paquete". */
    public static function etiqueta(array $p)
    {
        $v = (float)$p['valor'];
        $num = function ($x) { return rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.'); };
        $corto = function ($x) { return '$' . number_format($x, (floor($x) == $x) ? 0 : 2); };
        switch ($p['tipo']) {
            case 'porcentaje': return '−' . $num($v) . '%';
            case 'monto':      return '−' . $corto($v);
            case 'precio':     return 'A ' . $corto($v);
            case 'nxm':        return (int)$p['lleva'] . '×' . (int)$p['paga'];
            case 'paquete':    return 'Paquete';
            case 'volumen':    return 'Mayoreo';
        }
        return 'Promo';
    }

    /** Cómo se lee una promoción en una línea: "20% en Declaración anual". */
    public static function describir(array $p)
    {
        $v = (float)$p['valor'];
        $num = function ($x) { return rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.'); };
        switch ($p['tipo']) {
            case 'porcentaje': $que = $num($v) . '% de descuento'; break;
            case 'monto':      $que = Dinero::pesos($v) . ($p['alcance'] === 'todo' ? ' menos en la compra' : ' menos por pieza'); break;
            case 'precio':     $que = 'a ' . Dinero::pesos($v); break;
            case 'nxm':        $que = 'lleva ' . (int)$p['lleva'] . ', paga ' . (int)$p['paga']; break;
            case 'paquete':    $que = 'paquete por ' . Dinero::pesos($v); break;
            case 'volumen':    $que = 'desde ' . (int)$p['lleva'] . ' piezas, a ' . Dinero::pesos($v) . ' c/u'; break;
            default:           $que = '';
        }
        return $que;
    }

    public static function diasTexto($dias)
    {
        $dias = trim((string)$dias);
        if ($dias === '') return 'todos los días';
        $n = array_map('intval', explode(',', $dias));
        if ($n === [1, 2, 3, 4, 5]) return 'de lunes a viernes';
        if ($n === [6, 7]) return 'fines de semana';
        return implode(', ', array_map(function ($d) { return self::DIAS[$d] ?? '?'; }, $n));
    }

    /** Les pega sus objetivos (y, si se pide, los nombres para pantalla). */
    private function conObjetivos(array $promos, $nombres = false)
    {
        if (!$promos) return [];
        $ids = array_map(function ($p) { return (int)$p['id']; }, $promos);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $sql = $nombres
            ? "SELECT o.*, CASE WHEN o.tipo = 'area' THEN c.nombre ELSE pr.nombre END AS nombre
               FROM lf_promocion_objetivos o
               LEFT JOIN categorias c ON o.tipo = 'area' AND c.id = o.ref_id
               LEFT JOIN productos pr ON o.tipo = 'producto' AND pr.id = o.ref_id
               WHERE o.promocion_id IN ($marcas) ORDER BY o.id"
            : "SELECT o.* FROM lf_promocion_objetivos o WHERE o.promocion_id IN ($marcas) ORDER BY o.id";
        $st = $this->db->prepare($sql);
        $st->execute($ids);
        $por = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) $por[(int)$o['promocion_id']][] = $o;
        foreach ($promos as &$p) $p['objetivos'] = $por[(int)$p['id']] ?? [];
        unset($p);
        return $promos;
    }

    private static function fechaO($v)
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        $t = strtotime($v);
        if ($t === false) throw new \InvalidArgumentException('No entendí la fecha «' . $v . '».');
        return date('Y-m-d', $t);
    }
}
