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

    const previewSources = document.querySelectorAll('.js-firewall-preview-source');
    const previewScope = document.querySelector('[data-firewall-preview="scope"]');
    const previewAction = document.querySelector('[data-firewall-preview="action"]');
    const previewTtl = document.querySelector('[data-firewall-preview="ttl"]');
    const previewExpression = document.querySelector('[data-firewall-preview="expression"]');
    const previewState = document.querySelector('[data-firewall-preview="state"]');

    const selectedText = (field) => {
        if (field instanceof HTMLSelectElement) {
            return field.selectedOptions[0]?.textContent?.trim() || '';
        }

        if (field instanceof HTMLInputElement) {
            return field.value.trim();
        }

        return '';
    };

    const updateRulePreview = () => {
        const scope = document.querySelector('[name="domain_name"]');
        const action = document.querySelector('[name="action"]');
        const duration = document.querySelector('[name="duration"]');
        const field = document.querySelector('[name="field"]');
        const operator = document.querySelector('[name="operator"]');
        const value = document.querySelector('[name="value"]');
        const paused = document.querySelector('[name="paused"]');

        if (previewScope) {
            previewScope.textContent = selectedText(scope) || 'All Domains';
        }
        if (previewAction) {
            previewAction.textContent = selectedText(action) || 'managed_challenge (Smart CAPTCHA)';
        }
        if (previewTtl) {
            previewTtl.textContent = selectedText(duration) || 'Forever (No Expiry)';
        }
        if (previewExpression) {
            const valueText = selectedText(value) || 'Value to match against';
            previewExpression.textContent = `${selectedText(field) || 'IP Address / CIDR'} ${selectedText(operator) || 'Equals'} "${valueText}"`;
        }
        if (previewState) {
            previewState.textContent = paused instanceof HTMLInputElement && paused.checked ? 'Paused on create' : 'Enabled on create';
        }
    };

    previewSources.forEach((source) => {
        source.addEventListener('change', updateRulePreview);
        source.addEventListener('input', updateRulePreview);
    });
    updateRulePreview();
});
