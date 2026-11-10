'use strict';
const alternateLabel=road=>(road.alternate_names||[]).length?'Also known as: '+road.alternate_names.join('; '):'';
const memberQuery=road=>(road.member_ids||[]).length>1?'&members='+encodeURIComponent(road.member_ids.join(',')):'';
const normalizedDensity=m=>Math.max(1,Math.min(10,m.curve_density_score??(1+9*m.curve_density/53.6448))).toFixed(1);

const $ = id => document.getElementById(id);
let map, ready, roads = [], selected = null, request = 0, pollTimer, activeProfile='twisty';
let pendingHistorySearch=false,pendingLegacyHistory=false, activeSearchUser=null, restoredSearchUser=null, explicitSearchParameters=false;
let currentSearchParameters=null,pendingFavorite=null,pendingHistoryId=null;

const discoveryTabs=['zip','custom','discoveries'];
function showDiscoveryTab(name,focus=false){
  if(!discoveryTabs.includes(name))return;
  for(const key of discoveryTabs){
    const active=key===name,tab=$('tab-'+key);
    tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;
    $('panel-'+key).hidden=!active;
  }
  if(focus)$('tab-'+name).focus?.();
}
for(const [index,name] of discoveryTabs.entries()){
  const tab=$('tab-'+name);
  tab.onclick=()=>showDiscoveryTab(name);
  tab.onkeydown=event=>{
    let next;
    if(event.key==='ArrowRight')next=(index+1)%3;
    if(event.key==='ArrowLeft')next=(index+2)%3;
    if(event.key==='Home')next=0;
    if(event.key==='End')next=2;
    if(next!==undefined){event.preventDefault();showDiscoveryTab(discoveryTabs[next],true);}
  };
}
showDiscoveryTab('zip');

const searchStorageKey=id=>'sweepline:active-search:'+id;
function rememberSearch(payload) {
  if(!activeSearchUser)return;
  try {
    const parameters={...payload};delete parameters._recorded;delete parameters._mapShown;delete parameters._historyId;
    window.sessionStorage?.setItem(searchStorageKey(activeSearchUser),JSON.stringify(parameters));
  } catch (_) { /* Search remains usable when browser storage is unavailable. */ }
}
function fillSearch(p) {
  const form=$('search');
  form.zip.value=p.zip;form.profile.value=p.profile;form.radius.value=String(p.radius_miles);
  form.min_length.value=p.min_length_miles==null?'':String(p.min_length_miles);
  form.max_length.value=p.max_length_miles==null?'':String(p.max_length_miles);
  form.min_curve_density_score.value=String(p.min_curve_density_score??(p.min_curve_density==null?2.2:1+9*p.min_curve_density/53.6448));
  form.min_curvature_quality_score.value=String(p.min_curvature_quality_score??(1+9*(p.min_curvature_quality??0.12)));form.min_sustained_curve_ft.value=String(p.min_sustained_curve_ft??(p.min_sustained_curve_m==null?150:p.min_sustained_curve_m/0.3048));
  form.surface_filter.value=p.surface_filter??(p.paved_only===true?'known_paved':p.paved_only===false?'any':'exclude_unpaved');
  form.max_stops.value=String(Math.max(1,p.max_stops));
}
function restoreSearch(userId) {
  try {
    const raw=window.sessionStorage?.getItem(searchStorageKey(userId));
    if(!raw)return false;
    const p=JSON.parse(raw);
    if(!p || !/^\d{5}$/.test(p.zip) || !Number.isInteger(p.radius_miles) || p.radius_miles<1 || p.radius_miles>50 ||
      !['twisty','open'].includes(p.profile) || !Number.isInteger(p.max_stops) ||
      p.max_stops<0 || p.max_stops>100)return false;
    fillSearch(p);
    if(Number.isSafeInteger(p._searchJob)&&p._searchJob>0){
      const sequence=++request;setStatus('Reconnecting to your search...',true);
      discover({...p,_recorded:true},sequence);return false;
    }
    return true;
  } catch (_) {return false;}
}
let pickingZip=false,zipPickRequest=0;
function setZipPickMode(enabled) {
  pickingZip=enabled;
  $('pick-zip').textContent=enabled?'Cancel pick':'Pick on map';
  $('pick-zip').setAttribute('aria-pressed',String(enabled));
  $('map-pick-note').hidden=!enabled;
  if(map)map.getCanvas().style.cursor=enabled?'crosshair':'';
}
$('pick-zip').onclick=()=>{
  ++zipPickRequest;
  if(pickingZip){setZipPickMode(false);$('zip-pick-status').textContent='Map selection cancelled.';return;}
  if(!map){$('zip-pick-status').textContent='The map is unavailable. Enter a ZIP code manually.';return;}
  cancelCustomRoad();setZipPickMode(true);
  $('zip-pick-status').textContent='Click a spot on the map to find its nearest ZIP center.';
  $('map').scrollIntoView({behavior:'smooth',block:'center'});
};
async function pickZipAt(event) {
  if(!pickingZip)return;
  const sequence=++zipPickRequest;
  setZipPickMode(false);$('pick-zip').disabled=true;
  $('zip-pick-status').textContent='Looking up the nearest ZIP center…';
  try {
    const data=await api('/api/zips/nearest?lon='+encodeURIComponent(event.lngLat.lng)+'&lat='+encodeURIComponent(event.lngLat.lat));
    if(sequence!==zipPickRequest)return;
    if(!data.zip){$('zip-pick-status').textContent=data.message;return;}
    $('search').zip.value=data.zip;
    $('zip-pick-status').textContent=`Nearest ZIP: ${data.zip} · ${data.place}, ${data.state} · ${(Number(data.distance_m)/1609.344).toFixed(1)} miles from click. Search centers on this ZIP, not the clicked point.`;
  } catch(e) {if(sequence===zipPickRequest)$('zip-pick-status').textContent=e.message;}
  finally {if(sequence===zipPickRequest)$('pick-zip').disabled=false;}
}
const empty = () => ({type:'FeatureCollection',features:[]});
const color = ['interpolate',['linear'],['get','score'],0,'#94a3b8',50,'#60a5fa',100,'#1d4ed8'];

