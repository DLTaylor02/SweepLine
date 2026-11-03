'use strict';
(() => {
  let timer,view=0;
  window.sweeplineBeamStop=()=>{++view;clearTimeout(timer);};
  window.sweeplineBeamExport=(container,road,context={})=>{
    const current=++view;clearTimeout(timer);
    const node=(tag,text)=>{const n=document.createElement(tag);if(text)n.textContent=text;return n;};
    const action=node('a','Generate BeamNG');action.href='#';action.className='beam-action';
    const section=node('div');section.className='beam-status';section.hidden=true;
    const status=node('span');status.setAttribute('role','status');status.setAttribute('aria-live','polite');
    const spinner=node('span');spinner.className='spinner';spinner.hidden=true;spinner.setAttribute('aria-hidden','true');
    const cancel=node('button','Cancel export');cancel.type='button';cancel.hidden=true;
    const terrainLabel=node('label','Terrain');terrainLabel.className='beam-terrain-style';const terrain=node('select');
    for(const [value,label] of [['stock','Stock materials'],['aerial','Aerial imagery']]){const option=node('option',label);option.value=value;terrain.append(option);}terrain.value='stock';terrainLabel.append(terrain);
    section.append(spinner,status,cancel);container.append(action,section,terrainLabel);
    const user=window.SweepLineAccount?.user?.id;
    try{if(window.sessionStorage?.getItem('sweepline:beamng:terrain:'+user)==='aerial')terrain.value='aerial';}catch(_){}
    const baseStorageKey='sweepline:beamng:'+user+':'+(road.geometry_key||road.id)+':'+(context.orientation||'forward')+':'+(context.preferredName||road.name||'');
    let storageKey=baseStorageKey+':'+terrain.value,noticeKey=storageKey+':notice';
    let jobId=null,busy=false,ready=false,expires=0;
    const paint=job=>{
      if(!['queued','running'].includes(job.status)){try{window.sessionStorage?.removeItem(storageKey);}catch(_){}}
      busy=['queued','running'].includes(job.status);
      terrain.disabled=busy;
      ready=job.status==='complete'&&!!job.download_url;
      expires=job.expires_at?Date.parse(job.expires_at):0;
      action.textContent=ready?'Download BeamNG':busy?'Generating BeamNG…':'Generate BeamNG';
      action.classList.toggle('beam-ready',ready);action.setAttribute('aria-disabled',String(busy));
      action.href=ready?job.download_url:'#';
      if(ready)action.setAttribute('download','');else action.removeAttribute('download');
      const failed=job.status==='failed';
      section.hidden=false;section.classList.toggle('beam-error',failed);
      cancel.hidden=!busy;spinner.hidden=!busy;
      status.setAttribute('role',failed?'alert':'status');status.setAttribute('aria-live',failed?'assertive':'polite');
      status.textContent=failed?'BeamNG export could not be generated. '+(job.error||job.progress||'No reason was returned. Please retry; if it persists, ask a SuperAdmin to check Manage.'):(job.progress||'');
      if(!failed&&job.warning)status.textContent+=' '+job.warning;
      if(failed){try{window.sessionStorage?.setItem(noticeKey,job.error||job.progress||'No reason was returned. Please retry.');}catch(_){}section.scrollIntoView?.({block:'nearest',behavior:'smooth'});}
      else if(busy||ready){try{window.sessionStorage?.removeItem(noticeKey);}catch(_){}}
      if(ready&&expires)timer=setTimeout(poll,Math.max(1000,expires-Date.now()+100));
      return busy;
    };
    const fail=e=>{busy=false;ready=false;paint({status:'failed',error:e?.message||'The export request failed. Check your connection and try again.'});};
    const poll=async(resuming=false)=>{
      if(current!==view||!jobId)return;
      const expectedStyle=terrain.value;
      try{const job=await window.sweeplineRequest('/api/beamng/'+jobId);if(current!==view||expectedStyle!==terrain.value)return;if(resuming&&!['queued','running','failed'].includes(job.status)){try{window.sessionStorage?.removeItem(storageKey);}catch(_){}return;}if(paint(job))timer=setTimeout(poll,3000);}
      catch(e){if(current===view)fail(e);}
    };
    action.onclick=async event=>{
      if(ready&&(!expires||Date.now()<expires))return;
      event.preventDefault();if(busy)return;clearTimeout(timer);paint({status:'queued',progress:'Queueing map generation…'});
      try{
        const payload={terrain_style:terrain.value,padding_miles:0.5,resolution:2048,orientation:context.orientation||'forward',...(context.preferredName?{preferred_name:context.preferredName}:{})};
        if(context.favoriteId)payload.favorite_id=context.favoriteId;
        else Object.assign(payload,{road_id:road.id,profile:context.profile||'twisty',...((road.member_ids||[]).length>1?{member_ids:road.member_ids}:{})});
        const job=await window.sweeplineRequest('/api/beamng',{method:'POST',body:JSON.stringify(payload)});
        if(current!==view)return;jobId=job.id;
        try{window.sessionStorage?.setItem(storageKey,JSON.stringify({id:jobId}));}catch(_){}
        if(paint(job))timer=setTimeout(poll,3000);
      }catch(e){if(current===view)fail(e);}
    };
    cancel.onclick=async()=>{
      cancel.disabled=true;
      try{await window.sweeplineRequest('/api/beamng/'+jobId+'/cancel',{method:'POST',body:'{}'});await poll();}
      catch(e){if(current===view)status.textContent=e.message;}finally{cancel.disabled=false;}
    };
    terrain.onchange=()=>{
      if(busy)return;clearTimeout(timer);jobId=null;storageKey=baseStorageKey+':'+terrain.value;noticeKey=storageKey+':notice';
      try{window.sessionStorage?.setItem('sweepline:beamng:terrain:'+user,terrain.value);}catch(_){}
      paint({status:'idle',progress:'Terrain style changed. Generate a fresh export.'});
    };
    try{const notice=window.sessionStorage?.getItem(noticeKey);if(notice)paint({status:'failed',error:notice});}catch(_){}
    try{const saved=JSON.parse(window.sessionStorage?.getItem(storageKey)||'null');if(saved&&/^[a-f0-9]{32}$/.test(saved.id)){jobId=saved.id;poll(true);}}catch(_){}
  };
})();
