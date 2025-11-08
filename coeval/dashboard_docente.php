<?php
// --- LÍNEAS PARA DEPURACIÓN ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// -----------------------------------------
require 'db.php';
verificar_sesion(true); // Solo para docentes

// --- BLOQUE AÑADIR---
// Cargar la escala de notas en un array para consulta rápida
$escala_lookup = [];
$result_escala = $conn->query("SELECT puntaje, nota FROM escala_notas");
while ($row = $result_escala->fetch_assoc()) {
    $escala_lookup[$row['puntaje']] = $row['nota'];
}
// --- FIN DEL BLOQUE A AÑADIR ---

// --- Pre-cargar los miembros de todos los equipos en un array (se mantiene) ---
$miembros_por_equipo = [];
$sql_miembros = "SELECT id_equipo, nombre FROM usuarios WHERE id_equipo IS NOT NULL AND es_docente = FALSE ORDER BY nombre ASC";
$result_miembros = $conn->query($sql_miembros);
while ($miembro = $result_miembros->fetch_assoc()) {
    $miembros_por_equipo[$miembro['id_equipo']][] = $miembro['nombre'];
}

// --- Consulta principal actualizada para usar 'estado_presentacion' ---
$sql_equipos = "
    SELECT 
        e.id, e.nombre_equipo, e.estado_presentacion,
        (SELECT AVG(em1.puntaje_total) FROM evaluaciones_maestro em1 JOIN usuarios u1 ON em1.id_evaluador = u1.id WHERE em1.id_equipo_evaluado = e.id AND u1.es_docente = FALSE) as promedio_estudiantes,
        (SELECT em2.puntaje_total FROM evaluaciones_maestro em2 JOIN usuarios u2 ON em2.id_evaluador = u2.id WHERE em2.id_equipo_evaluado = e.id AND u2.es_docente = TRUE LIMIT 1) as nota_docente,
        (SELECT COUNT(em3.id) FROM evaluaciones_maestro em3 JOIN usuarios u3 ON em3.id_evaluador = u3.id WHERE em3.id_equipo_evaluado = e.id AND u3.es_docente = FALSE) as total_eval_estudiantes
    FROM equipos e
    ORDER BY e.nombre_equipo ASC";
$resultados_equipos = $conn->query($sql_equipos);

$res_max_score = $conn->query("SELECT COUNT(*) as num_criterios FROM criterios WHERE activo = TRUE");
$max_score_data = $res_max_score->fetch_assoc();
$max_score = ($max_score_data && $max_score_data['num_criterios'] > 0) ? $max_score_data['num_criterios'] * 5 : 30;

// --- Lógica del panel de evaluación actualizada para usar 'estado_presentacion' ---
$equipo_presentando = null;
$result_presentando = $conn->query("SELECT id, nombre_equipo FROM equipos WHERE estado_presentacion = 'presentando'");
if ($result_presentando->num_rows > 0) $equipo_presentando = $result_presentando->fetch_assoc();

