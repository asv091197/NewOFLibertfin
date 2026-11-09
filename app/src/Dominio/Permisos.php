<?php
namespace LibertyFin\Dominio;

/**
 * Quién puede hacer qué.
 *
 * Una sola matriz. El router, el menú y los controladores leen de aquí,
 * así que no puede pasar lo del sistema anterior: que el enlace esté
 * escondido pero la URL siga funcionando si alguien la escribe.
 *
 * Los permisos se nombran por lo que la persona HACE, no por la pantalla
 * donde lo hace. "cobrar" es cobrar, exista o no una sección llamada
 * Caja. Así, mover algo de lugar no obliga a repensar los permisos.
 */
final class Permisos
{
    /**
     * Los roles que existen de verdad en LibertyFin.
     *
     * No invento ninguno: son los tres que ya usaba el sistema anterior
     * más soporte, que allá entraba por un login aparte. Aquí entra por
     * el mismo: dos puertas al mismo sistema son dos superficies que
     * proteger, dos lugares donde aplicar un cambio y una que siempre
     * se queda atrás.
     */
    const ROLES = [
        'superadmin' => [
            'rotulo' => 'Superadministrador',
            'para'   => 'Dueño de LibertyFin. Puede todo, en todas las empresas.',
            'nivel'  => 'plataforma',
        ],
        'soporte' => [
            'rotulo' => 'Soporte',
            'para'   => 'Mantenimiento y diagnóstico. Ve todo, no mueve dinero.',
            'nivel'  => 'plataforma',
        ],
        'validador' => [
            'rotulo' => 'Validación',
            'para'   => 'Revisa la documentación de las empresas. No ve ventas ni dinero.',
            'nivel'  => 'plataforma',
        ],
        'admin' => [
            'rotulo' => 'Administrador',
            'para'   => 'Dueño o gerente del negocio. Ve el dinero y configura.',
            'nivel'  => 'empresa',
        ],
        'cajero' => [
            'rotulo' => 'Cajero',
            'para'   => 'Cobra, abre y cierra su caja. No ve las comisiones de nadie.',
            'nivel'  => 'empresa',
        ],
        'inventario' => [
            'rotulo' => 'Inventario',
            'para'   => 'Mantiene el catálogo y los proveedores. No cobra ni ve el dinero.',
            'nivel'  => 'empresa',
        ],
    ];

