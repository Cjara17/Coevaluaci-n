<?php
require 'db.php';
// Requerir ser docente Y tener un curso activo
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

$id_equipo = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id_equipo === 0) {
    header("Location: dashboard_docente.php?error=" . urlencode("ID de equipo no proporcionado."));
    exit();
}

// ----------------------------------------------------------------------
// 1. OBTENER INFORMACIÓN DEL EQUIPO Y VALIDAR PERTENENCIA AL CURSO ACTIVO
// ----------------------------------------------------------------------
$stmt_equipo = $conn->prepare("SELECT nombre_equipo, estado_presentacion FROM equipos WHERE id = ? AND id_curso = ?");
$stmt_equipo->bind_param("ii", $id_equipo, $id_curso_activo);
$stmt_equipo->execute();
$equipo_info = $stmt_equipo->get_result()->fetch_assoc();
$stmt_equipo->close();

if (!$equipo_info) {
    header("Location: dashboard_docente.php?error=" . urlencode("Equipo no encontrado o no pertenece a tu curso activo."));
    exit();
}
$nombre_equipo = $equipo_info['nombre_equipo'];
$estado_presentacion = $equipo_info['estado_presentacion'];

// Opcional: Obtener el nombre del curso para el título
$stmt_curso = $conn->prepare("SELECT nombre_curso, semestre FROM cursos WHERE id = ?");
$stmt_curso->bind_param("i", $id_curso_activo);
$stmt_curso->execute();
$curso_activo = $stmt_curso->get_result()->fetch_assoc();
$stmt_curso->close();


// ----------------------------------------------------------------------
// 2. OBTENER ESTUDIANTES DEL EQUIPO (Ya filtrado implícitamente por el id_equipo que pertenece al curso)
// ----------------------------------------------------------------------
$stmt_estudiantes = $conn->prepare("SELECT nombre, email FROM usuarios WHERE id_equipo = ?");
$stmt_estudiantes->bind_param("i", $id_equipo);
$stmt_estudiantes->execute();
$estudiantes = $stmt_estudiantes->get_result();

// ----------------------------------------------------------------------
// 3. OBTENER CRITERIOS DEL CURSO ACTIVO (para la tabla de promedios)
// ----------------------------------------------------------------------
$stmt_criterios = $conn->prepare("SELECT id, descripcion FROM criterios WHERE id_curso = ? AND activo = 1 ORDER BY orden ASC");
$stmt_criterios->bind_param("i", $id_curso_activo);
$stmt_criterios->execute();
$criterios_result = $stmt_criterios->get_result();

$criterios_map = [];
while ($criterio = $criterios_result->fetch_assoc()) {
    $criterios_map[$criterio['id']] = $criterio['descripcion'];
}
$stmt_criterios->close();


// ----------------------------------------------------------------------
// 4. OBTENER EVALUACIONES MAESTRAS Y DETALLES
// Se filtran las evaluaciones por el equipo Y por el curso.
// ----------------------------------------------------------------------
$sql_evaluaciones = "
    SELECT 
        em.id AS id_evaluacion, 
        em.id_evaluador,
        em.puntaje_total, 
        u.nombre AS nombre_evaluador,
        em.fecha_evaluacion
    FROM evaluaciones_maestro em
    JOIN usuarios u ON em.id_evaluador = u.id
    WHERE em.id_equipo_evaluado = ? AND em.id_curso = ?
    ORDER BY em.fecha_evaluacion DESC";
    
$stmt_evaluaciones = $conn->prepare($sql_evaluaciones);
$stmt_evaluaciones->bind_param("ii", $id_equipo, $id_curso_activo);
$stmt_evaluaciones->execute();
$evaluaciones_maestro = $stmt_evaluaciones->get_result();

$evaluaciones_detalle = [];
$puntajes_totales_criterios = [];
$num_evaluaciones = $evaluaciones_maestro->num_rows;

