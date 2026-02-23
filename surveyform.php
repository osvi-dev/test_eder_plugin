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

    // Borrar respuestas anteriores solo del usuario actual
    $DB->delete_records('learningstylesurvey_responses', [
        'userid' => $USER->id, 
        'surveyid' => $cm->instance
    ]);

    // Guardar cada respuesta individual
    for ($i = 1; $i <= 44; $i++) {
        $key = 'ilsq' . $i;
        if (isset($_POST[$key])) {
            $response = intval($_POST[$key]);

            $record = new stdClass();
            $record->courseid = $courseid;
            $record->userid = $USER->id;
            $record->questionid = $i;
            $record->response = $response;
            $record->surveyid = $cm->instance;
            $record->timecreated = $now;

            $DB->insert_record('learningstylesurvey_responses', $record);
        }
    }

    // Calcular conteo de respuestas por estilo
    $stylecounts = [
        'Activo' => 0, 'Reflexivo' => 0,
        'Sensorial' => 0, 'Intuitivo' => 0,
        'Visual' => 0, 'Verbal' => 0,
        'Secuencial' => 0, 'Global' => 0
    ];

    $stylemap = [
        1 => ['Activo','Reflexivo'], 2 => ['Sensorial','Intuitivo'], 3 => ['Visual','Verbal'], 4 => ['Secuencial','Global'],
        5 => ['Activo','Reflexivo'], 6 => ['Sensorial','Intuitivo'], 7 => ['Visual','Verbal'], 8 => ['Secuencial','Global'],
        9 => ['Activo','Reflexivo'],10 => ['Sensorial','Intuitivo'],11 => ['Visual','Verbal'],12 => ['Secuencial','Global'],
        13=> ['Activo','Reflexivo'],14 => ['Sensorial','Intuitivo'],15 => ['Visual','Verbal'],16 => ['Secuencial','Global'],
        17=> ['Activo','Reflexivo'],18 => ['Sensorial','Intuitivo'],19 => ['Visual','Verbal'],20 => ['Secuencial','Global'],
        21=> ['Activo','Reflexivo'],22=> ['Sensorial','Intuitivo'],23=> ['Visual','Verbal'],24=> ['Secuencial','Global'],
        25=> ['Activo','Reflexivo'],26=> ['Sensorial','Intuitivo'],27=> ['Visual','Verbal'],28=> ['Secuencial','Global'],
        29=> ['Activo','Reflexivo'],30=> ['Sensorial','Intuitivo'],31=> ['Visual','Verbal'],32=> ['Secuencial','Global'],
        33=> ['Activo','Reflexivo'],34=> ['Sensorial','Intuitivo'],35=> ['Visual','Verbal'],36=> ['Secuencial','Global'],
        37=> ['Activo','Reflexivo'],38=> ['Sensorial','Intuitivo'],39=> ['Visual','Verbal'],40=> ['Secuencial','Global'],
        41=> ['Activo','Reflexivo'],42=> ['Sensorial','Intuitivo'],43=> ['Visual','Verbal'],44=> ['Secuencial','Global']
    ];

    foreach ($_POST as $key => $value) {
        if (strpos($key, 'ilsq') === 0) {
            $qid = intval(substr($key, 4));
            $answer = intval($value);
            if (isset($stylemap[$qid])) {
                $stylecounts[$stylemap[$qid][$answer]]++;
            }
        }
    }

    arsort($stylecounts);
    $strongest = array_key_first($stylecounts);

    // Borrar resultados anteriores solo del usuario actual
    $DB->delete_records('learningstylesurvey_results', ['userid' => $USER->id]);
    $DB->delete_records('learningstylesurvey_userstyles', ['userid' => $USER->id]);

    // Insertar nuevo resultado
    $result = new stdClass();
    $result->userid = $USER->id;
    $result->strongeststyle = strtolower($strongest);
    $result->timecreated = $now;
    $DB->insert_record('learningstylesurvey_results', $result);

    // Guardar estilo del usuario para filtrado futuro
    $userstyle = new stdClass();
    $userstyle->userid = $USER->id;
    $userstyle->style = strtolower($strongest);
    $userstyle->timemodified = $now;
    $DB->insert_record('learningstylesurvey_userstyles', $userstyle);

    redirect(new moodle_url('/mod/learningstylesurvey/view.php', ['id' => $id]));
    exit;
}

