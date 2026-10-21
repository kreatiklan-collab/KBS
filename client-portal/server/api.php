<?php
declare(strict_types=1);
// The only HTTP entry point for the live portal. All mutations are server validated.
ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: frame-ancestors 'none'");
function fail(string $message, int $status = 400): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode(['error' => $message, 'csrf' => $_SESSION['csrf'] ?? null]); exit; }
function reply(array $data): never { header('Content-Type: application/json'); echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit; }
function textField(array $data, string $key, int $max, bool $required = false): string {
    $v = $data[$key] ?? ''; if (!is_string($v)) fail('Invalid '.$key.'.'); $v = trim($v);
    if (strlen($v) > $max || ($required && $v === '')) fail('Please provide a valid '.$key.'.'); return $v;
}
function validDate(string $value): bool { $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $d && $d->format('Y-m-d') === $value; }
function passwordValue(array $data): string { $p = $data['password'] ?? ''; if (!is_string($p) || strlen($p) < 12 || strlen($p) > 72) fail('Use a password of 12–72 characters.'); return $p; }
function usernameValue(array $data): string { $u = strtolower(textField($data, 'username', 100, true)); if (!preg_match('/^[a-z0-9][a-z0-9_.@-]{2,99}$/D', $u)) fail('Use 3–100 letters, numbers, dots, underscores, @ or hyphens for the username.'); return $u; }
function uid(): string { return bin2hex(random_bytes(16)); }
function nowUtc(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
$configFile = __DIR__.'/config.php';
if (!is_file($configFile)) fail('The portal has not been configured on this server.', 503);
$config = require $configFile;
$local = ($config['environment'] ?? '') === 'test';
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (!$local && !$secure) fail('Please use the HTTPS address for this portal.', 400);
if (PHP_VERSION_ID < 80100) fail('PHP 8.1 or later is required.', 503);
$private = $config['data_dir'] ?? dirname(__DIR__, 2).'/kreatiklan-portal-data';
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__));
if (!is_dir($private) && !mkdir($private, 0700, true)) fail('Private application storage is unavailable.', 503);
$private = realpath($private);
if (!$private || ($documentRoot && ($private === $documentRoot || str_starts_with($private, $documentRoot.DIRECTORY_SEPARATOR)))) fail('Application storage must be outside the public website folder.', 503);
if (!is_dir($private.'/uploads') && !mkdir($private.'/uploads', 0700)) fail('Private upload storage is unavailable.', 503);
if (!is_dir($private.'/sessions') && !mkdir($private.'/sessions', 0700)) fail('Session storage is unavailable.', 503);
session_save_path($private.'/sessions');
ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
session_name('KTI_PORTAL_SESSION'); session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!$local,'httponly'=>true,'samesite'=>'Lax']); session_start();
if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 28800) { $_SESSION = []; session_regenerate_id(true); }
$_SESSION['last_activity'] = time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET','POST'], true)) fail('Method not allowed.',405);
if ($method === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail('Your form has expired. Reload the page and try again.',403);
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        $expected = ($secure ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? '');
        if ($_SERVER['HTTP_ORIGIN'] !== $expected) fail('Invalid request origin.',403);
    }
}
$lock = fopen($private.'/application.lock','c');
if (!$lock || !flock($lock, LOCK_EX)) fail('The service is busy. Please try again.',503);
try {
    $dsn = $config['dsn'] ?? 'sqlite:'.$private.'/portal.sqlite';
    $db = new PDO($dsn, $config['db_user'] ?? null, $config['db_password'] ?? null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if (str_starts_with($dsn,'sqlite:')) { $db->exec('PRAGMA busy_timeout = 5000'); $db->exec('PRAGMA foreign_keys = ON'); }
    $db->exec('CREATE TABLE IF NOT EXISTS clients (id VARCHAR(64) PRIMARY KEY, name TEXT NOT NULL, initials VARCHAR(8) NOT NULL, email TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS users (id VARCHAR(64) PRIMARY KEY, username VARCHAR(100) NOT NULL UNIQUE, password_hash TEXT NOT NULL, role VARCHAR(10) NOT NULL, client_id VARCHAR(64), enabled INTEGER NOT NULL DEFAULT 1, session_version INTEGER NOT NULL DEFAULT 1)');
    $db->exec('CREATE TABLE IF NOT EXISTS requests (id VARCHAR(64) PRIMARY KEY, client_id VARCHAR(64) NOT NULL, body LONGTEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS files (id VARCHAR(64) PRIMARY KEY, client_id VARCHAR(64) NOT NULL, request_id VARCHAR(64), storage_name VARCHAR(64) NOT NULL, name TEXT NOT NULL, mime VARCHAR(100) NOT NULL, size INTEGER NOT NULL, created_at VARCHAR(30) NOT NULL, owner_id VARCHAR(64) NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (bucket VARCHAR(100) PRIMARY KEY, count INTEGER NOT NULL, last_at INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS notifications (id VARCHAR(64) PRIMARY KEY, client_id VARCHAR(64) NOT NULL, title TEXT NOT NULL, created_at VARCHAR(30) NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS mail_queue (id VARCHAR(64) PRIMARY KEY, recipient TEXT NOT NULL, subject TEXT NOT NULL, body LONGTEXT NOT NULL, state VARCHAR(20) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(30) NOT NULL)');
} catch (Throwable $e) { error_log('Kreatiklan portal database initialization failed: '.get_class($e)); fail('The database is unavailable. Check the configured PDO driver and storage permissions.',503); }
function query(string $sql, array $params = []): PDOStatement { global $db; $q=$db->prepare($sql); $q->execute($params); return $q; }
function setting(string $key, string $default=''): string { $r=query('SELECT setting_value FROM settings WHERE setting_key=?',[$key])->fetch(); return $r ? $r['setting_value'] : $default; }
function user(): array { if (!isset($_SESSION['uid'])) fail('Please sign in to continue.',401); $u=query('SELECT * FROM users WHERE id=? AND enabled=1',[$_SESSION['uid']])->fetch(); if (!$u || $u['session_version'] != ($_SESSION['session_version'] ?? 0)) { unset($_SESSION['uid'],$_SESSION['session_version']); fail('Please sign in again.',401); } return $u; }
function clientAccess(array $u, string $id): array { if ($u['role']!=='staff' && $u['client_id']!==$id) fail('Workspace not found.',404); $c=query('SELECT * FROM clients WHERE id=?',[$id])->fetch(); if (!$c) fail('Workspace not found.',404); return $c; }
function accountState(?array $u): array {
    $clients=$u ? ($u['role']==='staff' ? query('SELECT id,name,initials,email FROM clients ORDER BY name')->fetchAll() : query('SELECT id,name,initials,email FROM clients WHERE id=?',[$u['client_id']])->fetchAll()) : [];
    return ['user'=>$u?['id'=>$u['id'],'username'=>$u['username'],'role'=>$u['role'],'client'=>$u['client_id']]:null,'clients'=>$clients,'csrf'=>$_SESSION['csrf'],'setupRequired'=>(int)query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'emailConfigured'=>setting('mail_from')!==''];
}
function requestRecord(array $u, string $id): array { $row=query('SELECT * FROM requests WHERE id=?',[$id])->fetch(); if (!$row) fail('Request not found.',404); clientAccess($u,$row['client_id']); return json_decode($row['body'],true,512,JSON_THROW_ON_ERROR); }
function linksAndFiles(array $data, array $u, string $client, string $request): array {
    $files=$data['files'] ?? []; if (!is_array($files) || count($files)>6) fail('Attach up to five files and one link.'); $out=[]; $ids=[]; $links=0;
    foreach ($files as $f) {
        if (!is_array($f)) fail('Invalid attachment.');
        if (isset($f['url']) && !isset($f['uploadId'])) {
            $url=textField($f,'url',2048,true); $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME)); if (!in_array($scheme,['http','https'],true) || !filter_var($url,FILTER_VALIDATE_URL) || ++$links>1) fail('Use one valid HTTP or HTTPS file link.');
            $out[]=['id'=>uid(),'name'=>textField($f,'name',200) ?: 'Shared file','url'=>$url]; continue;
        }
        $id=textField($f,'uploadId',64,true); if (isset($ids[$id])) fail('Duplicate attachment.'); $ids[$id]=true;
        $row=query('SELECT * FROM files WHERE id=? AND client_id=? AND owner_id=? AND request_id IS NULL',[$id,$client,$u['id']])->fetch(); if (!$row || count($ids)>5) fail('Attachment not available for this request.');
        query('UPDATE files SET request_id=? WHERE id=?',[$request,$id]);
        $out[]=['id'=>$id,'uploadId'=>$id,'name'=>$row['name'],'mime'=>$row['mime'],'url'=>'server/api.php?route=file&id='.$id];
    } return $out;
}
function notifyWorkspace(string $client, string $title, string $role): void {
    query('INSERT INTO notifications (id,client_id,title,created_at) VALUES (?,?,?,?)',[uid(),$client,$title,nowUtc()]);
    $to=$role==='staff' ? (query('SELECT email FROM clients WHERE id=?',[$client])->fetchColumn() ?: '') : setting('staff_email');
    if ($to && setting('mail_from')) query('INSERT INTO mail_queue (id,recipient,subject,body,state,attempts,created_at) VALUES (?,?,?,?,?,0,?)',[uid(),$to,'Kreatiklan Client Portal: '.$title,$title."\n\nSign in to review this update: ".setting('portal_url'),'pending',nowUtc()]);
}
$route=$_GET['route'] ?? 'session';
$input=[];
if ($method==='POST' && !str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) {
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>100000) fail('Request is too large.',413);
    try {$input=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);} catch(Throwable $e) {fail('Invalid request body.');}
    if (!is_array($input)) fail('Invalid request body.');
}
try {
    if ($route==='session' && $method==='GET') reply(accountState(isset($_SESSION['uid'])?user():null));
    if ($route==='setup' && $method==='POST') {
        if ((int)query('SELECT COUNT(*) FROM users')->fetchColumn()!==0) fail('Initial setup is already complete.',409);
        $token=textField($input,'activationKey',200,true); $hash=$config['activation_hash']??'';
        if (!$hash || !hash_equals($hash,hash('sha256',$token))) fail('Invalid activation key.',403);
        $username=usernameValue($input); $password=passwordValue($input); $email=textField($input,'email',254); if ($email && !filter_var($email,FILTER_VALIDATE_EMAIL)) fail('Invalid email address.');
        $db->beginTransaction(); $id=uid();query('INSERT INTO users (id,username,password_hash,role,client_id) VALUES (?,?,?,?,NULL)',[$id,$username,password_hash($password,PASSWORD_DEFAULT),'staff']);
        query('INSERT INTO settings (setting_key,setting_value) VALUES (?,?)',['staff_email',$email]);query('INSERT INTO settings (setting_key,setting_value) VALUES (?,?)',['mail_from',$config['mail_from']??'']); query('INSERT INTO settings (setting_key,setting_value) VALUES (?,?)',['portal_url',$config['portal_url']??'https://clientportal.kreatiklan.com']);
        $db->commit(); session_regenerate_id(true);$_SESSION['uid']=$id;$_SESSION['session_version']=1;$_SESSION['csrf']=bin2hex(random_bytes(32));reply(accountState(user()));
    }
    if ($route==='login' && $method==='POST') {
        $username=strtolower(textField($input,'username',100,true));$password=$input['password']??'';if(!is_string($password)||strlen($password)>72)fail('Incorrect username or password.',401);
        $bucket=hash('sha256',($_SERVER['REMOTE_ADDR']??'unknown'));$a=query('SELECT * FROM login_attempts WHERE bucket=?',[$bucket])->fetch();
        if($a && (int)$a['count']>=10 && time()-(int)$a['last_at']<900)fail('Too many sign-in attempts. Try again in 15 minutes.',429);
        $u=query('SELECT * FROM users WHERE username=? AND enabled=1',[$username])->fetch();
        $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if(!password_verify($password,$u['password_hash']??$dummy) || !$u){
            $count=$a&&time()-(int)$a['last_at']<900?(int)$a['count']+1:1;
            query('DELETE FROM login_attempts WHERE bucket=?',[$bucket]); query('INSERT INTO login_attempts (bucket,count,last_at) VALUES (?,?,?)',[$bucket,$count,time()]);fail('Incorrect username or password.',401);
        }
        query('DELETE FROM login_attempts WHERE bucket=?',[$bucket]);session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['session_version']=$u['session_version'];$_SESSION['csrf']=bin2hex(random_bytes(32));reply(accountState($u));
    }
    $u=user();
    if ($route==='logout' && $method==='POST') { $_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));reply(['ok'=>true,'csrf'=>$_SESSION['csrf']]); }
    if ($route==='password' && $method==='POST') {
        if(!password_verify($input['currentPassword']??'', $u['password_hash']))fail('The current password is incorrect.',403);
        $password=passwordValue($input);query('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);session_regenerate_id(true);$_SESSION['session_version']=(int)$u['session_version']+1;$_SESSION['csrf']=bin2hex(random_bytes(32));reply(accountState(user()));
    }
    if ($route==='workspace' && $method==='POST') {
        if ($u['role']!=='staff') fail('Staff access required.',403);
        $name=textField($input,'name',160,true);$username=usernameValue($input);$password=passwordValue($input);$email=textField($input,'email',254);if($email&&!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Invalid email address.');
        if(query('SELECT id FROM users WHERE username=?',[$username])->fetch())fail('This username is already in use.',409);
        $db->beginTransaction();$id=uid();$initials=strtoupper(substr(preg_replace('/[^a-zA-Z]/','',$name),0,2))?:'CL';
        query('INSERT INTO clients (id,name,initials,email) VALUES (?,?,?,?)',[$id,$name,$initials,$email]);query('INSERT INTO users (id,username,password_hash,role,client_id) VALUES (?,?,?,?,?)',[uid(),$username,password_hash($password,PASSWORD_DEFAULT),'client',$id]);$db->commit();reply(accountState($u));
    }
    if ($route==='client-password' && $method==='POST') {
        if($u['role']!=='staff')fail('Staff access required.',403);$id=textField($input,'client',64,true);clientAccess($u,$id);$password=passwordValue($input);query('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE client_id=? AND role=?',[password_hash($password,PASSWORD_DEFAULT),$id,'client']);reply(['ok'=>true]);
    }
    if ($route==='state' && $method==='GET') {
        $client=(string)($_GET['workspace']??'');clientAccess($u,$client);$rows=query('SELECT body FROM requests WHERE client_id=?',[$client])->fetchAll();
        $requests=array_map(fn($r)=>json_decode($r['body'],true,512,JSON_THROW_ON_ERROR),$rows);usort($requests,fn($a,$b)=>strcmp($b['events'][0]['at'],$a['events'][0]['at']));reply(['version'=>1,'requests'=>$requests]);
    }
    if ($route==='notifications' && $method==='GET') { $client=(string)($_GET['workspace']??'');clientAccess($u,$client);$rows=query('SELECT title,created_at FROM notifications WHERE client_id=? ORDER BY created_at DESC LIMIT 30',[$client])->fetchAll();reply(['notifications'=>array_map(fn($r)=>['client'=>$client,'title'=>$r['title'],'at'=>$r['created_at']],$rows)]); }
    if ($route==='upload' && $method==='POST') {
        $client=(string)($_POST['workspace']??'');clientAccess($u,$client);$files=$_FILES['files']??null;if(!$files||!is_array($files['name'])||count($files['name'])>5)fail('Attach up to five files.');
        $out=[];$written=[];$db->beginTransaction();
        foreach($files['name'] as $i=>$name){
            if($files['error'][$i]!==UPLOAD_ERR_OK)throw new RuntimeException('The upload failed. Check the file size and retry.');
            if($files['size'][$i]>4*1024*1024||$files['size'][$i]===0)throw new RuntimeException('Files must be between 1 byte and 4 MB.');
            $name=trim(basename(str_replace('\\','/',(string)$name)));if(!$name||strlen($name)>200)throw new RuntimeException('Invalid filename.');
            $id=uid();$storage=uid();$dest=$private.'/uploads/'.$storage;$mime=(new finfo(FILEINFO_MIME_TYPE))->file($files['tmp_name'][$i])?:'application/octet-stream';
            if(!move_uploaded_file($files['tmp_name'][$i],$dest))throw new RuntimeException('Unable to store the uploaded file.');chmod($dest,0600);$written[]=$dest;
            query('INSERT INTO files (id,client_id,request_id,storage_name,name,mime,size,created_at,owner_id) VALUES (?,?,NULL,?,?,?,?,?,?)',[$id,$client,$storage,$name,$mime,$files['size'][$i],nowUtc(),$u['id']]);$out[]=['uploadId'=>$id,'id'=>$id,'name'=>$name,'mime'=>$mime,'url'=>'server/api.php?route=file&id='.$id];
        }
        $db->commit();reply(['files'=>$out]);
    }
    if ($route==='file' && $method==='GET') {
        $id=(string)($_GET['id']??'');$f=query('SELECT * FROM files WHERE id=?',[$id])->fetch();if(!$f)fail('File not found.',404);clientAccess($u,$f['client_id']);
        if(!$f['request_id']&&$f['owner_id']!==$u['id'])fail('File not found.',404);
        $path=$private.'/uploads/'.$f['storage_name'];if(!is_file($path))fail('File not found.',404);
        $inline=in_array($f['mime'],['image/png','image/jpeg','image/gif','image/webp'],true);
        header('Content-Type: '.($inline?$f['mime']:'application/octet-stream'));header('Content-Length: '.$f['size']);header("Content-Security-Policy: sandbox; default-src 'none'");
        header("Content-Disposition: ".($inline?'inline':'attachment')."; filename*=UTF-8''".rawurlencode($f['name']));session_write_close();flock($lock,LOCK_UN);readfile($path);exit;
    }
    if ($route==='request' && $method==='POST') {
        $client=textField($input,'client',64,true);clientAccess($u,$client);$title=textField($input,'title',140,true);$brief=textField($input,'brief',10000,true);$deadline=textField($input,'deadline',10,true);if(!validDate($deadline))fail('Provide a valid requested deadline.');
        $type=textField($input,'type',100,true);if(!in_array($type,['Social media','Digital banner','Presentation','Brand identity','Print design','Other'],true))fail('Invalid design type.');
        $db->beginTransaction();$num=(int)setting('request_counter','0')+1;query('DELETE FROM settings WHERE setting_key=?',['request_counter']);query('INSERT INTO settings (setting_key,setting_value) VALUES (?,?)',['request_counter',(string)$num]);$id='KR-'.str_pad((string)$num,3,'0',STR_PAD_LEFT);$at=nowUtc();
        $event=['id'=>uid(),'type'=>'created','role'=>$u['role'],'account'=>$u['username'],'message'=>'','name'=>textField($input,'name',80),'files'=>linksAndFiles($input,$u,$client,$id),'at'=>$at];
        $r=['id'=>$id,'client'=>$client,'title'=>$title,'brief'=>$brief,'type'=>$type,'deadline'=>$deadline,'dimensions'=>textField($input,'dimensions',120),'status'=>'requested','cover'=>['grid','pink','sage'][$num%3],'events'=>[$event]];
        query('INSERT INTO requests (id,client_id,body) VALUES (?,?,?)',[$id,$client,json_encode($r,JSON_THROW_ON_ERROR)]);notifyWorkspace($client,$id.' · New request: '.$title,$u['role']);$db->commit();reply(['request'=>$r]);
    }
    if ($route==='event' && $method==='POST') {
        $id=textField($input,'request',64,true);$r=requestRecord($u,$id);if($r['status']==='deleted')fail('Deleted requests are read-only.',409);
        if(($input['expectedEventId']??null)!==$r['events'][array_key_last($r['events'])]['id'])fail('This request was updated by another user. Reload it before submitting.',409);
        $type=textField($input,'type',30,true);$role=$u['role'];$message=textField($input,'message',10000);$name=textField($input,'name',80);
        if(!in_array($type,['comment','progress','design','delivery','clarification','revision','approved','reopened','deleted'],true))fail('Invalid update type.');
        if(in_array($type,['progress','design','delivery','clarification'],true)&&$role!=='staff')fail('This action requires Kreatiklan access.',403);
        if(in_array($type,['revision','approved'],true)&&$role!=='client')fail('This action requires client access.',403);
        if(in_array($type,['revision','approved'],true)&&$r['status']!=='review')fail('This request is not awaiting review.',409);
        if($type==='reopened'&&$r['status']!=='done')fail('Only completed requests can be reopened.',409);
        if($r['status']==='done'&&!in_array($type,['comment','reopened','deleted'],true))fail('Reopen the completed request before changing its status.',409);
        if(in_array($type,['revision','reopened','deleted','clarification'],true)&&!$message)fail('A reason or explanation is required.');
        if($type==='delivery'&&!validDate($input['deliveryDate']??''))fail('Select a valid confirmed delivery date.');
        $db->beginTransaction();$files=linksAndFiles($input,$u,$r['client'],$id);
        if($type==='comment'&&!$message&&!$files)throw new RuntimeException('Add a message, attachment, or file link.');if($type==='design'&&!$files)throw new RuntimeException('Attach a deliverable or file link.');
        $e=['id'=>uid(),'type'=>$type,'role'=>$role,'account'=>$u['username'],'message'=>$message,'name'=>$name,'files'=>$files,'at'=>nowUtc()];
        if($type==='delivery'){$e['deliveryDate']=$input['deliveryDate'];$r['confirmedDeliveryDate']=$input['deliveryDate'];}
        if($type==='approved'){$designs=array_values(array_filter($r['events'],fn($v)=>$v['type']==='design'));if(!$designs)throw new RuntimeException('No submitted version is available for approval.');$e['designEventId']=$designs[array_key_last($designs)]['id'];}
        if($type==='clarification')$r['needsClarification']=true;if($role==='client'&&in_array($type,['comment','revision','approved'],true))$r['needsClarification']=false;
        $r['status']=['progress'=>'progress','design'=>'review','revision'=>'revisions','approved'=>'done','reopened'=>'progress','deleted'=>'deleted'][$type]??$r['status'];$r['events'][]=$e;
        query('UPDATE requests SET body=? WHERE id=?',[json_encode($r,JSON_THROW_ON_ERROR),$id]);notifyWorkspace($r['client'],$id.' · '.['comment'=>'New comment','progress'=>'Work started','design'=>'Deliverable ready for review','revision'=>'Changes requested','approved'=>'Version approved','reopened'=>'Request reopened','deleted'=>'Request deleted','delivery'=>'Delivery date confirmed','clarification'=>'Clarification requested'][$type],$role);$db->commit();reply(['request'=>$r]);
    }
    fail('Endpoint not found.',404);
} catch(Throwable $e) {
    if($db->inTransaction())$db->rollBack();foreach($written??[] as $path)if(is_file($path))unlink($path);
    if($e instanceof RuntimeException && !($e instanceof PDOException))fail($e->getMessage());
    error_log('Kreatiklan portal request failed: '.get_class($e));fail('The update could not be saved. Please try again.',500);
}
