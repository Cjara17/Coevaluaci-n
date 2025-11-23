<?php
/**
 * Procesamiento de formulario POST para guardar evaluaciones numéricas y cualitativas.
 *
 * Valida token CSRF, recopila datos de puntajes, descripciones, conceptos cualitativos y observaciones,
 * realiza inserciones/actualizaciones en tablas evaluaciones_maestro, evaluaciones_detalle,
 * evaluaciones_cualitativas y evaluaciones_cualitativas_detalle.
 *
 * Gestiona la transacción para mantener la integridad del maestro y detalle.
 * Maneja redirecciones exitosas o de error según el rol (docente o estudiante).
 *
 * Utiliza variables superglobales:
 * @global array $_POST Datos del formulario enviado con la evaluación, incluyendo:
 *      - criterios (array) Puntajes numéricos por criterio.
 *      - descripciones (array|null) Descripciones opcionales por criterio, sanitizadas.
 *      - conceptos_cualitativos (array|null) Conceptos cualitativos seleccionados por criterio.
 *      - observaciones_cualitativas (string|null) Observaciones generales cualitativas.
 * @global array $_SESSION Datos de sesión incluyendo usuario, curso activo, rol y tokens CSRF.
 * @global string $_SERVER['REQUEST_METHOD'] Método HTTP para validar POST.
 *
 * @return void Redirige a páginas de éxito o error según el resultado del procesamiento.
 */
