<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\Conexion;
use PDO;

/**
 * Alta de una empresa nueva.
 *
 * CÓMO ERA ANTES, Y POR QUÉ CAMBIA
 *
 * `registroEmpresa.php` era una página PÚBLICA, sin sesión, que creaba
 * bases de datos llamando a la API de cPanel con el token escrito dentro
 * del archivo. Cualquiera que diera con esa URL podía crear bases en el
 * hosting hasta llenarlo.
 *
 * Aquí el alta sigue siendo autoservicio —es lo que el negocio necesita—
 * pero con tres cosas que antes no había:
 *
 *   · Las credenciales de cPanel viven en config/integraciones.php.
 *   · Sin ellas, el registro simplemente no existe: la ruta da 404.
 *   · Se guarda como SOLICITUD, no como empresa activa. La base se crea
 *     cuando alguien de LibertyFin la aprueba.
 *
 * Ese último punto es el que de verdad protege: una solicitud cuesta un
 * renglón en una tabla; una base de datos cuesta espacio, un usuario de
 * MySQL y una entrada en el respaldo.
 */
final class CrearEmpresa
{
    /** Los giros que se ofrecen en el registro. «Otro» deja escribirlo. */
    /** Días de la prueba gratuita: de aquí sale el vencimiento y el texto del registro. */
    const DIAS_PRUEBA = 15;

    const GIROS = [
        'Contabilidad y fiscal', 'Despacho legal', 'Marketing y publicidad', 'Consultoría',
        'Salud y bienestar', 'Belleza y estética',
        'Tienda o comercio', 'Restaurante o alimentos', 'Otro',
    ];

    /** Cuántas personas trabajan en el negocio: sirve para acompañar el alta. */
    const EMPLEADOS = ['1' => 'Solo yo', '2-5' => 'De 2 a 5', '6-20' => 'De 6 a 20',
                       '21-50' => 'De 21 a 50', '50+' => 'Más de 50'];

    private $principal;
    public function __construct(PDO $principal) { $this->principal = $principal; }

