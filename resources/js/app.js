const passwordToggle = document.querySelector('[data-password-toggle]');
passwordToggle?.addEventListener('click', () => {
    const input = document.getElementById(passwordToggle.getAttribute('aria-controls'));
    const revealed = input.type === 'password';
    input.type = revealed ? 'text' : 'password';
    passwordToggle.setAttribute('aria-pressed', String(revealed));
    passwordToggle.setAttribute('aria-label', revealed ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
});

const form = document.querySelector('[data-submit-form]');
form?.addEventListener('submit', () => {
    const button = form.querySelector('[data-submit-button]');
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.querySelector('[data-button-label]').textContent = 'Connexion en cours…';
    button.querySelector('.icon').hidden = true;
    button.querySelector('.button-spinner').hidden = false;
});

window.addEventListener('pageshow', () => {
    const button = document.querySelector('[data-submit-button]');
    if (button) {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.querySelector('[data-button-label]').textContent = 'Se connecter';
        button.querySelector('.icon').hidden = false;
        button.querySelector('.button-spinner').hidden = true;
    }
});

const menuToggle = document.querySelector('[data-menu-toggle]');
const sidebar = document.getElementById('workspace-sidebar');
const closeMenu = () => {
    sidebar?.classList.remove('is-open');
    menuToggle?.setAttribute('aria-expanded', 'false');
    menuToggle?.setAttribute('aria-label', 'Afficher le menu');
};
menuToggle?.addEventListener('click', () => {
    const open = sidebar.classList.toggle('is-open');
    menuToggle.setAttribute('aria-expanded', String(open));
    menuToggle.setAttribute('aria-label', open ? 'Masquer le menu' : 'Afficher le menu');
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && sidebar?.classList.contains('is-open')) {
        closeMenu();
        menuToggle.focus();
    }
});
document.addEventListener('click', (event) => {
    if (sidebar && !sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
        closeMenu();
    }
});

const transferForm = document.querySelector('[data-transfer-form]');
if (transferForm) {
    const source = transferForm.querySelector('[name="source_branch_id"]');
    const destination = transferForm.querySelector('[name="destination_branch_id"]');
    const parties = [...transferForm.querySelectorAll('[data-party]')];
    const searches = new Map();
    const loadPeople = async (section, preserveSelection = false) => {
        const kind = section.dataset.party;
        searches.get(kind)?.abort();
        const controller = new AbortController();
        searches.set(kind, controller);
        const select = section.querySelector('select');
        const status = section.querySelector('[data-search-status]');
        const previous = preserveSelection && select.value ? select.selectedOptions[0].cloneNode(true) : null;
        select.replaceChildren(new Option('Choisir une personne', ''));
        if (previous) {
            select.append(previous);
            select.value = previous.value;
        }
        if (!source.value) {
            status.textContent = 'Choisissez d’abord l’agence de départ.';
            return;
        }
        status.textContent = 'Recherche en cours…';
        const params = new URLSearchParams({ branch_id: source.value, kind, q: section.querySelector('[data-person-search]').value });
        if (kind === 'beneficiary' && transferForm.querySelector('[name="sender_mode"]:checked').value === 'existing') {
            params.set('sender_id', transferForm.querySelector('[name="sender_id"]').value);
        }
        try {
            const response = await fetch(`${transferForm.dataset.peopleUrl}?${params}`, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!response.ok) throw new Error('Search failed');
            const records = await response.json();
            for (const record of records) {
                if (String(record.id) !== previous?.value) select.add(new Option(record.label, record.id));
            }
            status.textContent = records.length ? `${records.length} résultat(s). Affinez la recherche si nécessaire.` : 'Aucun résultat. Vous pouvez ajouter une nouvelle personne ici.';
        } catch (error) {
            if (error.name !== 'AbortError') status.textContent = 'Recherche indisponible. Réessayez ou rechargez la page.';
        }
    };
    const refreshBeneficiaries = () => {
        const section = parties.find(party => party.dataset.party === 'beneficiary');
        section.querySelector('[data-person-search]').value = '';
        loadPeople(section);
    };
    for (const section of parties) {
        const toggle = () => {
            const existing = section.querySelector('input[type="radio"]:checked').value === 'existing';
            for (const [selector, active] of [['[data-party-existing]', existing], ['[data-party-new]', !existing]]) {
                const fields = section.querySelector(selector);
                if (fields) {
                    fields.disabled = !active;
                    fields.hidden = !active;
                }
            }
        };
        section.querySelectorAll('input[type="radio"]').forEach(radio => radio.addEventListener('change', () => {
            toggle();
            if (radio.value === 'existing') loadPeople(section, true);
            if (section.dataset.party === 'sender') refreshBeneficiaries();
        }));
        let debounce;
        section.querySelector('[data-person-search]').addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(() => loadPeople(section), 250);
        });
        toggle();
        if (section.querySelector('input[type="radio"]:checked').value === 'existing') loadPeople(section, true);
    }
    transferForm.querySelector('[name="sender_id"]').addEventListener('change', refreshBeneficiaries);
    const updateSource = () => {
        transferForm.querySelector('[data-source-currency]').textContent = source.selectedOptions[0]?.dataset.currency || '—';
        for (const option of destination.options) {
            option.disabled = !!source.value && option.value === source.value;
            if (option.disabled && option.selected) destination.value = '';
        }
    };
    source.addEventListener('change', () => {
        updateSource();
        parties.forEach(section => {
            section.querySelector('[data-person-search]').value = '';
            loadPeople(section);
        });
    });
    updateSource();
    window.addEventListener('pageshow', updateSource);
}

