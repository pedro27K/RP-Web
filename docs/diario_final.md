# Diario de Actividades — RP Travels (TFG ASIR 2025/2026)

**Equipo:** Pedro Trujillo Jurca · Raquel Romero Morilla  
**Período:** 28 de abril – 6 de junio de 2026  
**Proyecto:** Aplicación web de agencia de viajes (PHP 8.2 + MySQL 8 + Docker + Kubernetes)

---

## Semana 1 — 28 de abril al 2 de mayo de 2026

| Alumno | Actividad | Duración |
|--------|-----------|----------|
| Pedro | Página `login.php`: formulario de inicio de sesión con fetch a `api/auth.php?action=login`, manejo de errores y redirección tras éxito | 2 h |
| Raquel | Página de registro integrada en `login.php` (modal): campos nombre, apellidos, email, teléfono y contraseña con validación de requisitos en tiempo real | 2 h |
| Pedro | `includes/session.php`: validación de sesión activa para páginas protegidas y redirección a `login.php` si no hay sesión | 1 h |
| Raquel | Página `perfil.php`: visualización y edición de datos del usuario, cambio de contraseña y tabla de reservas activas con opciones de cancelación | 3 h |
| Pedro | Acción `update-profile` en `api/auth.php`: validación de campos, actualización PDO con sentencias preparadas, respuesta de confirmación | 2 h |
| Pedro | Página `reserva.php`: resumen del paquete seleccionado, formulario de viajeros, opción de seguro de cancelación (+10 %), opción de alquiler de coche, cálculo de precio total en JS | 3 h |
| Raquel | Estilos de `reserva.php` y `login.php` (`css/booking.css`, `css/modal.css`); validación visual del formulario de reserva y estados de error | 2 h |
| Raquel | Acción `reservar` en `api/api.php`: inserción en `reservas`, `viajeros` y `contactos_reserva`, lógica de precio con seguro y coche, comprobación de token CSRF, envío de email de confirmación vía msmtp + Gmail (Versión local) | 3 h |
| Pedro | Pruebas de la reserva completa (con y sin seguro, con y sin coche, varios viajeros); corrección del cálculo de días en el alquiler de coche | 1,5 h |
| Pedro | Acción `mis-reservas` en `api/api.php`: consulta de reservas del usuario en sesión con JOIN a `paquetes`, `destinos` y `coches` | 1,5 h |

---

## Semana 2 — 5 al 9 de mayo de 2026

| Alumno | Actividad | Duración |
|--------|-----------|----------|
| Raquel | Acción `cancelar-reserva` en `api/api.php`: validación de propiedad (solo cancela el dueño), comprobación de ventana de 48 h, actualización de estado en BD | 2 h |
| Pedro | Esqueleto del panel de administración: `admin/login.php`, `admin/auth-check.php` (verificación de `rol = 0`), `admin/logout.php` | 2 h |
| Raquel | Hoja de estilos del panel (`admin/css/admin.css`): sidebar, topbar, grid de contenido y diseño responsive | 2 h |
| Pedro | `admin/dashboard.php`: KPIs con consultas SQL agregadas (reservas totales, ingresos del mes, usuarios activos, paquetes disponibles) y gráfico de reservas por mes con Chart.js | 2,5 h |
| Raquel | `admin/partials/sidebar.php`: barra de navegación del panel con iconos SVG inline y marcado de sección activa | 1,5 h |
| Raquel | `admin/packages.php`: listado paginado de paquetes con filtro por tipo y estado, botones de editar y activar/desactivar | 2,5 h |
| Pedro | `admin/package-edit.php`: formulario completo de creación y edición de paquetes (nombre, destino, tipo, precio, fechas, descripción, imagen, servicios incluidos), actualización en BD | 2,5 h |
| Pedro | `admin/destinations.php`: CRUD completo de destinos con subida de imagen y campo de descripción | 2 h |
| Raquel | `admin/users.php`: listado de usuarios con búsqueda por nombre o email, paginación y cambio de rol (usuario ↔ administrador) | 2 h |
| Raquel | `admin/user-edit.php`: formulario de edición de datos del usuario desde el panel de administración | 1,5 h |
| Pedro | `admin/bookings.php`: listado de reservas con filtro por estado y rango de fechas, descarga de listado en CSV | 2,5 h |
| Raquel | `admin/send-booking-email.php`: reenvío manual del email de confirmación de una reserva desde el panel vía msmtp + Gmail | 1 h |
| Pedro | Pruebas del panel completo con usuario administrador y usuario normal; corrección del acceso no autorizado a rutas del panel | 1 h |

---

## Semana 3 — 12 al 16 de mayo de 2026

