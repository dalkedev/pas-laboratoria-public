async function myLoadHeader() {
    const target = document.getElementById('myHeader');
    if (!target) return;
    try {
        const response = await fetch('/z4/header.php', {credentials: 'same-origin', cache: 'no-store'});
        if (response.redirected) {
            window.location.assign('/z4/logowanie.php');
            return;
        }
        if (!response.ok) throw new Error('menu');
        target.innerHTML = await response.text();
        const current = window.location.pathname.replace(/index\.php$/, '');
        target.querySelectorAll('a.nav-link, a.dropdown-item, a.navbar-brand').forEach(link => {
            const path = new URL(link.href).pathname;
            if (path === current || (current === '/z4/visits.php' && path === '/z4/index9.php')) {
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
    const id = value('z4-visit-id');
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
        await fetch('/z4/visit.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                visit_id: id,
                visit_nonce: value('z4-visit-nonce'),
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

function startMonitoring() {
    const rows = document.getElementById('monitor-rows');
    if (!rows) return;
    const button = document.getElementById('probe-now');
    const status = document.getElementById('probe-status');
    const selector = document.getElementById('alarm-sound');
    const saved = localStorage.getItem('z4-alarm') || '1';
    selector.value = ['1', '2', '3', 'off'].includes(saved) ? saved : '1';
    selector.addEventListener('change', () => localStorage.setItem('z4-alarm', selector.value));
    let busy = false;
    let gesture = false;
    window.addEventListener('pointerdown', () => { gesture = true; }, {once: true});
    const duration = seconds => {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor(seconds % 3600 / 60);
        return [hours, minutes, seconds % 60].map(value => String(value).padStart(2, '0')).join(':');
    };
    const icon = item => {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 20 20');
        svg.setAttribute('width', '18');
        svg.setAttribute('height', '18');
        svg.setAttribute('aria-hidden', 'true');
        svg.classList.add('z4-state', 'align-middle', item.status === 'ok' ? 'z4-state-ok' : item.status === 'awaria' ? 'z4-state-fail' : 'z4-state-unknown', item.status === 'ok' ? 'text-success' : item.status === 'awaria' ? 'text-danger' : 'text-secondary');
        if (item.status === 'awaria' && Number(item.outage_seconds) > 20) svg.classList.add('z4-state-pulse');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', item.status === 'ok' ? 'M3 10l4 4L17 4' : 'M4 4l12 12M16 4L4 16');
        path.setAttribute('fill', 'none');
        path.setAttribute('stroke', 'currentColor');
        path.setAttribute('stroke-width', item.status === 'awaria' && Number(item.outage_seconds) > 20 ? '3' : '1.7');
        svg.append(path);
        return svg;
    };
    const poll = async () => {
        if (busy) return;
        busy = true;
        button.disabled = true;
        status.textContent = 'sprawdzanie';
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const response = await fetch('/z4/probe.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: new URLSearchParams({csrf})});
            if (response.redirected) {
                window.location.assign('/z4/logowanie.php');
                return;
            }
            if (!response.ok) throw new Error('probe');
            const data = await response.json();
            for (const item of data.rows) {
                const row = rows.querySelector(`[data-domain-id="${Number(item.id)}"]`);
                if (!row) continue;
                for (const field of ['last_error', 'errors', 'failure', 'total_errors']) row.querySelector(`[data-field="${field}"]`).textContent = item[field] ?? '-';
                row.querySelector('[data-field="downtime"]').textContent = duration(Number(item.total_errors) * 10);
                const cell = row.querySelector('[data-field="status"]');
                cell.replaceChildren(icon(item), document.createTextNode(' ' + item.status));
            }
            if (gesture && selector.value !== 'off' && data.rows.some(item => item.status === 'awaria' && Number(item.outage_seconds) > 40)) {
                new Audio(`/z4/media/alarm${selector.value}.mp3`).play().catch(() => {});
            }
            status.textContent = 'odświeżono ' + new Date().toLocaleTimeString('pl-PL', {hour12: false});
        } catch {
            status.textContent = 'błąd sprawdzania';
        } finally {
            busy = false;
            button.disabled = false;
        }
    };
    button.addEventListener('click', poll);
    poll();
    window.setInterval(poll, 10000);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startMonitoring);
} else {
    startMonitoring();
}
