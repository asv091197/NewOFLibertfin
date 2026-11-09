<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Datos\TicketRepo as T;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$ini = function ($n) { return mb_strtoupper(mb_substr(trim((string)$n), 0, 1) ?: '?'); };
// Al cliente se le dice en qué va, no el nombre técnico del estado.
$comoVa = [
  'abierto'   => ['Lo estamos viendo',        'bg-secondary'],
  'en_curso'  => ['Trabajando en ello',       'bg-warning'],
  'esperando' => ['Esperamos tu respuesta',   'bg-danger'],
  'resuelto'  => ['Resuelto',                 'bg-success'],
  'cerrado'   => ['Cerrado',                  'bg-secondary'],
];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($abierto): $t = $abierto; ?>
<div class="lf-acciones-chat" style="margin-bottom:18px;display:flex;gap:8px;flex-wrap:wrap">
  <a class="btn btn-secondary btn-sm" href="/ayuda">Volver a mis reportes</a>
  <?php if ($t['estado'] !== 'cerrado'): ?>
    <?php /* Para seguir la conversación en la esquina mientras se trabaja en otra pantalla. */ ?>
    <button type="button" class="btn btn-secondary btn-sm"
            data-lf-abrir-chat="<?= P::e(json_encode(['id' => (int)$t['id'], 'folio' => $t['folio'], 'asunto' => $t['asunto']], JSON_UNESCAPED_UNICODE)) ?>">
      Abrir en chat flotante</button>
    <?php /* Avisos de escritorio aunque cierre LibertyFin. Lo muestra
             lf-chat.js solo si el navegador los permite y no están ya
             activados. */ ?>
    <button type="button" class="btn btn-secondary btn-sm" data-lf-push hidden
            title="Te avisamos en el escritorio cuando soporte responda, aunque cierres LibertyFin">
      Avisarme cuando respondan</button>
    <?php /* Ya activados: manda uno de prueba para comprobar que llegan. */ ?>
    <button type="button" class="btn btn-secondary btn-sm" data-lf-push-probar hidden
            title="Manda un aviso de prueba a este navegador">
      Probar aviso</button>
  <?php endif; ?>
</div>

<?php /* La conversación es un chat en vivo: lo nuevo aparece solo y se
         contesta sin recargar (ver assets/js/lf-chat.js). Es una VENTANA
         DE CHAT: se abre en el último mensaje, la lista se desplaza por
         dentro y la caja de escribir queda fija abajo. */ ?>
