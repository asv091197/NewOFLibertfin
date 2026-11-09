<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Dinero;
use PDO;

/**
 * Cotizaciones: el presupuesto que se le da al cliente antes de venderle.
 *
 * CÓMO VIVE UNA
 *
 *   borrador  → se está armando; se puede cambiar todo.
 *   enviada   → ya la tiene el cliente. Se puede corregir y reenviar.
 *   aceptada  → se cobró en Caja. Queda ligada a su venta y ya no cambia.
 *   rechazada → el cliente dijo que no. Se puede reabrir.
 *
 * «Vencida» no es un estado guardado: es una borrador o enviada cuya
 * vigencia ya pasó. Así no hace falta un proceso que las marque cada noche.
 *
 * DE COTIZACIÓN A VENTA
 *
 * «Cobrar en Caja» abre Caja con los renglones y el cliente ya puestos
 * (CajaControlador::precarga). La cotización se marca aceptada solo
 * cuando la venta de verdad se registra (CajaControlador::despuesDeCobrar),
 * no al abrir Caja: si el cajero se arrepiente, la cotización sigue viva.
 */
final class Cotizaciones
{
    const ESTADOS = [
        'borrador'  => 'Borrador',
        'enviada'   => 'Enviada',
        'aceptada'  => 'Aceptada',
        'rechazada' => 'Rechazada',
    ];
    /** Las que todavía pueden volverse venta. */
    const ABIERTAS = ['borrador', 'enviada'];
    const VIGENCIA_DIAS = 15;

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    // ════════════════════════════════════════════════════════
    //  LEER
    // ════════════════════════════════════════════════════════

