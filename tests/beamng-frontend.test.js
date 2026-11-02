async function testBeamControls(source){
  let checks=0;const check=(x,m)=>{if(!x)throw Error(m);checks++;};
  class Element{constructor(tag){this.tag=tag;this.children=[];this.attrs={};this.classList={toggle:(key,on)=>{this[key]=on;}};}append(...n){this.children.push(...n);}setAttribute(k,v){this.attrs[k]=v;}removeAttribute(k){delete this.attrs[k];}insertAdjacentElement(_,n){this.status=n;}}
  const requests=[],tasks=[],window={SweepLineAccount:{user:{id:1}},sessionStorage:{getItem:()=>null,setItem:()=>{}},sweeplineRequest:async(path,options)=>{requests.push({path,options});return options?{id:"a".repeat(32),status:"queued",progress:"Queued"}:{status:"complete",download_url:"/private.zip",progress:"Ready"};}};
  new Function("window","document","setTimeout","clearTimeout",source)(window,{createElement:t=>new Element(t)},fn=>{tasks.push(fn);return tasks.length;},()=>{});
  const row=new Element("div");window.sweeplineBeamExport(row,{id:42});
  check(row.children.length===1&&row.children[0].tag==="a","Single inline export action");
  const action=row.children[0];await action.onclick({preventDefault(){}});
  const payload=JSON.parse(requests[0].options.body);check(payload.resolution===2048&&payload.padding_miles===0.5,"Fixed high-detail defaults");
  check(action.textContent.includes("Generating")&&action.attrs["aria-disabled"]==="true","Busy state visible");
  await tasks.shift()();check(action.textContent==="Download BeamNG"&&action.href==="/private.zip"&&action["beam-ready"],"Ready action becomes orange download");
  let prevented=false;await action.onclick({preventDefault(){prevented=true;}});check(!prevented&&requests.length===2,"Ready click uses native download without requeue");
  return checks;
}
if(typeof require==="function"&&require.main===module){testBeamControls(require("fs").readFileSync(require("path").join(__dirname,"../public/assets/beamng.js"),"utf8")).then(n=>console.log(n+" BeamNG frontend checks passed")).catch(e=>{console.error(e);process.exitCode=1;});}
