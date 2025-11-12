<?php
require 'db.php';
verificar_sesion(true);

// Función para calcular nota basada en puntaje (escala 1-7)
function calcular_nota_final($puntaje) {
    if ($puntaje === null) return "N/A";
    
    $nota = 1.0 + ($puntaje / 100) * 6.0;
    
    if ($nota < 1.0) $nota = 1.0;
    if ($nota > 7.0) $nota = 7.0;
    
    return number_format($nota, 1, ',', '.');
}

$filename = "resultados_finales_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$output = fopen('php://output', 'w');

fputcsv($output, [
    'Equipo', 'Puntaje Ponderado', 'Nota Final', 'Promedio Estudiantes', 'Nota Docente', 'Total Evaluaciones Estudiantes'
]);

$sql = "SELECT e.nombre_equipo, e.estado_presentacion, (SELECT AVG(em1.puntaje_total) FROM evaluaciones_maestro em1 JOIN usuarios u1 ON em1.id_evaluador = u1.id WHERE em1.id_equipo_evaluado = e.id AND u1.es_docente = FALSE) as promedio_estudiantes, (SELECT em2.puntaje_total FROM evaluaciones_maestro em2 JOIN usuarios u2 ON em2.id_evaluador = u2.id WHERE em2.id_equipo_evaluado = e.id AND u2.es_docente = TRUE LIMIT 1) as nota_docente, (SELECT COUNT(em3.id) FROM evaluaciones_maestro em3 JOIN usuarios u3 ON em3.id_evaluador = u3.id WHERE em3.id_equipo_evaluado = e.id AND u3.es_docente = FALSE) as total_eval_estudiantes FROM equipos e ORDER BY e.nombre_equipo ASC";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $promedio_est = $row['promedio_estudiantes'];
        $nota_doc = $row['nota_docente'];
        $puntaje_final_score = null;
        $nota_final_grado = 'N/A';

        if ($row['estado_presentacion'] == 'finalizado') {
            $promedio_est_final = ($promedio_est !== null) ? $promedio_est : 0;
            if ($nota_doc !== null) {
                $puntaje_final_score = ($promedio_est_final * 0.5) + ($nota_doc * 0.5);
                $nota_final_grado = calcular_nota_final($puntaje_final_score);
            }
        }
        
        fputcsv($output, [
            $row['nombre_equipo'],
            $puntaje_final_score ? number_format($puntaje_final_score, 2, ',', '.') : 'N/A',
            $nota_final_grado,
            $promedio_est ? number_format($promedio_est, 2, ',', '.') : '0,00',
            $nota_doc ? number_format($nota_doc, 2, ',', '.') : 'N/A',
            $row['total_eval_estudiantes']
        ]);
    }
}
fclose($output);
exit();
?>