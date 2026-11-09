<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\Conexion;
use PDO;

/**
 * Los accesos de una empresa ya creada: reenviarlos, y darla de baja.
 *
 * POR QUÉ HACE FALTA REENVIAR
 *
 * Al aprobar una empresa se genera una contraseña, se manda por correo y
 * se enseña UNA vez en pantalla. Eso funciona mientras el correo salga.
 * Cuando no sale —el proveedor bloquea la cuenta por spam, el mensaje
 * cae en la carpeta de no deseado, el cliente lo borra sin leerlo— queda
 * una empresa creada y correcta a la que nadie puede entrar, y la
 * contraseña no se puede recuperar: está guardada como hash, que es
 * precisamente lo que debe ser.
 *
 * Así que no se "reenvía" la contraseña anterior. Se genera una nueva, se
 * guarda, y se entrega. La vieja deja de servir en ese momento, y eso es
 * lo correcto: si se fue en un correo que acabó en spam, no se sabe quién
 * más lo vio.
 *
 * POR QUÉ BORRAR ES OTRA COSA
 *
 * Esta pantalla decía, con razón, que no se podía eliminar una empresa:
 * sus ventas y facturas hay que conservarlas cinco años, y la bitácora
 * existe para contestar "¿quién hizo esto?".
 *
 * Ese argumento vale para una empresa que OPERÓ. No vale para una que se
 * creó hace diez minutos, nunca recibió su correo y no tiene una sola
 * venta: eso no es un registro que haya que conservar, es un error de
 * captura. La diferencia la decide el sistema contando, no la persona
 * prometiendo que "esa no se usó".
 */
final class AccesosEmpresa
{
    private $principal;
    public function __construct(PDO $principal) { $this->principal = $principal; }

    /** Los datos de la empresa, o null. */
    public function empresa($id)
    {
        $st = $this->principal->prepare("SELECT * FROM empresas WHERE id = ?");
        $st->execute([(int)$id]);
        return $st->fetch() ?: null;
    }

    /**
     * El nombre de la base, verificado.
     *
     * Va a parar dentro de un nombre de base de datos, que no se puede
     * mandar como parámetro: si aquí entrara cualquier cosa, entraría
     * también en la consulta.
     */
    private static function base(array $e)
    {
        $b = (string)($e['nombre_base_datos'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $b)) {
            throw new \RuntimeException('El nombre de base «' . $b . '» no es válido');
        }
        return $b;
    }

    /**
     * Contraseña nueva para el administrador de una empresa.
     *
     * Devuelve la contraseña EN CLARO una sola vez, para entregarla. No
     * se guarda así en ningún lado: lo que queda en la base es el hash.
     *
     * El correo puede fallar y la operación sigue siendo buena: la
     * contraseña ya está cambiada y quien la pidió la tiene en pantalla.
     * Por eso `correo_ok` es un dato del resultado y no una excepción.
     */
    public function regenerar($id, $urlLogin = '', $mandarCorreo = true)
    {
        $e = $this->empresa($id);
        if (!$e) throw new \InvalidArgumentException('Esa empresa no existe');
        $base = self::base($e);
        $db = Conexion::de($base);

        // A quién. Primero el que quedó anotado al crearla; si ese usuario
        // ya no está (lo borraron, lo renombraron), el admin más antiguo.
        $usuario = null;
        $anotado = trim((string)($e['usuario_admin'] ?? ''));
        if ($anotado !== '') {
            $st = $db->prepare("SELECT * FROM usuarios WHERE username = ? LIMIT 1");
            $st->execute([$anotado]);
            $usuario = $st->fetch() ?: null;
        }
        if (!$usuario) {
            $st = $db->query("SELECT * FROM usuarios WHERE rol = 'admin' ORDER BY id LIMIT 1");
            $usuario = $st->fetch() ?: null;
        }
        if (!$usuario) {
            throw new \RuntimeException(
                'La base ' . $base . ' no tiene ningún usuario administrador. '
                . 'La empresa está creada pero sin nadie que pueda entrar.');
        }

        $clave = self::clave();
        $db->prepare("UPDATE usuarios SET password = ?, activo = 1 WHERE id = ?")
           ->execute([password_hash($clave, PASSWORD_DEFAULT), $usuario['id']]);

        // Si quedó anotado otro nombre en `empresas`, se corrige: esa
        // columna es lo que mira la siguiente persona que venga a ayudar.
        if ($anotado !== $usuario['username']) {
            $this->principal->prepare("UPDATE empresas SET usuario_admin = ? WHERE id = ?")
                 ->execute([$usuario['username'], (int)$e['id']]);
        }

        $para = trim((string)($usuario['email'] ?? '')) ?: trim((string)($e['email_admin'] ?? ''));
        $correoOk = false;
        $correoError = '';
        // Se puede pedir que NO se intente mandar. Sirve cuando el
        // proveedor bloqueó la cuenta y cada intento fallido alarga el
        // bloqueo: ahí la contraseña se cambia y se dicta por teléfono.
        if (!$mandarCorreo) {
            $correoError = 'no se intentó mandar';
        } elseif ($para !== '') {
            try {
                $correoOk = Avisos::cuentaCreada(
                    $para, $e['nombre_contacto'] ?: $usuario['nombre'], $e['nombre_empresa'],
                    $usuario['username'], $clave, $urlLogin ?: '/login');
                if (!$correoOk) $correoError = 'el correo no salió (revisa bin/revisar-correo.php)';
            } catch (\Throwable $ex) {
                $correoError = $ex->getMessage();
                error_log('[LibertyFin] reenviar accesos: ' . $ex->getMessage());
            }
        } else {
            $correoError = 'la empresa no tiene correo anotado';
        }

        return ['empresa' => $e['nombre_empresa'], 'base' => $base,
                'usuario' => $usuario['username'], 'clave' => $clave,
                'email' => $para, 'correo_ok' => $correoOk, 'correo_error' => $correoError];
    }

