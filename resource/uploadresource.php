<?php
require_once("../../../config.php");
require_once("../../../mod/learningstylesurvey/lib.php");
require_login();

$courseid = required_param("courseid", PARAM_INT);
$cmid     = optional_param("cmid", 0, PARAM_INT);

$context = context_course::instance($courseid);
$PAGE->set_context($context);
$PAGE->set_url("/mod/learningstylesurvey/resource/uploadresource.php", ["courseid" => $courseid, "cmid" => $cmid]);
$PAGE->set_title("Subir recurso adaptativo");
$PAGE->set_heading("Subir recurso adaptativo");
$PAGE->requires->css("/mod/learningstylesurvey/style/uploadresource.css");

// Resolver cmid
if ($cmid > 0) {
    $targetcmid = $cmid;
} else {
    $cm = get_fast_modinfo($courseid)->get_instances_of('learningstylesurvey');
    $firstcm    = reset($cm);
    $targetcmid = $firstcm->id;
}

// Cargar temas del usuario
$temas = $DB->get_records(
    'learningstylesurvey_temas',
    ['courseid' => $courseid, 'userid' => $USER->id],
    'timecreated DESC'
);

$errors  = [];
$success = false;
$inserted_count = 0;

/* ─────────────────────────────────────────────────────────────
   POST handling – multi-style insert
   ───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name  = required_param('name', PARAM_TEXT);
    $tema  = required_param('tema', PARAM_INT);
    $file  = $_FILES['file'];

    // Recoger todos los estilos enviados (styles[] es el array)
    $styles_raw = optional_param_array('styles', [], PARAM_TEXT);
    $styles     = array_values(array_filter(array_unique($styles_raw)));

    // Validaciones básicas
    if (empty($name)) {
        $errors[] = "El nombre del recurso es obligatorio.";
    }
    if (empty($tema)) {
        $errors[] = "Debes seleccionar un tema.";
    }
    if (empty($styles)) {
        $errors[] = "Debes seleccionar al menos un estilo de aprendizaje.";
    }
    if (empty($file['name'])) {
        $errors[] = "Debes adjuntar un archivo.";
    }

    if (empty($errors)) {
        $allowed_extensions = [
            'txt','pdf','doc','docx','xls','xlsx','ppt','pptx',
            'jpg','jpeg','png','gif','webp','svg',
            'mp4','webm','avi','mov','mp3','wav','ogg','html','htm'
        ];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "Tipo de archivo no permitido. Tipos permitidos: " . implode(', ', $allowed_extensions);
        }
    }

    if (empty($errors)) {
        $upload_dir = learningstylesurvey_ensure_upload_directory($courseid);
        learningstylesurvey_migrate_files($courseid);

        $originalname = basename($file['name']);
        // Timestamp único para esta subida; se reutiliza para todos los estilos
        $timestamp = time();
        // Usamos el primer estilo en el nombre del archivo físico
        $filename_base = $styles[0] . '_' . $timestamp . '_' . $originalname;
        $fullpath      = $upload_dir . $filename_base;

        if (!move_uploaded_file($file['tmp_name'], $fullpath)) {
            $errors[] = "Error al subir el archivo al servidor.";
        } else {
            // El archivo físico es UNO; creamos un registro por cada estilo
            $path = $DB->get_record('learningstylesurvey_paths', ['courseid' => $courseid], '*', IGNORE_MISSING);

            foreach ($styles as $idx => $style) {
                // Nombre de archivo lógico único por estilo
                $filename_logical = $style . '_' . $timestamp . '_' . $originalname;

                // Si el archivo físico ya existe con otro nombre lo copiamos (o reutilizamos el mismo)
                if ($idx > 0) {
                    // Copia física con nombre del estilo correspondiente
                    $dest = $upload_dir . $filename_logical;
                    if (!file_exists($dest)) {
                        copy($fullpath, $dest);
                    }
                } else {
                    $filename_logical = $filename_base; // primer estilo ya fue movido
                }

                // Verificar duplicado en BD
                $existing = $DB->get_record('learningstylesurvey_resources', [
                    'filename' => $filename_logical,
                    'courseid' => $courseid,
                    'style'    => $style,
                    'userid'   => $USER->id
                ]);
                if ($existing) {
                    continue; // ya existe, omitir
                }

                // Insert en resources
                $record              = new stdClass();
                $record->courseid    = $courseid;
                $record->userid      = $USER->id;
                $record->name        = $name;
                $record->style       = $style;
                $record->tema        = $tema;
                $record->filename    = $filename_logical;
                $record->timecreated = $timestamp;
                $resourceid = $DB->insert_record('learningstylesurvey_resources', $record);

                // Insert en inforoute
                if (!$DB->record_exists('learningstylesurvey_inforoute', ['filename' => $filename_logical, 'courseid' => $courseid])) {
                    $route               = new stdClass();
                    $route->courseid     = $courseid;
                    $route->name         = $name;
                    $route->filename     = $filename_logical;
                    $route->instructions = '';
                    $route->steporder    = 0;
                    $route->style        = $style;
                    $route->timecreated  = $timestamp;
                    $route->resourceid   = $resourceid;
                    $DB->insert_record('learningstylesurvey_inforoute', $route);
                }

                // Insert en path_files
                if ($path && !$DB->record_exists('learningstylesurvey_path_files', ['filename' => $filename_logical, 'pathid' => $path->id])) {
                    $pathfile           = new stdClass();
                    $pathfile->pathid   = $path->id;
                    $pathfile->filename = $filename_logical;
                    $pathfile->steporder = 0;
                    $DB->insert_record('learningstylesurvey_path_files', $pathfile);
                }

                // Insert en learningpath_steps
                if ($path && !$DB->record_exists('learningpath_steps', ['resourceid' => $resourceid, 'pathid' => $path->id])) {
                    $maxstep  = $DB->get_field_sql("SELECT MAX(stepnumber) FROM {learningpath_steps} WHERE pathid = ?", [$path->id]);
                    $nextstep = $maxstep ? $maxstep + 1 : 1;

                    $step             = new stdClass();
                    $step->pathid     = $path->id;
                    $step->stepnumber = $nextstep;
                    $step->resourceid = $resourceid;
                    $step->istest     = 0;
                    $DB->insert_record('learningpath_steps', $step);
                }

                $inserted_count++;
            }

            $success = true;
        }
    }
}

/* ─────────────────────────────────────────────────────────────
   OUTPUT
   ───────────────────────────────────────────────────────────── */