require 'db.php';
// Requerir sesión activa, no importa si es docente o estudiante, ambos pueden evaluar.
// Si no hay id_curso_activo en sesión, lo inferiremos desde el equipo evaluado.
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Verificar CSRF token
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: index.php?error=" . urlencode("Token CSRF inválido."));
        exit();
    }

    $id_evaluador = $_SESSION['id_usuario'];
    $id_equipo_evaluado = (int)$_POST['id_equipo_evaluado'];
    
    // Obtener o inferir el ID del curso activo
    if (isset($_SESSION['id_curso_activo'])) {
        $id_curso_activo = (int)$_SESSION['id_curso_activo'];
    } else {
        // Inferir curso desde el equipo evaluado
        $stmt_curso_from_equipo = $conn->prepare("SELECT id_curso FROM equipos WHERE id = ?");
        $stmt_curso_from_equipo->bind_param("i", $id_equipo_evaluado);
        $stmt_curso_from_equipo->execute();
        $res_curso = $stmt_curso_from_equipo->get_result();
        if ($res_curso->num_rows === 1) {
            $id_curso_activo = (int)$res_curso->fetch_assoc()['id_curso'];
            // Guardar en sesión para el resto del flujo
            $_SESSION['id_curso_activo'] = $id_curso_activo;
        } else {
            // No se pudo inferir, redirigir con error a vistas existentes
            if (isset($_SESSION['es_docente']) && $_SESSION['es_docente']) {
                header("Location: dashboard_docente.php?error=" . urlencode("No se pudo determinar el curso activo."));
            } else {
                header("Location: dashboard_estudiante.php?error=" . urlencode("No se pudo determinar el curso activo."));
            }
            exit();
        }
        $stmt_curso_from_equipo->close();
    }
    
    $puntajes = [];
    $descripciones = [];
    $puntaje_total = 0;
    $criterios_meta = [];

    $stmt_meta = $conn->prepare("SELECT id, puntaje_maximo, ponderacion FROM criterios WHERE id_curso = ?");
    $stmt_meta->bind_param("i", $id_curso_activo);
    $stmt_meta->execute();
    $meta_result = $stmt_meta->get_result();
    while ($row = $meta_result->fetch_assoc()) {
        $criterios_meta[(int)$row['id']] = [
            'puntaje_maximo' => max(1, (int)($row['puntaje_maximo'] ?? 5)),
            'ponderacion' => max(0, (float)($row['ponderacion'] ?? 1)),
        ];
    }
    $stmt_meta->close();

    // Recopilar puntajes y calcular el total
    // Aceptar dos formatos:
    // a) evaluar.php actual: criterios[ID] = valor
    // b) legado: inputs 'criterio_ID' = valor
    if (isset($_POST['criterios']) && is_array($_POST['criterios'])) {
        foreach ($_POST['criterios'] as $id_criterio => $value) {
            $id_criterio = (int)$id_criterio;
            if (!isset($criterios_meta[$id_criterio])) {
                continue;
            }
            $max_permitido = $criterios_meta[$id_criterio]['puntaje_maximo'];
            $puntaje = (int)$value;
            if ($puntaje < 0) $puntaje = 0;
            if ($puntaje > $max_permitido) {
                $puntaje = $max_permitido;
            }
            $puntajes[$id_criterio] = $puntaje;
            $puntaje_total += $puntaje;
        }
    } else {
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'criterio_') === 0) {
                $id_criterio = (int)str_replace('criterio_', '', $key);
                if (!isset($criterios_meta[$id_criterio])) {
                    continue;
                }
                $max_permitido = $criterios_meta[$id_criterio]['puntaje_maximo'];
                $puntaje = (int)$value;
                if ($puntaje < 0) $puntaje = 0;
                if ($puntaje > $max_permitido) {
                    $puntaje = $max_permitido;
                }
                $puntajes[$id_criterio] = $puntaje;
                $puntaje_total += $puntaje;
            }
        }
    }

    // NUEVO: Recopilar descripciones opcionales y sanitizarlas
    if (isset($_POST['descripciones']) && is_array($_POST['descripciones'])) {
        foreach ($_POST['descripciones'] as $id_criterio => $desc) {
            $id_criterio = (int)$id_criterio;
            $descripciones[$id_criterio] = htmlspecialchars(trim($desc));
        }
    }

    // NUEVO: Conceptos cualitativos seleccionados desde la evaluación numérica
    $conceptos_cualitativos_sel = [];
    if (isset($_POST['conceptos_cualitativos']) && is_array($_POST['conceptos_cualitativos'])) {
        foreach ($_POST['conceptos_cualitativos'] as $id_criterio => $id_concepto) {
            $id_criterio = (int)$id_criterio;
            $id_concepto = (int)$id_concepto;
            if ($id_criterio > 0 && $id_concepto > 0 && isset($criterios_meta[$id_criterio])) {
                $conceptos_cualitativos_sel[$id_criterio] = $id_concepto;
            }
        }
    }
    $observaciones_cualitativas = isset($_POST['observaciones_cualitativas']) ? trim($_POST['observaciones_cualitativas']) : null;
    
    // Iniciar transacción para asegurar la integridad de maestro y detalle
    $conn->begin_transaction();
    
    try {
        // ----------------------------------------------------------------------
        // 1. EVALUACIONES_MAESTRO (INSERT/UPDATE)
        // Usamos ON DUPLICATE KEY UPDATE para re-evaluar si ya existe una evaluación
        // (Esto evita el error del UNIQUE KEY: id_evaluador, id_equipo_evaluado, id_curso)
        // ----------------------------------------------------------------------
        $stmt_maestro = $conn->prepare("
            INSERT INTO evaluaciones_maestro 
                (id_evaluador, id_equipo_evaluado, id_curso, puntaje_total) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                puntaje_total = VALUES(puntaje_total), 
                fecha_evaluacion = CURRENT_TIMESTAMP
        ");
        
        $stmt_maestro->bind_param("iiii", $id_evaluador, $id_equipo_evaluado, $id_curso_activo, $puntaje_total);
        $stmt_maestro->execute();
        
        // Obtener el ID de la evaluación insertada o actualizada
        $id_evaluacion = $conn->insert_id;
        
        // Si fue un UPDATE, el ID de la evaluación se mantiene. Hay que consultarlo:
        if ($stmt_maestro->affected_rows === 2) { // 2 filas afectadas = UPDATE en MySQL
            $stmt_fetch = $conn->prepare("SELECT id FROM evaluaciones_maestro WHERE id_evaluador = ? AND id_equipo_evaluado = ? AND id_curso = ?");
            $stmt_fetch->bind_param("iii", $id_evaluador, $id_equipo_evaluado, $id_curso_activo);
            $stmt_fetch->execute();
            $id_evaluacion = $stmt_fetch->get_result()->fetch_assoc()['id'];
            $stmt_fetch->close();

            // Si fue un UPDATE, DEBEMOS BORRAR los detalles antiguos antes de insertar los nuevos
            $stmt_delete_detalle = $conn->prepare("DELETE FROM evaluaciones_detalle WHERE id_evaluacion = ?");
            $stmt_delete_detalle->bind_param("i", $id_evaluacion);
            $stmt_delete_detalle->execute();

            // Registrar log de eliminación de detalles de evaluación
            $user_id = $_SESSION['id_usuario'];
            $now = date('Y-m-d H:i:s');
            $detalle = "Eliminó DETALLE DE EVALUACIÓN ID $id_evaluacion";

            $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ELIMINAR', ?, ?)");
            $log->bind_param("iss", $user_id, $detalle, $now);
            $log->execute();
            $log->close();

            $stmt_delete_detalle->close();
        }


        // ----------------------------------------------------------------------
        // 2. EVALUACIONES_DETALLE (INSERT)
        // ----------------------------------------------------------------------
        $stmt_detalle = $conn->prepare("INSERT INTO evaluaciones_detalle (id_evaluacion, id_criterio, puntaje) VALUES (?, ?, ?)");
        
        foreach ($puntajes as $id_criterio => $puntaje) {
            $stmt_detalle->bind_param("iii", $id_evaluacion, $id_criterio, $puntaje);
            $stmt_detalle->execute();
        }
        
        // 3. Registrar evaluación cualitativa opcional (si se seleccionaron conceptos)
        if (!empty($conceptos_cualitativos_sel)) {
            $escala_cualitativa = get_primary_scale($conn, $id_curso_activo);

            if ($escala_cualitativa) {
                // Validar que los conceptos pertenezcan a la escala
                $conceptos_validos = [];
                $stmt_conceptos = $conn->prepare("SELECT id FROM conceptos_cualitativos WHERE id_escala = ? AND activo = 1");
                $stmt_conceptos->bind_param("i", $escala_cualitativa['id']);
                $stmt_conceptos->execute();
                $result_conceptos = $stmt_conceptos->get_result();
                while ($row = $result_conceptos->fetch_assoc()) {
                    $conceptos_validos[(int)$row['id']] = true;
                }
                $stmt_conceptos->close();

                $conceptos_filtrados = [];
                foreach ($conceptos_cualitativos_sel as $id_criterio => $id_concepto) {
                    if (isset($conceptos_validos[$id_concepto])) {
                        $conceptos_filtrados[$id_criterio] = $id_concepto;
                    }
                }

                if (!empty($conceptos_filtrados)) {
                    $stmt_cual = $conn->prepare("
                        INSERT INTO evaluaciones_cualitativas (id_evaluador, id_equipo_evaluado, id_curso, id_escala, observaciones)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            id_escala = VALUES(id_escala),
                            observaciones = VALUES(observaciones),
                            fecha_evaluacion = CURRENT_TIMESTAMP
                    ");
                    $stmt_cual->bind_param("iiiis", $id_evaluador, $id_equipo_evaluado, $id_curso_activo, $escala_cualitativa['id'], $observaciones_cualitativas);
                    $stmt_cual->execute();

                    $id_eval_cual = $conn->insert_id;
                    if ($stmt_cual->affected_rows === 2) {
                        $stmt_fetch_cual = $conn->prepare("SELECT id FROM evaluaciones_cualitativas WHERE id_evaluador = ? AND id_equipo_evaluado = ? AND id_curso = ?");
                        $stmt_fetch_cual->bind_param("iii", $id_evaluador, $id_equipo_evaluado, $id_curso_activo);
                        $stmt_fetch_cual->execute();
                        $id_eval_cual = (int)$stmt_fetch_cual->get_result()->fetch_assoc()['id'];
                        $stmt_fetch_cual->close();

                        $stmt_delete_cual = $conn->prepare("DELETE FROM evaluaciones_cualitativas_detalle WHERE id_evaluacion = ?");
                        $stmt_delete_cual->bind_param("i", $id_eval_cual);
                        $stmt_delete_cual->execute();
                        $stmt_delete_cual->close();
                    }

                    $stmt_det_cual = $conn->prepare("
                        INSERT INTO evaluaciones_cualitativas_detalle (id_evaluacion, id_criterio, id_concepto, qualitative_details)
                        VALUES (?, ?, ?, ?)
                    ");
                    foreach ($conceptos_filtrados as $id_criterio => $id_concepto) {
                        $detalle_texto = null;
                        $stmt_det_cual->bind_param("iiis", $id_eval_cual, $id_criterio, $id_concepto, $detalle_texto);
                        $stmt_det_cual->execute();
                    }
                    $stmt_det_cual->close();
                    $stmt_cual->close();
                }
            }
        }

        // 4. Confirmar transacción
        $conn->commit();
        
        // Redirección exitosa (Volver al dashboard del docente o a la página de éxito del estudiante)
        header("Location: evaluacion_exitosa.php?msg=" . urlencode("Tu coevaluación se ha guardado con éxito."));
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        // Manejar el error de forma segura
        $error_msg = "Error: La evaluación no pudo ser registrada. " . $e->getMessage();
        
        if ($_SESSION['es_docente']) {
            header("Location: dashboard_docente.php?error=" . urlencode($error_msg));
        } else {
            // Redirigir a dashboard estudiante con mensaje de error
            header("Location: dashboard_estudiante.php?error=" . urlencode($error_msg));
        }
        exit();
    }
} else {
    // Si acceden directamente sin POST
    if ($_SESSION['es_docente']) {
        header("Location: dashboard_docente.php");
    } else {
        header("Location: dashboard_estudiante.php");
    }
    exit();
}
?>