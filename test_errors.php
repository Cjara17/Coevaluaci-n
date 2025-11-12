<?php
// 1. FORZAMOS QUE PHP MUESTRE ERRORES DE FORMA GLOBAL
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. FORZAMOS UN ERROR DE SINTAXIS (Deberías ver un error rojo)
echo "Esta línea se ejecutó.";

// 3. Incluimos db.php (Si hay un error aquí, debería mostrarlo)
require 'db.php';

// 4. Intentamos usar la conexión
if (isset($conn) && $conn->ping()) {
    echo "<h1>✅ ÉXITO: db.php se cargó y la conexión está activa.</h1>";
} else {
    echo "<h1>❌ FALLO: db.php cargó, pero la conexión falló o no se encontró.</h1>";
}

// 5. Forzamos un error fatal que NO debería fallar si display_errors funciona
function test_error($param) {
    echo $param;
}

// Llamamos a una función con argumentos insuficientes. Esto es un error fatal.
test_error(); 

echo "Esta línea NO debería mostrarse si el error fatal anterior ocurrió.";

// Omitimos ?>