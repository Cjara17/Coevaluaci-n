<?php
// update_check.php - Sistema de alertas para actualizaciones de dependencias
// Ejecutar este script manualmente o vía cron para verificar actualizaciones.

// Función para obtener versión de PHP
function get_php_version() {
    return phpversion();
}

// Función para obtener versión de MySQL
function get_mysql_version() {
    // Intentar obtener desde mysqli si está disponible
    if (class_exists('mysqli')) {
        $servername = "localhost";
        $username = "root";
        $password = "";
        $dbname = "coeval_db";

        $conn = new mysqli($servername, $username, $password, $dbname);
        if ($conn->connect_error) {
            return "Error: No se pudo conectar a MySQL - " . $conn->connect_error;
        }
        $version = mysqli_get_server_info($conn);
        $conn->close();
        return $version;
    } else {
        // Fallback: intentar con PDO si mysqli no está disponible
        try {
            $pdo = new PDO("mysql:host=localhost;dbname=coeval_db", "root", "");
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $pdo = null;
            return $version;
        } catch (PDOException $e) {
            return "Error: No se pudo conectar a MySQL - " . $e->getMessage();
        }
    }
}

// Función para consultar Packagist y obtener la última versión
function get_latest_version_from_packagist($package) {
    $url = "https://packagist.org/packages/{$package}.json";
    $context = stream_context_create([
        "http" => [
            "timeout" => 10, // Timeout de 10 segundos
            "user_agent" => "PHP Update Checker/1.0"
        ]
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null; // Error al consultar
    }
    $data = json_decode($response, true);
    if (isset($data['package']['versions'])) {
        $versions = array_keys($data['package']['versions']);
        // Filtrar versiones estables (sin dev, alpha, etc.)
        $stable_versions = array_filter($versions, function($v) {
            return !preg_match('/(dev|alpha|beta|rc)/i', $v) && preg_match('/^\d+\.\d+(\.\d+)?$/', $v);
        });
        if (!empty($stable_versions)) {
            usort($stable_versions, 'version_compare');
            return end($stable_versions); // Última versión estable
        }
    }
    return null;
}

// Función para comparar versiones (simple)
function is_version_outdated($current, $latest) {
    return version_compare($current, $latest, '<');
}

// Función para guardar alertas en log
function save_alert($message) {
    $log_file = __DIR__ . '/update_alerts.log';
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] $message\n";
    file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
}

// Limpiar log anterior
$log_file = __DIR__ . '/update_alerts.log';
if (file_exists($log_file)) {
    unlink($log_file);
}

// Iniciar verificación
echo "=== Sistema de Alertas de Actualizaciones ===\n\n";

// 1. Verificar PHP
$current_php = get_php_version();
$recommended_php = "8.2.0";
echo "Versión actual de PHP: $current_php\n";
echo "Versión recomendada: $recommended_php\n";
if (is_version_outdated($current_php, $recommended_php)) {
    $alert = "PHP desactualizado: $current_php < $recommended_php";
    echo "ALERTA: $alert\n";
    save_alert($alert);
} else {
    echo "PHP está actualizado.\n";
}
echo "\n";

// 2. Verificar MySQL
$current_mysql = get_mysql_version();
$recommended_mysql = "8.0.0";
echo "Versión actual de MySQL: $current_mysql\n";
echo "Versión recomendada: $recommended_mysql\n";
if (strpos($current_mysql, 'Error') === false && is_version_outdated($current_mysql, $recommended_mysql)) {
    $alert = "MySQL desactualizado: $current_mysql < $recommended_mysql";
    echo "ALERTA: $alert\n";
    save_alert($alert);
} elseif (strpos($current_mysql, 'Error') !== false) {
    echo "Error al obtener versión de MySQL: $current_mysql\n";
} else {
    echo "MySQL está actualizado.\n";
}
echo "\n";

// 3. Verificar dependencias de Composer
$composer_file = __DIR__ . '/../composer.json'; // Asumiendo que está en la raíz del proyecto
if (file_exists($composer_file)) {
    echo "Revisando composer.json...\n";
    $composer_data = json_decode(file_get_contents($composer_file), true);
    if (isset($composer_data['require'])) {
        foreach ($composer_data['require'] as $package => $version_constraint) {
            // Extraer versión actual (simple, asume formato como ^1.29)
            $current_version = str_replace(['^', '~'], '', $version_constraint);
            echo "Dependencia: $package (actual: $current_version)\n";
            $latest_version = get_latest_version_from_packagist($package);
            if ($latest_version) {
                echo "Última versión: $latest_version\n";
                if (is_version_outdated($current_version, $latest_version)) {
                    $alert = "Dependencia desactualizada: $package $current_version < $latest_version";
                    echo "ALERTA: $alert\n";
                    save_alert($alert);
                } else {
                    echo "Actualizado.\n";
                }
            } else {
                echo "No se pudo obtener la última versión de Packagist.\n";
            }
            echo "\n";
        }
    } else {
        echo "No se encontraron dependencias en composer.json.\n";
    }
} else {
    echo "composer.json no encontrado.\n";
}

echo "Verificación completada. Alertas guardadas en update_alerts.log si existen.\n";
?>
