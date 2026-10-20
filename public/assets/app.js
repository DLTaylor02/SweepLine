'use strict';
const $ = id => document.getElementById(id);
let map, ready, roads = [], selected = null, request = 0, pollTimer, activeProfile='twisty';
const empty = () => ({type:'FeatureCollection',features:[]});
const color = ['interpolate',['linear'],['get','score'],0,'#94a3b8',50,'#60a5fa',100,'#1d4ed8'];
function setStatus(message, busy=false, preparation=null) {
  $('status').textContent=message;
  $('search-feedback').dataset.busy=String(busy);
  $('search-feedback').setAttribute('aria-busy',String(busy));
  $('search-spinner').hidden=!busy;
  $('search-progress').hidden=!busy;
  $('search-progress-label').hidden=!busy;
  $('map-spinner').hidden=!busy;
  $('map-status').textContent=busy?'Preparing roads · results update automatically':'EXPLORE SOMETHING DIFFERENT';
  $('submit').textContent=busy?'Search another area ↗':'Discover roads ↗';
  const progress=$('search-progress');
  if(preparation && preparation.total>0) {
    progress.max=preparation.total;progress.value=preparation.complete;
    $('search-progress-label').textContent=`${preparation.complete} of ${preparation.total} areas ready. This measures completed areas, not remaining time.`;
  } else {
    progress.removeAttribute('value');
    $('search-progress-label').textContent='Checking local coverage and requesting available roads…';
  }
}
async function api(path, options) { return window.sweeplineRequest(path, options); }
function syncMapTheme() {
  if (!map || !map.getLayer('basemap')) return;
  const dark = document.documentElement.dataset.theme === 'dark';
  map.setPaintProperty('basemap', 'raster-brightness-max', dark ? 0.32 : 1);
  map.setPaintProperty('basemap', 'raster-saturation', dark ? -0.85 : -0.65);
  map.setPaintProperty('roads-outline', 'line-color', dark ? '#15253b' : '#ffffff');
  map.setPaintProperty('radius-line', 'line-color', dark ? '#8bbcff' : '#2563a6');
  map.setPaintProperty('radius-fill', 'fill-color', dark ? '#8bbcff' : '#2563a6');
}
window.addEventListener('sweepline:themechange', syncMapTheme);
function initMap() {
  if (!window.maplibregl) { setStatus('Map library unavailable. Check your connection; road search remains available.'); return; }
  map = new maplibregl.Map({container:'map', bounds:[[-180,-70],[180,80]],fitBoundsOptions:{padding:20},style:{version:8,sources:{osm:{type:'raster',tiles:['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],tileSize:256,attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}},layers:[{id:'basemap',type:'raster',source:'osm',paint:{'raster-saturation':-0.65,'raster-opacity':0.88}}]}});
  map.addControl(new maplibregl.NavigationControl(),'top-right');
  ready = new Promise(resolve => map.on('load', () => {
    map.addSource('radius',{type:'geojson',data:empty()});
    map.addLayer({id:'radius-fill',type:'fill',source:'radius',paint:{'fill-color':'#2563a6','fill-opacity':0.035}});
    map.addLayer({id:'radius-line',type:'line',source:'radius',paint:{'line-color':'#2563a6','line-width':1.5,'line-dasharray':[3,3]}});
    map.addSource('roads',{type:'geojson',data:empty()});
    map.addLayer({id:'roads-outline',type:'line',source:'roads',paint:{'line-color':'#fff','line-width':7}});
    map.addLayer({id:'roads',type:'line',source:'roads',paint:{'line-color':color,'line-width':4}});
    map.addLayer({id:'selected',type:'line',source:'roads',filter:['==',['get','id'],-1],paint:{'line-color':'#f08a38','line-width':6}});
    map.on('click','roads', e => selectRoad(Number(e.features[0].properties.id)));
    map.on('mouseenter','roads',()=>map.getCanvas().style.cursor='pointer');
    map.on('mouseleave','roads',()=>map.getCanvas().style.cursor='');
    syncMapTheme();
    resolve();
  }));
}
function circle(lon,lat,miles) {
  const out=[], d=miles*1609.344/6371008.8, p=lat*Math.PI/180, l=lon*Math.PI/180;
  for(let i=0;i<=96;i++) {
    const b=i/96*2*Math.PI, q=Math.asin(Math.sin(p)*Math.cos(d)+Math.cos(p)*Math.sin(d)*Math.cos(b));
    const r=l+Math.atan2(Math.sin(b)*Math.sin(d)*Math.cos(p),Math.cos(d)-Math.sin(p)*Math.sin(q));
    out.push([r*180/Math.PI,q*180/Math.PI]);
  }
  return {type:'Feature',properties:{},geometry:{type:'Polygon',coordinates:[out]}};
}
function bounds(coords) { const b=new maplibregl.LngLatBounds(); coords.forEach(p=>b.extend(p)); return b; }
function text(tag,value,className) { const e=document.createElement(tag); e.textContent=value; if(className)e.className=className; return e; }
function render() {
  $('results').replaceChildren(); $('count').textContent=roads.length;
  roads.forEach((road,index)=>{
    const b=document.createElement('button'); b.className='road'; b.dataset.id=road.id;
    const top=text('div','', 'road-top');
    top.append(text('span',`${String(index+1).padStart(2,'0')}  ${road.name}`,'road-title'),text('span',road.score.toFixed(0),'score'));
    b.append(top,text('div',`${(road.length_m/1609.344).toFixed(1)} mi · ${road.metrics.curve_density.toFixed(1)} curve samples/mi · ${road.mandatory_stops} mapped stops + ${road.uncertain_controls} uncertain`,'road-meta'),text('div',`${road.speed_mph === null ? 'Speed unknown' : road.speed_mph.toFixed(0)+' mph minimum posted'} · ${Math.round(road.confidence*100)}% attribute completeness`,'road-meta'),text('div',(activeProfile==='twisty'?'Twisty Roads':'Open Highways')+'  ↗','road-tag'));
    b.onclick=()=>selectRoad(road.id); $('results').append(b);
  });
}
async function selectRoad(id) {
  selected=id;
  document.querySelectorAll('.road').forEach(e=>e.classList.toggle('selected',Number(e.dataset.id)===id));
  const road=roads.find(r=>r.id===id); if (!road) return;
  if(map && map.getLayer('selected')) { map.setFilter('selected',['==',['get','id'],id]); map.fitBounds(bounds(road.geometry.coordinates),{padding:65,maxZoom:13}); }
  const detail=$('road-detail'); detail.replaceChildren(); detail.hidden=false;
  const close=text('button','Close');close.onclick=()=>{detail.hidden=true;};
  detail.append(close,text('h2',road.name),text('p','Loading score breakdown…'));
  try {
    const data=await api(`/api/roads/${id}?profile=${encodeURIComponent(activeProfile)}`);
    if(selected!==id) return;
    detail.replaceChildren(close,text('h2',`${data.name} · ${data.score}/100`));
    Object.entries(data.dimensions).forEach(([key,value])=>{const row=text('div','','dimension');row.append(text('span',key),text('strong',`${Math.round(value*100)}/100`));detail.append(row);});
    detail.append(text('p','Elevation unavailable. Score dimensions are weighted for the selected profile. Completeness measures mapped attributes, not certainty that every control is present.'));
  } catch(e) { if(selected===id) detail.append(text('p',e.message)); }
}

async function discover(payload, sequence) {
  try {
    const params={...payload};delete params._mapShown;delete params._recorded;params.record_history=!payload._recorded;
    const data=await api('/api/search',{method:'POST',body:JSON.stringify(params)});
    if(sequence!==request)return;
    payload._recorded=true;
    roads=data.results || [];activeProfile=payload.profile;render();
    const preparation=data.preparation;
    if(preparation && preparation.state!=='ready') {
      setStatus(`${preparation.message} · ${preparation.complete}/${preparation.total} areas ready.`+
        (preparation.state==='failed'?' A SuperAdmin can retry failed tasks in Manage.':` ${roads.length?'Showing partial results.':'First-time preparation can take several minutes.'} This search will update automatically.`), preparation.state!=='failed', preparation);
      if(preparation.state!=='failed')pollTimer=setTimeout(()=>discover(payload,sequence),5000);
    } else {
      setStatus(roads.length?`${roads.length}${data.truncated?'+':''} roads found near ${data.center.place}.`:'No roads match these filters. Try adjusting the length, stop, or surface filters.');
    }
    if(map && data.center) {
      await ready;if(sequence!==request)return;
      map.getSource('roads').setData({type:'FeatureCollection',features:roads.map(r=>({type:'Feature',geometry:r.geometry,properties:{id:r.id,score:r.score}}))});
      map.setFilter('selected',['==',['get','id'],-1]);
      const radius=circle(Number(data.center.lon),Number(data.center.lat),payload.radius_miles);map.getSource('radius').setData(radius);
      if(!payload._mapShown) { map.fitBounds(bounds(radius.geometry.coordinates[0]),{padding:35}); payload._mapShown=true; }
    }
  } catch(e) {
    if(sequence!==request)return;
    setStatus(e.status===503?`${e.message} Retrying automatically…`:e.message,e.status===503);
    if(e.status===503)pollTimer=setTimeout(()=>discover(payload,sequence),10000);
  } finally { if(sequence===request)$('submit').disabled=false; }
}
$('search').onsubmit = event => {
  event.preventDefault();clearTimeout(pollTimer);const sequence=++request,form=event.target;
  $('submit').disabled=true;setStatus('Preparing your road search…',true);$('road-detail').hidden=true;selected=null;
  discover({zip:form.zip.value,radius_miles:Number(form.radius.value),profile:form.profile.value,
    ...(form.min_length.value.trim()===''?{}:{min_length_miles:Number(form.min_length.value)}),
    ...(form.max_length.value.trim()===''?{}:{max_length_miles:Number(form.max_length.value)}),
    max_stops:Number(form.max_stops.value),paved_only:form.paved.checked},sequence);
};
async function handleSession(event) {
  if(window.location?.pathname && window.location.pathname!=='/')return;
  if(!event.detail.user) {
    ++request;clearTimeout(pollTimer);roads=[];render();$('road-detail').hidden=true;$('submit').disabled=false;setStatus('Choose a ZIP code to explore roads nearby.');
    if(map && map.getSource('roads')) { map.getSource('roads').setData(empty());map.getSource('radius').setData(empty()); }
    return;
  }
  if(!map)initMap();
  if(map) { await ready;map.resize(); }
  api('/api/coverage').then(regions=>{
    const active=regions.filter(r=>r.active_job_id);
    $('coverage').textContent=active.length?`${active.length} AREAS CACHED`:'ROAD DATA PREPARES AUTOMATICALLY';
  }).catch(()=>{$('coverage').textContent='COVERAGE STATUS UNAVAILABLE';});
}
window.addEventListener('sweepline:sessionchange',handleSession);
if(window.SweepLineAccount?.user)handleSession({detail:window.SweepLineAccount});

if(window.location?.pathname==='/') {
  const params=new URLSearchParams(window.location.search);
  if(params.has('zip')) {
    const form=$('search');
    for(const key of ['zip','profile'])if(params.has(key))form[key].value=params.get(key);
    for(const [key,field] of [['radius_miles','radius'],['min_length_miles','min_length'],['max_length_miles','max_length'],['max_stops','max_stops']])
      if(params.has(key))form[field].value=params.get(key);
    if(params.has('paved_only'))form.paved.checked=params.get('paved_only')==='true';
    setStatus('Previous search loaded. Select Discover roads to run it again.');
  }
}
