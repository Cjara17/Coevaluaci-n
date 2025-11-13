<?php
require 'db.php';
// Requerir ser docente Y tener un curso activo
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;
$id_docente = $_SESSION['id_usuario'];

// Si no tiene curso activo, redirigir a select_course.php
if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

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

// 3. OBTENER INFORMACIÓN DE LOS EQUIPOS DEL CURSO ACTIVO
// Se incluyen subconsultas para calcular el promedio de estudiantes (0-100),
// la nota del docente (0-100) y el total de evaluaciones por equipo.
$sql_equipos = "
    SELECT 
        e.id, 
        e.nombre_equipo, 
        e.estado_presentacion,
        (
            SELECT AVG(em1.puntaje_total) 
            FROM evaluaciones_maestro em1 
            JOIN usuarios u1 ON em1.id_evaluador = u1.id 
            WHERE em1.id_equipo_evaluado = e.id 
            AND u1.es_docente = FALSE
        ) as promedio_estudiantes,
        (
            SELECT em2.puntaje_total
            FROM evaluaciones_maestro em2 
            JOIN usuarios u2 ON em2.id_evaluador = u2.id 
            WHERE em2.id_equipo_evaluado = e.id 
            AND u2.es_docente = TRUE 
            LIMIT 1
        ) as nota_docente,
        (
            SELECT COUNT(em3.id) 
            FROM evaluaciones_maestro em3 
            JOIN usuarios u3 ON em3.id_evaluador = u3.id 
            WHERE em3.id_equipo_evaluado = e.id 
            AND u3.es_docente = FALSE
        ) as total_eval_estudiantes
    FROM equipos e 
    WHERE e.id_curso = ?
    ORDER BY e.nombre_equipo ASC
";
$stmt_equipos = $conn->prepare($sql_equipos);
$stmt_equipos->bind_param("i", $id_curso_activo);
$stmt_equipos->execute();
$equipos = $stmt_equipos->get_result();

// 4. FUNCIÓN PARA CALCULAR LA NOTA FINAL PONDERADA Y LA NOTA EN ESCALA 1-7
// Escala Chilena (asumiendo puntaje max. 100)
function calcular_nota_final($puntaje) {
    if ($puntaje === null) return "N/A";
    
    // Fórmula: 1.0 + (Puntaje / 100) * 6.0
    $nota = 1.0 + ($puntaje / 100) * 6.0;
    
    if ($nota < 1.0) $nota = 1.0;
    if ($nota > 7.0) $nota = 7.0;
    
    return number_format($nota, 1, '.', ''); // Formato x.x
}

