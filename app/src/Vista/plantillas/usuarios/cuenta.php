<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Dominio\Permisos as Perm;
use LibertyFin\Datos\UsuarioRepo as U;
use LibertyFin\Datos\CuentaRepo as C;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$ed = $estadoDocs;
$colorDoc = ['sin_enviar'=>'bg-secondary','en_revision'=>'bg-warning',
             'rechazada'=>'bg-danger','aprobada'=>'bg-success'][$ed['estado']] ?? 'bg-secondary';
$textoDoc = ['sin_enviar'=>'Faltan documentos','en_revision'=>'En revisión',
             'rechazada'=>'Hay documentos rechazados','aprobada'=>'Documentación aprobada'][$ed['estado']] ?? '';
?>

<div class="lf-pills" style="margin-bottom:18px">
  <?php foreach ($pestanas as $k => $v): ?>
    <a class="lf-pill <?= $pestana===$k?'active':'' ?>" href="/cuenta?t=<?= $k ?>"><?= P::e($v) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($ed['estado'] !== 'aprobada' && $pestana !== 'documentos'): ?>
<a class="alert alert-warning" href="/cuenta?t=documentos" style="margin-bottom:18px;text-decoration:none">
  <?= W::icono('alerta','18px') ?>
  <span><b><?= P::e($textoDoc) ?>.</b>
    Hasta que la documentación esté aprobada puedes registrar ventas, pero no
    cobrar con tarjeta ni facturar.
    <?= (int)$ed['aprobados'] ?> de <?= (int)$ed['total'] ?> listos.</span>
</a>
<?php endif; ?>


<?php /* ═══════ MI PERFIL ═══════
   `lf-perfil`: aquí las tarjetas miden lo que su contenido. Con las
   columnas de igual altura del resto del sistema, la de la contraseña se
   estiraba hasta el fondo de la otra columna y quedaba un cuadro enorme
   casi vacío. */ if ($pestana === 'perfil'): ?>
