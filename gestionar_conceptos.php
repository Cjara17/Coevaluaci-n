<?php
require 'db.php';
verificar_sesion(true);

$id_docente = $_SESSION['id_usuario'];
$id_curso_activo = isset($_SESSION['id_curso_activo']) ? (int)$_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

$stmt_curso = $conn->prepare("SELECT nombre_curso, semestre, anio FROM cursos WHERE id = ?");
$stmt_curso->bind_param("i", $id_curso_activo);
$stmt_curso->execute();
$curso_activo = $stmt_curso->get_result()->fetch_assoc();
$stmt_curso->close();

$escala = ensure_default_qualitative_scale($conn, $id_curso_activo, $id_docente);
$conceptos = get_scale_concepts($conn, (int)$escala['id']);

$status_message = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
$error_message = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar conceptos cualitativos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard_docente.php">
                <img src="logo_uct.png" alt="Logo" style="height: 30px;">
                Panel Docente
            </a>
            <div class="d-flex me-4">
                <span class="navbar-text text-white me-3">
                    Curso: <?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre'] . '-' . $curso_activo['anio']); ?>
                </span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">Cerrar Sesión</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="mb-0">Conceptos cualitativos</h1>
                <p class="text-muted mb-0">Define los descriptores para las evaluaciones cualitativas.</p>
            </div>
            <a href="gestionar_criterios.php" class="btn btn-secondary">← Volver a criterios</a>
        </div>

        <?php if ($status_message): ?>
            <div class="alert alert-success"><?php echo $status_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <strong>Escala activa</strong>
                    </div>
                    <div class="card-body">
                        <form action="conceptos_actions.php" method="POST">
                            <input type="hidden" name="action" value="update_scale">
                            <input type="hidden" name="id_escala" value="<?php echo (int)$escala['id']; ?>">
                            <div class="mb-3">
                                <label class="form-label">Nombre de la escala</label>
                                <input type="text" name="nombre" class="form-control" required value="<?php echo htmlspecialchars($escala['nombre']); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Descripción / instrucciones</label>
                                <textarea name="descripcion" rows="4" class="form-control"><?php echo htmlspecialchars((string)$escala['descripcion']); ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Guardar escala</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-success text-white">
                        <strong>Nuevo concepto</strong>
                    </div>
                    <div class="card-body">
                        <form action="conceptos_actions.php" method="POST" class="row g-3">
                            <input type="hidden" name="action" value="add_concept">
                            <input type="hidden" name="id_escala" value="<?php echo (int)$escala['id']; ?>">
                            <div class="col-12">
                                <label class="form-label">Etiqueta</label>
                                <input type="text" name="etiqueta" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Orden</label>
                                <input type="number" name="orden" class="form-control" value="<?php echo count($conceptos) + 1; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Color</label>
                                <input type="color" name="color_hex" class="form-control form-control-color w-100" value="#0d6efd">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-success w-100">Añadir concepto</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light">
                        <strong>Conceptos disponibles (<?php echo count($conceptos); ?>)</strong>
                    </div>
                    <div class="card-body">
                        <?php if (count($conceptos) === 0): ?>
                            <p class="text-muted">Aún no hay conceptos definidos.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Orden</th>
                                            <th>Etiqueta</th>
                                            <th>Color</th>
                                            <th>Descripción</th>
                                            <th class="text-center">Activo</th>
                                            <th class="text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($conceptos as $concepto): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo (int)$concepto['orden']; ?></strong>
                                                <input type="hidden" form="form-update-<?php echo $concepto['id']; ?>" name="orden" value="<?php echo (int)$concepto['orden']; ?>">
                                            </td>
                                            <td style="min-width: 160px;">
                                                <input type="text" form="form-update-<?php echo $concepto['id']; ?>" name="etiqueta" class="form-control form-control-sm" value="<?php echo htmlspecialchars($concepto['etiqueta']); ?>">
                                            </td>
                                            <td style="width: 120px;">
                                                <input type="color" form="form-update-<?php echo $concepto['id']; ?>" name="color_hex" class="form-control form-control-color" value="<?php echo htmlspecialchars($concepto['color_hex']); ?>">
                                            </td>
                                            <td style="min-width: 220px;">
                                                <textarea form="form-update-<?php echo $concepto['id']; ?>" name="descripcion" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars((string)$concepto['descripcion']); ?></textarea>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check form-switch d-flex justify-content-center">
                                                    <input class="form-check-input" type="checkbox" role="switch"
                                                        form="form-update-<?php echo $concepto['id']; ?>"
                                                        name="activo" value="1" <?php echo $concepto['activo'] ? 'checked' : ''; ?>>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <form id="form-update-<?php echo $concepto['id']; ?>" action="conceptos_actions.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="update_concept">
                                                    <input type="hidden" name="id_concepto" value="<?php echo (int)$concepto['id']; ?>">
                                                    <input type="hidden" name="id_escala" value="<?php echo (int)$escala['id']; ?>">
                                                </form>
                                                <button type="submit" form="form-update-<?php echo $concepto['id']; ?>" class="btn btn-sm btn-outline-primary mb-2 w-100">Guardar</button>
                                                <form action="conceptos_actions.php" method="POST" onsubmit="return confirm('¿Eliminar concepto?');">
                                                    <input type="hidden" name="action" value="delete_concept">
                                                    <input type="hidden" name="id_concepto" value="<?php echo (int)$concepto['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger w-100">Eliminar</button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

