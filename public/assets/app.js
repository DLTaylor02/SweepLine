'use strict';
const $ = id => document.getElementById(id);
let map, ready, roads = [], selected = null, request = 0, pollTimer, activeProfile='twisty';
let pendingHistorySearch=false, activeSearchUser=null, restoredSearchUser=null, explicitSearchParameters=false;
let currentSearchParameters=null,pendingFavorite=null;
const searchStorageKey=id=>'sweepline:active-search:'+id;
function rememberSearch(payload) {
  if(!activeSearchUser)return;
  try {
    const parameters={...payload};delete parameters._recorded;delete parameters._mapShown;
    window.sessionStorage?.setItem(searchStorageKey(activeSearchUser),JSON.stringify(parameters));
  } catch (_) { /* Search remains usable when browser storage is unavailable. */ }
}
function fillSearch(p) {
  const form=$('search');
  form.zip.value=p.zip;form.profile.value=p.profile;form.radius.value=String(p.radius_miles);
  form.min_length.value=p.min_length_miles==null?'':String(p.min_length_miles);
  form.max_length.value=p.max_length_miles==null?'':String(p.max_length_miles);
  form.max_stops.value=String(Math.max(1,p.max_stops));form.paved.checked=p.paved_only;
}
function restoreSearch(userId) {
  try {
    const raw=window.sessionStorage?.getItem(searchStorageKey(userId));
    if(!raw)return false;
    const p=JSON.parse(raw);
    if(!p || !/^\d{5}$/.test(p.zip) || !Number.isInteger(p.radius_miles) || p.radius_miles<1 || p.radius_miles>50 ||
      !['twisty','open'].includes(p.profile) || typeof p.paved_only!=='boolean' || !Number.isInteger(p.max_stops) ||
      p.max_stops<0 || p.max_stops>100)return false;
    fillSearch(p);return true;
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
  setZipPickMode(true);
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
    map.on('click','roads', e => {if(!pickingZip)selectRoad(Number(e.features[0].properties.id));});
    map.on('mouseenter','roads',()=>map.getCanvas().style.cursor=pickingZip?'crosshair':'pointer');
    map.on('mouseleave','roads',()=>map.getCanvas().style.cursor=pickingZip?'crosshair':'');
    map.on('click',pickZipAt);
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
    const data=road._snapshot?road:await api(`/api/roads/${id}?profile=${encodeURIComponent(activeProfile)}`);
    if(selected!==id) return;
    detail.replaceChildren(close,text('h2',`${data.name} · ${data.score}/100`));
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
          const saved=await api('/api/favorites',{method:'POST',body:JSON.stringify({road_id:data.id,profile:activeProfile,search:currentSearchParameters})});
          data.favorite_id=saved.id;favoriteStatus.textContent='Road saved to favorites.';
        }
        heart.textContent=data.favorite_id?'♥ Favorited':'♡ Add to favorites';heart.setAttribute('aria-pressed',String(!!data.favorite_id));
      } catch(e){favoriteStatus.textContent=e.message;}
      finally {heart.disabled=false;}
    };
    detail.append(heart,favoriteStatus);
    const exports=text('div','','road-exports');
    for(const [format,label] of [['gpx','GPX'],['kmz','KMZ'],['pdf','Printable PDF']]) {
      const link=text('a',label);link.href=road._snapshot?`/api/favorites/${data.favorite_id}/export?format=${format}`:`/api/roads/${id}/export?format=${format}&profile=${encodeURIComponent(activeProfile)}`;
      link.setAttribute('download','');exports.append(link);
    }
    detail.append(exports,text('p','Export this road track or a PDF with a road sketch and section directions. No navigation to the start is included.'));
    Object.entries(data.dimensions).forEach(([key,value])=>{const row=text('div','','dimension');row.append(text('span',key),text('strong',`${Math.round(value*100)}/100`));detail.append(row);});
    detail.append(text('p','Elevation unavailable. Score dimensions are weighted for the selected profile. Completeness measures mapped attributes, not certainty that every control is present.'));
  } catch(e) { if(selected===id) detail.append(text('p',e.message)); }
}

