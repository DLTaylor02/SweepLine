'use strict';
(() => {
  let timer,view=0;
  window.sweeplineBeamStop=()=>{++view;clearTimeout(timer);};
  window.sweeplineBeamExport=(container,road,context={})=>{
    const current=++view;clearTimeout(timer);
    const node=(tag,text)=>{const n=document.createElement(tag);if(text)n.textContent=text;return n;};
    const section=node('section');section.className='beam-export';
    section.append(node('h3','BeamNG.drive v0.40 map'));
    section.append(node('p','Square map at real-world scale, with Start and End spawns. Terrain and aerial data are prepared in the background; scenery and missing lane data are estimates.'));
    const options=node('div');options.className='pair';
    const paddingLabel=node('label','Padding per side (miles)'),padding=node('input');
    Object.assign(padding,{type:'number',min:'0.1',max:'5',step:'any',value:'0.5'});paddingLabel.append(padding);
    const qualityLabel=node('label','Terrain detail'),quality=node('select');
    for(const [value,label] of [['1024','Standard (1024²)'],['2048','High (2048²)']]){const o=node('option',label);o.value=value;quality.append(o);}quality.value='1024';qualityLabel.append(quality);
    options.append(paddingLabel,qualityLabel);
    const generate=node('button','Generate BeamNG map');generate.type='button';
    const cancel=node('button','Cancel export');cancel.type='button';cancel.hidden=true;
    const status=node('p');status.setAttribute('role','status');status.setAttribute('aria-live','polite');
    const spinner=node('span');spinner.className='spinner';spinner.hidden=true;spinner.setAttribute('aria-hidden','true');
    const download=node('a','Download BeamNG map ZIP');download.className='page-back';download.hidden=true;download.setAttribute('download','');
    section.append(options,generate,cancel,spinner,status,download);container.append(section);
    const user=window.SweepLineAccount?.user?.id;
    const storageKey='sweepline:beamng:'+user+':'+(road.geometry_key||road.id);
    let jobId=null;
    const remember=()=>{try{window.sessionStorage?.setItem(storageKey,JSON.stringify({id:jobId,padding:padding.value,resolution:quality.value}));}catch(_){}};
    const paint=job=>{
      const busy=['queued','running'].includes(job.status);
      generate.disabled=busy;cancel.hidden=!busy;spinner.hidden=!busy;padding.disabled=busy;quality.disabled=busy;
      download.hidden=job.status!=='complete';status.textContent=job.error||job.progress;
      if(job.download_url)download.href=job.download_url;
      if(job.status==='complete' && job.report)status.textContent+=' '+(job.report.map_side_m/1609.344).toFixed(2)+' miles square. Lane counts and scenery include estimates; review the included report.';
      return busy;
    };
    const poll=async()=>{
      if(current!==view || !jobId)return;
      try {
        const job=await window.sweeplineRequest('/api/beamng/'+jobId);
        if(current!==view)return;
        if(paint(job))timer=setTimeout(poll,3000);
      } catch(e){if(current===view){status.textContent=e.message;generate.disabled=false;cancel.hidden=true;spinner.hidden=true;padding.disabled=false;quality.disabled=false;}}
    };
    generate.onclick=async()=>{
      if(!padding.reportValidity() || !quality.reportValidity())return;
      generate.disabled=true;spinner.hidden=false;download.hidden=true;status.textContent='Queueing map generation…';
      try {
        const payload={padding_miles:Number(padding.value),resolution:Number(quality.value)};
        if(context.favoriteId)payload.favorite_id=context.favoriteId;
        else Object.assign(payload,{road_id:road.id,profile:context.profile||'twisty',...((road.member_ids||[]).length>1?{member_ids:road.member_ids}:{})});
        const job=await window.sweeplineRequest('/api/beamng',{method:'POST',body:JSON.stringify(payload)});
        if(current!==view)return;jobId=job.id;remember();if(paint(job))timer=setTimeout(poll,3000);
      } catch(e){if(current===view){status.textContent=e.message;generate.disabled=false;spinner.hidden=true;}}
    };
    cancel.onclick=async()=>{
      cancel.disabled=true;
      try{await window.sweeplineRequest('/api/beamng/'+jobId+'/cancel',{method:'POST',body:'{}'});await poll();}
      catch(e){status.textContent=e.message;}finally{cancel.disabled=false;}
    };
    try {
      const saved=JSON.parse(window.sessionStorage?.getItem(storageKey)||'null');
      if(saved && /^[a-f0-9]{32}$/.test(saved.id)){jobId=saved.id;padding.value=saved.padding;quality.value=saved.resolution;poll();}
    } catch(_){}
  };
})();

