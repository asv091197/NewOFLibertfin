<?php
namespace LibertyFin\Datos;

use PDO;

abstract class Repo
{
    protected $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * Prepara y ejecuta, comprobando antes que el número de parámetros
     * coincida con el de marcadores.
     *
     * Sin esto, pasar un parámetro de más devuelve
     * "SQLSTATE[HY093]: Invalid parameter number", que no dice ni qué
     * consulta ni cuántos sobraban. Con la comprobación, el mensaje
     * apunta directo al problema.
     */
    private function correr($sql, array $p)
    {
        $marcadores = substr_count($sql, '?');
        if ($marcadores !== count($p)) {
            throw new \LogicException(sprintf(
                '%s: la consulta tiene %d marcadores y se pasaron %d parámetros. %s',
                static::class, $marcadores, count($p),
                trim(preg_replace('/\s+/', ' ', substr($sql, 0, 160))) . '…'
            ));
        }
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st;
    }

    protected function todos($sql, array $p = [])
    { return $this->correr($sql, $p)->fetchAll(); }

    protected function uno($sql, array $p = [])
    { $r = $this->correr($sql, $p)->fetch(); return $r ?: null; }

    protected function valor($sql, array $p = [])
    { return $this->correr($sql, $p)->fetchColumn(); }
}
