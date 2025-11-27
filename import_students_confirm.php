<?php
require 'db.php';
verificar_sesion();

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php?error=" . urlencode("Seleccione un curso activo."));
    exit();
}

// Verificar si hay datos en sesión
if (!isset($_SESSION['import_data_file']) || !file_exists($_SESSION['import_data_file'])) {
    header("Location: dashboard_docente.php?error=" . urlencode("Sesión de importación expirada."));
    exit();
}

// Cargar datos desde archivo temporal
$json_content = file_get_contents($_SESSION['import_data_file']);

// Decodificar JSON (los datos ya están sin acentos)
$import_data = json_decode($json_content, true, 512, JSON_UNESCAPED_UNICODE);

if (!$import_data || !isset($import_data['rows'])) {
    header("Location: dashboard_docente.php?error=" . urlencode("Datos de importación inválidos."));
    exit();
}

$rows = $import_data['rows'];
$file_name = $import_data['file_name'];

// Procesar confirmación del mapeo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm') {
    // Obtener mapeo de columnas
    $col_id = isset($_POST['col_id']) ? intval($_POST['col_id']) : -1;
    $col_name = isset($_POST['col_name']) ? intval($_POST['col_name']) : -1;
    $col_email = isset($_POST['col_email']) ? intval($_POST['col_email']) : -1;
    $skip_header = isset($_POST['skip_header']) ? true : false;

    // Validar mapeo
    if ($col_id < 0 || $col_name < 0 || $col_email < 0) {
        $error = "Debe mapear todas las columnas.";
    } elseif ($col_id == $col_name || $col_id == $col_email || $col_name == $col_email) {
        $error = "No puede asignar la misma columna dos veces.";
    } else {
        // Procesar importación
        $errors = [];
        $processed = 0;
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $seen_ids = [];
        $seen_emails = [];

        $conn->begin_transaction();
        try {
            // Asegurar que la conexión tiene encoding UTF-8
            $conn->set_charset("utf8mb4");
            
            $stmt_select_by_studentid = $conn->prepare("SELECT id, nombre, email FROM usuarios WHERE student_id = ? LIMIT 1");
            $stmt_select_by_email = $conn->prepare("SELECT id, nombre, student_id FROM usuarios WHERE email = ? LIMIT 1");
            $stmt_insert = $conn->prepare("INSERT INTO usuarios (nombre, email, student_id, es_docente, id_curso) VALUES (?, ?, ?, 0, ?)");
            $stmt_update = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ?, student_id = ?, id_curso = ? WHERE id = ?");

            $start_row = $skip_header ? 1 : 0;

            for ($i = $start_row; $i < count($rows); $i++) {
                $data = $rows[$i];
                $row_num = $i + 1; // Número de fila para reportes (1-indexado)

                // Extraer datos según mapeo (ya están sin acentos desde import_students.php)
                $id_val = isset($data[$col_id]) ? trim($data[$col_id]) : '';
                $name = isset($data[$col_name]) ? trim($data[$col_name]) : '';
                $email = isset($data[$col_email]) ? trim($data[$col_email]) : '';

                // Validar datos
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
                    $errors[] = ['row' => $row_num, 'errors' => $row_errors];
                    $skipped++;
                    continue;
                }

                // Buscar usuario existente
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

                // Insertar o actualizar
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
            $success = true;
            $summary = [
                "Registros procesados: $processed",
                "Insertados: $inserted",
                "Actualizados: $updated",
                "Omitidos por errores: $skipped"
            ];

            // Limpiar sesión y archivo temporal
            $temp_file = $_SESSION['import_data_file'];
            unset($_SESSION['import_data_file']);
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error al procesar: " . $e->getMessage();
            $success = false;
            
            // Limpiar archivo temporal en caso de error
            $temp_file = $_SESSION['import_data_file'];
            unset($_SESSION['import_data_file']);
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
        }
    }
}

// Si no hay error o éxito, mostrar formulario de mapeo
if (!isset($success)) {
    $success = false;
    $error = isset($error) ? $error : null;
}

// Preparar vista previa (primeras 10 filas)
$preview_rows = array_slice($rows, 0, min(10, count($rows)));
$col_count = count($rows[0]);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Confirmación de Importación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .column-preview { max-height: 300px; overflow-y: auto; }
        .preview-table { font-size: 0.9rem; }
        .mapper-section { background: #f8f9fa; padding: 20px; border-radius: 5px; }
    </style>
</head>
<body class="dashboard-bg p-4">
    <div class="container">
        <h1 class="mb-4">Confirmación de Importación</h1>

        <?php if ($success): ?>
            <!-- Pantalla de éxito -->
            <div class="alert alert-success">
                <h4>✓ Importación Completada</h4>
                <ul class="mb-0">
                    <?php foreach ($summary as $s): ?>
                        <li><?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="card border-warning mt-3">
                    <div class="card-header bg-warning">Filas con errores (no importadas)</div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>Fila</th><th>Errores</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($errors as $e): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($e['row'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(implode('; ', $e['errors']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mt-4">
                <a href="dashboard_docente.php" class="btn btn-primary">Volver al Panel</a>
            </div>

        <?php else: ?>
            <!-- Pantalla de mapeo -->
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <strong>Archivo:</strong> <?php echo htmlspecialchars($file_name, ENT_QUOTES, 'UTF-8'); ?>
                            <br><small class="text-muted">Total de filas: <?php echo count($rows); ?></small>
                        </div>
                        <div class="card-body">
                            <p><strong>Vista previa de los datos:</strong></p>
                            <div class="column-preview">
                                <table class="table table-bordered table-sm preview-table">
                                    <thead>
                                        <tr>
                                            <?php for ($c = 0; $c < $col_count; $c++): ?>
                                                <th>Columna <?php echo $c + 1; ?></th>
                                            <?php endfor; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($preview_rows as $row): ?>
                                            <tr>
                                                <?php for ($c = 0; $c < $col_count; $c++): ?>
                                                    <td><?php echo htmlspecialchars(isset($row[$c]) ? substr($row[$c], 0, 30) : '', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <?php endfor; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="mapper-section">
                        <h5>Mapeo de Columnas</h5>
                        <p class="text-muted small">Selecciona qué columna contiene cada dato:</p>

                        <form method="post">
                            <input type="hidden" name="action" value="confirm">

                            <div class="mb-3">
                                <label for="col_id" class="form-label">ID del Estudiante</label>
                                <select name="col_id" id="col_id" class="form-select" required>
                                    <option value="-1">-- Seleccionar --</option>
                                    <?php for ($c = 0; $c < $col_count; $c++): ?>
                                        <option value="<?php echo $c; ?>">Columna <?php echo $c + 1; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="col_name" class="form-label">Nombre del Estudiante</label>
                                <select name="col_name" id="col_name" class="form-select" required>
                                    <option value="-1">-- Seleccionar --</option>
                                    <?php for ($c = 0; $c < $col_count; $c++): ?>
                                        <option value="<?php echo $c; ?>">Columna <?php echo $c + 1; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="col_email" class="form-label">Email del Estudiante</label>
                                <select name="col_email" id="col_email" class="form-select" required>
                                    <option value="-1">-- Seleccionar --</option>
                                    <?php for ($c = 0; $c < $col_count; $c++): ?>
                                        <option value="<?php echo $c; ?>">Columna <?php echo $c + 1; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="skip_header" id="skip_header" value="1">
                                <label class="form-check-label" for="skip_header">
                                    Primera fila contiene encabezados (saltarla)
                                </label>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success">Importar</button>
                                <a href="dashboard_docente.php" class="btn btn-secondary">Cancelar</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </div>
</body>
</html>
