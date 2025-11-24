<?php
require 'db.php';
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

$id_evaluacion = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id_evaluacion === 0) {
    header("Location: dashboard_docente.php?error=" . urlencode("ID de evaluación no proporcionado."));
    exit();
}

// Obtener información de la evaluación
$stmt_evaluacion = $conn->prepare("SELECT nombre_evaluacion, tipo_evaluacion, estado, id_curso FROM evaluaciones WHERE id = ? AND id_curso = ?");
$stmt_evaluacion->bind_param("ii", $id_evaluacion, $id_curso_activo);
$stmt_evaluacion->execute();
$evaluacion_info = $stmt_evaluacion->get_result()->fetch_assoc();
$stmt_evaluacion->close();

if (!$evaluacion_info) {
    header("Location: dashboard_docente.php?error=" . urlencode("Evaluación no encontrada o no pertenece a este curso."));
    exit();
}

if ($evaluacion_info['estado'] !== 'iniciada' && $evaluacion_info['estado'] !== 'cerrada') {
    header("Location: dashboard_docente.php?error=" . urlencode("Esta evaluación aún no ha sido iniciada."));
    exit();
}

$nombre_evaluacion = $evaluacion_info['nombre_evaluacion'];
$tipo_evaluacion = $evaluacion_info['tipo_evaluacion'];
$estado_evaluacion = $evaluacion_info['estado'];

$status_message = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
$error_message = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';

// Obtener información del curso
$stmt_curso = $conn->prepare("SELECT nombre_curso, semestre, anio FROM cursos WHERE id = ?");
$stmt_curso->bind_param("i", $id_curso_activo);
$stmt_curso->execute();
$curso_activo = $stmt_curso->get_result()->fetch_assoc();
$stmt_curso->close();

// Función para calcular la evaluación docente ponderada
function obtener_puntaje_docente_ponderado($conn, $id_equipo, $id_curso) {
    $sql = "
        SELECT em.puntaje_total, dc.ponderacion
        FROM evaluaciones_maestro em
        JOIN usuarios u ON em.id_evaluador = u.id
        JOIN docente_curso dc ON u.id = dc.id_docente AND dc.id_curso = em.id_curso
        WHERE em.id_equipo_evaluado = ? AND em.id_curso = ? AND u.es_docente = TRUE
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id_equipo, $id_curso);
    $stmt->execute();
    $result = $stmt->get_result();

    $total_ponderado = 0;
    $total_ponderacion = 0;

    while ($row = $result->fetch_assoc()) {
        $total_ponderado += $row['puntaje_total'] * $row['ponderacion'];
        $total_ponderacion += $row['ponderacion'];
    }

    $stmt->close();

    if ($total_ponderacion > 0) {
        return $total_ponderado / $total_ponderacion;
    } else {
        return null;
    }
}

// Función para calcular la evaluación de invitados
function obtener_puntaje_invitados_ponderado($conn, $id_equipo, $id_curso) {
    $stmt_config = $conn->prepare("SELECT usar_ponderacion_unica_invitados, ponderacion_unica_invitados FROM cursos WHERE id = ?");
    $stmt_config->bind_param("i", $id_curso);
    $stmt_config->execute();
    $config = $stmt_config->get_result()->fetch_assoc();
    $stmt_config->close();
    
    $usar_ponderacion_unica = isset($config['usar_ponderacion_unica_invitados']) ? (bool)$config['usar_ponderacion_unica_invitados'] : false;
    
    $sql_invitados = "
        SELECT em.puntaje_total
        FROM evaluaciones_maestro em
        JOIN usuarios u ON em.id_evaluador = u.id
        WHERE em.id_equipo_evaluado = ? 
        AND em.id_curso = ? 
        AND u.es_docente = FALSE
        AND u.id_equipo IS NULL
        AND u.id_curso IS NULL
    ";
    $stmt_inv = $conn->prepare($sql_invitados);
    $stmt_inv->bind_param("ii", $id_equipo, $id_curso);
    $stmt_inv->execute();
    $result_inv = $stmt_inv->get_result();
    
    $evaluaciones_invitados = [];
    while ($row = $result_inv->fetch_assoc()) {
        $evaluaciones_invitados[] = $row['puntaje_total'];
    }
    $stmt_inv->close();
    
    if (empty($evaluaciones_invitados)) {
        return null;
    }
    
    $promedio_invitados = array_sum($evaluaciones_invitados) / count($evaluaciones_invitados);
    return $promedio_invitados;
}

