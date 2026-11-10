<?php
// SIAI - Desarrollado por Gabriela Roldan - 2026
// ============================================================
// 01. CONFIGURACIÓN Y SEGURIDAD
// ============================================================
declare(strict_types=1);
ini_set('display_errors','0');
session_start();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// ============================================================
// 02. FUNCIONES AUXILIARES
// ============================================================
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function go(int $year=1,string $tab='materias',int $edit=0): never {
    header('Location: admin.php?anio='.$year.'&tab='.$tab.($edit?'&edit='.$edit:''));
    exit;
}
// ============================================================
// 03. ARCHIVOS Y CONFIGURACIÓN
// ============================================================
$dir=dirname(__DIR__).'/datos';
if(!is_file($dir.'/admin.json')||!is_file($dir.'/siai.sqlite')){
    http_response_code(503);
    exit('Falta configurar SQLite o el administrador.');
}
$config=json_decode((string)file_get_contents($dir.'/admin.json'),true);
if(!is_array($config)||empty($config['hash'])){
    http_response_code(503);
    exit('Configuración inválida.');
}
if(empty($_SESSION['csrf_admin'])) $_SESSION['csrf_admin']=bin2hex(random_bytes(24));
$year=filter_var($_GET['anio']??1,FILTER_VALIDATE_INT);
if(!$year||$year<1||$year>5)$year=1;
// NAVEGACIÓN: cada pestaña del administrador
$tab=(string)($_GET['tab']??'materias');
if(!in_array($tab,['materias','consultas','profesores','cursadas','aulas'],true))$tab='materias';
$edit=filter_var($_GET['edit']??0,FILTER_VALIDATE_INT)?:0;
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($_SESSION['csrf_admin'],(string)($_POST['csrf']??''))){
        http_response_code(400);
        exit('Formulario vencido. Recargá la página.');
    }
    $action=(string)($_POST['accion']??'');
    if($action==='salir'){
        $_SESSION=[];
        session_regenerate_id(true);
        go();
    }
    if($action==='entrar'){
        $now=time();
        if($now-(int)($_SESSION['desde']??0)>600){
            $_SESSION['desde']=$now;
            $_SESSION['intentos']=0;
        }
        if((int)($_SESSION['intentos']??0)>=8)$error='Demasiados intentos. Esperá 10 minutos.';
        elseif(password_verify((string)($_POST['clave']??''),$config['hash'])){
            session_regenerate_id(true);
            $_SESSION['admin_siai']=true;
            $_SESSION['intentos']=0;
            go();
        }
        else{
            $_SESSION['intentos']=(int)($_SESSION['intentos']??0)+1;
            $error='Contraseña incorrecta.';
        }
    }
}
$auth=!empty($_SESSION['admin_siai']);
$materias=[];
$consultas=[];
$actual=null;
$totales=[];
$profesores=[];
$comisiones=[];
$cursadas=[];
$aulas=[];
$asignacionesAula=[];
$cambiosAula=[];
// ============================================================
// 04. ACCIONES ADMINISTRATIVAS Y CONEXIÓN SQLITE
// ============================================================
if($auth){
    try{
        $db=new PDO('sqlite:'.$dir.'/siai.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
// CONEXIÓN Y CLAVES FORÁNEAS
        $db->exec('PRAGMA busy_timeout=3000');
        $db->exec('PRAGMA foreign_keys=ON');
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $action=(string)($_POST['accion']??'');
            $postedYear=filter_var($_POST['anio_contexto']??1,FILTER_VALIDATE_INT)?:1;
            $postedYear=max(1,min(5,$postedYear));
// CREAR O EDITAR MATERIAS Y CORRELATIVIDADES
            // MATERIAS: alta y edición
            if($action==='guardar'){
                $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT)?:0;
                $nombre=trim((string)($_POST['nombre']??''));
                $anio=filter_var($_POST['anio']??0,FILTER_VALIDATE_INT);
                if(!$anio||$anio<1||$anio>5||$nombre===''||strlen($nombre)>160)throw new RuntimeException('Completá el nombre y el año correctamente.');
                $validos=$db->query('SELECT nombre FROM materias'.($id?' WHERE id!='.(int)$id:''))->fetchAll(PDO::FETCH_COLUMN);
                $modificarRequisitos=($_POST['modificar_requisitos']??'0')==='1';
                $previos=null;
                if($id){
                    $consulta=$db->prepare('SELECT tp_cursar,finales_cursar,finales_rendir FROM materias WHERE id=?');
                    $consulta->execute([$id]);
                    $previos=$consulta->fetch();
                    if(!$previos)throw new RuntimeException('No se encontró la materia.');
                }
                $fields=[];
                foreach(['tp_cursar','finales_cursar','finales_rendir'] as $campo){
                    if(!$modificarRequisitos && $previos){
                        $fields[]=$previos[$campo];
                        continue;
                    }
                    $selected=$_POST[$campo]??[];
                    if(!is_array($selected))throw new RuntimeException('Selección de requisitos inválida.');
                    $selected=array_values(array_unique(array_map('strval',$selected)));
                    if(count($selected)>100)throw new RuntimeException('Demasiados requisitos.');
                    foreach($selected as $r){
                        if(!in_array($r,$validos,true))throw new RuntimeException('La correlativa «'.$r.'» no existe o es la misma materia.');
                    }
                    $fields[]=json_encode($selected,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                }
                if($id){
                    $old=$db->prepare('SELECT nombre FROM materias WHERE id=?');
                    $old->execute([$id]);
                    $oldName=$old->fetchColumn();
                    if($oldName===false)throw new RuntimeException('No se encontró la materia.');
                    $q=$db->prepare('UPDATE materias SET anio=?,nombre=?,tp_cursar=?,finales_cursar=?,finales_rendir=? WHERE id=?');
                    $q->execute([$anio,$nombre,...$fields,$id]);
                    if($oldName!==$nombre){
                        foreach($db->query('SELECT id,tp_cursar,finales_cursar,finales_rendir FROM materias') as $m){
                            $updates=[];
                            foreach(['tp_cursar','finales_cursar','finales_rendir'] as $f){
                                $arr=json_decode($m[$f],true)?:[];
                                if(in_array($oldName,$arr,true))$updates[$f]=json_encode(array_map(fn($v)=>$v===$oldName?$nombre:$v,$arr),JSON_UNESCAPED_UNICODE);
                            }
                            if($updates){
                                $sets=implode(',',array_map(fn($k)=>$k.'=?',array_keys($updates)));
                                $db->prepare('UPDATE materias SET '.$sets.' WHERE id=?')->execute([...array_values($updates),$m['id']]);
                            }
                        }
                    }
                }
                else{
                    $q=$db->prepare('INSERT INTO materias(anio,nombre,tp_cursar,finales_cursar,finales_rendir) VALUES(?,?,?,?,?)');
                    $q->execute([$anio,$nombre,...$fields]);
                }
                $_SESSION['flash']='Materia guardada: '.$nombre;
                go($anio);
            }
// ELIMINAR MATERIAS
            // MATERIAS: eliminación
            if($action==='eliminar'){
                $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT)?:0;
                if(!$id)throw new RuntimeException('Materia inválida.');
                $q=$db->prepare('SELECT nombre FROM materias WHERE id=?');
                $q->execute([$id]);
                $nombre=$q->fetchColumn();
                if(!$nombre)throw new RuntimeException('La materia ya no existe.');
                $db->prepare('DELETE FROM materias WHERE id=?')->execute([$id]);
                // Los requisitos guardados como nombres deben limpiarse para no dejar referencias inexistentes.
                foreach($db->query('SELECT id,tp_cursar,finales_cursar,finales_rendir FROM materias') as $m){
                    $updates=[];
                    foreach(['tp_cursar','finales_cursar','finales_rendir'] as $f){
                        $arr=json_decode($m[$f],true)?:[];
                        if(in_array($nombre,$arr,true))$updates[$f]=json_encode(array_values(array_diff($arr,[$nombre])),JSON_UNESCAPED_UNICODE);
                    }
                    if($updates){
                        $sets=implode(',',array_map(fn($k)=>$k.'=?',array_keys($updates)));
                        $db->prepare('UPDATE materias SET '.$sets.' WHERE id=?')->execute([...array_values($updates),$m['id']]);
                    }
                }
                $_SESSION['flash']='Materia eliminada: '.$nombre;
                go($postedYear);
            }
// CREAR O EDITAR PROFESORES
            // PROFESORES: alta y edición
            if($action==='guardar_profesor'){
                $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT)?:0;
                $nombre=trim((string)($_POST['nombre']??''));
                $apellido=trim((string)($_POST['apellido']??''));
                if($nombre===''||$apellido===''||strlen($nombre)>100||strlen($apellido)>100)throw new RuntimeException('Completá nombre y apellido (máximo 100 caracteres).');
                if($id){
                    $q=$db->prepare('UPDATE profesores SET nombre=?,apellido=? WHERE id=?');
                    $q->execute([$nombre,$apellido,$id]);
                    if(!$q->rowCount())throw new RuntimeException('No se encontró el profesor o no hubo cambios.');
                }
                else{
                    $db->prepare('INSERT INTO profesores(nombre,apellido) VALUES (?,?)')->execute([$nombre,$apellido]);
                }
                $_SESSION['flash']='Profesor guardado correctamente.';
                go($postedYear,'profesores');
            }
            if($action==='eliminar_profesor'){
                $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT)?:0;
                if(!$id)throw new RuntimeException('Profesor inválido.');
                $q=$db->prepare('SELECT COUNT(*) FROM cursada_profesor WHERE profesor_id=?');
                $q->execute([$id]);
                if((int)$q->fetchColumn()>0)throw new RuntimeException('Primero quitá las asignaciones del profesor a las cursadas.');
                $db->prepare('DELETE FROM profesores WHERE id=?')->execute([$id]);
                $_SESSION['flash']='Profesor eliminado.';
                go($postedYear,'profesores');
            }
