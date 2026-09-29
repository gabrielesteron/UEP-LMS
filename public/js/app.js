const sidebar = document.getElementById('sidebar');
const menuToggle = document.getElementById('menuToggle');
const menuClose = document.getElementById('menuClose');
const backdrop = document.getElementById('sidebarBackdrop');

function setMenuOpen(open) {
    if (!sidebar || !menuToggle || !backdrop) return;
    sidebar.classList.toggle('open', open);
    backdrop.hidden = !open;
    menuToggle.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('menu-open', open);
    if (open) menuClose?.focus();
    else menuToggle.focus();
}

menuToggle?.addEventListener('click', () => setMenuOpen(true));
menuClose?.addEventListener('click', () => setMenuOpen(false));
backdrop?.addEventListener('click', () => setMenuOpen(false));
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && sidebar?.classList.contains('open')) setMenuOpen(false);
});
window.addEventListener('resize', () => {
    if (window.innerWidth >= 992 && sidebar?.classList.contains('open')) setMenuOpen(false);
});
sidebar?.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
    if (sidebar.classList.contains('open')) setMenuOpen(false);
}));

document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
}));

document.querySelectorAll('form[method="post"]').forEach(form => form.addEventListener('submit', event => {
    if (event.defaultPrevented || !form.checkValidity() || form.dataset.submitting === 'true') {
        if (form.dataset.submitting === 'true') event.preventDefault();
        return;
    }
    form.dataset.submitting = 'true';
    const submitter = event.submitter;
    if (submitter) {
        submitter.setAttribute('aria-disabled', 'true');
        submitter.classList.add('is-submitting');
    }
}));

const quizForm = document.querySelector('[data-quiz-deadline]');
if (quizForm) {
    const expires = Date.parse(quizForm.dataset.quizDeadline);
    const clock = document.getElementById('quizClock');
    if (Number.isFinite(expires) && clock) {
        let timer;
        const tick = () => {
            const seconds = Math.max(0, Math.floor((expires - Date.now()) / 1000));
            clock.textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
            if (seconds <= 1) {
                clearInterval(timer);
                if (quizForm.dataset.submitting !== 'true') quizForm.requestSubmit();
            }
        };
        timer = setInterval(tick, 1000);
        tick();
    }
}
