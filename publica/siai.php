<?php
// =============================================================
// 1. CONFIGURACIÓN Y VARIABLES INICIALES
// =============================================================
declare(strict_types=1);

$dbFile = dirname(__DIR__) . '/datos/siai.sqlite';
$error = null;
$materias = [];
$cursadas = [];
$horariosError = null;
$examenesError = null;
$mesasExamen = [];
$convocatoriasExamen = [];
$mensaje = '';

// =============================================================
// 2. FUNCIÓN DE SEGURIDAD PARA MOSTRAR TEXTO EN HTML
// =============================================================
function h(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Convierte los tramos de una cursada en un resumen breve.
function resumenHorarios(array $horarios): string
{
    if (!$horarios) return 'Horario a confirmar';
    $abreviaturas = ['Lunes'=>'Lun','Martes'=>'Mar','Miércoles'=>'Mié','Jueves'=>'Jue','Viernes'=>'Vie'];
    $partes = [];
    foreach ($horarios as $h) {
        $dia = $abreviaturas[$h['dia']] ?? $h['dia'];
        $hora = ($h['hora_inicio'] && $h['hora_fin'])
            ? $h['hora_inicio'] . '–' . $h['hora_fin'] : 'hora a confirmar';
        $partes[] = $dia . ' ' . $hora;
    }
    return implode(' · ', $partes);
}

// =============================================================
// 3. SESIÓN Y PROTECCIÓN DEL FORMULARIO DE CONSULTAS
// =============================================================
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_siai'])) {
    $_SESSION['csrf_siai'] = bin2hex(random_bytes(16));
}

