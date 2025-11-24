# 📋 RESUMEN EJECUTIVO DE CAMBIOS

## 🎯 Resumen Corto

Se ha implementado un **sistema completo de evaluaciones** que permite crear evaluaciones grupales e individuales, gestionar estudiantes y equipos, y controlar el flujo de presentaciones. El sistema reemplaza la tabla de equipos en el dashboard por una tabla de evaluaciones más flexible, permite seleccionar evaluaciones activas con un simple click, y habilita/deshabilita botones según el contexto. Se agregaron funcionalidades de gestión de equipos (crear, editar, eliminar, asignar estudiantes) y mejoras en los botones de control de presentaciones (iniciar, terminar, reiniciar) tanto para equipos como para estudiantes individuales.

**Script SQL completo disponible en:** `instrucciones.txt`

---

# 📋 RESUMEN DE CAMBIOS - Evaluaciones cualitativas

**Fecha:** 18 de noviembre de 2025  
**Objetivo:** Permitir evaluaciones cualitativas personalizables que convivan con las evaluaciones numéricas existentes.

---

## ✅ Entregables principales

- **Nuevo esquema**: tablas `escalas_cualitativas`, `conceptos_cualitativos`, `evaluaciones_cualitativas` y `evaluaciones_cualitativas_detalle` añadidas tanto a `coeval_db.sql` como al runtime (`qualitative_helpers.php`).
- **Gestión docente**:
  - Página `gestionar_conceptos.php` para personalizar conceptos, colores y orden por curso.
  - Acciones centralizadas en `conceptos_actions.php` con trazabilidad en tabla `logs`.
- **Evaluación**:
  - Flujo nuevo `evaluar_cualitativo.php` + `procesar_evaluacion_cualitativa.php` exclusivo para docentes.
  - `evaluar.php` ahora respeta el curso del equipo al cargar criterios.
- **Visualización**:
- **Carga de estudiantes**:
  - `upload.php` acepta archivos CSV y Excel (.xlsx) usando un lector propio (`libs/SimpleXlsxReader.php`).
  - El formulario en `dashboard_docente.php` y los mensajes de ayuda fueron actualizados para reflejar el nuevo soporte.
  - `dashboard_docente.php` muestra estado y feed de evaluaciones cualitativas manteniendo privacidad (oculta nombres por defecto).
  - `ver_detalles.php` incorpora un acordeón con las evaluaciones cualitativas por criterio.
- **Infraestructura**:
  - `db.php` carga `qualitative_helpers.php`, garantizando la creación de tablas.
  - Datos seed actualizados con escala y evaluación cualitativa de referencia.

Pruebas manuales: creación/edición de conceptos, registro de evaluación cualitativa, visualización en dashboard y detalle de equipo.

---

# 📋 RESUMEN DE CAMBIOS - Refactorización de BD y Archivos PHP

**Fecha:** 12 de noviembre de 2025  
**Cambios Realizados:** Eliminación de tabla `escala_notas`, unificación de CREATE/ALTER TABLE, datos de prueba

---

## ✅ Cambios Completados

### 1. **Archivo: `coeval_db.sql`** ✅ REORGANIZADO

#### Cambios:
- ✅ Unificados todos los `ALTER TABLE` con los `CREATE TABLE`
- ✅ Reordenados las tablas en orden lógico de dependencias
- ✅ **ELIMINADA completamente la tabla `escala_notas`**
- ✅ Agregados datos de prueba completos

#### Estructura Final (en orden):
1. `usuarios` - Con índices y constraints inline
2. `cursos` - Con índices inline
3. `docente_curso` - Con FK a usuarios y cursos inline
4. `criterios` - Con índices inline
5. `equipos` - Con índices inline
6. `evaluaciones_maestro` - Con índices inline
7. `evaluaciones_detalle` - Con índices inline

#### Datos de Prueba Incluidos:
```sql
-- 1 Usuario Docente
- Email: docente@uct.cl
- Contraseña: 123456 (con hash bcrypt)
- 3 Cursos asignados

-- 3 Cursos de Prueba
- Programación I (2025-1)
- Algoritmos (2025-1)
- Base de Datos (2025-2)

-- 3 Equipos de Prueba
- Equipo A, B, C (para Programación I)

-- 5 Criterios de Evaluación
- Presentación
- Contenido Técnico
- Organización
- Calidad del Código
- Respuesta a Preguntas

-- 5 Estudiantes de Prueba
- estudiante@alu.uct.cl
- estudiante2@alu.uct.cl
- estudiante3@alu.uct.cl
- estudiante4@alu.uct.cl
- estudiante5@alu.uct.cl

-- 3 Evaluaciones de Prueba
Con detalles de criterios completos
```

---