// CREAR O EDITAR CURSADAS Y ASIGNACIONES
            // CURSADAS: alta y edición
            if($action==='guardar_cursada'){
                $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT)?:0;
                $materiaId=filter_var($_POST['materia_id']??0,FILTER_VALIDATE_INT)?:0;
                $comisionId=filter_var($_POST['comision_id']??0,FILTER_VALIDATE_INT)?:0;
                $lectivo=filter_var($_POST['anio_lectivo']??0,FILTER_VALIDATE_INT)?:0;
                $periodo=(string)($_POST['periodo']??'sin_definir');
                $dia=trim((string)($_POST['dia']??''));
                $inicio=trim((string)($_POST['hora_inicio']??''));
                $fin=trim((string)($_POST['hora_fin']??''));
                $profIds=$_POST['profesores']??[];
                if(!$materiaId||!$comisionId||$lectivo<2020||$lectivo>2100||!in_array($periodo,['sin_definir','anual','1','2'],true))throw new RuntimeException('Revisá materia, comisión, año lectivo y período.');
                if($dia!==''&&!in_array($dia,['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'],true))throw new RuntimeException('Día inválido.');
                foreach([$inicio,$fin] as $h){
                    if($h!==''&&!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$h))throw new RuntimeException('El horario debe tener formato HH:MM.');
                }
                if(($inicio==='')!==($fin==='')||($inicio!==''&&$inicio>=$fin))throw new RuntimeException('Completá ambos horarios y verificá que el fin sea posterior al inicio.');
                if(!is_array($profIds))throw new RuntimeException('Selección de profesores inválida.');
                $profIds=array_values(array_unique(array_map('intval',$profIds)));
                if(count($profIds)>30)throw new RuntimeException('Demasiados profesores seleccionados.');
                $db->beginTransaction();
                try{
                    if($id){
                        $q=$db->prepare('UPDATE cursadas SET materia_id=?,comision_id=?,anio_lectivo=?,periodo=?,dia=?,hora_inicio=?,hora_fin=? WHERE id=?');
                        $q->execute([$materiaId,$comisionId,$lectivo,$periodo,$dia?:null,$inicio?:null,$fin?:null,$id]);
                        $check=$db->prepare('SELECT COUNT(*) FROM cursadas WHERE id=?');
                        $check->execute([$id]);
                        if(!(int)$check->fetchColumn())throw new RuntimeException('La cursada no existe.');
                    }
                    else{
                        $q=$db->prepare('INSERT INTO cursadas(materia_id,comision_id,anio_lectivo,periodo,dia,hora_inicio,hora_fin) VALUES(?,?,?,?,?,?,?)');
                        $q->execute([$materiaId,$comisionId,$lectivo,$periodo,$dia?:null,$inicio?:null,$fin?:null]);
                        $id=(int)$db->lastInsertId();
                    }
                    $db->prepare('DELETE FROM cursada_profesor WHERE cursada_id=?')->execute([$id]);
                    $add=$db->prepare('INSERT INTO cursada_profesor(cursada_id,profesor_id) VALUES(?,?)');
                    foreach($profIds as $pid){
                        if($pid<=0)throw new RuntimeException('Profesor inválido.');
                        $add->execute([$id,$pid]);
                    }
                    $db->commit();
                }
                catch(Throwable $e){
                    $db->rollBack();
                    throw $e;
                }
                $_SESSION['flash']='Cursada guardada correctamente.';
                go($postedYear,'cursadas');
            }
            if($action==='eliminar_cursada'){
                $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT)?:0;
                if(!$id)throw new RuntimeException('Cursada inválida.');
                $db->prepare('DELETE FROM cursadas WHERE id=?')->execute([$id]);
                $_SESSION['flash']='Cursada eliminada.';
                go($postedYear,'cursadas');
            }
            // AULAS: alta y edición
            if($action==='guardar_aula'){
                $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT)?:0;
                $nombre=trim((string)($_POST['nombre']??''));
                $piso=trim((string)($_POST['piso']??''));
                $descripcion=trim((string)($_POST['descripcion']??''));
                $activa=($_POST['activa']??'1')==='1'?1:0;
                if($nombre===''||strlen($nombre)>120||strlen($piso)>60||strlen($descripcion)>500)
                    throw new RuntimeException('Revisá el nombre del aula (máximo 120 caracteres), piso y descripción.');
                if($id){
                    $q=$db->prepare('UPDATE aulas SET nombre=?,piso=?,descripcion=?,activa=? WHERE id=?');
                    $q->execute([$nombre,$piso?:null,$descripcion?:null,$activa,$id]);
                    $q=$db->prepare('SELECT COUNT(*) FROM aulas WHERE id=?');
                    $q->execute([$id]);
                    if(!(int)$q->fetchColumn())throw new RuntimeException('No se encontró el aula.');
                }else{
                    $db->prepare('INSERT INTO aulas(nombre,piso,descripcion,activa) VALUES(?,?,?,?)')
                       ->execute([$nombre,$piso?:null,$descripcion?:null,$activa]);
                }
                $_SESSION['flash']='Aula guardada correctamente.';
                go($postedYear,'aulas');
            }
            if($action==='asignar_aula'){
                $cid=filter_var($_POST['cursada_id']??0,FILTER_VALIDATE_INT)?:0;
                $aid=filter_var($_POST['aula_id']??0,FILTER_VALIDATE_INT)?:0;
                $obs=trim((string)($_POST['observaciones']??''));
                if(!$cid||strlen($obs)>500)throw new RuntimeException('Seleccioná una cursada y revisá las observaciones.');
                if($aid){
                    $q=$db->prepare('SELECT COUNT(*) FROM aulas WHERE id=? AND activa=1');
                    $q->execute([$aid]);
                    if(!(int)$q->fetchColumn())throw new RuntimeException('El aula seleccionada no está disponible.');
                    $db->prepare('INSERT INTO cursada_aula(cursada_id,aula_id,observaciones) VALUES(?,?,?) ON CONFLICT(cursada_id) DO UPDATE SET aula_id=excluded.aula_id,observaciones=excluded.observaciones')
                       ->execute([$cid,$aid,$obs?:null]);
                }else{
                    $db->prepare('DELETE FROM cursada_aula WHERE cursada_id=?')->execute([$cid]);
                }
                $_SESSION['flash']='Asignación habitual actualizada.';
                go($postedYear,'aulas');
            }
            if($action==='guardar_cambio_aula'){
                $cid=filter_var($_POST['cursada_id']??0,FILTER_VALIDATE_INT)?:0;
                $aid=filter_var($_POST['aula_id']??0,FILTER_VALIDATE_INT)?:0;
                $fecha=(string)($_POST['fecha']??'');
                $motivo=trim((string)($_POST['motivo']??''));
                $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
                if(!$cid||!$aid||!$dt||$dt->format('Y-m-d')!==$fecha||strlen($motivo)>500)
                    throw new RuntimeException('Completá cursada, aula y fecha válida.');
                $q=$db->prepare('SELECT COUNT(*) FROM aulas WHERE id=? AND activa=1');
                $q->execute([$aid]);
                if(!(int)$q->fetchColumn())throw new RuntimeException('El aula seleccionada no está disponible.');
                $db->prepare('INSERT INTO cambios_aula(cursada_id,fecha,aula_id,motivo) VALUES(?,?,?,?) ON CONFLICT(cursada_id,fecha) DO UPDATE SET aula_id=excluded.aula_id,motivo=excluded.motivo')
                   ->execute([$cid,$fecha,$aid,$motivo?:null]);
                $_SESSION['flash']='Cambio excepcional registrado.';
                go($postedYear,'aulas');
            }
            if($action==='eliminar_cambio_aula'){
                $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT)?:0;
                if(!$id)throw new RuntimeException('Cambio inválido.');
                $db->prepare('DELETE FROM cambios_aula WHERE id=?')->execute([$id]);
                $_SESSION['flash']='Cambio excepcional eliminado.';
                go($postedYear,'aulas');
            }
