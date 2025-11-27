# Plataforma de Coevaluación UCT

## Descripción General

La **Plataforma de Coevaluación UCT** es un sistema académico diseñado para facilitar la coevaluación universitaria. Permite la evaluación cuantitativa y cualitativa de estudiantes en entornos de trabajo en equipo, con gestión de cursos, equipos, presentaciones y rúbricas personalizables. La plataforma es utilizada por docentes para configurar evaluaciones y por estudiantes para realizar coevaluaciones en un flujo estructurado de evaluación.

## Funcionalidades Principales

- Gestión de cursos
- Gestión de equipos
- Evaluaciones (crear, iniciar, cerrar y eliminar)
- Rúbricas, criterios, ponderaciones
- Evaluación cuantitativa y cualitativa
- Presentaciones con temporizador
- Exportación a Excel (PhpSpreadsheet)
- Dashboard docente y dashboard estudiante
- Seguridad por roles
- Logs de acciones administrativas

## Tecnologías Utilizadas

- PHP 8+
- MySQL / MariaDB
- Bootstrap 5
- PhpSpreadsheet
- JavaScript básico
- Arquitectura MVC legacy

## Arquitectura y Estructura del Proyecto

El proyecto sigue una estructura organizada para separar la lógica de backend y frontend:

- `backend/controllers/`: Controladores para manejar la lógica de negocio, como `DashboardController.php`.
- `backend/models/`: Modelos para cálculos y lógica de datos, como `EvaluacionCalculo.php`.
- `backend/config/db.php`: Configuración de la conexión a la base de datos.
- `frontend/views/`: Vistas para la interfaz de usuario (archivos PHP que renderizan HTML).
- `public/assets/css/`, `public/assets/js/`: Archivos estáticos de estilos y scripts.
- Archivos principales en la raíz: `admin_actions.php`, `criterios_actions.php`, `equipos_actions.php`, etc., que manejan acciones específicas del sistema.

## Árbol de Directorios (Resumido)

```
Coevaluaci-n/
├── backend/
│   ├── controllers/
│   │   └── DashboardController.php
│   ├── models/
│   │   └── EvaluacionCalculo.php
│   └── config/
│       └── db.php
├── docs/
│   └── README.md
├── frontend/
│   └── views/
├── img/
├── libs/
│   ├── SimpleXlsxReader.php
│   └── tcpdf/
├── public/
│   └── assets/
│       ├── css/
│       └── js/
├── tools/
├── admin_actions.php
├── criterios_actions.php
├── equipos_actions.php
├── evaluaciones_actions.php
├── dashboard_docente.php
├── dashboard_estudiante.php
├── index.php
├── login.php
├── logout.php
├── export_results.php
└── ...
```

## Requisitos del Sistema

- XAMPP / WAMP / LAMPP
- PHP 8+
- MySQL / MariaDB
- Composer (si corresponde)

## Instalación y Configuración

1. Clona el repositorio en tu directorio local.
2. Importa el archivo `coeval_db.sql` en tu servidor MySQL para crear la base de datos.
3. Configura la conexión a la base de datos en `backend/config/db.php` con tus credenciales locales.
4. Coloca el proyecto en el directorio `htdocs` de tu servidor local (XAMPP, etc.).
5. Accede al sistema mediante un navegador web en `http://localhost/Coevaluaci-n`.
6. Crea un curso, configura equipos y evaluaciones para comenzar a usar la plataforma.

## Flujo de Uso

### Docente
1. Inicia sesión como docente.
2. Crea o selecciona un curso.
3. Gestiona equipos y asigna estudiantes.
4. Configura rúbricas con criterios y ponderaciones.
5. Inicia evaluaciones y monitorea el progreso desde el dashboard.

### Estudiante
1. Inicia sesión como estudiante.
2. Únete a un equipo en el curso asignado.
3. Participa en presentaciones con temporizador.
4. Realiza evaluaciones cuantitativas y cualitativas de compañeros.
5. Visualiza resultados en el dashboard estudiante.

### Evaluación y Presentación
- Las evaluaciones se inician por el docente y se cierran automáticamente o manualmente.
- Durante presentaciones, un temporizador controla el tiempo disponible.
- Los estudiantes evalúan usando rúbricas predefinidas.

### Visualización y Exportación de Resultados
- Resultados se muestran en dashboards personalizados.
- Exporta datos a Excel usando PhpSpreadsheet para análisis externo.

## Capturas

### Dashboard Docente
(Agregar imagen aquí)

### Dashboard Estudiante
(Agregar imagen aquí)

### Configuración de Rúbricas
(Agregar imagen aquí)

### Evaluación en Progreso
(Agregar imagen aquí)

## Estructura de Base de Datos

La base de datos incluye las siguientes tablas principales y sus relaciones:

- `usuarios`: Almacena información de docentes y estudiantes, con roles definidos.
- `cursos`: Gestiona los cursos disponibles, asociados a docentes.
- `equipos`: Agrupa estudiantes en equipos dentro de cursos.
- `evaluaciones`: Define evaluaciones activas o cerradas, con fechas y configuraciones.
- `evaluaciones_maestro`: Registra evaluaciones realizadas por estudiantes.
- `evaluaciones_detalle`: Detalla criterios y calificaciones en evaluaciones.
- `escalas_cualitativas`: Define escalas para evaluaciones cualitativas.
- `conceptos_cualitativos`: Almacena conceptos cualitativos asociados a escalas.
- `logs`: Registra acciones administrativas para auditoría.

Las relaciones principales incluyen: usuarios a cursos (muchos a uno), cursos a equipos (uno a muchos), equipos a evaluaciones (muchos a muchos a través de evaluaciones_maestro), etc.

## Limitaciones

- Sistema legacy sin framework moderno, lo que limita la escalabilidad.
- Validación mínima en entradas de usuario.
- Dependencia del navegador para el funcionamiento del temporizador.
- Código acoplado en ciertas zonas, dificultando modificaciones.

## Contribuyentes

- Catalina Salas
- Paola Montes
- Nicolás Huenchullán
- Benjamín C. Dos Santos
- Mauricio Mora

## Algunos Prompts Utilizados en el Desarrollo

- “Optimizar validación de criterios para evitar ponderaciones incorrectas.”
- “Corregir acceso según rol para impedir que estudiantes entren al dashboard docente.”
- “Arreglar error de tiempo agotado en evaluar.php sin modificar estructura del sistema.”
- “Generar modal de confirmación para eliminar evaluaciones sin confirm() del navegador.”
- “Implementar delete seguro en evaluaciones usando solo columnas reales de la base de datos.”
- “Mejorar responsividad del dashboard sin modificar lógica heredada.”
- “Reparar acción de terminar presentación sin cerrar sesión.”

## Licencia

Este proyecto está bajo la Licencia MIT. Consulta el archivo LICENSE para más detalles.