<div class="lf-split lf-perfil">
  <div>
  <section class="card">
    <header class="card-header">Cambiar mi contraseña</header>
    <div class="card-body" style="max-width:400px">
      <form method="post" action="/cuenta/clave">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <label class="form-label" for="a">Contraseña actual</label>
        <input class="form-control" type="password" id="a" name="actual"
               autocomplete="current-password" required>
        <label class="form-label" for="n" style="margin-top:14px">Contraseña nueva</label>
        <input class="form-control" type="password" id="n" name="nueva"
               minlength="<?= U::CLAVE_MINIMA ?>" autocomplete="new-password" required>
        <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:8px;line-height:1.5">
          Mínimo <?= U::CLAVE_MINIMA ?> caracteres. Una frase que recuerdes es mejor
          que ocho caracteres raros que acabes apuntando en un papel.
        </p>
        <button class="btn btn-primary" type="submit" style="width:100%;margin-top:16px;padding:12px">
          Cambiar</button>
      </form>
      <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:14px;padding-top:14px;
                border-top:1px solid var(--lf-linea);line-height:1.5">
        Al cambiarla se cierra tu sesión. Si la cambias porque crees que alguien la
        sabía, dejarla abierta no serviría de nada.
      </p>
    </div>
  </section>

  <?php /* Solo cuentas de soporte: su tema y su color van con la CUENTA
           (al entrar desde otro equipo se ven igual). El color es uno de
           los ya probados, los mismos que una empresa elige para su marca.
           Compacto a propósito: son dos decisiones, en dos renglones. Se
           guarda al elegir, sin recargar. */
  if (!empty($_SESSION['plataforma']) && isset($apariencia)):
    $apTema = $apariencia['tema'] ?: 'light'; ?>
  <section class="card">
    <header class="card-header">Apariencia</header>
    <div class="card-body">
      <form method="post" action="/cuenta/apariencia" id="lfApariencia" class="lf-apariencia"
            data-guardar data-al-elegir data-tema-guardado="<?= P::e($apariencia['tema']) ?>">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <div class="fila">
          <span class="et" id="etTema">Tema</span>
          <div class="lf-segmento" role="radiogroup" aria-labelledby="etTema">
            <?php foreach (['light' => ['Claro', 'sol'], 'dark' => ['Oscuro', 'luna'], 'auto' => ['Automático', '']] as $k => $m): ?>
              <label class="<?= $apTema === $k ? 'on' : '' ?>"
                     <?= $k === 'auto' ? 'title="Claro u oscuro, igual que tu equipo"' : '' ?>>
                <input type="radio" name="tema" value="<?= $k ?>" <?= $apTema === $k ? 'checked' : '' ?>>
                <?= $m[1] ? W::icono($m[1], '14px') : '' ?><?= P::e($m[0]) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="fila">
          <span class="et" id="etColor">Color</span>
          <div class="lf-colores" role="radiogroup" aria-labelledby="etColor">
            <?php foreach (W::COLORES as $c => $nombre): ?>
              <label class="lf-color<?= $apariencia['color'] === $c ? ' on' : '' ?>"
                     style="background:<?= $c ?>" title="<?= P::e($nombre) ?>">
                <input type="radio" name="color" value="<?= $c ?>" aria-label="<?= P::e($nombre) ?>"
                       <?= $apariencia['color'] === $c ? 'checked' : '' ?>>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <p class="nota">Se guarda al elegir. Solo cambia cómo lo ves tú, en cualquier equipo donde entres.
          <?= $apariencia['color'] === '' ? 'Sin color elegido se usa el verde de LibertyFin.' : '' ?></p>
      </form>
    </div>
  </section>
  <script>
  (function(){
    var f = document.getElementById('lfApariencia');
    if (!f) return;
    var raiz = document.documentElement;
    function marcar() {
      [].forEach.call(f.querySelectorAll('.lf-segmento label, .lf-color'), function (m) {
        var r = m.querySelector('input');
        m.classList.toggle('on', !!(r && r.checked));
      });
    }
    /* Sin tema guardado en la cuenta, se marca el que se está viendo en
       este navegador: si no, al elegir un color se guardaría "Claro" a
       quien lo usa en oscuro. */
    if (!f.getAttribute('data-tema-guardado')) {
      var visto = raiz.hasAttribute('data-tema-auto') ? 'auto'
                : (raiz.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');
      var r = f.querySelector('input[name=tema][value="' + visto + '"]');
      if (r) { r.checked = true; marcar(); }
    }
    // Se ve al momento; el layout lo guarda por detrás (data-guardar).
    f.addEventListener('change', function (e) {
      var i = e.target;
      if (i.name === 'tema') {
        if (window.lfAplicarTema) window.lfAplicarTema(i.value);
        try { localStorage.setItem('lf-tema', i.value); } catch (x) {}
      }
      if (i.name === 'color') raiz.style.setProperty('--lf-brand', i.value);
      marcar();
    });
  })();
  </script>
  <?php endif; ?>
  </div>

  <div>
    <section class="card">
      <header class="card-header">Mi foto</header>
      <div class="card-body">
        <form method="post" action="/cuenta/foto" enctype="multipart/form-data">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <div class="lf-foto">
            <span class="prev" id="prevFoto"
                  style="<?= $foto ? "background-image:url('".P::e($foto)."')" : '' ?>">
              <?= $foto ? '' : P::e(mb_strtoupper(mb_substr($_SESSION['usuario_nombre'] ?? 'U',0,1))) ?></span>
            <div style="flex:1;min-width:0">
              <span class="lf-file">
                <input type="file" name="foto" id="inpFoto"
                       accept="image/png,image/jpeg,image/webp">
                <label class="bt" for="inpFoto">Elegir foto</label>
                <span class="n" data-vacio="Ninguna foto elegida">Ninguna foto elegida</span>
              </span>
              <p style="font-size:11px;color:var(--lf-tinta-4);margin-top:6px">
                Cuadrada. Se recorta en círculo y aparece en el menú.</p>
            </div>
          </div>
          <div style="display:flex;gap:9px;margin-top:16px;flex-wrap:wrap">
            <button class="btn btn-primary" type="submit">Guardar foto</button>
            <?php if ($foto): ?>
              <button class="btn btn-secondary" type="submit" name="quitar" value="1">Quitar</button>
            <?php endif; ?>
          </div>
        </form>

                <?php /* Solo cuentas de soporte: si el CLIENTE ve esta foto en los
                 tickets. Se guarda al marcar, sin recargar. Nace apagada:
                 enseñarle la foto de alguien del equipo a un cliente lo
                 decide esa persona. */
        if (!empty($_SESSION['plataforma'])): ?>
          <form method="post" action="/cuenta/foto-publica" class="lf-foto-pub" data-guardar data-al-elegir>
            <input type="hidden" name="token" value="<?= P::e($token) ?>">
            <label class="lf-foto-pub-chk">
              <input type="checkbox" name="publica" value="1" <?= !empty($fotoPublica) ? 'checked' : '' ?>>
              <span><b>Mostrar mi foto a los clientes en soporte</b>
                <small>Apagado, en los tickets ven solo tu inicial. Tu equipo siempre la ve.</small></span>
            </label>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <header class="card-header">Mis datos</header>
      <div class="card-body" style="font-size:13px">
        <?php foreach ([
          'Nombre'   => $_SESSION['usuario_nombre'] ?? '',
          'Rol'      => Perm::rotulo($_SESSION['usuario_rol'] ?? ''),
          'Empresa'  => $_SESSION['empresa_nombre'] ?? '',
          'Sucursal' => $_SESSION['sucursal_nombre'] ?? '',
        ] as $k => $v): if ($v === '') continue; ?>
          <div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0">
            <span style="color:var(--lf-tinta-3)"><?= P::e($k) ?></span>
            <b style="font-weight:600;text-align:right"><?= P::e($v) ?></b>
          </div>
        <?php endforeach; ?>
        <p style="margin-top:14px;padding-top:14px;border-top:1px solid var(--lf-linea);
                  font-size:11.5px;color:var(--lf-tinta-4)">
          Para cambiar tu nombre, rol o sucursal, pídeselo a un administrador.
        </p>
        <a class="btn btn-secondary btn-sm" href="/guia" style="width:100%;margin-top:10px">
          <?= W::icono('panel','15px') ?>Volver a ver la guía</a>
      </div>
    </section>
  </div>
</div>
<script>
(function(){
  var i = document.getElementById('inpFoto'), p = document.getElementById('prevFoto');
  if (!i || !p) return;
  i.addEventListener('change', function(){
    var f = i.files && i.files[0];
    if (!f) return;
    p.style.backgroundImage = "url('" + URL.createObjectURL(f) + "')";
    p.textContent = '';
  });
})();
</script>


<?php /* ═══════ PLAN ═══════ */ elseif ($pestana === 'plan'):
$em = $empresa;
$venc = ($em && !empty($em['fecha_vencimiento'])) ? strtotime($em['fecha_vencimiento']) : null;
$dias = $venc ? floor(($venc - strtotime('today')) / 86400) : null;
?>
<?php if (!$em): ?>
  <div class="alert alert-warning"><?= W::icono('alerta','18px') ?>
    <span>No se pudieron leer los datos del plan. Revisa <code>bd.principal</code>
      en <code>config/config.php</code>.</span></div>
<?php else: ?>
<div class="lf-split">
  <section class="card">
    <header class="card-header">Tu plan</header>
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px">
        <span class="lf-tile" style="width:52px;height:52px;border-radius:17px;margin:0">
          <?= W::icono('serv','24px') ?></span>
        <div>
          <b style="font-size:21px;font-weight:700;letter-spacing:-.4px;display:block">
            <?= P::e(ucfirst($em['plan'] ?? 'Prueba')) ?></b>
          <small style="font-size:12.5px;color:var(--lf-tinta-3)">
            <?= P::e($em['nombre_empresa']) ?></small>
        </div>
      </div>

      <?php if ($venc): ?>
        <div style="padding:14px 16px;border-radius:var(--lf-r);margin-bottom:6px;
             background:<?= $dias<0?'var(--lf-rojo-soft)':($dias<15?'var(--lf-amb-soft)':'var(--lf-brand-soft)') ?>;
             color:<?= $dias<0?'var(--lf-rojo)':($dias<15?'var(--lf-amb)':'var(--lf-brand-2)') ?>">
          <b style="display:block;font-size:15px;margin-bottom:3px">
            <?= $dias < 0 ? 'Venció hace ' . abs($dias) . ' días'
                          : 'Quedan ' . $dias . ' día' . ($dias==1?'':'s') ?></b>
          <span style="font-size:12.5px">Vence el <?= date('d/m/Y', $venc) ?></span>
        </div>
      <?php endif; ?>

      <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:16px;line-height:1.55">
        Para cambiar de plan o renovar, escríbele a LibertyFin. El plan, la fecha de
        vencimiento y los límites los administra la plataforma, no la empresa.
      </p>
    </div>
  </section>

  <section class="card">
    <header class="card-header">Para empezar a cobrar de verdad</header>
    <div class="card-body">
      <?php
      $pasos = [
        ['Datos fiscales',   'fiscales',
         !empty($fiscales['rfc_fiscal'] ?? '') , 'Para timbrar facturas'],
        ['Alta de comercio', 'comercio',
         false, 'Para procesar tarjeta y SPEI'],
        ['Documentos',       'documentos',
         $ed['estado'] === 'aprobada', 'Los revisa LibertyFin en 24 a 72 horas'],
      ];
      foreach ($pasos as $i => $ps): list($n, $t, $listo, $porque) = $ps; ?>
        <a class="lf-row" href="/cuenta?t=<?= $t ?>">
          <span class="lf-av <?= $listo ? '' : 'gris' ?>" style="flex-shrink:0">
            <?= $listo ? '✓' : ($i+1) ?></span>
          <span style="flex:1;min-width:0">
            <b style="display:block;font-size:13.5px"><?= P::e($n) ?></b>
            <small style="color:var(--lf-tinta-4);font-size:11.5px"><?= P::e($porque) ?></small>
          </span>
          <?= W::icono('venta','15px') ?>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<?php /* ═══ CONTRATAR O RENOVAR ═══
   Solo si existe config/planes.php. Sin catálogo, la tarjeta de arriba ya
   dice que se escriba a LibertyFin, como siempre. */
if (!empty($catalogo)):
  $ultimo = $pagosPlan[0] ?? null;
  $estPago = $ultimo['estado'] ?? '';
  $cu = $catalogo['cuenta'] ?? [];
  $etiqueta = ['por_pagar'=>['Por pagar','bg-warning'], 'en_revision'=>['En revisión','bg-warning'],
               'aprobado'=>['Aprobado','bg-success'], 'rechazado'=>['Rechazado','bg-danger'],
               'cancelado'=>['Cancelado','bg-secondary']];
?>

<?php if (!empty($reciente)): $lr = $reciente; ?>
<?php /* ─────────────────────────────────────────────────────
     COBRO EN LÍNEA RECIÉN GENERADO

     El usuario pidió pagar con tarjeta, SPEI o efectivo en tienda
     desde el modal. En vez de dejarlo con la tarjeta de
     transferencia —que ya no aplica— se le enseña lo que el
     proveedor devolvió.

     Se parece al modal de cobro de la Caja, pero en formato
     tarjeta: aquí no hay cajero esperando, el usuario se lleva
     los datos y los usa cuando pueda.

     La liga se conserva en la sesión hasta 24 h para que el
     polling del modal de pago confirmado siga funcionando si el
     usuario recarga. Cuando el pago se confirma desde
     estadoPlan(), se borra de la sesión.
     ───────────────────────────────────────────────────── */ ?>
<section class="card" style="border-color:color-mix(in srgb,var(--lf-brand) 45%,transparent);
         margin-bottom:18px">
  <header class="card-header">
    <div>
      <span>
        <?php if ($lr['metodo'] === 'tarjeta'): ?>Pago con tarjeta
        <?php elseif ($lr['metodo'] === 'spei'): ?>Transferencia SPEI
        <?php else: ?>Pago en tienda<?php endif; ?>
      </span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        <?= P::e($lr['descripcion']) ?> ·
        <b class="lf-mono" style="color:var(--lf-brand-2)"><?= D::pesos($lr['monto']) ?></b>
        <?php if (!empty($lr['pruebas'])): ?> · <span style="color:var(--lf-amb)">prueba</span><?php endif; ?>
      </p>
    </div>
    <?php if (!empty($lr['vence'])): ?>
      <span class="badge bg-secondary" style="flex-shrink:0">
        Vence <?= date('d/m/Y', strtotime($lr['vence'])) ?></span>
    <?php endif; ?>
  </header>
  <div class="card-body">
    <?php if ($lr['metodo'] === 'tarjeta' && !empty($lr['liga'])): ?>
      <p style="font-size:13px;color:var(--lf-tinta-3);margin:0 0 12px;line-height:1.55">
        Pasa la tarjeta aquí mismo. El cargo se procesa al momento
        y tu plan se activa solo.</p>

      <?php /* La página del proveedor embebida. Algunos procesadores mandan
               X-Frame-Options: DENY y el iframe queda en blanco: por eso el
               botón de "Abrir en otra pestaña" sigue ahí como respaldo. */ ?>
      <div class="lf-pago-marco" id="lfPagoMarco">
        <iframe src="<?= P::e($lr['liga']) ?>"
                title="Página de pago"
                loading="lazy"
                referrerpolicy="no-referrer"
                allow="payment"
                style="width:100%;height:640px;border:0;display:block;background:#fff"></iframe>
        <noscript>
          <p style="font-size:12.5px;padding:14px;color:var(--lf-tinta-3)">
            Activa JavaScript o abre la página en otra pestaña con el botón de abajo.</p>
        </noscript>
      </div>

      <?php /* Si el iframe no carga (proveedor que prohíbe embeber), caemos
               al botón. Se detecta con onload/onerror del propio iframe. */ ?>
      <div id="lfPagoRespaldo" hidden
           style="padding:14px 16px;border-radius:var(--lf-r);background:var(--lf-brand-soft);
                  color:var(--lf-brand-2);font-size:12.5px;line-height:1.55;margin-bottom:12px">
        <b style="display:block;margin-bottom:4px">Abre la página de pago en otra pestaña</b>
        El procesador no permite mostrarse aquí dentro. Usa el botón de abajo.
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn btn-primary" id="lfPagoAbrir" href="<?= P::e($lr['liga']) ?>"
           target="_blank" rel="noopener">
          <?= W::icono('cobro','16px') ?>Abrir la página de pago</a>
        <button type="button" class="btn btn-secondary" data-copiar="<?= P::e($lr['liga']) ?>">
          <?= W::icono('venta','16px') ?>Copiar la liga</button>
        <button type="button" class="btn btn-secondary" id="lfPagoRecargar">
          <?= W::icono('serv','16px') ?>Recargar</button>
      </div>

      <script>
      /* El iframe puede fallar por dos motivos:
           1) el proveedor manda X-Frame-Options / CSP frame-ancestors y el
              navegador lo bloquea → el iframe dispara onload con un documento
              vacío, o directamente nunca carga;
           2) la liga caducó.
         Como no podemos leer el contenido del iframe (es de otro origen), no
         hay forma 100% fiable de saber si falló. Hacemos una comprobación
         best-effort: si tras 6 s seguimos sin poder acceder a contentWindow
         (cross-origin normal) damos por bueno el embed; si onload no llegó
         en ese tiempo, mostramos el respaldo. */
      (function () {
        var marco    = document.getElementById('lfPagoMarco');
        var respaldo = document.getElementById('lfPagoRespaldo');
        var ifr      = marco && marco.querySelector('iframe');
        if (!ifr) return;

        var cargado = false, avisado = false;
        ifr.addEventListener('load', function () { cargado = true; });
        ifr.addEventListener('error', function () { mostrarRespaldo(); });

        setTimeout(function () {
          if (!cargado) mostrarRespaldo();
        }, 6000);

        function mostrarRespaldo() {
          if (avisado) return;
          avisado = true;
          respaldo.hidden = false;
          marco.style.display = 'none';
        }

        var rec = document.getElementById('lfPagoRecargar');
        if (rec) rec.addEventListener('click', function () {
          respaldo.hidden = true;
          marco.style.display = '';
          cargado = false; avisado = false;
          ifr.src = ifr.src;
          setTimeout(function () { if (!cargado) mostrarRespaldo(); }, 6000);
        });
      })();
      </script>

    <?php elseif ($lr['metodo'] === 'spei' && !empty($lr['clabe'])): ?>
      <p style="font-size:13px;color:var(--lf-tinta-3);margin:0 0 12px;line-height:1.55">
        Transfiere desde tu banco a esta CLABE, por
        <b><?= D::pesos($lr['monto']) ?></b> exactos.
        El plan se activa en cuanto entre el depósito.</p>
      <div style="padding:16px;border-radius:var(--lf-r);background:var(--lf-brand-soft);
           color:var(--lf-brand-2);font-family:var(--lf-mono);font-size:20px;
           letter-spacing:.5px;font-weight:700;text-align:center;word-break:break-all;
           margin-bottom:12px">
        <?= P::e($lr['clabe']) ?>
      </div>
      <button type="button" class="btn btn-secondary" style="width:100%"
              data-copiar="<?= P::e($lr['clabe']) ?>">
        <?= W::icono('venta','16px') ?>Copiar la CLABE</button>
      <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:12px;line-height:1.5">
        Referencia del cobro: <b class="lf-mono"><?= P::e($lr['referencia']) ?></b>.
        Guárdala por si hay que reclamar.</p>

    <?php elseif ($lr['metodo'] === 'efectivo' && !empty($lr['barras'])): ?>
      <p style="font-size:13px;color:var(--lf-tinta-3);margin:0 0 12px;line-height:1.55">
        Muestra este código en OXXO o tiendas participantes. Puedes pagar por
        <b><?= D::pesos($lr['monto']) ?></b>.</p>
      <div style="padding:18px;border-radius:var(--lf-r);background:var(--lf-brand-soft);
           color:var(--lf-brand-2);font-family:var(--lf-mono);font-size:20px;
           letter-spacing:1.5px;font-weight:700;text-align:center;word-break:break-all;
           margin-bottom:12px">
        <?= P::e($lr['barras']) ?>
      </div>
      <?php if (!empty($lr['imagen'])): ?>
        <div style="text-align:center;margin-bottom:12px">
          <img src="data:image/png;base64,<?= P::e($lr['imagen']) ?>"
               alt="Código de barras" style="max-width:100%;max-height:110px">
        </div>
      <?php endif; ?>

      <?php /* ─────────────────────────────────────────────────────
           COPIAR LA REFERENCIA O VER EL COMPROBANTE

           El comprobante es la ficha que se imprime o se le manda
           al cliente: trae el código de barras, la referencia
           escrita por si el escáner falla, los pasos y la lista de
           tiendas donde se puede pagar. Es lo mismo que da la
           Caja cuando cobra en efectivo en tienda.

           El enlace apunta a `/ligas/<liga_id>/documento`, que es
           la fila del directorio de ligas. Ese id viaja en la
           sesión porque el controlador lo captura al crear la
           liga: el `pago_id` de esta misma sesión es de otra
           tabla y no serviría.

           Si el id no llegó —porque la liga no se pudo guardar—
           solo se pinta el botón de copiar. Un enlace a una
           ficha que no existe es peor que no ofrecer el enlace.
           ───────────────────────────────────────────────────── */ ?>
<div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="btn btn-secondary" style="flex:1;min-width:180px"
                data-copiar="<?= P::e($lr['barras']) ?>">
          <?= W::icono('venta','16px') ?>Copiar la referencia</button>
        <?php if (!empty($lr['liga_id'])): ?>
          <a class="btn btn-secondary" style="flex:1;min-width:180px"
             href="/cuenta/plan/liga/<?= (int)$lr['liga_id'] ?>/documento"
             target="_blank" rel="noopener">
            <?= W::icono('baja','16px') ?>Ver comprobante</a>
        <?php endif; ?>
      </div>

    <?php else: ?>
      <p style="font-size:13px;color:var(--lf-rojo);line-height:1.55">
        El cobro quedó registrado pero el proveedor no devolvió los datos.
        Usa la transferencia de abajo, o inténtalo de nuevo.</p>
    <?php endif; ?>

    <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:16px;
              padding-top:14px;border-top:1px solid var(--lf-linea);line-height:1.55">
      <b>Todavía no entró el dinero.</b> Tu plan se activa cuando el proveedor
      confirme el pago, no al generar la referencia. Puedes ver el avance en
      <a href="#mis-pagos">Mis pagos</a>.
    </p>
  </div>
</section>

<?php /* ─────────────────────────────────────────────────────
     MODAL DE PAGO CONFIRMADO

     Se muestra cuando el polling detecta que la liga pasó a
     `pagada` y (si aplica) el pago de plan quedó aprobado.

     Solo se pinta si hay `liga_id`: el endpoint
     /cuenta/plan/estado/<liga_id> trabaja sobre la fila de
     `ligas`, no sobre el pago de plan. Sin ese id no hay a qué
     preguntar.

     Duración: 10 minutos máximo. Un pago que no entra en ese
     rango casi siempre significa que el usuario se fue a hacer
     otra cosa; el modal ya no le sirve a nadie.
     ───────────────────────────────────────────────────── */ ?>
<?php if (!empty($lr['liga_id'])): ?>
<div class="lf-modal" id="lfModalPagado" hidden>
  <div class="caja" role="dialog" aria-modal="true" aria-labelledby="lfPagadoTit">
    <header>
      <div>
        <h2 id="lfPagadoTit">¡Pago confirmado!</h2>
        <p>Tu plan ya está activo.</p>
      </div>
      <button type="button" class="cerrar" data-cerrar-pagado aria-label="Cerrar"><span class="lf-signo">×</span></button>
    </header>
    <div class="cuerpo" style="text-align:center;padding:8px 4px 4px">
      <div style="width:64px;height:64px;border-radius:50%;margin:0 auto 16px;
                  background:var(--lf-brand-soft);color:var(--lf-brand-2);
                  display:flex;align-items:center;justify-content:center">
        <?= W::icono('cobro','32px') ?>
      </div>
      <p style="font-size:14px;line-height:1.6;margin:0 0 18px">
        Recibimos tu pago de
        <b class="lf-mono"><?= D::pesos($lr['monto']) ?></b>
        para <b><?= P::e($lr['descripcion']) ?></b>.
      </p>
      <dl id="lfPagadoDatos" class="lf-conf-datos" style="text-align:left"></dl>
    </div>
    <footer>
      <a class="btn btn-primary" href="/cuenta?t=plan" style="width:100%">
        Ver mi plan</a>
    </footer>
  </div>
</div>

<script>
/* Polling del estado del pago.
   Cada 5 s pregunta a /cuenta/plan/estado/<liga_id>. Se detiene al
   mostrar el modal o a los 10 minutos, lo que ocurra primero.

   Para tarjeta el endpoint consulta al proveedor (y aprueba si ya
   pagó). Para SPEI y tienda no hay nada que consultar: el estado
   cambia solo cuando el webhook marca la liga, y el polling lo ve. */
(function () {
  var ligaId = <?= (int)$lr['liga_id'] ?>;
  var modal  = document.getElementById('lfModalPagado');
  if (!modal) return;

  var intervalo = null, intentos = 0, MAX = 120;

  function mostrarPagado(datos) {
    if (intervalo) { clearInterval(intervalo); intervalo = null; }

    var filas = [];
    if (datos.plan && datos.plan.nombre) filas.push(['Plan', esc(datos.plan.nombre)]);
    if (datos.plan && datos.plan.vence) {
      var f = new Date(datos.plan.vence + 'T00:00:00');
      filas.push(['Vence', f.toLocaleDateString('es-MX',
        { day:'2-digit', month:'2-digit', year:'numeric' })]);
    }
    document.getElementById('lfPagadoDatos').innerHTML = filas.map(function (fi) {
      return '<div><dt>' + fi[0] + '</dt><dd>' + fi[1] + '</dd></div>';
    }).join('');

    modal.hidden = false;
    document.body.classList.add('lf-modal-abierto');
    var b = modal.querySelector('[data-cerrar-pagado]');
    if (b) b.focus();
  }

  function cerrar() {
    modal.hidden = true;
    document.body.classList.remove('lf-modal-abierto');
  }
  modal.querySelectorAll('[data-cerrar-pagado]').forEach(function (b) {
    b.addEventListener('click', cerrar);
  });
  modal.addEventListener('click', function (e) { if (e.target === modal) cerrar(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) cerrar();
  });

  function preguntar() {
    if (++intentos > MAX) { clearInterval(intervalo); intervalo = null; return; }
    fetch('/cuenta/plan/estado/' + ligaId, {
      credentials: 'same-origin',
      headers: { 'X-LF-Json': '1' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j.ok && j.pagado) mostrarPagado(j); })
      .catch(function () { /* reintenta en la próxima vuelta */ });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }

  intervalo = setInterval(preguntar, 5000);
})();
</script>
<?php endif; ?>

<script>
document.querySelectorAll('[data-copiar]').forEach(function (b) {
  b.addEventListener('click', function () {
    var t = b.dataset.copiar;
    if (!t) return;
    navigator.clipboard.writeText(t).then(function () {
      var x = b.innerHTML; b.textContent = 'Copiado';
      setTimeout(function () { b.innerHTML = x; }, 1600);
    }).catch(function () {});
  });
});
</script>

<?php elseif ($estPago === 'en_revision'): ?>
<div class="alert alert-info" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b>Estamos revisando tu pago</b> de <?= D::pesos($ultimo['monto']) ?>
    (<?= P::e($ultimo['nombre_plan']) ?>, ref. <span class="lf-mono"><?= P::e($ultimo['referencia']) ?></span>).
    En cuanto lo aprobemos, tu plan se renueva y lo verás aquí.
    <a href="<?= P::e($ultimo['comprobante']) ?>" target="_blank" rel="noopener">Ver comprobante</a></span>
</div>
<?php endif; ?>

<?php /* ═══ PLANES ═══
   Tarjetas de planes con selector Mensual / Anual.

   El flujo al pulsar Seleccionar/Renovar es de dos pasos:
     1) un modal pregunta cómo paga (Tarjeta / SPEI / Efectivo);
     2) al elegir, se pinta una confirmación con avisos, total y
        resumen, antes de mandar el formulario.

   El navegador nunca manda un monto: el precio se recalcula en el
   servidor a partir de plan + periodo (PlanRepo::solicitar). */
$desc = (float)($catalogo['descuento_anual'] ?? 0);
$pe = function ($n) { return D::pesos($n); };
?>
<section class="lf-planes" id="lfPlanes" data-periodo="mensual">
  <header class="cab">
    <h2>Elige tu plan</h2>
    <p>Sin permanencia. Pagas solo el periodo que elijas y se suma a los días que te queden.</p>
    <?php if ($desc > 0): ?>
      <div class="lf-periodo" role="group" aria-label="Periodo de pago">
        <button type="button" class="on" data-periodo="mensual">Mensual</button>
        <button type="button" data-periodo="anual">Anual <span class="ahorro">−<?= rtrim(rtrim(number_format($desc, 1), '0'), '.') ?>%</span></button>
      </div>
    <?php endif; ?>
  </header>

  <div class="rejilla">
    <?php foreach ($catalogo['planes'] as $clave => $pl):
      $actual = strtolower((string)($em['plan'] ?? '')) === $clave; ?>
      <article class="lf-plan<?= $pl['popular'] ? ' popular' : '' ?><?= $actual ? ' actual' : '' ?>">
        <?php if ($pl['popular']): ?><span class="cinta">Más popular</span><?php endif; ?>
        <h3><?= P::e($pl['nombre']) ?><?php if ($actual): ?> <span class="badge bg-success">Tu plan</span><?php endif; ?></h3>

        <div class="precio" data-mensual="<?= $pl['periodos']['mensual']['por_mes'] ?>"
             data-anual="<?= $pl['periodos']['anual']['por_mes'] ?>">
          <b class="lf-mono" data-valor><?= $pe($pl['periodos']['mensual']['por_mes']) ?></b>
          <span>MXN/mes<?= $pl['usuarios'] ? ' · ' . (int)$pl['usuarios'] . ' usuario' . ($pl['usuarios'] === 1 ? '' : 's') : '' ?></span>
        </div>
        <p class="cobro" data-mensual="Se cobra cada mes"
           data-anual="Un solo pago de <?= P::e($pe($pl['periodos']['anual']['monto'])) ?> al año">
          Se cobra cada mes</p>

        <div class="caract">
          <?php foreach ($pl['grupos'] as $titulo => $items): ?>
            <?php if ($titulo !== ''): ?><h4><?= P::e($titulo) ?></h4><?php endif; ?>
            <ul>
              <?php foreach ($items as $it): ?>
                <li><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                         stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                      <polyline points="20 6 9 17 4 12"/></svg><?= P::e($it) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endforeach; ?>
        </div>

        <?php /* Los data-* son lo que el modal lee para armar la confirmación.
                 El navegador NUNCA manda el monto: solo muestra. */ ?>
        <form method="post" action="/cuenta/plan" class="lf-plan-form"
              data-nombre="<?= P::e($pl['nombre']) ?>"
              data-mensual="<?= $pl['periodos']['mensual']['por_mes'] ?>"
              data-anual="<?= $pl['periodos']['anual']['por_mes'] ?>"
              data-meses-mensual="1"
              data-meses-anual="12"
              data-es-actual="<?= $actual ? '1' : '0' ?>">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <input type="hidden" name="plan" value="<?= P::e($clave) ?>">
          <input type="hidden" name="periodo" value="mensual" data-periodo-campo>
          <input type="hidden" name="como_paga" class="lf-como-paga" value="">
          <button class="btn <?= ($pl['popular'] || $actual) ? 'btn-primary' : 'btn-secondary' ?>" type="submit">
            <?= $actual ? 'Renovar' : 'Seleccionar' ?></button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<?php /* ═══ MODAL DE COBRO ═══
   Dos vistas dentro de la misma caja:
     - "metodo": elegir Tarjeta / SPEI / Efectivo.
     - "conf":   confirmación con avisos, items, total y datos,
                 con los mismos nombres que usa abrirConf() en Caja
                 (.lf-conf-av, .lf-conf-items, .lf-conf-total,
                 .lf-conf-datos) para que se vea idéntico. */ ?>
<div class="lf-modal" id="lfModalPago" hidden>
  <div class="caja" role="dialog" aria-modal="true" aria-labelledby="lfModalTit">

    <!-- Paso 1: elegir método -->
    <div data-vista="metodo">
      <header>
        <div>
          <h2 id="lfModalTit">¿Cómo vas a pagar?</h2>
          <p>Elige el método para continuar con tu plan.</p>
        </div>
        <button type="button" class="cerrar" data-cerrar aria-label="Cerrar"><span class="lf-signo">×</span></button>
      </header>
      <div class="cuerpo">
        <div class="lf-metodos" style="padding:0">
          <label class="form-label">¿Cómo paga?</label>
          <div class="ops">
            <button type="button" class="m" data-metodo="_tarjeta">
              <?= W::icono('cobro','17px') ?>
              <span>Tarjeta</span>
            </button>
            <button type="button" class="m" data-metodo="_spei">
              <?= W::icono('venta','17px') ?>
              <span>SPEI</span>
            </button>
            <button type="button" class="m" data-metodo="_tienda">
              <?= W::icono('bolsa','17px') ?>
              <span>Efectivo (tienda)</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Paso 2: confirmación -->
    <div data-vista="conf" hidden>
      <header>
        <div>
          <h2>Confirmar cambio de plan</h2>
          <p>Revisa los datos antes de continuar.</p>
        </div>
        <button type="button" class="cerrar" data-cerrar aria-label="Cerrar"><span class="lf-signo">×</span></button>
      </header>
      <div class="cuerpo">
        <div id="confAvisos" class="lf-conf-av"></div>
        <ul id="confItems" class="lf-conf-items"></ul>
        <div id="confTotal" class="lf-conf-total"></div>
        <dl id="confDatos" class="lf-conf-datos"></dl>
      </div>
      <footer>
        <button type="button" id="cVolver" class="btn btn-secondary">Revisar</button>
        <button type="button" id="cOk" class="btn btn-primary">Confirmar</button>
      </footer>
    </div>

  </div>
</div>

<script>
/* Selector Mensual / Anual: cambia precios y textos sin recargar. */
(function(){
  var caja = document.getElementById('lfPlanes');
  if (!caja) return;
  function pesos(n){ return '$' + Number(n).toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2}); }
  function poner(p){
    caja.dataset.periodo = p;
    caja.querySelectorAll('[data-periodo]').forEach(function(b){
      if (b.tagName === 'BUTTON') b.classList.toggle('on', b.dataset.periodo === p);
    });
    caja.querySelectorAll('.precio').forEach(function(x){
      x.querySelector('[data-valor]').textContent = pesos(x.dataset[p]);
    });
    caja.querySelectorAll('.cobro').forEach(function(x){ x.textContent = x.dataset[p]; });
    caja.querySelectorAll('[data-periodo-campo]').forEach(function(i){ i.value = p; });
  }
  caja.querySelectorAll('.lf-periodo button').forEach(function(b){
    b.addEventListener('click', function(){ poner(b.dataset.periodo); });
  });
})();

