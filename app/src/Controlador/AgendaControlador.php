<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Agenda;
use LibertyFin\Servicio\Modulos;
use LibertyFin\Vista\Plantilla;

/**
 * Agenda de citas (módulo «agenda»): el día de cada especialista.
 * La lógica, los empalmes y el recordatorio viven en Servicio\Agenda.
 */
final class AgendaControlador
{
    public function index()
    {
        if (!Modulos::exigir('agenda')) return;
        $db  = Conexion::de($_SESSION['empresa_db']);
        $srv = new Agenda($db);
        $dia = Peticion::fecha('dia', date('Y-m-d'));
        $editar = Peticion::entero('editar');
        $edit = $editar ? $srv->una($editar) : null;

        // La semana del día elegido, para la tira de días de arriba.
        $lunes = date('Y-m-d', strtotime($dia . ' -' . ((int)date('N', strtotime($dia)) - 1) . ' days'));
        $domingo = date('Y-m-d', strtotime($lunes . ' +6 days'));

        $citas = $srv->dia($dia);
        $empresa = $_SESSION['empresa_nombre'] ?? '';
        foreach ($citas as &$c) $c['whatsapp'] = Agenda::whatsapp($c, $empresa);
        unset($c);

        Plantilla::pagina('agenda/index', [
            'titulo'       => 'Agenda',
            'icono'        => 'agenda',
            'subtitulo'    => $this->fechaLarga($dia),
            'dia'          => $dia,
            'lunes'        => $lunes,
            'porDia'       => $srv->cuantasPorDia($lunes, $domingo),
            'citas'        => $citas,
            'horario'      => $srv->horario(),
            'especialistas'=> $srv->especialistas(),
            'servicios'    => $srv->servicios(),
            'edit'         => $edit,
            'nueva'        => [
                'hora'  => preg_match('/^\d{2}:\d{2}$/', Peticion::texto('hora')) ? Peticion::texto('hora') : '',
                'colab' => Peticion::entero('colab'),
            ],
            'abrir'        => (bool)$edit || Peticion::texto('nueva') !== '',
            'puedeEditar'  => Permisos::puede('editar.agenda'),
            'puedeCobrar'  => Permisos::puede('cobrar'),
            'puedeAjustes' => Permisos::puede('editar.ajustes'),
            'aviso'        => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardar()
    {
        if (!Modulos::exigir('agenda')) return;
        $id = (int)($_POST['id'] ?? 0);
        $dia = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['fecha'] ?? '')) ? $_POST['fecha'] : date('Y-m-d');
        if (!$this->tokenValido()) $this->volver($dia, 'No se pudo verificar el formulario.', 'error');
        $db = Conexion::de($_SESSION['empresa_db']);
        try {
            $cita = (new Agenda($db))->guardar($_POST, $id, $_SESSION['usuario_nombre'] ?? '');
            $this->volver($dia, $id ? 'Cita actualizada.' : 'Cita agendada.', 'ok', [], ['id' => $cita]);
        } catch (\InvalidArgumentException $e) {
            $_SESSION['lf_agenda_previo'] = array_intersect_key($_POST, array_flip(
                ['cliente_id', 'cliente_nombre', 'telefono', 'colaborador_id', 'producto_id', 'hora', 'duracion', 'notas']));
            $this->volver($dia, $e->getMessage(), 'error', $id ? ['editar' => $id] : ['nueva' => 1]);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] agenda/guardar: ' . $e->getMessage());
            $this->volver($dia, 'No se pudo guardar la cita.', 'error');
        }
    }

    public function estado()
    {
        if (!Modulos::exigir('agenda')) return;
        $id = (int)($_POST['id'] ?? 0);
        $db = Conexion::de($_SESSION['empresa_db']);
        $srv = new Agenda($db);
        $c = $srv->una($id);
        $dia = $c ? substr($c['inicio'], 0, 10) : date('Y-m-d');
        if (!$this->tokenValido()) $this->volver($dia, 'No se pudo verificar el formulario.', 'error');
        try {
            $srv->estado($id, (string)($_POST['estado'] ?? ''));
            $this->volver($dia, 'Cita de ' . ($c['cliente_nombre'] ?? '') . ': ' . mb_strtolower(Agenda::ESTADOS[$_POST['estado']]) . '.', 'ok', [], ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->volver($dia, $e->getMessage(), 'error');
        }
    }

    public function horario()
    {
        if (!Modulos::exigir('agenda')) return;
        $dia = Peticion::fecha('dia', date('Y-m-d'));
        if (!$this->tokenValido()) $this->volver($dia, 'No se pudo verificar el formulario.', 'error');
        try {
            (new Agenda(Conexion::de($_SESSION['empresa_db'])))->guardarHorario($_POST['inicio'] ?? '', $_POST['fin'] ?? '', $_POST['intervalo'] ?? 30);
            $this->volver($dia, 'Horario de la agenda guardado.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($dia, $e->getMessage(), 'error');
        }
    }

    private function fechaLarga($d)
    {
        $dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                  'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $t = strtotime($d);
        return $dias[(int)date('w', $t)] . ' ' . date('j', $t) . ' de ' . $meses[(int)date('n', $t)]
             . ($d === date('Y-m-d') ? ' · hoy' : '');
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], (string)$_POST['token']);
    }

    /**
     * Guardado por detrás (la agenda no se recarga): contesta JSON con el
     * día al que hay que ir. Si no, como siempre: aviso y redirección.
     */
    private function volver($dia, $texto, $tipo, array $extra = [], array $datos = [])
    {
        if (!empty($_SERVER['HTTP_X_LF_JSON'])) {
            unset($_SESSION['lf_agenda_previo']);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $tipo !== 'error', 'mensaje' => $texto, 'dia' => $dia] + $datos,
                             JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /agenda?' . http_build_query(array_merge(['dia' => $dia], $extra))); exit;
    }
}
