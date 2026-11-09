<?php
namespace LibertyFin\Datos;

use PDO;

/**
 * Base de conocimientos y plantillas de respuesta.
 *
 * Vive en la base PRINCIPAL: un artículo sobre "por qué el reporte sale
 * en ceros" sirve para todas las empresas, no para una. Repetirlo por
 * empresa garantiza que treinta copias se desincronicen.
 *
 * Las plantillas están aquí y no en otra tabla porque son lo mismo con
 * otro uso: un texto que alguien escribió una vez para no volver a
 * escribirlo. La diferencia es a quién va dirigido.
 */
final class BaseConocimientoRepo
{
    private $db;
    public function __construct(PDO $principal) { $this->db = $principal; }

    const TIPOS = [
        'articulo'  => 'Artículo',
        'plantilla' => 'Plantilla de respuesta',
        'error'     => 'Error conocido',
    ];

    const AREAS = [
        'acceso'      => 'Acceso y contraseñas',
        'cobro'       => 'Caja y cobros',
        'cifras'      => 'Números y reportes',
        'facturacion' => 'Facturación',
        'catalogo'    => 'Productos y precios',
        'usuarios'    => 'Usuarios y permisos',
        'recargas'    => 'Recargas',
        'plataforma'  => 'Plataforma y altas',
    ];

    public function asegurar()
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS lf_conocimiento (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tipo VARCHAR(20) NOT NULL DEFAULT 'articulo',
                area VARCHAR(30) NOT NULL DEFAULT 'acceso',
                titulo VARCHAR(220) NOT NULL,
                cuerpo MEDIUMTEXT NOT NULL,
                sintoma VARCHAR(300) NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                vistas INT NOT NULL DEFAULT 0,
                usos INT NOT NULL DEFAULT 0,
                autor_id INT NULL,
                autor_nombre VARCHAR(160) NULL,
                creado_en DATETIME NOT NULL,
                actualizado_en DATETIME NULL,
                KEY ix_bc_tipo (tipo, activo),
                KEY ix_bc_area (area),
                FULLTEXT KEY ix_bc_texto (titulo, cuerpo, sintoma)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function guardar($id, array $d, $usuarioId, $usuarioNombre)
    {
        $this->asegurar();
        $titulo = trim($d['titulo'] ?? '');
        if (mb_strlen($titulo) < 6) {
            throw new \InvalidArgumentException('El título tiene que decir de qué trata');
        }
        $cuerpo = trim($d['cuerpo'] ?? '');
        if (mb_strlen($cuerpo) < 20) {
            throw new \InvalidArgumentException('Escribe el procedimiento completo, no una nota suelta');
        }
        $tipo = isset(self::TIPOS[$d['tipo'] ?? '']) ? $d['tipo'] : 'articulo';
        $area = isset(self::AREAS[$d['area'] ?? '']) ? $d['area'] : 'acceso';

        // El SÍNTOMA es lo que hace buscable un artículo. Nadie busca
        // "zona horaria": buscan "mis ventas salen en el mes equivocado".
        $sintoma = trim($d['sintoma'] ?? '');
        if ($tipo === 'error' && mb_strlen($sintoma) < 8) {
            throw new \InvalidArgumentException(
                'Para un error conocido, describe el SÍNTOMA: es como lo va a buscar quien lo sufra');
        }

        if ($id) {
            $this->db->prepare("
                UPDATE lf_conocimiento SET tipo=?, area=?, titulo=?, cuerpo=?, sintoma=?,
                       actualizado_en=NOW() WHERE id=?
            ")->execute([$tipo, $area, $titulo, $cuerpo, $sintoma ?: null, (int)$id]);
            return (int)$id;
        }
        $this->db->prepare("
            INSERT INTO lf_conocimiento (tipo, area, titulo, cuerpo, sintoma,
                                         autor_id, autor_nombre, creado_en)
            VALUES (?,?,?,?,?,?,?,NOW())
        ")->execute([$tipo, $area, $titulo, $cuerpo, $sintoma ?: null,
                     $usuarioId ?: null, $usuarioNombre]);
        return (int)$this->db->lastInsertId();
    }

    public function uno($id)
    {
        $this->asegurar();
        $st = $this->db->prepare("SELECT * FROM lf_conocimiento WHERE id = ?");
        $st->execute([(int)$id]);
        return $st->fetch() ?: null;
    }

    public function ver($id)
    {
        try { $this->db->prepare("UPDATE lf_conocimiento SET vistas = vistas + 1 WHERE id = ?")
                       ->execute([(int)$id]); } catch (\Throwable $e) {}
        return $this->uno($id);
    }

    public function usar($id)
    {
        try { $this->db->prepare("UPDATE lf_conocimiento SET usos = usos + 1 WHERE id = ?")
                       ->execute([(int)$id]); } catch (\Throwable $e) {}
    }

    /**
     * Busca. Con texto usa el índice de texto completo; sin él, lista.
     *
     * Se busca en título, cuerpo y síntoma a la vez: quien tiene el
     * problema no sabe cómo se llama la solución.
     */
    public function buscar($q = '', $tipo = '', $area = '', $limite = 40)
    {
        $this->asegurar();
        $w = ['activo = 1']; $p = [];
        if ($tipo !== '') { $w[] = 'tipo = ?'; $p[] = $tipo; }
        if ($area !== '') { $w[] = 'area = ?'; $p[] = $area; }

        $orden = 'actualizado_en DESC, creado_en DESC';
        if ($q !== '') {
            // LIKE y no MATCH: el índice de texto completo necesita
            // palabras de 4 letras o más por omisión, y medio soporte se
            // busca con "IVA", "SAT" o "CFDI".
            $w[] = '(titulo LIKE ? OR cuerpo LIKE ? OR sintoma LIKE ?)';
            $l = '%' . $q . '%'; $p[] = $l; $p[] = $l; $p[] = $l;
            $orden = 'usos DESC, vistas DESC';
        }
        $st = $this->db->prepare("SELECT * FROM lf_conocimiento WHERE " . implode(' AND ', $w)
            . " ORDER BY {$orden} LIMIT " . (int)$limite);
        $st->execute($p);
        return $st->fetchAll();
    }

    public function plantillas()
    {
        $this->asegurar();
        return $this->db->query("
            SELECT id, titulo, cuerpo, area FROM lf_conocimiento
            WHERE tipo = 'plantilla' AND activo = 1
            ORDER BY usos DESC, titulo")->fetchAll();
    }

    public function alternar($id)
    {
        $this->asegurar();
        $this->db->prepare("UPDATE lf_conocimiento SET activo = 1 - activo WHERE id = ?")
                 ->execute([(int)$id]);
        return true;
    }

    /** Lo que más se consulta contra lo que no se consulta nunca. */
    public function cifras()
    {
        $this->asegurar();
        return $this->db->query("
            SELECT COUNT(*) total,
                   SUM(tipo='articulo') articulos,
                   SUM(tipo='plantilla') plantillas,
                   SUM(tipo='error') errores,
                   SUM(vistas = 0 AND usos = 0) sin_usar
            FROM lf_conocimiento WHERE activo = 1")->fetch() ?: [];
    }
}
