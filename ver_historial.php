<?php
require 'db.php';
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : '';
$id_item = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!in_array($tipo, ['estudiante', 'equipo']) || $id_item === 0) {
    header("Location: historial.php?error=" . urlencode("Parámetros inválidos."));
    exit();
}

// Obtener información del curso
$stmt_curso = $conn->prepare("SELECT nombre_curso, semestre, anio, rendimiento_minimo, nota_minima FROM cursos WHERE id = ?");
$stmt_curso->bind_param("i", $id_curso_activo);
$stmt_curso->execute();
$curso_activo = $stmt_curso->get_result()->fetch_assoc();
$stmt_curso->close();

$rendimiento_minimo = isset($curso_activo['rendimiento_minimo']) ? (float)$curso_activo['rendimiento_minimo'] : 60.0;
$nota_minima = isset($curso_activo['nota_minima']) ? (float)$curso_activo['nota_minima'] : 1.0;

// Calcular puntaje máximo del curso
// Obtener el máximo puntaje de las opciones y multiplicar por el número de criterios activos
$stmt_opciones = $conn->prepare("SELECT MAX(puntaje) as max_puntaje FROM opciones_evaluacion WHERE id_curso = ?");
$stmt_opciones->bind_param("i", $id_curso_activo);
$stmt_opciones->execute();
$result_opciones = $stmt_opciones->get_result()->fetch_assoc();
$max_puntaje_opcion = isset($result_opciones['max_puntaje']) ? (float)$result_opciones['max_puntaje'] : 0;
$stmt_opciones->close();

$stmt_criterios_count = $conn->prepare("SELECT COUNT(*) as total FROM criterios WHERE id_curso = ? AND activo = 1");
$stmt_criterios_count->bind_param("i", $id_curso_activo);
$stmt_criterios_count->execute();
$result_criterios = $stmt_criterios_count->get_result()->fetch_assoc();
$criterios_activos_count = isset($result_criterios['total']) ? (int)$result_criterios['total'] : 0;
$stmt_criterios_count->close();

$puntaje_total_maximo = $max_puntaje_opcion * $criterios_activos_count;

// Función para calcular nota basada en puntaje, rendimiento mínimo y puntaje total máximo
function calcular_nota_escala($puntaje, $puntaje_minimo, $puntaje_maximo, $nota_minima = 1.0) {
    if ($puntaje <= 0) return $nota_minima;
    if ($puntaje >= $puntaje_maximo) return 7.0;
    
    if ($puntaje <= $puntaje_minimo) {
        // De 0 a puntaje_minimo: nota de nota_minima a 4.0
        return $nota_minima + ($puntaje / $puntaje_minimo) * (4.0 - $nota_minima);
    } else {
        // De puntaje_minimo a puntaje_maximo: nota de 4.0 a 7.0
        return 4.0 + (($puntaje - $puntaje_minimo) / ($puntaje_maximo - $puntaje_minimo)) * 3.0;
    }
}

// Función para formatear nota sin ceros finales
function formatear_nota($nota) {
    $nota_formateada = number_format($nota, 1, '.', '');
    if (substr($nota_formateada, -2) === '.0') {
        return substr($nota_formateada, 0, -2);
    }
    return $nota_formateada;
}

$nombre_item = '';
$es_equipo_eliminado = false;
$integrantes = [];

