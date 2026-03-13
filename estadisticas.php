<?php
require_once('../../config.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('learningstylesurvey', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);

// Solo profesores/admins pueden ver estadísticas
require_capability('moodle/course:update', $context);

$PAGE->set_cm($cm, $course);
$PAGE->set_url('/mod/learningstylesurvey/estadisticas.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title(format_string("Estadísticas de Estilos"));
$PAGE->set_heading(format_string($course->fullname));

// Cargar CSS
$PAGE->requires->css(new moodle_url('/mod/learningstylesurvey/style/estadisticas.css'));

echo $OUTPUT->header();

// ── Helper: badge class para estilos ──
function stats_badge_class($style) {
    $style_lower = strtolower(trim($style));
    $valid = ['activo','reflexivo','sensorial','intuitivo','visual','verbal','secuencial','global'];
    return in_array($style_lower, $valid) ? "stats-badge--{$style_lower}" : 'stats-badge--default';
}

function stats_badge($style) {
    $cls = stats_badge_class($style);
    return "<span class='stats-badge {$cls}'>" . ucfirst($style) . "</span>";
}

// ── Header ──
echo "<div class='stats-page'>";
echo "<div class='stats-header'>";
echo "  <h2>📈 Estadísticas de Aprendizaje</h2>";
echo "  <p>Análisis de rotaciones de estilos y rendimiento en evaluaciones</p>";
echo "</div>";

echo "<div class='stats-grid'>";

// ═══════════════════════════════════════════════
// 1. ¿Qué exámenes causan más rotaciones?
// ═══════════════════════════════════════════════
$sql1 = "SELECT q.name, COUNT(*) as rotations
         FROM {learningstylesurvey_style_log} sl
         JOIN {learningstylesurvey_quizzes} q ON q.id = sl.quizid
         WHERE sl.event_type = 'rotation'
         GROUP BY sl.quizid, q.name
         ORDER BY rotations DESC";
$rotations = $DB->get_records_sql($sql1);

echo "<div class='stats-card'>";
echo "  <h3 class='stats-card__title'>";
echo "    <span class='stats-card__icon stats-card__icon--rotate'>🔄</span>";
echo "    Exámenes con más rotaciones";
echo "  </h3>";

if (!empty($rotations)) {
    echo "<table class='stats-table'>";
    echo "  <thead><tr><th>Examen</th><th>Rotaciones</th></tr></thead>";
    echo "  <tbody>";
    foreach ($rotations as $row) {
        echo "<tr>";
        echo "  <td>{$row->name}</td>";
        echo "  <td><span class='stats-num stats-num--accent'>{$row->rotations}</span></td>";
        echo "</tr>";
    }
    echo "  </tbody></table>";
} else {
    echo "<div class='stats-empty'>";
    echo "  <span class='stats-empty__icon'>📭</span>";
    echo "  Sin datos de rotaciones disponibles";
    echo "</div>";
}
echo "</div>";

// ═══════════════════════════════════════════════
// 2. Tasa de aprobación por estilo
// ═══════════════════════════════════════════════
$sql2 = "SELECT new_style,
                SUM(CASE WHEN event_type = 'exam_pass' THEN 1 ELSE 0 END) as passes,
                SUM(CASE WHEN event_type = 'exam_fail' THEN 1 ELSE 0 END) as failures,
                COUNT(*) as total
         FROM {learningstylesurvey_style_log}
         WHERE event_type IN ('exam_pass', 'exam_fail')
         GROUP BY new_style";
$pass_rates = $DB->get_records_sql($sql2);

echo "<div class='stats-card'>";
echo "  <h3 class='stats-card__title'>";
echo "    <span class='stats-card__icon stats-card__icon--pass'>✅</span>";
echo "    Tasa de aprobación por estilo";
echo "  </h3>";

if (!empty($pass_rates)) {
    echo "<table class='stats-table'>";
    echo "  <thead><tr><th>Estilo</th><th>Aprobados</th><th>Reprobados</th><th>Tasa</th></tr></thead>";
    echo "  <tbody>";
    foreach ($pass_rates as $row) {
        $rate = $row->total > 0 ? round($row->passes * 100.0 / $row->total, 1) : 0;
        $badge = stats_badge($row->new_style);
        echo "<tr>";
        echo "  <td>{$badge}</td>";
        echo "  <td><span class='stats-num'>{$row->passes}</span></td>";
        echo "  <td><span class='stats-num'>{$row->failures}</span></td>";
        echo "  <td>";
        echo "    <div class='stats-rate'>";
        echo "      <div class='stats-rate__bar'><div class='stats-rate__fill' style='width:{$rate}%'></div></div>";
        echo "      <span class='stats-rate__value'>{$rate}%</span>";
        echo "    </div>";
        echo "  </td>";
        echo "</tr>";
    }
    echo "  </tbody></table>";
} else {
    echo "<div class='stats-empty'>";
    echo "  <span class='stats-empty__icon'>📭</span>";
    echo "  Sin datos de aprobación disponibles";
    echo "</div>";
}
echo "</div>";

// ═══════════════════════════════════════════════
// 3. ¿Mejoran los estudiantes después de rotar estilo?
// ═══════════════════════════════════════════════
$sql3 = "SELECT style_rank, AVG(exam_score) as avg_score, COUNT(*) as attempts
         FROM {learningstylesurvey_style_log}
         WHERE event_type IN ('exam_pass', 'exam_fail')
         GROUP BY style_rank
         ORDER BY style_rank ASC";
$improvements = $DB->get_records_sql($sql3);

echo "<div class='stats-card'>";
echo "  <h3 class='stats-card__title'>";
echo "    <span class='stats-card__icon stats-card__icon--improve'>📊</span>";
echo "    ¿Mejoran después de rotar estilo?";
echo "  </h3>";

if (!empty($improvements)) {
    echo "<table class='stats-table'>";
    echo "  <thead><tr><th>Ranking del estilo</th><th>Promedio de score</th><th>Intentos</th></tr></thead>";
    echo "  <tbody>";
    foreach ($improvements as $row) {
        $rank = intval($row->style_rank);
        $rank_cls = ($rank >= 1 && $rank <= 3) ? "stats-rank--{$rank}" : "stats-rank--default";
        $avg = round($row->avg_score, 1);
        // Color del score
        $score_cls = $avg >= 70 ? 'stats-score--good' : ($avg >= 50 ? 'stats-score--ok' : 'stats-score--bad');
        echo "<tr>";
        echo "  <td><span class='stats-rank {$rank_cls}'>{$rank}°</span></td>";
        echo "  <td><span class='{$score_cls}'>{$avg}%</span></td>";
        echo "  <td><span class='stats-num'>{$row->attempts}</span></td>";
        echo "</tr>";
    }
    echo "  </tbody></table>";
} else {
    echo "<div class='stats-empty'>";
    echo "  <span class='stats-empty__icon'>📭</span>";
    echo "  Sin datos de mejora disponibles";
    echo "</div>";
}
echo "</div>";

// ═══════════════════════════════════════════════
// 4. Estudiantes que siempre reprueban cierto examen (≥2)
// ═══════════════════════════════════════════════
$sql4 = "SELECT u.firstname, u.lastname, q.name, COUNT(*) as failures
         FROM {learningstylesurvey_style_log} sl
         JOIN {user} u ON u.id = sl.userid
         JOIN {learningstylesurvey_quizzes} q ON q.id = sl.quizid
         WHERE sl.event_type = 'exam_fail'
         GROUP BY sl.userid, sl.quizid, u.firstname, u.lastname, q.name
         HAVING COUNT(*) >= 2
         ORDER BY failures DESC";
$always_fail = $DB->get_records_sql($sql4);

echo "<div class='stats-card'>";
echo "  <h3 class='stats-card__title'>";
echo "    <span class='stats-card__icon stats-card__icon--fail'>⚠️</span>";
echo "    Estudiantes con reprobaciones repetidas";
echo "  </h3>";

if (!empty($always_fail)) {
    echo "<table class='stats-table'>";
    echo "  <thead><tr><th>Estudiante</th><th>Examen</th><th>Reprobaciones</th></tr></thead>";
    echo "  <tbody>";
    foreach ($always_fail as $row) {
        $fullname = s($row->firstname . ' ' . $row->lastname);
        echo "<tr>";
        echo "  <td>{$fullname}</td>";
        echo "  <td>{$row->name}</td>";
        echo "  <td><span class='stats-num' style='color:#dc2626;'>{$row->failures}</span></td>";
        echo "</tr>";
    }
    echo "  </tbody></table>";
} else {
    echo "<div class='stats-empty'>";
    echo "  <span class='stats-empty__icon'>🎉</span>";
    echo "  Ningún estudiante tiene reprobaciones repetidas";
    echo "</div>";
}
echo "</div>";

// ═══════════════════════════════════════════════
// 5. Transiciones de estilo más comunes (full width)
// ═══════════════════════════════════════════════
$sql5 = "SELECT old_style, new_style, COUNT(*) as times
         FROM {learningstylesurvey_style_log}
         WHERE event_type = 'rotation'
         GROUP BY old_style, new_style
         ORDER BY times DESC";
$transitions = $DB->get_records_sql($sql5);

echo "<div class='stats-card stats-card--full'>";
echo "  <h3 class='stats-card__title'>";
echo "    <span class='stats-card__icon stats-card__icon--trans'>🔀</span>";
echo "    Transiciones de estilo más comunes";
echo "  </h3>";

if (!empty($transitions)) {
    echo "<table class='stats-table'>";
    echo "  <thead><tr><th>Estilo origen</th><th></th><th>Estilo destino</th><th>Veces</th></tr></thead>";
    echo "  <tbody>";
    foreach ($transitions as $row) {
        $from = stats_badge($row->old_style);
        $to = stats_badge($row->new_style);
        echo "<tr>";
        echo "  <td>{$from}</td>";
        echo "  <td><span class='stats-transition__arrow'>→</span></td>";
        echo "  <td>{$to}</td>";
        echo "  <td><span class='stats-num stats-num--accent'>{$row->times}</span></td>";
        echo "</tr>";
    }
    echo "  </tbody></table>";
} else {
    echo "<div class='stats-empty'>";
    echo "  <span class='stats-empty__icon'>📭</span>";
    echo "  Sin datos de transiciones disponibles";
    echo "</div>";
}
echo "</div>";

echo "</div>"; // .stats-grid

// ── Back button ──
echo "<div class='stats-back'>";
$back_url = new moodle_url('/mod/learningstylesurvey/view.php', ['id' => $id]);
echo "  <a href='" . $back_url->out() . "'>← Volver al menú principal</a>";
echo "</div>";

echo "</div>"; // .stats-page

echo $OUTPUT->footer();