<section class="card lf-chat-ventana" data-lf-chat="/ayuda/<?= (int)$t['id'] ?>/mensajes" data-lf-lado="cliente"
         data-lf-escribe="/ayuda/<?= (int)$t['id'] ?>/escribiendo"
         data-lf-ultimo="<?= $mensajes ? max(array_column($mensajes, 'id')) : 0 ?>">
  <header class="card-header">
    <div><span><?= P::e($t['asunto']) ?></span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:3px;font-weight:400;
                font-family:var(--lf-mono)"><?= P::e($t['folio']) ?> ·
        abierto el <?= date('d/m/Y', strtotime($t['creado_en'])) ?></p></div>
    <span class="badge <?= $comoVa[$t['estado']][1] ?? 'bg-secondary' ?>">
      <?= P::e($comoVa[$t['estado']][0] ?? $t['estado']) ?></span>
  </header>
  <div class="card-body lf-chat-lista" data-lf-lista>
    <?php if (!$mensajes): ?>
      <p data-lf-vacio style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:22px">
        Todavía no hay mensajes en este reporte.</p>
    <?php endif; ?>
    <?php foreach ($mensajes as $m):
      // Por tipo y id: el id solo se repite entre soporte y la empresa, y
      // un mensaje de soporte salía como "Tú".
      $mio  = T::esMio($m);
      $foto = $fotos[(int)$m['id']] ?? ''; ?>
      <?php /* `propio`: lo escribió quien mira (en el celular, burbuja a la derecha). */ ?>
      <div class="lf-msj<?= $mio ? ' mio propio' : '' ?>" data-id="<?= (int)$m['id'] ?>">
        <span class="lf-av <?= $mio ? 'gris' : '' ?><?= $foto ? ' con-foto' : '' ?>"
              <?= $foto ? 'style="background-image:url(\'' . P::e($foto) . '\')"' : '' ?>><?= P::e($ini($m['autor_nombre'])) ?></span>
        <div class="cuerpo">
          <div class="cab">
            <b><?= $mio ? 'Tú' : P::e($m['autor_nombre'] ?: 'LibertyFin') ?></b>
            <?= T::fechaMsj($m['creado_en']) ?>
          </div>
          <p><?= nl2br(P::e($m['cuerpo'])) ?></p>
          <?php if ($m['adjunto'] && preg_match('/\.(png|jpe?g|webp|gif)$/i', $m['adjunto'])): ?>
            <a href="<?= P::e($m['adjunto']) ?>" target="_blank" rel="noopener" class="adj-img">
              <img src="<?= P::e($m['adjunto']) ?>" alt="Imagen adjunta" loading="lazy"></a>
          <?php elseif ($m['adjunto']): ?>
            <a href="<?= P::e($m['adjunto']) ?>" target="_blank" rel="noopener" class="adj">
              <?= W::icono('serv','14px') ?>Ver archivo</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php
  // Qué esperar ahora. Un ticket sin esto deja a la persona sin saber
  // si le toca a ella hacer algo o si está esperando a alguien.
  $queSigue = [
    'abierto'   => 'Lo recibimos. Alguien lo va a leer y te contestamos aquí mismo.',
    'en_curso'  => 'Estamos trabajando en ello. Te escribimos en cuanto haya algo.',
    'esperando' => 'Necesitamos que nos contestes para poder seguir. Si en ' . T::DIAS_ESPERANDO
                 . ' días no sabemos de ti, lo damos por resuelto.',
    'resuelto'  => 'Lo dimos por resuelto. Si sigue pasando, escríbenos aquí y lo reabrimos. '
                 . 'Si no, se cierra solo en ' . T::DIAS_RESUELTO . ' días.',
    'cerrado'   => 'Este reporte está cerrado. Si vuelve a pasar, abre uno nuevo.',
  ][$t['estado']] ?? ''; ?>
  <?php if ($queSigue): ?>
    <div class="lf-chat-nota" style="background:<?= $t['estado']==='esperando' ? 'var(--lf-amb-soft)' : 'var(--lf-vidrio)' ?>">
      <p style="color:<?= $t['estado']==='esperando' ? 'var(--lf-amb)' : 'var(--lf-tinta-3)' ?>">
        <b>¿Qué sigue?</b> <?= P::e($queSigue) ?></p>
    </div>
  <?php endif; ?>

  <?php if ($t['estado'] !== 'cerrado'): ?>
  <?php /* La caja de escribir, fija al pie de la ventana. */ ?>
  <div class="lf-chat-pie">
    <form method="post" action="/ayuda/<?= (int)$t['id'] ?>/responder" enctype="multipart/form-data"
          data-lf-enviar>
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <textarea class="form-control lf-desc" name="cuerpo" rows="2" required
                placeholder="Mensaje"></textarea>
      <div class="lf-resp-pie">
        <span class="lf-file">
          <input type="file" name="adjunto" id="adjResp"
                 accept="image/png,image/jpeg,image/webp,application/pdf">
          <label class="bt" for="adjResp">Adjuntar evidencia</label>
          <span class="n" data-vacio="Imagen o PDF, o pega una captura (Ctrl+V)">Imagen o PDF, o pega una captura (Ctrl+V)</span>
        </span>
        <button class="btn btn-primary" type="submit">Enviar</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</section>
<script>window.LFChat && LFChat.enlazar(document);</script>

<?php else: ?>

<?php /* El tutorial completo vive en su propia sección (menú → Tutorial). */ ?>
<div class="alert alert-success" style="margin-bottom:18px">
  <?= W::icono('libro','18px') ?>
  <span><b>¿Primera vez en LibertyFin?</b> El
    <a href="/tutorial" data-parcial>tutorial completo</a>
    te lleva paso a paso, de tu primera venta al corte del día.</span>
</div>

