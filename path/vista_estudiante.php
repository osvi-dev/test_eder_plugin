<?php
require_once("../../../config.php");
require_once("$CFG->libdir/formslib.php");
global $DB, $USER, $PAGE, $OUTPUT;

$courseid = required_param('courseid', PARAM_INT);
$pathid = optional_param('pathid', 0, PARAM_INT);
$stepid = optional_param('stepid', 0, PARAM_INT);
$tema_salto = optional_param('tema_salto', 0, PARAM_INT); // Para saltos adaptativos por tema
$tema_refuerzo = optional_param('tema_refuerzo', 0, PARAM_INT); // Para temas de refuerzo
$resource_index = optional_param('resource_index', 0, PARAM_INT); // Para navegación secuencial en temas de salto
$cmid = optional_param('cmid', 0, PARAM_INT);
$completed = optional_param('completed', 0, PARAM_INT); // Para mostrar mensaje de finalización
$from_salto = optional_param('from_salto', 0, PARAM_INT); // Para evitar reprocessar saltos

// Si no se proporciona cmid, intentar obtenerlo de manera inteligente
if (!$cmid) {
    $modinfo = get_fast_modinfo($courseid);
    $cms = $modinfo->get_instances_of('learningstylesurvey');
    if (!empty($cms)) {
        // LÓGICA MEJORADA: Buscar instancia con ruta en progreso para el usuario
        $cmid_with_progress = $DB->get_record_sql("
            SELECT p.cmid 
            FROM {learningstylesurvey_paths} p
            LEFT JOIN {learningstylesurvey_user_progress} up ON up.pathid = p.id AND up.userid = ?
            WHERE p.courseid = ? 
            AND (up.status IS NULL OR up.status != 'completed')
            ORDER BY up.timemodified DESC, p.timecreated DESC
            LIMIT 1
        ", [$USER->id, $courseid]);
        
        if ($cmid_with_progress) {
            $cmid = $cmid_with_progress->cmid;
        } else {
            // Fallback: usar la instancia más reciente con ruta disponible
            $cmid_with_route = $DB->get_record_sql("
                SELECT cmid 
                FROM {learningstylesurvey_paths} 
                WHERE courseid = ?
                ORDER BY timecreated DESC 
                LIMIT 1
            ", [$courseid]);
            
            if ($cmid_with_route) {
                $cmid = $cmid_with_route->cmid;
            } else {
                // Si no hay rutas, usar la primera instancia
                $firstcm = reset($cms);
                $cmid = $firstcm->id;
            }
        }
    }
}

require_login();

// Función para mostrar un recurso
function mostrar_recurso($resource) {
    global $CFG, $COURSE;
    $fileurl = new moodle_url('/mod/learningstylesurvey/resource/ver_recurso.php', [
        'filename' => $resource->filename,
        'courseid' => $resource->courseid,
        'serve' => 1
    ]);
    $filepath = $CFG->dataroot . "/learningstylesurvey/" . $resource->courseid . "/" . $resource->filename;
    $ext = strtolower(pathinfo($resource->filename, PATHINFO_EXTENSION));
    
    echo "<h3>" . format_string($resource->name ?: 'Recurso') . "</h3>";
    
    // Verificar si el archivo existe físicamente
    if (!file_exists($filepath)) {
        echo "<div class='alert alert-danger'>❌ <strong>Archivo no encontrado:</strong> {$resource->filename}</div>";
        echo "<p>El archivo puede haber sido movido o eliminado del servidor.</p>";
        return;
    }
    
    // Mostrar según el tipo de archivo
    if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
        // Imágenes
        echo "<img src='$fileurl' style='max-width:100%; height:auto; margin-bottom:20px; border: 1px solid #ddd; border-radius: 8px;'>";
        
    } elseif ($ext === 'pdf') {
        // PDFs
        echo "<iframe src='$fileurl' style='width:100%; height:600px; border:none; border-radius: 8px;'></iframe>";
        
    } elseif (in_array($ext, ['mp4','webm','avi','mov'])) {
        // Videos
        echo "<video controls style='width:100%; max-height:500px; border-radius: 8px;'>";
        echo "<source src='$fileurl' type='video/$ext'>";
        echo "Tu navegador no soporta video HTML5.";
        echo "</video>";
        
    } elseif (in_array($ext, ['mp3','wav','ogg'])) {
        // Audio
        echo "<audio controls style='width:100%; margin-bottom:20px;'>";
        echo "<source src='$fileurl' type='audio/$ext'>";
        echo "Tu navegador no soporta audio HTML5.";
        echo "</audio>";
        
    } elseif ($ext === 'txt') {
        // Archivos de texto - mostrar contenido
        $content = file_get_contents($filepath);
        if ($content !== false) {
            echo "<div style='background:#f8f9fa; padding:20px; border:1px solid #dee2e6; border-radius:8px; font-family:monospace; white-space:pre-wrap; max-height:500px; overflow-y:auto;'>";
            echo htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
            echo "</div>";
        } else {
            echo "<div class='alert alert-warning'>No se pudo leer el contenido del archivo de texto.</div>";
        }
        
    } elseif (in_array($ext, ['doc','docx','xls','xlsx','ppt','pptx'])) {
        // Archivos de Microsoft Office - usar Office Online Viewer
        $encoded_url = urlencode($fileurl);
        echo "<iframe src='https://view.officeapps.live.com/op/embed.aspx?src={$encoded_url}' style='width:100%; height:600px; border:none; border-radius: 8px;'></iframe>";
        echo "<p><small>📝 <strong>Nota:</strong> Si el visor no funciona, <a href='$fileurl' target='_blank'>descarga el archivo</a> para abrirlo localmente.</small></p>";
        
    } elseif (in_array($ext, ['html','htm'])) {
        // Archivos HTML
        echo "<iframe src='$fileurl' style='width:100%; height:600px; border:1px solid #ddd; border-radius: 8px;'></iframe>";
        
    } else {
        // Otros tipos de archivo - enlace de descarga mejorado
        $filesize = file_exists($filepath) ? human_filesize(filesize($filepath)) : 'Tamaño desconocido';
        echo "<div style='background:#f8f9fa; padding:20px; border:1px solid #dee2e6; border-radius:8px; text-align:center;'>";
        echo "<p>📁 <strong>Archivo:</strong> {$resource->filename}</p>";
        echo "<p>📊 <strong>Tamaño:</strong> {$filesize}</p>";
        echo "<a href='$fileurl' target='_blank' class='btn btn-primary' style='background:#007bff; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>📥 Descargar archivo</a>";
        echo "</div>";
    }
}

// Función auxiliar para formatear tamaño de archivo
function human_filesize($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}
$context = context_course::instance($courseid);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', ['courseid'=>$courseid,'pathid'=>$pathid]));
$PAGE->set_title("Ruta de Aprendizaje");
$PAGE->set_heading("Ruta de Aprendizaje");

// Obtener estilo más reciente del usuario
$userstyle = $DB->get_record_sql("
    SELECT style
    FROM {learningstylesurvey_userstyles}
    WHERE userid = ?
    ORDER BY timecreated DESC
    LIMIT 1
", [$USER->id]);

$show_warning = false;
if (!$userstyle) {
    $show_warning = true;
    echo $OUTPUT->header();
    echo "<div class='container' style='max-width:600px; margin:40px auto;'>";
    echo "<div class='alert alert-warning' style='font-size:18px; background:#fff3cd; color:#856404; border:1px solid #ffeeba; padding:20px; border-radius:8px;'>";
    echo "<strong>¡Atención!</strong> Para acceder a la ruta de aprendizaje primero debes contestar la <b>encuesta de estilos de aprendizaje</b>.";
    echo "</div>";
    // Botón para regresar al menú principal del plugin
    if ($cmid) {
        $viewurl = new moodle_url('/mod/learningstylesurvey/view.php', ['id' => $cmid]);
        echo html_writer::link($viewurl, 'Regresar al menú principal', ['class' => 'btn btn-primary', 'style' => 'font-size:18px; margin-top:20px;']);
    }
    echo "</div>";
    echo $OUTPUT->footer();
    exit;
}
$style = $userstyle->style; // El estilo ya viene normalizado desde la base de datos

// Obtener ruta más reciente si no se pasa pathid
if (!$pathid) {
    // LÓGICA MEJORADA: Buscar ruta específica para esta instancia (cmid)
    if ($cmid) {
        // 1. Primero buscar si hay una ruta en progreso para esta instancia específica
        $currentroute = $DB->get_record_sql("
            SELECT p.id 
            FROM {learningstylesurvey_paths} p
            LEFT JOIN {learningstylesurvey_user_progress} up ON up.pathid = p.id AND up.userid = ?
            WHERE p.courseid = ? AND p.cmid = ? 
            AND (up.status IS NULL OR up.status != 'completed')
            ORDER BY p.timecreated DESC 
            LIMIT 1
        ", [$USER->id, $courseid, $cmid]);
        
        if ($currentroute) {
            $pathid = $currentroute->id;
        } else {
            // 2. Si no hay ruta en progreso, buscar cualquier ruta para esta instancia
            $anyrouteforinstance = $DB->get_record_sql("
                SELECT id 
                FROM {learningstylesurvey_paths} 
                WHERE courseid = ? AND cmid = ?
                ORDER BY timecreated DESC 
                LIMIT 1
            ", [$courseid, $cmid]);
            
            if ($anyrouteforinstance) {
                $pathid = $anyrouteforinstance->id;
            }
        }
    }
    
    // 3. Si aún no hay pathid, buscar ruta más reciente del curso (fallback)
    if (!$pathid) {
        $lastroute = $DB->get_record_sql("
            SELECT id, cmid
            FROM {learningstylesurvey_paths} 
            WHERE courseid = ?
            ORDER BY timecreated DESC 
            LIMIT 1
        ", [$courseid]);
        
        if ($lastroute) {
            $pathid = $lastroute->id;
            // Actualizar cmid si no se proporcionó
            if (!$cmid) {
                $cmid = $lastroute->cmid;
            }
        }
    }
    
    if (!$pathid) {
        throw new moodle_exception('No se encontró ninguna ruta para esta actividad.');
    }
}

// --- FLUJO ADAPTADO ---
echo $OUTPUT->header();
echo "<div class='container' style='max-width:900px; margin:20px auto;'>";
echo "<h2>Ruta de Aprendizaje (" . ucfirst($style) . ")</h2>";

// Mostrar mensaje de finalización si se completó la ruta
if ($completed) {
    // Marcar la ruta como completada en la base de datos
    $existing_progress = $DB->get_record('learningstylesurvey_user_progress', [
        'userid' => $USER->id,
        'pathid' => $pathid
    ]);
    
    if ($existing_progress) {
        // Actualizar registro existente
        $existing_progress->status = 'completed';
        $existing_progress->timemodified = time();
        $DB->update_record('learningstylesurvey_user_progress', $existing_progress);
    } else {
        // Crear nuevo registro
        $progress_record = new stdClass();
        $progress_record->userid = $USER->id;
        $progress_record->pathid = $pathid;
        $progress_record->status = 'completed';
        $progress_record->timecreated = time();
        $progress_record->timemodified = time();
        $DB->insert_record('learningstylesurvey_user_progress', $progress_record);
    }
    
    echo "<div class='alert alert-success alert-dismissible' style='margin-bottom:30px;'>";
    echo "<button type='button' class='close' data-dismiss='alert'>&times;</button>";
    echo "<h4>🎉 ¡Felicitaciones!</h4>";
    echo "<p>Has completado exitosamente la ruta de aprendizaje.</p>";
    if ($cmid) {
        $menuurl = new moodle_url('/mod/learningstylesurvey/view.php', ['id'=>$cmid]);
        echo "<a href='{$menuurl}' class='btn btn-primary'>Regresar al menú principal</a>";
    }
    echo "</div>";
    echo "</div>";
    echo $OUTPUT->footer();
    exit;
}

// MANEJO ESPECIAL: Si viene desde refuerzo, ir directamente al examen
$is_returning_from_refuerzo = optional_param('from_refuerzo', 0, PARAM_INT);
if ($is_returning_from_refuerzo) {
    // Buscar el último examen reprobado para reintentarlo
    $lastquiz = $DB->get_record_sql("
        SELECT qr.*, s.failredirect 
        FROM {learningstylesurvey_quiz_results} qr
        JOIN {learningpath_steps} s ON s.resourceid = qr.quizid AND s.istest = 1
        WHERE qr.userid = ? AND qr.courseid = ? AND s.pathid = ?
        AND qr.score < 70
        ORDER BY qr.timecompleted DESC 
        LIMIT 1
    ", [$USER->id, $courseid, $pathid]);
    
    if ($lastquiz) {
        // Redirigir directamente al examen
        $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
            'id' => $lastquiz->quizid,
            'courseid' => $courseid,
            'embedded' => 1,
            'retry' => 1,
            'cmid' => $cmid,
            'from_refuerzo' => 1
        ]);
        redirect($retryurl);
    }
}

// Manejar saltos adaptativos por tema
if ($tema_salto) {
    // Determinar si es un salto a tema de refuerzo o salto normal
    $tema = $DB->get_record('learningstylesurvey_temas', ['id' => $tema_salto]);
    $is_refuerzo_tema = $DB->get_record('learningstylesurvey_path_temas', [
        'pathid' => $pathid,
        'temaid' => $tema_salto,
        'isrefuerzo' => 1
    ]);
    
    if ($tema) {
        if ($is_refuerzo_tema) {
            // SALTO A TEMA DE REFUERZO - Redirección automática después del refuerzo
            echo "<div class='alert alert-warning'>🔄 <strong>Salto automático a tema de refuerzo:</strong> " . format_string($tema->tema) . "</div>";
        } else {
            // SALTO NORMAL - Continuar con la ruta sin forzar retorno
            echo "<div class='alert alert-success'>🎯 <strong>Salto a tema:</strong> " . format_string($tema->tema) . "</div>";
        }
        
        $recursos = $DB->get_records('learningstylesurvey_resources', [
            'tema' => $tema_salto,
            'style' => $style,
            'courseid' => $courseid
        ], 'id ASC'); // Ordenar consistentemente
        
        if ($recursos) {
            $resource_keys = array_keys($recursos);
            $current_index = max(0, $resource_index); // Asegurar que no sea negativo
            
            if ($current_index < count($resource_keys)) {
                $resource_id = $resource_keys[$current_index];
                $resource = $recursos[$resource_id];
            } else {
                // Índice fuera de rango, usar el último recurso
                $resource = end($recursos);
                $current_index = count($resource_keys) - 1;
            }
            
            // Mostrar título del tema de salto
            if ($is_refuerzo_tema) {
                echo "<div style='background:#fff3cd; border-left:4px solid #ffc107; padding:15px; margin-bottom:20px; border-radius:5px;'>";
                echo "<h3 style='margin:0; color:#856404;'>🔄 " . format_string($tema->tema) . " (Refuerzo programado)</h3>";
                echo "</div>";
            } else {
                echo "<div style='background:#d4edda; border-left:4px solid #28a745; padding:15px; margin-bottom:20px; border-radius:5px;'>";
                echo "<h3 style='margin:0; color:#155724;'>🎯 " . format_string($tema->tema) . " (Tema asignado)</h3>";
                echo "</div>";
            }
            
            mostrar_recurso($resource);
            
            if ($is_refuerzo_tema) {
                // TEMA DE REFUERZO: Navegación secuencial de recursos y luego retorno
                echo "<div style='margin-top:30px; padding: 15px; background: #e7f3ff; border-left: 4px solid #007bff; border-radius: 5px;'>";
                echo "<h4 style='margin-top: 0;'>📚 Material de Refuerzo</h4>";
                echo "<p>Estás revisando material de refuerzo para fortalecer tu aprendizaje.</p>";
                echo "</div>";
                
                // Navegación por recursos del tema de refuerzo
                $total_resources = count($recursos);
                $next_index = $current_index + 1;
                
                if ($next_index < $total_resources) {
                    // Hay más recursos en este tema de refuerzo - continuar con el siguiente
                    echo "<div style='text-align: center; margin-top: 20px;'>";
                    echo "<p>Recurso " . ($current_index + 1) . " de " . $total_resources . " en este tema de refuerzo</p>";
                    echo "<a href='?courseid={$courseid}&pathid={$pathid}&tema_salto={$tema_salto}&resource_index={$next_index}&cmid={$cmid}' class='btn btn-success'>Continuar</a>";
                    echo "</div>";
                } else {
                    // Ya completó todos los recursos del tema de refuerzo - regresar al examen
                    echo "<div style='margin-top:20px; padding: 15px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 5px;'>";
                    echo "<h4 style='margin-top: 0;'>✅ Refuerzo completado</h4>";
                    echo "<p>Has completado todos los recursos de refuerzo (" . ($current_index + 1) . " de " . $total_resources . "). Regresando al examen...</p>";
                    echo "</div>";
                    
                    // Buscar el examen que originó este salto de refuerzo
                    $pending_exam = $DB->get_record_sql("
                        SELECT qr.*, s.failredirect 
                        FROM {learningstylesurvey_quiz_results} qr
                        JOIN {learningpath_steps} s ON s.resourceid = qr.quizid AND s.istest = 1
                        WHERE qr.userid = ? AND qr.courseid = ? AND qr.score < 70 AND s.failredirect = ?
                        ORDER BY qr.timecompleted DESC LIMIT 1
                    ", [$USER->id, $courseid, $tema_salto]);
                    
                    if ($pending_exam) {
                        $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
                            'id' => $pending_exam->quizid,
                            'courseid' => $courseid,
                            'embedded' => 1,
                            'retry' => 1,
                            'cmid' => $cmid,
                            'from_refuerzo' => 1
                        ]);
                        
                        echo "<p style='text-align:center; margin-top:20px;'>";
                        echo "<a href='{$retryurl}' class='btn btn-primary btn-lg'>Continuar</a>";
                        echo "</p>";
                    } else {
                        // Si no encuentra examen específico, botón manual para continuar
                        // Buscar el siguiente paso en la ruta para obtener el stepid
                        $next_step = $DB->get_record_sql("
                            SELECT s.* FROM {learningpath_steps} s 
                            WHERE s.pathid = ? 
                            ORDER BY s.stepnumber ASC LIMIT 1
                        ", [$pathid]);
                        
                        $stepid_param = $next_step ? $next_step->id : 0;
                        
                        echo "<form method='POST' action='siguiente.php' style='margin-top:20px;'>
                                <input type='hidden' name='stepid' value='{$stepid_param}'>
                                <input type='hidden' name='courseid' value='{$courseid}'>
                                <input type='hidden' name='pathid' value='{$pathid}'>
                                <input type='hidden' name='cmid' value='{$cmid}'>
                                <button type='submit' class='btn btn-success'>Continuar con la ruta</button>
                              </form>";
                    }
                }
            } else {
                // SALTO NORMAL: Mostrar todos los recursos del tema secuencialmente
                echo "<div style='margin-top:30px; padding: 15px; background: #d1ecf1; border-left: 4px solid #bee5eb; border-radius: 5px;'>";
                echo "<h4 style='margin-top: 0;'>⏩ Tema de salto</h4>";
                echo "<p>Estás viendo el material asignado para complementar tu aprendizaje.</p>";
                echo "</div>";
                
                // BOTÓN MANUAL para continuar navegando por los recursos del tema
                $total_resources = count($recursos);
                $next_index = $current_index + 1;
                
                if ($next_index < $total_resources) {
                    // Hay más recursos en este tema - continuar con el siguiente
                    echo "<div style='text-align: center; margin-top: 20px;'>";
                    echo "<p>Recurso " . ($current_index + 1) . " de " . $total_resources . " en este tema</p>";
                    echo "<a href='?courseid={$courseid}&pathid={$pathid}&tema_salto={$tema_salto}&resource_index={$next_index}&cmid={$cmid}' class='btn btn-success'>Continuar con el siguiente recurso</a>";
                    echo "</div>";
                } else {
                    // Ya completó todos los recursos del tema - continuar avanzando en la ruta desde este punto
                    echo "<div style='margin-top:20px; padding: 15px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 5px;'>";
                    echo "<h4 style='margin-top: 0;'>✅ Tema completado</h4>";
                    echo "<p>Has completado todos los recursos de este tema (" . ($current_index + 1) . " de " . $total_resources . "). Continuando con la ruta...</p>";
                    echo "</div>";
                    
                    // Buscar cualquier paso del tema actual para obtener su posición en la ruta
                    $current_tema_step = $DB->get_record_sql("
                        SELECT s.* FROM {learningpath_steps} s
                        JOIN {learningstylesurvey_resources} r ON s.resourceid = r.id
                        WHERE s.pathid = ? AND r.tema = ? AND s.istest = 0
                        ORDER BY s.stepnumber DESC LIMIT 1
                    ", [$pathid, $tema_salto]);
                    
                    if ($current_tema_step) {
                        // Buscar el siguiente paso después de TODOS los pasos de este tema
                        $next_step = $DB->get_record_sql("
                            SELECT s.* FROM {learningpath_steps} s
                            LEFT JOIN {learningstylesurvey_resources} r ON s.resourceid = r.id AND s.istest = 0
                            LEFT JOIN {learningstylesurvey_path_temas} pt ON pt.temaid = r.tema AND pt.pathid = s.pathid
                            WHERE s.pathid = ? AND s.stepnumber > ? 
                            AND (
                                (s.istest = 1) OR 
                                (s.istest = 0 AND r.style = ? AND r.courseid = ? AND (pt.isrefuerzo = 0 OR pt.isrefuerzo IS NULL))
                            )
                            ORDER BY s.stepnumber ASC LIMIT 1
                        ", [$pathid, $current_tema_step->stepnumber, $style, $courseid]);
                        
                        if ($next_step) {
                            // Hay un siguiente paso - continuar desde aquí
                            echo "<p style='text-align:center; margin-top:20px;'>";
                            echo "<a href='?courseid={$courseid}&pathid={$pathid}&stepid={$next_step->id}&cmid={$cmid}' class='btn btn-success'>Continuar al siguiente paso</a>";
                            echo "</p>";
                        } else {
                            // No hay más pasos - ruta completada
                            echo "<p style='text-align:center; margin-top:20px;'>";
                            echo "<a href='?courseid={$courseid}&pathid={$pathid}&completed=1&cmid={$cmid}' class='btn btn-success'>Finalizar ruta</a>";
                            echo "</p>";
                        }
                    } else {
                        // Fallback: usar navegación sin parámetros de salto para evitar loops
                        echo "<p style='text-align:center; margin-top:20px;'>";
                        echo "<a href='?courseid={$courseid}&pathid={$pathid}&cmid={$cmid}&from_salto=1' class='btn btn-success'>Continuar con la ruta</a>";
                        echo "</p>";
                    }
                }
            }
        } else {
            echo "<div class='alert alert-warning'>No hay recursos para tu estilo de aprendizaje en este tema.</div>";
        }
        echo "</div>";
        echo $OUTPUT->footer();
        exit;
    }
}

if ($tema_refuerzo) {
    // TEMA DE REFUERZO - Mostrar navegación secuencial de recursos
    $tema = $DB->get_record('learningstylesurvey_temas', ['id' => $tema_refuerzo]);
    if ($tema) {
        echo "<div class='alert alert-warning'>🔄 <strong>Tema de refuerzo:</strong> " . format_string($tema->tema) . "</div>";
        
        $recursos_refuerzo = $DB->get_records('learningstylesurvey_resources', [
            'tema' => $tema_refuerzo,
            'style' => $style,
            'courseid' => $courseid
        ], 'id ASC'); // Ordenar consistentemente
        
        if ($recursos_refuerzo) {
            // Obtener índice actual de recurso, por defecto 0
            $current_index = max(0, $resource_index);
            $resource_keys = array_keys($recursos_refuerzo);
            
            if ($current_index < count($resource_keys)) {
                $resource_id = $resource_keys[$current_index];
                $resource = $recursos_refuerzo[$resource_id];
            } else {
                // Si el índice está fuera de rango, usar el último recurso
                $resource = end($recursos_refuerzo);
                $current_index = count($resource_keys) - 1;
            }
            
            // Mostrar título del tema de refuerzo
            echo "<div style='background:#fff3cd; border-left:4px solid #ffc107; padding:15px; margin-bottom:20px; border-radius:5px;'>";
            echo "<h3 style='margin:0; color:#856404;'>🔄 " . format_string($tema->tema) . " (Refuerzo)</h3>";
            echo "</div>";
            
            mostrar_recurso($resource);
            
            // Navegación secuencial por recursos de refuerzo
            $total_resources = count($recursos_refuerzo);
            $next_index = $current_index + 1;
            
            echo "<div style='margin-top:30px; padding: 15px; background: #e7f3ff; border-left: 4px solid #007bff; border-radius: 5px;'>";
            echo "<h4 style='margin-top: 0;'>📚 Material de Refuerzo</h4>";
            echo "<p>Recurso " . ($current_index + 1) . " de " . $total_resources . " del tema de refuerzo.</p>";
            echo "</div>";
            
            if ($next_index < $total_resources) {
                // Hay más recursos - continuar con el siguiente
                echo "<div style='text-align: center; margin-top: 20px;'>";
                echo "<a href='?courseid={$courseid}&pathid={$pathid}&tema_refuerzo={$tema_refuerzo}&resource_index={$next_index}&cmid={$cmid}' class='btn btn-success'>Continuar</a>";
                echo "</div>";
            } else {
                // Completó todos los recursos de refuerzo - buscar el examen pendiente
                $pending_exam = $DB->get_record_sql("
                    SELECT qr.quizid, qr.score, qr.timecompleted, s.pathid, s.stepnumber
                    FROM {learningstylesurvey_quiz_results} qr
                    JOIN {learningpath_steps} s ON s.resourceid = qr.quizid AND s.istest = 1
                    JOIN {learningstylesurvey_resources} r ON r.id = s.failredirect
                    WHERE qr.userid = ? AND qr.courseid = ? AND qr.score < 70 AND r.tema = ?
                    ORDER BY qr.timecompleted DESC LIMIT 1
                ", [$USER->id, $courseid, $tema_refuerzo]);
                
                echo "<div style='margin-top:20px; padding: 15px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 5px;'>";
                echo "<h4 style='margin-top: 0;'>✅ Refuerzo completado</h4>";
                echo "<p>Has completado todos los recursos de refuerzo. Ahora puedes continuar con el examen.</p>";
                echo "</div>";
                
                if ($pending_exam) {
                    // RETORNO AL EXAMEN después del refuerzo
                    $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
                        'id' => $pending_exam->quizid,
                        'courseid' => $courseid,
                        'embedded' => 1,
                        'retry' => 1,
                        'cmid' => $cmid,
                        'from_refuerzo' => 1
                    ]);
                    
                    echo "<p style='text-align:center; margin-top:20px;'>";
                    echo "<a href='{$retryurl}' class='btn btn-primary btn-lg'>Continuar</a>";
                    echo "</p>";
                } else {
                    // Si no hay examen específico pendiente, buscar el examen que originó este salto
                    $originating_exam = $DB->get_record_sql("
                        SELECT s.*, qr.quizid 
                        FROM {learningpath_steps} s
                        JOIN {learningstylesurvey_quiz_results} qr ON s.resourceid = qr.quizid
                        WHERE s.pathid = ? AND s.istest = 1 AND s.failredirect = ? AND qr.userid = ? AND qr.score < 70
                        ORDER BY qr.timecompleted DESC LIMIT 1
                    ", [$pathid, $tema_refuerzo, $USER->id]);
                    
                    if ($originating_exam) {
                        // Encontramos el examen que causó este salto - volver a él
                        $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
                            'id' => $originating_exam->quizid,
                            'courseid' => $courseid,
                            'embedded' => 1,
                            'retry' => 1,
                            'cmid' => $cmid,
                            'from_refuerzo' => 1
                        ]);
                        
                        echo "<div style='text-align: center; margin-top: 20px;'>";
                        echo "<a href='" . $retryurl->out() . "' class='btn btn-primary'>Continuar</a>";
                        echo "</div>";
                    } else {
                        // Si no se encuentra el examen origen, mensaje de error y enlace para continuar normalmente
                        echo "<div class='alert alert-warning'>";
                        echo "<h4>⚠️ No se encontró el examen de origen</h4>";
                        echo "<p>No se pudo identificar el examen que originó este salto. Puedes regresar a la ruta principal.</p>";
                        echo "</div>";
                        
                        $mainurl = new moodle_url('/mod/learningstylesurvey/path/vista_estudiante.php', [
                            'courseid' => $courseid,
                            'pathid' => $pathid,
                            'cmid' => $cmid
                        ]);
                        
                        echo "<div style='text-align: center; margin-top: 20px;'>";
                        echo "<a href='" . $mainurl->out() . "' class='btn btn-secondary'>Volver a la Ruta Principal</a>";
                        echo "</div>";
                    }
                }
            }
        } else {
            echo "<div class='alert alert-info'>No hay recursos de refuerzo específicos para tu estilo. Revisando examen pendiente...</div>";
            
            // Buscar recursos sin filtro de estilo como fallback
            $recursos_generales = $DB->get_records('learningstylesurvey_resources', [
                'tema' => $tema_refuerzo,
                'courseid' => $courseid
            ]);
            
            if ($recursos_generales) {
                echo "<div class='alert alert-info'>Mostrando recursos generales del tema:</div>";
                $resource = reset($recursos_generales);
                echo "<div style='background:#fff3cd; border-left:4px solid #ffc107; padding:15px; margin-bottom:20px; border-radius:5px;'>";
                echo "<h3 style='margin:0; color:#856404;'>🔄 " . format_string($tema->tema) . " (Refuerzo)</h3>";
                echo "</div>";
                mostrar_recurso($resource);
                
                // Redirección al examen después de mostrar recursos generales
                $pending_exam = $DB->get_record_sql("
                    SELECT qr.* FROM {learningstylesurvey_quiz_results} qr
                    JOIN {learningpath_steps} s ON s.resourceid = qr.quizid AND s.istest = 1
                    WHERE qr.userid = ? AND qr.courseid = ? AND qr.score < 70 AND s.failredirect = ?
                    ORDER BY qr.timecompleted DESC LIMIT 1
                ", [$USER->id, $courseid, $tema_refuerzo]);
                
                if ($pending_exam) {
                    $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
                        'id' => $pending_exam->quizid,
                        'courseid' => $courseid,
                        'embedded' => 1,
                        'retry' => 1,
                        'cmid' => $cmid,
                        'from_refuerzo' => 1
                    ]);
                    
                    echo "<p style='text-align:center; margin-top:20px;'>";
                    echo "<a href='{$retryurl}' class='btn btn-primary btn-lg'>Continuar</a>";
                    echo "</p>";
                }
            } else {
                // No hay recursos, ir directo al reintento del examen
                $pending_exam = $DB->get_record_sql("
                    SELECT qr.* FROM {learningstylesurvey_quiz_results} qr
                    JOIN {learningpath_steps} s ON s.resourceid = qr.quizid AND s.istest = 1
                    WHERE qr.userid = ? AND qr.courseid = ? AND qr.score < 70 AND s.failredirect = ?
                    ORDER BY qr.timecompleted DESC LIMIT 1
                ", [$USER->id, $courseid, $tema_refuerzo]);
                
                if ($pending_exam) {
                    $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
                        'id' => $pending_exam->quizid,
                        'courseid' => $courseid,
                        'embedded' => 1,
                        'retry' => 1,
                        'cmid' => $cmid,
                        'from_refuerzo' => 1
                    ]);
                    redirect($retryurl);
                }
            }
        }
        
        echo "</div>";
        echo $OUTPUT->footer();
        exit;
    }
}

// Si hay stepid específico, mostrar ese recurso (flujo normal)
if ($stepid) {
    $step = $DB->get_record('learningpath_steps', ['id' => $stepid]);
    if ($step) {
        // Actualizamos el progreso del usuario para que apunte a este paso
        // Esto es crítico cuando se viene de un tema_salto y se va al examen
        $progress = $DB->get_record('learningstylesurvey_user_progress', [
            'userid' => $USER->id,
            'pathid' => $pathid
        ]);
        
        if ($progress) {
            // Actualizar progreso existente
            $progress->current_stepid = $stepid;
            $progress->timemodified = time();
            $DB->update_record('learningstylesurvey_user_progress', $progress);
        } else {
            // Crear nuevo progreso
            $new_progress = (object)[
                'userid' => $USER->id,
                'pathid' => $pathid,
                'current_stepid' => $stepid,
                'status' => 'inprogress',
                'timecreated' => time(),
                'timemodified' => time()
            ];
            $DB->insert_record('learningstylesurvey_user_progress', $new_progress);
        }
        
        if ($step->istest) {
            // Si es un examen, redirigir automáticamente (sin botón)
            $quizurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
                'id' => $step->resourceid,
                'courseid' => $courseid,
                'embedded' => 1,
                'cmid' => $cmid
            ]);
            
            // Redirigir directamente al examen
            redirect($quizurl);
            exit;
        } else {
            // Si es un recurso, mostrarlo
            $resource = $DB->get_record('learningstylesurvey_resources', ['id' => $step->resourceid]);
            if ($resource) {
                // Mostrar título del tema actual
                $tema_actual = $DB->get_record('learningstylesurvey_temas', ['id' => $resource->tema]);
                if ($tema_actual) {
                    echo "<div style='background:#e7f3ff; border-left:4px solid #007bff; padding:15px; margin-bottom:20px; border-radius:5px;'>";
                    echo "<h3 style='margin:0; color:#0056b3;'>📚 " . format_string($tema_actual->tema) . "</h3>";
                    echo "</div>";
                }
                
                mostrar_recurso($resource);
                echo "<form method='POST' action='siguiente.php'>
                        <input type='hidden' name='courseid' value='{$courseid}'>
                        <input type='hidden' name='pathid' value='{$pathid}'>
                        <input type='hidden' name='stepid' value='{$step->id}'>
                        <input type='hidden' name='cmid' value='{$cmid}'>
                        <button type='submit' class='btn btn-success' style='margin-top:15px;'>Continuar</button>
                      </form>";
                echo "</div>";
                echo $OUTPUT->footer();
                exit;
            }
        }
    }
}

