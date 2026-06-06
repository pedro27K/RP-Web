<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/api/db.php';

requerirLogin();

$usuario   = usuarioActual();
$error  = '';
$exito  = '';
$tab    = $_GET['tab'] ?? 'viajes';

// ── Procesar formularios POST ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificarCSRF();
    $accion = $_POST['accion'] ?? '';

    // Actualizar datos personales
    if ($accion === 'actualizar') {
        $nombre    = trim($_POST['nombre']    ?? '');
        $apellidos = trim($_POST['apellidos'] ?? '');
        $telefono  = trim($_POST['telefono']  ?? '');

        if (!$nombre) {
            $error = 'El nombre es obligatorio.';
            $tab   = 'datos';
        } else {
            try {
                $db = obtenerBD();
                $db->prepare("UPDATE usuarios SET nombre = ?, apellidos = ?, telefono = ? WHERE id = ?")
                   ->execute([$nombre, $apellidos, $telefono, $usuario['id']]);

                $_SESSION['user_nombre']    = $nombre;
                $_SESSION['user_apellidos'] = $apellidos;
                $usuario = usuarioActual();
                $exito = 'Datos actualizados correctamente.';
                $tab   = 'datos';
            } catch (Exception $e) {
                $error = 'Error al actualizar los datos.';
                $tab   = 'datos';
            }
        }
    }

    // Cancelar reserva
    if ($accion === 'cancelar') {
        $reserva_id = (int)($_POST['reserva_id'] ?? 0);
        try {
            $db   = obtenerBD();
            $stmt = $db->prepare("SELECT id, estado, seguro_cancelacion FROM reservas WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$reserva_id, $usuario['id']]);
            $reserva = $stmt->fetch();

            if (!$reserva) {
                $error = 'Reserva no encontrada.';
            } elseif ($reserva['estado'] === 'cancelada') {
                $error = 'Esta reserva ya está cancelada.';
            } elseif (!$reserva['seguro_cancelacion']) {
                $error = 'Esta reserva no incluye opción de cancelación.';
            } else {
                $db->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id = ?")
                   ->execute([$reserva_id]);
                $exito = 'Reserva cancelada correctamente.';

                // Envío de correo deshabilitado en versión web
            }
        } catch (Exception $e) {
            $error = 'Error al cancelar la reserva.';
        }
        $tab = 'viajes';
    }
}

// ── Cargar datos ───────────────────────────────────────────────
try {
    $db = obtenerBD();

    // Mis reservas
    $stmt = $db->prepare("
        SELECT r.id, r.referencia, r.fecha_salida, r.fecha_regreso,
               r.num_adultos, r.num_ninos, r.precio_total, r.estado,
               r.seguro_cancelacion, r.created_at,
               p.nombre AS paquete_nombre, p.noches, p.regimen,
               d.nombre AS destino_nombre, d.pais,
               co.nombre AS origen_nombre
        FROM reservas r
        JOIN paquetes p         ON p.id  = r.paquete_id
        JOIN destinos d         ON d.id  = p.destino_id
        JOIN ciudades_origen co ON co.id = r.origen_id
        WHERE r.usuario_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$usuario['id']]);
    $reservas = $stmt->fetchAll();

    // Datos del usuario (incluye teléfono)
    $datos_usuario = $db->prepare("SELECT nombre, apellidos, email, telefono FROM usuarios WHERE id = ?");
    $datos_usuario->execute([$usuario['id']]);
    $datos_usuario = $datos_usuario->fetch();

} catch (Exception $e) {
    $reservas      = [];
    $datos_usuario = [];
}