    /**
     * La matriz.
     *
     * Dos reglas de fondo:
     *
     *  · Soporte VE todo y no MUEVE nada. Quien entra a arreglar un
     *    problema necesita mirar, no cobrar. Si además pudiera cobrar,
     *    cancelar pagos o asignar comisiones, no habría forma de saber
     *    si un descuadre lo causó la empresa o quien vino a ayudar.
     *
     *  · Cajero no ve comisiones. No es desconfianza: es que el importe
     *    que cobra alguien más no es asunto suyo, y tenerlo a la vista
     *    en la pantalla donde atiende clientes es una fuga de información
     *    que nadie pidió.
     */
    /**
     * Quién puede qué.
     *
     * DOS NIVELES, Y NO SE MEZCLAN
     *
     * Las secciones de EMPRESA —Panel, Caja, Ventas, Reportes— muestran
     * los datos de UNA empresa: la de la sesión. Dárselas a soporte no
     * le sirve y además engaña: vería las ventas de quien le prestó su
     * cuenta y creería que son las del cliente que llamó.
     *
     * Para mirar dentro de una empresa, soporte entra por su ficha en
     * /soporte/{id}, que sí dice de quién son los números.
     *
     * Por eso ningún rol de plataforma tiene permisos de empresa, ni
     * superadmin. No es una limitación: es que ese permiso no significa
     * nada sin decir de qué empresa.
     */
    const MATRIZ = [
        // ── Secciones de EMPRESA ──
        'ver.panel'        => ['admin','cajero','inventario'],
        'ver.ventas'       => ['admin','cajero'],
        // Importar escribe ventas, cobros y comisiones de golpe:
        // es cosa de quien responde por los números, no de caja.
        'importar.ventas'  => ['admin'],
        'ver.clientes'     => ['admin','cajero'],
        'ver.cobranza'     => ['admin'],
        'ver.servicios'    => ['admin','cajero','inventario'],
        // Existencias y movimientos: quien surte y quien responde por ellos.
        'ver.inventario'   => ['admin','inventario'],
        'ver.comisiones'   => ['admin'],
        'ver.gastos'       => ['admin','inventario'],
        'ver.reportes'     => ['admin'],
        'ver.corte'        => ['admin','cajero'],
        'ver.recargas'     => ['admin','cajero'],
        'ver.facturacion'  => ['admin'],
        'ver.ligas'        => ['admin','cajero'],
        'ver.ajustes'      => ['admin'],
        'ver.usuarios'     => ['admin'],
        'ver.auditoria'    => ['admin'],

        'cobrar'           => ['admin','cajero'],
        'abonar'           => ['admin','cajero'],
        'cancelar.pago'    => ['admin'],
        'abrir.caja'       => ['admin','cajero'],
        'cerrar.caja'      => ['admin','cajero'],
        'vender.recarga'   => ['admin','cajero'],
        'timbrar'          => ['admin'],
        'editar.clientes'  => ['admin','cajero'],
        'editar.servicios' => ['admin','inventario'],
        'editar.inventario'=> ['admin','inventario'],
        // ── Módulos (Servicio\Modulos) ──
        'cancelar.venta'      => ['admin'],
        'ver.expediente'      => ['admin','cajero'],
        'editar.expediente'   => ['admin','cajero'],
        'ver.cotizaciones'    => ['admin','cajero'],
        'editar.cotizaciones' => ['admin','cajero'],
        // Una iguala genera ventas cada mes: decide quien responde por el dinero.
        'ver.igualas'         => ['admin'],
        'editar.igualas'      => ['admin'],
        'ver.seguimiento'     => ['admin','cajero'],
        'editar.seguimiento'  => ['admin','cajero'],
        'ver.compras'         => ['admin','inventario'],
        'editar.compras'      => ['admin','inventario'],
        // Generar una guía cuesta dinero de verdad, igual que cobrar.
        // Lo ve quien atiende; lo genera quien atiende, porque el
        // paquete sale del mostrador y no del escritorio del dueño.
        'ver.envios'          => ['admin','cajero','inventario'],
        'editar.envios'       => ['admin','cajero','inventario'],
        'ver.agenda'          => ['admin','cajero'],
        'editar.agenda'       => ['admin','cajero'],
        'editar.gastos'    => ['admin','inventario'],
        'borrar.gastos'    => ['admin'],
        'asignar.comision' => ['admin'],
        'quitar.comision'  => ['admin'],
        'editar.ajustes'   => ['admin'],
        'editar.usuarios'  => ['admin'],
        'editar.empresa'   => ['admin'],

        // ── Secciones de PLATAFORMA ──
        // Cualquiera de la empresa puede abrir un ticket. Si la única
        // puerta fuera el correo, media queja se pierde y la otra media
        // llega sin folio ni contexto.
        'abrir.ticket'     => ['admin','cajero','inventario'],

        'ver.soporte'      => ['superadmin','soporte','validador'],
        'ver.tickets'      => ['superadmin','soporte','validador'],
        'ver.empresas'     => ['superadmin','soporte','validador'],
        'ver.conocimiento' => ['superadmin','soporte','validador'],
        'editar.conocimiento' => ['superadmin','soporte','validador'],
        'ver.mantenimiento'=> ['superadmin','soporte'],
        'ver.informes'     => ['superadmin','soporte'],

        'revisar.docs'     => ['superadmin','soporte','validador'],
        'clave.ajena'      => ['superadmin','soporte'],
        'bloquear.cuenta'  => ['superadmin','soporte'],
        'secciones'        => ['superadmin','soporte','admin'],
        'diagnostico'      => ['superadmin','soporte'],
        'alta.empresas'    => ['superadmin','soporte'],

        // Solo el superadministrador. Entrar a la sesión de una empresa
        // es la llave maestra: se deja fuera de soporte a propósito.
        // Cuentas de plataforma: solo el superadministrador.
        'usuarios.plataforma' => ['superadmin'],
        // Suspender una empresa la deja sin entrar, pero conserva todo.
        'suspender.empresa'   => ['superadmin'],
        // Eliminar una empresa que nunca operó. Soporte también, porque
        // es quien da de alta y quien ve el renglón de prueba que sobró;
        // mandarlo a pedir que otro apriete el botón es cómo la basura se
        // queda años en la lista. Lo que de verdad protege no es el rol:
        // es que el sistema CUENTA los movimientos y se niega si hay uno
        // solo, y que la base de datos nunca se borra. Para dejarlo en
        // superadmin a secas, quita 'soporte' de aquí.
        'eliminar.empresa'    => ['superadmin','soporte'],
        // Apagar algo para TODAS las empresas es una decisión de
        // mantenimiento, no de atención: la toma quien responde por la
        // plataforma, no quien contesta un ticket.
        'ajustes.globales'    => ['superadmin'],
        // Apagar una sección o un método en UNA empresa sí lo hace
        // soporte: suele ser justo lo que el cliente está pidiendo.
        'ajustes.empresa'     => ['superadmin','soporte'],
    ];

    /**
     * El nombre legible de un rol, sin quedarse nunca en blanco.
     *
     * Si en la base hay un rol que esta versión no conoce —porque venía
     * del sistema anterior o porque alguien lo escribió a mano— se
     * muestra el valor crudo. Una celda vacía hace pensar que el dato
     * se perdió; ver "empleado" dice exactamente qué pasa.
     */
    public static function rotulo($rol)
    {
        $crudo = trim((string)$rol);
        if ($crudo === '') return 'sin rol asignado';
        $n = self::normalizar($crudo);
        if (isset(self::ROLES[$n])) {
            // Un rol traducido se muestra con su nombre actual, pero
            // diciendo de dónde viene: si no, nadie entiende por qué
            // "vendedor" abre lo mismo que "cajero".
            return $n === $crudo
                ? self::ROLES[$n]['rotulo']
                : self::ROLES[$n]['rotulo'] . ' (antes ' . $crudo . ')';
        }
        return $crudo;
    }

