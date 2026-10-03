document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-setup-form]');
    if (!form) return;
    const year = form.querySelector('[data-existing-year]');
    const structures = form.querySelector('[data-structures]');
    const directRows = (container, selector) => Array.from(container?.children || []).filter(row => row.matches(selector));
    const programs = () => directRows(structures, '[data-structure-program]');
    const levels = program => directRows(program.querySelector('[data-year-levels]'), '[data-structure-level]');
    const blocks = level => directRows(level.querySelector('[data-level-blocks]'), '[data-structure-block]');
    const blockCount = () => structures?.querySelectorAll('[data-structure-block]').length || 0;
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
    function yearLevelDetails(select) {
        const level = select.closest('[data-structure-level]');
        const existing = select.value !== '';
        level.querySelector('[data-new-level-fields]').hidden = existing;
        level.querySelectorAll('[data-new-level-required]').forEach(input => {
            input.disabled = existing;
            input.required = !existing;
        });
    }
    function initialiseCounter(container, selector, indexName) {
        if (!container || container.dataset.next !== undefined) return;
        const indices = directRows(container, selector).map(row => Number(row.dataset[indexName]));
        container.dataset.next = String(Math.max(-1, ...indices) + 1);
    }
    function initialiseStructure(program) {
        initialiseCounter(program.querySelector('[data-year-levels]'), '[data-structure-level]', 'levelIndex');
        levels(program).forEach(level => {
            initialiseCounter(level.querySelector('[data-level-blocks]'), '[data-structure-block]', 'blockIndex');
            yearLevelDetails(level.querySelector('[data-year-level-choice]'));
        });
    }
    function updateStructureSummary() {
        if (!structures) return;
        const programRows = programs();
        let levelTotal = 0;
        form.querySelector('[data-expected-structures]').value = programRows.length;
        programRows.forEach(program => {
            const levelRows = levels(program);
            levelTotal += levelRows.length;
            program.querySelector('[data-expected-levels]').value = levelRows.length;
            levelRows.forEach(level => { level.querySelector('[data-expected-blocks]').value = blocks(level).length; });
        });
        form.querySelector('[data-structure-summary]').textContent = `${programRows.length} programs · ${levelTotal} year levels · ${blockCount()} / 30 blocks`;
    }
    function nextIndex(container) {
        const next = Number(container.dataset.next);
        container.dataset.next = String(next + 1);
        return next;
    }
    function appendTemplate(container, templateSelector, replacements) {
        let html = form.querySelector(templateSelector).innerHTML;
        Object.entries(replacements).forEach(([token, value]) => { html = html.replaceAll(token, String(value)); });
        container.insertAdjacentHTML('beforeend', html);
        return container.lastElementChild;
    }
    if (structures) {
        initialiseCounter(structures, '[data-structure-program]', 'programIndex');
        programs().forEach(initialiseStructure);
        updateStructureSummary();
    }
    form.querySelectorAll('[data-repeat]').forEach(container => {
        const group = container.dataset.repeat;
        const indices = directRows(container, '[data-repeat-row]').map(row => {
            const name = row.querySelector('[name]')?.name || '';
            return Number(name.match(new RegExp(`^${group}\\[(\\d+)\\]`))?.[1] || 0);
        });
        container.dataset.next = String(Math.max(-1, ...indices) + 1);
    });
    form.addEventListener('change', event => {
        if (event.target.matches('[data-subject-choice]')) subjectDetails(event.target);
        if (event.target.matches('[data-year-level-choice]')) yearLevelDetails(event.target);
    });
    form.addEventListener('click', event => {
        const addProgram = event.target.closest('[data-add-program]');
        const addLevel = event.target.closest('[data-add-year-level]');
        const addBlock = event.target.closest('[data-add-block]');
        const removeProgram = event.target.closest('[data-remove-program]');
        const removeLevel = event.target.closest('[data-remove-year-level]');
        const removeBlock = event.target.closest('[data-remove-block]');
        if (addProgram) {
            if (programs().length >= 20) { window.alert('Add at most 20 programs per setup.'); return; }
            if (blockCount() >= 30) { window.alert('Add at most 30 blocks across all programs and year levels.'); return; }
            const program = appendTemplate(structures, '[data-program-template]', { __program__: nextIndex(structures), __level__: 0, __block__: 0 });
            initialiseStructure(program);
            program.querySelector('select')?.focus();
        }
        if (addLevel) {
            const program = addLevel.closest('[data-structure-program]');
            if (levels(program).length >= 12) { window.alert('Add at most 12 year levels per program.'); return; }
            if (blockCount() >= 30) { window.alert('Add at most 30 blocks across all programs and year levels.'); return; }
            const container = program.querySelector('[data-year-levels]');
            const level = appendTemplate(container, '[data-year-level-template]', { __program__: program.dataset.programIndex, __level__: nextIndex(container), __block__: 0 });
            initialiseCounter(level.querySelector('[data-level-blocks]'), '[data-structure-block]', 'blockIndex');
            yearLevelDetails(level.querySelector('[data-year-level-choice]'));
            level.querySelector('select')?.focus();
        }
        if (addBlock) {
            if (blockCount() >= 30) { window.alert('Add at most 30 blocks across all programs and year levels.'); return; }
            const level = addBlock.closest('[data-structure-level]');
            const program = addBlock.closest('[data-structure-program]');
            const container = level.querySelector('[data-level-blocks]');
            appendTemplate(container, '[data-block-template]', { __program__: program.dataset.programIndex, __level__: level.dataset.levelIndex, __block__: nextIndex(container) }).querySelector('input')?.focus();
        }
        if (removeProgram) {
            if (programs().length === 1) { window.alert('Keep at least one program in this setup.'); return; }
            removeProgram.closest('[data-structure-program]').remove();
        }
        if (removeLevel) {
            const program = removeLevel.closest('[data-structure-program]');
            if (levels(program).length === 1) { window.alert('Keep at least one year level in each program.'); return; }
            removeLevel.closest('[data-structure-level]').remove();
        }
        if (removeBlock) {
            const level = removeBlock.closest('[data-structure-level]');
            if (blocks(level).length === 1) { window.alert('Keep at least one block in each year level.'); return; }
            removeBlock.closest('[data-structure-block]').remove();
        }
        if (addProgram || addLevel || addBlock || removeProgram || removeLevel || removeBlock) updateStructureSummary();
        const add = event.target.closest('[data-add]');
        const remove = event.target.closest('[data-remove]');
        if (add) {
            const group = add.dataset.add;
            const container = form.querySelector(`[data-repeat="${group}"]`);
            const count = directRows(container, '[data-repeat-row]').length;
            if (count >= Number(add.dataset.max)) { window.alert(`Maximum ${add.dataset.max} rows per setup.`); return; }
            // Use a monotonic index to keep labels and inputs unique after removing rows.
            const next = nextIndex(container);
            container.insertAdjacentHTML('beforeend', document.querySelector(`[data-template="${group}"]`).innerHTML.replaceAll('__index__', String(next)));
            container.lastElementChild.querySelector('input,select')?.focus();
        }
        if (remove) {
            const row = remove.closest('[data-repeat-row]');
            if (directRows(row.parentElement, '[data-repeat-row]').length === 1) { window.alert('Keep at least one row in this step.'); return; }
            row.remove();
        }
    });
    form.addEventListener('submit', () => {
        updateStructureSummary();
        // DOM indices stay monotonic while editing. Send dense row names only at submission.
        programs().forEach((program, programIndex) => {
            program.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/^structures\[[^\]]+\]/, `structures[${programIndex}]`); });
            levels(program).forEach((level, levelIndex) => {
                level.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/\[year_levels\]\[[^\]]+\]/, `[year_levels][${levelIndex}]`); });
                blocks(level).forEach((block, blockIndex) => {
                    block.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/\[blocks\]\[[^\]]+\]/, `[blocks][${blockIndex}]`); });
                });
            });
        });
        form.querySelectorAll('[data-repeat]').forEach(container => {
            const group = container.dataset.repeat;
            directRows(container, '[data-repeat-row]').forEach((row, index) => {
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
