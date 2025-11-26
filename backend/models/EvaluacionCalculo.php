<?php
/**
 * Modelo para cálculos de evaluaciones.
 *
 * Contiene funciones para calcular calificaciones finales de estudiantes evaluados.
 */

require_once __DIR__ . '/../../db.php';

class EvaluacionCalculo {
    public static function calcularCalificacionFinal($id_evaluacion) {
        global $conn;

        // Obtener todas las filas de evaluaciones_detalle donde id_evaluacion = $id_evaluacion
        $query = "SELECT ed.puntaje, c.puntaje_maximo, c.ponderacion, c.descripcion
                  FROM evaluaciones_detalle ed
                  JOIN criterios c ON ed.id_criterio = c.id
                  WHERE ed.id_evaluacion = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $id_evaluacion);
        $stmt->execute();
        $result = $stmt->get_result();

        $puntaje_total_base = 0.0;
        $detalles = [];

        while ($row = $result->fetch_assoc()) {
            $puntaje = $row['puntaje'];
            $puntaje_maximo = $row['puntaje_maximo'];
            $ponderacion = $row['ponderacion'];
            $criterio = $row['descripcion'];

            // Calcular puntaje normalizado
            $puntaje_normalizado = ($puntaje / $puntaje_maximo) * $ponderacion;

            // Sumar al total
            $puntaje_total_base += $puntaje_normalizado;

            // Agregar a detalles
            $detalles[] = [
                "criterio" => $criterio,
                "puntaje" => $puntaje,
                "max" => $puntaje_maximo,
                "ponderacion" => $ponderacion,
                "resultado" => $puntaje_normalizado
            ];
        }

        $stmt->close();

        // Obtener id_evaluador, id_equipo_evaluado, id_curso desde evaluaciones_maestro
        $query_maestro = "SELECT id_evaluador, id_equipo_evaluado, id_curso FROM evaluaciones_maestro WHERE id = ?";
        $stmt_maestro = $conn->prepare($query_maestro);
        $stmt_maestro->bind_param("i", $id_evaluacion);
        $stmt_maestro->execute();
        $result_maestro = $stmt_maestro->get_result();
        $maestro = $result_maestro->fetch_assoc();
        $stmt_maestro->close();

        if (!$maestro) {
            return null; // O manejar error
        }

        $id_evaluador = $maestro['id_evaluador'];
        $id_curso = $maestro['id_curso'];

        // Obtener ponderaciones del curso
        $query_curso = "SELECT ponderacion_estudiantes, usar_ponderacion_unica_invitados, ponderacion_unica_invitados FROM cursos WHERE id = ?";
        $stmt_curso = $conn->prepare($query_curso);
        $stmt_curso->bind_param("i", $id_curso);
        $stmt_curso->execute();
        $result_curso = $stmt_curso->get_result();
        $curso = $result_curso->fetch_assoc();
        $stmt_curso->close();

        $ponderacion_estudiantes = $curso['ponderacion_estudiantes'];
        $usar_ponderacion_unica_invitados = $curso['usar_ponderacion_unica_invitados'];
        $ponderacion_unica_invitados = $curso['ponderacion_unica_invitados'];

        // Determinar tipo de evaluador
        $ponderacion_final = null;

        // Verificar si es docente
        $query_docente = "SELECT ponderacion FROM docente_curso WHERE id_docente = ? AND id_curso = ?";
        $stmt_docente = $conn->prepare($query_docente);
        $stmt_docente->bind_param("ii", $id_evaluador, $id_curso);
        $stmt_docente->execute();
        $result_docente = $stmt_docente->get_result();
        if ($result_docente->num_rows > 0) {
            $docente = $result_docente->fetch_assoc();
            $ponderacion_final = $docente['ponderacion'];
        }
        $stmt_docente->close();

        if ($ponderacion_final === null) {
            // Verificar si es invitado
            $query_invitado = "SELECT ponderacion FROM invitado_curso WHERE id_invitado = ? AND id_curso = ?";
            $stmt_invitado = $conn->prepare($query_invitado);
            $stmt_invitado->bind_param("ii", $id_evaluador, $id_curso);
            $stmt_invitado->execute();
            $result_invitado = $stmt_invitado->get_result();
            if ($result_invitado->num_rows > 0) {
                $invitado = $result_invitado->fetch_assoc();
                if ($usar_ponderacion_unica_invitados) {
                    $ponderacion_final = $ponderacion_unica_invitados;
                } else {
                    $ponderacion_final = $invitado['ponderacion'];
                }
            }
            $stmt_invitado->close();
        }

        if ($ponderacion_final === null) {
            // Asumir estudiante
            $ponderacion_final = $ponderacion_estudiantes;
        }

        // Calcular nota final
        $nota_final = $puntaje_total_base * ($ponderacion_final / 100); // Asumiendo ponderaciones en porcentaje

        return [
            "puntaje_base" => $puntaje_total_base,
            "ponderacion_aplicada" => $ponderacion_final,
            "nota_final" => $nota_final,
            "detalles" => $detalles
        ];
    }

    public static function guardarCalificacionEnHistorial($id_evaluacion, $nota_final) {
        global $conn;

        // Verificar si la columna nota_final existe, y agregarla si no
        $database = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'evaluaciones_maestro' AND COLUMN_NAME = 'nota_final'
        ");
        $stmt->bind_param("s", $database);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        if ($total == 0) {
            $conn->query("ALTER TABLE evaluaciones_maestro ADD COLUMN nota_final DECIMAL(6,2) NULL AFTER puntaje_total");
        }

        // Actualizar el registro con la nota_final y timestamp actual
        $stmt = $conn->prepare("UPDATE evaluaciones_maestro SET nota_final = ?, fecha_evaluacion = NOW() WHERE id = ?");
        $stmt->bind_param("di", $nota_final, $id_evaluacion);
        $stmt->execute();
        $stmt->close();
    }
}
?>
