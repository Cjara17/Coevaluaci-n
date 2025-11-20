<?php
require 'db.php';
verificar_sesion(); // Permitir a cualquier evaluador autenticado acceder

if (!isset($_GET['id_equipo']) || !is_numeric($_GET['id_equipo'])) {
    header("Location: dashboard_docente.php?error=" . urlencode("Equipo no especificado."));
    exit();
}

$id_equipo = (int)$_GET['id_equipo'];

$stmt_equipo = $conn->prepare("
    SELECT e.id, e.nombre_equipo, e.id_curso, c.nombre_curso, c.semestre, c.anio
    FROM equipos e
    JOIN cursos c ON e.id_curso = c.id
    WHERE e.id = ?
");
$stmt_equipo->bind_param("i", $id_equipo);
$stmt_equipo->execute();
$equipo = $stmt_equipo->get_result()->fetch_assoc();
$stmt_equipo->close();

if (!$equipo) {
    header("Location: dashboard_docente.php?error=" . urlencode("Equipo no encontrado."));
    exit();
}

$id_curso_equipo = (int)$equipo['id_curso'];

if (!isset($_SESSION['id_curso_activo']) || (int)$_SESSION['id_curso_activo'] !== $id_curso_equipo) {
    // Alinear curso activo para mantener consistencia en dashboards
    $_SESSION['id_curso_activo'] = $id_curso_equipo;
}

$escala = ensure_default_qualitative_scale($conn, $id_curso_equipo, $_SESSION['id_usuario']);
$conceptos = get_scale_concepts($conn, (int)$escala['id']);

$stmt_criterios = $conn->prepare("SELECT id, descripcion FROM criterios WHERE id_curso = ? AND activo = 1 ORDER BY orden ASC");
$stmt_criterios->bind_param("i", $id_curso_equipo);
$stmt_criterios->execute();
$criterios = $stmt_criterios->get_result();

if ($criterios->num_rows === 0) {
    header("Location: gestionar_conceptos.php?error=" . urlencode("Aún no hay criterios activos en este curso."));
    exit();
}

if (count($conceptos) === 0) {
    header("Location: gestionar_conceptos.php?error=" . urlencode("Debes definir al menos un concepto cualitativo antes de evaluar."));
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Evaluación cualitativa - <?php echo htmlspecialchars($equipo['nombre_equipo']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="mb-1">Evaluación cualitativa</h1>
                <p class="mb-0 text-muted">
                    Equipo: <strong><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></strong> ·
                    Curso: <?php echo htmlspecialchars($equipo['nombre_curso'] . ' ' . $equipo['semestre'] . '-' . $equipo['anio']); ?>
                </p>
                <small class="text-muted">Escala: <?php echo htmlspecialchars($escala['nombre']); ?></small>
            </div>
            <a href="dashboard_docente.php" class="btn btn-outline-secondary">← Volver</a>
        </div>

        <div class="alert alert-secondary">
            <h5 class="mb-3">Conceptos disponibles</h5>
            <div class="row g-3">
                <?php foreach ($conceptos as $concepto): ?>
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
        </div>

        <form action="procesar_evaluacion_cualitativa.php" method="POST" class="needs-validation" novalidate>
            <input type="hidden" name="id_equipo_evaluado" value="<?php echo $id_equipo; ?>">
            <input type="hidden" name="id_escala" value="<?php echo (int)$escala['id']; ?>">

            <?php while ($criterio = $criterios->fetch_assoc()): ?>
                <div class="card mb-3 shadow-sm">
                    <div class="card-header bg-light">
                        <strong><?php echo htmlspecialchars($criterio['descripcion']); ?></strong>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php foreach ($conceptos as $concepto): ?>
                                <?php $input_id = 'criterio-' . $criterio['id'] . '-concepto-' . $concepto['id']; ?>
                                <div class="col-md-3 col-sm-6">
                                    <input type="radio"
                                           class="btn-check"
                                           name="conceptos[<?php echo $criterio['id']; ?>]"
                                           id="<?php echo $input_id; ?>"
                                           value="<?php echo $concepto['id']; ?>"
                                           required>
                                    <label class="btn w-100 text-white"
                                           style="background-color: <?php echo htmlspecialchars($concepto['color_hex']); ?>;"
                                           for="<?php echo $input_id; ?>">
                                           <strong><?php echo htmlspecialchars($concepto['etiqueta']); ?></strong>
                                           <br>
                                           <small class="text-light"><?php echo htmlspecialchars($concepto['descripcion']); ?></small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- NUEVO: descripción opcional del criterio cualitativo -->
                        <textarea
                            name="descripciones[<?php echo $criterio['id']; ?>]"
                            class="form-control mt-2"
                            rows="2"
                            placeholder="Descripción opcional del concepto asignado a este criterio">
                        </textarea>
                    </div>
                </div>
            <?php endwhile; ?>

            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-light">
                    <strong>Observaciones generales (opcional)</strong>
                </div>
                <div class="card-body">
                    <textarea name="observaciones" class="form-control" rows="4" placeholder="Describe aspectos destacables, oportunidades de mejora, acuerdos, etc."></textarea>
                </div>
            </div>

            <div class="alert alert-info small">
                Las evaluaciones cualitativas no modifican las notas numéricas automáticamente. Sirven para registrar retroalimentación descriptiva y se pueden descargar junto al historial del equipo.
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">Guardar evaluación</button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

