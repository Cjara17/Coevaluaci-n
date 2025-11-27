<?php

class DashboardController {

    /**
     * Obtiene la información del curso activo
     */
    public static function getCourseInfo($conn, $id_curso_activo) {
        $stmt_curso = $conn->prepare("SELECT nombre_curso, semestre, anio FROM cursos WHERE id = ?");
        $stmt_curso->bind_param("i", $id_curso_activo);
        $stmt_curso->execute();
        $curso_activo = $stmt_curso->get_result()->fetch_assoc();
        $stmt_curso->close();
        return $curso_activo;
    }

    /**
     * Obtiene todos los cursos del docente
     */
    public static function getAllCourses($conn, $id_docente) {
        $sql_all_cursos = "
            SELECT c.id, c.nombre_curso, c.semestre, c.anio
            FROM cursos c
            JOIN docente_curso dc ON c.id = dc.id_curso
            WHERE dc.id_docente = ?
            ORDER BY c.anio DESC, c.semestre DESC";
        $stmt_all_cursos = $conn->prepare($sql_all_cursos);
        $stmt_all_cursos->bind_param("i", $id_docente);
        $stmt_all_cursos->execute();
        return $stmt_all_cursos->get_result();
    }
}