// GUARDAR RÉGIMEN ANUAL O CUATRIMESTRAL
            // MATERIAS: régimen de cursada
            if($action==='guardar_regimen'){
                $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT)?:0;
                $regimen=(string)($_POST['regimen']??'');
                if(!$id||!in_array($regimen,['sin_definir','anual','cuatrimestral'],true))throw new RuntimeException('Régimen inválido.');
                $db->prepare('UPDATE materias SET regimen=? WHERE id=?')->execute([$regimen,$id]);
                $_SESSION['flash']='Régimen actualizado.';
                go($postedYear,'materias');
            }
// ACTUALIZAR ESTADO DE CONSULTAS
            // CONSULTAS: cambio de estado
            if($action==='estado'){
                $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT)?:0;
                $estado=(string)($_POST['estado']??'');
                if(!$id||!in_array($estado,['pendiente','respondida'],true))throw new RuntimeException('Estado inválido.');
                $db->prepare('UPDATE consultas SET estado=? WHERE id=?')->execute([$estado,$id]);
                $_SESSION['flash']='Estado de consulta actualizado.';
                go($postedYear,'consultas');
            }
        }
// ============================================================
// 05. CONSULTAS PARA MOSTRAR DATOS
// ============================================================
$materias=$db->query('SELECT * FROM materias ORDER BY anio,nombre COLLATE NOCASE')->fetchAll();
        $consultas=$db->query('SELECT * FROM consultas ORDER BY id DESC LIMIT 100')->fetchAll();