| Alumno | Actividad | Duración |
|--------|-----------|----------|
| Raquel | Configuración del envío de correo con msmtp + Gmail: generación dinámica de `/etc/msmtprc` en `entrypoint.sh` con `MAIL_USER` y `MAIL_PASS` del `.env`, host `smtp.gmail.com:587` con STARTTLS | 2,5 h |
| Pedro | Integración de msmtp en el `Dockerfile`: instalación de `msmtp` y `ca-certificates`, configuración de `sendmail_path` en `mail.ini` para redirigir `mail()` de PHP a `/usr/bin/msmtp -t` | 1,5 h |
| Pedro | Pruebas de envío de correo dentro del contenedor Docker; verificación del log `/tmp/msmtp.log` y resolución del error de certificado TLS con Gmail | 1 h |
| Pedro | Desarrollo de `tools/recordatorios.php`: arquitectura multiproceso con `pcntl_fork()`, partición de recordatorios pendientes entre `--workers` procesos hijo, recogida de resultados con `pcntl_wait()`, argumentos `--days` y `--send` por línea de comandos | 3 h |
| Raquel | Pruebas de `recordatorios.php` en modo simulacro y en modo `--send`; verificación de que no se envían duplicados gracias al flag `enviado` en BD | 1,5 h |
| Raquel | `scripts/backup.sh`: volcado de la BD con `mysqldump` comprimido con `gzip`, nomenclatura con fecha y hora, rotación automática de copias con más de 7 días y log en `backup.log`; autodetección de entorno local o Docker | 2 h |
| Pedro | `scripts/restore.sh`: restauración a partir de un `.sql.gz` con descompresión y reimportación; `scripts/crontab.example`: tarea cron para backup diario a las 02:00 | 1,5 h |
| Raquel | Prueba completa del ciclo backup → restore en el contenedor Docker | 1 h |
| Pedro | Manifiestos de Kubernetes: `00-namespace.yaml`, `02-mysql.yaml` con `Deployment` y `PersistentVolumeClaim`, `03-app.yaml` con `replicas: 2` y probes de liveness/readiness | 2 h |
| Raquel | `k8s/01-secret.yaml` con credenciales de BD en base64, `k8s/04-phpmyadmin.yaml`; `k8s/README.md` con guía paso a paso de despliegue en Minikube | 2 h |
| Pedro | Pruebas de despliegue en Kubernetes con Minikube: `kubectl apply`, verificación de pods, `port-forward` y escalado horizontal con `kubectl scale` | 1,5 h |
| Raquel | Ajuste de límites de recursos (`requests`/`limits` de CPU y memoria) en el manifiesto del deployment de la app | 1 h |

---

## Semana 4 — 19 al 23 de mayo de 2026

| Alumno | Actividad | Duración |
|--------|-----------|----------|
| Pedro | Revisión de seguridad: auditoría de todas las consultas PDO en `api/api.php` y `api/auth.php`, verificación de parámetros vinculados y revisión del rate limiting en login y registro (tabla `login_attempts`) | 2 h |
| Raquel | Revisión de XSS: comprobación de `htmlspecialchars` en todas las salidas PHP y revisión del Content Security Policy en `.htaccess` | 2 h |
| Pedro | Verificación de `session_regenerate_id(true)` en los flujos de login y registro; revisión de cabeceras de seguridad HTTP en `.htaccess` (X-Frame-Options, X-Content-Type-Options, HSTS, Referrer-Policy) | 1 h |
| Raquel | Escritura del manual de usuario (`docs/manual_usuario.md`): secciones de búsqueda, registro, login, reserva y perfil con capturas de pantalla (carpeta `docs/img/`) | 3 h |
| Pedro | Sección de administración del manual: gestión de paquetes, destinos, usuarios y reservas con capturas; exportación de diagramas E/R a PNG (`docs/diagrama_bbdd.png`, `docs/diagrama_er.png`) | 2,5 h |
| Raquel | Redacción y completado del `README.md`: estructura del proyecto, puesta en marcha con Docker y XAMPP, endpoints de la API, variables de entorno, seguridad y bibliografía | 2,5 h |
| Pedro | Revisión del `README.md` y redacción del `MODULOS.md` relacionando cada parte del proyecto con los módulos del ciclo ASIR | 1 h |
| Raquel | Corrección de bugs del frontend: cálculo incorrecto del precio total al cambiar el número de viajeros, estilos rotos en `css/confirm.css` | 2 h |
| Pedro | Corrección de bugs del backend: error al insertar reserva cuando el teléfono del contacto estaba vacío, token CSRF no regenerado correctamente tras sesión larga | 2 h |
| Raquel | Mejora de la accesibilidad: atributos `aria-label` en botones de icono del panel y revisión del contraste de color en textos secundarios | 1 h |
| Pedro | Optimización SQL: índices compuestos en `reservas(usuario_id, estado)` y `paquetes(destino_id, activo)`; actualización del script `sql/rp.sql` con los nuevos índices | 1,5 h |
| Raquel | Pruebas de rendimiento con los nuevos índices; comprobación de tiempos de respuesta de la API de búsqueda con distintos filtros | 1 h |

