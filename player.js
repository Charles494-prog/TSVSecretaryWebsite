// A YouTube-style video player for TSV Memories: big centre play button, seek bar with buffered range and
// hover time, volume, speed, picture-in-picture, fullscreen, auto-hiding controls and keyboard shortcuts.
window.TsvPlayer = (() => {
    const svg = (path, fill = true) => `<svg viewBox="0 0 24 24" width="24" height="24" ${fill ? 'fill="currentColor"' : 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'} aria-hidden="true">${path}</svg>`;
    const ICON = {
        play: svg('<path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.2-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14Z"/>'),
        pause: svg('<rect x="6" y="4.5" width="4" height="15" rx="1.2"/><rect x="14" y="4.5" width="4" height="15" rx="1.2"/>'),
        replay: svg('<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>', false),
        volume: svg('<path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/>', false),
        volumeLow: svg('<path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/>', false),
        muted: svg('<path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="m16 9 6 6M22 9l-6 6"/>', false),
        settings: svg('<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>', false),
        pip: svg('<rect x="2" y="4" width="20" height="16" rx="2"/><rect x="12" y="12" width="8" height="6" rx="1" fill="currentColor"/>', false),
        fullscreen: svg('<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>', false),
        exitFullscreen: svg('<path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/>', false),
        back10: svg('<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><text x="12" y="15.5" font-size="7" font-weight="700" text-anchor="middle" fill="currentColor" stroke="none">10</text>', false),
        fwd10: svg('<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/><text x="12" y="15.5" font-size="7" font-weight="700" text-anchor="middle" fill="currentColor" stroke="none">10</text>', false),
        check: svg('<path d="M20 6 9 17l-5-5"/>', false),
        chevron: svg('<path d="m9 18 6-6-6-6"/>', false),
    };
    const SPEEDS = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
    const pad = (n) => String(n).padStart(2, '0');
    const clock = (seconds) => {
        if (!Number.isFinite(seconds)) return '0:00';
        const total = Math.floor(seconds);
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        return h ? `${h}:${pad(m)}:${pad(total % 60)}` : `${m}:${pad(total % 60)}`;
    };
    const stored = (key, fallback) => {
        try {
            const value = localStorage.getItem(key);
            return value === null ? fallback : value;
        } catch {
            return fallback;
        }
    };
    const store = (key, value) => {
        try {
            localStorage.setItem(key, String(value));
        } catch {
            // Remembering the volume is only a convenience.
        }
    };
    const qualityLabel = (width, height) => {
        const lines = Math.min(width || 0, height || 0);
        if (!lines) return 'Original';
        const named = lines >= 2160 ? '4K' : lines >= 1440 ? '1440p' : lines >= 1080 ? '1080p HD' : lines >= 720 ? '720p HD' : `${lines}p`;
        return `Original · ${named}`;
    };

    // Only one TSV video plays at a time, like a social media feed.
    document.addEventListener('play', (event) => {
        document.querySelectorAll('.vplayer video').forEach((video) => {
            if (video !== event.target) video.pause();
        });
    }, true);

    function create({ src, poster, width, height, duration, autoplay = false, label = 'Video' }) {
        const root = document.createElement('div');
        root.className = 'vplayer is-paused is-fresh';
        root.tabIndex = 0;
        root.setAttribute('role', 'region');
        root.setAttribute('aria-label', label);
        if (width && height) root.style.setProperty('--ratio', `${width} / ${height}`);
        root.innerHTML = `
            <video playsinline preload="metadata"></video>
            <div class="vplayer-shade"></div>
            <button type="button" class="vplayer-big" aria-label="Play">${ICON.play}</button>
            <div class="vplayer-spinner" aria-hidden="true"></div>
            <div class="vplayer-flash" aria-hidden="true"></div>
            <div class="vplayer-seek-hint vplayer-seek-back" aria-hidden="true">${ICON.back10}<span>10 seconds</span></div>
            <div class="vplayer-seek-hint vplayer-seek-fwd" aria-hidden="true">${ICON.fwd10}<span>10 seconds</span></div>
            <div class="vplayer-duration">${clock(duration)}</div>
            <div class="vplayer-controls">
                <div class="vplayer-progress" role="slider" aria-label="Seek" aria-valuemin="0" tabindex="-1">
                    <div class="vplayer-track">
                        <div class="vplayer-buffered"></div>
                        <div class="vplayer-hover"></div>
                        <div class="vplayer-played"></div>
                    </div>
                    <div class="vplayer-knob"></div>
                    <div class="vplayer-tooltip">0:00</div>
                </div>
                <div class="vplayer-bar">
                    <button type="button" class="vplayer-btn" data-act="toggle" aria-label="Play (k)">${ICON.play}</button>
                    <div class="vplayer-volume">
                        <button type="button" class="vplayer-btn" data-act="mute" aria-label="Mute (m)">${ICON.volume}</button>
                        <input type="range" min="0" max="1" step="0.01" aria-label="Volume">
                    </div>
                    <span class="vplayer-time"><span data-current>0:00</span> / <span data-total>${clock(duration)}</span></span>
                    <span class="vplayer-spacer"></span>
                    <div class="vplayer-menu-wrap">
                        <button type="button" class="vplayer-btn" data-act="settings" aria-label="Settings" aria-haspopup="true">${ICON.settings}</button>
                        <div class="vplayer-menu" hidden></div>
                    </div>
                    <button type="button" class="vplayer-btn" data-act="pip" aria-label="Picture-in-picture">${ICON.pip}</button>
                    <button type="button" class="vplayer-btn" data-act="fullscreen" aria-label="Full screen (f)">${ICON.fullscreen}</button>
                </div>
            </div>`;

        const video = root.querySelector('video');
        const $ = (selector) => root.querySelector(selector);
        const progress = $('.vplayer-progress');
        const menu = $('.vplayer-menu');
        const volumeInput = $('.vplayer-volume input');
        if (poster) video.poster = poster;
        // Sound on/off and volume are one setting shared by every video: each video applies the latest
        // choice when it starts, so muting one keeps the next ones muted, and unmuting does the same.
        let autoMuted = false;
        const applySoundSetting = () => {
            autoMuted = false;
            video.volume = Math.min(1, Math.max(0, Number(stored('tsvPlayerVolume', '1'))));
            video.muted = stored('tsvPlayerMuted', '0') === '1';
        };
        applySoundSetting();
        document.addEventListener('tsv-sound', () => {
            if (video.paused) applySoundSetting();
        });
        if (!document.pictureInPictureEnabled) $('[data-act="pip"]').hidden = true;

        let loaded = false;
        const load = () => {
            if (!loaded) {
                loaded = true;
                video.src = src;
            }
        };
        const play = () => {
            load();
            applySoundSetting();
            return video.play().catch(() => {
                // The browser can block sound until the page has been clicked; start muted rather than not at all,
                // without saving that as the viewer's choice.
                if (!video.muted) {
                    autoMuted = true;
                    video.muted = true;
                    return video.play().catch(() => {});
                }
                return undefined;
            });
        };
        const toggle = () => (video.paused || video.ended ? play() : video.pause());

        // ---- auto-hiding controls ----
        let hideTimer = 0;
        const wake = () => {
            root.classList.add('is-active');
            clearTimeout(hideTimer);
            hideTimer = setTimeout(() => {
                if (!video.paused && menu.hidden && !root.classList.contains('is-scrubbing')) {
                    root.classList.remove('is-active');
                }
            }, 2600);
        };
        root.addEventListener('pointermove', wake);
        root.addEventListener('pointerdown', wake);
        root.addEventListener('focusin', wake);
        root.addEventListener('pointerleave', () => {
            if (!video.paused && menu.hidden) root.classList.remove('is-active');
        });

        const flash = (icon) => {
            const node = $('.vplayer-flash');
            node.innerHTML = icon;
            node.classList.remove('is-on');
            void node.offsetWidth;
            node.classList.add('is-on');
        };
        const seekBy = (seconds) => {
            if (!Number.isFinite(video.duration)) return;
            video.currentTime = Math.min(video.duration, Math.max(0, video.currentTime + seconds));
            const hint = $(seconds < 0 ? '.vplayer-seek-back' : '.vplayer-seek-fwd');
            hint.classList.remove('is-on');
            void hint.offsetWidth;
            hint.classList.add('is-on');
            wake();
        };

        // ---- state → UI ----
        const setButton = (act, icon, labelText) => {
            const button = $(`[data-act="${act}"]`);
            button.innerHTML = icon;
            button.setAttribute('aria-label', labelText);
        };
        const syncPlay = () => {
            const paused = video.paused || video.ended;
            root.classList.toggle('is-paused', paused);
            root.classList.toggle('is-ended', video.ended);
            setButton('toggle', video.ended ? ICON.replay : paused ? ICON.play : ICON.pause, paused ? 'Play (k)' : 'Pause (k)');
            const big = $('.vplayer-big');
            big.innerHTML = video.ended ? ICON.replay : ICON.play;
            big.setAttribute('aria-label', video.ended ? 'Replay' : 'Play');
            if (paused) root.classList.add('is-active');
            else wake();
        };
        const syncVolume = () => {
            const level = video.muted ? 0 : video.volume;
            volumeInput.value = String(level);
            volumeInput.style.setProperty('--level', `${level * 100}%`);
            setButton('mute', level === 0 ? ICON.muted : level < 0.5 ? ICON.volumeLow : ICON.volume, video.muted ? 'Unmute (m)' : 'Mute (m)');
        };
        // Saved only when the viewer changes the sound (not when the browser forced a muted start).
        const saveSound = () => {
            autoMuted = false;
            store('tsvPlayerVolume', video.volume);
            store('tsvPlayerMuted', video.muted ? '1' : '0');
            document.dispatchEvent(new CustomEvent('tsv-sound'));
        };
        const syncTime = () => {
            const total = Number.isFinite(video.duration) ? video.duration : duration || 0;
            const fraction = total ? video.currentTime / total : 0;
            root.style.setProperty('--played', `${fraction * 100}%`);
            $('[data-current]').textContent = clock(video.currentTime);
            $('[data-total]').textContent = clock(total);
            progress.setAttribute('aria-valuemax', String(Math.round(total)));
            progress.setAttribute('aria-valuenow', String(Math.round(video.currentTime)));
            progress.setAttribute('aria-valuetext', `${clock(video.currentTime)} of ${clock(total)}`);
        };
        const syncBuffered = () => {
            const total = video.duration;
            if (!Number.isFinite(total) || !video.buffered.length) return;
            let end = 0;
            for (let i = 0; i < video.buffered.length; i++) {
                if (video.buffered.start(i) <= video.currentTime + 0.5) end = Math.max(end, video.buffered.end(i));
            }
            root.style.setProperty('--buffered', `${(end / total) * 100}%`);
        };

        video.addEventListener('play', () => {
            root.classList.remove('is-fresh');
            syncPlay();
        });
        video.addEventListener('pause', syncPlay);
        video.addEventListener('ended', syncPlay);
        video.addEventListener('timeupdate', syncTime);
        video.addEventListener('durationchange', syncTime);
        video.addEventListener('progress', syncBuffered);
        video.addEventListener('timeupdate', syncBuffered);
        video.addEventListener('volumechange', syncVolume);
        video.addEventListener('waiting', () => root.classList.add('is-loading'));
        ['playing', 'canplay', 'pause', 'error'].forEach((type) => video.addEventListener(type, () => root.classList.remove('is-loading')));
        video.addEventListener('error', () => {
            if (video.error) root.classList.add('is-error');
        });
        video.addEventListener('ratechange', () => renderMenu(menu.dataset.page || 'main'));

        // ---- clicks on the picture: play/pause, double-click for full screen, double-tap sides to skip 10 s ----
        let clickTimer = 0;
        const surfaceClick = (event) => {
            if (event.target.closest('.vplayer-controls, .vplayer-big')) return;
            if (!menu.hidden) {
                menu.hidden = true;
                return;
            }
            if (event.pointerType === 'touch' || event.detail === 0) {
                // On touch, a tap only shows the controls; double-tapping the sides skips.
                if (!root.classList.contains('is-active') && !video.paused) {
                    wake();
                    return;
                }
            }
            clearTimeout(clickTimer);
            clickTimer = setTimeout(() => {
                const wasPaused = video.paused || video.ended;
                toggle();
                if (!root.classList.contains('is-fresh')) flash(wasPaused ? ICON.play : ICON.pause);
            }, 220);
        };
        root.addEventListener('click', surfaceClick);
        root.addEventListener('dblclick', (event) => {
            if (event.target.closest('.vplayer-controls')) return;
            clearTimeout(clickTimer);
            const box = root.getBoundingClientRect();
            const x = (event.clientX - box.left) / box.width;
            if (event.pointerType === 'touch' || matchMedia('(pointer: coarse)').matches) {
                if (x < 0.35) return seekBy(-10);
                if (x > 0.65) return seekBy(10);
            }
            toggleFullscreen();
            return undefined;
        });
        $('.vplayer-big').addEventListener('click', (event) => {
            event.stopPropagation();
            if (video.ended) video.currentTime = 0;
            play();
            root.focus({ preventScroll: true });
        });

        // ---- seek bar ----
        const fractionAt = (event) => {
            const box = progress.getBoundingClientRect();
            return Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));
        };
        const totalTime = () => (Number.isFinite(video.duration) ? video.duration : duration || 0);
        progress.addEventListener('pointermove', (event) => {
            const fraction = fractionAt(event);
            root.style.setProperty('--hover', `${fraction * 100}%`);
            const tooltip = $('.vplayer-tooltip');
            tooltip.textContent = clock(fraction * totalTime());
            const box = progress.getBoundingClientRect();
            const half = tooltip.offsetWidth / 2;
            tooltip.style.left = `${Math.min(box.width - half, Math.max(half, fraction * box.width))}px`;
        });
        progress.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) return;
            load();
            progress.setPointerCapture(event.pointerId);
            root.classList.add('is-scrubbing');
            const wasPlaying = !video.paused;
            video.pause();
            const move = (e) => {
                const fraction = fractionAt(e);
                root.style.setProperty('--played', `${fraction * 100}%`);
                $('[data-current]').textContent = clock(fraction * totalTime());
                if (Number.isFinite(video.duration)) video.currentTime = fraction * video.duration;
            };
            move(event);
            const up = (e) => {
                move(e);
                root.classList.remove('is-scrubbing');
                progress.removeEventListener('pointermove', move);
                progress.removeEventListener('pointerup', up);
                progress.removeEventListener('pointercancel', up);
                if (wasPlaying) play();
            };
            progress.addEventListener('pointermove', move);
            progress.addEventListener('pointerup', up);
            progress.addEventListener('pointercancel', up);
        });

        // ---- volume ----
        volumeInput.addEventListener('input', () => {
            video.volume = Number(volumeInput.value);
            video.muted = video.volume === 0;
            saveSound();
        });
        const toggleMute = () => {
            if (video.muted || video.volume === 0) {
                video.muted = false;
                if (video.volume === 0) video.volume = 0.5;
            } else {
                video.muted = true;
            }
            saveSound();
        };

        // ---- full screen and picture-in-picture ----
        const toggleFullscreen = () => {
            if (document.fullscreenElement === root) {
                document.exitFullscreen();
            } else if (root.requestFullscreen) {
                root.requestFullscreen().catch(() => {});
            } else if (video.webkitEnterFullscreen) {
                video.webkitEnterFullscreen();
            }
        };
        document.addEventListener('fullscreenchange', () => {
            const full = document.fullscreenElement === root;
            root.classList.toggle('is-fullscreen', full);
            setButton('fullscreen', full ? ICON.exitFullscreen : ICON.fullscreen, full ? 'Exit full screen (f)' : 'Full screen (f)');
        });
        const togglePip = async () => {
            try {
                if (document.pictureInPictureElement === video) await document.exitPictureInPicture();
                else {
                    load();
                    await video.requestPictureInPicture();
                }
            } catch {
                // Not available for this video.
            }
        };

        // ---- settings menu: playback speed and quality ----
        function renderMenu(page) {
            menu.dataset.page = page;
            const speedText = video.playbackRate === 1 ? 'Normal' : `${video.playbackRate}×`;
            if (page === 'speed') {
                menu.innerHTML = `<button type="button" class="vplayer-menu-back" data-page="main">${ICON.chevron}<span>Playback speed</span></button>`
                    + SPEEDS.map((speed) => `<button type="button" class="vplayer-menu-item${speed === video.playbackRate ? ' is-on' : ''}" data-speed="${speed}"><span class="vplayer-menu-check">${speed === video.playbackRate ? ICON.check : ''}</span><span>${speed === 1 ? 'Normal' : speed}</span></button>`).join('');
            } else if (page === 'quality') {
                menu.innerHTML = `<button type="button" class="vplayer-menu-back" data-page="main">${ICON.chevron}<span>Quality</span></button>`
                    + `<button type="button" class="vplayer-menu-item is-on"><span class="vplayer-menu-check">${ICON.check}</span><span>${qualityLabel(video.videoWidth || width, video.videoHeight || height)}</span></button>`
                    + '<p class="vplayer-menu-note">Videos play at the quality they were uploaded in.</p>';
            } else {
                menu.innerHTML = `<button type="button" class="vplayer-menu-row" data-page="speed"><span>Playback speed</span><span class="vplayer-menu-value">${speedText}${ICON.chevron}</span></button>`
                    + `<button type="button" class="vplayer-menu-row" data-page="quality"><span>Quality</span><span class="vplayer-menu-value">${qualityLabel(video.videoWidth || width, video.videoHeight || height).replace('Original · ', '')}${ICON.chevron}</span></button>`;
            }
        }
        menu.addEventListener('click', (event) => {
            event.stopPropagation();
            const target = event.target.closest('button');
            if (!target) return;
            if (target.dataset.page) renderMenu(target.dataset.page);
            if (target.dataset.speed) {
                video.playbackRate = Number(target.dataset.speed);
                menu.hidden = true;
            }
        });

        // ---- control bar buttons ----
        root.querySelector('.vplayer-bar').addEventListener('click', (event) => {
            const button = event.target.closest('[data-act]');
            if (!button) return;
            event.stopPropagation();
            const act = button.dataset.act;
            if (act === 'toggle') toggle();
            if (act === 'mute') toggleMute();
            if (act === 'fullscreen') toggleFullscreen();
            if (act === 'pip') togglePip();
            if (act === 'settings') {
                renderMenu('main');
                menu.hidden = !menu.hidden;
                wake();
            }
        });
        document.addEventListener('click', (event) => {
            if (!menu.hidden && !root.contains(event.target)) menu.hidden = true;
        });

        // ---- keyboard shortcuts (YouTube's) while the player has focus ----
        root.addEventListener('keydown', (event) => {
            if (event.target === volumeInput && ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
            if (event.ctrlKey || event.metaKey || event.altKey) return;
            const key = event.key;
            let handled = true;
            if (key === ' ' || key === 'k' || key === 'K') {
                if (event.target.closest('button') && key === ' ') return;
                const wasPaused = video.paused || video.ended;
                toggle();
                flash(wasPaused ? ICON.play : ICON.pause);
            } else if (key === 'j' || key === 'J') seekBy(-10);
            else if (key === 'l' || key === 'L') seekBy(10);
            else if (key === 'ArrowLeft') seekBy(-5);
            else if (key === 'ArrowRight') seekBy(5);
            else if (key === 'ArrowUp') video.volume = Math.min(1, video.volume + 0.05), video.muted = false, saveSound();
            else if (key === 'ArrowDown') video.volume = Math.max(0, video.volume - 0.05), saveSound();
            else if (key === 'm' || key === 'M') toggleMute();
            else if (key === 'f' || key === 'F') toggleFullscreen();
            else if (key === 'i' || key === 'I') togglePip();
            else if (key === '>' ) video.playbackRate = SPEEDS[Math.min(SPEEDS.length - 1, SPEEDS.indexOf(video.playbackRate) + 1)] || 1;
            else if (key === '<') video.playbackRate = SPEEDS[Math.max(0, SPEEDS.indexOf(video.playbackRate) - 1)] || 1;
            else if (/^[0-9]$/.test(key) && Number.isFinite(video.duration)) video.currentTime = (Number(key) / 10) * video.duration;
            else if (key === 'Home') video.currentTime = 0;
            else if (key === 'End' && Number.isFinite(video.duration)) video.currentTime = video.duration;
            else if (key === 'Escape' && !menu.hidden) menu.hidden = true;
            else handled = false;
            if (handled) {
                event.preventDefault();
                event.stopPropagation();
                if (key.startsWith('Arrow') && (key === 'ArrowUp' || key === 'ArrowDown')) flash(video.muted || video.volume === 0 ? ICON.muted : ICON.volume);
                load();
                wake();
            }
        });

        syncVolume();
        syncTime();
        syncPlay();
        root.classList.add('is-active');
        if (autoplay) play();

        return {
            element: root,
            video,
            play,
            pause: () => video.pause(),
            destroy() {
                clearTimeout(hideTimer);
                video.pause();
                video.removeAttribute('src');
                video.load();
                if (document.pictureInPictureElement === video) document.exitPictureInPicture().catch(() => {});
            },
        };
    }

    return { create, clock };
})();