let preparationAreas={type:'FeatureCollection',features:[]},areaPulseTimer=null,areaPulsePhase=0;
function renderPreparationAreas(){
  const source=map?.getSource?.('preparation-areas');if(!source)return;
  source.setData(preparationAreas);
  clearTimeout(areaPulseTimer);areaPulseTimer=null;
  const pulse=()=>{
    if(document.visibilityState==='hidden'||!map?.getLayer?.('preparation-fill')||!preparationAreas.features.some(f=>f.properties.status==='running'))return;
    areaPulsePhase=(areaPulsePhase+1)%12;
    const amount=(1+Math.sin(areaPulsePhase*Math.PI/6))/2;
    map.setPaintProperty('preparation-fill','fill-opacity',['case',['==',['get','status'],'running'],0.10+amount*0.16,0.07]);
    map.setPaintProperty('preparation-border','line-opacity',['case',['==',['get','status'],'running'],0.6+amount*0.4,0.7]);
    areaPulseTimer=setTimeout(pulse,150);
  };
  pulse();
}
function updatePreparationAreas(preparation){
  preparationAreas=preparation&&preparation.state!=='ready'&&preparation.areas?.type==='FeatureCollection'?preparation.areas:{type:'FeatureCollection',features:[]};
  $('preparation-legend').hidden=preparationAreas.features.length===0;
  clearTimeout(areaPulseTimer);areaPulseTimer=null;
  renderPreparationAreas();
}

const searchStages=['Coverage','ZIP location','Download areas','Analyze roads','Results'];
function paintSearchStages(busy,preparation){
  const complete=preparation?.state==='ready',panel=$('search-stages');
  panel.hidden=!busy&&!complete;
  if(panel.hidden)return;
  const message=preparation?.message||'';
  let stage=0;
  if(complete)stage=searchStages.length;
  else if(/final coverage|saving search results|results ready/i.test(message))stage=4;
  else if(/ZIP|postal/i.test(message))stage=1;
  else if(/analyz|connect|scor|normaliz|loaded.*nodes|overlapping|candidate|combining/i.test(message))stage=3;
  else if(preparation)stage=2;
  const label=complete?'Search ready · cached or prepared data':`Step ${stage+1} of ${searchStages.length} · ${searchStages[stage]}`;
  $('search-stage-label').textContent=label;
  const track=$('search-stage-track');track.replaceChildren();
  track.setAttribute('role','img');track.setAttribute('aria-label',label+'. Preparation stages can repeat across areas; this is not a percentage or time estimate.');
  for(const [index,name] of searchStages.entries()){
    const segment=text('span','');segment.title=name;segment.setAttribute('aria-hidden','true');
    segment.className=complete||index<stage?'is-complete':index===stage?'is-current':'';
    track.append(segment);
  }
}