// CONSULTAS PARA POBLAR LOS FORMULARIOS DE DOCENTES Y CURSADAS
        $profesores=$db->query('SELECT * FROM profesores ORDER BY apellido COLLATE NOCASE,nombre COLLATE NOCASE')->fetchAll();
        $comisiones=$db->query('SELECT * FROM comisiones ORDER BY codigo')->fetchAll();
        $cursadas=$db->query("SELECT c.*,m.nombre AS materia,m.anio AS anio_materia,co.codigo AS comision,co.turno AS turno, (SELECT GROUP_CONCAT(p.apellido || ', ' || p.nombre, ' / ') FROM cursada_profesor cp JOIN profesores p ON p.id=cp.profesor_id WHERE cp.cursada_id=c.id) AS docentes FROM cursadas c JOIN materias m ON m.id=c.materia_id JOIN comisiones co ON co.id=c.comision_id ORDER BY c.anio_lectivo DESC,m.anio,m.nombre,co.codigo")->fetchAll();
// Lectura de aulas y su vinculación con las cursadas.
        $aulas=$db->query('SELECT * FROM aulas ORDER BY activa DESC,nombre COLLATE NOCASE')->fetchAll();
        $asignacionesAula=$db->query('SELECT ca.*,a.nombre AS aula,a.piso FROM cursada_aula ca JOIN aulas a ON a.id=ca.aula_id')->fetchAll();
        $cambiosAula=$db->query('SELECT ca.*,a.nombre AS aula,m.nombre AS materia,co.codigo AS comision FROM cambios_aula ca JOIN aulas a ON a.id=ca.aula_id JOIN cursadas c ON c.id=ca.cursada_id JOIN materias m ON m.id=c.materia_id JOIN comisiones co ON co.id=c.comision_id ORDER BY ca.fecha DESC,ca.id DESC LIMIT 100')->fetchAll();
        if($edit){
            foreach($materias as $m){
                if((int)$m['id']===$edit){
                    $actual=$m;
                    $year=(int)$m['anio'];
                    break;
                }
            }
        }
    }
    catch(Throwable $ex){
        $error=$ex instanceof PDOException?'No se pudo guardar. Verificá los datos, las asignaciones y las restricciones de SQLite.':$ex->getMessage();
    }
}
$flash=(string)($_SESSION['flash']??'');
unset($_SESSION['flash']);
$byYear=array_values(array_filter($materias,fn($m)=>(int)$m['anio']===$year));
foreach($materias as $m){
    $y=(int)$m['anio'];
    $totales[$y]=($totales[$y]??0)+1;
}
$creating=isset($_GET['nuevo'])&&$_GET['nuevo']==='1';
$editing=$actual!==null||$creating;
?>
<!-- INTERFAZ HTML: diseño del panel y formularios -->
<!-- ========================================================
     06. INTERFAZ HTML DEL ADMINISTRADOR
     ======================================================== -->
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gestión académica | SIAI</title>
<style>
/* ==========================================================
   07. ESTILOS CSS DEL PANEL
   ========================================================== */
/* ESTILOS DEL PANEL: distribución, colores, formularios y diseño adaptable  */
* {
    box-sizing:border-box
}

body {
    margin:0;
    font:13px Arial,sans-serif;
    background:#f2f4f8;
    color:#223046
}

header {
    background:#182b45;
    color:#fff;
    padding:12px 18px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px
}

header b {
    font-size:16px
}

header small {
    color:#c8d6e7
}

a {
    color:#234f88;
    text-decoration:none
}

button,.btn {
    border:1px solid #bac9da;
    border-radius:5px;
    padding:8px 11px;
    font:inherit;
    cursor:pointer;
    background:#fff;
    color:#1b385a;
    display:inline-block
}

button.primary,.btn.primary {
    background:#24517d;
    color:white;
    border-color:#24517d
}

button.danger {
    color:#a02835;
    border-color:#e4b6be
}

button:hover,.btn:hover {
    filter:brightness(.96)
}

.layout {
    max-width:1200px;
    margin:0 auto;
    display:grid;
    grid-template-columns:190px minmax(0,1fr);
    gap:14px;
    padding:14px
}

.side {
    background:#e5eaf1;
    border-radius:8px;
    padding:10px;
    height:fit-content
}

.side a {
    display:block;
    padding:10px;
    border-radius:5px;
    font-weight:bold
}

.side a.active {
    background:#24517d;
    color:#fff
}

.side .small {
    font-size:11px;
    color:#596a7d;
    padding:10px 5px
}

.main {
    min-width:0
}

.head {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
    margin-bottom:10px
}

h1 {
    font-size:18px;
    margin:0
}

h2 {
    font-size:15px;
    margin:0 0 10px
}

h3 {
    font-size:14px;
    margin:0 0 7px
}

.panel {
    background:white;
    border:1px solid #d8e1eb;
    border-radius:8px;
    padding:13px;
    margin-bottom:10px
}

.years {
    display:flex;
    gap:5px;
    flex-wrap:wrap
}

.years a {
    border:1px solid #c7d4e2;
    border-radius:5px;
    padding:9px 14px;
    background:white;
    font-weight:bold
}

.years a.active {
    background:#24517d;
    color:white
}

.muted {
    color:#617186;
    font-size:12px
}

.list {
    border-top:1px solid #e5eaf0
}

.item {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:9px 3px;
    border-bottom:1px solid #e5eaf0
}

.item strong {
    font-size:13px
}

.item .info {
    min-width:0
}

.item .info small {
    display:block;
    color:#66778a;
    margin-top:3px;
    line-height:1.4
}

.item .buttons {
    display:flex;
    gap:5px;
    flex-shrink:0
}

input,select,textarea {
    padding:8px;
    border:1px solid #bfcbd8;
    border-radius:5px;
    font:inherit;
    width:100%
}

label {
    font-weight:bold;
    display:block;
    margin-bottom:4px
}

.fields {
    display:grid;
    grid-template-columns:2fr 1fr;
    gap:10px;
    margin-bottom:10px
}

.requisitos {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:9px
}

.req {
    border:1px solid #dae2eb;
    border-radius:6px;
    padding:9px;
    min-width:0
}

.req summary {
    font-weight:bold;
    cursor:pointer
}

