(() => {
    const form=document.getElementById('currencies-form');
    if (!form) return;
    const automatic=form.querySelector('#automatic-rates');
    const update=() => form.querySelectorAll('.currency-setting-row').forEach(row => {
        const checkbox=form.querySelector('.currency-choices input[value="'+row.dataset.currency+'"]');
        row.hidden=!checkbox.checked;
        const rate=row.querySelector('input[type=number]');
        if (rate) rate.disabled=automatic.checked || !checkbox.checked;

    });
    form.addEventListener('change',update); update();
    if (typeof Intl.DisplayNames==='function') {
        const names=new Intl.DisplayNames([document.documentElement.lang || 'en'],{type:'currency'});
        form.querySelectorAll('[data-currency-name]').forEach(label => {label.textContent=names.of(label.dataset.currencyName);});
    }
})();
