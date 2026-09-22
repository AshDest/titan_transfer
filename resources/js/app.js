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
document.querySelector('[data-print-receipt]')?.addEventListener('click', () => window.print());