// Mensajes de estado y error
$status_message = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
$error_message = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
$invite_success = isset($_GET['invite_success']) ? true : false;
$invite_error = isset($_GET['invite_error']) ? htmlspecialchars($_GET['invite_error']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Docente - Coevaluación</title>
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
                <form action="set_course.php" method="POST" class="d-flex">
                    <select name="id_curso" class="form-select form-select-sm me-2" onchange="this.form.submit()">
                        <?php while($c = $all_cursos->fetch_assoc()): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $id_curso_activo) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['nombre_curso'] . ' ' . $c['semestre'] . '-' . $c['anio']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </form>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">Cerrar Sesión</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>
                Curso Activo: <?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre'] . '-' . $curso_activo['anio']); ?>
            </h1>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#inviteModal">
                    Agregar invitado
                </button>
                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#resetModal">
                    Resetear Plataforma
                </button>
                <a href="gestionar_criterios.php" class="btn btn-info">
                    Gestionar Criterios
                </a>
            </div>
        </div>

        <?php if ($status_message): ?>
            <div class="alert alert-success"><?php echo $status_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        <?php if ($invite_error): ?>
            <div class="alert alert-danger"><?php echo $invite_error; ?></div>
        <?php endif; ?>

        <div class="modal fade" id="resetModal" tabindex="-1" aria-labelledby="resetModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="resetModalLabel">Confirmar Reseteo de Plataforma</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-danger fw-bold">ADVERTENCIA: Esta acción es irreversible.</p>
                        <p>Al confirmar, se eliminarán todos los datos (equipos, estudiantes, evaluaciones, criterios) asociados al **curso activo**.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <form action="admin_actions.php" method="POST" class="d-inline">
                            <input type="hidden" name="action" value="reset_all">
                            <input type="hidden" name="confirm" value="yes">
                            <button type="submit" class="btn btn-danger">Confirmar Reseteo Total</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card shadow h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Gestión de Estudiantes y Equipos</h5>
                    </div>
                    <div class="card-body">
                        <p>Sube un archivo **CSV** con la lista de estudiantes y su equipo.</p>
                        <form action="upload.php" method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="lista_estudiantes" class="form-label">Archivo CSV (Nombre, Email, Equipo)</label>
                                <input class="form-control" type="file" id="lista_estudiantes" name="lista_estudiantes" accept=".csv" required>
                            </div>
                            <button type="submit" class="btn btn-success w-100">Subir y Procesar Lista</button>
                        </form>
                        <hr>
                        <p class="text-muted small">
                            Formato CSV requerido:
                            <br>
                            `Nombre Apellido,correo@alu.uct.cl,Nombre Equipo`
                            <br>
                            Ejemplo: `Juan Perez,jperez@alu.uct.cl,Los Vengadores`
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                 <div class="card shadow h-100">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Resultados y Exportar</h5>
                    </div>
                    <div class="card-body d-flex flex-column justify-content-between">
                        <div>
                            <p>Exporta los resultados finales (promedio coevaluación de estudiantes y nota del docente) a un archivo CSV para el cálculo de notas.</p>
                            <p class="text-muted small">
                                *El cálculo usa una ponderación **50% estudiantes** y **50% docente**.
                            </p>
                        </div>
                        <a href="export_results.php" class="btn btn-success btn-lg mt-3">Exportar Resultados Finales</a>
                    </div>
                </div>
            </div>
        </div>

        <h2>Equipos del Curso</h2>
        <table class="table table-striped table-hover shadow-sm">
            <thead class="table-dark">
                <tr>
                    <th>Equipo</th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Eval. Estudiantes</th>
                    <th class="text-center">Nota Docente (0-100)</th>
                    <th class="text-center">Puntaje Final (0-100)</th>
                    <th class="text-center">Nota Final (1.0-7.0)</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($equipos->num_rows > 0): ?>
                    <?php while($equipo = $equipos->fetch_assoc()): ?>
                        <?php
                            // Calcular puntaje final y nota
                            $promedio_est = $equipo['promedio_estudiantes']; // Valor de 0 a 100
                            $nota_doc = $equipo['nota_docente']; // Valor de 0 a 100
                            $puntaje_final_score = null;
                            $nota_final_grado = 'N/A';

                            $promedio_est_display = ($promedio_est !== null) ? round($promedio_est, 2) : 'N/A';
                            $nota_doc_display = ($nota_doc !== null) ? round($nota_doc, 2) : 'N/A';

                            // Solo calcular el puntaje final si la presentación ha terminado
                            if ($equipo['estado_presentacion'] == 'finalizado') {
                                // Si no hay promedio de estudiantes, asumimos 0 (para no perder la nota docente)
                                $promedio_est_final = ($promedio_est !== null) ? $promedio_est : 0; 
                                
                                if ($nota_doc !== null) {
                                    // Ponderación 50% estudiantes, 50% docente
                                    $puntaje_final_score = ($promedio_est_final * 0.5) + ($nota_doc * 0.5);
                                    $nota_final_grado = calcular_nota_final($puntaje_final_score);
                                    $puntaje_final_score = number_format($puntaje_final_score, 2, '.', ''); // Formatear para mostrar
                                } else {
                                    $puntaje_final_score = 'N/A (Falta Docente)';
                                }
                            } else {
                                $puntaje_final_score = 'Pendiente';
                            }
                        ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></strong></td>
                        <td class="text-center">
                            <?php 
                                if ($equipo['estado_presentacion'] == 'presentando') {
                                    echo '<span class="badge bg-success">Presentando</span>';
                                } elseif ($equipo['estado_presentacion'] == 'finalizado') {
                                    echo '<span class="badge bg-secondary">Finalizado</span>';
                                } else {
                                    echo '<span class="badge bg-warning text-dark">Pendiente</span>';
                                }
                            ?>
                        </td>
                        <td class="text-center" title="Puntaje promedio estudiantes (0-100): <?php echo $promedio_est_display; ?>">
                            <?php echo $equipo['total_eval_estudiantes']; ?> (<?php echo $promedio_est_display; ?>)
                        </td>
                        <td class="text-center"><?php echo $nota_doc_display; ?></td>
                        <td class="text-center fw-bold text-primary"><?php echo $puntaje_final_score; ?></td>
                        <td class="text-center fw-bold text-danger"><?php echo $nota_final_grado; ?></td>
                        <td class="text-center">
                            <form action="gestionar_presentacion.php" method="POST" class="d-inline">
                                <input type="hidden" name="id_equipo" value="<?php echo $equipo['id']; ?>">
                                <?php if ($equipo['estado_presentacion'] == 'pendiente'): ?>
                                    <input type="hidden" name="accion" value="iniciar">
                                    <button type="submit" class="btn btn-sm btn-primary">Iniciar Presentación</button>
                                <?php elseif ($equipo['estado_presentacion'] == 'presentando'): ?>
                                    <input type="hidden" name="accion" value="terminar">
                                    <button type="submit" class="btn btn-sm btn-success">Terminar Presentación</button>
                                <?php endif; ?>
                            </form>

                            <a href="ver_detalles.php?id=<?php echo $equipo['id']; ?>" class="btn btn-sm btn-info ms-2">Detalles</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center">No hay equipos registrados para este curso. Sube la lista de estudiantes.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="height: 120px;"></div>

    </div>

    <!-- Modal: Crear nuevo evaluador -->
    <div class="modal fade" id="inviteModal" tabindex="-1" aria-labelledby="inviteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="inviteModalLabel">Crear nuevo evaluador</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="create_evaluator.php" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="tipo_evaluador" class="form-label">Rol del evaluador</label>
                            <select class="form-select" id="tipo_evaluador" name="tipo_evaluador" required>
                                <option value="invitado">Invitado</option>
                                <option value="estudiante">Estudiante</option>
                                <option value="docente">Docente</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="email_evaluador" class="form-label">Correo electrónico</label>
                            <input type="email" class="form-control" id="email_evaluador" name="email" required placeholder="correo@dominio.cl">
                        </div>
                        <div class="mb-3">
                            <label for="password_evaluador" class="form-label">Contraseña</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="password_evaluador" name="password" required minlength="6" placeholder="Ingrese o genere una contraseña">
                                <button class="btn btn-outline-secondary" type="button" id="btn-generar-password">Generar automáticamente</button>
                            </div>
                            <div class="form-text">La contraseña debe tener al menos 6 caracteres.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Crear</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Éxito en creación -->
    <div class="modal fade" id="inviteSuccessModal" tabindex="-1" aria-labelledby="inviteSuccessModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="inviteSuccessModalLabel">Operación exitosa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <p class="lead mb-0">Perfil creado y credenciales enviadas de manera correcta.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" data-bs-dismiss="modal">Aceptar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmación de Eliminación -->
    <div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="confirmDeleteModalLabel">Confirmar eliminación</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-danger fw-bold">ADVERTENCIA: Esta acción es irreversible.</p>
                    <p>¿Estás seguro de que quieres eliminar este elemento? No se puede deshacer.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="form-delete" class="btn btn-danger fw-bold">Eliminar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Formulario oculto para eliminación -->
    <form id="form-delete" method="POST" style="display: none;">
        <input type="hidden" name="action" id="delete-action">
        <input type="hidden" name="id" id="delete-id">
        <input type="hidden" name="confirm" value="yes">
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var confirmDeleteModal = document.getElementById('confirmDeleteModal');
            confirmDeleteModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var action = button.getAttribute('data-action');
                var id = button.getAttribute('data-id');
                var type = button.getAttribute('data-type');

                var form = document.getElementById('form-delete');
                form.action = action;
                document.getElementById('delete-action').value = 'delete';
                document.getElementById('delete-id').value = id;
                document.getElementById('delete-id').name = (type === 'criterio') ? 'id_criterio' : 'id';
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const generarBtn = document.getElementById('btn-generar-password');
            if (generarBtn) {
                generarBtn.addEventListener('click', function () {
                    const targetInput = document.getElementById('password_evaluador');
                    if (!targetInput) return;

                    const caracteres = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()';
                    let password = '';
                    for (let i = 0; i < 12; i++) {
                        password += caracteres.charAt(Math.floor(Math.random() * caracteres.length));
                    }
                    targetInput.value = password;
                });
            }

            <?php if ($invite_success): ?>
            const successModal = new bootstrap.Modal(document.getElementById('inviteSuccessModal'));
            successModal.show();
            <?php endif; ?>
        });
    </script>
</body>
</html>