function setStatus(message, busy=false, preparation=null) {
  $('search-error').hidden=true;
  paintSearchStages(busy,preparation);
  if(preparation?.state==='ready')showDiscoveryTab('discoveries');
  if(preparation||!busy)updatePreparationAreas(preparation);
  $('status').textContent=message;
  $('search-feedback').hidden=!message&&!busy;
  $('search-feedback').dataset.busy=String(busy);
  $('search-feedback').setAttribute('aria-busy',String(busy));
  $('search-spinner').hidden=!busy;
  $('search-progress').hidden=!busy;
  $('search-progress-label').hidden=!busy&&preparation?.state!=='ready';
  $('map-spinner').hidden=!busy;
  $('map-status').textContent=busy?'Preparing roads · results update automatically':'EXPLORE SOMETHING DIFFERENT';
  $('submit').textContent=busy?'Search another area ↗':'Discover roads ↗';
  const progress=$('search-progress');
  if(preparation && preparation.total>0) {
    progress.max=preparation.total;progress.value=preparation.complete;
    $('search-progress-label').textContent=`${preparation.complete} of ${preparation.total} areas ready. This measures completed areas, not remaining time.`;
  } else {
    progress.removeAttribute('value');
    $('search-progress-label').textContent=preparation?.state==='ready'?'Local data ready.':'Checking local coverage and requesting available roads…';
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
let mapRefreshPending=false,pdfPreviewPending=false,pdfPreviewAway=false;
function markPdfPreview() {pdfPreviewPending=true;pdfPreviewAway=false;}
window.addEventListener('blur',()=>{if(pdfPreviewPending)pdfPreviewAway=true;});
function rebuildMapAfterPdf() {
  if(!pdfPreviewPending || !pdfPreviewAway || !map || !map.getLayer('selected'))return false;
  const oldMap=map;
  const snapshot={style:oldMap.getStyle(),center:oldMap.getCenter(),zoom:oldMap.getZoom(),
    bearing:oldMap.getBearing(),pitch:oldMap.getPitch(),padding:oldMap.getPadding()};
  pdfPreviewPending=false;pdfPreviewAway=false;
  clearRoadEndpoints(false);oldMap.remove();map=null;
  initMap(snapshot);
  ready.then(refreshVisibleMap);
  return true;
}
function refreshVisibleMap() {
  if(document.visibilityState==='hidden') {if(pdfPreviewPending)pdfPreviewAway=true;return;}
  if(!map || $('workspace').hidden || mapRefreshPending)return;
  const currentMap=map;mapRefreshPending=true;
  const nextFrame=window.requestAnimationFrame?.bind(window) || (callback=>setTimeout(callback,0));
  nextFrame(()=>{
    mapRefreshPending=false;
    if(map!==currentMap || document.visibilityState==='hidden' || $('workspace').hidden)return;
    // Let the browser restore layout first, then ask MapLibre for a fresh frame.
    // Existing sources, selection and camera stay intact; no search/import request.
    if(rebuildMapAfterPdf())return;
    currentMap.resize();currentMap.triggerRepaint();renderPreparationAreas();
  });
}
window.addEventListener('focus',refreshVisibleMap);
document.addEventListener?.('visibilitychange',refreshVisibleMap);
function initMap(snapshot=null) {
  if (!window.maplibregl) { setStatus('Map library unavailable. Check your connection; road search remains available.'); return; }
  const view=snapshot?{center:snapshot.center,zoom:snapshot.zoom,bearing:snapshot.bearing,pitch:snapshot.pitch}:{bounds:[[-180,-70],[180,80]],fitBoundsOptions:{padding:20}};
  map = new maplibregl.Map({container:'map',...view,style:snapshot?snapshot.style:{version:8,sources:{osm:{type:'raster',tiles:['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],tileSize:256,attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}},layers:[{id:'basemap',type:'raster',source:'osm',paint:{'raster-saturation':-0.65,'raster-opacity':0.88}}]}});
  if(snapshot)map.setPadding(snapshot.padding);
  map.addControl(new maplibregl.NavigationControl(),'top-right');
  map.on('webglcontextrestored',refreshVisibleMap);
  ready = new Promise(resolve => map.on('load', () => {
    if(!snapshot) {
      map.addSource('radius',{type:'geojson',data:empty()});
      map.addLayer({id:'radius-fill',type:'fill',source:'radius',paint:{'fill-color':'#2563a6','fill-opacity':0.035}});
      map.addLayer({id:'radius-line',type:'line',source:'radius',paint:{'line-color':'#2563a6','line-width':1.5,'line-dasharray':[3,3]}});
      map.addSource('preparation-areas',{type:'geojson',data:preparationAreas});
      const areaColor=['match',['get','status'],'complete','#22c55e','running','#facc15','#ef4444'];
      map.addLayer({id:'preparation-fill',type:'fill',source:'preparation-areas',paint:{'fill-color':areaColor,'fill-opacity':0.07}});
      map.addLayer({id:'preparation-border',type:'line',source:'preparation-areas',paint:{'line-color':areaColor,'line-width':2,'line-opacity':0.7}});
      map.addSource('roads',{type:'geojson',data:empty()});
      map.addLayer({id:'roads-outline',type:'line',source:'roads',paint:{'line-color':'#fff','line-width':7}});
      map.addLayer({id:'roads',type:'line',source:'roads',paint:{'line-color':color,'line-width':4}});
      map.addLayer({id:'selected',type:'line',source:'roads',filter:['==',['get','id'],-1],paint:{'line-color':'#f08a38','line-width':6}});
      }
    if(roadEndpointSelection)drawRoadEndpoints(roadEndpointSelection.road,roadEndpointSelection.orientation);
    map.on('click','roads', e => {if(!pickingZip&&!customPicking)selectRoad(Number(e.features[0].properties.id));});
    map.on('mouseenter','roads',()=>map.getCanvas().style.cursor=(pickingZip||customPicking)?'crosshair':'pointer');
    map.on('mouseleave','roads',()=>map.getCanvas().style.cursor=(pickingZip||customPicking)?'crosshair':'');
    map.on('click',pickZipAt);map.on('click',pickCustomRoadAt);
    syncMapTheme();renderPreparationAreas();
    renderPreparationAreas();resolve();
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
    b.append(top,text('div',`${(road.length_m/1609.344).toFixed(1)} mi · ${normalizedDensity(road.metrics)}/10 curve density · ${road.mandatory_stops} mapped stops + ${road.uncertain_controls} uncertain`,'road-meta'),text('div',`${road.speed_mph === null ? 'Speed unknown' : road.speed_mph.toFixed(0)+' mph minimum posted'} · ${Math.round(road.confidence*100)}% attribute completeness`,'road-meta'),text('div',(activeProfile==='twisty'?'Twisty Roads':'Open Highways')+'  ↗','road-tag'));
    if(alternateLabel(road))b.append(text('div',alternateLabel(road),'road-meta'));
    b.onclick=()=>selectRoad(road.id); $('results').append(b);
  });
}

let customPicking=false,customPoints=[],customMarkers=[],customSequence=0,customTimer;
function cancelCustomRoad(){
  $('custom-road-error').hidden=true;
  customPicking=false;customPoints=[];++customSequence;clearTimeout(customTimer);
  customMarkers.forEach(marker=>marker.remove());customMarkers=[];
  $('pick-custom-road').textContent='Choose two points';$('pick-custom-road').setAttribute('aria-pressed','false');
  if(map)map.getCanvas().style.cursor='';
}
$('pick-custom-road').onclick=()=>{
  if(customPicking){cancelCustomRoad();setStatus('Custom selection cancelled.');$('custom-road-status').textContent='Custom selection cancelled.';return;}
  if(!map){$('custom-road-status').textContent='The map is unavailable. Try reloading Discovery.';return;}
  cancelCustomRoad();showDiscoveryTab('custom');updatePreparationAreas(null);setZipPickMode(false);customPicking=true;
  ++request;clearTimeout(pollTimer);$('submit').disabled=false;setStatus('Choose two points on one continuous road.');
  $('road-detail').hidden=true;clearRoadEndpoints();
  $('pick-custom-road').textContent='Cancel selection';$('pick-custom-road').setAttribute('aria-pressed','true');
  $('map').scrollIntoView({behavior:'smooth',block:'center'});
  map.getCanvas().style.cursor='crosshair';$('custom-road-status').textContent='Click the start point on a road.';
};
async function pickCustomRoadAt(event){
  if(!customPicking||customPoints.length>=2)return;
  customPoints.push({lon:event.lngLat.lng,lat:event.lngLat.lat});
  if(window.maplibregl?.Marker){
    const pin=text('div',customPoints.length===1?'Start?':'Finish?','road-endpoint road-endpoint-'+(customPoints.length===1?'start':'finish'));
    customMarkers.push(new window.maplibregl.Marker({element:pin,anchor:'bottom-left'}).setLngLat([event.lngLat.lng,event.lngLat.lat]).addTo(map));
  }
  if(customPoints.length===1){$('custom-road-status').textContent='Now click the finish point on the same road.';return;}
  const sequence=customSequence,payload={start:customPoints[0],finish:customPoints[1],profile:$('search').profile.value};
  setStatus('Preparing your custom road…',true);scrollToSearchProgress();
  await resolveCustomRoad(payload,sequence);
}
async function resolveCustomRoad(payload,sequence){
  $('custom-road-status').textContent='Checking road connectivity and preparing local data…';
  try{
    const result=await api('/api/custom-roads',{method:'POST',body:JSON.stringify(payload)});
    if(sequence!==customSequence)return;
    if(!result.road){
      const prep=result.preparation;
      setStatus(prep.message+' · '+prep.complete+'/'+prep.total+' areas ready. Custom selection will update automatically.',prep.state==='preparing',prep);
      $('custom-road-status').textContent=prep.message+' · '+prep.complete+'/'+prep.total+' areas ready.';
      if(prep.state==='preparing'){customTimer=setTimeout(()=>resolveCustomRoad(payload,sequence),5000);return;}
      throw new Error(prep.message+' A SuperAdmin can review preparation in Manage.');
    }
    cancelCustomRoad();currentSearchParameters=result.parameters;activeProfile=payload.profile;
    try{window.sessionStorage?.setItem(roadDirectionKey(result.road),result.road.preferred_orientation||'forward');}catch(_){}
    roads=[result.road];render();
    const completedSequence=customSequence;if(map)await ready;
    if(completedSequence!==customSequence)return;
    map?.getSource('roads')?.setData({type:'FeatureCollection',features:[{type:'Feature',geometry:result.road.geometry,properties:{id:result.road.id,score:result.road.score}}]});
    map?.getSource('radius')?.setData(empty());
    await selectRoad(result.road.id);$('custom-road-status').textContent='Custom stretch selected. Scores, favorites and exports use only this stretch.';
    setStatus('Custom road selection · '+(result.road.length_m/1609.344).toFixed(2)+' miles.',false,result.preparation||{state:'ready'});
  }catch(e){if(sequence===customSequence){cancelCustomRoad();updatePreparationAreas(null);
      const message='Custom road could not be selected. '+(e.message||'Please try again.');
      $('custom-road-status').textContent=message;
      $('custom-road-error').textContent=message+' Choose two points again to retry.';
      $('custom-road-error').hidden=false;
      setStatus(message);}}
}

let roadEndpointMarkers=[],roadEndpointSelection=null;
function clearRoadEndpoints(clear=true){for(const marker of roadEndpointMarkers)marker.remove();roadEndpointMarkers=[];if(clear)roadEndpointSelection=null;}
function roadDirectionKey(road){return 'sweepline:orientation:'+(window.SweepLineAccount?.user?.id||activeSearchUser)+':'+(road.geometry_key||road.id);}
function savedRoadDirection(road){try{return (window.sessionStorage?.getItem(roadDirectionKey(road))||road.preferred_orientation)==='reverse'?'reverse':'forward';}catch(_){return 'forward';}}
function drawRoadEndpoints(road,orientation){
  roadEndpointSelection={road,orientation};clearRoadEndpoints(false);
  if(!map||!window.maplibregl?.Marker)return;
  const points=road.geometry.coordinates;if(!points.length)return;
  const reversed=(Number(road.travel_direction)<0)!==(orientation==='reverse');
  const first=points[reversed?points.length-1:0],last=points[reversed?0:points.length-1];
  for(const [label,coordinate,kind] of [['Start',first,'start'],['Finish',last,'finish']]){
    const pin=text('div',label,'road-endpoint road-endpoint-'+kind);pin.setAttribute('aria-label',label+' of selected road');
    roadEndpointMarkers.push(new window.maplibregl.Marker({element:pin,anchor:'bottom-left'}).setLngLat(coordinate).addTo(map));
  }
}

function roadViewportPadding(width,height,panelWidth,panelHeight,bottom){
  return bottom
    ? {top:45,bottom:Math.min(Math.ceil(panelHeight)+36,Math.max(45,height-100)),left:30,right:30}
    : {top:65,bottom:65,left:40,right:Math.min(Math.ceil(panelWidth)+40,Math.max(40,width-120))};
}
function fitSelectedRoad(){
  const detail=$('road-detail'),container=$('map');
  if(!map||detail.hidden||!roadEndpointSelection||!container.getBoundingClientRect)return;
  const area=container.getBoundingClientRect();if(!area.width||!area.height)return;
  const bottom=area.width<700;detail.classList.toggle('road-detail-bottom',bottom);
  const panel=detail.getBoundingClientRect();
  map.fitBounds(bounds(roadEndpointSelection.road.geometry.coordinates),{padding:roadViewportPadding(area.width,area.height,panel.width,panel.height,bottom),maxZoom:13,duration:350,retainPadding:false});
}
let roadFitTimer;
function scheduleRoadFit(){clearTimeout(roadFitTimer);roadFitTimer=setTimeout(fitSelectedRoad,80);}
if(window.ResizeObserver&&$('road-detail')&&$('map')){
  const observer=new window.ResizeObserver(scheduleRoadFit);
  observer.observe($('road-detail'));observer.observe($('map'));
}
window.addEventListener('resize',scheduleRoadFit);

async function selectRoad(id) {
  window.sweeplineBeamStop?.();
  selected=id;clearRoadEndpoints();
  document.querySelectorAll('.road').forEach(e=>e.classList.toggle('selected',Number(e.dataset.id)===id));
  const road=roads.find(r=>r.id===id); if (!road) return;
  if(map && map.getLayer('selected')) { map.setFilter('selected',['==',['get','id'],id]); scheduleRoadFit(); }
  drawRoadEndpoints(road,savedRoadDirection(road));
  const detail=$('road-detail'); detail.replaceChildren(); detail.hidden=false;
  const close=text('button','Close','road-detail-close');close.onclick=()=>{detail.hidden=true;clearRoadEndpoints();window.sweeplineBeamStop?.();};
  detail.append(close,text('h2',road.name),text('p','Loading score breakdown…'));
  try {
    const data=(road._snapshot||road._historySnapshot||road.custom_id)?road:await api(`/api/roads/${id}?profile=${encodeURIComponent(activeProfile)}${memberQuery(road)}`);
    if(selected!==id) return;
    detail.replaceChildren(close,text('h2',`${data.name} · ${data.score}/100`));

    const nameChoices=[...new Set([data.name,...(data.alternate_names||[])])];
    const nameKey=roadDirectionKey(data)+':name';
    if(nameChoices.includes(data.preferred_name))data.name=data.preferred_name;
    try{const preferred=window.sessionStorage?.getItem(nameKey);if(nameChoices.includes(preferred))data.name=preferred;}catch(_){}
    const namePicker=text('div','','road-name-picker');namePicker.setAttribute('role','group');namePicker.setAttribute('aria-label','Choose primary road name');
    detail.append(namePicker);
    const heart=text('button',data.favorite_id?'♥ Favorited':'♡ Add to favorites');heart.type='button';heart.className='favorite-heart';
    heart.setAttribute('aria-pressed',String(!!data.favorite_id));
    const favoriteStatus=text('p','');favoriteStatus.setAttribute('role','status');
    heart.onclick=async()=>{
      heart.disabled=true;
      try {
        if(data.favorite_id) {
          await api('/api/favorites/'+data.favorite_id,{method:'DELETE'});data.favorite_id=null;
          favoriteStatus.textContent='Removed from favorites.';
        } else {
          const saved=await api('/api/favorites',{method:'POST',body:JSON.stringify({road_id:data.id,profile:activeProfile,search:currentSearchParameters,...(data.custom_id?{custom_id:data.custom_id}:{}),preferred_name:data.name,orientation,...((data.member_ids||[]).length>1?{member_ids:data.member_ids}:{})})});
          data.favorite_id=saved.id;favoriteStatus.textContent='Road saved to favorites.';
        }
        heart.textContent=data.favorite_id?'♥ Favorited':'♡ Add to favorites';heart.setAttribute('aria-pressed',String(!!data.favorite_id));
      } catch(e){favoriteStatus.textContent=e.message;}
      finally {heart.disabled=false;}
    };
    favoriteStatus.className='road-feedback';
    let orientation=savedRoadDirection(data);

    let elevations=null,elevationLoading=true;
    const overview=text('div','','road-overview');overview.setAttribute('role','status');overview.setAttribute('aria-live','polite');
    const paintOverview=()=>{
      overview.replaceChildren();
      const reversed=(Number(data.travel_direction??road.travel_direction)<0)!==(orientation==='reverse');
      const values=elevations?(reversed?[elevations[1],elevations[0]]:elevations):[null,null];
      const feet=value=>typeof value==='number'&&Number.isFinite(value)?Math.round(value/0.3048).toLocaleString()+' ft':elevationLoading?'Loading…':'Unavailable';
      const length=Number(data.length_m??road.length_m);
      for(const [label,value] of [['Length',Number.isFinite(length)?(length/1609.344).toFixed(2)+' mi':'Unavailable'],['Starting elevation',feet(values[0])],['Ending elevation',feet(values[1])]]){
        const item=text('div','','road-overview-item');item.append(text('span',label),text('strong',value));overview.append(item);
      }
    };
    paintOverview();detail.append(overview);

    const direction=text('div','','road-direction');
    const swap=text('button','Swap ends');swap.type='button';swap.className='road-direction-swap';
    const directionStatus=text('p','');directionStatus.setAttribute('role','status');
    swap.title='Swap Start and Finish for favorites and exports';direction.append(heart,swap);
    const exports=text('div','','road-exports');
    const paintExports=()=>{
      drawRoadEndpoints(data,orientation);paintOverview();exports.replaceChildren();
      swap.setAttribute('aria-pressed',String(orientation==='reverse'));
      directionStatus.textContent=orientation==='reverse'&&(Number(data.travel_direction)||0)!==0?'Selected orientation opposes mapped one-way travel. Verify signs and access before driving.':'Start and Finish pins show the direction used by all exports.';
      directionStatus.hidden=!(orientation==='reverse'&&(Number(data.travel_direction)||0)!==0);
      for(const [format,label] of [['gpx','GPX'],['kmz','KMZ'],['pdf','PDF']]){
        const link=text('a',label);const query='&orientation='+orientation+'&preferred_name='+encodeURIComponent(data.name);
        link.href=data.custom_id&&!road._snapshot?`/api/custom-roads/${data.custom_id}/export?format=${format}${query}`:road._snapshot?`/api/favorites/${data.favorite_id}/export?format=${format}${query}`:`/api/roads/${id}/export?format=${format}&profile=${encodeURIComponent(activeProfile)}${memberQuery(data)}${query}`;
        if(format==='pdf'){link.target='_blank';link.rel='noopener';link.setAttribute('aria-label','Preview PDF in a new tab');link.onclick=markPdfPreview;}else link.setAttribute('download','');
        exports.append(link);
      }
      window.sweeplineBeamExport?.(exports,data,{profile:activeProfile,favoriteId:road._snapshot?data.favorite_id:null,customId:road._snapshot?null:data.custom_id,orientation,preferredName:data.name});
    };
    let preferenceSave=Promise.resolve();
    const savePreferences=()=>{
      if(!data.favorite_id)return;
      const favoriteId=data.favorite_id,body=JSON.stringify({preferred_name:data.name,orientation});
      preferenceSave=preferenceSave.then(()=>api('/api/favorites/'+favoriteId+'/preferences',{method:'POST',body}))
        .then(()=>{favoriteStatus.textContent='Favorite preferences updated.';})
        .catch(e=>{favoriteStatus.textContent='Could not save Favorite preferences: '+e.message;});
    };
    const paintNames=()=>{
      data.alternate_names=nameChoices.filter(name=>name!==data.name);namePicker.replaceChildren();
      const title=detail.children[1];title.replaceChildren();const primary=text('button',data.name,'primary-road-name');primary.type='button';primary.setAttribute('aria-pressed','true');primary.onclick=()=>chooseName(data.name);title.append(primary,text('span',` · ${data.score}/100`));
      const alias=Array.from(detail.children).find(n=>n.className==='road-aliases');if(alias)alias.textContent=alternateLabel(data);
      for(const name of nameChoices.filter(name=>name!==data.name)){const choice=text('button',name,'road-name-choice');choice.type='button';choice.setAttribute('aria-pressed',String(name===data.name));choice.onclick=()=>chooseName(name);namePicker.append(choice);}
    };
    const chooseName=name=>{
      data.name=name;try{window.sessionStorage?.setItem(nameKey,name);}catch(_){}
      road.name=name;road.alternate_names=nameChoices.filter(n=>n!==name);paintNames();paintExports();render();savePreferences();
    };
    swap.onclick=()=>{orientation=orientation==='forward'?'reverse':'forward';try{window.sessionStorage?.setItem(roadDirectionKey(data),orientation);}catch(_){}paintExports();savePreferences();};
    detail.append(direction,favoriteStatus,directionStatus,exports);
    paintNames();paintExports();
    const coordinates=(data.geometry||road.geometry)?.coordinates;
    if(coordinates?.length){
      api('/api/elevations',{method:'POST',body:JSON.stringify({points:[coordinates[0].slice(0,2),coordinates.at(-1).slice(0,2)]})})
        .then(result=>{elevations=Array.isArray(result.elevations_m)?result.elevations_m:null;})
        .catch(()=>{elevations=null;})
        .finally(()=>{elevationLoading=false;paintOverview();scheduleRoadFit();});
    }else{elevationLoading=false;paintOverview();}
    const breakdown=text('details','','road-breakdown');breakdown.append(text('summary','Score breakdown & details'));const metrics=text('div','','road-dimensions');breakdown.append(metrics);
    Object.entries(data.dimensions).forEach(([key,value])=>{const row=text('div','','dimension');row.append(text('span',key),text('strong',`${Math.round(value*100)}/100`));metrics.append(row);});
    breakdown.append(text('p','Endpoint elevations estimate ground height, not surveyed road or bridge height. Score dimensions are weighted for the selected profile. Completeness measures mapped attributes, not certainty that every control is present.'));
    breakdown.append(text('p','Exports follow the selected direction; no navigation to the start is included.'));detail.append(breakdown);scheduleRoadFit();
  } catch(e) { if(selected===id) detail.append(text('p',e.message)); }
}

function showSearchError(error,context='Road search',retrying=false,preparation=null){
  const reason=error?.message||'An unexpected error occurred. Please try again.';
  const message=context+' failed. '+reason+(retrying?' Retrying automatically…':' You can retry this search.');
  setStatus(message,retrying,preparation);
  $('search-error').textContent=message;$('search-error').hidden=false;
  $('map-status').textContent=retrying?'SEARCH RETRY SCHEDULED':'SEARCH NEEDS ATTENTION';
  scrollToSearchProgress();
}
async function discover(payload, sequence) {
  let phase='Road search';
  try {
    const params={...payload};delete params._mapShown;delete params._recorded;delete params._historyId;delete params._searchJob;params.record_history=!payload._recorded;params.background=true;
    if(payload._historyId)params.history_id=payload._historyId;
    let data=payload._searchJob?await api('/api/search-jobs/'+payload._searchJob):await api('/api/search',{method:'POST',body:JSON.stringify(params)});
    if(sequence!==request)return;
    if(data.search_job){
      const job=data.search_job;
      if('history_id' in data)payload._historyId=data.history_id??undefined;
      if(['failed','cancelled'].includes(job.status))throw new Error(job.error||'Search cancelled.');
      if(job.status!=='complete'){
        payload._searchJob=job.id;rememberSearch(payload);
        const preparation=data.preparation?{...data.preparation,state:'preparing',message:job.status==='queued'&&data.preparation.state!=='ready'?data.preparation.message:job.progress}:null;
        setStatus(job.progress+(job.status==='queued'?'. '+(job.ahead?job.ahead+' search(es) ahead. ':'')+'Waiting for the search worker.':'.')+(preparation?.total?' '+preparation.complete+'/'+preparation.total+' areas ready.':'')+(roads.length?' Previously displayed roads remain visible.':''),true,preparation);
        if(preparation?.warning){$('search-error').textContent=preparation.warning;$('search-error').hidden=false;}
        pollTimer=setTimeout(()=>discover(payload,sequence),2000);return;
      }
      delete payload._searchJob;data=data.result;
    }
    if(!data||!Array.isArray(data.results))throw new Error('The server returned an invalid search response. Reload the page and try again.');
    phase='Displaying search results';
    currentSearchParameters=data.parameters || {...params};delete currentSearchParameters.record_history;
    payload._recorded=true;payload._historyId=data.history_id??payload._historyId;
    roads=data.results || [];activeProfile=payload.profile;render();
    const preparation=data.preparation;
    const retryNote=preparation?.retrying>0&&preparation.running===0
      ? ' Waiting for an automatic retry'+(preparation.next_retry_at?' at '+new Date(preparation.next_retry_at).toLocaleTimeString():'')+'.'
      : preparation?.queued>0&&preparation.running===0?' Waiting for the background worker.':'';

    if(preparation && preparation.state!=='ready') {
      setStatus(`${preparation.message} · ${preparation.complete}/${preparation.total} areas ready.`+
        (preparation.state==='failed'?' A SuperAdmin can retry failed tasks in Manage.':` ${roads.length?'Showing partial results.':'First-time preparation can take several minutes.'} This search will update automatically.`)+retryNote, preparation.state!=='failed', preparation);
      if(preparation.warning&&preparation.state!=='failed'){
        $('search-error').textContent=preparation.warning+' '+(roads.length?'Available roads remain visible; these results are incomplete.':'This search is incomplete.');$('search-error').hidden=false;
        $('map-status').textContent='PREPARATION NEEDS ATTENTION';
      }
      if(preparation.state==='failed')showSearchError(new Error(preparation.message+' A SuperAdmin can retry failed tasks in Manage.'),'Road preparation',false,preparation);
      else pollTimer=setTimeout(()=>discover(payload,sequence),5000);
    } else {
      setStatus(roads.length?`${roads.length}${data.truncated?'+':''} roads found near ${data.center.place}.`:'No roads match these filters. Try adjusting the length, stop, or surface filters.',false,preparation||{state:'ready'});
    }
    if(map && data.center) {
      phase='Updating the discovery map';
      await ready;if(sequence!==request)return;
      map.getSource('roads').setData({type:'FeatureCollection',features:roads.map(r=>({type:'Feature',geometry:r.geometry,properties:{id:r.id,score:r.score}}))});
      map.setFilter('selected',['==',['get','id'],-1]);
      const radius=circle(Number(data.center.lon),Number(data.center.lat),payload.radius_miles);map.getSource('radius').setData(radius);
      if(!payload._mapShown) { map.fitBounds(bounds(radius.geometry.coordinates[0]),{padding:35}); payload._mapShown=true; }
    }
  } catch(e) {
    if(sequence!==request)return;
    showSearchError(e,phase,e.status===503);
    if(e.status===503)pollTimer=setTimeout(()=>discover(payload,sequence),10000);
  } finally { if(sequence===request)$('submit').disabled=false; }
}
function searchParameters(form) {
  return {zip:form.zip.value,radius_miles:Number(form.radius.value),profile:form.profile.value,
    ...(form.min_length.value.trim()===''?{}:{min_length_miles:Number(form.min_length.value)}),
    ...(form.max_length.value.trim()===''?{}:{max_length_miles:Number(form.max_length.value)}),
    min_curve_density_score:Number(form.min_curve_density_score.value),min_curvature_quality_score:Number(form.min_curvature_quality_score.value),min_sustained_curve_ft:Number(form.min_sustained_curve_ft.value),max_stops:Number(form.max_stops.value),surface_filter:form.surface_filter.value};
}
let diagnosticRequest=0;
$('road-diagnostic').onsubmit=async event=>{
  event.preventDefault();
  const search=$('search');if(search.reportValidity && !search.reportValidity())return;
  const sequence=++diagnosticRequest;
  $('diagnostic-submit').disabled=true;$('diagnostic-status').textContent='Checking locally imported ways and candidate exclusions…';
  $('diagnostic-results').replaceChildren();
  try {
    let data=await api('/api/search-diagnostics',{method:'POST',body:JSON.stringify({...searchParameters(search),road:event.target.road.value})});
    if(sequence!==diagnosticRequest)return;
    while(data.search_job){
      const job=data.search_job;
      if(['failed','cancelled'].includes(job.status))throw new Error(job.error||'Road diagnosis cancelled.');
      if(job.status==='complete'){data=data.result;break;}
      $('diagnostic-status').textContent=job.progress+(job.status==='queued'?' · Waiting for the search worker'+(job.ahead?' · '+job.ahead+' search(es) ahead':''):'');
      await new Promise(resolve=>setTimeout(resolve,2000));
      if(sequence!==diagnosticRequest)return;
      data=await api('/api/search-jobs/'+job.id);
      if(sequence!==diagnosticRequest)return;
    }
    if(!data||!Array.isArray(data.candidates)||!Array.isArray(data.ways))throw new Error('The server returned an invalid diagnosis. Please try again.');
    $('diagnostic-status').textContent='Checking '+data.road+' near '+data.parameters.zip+' within '+data.parameters.radius_miles+' miles. '+data.ways.length+' matching source ways; '+data.candidates.length+' continuous candidates. '+
      (data.full_radius_covered?'Search area has imported coverage. ':'Search area is not fully covered yet. ')+(data.truncated?'Showing the first 100 matches. ':'');
    data.candidates.forEach(road=>{
      const row=text('div','','diagnostic-road');
      row.append(text('strong',road.name+(road.ref?' ('+road.ref+')':'')),
        text('p',(road.length_m/1609.344).toFixed(2)+' mi · '+road.mandatory_stops+' stops + '+road.uncertain_controls+' uncertain · '+road.highway));
      if(alternateLabel(road))row.append(text('p',alternateLabel(road)));
      const reasons=road.reasons.length?road.reasons:['Included in the current search results.'];
      reasons.forEach(reason=>row.append(text('p',reason)));$('diagnostic-results').append(row);
    });
    if(data.ways.length) {
      $('diagnostic-results').append(text('h3','Imported source tags'));
      const tags=new Set(data.ways.map(w=>[w.name||'Unnamed',w.ref||'no route reference',w.highway,'surface: '+(w.surface||'unknown')].join(' · ')));
      [...tags].forEach(tag=>$('diagnostic-results').append(text('p',tag)));
    }
    if(!data.candidates.length)$('diagnostic-results').append(text('p',data.ways.length?
      'Source ways were imported, but no matching continuous candidate was built. Check classification, segmentation, continuity and access restrictions.':
      'No matching source ways in active local data. The name/reference may differ, the road may be outside the radius, or the import may have excluded it.'));
    $('diagnostic-results').append(text('p',data.note));
  } catch(e){if(sequence===diagnosticRequest)$('diagnostic-status').textContent='Road diagnosis failed. '+e.message+' You can retry this check.';}
  finally{if(sequence===diagnosticRequest)$('diagnostic-submit').disabled=false;}
};

function scrollToSearchProgress(){
  const feedback=$('search-feedback'),panel=feedback.closest?.('aside');
  if(panel&&panel.scrollHeight>panel.clientHeight){
    panel.scrollTo({top:Math.max(0,panel.scrollTop+feedback.getBoundingClientRect().top-panel.getBoundingClientRect().top-16),behavior:'auto'});
  }else feedback.scrollIntoView({behavior:'auto',block:'start'});
}

$('search').onsubmit = event => {
  event.preventDefault();showDiscoveryTab('zip');cancelCustomRoad();updatePreparationAreas(null);clearTimeout(pollTimer);const sequence=++request,form=event.target;
  $('submit').disabled=true;setStatus('Preparing your road search…',true);$('road-detail').hidden=true;selected=null;clearRoadEndpoints();
  const payload={...searchParameters(form),_recorded:event.fromHistory===true};
  scrollToSearchProgress();rememberSearch(payload);discover(payload,sequence);
};
function sameHistoryFilters(a,b){
  const normalize=p=>({
    zip:String(p.zip),profile:p.profile??'twisty',radius_miles:Number(p.radius_miles??5),
    min_length_miles:Number(p.min_length_miles??0),max_length_miles:p.max_length_miles==null?null:Number(p.max_length_miles),
    max_stops:Number(p.max_stops??1),
    surface_filter:p.surface_filter??(p.paved_only===true?'known_paved':p.paved_only===false?'any':'exclude_unpaved'),
    min_curve_density_score:Number(p.min_curve_density_score??(p.min_curve_density==null?2.2:1+9*p.min_curve_density/53.6448)),
    min_curvature_quality_score:Number(p.min_curvature_quality_score??(1+9*(p.min_curvature_quality??0.12))),
    min_sustained_curve_ft:Number(p.min_sustained_curve_ft??(p.min_sustained_curve_m==null?150:p.min_sustained_curve_m/0.3048))
  });
  const left=normalize(a),right=normalize(b);
  return Object.keys(left).every(key=>typeof left[key]==='number'&&typeof right[key]==='number'?Math.abs(left[key]-right[key])<1e-8:left[key]===right[key]);
}
async function openLegacyHistory(){
  clearTimeout(pollTimer);const sequence=++request;
  setStatus('Looking up saved results for this older History link…',true);
  try{
    const entries=await api('/api/search-history');if(sequence!==request)return;
    const parameters=searchParameters($('search'));
    const legacyQuery=new URLSearchParams(window.location?.search||'');
    if(legacyQuery.has('max_stops'))parameters.max_stops=Number(legacyQuery.get('max_stops'));
    const entry=entries.find(row=>sameHistoryFilters(row.parameters,parameters));
    if(entry){window.history?.replaceState?.(null,'','/?history='+encodeURIComponent(entry.id));await openHistory(entry.id);return;}
    roads=[];render();selected=null;clearRoadEndpoints();$('road-detail').hidden=true;
    showDiscoveryTab('zip');
    setStatus('This older link has no matching saved History entry. Its filters are loaded. Choose Discover roads to run a new search.');
  }catch(error){if(sequence===request)showSearchError(error,'Opening older History link');}
  finally{if(sequence===request)$('submit').disabled=false;}
}

async function openHistory(id){
  clearTimeout(pollTimer);const sequence=++request;
  selected=null;clearRoadEndpoints();$('road-detail').hidden=true;
  showDiscoveryTab('discoveries');setStatus('Loading saved search results…',true);
  try{
    const saved=await api('/api/search-history/'+id);if(sequence!==request)return;
    fillSearch(saved.parameters);currentSearchParameters=saved.parameters;activeProfile=saved.parameters.profile;
    const data=saved.result_snapshot;
    if(!data){
      roads=[];render();showDiscoveryTab('zip');
      setStatus('This older entry has no saved results. Its filters are loaded; choose Discover roads to run a new search.');
      if(map){await ready;if(sequence!==request)return;map.getSource('roads').setData(empty());map.getSource('radius').setData(empty());}
      return;
    }
    roads=(data.results||[]).map(road=>({...road,_historySnapshot:true}));render();
    const partial=data.preparation&&data.preparation.state!=='ready';
    setStatus('Saved results from '+new Date(saved.snapshot_at||saved.created_at).toLocaleString()+'. '+roads.length+' roads.'+(partial?' This is an incomplete snapshot of the results available at that time.':'')+' Choose Discover roads to run a fresh search.');
    showDiscoveryTab('discoveries');
    if(map){
      await ready;if(sequence!==request)return;
      map.getSource('roads').setData({type:'FeatureCollection',features:roads.map(r=>({type:'Feature',geometry:r.geometry,properties:{id:r.id,score:r.score}}))});
      map.setFilter('selected',['==',['get','id'],-1]);
      if(data.center){const radius=circle(Number(data.center.lon),Number(data.center.lat),saved.parameters.radius_miles);map.getSource('radius').setData(radius);map.fitBounds(bounds(radius.geometry.coordinates[0]),{padding:35});}
      else map.getSource('radius').setData(empty());
    }
  }catch(error){if(sequence===request)showSearchError(error,'Opening saved search');}
  finally{if(sequence===request)$('submit').disabled=false;}
}

async function openFavorite(id) {
  clearTimeout(pollTimer);const sequence=++request;
  showDiscoveryTab('discoveries');setStatus('Loading favorite road…',true);
  try {
    const saved=await api('/api/favorites/'+id);if(sequence!==request)return;
    const road={...saved.road,_snapshot:true};
    currentSearchParameters=saved.search_parameters;fillSearch(currentSearchParameters);activeProfile=saved.profile;
    roads=[road];render();selectRoad(road.id);
    setStatus('Saved favorite · '+new Date(saved.created_at).toLocaleDateString()+'. Geometry and metrics reflect when you saved this road.');
    if(map)ready.then(()=>{
      if(sequence!==request)return;
      map.getSource('roads').setData({type:'FeatureCollection',features:[{type:'Feature',geometry:road.geometry,properties:{id:road.id,score:road.score}}]});
      map.getSource('radius').setData(empty());
      map.setFilter('selected',['==',['get','id'],road.id]);
      scheduleRoadFit();
    });
  } catch(e){if(sequence===request)setStatus(e.message);}
}
async function handleSession(event) {
  try { await applyDiscoverySession(event); }
  catch(error){showSearchError(error,'Restoring discovery');$('submit').disabled=false;}
}
async function applyDiscoverySession(event) {
  if(window.location?.pathname && window.location.pathname!=='/')return;
  if(!event.detail.user) {
    window.sweeplineBeamStop?.();
    if(activeSearchUser) {try {window.sessionStorage?.removeItem(searchStorageKey(activeSearchUser));}catch(_) {}}
    ++diagnosticRequest;$('diagnostic-results').replaceChildren();$('diagnostic-status').textContent='';$('diagnostic-submit').disabled=false;
    cancelCustomRoad();++zipPickRequest;setZipPickMode(false);$('pick-zip').disabled=false;$('zip-pick-status').textContent='';
    clearRoadEndpoints();activeSearchUser=null;restoredSearchUser=null;currentSearchParameters=null;
    ++request;clearTimeout(pollTimer);roads=[];render();$('road-detail').hidden=true;$('submit').disabled=false;setStatus('');
    if(map && map.getSource('roads')) { map.getSource('roads').setData(empty());map.getSource('radius').setData(empty()); }
    return;
  }
  activeSearchUser=event.detail.user.id;
  if(restoredSearchUser!==activeSearchUser) {
    restoredSearchUser=activeSearchUser;
    if(!explicitSearchParameters && restoreSearch(activeSearchUser)) {
      pendingHistorySearch=true;setStatus('Restoring your search and checking preparation progress…',true);
    }
  }
  if(!map)initMap();
  if(pendingLegacyHistory){pendingLegacyHistory=false;await openLegacyHistory();}
  if(pendingHistoryId){const id=pendingHistoryId;pendingHistoryId=null;await openHistory(id);}
  if(pendingFavorite) {const id=pendingFavorite;pendingFavorite=null;openFavorite(id);}
  if(pendingHistorySearch) {
    pendingHistorySearch=false;
    const form=$('search');
    if(!form.checkValidity || form.checkValidity())form.onsubmit({preventDefault(){},target:form,fromHistory:new URLSearchParams(window.location?.search||'').get('fresh')!=='1'});
    else showSearchError(new Error('Saved search contains filters that are no longer supported. Please adjust them.'),'Opening previous search');
  }
  if(map) { await ready;map.resize(); }
  api('/api/coverage').then(regions=>{
    const active=regions.filter(r=>r.active_job_id);
    $('coverage').textContent=active.length?`${active.length} AREAS CACHED`:'ROAD DATA PREPARES AUTOMATICALLY';
  }).catch(()=>{$('coverage').textContent='COVERAGE STATUS UNAVAILABLE';});
}


if(window.location?.pathname==='/') {
  const params=new URLSearchParams(window.location.search);
  if(/^[1-9][0-9]{0,17}$/.test(params.get('favorite')||'')) {pendingFavorite=params.get('favorite');explicitSearchParameters=true;}
  if(/^[1-9][0-9]{0,17}$/.test(params.get('history')||'')){pendingHistoryId=params.get('history');explicitSearchParameters=true;}
  if(!pendingFavorite && !pendingHistoryId && params.has('zip')) {
    explicitSearchParameters=true;
    const form=$('search');
    for(const key of ['zip','profile'])if(params.has(key))form[key].value=params.get(key);
    for(const [key,field] of [['radius_miles','radius'],['min_length_miles','min_length'],['max_length_miles','max_length'],['max_stops','max_stops'],['min_curve_density_score','min_curve_density_score'],['min_curvature_quality_score','min_curvature_quality_score'],['min_sustained_curve_ft','min_sustained_curve_ft'],['surface_filter','surface_filter']])
      if(params.has(key))form[field].value=params.get(key);
    if(!params.has('min_curvature_quality_score')&&params.has('min_curvature_quality'))form.min_curvature_quality_score.value=String(1+9*Number(params.get('min_curvature_quality')));
    if(!params.has('min_sustained_curve_ft')&&params.has('min_sustained_curve_m'))form.min_sustained_curve_ft.value=String(Number(params.get('min_sustained_curve_m'))/0.3048);
    if(form.max_stops.value==='0')form.max_stops.value='1';
    if(!params.has('min_curve_density_score')&&params.has('min_curve_density'))form.min_curve_density_score.value=String(1+9*Number(params.get('min_curve_density'))/53.6448);
    if(!params.has('surface_filter')&&params.has('paved_only'))form.surface_filter.value=params.get('paved_only')==='true'?'known_paved':'any';
    pendingHistorySearch=params.get('open')==='1'&&params.get('fresh')==='1';
    pendingLegacyHistory=params.get('open')==='1'&&!pendingHistorySearch;
    setStatus(pendingHistorySearch?'Starting fresh search…':pendingLegacyHistory?'Opening saved results for an older History link…':'Previous search loaded. Select Discover roads to run it again.');
  }
}

window.addEventListener('sweepline:sessionchange',handleSession);
if(window.SweepLineAccount?.user)handleSession({detail:window.SweepLineAccount});

window.addEventListener('pageshow',event=>{
  refreshVisibleMap();
  if(event.persisted && window.SweepLineAccount?.user) {
    restoredSearchUser=null;explicitSearchParameters=false;
    const history=new URLSearchParams(window.location.search).get('history');
    if(/^[1-9][0-9]{0,17}$/.test(history||'')){pendingHistoryId=history;explicitSearchParameters=true;}
    const favorite=new URLSearchParams(window.location.search).get('favorite');
    if(/^[1-9][0-9]{0,17}$/.test(favorite||'')){pendingFavorite=favorite;explicitSearchParameters=true;}
    handleSession({detail:window.SweepLineAccount});
  }
});
