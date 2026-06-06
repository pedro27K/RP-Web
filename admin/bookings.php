<?php
require_once 'auth-check.php';
require_once '../api/db.php';

$db = obtenerBD();

// Estado reserva
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reserva_id'], $_POST['nuevo_estado'])) {
    $allowed   = ['pendiente', 'confirmada', 'cancelada'];
    $nuevo     = $_POST['nuevo_estado'];
    $reservaId = (int)$_POST['reserva_id'];
    if (in_array($nuevo, $allowed, true)) {
        $stmtUpd = $db->prepare('UPDATE reservas SET estado = ? WHERE id = ?');
        $stmtUpd->execute([$nuevo, $reservaId]);

    }
    header('Location: bookings.php' . (isset($_GET['estado']) ? '?estado=' . urlencode($_GET['estado']) : ''));
    exit;
}

// Filtros
$filtro_estado = $_GET['estado'] ?? '';
$buscar        = trim($_GET['q'] ?? '');

$where  = [];
$params = [];

if ($filtro_estado && in_array($filtro_estado, ['pendiente', 'confirmada', 'cancelada'], true)) {
    $where[]  = 'r.estado = ?';
    $params[] = $filtro_estado;
}

if ($buscar !== '') {
    $like     = '%' . $buscar . '%';
    $where[]  = '(u.nombre LIKE ? OR u.apellidos LIKE ? OR u.email LIKE ? OR c.email LIKE ? OR p.nombre LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Paginatcion
$per_page    = 15;
$page        = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page - 1) * $per_page;

