<?php
// ============================================================
// SIAI - SISTEMA DE INFORMACIÓN ACADÉMICA DE INFORMÁTICA
// Desarrollado por Gabriela Roldan - 2026
// MÓDULO 04 - ADMINISTRACIÓN DE EXÁMENES
// ============================================================
declare(strict_types=1);

// 01. SEGURIDAD Y CONFIGURACIÓN
session_start();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if (empty($_SESSION['admin_siai'])) {
    header('Location: admin.php');
    exit;
}
if (empty($_SESSION['csrf_admin'])) {
    $_SESSION['csrf_admin'] = bin2hex(random_bytes(24));
}
// Cierre de sesión exclusivo del administrador (solicitud POST protegida).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cerrar_sesion') {
    if (!hash_equals($_SESSION['csrf_admin'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Solicitud de cierre de sesión inválida.');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: admin.php');
    exit;
}
function escapar($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function entero($valor): int {
    return filter_var($valor, FILTER_VALIDATE_INT) ?: 0;
}
function volver(string $mensaje): never {
    $_SESSION['flash_examenes'] = $mensaje;
    header('Location: examenes_admin.php');
    exit;
}

// 02. CONEXIÓN A SQLITE
$ruta = dirname(__DIR__) . '/datos/siai.sqlite';
if (!is_file($ruta)) {
    http_response_code(503);
    exit('No se encontró la base de datos de SIAI.');
}
try {
    $db = new PDO('sqlite:' . $ruta, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 3000');
    foreach (['convocatorias_examen', 'mesas_examen', 'mesa_profesor'] as $tabla) {
        $consulta = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
        $consulta->execute([$tabla]);
        if (!$consulta->fetchColumn()) {
            throw new RuntimeException('Falta la tabla ' . $tabla . '.');
        }
    }
} catch (Throwable $error) {
    http_response_code(503);
    exit('No se pudo abrir el módulo de exámenes: ' . escapar($error->getMessage()));
}

// 03. VALIDACIONES
function fechaValida(string $fecha): bool {
    $obj = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    return $obj !== false && $obj->format('Y-m-d') === $fecha;
}
function horaValida(string $hora): bool {
    return (bool)preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $hora);
}
function existe(PDO $db, string $tabla, int $id): bool {
    // Solo se invoca con nombres de tabla definidos dentro de este archivo.
    $consulta = $db->prepare('SELECT COUNT(*) FROM ' . $tabla . ' WHERE id=?');
    $consulta->execute([$id]);
    return (int)$consulta->fetchColumn() > 0;
}

// 04. PROCESAMIENTO DE FORMULARIOS (INSERT, UPDATE, DELETE)
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf_admin'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Formulario vencido. Actualizá la página.');
        }
        $accion = (string)($_POST['accion'] ?? '');

        // 04.1. CREAR O MODIFICAR CONVOCATORIA
        if ($accion === 'guardar_convocatoria') {
            $id = entero($_POST['id'] ?? 0);
            $anio = entero($_POST['anio_lectivo'] ?? 0);
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $inicio = (string)($_POST['fecha_inicio'] ?? '');
            $fin = (string)($_POST['fecha_fin'] ?? '');
            if ($anio < 2000 || $anio > 2100 || $nombre === '' || strlen($nombre) > 100 ||
                !fechaValida($inicio) || !fechaValida($fin) || $inicio > $fin) {
                throw new RuntimeException('Revisá el nombre, el año y el rango de fechas.');
            }
            if ($id) {
                // No permitir que un cambio de período deje mesas fuera de rango.
                $control = $db->prepare('SELECT COUNT(*) FROM mesas_examen WHERE convocatoria_id=? AND fecha IS NOT NULL AND (fecha<? OR fecha>?)');
                $control->execute([$id, $inicio, $fin]);
                if ((int)$control->fetchColumn() > 0) {
                    throw new RuntimeException('Hay mesas fuera del nuevo período. Reprogramalas antes.');
                }
                if (!existe($db, 'convocatorias_examen', $id)) {
                    throw new RuntimeException('La convocatoria que querés editar no existe.');
                }
                $db->prepare('UPDATE convocatorias_examen SET anio_lectivo=?,nombre=?,fecha_inicio=?,fecha_fin=? WHERE id=?')
                   ->execute([$anio, $nombre, $inicio, $fin, $id]);
            } else {
                $db->prepare('INSERT INTO convocatorias_examen(anio_lectivo,nombre,fecha_inicio,fecha_fin) VALUES(?,?,?,?)')
                   ->execute([$anio, $nombre, $inicio, $fin]);
            }
            volver('Convocatoria guardada correctamente.');
        }

        // 04.2. CREAR O MODIFICAR UNA MESA
        if ($accion === 'guardar_mesa') {
            $id = entero($_POST['id'] ?? 0);
            $convocatoria = entero($_POST['convocatoria_id'] ?? 0);
            $materia = entero($_POST['materia_id'] ?? 0);
            $comision = entero($_POST['comision_id'] ?? 0);
            $fecha = trim((string)($_POST['fecha'] ?? ''));
            $hora = trim((string)($_POST['hora_inicio'] ?? ''));
            $aula = entero($_POST['aula_id'] ?? 0);
            $estado = (string)($_POST['estado'] ?? 'a_confirmar');
            $observaciones = trim((string)($_POST['observaciones'] ?? ''));
            if (!$convocatoria || !$materia || !$comision ||
                !in_array($estado, ['a_confirmar','confirmada','reprogramada','cancelada'], true) ||
                strlen($observaciones) > 500) {
                throw new RuntimeException('Revisá los datos de la mesa.');
            }
            if (!existe($db, 'materias', $materia) || !existe($db, 'comisiones', $comision) ||
                ($aula > 0 && !existe($db, 'aulas', $aula))) {
                throw new RuntimeException('La materia, la comisión o el aula no existen.');
            }
            if (($fecha !== '' && !fechaValida($fecha)) || ($hora !== '' && !horaValida($hora))) {
                throw new RuntimeException('La fecha o la hora no tienen un formato válido.');
            }
            $rango = $db->prepare('SELECT fecha_inicio,fecha_fin FROM convocatorias_examen WHERE id=?');
            $rango->execute([$convocatoria]);
            $periodo = $rango->fetch();
            if (!$periodo || ($fecha !== '' && ($fecha < $periodo['fecha_inicio'] || $fecha > $periodo['fecha_fin']))) {
                throw new RuntimeException('La fecha debe estar dentro del período de la convocatoria.');
            }
            if ($id) {
                if (!existe($db, 'mesas_examen', $id)) {
                    throw new RuntimeException('La mesa que querés editar no existe.');
                }
                $db->prepare('UPDATE mesas_examen SET convocatoria_id=?,materia_id=?,comision_id=?,fecha=?,hora_inicio=?,aula_id=?,observaciones=?,estado=? WHERE id=?')
                   ->execute([$convocatoria,$materia,$comision,$fecha ?: null,$hora ?: null,$aula ?: null,$observaciones ?: null,$estado,$id]);
            } else {
                $db->prepare('INSERT INTO mesas_examen(convocatoria_id,materia_id,comision_id,fecha,hora_inicio,aula_id,observaciones,estado) VALUES(?,?,?,?,?,?,?,?)')
                   ->execute([$convocatoria,$materia,$comision,$fecha ?: null,$hora ?: null,$aula ?: null,$observaciones ?: null,$estado]);
            }
            volver('Mesa guardada correctamente.');
        }

        // 04.3. AGREGAR UN PROFESOR AL CATÁLOGO
        if ($accion === 'agregar_profesor') {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $apellido = trim((string)($_POST['apellido'] ?? ''));
            if ($nombre === '' || $apellido === '' || strlen($nombre) > 100 || strlen($apellido) > 100) {
                throw new RuntimeException('Ingresá nombre y apellido del profesor.');
            }
            $q = $db->prepare('SELECT COUNT(*) FROM profesores WHERE nombre=? COLLATE NOCASE AND apellido=? COLLATE NOCASE');
            $q->execute([$nombre,$apellido]);
            if ((int)$q->fetchColumn()) {
                throw new RuntimeException('El docente ya está registrado. Seleccionalo en el tribunal.');
            }
            $db->prepare('INSERT INTO profesores(nombre,apellido) VALUES(?,?)')->execute([$nombre,$apellido]);
            volver('Profesor incorporado al catálogo.');
        }

        // 04.4. ASIGNAR O MODIFICAR UN INTEGRANTE DEL TRIBUNAL
        if ($accion === 'guardar_integrante') {
            $mesa = entero($_POST['mesa_id'] ?? 0);
            $profesor = entero($_POST['profesor_id'] ?? 0);
            $rol = (string)($_POST['rol'] ?? '');
            if (!$mesa || !$profesor || !in_array($rol,['presidente','vocal','suplente'],true) ||
                !existe($db,'mesas_examen',$mesa) || !existe($db,'profesores',$profesor)) {
                throw new RuntimeException('Seleccioná una mesa, un profesor y su función.');
            }
            $db->prepare('INSERT INTO mesa_profesor(mesa_id,profesor_id,rol) VALUES(?,?,?) ON CONFLICT(mesa_id,profesor_id) DO UPDATE SET rol=excluded.rol')
               ->execute([$mesa,$profesor,$rol]);
            volver('Integrante del tribunal guardado.');
        }

        // 04.5. QUITAR UN INTEGRANTE (SIN BORRAR AL PROFESOR)
        if ($accion === 'quitar_integrante') {
            $db->prepare('DELETE FROM mesa_profesor WHERE mesa_id=? AND profesor_id=?')
               ->execute([entero($_POST['mesa_id'] ?? 0),entero($_POST['profesor_id'] ?? 0)]);
            volver('Integrante retirado del tribunal.');
        }
        throw new RuntimeException('Operación desconocida.');
    } catch (Throwable $ex) {
        $error = $ex instanceof PDOException
            ? 'No se pudo guardar: comprobá que no haya duplicados o conflictos con SQLite.'
            : $ex->getMessage();
    }
}

