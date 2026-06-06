<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/destino_assets.php';
require_once __DIR__ . '/api/db.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: resultados.php');
    exit;
}

$error              = '';
$confirmado         = false;
$show_payment       = false;
$referencia         = '';
$precio_total       = 0;
$fecha_salida_conf  = '';
$txn_ref            = '';
$card_ultimos_conf  = '';

try {
    $db   = obtenerBD();
    $stmt = $db->prepare("
        SELECT p.*, d.nombre AS destino_nombre, d.pais
        FROM paquetes p
        JOIN destinos d ON d.id = p.destino_id
        WHERE p.id = :id AND p.activo = 1
    ");
    $stmt->execute([':id' => $id]);
    $paquete = $stmt->fetch();

    if (!$paquete) {
        header('Location: resultados.php');
        exit;
    }

    $origenes = $db->query("SELECT id, nombre, codigo FROM ciudades_origen WHERE activo = 1 ORDER BY nombre")->fetchAll();
    $coches   = $db->query("SELECT id, nombre, categoria, precio_dia, imagen FROM coches WHERE activo = 1 ORDER BY precio_dia ASC")->fetchAll();

} catch (Exception $e) {
    header('Location: resultados.php');
    exit;
}

// ── Procesar formulario ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificarCSRF();
    $fase = $_POST['fase'] ?? 'datos';

    // ── FASE 1: validar datos del viaje ────────────────────────
    if ($fase === 'datos') {

        $email         = trim($_POST['email']       ?? '');
        $telefono      = trim($_POST['telefono']    ?? '');
        $fecha_salida  = $_POST['fecha_salida']  ?? '';
        $fecha_regreso = $_POST['fecha_regreso'] ?? '';
        $origen_id     = (int)($_POST['origen_id'] ?? 1);
        $coche_id      = (int)($_POST['coche_id']  ?? 0);
        $seguro        = isset($_POST['seguro_cancelacion']) ? 1 : 0;
        $num_adultos   = max(1, min(6, (int)($_POST['num_adultos'] ?? 1)));
        $num_ninos     = max(0, min(6, (int)($_POST['num_ninos']   ?? 0)));

        $viajeros_post = [];
        for ($i = 1; $i <= $num_adultos; $i++) {
            $vn  = trim($_POST['v' . $i . '_nombre']    ?? '');
            $va  = trim($_POST['v' . $i . '_apellidos'] ?? '');
            $vd  = trim($_POST['v' . $i . '_documento'] ?? '');
            if (!$vn || !$va || !$vd) {
                $error = 'El nombre, apellidos y documento del Adulto ' . $i . ' son obligatorios.';
                break;
            }
            $viajeros_post[] = [
                'nombre'           => $vn,
                'apellidos'        => $va,
                'documento'        => $vd,
                'fecha_nacimiento' => trim($_POST['v' . $i . '_fn'] ?? '') ?: null,
                'nacionalidad'     => trim($_POST['v' . $i . '_nac'] ?? '') ?: 'Española',
                'tipo'             => 'adulto',
            ];
        }
        if (!$error) {
            for ($i = 1; $i <= $num_ninos; $i++) {
                $vn  = trim($_POST['n' . $i . '_nombre']    ?? '');
                $va  = trim($_POST['n' . $i . '_apellidos'] ?? '');
                $vd  = trim($_POST['n' . $i . '_documento'] ?? '');
                if (!$vn || !$va || !$vd) {
                    $error = 'El nombre, apellidos y documento del Niño ' . $i . ' son obligatorios.';
                    break;
                }
                $viajeros_post[] = [
                    'nombre'           => $vn,
                    'apellidos'        => $va,
                    'documento'        => $vd,
                    'fecha_nacimiento' => trim($_POST['n' . $i . '_fn'] ?? '') ?: null,
                    'nacionalidad'     => trim($_POST['n' . $i . '_nac'] ?? '') ?: 'Española',
                    'tipo'             => 'niño',
                ];
            }
        }

        if (!$error && (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $error = 'Introduce un email de contacto válido.';
        }
        if (!$error && (!$fecha_salida || !$fecha_regreso)) {
            $error = 'Las fechas de salida y regreso son obligatorias.';
        }
        if (!$error && $fecha_salida < date('Y-m-d')) {
            $error = 'La fecha de salida no puede ser anterior a hoy.';
        }
        if (!$error && $fecha_regreso <= $fecha_salida) {
            $error = 'La fecha de regreso debe ser posterior a la de salida.';
        }
        if (!$error) {
            $noches_sel = (int)round((strtotime($fecha_regreso) - strtotime($fecha_salida)) / 86400);
            if ($noches_sel !== (int)$paquete['noches']) {
                $error = 'Las fechas seleccionadas cubren ' . $noches_sel . ' noche' . ($noches_sel !== 1 ? 's' : '') .
                         ', pero este paquete es de ' . $paquete['noches'] . ' noches. Por favor, ajusta las fechas.';
            }
        }

        if (!$error) {
            // Pre-calcular precio total y guardar en sesión para el paso de pago
            $precio_pp    = (float)$paquete['precio_persona'];
            $precio_total = $precio_pp * ($num_adultos + $num_ninos);
            if ($seguro) $precio_total = round($precio_total * 1.10, 2);

            $precio_coche = 0.0;
            $coche_nombre = null;
            if ($coche_id > 0) {
                foreach ($coches as $c) {
                    if ((int)$c['id'] === $coche_id) {
                        $noches_viaje = (strtotime($fecha_regreso) - strtotime($fecha_salida)) / 86400;
                        $precio_coche = round((float)$c['precio_dia'] * $noches_viaje, 2);
                        $coche_nombre = $c['nombre'];
                        break;
                    }
                }
            }
            $precio_total = round($precio_total + $precio_coche, 2);

            $_SESSION['pending_booking'] = [
                'email'         => $email,
                'telefono'      => $telefono,
                'fecha_salida'  => $fecha_salida,
                'fecha_regreso' => $fecha_regreso,
                'origen_id'     => $origen_id,
                'coche_id'      => $coche_id,
                'seguro'        => $seguro,
                'num_adultos'   => $num_adultos,
                'num_ninos'     => $num_ninos,
                'viajeros'      => $viajeros_post,
                'precio_total'  => $precio_total,
                'precio_coche'  => $precio_coche,
                'coche_nombre'  => $coche_nombre,
                'paquete_id'    => $id,
            ];
            $show_payment = true;
        }

    // ── FASE 2: procesar pago simulado y guardar reserva ──────
    } elseif ($fase === 'pago') {

        $card_titular   = trim($_POST['card_titular']   ?? '');
        $card_numero    = preg_replace('/[\s\-]/', '', $_POST['card_numero'] ?? '');
        $card_caducidad = trim($_POST['card_caducidad'] ?? '');
        $card_cvv       = trim($_POST['card_cvv']       ?? '');

        if (!$card_titular) {
            $error = 'El nombre del titular de la tarjeta es obligatorio.';
        } elseif (!preg_match('/^\d{16}$/', $card_numero)) {
            $error = 'El número de tarjeta debe contener 16 dígitos.';
        } elseif (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $card_caducidad)) {
            $error = 'La fecha de caducidad debe tener el formato MM/AA.';
        } elseif (!preg_match('/^\d{3,4}$/', $card_cvv)) {
            $error = 'El CVV debe tener 3 o 4 dígitos.';
        }

        if (!$error && empty($_SESSION['pending_booking'])) {
            $error = 'La sesión ha expirado. Por favor, inicia la reserva de nuevo.';
        }

        if (!$error && (int)($_SESSION['pending_booking']['paquete_id'] ?? 0) !== $id) {
            $error = 'Error de sesión. Por favor, inicia la reserva de nuevo.';
        }

        if (!$error) {
            $txn_id       = 'TXN' . strtoupper(bin2hex(random_bytes(5)));
            $card_ultimos = substr($card_numero, -4);
            $d = $_SESSION['pending_booking'];

            try {
                $precio_total = (float)$d['precio_total'];
                $precio_coche = (float)$d['precio_coche'];
                $coche_nombre = $d['coche_nombre'];
                $referencia   = 'RP' . strtoupper(substr(uniqid(), -8));
                $usuario_id   = estaLogueado() ? (int)$_SESSION['user_id'] : null;

                $db->beginTransaction();

                $stmt = $db->prepare("
                    INSERT INTO reservas (referencia, usuario_id, paquete_id, origen_id, fecha_salida, fecha_regreso,
                                          num_adultos, num_ninos, precio_total, seguro_cancelacion, coche_id, precio_coche, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmada')
                ");
                $stmt->execute([
                    $referencia, $usuario_id, $id, (int)$d['origen_id'],
                    $d['fecha_salida'], $d['fecha_regreso'], (int)$d['num_adultos'], (int)$d['num_ninos'],
                    $precio_total, (int)$d['seguro'], $d['coche_id'] ?: null, $precio_coche,
                ]);
                $reserva_id = (int)$db->lastInsertId();

                $stmtV = $db->prepare("
                    INSERT INTO viajeros (reserva_id, nombre, apellidos, documento, fecha_nacimiento, nacionalidad, tipo)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($d['viajeros'] as $v) {
                    $stmtV->execute([
                        $reserva_id,
                        substr($v['nombre'], 0, 100),
                        substr($v['apellidos'], 0, 150),
                        substr($v['documento'], 0, 30),
                        $v['fecha_nacimiento'],
                        substr($v['nacionalidad'], 0, 80),
                        $v['tipo'],
                    ]);
                }

                $db->prepare("
                    INSERT INTO contactos_reserva (reserva_id, telefono, email)
                    VALUES (?, ?, ?)
                ")->execute([$reserva_id, substr($d['telefono'], 0, 30), substr($d['email'], 0, 150)]);

                $db->commit();

                try {
                    $db->prepare("
                        INSERT INTO pagos (reserva_id, transaccion_id, titular, ultimos_digitos, importe)
                        VALUES (?, ?, ?, ?, ?)
                    ")->execute([$reserva_id, $txn_id, substr($card_titular, 0, 200), $card_ultimos, $precio_total]);
                } catch (Exception $e) {
                    error_log('[RP pagos] ' . $e->getMessage());
                }

                $confirmado        = true;
                $txn_ref           = $txn_id;
                $card_ultimos_conf = $card_ultimos;
                $fecha_salida_conf = $d['fecha_salida'];
                unset($_SESSION['pending_booking']);

                // Envío de correo deshabilitado en versión web

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Error al guardar la reserva. Por favor, inténtalo de nuevo.';
            }
        }

        if ($error) {
            $show_payment = true;
        }
    }
}

$precio    = (float)$paquete['precio_persona'];
$noches    = (int)$paquete['noches'];
$paginaActiva = '';
$usuario = usuarioActual();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reservar — <?= htmlspecialchars($paquete['destino_nombre']) ?> · RP Travels</title>
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
    .form-notice { background:#FEF9C3;border:1px solid #FDE68A;color:#92400E;border-radius:8px;padding:12px 16px;font-size:14px;margin-bottom:20px;display:flex;gap:10px;align-items:flex-start }
    .booking-error-msg { background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;border-radius:8px;padding:12px 16px;font-size:14px; }
    .req { color: #e11d48; }
    .payment-demo-badge {
      display:inline-flex;align-items:center;gap:6px;
      background:#FEF9C3;border:1px solid #FDE68A;color:#92400E;
      font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;
      letter-spacing:0.5px;margin-bottom:20px;
    }
  </style>
</head>
<body>

<?php include __DIR__ . '/includes/nav.php'; ?>

<div class="booking-form-layout">

  <?php if ($confirmado): ?>
  <!-- ── CONFIRMACIÓN ───────────────────────────────── -->
  <div class="confirm-screen">
    <div class="confirm-icon">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#16A34A" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
    </div>
    <h1 class="confirm-title">¡Reserva confirmada!</h1>
    <div style="display:inline-flex;align-items:center;gap:6px;background:#dcfce7;border:1px solid #86efac;color:#15803d;font-size:12px;font-weight:700;padding:5px 14px;border-radius:20px;margin-bottom:16px">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      Pago aprobado
    </div>
    <p class="confirm-ref">Tu referencia de reserva es:</p>
    <p class="confirm-ref"><strong><?= htmlspecialchars($referencia) ?></strong></p>
    <?php if ($txn_ref): ?>
    <p style="font-size:13px;color:var(--n400);margin-bottom:20px">
      ID de transacción: <code style="font-family:monospace;background:#f1f5f9;padding:2px 6px;border-radius:4px"><?= htmlspecialchars($txn_ref) ?></code>
      <?php if ($card_ultimos_conf): ?> · Tarjeta terminada en <?= htmlspecialchars($card_ultimos_conf) ?><?php endif; ?>
    </p>
    <?php endif; ?>
    <div class="confirm-card">
      <div class="summary-rows">
        <div class="summary-row"><span class="sk">Destino</span><span class="sv"><?= htmlspecialchars($paquete['destino_nombre']) ?>, <?= htmlspecialchars($paquete['pais']) ?></span></div>
        <div class="summary-row"><span class="sk">Régimen</span><span class="sv"><?= htmlspecialchars($paquete['regimen']) ?></span></div>
        <div class="summary-row"><span class="sk">Duración</span><span class="sv"><?= $noches ?> noches</span></div>
        <?php if ($fecha_salida_conf): ?><div class="summary-row"><span class="sk">Salida</span><span class="sv"><?= date('d/m/Y', strtotime($fecha_salida_conf)) ?></span></div><?php endif; ?>
      </div>
      <div class="summary-total" style="margin-top:12px">
        <span>Total pagado</span>
        <span class="st-val"><?= number_format($precio_total, 0, ',', '.') ?>€</span>
      </div>
    </div>
    <p style="font-size:14px;color:var(--n500);margin-bottom:24px">
      Tu pago ha sido procesado correctamente. Recibirás un email de confirmación con todos los detalles de tu reserva.
    </p>
    <a class="btn-primary" href="index.php" style="max-width:300px;margin:0 auto;display:block;text-align:center;text-decoration:none">
      Volver al inicio
    </a>
  </div>

  <?php elseif ($show_payment):
    $pb    = $_SESSION['pending_booking'] ?? [];
    $pt    = (float)($pb['precio_total'] ?? 0);
    $ptFmt = number_format($pt, 0, ',', '.');
  ?>
  <!-- ── PASO 2: PAGO SIMULADO ─────────────────────── -->
  <a class="btn-back" href="reserva.php?id=<?= $id ?>">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
      <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
    </svg>
    Volver a los datos del viaje
  </a>

  <!-- Barra de progreso -->
  <div class="steps-bar" style="margin-bottom:32px">
    <div class="step-item">
      <div class="step-circle done">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <span class="step-label">Datos del viaje</span>
      <div class="step-line"></div>
    </div>
    <div class="step-item">
      <div class="step-circle active">2</div>
      <span class="step-label active">Pago</span>
      <div class="step-line"></div>
    </div>
    <div class="step-item">
      <div class="step-circle pending">3</div>
      <span class="step-label">Confirmación</span>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 340px;gap:36px">

    <form method="POST">
      <input type="hidden" name="csrf" value="<?= tokenCSRF() ?>">
      <input type="hidden" name="fase" value="pago">

      <div class="form-notice">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <span><strong>Modo demo:</strong> Pasarela de pago simulada. No se realizará ningún cargo real en tu tarjeta.</span>
      </div>

      <div class="form-panel">
        <h2>Datos de pago</h2>

        <div style="display:flex;gap:8px;margin-bottom:24px;align-items:center">
          <span style="background:#1a1f71;color:white;font-size:11px;font-weight:800;padding:5px 12px;border-radius:4px;letter-spacing:1px">VISA</span>
          <span style="background:#eb001b;color:white;font-size:11px;font-weight:800;padding:5px 10px;border-radius:4px;letter-spacing:0.5px">MC</span>
          <span style="background:#2e77bc;color:white;font-size:11px;font-weight:800;padding:5px 10px;border-radius:4px;letter-spacing:0.5px">AMEX</span>
          <span style="font-size:12px;color:var(--n400);margin-left:4px">y más</span>
        </div>

        <div style="margin-bottom:20px">
          <label class="form-label">Titular de la tarjeta <span class="req">*</span></label>
          <input class="form-input" name="card_titular" placeholder="Nombre y apellidos como aparece en la tarjeta"
            required value="<?= htmlspecialchars($_POST['card_titular'] ?? '') ?>">
        </div>

        <div style="margin-bottom:20px;position:relative">
          <label class="form-label">Número de tarjeta <span class="req">*</span></label>
          <input class="form-input" name="card_numero" id="card-numero"
            placeholder="0000 0000 0000 0000" maxlength="19"
            required autocomplete="cc-number"
            value="<?= htmlspecialchars($_POST['card_numero'] ?? '') ?>"
            style="padding-right:46px">
          <svg style="position:absolute;right:14px;bottom:10px;opacity:0.3;pointer-events:none" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>
          </svg>
        </div>

        <div class="form-row cols2" style="margin-bottom:20px">
          <div>
            <label class="form-label">Fecha de caducidad <span class="req">*</span></label>
            <input class="form-input" name="card_caducidad" id="card-caducidad"
              placeholder="MM/AA" maxlength="5"
              required autocomplete="cc-exp"
              value="<?= htmlspecialchars($_POST['card_caducidad'] ?? '') ?>">
          </div>
          <div>
            <label class="form-label">CVV <span class="req">*</span></label>
            <input class="form-input" name="card_cvv" type="password"
              placeholder="•••" maxlength="4"
              required autocomplete="cc-csc">
          </div>
        </div>

        <?php if ($error): ?>
        <div class="booking-error-msg" style="margin:0 0 16px"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="ssl-notice">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0057B8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
          <span>Conexión segura SSL · Los datos de tu tarjeta están cifrados y no se almacenan</span>
        </div>

        <div class="form-actions" style="margin-top:24px">
          <button type="submit" class="btn-form-pay" style="font-size:16px;letter-spacing:0.3px">
            Pagar <?= $ptFmt ?>€ →
          </button>
        </div>
      </div>
    </form>

    <!-- Resumen del pago -->
    <div>
      <div class="summary-panel">
        <h3>Resumen del pago</h3>
        <?php $sumImgSrc = imagenDestino((int)$paquete['destino_id'], $paquete['imagen_url'], $IMGS_DESTINOS); ?>
        <?php if ($sumImgSrc): ?>
        <div class="summary-img" style="background-image:url('<?= htmlspecialchars($sumImgSrc) ?>');background-size:cover;background-position:center"></div>
        <?php else: ?>
        <div class="summary-img grad-default"></div>
        <?php endif; ?>
        <div class="summary-dest-name"><?= htmlspecialchars($paquete['destino_nombre']) ?></div>
        <div class="summary-dest-meta"><?= $noches ?> noches · <?= htmlspecialchars($paquete['regimen']) ?></div>
        <div class="summary-rows">
          <?php if (!empty($pb)): ?>
          <div class="summary-row">
            <span class="sk">Viajeros</span>
            <span class="sv">
              <?= (int)$pb['num_adultos'] ?> adulto<?= $pb['num_adultos'] > 1 ? 's' : '' ?>
              <?php if (!empty($pb['num_ninos']) && $pb['num_ninos'] > 0): ?>
              , <?= (int)$pb['num_ninos'] ?> niño<?= $pb['num_ninos'] > 1 ? 's' : '' ?>
              <?php endif; ?>
            </span>
          </div>
          <div class="summary-row">
            <span class="sk">Salida</span>
            <span class="sv"><?= date('d/m/Y', strtotime($pb['fecha_salida'])) ?></span>
          </div>
          <div class="summary-row">
            <span class="sk">Regreso</span>
            <span class="sv"><?= date('d/m/Y', strtotime($pb['fecha_regreso'])) ?></span>
          </div>
          <?php if ($pb['seguro']): ?>
          <div class="summary-row">
            <span class="sk">Seguro cancelación</span>
            <span class="sv" style="color:#16a34a">Incluido (+10%)</span>
          </div>
          <?php endif; ?>
          <?php if ($pb['coche_nombre']): ?>
          <div class="summary-row">
            <span class="sk">Vehículo</span>
            <span class="sv"><?= htmlspecialchars($pb['coche_nombre']) ?></span>
          </div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="summary-total" style="margin-top:12px">
          <span>Total a pagar</span>
          <span class="st-val"><?= $ptFmt ?>€</span>
        </div>
      </div>
    </div>

  </div>

  <?php else: ?>
  <!-- ── PASO 1: FORMULARIO DE RESERVA ─────────────────────── -->
  <a class="btn-back" href="paquete.php?id=<?= $id ?>">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
      <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
    </svg>
    Volver al detalle
  </a>

  <!-- Barra de progreso -->
  <div class="steps-bar" style="margin-bottom:32px">
    <div class="step-item">
      <div class="step-circle active">1</div>
      <span class="step-label active">Datos del viaje</span>
      <div class="step-line"></div>
    </div>
    <div class="step-item">
      <div class="step-circle pending">2</div>
      <span class="step-label">Pago</span>
      <div class="step-line"></div>
    </div>
    <div class="step-item">
      <div class="step-circle pending">3</div>
      <span class="step-label">Confirmación</span>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 340px;gap:36px">

    <!-- Formulario -->
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= tokenCSRF() ?>">
      <input type="hidden" name="fase" value="datos">

      <?php
        $numAdultosActual = max(1, min(6, (int)($_POST['num_adultos'] ?? 1)));
        $numNinosActual   = max(0, min(6, (int)($_POST['num_ninos']   ?? 0)));
      ?>
      <div class="form-panel">
        <h2>Datos de los viajeros</h2>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px">
          <div>
            <label class="form-label">Número de adultos</label>
            <select class="form-input" name="num_adultos" id="num-adultos"
                    onchange="actualizarPasajeros()" style="max-width:160px">
              <?php for ($i = 1; $i <= 6; $i++): ?>
              <option value="<?= $i ?>" <?= $numAdultosActual === $i ? 'selected' : '' ?>>
                <?= $i ?> adulto<?= $i > 1 ? 's' : '' ?>
              </option>
              <?php endfor; ?>
            </select>
          </div>
          <div>
            <label class="form-label">Número de niños</label>
            <select class="form-input" name="num_ninos" id="num-ninos"
                    onchange="actualizarPasajeros()" style="max-width:160px">
              <?php for ($i = 0; $i <= 6; $i++): ?>
              <option value="<?= $i ?>" <?= $numNinosActual === $i ? 'selected' : '' ?>>
                <?= $i ?> niño<?= $i !== 1 ? 's' : '' ?>
              </option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <?php for ($i = 1; $i <= 6; $i++):
          $vis = $i <= $numAdultosActual; ?>
        <div class="traveler-block" id="pasajero-block-<?= $i ?>"<?= $vis ? '' : ' style="display:none"' ?>>
          <div class="traveler-title">Adulto <?= $i ?></div>
          <div class="form-row cols2">
            <div>
              <label class="form-label">Nombre <span class="req">*</span></label>
              <input class="form-input" name="v<?= $i ?>_nombre" placeholder="Nombre"
                data-req="1" <?= $vis ? 'required' : 'disabled' ?>
                value="<?= htmlspecialchars($_POST['v' . $i . '_nombre'] ?? '') ?>">
            </div>
            <div>
              <label class="form-label">Apellidos <span class="req">*</span></label>
              <input class="form-input" name="v<?= $i ?>_apellidos" placeholder="Apellidos"
                data-req="1" <?= $vis ? 'required' : 'disabled' ?>
                value="<?= htmlspecialchars($_POST['v' . $i . '_apellidos'] ?? '') ?>">
            </div>
          </div>
          <div class="form-row cols3">
            <div>
              <label class="form-label">Pasaporte / DNI <span class="req">*</span></label>
              <input class="form-input" name="v<?= $i ?>_documento" placeholder="Nº documento"
                data-req="1" <?= $vis ? 'required' : 'disabled' ?>
                value="<?= htmlspecialchars($_POST['v' . $i . '_documento'] ?? '') ?>">
            </div>
            <div>
              <label class="form-label">Fecha de nacimiento</label>
              <input class="form-input" type="date" name="v<?= $i ?>_fn"
                <?= $vis ? '' : 'disabled' ?>
                value="<?= htmlspecialchars($_POST['v' . $i . '_fn'] ?? '') ?>">
            </div>
            <div>
              <label class="form-label">Nacionalidad</label>
              <select class="form-input" name="v<?= $i ?>_nac" <?= $vis ? '' : 'disabled' ?>>
                <option value="Española"  <?= ($_POST['v' . $i . '_nac'] ?? 'Española') === 'Española'  ? 'selected' : '' ?>>Española</option>
                <option value="Extranjera"<?= ($_POST['v' . $i . '_nac'] ?? '') === 'Extranjera' ? 'selected' : '' ?>>Extranjera</option>
              </select>
            </div>
          </div>
        </div>
        <?php endfor; ?>

        <?php for ($i = 1; $i <= 6; $i++):
          $vis = $i <= $numNinosActual; ?>
        <div class="traveler-block" id="nino-block-<?= $i ?>"<?= $vis ? '' : ' style="display:none"' ?>>
          <div class="traveler-title">Niño <?= $i ?></div>
          <div class="form-row cols2">
            <div>
              <label class="form-label">Nombre <span class="req">*</span></label>
              <input class="form-input" name="n<?= $i ?>_nombre" placeholder="Nombre"
                data-req="1" <?= $vis ? 'required' : 'disabled' ?>
                value="<?= htmlspecialchars($_POST['n' . $i . '_nombre'] ?? '') ?>">
            </div>
            <div>
              <label class="form-label">Apellidos <span class="req">*</span></label>
              <input class="form-input" name="n<?= $i ?>_apellidos" placeholder="Apellidos"
                data-req="1" <?= $vis ? 'required' : 'disabled' ?>
                value="<?= htmlspecialchars($_POST['n' . $i . '_apellidos'] ?? '') ?>">
            </div>
          </div>
          <div class="form-row cols3">
            <div>
              <label class="form-label">Pasaporte / DNI <span class="req">*</span></label>
              <input class="form-input" name="n<?= $i ?>_documento" placeholder="Nº documento"
                data-req="1" <?= $vis ? 'required' : 'disabled' ?>
                value="<?= htmlspecialchars($_POST['n' . $i . '_documento'] ?? '') ?>">
            </div>
            <div>
              <label class="form-label">Fecha de nacimiento</label>
              <input class="form-input" type="date" name="n<?= $i ?>_fn"
                <?= $vis ? '' : 'disabled' ?>
                value="<?= htmlspecialchars($_POST['n' . $i . '_fn'] ?? '') ?>">
            </div>
            <div>
              <label class="form-label">Nacionalidad</label>
              <select class="form-input" name="n<?= $i ?>_nac" <?= $vis ? '' : 'disabled' ?>>
                <option value="Española"  <?= ($_POST['n' . $i . '_nac'] ?? 'Española') === 'Española'  ? 'selected' : '' ?>>Española</option>
                <option value="Extranjera"<?= ($_POST['n' . $i . '_nac'] ?? '') === 'Extranjera' ? 'selected' : '' ?>>Extranjera</option>
              </select>
            </div>
          </div>
        </div>
        <?php endfor; ?>
      </div>

      <div class="form-panel" style="margin-top:24px">
        <h2>Fechas y origen</h2>
        <div class="form-row cols2">
          <div>
            <label class="form-label">Fecha de salida <span class="req">*</span></label>
            <input class="form-input" type="date" name="fecha_salida" required
              min="<?= date('Y-m-d') ?>"
              value="<?= htmlspecialchars($_POST['fecha_salida'] ?? date('Y-m-d', strtotime('+7 days'))) ?>">
          </div>
          <div>
            <label class="form-label">Fecha de regreso <span class="req">*</span></label>
            <input class="form-input" type="date" name="fecha_regreso" required
              min="<?= date('Y-m-d', strtotime('+8 days')) ?>"
              value="<?= htmlspecialchars($_POST['fecha_regreso'] ?? date('Y-m-d', strtotime('+' . ($noches + 7) . ' days'))) ?>">
          </div>
        </div>
        <div>
          <label class="form-label">Ciudad de origen</label>
          <select class="form-input" name="origen_id">
            <?php foreach ($origenes as $o): ?>
            <option value="<?= $o['id'] ?>" <?= (int)($_POST['origen_id'] ?? 1) === (int)$o['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($o['nombre']) ?> (<?= htmlspecialchars($o['codigo']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-panel" style="margin-top:24px">
        <h2>Datos de contacto</h2>
        <div class="form-row cols2">
          <div>
            <label class="form-label">Teléfono</label>
            <input class="form-input" type="tel" name="telefono" placeholder="+34 600 000 000"
              value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>">
          </div>
          <div>
            <label class="form-label">Email <span class="req">*</span></label>
            <input class="form-input" type="email" name="email" placeholder="tu@email.com" required
              value="<?= htmlspecialchars($_POST['email'] ?? ($usuario['email'] ?? '')) ?>">
          </div>
        </div>
      </div>

      <?php if (!empty($coches)): ?>
      <div class="form-panel" style="margin-top:24px">
        <h2>¿Necesitas un vehículo?</h2>
        <p style="font-size:14px;color:var(--n500);margin:4px 0 20px">Alquiler incluido durante toda la estancia · <?= $noches ?> noches</p>
        <div class="car-grid">
          <label class="car-card <?= (int)($_POST['coche_id'] ?? 0) === 0 ? 'selected' : '' ?>">
            <input type="radio" name="coche_id" value="0" <?= (int)($_POST['coche_id'] ?? 0) === 0 ? 'checked' : '' ?> onchange="selectCar(this)">
            <div class="car-card-img car-card-none">
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--n400)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </div>
            <div class="car-card-body">
              <div class="car-name">Sin vehículo</div>
              <div class="car-cat">—</div>
              <div class="car-price">0€</div>
            </div>
          </label>
          <?php foreach ($coches as $c):
            $total_c  = round((float)$c['precio_dia'] * $noches, 0);
            $selected = (int)($_POST['coche_id'] ?? 0) === (int)$c['id'];
          ?>
          <label class="car-card <?= $selected ? 'selected' : '' ?>">
            <input type="radio" name="coche_id" value="<?= $c['id'] ?>" <?= $selected ? 'checked' : '' ?> onchange="selectCar(this)">
            <div class="car-card-img">
              <img src="assets/<?= htmlspecialchars($c['imagen']) ?>" alt="<?= htmlspecialchars($c['nombre']) ?>">
            </div>
            <div class="car-card-body">
              <div class="car-name"><?= htmlspecialchars($c['nombre']) ?></div>
              <div class="car-cat"><?= htmlspecialchars($c['categoria']) ?></div>
              <div class="car-price"><?= number_format($total_c, 0, ',', '.') ?>€ <span class="car-price-sub">(<?= (int)$c['precio_dia'] ?>€/noche)</span></div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="form-panel" style="margin-top:24px">
        <h2>Opciones adicionales</h2>
        <div class="cancel-option-box">
          <label class="cancel-option-label">
            <input type="checkbox" name="seguro_cancelacion" <?= !empty($_POST['seguro_cancelacion']) ? 'checked' : '' ?>>
            <div class="cancel-option-content">
              <div class="cancel-option-title">Añadir opción de cancelación</div>
              <div class="cancel-option-desc">Cancela tu viaje antes de la salida y recibe un reembolso completo. <strong>+10%</strong> sobre el precio total.</div>
            </div>
          </label>
        </div>

        <?php if ($error): ?>
        <div class="booking-error-msg" style="margin:0 0 16px"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="form-actions" style="margin-top:20px">
          <button type="submit" class="btn-form-pay">
            Continuar al pago →
          </button>
        </div>
      </div>
    </form>

    <!-- Resumen del paquete -->
    <div>
      <div class="summary-panel">
        <h3>Resumen de la reserva</h3>
        <?php $sumImgSrc = imagenDestino((int)$paquete['destino_id'], $paquete['imagen_url'], $IMGS_DESTINOS); ?>
        <?php if ($sumImgSrc): ?>
        <div class="summary-img" style="background-image:url('<?= htmlspecialchars($sumImgSrc) ?>');background-size:cover;background-position:center"></div>
        <?php else: ?>
        <div class="summary-img grad-default"></div>
        <?php endif; ?>
        <div class="summary-dest-name"><?= htmlspecialchars($paquete['destino_nombre']) ?></div>
        <div class="summary-dest-meta"><?= $noches ?> noches · <?= htmlspecialchars($paquete['regimen']) ?></div>
        <div class="summary-rows">
          <div class="summary-row"><span class="sk">Precio p.p.</span><span class="sv"><?= number_format($precio, 0, ',', '.') ?>€</span></div>
          <div class="summary-row"><span class="sk">Tasas</span><span class="sv">Incluidas</span></div>
        </div>
        <div class="summary-total">
          <span>Total estimado</span>
          <span class="st-val"><?= number_format($precio, 0, ',', '.') ?>€</span>
        </div>
        <p style="font-size:12px;color:var(--n400);margin-top:8px">El precio final se calcula al confirmar.</p>
      </div>
    </div>

  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
/* ── Mostrar/ocultar bloques de pasajeros ── */
function toggleBlock(block, visible) {
  block.style.display = visible ? '' : 'none';
  block.querySelectorAll('input, select').forEach(function(el) {
    if (visible) {
      el.removeAttribute('disabled');
      if (el.dataset.req) el.setAttribute('required', '');
    } else {
      el.removeAttribute('required');
      el.setAttribute('disabled', '');
    }
  });
}

function actualizarPasajeros() {
  var nAdultos = parseInt(document.getElementById('num-adultos').value) || 1;
  var nNinos   = parseInt(document.getElementById('num-ninos').value)   || 0;
  for (var i = 1; i <= 6; i++) {
    var bAdulto = document.getElementById('pasajero-block-' + i);
    if (bAdulto) toggleBlock(bAdulto, i <= nAdultos);
    var bNino = document.getElementById('nino-block-' + i);
    if (bNino) toggleBlock(bNino, i <= nNinos);
  }
}

/* ── Validación visual de fechas ── */
(function () {
  var NOCHES = <?= (int)$paquete['noches'] ?>;
  var salida  = document.querySelector('input[name="fecha_salida"]');
  var regreso = document.querySelector('input[name="fecha_regreso"]');
  if (!salida || !regreso) return;

  var aviso = document.createElement('p');
  aviso.style.cssText = 'color:#c0392b;font-size:13px;margin:6px 0 0;display:none';
  regreso.parentNode.appendChild(aviso);

  function validarFechas() {
    if (!salida.value || !regreso.value) { aviso.style.display = 'none'; return; }
    var diff = Math.round((new Date(regreso.value) - new Date(salida.value)) / 86400000);
    if (diff !== NOCHES) {
      aviso.textContent = 'Las fechas cubren ' + diff + ' noche' + (diff !== 1 ? 's' : '') +
        ', pero este paquete es de ' + NOCHES + ' noches.';
      aviso.style.display = 'block';
    } else {
      aviso.style.display = 'none';
    }
  }

  salida.addEventListener('change', validarFechas);
  regreso.addEventListener('change', validarFechas);
  validarFechas();
})();

/* ── Formateo automático del número de tarjeta (grupos de 4) ── */
(function () {
  var cardInput = document.getElementById('card-numero');
  if (!cardInput) return;
  cardInput.addEventListener('input', function () {
    var v = this.value.replace(/\D/g, '').substring(0, 16);
    var groups = v.match(/.{1,4}/g);
    this.value = groups ? groups.join(' ') : '';
  });
})();

/* ── Formateo automático de la caducidad (MM/AA) ── */
(function () {
  var cadInput = document.getElementById('card-caducidad');
  if (!cadInput) return;
  cadInput.addEventListener('input', function () {
    var v = this.value.replace(/\D/g, '').substring(0, 4);
    this.value = v.length >= 3 ? v.substring(0, 2) + '/' + v.substring(2) : v;
  });
})();

function selectCar(radio) {
  document.querySelectorAll('.car-card').forEach(c => c.classList.remove('selected'));
  radio.closest('.car-card').classList.add('selected');
}
</script>
</body>
</html>