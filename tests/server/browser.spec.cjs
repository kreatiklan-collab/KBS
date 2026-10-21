const assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_PATH||'playwright');
const base=process.env.PORTAL_TEST_URL||'http://127.0.0.1:8085/';
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||undefined,args:['--no-sandbox']});try{
 const staffContext=await browser.newContext({viewport:{width:1440,height:1000}}),clientContext=await browser.newContext({viewport:{width:390,height:844}});
 const staff=await staffContext.newPage(),client=await clientContext.newPage(),errors=[];for(const p of [staff,client])p.on('pageerror',e=>errors.push(e.message));
 async function login(p,username,password){await p.goto(base);await p.locator('#live-login [name=username]').fill(username);await p.locator('#live-login [name=password]').fill(password);await p.locator('#live-login button[type=submit]').click();await p.locator('#new-request').waitFor();}
 await login(staff,'owner','Owner-test-pass-123');assert.equal(await staff.locator('#role-select').count(),0);assert.equal(await staff.locator('.preview-bar').count(),0);
 await staff.locator('[data-nav=settings]').click();
 await staff.locator('#workspace-form [name=name]').fill('UI Client');await staff.locator('#workspace-form [name=username]').fill('ui-client');await staff.locator('#workspace-form [name=password]').fill('UI-client-pass-123');await staff.locator('#workspace-form button[type=submit]').click();
 await staff.getByLabel('Client workspace').selectOption({label:'UI Client'});await staff.locator('[data-nav=requests]').click();
 await login(client,'ui-client','UI-client-pass-123');assert.equal(await client.locator('#workspace-select').count(),0);assert.equal(await client.locator('.request-card').count(),0);
 await client.locator('#new-request').click();await client.locator('#new-form [name=title]').fill('Shared production request');await client.locator('#new-form [name=brief]').fill('A shared brief visible to both client and staff.');await client.locator('#new-form [name=deadline]').fill('2026-12-04');
 await client.locator('#new-form [name=files]').setInputFiles({name:'reference.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+yN1kAAAAASUVORK5CYII=','base64')});
 await client.locator('#new-form button[type=submit]').click();await client.getByRole('heading',{name:'Shared production request',level:2}).waitFor();assert.equal(await client.locator('.attachment').count(),1);
 await client.getByRole('button',{name:'Close dialog'}).click();await client.reload();await client.getByRole('button',{name:'Open Shared production request'}).waitFor();
 await staff.reload();await staff.getByLabel('Client workspace').selectOption({label:'UI Client'});await staff.locator('[data-nav=requests]').click();await staff.getByRole('button',{name:'Open Shared production request'}).click();
 await staff.locator('#comment-form [name=action]').selectOption('design');await staff.locator('#comment-form [name=link]').fill('https://example.com/deliverable-v1');await staff.getByRole('textbox',{name:'Your message'}).fill('Please review the first version.');await staff.getByRole('button',{name:'Post update'}).click();await staff.getByText('submitted Version 1 for review',{exact:true}).waitFor();
 await client.reload();await client.getByRole('button',{name:'Open Shared production request'}).click();await client.getByRole('button',{name:'Approve version'}).click();await client.locator('#confirm-form [name=name]').fill('Client approver');await client.locator('#confirm-form button[type=submit]').click();await client.getByText('approved Version 1 · Completed',{exact:true}).waitFor();
 await client.getByRole('button',{name:'Close dialog'}).click();await client.getByRole('button',{name:'Open navigation'}).click();await client.locator('[data-nav=settings]').click();assert.equal(await client.locator('#workspace-form').count(),0);
 await client.locator('#password-form [name=currentPassword]').fill('UI-client-pass-123');await client.locator('#password-form [name=password]').fill('UI-client-updated-456');await client.locator('#password-form button[type=submit]').click();await client.getByText('Password updated. Other sessions have been signed out.').waitFor();
 assert.equal(await client.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
 await staff.getByRole('button',{name:'Close dialog'}).click();await staff.getByLabel('Client workspace').selectOption({label:'UI Client'});await staff.locator('[data-nav=completed]').click();assert.equal(await staff.getByRole('button',{name:'Open Shared production request'}).count(),1);
 await staff.screenshot({path:'/workspace/artifacts/live-portal-shared-workspace.png',fullPage:true});
 assert.deepEqual(errors,[]);console.log('PASS: real browser login, no demo role switch, staff workspace/account creation, isolated client UI, private file upload, cross-session shared request, persisted reload, staff deliverable submission, client version approval, password change, and mobile layout.');
 }finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