// Función para calcular nota final
function calcular_nota_final($puntaje) {
    if ($puntaje <= 0) return 1.0;
    if ($puntaje >= 100) return 7.0;
    return round(1.0 + ($puntaje / 100) * 6.0, 1);
}

require_once __DIR__ . '/qualitative_helpers.php';

// Si es grupal, mostrar equipos; si es individual, mostrar estudiantes
if ($tipo_evaluacion === 'grupal') {
    // Obtener equipos del curso
    $sql_equipos = "
        SELECT
            e.id,
            e.nombre_equipo,
            e.estado_presentacion,
            (
                SELECT AVG(em1.puntaje_total)
                FROM evaluaciones_maestro em1
                JOIN usuarios u1 ON em1.id_evaluador = u1.id
                WHERE em1.id_equipo_evaluado = e.id
                AND u1.es_docente = FALSE
            ) as promedio_estudiantes,
            (
                SELECT COUNT(em3.id)
                FROM evaluaciones_maestro em3
                JOIN usuarios u3 ON em3.id_evaluador = u3.id
                WHERE em3.id_equipo_evaluado = e.id
                AND u3.es_docente = FALSE
            ) as total_eval_estudiantes
        FROM equipos e
        WHERE e.id_curso = ?
        ORDER BY e.nombre_equipo ASC
    ";
    $stmt_equipos = $conn->prepare($sql_equipos);
    $stmt_equipos->bind_param("i", $id_curso_activo);
    $stmt_equipos->execute();
    $items = $stmt_equipos->get_result();
    $es_grupal = true;
} else {
    // Obtener estudiantes del curso
    // Para individual, necesitamos obtener el equipo de cada estudiante para calcular evaluaciones
    $sql_estudiantes = "
        SELECT
            u.id,
            u.nombre,
            u.email,
            u.id_equipo,
            (
                SELECT AVG(em1.puntaje_total)
                FROM evaluaciones_maestro em1
                JOIN usuarios u1 ON em1.id_evaluador = u1.id
                WHERE em1.id_equipo_evaluado = COALESCE(u.id_equipo, 0)
                AND u1.es_docente = FALSE
                AND em1.id_curso = ?
            ) as promedio_estudiantes,
            (
                SELECT COUNT(em3.id)
                FROM evaluaciones_maestro em3
                JOIN usuarios u3 ON em3.id_evaluador = u3.id
                WHERE em3.id_equipo_evaluado = COALESCE(u.id_equipo, 0)
                AND u3.es_docente = FALSE
                AND em3.id_curso = ?
            ) as total_eval_estudiantes
        FROM usuarios u
        WHERE u.es_docente = 0 
        AND u.id_curso = ?
        ORDER BY u.nombre ASC
    ";
    $stmt_estudiantes = $conn->prepare($sql_estudiantes);
    $stmt_estudiantes->bind_param("iii", $id_curso_activo, $id_curso_activo, $id_curso_activo);
    $stmt_estudiantes->execute();
    $items = $stmt_estudiantes->get_result();
    $es_grupal = false;
}