document.querySelector('[data-confirm-transfer]')?.addEventListener('submit', event => {
    if (event.submitter?.matches('[data-confirm-button]')) {
        event.submitter.disabled = true;
        event.submitter.textContent = 'Enregistrement…';
    }
});
window.addEventListener('pageshow', () => {
    const confirmButton = document.querySelector('[data-confirm-button]');
    if (confirmButton) {
        confirmButton.disabled = false;
        confirmButton.textContent = 'Confirmer l’opération';
    }
});
const thermalReceipt = document.querySelector('[data-thermal-receipt]');
if (thermalReceipt) {
    const pageStyle = document.createElement('style');
    document.head.append(pageStyle);
    const prepareReceiptPage = () => {
        thermalReceipt.classList.add('is-measuring');
        // CSS pixels use 96 dpi. Add the two 4 mm margins and rounding clearance.
        const heightMm = Math.ceil(thermalReceipt.getBoundingClientRect().height * 25.4 / 96) + 10;
        thermalReceipt.classList.remove('is-measuring');
        pageStyle.textContent = `@media print { @page titan-receipt { size: 80mm ${heightMm}mm; margin: 4mm; } }`;
    };
    const printReceipt = () => {
        prepareReceiptPage();
        window.print();
    };
    window.addEventListener('beforeprint', prepareReceiptPage);
    document.querySelector('[data-print-receipt]')?.addEventListener('click', printReceipt);
    if (thermalReceipt.hasAttribute('data-auto-print')) {
        const autoPrint = async () => {
            thermalReceipt.removeAttribute('data-auto-print');
            await document.fonts.ready;
            requestAnimationFrame(printReceipt);
        };
        if (document.readyState === 'complete') autoPrint();
        else window.addEventListener('load', autoPrint, { once: true });
    }
}

