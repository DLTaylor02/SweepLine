async function testBeamControls(source){
  let checks=0;const check=(x,m)=>{if(!x)throw Error(m);checks++;};
  class Element{constructor(tag){this.tag=tag;this.children=[];this.attrs={};this.classList={toggle:(key,on)=>{this[key]=on;}};}append(...n){this.children.push(...n);}setAttribute(k,v){this.attrs[k]=v;}removeAttribute(k){delete this.attrs[k];}insertAdjacentElement(_,n){this.status=n;}}
  const requests=[],tasks=[],window={SweepLineAccount:{user:{id:1}},sessionStorage:{getItem:()=>null,setItem:()=>{}},sweeplineRequest:async(path,options)=>{requests.push({path,options});return options?{id:"a".repeat(32),status:"queued",progress:"Queued"}:{status:"complete",download_url:"/private.zip",progress:"Ready"};}};
  new Function("window","document","setTimeout","clearTimeout",source)(window,{createElement:t=>new Element(t)},fn=>{tasks.push(fn);return tasks.length;},()=>{});
  const row=new Element("div");window.sweeplineBeamExport(row,{id:42},{orientation:"reverse",preferredName:"Chosen Alias"});
  check(row.children.length===3&&row.children[0].tag==="a","Single inline export action");
  const action=row.children[0];await action.onclick({preventDefault(){}});
  const payload=JSON.parse(requests[0].options.body);check(payload.resolution===2048&&payload.padding_miles===0.5&&payload.orientation==="reverse"&&payload.preferred_name==="Chosen Alias"&&payload.terrain_style==="stock","Fixed high-detail defaults");
  check(action.textContent.includes("Generating")&&action.attrs["aria-disabled"]==="true","Busy state visible");
  await tasks.shift()();check(action.textContent==="Download BeamNG"&&action.href==="/private.zip"&&action["beam-ready"],"Ready action becomes orange download");
  let prevented=false;await action.onclick({preventDefault(){prevented=true;}});check(!prevented&&requests.length===2,"Ready click uses native download without requeue");
  const terrain=row.children[2].children[0];terrain.value="aerial";terrain.onchange();
  check(action.textContent==="Generate BeamNG"&&!action["beam-ready"],"Changing terrain style resets download to Generate");
  await action.onclick({preventDefault(){}});check(JSON.parse(requests.at(-1).options.body).terrain_style==="aerial","Aerial selection reaches export API");
  const notices=new Map();window.sessionStorage={getItem:k=>notices.get(k)||null,setItem:(k,v)=>notices.set(k,v),removeItem:k=>notices.delete(k)};
  window.sweeplineRequest=async()=>{throw new Error("This stretch contains a mapped bridge or tunnel.");};
  const rejected=new Element("div");window.sweeplineBeamExport(rejected,{id:91});await rejected.children[0].onclick({preventDefault(){}});
  const errorBox=rejected.children[1],errorText=errorBox.children[1];
  check(!errorBox.hidden&&errorBox["beam-error"]&&errorText.textContent.includes("mapped bridge or tunnel"),"Rejection reason remains visible in inline error panel");
  check(errorBox.children[0].hidden&&errorBox.children[2].hidden&&errorText.attrs.role==="alert","Failure hides spinner and cancellation and announces error");
  const reopened=new Element("div");window.sweeplineBeamExport(reopened,{id:91});check(!reopened.children[1].hidden&&reopened.children[1].children[1].textContent.includes("mapped bridge"),"Reopening road preserves rejection reason");
  window.sweeplineRequest=async(path,options)=>options?{id:"b".repeat(32),status:"queued",progress:"Queued"}:{status:"failed",error:"USGS elevation: provider unavailable"};
  tasks.length=0;await reopened.children[0].onclick({preventDefault(){}});check(!reopened.children[1]["beam-error"]&&reopened.children[1].children[1].textContent==="Queued","Retry clears old rejection and shows new progress");
  await tasks.shift()();check(reopened.children[1]["beam-error"]&&reopened.children[1].children[1].textContent.includes("USGS elevation"),"Background failure keeps provider reason visible");
  return checks;
}
if(typeof require==="function"&&require.main===module){testBeamControls(require("fs").readFileSync(require("path").join(__dirname,"../public/assets/beamng.js"),"utf8")).then(n=>console.log(n+" BeamNG frontend checks passed")).catch(e=>{console.error(e);process.exitCode=1;});}
