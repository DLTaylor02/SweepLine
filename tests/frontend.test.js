'use strict';
async function testFrontend(sources) {
  const events={},elements={},timers=[];
  class Element {
    constructor(id=''){Object.assign(this,{id,hidden:false,textContent:'',value:'',children:[],dataset:{},style:{},disabled:false,fields:{}});this.elements={namedItem:n=>this.fields[n]};}
    append(...nodes){this.children.push(...nodes);}
    replaceChildren(...nodes){this.children=nodes;}
    setAttribute(k,v){this[k]=v;}
    contains(){return false;}
    scrollIntoView(){}
    reset(){for(const field of Object.values(this.fields))field.value='';}
    addEventListener(n,fn){this[n]=fn;}
  }
  const get=id=>elements[id]??=new Element(id);
  for(const n of ['setup_code','name','email','password']){get('account-form').fields[n]=new Element();get('account-form')[n]=get('account-form').fields[n];}
  for(const n of ['name','email','password']){get('create-user').fields[n]=new Element();get('create-user')[n]=get('create-user').fields[n];}
  const search=get('search');
  for(const n of ['zip','radius','custom_radius','profile','min_length','max_length','max_stops','paved'])search[n]=new Element();
  search.zip.value='12345';search.radius.value='50';search.profile.value='twisty';search.max_stops.value='5';search.paved.checked=true;
  get('admin-panel').hidden=true;
  const document={getElementById:get,createElement:()=>new Element(),querySelectorAll:()=>[],documentElement:{dataset:{theme:'light'}},activeElement:null};
  const window={addEventListener:(n,fn)=>(events[n]??=[]).push(fn),dispatchEvent:e=>(events[e.type]||[]).forEach(fn=>fn(e))};
  let logged=false,setupNeeded=true,csrf='initial',searchResponses=[];
  const requests=[];
  const session=()=>({user:logged?{id:1,name:'Owner',role:'superadmin'}:null,csrf,setup_required:setupNeeded});
  const fetch=async(path,options={})=>{
    requests.push({path,...options});let status=200,data;
    if(path==='/api/session')data=session();
    else if(path==='/api/setup'){logged=true;setupNeeded=false;csrf='signed-in';data=session();}
    else if(path==='/api/logout'){logged=false;csrf='signed-out';data=session();}
    else if(path==='/api/search')data=searchResponses.shift()??{results:[],center:{place:'Synthetic place'},preparation:{state:'ready'}};
    else if(path==='/api/coverage'||path==='/api/admin/users')data=[];
    else if(path==='/api/admin/data')data={tasks:[],worker_heartbeat:null,zip_last_sync:null};
    else {status=401;data={error:'Please sign in'};}
    return {ok:status<400,status,json:async()=>data};
  };
  const setTimer=(fn,ms)=>{timers.push({fn,ms,active:true});return timers.length-1;};
  const clearTimer=id=>{if(timers[id])timers[id].active=false;};
  const run=source=>new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout',source)(window,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer);
  run(sources['account.js']);run(sources['app.js']);
  const flush=async()=>{for(let i=0;i<20;i++)await Promise.resolve();};
  let checks=0;const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
  await flush();
  check(get('workspace').hidden&&!get('account-form').hidden,'First run shows setup gate');
  check(!get('setup-code-wrap').hidden&&get('account-form').fields.setup_code.required,'Setup code required');
  get('account-form').email.value='owner@example.test';get('account-form').password.value='long-password-123';get('account-form').fields.name.value='Owner';get('account-form').setup_code.value='code';
  await get('account-form').onsubmit({preventDefault(){},target:get('account-form')});await flush();
  check(!get('workspace').hidden&&get('account-gate').hidden,'Setup reveals authenticated app');
  check(!get('admin-open').hidden,'SuperAdmin sees Manage');
  check(requests.find(r=>r.path==='/api/setup').headers['X-CSRF-Token']==='initial','Setup uses CSRF');
  searchResponses=[{results:[],preparation:{state:'preparing',message:'Downloading roads',complete:0,total:3}},{results:[],center:{place:'Synthetic place'},preparation:{state:'ready'}}];
  search.max_length.value='12.5';search.onsubmit({preventDefault(){},target:search});await flush();
  let req=requests.filter(r=>r.path==='/api/search').at(-1),payload=JSON.parse(req.body);
  check(payload.max_length_miles===12.5&&!('min_length_miles'in payload),'Optional bounds preserved');
  check(req.headers['X-CSRF-Token']==='signed-in','Search uses rotated CSRF');
  check(get('status').textContent.includes('0/3'),'Preparation progress displayed');
  let timer=timers.filter(t=>t.active&&t.ms===5000).at(-1);check(!!timer,'Preparation schedules polling');
  await timer.fn();await flush();check(get('status').textContent.includes('No roads match'),'Ready response ends preparation message');
  searchResponses=[{results:[],preparation:{state:'failed',message:'Provider unavailable',complete:0,total:3}}];
  const beforeFailure=timers.length;
  search.onsubmit({preventDefault(){},target:search});await flush();
  check(timers.length===beforeFailure&&get('status').textContent.includes('SuperAdmin'),'Terminal preparation failure stops polling with actionable message');
  get('admin-open').onclick();await flush();check(!get('admin-panel').hidden,'Manage opens');
  get('admin-close').onclick();check(get('admin-panel').hidden,'Manage closes');
  get('submit').disabled=true;
  await get('sign-out').onclick();await flush();
  check(get('workspace').hidden&&!get('account-gate').hidden,'Signout hides workspace');
  check(get('results').children.length===0,'Signout clears results');
  check(!get('submit').disabled,'Signout clears pending submit state');
  let lateCoverage=0;
  const lateWindow={...window,SweepLineAccount:{user:{id:1}},sweeplineRequest:async()=>{lateCoverage++;return [];}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout',sources['app.js'])(lateWindow,document,fetch,function(){},setTimer,clearTimer);
  await flush();check(lateCoverage===1,'Late-loading app hydrates an already authenticated session');
  return checks;
}
if(typeof require==='function'&&typeof module!=='undefined'&&require.main===module){
  const fs=require('node:fs'),path=require('node:path'),sources={};
  for(const file of ['account.js','app.js'])sources[file]=fs.readFileSync(path.join(__dirname,'../public/assets',file),'utf8');
  testFrontend(sources).then(count=>console.log(count+' frontend checks passed')).catch(e=>{console.error(e);process.exitCode=1;});
}
