<?php
require 'db.php';
verificar_sesion(true);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['id_equipo'], $_POST['accion'])) {
    $id_equipo = (int)$_POST['id_equipo'];
    $accion = $_POST['accion'];

    if ($accion == 'iniciar') {
        // Asegurarse de que no haya otro equipo 'presentando'
        $conn->query("UPDATE equipos SET estado_presentacion = 'pendiente' WHERE estado_presentacion = 'presentando'");
        
        // Establecer el equipo seleccionado como 'presentando'
        $stmt = $conn->prepare("UPDATE equipos SET estado_presentacion = 'presentando' WHERE id = ?");
        $stmt->bind_param("i", $id_equipo);
        $stmt->execute();
        $stmt->close();
    } elseif ($accion == 'terminar') {
        // La acción clave: Marcar la presentación como 'finalizada'
        $stmt = $conn->prepare("UPDATE equipos SET estado_presentacion = 'finalizado' WHERE id = ?");
        $stmt->bind_param("i", $id_equipo);
        $stmt->execute();
        $stmt->close();
    }
    
    header("Location: dashboard_docente.php");
}
?>