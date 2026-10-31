'use strict';
(() => {
  const el=id=>document.getElementById(id);
  let session={user:null,csrf:null,admin_ready:false};
  let adminTimer;
  const jobsPage=window.location?.pathname==='/manage';
  const managePage=window.location?.pathname==='/users';
  const historyPage=window.location?.pathname==='/history';
  const favoritesPage=window.location?.pathname==='/favorites';
  const adminPage=jobsPage || managePage;
  window.SweepLineAccount=session;
  window.sweeplineRequest=async (path,options={}) => {
    const method=options.method || 'GET';
    const headers={...(options.headers || {})};
    if(method!=='GET') { headers['Content-Type']='application/json';headers['X-CSRF-Token']=session.csrf || ''; }
    const response=await fetch(path,{...options,headers,credentials:'same-origin'});
    let data;
    try { data=await response.json(); } catch (_) { throw new Error('Server response unavailable. Please try again.'); }
    if(!response.ok) {
      if(response.status===401 && path!=='/api/login') { session.user=null;paint(); }
      const error=new Error(data.error || 'Request failed');error.status=response.status;throw error;
    }
    return data;
  };
  function paint() {
    const signed=!!session.user;
    el('workspace').hidden=!signed || adminPage || historyPage || favoritesPage;el('account-gate').hidden=signed;
    el('sign-out').hidden=!signed;el('admin-open').hidden=!['admin','superadmin'].includes(session.user?.role);
    el('jobs-open').hidden=session.user?.role!=='superadmin';
    el('history-open').hidden=!signed;el('history-panel').hidden=!signed || !historyPage;
    if(historyPage && signed)refreshHistory();
    el('favorites-open').hidden=!signed;el('favorites-panel').hidden=!signed || !favoritesPage;
    if(favoritesPage && signed)refreshFavorites();
    el('admin-panel').hidden=!signed || !adminPage;
    if(adminPage && signed) {
      el('admin-panel').hidden=false;el('admin-users').hidden=jobsPage;
      el('admin-jobs').hidden=managePage;
      el('admin-page-title').textContent=jobsPage?'Manage':'Users';
      el('admin-panel').className='admin-panel jobs-page';
      el('create-role-label').hidden=session.user.role!=='superadmin';
      if(session.user.role==='superadmin' || (managePage && session.user.role==='admin'))refreshAdmin();
      else {el('admin-jobs').hidden=true;el('admin-users').hidden=true;el('admin-status').textContent='SuperAdmin access required.';}
    }
    el('account-name').textContent=signed?session.user.name:'';
    if(!signed) {el('favorites-list').replaceChildren();el('history-list').replaceChildren();el('admin-panel').hidden=true;clearTimeout(adminTimer);}
    el('account-form').hidden=signed || !session.admin_ready;
    el('account-title').textContent='Sign in';
    el('account-submit').textContent='Sign in';
    el('account-intro').textContent=session.admin_ready?
      'Sign in with your username and password. Your web account is separate from the Linux service account.':
      'The SuperAdmin account has not been initialized. Complete installation in the server terminal.';
    el('account-retry').hidden=session.admin_ready || signed;
    window.SweepLineAccount=session;
    window.dispatchEvent(new CustomEvent('sweepline:sessionchange',{detail:session}));
  }
  async function initialize() {
    el('account-retry').hidden=true;
    try { session=await window.sweeplineRequest('/api/session');el('account-status').textContent='';paint(); }
    catch(e) {el('account-status').textContent=e.message;el('account-retry').hidden=false;}
  }
  el('account-retry').onclick=initialize;
  el('account-form').onsubmit=async event=>{
    event.preventDefault();const form=event.target;el('account-submit').disabled=true;
    try {
      session=await window.sweeplineRequest('/api/login',
        {method:'POST',body:JSON.stringify({username:form.username.value,password:form.password.value})});
      form.reset();el('account-status').textContent='';paint();
    } catch(e) {el('account-status').textContent=e.message;}
    finally {el('account-submit').disabled=false;}
  };
  el('sign-out').onclick=async ()=>{
    try {session=await window.sweeplineRequest('/api/logout',{method:'POST',body:'{}'});paint();}
    catch(e) {el('status').textContent=e.message;}
  };
  const surfaceLabel=p=>({known_paved:'Confirmed paved only',exclude_unpaved:'Allow unknown; exclude unpaved',any:'All surfaces'}[p.surface_filter??(p.paved_only===true?'known_paved':p.paved_only===false?'any':'exclude_unpaved')]);
  const text=(tag,value)=>{const node=document.createElement(tag);node.textContent=value;return node;};
  function thumbnail(road,id) {
    const image=document.createElement('img');
    image.src='/api/favorites/'+id+'/thumbnail';image.alt=road.name+' on a geographic street basemap';
    image.loading='lazy';image.decoding='async';image.width=532;image.height=280;
    return image;
  }
  async function refreshFavorites() {
    const userId=session.user.id;
    el('favorites-status').textContent='Loading favorites…';
    try {
      const items=await window.sweeplineRequest('/api/favorites');
      if(session.user?.id!==userId)return;
      el('favorites-list').replaceChildren();el('favorites-status').textContent=items.length?items.length+' saved roads':'No favorites yet. Open a road result and select its heart to save it.';
      items.forEach(item=>{
        const road=item.road,card=text('article','');card.className='favorite-card';
        const link=text('a','');link.href='/?favorite='+item.id;link.className='favorite-link';
        link.append(thumbnail(road,item.id),text('h3',road.name),text('p',`${road.score}/100 · ${(road.length_m/1609.344).toFixed(1)} mi · ${item.profile==='twisty'?'Twisty Roads':'Open Highways'}`),
          text('p',`${Math.max(1,Math.min(10,road.metrics.curve_density_score??(1+9*road.metrics.curve_density/53.6448))).toFixed(1)}/10 curve density · ${road.mandatory_stops} mapped stops + ${road.uncertain_controls} uncertain`),
          text('p',`${road.speed_mph==null?'Speed unknown':road.speed_mph.toFixed(0)+' mph minimum posted'} · ${Math.round(road.confidence*100)}% attribute completeness`));
        const remove=text('button','Remove favorite');remove.type='button';
        remove.onclick=async()=>{remove.disabled=true;try{await window.sweeplineRequest('/api/favorites/'+item.id,{method:'DELETE'});await refreshFavorites();}catch(e){el('favorites-status').textContent=e.message;remove.disabled=false;}};
        card.append(link,remove);el('favorites-list').append(card);
      });
    } catch(e){el('favorites-status').textContent=e.message;}
  }
  async function refreshHistory() {
    el('history-status').textContent='Loading previous searches…';
    try {
      const searches=await window.sweeplineRequest('/api/search-history');
      if(!session.user)return;
      el('history-list').replaceChildren();
      el('history-status').textContent=searches.length?'':'No previous searches yet. Searches you run from now on will appear here.';
      searches.forEach(item=>{
        const p=item.parameters,row=text('div','');row.className='history-row';
        const title=text('a',p.zip+' · '+p.radius_miles+' miles · '+(p.profile==='twisty'?'Twisty Roads':'Open Highways'));
        row.append(title,text('p',new Date(item.created_at).toLocaleString()),
          text('p',(p.profile==='twisty'?'Minimum curve density: '+(p.min_curve_density_score??(p.min_curve_density==null?2.2:1+9*p.min_curve_density/53.6448)).toFixed(1)+'/10 · ':'')+`Length: ${p.min_length_miles>0?'over '+p.min_length_miles:'no minimum'} to ${p.max_length_miles??'no maximum'} miles · Stops: ${p.max_stops} · ${surfaceLabel(p)}`));
        const again=text('a','View results');again.className='page-back';
        again.href='/?open=1&'+new URLSearchParams(Object.entries(p).filter(([,v])=>v!==null)).toString();
        title.href=again.href;
        const remove=text('button','Delete search');remove.type='button';
        remove.onclick=async()=>{
          remove.disabled=true;
          try {
            await window.sweeplineRequest('/api/search-history/'+item.id,{method:'DELETE'});
            await refreshHistory();
          } catch(e) {el('history-status').textContent=e.message;remove.disabled=false;}
        };
        row.append(again,remove);el('history-list').append(row);
      });
    } catch(e) {el('history-status').textContent=e.message;}
  }
  async function updateUser(id,payload) {
    await window.sweeplineRequest('/api/admin/users/'+id,{method:'PATCH',body:JSON.stringify(payload)});
  }
  function renderStorage(storage) {
    const pie=el('storage-pie'),legend=el('storage-legend');
    pie.hidden=true;legend.replaceChildren();
    if(!storage){el('storage-note').textContent='Storage reporting unavailable.';return;}
    const size=value=>{
      const units=['B','KiB','MiB','GiB','TiB','PiB'];let n=value,index=0;
      while(n>=1024&&index<units.length-1){n/=1024;index++;}
      return n.toFixed(index?1:0)+' '+units[index];
    };
    const slices=storage.available?[
      ['SweepLine database',storage.database_bytes,'#2563a6'],
      ['Other used space',storage.other_bytes,'#f08a38'],
      ['Free space',storage.free_bytes,'#94a3b8']
    ]:[['SweepLine database',storage.database_bytes,'#2563a6']];
    const labels=[];
    slices.forEach(([label,bytes,color])=>{
      const row=text('li',''),swatch=text('span','');swatch.className='storage-swatch';swatch.style.background=color;
      const percentage=storage.available?' ('+(bytes/storage.total_bytes*100).toFixed(1)+'%)':'';
      const description=label+': '+size(bytes)+percentage;labels.push(description);
      row.append(swatch,text('span',description));legend.append(row);
    });
    el('storage-note').textContent=storage.message+(storage.available?' Volumes: '+storage.volumes.map(v=>v.mount).join(', ')+'. Total: '+size(storage.total_bytes)+'.':'');
    if(storage.available) {
      const db=storage.database_bytes/storage.total_bytes*100;
      const used=(storage.database_bytes+storage.other_bytes)/storage.total_bytes*100;
      pie.style.background=`conic-gradient(#2563a6 0% ${db}%, #f08a38 ${db}% ${used}%, #94a3b8 ${used}% 100%)`;
      pie.setAttribute('aria-label',labels.join('; '));pie.hidden=false;
    }
  }
  async function refreshAdmin() {
    clearTimeout(adminTimer);
    if(el('admin-panel').hidden || !(session.user?.role==='superadmin' || (managePage && session.user?.role==='admin')))return;
    if(el('user-list').contains(document.activeElement)) { adminTimer=setTimeout(refreshAdmin,15000);return; }
    try {
      const users=managePage?await window.sweeplineRequest('/api/admin/users'):[];
      const data=jobsPage?await window.sweeplineRequest('/api/admin/data'):null;
      el('user-list').replaceChildren();
      users.forEach(user=>{
        const row=text('div','');row.className='user-row';
        row.append(text('strong',user.name),text('p',user.username+(user.email?' · '+user.email:'')+' · '+(user.role==='member'?'User':user.role==='admin'?'Admin':'SuperAdmin')+' · '+(user.active?'Active':'Disabled')));
        if(session.user.role!=='superadmin' && user.role!=='member') {el('user-list').append(row);return;}
        if(Number(user.id)!==Number(session.user.id)) {
          const toggle=text('button',user.active?'Disable':'Enable');toggle.type='button';
          toggle.onclick=async()=>{try{await updateUser(user.id,{active:!user.active});await refreshAdmin();}catch(e){el('admin-status').textContent=e.message;}};
          row.append(toggle);
        }
        if(session.user.role==='superadmin' && user.role!=='superadmin') {
          const role=document.createElement('select');role.setAttribute('aria-label','Role for '+user.username);
          for(const [value,label] of [['member','User'],['admin','Admin']]) {const option=text('option',label);option.value=value;role.append(option);}
          role.value=user.role;
          const saveRole=text('button','Save role');saveRole.type='button';
          saveRole.onclick=async()=>{try {await updateUser(user.id,{role:role.value});await refreshAdmin();}catch(e){el('admin-status').textContent=e.message;}};
          row.append(role,saveRole);
        }
        const password=document.createElement('input');password.type='password';password.autocomplete='new-password';
        password.placeholder='New password (12–72 bytes)';password.setAttribute('aria-label','New password for '+user.username);
        const reset=text('button','Reset password');reset.type='button';
        reset.onclick=async()=>{
          try {
            await updateUser(user.id,{password:password.value});password.value='';
            el('admin-status').textContent='Password updated. Existing sessions have been invalidated.';
            if(Number(user.id)===Number(session.user.id))await initialize();
          } catch(e) {el('admin-status').textContent=e.message;}
        };
        row.append(password,reset);el('user-list').append(row);
      });
      if(data) {
      renderStorage(data.storage);
      if(data.settings && !el('database-budget').dataset.loaded) {
        el('database-budget').value=String(data.settings.database_budget_mb);
        el('database-budget').dataset.loaded='true';
      }
      el('worker-status').textContent='Worker last seen: '+(data.worker_heartbeat || 'waiting for startup')+
        '. ZIP coordinates updated: '+(data.zip_last_sync || 'preparing automatically')+'.';
      el('task-list').replaceChildren();
      el('job-counts').textContent=(data.counts || []).map(item=>item.count+' '+item.status).join(' · ');
      const jobs=[...data.tasks.map(t=>({...t,source:'tasks'})),...(data.imports || []).map(t=>({...t,task_key:t.name,source:'imports'}))];
      jobs.filter(task=>task.status!=='cancelled'&&!task.cancel_requested).forEach(task=>{
        const row=text('div','');row.className='task-row';
        row.append(text('strong',task.kind==='zips'?'US ZIP coordinates':task.task_key),
          text('p',(task.cancel_requested && task.status==='running'?'Cancelling':task.cancel_requested && task.status==='failed'?'cancelled':task.status)+' · '+task.progress));
        if(task.error)row.append(text('p',task.error));
        if(task.source==='tasks' && task.status==='failed') {
          const retry=text('button','Retry');retry.type='button';retry.onclick=async()=>{
            try {await window.sweeplineRequest('/api/admin/tasks/'+task.id+'/retry',{method:'POST',body:'{}'});await refreshAdmin();}
            catch(e){el('admin-status').textContent=e.message;}
          };row.append(retry);
        }
        if(['queued','running','retry'].includes(task.status) && !task.cancel_requested) {
          const cancel=text('button','Cancel job');cancel.type='button';
          cancel.onclick=async()=>{
            cancel.disabled=true;
            try {
              await window.sweeplineRequest('/api/admin/'+task.source+'/'+task.id+'/cancel',{method:'POST',body:'{}'});
              el('admin-status').textContent='Job removed from the queue. Running work stops at its next checkpoint.';
              await refreshAdmin();
            } catch(e) {el('admin-status').textContent=e.message;cancel.disabled=false;}
          };row.append(cancel);
        }
        el('task-list').append(row);
      });
      }
    } catch(e) {el('admin-status').textContent=e.message;}
    if(!el('admin-panel').hidden)adminTimer=setTimeout(refreshAdmin,15000);
  }
  el('storage-settings').onsubmit=async event=>{
    event.preventDefault();el('storage-save').disabled=true;
    try {
      const result=await window.sweeplineRequest('/api/admin/storage',{method:'PATCH',body:JSON.stringify({database_budget_mb:Number(el('database-budget').value)})});
      el('database-budget').value=String(result.database_budget_mb);
      el('storage-settings-status').textContent='Storage limit saved. '+result.resumed_jobs+' blocked jobs queued.';
      await refreshAdmin();
    } catch(e) {el('storage-settings-status').textContent=e.message;}
    finally {el('storage-save').disabled=false;}
  };
  el('admin-refresh').onclick=refreshAdmin;
  el('create-user').onsubmit=async event=>{
    event.preventDefault();const form=event.target;
    try {
      await window.sweeplineRequest('/api/admin/users',{method:'POST',body:JSON.stringify({username:form.username.value,name:form.elements.namedItem('name').value,email:form.email.value,password:form.password.value,role:session.user.role==='superadmin'?(el('create-role').value || 'member'):'member'})});
      form.reset();el('admin-status').textContent='User created.';await refreshAdmin();
    } catch(e) {el('admin-status').textContent=e.message;}
  };
  initialize();
})();
