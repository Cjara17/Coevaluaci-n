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

// Verificar si PhpSpreadsheet está disponible y tiene soporte PDF
$use_phpspreadsheet = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    
    // Verificar si hay una librería PDF disponible
    if (class_exists('\Dompdf\Dompdf') || class_exists('\TCPDF') || class_exists('\Mpdf\Mpdf')) {
        $use_phpspreadsheet = true;
    }
}

if ($use_phpspreadsheet) {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Título
    $sheet->setCellValue('A1', 'Rúbrica de Evaluación');
    $sheet->mergeCells('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($opciones) + 1) . '1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // Información del curso
    $sheet->setCellValue('A2', 'Curso: ' . $curso['nombre_curso'] . ' ' . $curso['semestre'] . '-' . $curso['anio']);
    $sheet->mergeCells('A2:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($opciones) + 1) . '2');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // Fila vacía
    $sheet->setCellValue('A3', '');
    
    // Encabezados
    $col = 2; // Empezar en columna B (A es para criterios)
    $sheet->setCellValue('A4', 'Criterios');
    $sheet->getStyle('A4')->getFont()->setBold(true);
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
    
    foreach ($opciones as $opcion) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($colLetter . '4', $opcion['nombre'] . "\n(Puntaje: " . number_format($opcion['puntaje'], 2) . ")");
        $sheet->getStyle($colLetter . '4')->getFont()->setBold(true);
        $sheet->getStyle($colLetter . '4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle($colLetter . '4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
        $col++;
    }
    
    // Datos de criterios
    $row = 5;
    foreach ($criterios as $criterio) {
        $sheet->setCellValue('A' . $row, $criterio['descripcion']);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP)->setWrapText(true);
        
        $col = 2;
        foreach ($opciones as $opcion) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $descripcion = isset($descripciones[$criterio['id']][$opcion['id']]) 
                ? $descripciones[$criterio['id']][$opcion['id']] 
                : '';
            $sheet->setCellValue($colLetter . $row, $descripcion);
            $sheet->getStyle($colLetter . $row)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP)->setWrapText(true);
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
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
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
    $row++;
    $sheet->setCellValue('A' . $row, 'Puntaje Total Máximo: ' . number_format($puntaje_total_maximo, 2));
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $sheet->mergeCells('A' . $row . ':' . $lastCol . $row);
    
    // Configurar orientación horizontal para mejor visualización
    $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    
    // Generar nombre de archivo
    $filename = 'rubrica_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $curso['nombre_curso']) . '_' . date('Y-m-d') . '.pdf';
    
    // Intentar usar Dompdf primero, luego TCPDF, luego Mpdf
    $pdfWriter = null;
    if (class_exists('\Dompdf\Dompdf')) {
        $className = \PhpOffice\PhpSpreadsheet\Writer\Pdf\Dompdf::class;
        \PhpOffice\PhpSpreadsheet\IOFactory::registerWriter('Pdf', $className);
        $pdfWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Pdf');
    } elseif (class_exists('\TCPDF')) {
        $className = \PhpOffice\PhpSpreadsheet\Writer\Pdf\Tcpdf::class;
        \PhpOffice\PhpSpreadsheet\IOFactory::registerWriter('Pdf', $className);
        $pdfWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Pdf');
    } elseif (class_exists('\Mpdf\Mpdf')) {
        $className = \PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf::class;
        \PhpOffice\PhpSpreadsheet\IOFactory::registerWriter('Pdf', $className);
        $pdfWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Pdf');
    }
    
    if ($pdfWriter) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $pdfWriter->save('php://output');
        exit();
    }
}

// Fallback: Generar PDF usando HTML simple (requiere que el navegador pueda imprimir a PDF)
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rúbrica de Evaluación - <?php echo htmlspecialchars($curso['nombre_curso']); ?></title>
    <style>
        @media print {
            body { margin: 0; padding: 20px; }
            .no-print { display: none; }
        }
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        h1 {
            text-align: center;
            margin-bottom: 10px;
        }
        .curso-info {
            text-align: center;
            margin-bottom: 20px;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background-color: #e0e0e0;
            font-weight: bold;
            text-align: center;
        }
        .criterio-col {
            font-weight: bold;
            width: 30%;
        }
        .puntaje-total {
            margin-top: 20px;
            font-weight: bold;
            text-align: center;
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
    
    <h1>Rúbrica de Evaluación</h1>
    <div class="curso-info">
        Curso: <?php echo htmlspecialchars($curso['nombre_curso'] . ' ' . $curso['semestre'] . '-' . $curso['anio']); ?>
    </div>
    
    <table>
        <thead>
            <tr>
                <th class="criterio-col">Criterios</th>
                <?php foreach ($opciones as $opcion): ?>
                    <th><?php echo htmlspecialchars($opcion['nombre']); ?><br>(Puntaje: <?php echo number_format($opcion['puntaje'], 2); ?>)</th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($criterios as $criterio): ?>
                <tr>
                    <td class="criterio-col"><?php echo htmlspecialchars($criterio['descripcion']); ?></td>
                    <?php foreach ($opciones as $opcion): ?>
                        <td><?php 
                            $descripcion = isset($descripciones[$criterio['id']][$opcion['id']]) 
                                ? $descripciones[$criterio['id']][$opcion['id']] 
                                : '';
                            echo nl2br(htmlspecialchars($descripcion)); 
                        ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="puntaje-total">
        Puntaje Total Máximo: <?php echo number_format($puntaje_total_maximo, 2); ?>
    </div>
</body>
</html>
<?php
exit();
?>