.current {
    background:#edf4f9;
    border:1px solid #cadce9;
    border-radius:6px;
    padding:10px;
    margin:9px 0
}

.current-grid {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:8px
}

.current-grid>div {
    background:#fff;
    padding:8px;
    border-radius:5px;
    border:1px solid #dbe5ee
}

.current-grid strong {
    display:block;
    margin-bottom:6px
}

.current-grid ul {
    padding-left:16px;
    margin:0
}

.current-grid li {
    margin:3px 0
}

.current-grid .none {
    color:#64748b
}

.edit-tools {
    margin:9px 0
}

.req details:not([open]) .checks {
    display:none
}

.checks {
    max-height:215px;
    overflow-y:auto;
    margin-top:7px
}

.check {
    display:flex;
    align-items:center;
    gap:7px;
    padding:5px 2px;
    border-bottom:1px solid #f0f2f6;
    font-weight:normal
}

.check input {
    width:auto;
    flex-shrink:0
}

.actions {
    display:flex;
    gap:7px;
    flex-wrap:wrap;
    margin-top:12px
}

.alert {
    padding:10px;
    border-radius:5px;
    background:#e6eef7;
    margin-bottom:10px
}

.err {
    background:#fff0f0;
    color:#8e2631
}

.consulta {
    border-bottom:1px solid #e5eaf0;
    padding:10px 0
}

.consulta p {
    white-space:pre-wrap;
    overflow-wrap:anywhere
}

.inline {
    display:flex;
    gap:6px;
    align-items:center;
    flex-wrap:wrap
}

.inline select {
    width:auto
}

.login {
    max-width:400px;
    margin:30px auto
}

.login input {
    margin:8px 0
}

.tag {
    background:#e9eff6;
    border-radius:4px;
    padding:2px 5px;
    font-size:11px;
    color:#36546f
}

@media(max-width:760px) {
    .layout {
    display:block;
    padding:7px
}

.side {
    display:flex;
    gap:5px;
    margin-bottom:8px;
    padding:6px
}

.side a {
    padding:8px
}

.side .small {
    display:none
}

.requisitos,.current-grid {
    grid-template-columns:1fr
}

.fields {
    grid-template-columns:1fr
}

.panel {
    padding:10px
}

.item {
    align-items:flex-start
}

.item .buttons {
    flex-direction:column
}

.years a {
    flex:1;
    text-align:center;
    padding:9px 6px
}
}

.form-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px
}

.form-grid>div {
    min-width:0
}

