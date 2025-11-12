<?php
require 'db.php';
verificar_sesion(true);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ***** CORRECCIÓN AQUÍ *****
    // Esta acción no la habíamos implementado con los nuevos cambios.
    // La elimino por ahora para evitar confusiones, ya que el modal ya no la invoca.
    // El reseteo total es más seguro.

    if ($action === 'reset_all') {
        $conn->begin_transaction();
        try {
            // ***** CORRECCIÓN AQUÍ *****
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