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

// Generar PDF con TCPDF
require_once('libs/tcpdf/TCPDF-6.6.2/tcpdf.php');

// Crear nueva instancia de TCPDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Configurar información del documento
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Sistema de Coevaluación');
$pdf->SetTitle('Reporte de Evaluaciones - ' . $curso['nombre_curso']);
$pdf->SetSubject('Evaluaciones del curso');
$pdf->SetKeywords('evaluaciones, coevaluación, pdf');

// Configurar márgenes
$pdf->SetMargins(15, 20, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);

// Configurar auto page breaks
$pdf->SetAutoPageBreak(TRUE, 15);

// Agregar página
$pdf->AddPage();

// Título principal
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'Reporte de Evaluaciones', 0, 1, 'C');
$pdf->Ln(5);

// Información del curso
$pdf->SetFont('helvetica', '', 12);
$curso_info = 'Curso: ' . $curso['nombre_curso'] . ' ' . $curso['semestre'] . '-' . $curso['anio'];
$pdf->Cell(0, 8, $curso_info, 0, 1, 'C');
$pdf->Ln(5);

// Filtros aplicados
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 8, 'Filtros aplicados:', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$filtros = [];
if ($id_equipo) {
    $filtros[] = 'Equipo específico seleccionado';
}
if ($fecha_desde) {
    $filtros[] = 'Desde: ' . $fecha_desde;
}
if ($fecha_hasta) {
    $filtros[] = 'Hasta: ' . $fecha_hasta;
}
$filtros[] = 'Total de evaluaciones: ' . count($evaluaciones);

foreach ($filtros as $filtro) {
    $pdf->Cell(0, 6, $filtro, 0, 1, 'L');
}
$pdf->Ln(5);

if (empty($evaluaciones)) {
    $pdf->SetFont('helvetica', 'I', 12);
    $pdf->Cell(0, 10, 'No hay evaluaciones que coincidan con los filtros seleccionados.', 0, 1, 'C');
} else {
    $contador = 1;
    foreach ($evaluaciones as $eval) {
        // Verificar si necesitamos una nueva página
        if ($pdf->GetY() > 200) {
            $pdf->AddPage();
        }

        // Encabezado de evaluación
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(0, 8, 'Evaluación #' . $contador . ' - ' . $eval['nombre_evaluado'], 1, 1, 'L', true);
        $pdf->Ln(2);

        // Información de la evaluación
        $pdf->SetFont('helvetica', '', 10);

        // Primera fila de información
        $pdf->Cell(40, 6, 'Tipo:', 0, 0, 'L');
        $pdf->Cell(50, 6, $eval['tipo_evaluado'], 0, 0, 'L');
        $pdf->Cell(40, 6, 'Evaluador:', 0, 0, 'L');
        $pdf->Cell(0, 6, $eval['nombre_evaluador'] . ' (' . ($eval['es_docente'] ? 'Docente' : 'Estudiante') . ')', 0, 1, 'L');

        // Segunda fila de información
        $pdf->Cell(40, 6, 'Fecha:', 0, 0, 'L');
        $pdf->Cell(50, 6, date('d/m/Y H:i', strtotime($eval['fecha_evaluacion'])), 0, 0, 'L');
        $pdf->Cell(40, 6, 'Puntaje Total:', 0, 0, 'L');
        $pdf->Cell(30, 6, $eval['puntaje_total'] . ' / ' . $puntaje_maximo, 0, 0, 'L');
        $pdf->Cell(20, 6, 'Nota Final:', 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(13, 110, 253);
        $pdf->Cell(0, 6, number_format($eval['nota_final'], 1), 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);

        // Tabla de detalles si existen
        if (!empty($eval['detalles'])) {
            // Encabezados de tabla
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(248, 249, 250);
            $pdf->Cell(80, 7, 'Criterio', 1, 0, 'C', true);
            $pdf->Cell(20, 7, 'Puntaje', 1, 0, 'C', true);
            $pdf->Cell(0, 7, 'Comentarios', 1, 1, 'C', true);

            // Filas de datos
            $pdf->SetFont('helvetica', '', 8);
            $fill = false;
            foreach ($eval['detalles'] as $detalle) {
                $pdf->SetFillColor(245, 245, 245);
                $comentarios = $detalle['numerical_details'] ?? 'Sin comentarios';

                // Calcular altura necesaria para la celda de comentarios
                $altura_linea = 5;
                $ancho_comentarios = $pdf->GetPageWidth() - 115; // 80 + 20 + márgenes
                $altura_comentarios = $pdf->getStringHeight($ancho_comentarios, $comentarios);
                $altura_fila = max($altura_linea, $altura_comentarios);

                $y_inicial = $pdf->GetY();

                $pdf->MultiCell(80, $altura_fila, $detalle['nombre_criterio'], 1, 'L', $fill, 0, '', $y_inicial);
                $pdf->MultiCell(20, $altura_fila, $detalle['puntaje'], 1, 'C', $fill, 0, '', $y_inicial);
                $pdf->MultiCell(0, $altura_fila, $comentarios, 1, 'L', $fill, 1, '', $y_inicial);

                $fill = !$fill;
            }
        }

        $pdf->Ln(8);
        $contador++;
    }
}

// Salida del PDF
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="evaluaciones_' . date('Y-m-d_H-i-s') . '.pdf"');
$pdf->Output('evaluaciones_' . date('Y-m-d_H-i-s') . '.pdf', 'D');
exit();

