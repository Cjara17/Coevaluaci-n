<?php
// NUEVO: se agregó header global institucional UCT
include 'header.php';
require 'db.php';
// Requerir ser docente Y tener un curso activo
verificar_sesion(true);

$id_docente = $_SESSION['id_usuario'];
$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

// Consulta CLAVE: Filtrar equipos por el curso activo
$stmt_equipos = $conn->prepare("SELECT * FROM equipos WHERE id_curso = ? ORDER BY nombre_equipo ASC");
$stmt_equipos->bind_param("i", $id_curso_activo);
$stmt_equipos->execute();
$equipos = $stmt_equipos->get_result();

// 1. OBTENER INFORMACIÓN DEL CURSO ACTIVO (para mostrar el título)
$stmt_curso = $conn->prepare("SELECT nombre_curso, semestre, anio FROM cursos WHERE id = ?");
$stmt_curso->bind_param("i", $id_curso_activo);
$stmt_curso->execute();
$curso_activo = $stmt_curso->get_result()->fetch_assoc();
$stmt_curso->close();

// 2. OBTENER TODOS LOS CURSOS DEL DOCENTE (para el selector en el navbar)
$sql_all_cursos = "
    SELECT c.id, c.nombre_curso, c.semestre, c.anio
    FROM cursos c
    JOIN docente_curso dc ON c.id = dc.id_curso
    WHERE dc.id_docente = ?
    ORDER BY c.anio DESC, c.semestre DESC";
$stmt_all_cursos = $conn->prepare($sql_all_cursos);
$stmt_all_cursos->bind_param("i", $id_docente);
$stmt_all_cursos->execute();
$all_cursos = $stmt_all_cursos->get_result();

$status_message = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
$error_message = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Docente</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- NUEVO: se eliminó navbar antiguo tras implementación de header institucional UCT -->

    <div class="container mt-5">
        <h1>Dashboard Docente</h1>
        <p class="lead">Curso Activo: <strong><?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre']); ?></strong></p>

        <?php if ($status_message): ?>
            <div class="alert alert-success"><?php echo $status_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h4>Equipos del Curso</h4></div>
                    <div class="card-body">
                        <p>Equipos registrados en el curso actual:</p>
                        <ul class="list-group">
                            <?php if ($equipos->num_rows > 0): ?>
                                <?php while($equipo = $equipos->fetch_assoc()): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?php echo htmlspecialchars($equipo['nombre_equipo']); ?>
                                    <div class="d-flex gap-2">
                                        <a href="ver_detalles.php?id_equipo=<?php echo $equipo['id']; ?>" class="btn btn-info btn-sm">Ver Detalles</a>
                                        <a href="gestionar_presentacion.php?id_equipo=<?php echo $equipo['id']; ?>" class="btn btn-warning btn-sm">Presentación</a>
                                    </div>
                                </li>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <li class="list-group-item text-center">Aún no hay equipos registrados en este curso.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h4>Acciones Rápidas</h4></div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="create_evaluator.php" class="btn btn-primary">Crear Evaluador</a>
                            <a href="gestionar_criterios.php" class="btn btn-secondary">Gestionar Criterios</a>
                            <a href="gestionar_conceptos.php" class="btn btn-secondary">Gestionar Conceptos</a>
                            <a href="import_students.php" class="btn btn-success">Importar Estudiantes</a>
                            <a href="export_results.php" class="btn btn-info">Exportar Resultados</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
