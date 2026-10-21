const assert=require('node:assert/strict');
const base=process.env.PORTAL_TEST_URL||'http://127.0.0.1:8085/';
class Client{
 constructor(){this.cookie='';this.csrf='';}
 async call(route,data,query={},expected=200){const u=new URL('server/api.php',base);u.searchParams.set('route',route);for(const[k,v]of Object.entries(query))u.searchParams.set(k,v);const headers={Cookie:this.cookie};const opt={headers};if(data!==undefined){opt.method='POST';headers['X-CSRF-Token']=this.csrf;if(data instanceof FormData)opt.body=data;else{headers['Content-Type']='application/json';opt.body=JSON.stringify(data);}}const r=await fetch(u,opt);for(const c of r.headers.getSetCookie())if(c.startsWith('KTI_PORTAL_SESSION='))this.cookie=c.split(';')[0];const text=await r.text();let body;try{body=JSON.parse(text);}catch{body={raw:text};}assert.equal(r.status,expected,`${route}: ${text}`);if(body.csrf)this.csrf=body.csrf;return body;}
}
(async()=>{
 const staff=new Client(),a=new Client(),b=new Client(),guest=new Client();
 const init=await staff.call('session');assert.equal(init.setupRequired,true);
 await staff.call('setup',{activationKey:'incorrect',username:'owner',password:'Owner-test-pass-123'}, {},403);
 await staff.call('setup',{activationKey:'local-test-activation-only',username:'owner',password:'Owner-test-pass-123'});
 await staff.call('setup',{activationKey:'local-test-activation-only',username:'intruder',password:'Intruder-pass-123'},{},409);
 let info=await staff.call('workspace',{name:'Client Alpha',username:'alpha',password:'Alpha-test-pass-123'});const ca=info.clients[0].id;
 info=await staff.call('workspace',{name:'Client Beta',username:'beta',password:'Beta-test-pass-123'});const cb=info.clients.find(c=>c.id!==ca).id;
 await a.call('session');info=await a.call('login',{username:'alpha',password:'Alpha-test-pass-123'});assert.equal(info.user.role,'client');assert.equal(info.clients.length,1);assert.equal(info.clients[0].id,ca);
 await b.call('session');await b.call('login',{username:'beta',password:'Beta-test-pass-123'});
 await guest.call('session');await guest.call('state',undefined,{workspace:ca},401);
 await a.call('state',undefined,{workspace:cb},404);
 await a.call('workspace',{name:'Illegal',username:'illegal',password:'Illegal-pass-123'},{},403);
 const saved=a.csrf;a.csrf='bad';await a.call('request',{client:ca},{},403);a.csrf=saved;
 const form=new FormData();form.set('workspace',ca);const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+yN1kAAAAASUVORK5CYII=','base64');form.append('files[]',new Blob([png],{type:'image/png'}),'reference.png');
 const upload=await a.call('upload',form);assert.equal(upload.files.length,1);
 let result=await a.call('request',{client:ca,title:'Production request',brief:'Client Alpha shared brief',type:'Social media',deadline:'2026-12-01',dimensions:'',name:'Client person',files:upload.files});let request=result.request;const rid=request.id;
 assert.equal(request.events[0].role,'client');assert.equal(request.events[0].account,'alpha');
 await b.call('event',{request:rid,type:'comment',message:'Forbidden',expectedEventId:request.events.at(-1).id},{},404);
 await b.call('file',undefined,{id:upload.files[0].id},404);
 await guest.call('file',undefined,{id:upload.files[0].id},401);
 const privateFile=await fetch(new URL(upload.files[0].url,base),{headers:{Cookie:a.cookie}});assert.equal(privateFile.status,200);assert.deepEqual(Buffer.from(await privateFile.arrayBuffer()),png);
 await a.call('event',{request:rid,type:'design',role:'staff',message:'Forged role',files:[{url:'https://example.com/file',name:'File'}],expectedEventId:request.events.at(-1).id},{},403);
 async function event(client,type,other={}){result=await client.call('event',{request:rid,type,message:'',files:[],expectedEventId:request.events.at(-1).id,...other});request=result.request;return request;}
 await event(staff,'delivery',{deliveryDate:'2026-12-03'});assert.equal(request.confirmedDeliveryDate,'2026-12-03');
 await event(staff,'clarification',{message:'Confirm headline'});assert.equal(request.needsClarification,true);
 await event(a,'comment',{message:'Confirmed'});assert.equal(request.needsClarification,false);
 await event(staff,'design',{files:[{url:'https://example.com/version-1',name:'Version 1'}]});assert.equal(request.status,'review');const v1=request.events.at(-1).id;
 await staff.call('event',{request:rid,type:'approved',expectedEventId:request.events.at(-1).id},{},403);
 await event(a,'approved');assert.equal(request.status,'done');assert.equal(request.events.at(-1).designEventId,v1);const approvedAt=request.events.at(-1).at;
 await event(a,'reopened',{message:'Further changes'});assert.equal(request.status,'progress');assert.equal(request.events.find(e=>e.type==='approved').at,approvedAt);
 const stale=request.events.at(-1).id;await event(a,'comment',{message:'Latest update'});await a.call('event',{request:rid,type:'comment',message:'Stale',expectedEventId:stale},{},409);
 await a.call('event',{request:rid,type:'deleted',message:'  ',expectedEventId:request.events.at(-1).id},{},400);
 await event(a,'deleted',{message:'Campaign cancelled',name:'Client contact'});assert.equal(request.status,'deleted');assert.equal(request.events.at(-1).message,'Campaign cancelled');
 await a.call('event',{request:rid,type:'comment',message:'Cannot edit archive',expectedEventId:request.events.at(-1).id},{},409);
 result=await staff.call('state',undefined,{workspace:ca});assert.equal(result.requests[0].status,'deleted');assert.equal(result.requests[0].events.find(e=>e.type==='approved').at,approvedAt);
 result=await b.call('state',undefined,{workspace:cb});assert.equal(result.requests.length,0);
 assert.ok((await a.call('notifications',undefined,{workspace:ca})).notifications.length>0);await b.call('notifications',undefined,{workspace:ca},404);
 await staff.call('client-password',{client:ca,password:'Reset-alpha-pass-456'});await a.call('state',undefined,{workspace:ca},401);
 await a.call('login',{username:'alpha',password:'Reset-alpha-pass-456'});assert.equal((await a.call('state',undefined,{workspace:ca})).requests[0].events.at(-1).message,'Campaign cancelled');
 const originalCookie=a.cookie;await a.call('password',{currentPassword:'Reset-alpha-pass-456',password:'Changed-alpha-pass-789'});assert.notEqual(a.cookie,originalCookie);
 await a.call('logout',{});await a.call('state',undefined,{workspace:ca},401);
 console.log('PASS: owner activation, client account creation, real login, CSRF, client isolation, private uploads/downloads, role enforcement, shared state, delivery/clarification, version approval, reopening, immutable deletion history, optimistic concurrency, notifications, password reset/change, and sign-out.');
})().catch(e=>{console.error(e);process.exitCode=1;});
