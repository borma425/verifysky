document.addEventListener('DOMContentLoaded', () => {
    const bulkForm = document.getElementById('bulkDeleteForm');
    const bulkBtn = document.querySelector('.js-bulk-delete');
    const selectAllToggles = document.querySelectorAll('.selectAllRules');
    const checkboxes = document.querySelectorAll('.rule-checkbox');

    const refreshBulkState = () => {
        if (!bulkBtn) {
            return;
        }
        const checked = document.querySelectorAll('.rule-checkbox:checked').length;
        if (checked > 0) {
            bulkBtn.classList.remove('hidden');
            bulkBtn.textContent = `Delete Selected (${checked})`;
        } else {
            bulkBtn.classList.add('hidden');
        }
    };

    selectAllToggles.forEach((toggle) => {
        toggle.addEventListener('change', (event) => {
            const table = event.target.closest('table');
            if (!table) {
                return;
            }
            table.querySelectorAll('.rule-checkbox').forEach((checkbox) => {
                checkbox.checked = event.target.checked;
            });
            refreshBulkState();
        });
    });

    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', refreshBulkState));

    if (bulkBtn && bulkForm) {
        bulkBtn.addEventListener('click', () => {
            if (window.confirm('Are you sure you want to delete all selected rules?')) {
                bulkForm.submit();
            }
        });
    }

    document.querySelectorAll('.js-toggle-rule').forEach((button) => {
        button.addEventListener('click', () => {
            const formId = button.getAttribute('data-form-id');
            if (!formId) {
                return;
            }
            const form = document.getElementById(formId);
            if (form instanceof HTMLFormElement) {
                form.submit();
            }
        });
    });

    const fieldSelect = document.querySelector('.js-firewall-field');
    const operatorSelect = document.querySelector('.js-firewall-operator');
    if (fieldSelect instanceof HTMLSelectElement && operatorSelect instanceof HTMLSelectElement) {
        const inOption = operatorSelect.querySelector('option[value="in"]');
        const notInOption = operatorSelect.querySelector('option[value="not_in"]');
        const updateOperatorText = () => {
            if (!(inOption instanceof HTMLOptionElement)) {
                return;
            }
            if (fieldSelect.value === 'ip.src') {
                inOption.textContent = 'is in (comma-separated or CIDR)';
                if (notInOption instanceof HTMLOptionElement) {
                    notInOption.textContent = 'is not in (comma-separated or CIDR)';
                }
            } else {
                inOption.textContent = 'is in (comma-separated list)';
                if (notInOption instanceof HTMLOptionElement) {
                    notInOption.textContent = 'is not in (comma-separated list)';
                }
            }
        };
        fieldSelect.addEventListener('change', updateOperatorText);
        updateOperatorText();
    }
});
