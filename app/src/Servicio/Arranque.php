<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * La configuración inicial: las preguntas del primer ingreso.
 *
 * QUÉ HACE
 *
 * El primer administrador de una empresa nueva contesta unas preguntas
 * (a qué se dedica, qué vende, cómo le pagan, IVA, comisiones), elige sus
 * áreas y su marca, y aquí se traduce a lo que el sistema YA sabe hacer:
 * Inventario encendido o no, cómo arranca Caja, si se ve Comisiones, las
 * categorías del catálogo. Nada se configura que no exista.
 *
 * LOS GIROS QUE TODAVÍA NO
 *
 * Restaurante y Hotelería se muestran como «Próximamente»: el sistema no
 * está pensado aún para mesas ni habitaciones, y dejarlos elegir sería
 * prometer algo que no hay. El catálogo ya los tiene para que, cuando sus
 * módulos existan, baste con quitarles la marca y darles sus valores.
 * `negocio.tipo` queda guardado para que esos módulos sepan qué empresa es.
 *
 * QUIÉN LO VE
 *
 * Solo la empresa marcada con `inicio.pendiente` (la marca CrearEmpresa al
 * aprobarla): las que ya existían no lo ven. Se puede repetir desde
 * Ajustes; no borra nada, solo vuelve a aplicar.
 */
final class Arranque
{
    /**
     * Los giros y lo que cada uno sugiere. Las sugerencias solo son el
     * punto de partida de las preguntas siguientes: la persona las cambia.
     *
     *   vende       servicios | productos | ambos   (Inventario)
     *   cobro       completo | anticipo             (cómo arranca el cobro en Caja)
     *   iva         16 | 8 | sin
     *   comisiones  si | no
     */
    const PERFILES = [
        'profesional' => ['nombre' => 'Servicios profesionales', 'ejemplo' => 'Contabilidad, legal, consultoría, marketing',
                          'ilu' => 'maletin', 'vende' => 'servicios', 'cobro' => 'anticipo', 'iva' => '16', 'comisiones' => 'si',
                          'areas' => ['Contabilidad', 'Fiscal', 'Legal', 'Consultoría'],
                          'modulos' => ['cotizaciones', 'igualas', 'seguimiento', 'expediente']],
        'belleza'     => ['nombre' => 'Salud y belleza', 'ejemplo' => 'Spa, estética, barbería, consultorio',
                          'ilu' => 'tijeras', 'vende' => 'ambos', 'cobro' => 'completo', 'iva' => '16', 'comisiones' => 'si',
                          'areas' => ['Tratamientos', 'Cortes y peinados', 'Productos'],
                          'modulos' => ['agenda', 'paquetes', 'expediente']],
        'tienda'      => ['nombre' => 'Tienda o comercio', 'ejemplo' => 'Abarrotes, ropa, papelería, refacciones',
                          'ilu' => 'tienda', 'vende' => 'productos', 'cobro' => 'completo', 'iva' => '16', 'comisiones' => 'no',
                          'areas' => ['General'],
                          'modulos' => ['compras']],
        // Sin educación: escuelas y academias no son mercado de Libertyfin.
        'otro'        => ['nombre' => 'Otro giro', 'ejemplo' => 'Lo ajustamos con tus respuestas',
                          'ilu' => 'estrella', 'vende' => 'servicios', 'cobro' => 'completo', 'iva' => 'sin', 'comisiones' => 'no',
                          'areas' => [], 'modulos' => []],
        // ── Próximamente: el sistema todavía no está pensado para ellos ──
        'restaurante' => ['nombre' => 'Restaurante o cafetería', 'ilu' => 'cubiertos',
                          'proximamente' => 'Mesas, comandas y propinas'],
        'hotel'       => ['nombre' => 'Hotelería', 'ilu' => 'cama',
                          'proximamente' => 'Habitaciones, reservas y estancias'],
    ];

    /**
     * Los módulos que se ofrecen en la pregunta «Herramientas». Inventario y
     * Comisiones no van aquí: los deciden «¿Qué vendes?» y «¿Pagas comisiones?».
     */
    const MODULOS = ['agenda', 'paquetes', 'cotizaciones', 'igualas', 'seguimiento', 'expediente', 'compras'];

    const VENDE      = ['servicios', 'productos', 'ambos'];
    const COBRO      = ['completo', 'anticipo'];
    const IVA        = ['16', '8', 'sin'];
    const COMISIONES = ['si', 'no'];

