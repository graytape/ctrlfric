(() => {
 const form=document.getElementById('transaction-search'),panel=document.getElementById('advanced-search'),toggle=document.getElementById('advanced-toggle');
 if(!form)return;
 const dateInputs=['start_date','end_date'].map(id=>document.getElementById(id));
 const syncDates=()=>dateInputs.forEach(input=>{form.elements[input.id].value=input.value;});
 form.addEventListener('submit',syncDates);
 toggle.addEventListener('click',()=>{panel.hidden=!panel.hidden;toggle.setAttribute('aria-expanded',String(!panel.hidden));});
 if(['category','macrocategory','type','currency','amount_min','amount_max','source','has_note','sort'].some(key=>entryFilters[key])){panel.hidden=false;toggle.setAttribute('aria-expanded','true');}
 const dateString=date=>`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
 panel.querySelectorAll('[data-period]').forEach(button=>button.addEventListener('click',()=>{
  const end=new Date(),start=new Date(end),period=button.dataset.period;
  if(period==='last-month'){start.setDate(1);start.setMonth(start.getMonth()-1);end.setDate(0);}
  else if(period==='week')start.setDate(start.getDate()-6);
  else{const months=period==='year'?12:period==='six-months'?6:1;const day=start.getDate();start.setDate(1);start.setMonth(start.getMonth()-months);start.setDate(Math.min(day,new Date(start.getFullYear(),start.getMonth()+1,0).getDate()));}
  dateInputs[0].value=dateString(start);dateInputs[1].value=dateString(end);syncDates();form.requestSubmit();
 }));
 if(window.jQuery?.fn?.select2)window.jQuery('.advanced-select').select2({width:'100%',language:{noResults:()=>t['No results found'],searching:()=>t['Loading...']}});
 const tbody=document.querySelector('.transactions-table'),cog=document.getElementById('selection-toggle'),tools=document.getElementById('selection-tools'),operation=document.getElementById('bulk-operation'),value=document.getElementById('bulk-value'),apply=document.getElementById('bulk-apply');
 let selecting=false,busy=false;const selected=new Set();
 const refresh=()=>{
  document.getElementById('selected-count').textContent=`${selected.size} ${t['Selected']}`;
  apply.disabled=busy||selected.size===0;
  tbody.querySelectorAll('.entry-select').forEach(box=>{box.hidden=!selecting;box.checked=selected.has(box.dataset.id);box.closest('tr').classList.toggle('entry-selected',box.checked);});
 };
 const enhance=()=>{tbody.querySelectorAll('tr[data-id]').forEach(row=>{
  if(row.querySelector('.entry-select'))return;
  const box=document.createElement('input');box.type='checkbox';box.className='entry-select';box.dataset.id=row.dataset.id;box.setAttribute('aria-label',t['Select entries']);box.hidden=!selecting;row.children[0].prepend(box);
 });const loaded=new Set([...tbody.querySelectorAll('tr[data-id]')].map(row=>row.dataset.id));for(const id of selected)if(!loaded.has(id))selected.delete(id);refresh();};
 enhance();new MutationObserver(enhance).observe(tbody,{childList:true,subtree:true});
 cog.addEventListener('click',()=>{selecting=!selecting;if(!selecting)selected.clear();tools.hidden=!selecting;cog.setAttribute('aria-pressed',String(selecting));refresh();});
 tbody.addEventListener('change',event=>{const box=event.target.closest('.entry-select');if(box){if(box.checked)selected.add(box.dataset.id);else selected.delete(box.dataset.id);refresh();}});
 document.getElementById('select-all').addEventListener('click',()=>{tbody.querySelectorAll('tr[data-id]').forEach(row=>selected.add(row.dataset.id));refresh();});
 document.getElementById('select-none').addEventListener('click',()=>{selected.clear();refresh();});
 const options=()=>{
  value.replaceChildren();let choices=[];
  if(operation.value==='category')choices=categories.map(name=>[name,name]);
  if(operation.value==='macrocategory')choices=macrocategories.map(name=>[name,name]);
  if(operation.value==='type')choices=[['Expense',t['Expense']],['Income',t['Income']]];
  for(const [code,label] of choices)value.add(new Option(label,code));value.hidden=choices.length===0;
 };
 operation.addEventListener('change',options);options();
 apply.addEventListener('click',async()=>{
  if(!selected.size||busy)return;
  if(operation.value==='delete'&&!confirm(`${t['Delete selected entries?']} (${selected.size})`))return;
  busy=true;refresh();const params=new URLSearchParams({action:'bulk',operation:operation.value,value:value.value,csrf:entriesCsrf});selected.forEach(id=>params.append('ids[]',id));
  try{
   const response=await fetch('get_data.php',{method:'POST',body:params});
   if(!response.ok){const error=await response.json();throw Error(error.error||t['Error']);}
   if(operation.value==='export'){
    const url=URL.createObjectURL(await response.blob()),link=document.createElement('a');link.href=url;link.download='ctrlfric-selection.csv';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
   }else location.reload();
  }catch(error){alert(error.message);}finally{busy=false;refresh();}
 });
})();