/* Modal de cobro: método → confirmación → envío.
   Sigue el mismo patrón que abrirConf() en Caja: avisos en
   .lf-conf-av, items en .lf-conf-items, total en .lf-conf-total,
   y el resumen en .lf-conf-datos. El foco cae en "Revisar" al
   llegar a la confirmación, no en el botón de enviar. */
(function () {
  var modal = document.getElementById('lfModalPago');
  if (!modal) return;

  var vistaMetodo = modal.querySelector('[data-vista="metodo"]');
  var vistaConf   = modal.querySelector('[data-vista="conf"]');
  var formActivo  = null;
  var metodoActivo = null;

  /* --- Abrir desde cualquier tarjeta de plan --- */
  document.querySelectorAll('.lf-plan-form').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (f.dataset.listo === '1') return;
      ev.preventDefault();
      formActivo = f;
      mostrarVista('metodo');
      modal.hidden = false;
      document.body.classList.add('lf-modal-abierto');
      var primero = vistaMetodo.querySelector('.m');
      if (primero) primero.focus();
    });
  });

  function mostrarVista(cual) {
    vistaMetodo.hidden = (cual !== 'metodo');
    vistaConf.hidden   = (cual !== 'conf');
  }

  function cerrar() {
    modal.hidden = true;
    document.body.classList.remove('lf-modal-abierto');
    formActivo = null;
    metodoActivo = null;
  }

  /* --- Cerrar --- */
  modal.querySelectorAll('[data-cerrar]').forEach(function (b) {
    b.addEventListener('click', cerrar);
  });
  modal.addEventListener('click', function (e) {
    if (e.target === modal) cerrar();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || modal.hidden) return;
    /* Escape desde la confirmación vuelve al paso anterior, no cierra:
       cerrar de golpe obliga a empezar otra vez. */
    if (!vistaConf.hidden) { mostrarVista('metodo'); return; }
    cerrar();
  });

  /* --- Paso 1: elegir método --- */
  vistaMetodo.querySelectorAll('.m').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!formActivo) return;
      metodoActivo = b.dataset.metodo;
      pintarConfirmacion();
      mostrarVista('conf');
      /* El foco va a "Revisar", igual que en Caja. */
      document.getElementById('cVolver').focus();
    });
  });

  /* --- Paso 2: pintar la confirmación --- */
  function pintarConfirmacion() {
    var f = formActivo;
    var nombrePlan = f.dataset.nombre || '';
    var periodo    = f.querySelector('[data-periodo-campo]').value;
    var esActual   = f.dataset.esActual === '1';
    var porMes     = parseFloat(f.dataset[periodo]) || 0;
    var meses      = parseInt(f.dataset['meses' + (periodo === 'anual' ? 'Anual' : 'Mensual')], 10) || 1;
    var total      = porMes * meses;

    var etiquetaPeriodo = periodo === 'anual' ? 'Anual · 12 meses' : 'Mensual · 1 mes';
    var etiquetaMetodo  = { _tarjeta:'Tarjeta', _spei:'SPEI', _tienda:'Efectivo (tienda)' }[metodoActivo] || metodoActivo;

    /* Avisos: lo que cambia respecto a hoy. */
    var av = [];
    if (esActual) {
      av.push('Estás <b>renovando tu plan actual</b>: los días que te queden se suman al nuevo periodo.');
    } else {
      av.push('Estás <b>cambiando de plan</b>: el nuevo plan reemplazará al anterior una vez confirmado el pago.');
    }
    if (metodoActivo === '_spei') {
      av.push('Al confirmar, recibirás la <b>CLABE y la referencia</b> para realizar tu transferencia. Tu plan se actualizará automáticamente una vez confirmado el pago.');
    } else if (metodoActivo === '_tienda') {
      av.push('Recibirás las instrucciones para realizar tu pago en <b>efectivo</b>. Tu plan se actualizará automáticamente una vez confirmado el pago.');
    } else {
      av.push('Al confirmar el pago, se procesará el <b>cargo a tu tarjeta</b> y tu plan se actualizará automáticamente.');
    }

    document.getElementById('confAvisos').innerHTML =
      av.map(function(a){ return '<p>' + a + '</p>'; }).join('');

    /* Items: aquí solo hay una línea, el plan. */
    document.getElementById('confItems').innerHTML =
      '<li><span>' + esc(nombrePlan) + ' · ' + esc(etiquetaPeriodo) + '</span>' +
      '<b class="lf-mono">' + money(total) + '</b></li>';

    document.getElementById('confTotal').innerHTML =
      '<span>Total</span><b class="lf-mono">' + money(total) + '</b>';

    /* Resumen */
    var filas = [
      ['Plan',     esc(nombrePlan)],
      ['Periodo',  esc(etiquetaPeriodo)],
      ['Paga con', esc(etiquetaMetodo)],
    ];
    document.getElementById('confDatos').innerHTML = filas.map(function (fi) {
      return '<div><dt>' + fi[0] + '</dt><dd>' + fi[1] + '</dd></div>';
    }).join('');

    /* Texto del botón según método. */
    var cOk = document.getElementById('cOk');
    if (metodoActivo === '_spei')        cOk.textContent = 'Confirmar y ver datos de transferencia';
    else if (metodoActivo === '_tienda') cOk.textContent = 'Confirmar y generar referencia';
    else                                 cOk.textContent = 'Confirmar y pagar';
  }

  /* --- Volver al paso 1 --- */
  document.getElementById('cVolver').addEventListener('click', function () {
    mostrarVista('metodo');
    var b = vistaMetodo.querySelector('.m[data-metodo="' + metodoActivo + '"]');
    if (b) b.focus();
  });

  /* --- Confirmar y enviar --- */
  document.getElementById('cOk').addEventListener('click', function () {
    if (!formActivo || !metodoActivo) return;
    var campo = formActivo.querySelector('.lf-como-paga');
    if (campo) campo.value = metodoActivo;
    formActivo.dataset.listo = '1';
    formActivo.submit();
  });

  /* --- Utilidades --- */
  function money(n) {
    return '$' + Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }
})();
</script>


