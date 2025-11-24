<?php
// NUEVO: se agregó header global institucional UCT
include 'header.php';
require 'db.php';
// Requerir ser docente Y tener un curso activo
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;
$id_docente = $_SESSION['id_usuario'];

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

// Obtener criterios del curso activo
$stmt_criterios = $conn->prepare("SELECT * FROM criterios WHERE id_curso = ? ORDER BY orden ASC");
$stmt_criterios->bind_param("i", $id_curso_activo);
$stmt_criterios->execute();
$criterios_result = $stmt_criterios->get_result();
$criterios = [];
while ($row = $criterios_result->fetch_assoc()) {
    $criterios[] = $row;
}
$stmt_criterios->close();

// Obtener opciones de evaluación del curso activo
$stmt_opciones = $conn->prepare("SELECT * FROM opciones_evaluacion WHERE id_curso = ? ORDER BY orden ASC, puntaje ASC");
$stmt_opciones->bind_param("i", $id_curso_activo);
$stmt_opciones->execute();
$opciones_result = $stmt_opciones->get_result();
$opciones = [];
while ($row = $opciones_result->fetch_assoc()) {
    $opciones[] = $row;
}
$stmt_opciones->close();

// Obtener información del curso
$stmt_curso = $conn->prepare("SELECT nombre_curso, semestre, anio, rendimiento_minimo, nota_minima FROM cursos WHERE id = ?");
$stmt_curso->bind_param("i", $id_curso_activo);
$stmt_curso->execute();
$curso_activo = $stmt_curso->get_result()->fetch_assoc();
$stmt_curso->close();

$rendimiento_minimo = isset($curso_activo['rendimiento_minimo']) ? (float)$curso_activo['rendimiento_minimo'] : 60.0;
$nota_minima = isset($curso_activo['nota_minima']) ? (float)$curso_activo['nota_minima'] : 1.0;

