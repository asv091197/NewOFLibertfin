<?php
// Conexión para cargar giros comerciales
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$db_main = "juanc141_ventas";

$conn_giros = new mysqli($servername, $username, $password, $db_main);

// Verificar si hay mensajes de éxito/error
$success = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registra tu empresa — Libertyfin</title>
<link rel="stylesheet" href="../css/main.css">
<style>
body { padding-top: 64px; background: var(--bg); }

.registro-wrap {
  min-height: calc(100vh - 64px);
  display: grid;
  grid-template-columns: 1fr 1fr;
}

/* ── LADO IZQUIERDO — info ── */
.reg-left {
  background: var(--ink);
  padding: 64px 56px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  position: relative;
  overflow: hidden;
}
.reg-left-glow {
  position: absolute;
  width: 400px; height: 400px; border-radius: 50%;
  background: rgba(34,197,94,0.1); filter: blur(80px);
  top: -100px; left: -80px; pointer-events: none;
}
.reg-left-inner { position: relative; z-index: 1; }

.reg-eyebrow {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 5px 14px; border-radius: 100px;
  background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.25);
  font-size: 12px; font-weight: 600; color: var(--green);
  margin-bottom: 28px;
}
.reg-eyebrow-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--green); animation: blink 2s ease-in-out infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.4} }

.reg-title {
  font-family: 'Nunito', sans-serif;
  font-size: clamp(30px, 3.5vw, 44px);
  font-weight: 900; letter-spacing: -1.5px;
  color: white; line-height: 1.1; margin-bottom: 16px;
}
.reg-title em { font-style: italic; color: var(--green); }

.reg-sub {
  font-size: 15px; color: rgba(255,255,255,0.5);
  line-height: 1.7; margin-bottom: 40px; max-width: 380px;
}

.reg-benefits { display: flex; flex-direction: column; gap: 14px; margin-bottom: 44px; }
.reg-benefit {
  display: flex; align-items: flex-start; gap: 12px;
}
.reg-benefit-icon {
  width: 36px; height: 36px; border-radius: 9px;
  background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.2);
  display: flex; align-items: center; justify-content: center;
  font-size: 17px; flex-shrink: 0;
}
.reg-benefit-title { font-size: 14px; font-weight: 700; color: white; margin-bottom: 2px; }
.reg-benefit-desc { font-size: 12.5px; color: rgba(255,255,255,0.45); line-height: 1.5; }

.reg-trust {
  display: flex; flex-direction: column; gap: 8px;
  padding-top: 32px; border-top: 1px solid rgba(255,255,255,0.08);
}
.reg-trust-item {
  display: flex; align-items: center; gap: 8px;
  font-size: 13px; color: rgba(255,255,255,0.4); font-weight: 500;
}
.reg-trust-check { color: var(--green); font-size: 12px; font-weight: 700; }

