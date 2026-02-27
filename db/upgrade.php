<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_learningstylesurvey_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // 2025070401: Se elimina creación de tablas path_files y path_evaluations (ahora están en install.xml).
    // Conservado para instalaciones antiguas que pudieran no tenerlas.
    if ($oldversion < 2025070401) {
        $table1 = new xmldb_table('learningstylesurvey_path_files');
        if (!$dbman->table_exists($table1)) {
            // Esta rama solo ejecutará en sitios muy antiguos sin la tabla.
            $table1->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table1->add_field('pathid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table1->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table1->add_field('steporder', XMLDB_TYPE_INTEGER, '10', null, false, null, '0');
            $table1->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table1->add_key('path_fk', XMLDB_KEY_FOREIGN, ['pathid'], 'learningstylesurvey_paths', ['id']);
            $dbman->create_table($table1);
        }

        $table2 = new xmldb_table('learningstylesurvey_path_evaluations');
        if (!$dbman->table_exists($table2)) {
            $table2->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table2->add_field('pathid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table2->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table2->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table2->add_key('path_fk', XMLDB_KEY_FOREIGN, ['pathid'], 'learningstylesurvey_paths', ['id']);
            $dbman->create_table($table2);
        }

        upgrade_mod_savepoint(true, 2025070401, 'learningstylesurvey');
    }

    // Versión 2025090401 - REPARACIÓN COMPLETA DE CAMPOS FALTANTES
    if ($oldversion < 2025090401) {
        
        // 1. ASEGURAR que table learningstylesurvey_resources tenga campo userid
        $resourcestable = new xmldb_table('learningstylesurvey_resources');
        if ($dbman->table_exists($resourcestable)) {
            $useridfield = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            if (!$dbman->field_exists($resourcestable, $useridfield)) {
                $dbman->add_field($resourcestable, $useridfield);
            }
            
            $timecreatedfield = new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            if (!$dbman->field_exists($resourcestable, $timecreatedfield)) {
                $dbman->add_field($resourcestable, $timecreatedfield);
            }
        }

        // 2. ASEGURAR que tabla learningstylesurvey_temas tenga campo userid 
        $temastable = new xmldb_table('learningstylesurvey_temas');
        if ($dbman->table_exists($temastable)) {
            $useridfield = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            if (!$dbman->field_exists($temastable, $useridfield)) {
                $dbman->add_field($temastable, $useridfield);
            }
        }

        // 3. ASEGURAR que tabla learningstylesurvey_paths tenga campo cmid
        $pathstable = new xmldb_table('learningstylesurvey_paths');
        if ($dbman->table_exists($pathstable)) {
            $cmidfield = new xmldb_field('cmid', XMLDB_TYPE_INTEGER, '10', null, false, null, null);
            if (!$dbman->field_exists($pathstable, $cmidfield)) {
                $dbman->add_field($pathstable, $cmidfield);
            }
        }

        // 4. ASEGURAR que tabla learningstylesurvey_quiz_results tenga campo timemodified
        $resultstable = new xmldb_table('learningstylesurvey_quiz_results');
        if ($dbman->table_exists($resultstable)) {
            $timemodifiedfield = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, false, null, '0');
            if (!$dbman->field_exists($resultstable, $timemodifiedfield)) {
                $dbman->add_field($resultstable, $timemodifiedfield);
            }
        }

        // 5. ASEGURAR que tabla learningpath_steps tenga campos de redirección
        $stepstable = new xmldb_table('learningpath_steps');
        if ($dbman->table_exists($stepstable)) {
            $passfield = new xmldb_field('passredirect', XMLDB_TYPE_INTEGER, '10', null, false, null, null);
            if (!$dbman->field_exists($stepstable, $passfield)) {
                $dbman->add_field($stepstable, $passfield);
            }
            
            $failfield = new xmldb_field('failredirect', XMLDB_TYPE_INTEGER, '10', null, false, null, null);
            if (!$dbman->field_exists($stepstable, $failfield)) {
                $dbman->add_field($stepstable, $failfield);
            }
            
            $istest = new xmldb_field('istest', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            if (!$dbman->field_exists($stepstable, $istest)) {
                $dbman->add_field($stepstable, $istest);
            }
        }

        upgrade_mod_savepoint(true, 2025090401, 'learningstylesurvey');
    }

    // Versión 2025091020 - Tablas ILS + rediseño de responses
    if ($oldversion < 2025091020) {

        // 1. Crear tabla learningstylesurvey_ilsquestions
        $table = new xmldb_table('learningstylesurvey_ilsquestions');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('questionnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('questiontext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // 2. Crear tabla learningstylesurvey_ilsanswers (sin optionindex)
        $table2 = new xmldb_table('learningstylesurvey_ilsanswers');
        if (!$dbman->table_exists($table2)) {
            $table2->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table2->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table2->add_field('answertext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table2->add_field('style', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table2->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table2->add_key('questionid_fk', XMLDB_KEY_FOREIGN, ['questionid'], 'learningstylesurvey_ilsquestions', ['id']);
            $dbman->create_table($table2);
        }

        // 3. Migrar tabla learningstylesurvey_responses para guardar top 3 estilos
        $responses = new xmldb_table('learningstylesurvey_responses');
        if ($dbman->table_exists($responses)) {
            // Eliminar datos antiguos (ya no son compatibles con el nuevo esquema)
            $DB->delete_records('learningstylesurvey_responses');

            // Eliminar campos viejos si existen
            $questionidfield = new xmldb_field('questionid');
            if ($dbman->field_exists($responses, $questionidfield)) {
                $dbman->drop_field($responses, $questionidfield);
            }
            $responsefield = new xmldb_field('response');
            if ($dbman->field_exists($responses, $responsefield)) {
                $dbman->drop_field($responses, $responsefield);
            }

            // Agregar campos nuevos
            $stylefield = new xmldb_field('style', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            if (!$dbman->field_exists($responses, $stylefield)) {
                $dbman->add_field($responses, $stylefield);
            }
            $scorefield = new xmldb_field('score', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            if (!$dbman->field_exists($responses, $scorefield)) {
                $dbman->add_field($responses, $scorefield);
            }
            $rankingfield = new xmldb_field('ranking', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
            if (!$dbman->field_exists($responses, $rankingfield)) {
                $dbman->add_field($responses, $rankingfield);
            }
        }

        // 4. Ejecutar seeder para insertar las preguntas y respuestas del ILS
        require_once(__DIR__ . '/install.php');
        xmldb_learningstylesurvey_install();

        upgrade_mod_savepoint(true, 2025091020, 'learningstylesurvey');
    }


    return true;
}