    /** Se crea sola la primera vez. */
    private function asegurar()
    {
        $this->principal->exec("
            CREATE TABLE IF NOT EXISTS solicitudes_empresa (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre_empresa VARCHAR(200) NOT NULL,
                giro_comercial VARCHAR(200) NULL,
                rfc VARCHAR(20) NULL,
                nombre_contacto VARCHAR(200) NOT NULL,
                email_admin VARCHAR(160) NOT NULL,
                telefono VARCHAR(30) NULL,
                no_distribuidor VARCHAR(50) NULL,
                plan VARCHAR(40) NULL,
                estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                motivo_rechazo VARCHAR(400) NULL,
                empresa_id INT NULL,
                ip VARCHAR(45) NULL,
                creada_en DATETIME NOT NULL,
                resuelta_en DATETIME NULL,
                resuelta_por INT NULL,
                KEY ix_se_estado (estado),
                KEY ix_se_email (email_admin)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** @throws \InvalidArgumentException con un mensaje apto para mostrar */
    public function solicitar(array $d, $ip)
    {
        $this->asegurar();

        $nombre = trim($d['nombre_empresa'] ?? '');
        if (mb_strlen($nombre) < 3) {
            throw new \InvalidArgumentException('Escribe el nombre de tu negocio');
        }
        $contacto = trim($d['nombre_contacto'] ?? '');
        if (mb_strlen($contacto) < 3) {
            throw new \InvalidArgumentException('Escribe tu nombre');
        }
        $email = trim($d['email_admin'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo no es válido');
        }
        $rfc = mb_strtoupper(trim($d['rfc'] ?? ''));
        if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            throw new \InvalidArgumentException('El RFC no tiene el formato correcto');
        }
        // El teléfono: es por donde se le acompaña en el alta. Al menos los
        // 10 dígitos de un número en México; la lada internacional, opcional.
        $tel = preg_replace('/[^0-9+]/', '', (string)($d['telefono'] ?? ''));
        if (strlen(preg_replace('/\D/', '', $tel)) < 10) {
            throw new \InvalidArgumentException('Escribe un teléfono de 10 dígitos');
        }
        $empleados = (string)($d['empleados'] ?? '');
        if ($empleados !== '' && !isset(self::EMPLEADOS[$empleados])) $empleados = '';

        // Un correo, una solicitud pendiente. Sin esto, recargar el
        // formulario llena la tabla de duplicados y quien revisa no sabe
        // cuál atender.
        $st = $this->principal->prepare("
            SELECT COUNT(*) FROM solicitudes_empresa
            WHERE email_admin = ? AND estado = 'pendiente'");
        $st->execute([$email]);
        if ((int)$st->fetchColumn() > 0) {
            throw new \InvalidArgumentException(
                'Ya hay una solicitud en revisión con ese correo. Te avisamos en cuanto esté lista.');
        }

        // Y un tope por IP: una página pública sin tope es una invitación.
        $st = $this->principal->prepare("
            SELECT COUNT(*) FROM solicitudes_empresa
            WHERE ip = ? AND creada_en > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() >= 3) {
            throw new \InvalidArgumentException('Demasiadas solicitudes. Inténtalo más tarde.');
        }

        $conEmpleados = $this->asegurarEmpleados();
        $this->principal->prepare("
            INSERT INTO solicitudes_empresa
                (nombre_empresa, giro_comercial, rfc, nombre_contacto, email_admin,
                 telefono, no_distribuidor, plan, estado, ip, creada_en"
                 . ($conEmpleados ? ", empleados" : "") . ")
            VALUES (?,?,?,?,?,?,?,?, 'pendiente', ?, NOW()" . ($conEmpleados ? ",?" : "") . ")
        ")->execute(array_merge([
            $nombre, mb_substr(trim($d['giro_comercial'] ?? ''), 0, 200) ?: null, $rfc ?: null,
            $contacto, $email, $tel,
            mb_substr(trim($d['no_distribuidor'] ?? ''), 0, 50) ?: null,
            trim($d['plan'] ?? '') ?: 'prueba',
            $ip,
        ], $conEmpleados ? [$empleados ?: null] : []));
        return (int)$this->principal->lastInsertId();
    }

    /**
     * La columna `empleados` llegó después que la tabla: se agrega si falta.
     * Si no se puede (permisos), la solicitud se guarda igual, sin ese dato.
     */
    private function asegurarEmpleados()
    {
        try {
            $st = $this->principal->query("SHOW COLUMNS FROM solicitudes_empresa LIKE 'empleados'");
            if ($st->fetch()) return true;
            $this->principal->exec("ALTER TABLE solicitudes_empresa ADD COLUMN empleados VARCHAR(10) NULL");
            return true;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] solicitudes.empleados: ' . $e->getMessage());
            return false;
        }
    }

    public function pendientes()
    {
        $this->asegurar();
        $st = $this->principal->query("
            SELECT *, TIMESTAMPDIFF(HOUR, creada_en, NOW()) AS horas
            FROM solicitudes_empresa WHERE estado = 'pendiente'
            ORDER BY creada_en");
        return $st->fetchAll();
    }

    /**
     * Aprueba una solicitud: crea la base, carga el esquema, corre las
     * migraciones y deja al administrador listo para entrar.
     *
     * Devuelve la contraseña generada UNA sola vez. No se guarda en claro
     * en ningún lado: se muestra a quien aprueba para que la entregue.
     */
    public function aprobar($id, $raiz, $revisorId, array $opciones = [])
    {
        $this->asegurar();

        $st = $this->principal->prepare("SELECT * FROM solicitudes_empresa WHERE id = ?");
        $st->execute([(int)$id]);
        $s = $st->fetch();
        if (!$s) throw new \InvalidArgumentException('Esa solicitud no existe');
        if ($s['estado'] !== 'pendiente') throw new \InvalidArgumentException('Ya estaba resuelta');

        $r = $this->alta($s, $raiz, $opciones);

        $this->principal->prepare("
            UPDATE solicitudes_empresa
            SET estado = 'aprobada', empresa_id = ?, resuelta_en = NOW(), resuelta_por = ?
            WHERE id = ?")->execute([$r['empresa_id'], $revisorId ?: null, (int)$id]);

        return $r;
    }

    /**
     * El alta en sí: base, esquema, migraciones, matriz, administrador y
     * el renglón en `empresas`.
     *
     * Vive aparte de aprobar() porque hay dos caminos que llegan aquí y
     * tienen que hacer EXACTAMENTE lo mismo: la pantalla de revisión y
     * `bin/crear-empresa.php`. Con el código duplicado, el día que cambie
     * una tabla solo se arregla uno de los dos y el otro deja empresas a
     * medias que nadie nota hasta que el cliente entra.
     *
     * @param array $s         nombre_empresa, nombre_contacto, email_admin y
     *                         lo demás que haya (giro, rfc, teléfono, plan…)
     * @param array $opciones  'base'    usar una base YA creada a mano
     *                         'usuario' nombre de usuario del administrador
     *                         'clave'   contraseña en vez de una generada
     *                         'dias'    días de vigencia (0 = sin vencimiento)
     */
    public function alta(array $s, $raiz, array $opciones = [])
    {
        $this->asegurar();

        $nombre = trim((string)($s['nombre_empresa'] ?? ''));
        $email  = trim((string)($s['email_admin'] ?? ''));
        if ($nombre === '') throw new \InvalidArgumentException('Falta el nombre de la empresa');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo del administrador no es válido');
        }

        $esquema = $raiz . '/config/esquema.sql';
        if (!is_readable($esquema)) {
            throw new \RuntimeException('No se encuentra config/esquema.sql');
        }

        // ── La base ──
        // Dos caminos. El normal la pide a cPanel. El manual recibe una que
        // ya existe, creada desde el panel: sirve cuando la API de cPanel no
        // contesta y no se puede dejar a un cliente esperando por eso.
        $base = trim((string)($opciones['base'] ?? ''));
        if ($base === '') {
            $cp = Integraciones::de('cpanel');
            if (!$cp) {
                throw new \InvalidArgumentException(
                    'Faltan las credenciales de cPanel en config/integraciones.php: '
                    . 'sin ellas no se puede crear la base de la empresa');
            }
            // El nombre de la base sale del nombre del negocio, limpio y corto.
            // cPanel le antepone el usuario de la cuenta, así que el prefijo no
            // se escribe aquí.
            $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $nombre));
            $sufijo = substr($slug, 0, 8) . substr(bin2hex(random_bytes(3)), 0, 4);
            $base = $cp['usuario'] . '_' . $sufijo;

            $creada = self::crearBaseEnCpanel($cp, $sufijo);
            if (!$creada['ok']) {
                throw new \RuntimeException('cPanel no creó la base: ' . $creada['error']);
            }
        }

        // Ya hay una empresa apuntando ahí: seguir dejaría dos empresas
        // compartiendo ventas, clientes y caja sin saberlo.
        $st = $this->principal->prepare("SELECT id, nombre_empresa FROM empresas WHERE nombre_base_datos = ?");
        $st->execute([$base]);
        if ($ya = $st->fetch()) {
            throw new \RuntimeException(
                'La base ' . $base . ' ya es de la empresa #' . $ya['id'] . ' («' . $ya['nombre_empresa'] . '»)');
        }

        // Cargar el esquema y ponerlo al día.
        $db = Conexion::de($base);
        $errores = self::cargarEsquema($db, $esquema);

        // Si el esquema no entró, lo siguiente falla con «Table 'usuarios'
        // doesn't exist»: un mensaje que manda a buscar el problema a la
        // tabla equivocada. El problema está en el esquema, y aquí se dice.
        foreach (['sucursales', 'usuarios'] as $t) {
            try { $db->query("SELECT 1 FROM `$t` LIMIT 1"); }
            catch (\Throwable $e) {
                throw new \RuntimeException(
                    'El esquema no cargó en ' . $base . ': falta la tabla ' . $t
                    . ($errores ? '. Primer error: ' . $errores[0] : '')
                    . '. Revisa que el usuario de MySQL tenga permiso para crear tablas en esa base.');
            }
        }

        // Una base dada a mano puede no estar vacía. Si ya tiene usuarios,
        // es de alguien: no se le agrega un administrador encima.
        if (trim((string)($opciones['base'] ?? '')) !== '' && empty($opciones['forzar'])) {
            $n = (int)$db->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
            if ($n > 0) {
                throw new \RuntimeException(
                    'La base ' . $base . ' ya tiene ' . $n . ' usuario(s): no está vacía');
            }
        }

        // Sucursal matriz y administrador.
        $sucursal = (int)$db->query("SELECT id FROM sucursales ORDER BY es_matriz DESC, id LIMIT 1")->fetchColumn();
        if (!$sucursal) {
            $db->exec("INSERT INTO sucursales (nombre, es_matriz, activo, fecha_creacion)
                       VALUES ('Matriz', 1, 1, NOW())");
            $sucursal = (int)$db->lastInsertId();
        }

        // Sus almacenes (Piso y Bodega). Va aquí y no en la migración
        // porque cargarEsquema() migra al final, y en ese momento la
        // sucursal de arriba todavía no existía.
        try { (new Almacenes($db))->asegurar($sucursal); }
        catch (\Throwable $e) { error_log('[LibertyFin] almacenes del alta: ' . $e->getMessage()); }

        $usuario = trim((string)($opciones['usuario'] ?? ''));
        if ($usuario === '') {
            $usuario = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('@', $email)[0]));
        }
        $usuario = substr($usuario ?: 'admin', 0, 30);
        $clave   = (string)($opciones['clave'] ?? '') ?: self::clave();
        $db->prepare("
            INSERT INTO usuarios (username, password, nombre, email, rol, sucursal_id)
            VALUES (?,?,?,?, 'admin', ?)
        ")->execute([$usuario, password_hash($clave, PASSWORD_DEFAULT),
                     $s['nombre_contacto'] ?? $usuario, $email, $sucursal]);

