<?php
require_once('../../config.php');
require_login();

global $DB, $OUTPUT, $PAGE, $USER;

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('learningstylesurvey', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
$courseid = $course->id;

$PAGE->set_context($context);
$PAGE->requires->css(new moodle_url('/mod/learningstylesurvey/style/surveyform.css'));
$PAGE->set_cm($cm);
$PAGE->set_url('/mod/learningstylesurvey/surveyform.php', ['id' => $id]);
$PAGE->set_title(get_string('pluginname', 'learningstylesurvey'));
$PAGE->set_heading(format_string($course->fullname));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $now = time();

    // Borrar respuestas anteriores del usuario actual
    $DB->delete_records('learningstylesurvey_responses', [
        'userid' => $USER->id,
        'surveyid' => $cm->instance
    ]);

    // Contar puntajes por estilo a partir de los answer IDs enviados
    $stylecounts = [
        'activo' => 0, 'reflexivo' => 0,
        'sensorial' => 0, 'intuitivo' => 0,
        'visual' => 0, 'verbal' => 0,
        'secuencial' => 0, 'global' => 0
    ];

    foreach ($_POST as $key => $value) {
        if (strpos($key, 'ilsq_') === 0) {
            $answerid = intval($value);
            // Buscar el estilo de la respuesta seleccionada
            $answer = $DB->get_record('learningstylesurvey_ilsanswers', ['id' => $answerid]);
            if ($answer && isset($stylecounts[$answer->style])) {
                $stylecounts[$answer->style]++;
            }
        }
    }

    // Ordenar por puntaje descendente y guardar top 3
    arsort($stylecounts);
    $ranking = 0;
    foreach ($stylecounts as $style => $score) {
        $ranking++;
        if ($ranking > 3) {
            break;
        }
        $record = new stdClass();
        $record->surveyid = $cm->instance;
        $record->userid = $USER->id;
        $record->style = $style;
        $record->score = $score;
        $record->ranking = $ranking;
        $record->timecreated = $now;
        $DB->insert_record('learningstylesurvey_responses', $record);
    }

    // Actualizar tablas de resultados y userstyles (mantener compatibilidad)
    $strongest = array_key_first($stylecounts);

    $DB->delete_records('learningstylesurvey_results', ['userid' => $USER->id]);
    $DB->delete_records('learningstylesurvey_userstyles', ['userid' => $USER->id]);

    $result = new stdClass();
    $result->userid = $USER->id;
    $result->strongeststyle = $strongest;
    $result->timecreated = $now;
    $DB->insert_record('learningstylesurvey_results', $result);

    $userstyle = new stdClass();
    $userstyle->userid = $USER->id;
    $userstyle->style = $strongest;
    $userstyle->timemodified = $now;
    $DB->insert_record('learningstylesurvey_userstyles', $userstyle);

    redirect(new moodle_url('/mod/learningstylesurvey/view.php', ['id' => $id]));
    exit;
}

// Cargar preguntas y respuestas desde la base de datos
$dbquestions = $DB->get_records('learningstylesurvey_ilsquestions', null, 'questionnumber ASC');
$questionids = array_keys($dbquestions);

// Mezclar aleatoriamente (semilla consistente por usuario/survey)
$seed = crc32($USER->id . '_' . $cm->instance . '_learningstylesurvey');
mt_srand($seed);
$shuffled = $questionids;
for ($i = count($shuffled) - 1; $i > 0; $i--) {
    $j = mt_rand(0, $i);
    [$shuffled[$i], $shuffled[$j]] = [$shuffled[$j], $shuffled[$i]];
}

// Pre-cargar todas las respuestas indexadas por questionid
$allanswers = $DB->get_records('learningstylesurvey_ilsanswers');
$answersByQuestion = [];
foreach ($allanswers as $ans) {
    $answersByQuestion[$ans->questionid][] = $ans;
}

// Dividir en 4 páginas de 11 preguntas
$pages = array_chunk($shuffled, 11);
$totalPages = count($pages);

echo $OUTPUT->header();
?>

