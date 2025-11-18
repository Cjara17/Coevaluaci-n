<?php
/**
 * Utilidades para soportar evaluaciones cualitativas.
 * Se cargan junto con la conexión en db.php para asegurar el esquema
 * y facilitar la reutilización en los controladores/ vistas.
 */

if (!function_exists('ensure_qualitative_schema')) {
    /**
     * Garantiza que las tablas necesarias para las evaluaciones cualitativas existan.
     * Usa CREATE TABLE IF NOT EXISTS para no interferir con instalaciones existentes.
     */
    function ensure_qualitative_schema(mysqli $conn): void
    {
        $queries = [
            "CREATE TABLE IF NOT EXISTS escalas_cualitativas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_curso INT NOT NULL,
                nombre VARCHAR(120) NOT NULL,
                descripcion TEXT DEFAULT NULL,
                es_principal TINYINT(1) NOT NULL DEFAULT 1,
                creado_por INT DEFAULT NULL,
                creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_escalas_curso FOREIGN KEY (id_curso) REFERENCES cursos(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS conceptos_cualitativos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_escala INT NOT NULL,
                etiqueta VARCHAR(80) NOT NULL,
                descripcion TEXT DEFAULT NULL,
                color_hex VARCHAR(7) DEFAULT '#0d6efd',
                peso DECIMAL(5,2) DEFAULT NULL,
                orden INT NOT NULL DEFAULT 0,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                CONSTRAINT fk_conceptos_escala FOREIGN KEY (id_escala) REFERENCES escalas_cualitativas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS evaluaciones_cualitativas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_evaluador INT NOT NULL,
                id_equipo_evaluado INT NOT NULL,
                id_curso INT NOT NULL,
                id_escala INT NOT NULL,
                observaciones TEXT DEFAULT NULL,
                fecha_evaluacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_eval_qualitativa (id_evaluador, id_equipo_evaluado, id_curso),
                KEY idx_equipo_qual (id_equipo_evaluado),
                CONSTRAINT fk_evalqual_evaluador FOREIGN KEY (id_evaluador) REFERENCES usuarios(id) ON DELETE CASCADE,
                CONSTRAINT fk_evalqual_equipo FOREIGN KEY (id_equipo_evaluado) REFERENCES equipos(id) ON DELETE CASCADE,
                CONSTRAINT fk_evalqual_curso FOREIGN KEY (id_curso) REFERENCES cursos(id) ON DELETE CASCADE,
                CONSTRAINT fk_evalqual_escala FOREIGN KEY (id_escala) REFERENCES escalas_cualitativas(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS evaluaciones_cualitativas_detalle (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_evaluacion INT NOT NULL,
                id_criterio INT NOT NULL,
                id_concepto INT NOT NULL,
                ponderacion_aplicada DECIMAL(5,2) DEFAULT NULL,
                CONSTRAINT fk_evalqual_det_eval FOREIGN KEY (id_evaluacion) REFERENCES evaluaciones_cualitativas(id) ON DELETE CASCADE,
                CONSTRAINT fk_evalqual_det_criterio FOREIGN KEY (id_criterio) REFERENCES criterios(id) ON DELETE CASCADE,
                CONSTRAINT fk_evalqual_det_concepto FOREIGN KEY (id_concepto) REFERENCES conceptos_cualitativos(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];

        foreach ($queries as $sql) {
            if (!$conn->query($sql)) {
                error_log('[QualitativeSchema] Error ejecutando DDL: ' . $conn->error);
            }
        }
    }
}

if (!function_exists('ensure_default_qualitative_scale')) {
    /**
     * Crea una escala por defecto para un curso si no existe alguna.
     * Incluye los conceptos base: Excelente, Bueno, Regular, Necesita Mejorar.
     */
    function ensure_default_qualitative_scale(mysqli $conn, int $id_curso, ?int $id_docente = null): array
    {
        $stmt = $conn->prepare("SELECT * FROM escalas_cualitativas WHERE id_curso = ? ORDER BY es_principal DESC, id ASC LIMIT 1");
        $stmt->bind_param("i", $id_curso);
        $stmt->execute();
        $result = $stmt->get_result();
        $escala = $result->fetch_assoc();
        $stmt->close();

        if ($escala) {
            return $escala;
        }

        $nombre = "Escala cualitativa estándar";
        $descripcion = "Escala por defecto con descriptores cualitativos.";

        $stmt_insert = $conn->prepare("INSERT INTO escalas_cualitativas (id_curso, nombre, descripcion, es_principal, creado_por) VALUES (?, ?, ?, 1, ?)");
        $stmt_insert->bind_param("issi", $id_curso, $nombre, $descripcion, $id_docente);
        $stmt_insert->execute();
        $id_escala = $stmt_insert->insert_id;
        $stmt_insert->close();

        $conceptos_defecto = [
            ['Excelente', 'Desempeño sobresaliente, supera las expectativas.', '#198754', 1],
            ['Bueno', 'Cumple la mayoría de los criterios con seguridad.', '#0d6efd', 2],
            ['Regular', 'Cubre aspectos mínimos, con oportunidades de mejora.', '#ffc107', 3],
            ['Necesita Mejorar', 'Requiere apoyo y replanificación.', '#dc3545', 4],
        ];

        $stmt_concept = $conn->prepare("INSERT INTO conceptos_cualitativos (id_escala, etiqueta, descripcion, color_hex, orden) VALUES (?, ?, ?, ?, ?)");
        foreach ($conceptos_defecto as $concepto) {
            [$etiqueta, $desc, $color, $orden] = $concepto;
            $stmt_concept->bind_param("isssi", $id_escala, $etiqueta, $desc, $color, $orden);
            $stmt_concept->execute();
        }
        $stmt_concept->close();

        return [
            'id' => $id_escala,
            'id_curso' => $id_curso,
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'es_principal' => 1
        ];
    }
}

if (!function_exists('get_primary_scale')) {
    function get_primary_scale(mysqli $conn, int $id_curso): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM escalas_cualitativas WHERE id_curso = ? ORDER BY es_principal DESC, id ASC LIMIT 1");
        $stmt->bind_param("i", $id_curso);
        $stmt->execute();
        $result = $stmt->get_result();
        $escala = $result->fetch_assoc();
        $stmt->close();
        return $escala ?: null;
    }
}

if (!function_exists('get_scale_concepts')) {
    function get_scale_concepts(mysqli $conn, int $id_escala): array
    {
        $stmt = $conn->prepare("SELECT * FROM conceptos_cualitativos WHERE id_escala = ? AND activo = 1 ORDER BY orden ASC, id ASC");
        $stmt->bind_param("i", $id_escala);
        $stmt->execute();
        $result = $stmt->get_result();
        $conceptos = [];
        while ($row = $result->fetch_assoc()) {
            $conceptos[] = $row;
        }
        $stmt->close();
        return $conceptos;
    }
}

if (!function_exists('get_latest_qualitative_summary')) {
    /**
     * Devuelve conteo y última fecha de evaluaciones cualitativas para un equipo.
     */
    function get_latest_qualitative_summary(mysqli $conn, int $id_equipo, int $id_curso): array
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total, MAX(fecha_evaluacion) AS ultima_fecha
            FROM evaluaciones_cualitativas
            WHERE id_equipo_evaluado = ? AND id_curso = ?
        ");
        $stmt->bind_param("ii", $id_equipo, $id_curso);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $data ?: ['total' => 0, 'ultima_fecha' => null];
    }
}

if (!function_exists('get_course_qualitative_feed')) {
    /**
     * Obtiene las últimas evaluaciones cualitativas del curso para mostrar en el dashboard.
     */
    function get_course_qualitative_feed(mysqli $conn, int $id_curso, int $limit = 6): array
    {
        $stmt = $conn->prepare("
            SELECT ec.id,
                   ec.id_equipo_evaluado,
                   e.nombre_equipo,
                   ec.fecha_evaluacion,
                   ec.observaciones,
                   ec.id_evaluador,
                   u.nombre AS nombre_evaluador,
                   (
                       SELECT COUNT(*) FROM evaluaciones_cualitativas_detalle d
                       WHERE d.id_evaluacion = ec.id
                   ) AS total_items
            FROM evaluaciones_cualitativas ec
            JOIN equipos e ON ec.id_equipo_evaluado = e.id
            JOIN usuarios u ON ec.id_evaluador = u.id
            WHERE ec.id_curso = ?
            ORDER BY ec.fecha_evaluacion DESC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $id_curso, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $feed = [];
        while ($row = $result->fetch_assoc()) {
            $feed[] = $row;
        }
        $stmt->close();
        return $feed;
    }
}
?>

