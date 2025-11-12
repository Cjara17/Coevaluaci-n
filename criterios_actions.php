<?php
require 'db.php';
verificar_sesion(true); // Solo docentes

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    $id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

    if (!$id_curso_activo) {
        header("Location: select_course.php");
        exit();
    }

    $status_message = "Acción realizada con éxito.";
    $error_message = "";

    // Función para redirigir fácilmente
    function redirect($status, $error = "") {
        $url = "gestionar_criterios.php";
        if (!empty($status)) {
            $url .= "?status=" . urlencode($status);
        } elseif (!empty($error)) {
            $url .= "?error=" . urlencode($error);
        }
        header("Location: " . $url);
        exit();
    }

    switch ($action) {
        // --- 1. AÑADIR NUEVO CRITERIO ---
        case 'add':
            $descripcion = trim($_POST['descripcion']);
            $orden = (int)$_POST['orden'];

            if (empty($descripcion)) {
                redirect("", "La descripción no puede estar vacía.");
            }

            // Inserción, incluyendo el id_curso_activo
            $stmt = $conn->prepare("INSERT INTO criterios (descripcion, orden, id_curso) VALUES (?, ?, ?)");
            $stmt->bind_param("sii", $descripcion, $orden, $id_curso_activo);

            if ($stmt->execute()) {
                redirect("Criterio '$descripcion' añadido al curso.");
            } else {
                $error_message = "Error al añadir el criterio: " . $stmt->error;
                redirect("", $error_message);
            }
            $stmt->close();
            break;

        // --- 2. ACTIVAR/DESACTIVAR ESTADO ---
        case 'toggle_status':
            $id_criterio = (int)$_POST['id_criterio'];

            // Obtener el estado actual y verificar pertenencia al curso
            $stmt_current = $conn->prepare("SELECT activo FROM criterios WHERE id = ? AND id_curso = ?");
            $stmt_current->bind_param("ii", $id_criterio, $id_curso_activo);
            $stmt_current->execute();
            $result = $stmt_current->get_result();

            if ($result->num_rows == 0) {
                 redirect("", "Criterio no encontrado o no pertenece al curso activo.");
            }

            $current_estado = $result->fetch_assoc()['activo'];
            $new_estado = $current_estado ? 0 : 1; // 1=Activo, 0=Inactivo
            $accion_msg = $new_estado ? 'Activado' : 'Desactivado';

            // Actualizar el estado, siempre filtrando por id_curso para seguridad
            $stmt = $conn->prepare("UPDATE criterios SET activo = ? WHERE id = ? AND id_curso = ?");
            $stmt->bind_param("iii", $new_estado, $id_criterio, $id_curso_activo);

            if ($stmt->execute()) {
                redirect("Criterio $id_criterio $accion_msg con éxito.");
            } else {
                $error_message = "Error al cambiar estado: " . $stmt->error;
                redirect("", $error_message);
            }
            $stmt->close();
            break;

        // --- 3. ELIMINAR CRITERIO ---
        case 'delete':
            if (!isset($_POST['confirm']) || $_POST['confirm'] !== 'yes') {
                redirect("Eliminación cancelada");
            }

            $id_criterio = (int)$_POST['id_criterio'];

            // Eliminar, siempre filtrando por id_curso para seguridad
            $stmt = $conn->prepare("DELETE FROM criterios WHERE id = ? AND id_curso = ?");
            $stmt->bind_param("ii", $id_criterio, $id_curso_activo);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    // Registrar log de eliminación
                    $user_id = $_SESSION['id_usuario'];
                    $now = date('Y-m-d H:i:s');
                    $detalle = "Eliminó CRITERIO ID $id_criterio";

                    $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ELIMINAR', ?, ?)");
                    $log->bind_param("iss", $user_id, $detalle, $now);
                    $log->execute();
                    $log->close();

                    redirect("Criterio $id_criterio eliminado permanentemente.");
                } else {
                    redirect("", "Criterio no encontrado o no pertenece al curso activo.");
                }
            } else {
                $error_message = "Error al eliminar criterio: " . $stmt->error;
                redirect("", $error_message);
            }
            $stmt->close();
            break;

        default:
            redirect("", "Acción inválida.");
            break;
    }
} else {
    header("Location: gestionar_criterios.php");
    exit();
}
?>
