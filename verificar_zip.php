<?php
/**
 * Script de verificación para comprobar si la extensión ZipArchive está habilitada
 * Acceda a este archivo desde su navegador: http://localhost/Coevaluaci-n/verificar_zip.php
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de ZipArchive</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .status {
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            font-size: 18px;
            font-weight: bold;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .info h3 {
            margin-top: 0;
        }
        .info ol {
            line-height: 1.8;
        }
        code {
            background-color: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Verificación de Extensión ZipArchive</h1>
        
        <?php
        $zipHabilitado = extension_loaded('zip');
        $zipArchiveDisponible = class_exists('ZipArchive');
        
        if ($zipHabilitado && $zipArchiveDisponible) {
            echo '<div class="status success">';
            echo '✓ ZipArchive está HABILITADO y funcionando correctamente';
            echo '</div>';
            echo '<p>Puede subir archivos Excel (.xlsx) sin problemas.</p>';
        } else {
            echo '<div class="status error">';
            echo '✗ ZipArchive NO está habilitado';
            echo '</div>';
            
            echo '<div class="info">';
            echo '<h3>📋 Cómo habilitar ZipArchive en XAMPP:</h3>';
            echo '<ol>';
            echo '<li>Abra el archivo <code>php.ini</code> de XAMPP<br>';
            echo '   <small>Ubicación típica: <code>C:\\xampp\\php\\php.ini</code></small><br>';
            echo '   <small>O desde el panel de control de XAMPP: <strong>Config > PHP > php.ini</strong></small></li>';
            echo '<li>Busque la línea que contiene: <code>;extension=zip</code></li>';
            echo '<li>Elimine el punto y coma (<code>;</code>) al inicio para descomentarla:<br>';
            echo '   <code>extension=zip</code></li>';
            echo '<li>Guarde el archivo <code>php.ini</code></li>';
            echo '<li>Reinicie Apache desde el panel de control de XAMPP<br>';
            echo '   <small>(Haga clic en <strong>Stop</strong> y luego en <strong>Start</strong>)</small></li>';
            echo '<li>Recargue esta página para verificar que funcionó</li>';
            echo '</ol>';
            echo '</div>';
            
            echo '<div class="info">';
            echo '<h3>💡 Alternativa temporal:</h3>';
            echo '<p>Mientras habilita ZipArchive, puede usar archivos <strong>CSV</strong> en lugar de Excel (.xlsx). Los archivos CSV funcionan sin necesidad de esta extensión.</p>';
            echo '</div>';
        }
        
        // Información adicional
        echo '<div class="info">';
        echo '<h3>ℹ️ Información del sistema:</h3>';
        echo '<ul>';
        echo '<li><strong>Versión de PHP:</strong> ' . phpversion() . '</li>';
        echo '<li><strong>Extensión zip cargada:</strong> ' . ($zipHabilitado ? 'Sí ✓' : 'No ✗') . '</li>';
        echo '<li><strong>Clase ZipArchive disponible:</strong> ' . ($zipArchiveDisponible ? 'Sí ✓' : 'No ✗') . '</li>';
        echo '</ul>';
        echo '</div>';
        ?>
        
        <p style="margin-top: 30px; text-align: center;">
            <a href="dashboard_docente.php" style="color: #007bff; text-decoration: none;">← Volver al Dashboard</a>
        </p>
    </div>
</body>
</html>

