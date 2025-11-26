<?php
require_once 'db.php';
require_once 'backend/models/EvaluacionCalculo.php';

// Insertar datos de prueba
$conn->query("INSERT INTO evaluaciones_maestro (id_evaluador, id_equipo_evaluado, id_curso, puntaje_total) VALUES (1, 1, 1, 10)");
$id_evaluacion = $conn->insert_id;

$conn->query("INSERT INTO evaluaciones_detalle (id_evaluacion, id_criterio, puntaje) VALUES ($id_evaluacion, 1, 4)");

// Llamar la función
$result = EvaluacionCalculo::calcularCalificacionFinal($id_evaluacion);

// Verificar estructura
if (is_array($result) && isset($result['puntaje_base'], $result['ponderacion_aplicada'], $result['nota_final'], $result['detalles'])) {
    echo "Estructura correcta\n";
    echo "Puntaje base: " . $result['puntaje_base'] . "\n";
    echo "Ponderacion aplicada: " . $result['ponderacion_aplicada'] . "\n";
    echo "Nota final: " . $result['nota_final'] . "\n";
    echo "Detalles: " . count($result['detalles']) . " items\n";
} else {
    echo "Error en estructura\n";
}

// Limpiar datos de prueba
$conn->query("DELETE FROM evaluaciones_detalle WHERE id_evaluacion = $id_evaluacion");
$conn->query("DELETE FROM evaluaciones_maestro WHERE id = $id_evaluacion");
?>