@media(max-width:760px) {
    .form-grid {
    grid-template-columns:1fr
}

.side {
    flex-wrap:wrap
}

.item {
    flex-wrap:wrap
}
}
</style>
</head>
<body>
<!-- 08. CABECERA Y ACCESO -->
<header>
<div>
<b>SIAI · Gestión académica</b> <small>Panel de administración</small>
</div>
<span style="font-size:12px">Acceso restringido</span>
</header>
<?php if(!$auth): ?>
<div class="login panel">
<h2>Ingresar al administrador</h2>
<?php if($error): ?>
<p class="alert err">
<?=e($error)?>
</p>
<?php endif;
?>
<form method="post">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="entrar">
<label>Contraseña</label>
<input type="password" name="clave" required autocomplete="current-password">
<button class="primary">Ingresar</button>
</form>
</div>
<?php else: ?>
<div class="layout">
<!-- 09. MENÚ DE NAVEGACIÓN -->
<aside class="side">
<a href="admin.php?anio=<?=$year?>" class="<?=$tab==='materias'?'active':''?>">Materias</a>
<a href="admin.php?tab=consultas" class="<?=$tab==='consultas'?'active':''?>">Consultas (<?=count($consultas)?>)</a>
<a href="admin.php?tab=profesores" class="<?=$tab==='profesores'?'active':''?>">Profesores</a>
<a href="admin.php?tab=cursadas" class="<?=$tab==='cursadas'?'active':''?>">Cursadas</a>
<a href="admin.php?tab=aulas" class="<?=$tab==='aulas'?'active':''?>">Aulas</a>
<a href="examenes_admin.php">Exámenes</a>
<div class="small">Administración del Plan 2015</div>
</aside>
<main class="main">
<div class="head">
<h1>
<?=e(['materias'=>'Materias por año','consultas'=>'Consultas recibidas','profesores'=>'Gestión de profesores','cursadas'=>'Comisiones y horarios','aulas'=>'Gestión de aulas'][$tab])?>
</h1>
<div class="inline">
<a class="btn" href="siai.php">Portal estudiantil</a>
<form method="post">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="salir">
<button>Cerrar sesión</button>
</form>
</div>
</div>
<?php if($flash): ?>
<p class="alert">
<?=e($flash)?>
</p>
<?php endif;
?>
<?php if($error): ?>
<p class="alert err">
<?=e($error)?>
</p>
<?php endif;
?>
<!-- 10. SECCIONES DE ADMINISTRACIÓN -->
<?php if($tab==='materias'): ?>
<div class="panel">
<div class="years">
<?php for($y=1;$y<=5;$y++): ?>
<a class="<?=$year===$y?'active':''?>" href="admin.php?anio=<?=$y?>">
<?=$y?>.º año <span style="font-weight:normal">(<?=$totales[$y]??0?>)</span>
</a>
<?php endfor;
?>
</div>
</div>
<div class="panel">
<div class="head">
<div>
<h2>Materias de <?=$year?>.º año</h2>
<span class="muted">
<?=count($byYear)?> materias registradas. Seleccioná Editar para gestionar sus correlatividades.</span>
</div>
<a class="btn primary" href="admin.php?anio=<?=$year?>&nuevo=1">+ Agregar materia</a>
</div>
<div class="list">
<?php foreach($byYear as $m): ?>
<?php $r1=json_decode($m['tp_cursar'],true)?:[];
$r2=json_decode($m['finales_cursar'],true)?:[];
$r3=json_decode($m['finales_rendir'],true)?:[];
?>
<div class="item">
<div class="info">
<strong>
<?=e($m['nombre'])?>
</strong>
<small>TP cursar: <?=e($r1?implode(", ",$r1):"Ninguno")?> · Finales cursar: <?=e($r2?implode(", ",$r2):"Ninguno")?> · Finales rendir: <?=e($r3?implode(", ",$r3):"Ninguno")?>
</small>
</div>
<div class="buttons">
<a class="btn" href="admin.php?anio=<?=$year?>&edit=<?=(int)$m['id']?>">Editar / correlativas</a>
<form method="post" class="inline">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="guardar_regimen">
<input type="hidden" name="id" value="<?=(int)$m['id']?>">
<input type="hidden" name="anio_contexto" value="<?=$year?>">
<select name="regimen" aria-label="Régimen de cursada">
<?php foreach(['sin_definir'=>'Sin definir','anual'=>'Anual','cuatrimestral'=>'Cuatrimestral'] as $k=>$v): ?>
<option value="<?=e($k)?>" <?=($m['regimen']??'sin_definir')===$k?'selected':''?>>
<?=e($v)?>
</option>
<?php endforeach;
?>
</select>
<button>Guardar régimen</button>
</form>
</div>
</div>
<?php endforeach;
?>
<?php if(!$byYear): ?>
<p class="muted">Este año todavía no tiene materias cargadas.</p>
<?php endif;
?>
</div>
</div>
<?php if($editing): ?>
<div class="panel" id="editor">
<h2>
<?=$creating?'Agregar materia a '.$year.'.º año':'Editar materia y correlatividades'?>
</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="guardar">
<input type="hidden" name="id" value="<?=e($actual['id']??0)?>">
<input type="hidden" name="anio_contexto" value="<?=$year?>">
<div class="fields">
<div>
<label>Nombre de la materia</label>
<input name="nombre" maxlength="160" value="<?=e($actual['nombre']??'')?>" required>
</div>
<div>
<label>Año</label>
<select name="anio">
<?php for($y=1;$y<=5;$y++): ?>
<option value="<?=$y?>" <?=$year===$y?'selected':''?>>
<?=$y?>.º año</option>
<?php endfor;
?>
</select>
</div>
</div>
<?php if($actual): ?>
<div class="current">
<h3>Correlatividades actuales de <?=e($actual['nombre'])?>
</h3>
<div class="current-grid">
<?php foreach(['tp_cursar'=>'TP aprobados para cursar','finales_cursar'=>'Finales aprobados para cursar','finales_rendir'=>'Finales aprobados para rendir'] as $key=>$title): $vals=json_decode($actual[$key],true)?:[];
?>
<div>
<strong>
<?=e($title)?>
</strong>
<?php if($vals): ?>
<ul>
<?php foreach($vals as $v): ?>
<li>
<?=e($v)?>
</li>
<?php endforeach;
?>
</ul>
<?php else: ?>
<span class="none">Sin requisitos</span>
<?php endif;
?>
</div>
<?php endforeach;
?>
</div>
</div>
<?php endif;
?>
<input type="hidden" name="modificar_requisitos" id="modificar-requisitos-flag" value="0">
<div class="edit-tools">
<button type="button" onclick="const panel=document.getElementById('modificar-correlativas');panel.hidden=!panel.hidden;document.getElementById('modificar-requisitos-flag').value=panel.hidden?'0':'1';">
<?= $actual?'Modificar correlatividades':'Seleccionar correlatividades' ?>
</button> <span class="muted">Los cambios solo se aplican al guardar.</span>
</div>
<div id="modificar-correlativas" hidden>
<h3>Agregar o quitar requisitos</h3>
<div class="requisitos">
<?php foreach(['tp_cursar'=>'TP aprobados para cursar','finales_cursar'=>'Finales aprobados para cursar','finales_rendir'=>'Finales aprobados para rendir'] as $key=>$title): ?>
<?php $selected=json_decode($actual[$key]??'[]',true)?:[];
?>
<div class="req">
<details open>
<summary>
<?=e($title)?> <span class="tag">
<?=count($selected)?> elegidas</span>
</summary>
<div class="checks">
<?php foreach($materias as $candidate): ?>
<?php if($actual&&(int)$candidate['id']===(int)$actual['id'])continue;
?>
<label class="check">
<input type="checkbox" name="<?=e($key)?>[]" value="<?=e($candidate['nombre'])?>" <?=in_array($candidate['nombre'],$selected,true)?'checked':''?>>
<?=e($candidate['nombre'])?>
</label>
<?php endforeach;
?>
</div>
</details>
</div>
<?php endforeach;
?>
</div>
</div>
<div class="actions">
<button class="primary">Guardar materia y correlatividades</button>
<a class="btn" href="admin.php?anio=<?=$year?>">Cancelar</a>
</div>
</form>
<?php if($actual): ?>
<form method="post" onsubmit="return confirm('¿Eliminar esta materia? También se quitará de los requisitos de otras materias. Esta acción no se puede deshacer.')" style="margin-top:8px">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="eliminar">
<input type="hidden" name="id" value="<?=e($actual['id'])?>">
<input type="hidden" name="anio_contexto" value="<?=$year?>">
<button class="danger">Eliminar esta materia</button>
</form>
<?php endif;
?>
</div>
<script>document.getElementById('editor')?.scrollIntoView({behavior:'smooth',block:'start'});</script>
<?php endif;
?>
<?php elseif($tab==='profesores'): ?>
<!-- INTERFAZ DE PROFESORES -->
<div class="panel">
<h2>Agregar profesor</h2>
<form method="post" class="inline">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="guardar_profesor">
<input name="nombre" placeholder="Nombre" required maxlength="100" style="max-width:240px">
<input name="apellido" placeholder="Apellido" required maxlength="100" style="max-width:240px">
<button class="primary">Agregar profesor</button>
</form>
</div>
<div class="panel">
<h2>Profesores registrados (<?=count($profesores)?>)</h2>
<?php foreach($profesores as $p): ?>
<div class="item">
<form method="post" class="inline" style="flex:1">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="guardar_profesor">
<input type="hidden" name="id" value="<?=(int)$p['id']?>">
<input name="nombre" value="<?=e($p['nombre'])?>" required maxlength="100" style="max-width:220px">
<input name="apellido" value="<?=e($p['apellido'])?>" required maxlength="100" style="max-width:220px">
<button>Guardar cambios</button>
</form>
<form method="post" onsubmit="return confirm('¿Eliminar este profesor?')">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="eliminar_profesor">
<input type="hidden" name="id" value="<?=(int)$p['id']?>">
<button class="danger">Eliminar</button>
</form>
</div>
<?php endforeach;
?>
<?php if(!$profesores): ?>
<p class="muted">Todavía no hay profesores cargados.</p>
<?php endif;
?>
</div>
<?php elseif($tab==='cursadas'): ?>
<!-- INTERFAZ DE CURSADAS Y ASIGNACIÓN DE PROFESORES -->
<?php $editarCursada=filter_var($_GET['cursada']??0,FILTER_VALIDATE_INT)?:0;
$cActual=null;
foreach($cursadas as $c){
    if((int)$c['id']===$editarCursada){
        $cActual=$c;
        break;
    }
}
$pSeleccionados=[];
if($cActual){
    $q=$db->prepare('SELECT profesor_id FROM cursada_profesor WHERE cursada_id=?');
    $q->execute([$cActual['id']]);
    $pSeleccionados=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}
