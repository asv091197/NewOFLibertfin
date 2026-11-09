<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\EnvioRepo;
use PDO;

/**
 * Envíos: lo que LibertyFin sabe hacer con una paquetería.
 *
 * Envia.php habla con la API y no sabe nada de ventas ni de clientes.
 * Esta clase es la que sí: saca el «de dónde» de la sucursal, el «a
 * dónde» del cliente, arma el bulto, cotiza, genera la guía y la guarda.
 *
 * ── POR QUÉ EL CÓDIGO POSTAL MANDA ──
 *
 * La paquetería no entiende «Jalisco»: quiere «JA», con un catálogo de
 * claves que es suyo y que no coincide ni con el del SAT ni con la ISO
 * (la Ciudad de México es CX). Teclear el estado a mano es, tarde o
 * temprano, una cotización vacía que nadie sabe explicar.
 *
 * Así que aquí el código postal es el dato de verdad: con él se le
 * pregunta a Envia qué ciudad y qué estado son, y eso es lo que se
 * guarda. El catálogo de CPs es público y no gasta credenciales, así
 * que se puede usar incluso antes de contratar.
 */
final class Envios
{
    /** Cómo se llaman las paqueterías, para no enseñar «fedex» en pantalla. */
    const CARRIERS = [
        'dhl' => 'DHL', 'fedex' => 'FedEx', 'estafeta' => 'Estafeta',
        'redpack' => 'Redpack', 'paquetexpress' => 'Paquete Express',
        'ups' => 'UPS', 'sendex' => 'Sendex', 'ampm' => 'AMPM',
        'carssa' => 'Carssa', 'tresguerras' => 'Tres Guerras',
        'noventa y nueve minutos' => '99 Minutos', '99minutos' => '99 Minutos',
        'minutos99' => '99 Minutos', 'jtexpress' => 'J&T Express',
    ];

    private $db;
    private $repo;
    private $envia;

    public function __construct(PDO $db, Envia $envia = null)
    {
        $this->db    = $db;
        $this->repo  = new EnvioRepo($db);
        $this->envia = $envia ?: Envia::deConfig();
    }

    /** Un problema nuestro, no de Envia. Manda sobre el de la API. */
    private $propioError = '';

    public function repo()  { return $this->repo; }
    public function listo() { return $this->envia && $this->envia->listo(); }
    public function sandbox() { return $this->envia && $this->envia->sandbox(); }

    public function error()
    {
        if ($this->propioError !== '') return $this->propioError;
        return $this->envia ? $this->envia->error() : 'Envia no está configurado.';
    }

