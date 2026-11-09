<?php
namespace LibertyFin\Datos;

use PDO;

/**
 * Planes y pagos de plan. Viven en la base PRINCIPAL.
 *
 * El CATÁLOGO (qué planes hay y cuánto cuestan) sale de config/planes.php,
 * no de la base: son precios que decide quien administra la plataforma y
 * cambian pocas veces, y así no hay una pantalla más que mantener ni
 * precios inventados en una tabla.
 *
 * El PAGO es por transferencia con comprobante:
 *
 *   por_pagar    la empresa eligió un plan y todavía no manda el comprobante
 *   en_revision  mandó el comprobante; falta que lo vea alguien de plataforma
 *   aprobado     se verificó el dinero: el plan y el vencimiento ya cambiaron
 *   rechazado    el comprobante no sirvió; se dice por qué
 *   cancelado    la empresa eligió otro plan antes de pagar
 *
 * El vencimiento SOLO se mueve al aprobar, y dentro de una transacción
 * con el cambio de estado: así un pago no se puede aprobar dos veces ni
 * sumar meses de más.
 */
final class PlanRepo
{
    private $principal;
    public function __construct(PDO $principal) { $this->principal = $principal; }

    /**
     * El catálogo y los datos para transferir, o null si config/planes.php
     * no existe o no es válido.
     * @return array|null ['planes' => [clave => [...]], 'cuenta' => [...]]
     */
    public static function catalogo()
    {
        $ruta = dirname(__DIR__, 2) . '/config/planes.php';
        if (!is_file($ruta)) return null;
        $c = include $ruta;
        if (!is_array($c) || empty($c['planes']) || !is_array($c['planes'])) return null;

        // Descuento por pagar el año completo, en %. Se limita para que un
        // error de captura no regale el plan.
        $desc = max(0, min(60, (float)($c['descuento_anual'] ?? 0)));

        $planes = [];
        foreach ($c['planes'] as $clave => $p) {
            if (!preg_match('/^[a-z0-9_-]{1,40}$/', (string)$clave)) continue;
            if (empty($p['nombre']) || !isset($p['precio']) || (float)$p['precio'] <= 0) continue;
            $mensual = round((float)$p['precio'], 2);
            $anual   = round($mensual * 12 * (1 - $desc / 100), 2);

            // Características agrupadas: ['Punto de venta' => ['1 caja', ...]].
            // `incluye` (lista plana) sigue valiendo y cae en un grupo sin título.
            $grupos = [];
            foreach ((array)($p['grupos'] ?? []) as $titulo => $items) {
                $items = array_values(array_filter((array)$items));
                if ($items) $grupos[(string)$titulo] = $items;
            }
            if (!$grupos && !empty($p['incluye'])) $grupos[''] = array_values(array_filter((array)$p['incluye']));

            $planes[$clave] = [
                'nombre'   => (string)$p['nombre'],
                'precio'   => $mensual,                      // por mes
                'usuarios' => (int)($p['usuarios'] ?? 0),
                'popular'  => !empty($p['popular']),
                'grupos'   => $grupos,
                // Los módulos que incluye (Servicio\Modulos). Sin la llave,
                // todos: así se comportaba antes de que hubiera módulos.
                'modulos'  => isset($p['modulos']) ? array_values((array)$p['modulos']) : null,
                'periodos' => [
                    'mensual' => ['monto' => $mensual, 'meses' => 1,  'por_mes' => $mensual],
                    'anual'   => ['monto' => $anual,   'meses' => 12, 'por_mes' => round($anual / 12, 2)],
                ],
            ];
        }
        if (!$planes) return null;
        return ['planes' => $planes, 'cuenta' => (array)($c['cuenta'] ?? []),
                'descuento_anual' => $desc,
                'leyenda' => (string)($c['leyenda'] ?? '')];
    }