async function discover(payload, sequence) {
  try {
    const params={...payload};delete params._mapShown;delete params._recorded;params.record_history=!payload._recorded;
    const data=await api('/api/search',{method:'POST',body:JSON.stringify(params)});
    if(sequence!==request)return;
    currentSearchParameters=data.parameters || {...params};delete currentSearchParameters.record_history;
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
function searchParameters(form) {
  return {zip:form.zip.value,radius_miles:Number(form.radius.value),profile:form.profile.value,
    ...(form.min_length.value.trim()===''?{}:{min_length_miles:Number(form.min_length.value)}),
    ...(form.max_length.value.trim()===''?{}:{max_length_miles:Number(form.max_length.value)}),
    max_stops:Number(form.max_stops.value),paved_only:form.paved.checked};
}
let diagnosticRequest=0;
$('road-diagnostic').onsubmit=async event=>{
  event.preventDefault();
  const search=$('search');if(search.reportValidity && !search.reportValidity())return;
  const sequence=++diagnosticRequest;
  $('diagnostic-submit').disabled=true;$('diagnostic-status').textContent='Checking locally imported ways and candidate exclusions…';
  $('diagnostic-results').replaceChildren();
  try {
    const data=await api('/api/search-diagnostics',{method:'POST',body:JSON.stringify({...searchParameters(search),road:event.target.road.value})});
    if(sequence!==diagnosticRequest)return;
    $('diagnostic-status').textContent='Checking '+data.road+' near '+data.parameters.zip+' within '+data.parameters.radius_miles+' miles. '+data.ways.length+' matching source ways; '+data.candidates.length+' continuous candidates. '+
      (data.full_radius_covered?'Search area has imported coverage. ':'Search area is not fully covered yet. ')+(data.truncated?'Showing the first 100 matches. ':'');
    data.candidates.forEach(road=>{
      const row=text('div','','diagnostic-road');
      row.append(text('strong',road.name+(road.ref?' ('+road.ref+')':'')),
        text('p',(road.length_m/1609.344).toFixed(2)+' mi · '+road.mandatory_stops+' stops + '+road.uncertain_controls+' uncertain · '+road.highway));
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
  } catch(e){if(sequence===diagnosticRequest)$('diagnostic-status').textContent=e.message;}
  finally{if(sequence===diagnosticRequest)$('diagnostic-submit').disabled=false;}
};

$('search').onsubmit = event => {
  event.preventDefault();clearTimeout(pollTimer);const sequence=++request,form=event.target;
  $('submit').disabled=true;setStatus('Preparing your road search…',true);$('road-detail').hidden=true;selected=null;
  const payload={...searchParameters(form),_recorded:event.fromHistory===true};
  rememberSearch(payload);discover(payload,sequence);
};
async function openFavorite(id) {
  clearTimeout(pollTimer);const sequence=++request;
  setStatus('Loading favorite road…',true);
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
      map.fitBounds(bounds(road.geometry.coordinates),{padding:65,maxZoom:13});
    });
  } catch(e){if(sequence===request)setStatus(e.message);}
}
async function handleSession(event) {
  if(window.location?.pathname && window.location.pathname!=='/')return;
  if(!event.detail.user) {
    if(activeSearchUser) {try {window.sessionStorage?.removeItem(searchStorageKey(activeSearchUser));}catch(_) {}}
    ++diagnosticRequest;$('diagnostic-results').replaceChildren();$('diagnostic-status').textContent='';$('diagnostic-submit').disabled=false;
    ++zipPickRequest;setZipPickMode(false);$('pick-zip').disabled=false;$('zip-pick-status').textContent='';
    activeSearchUser=null;restoredSearchUser=null;currentSearchParameters=null;
    ++request;clearTimeout(pollTimer);roads=[];render();$('road-detail').hidden=true;$('submit').disabled=false;setStatus('Choose a ZIP code to explore roads nearby.');
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
  if(pendingFavorite) {const id=pendingFavorite;pendingFavorite=null;openFavorite(id);}
  if(pendingHistorySearch) {
    pendingHistorySearch=false;
    const form=$('search');
    if(!form.checkValidity || form.checkValidity())form.onsubmit({preventDefault(){},target:form,fromHistory:true});
    else setStatus('Saved search contains filters that are no longer supported. Please adjust them.');
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
  if(!pendingFavorite && params.has('zip')) {
    explicitSearchParameters=true;
    const form=$('search');
    for(const key of ['zip','profile'])if(params.has(key))form[key].value=params.get(key);
    for(const [key,field] of [['radius_miles','radius'],['min_length_miles','min_length'],['max_length_miles','max_length'],['max_stops','max_stops']])
      if(params.has(key))form[field].value=params.get(key);
    if(form.max_stops.value==='0')form.max_stops.value='1';
    if(params.has('paved_only'))form.paved.checked=params.get('paved_only')==='true';
    pendingHistorySearch=params.get('open')==='1';
    setStatus(pendingHistorySearch?'Opening previous search…':'Previous search loaded. Select Discover roads to run it again.');
  }
}

window.addEventListener('sweepline:sessionchange',handleSession);
if(window.SweepLineAccount?.user)handleSession({detail:window.SweepLineAccount});

window.addEventListener('pageshow',event=>{
  if(event.persisted && window.SweepLineAccount?.user) {
    restoredSearchUser=null;explicitSearchParameters=false;
    const favorite=new URLSearchParams(window.location.search).get('favorite');
    if(/^[1-9][0-9]{0,17}$/.test(favorite||'')){pendingFavorite=favorite;explicitSearchParameters=true;}
    handleSession({detail:window.SweepLineAccount});
  }
});