---

## Semana 5 — 26 al 30 de mayo de 2026

| Alumno | Actividad | Duración |
|--------|-----------|----------|
| Raquel | Prueba funcional completa del flujo de usuario: registro → login → búsqueda con filtros → detalle de paquete → reserva con viajeros → confirmación → cancelación → perfil | 2,5 h |
| Pedro | Prueba funcional del panel de administración: login → dashboard → editar paquete → gestionar reserva → reenviar email de confirmación | 2 h |
| Raquel | Registro y resolución de incidencias detectadas en las pruebas (bugs en `resultados.php` y en el filtro de fechas del buscador) | 1,5 h |
| Pedro | Pruebas de seguridad: intento de acceso al panel sin autenticar, inyección SQL manual en campos de búsqueda, verificación del bloqueo por rate limiting tras 5 intentos fallidos | 2 h |
| Raquel | Prueba del flujo de recuperación de contraseña end-to-end: solicitud de reset, recepción del email vía msmtp + Gmail y establecimiento de nueva contraseña | 1 h |
| Pedro | Corrección del bug de redirección tras logout del administrador que enviaba a `login.php` público en lugar de `admin/login.php` | 1 h |
| Raquel | Pruebas de compatibilidad del frontend en Chrome, Firefox y Edge; corrección de gap incorrecto en CSS Grid en Firefox | 2 h |
| Pedro | Prueba de la app en pantallas pequeñas (320 px) y corrección de overflow horizontal en `resultados.php` y `reserva.php` | 1,5 h |
| Raquel | Validación del HTML con el validador W3C; corrección de atributos `alt` vacíos en imágenes de destinos | 1 h |
| Pedro | Despliegue en Render.com: configuración del servicio web con imagen Docker, variables de entorno en el panel de Render, base de datos MySQL externa | 2,5 h |
| Raquel | Verificación del despliegue en producción (`rp-travels.onrender.com`): prueba de todas las páginas y endpoints en el entorno real | 2 h |
| Pedro | Corrección del error 500 en producción por diferencia en la extensión `pcntl` entre el contenedor local y Render | 1 h |


---

## Semana 6 — 2 al 6 de junio de 2026

| Alumno | Actividad | Duración |
|--------|-----------|----------|

| Pedro | Revisión final de `README.md`, `MODULOS.md` y `docs/manual_usuario.md`; limpieza del repositorio. | 2 h |
| Raquel | Comprobación del repositorio público de GitHub (`pedro27K/RP-Local` y `pedro27K/RP-Web`): estructura de ficheros, renderizado correcto del `README.md` y accesibilidad pública | 1 h |

| Raquel | Elaboración del diario de actividades (`docs/diario.md`) | 2 h |
| Pedro | Revisión del diario de actividades y verificación de la coherencia con el trabajo realizado | 1 h |
| Pedro | Implementación de GitHub como plataforma de entrega: subida del repositorio completo con código fuente, documentación y recursos | 1 h |
| Raquel | Verificación del repositorio GitHub tras la subida: estructura de ficheros, renderizado del `README.md` y accesibilidad pública | 0,5 h |
| Raquel | Commit y push final al repositorio; cierre del proyecto | 0,5 h |
| Pedro | Commit y push final al repositorio; cierre del proyecto | 0,5 h |

---

## Resumen de horas por alumno

| Módulo / Área | Pedro | Raquel |
|---------------|------:|-------:|
| API backend (PHP + PDO) | 9 h | 9 h |
| Frontend público (HTML, CSS, JS) | 7 h | 9 h |
| Panel de administración | 10,5 h | 9,5 h |
| Autenticación y seguridad | 5 h | 4 h |
| msmtp + Gmail y notificaciones | 2,5 h | 2,5 h |
| Worker multiproceso (`recordatorios.php`) | 3 h | 1,5 h |
| Scripts de backup y restauración | 1,5 h | 3 h |
| Despliegue Docker y entrypoint | 1,5 h | 0 h |
| Despliegue Kubernetes | 3,5 h | 3 h |
| Despliegue en producción (Render) | 3,5 h | 2 h |
| Pruebas y corrección de bugs | 8 h | 8 h |
| Documentación | 5 h | 7,5 h |
| **Total** | **70 h** | **69 h** |

> **Total conjunto:** 139 horas · **Media por alumno:** ~69,5 horas
