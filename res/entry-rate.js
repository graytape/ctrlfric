(() => {
 const config=window.entryRateConfig, currency=document.querySelector('select[name="currency"]'), input=document.getElementById('exchange_rate'), row=document.getElementById('entry-rate-row'), text=document.getElementById('entry-rate-description'), override=document.getElementById('rate-override');
 if(!config||!input||!currency)return;
 const names=typeof Intl.DisplayNames==='function'?new Intl.DisplayNames([config.language],{type:'currency'}):null;
 let controller;
 const rounded=rate=>{const n=Number(rate);return n>=.0005?(Math.round((n+Number.EPSILON)*1000)/1000).toFixed(3):String(n);};
 const describe=(rate,date)=>{text.textContent=`1 ${names?.of(currency.value)||currency.value} = ${Number(rate).toLocaleString(config.language,{minimumFractionDigits:Number(rate)>=.0005?3:0,maximumFractionDigits:Number(rate)>=.0005?3:10})} ${names?.of(config.currency)||config.currency}`+(date?` · ${config.published}: ${date}`:'');};
 async function update(reset=false){
  controller?.abort();row.hidden=currency.value===config.currency;input.disabled=row.hidden;
  if(row.hidden){input.value='';return;}
  if(!reset&&override.value==='1'&&input.value!==''){describe(input.value);return;}
  if(config.auto){
   input.value='';text.textContent=config.loading;controller=new AbortController();
   try{const response=await fetch('currency-rates.php?'+new URLSearchParams({currency:currency.value}),{signal:controller.signal});const data=await response.json();if(!response.ok||!Number.isFinite(Number(data.rate)))throw Error();if(override.value!=='1'){input.value=rounded(data.rate);describe(input.value,data.date);}}
   catch(e){if(e.name!=='AbortError'&&override.value!=='1')text.textContent=config.failure;}
  }else{input.value=rounded(config.rates[currency.value]??'');if(config.rates[currency.value])describe(input.value);else{input.value='';text.textContent=config.failure;}}
 }
 currency.addEventListener('change',()=>{override.value='0';update(true);});
 input.addEventListener('input',()=>{controller?.abort();override.value=input.value===''?'0':'1';if(input.value!=='')describe(input.value);});
 input.addEventListener('blur',()=>{if(input.value!==''&&Number(input.value)>0){input.value=rounded(input.value);describe(input.value);}});
 update();
})();