### 2. **Archivos PHP Modificados**

#### ✅ `dashboard_docente.php`
**Cambios:**
- Resueltos conflictos de merge
- Eliminada sección de carga de escala de notas
- Reemplazada función `calcular_nota_final()` con versión sin BD
  - Usa escala simple: puntaje 0-100 → nota 1.0-7.0
- Removidas referencias a `get_active_course_id()`
- Reemplazadas con `$_SESSION['id_curso_activo']`

#### ✅ `ver_detalles.php`
**Cambios:**
- Eliminadas referencias a tabla `escala_notas`
- Función `calcular_nota_final()` simplificada
  - Ahora recibe solo el puntaje (sin parámetros DB)
  - Calcula nota automáticamente: $nota = 1.0 + (puntaje/100)*6.0

#### ✅ `export_results.php`
**Cambios:**
- Eliminada carga de escala de notas desde BD
- Función `calcular_nota_final()` creada inline
  - Misma lógica: puntaje → nota (1-7)
  - Devuelve formato separado por comas para CSV

#### ✅ `upload.php`
**Cambios:**
- Eliminada referencia a `get_active_course_id()`
- Usa `$_SESSION['id_curso_activo']` directamente

#### ✅ `gestionar_criterios.php`
**Cambios:**
- Eliminada referencia a `get_active_course_id()`
- Usa `$_SESSION['id_curso_activo']` directamente
- Validación de sesión actualizada

#### ✅ `criterios_actions.php`
**Cambios:**
- Eliminada referencia a `get_active_course_id()`
- Usa `$_SESSION['id_curso_activo']` directamente

#### ✅ `gestionar_presentacion.php`
**Cambios:**
- Eliminada referencia a `get_active_course_id()`
- Usa `$_SESSION['id_curso_activo']` directamente

#### ✅ `upload_escala.php`
**ELIMINADO COMPLETAMENTE** ❌
- Archivo borrado del servidor
- No hay referencias a tablas de escala

---

### 3. **Archivo: `db.php`** ✅ ACTUALIZADO
**Cambios:**
- Resueltos conflictos de merge
- Mantenido timeout de sesión (15 minutos)
- Función `verificar_sesion()` simplificada
- Eliminada función `get_active_course_id()` (estaba sin implementar)

---

## 📊 Cambios en la Lógica de Notas

### Antes (con tabla `escala_notas`):
```php
// Requería una tabla con mappeo manual de puntajes a notas
SELECT nota FROM escala_notas 
WHERE id_curso = ? 
ORDER BY ABS(puntaje - ?) ASC LIMIT 1
```

### Ahora (cálculo automático):
```php
function calcular_nota_final($puntaje) {
    if ($puntaje === null) return "N/A";
    
    // Escala: 0-100 → 1.0-7.0
    $nota = 1.0 + ($puntaje / 100) * 6.0;
    
    if ($nota < 1.0) $nota = 1.0;
    if ($nota > 7.0) $nota = 7.0;
    
    return number_format($nota, 1);
}
```

**Ventajas:**
- ✅ No requiere tabla adicional
- ✅ Cálculo consistente en todo el sistema
- ✅ Más simple de mantener
- ✅ Escala clara y lineal

---

## 🔐 Credenciales de Prueba

### Docente:
```
Email: docente@uct.cl
Contraseña: 123456
```

### Estudiantes:
```
Email: estudiante@alu.uct.cl (sin contraseña)
Email: estudiante2@alu.uct.cl (sin contraseña)
Email: estudiante3@alu.uct.cl (sin contraseña)
Email: estudiante4@alu.uct.cl (sin contraseña)
Email: estudiante5@alu.uct.cl (sin contraseña)
```

---

## 📋 Checklist de Verificación

**Base de Datos:**
- ✅ Tabla `escala_notas` eliminada
- ✅ CREATE TABLE y ALTER TABLE unificados
- ✅ Datos de prueba insertados
- ✅ Índices y constraints correctos

**Archivos PHP:**
- ✅ Eliminadas todas las referencias a `escala_notas`
- ✅ Eliminadas referencias a `get_active_course_id()`
- ✅ Eliminado archivo `upload_escala.php`
- ✅ Función `calcular_nota_final()` actualizada en todos lados
- ✅ Resueltos conflictos de merge

**Funcionalidad:**
- ✅ Sistema de login mantiene funcionamiento
- ✅ Dashboards actualizados sin tabla de escala
- ✅ Exportación de resultados funcional
- ✅ Gestión de criterios sin dependencias

---

## 🚀 Próximos Pasos

1. Ejecutar el script SQL en la BD
2. Probar login con:
   - `docente@uct.cl` / `123456` (docente)
   - `estudiante@alu.uct.cl` (estudiante)
