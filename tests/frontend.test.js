'use strict';
async function testFrontend(sources) {
  const events={},elements={},timers=[];
  const SearchParams=globalThis.URLSearchParams || class {
    constructor(value){this.values=new Map(typeof value==='string'?value.replace(/^\?/,'').split('&').filter(Boolean).map(pair=>pair.split('=').map(decodeURIComponent)):value);}
    has(key){return this.values.has(key);}
    get(key){return this.values.get(key);}
    toString(){return [...this.values].map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');}
  };
  class Element {
    constructor(id=''){Object.assign(this,{id,hidden:false,textContent:'',value:'',children:[],dataset:{},style:{},disabled:false,fields:{}});this.elements={namedItem:n=>this.fields[n]};}
    append(...nodes){this.children.push(...nodes);}
    replaceChildren(...nodes){this.children=nodes;}
    setAttribute(k,v){this[k]=v;}
    removeAttribute(k){delete this[k];}
    contains(){return false;}
    scrollIntoView(){}
    reset(){for(const field of Object.values(this.fields))field.value='';}
    addEventListener(n,fn){this[n]=fn;}
  }
  const get=id=>elements[id]??=new Element(id);
  for(const n of ['username','password']){get('account-form').fields[n]=new Element();get('account-form')[n]=get('account-form').fields[n];}
  for(const n of ['username','name','email','password']){get('create-user').fields[n]=new Element();get('create-user')[n]=get('create-user').fields[n];}
  const search=get('search');
  for(const n of ['zip','radius','profile','min_length','max_length','max_stops','paved'])search[n]=new Element();
  search.zip.value='12345';search.radius.value='5';search.profile.value='twisty';search.max_stops.value='0';search.paved.checked=true;
  get('admin-panel').hidden=true;
  const document={getElementById:get,createElement:()=>new Element(),querySelectorAll:()=>[],documentElement:{dataset:{theme:'light'}},activeElement:null};
  const window={addEventListener:(n,fn)=>(events[n]??=[]).push(fn),dispatchEvent:e=>(events[e.type]||[]).forEach(fn=>fn(e))};
  let logged=false,adminReady=true,csrf='initial',searchResponses=[],jobCancelled=false,historyDeleted=false;
  let storage={available:true,database_bytes:100,other_bytes:500,free_bytes:400,total_bytes:1000,volumes:[{mount:'/'}],message:'Mounted local disks combined.'};
  const requests=[];
  const session=()=>({user:logged?{id:1,name:'Owner',role:'superadmin'}:null,csrf,admin_ready:adminReady});
  const fetch=async(path,options={})=>{
    requests.push({path,...options});let status=200,data;
    if(path==='/api/session')data=session();
    else if(path==='/api/login'){logged=true;csrf='signed-in';data=session();}
    else if(path==='/api/logout'){logged=false;csrf='signed-out';data=session();}
    else if(path==='/api/search')data=searchResponses.shift()??{results:[],center:{place:'Synthetic place'},preparation:{state:'ready'}};
    else if(path==='/api/search-history/1'&&options.method==='DELETE'){historyDeleted=true;data={deleted:true};}
    else if(path==='/api/search-history')data=historyDeleted?[]:[{id:1,created_at:'2026-10-19T12:00:00Z',parameters:{zip:'12345',radius_miles:5,profile:'twisty',max_stops:5,paved_only:true,min_length_miles:0,max_length_miles:null}}];
    else if(path==='/api/coverage'||path==='/api/admin/users')data=[];
    else if(path==='/api/admin/data')data={storage,tasks:[{id:42,kind:'roads',task_key:'test-area',status:jobCancelled?'cancelled':'running',progress:'Analyzing roads',cancel_requested:jobCancelled}],worker_heartbeat:null,zip_last_sync:null};
    else if(path==='/api/admin/tasks/42/cancel'){jobCancelled=true;data={cancel_requested:true};}
    else {status=401;data={error:'Please sign in'};}
    return {ok:status<400,status,json:async()=>data};
  };
  const setTimer=(fn,ms)=>{timers.push({fn,ms,active:true});return timers.length-1;};
  const clearTimer=id=>{if(timers[id])timers[id].active=false;};
  const run=source=>new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',source)(window,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  run(sources['account.js']);run(sources['app.js']);
  const flush=async()=>{for(let i=0;i<20;i++)await Promise.resolve();};
  let checks=0;const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
  await flush();
  check(get('workspace').hidden&&!get('account-form').hidden,'Terminal-provisioned accounts use sign-in form');
  check(!requests.some(r=>r.path==='/api/setup'),'Browser never requests account creation');
  get('account-form').username.value='superadmin';get('account-form').password.value='long-password-123';
  await get('account-form').onsubmit({preventDefault(){},target:get('account-form')});await flush();
  check(!get('workspace').hidden&&get('account-gate').hidden,'Login reveals authenticated app');
  check(!get('admin-open').hidden,'SuperAdmin sees Manage');
  check(requests.find(r=>r.path==='/api/login').headers['X-CSRF-Token']==='initial','Login uses CSRF');
  searchResponses=[{results:[],preparation:{state:'preparing',message:'Downloading roads',complete:0,total:3}},{results:[],center:{place:'Synthetic place'},preparation:{state:'ready'}}];
  search.max_length.value='12.5';search.onsubmit({preventDefault(){},target:search});
  check(!get('search-spinner').hidden&&!('value' in get('search-progress')),'Submitting immediately shows indeterminate preparation');
  await flush();
  let req=requests.filter(r=>r.path==='/api/search').at(-1),payload=JSON.parse(req.body);
  check(payload.max_stops===0,'New searches submit zero mapped stops by default');
  check(payload.radius_miles===5,'Numeric radius submitted directly');
  check(payload.max_length_miles===12.5&&!('min_length_miles'in payload),'Optional bounds preserved');
  check(payload.record_history===true,'New searches request a history entry');
  check(req.headers['X-CSRF-Token']==='signed-in','Search uses rotated CSRF');
  check(get('status').textContent.includes('0/3'),'Preparation progress displayed');
  check(!get('search-spinner').hidden&&!get('map-spinner').hidden,'Preparation spinner visible by search and map');
  check(get('search-progress').value===0&&get('search-progress').max===3,'Area progress uses actual completed coverage');
  check(get('search-feedback')['aria-busy']==='true','Preparation exposes accessible busy state');
  let timer=timers.filter(t=>t.active&&t.ms===5000).at(-1);check(!!timer,'Preparation schedules polling');
  await timer.fn();await flush();check(get('status').textContent.includes('No roads match'),'Ready response ends preparation message');
  check(get('search-spinner').hidden&&get('map-spinner').hidden&&get('search-progress').hidden,'Ready response clears all loading indicators');
  check(JSON.parse(requests.filter(r=>r.path==='/api/search').at(-1).body).record_history===false,'Background polling does not add search history');
  searchResponses=[{results:[{id:1,name:'Test road',score:70,length_m:5000,metrics:{curve_density:4},mandatory_stops:0,uncertain_controls:0,speed_mph:null,confidence:0.5}],preparation:{state:'preparing',message:'Analyzing roads',complete:1,total:3}}];
  search.onsubmit({preventDefault(){},target:search});await flush();
  check(get('status').textContent.includes('Showing partial results')&&get('results').children.length===1,'Available roads remain visible and labeled during preparation');
  check(get('search-progress').value===1,'Progress advances with completed areas');
  searchResponses=[{results:[],preparation:{state:'failed',message:'Provider unavailable',complete:0,total:3}}];
  const beforeFailure=timers.length;
  search.onsubmit({preventDefault(){},target:search});await flush();
  check(timers.length===beforeFailure&&get('status').textContent.includes('SuperAdmin'),'Terminal preparation failure stops polling with actionable message');
  check(get('search-spinner').hidden&&get('search-progress').hidden,'Terminal failure clears loading state');
  const jobWindow={...window,location:{pathname:'/jobs'}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(jobWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();check(!get('admin-panel').hidden,'Jobs opens as a dedicated page');
  check(!get('jobs-open').hidden,'SuperAdmin sees dedicated Jobs page link');
  check(!get('storage-pie').hidden&&get('storage-legend').children.length===3,'Jobs shows three storage categories');
  check(get('storage-pie').style.background.includes('10%')&&get('storage-pie').style.background.includes('60%'),'Storage pie proportions use database, other used, and free bytes');
  check(get('storage-pie')['aria-label'].includes('Free space: 400 B (40.0%)'),'Pie has equivalent accessible text with sizes and percentages');
  check(get('storage-note').textContent.includes('Volumes: /'),'Storage scope identifies measured volumes');
  const previousStorage=storage;storage={available:false,database_bytes:100,message:'Remote database; combined chart unavailable.'};
  await get('admin-refresh').onclick();await flush();
  check(get('storage-pie').hidden&&get('storage-legend').children.length===1,'Unavailable disk totals hide pie and retain database size');
  storage=previousStorage;await get('admin-refresh').onclick();await flush();
  const cancel=get('task-list').children[0].children.find(node=>node.textContent==='Cancel job');
  check(!!cancel,'Running jobs show cancellation control');
  await cancel.onclick();await flush();
  check(requests.some(r=>r.path==='/api/admin/tasks/42/cancel'&&r.headers['X-CSRF-Token']==='signed-in'),'Cancel action sends authenticated CSRF request');
  check(get('task-list').children.length===0,'Cancelled tasks disappear from the job queue');
  get('admin-panel').hidden=true;
  get('submit').disabled=true;
  await get('sign-out').onclick();await flush();
  check(get('workspace').hidden&&!get('account-gate').hidden,'Signout hides workspace');
  check(get('results').children.length===0,'Signout clears results');
  check(!get('submit').disabled,'Signout clears pending submit state');
  adminReady=false;
  get('account-retry').onclick();await flush();
  check(get('account-form').hidden&&get('account-intro').textContent.includes('terminal'),'Missing administrator directs operator to terminal without browser provisioning');
  const loginBody=JSON.parse(requests.find(r=>r.path==='/api/login').body);
  check(loginBody.username==='superadmin'&&!('email' in loginBody),'Login does not require email');
  let lateCoverage=0;
  const lateWindow={...window,SweepLineAccount:{user:{id:1}},sweeplineRequest:async()=>{lateCoverage++;return [];}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['app.js'])(lateWindow,document,fetch,function(){},setTimer,clearTimer,SearchParams);
  await flush();check(lateCoverage===1,'Late-loading app hydrates an already authenticated session');
  logged=true;adminReady=true;
  const jobsWindow={...window,location:{pathname:'/jobs'}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(jobsWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();
  check(!get('admin-panel').hidden&&get('workspace').hidden&&get('admin-users').hidden,'Dedicated Jobs page opens management without search or user forms');
  const beforeManage=requests.length;
  const manageWindow={...window,location:{pathname:'/manage'}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(manageWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();
  check(!get('admin-users').hidden&&get('admin-jobs').hidden&&get('workspace').hidden,'Manage page contains user management only');
  check(!requests.slice(beforeManage).some(r=>r.path==='/api/admin/data'),'Manage does not fetch job preparation');
  const historyWindow={...window,location:{pathname:'/history'}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(historyWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();
  check(!get('history-panel').hidden&&get('history-list').children.length===1,'Previous searches render on their own page');
  check(get('history-list').children[0].children.find(n=>n.textContent==='View results').href.includes('radius_miles=5'),'Run again link preserves search filters');
  const title=get('history-list').children[0].children[0];
  check(title.href.includes('open=1'),'History title opens results automatically');
  await get('history-list').children[0].children.at(-1).onclick();await flush();
  check(get('history-list').children.length===0&&get('history-status').textContent.includes('No previous searches'),'Deleted history entry disappears');
  check(requests.some(r=>r.path==='/api/search-history/1'&&r.method==='DELETE'&&!!r.headers['X-CSRF-Token']),'History deletion sends CSRF-protected request');
  const beforeReplay=requests.filter(r=>r.path==='/api/search').length;
  const replayWindow={...window,SweepLineAccount:{user:null},location:{pathname:'/',search:'?open=1&zip=12345&radius_miles=5&profile=open&max_stops=2&paved_only=false'}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['app.js'])(replayWindow,document,fetch,function(){},setTimer,clearTimer,SearchParams);
  check(search.radius.value==='5'&&search.profile.value==='open'&&search.paved.checked===false,'Replayed search restores saved form values');
  check(requests.filter(r=>r.path==='/api/search').length===beforeReplay,'Saved search waits for authentication');
  await events['sweepline:sessionchange'].at(-1)({detail:{user:{id:1}}});await flush();
  check(requests.filter(r=>r.path==='/api/search').length===beforeReplay+1,'Saved search automatically fetches results after authentication');
  check(JSON.parse(requests.filter(r=>r.path==='/api/search').at(-1).body).record_history===false,'Reopening saved search does not duplicate history');
  await events['sweepline:sessionchange'].at(-1)({detail:{user:{id:1}}});await flush();
  check(requests.filter(r=>r.path==='/api/search').length===beforeReplay+1,'Session refresh does not repeat saved search');
  return checks;
}
if(typeof require==='function'&&typeof module!=='undefined'&&require.main===module){
  const fs=require('node:fs'),path=require('node:path'),sources={};
  for(const file of ['account.js','app.js'])sources[file]=fs.readFileSync(path.join(__dirname,'../public/assets',file),'utf8');
  testFrontend(sources).then(count=>console.log(count+' frontend checks passed')).catch(e=>{console.error(e);process.exitCode=1;});
}
