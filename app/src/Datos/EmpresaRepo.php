<?php
namespace LibertyFin\Datos;

use PDO;

/**
 * Datos de la empresa. Viven en la base PRINCIPAL, no en la de la empresa.
 *
 * Solo se pueden editar los datos de contacto y fiscales. El nombre de la
 * base, sus credenciales y la fecha de vencimiento NO se tocan desde aquí:
 * cambiarlos dejaría a la empresa sin poder entrar, y eso es trabajo de
 * quien administra la plataforma, no de quien administra el negocio.
 */
final class EmpresaRepo
{
    private $principal;
    public function __construct(PDO $principal) { $this->principal = $principal; }

    /** Solo estos campos son editables desde la app. */
    const EDITABLES = ['nombre_empresa','giro_comercial','rfc','telefono',
                       'direccion','nombre_contacto','email_admin'];

    /**
     * Los datos que pide el SAT para timbrar. Viven en la base de la
     * empresa, no en la principal: son operativos, los captura el propio
     * negocio y cambian sin que la plataforma se entere.
     */
    /**
     * Claves lógicas de los datos fiscales. ConfigRepo las traduce a las
     * columnas reales de `sistema_config`, que se llaman distinto
     * (`rfc`, `regimen_fiscal`): así el formulario no depende del nombre
     * que tengan en la base.
     */
    const FISCALES = ['tipo_persona','rfc_fiscal','cp_fiscal','razon_social','regimen_sat'];

    public function uno($id)
    {
        $st = $this->principal->prepare("
            SELECT id, nombre_empresa, giro_comercial, rfc, telefono, direccion,
                   nombre_contacto, email_admin, nombre_base_datos, plan,
                   fecha_vencimiento, activo, no_distribuidor
            FROM empresas WHERE id = ?");
        $st->execute([(int)$id]);
        return $st->fetch() ?: null;
    }

    public function actualizar($id, array $d)
    {
        $nombre = trim($d['nombre_empresa'] ?? '');
        if ($nombre === '') throw new \InvalidArgumentException('El nombre de la empresa es obligatorio');

        $rfc = mb_strtoupper(trim($d['rfc'] ?? ''));
        if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc)) {
            throw new \InvalidArgumentException('El RFC no tiene el formato correcto');
        }
        $email = trim($d['email_admin'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo no es válido');
        }

        // La lista blanca es lo que impide que un campo extra en el POST
        // termine escribiendo en `nombre_base_datos`.
        $valores = [
            'nombre_empresa'  => $nombre,
            'giro_comercial'  => trim($d['giro_comercial'] ?? '') ?: null,
            'rfc'             => $rfc ?: null,
            'telefono'        => preg_replace('/[^0-9+]/', '', (string)($d['telefono'] ?? '')) ?: null,
            'direccion'       => trim($d['direccion'] ?? '') ?: null,
            'nombre_contacto' => trim($d['nombre_contacto'] ?? '') ?: null,
            'email_admin'     => $email ?: null,
        ];
        $sets = []; $p = [];
        foreach (self::EDITABLES as $c) { $sets[] = "`$c` = ?"; $p[] = $valores[$c]; }
        $p[] = (int)$id;

        $this->principal->prepare("UPDATE empresas SET " . implode(', ', $sets) . " WHERE id = ?")
                        ->execute($p);
        return true;
    }
}
