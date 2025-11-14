<?php
require 'db.php';
verificar_sesion(true);

// Función para calcular nota basada en puntaje (escala 1-7)
function calcular_nota_final($puntaje) {
    if ($puntaje === null) return "N/A";
    
    // Asumiendo que el puntaje es de 1 a 100
    // Fórmula: Nota = 1.0 + (Puntaje / 100) * 6.0
    $nota = 1.0 + ($puntaje / 100) * 6.0;
    
    if ($nota < 1.0) $nota = 1.0;
    if ($nota > 7.0) $nota = 7.0;
    
    return number_format($nota, 1, ',', '.');
}

$filename = "resultados_finales_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$output = fopen('php://output', 'w');

// Cabecera del archivo CSV (Incluye el nuevo ID Único)
fputcsv($output, [
    'ID Único', 
    'Equipo', 
    'Puntaje Ponderado', 
    'Nota Final', 
    'Promedio Estudiantes', 
    'Nota Docente', 
    'Total Evaluaciones Estudiantes'
]);

// Consulta SQL con el campo id_unico_docente (Tarea 5.3)
$sql = "
    SELECT 
        e.nombre_equipo, 
        e.id_unico_docente, /* <<< AGREGADO */
        e.estado_presentacion, 
        (
            SELECT AVG(em1.puntaje_total) 
            FROM evaluaciones_maestro em1 
            JOIN usuarios u1 ON em1.id_evaluador = u1.id 
            WHERE em1.id_equipo_evaluado = e.id AND u1.es_docente = FALSE
        ) as promedio_estudiantes, 
        (
            SELECT em2.puntaje_total 
            FROM evaluaciones_maestro em2 
            JOIN usuarios u2 ON em2.id_evaluador = u2.id 
            WHERE em2.id_equipo_evaluado = e.id AND u2.es_docente = TRUE 
            LIMIT 1
        ) as nota_docente, 
        (
            SELECT COUNT(em3.id) 
            FROM evaluaciones_maestro em3 
            JOIN usuarios u3 ON em3.id_evaluador = u3.id 
            WHERE em3.id_equipo_evaluado = e.id AND u3.es_docente = FALSE
        ) as total_eval_estudiantes 
    FROM equipos e 
    ORDER BY e.nombre_equipo ASC";

$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $promedio_est = $row['promedio_estudiantes'];
        $nota_doc = $row['nota_docente'];
        $puntaje_final_score = null;
        $nota_final_grado = 'N/A';
        $id_unico_reporte = $row['id_unico_docente'] ?: 'N/A'; // Usar 'N/A' si no tiene ID único

        if ($row['estado_presentacion'] == 'finalizado') {
            $promedio_est_final = ($promedio_est !== null) ? $promedio_est : 0;
            if ($nota_doc !== null) {
                // Ponderación 50% estudiantes, 50% docente
                $puntaje_final_score = ($promedio_est_final * 0.5) + ($nota_doc * 0.5);
                $nota_final_grado = calcular_nota_final($puntaje_final_score);
            }
        }
        
        fputcsv($output, [
            $id_unico_reporte, // <<< INCLUSIÓN DEL CAMPO
            $row['nombre_equipo'],
            $puntaje_final_score !== null ? number_format($puntaje_final_score, 2) : 'N/A',
            $nota_final_grado,
            $promedio_est !== null ? number_format($promedio_est, 2) : 'N/A',
            $nota_doc !== null ? number_format($nota_doc, 2) : 'N/A',
            $row['total_eval_estudiantes']
        ]);
    }
}

fclose($output);
exit();
?>