// Iterar sobre las evaluaciones para obtener detalles y calcular promedios
if ($num_evaluaciones > 0) {
    while($eval = $evaluaciones_maestro->fetch_assoc()){
        $id_evaluacion = $eval['id_evaluacion'];
        $evaluaciones_detalle[$id_evaluacion] = $eval;
        $evaluaciones_detalle[$id_evaluacion]['detalles'] = [];

        // Obtener los detalles de la evaluación específica
        $stmt_detalle = $conn->prepare("SELECT id_criterio, puntaje, numerical_details FROM evaluaciones_detalle WHERE id_evaluacion = ?");
        $stmt_detalle->bind_param("i", $id_evaluacion);
        $stmt_detalle->execute();
        $detalles = $stmt_detalle->get_result();

        $evaluaciones_detalle[$id_evaluacion]['descripciones'] = [];

        while ($detalle = $detalles->fetch_assoc()) {
            $id_criterio = $detalle['id_criterio'];
            $puntaje = $detalle['puntaje'];

            // Llenar el detalle para la tabla de evaluadores
            $evaluaciones_detalle[$id_evaluacion]['detalles'][$id_criterio] = $puntaje;

            // NUEVO: descripción numérica por criterio
            $evaluaciones_detalle[$id_evaluacion]['descripciones'][$id_criterio] = $detalle['numerical_details'];

            // Sumar para calcular promedio por criterio
            if (!isset($puntajes_totales_criterios[$id_criterio])) {
                $puntajes_totales_criterios[$id_criterio] = 0;
            }
            $puntajes_totales_criterios[$id_criterio] += $puntaje;
        }
        $stmt_detalle->close();
    }
}


// Calcular promedios finales por criterio
$promedios_criterios = [];
if ($num_evaluaciones > 0) {
    foreach ($puntajes_totales_criterios as $id_criterio => $total) {
        $promedios_criterios[$id_criterio] = $total / $num_evaluaciones;
    }
}

// Función para obtener la Nota Final basada en puntaje promedio (escala 1-7)
function calcular_nota_final($puntaje) {
    if ($puntaje === null) return "N/A";

    // Escala simple: puntaje de 0-100 a nota de 1.0-7.0
    $nota = 1.0 + ($puntaje / 100) * 6.0;
    
    // Límites mínimo y máximo
    if ($nota < 1.0) $nota = 1.0;
    if ($nota > 7.0) $nota = 7.0;
    
    return number_format($nota, 1);
}

// Obtener el puntaje promedio general del equipo
$sql_promedio_general = "SELECT AVG(puntaje_total) AS promedio FROM evaluaciones_maestro WHERE id_equipo_evaluado = ? AND id_curso = ?";
$stmt_promedio = $conn->prepare($sql_promedio_general);
$stmt_promedio->bind_param("ii", $id_equipo, $id_curso_activo);
$stmt_promedio->execute();
$promedio_general = $stmt_promedio->get_result()->fetch_assoc()['promedio'];
$stmt_promedio->close();

$nota_final = calcular_nota_final($promedio_general);

// ----------------------------------------------------------------------
// 5. EVALUACIONES CUALITATIVAS
// ----------------------------------------------------------------------
$sql_eval_cual = "
    SELECT ec.id,
           ec.id_evaluador,
           ec.fecha_evaluacion,
           ec.observaciones,
           u.nombre AS nombre_evaluador,
           ec.id_escala
    FROM evaluaciones_cualitativas ec
    JOIN usuarios u ON ec.id_evaluador = u.id
    WHERE ec.id_equipo_evaluado = ? AND ec.id_curso = ?
    ORDER BY ec.fecha_evaluacion DESC
";
$stmt_eval_cual = $conn->prepare($sql_eval_cual);
$stmt_eval_cual->bind_param("ii", $id_equipo, $id_curso_activo);
$stmt_eval_cual->execute();
$result_eval_cual = $stmt_eval_cual->get_result();