?>
<div class="panel">
<h2>
<?=$cActual?'Editar cursada':'Agregar cursada'?>
</h2>
<p class="muted">Una cursada es una oferta concreta de una materia en una comisión y un año lectivo. Podés dejar el docente o el horario pendiente.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="guardar_cursada">
<input type="hidden" name="id" value="<?=(int)($cActual['id']??0)?>">
<div class="form-grid">
<div>
<label>Materia</label>
<select name="materia_id" required>
<option value="">Seleccionar materia</option>
<?php foreach($materias as $m): ?>
<option value="<?=(int)$m['id']?>" <?=(int)($cActual['materia_id']??0)===(int)$m['id']?'selected':''?>>
<?=e($m['anio'].'.º · '.$m['nombre'])?>
</option>
<?php endforeach;
?>
</select>
</div>
<div>
<label>Comisión</label>
<select name="comision_id" required>
<option value="">Seleccionar</option>
<?php foreach($comisiones as $co): ?>
<option value="<?=(int)$co['id']?>" <?=(int)($cActual['comision_id']??0)===(int)$co['id']?'selected':''?>>
<?=e($co['codigo'].' · '.$co['turno'])?>
</option>
<?php endforeach;
?>
</select>
</div>
<div>
<label>Año lectivo</label>
<input type="number" name="anio_lectivo" min="2020" max="2100" required value="<?=e($cActual['anio_lectivo']??date('Y'))?>">
</div>
<div>
<label>Período</label>
<select name="periodo">
<?php foreach(['sin_definir'=>'Sin definir','anual'=>'Anual','1'=>'1.º cuatrimestre','2'=>'2.º cuatrimestre'] as $k=>$v): ?>
<option value="<?=e($k)?>" <?=($cActual['periodo']??'sin_definir')===$k?'selected':''?>>
<?=e($v)?>
</option>
<?php endforeach;
?>
</select>
</div>
<div>
<label>Día</label>
<select name="dia">
<option value="">A confirmar</option>
<?php foreach(['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'] as $dia): ?>
<option value="<?=e($dia)?>" <?=($cActual['dia']??'')===$dia?'selected':''?>>
<?=e($dia)?>
</option>
<?php endforeach;
?>
</select>
</div>
<div>
<label>Hora de inicio</label>
<input type="time" name="hora_inicio" value="<?=e($cActual['hora_inicio']??'')?>">
</div>
<div>
<label>Hora de finalización</label>
<input type="time" name="hora_fin" value="<?=e($cActual['hora_fin']??'')?>">
</div>
</div>
<h3 style="margin-top:12px">Profesores asignados</h3>
<div class="checks" style="max-height:140px">
<?php foreach($profesores as $p): ?>
<label class="check">
<input type="checkbox" name="profesores[]" value="<?=(int)$p['id']?>" <?=in_array((int)$p['id'],$pSeleccionados,true)?'checked':''?>>
<?=e($p['apellido'].', '.$p['nombre'])?>
</label>
<?php endforeach;
?>
<?php if(!$profesores): ?>
<p class="muted">Primero podés registrar docentes en la pestaña Profesores.</p>
<?php endif;
?>
</div>
<div class="actions">
<button class="primary">Guardar cursada</button>
<?php if($cActual): ?>
<a class="btn" href="admin.php?tab=cursadas">Cancelar edición</a>
<?php endif;
?>
</div>
</form>
</div>
<div class="panel">
<h2>Cursadas registradas (<?=count($cursadas)?>)</h2>
<?php foreach($cursadas as $c): ?>
<div class="item">
<div class="info">
<strong>
<?=e($c['materia'])?>
</strong>
<small>
<?=e($c['anio_materia'])?>.º año · <?=e($c['anio_lectivo'])?> · Comisión <?=e($c['comision'])?> (<?=e($c['turno'])?>) · <?=e(['anual'=>'Anual','1'=>'1.º cuatrimestre','2'=>'2.º cuatrimestre','sin_definir'=>'Período pendiente'][$c['periodo']]??$c['periodo'])?>
</small>
<small>Horario: <?=e(($c['dia']?:'A confirmar').' · '.($c['hora_inicio']&&$c['hora_fin']?$c['hora_inicio'].' a '.$c['hora_fin']:'Horario pendiente'))?> · Docentes: <?=e($c['docentes']?:'A confirmar')?>
</small>
</div>
<div class="buttons">
<a class="btn" href="admin.php?tab=cursadas&cursada=<?=(int)$c['id']?>">Editar</a>
<form method="post" onsubmit="return confirm('¿Eliminar esta cursada y sus asignaciones?')">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="eliminar_cursada">
<input type="hidden" name="id" value="<?=(int)$c['id']?>">
<button class="danger">Eliminar</button>
</form>
</div>
</div>
<?php endforeach;
?>
<?php if(!$cursadas): ?>
<p class="muted">Todavía no hay cursadas registradas.</p>
<?php endif;
?>
</div>
<?php elseif($tab==='aulas'): ?>
<!-- GESTIÓN DE AULAS: catálogo, aula habitual y cambios por fecha -->
<div class="panel">
<h2>Registrar aula o laboratorio</h2>
<p class="muted">No asignamos letras ni números sin confirmar. Podés registrar "Laboratorio de Informática, 9.º piso" y completar el dato después.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="guardar_aula">
<div class="form-grid">
<div><label>Nombre o identificación</label><input name="nombre" required maxlength="120" placeholder="Ej.: Laboratorio de Informática"></div>
<div><label>Piso</label><input name="piso" maxlength="60" placeholder="Ej.: 9.º piso"></div>
<div><label>Descripción (opcional)</label><input name="descripcion" maxlength="500" placeholder="Ubicación a confirmar"></div>
</div>
<div class="actions"><button class="primary">Agregar aula</button></div>
</form>
</div>
<div class="panel">
<h2>Aulas registradas (<?=count($aulas)?>)</h2>
<?php foreach($aulas as $a): ?>
<form method="post" class="item" style="align-items:flex-end;flex-wrap:wrap">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="guardar_aula">
<input type="hidden" name="id" value="<?=(int)$a['id']?>">
<div style="flex:2;min-width:160px"><label>Nombre</label><input name="nombre" maxlength="120" required value="<?=e($a['nombre'])?>"></div>
<div style="flex:1;min-width:100px"><label>Piso</label><input name="piso" maxlength="60" value="<?=e($a['piso']??'')?>"></div>
<div style="flex:2;min-width:160px"><label>Descripción</label><input name="descripcion" maxlength="500" value="<?=e($a['descripcion']??'')?>"></div>
<div style="min-width:105px"><label>Estado</label><select name="activa"><option value="1" <?=$a['activa']?'selected':''?>>Activa</option><option value="0" <?=!$a['activa']?'selected':''?>>Inactiva</option></select></div>
<button>Guardar</button>
</form>
<?php endforeach; ?>
<?php if(!$aulas): ?><p class="muted">Todavía no hay aulas registradas.</p><?php endif; ?>
</div>
<div class="panel">
<h2>Asignar aula habitual a una cursada</h2>
<p class="muted">Cada cursada tiene su propia asignación. Seleccioná "Ubicación a confirmar" para quitar la asignación habitual.</p>
<?php foreach($cursadas as $c):
    $asig=null;
    foreach($asignacionesAula as $aa){if((int)$aa['cursada_id']===(int)$c['id']){$asig=$aa;break;}}