<?php if ($pagosPlan): ?>
<section class="card" id="mis-pagos">
  <header class="card-header">Mis pagos</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Fecha</th><th>Plan</th><th>Referencia</th>
        <th class="text-end">Monto</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach ($pagosPlan as $pp): $e2 = $etiqueta[$pp['estado']] ?? [$pp['estado'],'bg-secondary']; ?>
        <tr>
          <td data-label="Fecha"><?= date('d/m/Y', strtotime($pp['creado_en'])) ?></td>
          <td data-label="Plan"><?= P::e($pp['nombre_plan']) ?></td>
          <td data-label="Referencia" class="lf-mono" style="font-size:12px"><?= P::e($pp['referencia']) ?></td>
          <td data-label="Monto" class="text-end lf-mono"><?= D::pesos($pp['monto']) ?></td>
          <td data-label="Estado"><span class="badge <?= $e2[1] ?>"><?= $e2[0] ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php endif; /* fin del catálogo */ ?>
<?php endif; ?>


<?php /* ═══════ DATOS FISCALES ═══════ */ elseif ($pestana === 'fiscales'): ?>
<section class="card" style="max-width:720px">
  <header class="card-header">
    <div><span>Datos fiscales</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Los que el SAT necesita para timbrar tus facturas</p></div>
  </header>
  <div class="card-body">
    <form method="post" action="/cuenta/fiscales" class="lf-form-g">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">

      <div class="todo">
        <label class="form-label">Tipo de persona</label>
        <div style="display:flex;gap:9px;flex-wrap:wrap" id="fiscTipo">
          <?php foreach (['fisica'=>'Persona física','moral'=>'Persona moral'] as $k=>$v): ?>
            <label class="lf-pill <?= ($fiscales['tipo_persona'] ?? '')===$k ? 'active':'' ?>"
                   style="cursor:pointer">
              <input type="radio" name="tipo_persona" value="<?= $k ?>" hidden
                     <?= ($fiscales['tipo_persona'] ?? '')===$k ? 'checked':'' ?>><?= $v ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <script>
      /* Los radios van ocultos: sin esto, al elegir una opción no se veía
         marcada y parecía que no dejaba seleccionar. */
      (function () {
        var caja = document.getElementById('fiscTipo');
        if (!caja) return;
        caja.addEventListener('change', function () {
          caja.querySelectorAll('.lf-pill').forEach(function (l) {
            var r = l.querySelector('input');
            l.classList.toggle('active', !!(r && r.checked));
          });
        });
      })();
      </script>

      <div><label class="form-label">RFC</label>
        <input class="form-control lf-mono" name="rfc_fiscal" style="text-transform:uppercase"
               value="<?= P::e($fiscales['rfc_fiscal'] ?? '') ?>" maxlength="13"></div>
      <div><label class="form-label">CP fiscal</label>
        <input class="form-control lf-mono" name="cp_fiscal" inputmode="numeric" maxlength="5"
               value="<?= P::e($fiscales['cp_fiscal'] ?? '') ?>"></div>
      <div class="todo"><label class="form-label">Razón social</label>
        <input class="form-control" name="razon_social"
               value="<?= P::e($fiscales['razon_social'] ?? '') ?>"></div>
      <div class="todo"><label class="form-label">Régimen fiscal</label>
        <select class="form-select" name="regimen_sat">
          <option value="">Sin definir</option>
          <?php foreach (C::REGIMENES as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($fiscales['regimen_sat'] ?? '')===$k?'selected':'' ?>>
              <?= P::e($v) ?></option>
          <?php endforeach; ?>
        </select></div>

      <div class="todo"><button class="btn btn-primary" type="submit">Guardar</button></div>
      <p class="todo" style="font-size:11.5px;color:var(--lf-tinta-4);margin:0;line-height:1.5">
        Tienen que coincidir <b>exactamente</b> con tu constancia de situación fiscal.
        Un solo carácter distinto y el SAT rechaza el timbrado.
      </p>
    </form>
  </div>
</section>


<?php /* ═══════ COMERCIO ═══════ */ elseif ($pestana === 'comercio'):
$c = $comercio;
$v = function ($k) use ($c) { return P::e($c[$k] ?? ''); };
$grupos = [
  ['Datos generales del titular', [
    ['titular_nombre','Nombre del titular','Como aparece en el estado de cuenta',2],
    ['nombre_comercio','Nombre del comercio','',1],
    ['titular_correo','Correo','',1,'email'],
    ['giro','Actividad o giro','',1],
    ['telefono_celular','Celular','',0.7],
    ['telefono_oficina','Oficina','',0.7],
    ['calle_numero','Calle y número exterior','',2],
    ['numero_interior','Interior','',0.6],
    ['colonia','Colonia','',1],
    ['delegacion_municipio','Delegación o municipio','',1],
    ['ciudad','Ciudad','',1],
    ['estado_direccion','Estado','',1],
    ['pais','País','',1],
    ['nombre_vendedor','Nombre del vendedor','',1],
  ]],
  ['Representante legal', [
    ['rep_legal_nombre','Nombre completo','',2],
    ['rep_legal_escritura','Número y fecha de escritura','',1],
    ['rep_legal_notaria_numero','Notaría número','',0.7],
    ['rep_legal_notario_nombre','Nombre del notario','',1],
    ['rep_legal_ciudad','Ciudad','',1],
  ]],
  ['Identificación del titular', [
    ['id_tipo','Tipo de identificación','',1],
    ['id_numero','Número','',1],
    ['id_fecha_expedicion','Fecha de expedición','',1,'date'],
    ['id_vigencia','Vigencia','',1,'date'],
  ]],
  ['Datos bancarios', [
    ['banco','Banco','',1],
    ['plaza','Plaza','',1],
    ['sucursal_bancaria','Sucursal','',1],
    ['cuenta_cheques','Cuenta de cheques','',1],
    ['cuenta_clabe','CLABE','18 dígitos',1.4],
  ]],
];
?>
<div id="comAviso" role="alert" hidden style="margin-bottom:18px"></div>
<div id="comBorrador" hidden class="alert alert-info" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>Recuperamos lo que habías escrito y no se había guardado.
    <a href="#" id="comDescartar" style="font-weight:600">Descartar borrador</a></span>
</div>
<form method="post" action="/cuenta/comercio" id="formComercio">
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <?php foreach ($grupos as $g): ?>
    <section class="card">
      <header class="card-header"><?= P::e($g[0]) ?></header>
      <div class="card-body">
        <div class="lf-form-g">
          <?php foreach ($g[1] as $campo):
            $k = $campo[0]; $tipo = $campo[4] ?? 'text'; ?>
            <div<?= $campo[3] >= 2 ? ' class="ancho"' : '' ?>>
              <label class="form-label"><?= P::e($campo[1]) ?></label>
              <input class="form-control<?= in_array($k,['cuenta_clabe','cuenta_cheques'],true)?' lf-mono':'' ?>"
                     type="<?= $tipo ?>" name="<?= $k ?>" value="<?= $v($k) ?>"
                     <?= $k==='cuenta_clabe' ? 'inputmode="numeric" maxlength="18"' : '' ?>
                     <?= $campo[2] ? 'placeholder="'.P::e($campo[2]).'"' : '' ?>>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endforeach; ?>

  <section class="card">
    <div class="card-body">
      <label style="display:flex;gap:11px;align-items:flex-start;cursor:pointer;font-size:13px;
             line-height:1.55;color:var(--lf-tinta-2)">
        <input type="checkbox" name="clausulado" value="1" style="margin-top:3px;flex-shrink:0"
               <?= !empty($c['clausulado_aceptado_en']) ? 'checked disabled' : 'required' ?>>
        <span>He leído y acepto el clausulado del contrato de procesamiento de
          transacciones.
          <?php if (!empty($c['clausulado_aceptado_en'])): ?>
            <b style="display:block;color:var(--lf-brand-2);font-size:12px;margin-top:4px">
              Aceptado el <?= date('d/m/Y', strtotime($c['clausulado_aceptado_en'])) ?></b>
          <?php else: ?>
            <b style="display:block;font-size:12px;margin-top:4px">Sin esto no se puede enviar.</b>
          <?php endif; ?>
        </span>
      </label>
      <button class="btn btn-primary" type="submit" style="margin-top:18px">Guardar datos</button>
      <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:12px;line-height:1.55">
        Se puede guardar incompleto y seguir después: no se envía a ningún lado hasta
        que la documentación esté aprobada.
        <?php if (!empty($c['actualizado_en'])): ?>
          Última actualización: <?= date('d/m/Y H:i', strtotime($c['actualizado_en'])) ?>.
        <?php endif; ?>
      </p>
    </div>
  </section>
</form>

<script>
(function () {
  var form = document.getElementById('formComercio');
  if (!form) return;
  var aviso = document.getElementById('comAviso'),
      nota  = document.getElementById('comBorrador'),
      boton = form.querySelector('button[type="submit"]'),
      CLAVE = 'lf-comercio-borrador';

  /* ── Borrador: cada cambio se guarda en este navegador ──
     Si la página se recarga o algo sale mal, lo escrito sigue ahí. El token
     no se guarda; las casillas ya aceptadas (disabled) tampoco. */
  function campos() {
    return [].slice.call(form.elements).filter(function (e) {
      return e.name && e.name !== 'token' && !e.disabled && e.type !== 'submit';
    });
  }
  function guardarBorrador() {
    try {
      var d = {};
      campos().forEach(function (e) { d[e.name] = e.type === 'checkbox' ? e.checked : e.value; });
      localStorage.setItem(CLAVE, JSON.stringify(d));
    } catch (x) { /* sin almacenamiento, se opera igual */ }
  }
  function borrarBorrador() { try { localStorage.removeItem(CLAVE); } catch (x) {} }

  try {
    var g = JSON.parse(localStorage.getItem(CLAVE) || 'null'), cambio = false;
    if (g) campos().forEach(function (e) {
      if (!(e.name in g)) return;
      if (e.type === 'checkbox') { if (e.checked !== g[e.name]) { e.checked = g[e.name]; cambio = true; } }
      else if (e.value !== g[e.name]) { e.value = g[e.name]; cambio = true; }
    });
    if (cambio) nota.hidden = false; else borrarBorrador();
  } catch (x) {}

  var espera = null;
  form.addEventListener('input', function () { clearTimeout(espera); espera = setTimeout(guardarBorrador, 400); });
  form.addEventListener('change', guardarBorrador);
  document.getElementById('comDescartar').addEventListener('click', function (ev) {
    ev.preventDefault(); borrarBorrador(); location.reload();
  });

  /* ── Guardar sin recargar: si algo está mal, se dice aquí y no se pierde nada ── */
  function decir(texto, ok) {
    aviso.className = 'alert alert-' + (ok ? 'success' : 'danger');
    aviso.textContent = texto; aviso.hidden = false;
    aviso.scrollIntoView({behavior: 'smooth', block: 'center'});
  }
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (boton.disabled) return;
    guardarBorrador();
    var original = boton.textContent;
    boton.disabled = true; boton.textContent = 'Guardando…';
    fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin',
                        headers: {'X-LF-Ajax': '1', 'Accept': 'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        decir(j.texto || (j.ok ? 'Datos de comercio guardados.' : 'No se pudieron guardar.'), !!j.ok);
        if (j.ok) { borrarBorrador(); nota.hidden = true; }
      })
      .catch(function () {
        decir('No se pudo guardar (sin conexión o la sesión caducó). Lo que escribiste sigue aquí: vuelve a intentarlo.', false);
      })
      .then(function () { boton.disabled = false; boton.textContent = original; });
  });
})();
</script>


