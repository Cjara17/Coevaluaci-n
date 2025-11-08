<?php
require 'db.php';
verificar_sesion();

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['criterios'])) {
    $id_evaluador = $_SESSION['id_usuario'];
    $id_equipo_evaluado = (int)$_POST['id_equipo_evaluado'];
    $puntajes_criterios = $_POST['criterios'];

    $puntaje_total = 0;
    foreach ($puntajes_criterios as $puntaje) {
        $puntaje_total += (int)$puntaje;
    }

    $conn->begin_transaction();
    try {
        // 1. Insertar en la tabla maestra
        $stmt_maestro = $conn->prepare("INSERT INTO evaluaciones_maestro (id_evaluador, id_equipo_evaluado, puntaje_total) VALUES (?, ?, ?)");
        $stmt_maestro->bind_param("iii", $id_evaluador, $id_equipo_evaluado, $puntaje_total);
        $stmt_maestro->execute();
        $id_evaluacion_maestro = $conn->insert_id;
        $stmt_maestro->close();

        // 2. Insertar cada puntaje en la tabla de detalle
        $stmt_detalle = $conn->prepare("INSERT INTO evaluaciones_detalle (id_evaluacion, id_criterio, puntaje) VALUES (?, ?, ?)");
        foreach ($puntajes_criterios as $id_criterio => $puntaje) {
            $stmt_detalle->bind_param("iii", $id_evaluacion_maestro, $id_criterio, $puntaje);
            $stmt_detalle->execute();
        }
        $stmt_detalle->close();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        // Es mejor redirigir con un mensaje de error que mostrar un 'die'
        $error_message = urlencode("Error al procesar la evaluación: " . $e->getMessage());
        $redirect_url = $_SESSION['es_docente'] ? "dashboard_docente.php" : "dashboard_estudiante.php";
        header("Location: $redirect_url?status=$error_message");
        exit();
    }

    // --- LÓGICA DE REDIRECCIÓN ---
    // Esta sección decide a dónde enviar al usuario basado en su rol.
    if ($_SESSION['es_docente']) {
        // Si el usuario es docente, lo envía al dashboard del docente con un mensaje.
        header("Location: dashboard_docente.php?status=Evaluación enviada con éxito.");
    } else {
        // Si no es docente (es estudiante), lo envía a su dashboard con un mensaje.
        header("Location: dashboard_estudiante.php?status=success");
    }
    exit();
}
?>