include 'header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($nombre_evaluacion); ?> - <?php echo htmlspecialchars($curso_activo['nombre_curso']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1><?php echo htmlspecialchars($nombre_evaluacion); ?></h1>
                <p class="text-muted mb-0">
                    Curso: <strong><?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre'] . '-' . $curso_activo['anio']); ?></strong> | 
                    Tipo: <strong><?php echo $tipo_evaluacion === 'grupal' ? 'Grupal' : 'Individual'; ?></strong> | 
                    Estado: <strong><?php echo $estado_evaluacion === 'iniciada' ? 'Iniciada' : 'Cerrada'; ?></strong>
                </p>
            </div>
            <a href="dashboard_docente.php" class="btn btn-secondary">← Volver al Dashboard</a>
        </div>

        <?php if ($status_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $status_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <h2><?php echo $es_grupal ? 'Equipos del Curso' : 'Estudiantes del Curso'; ?></h2>
        <table class="table table-striped table-hover shadow-sm">
            <thead class="table-dark">
                <tr>
                    <th><?php echo $es_grupal ? 'Equipo' : 'Estudiante'; ?></th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Eval. Estudiantes</th>
                    <th class="text-center">Nota Docente (0-100)</th>
                    <th class="text-center">Puntaje Final (0-100)</th>
                    <th class="text-center">Nota Final (1.0-7.0)</th>
                    <th class="text-center">Eval. Cualitativa</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($items->num_rows > 0): ?>
                    <?php while($item = $items->fetch_assoc()): ?>
                        <?php
                            $id_item = $item['id'];
                            $nombre_item = $es_grupal ? $item['nombre_equipo'] : $item['nombre'];
                            
                            // Para individual, necesitamos obtener el equipo del estudiante
                            if (!$es_grupal && isset($item['id_equipo']) && $item['id_equipo']) {
                                $id_equipo_para_calculo = $item['id_equipo'];
                            } else {
                                $id_equipo_para_calculo = $id_item;
                            }
                            
                            $promedio_est = $item['promedio_estudiantes'];
                            $nota_doc = obtener_puntaje_docente_ponderado($conn, $id_equipo_para_calculo, $id_curso_activo);
                            $puntaje_final_score = null;
                            $nota_final_grado = 'N/A';

                            $promedio_est_display = ($promedio_est !== null) ? round($promedio_est, 2) : 'N/A';
                            $nota_doc_display = ($nota_doc !== null) ? round($nota_doc, 2) : 'N/A';

                            // Solo calcular el puntaje final si la evaluación está cerrada o si hay datos suficientes
                            if ($estado_evaluacion == 'cerrada' || ($promedio_est !== null || $nota_doc !== null)) {
                                // Obtener ponderaciones del curso
                                $stmt_pond = $conn->prepare("SELECT ponderacion_estudiantes, usar_ponderacion_unica_invitados, ponderacion_unica_invitados FROM cursos WHERE id = ?");
                                $stmt_pond->bind_param("i", $id_curso_activo);
                                $stmt_pond->execute();
                                $config_pond = $stmt_pond->get_result()->fetch_assoc();
                                $stmt_pond->close();
                                
                                $pond_est = isset($config_pond['ponderacion_estudiantes']) ? (float)$config_pond['ponderacion_estudiantes'] / 100 : 0;
                                $pond_doc = 0;
                                $pond_inv = 0;
                                
                                // Calcular ponderación de docentes
                                $stmt_pond_doc = $conn->prepare("SELECT SUM(ponderacion) as total FROM docente_curso WHERE id_curso = ?");
                                $stmt_pond_doc->bind_param("i", $id_curso_activo);
                                $stmt_pond_doc->execute();
                                $result_pond_doc = $stmt_pond_doc->get_result()->fetch_assoc();
                                $pond_doc = isset($result_pond_doc['total']) ? (float)$result_pond_doc['total'] : 0;
                                $stmt_pond_doc->close();
                                
                                // Calcular ponderación de invitados
                                if (isset($config_pond['usar_ponderacion_unica_invitados']) && $config_pond['usar_ponderacion_unica_invitados']) {
                                    $pond_inv = isset($config_pond['ponderacion_unica_invitados']) ? (float)$config_pond['ponderacion_unica_invitados'] / 100 : 0;
                                } else {
                                    $stmt_pond_inv = $conn->prepare("SELECT SUM(ponderacion) as total FROM invitado_curso WHERE id_curso = ?");
                                    $stmt_pond_inv->bind_param("i", $id_curso_activo);
                                    $stmt_pond_inv->execute();
                                    $result_pond_inv = $stmt_pond_inv->get_result()->fetch_assoc();
                                    $pond_inv = isset($result_pond_inv['total']) ? (float)$result_pond_inv['total'] : 0;
                                    $stmt_pond_inv->close();
                                }
                                
                                $promedio_est_final = ($promedio_est !== null) ? $promedio_est : 0;
                                $nota_doc_final = ($nota_doc !== null) ? $nota_doc : 0;
                                $nota_inv = obtener_puntaje_invitados_ponderado($conn, $id_equipo_para_calculo, $id_curso_activo);
                                $nota_inv_final = ($nota_inv !== null) ? $nota_inv : 0;
                                
                                $puntaje_final_score = ($promedio_est_final * $pond_est) + ($nota_doc_final * $pond_doc) + ($nota_inv_final * $pond_inv);
                                
                                if ($puntaje_final_score > 0) {
                                    $nota_final_grado = calcular_nota_final($puntaje_final_score);
                                    $puntaje_final_score = number_format($puntaje_final_score, 2, '.', '');
                                } else {
                                    $puntaje_final_score = 'N/A (Faltan evaluaciones)';
                                }
                            } else {
                                $puntaje_final_score = 'Pendiente';
                            }

                            $qual_summary = get_latest_qualitative_summary($conn, (int)$id_equipo_para_calculo, $id_curso_activo);
                            $qual_total = isset($qual_summary['total']) ? (int)$qual_summary['total'] : 0;
                            $qual_last = (!empty($qual_summary['ultima_fecha'])) ? date("d/m H:i", strtotime($qual_summary['ultima_fecha'])) : null;
                            
                            // Para individual, obtener estado de presentación individual del estudiante
                            $estado_presentacion = 'pendiente';
                            if (!$es_grupal) {
                                // En evaluaciones individuales, usar el estado de presentación individual del estudiante
                                $stmt_estado = $conn->prepare("SELECT estado_presentacion_individual FROM usuarios WHERE id = ?");
                                $stmt_estado->bind_param("i", $id_item);
                                $stmt_estado->execute();
                                $estado_result = $stmt_estado->get_result()->fetch_assoc();
                                if ($estado_result && isset($estado_result['estado_presentacion_individual'])) {
                                    $estado_presentacion = $estado_result['estado_presentacion_individual'];
                                }
                                $stmt_estado->close();
                            } elseif ($es_grupal) {
                                $estado_presentacion = $item['estado_presentacion'];
                            }
                        ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($nombre_item); ?></strong></td>
                        <td class="text-center">
                            <?php 
                                if ($estado_presentacion == 'presentando') {
                                    echo '<span class="badge bg-success">Presentando</span>';
                                } elseif ($estado_presentacion == 'finalizado') {
                                    echo '<span class="badge bg-secondary">Finalizado</span>';
                                } else {
                                    echo '<span class="badge bg-warning text-dark">Pendiente</span>';
                                }
                            ?>
                        </td>
                        <td class="text-center" title="Puntaje promedio estudiantes (0-100): <?php echo $promedio_est_display; ?>">
                            <?php echo $item['total_eval_estudiantes']; ?> (<?php echo $promedio_est_display; ?>)
                        </td>
                        <td class="text-center"><?php echo $nota_doc_display; ?></td>
                        <td class="text-center fw-bold text-primary"><?php echo $puntaje_final_score; ?></td>
                        <td class="text-center fw-bold text-danger"><?php echo $nota_final_grado; ?></td>
                        <td class="text-center">
                            <?php if ($qual_total > 0): ?>
                                <span class="badge bg-success"><?php echo $qual_total; ?> registro<?php echo $qual_total > 1 ? 's' : ''; ?></span>
                                <div class="small text-muted">Última: <?php echo $qual_last ? $qual_last : 'N/D'; ?></div>
                            <?php else: ?>
                                <span class="badge bg-secondary">Sin registros</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($es_grupal): ?>
                                <form action="gestionar_presentacion.php" method="POST" class="d-inline">
                                    <input type="hidden" name="id_equipo" value="<?php echo $id_item; ?>">
                                    <input type="hidden" name="id_evaluacion" value="<?php echo $id_evaluacion; ?>">
                                    <?php if ($estado_presentacion == 'pendiente'): ?>
                                        <input type="hidden" name="accion" value="iniciar">
                                        <button type="submit" class="btn btn-sm btn-primary">Iniciar Presentación</button>
                                    <?php elseif ($estado_presentacion == 'presentando'): ?>
                                        <input type="hidden" name="accion" value="terminar">
                                        <button type="submit" class="btn btn-sm btn-success">Terminar Presentación</button>
                                    <?php endif; ?>
                                </form>
                                <?php if ($estado_presentacion == 'finalizado' || $estado_presentacion == 'presentando'): ?>
                                    <form action="gestionar_presentacion.php" method="POST" class="d-inline">
                                        <input type="hidden" name="id_equipo" value="<?php echo $id_item; ?>">
                                        <input type="hidden" name="id_evaluacion" value="<?php echo $id_evaluacion; ?>">
                                        <input type="hidden" name="accion" value="reiniciar">
                                        <button type="submit" class="btn btn-sm btn-warning ms-2" 
                                                onclick="return confirm('¿Estás seguro de reiniciar esta presentación?')">
                                            Reiniciar Presentación
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <a href="ver_detalles.php?id=<?php echo $id_item; ?>" class="btn btn-sm btn-info ms-2">Detalles</a>
                                <a href="evaluar_cualitativo.php?id_equipo=<?php echo $id_item; ?>" class="btn btn-sm btn-outline-secondary ms-2 mt-2">Eval. cualitativa</a>
                            <?php else: ?>
                                <!-- Para evaluaciones individuales, usar id_estudiante en lugar de id_equipo -->
                                <form action="gestionar_presentacion.php" method="POST" class="d-inline">
                                    <input type="hidden" name="id_estudiante" value="<?php echo $id_item; ?>">
                                    <input type="hidden" name="id_evaluacion" value="<?php echo $id_evaluacion; ?>">
                                    <?php if ($estado_presentacion == 'pendiente'): ?>
                                        <input type="hidden" name="accion" value="iniciar">
                                        <button type="submit" class="btn btn-sm btn-primary">Iniciar Presentación</button>
                                    <?php elseif ($estado_presentacion == 'presentando'): ?>
                                        <input type="hidden" name="accion" value="terminar">
                                        <button type="submit" class="btn btn-sm btn-success">Terminar Presentación</button>
                                    <?php endif; ?>
                                </form>
                                <?php if ($estado_presentacion == 'finalizado' || $estado_presentacion == 'presentando'): ?>
                                    <form action="gestionar_presentacion.php" method="POST" class="d-inline">
                                        <input type="hidden" name="id_estudiante" value="<?php echo $id_item; ?>">
                                        <input type="hidden" name="id_evaluacion" value="<?php echo $id_evaluacion; ?>">
                                        <input type="hidden" name="accion" value="reiniciar">
                                        <button type="submit" class="btn btn-sm btn-warning ms-2" 
                                                onclick="return confirm('¿Estás seguro de reiniciar esta presentación?')">
                                            Reiniciar Presentación
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (isset($item['id_equipo']) && $item['id_equipo']): ?>
                                    <a href="ver_detalles.php?id=<?php echo $item['id_equipo']; ?>" class="btn btn-sm btn-info ms-2">Detalles</a>
                                <?php else: ?>
                                    <a href="ver_detalles.php?id=<?php echo $id_item; ?>" class="btn btn-sm btn-info ms-2">Detalles</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-center">
                            <?php echo $es_grupal ? 'No hay equipos registrados para este curso.' : 'No hay estudiantes registrados para este curso.'; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

