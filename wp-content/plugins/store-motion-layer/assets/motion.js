(()=>{'use strict';
 const fine=matchMedia('(min-width:1025px) and (hover:hover) and (pointer:fine)');
 const reduced=matchMedia('(prefers-reduced-motion:reduce)');
 let frame=0,pending=null;
 const clamp=(n,min,max)=>Math.min(max,Math.max(min,n));
 const reset=(el,vars)=>vars.forEach(v=>el.style.removeProperty(v));
 const paint=()=>{frame=0;if(!pending||!fine.matches||reduced.matches)return;const {kind,el,x,y}=pending;pending=null;const r=el.getBoundingClientRect(),nx=clamp((x-r.left)/r.width*2-1,-1,1),ny=clamp((y-r.top)/r.height*2-1,-1,1);
  if(kind==='hero'){el.style.setProperty('--hero-x',`${(nx*8).toFixed(2)}px`);el.style.setProperty('--hero-y',`${(ny*6).toFixed(2)}px`);el.style.setProperty('--hero-r',`${(nx*.6).toFixed(2)}deg`);}
  if(kind==='category'){el.style.setProperty('--tilt-x',`${(-ny*1.4).toFixed(2)}deg`);el.style.setProperty('--tilt-y',`${(nx*1.6).toFixed(2)}deg`);}
  if(kind==='product'){el.style.setProperty('--product-x',`${(-ny*.75).toFixed(2)}deg`);el.style.setProperty('--product-y',`${(nx*.9).toFixed(2)}deg`);}
 };
 const queue=(kind,el,e)=>{pending={kind,el,x:e.clientX,y:e.clientY};if(!frame)frame=requestAnimationFrame(paint)};
 const bind=()=>{const hero=document.querySelector('.store-hero');if(hero){hero.addEventListener('pointermove',e=>queue('hero',hero,e),{passive:true});hero.addEventListener('pointerleave',()=>reset(hero,['--hero-x','--hero-y','--hero-r']));}
  document.querySelectorAll('.home .store-discovery .store-category-card').forEach(el=>{el.addEventListener('pointermove',e=>queue('category',el,e),{passive:true});el.addEventListener('pointerleave',()=>reset(el,['--tilt-x','--tilt-y']));});
  document.querySelectorAll('.woocommerce ul.products li.product').forEach(el=>{el.addEventListener('pointermove',e=>queue('product',el,e),{passive:true});el.addEventListener('pointerleave',()=>reset(el,['--product-x','--product-y']));});
 };
 const clear=()=>{document.querySelectorAll('.store-hero,.store-category-card,.woocommerce ul.products li.product').forEach(el=>reset(el,['--hero-x','--hero-y','--hero-r','--tilt-x','--tilt-y','--product-x','--product-y']));};
 addEventListener('DOMContentLoaded',bind,{once:true});fine.addEventListener('change',clear);reduced.addEventListener('change',clear);
})();
