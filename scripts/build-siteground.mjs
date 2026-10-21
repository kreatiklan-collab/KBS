import {cp, mkdir, readFile, writeFile, rm} from 'node:fs/promises';
import {randomBytes, createHash} from 'node:crypto';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../',import.meta.url));
const output=path.resolve(process.env.PORTAL_BUILD_DIR||path.join(root,'siteground-dist'));
if(output===root||root.startsWith(output+path.sep))throw Error('Build output must not contain the source checkout.');
await mkdir(output,{recursive:true});
for(const entry of ['index.html','app.js','style.css','assets'])await cp(path.join(root,'client-portal',entry),path.join(output,entry),{recursive:true});
await mkdir(path.join(output,'server'),{recursive:true});
for(const entry of ['api.php','send-notifications.php','.htaccess'])await cp(path.join(root,'client-portal/server',entry),path.join(output,'server',entry));
await cp(path.join(root,'client-portal/server/server-client.js'),path.join(output,'server-client.js'));
await writeFile(path.join(output,'.user.ini'),'upload_max_filesize=4M\npost_max_size=24M\nmax_file_uploads=5\nexpose_php=Off\n');
let html=await readFile(path.join(output,'index.html'),'utf8');
html=html.replace('<script src="app.js" defer>','<script src="server-client.js" defer></script><script src="app.js" defer>');
await writeFile(path.join(output,'index.html'),html);
const config=path.join(output,'server/config.php');
let exists=false;try{await readFile(config);exists=true;}catch{}
if(!exists){
 const key=randomBytes(32).toString('hex'),hash=createHash('sha256').update(key).digest('hex');
 await writeFile(config,`<?php\nreturn ['environment'=>'production','portal_url'=>'https://clientportal.kreatiklan.com','activation_hash'=>'${hash}'];\n`,{mode:0o600});
 const keyFile=process.env.PORTAL_ACTIVATION_FILE||path.join(root,'..','artifacts','portal-activation-key.txt');
 await mkdir(path.dirname(keyFile),{recursive:true});await writeFile(keyFile,key+'\n',{mode:0o600});
 console.log('Deployment activation material generated separately from the web files.');
}
console.log('SiteGround PHP application built. Existing installation config preserved.');
