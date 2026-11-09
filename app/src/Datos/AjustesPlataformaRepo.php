<?php
namespace LibertyFin\Datos;

use PDO;

/**
 * Interruptores globales de la plataforma.
 *
 * DOS NIVELES, Y EL GLOBAL MANDA
 *
 * Una sección o un método de pago está disponible si LOS DOS lo
 * permiten: el interruptor global de LibertyFin y el de la empresa.
 *
 *     disponible = global AND empresa
 *
 * No al revés, y no "el que se haya tocado al último". La razón es que
 * los dos interruptores contestan preguntas distintas:
 *
 *   · El global dice "esto funciona". Se apaga cuando Facturación está
 *     rota, cuando el proveedor de tarjeta se cayó, o cuando una función
 *     está a medias. Si una empresa pudiera encenderla igual, estaría
 *     usando algo que sabemos que no sirve.
 *
 *   · El de la empresa dice "esto lo uso". Se apaga porque ese negocio
 *     no cobra con tarjeta o no lleva comisiones.
 *
 * Apagar algo globalmente es una decisión de mantenimiento y tiene que
 * ganar. Apagarlo por empresa es una preferencia y no debe poder
 * encender lo que está roto.
 */
final class AjustesPlataformaRepo
{
    private $db;
    public function __construct(PDO $principal) { $this->db = $principal; }

    /** Las secciones que se pueden apagar. Igual que en ConfigRepo. */
    const SECCIONES = [
        'cobranza'    => 'Cobranza',
        'corte'       => 'Corte de caja',
        'comisiones'  => 'Comisiones',
        'gastos'      => 'Gastos',
        'reportes'    => 'Reportes',
        'recargas'    => 'Recargas',
        'facturacion' => 'Facturación',
        'ligas'       => 'Ligas de pago',
    ];

    /**
     * Los métodos de pago.
     *
     * `efectivo` NO está: apagarlo dejaría a un negocio sin poder cobrar
     * de ninguna forma en el mostrador, y no hay caso donde eso sea lo
     * que alguien quiso hacer.
     */
    const METODOS = [
        'transferencia' => 'Transferencia',
        'tarjeta'       => 'Tarjeta',
    ];

    private static $cache = null;

    private function asegurar()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS lf_plataforma_ajustes (
                    clave VARCHAR(80) NOT NULL PRIMARY KEY,
                    valor TEXT NULL,
                    nota VARCHAR(300) NULL,
                    actualizado DATETIME NULL,
                    actualizado_por VARCHAR(160) NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] lf_plataforma_ajustes: ' . $e->getMessage());
        }
    }

    private function todo()
    {
        if (self::$cache !== null) return self::$cache;
        $this->asegurar();
        try {
            $f = $this->db->query("SELECT clave, valor, nota FROM lf_plataforma_ajustes")->fetchAll();
            self::$cache = [];
            foreach ($f as $x) self::$cache[$x['clave']] = $x;
        } catch (\Throwable $e) { self::$cache = []; }
        return self::$cache;
    }

    public function activo($clave, $porDefecto = true)
    {
        $t = $this->todo();
        if (!isset($t[$clave])) return $porDefecto;
        return $t[$clave]['valor'] !== '0';
    }

    public function nota($clave)
    {
        $t = $this->todo();
        return $t[$clave]['nota'] ?? '';
    }

    /**
     * Apaga o enciende algo globalmente.
     *
     * Apagar EXIGE un motivo. Lo van a ver todas las empresas afectadas,
     * y sin él lo único que sabrían es que una función desapareció.
     */
    public function alternar($clave, $nota, $quien)
    {
        $this->asegurar();
        $prendido = $this->activo($clave);
        $nota = trim((string)$nota);
        if ($prendido && mb_strlen($nota) < 8) {
            throw new \InvalidArgumentException(
                'Escribe por qué se apaga: es lo único que van a ver las empresas afectadas');
        }
        $this->db->prepare("
            INSERT INTO lf_plataforma_ajustes (clave, valor, nota, actualizado, actualizado_por)
            VALUES (?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), nota = VALUES(nota),
                                    actualizado = NOW(), actualizado_por = VALUES(actualizado_por)
        ")->execute([$clave, $prendido ? '0' : '1', $prendido ? $nota : null, $quien]);
        self::$cache = null;
        return !$prendido;
    }

    /** El estado de todo, para la pantalla. */
    public function estado()
    {
        $r = ['secciones' => [], 'metodos' => []];
        foreach (self::SECCIONES as $k => $rotulo) {
            $r['secciones'][$k] = ['rotulo' => $rotulo, 'activa' => $this->activo('seccion.' . $k),
                                   'nota' => $this->nota('seccion.' . $k)];
        }
        foreach (self::METODOS as $k => $rotulo) {
            $r['metodos'][$k] = ['rotulo' => $rotulo, 'activa' => $this->activo('metodo.' . $k),
                                 'nota' => $this->nota('metodo.' . $k)];
        }
        return $r;
    }

    /**
     * Lo global, en forma de arreglo simple.
     * Se guarda en la sesión al entrar para no consultar la base
     * principal en cada página.
     */
    public function paraSesion()
    {
        $r = [];
        foreach (self::SECCIONES as $k => $_) $r['seccion.' . $k] = $this->activo('seccion.' . $k);
        foreach (self::METODOS as $k => $_)  $r['metodo.' . $k]  = $this->activo('metodo.' . $k);
        return $r;
    }
}
