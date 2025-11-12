<?php
require 'db.php';
// Requerir ser docente Y tener un curso activo
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

// Consulta CLAVE: Filtrar criterios por el curso activo
$stmt_criterios = $conn->prepare("SELECT * FROM criterios WHERE id_curso = ? ORDER BY orden ASC");
$stmt_criterios->bind_param("i", $id_curso_activo);
$stmt_criterios->execute();
$criterios = $stmt_criterios->get_result();

// Opcional: Obtener el nombre del curso para el título
$stmt_curso = $conn->prepare("SELECT nombre_curso, semestre FROM cursos WHERE id = ?");
$stmt_curso->bind_param("i", $id_curso_activo);
$stmt_curso->execute();
$curso_activo = $stmt_curso->get_result()->fetch_assoc();
$stmt_curso->close();

$status_message = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
$error_message = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Criterios de Evaluación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard_docente.php">
                <?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' (' . $curso_activo['semestre'] . ')'); ?>
            </a>
            <a class="btn btn-outline-light" href="dashboard_docente.php">Volver al Dashboard</a>
        </div>
    </nav>

    <div class="container mt-5">
        <h1>Gestionar Criterios</h1>
        <p class="lead">Curso Activo: <strong><?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre']); ?></strong></p>

        <?php if ($status_message): ?>
            <div class="alert alert-success"><?php echo $status_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><h4>Añadir Nuevo Criterio</h4></div>
                    <div class="card-body">
                        <form action="criterios_actions.php" method="POST">
                            <input type="hidden" name="action" value="add">
                            <div class="mb-3">
                                <label for="descripcion" class="form-label">Descripción del Criterio</label>
                                <textarea class="form-control" name="descripcion" id="descripcion" rows="3" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="orden" class="form-label">Orden (número menor aparece primero)</label>
                                <input type="number" class="form-control" name="orden" id="orden" value="100" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Añadir Criterio</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <h4>Criterios Actuales del Curso</h4>
                <table class="table table-striped">
                    <thead><tr><th>Orden</th><th>Descripción</th><th>Estado</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php if ($criterios->num_rows > 0): ?>
                            <?php while($criterio = $criterios->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $criterio['orden']; ?></td>
                                <td><?php echo htmlspecialchars($criterio['descripcion']); ?></td>
                                <td>
                                    <?php if ($criterio['activo']): ?>
                                        <span class="badge bg-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form action="criterios_actions.php" method="POST" class="d-inline">
                                        <input type="hidden" name="id_criterio" value="<?php echo $criterio['id']; ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <button type="submit" class="btn btn-sm btn-warning">
                                            <?php echo $criterio['activo'] ? 'Desactivar' : 'Activar'; ?>
                                        </button>
                                    </form>
                                    <form action="criterios_actions.php" method="POST" class="d-inline">
                                        <input type="hidden" name="id_criterio" value="<?php echo $criterio['id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('¿Estás seguro de que quieres eliminar este criterio? Esto no se puede deshacer.');">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center">Aún no hay criterios definidos para este curso.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>