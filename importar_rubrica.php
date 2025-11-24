<?php
require 'db.php';
verificar_sesion(true); // Solo docentes

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

// Verificar que el curso pertenece al docente
$stmt_check = $conn->prepare("
    SELECT c.id 
    FROM cursos c 
    JOIN docente_curso dc ON c.id = dc.id_curso 
    WHERE c.id = ? AND dc.id_docente = ?
");
$stmt_check->bind_param("ii", $id_curso_activo, $_SESSION['id_usuario']);
$stmt_check->execute();
if ($stmt_check->get_result()->num_rows == 0) {
    header("Location: dashboard_docente.php?error=" . urlencode("No tienes acceso a este curso."));
    exit();
}
$stmt_check->close();

$error = '';
$success = false;
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_csv'])) {
    if ($_FILES['archivo_csv']['error'] === UPLOAD_ERR_OK) {
        $archivo_tmp = $_FILES['archivo_csv']['tmp_name'];
        $archivo_nombre = $_FILES['archivo_csv']['name'];
        
        // Verificar extensión
        $extension = strtolower(pathinfo($archivo_nombre, PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            $error = 'El archivo debe ser un CSV (.csv)';
        } else {
            // Leer archivo CSV
            $handle = fopen($archivo_tmp, 'r');
            if ($handle === false) {
                $error = 'No se pudo leer el archivo';
            } else {
                // Leer BOM si existe (UTF-8)
                $bom = fread($handle, 3);
                if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) {
                    rewind($handle);
                }
                
                $lineas = [];
                while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                    $lineas[] = $data;
                }
                fclose($handle);
                
                if (empty($lineas)) {
                    $error = 'El archivo CSV está vacío';
                } else {
                    // Buscar la fila de encabezados (buscar "Criterios" en la primera columna)
                    $header_row = -1;
                    for ($i = 0; $i < count($lineas); $i++) {
                        if (!empty($lineas[$i][0]) && stripos($lineas[$i][0], 'Criterios') !== false) {
                            $header_row = $i;
                            break;
                        }
                    }
                    
                    if ($header_row === -1) {
                        $error = 'No se encontró la fila de encabezados con "Criterios"';
                    } else {
                        // Extraer opciones de los encabezados
                        $headers = $lineas[$header_row];
                        $opciones_data = [];
                        
                        // Procesar encabezados (empezar desde la columna 1, la 0 es "Criterios")
                        for ($col = 1; $col < count($headers); $col++) {
                            $header_text = trim($headers[$col]);
                            if (!empty($header_text)) {
                                // Formato esperado: "Nombre Opción (Puntaje: X.XX)"
                                $puntaje = 0;
                                $nombre = $header_text;
                                
                                // Intentar extraer puntaje
                                if (preg_match('/\(Puntaje:\s*([0-9]+\.?[0-9]*)\)/i', $header_text, $matches)) {
                                    $puntaje = floatval($matches[1]);
                                    $nombre = trim(preg_replace('/\s*\(Puntaje:\s*[0-9]+\.?[0-9]*\)\s*/i', '', $header_text));
                                }
                                
                                $opciones_data[] = [
                                    'nombre' => $nombre,
                                    'puntaje' => $puntaje,
                                    'orden' => count($opciones_data) + 1
                                ];
                            }
                        }
                        
                        if (empty($opciones_data)) {
                            $error = 'No se encontraron opciones válidas en los encabezados';
                        } else {
                            // Procesar criterios y descripciones
                            $criterios_data = [];
                            $descripciones_data = [];
                            
                            for ($row = $header_row + 1; $row < count($lineas); $row++) {
                                $fila = $lineas[$row];
                                
                                // Saltar filas vacías
                                if (empty($fila[0]) || trim($fila[0]) === '') {
                                    continue;
                                }
                                
                                // Si encontramos "Puntaje Total Máximo", terminamos
                                if (stripos($fila[0], 'Puntaje Total') !== false) {
                                    break;
                                }
                                
                                $criterio_nombre = trim($fila[0]);
                                if (!empty($criterio_nombre)) {
                                    $criterio_id_temp = count($criterios_data);
                                    $criterios_data[] = [
                                        'descripcion' => $criterio_nombre,
                                        'orden' => count($criterios_data) + 1
                                    ];
                                    
                                    // Procesar descripciones para cada opción
                                    for ($col = 1; $col < count($fila) && ($col - 1) < count($opciones_data); $col++) {
                                        $descripcion = trim($fila[$col]);
                                        if (!empty($descripcion)) {
                                            $descripciones_data[] = [
                                                'criterio_index' => $criterio_id_temp,
                                                'opcion_index' => $col - 1,
                                                'descripcion' => $descripcion
                                            ];
                                        }
                                    }
                                }
                            }
                            
                            if (empty($criterios_data)) {
                                $error = 'No se encontraron criterios válidos en el archivo';
                            } else {
                                // Iniciar transacción
                                $conn->begin_transaction();
                                
                                try {
                                    // Desactivar criterios existentes (marcarlos como inactivos)
                                    $stmt_desactivar = $conn->prepare("UPDATE criterios SET activo = 0 WHERE id_curso = ?");
                                    $stmt_desactivar->bind_param("i", $id_curso_activo);
                                    $stmt_desactivar->execute();
                                    $stmt_desactivar->close();
                                    
                                    // Desactivar opciones existentes
                                    $stmt_desactivar_op = $conn->prepare("UPDATE opciones_evaluacion SET activo = 0 WHERE id_curso = ?");
                                    $stmt_desactivar_op->bind_param("i", $id_curso_activo);
                                    $stmt_desactivar_op->execute();
                                    $stmt_desactivar_op->close();
                                    
                                    // Insertar o actualizar opciones
                                    $opciones_ids = [];
                                    foreach ($opciones_data as $index => $opcion) {
                                        // Buscar si existe una opción con el mismo nombre y puntaje
                                        $stmt_check = $conn->prepare("
                                            SELECT id FROM opciones_evaluacion 
                                            WHERE id_curso = ? AND nombre = ? AND puntaje = ?
                                        ");
                                        $stmt_check->bind_param("isd", $id_curso_activo, $opcion['nombre'], $opcion['puntaje']);
                                        $stmt_check->execute();
                                        $result = $stmt_check->get_result();
                                        
                                        if ($result->num_rows > 0) {
                                            // Actualizar existente
                                            $opcion_existente = $result->fetch_assoc();
                                            $opcion_id = $opcion_existente['id'];
                                            $stmt_update = $conn->prepare("
                                                UPDATE opciones_evaluacion 
                                                SET orden = ?, activo = 1 
                                                WHERE id = ?
                                            ");
                                            $stmt_update->bind_param("ii", $opcion['orden'], $opcion_id);
                                            $stmt_update->execute();
                                            $stmt_update->close();
                                        } else {
                                            // Insertar nueva
                                            $stmt_insert = $conn->prepare("
                                                INSERT INTO opciones_evaluacion (id_curso, nombre, puntaje, orden, activo) 
                                                VALUES (?, ?, ?, ?, 1)
                                            ");
                                            $stmt_insert->bind_param("isdi", $id_curso_activo, $opcion['nombre'], $opcion['puntaje'], $opcion['orden']);
                                            $stmt_insert->execute();
                                            $opcion_id = $conn->insert_id;
                                            $stmt_insert->close();
                                        }
                                        $opciones_ids[] = $opcion_id;
                                        $stmt_check->close();
                                    }
                                    
                                    // Insertar o actualizar criterios
                                    $criterios_ids = [];
                                    foreach ($criterios_data as $index => $criterio) {
                                        // Buscar si existe un criterio con la misma descripción
                                        $stmt_check = $conn->prepare("
                                            SELECT id FROM criterios 
                                            WHERE id_curso = ? AND descripcion = ?
                                        ");
                                        $stmt_check->bind_param("is", $id_curso_activo, $criterio['descripcion']);
                                        $stmt_check->execute();
                                        $result = $stmt_check->get_result();
                                        
                                        if ($result->num_rows > 0) {
                                            // Actualizar existente
                                            $criterio_existente = $result->fetch_assoc();
                                            $criterio_id = $criterio_existente['id'];
                                            $stmt_update = $conn->prepare("
                                                UPDATE criterios 
                                                SET orden = ?, activo = 1 
                                                WHERE id = ?
                                            ");
                                            $stmt_update->bind_param("ii", $criterio['orden'], $criterio_id);
                                            $stmt_update->execute();
                                            $stmt_update->close();
                                        } else {
                                            // Insertar nuevo
                                            $stmt_insert = $conn->prepare("
                                                INSERT INTO criterios (id_curso, descripcion, orden, activo) 
                                                VALUES (?, ?, ?, 1)
                                            ");
                                            $stmt_insert->bind_param("isi", $id_curso_activo, $criterio['descripcion'], $criterio['orden']);
                                            $stmt_insert->execute();
                                            $criterio_id = $conn->insert_id;
                                            $stmt_insert->close();
                                        }
                                        $criterios_ids[] = $criterio_id;
                                        $stmt_check->close();
                                    }
                                    
                                    // Eliminar descripciones antiguas de los criterios y opciones activos
                                    if (!empty($criterios_ids) && !empty($opciones_ids)) {
                                        $placeholders_c = implode(',', array_fill(0, count($criterios_ids), '?'));
                                        $placeholders_o = implode(',', array_fill(0, count($opciones_ids), '?'));
                                        $stmt_delete = $conn->prepare("
                                            DELETE FROM criterio_opcion_descripciones 
                                            WHERE id_criterio IN ($placeholders_c) AND id_opcion IN ($placeholders_o)
                                        ");
                                        $stmt_delete->bind_param(str_repeat('i', count($criterios_ids) + count($opciones_ids)), ...array_merge($criterios_ids, $opciones_ids));
                                        $stmt_delete->execute();
                                        $stmt_delete->close();
                                    }
                                    
                                    // Insertar nuevas descripciones
                                    foreach ($descripciones_data as $desc) {
                                        if (isset($criterios_ids[$desc['criterio_index']]) && isset($opciones_ids[$desc['opcion_index']])) {
                                            $stmt_insert = $conn->prepare("
                                                INSERT INTO criterio_opcion_descripciones (id_criterio, id_opcion, descripcion) 
                                                VALUES (?, ?, ?)
                                            ");
                                            $stmt_insert->bind_param("iis", 
                                                $criterios_ids[$desc['criterio_index']], 
                                                $opciones_ids[$desc['opcion_index']], 
                                                $desc['descripcion']
                                            );
                                            $stmt_insert->execute();
                                            $stmt_insert->close();
                                        }
                                    }
                                    
                                    // Confirmar transacción
                                    $conn->commit();
                                    $success = true;
                                    $mensaje = 'Rúbrica importada exitosamente. Se importaron ' . count($criterios_data) . ' criterios y ' . count($opciones_data) . ' opciones.';
                                    
                                } catch (Exception $e) {
                                    // Revertir transacción en caso de error
                                    $conn->rollback();
                                    $error = 'Error al importar la rúbrica: ' . $e->getMessage();
                                }
                            }
                        }
                    }
                }
            }
        }
    } else {
        $error = 'Error al subir el archivo. Código de error: ' . $_FILES['archivo_csv']['error'];
    }
}

// Redirigir con mensaje
$redirect_url = "gestionar_criterios.php";
if ($success) {
    $redirect_url .= "?success=" . urlencode($mensaje);
} else if ($error) {
    $redirect_url .= "?error=" . urlencode($error);
}
header("Location: " . $redirect_url);
exit();
?>

