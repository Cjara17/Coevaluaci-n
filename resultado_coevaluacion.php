<?php
session_start();

// Verificar si hay datos en sesión
if (!isset($_SESSION['resultado_nota_final']) || !isset($_SESSION['resultado_detalles'])) {
    header("Location: dashboard_estudiante.php");
    exit();
}

$nota_final = $_SESSION['resultado_nota_final'];
$detalles = $_SESSION['resultado_detalles'];
$ponderacion_aplicada = $_SESSION['resultado_detalles_globales']['ponderacion_aplicada'];

// Limpiar los datos de sesión después de usarlos
unset($_SESSION['resultado_nota_final']);
unset($_SESSION['resultado_detalles']);
unset($_SESSION['resultado_detalles_globales']);
?>

<div class="resultado-box" style="margin-bottom: 15px;">
    <h2>Resultado de tu Coevaluación</h2>
    <h3>Tu calificación final:</h3>
    <div id="nota-final" class="nota-final"><?php echo htmlspecialchars(number_format($nota_final, 2)); ?></div>

    <div class="ponderacion-final">
        <strong>Ponderación aplicada:</strong> <?= $ponderacion_aplicada ?>
    </div>

    <h4>Desglose por criterios</h4>
    <div id="detalle-criterios">
        <?php foreach ($detalles as $detalle): ?>
            <div class="criterio-item">
                <div class="criterio-nombre"><?= htmlspecialchars($detalle["criterio"]) ?></div>
                <div>Puntaje: <?= htmlspecialchars($detalle["puntaje"]) ?> / <?= htmlspecialchars($detalle["max"]) ?></div>
                <div>Ponderación: <?= htmlspecialchars($detalle["ponderacion"]) ?></div>
                <div>Resultado: <?= htmlspecialchars(number_format($detalle["resultado"], 2)) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
