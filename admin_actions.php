<?php
require 'db.php';
verificar_sesion(true);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];
    $id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

    if ($action === 'reset_all') {
        if (!isset($_POST['confirm']) || $_POST['confirm'] !== 'yes') {
            header("Location: dashboard_docente.php?status=Eliminación cancelada");
            exit();
        }

        $conn->begin_transaction();
        try {
            // Apuntar a las tablas correctas en el orden correcto
            $conn->query("DELETE FROM evaluaciones_detalle");
            $conn->query("DELETE FROM evaluaciones_maestro");
            $conn->query("DELETE FROM usuarios WHERE es_docente = FALSE");
            $conn->query("DELETE FROM equipos");
            $conn->query("DELETE FROM criterios"); // También borramos los criterios personalizados

            $conn->query("ALTER TABLE equipos AUTO_INCREMENT = 1");
            $conn->query("ALTER TABLE evaluaciones_maestro AUTO_INCREMENT = 1");
            $conn->query("ALTER TABLE evaluaciones_detalle AUTO_INCREMENT = 1");
            $conn->query("ALTER TABLE criterios AUTO_INCREMENT = 1");

            $conn->commit();

            // Registrar log de eliminación masiva
            $user_id = $_SESSION['id_usuario'];
            $now = date('Y-m-d H:i:s');
            $detalle = "Eliminó TODOS los datos del curso (reset_all)";

            $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ELIMINAR', ?, ?)");
            $log->bind_param("iss", $user_id, $detalle, $now);
            $log->execute();
            $log->close();

            header("Location: dashboard_docente.php?status=Plataforma reseteada para un nuevo curso.");
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: dashboard_docente.php?status=Error al resetear la plataforma: " . $e->getMessage());
        }
        exit();
    } 
    
    // --- NUEVA ACCIÓN: ACTUALIZAR ID ÚNICO DEL EQUIPO (Tarea 5.3) ---
    elseif ($action === 'update_team_id') {
        $id_equipo = (int)$_POST['id_equipo'];
        // Limpiamos el valor, permitiendo que sea NULL
        $id_unico_docente = trim($_POST['id_unico_docente']);

        if ($id_equipo === 0 || !$id_curso_activo) {
            header("Location: dashboard_docente.php?error=" . urlencode("ID de equipo o curso activo no proporcionado."));
            exit();
        }

        // Si el valor es una cadena vacía, lo tratamos como NULL para la base de datos
        if (empty($id_unico_docente)) {
            $stmt = $conn->prepare("UPDATE equipos SET id_unico_docente = NULL WHERE id = ? AND id_curso = ?");
            $stmt->bind_param("ii", $id_equipo, $id_curso_activo);
        } else {
            // Caso donde se asigna un valor
            $stmt = $conn->prepare("UPDATE equipos SET id_unico_docente = ? WHERE id = ? AND id_curso = ?");
            $stmt->bind_param("sii", $id_unico_docente, $id_equipo, $id_curso_activo);
        }

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                header("Location: dashboard_docente.php?status=" . urlencode("ID único del equipo actualizado con éxito."));
            } else {
                header("Location: dashboard_docente.php?status=" . urlencode("El ID no cambió o el equipo no fue encontrado."));
            }
        } else {
            // Código de error MySQL 1062 es para violación de llave única (ID duplicado)
            if ($conn->errno == 1062) {
                header("Location: dashboard_docente.php?error=" . urlencode("Error: El ID único '" . htmlspecialchars($id_unico_docente) . "' ya está asignado a otro equipo en este curso."));
            } else {
                header("Location: dashboard_docente.php?error=" . urlencode("Error al actualizar ID del equipo: " . $stmt->error));
            }
        }
        $stmt->close();
        exit();
    }

    // --- NUEVA ACCIÓN: FINALIZAR PRESENTACIÓN Y ACTIVAR COEVALUACIÓN ---
    elseif ($action === 'finalize_presentation') {
        $id_equipo = (int)$_POST['id_equipo'];

        if (!$id_curso_activo) {
            header("Location: dashboard_docente.php?error=" . urlencode("Curso activo no disponible."));
            exit();
        }

        // Validar que el equipo pertenece al curso activo y está presentando
        $stmt_check = $conn->prepare("SELECT nombre_equipo, estado_presentacion FROM equipos WHERE id = ? AND id_curso = ?");
        $stmt_check->bind_param("ii", $id_equipo, $id_curso_activo);
        $stmt_check->execute();
        $equipo = $stmt_check->get_result()->fetch_assoc();
        $stmt_check->close();

        if (!$equipo) {
            header("Location: dashboard_docente.php?error=" . urlencode("Equipo no encontrado o no pertenece al curso activo."));
            exit();
        }

        if ($equipo['estado_presentacion'] !== 'presentando') {
            header("Location: dashboard_docente.php?error=" . urlencode("La presentación del equipo no está en curso."));
            exit();
        }

        // Iniciar transacción
        $conn->begin_transaction();
        try {
            $user_id = $_SESSION['id_usuario'];
            $now = date('Y-m-d H:i:s');

            // Guardar datos de la presentación antes de finalizar
            $titulo_presentacion = "Presentación del Equipo " . $equipo['nombre_equipo'];
            $stmt_log_presentacion = $conn->prepare("INSERT INTO presentaciones_log (id_equipo, id_curso, titulo_presentacion) VALUES (?, ?, ?)");
            $stmt_log_presentacion->bind_param("iis", $id_equipo, $id_curso_activo, $titulo_presentacion);
            $stmt_log_presentacion->execute();
            $stmt_log_presentacion->close();

            // Marcar presentación como finalizada
            $stmt_update = $conn->prepare("UPDATE equipos SET estado_presentacion = 'finalizado' WHERE id = ? AND id_curso = ?");
            $stmt_update->bind_param("ii", $id_equipo, $id_curso_activo);
            $stmt_update->execute();
            $stmt_update->close();

            // Registrar log de auditoría
            $detalle = "Finalizó presentación del equipo '" . $equipo['nombre_equipo'] . "' (ID: $id_equipo) e inició coevaluación";
            $log_stmt = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'FINALIZAR_PRESENTACION', ?, ?)");
            $log_stmt->bind_param("iss", $user_id, $detalle, $now);
            $log_stmt->execute();
            $log_stmt->close();

            $conn->commit();

            // Redirigir inmediatamente a la interfaz de coevaluación
            header("Location: evaluar.php?id_equipo=$id_equipo");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: dashboard_docente.php?error=" . urlencode("Error al finalizar presentación: " . $e->getMessage()));
            exit();
        }
    }

    // --- NUEVA ACCIÓN: ACTUALIZAR PONDERACIONES DE DOCENTES, INVITADOS Y ESTUDIANTES ---
    elseif ($action === 'update_docente_weights') {
        if (!$id_curso_activo) {
            header("Location: dashboard_docente.php?error=" . urlencode("Curso activo no disponible."));
            exit();
        }

        // Procesar ponderaciones enviadas
        $ponderaciones_docentes = isset($_POST['ponderaciones_docentes']) ? $_POST['ponderaciones_docentes'] : [];
        $ponderaciones_invitados = isset($_POST['ponderaciones_invitados']) ? $_POST['ponderaciones_invitados'] : [];
        $ponderacion_estudiantes = isset($_POST['ponderacion_estudiantes']) ? (float)$_POST['ponderacion_estudiantes'] : 0;
        $usar_ponderacion_unica_invitados = isset($_POST['usar_ponderacion_unica_invitados']) && $_POST['usar_ponderacion_unica_invitados'] == '1';
        $ponderacion_unica_invitados = isset($_POST['ponderacion_unica_invitados']) ? (float)$_POST['ponderacion_unica_invitados'] : 0;
        $nuevos_docentes = isset($_POST['nuevos_docentes']) ? $_POST['nuevos_docentes'] : [];

        // Validar ponderación de estudiantes
        if (!is_numeric($ponderacion_estudiantes) || $ponderacion_estudiantes < 0 || $ponderacion_estudiantes > 100) {
            header("Location: dashboard_docente.php?error=" . urlencode("Ponderación de estudiantes inválida. Debe estar entre 0 y 100."));
            exit();
        }
        
        // Validar ponderación única de invitados si está activa
        if ($usar_ponderacion_unica_invitados) {
            if (!is_numeric($ponderacion_unica_invitados) || $ponderacion_unica_invitados < 0 || $ponderacion_unica_invitados > 100) {
                header("Location: dashboard_docente.php?error=" . urlencode("Ponderación única de invitados inválida. Debe estar entre 0 y 100."));
                exit();
            }
        }

        // Obtener docentes actuales del curso
        $stmt_docentes_actuales = $conn->prepare("SELECT id_docente, ponderacion FROM docente_curso WHERE id_curso = ?");
        $stmt_docentes_actuales->bind_param("i", $id_curso_activo);
        $stmt_docentes_actuales->execute();
        $result_docentes_actuales = $stmt_docentes_actuales->get_result();
        $docentes_actuales = [];
        while ($row = $result_docentes_actuales->fetch_assoc()) {
            $docentes_actuales[$row['id_docente']] = $row['ponderacion'];
        }
        $stmt_docentes_actuales->close();

        // Obtener invitados actuales del curso
        $stmt_invitados_actuales = $conn->prepare("SELECT id_invitado, ponderacion FROM invitado_curso WHERE id_curso = ?");
        $stmt_invitados_actuales->bind_param("i", $id_curso_activo);
        $stmt_invitados_actuales->execute();
        $result_invitados_actuales = $stmt_invitados_actuales->get_result();
        $invitados_actuales = [];
        while ($row = $result_invitados_actuales->fetch_assoc()) {
            $invitados_actuales[$row['id_invitado']] = $row['ponderacion'];
        }
        $stmt_invitados_actuales->close();

        // Validar que todos los id_docente en ponderaciones estén en docentes_actuales
        foreach ($ponderaciones_docentes as $id_docente => $porcentaje) {
            if (!array_key_exists($id_docente, $docentes_actuales)) {
                header("Location: dashboard_docente.php?error=" . urlencode("Docente ID $id_docente no está asociado al curso."));
                exit();
            }
            if (!is_numeric($porcentaje) || $porcentaje < 0 || $porcentaje > 100) {
                header("Location: dashboard_docente.php?error=" . urlencode("Porcentaje inválido para docente ID $id_docente. Debe estar entre 0 y 100."));
                exit();
            }
        }

        // Validar ponderaciones de invitados
        foreach ($ponderaciones_invitados as $id_invitado => $porcentaje) {
            if (!is_numeric($porcentaje) || $porcentaje < 0 || $porcentaje > 100) {
                header("Location: dashboard_docente.php?error=" . urlencode("Porcentaje inválido para invitado ID $id_invitado. Debe estar entre 0 y 100."));
                exit();
            }
            // Verificar que el invitado existe y es realmente un invitado
            $stmt_check_inv = $conn->prepare("SELECT id, es_docente, id_equipo, id_curso FROM usuarios WHERE id = ?");
            $stmt_check_inv->bind_param("i", $id_invitado);
            $stmt_check_inv->execute();
            $result_check_inv = $stmt_check_inv->get_result();
            if ($result_check_inv->num_rows == 0) {
                $stmt_check_inv->close();
                header("Location: dashboard_docente.php?error=" . urlencode("Invitado ID $id_invitado no existe."));
                exit();
            }
            $inv_user = $result_check_inv->fetch_assoc();
            if ($inv_user['es_docente'] != 0 || $inv_user['id_equipo'] !== null || $inv_user['id_curso'] !== null) {
                $stmt_check_inv->close();
                header("Location: dashboard_docente.php?error=" . urlencode("Usuario ID $id_invitado no es un invitado válido."));
                exit();
            }
            $stmt_check_inv->close();
        }

        // Validar nuevos_docentes: deben existir, ser docentes, y no estar asociados al curso
        $nuevos_validos = [];
        foreach ($nuevos_docentes as $id_nuevo) {
            if (!is_numeric($id_nuevo)) {
                header("Location: dashboard_docente.php?error=" . urlencode("ID de docente nuevo inválido: $id_nuevo."));
                exit();
            }
            $stmt_check = $conn->prepare("SELECT id, es_docente FROM usuarios WHERE id = ?");
            $stmt_check->bind_param("i", $id_nuevo);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            if ($result_check->num_rows == 0) {
                $stmt_check->close();
                header("Location: dashboard_docente.php?error=" . urlencode("Docente ID $id_nuevo no existe."));
                exit();
            }
            $user = $result_check->fetch_assoc();
            $stmt_check->close();
            if ($user['es_docente'] != 1) {
                header("Location: dashboard_docente.php?error=" . urlencode("Usuario ID $id_nuevo no es docente."));
                exit();
            }
            if (array_key_exists($id_nuevo, $docentes_actuales)) {
                header("Location: dashboard_docente.php?error=" . urlencode("Docente ID $id_nuevo ya está asociado al curso."));
                exit();
            }
            $nuevos_validos[] = $id_nuevo;
        }

        // Calcular suma total: estudiantes + docentes + invitados (según modo)
        if ($usar_ponderacion_unica_invitados) {
            // Si usa ponderación única, solo sumar ese valor
            $suma = $ponderacion_estudiantes + array_sum($ponderaciones_docentes) + $ponderacion_unica_invitados + (0 * count($nuevos_validos));
        } else {
            // Si usa ponderaciones individuales, sumar todas
            $suma = $ponderacion_estudiantes + array_sum($ponderaciones_docentes) + array_sum($ponderaciones_invitados) + (0 * count($nuevos_validos));
        }
        if (abs($suma - 100) > 0.5) {
            header("Location: dashboard_docente.php?error=" . urlencode("La suma total de ponderaciones debe ser exactamente 100%. Suma actual: " . number_format($suma, 2) . "%."));
            exit();
        }

        // Si todo válido, proceder con transacción
        $conn->begin_transaction();
        try {
            $user_id = $_SESSION['id_usuario'];
            $now = date('Y-m-d H:i:s');

            // Obtener ponderaciones anteriores antes de borrar
            $ponderaciones_anteriores = [];
            $stmt_anteriores = $conn->prepare("SELECT id_docente, ponderacion FROM docente_curso WHERE id_curso = ?");
            $stmt_anteriores->bind_param("i", $id_curso_activo);
            $stmt_anteriores->execute();
            $result_anteriores = $stmt_anteriores->get_result();
            while ($row = $result_anteriores->fetch_assoc()) {
                $ponderaciones_anteriores[$row['id_docente']] = $row['ponderacion'];
            }
            $stmt_anteriores->close();

            // 1. Actualizar ponderación de estudiantes y configuración de invitados en la tabla cursos
            $stmt_update_est = $conn->prepare("UPDATE cursos SET ponderacion_estudiantes = ?, usar_ponderacion_unica_invitados = ?, ponderacion_unica_invitados = ? WHERE id = ?");
            $usar_unica_int = $usar_ponderacion_unica_invitados ? 1 : 0;
            $stmt_update_est->bind_param("didi", $ponderacion_estudiantes, $usar_unica_int, $ponderacion_unica_invitados, $id_curso_activo);
            $stmt_update_est->execute();
            $stmt_update_est->close();

            // 2. Borrar vínculos previos en docente_curso para este curso
            $stmt_delete = $conn->prepare("DELETE FROM docente_curso WHERE id_curso = ?");
            $stmt_delete->bind_param("i", $id_curso_activo);
            $stmt_delete->execute();
            $stmt_delete->close();

            // 3. Insertar todos los docentes con su ponderación
            $todos_docentes = array_merge(array_keys($ponderaciones_docentes), $nuevos_validos);
            foreach ($todos_docentes as $id_docente) {
                $porcentaje = isset($ponderaciones_docentes[$id_docente]) ? $ponderaciones_docentes[$id_docente] : 0;
                $ponderacion_decimal = $porcentaje / 100;
                $ponderacion_anterior = isset($ponderaciones_anteriores[$id_docente]) ? $ponderaciones_anteriores[$id_docente] : 0.00;

                // Registrar en docente_curso_log antes del upsert
                $log_docente_stmt = $conn->prepare("INSERT INTO docente_curso_log (id_docente, id_curso, ponderacion_anterior, ponderacion_nueva, id_usuario_accion) VALUES (?, ?, ?, ?, ?)");
                $log_docente_stmt->bind_param("iiddd", $id_docente, $id_curso_activo, $ponderacion_anterior, $ponderacion_decimal, $user_id);
                $log_docente_stmt->execute();
                $log_docente_stmt->close();

                // Insertar log general
                $detalle_log = "Asignada ponderación " . number_format($porcentaje, 2) . "% a docente ID $id_docente en curso ID $id_curso_activo";
                $log_stmt = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ACTUALIZAR', ?, ?)");
                $log_stmt->bind_param("iss", $user_id, $detalle_log, $now);
                $log_stmt->execute();
                $log_stmt->close();

                // Insertar en docente_curso
                $stmt_insert = $conn->prepare("INSERT INTO docente_curso (id_docente, id_curso, ponderacion) VALUES (?, ?, ?)");
                $stmt_insert->bind_param("iid", $id_docente, $id_curso_activo, $ponderacion_decimal);
                $stmt_insert->execute();
                $stmt_insert->close();
            }

            // 4. Actualizar/Insertar ponderaciones de invitados (solo si NO se usa ponderación única)
            if (!$usar_ponderacion_unica_invitados) {
                foreach ($ponderaciones_invitados as $id_invitado => $porcentaje) {
                    $ponderacion_decimal = $porcentaje / 100;
                    
                    // Usar INSERT ... ON DUPLICATE KEY UPDATE
                    $stmt_inv = $conn->prepare("INSERT INTO invitado_curso (id_invitado, id_curso, ponderacion) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE ponderacion = VALUES(ponderacion)");
                    $stmt_inv->bind_param("iid", $id_invitado, $id_curso_activo, $ponderacion_decimal);
                    $stmt_inv->execute();
                    $stmt_inv->close();

                    // Insertar log
                    $detalle_log = "Asignada ponderación " . number_format($porcentaje, 2) . "% a invitado ID $id_invitado en curso ID $id_curso_activo";
                    $log_stmt = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ACTUALIZAR', ?, ?)");
                    $log_stmt->bind_param("iss", $user_id, $detalle_log, $now);
                    $log_stmt->execute();
                    $log_stmt->close();
                }
            } else {
                // Si se usa ponderación única, registrar en log
                $detalle_log = "Configurada ponderación única de invitados: " . number_format($ponderacion_unica_invitados, 2) . "% en curso ID $id_curso_activo";
                $log_stmt = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ACTUALIZAR', ?, ?)");
                $log_stmt->bind_param("iss", $user_id, $detalle_log, $now);
                $log_stmt->execute();
                $log_stmt->close();
            }

            $conn->commit();

            header("Location: dashboard_docente.php?status=" . urlencode("Ponderaciones actualizadas y docentes agregados exitosamente."));
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: dashboard_docente.php?error=" . urlencode("Error al actualizar ponderaciones: " . $e->getMessage()));
        }
        exit();
    }

    // --- NUEVA ACCIÓN: ACTUALIZAR DURACIÓN DEL TEMPORIZADOR ---
    elseif ($action === 'update_timer_duration') {
        if (!$id_curso_activo) {
            header("Location: dashboard_docente.php?error=" . urlencode("Curso activo no disponible."));
            exit();
        }

        // Verificar si hay evaluaciones iniciadas (SUBTAREA 6)
        if (hayEvaluacionesIniciadas($id_curso_activo)) {
            header("Location: dashboard_docente.php?error=" . urlencode("No se puede modificar la duración del temporizador porque ya hay evaluaciones iniciadas en este curso."));
            exit();
        }

        $duracion_minutos = isset($_POST['duracion_minutos']) ? (int)$_POST['duracion_minutos'] : null;

        // Validar duración
        if ($duracion_minutos !== null && ($duracion_minutos < 1 || $duracion_minutos > 300)) {
            header("Location: dashboard_docente.php?error=" . urlencode("La duración debe estar entre 1 y 300 minutos."));
            exit();
        }

        // Actualizar en la base de datos
        $stmt = $conn->prepare("UPDATE cursos SET duracion_minutos = ? WHERE id = ?");
        $stmt->bind_param("ii", $duracion_minutos, $id_curso_activo);
        if ($stmt->execute()) {
            $user_id = $_SESSION['id_usuario'];
            $now = date('Y-m-d H:i:s');
            $detalle = "Actualizada duración del temporizador a " . ($duracion_minutos ? $duracion_minutos . " minutos" : "sin límite") . " para el curso ID $id_curso_activo";
            $log_stmt = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ACTUALIZAR', ?, ?)");
            $log_stmt->bind_param("iss", $user_id, $detalle, $now);
            $log_stmt->execute();
            $log_stmt->close();

            header("Location: dashboard_docente.php?status=" . urlencode("Duración del temporizador actualizada exitosamente."));
        } else {
            header("Location: dashboard_docente.php?error=" . urlencode("Error al actualizar la duración: " . $stmt->error));
        }
        $stmt->close();
        exit();
    }

    // --- NUEVA ACCIÓN: ELIMINAR EVALUACIÓN ---
