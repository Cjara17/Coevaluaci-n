<?php
require 'db.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario']) || !$_SESSION['es_docente']) {
    http_response_code(403);
    echo "Acceso denegado: no tienes permisos para exportar la rúbrica.";
    exit;
}

$id_curso_activo = isset($_GET['id_curso']) ? intval($_GET['id_curso']) : (isset($_SESSION['id_curso_activo']) ? intval($_SESSION['id_curso_activo']) : null);
if (!$id_curso_activo || $id_curso_activo <= 0) {
    http_response_code(400);
    echo "Parámetro id_curso inválido.";
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

// Obtener criterios
$stmt_criterios = $conn->prepare("SELECT * FROM criterios WHERE id_curso = ? ORDER BY orden ASC");
$stmt_criterios->bind_param("i", $id_curso_activo);
$stmt_criterios->execute();
$criterios_result = $stmt_criterios->get_result();
$criterios = [];
while ($row = $criterios_result->fetch_assoc()) {
    $criterios[] = $row;
}
$stmt_criterios->close();

// Obtener opciones
$stmt_opciones = $conn->prepare("SELECT * FROM opciones_evaluacion WHERE id_curso = ? ORDER BY orden ASC, puntaje ASC");
$stmt_opciones->bind_param("i", $id_curso_activo);
$stmt_opciones->execute();
$opciones_result = $stmt_opciones->get_result();
$opciones = [];
while ($row = $opciones_result->fetch_assoc()) {
    $opciones[] = $row;
}
$stmt_opciones->close();

// Obtener descripciones
$descripciones = [];
if (!empty($criterios) && !empty($opciones)) {
    $ids_criterios = array_column($criterios, 'id');
    $ids_opciones = array_column($opciones, 'id');
    if (!empty($ids_criterios) && !empty($ids_opciones)) {
        $placeholders_c = implode(',', array_fill(0, count($ids_criterios), '?'));
        $placeholders_o = implode(',', array_fill(0, count($ids_opciones), '?'));
        $stmt_desc = $conn->prepare("
            SELECT id_criterio, id_opcion, descripcion 
            FROM criterio_opcion_descripciones 
            WHERE id_criterio IN ($placeholders_c) AND id_opcion IN ($placeholders_o)
        ");
        $stmt_desc->bind_param(str_repeat('i', count($ids_criterios) + count($ids_opciones)), ...array_merge($ids_criterios, $ids_opciones));
        $stmt_desc->execute();
        $desc_result = $stmt_desc->get_result();
        while ($row = $desc_result->fetch_assoc()) {
            $descripciones[$row['id_criterio']][$row['id_opcion']] = $row['descripcion'];
        }
        $stmt_desc->close();
    }
}

// Calcular puntaje total máximo
$puntaje_total_maximo = 0;
if (!empty($opciones)) {
    $max_puntaje_global = 0;
    foreach ($opciones as $opcion) {
        if ($opcion['puntaje'] > $max_puntaje_global) {
            $max_puntaje_global = $opcion['puntaje'];
        }
    }
    $criterios_activos_count = count(array_filter($criterios, function($c) { return $c['activo']; }));
    $puntaje_total_maximo = $max_puntaje_global * $criterios_activos_count;
}

// Generar nombre de archivo
$filename = 'rubrica_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $curso['nombre_curso']) . '_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$output = fopen('php://output', 'w');

// BOM para UTF-8 (ayuda con Excel)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Información del curso
fputcsv($output, ['Rúbrica de Evaluación']);
fputcsv($output, ['Curso:', $curso['nombre_curso'] . ' ' . $curso['semestre'] . '-' . $curso['anio']]);
fputcsv($output, []); // Fila vacía

// Encabezados
$headers = ['Criterios'];
foreach ($opciones as $opcion) {
    $headers[] = $opcion['nombre'] . ' (Puntaje: ' . number_format($opcion['puntaje'], 2) . ')';
}
fputcsv($output, $headers);

// Datos de criterios
foreach ($criterios as $criterio) {
    $row = [$criterio['descripcion']];
    foreach ($opciones as $opcion) {
        $descripcion = isset($descripciones[$criterio['id']][$opcion['id']]) 
            ? $descripciones[$criterio['id']][$opcion['id']] 
            : '';
        $row[] = $descripcion;
    }
    fputcsv($output, $row);
}

// Fila vacía
fputcsv($output, []);

// Puntaje total máximo
fputcsv($output, ['Puntaje Total Máximo:', number_format($puntaje_total_maximo, 2)]);

fclose($output);
exit();
?>

