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
}

header("Location: dashboard_docente.php");
exit();
?>