// =============================================================
// 4. CONEXIÓN A SQLITE Y LECTURA DE MATERIAS Y CURSADAS
// =============================================================
if (is_file($dbFile)) {
    try {
        $db = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $materias = $db->query(<<<'SQL'
            SELECT id, anio, nombre, regimen, tp_cursar, finales_cursar, finales_rendir
            FROM materias
            ORDER BY anio, nombre
            SQL)->fetchAll();

        // Horarios 2026: cada cursada puede tener varios tramos semanales.
        // La consulta se prepara una sola vez y los filtros usan parámetros.
        try {
            $sqlCursadas = <<<'SQL'
                SELECT c.id, m.nombre AS materia, m.anio AS anio_materia,
                       co.codigo AS comision, co.turno,
                       (
                           SELECT GROUP_CONCAT(p.nombre || ' ' || p.apellido, ', ')
                           FROM cursada_profesor cp
                           JOIN profesores p ON p.id = cp.profesor_id
                           WHERE cp.cursada_id = c.id
                       ) AS docentes,
                       (
                           SELECT a.nombre || COALESCE(' · ' || a.piso, '')
                           FROM cursada_aula ca
                           JOIN aulas a ON a.id = ca.aula_id
                           WHERE ca.cursada_id = c.id
                       ) AS aula
                FROM cursadas c
                JOIN materias m ON m.id = c.materia_id
                JOIN comisiones co ON co.id = c.comision_id
                WHERE c.anio_lectivo = 2026
                ORDER BY m.anio, co.codigo, m.nombre COLLATE NOCASE
                SQL;
            $cursadas = $db->query($sqlCursadas)->fetchAll();

            $sqlTramos = <<<'SQL'
                SELECT dia, modulo_inicio, modulo_fin, hora_inicio, hora_fin
                FROM horarios_cursada
                WHERE cursada_id = ?
                ORDER BY CASE dia
                    WHEN 'Lunes' THEN 1 WHEN 'Martes' THEN 2
                    WHEN 'Miércoles' THEN 3 WHEN 'Jueves' THEN 4
                    WHEN 'Viernes' THEN 5 ELSE 6 END, modulo_inicio
                SQL;
            $consultaTramos = $db->prepare($sqlTramos);
            foreach ($cursadas as &$cursada) {
                $consultaTramos->execute([(int) $cursada['id']]);
                $cursada['horarios'] = $consultaTramos->fetchAll();
            }
            unset($cursada);
        } catch (Throwable $e) {
            $horariosError = 'No se pudieron cargar los horarios 2026. Revisá la importación.';
        }

        // =============================================================
        // 5. LECTURA DE CONVOCATORIAS Y MESAS DE EXAMEN
        // =============================================================
        // La consulta es de solo lectura. Los cambios se realizan en
        // examenes_admin.php y quedan guardados en SQLite.
        try {
            $convocatoriasExamen = $db->query(<<<'SQL'
                SELECT id, anio_lectivo, nombre, fecha_inicio, fecha_fin
                FROM convocatorias_examen
                ORDER BY anio_lectivo DESC, fecha_inicio, nombre
                SQL)->fetchAll();

            $mesasExamen = $db->query(<<<'SQL'
                SELECT me.id, m.anio AS anio_materia,
                       m.nombre AS materia, co.codigo AS comision,
                       co.turno, ce.id AS convocatoria_id,
                       ce.anio_lectivo, ce.nombre AS convocatoria,
                       ce.fecha_inicio AS periodo_inicio,
                       ce.fecha_fin AS periodo_fin,
                       me.fecha, me.hora_inicio, me.estado,
                       me.observaciones,
                       a.nombre AS aula, a.piso AS piso_aula,
                       (SELECT GROUP_CONCAT(
                           CASE mp.rol
                               WHEN 'presidente' THEN 'Presidente'
                               WHEN 'vocal' THEN 'Vocal'
                               ELSE 'Suplente' END
                           || ': ' || p.nombre || ' ' || p.apellido, ' · ')
                        FROM mesa_profesor mp
                        JOIN profesores p ON p.id = mp.profesor_id
                        WHERE mp.mesa_id = me.id) AS tribunal
                FROM mesas_examen me
                JOIN materias m ON m.id = me.materia_id
                JOIN comisiones co ON co.id = me.comision_id
                JOIN convocatorias_examen ce ON ce.id = me.convocatoria_id
                LEFT JOIN aulas a ON a.id = me.aula_id
                ORDER BY ce.anio_lectivo DESC, ce.fecha_inicio,
                         m.anio, co.codigo, me.fecha, m.nombre
                SQL)->fetchAll();
        } catch (Throwable $e) {
            $examenesError = 'No se pudieron consultar las mesas. Verificá que se haya ejecutado la ampliación de exámenes.';
        }

        // =============================================================
        // 6. RECEPCIÓN Y VALIDACIÓN DE CONSULTAS
        // =============================================================
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!hash_equals($_SESSION['csrf_siai'], (string) ($_POST['csrf'] ?? ''))) {
                throw new RuntimeException('Sesión inválida. Actualizá la página.');
            }

            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $correo = trim((string) ($_POST['correo'] ?? ''));
            $asunto = trim((string) ($_POST['asunto'] ?? ''));
            $texto = trim((string) ($_POST['mensaje'] ?? ''));

            if (
                $nombre === '' || strlen($nombre) > 100 ||
                !filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 150 ||
                $asunto === '' || strlen($asunto) > 150 ||
                $texto === '' || strlen($texto) > 2000
            ) {
                throw new RuntimeException('Revisá los datos de la consulta.');
            }

            $db->prepare(<<<'SQL'
                INSERT INTO consultas (nombre, correo, asunto, mensaje)
                VALUES (?, ?, ?, ?)
                SQL)->execute([$nombre, $correo, $asunto, $texto]);

            $mensaje = 'Consulta guardada correctamente en la base de datos. No se envió por correo.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} else {
    $error = 'No se encontró la base de datos. Revisá la instalación de SIAI.';
}

