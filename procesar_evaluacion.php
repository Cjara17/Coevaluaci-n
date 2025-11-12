<?php
require 'db.php';
// Requerir sesión activa, no importa si es docente o estudiante, ambos pueden evaluar.
// Asumimos que los estudiantes siempre tienen id_curso en sesión (seteado en login.php).
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $id_evaluador = $_SESSION['id_usuario'];
    $id_equipo_evaluado = (int)$_POST['id_equipo_evaluado'];
    
    // Obtener el ID del curso activo. Si es docente, de la sesión. Si es estudiante, de la sesión.
    // Esto funciona porque en login.php ya aseguramos que los estudiantes tengan este valor.
    if (!isset($_SESSION['id_curso_activo'])) {
         header("Location: /error.php?msg=" . urlencode("No se encontró el curso activo en la sesión."));
         exit();
    }
    $id_curso_activo = (int)$_SESSION['id_curso_activo'];
    
    $puntajes = [];
    $puntaje_total = 0;
    
    // Recopilar puntajes y calcular el total
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'criterio_') === 0) {
            $id_criterio = (int)str_replace('criterio_', '', $key);
            $puntaje = (int)$value;
            
            // Validar que el puntaje esté en un rango razonable (ej. 1 a 5, o 0 a 100)
            if ($puntaje < 0) {
                $puntaje = 0; // O manejar error
            }

            $puntajes[$id_criterio] = $puntaje;
            $puntaje_total += $puntaje;
        }
    }
    
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
        
        // 3. Confirmar transacción
        $conn->commit();
        
        // Redirección exitosa (Volver al dashboard del docente o a la página de éxito del estudiante)
        if ($_SESSION['es_docente']) {
            header("Location: dashboard_docente.php?status=" . urlencode("Evaluación registrada/actualizada con éxito."));
        } else {
             // Redirigir al estudiante a una página de éxito
            header("Location: evaluacion_exitosa.php?msg=" . urlencode("Tu coevaluación se ha guardado con éxito."));
        }
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        // Manejar el error de forma segura
        $error_msg = "Error: La evaluación no pudo ser registrada. " . $e->getMessage();
        
        if ($_SESSION['es_docente']) {
            header("Location: dashboard_docente.php?error=" . urlencode($error_msg));
        } else {
            // Enviar al estudiante a la página de error
            header("Location: /error.php?msg=" . urlencode($error_msg));
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