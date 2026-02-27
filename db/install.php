<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Seeder que se ejecuta al instalar el plugin.
 * Inserta las 44 preguntas del ILS de Felder-Silverman y sus 88 respuestas
 * en las tablas learningstylesurvey_ilsquestions y learningstylesurvey_ilsanswers.
 */
function xmldb_learningstylesurvey_install() {
    global $DB;

    // Si ya hay preguntas, no volver a insertar.
    if ($DB->count_records('learningstylesurvey_ilsquestions') > 0) {
        return;
    }

    // Mapeo: número de pregunta => [estilo_a0, estilo_a1]
    // Patrón cíclico cada 4 preguntas:
    //   N%4==1 => Activo/Reflexivo
    //   N%4==2 => Sensorial/Intuitivo
    //   N%4==3 => Visual/Verbal
    //   N%4==0 => Secuencial/Global
    $stylemap = [
        1 => ['activo', 'reflexivo'],
        2 => ['sensorial', 'intuitivo'],
        3 => ['visual', 'verbal'],
        4 => ['secuencial', 'global'],
    ];

    // Las 44 preguntas con sus dos respuestas: [pregunta, respuesta_a0, respuesta_a1]
    $questions = [
        1  => ['Entiendo algo mejor después de', 'probarlo.', 'pensarlo detenidamente.'],
        2  => ['Preferiría que me consideraran', 'realista.', 'innovador.'],
        3  => ['Cuando pienso en lo que hice ayer, lo más probable es que recuerde', 'una imagen.', 'palabras.'],
        4  => ['Tiendo a', 'entender los detalles pero no el panorama general.', 'entender el panorama general pero no los detalles.'],
        5  => ['Cuando aprendo algo nuevo, me ayuda', 'hablar sobre ello.', 'pensar en ello.'],
        6  => ['Si fuera profesor, preferiría enseñar un curso', 'que trate sobre hechos y situaciones reales.', 'que trate sobre ideas y teorías.'],
        7  => ['Prefiero obtener nueva información a través de', 'imágenes, diagramas y gráficos.', 'instrucciones verbales o discusión.'],
        8  => ['Entiendo mejor algo', 'practicándolo.', 'pensando en ello.'],
        9  => ['En un grupo de estudio para una materia difícil, prefiero', 'discutir los temas.', 'trabajar solo.'],
        10 => ['Consigo aprender mejor', 'cuando se me presentan hechos concretos.', 'cuando se me presentan ideas y conceptos.'],
        11 => ['En los cursos que he tomado', 'me ha resultado más fácil aprender hechos.', 'me ha resultado más fácil aprender conceptos.'],
        12 => ['Me interesa más', 'un curso que enseñe técnicas prácticas.', 'un curso que enseñe ideas teóricas.'],
        13 => ['Prefiero recibir nueva información', 'visualmente (diagramas, gráficos).', 'verbalmente (palabras, explicaciones).'],
        14 => ['Prefiero', 'hacer algo práctico.', 'pensar en cómo hacerlo.'],
        15 => ['En un grupo de estudio en el que se trabaja duro, prefiero', 'participar activamente en las discusiones.', 'mantenerme al margen y observar.'],
        16 => ['Me resulta más fácil aprender', 'paso a paso.', 'viendo el panorama completo.'],
        17 => ['Prefiero', 'el aprendizaje visual (diagramas, imágenes).', 'el aprendizaje verbal (lectura, conversación).'],
        18 => ['Cuando comienzo a resolver un problema, usualmente', 'trabajo con la primera estrategia que se me ocurre.', 'pienso en distintas formas antes de intentarlo.'],
        19 => ['En la mayoría de las clases que he tomado', 'me ha resultado más fácil aprender hechos.', 'me ha resultado más fácil aprender ideas.'],
        20 => ['Me considero', 'cuidadoso.', 'creativo.'],
        21 => ['Cuando leo instrucciones para armar algo', 'prefiero probar y ver cómo resulta.', 'prefiero leer todas las instrucciones antes de comenzar.'],
        22 => ['Aprendo mejor', 'de ejemplos concretos.', 'de principios generales.'],
        23 => ['Cuando veo un diagrama o una tabla en un texto', 'examino cuidadosamente los detalles.', 'concentro mi atención en el significado global.'],
        24 => ['Cuando pienso en lo que hice ayer', 'veo imágenes mentales.', 'recuerdo palabras.'],
        25 => ['Aprendo mejor de', 'experiencias prácticas.', 'reflexión.'],
        26 => ['Prefiero que me consideren', 'cuidadoso con los hechos.', 'imaginativo.'],
        27 => ['Cuando resuelvo problemas en grupo', 'me gusta hablar y explicar.', 'prefiero escuchar y pensar.'],
        28 => ['Cuando aprendo una nueva materia', 'me concentro en los detalles.', 'trato de comprender la estructura general.'],
        29 => ['Cuando alguien me da instrucciones', 'prefiero que me muestre cómo hacerlo.', 'prefiero que me diga cómo hacerlo.'],
        30 => ['Cuando resuelvo problemas', 'trabajo a partir de hechos específicos.', 'uso principios y teorías.'],
        31 => ['En mis clases', 'uso dibujos, esquemas y diagramas.', 'uso palabras y explicaciones.'],
        32 => ['Cuando resuelvo problemas', 'lo hago de forma activa.', 'analizo y planeo antes de actuar.'],
        33 => ['Cuando estoy aprendiendo un nuevo tema', 'me concentro en los pasos necesarios para entenderlo.', 'trato de ver cómo encaja con otros temas.'],
        34 => ['Cuando leo un libro con muchos gráficos', 'examino los gráficos con atención.', 'concentro mi atención en el texto.'],
        35 => ['Aprendo más', 'trabajando con otros.', 'trabajando solo.'],
        36 => ['Cuando aprendo algo', 'lo aplico de inmediato.', 'pienso en otras formas de aplicarlo.'],
        37 => ['Me resulta más fácil aprender', 'viendo imágenes.', 'escuchando palabras.'],
        38 => ['Cuando abordo un proyecto nuevo', 'empiezo enseguida.', 'planifico antes de empezar.'],
        39 => ['Prefiero', 'aprender hechos.', 'aprender conceptos.'],
        40 => ['Cuando alguien me explica algo', 'prefiero que use imágenes o esquemas.', 'prefiero que lo diga con palabras.'],
        41 => ['En general', 'soy una persona activa.', 'soy una persona reflexiva.'],
        42 => ['Cuando trabajo en equipo', 'hablo mucho.', 'observo más que hablo.'],
        43 => ['Prefiero cursos que', 'ofrezcan contenido estructurado paso a paso.', 'presenten el contenido global desde el principio.'],
        44 => ['Cuando veo información visual', 'prefiero imágenes o esquemas.', 'prefiero palabras escritas.'],
    ];

    foreach ($questions as $num => $data) {
        // Determinar los estilos según el patrón cíclico.
        $mod = $num % 4;
        if ($mod == 0) {
            $mod = 4;
        }
        $styles = $stylemap[$mod];

        // Insertar la pregunta.
        $question = new stdClass();
        $question->questionnumber = $num;
        $question->questiontext = $data[0];
        $questionid = $DB->insert_record('learningstylesurvey_ilsquestions', $question);

        // Insertar respuesta a0 (primera opción).
        $answer0 = new stdClass();
        $answer0->questionid = $questionid;
        $answer0->answertext = $data[1];
        $answer0->style = $styles[0];
        $DB->insert_record('learningstylesurvey_ilsanswers', $answer0);

        // Insertar respuesta a1 (segunda opción).
        $answer1 = new stdClass();
        $answer1->questionid = $questionid;
        $answer1->answertext = $data[2];
        $answer1->style = $styles[1];
        $DB->insert_record('learningstylesurvey_ilsanswers', $answer1);
    }
}
