<?php
require 'db.php';
verificar_sesion(true);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

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
}

header("Location: dashboard_docente.php");
exit();
?>