<div class="ils-survey-container">
    <!-- Header -->
    <div class="ils-survey-header">
        <h2>📋 Encuesta de Estilos de Aprendizaje</h2>
        <p>Responde cada pregunta seleccionando la opción que mejor te describa.</p>
    </div>

    <!-- Progress Bar -->
    <div class="ils-progress-wrapper">
        <div class="ils-progress-bar">
            <div class="ils-progress-fill" id="ilsProgressFill" style="width: <?php echo round(100 / $totalPages); ?>%"></div>
        </div>
        <span class="ils-progress-label" id="ilsProgressLabel">Página 1 / <?php echo $totalPages; ?></span>
    </div>

    <!-- Stepper Dots -->
    <div class="ils-stepper">
        <?php for ($s = 0; $s < $totalPages; $s++): ?>
            <div class="ils-step-dot <?php echo $s === 0 ? 'active' : ''; ?>" data-step="<?php echo $s; ?>"></div>
        <?php endfor; ?>
    </div>

    <form method="post" id="ilsSurveyForm">
        <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">

        <?php
        $globalIdx = 0;
        foreach ($pages as $pageIndex => $pageQuestionIds): ?>
            <div class="ils-section <?php echo $pageIndex === 0 ? 'active' : ''; ?>" data-section="<?php echo $pageIndex; ?>">
                <!-- Questions -->
                <?php foreach ($pageQuestionIds as $qId):
                    $globalIdx++;
                    $q = $dbquestions[$qId];
                    $answers = isset($answersByQuestion[$qId]) ? $answersByQuestion[$qId] : [];
                    $radioName = "ilsq_{$qId}";
                ?>
                    <div class="ils-question-card" data-question="<?php echo $qId; ?>">
                        <div class="ils-question-label">
                            <span class="ils-question-number"><?php echo $globalIdx; ?></span>
                            <?php echo s($q->questiontext); ?>
                        </div>
                        <div class="ils-options">
                            <?php foreach ($answers as $idx => $ans): ?>
                                <div class="ils-option">
                                    <input type="radio" name="<?php echo $radioName; ?>" id="ans_<?php echo $ans->id; ?>" value="<?php echo $ans->id; ?>" <?php echo $idx === 0 ? 'required' : ''; ?>>
                                    <label for="ans_<?php echo $ans->id; ?>" class="ils-option-label">
                                        <span class="ils-radio-dot"></span>
                                        <?php echo s($ans->answertext); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <!-- Validation Warning -->
        <div class="ils-warning" id="ilsWarning">
            ⚠️ <span>Por favor, responde todas las preguntas de esta página antes de continuar.</span>
        </div>

        <!-- Navigation -->
        <div class="ils-nav">
            <button type="button" class="ils-btn ils-btn-secondary" id="ilsBtnPrev" disabled>
                ← Anterior
            </button>
            <button type="button" class="ils-btn ils-btn-primary" id="ilsBtnNext">
                Siguiente →
            </button>
            <button type="submit" class="ils-btn ils-btn-submit" id="ilsBtnSubmit" style="display:none;">
                Enviar respuestas
            </button>
        </div>
    </form>

    <!-- Back Link -->
    <div class="ils-back-link">
        <?php echo html_writer::link(
            new moodle_url('/mod/learningstylesurvey/view.php', ['id' => $id]),
            '← Regresar al menú',
            ['class' => 'ils-back']
        ); ?>
    </div>
</div>

<script>
(function() {
    const sections = document.querySelectorAll('.ils-section');
    const dots     = document.querySelectorAll('.ils-step-dot');
    const btnPrev  = document.getElementById('ilsBtnPrev');
    const btnNext  = document.getElementById('ilsBtnNext');
    const btnSubmit= document.getElementById('ilsBtnSubmit');
    const progressFill  = document.getElementById('ilsProgressFill');
    const progressLabel = document.getElementById('ilsProgressLabel');
    const warning  = document.getElementById('ilsWarning');
    const total    = sections.length;
    let current    = 0;

    function showSection(index) {
        sections.forEach((s, i) => {
            s.classList.toggle('active', i === index);
        });
        dots.forEach((d, i) => {
            d.classList.remove('active', 'completed');
            if (i === index) d.classList.add('active');
            else if (i < index) d.classList.add('completed');
        });
        btnPrev.disabled = (index === 0);
        btnNext.style.display = (index === total - 1) ? 'none' : '';
        btnSubmit.style.display = (index === total - 1) ? '' : 'none';
        const pct = Math.round(((index + 1) / total) * 100);
        progressFill.style.width = pct + '%';
        progressLabel.textContent = 'Página ' + (index + 1) + ' / ' + total;
        warning.classList.remove('show');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function isSectionComplete(index) {
        const sec = sections[index];
        const radios = sec.querySelectorAll('input[type="radio"]');
        const names = new Set();
        radios.forEach(r => names.add(r.name));
        for (const name of names) {
            if (!sec.querySelector('input[name="' + name + '"]:checked')) return false;
        }
        return true;
    }

    btnNext.addEventListener('click', function() {
        if (!isSectionComplete(current)) {
            warning.classList.add('show');
            return;
        }
        if (current < total - 1) {
            current++;
            showSection(current);
        }
    });

    btnPrev.addEventListener('click', function() {
        if (current > 0) {
            current--;
            showSection(current);
        }
    });

    btnSubmit.addEventListener('click', function(e) {
        if (!isSectionComplete(current)) {
            e.preventDefault();
            warning.classList.add('show');
        }
    });

    dots.forEach(dot => {
        dot.addEventListener('click', function() {
            const target = parseInt(this.dataset.step);
            if (target < current) {
                current = target;
                showSection(current);
            } else if (target > current && isSectionComplete(current)) {
                current = target;
                showSection(current);
            } else if (target > current) {
                warning.classList.add('show');
            }
        });
    });

    // Mark cards as answered when radio is selected
    document.querySelectorAll('.ils-option input[type="radio"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const card = this.closest('.ils-question-card');
            card.classList.add('answered');
            warning.classList.remove('show');
        });
    });

    showSection(0);
})();
</script>

<?php
echo $OUTPUT->footer();
