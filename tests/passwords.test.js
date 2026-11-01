'use strict';
function testPasswords(source) {
  const nodes=[];
  class Element {
    constructor(){this.dataset={};this.value='';this.children=[];}
    append(...n){this.children.push(...n);} after(n){nodes.push(n);}
    setAttribute(k,v){this[k]=v;} setCustomValidity(v){this.validity=v;}
  }
  const window={},document={createElement:()=>new Element()};
  const Encoder=globalThis.TextEncoder || class {encode(s){return {length:unescape(encodeURIComponent(s)).length};}};
  new Function('window','document','TextEncoder',source)(window,document,Encoder);
  const {evaluate,attach}=window.SweepLinePasswords;let checks=0;
  const check=(ok,m)=>{checks++;if(!ok)throw new Error(m);};
  const policy={minimum_length:12,strength:'standard'};
  check(evaluate('abcdefghijkl',{...policy,strength:'basic'}).satisfied,'Basic length only');
  check(!evaluate('Ab1!',policy).satisfied,'Minimum length enforced');
  for(const p of ['Abcdefghijk1','abcdefghij1!','ABCDEFGHIJ1!','Abcdefghijk!'])check(evaluate(p,policy).satisfied,'Any three types');
  for(const p of ['Abcdefghijk1','abcdefghij1!','ABCDEFGHIJ1!','Abcdefghijk!'])check(!evaluate(p,{...policy,strength:'strong'}).satisfied,'Each Strong type required');
  check(evaluate('Abcdefghij1!',{...policy,strength:'strong'}).level==='strong','Strong meets policy');
  check(evaluate('A1!',policy).level!=='strong','Never label insufficient password Strong');
  check(evaluate('x',policy,true).satisfied,'SuperAdmin exemption reflected');
  check(!evaluate('é'.repeat(37),{minimum_length:1,strength:'basic'}).satisfied,'UTF-8 byte cap');
  const input=new Element();attach(input,policy);input.value='short';input.oninput();
  check(input.dataset.minimumLength==='12'&&!!input.validity,'Data attributes and custom validity');
  input.value='Abcdefghij1!';input.oninput();
  check(!input.validity&&input._passwordMeter.message['aria-live']==='polite','Accessible live status');
  check(input._passwordMeter.box.dataset.level==='strong','Theme state tracks strength');
  attach(input,{minimum_length:30,strength:'strong'});
  check(!!input.validity&&input._passwordMeter.box.dataset.level!=='strong','Policy refresh updates existing meter');
  return checks;
}
if(typeof module!=='undefined') {
  module.exports=testPasswords;
  if(require.main===module)console.log(testPasswords(require('node:fs').readFileSync(require('node:path').join(__dirname,'../public/assets/passwords.js'),'utf8'))+' password UI checks passed');
}

