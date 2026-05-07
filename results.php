<?php
require_once("../../config.php");
require_login();

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('learningstylesurvey', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$survey = $DB->get_record('learningstylesurvey', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_once(__DIR__ . '/locallib.php');
require_login($course, true, $cm);

// Obtener todos los IDs de instancias del plugin en este curso
$allsurveyids = learningstylesurvey_get_all_surveyids_in_course($course);

$PAGE->set_url('/mod/learningstylesurvey/results.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_cm($cm);
$PAGE->set_title(format_string($survey->name));
$PAGE->set_heading(format_string($course->fullname));

// Importamos el css
$PAGE->requires->css(new moodle_url('/mod/learningstylesurvey/style/results.css'));

echo $OUTPUT->header();

// Obtener lista de usuarios que han respondido la encuesta en CUALQUIER instancia del curso
if (!empty($allsurveyids)) {
    list($insql, $inparams) = $DB->get_in_or_equal($allsurveyids, SQL_PARAMS_NAMED, 'sid');
    $sqlusers = "SELECT DISTINCT u.id, u.firstname, u.lastname
                 FROM {user} u
                 JOIN {learningstylesurvey_responses} r ON r.userid = u.id
                 WHERE r.surveyid $insql
                 ORDER BY u.lastname, u.firstname";
    $respondents = $DB->get_records_sql($sqlusers, $inparams);
} else {
    $respondents = [];
}

// Obtener userid para mostrar resultados (por GET)
// Si el usuario actual ha respondido, mostrar sus resultados por defecto
// Si no (ej. profesor), mostrar vista general (userid=0)
$defaultuserid = array_key_exists($USER->id, $respondents) ? $USER->id : 0;
$selecteduserid = optional_param('userid', $defaultuserid, PARAM_INT);

// Verificar si el usuario seleccionado realmente respondió la encuesta, o es 'general' (0)
if ($selecteduserid != 0 && !array_key_exists($selecteduserid, $respondents)) {
    echo $OUTPUT->notification('El usuario seleccionado no ha respondido la encuesta.', 'notifywarning');
    $selecteduserid = 0; // Mostrar vista general como fallback
}

// Inicializar conteo de estilos (capitalizado para display)
$stylecounts = [
    'Activo' => 0, 'Reflexivo' => 0,
    'Sensorial' => 0, 'Intuitivo' => 0,
    'Visual' => 0, 'Verbal' => 0,
    'Secuencial' => 0, 'Global' => 0
];

// Mapeo de minúsculas (BD) a capitalizado (display)
$styleDisplay = [
    'activo' => 'Activo', 'reflexivo' => 'Reflexivo',
    'sensorial' => 'Sensorial', 'intuitivo' => 'Intuitivo',
    'visual' => 'Visual', 'verbal' => 'Verbal',
    'secuencial' => 'Secuencial', 'global' => 'Global'
];

if ($selecteduserid == 0) {
    // Vista general: agregar puntajes de todos los usuarios del curso
    list($insql2, $inparams2) = $DB->get_in_or_equal($allsurveyids, SQL_PARAMS_NAMED, 'sid');
    $allresponses = $DB->get_records_select('learningstylesurvey_responses', "surveyid $insql2", $inparams2);
    if (!$allresponses) {
        echo $OUTPUT->notification("No hay respuestas registradas para esta encuesta.", 'notifymessage');
        echo $OUTPUT->footer();
        exit;
    }
    foreach ($allresponses as $r) {
        $displayKey = isset($styleDisplay[$r->style]) ? $styleDisplay[$r->style] : ucfirst($r->style);
        if (isset($stylecounts[$displayKey])) {
            $stylecounts[$displayKey] += $r->score;
        }
    }
    $title = "Resultados generales";
    $titleIcon = "🌐";
} else {
    list($insql3, $inparams3) = $DB->get_in_or_equal($allsurveyids, SQL_PARAMS_NAMED, 'sid');
    $inparams3['userid'] = $selecteduserid;
    $responses = $DB->get_records_select('learningstylesurvey_responses',
        "userid = :userid AND surveyid $insql3", $inparams3, 'ranking ASC');
    if (!$responses) {
        echo $OUTPUT->notification("El usuario seleccionado no ha respondido la encuesta.", 'notifymessage');
        echo $OUTPUT->footer();
        exit;
    }
    foreach ($responses as $r) {
        $displayKey = isset($styleDisplay[$r->style]) ? $styleDisplay[$r->style] : ucfirst($r->style);
        if (isset($stylecounts[$displayKey])) {
            $stylecounts[$displayKey] = $r->score;
        }
    }
    $title = "Resultados de " . fullname($respondents[$selecteduserid]);
    $titleIcon = "👤";
}

arsort($stylecounts);
$strongest = array_key_first($stylecounts);
$maxcount = max($stylecounts);

// Metadata for styles
$stylesMeta = [
    'Activo'     => ['icon' => '🏃', 'color' => '#6366f1', 'desc' => 'Aprende haciendo, experimentando y trabajando en grupo.'],
    'Reflexivo'  => ['icon' => '🤔', 'color' => '#8b5cf6', 'desc' => 'Aprende pensando, analizando, y trabajando individualmente.'],
    'Sensorial'  => ['icon' => '🔬', 'color' => '#f59e0b', 'desc' => 'Prefiere hechos concretos, datos y experimentación.'],
    'Intuitivo'  => ['icon' => '💡', 'color' => '#ef4444', 'desc' => 'Prefiere conceptos abstractos, teorías e innovación.'],
    'Visual'     => ['icon' => '👁️', 'color' => '#06b6d4', 'desc' => 'Aprende mejor con imágenes, diagramas y esquemas.'],
    'Verbal'     => ['icon' => '💬', 'color' => '#0ea5e9', 'desc' => 'Aprende mejor con palabras, explicaciones y lecturas.'],
    'Secuencial' => ['icon' => '📋', 'color' => '#10b981', 'desc' => 'Comprende mejor paso a paso, de forma lineal.'],
    'Global'     => ['icon' => '🌐', 'color' => '#14b8a6', 'desc' => 'Comprende mejor viendo el panorama completo primero.'],
];

// Prepare data for chart
$labels = json_encode(array_keys($stylecounts));
$data = json_encode(array_values($stylecounts));
$colors = json_encode(array_map(function($s) use ($stylesMeta) {
    return $stylesMeta[$s]['color'];
}, array_keys($stylecounts)));

// Build user selector options
$options = ['0' => 'General (Todos los usuarios)'];
foreach ($respondents as $user) {
    $options[$user->id] = fullname($user);
}
?>

<div class="ils-results">
    <!-- Header -->
    <div class="ils-results-header">
        <h2>📊 Resultados del Test de Estilos de Aprendizaje</h2>
        <p>Análisis de preferencias de aprendizaje basado en el modelo ILS de Felder-Silverman</p>
    </div>

    <!-- User Selector -->
    <div class="ils-selector-wrapper">
        <form method="get" action="<?php echo $PAGE->url->out(false); ?>">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <select name="userid" onchange="this.form.submit();">
                <?php foreach ($options as $uid => $uname): ?>
                    <option value="<?php echo $uid; ?>" <?php echo ($uid == $selecteduserid) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($uname); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- Strongest Style Banner -->
    <div class="ils-strongest">
        <div class="ils-strongest-icon">
            <?php echo $stylesMeta[$strongest]['icon']; ?>
        </div>
        <div class="ils-strongest-info">
            <h3><?php echo $titleIcon . ' ' . $title; ?></h3>
            <h2>Estilo dominante: <?php echo $strongest; ?></h2>
            <p><?php echo $stylesMeta[$strongest]['desc']; ?></p>
        </div>
    </div>

    <!-- Content Grid: Chart + Progress Bars -->
    <div class="ils-content-grid">
        <!-- Radar Chart -->
        <div class="ils-chart-card">
            <h4>Perfil de Aprendizaje</h4>
            <div class="ils-chart-wrapper">
                <canvas id="resultChart"></canvas>
            </div>
        </div>

        <!-- Style Bars -->
        <div class="ils-bars-card">
            <h4>Desglose por Estilo</h4>
            <?php foreach ($stylecounts as $estilo => $cantidad):
                $pct = $maxcount > 0 ? round(($cantidad / $maxcount) * 100) : 0;
                $meta = $stylesMeta[$estilo];
                $isStrongest = ($estilo === $strongest);
            ?>
                <div class="ils-bar-item <?php echo $isStrongest ? 'strongest' : ''; ?>">
                    <div class="ils-bar-header">
                        <span class="ils-bar-name">
                            <span class="icon"><?php echo $meta['icon']; ?></span>
                            <?php echo $estilo; ?>
                        </span>
                        <span class="ils-bar-count"><?php echo $cantidad; ?> resp.</span>
                    </div>
                    <div class="ils-bar-track">
                        <div class="ils-bar-fill" style="width: <?php echo $pct; ?>%; background: <?php echo $meta['color']; ?>;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Dimension Comparison Cards -->
    <div class="ils-dimensions">
        <?php
        $pairs = [
            ['Activo', 'Reflexivo', 'Procesamiento', '#6366f1', '#8b5cf6'],
            ['Sensorial', 'Intuitivo', 'Percepción', '#f59e0b', '#ef4444'],
            ['Visual', 'Verbal', 'Canal Sensorial', '#06b6d4', '#0ea5e9'],
            ['Secuencial', 'Global', 'Organización', '#10b981', '#14b8a6'],
        ];
        foreach ($pairs as $pair):
            $left = $stylecounts[$pair[0]];
            $right = $stylecounts[$pair[1]];
            $total = $left + $right;
            $leftPct = $total > 0 ? round(($left / $total) * 100) : 50;
            $rightPct = 100 - $leftPct;
            $leftLead = $left >= $right;
        ?>
        <div class="ils-dim-card">
            <div class="ils-dim-title"><?php echo $pair[2]; ?></div>
            <div class="ils-dim-versus">
                <span class="ils-dim-label <?php echo $leftLead ? 'lead' : ''; ?>"><?php echo $pair[0]; ?> (<?php echo $left; ?>)</span>
                <span class="ils-dim-vs">VS</span>
                <span class="ils-dim-label <?php echo !$leftLead ? 'lead' : ''; ?>"><?php echo $pair[1]; ?> (<?php echo $right; ?>)</span>
            </div>
            <div class="ils-dim-scale">
                <div class="ils-dim-scale-left" style="width: <?php echo $leftPct; ?>%; background: <?php echo $pair[3]; ?>;"></div>
                <div class="ils-dim-scale-right" style="width: <?php echo $rightPct; ?>%; background: <?php echo $pair[4]; ?>;"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Back Button -->
    <div class="ils-results-back">
        <a href="<?php echo (new moodle_url('/mod/learningstylesurvey/view.php', ['id' => $cm->id]))->out(); ?>">
            ← Volver al menú
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    const ctx = document.getElementById('resultChart').getContext('2d');
    const labels = <?php echo $labels; ?>;
    const data = <?php echo $data; ?>;
    const colors = <?php echo $colors; ?>;

    new Chart(ctx, {
        type: 'radar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Respuestas',
                data: data,
                backgroundColor: 'rgba(99, 102, 241, 0.15)',
                borderColor: '#6366f1',
                borderWidth: 2.5,
                pointBackgroundColor: colors,
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                r: {
                    beginAtZero: true,
                    ticks: {
                        display: false
                    },
                    grid: {
                        color: 'rgba(0,0,0,0.06)'
                    },
                    angleLines: {
                        color: 'rgba(0,0,0,0.06)'
                    },
                    pointLabels: {
                        font: {
                            size: 12,
                            weight: '600',
                            family: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
                        },
                        color: '#475569'
                    }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleFont: { weight: '600' },
                    bodyFont: { size: 13 },
                    padding: 12,
                    cornerRadius: 10,
                    displayColors: true
                }
            }
        }
    });
})();
</script>

<?php
echo $OUTPUT->footer();