    /**
     * Cuántos usuarios ACTIVOS permite el plan de la empresa; 0 = sin tope.
     *
     * Se lee el plan de la base principal, no de la sesión: al aprobarse un
     * pago el plan cambia, y la sesión sigue con el de cuando entró. Un plan
     * que no está en config/planes.php (la prueba, por ejemplo) o sin
     * `usuarios` no tiene tope.
     */
    public function usuariosPermitidos($empresaId)
    {
        try {
            $st = $this->principal->prepare("SELECT plan FROM empresas WHERE id = ?");
            $st->execute([(int)$empresaId]);
            $clave = (string)$st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
        $cat = self::catalogo();
        return (int)($cat['planes'][$clave]['usuarios'] ?? 0);
    }

    /** El nombre del plan de la empresa, para los mensajes. */
    public function nombrePlan($empresaId)
    {
        try {
            $st = $this->principal->prepare("SELECT plan FROM empresas WHERE id = ?");
            $st->execute([(int)$empresaId]);
            $clave = (string)$st->fetchColumn();
        } catch (\Throwable $e) {
            return '';
        }
        $cat = self::catalogo();
        return (string)($cat['planes'][$clave]['nombre'] ?? $clave);
    }

    /** Se crea sola la primera vez. */
    private function asegurar()
    {
        $this->principal->exec("
            CREATE TABLE IF NOT EXISTS pagos_plan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                empresa_id INT NOT NULL,
                plan VARCHAR(40) NOT NULL,
                nombre_plan VARCHAR(120) NOT NULL,
                meses INT NOT NULL DEFAULT 1,
                monto DECIMAL(12,2) NOT NULL,
                referencia VARCHAR(40) NULL,
                comprobante VARCHAR(255) NULL,
                estado VARCHAR(20) NOT NULL DEFAULT 'por_pagar',
                motivo_rechazo VARCHAR(400) NULL,
                creado_en DATETIME NOT NULL,
                enviado_en DATETIME NULL,
                resuelto_en DATETIME NULL,
                resuelto_por INT NULL,
                KEY ix_pp_empresa (empresa_id),
                KEY ix_pp_estado (estado),
                KEY ix_pp_referencia (referencia)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Historial de una empresa, lo más reciente primero. */
    public function deEmpresa($empresaId, $tope = 20)
    {
        $this->asegurar();
        $st = $this->principal->prepare(
            "SELECT * FROM pagos_plan WHERE empresa_id = ? ORDER BY id DESC LIMIT " . (int)$tope);
        $st->execute([(int)$empresaId]);
        return $st->fetchAll();
    }

    /** Un pago, solo si es de esa empresa. */
    public function uno($id, $empresaId)
    {
        $this->asegurar();
        $st = $this->principal->prepare("SELECT * FROM pagos_plan WHERE id = ? AND empresa_id = ?");
        $st->execute([(int)$id, (int)$empresaId]);
        return $st->fetch() ?: null;
    }

    /**
     * La empresa elige un plan. Si ya había uno sin pagar, se cancela:
     * dos pagos abiertos a la vez confunden a quien revisa.
     * @throws \InvalidArgumentException
     */
    public function solicitar($empresaId, $clave, $periodo = 'mensual')
    {
        $cat = self::catalogo();
        if (!$cat || !isset($cat['planes'][$clave])) {
            throw new \InvalidArgumentException('Ese plan no existe');
        }
        // El monto y los meses salen del catálogo, NUNCA de lo que mande el
        // navegador: el cliente solo elige plan y periodo.
        if (!in_array($periodo, ['mensual', 'anual'], true)) $periodo = 'mensual';
        $this->asegurar();
        $pl = $cat['planes'][$clave];
        $p  = [
            'nombre' => $pl['nombre'] . ($periodo === 'anual' ? ' · anual' : ' · mensual'),
            'precio' => $pl['periodos'][$periodo]['monto'],
            'meses'  => $pl['periodos'][$periodo]['meses'],
        ];

        $this->principal->beginTransaction();
        try {
            $this->principal->prepare(
                "UPDATE pagos_plan SET estado = 'cancelado'
                  WHERE empresa_id = ? AND estado = 'por_pagar'")->execute([(int)$empresaId]);
            $this->principal->prepare("
                INSERT INTO pagos_plan (empresa_id, plan, nombre_plan, meses, monto, estado, creado_en)
                VALUES (?,?,?,?,?,'por_pagar',NOW())"
            )->execute([(int)$empresaId, $clave, $p['nombre'], $p['meses'], $p['precio']]);
            $id = (int)$this->principal->lastInsertId();
            // La referencia va en el concepto de la transferencia: es lo
            // que permite saber de quién es cada depósito.
            $ref = 'LF-' . (int)$empresaId . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
            $this->principal->prepare("UPDATE pagos_plan SET referencia = ? WHERE id = ?")
                            ->execute([$ref, $id]);
            $this->principal->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->principal->rollBack();
            throw $e;
        }
    }

    /** Guarda el comprobante y lo manda a revisión. */
    public function comprobante($id, $empresaId, $ruta)
    {
        $pago = $this->uno($id, $empresaId);
        if (!$pago || !in_array($pago['estado'], ['por_pagar', 'rechazado'], true)) {
            throw new \InvalidArgumentException('Ese pago ya no acepta comprobante');
        }
        $this->principal->prepare("
            UPDATE pagos_plan SET comprobante = ?, estado = 'en_revision',
                   enviado_en = NOW(), motivo_rechazo = NULL
             WHERE id = ? AND empresa_id = ?")
            ->execute([$ruta, (int)$id, (int)$empresaId]);
        return $pago;
    }

    /**
     * Guarda en el pago la referencia que devolvió el proveedor de cobro.
     *
     * Solo se persiste el VALOR de `referencia`; el resto de datos de la
     * liga (liga, clabe, barras, imagen, vence, pruebas…) viven en el
     * directorio de ligas (LigaRepo) y no duplicamos aquí.
     */
    public function guardarLiga($pagoId, array $datos)
    {
        $ref = trim((string)($datos['referencia'] ?? ''));
        if ($ref === '') return false;

        $this->asegurar();
        $st = $this->principal->prepare(
            "UPDATE pagos_plan SET referencia = ? WHERE id = ?");
        $st->execute([$ref, (int)$pagoId]);
        return $st->rowCount() > 0;
    }

    /** Lo que espera revisión, de todas las empresas. */
    public function porRevisar()
    {
        $this->asegurar();
        return $this->principal->query("
            SELECT p.*, e.nombre_empresa, e.plan AS plan_actual, e.fecha_vencimiento,
                   TIMESTAMPDIFF(HOUR, p.enviado_en, NOW()) AS horas
              FROM pagos_plan p
              JOIN empresas e ON e.id = p.empresa_id
             WHERE p.estado = 'en_revision'
             ORDER BY p.enviado_en ASC")->fetchAll();
    }

    /**
     * Aprueba o rechaza. Al aprobar, el plan cambia y el vencimiento
     * se extiende desde el que sea MÁS TARDE entre hoy y el actual: quien
     * paga antes de vencer no pierde los días que le quedaban, y quien
     * paga ya vencido cuenta desde hoy.
     * @return array el pago, para avisar a quien corresponda
     * @throws \InvalidArgumentException
     */
    public function resolver($id, $decision, $motivo, $porUsuario)
    {
        if (!in_array($decision, ['aprobado', 'rechazado'], true)) {
            throw new \InvalidArgumentException('Decisión no válida');
        }
        $motivo = trim((string)$motivo);
        if ($decision === 'rechazado' && $motivo === '') {
            throw new \InvalidArgumentException('Escribe el motivo del rechazo');
        }
        $this->asegurar();

        $this->principal->beginTransaction();
        try {
            $st = $this->principal->prepare(
                "SELECT * FROM pagos_plan WHERE id = ? AND estado = 'en_revision' FOR UPDATE");
            $st->execute([(int)$id]);
            $pago = $st->fetch();
            if (!$pago) throw new \InvalidArgumentException('Ese pago ya fue revisado');

            // A quién avisar: el correo del administrador de la empresa.
            $st = $this->principal->prepare(
                "SELECT nombre_empresa, nombre_contacto, email_admin FROM empresas WHERE id = ?");
            $st->execute([(int)$pago['empresa_id']]);
            $pago += (array)($st->fetch() ?: []);

            if ($decision === 'aprobado') {
                $st = $this->principal->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = ?");
                $st->execute([(int)$pago['empresa_id']]);
                $actual = $st->fetchColumn();
                $hoy  = new \DateTimeImmutable('today');
                $base = ($actual && strtotime($actual) > $hoy->getTimestamp())
                      ? new \DateTimeImmutable($actual) : $hoy;
                $nuevo = $base->modify('+' . (int)$pago['meses'] . ' months')->format('Y-m-d');

                $this->principal->prepare(
                    "UPDATE empresas SET plan = ?, fecha_vencimiento = ? WHERE id = ?")
                    ->execute([$pago['plan'], $nuevo, (int)$pago['empresa_id']]);
                $pago['vence_nuevo'] = $nuevo;
            }

            $this->principal->prepare("
                UPDATE pagos_plan SET estado = ?, motivo_rechazo = ?, resuelto_en = NOW(), resuelto_por = ?
                 WHERE id = ?")
                ->execute([$decision, $decision === 'rechazado' ? $motivo : null,
                           (int)$porUsuario, (int)$id]);
            $this->principal->commit();
            return $pago;
        } catch (\Throwable $e) {
            $this->principal->rollBack();
            throw $e;
        }
    }

    /**
     * Aprueba un pago de plan cuando el cobro en línea ya se confirmó.
     *
     * A diferencia de `resolver()`, que espera un pago en `en_revision`
     * (transferencia + comprobante revisado por una persona), aquí el
     * pago sigue en `por_pagar`: el usuario nunca subió nada porque el
     * proveedor avisó que el cargo ya entró. Por eso se busca por
     * `referencia` y se acepta el estado actual tal como esté.
     *
     * Hace exactamente lo mismo que `resolver()` al aprobar:
     *   · cambia el plan y extiende el vencimiento desde el que sea MÁS
     *     TARDE entre hoy y el actual;
     *   · marca el pago como `aprobado` y sella `resuelto_en`;
     *   · TODO dentro de una transacción, para que no se pueda aprobar
     *     dos veces ni sumar meses de más si el proveedor avisa varias
     *     veces del mismo cobro.
     *
     * @param string   $referencia  la referencia del cobro (la que viaja en los avisos)
     * @param int|null $porUsuario  quién disparó la aprobación; null = automático
     * @return array|null  el pago aprobado, o null si no había nada que aprobar
     */
    public function aprobarPorPago($referencia, $porUsuario = null)
    {
        $referencia = trim((string)$referencia);
        if ($referencia === '') return null;

        $this->asegurar();
        $this->principal->beginTransaction();
        try {
            // `por_pagar` cubre el caso normal (nadie subió comprobante);
            // `en_revision` cubre el raro en que alguien subió uno y
            // además entró el cargo por línea: gana la confirmación del
            // proveedor y se aprueba igual.
            $st = $this->principal->prepare(
                "SELECT * FROM pagos_plan
                  WHERE referencia = ?
                    AND estado IN ('por_pagar', 'en_revision')
                  ORDER BY id DESC
                  LIMIT 1
                  FOR UPDATE");
            $st->execute([$referencia]);
            $pago = $st->fetch();
            if (!$pago) {
                $this->principal->rollBack();
                return null;
            }

            // Mismo enriquecimiento que hace `resolver()`: a quién avisar.
            $st = $this->principal->prepare(
                "SELECT nombre_empresa, nombre_contacto, email_admin FROM empresas WHERE id = ?");
            $st->execute([(int)$pago['empresa_id']]);
            $pago += (array)($st->fetch() ?: []);

            // Mismo cálculo de vencimiento que `resolver()`: desde el que
            // sea más tarde entre hoy y el actual.
            $st = $this->principal->prepare("SELECT fecha_vencimiento FROM empresas WHERE id = ?");
            $st->execute([(int)$pago['empresa_id']]);
            $actual = $st->fetchColumn();
            $hoy  = new \DateTimeImmutable('today');
            $base = ($actual && strtotime($actual) > $hoy->getTimestamp())
                  ? new \DateTimeImmutable($actual) : $hoy;
            $nuevo = $base->modify('+' . (int)$pago['meses'] . ' months')->format('Y-m-d');

            $this->principal->prepare(
                "UPDATE empresas SET plan = ?, fecha_vencimiento = ? WHERE id = ?")
                ->execute([$pago['plan'], $nuevo, (int)$pago['empresa_id']]);

            $this->principal->prepare("
                UPDATE pagos_plan
                   SET estado = 'aprobado',
                       motivo_rechazo = NULL,
                       resuelto_en = NOW(),
                       resuelto_por = ?
                 WHERE id = ?")
                ->execute([$porUsuario !== null ? (int)$porUsuario : null, (int)$pago['id']]);

            $this->principal->commit();

            $pago['estado']      = 'aprobado';
            $pago['vence_nuevo'] = $nuevo;
            return $pago;
        } catch (\Throwable $e) {
            $this->principal->rollBack();
            throw $e;
        }
    }
}