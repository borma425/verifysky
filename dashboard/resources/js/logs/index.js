document.addEventListener('DOMContentLoaded', () => {
    const pathTooltips = Array.from(document.querySelectorAll('.es-path-tooltip'));
    if (pathTooltips.length === 0) {
        return;
    }

    document.addEventListener('click', (event) => {
        pathTooltips.forEach((tooltip) => {
            if (!(tooltip instanceof HTMLDetailsElement)) {
                return;
            }
            if (!tooltip.open || tooltip.contains(event.target)) {
                return;
            }
            tooltip.open = false;
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        pathTooltips.forEach((tooltip) => {
            if (tooltip instanceof HTMLDetailsElement) {
                tooltip.open = false;
            }
        });
    });
});
