'use strict';
(() => {
  const el=id=>document.getElementById(id);
  let session={user:null,csrf:null,admin_ready:false};
  let adminTimer;
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
    el('workspace').hidden=!signed;el('account-gate').hidden=signed;
    el('sign-out').hidden=!signed;el('admin-open').hidden=session.user?.role!=='superadmin';
    el('account-name').textContent=signed?session.user.name:'';
    if(!signed) {el('admin-panel').hidden=true;clearTimeout(adminTimer);}
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
  const text=(tag,value)=>{const node=document.createElement(tag);node.textContent=value;return node;};
  async function updateUser(id,payload) {
    await window.sweeplineRequest('/api/admin/users/'+id,{method:'PATCH',body:JSON.stringify(payload)});
  }
  async function refreshAdmin() {
    clearTimeout(adminTimer);
    if(el('admin-panel').hidden || session.user?.role!=='superadmin')return;
    if(el('user-list').contains(document.activeElement)) { adminTimer=setTimeout(refreshAdmin,15000);return; }
    try {
      const [users,data]=await Promise.all([window.sweeplineRequest('/api/admin/users'),window.sweeplineRequest('/api/admin/data')]);
      el('user-list').replaceChildren();
      users.forEach(user=>{
        const row=text('div','');row.className='user-row';
        row.append(text('strong',user.name),text('p',user.username+(user.email?' · '+user.email:'')+' · '+user.role+' · '+(user.active?'Active':'Disabled')));
        if(Number(user.id)!==Number(session.user.id)) {
          const toggle=text('button',user.active?'Disable':'Enable');toggle.type='button';
          toggle.onclick=async()=>{try{await updateUser(user.id,{active:!user.active});await refreshAdmin();}catch(e){el('admin-status').textContent=e.message;}};
          row.append(toggle);
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
      el('worker-status').textContent='Worker last seen: '+(data.worker_heartbeat || 'waiting for startup')+
        '. ZIP coordinates updated: '+(data.zip_last_sync || 'preparing automatically')+'.';
      el('task-list').replaceChildren();
      data.tasks.forEach(task=>{
        const row=text('div','');row.className='task-row';
        row.append(text('strong',task.kind==='zips'?'US ZIP coordinates':task.task_key),
          text('p',task.status+' · '+task.progress));
        if(task.error)row.append(text('p',task.error));
        if(task.status==='failed') {
          const retry=text('button','Retry');retry.type='button';retry.onclick=async()=>{
            try {await window.sweeplineRequest('/api/admin/tasks/'+task.id+'/retry',{method:'POST',body:'{}'});await refreshAdmin();}
            catch(e){el('admin-status').textContent=e.message;}
          };row.append(retry);
        }
        el('task-list').append(row);
      });
    } catch(e) {el('admin-status').textContent=e.message;}
    if(!el('admin-panel').hidden)adminTimer=setTimeout(refreshAdmin,15000);
  }
  el('admin-open').onclick=()=>{el('admin-panel').hidden=false;refreshAdmin();el('admin-panel').scrollIntoView({behavior:'smooth'});};
  el('admin-close').onclick=()=>{el('admin-panel').hidden=true;clearTimeout(adminTimer);};
  el('admin-refresh').onclick=refreshAdmin;
  el('create-user').onsubmit=async event=>{
    event.preventDefault();const form=event.target;
    try {
      await window.sweeplineRequest('/api/admin/users',{method:'POST',body:JSON.stringify({username:form.username.value,name:form.elements.namedItem('name').value,email:form.email.value,password:form.password.value})});
      form.reset();el('admin-status').textContent='User created.';await refreshAdmin();
    } catch(e) {el('admin-status').textContent=e.message;}
  };
  initialize();
})();
