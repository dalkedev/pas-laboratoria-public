async function myLoadHeader() {
    const target = document.getElementById('myHeader');
    if (!target) return;
    try {
        const response = await fetch('/z5/header.php', {credentials: 'same-origin', cache: 'no-store'});
        if (response.redirected) {
            window.location.assign('/z5/logowanie.php');
            return;
        }
        if (!response.ok) throw new Error('menu');
        target.innerHTML = await response.text();
        const current = window.location.pathname.replace(/index\.php$/, '');
        target.querySelectorAll('a.nav-link, a.dropdown-item, a.navbar-brand').forEach(link => {
            const path = new URL(link.href).pathname;
            if (path === current || (current === '/z5/visits.php' && path === '/z5/index9.php')) {
                link.classList.add('active');
                link.setAttribute('aria-current', 'page');
                link.closest('.dropdown')?.querySelector('.dropdown-toggle')?.classList.add('active');
            }
        });
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

async function saveVisitMetrics() {
    const value = name => document.querySelector(`meta[name="${name}"]`)?.content;
    const id = value('z5-visit-id');
    if (!id) return;
    let browser = 'nieustalona';
    if (typeof UAParser === 'function') {
        const parsed = new UAParser().getBrowser();
        browser = [parsed.name, parsed.version].filter(Boolean).join(' ') || browser;
    }
    let java = false;
    try {
        java = typeof navigator.javaEnabled === 'function' && navigator.javaEnabled();
    } catch {}
    const number = (input, max) => Math.max(1, Math.min(max, Math.round(Number(input) || 1)));
    try {
        await fetch('/z5/visit.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                visit_id: id,
                visit_nonce: value('z5-visit-nonce'),
                csrf: value('csrf-token'),
                browser: browser.slice(0, 128),
                screen_width: number(screen.width, 65535),
                screen_height: number(screen.height, 65535),
                viewport_width: number(window.innerWidth, 65535),
                viewport_height: number(window.innerHeight, 65535),
                colors: number(screen.colorDepth, 64),
                cookies_enabled: Boolean(navigator.cookieEnabled),
                java_enabled: Boolean(java),
                language: (navigator.language || 'und').slice(0, 35)
            })
        });
    } catch {}
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', saveVisitMetrics);
} else {
    saveVisitMetrics();
}
