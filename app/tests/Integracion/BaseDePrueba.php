<?php
declare(strict_types=1);

namespace LibertyFin\Pruebas\Integracion;

use LibertyFin\Datos\Conexion;
use LibertyFin\Servicio\CrearEmpresa;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas contra un MySQL de verdad.
 *
 * Arma DOS bases desechables, como en producción:
 *
 *   lf_prueba_principal  la de la plataforma (empresas, llaves de la API)
 *   lf_prueba_empresa    la de una empresa, cargada con config/esquema.sql
 *                        y las migraciones, igual que un alta nueva
 *
 * Se borran y se vuelven a crear UNA vez por corrida. Cada prueba crea
 * sus propios datos y no depende del orden.
 *
 * Variables (con sus valores por omisión):
 *   LF_PRUEBAS_BD_HOST     127.0.0.1   sin ella, estas pruebas se omiten
 *   LF_PRUEBAS_BD_PUERTO   3306
 *   LF_PRUEBAS_BD_USUARIO  root
 *   LF_PRUEBAS_BD_CLAVE    (vacía)
 *
 * NUNCA apuntes estas variables a un servidor con datos reales: las dos
 * bases se BORRAN al empezar.
 */
abstract class BaseDePrueba extends TestCase
{
    const PRINCIPAL = 'lf_prueba_principal';
    const EMPRESA   = 'lf_prueba_empresa';
    const EMPRESA_ID = 1;

    private static $preparadas = false;
    private static $erroresEsquema = [];

    public static function setUpBeforeClass(): void
    {
        $cfg = self::configuracion();
        if ($cfg === null) {
            self::markTestSkipped('Sin LF_PRUEBAS_BD_HOST: se omiten las pruebas de integración.');
        }
        if (!self::$preparadas) {
            self::preparar($cfg);
            self::$preparadas = true;
        }
        Conexion::configurar($cfg);
        $GLOBALS['lf_bd_principal'] = self::PRINCIPAL;
    }

    /** @return array|null */
    public static function configuracion()
    {
        $host = getenv('LF_PRUEBAS_BD_HOST');
        if ($host === false || $host === '') return null;
        return [
            'host'     => $host,
            'puerto'   => (int)(getenv('LF_PRUEBAS_BD_PUERTO') ?: 3306),
            'usuario'  => getenv('LF_PRUEBAS_BD_USUARIO') ?: 'root',
            'clave'    => getenv('LF_PRUEBAS_BD_CLAVE') ?: '',
            'zona_sql' => '-06:00',
        ];
    }

    private static function preparar(array $cfg)
    {
        $srv = new PDO("mysql:host={$cfg['host']};port={$cfg['puerto']};charset=utf8mb4",
            $cfg['usuario'], $cfg['clave'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ([self::PRINCIPAL, self::EMPRESA] as $b) {
            $srv->exec("DROP DATABASE IF EXISTS `$b`");
            $srv->exec("CREATE DATABASE `$b` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        Conexion::configurar($cfg);

        // La base de la empresa, exactamente como en un alta.
        $emp = Conexion::de(self::EMPRESA);
        self::$erroresEsquema = CrearEmpresa::cargarEsquema($emp, LF_RAIZ . '/config/esquema.sql');
        $emp->exec("INSERT INTO sucursales (nombre, es_matriz, activo) VALUES ('Matriz', 1, 1)");
        $emp->exec("INSERT INTO usuarios (username, password, nombre, rol, sucursal_id)
                    VALUES ('admin', '', 'Administración', 'admin', 1)");

        // La principal: solo lo que la API y la suscripción leen de ella.
        // La tabla `empresas` viene del sistema anterior y no la crea el
        // código; aquí va con las columnas que se consultan.
        $pri = Conexion::de(self::PRINCIPAL);
        $pri->exec("CREATE TABLE empresas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre_empresa VARCHAR(255) NOT NULL,
            giro_comercial VARCHAR(255) NULL, rfc VARCHAR(20) NULL, telefono VARCHAR(20) NULL,
            direccion TEXT NULL, nombre_contacto VARCHAR(255) NULL, email_admin VARCHAR(255) NULL,
            nombre_base_datos VARCHAR(100) NOT NULL,
            plan VARCHAR(50) NULL, fecha_vencimiento DATE NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1, no_distribuidor TINYINT(1) NOT NULL DEFAULT 0)");
        $pri->prepare("INSERT INTO empresas (id, nombre_empresa, nombre_base_datos, plan, fecha_vencimiento, activo)
                       VALUES (?, 'Estética de Prueba', ?, 'pro', DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 1)")
            ->execute([self::EMPRESA_ID, self::EMPRESA]);
    }

    /** Lo que no entró del esquema. Una prueba lo revisa. */
    protected static function erroresEsquema(): array { return self::$erroresEsquema; }

    protected function empresa(): PDO { return Conexion::de(self::EMPRESA); }
    protected function principal(): PDO { return Conexion::de(self::PRINCIPAL); }

    /** Un cliente nuevo, con nombre único. */
    protected function crearCliente(string $nombre = ''): int
    {
        $nombre = $nombre ?: 'Cliente ' . bin2hex(random_bytes(4));
        $this->empresa()->prepare("INSERT INTO clientes (nombre, telefono) VALUES (?, '5512345678')")
            ->execute([$nombre]);
        return (int)$this->empresa()->lastInsertId();
    }

    /** Una venta sin cobros, por el total indicado. */
    protected function crearVenta(float $total, ?int $clienteId = null, string $estado = 'completada'): int
    {
        // Único en la corrida: un contador, no el azar (dos ventas en el
        // mismo segundo chocaban con el índice único de codigo_venta).
        static $n = 0;
        $folio = 'P' . date('ymdHis') . str_pad((string)(++$n), 4, '0', STR_PAD_LEFT);
        $this->empresa()->prepare("
            INSERT INTO ventas (codigo_venta, cliente_id, usuario_id, sucursal_id, subtotal, total, estado, fecha)
            VALUES (?, ?, 1, 1, ?, ?, ?, NOW())")
            ->execute([$folio, $clienteId, $total, $total, $estado]);
        return (int)$this->empresa()->lastInsertId();
    }

    protected function crearProducto(string $nombre, float $precio): int
    {
        $this->empresa()->prepare("INSERT INTO productos (codigo, nombre, precio, costo) VALUES (?, ?, ?, 0)")
            ->execute(['P' . bin2hex(random_bytes(4)), $nombre, $precio]);
        return (int)$this->empresa()->lastInsertId();
    }
}
