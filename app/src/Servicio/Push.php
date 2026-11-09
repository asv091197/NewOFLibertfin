<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;

/**
 * AVISOS AUNQUE LIBERTYFIN ESTÉ CERRADO (Web Push).
 *
 * Quien lo activa (soporte desde la campana; el cliente desde su
 * reporte) deja su navegador SUSCRITO: el navegador le da a LibertyFin
 * una dirección única (`endpoint`) en el servicio de avisos de Google,
 * Mozilla o Apple. Cuando hay algo que avisar, el servidor "toca" esa
 * dirección y el navegador despierta a public/lf-sw.js, que muestra el
 * aviso aunque no haya ninguna pestaña abierta.
 *
 * EL TOQUE VA VACÍO, SIN CONTENIDO. El navegador, al despertar, viene a
 * preguntar qué decir (`aviso()`, identificándose con su propia
 * dirección, que solo conocen él, el servicio de avisos y nosotros). Así
 * no hay que cifrar mensajes ni instalar librerías; solo se firma el
 * toque con las llaves VAPID del servidor, que se generan solas.
 *
 * QUÉ DICE: quién y de qué ticket, NUNCA el texto del mensaje. Un aviso
 * de escritorio lo ve cualquiera que pase frente a la pantalla.
 *
 * Al salir con "Salir", ese navegador deja de recibir. Y si en ese
 * navegador entra otra persona, lo de la anterior se le quita: no hereda
 * sus avisos (ver SesionUnica::abrir).
 *
 * Nada de esto tumba nada: si falla, se anota y se sigue.
 */
final class Push
{
    private static $tabla = false;