<details class="lf-alta" open>
  <summary><?= W::icono('mas','16px') ?>Reportar un problema</summary>
  <?php /* `data-lf-pegar`: una captura se puede pegar con Ctrl+V (o arrastrar)
           y se adjunta sola (ver lf-chat.js). */ ?>
  <form method="post" action="/ayuda/crear" class="lf-form" enctype="multipart/form-data" data-lf-pegar>
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <div style="flex:2;min-width:250px"><label class="form-label">¿Qué pasa?</label>
      <input class="form-control" name="asunto" required
             placeholder="En una línea, como se lo dirías a alguien"></div>
    <div style="width:220px"><label class="form-label">¿Con qué tiene que ver?</label>
      <select class="form-select" name="categoria">
        <?php foreach (T::CATEGORIAS as $k=>$v): ?>
          <option value="<?= $k ?>"><?= P::e($v) ?></option><?php endforeach; ?>
      </select></div>
    <div style="width:100%"><label class="form-label">Cuéntanos con detalle</label>
      <textarea class="form-control lf-desc" name="cuerpo" rows="4" required
                placeholder="Qué hiciste, qué viste y qué esperabas ver. Si hay un número que no cuadra, dinos cuál y cuánto debería ser."></textarea></div>
    <?php /* La evidencia va con el reporte, no después de enviarlo. */ ?>
    <div style="width:100%"><label class="form-label">Evidencia <span style="font-weight:400;color:var(--lf-tinta-4)">(opcional)</span></label>
      <span class="lf-file">
        <input type="file" name="adjunto" id="adjReporte"
               accept="image/png,image/jpeg,image/webp,application/pdf">
        <label class="bt" for="adjReporte">Adjuntar captura o archivo</label>
        <span class="n" data-vacio="o pega una captura con Ctrl+V">o pega una captura con Ctrl+V</span>
      </span>
      <p style="font-size:11px;color:var(--lf-tinta-4);margin:6px 0 0">
        Una captura de pantalla de lo que ves ahorra muchas preguntas. Cópiala y pégala aquí
        con <b>Ctrl+V</b>, o arrástrala. Imagen o PDF.</p></div>
    <button class="btn btn-primary" type="submit">Enviar reporte</button>
    <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0;line-height:1.55">
      Entre más concreto, más rápido se resuelve. "No funciona" obliga a preguntarte
      tres veces antes de poder empezar.
    </p>
  </form>
</details>

<section class="card">
  <header class="card-header">
    <div><span>Tus reportes</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Los de tu empresa, del más reciente al más viejo</p></div>
  </header>
  <div style="padding:0 10px 8px">
    <?php if (!$tickets): ?>
      <p style="text-align:center;color:var(--lf-tinta-4);font-size:13px;padding:30px">
        Todavía no has reportado nada.</p>
    <?php endif; ?>
    <?php foreach ($tickets as $t): ?>
      <a class="lf-row" href="/ayuda?ver=<?= (int)$t['id'] ?>">
        <span style="flex:1;min-width:0">
          <b style="display:block;font-size:13.5px"><?= P::e($t['asunto']) ?></b>
          <small style="color:var(--lf-tinta-4);font-size:11.5px">
            <?= P::e($t['folio']) ?> ·
            <?= date('d/m/Y', strtotime($t['creado_en'])) ?> ·
            <?= (int)$t['mensajes'] ?> mensaje<?= $t['mensajes']==1?'':'s' ?></small>
        </span>
        <span class="badge <?= $comoVa[$t['estado']][1] ?? 'bg-secondary' ?>">
          <?= P::e($comoVa[$t['estado']][0] ?? $t['estado']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span><?= count($tickets) ?> reporte<?= count($tickets)==1?'':'s' ?></span>
    <?php P::parcial('parciales/paginacion', ['pagina'=>$pagina,'paginas'=>$paginas,
      'enlace'=>function($n){ return '?p=' . $n; }]); ?>
  </div>
  <div class="card-footer" style="border-top:none;padding-top:0">
    Te avisaremos por correo electrónico en cuanto tengamos una respuesta. No es necesario que revises esta sección constantemente.
  </div>
</section>
<?php endif; ?>