echo $OUTPUT->header();

$viewurl = new moodle_url('/mod/learningstylesurvey/view.php', ['id' => $targetcmid, 'courseid' => $courseid]);
?>
<div class="ur-wrapper">
    <div class="ur-card">

        <!-- Header -->
        <div class="ur-header">
            <div class="ur-header-icon">📤</div>
            <div>
                <h2>Subir recurso adaptativo</h2>
                <p>Asocia el recurso a uno o varios estilos de aprendizaje</p>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $e): ?>
                <div class="ur-alert ur-alert-error">
                    <span>⚠️</span>
                    <span><?php echo htmlspecialchars($e); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="ur-alert ur-alert-success">
                <span>✅</span>
                <span>
                    Recurso subido exitosamente.
                    <?php if ($inserted_count > 0): ?>
                        Se realizaron <strong><?php echo $inserted_count; ?></strong>
                        <?php echo $inserted_count === 1 ? 'inserción' : 'inserciones'; ?>
                        en la base de datos (una por estilo de aprendizaje).
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form id="ur-form" method="post" enctype="multipart/form-data" novalidate>

            <!-- Resource name -->
            <div class="ur-field">
                <label class="ur-label" for="name">Nombre del recurso</label>
                <input class="ur-input" type="text" id="name" name="name"
                       placeholder="Ej: Presentación Unidad 1" required
                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
            </div>

            <!-- Tema -->
            <div class="ur-field">
                <label class="ur-label" for="tema">Tema</label>
                <select class="ur-select" id="tema" name="tema" required>
                    <option value="">— Selecciona un tema —</option>
                    <?php foreach ($temas as $t):
                        $sel = (isset($_POST['tema']) && $_POST['tema'] == $t->id) ? 'selected' : '';
                    ?>
                        <option value="<?php echo htmlspecialchars($t->id); ?>" <?php echo $sel; ?>>
                            <?php echo format_string($t->tema); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <hr class="ur-divider">

            <!-- Learning styles – dynamic section -->
            <div class="ur-styles-section">
                <span class="ur-styles-label">
                    Estilos de aprendizaje
                    <span class="ur-count-chip" id="ur-style-count">1</span>
                </span>

                <div id="ur-styles-container">
                    <!-- Row 1 (static, cannot be removed) -->
                    <div class="ur-style-row" id="ur-style-row-1">
                        <span class="ur-style-badge badge-1">1</span>
                        <select class="ur-select" name="styles[]" required>
                            <option value="">— Selecciona un estilo —</option>
                            <?php
                            $style_options = [
                                'activo'      => 'Activo',
                                'reflexivo'   => 'Reflexivo',
                                'sensorial'   => 'Sensorial',
                                'intuitivo'   => 'Intuitivo',
                                'visual'      => 'Visual',
                                'verbal'      => 'Verbal',
                                'secuencial'  => 'Secuencial',
                                'global'      => 'Global',
                            ];
                            foreach ($style_options as $val => $label) {
                                $sel = (isset($_POST['styles'][0]) && $_POST['styles'][0] === $val) ? 'selected' : '';
                                echo "<option value=\"$val\" $sel>$label</option>";
                            }
                            ?>
                        </select>
                        <!-- No remove button on first row -->
                    </div>
                </div>

                <!-- Add another style button -->
                <button type="button" class="ur-add-style-btn" id="ur-add-style" onclick="urAddStyleRow()">
                    <span class="add-icon">＋</span>
                    Agregar otro estilo de aprendizaje
                </button>
            </div>

            <hr class="ur-divider">

            <!-- File -->
            <div class="ur-field">
                <label class="ur-label">Archivo</label>
                <div class="ur-dropzone" id="ur-dropzone">
                    <input type="file" id="file" name="file" required
                           onchange="urShowFilename(this)">
                    <span class="ur-dropzone-icon">📁</span>
                    <p class="ur-dropzone-text">Arrastra tu archivo aquí o haz clic para seleccionarlo</p>
                    <p class="ur-dropzone-hint">PDF, Word, Excel, PowerPoint, imágenes, video, audio…</p>
                    <div id="ur-filename-display"></div>
                </div>

                <div class="ur-formats">
                    <strong>📄 Documentos:</strong> PDF, Word (.doc .docx), Excel (.xls .xlsx), PowerPoint (.ppt .pptx), Texto (.txt)<br>
                    <strong>🖼️ Imágenes:</strong> JPG, PNG, GIF, WebP, SVG<br>
                    <strong>🎬 Video:</strong> MP4, WebM, AVI, MOV<br>
                    <strong>🎵 Audio:</strong> MP3, WAV, OGG<br>
                    <strong>🌐 Web:</strong> HTML, HTM
                </div>
            </div>

            <!-- Submit -->
            <button type="submit" class="ur-submit-btn" id="ur-submit">
                ⬆️ &nbsp;Subir recurso
            </button>
        </form>

    </div><!-- /.ur-card -->

    <!-- Back link -->
    <div class="ur-back">
        <a href="<?php echo $viewurl->out(); ?>">← Regresar al menú</a>
    </div>
