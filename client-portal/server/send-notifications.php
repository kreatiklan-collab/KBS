<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require __DIR__.'/config.php';
$private=$config['data_dir']??dirname(__DIR__,2).'/kreatiklan-portal-data';
if (!is_dir($private)) { fwrite(STDERR,"Portal has not been activated.\n");exit(1); }
$lock=fopen($private.'/application.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
$db=new PDO($config['dsn']??'sqlite:'.$private.'/portal.sqlite',$config['db_user']??null,$config['db_password']??null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$q=$db->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute(['mail_from']);$from=$q->fetchColumn();
if(!$from||!filter_var($from,FILTER_VALIDATE_EMAIL)){echo "Email sender is not configured. No messages sent.\n";exit;}
$rows=$db->query("SELECT * FROM mail_queue WHERE state='pending' AND attempts<5 ORDER BY created_at LIMIT 20")->fetchAll();$accepted=0;$failed=0;
foreach($rows as $row){
 $valid=filter_var($row['recipient'],FILTER_VALIDATE_EMAIL);$subject=preg_replace('/[\r\n]+/',' ',$row['subject']);
 $ok=$valid&&mail($row['recipient'],$subject,$row['body'],['From'=>$from,'MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8']);
 $q=$db->prepare('UPDATE mail_queue SET state=?,attempts=attempts+1 WHERE id=?');$q->execute([$ok?'accepted':($row['attempts']>=4?'failed':'pending'),$row['id']]);if($ok)$accepted++;else $failed++;
}
echo "Notification processing complete: $accepted accepted by mail server, $failed unsuccessful.\n";
