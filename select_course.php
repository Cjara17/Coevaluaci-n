<?php
require 'db.php';
// El docente debe estar logueado, pero AÚN no necesita un curso activo.
verificar_sesion(true, false);

$id_docente = $_SESSION['id_usuario'];

// --- LÓGICA PARA ESTABLECER CURSO ACTIVO (POST SELECCIÓN) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'select') {
    $id_curso_seleccionado = (int)$_POST['id_curso'];

    // Seguridad: Verificar que el docente realmente esté asociado a ese curso
    $stmt_check = $conn->prepare("SELECT id_curso FROM docente_curso WHERE id_docente = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_docente, $id_curso_seleccionado);
    $stmt_check->execute();
    
    if ($stmt_check->get_result()->num_rows == 1) {
        // Curso válido: Establecer en la sesión y redirigir
        $_SESSION['id_curso_activo'] = $id_curso_seleccionado;
        header("Location: dashboard_docente.php");
        exit();
    } else {
        // Intento de acceder a un curso no asignado
        $error = "Acceso denegado al curso seleccionado.";
    }
    $stmt_check->close();
}

// --- CONSULTAR CURSOS ASIGNADOS AL DOCENTE ---
$sql_cursos = "
    SELECT c.id, c.nombre_curso, c.semestre, c.anio 
    FROM cursos c
    JOIN docente_curso dc ON c.id = dc.id_curso
    WHERE dc.id_docente = ?
    ORDER BY c.anio DESC, c.semestre DESC, c.nombre_curso ASC";

$stmt_cursos = $conn->prepare($sql_cursos);
$stmt_cursos->bind_param("i", $id_docente);
$stmt_cursos->execute();
$cursos = $stmt_cursos->get_result();

// --- MENSAJES DE ESTADO Y ERROR ---
$status_message = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
$error_message = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : (isset($error) ? htmlspecialchars($error) : '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Seleccionar Curso</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container mt-5">
        <h1 class="mb-4">Bienvenido, <?php echo htmlspecialchars($_SESSION['nombre']); ?></h1>
        <p class="text-muted">Selecciona el curso que deseas gestionar o crea uno nuevo.</p>

        <?php if ($status_message): ?>
            <div class="alert alert-success"><?php echo $status_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-7">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Mis Cursos Asignados</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($cursos->num_rows > 0): ?>
                            <div class="list-group">
                                <?php while($curso = $cursos->fetch_assoc()): ?>
                                    <form action="select_course.php" method="POST" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($curso['nombre_curso']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($curso['semestre']) . ' - ' . htmlspecialchars($curso['anio']); ?></small>
                                        </div>
                                        <input type="hidden" name="id_curso" value="<?php echo $curso['id']; ?>">
                                        <input type="hidden" name="action" value="select">
                                        <button type="submit" class="btn btn-sm btn-success">Gestionar</button>
                                    </form>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">Aún no estás asignado a ningún curso. Por favor, crea uno.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-5">
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">Crear Nuevo Curso</h5>
                    </div>
                    <div class="card-body">
                        <form action="create_course.php" method="POST">
                            <div class="mb-3">
                                <label for="nombre_curso" class="form-label">Nombre del Curso</label>
                                <input type="text" class="form-control" id="nombre_curso" name="nombre_curso" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="semestre" class="form-label">Semestre</label>
                                    <select class="form-select" id="semestre" name="semestre" required>
                                        <option value="2025-1">2025-1</option>
                                        <option value="2025-2">2025-2</option>
                                        </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="anio" class="form-label">Año</label>
                                    <input type="number" class="form-control" id="anio" name="anio" value="<?php echo date("Y"); ?>" required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Crear y Activar Curso</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="text-center mt-5">
             <a href="logout.php" class="btn btn-outline-danger">Cerrar Sesión</a>
        </div>
    </div>
</body>
</html>