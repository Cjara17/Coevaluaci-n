<?php
// NUEVO: se agregó header global institucional UCT
include 'header.php';
require 'db.php';
// Requerir ser docente Y tener un curso activo
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['id_equipo']) && isset($_POST['accion'])) {

    $id_equipo = (int)$_POST['id_equipo'];
    $accion = $_POST['accion'];

    $nuevo_estado = '';
    $mensaje = '';

    switch ($accion) {
        case 'iniciar':
            $nuevo_estado = 'presentando';
            $mensaje = 'Presentación iniciada con éxito.';
            break;
        case 'terminar':
            $nuevo_estado = 'finalizado';
            $mensaje = 'Presentación finalizada con éxito.';
            break;
        case 'reiniciar':
            $nuevo_estado = 'pendiente';
            $mensaje = 'Presentación reiniciada con éxito.';
            break;
        default:
            // Acción inválida, redirigir sin hacer nada
            header("Location: dashboard_docente.php?error=" . urlencode("Acción inválida."));
            exit();
    }

    // Consulta CLAVE: Actualiza el estado, pero SOLO si el equipo pertenece al curso activo
    $stmt = $conn->prepare("UPDATE equipos SET estado_presentacion = ? WHERE id = ? AND id_curso = ?");
    $stmt->bind_param("sii", $nuevo_estado, $id_equipo, $id_curso_activo);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            // Si viene de ver_evaluacion.php, redirigir de vuelta allí
            $id_evaluacion = isset($_POST['id_evaluacion']) ? (int)$_POST['id_evaluacion'] : null;
            if ($id_evaluacion) {
                header("Location: ver_evaluacion.php?id=" . $id_evaluacion . "&status=" . urlencode($mensaje));
            } else {
                header("Location: dashboard_docente.php?status=" . urlencode($mensaje));
            }
        } else {
             // Esto ocurre si el equipo no existe O si no pertenece al curso activo.
            $id_evaluacion = isset($_POST['id_evaluacion']) ? (int)$_POST['id_evaluacion'] : null;
            if ($id_evaluacion) {
                header("Location: ver_evaluacion.php?id=" . $id_evaluacion . "&error=" . urlencode("Error: Equipo no encontrado o no pertenece al curso activo."));
            } else {
                header("Location: dashboard_docente.php?error=" . urlencode("Error: Equipo no encontrado o no pertenece al curso activo."));
            }
        }
    } else {
        $id_evaluacion = isset($_POST['id_evaluacion']) ? (int)$_POST['id_evaluacion'] : null;
        if ($id_evaluacion) {
            header("Location: ver_evaluacion.php?id=" . $id_evaluacion . "&error=" . urlencode("Error al actualizar el estado: " . $stmt->error));
        } else {
            header("Location: dashboard_docente.php?error=" . urlencode("Error al actualizar el estado: " . $stmt->error));
        }
    }

    $stmt->close();
    $conn->close();
    exit();

} elseif ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['id_equipo'])) {
    // Mostrar pantalla de presentación
    $id_equipo = (int)$_GET['id_equipo'];

    // Verificar que el equipo pertenece al curso activo
    $stmt = $conn->prepare("SELECT nombre_equipo, estado_presentacion FROM equipos WHERE id = ? AND id_curso = ?");
    $stmt->bind_param("ii", $id_equipo, $id_curso_activo);
    $stmt->execute();
    $equipo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$equipo) {
        header("Location: dashboard_docente.php?error=" . urlencode("Equipo no encontrado o no pertenece al curso activo."));
        exit();
    }

    // Obtener miembros del equipo
    $stmt_miembros = $conn->prepare("SELECT nombre FROM usuarios WHERE id_equipo = ? AND id_curso = ?");
    $stmt_miembros->bind_param("ii", $id_equipo, $id_curso_activo);
    $stmt_miembros->execute();
    $miembros = $stmt_miembros->get_result();
    $stmt_miembros->close();

    // Obtener curso
    $stmt_curso = $conn->prepare("SELECT nombre_curso, semestre, anio FROM cursos WHERE id = ?");
    $stmt_curso->bind_param("i", $id_curso_activo);
    $stmt_curso->execute();
    $curso = $stmt_curso->get_result()->fetch_assoc();
    $stmt_curso->close();

    $conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Presentación del Equipo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body style="padding-bottom: 120px;">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard_docente.php">
                <img src="logo_uct.png" alt="TEC-UCT Logo" style="height: 30px;">
                Panel Docente
            </a>
            <div class="d-flex me-4">
                <a href="dashboard_docente.php" class="btn btn-outline-danger btn-sm">Volver al Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row">
            <div class="col-md-8">
                <h1>Presentación del Equipo: <strong><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></strong></h1>
                <p><strong>Curso:</strong> <?php echo htmlspecialchars($curso['nombre_curso'] . ' ' . $curso['semestre'] . '-' . $curso['anio']); ?></p>
                <p><strong>Estado:</strong> 
                    <?php 
                        if ($equipo['estado_presentacion'] == 'presentando') {
                            echo '<span class="badge bg-success">Presentando</span>';
                        } elseif ($equipo['estado_presentacion'] == 'finalizado') {
                            echo '<span class="badge bg-secondary">Finalizado</span>';
                        } else {
                            echo '<span class="badge bg-warning text-dark">Pendiente</span>';
                        }
                    ?>
                </p>
                <h3>Miembros del Equipo:</h3>
                <ul class="list-group mb-4">
                    <?php while($miembro = $miembros->fetch_assoc()): ?>
                        <li class="list-group-item"><?php echo htmlspecialchars($miembro['nombre']); ?></li>
                    <?php endwhile; ?>
                </ul>
                <!-- Espacio para contenido de presentación (puedes agregar más elementos aquí) -->
                <div class="alert alert-info">
                    <h5>Contenido de la Presentación</h5>
                    <p>Aquí se mostraría el contenido de la presentación del equipo (diapositivas, videos, etc.).</p>
                </div>

                <!-- Botón para finalizar presentación (solo visible para docentes) -->
                <?php if ($_SESSION['es_docente'] && $equipo['estado_presentacion'] == 'presentando'): ?>
                    <form method="POST" action="admin_actions.php" class="mt-4">
                        <input type="hidden" name="action" value="finalize_presentation">
                        <input type="hidden" name="id_equipo" value="<?= $id_equipo ?>">
                        <button type="submit" class="btn btn-danger btn-lg">
                            Finalizar presentación e iniciar coevaluación
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Acciones del Docente</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($_SESSION['es_docente'] && $equipo['estado_presentacion'] == 'presentando'): ?>
                            <form action="admin_actions.php" method="POST">
                                <input type="hidden" name="action" value="finalize_presentation">
                                <input type="hidden" name="id_equipo" value="<?php echo $id_equipo; ?>">
                                <button type="submit" class="btn btn-success btn-lg w-100">Finalizar Presentación e Iniciar Coevaluación</button>
                            </form>
                        <?php else: ?>
                            <p class="text-muted">La presentación no está en curso.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
    exit();
} else {
    header("Location: dashboard_docente.php");
    exit();
}
?>