/* ── LADO DERECHO — formulario ── */
.reg-right {
  background: white;
  padding: 56px 64px;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.reg-form-header { margin-bottom: 32px; }
.reg-form-title {
  font-family: 'Nunito', sans-serif;
  font-size: 26px; font-weight: 900;
  letter-spacing: -0.8px; color: var(--ink); margin-bottom: 6px;
}
.reg-form-sub { font-size: 14px; color: var(--ink3); }

.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
.form-group.full { grid-column: span 2; }
.form-label { font-size: 12.5px; font-weight: 600; color: var(--ink2); }
.form-label span { color: #ef4444; }
.form-input {
  background: var(--bg); border: 1.5px solid var(--border);
  border-radius: 10px; padding: 11px 14px;
  font-family: 'Poppins', sans-serif; font-size: 13.5px; color: var(--ink);
  outline: none; transition: all 0.15s; width: 100%;
}
.form-input:focus { border-color: var(--green); background: white; box-shadow: 0 0 0 3px rgba(34,197,94,0.1); }
.form-input::placeholder { color: var(--ink4); }
.form-select { appearance: none; cursor: pointer;
  background-image: url("data:image/svg+xml,%3Csvg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' xmlns='http://www.w3.org/2000/svg'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 12px center;
}

.submit-btn {
  width: 100%; padding: 14px;
  border-radius: 11px; border: none;
  background: var(--green); color: white;
  font-family: 'Poppins', sans-serif; font-size: 15px; font-weight: 700;
  cursor: pointer; transition: all 0.2s;
  box-shadow: 0 4px 16px rgba(34,197,94,0.3);
  display: flex; align-items: center; justify-content: center; gap: 8px;
  margin-bottom: 14px;
}
.submit-btn:hover { background: var(--green-d); transform: translateY(-1px); box-shadow: 0 8px 24px rgba(34,197,94,0.4); }
.submit-btn:active { transform: translateY(0); }

.form-terms {
  font-size: 11.5px; color: var(--ink4); text-align: center; line-height: 1.6;
}
.form-terms a { color: var(--green-d); text-decoration: none; font-weight: 600; }
.form-terms a:hover { text-decoration: underline; }

.already-account {
  text-align: center; margin-top: 20px;
  font-size: 13px; color: var(--ink3);
  padding-top: 20px; border-top: 1px solid var(--border);
}
.already-account a {
  color: var(--green-d); font-weight: 700; text-decoration: none;
}
.already-account a:hover { text-decoration: underline; }

/* Mensajes */
.alert {
  padding: 14px 18px;
  border-radius: 12px;
  margin-bottom: 24px;
  font-size: 14px;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 10px;
}
.alert-success {
  background: #e8f5e9;
  border: 1px solid #4caf50;
  color: #2e7d32;
}
.alert-error {
  background: #ffebee;
  border: 1px solid #ef5350;
  color: #c62828;
}
.alert-icon {
  font-size: 18px;
}

/* Success state */
.success-state {
  text-align: center;
  padding: 40px 20px;
}
.success-icon {
  width: 80px; height: 80px; border-radius: 50%;
  background: #e8f5e9; border: 2px solid #4caf50;
  display: flex; align-items: center; justify-content: center;
  font-size: 36px; margin: 0 auto 20px;
}
.success-title {
  font-family: 'Nunito', sans-serif;
  font-size: 26px; font-weight: 900; color: var(--ink);
  margin-bottom: 10px; letter-spacing: -0.5px;
}
.success-sub { font-size: 15px; color: var(--ink3); line-height: 1.65; margin-bottom: 28px; }

@media(max-width: 900px) {
  .registro-wrap { grid-template-columns: 1fr; }
  .reg-left { padding: 48px 32px; }
  .reg-right { padding: 48px 32px; }
  .form-grid-2 { grid-template-columns: 1fr; }
  .form-group.full { grid-column: span 1; }
}
</style>
</head>
<body>

<div id="lf-nav-placeholder"></div>

<div class="registro-wrap">

  <!-- ── IZQUIERDA ── -->
  <div class="reg-left">
    <div class="reg-left-glow"></div>
    <div class="reg-left-inner">
      <div class="reg-eyebrow">
        <span class="reg-eyebrow-dot"></span>
        14 días gratis · Sin tarjeta de crédito
      </div>
      <h1 class="reg-title">
        Tu negocio listo<br>
        <em>en 10 minutos.</em>
      </h1>
      <p class="reg-sub">
        Únete a más de 1,000 empresas en México que ya venden más y administran mejor con Libertyfin.
      </p>
      <div class="reg-benefits">
        <div class="reg-benefit">
          <div class="reg-benefit-icon">🛒</div>
          <div>
            <div class="reg-benefit-title">Punto de venta listo al instante</div>
            <div class="reg-benefit-desc">Empieza a cobrar desde cualquier dispositivo sin instalaciones.</div>
          </div>
        </div>
        <div class="reg-benefit">
          <div class="reg-benefit-icon">📄</div>
          <div>
            <div class="reg-benefit-title">Facturación CFDI 4.0 incluida</div>
            <div class="reg-benefit-desc">Timbre tus facturas al SAT en segundos desde el día uno.</div>
          </div>
        </div>
        <div class="reg-benefit">
          <div class="reg-benefit-icon">📦</div>
          <div>
            <div class="reg-benefit-title">Inventario en tiempo real</div>
            <div class="reg-benefit-desc">Controla tu stock en todos tus almacenes desde cualquier lugar.</div>
          </div>
        </div>
        <div class="reg-benefit">
          <div class="reg-benefit-icon">🎓</div>
          <div>
            <div class="reg-benefit-title">Capacitación y soporte incluidos</div>
            <div class="reg-benefit-desc">Te acompañamos en cada paso para que saques el máximo provecho.</div>
          </div>
        </div>
      </div>
      <div class="reg-trust">
        <div class="reg-trust-item"><span class="reg-trust-check">✓</span> Sin permanencia ni contratos</div>
        <div class="reg-trust-item"><span class="reg-trust-check">✓</span> Cancela cuando quieras</div>
        <div class="reg-trust-item"><span class="reg-trust-check">✓</span> Soporte en español 24/7</div>
        <div class="reg-trust-item"><span class="reg-trust-check">✓</span> Tus datos siempre seguros</div>
      </div>
    </div>
  </div>

  <!-- ── DERECHA — FORMULARIO ── -->
  <div class="reg-right">

    <?php if ($success == 1): ?>
    <!-- Estado de éxito -->
    <div class="success-state" id="successView">
      <div class="success-icon">🎉</div>
      <div class="success-title">¡Cuenta creada exitosamente!</div>
      <p class="success-sub">
        Revisa tu correo electrónico — te enviamos las credenciales para acceder a tu cuenta y comenzar tu prueba de 14 días.
      </p>
      <a class="submit-btn" href="../index.html" style="text-decoration:none; display:inline-block; width:auto; padding:12px 28px;">
        Ir al inicio →
      </a>
    </div>
    <?php else: ?>

    <!-- Mensaje de error -->
    <?php if ($error): ?>
    <div class="alert alert-error">
      <span class="alert-icon">⚠️</span>
      <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <div class="reg-form-header">
      <h2 class="reg-form-title">Crea tu cuenta gratis</h2>
      <p class="reg-form-sub">14 días de prueba. Sin tarjeta de crédito requerida.</p>
    </div>

    <form id="registroForm" method="POST" action="../registroEmpresa.php">
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Nombre <span>*</span></label>
          <input class="form-input" type="text" placeholder="Ej. Juan" name="nombre_contacto" id="firstName" required>
        </div>
        <div class="form-group">
          <label class="form-label">Apellido <span>*</span></label>
          <input class="form-input" type="text" placeholder="Ej. Rodríguez" name="apellido_contacto" id="lastName" required>
        </div>
        <div class="form-group full">
          <label class="form-label">Nombre de tu empresa <span>*</span></label>
          <input class="form-input" type="text" placeholder="Ej. Distribuidora MX S.A. de C.V." name="nombre_empresa" id="company" required>
        </div>
        <div class="form-group full">
          <label class="form-label">Correo electrónico <span>*</span></label>
          <input class="form-input" type="email" placeholder="correo@tuempresa.com" name="email" id="email" required>
        </div>
        <div class="form-group">
          <label class="form-label">Teléfono <span>*</span></label>
          <input class="form-input" type="tel" placeholder="+52 55 0000 0000" name="telefono" id="phone" required>
        </div>
        <div class="form-group">
          <label class="form-label">Giro del negocio <span>*</span></label>
          <select class="form-input form-select" name="giro_comercial" id="industry" required>
            <option value="" disabled selected>Selecciona...</option>
            <?php
            if (!$conn_giros->connect_error) {
              $result = $conn_giros->query("SELECT id, nombre FROM giro_comercial ORDER BY nombre");
              while ($row = $result->fetch_assoc()) {
                echo '<option value="' . $row['id'] . '">' . htmlspecialchars($row['nombre']) . '</option>';
              }
              $conn_giros->close();
            }
            ?>
          </select>
        </div>
        <div class="form-group full">
          <label class="form-label">¿Cuántos empleados tienen? <span>*</span></label>
          <select class="form-input form-select" name="cantidad_empleados" id="size" required>
            <option value="" disabled selected>Selecciona...</option>
            <option value="Solo yo">Solo yo</option>
            <option value="2 – 5 empleados">2 – 5 empleados</option>
            <option value="6 – 20 empleados">6 – 20 empleados</option>
            <option value="21 – 50 empleados">21 – 50 empleados</option>
            <option value="Más de 50">Más de 50</option>
          </select>
        </div>
      </div>

      <button type="submit" class="submit-btn" id="submitBtn">
        <span id="btnText">Crear mi cuenta gratis</span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <path d="M5 12h14M12 5l7 7-7 7"/>
        </svg>
      </button>
    </form>

    <p class="form-terms">
      Al registrarte aceptas nuestros
      <a href="#">Términos de uso</a> y
      <a href="#">Política de privacidad</a>.
    </p>

    <div class="already-account">
      ¿Ya tienes cuenta? <a href="../Login">Inicia sesión →</a>
    </div>

    <?php endif; ?>

  </div>
</div>

<script src="../js/shared.js"></script>
<script>
// Remover borde rojo al escribir
document.querySelectorAll('.form-input').forEach(el => {
    el.addEventListener('input', () => {
        el.style.borderColor = '';
        el.style.boxShadow = '';
    });
});

// Validación en cliente antes de enviar
document.getElementById('registroForm')?.addEventListener('submit', function(e) {
    const required = ['nombre_contacto', 'apellido_contacto', 'nombre_empresa', 'email', 'telefono', 'giro_comercial', 'cantidad_empleados'];
    let valid = true;
    
    required.forEach(field => {
        const input = this.querySelector(`[name="${field}"]`);
        if (!input.value.trim()) {
            input.style.borderColor = '#ef4444';
            input.style.boxShadow = '0 0 0 3px rgba(239,68,68,0.1)';
            valid = false;
        } else {
            input.style.borderColor = '';
            input.style.boxShadow = '';
        }
    });
    
    if (!valid) {
        e.preventDefault();
        alert('Por favor completa todos los campos obligatorios.');
        return false;
    }
    
    const btn = document.getElementById('submitBtn');
    const txt = document.getElementById('btnText');
    btn.style.opacity = '0.7';
    btn.style.cursor = 'not-allowed';
    txt.textContent = 'Creando tu cuenta...';
    
    return true;
});
</script>
</body>
</html>