$evaluaciones_cualitativas = [];
$qualitative_by_evaluator = [];
while ($row = $result_eval_cual->fetch_assoc()) {
    $stmt_det_cual = $conn->prepare("
        SELECT d.id_criterio,
               c.descripcion AS criterio,
               cc.etiqueta AS concepto,
               cc.color_hex,
               d.qualitative_details
        FROM evaluaciones_cualitativas_detalle d
        JOIN criterios c ON d.id_criterio = c.id
        JOIN conceptos_cualitativos cc ON d.id_concepto = cc.id
        WHERE d.id_evaluacion = ?
        ORDER BY c.orden ASC
    ");
    $stmt_det_cual->bind_param("i", $row['id']);
    $stmt_det_cual->execute();
    $detalles = $stmt_det_cual->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_det_cual->close();

    $row['detalles'] = $detalles;
    $evaluaciones_cualitativas[] = $row;

    $evalId = (int)$row['id_evaluador'];
    if (!isset($qualitative_by_evaluator[$evalId])) {
        $qualitative_by_evaluator[$evalId] = [
            'observaciones' => $row['observaciones'],
            'fecha' => $row['fecha_evaluacion'],
            'detalles' => []
        ];
    }
    foreach ($detalles as $detalle) {
        $qualitative_by_evaluator[$evalId]['detalles'][(int)$detalle['id_criterio']] = $detalle;
    }
}
$stmt_eval_cual->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalles de Evaluación - <?php echo htmlspecialchars($nombre_equipo); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard_docente.php">
                <?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' (' . $curso_activo['semestre'] . ')'); ?>
            </a>
            <a class="btn btn-outline-light" href="dashboard_docente.php">Volver al Dashboard</a>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Detalles: <?php echo htmlspecialchars($nombre_equipo); ?></h1>
            <span class="badge bg-primary fs-5">Curso: <?php echo htmlspecialchars($curso_activo['nombre_curso']); ?></span>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center bg-light">
                    <div class="card-body">
                        <h5 class="card-title">Puntaje Promedio Total</h5>
                        <p class="card-text fs-2 fw-bold"><?php echo $promedio_general !== null ? number_format($promedio_general, 2) : 'N/A'; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center bg-light">
                    <div class="card-body">
                        <h5 class="card-title">Nota Final</h5>
                        <p class="card-text fs-2 fw-bold text-success"><?php echo $nota_final; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center bg-light">
                    <div class="card-body">
                        <h5 class="card-title">Estado de Presentación</h5>
                        <p class="card-text fs-2 fw-bold"><?php echo ucfirst(htmlspecialchars($estado_presentacion)); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <h2 class="mt-4">Miembros del Equipo</h2>
        <table class="table table-bordered">
            <thead><tr><th>Nombre</th><th>Correo</th></tr></thead>
            <tbody>
                <?php while($estudiante = $estudiantes->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($estudiante['nombre']); ?></td>
                    <td><?php echo htmlspecialchars($estudiante['email']); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <h2 class="mt-5">Promedio por Criterio de Evaluación</h2>
        <table class="table table-bordered table-striped">
            <thead><tr><th>Criterio</th><th class="text-center">Puntaje Promedio</th></tr></thead>
            <tbody>
                <?php if (!empty($promedios_criterios)): ?>
                    <?php foreach ($criterios_map as $id_criterio => $descripcion): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($descripcion); ?></td>
                        <td class="text-center fw-bold">
                            <?php echo isset($promedios_criterios[$id_criterio]) ? number_format($promedios_criterios[$id_criterio], 2) : '0.00'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="2" class="text-center">No hay evaluaciones o criterios activos para este equipo/curso.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2 class="mt-5">Detalle de Evaluaciones Individuales (<?php echo $num_evaluaciones; ?>)</h2>
        <?php if ($num_evaluaciones > 0): ?>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th rowspan="2" class="align-middle">Evaluador</th>
                            <th rowspan="2" class="align-middle text-center">Puntaje Total</th>
                            <th colspan="<?php echo count($criterios_map); ?>" class="text-center">Puntaje por Criterio</th>
                            <th rowspan="2" class="align-middle">Fecha</th>
                        </tr>
                        <tr>
                            <?php foreach ($criterios_map as $id_criterio => $descripcion): ?>
                                <th class="text-center small" title="<?php echo htmlspecialchars($descripcion); ?>">
                                    <?php echo substr($descripcion, 0, 15) . '...'; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($evaluaciones_detalle as $eval): ?>
                        <?php $qual_match = isset($qualitative_by_evaluator[$eval['id_evaluador']]) ? $qualitative_by_evaluator[$eval['id_evaluador']] : null; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($eval['nombre_evaluador']); ?></td>
                            <td class="text-center fw-bold"><?php echo $eval['puntaje_total']; ?></td>
                            <?php foreach ($criterios_map as $id_criterio => $descripcion): ?>
                                <td class="text-center align-top">
                                    <?php if (isset($eval['detalles'][$id_criterio])): ?>
                                        <div class="fw-bold"><?php echo $eval['detalles'][$id_criterio]; ?></div>

                                        <?php if (!empty($eval['descripciones'][$id_criterio])): ?>
                                            <?php $collapseId = 'numdesc-' . $eval['id_evaluacion'] . '-' . $id_criterio; ?>
                                            <small class="text-primary d-block" style="cursor:pointer;"
                                                   data-bs-toggle="collapse"
                                                   href="#<?php echo $collapseId; ?>">
                                                Ver descripción
                                            </small>

                                            <div class="collapse mt-1 small text-muted text-start" id="<?php echo $collapseId; ?>">
                                                <?php echo nl2br(htmlspecialchars($eval['descripciones'][$id_criterio])); ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($qual_match && isset($qual_match['detalles'][$id_criterio])): ?>
                                            <?php $qualDetalle = $qual_match['detalles'][$id_criterio]; ?>
                                            <div class="mt-2">
                                                <span class="badge text-white" style="background-color: <?php echo htmlspecialchars($qualDetalle['color_hex']); ?>;">
                                                    <?php echo htmlspecialchars($qualDetalle['concepto']); ?>
                                                </span>
                                                <?php if (!empty($qualDetalle['qualitative_details'])): ?>
                                                    <small class="d-block text-muted mt-1">
                                                        <?php echo nl2br(htmlspecialchars($qualDetalle['qualitative_details'])); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td><?php echo date("Y-m-d H:i", strtotime($eval['fecha_evaluacion'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">Aún no hay evaluaciones registradas para este equipo en el curso activo.</div>
        <?php endif; ?>

        <h2 class="mt-5">Evaluaciones cualitativas</h2>
        <?php if (!empty($evaluaciones_cualitativas)): ?>
            <div class="accordion" id="accordionCualitativas">
                <?php foreach ($evaluaciones_cualitativas as $index => $eval): ?>
                    <?php $collapseId = 'qual-' . $eval['id']; ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="heading-<?php echo $collapseId; ?>">
                            <button class="accordion-button <?php echo $index === 0 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?php echo $collapseId; ?>" aria-expanded="<?php echo $index === 0 ? 'true' : 'false'; ?>" aria-controls="collapse-<?php echo $collapseId; ?>">
                                <div class="w-100">
                                    <div class="d-flex justify-content-between">
                                        <span><strong><?php echo htmlspecialchars($eval['nombre_evaluador']); ?></strong></span>
                                        <small class="text-muted"><?php echo date("d/m/Y H:i", strtotime($eval['fecha_evaluacion'])); ?></small>
                                    </div>
                                    <small class="text-muted">Observaciones: <?php echo $eval['observaciones'] ? htmlspecialchars(mb_strimwidth($eval['observaciones'], 0, 90, '…', 'UTF-8')) : 'Sin comentarios'; ?></small>
                                </div>
                            </button>
                        </h2>
                        <div id="collapse-<?php echo $collapseId; ?>" class="accordion-collapse collapse <?php echo $index === 0 ? 'show' : ''; ?>" aria-labelledby="heading-<?php echo $collapseId; ?>" data-bs-parent="#accordionCualitativas">
                            <div class="accordion-body">
                                <?php if (!empty($eval['observaciones'])): ?>
                                    <p><strong>Observaciones:</strong> <?php echo nl2br(htmlspecialchars($eval['observaciones'])); ?></p>
                                <?php endif; ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle">
                                        <thead>
                                            <tr>
                                                <th>Criterio</th>
                                                <th>Concepto asignado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($eval['detalles'] as $detalle): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($detalle['criterio']); ?></td>
                                                    <td>
                                                        <span class="badge text-white" style="background-color: <?php echo htmlspecialchars($detalle['color_hex']); ?>;">
                                                            <?php echo htmlspecialchars($detalle['concepto']); ?>
                                                        </span>
                                                        <?php if (!empty($detalle['qualitative_details'])): ?>
                                                            <div class="text-muted small mt-1">
                                                                <strong>Detalle:</strong>
                                                                <?php echo htmlspecialchars($detalle['qualitative_details']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-secondary">No hay evaluaciones cualitativas registradas todavía.</div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Inicializar tooltips de Bootstrap
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>