<?php /* ═══════ DOCUMENTOS ═══════ */ else: ?>
<div class="alert alert-<?= $ed['estado']==='aprobada'?'success':($ed['estado']==='rechazada'?'danger':'info') ?>"
     style="margin-bottom:18px" id="resumenDocs">
  <?= W::icono('alerta','18px') ?>
  <span><b><?= P::e($textoDoc) ?>.</b>
    <?= (int)$ed['aprobados'] ?> de <?= (int)$ed['total'] ?> obligatorios aprobados.
    JPG, PNG o PDF, máximo 10 MB. Un administrador de LibertyFin los revisa en 24 a 72 horas.</span>
</div>

<div class="lf-docs">
  <?php foreach (C::DOCUMENTOS as $k => $d):
    $doc = $documentos[$k] ?? null;
    $est = $doc['estado'] ?? null; ?>
    <section class="card lf-doc" data-doc="<?= P::e($k) ?>">
      <div class="card-body">
        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:14px">
          <span class="lf-tile <?= $est==='aprobado'?'':($est==='rechazado'?'r':'g') ?>"
                style="width:38px;height:38px;border-radius:12px;margin:0;flex-shrink:0">
            <?= W::icono($est==='aprobado'?'cobro':($est==='rechazado'?'alerta':'serv'),'17px') ?></span>
          <div style="flex:1;min-width:0">
            <b style="display:block;font-size:13.5px;font-weight:600;line-height:1.35">
              <?= P::e($d[0]) ?></b>
            <small style="font-size:11px;color:var(--lf-tinta-4)">
              <?= $d[1] ? 'Obligatorio' : 'Opcional' ?></small>
          </div>
          <?php if ($est === 'aprobado'): ?><span class="badge bg-success">Aprobado</span>
          <?php elseif ($est === 'rechazado'): ?><span class="badge bg-danger">Rechazado</span>
          <?php elseif ($est): ?><span class="badge bg-warning">En revisión</span>
          <?php endif; ?>
        </div>

        <?php if ($est === 'rechazado' && !empty($doc['motivo_rechazo'])): ?>
          <p style="font-size:12px;color:var(--lf-rojo);background:var(--lf-rojo-soft);
                    padding:10px 12px;border-radius:var(--lf-r);margin-bottom:12px;line-height:1.5">
            <b>Por qué se rechazó:</b> <?= P::e($doc['motivo_rechazo']) ?></p>
        <?php endif; ?>

        <?php if ($doc): ?>
          <div style="display:flex;align-items:center;gap:9px;font-size:11.5px;
               color:var(--lf-tinta-4);margin-bottom:12px">
            <a href="<?= P::e($doc['ruta_archivo']) ?>" target="_blank" rel="noopener"
               style="font-weight:600">Ver archivo</a>
            <span>·</span>
            <span><?= date('d/m/Y', strtotime($doc['subido_en'])) ?></span>
            <?php if ($doc['tamano_bytes']): ?>
              <span>·</span><span><?= round($doc['tamano_bytes']/1024) ?> KB</span>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <?php /* Misma altura que con archivo: al subir, la tarjeta no crece
                   y no desacomoda las demás de la fila. */ ?>
          <div style="display:flex;align-items:center;gap:9px;font-size:11.5px;
               color:var(--lf-tinta-4);margin-bottom:12px">Sin archivo todavía</div>
        <?php endif; ?>

        <?php if ($est !== 'aprobado'): ?>
          <form method="post" action="/cuenta/documento" enctype="multipart/form-data">
            <input type="hidden" name="token" value="<?= P::e($token) ?>">
            <input type="hidden" name="tipo" value="<?= P::e($k) ?>">
            <input type="file" name="archivo" accept="image/png,image/jpeg,image/webp,application/pdf" required
                   style="font-size:11.5px;width:100%;margin-bottom:10px">
            <button class="btn btn-secondary btn-sm" type="submit" style="width:100%">
              <?= $doc ? 'Reemplazar' : 'Subir' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>