// Obtener descripciones de criterio-opción
$descripciones = [];
if (!empty($criterios) && !empty($opciones)) {
    $ids_criterios = array_column($criterios, 'id');
    $ids_opciones = array_column($opciones, 'id');
    if (!empty($ids_criterios) && !empty($ids_opciones)) {
        $placeholders_c = implode(',', array_fill(0, count($ids_criterios), '?'));
        $placeholders_o = implode(',', array_fill(0, count($ids_opciones), '?'));
        $types = str_repeat('i', count($ids_criterios) + count($ids_opciones));
        $params = array_merge($ids_criterios, $ids_opciones);
        $stmt_desc = $conn->prepare("
            SELECT id_criterio, id_opcion, descripcion 
            FROM criterio_opcion_descripciones 
            WHERE id_criterio IN ($placeholders_c) AND id_opcion IN ($placeholders_o)
        ");
        if ($stmt_desc) {
            $stmt_desc->bind_param($types, ...$params);
            $stmt_desc->execute();
            $desc_result = $stmt_desc->get_result();
            while ($row = $desc_result->fetch_assoc()) {
                $descripciones[$row['id_criterio']][$row['id_opcion']] = $row['descripcion'];
            }
            $stmt_desc->close();
        }
    }
}

// Calcular puntaje total máximo
// Para cada criterio activo, sumar el puntaje máximo entre todas las opciones
$puntaje_total_maximo = 0;
if (!empty($opciones)) {
    $max_puntaje_global = 0;
    foreach ($opciones as $opcion) {
        if ($opcion['puntaje'] > $max_puntaje_global) {
            $max_puntaje_global = $opcion['puntaje'];
        }
    }
    // Contar criterios activos y multiplicar por el puntaje máximo
    $criterios_activos_count = count(array_filter($criterios, function($c) { return $c['activo']; }));
    $puntaje_total_maximo = $max_puntaje_global * $criterios_activos_count;
}

// Función para calcular nota basada en puntaje, rendimiento mínimo y puntaje total máximo
function calcular_nota_escala($puntaje, $puntaje_minimo, $puntaje_maximo, $nota_minima = 1.0) {
    if ($puntaje <= 0) return $nota_minima;
    if ($puntaje >= $puntaje_maximo) return 7.0;
    
    if ($puntaje <= $puntaje_minimo) {
        // De 0 a puntaje_minimo: nota de nota_minima a 4.0
        return $nota_minima + ($puntaje / $puntaje_minimo) * (4.0 - $nota_minima);
    } else {
        // De puntaje_minimo a puntaje_maximo: nota de 4.0 a 7.0
        return 4.0 + (($puntaje - $puntaje_minimo) / ($puntaje_maximo - $puntaje_minimo)) * 3.0;
    }
}

// Función inversa: calcular puntaje basado en nota, rendimiento mínimo y puntaje total máximo
function calcular_puntaje_desde_nota($nota, $puntaje_minimo, $puntaje_maximo, $nota_minima = 1.0) {
    if ($nota <= $nota_minima) return 0.0;
    if ($nota >= 7.0) return $puntaje_maximo;
    
    if ($nota <= 4.0) {
        // De nota_minima a 4.0: puntaje de 0 a puntaje_minimo
        return (($nota - $nota_minima) / (4.0 - $nota_minima)) * $puntaje_minimo;
    } else {
        // De nota 4.0 a 7.0: puntaje de puntaje_minimo a puntaje_maximo
        return $puntaje_minimo + (($nota - 4.0) / 3.0) * ($puntaje_maximo - $puntaje_minimo);
    }
}

// Función para formatear nota sin ceros finales (1.1 en lugar de 1.10, 2.0 en lugar de 2.00)
function formatear_nota($nota) {
    $nota_formateada = number_format($nota, 1, '.', '');
    // Eliminar el cero final si existe (1.0 -> 1, 1.10 -> 1.1, pero 1.15 -> 1.15)
    if (substr($nota_formateada, -2) === '.0') {
        return substr($nota_formateada, 0, -2);
    }
    return $nota_formateada;
}

// Función para formatear puntaje sin ceros finales (1.1 en lugar de 1.10, 2.0 en lugar de 2.00)
function formatear_puntaje($puntaje) {
    $puntaje_formateado = number_format($puntaje, 2, '.', '');
    // Eliminar ceros finales (1.00 -> 1, 1.10 -> 1.1, 1.15 -> 1.15)
    $puntaje_formateado = rtrim($puntaje_formateado, '0');
    $puntaje_formateado = rtrim($puntaje_formateado, '.');
    return $puntaje_formateado;
}

// Obtener o generar escala de notas
$puntaje_minimo_requerido = $puntaje_total_maximo * $rendimiento_minimo / 100;
$stmt_escala = $conn->prepare("SELECT * FROM escala_notas_curso WHERE id_curso = ? ORDER BY nota ASC");
$stmt_escala->bind_param("i", $id_curso_activo);
$stmt_escala->execute();
$escala_result = $stmt_escala->get_result();
$escala_notas = [];
while ($row = $escala_result->fetch_assoc()) {
    $escala_notas[] = $row;
}
$stmt_escala->close();

// Si no hay escala o el puntaje máximo/rendimiento mínimo cambió, regenerar
$necesita_regenerar = false;
if (empty($escala_notas)) {
    $necesita_regenerar = true;
} else {
    // Verificar si el puntaje máximo cambió
    $max_puntaje_escala = max(array_column($escala_notas, 'puntaje'));
    if (abs($max_puntaje_escala - $puntaje_total_maximo) > 0.01) {
        $necesita_regenerar = true;
    }
    
    // Verificar si el rendimiento mínimo o nota mínima cambió (verificando que la nota 4.0 corresponda al puntaje mínimo)
    if (!$necesita_regenerar) {
        // Buscar la entrada con nota 4.0 (o la más cercana)
        $puntaje_para_nota_4 = null;
        foreach ($escala_notas as $item) {
            if (abs((float)$item['nota'] - 4.0) < 0.05) {
                $puntaje_para_nota_4 = (float)$item['puntaje'];
                break;
            }
        }
        if ($puntaje_para_nota_4 === null || abs($puntaje_para_nota_4 - $puntaje_minimo_requerido) > 0.01) {
            $necesita_regenerar = true;
        }
        // Verificar si la nota mínima cambió (verificando que el primer puntaje tenga la nota mínima correcta)
        if (!$necesita_regenerar && !empty($escala_notas)) {
            $primer_item = $escala_notas[0];
            $nota_calculada = calcular_nota_escala((float)$primer_item['puntaje'], $puntaje_minimo_requerido, $puntaje_total_maximo, $nota_minima);
            if (abs((float)$primer_item['nota'] - $nota_calculada) > 0.05) {
                $necesita_regenerar = true;
            }
        }
    }
}

if ($necesita_regenerar && $puntaje_total_maximo > 0) {
    // Eliminar escala antigua
    $conn->query("DELETE FROM escala_notas_curso WHERE id_curso = $id_curso_activo");
    
    // Generar nueva escala basada en puntajes enteros (0, 1, 2, 3... hasta el máximo)
    // Esto asegura que solo se muestren puntajes enteros ya que no es posible conseguir un puntaje decimal
    $escala_notas = [];
    $orden = 0;
    for ($puntaje = 0; $puntaje <= $puntaje_total_maximo; $puntaje++) {
        $nota = calcular_nota_escala($puntaje, $puntaje_minimo_requerido, $puntaje_total_maximo, $nota_minima);
        $nota = round($nota, 1); // Redondear a 1 decimal
        
        $stmt_ins = $conn->prepare("INSERT INTO escala_notas_curso (id_curso, puntaje, nota, orden) VALUES (?, ?, ?, ?)");
        $stmt_ins->bind_param("iddi", $id_curso_activo, $puntaje, $nota, $orden);
        $stmt_ins->execute();
        $stmt_ins->close();
        $escala_notas[] = [
            'id' => $conn->insert_id,
            'puntaje' => $puntaje,
            'nota' => $nota,
            'orden' => $orden
        ];
        $orden++;
    }
}

$status_message = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
$error_message = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
$success_message = isset($_GET['success']) ? htmlspecialchars($_GET['success']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Criterios y Escala de Notas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
    <link rel="stylesheet" href="public/assets/css/min/gestionar_criterios.min.css" />
    <style>
        .rubrica-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .rubrica-table th,
        .rubrica-table td {
            border: 1px solid #dee2e6;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }
        .rubrica-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            text-align: center;
        }
        .rubrica-table .criterio-cell {
            min-width: 200px;
            font-weight: 500;
        }
        .rubrica-table .opcion-cell {
            min-width: 150px;
            text-align: center;
        }
        .rubrica-table .puntaje-header {
            font-size: 0.9em;
            color: #666;
        }
        .editable-input {
            width: 100%;
            border: none;
            background: transparent;
            padding: 4px;
        }
        .editable-input:focus {
            background: #fff;
            border: 1px solid #0d6efd;
            outline: none;
        }
        .btn-add-cell {
            padding: 4px 8px;
            font-size: 0.85em;
        }
        .escala-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 30px;
        }
        .escala-table th,
        .escala-table td {
            border: 1px solid #dee2e6;
            padding: 6px;
            text-align: center;
            font-size: 0.9em;
        }
        .escala-table th {
            background-color: #0d6efd;
            color: white;
            font-weight: bold;
        }
        .escala-table .puntaje-readonly {
            display: inline-block;
            padding: 4px 8px;
            text-align: center;
            font-weight: normal;
            color: inherit;
        }
        .escala-table .nota-roja {
            color: #dc3545;
            font-weight: bold;
        }
        .escala-table .nota-azul {
            color: #0d6efd;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1>Criterios y Escala de Notas</h1>
                <p class="lead mb-0">Curso: <strong><?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre'] . '-' . $curso_activo['anio']); ?></strong></p>
            </div>
            <div>
                <a href="dashboard_docente.php" class="btn btn-secondary">
                    ← Volver al curso activo
                </a>
            </div>
        </div>

        <?php if ($status_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $status_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3>Rúbrica de Evaluación</h3>
            <div>
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAgregarCriterio">
                    + Agregar Criterio
                </button>
                <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalAgregarOpcion">
                    + Agregar Opción
                </button>
                <button type="button" class="btn btn-warning" onclick="guardarTodosLosCambios()" id="btnGuardarCambios">
                    💾 Guardar Cambios
                </button>
                <a href="exportar_rubrica.php?id_curso=<?php echo $id_curso_activo; ?>" class="btn btn-primary">
                    📊 Exportar a Excel
                </a>
                <a href="exportar_rubrica_pdf.php?id_curso=<?php echo $id_curso_activo; ?>" class="btn btn-danger" target="_blank">
                    📄 Exportar a PDF
                </a>
                <a href="exportar_rubrica_csv.php?id_curso=<?php echo $id_curso_activo; ?>" class="btn btn-secondary">
                    📋 Exportar a CSV
                </a>
                <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalImportarRubrica">
                    📥 Importar desde CSV
                </button>
            </div>
        </div>

        <div class="mb-3 p-3 border rounded bg-light">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <strong>Puntaje Total Máximo: <span id="puntaje-total"><?php echo number_format($puntaje_total_maximo, 2); ?></span></strong>
                            </div>
                <div class="col-md-6">
                    <div class="d-flex align-items-center gap-2">
                        <label for="nota_minima" class="form-label mb-0"><strong>Nota Mínima:</strong></label>
                        <select class="form-select form-select-sm" 
                                id="nota_minima" 
                                style="width: 80px;"
                                onchange="guardarNotaMinima(this.value)">
                            <option value="1.0" <?php echo $nota_minima == 1.0 ? 'selected' : ''; ?>>1.0</option>
                            <option value="2.0" <?php echo $nota_minima == 2.0 ? 'selected' : ''; ?>>2.0</option>
                        </select>
                        <label for="rendimiento_minimo" class="form-label mb-0 ms-3"><strong>Rendimiento Mínimo:</strong></label>
                        <input type="number" 
                               class="form-control form-control-sm" 
                               id="rendimiento_minimo" 
                               value="<?php echo number_format($rendimiento_minimo, 2); ?>" 
                               min="0" 
                               max="100" 
                               step="0.01"
                               style="width: 100px;"
                               onchange="guardarRendimientoMinimo(this.value)">
                        <span>%</span>
                        <small class="text-muted ms-2">
                            (Nota mínima: <span id="nota-minima"><?php echo number_format(4.0, 1); ?></span>)
                        </small>
                            </div>
                    <small class="text-muted d-block mt-1">
                        Si el estudiante obtiene el <span id="rendimiento-minimo-display"><?php echo number_format($rendimiento_minimo, 2); ?></span>% del puntaje total máximo (<span id="puntaje-minimo-requerido"><?php echo number_format($puntaje_total_maximo * $rendimiento_minimo / 100, 2); ?></span> puntos), aprueba con nota 4.0
                    </small>
                    </div>
                </div>
            </div>

        <div class="table-responsive">
            <table class="table table-bordered rubrica-table">
                    <thead>
                        <tr>
                        <th class="criterio-cell">Criterios</th>
                        <?php if (empty($opciones)): ?>
                            <th class="opcion-cell">No hay opciones definidas</th>
                        <?php else: ?>
                            <?php foreach ($opciones as $opcion): ?>
                                <th class="opcion-cell">
                                    <div>
                                        <input type="text" 
                                               class="editable-input text-center fw-bold" 
                                               value="<?php echo htmlspecialchars($opcion['nombre']); ?>"
                                               data-opcion-id="<?php echo $opcion['id']; ?>"
                                               onchange="actualizarOpcion(<?php echo $opcion['id']; ?>, 'nombre', this.value)"
                                               style="font-weight: bold;">
                                    </div>
                                    <div class="puntaje-header mt-2">
                                        <small class="text-muted d-block mb-1">Puntaje:</small>
                                        <input type="number" 
                                               class="form-control form-control-sm text-center" 
                                               value="<?php echo number_format($opcion['puntaje'], 2); ?>"
                                               step="0.01"
                                               data-opcion-id="<?php echo $opcion['id']; ?>"
                                               onchange="actualizarOpcion(<?php echo $opcion['id']; ?>, 'puntaje', this.value); actualizarPuntajeTotal();"
                                               style="width: 100%;">
                                    </div>
                                    <div class="mt-2 d-flex gap-1">
                                        <button type="button" 
                                                class="btn btn-sm btn-danger btn-add-cell flex-fill"
                                                onclick="eliminarOpcion(<?php echo $opcion['id']; ?>)"
                                                title="Eliminar opción">
                                            🗑️ Eliminar
                                        </button>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($criterios)): ?>
                        <tr>
                            <td colspan="<?php echo max(2, count($opciones) + 1); ?>" class="text-center">
                                No hay criterios definidos. Agrega criterios para comenzar.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($criterios as $criterio): ?>
                            <tr>
                                <td class="criterio-cell">
                                    <div class="d-flex align-items-start gap-2">
                                        <input type="text" 
                                               class="form-control form-control-sm fw-bold" 
                                               value="<?php echo htmlspecialchars($criterio['descripcion']); ?>"
                                               data-criterio-id="<?php echo $criterio['id']; ?>"
                                               onchange="actualizarCriterio(<?php echo $criterio['id']; ?>, 'descripcion', this.value)"
                                               style="flex: 1;">
                                        <div class="d-flex flex-column gap-1">
                                            <button type="button" 
                                                    class="btn btn-sm btn-danger"
                                                    onclick="eliminarCriterio(<?php echo $criterio['id']; ?>)">
                                                ×
                                            </button>
                                    <?php if ($criterio['activo']): ?>
                                        <span class="badge bg-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactivo</span>
                                    <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <?php if (empty($opciones)): ?>
                                    <td class="text-center text-muted">-</td>
                                <?php else: ?>
                                    <?php foreach ($opciones as $opcion): ?>
                                        <td class="opcion-cell">
                                            <textarea class="form-control form-control-sm editable-input" 
                                                      rows="3"
                                                      placeholder="Descripción para <?php echo htmlspecialchars($opcion['nombre']); ?>"
                                                      data-criterio-id="<?php echo $criterio['id']; ?>"
                                                      data-opcion-id="<?php echo $opcion['id']; ?>"
                                                      onchange="guardarDescripcionCriterioOpcion(<?php echo $criterio['id']; ?>, <?php echo $opcion['id']; ?>, this.value)"><?php 
                                                echo isset($descripciones[$criterio['id']][$opcion['id']]) 
                                                    ? htmlspecialchars($descripciones[$criterio['id']][$opcion['id']]) 
                                                    : ''; 
                                            ?></textarea>
                                        </td>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Tabla de Escala de Notas -->
        <div class="mt-5">
            <h3>Escala de Notas</h3>
            <p class="text-muted">Los puntajes son editables. Las notas se calculan automáticamente basándose en el rendimiento mínimo.</p>
            <div class="table-responsive">
                <table class="table table-bordered escala-table">
                    <thead>
                        <tr>
                            <th>Puntaje</th>
                            <th>Nota</th>
                        </tr>
                    </thead>
                    <tbody id="escala-notas-body">
                        <?php
                        // Mostrar la escala en orden vertical (una fila por cada puntaje)
                        foreach ($escala_notas as $item):
                            $puntaje = (float)$item['puntaje'];
                            $nota = (float)$item['nota'];
                            $id_escala = $item['id'];
                            // Determinar color: rojo si nota < 4.0, azul si >= 4.0
                            $clase_nota = $nota < 4.0 ? 'nota-roja' : 'nota-azul';
                        ?>
                            <tr>
                                <td>
                                    <span class="puntaje-readonly"><?php echo (int)$puntaje; ?></span>
                                </td>
                                <td class="<?php echo $clase_nota; ?>" id="nota-<?php echo $id_escala; ?>">
                                    <?php echo formatear_nota($nota); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Agregar Criterio -->
    <div class="modal fade" id="modalAgregarCriterio" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Agregar Nuevo Criterio</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="criterios_actions.php" method="POST">
                <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="descripcion_criterio" class="form-label">Descripción del Criterio</label>
                            <textarea class="form-control" name="descripcion" id="descripcion_criterio" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="orden_criterio" class="form-label">Orden</label>
                            <input type="number" class="form-control" name="orden" id="orden_criterio" value="100" required>
                            <small class="text-muted">Número menor aparece primero</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Agregar Criterio</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Agregar Opción -->
    <div class="modal fade" id="modalAgregarOpcion" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Agregar Nueva Opción de Evaluación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="criterios_actions.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_opcion">
                        <div class="mb-3">
                            <label for="nombre_opcion" class="form-label">Nombre de la Opción</label>
                            <input type="text" class="form-control" name="nombre" id="nombre_opcion" required placeholder="Ej: Cumple, Bien, Excelente">
                        </div>
                        <div class="mb-3">
                            <label for="puntaje_opcion" class="form-label">Puntaje</label>
                            <input type="number" class="form-control" name="puntaje" id="puntaje_opcion" step="0.01" value="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="orden_opcion" class="form-label">Orden</label>
                            <input type="number" class="form-control" name="orden" id="orden_opcion" value="100" required>
                            <small class="text-muted">Número menor aparece primero</small>
                        </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Agregar Opción</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Importar Rúbrica -->
    <div class="modal fade" id="modalImportarRubrica" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Importar Rúbrica desde CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="importar_rubrica.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <strong>Formato esperado del CSV:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Primera columna: Criterios</li>
                                <li>Siguientes columnas: Opciones con formato "Nombre (Puntaje: X.XX)"</li>
                                <li>Las filas después de los encabezados contienen los criterios y sus descripciones</li>
                            </ul>
                            <p class="mb-0 mt-2"><small>Puedes exportar primero una rúbrica para ver el formato exacto.</small></p>
                        </div>
                        <div class="mb-3">
                            <label for="archivo_csv" class="form-label">Seleccionar archivo CSV</label>
                            <input type="file" class="form-control" name="archivo_csv" id="archivo_csv" accept=".csv" required>
                            <small class="text-muted">Solo archivos .csv</small>
                        </div>
                        <div class="alert alert-warning">
                            <strong>⚠️ Advertencia:</strong> La importación reemplazará los criterios y opciones actuales. Los elementos existentes se marcarán como inactivos si no están en el archivo CSV.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Importar Rúbrica</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function actualizarCriterio(id, campo, valor) {
            // Marcar que hay cambios pendientes
            cambiosPendientes.add(`criterio_${id}_${campo}`);
        }

        // Variable para rastrear cambios pendientes
        let cambiosPendientes = new Set();
        let puntajeTotalActualizado = false;

        function actualizarOpcion(id, campo, valor) {
            // Marcar que hay cambios pendientes
            cambiosPendientes.add(`opcion_${id}_${campo}`);
            
            // Si es un cambio de puntaje, actualizar inmediatamente el puntaje total y la escala
            if (campo === 'puntaje') {
                actualizarPuntajeTotal();
                puntajeTotalActualizado = true;
            }
        }

        function guardarDescripcionCriterioOpcion(idCriterio, idOpcion, descripcion) {
            // Marcar que hay cambios pendientes
            cambiosPendientes.add(`descripcion_${idCriterio}_${idOpcion}`);
        }

        function eliminarCriterio(id) {
            if (!confirm('¿Estás seguro de eliminar este criterio?')) {
                return;
            }
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'criterios_actions.php';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id_criterio" value="${id}">
                <input type="hidden" name="confirm" value="yes">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function eliminarOpcion(id) {
            if (!confirm('¿Estás seguro de eliminar esta opción? Esto afectará todas las evaluaciones.')) {
                return;
            }
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'criterios_actions.php';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_opcion">
                <input type="hidden" name="id_opcion" value="${id}">
        <input type="hidden" name="confirm" value="yes">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function actualizarPuntajeTotal() {
            // Recalcular el puntaje total máximo
            // Obtener todos los puntajes de opciones
            const inputsPuntaje = document.querySelectorAll('input[type="number"][data-opcion-id]');
            let maxPuntaje = 0;
            inputsPuntaje.forEach(input => {
                if (input.value) {
                    const puntaje = parseFloat(input.value);
                    if (puntaje > maxPuntaje) {
                        maxPuntaje = puntaje;
                    }
                }
            });
            // Contar criterios activos
            const criteriosActivos = <?php echo count(array_filter($criterios, function($c) { return $c['activo']; })); ?>;
            const total = maxPuntaje * criteriosActivos;
            document.getElementById('puntaje-total').textContent = total.toFixed(2);
            
            // Actualizar también el puntaje mínimo requerido
            const rendimientoMinimo = parseFloat(document.getElementById('rendimiento_minimo').value) || <?php echo $rendimiento_minimo; ?>;
            const puntajeMinimo = (total * rendimientoMinimo / 100).toFixed(2);
            document.getElementById('puntaje-minimo-requerido').textContent = puntajeMinimo;
            
            // Nota: La escala de notas se regenerará automáticamente cuando se guarde el cambio
            // o cuando se recargue la página después de guardar
        }

        function guardarTodosLosCambios() {
            if (cambiosPendientes.size === 0 && !puntajeTotalActualizado) {
                alert('No hay cambios pendientes para guardar.');
                return;
            }

            if (!confirm('¿Desea guardar todos los cambios realizados? Esto actualizará el puntaje total máximo y regenerará la escala de notas.')) {
                return;
            }

            // Guardar todos los cambios pendientes
            const promesas = [];
            
            cambiosPendientes.forEach(cambio => {
                const partes = cambio.split('_');
                
                if (partes[0] === 'opcion') {
                    // Cambios en opciones
                    const id = partes[1];
                    const campo = partes[2];
                    const input = document.querySelector(`input[data-opcion-id="${id}"][onchange*="${campo}"]`);
                    if (input) {
                        const valor = input.value;
                        promesas.push(
                            fetch('criterios_actions.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: `action=update_opcion&id_opcion=${id}&campo=${campo}&valor=${encodeURIComponent(valor)}`
                            }).then(response => response.json())
                        );
                    }
                } else if (partes[0] === 'criterio') {
                    // Cambios en criterios
                    const idCriterio = partes[1];
                    const campo = partes[2];
                    const input = document.querySelector(`input[data-criterio-id="${idCriterio}"][onchange*="${campo}"]`);
                    if (input) {
                        promesas.push(
                            fetch('criterios_actions.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: `action=update_campo&id_criterio=${idCriterio}&campo=${campo}&valor=${encodeURIComponent(input.value)}`
                            }).then(response => response.json())
                        );
                    }
                } else if (partes[0] === 'descripcion') {
                    // Cambios en descripciones
                    const idCriterio = partes[1];
                    const idOpcion = partes[2];
                    const textarea = document.querySelector(`textarea[data-criterio-id="${idCriterio}"][data-opcion-id="${idOpcion}"]`);
                    if (textarea) {
                        promesas.push(
                            fetch('criterios_actions.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: `action=save_descripcion&id_criterio=${idCriterio}&id_opcion=${idOpcion}&descripcion=${encodeURIComponent(textarea.value)}`
                            }).then(response => response.json())
                        );
                    }
                }
            });

            // Ejecutar todas las promesas
            Promise.all(promesas)
                .then(results => {
                    const errores = results.filter(r => !r.success);
                    if (errores.length > 0) {
                        alert('Algunos cambios no se pudieron guardar. Por favor, intente nuevamente.');
                        console.error('Errores:', errores);
                    } else {
                        alert('¡Todos los cambios se han guardado correctamente! La escala de notas se regenerará.');
                        // Recargar la página para regenerar la escala de notas
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al guardar los cambios. Por favor, intente nuevamente.');
                });
        }
        
        // Actualizar puntaje total al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            actualizarPuntajeTotal();
        });


        function guardarNotaMinima(valor) {
            const notaMinima = parseFloat(valor);
            if (isNaN(notaMinima) || (notaMinima !== 1.0 && notaMinima !== 2.0)) {
                alert('La nota mínima debe ser 1.0 o 2.0');
                return;
            }

            fetch('criterios_actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=update_nota_minima&nota_minima=${notaMinima}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Recargar la página para regenerar la escala
                    location.reload();
                } else {
                    alert('Error al guardar: ' + (data.error || 'Error desconocido'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al guardar la nota mínima');
            });
        }

        function guardarRendimientoMinimo(valor) {
            const porcentaje = parseFloat(valor);
            if (isNaN(porcentaje) || porcentaje < 0 || porcentaje > 100) {
                alert('El rendimiento mínimo debe estar entre 0 y 100%');
                return;
            }

            fetch('criterios_actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=update_rendimiento_minimo&rendimiento_minimo=${porcentaje}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('rendimiento-minimo-display').textContent = porcentaje.toFixed(2);
                    // La nota mínima siempre es 4.0 según el requerimiento
                    document.getElementById('nota-minima').textContent = '4.0';
                    // Actualizar puntaje mínimo requerido
                    const puntajeTotal = parseFloat(document.getElementById('puntaje-total').textContent);
                    const puntajeMinimo = (puntajeTotal * porcentaje / 100).toFixed(2);
                    document.getElementById('puntaje-minimo-requerido').textContent = puntajeMinimo;
                    // Recargar la página para regenerar la escala
                    location.reload();
                } else {
                    alert('Error al guardar: ' + (data.error || 'Error desconocido'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al guardar el rendimiento mínimo');
            });
        }

        // Función para formatear puntaje sin ceros finales (1.1 en lugar de 1.10, 2 en lugar de 2.00)
        function formatearPuntaje(puntaje) {
            if (puntaje % 1 === 0) {
                return puntaje.toString();
            }
            return puntaje.toFixed(2).replace(/\.?0+$/, '');
        }

        function calcularNotaEscala(puntaje, puntajeMinimo, puntajeMaximo, notaMinima = 1.0) {
            if (puntaje <= 0) return notaMinima;
            if (puntaje >= puntajeMaximo) return 7.0;
            
            if (puntaje <= puntajeMinimo) {
                // De 0 a puntaje_minimo: nota de notaMinima a 4.0
                return Math.round((notaMinima + (puntaje / puntajeMinimo) * (4.0 - notaMinima)) * 10) / 10;
            } else {
                // De puntaje_minimo a puntaje_maximo: nota de 4.0 a 7.0
                return Math.round((4.0 + ((puntaje - puntajeMinimo) / (puntajeMaximo - puntajeMinimo)) * 3.0) * 10) / 10;
            }
        }

    </script>
</body>
</html>