    /** @param array $f estado ('vencida' también vale), q */
    public function listar(array $f = [])
    {
        $w = ['1 = 1']; $p = [];
        $hoy = date('Y-m-d');
        $e = (string)($f['estado'] ?? '');
        if ($e === 'vencida') {
            $w[] = "c.estado IN ('borrador','enviada') AND c.vigencia IS NOT NULL AND c.vigencia < ?"; $p[] = $hoy;
        } elseif ($e === 'abiertas') {
            $w[] = "c.estado IN ('borrador','enviada')";
        } elseif (isset(self::ESTADOS[$e])) {
            $w[] = 'c.estado = ?'; $p[] = $e;
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(c.folio LIKE ? OR c.cliente_nombre LIKE ?)';
            $p[] = '%' . $q . '%'; $p[] = '%' . $q . '%';
        }
        $st = $this->db->prepare("
            SELECT c.*, v.codigo_venta,
                   (SELECT COUNT(*) FROM lf_cotizacion_lineas l WHERE l.cotizacion_id = c.id) AS renglones
            FROM lf_cotizaciones c
            LEFT JOIN ventas v ON v.id = c.venta_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY c.creado_en DESC, c.id DESC
            LIMIT 300");
        $st->execute($p);
        $r = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($r as &$c) $c['vencida'] = self::vencida($c);
        return $r;
    }

    /** Cuántas hay de cada cosa, para los números de arriba. */
    public function resumen()
    {
        $r = ['abiertas' => 0, 'monto_abierto' => 0.0, 'vencidas' => 0, 'aceptadas_mes' => 0, 'monto_aceptado_mes' => 0.0];
        $mes = date('Y-m-01');
        foreach ($this->db->query("SELECT estado, vigencia, total, actualizado_en, creado_en FROM lf_cotizaciones")
                          ->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (in_array($c['estado'], self::ABIERTAS, true)) {
                if (self::vencida($c)) { $r['vencidas']++; continue; }
                $r['abiertas']++; $r['monto_abierto'] += (float)$c['total'];
            } elseif ($c['estado'] === 'aceptada' && substr((string)($c['actualizado_en'] ?: $c['creado_en']), 0, 10) >= $mes) {
                $r['aceptadas_mes']++; $r['monto_aceptado_mes'] += (float)$c['total'];
            }
        }
        return $r;
    }

    /** Una, con sus renglones. null si no existe. */
    public function una($id)
    {
        $st = $this->db->prepare("SELECT c.*, v.codigo_venta FROM lf_cotizaciones c
                                  LEFT JOIN ventas v ON v.id = c.venta_id WHERE c.id = ?");
        $st->execute([(int)$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;
        $st = $this->db->prepare("SELECT producto_id, nombre, cantidad, precio FROM lf_cotizacion_lineas
                                  WHERE cotizacion_id = ? ORDER BY id");
        $st->execute([(int)$id]);
        $c['lineas'] = $st->fetchAll(PDO::FETCH_ASSOC);
        $c['vencida'] = self::vencida($c);
        return $c;
    }

    /** Queda anotado a dónde se mandó y cuándo. */
    public function anotarEnvio($id, $correo)
    {
        $this->db->prepare("UPDATE lf_cotizaciones SET enviada_a = ?, enviada_en = NOW() WHERE id = ?")
                 ->execute([mb_substr(trim((string)$correo), 0, 160), (int)$id]);
        return true;
    }

    /** De un cliente, para su ficha. */
    public function delCliente($clienteId, $tope = 10)
    {
        $st = $this->db->prepare("SELECT id, folio, estado, vigencia, total, creado_en FROM lf_cotizaciones
                                  WHERE cliente_id = ? ORDER BY creado_en DESC, id DESC LIMIT " . max(1, (int)$tope));
        $st->execute([(int)$clienteId]);
        $r = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($r as &$c) $c['vencida'] = self::vencida($c);
        return $r;
    }

    public static function vencida(array $c)
    {
        return in_array($c['estado'] ?? '', self::ABIERTAS, true)
            && !empty($c['vigencia']) && $c['vigencia'] < date('Y-m-d');
    }

    // ════════════════════════════════════════════════════════
    //  ESCRIBIR
    // ════════════════════════════════════════════════════════

    /**
     * Crea o corrige una cotización.
     *
     * @param array $d cliente_id | cliente_nombre, vigencia (Y-m-d), notas,
     *                 lineas: [['producto_id', 'nombre', 'cantidad', 'precio'], ...]
     * @return int el id
     */
    public function guardar(array $d, $id = 0, $usuario = '')
    {
        $id = (int)$id;
        if ($id) {
            $antes = $this->una($id);
            if (!$antes) throw new \InvalidArgumentException('Esa cotización no existe.');
            if (!in_array($antes['estado'], self::ABIERTAS, true)) {
                throw new \InvalidArgumentException('La cotización ' . $antes['folio'] . ' ya está '
                    . mb_strtolower(self::ESTADOS[$antes['estado']]) . ' y no se puede cambiar.');
            }
        }

        // El cliente: uno del catálogo o solo un nombre (un prospecto que
        // todavía no es cliente).
        $clienteId = (int)($d['cliente_id'] ?? 0) ?: null;
        $nombre = mb_substr(trim((string)($d['cliente_nombre'] ?? '')), 0, 200);
        if ($clienteId) {
            $st = $this->db->prepare("SELECT nombre FROM clientes WHERE id = ?");
            $st->execute([$clienteId]);
            $n = $st->fetchColumn();
            if ($n === false) { $clienteId = null; } else { $nombre = (string)$n; }
        }
        if ($nombre === '') throw new \InvalidArgumentException('Escribe para quién es la cotización.');

        $lineas = [];
        $total = 0.0;
        foreach ((array)($d['lineas'] ?? []) as $l) {
            $n = mb_substr(trim((string)($l['nombre'] ?? '')), 0, 255);
            $cant = round((float)str_replace(',', '', (string)($l['cantidad'] ?? 1)), 3);
            $precio = round((float)str_replace(['$', ','], '', (string)($l['precio'] ?? 0)), 2);
            $pid = (int)($l['producto_id'] ?? 0) ?: null;
            if ($n === '' && !$pid) continue;
            if ($pid && $n === '') {
                $st = $this->db->prepare("SELECT nombre FROM productos WHERE id = ?");
                $st->execute([$pid]);
                $n = (string)$st->fetchColumn();
            }
            if ($cant <= 0) throw new \InvalidArgumentException('La cantidad de «' . $n . '» debe ser mayor que cero.');
            if ($precio < 0) throw new \InvalidArgumentException('El precio de «' . $n . '» no puede ser negativo.');
            $lineas[] = [$pid, $n, $cant, $precio];
            $total += $cant * $precio;
        }
        if (!$lineas) throw new \InvalidArgumentException('Agrega al menos un concepto a la cotización.');
        $total = Dinero::centavos($total);

        $vig = trim((string)($d['vigencia'] ?? ''));
        if ($vig === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vig)) {
            $vig = date('Y-m-d', strtotime('+' . self::VIGENCIA_DIAS . ' days'));
        }
        $notas = trim((string)($d['notas'] ?? ''));
        $notas = $notas === '' ? null : mb_substr($notas, 0, 2000);

        $this->db->beginTransaction();
        try {
            if ($id) {
                $this->db->prepare("UPDATE lf_cotizaciones SET cliente_id = ?, cliente_nombre = ?, vigencia = ?,
                                           notas = ?, total = ?, actualizado_en = NOW() WHERE id = ?")
                         ->execute([$clienteId, $nombre, $vig, $notas, $total, $id]);
                $this->db->prepare("DELETE FROM lf_cotizacion_lineas WHERE cotizacion_id = ?")->execute([$id]);
            } else {
                $this->db->prepare("INSERT INTO lf_cotizaciones
                        (folio, cliente_id, cliente_nombre, estado, vigencia, notas, total, creado_por, creado_en)
                        VALUES ('', ?, ?, 'borrador', ?, ?, ?, ?, NOW())")
                         ->execute([$clienteId, $nombre, $vig, $notas, $total, mb_substr((string)$usuario, 0, 100) ?: null]);
                $id = (int)$this->db->lastInsertId();
                // El folio sale del id: nunca se repite, ni con dos al mismo tiempo.
                $this->db->prepare("UPDATE lf_cotizaciones SET folio = ? WHERE id = ?")
                         ->execute(['COT-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT), $id]);
            }
            $ins = $this->db->prepare("INSERT INTO lf_cotizacion_lineas (cotizacion_id, producto_id, nombre, cantidad, precio)
                                       VALUES (?,?,?,?,?)");
            foreach ($lineas as $l) $ins->execute(array_merge([$id], $l));
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $id;
    }

    /**
     * Enviada, rechazada o de vuelta a borrador. «Aceptada» no se pone a
     * mano: la pone la venta (aceptar()).
     */
    public function estado($id, $estado)
    {
        $c = $this->una($id);
        if (!$c) throw new \InvalidArgumentException('Esa cotización no existe.');
        $permitidos = [
            'borrador'  => ['enviada', 'rechazada'],
            'enviada'   => ['borrador', 'rechazada'],
            'rechazada' => ['borrador'],
            'aceptada'  => [],
        ];
        if (!in_array($estado, $permitidos[$c['estado']] ?? [], true)) {
            throw new \InvalidArgumentException($c['estado'] === 'aceptada'
                ? 'La cotización ya se volvió venta y no cambia.'
                : 'No se puede pasar de ' . mb_strtolower(self::ESTADOS[$c['estado']]) . ' a ' . mb_strtolower(self::ESTADOS[$estado] ?? $estado) . '.');
        }
        $this->db->prepare("UPDATE lf_cotizaciones SET estado = ?, actualizado_en = NOW() WHERE id = ?")
                 ->execute([$estado, (int)$id]);
        return $c;
    }

    /** La venta que la cerró. Solo si seguía abierta. */
    public function aceptar($id, $ventaId)
    {
        $st = $this->db->prepare("UPDATE lf_cotizaciones SET estado = 'aceptada', venta_id = ?, actualizado_en = NOW()
                                  WHERE id = ? AND estado IN ('borrador','enviada')");
        $st->execute([(int)$ventaId, (int)$id]);
        return $st->rowCount() > 0;
    }
}