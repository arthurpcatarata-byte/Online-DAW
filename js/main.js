// =============================================================
//  CatarataDAW — Main JavaScript
// =============================================================

// Auto-dismiss alerts after 5 seconds
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        alert.style.transition = 'opacity .5s';
        alert.style.opacity    = '0';
        setTimeout(() => alert.remove(), 500);
    }, 5000);
});

// Close modals with Escape key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active')
                .forEach(m => m.classList.remove('active'));
    }
});

// Auto-focus first text input when a modal opens
const observer = new MutationObserver(mutations => {
    mutations.forEach(m => {
        if (m.type === 'attributes' && m.attributeName === 'class') {
            const target = m.target;
            if (target.classList.contains('active')) {
                const first = target.querySelector('input:not([type="hidden"]):not([type="file"])');
                if (first) setTimeout(() => first.focus(), 120);
            }
        }
    });
});
document.querySelectorAll('.modal-overlay').forEach(el =>
    observer.observe(el, { attributes: true })
);
