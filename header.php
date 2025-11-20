<?php
// NUEVO: header global institucional UCT

// Si no hay sesión iniciada, evitar errores y desactivar funciones docentes
if (!isset($_SESSION)) {
    session_start();
}

$page_title = isset($page_title) ? $page_title : 'Coevaluación UCT';
$es_docente = isset($_SESSION['es_docente']) ? $_SESSION['es_docente'] : false;
$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;
$id_docente = isset($_SESSION['id_usuario']) ? $_SESSION['id_usuario'] : null;

// Esta variable contendrá los cursos del docente SOLO si corresponde
$all_cursos = [];

// VALIDACIÓN ESENCIAL:
// Solo ejecutar consultas si:
// - el usuario es docente
// - existe conexión a BD ($conn)
// - hay un curso activo
if ($es_docente && $id_docente && $id_curso_activo && isset($conn)) {

    $sql_all_cursos = "
        SELECT c.id, c.nombre_curso, c.semestre, c.anio
        FROM cursos c
        JOIN docente_curso dc ON c.id = dc.id_curso
        WHERE dc.id_docente = ?
        ORDER BY c.anio DESC, c.semestre DESC";

    if ($stmt_all_cursos = $conn->prepare($sql_all_cursos)) {
        $stmt_all_cursos->bind_param("i", $id_docente);
        $stmt_all_cursos->execute();
        $all_cursos = $stmt_all_cursos->get_result();
        $stmt_all_cursos->close();
    }
}
?>
<header class="d-flex align-items-center justify-content-between p-3" style="background-color: #005A8C; color: white;">
    <div class="d-flex align-items-center">
        <img src="img/logo_uct.png" alt="Logo UCT" style="height: 40px; margin-right: 15px;">
        <h5 class="mb-0"><?php echo htmlspecialchars($page_title); ?></h5>
    </div>
    <div class="d-flex align-items-center">
        <?php if ($es_docente && !empty($all_cursos)): ?>
            <form action="set_course.php" method="POST" class="d-flex me-3">
                <select name="id_curso" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php while ($c = $all_cursos->fetch_assoc()): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $id_curso_activo) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['nombre_curso'] . ' ' . $c['semestre'] . '-' . $c['anio']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </form>
        <?php endif; ?>

        <?php if (isset($_SESSION['id_usuario'])): ?>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Cerrar Sesión</a>
        <?php endif; ?>
    </div>
</header>
