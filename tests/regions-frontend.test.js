'use strict';
const fs=require('node:fs');
(async()=>{
  let checks=0;const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
  class Element{constructor(){this.hidden=false;this.children=[];this.textContent='';}append(...v){this.children.push(...v);}replaceChildren(...v){this.children=v;}}
  const elements={},el=id=>elements[id]??=new Element(),events={},callbacks={},sources={},layers=[],requests=[],maps=[];
  const features=[{type:'Feature',geometry:{type:'Polygon',coordinates:[[[0,0],[1,0],[1,1],[0,1],[0,0]]]},properties:{key:'auto-test',name:'Test region',status:'complete',progress:'Ready'}}];
  class Map{
    constructor(){this.removed=false;maps.push(this);}
    addControl(){}on(event,layer,fn){callbacks[event+(typeof layer==='string'?':'+layer:'')]=typeof layer==='function'?layer:fn;}
    addSource(id,value){sources[id]={...value,setData(data){this.data=data;}};}
    addLayer(layer){layers.push(layer);}getSource(id){return sources[id];}setFilter(){}setPaintProperty(){}fitBounds(){}resize(){}remove(){this.removed=true;}
  }
  const window={location:{pathname:'/regions'},SweepLineAccount:{user:{role:'superadmin'}},maplibregl:{Map,NavigationControl:class{},LngLatBounds:class{extend(){}}},
    addEventListener:(name,fn)=>events[name]=fn,confirm:()=>true,
    sweeplineRequest:async(path,options={})=>{requests.push({path,options});if(path==='/api/admin/regions')return {type:'FeatureCollection',features};
      if(path.includes('?key='))return {name:'Test region',status:'complete',progress:'Ready',database_estimated_bytes:1024,file_bytes:512,file_count:1,size_note:'Estimate'};
      return {queued:true,message:'Action queued'};}
  };
  const document={getElementById:el,createElement:()=>new Element(),documentElement:{dataset:{theme:'light'}}};
  new Function('window','document','setTimeout','clearTimeout','setInterval','clearInterval',fs.readFileSync('public/assets/regions.js','utf8'))(window,document,()=>1,()=>{},()=>1,()=>{});
  callbacks.load();for(let i=0;i<20;i++)await Promise.resolve();
  check(layers.map(l=>l.id).join(',')==='region-fill,region-border,region-selected'&&!sources.roads,'Region mode renders only regional boxes over the basemap');
  check(sources.regions.data.features.length===1&&el('regions-list').children.length===1,'Live inventory populates map and selectable list');
  el('regions-list').children[0].onclick();for(let i=0;i<20;i++)await Promise.resolve();
  check(el('region-size').children.length===6&&el('region-title').textContent==='Test region','Selecting a region displays separate database and file size metrics');
  await el('region-refresh').onclick();
  check(JSON.parse(requests.find(r=>r.options.method==='POST').options.body).action==='refresh','Refresh explicitly posts the selected region action');
  await el('region-discard').onclick();
  check(requests.some(r=>r.options.body&&JSON.parse(r.options.body).action==='discard'),'Confirmed discard posts only the selected region');
  const before=requests.length;window.confirm=()=>false;await el('region-discard').onclick();check(requests.length===before,'Declining discard confirmation makes no request');
  events['sweepline:sessionchange']({detail:{user:null}});check(maps[0].removed,'Signing out removes the administrative map');
  events['sweepline:sessionchange']({detail:{user:{role:'admin'}}});check(maps.length===1,'Web Admin sessions cannot initialize the region map');
  console.log(checks+' region frontend checks passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
