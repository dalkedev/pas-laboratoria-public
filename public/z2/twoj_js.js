async function myLoadHeader() {
    const target = document.getElementById('myHeader');
    if (!target) return;
    try {
        const response = await fetch('/z2/header.php', {credentials: 'same-origin', cache: 'no-store'});
        if (response.redirected) {
            window.location.assign('/z2/logowanie.php');
            return;
        }
        if (!response.ok) throw new Error('menu');
        target.innerHTML = await response.text();
        const viewport = window.matchMedia('(min-width: 768px)');
        viewport.addEventListener('change', () => {
            if (!viewport.matches) {
                const menu = document.getElementById('main_nav');
                if (menu) bootstrap.Collapse.getOrCreateInstance(menu, {toggle: false}).hide();
            }
        });
    } catch {
        target.textContent = 'błąd menu';
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', myLoadHeader);
} else {
    myLoadHeader();
}