    /** El giro que escogió en el registro → el perfil que le corresponde. */
    const DEL_REGISTRO = [
        'Contabilidad y fiscal' => 'profesional', 'Despacho legal' => 'profesional',
        'Marketing y publicidad' => 'profesional', 'Consultoría' => 'profesional',
        'Salud y bienestar' => 'belleza', 'Belleza y estética' => 'belleza',
        'Tienda o comercio' => 'tienda',
    ];

    private $db;
    private $cfg;

    /** @param object|null $cfg  un ConfigRepo (o algo que responda igual, en las pruebas) */
    public function __construct(PDO $db, $cfg = null)
    {
        $this->db  = $db;
        $this->cfg = $cfg ?: new \LibertyFin\Datos\ConfigRepo($db);
    }

    /** ¿Esta empresa todavía no hace su configuración inicial? */
    public function pendiente() { return (string)$this->cfg->valorDe('inicio.pendiente', '') === '1'; }

    /** Los perfiles que se pueden elegir hoy. */
    public static function elegibles()
    {
        return array_filter(self::PERFILES, function ($p) { return empty($p['proximamente']); });
    }

    /**
     * Con qué arrancan las preguntas. La primera vez, lo que sugiere el
     * giro del registro; si se repite, lo que la empresa ya tiene.
     */
    public function respuestasIniciales()
    {
        $tipo = (string)$this->cfg->valorDe('negocio.tipo', '');
        if ($tipo !== '' && isset(self::elegibles()[$tipo])) {
            // Se repite: se parte de lo que hay.
            $caja = $this->cfg->caja();
            $inv  = $this->cfg->inventarioActivo();
            return [
                'perfil'     => $tipo,
                'vende'      => (string)$this->cfg->valorDe('negocio.vende', $inv ? 'productos' : 'servicios'),
                'cobro'      => $caja['anticipo'] === 'vacio' ? 'anticipo' : 'completo',
                'iva'        => $caja['iva_inicio'] === 'con' ? ($caja['iva_tasa'] === '8' ? '8' : '16') : 'sin',
                'comisiones' => $this->cfg->seccionActiva('comisiones') ? 'si' : 'no',
                'modulos'    => $this->modulosEncendidos(),
                'repite'     => true,
            ];
        }
        $giro = (string)$this->cfg->valorDe('negocio.giro_registro', '');
        $perfil = self::DEL_REGISTRO[$giro] ?? 'otro';
        $p = self::PERFILES[$perfil];
        return ['perfil' => $perfil, 'vende' => $p['vende'], 'cobro' => $p['cobro'],
                'iva' => $p['iva'], 'comisiones' => $p['comisiones'],
                'modulos' => array_values(array_filter($p['modulos'] ?? [], function ($k) { return Modulos::enPlan($k); })),
                'repite' => false];
    }

