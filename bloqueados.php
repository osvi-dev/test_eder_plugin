<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/mod/learningstylesurvey/locallib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('learningstylesurvey', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('moodle/course:update', $context);

$PAGE->set_cm($cm, $course);
$PAGE->set_url('/mod/learningstylesurvey/bloqueados.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title(format_string("Estudiantes Bloqueados"));
$PAGE->set_heading(format_string($course->fullname));

// Cargar CSS
$PAGE->requires->css(new moodle_url('/mod/learningstylesurvey/style/bloqueados.css'));

echo $OUTPUT->header();

// ── Función: contar reprobaciones consecutivas desde el más reciente ──
function bloq_count_consecutive_failures($DB, $userid, $quizid, $courseid) {
    $all_results = $DB->get_records_sql("
        SELECT id, score, timecompleted FROM {learningstylesurvey_quiz_results}
        WHERE userid = ? AND quizid = ? AND courseid = ?
        ORDER BY timecompleted DESC
    ", [$userid, $quizid, $courseid]);

    $consecutive = 0;
    foreach ($all_results as $r) {
        if ($r->score < 70) {
            $consecutive++;
        } else {
            break;
        }
    }
    return $consecutive;
}

// ── Función: verificar si hay desbloqueo posterior a la última reprobación ──
function bloq_has_active_unblock($DB, $userid, $quizid, $courseid) {
    // Obtener timestamp de la última reprobación
    $last_fail = $DB->get_record_sql("
        SELECT MAX(timecompleted) as lastfail FROM {learningstylesurvey_quiz_results}
        WHERE userid = ? AND quizid = ? AND courseid = ? AND score < 70
    ", [$userid, $quizid, $courseid]);

    if (!$last_fail || !$last_fail->lastfail) {
        return false;
    }

    // Verificar si hay un desbloqueo posterior
    $unblock = $DB->get_record_sql("
        SELECT id FROM {learningstylesurvey_unblocks}
        WHERE userid = ? AND quizid = ? AND courseid = ? AND timecreated >= ?
        ORDER BY timecreated DESC LIMIT 1
    ", [$userid, $quizid, $courseid, $last_fail->lastfail]);

    return !empty($unblock);
}

// ═══════════════════════════════════════════════
// OBTENER TODOS LOS ESTUDIANTES BLOQUEADOS DEL CURSO
// ═══════════════════════════════════════════════

// Obtener todos los pares (userid, quizid) con resultados en este curso
$user_quiz_pairs = $DB->get_records_sql("
    SELECT DISTINCT userid, quizid
    FROM {learningstylesurvey_quiz_results}
    WHERE courseid = ?
", [$course->id]);

$blocked_students = [];
foreach ($user_quiz_pairs as $pair) {
    $consecutive = bloq_count_consecutive_failures($DB, $pair->userid, $pair->quizid, $course->id);
    if ($consecutive >= 3) {
        // Verificar que no tenga un desbloqueo activo
        if (!bloq_has_active_unblock($DB, $pair->userid, $pair->quizid, $course->id)) {
            // Obtener datos del estudiante
            $user = $DB->get_record('user', ['id' => $pair->userid], 'id, firstname, lastname, email');
            $quiz = $DB->get_record('learningstylesurvey_quizzes', ['id' => $pair->quizid], 'id, name');

            // Obtener último resultado
            $last_result = $DB->get_record_sql("
                SELECT score, timecompleted FROM {learningstylesurvey_quiz_results}
                WHERE userid = ? AND quizid = ? AND courseid = ?
                ORDER BY timecompleted DESC LIMIT 1
            ", [$pair->userid, $pair->quizid, $course->id]);

            if ($user && $quiz) {
                $blocked_students[] = (object)[
                    'userid' => $user->id,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'email' => $user->email,
                    'quizid' => $quiz->id,
                    'quizname' => $quiz->name,
                    'consecutive_failures' => $consecutive,
                    'last_score' => $last_result ? $last_result->score : 0,
                    'last_attempt' => $last_result ? $last_result->timecompleted : 0
                ];
            }
        }
    }
}

// Obtener historial de desbloqueos
$unblock_history = $DB->get_records_sql("
    SELECT ub.*, u.firstname as student_first, u.lastname as student_last,
           ub2.firstname as prof_first, ub2.lastname as prof_last,
           q.name as quizname
    FROM {learningstylesurvey_unblocks} ub
    JOIN {user} u ON u.id = ub.userid
    JOIN {user} ub2 ON ub2.id = ub.unblockedby
    JOIN {learningstylesurvey_quizzes} q ON q.id = ub.quizid
    WHERE ub.courseid = ?
    ORDER BY ub.timecreated DESC
    LIMIT 20
", [$course->id]);

$total_blocked = count($blocked_students);
$total_unblocked = $DB->count_records('learningstylesurvey_unblocks', ['courseid' => $course->id]);

// ═══════════════════════════════════════════════
// RENDERIZAR PÁGINA
// ═══════════════════════════════════════════════

echo "<div class='bloq-page'>";

// ── Header ──
echo "<div class='bloq-header'>";
echo "  <h2>🔒 Estudiantes Bloqueados</h2>";
echo "  <p>Gestión de estudiantes que han agotado sus 3 intentos en evaluaciones</p>";
echo "</div>";

// ── Mensaje de éxito ──
$success = optional_param('success', 0, PARAM_INT);
if ($success) {
    echo "<div class='bloq-alert bloq-alert--success'>";
    echo "  <span>✅</span> Estudiante desbloqueado exitosamente. Ahora tiene 3 nuevos intentos.";
    echo "</div>";
}

// ── Summary cards ──
echo "<div class='bloq-summary'>";

echo "<div class='bloq-summary-card'>";
echo "  <span class='bloq-summary-card__icon'>⛔</span>";
echo "  <div class='bloq-summary-card__number bloq-summary-card__number--danger'>{$total_blocked}</div>";
echo "  <div class='bloq-summary-card__label'>Bloqueados actualmente</div>";
echo "</div>";

echo "<div class='bloq-summary-card'>";
echo "  <span class='bloq-summary-card__icon'>✅</span>";
echo "  <div class='bloq-summary-card__number bloq-summary-card__number--success'>{$total_unblocked}</div>";
echo "  <div class='bloq-summary-card__label'>Desbloqueos realizados</div>";
echo "</div>";

$total_enrolled = count(get_enrolled_users($context, '', 0, 'u.id'));
echo "<div class='bloq-summary-card'>";
echo "  <span class='bloq-summary-card__icon'>👥</span>";
echo "  <div class='bloq-summary-card__number bloq-summary-card__number--info'>{$total_enrolled}</div>";
echo "  <div class='bloq-summary-card__label'>Estudiantes en el curso</div>";
echo "</div>";

echo "</div>"; // .bloq-summary

// ═══════════════════════════════════════════════
// TABLA DE BLOQUEADOS
// ═══════════════════════════════════════════════
echo "<div class='bloq-card'>";
echo "  <h3 class='bloq-card__title'>";
echo "    <span class='bloq-card__icon bloq-card__icon--blocked'>⛔</span>";
echo "    Estudiantes bloqueados ({$total_blocked})";
echo "  </h3>";

if (!empty($blocked_students)) {
    echo "<table class='bloq-table'>";
    echo "  <thead><tr>";
    echo "    <th>Estudiante</th>";
    echo "    <th>Examen</th>";
    echo "    <th>Reprobaciones</th>";
    echo "    <th>Último Score</th>";
    echo "    <th>Último Intento</th>";
    echo "    <th>Acción</th>";
    echo "  </tr></thead>";
    echo "  <tbody>";

    foreach ($blocked_students as $student) {
        $fullname = s($student->firstname . ' ' . $student->lastname);
        $date = $student->last_attempt ? userdate($student->last_attempt, '%d/%m/%Y %H:%M') : '-';

        echo "<tr>";
        echo "  <td><strong>{$fullname}</strong><br><span class='bloq-date'>{$student->email}</span></td>";
        echo "  <td>" . format_string($student->quizname) . "</td>";
        echo "  <td><span class='bloq-failures'>{$student->consecutive_failures}</span></td>";
        echo "  <td><span class='bloq-score bloq-score--fail'>{$student->last_score}%</span></td>";
        echo "  <td><span class='bloq-date'>{$date}</span></td>";
        echo "  <td>";
        echo "    <button class='bloq-btn-unblock' onclick='openUnblockModal({$student->userid}, {$student->quizid}, \"{$fullname}\", \"" . s($student->quizname) . "\")'>🔓 Desbloquear</button>";
        echo "  </td>";
        echo "</tr>";
    }

    echo "  </tbody></table>";
} else {
    echo "<div class='bloq-empty'>";
    echo "  <span class='bloq-empty__icon'>🎉</span>";
    echo "  <div class='bloq-empty__text'>No hay estudiantes bloqueados</div>";
    echo "  <div class='bloq-empty__sub'>Todos los estudiantes pueden continuar con su ruta de aprendizaje</div>";
    echo "</div>";
}

echo "</div>"; // .bloq-card

// ═══════════════════════════════════════════════
// HISTORIAL DE DESBLOQUEOS
// ═══════════════════════════════════════════════
echo "<div class='bloq-card'>";
echo "  <h3 class='bloq-card__title'>";
echo "    <span class='bloq-card__icon bloq-card__icon--history'>📋</span>";
echo "    Historial de desbloqueos";
echo "  </h3>";

if (!empty($unblock_history)) {
    echo "<table class='bloq-table'>";
    echo "  <thead><tr>";
    echo "    <th>Estudiante</th>";
    echo "    <th>Examen</th>";
    echo "    <th>Desbloqueado por</th>";
    echo "    <th>Notas</th>";
    echo "    <th>Fecha</th>";
    echo "  </tr></thead>";
    echo "  <tbody>";

    foreach ($unblock_history as $ub) {
        $student_name = s($ub->student_first . ' ' . $ub->student_last);
        $prof_name = s($ub->prof_first . ' ' . $ub->prof_last);
        $date = userdate($ub->timecreated, '%d/%m/%Y %H:%M');
        $reason = !empty($ub->reason) ? s($ub->reason) : '<span class="bloq-reason--empty">Sin notas</span>';

        echo "<tr>";
        echo "  <td><strong>{$student_name}</strong></td>";
        echo "  <td>" . format_string($ub->quizname) . "</td>";
        echo "  <td><span class='bloq-badge bloq-badge--unblocked'>✅ {$prof_name}</span></td>";
        echo "  <td><span class='bloq-reason'>{$reason}</span></td>";
        echo "  <td><span class='bloq-date'>{$date}</span></td>";
        echo "</tr>";
    }

    echo "  </tbody></table>";
} else {
    echo "<div class='bloq-empty'>";
    echo "  <span class='bloq-empty__icon'>📭</span>";
    echo "  <div class='bloq-empty__text'>Sin historial de desbloqueos</div>";
    echo "  <div class='bloq-empty__sub'>Aún no se ha desbloqueado a ningún estudiante</div>";
    echo "</div>";
}

echo "</div>"; // .bloq-card

// ── Modal de desbloqueo ──
$sesskey = sesskey();
echo "<div class='bloq-modal-overlay' id='unblockModal'>";
echo "  <div class='bloq-modal'>";
echo "    <h3>🔓 Desbloquear estudiante</h3>";
echo "    <p>Estás a punto de desbloquear a <strong id='modalStudentName'></strong> en el examen <strong id='modalQuizName'></strong>. El estudiante tendrá 3 nuevos intentos.</p>";
echo "    <form method='post' action='" . (new moodle_url('/mod/learningstylesurvey/desbloquear.php'))->out() . "'>";
echo "      <input type='hidden' name='id' value='{$id}'>";
echo "      <input type='hidden' name='courseid' value='{$course->id}'>";
echo "      <input type='hidden' name='sesskey' value='{$sesskey}'>";
echo "      <input type='hidden' name='userid' id='modalUserId' value=''>";
echo "      <input type='hidden' name='quizid' id='modalQuizId' value=''>";
echo "      <textarea name='reason' placeholder='Notas opcionales (ej: Acreditó examen presencial el 13/03/2026)' rows='3'></textarea>";
echo "      <div class='bloq-modal__actions'>";
echo "        <button type='button' class='bloq-modal__btn bloq-modal__btn--cancel' onclick='closeUnblockModal()'>Cancelar</button>";
echo "        <button type='submit' class='bloq-modal__btn bloq-modal__btn--confirm'>✅ Confirmar desbloqueo</button>";
echo "      </div>";
echo "    </form>";
echo "  </div>";
echo "</div>";

// ── Back button ──
echo "<div class='bloq-back'>";
$back_url = new moodle_url('/mod/learningstylesurvey/view.php', ['id' => $id]);
echo "  <a href='" . $back_url->out() . "'>← Volver al menú principal</a>";
echo "</div>";

echo "</div>"; // .bloq-page

// ── JavaScript ──
echo "<script>
function openUnblockModal(userid, quizid, studentName, quizName) {
    document.getElementById('modalUserId').value = userid;
    document.getElementById('modalQuizId').value = quizid;
    document.getElementById('modalStudentName').textContent = studentName;
    document.getElementById('modalQuizName').textContent = quizName;
    document.getElementById('unblockModal').classList.add('active');
}

function closeUnblockModal() {
    document.getElementById('unblockModal').classList.remove('active');
}

// Cerrar modal al hacer clic fuera
document.getElementById('unblockModal').addEventListener('click', function(e) {
    if (e.target === this) closeUnblockModal();
});

// Cerrar con Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeUnblockModal();
});
</script>";

echo $OUTPUT->footer();