// 05. CONSULTAS SQL PARA MOSTRAR LOS FORMULARIOS
$convocatorias = $db->query('SELECT * FROM convocatorias_examen ORDER BY anio_lectivo DESC,fecha_inicio DESC')->fetchAll();
$materias = $db->query('SELECT id,anio,nombre FROM materias ORDER BY anio,nombre COLLATE NOCASE')->fetchAll();
$comisiones = $db->query('SELECT id,codigo,turno FROM comisiones ORDER BY codigo')->fetchAll();
$profesores = $db->query('SELECT id,nombre,apellido FROM profesores ORDER BY apellido COLLATE NOCASE,nombre COLLATE NOCASE')->fetchAll();
$aulas = $db->query('SELECT id,nombre,piso FROM aulas WHERE activa=1 ORDER BY nombre COLLATE NOCASE')->fetchAll();
$mesas = $db->query('SELECT me.*,c.nombre AS convocatoria,c.anio_lectivo,m.nombre AS materia,m.anio AS anio_materia,co.codigo AS comision,co.turno AS turno,a.nombre AS aula FROM mesas_examen me JOIN convocatorias_examen c ON c.id=me.convocatoria_id JOIN materias m ON m.id=me.materia_id JOIN comisiones co ON co.id=me.comision_id LEFT JOIN aulas a ON a.id=me.aula_id ORDER BY c.anio_lectivo DESC,me.fecha,me.hora_inicio,m.nombre')->fetchAll();
$integrantes = $db->query('SELECT mp.mesa_id,mp.profesor_id,mp.rol,p.nombre,p.apellido FROM mesa_profesor mp JOIN profesores p ON p.id=mp.profesor_id ORDER BY CASE mp.rol WHEN \'presidente\' THEN 0 WHEN \'vocal\' THEN 1 ELSE 2 END,p.apellido')->fetchAll();
$porMesa = [];
foreach ($integrantes as $integrante) {
    $porMesa[(int)$integrante['mesa_id']][] = $integrante;
}
$flash = (string)($_SESSION['flash_examenes'] ?? '');
unset($_SESSION['flash_examenes']);
$editarConv = entero($_GET['editar_conv'] ?? 0);
$editarMesa = entero($_GET['editar_mesa'] ?? 0);
$convActual = null;
$mesaActual = null;
foreach ($convocatorias as $c) if ((int)$c['id'] === $editarConv) $convActual = $c;
foreach ($mesas as $m) if ((int)$m['id'] === $editarMesa) $mesaActual = $m;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SIAI | Administración de exámenes</title>
<style>
/* 06. ESTILOS: DISEÑO COMPACTO Y ADAPTABLE */
:root{font-family:Arial,sans-serif;color:#213249;background:#f4f5f9}
*{box-sizing:border-box}body{margin:0}header{background:#244c77;color:white;padding:15px 20px}
header h1{font-size:19px;margin:0 0 5px}header small{opacity:.9}
main{max-width:1150px;margin:auto;padding:16px}nav{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px}nav form{margin:0}nav form button{font-size:13px;padding:9px 13px;background:#6b3140}
a{color:#245584}nav a,.boton{display:inline-block;background:#244c77;color:white;text-decoration:none;padding:9px 13px;border-radius:6px;font-size:13px}
section{scroll-margin-top:14px;background:white;border:1px solid #dbe1e9;border-radius:9px;padding:15px;margin:12px 0}
#formulario-mesa:target{border-color:#244c77;box-shadow:0 0 0 2px #dce8f5}
h2{font-size:17px;margin:0 0 12px}h3{font-size:15px;margin:12px 0 8px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.campos{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
label{display:block;font-size:12px;font-weight:bold;margin-bottom:4px}input,select,textarea{width:100%;padding:9px;border:1px solid #bdc9d7;border-radius:5px;background:white;color:#213249;font:inherit;font-size:13px}
textarea{min-height:65px}button{border:0;background:#244c77;color:white;border-radius:5px;padding:9px 12px;cursor:pointer;font-weight:bold}
.form-actions{margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.tabla{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:13px}th,td{text-align:left;padding:9px;border-bottom:1px solid #e2e6ed;vertical-align:top}th{background:#f0f4f9}
.muted{color:#617084;font-size:12px}.mensaje{padding:11px;border-radius:6px;background:#e9f4ee;color:#225b3a}.error{background:#fff0ed;color:#9b3525}
.inline{display:inline-flex;gap:5px;align-items:center;margin:2px}.inline button{padding:5px 8px}
.mesa{border:1px solid #dbe1e9;border-radius:7px;padding:11px;margin:9px 0}.mesa-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
.mesa p{margin:5px 0}.tribunal{margin-top:8px;padding:8px;background:#f6f7fa;border-radius:5px}
.filtros-mesas{display:grid;grid-template-columns:2fr repeat(3,minmax(0,1fr));gap:9px;align-items:end;margin:12px 0}
.filtros-mesas label{margin-bottom:5px}.paginacion{display:flex;gap:10px;align-items:center;justify-content:center;margin:14px 0;flex-wrap:wrap}
.paginacion button:disabled{opacity:.45;cursor:default}.resumen-filtros{font-size:12px;color:#617084;margin:8px 0}
footer{text-align:center;color:#617084;font-size:12px;padding:16px}
@media(max-width:750px){main{padding:9px}.campos,.grid,.filtros-mesas{grid-template-columns:1fr 1fr}section{padding:11px}}
@media(max-width:480px){.campos,.grid,.filtros-mesas{grid-template-columns:1fr}.mesa-head{display:block}header{padding:12px}nav a{flex:1;text-align:center}}
</style>
</head>
<body>
<!-- 07. CABECERA Y NAVEGACIÓN -->
<header>
<h1>SIAI · Administración de exámenes</h1>
<small>Profesorado Superior en Informática · Ciclo lectivo 2026</small>
</header>
<main>
<nav>
<a href="admin.php">← Volver a Administración</a>
<a href="siai.php">Portal estudiantil</a>
<form method="post">
<input type="hidden" name="csrf" value="<?=escapar($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="cerrar_sesion">
<button type="submit">Cerrar sesión</button>
</form>
</nav>
<?php if ($flash): ?><p class="mensaje"><?=escapar($flash)?></p><?php endif; ?>
<?php if ($error): ?><p class="mensaje error"><?=escapar($error)?></p><?php endif; ?>

<!-- 08. FORMULARIO DE CONVOCATORIAS -->
<section id="convocatorias">
<h2>1. Convocatorias de examen</h2>
<p class="muted">Definí el período general de cada llamado. Las fechas pueden cambiar en otros años.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?=escapar($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="guardar_convocatoria">
<input type="hidden" name="id" value="<?=escapar($convActual['id'] ?? 0)?>">
<div class="campos">
<div><label>Año lectivo</label><input type="number" name="anio_lectivo" min="2000" max="2100" value="<?=escapar($convActual['anio_lectivo'] ?? 2026)?>" required></div>
<div><label>Nombre de convocatoria</label><input name="nombre" maxlength="100" placeholder="Ej.: Primera presentación" value="<?=escapar($convActual['nombre'] ?? '')?>" required></div>
<div><label>Fecha de inicio</label><input type="date" name="fecha_inicio" value="<?=escapar($convActual['fecha_inicio'] ?? '')?>" required></div>
<div><label>Fecha de finalización</label><input type="date" name="fecha_fin" value="<?=escapar($convActual['fecha_fin'] ?? '')?>" required></div>
</div>
<div class="form-actions"><button>Guardar convocatoria</button><?php if($convActual): ?><a href="examenes_admin.php">Cancelar edición</a><?php endif; ?></div>
</form>
<div class="tabla"><table><thead><tr><th>Año</th><th>Convocatoria</th><th>Período</th><th>Acción</th></tr></thead><tbody>
<?php foreach($convocatorias as $c): ?><tr><td><?=escapar($c['anio_lectivo'])?></td><td><?=escapar($c['nombre'])?></td><td><?=escapar($c['fecha_inicio'])?> a <?=escapar($c['fecha_fin'])?></td><td><a href="?editar_conv=<?=escapar($c['id'])?>#convocatorias">Editar</a></td></tr><?php endforeach; ?>
<?php if(!$convocatorias): ?><tr><td colspan="4">Todavía no hay convocatorias registradas.</td></tr><?php endif; ?>
</tbody></table></div>
</section>

<!-- 09. FORMULARIO DE MESAS: ANCLA DE EDICIÓN -->
<section id="formulario-mesa">
<h2>2. <?= $mesaActual ? "Editar mesa de examen" : "Programar una mesa de examen" ?></h2>
<?php if ($mesaActual): ?><p class="mensaje">Estás editando la mesa de <?=escapar($mesaActual['materia'])?>, comisión <?=escapar($mesaActual['comision'])?>. Los cambios se guardarán en esta mesa, sin crear otra.</p><?php endif; ?>
<p class="muted">La fecha debe pertenecer al período de la convocatoria. Podés dejar la fecha, la hora o el aula sin completar.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?=escapar($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="guardar_mesa">
<input type="hidden" name="id" value="<?=escapar($mesaActual['id'] ?? 0)?>">
<div class="campos">
<div><label>Convocatoria</label><select name="convocatoria_id" required><option value="">Seleccionar</option><?php foreach($convocatorias as $c): ?><option value="<?=escapar($c['id'])?>" <?=((int)($mesaActual['convocatoria_id']??0)===(int)$c['id'])?'selected':''?>><?=escapar($c['anio_lectivo'].' · '.$c['nombre'])?></option><?php endforeach; ?></select></div>
<div><label>Materia</label><select name="materia_id" required><option value="">Seleccionar</option><?php foreach($materias as $m): ?><option value="<?=escapar($m['id'])?>" <?=((int)($mesaActual['materia_id']??0)===(int)$m['id'])?'selected':''?>><?=escapar($m['anio'].'.º · '.$m['nombre'])?></option><?php endforeach; ?></select></div>
<div><label>Comisión</label><select name="comision_id" required><option value="">Seleccionar</option><?php foreach($comisiones as $c): ?><option value="<?=escapar($c['id'])?>" <?=((int)($mesaActual['comision_id']??0)===(int)$c['id'])?'selected':''?>><?=escapar($c['codigo'].' · '.$c['turno'])?></option><?php endforeach; ?></select></div>
<div><label>Fecha de examen</label><input type="date" name="fecha" value="<?=escapar($mesaActual['fecha'] ?? '')?>"></div>
<div><label>Hora de inicio</label><input type="time" name="hora_inicio" value="<?=escapar($mesaActual['hora_inicio'] ?? '')?>"></div>
<div><label>Aula (opcional)</label><select name="aula_id"><option value="">—</option><?php foreach($aulas as $a): ?><option value="<?=escapar($a['id'])?>" <?=((int)($mesaActual['aula_id']??0)===(int)$a['id'])?'selected':''?>><?=escapar($a['nombre'].' · '.$a['piso'])?></option><?php endforeach; ?></select></div>
<div><label>Estado</label><select name="estado"><?php foreach(['a_confirmar'=>'A confirmar','confirmada'=>'Confirmada','reprogramada'=>'Reprogramada','cancelada'=>'Cancelada'] as $v=>$t): ?><option value="<?=escapar($v)?>" <?=($mesaActual['estado']??'a_confirmar')===$v?'selected':''?>><?=escapar($t)?></option><?php endforeach; ?></select></div>
<div><label>Observaciones</label><input name="observaciones" maxlength="500" value="<?=escapar($mesaActual['observaciones']??'')?>"></div>
</div>
<div class="form-actions"><button type="submit" <?=!$convocatorias?'disabled':''?>><?= $mesaActual ? "Guardar cambios" : "Guardar mesa" ?></button><?php if($mesaActual): ?><a href="examenes_admin.php#formulario-mesa">Cancelar edición</a><?php endif; ?></div>
</form>
</section>

<!-- 10. GESTIÓN DE PROFESORES Y TRIBUNALES -->
<section id="tribunales">
<h2>3. Integrantes de tribunales</h2>
<div class="grid">
<div><h3>Asignar docente a una mesa</h3>
<form method="post">
<input type="hidden" name="csrf" value="<?=escapar($_SESSION['csrf_admin'])?>"><input type="hidden" name="accion" value="guardar_integrante">
<label>Mesa</label><select name="mesa_id" required><option value="">Seleccionar</option><?php foreach($mesas as $m): ?><option value="<?=escapar($m['id'])?>"><?=escapar($m['materia'].' · '.$m['comision'].' · '.$m['convocatoria'])?></option><?php endforeach; ?></select>
<label>Profesor registrado</label><select name="profesor_id" required><option value="">Seleccionar</option><?php foreach($profesores as $p): ?><option value="<?=escapar($p['id'])?>"><?=escapar($p['apellido'].', '.$p['nombre'])?></option><?php endforeach; ?></select>
<label>Función en el tribunal</label><select name="rol"><option value="presidente">Presidente</option><option value="vocal">Vocal</option><option value="suplente">Suplente</option></select>
<div class="form-actions"><button <?=!$mesas?'disabled':''?>>Guardar integrante</button></div>
</form></div>
<div><h3>Registrar un docente nuevo</h3>
<form method="post">
<input type="hidden" name="csrf" value="<?=escapar($_SESSION['csrf_admin'])?>"><input type="hidden" name="accion" value="agregar_profesor">
<label>Nombre</label><input name="nombre" maxlength="100" required>
<label>Apellido</label><input name="apellido" maxlength="100" required>
<div class="form-actions"><button>Agregar al catálogo</button></div>
</form><p class="muted">El docente nuevo quedará disponible para futuras mesas y cursadas.</p></div>
</div>
</section>

<!-- 11. LISTADO Y EDICIÓN DE MESAS -->
<section id="listado">
<h2>4. Mesas registradas (<?=count($mesas)?>)</h2>
<div class="filtros-mesas">
<div><label for="buscar-mesas">Buscar materia o profesor</label><input id="buscar-mesas" type="search" placeholder="Escribí una materia o un apellido..." autocomplete="off"></div>
<div><label for="filtrar-anio">Año de la materia</label><select id="filtrar-anio"><option value="">Todos</option><?php foreach (array_unique(array_column($materias, 'anio')) as $anioMateria): ?><option value="<?=escapar($anioMateria)?>"><?=escapar($anioMateria)?>.º año</option><?php endforeach; ?></select></div>
<div><label for="filtrar-comision">Comisión</label><select id="filtrar-comision"><option value="">Todas</option><?php foreach ($comisiones as $c): ?><option value="<?=escapar($c['id'])?>"><?=escapar($c['codigo'].' · '.$c['turno'])?></option><?php endforeach; ?></select></div>
<div><label for="filtrar-convocatoria">Convocatoria</label><select id="filtrar-convocatoria"><option value="">Todas</option><?php foreach ($convocatorias as $c): ?><option value="<?=escapar($c['id'])?>"><?=escapar($c['anio_lectivo'].' · '.$c['nombre'])?></option><?php endforeach; ?></select></div>
</div>
<p class="resumen-filtros" id="resumen-mesas" role="status" aria-live="polite"></p>
<?php if(!$mesas): ?><p class="muted">Todavía no se registraron mesas de examen.</p><?php endif; ?>
<?php foreach($mesas as $m): ?>
<div class="mesa" data-anio="<?=escapar($m['anio_materia'])?>" data-comision="<?=escapar($m['comision_id'])?>" data-convocatoria="<?=escapar($m['convocatoria_id'])?>" data-busqueda="<?=escapar($m['materia'].' '.$m['comision'].' '.$m['convocatoria'].' '.implode(' ',array_map(static fn($p) => $p['nombre'].' '.$p['apellido'], $porMesa[(int)$m['id']] ?? [])))?>">
<div class="mesa-head"><div><strong><?=escapar($m['materia'])?></strong> · Comisión <?=escapar($m['comision'])?> <span class="muted">(<?=escapar($m['turno'])?>)</span>
<p class="muted"><?=escapar($m['anio_lectivo'].' · '.$m['convocatoria'])?></p></div>
<div><strong><?=escapar($m['fecha'] ?: '—')?></strong> · <?=escapar($m['hora_inicio'] ?: '—')?> <a href="?editar_mesa=<?=escapar($m['id'])?>#formulario-mesa">Editar</a></div></div>
<p class="muted">Aula: <?=escapar($m['aula'] ?: '—')?> · Estado: <?=escapar(str_replace('_',' ',$m['estado']))?></p>
<div class="tribunal"><strong>Tribunal:</strong>
<?php foreach($porMesa[(int)$m['id']]??[] as $p): ?>
<form method="post" class="inline"><span><?=escapar(ucfirst($p['rol']).': '.$p['apellido'].', '.$p['nombre'])?></span>
<input type="hidden" name="csrf" value="<?=escapar($_SESSION['csrf_admin'])?>"><input type="hidden" name="accion" value="quitar_integrante">
<input type="hidden" name="mesa_id" value="<?=escapar($m['id'])?>"><input type="hidden" name="profesor_id" value="<?=escapar($p['profesor_id'])?>">
<button type="submit" title="Quitar integrante" onclick="return confirm('¿Quitar este integrante del tribunal?')">Quitar</button></form>
<?php endforeach; ?>
<?php if(empty($porMesa[(int)$m['id']])): ?><span class="muted"> A designar</span><?php endif; ?>
</div></div>
<?php endforeach; ?>
<p class="resumen-filtros" id="sin-resultados" hidden>No se encontraron mesas con esos filtros.</p>
<div class="paginacion" id="paginacion-mesas" hidden>
<button type="button" id="mesas-anterior">Anterior</button>
<span id="mesas-pagina" aria-live="polite"></span>
<button type="button" id="mesas-siguiente">Siguiente</button>
</div>
</section>
</main>
<script>
// 12. Búsqueda, filtros y paginación del listado (sin modificar SQLite).
(() => {
    const tarjetas = Array.from(document.querySelectorAll('#listado .mesa'));
    const buscar = document.getElementById('buscar-mesas');
    const anio = document.getElementById('filtrar-anio');
    const comision = document.getElementById('filtrar-comision');
    const convocatoria = document.getElementById('filtrar-convocatoria');
    const resumen = document.getElementById('resumen-mesas');
    const sinResultados = document.getElementById('sin-resultados');
    const paginacion = document.getElementById('paginacion-mesas');
    const anterior = document.getElementById('mesas-anterior');
    const siguiente = document.getElementById('mesas-siguiente');
    const paginaTexto = document.getElementById('mesas-pagina');
    const porPagina = 12;
    let pagina = 1;
    const normalizar = texto => (texto || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

    function actualizar() {
        const termino = normalizar(buscar.value);
        const filtradas = tarjetas.filter(tarjeta =>
            (!termino || normalizar(tarjeta.dataset.busqueda).includes(termino)) &&
            (!anio.value || tarjeta.dataset.anio === anio.value) &&
            (!comision.value || tarjeta.dataset.comision === comision.value) &&
            (!convocatoria.value || tarjeta.dataset.convocatoria === convocatoria.value)
        );
        const totalPaginas = Math.max(1, Math.ceil(filtradas.length / porPagina));
        pagina = Math.min(pagina, totalPaginas);
        tarjetas.forEach(tarjeta => { tarjeta.hidden = true; });
        filtradas.slice((pagina - 1) * porPagina, pagina * porPagina).forEach(tarjeta => { tarjeta.hidden = false; });
        resumen.textContent = filtradas.length + ' mesas encontradas de ' + tarjetas.length + ' registradas';
        sinResultados.hidden = filtradas.length !== 0;
        paginacion.hidden = filtradas.length <= porPagina;
        paginaTexto.textContent = 'Página ' + pagina + ' de ' + totalPaginas;
        anterior.disabled = pagina <= 1;
        siguiente.disabled = pagina >= totalPaginas;
    }
    [buscar, anio, comision, convocatoria].forEach(control => {
        control.addEventListener(control === buscar ? 'input' : 'change', () => { pagina = 1; actualizar(); });
    });
    anterior.addEventListener('click', () => { if (pagina > 1) { pagina--; actualizar(); } });
    siguiente.addEventListener('click', () => { pagina++; actualizar(); });
    actualizar();
})();
</script>
<footer>SIAI · Desarrollado por Gabriela Roldan · 2026</footer>
</body>
</html>
