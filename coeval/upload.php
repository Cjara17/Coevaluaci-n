<?php
require 'db.php';
verificar_sesion(true); // Solo docentes

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['lista_estudiantes'])) {

    $archivo = $_FILES['lista_estudiantes']['tmp_name'];
    $nombreArchivo = $_FILES['lista_estudiantes']['name'];

    // Validaciones iniciales del archivo
    if ($_FILES['lista_estudiantes']['error'] !== UPLOAD_ERR_OK) {
        header("Location: dashboard_docente.php?status=" . urlencode("Error al subir archivo (Código: " . $_FILES['lista_estudiantes']['error'] . ")"));
        exit();
    }
    
    $extension = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
    if ($extension != 'csv') {
        header("Location: dashboard_docente.php?status=" . urlencode("Error: El archivo debe ser un CSV."));
        exit();
    }

    $fila_num = 0;
    $registros_procesados = 0;
    $errores = [];

    if (($gestor = fopen($archivo, "r")) !== FALSE) {
        $conn->begin_transaction();
        try {
            while (($datos = fgetcsv($gestor, 1000, ",")) !== FALSE) { // Lee CSV delimitado por COMA
                $fila_num++;

                // Omitir la cabecera (header) si existe
                if ($fila_num == 1 && (strcasecmp(trim($datos[0]), 'Nombre Completo') == 0 || strcasecmp(trim($datos[0]), 'Nombre') == 0)) {
                    continue; 
                }

                // Validación estricta de 3 columnas CON equipo
                if (count($datos) < 3 || empty(trim($datos[0])) || empty(trim($datos[1])) || empty(trim($datos[2]))) {
                    // Si la fila no está completamente vacía, reportar error, si no, solo ignorar (ej. línea en blanco al final)
                    if(array_filter($datos)) { 
                         $errores[] = "Fila $fila_num mal formada o datos incompletos (Nombre, Correo y Equipo son obligatorios).";
                    }
                    continue; // Saltar esta fila
                }
                
                $nombre = trim($datos[0]);
                $email = trim($datos[1]);
                $nombre_equipo = trim($datos[2]);
                $id_equipo = null;

                // --- Procesar Equipo ---
                $stmt_equipo = $conn->prepare("SELECT id FROM equipos WHERE nombre_equipo = ?");
                $stmt_equipo->bind_param("s", $nombre_equipo);
                $stmt_equipo->execute();
                $res_equipo = $stmt_equipo->get_result();
                
                if ($res_equipo->num_rows == 0) {
                    // Si el equipo no existe, lo crea
                    $stmt_insert_equipo = $conn->prepare("INSERT INTO equipos (nombre_equipo) VALUES (?)");
                    $stmt_insert_equipo->bind_param("s", $nombre_equipo);
                    $stmt_insert_equipo->execute();
                    $id_equipo = $conn->insert_id;
                    $stmt_insert_equipo->close();
                } else {
                    // Si ya existe, usa su ID
                    $id_equipo = $res_equipo->fetch_assoc()['id'];
                }
                $stmt_equipo->close();

                // --- Procesar Usuario (Estudiante) ---
                // Inserta al usuario si no existe (basado en el email ÚNICO) o actualiza su nombre y equipo si ya existe.
                $stmt_usuario = $conn->prepare("INSERT INTO usuarios (nombre, email, id_equipo, es_docente) VALUES (?, ?, ?, FALSE) 
                                                 ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), id_equipo = VALUES(id_equipo)");
                $stmt_usuario->bind_param("ssi", $nombre, $email, $id_equipo);
                $stmt_usuario->execute();
                
                if ($stmt_usuario->affected_rows > 0) {
                    $registros_procesados++;
                }
                $stmt_usuario->close();
            }
            fclose($gestor);
            $conn->commit(); // Confirmar todos los cambios si el bucle terminó sin errores fatales
            
            $mensaje = "Éxito: " . $registros_procesados . " registros procesados/actualizados.";
            if (!empty($errores)) {
                $mensaje .= " Se omitieron " . count($errores) . " filas por errores.";
            }
            header("Location: dashboard_docente.php?status=" . urlencode($mensaje));

        } catch (Exception $e) {
            $conn->rollback(); // Revertir todo si algo falló
            header("Location: dashboard_docente.php?status=" . urlencode("Error Crítico: " . $e->getMessage()));
        }
    } else {
        header("Location: dashboard_docente.php?status=" . urlencode("Error al intentar abrir el archivo CSV. Verifique permisos."));
    }
}
?>