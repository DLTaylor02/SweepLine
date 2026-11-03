'use strict';
(() => {
  const steps=['Queued','Elevation','Aerial imagery','Preparation','Terrain analysis','Road shaping','Materials','Scenery','Packaging'];
  function stageFor(job){
    if(job.status==='complete')return steps.length;
    const message=job.progress||'';
    const stages=[
      [/Finalizing.*ZIP/i,8],[/Placing regional scenery/i,7],
      [/Writing collision|Blending bounded aerial/i,6],
      [/Shaping the road|Checking and removing terrain/i,5],
      [/Classifying grass/i,4],
      [/Preparing terrain|Validating elevation|building/i,3],
      [/NAIP|aerial|Aligning aerial/i,2],[/USGS elevation|Aligning dem/i,1]
    ];
    return stages.find(([pattern])=>pattern.test(message))?.[1]??0;
  }

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
    const progress=node('div');progress.className='beam-steps';progress.hidden=true;
    const stepLabel=node('div');stepLabel.className='beam-step-label';
    const track=node('div');track.className='beam-step-track';track.setAttribute('role','img');
    const segments=steps.map(label=>{const segment=node('span');segment.title=label;segment.setAttribute('aria-hidden','true');track.append(segment);return segment;});
    progress.append(stepLabel,track);
    section.append(spinner,status,cancel,progress);container.append(action,section);
    const user=window.SweepLineAccount?.user?.id;
    const baseStorageKey='sweepline:beamng:'+user+':'+(road.geometry_key||road.id)+':'+(context.orientation||'forward')+':'+(context.preferredName||road.name||'');
    const storageKey=baseStorageKey+':stock',noticeKey=storageKey+':notice';
    let jobId=null,busy=false,ready=false,expires=0;
    const paint=job=>{
      if(!['queued','running'].includes(job.status)){try{window.sessionStorage?.removeItem(storageKey);}catch(_){}}
      busy=['queued','running'].includes(job.status);
      ready=job.status==='complete'&&!!job.download_url;
      expires=job.expires_at?Date.parse(job.expires_at):0;
      action.textContent=ready?'Download BeamNG':busy?'Generating BeamNG…':'Generate BeamNG';
      action.classList.toggle('beam-ready',ready);action.setAttribute('aria-disabled',String(busy));
      action.href=ready?job.download_url:'#';
      if(ready)action.setAttribute('download','');else action.removeAttribute('download');
      const failed=job.status==='failed';
      section.hidden=false;section.classList.toggle('beam-error',failed);
      cancel.hidden=!busy;spinner.hidden=!busy;
      progress.hidden=!busy&&!ready;
      const stage=stageFor(job),complete=stage===steps.length;
      stepLabel.textContent=complete?'All export steps complete':`Step ${stage+1} of ${steps.length} · ${steps[stage]}`;
      track.setAttribute('aria-label',stepLabel.textContent+'. Steps indicate stages, not elapsed time or percentage.');
      segments.forEach((segment,index)=>{segment.classList.toggle('is-complete',complete||index<stage);segment.classList.toggle('is-current',!complete&&index===stage);});

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
      try{const job=await window.sweeplineRequest('/api/beamng/'+jobId);if(current!==view)return;if(resuming&&!['queued','running','failed'].includes(job.status)){try{window.sessionStorage?.removeItem(storageKey);}catch(_){}return;}if(paint(job))timer=setTimeout(poll,3000);}
      catch(e){if(current===view)fail(e);}
    };
    action.onclick=async event=>{
      if(ready&&(!expires||Date.now()<expires))return;
      event.preventDefault();if(busy)return;clearTimeout(timer);paint({status:'queued',progress:'Queueing map generation…'});
      try{
        const payload={terrain_style:'stock',padding_miles:0.5,resolution:2048,orientation:context.orientation||'forward',...(context.preferredName?{preferred_name:context.preferredName}:{})};
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
    try{const notice=window.sessionStorage?.getItem(noticeKey);if(notice)paint({status:'failed',error:notice});}catch(_){}
    try{const saved=JSON.parse(window.sessionStorage?.getItem(storageKey)||'null');if(saved&&/^[a-f0-9]{32}$/.test(saved.id)){jobId=saved.id;poll(true);}}catch(_){}
  };
})();
