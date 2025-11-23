# Documentación del Proyecto – Plataforma de Coevaluación TEC-UCT

Este directorio contiene la documentación generada automáticamente mediante PHPDocumentor.

## Cómo regenerar la documentación

Para regenerar la documentación, asegúrate de tener PHPDocumentor instalado en tu entorno. Puedes instalarlo globalmente usando Composer:

```bash
composer global require phpdocumentor/phpdocumentor
```

Luego, ejecuta el siguiente comando en la raíz del proyecto:

```bash
phpdoc -c phpdoc.xml
```

El archivo `phpdoc.xml` está configurado para evitar rutas absolu tas, usar exclusiones adecuadas y generar la documentación en la carpeta `/docs`.

### Estructura

- `/docs/index.html` → Archivo principal de la documentación
- `/docs/classes` → Clases y funciones documentadas
- `/docs/namespaces` → Espacios de nombres y organización
- `/docs/files` → Todos los archivos PHP documentados del proyecto

### Notas

- No editar los archivos HTML generados automáticamente.
- Cualquier cambio en el código PHP debe ser reflejado ejecutando nuevamente PHPDocumentor.
- Si tienes problemas para regenerar la documentación, verifica que PHPDocumentor esté correctamente instalado y que estés ejecutando el comando desde la raíz del proyecto.
- No modifiques ninguna otra parte del proyecto.
