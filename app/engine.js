// Zmiano – silnik układania grafiku dla dowolnego lokalu.
// Dzień grafiku = obiekt {"<idStanowiska>#<nr>": idPracownika}; null = lokal zamknięty.
(function(global){
'use strict';

function slotsOf(positions){
  const out=[];
  positions.forEach(p=>{for(let k=0;k<p.count;k++)out.push({key:p.id+'#'+k,pos:p.id})});
  return out;
}
function parseRhythm(r){if(!r)return null;const [a,b]=r.split('+').map(Number);return{a,b}}

// efektywne ustawienia osoby: preferencje z miesiąca nadpisują ustawienia z panelu
function effective(staff,prefs,n,wday){
  const E={};
  staff.forEach(s=>{
    const p=prefs[s.id]||{};
    const has=p.daysMin!=null||p.daysMax!=null;
    const min=has?(p.daysMin??p.daysMax):s.min??null, max=has?(p.daysMax??p.daysMin):s.max??(s.min??null);
    const off=new Set(p.blocked||[]);
    for(let d=1;d<=n;d++)if((s.noWd||[]).includes(wday(d)))off.add(d);
    E[s.id]={min:min,max:max==null&&min!=null?min:max,rh:parseRhythm(p.rhythm),off,want:new Set(p.want||[]),picksOwn:!!s.picksOwn,pos:new Set(s.positions||[])};
  });
  return E;
}

function works(day,id){if(!day)return false;for(const k in day)if(day[k]===id)return true;return false}
function slotOf(day,id){if(!day)return null;for(const k in day)if(day[k]===id)return k.split('#')[0];return null}

function scorePerson(A,id,n,closed,fair,e,maxRun){
  let c=0,cnt=0;const w=new Array(n);
  for(let i=0;i<n;i++){
    w[i]=works(A[i],id);
    if(w[i]){cnt++;if(e.off.has(i+1))c+=1000;if(e.picksOwn&&!e.want.has(i+1))c+=1000}
    else if(e.want.has(i+1)&&!closed[i])c+=e.picksOwn?400:15;
  }
  if(e.min!=null){if(cnt<e.min)c+=40*(e.min-cnt);if(cnt>e.max)c+=40*(cnt-e.max)}
  else if(fair!=null)c+=4*Math.abs(cnt-fair);
  let i=0;
  while(i<n){
    let j=i;while(j<n&&w[j]===w[i])j++;
    const len=j-i,edge=(i===0||j===n);
    if(w[i]){
      if(len>maxRun)c+=2000*(len-maxRun);
      if(e.rh){if(len>e.rh.a)c+=8*(len-e.rh.a);else if(len<e.rh.a&&!edge)c+=3*(e.rh.a-len)}
    }else if(e.rh&&!edge){
      let off=0;for(let k=i;k<j;k++)if(!closed[k])off++;
      if(off<e.rh.b)c+=6*(e.rh.b-off);else if(off>e.rh.b)c+=off-e.rh.b;
    }
    i=j;
  }
  return c;
}

// kary dnia: puste stanowisko, ta sama osoba dwa razy, zasady par
function scoreDay(day,rules,slots){
  if(!day)return 0;
  let c=0;const seen={};
  for(const s of slots){const id=day[s.key];if(!id){c+=5000;continue}if(seen[id])c+=5000;seen[id]=1}
  for(const p of rules.pairs||[]){if(seen[p.a]&&seen[p.b]&&slotOf(day,p.b)!==p.bPos)c+=3000}
  for(const p of rules.apart||[]){if(seen[p.a]&&seen[p.b])c+=3000}
  return c;
}

/**
 * opts: {year, month0, positions, staff, prefs, rules, closed:Set(dni), base: tablica dni (dopasowanie z minimalną liczbą zmian) | null}
 */
function solve(o){
  const n=new Date(o.year,o.month0+1,0).getDate();
  const wday=d=>new Date(o.year,o.month0,d).getDay();
  const rules=o.rules||{},maxRun=rules.maxRun||5,openWd=rules.openWd||[0,1,2,3,4,5,6];
  const closed=Array.from({length:n},(_,i)=>o.closed.has(i+1)||!openWd.includes(wday(i+1)));
  const slots=slotsOf(o.positions);
  const staff=o.staff.filter(s=>(s.positions||[]).length);
  const E=effective(staff,o.prefs||{},n,wday);
  // sprawiedliwy podział dla osób bez limitu
  const open=closed.filter(x=>!x).length;
  const fair={};
  const capPos={};o.positions.forEach(p=>capPos[p.id]=open*p.count);
  staff.forEach(s=>{
    if(E[s.id].min!=null)return;
    let share=0;
    s.positions.forEach(pid=>{const el=staff.filter(x=>x.positions.includes(pid));share+=capPos[pid]/el.length/s.positions.length});
    fair[s.id]=Math.min(open,share);
  });
  const can=(id,d)=>!E[id].off.has(d)&&!(E[id].picksOwn&&!E[id].want.has(d));
  const cand=[];
  for(let d=1;d<=n;d++){
    if(closed[d-1]){cand.push(null);continue}
    const m={};
    slots.forEach(sl=>{
      const all=staff.filter(s=>E[s.id].pos.has(sl.pos)).map(s=>s.id);
      const ok=all.filter(id=>can(id,d));
      m[sl.key]=ok.length?ok:all;
    });
    cand.push(m);
  }
  const ids=staff.map(s=>s.id);
  const pick=a=>a[Math.floor(Math.random()*a.length)];
  const CHG=25,chg=(d,x)=>{const b=o.base&&o.base[d];if(!b||!x)return 0;let c=0;for(const s of slots)if(b[s.key]!==x[s.key])c+=CHG;return c};
  const sc=(A,id)=>scorePerson(A,id,n,closed,fair[id]??null,E[id],maxRun);
  let best=null,bestC=Infinity;
  const runs=o.base?3:5,budget=o.timeMs||6000,t0=Date.now(),now=()=>Date.now();
  let iters=Math.max(20000,slots.length*7000);
  for(let r=0;r<runs;r++){
    if(r>0&&now()-t0>budget)break;
    const A=cand.map((m,d)=>{
      if(!m)return null;
      const b=o.base&&o.base[d];
      if(b){const x={};slots.forEach(s=>x[s.key]=b[s.key]&&m[s.key].includes(b[s.key])?b[s.key]:pick(m[s.key])||null);return x}
      const x={},used=new Set();
      slots.forEach(s=>{const free=m[s.key].filter(id=>!used.has(id));const v=pick(free.length?free:m[s.key])||null;x[s.key]=v;if(v)used.add(v)});
      return x;
    });
    const pc={};let c=0;
    ids.forEach(id=>{pc[id]=sc(A,id);c+=pc[id]});
    const dc=new Array(n);
    for(let d=0;d<n;d++)dc[d]=scoreDay(A[d],rules,slots)+chg(d,A[d]);
    c+=dc.reduce((a,b)=>a+b,0);
    const T0=o.base?6:60;let T=T0,decay=Math.pow(.05/T0,1/(iters*.85));
    for(let it=0;it<iters;it++){
      // limit czasu: po pierwszych 2000 krokach dopasuj liczbę kroków do budżetu
      if(it===2000&&r===0){const per=(now()-t0)/2000,cap=Math.floor(budget/runs/per);if(cap<iters){iters=Math.max(4000,cap);decay=Math.pow(.05/T0,1/(iters*.85))}}
      const d=Math.floor(Math.random()*n),m=cand[d];if(!m)continue;
      const sl=pick(slots),list=m[sl.key];if(!list.length)continue;
      const old=A[d],nx={...old},v=pick(list);
      if(v===old[sl.key])continue;
      // jeśli osoba jest już na innym stanowisku tego dnia – zamiana
      let okSwap=true;
      for(const s of slots)if(nx[s.key]===v&&s.key!==sl.key){
        const back=old[sl.key];
        if(back&&!E[back].pos.has(s.pos)){okSwap=false;break}
        nx[s.key]=back;break;
      }
      if(!okSwap)continue;
      nx[sl.key]=v;
      const aff=new Set();for(const s of slots){if(old[s.key])aff.add(old[s.key]);if(nx[s.key])aff.add(nx[s.key])}
      A[d]=nx;
      const nd=scoreDay(nx,rules,slots)+chg(d,nx);
      let delta=nd-dc[d];const np={};
      for(const id of aff){np[id]=sc(A,id);delta+=np[id]-pc[id]}
      if(delta<=0||Math.random()<Math.exp(-delta/T)){c+=delta;dc[d]=nd;Object.assign(pc,np)}else A[d]=old;
      T=Math.max(.05,T*decay);
    }
    if(c<bestC){bestC=c;best=A.map(x=>x&&{...x})}
  }
  return best;
}

global.ZmianoEngine={solve,slotsOf,works,slotOf,effective,parseRhythm};
})(typeof window!=='undefined'?window:globalThis);
