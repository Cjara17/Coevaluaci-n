<?php
require 'db.php';
verificar_sesion(true);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['escala_csv'])) {
    if ($_FILES['escala_csv']['error'] !== UPLOAD_ERR_OK) {
        header("Location: dashboard_docente.php?status=" . urlencode("Error al subir el archivo de escala."));
        exit();
    }
    $archivo = $_FILES['escala_csv']['tmp_name'];
    $conn->begin_transaction();
    try {
        $conn->query("TRUNCATE TABLE escala_notas");
        $fila = 0;
        if (($gestor = fopen($archivo, "r")) !== FALSE) {
            $stmt = $conn->prepare("INSERT INTO escala_notas (puntaje, nota) VALUES (?, ?)");
            while (($datos = fgetcsv($gestor, 1000, ",")) !== FALSE) {
                $fila++;
                if ($fila == 1 && !is_numeric($datos[0])) continue;
                if (count($datos) >= 2 && is_numeric($datos[0]) && is_numeric(str_replace(',', '.', $datos[1]))) {
                    $puntaje = (int)$datos[0];
                    $nota = (float)str_replace(',', '.', $datos[1]);
                    $stmt->bind_param("id", $puntaje, $nota);
                    $stmt->execute();
                }
            }
            fclose($gestor);
            $stmt->close();
        }
        $conn->commit();
        header("Location: dashboard_docente.php?status=" . urlencode("Escala de notas actualizada correctamente."));
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: dashboard_docente.php?status=" . urlencode("Error al procesar la escala: " . $e->getMessage()));
    }
    exit();
}
?>