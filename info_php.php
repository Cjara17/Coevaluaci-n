<?php
/**
 * Script para verificar información de PHP y ubicación de php.ini
 * Acceda a: http://localhost/Coevaluaci-n/info_php.php
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Información de PHP</title>
    <link rel="stylesheet" href="public/assets/css/info_php.css">
</head>
<body>
    <div class="container">
        <h1>🔍 Información de Configuración PHP</h1>
        
        <?php
        $phpIniPath = php_ini_loaded_file();
        $phpIniScanned = php_ini_scanned_files();
        $zipLoaded = extension_loaded('zip');
        $zipArchiveExists = class_exists('ZipArchive');
        
        // Información del archivo php.ini
        echo '<div class="info-box ' . ($phpIniPath ? 'success' : 'error') . '">';
        echo '<h3>📄 Archivo php.ini en uso:</h3>';
        if ($phpIniPath) {
            echo '<code>' . htmlspecialchars($phpIniPath) . '</code>';
            echo '<p><strong>✅ Este es el archivo que debe editar.</strong></p>';
            
            // Verificar si el archivo existe y es legible
            if (file_exists($phpIniPath)) {
                echo '<p>✅ El archivo existe y es accesible.</p>';
                
                // Leer el contenido y buscar extension=zip
                $iniContent = file_get_contents($phpIniPath);
                $hasExtensionZip = preg_match('/^\s*;?\s*extension\s*=\s*zip\s*$/mi', $iniContent, $matches);
                
                if ($hasExtensionZip) {
                    echo '<div class="info-box warning">';
                    echo '<h4>🔍 Línea encontrada en php.ini:</h4>';
                    // Buscar todas las líneas relacionadas con zip
                    preg_match_all('/^\s*;?\s*extension\s*=\s*zip.*$/mi', $iniContent, $allMatches);
                    foreach ($allMatches[0] as $line) {
                        $trimmed = trim($line);
                        $isCommented = (substr($trimmed, 0, 1) === ';');
                        echo '<code>' . htmlspecialchars($trimmed) . '</code>';
                        if ($isCommented) {
                            echo '<p>⚠️ Esta línea está <strong>COMENTADA</strong> (tiene ; al inicio). Debe eliminar el ; para habilitarla.</p>';
                        } else {
                            echo '<p>✅ Esta línea está <strong>HABILITADA</strong>. Si ZipArchive no funciona, puede ser otro problema.</p>';
                        }
                    }
                    echo '</div>';
                } else {
                    echo '<div class="info-box error">';
                    echo '<h4>❌ No se encontró la línea extension=zip</h4>';
                    echo '<p>Necesita <strong>agregar</strong> la siguiente línea al final de la sección de extensiones:</p>';
                    echo '<code>extension=zip</code>';
                    echo '<p><strong>Instrucciones:</strong></p>';
                    echo '<ol>';
                    echo '<li>Abra el archivo: <code>' . htmlspecialchars($phpIniPath) . '</code></li>';
                    echo '<li>Busque la sección <code>[Extensions]</code> o busque otras líneas que digan <code>extension=</code></li>';
                    echo '<li>Agregue al final de esa sección: <code>extension=zip</code></li>';
                    echo '<li>Guarde el archivo</li>';
                    echo '<li>Reinicie Apache en XAMPP</li>';
                    echo '</ol>';
                    echo '</div>';
                }
            } else {
                echo '<p>❌ El archivo no existe o no es accesible.</p>';
            }
        } else {
            echo '<p>❌ No se encontró el archivo php.ini cargado.</p>';
        }
        echo '</div>';
        
        // Archivos adicionales escaneados
        if ($phpIniScanned) {
            echo '<div class="info-box">';
            echo '<h3>📂 Archivos adicionales escaneados:</h3>';
            echo '<code>' . htmlspecialchars($phpIniScanned) . '</code>';
            echo '</div>';
        }
        
        // Estado de ZipArchive
        echo '<div class="info-box ' . ($zipLoaded && $zipArchiveExists ? 'success' : 'error') . '">';
        echo '<h3>📦 Estado de ZipArchive:</h3>';
        echo '<table>';
        echo '<tr><th>Componente</th><th>Estado</th></tr>';
        echo '<tr><td>Extensión zip cargada</td><td>' . ($zipLoaded ? '✅ Sí' : '❌ No') . '</td></tr>';
        echo '<tr><td>Clase ZipArchive disponible</td><td>' . ($zipArchiveExists ? '✅ Sí' : '❌ No') . '</td></tr>';
        echo '</table>';
        echo '</div>';
        
        // Información adicional
        echo '<div class="info-box">';
        echo '<h3>ℹ️ Información del sistema:</h3>';
        echo '<table>';
        echo '<tr><th>Propiedad</th><th>Valor</th></tr>';
        echo '<tr><td>Versión de PHP</td><td>' . phpversion() . '</td></tr>';
        echo '<tr><td>Sistema Operativo</td><td>' . PHP_OS . '</td></tr>';
        echo '<tr><td>Arquitectura</td><td>' . (PHP_INT_SIZE * 8) . ' bits</td></tr>';
        echo '<tr><td>Directorio de extensiones</td><td>' . ini_get('extension_dir') . '</td></tr>';
        echo '</table>';
        echo '</div>';
        
        // Verificar si existe el archivo DLL de zip
        $extensionDir = ini_get('extension_dir');
        if ($extensionDir) {
            $zipDll = rtrim($extensionDir, '\\/') . DIRECTORY_SEPARATOR . 'php_zip.dll';
            echo '<div class="info-box">';
            echo '<h3>📁 Verificación de archivos:</h3>';
            echo '<table>';
            echo '<tr><th>Archivo</th><th>Estado</th></tr>';
            echo '<tr><td>php_zip.dll</td><td>' . (file_exists($zipDll) ? '✅ Existe: <code>' . htmlspecialchars($zipDll) . '</code>' : '❌ No encontrado en: <code>' . htmlspecialchars($zipDll) . '</code>') . '</td></tr>';
            echo '</table>';
            echo '</div>';
        }
        ?>
        
        <div style="margin-top: 30px; text-align: center;">
            <a href="verificar_zip.php" class="btn">Verificar ZipArchive</a>
            <a href="dashboard_docente.php" class="btn">Volver al Dashboard</a>
        </div>
    </div>
</body>
</html>

