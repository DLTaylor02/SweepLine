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
  for(const n of ['current_password','new_password','confirmation'])get('change-password')[n]=new Element();
  get('road-diagnostic').road=new Element();get('road-diagnostic').road.value='424';
  const search=get('search');
  for(const n of ['zip','radius','profile','min_length','max_length','max_stops','min_curve_density_score','min_curvature_quality_score','min_sustained_curve_ft','surface_filter','paved'])search[n]=new Element();
  search.zip.value='12345';search.radius.value='5';search.profile.value='twisty';search.max_stops.value='1';search.min_curve_density_score.value='2.2';search.min_curvature_quality_score.value='2.08';search.min_sustained_curve_ft.value='150';search.surface_filter.value='exclude_unpaved';search.paved.checked=true;
  get('admin-panel').hidden=true;
  const document={getElementById:get,createElement:()=>new Element(),createElementNS:()=>new Element(),querySelectorAll:()=>[],documentElement:{dataset:{theme:'light'}},activeElement:null};
  const observedElements=[];
  const window={ResizeObserver:class {constructor(callback){this.callback=callback;}observe(element){if(!element)throw new Error('Cannot observe missing element');observedElements.push(element);}},addEventListener:(n,fn)=>(events[n]??=[]).push(fn),dispatchEvent:e=>(events[e.type]||[]).forEach(fn=>fn(e))};
  let currentRole='superadmin',mustChange=false,redirectTarget=null;
  let logged=false,adminReady=true,csrf='initial',searchResponses=[],jobCancelled=false,historyDeleted=false,favoritesSaved=false;
  const favoriteRoad={id:1,name:'Test road',alternate_names:['Other Road Name'],score:70,length_m:5000,metrics:{curve_density:4},mandatory_stops:0,uncertain_controls:0,speed_mph:null,confidence:0.5,dimensions:{curvature:0.7},favorite_id:7,geometry:{type:'LineString',coordinates:[[-120,39],[-119.99,39.01]]}};
  const savedFavorite={id:7,profile:'twisty',created_at:'2026-10-19T12:00:00Z',road:favoriteRoad,search_parameters:{zip:'12345',radius_miles:5,profile:'twisty',max_stops:1,paved_only:true}};
  let storage={available:true,database_bytes:100,other_bytes:500,free_bytes:400,total_bytes:1000,volumes:[{mount:'/'}],message:'Mounted local disks combined.'};
  const requests=[];
  const session=()=>({user:logged?{id:1,name:'Owner',role:currentRole,must_change_password:mustChange}:null,csrf,admin_ready:adminReady});
  const fetch=async(path,options={})=>{
    requests.push({path,...options});let status=200,data;
    if(path==='/api/session')data=session();
    else if(path==='/api/login'){logged=true;csrf='signed-in';data=session();}
    else if(path==='/api/logout'){logged=false;csrf='signed-out';data=session();}
    else if(path==='/api/search-diagnostics')data={road:'424',parameters:{zip:'12345',radius_miles:5},ways:[{name:'Test road',ref:'CR 424',highway:'secondary',surface:null}],candidates:[{name:'Test road',ref:'CR 424',highway:'secondary',length_m:10000,mandatory_stops:1,uncertain_controls:0,reasons:['Not every piece is explicitly tagged as paved.']}],full_radius_covered:true,note:'Read-only local diagnosis'};
    else if(path==='/api/search')data=searchResponses.shift()??{results:[],center:{place:'Synthetic place'},preparation:{state:'ready'}};
    else if(path==='/api/search-history/1'&&options.method==='DELETE'){historyDeleted=true;data={deleted:true};}
    else if(path==='/api/search-history')data=historyDeleted?[]:[{id:1,city:'Test City',state:'NV',created_at:'2026-10-19T12:00:00Z',parameters:{zip:'12345',radius_miles:5,profile:'twisty',max_stops:5,paved_only:true,min_length_miles:0,max_length_miles:null}}];
    else if(path==='/api/favorites'&&options.method==='POST'){favoritesSaved=true;data={id:7,saved:true};}
    else if(path==='/api/favorites/7'&&options.method==='DELETE'){favoritesSaved=false;data={deleted:true};}
    else if(path==='/api/favorites/7')data=savedFavorite;
    else if(path==='/api/favorites')data=favoritesSaved?[savedFavorite]:[];
    else if(path==='/api/roads/1?profile=twisty')data={id:1,name:'Test road',alternate_names:['Other Road Name'],score:70,dimensions:{curvature:0.7}};
    else if(path==='/api/coverage')data=[];
    else if(path==='/api/admin/users')data=[{id:2,name:'Test member',username:'member',email:null,role:'member',active:true,must_change_password:true}];
    else if(path==='/api/password-policy')data={minimum_length:12,strength:'standard',screen_compromised:true};
    else if(path==='/api/admin/session-settings')data=JSON.parse(options.body);
    else if(path==='/api/password/change')data={changed:true,redirect:'/'};
    else if(path==='/api/admin/password-policy')data=JSON.parse(options.body);
    else if(path==='/api/admin/storage')data={database_budget_mb:20480,resumed_jobs:2};
    else if(path==='/api/admin/data')data={settings:{database_budget_mb:5120},storage,tasks:[{id:42,kind:'roads',task_key:'test-area',status:jobCancelled?'cancelled':'running',progress:'Analyzing roads',cancel_requested:jobCancelled}],worker_heartbeat:null,zip_last_sync:null};
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
  check(!get('search-stages').hidden&&get('search-stage-track').children.length===5&&get('search-stage-label').textContent.includes('Coverage'),'Search immediately shows staged coverage check');
  await flush();
  let req=requests.filter(r=>r.path==='/api/search').at(-1),payload=JSON.parse(req.body);
  check(observedElements.includes(get('map'))&&observedElements.includes(get('road-detail')),'Browser ResizeObserver attaches without blocking Discovery initialization');
  check(payload.min_curve_density_score===2.2,'Curve density uses normalized default in search requests');
  check(payload.surface_filter==='exclude_unpaved'&&payload.min_curvature_quality_score===2.08&&payload.min_sustained_curve_ft===150,'Advanced filter defaults are submitted');
  check(payload.max_stops===1,'New searches submit one mapped stop by default');
  await get('road-diagnostic').onsubmit({preventDefault(){},target:get('road-diagnostic')});await flush();
  check(get('diagnostic-status').textContent.includes('1 continuous candidates'),'Missing-road checker shows candidate count');
  check(get('diagnostic-results').children[0].children.some(n=>n.textContent.includes('explicitly tagged as paved')),'Missing-road checker shows actual rejection reason');
  check(JSON.parse(requests.find(r=>r.path==='/api/search-diagnostics').body).road==='424','Missing-road checker submits road query with current filters');
  check(payload.radius_miles===5,'Numeric radius submitted directly');
  check(payload.max_length_miles===12.5&&!('min_length_miles'in payload),'Optional bounds preserved');
  check(payload.record_history===true,'New searches request a history entry');
  check(req.headers['X-CSRF-Token']==='signed-in','Search uses rotated CSRF');
  check(get('status').textContent.includes('0/3'),'Preparation progress displayed');
  check(!get('search-spinner').hidden&&!get('map-spinner').hidden,'Preparation spinner visible by search and map');
  check(get('search-progress').value===0&&get('search-progress').max===3,'Area progress uses actual completed coverage');
  check(get('search-stage-label').textContent.includes('Download areas'),'Uncached coverage shows download stage');
  check(get('search-feedback')['aria-busy']==='true','Preparation exposes accessible busy state');
  let timer=timers.filter(t=>t.active&&t.ms===5000).at(-1);check(!!timer,'Preparation schedules polling');
  await timer.fn();await flush();check(get('status').textContent.includes('No roads match'),'Ready response ends preparation message');
  check(get('search-spinner').hidden&&get('map-spinner').hidden&&get('search-progress').hidden,'Ready response clears all loading indicators');
  check(!get('search-stages').hidden&&get('search-stage-track').children.every(n=>n.className==='is-complete'),'Ready response fills every search stage even for empty results');
  check(JSON.parse(requests.filter(r=>r.path==='/api/search').at(-1).body).record_history===false,'Background polling does not add search history');
  search.min_curve_density_score.value='4.5';
  searchResponses=[{results:[{id:1,name:'Test road',score:70,length_m:5000,metrics:{curve_density:4},mandatory_stops:0,uncertain_controls:0,speed_mph:null,confidence:0.5}],preparation:{state:'preparing',message:'Analyzing roads',complete:1,total:3}}];
  search.onsubmit({preventDefault(){},target:search});await flush();
  check(get('status').textContent.includes('Showing partial results')&&get('results').children.length===1,'Available roads remain visible and labeled during preparation');
  check(get('search-progress').value===1,'Progress advances with completed areas');
  check(get('search-stage-label').textContent.includes('Analyze roads')&&get('search-progress-label').textContent.includes('1 of 3'),'Analysis stage keeps actual partial-area counts');
  check(JSON.parse(requests.filter(r=>r.path==='/api/search').at(-1).body).min_curve_density_score===4.5,'User-defined fractional density is submitted');
  get('results').children[0].onclick();await flush();
  check(get('road-detail').children.find(n=>n.className==='road-name-picker').children.some(n=>n.textContent==='Other Road Name'),'Road details display clickable alternate names without duplicate text');

  const paddingSource=sources['app.js'].match(/function roadViewportPadding\([\s\S]*?\n\}/)[0];
  const padding=new Function(paddingSource+';return roadViewportPadding;')();
  const desktopPadding=padding(1100,700,350,250,false);
  check(desktopPadding.right>=390&&1100-desktopPadding.left-desktopPadding.right>=600,'Desktop camera reserves the road card width and leaves map space');
  const mobilePadding=padding(390,400,366,180,true);
  check(mobilePadding.bottom>=216&&400-mobilePadding.top-mobilePadding.bottom>=100,'Narrow camera reserves the bottom card and endpoint clearance');
  const scoreDetails=get('road-detail').children.find(n=>n.className==='road-breakdown');
  check(scoreDetails&&scoreDetails.children[0].textContent.includes('Score breakdown')&&!scoreDetails.open,'Score details are expandable and collapsed initially');

  const exportLinks=get('road-detail').children.find(node=>node.className==='road-exports').children;
  check(exportLinks.length===3&&exportLinks[2].textContent==='PDF','Selected road offers GPX, KMZ and PDF exports');
  check(exportLinks[2].target==='_blank'&&exportLinks[2].rel==='noopener'&&!('download' in exportLinks[2]),'PDF opens a new preview tab without a forced download');
  check('download' in exportLinks[0]&&'download' in exportLinks[1],'GPX and KMZ remain downloads');
  check(exportLinks[0].href==='/api/roads/1/export?format=gpx&profile=twisty&orientation=forward&preferred_name=Test%20road','Export targets selected road and active profile');
  const swapDirection=get('road-detail').children.find(n=>n.className==='road-direction').children.find(n=>n.className==='road-direction-swap');
  swapDirection.onclick();
  check(get('road-detail').children.find(n=>n.className==='road-exports').children.every(n=>n.href.includes('orientation=reverse')),'Swapping endpoints updates all export formats');
  check(swapDirection['aria-pressed']==='true','Swap direction exposes accessible selected state');
  swapDirection.onclick();
  const nameChoices=get('road-detail').children.find(n=>n.className==='road-name-picker');
  nameChoices.children.find(n=>n.textContent==='Other Road Name').onclick();
  check(get('road-detail').children[1].children[0].textContent==='Other Road Name','Clicking alias changes displayed primary name');
  check(get('road-detail').children.find(n=>n.className==='road-exports').children.every(n=>n.href.includes('preferred_name=Other%20Road%20Name')),'Selected alias propagates to each export link');
  nameChoices.children.find(n=>n.textContent==='Test road').onclick();
  const heart=get('road-detail').children.find(node=>node.className==='road-direction').children.find(node=>node.className==='favorite-heart');
  await heart.onclick();await flush();
  check(heart['aria-pressed']==='true'&&heart.textContent.includes('Favorited'),'Heart saves selected road');
  const favoriteRequest=requests.find(r=>r.path==='/api/favorites'&&r.method==='POST');
  check(JSON.parse(favoriteRequest.body).search.zip==='12345'&&!!favoriteRequest.headers['X-CSRF-Token'],'Favorite captures search context with CSRF');

  check(JSON.parse(favoriteRequest.body).preferred_name==='Test road'&&JSON.parse(favoriteRequest.body).orientation==='forward','New Favorite captures preferred name and endpoint orientation');
  nameChoices.children.find(n=>n.textContent==='Other Road Name').onclick();await flush();
  const preferenceRequest=requests.filter(r=>r.path==='/api/favorites/7/preferences').at(-1);
  check(preferenceRequest&&JSON.parse(preferenceRequest.body).preferred_name==='Other Road Name'&&!!preferenceRequest.headers['X-CSRF-Token'],'Changing a saved Favorite name uses owner preference POST with CSRF');
  nameChoices.children.find(n=>n.textContent==='Test road').onclick();await flush();
  await heart.onclick();await flush();
  check(heart['aria-pressed']==='false'&&!favoritesSaved,'Heart removes saved road');
  searchResponses=[{results:[],preparation:{state:'failed',message:'Provider unavailable',complete:0,total:3}}];
  const beforeFailure=timers.length;
  search.onsubmit({preventDefault(){},target:search});await flush();
  check(timers.length===beforeFailure&&get('status').textContent.includes('SuperAdmin'),'Terminal preparation failure stops polling with actionable message');
  check(get('search-spinner').hidden&&get('search-progress').hidden,'Terminal failure clears loading state');
  check(get('search-stages').hidden,'Failed search hides active stage bar while keeping error message');
  const jobWindow={...window,location:{pathname:'/manage'}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(jobWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();check(!get('admin-panel').hidden,'Jobs opens as a dedicated page');
  check(!get('jobs-open').hidden,'SuperAdmin sees dedicated Jobs page link');
  check(!get('storage-pie').hidden&&get('storage-legend').children.length===3,'Jobs shows three storage categories');
  check(get('storage-pie').style.background.includes('10%')&&get('storage-pie').style.background.includes('60%'),'Storage pie proportions use database, other used, and free bytes');
  check(get('storage-pie')['aria-label'].includes('Free space: 400 B (40.0%)'),'Pie has equivalent accessible text with sizes and percentages');
  check(get('storage-note').textContent.includes('Volumes: /'),'Storage scope identifies measured volumes');
  check(get('database-budget').value==='5120','Manage displays database storage limit');
  get('database-budget').value='20480';
  await get('storage-settings').onsubmit({preventDefault(){}});await flush();
  check(requests.some(r=>r.path==='/api/admin/storage'&&r.method==='PATCH'&&r.headers['X-CSRF-Token']==='signed-in'&&JSON.parse(r.body).database_budget_mb===20480),'Storage limit save sends authenticated CSRF request');
  check(get('storage-settings-status').textContent.includes('2 blocked jobs'),'Storage limit save reports resumed work');
  check(get('session-days').value==='7','Manage displays seven-day session default');
  get('session-days').value='14';
  await get('session-settings').onsubmit({preventDefault(){}});await flush();
  check(requests.some(r=>r.path==='/api/admin/session-settings'&&r.method==='POST'&&r.headers['X-CSRF-Token']&&JSON.parse(r.body).session_timeout_days===14),'Session setting saves with authenticated CSRF POST');
  check(get('session-settings-status').textContent.includes('saved'),'Session setting save is confirmed');
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
  const jobsWindow={...window,location:{pathname:'/manage'}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(jobsWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();
  check(!get('admin-panel').hidden&&get('workspace').hidden&&get('admin-users').hidden,'Dedicated Jobs page opens management without search or user forms');
  const beforeManage=requests.length;
  const manageWindow={...window,location:{pathname:'/users'}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(manageWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();
  check(!get('admin-users').hidden&&get('admin-jobs').hidden&&get('workspace').hidden,'Manage page contains user management only');
  check(get('user-list').children[0].children.length===5&&get('user-list').children[0].children[4].dataset.label==='Reset password','Each user renders in five aligned account-management columns');
  check(!requests.slice(beforeManage).some(r=>r.path==='/api/admin/data'),'Manage does not fetch job preparation');

  currentRole='admin';
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(manageWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();
  check(!get('admin-users').hidden&&!get('admin-open').hidden&&get('jobs-open').hidden,'Admin can access Users but Manage link is hidden');
  check(get('create-role-label').hidden,'Admin cannot assign roles');
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(jobsWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();
  check(get('admin-jobs').hidden,'Admin denied Manage page');
  currentRole='member';
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(manageWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();
  check(get('admin-users').hidden&&get('admin-open').hidden&&get('jobs-open').hidden,'User has no management access');
  currentRole='superadmin';
  const historyWindow={...window,location:{pathname:'/history'}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(historyWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();
  check(!get('history-panel').hidden&&get('history-list').children.length===1,'Previous searches render on their own page');
  check(get('history-list').children[0].children[4].children.find(n=>n.textContent==='View results').href.includes('radius_miles=5'),'Run again link preserves search filters');
  const title=get('history-list').children[0].children[0].children[0];
  check(title.textContent==='Test City, NV','ZIP city is primary History label');
  check(get('history-list').children[0].children.length===5,'History entries have five aligned columns');
  check(title.href.includes('open=1'),'History title opens results automatically');
  await get('history-list').children[0].children[4].children.at(-1).onclick();await flush();
  check(get('history-list').children.length===0&&get('history-status').textContent.includes('No previous searches'),'Deleted history entry disappears');
  check(requests.some(r=>r.path==='/api/search-history/1'&&r.method==='DELETE'&&!!r.headers['X-CSRF-Token']),'History deletion sends CSRF-protected request');
  const beforeReplay=requests.filter(r=>r.path==='/api/search').length;
  const replayWindow={...window,SweepLineAccount:{user:null},location:{pathname:'/',search:'?open=1&zip=12345&radius_miles=5&profile=open&max_stops=2&paved_only=false'}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['app.js'])(replayWindow,document,fetch,function(){},setTimer,clearTimer,SearchParams);
  check(search.radius.value==='5'&&search.profile.value==='open'&&search.surface_filter.value==='any','Replayed search restores saved form values');
  check(requests.filter(r=>r.path==='/api/search').length===beforeReplay,'Saved search waits for authentication');
  await events['sweepline:sessionchange'].at(-1)({detail:{user:{id:1}}});await flush();
  check(requests.filter(r=>r.path==='/api/search').length===beforeReplay+1,'Saved search automatically fetches results after authentication');
  check(JSON.parse(requests.filter(r=>r.path==='/api/search').at(-1).body).record_history===false,'Reopening saved search does not duplicate history');
  await events['sweepline:sessionchange'].at(-1)({detail:{user:{id:1}}});await flush();
  check(requests.filter(r=>r.path==='/api/search').length===beforeReplay+1,'Session refresh does not repeat saved search');
  const saved=new Map([['sweepline:active-search:1',JSON.stringify({zip:'12345',radius_miles:5,profile:'twisty',max_stops:0,paved_only:true})]]);
  const tabStorage={getItem:key=>saved.get(key)??null,setItem:(key,value)=>saved.set(key,value),removeItem:key=>saved.delete(key)};
  const resumeEvents={};let resumed=0;
  const resumeWindow={location:{pathname:'/',search:''},sessionStorage:tabStorage,SweepLineAccount:{user:{id:1}},
    addEventListener:(name,fn)=>resumeEvents[name]=fn,
    sweeplineRequest:async path=>{
      if(path==='/api/search'){resumed++;return {results:[],preparation:{state:'preparing',message:'Analyzing connected roads and driving profiles',complete:3,total:9}};}
      return [];
    }};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['app.js'])(resumeWindow,document,fetch,function(){},setTimer,clearTimer,SearchParams);
  await flush();
  check(resumed===1&&get('status').textContent.includes('3/9'),'Returning to discovery restores current preparation progress');
  check(!get('search-spinner').hidden&&get('search-progress').value===3,'Returning search displays spinner and real area progress');
  check(search.radius.value==='5'&&search.max_stops.value==='1'&&search.min_curve_density_score.value==='2.2','Returning search upgrades a legacy zero stop limit to one');
  await resumeEvents['sweepline:sessionchange']({detail:{user:{id:1}}});await flush();
  check(resumed===1,'Repeated session notification does not start duplicate search');
  resumeEvents.pageshow({persisted:true});await flush();
  check(resumed===2,'Browser back/forward cache refreshes current preparation');
  await resumeEvents['sweepline:sessionchange']({detail:{user:null}});
  check(!saved.has('sweepline:active-search:1'),'Signing out removes remembered search');
  saved.set('sweepline:active-search:1',JSON.stringify({zip:'12345',radius_miles:5,profile:'twisty',max_stops:0,paved_only:true}));
  const otherWindow={...resumeWindow,SweepLineAccount:{user:{id:2}}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['app.js'])(otherWindow,document,fetch,function(){},setTimer,clearTimer,SearchParams);
  await flush();
  check(resumed===2,'Another account cannot resume the previous user search');
  favoritesSaved=true;
  const favoritesWindow={...window,location:{pathname:'/favorites'}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(favoritesWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();
  check(!get('favorites-panel').hidden&&get('workspace').hidden,'Favorites has a dedicated page');
  const card=get('favorites-list').children[0];
  check(card.children[0].href==='/?favorite=7'&&card.children[0].children[0].src==='/api/favorites/7/thumbnail','Favorite card has geographic thumbnail and direct map link');
  check(card.children[0].children.some(n=>n.textContent==='Also known as: Other Road Name'),'Favorite cards display alternate names');
  check(card.children[0].children[0].loading==='lazy'&&card.children[0].children[0].alt.includes('basemap'),'Geographic thumbnails load lazily with accessible descriptions');
  await card.children[1].onclick();await flush();
  check(get('favorites-list').children.length===0,'Favorites page can remove a road');
  const favoriteMapWindow={...window,SweepLineAccount:{user:{id:1}},location:{pathname:'/',search:'?favorite=7'},sweeplineRequest:async path=>path==='/api/favorites/7'?savedFavorite:[]};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['app.js'])(favoriteMapWindow,document,fetch,function(){},setTimer,clearTimer,SearchParams);
  await flush();
  check(get('results').children.length===1&&get('road-detail').children.some(node=>node.textContent==='Test road · 70/100'),'Opening a favorite restores saved road result and details');
  check(get('road-detail').children.find(node=>node.className==='road-exports').children[0].href==='/api/favorites/7/export?format=gpx&orientation=forward&preferred_name=Test%20road','Saved road exports do not require active candidate ID');
  check(get('road-detail').children.find(node=>node.className==='road-exports').children[2].target==='_blank','Favorite PDFs also open a preview tab');

  const mapReturnEvents={},documentReturnEvents={},frames=[];
  const returnDocument={...document,visibilityState:'visible',addEventListener:(name,handler)=>documentReturnEvents[name]=handler};
  const returnWindow={location:{pathname:'/',search:''},requestAnimationFrame:callback=>frames.push(callback),addEventListener:(name,handler)=>mapReturnEvents[name]=handler};
  const mapReturn=new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['app.js']+'\nreturn {setMap:value=>map=value};')(returnWindow,returnDocument,fetch,function(){},setTimer,clearTimer,SearchParams);
  let mapResizes=0,mapRepaints=0;
  const activeMap={resize:()=>mapResizes++,triggerRepaint:()=>mapRepaints++};
  mapReturn.setMap(activeMap);get('workspace').hidden=false;
  const requestsBeforeReturn=requests.length;
  mapReturnEvents.focus();documentReturnEvents.visibilitychange();
  check(frames.length===1,'Tab-return events coalesce into one map recovery frame');
  frames.shift()();
  check(mapResizes===1&&mapRepaints===1,'Returning from PDF refreshes layout and repaints existing map');
  check(requests.length===requestsBeforeReturn,'Map recovery does not rerun searches or reload results');
  returnDocument.visibilityState='hidden';mapReturnEvents.focus();
  check(frames.length===0,'Hidden tab does not repaint');
  returnDocument.visibilityState='visible';documentReturnEvents.visibilitychange();get('workspace').hidden=true;frames.shift()();
  check(mapResizes===1,'Pending recovery skips hidden discovery workspace');
  get('workspace').hidden=false;mapReturnEvents.pageshow({persisted:false});frames.shift()();
  check(mapResizes===2&&mapRepaints===2,'Visible page-show restores map without a search');

  const rebuiltMaps=[],pdfFrames=[],pdfEvents={};
  class RestoredMap {
    constructor(options){this.options=options;this.handlers={};rebuiltMaps.push(this);}
    on(name,layer,handler){if(typeof layer==='function')this.handlers[name]=layer;}
    addControl(){} setPadding(padding){this.padding=padding;}
    getLayer(id){return this.options.style.layers.find(layer=>layer.id===id);}
    setPaintProperty(){} resize(){} triggerRepaint(){}
  }
  const pinInstances=[];
  const lib={Map:RestoredMap,NavigationControl:class {},Marker:class {constructor(options){this.options=options;pinInstances.push(this);}setLngLat(p){this.position=p;return this;}addTo(map){this.map=map;return this;}remove(){this.removed=true;}}};
  const pdfDocument={...document,visibilityState:'visible',addEventListener:()=>{}};
  const pdfWindow={maplibregl:lib,location:{pathname:'/',search:''},requestAnimationFrame:callback=>pdfFrames.push(callback),addEventListener:(name,handler)=>pdfEvents[name]=handler};
  const pdfMap=new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams','maplibregl',sources['app.js']+'\nreturn {setMap:value=>map=value,markPdfPreview,drawRoadEndpoints,clearRoadEndpoints};')(pdfWindow,pdfDocument,fetch,function(){},setTimer,clearTimer,SearchParams,lib);
  pdfMap.setMap({});const pinRoad={geometry:{coordinates:[[-119,39],[-118,40]]},travel_direction:0};
  pdfMap.drawRoadEndpoints(pinRoad,'forward');
  check(pinInstances[0].position[0]===-119&&pinInstances[1].position[0]===-118,'Map shows Start and Finish at selected road endpoints');
  pdfMap.drawRoadEndpoints(pinRoad,'reverse');
  check(pinInstances[2].position[0]===-118&&pinInstances[3].position[0]===-119&&pinInstances[0].removed,'Swapping exchanges endpoint pins without leaving old markers');
  pdfMap.clearRoadEndpoints();check(pinInstances[2].removed&&pinInstances[3].removed,'Clearing road selection removes endpoint pins');
  const preservedStyle={version:8,sources:{roads:{type:'geojson',data:{type:'FeatureCollection',features:[{id:7}]}},radius:{type:'geojson',data:{type:'Feature',geometry:{type:'Polygon',coordinates:[]}}}},layers:[{id:'basemap'},{id:'selected',filter:['==',['get','id'],7]}]};
  let removedOldMap=false;
  pdfMap.setMap({getLayer:()=>true,getStyle:()=>preservedStyle,getCenter:()=>({lng:-119.8,lat:39.5}),getZoom:()=>12,getBearing:()=>25,getPitch:()=>20,getPadding:()=>({top:65,right:65,bottom:65,left:65}),remove:()=>removedOldMap=true});
  get('workspace').hidden=false;
  const requestsBeforePdf=requests.length;
  pdfMap.markPdfPreview();pdfEvents.blur();pdfEvents.focus();pdfFrames.shift()();
  check(removedOldMap&&rebuiltMaps.length===1,'Returning from PDF replaces stale canvas with a fresh map');
  const rebuilt=rebuiltMaps[0];
  check(rebuilt.options.center.lng===-119.8&&rebuilt.options.zoom===12&&rebuilt.options.bearing===25&&rebuilt.options.pitch===20&&rebuilt.padding.top===65,'PDF recovery retains viewport and padding');
  check(rebuilt.options.style.sources.roads.data.features[0].id===7&&rebuilt.options.style.sources.radius.data.type==='Feature'&&rebuilt.options.style.layers[1].filter[2]===7,'PDF recovery retains road data, radius and selection');
  rebuilt.handlers.load();await flush();pdfFrames.shift()();
  pdfEvents.focus();pdfFrames.shift()();
  check(rebuiltMaps.length===1,'PDF return replaces canvas only once');
  check(requests.length===requestsBeforePdf,'PDF canvas recovery does not query roads or import data');
  let pickerReply={zip:'01234',place:'Test place',state:'ZZ',distance_m:1609.344},pickerError=false;
  const pickerWindow={addEventListener(){},SweepLineAccount:{user:null},sweeplineRequest:async path=>{
    check(path.startsWith('/api/zips/nearest?lon='),'Map selection uses local coordinate lookup endpoint');
    if(pickerError)throw Error('No nearby ZIP center');return pickerReply;
  }};
  const picker=new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['app.js']+'\nreturn {pickZipAt,setMap:value=>map=value};')(pickerWindow,document,fetch,function(){},setTimer,clearTimer,SearchParams);
  picker.setMap({getCanvas:()=>({style:{}})});
  get('pick-zip').onclick();
  check(get('pick-zip')['aria-pressed']==='true'&&!get('map-pick-note').hidden,'Picker shows map selection instructions');
  await picker.pickZipAt({lngLat:{lng:-100,lat:40}});
  check(search.zip.value==='01234'&&get('zip-pick-status').textContent.includes('1.0 miles'),'Map selection fills ZIP including leading zero and distance');
  check(get('map-pick-note').hidden&&!get('pick-zip').disabled,'Successful selection exits picking state');
  pickerError=true;get('pick-zip').onclick();await picker.pickZipAt({lngLat:{lng:0,lat:0}});
  check(search.zip.value==='01234'&&get('zip-pick-status').textContent.includes('No nearby'),'Missing ZIP leaves existing input intact with explanation');
  get('pick-zip').onclick();get('pick-zip').onclick();
  check(get('map-pick-note').hidden,'Map picking can be cancelled');


  let compositeSaved=null,compositeRequested=null;
  const compositeRoad={...favoriteRoad,id:11,favorite_id:null,name:'Combined Highway',member_ids:[11,12],alternate_names:['Alternate Highway']};
  const compositeWindow={location:{pathname:'/',search:''},addEventListener(){},sweeplineRequest:async(path,options={})=>{
    if(path.startsWith('/api/roads/11?')) {compositeRequested=path;return compositeRoad;}
    if(path==='/api/favorites') {compositeSaved=JSON.parse(options.body);return {id:99};}
    return [];
  }};
  const compositeUI=new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['app.js']+'\nreturn {selectRoad,render,setRoads:value=>roads=value};')(compositeWindow,document,fetch,function(){},setTimer,clearTimer,SearchParams);
  compositeUI.setRoads([compositeRoad]);compositeUI.render();await compositeUI.selectRoad(11);
  check(compositeRequested.includes('members=11%2C12'),'Composite details retain complete member geometry');
  const compositeExports=get('road-detail').children.find(n=>n.className==='road-exports').children;
  check(compositeExports.every(n=>n.href.includes('members=11%2C12')),'All composite exports carry the same selected member IDs');
  await get('road-detail').children.find(n=>n.className==='road-direction').children.find(n=>n.className==='favorite-heart').onclick();
  check(compositeSaved.member_ids.join(',')==='11,12','Favoriting preserves complete consolidated road');

  let customChecks=0,customSavedInput=null,customBeamContext=null;
  const customFixture={...compositeRoad,id:81,custom_id:55,member_ids:[81],favorite_id:null,preferred_orientation:'forward'};
  const customWindow={...window,location:{pathname:'/',search:''},sweeplineBeamExport:(container,road,context)=>customBeamContext=context,sweeplineRequest:async(path,options={})=>{
    if(path==='/api/custom-roads'){
      customChecks++;
      return customChecks===1?{preparation:{state:'preparing',message:'Loading roads',complete:0,total:2}}:{road:customFixture,parameters:{zip:'12345',profile:'twisty',radius_miles:5},preparation:{state:'ready'}};
    }
    if(path==='/api/favorites'){customSavedInput=JSON.parse(options.body);return {id:101};}
    throw Error('Unexpected custom-road request '+path);
  }};
  const customUI=new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['app.js']+'\nreturn {setMap:value=>{map=value;ready=Promise.resolve();},pickCustomRoadAt};')(customWindow,document,fetch,function(){},setTimer,clearTimer,SearchParams);
  const customSource={setData(value){this.data=value;}};customUI.setMap({getCanvas:()=>({style:{}}),getSource:()=>customSource,getLayer:()=>true,setFilter(){}});
  get('pick-custom-road').onclick();await customUI.pickCustomRoadAt({lngLat:{lng:-119.8,lat:39.503}});
  check(get('custom-road-status').textContent.includes('finish'),'First custom click requests the second point');
  await customUI.pickCustomRoadAt({lngLat:{lng:-119.8,lat:39.509}});
  check(get('custom-road-status').textContent.includes('0/2'),'Custom picker shows automatic coverage preparation');
  await timers.filter(t=>t.active&&t.ms===5000).at(-1).fn();await flush();
  const customLinks=get('road-detail').children.find(n=>n.className==='road-exports').children;
  check(customLinks.every(n=>n.href.includes('/api/custom-roads/55/export')),'Every standard export uses server-validated custom selection');
  check(customBeamContext.customId===55,'BeamNG uses custom selection rather than the full source candidate');
  const customHeart=get('road-detail').children.find(n=>n.className==='road-direction').children.find(n=>n.className==='favorite-heart');
  await customHeart.onclick();await flush();check(customSavedInput.custom_id===55,'Custom favorite sends selection ID for server-owned geometry');
  get('pick-custom-road').onclick();get('pick-custom-road').onclick();
  check(get('custom-road-status').textContent.includes('cancelled'),'Two-point selection can be cancelled');


  const areaFrames=[],areaPaints=[];
  const areaWindow={location:{pathname:'/',search:''},addEventListener(){}};
  const areaUI=new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['app.js']+'\nreturn {updatePreparationAreas,setMap:value=>map=value};')(areaWindow,document,fetch,function(){},setTimer,clearTimer,SearchParams);
  areaUI.setMap({getSource:id=>id==='preparation-areas'?{setData:value=>areaFrames.push(value)}:null,getLayer:()=>true,setPaintProperty:(...args)=>areaPaints.push(args)});
  const areaData={type:'FeatureCollection',features:['queued','running','complete'].map(status=>({type:'Feature',properties:{status},geometry:{type:'Polygon',coordinates:[[[-120,39],[-119,39],[-119,40],[-120,40],[-120,39]]]}}))};
  areaUI.updatePreparationAreas({state:'preparing',areas:areaData});
  check(areaFrames.at(-1).features.map(f=>f.properties.status).join(',')==='queued,running,complete'&&!get('preparation-legend').hidden,'Preparation overlays preserve queued, active and completed region states');
  check(areaPaints.some(p=>p[0]==='preparation-fill'&&p[2][1][2]==='running'),'Pulse applies only to active region opacity');
  const pulse=timers.filter(t=>t.active&&t.ms===150).at(-1),beforePulse=areaPaints.length;
  pulse.active=false;pulse.fn();check(areaPaints.length>beforePulse,'Active regions pulse as status polling continues');
  areaUI.updatePreparationAreas({state:'ready',areas:areaData});
  check(areaFrames.at(-1).features.length===0&&get('preparation-legend').hidden&&!timers.some(t=>t.active&&t.ms===150),'Completed search clears regions, legend and pulse timer');
  areaUI.updatePreparationAreas({state:'preparing',areas:areaData});areaUI.updatePreparationAreas(null);
  check(areaFrames.at(-1).features.length===0&&get('preparation-legend').hidden,'New search or sign-out clears stale geographic preparation');

  logged=true;mustChange=true;
  const forcedWindow={...window,location:{pathname:'/',replace:p=>redirectTarget=p}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(forcedWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();
  check(redirectTarget==='/password'&&get('workspace').hidden,'Flagged session redirected without discovery');
  const passwordWindow={...window,location:{pathname:'/password',assign:p=>redirectTarget=p}};
  new Function('window','document','fetch','CustomEvent','setTimeout','clearTimeout','URLSearchParams',sources['account.js'])(passwordWindow,document,fetch,function(type,opts){this.type=type;this.detail=opts.detail;},setTimer,clearTimer,SearchParams);
  await flush();
  check(!get('password-panel').hidden&&get('password-back').hidden&&get('admin-open').hidden&&get('history-open').hidden,'Forced password page hides unrelated actions');
  const change=get('change-password');change.current_password.value='old';change.new_password.value='New-pass123!';change.confirmation.value='wrong';
  await change.onsubmit({preventDefault(){},target:change});await flush();
  check(get('password-status').textContent.includes('match'),'Confirmation mismatch shown');
  change.confirmation.value='New-pass123!';
  await change.onsubmit({preventDefault(){},target:change});await flush();
  check(requests.some(r=>r.path==='/api/password/change'&&r.method==='POST'&&!!r.headers['X-CSRF-Token']),'Password change uses CSRF POST');
  check(redirectTarget==='/','Password success returns to authenticated discovery');
  return checks;
}
if(typeof require==='function'&&typeof module!=='undefined'&&require.main===module){
  const fs=require('node:fs'),path=require('node:path'),sources={};
  for(const file of ['account.js','app.js'])sources[file]=fs.readFileSync(path.join(__dirname,'../public/assets',file),'utf8');
  testFrontend(sources).then(count=>console.log(count+' frontend checks passed')).catch(e=>{console.error(e);process.exitCode=1;});
}
