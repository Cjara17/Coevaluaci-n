<?php
require 'db.php';
// Permitir que tanto admin como docentes importen (usuario debe estar logueado)
verificar_sesion();

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php?error=" . urlencode("Seleccione un curso activo antes de importar estudiantes."));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['lista_estudiantes'])) {
    header("Location: dashboard_docente.php?error=" . urlencode("Solicitud inválida para importación."));
    exit();
}

    $file = $_FILES['lista_estudiantes'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        header("Location: dashboard_docente.php?error=" . urlencode("Error subiendo archivo (Código: " . $file['error'] . ")"));
        exit();
    }

    // Permitir CSV y XLSX
    $allowed_ext = ['csv', 'xlsx'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $tmp = $file['tmp_name'];

    // Validación adicional de MIME type usando finfo_file
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $tmp);
    finfo_close($finfo);
    $allowed_mime_types = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    if (!in_array($mime_type, $allowed_mime_types)) {
        header("Location: dashboard_docente.php?error=" . urlencode("Error: El archivo no es un CSV o XLSX válido."));
        exit();
    }

$errors = [];
$processed = 0;
$inserted = 0;
$updated = 0;
$skipped = 0;
$seen_ids = [];
$seen_emails = [];

// Función para leer XLSX sin librerías externas
function read_xlsx_simple($file) {
    $zip = new ZipArchive();
    if ($zip->open($file) === TRUE) {
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($xml === FALSE) return null;
        
        $xml = simplexml_load_string($xml);
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $v = (string)$cell->v;
                $cells[] = $v;
            }
            if (array_filter($cells)) {
                $rows[] = $cells;
            }
        }
        return $rows;
    }
    return null;
}

