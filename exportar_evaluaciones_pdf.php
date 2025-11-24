<?php
require 'db.php';
verificar_sesion(true); // Solo docentes

$id_curso_activo = isset($_GET['id_curso']) ? (int)$_GET['id_curso'] : (isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null);
$id_equipo = isset($_GET['id_equipo']) ? (int)$_GET['id_equipo'] : null;
$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : null;
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

// Verificar que el curso pertenece al docente
$stmt_check = $conn->prepare("
    SELECT c.id 
    FROM cursos c 
    JOIN docente_curso dc ON c.id = dc.id_curso 
    WHERE c.id = ? AND dc.id_docente = ?
");
$stmt_check->bind_param("ii", $id_curso_activo, $_SESSION['id_usuario']);
$stmt_check->execute();
if ($stmt_check->get_result()->num_rows == 0) {
    header("Location: dashboard_docente.php?error=" . urlencode("No tienes acceso a este curso."));
    exit();
}
$stmt_check->close();

// Obtener información del curso
$stmt_curso = $conn->prepare("SELECT nombre_curso, semestre, anio FROM cursos WHERE id = ?");
$stmt_curso->bind_param("i", $id_curso_activo);
$stmt_curso->execute();
$curso = $stmt_curso->get_result()->fetch_assoc();
$stmt_curso->close();

// Función para calcular nota final
function calcular_nota_final($puntaje, $puntaje_maximo) {
    if ($puntaje <= 0) return 1.0;
    if ($puntaje >= $puntaje_maximo) return 7.0;
    return round(1.0 + ($puntaje / $puntaje_maximo) * 6.0, 1);
}

// Obtener puntaje máximo del curso
$stmt_puntaje_max = $conn->prepare("
    SELECT rendimiento_minimo, nota_minima 
    FROM cursos 
    WHERE id = ?
");
$stmt_puntaje_max->bind_param("i", $id_curso_activo);
$stmt_puntaje_max->execute();
$config_curso = $stmt_puntaje_max->get_result()->fetch_assoc();
$stmt_puntaje_max->close();

// Calcular puntaje máximo (suma de puntajes máximos de opciones por criterio)
$stmt_criterios = $conn->prepare("SELECT COUNT(*) as total FROM criterios WHERE id_curso = ? AND activo = 1");
$stmt_criterios->bind_param("i", $id_curso_activo);
$stmt_criterios->execute();
$num_criterios = $stmt_criterios->get_result()->fetch_assoc()['total'];
$stmt_criterios->close();

$stmt_max_opcion = $conn->prepare("SELECT MAX(puntaje) as max_puntaje FROM opciones_evaluacion WHERE id_curso = ? AND activo = 1");
$stmt_max_opcion->bind_param("i", $id_curso_activo);
$stmt_max_opcion->execute();
$max_puntaje_opcion = $stmt_max_opcion->get_result()->fetch_assoc()['max_puntaje'] ?? 0;
$stmt_max_opcion->close();

$puntaje_maximo = $num_criterios * $max_puntaje_opcion;

// Construir query para obtener evaluaciones
$sql_evaluaciones = "
    SELECT 
        em.id,
        em.id_equipo_evaluado,
        em.puntaje_total,
        em.fecha_evaluacion,
        u_evaluador.nombre AS nombre_evaluador,
        u_evaluador.es_docente,
        e.nombre_equipo,
        u_estudiante.nombre AS nombre_estudiante,
        u_estudiante.email AS email_estudiante
    FROM evaluaciones_maestro em
    JOIN usuarios u_evaluador ON em.id_evaluador = u_evaluador.id
    LEFT JOIN equipos e ON em.id_equipo_evaluado = e.id
    LEFT JOIN usuarios u_estudiante ON em.id_equipo_evaluado = u_estudiante.id AND u_estudiante.es_docente = 0
    WHERE em.id_curso = ?
";

$params = [$id_curso_activo];
$types = "i";

if ($id_equipo) {
    $sql_evaluaciones .= " AND em.id_equipo_evaluado = ?";
    $params[] = $id_equipo;
    $types .= "i";
}

if ($fecha_desde) {
    $sql_evaluaciones .= " AND DATE(em.fecha_evaluacion) >= ?";
    $params[] = $fecha_desde;
    $types .= "s";
}

if ($fecha_hasta) {
    $sql_evaluaciones .= " AND DATE(em.fecha_evaluacion) <= ?";
    $params[] = $fecha_hasta;
    $types .= "s";
}

$sql_evaluaciones .= " ORDER BY em.fecha_evaluacion DESC, e.nombre_equipo ASC, u_estudiante.nombre ASC";

$stmt_evaluaciones = $conn->prepare($sql_evaluaciones);
if (!empty($params)) {
    $stmt_evaluaciones->bind_param($types, ...$params);
}
$stmt_evaluaciones->execute();
$result_evaluaciones = $stmt_evaluaciones->get_result();

$evaluaciones = [];
while ($row = $result_evaluaciones->fetch_assoc()) {
    // Obtener detalles de la evaluación
    $stmt_detalle = $conn->prepare("
        SELECT 
            ed.id_criterio,
            ed.puntaje,
            ed.numerical_details,
            c.descripcion AS nombre_criterio
        FROM evaluaciones_detalle ed
        JOIN criterios c ON ed.id_criterio = c.id
        WHERE ed.id_evaluacion = ?
        ORDER BY c.orden ASC
    ");
    $stmt_detalle->bind_param("i", $row['id']);
    $stmt_detalle->execute();
    $detalles = $stmt_detalle->get_result();
    
    $row['detalles'] = [];
    while ($det = $detalles->fetch_assoc()) {
        $row['detalles'][] = $det;
    }
    $stmt_detalle->close();
    
    // Determinar nombre del evaluado
    if ($row['nombre_equipo']) {
        $row['nombre_evaluado'] = $row['nombre_equipo'];
        $row['tipo_evaluado'] = 'Equipo';
    } else {
        $row['nombre_evaluado'] = $row['nombre_estudiante'] ?? 'Estudiante #' . $row['id_equipo_evaluado'];
        $row['tipo_evaluado'] = 'Estudiante';
    }
    
    $row['nota_final'] = calcular_nota_final($row['puntaje_total'], $puntaje_maximo);
    
    $evaluaciones[] = $row;
}
$stmt_evaluaciones->close();

// Generar HTML para PDF
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exportación de Evaluaciones - <?php echo htmlspecialchars($curso['nombre_curso']); ?></title>
    <style>
        @media print {
            body { margin: 0; padding: 20px; }
            .no-print { display: none; }
            .page-break { page-break-after: always; }
        }
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            font-size: 11px;
        }
        h1 {
            text-align: center;
            margin-bottom: 10px;
            font-size: 18px;
        }
        .curso-info {
            text-align: center;
            margin-bottom: 20px;
            font-size: 12px;
        }
        .filtros {
            margin-bottom: 20px;
            padding: 10px;
            background-color: #f5f5f5;
            border-radius: 5px;
            font-size: 10px;
        }
        .evaluacion {
            margin-bottom: 30px;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
            page-break-inside: avoid;
        }
        .evaluacion-header {
            background-color: #e9ecef;
            padding: 10px;
            margin: -15px -15px 15px -15px;
            border-radius: 5px 5px 0 0;
        }
        .evaluacion-header h3 {
            margin: 0;
            font-size: 14px;
        }
        .evaluacion-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
            font-size: 10px;
        }
        .info-item {
            padding: 5px;
        }
        .info-label {
            font-weight: bold;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 10px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: left;
        }
        th {
            background-color: #f8f9fa;
            font-weight: bold;
            text-align: center;
        }
        .puntaje-total {
            font-weight: bold;
            text-align: center;
            background-color: #e9ecef;
        }
        .nota-final {
            font-size: 14px;
            font-weight: bold;
            color: #0d6efd;
        }
        .comentarios {
            margin-top: 10px;
            padding: 10px;
            background-color: #f8f9fa;
            border-left: 3px solid #0d6efd;
            font-size: 10px;
        }
        .no-print {
            text-align: center;
            margin: 20px 0;
        }
        .no-print button {
            padding: 10px 20px;
            font-size: 16px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">Imprimir / Guardar como PDF</button>
    </div>
    
    <h1>Reporte de Evaluaciones</h1>
    <div class="curso-info">
        Curso: <?php echo htmlspecialchars($curso['nombre_curso'] . ' ' . $curso['semestre'] . '-' . $curso['anio']); ?>
    </div>
    
    <div class="filtros">
        <strong>Filtros aplicados:</strong><br>
        <?php if ($id_equipo): ?>
            Equipo específico seleccionado<br>
        <?php endif; ?>
        <?php if ($fecha_desde): ?>
            Desde: <?php echo htmlspecialchars($fecha_desde); ?><br>
        <?php endif; ?>
        <?php if ($fecha_hasta): ?>
            Hasta: <?php echo htmlspecialchars($fecha_hasta); ?><br>
        <?php endif; ?>
        Total de evaluaciones: <?php echo count($evaluaciones); ?>
    </div>
    
    <?php if (empty($evaluaciones)): ?>
        <p style="text-align: center; color: #666; margin-top: 50px;">No hay evaluaciones que coincidan con los filtros seleccionados.</p>
    <?php else: ?>
        <?php foreach ($evaluaciones as $index => $eval): ?>
            <div class="evaluacion">
                <div class="evaluacion-header">
                    <h3>Evaluación #<?php echo $index + 1; ?> - <?php echo htmlspecialchars($eval['nombre_evaluado']); ?></h3>
                </div>
                
                <div class="evaluacion-info">
                    <div class="info-item">
                        <span class="info-label">Tipo:</span> <?php echo htmlspecialchars($eval['tipo_evaluado']); ?>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Evaluador:</span> <?php echo htmlspecialchars($eval['nombre_evaluador']); ?>
                        <span style="color: <?php echo $eval['es_docente'] ? '#0d6efd' : '#6c757d'; ?>;">
                            (<?php echo $eval['es_docente'] ? 'Docente' : 'Estudiante'; ?>)
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Fecha:</span> <?php echo date('d/m/Y H:i', strtotime($eval['fecha_evaluacion'])); ?>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Puntaje Total:</span> <?php echo $eval['puntaje_total']; ?> / <?php echo $puntaje_maximo; ?>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Nota Final:</span> 
                        <span class="nota-final"><?php echo number_format($eval['nota_final'], 1); ?></span>
                    </div>
                </div>
                
                <?php if (!empty($eval['detalles'])): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Criterio</th>
                                <th style="width: 80px;">Puntaje</th>
                                <th>Comentarios</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eval['detalles'] as $detalle): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($detalle['nombre_criterio']); ?></td>
                                    <td style="text-align: center;"><?php echo $detalle['puntaje']; ?></td>
                                    <td><?php echo nl2br(htmlspecialchars($detalle['numerical_details'] ?? 'Sin comentarios')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
<?php
exit();
?>