?>
<form method="post" class="item" style="flex-wrap:wrap;align-items:center">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="asignar_aula">
<input type="hidden" name="cursada_id" value="<?=(int)$c['id']?>">
<div class="info" style="flex:2;min-width:190px"><strong><?=e($c['materia'])?></strong><small><?=e($c['anio_lectivo'])?> · Comisión <?=e($c['comision'])?> (<?=e($c['turno'])?>) · <?=e($c['dia']?:'Día pendiente')?></small></div>
<div style="flex:1;min-width:160px"><label>Aula habitual</label><select name="aula_id"><option value="0">Ubicación a confirmar</option>
<?php foreach($aulas as $a): if(!$a['activa'] && (int)($asig['aula_id']??0)!==(int)$a['id'])continue; ?>
<option value="<?=(int)$a['id']?>" <?=(int)($asig['aula_id']??0)===(int)$a['id']?'selected':''?>><?=e($a['nombre'].($a['piso']?' · '.$a['piso']:''))?></option>
<?php endforeach; ?></select></div>
<div style="flex:1;min-width:150px"><label>Observaciones</label><input name="observaciones" maxlength="500" value="<?=e($asig['observaciones']??'')?>"></div>
<button>Guardar</button>
</form>
<?php endforeach; ?>
<?php if(!$cursadas): ?><p class="muted">Primero registrá una cursada en la pestaña Cursadas.</p><?php endif; ?>
</div>
<div class="panel">
<h2>Cambio excepcional de aula</h2>
<p class="muted">Este cambio solo se aplica a la fecha indicada. Si ya existe un cambio para esa cursada y fecha, se actualiza.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="guardar_cambio_aula">
<div class="form-grid">
<div><label>Cursada</label><select name="cursada_id" required><option value="">Seleccionar</option><?php foreach($cursadas as $c): ?><option value="<?=(int)$c['id']?>"><?=e($c['materia'].' · '.$c['anio_lectivo'].' · '.$c['comision'].' ('.$c['turno'].')')?></option><?php endforeach; ?></select></div>
<div><label>Fecha</label><input type="date" name="fecha" required></div>
<div><label>Aula excepcional</label><select name="aula_id" required><option value="">Seleccionar</option><?php foreach($aulas as $a): if(!$a['activa'])continue; ?><option value="<?=(int)$a['id']?>"><?=e($a['nombre'].($a['piso']?' · '.$a['piso']:''))?></option><?php endforeach; ?></select></div>
<div><label>Motivo (opcional)</label><input name="motivo" maxlength="500" placeholder="Ej.: laboratorio ocupado"></div>
</div>
<div class="actions"><button class="primary">Registrar cambio</button></div>
</form>
<h3 style="margin-top:16px">Últimos cambios registrados</h3>
<?php foreach($cambiosAula as $ca): ?>
<div class="item"><div class="info"><strong><?=e($ca['fecha'].' · '.$ca['materia'].' · Comisión '.$ca['comision'])?></strong><small><?=e($ca['aula'].($ca['motivo']?' · '.$ca['motivo']:''))?></small></div>
<form method="post" onsubmit="return confirm('¿Eliminar este cambio excepcional?')"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>"><input type="hidden" name="accion" value="eliminar_cambio_aula"><input type="hidden" name="id" value="<?=(int)$ca['id']?>"><button class="danger">Eliminar</button></form></div>
<?php endforeach; ?>
<?php if(!$cambiosAula): ?><p class="muted">Todavía no hay cambios excepcionales.</p><?php endif; ?>
</div>
<?php else: ?>
<div class="panel">
<p class="muted">Marcar una consulta como respondida no envía correos electrónicos.</p>
<?php foreach($consultas as $c): ?>
<div class="consulta">
<b>
<?=e($c['asunto'])?>
</b> <span class="tag">
<?=e($c['estado'])?>
</span>
<p class="muted">
<?=e($c['fecha'])?> · <?=e($c['nombre'])?> · <?=e($c['correo'])?>
</p>
<p>
<?=e($c['mensaje'])?>
</p>
<form method="post" class="inline">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf_admin'])?>">
<input type="hidden" name="accion" value="estado">
<input type="hidden" name="id" value="<?=e($c['id'])?>">
<select name="estado">
<option value="pendiente" <?=$c['estado']==='pendiente'?'selected':''?>>Pendiente</option>
<option value="respondida" <?=$c['estado']==='respondida'?'selected':''?>>Respondida</option>
</select>
<button>Guardar estado</button>
</form>
</div>
<?php endforeach;
?>
<?php if(!$consultas): ?>
<p>No hay consultas.</p>
<?php endif;
?>
</div>
<?php endif;
?>
</main>
</div>
<?php endif;
?>
<footer style="text-align:center;padding:14px;color:#617186;font-size:12px">SIAI · Desarrollado por Gabriela Roldan · 2026</footer>
</body>
</html>
