<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$accion = $_GET['action'] ?? '';

validarCSRF();

try {
    $db = obtenerBD();

    switch ($accion) {

        // ── Obtener ciudades de origen ─────────────────────
        case 'origenes':
            $stmt = $db->query("SELECT id, nombre, codigo, pais FROM ciudades_origen WHERE activo = 1 ORDER BY nombre");
            respuestaJson(['success' => true, 'data' => $stmt->fetchAll()]);

        // ── Obtener destinos ───────────────────────────────
        case 'destinos':
            $stmt = $db->query("SELECT id, nombre, pais, continente, descripcion FROM destinos WHERE activo = 1 ORDER BY nombre");
            respuestaJson(['success' => true, 'data' => $stmt->fetchAll()]);

        // ── Todos los paquetes (para home) ─────────────────
        case 'paquetes':
            $stmt = $db->query("
                SELECT p.*, d.nombre AS destino_nombre, d.pais, d.continente
                FROM paquetes p
                JOIN destinos d ON d.id = p.destino_id
                WHERE p.activo = 1
                ORDER BY p.badge_tipo DESC, p.precio_persona ASC
                LIMIT 100
            ");
            respuestaJson(['success' => true, 'data' => $stmt->fetchAll()]);

        // ── Buscar paquetes ────────────────────────────────
        case 'buscar':
            $destino    = $_GET['destino']    ?? '';
            $noches_min = (int)($_GET['noches_min'] ?? 1);
            $noches_max = (int)($_GET['noches_max'] ?? 30);
            $precio_max = (float)($_GET['precio_max'] ?? 9999);
            $regimen    = $_GET['regimen']    ?? '';
            $tab        = $_GET['tab']        ?? '';

            $tipo_map = [
                'vuelos'    => 'vuelo',
                'hoteles'   => 'hotel',
                'paquetes'  => 'paquete',
                'cruceros'  => 'crucero',
                'circuitos' => 'circuito',
                'finde'     => 'finde',
            ];

            $sql = "
                SELECT p.*, d.nombre AS destino_nombre, d.pais, d.continente
                FROM paquetes p
                JOIN destinos d ON d.id = p.destino_id
                WHERE p.activo = 1
                  AND p.noches BETWEEN :noches_min AND :noches_max
                  AND p.precio_persona <= :precio_max
            ";
            $params = [
                ':noches_min' => $noches_min,
                ':noches_max' => $noches_max,
                ':precio_max' => $precio_max,
            ];

            // Solo filtrar por tipo si la columna existe (migración ejecutada)
            if ($tab && isset($tipo_map[$tab])) {
                $tipoColExiste = (int)$db->query("
                    SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'paquetes'
                      AND COLUMN_NAME = 'tipo'
                ")->fetchColumn() > 0;
                if ($tipoColExiste) {
                    $sql .= " AND p.tipo = :tipo";
                    $params[':tipo'] = $tipo_map[$tab];
                }
            }
            if ($destino) {
                $sql .= " AND (d.nombre LIKE :destino OR d.pais LIKE :destino2 OR d.continente LIKE :destino3)";
                $params[':destino']  = "%$destino%";
                $params[':destino2'] = "%$destino%";
                $params[':destino3'] = "%$destino%";
            }
            if ($regimen) {
                $sql .= " AND p.regimen = :regimen";
                $params[':regimen'] = $regimen;
            }

            $sql .= " ORDER BY p.badge_tipo DESC, p.precio_persona ASC LIMIT 50";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            respuestaJson(['success' => true, 'data' => $stmt->fetchAll()]);

        // ── Detalle de un paquete ──────────────────────────
        case 'paquete':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) respuestaJson(['error' => 'ID requerido'], 400);

            $stmt = $db->prepare("
                SELECT p.*, d.nombre AS destino_nombre, d.pais, d.continente, d.descripcion AS destino_descripcion
                FROM paquetes p
                JOIN destinos d ON d.id = p.destino_id
                WHERE p.id = :id AND p.activo = 1
            ");
            $stmt->execute([':id' => $id]);
            $paquete = $stmt->fetch();
            if (!$paquete) respuestaJson(['error' => 'Paquete no encontrado'], 404);
            respuestaJson(['success' => true, 'data' => $paquete]);

        // ── Crear reserva ──────────────────────────────────
        case 'reservar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') respuestaJson(['error' => 'Método no permitido'], 405);

            $datos = json_decode(file_get_contents('php://input'), true);
            if (!$datos) respuestaJson(['error' => 'Datos inválidos'], 400);

            $required = ['paquete_id', 'origen_id', 'fecha_salida', 'fecha_regreso', 'num_adultos', 'viajeros', 'contacto'];
            foreach ($required as $field) {
                if (empty($datos[$field])) respuestaJson(['error' => "Campo requerido: $field"], 400);
            }

            $paqueteId = (int)$datos['paquete_id'];
            if ($paqueteId <= 0) respuestaJson(['error' => 'ID de paquete inválido'], 400);

            $viajeros = $datos['viajeros'];
            if (!is_array($viajeros) || count($viajeros) < 1 || count($viajeros) > 10) {
                respuestaJson(['error' => 'La reserva debe tener entre 1 y 10 viajeros'], 400);
            }

            foreach ($viajeros as $v) {
                if (empty($v['nombre']) || strlen($v['nombre']) > 100) respuestaJson(['error' => 'Nombre de viajero inválido'], 400);
                if (empty($v['apellidos']) || strlen($v['apellidos']) > 150) respuestaJson(['error' => 'Apellidos de viajero inválidos'], 400);
                if (empty($v['documento']) || strlen($v['documento']) > 30) respuestaJson(['error' => 'Documento de viajero inválido'], 400);
            }

            // Validar formato y rango de fechas
            $fechaSalida  = $datos['fecha_salida']  ?? '';
            $fechaRegreso = $datos['fecha_regreso'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaSalida) || !strtotime($fechaSalida)) {
                respuestaJson(['error' => 'Fecha de salida inválida'], 400);
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaRegreso) || !strtotime($fechaRegreso)) {
                respuestaJson(['error' => 'Fecha de regreso inválida'], 400);
            }
            if ($fechaSalida < date('Y-m-d')) {
                respuestaJson(['error' => 'La fecha de salida no puede ser anterior a hoy'], 400);
            }
            if ($fechaRegreso <= $fechaSalida) {
                respuestaJson(['error' => 'La fecha de regreso debe ser posterior a la de salida'], 400);
            }
            $diasViaje = (strtotime($fechaRegreso) - strtotime($fechaSalida)) / 86400;
            if ($diasViaje > 365) {
                respuestaJson(['error' => 'El viaje no puede durar más de 365 días'], 400);
            }

            // Alquiler de coche (opcional)
            $cocheId     = (int)($datos['coche_id'] ?? 0);
            $precioCoche = 0.0;
            $cocheNombre = null;
            if ($cocheId > 0) {
                $stmtC = $db->prepare("SELECT nombre, precio_dia FROM coches WHERE id = :id AND activo = 1");
                $stmtC->execute([':id' => $cocheId]);
                $cocheData = $stmtC->fetch();
                if ($cocheData) {
                    $precioCoche = round((float)$cocheData['precio_dia'] * $diasViaje, 2);
                    $cocheNombre = $cocheData['nombre'];
                } else {
                    $cocheId = 0;
                }
            }

            $numAdultos = (int)($datos['num_adultos'] ?? 0);
            $numNinos   = (int)($datos['num_ninos']   ?? 0);
            if ($numAdultos < 1 || $numAdultos > 10) {
                respuestaJson(['error' => 'El número de adultos debe estar entre 1 y 10'], 400);
            }
            if ($numNinos < 0 || $numNinos > 10) {
                respuestaJson(['error' => 'El número de niños debe estar entre 0 y 10'], 400);
            }

            $c = $datos['contacto'];
            if (empty($c['email']) || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
                respuestaJson(['error' => 'Email de contacto inválido'], 400);
            }

            $stmt = $db->prepare("
                SELECT p.precio_persona, p.plazas_disponibles, p.nombre AS paquete_nombre, p.noches, p.regimen,
                       d.nombre AS destino_nombre, d.pais
                FROM paquetes p
                JOIN destinos d ON d.id = p.destino_id
                WHERE p.id = :id AND p.activo = 1
            ");
            $stmt->execute([':id' => $paqueteId]);
            $paquete = $stmt->fetch();
            if (!$paquete) respuestaJson(['error' => 'Este paquete no está disponible.'], 404);

            $total_viajeros = $numAdultos + $numNinos;
            if ($paquete['plazas_disponibles'] !== null && (int)$paquete['plazas_disponibles'] < $total_viajeros) {
                respuestaJson(['error' => 'No hay suficientes plazas disponibles para este paquete.'], 409);
            }

            $seguroCancelacion = (isset($datos['seguro_cancelacion']) && $datos['seguro_cancelacion']) ? 1 : 0;

            $precio_total = (float)$paquete['precio_persona'] * $total_viajeros;
            if ($seguroCancelacion) {
                $precio_total = round($precio_total * 1.10, 2);
            }
            $precio_total = round($precio_total + $precioCoche, 2);
            $referencia   = 'RP' . strtoupper(substr(uniqid(), -8));

            $usuario_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO reservas (referencia, usuario_id, paquete_id, origen_id, fecha_salida, fecha_regreso, num_adultos, num_ninos, precio_total, seguro_cancelacion, coche_id, precio_coche)
                VALUES (:ref, :uid, :paq, :orig, :salida, :regreso, :adultos, :ninos, :total, :seguro, :coche_id, :precio_coche)
            ");
            $stmt->execute([
                ':ref'          => $referencia,
                ':uid'          => $usuario_id,
                ':paq'          => (int)$datos['paquete_id'],
                ':orig'         => (int)$datos['origen_id'],
                ':salida'       => $fechaSalida,
                ':regreso'      => $fechaRegreso,
                ':adultos'      => $numAdultos,
                ':ninos'        => $numNinos,
                ':total'        => $precio_total,
                ':seguro'       => $seguroCancelacion,
                ':coche_id'     => $cocheId ?: null,
                ':precio_coche' => $precioCoche,
            ]);
            $reserva_id = $db->lastInsertId();

            foreach ($viajeros as $v) {
                $stmt = $db->prepare("
                    INSERT INTO viajeros (reserva_id, nombre, apellidos, documento, fecha_nacimiento, nacionalidad, tipo)
                    VALUES (:rid, :nombre, :apellidos, :doc, :fn, :nac, :tipo)
                ");
                $stmt->execute([
                    ':rid'       => $reserva_id,
                    ':nombre'    => substr($v['nombre'],    0, 100),
                    ':apellidos' => substr($v['apellidos'], 0, 150),
                    ':doc'       => substr($v['documento'], 0, 30),
                    ':fn'        => $v['fecha_nacimiento'] ?: null,
                    ':nac'       => substr($v['nacionalidad'] ?? 'Española', 0, 80),
                    ':tipo'      => in_array($v['tipo'] ?? '', ['adulto', 'niño']) ? $v['tipo'] : 'adulto',
                ]);
            }

            $stmt = $db->prepare("
                INSERT INTO contactos_reserva (reserva_id, telefono, email, comentarios)
                VALUES (:rid, :tel, :email, :com)
            ");
            $stmt->execute([
                ':rid'   => $reserva_id,
                ':tel'   => substr($c['telefono']    ?? '', 0, 30),
                ':email' => substr($c['email'],          0, 150),
                ':com'   => substr($c['comentarios'] ?? '', 0, 1000),
            ]);


            $db->commit();

            // Envío de correo deshabilitado en versión web

            respuestaJson(['success' => true, 'referencia' => $referencia, 'precio_total' => $precio_total]);

        // ── Mis reservas (usuario autenticado) ─────────────
        case 'mis-reservas':
            if (!isset($_SESSION['user_id'])) {
                respuestaJson(['error' => 'No autenticado'], 401);
            }

            $stmt = $db->prepare("
                SELECT
                    r.id, r.referencia, r.fecha_salida, r.fecha_regreso,
                    r.num_adultos, r.num_ninos, r.precio_total, r.estado,
                    r.seguro_cancelacion, r.created_at,
                    r.precio_coche,
                    p.nombre  AS paquete_nombre,
                    p.noches, p.regimen, p.aerolinea,
                    d.nombre  AS destino_nombre,
                    d.pais,
                    co.nombre AS origen_nombre,
                    c2.nombre AS coche_nombre
                FROM reservas r
                JOIN paquetes p         ON p.id  = r.paquete_id
                JOIN destinos d         ON d.id  = p.destino_id
                JOIN ciudades_origen co ON co.id = r.origen_id
                LEFT JOIN coches c2     ON c2.id = r.coche_id
                WHERE r.usuario_id = :uid
                ORDER BY r.created_at DESC
            ");
            $stmt->execute([':uid' => $_SESSION['user_id']]);
            respuestaJson(['success' => true, 'data' => $stmt->fetchAll()]);

        // ── Cancelar reserva ──────────────────────────────
        case 'cancelar-reserva':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') respuestaJson(['error' => 'Método no permitido'], 405);
            if (!isset($_SESSION['user_id'])) respuestaJson(['error' => 'No autenticado'], 401);

            $datos = json_decode(file_get_contents('php://input'), true);
            $reservaId = (int)($datos['reserva_id'] ?? 0);
            if (!$reservaId) respuestaJson(['error' => 'ID de reserva requerido'], 400);

            $stmt = $db->prepare("SELECT id, estado, seguro_cancelacion FROM reservas WHERE id = :id AND usuario_id = :uid");
            $stmt->execute([':id' => $reservaId, ':uid' => $_SESSION['user_id']]);
            $reserva = $stmt->fetch();

            if (!$reserva) respuestaJson(['error' => 'Reserva no encontrada'], 404);
            if ($reserva['estado'] === 'cancelada') respuestaJson(['error' => 'Esta reserva ya está cancelada'], 400);
            if (!$reserva['seguro_cancelacion']) respuestaJson(['error' => 'Esta reserva no incluye opción de cancelación'], 400);

            $stmt = $db->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id = :id");
            $stmt->execute([':id' => $reservaId]);

            // Envío de correo deshabilitado en versión web

            respuestaJson(['success' => true]);

        default:
            respuestaJson(['error' => 'Acción no válida'], 400);
    }

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[RP api] PDOException: ' . $e->getMessage());
    respuestaJson(['error' => 'Error en la base de datos.'], 500);
} catch (Exception $e) {
    respuestaJson(['error' => $e->getMessage()], 500);
}