<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/mod/learningstylesurvey/locallib.php');

$id = required_param('id', PARAM_INT); // cmid
$userid_to_unblock = required_param('userid', PARAM_INT);
$quizid = required_param('quizid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$reason = optional_param('reason', '', PARAM_TEXT);

$cm = get_coursemodule_from_id('learningstylesurvey', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('moodle/course:update', $context);
require_sesskey();

// Insertar registro de desbloqueo
$record = new stdClass();
$record->userid = $userid_to_unblock;
$record->quizid = $quizid;
$record->courseid = $courseid;
$record->unblockedby = $USER->id;
$record->reason = $reason;
$record->timecreated = time();

$DB->insert_record('learningstylesurvey_unblocks', $record);

// Registrar evento en el log de métricas
log_style_event('unblock', [
    'userid' => $userid_to_unblock,
    'courseid' => $courseid,
    'quizid' => $quizid,
    'old_style' => null,
    'new_style' => null,
    'exam_score' => null,
    'attempt_number' => null,
    'consecutive_failures' => null
]);

// Redirigir de vuelta a bloqueados.php con mensaje de éxito
$redirecturl = new moodle_url('/mod/learningstylesurvey/bloqueados.php', [
    'id' => $id,
    'success' => 1
]);

redirect($redirecturl, 'Estudiante desbloqueado exitosamente.', null, \core\output\notification::NOTIFY_SUCCESS);
