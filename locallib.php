<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Obtiene todos los IDs de instancias del plugin en un curso.
 * Se usa para compartir la encuesta entre todas las actividades del plugin en el mismo curso.
 *
 * @param object $course  Objeto del curso
 * @return array  Array de IDs de instancias (surveyids)
 */
function learningstylesurvey_get_all_surveyids_in_course($course) {
    $allsurveyids = [];
    $modinfo = get_fast_modinfo($course);
    foreach ($modinfo->get_instances_of('learningstylesurvey') as $cminfo) {
        $allsurveyids[] = $cminfo->instance;
    }
    return $allsurveyids;
}

/**
 * Verifica si un usuario ya contestó la encuesta en cualquier instancia del curso.
 *
 * @param int    $userid       ID del usuario
 * @param object $course       Objeto del curso
 * @return bool  true si ya contestó
 */
function learningstylesurvey_user_has_responded($userid, $course) {
    global $DB;
    $allsurveyids = learningstylesurvey_get_all_surveyids_in_course($course);
    if (empty($allsurveyids)) {
        return false;
    }
    list($insql, $inparams) = $DB->get_in_or_equal($allsurveyids, SQL_PARAMS_NAMED, 'sid');
    $inparams['userid'] = $userid;
    return $DB->record_exists_select('learningstylesurvey_responses', "userid = :userid AND surveyid $insql", $inparams);
}

function learningstylesurvey_get_responses($surveyid) {
    global $DB;
    return $DB->get_records('learningstylesurvey_responses', ['surveyid' => $surveyid]);
}

/**
 * Rotación circular del estilo de aprendizaje del usuario.
 *
 * Obtiene el top 3 estilos del usuario desde learningstylesurvey_responses,
 * encuentra su estilo actual en learningstylesurvey_userstyles,
 * y avanza al siguiente estilo de forma circular (1→2→3→1→...).
 *
 * @param int $userid  ID del usuario
 * @return string|false  El nuevo estilo asignado, o false si no se pudo rotar
 */
function rotate_user_learning_style($userid) {
    global $DB;

    // 1. Obtener los 3 estilos mejor rankeados del usuario (ranking 1, 2, 3)
    $top3 = $DB->get_records_sql("
        SELECT style, ranking
        FROM {learningstylesurvey_responses}
        WHERE userid = ? AND ranking >= 1 AND ranking <= 3
        ORDER BY ranking ASC
    ", [$userid]);

    if (!$top3 || count($top3) < 2) {
        // No hay suficientes estilos para rotar
        return false;
    }

    // Convertir a array indexado de estilos en orden de ranking
    $styles = [];
    foreach ($top3 as $row) {
        $styles[] = $row->style;
    }

    // 2. Obtener el estilo actual del usuario
    $current = $DB->get_record_sql("
        SELECT id, style
        FROM {learningstylesurvey_userstyles}
        WHERE userid = ?
        ORDER BY timecreated DESC
        LIMIT 1
    ", [$userid]);

    if (!$current) {
        return false;
    }

    $current_style = $current->style;

    // 3. Encontrar la posición actual en el top 3 y avanzar circularmente
    $current_index = array_search($current_style, $styles);

    if ($current_index === false) {
        // El estilo actual no está en el top 3 (caso raro), empezar desde el primero
        $next_index = 0;
    } else {
        // Avanzar al siguiente de forma circular
        $next_index = ($current_index + 1) % count($styles);
    }

    $new_style = $styles[$next_index];
    $new_rank = $next_index + 1; // ranking 1-based

    // 4. Actualizar la tabla userstyles con el nuevo estilo
    $current->style = $new_style;
    $current->timecreated = time();
    $DB->update_record('learningstylesurvey_userstyles', $current);

    return ['style' => $new_style, 'rank' => $new_rank, 'old_style' => $current_style];
}

/**
 * Registra un evento en la tabla de métricas learningstylesurvey_style_log.
 *
 * @param string $event_type  'rotation', 'exam_pass', o 'exam_fail'
 * @param array  $data        Datos del evento (userid, courseid, quizid, pathid, old_style, new_style, etc.)
 */
function log_style_event($event_type, $data) {
    global $DB;

    $record = new stdClass();
    $record->userid = $data['userid'];
    $record->courseid = $data['courseid'];
    $record->quizid = isset($data['quizid']) ? $data['quizid'] : null;
    $record->pathid = isset($data['pathid']) ? $data['pathid'] : null;
    $record->event_type = $event_type;
    $record->old_style = isset($data['old_style']) ? $data['old_style'] : null;
    $record->new_style = isset($data['new_style']) ? $data['new_style'] : null;
    $record->style_rank = isset($data['style_rank']) ? $data['style_rank'] : null;
    $record->exam_score = isset($data['exam_score']) ? $data['exam_score'] : null;
    $record->attempt_number = isset($data['attempt_number']) ? $data['attempt_number'] : null;
    $record->consecutive_failures = isset($data['consecutive_failures']) ? $data['consecutive_failures'] : null;
    $record->timecreated = time();

    $DB->insert_record('learningstylesurvey_style_log', $record);
}