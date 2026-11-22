(() => {
    'use strict';

    const localUrl = value => {
        if (typeof value !== 'string' || !value.trim()) return null;
        try {
            const url = new URL(value, window.location.href);
            return url.origin === window.location.origin && ['http:', 'https:'].includes(url.protocol) ? url.href : null;
        } catch {
            return null;
        }
    };

    const initialize = root => {
        if (root.dataset.playerReady === 'true') return;
        const media = root.querySelector('[data-player-media]');
        const source = root.querySelector('[data-player-queue]');
        if (!media || !source) return;

        const title = root.querySelector('[data-player-title]');
        const artist = root.querySelector('[data-player-artist]');
        const status = root.querySelector('[data-player-status]');
        const text = root.querySelector('[data-player-text]');
        const previous = root.querySelector('[data-player-prev]');
        const next = root.querySelector('[data-player-next]');
        const toggle = root.querySelector('[data-player-toggle]');
        const repeatButton = root.querySelector('[data-player-repeat]');
        const trackButtons = Array.from(root.querySelectorAll('[data-track-id]'));
        const announce = message => {
            if (status) status.textContent = message;
        };

        let queue;
        try {
            const data = JSON.parse(source.textContent);
            if (!Array.isArray(data)) throw new Error('queue');
            queue = data.filter(track => track && typeof track.title === 'string' && localUrl(track.url)).map(track => ({...track, url: localUrl(track.url)}));
        } catch {
            queue = [];
            announce('This playlist could not be loaded.');
        }

        let index = -1;
        let repeat = false;
        let revision = 0;
        root.dataset.playerReady = 'true';
        root.dataset.playerState = 'idle';
        media.loop = false;
        media.controls = true;
        if (status) {
            status.setAttribute('aria-live', 'polite');
            status.setAttribute('role', 'status');
        }

        const updateControls = () => {
            if (previous) previous.disabled = queue.length < 2 || (index <= 0 && !repeat);
            if (next) next.disabled = queue.length < 2 || (index === queue.length - 1 && !repeat);
            if (toggle) {
                toggle.disabled = queue.length === 0;
                toggle.textContent = media.paused ? 'Play' : 'Pause';
                toggle.setAttribute('aria-label', media.paused ? 'Play current item' : 'Pause current item');
            }
            if (repeatButton) {
                repeatButton.disabled = queue.length === 0;
                repeatButton.setAttribute('aria-pressed', String(repeat));
            }
            for (const button of trackButtons) {
                button.setAttribute('aria-current', String(index >= 0 && String(queue[index].id) === button.dataset.trackId));
            }
        };

        const play = async () => {
            if (index < 0) return;
            const currentRevision = revision;
            try {
                await media.play();
                if (currentRevision !== revision) return;
                root.dataset.playerState = 'playing';
                announce('');
            } catch (error) {
                if (currentRevision !== revision || error?.name === 'AbortError') return;
                root.dataset.playerState = 'paused';
                announce('Press Play to continue playback.');
            }
            updateControls();
        };

        const select = (position, shouldPlay = false) => {
            if (!Number.isInteger(position) || position < 0 || position >= queue.length) return;
            revision += 1;
            media.pause();
            index = position;
            const track = queue[index];
            media.src = track.url;
            if (title) title.textContent = track.title;
            if (artist) artist.textContent = typeof track.artist === 'string' ? track.artist : '';
            if (text) {
                text.textContent = typeof track.lyrics === 'string' ? track.lyrics : '';
                text.hidden = !text.textContent;
            }
            for (const caption of Array.from(media.textTracks || [])) caption.mode = 'disabled';
            for (const caption of Array.from(media.querySelectorAll('track'))) caption.remove();
            const subtitle = localUrl(track.subtitleUrl);
            if (media.tagName.toLowerCase() === 'video' && subtitle) {
                const caption = document.createElement('track');
                caption.kind = 'subtitles';
                caption.label = 'Subtitles';
                caption.srclang = typeof track.subtitleLanguage === 'string' ? track.subtitleLanguage : 'en';
                caption.src = subtitle;
                caption.default = true;
                media.append(caption);
            }
            media.load();
            root.dataset.playerIndex = String(index);
            root.dataset.playerTrackId = String(track.id);
            root.dataset.playerState = 'paused';
            announce('');
            updateControls();
            root.dispatchEvent(new CustomEvent('library:trackchange', {bubbles: true, detail: {id: track.id, index}}));
            if (shouldPlay) void play();
        };

        const advance = automatic => {
            if (index + 1 < queue.length) {
                select(index + 1, true);
            } else if (repeat && queue.length > 0) {
                select(0, true);
            } else if (automatic) {
                root.dataset.playerState = 'finished';
                announce('Playlist finished.');
                updateControls();
            }
        };

        media.addEventListener('ended', () => advance(true));
        media.addEventListener('play', () => {
            root.dataset.playerState = 'playing';
            announce('');
            updateControls();
        });
        media.addEventListener('pause', () => {
            if (!media.ended) root.dataset.playerState = 'paused';
            updateControls();
        });
        media.addEventListener('error', () => {
            root.dataset.playerState = 'error';
            announce('This item could not be played. Choose another item or try again.');
            updateControls();
        });
        previous?.addEventListener('click', () => {
            if (index > 0) select(index - 1, true);
            else if (repeat && queue.length > 0) select(queue.length - 1, true);
        });
        next?.addEventListener('click', () => advance(false));
        toggle?.addEventListener('click', () => {
            if (queue.length === 0) return;
            if (index < 0) select(0, true);
            else if (media.paused) {
                if (media.ended) media.currentTime = 0;
                void play();
            } else media.pause();
        });
        repeatButton?.addEventListener('click', () => {
            repeat = !repeat;
            updateControls();
        });
        for (const button of trackButtons) {
            button.addEventListener('click', () => {
                const position = queue.findIndex(track => String(track.id) === button.dataset.trackId);
                if (position >= 0) select(position, true);
            });
        }
        if (queue.length > 0) select(0);
        else {
            if (title) title.textContent = 'No items in this playlist';
            updateControls();
        }
    };

    const saveVisit = async () => {
        const value = name => document.querySelector(`meta[name="${name}"]`)?.content;
        const endpoint = localUrl(value('media-visit-endpoint'));
        const id = value('media-visit-id');
        if (!endpoint || !id) return;
        let browser = 'Unknown';
        if (typeof window.UAParser === 'function') {
            const result = new window.UAParser().getBrowser();
            browser = [result.name, result.version].filter(Boolean).join(' ') || browser;
        }
        let java = false;
        try {
            java = typeof navigator.javaEnabled === 'function' && navigator.javaEnabled();
        } catch {}
        const number = (input, maximum) => Math.max(1, Math.min(maximum, Math.round(Number(input) || 1)));
        try {
            await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    visit_id: id,
                    visit_nonce: value('media-visit-nonce'),
                    csrf: value('csrf-token'),
                    browser: browser.slice(0, 128),
                    screen_width: number(window.screen.width, 65535),
                    screen_height: number(window.screen.height, 65535),
                    viewport_width: number(window.innerWidth, 65535),
                    viewport_height: number(window.innerHeight, 65535),
                    colors: number(window.screen.colorDepth, 64),
                    cookies_enabled: Boolean(navigator.cookieEnabled),
                    java_enabled: Boolean(java),
                    language: (navigator.language || 'und').slice(0, 35)
                })
            });
        } catch {}
    };

    const start = () => {
        document.querySelectorAll('[data-media-player]').forEach(initialize);
        void saveVisit();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
})();
