// TSV Memories: a feed of activity photos with timeline, gallery and calendar views.
(() => {
    const root = document.querySelector('[data-memories]');
    const dataElement = document.querySelector('#memories-data');
    if (!root || !dataElement) {
        return;
    }
    const data = JSON.parse(dataElement.textContent);
    const csrf = data.csrf;
    const today = data.today;
    let posts = data.posts;

    const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const PHOTO_MAX_EDGE = 1600;
    const PHOTO_MAX_BASE64 = 700 * 1024;
    const THUMB_EDGE = 480;
    const POSTER_EDGE = 1280;
    const POSTER_MAX_BASE64 = 230 * 1024;
    const VIDEO_MAX_BYTES = data.videoMaxBytes || 100 * 1024 * 1024;
    const isVideoFile = (file) => file.type.startsWith('video/') || /\.(mp4|m4v|mov|webm|ogv)$/i.test(file.name);
    const videoMime = (file) => file.type || ({ mov: 'video/quicktime', webm: 'video/webm', ogv: 'video/ogg' }[file.name.split('.').pop().toLowerCase()] ?? 'video/mp4');
    const clock = (seconds) => {
        const total = Math.round(seconds || 0);
        return `${Math.floor(total / 60)}:${pad(total % 60)}`;
    };
    // "3 photos · 1 video"
    const mediaCount = (items) => {
        const videos = items.filter((item) => item.video).length;
        const photos = items.length - videos;
        return [photos && `${photos} ${photos === 1 ? 'photo' : 'photos'}`, videos && `${videos} ${videos === 1 ? 'video' : 'videos'}`].filter(Boolean).join(' · ') || 'No photos';
    };

    const viewHost = root.querySelector('[data-memories-view]');
    const yearSelect = root.querySelector('[data-year-filter]');
    const onThisDay = root.querySelector('[data-on-this-day]');
    const viewButtons = root.querySelectorAll('[data-view]');

    const remembered = (key, fallback) => {
        try {
            return localStorage.getItem(key) || fallback;
        } catch {
            return fallback;
        }
    };
    const remember = (key, value) => {
        try {
            localStorage.setItem(key, value);
        } catch {
            // Remembering the view is only a convenience.
        }
    };

    let view = remembered('tsvMemoriesView', 'timeline');
    let year = '';
    let calendarCursor = null;
    let calendarDay = null;

    // ---------- helpers ----------
    const el = (tag, className = '', text = '') => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    };
    const pad = (n) => String(n).padStart(2, '0');
    const parts = (iso) => iso.split('-').map(Number);
    const longDate = (iso) => {
        const [y, m, d] = parts(iso);
        return `${MONTHS[m - 1]} ${pad(d)}, ${y}`;
    };
    const weekday = (iso) => {
        const [y, m, d] = parts(iso);
        return WEEKDAYS[new Date(y, m - 1, d).getDay()];
    };
    const photoUrl = (id, size = 'full') => `index.php?action=memory_photo&id=${id}${size === 'thumb' ? '&size=thumb' : ''}`;
    const videoUrl = (id) => `index.php?action=memory_video&id=${id}`;
    // A play badge with the length, laid over video thumbnails.
    const playBadge = (item, large = false) => {
        const badge = el('span', `play-badge${large ? ' is-large' : ''}`);
        badge.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5Z"/></svg>';
        if (item.duration) badge.append(el('span', '', clock(item.duration)));
        return badge;
    };
    const filtered = () => posts.filter((post) => !year || post.date.startsWith(year));
    const icon = (path) => `<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;
    const ICONS = {
        more: icon('<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>'),
        pen: icon('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>'),
        trash: icon('<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/>'),
        left: icon('<path d="m15 18-6-6 6-6"/>'),
        right: icon('<path d="m9 18 6-6-6-6"/>'),
        x: icon('<path d="M18 6 6 18M6 6l12 12"/>'),
        image: icon('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>'),
    };

    async function post(action, fields) {
        const body = new FormData();
        body.set('csrf_token', csrf);
        body.set('action', action);
        Object.entries(fields).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((item) => body.append(`${key}[]`, item));
            } else if (value instanceof Blob) {
                body.set(key, value, 'piece.bin');
            } else {
                body.set(key, value);
            }
        });
        let response;
        try {
            response = await fetch('index.php', { method: 'POST', body, headers: { Accept: 'application/json' } });
        } catch {
            throw new Error('The server could not be reached. Is Apache running?');
        }
        const result = await response.json().catch(() => ({}));
        if (!response.ok || result.success === false) {
            throw new Error(result.message || 'Something went wrong. Please try again.');
        }
        return result;
    }

    async function reload() {
        const response = await fetch('index.php?action=memories_data', { headers: { Accept: 'application/json' }, cache: 'no-store' });
        posts = (await response.json()).posts;
        render();
    }

    // ---------- toolbar ----------
    function renderYears() {
        const years = [...new Set(posts.map((p) => p.date.slice(0, 4)))].sort().reverse();
        if (year && !years.includes(year)) {
            year = '';
        }
        yearSelect.replaceChildren(new Option('All years', ''), ...years.map((y) => new Option(y, y, false, y === year)));
        yearSelect.value = year;
    }
    const transition = (update) => {
        if (document.startViewTransition && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            document.startViewTransition(update);
        } else {
            update();
        }
    };
    yearSelect.addEventListener('change', () => {
        year = yearSelect.value;
        journeyScroll = null;
        calendarCursor = null;
        calendarDay = null;
        transition(render);
    });
    viewButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (button.dataset.view === view) return;
            view = button.dataset.view;
            remember('tsvMemoriesView', view);
            transition(render);
        });
    });

    // ---------- photo grid inside a post (Facebook-style collage) ----------
    function collage(postData, items) {
        const photos = postData.photos;
        const grid = el('div', `collage collage-${Math.min(photos.length, 5)}`);
        const shown = photos.slice(0, 5);
        if (photos.length === 1 && photos[0].video) {
            const photo = photos[0];
            const player = TsvPlayer.create({
                src: videoUrl(photo.id),
                poster: photoUrl(photo.id),
                width: photo.w,
                height: photo.h,
                duration: photo.duration,
                label: postData.caption ? `Video: ${postData.caption.slice(0, 80)}` : `Video from ${longDate(postData.date)}`,
            });
            player.element.classList.add('vplayer-inline');
            // Like Facebook: wide videos show whole, very tall ones are cropped to 4:5 until full screen.
            player.element.style.setProperty('--ratio', String(Math.min(2, Math.max(0.8, photo.w / photo.h))));
            grid.append(player.element);
            return grid;
        }
        shown.forEach((photo, index) => {
            const button = el('button', 'collage-item');
            button.type = 'button';
            button.setAttribute('aria-label', `Open ${photo.video ? 'video' : 'photo'} ${index + 1} of ${photos.length}`);
            const img = el('img');
            img.loading = 'lazy';
            img.decoding = 'async';
            img.alt = postData.caption ? postData.caption.slice(0, 120) : `Memory from ${longDate(postData.date)}`;
            // A single photo shows at its own shape; collages use the thumbnails.
            img.src = photos.length === 1 ? photoUrl(photo.id) : photoUrl(photo.id, 'thumb');
            if (photos.length === 1) {
                // Like Facebook: wide photos show whole, very tall ones are cropped to 4:5.
                button.style.aspectRatio = String(Math.min(2, Math.max(0.8, photo.w / photo.h)));
            }
            button.append(img);
            if (photo.video && !(index === 4 && photos.length > 5)) {
                button.append(playBadge(photo, true));
            }
            if (index === 4 && photos.length > 5) {
                button.append(el('span', 'collage-more', `+${photos.length - 5}`));
            }
            button.addEventListener('click', () => openLightbox(items, items.findIndex((item) => item.photo.id === photo.id)));
            grid.append(button);
        });
        return grid;
    }

    function postCard(postData, scopeItems) {
        const card = el('article', 'memory-card');
        const head = el('header', 'memory-card-head');
        const badge = el('div', 'memory-date-badge');
        const [, m, d] = parts(postData.date);
        badge.append(el('span', '', MONTHS[m - 1].slice(0, 3)), el('strong', '', String(d)));
        const title = el('div', 'memory-card-title');
        title.append(el('strong', '', longDate(postData.date)));
        const sub = el('span', 'muted', `${weekday(postData.date)} · ${mediaCount(postData.photos)}${postData.edited ? ' · Edited' : ''}`);
        title.append(sub);
        const menu = el('div', 'memory-menu');
        const menuButton = el('button', 'icon-button');
        menuButton.type = 'button';
        menuButton.setAttribute('aria-label', 'Post options');
        menuButton.setAttribute('aria-haspopup', 'true');
        menuButton.innerHTML = ICONS.more;
        const list = el('div', 'memory-menu-list');
        list.hidden = true;
        const edit = el('button', '', '');
        edit.type = 'button';
        edit.innerHTML = `${ICONS.pen}<span>Edit post</span>`;
        edit.addEventListener('click', () => {
            list.hidden = true;
            openComposer(postData);
        });
        const remove = el('button', 'is-danger', '');
        remove.type = 'button';
        remove.innerHTML = `${ICONS.trash}<span>Delete post</span>`;
        remove.addEventListener('click', () => {
            list.hidden = true;
            deletePost(postData);
        });
        list.append(edit, remove);
        menuButton.addEventListener('click', (event) => {
            event.stopPropagation();
            document.querySelectorAll('.memory-menu-list').forEach((other) => {
                if (other !== list) other.hidden = true;
            });
            list.hidden = !list.hidden;
        });
        menu.append(menuButton, list);
        head.append(badge, title, menu);
        card.append(head);
        if (postData.caption) {
            card.append(el('p', 'memory-caption', postData.caption));
        }
        if (postData.photos.length) {
            const items = scopeItems || postData.photos.map((photo) => ({ post: postData, photo }));
            card.append(collage(postData, items));
        }
        return card;
    }
    document.addEventListener('click', () => {
        document.querySelectorAll('.memory-menu-list').forEach((list) => {
            list.hidden = true;
        });
    });

    function emptyState(message) {
        const box = el('div', 'memories-empty');
        const art = el('div', 'memories-empty-icon');
        art.innerHTML = ICONS.image;
        const button = el('button', 'button', 'New memory');
        button.type = 'button';
        button.addEventListener('click', () => openComposer());
        box.append(art, el('strong', '', message), el('p', 'muted', 'Photos and videos you add are kept here by date and saved in your Export SQL backups.'), button);
        return box;
    }

    const byMonth = (list) => {
        const groups = new Map();
        list.forEach((item) => {
            const key = (item.date || item.post.date).slice(0, 7);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(item);
        });
        return groups;
    };
    const monthLabel = (key) => `${MONTHS[Number(key.slice(5, 7)) - 1]} ${key.slice(0, 4)}`;

    // ---------- views ----------
    function renderTimeline(list) {
        const wrap = el('div', 'memories-timeline');
        byMonth(list).forEach((group, key) => {
            const section = el('section', 'timeline-month');
            const heading = el('h2', 'timeline-month-label');
            heading.append(el('span', '', monthLabel(key)), el('span', 'muted', `${group.length} ${group.length === 1 ? 'memory' : 'memories'}`));
            section.append(heading);
            group.forEach((postData) => section.append(postCard(postData)));
            wrap.append(section);
        });
        return wrap;
    }

    function renderGallery(list) {
        const wrap = el('div', 'memories-gallery');
        const items = list.flatMap((postData) => postData.photos.map((photo) => ({ post: postData, photo })));
        byMonth(items).forEach((group, key) => {
            const section = el('section', 'gallery-month');
            const heading = el('h2', 'timeline-month-label');
            heading.append(el('span', '', monthLabel(key)), el('span', 'muted', mediaCount(group.map((item) => item.photo))));
            const grid = el('div', 'gallery-grid');
            group.forEach((item) => {
                const button = el('button', 'gallery-item');
                button.type = 'button';
                button.title = `${longDate(item.post.date)}${item.post.caption ? ' — ' + item.post.caption.slice(0, 80) : ''}`;
                const img = el('img');
                img.loading = 'lazy';
                img.decoding = 'async';
                img.alt = item.post.caption ? item.post.caption.slice(0, 120) : `Memory from ${longDate(item.post.date)}`;
                img.src = photoUrl(item.photo.id, 'thumb');
                button.append(img);
                if (item.photo.video) button.append(playBadge(item.photo, true));
                button.addEventListener('click', () => openLightbox(items, items.indexOf(item)));
                grid.append(button);
            });
            section.append(heading, grid);
            wrap.append(section);
        });
        return wrap;
    }

    function renderCalendar(list) {
        const wrap = el('div', 'memories-calendar');
        const byDay = new Map();
        list.forEach((postData) => {
            if (!byDay.has(postData.date)) byDay.set(postData.date, []);
            byDay.get(postData.date).push(postData);
        });
        if (!calendarCursor) {
            // Start on the newest month that has memories (within the chosen year), or this month.
            const latest = list[0]?.date || (year ? `${year}-12-01` : today);
            calendarCursor = latest.slice(0, 7);
        }
        const [cy, cm] = calendarCursor.split('-').map(Number);
        const head = el('div', 'calendar-head');
        const prev = el('button', 'icon-button');
        prev.type = 'button';
        prev.setAttribute('aria-label', 'Previous month');
        prev.innerHTML = ICONS.left;
        const next = el('button', 'icon-button');
        next.type = 'button';
        next.setAttribute('aria-label', 'Next month');
        next.innerHTML = ICONS.right;
        const step = (delta) => {
            const date = new Date(cy, cm - 1 + delta, 1);
            calendarCursor = `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
            calendarDay = null;
            render();
        };
        prev.addEventListener('click', () => step(-1));
        next.addEventListener('click', () => step(1));
        const monthCount = list.filter((p) => p.date.startsWith(calendarCursor)).length;
        const label = el('div', 'calendar-label');
        label.append(el('strong', '', `${MONTHS[cm - 1]} ${cy}`), el('span', 'muted', monthCount ? `${monthCount} ${monthCount === 1 ? 'memory' : 'memories'}` : 'No memories this month'));
        head.append(prev, label, next);

        const grid = el('div', 'calendar-grid');
        WEEKDAYS.forEach((day) => grid.append(el('span', 'calendar-weekday', day)));
        const first = new Date(cy, cm - 1, 1);
        const daysInMonth = new Date(cy, cm, 0).getDate();
        for (let i = 0; i < first.getDay(); i++) {
            grid.append(el('span', 'calendar-cell is-blank'));
        }
        for (let day = 1; day <= daysInMonth; day++) {
            const iso = `${cy}-${pad(cm)}-${pad(day)}`;
            const dayPosts = byDay.get(iso) || [];
            const photos = dayPosts.flatMap((p) => p.photos);
            const cell = el(dayPosts.length ? 'button' : 'span', 'calendar-cell');
            if (iso === today) cell.classList.add('is-today');
            if (iso === calendarDay) cell.classList.add('is-selected');
            cell.append(el('span', 'calendar-day', String(day)));
            if (dayPosts.length) {
                cell.type = 'button';
                cell.classList.add('has-memories');
                cell.setAttribute('aria-label', `${longDate(iso)}: ${dayPosts.length} ${dayPosts.length === 1 ? 'memory' : 'memories'}`);
                const covers = dayPosts.map((p) => p.photos[0]).filter(Boolean).slice(0, 4);
                if (covers.length) {
                    const mosaic = el('span', `calendar-mosaic calendar-mosaic-${covers.length}`);
                    covers.forEach((photo) => {
                        const img = el('img');
                        img.loading = 'lazy';
                        img.alt = '';
                        img.src = photoUrl(photo.id, 'thumb');
                        mosaic.append(img);
                    });
                    cell.prepend(mosaic);
                }
                if (photos.some((photo) => photo.video)) {
                    cell.append(playBadge({}, true));
                }
                if (dayPosts.length > 1) {
                    cell.append(el('span', 'calendar-count', `${dayPosts.length} posts`));
                } else if (photos.length > 1) {
                    cell.append(el('span', 'calendar-count', String(photos.length)));
                }
                cell.addEventListener('click', () => {
                    calendarDay = iso;
                    cell.closest('.calendar-grid')?.querySelectorAll('.is-selected').forEach((other) => other.classList.remove('is-selected'));
                    cell.classList.add('is-selected');
                    // One post opens straight into the viewer; several posts open a panel listing every one.
                    if (dayPosts.length > 1) {
                        openFocus(dayPosts, cell);
                    } else {
                        const dayItems = dayPosts[0].photos.map((photo) => ({ post: dayPosts[0], photo }));
                        if (dayItems.length) openLightbox(dayItems, 0);
                    }
                });
            }
            grid.append(cell);
        }
        wrap.append(head, grid);
        return wrap;
    }

    // "On this day": memories from today's date in earlier years, like Facebook Memories.
    function renderOnThisDay() {
        const monthDay = today.slice(5);
        const matches = posts.filter((p) => p.date.slice(5) === monthDay && p.date < today.slice(0, 4) && p.photos.length);
        onThisDay.hidden = matches.length === 0 || Boolean(year);
        onThisDay.replaceChildren();
        if (onThisDay.hidden) {
            return;
        }
        const heading = el('div', 'onthisday-head');
        heading.append(el('strong', '', 'On this day'), el('span', 'muted', longDate(today).replace(/, \d{4}$/, '') + ' in earlier years'));
        const row = el('div', 'onthisday-row');
        matches.forEach((postData) => {
            const items = postData.photos.map((photo) => ({ post: postData, photo }));
            const yearsAgo = Number(today.slice(0, 4)) - Number(postData.date.slice(0, 4));
            const button = el('button', 'onthisday-card');
            button.type = 'button';
            const img = el('img');
            img.alt = '';
            img.src = photoUrl(postData.photos[0].id, 'thumb');
            const label = el('span', 'onthisday-label');
            label.append(el('strong', '', `${yearsAgo} ${yearsAgo === 1 ? 'year' : 'years'} ago`), el('span', '', postData.caption || longDate(postData.date)));
            button.append(img, label);
            if (postData.photos[0].video) button.append(playBadge(postData.photos[0], true));
            button.addEventListener('click', () => openLightbox(items, 0));
            row.append(button);
        });
        onThisDay.append(heading, row);
    }

    // ---------- horizontal timeline ("Horizontal" view) ----------
    // Memories run left (oldest) to right (newest) along a line. Resting the mouse near either side glides
    // the line that way, faster the closer to the edge. Clicking a memory zooms it open.
    let journeyCleanup = null;
    let journeyScroll = null;

    function journeyCard(postData, index) {
        const card = el('article', 'journey-card');
        card.tabIndex = 0;
        card.setAttribute('role', 'button');
        card.setAttribute('aria-label', `Open the memory from ${longDate(postData.date)}`);
        card.style.setProperty('--i', String(Math.min(index, 14)));
        const when = el('header', 'journey-when');
        when.append(el('strong', '', longDate(postData.date)), el('span', 'muted', `${weekday(postData.date)} · ${mediaCount(postData.photos)}`));
        card.append(when);
        if (postData.photos.length) {
            const media = el('div', `journey-media journey-media-${Math.min(postData.photos.length, 4)}`);
            postData.photos.slice(0, 4).forEach((photo, i) => {
                const tile = el('div', 'journey-tile');
                const img = el('img');
                img.loading = 'lazy';
                img.decoding = 'async';
                img.alt = '';
                img.draggable = false;
                img.src = photoUrl(photo.id, 'thumb');
                tile.append(img);
                if (photo.video) tile.append(playBadge(photo, true));
                if (i === 3 && postData.photos.length > 4) tile.append(el('span', 'collage-more', `+${postData.photos.length - 4}`));
                media.append(tile);
            });
            card.append(media);
        }
        if (postData.caption) card.append(el('p', 'journey-caption', postData.caption));
        return card;
    }

    function renderJourney(list) {
        const ordered = [...list].sort((a, b) => a.date.localeCompare(b.date) || a.id - b.id);
        const wrap = el('div', 'journey');
        const scroller = el('div', 'journey-scroller');
        scroller.tabIndex = 0;
        scroller.setAttribute('aria-label', 'Memories from oldest to newest. Use the arrow keys to move along.');
        const track = el('div', 'journey-track');
        let lastYear = '';
        ordered.forEach((postData, index) => {
            const yearText = postData.date.slice(0, 4);
            if (yearText !== lastYear) {
                const mark = el('div', 'journey-year');
                mark.append(el('span', '', yearText));
                track.append(mark);
                lastYear = yearText;
            }
            const stop = el('div', 'journey-stop');
            const card = journeyCard(postData, index);
            card.addEventListener('click', () => {
                if (!dragMoved) openFocus(postData, card);
            });
            card.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openFocus(postData, card);
                }
            });
            stop.append(el('span', 'journey-dot'), card);
            track.append(stop);
        });
        scroller.append(track);
        const edgeLeft = el('div', 'journey-edge journey-edge-left');
        edgeLeft.innerHTML = `<span>${ICONS.left}</span>`;
        const edgeRight = el('div', 'journey-edge journey-edge-right');
        edgeRight.innerHTML = `<span>${ICONS.right}</span>`;
        const hint = el('p', 'journey-hint muted', 'Rest the mouse near the left or right side to travel through time · click a memory to open it');
        wrap.append(hint, scroller, edgeLeft, edgeRight);

        const updateEdges = () => {
            const max = scroller.scrollWidth - scroller.clientWidth;
            wrap.classList.toggle('can-left', scroller.scrollLeft > 4);
            wrap.classList.toggle('can-right', scroller.scrollLeft < max - 4);
            journeyScroll = scroller.scrollLeft;
        };
        scroller.addEventListener('scroll', updateEdges, { passive: true });

        // Hover glide: the closer the mouse is to an edge, the faster it moves (eased, so it starts gently).
        let speed = 0;
        let frame = 0;
        let lastTime = 0;
        const MAX_SPEED = 1600;
        const glide = (time) => {
            const elapsed = lastTime ? Math.min(48, time - lastTime) : 16;
            lastTime = time;
            if (speed) {
                scroller.scrollLeft += (speed * elapsed) / 1000;
                frame = requestAnimationFrame(glide);
            } else {
                frame = 0;
                lastTime = 0;
            }
        };
        const stopGlide = () => {
            speed = 0;
            wrap.classList.remove('is-gliding-left', 'is-gliding-right');
        };
        scroller.addEventListener('pointermove', (event) => {
            if (event.pointerType !== 'mouse' || dragging) return;
            const box = scroller.getBoundingClientRect();
            const zone = Math.min(240, box.width * 0.22);
            const x = event.clientX - box.left;
            speed = 0;
            if (x < zone) speed = -MAX_SPEED * ((zone - x) / zone) ** 2;
            else if (x > box.width - zone) speed = MAX_SPEED * ((x - (box.width - zone)) / zone) ** 2;
            wrap.classList.toggle('is-gliding-left', speed < 0);
            wrap.classList.toggle('is-gliding-right', speed > 0);
            if (speed && !frame) frame = requestAnimationFrame(glide);
        });
        scroller.addEventListener('pointerleave', stopGlide);

        // The mouse wheel moves along the line too (until the line ends, then the page scrolls as usual).
        scroller.addEventListener('wheel', (event) => {
            if (Math.abs(event.deltaX) > Math.abs(event.deltaY)) return;
            const max = scroller.scrollWidth - scroller.clientWidth;
            if ((event.deltaY < 0 && scroller.scrollLeft <= 0) || (event.deltaY > 0 && scroller.scrollLeft >= max - 1)) return;
            event.preventDefault();
            scroller.scrollLeft += event.deltaY * 1.2;
        }, { passive: false });

        // Click-and-drag also moves it; a drag does not count as a click on a memory.
        let dragging = false;
        let dragMoved = false;
        scroller.addEventListener('pointerdown', (event) => {
            if (event.pointerType !== 'mouse' || event.button !== 0) return;
            dragging = true;
            dragMoved = false;
            stopGlide();
            const startX = event.clientX;
            const startScroll = scroller.scrollLeft;
            const move = (e) => {
                const dx = e.clientX - startX;
                if (Math.abs(dx) > 6) {
                    dragMoved = true;
                    wrap.classList.add('is-dragging');
                }
                if (dragMoved) scroller.scrollLeft = startScroll - dx;
            };
            const up = () => {
                dragging = false;
                wrap.classList.remove('is-dragging');
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                setTimeout(() => {
                    dragMoved = false;
                }, 0);
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        });

        scroller.addEventListener('keydown', (event) => {
            const stepSize = 330;
            const moves = { ArrowLeft: -stepSize, ArrowRight: stepSize, PageUp: -scroller.clientWidth * 0.8, PageDown: scroller.clientWidth * 0.8 };
            if (event.key in moves) {
                event.preventDefault();
                scroller.scrollBy({ left: moves[event.key], behavior: 'smooth' });
            } else if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();
                scroller.scrollTo({ left: event.key === 'Home' ? 0 : scroller.scrollWidth, behavior: 'smooth' });
            }
        });

        // Opens on the newest memories (the right end), or where it was left.
        requestAnimationFrame(() => {
            scroller.scrollLeft = journeyScroll ?? scroller.scrollWidth;
            updateEdges();
        });
        journeyCleanup = () => {
            cancelAnimationFrame(frame);
            speed = 0;
        };
        return wrap;
    }

    // Zooms a memory card open into a large panel (and back), animating from the card's position.
    let focusOverlay = null;
    // One post (from the horizontal timeline) or every post of a day (from the calendar).
    function focusMedia(postData) {
        const items = postData.photos.map((photo) => ({ post: postData, photo }));
        const media = el('div', `journey-focus-media${postData.photos.length === 1 ? ' is-single' : ''}`);
        postData.photos.forEach((photo, index) => {
            if (photo.video && postData.photos.length === 1) {
                const player = TsvPlayer.create({
                    src: videoUrl(photo.id),
                    poster: photoUrl(photo.id),
                    width: photo.w,
                    height: photo.h,
                    duration: photo.duration,
                    label: 'Video',
                });
                media.append(player.element);
                return;
            }
            const tile = el('button', 'journey-focus-tile');
            tile.type = 'button';
            tile.setAttribute('aria-label', `Open ${photo.video ? 'video' : 'photo'} ${index + 1} of ${postData.photos.length}`);
            if (postData.photos.length === 1) tile.style.aspectRatio = String(Math.min(2, Math.max(0.75, photo.w / photo.h)));
            const img = el('img');
            img.alt = '';
            img.decoding = 'async';
            img.src = postData.photos.length <= 4 && !photo.video ? photoUrl(photo.id) : photoUrl(photo.id, 'thumb');
            tile.append(img);
            if (photo.video) tile.append(playBadge(photo, true));
            tile.addEventListener('click', () => openLightbox(items, index));
            media.append(tile);
        });
        return media;
    }
    const editButton = (postData) => {
        const edit = el('button', 'button button-light');
        edit.type = 'button';
        edit.innerHTML = `${ICONS.pen}<span>Edit post</span>`;
        edit.addEventListener('click', () => {
            closeFocus(true);
            openComposer(postData);
        });
        return edit;
    };

    function openFocus(postOrPosts, card) {
        closeFocus(true);
        const list = Array.isArray(postOrPosts) ? postOrPosts : [postOrPosts];
        const single = list.length === 1;
        const first = list[0];
        const overlay = el('div', 'journey-focus');
        const panel = el('article', 'journey-focus-panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-label', single ? `Memory from ${longDate(first.date)}` : `${list.length} memories from ${longDate(first.date)}`);
        const head = el('header', 'journey-focus-head');
        const titles = el('div', 'journey-focus-titles');
        titles.append(
            el('strong', '', `${weekday(first.date)}, ${longDate(first.date)}`),
            el('span', 'muted', single
                ? `${mediaCount(first.photos)}${first.edited ? ' · Edited' : ''}`
                : `${list.length} memories · ${mediaCount(list.flatMap((p) => p.photos))}`)
        );
        const actions = el('div', 'journey-focus-actions');
        const close = el('button', 'dialog-close');
        close.type = 'button';
        close.setAttribute('aria-label', 'Close');
        close.innerHTML = ICONS.x;
        close.addEventListener('click', () => closeFocus());
        if (single) actions.append(editButton(first));
        actions.append(close);
        head.append(titles, actions);
        panel.append(head);
        if (single) {
            if (first.caption) panel.append(el('p', 'journey-focus-caption', first.caption));
            panel.append(focusMedia(first));
        } else {
            // Each post of the day keeps its own caption, photos and Edit button.
            list.forEach((postData, index) => {
                const section = el('section', 'journey-focus-post');
                const sectionHead = el('div', 'journey-focus-post-head');
                const label = el('div', 'journey-focus-post-label');
                label.append(el('strong', '', `Memory ${index + 1} of ${list.length}`), el('span', 'muted', `${mediaCount(postData.photos)}${postData.edited ? ' · Edited' : ''}`));
                sectionHead.append(label, editButton(postData));
                section.append(sectionHead);
                if (postData.caption) section.append(el('p', 'journey-focus-caption', postData.caption));
                section.append(focusMedia(postData));
                panel.append(section);
            });
        }
        overlay.append(panel);
        document.body.append(overlay);
        document.body.classList.add('has-focus');
        viewHost.querySelectorAll('.vplayer video').forEach((video) => video.pause());

        // FLIP: start the panel exactly over the card, then let it grow into place.
        const from = card.getBoundingClientRect();
        const to = panel.getBoundingClientRect();
        panel.style.transformOrigin = '0 0';
        const startTransform = `translate(${from.left - to.left}px, ${from.top - to.top}px) scale(${from.width / to.width}, ${Math.min(from.height, to.height) / to.height})`;
        panel.animate([{ transform: startTransform, opacity: 0.5, borderRadius: '14px' }, { transform: 'none', opacity: 1 }], { duration: 440, easing: 'cubic-bezier(.2,.85,.25,1)' });
        overlay.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 320, easing: 'ease-out' });
        card.classList.add('is-focused');
        focusOverlay = { overlay, panel, card, startTransform };

        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) closeFocus();
        });
        close.focus({ preventScroll: true });
    }

    function closeFocus(instant = false) {
        if (!focusOverlay) return;
        const { overlay, panel, card } = focusOverlay;
        focusOverlay = null;
        overlay.querySelectorAll('.vplayer video').forEach((video) => {
            video.pause();
            video.removeAttribute('src');
            video.load();
        });
        document.body.classList.remove('has-focus');
        const finish = () => {
            overlay.remove();
            card.classList.remove('is-focused');
        };
        if (instant || !card.isConnected) {
            finish();
            return;
        }
        // Shrink back into the card it came from.
        const from = card.getBoundingClientRect();
        const to = panel.getBoundingClientRect();
        const back = `translate(${from.left - to.left}px, ${from.top - to.top}px) scale(${from.width / to.width}, ${Math.min(from.height, to.height) / to.height})`;
        overlay.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 300, easing: 'ease-in', fill: 'forwards' });
        panel.animate([{ transform: 'none', opacity: 1 }, { transform: back, opacity: 0.4 }], { duration: 340, easing: 'cubic-bezier(.4,0,.6,1)', fill: 'forwards' }).finished.then(finish, finish);
        card.focus({ preventScroll: true });
    }
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && focusOverlay && document.querySelector('[data-lightbox]')?.hidden !== false && !document.querySelector('dialog[open]')) {
            event.preventDefault();
            closeFocus();
        }
    });

    function render() {
        journeyCleanup?.();
        journeyCleanup = null;
        closeFocus(true);
        renderYears();
        viewButtons.forEach((button) => {
            const active = button.dataset.view === view;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', String(active));
        });
        const tabs = root.querySelector('.segmented');
        if (tabs && window.syncSegmented) window.syncSegmented(tabs);
        renderOnThisDay();
        viewHost.querySelectorAll('.vplayer video').forEach((video) => {
            video.pause();
            video.removeAttribute('src');
            video.load();
        });
        const list = filtered();
        if (!posts.length) {
            viewHost.replaceChildren(emptyState('No memories yet'));
            return;
        }
        if (view === 'horizontal') {
            viewHost.replaceChildren(list.length ? renderJourney(list) : emptyState(`No memories in ${year}`));
        } else if (view === 'gallery') {
            viewHost.replaceChildren(list.length ? renderGallery(list) : emptyState(`No memories in ${year}`));
        } else if (view === 'calendar') {
            viewHost.replaceChildren(renderCalendar(list));
        } else {
            viewHost.replaceChildren(list.length ? renderTimeline(list) : emptyState(`No memories in ${year}`));
        }
        // Posts and gallery months rise in as they scroll into view.
        window.revealOnScroll?.(viewHost.querySelectorAll('.memory-card, .gallery-month'));
    }

    // ---------- lightbox ----------
    const lightbox = document.querySelector('[data-lightbox]');
    const lightImage = lightbox.querySelector('[data-lightbox-image]');
    const playerHost = lightbox.querySelector('[data-lightbox-player]');
    let lightPlayer = null;
    const stopVideo = () => {
        lightPlayer?.destroy();
        lightPlayer = null;
        playerHost.replaceChildren();
    };
    let lightItems = [];
    let lightIndex = 0;

    function showLight() {
        const item = lightItems[lightIndex];
        if (!item) return;
        const isVideo = Boolean(item.photo.video);
        stopVideo();
        lightImage.hidden = isVideo;
        playerHost.hidden = !isVideo;
        if (isVideo) {
            lightImage.removeAttribute('src');
            lightPlayer = TsvPlayer.create({
                src: videoUrl(item.photo.id),
                poster: photoUrl(item.photo.id),
                width: item.photo.w,
                height: item.photo.h,
                duration: item.photo.duration,
                autoplay: true,
                label: 'Video',
            });
            playerHost.append(lightPlayer.element);
            lightPlayer.element.focus({ preventScroll: true });
        } else {
            const next = photoUrl(item.photo.id);
            if (!lightImage.src.endsWith(next)) {
                lightImage.classList.remove('is-loaded');
                lightImage.src = next;
            }
        }
        lightImage.alt = item.post.caption ? item.post.caption.slice(0, 120) : `Memory from ${longDate(item.post.date)}`;
        lightbox.querySelector('[data-lightbox-date]').textContent = `${weekday(item.post.date)}, ${longDate(item.post.date)}`;
        lightbox.querySelector('[data-lightbox-count]').textContent = lightItems.length > 1 ? `${lightIndex + 1} of ${lightItems.length}` : '';
        const caption = lightbox.querySelector('[data-lightbox-caption]');
        caption.textContent = item.post.caption;
        caption.hidden = !item.post.caption;
        lightbox.querySelector('[data-lightbox-download]').href = `${isVideo ? videoUrl(item.photo.id) : photoUrl(item.photo.id)}&download=1`;
        lightbox.querySelectorAll('[data-lightbox-step]').forEach((button) => {
            button.hidden = lightItems.length < 2;
        });
        // Load the neighbours early so arrowing through feels instant.
        [lightIndex - 1, lightIndex + 1].forEach((index) => {
            const neighbour = lightItems[(index + lightItems.length) % lightItems.length];
            if (neighbour) new Image().src = photoUrl(neighbour.photo.id, neighbour.photo.video ? 'thumb' : 'full');
        });
    }
    function openLightbox(items, index) {
        viewHost.querySelectorAll('.vplayer video').forEach((video) => video.pause());
        lightItems = items;
        lightIndex = Math.max(0, index);
        lightbox.hidden = false;
        document.body.classList.add('has-lightbox');
        showLight();
        if (!lightPlayer) lightbox.querySelector('[data-lightbox-close]').focus();
    }
    function closeLightbox() {
        lightbox.hidden = true;
        document.body.classList.remove('has-lightbox');
        lightImage.removeAttribute('src');
        stopVideo();
    }
    lightbox.querySelectorAll('[data-lightbox-step]').forEach((button) => {
        button.addEventListener('click', () => {
            lightIndex = (lightIndex + Number(button.dataset.lightboxStep) + lightItems.length) % lightItems.length;
            showLight();
        });
    });
    lightbox.querySelector('[data-lightbox-close]').addEventListener('click', closeLightbox);
    lightbox.addEventListener('click', (event) => {
        if (event.target === lightbox || event.target === playerHost) closeLightbox();
    });
    lightbox.querySelector('[data-lightbox-edit]').addEventListener('click', () => {
        const target = lightItems[lightIndex]?.post;
        closeLightbox();
        if (target) openComposer(target);
    });
    document.addEventListener('keydown', (event) => {
        if (lightbox.hidden) return;
        if (event.key === 'Escape') closeLightbox();
        if ((event.key === 'ArrowLeft' || event.key === 'ArrowRight') && !event.target.closest?.('.vplayer')) {
            lightIndex = (lightIndex + (event.key === 'ArrowLeft' ? -1 : 1) + lightItems.length) % lightItems.length;
            showLight();
        }
    });
    // Swipe on touch screens.
    let touchX = null;
    lightbox.addEventListener('touchstart', (event) => {
        // Dragging along the video's seek bar must not swipe to the next photo.
        touchX = event.target.closest('.vplayer') ? null : event.touches[0].clientX;
    }, { passive: true });
    lightbox.addEventListener('touchend', (event) => {
        if (touchX === null || lightItems.length < 2) return;
        const dx = event.changedTouches[0].clientX - touchX;
        if (Math.abs(dx) > 50) {
            lightIndex = (lightIndex + (dx > 0 ? -1 : 1) + lightItems.length) % lightItems.length;
            showLight();
        }
        touchX = null;
    });

    // ---------- delete ----------
    function deletePost(postData) {
        const dialog = document.querySelector('#confirm-dialog');
        dialog.querySelector('[data-confirm-title]').textContent = 'Delete this memory?';
        dialog.querySelector('[data-confirm-message]').textContent = `The post from ${longDate(postData.date)} and its ${mediaCount(postData.photos).toLowerCase().replace(' · ', ' and ')} will be removed. This cannot be undone.`;
        const ok = dialog.querySelector('[data-confirm-ok]');
        ok.textContent = 'Delete';
        ok.classList.add('button-danger');
        dialog.querySelector('[data-confirm-icon]').hidden = false;
        const onOk = async () => {
            try {
                await post('memory_delete', { post_id: postData.id });
                showToast('Memory Deleted', `The post from ${longDate(postData.date)} was removed.`);
                await reload();
            } catch (error) {
                showToast('Could Not Delete', error.message, 'error');
            }
        };
        ok.addEventListener('click', onOk, { once: true });
        dialog.addEventListener('close', () => ok.removeEventListener('click', onOk), { once: true });
        dialog.showModal();
        dialog.querySelector('[data-confirm-cancel]').focus();
    }

    // ---------- composer (new post and edit post) ----------
    const composer = document.querySelector('#memory-composer');
    const form = composer.querySelector('[data-memory-form]');
    const photoList = composer.querySelector('[data-composer-photos]');
    const fileInput = composer.querySelector('[data-composer-files]');
    const drop = composer.querySelector('[data-composer-drop]');
    const status = composer.querySelector('[data-composer-status]');
    const submit = composer.querySelector('[data-composer-submit]');
    const countLabel = composer.querySelector('[data-composer-count]');
    const dateInput = form.querySelector('[name="taken_on"]');
    let editing = null;
    let composerItems = [];
    let busy = false;

    function setDate(iso) {
        dateInput.value = iso;
        const display = dateInput.closest('.datepicker')?.querySelector('.datepicker-display');
        if (display) {
            display.value = iso ? longDate(iso) : '';
            display.setCustomValidity('');
        }
    }

    function renderComposerPhotos() {
        photoList.replaceChildren();
        composerItems.forEach((item, index) => {
            const tile = el('div', 'composer-photo');
            let img;
            if (item.kind === 'new' && item.video) {
                // A muted <video> shows the clip's first frame as its preview.
                img = el('video');
                img.muted = true;
                img.preload = 'metadata';
                img.src = `${item.preview}#t=0.5`;
            } else {
                img = el('img');
                img.alt = '';
                img.src = item.kind === 'existing' ? photoUrl(item.id, 'thumb') : item.preview;
            }
            const remove = el('button', 'composer-photo-remove');
            remove.type = 'button';
            remove.setAttribute('aria-label', 'Remove photo');
            remove.innerHTML = ICONS.x;
            remove.disabled = busy;
            remove.addEventListener('click', () => {
                if (item.preview) URL.revokeObjectURL(item.preview);
                composerItems.splice(index, 1);
                renderComposerPhotos();
            });
            tile.append(img, remove);
            if (item.video) tile.append(playBadge({ duration: item.duration }));
            if (item.state === 'busy' && item.progress !== undefined) tile.append(el('span', 'composer-photo-progress', `${item.progress}%`));
            if (item.state) tile.append(el('span', `composer-photo-state is-${item.state}`, item.state === 'done' ? '✓' : item.state === 'error' ? '!' : ''));
            photoList.append(tile);
        });
        photoList.hidden = composerItems.length === 0;
        countLabel.textContent = composerItems.length ? mediaCount(composerItems) : '';
    }

    function openComposer(postData = null) {
        editing = postData;
        composerItems.forEach((item) => item.preview && URL.revokeObjectURL(item.preview));
        composerItems = postData ? postData.photos.map((photo) => ({ kind: 'existing', id: photo.id, video: Boolean(photo.video), duration: photo.duration })) : [];
        form.caption.value = postData ? postData.caption : '';
        setDate(postData ? postData.date : '');
        composer.querySelector('[data-composer-title]').textContent = postData ? 'Edit memory' : 'New memory';
        composer.querySelector('[data-composer-hint]').textContent = postData
            ? 'Change the caption or date, remove photos or videos, or add more.'
            : 'Add photos or videos, a caption and the date of the activity.';
        submit.textContent = postData ? 'Save changes' : 'Post';
        status.textContent = '';
        status.classList.remove('is-error');
        renderComposerPhotos();
        composer.showModal();
        form.caption.focus();
    }

    function addFiles(files) {
        const problems = [];
        [...files].forEach((file) => {
            if (isVideoFile(file)) {
                if (file.size > VIDEO_MAX_BYTES) {
                    problems.push(`${file.name} is larger than ${Math.round(VIDEO_MAX_BYTES / 1024 / 1024)} MB. Trim it or share a shorter clip.`);
                    return;
                }
                const item = { kind: 'new', video: true, file, preview: URL.createObjectURL(file) };
                composerItems.push(item);
                // Read the length for the badge; a clip the browser cannot open is caught again at upload.
                const probe = document.createElement('video');
                probe.preload = 'metadata';
                probe.onloadedmetadata = () => {
                    item.duration = probe.duration;
                    renderComposerPhotos();
                };
                probe.src = item.preview;
            } else if (file.type.startsWith('image/') || /\.(jpe?g|png|webp|heic|heif)$/i.test(file.name)) {
                composerItems.push({ kind: 'new', file, preview: URL.createObjectURL(file) });
            } else {
                problems.push(`${file.name} is not a photo or video.`);
            }
        });
        status.textContent = problems.join(' ');
        status.classList.toggle('is-error', problems.length > 0);
        renderComposerPhotos();
    }

    fileInput.addEventListener('change', () => {
        addFiles(fileInput.files);
        fileInput.value = '';
    });
    ['dragenter', 'dragover'].forEach((type) => drop.addEventListener(type, (event) => {
        event.preventDefault();
        drop.classList.add('is-dragover');
    }));
    ['dragleave', 'drop'].forEach((type) => drop.addEventListener(type, () => drop.classList.remove('is-dragover')));
    drop.addEventListener('drop', (event) => {
        event.preventDefault();
        addFiles(event.dataTransfer.files);
    });
    // Pasting a picture into the composer adds it too.
    composer.addEventListener('paste', (event) => {
        const files = [...(event.clipboardData?.files || [])];
        if (files.length) {
            event.preventDefault();
            addFiles(files);
        }
    });

    async function loadBitmap(file) {
        try {
            return await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch {
            const url = URL.createObjectURL(file);
            try {
                const img = new Image();
                img.src = url;
                await img.decode();
                return img;
            } catch {
                throw new Error(`${file.name} could not be read. iPhone HEIC photos must be saved as JPEG first.`);
            } finally {
                URL.revokeObjectURL(url);
            }
        }
    }
    const blobToBase64 = (blob) => new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result).split(',')[1] || '');
        reader.onerror = () => reject(new Error('A photo could not be prepared.'));
        reader.readAsDataURL(blob);
    });
    async function encode(source, maxEdge, quality) {
        const width = source.videoWidth || source.width;
        const height = source.videoHeight || source.height;
        const scale = Math.min(1, maxEdge / Math.max(width, height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        const context = canvas.getContext('2d');
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.imageSmoothingQuality = 'high';
        context.drawImage(source, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        return blobToBase64(blob);
    }
    // Shrinks a photo to at most 1600px and about 500 KB, so it fits MySQL's 1 MB row limit and the backups stay small.
    async function preparePhoto(file) {
        const bitmap = await loadBitmap(file);
        let edge = PHOTO_MAX_EDGE;
        let quality = 0.85;
        let photo = await encode(bitmap, edge, quality);
        while (photo.length > PHOTO_MAX_BASE64) {
            if (quality > 0.6) {
                quality -= 0.1;
            } else {
                edge = Math.round(edge * 0.8);
            }
            photo = await encode(bitmap, edge, quality);
        }
        const thumb = await encode(bitmap, THUMB_EDGE, 0.75);
        bitmap.close?.();
        return { photo, thumb };
    }

    // Opens a clip in a hidden <video> to read its size and length and grab a poster frame.
    async function prepareVideo(file) {
        const url = URL.createObjectURL(file);
        const video = document.createElement('video');
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';
        const unsupported = `${file.name} cannot be played in this browser. Save it as an MP4 (H.264) video and try again.`;
        const waitFor = (event) => new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error(unsupported)), 20000);
            video.addEventListener(event, () => { clearTimeout(timer); resolve(); }, { once: true });
            video.addEventListener('error', () => { clearTimeout(timer); reject(new Error(unsupported)); }, { once: true });
        });
        try {
            video.src = url;
            await waitFor('loadeddata');
            if (!video.videoWidth || !video.videoHeight) {
                throw new Error(unsupported);
            }
            const seekTo = Math.min(1, (video.duration || 0) / 3);
            if (seekTo > 0) {
                video.currentTime = seekTo;
                await waitFor('seeked');
            }
            let quality = 0.8;
            let poster = await encode(video, POSTER_EDGE, quality);
            while (poster.length > POSTER_MAX_BASE64 && quality > 0.4) {
                quality -= 0.1;
                poster = await encode(video, POSTER_EDGE, quality);
            }
            return { width: video.videoWidth, height: video.videoHeight, duration: video.duration || 0, poster };
        } finally {
            video.removeAttribute('src');
            video.load();
            URL.revokeObjectURL(url);
        }
    }

    // Sends a video in 512 KB pieces with a percentage on its tile, then asks the server to check it.
    async function uploadVideo(item, postId, wasEditing) {
        const details = await prepareVideo(item.file);
        const start = await post('memory_video_start', {
            post_id: postId,
            mime: videoMime(item.file),
            size: item.file.size,
            width: details.width,
            height: details.height,
            duration: details.duration,
            poster: details.poster,
        });
        const chunkSize = start.chunk;
        const pieces = Math.ceil(item.file.size / chunkSize);
        for (let seq = 0; seq < pieces; seq++) {
            const piece = item.file.slice(seq * chunkSize, Math.min(item.file.size, (seq + 1) * chunkSize));
            let tries = 0;
            // A dropped connection retries the same piece a few times before giving up.
            for (;;) {
                try {
                    await post('memory_video_chunk', { post_id: postId, media_id: start.id, seq, chunk: piece });
                    break;
                } catch (error) {
                    if (++tries >= 3) throw error;
                    await new Promise((resolve) => setTimeout(resolve, 1000 * tries));
                }
            }
            item.progress = Math.round(((seq + 1) / pieces) * 100);
            renderComposerPhotos();
        }
        return (await post('memory_video_finish', { post_id: postId, media_id: start.id, edit: wasEditing ? '1' : '0' })).photo;
    }

    function setBusy(value) {
        busy = value;
        submit.disabled = value;
        form.querySelectorAll('textarea, input, [data-composer-close]').forEach((control) => {
            control.disabled = value;
        });
        drop.classList.toggle('is-disabled', value);
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        if (!composerItems.length) {
            status.textContent = 'Add at least one photo or video.';
            status.classList.add('is-error');
            return;
        }
        const display = dateInput.closest('.datepicker')?.querySelector('.datepicker-display');
        if (display && display.validationMessage) {
            display.reportValidity();
            return;
        }
        status.classList.remove('is-error');
        setBusy(true);
        const newItems = composerItems.filter((item) => item.kind === 'new' && item.state !== 'done');
        const failures = [];
        const wasEditing = Boolean(editing);
        try {
            let postId = editing?.id;
            const fields = { caption: form.caption.value, taken_on: dateInput.value };
            if (editing) {
                const kept = new Set(composerItems.filter((item) => item.kind === 'existing').map((item) => item.id));
                status.textContent = 'Saving changes…';
                await post('memory_update', {
                    ...fields,
                    post_id: postId,
                    remove_photos: editing.photos.filter((photo) => !kept.has(photo.id)).map((photo) => photo.id),
                });
            } else {
                status.textContent = 'Creating the post…';
                postId = (await post('memory_create', fields)).id;
                editing = { id: postId, photos: [] };
            }
            for (const [index, item] of newItems.entries()) {
                status.textContent = `Uploading ${item.video ? 'video' : 'photo'} ${index + 1} of ${newItems.length}…`;
                item.state = 'busy';
                item.progress = item.video ? 0 : undefined;
                renderComposerPhotos();
                try {
                    let saved;
                    if (item.video) {
                        saved = await uploadVideo(item, postId, wasEditing);
                    } else {
                        const prepared = await preparePhoto(item.file);
                        saved = (await post('memory_add_photo', { post_id: postId, edit: wasEditing ? '1' : '0', ...prepared })).photo;
                    }
                    item.state = 'done';
                    item.kind = 'existing';
                    item.id = saved.id;
                    editing.photos.push(saved);
                } catch (error) {
                    item.state = 'error';
                    failures.push(error.message);
                }
                renderComposerPhotos();
            }
            await reload();
            if (failures.length) {
                status.textContent = `${failures.length} ${failures.length === 1 ? 'file was' : 'files were'} not added: ${failures[0]}`;
                status.classList.add('is-error');
                // Keep the dialog open on the saved post so the failed photos can be removed or retried.
                editing = posts.find((p) => p.id === postId) || editing;
                composerItems = composerItems.filter((item) => item.state !== 'done' || item.kind === 'existing');
                submit.textContent = 'Retry';
            } else {
                composer.close();
                showToast(wasEditing ? 'Memory Saved' : 'Memory Posted', fields.taken_on ? longDate(fields.taken_on) : `Dated today, ${longDate(today)}.`);
            }
        } catch (error) {
            status.textContent = error.message;
            status.classList.add('is-error');
        } finally {
            setBusy(false);
            renderComposerPhotos();
        }
    });

    composer.querySelectorAll('[data-composer-close]').forEach((button) => button.addEventListener('click', () => composer.close()));
    composer.addEventListener('cancel', (event) => {
        if (busy) event.preventDefault();
    });
    composer.addEventListener('click', (event) => {
        if (event.target === composer && !busy) composer.close();
    });
    root.ownerDocument.querySelectorAll('[data-memory-new]').forEach((button) => button.addEventListener('click', () => openComposer()));

    render();
})();
