<?php
namespace LibertyFin\Api\Controlador;

use LibertyFin\Api\Contexto;
use LibertyFin\Api\ErrorApi;
use LibertyFin\Api\Formato;
use LibertyFin\Api\IdempotenciaRepo;
use LibertyFin\Api\Respuesta;
use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Datos\VentaRepo;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Servicio\Auditoria;
use LibertyFin\Servicio\RegistrarPago;

/**
 * Ventas por la API.
 *
 *   GET  /api/v1/ventas               lista paginada, con filtros
 *   GET  /api/v1/ventas/{id}          una venta con sus renglones y cobros
 *   POST /api/v1/ventas/{id}/abonos   registra un abono
 *
 * El abono pasa por Servicio\RegistrarPago, el mismo que usa la pantalla:
 * no se puede abonar más que el saldo, lleva su subfolio y resincroniza
 * las comisiones. La API solo agrega la protección contra reenvíos.
 */
final class VentasControlador
{
    /** Cómo se llama cada estado en la API → cómo lo llama VentaRepo. */
    const ESTADOS = ['liquidada' => 'liquidada', 'con_saldo' => 'debe', 'cancelada' => 'cancelada'];

    public function listar(Contexto $c)
    {
        $e = $c->entrada;
        $desde = $e->fecha('desde');
        $hasta = $e->fecha('hasta');
        if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
            throw new ErrorApi(422, 'parametro_invalido', '«desde» no puede ser posterior a «hasta».',
                ['parametro' => 'desde']);
        }
        $estado = $e->opcion('estado', array_keys(self::ESTADOS));
        $filtros = ['estado' => $estado !== '' ? self::ESTADOS[$estado] : '', 'buscar' => $e->texto('q')];

