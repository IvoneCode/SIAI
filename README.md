# SIAI – Sistema de Información Académica de Informática

**Proyecto académico desarrollado por Gabriela Roldan (2026)**  
**Instituto Superior del Profesorado «Dr. Joaquín V. González»**  
**Profesorado de Educación Superior en Informática**  
**Materia: Diseño de Sistemas**

## 1. Descripción del proyecto

SIAI es una aplicación web desarrollada para organizar, administrar y consultar información académica del Profesorado de Informática.

El sistema reúne en un mismo espacio las materias del plan de estudios, sus correlatividades, los horarios de cursada y las mesas de examen.

Su propósito es facilitar el acceso a la información académica y permitir su actualización desde un panel de administración.

## 2. Objetivos

- Centralizar la información académica del profesorado.
- Facilitar la consulta de materias y correlatividades.
- Organizar los horarios de cursada por año y comisión.
- Publicar las convocatorias y mesas de examen.
- Permitir la administración de los datos mediante formularios.
- Utilizar una base de datos relacional para almacenar la información.

## 3. Tecnologías utilizadas

| Tecnología | Función |
|---|---|
| PHP | Procesamiento de datos y lógica del servidor |
| SQLite | Almacenamiento de la información académica |
| PDO | Conexión de PHP con la base de datos |
| HTML | Estructura de las páginas |
| CSS | Diseño visual y adaptación a diferentes pantallas |
| JavaScript | Interactividad, búsquedas, filtros y paginación |
| InfinityFree | Alojamiento de la aplicación web |
| GitHub | Publicación y organización del código fuente |

## 4. Funcionalidades principales

### Portal estudiantil

El portal es de acceso público y no requiere iniciar sesión.

Permite consultar:

- Las 42 materias del plan de estudios.
- Las correlatividades correspondientes.
- Los horarios de cursada.
- Las comisiones y turnos.
- Las convocatorias y mesas de examen.

### Panel de administración

Dispone de acceso restringido mediante una sesión de administrador.

Permite gestionar información académica, incluyendo materias, correlatividades y otros datos del sistema.

### Administración de exámenes

Permite:

- Registrar y modificar convocatorias.
- Programar y editar mesas de examen.
- Asignar profesores a los tribunales.
- Consultar mesas mediante búsquedas y filtros.
- Filtrar por año, comisión y convocatoria.
- Navegar los resultados mediante paginación.

## 5. Base de datos

SIAI utiliza SQLite como sistema de gestión de base de datos relacional.

La base de datos del proyecto contiene 13 tablas:

- `materias`
- `comisiones`
- `profesores`
- `aulas`
- `cursadas`
- `horarios_cursada`
- `cursada_profesor`
- `cursada_aula`
- `cambios_aula`
- `consultas`
- `convocatorias_examen`
- `mesas_examen`
- `mesa_profesor`

Estas tablas permiten relacionar las materias con las cursadas, los horarios, las aulas, los docentes y las mesas de examen.

## 6. Estructura del repositorio

```text
SIAI/
├── publica/
│   ├── siai.php
│   ├── admin.php
│   └── examenes_admin.php
├── .gitignore
└── README.md
```

**Descripción de los archivos:**

- `siai.php`: portal público de consulta académica.
- `admin.php`: panel de administración.
- `examenes_admin.php`: gestión de convocatorias, mesas y tribunales.
- `.gitignore`: reglas para excluir archivos privados y respaldos de futuras operaciones con Git.
- `README.md`: documentación general del proyecto.

Por razones de seguridad, el repositorio no incluye la base de datos utilizada en producción ni el archivo de credenciales del administrador.

## 7. Aplicación en línea

SIAI se encuentra publicado en InfinityFree.

**Acceso al portal estudiantil:**

https://siai-informatica.ifree.page/siai/siai.php

El portal permite consultar la información académica sin necesidad de iniciar sesión.

El sistema también cuenta con un panel de administración de acceso restringido para actualizar los datos y gestionar las mesas de examen.

## 8. Seguridad

El proyecto incorpora mecanismos de protección, entre ellos:

- Control de acceso mediante sesiones PHP.
- Restricción de acceso a los módulos administrativos.
- Consultas preparadas mediante PDO.
- Validación de los datos recibidos en formularios.
- Protección CSRF en operaciones administrativas.
- Separación de los archivos privados respecto de los archivos públicos de la aplicación.

La seguridad del sistema requiere mantener protegidas las credenciales y la base de datos en el servidor.

## 9. Estado del proyecto

**Versión académica 2026.**

El portal estudiantil y los módulos administrativos se encuentran publicados en InfinityFree.

La verificación integral de las operaciones de escritura, la seguridad del alojamiento y la correspondencia entre las versiones publicadas en GitHub y el servidor forman parte de las comprobaciones finales.

## 10. Autora

**Gabriela Roldan**  
Profesorado de Educación Superior en Informática  
Instituto Superior del Profesorado «Dr. Joaquín V. González»  
Año 2026

Repositorio: https://github.com/IvoneCode/SIAI
