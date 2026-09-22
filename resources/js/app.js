const passwordToggle = document.querySelector('[data-password-toggle]');
passwordToggle?.addEventListener('click', () => {
    const input = document.getElementById(passwordToggle.getAttribute('aria-controls'));
    const revealed = input.type === 'password';
    input.type = revealed ? 'text' : 'password';
    passwordToggle.setAttribute('aria-pressed', String(revealed));
    passwordToggle.setAttribute('aria-label', revealed ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
});

const helpDialog = document.getElementById('login-help');
document.querySelector('[data-open-help]')?.addEventListener('click', () => helpDialog.showModal());
helpDialog?.addEventListener('click', (event) => {
    if (event.target === helpDialog) {
        const bounds = helpDialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
            helpDialog.close();
        }
    }
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
