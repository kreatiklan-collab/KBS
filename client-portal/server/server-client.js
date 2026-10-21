'use strict';
window.PORTAL_CONFIG={mode:'server'};
window.PortalServer=(()=>{
 let csrf='';
 async function call(route,data,query={}){
  const url=new URL('server/api.php',location.href);url.searchParams.set('route',route);for(const [k,v] of Object.entries(query))url.searchParams.set(k,v);
  const options={credentials:'same-origin',headers:{Accept:'application/json'}};
  if(data!==undefined){options.method='POST';options.headers['X-CSRF-Token']=csrf;if(data instanceof FormData)options.body=data;else{options.headers['Content-Type']='application/json';options.body=JSON.stringify(data);}}
  let response;try{response=await fetch(url,options);}catch{throw Error('Unable to reach the portal. Check your connection and retry.');}
  let result;try{result=await response.json();}catch{throw Error('The portal server did not return a valid response. Contact Kreatiklan.');}
  if(result.csrf)csrf=result.csrf;
  if(!response.ok){if(response.status===401&&route!=='login')dispatchEvent(new Event('portal-session-expired'));throw Error(result.error||'The request could not be completed.');}return result;
 }
 return {call,session:()=>call('session'),state:workspace=>call('state',undefined,{workspace}),upload:async(workspace,files)=>{const data=new FormData();data.set('workspace',workspace);for(const f of files)data.append('files[]',f);return (await call('upload',data)).files;},create:request=>call('request',request),event:(request,event,expectedEventId)=>call('event',{request,...event,expectedEventId}),notifications:async workspace=>(await call('notifications',undefined,{workspace})).notifications};
})();