$docente_ya_evaluo = false;
if ($equipo_presentando) {
    $stmt_check = $conn->prepare("SELECT id FROM evaluaciones_maestro WHERE id_evaluador = ? AND id_equipo_evaluado = ?");
    $stmt_check->bind_param("ii", $_SESSION['id_usuario'], $equipo_presentando['id']);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) $docente_ya_evaluo = true;
    $stmt_check->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Docente</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">Panel del Docente</a>
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><span class="navbar-text me-3">¡Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre']); ?>!</span></li>
                <li class="nav-item"><a class="btn btn-outline-light" href="logout.php">Cerrar Sesión</a></li>
            </ul>
        </div>
    </nav>

    <div class="container mt-5">
        <?php if (isset($_GET['status'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_GET['status']); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header"><h4>Calificaciones Finales (sobre <?php echo $max_score; ?>)</h4></div>
                    <div class="card-body">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Equipo y Miembros</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-center">Puntaje Final</th>
                                    <th class="text-center">Nota (Escala)</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($equipo = $resultados_equipos->fetch_assoc()): ?>
                                    <?php
                                    // --- LÓGICA DE CÁLCULO Y CONVERSIÓN MODIFICADA ---
                                    $promedio_est = $equipo['promedio_estudiantes'];
                                    $nota_doc = $equipo['nota_docente'];
                                    $puntaje_final_score = null;
                                    $nota_final_grado = "N/A";

                                    if ($equipo['estado_presentacion'] == 'finalizado') {
                                        $promedio_est_final = ($promedio_est !== null) ? $promedio_est : 0;
                                        if ($nota_doc !== null) {
                                            $puntaje_final_score = ($promedio_est_final * 0.5) + ($nota_doc * 0.5);
                                            $puntaje_redondeado = round($puntaje_final_score);
                                            if (isset($escala_lookup[$puntaje_redondeado])) {
                                                $nota_final_grado = number_format($escala_lookup[$puntaje_redondeado], 1, ',', '.');
                                            } else {
                                                $nota_final_grado = "<span class='text-danger'>?</span>";
                                            }
                                        } else {
                                            $nota_final_grado = "<span class='text-danger'>Sin nota</span>";
                                        }
                                    } elseif ($equipo['estado_presentacion'] == 'presentando') {
                                        $nota_final_grado = "<span class='text-muted'>...</span>";
                                    }
                                    ?>
                                <tr>
                                    <td>
                                        <strong class="fs-6"><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></strong>
                                        <?php if (!empty($miembros_por_equipo[$equipo['id']])): ?>
                                            <ul class="list-unstyled mb-0 mt-1 small text-muted ps-2">
                                                <?php foreach ($miembros_por_equipo[$equipo['id']] as $nombre_miembro): ?>
                                                    <li>- <?php echo htmlspecialchars($nombre_miembro); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                        <div class="mt-2"><small class="badge bg-light text-dark"><?php echo $equipo['total_eval_estudiantes']; ?> eval. de estudiantes</small></div>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($equipo['estado_presentacion'] == 'pendiente'): ?>
                                            <span class="badge bg-secondary">Pendiente</span>
                                        <?php elseif ($equipo['estado_presentacion'] == 'presentando'): ?>
                                            <span class="badge bg-primary">Presentando</span>
                                        <?php else: // finalizado ?>
                                            <span class="badge bg-success">Finalizado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fs-5 fw-bold">
                                        <?php echo $puntaje_final_score !== null ? number_format($puntaje_final_score, 2, ',', '.') : 'N/A'; ?>
                                    </td>
                                    <td class="text-center fs-5 fw-bold text-primary">
                                        <?php echo $nota_final_grado; ?>
                                    </td>
                                    <td>
                                        <form action="gestionar_presentacion.php" method="POST" class="d-inline">
                                            <input type="hidden" name="id_equipo" value="<?php echo $equipo['id']; ?>">
                                            <?php if ($equipo['estado_presentacion'] == 'pendiente'): ?>
                                                <button type="submit" name="accion" value="iniciar" class="btn btn-primary btn-sm">Iniciar</button>
                                            <?php elseif ($equipo['estado_presentacion'] == 'presentando'): ?>
                                                <button type="submit" name="accion" value="terminar" class="btn btn-warning btn-sm">Terminar</button>
                                            <?php endif; ?>
                                        </form>
                                        <a href="ver_detalles.php?id=<?php echo $equipo['id']; ?>" class="btn btn-secondary btn-sm">Detalles</a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <!-- La columna derecha se mantiene intacta -->
            <div class="col-lg-4">
                <div class="card mb-4 border-primary">
                    <div class="card-header bg-primary text-white"><h5>Evaluar Presentación Actual</h5></div>
                    <div class="card-body text-center">
                        <?php if ($equipo_presentando): ?>
                            <h5 class="card-title"><?php echo htmlspecialchars($equipo_presentando['nombre_equipo']); ?></h5>
                            <?php if ($docente_ya_evaluo): ?>
                                <p class="alert alert-success">¡Gracias! Ya has evaluado a este equipo.</p>
                            <?php else: ?>
                                <a href="evaluar.php?id_equipo=<?php echo $equipo_presentando['id']; ?>" class="btn btn-success">Evaluar a este Equipo</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-muted">No hay ningún equipo presentando actualmente.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card mb-4">
                    <div class="card-header"><h5>Cargar Lista</h5></div>
                    <div class="card-body">
                    <p class="small">Sube un CSV con 3 columnas: <code>nombre,email,id_equipo</code></p>
                    <form action="upload.php" method="post" enctype="multipart/form-data"><div class="mb-2"><input class="form-control form-control-sm" type="file" name="lista_estudiantes" accept=".csv" required></div><button type="submit" class="btn btn-success w-100">Cargar</button></form></div>
                </div>
                <!-- AÑADIR ESTE PANEL COMPLETO -->
                <div class="card mb-4">
                    <div class="card-header"><h5>Cargar Escala de Notas</h5></div>
                    <div class="card-body">
                        <p class="small">Sube un CSV con 2 columnas: <code>puntaje,nota</code></p>
                        <form action="upload_escala.php" method="post" enctype="multipart/form-data">
                            <div class="mb-2">
                                <input class="form-control form-control-sm" type="file" name="escala_csv" accept=".csv" required>
                            </div>
                            <button type="submit" class="btn btn-info w-100">Cargar/Actualizar Escala</button>
                        </form>
                    </div>
                </div>
                <!-- FIN DEL PANEL A AÑADIR -->
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white"><h5>Zona de Administración</h5></div>
                    <div class="card-body">
                        <a href="gestionar_criterios.php" class="btn btn-secondary w-100 mb-2">Gestionar Criterios</a>
                        <a href="export_results.php" class="btn btn-info w-100 mb-2">Exportar Resultados</a>
                        <button class="btn btn-danger w-100" data-bs-toggle="modal" data-bs-target="#confirmModal">Resetear Plataforma</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Modal y scripts se mantienen intactos -->
    <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Confirmar Reseteo</h5></div><div class="modal-body"><strong>¡ADVERTENCIA!</strong> Estás a punto de borrar TODOS los estudiantes, equipos y evaluaciones. ¿Deseas continuar?</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><form action="admin_actions.php" method="POST"><input type="hidden" name="action" value="reset_all"><button type="submit" class="btn btn-danger">Sí, estoy seguro</button></form></div></div></div></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>