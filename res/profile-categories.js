/* Keep the real inputs together while a floating row follows the pointer. */
(() => {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    document.querySelectorAll('#categoriesList, #macrocategoriesList').forEach(list => {
        let drag = null;
        const rows = () => [...list.querySelectorAll('.category-item')].filter(row => row !== drag?.row);
        const moveGap = before => {
            if (before === drag.gap || drag.gap.nextElementSibling === before) return;
            const positions = new Map(rows().map(row => [row, row.getBoundingClientRect()]));
            list.insertBefore(drag.gap, before);
            if (!reducedMotion.matches) positions.forEach((old, row) => {
                const next = row.getBoundingClientRect();
                row.animate([{transform:`translate(${old.left-next.left}px, ${old.top-next.top}px)`}, {transform:'none'}], {duration:140, easing:'ease-out'});
            });
        };
        list.addEventListener('pointerdown', event => {
            const handle = event.target.closest('.category-drag');
            if (!handle || event.button !== 0 || drag) return;
            const row = handle.closest('.category-item');
            const rect = row.getBoundingClientRect();
            const gap = document.createElement('li');
            gap.className = 'category-placeholder';
            gap.setAttribute('aria-hidden', 'true');
            gap.style.width = `${rect.width}px`;
            gap.style.height = `${rect.height}px`;
            list.insertBefore(gap, row);
            drag = {row, gap, handle, pointerId:event.pointerId, originalNext:row.nextElementSibling,
                dx:event.clientX-rect.left, dy:event.clientY-rect.top};
            row.style.width = `${rect.width}px`;
            row.style.left = `${rect.left}px`;
            row.style.top = `${rect.top}px`;
            row.classList.add('sorting');
            handle.setPointerCapture(event.pointerId);
            event.preventDefault();
        });
        list.addEventListener('pointermove', event => {
            if (!drag || drag.pointerId !== event.pointerId) return;
            drag.row.style.left = `${event.clientX-drag.dx}px`;
            drag.row.style.top = `${event.clientY-drag.dy}px`;
            const under = document.elementFromPoint(event.clientX, event.clientY);
            if (!under || under.closest('ul') !== list || under.closest('.category-placeholder')) return;
            const candidates = rows();
            const target = candidates.find(row => {
                const rect = row.getBoundingClientRect();
                return event.clientY < rect.top || (event.clientY <= rect.bottom && event.clientX < rect.left + rect.width/2);
            });
            moveGap(target || null);
        });
        const finish = cancel => {
            if (!drag) return;
            const current = drag;
            drag = null;
            list.insertBefore(current.row, cancel ? current.originalNext : current.gap);
            current.gap.remove();
            current.row.classList.remove('sorting');
            ['width', 'left', 'top'].forEach(key => current.row.style.removeProperty(key));
            if (current.handle.hasPointerCapture(current.pointerId)) current.handle.releasePointerCapture(current.pointerId);
            current.handle.focus({preventScroll:true});
        };
        list.addEventListener('pointerup', () => finish(false));
        list.addEventListener('pointercancel', () => finish(true));
        list.addEventListener('lostpointercapture', () => finish(true));
        window.addEventListener('blur', () => finish(true));
        list.addEventListener('keydown', event => {
            if (event.key === 'Escape' && drag) { finish(true); return; }
            const handle = event.target.closest('.category-drag');
            if (!handle || drag || !['ArrowUp','ArrowDown','ArrowLeft','ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            const row = handle.closest('.category-item');
            const previous = ['ArrowUp','ArrowLeft'].includes(event.key);
            const target = previous ? row.previousElementSibling : row.nextElementSibling;
            if (target) list.insertBefore(row, previous ? target : target.nextSibling);
            handle.focus();
        });
    });
    const updateLimit = input => input.classList.toggle('is-inactive', input.value === '' || Number(input.value) === 0);
    document.querySelectorAll('.category-limit-input').forEach(updateLimit);
    document.addEventListener('input', event => {
        if (event.target.matches('.category-limit-input')) updateLimit(event.target);
    });
    const list = document.getElementById('categoriesList');
    if (list) new MutationObserver(() => list.querySelectorAll('.category-limit-input').forEach(updateLimit)).observe(list, {childList:true, subtree:true});
})();