// Verificar si hay examen reprobado que requiera tema de refuerzo
// PERO SOLO si no estamos procesando un salto específico ni venimos de un salto completado
if (!$tema_salto && !$tema_refuerzo && !$from_salto) {
    // PRIMERA VERIFICACIÓN: Comprobar si la ruta ya está completada
    $completed_progress = $DB->get_record('learningstylesurvey_user_progress', [
        'userid' => $USER->id,
        'pathid' => $pathid,
        'status' => 'completed'
    ]);
    
    // EXCEPCIÓN ESPECIAL: Si viene desde refuerzo, permitir acceso al examen incluso si está completada
    if ($completed_progress && !$is_returning_from_refuerzo) {
        // Si la ruta está completada Y NO viene desde refuerzo, mostrar mensaje de ruta completada y salir
        echo "<div class='alert alert-info alert-dismissible' style='margin-bottom:30px;'>";
        echo "<h4>✅ Ruta ya completada</h4>";
        echo "<p>Ya has completado esta ruta de aprendizaje anteriormente.</p>";
        if ($cmid) {
            $menuurl = new moodle_url('/mod/learningstylesurvey/view.php', ['id'=>$cmid]);
            echo "<a href='{$menuurl}' class='btn btn-primary'>Regresar al menú principal</a>";
        }
        echo "</div>";
        echo "</div>";
        echo $OUTPUT->footer();
        exit;
    } else {
        // Buscar exámenes reprobados si: 1) La ruta NO está completada, O 2) Viene desde refuerzo
        $lastquiz = $DB->get_record_sql("
            SELECT qr.*, s.failredirect 
            FROM {learningstylesurvey_quiz_results} qr
            JOIN {learningpath_steps} s ON s.resourceid = qr.quizid AND s.istest = 1
            WHERE qr.userid = ? AND qr.courseid = ? AND s.pathid = ?
            ORDER BY qr.timecompleted DESC 
            LIMIT 1
        ", [$USER->id, $courseid, $pathid]);

        $show_refuerzo = false;
        $tema_refuerzo_id = null;

        if ($lastquiz && $lastquiz->score < 70 && $lastquiz->failredirect && !$is_returning_from_refuerzo) {
            $tema_refuerzo_id = $lastquiz->failredirect;
            $show_refuerzo = true;
        }
    }
} else {
    // Si ya estamos procesando un salto específico, no ejecutar detección automática
    $show_refuerzo = false;
    $tema_refuerzo_id = null;
}

if ($show_refuerzo && $tema_refuerzo_id) {
    // REDIRECCIÓN AUTOMÁTICA A TEMA DE REFUERZO con navegación secuencial
    $recursos_refuerzo = $DB->get_records('learningstylesurvey_resources', [
        'tema' => $tema_refuerzo_id,
        'style' => $style,
        'courseid' => $courseid
    ], 'id ASC'); // Ordenar consistentemente
    
    if ($recursos_refuerzo) {
        // Obtener índice actual de recurso, por defecto 0
        $current_index = max(0, $resource_index);
        $resource_keys = array_keys($recursos_refuerzo);
        
        if ($current_index < count($resource_keys)) {
            $resource_id = $resource_keys[$current_index];
            $resource = $recursos_refuerzo[$resource_id];
        } else {
            // Si el índice está fuera de rango, usar el último recurso
            $resource = end($recursos_refuerzo);
            $current_index = count($resource_keys) - 1;
        }
        
        $tema = $DB->get_record('learningstylesurvey_temas', ['id' => $tema_refuerzo_id]);
        
        echo "<div class='alert alert-warning' style='margin-bottom:20px;'>🔄 <strong>Redirección automática:</strong> Necesitas refuerzo en el tema: <strong>" . format_string($tema->tema) . "</strong></div>";
        
        // Mostrar título del tema de refuerzo
        echo "<div style='background:#fff3cd; border-left:4px solid #ffc107; padding:15px; margin-bottom:20px; border-radius:5px;'>";
        echo "<h3 style='margin:0; color:#856404;'>🔄 " . format_string($tema->tema) . " (Refuerzo)</h3>";
        echo "</div>";
        
        mostrar_recurso($resource);
        
        // Navegación secuencial por recursos de refuerzo
        $total_resources = count($recursos_refuerzo);
        $next_index = $current_index + 1;
        
        echo "<div style='margin-top:30px; padding: 15px; background: #e7f3ff; border-left: 4px solid #007bff; border-radius: 5px;'>";
        echo "<h4 style='margin-top: 0;'>📚 Material de Refuerzo</h4>";
        echo "<p>Recurso " . ($current_index + 1) . " de " . $total_resources . " del tema de refuerzo.</p>";
        echo "</div>";
        
        if ($next_index < $total_resources) {
            // Hay más recursos - continuar con el siguiente
            echo "<div style='text-align: center; margin-top: 20px;'>";
            echo "<a href='?courseid={$courseid}&pathid={$pathid}&tema_refuerzo={$tema_refuerzo_id}&resource_index={$next_index}&cmid={$cmid}' class='btn btn-primary btn-lg'>Continuar</a>";
            echo "</div>";
        } else {
            // Completó todos los recursos de refuerzo - ir al examen
            echo "<div style='margin-top:20px; padding: 15px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 5px;'>";
            echo "<h4 style='margin-top: 0;'>✅ Refuerzo completado</h4>";
            echo "<p>Has completado todos los recursos de refuerzo. Ahora puedes continuar con el examen.</p>";
            echo "</div>";
            
            $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
                'id' => $lastquiz->quizid,
                'courseid' => $courseid,
                'embedded' => 1,
                'retry' => 1,
                'cmid' => $cmid,
                'from_refuerzo' => 1
            ]);
            echo "<div class='text-center'>";
            echo "<a href='{$retryurl}' class='btn btn-primary btn-lg'>Continuar</a>";
            echo "</div>";
        }
    } else {
        echo "<div class='alert alert-warning'>No hay recursos de refuerzo específicos para tu estilo de aprendizaje.</div>";
        // Redirección directa al reintento si no hay recursos
        $retryurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
            'id' => $lastquiz->quizid,
            'courseid' => $courseid,
            'embedded' => 1,
            'retry' => 1,
            'cmid' => $cmid,
            'from_refuerzo' => 1
        ]);
        redirect($retryurl);
    }
} else {
    // Flujo normal: consultar progreso del usuario para mostrar el paso correcto
    $progress = $DB->get_record('learningstylesurvey_user_progress', [
        'userid' => $USER->id,
        'pathid' => $pathid
    ]);

    // VERIFICACIÓN CRÍTICA: Si la ruta está completada, mostrar mensaje de finalización
    if ($progress && $progress->status === 'completed') {
        echo "<div class='alert alert-success' style='text-align:center; padding:25px;'>";
        echo "<h4>🎉 ¡Ruta ya completada!</h4>";
        echo "<p>Ya has finalizado exitosamente esta ruta de aprendizaje.</p>";
        if ($cmid) {
            $menuurl = new moodle_url('/mod/learningstylesurvey/view.php', ['id'=>$cmid]);
            echo "<a href='{$menuurl}' class='btn btn-primary btn-lg' style='margin-top:10px;'>Regresar al menú principal</a>";
        }
        echo "</div>";
        echo "</div>";
        echo $OUTPUT->footer();
        exit;
    }

    if ($progress && $progress->current_stepid) {
        // Si hay progreso, mostrar el paso actual del usuario
        $step = $DB->get_record('learningpath_steps', ['id' => $progress->current_stepid]);
        
        // Verificar que el step existe y coincide con el estilo del usuario
        if ($step && !$step->istest) {
            $resource_check = $DB->get_record('learningstylesurvey_resources', [
                'id' => $step->resourceid,
                'style' => $style
            ]);

            // Verificar que el tema no sea de refuerzo
            if ($resource_check) {
                $tema_check = $DB->get_record('learningstylesurvey_path_temas', [
                    'pathid' => $pathid,
                    'temaid' => $resource_check->tema,
                    'isrefuerzo' => 0  // Solo temas normales
                ]);

                if (!$tema_check) {
                    // Si el tema es de refuerzo, buscar el siguiente paso apropiado
                    $resource_check = null;
                }
            }
            
            if (!$resource_check) {
                // Si el paso actual no coincide con el estilo o es refuerzo, buscar el siguiente paso apropiado
                $potential_step = $DB->get_record_sql("
                    SELECT s.*
                    FROM {learningpath_steps} s
                    LEFT JOIN {learningstylesurvey_resources} r ON s.resourceid = r.id AND s.istest = 0
                    LEFT JOIN {learningstylesurvey_path_temas} pt ON pt.temaid = r.tema AND pt.pathid = s.pathid
                    WHERE s.pathid = ? AND s.stepnumber > ?
                    AND (
                        (s.istest = 1) OR 
                        (s.istest = 0 AND r.style = ? AND r.courseid = ? AND (pt.isrefuerzo = 0 OR pt.isrefuerzo IS NULL))
                    )
                    ORDER BY s.stepnumber ASC
                    LIMIT 1
                ", [$pathid, $step->stepnumber, $style, $courseid]);
                
                if ($potential_step) {
                    $step = $potential_step;
                } else {
                    $step = null; // No hay más pasos apropiados
                }
            }
        }
    } else {
        // Si no hay progreso, crear uno y mostrar el primer paso disponible
        // Buscar el primer paso que sea adecuado: examen O recurso para su estilo (NO refuerzo)
        $step = $DB->get_record_sql("
            SELECT s.*
            FROM {learningpath_steps} s
            LEFT JOIN {learningstylesurvey_resources} r ON s.resourceid = r.id AND s.istest = 0
            LEFT JOIN {learningstylesurvey_path_temas} pt ON pt.temaid = r.tema AND pt.pathid = s.pathid
            WHERE s.pathid = ? 
            AND (
                (s.istest = 1) OR 
                (s.istest = 0 AND r.style = ? AND r.courseid = ? AND (pt.isrefuerzo = 0 OR pt.isrefuerzo IS NULL))
            )
            ORDER BY s.stepnumber ASC
            LIMIT 1
        ", [$pathid, $style, $courseid]);

        if ($step) {
            // Crear registro de progreso
            $new_progress = (object)[
                'userid' => $USER->id,
                'pathid' => $pathid,
                'current_stepid' => $step->id,
                'status' => 'inprogress',
                'timemodified' => time()
            ];
            $DB->insert_record('learningstylesurvey_user_progress', $new_progress);
        }
    }

    if ($step) {
        if ($step->istest) {
            // Si es un test, buscar en la tabla de quizzes
            $quiz = $DB->get_record('learningstylesurvey_quizzes', ['id' => $step->resourceid]);

            if ($quiz) {
                // Verificar si ya fue aprobado
                $quiz_result = $DB->get_record_sql("
                    SELECT * FROM {learningstylesurvey_quiz_results}
                    WHERE userid = ? AND quizid = ? AND score >= 70
                    ORDER BY timecompleted DESC LIMIT 1
                ", [$USER->id, $quiz->id]);
                
                if ($quiz_result) {
                    // Examen ya aprobado, avanzar al siguiente paso automáticamente
                    $potential_next = $DB->get_record_sql("
                        SELECT s.*
                        FROM {learningpath_steps} s
                        LEFT JOIN {learningstylesurvey_resources} r ON s.resourceid = r.id AND s.istest = 0
                        LEFT JOIN {learningstylesurvey_path_temas} pt ON pt.temaid = r.tema AND pt.pathid = s.pathid
                        WHERE s.pathid = ? AND s.stepnumber > ?
                        AND (
                            (s.istest = 1) OR 
                            (s.istest = 0 AND r.style = ? AND r.courseid = ? AND (pt.isrefuerzo = 0 OR pt.isrefuerzo IS NULL))
                        )
                        ORDER BY s.stepnumber ASC
                        LIMIT 1
                    ", [$pathid, $step->stepnumber, $style, $courseid]);
                    
                    if ($potential_next) {
                        // Actualizar progreso al siguiente paso
                        $progress->current_stepid = $potential_next->id;
                        $progress->timemodified = time();
                        $DB->update_record('learningstylesurvey_user_progress', $progress);
                        
                        // Usar el siguiente paso como paso actual
                        $step = $potential_next;
                    } else {
                        $step = null; // No hay más pasos, ir a finalización
                    }
                } else {
                    // MOSTRAR EXAMEN AUTOMÁTICAMENTE - Sin botón, redirección directa
                    echo "<div class='alert alert-info' style='text-align:center; padding:25px;'>";
                    echo "<h4>📚 ¡Has completado todos los recursos del tema!</h4>";
                    echo "<p>Es momento de evaluar lo aprendido.</p>";
                    echo "<h5>⏳ Redirigiendo al examen: <strong>" . format_string($quiz->name) . "</strong></h5>";
                    echo "<div class='progress' style='height:15px; margin:20px 0;'>";
                    echo "<div class='progress-bar progress-bar-striped progress-bar-animated' style='width:100%; background:#007bff;'></div>";
                    echo "</div>";
                    echo "</div>";
                    
                    $quizurl = new moodle_url('/mod/learningstylesurvey/quiz/responder_quiz.php', [
                        'id' => $quiz->id,
                        'courseid' => $courseid,
                        'embedded' => 1,
                        'cmid' => $cmid
                    ]);

                    // Usar redirección PHP directa - más confiable que JavaScript
                    redirect($quizurl);
                    
                    echo "</div>";
                    echo $OUTPUT->footer();
                    exit;
                }
            }
        }
        
        // Si llegamos aquí y aún tenemos un step, debe ser un recurso
        if ($step && !$step->istest) {
            $resource = $DB->get_record('learningstylesurvey_resources', ['id' => $step->resourceid]);
        } else {
            $resource = null;
        }

        if ($resource) {
            // Mostrar título del tema actual
            $tema_actual = $DB->get_record('learningstylesurvey_temas', ['id' => $resource->tema]);
            if ($tema_actual) {
                echo "<div style='background:#e7f3ff; border-left:4px solid #007bff; padding:15px; margin-bottom:20px; border-radius:5px;'>";
                echo "<h3 style='margin:0; color:#0056b3;'>📚 " . format_string($tema_actual->tema) . "</h3>";
                echo "</div>";
            }
            
            mostrar_recurso($resource);
            
            // BOTÓN MANUAL DE CONTINUAR (sin auto-avance por tiempo)
            echo "<form method='POST' action='siguiente.php'>
                    <input type='hidden' name='courseid' value='{$courseid}'>
                    <input type='hidden' name='pathid' value='{$pathid}'>
                    <input type='hidden' name='stepid' value='{$step->id}'>
                    <input type='hidden' name='cmid' value='{$cmid}'>
                    <button type='submit' class='btn btn-success' style='margin-top:15px;'>Continuar</button>
                  </form>";
        }
    } else {
        // No hay más recursos - verificar si hay examen pendiente y mostrarlo automáticamente
        $pending_quiz = $DB->get_record_sql("
            SELECT s.*
            FROM {learningpath_steps} s
            WHERE s.pathid = ? AND s.istest = 1
            ORDER BY s.stepnumber ASC
            LIMIT 1
        ", [$pathid]);
        
        if ($pending_quiz) {
            // Verificar si este examen ya fue aprobado
            $quiz_result = $DB->get_record_sql("
                SELECT qr.* FROM {learningstylesurvey_quiz_results} qr
                WHERE qr.userid = ? AND qr.quizid = ? AND qr.score >= 70
                ORDER BY qr.timecompleted DESC LIMIT 1
            ", [$USER->id, $pending_quiz->resourceid]);
            
            if (!$quiz_result) {
                // Esta sección ya no se debería alcanzar - la lógica se movió a siguiente.php
                echo "<div class='alert alert-info' style='text-align:center; padding:25px;'>";
                echo "<h4>📚 Ruta en progreso</h4>";
                echo "<p>Presiona 'Continuar' para avanzar en la ruta.</p>";
                echo "</div>";
                
            } else {
                echo "<div class='alert alert-success' style='text-align:center; padding:25px;'>";
                echo "<h4>🎉 ¡Felicitaciones!</h4>";
                echo "<p>Has completado exitosamente toda la ruta de aprendizaje.</p>";
                echo "<p>✅ Examen aprobado con {$quiz_result->score}%</p>";
                if ($cmid) {
                    $menuurl = new moodle_url('/mod/learningstylesurvey/view.php', ['id'=>$cmid]);
                    echo "<a href='{$menuurl}' class='btn btn-primary btn-lg' style='margin-top:10px;'>Regresar al menú principal</a>";
                }
                echo "</div>";
            }
        } else {

            echo "<div class='alert alert-info' style='text-align:center;'>";
            echo "<h4>📖 Ruta en progreso</h4>";
            echo "<p>No hay más recursos disponibles en este momento para tu estilo de aprendizaje.</p>";
            echo "</div>";
        }
    }
}

// ELIMINAMOS la sección del botón manual del examen - ahora todo es automático

// Botón regresar al menú
if ($cmid) {
    $menuurl = new moodle_url('/mod/learningstylesurvey/view.php', ['id'=>$cmid]);
    echo "<div style='margin-top:30px;'><a href='{$menuurl}' class='btn btn-secondary'>Regresar al menú</a></div>";
}

echo "</div>";
echo $OUTPUT->footer();
?>