elseif ($action === 'eliminar_evaluacion') {
    $id_evaluacion = (int)$_POST['id_evaluacion'];

    if (!$id_curso_activo) {
        header("Location: dashboard_docente.php?error=" . urlencode("Curso activo no disponible."));
        exit();
    }

    // 1) Verificar que la evaluación pertenece al curso activo
    $stmt_check = $conn->prepare("SELECT nombre_evaluacion FROM evaluaciones WHERE id = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_evaluacion, $id_curso_activo);
    $stmt_check->execute();
    $evaluacion = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();

    if (!$evaluacion) {
        header("Location: dashboard_docente.php?error=" . urlencode("Evaluación no encontrada o no pertenece al curso activo."));
        exit();
    }

    // 2) Obtener todos los equipos evaluados en esta evaluación
    $stmt_equipos = $conn->prepare("
        SELECT id_equipo_evaluado 
        FROM evaluaciones_maestro 
        WHERE id_curso = ?
    ");
    $stmt_equipos->bind_param("i", $id_curso_activo);
    $stmt_equipos->execute();
    $result_equipos = $stmt_equipos->get_result();
    $equipos_evaluados = [];
    while ($row = $result_equipos->fetch_assoc()) {
        $equipos_evaluados[] = $row['id_equipo_evaluado'];
    }
    $stmt_equipos->close();

    // 3) Iniciar transacción
    $conn->begin_transaction();
    try {
        $user_id = $_SESSION['id_usuario'];
        $now = date('Y-m-d H:i:s');

        // 4) Eliminar datos de evaluaciones_detalle
        $stmt_detalle = $conn->prepare("DELETE FROM evaluaciones_detalle WHERE id_evaluacion = ?");
        $stmt_detalle->bind_param("i", $id_evaluacion);
        $stmt_detalle->execute();
        $stmt_detalle->close();

        // 5) Eliminar datos en evaluaciones_maestro
        if (!empty($equipos_evaluados)) {
            $stmt_maestro = $conn->prepare("
                DELETE FROM evaluaciones_maestro 
                WHERE id_curso = ? AND id_equipo_evaluado = ?
            ");

            foreach ($equipos_evaluados as $id_equipo) {
                $stmt_maestro->bind_param("ii", $id_curso_activo, $id_equipo);
                $stmt_maestro->execute();
            }

            $stmt_maestro->close();
        }

        // 6) Eliminar la evaluación principal
        $stmt_evaluacion = $conn->prepare("DELETE FROM evaluaciones WHERE id = ?");
        $stmt_evaluacion->bind_param("i", $id_evaluacion);
        $stmt_evaluacion->execute();
        $stmt_evaluacion->close();

        // 7) Registrar log de eliminación
        $detalle = "Eliminó la evaluación '" . $evaluacion['nombre_evaluacion'] . "' (ID: $id_evaluacion) y todos sus datos asociados";
        $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ELIMINAR', ?, ?)");
        $log->bind_param("iss", $user_id, $detalle, $now);
        $log->execute();
        $log->close();

        // 8) Confirmar transacción
        $conn->commit();

        header("Location: dashboard_docente.php?status=" . urlencode("Evaluación eliminada exitosamente."));
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: dashboard_docente.php?error=" . urlencode("Error al eliminar la evaluación: " . $e->getMessage()));
    }
    exit();
}

}

header("Location: dashboard_docente.php");
exit();
?>