</div><!-- /.ur-wrapper -->

<script>
/* ── Style options (reusable) ── */
const UR_STYLES = [
    { value: 'activo',     label: 'Activo' },
    { value: 'reflexivo',  label: 'Reflexivo' },
    { value: 'sensorial',  label: 'Sensorial' },
    { value: 'intuitivo',  label: 'Intuitivo' },
    { value: 'visual',     label: 'Visual' },
    { value: 'verbal',     label: 'Verbal' },
    { value: 'secuencial', label: 'Secuencial' },
    { value: 'global',     label: 'Global' },
];
const BADGE_CLASSES = ['badge-1','badge-2','badge-3','badge-4','badge-5'];
const MAX_STYLES = 5;

let urRowCount = 1;

function urBuildSelect(value) {
    const sel = document.createElement('select');
    sel.className  = 'ur-select';
    sel.name       = 'styles[]';
    sel.required   = true;
    sel.innerHTML  = '<option value="">— Selecciona un estilo —</option>';
    UR_STYLES.forEach(function(s) {
        const opt = document.createElement('option');
        opt.value       = s.value;
        opt.textContent = s.label;
        if (s.value === value) opt.selected = true;
        sel.appendChild(opt);
    });
    return sel;
}

function urAddStyleRow(preselect) {
    if (urRowCount >= MAX_STYLES) {
        return;
    }
    urRowCount++;
    const container = document.getElementById('ur-styles-container');
    const idx       = urRowCount;
    const badgeClass = BADGE_CLASSES[idx - 1] || 'badge-5';

    const row = document.createElement('div');
    row.className = 'ur-style-row';
    row.id        = 'ur-style-row-' + idx;

    // Badge
    const badge = document.createElement('span');
    badge.className = 'ur-style-badge ' + badgeClass;
    badge.textContent = idx;
    row.appendChild(badge);

    // Select
    row.appendChild(urBuildSelect(preselect || ''));

    // Remove button
    const removeBtn = document.createElement('button');
    removeBtn.type       = 'button';
    removeBtn.className  = 'ur-remove-btn';
    removeBtn.title      = 'Quitar este estilo';
    removeBtn.innerHTML  = '✕';
    removeBtn.addEventListener('click', function() {
        row.remove();
        urRowCount = document.querySelectorAll('.ur-style-row').length;
        urRenumberRows();
        urUpdateCount();
        urToggleAddBtn();
    });
    row.appendChild(removeBtn);

    container.appendChild(row);
    urUpdateCount();
    urToggleAddBtn();
}

