<?php
require 'db.php';
// Esta página es usada por estudiantes y docentes
verificar_sesion();

if (!isset($_GET['id_equipo']) || !is_numeric($_GET['id_equipo'])) {
    header("Location: dashboard_estudiante.php"); exit();
}
$id_equipo_a_evaluar = $_GET['id_equipo'];

// Restricción para estudiantes, no para docentes
if (!$_SESSION['es_docente'] && $id_equipo_a_evaluar == $_SESSION['id_equipo']) {
    header("Location: dashboard_estudiante.php"); exit();
}

$stmt = $conn->prepare("SELECT nombre_equipo, id_curso FROM equipos WHERE id = ?");
$stmt->bind_param("i", $id_equipo_a_evaluar);
$stmt->execute();
$equipo = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$equipo) {
    header("Location: dashboard_estudiante.php?error=" . urlencode("Equipo no encontrado."));
    exit();
}

$id_curso_equipo = (int)$equipo['id_curso'];
if (!isset($_SESSION['id_curso_activo']) || $_SESSION['id_curso_activo'] != $id_curso_equipo) {
    $_SESSION['id_curso_activo'] = $id_curso_equipo;
}

$stmt_criterios = $conn->prepare("SELECT * FROM criterios WHERE activo = TRUE AND id_curso = ? ORDER BY orden ASC");
$stmt_criterios->bind_param("i", $id_curso_equipo);
$stmt_criterios->execute();
$criterios_result = $stmt_criterios->get_result();
$criterios = [];
while ($row = $criterios_result->fetch_assoc()) {
    $criterios[] = $row;
}
$stmt_criterios->close();

$escala_cualitativa = get_primary_scale($conn, $id_curso_equipo);
$conceptos_cualitativos = $escala_cualitativa ? get_scale_concepts($conn, (int)$escala_cualitativa['id']) : [];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Evaluar Equipo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between mb-4 gap-3">
            <div>
                <h1 class="mb-1">Evaluando a: <strong><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></strong></h1>
                <p class="mb-0">Evalúa cada criterio de 1 (deficiente) a 5 (excelente).</p>
            </div>
            <a class="btn btn-outline-primary" href="evaluar_cualitativo.php?id_equipo=<?php echo $id_equipo_a_evaluar; ?>">
                Abrir evaluación cualitativa
            </a>
        </div>

        <?php if (!empty($conceptos_cualitativos)): ?>
        <div class="alert alert-secondary mb-4">
            <h5 class="mb-3">Conceptos cualitativos disponibles</h5>
            <div class="row g-3">
                <?php foreach ($conceptos_cualitativos as $concepto): ?>
                    <div class="col-md-3 col-sm-6">
                        <div class="p-3 h-100 border rounded" style="border-left: .5rem solid <?php echo htmlspecialchars($concepto['color_hex']); ?>;">
                            <strong><?php echo htmlspecialchars($concepto['etiqueta']); ?></strong>
                            <small class="d-block text-muted mt-2">
                                <?php echo htmlspecialchars($concepto['descripcion'] ?? ''); ?>
                            </small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <small class="text-muted d-block mt-3">Usa estos conceptos como guía al completar la evaluación cualitativa.</small>
        </div>
        <?php endif; ?>

        <form action="procesar_evaluacion.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="id_equipo_evaluado" value="<?php echo $id_equipo_a_evaluar; ?>">
            <table class="table table-striped table-bordered align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Criterio</th>
                        <th class="text-center">Puntaje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($criterios as $criterio): ?>
                    <tr>
                        <td style="min-width: 260px;">
                            <strong><?php echo htmlspecialchars($criterio['descripcion']); ?></strong>
                            <!-- Descripción opcional -->
                            <textarea name="descripciones[<?php echo $criterio['id']; ?>]" class="form-control mt-2" rows="2" placeholder="Descripción opcional del puntaje asignado a este criterio"></textarea>
                            <?php if (!empty($conceptos_cualitativos)): ?>
                                <div class="mt-3">
                                    <label class="form-label small text-muted mb-1">Concepto cualitativo (opcional)</label>
                                    <select name="conceptos_cualitativos[<?php echo $criterio['id']; ?>]" class="form-select form-select-sm">
                                        <option value="">Selecciona una opción</option>
                                        <?php foreach ($conceptos_cualitativos as $concepto): ?>
                                            <option value="<?php echo $concepto['id']; ?>">
                                                <?php echo htmlspecialchars($concepto['etiqueta']); ?> — <?php echo htmlspecialchars($concepto['descripcion']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted d-block mt-1">Usa estos conceptos como guía al completar la evaluación cualitativa.</small>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php
                                $puntaje_maximo = isset($criterio['puntaje_maximo']) ? max(1, (int)$criterio['puntaje_maximo']) : 5;
                            ?>
                            <div class="d-flex flex-wrap gap-2 justify-content-center">
                                <?php for ($i = 1; $i <= $puntaje_maximo; $i++): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="criterios[<?php echo $criterio['id']; ?>]" value="<?php echo $i; ?>" required>
                                        <label class="form-check-label"><?php echo $i; ?></label>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <small class="text-muted d-block mt-2">Selecciona un puntaje de 1 a <?php echo $puntaje_maximo; ?>.</small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (!empty($conceptos_cualitativos) && !empty($criterios)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <strong>Observaciones cualitativas (opcional)</strong>
                    </div>
                    <div class="card-body">
                        <textarea name="observaciones_cualitativas" class="form-control" rows="3" placeholder="Puedes agregar observaciones generales que acompañen los conceptos seleccionados en cada criterio."></textarea>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="d-grid gap-2"><button type="submit" class="btn btn-primary btn-lg">Enviar Evaluación</button></div>
        </form>
    </div>

</body>
</html>