    /** ¿Es un rol que esta versión conoce? */
    public static function conocido($rol)
    {
        return isset(self::ROLES[self::normalizar($rol)]);
    }

    /**
     * Roles del sistema anterior que ya no existen, y a qué equivalen.
     *
     * Una cuenta con uno de estos seguiría funcionando en vez de quedarse
     * sin permisos sin explicación. Se traduce, no se niega.
     */
    const EQUIVALENCIAS = [
        'supervisor'  => 'admin',
        'vendedor'    => 'cajero',
        'super_admin' => 'admin',
        'empleado'    => 'cajero',
    ];

    /** Traduce un rol viejo al actual. Devuelve el mismo si ya es actual. */
    public static function normalizar($rol)
    {
        $rol = trim((string)$rol);
        if (isset(self::ROLES[$rol])) return $rol;
        return self::EQUIVALENCIAS[$rol] ?? $rol;
    }

    public static function puede($permiso, $rol = null)
    {
        $rol = self::normalizar($rol ?? ($_SESSION['usuario_rol'] ?? ''));
        $lista = self::MATRIZ[$permiso] ?? null;
        // Un permiso que no existe se niega. Escribirlo mal debe cerrar
        // la puerta, no abrirla.
        if ($lista === null) return false;
        return in_array($rol, $lista, true);
    }

    /** Todo lo que puede un rol, para mostrarlo al asignarlo. */
    public static function de($rol)
    {
        $rol = self::normalizar($rol);
        $r = [];
        foreach (self::MATRIZ as $p => $roles) {
            if (in_array($rol, $roles, true)) $r[] = $p;
        }
        return $r;
    }

    /**
     * Qué roles puede ASIGNAR cada rol.
     *
     * Esto es lo que impide una escalada de privilegios silenciosa: sin
     * este límite, un administrador de empresa podía crear un usuario
     * con rol `soporte` y con eso ver los datos de TODOS los clientes.
     * No hacía falta ningún truco, solo elegir del desplegable.
     *
     * Nadie puede repartir un poder que no tiene. Un administrador
     * reparte roles de su empresa; los de plataforma solo los da el
     * superadministrador, y esos ni siquiera viven en una empresa.
     */
    const PUEDE_ASIGNAR = [
        'superadmin' => ['superadmin','soporte','validador','admin','cajero','inventario'],
        'soporte'    => [],
        'validador'  => [],
        'admin'      => ['admin','cajero','inventario'],
        'cajero'     => [],
        'inventario' => [],
    ];

    /**
     * Los permisos que NO necesitan una empresa.
     *
     * Sirve para un guardia central: si una sesión sin empresa llega a
     * una ruta que pide cualquier otro permiso, se detiene ahí con un
     * mensaje. Sin eso, la petición avanza hasta que un repositorio
     * intenta consultar una base vacía y revienta con "1046 No database
     * selected" y una traza que no explica nada.
     *
     * Es una red, no el arreglo: cada pantalla sigue siendo responsable
     * de lo suyo. Pero convierte un error feo en uno que se entiende, y
     * lo hace en UN lugar en vez de cuarenta y siete.
     */
    const SIN_EMPRESA = [
        'ver.soporte', 'ver.tickets', 'ver.empresas', 'ver.conocimiento',
        'editar.conocimiento', 'ver.mantenimiento', 'ver.informes',
        'revisar.docs', 'clave.ajena', 'bloquear.cuenta', 'diagnostico',
        'alta.empresas', 'usuarios.plataforma', 'suspender.empresa',
        'eliminar.empresa',
        'ajustes.globales', 'ajustes.empresa',
    ];

    public static function necesitaEmpresa($permiso)
    {
        return !in_array($permiso, self::SIN_EMPRESA, true);
    }

    public static function rolesQuePuedeAsignar($rol = null)
    {
        $rol = self::normalizar($rol ?? ($_SESSION['usuario_rol'] ?? ''));
        $lista = self::PUEDE_ASIGNAR[$rol] ?? [];
        $r = [];
        foreach ($lista as $k) if (isset(self::ROLES[$k])) $r[$k] = self::ROLES[$k];
        return $r;
    }

    public static function puedeAsignar($rolDestino, $rol = null)
    {
        return array_key_exists($rolDestino, self::rolesQuePuedeAsignar($rol));
    }

    /** ¿Es un rol de plataforma? Esos no pertenecen a una empresa. */
    public static function esPlataforma($rol)
    {
        $n = self::normalizar($rol);
        return (self::ROLES[$n]['nivel'] ?? 'empresa') === 'plataforma';
    }

    /** Un resumen legible del rol, para la pantalla de usuarios. */
    public static function resumen($rol)
    {
        $todos = self::de($rol);
        $ve    = count(array_filter($todos, function ($p) { return strpos($p, 'ver.') === 0; }));
        $hace  = count($todos) - $ve;
        return ['ve' => $ve, 'hace' => $hace, 'total' => count(self::MATRIZ)];
    }
}