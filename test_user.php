<?php
require 'db.php';
$emails = ['docente@uct.cl','estudiante@alu.uct.cl'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Test Usuarios - Coevaluación</title>
  <style>body{font-family:Arial,Helvetica,sans-serif;padding:20px}pre{background:#f5f5f5;padding:10px;border-radius:6px}</style>
</head>
<body>
  <h2>Comprobación de usuarios en la base de datos</h2>
  <p>Este script muestra si los usuarios de prueba existen en la tabla <code>usuarios</code>.</p>
<?php
foreach ($emails as $email) {
    $e = $conn->real_escape_string($email);
    $res = $conn->query("SELECT id,nombre,email,es_docente,password,id_equipo,id_curso FROM usuarios WHERE email='$e'");
    echo "<h3>Consulta: <code>".htmlspecialchars($email)."</code></h3>";
    if (!$res) {
        echo "<p style='color:red'>Error en la consulta: " . htmlspecialchars($conn->error) . "</p>";
        continue;
    }
    if ($res->num_rows === 0) {
        echo "<p style='color:darkred'>Resultado: <strong>No encontrado</strong></p>";
    } else {
        $row = $res->fetch_assoc();
        echo "<p style='color:green'>Resultado: <strong>Encontrado</strong></p>";
        echo '<pre>' . htmlspecialchars(print_r($row, true)) . '</pre>';
        if (!empty($row['password'])) {
            echo "<p>Nota: el usuario tiene un hash de contraseña almacenado (docente). Para la contraseña de prueba use: <code>123456</code></p>";
        } else {
            echo "<p>Nota: el usuario no tiene contraseña (estudiante).</p>";
        }
    }
}
?>
  <hr>
  <p>Si los usuarios no existen, importe <code>coeval_db.sql</code> en <code>phpMyAdmin</code> o use la línea de comandos.</p>
</body>
</html>