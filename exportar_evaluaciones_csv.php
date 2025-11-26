<?php
require 'db.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario']) || !$_SESSION['es_docente']) {
    http_response_code(403);
    echo "Acceso denegado: no tienes permisos para exportar evaluaciones.";
    exit;
}

$id_curso_activo = isset($_GET['id_curso']) ? intval($_GET['id_curso']) : (isset($_SESSION['id_curso_activo']) ? intval($_SESSION['id_curso_activo']) : null);
if (!$id_curso_activo || $id_curso_activo <= 0) {
    http_response_code(400);
    echo "Parámetro id_curso inválido.";
    exit;
}

$id_equipo = isset($_GET['id_equipo']) ? intval($_GET['id_equipo']) : null;
if ($id_equipo !== null && $id_equipo <= 0) {
    http_response_code(400);
    echo "Parámetro id_equipo inválido.";
    exit;
}

$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : null;
if ($fecha_desde && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) {
    http_response_code(400);
    echo "Parámetro fecha_desde inválido.";
    exit;
}

$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : null;
if ($fecha_hasta && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) {
    http_response_code(400);
    echo "Parámetro fecha_hasta inválido.";
    exit;
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

// Obtener criterios para encabezados
$stmt_criterios = $conn->prepare("SELECT id, descripcion FROM criterios WHERE id_curso = ? AND activo = 1 ORDER BY orden ASC");
$stmt_criterios->bind_param("i", $id_curso_activo);
$stmt_criterios->execute();
$criterios_result = $stmt_criterios->get_result();
$criterios = [];
while ($row = $criterios_result->fetch_assoc()) {
    $criterios[] = $row;
}
$stmt_criterios->close();

// Calcular puntaje máximo
$num_criterios = count($criterios);
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

// Generar nombre de archivo
$filename = 'evaluaciones_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $curso['nombre_curso']) . '_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$output = fopen('php://output', 'w');

// BOM para UTF-8 (ayuda con Excel)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Información del curso
fputcsv($output, ['Reporte de Evaluaciones']);
fputcsv($output, ['Curso:', $curso['nombre_curso'] . ' ' . $curso['semestre'] . '-' . $curso['anio']]);
fputcsv($output, ['Fecha de exportación:', date('d/m/Y H:i')]);
fputcsv($output, []); // Fila vacía

// Encabezados
$headers = [
    'ID Evaluación',
    'Fecha',
    'Tipo Evaluado',
    'Nombre Evaluado',
    'Email Evaluado',
    'Evaluador',
    'Tipo Evaluador',
    'Puntaje Total',
    'Puntaje Máximo',
    'Nota Final'
];

// Agregar columnas para cada criterio
foreach ($criterios as $criterio) {
    $headers[] = 'Puntaje: ' . $criterio['descripcion'];
    $headers[] = 'Comentarios: ' . $criterio['descripcion'];
}

fputcsv($output, $headers);

// Datos de evaluaciones
while ($row = $result_evaluaciones->fetch_assoc()) {
    // Obtener detalles de la evaluación
    $stmt_detalle = $conn->prepare("
        SELECT 
            ed.id_criterio,
            ed.puntaje,
            ed.numerical_details
        FROM evaluaciones_detalle ed
        WHERE ed.id_evaluacion = ?
    ");
    $stmt_detalle->bind_param("i", $row['id']);
    $stmt_detalle->execute();
    $detalles_result = $stmt_detalle->get_result();
    
    $detalles_map = [];
    while ($det = $detalles_result->fetch_assoc()) {
        $detalles_map[$det['id_criterio']] = $det;
    }
    $stmt_detalle->close();
    
    // Determinar nombre del evaluado
    if ($row['nombre_equipo']) {
        $nombre_evaluado = $row['nombre_equipo'];
        $tipo_evaluado = 'Equipo';
        $email_evaluado = '';
    } else {
        $nombre_evaluado = $row['nombre_estudiante'] ?? 'Estudiante #' . $row['id_equipo_evaluado'];
        $tipo_evaluado = 'Estudiante';
        $email_evaluado = $row['email_estudiante'] ?? '';
    }
    
    $nota_final = calcular_nota_final($row['puntaje_total'], $puntaje_maximo);
    
    // Construir fila
    $csv_row = [
        $row['id'],
        date('d/m/Y H:i', strtotime($row['fecha_evaluacion'])),
        $tipo_evaluado,
        $nombre_evaluado,
        $email_evaluado,
        $row['nombre_evaluador'],
        $row['es_docente'] ? 'Docente' : 'Estudiante',
        $row['puntaje_total'],
        $puntaje_maximo,
        number_format($nota_final, 1)
    ];
    
    // Agregar datos por criterio
    foreach ($criterios as $criterio) {
        if (isset($detalles_map[$criterio['id']])) {
            $csv_row[] = $detalles_map[$criterio['id']]['puntaje'];
            $csv_row[] = $detalles_map[$criterio['id']]['numerical_details'] ?? '';
        } else {
            $csv_row[] = '';
            $csv_row[] = '';
        }
    }
    
    fputcsv($output, $csv_row);
}

$stmt_evaluaciones->close();

fclose($output);
exit();
?>

