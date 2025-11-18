<?php
require 'db.php';

// Verificar si ZipArchive está disponible (solo necesario para Excel)
$zipDisponible = extension_loaded('zip') && class_exists('ZipArchive');

// Solo cargar SimpleXlsxReader si ZipArchive está disponible
if ($zipDisponible) {
    require_once __DIR__ . '/libs/SimpleXlsxReader.php';
}

// Requerir ser docente Y tener un curso activo
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['lista_estudiantes'])) {

    $archivo = $_FILES['lista_estudiantes']['tmp_name'];
    $nombreArchivo = $_FILES['lista_estudiantes']['name'];

    if ($_FILES['lista_estudiantes']['error'] !== UPLOAD_ERR_OK) {
        header("Location: dashboard_docente.php?status=" . urlencode("Error al subir archivo (Código: " . $_FILES['lista_estudiantes']['error'] . ")"));
        exit();
    }
    
    $extension = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
    $es_csv = $extension === 'csv';
    $es_xlsx = in_array($extension, ['xlsx', 'xls'], true);

    if (!$es_csv && !$es_xlsx) {
        header("Location: dashboard_docente.php?status=" . urlencode("Error: El archivo debe ser CSV o Excel (.xlsx)."));
        exit();
    }

    // Si intentan subir Excel pero ZipArchive no está disponible
    if ($es_xlsx && !$zipDisponible) {
        header("Location: dashboard_docente.php?status=" . urlencode("Error: Los archivos Excel (.xlsx) requieren la extensión ZipArchive. Por favor, use un archivo CSV o habilite la extensión php_zip en XAMPP. Consulte INSTRUCCIONES_ZIP.txt o visite verificar_zip.php para más detalles."));
        exit();
    }

    $registros_procesados = 0;
    $errores = [];
    $fila_num = 0;

    $procesarFila = function (int $fila_actual, array $datos) use ($conn, $id_curso_activo, &$registros_procesados, &$errores) {
        // Limpiar y normalizar los datos del array
        $datos_limpios = array_map(function($valor) {
            // Convertir a string, eliminar espacios y caracteres de control
            $limpio = trim((string)($valor ?? ''));
            // Eliminar espacios múltiples
            $limpio = preg_replace('/\s+/', ' ', $limpio);
            return $limpio;
        }, $datos);
        
        // Obtener los tres primeros campos (Nombre, Email, Equipo)
        $nombre = $datos_limpios[0] ?? '';
        $email = $datos_limpios[1] ?? '';
        $nombre_equipo = $datos_limpios[2] ?? '';

        // Validar que los campos obligatorios no estén vacíos
        if ($nombre === '' || $email === '' || $nombre_equipo === '') {
            // Solo reportar error si la fila tiene algún contenido
            if (filaTieneContenido($datos_limpios)) {
                $errores[] = "Fila $fila_actual mal formada o datos incompletos (Nombre, Correo y Equipo son obligatorios).";
            }
            return;
        }
        
        // Validar formato de email básico
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = "Fila $fila_actual: El correo '$email' no tiene un formato válido.";
            return;
        }

        // Normalizar el nombre del equipo (eliminar espacios extra, mantener consistencia)
        $nombre_equipo_normalizado = preg_replace('/\s+/', ' ', trim($nombre_equipo));
        
        // Buscar equipo existente (case-insensitive para evitar duplicados)
        $stmt_equipo = $conn->prepare("SELECT id, nombre_equipo FROM equipos WHERE LOWER(TRIM(nombre_equipo)) = LOWER(?) AND id_curso = ?");
        $stmt_equipo->bind_param("si", $nombre_equipo_normalizado, $id_curso_activo);
        $stmt_equipo->execute();
        $res_equipo = $stmt_equipo->get_result();

        if ($res_equipo->num_rows == 0) {
            // Crear nuevo equipo
            $stmt_insert_equipo = $conn->prepare("INSERT INTO equipos (nombre_equipo, id_curso) VALUES (?, ?)");
            $stmt_insert_equipo->bind_param("si", $nombre_equipo_normalizado, $id_curso_activo);
            $stmt_insert_equipo->execute();
            $id_equipo = $conn->insert_id;
            $stmt_insert_equipo->close();
        } else {
            // Usar equipo existente
            $equipo_existente = $res_equipo->fetch_assoc();
            $id_equipo = $equipo_existente['id'];
            // Actualizar el nombre del equipo si hay diferencias menores (normalizar)
            if ($equipo_existente['nombre_equipo'] !== $nombre_equipo_normalizado) {
                $stmt_update_equipo = $conn->prepare("UPDATE equipos SET nombre_equipo = ? WHERE id = ?");
                $stmt_update_equipo->bind_param("si", $nombre_equipo_normalizado, $id_equipo);
                $stmt_update_equipo->execute();
                $stmt_update_equipo->close();
            }
        }
        $stmt_equipo->close();

        $stmt_usuario = $conn->prepare("INSERT INTO usuarios (nombre, email, id_equipo, es_docente, id_curso) VALUES (?, ?, ?, FALSE, ?) 
                                         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), id_equipo = VALUES(id_equipo), id_curso = VALUES(id_curso)");
        $stmt_usuario->bind_param("ssii", $nombre, $email, $id_equipo, $id_curso_activo);
        $stmt_usuario->execute();

        if ($stmt_usuario->affected_rows > 0) {
            $registros_procesados++;
        }
        $stmt_usuario->close();
    };

    $esCabecera = function (array $datos): bool {
        $primerCampo = strtolower(trim($datos[0] ?? ''));
        return in_array($primerCampo, ['nombre', 'nombre completo'], true);
    };

    $conn->begin_transaction();

    try {
        if ($es_csv) {
            if (($gestor = fopen($archivo, "r")) === FALSE) {
                throw new RuntimeException("Error al intentar abrir el archivo CSV.");
            }

            while (($datos = fgetcsv($gestor, 1000, ",")) !== FALSE) {
                $fila_num++;
                if ($fila_num == 1 && $esCabecera($datos)) {
                    continue;
                }
                if (filaTieneContenido($datos)) {
                    $procesarFila($fila_num, $datos);
                }
            }
            fclose($gestor);
        } else {
            $filas = SimpleXlsxReader::rows($archivo);
            foreach ($filas as $datos) {
                $fila_num++;
                
                // Saltar filas completamente vacías
                if (empty(array_filter($datos, function($v) { return trim((string)$v) !== ''; }))) {
                    continue;
                }
                
                // Saltar la fila de cabecera
                if ($fila_num == 1 && $esCabecera($datos)) {
                    continue;
                }
                
                // Procesar solo filas con contenido válido
                if (filaTieneContenido($datos)) {
                    $procesarFila($fila_num, $datos);
                }
            }
        }

        $conn->commit();
        
        // Obtener estadísticas de equipos creados/actualizados
        $stmt_stats = $conn->prepare("SELECT COUNT(DISTINCT id) as total_equipos FROM equipos WHERE id_curso = ?");
        $stmt_stats->bind_param("i", $id_curso_activo);
        $stmt_stats->execute();
        $stats = $stmt_stats->get_result()->fetch_assoc();
        $total_equipos = $stats['total_equipos'];
        $stmt_stats->close();

        if ($registros_procesados === 0 && empty($errores)) {
            $errores[] = "No se encontraron filas válidas en el archivo.";
        }

        $mensaje = "Éxito: " . $registros_procesados . " estudiante(s) procesado(s) en " . $total_equipos . " equipo(s).";
        if (!empty($errores)) {
            $mensaje .= " Se omitieron " . count($errores) . " fila(s) por errores.";
        }

        header("Location: dashboard_docente.php?status=" . urlencode($mensaje));

    } catch (Exception $e) {
        $conn->rollback();
        $mensajeError = $e->getMessage();
        
        // Mensaje más específico si el error es sobre ZipArchive
        if (strpos($mensajeError, 'ZipArchive') !== false || strpos($mensajeError, 'zip') !== false) {
            $mensajeError = "Error: La extensión ZipArchive no está habilitada en el servidor. Por favor, habilite la extensión php_zip en XAMPP. Consulte INSTRUCCIONES_ZIP.txt para más detalles.";
        }
        
        header("Location: dashboard_docente.php?status=" . urlencode("Error al procesar el archivo: " . $mensajeError));
    }
}

function filaTieneContenido(array $datos): bool
{
    // Verificar que al menos los primeros 3 campos tengan contenido
    $campos_requeridos = 0;
    foreach ([0, 1, 2] as $index) {
        $valor = isset($datos[$index]) ? trim((string)$datos[$index]) : '';
        if ($valor !== '') {
            $campos_requeridos++;
        }
    }
    // Una fila válida debe tener al menos algún contenido en las primeras 3 columnas
    return $campos_requeridos > 0;
}
?>