    /**
     * ¿Esta empresa ya operó?
     *
     * Lo que cuenta es lo que no se puede volver a hacer: ventas, cobros,
     * clientes, movimientos de caja e inventario. Que tenga sucursal y un
     * usuario no cuenta — eso lo pone el alta, no la empresa.
     *
     * Una tabla que no existe cuenta como cero y no como error: una base
     * a medio crear es justamente de las que hay que poder borrar.
     *
     * @return array nombre de la tabla => cuántos renglones (solo > 0)
     */
    public function actividad($id)
    {
        $e = $this->empresa($id);
        if (!$e) throw new \InvalidArgumentException('Esa empresa no existe');
        $db = Conexion::de(self::base($e));

        $r = [];
        foreach (['ventas', 'venta_pagos', 'clientes', 'productos', 'compras',
                  'movimientos_caja', 'movimientos_inventario', 'gastos',
                  'venta_comisiones', 'lf_auditoria'] as $t) {
            try {
                $n = (int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
                if ($n > 0) $r[$t] = $n;
            } catch (\Throwable $ex) { /* sin tabla, nada que conservar */ }
        }
        // Más de un usuario quiere decir que alguien entró y dio de alta a
        // su gente: ya no es la empresa recién creada que nadie tocó.
        try {
            $n = (int)$db->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
            if ($n > 1) $r['usuarios'] = $n;
        } catch (\Throwable $ex) { /* ídem */ }
        return $r;
    }

    /**
     * Da de baja una empresa que nunca operó.
     *
     * NO BORRA LA BASE DE DATOS. Quita el renglón de `empresas`, que es lo
     * que la hace existir para el sistema, y devuelve el nombre de la base
     * para darla de baja en cPanel a mano.
     *
     * Es deliberado. Soltar un DROP DATABASE desde un botón web es
     * irreversible y no tiene deshacer; dejar la base huérfana cuesta unos
     * megas y se arregla cuando a uno ya no le tiemble el pulso. Además la
     * API de cPanel aquí no siempre contesta, y un borrado a medias —el
     * renglón fuera, la base dentro, nadie sabe de quién era— es peor que
     * cualquiera de los dos extremos.
     *
     * @param string $confirmacion el nombre de la empresa, escrito otra vez
     */
    public function eliminar($id, $confirmacion, $forzar = false)
    {
        $e = $this->empresa($id);
        if (!$e) throw new \InvalidArgumentException('Esa empresa no existe');

        // Escribir el nombre no es un trámite: es el paso que separa
        // "quería borrar esta" de "le di al renglón de abajo".
        if (self::normal((string)$confirmacion) !== self::normal((string)$e['nombre_empresa'])) {
            throw new \InvalidArgumentException(
                'Para eliminarla hay que escribir su nombre tal cual: ' . $e['nombre_empresa']);
        }

        $actividad = $this->actividad($id);
        if ($actividad && !$forzar) {
            $partes = [];
            foreach ($actividad as $t => $n) $partes[] = $n . ' ' . str_replace('_', ' ', $t);
            throw new \RuntimeException(
                $e['nombre_empresa'] . ' ya operó: tiene ' . implode(', ', $partes) . '. '
                . 'Una empresa con movimientos no se elimina —sus ventas y facturas hay que '
                . 'conservarlas— pero sí se puede suspender: deja de entrar y sus datos quedan.');
        }

        // Se anota ANTES de quitarla: después ya no hay de quién hablar.
        try {
            Auditoria::anota('empresa.baja', $e['nombre_empresa'],
                'activa, base ' . $e['nombre_base_datos'], 'eliminada', $this->principal);
        } catch (\Throwable $ex) {
            error_log('[LibertyFin] bitácora de baja: ' . $ex->getMessage());
        }

        // La solicitud que la originó vuelve a quedar sin empresa, para que
        // no apunte a un id que ya no existe.
        try {
            $this->principal->prepare("
                UPDATE solicitudes_empresa SET empresa_id = NULL WHERE empresa_id = ?")
                 ->execute([(int)$e['id']]);
        } catch (\Throwable $ex) { /* puede no existir la tabla */ }

        $this->principal->prepare("DELETE FROM empresas WHERE id = ?")->execute([(int)$e['id']]);

        return ['empresa' => $e['nombre_empresa'], 'base' => $e['nombre_base_datos'],
                'actividad' => $actividad];
    }

    /** Para comparar nombres sin que un espacio de más estorbe. */
    private static function normal($s)
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }

    /** Sin l, I, 1, O ni 0: estas claves se dictan por teléfono. */
    private static function clave($largo = 12)
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $r = '';
        for ($i = 0; $i < $largo; $i++) $r .= $abc[random_int(0, strlen($abc) - 1)];
        return $r;
    }
}