if ($tipo === 'estudiante') {
    // Obtener información del estudiante
    $stmt = $conn->prepare("SELECT nombre, email FROM usuarios WHERE id = ? AND es_docente = 0 AND id_curso = ?");
    $stmt->bind_param("ii", $id_item, $id_curso_activo);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$item) {
        header("Location: historial.php?error=" . urlencode("Estudiante no encontrado."));
        exit();
    }
    
    $nombre_item = $item['nombre'];
    $id_equipo_para_evaluaciones = $id_item; // Para evaluaciones individuales, usar el id del estudiante
} else {
    // Obtener información del equipo
    $stmt = $conn->prepare("SELECT nombre_equipo FROM equipos WHERE id = ? AND id_curso = ?");
    $stmt->bind_param("ii", $id_item, $id_curso_activo);
    $stmt->execute();
    $equipo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($equipo) {
        $nombre_item = $equipo['nombre_equipo'];
        $id_equipo_para_evaluaciones = $id_item;
        
        // Obtener integrantes actuales
        $stmt_int = $conn->prepare("SELECT id, nombre, email FROM usuarios WHERE id_equipo = ?");
        $stmt_int->bind_param("i", $id_item);
        $stmt_int->execute();
        $integrantes_result = $stmt_int->get_result();
        while ($row = $integrantes_result->fetch_assoc()) {
            $integrantes[] = $row;
        }
        $stmt_int->close();
    } else {
        // Es un equipo eliminado
        $es_equipo_eliminado = true;
        $nombre_item = "Equipo Eliminado (ID: $id_item)";
        $id_equipo_para_evaluaciones = $id_item;
        
        // Intentar obtener integrantes históricos de los logs
        // Buscar en los logs información sobre la eliminación del equipo
        $sql_logs = "SELECT detalle, fecha FROM logs WHERE detalle LIKE ? AND accion = 'ELIMINAR' ORDER BY fecha DESC LIMIT 1";
        $stmt_logs = $conn->prepare($sql_logs);
        $patron = "%ID: $id_item%";
        $stmt_logs->bind_param("s", $patron);
        $stmt_logs->execute();
        $log_result = $stmt_logs->get_result();
        $fecha_eliminacion = null;
        if ($log_result->num_rows > 0) {
            $log = $log_result->fetch_assoc();
            $fecha_eliminacion = $log['fecha'];
        }
        $stmt_logs->close();
        
        // Intentar obtener integrantes históricos buscando estudiantes que tenían este id_equipo
        // antes de la eliminación, usando los logs o buscando en evaluaciones anteriores
        // Como los estudiantes se desasignan al eliminar el equipo, intentamos obtener
        // información de las evaluaciones realizadas antes de la eliminación
        if ($fecha_eliminacion) {
            // Buscar estudiantes que evaluaron a este equipo antes de su eliminación
            // y que podrían haber sido integrantes
            $sql_aprox = "
                SELECT DISTINCT u.id, u.nombre, u.email
                FROM usuarios u
                JOIN evaluaciones_maestro em ON u.id = em.id_evaluador
                WHERE em.id_equipo_evaluado = ?
                  AND u.es_docente = 0
                  AND u.id_curso = ?
                  AND em.fecha_evaluacion < ?
                ORDER BY em.fecha_evaluacion DESC
                LIMIT 10
            ";
            $stmt_aprox = $conn->prepare($sql_aprox);
            $stmt_aprox->bind_param("iis", $id_item, $id_curso_activo, $fecha_eliminacion);
            $stmt_aprox->execute();
            $aprox_result = $stmt_aprox->get_result();
            while ($row = $aprox_result->fetch_assoc()) {
                $integrantes[] = $row;
            }
            $stmt_aprox->close();
        } else {
            // Si no hay fecha de eliminación, buscar en todas las evaluaciones
            $sql_aprox = "
                SELECT DISTINCT u.id, u.nombre, u.email
                FROM usuarios u
                JOIN evaluaciones_maestro em ON u.id = em.id_evaluador
                WHERE em.id_equipo_evaluado = ?
                  AND u.es_docente = 0
                  AND u.id_curso = ?
                ORDER BY em.fecha_evaluacion DESC
                LIMIT 10
            ";
            $stmt_aprox = $conn->prepare($sql_aprox);
            $stmt_aprox->bind_param("ii", $id_item, $id_curso_activo);
            $stmt_aprox->execute();
            $aprox_result = $stmt_aprox->get_result();
            while ($row = $aprox_result->fetch_assoc()) {
                $integrantes[] = $row;
            }
            $stmt_aprox->close();
        }
    }
}

