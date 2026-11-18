(() => {
    const panels = [...document.querySelectorAll('.preference-panel')];
    const links = [...document.querySelectorAll('.preferences-nav a')];
    const track = document.querySelector('.preferences-panels');
    const mobile = matchMedia('(max-width: 700px)');
    let active = '';
    function select(id, scroll = true) {
        if (!panels.some(panel => panel.id === id)) id = 'general';
        active = id;
        panels.forEach(panel => { panel.hidden = !mobile.matches && panel.id !== id; });
        links.forEach(link => {
            const current = link.dataset.panel === id;
            link.classList.toggle('active', current);
            if (current) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
        if (mobile.matches && scroll) {
            const panel = document.getElementById(id);
            track.scrollTo({left: panel.offsetLeft - panels[0].offsetLeft, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
            links.find(link => link.dataset.panel === id)?.scrollIntoView({block:'nearest', inline:'nearest'});
        }
    }
    links.forEach(link => link.addEventListener('click', event => {
        event.preventDefault(); history.replaceState(null, '', '#' + link.dataset.panel); select(link.dataset.panel);
    }));
    window.addEventListener('hashchange', () => select(location.hash.slice(1)));
    mobile.addEventListener('change', () => select(active));
    let timer;
    track.addEventListener('scroll', () => {
        if (!mobile.matches) return;
        clearTimeout(timer);
        timer = setTimeout(() => {
            const panel = panels.reduce((best, panel) => Math.abs(panel.offsetLeft - panels[0].offsetLeft - track.scrollLeft) < Math.abs(best.offsetLeft - panels[0].offsetLeft - track.scrollLeft) ? panel : best);
            select(panel.id, false); history.replaceState(null, '', '#' + panel.id);
        }, 120);
    }, {passive:true});
    const submittedPanel = document.body.dataset.preferencePanel;
    select(submittedPanel || location.hash.slice(1) || (location.pathname.endsWith('/import.php') ? 'import' : 'general'));
    document.querySelectorAll('.preference-panel form').forEach(form => form.addEventListener('submit', () => {
        form.action = 'profile.php#' + form.closest('.preference-panel').id;
    }));
})();