    private static function db()
    {
        $db = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
        if (!self::$tabla) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS lf_push (
                    id       INT AUTO_INCREMENT PRIMARY KEY,
                    huella   CHAR(64)    NOT NULL,
                    endpoint TEXT        NOT NULL,
                    cuenta   VARCHAR(80) NOT NULL,
                    disp     CHAR(32)    NULL,
                    aviso    TEXT        NULL,
                    creado   DATETIME    NOT NULL,
                    usado    DATETIME    NULL,
                    UNIQUE KEY ix_push_huella (huella),
                    KEY ix_push_cuenta (cuenta),
                    KEY ix_push_disp (disp)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("
                CREATE TABLE IF NOT EXISTS lf_push_llaves (
                    clave VARCHAR(20) NOT NULL PRIMARY KEY,
                    valor TEXT        NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::$tabla = true;
        }
        return $db;
    }

    private static function b64($bin) { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }

    // ── Las llaves del servidor (VAPID) ─────────────────────────

    /**
     * ['publica' => base64url de 65 bytes, 'privada' => PEM], o null si el
     * servidor no puede generarlas (sin OpenSSL con curvas elípticas): en
     * ese caso los avisos siguen saliendo solo con la plataforma abierta.
     */
    private static function llaves()
    {
        static $ll = null;
        if ($ll !== null) return $ll ?: null;
        try {
            $db = self::db();
            $f = $db->query("SELECT clave, valor FROM lf_push_llaves")->fetchAll(\PDO::FETCH_KEY_PAIR);
            if (!empty($f['publica']) && !empty($f['privada'])) return $ll = $f;

            // Algunos servidores (sobre todo Windows) no encuentran la
            // configuración de OpenSSL y la llave no se genera: se prueba
            // también con la que trae PHP.
            $base = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
            $intentos = [$base];
            foreach ([getenv('OPENSSL_CONF'), dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
                      PHP_BINDIR . '/../extras/ssl/openssl.cnf'] as $cnf) {
                if ($cnf && is_file($cnf)) $intentos[] = $base + ['config' => $cnf];
            }
            $pem = null;
            foreach ($intentos as $op) {
                $k = @openssl_pkey_new($op);
                if ($k && @openssl_pkey_export($k, $pem, null, $op)) break;
                $k = null; $pem = null;
            }
            if (!$k || !$pem) {
                $err = '';
                while ($m = openssl_error_string()) $err .= ' ' . $m;
                throw new \RuntimeException('OpenSSL no generó la llave.' . $err);
            }
            $d = openssl_pkey_get_details($k);
            $x = str_pad($d['ec']['x'] ?? '', 32, "\0", STR_PAD_LEFT);
            $y = str_pad($d['ec']['y'] ?? '', 32, "\0", STR_PAD_LEFT);
            $nuevas = ['publica' => self::b64("\x04" . $x . $y), 'privada' => $pem];
            // INSERT IGNORE: si dos peticiones las generan a la vez, se
            // queda la primera y las dos usan esa.
            $st = $db->prepare("INSERT IGNORE INTO lf_push_llaves (clave, valor) VALUES (?,?)");
            foreach ($nuevas as $c => $v) $st->execute([$c, $v]);
            $f = $db->query("SELECT clave, valor FROM lf_push_llaves")->fetchAll(\PDO::FETCH_KEY_PAIR);
            return $ll = (!empty($f['publica']) && !empty($f['privada'])) ? $f : false;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] push (llaves): ' . $e->getMessage());
            $ll = false;
            return null;
        }
    }

    /** La llave pública, para que el navegador se suscriba. Null: no hay push. */
    public static function llavePublica()
    {
        $ll = self::llaves();
        return $ll ? $ll['publica'] : null;
    }

    // ── Suscripciones ───────────────────────────────────────────

    private static function huella($endpoint) { return hash('sha256', (string)$endpoint); }

    /**
     * Una dirección https de un servicio de avisos. No se usa
     * FILTER_VALIDATE_URL: las de Chrome llevan ":" dentro de la ruta y
     * alguna versión de PHP las rechazaba, y entonces no se podía activar.
     */
    private static function endpointValido($endpoint)
    {
        $endpoint = (string)$endpoint;
        return strlen($endpoint) < 1000
            && (bool)preg_match('#^https://[a-z0-9.\-]+(:\d+)?/\S+$#i', $endpoint);
    }

    /** Este navegador avisa a esta cuenta. Si avisaba a otra, deja de hacerlo. */
    public static function suscribir($cuenta, $endpoint, $disp)
    {
        if (!$cuenta || !self::endpointValido($endpoint)) return false;
        try {
            self::db()->prepare("
                INSERT INTO lf_push (huella, endpoint, cuenta, disp, creado, usado)
                VALUES (?,?,?,?,NOW(),NOW())
                ON DUPLICATE KEY UPDATE endpoint = VALUES(endpoint), cuenta = VALUES(cuenta),
                    disp = VALUES(disp), usado = NOW()
            ")->execute([self::huella($endpoint), $endpoint, $cuenta, $disp ?: null]);
            return true;
        } catch (\Throwable $e) {
            error_log('[LibertyFin] push (suscribir): ' . $e->getMessage());
            return false;
        }
    }

    public static function quitar($endpoint)
    {
        try {
            self::db()->prepare("DELETE FROM lf_push WHERE huella = ?")->execute([self::huella($endpoint)]);
        } catch (\Throwable $e) { /* se limpia sola cuando el servicio diga que ya no existe */ }
    }

    /**
     * Lo de este navegador que NO es de `$cuenta` se quita (otra persona
     * entró aquí). Con `$cuenta` null se quita todo lo de este navegador
     * (salió con "Salir").
     */
    public static function soltarNavegador($disp, $cuenta = null)
    {
        if (!$disp) return;
        try {
            if ($cuenta === null) {
                self::db()->prepare("DELETE FROM lf_push WHERE disp = ?")->execute([$disp]);
            } else {
                self::db()->prepare("DELETE FROM lf_push WHERE disp = ? AND cuenta <> ?")->execute([$disp, $cuenta]);
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] push (soltar): ' . $e->getMessage());
        }
    }

    /** Lo que el navegador viene a preguntar al despertar: qué mostrar. */
    public static function aviso($endpoint)
    {
        if (!self::endpointValido($endpoint)) return null;
        try {
            $db = self::db();
            $st = $db->prepare("SELECT id, aviso FROM lf_push WHERE huella = ?");
            $st->execute([self::huella($endpoint)]);
            $f = $st->fetch();
            if (!$f || !$f['aviso']) return null;
            $db->prepare("UPDATE lf_push SET usado = NOW() WHERE id = ?")->execute([(int)$f['id']]);
            $a = json_decode($f['aviso'], true);
            return is_array($a) ? $a : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Enviar ──────────────────────────────────────────────────

    /**
     * Avisa a una o varias cuentas, en todos sus navegadores suscritos.
     * `$aviso`: titulo, cuerpo, url, tag (el tag junta los avisos del
     * mismo ticket en uno solo, en vez de apilarlos).
     */
    public static function enviar(array $cuentas, array $aviso, $soloNavegador = null)
    {
        $res = [];
        $cuentas = array_values(array_unique(array_filter($cuentas)));
        if (!$cuentas) return $res;
        $ll = self::llaves();
        if (!$ll) return [['error' => 'El servidor no tiene llaves para los avisos (OpenSSL).']];
        if (!function_exists('curl_init')) return [['error' => 'El servidor no tiene curl.']];
        try {
            $db = self::db();
            $marcas = implode(',', array_fill(0, count($cuentas), '?'));
            $sql = "SELECT id, endpoint FROM lf_push WHERE cuenta IN ($marcas)";
            $par = $cuentas;
            if ($soloNavegador) { $sql .= " AND disp = ?"; $par[] = $soloNavegador; }
            $st = $db->prepare($sql);
            $st->execute($par);
            $subs = $st->fetchAll();
            if (!$subs) return $res;

            $json = json_encode($aviso, JSON_UNESCAPED_UNICODE);
            $guardar = $db->prepare("UPDATE lf_push SET aviso = ? WHERE id = ?");
            $borrar  = $db->prepare("DELETE FROM lf_push WHERE id = ?");
            $firmas  = [];
            foreach ($subs as $s) {
                $guardar->execute([$json, (int)$s['id']]);
                $partes = parse_url($s['endpoint']);
                $host = $partes['host'] ?? '';
                $aud = ($partes['scheme'] ?? 'https') . '://' . $host;
                if (!isset($firmas[$aud])) $firmas[$aud] = self::firma($aud, $ll);
                if (!$firmas[$aud]) {
                    $res[] = ['servicio' => $host, 'codigo' => 0, 'error' => 'No se pudo firmar el aviso (OpenSSL).'];
                    continue;
                }
                list($codigo, $cuerpo) = self::tocar($s['endpoint'], $firmas[$aud], $ll['publica']);
                $res[] = ['servicio' => $host, 'codigo' => $codigo, 'respuesta' => mb_substr($cuerpo, 0, 200)];
                // 404/410: el navegador ya no tiene esa suscripción.
                if ($codigo === 404 || $codigo === 410) $borrar->execute([(int)$s['id']]);
                elseif ($codigo < 200 || $codigo >= 300) {
                    error_log('[LibertyFin] push: ' . $host . ' contestó HTTP ' . $codigo . ' ' . mb_substr($cuerpo, 0, 200));
                }
            }
        } catch (\Throwable $e) {
            error_log('[LibertyFin] push (enviar): ' . $e->getMessage());
            $res[] = ['error' => 'Falló el envío en el servidor.'];
        }
        return $res;
    }

    /**
     * Un aviso de PRUEBA a este navegador, ahora mismo, y lo que contestó
     * el servicio de avisos. Se muestra aunque LibertyFin esté a la vista
     * (`forzar`): quien lo pide está mirando la pantalla.
     */
    public static function probar($cuenta, $disp)
    {
        return self::enviar([$cuenta], [
            'titulo' => 'Aviso de prueba de LibertyFin',
            'cuerpo' => 'Si ves esto, los avisos de escritorio funcionan en este navegador.',
            'url'    => '/panel',
            'tag'    => 'lf-prueba',
            'forzar' => true,
        ], $disp);
    }

    /** El JWT de VAPID (ES256) para un servicio de avisos. */
    private static function firma($aud, array $ll)
    {
        $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        $cab  = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $dato = self::b64(json_encode([
            'aud' => $aud,
            'exp' => time() + 12 * 3600,
            'sub' => $host !== '' ? 'https://' . $host : 'mailto:soporte@libertyfin.mx',
        ], JSON_UNESCAPED_SLASHES));
        $firmar = $cab . '.' . $dato;
        if (!openssl_sign($firmar, $der, $ll['privada'], OPENSSL_ALGO_SHA256)) return null;
        $raw = self::derARaw($der);
        return $raw ? $firmar . '.' . self::b64($raw) : null;
    }

    /** OpenSSL firma en DER; JWT pide r||s, 32 bytes cada uno. */
    private static function derARaw($der)
    {
        if (strlen($der) < 8 || ord($der[0]) !== 0x30) return null;
        $p = (ord($der[1]) & 0x80) ? 2 + (ord($der[1]) & 0x7f) : 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (!isset($der[$p + 1]) || ord($der[$p]) !== 0x02) return null;
            $largo = ord($der[$p + 1]);
            $v = ltrim(substr($der, $p + 2, $largo), "\0");
            $p += 2 + $largo;
            if (strlen($v) > 32) return null;
            $out .= str_pad($v, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    /** El toque vacío. Devuelve [código HTTP (0 si no hubo conexión), respuesta]. */
    private static function tocar($endpoint, $jwt, $publica)
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_HTTPHEADER     => [
                'TTL: 86400',
                'Urgency: high',
                'Content-Length: 0',
                'Authorization: vapid t=' . $jwt . ', k=' . $publica,
            ],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $cuerpo = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);
        return [$codigo, $cuerpo === false ? $error : trim((string)$cuerpo)];
    }

    // ── A quién ─────────────────────────────────────────────────

    /**
     * Un cliente escribió (o abrió un ticket): a quien lo atiende; si nadie
     * lo ha tomado, a todo soporte. Sin el texto: quién y de qué ticket.
     */
    public static function aSoporte(array $t, $esNuevo = false)
    {
        if (!empty($t['asignado_a'])) {
            $cuentas = [SesionUnica::cuenta('p', $t['asignado_a'])];
        } else {
            $cuentas = [];
            try {
                $filas = self::db()->query("SELECT id, rol FROM usuarios_plataforma WHERE activo = 1")->fetchAll();
                foreach ($filas as $u) {
                    if (Permisos::puede('ver.tickets', $u['rol'])) $cuentas[] = SesionUnica::cuenta('p', $u['id']);
                }
            } catch (\Throwable $e) { return; }
        }
        $empresa = trim((string)($t['nombre_empresa'] ?? ''));
        self::enviar($cuentas, [
            'titulo' => $esNuevo ? 'Nuevo ticket de un cliente' : 'Nuevo mensaje de un cliente',
            'cuerpo' => $t['folio'] . ($empresa !== '' ? ' · ' . $empresa : ''),
            'url'    => '/tickets/' . (int)$t['id'],
            'tag'    => 'lf-ticket-' . (int)$t['id'],
        ]);
    }

    /** Soporte contestó: solo a quien abrió el reporte. */
    public static function aCliente(array $t)
    {
        if (!in_array($t['creado_tipo'] ?? null, [null, '', 'empresa'], true)) return;
        if (empty($t['creado_por']) || empty($t['empresa_id'])) return;
        self::enviar([SesionUnica::cuenta('e', $t['creado_por'], $t['empresa_id'])], [
            'titulo' => 'Soporte respondió tu reporte',
            'cuerpo' => 'Reporte ' . $t['folio'] . '. Toca para ver la respuesta.',
            'url'    => '/ayuda?ver=' . (int)$t['id'],
            'tag'    => 'lf-ayuda-' . (int)$t['id'],
        ]);
    }
}
