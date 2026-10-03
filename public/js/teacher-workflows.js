document.querySelectorAll('[data-bulk-attendance]').forEach(form => {
    const threshold = Number(form.dataset.lateThreshold);
    const adjustMinutes = select => {
        const minutes = select.closest('.bulk-attendance-row').querySelector('[data-attendance-minutes]');
        if (select.value === 'present') minutes.value = '0';
        else if (select.value === 'late' && (minutes.value === '' || Number(minutes.value) < threshold)) minutes.value = String(threshold);
        else if (['absent', 'excused'].includes(select.value)) minutes.value = '';
    };
    form.querySelector('[data-mark-all-present]')?.addEventListener('click', () => {
        form.querySelectorAll('[data-attendance-status]').forEach(select => {
            if (!select.disabled && !select.closest('fieldset').disabled) {
                select.value = 'present';
                adjustMinutes(select);
            }
        });
    });
    form.querySelectorAll('[data-attendance-status]').forEach(select => select.addEventListener('change', () => adjustMinutes(select)));
});