const notificationMenu = document.querySelector('[data-notification-menu]');
if (notificationMenu) {
    const toggle = notificationMenu.querySelector('[data-notification-toggle]');
    const badge = notificationMenu.querySelector('[data-unread-badge]');
    const status = notificationMenu.querySelector('[data-notification-refresh]');
    let previousCount = Number(toggle.getAttribute('aria-label').match(/\d+/)?.[0] || 0);
    let timer;
    let refreshing = false;
    let stopped = false;
    const refreshNotifications = async () => {
        clearTimeout(timer);
        if (refreshing || stopped || document.hidden) return;
        refreshing = true;
        try {
            const response = await fetch(notificationMenu.dataset.feedUrl, {
                headers: { Accept: 'application/json' }, cache: 'no-store', signal: AbortSignal.timeout(10000),
            });
            if ([401, 403, 419].includes(response.status)) {
                stopped = true;
                status.textContent = 'Session expirée ou accès modifié. Rechargez la page.';
                return;
            }
            if (!response.ok) throw new Error('Notifications unavailable');
            const data = await response.json();
            badge.hidden = data.unread_count === 0;
            badge.textContent = data.unread_count > 99 ? '99+' : String(data.unread_count);
            toggle.setAttribute('aria-label', `Notifications : ${data.unread_count} non lues`);
            // Keep keyboard focus on the current notification while refreshing the count.
            if (!notificationMenu.querySelector('[data-notification-preview]').contains(document.activeElement)) {
                notificationMenu.querySelector('[data-notification-preview]').innerHTML = data.html;
            }
            if (data.unread_count > previousCount) {
                notificationMenu.querySelector('[data-notification-announcement]').textContent = `${data.unread_count} notifications non lues.`;
            }
            previousCount = data.unread_count;
            status.textContent = 'À jour · actualisation automatique';
        } catch {
            status.textContent = 'Actualisation indisponible. Nouvelle tentative automatique.';
        } finally {
            refreshing = false;
            if (!stopped && !document.hidden) timer = setTimeout(refreshNotifications, 20000);
        }
    };
    notificationMenu.addEventListener('toggle', () => {
        if (notificationMenu.open) refreshNotifications();
    });
    document.addEventListener('click', event => {
        if (!notificationMenu.contains(event.target)) notificationMenu.open = false;
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && notificationMenu.open) {
            notificationMenu.open = false;
            toggle.focus();
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) clearTimeout(timer);
        else refreshNotifications();
    });
    window.addEventListener('focus', refreshNotifications);
    timer = setTimeout(refreshNotifications, 20000);
}

document.querySelectorAll('[data-review-transfer]').forEach(reviewForm => {
    reviewForm.addEventListener('submit', () => {
        const button = reviewForm.querySelector('button');
        button.dataset.originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = 'Enregistrement…';
    });
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-review-transfer] button').forEach(button => {
        button.disabled = false;
        if (button.dataset.originalLabel) button.textContent = button.dataset.originalLabel;
    });
});

const feeRuleForm = document.querySelector('[data-fee-rule-form]');
if (feeRuleForm) {
    const mode = feeRuleForm.querySelector('[name="fee_type"]');
    const currency = feeRuleForm.querySelector('[name="source_currency"]');
    const minimum = feeRuleForm.querySelector('[name="minimum_amount"]');
    const maximum = feeRuleForm.querySelector('[name="maximum_amount"]');
    const updateFeeFields = (updateBounds = false) => {
        feeRuleForm.querySelectorAll('[data-fee-value]').forEach(group => {
            const selected = group.dataset.feeValue === mode.value;
            group.hidden = !selected;
            const input = group.querySelector('input');
            input.disabled = !selected;
            input.required = selected;
        });
        if (updateBounds && currency.value === 'USD') {
            if (mode.value === 'percentage') {
                if (!minimum.value || Number(minimum.value) <= 1000) minimum.value = '1000.01';
                if (maximum.value && Number(maximum.value) <= 1000) maximum.value = '';
            } else {
                if (Number(minimum.value) > 1000) minimum.value = '0.00';
                if (!maximum.value || Number(maximum.value) > 1000) maximum.value = '1000.00';
            }
        }
    };
    mode.addEventListener('change', () => updateFeeFields(true));
    currency.addEventListener('change', () => updateFeeFields(true));
    updateFeeFields();
}


document.querySelectorAll('[data-confirm-delete]').forEach(form => {
    form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirmDelete)) event.preventDefault();
    });
});
