'use strict';
(() => {
  if(window.location.pathname!=='/regions')return;
  const el=id=>document.getElementById(id);
  let map=null,loaded=false,timer=null,pulse=null,selected=null,data={features:[]},request=0,detailRequest=0,acting=false,fit=false;
  const bytes=value=>value===null?'Unavailable':(Number(value)/1048576).toLocaleString(undefined,{maximumFractionDigits:2})+' MiB';
  const node=(tag,value)=>{const item=document.createElement(tag);item.textContent=value;return item;};
  const color=['match',['get','status'],'complete','#22c55e','running','#facc15','discarding','#94a3b8','failed','#d97706','#ef4444'];
  function theme(){
    if(!loaded)return;
    const dark=document.documentElement.dataset.theme==='dark';
    map.setPaintProperty('region-basemap','raster-brightness-max',dark?0.4:1);
    map.setPaintProperty('region-basemap','raster-saturation',-0.6);
  }
  function paint(){
    if(!loaded)return;
    map.getSource('regions').setData(data);
    map.setFilter('region-selected',['==',['get','key'],selected||'']);
  }
  function renderList(){
    const query=String(el('region-filter').value||'').trim().toLowerCase();
    const matches=data.features.filter(f=>(f.properties.name+' '+f.properties.key+' '+f.properties.status).toLowerCase().includes(query));
    const list=el('regions-list');list.replaceChildren();
    for(const feature of matches.slice(0,150)){
      const p=feature.properties,button=node('button',p.name+' · '+p.status);
      button.type='button';button.className='region-list-item';button.onclick=()=>select(p.key);list.append(button);
    }
    if(matches.length>150)list.append(node('p','Refine the filter or select additional regions on the map.'));
    if(!matches.length)list.append(node('p','No matching regions.'));
  }
  async function refresh(){
    const sequence=++request;
    try{
      const result=await window.sweeplineRequest('/api/admin/regions');
      if(sequence!==request||!map)return;
      data=result;paint();
      el('regions-status').textContent=data.features.length+' regions shown.'+(data.truncated?' Showing a bounded selection of regions.':'');
      el('map-status').textContent='REGION DATA · queued, active and downloaded';
      renderList();
      if(!fit&&loaded&&data.features.length){
        const bounds=new window.maplibregl.LngLatBounds();
        for(const feature of data.features)for(const ring of feature.geometry.coordinates)for(const point of ring)bounds.extend(point);
        map.fitBounds(bounds,{padding:35,maxZoom:11});fit=true;
      }
      if(selected){
        const feature=data.features.find(f=>f.properties.key===selected);
        if(feature)el('region-state').textContent=feature.properties.status+' · '+feature.properties.progress;
        else if(!acting){selected=null;el('region-detail').hidden=true;paint();}
      }
    }catch(error){if(sequence===request)el('regions-status').textContent='Could not load regions. '+error.message;}
    finally{clearTimeout(timer);if(map)timer=setTimeout(refresh,5000);}
  }
  async function select(key){
    selected=key;paint();const sequence=++detailRequest;
    el('region-detail').hidden=false;el('region-title').textContent=key;el('region-size').replaceChildren();
    el('region-action-status').textContent='';el('region-state').textContent='Loading region details…';
    el('region-refresh').disabled=true;el('region-discard').disabled=true;
    try{
      const result=await window.sweeplineRequest('/api/admin/regions?key='+encodeURIComponent(key));
      if(sequence!==detailRequest||key!==selected)return;
      el('region-title').textContent=result.name;el('region-state').textContent=result.status+' · '+result.progress+(result.downloaded?' · Validated coverage available.':'');
      for(const [label,value] of [['Estimated database rows',bytes(result.database_estimated_bytes)],['Managed download files',bytes(result.file_bytes)],['Managed files',String(result.file_count)]]){
        el('region-size').append(node('dt',label),node('dd',value));
      }
      el('region-size-note').textContent=result.size_note+(result.external_source_files_retained?' External/manual source files are retained.':'')+(result.warning?' '+result.warning:'');
      el('region-refresh').disabled=['running','discarding'].includes(result.status);el('region-discard').disabled=result.status==='discarding';
    }catch(error){if(sequence===detailRequest){el('region-state').textContent='Could not inspect region. '+error.message;}}
  }
  async function action(action){
    if(!selected||acting)return;
    if(action==='discard'&&!window.confirm('Discard this region and its local road data? Active searches may download it again.'))return;
    acting=true;el('region-refresh').disabled=true;el('region-discard').disabled=true;
    const key=selected;el('region-action-status').textContent=action==='discard'?'Queuing discard…':'Queuing fresh download…';
    try{
      const result=await window.sweeplineRequest('/api/admin/regions/action',{method:'POST',body:JSON.stringify({key,action})});
      el('region-action-status').textContent=result.message;await refresh();
    }catch(error){el('region-action-status').textContent='Could not '+action+' region. '+error.message;}
    finally{acting=false;el('region-refresh').disabled=false;el('region-discard').disabled=false;}
  }
  function session(event){
    const user=event.detail.user;
    if(!user||user.role!=='superadmin'||user.must_change_password){
      clearTimeout(timer);clearInterval(pulse);++request;++detailRequest;
      if(map)map.remove();map=null;loaded=false;return;
    }
    el('region-controls').hidden=false;
    if(map)return;
    if(!window.maplibregl){el('regions-status').textContent='Map library unavailable. Reload the page to retry.';return;}
    map=new window.maplibregl.Map({container:'map',center:[-98,39],zoom:3,style:{version:8,sources:{osm:{type:'raster',tiles:['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],tileSize:256,attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'}},layers:[{id:'region-basemap',type:'raster',source:'osm'}]}});
    map.addControl(new window.maplibregl.NavigationControl(),'top-right');
    const instance=map;
    map.on('load',()=>{
      if(map!==instance)return;
      map.addSource('regions',{type:'geojson',data:{type:'FeatureCollection',features:[]}});
      map.addLayer({id:'region-fill',type:'fill',source:'regions',paint:{'fill-color':color,'fill-opacity':0.10}});
      map.addLayer({id:'region-border',type:'line',source:'regions',paint:{'line-color':color,'line-width':2,'line-opacity':0.85}});
      map.addLayer({id:'region-selected',type:'line',source:'regions',filter:['==',['get','key'],''],paint:{'line-color':'#f97316','line-width':4}});
      map.on('click','region-fill',event=>{
        const area=feature=>{const points=feature.geometry.coordinates[0],xs=points.map(p=>p[0]),ys=points.map(p=>p[1]);return (Math.max(...xs)-Math.min(...xs))*(Math.max(...ys)-Math.min(...ys));};
        select([...event.features].sort((a,b)=>area(a)-area(b))[0].properties.key);
      });
      loaded=true;theme();refresh();
      pulse=setInterval(()=>{if(map&&loaded)map.setPaintProperty('region-fill','fill-opacity',['case',['==',['get','status'],'running'],0.14+0.07*Math.sin(Date.now()/450),0.09]);},200);
      map.resize();
    });
    map.on('error',()=>{el('regions-status').textContent='Some map content could not load. Region controls remain available.';});
  }
  el('region-filter').oninput=renderList;
  el('regions-reload').onclick=async()=>{await refresh();if(selected&&!acting)await select(selected);};
  el('region-refresh').onclick=()=>action('refresh');el('region-discard').onclick=()=>action('discard');
  window.addEventListener('sweepline:sessionchange',session);window.addEventListener('sweepline:themechange',theme);
  window.addEventListener('resize',()=>map?.resize());
  if(window.SweepLineAccount?.user)session({detail:window.SweepLineAccount});
})();
