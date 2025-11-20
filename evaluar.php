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
$criterios = $stmt_criterios->get_result();

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
        <h1 class="mb-4">Evaluando a: <strong><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></strong></h1>
        <p>Evalúa cada criterio de 1 (deficiente) a 5 (excelente).</p>

        <form action="procesar_evaluacion.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="id_equipo_evaluado" value="<?php echo $id_equipo_a_evaluar; ?>">
            <table class="table table-striped table-bordered">
                <thead class="table-dark"><tr><th>Criterio</th><th class="text-center" colspan="5">Puntaje</th></tr></thead>
                <tbody>
                    <?php while($criterio = $criterios->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($criterio['descripcion']); ?></strong>
                        <!-- NUEVO: descripción opcional del criterio -->
                        <textarea name="descripciones[<?php echo $criterio['id']; ?>]" class="form-control mt-2" rows="2" placeholder="Descripción opcional del puntaje asignado a este criterio"></textarea></td>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <td class="text-center">
                            <!-- El nombre del input es un array que usa el ID del criterio como clave -->
                            <input class="form-check-input" type="radio" name="criterios[<?php echo $criterio['id']; ?>]" value="<?php echo $i; ?>" required>
                            <label class="form-check-label ms-1"><?php echo $i; ?></label>
                        </td>
                        <?php endfor; ?>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <div class="d-grid gap-2"><button type="submit" class="btn btn-primary btn-lg">Enviar Evaluación</button></div>
        </form>
    </div>
</body>
</html>