$countStmt = $db->prepare("
    SELECT COUNT(*)
    FROM reservas r
    LEFT JOIN usuarios u ON u.id = r.usuario_id
    LEFT JOIN contactos_reserva c ON c.reserva_id = r.id
    JOIN paquetes p ON p.id = r.paquete_id
    $whereSql
");
$countStmt->execute($params);
$total   = (int)$countStmt->fetchColumn();
$pages   = max(1, (int)ceil($total / $per_page));

$stmt = $db->prepare("
    SELECT r.id, r.referencia, r.estado, r.precio_total, r.created_at, r.fecha_salida, r.fecha_regreso,
           (r.num_adultos + r.num_ninos) AS num_viajeros,
           p.nombre AS paquete, p.tipo,
           d.nombre AS destino,
           COALESCE(u.nombre, '') AS cli_nombre,
           COALESCE(u.apellidos, '') AS cli_apellidos,
           COALESCE(u.email, c.email, '') AS cli_email,
           COALESCE(u.telefono, c.telefono, '') AS cli_tel
    FROM reservas r
    JOIN paquetes p ON p.id = r.paquete_id
    LEFT JOIN destinos d ON d.id = p.destino_id
    LEFT JOIN usuarios u ON u.id = r.usuario_id
    LEFT JOIN contactos_reserva c ON c.reserva_id = r.id
    $whereSql
    ORDER BY r.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$reservas = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reservas — RP Travels Admin</title>
  <link rel="stylesheet" href="../css/fonts.css">
  <link rel="stylesheet" href="css/admin.css">
</head>
<body>
<div class="admin-wrap">
  <?php include 'partials/sidebar.php'; ?>

  <div class="admin-main">
    <div class="admin-topbar">
      <h1>Reservas</h1>
      <div class="admin-user">
        <div class="admin-user-avatar"><?= strtoupper(substr($_SESSION['admin']['nombre'], 0, 1)) ?></div>
        <?= htmlspecialchars($_SESSION['admin']['nombre']) ?>
      </div>
    </div>

    <div class="admin-content">
      <div class="admin-card">

        <!-- Filtros -->
        <form method="GET" class="filter-bar">
          <select name="estado" onchange="this.form.submit()">
            <option value="">Todos los estados</option>
            <option value="pendiente"  <?= $filtro_estado==='pendiente'  ? 'selected' : '' ?>>Pendiente</option>
            <option value="confirmada" <?= $filtro_estado==='confirmada' ? 'selected' : '' ?>>Confirmada</option>
            <option value="cancelada"  <?= $filtro_estado==='cancelada'  ? 'selected' : '' ?>>Cancelada</option>
          </select>
          <input type="text" name="q" placeholder="Buscar cliente o paquete…" value="<?= htmlspecialchars($buscar) ?>">
          <button type="submit" class="btn btn-primary">Buscar</button>
          <?php if ($filtro_estado || $buscar): ?>
            <a href="bookings.php" class="btn btn-ghost">Limpiar</a>
          <?php endif; ?>
          <span class="text-muted text-sm" style="margin-left:auto"><?= $total ?> resultado<?= $total !== 1 ? 's' : '' ?></span>
        </form>

        <!-- Tabla -->
        <table class="admin-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Referencia</th>
              <th>Cliente</th>
              <th>Paquete / Destino</th>
              <th>Fechas</th>
              <th>Viajeros</th>
              <th>Importe</th>
              <th>Estado</th>
              <th>Cambiar estado</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($reservas): ?>
              <?php foreach ($reservas as $r): ?>
              <tr>
                <td class="font-mono"><?= $r['id'] ?></td>
                <td class="font-mono" style="font-size:12px;color:var(--n500)"><?= htmlspecialchars($r['referencia'] ?? '—') ?></td>
                <td>
                  <div style="font-weight:600"><?= htmlspecialchars($r['cli_nombre'] . ' ' . $r['cli_apellidos']) ?></div>
                  <div class="text-muted text-sm"><?= htmlspecialchars($r['cli_email']) ?></div>
                  <?php if ($r['cli_tel']): ?>
                    <div class="text-muted text-sm"><?= htmlspecialchars($r['cli_tel']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="font-weight:600"><?= htmlspecialchars($r['paquete']) ?></div>
                  <div class="text-muted text-sm"><?= htmlspecialchars($r['destino'] ?? '—') ?></div>
                </td>
                <td class="text-sm">
                  <?php if ($r['fecha_salida']): ?>
                    <?= date('d/m/Y', strtotime($r['fecha_salida'])) ?>
                    <?php if ($r['fecha_regreso']): ?>
                      → <?= date('d/m/Y', strtotime($r['fecha_regreso'])) ?>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center"><?= (int)$r['num_viajeros'] ?></td>
                <td style="font-weight:700"><?= number_format($r['precio_total'], 0, ',', '.') ?> €</td>
                <td><span class="badge badge-<?= $r['estado'] ?>"><?= ucfirst($r['estado']) ?></span></td>
                <td>
                  <form method="POST" class="status-form">
                    <input type="hidden" name="reserva_id" value="<?= $r['id'] ?>">
                    <?php if ($filtro_estado): ?>
                      <input type="hidden" name="estado" value="<?= htmlspecialchars($filtro_estado) ?>">
                    <?php endif; ?>
                    <select name="nuevo_estado">
                      <option value="pendiente"  <?= $r['estado']==='pendiente'  ? 'selected' : '' ?>>Pendiente</option>
                      <option value="confirmada" <?= $r['estado']==='confirmada' ? 'selected' : '' ?>>Confirmada</option>
                      <option value="cancelada"  <?= $r['estado']==='cancelada'  ? 'selected' : '' ?>>Cancelada</option>
                    </select>
                    <button type="submit" class="btn btn-ghost" style="padding:5px 10px">Guardar</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="9">
                <div class="empty-state">
                  <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  <p>No hay reservas que coincidan con los filtros.</p>
                </div>
              </td></tr>
            <?php endif; ?>
          </tbody>
        </table>

        <!-- Paginación -->
        <?php if ($pages > 1): ?>
        <div class="pagination">
          <span>Página <?= $page ?> de <?= $pages ?></span>
          <div class="pagination-links">
            <?php
            $qs = http_build_query(array_filter(['estado' => $filtro_estado, 'q' => $buscar]));
            $qs = $qs ? '&' . $qs : '';
            ?>
            <button class="page-btn" onclick="location.href='?page=<?= max(1,$page-1) ?><?= $qs ?>'"
              <?= $page <= 1 ? 'disabled' : '' ?>>← Ant.</button>
            <?php for ($i = max(1,$page-2); $i <= min($pages,$page+2); $i++): ?>
              <button class="page-btn <?= $i===$page?'active':'' ?>"
                onclick="location.href='?page=<?= $i ?><?= $qs ?>'"><?= $i ?></button>
            <?php endfor; ?>
            <button class="page-btn" onclick="location.href='?page=<?= min($pages,$page+1) ?><?= $qs ?>'"
              <?= $page >= $pages ? 'disabled' : '' ?>>Sig. →</button>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>
</body>
</html>