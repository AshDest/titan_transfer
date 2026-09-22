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
