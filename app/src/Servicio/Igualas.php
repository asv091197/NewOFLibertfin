<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Iva;
use LibertyFin\Dominio\Ticket;
use PDO;

/**
 * Igualas: lo que un cliente paga cada mes por el mismo servicio (la
 * contabilidad mensual, la asesoría, el mantenimiento).
 *
 * CÓMO FUNCIONA
 *
 * Cada iguala dice a quién, qué servicio, cuánto y qué día del mes se
 * cobra. Al llegar ese día se genera SOLA una venta sin anticipo: entra a
 * Cobranza con su saldo, y de ahí se cobra como cualquier otra.
 *
 * NUNCA DOS VECES EL MISMO MES
 *
 * `lf_iguala_ventas` tiene una llave única (iguala, mes). Antes de crear
 * la venta se "aparta" el mes con un INSERT: si dos personas abren el
 * sistema al mismo tiempo, solo una lo logra y la otra se lo salta. Si
 * la venta no se pudo crear, el apartado se borra y se reintenta después.
 *
 * CUÁNDO SE GENERAN
 *
 * No hay un proceso nocturno (el hospedaje compartido no siempre deja).
 * Se revisa al abrir Igualas, el Panel o Cobranza, una vez al día por
 * sesión. Lo atrasado (si nadie entró en días) se pone al corriente,
 * hasta 12 meses por iguala.
 */
final class Igualas
{
    const TOPE_MESES = 12;

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /** Revisa y genera lo que toque, a lo más una vez al día por sesión. */
    public static function alDia(PDO $db)
    {
        $hoy = date('Y-m-d');
        $clave = 'lf_igualas_' . ($_SESSION['empresa_db'] ?? '');
        if (($_SESSION[$clave] ?? '') === $hoy) return 0;
        try {
            if (!Modulos::deSesion()->activo('igualas')) return 0;
            $_SESSION[$clave] = $hoy;
            return count((new self($db))->generarPendientes());
        } catch (\Throwable $e) {
            error_log('[LibertyFin] igualas al día: ' . $e->getMessage());
            return 0;
        }
    }

    // ════════════════════════════════════════════════════════
    //  LEER
    // ════════════════════════════════════════════════════════

