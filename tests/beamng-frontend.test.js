async function testBeamControls(source){
  let checks=0;const check=(x,m)=>{if(!x)throw Error(m);checks++;};
  class Element{constructor(tag){this.tag=tag;this.children=[];this.attrs={};this.classList={toggle:(key,on)=>{this[key]=on;}};}append(...n){this.children.push(...n);}setAttribute(k,v){this.attrs[k]=v;}removeAttribute(k){delete this.attrs[k];}insertAdjacentElement(_,n){this.status=n;}}
  const requests=[],tasks=[],window={SweepLineAccount:{user:{id:1}},sessionStorage:{getItem:()=>null,setItem:()=>{}},sweeplineRequest:async(path,options)=>{requests.push({path,options});return options?{id:"a".repeat(32),status:"queued",progress:"Queued"}:{status:"complete",download_url:"/private.zip",progress:"Ready"};}};
  new Function("window","document","setTimeout","clearTimeout",source)(window,{createElement:t=>new Element(t)},fn=>{tasks.push(fn);return tasks.length;},()=>{});
  const row=new Element("div");window.sweeplineBeamExport(row,{id:42},{orientation:"reverse",preferredName:"Chosen Alias"});
  check(row.children.length===2&&row.children[0].tag==="a","Single inline export action");
  const action=row.children[0];await action.onclick({preventDefault(){}});
  const payload=JSON.parse(requests[0].options.body);check(payload.resolution===2048&&payload.padding_miles===0.5&&payload.orientation==="reverse"&&payload.preferred_name==="Chosen Alias"&&payload.terrain_style==="stock","Fixed high-detail defaults");
  check(action.textContent.includes("Generating")&&action.attrs["aria-disabled"]==="true","Busy state visible");

  const progress=row.children[1].children[3];
  check(!progress.hidden&&progress.children[0].textContent.includes('Step 1 of 9')&&progress.children[1].children.length===9,'Queued export shows nine stage segments without a percentage');

  await tasks.shift()();check(action.textContent==="Download BeamNG"&&action.href==="/private.zip"&&action["beam-ready"],"Ready action becomes orange download");

  check(progress.children[0].textContent==='All export steps complete'&&progress.children[1].children.every(n=>n['is-complete']),'Completed download fills every stage');

  let prevented=false;await action.onclick({preventDefault(){prevented=true;}});check(!prevented&&requests.length===2,"Ready click uses native download without requeue");
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

  check(errorBox.children[3].hidden,'Rejected export hides active progress while keeping its error');
  const messages=['USGS elevation: provider temporarily unavailable; retrying','Aligning aerial raster to the square map','Preparing terrain from original 1024 elevation samples','Classifying grass, forest floor','Checking and removing terrain intersections beneath the road','Blending bounded aerial colors into stock terrain materials','Placing regional scenery clear of the driving corridor','Finalizing the map ZIP'];
  let stageIndex=0;tasks.length=0;
  window.sweeplineRequest=async(path,options)=>options?{id:'c'.repeat(32),status:'queued',progress:'Queued'}:stageIndex<messages.length?{status:'running',progress:messages[stageIndex++]}:{status:'complete',download_url:'/staged.zip',progress:'Ready'};
  const staged=new Element('div');window.sweeplineBeamExport(staged,{id:92});await staged.children[0].onclick({preventDefault(){}});
  const stageProgress=staged.children[1].children[3];
  for(let index=0;index<messages.length;index++){
    await tasks.shift()();
    check(stageProgress.children[0].textContent.includes('Step '+(index+2)+' of 9')&&stageProgress.children[1].children[index+1]['is-current'],'Worker stage '+messages[index]+' selects its actual segment');
  }
  await tasks.shift()();check(stageProgress.children[1].children.every(n=>n['is-complete']),'Packaging completes only when the worker reports complete');


  let customPayload;
  window.sweeplineRequest=async(path,options)=>{customPayload=JSON.parse(options.body);return {id:'d'.repeat(32),status:'queued',progress:'Queued'};};
  const customRow=new Element('div');window.sweeplineBeamExport(customRow,{id:42},{customId:55,orientation:'reverse'});
  await customRow.children[0].onclick({preventDefault(){}});
  check(customPayload.custom_id===55&&!('road_id' in customPayload),'BeamNG custom selections never request the full candidate geometry');


  tasks.length=0;let regressionIndex=0;
  const regressionMessages=['Classifying grass, forest floor','Downloading 0.1 MB','Preparing intersecting road context','Shaping the road corridor'];
  window.sweeplineRequest=async(path,options)=>options?{id:'e'.repeat(32),status:'queued',progress:'Queued'}:{status:'running',progress:regressionMessages[regressionIndex++]};
  const regressionRow=new Element('div');window.sweeplineBeamExport(regressionRow,{id:94});await regressionRow.children[0].onclick({preventDefault(){}});
  const regressionLabel=regressionRow.children[1].children[3].children[0];
  await tasks.shift()();check(regressionLabel.textContent.includes('Step 5 of 9'),'Terrain classification reaches analysis stage');
  await tasks.shift()();check(regressionLabel.textContent.includes('Step 5 of 9'),'Unrecognized download status does not reset the stage bar');
  await tasks.shift()();check(regressionLabel.textContent.includes('Step 5 of 9'),'Repeated preparation status cannot move progress backwards');
  await tasks.shift()();check(regressionLabel.textContent.includes('Step 6 of 9'),'A later worker phase still advances the monotonic progress bar');

  return checks;
}
if(typeof require==="function"&&require.main===module){testBeamControls(require("fs").readFileSync(require("path").join(__dirname,"../public/assets/beamng.js"),"utf8")).then(n=>console.log(n+" BeamNG frontend checks passed")).catch(e=>{console.error(e);process.exitCode=1;});}