// Obtener todas las evaluaciones (incluso las que fueron reiniciadas/eliminadas)
// Las evaluaciones se mantienen en la base de datos incluso después de reiniciar presentaciones
$sql_evaluaciones = "
    SELECT 
        em.id AS id_evaluacion,
        em.puntaje_total,
        em.fecha_evaluacion,
        u.nombre AS nombre_evaluador,
        u.es_docente
    FROM evaluaciones_maestro em
    LEFT JOIN usuarios u ON em.id_evaluador = u.id
    WHERE em.id_equipo_evaluado = ?
      AND em.id_curso = ?
    ORDER BY em.fecha_evaluacion DESC
";
$stmt_eval = $conn->prepare($sql_evaluaciones);
$stmt_eval->bind_param("ii", $id_equipo_para_evaluaciones, $id_curso_activo);
$stmt_eval->execute();
$evaluaciones = $stmt_eval->get_result();
$stmt_eval->close();

// Obtener detalles de cada evaluación
$evaluaciones_completas = [];
$puntaje_minimo_requerido = $puntaje_total_maximo * $rendimiento_minimo / 100;

while ($eval = $evaluaciones->fetch_assoc()) {
    // Obtener detalles de criterios
    $sql_detalles = "
        SELECT ed.id_criterio, ed.puntaje, c.descripcion as nombre_criterio
        FROM evaluaciones_detalle ed
        LEFT JOIN criterios c ON ed.id_criterio = c.id
        WHERE ed.id_evaluacion = ?
        ORDER BY c.orden ASC
    ";
    $stmt_det = $conn->prepare($sql_detalles);
    $stmt_det->bind_param("i", $eval['id_evaluacion']);
    $stmt_det->execute();
    $detalles = $stmt_det->get_result();
    
    $criterios_detalle = [];
    while ($det = $detalles->fetch_assoc()) {
        // Buscar la opción que corresponde a este puntaje
        $puntaje_criterio = (float)$det['puntaje'];
        $sql_opcion = "
            SELECT nombre, puntaje 
            FROM opciones_evaluacion 
            WHERE id_curso = ? AND ABS(puntaje - ?) < 0.01
            ORDER BY orden ASC
            LIMIT 1
        ";
        $stmt_opcion = $conn->prepare($sql_opcion);
        $stmt_opcion->bind_param("id", $id_curso_activo, $puntaje_criterio);
        $stmt_opcion->execute();
        $opcion_result = $stmt_opcion->get_result();
        $opcion_nombre = null;
        if ($opcion_result->num_rows > 0) {
            $opcion = $opcion_result->fetch_assoc();
            $opcion_nombre = $opcion['nombre'];
        }
        $stmt_opcion->close();
        
        $det['opcion_nombre'] = $opcion_nombre;
        $criterios_detalle[] = $det;
    }
    $stmt_det->close();
    
    // Calcular la nota otorgada
    $puntaje_evaluacion = (float)$eval['puntaje_total'];
    $nota_otorgada = calcular_nota_escala($puntaje_evaluacion, $puntaje_minimo_requerido, $puntaje_total_maximo, $nota_minima);
    
    $eval['criterios'] = $criterios_detalle;
    $eval['puntaje_maximo'] = $puntaje_total_maximo;
    $eval['rendimiento_minimo'] = $rendimiento_minimo;
    $eval['nota_otorgada'] = $nota_otorgada;
    $evaluaciones_completas[] = $eval;
}

