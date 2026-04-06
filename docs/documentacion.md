# Documentación Técnica — Plugin Prisma (Learning Style Survey)

> Plugin de actividad para Moodle que implementa aprendizaje adaptativo basado en estilos de aprendizaje ILS.

---

## Índice

1. [Arquitectura general](#1-arquitectura-general)
2. [Base de datos](#2-base-de-datos)
3. [Módulos del plugin](#3-módulos-del-plugin)
4. [Permisos y roles](#4-permisos-y-roles)
5. [Integración con Moodle](#5-integración-con-moodle)
6. [Errores comunes y soluciones](#6-errores-comunes-y-soluciones)
7. [Guía para extender el plugin](#7-guía-para-extender-el-plugin)

---

## 1. Arquitectura general

El plugin se divide en cuatro módulos funcionales:

```
Encuesta ILS → Detección de estilo → Ruta de aprendizaje → Evaluación
                                          ↕
                                   Recursos didácticos
```

| Módulo        | Carpeta/Archivo          | Responsabilidad                                      |
|---------------|--------------------------|------------------------------------------------------|
| Encuesta      | `surveyform.php`         | Presenta las preguntas ILS y guarda respuestas       |
| Estilos       | `results.php`            | Calcula y muestra el estilo dominante del usuario    |
| Rutas         | `path/`                  | Gestiona los pasos de la ruta adaptativa             |
| Recursos      | `resource/`              | Sube, organiza y sirve archivos didácticos           |
| Evaluaciones  | `quiz/`                  | Crea, administra y califica exámenes                 |
| Estadísticas  | `estadisticas.php`       | Reportes para el profesor                            |
| Bloqueados    | `bloqueados.php`         | Gestiona estudiantes inhabilitados por 3 fallos      |

---

## 2. Base de datos

### Tablas principales (definidas en `db/install.xml`)

| Tabla                                  | Descripción                                             |
|----------------------------------------|---------------------------------------------------------|
| `learningstylesurvey`                  | Instancia del módulo en un curso                        |
| `learningstylesurvey_userstyles`       | Estilo de aprendizaje asignado a cada usuario           |
| `learningstylesurvey_responses`        | Respuestas de la encuesta ILS                           |
| `learningstylesurvey_ilsquestions`     | Preguntas de la encuesta ILS                            |
| `learningstylesurvey_ilsanswers`       | Opciones de respuesta de la encuesta                    |
| `learningstylesurvey_paths`            | Rutas de aprendizaje por curso                          |
| `learningpath_steps`                   | Pasos de cada ruta (recurso o evaluación)               |
| `learningstylesurvey_user_progress`    | Progreso del estudiante en su ruta                      |
| `learningstylesurvey_resources`        | Recursos didácticos subidos por el profesor             |
| `learningstylesurvey_temas`            | Categorías/temas de los recursos                        |
| `learningstylesurvey_quizzes`          | Exámenes creados                                        |
| `learningstylesurvey_questions`        | Preguntas de cada examen                                |
| `learningstylesurvey_options`          | Opciones de respuesta de cada pregunta                  |
| `learningstylesurvey_quiz_results`     | Resultados de exámenes por intento                      |
| `learningstylesurvey_style_log`        | Historial de rotaciones de estilo y resultados          |

### Tablas añadidas por actualizaciones (`db/upgrade.php`)

| Tabla                                  | Descripción                                             |
|----------------------------------------|---------------------------------------------------------|
| `learningstylesurvey_path_temas`       | Relación entre rutas y temas                            |
| `learningstylesurvey_path_evaluations` | Evaluaciones asociadas a pasos de una ruta              |
| `learningstylesurvey_unblocks`         | Registro de desbloqueos de estudiantes                  |

### Campos críticos a recordar

```
learningstylesurvey_resources
  → El campo es 'tema' (NO 'temaid')
  → 'userid' es del PROFESOR que créo el recurso, NO del estudiante

learningstylesurvey_quizzes
  → El nombre es 'quizzes' (NO 'quiz')

learningstylesurvey_options
  → Siempre ordenar con 'ORDER BY id ASC' para consistencia

learningpath_steps
  → istest = 1 → es evaluación; istest = 0 → es recurso
  → passredirect / failredirect → ID del siguiente paso según resultado

learningstylesurvey_userstyles
  → Para obtener el estilo actual: ORDER BY timecreated DESC LIMIT 1
```

---

## 3. Módulos del plugin

### 3.1 Encuesta y detección de estilo

**Archivos:** `surveyform.php`, `results.php`

- `surveyform.php` carga las preguntas desde `learningstylesurvey_ilsquestions` y guarda las respuestas en `learningstylesurvey_responses`.
- `results.php` cuenta las respuestas por estilo, determina el dominante y lo guarda en `learningstylesurvey_userstyles`.

**Estilos reconocidos:** `activo`, `reflexivo`, `sensorial`, `intuitivo`, `visual`, `verbal`, `secuencial`, `global`

> **Importante:** Siempre normalizar el estilo con `strtolower(trim($style))` antes de comparar o guardar.

---

### 3.2 Rutas de aprendizaje (`path/`)

| Archivo                  | Función                                                              |
|--------------------------|----------------------------------------------------------------------|
| `vista_estudiante.php`   | Vista principal; muestra el paso actual, recursos y evaluaciones     |
| `learningpath.php`       | Lista las rutas del curso (profesor)                                 |
| `createsteproute.php`    | Formulario para crear un nuevo paso en una ruta                      |
| `edit_learningpath.php`  | Editar nombre, pasos y orden de una ruta existente                   |
| `organizar_ruta.php`     | Interfaz drag-and-drop para reordenar pasos                          |
| `guardar_orden.php`      | Endpoint AJAX que guarda el nuevo orden de pasos                     |
| `siguiente.php`          | Procesa el avance al siguiente paso y actualiza el progreso          |
| `siguiente_tema.php`     | Navega entre temas dentro de un paso                                 |
| `verruta.php`            | Vista simplificada de la ruta para el estudiante                     |
| `delete_learningpath.php`| Elimina una ruta y su progreso asociado                              |

**Lógica de avance:**

```php
// El estudiante avanza si aprueba la evaluación del paso
if ($score >= 70) {
    $progress->current_stepid = $step->passredirect;
} else {
    $progress->current_stepid = $step->failredirect; // paso de refuerzo
}
$DB->update_record('learningstylesurvey_user_progress', $progress);
```

**Regla de 3 intentos:**
- Si el estudiante reprueba 3 veces el mismo examen, su estado cambia a `blocked`.
- El profesor puede desbloquearlo desde `bloqueados.php` → `desbloquear.php`.

---

### 3.3 Recursos didácticos (`resource/`)

| Archivo              | Función                                                       |
|----------------------|---------------------------------------------------------------|
| `uploadresource.php` | Subir un archivo y asociarlo a un tema y estilo de aprendizaje|
| `viewresources.php`  | Listar todos los recursos del curso                           |
| `ver_recurso.php`    | Mostrar un recurso individual (PDF, imagen, etc.)             |
| `temas.php`          | Crear y gestionar categorías de recursos                      |

Los archivos se almacenan en `{moodle_dataroot}/learningstylesurvey/{courseid}/`.

---

### 3.4 Evaluaciones (`quiz/`)

| Archivo              | Función                                                        |
|----------------------|----------------------------------------------------------------|
| `crear_examen.php`   | Crear un examen con preguntas y opciones de respuesta          |
| `manage_quiz.php`    | Editar preguntas, opciones y respuesta correcta                |
| `responder_quiz.php` | Presentar el examen al estudiante y calificarlo                |
| `guardar_examen.php` | Guardar el intento y su resultado en la base de datos          |

**Regla de índices:** Las opciones de respuesta usan índices que empiezan en `0`. Este orden debe ser consistente entre creación, edición y evaluación.

```php
// Correcto: índices desde 0, orden consistente
$options = $DB->get_records('learningstylesurvey_options', ['questionid' => $q->id], 'id ASC');

// Cada intento es un nuevo registro (NO update_record)
$DB->insert_record('learningstylesurvey_quiz_results', $record);
```

---

### 3.5 Archivos de soporte

| Archivo          | Función                                                            |
|------------------|--------------------------------------------------------------------|
| `view.php`       | Menú principal; muestra opciones según el rol del usuario          |
| `lib.php`        | Funciones requeridas por Moodle: `add_instance`, `update_instance`, `delete_instance` |
| `locallib.php`   | Funciones internas: detección de estilo, gestión de directorios    |
| `mod_form.php`   | Formulario de configuración al agregar el módulo a un curso        |
| `version.php`    | Versión del plugin y versión mínima de Moodle requerida            |
| `access.php`     | Definición de capabilities (`manage`, `view`)                      |
| `estadisticas.php` | Reportes de rendimiento y rotación de estilos (solo profesor)    |
| `bloqueados.php` | Lista de estudiantes bloqueados con opción de desbloqueo           |
| `desbloquear.php`| Procesa el desbloqueo de un estudiante                             |

---

## 4. Permisos y roles

Definidos en `db/access.php`:

| Capability                       | Estudiante | Profesor | Admin |
|----------------------------------|:----------:|:--------:|:-----:|
| `mod/learningstylesurvey:view`   | ✅         | ✅       | ✅    |
| `mod/learningstylesurvey:manage` | ❌         | ✅       | ✅    |
| Herramientas de verificación     | ❌         | ❌       | ✅    |

En el código, la distinción estudiante/profesor se hace con:

```php
if (has_capability('moodle/course:update', $context)) {
    // Es profesor o admin
} else {
    // Es estudiante
}
```

---

## 5. Integración con Moodle

### Globals disponibles en todos los archivos

```php
$CFG      // Configuración global (dirroot, dataroot, wwwroot)
$DB       // Acceso a base de datos
$USER     // Usuario en sesión
$PAGE     // Objeto de la página actual
$OUTPUT   // Renderer de HTML
```

### Patrón estándar de inicio de archivo

```php
require_once('../../config.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('learningstylesurvey', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
$PAGE->set_url('/mod/learningstylesurvey/archivo.php', ['id' => $id]);
$PAGE->set_context($context);

echo $OUTPUT->header();
// ... contenido ...
echo $OUTPUT->footer();
```

### Construcción de URLs

```php
// Siempre usar moodle_url (no strings hardcodeadas)
$url = new moodle_url('/mod/learningstylesurvey/view.php', ['id' => $cmid]);
echo html_writer::link($url, 'Regresar', ['class' => 'btn btn-secondary']);
```

### Obtener el cmid de un curso

```php
$modinfo = get_fast_modinfo($courseid);
foreach ($modinfo->get_cms() as $cm) {
    if ($cm->modname === 'learningstylesurvey') {
        $cmid = $cm->id;
        break;
    }
}
```

---

## 6. Errores comunes y soluciones

| Problema                                      | Causa probable                                          | Solución                                                     |
|-----------------------------------------------|---------------------------------------------------------|--------------------------------------------------------------|
| No se asigna ruta al estudiante               | El usuario no completó la encuesta                      | Verificar que exista registro en `learningstylesurvey_userstyles` |
| Los recursos no aparecen                      | El estilo no coincide (mayúsculas/minúsculas)           | Normalizar con `strtolower(trim($style))`                    |
| El examen siempre marca mal las respuestas    | Inconsistencia en índices de opciones                   | Usar `ORDER BY id ASC` y empezar índices en `0`              |
| El estudiante va a refuerzo aunque aprobó     | El sistema busca exámenes del curso completo, no la ruta | Filtrar resultados por `pathid` en la consulta               |
| Error de tabla inexistente                    | Se usó nombre incorrecto (`quiz` en lugar de `quizzes`) | Revisar `db/install.xml` para confirmar nombres exactos      |
| El módulo no avanza al siguiente paso         | `passredirect` o `failredirect` no configurados en el paso | Verificar la configuración del paso en la ruta             |
| Error de permisos                             | `require_capability()` mal configurado                  | Revisar `db/access.php` y el contexto usado                  |

---

## 7. Guía para extender el plugin

### Agregar un nuevo tipo de recurso (ejemplo: videos)

1. **Base de datos:** Agregar una tabla `learningstylesurvey_videos` en `db/upgrade.php`.
2. **Subida:** Crear o modificar `resource/uploadresource.php` para aceptar el nuevo tipo.
3. **Visualización:** Modificar `resource/ver_recurso.php` para renderizar videos.
4. **Ruta:** Actualizar `path/vista_estudiante.php` para mostrar el nuevo tipo en los pasos.
5. **Gestión:** Actualizar `resource/viewresources.php` para listar los nuevos recursos.

### Agregar un nuevo estilo de aprendizaje

1. Agregar el estilo al array en `results.php`.
2. Agregar las preguntas correspondientes en `learningstylesurvey_ilsquestions`.
3. Crear recursos en `learningstylesurvey_resources` con el nuevo valor de `style`.
4. Actualizar la lógica de asignación de rutas en `path/vista_estudiante.php`.

### Agregar una nueva página de profesor

```php
<?php
require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$cmid     = required_param('cmid', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$cm      = get_coursemodule_from_id('learningstylesurvey', $cmid, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/learningstylesurvey:manage', $context);

$PAGE->set_url('/mod/learningstylesurvey/mi_pagina.php', ['courseid' => $courseid, 'cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_title('Mi nueva página');

echo $OUTPUT->header();
// ... tu contenido aquí ...
echo $OUTPUT->footer();
```

---

*Para más información consulta la [documentación oficial de desarrollo de Moodle](https://moodledev.io/).*