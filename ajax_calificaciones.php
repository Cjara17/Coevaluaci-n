<?php
require_once 'db.php';
verificar_sesion(true);

$id_docente = $_SESSION['id_usuario'];
$id_curso_activo = $_SESSION['id_curso_activo'];

$sql = "
    SELECT e.nombre_equipo,
           AVG(em.puntaje_total) AS promedio
    FROM equipos e
    LEFT JOIN evaluaciones_maestro em ON e.id = em.id_equipo_evaluado AND em.id_curso = e.id_curso
    WHERE e.id_curso = ?
    GROUP BY e.id, e.nombre_equipo
    ORDER BY e.nombre_equipo
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_curso_activo);
$stmt->execute();
$result = $stmt->get_result();

$calificaciones = [];
while ($row = $result->fetch_assoc()) {
    $calificaciones[] = [
        'nombre_equipo' => $row['nombre_equipo'],
        'promedio' => $row['promedio'] !== null ? (float)$row['promedio'] : null
    ];
}

header('Content-Type: application/json');
echo json_encode($calificaciones);

$stmt->close();
$conn->close();
?>