include 'header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial - <?php echo htmlspecialchars($nombre_item); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .card-header a[data-bs-toggle="collapse"] {
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-header a[data-bs-toggle="collapse"]:hover {
            color: #0d6efd !important;
        }
        .chevron-icon {
            transition: transform 0.3s ease;
            display: inline-block;
            font-size: 0.8em;
        }
        .card-header a[data-bs-toggle="collapse"][aria-expanded="true"] .chevron-icon {
            transform: rotate(180deg);
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Historial de Evaluaciones</h1>
            <a href="historial.php" class="btn btn-secondary">Volver al Historial</a>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="mb-0"><?php echo htmlspecialchars($nombre_item); ?></h3>
            </div>
            <div class="card-body">
                <p><strong>Curso:</strong> <?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre'] . '-' . $curso_activo['anio']); ?></p>
                
                <?php if ($tipo === 'equipo'): ?>
                    <div class="mt-3">
                        <h5>Integrantes <?php echo $es_equipo_eliminado ? '(Históricos)' : ''; ?>:</h5>
                        <?php if (!empty($integrantes)): ?>
                            <ul class="list-group">
                                <?php foreach ($integrantes as $integrante): ?>
                                    <li class="list-group-item">
                                        <?php echo htmlspecialchars($integrante['nombre']); ?> 
                                        <small class="text-muted">(<?php echo htmlspecialchars($integrante['email']); ?>)</small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted"><?php echo $es_equipo_eliminado ? 'No se pudo determinar la información de integrantes históricos.' : 'No hay integrantes asignados.'; ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <h3 class="mb-3">Evaluaciones Realizadas (<?php echo count($evaluaciones_completas); ?>)</h3>
        
        <?php if (!empty($evaluaciones_completas)): ?>
            <?php foreach ($evaluaciones_completas as $index => $eval): ?>
                <?php 
                $eval_num = count($evaluaciones_completas) - $index;
                $collapse_id = "collapse_eval_" . $eval['id_evaluacion'];
                ?>
                <div class="card mb-3">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="#<?php echo $collapse_id; ?>" 
                               class="text-decoration-none text-dark fw-bold" 
                               data-bs-toggle="collapse" 
                               role="button" 
                               aria-expanded="false" 
                               aria-controls="<?php echo $collapse_id; ?>"
                               style="cursor: pointer;">
                                <span>Evaluación #<?php echo $eval_num; ?></span>
                                <span class="chevron-icon">▼</span>
                            </a>
                            <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($eval['fecha_evaluacion'])); ?></small>
                        </div>
                    </div>
                    <div class="collapse" id="<?php echo $collapse_id; ?>">
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <p><strong>Evaluador:</strong> 
                                        <?php echo htmlspecialchars($eval['nombre_evaluador']); ?>
                                        <span class="badge bg-<?php echo $eval['es_docente'] ? 'primary' : 'secondary'; ?> ms-2">
                                            <?php echo $eval['es_docente'] ? 'Docente' : 'Estudiante'; ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <p><strong>Puntaje Máximo:</strong> <span class="badge bg-info"><?php echo number_format($eval['puntaje_maximo'], 0); ?></span></p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Puntaje Obtenido:</strong> <span class="badge bg-success fs-6"><?php echo $eval['puntaje_total']; ?></span></p>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <p><strong>Rendimiento Mínimo:</strong> <span class="badge bg-warning text-dark"><?php echo number_format($eval['rendimiento_minimo'], 2); ?>%</span></p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Nota Otorgada:</strong> <span class="badge bg-primary fs-6"><?php echo formatear_nota($eval['nota_otorgada']); ?></span></p>
                                </div>
                            </div>
                            
                            <?php if (!empty($eval['criterios'])): ?>
                                <h6>Detalle por Criterios:</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Criterio</th>
                                                <th>Opción</th>
                                                <th class="text-center">Puntaje</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($eval['criterios'] as $criterio): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($criterio['nombre_criterio'] ?? 'Criterio eliminado'); ?></td>
                                                    <td>
                                                        <?php if (!empty($criterio['opcion_nombre'])): ?>
                                                            <?php echo htmlspecialchars($criterio['opcion_nombre']); ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center"><?php echo $criterio['puntaje']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-info">
                No se han registrado evaluaciones para este <?php echo $tipo === 'estudiante' ? 'estudiante' : 'equipo'; ?>.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

