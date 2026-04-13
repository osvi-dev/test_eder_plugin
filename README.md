# Prisma — Plugin de Aprendizaje Adaptativo para Moodle

**Prisma** es un plugin de actividad para Moodle que detecta el estilo de aprendizaje de cada estudiante y le asigna una ruta personalizada de recursos y evaluaciones.

> Requiere Moodle 4.0 o superior · Versión 1.0 · Licencia GPL v3

---

## ¿Qué hace?

1. El estudiante responde una **encuesta ILS** (Índice de Estilos de Aprendizaje).
2. El sistema detecta su estilo dominante (activo, reflexivo, visual, verbal, etc.).
3. Se le asigna una **ruta de aprendizaje** con pasos que incluyen recursos y evaluaciones.
4. El estudiante avanza paso a paso; si reprueba un examen, recibe material de refuerzo o puede reintentar (máximo 3 veces).
5. El profesor puede ver el progreso de cada estudiante, gestionar rutas y consultar estadísticas.

---

## Instalación

1. Descarga o clona este repositorio.
2. Copia la carpeta en `{moodle_root}/mod/learningstylesurvey/`.
3. Inicia sesión como administrador en tu Moodle.
4. Ve a **Administración del sitio → Notificaciones** para ejecutar la instalación de la base de datos.
5. Agrega el módulo a cualquier curso desde **Agregar actividad o recurso → Prisma**.

---

## Estructura del proyecto

```
learningstylesurvey/
├── view.php              # Menú principal (estudiante y profesor)
├── surveyform.php        # Encuesta de estilos de aprendizaje
├── results.php           # Resultados de la encuesta
├── estadisticas.php      # Estadísticas del curso (solo profesor)
├── bloqueados.php        # Estudiantes bloqueados por 3 fallos
├── desbloquear.php       # Desbloquear un estudiante manualmente
│
├── path/                 # Módulo de rutas de aprendizaje
│   ├── vista_estudiante.php    # Vista principal del estudiante
│   ├── learningpath.php        # Gestión de rutas (profesor)
│   ├── createsteproute.php     # Crear pasos en una ruta
│   ├── edit_learningpath.php   # Editar una ruta existente
│   ├── organizar_ruta.php      # Reordenar pasos
│   └── siguiente.php           # Lógica de avance al siguiente paso
│
├── quiz/                 # Módulo de evaluaciones
│   ├── crear_examen.php        # Crear un examen
│   ├── manage_quiz.php         # Editar/eliminar exámenes
│   ├── responder_quiz.php      # Responder un examen (estudiante)
│   └── guardar_examen.php      # Guardar resultados
│
├── resource/             # Módulo de recursos didácticos
│   ├── uploadresource.php      # Subir archivos
│   ├── viewresources.php       # Ver recursos del curso
│   ├── ver_recurso.php         # Ver un recurso individual
│   └── temas.php               # Gestión de temas
│
├── db/                   # Base de datos
│   ├── install.xml             # Esquema de tablas inicial
│   ├── install.php             # Datos iniciales
│   └── upgrade.php             # Actualizaciones de la BD
│
├── lang/en/              # Cadenas de idioma
├── pix/                  # Ícono del plugin
├── lib.php               # Funciones requeridas por Moodle
├── locallib.php          # Funciones internas del plugin
├── mod_form.php          # Formulario de configuración del módulo
└── version.php           # Versión y dependencias
```

---

## Roles y permisos

| Rol              | Acceso                                                                 |
|------------------|------------------------------------------------------------------------|
| **Estudiante**   | Encuesta, ruta de aprendizaje, recursos, evaluaciones, ver resultados  |
| **Profesor**     | Todo lo anterior + gestión de rutas, recursos, exámenes, estadísticas  |
| **Administrador**| Todo lo anterior + herramientas de verificación del sistema            |

---

## Flujo del estudiante

```
Ingresa al módulo
    → Responde la encuesta ILS
        → Se detecta su estilo de aprendizaje
            → Se le asigna una ruta personalizada
                → Avanza paso a paso (recurso → evaluación)
                    → Aprueba: avanza al siguiente paso
                    → Reprueba: ve material de refuerzo, reintenta (máx. 3 veces)
                        → 3 fallos: bloqueado, contactar al profesor
                → Completa la ruta
```

---

## Desarrollo y contribución

- Repositorio: [github.com/osvi-dev/test_eder_plugin](https://github.com/osvi-dev/test_eder_plugin)
- Para reportar errores o proponer mejoras, abre un **Issue**.
- Sigue la [guía de estilo de Moodle](https://moodledev.io/docs/guides/codingstyle) al contribuir código.

---

## Recursos útiles

- [Documentación para desarrolladores de Moodle](https://moodledev.io/)
- [API de base de datos de Moodle](https://moodledev.io/docs/apis/core/dml)
- [Guía de plugins de actividad](https://moodledev.io/docs/apis/plugins/activity)

## Atribuciones
Los iconos utilizados en este plugin han sido diseñados por:
* Freepik desde [Flaticon](https://www.flaticon.es/) 