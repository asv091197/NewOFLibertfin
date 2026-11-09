<?php
namespace LibertyFin\Servicio;

/**
 * Subida de imágenes.
 *
 * Una carpeta donde cualquiera puede escribir es la puerta de entrada
 * más usada que existe. Las defensas, en orden de importancia:
 *
 *  1. El tipo se deduce del CONTENIDO con getimagesize(), no del nombre
 *     ni del Content-Type que manda el navegador. Los dos los escribe
 *     quien sube el archivo.
 *  2. El nombre se descarta por completo y se genera uno aleatorio con
 *     la extensión que corresponde al tipo real.
 *  3. La carpeta lleva un .htaccess que apaga PHP. Si algo se cuela,
 *     queda inerte.
 *  4. Tope de tamaño y de dimensiones, para que un archivo no tire el
 *     disco ni el navegador de quien lo vea.
 */
final class Archivos
{
    const MAX_BYTES = 3145728;      // 3 MB
    const MAX_LADO  = 4000;         // píxeles

    const TIPOS = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF  => 'gif',
    ];

    private static $destino;
    public static function destino($ruta) { self::$destino = rtrim($ruta, '/'); }

    /**
     * Guarda una imagen de $_FILES y devuelve su ruta pública.
     * @throws \InvalidArgumentException con un mensaje apto para mostrar
     */
    public static function imagen(array $archivo, $prefijo = 'img')
    {
        if (!isset($archivo['error']) || is_array($archivo['error'])) {
            throw new \InvalidArgumentException('No se recibió el archivo');
        }
        switch ($archivo['error']) {
            case UPLOAD_ERR_OK: break;
            case UPLOAD_ERR_NO_FILE:
                throw new \InvalidArgumentException('No elegiste ninguna imagen');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new \InvalidArgumentException('La imagen pesa demasiado');
            default:
                throw new \InvalidArgumentException('No se pudo subir la imagen');
        }
        if ($archivo['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException(
                'La imagen no puede pesar más de ' . round(self::MAX_BYTES / 1048576, 1) . ' MB');
        }
        // is_uploaded_file: que venga de verdad de una subida y no sea
        // una ruta del servidor que alguien metió en el formulario.
        if (!is_uploaded_file($archivo['tmp_name'])) {
            throw new \InvalidArgumentException('Origen del archivo no válido');
        }

        $info = @getimagesize($archivo['tmp_name']);
        if ($info === false || !isset(self::TIPOS[$info[2]])) {
            throw new \InvalidArgumentException('Ese archivo no es una imagen válida (JPG, PNG, WebP o GIF)');
        }
        if ($info[0] > self::MAX_LADO || $info[1] > self::MAX_LADO) {
            throw new \InvalidArgumentException(
                'La imagen es demasiado grande: máximo ' . self::MAX_LADO . ' píxeles por lado');
        }

        if (!self::$destino || !is_dir(self::$destino)) {
            if (!@mkdir(self::$destino, 0755, true) && !is_dir(self::$destino)) {
                throw new \RuntimeException('No existe la carpeta de subidas');
            }
        }

        $nombre = preg_replace('/[^a-z0-9_-]/i', '', $prefijo) . '_'
                . bin2hex(random_bytes(8)) . '.' . self::TIPOS[$info[2]];
        $ruta = self::$destino . '/' . $nombre;

        if (!move_uploaded_file($archivo['tmp_name'], $ruta)) {
            throw new \RuntimeException('No se pudo guardar la imagen');
        }
        @chmod($ruta, 0644);
        return '/assets/subidas/' . $nombre;
    }

    private static $ultimoMime = '';
    public static function ultimoMime() { return self::$ultimoMime; }

    /**
     * Guarda un documento: imagen o PDF.
     *
     * Los papeles del alta de comercio llegan casi siempre en PDF, así que
     * getimagesize() no sirve para validarlos. Para el PDF se comprueba la
     * firma del archivo —los cuatro bytes `%PDF`— que es lo más cercano a
     * mirar el contenido sin una librería completa.
     *
     * El tope es mayor que el de una imagen: un estado de cuenta escaneado
     * pasa fácil de 3 MB y rechazarlo obligaría a la gente a comprimirlo,
     * que es justo donde se rinden.
     */
    const MAX_DOC = 10485760;   // 10 MB

    public static function documento(array $archivo, $prefijo = 'doc')
    {
        if (!isset($archivo['error']) || is_array($archivo['error'])) {
            throw new \InvalidArgumentException('No se recibió el archivo');
        }
        if ($archivo['error'] === UPLOAD_ERR_NO_FILE) {
            throw new \InvalidArgumentException('No elegiste ningún archivo');
        }
        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('No se pudo subir el archivo');
        }
        if ($archivo['size'] > self::MAX_DOC) {
            throw new \InvalidArgumentException(
                'El archivo no puede pesar más de ' . round(self::MAX_DOC / 1048576) . ' MB');
        }
        if (!is_uploaded_file($archivo['tmp_name'])) {
            throw new \InvalidArgumentException('Origen del archivo no válido');
        }

        // ¿Imagen?
        $info = @getimagesize($archivo['tmp_name']);
        if ($info !== false && isset(self::TIPOS[$info[2]])) {
            $ext = self::TIPOS[$info[2]];
            self::$ultimoMime = image_type_to_mime_type($info[2]);
        } else {
            // ¿PDF? Se leen los primeros bytes, no la extensión.
            $f = @fopen($archivo['tmp_name'], 'rb');
            $cabeza = $f ? fread($f, 5) : '';
            if ($f) fclose($f);
            if (strncmp($cabeza, '%PDF-', 5) !== 0) {
                throw new \InvalidArgumentException(
                    'Solo se aceptan JPG, PNG, WebP o PDF');
            }
            $ext = 'pdf';
            self::$ultimoMime = 'application/pdf';
        }

        if (!self::$destino || !is_dir(self::$destino)) {
            if (!@mkdir(self::$destino, 0755, true) && !is_dir(self::$destino)) {
                throw new \RuntimeException('No existe la carpeta de subidas');
            }
        }
        $nombre = preg_replace('/[^a-z0-9_-]/i', '', $prefijo) . '_'
                . bin2hex(random_bytes(10)) . '.' . $ext;
        $ruta = self::$destino . '/' . $nombre;
        if (!move_uploaded_file($archivo['tmp_name'], $ruta)) {
            throw new \RuntimeException('No se pudo guardar el archivo');
        }
        @chmod($ruta, 0644);
        return '/assets/subidas/' . $nombre;
    }

    /** Borra una imagen subida. Solo dentro de la carpeta de subidas. */
    public static function borrar($rutaPublica)
    {
        if (!$rutaPublica || strpos($rutaPublica, '/assets/subidas/') !== 0) return false;
        $nombre = basename($rutaPublica);
        $ruta = self::$destino . '/' . $nombre;
        return is_file($ruta) ? @unlink($ruta) : false;
    }
}