if ($ext === 'csv') {
    if (($handle = fopen($tmp, 'r')) === FALSE) {
        header("Location: dashboard_docente.php?error=" . urlencode("No se pudo abrir el archivo CSV."));
        exit();
    }
    $conn->begin_transaction();
    try {
        $row = 0;
        $first = fgetcsv($handle, 10000, ",");
        if ($first === FALSE) {
            throw new Exception('CSV vacío o inválido');
        }
        $row++;

        $header_map = [];
        $is_header = false;
        $h0 = isset($first[0]) ? strtolower(trim($first[0])) : '';
        $h1 = isset($first[1]) ? strtolower(trim($first[1])) : '';
        $h2 = isset($first[2]) ? strtolower(trim($first[2])) : '';
        if (str_contains($h0, 'id') || str_contains($h0, 'codigo') || str_contains($h0, 'rut') || str_contains($h0, 'student')) {
            $is_header = true;
        }
        if (str_contains($h1, 'nombre') || str_contains($h1, 'name')) $is_header = true;
        if (str_contains($h2, 'email') || str_contains($h2, 'correo')) $is_header = true;

        if ($is_header) {
            foreach ($first as $idx => $cell) {
                $c = strtolower(trim($cell));
                if (str_contains($c, 'id') || str_contains($c, 'codigo') || str_contains($c, 'rut') || str_contains($c, 'student')) $header_map['id'] = $idx;
                if (str_contains($c, 'nombre') || str_contains($c, 'name')) $header_map['name'] = $idx;
                if (str_contains($c, 'email') || str_contains($c, 'correo')) $header_map['email'] = $idx;
            }
        } else {
            $header_map = ['id' => 0, 'name' => 1, 'email' => 2];
            rewind($handle);
            $row = 0;
        }

        $stmt_select_by_studentid = $conn->prepare("SELECT id, nombre, email FROM usuarios WHERE student_id = ? LIMIT 1");
        $stmt_select_by_email = $conn->prepare("SELECT id, nombre, student_id FROM usuarios WHERE email = ? LIMIT 1");
        $stmt_insert = $conn->prepare("INSERT INTO usuarios (nombre, email, student_id, es_docente, id_curso) VALUES (?, ?, ?, 0, ?)");
        $stmt_update = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ?, student_id = ?, id_curso = ? WHERE id = ?");

        while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
            $row++;
            if (!array_filter($data)) continue;

            $id_val = isset($data[$header_map['id']]) ? trim($data[$header_map['id']]) : '';
            $name = isset($data[$header_map['name']]) ? trim($data[$header_map['name']]) : '';
            $email = isset($data[$header_map['email']]) ? trim($data[$header_map['email']]) : '';

            $row_errors = [];
            if ($id_val === '') $row_errors[] = 'ID vacío';
            if ($name === '') $row_errors[] = 'Nombre vacío';
            if ($email === '') $row_errors[] = 'Email vacío';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $row_errors[] = 'Email inválido';

            if ($id_val !== '') {
                if (in_array(strtolower($id_val), $seen_ids)) $row_errors[] = 'ID duplicado en archivo';
                else $seen_ids[] = strtolower($id_val);
            }
            if ($email !== '') {
                if (in_array(strtolower($email), $seen_emails)) $row_errors[] = 'Email duplicado en archivo';
                else $seen_emails[] = strtolower($email);
            }

            if (!empty($row_errors)) {
                $errors[] = ['row' => $row, 'errors' => $row_errors];
                $skipped++;
                continue;
            }

            $existing_id = null;
            $stmt_select_by_studentid->bind_param('s', $id_val);
            $stmt_select_by_studentid->execute();
            $res_sid = $stmt_select_by_studentid->get_result();
            if ($res_sid && $res_sid->num_rows > 0) {
                $existing = $res_sid->fetch_assoc();
                $existing_id = $existing['id'];
            }

            if (is_null($existing_id)) {
                $stmt_select_by_email->bind_param('s', $email);
                $stmt_select_by_email->execute();
                $res_email = $stmt_select_by_email->get_result();
                if ($res_email && $res_email->num_rows > 0) {
                    $existing = $res_email->fetch_assoc();
                    $existing_id = $existing['id'];
                }
            }

            if ($existing_id) {
                $stmt_update->bind_param('sssii', $name, $email, $id_val, $id_curso_activo, $existing_id);
                $stmt_update->execute();
                if ($stmt_update->affected_rows >= 0) $updated++;
            } else {
                $stmt_insert->bind_param('sssi', $name, $email, $id_val, $id_curso_activo);
                $stmt_insert->execute();
                if ($stmt_insert->affected_rows > 0) $inserted++;
            }

            $processed++;
        }

        fclose($handle);
        $conn->commit();

        $summary = [];
        $summary[] = "Registros leídos: " . ($processed + $skipped);
        $summary[] = "Procesados (insert/update): $processed (Insertados: $inserted, Actualizados: $updated)";
        $summary[] = "Filas omitidas por errores: $skipped";

    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = ['row' => 'N/A', 'errors' => ["Error crítico: " . $e->getMessage()]];
        $summary = ["Error crítico: " . $e->getMessage()];
    }
} elseif ($ext === 'xlsx') {
    $conn->begin_transaction();
    try {
        $rows = read_xlsx_simple($tmp);
        if ($rows === null) {
            throw new Exception('No se pudo leer el archivo Excel.');
        }

        $stmt_select_by_studentid = $conn->prepare("SELECT id, nombre, email FROM usuarios WHERE student_id = ? LIMIT 1");
        $stmt_select_by_email = $conn->prepare("SELECT id, nombre, student_id FROM usuarios WHERE email = ? LIMIT 1");
        $stmt_insert = $conn->prepare("INSERT INTO usuarios (nombre, email, student_id, es_docente, id_curso) VALUES (?, ?, ?, 0, ?)");
        $stmt_update = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ?, student_id = ?, id_curso = ? WHERE id = ?");

        $row = 0;
        $header_map = [];
        $is_header = false;

        foreach ($rows as $data) {
            $row++;
            if ($row == 1) {
                $h0 = isset($data[0]) ? strtolower(trim((string)$data[0])) : '';
                $h1 = isset($data[1]) ? strtolower(trim((string)$data[1])) : '';
                $h2 = isset($data[2]) ? strtolower(trim((string)$data[2])) : '';
                if (str_contains($h0, 'id') || str_contains($h0, 'codigo') || str_contains($h0, 'rut') || str_contains($h0, 'student')) $is_header = true;
                if (str_contains($h1, 'nombre') || str_contains($h1, 'name')) $is_header = true;
                if (str_contains($h2, 'email') || str_contains($h2, 'correo')) $is_header = true;

                if ($is_header) {
                    foreach ($data as $idx => $cell) {
                        $c = strtolower(trim((string)$cell));
                        if (str_contains($c, 'id') || str_contains($c, 'codigo') || str_contains($c, 'rut') || str_contains($c, 'student')) $header_map['id'] = $idx;
                        if (str_contains($c, 'nombre') || str_contains($c, 'name')) $header_map['name'] = $idx;
                        if (str_contains($c, 'email') || str_contains($c, 'correo')) $header_map['email'] = $idx;
                    }
                    continue;
                } else {
                    $header_map = ['id' => 0, 'name' => 1, 'email' => 2];
                }
            }

            $id_val = isset($data[$header_map['id']]) ? trim((string)$data[$header_map['id']]) : '';
            $name = isset($data[$header_map['name']]) ? trim((string)$data[$header_map['name']]) : '';
            $email = isset($data[$header_map['email']]) ? trim((string)$data[$header_map['email']]) : '';

            $row_errors = [];
            if ($id_val === '') $row_errors[] = 'ID vacío';
            if ($name === '') $row_errors[] = 'Nombre vacío';
            if ($email === '') $row_errors[] = 'Email vacío';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $row_errors[] = 'Email inválido';

            if ($id_val !== '') {
                if (in_array(strtolower($id_val), $seen_ids)) $row_errors[] = 'ID duplicado en archivo';
                else $seen_ids[] = strtolower($id_val);
            }
            if ($email !== '') {
                if (in_array(strtolower($email), $seen_emails)) $row_errors[] = 'Email duplicado en archivo';
                else $seen_emails[] = strtolower($email);
            }

            if (!empty($row_errors)) {
                $errors[] = ['row' => $row, 'errors' => $row_errors];
                $skipped++;
                continue;
            }

            $existing_id = null;
            $stmt_select_by_studentid->bind_param('s', $id_val);
            $stmt_select_by_studentid->execute();
            $res_sid = $stmt_select_by_studentid->get_result();
            if ($res_sid && $res_sid->num_rows > 0) {
                $existing = $res_sid->fetch_assoc();
                $existing_id = $existing['id'];
            }

            if (is_null($existing_id)) {
                $stmt_select_by_email->bind_param('s', $email);
                $stmt_select_by_email->execute();
                $res_email = $stmt_select_by_email->get_result();
                if ($res_email && $res_email->num_rows > 0) {
                    $existing = $res_email->fetch_assoc();
                    $existing_id = $existing['id'];
                }
            }

            if ($existing_id) {
                $stmt_update->bind_param('sssii', $name, $email, $id_val, $id_curso_activo, $existing_id);
                $stmt_update->execute();
                if ($stmt_update->affected_rows >= 0) $updated++;
            } else {
                $stmt_insert->bind_param('sssi', $name, $email, $id_val, $id_curso_activo);
                $stmt_insert->execute();
                if ($stmt_insert->affected_rows > 0) $inserted++;
            }

            $processed++;
        }

        $conn->commit();

        $summary = [];
        $summary[] = "Registros leídos: " . ($processed + $skipped);
        $summary[] = "Procesados (insert/update): $processed (Insertados: $inserted, Actualizados: $updated)";
        $summary[] = "Filas omitidas por errores: $skipped";

    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = ['row' => 'N/A', 'errors' => ["Error crítico: " . $e->getMessage()]];
        $summary = ["Error crítico: " . $e->getMessage()];
    }
} else {
    header("Location: dashboard_docente.php?error=" . urlencode("Formato no soportado. Suba un archivo CSV o Excel (.xlsx) con columnas: ID, Nombre, Email."));
    exit();
}
    $conn->begin_transaction();
    try {
        $row = 0;
        // Detect header by looking at first row values
        $first = fgetcsv($handle, 10000, ",");
        if ($first === FALSE) {
            throw new Exception('CSV vacío o inválido');
        }
        $row++;

        // Normalize header detection
        $header_map = [];
        $is_header = false;
        $h0 = isset($first[0]) ? strtolower(trim($first[0])) : '';
        $h1 = isset($first[1]) ? strtolower(trim($first[1])) : '';
        $h2 = isset($first[2]) ? strtolower(trim($first[2])) : '';
        if (str_contains($h0, 'id') || str_contains($h0, 'codigo') || str_contains($h0, 'rut') || str_contains($h0, 'student')) {
            $is_header = true;
        }
        if (str_contains($h1, 'nombre') || str_contains($h1, 'name')) $is_header = true;
        if (str_contains($h2, 'email') || str_contains($h2, 'correo')) $is_header = true;

        if ($is_header) {
            // Find which columns correspond to id, name, email
            foreach ($first as $idx => $cell) {
                $c = strtolower(trim($cell));
                if (str_contains($c, 'id') || str_contains($c, 'codigo') || str_contains($c, 'rut') || str_contains($c, 'student')) $header_map['id'] = $idx;
                if (str_contains($c, 'nombre') || str_contains($c, 'name')) $header_map['name'] = $idx;
                if (str_contains($c, 'email') || str_contains($c, 'correo')) $header_map['email'] = $idx;
            }
        } else {
            // Assume order: ID, Nombre, Email
            $header_map = ['id' => 0, 'name' => 1, 'email' => 2];
            // process first row as data
            rewind($handle);
            $row = 0;
        }

        // Prepare statements
        $stmt_select_by_studentid = $conn->prepare("SELECT id, nombre, email FROM usuarios WHERE student_id = ? LIMIT 1");
        $stmt_select_by_email = $conn->prepare("SELECT id, nombre, student_id FROM usuarios WHERE email = ? LIMIT 1");

        $stmt_insert = $conn->prepare("INSERT INTO usuarios (nombre, email, student_id, es_docente, id_curso) VALUES (?, ?, ?, 0, ?)");
        $stmt_update = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ?, student_id = ?, id_curso = ? WHERE id = ?");

        while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
            $row++;
            // Skip completely empty rows
            if (!array_filter($data)) continue;

            $id_val = isset($data[$header_map['id']]) ? trim($data[$header_map['id']]) : '';
            $name = isset($data[$header_map['name']]) ? trim($data[$header_map['name']]) : '';
            $email = isset($data[$header_map['email']]) ? trim($data[$header_map['email']]) : '';

            $row_errors = [];
            if ($id_val === '') $row_errors[] = 'ID vacío';
            if ($name === '') $row_errors[] = 'Nombre vacío';
            if ($email === '') $row_errors[] = 'Email vacío';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $row_errors[] = 'Email inválido';

            // Duplicates inside the file
            if ($id_val !== '') {
                if (in_array(strtolower($id_val), $seen_ids)) $row_errors[] = 'ID duplicado en archivo';
                else $seen_ids[] = strtolower($id_val);
            }
            if ($email !== '') {
                if (in_array(strtolower($email), $seen_emails)) $row_errors[] = 'Email duplicado en archivo';
                else $seen_emails[] = strtolower($email);
            }

            if (!empty($row_errors)) {
                $errors[] = ['row' => $row, 'errors' => $row_errors];
                $skipped++;
                continue;
            }

            // Check DB for existing by student_id
            $existing_id = null;
            $stmt_select_by_studentid->bind_param('s', $id_val);
            $stmt_select_by_studentid->execute();
            $res_sid = $stmt_select_by_studentid->get_result();
            if ($res_sid && $res_sid->num_rows > 0) {
                $existing = $res_sid->fetch_assoc();
                $existing_id = $existing['id'];
            }

            // If not found by student_id, check by email
            if (is_null($existing_id)) {
                $stmt_select_by_email->bind_param('s', $email);
                $stmt_select_by_email->execute();
                $res_email = $stmt_select_by_email->get_result();
                if ($res_email && $res_email->num_rows > 0) {
                    $existing = $res_email->fetch_assoc();
                    $existing_id = $existing['id'];
                }
            }

            if ($existing_id) {
                // Update existing record
                $stmt_update->bind_param('sssii', $name, $email, $id_val, $id_curso_activo, $existing_id);
                $stmt_update->execute();
                if ($stmt_update->affected_rows >= 0) $updated++;
            } else {
                // Insert new
                $stmt_insert->bind_param('sssi', $name, $email, $id_val, $id_curso_activo);
                $stmt_insert->execute();
                if ($stmt_insert->affected_rows > 0) $inserted++;
            }

            $processed++;
        }

        fclose($handle);
        $conn->commit();

        // Prepare summary HTML
        $summary = [];
        $summary[] = "Registros leídos: " . ($processed + $skipped);
        $summary[] = "Procesados (insert/update): $processed (Insertados: $inserted, Actualizados: $updated)";
        $summary[] = "Filas omitidas por errores: $skipped";

    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = ['row' => 'N/A', 'errors' => ["Error crítico: " . $e->getMessage()]];
        $summary = ["Error crítico: " . $e->getMessage()];
    }
} else {
    header("Location: dashboard_docente.php?error=" . urlencode("No se pudo abrir el archivo CSV."));
    exit();
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Importación de Estudiantes - Resultado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
    <div class="container">
        <h1>Resultado de Importación</h1>
        <div class="mt-3">
            <?php foreach ($summary as $s): ?>
                <div class="alert alert-secondary"><?php echo htmlspecialchars($s); ?></div>
            <?php endforeach; ?>

            <?php if (!empty($errors)): ?>
                <div class="card">
                    <div class="card-header bg-danger text-white">Informe de Errores (filas afectadas)</div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <thead><tr><th>Fila</th><th>Errores</th></tr></thead>
                            <tbody>
                                <?php foreach ($errors as $e): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($e['row']); ?></td>
                                        <td><?php echo htmlspecialchars(implode('; ', $e['errors'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mt-3">
                <a href="dashboard_docente.php" class="btn btn-primary">Volver al Panel</a>
            </div>
        </div>
    </div>
</body>
</html>