    public function listar()
    {
        $r = $this->db->query("
            SELECT i.*, c.nombre AS cliente, p.nombre AS producto, cc.nombre AS especialista,
                   (SELECT MAX(periodo) FROM lf_iguala_ventas iv WHERE iv.iguala_id = i.id) AS ultimo
            FROM lf_igualas i
            LEFT JOIN clientes c ON c.id = i.cliente_id
            LEFT JOIN productos p ON p.id = i.producto_id
            LEFT JOIN comision_colaboradores cc ON cc.id = i.especialista_id
            ORDER BY i.activo DESC, c.nombre, i.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($r as &$i) $i['siguiente'] = $this->siguiente($i);
        return $r;
    }

    public function una($id)
    {
        $st = $this->db->prepare("SELECT * FROM lf_igualas WHERE id = ?");
        $st->execute([(int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Lo generado: cada mes con su venta y lo que falta cobrar. */
    public function historial($tope = 60)
    {
        return $this->db->query("
            SELECT iv.periodo, iv.venta_id, iv.creado_en, i.concepto, c.nombre AS cliente, p.nombre AS producto,
                   v.codigo_venta, v.total, v.estado,
                   v.total - COALESCE((SELECT SUM(monto) FROM venta_pagos vp WHERE vp.venta_id = v.id AND vp.cancelado = 0),0) AS saldo
            FROM lf_iguala_ventas iv
            INNER JOIN lf_igualas i ON i.id = iv.iguala_id
            LEFT JOIN clientes c ON c.id = i.cliente_id
            LEFT JOIN productos p ON p.id = i.producto_id
            LEFT JOIN ventas v ON v.id = iv.venta_id
            ORDER BY iv.creado_en DESC, iv.id DESC
            LIMIT " . max(1, (int)$tope))->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Las de un cliente, para su ficha. */
    public function delCliente($clienteId)
    {
        $st = $this->db->prepare("SELECT i.*, p.nombre AS producto FROM lf_igualas i
                                  LEFT JOIN productos p ON p.id = i.producto_id
                                  WHERE i.cliente_id = ? ORDER BY i.activo DESC, i.id");
        $st->execute([(int)$clienteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Cuánto se factura al mes con las igualas activas. */
    public function mensual()
    {
        return (float)$this->db->query("SELECT COALESCE(SUM(monto),0) FROM lf_igualas WHERE activo = 1")->fetchColumn();
    }

    /** La fecha del próximo cargo (Y-m-d), o null si está pausada. */
    public function siguiente(array $i, $hoy = null)
    {
        if (empty($i['activo'])) return null;
        $hoy = $hoy ?: date('Y-m-d');
        // Lo que ya tocaba y falta generar va primero.
        $pend = $this->periodosPendientes($i, $hoy);
        if ($pend) return $this->fechaDe($pend[0], $i['dia']);
        // Si no, este mes (si su día no ha llegado) o el que sigue.
        $p = max((string)$i['desde'], substr($hoy, 0, 7));
        if ($this->fechaDe($p, $i['dia']) <= $hoy) $p = date('Y-m', strtotime($p . '-01 +1 month'));
        return $this->fechaDe($p, $i['dia']);
    }

    // ════════════════════════════════════════════════════════
    //  ESCRIBIR
    // ════════════════════════════════════════════════════════

    /**
     * @param array $d cliente_id, producto_id, monto, dia, especialista_id, concepto, desde (Y-m)
     */
    public function guardar(array $d, $id = 0, $usuario = '')
    {
        $id = (int)$id;
        $cliente = (int)($d['cliente_id'] ?? 0);
        $st = $this->db->prepare("SELECT id FROM clientes WHERE id = ?");
        $st->execute([$cliente]);
        if (!$cliente || !$st->fetchColumn()) throw new \InvalidArgumentException('Elige al cliente de la lista.');

        $producto = (int)($d['producto_id'] ?? 0);
        $st = $this->db->prepare("SELECT id FROM productos WHERE id = ?");
        $st->execute([$producto]);
        if (!$producto || !$st->fetchColumn()) throw new \InvalidArgumentException('Elige el servicio que se cobra.');

        $monto = round((float)str_replace(['$', ','], '', (string)($d['monto'] ?? 0)), 2);
        if ($monto <= 0) throw new \InvalidArgumentException('Escribe cuánto se cobra al mes.');
        $dia = (int)($d['dia'] ?? 1);
        if ($dia < 1 || $dia > 28) throw new \InvalidArgumentException('El día de cobro va del 1 al 28 (todos los meses lo tienen).');
        $desde = (string)($d['desde'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $desde)) $desde = date('Y-m');
        $esp = (int)($d['especialista_id'] ?? 0) ?: null;
        $concepto = mb_substr(trim((string)($d['concepto'] ?? '')), 0, 200) ?: null;

        if ($id) {
            if (!$this->una($id)) throw new \InvalidArgumentException('Esa iguala no existe.');
            $this->db->prepare("UPDATE lf_igualas SET cliente_id = ?, producto_id = ?, concepto = ?, monto = ?, dia = ?,
                                       especialista_id = ?, desde = ? WHERE id = ?")
                     ->execute([$cliente, $producto, $concepto, $monto, $dia, $esp, $desde, $id]);
            return $id;
        }
        $this->db->prepare("INSERT INTO lf_igualas (cliente_id, producto_id, concepto, monto, dia, especialista_id, desde,
                                                    activo, creado_por, creado_en)
                            VALUES (?,?,?,?,?,?,?,1,?,NOW())")
                 ->execute([$cliente, $producto, $concepto, $monto, $dia, $esp, $desde, mb_substr((string)$usuario, 0, 100) ?: null]);
        return (int)$this->db->lastInsertId();
    }

    /** Pausar o reanudar. Al reanudar no se cobran los meses de pausa. */
    public function alternar($id)
    {
        $i = $this->una($id);
        if (!$i) throw new \InvalidArgumentException('Esa iguala no existe.');
        $on = (int)$i['activo'] ? 0 : 1;
        if ($on) {
            // Lo que no se generó durante la pausa ya no se genera.
            $this->db->prepare("UPDATE lf_igualas SET activo = 1, desde = ? WHERE id = ?")
                     ->execute([max((string)$i['desde'], date('Y-m')), (int)$id]);
        } else {
            $this->db->prepare("UPDATE lf_igualas SET activo = 0 WHERE id = ?")->execute([(int)$id]);
        }
        return (bool)$on;
    }

    /**
     * Genera las ventas que ya tocan. Devuelve lo generado:
     * [['iguala' => id, 'periodo' => 'Y-m', 'venta' => id], ...]
     */
    public function generarPendientes($hoy = null)
    {
        $hoy = $hoy ?: date('Y-m-d');
        $hechas = [];
        $igualas = $this->db->query("
            SELECT i.*, p.nombre AS producto, p.costo, cc.nombre AS especialista
            FROM lf_igualas i
            INNER JOIN productos p ON p.id = i.producto_id
            INNER JOIN clientes c ON c.id = i.cliente_id
            LEFT JOIN comision_colaboradores cc ON cc.id = i.especialista_id
            WHERE i.activo = 1")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($igualas as $i) {
            foreach ($this->periodosPendientes($i, $hoy) as $periodo) {
                // Apartar el mes. Si otro ya lo apartó, la llave única lo frena.
                try {
                    $this->db->prepare("INSERT INTO lf_iguala_ventas (iguala_id, periodo, creado_en) VALUES (?,?,NOW())")
                             ->execute([(int)$i['id'], $periodo]);
                } catch (\PDOException $e) {
                    continue;
                }
                $apartado = (int)$this->db->lastInsertId();
                try {
                    $venta = $this->crearVenta($i, $periodo);
                    $this->db->prepare("UPDATE lf_iguala_ventas SET venta_id = ? WHERE id = ?")->execute([$venta, $apartado]);
                    $hechas[] = ['iguala' => (int)$i['id'], 'periodo' => $periodo, 'venta' => $venta];
                } catch (\Throwable $e) {
                    $this->db->prepare("DELETE FROM lf_iguala_ventas WHERE id = ?")->execute([$apartado]);
                    error_log('[LibertyFin] iguala ' . $i['id'] . ' · ' . $periodo . ': ' . $e->getMessage());
                }
            }
        }
        return $hechas;
    }

    /** Los meses que ya tocan y no se han generado, del más viejo al más nuevo. */
    private function periodosPendientes(array $i, $hoy)
    {
        $mesHoy = substr($hoy, 0, 7);
        $p = (string)$i['desde'];
        if (!preg_match('/^\d{4}-\d{2}$/', $p) || $p > $mesHoy) return [];
        $st = $this->db->prepare("SELECT periodo FROM lf_iguala_ventas WHERE iguala_id = ?");
        $st->execute([(int)$i['id']]);
        $ya = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        $r = [];
        // Como mucho los últimos TOPE_MESES: una iguala vieja no debe soltar años de cargos.
        $minimo = date('Y-m', strtotime($mesHoy . '-01 -' . (self::TOPE_MESES - 1) . ' months'));
        if ($p < $minimo) $p = $minimo;
        while ($p <= $mesHoy) {
            if (!isset($ya[$p]) && $this->fechaDe($p, $i['dia']) <= $hoy) $r[] = $p;
            $p = date('Y-m', strtotime($p . '-01 +1 month'));
        }
        return $r;
    }

    private function fechaDe($periodo, $dia)
    {
        return $periodo . '-' . str_pad((string)max(1, min(28, (int)$dia)), 2, '0', STR_PAD_LEFT);
    }

    /** La venta del mes, sin anticipo: entra a Cobranza con su saldo. */
    private function crearVenta(array $i, $periodo)
    {
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                  'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $nombreMes = $meses[(int)substr($periodo, 5, 2)] . ' ' . substr($periodo, 0, 4);
        $t = new Ticket(new Iva(0, Iva::INCLUIDO));
        $t->agregar((int)$i['producto_id'], $i['producto'], (float)$i['monto'], 1, 0, (float)($i['costo'] ?? 0));
        $r = (new RegistrarVenta($this->db))->cobrar($t, [
            'cliente_id'          => (int)$i['cliente_id'],
            'usuario_id'          => $_SESSION['usuario_id'] ?? null,
            'especialista_id'     => $i['especialista_id'] ?: null,
            'especialista_nombre' => $i['especialista'] ?: null,
            'sucursal_id'         => $_SESSION['sucursal_id'] ?? null,
            'caja_id'             => null,
            'anticipo'            => 0,
            'metodo_pago'         => 'efectivo',
            'referencia'          => '',
            'descripcion'         => 'Iguala de ' . $nombreMes . ($i['concepto'] ? ' · ' . $i['concepto'] : ''),
        ]);
        return (int)$r['id'];
    }
}