function urRenumberRows() {
    const rows = document.querySelectorAll('.ur-style-row');
    rows.forEach(function(row, i) {
        const badge = row.querySelector('.ur-style-badge');
        if (badge) {
            badge.textContent = i + 1;
            badge.className   = 'ur-style-badge ' + (BADGE_CLASSES[i] || 'badge-5');
        }
    });
}

function urUpdateCount() {
    const count = document.querySelectorAll('.ur-style-row').length;
    document.getElementById('ur-style-count').textContent = count;
}

function urToggleAddBtn() {
    const btn = document.getElementById('ur-add-style');
    const count = document.querySelectorAll('.ur-style-row').length;
    btn.style.display = count >= MAX_STYLES ? 'none' : 'flex';
}

/* ── Dropzone drag & drop highlight ── */
(function() {
    const dz = document.getElementById('ur-dropzone');
    if (!dz) return;
    dz.addEventListener('dragover',  function(e) { e.preventDefault(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', function()  { dz.classList.remove('drag-over'); });
    dz.addEventListener('drop',      function(e) {
        e.preventDefault();
        dz.classList.remove('drag-over');
        const input = dz.querySelector('input[type="file"]');
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            urShowFilename(input);
        }
    });
})();

function urShowFilename(input) {
    const display = document.getElementById('ur-filename-display');
    if (input.files && input.files[0]) {
        display.textContent = '✔ ' + input.files[0].name;
        display.style.display = 'block';
    } else {
        display.style.display = 'none';
    }
}

/* ── Client-side validation ── */
document.getElementById('ur-form').addEventListener('submit', function(e) {
    // Verify at least one style is selected
    const selects = document.querySelectorAll('[name="styles[]"]');
    let hasStyle = false;
    selects.forEach(function(s) { if (s.value) hasStyle = true; });
    if (!hasStyle) {
        e.preventDefault();
        alert('Debes seleccionar al menos un estilo de aprendizaje.');
        return false;
    }
    // Warn on duplicates
    const vals = [];
    let hasDup = false;
    selects.forEach(function(s) {
        if (s.value && vals.includes(s.value)) hasDup = true;
        else if (s.value) vals.push(s.value);
    });
    if (hasDup) {
        if (!confirm('Hay estilos de aprendizaje repetidos. ¿Deseas continuar de todas formas?')) {
            e.preventDefault();
            return false;
        }
    }
});
</script>

<?php
echo $OUTPUT->footer();
