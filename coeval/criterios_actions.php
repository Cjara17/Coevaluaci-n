<?php
require 'db.php';
verificar_sesion(true);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    switch ($action) {
        case 'add':
            $descripcion = $_POST['descripcion'];
            $orden = (int)$_POST['orden'];
            $stmt = $conn->prepare("INSERT INTO criterios (descripcion, orden) VALUES (?, ?)");
            $stmt->bind_param("si", $descripcion, $orden);
            $stmt->execute();
            break;

        case 'toggle_status':
            $id = (int)$_POST['id_criterio'];
            // Invierte el valor actual del campo 'activo'
            $conn->query("UPDATE criterios SET activo = NOT activo WHERE id = $id");
            break;

        case 'delete':
            $id = (int)$_POST['id_criterio'];
            $stmt = $conn->prepare("DELETE FROM criterios WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            break;
    }
}

header("Location: gestionar_criterios.php");
exit();
?>