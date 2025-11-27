<?php
/**
 * Funciones auxiliares para manejo de temporizador de evaluaciones.
 *
 * Incluye funciones para verificar timeout, guardar automáticamente evaluaciones
 * y manejar bloqueo por tiempo.
 */

/**
 * Verifica si una evaluación ha expirado por tiempo.
 *
 * @param mysqli $conn Conexión a la base de datos
 * @param int $id_evaluacion ID de la evaluación a verificar
 * @return array Retorna array con 'expirado' (bool), 'tiempo_restante_segundos' (int), 'fin_temporizador' (string)
 */
function verificar_timeout($conn, $id_evaluacion) {
    $stmt = $conn->prepare("SELECT fin_temporizador, finalizado_por_tiempo, inicio_temporizador, id_curso FROM evaluaciones_maestro WHERE id = ?");
    $stmt->bind_param("i", $id_evaluacion);
    $stmt->execute();
    $result = $stmt->get_result();
    $evaluacion = $result->fetch_assoc();
    $stmt->close();

    if (!$evaluacion) {
        return ['expirado' => false, 'tiempo_restante_segundos' => null, 'fin_temporizador' => null];
    }

    $fin_temporizador = $evaluacion['fin_temporizador'];
    $finalizado_por_tiempo = $evaluacion['finalizado_por_tiempo'];
    $inicio_temporizador = $evaluacion['inicio_temporizador'];
    $id_curso = $evaluacion['id_curso'];

    if ($finalizado_por_tiempo) {
        // Ya está finalizado por tiempo
        return ['expirado' => true, 'tiempo_restante_segundos' => 0, 'fin_temporizador' => $fin_temporizador];
    }

    $now = new DateTime();

    if ($fin_temporizador) {
        // Si hay fin_temporizador configurado, usar ese
        $fin = new DateTime($fin_temporizador);
        $expirado = $now >= $fin;
        $tiempo_restante = $expirado ? 0 : $now->diff($fin)->s + ($now->diff($fin)->i * 60) + ($now->diff($fin)->h * 3600);
    } elseif ($inicio_temporizador) {
        // Si no hay fin_temporizador pero sí inicio_temporizador, calcular usando duración del curso
        $stmt_duracion = $conn->prepare("SELECT duracion_minutos FROM cursos WHERE id = ?");
        $stmt_duracion->bind_param("i", $id_curso);
        $stmt_duracion->execute();
        $duracion_result = $stmt_duracion->get_result();
        $duracion_minutos = $duracion_result->fetch_assoc()['duracion_minutos'] ?? null;
        $stmt_duracion->close();

        if ($duracion_minutos && $duracion_minutos > 0) {
            $inicio = new DateTime($inicio_temporizador);
            $fin = clone $inicio;
            $fin->modify("+{$duracion_minutos} minutes");
            $expirado = $now >= $fin;
            $tiempo_restante = $expirado ? 0 : $now->diff($fin)->s + ($now->diff($fin)->i * 60) + ($now->diff($fin)->h * 3600);
            $fin_temporizador = $fin->format('Y-m-d H:i:s');
        } else {
            // No hay duración configurada
            $expirado = false;
            $tiempo_restante = null;
        }
    } else {
        // No hay temporizador configurado
        $expirado = false;
        $tiempo_restante = null;
    }

    return [
        'expirado' => $expirado,
        'tiempo_restante_segundos' => $tiempo_restante,
        'fin_temporizador' => $fin_temporizador
    ];
}

/**
 * Guarda automáticamente una evaluación cuando expira el tiempo.
 *
 * @param mysqli $conn Conexión a la base de datos
 * @param int $id_evaluacion ID de la evaluación
 * @param array $datos_evaluacion Datos de la evaluación (criterios, descripciones, etc.)
 * @return bool True si se guardó correctamente, false en caso contrario
 */
function guardar_automatico_por_timeout($conn, $id_evaluacion, $datos_evaluacion) {
    // Bloqueo automático por tiempo
    // Guardado forzado al expirar
    // Lógica de finalización de coevaluación

    $conn->begin_transaction();

    try {
        // Marcar como finalizado por tiempo
        $stmt_update = $conn->prepare("UPDATE evaluaciones_maestro SET finalizado_por_tiempo = 1 WHERE id = ?");
        $stmt_update->bind_param("i", $id_evaluacion);
        $stmt_update->execute();
        $stmt_update->close();

        // Si hay datos de evaluación, guardarlos
        if (!empty($datos_evaluacion['criterios'])) {
            // Actualizar puntaje total
            $puntaje_total = array_sum($datos_evaluacion['criterios']);
            $stmt_puntaje = $conn->prepare("UPDATE evaluaciones_maestro SET puntaje_total = ? WHERE id = ?");
            $stmt_puntaje->bind_param("ii", $puntaje_total, $id_evaluacion);
            $stmt_puntaje->execute();
            $stmt_puntaje->close();

            // Guardar detalles de criterios
            $stmt_detalle = $conn->prepare("INSERT INTO evaluaciones_detalle (id_evaluacion, id_criterio, puntaje) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE puntaje = VALUES(puntaje)");
            foreach ($datos_evaluacion['criterios'] as $id_criterio => $puntaje) {
                $stmt_detalle->bind_param("iii", $id_evaluacion, $id_criterio, $puntaje);
                $stmt_detalle->execute();
            }
            $stmt_detalle->close();

            // Guardar descripciones si existen
            if (!empty($datos_evaluacion['descripciones'])) {
                // Nota: evaluaciones_detalle no tiene campo para descripciones, se necesitaría modificar la tabla
                // Por ahora, las descripciones se pierden en guardado automático
            }
        }

        $conn->commit();

        // Calcular y guardar calificación final
        $resultado = EvaluacionCalculo::calcularCalificacionFinal($id_evaluacion);
        EvaluacionCalculo::guardarCalificacionEnHistorial($id_evaluacion, $resultado["nota_final"]);

        return true;

    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}
?>
