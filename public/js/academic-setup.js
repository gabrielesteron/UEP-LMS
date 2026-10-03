document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-setup-form]');
    if (!form) return;
    const year = form.querySelector('[data-existing-year]');
    function updateYear() {
        if (!year) return;
        const existing = year.value !== '';
        form.querySelector('[data-new-year]').hidden = existing;
        form.querySelectorAll('[data-new-year-required]').forEach(input => { input.disabled = existing; input.required = !existing; });
    }
    updateYear();
    year?.addEventListener('change', updateYear);
    function subjectDetails(select) {
        const row = select.closest('[data-repeat-row]');
        const option = select.selectedOptions[0];
        ['code', 'name', 'units'].forEach(field => {
            const input = row.querySelector(`[data-subject-${field}]`);
            if (select.value) input.value = option.dataset[field];
            input.readOnly = select.value !== '';
        });
    }
    form.querySelectorAll('[data-subject-choice]').forEach(subjectDetails);
    form.addEventListener('change', event => {
        if (event.target.matches('[data-subject-choice]')) subjectDetails(event.target);
    });
    form.addEventListener('click', event => {
        const add = event.target.closest('[data-add]');
        const remove = event.target.closest('[data-remove]');
        if (add) {
            const group = add.dataset.add;
            const container = form.querySelector(`[data-repeat="${group}"]`);
            const count = container.querySelectorAll('[data-repeat-row]').length;
            if (count >= Number(add.dataset.max)) { window.alert(`Maximum ${add.dataset.max} rows per setup.`); return; }
            // Use a monotonic index to keep labels and inputs unique after removing rows.
            const next = Number(container.dataset.next || Math.max(...Array.from(container.querySelectorAll('[name]')).map(input => Number(input.name.match(/\[(\d+)\]/)?.[1] || 0))) + 1);
            container.dataset.next = next + 1;
            container.insertAdjacentHTML('beforeend', document.querySelector(`[data-template="${group}"]`).innerHTML.replaceAll('__index__', String(next)));
            container.lastElementChild.querySelector('input,select')?.focus();
        }
        if (remove) {
            const row = remove.closest('[data-repeat-row]');
            if (row.parentElement.querySelectorAll('[data-repeat-row]').length === 1) { window.alert('Keep at least one row in this step.'); return; }
            row.remove();
        }
    });
    form.addEventListener('submit', () => {
        form.querySelectorAll('[data-repeat]').forEach(container => {
            const group = container.dataset.repeat;
            container.querySelectorAll('[data-repeat-row]').forEach((row, index) => {
                row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(new RegExp(`^${group}\\[\\d+\\]`), `${group}[${index}]`); });
            });
        });
    });
    form.querySelector('[data-student-search]')?.addEventListener('click', () => {
        const url = new URL('/admin/setup', window.location.origin);
        url.searchParams.set('step', '5');
        url.searchParams.set('q', document.getElementById('student_search').value);
        window.location.assign(url);
    });
});