$estado_labels = ['confirmada' => 'Confirmada', 'pendiente' => 'Pendiente', 'cancelada' => 'Cancelada'];
$paginaActiva    = '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mi perfil — RP Travels</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>✈</text></svg>">
  <link rel="stylesheet" href="css/fonts.css">
  <link rel="stylesheet" href="css/variables.css">
  <link rel="stylesheet" href="css/animations.css">
  <link rel="stylesheet" href="css/nav.css">
  <link rel="stylesheet" href="css/hero.css">
  <link rel="stylesheet" href="css/home.css">
  <link rel="stylesheet" href="css/results.css">
  <link rel="stylesheet" href="css/detail.css">
  <link rel="stylesheet" href="css/booking.css">
  <link rel="stylesheet" href="css/confirm.css">
  <link rel="stylesheet" href="css/modal.css">
  <link rel="stylesheet" href="css/profile.css">
  <link rel="stylesheet" href="css/car-rental.css">
  <link rel="stylesheet" href="css/footer.css">
  <style>
    .nav-tab { text-decoration: none; }
    .footer-link { text-decoration: none; }
    body { background: var(--n50, #f9fafb); }
    .alert-success { background:#D1FAE5;color:#065F46;border:1px solid #6EE7B7;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:14px }
    .alert-error   { background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:14px }
  </style>
</head>
<body>

<?php include __DIR__ . '/includes/nav.php'; ?>

<div class="profile-layout">

  <!-- Sidebar -->
  <aside class="profile-sidebar">
    <div class="profile-avatar-wrap">
      <div class="profile-avatar">
        <span><?= strtoupper(substr($usuario['nombre'], 0, 1)) ?></span>
      </div>
    </div>
    <div class="profile-name"><?= htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellidos']) ?></div>
    <div class="profile-email"><?= htmlspecialchars($usuario['email']) ?></div>

    <nav class="profile-sidenav">
      <a class="prof-sidenav-btn <?= $tab === 'viajes' ? 'active' : '' ?>"
         href="perfil.php?tab=viajes" style="text-decoration:none">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17.8 19.2 16 11l3.5-3.5C21 6 21 4 19.5 2.5S18 2 16.5 3.5L13 7 4.8 5.2C4.3 5.1 3.8 5.4 3.5 5.7l-.9.9c-.4.4-.4 1 0 1.4L7 11 5 13H2l-1 2 2 1 1 2 2-1 2-2 3.5 4.5c.4.4 1 .4 1.4 0l.9-.9c.3-.3.6-.8.5-1.3Z"/>
        </svg>
        Mis viajes
      </a>
      <a class="prof-sidenav-btn <?= $tab === 'datos' ? 'active' : '' ?>"
         href="perfil.php?tab=datos" style="text-decoration:none">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
        </svg>
        Mis datos
      </a>
    </nav>

    <a class="btn-logout" href="logout.php" style="text-decoration:none">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      Cerrar sesión
    </a>
  </aside>

  <!-- Contenido -->
  <main class="profile-main">
    <a class="btn-back" href="index.php" style="margin-bottom:28px;display:inline-flex;text-decoration:none">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
      </svg>
      Volver al inicio
    </a>

    <?php if ($exito): ?>
    <div class="alert-success"><?= htmlspecialchars($exito) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($tab === 'viajes'): ?>
    <!-- MIS VIAJES -->
    <div class="prof-section-head">
      <h2 class="prof-section-title">Mis viajes</h2>
      <a class="btn-primary" href="resultados.php" style="padding:10px 20px;font-size:14px;text-decoration:none">
        + Reservar nuevo viaje
      </a>
    </div>

    <?php if (empty($reservas)): ?>
    <div class="prof-empty">
      <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="var(--n300)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M17.8 19.2 16 11l3.5-3.5C21 6 21 4 19.5 2.5S18 2 16.5 3.5L13 7 4.8 5.2C4.3 5.1 3.8 5.4 3.5 5.7l-.9.9c-.4.4-.4 1 0 1.4L7 11 5 13H2l-1 2 2 1 1 2 2-1 2-2 3.5 4.5c.4.4 1 .4 1.4 0l.9-.9c.3-.3.6-.8.5-1.3Z"/>
      </svg>
      <p>Todavía no tienes viajes reservados.</p>
      <a class="btn-primary" href="resultados.php" style="max-width:220px;margin:0 auto;text-decoration:none;text-align:center">Buscar viajes</a>
    </div>
    <?php else: ?>
    <?php foreach ($reservas as $r):
      $estado_label = $estado_labels[$r['estado']] ?? $r['estado'];
      $puede_cancelar = (int)$r['seguro_cancelacion'] === 1 && $r['estado'] !== 'cancelada';
    ?>
    <div class="reserva-card">
      <div class="reserva-card-head">
        <div>
          <div class="reserva-dest"><?= htmlspecialchars($r['destino_nombre']) ?>, <?= htmlspecialchars($r['pais']) ?></div>
          <div class="reserva-meta"><?= htmlspecialchars($r['paquete_nombre']) ?> · <?= (int)$r['noches'] ?> noches · <?= htmlspecialchars($r['regimen']) ?></div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-shrink:0">
          <?php if ($puede_cancelar): ?>
          <span class="reserva-badge-seguro">🛡 Cancelable</span>
          <?php endif; ?>
          <span class="reserva-badge estado-<?= htmlspecialchars($r['estado']) ?>">
            <?= htmlspecialchars($estado_label) ?>
          </span>
        </div>
      </div>
      <div class="reserva-info-row">
        <div class="reserva-info-item">
          <span class="rinfo-label">Referencia</span>
          <span class="rinfo-val"><?= htmlspecialchars($r['referencia']) ?></span>
        </div>
        <div class="reserva-info-item">
          <span class="rinfo-label">Origen</span>
          <span class="rinfo-val"><?= htmlspecialchars($r['origen_nombre']) ?></span>
        </div>
        <div class="reserva-info-item">
          <span class="rinfo-label">Salida</span>
          <span class="rinfo-val"><?= date('d/m/Y', strtotime($r['fecha_salida'])) ?></span>
        </div>
        <div class="reserva-info-item">
          <span class="rinfo-label">Regreso</span>
          <span class="rinfo-val"><?= date('d/m/Y', strtotime($r['fecha_regreso'])) ?></span>
        </div>
        <div class="reserva-info-item">
          <span class="rinfo-label">Viajeros</span>
          <span class="rinfo-val"><?= (int)$r['num_adultos'] ?> adulto<?= $r['num_adultos'] > 1 ? 's' : '' ?></span>
        </div>
        <div class="reserva-info-item">
          <span class="rinfo-label">Total</span>
          <span class="rinfo-val rinfo-price"><?= number_format((float)$r['precio_total'], 0, ',', '.') ?>€</span>
        </div>
      </div>

      <?php if ($puede_cancelar): ?>
      <div class="reserva-actions">
        <form method="POST" onsubmit="return confirm('¿Seguro que quieres cancelar esta reserva?')">
          <input type="hidden" name="csrf"       value="<?= tokenCSRF() ?>">
          <input type="hidden" name="accion"     value="cancelar">
          <input type="hidden" name="reserva_id" value="<?= $r['id'] ?>">
          <button type="submit" class="btn-cancelar-viaje">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px">
              <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
            Cancelar viaje
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php else: ?>
    <!-- MIS DATOS -->
    <h2 class="prof-section-title">Mis datos personales</h2>
    <div class="prof-datos-card">
      <form method="POST">
        <input type="hidden" name="csrf"   value="<?= tokenCSRF() ?>">
        <input type="hidden" name="accion" value="actualizar">

        <div class="form-row cols2" style="margin-bottom:20px">
          <div>
            <label class="form-label">Nombre</label>
            <input class="form-input" type="text" name="nombre" required
              value="<?= htmlspecialchars($_POST['nombre'] ?? ($datos_usuario['nombre'] ?? '')) ?>">
          </div>
          <div>
            <label class="form-label">Apellidos</label>
            <input class="form-input" type="text" name="apellidos"
              value="<?= htmlspecialchars($_POST['apellidos'] ?? ($datos_usuario['apellidos'] ?? '')) ?>">
          </div>
        </div>
        <div class="form-row cols2" style="margin-bottom:20px">
          <div>
            <label class="form-label">Email</label>
            <input class="form-input" type="email" value="<?= htmlspecialchars($usuario['email']) ?>"
              disabled style="opacity:.6;cursor:not-allowed">
          </div>
          <div>
            <label class="form-label">Teléfono</label>
            <input class="form-input" type="tel" name="telefono" placeholder="+34 600 000 000"
              value="<?= htmlspecialchars($_POST['telefono'] ?? ($datos_usuario['telefono'] ?? '')) ?>">
          </div>
        </div>
        <button type="submit" class="btn-primary" style="padding:11px 28px">Guardar cambios</button>
      </form>
    </div>
    <?php endif; ?>

  </main>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>