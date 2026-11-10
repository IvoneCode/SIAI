# SIAI — Sistema de Información Académica de Informática

Proyecto académico desarrollado por Gabriela Roldan (2026) para la materia Diseño de Sistemas del Profesorado de Educación Superior en Informática, Instituto Superior del Profesorado «Dr. Joaquín V. González».

## Descripción

Aplicación web para consultar y administrar información académica del Profesorado de Informática. Reúne materias, correlatividades, horarios de cursada y mesas de examen.

## Tecnologías

PHP, SQLite (PDO), HTML, CSS y JavaScript.

## Módulos

- `publica/siai.php`: portal de consulta para estudiantes, sin inicio de sesión.
- `publica/admin.php`: panel administrativo con autenticación.
- `publica/examenes_admin.php`: gestión de convocatorias, mesas y tribunales de examen.

## Ejecución y configuración

Requiere un servidor PHP con extensión PDO SQLite habilitada. La aplicación necesita una base de datos SQLite con el esquema correspondiente y un archivo privado `admin.json` con el hash de la contraseña administrativa.

**Por seguridad, la base de datos y `admin.json` no se incluyen en este repositorio.** No publiques esos archivos ni los coloques en una carpeta accesible por URL. Las rutas de los archivos PHP incluidos corresponden a la estructura local original (`datos/`); en el despliegue de InfinityFree se utiliza una carpeta privada `siai_privado/` y deben adaptarse las rutas en los tres módulos.

Este repositorio documenta el código de los módulos principales; **no es una distribución autónoma lista para ejecutar sin configurar la base y las credenciales**.

## Sitio de demostración

[Portal de SIAI](https://siai-informatica.ifree.page/siai/siai.php)

## Nota sobre los datos

Los horarios, aulas y mesas de examen deben contrastarse con la documentación institucional correspondiente antes de utilizarlos como información oficial.