// Definir las 4 dimensiones y sus preguntas
$dimensions = [
    [
        'title' => 'Activo / Reflexivo',
        'subtitle' => '¿Cómo prefieres procesar la información?',
        'icon' => '⚡',
        'color' => '#6366f1',
        'questions' => [1, 5, 9, 13, 17, 21, 25, 29, 33, 37, 41]
    ],
    [
        'title' => 'Sensorial / Intuitivo',
        'subtitle' => '¿Qué tipo de información prefieres percibir?',
        'icon' => '🧠',
        'color' => '#8b5cf6',
        'questions' => [2, 6, 10, 14, 18, 22, 26, 30, 34, 38, 42]
    ],
    [
        'title' => 'Visual / Verbal',
        'subtitle' => '¿A través de qué canal sensorial percibes mejor?',
        'icon' => '👁️',
        'color' => '#06b6d4',
        'questions' => [3, 7, 11, 15, 19, 23, 27, 31, 35, 39, 43]
    ],
    [
        'title' => 'Secuencial / Global',
        'subtitle' => '¿Cómo organizas la información?',
        'icon' => '🔗',
        'color' => '#10b981',
        'questions' => [4, 8, 12, 16, 20, 24, 28, 32, 36, 40, 44]
    ]
];

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
            <div class="ils-progress-fill" id="ilsProgressFill" style="width: 25%"></div>
        </div>
        <span class="ils-progress-label" id="ilsProgressLabel">Sección 1 / 4</span>
    </div>

    <!-- Stepper Dots -->
    <div class="ils-stepper">
        <?php for ($s = 0; $s < 4; $s++): ?>
            <div class="ils-step-dot <?php echo $s === 0 ? 'active' : ''; ?>" data-step="<?php echo $s; ?>"></div>
        <?php endfor; ?>
    </div>

    <form method="post" id="ilsSurveyForm">
        <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">

        <?php foreach ($dimensions as $dIndex => $dim): ?>
            <div class="ils-section <?php echo $dIndex === 0 ? 'active' : ''; ?>" data-section="<?php echo $dIndex; ?>">
                <!-- Section Header -->
                <div class="ils-section-header">
                    <div class="ils-section-icon" style="border: 2px solid <?php echo $dim['color']; ?>20;">
                        <?php echo $dim['icon']; ?>
                    </div>
                    <div class="ils-section-info">
                        <h3><?php echo $dim['title']; ?></h3>
                        <p><?php echo $dim['subtitle']; ?></p>
                    </div>
                    <span class="ils-section-counter"><?php echo count($dim['questions']); ?> preguntas</span>
                </div>

                <!-- Questions -->
                <?php foreach ($dim['questions'] as $qIdx => $qNum):
                    $qkey = "ilsq{$qNum}";
                    $a0key = "ilsq{$qNum}a0";
                    $a1key = "ilsq{$qNum}a1";
                ?>
                    <div class="ils-question-card" data-question="<?php echo $qNum; ?>">
                        <div class="ils-question-label">
                            <span class="ils-question-number"><?php echo ($qIdx + 1); ?></span>
                            <?php echo get_string($qkey, 'learningstylesurvey'); ?>
                        </div>
                        <div class="ils-options">
                            <div class="ils-option">
                                <input type="radio" name="<?php echo $qkey; ?>" id="<?php echo $qkey; ?>_a" value="0" required>
                                <label for="<?php echo $qkey; ?>_a" class="ils-option-label">
                                    <span class="ils-radio-dot"></span>
                                    <?php echo get_string($a0key, 'learningstylesurvey'); ?>
                                </label>
                            </div>
                            <div class="ils-option">
                                <input type="radio" name="<?php echo $qkey; ?>" id="<?php echo $qkey; ?>_b" value="1">
                                <label for="<?php echo $qkey; ?>_b" class="ils-option-label">
                                    <span class="ils-radio-dot"></span>
                                    <?php echo get_string($a1key, 'learningstylesurvey'); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <!-- Validation Warning -->
        <div class="ils-warning" id="ilsWarning">
            ⚠️ <span>Por favor, responde todas las preguntas de esta sección antes de continuar.</span>
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
                ✅ Enviar respuestas
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
        progressLabel.textContent = 'Sección ' + (index + 1) + ' / ' + total;
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
            // Allow navigating back freely, but forward only if current section is complete
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
