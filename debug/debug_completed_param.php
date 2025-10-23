<?php
require_once('../../../config.php');
global $DB, $USER;

require_login();

$quizid = 2;
$userid = $USER->id;
$courseid = 2;

echo "<h2>Depuración: ¿Por qué no aparece 'completed=1'?</h2>";

// 1. Verificar si el examen está aprobado
$quiz_result = $DB->get_record('learningstylesurvey_quiz_results', [
    'userid' => $userid,
    'quizid' => $quizid,
    'courseid' => $courseid
]);

echo "<h3>1. Resultado del Examen</h3>";
if ($quiz_result) {
    echo "<p>Score: <strong>{$quiz_result->score}%</strong></p>";
    echo "<p>Estado: " . ($quiz_result->score >= 70 ? '<span style="color:green;">✅ APROBADO</span>' : '<span style="color:red;">❌ REPROBADO</span>') . "</p>";
} else {
    echo "<p style='color:red;'>No hay resultado del examen</p>";
}

// 2. Obtener el paso del examen
$step = $DB->get_record_sql("
    SELECT s.* FROM {learningpath_steps} s 
    WHERE s.resourceid = ? AND s.istest = 1
    ORDER BY s.id DESC LIMIT 1
", [$quizid]);

echo "<h3>2. Información del Paso</h3>";
echo "<pre>";
print_r($step);
echo "</pre>";

// 3. Verificar si hay passredirect
echo "<h3>3. Verificación de passredirect</h3>";
echo "<p>passredirect value: <strong>{$step->passredirect}</strong></p>";
echo "<p>¿Es mayor que 0? " . (($step->passredirect && $step->passredirect > 0) ? '<span style="color:green;">SÍ</span>' : '<span style="color:red;">NO</span>') . "</p>";
echo "<p>¿Entra al IF de passredirect? " . (($step && $step->passredirect && $step->passredirect > 0) ? '<span style="color:green;">SÍ (PROBLEMA)</span>' : '<span style="color:red;">NO (CORRECTO)</span>') . "</p>";

// 4. Simular la lógica que debería ejecutarse
if ($step && $step->passredirect && $step->passredirect > 0) {
    echo "<div style='background:#ffcccc; padding:10px; border:1px solid red;'>";
    echo "<h4>❌ PROBLEMA DETECTADO</h4>";
    echo "<p>El código está entrando al bloque de 'passredirect' cuando NO debería.</p>";
    echo "<p>Está intentando buscar un recurso con ID = {$step->passredirect}</p>";
    echo "</div>";
} else {
    echo "<div style='background:#ccffcc; padding:10px; border:1px solid green;'>";
    echo "<h4>✅ CORRECTO</h4>";
    echo "<p>El código NO entra al bloque de 'passredirect', pasa al ELSE donde verifica siguientes pasos.</p>";
    echo "</div>";
    
    // Simular la búsqueda de siguiente paso
    $userstyle = $DB->get_record_sql("
        SELECT style FROM {learningstylesurvey_userstyles}
        WHERE userid = ? ORDER BY timecreated DESC LIMIT 1
    ", [$userid]);
    
    $style = $userstyle ? $userstyle->style : null;
    
    echo "<h4>4. Búsqueda de Siguiente Paso</h4>";
    echo "<p>Estilo del usuario: <strong>$style</strong></p>";
    
    $nextstep = $DB->get_record_sql("
        SELECT s.* FROM {learningpath_steps} s
        LEFT JOIN {learningstylesurvey_resources} r ON s.resourceid = r.id AND s.istest = 0
        LEFT JOIN {learningstylesurvey_path_temas} pt ON pt.temaid = r.tema AND pt.pathid = s.pathid
        WHERE s.pathid = ? AND s.stepnumber > ?
        AND (
            (s.istest = 1) OR 
            (s.istest = 0 AND r.style = ? AND r.courseid = ? AND (pt.isrefuerzo = 0 OR pt.isrefuerzo IS NULL))
        )
        ORDER BY s.stepnumber ASC LIMIT 1
    ", [$step->pathid, $step->stepnumber, $style, $courseid]);
    
    if ($nextstep) {
        echo "<div style='background:#ffcccc; padding:10px; border:1px solid red;'>";
        echo "<h5>❌ Encontró siguiente paso (por eso NO pone completed=1)</h5>";
        echo "<pre>";
        print_r($nextstep);
        echo "</pre>";
        echo "</div>";
    } else {
        echo "<div style='background:#ccffcc; padding:10px; border:1px solid green;'>";
        echo "<h5>✅ NO hay siguiente paso (debería poner completed=1)</h5>";
        echo "<p><strong>La URL debería ser:</strong></p>";
        echo "<code>vista_estudiante.php?courseid=$courseid&pathid={$step->pathid}&completed=1&cmid=9</code>";
        echo "</div>";
    }
}

// 5. Verificar el progreso del usuario
echo "<h3>5. Progreso del Usuario</h3>";
$progress = $DB->get_record('learningstylesurvey_user_progress', [
    'userid' => $userid,
    'pathid' => $step->pathid
]);

if ($progress) {
    echo "<pre>";
    print_r($progress);
    echo "</pre>";
    echo "<p>Estado actual: <strong>{$progress->status}</strong></p>";
} else {
    echo "<p>No hay registro de progreso</p>";
}

?>