    /**
     * El domicilio de salida, sin reventar si la tabla no existe.
     *
     * Una base que todavía no corrió la migración hacía que cotizar y
     * generar tiraran la excepción hasta arriba: el usuario veía una
     * traza de SQL en plena pantalla en vez de «falta actualizar la
     * base». Leer el origen es lo primero que hacen los dos, así que
     * el candado va aquí y no repetido en cada uno.
     *
     * @return array|null null y el motivo en error().
     */
    private function origenSeguro($sucursalId)
    {
        try {
            return $this->origen($sucursalId);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] origen de envío: ' . $e->getMessage());
            $this->propioError = 'La base todavía no tiene las tablas de Envíos. '
                . 'Aplica config/actualizar-v28.sql en phpMyAdmin, o entra a '
                . 'Mantenimiento y aprieta «Poner todas al día».';
            return null;
        }
    }

    /**
     * ¿Este servicio exige llevar el paquete a una sucursal?
     *
     * Las paqueterías mexicanas nombran sus servicios por las dos
     * puntas del viaje: **o**curre (sucursal) o **d**omicilio.
     *
     *     ground_dd → de tu domicilio al domicilio del cliente
     *     ground_od → TÚ lo llevas a sucursal, llega a domicilio
     *     ground_do → lo recogen contigo, el cliente va por él
     *     ground_oo → sucursal a sucursal
     *
     * Cuando la primera letra es «o», Envia pide además la clave de la
     * sucursal de donde sale, y sin ella rechaza la guía con un error
     * que no explica nada («Origin branch code is required»).
     *
     * Esto es una PISTA para avisar en pantalla, no una regla: se usa
     * solo para poner una etiqueta, nunca para esconder una opción. Un
     * servicio con otro nombre se cotiza igual que siempre.
     */
    public static function saleDeSucursal($service)
    {
        $s = strtolower(trim((string)$service));
        if ($s === '') return false;
        if (preg_match('/[_-]o[od]$/', $s)) return true;         // _od, _oo
        return (bool)preg_match('/(^|[_-])(ocurre|occurs|branch)/', $s);
    }

    /**
     * Parte la lista en las que sirven y las que no.
     *
     * Una opción que al apretar «Generar guía» va a devolver un error
     * no es una opción: es una trampa con precio. Se apartan dos clases:
     *
     *   · Las que salen de una sucursal (`_od`, `_oo`). Envia pide la
     *     clave de la sucursal y LibertyFin todavía no tiene pantalla
     *     para elegirla, así que hoy no se pueden completar.
     *   · Las que ya se intentaron y contestaron «solo cotizo».
     *
     * No se BORRAN: se devuelven aparte, con el motivo, y la pantalla
     * las deja ver. Esconder una opción más barata sin decir que existe
     * sería decidir por el negocio.
     *
     * @return array [las usables, las apartadas con su motivo]
     */
    public static function separarUsables(array $opciones)
    {
        $buenas = []; $fuera = [];
        foreach ($opciones as $o) {
            if (self::esSoloCotiza($o['carrier'] ?? '', $o['service'] ?? '')) {
                $o['motivo'] = 'Ya la probaste: esta paquetería solo cotiza, no genera guías.';
                $fuera[] = $o;
                continue;
            }
            // Las de sucursal YA se pueden usar: la pantalla pide a cuál
            // vas a llevar el paquete antes de generar. Se marcan para
            // que se vea la condición junto al precio.
            $o['requiere_sucursal'] = self::saleDeSucursal($o['service'] ?? '');
            $buenas[] = $o;
        }
        return [$buenas, $fuera];
    }

    /** Qué hay que hacer con un servicio así, en una línea. */
    public static function avisoSucursal($service)
    {
        return self::saleDeSucursal($service)
            ? 'Tú llevas el paquete a una sucursal de la paquetería.' : '';
    }

    /**
     * Las que ya se intentaron y contestaron «solo cotizo».
     *
     * Vive en la sesión y no en la base a propósito: es una restricción
     * de la cuenta y del entorno —en Sandbox casi todas lo dicen, en
     * producción casi ninguna— y guardarla para siempre haría que una
     * opción que ya sirve siguiera saliendo marcada para siempre.
     */
    private static function anotarSoloCotiza($carrier, $servicio)
    {
        $llave = strtolower(trim($carrier) . '|' . trim($servicio));
        if (!isset($_SESSION['lf_envios_solo_cotiza'])) $_SESSION['lf_envios_solo_cotiza'] = [];
        $_SESSION['lf_envios_solo_cotiza'][$llave] = 1;
    }

    /** ¿Esta combinación ya dijo que solo cotiza? */
    public static function esSoloCotiza($carrier, $servicio)
    {
        $llave = strtolower(trim((string)$carrier) . '|' . trim((string)$servicio));
        return !empty($_SESSION['lf_envios_solo_cotiza'][$llave]);
    }

    /** El nombre bonito de una paquetería. */
    public static function carrier($clave)
    {
        $k = strtolower(trim((string)$clave));
        return self::CARRIERS[$k] ?? ucwords($k);
    }

    // ═════════════════════════════════════════════════════════
    //  DIRECCIONES
    // ═════════════════════════════════════════════════════════

    /**
     * Qué ciudad y qué estado son un código postal.
     *
     * No necesita token, así que contesta aunque la integración todavía
     * no esté contratada. Es lo que llena los campos solos en la
     * pantalla de captura.
     */
    public function cp($codigo, $pais = 'MX')
    {
        $e = $this->envia ?: new Envia([]);
        return $e->cp($codigo, $pais);
    }

    /** De dónde sale: el domicilio de despacho de la sucursal. */
    public function origen($sucursalId)
    {
        $o = $this->repo->origen($sucursalId);
        return [
            'nombre'   => $o['nombre'] ?: 'Almacén', 'empresa' => $o['empresa'],
            'telefono' => $o['telefono'], 'email' => $o['email'],
            'calle'    => $o['calle'],   'numero' => $o['numero'],
            'colonia'  => $o['colonia'], 'ciudad' => $o['ciudad'],
            'estado'   => $o['estado'],  'cp'     => $o['cp'],
            'pais'     => $o['pais'] ?: 'MX', 'referencia' => $o['referencia'],
        ];
    }

    /**
     * A dónde va: el domicilio del cliente.
     *
     * Se toma del domicilio fiscal, que es el único desglosado que
     * tiene la ficha. `direccion` —el texto libre de toda la vida— no
     * sirve aquí: «Av. Juárez 123, col. Centro» es una cadena, y la
     * paquetería necesita saber cuál parte es la colonia.
     *
     * Devuelve el estado tal como esté capturado. Si no es una clave de
     * dos letras, el formulario lo corrige con el CP antes de cotizar.
     */
    public function destinoDeCliente($clienteId)
    {
        $vacio = ['nombre' => '', 'telefono' => '', 'email' => '', 'calle' => '',
                  'numero' => '', 'colonia' => '', 'ciudad' => '', 'estado' => '',
                  'cp' => '', 'pais' => 'MX', 'referencia' => ''];
        if (!$clienteId) return $vacio;
        try {
            $st = $this->db->prepare("
                SELECT nombre, telefono, email, calle_numero, numero_interior, colonia,
                       delegacion_municipio, ciudad, estado_direccion, codigo_postal
                  FROM clientes WHERE id = ?");
            $st->execute([(int)$clienteId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return $vacio;   // ficha sin domicilio fiscal: se captura a mano
        }
        if (!$c) return $vacio;

        return [
            'nombre'   => (string)$c['nombre'],
            'telefono' => (string)$c['telefono'],
            'email'    => (string)$c['email'],
            'calle'    => (string)$c['calle_numero'],
            'numero'   => (string)$c['numero_interior'],
            'colonia'  => (string)$c['colonia'],
            // Para la paquetería, la «ciudad» es el municipio. Cuando
            // los dos están capturados mandan el municipio, que es lo
            // que reparte: en el Estado de México «Naucalpan» llega y
            // «Ciudad Satélite» no.
            'ciudad'   => (string)($c['delegacion_municipio'] ?: $c['ciudad']),
            'estado'   => (string)$c['estado_direccion'],
            'cp'       => (string)$c['codigo_postal'],
            'pais'     => 'MX',
            'referencia' => '',
        ];
    }

    /**
     * Rellena ciudad y estado desde el CP, y avisa si no cuadran.
     *
     * Lo que el usuario escribió en ciudad se respeta —hay colonias que
     * se reparten mejor con el nombre local—, pero el ESTADO se
     * reemplaza por la clave del catálogo: ese es el campo que rompe la
     * cotización en silencio.
     *
     * ── EL CATÁLOGO AYUDA, NO MANDA ──
     *
     * Antes, si el catálogo decía «ese CP no existe», no se dejaba
     * guardar. Estaba mal, y se vio en la práctica: el 11590 es Polanco
     * y el catálogo lo rechazó, dejando a la empresa sin poder capturar
     * su propio domicilio.
     *
     * El catálogo de Envia está incompleto en partes y además puede
     * estar caído. Lo que sí es cierto es el domicilio que el negocio
     * tiene enfrente. Así que el catálogo RELLENA cuando sabe, y nunca
     * veta: lo único que de verdad detiene es no saber el estado y que
     * nadie lo haya escrito, porque sin eso la cotización sale vacía.
     *
     * @return array [la dirección, '' o el motivo del rechazo, '' o un aviso]
     */
    public function completarConCp(array $d)
    {
        $cp = trim((string)($d['cp'] ?? ''));
        $puesto = mb_strtoupper(trim((string)($d['estado'] ?? '')));
        if ($cp === '') {
            if (mb_strlen($puesto) === 2) { $d['estado'] = $puesto; return [$d, '', '']; }
            return [$d, 'Falta el código postal.', ''];
        }

        $e = $this->envia ?: new Envia([]);
        $g = $e->cp($cp, $d['pais'] ?? 'MX');

        if ($g) {
            $d['estado'] = $g['estado'];
            if (trim((string)($d['ciudad'] ?? '')) === '') $d['ciudad'] = $g['ciudad'];
            // El catálogo sabe y el usuario escribió otra cosa: manda el
            // catálogo, pero se avisa. Una guía con el estado equivocado
            // se va a la otra punta del país.
            if ($puesto !== '' && $puesto !== $g['estado']) {
                return [$d, '', 'Pusiste «' . $puesto . '» pero el código postal ' . $cp
                             . ' es de ' . $g['ciudad'] . ', ' . $g['estado']
                             . '. Se guardó ' . $g['estado'] . '.'];
            }
            return [$d, '', ''];
        }

        // El catálogo no ayudó —ni existe el CP para él, ni se pudo
        // preguntar—. Si el estado ya viene como clave, se respeta.
        if (mb_strlen($puesto) === 2) {
            $d['estado'] = $puesto;
            return [$d, '', 'No pude confirmar el código postal ' . $cp
                          . ' con Envia (' . $e->error() . ') Se guardó con el estado «'
                          . $puesto . '» tal como lo escribiste; revisa que sea el correcto.'];
        }

        return [$d, 'No pude sacar el estado del código postal ' . $cp . ': ' . $e->error()
                  . ' Escríbelo tú en la casilla del estado, con su clave de dos letras '
                  . '(Jalisco es JA, la Ciudad de México es CX, Nuevo León es NL).', ''];
    }

    // ═════════════════════════════════════════════════════════
    //  EL BULTO
    // ═════════════════════════════════════════════════════════

    /**
     * Un paquete con lo que se puede saber de la venta.
     *
     * El valor declarado sale del total: es lo que se repone si se
     * pierde. El peso y las medidas NO se inventan —no hay de dónde
     * sacarlos— y van en una caja chica de ejemplo que el usuario
     * corrige. Inventar un peso es cotizar un precio que después no se
     * cobra.
     */
    public function paqueteDeVenta($ventaId)
    {
        $valor = 0.0; $que = 'Mercancía';
        try {
            $st = $this->db->prepare("SELECT total FROM ventas WHERE id = ?");
            $st->execute([(int)$ventaId]);
            $valor = round((float)$st->fetchColumn(), 2);
        } catch (\Throwable $e) { /* queda en cero */ }
        return ['tipo' => 'box', 'contenido' => $que, 'cantidad' => 1,
                'valor' => $valor, 'peso' => 1, 'largo' => 30, 'ancho' => 20, 'alto' => 15];
    }

    /** Los paquetes que vienen del formulario, limpios. */
    public static function paquetesDeForm(array $post)
    {
        $tipos   = (array)($post['p_tipo'] ?? []);
        $pesos   = (array)($post['p_peso'] ?? []);
        $largos  = (array)($post['p_largo'] ?? []);
        $anchos  = (array)($post['p_ancho'] ?? []);
        $altos   = (array)($post['p_alto'] ?? []);
        $conts   = (array)($post['p_contenido'] ?? []);
        $valores = (array)($post['p_valor'] ?? []);

        $r = [];
        foreach ($pesos as $i => $peso) {
            if ((float)$peso <= 0) continue;
            $r[] = [
                'tipo'      => (string)($tipos[$i] ?? 'box'),
                'contenido' => (string)($conts[$i] ?? 'Mercancía') ?: 'Mercancía',
                'cantidad'  => 1,
                'valor'     => round((float)($valores[$i] ?? 0), 2),
                'peso'      => round((float)$peso, 3),
                'largo'     => max(1, (float)($largos[$i] ?? 1)),
                'ancho'     => max(1, (float)($anchos[$i] ?? 1)),
                'alto'      => max(1, (float)($altos[$i] ?? 1)),
            ];
        }
        return $r;
    }

    // ═════════════════════════════════════════════════════════
    //  COTIZAR, GENERAR, RASTREAR, CANCELAR
    // ═════════════════════════════════════════════════════════

    /** Un envío guardado, o null —también si la tabla todavía no existe—. */
    public function uno($id)
    {
        try {
            return $this->repo->uno_($id);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] leer envío ' . $id . ': ' . $e->getMessage());
            $this->propioError = 'La base todavía no tiene las tablas de Envíos.';
            return null;
        }
    }

    /** Las opciones y su precio. null si algo falló (el motivo, en error()). */
    public function cotizar($sucursalId, array $destino, array $paquetes)
    {
        $this->propioError = '';
        if (!$this->listo()) return null;
        $origen = $this->origenSeguro($sucursalId);
        if ($origen === null) return null;
        return $this->envia->cotizar($origen, $destino, $paquetes);
    }

    /**
     * Genera la guía y la guarda.
     *
     * El orden importa: primero Envia, después la base. Si se guardara
     * antes, un fallo al generar dejaría en el sistema una guía que no
     * existe en ninguna paquetería, y alguien le diría a un cliente un
     * número de rastreo inventado.
     *
     * @return int|null El id del envío guardado.
     */
    /** Las sucursales de una paquetería donde se puede dejar el paquete. */
    public function sucursalesDe($carrier, $cp = '')
    {
        if (!$this->listo()) return [];
        return $this->envia->sucursales($carrier, 'MX', $cp);
    }

    public function generar($sucursalId, array $destino, array $paquetes,
                            $carrier, $servicio, array $extra = [])
    {
        $this->propioError = '';
        if (!$this->listo()) return null;
        $origen = $this->origenSeguro($sucursalId);
        if ($origen === null) return null;

        $g = $this->envia->generar($origen, $destino, $paquetes, $carrier, $servicio,
                                   (string)($extra['sucursal_envia'] ?? ''));
        if (!$g) {
            // Si rechazó por ser «solo cotiza», se anota para marcarla en
            // la próxima lista. Es una política de la paquetería, no un
            // tropiezo: ofrecerla otra vez como si nada sería hacer
            // perder el tiempo a quien está despachando.
            if ($this->envia->fueSoloCotiza()) self::anotarSoloCotiza($carrier, $servicio);
            return null;
        }

        try {
            $id = $this->repo->crear([
                'venta_id'       => $extra['venta_id'] ?? null,
                'sucursal_id'    => $sucursalId,
                'estado'         => 'generado',
                'carrier'        => $g['carrier'],
                'servicio'       => $g['service'],
                'servicio_desc'  => $extra['servicio_desc'] ?? '',
                'guia'           => $g['guia'],
                'guias'          => $g['guias'],
                'envia_envio_id' => $g['envio_id'],
                'etiqueta_url'   => $g['etiqueta'],
                'rastreo_url'    => $g['url_rastreo'],
                'costo'          => $g['precio'],
                'moneda'         => $g['moneda'],
                'dest'           => $destino,
                'paquetes'       => $paquetes,
                'nota'           => $extra['nota'] ?? '',
                'usuario_id'     => $_SESSION['usuario_id'] ?? null,
                'usuario_nombre' => $_SESSION['usuario_nombre'] ?? '',
            ]);
        } catch (\Throwable $e) {
            // La guía YA existe en la paquetería. Perderla aquí sería
            // peor que el error: se deja en el registro con todo lo que
            // hace falta para encontrarla a mano.
            error_log('[LibertyFin] envío generado pero no guardado · guía ' . $g['guia']
                    . ' · envío ' . $g['envio_id'] . ' · ' . $e->getMessage());
            return null;
        }

        // Se apunta de qué empresa es esta guía, en la base principal.
        // Es lo único que después le permite a un aviso de la
        // paquetería —que llega sin sesión y solo trae el número—
        // encontrar en qué base está el envío.
        self::apuntarRuta($g['guia'], $g['guias'], $id);

        try {
            Auditoria::anota('envio.generar', 'guía ' . $g['guia'], null,
                self::carrier($g['carrier']) . ' · $' . number_format($g['precio'], 2), $this->db);
        } catch (\Throwable $e) { /* la guía ya está, la bitácora no la detiene */ }

        return $id;
    }

    /**
     * Apunta la guía en la base principal, para los avisos.
     *
     * Se apuntan TODAS las guías del envío, no solo la principal: con
     * tres bultos hay tres números de rastreo, y la paquetería avisa
     * por cualquiera de ellos.
     *
     * Si falla no se detiene nada: la guía ya existe y los avisos
     * tienen su camino lento —recorrer las bases— como respaldo.
     */
    private static function apuntarRuta($guia, array $guias, $envioId)
    {
        $base = (string)($_SESSION['empresa_db'] ?? '');
        if ($base === '') return;
        try {
            $principal = \LibertyFin\Datos\Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            $rutas = new \LibertyFin\Datos\RutaEnvioRepo($principal);
            foreach (array_unique(array_filter(array_merge([$guia], $guias))) as $g) {
                $rutas->apuntar($g, $base, $envioId);
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] apuntar ruta de guía ' . $guia . ': ' . $e->getMessage());
        }
    }

    /** Dónde va. Actualiza el estado guardado. */
    public function rastrear($id)
    {
        $this->propioError = '';
        $e = $this->uno($id);
        if (!$e) return null;
        if (trim((string)$e['guia']) === '') {
            return null;
        }
        if (!$this->listo()) return null;

        $r = $this->envia->rastrear($e['guias'] ?: [$e['guia']]);
        if ($r === null) return null;

        $primero = $r[0] ?? [];
        $texto   = (string)($primero['estado'] ?? '');
        $nuevo   = self::estadoDe($texto, $e['estado']);
        try {
            $this->repo->anotarRastreo($id, $nuevo, $texto);
        } catch (\Throwable $ex) { error_log('[LibertyFin] anotar rastreo: ' . $ex->getMessage()); }

        return $r;
    }

    /**
     * Qué estado nuestro corresponde a lo que dice la paquetería.
     *
     * Cada una escribe lo suyo —«Delivered», «Entregado», «ENTREGA
     * EFECTUADA»— así que se busca por pedazos y en los dos idiomas. Lo
     * que no se reconoce se queda EN CAMINO y conserva el texto tal
     * cual: inventar «entregado» por no entender una palabra es decirle
     * a un cliente que ya le llegó algo que no llegó.
     */
    public static function estadoDe($texto, $actual = 'generado')
    {
        $t = mb_strtolower(trim((string)$texto));
        if ($t === '') return $actual;
        foreach (['delivered', 'entregad', 'entrega efectuada'] as $x) {
            if (mb_strpos($t, $x) !== false) return 'entregado';
        }
        foreach (['cancel', 'anulad'] as $x) {
            if (mb_strpos($t, $x) !== false) return 'cancelado';
        }
        // «Created» quiere decir que la GUÍA se creó, no que el paquete
        // vaya en camino: nadie lo ha recogido todavía. Decir «en
        // camino» ahí es mentirle a quien pregunta dónde va su paquete,
        // y es lo primero que se revisa cuando un cliente reclama.
        foreach (['created', 'creada', 'generated', 'generada', 'label',
                  'pending', 'pendiente', 'ready', 'manifest'] as $x) {
            if (mb_strpos($t, $x) !== false) return 'generado';
        }
        return 'en_camino';
    }

    /** Cancela la guía con la paquetería y lo anota. */
    public function cancelar($id)
    {
        $this->propioError = '';
        $e = $this->uno($id);
        if (!$e) return false;
        if ($e['cerrado']) return false;
        if (!$this->listo()) return false;

        if (trim((string)$e['envia_envio_id']) === '') {
            // Sin el identificador de Envia no hay a qué cancelarle.
            return false;
        }
        if (!$this->envia->cancelar($e['envia_envio_id'], $e['carrier'], $e['guia'])) return false;

        try {
            $this->repo->anotarCancelado($id);
            Auditoria::anota('envio.cancelar', 'guía ' . $e['guia'], $e['estado_rotulo'], 'Cancelada', $this->db);
        } catch (\Throwable $ex) { error_log('[LibertyFin] anotar cancelación: ' . $ex->getMessage()); }
        return true;
    }
}