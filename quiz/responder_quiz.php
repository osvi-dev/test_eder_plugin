<?php
require_once('../../../config.php');
global $DB, $USER, $OUTPUT;

// Detectar si se carga embebido y de dónde viene
$embedded = optional_param('embedded', 0, PARAM_INT) == 1;
$retry = optional_param('retry', 0, PARAM_INT) == 1;
$from_refuerzo = optional_param('from_refuerzo', 0, PARAM_INT) == 1;
$cmid = optional_param('cmid', 0, PARAM_INT);

$quizid   = required_param('id', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$userid   = $USER->id;

// Validar courseid
if (!$courseid) {
    // Intentar obtener courseid desde el quiz
    $quiz = $DB->get_record('learningstylesurvey_quizzes', ['id' => $quizid]);
    if ($quiz && $quiz->courseid) {
        $courseid = $quiz->courseid;
    } else {
        throw new moodle_exception('courseid es requerido');
    }
}

require_login($courseid);
$PAGE->set_url(new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', ['id' => $quizid, 'courseid' => $courseid, 'embedded' => $embedded ? 1 : 0]));
$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_title('Responder Cuestionario');
$PAGE->set_heading('Responder Cuestionario');
$PAGE->set_pagelayout('popup'); // Cambiar a popup para evitar problemas de navegación
echo $OUTPUT->header();
echo "<div class='box generalbox' style='padding: 20px; max-width: 800px; margin: 0 auto;'>";
echo "<h3 style='text-align: center; margin-bottom: 20px;'>Cuestionario: " . format_string($DB->get_field('learningstylesurvey_quizzes','name',['id'=>$quizid])) . "</h3>";
// Solo mostrar el cuestionario sin botones adicionales que puedan interferir

// Función para procesar envío
function process_quiz_submission($quizid, $courseid, $userid, $embedded = false) {
    global $DB;

    $questions = $DB->get_records('learningstylesurvey_questions', ['quizid' => $quizid]);
    $total = count($questions);
    $correct = 0;

    foreach ($questions as $q) {
        $userOptionId = optional_param("question{$q->id}", null, PARAM_INT);
        // ✅ Ordenar opciones por ID para mantener consistencia con el índice guardado
        $options = $DB->get_records('learningstylesurvey_options', ['questionid' => $q->id], 'id ASC');
        $selectedText = null;
        $selectedIndex = null;
        
        // Buscar la opción seleccionada por ID
        if ($userOptionId !== null) {
            foreach ($options as $opt) {
                if ($opt->id == $userOptionId) {
                    $selectedText = $opt->optiontext;
                    break;
                }
            }
        }
        
        // ✅ Encontrar el índice correcto (0, 1, 2...) basado en el orden de las opciones
        if ($userOptionId !== null) {
            $optionIndex = 0; // ✅ Empezar desde 0, no desde 1
            foreach ($options as $opt) {
                if ($opt->id == $userOptionId) {
                    $selectedIndex = $optionIndex;
                    break;
                }
                $optionIndex++;
            }
        }
        
        // Verificación robusta para correctanswer (maneja tanto índice numérico como texto)
        $isCorrect = false;
        if (is_numeric($q->correctanswer)) {
            // Nuevo formato: índice numérico (0, 1, 2, 3...)
            $isCorrect = ($selectedIndex !== null && (int)$q->correctanswer == $selectedIndex);
        } else {
            // Formato antiguo: texto de la opción
            $isCorrect = ($selectedText !== null && trim(strtolower($selectedText)) === trim(strtolower($q->correctanswer)));
        }
        
        if ($isCorrect) {
            $correct++;
        }
    }

    $score = ($total > 0) ? round(($correct / $total) * 100) : 0;
    
    // Buscar si ya existe resultado
    $existing = $DB->get_record('learningstylesurvey_quiz_results', [
        'userid' => $userid,
        'quizid' => $quizid,
        'courseid' => $courseid
    ]);

    $record = new stdClass();
    $record->userid = $userid;
    $record->quizid = $quizid;
    $record->courseid = $courseid;
    $record->score = $score;
    $record->timemodified = time();
    $record->timecompleted = time();

    // CAMBIO IMPORTANTE: Siempre crear un nuevo registro para cada intento
    // Esto permite un seguimiento preciso del progreso del estudiante
    $result_id = $DB->insert_record('learningstylesurvey_quiz_results', $record);
    
    return $score;
}

// Verificar si ya respondió
$result = $DB->get_record('learningstylesurvey_quiz_results', [
    'userid' => $userid,
    'quizid' => $quizid,
    'courseid' => $courseid
]);

if ($result) {$retry = true;}

// Si es un reintento y existe un resultado previo, eliminarlo
if ($retry && $result) {
    $deleted = $DB->delete_records('learningstylesurvey_quiz_results', [
        'userid' => $userid,
        'quizid' => $quizid,
        'courseid' => $courseid
    ]);
    echo "<div class='alert alert-success'>✅ Resultado anterior eliminado. Puedes realizar el examen nuevamente.</div>";
    $result = null; // Limpiar la variable para permitir mostrar el formulario
}

// Lógica mejorada: verificar si hay saltos configurados antes de permitir reintentos automáticos
$can_retry = false;
$auto_retry = false;

// IMPORTANTE: Verificar primero si la ruta ya está completada
// Necesitamos obtener el pathid del quiz para verificar la ruta específica
$pathid_for_quiz = $DB->get_field_sql("
    SELECT s.pathid FROM {learningpath_steps} s 
    WHERE s.resourceid = ? AND s.istest = 1
    ORDER BY s.id DESC LIMIT 1
", [$quizid]);

$route_completed = false;
if ($pathid_for_quiz) {
    $route_completed = $DB->get_record('learningstylesurvey_user_progress', [
        'userid' => $userid,
        'pathid' => $pathid_for_quiz,
        'status' => 'completed'
    ]);
}

// Solo permitir reintento automático si NO hay saltos configurados Y la ruta NO está completada
if ($result && $result->score < 70) {
    // Si la ruta está completada y NO viene explícitamente de refuerzo, bloquear acceso
    if ($route_completed && !$from_refuerzo) {
        echo "<div class='alert alert-info' style='text-align:center; margin-top:20px;'>";
        echo "<h4>📋 Ruta completada</h4>";
        echo "<p>Esta ruta de aprendizaje ya ha sido completada. Para revisiones adicionales, consulta con tu instructor.</p>";
        if ($cmid) {
            $menuurl = new moodle_url('/mod/learningstylesurvey/view.php', ['id'=>$cmid]);
            echo "<a href='{$menuurl}' class='btn btn-primary'>Regresar al menú principal</a>";
        }
        echo "</div>";
        echo "</div>";
        echo $OUTPUT->footer();
        exit;
    }
    
    // Verificar si hay salto configurado para este examen
    $step_check = $DB->get_record_sql("
        SELECT s.* FROM {learningpath_steps} s 
        WHERE s.resourceid = ? AND s.istest = 1
        ORDER BY s.id DESC LIMIT 1
    ", [$result->quizid]);
    
    if ($step_check && $step_check->failredirect && $step_check->failredirect > 0) {
        // HAY salto configurado - NO hacer reintento automático
        echo "<div class='alert alert-warning'>⚠️ Resultado anterior reprobatorio ({$result->score}%). Se aplicará el salto programado.</div>";
        $auto_retry = false;
        $can_retry = false;
    } else {
        // NO hay salto configurado - permitir reintento automático
        echo "<div class='alert alert-warning'>⚠️ Resultado anterior reprobatorio ({$result->score}%). Puedes volver a intentarlo.</div>";
        echo "<div class='alert alert-info'>💡 <strong>Tip:</strong> Si repruebas, podrás acceder a material de refuerzo y volver a intentarlo.</div>";
        $auto_retry = true;
        $can_retry = true;
    }
}



if ($result && !$retry && !$auto_retry) {
    echo "<div class='alert alert-success' style='font-size: 14px;'>
        ✅ Examen ya completado - Score: {$result->score}% - " . date('Y-m-d H:i:s', $result->timecompleted) . "
    </div>";
        echo "</div>";
    
    // También ofrecer reintento inmediato si el estudiante lo desea
    $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
        'id' => $quizid,
        'courseid' => $courseid,
        'embedded' => 1,
        'retry' => 1,
        'cmid' => $cmid
    ]);
    // echo "<div style='text-align:center; margin:10px 0;'>";
    // echo "<a href='{$retryurl}' class='btn btn-primary btn-lg' style='padding:10px 20px; text-decoration:none; background:#007bff; color:#fff; border-radius:5px;'>Reintentar examen</a>";
    // echo "</div>";

} else if ($retry) {
    echo "<div class='alert alert-success'>🔄 Reintento solicitado</div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $score = process_quiz_submission($quizid, $courseid, $userid, $embedded);
    
    // Obtener el mejor score guardado (puede ser diferente al actual si hubo intentos previos)
    $best_result = $DB->get_record('learningstylesurvey_quiz_results', [
        'userid' => $userid,
        'quizid' => $quizid,
        'courseid' => $courseid
    ]);
    $best_score = $best_result ? $best_result->score : $score;
    
    echo "<div style='text-align:center; margin-top:20px;'>";
    echo "<h3>Resultado actual: {$score}%</h3>";
    
    if ($best_score != $score) {
        echo "<p style='color:#007bff; font-weight:bold;'>Mejor resultado: {$best_score}%</p>";
    }
    
    if ($best_score >= 70) {
        echo "<p style='color:green; font-weight:bold;'>¡Aprobado!</p>";
        if ($score < 70) {
            echo "<div class='alert alert-success'>✅ Aunque este intento fue {$score}%, tu mejor resultado ({$best_score}%) ya aprueba el examen.</div>";
        }
        
        // REDIRECCIÓN AUTOMÁTICA después de aprobar
        echo "<div class='alert alert-success' style='text-align:center; margin-top:20px;'>";
        echo "<h4>🎉 ¡Examen aprobado!</h4>";
        echo "<p>Continuando automáticamente con la ruta de aprendizaje...</p>";
        echo "<div class='progress' style='height:15px; margin:20px 0;'>";
        echo "<div class='progress-bar progress-bar-striped progress-bar-animated' style='width:100%; background:#28a745;'></div>";
        echo "</div>";
        echo "</div>";
        
        // Buscar el paso de examen correcto para obtener saltos programados
        $step = $DB->get_record_sql("
            SELECT s.* FROM {learningpath_steps} s 
            WHERE s.resourceid = ? AND s.istest = 1
            ORDER BY s.id DESC LIMIT 1
        ", [$quizid]);
        
        if ($step && $step->passredirect && $step->passredirect > 0) {
            // Salto programado después de aprobar
            $target_resource = $DB->get_record('learningstylesurvey_resources', ['id' => $step->passredirect]);
            
            if ($target_resource) {
                $nexturl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                    'courseid' => $courseid,
                    'pathid' => $step->pathid,
                    'tema_salto' => $target_resource->tema,
                    'cmid' => $cmid
                ]);
            } else {
                // Si no se encuentra el recurso, continuar con la ruta normal
                $nexturl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                    'courseid' => $courseid,
                    'pathid' => $step->pathid,
                    'cmid' => $cmid
                ]);
            }
        } else {
            // Si no hay salto configurado, verificar si hay más pasos después de este examen
            if ($step) {
                // Obtener el estilo del usuario para verificar siguientes pasos
                $userstyle = $DB->get_record_sql("
                    SELECT style FROM {learningstylesurvey_userstyles}
                    WHERE userid = ? ORDER BY timecreated DESC LIMIT 1
                ", [$userid]);
                
                // Si el estilo es null, mandamos el mensaje que debe de completar la encuesta
                $style = $userstyle ? $userstyle->style : null;

                if (!$style) {
                    // Redirigir a hacer la encuesta o mostrar error
                    throw new moodle_exception('Debes completar la encuesta de estilos de aprendizaje primero');
                }
                
                // Buscar si hay más pasos después de este examen
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
                    // Hay más pasos - continuar con la ruta
                    $nexturl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                        'courseid' => $courseid,
                        'pathid' => $step->pathid,
                        'cmid' => $cmid
                    ]);
                } else {
                    // No hay más pasos - ruta completada
                    $nexturl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                        'courseid' => $courseid,
                        'pathid' => $step->pathid,
                        'completed' => 1,
                        'cmid' => $cmid
                    ]);
                }
            } else {
                // Fallback al menú principal
                $nexturl = new moodle_url('/mod/learningstylesurvey/view.php', ['id' => $cmid]);
            }
        }
        
        echo "<div style='margin-top:20px; text-align:center;'>";
        echo "<div class='alert alert-success' style='margin-bottom:20px;'>";
        echo "<h4>✅ ¡Excelente trabajo!</h4>";
        echo "<p>Has aprobado el examen. Tómate el tiempo necesario para revisar tu resultado.</p>";
        echo "</div>";
        
        echo "<a href='{$nexturl}' class='btn btn-success btn-lg' style='margin:10px; padding:12px 25px; font-size:16px; text-decoration:none; background:#28a745; color:white; border-radius:5px; display:inline-block;'>Continuar ahora</a>";
        echo "</div>";
        
    } else {
        echo "<p style='color:red; font-weight:bold;'>Reprobado</p>";
        
        // VERIFICAR si hay salto configurado y si es tema de refuerzo o no
        $step = $DB->get_record_sql("
            SELECT s.* FROM {learningpath_steps} s 
            WHERE s.resourceid = ? AND s.istest = 1
            ORDER BY s.id DESC LIMIT 1
        ", [$quizid]);
        
        if ($step && $step->failredirect && $step->failredirect > 0) {
            $target_resource = $DB->get_record('learningstylesurvey_resources', ['id' => $step->failredirect]);
            
            if ($target_resource) {
                // Verificar si es tema de refuerzo
                $is_refuerzo_tema = $DB->get_record('learningstylesurvey_path_temas', [
                    'pathid' => $step->pathid,
                    'temaid' => $target_resource->tema,
                    'isrefuerzo' => 1
                ]);
                
                if ($is_refuerzo_tema) {
                    // ES TEMA DE REFUERZO: Mensaje con tiempo y botón
                    echo "<div class='alert alert-warning' style='text-align:center; margin-top:20px;'>";
                    echo "<h4>🔄 Material de refuerzo disponible</h4>";
                    echo "<p>Tu puntuación indica que necesitas revisar material adicional para reforzar tu comprensión del tema.</p>";
                    echo "<p><strong>Te recomendamos revisar el contenido de refuerzo antes de intentar nuevamente.</strong></p>";
                    echo "</div>";
                    

                    
                    $refuerzourl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                        'courseid' => $courseid,
                        'pathid' => $step->pathid,
                        'tema_refuerzo' => $target_resource->tema,
                        'cmid' => $cmid
                    ]);
                    
                    echo "<div style='text-align:center; margin:20px 0;'>";
                    echo "<a href='{$refuerzourl}' class='btn btn-warning btn-lg' style='margin:10px; padding:12px 25px; font-size:16px; text-decoration:none; background:#ffc107; color:#000; border-radius:5px; display:inline-block;'>Ir al material de refuerzo</a>";
                    echo "</div>";
                    
                    // Permitir reintento desde la vista de refuerzo
                    $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
                        'id' => $quizid,
                        'courseid' => $courseid,
                        'embedded' => 1,
                        'retry' => 1,
                        'from_refuerzo' => 1,
                        'cmid' => $cmid
                    ]);
                    echo "<div style='text-align:center; margin:10px 0;'>";
                    echo "<a href='{$retryurl}' class='btn btn-primary btn-lg' style='padding:10px 20px; text-decoration:none; background:#007bff; color:#fff; border-radius:5px;'>Reintentar examen</a>";
                    echo "</div>";
                    

                } else {
                    // NO ES TEMA DE REFUERZO: Tema asignado para revisión
                    echo "<div class='alert alert-info' style='text-align:center; margin-top:20px;'>";
                    echo "<h4>🎯 Material adicional asignado</h4>";
                    echo "<p>Se te ha asignado material adicional para complementar tu aprendizaje antes de continuar.</p>";
                    echo "<p><strong>Te recomendamos revisar este contenido antes de seguir con la ruta.</strong></p>";
                    echo "</div>";
                    

                    
                    $saltourl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                        'courseid' => $courseid,
                        'pathid' => $step->pathid,
                        'tema_salto' => $target_resource->tema,
                        'cmid' => $cmid
                    ]);
                    
                    echo "<div style='text-align:center; margin:20px 0;'>";
                    echo "<a href='{$saltourl}' class='btn btn-info btn-lg' style='margin:10px; padding:12px 25px; font-size:16px; text-decoration:none; background:#17a2b8; color:white; border-radius:5px; display:inline-block;'>Ir al material asignado</a>";
                    echo "</div>";



                    /* 
                    
                    MODIFIQUE ESTOOOOOOOOOOOOOOOOOO
                    
                    */


                    // // También ofrecer reintento inmediato si el estudiante lo desea
                    // $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
                    //     'id' => $quizid,
                    //     'courseid' => $courseid,
                    //     'embedded' => 1,
                    //     'retry' => 1,
                    //     'cmid' => $cmid
                    // ]);
                    // echo "<div style='text-align:center; margin:10px 0;'>";
                    // echo "<a href='{$retryurl}' class='btn btn-primary btn-lg' style='padding:10px 20px; text-decoration:none; background:#007bff; color:#fff; border-radius:5px;'>Reintentar examen</a>";
                    // echo "</div>";
                    

                }
            } else {
                // Si no se encuentra el recurso de salto, permitir reintento
                echo "<div class='alert alert-info' style='text-align:center; margin-top:20px;'>";
                echo "<h4>� Preparando nuevo intento</h4>";
                echo "<p>No se encontró material adicional. Puedes intentar el examen nuevamente cuando estés listo.</p>";
                echo "</div>";

            }
        } else {
            // No hay salto configurado - permitir reintento inmediato
            echo "<div class='alert alert-warning' style='text-align:center; margin-top:20px;'>";
            echo "<h4>� Sin material adicional</h4>";
            echo "<p>No se ha configurado material adicional. El examen ha finalizado - consulta con tu instructor.</p>";
            echo "</div>";
            



        }
    }
    
    // ELIMINAMOS los botones manuales - todo es automático ahora
    
    echo "</div>";
} else if ($result && !$auto_retry) {
    // Mostrar resultado previo solo si está aprobado o no se permite auto-retry
    echo "<div style='text-align:center; margin-top:20px;'>";
    echo "<h3>Resultado previo: {$result->score}%</h3>";
    echo $result->score >= 70
        ? "<p style='color:green; font-weight:bold;'>¡Aprobado!</p>"
        : "<p style='color:red; font-weight:bold;'>Reprobado</p>";
    
    // Si está reprobado, aplicar redirección automática
    if ($result->score < 70) {
        echo "<div class='alert alert-warning' style='text-align:center; margin-top:20px;'>";
        echo "<h4>📊 Revisión de resultado</h4>";
        echo "<p>Tu puntuación anterior fue insuficiente. Revisa las opciones disponibles para mejorar tu comprensión.</p>";
        echo "</div>";
        
        // Buscar si hay tema de refuerzo configurado
        $step = $DB->get_record_sql("
            SELECT s.* FROM {learningpath_steps} s 
            WHERE s.resourceid = ? AND s.istest = 1
            ORDER BY s.id DESC LIMIT 1
        ", [$quizid]);
        
        if ($step && $step->failredirect && $step->failredirect > 0) {
            $target_resource = $DB->get_record('learningstylesurvey_resources', ['id' => $step->failredirect]);
            
            if ($target_resource) {
                // Verificar si es tema de refuerzo
                $is_refuerzo_tema = $DB->get_record('learningstylesurvey_path_temas', [
                    'pathid' => $step->pathid,
                    'temaid' => $target_resource->tema,
                    'isrefuerzo' => 1
                ]);
                
                if ($is_refuerzo_tema) {
                    // Mensaje para tema de refuerzo
                    echo "<div class='alert alert-info' style='margin-top:20px;'>";
                    echo "<h5>🔄 Material de refuerzo disponible</h5>";
                    echo "<p>Se recomienda revisar el material de refuerzo antes de intentar nuevamente.</p>";
                    echo "</div>";
                    

                    
                    $refuerzourl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                        'courseid' => $courseid,
                        'pathid' => $step->pathid,
                        'tema_refuerzo' => $target_resource->tema,
                        'cmid' => $cmid
                    ]);
                    
                    echo "<div style='text-align:center; margin:20px 0;'>";
                    echo "<a href='{$refuerzourl}' class='btn btn-warning btn-lg' style='margin:10px; padding:12px 25px; font-size:16px; text-decoration:none; background:#ffc107; color:#000; border-radius:5px; display:inline-block;'>Ir al refuerzo</a>";
                    echo "</div>";
                    

                } else {
                    // Redirección a tema normal (sin forzar retorno)
                    echo "<div class='alert alert-info' style='margin-top:20px;'>";
                    echo "<h5>📚 Material de apoyo disponible</h5>";
                    echo "<p>Se ha configurado material adicional para ayudarte a mejorar. Puedes revisarlo antes de reintentar.</p>";
                    echo "</div>";
                    

                    
                    $saltourl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                        'courseid' => $courseid,
                        'pathid' => $step->pathid,
                        'tema_salto' => $target_resource->tema,
                        'cmid' => $cmid
                    ]);
                    
                    echo "<div style='text-align:center; margin:20px 0;'>";
                    echo "<a href='{$saltourl}' class='btn btn-info btn-lg' style='margin:10px; padding:12px 25px; font-size:16px; text-decoration:none; background:#17a2b8; color:#fff; border-radius:5px; display:inline-block;'>Ver material</a>";
                    echo "</div>";
                    

                }
            } else {
                // Si no se encuentra recurso, ir a reintento
                echo "<div class='alert alert-secondary' style='margin-top:20px;'>";
                echo "<h5>🔄 Preparando reintento</h5>";
                echo "<p>No se encontró material específico. Puedes volver a intentar cuando estés listo.</p>";
                echo "</div>";
                

                
                $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
                    'id' => $quizid,
                    'courseid' => $courseid,
                    'embedded' => 1,
                    'retry' => 1,
                    'cmid' => $cmid
                ]);
                
                echo "<div style='text-align:center; margin:20px 0;'>";
                echo "<a href='{$retryurl}' class='btn btn-secondary btn-lg' style='margin:10px; padding:12px 25px; font-size:16px; text-decoration:none; background:#6c757d; color:#fff; border-radius:5px; display:inline-block;'>Reintentar ahora</a>";
                echo "</div>";
                

            }
        } else {
            // No hay tema de refuerzo - ir directo a reintento
            echo "<div class='alert alert-warning' style='margin-top:20px;'>";
            echo "<h5>🎯 Preparando nuevo intento</h5>";
            echo "<p>No hay material de refuerzo configurado. Puedes intentar el examen nuevamente cuando te sientas preparado.</p>";
            echo "</div>";
            

            
            $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
                'id' => $quizid,
                'courseid' => $courseid,
                'embedded' => 1,
                'retry' => 1,
                'cmid' => $cmid
            ]);
            
            echo "<div style='text-align:center; margin:20px 0;'>";
            echo "<a href='{$retryurl}' class='btn btn-warning btn-lg' style='margin:10px; padding:12px 25px; font-size:16px; text-decoration:none; background:#ffc107; color:#000; border-radius:5px; display:inline-block;'>Reintentar examen</a>";
            echo "</div>";
            

        }
    } else {
        // Está aprobado - verificar si es el último paso para mostrar mensaje de completitud
        echo "<div class='alert alert-success' style='text-align:center; margin-top:20px;'>";
        echo "<h4>✅ Examen ya aprobado</h4>";
        echo "<p>Tu resultado anterior fue exitoso ({$result->score}%). Continuando con la ruta de aprendizaje...</p>";
        echo "</div>";
        
        $step = $DB->get_record_sql("
            SELECT s.* FROM {learningpath_steps} s 
            WHERE s.resourceid = ? AND s.istest = 1
            ORDER BY s.id DESC LIMIT 1
        ", [$quizid]);
        
        if ($step) {
            // Obtener el estilo del usuario
            $userstyle = $DB->get_record_sql("
                SELECT style FROM {learningstylesurvey_userstyles}
                WHERE userid = ? ORDER BY timecreated DESC LIMIT 1
            ", [$userid]);
            
            $style = $userstyle ? $userstyle->style : null;
            
            if ($style) {
                // Buscar si hay más pasos después de este examen
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
                    // Hay más pasos - continuar con la ruta
                    $returnurl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                        'courseid' => $courseid,
                        'pathid' => $step->pathid,
                        'cmid' => $cmid
                    ]);
                } else {
                    // No hay más pasos - ruta completada
                    $returnurl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                        'courseid' => $courseid,
                        'pathid' => $step->pathid,
                        'completed' => 1,
                        'cmid' => $cmid
                    ]);
                }
            } else {
                // Sin estilo definido, ir al menú
                $returnurl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                    'courseid' => $courseid,
                    'cmid' => $cmid
                ]);
            }
        } else {
            // No se encontró el paso, ir a vista general
            $returnurl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                'courseid' => $courseid,
                'cmid' => $cmid
            ]);
        }
        
        echo "<div style='text-align:center; margin:20px 0;'>";
        echo "<a href='{$returnurl}' class='btn btn-success btn-lg' style='margin:10px; padding:12px 25px; font-size:16px; text-decoration:none; background:#28a745; color:#fff; border-radius:5px; display:inline-block;'>Continuar ruta</a>";
        echo "</div>";
        

    }
    
    echo "</div>";
} else {
    // Mostrar formulario: cuando no hay resultado, viene con retry, o auto_retry está activo
    $questions = $DB->get_records('learningstylesurvey_questions', ['quizid' => $quizid]);
    
    // Le hacemos un shuffle a las preguntas
    $questions_array = array_values($questions); // Convertir a array indexado
    shuffle($questions_array); // Mezclar aleatoriamente
    
    if ($auto_retry) {
        echo "<div class='alert alert-info'>";
        echo "<h4>🔄 Nuevo intento</h4>";
        echo "<p>Puedes volver a realizar este examen. No hay límite de intentos.</p>";
        echo "</div>";
    }
    
    echo '<form method="post" action="">'; 
    echo '<div style="margin: 20px 0;">';
    
    // Contador para cada pregunta
    $question_number = 1;
    foreach ($questions_array as $q) {
        // ✅ Ordenar opciones por ID para mantener consistencia
        $options = $DB->get_records('learningstylesurvey_options', ['questionid' => $q->id], 'id ASC');

        echo "<div style='margin-bottom:25px; padding:15px; border:1px solid #ddd; border-radius:5px; background:#f9f9f9;'>";
        echo "<h4 style='margin-bottom:15px; color:#333;'>Pregunta {$question_number}: " . format_string($q->questiontext) . "</h4>";
        
        foreach ($options as $opt) {
            $radio_id = "q{$q->id}_opt{$opt->id}";
            echo "<div style='margin-bottom:10px;'>";
            echo "<input type='radio' id='{$radio_id}' name='question{$q->id}' value='{$opt->id}' style='margin-right:8px;'>";
            echo "<label for='{$radio_id}' style='cursor:pointer;'>" . format_string($opt->optiontext) . "</label>";
            echo "</div>";
        }
        echo "</div>";
        $question_number++;
    }

    $cancelurl = new moodle_url('/mod/learningstylesurvey/view.php', ['id' => $cmid]);
    
    echo '<div style="text-align:center; margin-top:30px;">';
    echo '<input type="submit" value="Enviar respuestas" style="padding:12px 30px; margin:10px; font-size:16px; background:#28a745; color:white; border:none; border-radius:5px; cursor:pointer;">';
    echo '<a href="' . $cancelurl . '" class="btn" style="padding:12px 30px; margin:10px; font-size:16px; background:#dc3545; color:white; border:none; border-radius:5px; cursor:pointer; text-decoration:none; display:inline-block;">Cancelar intento</a>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
}

echo "</div>";
?>