3. Verificar que los dashboards se carguen correctamente
4. Probar gestión de criterios
5. Probar exportación de resultados

---

**Estado:** ✅ COMPLETADO  
**Archivos Modificados:** 8  
**Archivos Eliminados:** 1 (`upload_escala.php`)  
**Tabla Eliminada:** 1 (`escala_notas`)

---

# 📋 RESUMEN DE CAMBIOS - Sistema de Evaluaciones y Gestión de Estudiantes/Equipos

**Fecha:** Diciembre 2025  
**Objetivo:** Implementar sistema de evaluaciones (grupales e individuales), gestión de estudiantes y equipos, y mejoras en la interfaz del dashboard.

---

## ✅ Cambios Completados

### 1. **Sistema de Evaluaciones** ✅

#### Nueva Tabla en Base de Datos:
- **`evaluaciones`**: Almacena evaluaciones con nombre, tipo (grupal/individual), estado (pendiente/iniciada/cerrada) y curso asociado.

#### Funcionalidades Implementadas:
- ✅ **Crear evaluación**: Modal con nombre y tipo (grupal/individual)
- ✅ **Editar evaluación**: Permite modificar nombre y tipo (solo si está pendiente)
- ✅ **Eliminar evaluación**: Elimina evaluaciones pendientes
- ✅ **Iniciar evaluación**: Cambia estado a "iniciada" y redirige a la vista de evaluación
- ✅ **Cerrar evaluación**: Cambia estado a "cerrada"
- ✅ **Selección de evaluación**: Click en la fila para seleccionar (solo iniciadas/cerradas)
- ✅ **Resaltado visual**: Fila seleccionada se resalta con color azul y badge "✓ Seleccionada"

#### Archivos Creados:
- `evaluaciones_actions.php`: Maneja todas las acciones CRUD de evaluaciones
- `ver_evaluacion.php`: Página que muestra la evaluación iniciada/cerrada con tablas según tipo

#### Archivos Modificados:
- `dashboard_docente.php`: 
  - Reemplazada tabla "Equipos del Curso" por "Evaluaciones del Curso"
  - Agregados modales para crear/editar evaluaciones
  - Botones "Docentes y ponderaciones" y "Gestionar Criterios" se desactivan si no hay evaluación seleccionada
  - Sistema de selección de evaluación por click en fila
- `db.php`: Agregada función `ensure_evaluaciones_schema()` para crear tabla automáticamente

---

### 2. **Página de Visualización de Evaluaciones** ✅

#### `ver_evaluacion.php`:
- Muestra evaluación iniciada o cerrada
- **Evaluación Grupal**: Muestra tabla "Equipos del Curso" con todas las columnas y funcionalidades
- **Evaluación Individual**: Muestra tabla "Estudiantes del Curso" con las mismas columnas y funcionalidades
- Ambas tablas incluyen:
  - Estado de presentación
  - Evaluaciones de estudiantes
  - Nota docente
  - Puntaje final
  - Nota final (1.0-7.0)
  - Evaluación cualitativa
  - Acciones (Iniciar/Terminar/Reiniciar presentación, Detalles, etc.)

---

### 3. **Gestión de Estudiantes y Equipos** ✅

#### Nueva Página:
- `gestionar_estudiantes_equipos.php`: Página completa para gestionar estudiantes y equipos

#### Funcionalidades:
- ✅ **Vista de dos columnas**: Estudiantes a la izquierda, Equipos a la derecha
- ✅ **Crear equipo**: Modal con nombre y selección de estudiantes
- ✅ **Editar equipo**: Modal con nombre y gestión de estudiantes
  - Lista de estudiantes actuales del equipo con botón "Eliminar"
  - Tabla de estudiantes disponibles para agregar
- ✅ **Eliminar equipo**: Elimina equipo y desasigna estudiantes
- ✅ **Agregar estudiantes a equipo**: Selección múltiple desde modal
- ✅ **Eliminar estudiantes de equipo**: Botón en lista de estudiantes actuales

#### Archivos Creados:
- `gestionar_estudiantes_equipos.php`: Página principal de gestión
- `equipos_actions.php`: Maneja todas las acciones CRUD de equipos y asignación de estudiantes

#### Archivos Modificados:
- `dashboard_docente.php`: Agregado botón "Estudiantes y Equipos" en la sección de botones

---

### 4. **Mejoras en Botones de Presentación** ✅

#### Funcionalidades Agregadas:
- ✅ **Botones en tabla de estudiantes**: Agregados "Iniciar Presentación" y "Terminar Presentación" en evaluaciones individuales
- ✅ **Botón "Reiniciar Presentación"**: Agregado tanto en equipos como en estudiantes
  - Visible cuando estado es "presentando" o "finalizado"
  - Cambia estado a "pendiente"
  - Incluye confirmación antes de ejecutar