// =============================================================
// 6. ETIQUETAS DE RÉGIMEN Y PERÍODO
// =============================================================
$regimenes = [
    'anual' => 'Anual',
    'cuatrimestral' => 'Cuatrimestral',
    'sin_definir' => 'Régimen a confirmar',
];
$periodos = [
    'anual' => 'Anual',
    '1' => '1.º cuatrimestre',
    '2' => '2.º cuatrimestre',
    'sin_definir' => 'Período a confirmar',
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SIAI | Informática</title>

    <!-- =======================================================
         7. ESTILOS VISUALES DEL PORTAL ESTUDIANTIL
         ======================================================= -->
    <style>
        :root {
            --v: #61427f;
            --bg: #f7f5fa;
            --line: #e2dce9;
            --ink: #30283c;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 14px/1.4 Arial, sans-serif; }
        header { background: var(--v); color: #fff; padding: 10px 16px; }
        header strong { font-size: 17px; }
        header small { display: block; }
        main { max-width: 960px; margin: 10px auto; padding: 0 10px; }
        nav { display: flex; gap: 6px; }
        button { cursor: pointer; border: 1px solid var(--line); background: #fff; border-radius: 5px; padding: 8px 12px; font: inherit; }
        button.active, button[type="submit"] { background: var(--v); color: #fff; }
        section { background: #fff; border: 1px solid var(--line); border-radius: 7px; padding: 12px; margin-top: 8px; }
        section[hidden], [hidden] { display: none !important; }
        h1 { font-size: 16px; margin: 0 0 8px; }
        label { display: block; font-size: 12px; font-weight: bold; margin: 7px 0 3px; }
        input, textarea, select { padding: 8px; border: 1px solid #ccc; border-radius: 5px; font: inherit; }
        input, textarea { width: 100%; }
        textarea { min-height: 70px; }
        .item { border-bottom: 1px solid #eee; padding: 7px 2px; }
        .item summary { cursor: pointer; font-weight: 600; }
        .req { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 7px; font-size: 12px; }
        .req div { background: var(--bg); padding: 7px; border-radius: 5px; }
        .muted { font-size: 12px; color: #6d6277; }
        .err { color: #9b2335; }
        .ok { color: #21603c; }
        .filter { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .filter input { flex: 1; min-width: 160px; }
        p { margin: 6px 0; }
        .pill { display: inline-block; padding: 2px 7px; background: #f1eaf6; border-radius: 5px; color: #51346b; font-size: 12px; }
        /* Horarios: filas compactas, dos columnas y detalle desplegable */
        .schedule { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 7px; margin-top: 8px; }
        .schedule-card { border: 1px solid var(--line); border-radius: 6px; min-width: 0; overflow: hidden; }
        .schedule-card summary { cursor: pointer; padding: 9px 11px; list-style: none; }
        .schedule-card summary::-webkit-details-marker { display: none; }
        .schedule-card summary:focus-visible { outline: 2px solid var(--v); outline-offset: -2px; }
        .schedule-card summary::after { content: '⌄'; float: right; color: var(--v); }
        .schedule-card[open] summary::after { content: '⌃'; }
        .schedule-card h2 { font-size: 13px; margin: 0 0 4px; }
        .schedule-line { display: flex; justify-content: space-between; gap: 6px; flex-wrap: wrap; font-size: 12px; }
        .schedule-line .time { font-weight: bold; }
        .schedule-detail { background: var(--bg); padding: 8px 11px; font-size: 12px; border-top: 1px solid var(--line); }
        .schedule-detail p { margin: 4px 0; }
        .schedule-tramo { padding: 4px 0; }
        .schedule-tramo + .schedule-tramo { border-top: 1px solid var(--line); }
         /* Exámenes: misma grilla compacta que los horarios */
         .exam-card { border: 1px solid var(--line); border-radius: 6px; min-width: 0; overflow: hidden; }
         .exam-card summary { cursor: pointer; padding: 9px 11px; list-style: none; }
         .exam-card summary::-webkit-details-marker { display: none; }
         .exam-card summary::after { content: '⌄'; float: right; color: var(--v); }
         .exam-card[open] summary::after { content: '⌃'; }
         .exam-card h2 { font-size: 13px; margin: 0 0 4px; }
         .exam-detail { padding: 8px 11px; background: var(--bg); border-top: 1px solid var(--line); font-size: 12px; }
         .exam-detail p { margin: 4px 0; }
         .exam-date { font-size: 12px; font-weight: bold; }
         footer { text-align: center; color: #6d6277; font-size: 11px; padding: 15px 10px; }
        .back-admin { max-width: 960px; margin: 7px auto 0; padding: 0 10px; text-align: right; }
        .back-admin a { display: inline-block; padding: 7px 12px; border-radius: 5px; background: #223e60; color: #fff; text-decoration: none; font-size: 13px; }
        @media (max-width: 600px) {
            main { padding: 0 6px; margin: 6px auto; }
            section { padding: 9px; }
            nav button { flex: 1; }
            .req { grid-template-columns: 1fr; gap: 3px; }
            .schedule { grid-template-columns: 1fr; }
            .filter { gap: 5px; }
        }
    </style>
</head>
<body>
    <!-- =======================================================
         8. ENCABEZADO Y NAVEGACIÓN
         ======================================================= -->
    <header>
        <strong>SIAI | Informática</strong>
        <small>Profesorado Dr. Joaquín V. González · Prototipo académico · Plan 2015</small>
    </header>

    <?php if (!empty($_SESSION['admin_siai'])): ?>
        <div class="back-admin">
            <a href="admin.php">← Volver a Administración</a>
        </div>
    <?php endif; ?>

    <main>
       <nav aria-label="Secciones del portal">
    <button type="button" class="active" data-tab="materias">Materias</button>
    <button type="button" data-tab="horarios">Horarios</button>
    <button type="button" data-tab="examenes">Exámenes</button>
    <button type="button" data-tab="consultas">Consultas</button>
       </nav>

        <!-- ===================================================
             9. MATERIAS, RÉGIMEN Y CORRELATIVIDADES
             =================================================== -->
        <section id="materias">
            <h1>Materias y correlatividades</h1>
            <div class="filter">
                <input type="search" id="buscar" placeholder="Buscar materia..." aria-label="Buscar materia">
                <select id="anio" aria-label="Filtrar año">
                    <option value="">Todos</option>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <option value="<?= $i ?>"><?= $i ?>.º año</option>
                    <?php endfor; ?>
                </select>
            </div>

            <?php if ($error): ?>
                <p class="err"><?= h($error) ?></p>
            <?php endif; ?>
            <p class="muted" id="contador"><?= count($materias) ?> materias</p>

            <div id="lista">
                <?php foreach ($materias as $m): ?>
                    <details class="item materia-item"
                             data-anio="<?= (int) $m['anio'] ?>"
                             data-nombre="<?= h($m['nombre']) ?>">
                        <summary>
                            <?= h($m['nombre']) ?>
                            <span class="muted">· <?= (int) $m['anio'] ?>.º año</span>
                            <span class="pill"><?= h($regimenes[$m['regimen']] ?? 'Régimen a confirmar') ?></span>
                        </summary>
                        <div class="req">
                            <?php foreach ([
                                'tp_cursar' => 'TP aprobados para cursar',
                                'finales_cursar' => 'Finales aprobados para cursar',
                                'finales_rendir' => 'Finales aprobados para rendir',
                            ] as $clave => $etiqueta): ?>
                                <div>
                                    <b><?= h($etiqueta) ?></b>
                                    <p>
                                        <?php
                                        $requisitos = json_decode($m[$clave], true) ?: [];
                                        echo $requisitos ? h(implode(' · ', $requisitos)) : 'Sin requisitos indicados';
                                        ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
            <p class="muted">Fuente de correlatividades: Plan 2015. Confirmar vigencia y situaciones particulares con la institución.</p>
        </section>

        <!-- ===================================================
             10. HORARIOS 2026: AÑO, COMISIÓN Y TRAMOS SEMANALES
             =================================================== -->
        <section id="horarios" hidden>
            <h1>Horarios de cursada · 2026</h1>
            <div class="filter">
                <label for="horario-anio">Año</label>
                <select id="horario-anio">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <option value="<?= $i ?>" <?= $i === 4 ? 'selected' : '' ?>><?= $i ?>.º año</option>
                    <?php endfor; ?>
                </select>
                <label for="horario-comision">Comisión</label>
                <select id="horario-comision">
                    <option value="A">A · Tarde</option>
                    <option value="B">B · Mañana</option>
                </select>
            </div>

            <?php if ($horariosError): ?>
                <p class="err"><?= h($horariosError) ?></p>
            <?php endif; ?>
            <p class="muted" id="horarios-contador"></p>

            <div class="schedule" id="lista-horarios">
                <?php foreach ($cursadas as $c): ?>
                    <details class="schedule-card horario-item"
                             data-anio="<?= (int) $c['anio_materia'] ?>"
                             data-comision="<?= h($c['comision']) ?>">
                        <summary>
                            <h2><?= h($c['materia']) ?></h2>
                            <div class="schedule-line">
                                <span class="muted"><?= h($c['docentes'] ?: 'Docente a confirmar') ?></span>
                                <span class="time"><?= h(resumenHorarios($c['horarios'])) ?></span>
                            </div>
                        </summary>
                        <div class="schedule-detail">
                            <p><strong>Aula:</strong> <?= h($c['aula'] ?: 'A confirmar') ?></p>
                            <?php if (!$c['horarios']): ?>
                                <p>Horario pendiente de carga.</p>
                            <?php else: ?>
                                <?php foreach ($c['horarios'] as $tramo): ?>
                                    <div class="schedule-tramo">
                                        <strong><?= h($tramo['dia']) ?></strong> · Módulos
                                        <?= (int) $tramo['modulo_inicio'] === 0 ? 'Prehora' : (int) $tramo['modulo_inicio'] ?>
                                        a <?= (int) $tramo['modulo_fin'] ?> ·
                                        <?= ($tramo['hora_inicio'] && $tramo['hora_fin'])
                                            ? h($tramo['hora_inicio'] . ' a ' . $tramo['hora_fin'])
                                            : 'Hora exacta a confirmar' ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
            <p id="horarios-vacio" class="muted" hidden>No hay cursadas registradas para esta selección.</p>
            <p class="muted">Fuente: planillas de horarios 2026. Las aulas y los cambios deben confirmarse con Bedelía.</p>
        </section>

         <!-- ===================================================
              11. EXÁMENES: CONVOCATORIAS, MESAS Y TRIBUNALES
              =================================================== -->
         <section id="examenes" hidden>
             <h1>Mesas de examen</h1>
             <div class="filter">
                 <label for="examen-anio">Año</label>
                 <select id="examen-anio">
                     <?php for ($i = 1; $i <= 5; $i++): ?>
                         <option value="<?= $i ?>" <?= $i === 5 ? 'selected' : '' ?>><?= $i ?>.º año</option>
                     <?php endfor; ?>
                 </select>
                 <label for="examen-comision">Comisión</label>
                 <select id="examen-comision">
                     <option value="A">A · Tarde</option>
                     <option value="B">B · Mañana</option>
                 </select>
                 <label for="examen-convocatoria">Convocatoria</label>
                 <select id="examen-convocatoria">
                     <option value="">Todas</option>
                     <?php foreach ($convocatoriasExamen as $conv): ?>
                         <option value="<?= (int) $conv['id'] ?>">
                             <?= h((string) $conv['anio_lectivo'] . ' · ' . $conv['nombre']) ?>
                         </option>
                     <?php endforeach; ?>
                 </select>
             </div>

             <?php if ($examenesError): ?>
                 <p class="err"><?= h($examenesError) ?></p>
             <?php endif; ?>
             <p class="muted" id="examenes-contador"></p>
             <div class="schedule" id="lista-examenes">
                 <?php foreach ($mesasExamen as $mesa): ?>
                     <details class="exam-card examen-item"
                              data-anio="<?= (int) $mesa['anio_materia'] ?>"
                              data-comision="<?= h((string) $mesa['comision']) ?>"
                              data-convocatoria="<?= (int) $mesa['convocatoria_id'] ?>">
                         <summary>
                             <h2><?= h((string) $mesa['materia']) ?></h2>
                             <div class="schedule-line">
                                 <span class="muted"><?= h((string) $mesa['convocatoria']) ?></span>
                                 <span class="exam-date">
                                     <?= $mesa['fecha'] ? h(date('d/m/Y', strtotime((string) $mesa['fecha']))) : 'Fecha a confirmar' ?>
                                     · <?= $mesa['hora_inicio'] ? h(substr((string) $mesa['hora_inicio'], 0, 5)) : 'Hora a confirmar' ?>
                                 </span>
                             </div>
                         </summary>
                         <div class="exam-detail">
                             <p><strong>Comisión:</strong> <?= h((string) $mesa['comision']) ?> · <?= h((string) $mesa['turno']) ?></p>
                             <p><strong>Período:</strong>
                                 <?= h(date('d/m/Y', strtotime((string) $mesa['periodo_inicio']))) ?>
                                 a <?= h(date('d/m/Y', strtotime((string) $mesa['periodo_fin']))) ?>
                             </p>
                             <p><strong>Aula:</strong>
                                 <?= $mesa['aula'] ? h((string) $mesa['aula'] . ($mesa['piso_aula'] ? ' · ' . $mesa['piso_aula'] : '')) : 'A confirmar' ?>
                             </p>
                             <p><strong>Estado:</strong> <?= h(str_replace('_', ' ', (string) $mesa['estado'])) ?></p>
                             <p><strong>Tribunal:</strong> <?= h((string) ($mesa['tribunal'] ?: 'A designar')) ?></p>
                             <?php if ($mesa['observaciones']): ?>
                                 <p><strong>Observaciones:</strong> <?= h((string) $mesa['observaciones']) ?></p>
                             <?php endif; ?>
                         </div>
                     </details>
                 <?php endforeach; ?>
             </div>
             <p id="examenes-vacio" class="muted" hidden>No hay mesas registradas para esta selección.</p>
             <p class="muted">Información cargada en SIAI. Fechas, horarios, aulas y tribunales pueden modificarse; verificá su confirmación institucional.</p>
         </section>

         <!-- ===================================================
              12. FORMULARIO DE CONSULTAS
             =================================================== -->
        <section id="consultas" hidden>
            <h1>Enviar una consulta</h1>
            <p class="muted">Las consultas se guardan en este prototipo local. No se envían por correo ni son atendidas por la institución.</p>
            <form method="post" id="formulario">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_siai']) ?>">
                <label>Nombre y apellido <input name="nombre" maxlength="100" required></label>
                <label>Correo electrónico <input name="correo" type="email" maxlength="150" required></label>
                <label>Asunto <input name="asunto" maxlength="150" required></label>
                <label>Consulta <textarea name="mensaje" maxlength="2000" required></textarea></label>
                <button type="submit">Guardar consulta</button>
            </form>
            <?php if ($mensaje): ?>
                <p class="ok"><?= h($mensaje) ?></p>
            <?php elseif ($error): ?>
                <p class="err"><?= h($error) ?></p>
            <?php endif; ?>
        </section>
    </main>
    <footer>SIAI · Desarrollado por Gabriela Roldan · 2026</footer>

    <!-- =======================================================
         12. JAVASCRIPT: PESTAÑAS Y FILTROS
         ======================================================= -->
    <script>
        // Cambia entre Materias, Horarios y Consultas sin recargar.
        const tabs = [...document.querySelectorAll('[data-tab]')];
        function tab(id) {
            tabs.forEach(boton => {
                boton.classList.toggle('active', boton.dataset.tab === id);
            });
            document.querySelectorAll('main section').forEach(seccion => {
                seccion.hidden = seccion.id !== id;
            });
        }
        tabs.forEach(boton => {
            boton.addEventListener('click', () => tab(boton.dataset.tab));
        });

        // Ignora mayúsculas y tildes al buscar materias.
        function normalizar(texto) {
            return texto.toLocaleLowerCase('es')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '');
        }
        const buscar = document.querySelector('#buscar');
        const anio = document.querySelector('#anio');
        const materias = [...document.querySelectorAll('.materia-item')];
        function filtrarMaterias() {
            let cantidad = 0;
            materias.forEach(elemento => {
                const coincide = normalizar(elemento.dataset.nombre).includes(normalizar(buscar.value.trim()))
                    && (!anio.value || elemento.dataset.anio === anio.value);
                elemento.hidden = !coincide;
                if (coincide) cantidad++;
            });
            document.querySelector('#contador').textContent = cantidad + ' materias';
        }
        buscar.addEventListener('input', filtrarMaterias);
        anio.addEventListener('change', filtrarMaterias);

        // Filtra las cursadas de 2026 por año y comisión.
        const filtroAnio = document.querySelector('#horario-anio');
        const filtroComision = document.querySelector('#horario-comision');
        const horarios = [...document.querySelectorAll('.horario-item')];

        function filtrarHorarios() {
            let cantidad = 0;
            horarios.forEach(elemento => {
                const coincide = elemento.dataset.anio === filtroAnio.value
                    && elemento.dataset.comision === filtroComision.value;
                elemento.hidden = !coincide;
                if (coincide) cantidad++;
            });
            document.querySelector('#horarios-contador').textContent =
                cantidad + ' cursadas · ' + filtroAnio.value + '.º año · Comisión ' + filtroComision.value;
            document.querySelector('#horarios-vacio').hidden = cantidad > 0;
        }
        filtroAnio.addEventListener('change', filtrarHorarios);
        filtroComision.addEventListener('change', filtrarHorarios);
        filtrarHorarios();

         // Filtra mesas por año, comisión y convocatoria.
         const examenAnio = document.querySelector('#examen-anio');
         const examenComision = document.querySelector('#examen-comision');
         const examenConvocatoria = document.querySelector('#examen-convocatoria');
         const examenes = [...document.querySelectorAll('.examen-item')];

         function filtrarExamenes() {
             let cantidad = 0;
             examenes.forEach(elemento => {
                 const coincide = elemento.dataset.anio === examenAnio.value
                     && elemento.dataset.comision === examenComision.value
                     && (!examenConvocatoria.value || elemento.dataset.convocatoria === examenConvocatoria.value);
                 elemento.hidden = !coincide;
                 if (coincide) cantidad++;
             });
             document.querySelector('#examenes-contador').textContent =
                 cantidad + ' mesas · ' + examenAnio.value + '.º año · Comisión ' + examenComision.value;
             document.querySelector('#examenes-vacio').hidden = cantidad > 0;
         }
         examenAnio.addEventListener('change', filtrarExamenes);
         examenComision.addEventListener('change', filtrarExamenes);
         examenConvocatoria.addEventListener('change', filtrarExamenes);
         filtrarExamenes();

         // Después de enviar una consulta, muestra la pestaña correspondiente.
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            tab('consultas');
        <?php endif; ?>
    </script>
</body>
</html>