        $repo = new VentaRepo($c->db);
        $total = $repo->cuantas($desde, $hasta, $filtros);
        $filas = $repo->listado($desde, $hasta, $filtros, $e->porPagina(), $c->desfase());
        return Respuesta::lista(array_map([Formato::class, 'ventaResumen'], $filas), $c->paginacion($total));
    }

    public function ver(Contexto $c, $id)
    {
        $repo = new VentaRepo($c->db);
        $v = $this->venta($repo, $id);
        return Respuesta::dato(Formato::venta($v, $repo->lineas($id), $repo->pagos($id),
            $c->puede('ver.comisiones')));
    }

    /**
     * Registra un abono.
     *
     * Cuerpo: {"monto": 500.0, "metodo": "transferencia",
     *          "fecha": "2026-10-16", "referencia": "…", "banco": "…"}
     *
     * Con la cabecera Idempotency-Key, un reenvío con la misma clave y el
     * mismo cuerpo recibe la respuesta original en vez de un segundo abono.
     */
    public function abonar(Contexto $c, $id)
    {
        $e = $c->entrada;
        $clave = $e->encabezado('Idempotency-Key');
        if ($clave !== '' && !IdempotenciaRepo::claveValida($clave)) {
            throw new ErrorApi(422, 'idempotency_key_invalida',
                'La cabecera Idempotency-Key admite de 1 a 100 letras, números, «-», «_», «.» o «:».');
        }
        $datos = $this->leerAbono($c);

        $repo = new VentaRepo($c->db);
        $this->venta($repo, $id);

        // La protección contra reenvíos va ANTES de revisar el saldo: el
        // reenvío de un abono que liquidó la venta debe recibir su respuesta
        // original, no "esta venta ya está liquidada".
        $idem = new IdempotenciaRepo($c->db);
        $llaveId = (int)$c->llave['id'];
        if ($clave !== '') {
            $previo = $idem->reservar($llaveId, $clave, $e->huella());
            if ($previo !== null) return $this->repetir($previo, $e->huella());
        }

        try {
            $v = $repo->detalle($id);
            $this->revisarSaldo($v, $datos['monto']);
            $r = (new RegistrarPago($c->db))->abonar($id, $datos + ['usuario_id' => null]);
        } catch (ErrorApi $err) {
            if ($clave !== '') $idem->liberar($llaveId, $clave);
            throw $err;
        } catch (\InvalidArgumentException $err) {
            if ($clave !== '') $idem->liberar($llaveId, $clave);
            throw new ErrorApi(422, 'abono_invalido', $err->getMessage());
        } catch (\Throwable $err) {
            if ($clave !== '') $idem->liberar($llaveId, $clave);
            throw $err;
        }

        Auditoria::anota('pago.registrar', 'venta ' . $v['codigo_venta'] . ' · por la API',
            null, Dinero::pesos($r['monto']) . ' · ' . $datos['metodo']);

        $pago = null;
        foreach ($repo->pagos($id) as $p) if ((int)$p['id'] === (int)$r['pago_id']) $pago = $p;
        $resp = Respuesta::dato([
            'pago'  => Formato::pago($pago, $c->puede('ver.comisiones')),
            'venta' => Formato::ventaResumen($repo->detalle($id)),
        ], 201)->con('Location', '/api/v1/ventas/' . (int)$id);

        if ($clave !== '') $idem->completar($llaveId, $clave, $resp);
        return $resp;
    }

    private function venta(VentaRepo $repo, $id)
    {
        $v = $repo->detalle($id);
        if (!$v) throw new ErrorApi(404, 'venta_no_encontrada', 'No existe una venta con ese id.');
        return $v;
    }

    /** Valida el cuerpo del abono. No toca la base. */
    private function leerAbono(Contexto $c)
    {
        $b = $c->entrada->cuerpo();
        $monto = $b['monto'] ?? null;
        if (!is_int($monto) && !is_float($monto)) {
            throw new ErrorApi(422, 'monto_invalido', '«monto» es obligatorio y debe ser un número (500 o 500.5).',
                ['campo' => 'monto']);
        }
        if ($monto <= 0 || abs(round($monto, 2) - $monto) > 0.0000001) {
            throw new ErrorApi(422, 'monto_invalido', '«monto» debe ser mayor a cero y con máximo dos decimales.',
                ['campo' => 'monto']);
        }

        // Los mismos métodos que ofrece la pantalla de esta empresa.
        $this->cargarInterruptores();
        $disponibles = (new ConfigRepo($c->db))->metodosDisponibles();
        $metodo = (string)($b['metodo'] ?? 'efectivo');
        if (!in_array($metodo, $disponibles, true)) {
            throw new ErrorApi(422, 'metodo_no_disponible', "Esta empresa no cobra con «{$metodo}».",
                ['campo' => 'metodo', 'disponibles' => $disponibles]);
        }

        $fecha = (string)($b['fecha'] ?? '');
        if ($fecha !== '') {
            $d = \DateTime::createFromFormat('Y-m-d', $fecha);
            if (!$d || $d->format('Y-m-d') !== $fecha) {
                throw new ErrorApi(422, 'fecha_invalida', '«fecha» debe ser AAAA-MM-DD.', ['campo' => 'fecha']);
            }
            if ($fecha > date('Y-m-d')) {
                throw new ErrorApi(422, 'fecha_invalida', 'No se puede registrar un abono con fecha futura.',
                    ['campo' => 'fecha']);
            }
        }

        $texto = function ($campo) use ($b) {
            $v = $b[$campo] ?? '';
            if (!is_string($v)) throw new ErrorApi(422, 'campo_invalido', "«{$campo}» debe ser texto.", ['campo' => $campo]);
            $v = trim($v);
            if (mb_strlen($v) > 100) {
                throw new ErrorApi(422, 'campo_invalido', "«{$campo}» no puede pasar de 100 caracteres.", ['campo' => $campo]);
            }
            return $v;
        };

        return [
            'monto'      => Dinero::centavos($monto),
            'metodo'     => $metodo,
            'fecha'      => $fecha,
            'referencia' => $texto('referencia'),
            // En efectivo no hay banco, igual que en la pantalla.
            'banco'      => $metodo === 'efectivo' ? '' : $texto('banco'),
        ];
    }

    private function revisarSaldo(array $v, $monto)
    {
        if ($v['estado'] === 'cancelada') {
            throw new ErrorApi(409, 'venta_cancelada', 'La venta está cancelada: no admite abonos.');
        }
        $saldo = Dinero::centavos($v['saldo']);
        if ($saldo <= 0.005) {
            throw new ErrorApi(409, 'venta_liquidada', 'La venta ya está liquidada.');
        }
        if ($monto > $saldo) {
            throw new ErrorApi(422, 'abono_mayor_al_saldo',
                'El abono (' . Dinero::pesos($monto) . ') supera el saldo (' . Dinero::pesos($saldo) . ').',
                ['saldo' => $saldo]);
        }
    }

    /** La respuesta de una petición que ya se había hecho con esa clave. */
    private function repetir(array $previo, $huella)
    {
        if (!hash_equals((string)$previo['huella'], (string)$huella)) {
            throw new ErrorApi(422, 'idempotencia_en_conflicto',
                'Esa Idempotency-Key ya se usó con un cuerpo distinto. Usa una clave nueva por operación.');
        }
        if ($previo['estado'] !== 'listo') {
            throw new ErrorApi(409, 'en_proceso',
                'Una petición con esa Idempotency-Key todavía se está procesando. Reintenta en unos segundos.');
        }
        $r = new Respuesta((int)$previo['http_estado'], json_decode((string)$previo['respuesta'], true) ?: []);
        return $r->con('Idempotent-Replayed', 'true');
    }

    /**
     * Los métodos apagados para toda la plataforma. En pantalla se leen al
     * entrar; la API no tiene "entrar", así que se leen aquí.
     */
    private function cargarInterruptores()
    {
        if (isset($_SESSION['lf_global'])) return;
        try {
            $_SESSION['lf_global'] = (new \LibertyFin\Datos\AjustesPlataformaRepo(
                \LibertyFin\Datos\Conexion::de($GLOBALS['lf_bd_principal'] ?? '')))->paraSesion();
        } catch (\Throwable $e) {
            $_SESSION['lf_global'] = [];
        }
    }
}
