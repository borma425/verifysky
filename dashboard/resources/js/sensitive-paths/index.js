document.addEventListener('DOMContentLoaded', () => {
    const bindBulk = (selectAllId, checkboxSelector, buttonId, confirmMessage, formId) => {
        const selectAll = document.getElementById(selectAllId);
        const button = document.getElementById(buttonId);
        const form = document.getElementById(formId);

        const updateButton = () => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }
            const checked = document.querySelectorAll(`${checkboxSelector}:checked`).length;
            if (checked > 0) {
                button.classList.remove('hidden');
                button.textContent = `Unlock Selected (${checked})`;
            } else {
                button.classList.add('hidden');
            }
        };

        if (selectAll instanceof HTMLInputElement) {
            selectAll.addEventListener('change', (event) => {
                document.querySelectorAll(checkboxSelector).forEach((checkbox) => {
                    if (checkbox instanceof HTMLInputElement) {
                        checkbox.checked = (event.target instanceof HTMLInputElement) ? event.target.checked : false;
                    }
                });
                updateButton();
            });
        }

        document.querySelectorAll(checkboxSelector).forEach((checkbox) => {
            checkbox.addEventListener('change', updateButton);
        });

        if (button instanceof HTMLButtonElement && form instanceof HTMLFormElement) {
            button.addEventListener('click', () => {
                if (window.confirm(confirmMessage)) {
                    form.submit();
                }
            });
        }
    };

    bindBulk(
        'selectAllCritical',
        '.rule-cb-crit',
        'bulkCriticalBtn',
        'Are you sure you want to completely unlock these Critical Paths for everyone?',
        'bulkCriticalForm'
    );
    bindBulk(
        'selectAllMedium',
        '.rule-cb-med',
        'bulkMediumBtn',
        'Are you sure you want to remove the Challenge protection from these paths?',
        'bulkMediumForm'
    );

    const singleUnlockForm = document.getElementById('singleUnlockForm');
    const destroyBase = document.querySelector('[data-destroy-base]')?.getAttribute('data-destroy-base') ?? '/sensitive-paths';
    document.querySelectorAll('.js-single-unlock').forEach((button) => {
        button.addEventListener('click', () => {
            const pathId = button.getAttribute('data-path-id');
            if (!pathId || !(singleUnlockForm instanceof HTMLFormElement)) {
                return;
            }
            if (window.confirm('Unlock this specific path?')) {
                singleUnlockForm.action = `${destroyBase}/${pathId}`;
                singleUnlockForm.submit();
            }
        });
    });

    let rowIndex = 0;
    const pathsContainer = document.getElementById('paths-container');
    const addRowButton = document.querySelector('.js-add-path-row');
    if (pathsContainer && addRowButton instanceof HTMLButtonElement) {
        addRowButton.addEventListener('click', () => {
            rowIndex += 1;
            const source = pathsContainer.querySelector('.path-row');
            if (!(source instanceof HTMLElement)) {
                return;
            }
            const clone = source.cloneNode(true);
            if (!(clone instanceof HTMLElement)) {
                return;
            }

            clone.querySelectorAll('select, input').forEach((element) => {
                const input = element;
                if ('name' in input && typeof input.name === 'string') {
                    input.name = input.name.replace(/\[\d+\]/, `[${rowIndex}]`);
                }
                if (input instanceof HTMLInputElement && input.type === 'text') {
                    input.value = '';
                }
            });

            clone.querySelector('.js-remove-row')?.classList.remove('hidden');
            pathsContainer.appendChild(clone);
        });
    }

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const button = target.closest('.js-remove-row');
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        const row = button.closest('.path-row');
        if (row) {
            row.remove();
        }
    });
});