        // Estrena: su administrador verá primero las preguntas de la
        // configuración inicial, con lo que dijo en el registro ya puesto.
        try {
            Arranque::marcarPendiente($db, $s['giro_comercial'] ?? null, $s['empleados'] ?? null);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] arranque pendiente: ' . $e->getMessage());
        }

        // Y el renglón en empresas, ya activa.
        $dias = array_key_exists('dias', $opciones) ? (int)$opciones['dias'] : self::DIAS_PRUEBA;
        $vence = $dias > 0 ? 'DATE_ADD(CURDATE(), INTERVAL ' . $dias . ' DAY)' : 'NULL';
        $this->principal->prepare("
            INSERT INTO empresas
                (nombre_empresa, giro_comercial, rfc, no_distribuidor, telefono,
                 nombre_contacto, email_admin, usuario_admin, nombre_base_datos,
                 activo, plan, fecha_vencimiento)
            VALUES (?,?,?,?,?,?,?,?,?, 1, ?, " . $vence . ")
        ")->execute([
            $nombre, $s['giro_comercial'] ?? null, $s['rfc'] ?? null, $s['no_distribuidor'] ?? null,
            $s['telefono'] ?? null, $s['nombre_contacto'] ?? null, $email, $usuario, $base,
            ($s['plan'] ?? '') ?: 'prueba',
        ]);
        $empresaId = (int)$this->principal->lastInsertId();

        return ['base' => $base, 'usuario' => $usuario, 'clave' => $clave,
                'empresa_id' => $empresaId, 'email' => $email,
                'contacto' => $s['nombre_contacto'] ?? '', 'empresa' => $nombre,
                'errores_esquema' => $errores];
    }

    /**
     * Carga config/esquema.sql en una base vacía y la pone al día.
     *
     * Aparte del alta para que las pruebas armen sus bases EXACTAMENTE
     * como se arma la de una empresa nueva: si el esquema o una migración
     * deja de cargar en MySQL, lo dice la prueba y no el primer cliente.
     *
     * @return string[] los errores de las sentencias que no entraron
     */
    public static function cargarEsquema(PDO $db, $archivo)
    {
        $sql = file_get_contents($archivo);
        // Se quitan los comentarios de línea ANTES de partir por ";". Si no,
        // el encabezado del archivo se pega a la primera sentencia
        // (CREATE TABLE sucursales), esta empieza con "--" y se descartaba
        // completa: sin sucursales fallaban también las tablas con llave
        // foránea hacia ella y el alta terminaba con "1146 table doesn't exist".
        $sql = preg_replace('/^[ \t]*--.*$/m', '', $sql);
        $errores = [];
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $sent) {
            if ($sent === '') continue;
            try { $db->exec($sent); }
            catch (\Throwable $e) {
                $errores[] = $e->getMessage();
                error_log('[LibertyFin] esquema: ' . $e->getMessage());
            }
        }
        Migraciones::aplicar($db);
        return $errores;
    }

    public function rechazar($id, $motivo, $revisorId)
    {
        $this->asegurar();
        $motivo = trim((string)$motivo);
        if (mb_strlen($motivo) < 10) {
            throw new \InvalidArgumentException(
                'Escribe por qué se rechaza: es lo único que el solicitante va a recibir');
        }
        $st = $this->principal->prepare("SELECT * FROM solicitudes_empresa WHERE id = ?");
        $st->execute([(int)$id]);
        $sol = $st->fetch();

        $this->principal->prepare("
            UPDATE solicitudes_empresa
            SET estado = 'rechazada', motivo_rechazo = ?, resuelta_en = NOW(), resuelta_por = ?
            WHERE id = ? AND estado = 'pendiente'")->execute([$motivo, $revisorId ?: null, (int)$id]);

        // Se devuelve la solicitud para poder avisarle a quien la mandó.
        return $sol ?: true;
    }

    /**
     * Una contraseña que se pueda dictar por teléfono.
     *
     * Sin l, I, 1, O ni 0: en una llamada nadie distingue esos, y la
     * primera experiencia con el sistema no puede ser "no puedo entrar".
     */
    private static function clave($largo = 12)
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $r = '';
        for ($i = 0; $i < $largo; $i++) $r .= $abc[random_int(0, strlen($abc) - 1)];
        return $r;
    }

    /**
     * El host del panel, tal como lo pide curl.
     *
     * En `integraciones.php` esto lo escribe una persona, y lo escribe como
     * lo tiene a la vista: unas veces `midominio.com`, otras pegado del
     * navegador con `https://` y `:2083` incluidos. El código le ponía el
     * esquema y el puerto por su cuenta, así que lo segundo armaba
     * `https://https://midominio.com:2083:2083/execute/...`, curl no llegaba
     * a ningún lado y el alta terminaba con «respuesta inesperada»: un
     * mensaje que no apunta al archivo donde está el error.
     *
     * Se acepta cualquiera de las dos formas y se deja solo el nombre.
     */
    public static function hostCpanel($host)
    {
        $h = trim((string)$host);
        $h = preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $h);   // fuera el esquema
        $h = explode('/', $h)[0];                                 // fuera la ruta
        $h = preg_replace('~:\d+$~', '', $h);                     // fuera el puerto
        return trim($h, ". \t");
    }

    /** El usuario de MySQL al que se le dan los permisos sobre la base nueva. */
    public static function usuarioBd(array $cp)
    {
        $u = trim((string)($cp['usuario_bd'] ?? ''));
        if ($u === '') return (string)$cp['usuario'];
        // Se acepta tanto `alexis` como `juanc141_alexis`: en cPanel el
        // nombre completo lleva el prefijo de la cuenta, y quien lo copia
        // del panel lo copia completo.
        $pre = $cp['usuario'] . '_';
        return strncmp($u, $pre, strlen($pre)) === 0 ? $u : $pre . $u;
    }

    /**
     * Llama a la API de cPanel para crear la base y darle permisos.
     *
     * Devuelve ['ok', 'error', 'detalle'] — `detalle` trae código HTTP,
     * tipo de contenido y el principio de la respuesta, que es lo único
     * que permite distinguir «el token no sirve» de «el puerto 2083 está
     * cerrado» de «contestó Cloudflare». Nunca incluye el token.
     */
    private static function crearBaseEnCpanel(array $cp, $sufijo)
    {
        $host = self::hostCpanel($cp['host'] ?? '');
        if ($host === '') {
            return ['ok' => false, 'error' => 'falta `host` en el bloque cpanel de integraciones.php',
                    'detalle' => ''];
        }

        $llamar = function ($modulo, $funcion, $params) use ($cp, $host) {
            $url = 'https://' . $host . ':2083/execute/' . $modulo . '/' . $funcion
                 . '?' . http_build_query($params);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $cp['usuario'] . ':' . $cp['token']],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 15,
                // El certificado SÍ se verifica. El sistema anterior lo
                // desactivaba, y eso convierte HTTPS en decoración.
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $r    = curl_exec($ch);
            $err  = curl_error($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $tipo = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            $donde = $modulo . '/' . $funcion . ' en ' . $host . ':2083';
            if ($r === false) {
                return ['ok' => false, 'error' => 'no se pudo conectar a ' . $donde . ': ' . $err,
                        'detalle' => $err];
            }
            $j = json_decode($r, true);
            if (is_array($j) && array_key_exists('status', $j)) {
                if (!empty($j['status'])) return ['ok' => true, 'error' => '', 'detalle' => ''];
                $e = $j['errors'][0] ?? ($j['error'] ?? 'cPanel contestó status 0 sin decir por qué');
                return ['ok' => false, 'error' => $e, 'detalle' => 'HTTP ' . $http];
            }

            // No es JSON de la API: contestó otra cosa. Lo que sea que
            // haya contestado es la pista, así que se devuelve.
            $frag = trim(preg_replace('/\s+/', ' ', strip_tags((string)$r)));
            $frag = mb_substr($frag, 0, 160);
            $pista = $http === 401 || $http === 403
                ? 'el token de cPanel no sirve, está vencido o no es de la cuenta ' . $cp['usuario']
                : ($http === 0 ? 'no hubo respuesta (¿puerto 2083 cerrado?)'
                               : 'contestó algo que no es la API de cPanel');
            return ['ok' => false,
                    'error'   => $pista . ' · ' . $donde . ' · HTTP ' . $http,
                    'detalle' => 'HTTP ' . $http . ' · ' . ($tipo ?: 'sin tipo') . ' · ' . $frag];
        };

        $r = $llamar('Mysql', 'create_database', ['name' => $cp['usuario'] . '_' . $sufijo]);
        if (!$r['ok']) return $r;
        return $llamar('Mysql', 'set_privileges_on_database', [
            'user'       => self::usuarioBd($cp),
            'database'   => $cp['usuario'] . '_' . $sufijo,
            'privileges' => 'ALL PRIVILEGES',
        ]);
    }

    /**
     * Prueba la conexión con cPanel sin crear nada.
     *
     * Usa `Mysql/list_databases`, que solo lee. Lo llama
     * `bin/crear-empresa.php --revisar`.
     */
    public static function probarCpanel(array $cp)
    {
        $host = self::hostCpanel($cp['host'] ?? '');
        if ($host === '') return ['ok' => false, 'error' => 'falta `host`', 'detalle' => '', 'bases' => []];

        $url = 'https://' . $host . ':2083/execute/Mysql/list_databases';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $cp['usuario'] . ':' . $cp['token']],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $r    = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tipo = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($r === false) {
            return ['ok' => false, 'error' => 'no se pudo conectar a ' . $host . ':2083',
                    'detalle' => $err, 'bases' => []];
        }
        $j = json_decode($r, true);
        if (is_array($j) && !empty($j['status'])) {
            $bases = [];
            foreach ((array)($j['data'] ?? []) as $d) {
                $bases[] = is_array($d) ? ($d['database'] ?? reset($d)) : $d;
            }
            return ['ok' => true, 'error' => '', 'detalle' => 'HTTP ' . $http, 'bases' => $bases];
        }
        if (is_array($j)) {
            return ['ok' => false, 'error' => $j['errors'][0] ?? 'cPanel contestó status 0',
                    'detalle' => 'HTTP ' . $http, 'bases' => []];
        }
        $frag = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$r))), 0, 200);
        return ['ok' => false,
                'error'   => $http === 401 || $http === 403
                           ? 'el token no sirve o no es de la cuenta ' . $cp['usuario'] . ' (HTTP ' . $http . ')'
                           : 'la respuesta no es JSON de cPanel (HTTP ' . $http . ')',
                'detalle' => ($tipo ?: 'sin tipo') . ' · ' . $frag, 'bases' => []];
    }
}