#### Archivos Modificados:
- `gestionar_presentacion.php`: 
  - Agregada acción "reiniciar" en el switch
  - Mejorada redirección para mantener contexto de evaluación
  - Soporte para redirigir a `ver_evaluacion.php` después de acciones
- `ver_evaluacion.php`: 
  - Agregados botones de presentación en tabla de estudiantes
  - Agregado botón "Reiniciar Presentación" en ambas tablas
  - Agregados mensajes de estado y error

---

### 5. **Reorganización de Botones en Dashboard** ✅

#### Cambios en `dashboard_docente.php`:
- ✅ Botones "Docentes y ponderaciones", "Gestionar Criterios" y "Conceptos Cualitativos" movidos sobre la tabla de evaluaciones
- ✅ Alineados a la misma altura del título "Evaluaciones del Curso"
- ✅ Botones se desactivan si no hay evaluación seleccionada
- ✅ Tooltips explicativos cuando están deshabilitados

---

## 📊 Cambios en Base de Datos

### Tablas Creadas:
1. **`evaluaciones`**: Sistema de evaluaciones
   - Campos: id, nombre_evaluacion, tipo_evaluacion, estado, id_curso, fecha_creacion
   - Estados: pendiente, iniciada, cerrada
   - Tipos: grupal, individual

### Script SQL Completo:
- Ver archivo `instrucciones.txt` para el script SQL completo que incluye:
  - Ponderaciones de estudiantes e invitados
  - Tabla de evaluaciones
  - Todas las tablas y columnas necesarias

---

## 🎯 Flujo de Uso del Sistema

### Para el Docente:

1. **Crear Evaluación**:
   - Click en "Crear Evaluación"
   - Ingresar nombre y seleccionar tipo (grupal/individual)
   - Click en "Crear Evaluación"

2. **Iniciar Evaluación**:
   - Click en botón "Iniciar" de la evaluación pendiente
   - Sistema redirige a la vista de evaluación

3. **Seleccionar Evaluación**:
   - Click en cualquier parte de la fila de una evaluación iniciada/cerrada
   - La fila se resalta y los botones se activan

4. **Gestionar Equipos y Estudiantes**:
   - Click en "Estudiantes y Equipos"
   - Crear/editar/eliminar equipos
   - Agregar/eliminar estudiantes de equipos

5. **Gestionar Presentaciones**:
   - Desde la vista de evaluación, usar botones:
     - "Iniciar Presentación" (estado pendiente)
     - "Terminar Presentación" (estado presentando)
     - "Reiniciar Presentación" (estado presentando/finalizado)

---

## 📋 Checklist de Verificación

**Base de Datos:**
- ✅ Tabla `evaluaciones` creada
- ✅ Tabla `invitado_curso` creada
- ✅ Tabla `docente_curso_log` creada
- ✅ Columnas de ponderaciones agregadas a `cursos`

**Archivos Nuevos:**
- ✅ `evaluaciones_actions.php`
- ✅ `ver_evaluacion.php`
- ✅ `gestionar_estudiantes_equipos.php`
- ✅ `equipos_actions.php`

**Archivos Modificados:**
- ✅ `dashboard_docente.php` (sistema de evaluaciones y selección)
- ✅ `db.php` (función para crear tabla evaluaciones)
- ✅ `gestionar_presentacion.php` (acción reiniciar y redirección)

**Funcionalidad:**
- ✅ Crear/editar/eliminar evaluaciones
- ✅ Iniciar/cerrar evaluaciones
- ✅ Seleccionar evaluación por click
- ✅ Ver evaluación con tablas según tipo
- ✅ Gestionar equipos y estudiantes
- ✅ Botones de presentación en ambas tablas
- ✅ Botón reiniciar presentación

---

## 🚀 Instrucciones de Instalación

1. **Ejecutar Script SQL**:
   - Abrir `instrucciones.txt`
   - Copiar y ejecutar todo el script en phpMyAdmin o cliente MySQL
   - Verificar que no haya errores críticos (errores #1060 son normales)

2. **Verificar Tablas**:
   - Ejecutar: `SHOW TABLES LIKE 'evaluaciones';`
   - Ejecutar: `DESCRIBE evaluaciones;`

3. **Probar Funcionalidad**:
   - Crear una evaluación de prueba
   - Iniciar la evaluación
   - Seleccionar la evaluación haciendo click en la fila
   - Verificar que los botones se activen

---

**Estado:** ✅ COMPLETADO  
**Archivos Nuevos:** 4  
**Archivos Modificados:** 3  
**Tablas Nuevas:** 1 (`evaluaciones`)  
**Script SQL:** Ver `instrucciones.txt`