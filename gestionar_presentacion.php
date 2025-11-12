<?php
require 'db.php';
// Requerir ser docente Y tener un curso activo
verificar_sesion(true);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['id_equipo']) && isset($_POST['accion'])) {
    
    $id_equipo = (int)$_POST['id_equipo'];
    $accion = $_POST['accion'];
    
    $id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;
    
    if (!$id_curso_activo) {
        header("Location: select_course.php");
        exit();
    }

    $nuevo_estado = '';
    $mensaje = '';

    switch ($accion) {
        case 'iniciar':
            $nuevo_estado = 'presentando';
            $mensaje = 'Presentación iniciada con éxito.';
            break;
        case 'terminar':
            $nuevo_estado = 'finalizado';
            $mensaje = 'Presentación finalizada con éxito.';
            break;
        default:
            // Acción inválida, redirigir sin hacer nada
            header("Location: dashboard_docente.php?error=" . urlencode("Acción inválida."));
            exit();
    }
    
    // Consulta CLAVE: Actualiza el estado, pero SOLO si el equipo pertenece al curso activo
    $stmt = $conn->prepare("UPDATE equipos SET estado_presentacion = ? WHERE id = ? AND id_curso = ?");
    $stmt->bind_param("sii", $nuevo_estado, $id_equipo, $id_curso_activo);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            header("Location: dashboard_docente.php?status=" . urlencode($mensaje));
        } else {
             // Esto ocurre si el equipo no existe O si no pertenece al curso activo.
            header("Location: dashboard_docente.php?error=" . urlencode("Error: Equipo no encontrado o no pertenece al curso activo."));
        }
    } else {
        header("Location: dashboard_docente.php?error=" . urlencode("Error al actualizar el estado: " . $stmt->error));
    }
    
    $stmt->close();
    $conn->close();
    exit();

} else {
    header("Location: dashboard_docente.php");
    exit();
}
?>