<script>
/* Subir un documento SIN recargar la página.
   Cada tarjeta es su propio formulario: al enviar uno, la página entera
   se recargaba y los archivos que ya se habían elegido en las demás
   tarjetas se perdían. Ahora se envía solo ese, y solo esa tarjeta se
   vuelve a pintar; los demás campos de archivo no se tocan. */
(function () {
  var rejilla = document.querySelector('.lf-docs');
  if (!rejilla || !window.fetch || !window.FormData) return;

  rejilla.addEventListener('submit', function (ev) {
    var f = ev.target.closest('form[action="/cuenta/documento"]');
    if (!f) return;
    ev.preventDefault();

    var tarjeta = f.closest('.lf-doc');
    var btn = f.querySelector('button[type=submit]');
    var txt = btn.textContent;
    btn.disabled = true; btn.textContent = 'Subiendo…';

    /* El aviso flota fuera de la rejilla (dentro de la tarjeta la hacía crecer
       y desacomodaba la fila). Usa los colores del tema, igual que el resto
       de avisos: en modo oscuro toma la superficie oscura, no un fondo claro. */
    function msj(t, ok) {
      if (!document.getElementById('lfAvisoDocCss')) {
        var s = document.createElement('style'); s.id = 'lfAvisoDocCss';
        s.textContent =
          '#lfAvisoDoc{position:fixed;left:50%;bottom:24px;z-index:500;display:flex;align-items:center;gap:10px;'
          + 'max-width:min(92vw,440px);padding:12px 16px;border-radius:16px;font-size:13px;line-height:1.45;'
          + 'font-weight:600;color:var(--lf-tinta);'
          + 'background:color-mix(in srgb,var(--lf-sup) 90%,transparent);'
          + 'border:1px solid color-mix(in srgb,var(--lf-linea) 85%,transparent);'
          + '-webkit-backdrop-filter:blur(14px) saturate(1.4);backdrop-filter:blur(14px) saturate(1.4);'
          + 'box-shadow:0 12px 32px rgba(0,0,0,.18);'
          + 'opacity:0;transform:translate(-50%,14px) scale(.97);pointer-events:none;'
          + 'transition:opacity .28s ease,transform .38s cubic-bezier(.2,.9,.3,1.2)}'
          + '#lfAvisoDoc.on{opacity:1;transform:translate(-50%,0) scale(1);pointer-events:auto}'
          + '#lfAvisoDoc i{flex:0 0 auto;width:9px;height:9px;border-radius:50%;background:var(--lf-brand)}'
          + '#lfAvisoDoc.mal i{background:var(--lf-rojo)}';
        document.head.appendChild(s);
      }
      var a = document.getElementById('lfAvisoDoc');
      if (!a) {
        a = document.createElement('div');
        a.id = 'lfAvisoDoc'; a.setAttribute('role', 'status');
        a.innerHTML = '<i></i><span></span>';
        document.body.appendChild(a);
      }
      a.querySelector('span').textContent = t;
      a.classList.toggle('mal', !ok);
      void a.offsetWidth;                       /* reinicia la transición si ya estaba */
      a.classList.add('on');
      clearTimeout(a._t); a._t = setTimeout(function () { a.classList.remove('on'); }, 6000);
    }

    fetch(f.action, { method: 'POST', body: new FormData(f), credentials: 'same-origin',
                      headers: { 'X-LF-Ajax': '1' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { btn.disabled = false; btn.textContent = txt; msj(j.texto, false); return; }
        return fetch('/cuenta?t=documentos', { credentials: 'same-origin',
                                               headers: { 'X-LF-Parcial': '1' } })
          .then(function (r) { return r.text(); })
          .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var nueva = doc.querySelector('.lf-doc[data-doc="' + tarjeta.dataset.doc + '"]');
            if (nueva) tarjeta.replaceWith(nueva);
            var r0 = document.getElementById('resumenDocs'), r1 = doc.getElementById('resumenDocs');
            if (r0 && r1) r0.replaceWith(r1);
            msj(j.texto || 'Documento subido.', true);
          });
      })
      .catch(function () {
        btn.disabled = false; btn.textContent = txt;
        msj('No se pudo subir. Revisa tu conexión e inténtalo de nuevo.', false);
      });
  });
})();
</script>
<?php endif; ?>