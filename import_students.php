<?php
require 'db.php';
// Permitir que tanto admin como docentes importen (usuario debe estar logueado)
verificar_sesion();

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php?error=" . urlencode("Seleccione un curso activo antes de importar estudiantes."));
    exit();
}

// Si es una solicitud GET para procesar confirmación
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'process') {
    header("Location: import_students_confirm.php");
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

$all_rows = [];
$delimiter = ',';

if ($ext === 'csv') {
    if (($handle = fopen($tmp, 'r')) === FALSE) {
        header("Location: dashboard_docente.php?error=" . urlencode("No se pudo abrir el archivo CSV."));
        exit();
    }

    try {
        // Detectar delimitador automáticamente (coma o punto y coma)
        $first_line = fgets($handle);
        if ($first_line === FALSE) {
            throw new Exception('CSV vacío o inválido');
        }

        // Eliminar BOM si existe
        $first_line = preg_replace('/^\xEF\xBB\xBF/', '', $first_line);
        $first_line = trim($first_line);

        // Contar columnas en ambos formatos
        $comma_count = count(str_getcsv($first_line, ','));
        $semicolon_count = count(str_getcsv($first_line, ';'));

        if ($semicolon_count > $comma_count) {
            $delimiter = ';';
        }

        rewind($handle);

        // Leer todas las filas
        while (($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {
            if (array_filter($data)) { // Skip empty rows
                $all_rows[] = array_map('trim', $data);
            }
        }
        fclose($handle);

    } catch (Exception $e) {
        header("Location: dashboard_docente.php?error=" . urlencode("Error al procesar CSV: " . $e->getMessage()));
        exit();
    }
} elseif ($ext === 'xlsx') {
    try {
        $rows = read_xlsx_simple($tmp);
        if ($rows === null) {
            throw new Exception('No se pudo leer el archivo Excel.');
        }

        foreach ($rows as $row) {
            $row_clean = array_map(function($cell) {
                $cell = trim((string)$cell);
                return $cell;
            }, $row);
            if (array_filter($row_clean)) {
                $all_rows[] = $row_clean;
            }
        }

    } catch (Exception $e) {
        header("Location: dashboard_docente.php?error=" . urlencode("Error al procesar XLSX: " . $e->getMessage()));
        exit();
    }
} else {
    header("Location: dashboard_docente.php?error=" . urlencode("Formato no soportado. Use CSV o XLSX."));
    exit();
}

// Validar que haya al menos 3 columnas
if (empty($all_rows)) {
    header("Location: dashboard_docente.php?error=" . urlencode("El archivo está vacío."));
    exit();
}

$first_row = reset($all_rows);
if (count($first_row) < 3) {
    header("Location: dashboard_docente.php?error=" . urlencode("El archivo debe tener al menos 3 columnas."));
    exit();
}

// Guardar datos en sesión para la pantalla de confirmación
$temp_file = sys_get_temp_dir() . '/import_' . session_id() . '_' . time() . '.json';

$json_data = json_encode([
    'rows' => $all_rows,
    'file_name' => $file['name'],
    'upload_time' => time()
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

$result = file_put_contents($temp_file, $json_data, LOCK_EX);
if ($result === false) {
    header("Location: dashboard_docente.php?error=" . urlencode("Error al guardar datos temporales."));
    exit();
}

// Guardar ruta del archivo temporal en sesión
$_SESSION['import_data_file'] = $temp_file;

// Redirigir a pantalla de confirmación
header("Location: import_students_confirm.php");
exit();
