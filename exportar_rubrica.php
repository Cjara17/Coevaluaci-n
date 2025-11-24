<?php
require 'db.php';
verificar_sesion(true); // Solo docentes

$id_curso_activo = isset($_GET['id_curso']) ? (int)$_GET['id_curso'] : (isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null);

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

// Verificar si PhpSpreadsheet está disponible
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    
    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    use PhpOffice\PhpSpreadsheet\Style\Alignment;
    use PhpOffice\PhpSpreadsheet\Style\Border;
    use PhpOffice\PhpSpreadsheet\Style\Fill;
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Título
    $sheet->setCellValue('A1', 'Rúbrica de Evaluación');
    $sheet->mergeCells('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($opciones) + 1) . '1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Información del curso
    $sheet->setCellValue('A2', 'Curso: ' . $curso['nombre_curso'] . ' ' . $curso['semestre'] . '-' . $curso['anio']);
    $sheet->mergeCells('A2:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($opciones) + 1) . '2');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Fila vacía
    $sheet->setCellValue('A3', '');
    
    // Encabezados
    $col = 2; // Empezar en columna B (A es para criterios)
    $sheet->setCellValue('A4', 'Criterios');
    $sheet->getStyle('A4')->getFont()->setBold(true);
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
    
    foreach ($opciones as $opcion) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($colLetter . '4', $opcion['nombre'] . "\n(Puntaje: " . number_format($opcion['puntaje'], 2) . ")");
        $sheet->getStyle($colLetter . '4')->getFont()->setBold(true);
        $sheet->getStyle($colLetter . '4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle($colLetter . '4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
        $col++;
    }
    
    // Datos de criterios
    $row = 5;
    foreach ($criterios as $criterio) {
        $sheet->setCellValue('A' . $row, $criterio['descripcion']);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        
        $col = 2;
        foreach ($opciones as $opcion) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $descripcion = isset($descripciones[$criterio['id']][$opcion['id']]) 
                ? $descripciones[$criterio['id']][$opcion['id']] 
                : '';
            $sheet->setCellValue($colLetter . $row, $descripcion);
            $sheet->getStyle($colLetter . $row)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
            $col++;
        }
        $row++;
    }
    
    // Ajustar ancho de columnas
    $sheet->getColumnDimension('A')->setWidth(30);
    foreach ($opciones as $index => $opcion) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 2);
        $sheet->getColumnDimension($colLetter)->setWidth(25);
    }
    
    // Aplicar bordes
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($opciones) + 1);
    $lastRow = 4 + count($criterios);
    $sheet->getStyle('A4:' . $lastCol . $lastRow)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'FF000000'],
            ],
        ],
    ]);
    
    // Ajustar altura de filas
    $sheet->getRowDimension(4)->setRowHeight(40);
    for ($i = 5; $i <= $lastRow; $i++) {
        $sheet->getRowDimension($i)->setRowHeight(-1); // Auto height
    }
    
    // Calcular y mostrar puntaje total
    $puntaje_total = 0;
    foreach ($criterios as $criterio) {
        if ($criterio['activo']) {
            $max_puntaje = 0;
            foreach ($opciones as $opcion) {
                if ($opcion['puntaje'] > $max_puntaje) {
                    $max_puntaje = $opcion['puntaje'];
                }
            }
            $puntaje_total += $max_puntaje;
        }
    }
    
    $row++;
    $sheet->setCellValue('A' . $row, 'Puntaje Total Máximo: ' . number_format($puntaje_total, 2));
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->mergeCells('A' . $row . ':' . $lastCol . $row);
    
    // Generar nombre de archivo
    $filename = 'rubrica_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $curso['nombre_curso']) . '_' . date('Y-m-d') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
} else {
    // Fallback a CSV si PhpSpreadsheet no está disponible
    $filename = 'rubrica_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $curso['nombre_curso']) . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    
    // BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Encabezados
    $headers = ['Criterios'];
    foreach ($opciones as $opcion) {
        $headers[] = $opcion['nombre'] . ' (Puntaje: ' . number_format($opcion['puntaje'], 2) . ')';
    }
    fputcsv($output, $headers);
    
    // Datos
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
    
    fclose($output);
    exit();
}
?>

