<?php
/* =========================================================
   LT AUTOS · serveur des données (remplace Supabase) · v1.0
   Hébergé chez Hostinger avec le site. Base MySQL locale.
   ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const PUBK = ['ltautos_voitures_v1','ltautos_horaires_v1','ltautos_dispo_v29','ltautos_heures_v23','ltautos_fidelite_v1','ltautos_frais_on_v1','ltautos_frais_rate_v35'];
const PRIVK = ['ltautos_clients_v22','ltautos_codes_v25','ltautos_rappels_v32','ltautos_points_v32','ltautos_points_bonus_v1','ltautos_sms_v1','ltautos_taches_v31','ltautos_agenda_v32'];
const CODESK = 'ltautos_codes_v25';

function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function fail($e=null){ out($e===null?['ok'=>false]:['ok'=>false,'err'=>$e]); }

/* ---- connexion à la base (identifiants dans un fichier hors de GitHub) ---- */
$cfgFiles = [__DIR__.'/../lt-config.php', __DIR__.'/lt-config.php'];
$CFG = null; foreach ($cfgFiles as $f) if (is_file($f)) { $CFG = include $f; break; }
if (!$CFG) { http_response_code(500); fail('config'); }
try {
  $db = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$CFG['db'].';charset=utf8mb4', $CFG['user'], $CFG['pass'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch (Exception $e) { http_response_code(500); fail('db'); }

/* ---- tables (créées automatiquement la 1re fois) ---- */
$db->exec("CREATE TABLE IF NOT EXISTS lt_kv(k VARCHAR(64) PRIMARY KEY, v LONGTEXT NOT NULL, pub TINYINT NOT NULL DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) CHARSET utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS lt_rdv(id VARCHAR(64) PRIMARY KEY, d LONGTEXT NOT NULL, created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3), updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) CHARSET utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS lt_accounts(email VARCHAR(190) PRIMARY KEY, d LONGTEXT NOT NULL, pw VARCHAR(255) NOT NULL, token VARCHAR(64), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(token)) CHARSET utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS lt_sessions(token VARCHAR(64) PRIMARY KEY, who VARCHAR(64) NOT NULL, exp DATETIME NOT NULL) CHARSET utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS lt_fails(at DATETIME NOT NULL, INDEX(at)) CHARSET utf8mb4");

/* ---- outils ---- */
function q($sql,$p=[]){ global $db; $s=$db->prepare($sql); $s->execute($p); return $s; }
function jd($s){ $v=json_decode($s,true); return $v===null?null:$v; }
function digits($s){ return preg_replace('/\D/','',(string)$s); }
function tok(){ return bin2hex(random_bytes(24)); }
function kvAll($onlyPub){ $o=[]; foreach(q('SELECT k,v FROM lt_kv'.($onlyPub?' WHERE pub=1':''))->fetchAll() as $r) $o[$r['k']]=jd($r['v']); return (object)$o; }
function rdvAll(){ return array_map(fn($r)=>jd($r['d']), q('SELECT d FROM lt_rdv ORDER BY created_at')->fetchAll()); }
function kvSet($k,$v){ q('INSERT INTO lt_kv(k,v,pub) VALUES(?,?,?) ON DUPLICATE KEY UPDATE v=VALUES(v),pub=VALUES(pub)',[$k,json_encode($v,JSON_UNESCAPED_UNICODE),in_array($k,PUBK)?1:0]); }
function who($t){ if(!is_string($t)||strlen($t)<20) return null; $r=q('SELECT who FROM lt_sessions WHERE token=? AND exp>NOW()',[$t])->fetch(); return $r?$r['who']:null; }
// « base neuve » = aucun réglage du garage encore reçu (les RDV pris sur le site ne comptent pas)
function isFresh(){ return !q('SELECT 1 FROM lt_kv WHERE k<>? LIMIT 1',[CODESK])->fetch(); }
function session($who){ q('DELETE FROM lt_sessions WHERE exp<NOW()'); $t=tok(); q('INSERT INTO lt_sessions VALUES(?,?,DATE_ADD(NOW(),INTERVAL 120 DAY))',[$t,$who]); return $t; }
function clientData($acc){
  $d=jd($acc['d'])?:[]; $d['email']=$acc['email']; $email=$acc['email']; $tel=substr(digits($d['tel']??''),-9);
  $rdv=array_values(array_filter(rdvAll(),function($r)use($email,$tel){ return strtolower($r['email']??'')===$email || (strlen($tel)===9 && substr(digits($r['tel']??''),-9)===$tel); }));
  $kv=kvAll(false); $rap=[];
  foreach((array)($kv->ltautos_rappels_v32??[]) as $x){ $x=(array)$x; if(strtolower($x['email']??'')===$email || ($x['ckey']??'')==='t'.$tel) $rap[]=$x; }
  $pts=(array)($kv->ltautos_points_v32??[]); $bon=(array)($kv->ltautos_points_bonus_v1??[]);
  return ['ok'=>true,'acc'=>$d,'token'=>$acc['token'],'rdv'=>$rdv,'rappels'=>$rap,'used'=>$pts['t'.$tel]??0,'bonus'=>$bon['t'.$tel]??0];
}

$fn = $_GET['fn'] ?? '';

/* ---- photos des voitures (envoyées par le panel) ---- */
if ($fn === 'photo') {
  if (!who($_GET['token'] ?? '')) fail();
  $bin = file_get_contents('php://input', false, null, 0, 3000001);
  if (!$bin || strlen($bin) > 3000000) fail('taille');
  $info = @getimagesizefromstring($bin);
  $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime'] ?? ''] ?? null;
  if (!$ext) fail('format');
  $dir = __DIR__.'/photos'; if (!is_dir($dir)) mkdir($dir, 0755, true);
  $name = date('Ymd').'-'.bin2hex(random_bytes(8)).'.'.$ext;
  file_put_contents($dir.'/'.$name, $bin);
  out(['ok'=>true,'url'=>'photos/'.$name]);
}

$a = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($fn) {
/* ---------- SITE PUBLIC ---------- */
case 'lt_public':
  $since = date('Y-m-d', time()-86400); $slots=[];
  foreach (rdvAll() as $r) if (($r['date']??'') >= $since && in_array($r['statut']??'',['nouveau','confirme']))
    $slots[] = ['date'=>$r['date'],'heure'=>$r['heure']??'','statut'=>$r['statut'],'urgence'=>!empty($r['urgence'])];
  out(['kv'=>kvAll(true),'slots'=>$slots]);

case 'lt_book':
  $r = $a['r'] ?? []; $tel = digits($r['tel'] ?? '');
  if (mb_strlen($r['nom']??'')<2 || strlen($tel)<9 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$r['date']??'') || !preg_match('/^\d{2}:\d{2}$/',$r['heure']??'')) fail('incomplet');
  if (strlen(json_encode($r)) > 6000) fail('trop long');
  $all = rdvAll(); $n=0; $nouveaux=0;
  foreach (q('SELECT d FROM lt_rdv WHERE created_at > NOW() - INTERVAL 1 DAY')->fetchAll() as $x) if (digits(jd($x['d'])['tel']??'')===$tel) $n++;
  foreach ($all as $x) if (($x['statut']??'')==='nouveau') $nouveaux++;
  if ($n>=4 || $nouveaux>=300) fail('limite');
  if (empty($r['urgence'])) foreach ($all as $x)
    if (($x['date']??'')===$r['date'] && ($x['heure']??'')===$r['heure'] && in_array($x['statut']??'',['nouveau','confirme']) && empty($x['urgence'])) fail('pris');
  $id = 'r'.date('YmdHis').sprintf('%03d',(int)(microtime(true)*1000)%1000).bin2hex(random_bytes(2));
  unset($r['id'],$r['statut'],$r['travaux'],$r['noteValid']); $r['id']=$id; $r['statut']='nouveau';
  q('INSERT INTO lt_rdv(id,d) VALUES(?,?)',[$id,json_encode($r,JSON_UNESCAPED_UNICODE)]);
  out(['ok'=>true,'id'=>$id]);

case 'lt_client_signup':
  $email = strtolower(trim($a['email'] ?? '')); $pw = (string)($a['pw'] ?? '');
  if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/',$email) || strlen($pw)<4) fail('invalide');
  if (q('SELECT 1 FROM lt_accounts WHERE email=?',[$email])->fetch()) fail('existe');
  $d = $a['d'] ?? []; unset($d['pw']);
  q('INSERT INTO lt_accounts(email,d,pw,token) VALUES(?,?,?,?)',[$email,json_encode($d,JSON_UNESCAPED_UNICODE),password_hash($pw,PASSWORD_DEFAULT),tok()]);
  out(clientData(q('SELECT * FROM lt_accounts WHERE email=?',[$email])->fetch()));

case 'lt_client_login':
  $acc = q('SELECT * FROM lt_accounts WHERE email=?',[strtolower(trim($a['email']??''))])->fetch();
  if (!$acc || !password_verify((string)($a['pw']??''), $acc['pw'])) { usleep(600000); fail(); }
  out(clientData($acc));

case 'lt_client_refresh':
  $t = (string)($a['token'] ?? ''); if (strlen($t)<=20) fail();
  $acc = q('SELECT * FROM lt_accounts WHERE token=?',[$t])->fetch(); if (!$acc) fail();
  out(clientData($acc));

/* ---------- PANEL DU GARAGE ---------- */
case 'lt_panel_login':
  if (q('SELECT COUNT(*) c FROM lt_fails WHERE at > NOW() - INTERVAL 10 MINUTE')->fetch()['c'] >= 15) fail('bloque');
  $code = (string)($a['code'] ?? '');
  $c = q('SELECT v FROM lt_kv WHERE k=?',[CODESK])->fetch();
  // 1re connexion après le déménagement : la base est vide, le panel envoie ses codes
  if (!$c && isFresh() && is_array($a['codes'] ?? null) && !empty($a['codes']['owner'])) { kvSet(CODESK,$a['codes']); $c=['v'=>json_encode($a['codes'])]; }
  $c = $c ? jd($c['v']) : ['owner'=>'0000','users'=>[]];
  $who = null;
  if ($code !== '' && hash_equals((string)($c['owner'] ?? '0000'), $code)) $who='owner';
  else foreach (($c['users'] ?? []) as $u) if (($u['code'] ?? null) === $code) { $who=$u['id']; break; }
  if (!$who) { q('INSERT INTO lt_fails VALUES(NOW())'); q('DELETE FROM lt_fails WHERE at < NOW() - INTERVAL 1 DAY'); usleep(800000); fail(); }
  out(['ok'=>true,'token'=>session($who),'who'=>$who,'fresh'=>isFresh()]);

case 'lt_panel_pull':
  $w = who($a['token'] ?? null); if (!$w) fail();
  out(['ok'=>true,'who'=>$w,'kv'=>kvAll(false),'rdv'=>rdvAll(),'fresh'=>isFresh()]);

case 'lt_panel_set':
  $w = who($a['token'] ?? null); if (!$w) fail();
  $k = (string)($a['key'] ?? '');
  if (!in_array($k, PUBK) && !in_array($k, PRIVK)) fail('cle');
  if ($k === CODESK && $w !== 'owner') fail('patron');
  kvSet($k, $a['val'] ?? null); out(['ok'=>true]);

case 'lt_panel_rdv_put':
  if (!who($a['token'] ?? null)) fail();
  foreach (($a['list'] ?? []) as $x) if (!empty($x['id']))
    q('INSERT INTO lt_rdv(id,d) VALUES(?,?) ON DUPLICATE KEY UPDATE d=VALUES(d)',[(string)$x['id'],json_encode($x,JSON_UNESCAPED_UNICODE)]);
  out(['ok'=>true]);

case 'lt_panel_logout':
  q('DELETE FROM lt_sessions WHERE token=?',[(string)($a['token'] ?? '')]); out(['ok'=>true]);

case 'lt_photo_ticket':
  if (!who($a['token'] ?? null)) fail(); out(['ok'=>true,'folder'=>'php']);
}
http_response_code(404); fail('fn');
