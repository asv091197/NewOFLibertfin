<?php
namespace LibertyFin\Servicio;

use LibertyFin\Dominio\Permisos;
use PDO;

/**
 * El administrador entra como otro usuario de su empresa.
 *
 * PARA QUÉ
 *
 * «Al cajero no le aparece el botón», «a Lupita se le traba la pantalla
 * de cobro». Hasta ahora, verlo con los ojos del cajero obligaba a
 * pedirle su contraseña — y una contraseña prestada deja de ser de
 * nadie: la bitácora dice «Lupita hizo esto» cuando lo hizo el jefe.
 *
 * CON LA CONTRASEÑA PROPIA, NO CON LA AJENA
 *
 * El administrador escribe LA SUYA. No necesita saber la de nadie, y
 * queda anotado quién entró como quién. Esa es toda la diferencia entre
 * una herramienta de soporte y un agujero.
 *
 * QUÉ NO PUEDE HACER MIENTRAS SUPLANTA
 *
 * Nada que cambie quién tiene acceso: ni tocar usuarios, ni ajustes, ni
 * la bitácora. Si pudiera, suplantar sería el camino corto para subirse
 * de rol sin dejar rastro — entras como alguien, te das permisos, sales.
 * Tampoco se puede suplantar a otro administrador: entre iguales no hay
 * nada que ver que no se vea con la propia cuenta.
 *
 * SIEMPRE SE VUELVE
 *
 * La sesión guarda de dónde se viene. Un botón regresa, y la vuelta no
 * pide contraseña: ya se verificó al entrar.
 */
final class Suplantar
{
    /** Lo que no se puede hacer suplantando, por más admin que seas. */
    const PROHIBIDO = [
        'ver.usuarios', 'ver.auditoria', 'ver.ajustes',
        'editar.ajustes', 'editar.usuarios', 'usuarios.plataforma',
    ];

    /** ¿Esta sesión es una suplantación? */
    public static function activa()
    {
        return !empty($_SESSION['lf_suplanta']);
    }

    /** Quién es en realidad, para enseñarlo en la barra. */
    public static function real()
    {
        return self::activa() ? ($_SESSION['lf_suplanta'] ?? null) : null;
    }

    /**
     * ¿Se puede hacer esto mientras se suplanta?
     * Fuera de una suplantación siempre es que sí: esta clase no decide
     * permisos, solo los recorta.
     */
    public static function permite($permiso)
    {
        if (!self::activa()) return true;
        return !in_array($permiso, self::PROHIBIDO, true);
    }

    /** A quién se puede entrar: los de la empresa, menos uno mismo y los admin. */
    public static function candidatos(PDO $db)
    {
        $yo = (int)($_SESSION['lf_suplanta']['usuario_id'] ?? $_SESSION['usuario_id'] ?? 0);
        $st = $db->prepare("
            SELECT id, nombre, username, rol
              FROM usuarios
             WHERE activo = 1 AND id <> ? AND rol <> 'admin'
             ORDER BY nombre");
        $st->execute([$yo]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Entra como otro. Devuelve el usuario suplantado.
     *
     * @param string $claveDelAdmin la del que suplanta, no la del otro
     * @throws \InvalidArgumentException con un mensaje que se puede enseñar
     */
    public static function entrar(PDO $db, $destinoId, $claveDelAdmin)
    {
        if (self::activa()) {
            throw new \InvalidArgumentException(
                'Ya estás viendo el sistema como alguien más. Vuelve a tu cuenta antes de cambiar.');
        }
        if (!Permisos::puede('ver.usuarios')) {
            throw new \InvalidArgumentException('Solo un administrador puede hacer esto.');
        }

        $yo = (int)($_SESSION['usuario_id'] ?? 0);
        $destinoId = (int)$destinoId;
        if (!$yo || !$destinoId) throw new \InvalidArgumentException('Elige a quién quieres ver.');
        if ($destinoId === $yo)  throw new \InvalidArgumentException('Esa ya es tu cuenta.');

        // La contraseña PROPIA. Se verifica contra el renglón del que
        // está suplantando, no contra el destino.
        $st = $db->prepare("SELECT id, password FROM usuarios WHERE id = ? AND activo = 1");
        $st->execute([$yo]);
        $mio = $st->fetch(PDO::FETCH_ASSOC);
        if (!$mio || !password_verify((string)$claveDelAdmin, (string)$mio['password'])) {
            // Se anota el intento fallido: alguien probando contraseñas
            // en esta pantalla es exactamente lo que hay que ver venir.
            error_log('[LibertyFin] suplantar: contraseña incorrecta del usuario ' . $yo);
            throw new \InvalidArgumentException('Esa no es tu contraseña.');
        }

        $st = $db->prepare("SELECT * FROM usuarios WHERE id = ? AND activo = 1");
        $st->execute([$destinoId]);
        $otro = $st->fetch(PDO::FETCH_ASSOC);
        if (!$otro) throw new \InvalidArgumentException('Esa cuenta no existe o está apagada.');
        if (Permisos::normalizar($otro['rol']) === 'admin') {
            throw new \InvalidArgumentException(
                'No se puede entrar como otro administrador: ve lo mismo que tú.');
        }

        // De dónde se viene, para poder volver.
        $_SESSION['lf_suplanta'] = [
            'usuario_id'     => $yo,
            'usuario_nombre' => $_SESSION['usuario_nombre'] ?? '',
            'usuario_rol'    => $_SESSION['usuario_rol'] ?? 'admin',
            'sucursal_id'    => $_SESSION['sucursal_id'] ?? null,
            'desde'          => time(),
        ];

        $_SESSION['usuario_id']     = (int)$otro['id'];
        $_SESSION['usuario_nombre'] = $otro['nombre'];
        $_SESSION['usuario_rol']    = $otro['rol'];
        if (!empty($otro['sucursal_id'])) $_SESSION['sucursal_id'] = (int)$otro['sucursal_id'];

        // El turno de caja es de cada quien: el que estuviera leído es
        // del administrador y no del cajero.
        Caja::olvidar();
        Alertas::olvidar();

        Auditoria::anota('usuario.suplantar', $otro['nombre'],
            $_SESSION['lf_suplanta']['usuario_nombre'], 'entró como ' . $otro['username'], $db);

        return $otro;
    }

    /** Vuelve a la cuenta de siempre. No pide contraseña: ya se verificó. */
    public static function salir(PDO $db = null)
    {
        if (!self::activa()) return false;
        $v = $_SESSION['lf_suplanta'];
        $eraNombre = $_SESSION['usuario_nombre'] ?? '';

        $_SESSION['usuario_id']     = (int)$v['usuario_id'];
        $_SESSION['usuario_nombre'] = $v['usuario_nombre'];
        $_SESSION['usuario_rol']    = $v['usuario_rol'];
        if ($v['sucursal_id'] !== null) $_SESSION['sucursal_id'] = (int)$v['sucursal_id'];
        unset($_SESSION['lf_suplanta']);

        Caja::olvidar();
        Alertas::olvidar();

        if ($db) {
            try {
                Auditoria::anota('usuario.suplantar', $eraNombre, 'entró como ' . $eraNombre,
                    'volvió a su cuenta', $db);
            } catch (\Throwable $e) {
                error_log('[LibertyFin] bitácora al volver: ' . $e->getMessage());
            }
        }
        return true;
    }
}