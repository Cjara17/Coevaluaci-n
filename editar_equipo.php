<?php
include 'header.php';
require 'db.php';
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

$id_equipo = isset($_GET['id_equipo']) ? (int)$_GET['id_equipo'] : 0;

if ($id_equipo === 0) {
    header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("ID de equipo no proporcionado."));
    exit();
}

// Obtener información del equipo
$stmt_equipo = $conn->prepare("SELECT nombre_equipo FROM equipos WHERE id = ? AND id_curso = ?");
$stmt_equipo->bind_param("ii", $id_equipo, $id_curso_activo);
$stmt_equipo->execute();
$equipo = $stmt_equipo->get_result()->fetch_assoc();
$stmt_equipo->close();

if (!$equipo) {
    header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Equipo no encontrado."));
    exit();
}

// Obtener información del curso activo
$stmt_curso = $conn->prepare("SELECT nombre_curso, semestre, anio FROM cursos WHERE id = ?");
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
    <title>Editar Equipo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
</head>
<body style="padding-bottom: 120px;">
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1>Editar Equipo</h1>
                <p class="text-muted mb-0">Curso: <strong><?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre'] . '-' . $curso_activo['anio']); ?></strong></p>
            </div>
            <a href="gestionar_estudiantes_equipos.php" class="btn btn-secondary">← Volver</a>
        </div>

        <?php if ($status_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $status_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Editar Nombre del Equipo</h5>
                    </div>
                    <div class="card-body">
                        <form action="equipos_actions.php" method="POST">
                            <input type="hidden" name="action" value="update_equipo_nombre">
                            <input type="hidden" name="id_equipo" value="<?php echo $id_equipo; ?>">
                            <div class="mb-3">
                                <label for="nuevo_nombre" class="form-label">Nombre del Equipo</label>
                                <input type="text" class="form-control" id="nuevo_nombre" name="nuevo_nombre" value="<?php echo htmlspecialchars($equipo['nombre_equipo']); ?>" required>
                            </div>
                            <div class="d-flex justify-content-between">
                                <a href="gestionar_estudiantes_equipos.php" class="btn btn-secondary">Cancelar</a>
                                <button type="submit" class="btn btn-warning">Guardar Cambios</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
