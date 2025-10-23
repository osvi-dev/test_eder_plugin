<?php
require_once('../../../config.php');
global $DB, $USER;

require_login();

$quizid = 2; // El quiz que aprobaste
$pathid = 1; // La ruta
$userid = $USER->id;

echo "<h2>Depuración de Ruta Completa</h2>";

// 1. Obtener información del examen
$step = $DB->get_record_sql("
    SELECT s.* FROM {learningpath_steps} s 
    WHERE s.resourceid = ? AND s.istest = 1
    ORDER BY s.id DESC LIMIT 1
", [$quizid]);

echo "<h3>1. Paso del Examen (Quiz ID: $quizid)</h3>";
echo "<pre>";
print_r($step);
echo "</pre>";

// 2. Obtener estilo del usuario
$userstyle = $DB->get_record_sql("
    SELECT style FROM {learningstylesurvey_userstyles}
    WHERE userid = ? ORDER BY timecreated DESC LIMIT 1
", [$userid]);

$style = $userstyle ? $userstyle->style : 'visual';

echo "<h3>2. Estilo del Usuario</h3>";
echo "<p>Estilo: <strong>$style</strong></p>";

// 3. Buscar todos los pasos de la ruta
$allsteps = $DB->get_records('learningpath_steps', ['pathid' => $pathid], 'stepnumber ASC');

echo "<h3>3. Todos los Pasos de la Ruta (pathid: $pathid)</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Step ID</th><th>Step Number</th><th>Resource ID</th><th>Is Test</th><th>Pass Redirect</th><th>Fail Redirect</th></tr>";
foreach ($allsteps as $s) {
    echo "<tr>";
    echo "<td>{$s->id}</td>";
    echo "<td>{$s->stepnumber}</td>";
    echo "<td>{$s->resourceid}</td>";
    echo "<td>" . ($s->istest ? 'YES' : 'NO') . "</td>";
    echo "<td>{$s->passredirect}</td>";
    echo "<td>{$s->failredirect}</td>";
    echo "</tr>";
}
echo "</table>";

// 4. Buscar siguiente paso después del examen (la consulta que está fallando)
if ($step) {
    echo "<h3>4. Buscando Siguiente Paso después del Examen (stepnumber > {$step->stepnumber})</h3>";
    
    $nextstep = $DB->get_record_sql("
        SELECT s.*, r.name as resource_name, r.style as resource_style, pt.isrefuerzo
        FROM {learningpath_steps} s
        LEFT JOIN {learningstylesurvey_resources} r ON s.resourceid = r.id AND s.istest = 0
        LEFT JOIN {learningstylesurvey_path_temas} pt ON pt.temaid = r.tema AND pt.pathid = s.pathid
        WHERE s.pathid = ? AND s.stepnumber > ?
        AND (
            (s.istest = 1) OR 
            (s.istest = 0 AND r.style = ? AND r.courseid = ? AND (pt.isrefuerzo = 0 OR pt.isrefuerzo IS NULL))
        )
        ORDER BY s.stepnumber ASC LIMIT 1
    ", [$step->pathid, $step->stepnumber, $style, 2]); // courseid = 2
    
    if ($nextstep) {
        echo "<p style='color:red;'><strong>❌ ENCONTRÓ UN SIGUIENTE PASO (por eso no marca como completada):</strong></p>";
        echo "<pre>";
        print_r($nextstep);
        echo "</pre>";
    } else {
        echo "<p style='color:green;'><strong>✅ NO hay siguiente paso - debería marcar como completada</strong></p>";
    }
}

// 5. Verificar recursos del estilo del usuario
echo "<h3>5. Recursos disponibles para estilo '$style' en curso 2</h3>";
$resources = $DB->get_records('learningstylesurvey_resources', ['style' => $style, 'courseid' => 2]);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Tema ID</th><th>Filename</th></tr>";
foreach ($resources as $r) {
    echo "<tr>";
    echo "<td>{$r->id}</td>";
    echo "<td>{$r->name}</td>";
    echo "<td>{$r->tema}</td>";
    echo "<td>{$r->filename}</td>";
    echo "</tr>";
}
echo "</table>";

// 6. Verificar temas de refuerzo
echo "<h3>6. Temas de Refuerzo en la Ruta</h3>";
$refuerzo_temas = $DB->get_records('learningstylesurvey_path_temas', ['pathid' => $pathid, 'isrefuerzo' => 1]);
if ($refuerzo_temas) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Tema ID</th><th>Orden</th></tr>";
    foreach ($refuerzo_temas as $rt) {
        echo "<tr>";
        echo "<td>{$rt->id}</td>";
        echo "<td>{$rt->temaid}</td>";
        echo "<td>{$rt->orden}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay temas de refuerzo configurados.</p>";
}
?>
