'use strict';
(() => {
  function evaluate(value,policy,exempt=false) {
    const count=Array.from(value).length;
    const types=[/[A-Z]/,/[a-z]/,/[0-9]/,/[^A-Za-z0-9]/u].filter(re=>re.test(value)).length;
    const bytes=new TextEncoder().encode(value).length;
    const minimum=exempt?1:Number(policy.minimum_length);
    const needed=exempt?0:({basic:0,standard:3,strong:4}[policy.strength]??4);
    const satisfied=count>=minimum && types>=needed && bytes<=72 && !value.includes('\0');
    const level=!satisfied?(count>=minimum?'fair':'weak'):(types===4?'strong':'good');
    return {count,types,minimum,satisfied,level};
  }
  function attach(input,policy,exempt=false) {
    if(!input)return;
    input.dataset.minimumLength=String(exempt?1:policy.minimum_length);
    input.dataset.strength=exempt?'basic':policy.strength;
    input.minLength=exempt?1:policy.minimum_length;input.maxLength=72;
    let meter=input._passwordMeter;
    if(!meter) {
      const box=document.createElement('div');box.className='password-meter';
      const track=document.createElement('div');track.className='password-track';
      const bar=document.createElement('span');track.append(bar);
      const message=document.createElement('p');message.setAttribute('role','status');message.setAttribute('aria-live','polite');
      box.append(track,message);input.after(box);meter={box,bar,message};input._passwordMeter=meter;
    }
    const update=()=>{
      const result=evaluate(input.value,policy,exempt);
      meter.box.dataset.level=result.level;
      meter.message.textContent=(exempt?'SuperAdmin exempt. ':'')+
        result.count+' / '+result.minimum+' characters; '+result.types+' / 4 character types. '+
        (result.satisfied?'Policy satisfied.':'Policy not yet satisfied.')+
        ' '+result.level[0].toUpperCase()+result.level.slice(1)+'. Maximum 72 UTF-8 bytes.';
      input.setCustomValidity(result.satisfied || !input.value?'':'Password does not yet meet the displayed requirements.');
    };
    input.oninput=update;update();
  }
  window.SweepLinePasswords={evaluate,attach};
})();