    /**
     * Aplica las respuestas. Devuelve lo que quedó, para el resumen.
     * @throws \InvalidArgumentException si algo no es una opción válida
     */
    public function aplicar(array $r)
    {
        $perfil = (string)($r['perfil'] ?? '');
        if (!isset(self::elegibles()[$perfil])) throw new \InvalidArgumentException('Elige a qué se dedica tu negocio.');
        foreach (['vende' => self::VENDE, 'cobro' => self::COBRO, 'iva' => self::IVA, 'comisiones' => self::COMISIONES] as $k => $ops) {
            if (!in_array((string)($r[$k] ?? ''), $ops, true)) throw new \InvalidArgumentException('Falta contestar una pregunta.');
        }

        $c = $this->cfg;
        $c->guardar('negocio.tipo', $perfil);
        $c->guardar('negocio.vende', $r['vende']);
        // Productos → existencias. Solo servicios → nada de inventario.
        $c->guardarInventario(['activo' => $r['vende'] === 'servicios' ? 'no' : 'si']);
        // Cómo arranca cada venta en Caja.
        $c->guardarCaja([
            'anticipo'   => $r['cobro'] === 'anticipo' ? 'vacio' : 'total',
            'iva_inicio' => $r['iva'] === 'sin' ? 'sin' : 'con',
            'iva_tasa'   => $r['iva'] === '8' ? '8' : '16',
        ]);
        // Comisiones solo si las paga. Cobranza NO se toca: cualquier negocio
        // puede quedarse con un saldo algún día, y el administrador no tiene
        // interruptor para volver a encenderla (eso lo hace soporte).
        // Comisiones sí se recupera: repitiendo estas preguntas.
        $c->guardar('seccion.comisiones', $r['comisiones'] === 'si' ? '1' : '0');

        $areas = $this->crearAreas((array)($r['areas'] ?? []));

        // Las herramientas (módulos). Solo si la pregunta llegó: sin ella
        // (un formulario viejo) se quedan como estaban.
        $modulos = null;
        if (!empty($r['modulos_paso']) || isset($r['modulos'])) {
            $modulos = $this->ponerModulos((array)($r['modulos'] ?? []), $r['vende'] !== 'servicios');
        }

        $c->guardar('inicio.pendiente', '0');
        $c->guardar('inicio.hecho_en', date('Y-m-d H:i:s'));
        try {
            Auditoria::anota('inicio.configurar', self::PERFILES[$perfil]['nombre'], null,
                json_encode(['vende' => $r['vende'], 'cobro' => $r['cobro'], 'iva' => $r['iva'],
                             'comisiones' => $r['comisiones'], 'areas' => $areas, 'modulos' => $modulos], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) { /* sin bitácora se configura igual */ }

        return ['perfil' => $perfil, 'areas' => $areas, 'modulos' => $modulos];
    }

    /**
     * Enciende los elegidos y apaga los demás de la pregunta. Lo que el plan
     * no incluye no se enciende (el paso lo muestra con candado). Compras
     * necesita Inventario: si solo vende servicios, no se enciende.
     *
     * @return array los que quedaron encendidos
     */
    private function ponerModulos(array $elegidos, $conInventario)
    {
        $m = new Modulos($this->cfg);
        $on = [];
        foreach (self::MODULOS as $k) {
            $quiere = in_array($k, $elegidos, true) && Modulos::enPlan($k)
                   && ($k !== 'compras' || $conInventario);
            try {
                $m->poner($k, $quiere);
                if ($quiere) $on[] = $k;
            } catch (\InvalidArgumentException $e) { /* fuera del plan: se queda apagado */ }
        }
        return $on;
    }

    /** Los de la pregunta que ya están encendidos (al repetir las preguntas). */
    private function modulosEncendidos()
    {
        $m = new Modulos($this->cfg);
        return array_values(array_filter(self::MODULOS, function ($k) use ($m) { return $m->encendido($k); }));
    }

    /** Saltar: se queda como está (los valores de siempre) y no vuelve a salir. */
    public function saltar()
    {
        $this->cfg->guardar('inicio.pendiente', '0');
        $this->cfg->guardar('inicio.hecho_en', date('Y-m-d H:i:s'));
    }

    /**
     * Crea las áreas (categorías) que no existan. Devuelve las nuevas.
     * Sin distinguir mayúsculas: «fiscal» y «Fiscal» son la misma.
     */
    private function crearAreas(array $nombres)
    {
        $ya = [];
        foreach ($this->db->query("SELECT nombre FROM categorias")->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $ya[mb_strtolower(trim($n))] = true;
        }
        $nuevas = [];
        $st = $this->db->prepare("INSERT INTO categorias (nombre, descripcion) VALUES (?, NULL)");
        foreach ($nombres as $n) {
            $n = mb_substr(trim(preg_replace('/\s+/', ' ', (string)$n)), 0, 80);
            if ($n === '' || isset($ya[mb_strtolower($n)])) continue;
            $st->execute([$n]);
            $ya[mb_strtolower($n)] = true;
            $nuevas[] = $n;
            if (count($nuevas) >= 12) break;   // un tope: es un arranque, no una importación
        }
        return $nuevas;
    }

    /**
     * La marca la pone CrearEmpresa al aprobar: esta empresa estrena y su
     * administrador verá las preguntas. Guarda también lo que dijo en el
     * registro, para que las preguntas lleguen ya contestadas.
     */
    public static function marcarPendiente(PDO $db, $giro = null, $empleados = null)
    {
        $c = new \LibertyFin\Datos\ConfigRepo($db);
        $c->guardar('inicio.pendiente', '1');
        if ($giro)      $c->guardar('negocio.giro_registro', (string)$giro);
        if ($empleados) $c->guardar('negocio.empleados', (string)$empleados);
    }
}
