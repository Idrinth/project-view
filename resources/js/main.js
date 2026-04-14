// Project View frontend entry point.
// Looks for a [data-view] container on the page, fetches the matching
// payload from the PHP API and renders it into the DOM.
(function () {
    'use strict';

    // API endpoints are served as path segments (e.g. /login) by the
    // .htaccess rewrite in front of index.php. We use a relative base
    // so the app keeps working when deployed under a sub-path.
    var API_BASE = './';

    document.addEventListener('DOMContentLoaded', function () {
        bindLoginForm();
        initThemeToggle();
        initUserTooltips();

        // Fetch the current user once and share the result between the
        // nav user-menu and the view renderer. The promise never
        // rejects - it resolves to null when the caller isn't signed in
        // so callers can treat the value as a simple boolean.
        var userPromise = apiRequest('GET', 'me')
            .then(function (data) {
                if (!data || !data.user || !data.user.name) {
                    return null;
                }
                return {
                    name: data.user.name,
                    displayName: data.user.displayName || ''
                };
            })
            .catch(function () {
                return null;
            });

        userPromise.then(renderUserMenu);

        var container = document.querySelector('[data-view]');
        if (!container) {
            return;
        }
        var view = container.getAttribute('data-view');
        var renderer = renderers[view];
        if (!renderer) {
            return;
        }
        Promise.all([fetchEndpoint(view), userPromise])
            .then(function (results) {
                initFilteredView(container, view, renderer, results[0], { user: results[1] });
            })
            .catch(function (err) {
                clear(container);
                var msg = document.createElement('p');
                msg.className = 'muted';
                msg.textContent = 'Failed to load data: ' + err.message;
                container.appendChild(msg);
            });
    });

    // Wire up the optional category filter bar (if the page includes
    // one) and render the view. The data is fetched once and filtered
    // in-memory so the user can type freely without hitting the API
    // again. Views that don't carry category information (e.g. time)
    // fall through untouched.
    function initFilteredView(container, view, renderer, data, options) {
        var filterInput = document.querySelector('[data-category-filter]');
        var clearBtn = document.querySelector('[data-category-filter-clear]');

        // Seed the shared category datalist from the loaded data so
        // both the filter input and the add-card form get suggestions
        // for every category (and prefix) currently in use.
        ensureCategoryDatalist(collectCategoryPaths(view, data));
        if (filterInput) {
            filterInput.setAttribute('list', CATEGORY_DATALIST_ID);
        }

        function render() {
            var filter = filterInput ? parseCategoryFilter(filterInput.value) : [];
            var viewData = applyCategoryFilter(view, data, filter);
            clear(container);
            renderer(container, viewData, options);
        }

        if (filterInput) {
            filterInput.addEventListener('input', render);
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (filterInput && filterInput.value !== '') {
                    filterInput.value = '';
                    render();
                }
            });
        }

        render();

        // Deep-link support: if the page was opened with a hash like
        // `#issue-42`, pop the detail view for that issue straight away
        // so the URL can be shared as a direct link to a card.
        maybeOpenIssueFromHash(container);

        // Passively re-fetch the view payload every ~10 minutes so
        // viewers see newly-added cards and edits without reloading.
        // Ticks are skipped when the user is mid-interaction (drag,
        // open modal) or the tab is hidden, and network errors are
        // swallowed so a transient hiccup just keeps the last data.
        setInterval(function () {
            if (isRefreshBusy()) {
                return;
            }
            if (typeof document.hidden === 'boolean' && document.hidden) {
                return;
            }
            fetchEndpoint(view).then(function (fresh) {
                data = fresh;
                ensureCategoryDatalist(collectCategoryPaths(view, data));
                render();
            }).catch(function () {
                /* ignore - keep showing the previous data */
            });
        }, AUTO_REFRESH_MS);
    }

    // How often the view polls the API for fresh data.
    var AUTO_REFRESH_MS = 10 * 60 * 1000;

    // True while the user is interacting with something that a silent
    // re-render would disrupt: dragging a card, or with the add-card
    // or card-detail modal open.
    function isRefreshBusy() {
        if (draggedCard) {
            return true;
        }
        if (document.body.classList.contains('kanban-modal-open')) {
            return true;
        }
        var detail = document.querySelector('[data-detail-overlay]');
        if (detail && !detail.hidden) {
            return true;
        }
        return false;
    }

    // Shared id used by both the filter input and the kanban add-card
    // category input so a single <datalist> feeds every autocomplete.
    var CATEGORY_DATALIST_ID = 'category-suggestions';

    // Collect every distinct category path visible in the current
    // payload, plus all prefix paths, so users can pick any level of
    // the hierarchy (e.g. "Mods", "Mods / Skyrim", "Mods / Skyrim /
    // Idrinth Thalui") without having to remember the leaf name.
    function collectCategoryPaths(view, data) {
        var seen = {};
        var paths = [];
        var push = function (path) {
            if (!Array.isArray(path) || path.length === 0) {
                return;
            }
            for (var i = 1; i <= path.length; i++) {
                var label = path.slice(0, i).join(' / ');
                var key = label.toLowerCase();
                if (label && !seen[key]) {
                    seen[key] = true;
                    paths.push(label);
                }
            }
        };
        if (view === 'kanban' && data && Array.isArray(data.columns)) {
            data.columns.forEach(function (column) {
                (column.cards || []).forEach(function (card) {
                    push(cardCategoryPath(card));
                });
            });
        } else if (view === 'releases' && data && Array.isArray(data.projects)) {
            data.projects.forEach(function (project) {
                push(projectCategoryPath(project));
            });
        }
        paths.sort(function (a, b) { return a.localeCompare(b); });
        return paths;
    }

    // Create (or refresh) a <datalist> holding the supplied category
    // paths. Inputs reference it via the `list` attribute.
    function ensureCategoryDatalist(paths) {
        var list = document.getElementById(CATEGORY_DATALIST_ID);
        if (!list) {
            list = document.createElement('datalist');
            list.id = CATEGORY_DATALIST_ID;
            document.body.appendChild(list);
        }
        clear(list);
        paths.forEach(function (label) {
            var option = document.createElement('option');
            option.value = label;
            list.appendChild(option);
        });
        return list;
    }

    // Merge a freshly-added category path into the shared datalist so
    // suggestions stay current without needing a page reload.
    function addCategoryPathToDatalist(path) {
        var list = document.getElementById(CATEGORY_DATALIST_ID);
        if (!list || !Array.isArray(path) || path.length === 0) {
            return;
        }
        var existing = {};
        for (var i = 0; i < list.options.length; i++) {
            existing[list.options[i].value.toLowerCase()] = true;
        }
        for (var j = 1; j <= path.length; j++) {
            var label = path.slice(0, j).join(' / ');
            if (label && !existing[label.toLowerCase()]) {
                existing[label.toLowerCase()] = true;
                var option = document.createElement('option');
                option.value = label;
                list.appendChild(option);
            }
        }
    }

    // Split a user-typed path like "Mods / Skyrim" into trimmed,
    // non-empty segments. Extra slashes and whitespace are ignored so
    // "mods//skyrim" and " mods / skyrim " both yield ["mods","skyrim"].
    function parseCategoryFilter(value) {
        if (!value) {
            return [];
        }
        return String(value).split('/').map(function (segment) {
            return segment.trim();
        }).filter(function (segment) {
            return segment.length > 0;
        });
    }

    // Prefix-match an item's category path against the filter: every
    // filter segment must equal the corresponding segment of the item
    // path (case-insensitive), and the item may have extra deeper
    // segments. That way filtering by "cat/abc" also keeps items at
    // "cat/abc/def".
    function matchesCategoryFilter(path, filter) {
        if (!filter.length) {
            return true;
        }
        if (!path || path.length < filter.length) {
            return false;
        }
        for (var i = 0; i < filter.length; i++) {
            var itemSeg = String(path[i] == null ? '' : path[i]).toLowerCase();
            var filterSeg = String(filter[i]).toLowerCase();
            if (itemSeg !== filterSeg) {
                return false;
            }
        }
        return true;
    }

    function cardCategoryPath(card) {
        if (Array.isArray(card.categoryPath)) {
            return card.categoryPath;
        }
        if (card.category) {
            return [card.category];
        }
        return [];
    }

    function projectCategoryPath(project) {
        if (Array.isArray(project.path)) {
            return project.path;
        }
        if (project.name) {
            return [project.name];
        }
        return [];
    }

    function applyCategoryFilter(view, data, filter) {
        if (!filter.length) {
            return data;
        }
        if (view === 'kanban') {
            return filterKanbanData(data, filter);
        }
        if (view === 'releases') {
            return filterReleasesData(data, filter);
        }
        return data;
    }

    function filterKanbanData(data, filter) {
        if (!data || !Array.isArray(data.columns)) {
            return data;
        }
        var columns = data.columns.map(function (column) {
            var cards = (column.cards || []).filter(function (card) {
                return matchesCategoryFilter(cardCategoryPath(card), filter);
            });
            var copy = {};
            Object.keys(column).forEach(function (key) { copy[key] = column[key]; });
            copy.cards = cards;
            return copy;
        });
        var result = {};
        Object.keys(data).forEach(function (key) { result[key] = data[key]; });
        result.columns = columns;
        return result;
    }

    function filterReleasesData(data, filter) {
        if (!data || !Array.isArray(data.projects)) {
            return data;
        }
        var projects = data.projects.filter(function (project) {
            return matchesCategoryFilter(projectCategoryPath(project), filter);
        });
        var result = {};
        Object.keys(data).forEach(function (key) { result[key] = data[key]; });
        result.projects = projects;
        return result;
    }

    // localStorage key carrying the explicit theme preference, if any.
    // Mirrors the small inline bootstrap script in each HTML <head>
    // that sets data-theme on <html> before the first paint so there's
    // no flash of the wrong palette. When the key is absent we fall
    // back to the OS-level prefers-color-scheme.
    var THEME_STORAGE_KEY = 'pv-theme';

    // Inject a small theme-toggle button into the site header and wire
    // it up to flip between light and dark mode. The button lives next
    // to the user menu so the chrome stays together visually.
    function initThemeToggle() {
        var header = document.querySelector('.site-header');
        if (!header) {
            return;
        }
        if (header.querySelector('[data-theme-toggle]')) {
            return;
        }
        var button = el('button', {
            className: 'theme-toggle',
            type: 'button'
        });
        button.setAttribute('data-theme-toggle', '');

        var update = function () {
            var current = currentTheme();
            var next = current === 'dark' ? 'light' : 'dark';
            button.textContent = next === 'dark' ? 'Dark mode' : 'Light mode';
            button.setAttribute('aria-label', 'Switch to ' + next + ' mode');
            button.setAttribute('aria-pressed', current === 'dark' ? 'true' : 'false');
        };

        button.addEventListener('click', function () {
            var next = currentTheme() === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            update();
        });

        // Insert before the user menu so the layout reads:
        // [theme toggle] [user menu]. Falls back to appending if the
        // slot isn't there for some reason.
        var userMenu = header.querySelector('[data-user-menu]');
        if (userMenu) {
            header.insertBefore(button, userMenu);
        } else {
            header.appendChild(button);
        }

        update();

        // React to OS-level dark-mode changes when the user hasn't
        // picked a theme explicitly, so the button's label stays in
        // sync with the palette the page is actually showing.
        if (typeof window.matchMedia === 'function') {
            var media = window.matchMedia('(prefers-color-scheme: dark)');
            var listener = function () {
                if (!storedTheme()) {
                    update();
                }
            };
            if (typeof media.addEventListener === 'function') {
                media.addEventListener('change', listener);
            } else if (typeof media.addListener === 'function') {
                media.addListener(listener);
            }
        }
    }

    function storedTheme() {
        try {
            var value = window.localStorage.getItem(THEME_STORAGE_KEY);
            return value === 'dark' || value === 'light' ? value : null;
        } catch (e) {
            return null;
        }
    }

    function currentTheme() {
        var stored = storedTheme();
        if (stored) {
            return stored;
        }
        if (typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            return 'dark';
        }
        return 'light';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try {
            window.localStorage.setItem(THEME_STORAGE_KEY, theme);
        } catch (e) {
            /* ignore - storage may be disabled */
        }
    }

    // Populate the nav user-menu slot based on the resolved auth state.
    function renderUserMenu(user) {
        var slot = document.querySelector('[data-user-menu]');
        if (!slot) {
            return;
        }
        if (user && user.name) {
            renderSignedIn(slot, user);
        } else {
            renderSignedOut(slot);
        }
    }

    function renderSignedIn(slot, user) {
        clear(slot);
        // Prefer the user-chosen display name; fall back to the login
        // username so the slot is never empty. The name links to the
        // profile editor so it doubles as the entry point. The
        // data-user-mention attribute lets the shared hover tooltip
        // surface the full profile when someone hovers the nav.
        var label = userLabel({
            displayName: user.displayName,
            username: user.name,
            fallback: user.name
        });
        slot.appendChild(el('a', {
            className: 'user-menu-name user-mention',
            href: 'profile.html',
            'data-user-mention': user.name || '',
            text: label
        }));
        var button = el('button', { className: 'user-menu-logout', type: 'button', text: 'Sign out' });
        button.addEventListener('click', function () {
            apiRequest('POST', 'logout', {})
                .catch(function () { /* ignore - we'll update the UI anyway */ })
                .then(function () {
                    renderSignedOut(slot);
                });
        });
        slot.appendChild(button);
    }

    function renderSignedOut(slot) {
        clear(slot);
        slot.appendChild(el('a', { className: 'user-menu-login', href: 'login.html', text: 'Sign in' }));
    }

    function bindLoginForm() {
        var form = document.querySelector('[data-login-form]');
        if (!form) {
            return;
        }
        var errorNode = form.querySelector('[data-login-error]');
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            hideError(errorNode);
            var data = new FormData(form);
            var payload = {
                username: String(data.get('username') || ''),
                password: String(data.get('password') || '')
            };
            apiRequest('POST', 'login', payload)
                .then(function () {
                    window.location.href = 'index.html';
                })
                .catch(function (err) {
                    showError(errorNode, err.message || 'Sign in failed');
                });
        });
    }

    function showError(node, message) {
        if (!node) {
            return;
        }
        node.textContent = message;
        node.hidden = false;
    }

    function hideError(node) {
        if (!node) {
            return;
        }
        node.textContent = '';
        node.hidden = true;
    }

    function fetchEndpoint(name) {
        return apiRequest('GET', name);
    }

    // Generic API helper. Always sends/receives JSON and forwards the
    // session cookie so the backend can identify the caller. The
    // returned promise rejects with an Error whose .message is the
    // server-supplied error string (or "HTTP <status>" as a fallback).
    //
    // If `body` is a FormData instance (used for file uploads) it is
    // sent verbatim and the browser picks the multipart boundary;
    // plain objects are JSON-encoded as before.
    function apiRequest(method, endpoint, body) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        };
        if (body !== undefined) {
            if (typeof FormData !== 'undefined' && body instanceof FormData) {
                options.body = body;
            } else {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }
        }
        // Separate any query string from the endpoint name so only the
        // path segment is URL-encoded. Without this, a call like
        // `user?username=alice` would turn into `user%3Fusername%3Dalice`
        // and the front controller would treat the whole blob as the
        // endpoint name.
        var queryIndex = endpoint.indexOf('?');
        var path = queryIndex >= 0 ? endpoint.slice(0, queryIndex) : endpoint;
        var query = queryIndex >= 0 ? endpoint.slice(queryIndex) : '';
        return fetch(API_BASE + encodeURIComponent(path) + query, options)
            .then(function (response) {
                return response.json().then(
                    function (data) { return { response: response, data: data }; },
                    function () { return { response: response, data: null }; }
                );
            })
            .then(function (result) {
                if (!result.response.ok) {
                    var message = (result.data && result.data.error) || ('HTTP ' + result.response.status);
                    var err = new Error(message);
                    err.status = result.response.status;
                    throw err;
                }
                return result.data;
            });
    }

    function clear(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (key) {
                if (key === 'className') {
                    node.className = attrs[key];
                } else if (key === 'text') {
                    node.textContent = attrs[key];
                } else {
                    node.setAttribute(key, attrs[key]);
                }
            });
        }
        if (children) {
            for (var i = 0; i < children.length; i++) {
                var child = children[i];
                if (child == null) {
                    continue;
                }
                if (typeof child === 'string') {
                    node.appendChild(document.createTextNode(child));
                } else {
                    node.appendChild(child);
                }
            }
        }
        return node;
    }

    function formatHours(value) {
        var num = Number(value);
        if (!isFinite(num)) {
            return '0.0';
        }
        // Round to the hundredths so a 0.25h entry survives the trip
        // without collapsing to 0.3. Drop a trailing hundredths zero so
        // the common "X.Xh" shape (0.5h, 1.0h) is preserved for values
        // that don't actually need two decimals.
        var rounded = Math.round(num * 100) / 100;
        return rounded.toFixed(2).replace(/(\.\d)0$/, '$1');
    }

    function dateOrDash(value) {
        if (!value) {
            return document.createTextNode('\u2014');
        }
        return el('time', { datetime: value, text: value });
    }

    // Resolve the label used when showing a person. The display name
    // wins whenever the user has set one; the login username is the
    // fallback so accounts that haven't filled in their profile still
    // get a visible label. `info.fallback` is used only when neither is
    // set (e.g. a deleted account referenced by user id) so the label
    // is never the empty string.
    function userLabel(info) {
        if (!info) {
            return '';
        }
        var display = typeof info.displayName === 'string' ? info.displayName.trim() : '';
        if (display) {
            return display;
        }
        var username = typeof info.username === 'string' ? info.username.trim() : '';
        if (username) {
            return username;
        }
        return info.fallback || '';
    }

    // Render a person's label as a hover target. The label always
    // renders the display name when the user has set one (falling back
    // to the login username), and carries the login username in a data
    // attribute so the shared hover tooltip can look up the full
    // profile lazily.
    //
    // `info` may include: { username, displayName, fallback, className,
    // tag }. Passing `fallback` lets callers surface a synthetic label
    // like "#42" when the user reference has been orphaned.
    function userMentionEl(info, extras) {
        info = info || {};
        extras = extras || {};
        var tag = extras.tag || 'span';
        var className = 'user-mention';
        if (extras.className) {
            className += ' ' + extras.className;
        }
        var attrs = { className: className };
        var username = typeof info.username === 'string' ? info.username.trim() : '';
        if (username) {
            attrs['data-user-mention'] = username;
        }
        var label = userLabel(info);
        if (!label) {
            // Without a label there is nothing meaningful to hover on;
            // return a bare text node so the caller can slot it in
            // without worrying about empty spans.
            return document.createTextNode('');
        }
        attrs.text = label;
        if (extras.title) {
            attrs.title = extras.title;
        }
        return el(tag, attrs);
    }

    // ---------- Shared hover tooltip for user mentions ----------
    //
    // Every element produced by userMentionEl() carries a
    // `data-user-mention` attribute. A single document-level listener
    // drives the popover so new mentions inserted after load (e.g. a
    // freshly posted comment) pick up the behaviour automatically. The
    // profile payload is cached by username for the lifetime of the
    // page so repeated hovers over the same person don't refetch.
    var userProfileCache = {};
    var userProfileInflight = {};
    var userTooltipEl = null;
    var userTooltipTarget = null;
    var userTooltipHideTimer = null;

    function getUserTooltipEl() {
        if (userTooltipEl) {
            return userTooltipEl;
        }
        userTooltipEl = el('div', {
            className: 'user-tooltip',
            role: 'tooltip'
        });
        userTooltipEl.hidden = true;
        // Keeping the tooltip interactive (mouse can enter it without
        // dismissing) lets users click the website link or select the
        // about text.
        userTooltipEl.addEventListener('mouseenter', cancelUserTooltipHide);
        userTooltipEl.addEventListener('mouseleave', scheduleUserTooltipHide);
        document.body.appendChild(userTooltipEl);
        return userTooltipEl;
    }

    function fetchUserProfile(username) {
        if (userProfileCache[username]) {
            return Promise.resolve(userProfileCache[username]);
        }
        if (userProfileInflight[username]) {
            return userProfileInflight[username];
        }
        var promise = apiRequest('GET', 'user?username=' + encodeURIComponent(username))
            .then(function (data) {
                var profile = (data && data.profile) || { username: username };
                userProfileCache[username] = profile;
                delete userProfileInflight[username];
                return profile;
            })
            .catch(function () {
                // Swallow the error - tooltips are a nice-to-have and a
                // transient API hiccup should not break the surrounding
                // view. Cache a stub so we do not hammer the backend on
                // repeated hovers.
                var stub = { username: username, displayName: '', websiteUrl: '', about: '', avatar: null };
                userProfileCache[username] = stub;
                delete userProfileInflight[username];
                return stub;
            });
        userProfileInflight[username] = promise;
        return promise;
    }

    function renderUserTooltip(profile) {
        var tip = getUserTooltipEl();
        clear(tip);

        var header = el('div', { className: 'user-tooltip-header' });
        var avatarSlot;
        if (profile && profile.avatar) {
            avatarSlot = el('img', {
                className: 'user-tooltip-avatar',
                src: profile.avatar,
                alt: ''
            });
        } else {
            var initial = userLabel({
                displayName: profile && profile.displayName,
                username: profile && profile.username,
                fallback: '?'
            }).charAt(0).toUpperCase() || '?';
            avatarSlot = el('div', {
                className: 'user-tooltip-avatar user-tooltip-avatar-placeholder',
                text: initial
            });
        }
        header.appendChild(avatarSlot);

        var name = userLabel({
            displayName: profile && profile.displayName,
            username: profile && profile.username,
            fallback: profile && profile.username ? profile.username : ''
        });
        var identity = el('div', { className: 'user-tooltip-identity' });
        identity.appendChild(el('p', {
            className: 'user-tooltip-name',
            text: name || '\u2014'
        }));
        if (profile && profile.username && profile.username !== name) {
            identity.appendChild(el('p', {
                className: 'user-tooltip-username muted',
                text: '@' + profile.username
            }));
        }
        header.appendChild(identity);
        tip.appendChild(header);

        if (profile && profile.websiteUrl) {
            tip.appendChild(el('a', {
                className: 'user-tooltip-url',
                href: profile.websiteUrl,
                target: '_blank',
                rel: 'noopener',
                text: profile.websiteUrl
            }));
        }
        if (profile && profile.about) {
            tip.appendChild(el('p', {
                className: 'user-tooltip-about',
                text: profile.about
            }));
        }
        if ((!profile || (!profile.websiteUrl && !profile.about)) && (!profile || !profile.avatar)) {
            tip.appendChild(el('p', {
                className: 'user-tooltip-empty muted',
                text: 'No profile details yet.'
            }));
        }
    }

    function positionUserTooltip(target) {
        var tip = getUserTooltipEl();
        var rect = target.getBoundingClientRect();
        // Show the tooltip below the mention by default; flip above
        // when there is no room. Horizontal position tracks the
        // mention but gets nudged back inside the viewport.
        var margin = 8;
        tip.style.visibility = 'hidden';
        tip.hidden = false;
        var tipRect = tip.getBoundingClientRect();
        var top = rect.bottom + margin + window.pageYOffset;
        if (rect.bottom + margin + tipRect.height > window.innerHeight
            && rect.top - margin - tipRect.height > 0) {
            top = rect.top - margin - tipRect.height + window.pageYOffset;
        }
        var left = rect.left + window.pageXOffset;
        var maxLeft = window.pageXOffset + window.innerWidth - tipRect.width - margin;
        if (left > maxLeft) {
            left = maxLeft;
        }
        if (left < window.pageXOffset + margin) {
            left = window.pageXOffset + margin;
        }
        tip.style.left = Math.max(0, Math.round(left)) + 'px';
        tip.style.top = Math.max(0, Math.round(top)) + 'px';
        tip.style.visibility = '';
    }

    function showUserTooltip(target) {
        cancelUserTooltipHide();
        var username = target.getAttribute('data-user-mention') || '';
        if (!username) {
            return;
        }
        userTooltipTarget = target;
        var tip = getUserTooltipEl();
        // Render an initial placeholder so the first hover feels
        // responsive even if the network roundtrip is slow.
        renderUserTooltip({ username: username, displayName: target.textContent || '' });
        tip.hidden = false;
        positionUserTooltip(target);
        fetchUserProfile(username).then(function (profile) {
            if (userTooltipTarget !== target) {
                return;
            }
            renderUserTooltip(profile);
            positionUserTooltip(target);
        });
    }

    function hideUserTooltip() {
        if (!userTooltipEl) {
            return;
        }
        userTooltipEl.hidden = true;
        userTooltipTarget = null;
    }

    function scheduleUserTooltipHide() {
        cancelUserTooltipHide();
        userTooltipHideTimer = setTimeout(hideUserTooltip, 120);
    }

    function cancelUserTooltipHide() {
        if (userTooltipHideTimer) {
            clearTimeout(userTooltipHideTimer);
            userTooltipHideTimer = null;
        }
    }

    function initUserTooltips() {
        document.addEventListener('mouseover', function (event) {
            var target = event.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }
            var mention = target.closest('[data-user-mention]');
            if (!mention) {
                return;
            }
            showUserTooltip(mention);
        });
        document.addEventListener('mouseout', function (event) {
            var target = event.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }
            var mention = target.closest('[data-user-mention]');
            if (!mention) {
                return;
            }
            // Only schedule a hide when the pointer leaves for somewhere
            // that is neither the mention nor the tooltip itself; that
            // keeps the card available long enough to click the link.
            var related = event.relatedTarget;
            if (related && typeof related.closest === 'function') {
                if (related.closest('[data-user-mention]') === mention) {
                    return;
                }
                if (userTooltipEl && related.closest('.user-tooltip') === userTooltipEl) {
                    return;
                }
            }
            scheduleUserTooltipHide();
        });
        document.addEventListener('focusin', function (event) {
            var target = event.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }
            var mention = target.closest('[data-user-mention]');
            if (mention) {
                showUserTooltip(mention);
            }
        });
        document.addEventListener('focusout', function (event) {
            var target = event.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }
            if (target.closest('[data-user-mention]')) {
                scheduleUserTooltipHide();
            }
        });
        window.addEventListener('scroll', hideUserTooltip, true);
        window.addEventListener('resize', hideUserTooltip);
    }

    var draggedCard = null;
    var draggedOriginParent = null;
    var draggedOriginNext = null;

    // Build the card shown on the releases page for a single
    // project. When `withinGroup` is true the enclosing group
    // already shows the umbrella category, so only the intermediate
    // breadcrumb (everything between the root and the leaf) is
    // surfaced to avoid repeating the group name on every card.
    function buildReleaseProjectEl(project, withinGroup) {
        var releases = project.releases || [];
        var latest = releases[0];
        var path = Array.isArray(project.path) ? project.path : [project.name];
        var headerChildren = [el('h3', { text: project.name })];
        if (withinGroup && path.length > 2) {
            headerChildren.push(el('p', {
                className: 'release-project-path',
                text: path.slice(1, -1).join(' / ')
            }));
        }
        if (latest) {
            headerChildren.push(el('p', { className: 'release-project-meta' }, [
                'Latest: ',
                el('strong', { text: latest.version }),
                ' \u00b7 ',
                el('time', { datetime: latest.date, text: latest.date })
            ]));
        }
        if (typeof project.totalHours === 'number' && project.totalHours > 0) {
            headerChildren.push(el('p', { className: 'release-project-total' }, [
                'Total time: ',
                el('strong', { text: formatHours(project.totalHours) + 'h' })
            ]));
        }

        var list = el('ol', { className: 'release-list' });
        releases.forEach(function (release) {
            var releaseChildren = [
                el('span', { className: 'release-version', text: release.version }),
                el('time', {
                    className: 'release-date',
                    datetime: release.date,
                    text: release.date
                })
            ];
            if (typeof release.totalHours === 'number' && release.totalHours > 0) {
                releaseChildren.push(el('span', {
                    className: 'release-time',
                    text: formatHours(release.totalHours) + 'h'
                }));
            }
            releaseChildren.push(el('p', { className: 'release-notes', text: release.notes }));
            var contributors = Array.isArray(release.contributors) ? release.contributors : [];
            if (contributors.length > 0) {
                var contribList = el('ul', { className: 'release-contributors' });
                contributors.forEach(function (contributor) {
                    var nameEl = userMentionEl({
                        username: contributor.username,
                        displayName: contributor.displayName,
                        fallback: contributor.userId != null ? '#' + contributor.userId : ''
                    }, { className: 'release-contributor-name' });
                    contribList.appendChild(el('li', { className: 'release-contributor' }, [
                        nameEl,
                        ' \u00b7 ',
                        el('span', {
                            className: 'release-contributor-hours',
                            text: formatHours(contributor.hours) + 'h'
                        })
                    ]));
                });
                releaseChildren.push(el('p', {
                    className: 'release-contributors-heading muted',
                    text: 'Contributors'
                }));
                releaseChildren.push(contribList);
            }
            var issues = Array.isArray(release.issues) ? release.issues : [];
            if (issues.length > 0) {
                var issuesList = el('ul', { className: 'release-issues' });
                issues.forEach(function (issue) {
                    var link = el('a', {
                        className: 'release-issue-link',
                        href: 'kanban.html#issue-' + issue.id,
                        text: '#' + issue.id + ' ' + issue.title
                    });
                    var item = el('li', {
                        className: 'release-issue release-issue-' + (issue.status || 'unknown')
                    }, [link]);
                    issuesList.appendChild(item);
                });
                releaseChildren.push(issuesList);
            }
            list.appendChild(el('li', { className: 'release' }, releaseChildren));
        });

        return el('article', { className: 'release-project' }, [
            el('header', { className: 'release-project-header' }, headerChildren),
            list
        ]);
    }

    // Collapse a [parents..., leaf] breadcrumb to a single line using
    // the same separator as the backend. Falls back to `card.category`
    // (the pre-hierarchy flat name) when no path is supplied.
    function categoryLabel(card) {
        var path = Array.isArray(card.categoryPath) ? card.categoryPath : null;
        if (path && path.length > 0) {
            return path.join(' / ');
        }
        return card.category || '';
    }

    function buildCardChildren(card) {
        var path = cardCategoryPath(card);
        var categoryNode;
        if (path.length > 0) {
            // Render a breadcrumb where each segment except the last
            // is de-emphasised, so the leaf project (the thing the
            // card actually belongs to) stands out at a glance. This
            // runs even for a single-segment path so the leaf is still
            // highlighted when there is no parent category.
            var crumbs = [document.createTextNode('Category: ')];
            for (var i = 0; i < path.length; i++) {
                var isLeaf = i === path.length - 1;
                crumbs.push(el('span', {
                    className: isLeaf ? 'kanban-cat-leaf' : 'kanban-cat-parent',
                    text: path[i]
                }));
                if (!isLeaf) {
                    crumbs.push(el('span', { className: 'kanban-cat-sep', text: ' / ' }));
                }
            }
            categoryNode = el('p', { className: 'kanban-meta kanban-category' }, crumbs);
        } else {
            categoryNode = el('p', {
                className: 'kanban-meta',
                text: 'Category: ' + categoryLabel(card)
            });
        }

        var description = typeof card.description === 'string' ? card.description : '';
        var descriptionNode = description !== ''
            ? el('p', { className: 'kanban-description', text: description })
            : el('p', { className: 'kanban-description kanban-description-empty', text: 'No description' });

        var titleText = card.id != null
            ? '#' + card.id + ' ' + card.title
            : card.title;
        return [
            el('h4', { text: titleText }),
            descriptionNode,
            categoryNode,
            el('p', { className: 'kanban-meta', text: 'Milestone: ' + (card.milestone || '\u2014') }),
            el('p', { className: 'kanban-meta', 'data-field': 'work-started' },
                ['Work started: ', dateOrDash(card.workStarted)]),
            el('p', { className: 'kanban-meta', 'data-field': 'work-completed' },
                ['Work completed: ', dateOrDash(card.workCompleted)]),
            el('p', {
                className: 'kanban-meta kanban-time',
                text: 'Time spent: ' + formatHours(card.timeSpent) + 'h'
            })
        ];
    }

    function buildCardEl(card, canEdit) {
        var cardEl = el('article', { className: 'kanban-card' }, buildCardChildren(card));
        if (card.id != null) {
            cardEl.setAttribute('data-card-id', String(card.id));
        }
        if (canEdit) {
            makeDraggable(cardEl);
        }
        // Clicking a card opens the detail view. Skip the click when
        // a drag just finished (otherwise dropping onto an empty area
        // of the same column would pop the modal open unexpectedly).
        cardEl.addEventListener('click', function (e) {
            if (cardEl.classList.contains('kanban-card-dragging')) {
                return;
            }
            if (e.defaultPrevented) {
                return;
            }
            if (card.id == null) {
                return;
            }
            openDetailView(card.id, cardEl, canEdit);
        });
        return cardEl;
    }

    // Extract an issue id from a `#issue-<id>` URL hash. Returns null
    // when the hash is absent or doesn't match the expected shape so
    // callers can use the result as a simple truthiness test.
    function parseIssueHash() {
        var hash = String(window.location.hash || '').replace(/^#/, '');
        var match = /^issue-(\d+)$/.exec(hash);
        if (!match) {
            return null;
        }
        var id = parseInt(match[1], 10);
        return isFinite(id) && id > 0 ? id : null;
    }

    // Write/remove the `#issue-<id>` fragment via history.replaceState
    // so it doesn't pollute the browser's back-stack and doesn't fire
    // a hashchange event that would re-enter the open/close logic.
    function setIssueHash(id) {
        var target = '#issue-' + id;
        if (window.location.hash === target) {
            return;
        }
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', target);
        } else {
            window.location.hash = 'issue-' + id;
        }
    }

    function clearIssueHash() {
        if (!window.location.hash) {
            return;
        }
        if (window.history && window.history.replaceState) {
            var url = window.location.pathname + window.location.search;
            window.history.replaceState(null, '', url);
        } else {
            window.location.hash = '';
        }
    }

    // On first render, check the URL hash and pop open the matching
    // card's detail view so e.g. kanban.html#issue-42 lands directly on
    // issue 42. The card element is looked up so edits still propagate
    // back to the board, but a missing cardEl is tolerated (the issue
    // may be filtered out of the current view, or live on another page)
    // since the modal fetches its own payload from the API.
    function maybeOpenIssueFromHash(container) {
        if (!document.querySelector('[data-detail-overlay]')) {
            return;
        }
        var id = parseIssueHash();
        if (!id) {
            return;
        }
        var cardEl = null;
        if (container && typeof container.querySelector === 'function') {
            cardEl = container.querySelector('[data-card-id="' + id + '"]');
        }
        openDetailView(id, cardEl);
    }

    // Task detail modal. Shares one overlay (defined in kanban.html)
    // across every card; each open() replaces its contents. `canEdit`
    // controls whether the modal exposes any edit affordances - signed-out
    // visitors get a read-only view of links, time entries and comments.
    function openDetailView(issueId, cardEl, canEdit) {
        var overlay = document.querySelector('[data-detail-overlay]');
        var body = document.querySelector('[data-detail-body]');
        if (!overlay || !body) {
            return;
        }
        var closeBtn = document.querySelector('[data-detail-close]');
        overlay.hidden = false;
        clear(body);
        body.appendChild(el('p', { className: 'muted', text: 'Loading\u2026' }));
        // Reflect the open card in the URL so users can copy/share a
        // direct link to it. Cleared again in close().
        setIssueHash(issueId);

        function close() {
            overlay.hidden = true;
            clear(body);
            overlay.removeEventListener('click', onOverlayClick);
            document.removeEventListener('keydown', onKey);
            if (closeBtn) {
                closeBtn.removeEventListener('click', close);
            }
            clearIssueHash();
        }
        function onOverlayClick(e) {
            if (e.target === overlay) {
                close();
            }
        }
        function onKey(e) {
            if (e.key === 'Escape') {
                close();
            }
        }
        overlay.addEventListener('click', onOverlayClick);
        document.addEventListener('keydown', onKey);
        if (closeBtn) {
            closeBtn.addEventListener('click', close);
        }

        apiRequest('POST', 'issue', { id: issueId })
            .then(function (data) {
                clear(body);
                renderDetailView(body, data, cardEl, close, canEdit);
            })
            .catch(function (err) {
                clear(body);
                body.appendChild(el('p', {
                    className: 'detail-error',
                    text: 'Failed to load task: ' + (err.message || 'unknown error')
                }));
            });
    }

    // Render the three-section detail view (edit form, time entries,
    // comments) into `container`. `cardEl` is the kanban card the user
    // clicked to open the view; it is updated in place when an edit
    // succeeds so the board reflects the change without a full reload.
    // When `canEdit` is false the edit form is omitted and the link,
    // time and comment sections are rendered without their add/remove
    // controls so non-authenticated viewers only get read-only data.
    function renderDetailView(container, data, cardEl, close, canEdit) {
        var issue = data && data.issue ? data.issue : {};
        var timeEntries = (data && data.timeEntries) || [];
        var comments = (data && data.comments) || [];
        var blockedBy = (data && data.blockedBy) || [];
        var blocks = (data && data.blocks) || [];

        container.appendChild(el('h3', { id: 'detail-title', text: issue.title || 'Task' }));

        container.appendChild(buildEditSection(issue, cardEl, canEdit));
        container.appendChild(buildLinkSection(issue, blockedBy, blocks, canEdit));
        container.appendChild(buildTimeSection(issue, timeEntries, cardEl, canEdit));
        container.appendChild(buildCommentSection(issue, comments, canEdit));
    }

    function buildEditSection(issue, cardEl, canEdit) {
        var section = el('section', { className: 'detail-section' });
        section.appendChild(el('h4', { text: canEdit ? 'Edit' : 'Details' }));

        var form = el('form', { className: 'detail-form' });
        var titleInput = el('input', {
            type: 'text',
            required: 'required',
            value: issue.title || ''
        });
        titleInput.value = issue.title || '';
        var descriptionInput = el('textarea', { rows: '4' });
        descriptionInput.value = issue.description || '';
        var categoryInput = el('input', {
            type: 'text',
            placeholder: 'e.g. Mods / Skyrim / Idrinth Thalui'
        });
        categoryInput.value = Array.isArray(issue.categoryPath) && issue.categoryPath.length
            ? issue.categoryPath.join(' / ')
            : (issue.category || '');
        var milestoneInput = el('input', { type: 'text', placeholder: 'Milestone (optional)' });
        milestoneInput.value = issue.milestone || '';

        var statusSelect = el('select');
        var statusOptions = [
            { value: 'todo', label: 'Todo' },
            { value: 'in-progress', label: 'In Progress' },
            { value: 'waiting', label: 'Waiting' },
            { value: 'done', label: 'Done' },
            { value: 'discarded', label: 'Discarded' }
        ];
        statusOptions.forEach(function (opt) {
            var o = el('option', { value: opt.value, text: opt.label });
            if (opt.value === issue.status) {
                o.setAttribute('selected', 'selected');
            }
            statusSelect.appendChild(o);
        });

        var startedInput = el('input', { type: 'date' });
        startedInput.value = issue.workStarted || '';
        var completedInput = el('input', { type: 'date' });
        completedInput.value = issue.workCompleted || '';

        if (!canEdit) {
            // Viewers see the same fields so nothing disappears from
            // the detail view, but every control is locked down.
            // `readonly` keeps the value visible and selectable on
            // text-like inputs; `<select>` only supports `disabled`.
            titleInput.readOnly = true;
            descriptionInput.readOnly = true;
            categoryInput.readOnly = true;
            milestoneInput.readOnly = true;
            startedInput.readOnly = true;
            completedInput.readOnly = true;
            statusSelect.disabled = true;
        }

        form.appendChild(field('Title', titleInput, true));
        form.appendChild(field('Status', statusSelect, false));
        form.appendChild(field('Category', categoryInput, true));
        form.appendChild(field('Milestone', milestoneInput, true));
        form.appendChild(field('Work started', startedInput, false));
        form.appendChild(field('Work completed', completedInput, false));
        form.appendChild(field('Description', descriptionInput, true));

        if (!canEdit) {
            section.appendChild(form);
            return section;
        }

        var submitBtn = el('button', {
            type: 'submit',
            className: 'detail-button',
            text: 'Save'
        });
        var statusNode = el('p', { className: 'detail-status' });
        var errorNode = el('p', { className: 'detail-error', hidden: 'hidden' });

        form.appendChild(el('div', { className: 'detail-actions' }, [submitBtn, statusNode]));
        form.appendChild(errorNode);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            errorNode.hidden = true;
            errorNode.textContent = '';
            submitBtn.disabled = true;
            statusNode.textContent = 'Saving\u2026';
            var payload = {
                id: issue.id,
                title: titleInput.value.trim(),
                description: descriptionInput.value.trim(),
                category: categoryInput.value.trim() || 'Uncategorised',
                milestone: milestoneInput.value.trim(),
                status: statusSelect.value,
                workStarted: startedInput.value,
                workCompleted: completedInput.value
            };
            apiRequest('POST', 'issue-update', payload)
                .then(function (result) {
                    submitBtn.disabled = false;
                    statusNode.textContent = 'Saved.';
                    var updated = (result && result.issue) || payload;
                    issue.title = updated.title;
                    issue.description = updated.description;
                    issue.category = updated.category;
                    issue.categoryPath = updated.categoryPath || null;
                    issue.milestone = updated.milestone;
                    issue.status = updated.status;
                    issue.workStarted = updated.workStarted;
                    issue.workCompleted = updated.workCompleted;
                    applyCardUpdate(cardEl, issue);
                })
                .catch(function (err) {
                    submitBtn.disabled = false;
                    statusNode.textContent = '';
                    errorNode.textContent = err.message || 'Failed to save';
                    errorNode.hidden = false;
                });
        });

        section.appendChild(form);
        return section;
    }

    // Render the "Blocked by / Blocks" section of the detail modal:
    // two lists of linked issues plus a small form to add a new
    // "blocked by" link. Each row carries a remove button so the
    // user can break the link without leaving the dialog. When
    // `canEdit` is false the add-link form is omitted and the
    // per-link remove buttons are not rendered.
    function buildLinkSection(issue, blockedBy, blocks, canEdit) {
        var section = el('section', { className: 'detail-section' });
        section.appendChild(el('h4', { text: 'Links' }));

        var blockedByWrap = el('div', { className: 'detail-links' });
        blockedByWrap.appendChild(el('p', {
            className: 'detail-links-label',
            text: 'Blocked by'
        }));
        var blockedByList = el('div');
        blockedByWrap.appendChild(blockedByList);

        var blocksWrap = el('div', { className: 'detail-links' });
        blocksWrap.appendChild(el('p', {
            className: 'detail-links-label',
            text: 'Blocks'
        }));
        var blocksList = el('div');
        blocksWrap.appendChild(blocksList);

        function renderList(wrap, items, removable) {
            clear(wrap);
            if (!items.length) {
                wrap.appendChild(el('p', {
                    className: 'detail-empty',
                    text: 'None.'
                }));
                return;
            }
            var list = el('ul', { className: 'detail-link-list' });
            items.forEach(function (link) {
                var statusLabel = (link.status || '').replace(/-/g, ' ');
                var meta = '#' + link.id + ' \u00b7 ' + (link.title || '');
                if (link.category) {
                    meta += ' \u00b7 ' + link.category;
                }
                if (statusLabel) {
                    meta += ' \u00b7 ' + statusLabel;
                }
                var children = [
                    el('span', { className: 'detail-link-text', text: meta })
                ];
                if (removable) {
                    var removeBtn = el('button', {
                        type: 'button',
                        className: 'detail-link-remove',
                        text: 'Remove'
                    });
                    removeBtn.addEventListener('click', function () {
                        removeBtn.disabled = true;
                        apiRequest('POST', 'issue-link-remove', { linkId: link.linkId })
                            .then(function () {
                                var idx = items.indexOf(link);
                                if (idx >= 0) {
                                    items.splice(idx, 1);
                                }
                                renderList(wrap, items, removable);
                            })
                            .catch(function (err) {
                                removeBtn.disabled = false;
                                if (window.console) {
                                    window.console.warn('issue-link-remove failed: ' + err.message);
                                }
                            });
                    });
                    children.push(removeBtn);
                }
                list.appendChild(el('li', { className: 'detail-link' }, children));
            });
            wrap.appendChild(list);
        }

        renderList(blockedByList, blockedBy, canEdit);
        renderList(blocksList, blocks, false);

        section.appendChild(blockedByWrap);
        section.appendChild(blocksWrap);

        if (!canEdit) {
            return section;
        }

        // Add-link form. Only "blocked by" is editable - the inverse
        // direction is implied and shown above. Issue ids accept "#42"
        // or "42" so users can paste either form.
        var form = el('form', { className: 'detail-form detail-link-form' });
        var blockerInput = el('input', {
            type: 'text',
            placeholder: 'Issue id (e.g. 42)',
            required: 'required'
        });
        var submitBtn = el('button', {
            type: 'submit',
            className: 'detail-button',
            text: 'Add blocker'
        });
        var statusNode = el('p', { className: 'detail-status' });
        var errorNode = el('p', { className: 'detail-error', hidden: 'hidden' });

        form.appendChild(field('Add blocker', blockerInput, false));
        form.appendChild(el('div', { className: 'detail-actions' }, [submitBtn, statusNode]));
        form.appendChild(errorNode);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            errorNode.hidden = true;
            errorNode.textContent = '';
            var raw = blockerInput.value.trim().replace(/^#/, '');
            var blockerId = parseInt(raw, 10);
            if (!isFinite(blockerId) || blockerId <= 0) {
                errorNode.textContent = 'Enter a positive issue id';
                errorNode.hidden = false;
                return;
            }
            submitBtn.disabled = true;
            statusNode.textContent = 'Linking\u2026';
            apiRequest('POST', 'issue-link-add', { id: issue.id, blockedBy: blockerId })
                .then(function (result) {
                    submitBtn.disabled = false;
                    statusNode.textContent = 'Linked.';
                    blockerInput.value = '';
                    if (result && result.link) {
                        blockedBy.push(result.link);
                        renderList(blockedByList, blockedBy, true);
                    }
                })
                .catch(function (err) {
                    submitBtn.disabled = false;
                    statusNode.textContent = '';
                    errorNode.textContent = err.message || 'Failed to add link';
                    errorNode.hidden = false;
                });
        });

        section.appendChild(form);
        return section;
    }

    function buildTimeSection(issue, entries, cardEl, canEdit) {
        var section = el('section', { className: 'detail-section' });
        section.appendChild(el('h4', { text: 'Time spent' }));

        var listWrap = el('div');
        renderTimeEntries(listWrap, entries);
        section.appendChild(listWrap);

        if (!canEdit) {
            return section;
        }

        var form = el('form', { className: 'detail-form' });
        var dateInput = el('input', { type: 'date', required: 'required' });
        dateInput.value = new Date().toISOString().slice(0, 10);
        var hoursInput = el('input', {
            type: 'number',
            step: '0.25',
            min: '0',
            required: 'required',
            placeholder: '1.5'
        });
        var categoryInput = el('input', {
            type: 'text',
            placeholder: 'Category (e.g. Development)'
        });
        var noteInput = el('textarea', { rows: '2', placeholder: 'Note (optional)' });
        var submitBtn = el('button', {
            type: 'submit',
            className: 'detail-button',
            text: 'Log time'
        });
        var statusNode = el('p', { className: 'detail-status' });
        var errorNode = el('p', { className: 'detail-error', hidden: 'hidden' });

        form.appendChild(field('Date', dateInput, false));
        form.appendChild(field('Hours', hoursInput, false));
        form.appendChild(field('Category', categoryInput, true));
        form.appendChild(field('Note', noteInput, true));
        form.appendChild(el('div', { className: 'detail-actions' }, [submitBtn, statusNode]));
        form.appendChild(errorNode);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            errorNode.hidden = true;
            errorNode.textContent = '';
            var hours = parseFloat(hoursInput.value);
            if (!isFinite(hours) || hours <= 0) {
                errorNode.textContent = 'Hours must be greater than zero';
                errorNode.hidden = false;
                return;
            }
            submitBtn.disabled = true;
            statusNode.textContent = 'Saving\u2026';
            var payload = {
                id: issue.id,
                spentOn: dateInput.value,
                hours: hours,
                category: categoryInput.value.trim(),
                note: noteInput.value.trim()
            };
            apiRequest('POST', 'issue-time-add', payload)
                .then(function (result) {
                    submitBtn.disabled = false;
                    statusNode.textContent = 'Logged.';
                    var entry = (result && result.entry) || payload;
                    entries.unshift({
                        id: entry.id,
                        spentOn: entry.spentOn,
                        hours: entry.hours,
                        category: entry.category || '',
                        note: entry.note || '',
                        userId: entry.userId != null ? entry.userId : null,
                        userName: entry.userName || '',
                        displayName: entry.displayName || ''
                    });
                    renderTimeEntries(listWrap, entries);
                    hoursInput.value = '';
                    categoryInput.value = '';
                    noteInput.value = '';
                    var total = result && typeof result.timeSpent === 'number'
                        ? result.timeSpent
                        : null;
                    if (total != null) {
                        issue.timeSpent = total;
                        updateCardTimeSpent(cardEl, total);
                    }
                })
                .catch(function (err) {
                    submitBtn.disabled = false;
                    statusNode.textContent = '';
                    errorNode.textContent = err.message || 'Failed to log time';
                    errorNode.hidden = false;
                });
        });

        section.appendChild(form);
        return section;
    }

    function renderTimeEntries(wrap, entries) {
        clear(wrap);
        if (!entries.length) {
            wrap.appendChild(el('p', { className: 'detail-empty', text: 'No time logged yet.' }));
            return;
        }
        var list = el('ul', { className: 'detail-entries' });
        entries.forEach(function (entry) {
            var meta = entry.spentOn + ' \u00b7 ' + formatHours(entry.hours) + 'h';
            if (entry.category) {
                meta += ' \u00b7 ' + entry.category;
            }
            // Attribution: always prefer the display name, falling
            // back to the login username and finally to the raw user id
            // so legacy rows backfilled with the bootstrap user are
            // still visibly tagged.
            var metaParts = [document.createTextNode(meta)];
            var hasWho = !!(entry.displayName || entry.userName || entry.userId != null);
            if (hasWho) {
                metaParts.push(document.createTextNode(' \u00b7 '));
                metaParts.push(userMentionEl({
                    username: entry.userName,
                    displayName: entry.displayName,
                    fallback: entry.userId != null ? '#' + entry.userId : ''
                }));
            }
            var children = [el('p', { className: 'detail-entry-meta' }, metaParts)];
            if (entry.note) {
                children.push(el('p', { className: 'detail-entry-note', text: entry.note }));
            }
            list.appendChild(el('li', { className: 'detail-entry' }, children));
        });
        wrap.appendChild(list);
    }

    function buildCommentSection(issue, comments, canEdit) {
        var section = el('section', { className: 'detail-section' });
        section.appendChild(el('h4', { text: 'Comments' }));

        var listWrap = el('div');
        renderComments(listWrap, comments);
        section.appendChild(listWrap);

        if (!canEdit) {
            return section;
        }

        var form = el('form', { className: 'detail-form' });
        var bodyInput = el('textarea', {
            rows: '3',
            placeholder: 'Leave a comment\u2026'
        });
        // Accept multiple images, videos or audio clips. The `accept`
        // attribute hints to the browser's file picker; the server
        // re-validates the mime type on every upload, so users that
        // bypass the hint still get a clean 400 rather than something
        // unexpected in the DB. The list is kept in sync with the
        // server-side whitelist in CommentAttachments::MIME_KINDS so
        // the picker only surfaces files the server will accept.
        var acceptedMimes = [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif',
            'video/mp4', 'video/webm', 'video/ogg',
            'audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav',
            'audio/webm', 'audio/mp4', 'audio/aac', 'audio/flac'
        ];
        var fileInput = el('input', {
            type: 'file',
            multiple: 'multiple',
            accept: acceptedMimes.join(','),
            className: 'detail-file-input'
        });
        var submitBtn = el('button', {
            type: 'submit',
            className: 'detail-button',
            text: 'Post comment'
        });
        var statusNode = el('p', { className: 'detail-status' });
        var errorNode = el('p', { className: 'detail-error', hidden: 'hidden' });

        form.appendChild(field('Comment', bodyInput, true));
        form.appendChild(field('Attachments (images, video, audio)', fileInput, true));
        form.appendChild(el('div', { className: 'detail-actions' }, [submitBtn, statusNode]));
        form.appendChild(errorNode);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            errorNode.hidden = true;
            errorNode.textContent = '';
            var text = bodyInput.value.trim();
            var files = fileInput.files ? Array.prototype.slice.call(fileInput.files) : [];
            if (!text && files.length === 0) {
                errorNode.textContent = 'Add a comment or attach at least one file.';
                errorNode.hidden = false;
                return;
            }
            submitBtn.disabled = true;
            statusNode.textContent = 'Posting\u2026';

            // Send as multipart when files are attached so the browser
            // streams them directly; fall back to plain JSON for
            // text-only comments to keep the hot path cheap.
            var payload;
            if (files.length > 0) {
                payload = new FormData();
                payload.append('id', String(issue.id));
                payload.append('body', text);
                files.forEach(function (file) {
                    payload.append('files[]', file, file.name);
                });
            } else {
                payload = { id: issue.id, body: text };
            }

            apiRequest('POST', 'issue-comment-add', payload)
                .then(function (result) {
                    submitBtn.disabled = false;
                    statusNode.textContent = 'Posted.';
                    var c = (result && result.comment) || {
                        author: '',
                        body: text,
                        createdAt: new Date().toISOString(),
                        attachments: []
                    };
                    if (!c.attachments) {
                        c.attachments = [];
                    }
                    comments.push(c);
                    renderComments(listWrap, comments);
                    bodyInput.value = '';
                    fileInput.value = '';
                })
                .catch(function (err) {
                    submitBtn.disabled = false;
                    statusNode.textContent = '';
                    errorNode.textContent = err.message || 'Failed to post comment';
                    errorNode.hidden = false;
                });
        });

        section.appendChild(form);
        return section;
    }

    function renderComments(wrap, comments) {
        clear(wrap);
        if (!comments.length) {
            wrap.appendChild(el('p', { className: 'detail-empty', text: 'No comments yet.' }));
            return;
        }
        var list = el('ul', { className: 'detail-comments' });
        comments.forEach(function (c) {
            var hasAuthor = !!(c.author || c.displayName);
            var metaChildren = [];
            if (hasAuthor) {
                metaChildren.push(userMentionEl({
                    username: c.author,
                    displayName: c.displayName,
                    fallback: 'unknown'
                }));
            } else {
                metaChildren.push(document.createTextNode('unknown'));
            }
            metaChildren.push(document.createTextNode(' \u00b7 ' + (c.createdAt || '')));
            var item = el('li', { className: 'detail-comment' }, [
                el('p', { className: 'detail-comment-meta' }, metaChildren)
            ]);
            if (c.body) {
                item.appendChild(el('p', { className: 'detail-comment-body', text: c.body }));
            }
            var attachments = c.attachments || [];
            if (attachments.length) {
                item.appendChild(renderCommentAttachments(attachments));
            }
            list.appendChild(item);
        });
        wrap.appendChild(list);
    }

    // Render a comment's media attachments. Each attachment is shown
    // inline with the tag that matches its `kind` (image / video /
    // audio); unknown kinds fall back to a plain download link so a
    // future server-side addition never leaves a comment looking
    // empty.
    function renderCommentAttachments(attachments) {
        var wrap = el('div', { className: 'detail-comment-attachments' });
        attachments.forEach(function (att) {
            var url = att.url || ('comment-attachment?id=' + att.id);
            var name = att.originalName || 'attachment';
            if (att.kind === 'image') {
                var img = el('img', {
                    className: 'detail-comment-media detail-comment-image',
                    src: url,
                    alt: name,
                    loading: 'lazy'
                });
                var link = el('a', {
                    href: url,
                    target: '_blank',
                    rel: 'noopener',
                    className: 'detail-comment-media-link'
                });
                link.appendChild(img);
                wrap.appendChild(link);
            } else if (att.kind === 'video') {
                var video = el('video', {
                    className: 'detail-comment-media detail-comment-video',
                    src: url,
                    controls: 'controls',
                    preload: 'metadata'
                });
                wrap.appendChild(video);
            } else if (att.kind === 'audio') {
                var audio = el('audio', {
                    className: 'detail-comment-media detail-comment-audio',
                    src: url,
                    controls: 'controls',
                    preload: 'metadata'
                });
                wrap.appendChild(audio);
            } else {
                wrap.appendChild(el('a', {
                    href: url,
                    target: '_blank',
                    rel: 'noopener',
                    className: 'detail-comment-download',
                    text: name
                }));
            }
        });
        return wrap;
    }

    function field(labelText, input, full) {
        var label = el('label', { className: 'detail-field' + (full ? ' detail-field-full' : '') });
        label.appendChild(el('span', { text: labelText }));
        label.appendChild(input);
        return label;
    }

    // Update the visible kanban card after an edit. Rebuilds the card
    // in place so breadcrumbs, milestone and work dates all reflect
    // the new state. If the status changed the card is moved to the
    // bottom of the matching column.
    function applyCardUpdate(cardEl, issue) {
        if (!cardEl || !cardEl.parentNode) {
            return;
        }
        var parent = cardEl.parentNode;
        var currentStatus = parent.getAttribute('data-column-id') || '';
        if (issue.status && issue.status !== currentStatus) {
            var candidate = document.querySelector('[data-column-id="' + issue.status + '"]');
            if (candidate && candidate !== parent) {
                candidate.appendChild(cardEl);
            }
        }
        var card = {
            id: issue.id,
            title: issue.title,
            description: issue.description,
            category: issue.category,
            categoryPath: issue.categoryPath,
            milestone: issue.milestone,
            workStarted: issue.workStarted,
            workCompleted: issue.workCompleted,
            timeSpent: issue.timeSpent != null ? issue.timeSpent : 0
        };
        // Rewrite the card's contents in place so the element identity
        // is preserved. The detail modal caches this cardEl in the
        // closures for the edit and time-log forms; if we swapped the
        // whole element out, a subsequent time-log submit would write
        // its "Time spent: Xh" update to the detached old node and
        // never reach the real card on the board.
        clear(cardEl);
        var children = buildCardChildren(card);
        for (var i = 0; i < children.length; i++) {
            cardEl.appendChild(children[i]);
        }
        if (card.id != null) {
            cardEl.setAttribute('data-card-id', String(card.id));
        }
    }

    // In-place update of a kanban card's "Time spent" line so the
    // board reflects a newly logged entry without a full rebuild.
    function updateCardTimeSpent(cardEl, totalHours) {
        if (!cardEl) {
            return;
        }
        var node = cardEl.querySelector('.kanban-time');
        if (node) {
            node.textContent = 'Time spent: ' + formatHours(totalHours) + 'h';
        }
    }

    function makeDraggable(cardEl) {
        cardEl.setAttribute('draggable', 'true');
        cardEl.addEventListener('dragstart', function (e) {
            draggedCard = cardEl;
            draggedOriginParent = cardEl.parentNode;
            draggedOriginNext = cardEl.nextSibling;
            cardEl.classList.add('kanban-card-dragging');
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', ''); } catch (err) { /* ignore */ }
            }
        });
        cardEl.addEventListener('dragend', function () {
            cardEl.classList.remove('kanban-card-dragging');
            var movedParent = cardEl.parentNode !== draggedOriginParent;
            var movedIndex = cardEl.nextSibling !== draggedOriginNext;
            if (cardEl.parentNode && (movedParent || movedIndex)) {
                notifyCardMoved(cardEl, draggedOriginParent);
            }
            draggedCard = null;
            draggedOriginParent = null;
            draggedOriginNext = null;
        });
    }

    function notifyCardMoved(cardEl, originParent) {
        var newParent = cardEl.parentNode;
        if (originParent && originParent !== newParent) {
            refreshKanbanColumnCount(originParent);
        }
        refreshKanbanColumnCount(newParent);
        var idAttr = cardEl.getAttribute('data-card-id');
        if (!idAttr) {
            // Card has no persistent id yet (e.g. still saving); skip.
            return;
        }
        var from = originParent ? originParent.getAttribute('data-column-id') : '';
        var to = newParent.getAttribute('data-column-id') || '';
        var siblings = newParent.querySelectorAll('.kanban-card');
        var index = 0;
        for (var i = 0; i < siblings.length; i++) {
            if (siblings[i] === cardEl) {
                index = i;
                break;
            }
        }
        apiRequest('POST', 'kanban-move', {
            id: Number(idAttr),
            from: from,
            to: to,
            index: index
        }).then(function (response) {
            // The backend fills in work_started_at / work_completed_at
            // on status transitions; reflect those stamps on the card
            // so the user sees them without a reload.
            if (response && response.card) {
                updateCardTimestamps(cardEl, response.card.workStarted, response.card.workCompleted);
            }
        }).catch(function (err) {
            if (window.console) {
                window.console.warn('kanban-move failed: ' + err.message);
            }
        });
    }

    function updateCardTimestamps(cardEl, workStarted, workCompleted) {
        var started = cardEl.querySelector('[data-field="work-started"]');
        if (started) {
            started.textContent = '';
            started.appendChild(document.createTextNode('Work started: '));
            started.appendChild(dateOrDash(workStarted));
        }
        var completed = cardEl.querySelector('[data-field="work-completed"]');
        if (completed) {
            completed.textContent = '';
            completed.appendChild(document.createTextNode('Work completed: '));
            completed.appendChild(dateOrDash(workCompleted));
        }
    }

    // Update the "(N)" badge in a kanban column's heading to match the
    // number of cards currently in its card list. Called after any
    // DOM-level mutation that doesn't go through a full re-render
    // (drag-and-drop moves, inline card additions).
    function refreshKanbanColumnCount(cardListEl) {
        if (!cardListEl || !cardListEl.parentNode) {
            return;
        }
        var countEl = cardListEl.parentNode.querySelector('[data-kanban-count]');
        if (!countEl) {
            return;
        }
        var count = cardListEl.querySelectorAll('.kanban-card').length;
        countEl.textContent = '(' + count + ')';
    }

    function findInsertBefore(cardListEl, y) {
        var cards = cardListEl.querySelectorAll('.kanban-card:not(.kanban-card-dragging)');
        for (var i = 0; i < cards.length; i++) {
            var rect = cards[i].getBoundingClientRect();
            if (y < rect.top + rect.height / 2) {
                return cards[i];
            }
        }
        return null;
    }

    function makeDropTarget(cardListEl) {
        cardListEl.addEventListener('dragover', function (e) {
            if (!draggedCard) {
                return;
            }
            e.preventDefault();
            if (e.dataTransfer) {
                e.dataTransfer.dropEffect = 'move';
            }
            var before = findInsertBefore(cardListEl, e.clientY);
            if (before == null) {
                if (draggedCard.parentNode !== cardListEl || draggedCard.nextSibling !== null) {
                    cardListEl.appendChild(draggedCard);
                }
            } else if (before !== draggedCard && before !== draggedCard.nextSibling) {
                cardListEl.insertBefore(draggedCard, before);
            }
        });
        cardListEl.addEventListener('drop', function (e) {
            e.preventDefault();
        });
    }

    // Shared "Add card" modal for the kanban board. One instance is
    // built per board render; each column registers itself with the
    // modal and installs a "+ Add card" button that opens the dialog
    // pre-selected to that column. The modal can also be dismissed
    // via Escape or by clicking the backdrop.
    function buildAddCardModal() {
        var columnsMap = {};
        var escHandler = null;

        var overlay = el('div', { className: 'kanban-modal-overlay', hidden: 'hidden' });
        var dialog = el('div', {
            className: 'kanban-modal',
            role: 'dialog',
            'aria-modal': 'true',
            'aria-labelledby': 'kanban-modal-title'
        });
        var heading = el('h3', {
            id: 'kanban-modal-title',
            className: 'kanban-modal-title',
            text: 'Add card'
        });
        var closeX = el('button', {
            type: 'button',
            className: 'kanban-modal-close',
            'aria-label': 'Close'
        });
        closeX.textContent = '\u00d7';

        var form = el('form', { className: 'kanban-add-form kanban-modal-form' });
        var columnSelect = el('select', { className: 'kanban-add-input' });
        var columnLabel = el('label', { className: 'kanban-add-label' }, [
            document.createTextNode('Column'),
            columnSelect
        ]);
        var titleInput = el('input', {
            type: 'text',
            placeholder: 'Title',
            required: 'required',
            className: 'kanban-add-input'
        });
        var descriptionInput = el('textarea', {
            placeholder: 'Description (what is this todo about?)',
            rows: '3',
            className: 'kanban-add-input kanban-add-description'
        });
        var categoryInput = el('input', {
            type: 'text',
            placeholder: 'Category (e.g. Mods / Skyrim / Idrinth Thalui)',
            className: 'kanban-add-input',
            list: CATEGORY_DATALIST_ID
        });
        var milestoneInput = el('input', {
            type: 'text',
            placeholder: 'Milestone (optional)',
            className: 'kanban-add-input'
        });
        var submitBtn = el('button', {
            type: 'submit',
            className: 'kanban-add-submit',
            text: 'Add'
        });
        var cancelBtn = el('button', {
            type: 'button',
            className: 'kanban-add-cancel',
            text: 'Cancel'
        });
        var errorNode = el('p', { className: 'kanban-add-error', hidden: 'hidden' });
        var actions = el('div', { className: 'kanban-add-actions' }, [submitBtn, cancelBtn]);

        form.appendChild(columnLabel);
        form.appendChild(titleInput);
        form.appendChild(descriptionInput);
        form.appendChild(categoryInput);
        form.appendChild(milestoneInput);
        form.appendChild(errorNode);
        form.appendChild(actions);

        var header = el('div', { className: 'kanban-modal-header' }, [heading, closeX]);
        dialog.appendChild(header);
        dialog.appendChild(form);
        overlay.appendChild(dialog);

        function open(columnId) {
            form.reset();
            if (columnsMap[columnId]) {
                columnSelect.value = columnId;
            }
            errorNode.hidden = true;
            errorNode.textContent = '';
            submitBtn.disabled = false;
            overlay.hidden = false;
            document.body.classList.add('kanban-modal-open');
            escHandler = function (e) {
                if (e.key === 'Escape') {
                    close();
                }
            };
            document.addEventListener('keydown', escHandler);
            titleInput.focus();
        }
        function close() {
            overlay.hidden = true;
            document.body.classList.remove('kanban-modal-open');
            if (escHandler) {
                document.removeEventListener('keydown', escHandler);
                escHandler = null;
            }
        }

        cancelBtn.addEventListener('click', close);
        closeX.addEventListener('click', close);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                close();
            }
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var title = titleInput.value.trim();
            if (!title) {
                return;
            }
            var columnId = columnSelect.value;
            var column = columnsMap[columnId];
            if (!column) {
                return;
            }
            var payload = {
                column: columnId,
                title: title,
                description: descriptionInput.value.trim(),
                category: categoryInput.value.trim() || 'Uncategorised',
                milestone: milestoneInput.value.trim() || null
            };
            errorNode.hidden = true;
            errorNode.textContent = '';
            submitBtn.disabled = true;
            apiRequest('POST', 'kanban-add', payload)
                .then(function (data) {
                    var created = (data && data.card) || {};
                    var cardData = {
                        id: created.id != null ? created.id : null,
                        title: created.title || payload.title,
                        description: typeof created.description === 'string' ? created.description : payload.description,
                        category: created.category || payload.category,
                        categoryPath: Array.isArray(created.categoryPath) ? created.categoryPath : null,
                        milestone: created.milestone != null ? created.milestone : payload.milestone,
                        workStarted: created.workStarted != null ? created.workStarted : null,
                        workCompleted: created.workCompleted != null ? created.workCompleted : null,
                        timeSpent: created.timeSpent != null ? created.timeSpent : 0.0
                    };
                    var card = buildCardEl(cardData, true);
                    column.cardListEl.appendChild(card);
                    refreshKanbanColumnCount(column.cardListEl);
                    addCategoryPathToDatalist(cardCategoryPath(cardData));
                    close();
                })
                .catch(function (err) {
                    submitBtn.disabled = false;
                    errorNode.textContent = err.message || 'Failed to add card';
                    errorNode.hidden = false;
                });
        });

        return {
            root: overlay,
            registerColumn: function (columnId, columnTitle, cardListEl) {
                columnsMap[columnId] = { cardListEl: cardListEl, title: columnTitle };
                columnSelect.appendChild(el('option', {
                    value: columnId,
                    text: columnTitle
                }));
            },
            open: open
        };
    }

    function buildAddCardButton(modal, columnId) {
        var button = el('button', {
            type: 'button',
            className: 'kanban-add-button',
            text: '+ Add card'
        });
        button.addEventListener('click', function () {
            modal.open(columnId);
        });
        return el('div', { className: 'kanban-add' }, [button]);
    }

    // ---------------------------------------------------------------
    // Profile page: show the current user's profile and let them edit
    // the display name / website URL / about blurb, plus upload or
    // clear an avatar image. Signed-out visitors get a short nudge
    // toward the sign-in page instead; the profile API requires auth.
    // ---------------------------------------------------------------
    var PROFILE_AVATAR_MAX_BYTES = 256 * 1024;
    var PROFILE_AVATAR_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    function renderProfilePage(container, data, options) {
        if (!options || !options.user) {
            container.appendChild(el('p', { className: 'muted' }, [
                'You need to ',
                el('a', { href: 'login.html', text: 'sign in' }),
                ' to edit your profile.'
            ]));
            return;
        }

        var profile = (data && data.profile) || {};

        var heading = el('h2', { className: 'profile-title', text: 'Your profile' });
        // "Signed in as" is the only place where we still surface the
        // raw login username; showing the display name here would be
        // tautological because the surrounding page is the display
        // name editor. Falling back to the login name keeps the line
        // meaningful when the account hasn't picked a display name yet.
        var subtitleLabel = userLabel({
            displayName: profile.displayName,
            username: profile.username || options.user.name,
            fallback: ''
        });
        var subtitle = el('p', {
            className: 'muted profile-subtitle',
            text: 'Signed in as ' + subtitleLabel
        });
        container.appendChild(heading);
        container.appendChild(subtitle);

        var status = el('p', { className: 'profile-status', hidden: 'hidden' });

        // Avatar section: preview on top, file picker + clear button
        // below. The preview is updated on a successful upload so the
        // user sees the round trip without a full page refresh.
        var avatarImg = el('img', { className: 'profile-avatar', alt: '' });
        var avatarPlaceholder = el('div', { className: 'profile-avatar profile-avatar-placeholder' });
        var avatarSlot = el('div', { className: 'profile-avatar-slot' });

        function showAvatar(dataUrl) {
            clear(avatarSlot);
            if (dataUrl) {
                avatarImg.src = dataUrl;
                avatarImg.alt = (profile.displayName || profile.username || '') + ' avatar';
                avatarSlot.appendChild(avatarImg);
            } else {
                avatarPlaceholder.textContent = (profile.displayName || profile.username || '?')
                    .charAt(0).toUpperCase();
                avatarSlot.appendChild(avatarPlaceholder);
            }
        }
        showAvatar(profile.avatar);

        var fileInput = el('input', {
            type: 'file',
            accept: PROFILE_AVATAR_MIMES.join(','),
            className: 'profile-file'
        });
        var uploadBtn = el('button', {
            type: 'button',
            className: 'profile-button profile-upload',
            text: 'Upload picture'
        });
        var clearAvatarBtn = el('button', {
            type: 'button',
            className: 'profile-button profile-button-secondary',
            text: 'Remove picture'
        });

        uploadBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            if (!file) {
                return;
            }
            if (PROFILE_AVATAR_MIMES.indexOf(file.type) === -1) {
                showProfileStatus(status,
                    'Please pick a PNG, JPEG, GIF or WebP image.', true);
                fileInput.value = '';
                return;
            }
            if (file.size > PROFILE_AVATAR_MAX_BYTES) {
                showProfileStatus(status,
                    'The image must be 256 KB or smaller.', true);
                fileInput.value = '';
                return;
            }
            readFileAsDataUrl(file).then(function (dataUrl) {
                return apiRequest('POST', 'profile-picture', { image: dataUrl });
            }).then(function (result) {
                var fresh = (result && result.profile) || {};
                profile.avatar = fresh.avatar || null;
                showAvatar(profile.avatar);
                showProfileStatus(status, 'Profile picture updated.', false);
            }).catch(function (err) {
                showProfileStatus(status, err.message || 'Upload failed.', true);
            }).then(function () {
                fileInput.value = '';
            });
        });

        clearAvatarBtn.addEventListener('click', function () {
            apiRequest('POST', 'profile-picture', { clear: true })
                .then(function (result) {
                    var fresh = (result && result.profile) || {};
                    profile.avatar = fresh.avatar || null;
                    showAvatar(profile.avatar);
                    showProfileStatus(status, 'Profile picture removed.', false);
                })
                .catch(function (err) {
                    showProfileStatus(status, err.message || 'Remove failed.', true);
                });
        });

        var avatarSection = el('div', { className: 'profile-avatar-section' }, [
            avatarSlot,
            el('div', { className: 'profile-avatar-actions' }, [
                uploadBtn,
                clearAvatarBtn,
                el('p', {
                    className: 'muted profile-avatar-hint',
                    text: 'PNG, JPEG, GIF or WebP. Up to 256 KB.'
                })
            ])
        ]);

        // Text fields: display name, website, about. Plain <form>
        // submission so the browser handles keyboard accessibility
        // (Enter to submit, focus ring, labels-for) for free.
        var displayNameInput = el('input', {
            type: 'text',
            name: 'displayName',
            maxlength: '80',
            className: 'profile-input',
            value: profile.displayName || ''
        });
        var websiteUrlInput = el('input', {
            type: 'url',
            name: 'websiteUrl',
            maxlength: '255',
            placeholder: 'https://example.com',
            className: 'profile-input',
            value: profile.websiteUrl || ''
        });
        var aboutInput = el('textarea', {
            name: 'about',
            maxlength: '500',
            rows: '4',
            className: 'profile-input profile-textarea'
        });
        aboutInput.value = profile.about || '';

        var form = el('form', { className: 'profile-form' }, [
            el('label', { className: 'profile-field' }, [
                el('span', { text: 'Display name' }),
                displayNameInput
            ]),
            el('label', { className: 'profile-field' }, [
                el('span', { text: 'Website (optional)' }),
                websiteUrlInput
            ]),
            el('label', { className: 'profile-field' }, [
                el('span', { text: 'About' }),
                aboutInput
            ]),
            el('div', { className: 'profile-actions' }, [
                el('button', {
                    type: 'submit',
                    className: 'profile-button profile-save',
                    text: 'Save profile'
                })
            ])
        ]);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var payload = {
                displayName: displayNameInput.value,
                websiteUrl: websiteUrlInput.value,
                about: aboutInput.value
            };
            apiRequest('POST', 'profile', payload)
                .then(function (result) {
                    var fresh = (result && result.profile) || {};
                    profile.displayName = fresh.displayName || '';
                    profile.websiteUrl = fresh.websiteUrl || '';
                    profile.about = fresh.about || '';
                    displayNameInput.value = profile.displayName;
                    websiteUrlInput.value = profile.websiteUrl;
                    aboutInput.value = profile.about;
                    showProfileStatus(status, 'Profile saved.', false);
                    // Sync the nav so the header reflects the new name
                    // without waiting for the next page load.
                    renderUserMenu({
                        name: profile.username || options.user.name,
                        displayName: profile.displayName
                    });
                })
                .catch(function (err) {
                    showProfileStatus(status, err.message || 'Save failed.', true);
                });
        });

        container.appendChild(avatarSection);
        container.appendChild(form);
        container.appendChild(status);
        container.appendChild(fileInput);

        // Second form on the page: change password. Kept separate from
        // the profile form above so a save of the display name / avatar
        // never accidentally re-submits password fields. Requires the
        // current password to guard against a hijacked session.
        var passwordHeading = el('h3', {
            className: 'profile-title profile-section-title',
            text: 'Change password'
        });
        var passwordStatus = el('p', { className: 'profile-status', hidden: 'hidden' });

        var currentPasswordInput = el('input', {
            type: 'password',
            name: 'currentPassword',
            autocomplete: 'current-password',
            className: 'profile-input'
        });
        var newPasswordInput = el('input', {
            type: 'password',
            name: 'newPassword',
            autocomplete: 'new-password',
            className: 'profile-input'
        });
        var confirmPasswordInput = el('input', {
            type: 'password',
            name: 'confirmPassword',
            autocomplete: 'new-password',
            className: 'profile-input'
        });

        var passwordForm = el('form', { className: 'profile-form' }, [
            el('label', { className: 'profile-field' }, [
                el('span', { text: 'Current password' }),
                currentPasswordInput
            ]),
            el('label', { className: 'profile-field' }, [
                el('span', { text: 'New password' }),
                newPasswordInput
            ]),
            el('label', { className: 'profile-field' }, [
                el('span', { text: 'Confirm new password' }),
                confirmPasswordInput
            ]),
            el('div', { className: 'profile-actions' }, [
                el('button', {
                    type: 'submit',
                    className: 'profile-button profile-save',
                    text: 'Update password'
                })
            ])
        ]);

        passwordForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var current = currentPasswordInput.value;
            var next = newPasswordInput.value;
            var confirm = confirmPasswordInput.value;
            if (current === '' || next === '') {
                showProfileStatus(passwordStatus,
                    'Please fill in both the current and new password.', true);
                return;
            }
            if (next !== confirm) {
                showProfileStatus(passwordStatus,
                    'The new passwords do not match.', true);
                return;
            }
            apiRequest('POST', 'password', {
                currentPassword: current,
                newPassword: next
            })
                .then(function () {
                    currentPasswordInput.value = '';
                    newPasswordInput.value = '';
                    confirmPasswordInput.value = '';
                    showProfileStatus(passwordStatus, 'Password updated.', false);
                })
                .catch(function (err) {
                    showProfileStatus(passwordStatus,
                        err.message || 'Password update failed.', true);
                });
        });

        container.appendChild(passwordHeading);
        container.appendChild(passwordForm);
        container.appendChild(passwordStatus);
    }

    function showProfileStatus(node, message, isError) {
        if (!node) {
            return;
        }
        node.textContent = message;
        node.hidden = false;
        if (isError) {
            node.classList.add('profile-status-error');
        } else {
            node.classList.remove('profile-status-error');
        }
    }

    function readFileAsDataUrl(file) {
        return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onload = function () { resolve(String(reader.result || '')); };
            reader.onerror = function () { reject(new Error('Could not read the file.')); };
            reader.readAsDataURL(file);
        });
    }

    // Deterministic palette used for the time tracking pie charts. Two
    // sibling charts on the same page look related when they reuse the
    // same colour set, and keying off the slice index (rather than the
    // label) keeps things predictable even when categories and
    // contributors overlap.
    var PIE_COLORS = [
        '#1f6feb', '#2da44e', '#d29922', '#cf222e', '#8250df',
        '#bf3989', '#0969da', '#9a6700', '#1a7f37', '#6e7781'
    ];

    // Build the two pie charts (hours by category, hours by
    // contributor) that sit above the per-week tables on the time
    // tracking page. Returns null when there is nothing to plot so the
    // view just skips the block for empty data sets.
    function buildTimeCharts(categoryTotals, contributorTotals) {
        var hasCategories = categoryTotals && categoryTotals.length > 0;
        var hasContributors = contributorTotals && contributorTotals.length > 0;
        if (!hasCategories && !hasContributors) {
            return null;
        }
        var wrap = el('div', { className: 'time-charts' });
        if (hasCategories) {
            wrap.appendChild(buildPieChart('Hours by category', categoryTotals));
        }
        if (hasContributors) {
            wrap.appendChild(buildPieChart('Hours by contributor', contributorTotals));
        }
        return wrap;
    }

    // Render a single labelled pie chart. `slices` is the
    // already-sorted list of `{label, hours}` objects coming from the
    // API. The SVG is drawn at a 100x100 viewBox so the outer CSS can
    // resize it freely; slices are arcs via the standard "two points
    // on a unit circle" approach, with a full-circle special case so a
    // single-slice dataset still renders.
    function buildPieChart(title, slices) {
        var total = 0;
        slices.forEach(function (slice) { total += Number(slice.hours) || 0; });
        var section = el('section', { className: 'time-chart' }, [
            el('h3', { className: 'time-chart-title', text: title })
        ]);

        if (total <= 0) {
            section.appendChild(el('p', {
                className: 'time-chart-empty muted',
                text: 'No hours logged yet.'
            }));
            return section;
        }

        var svgNs = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(svgNs, 'svg');
        svg.setAttribute('class', 'time-chart-svg');
        svg.setAttribute('viewBox', '0 0 100 100');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', title);

        var cx = 50;
        var cy = 50;
        var r = 48;
        var startAngle = -Math.PI / 2; // Start at 12 o'clock.
        slices.forEach(function (slice, i) {
            var hours = Number(slice.hours) || 0;
            if (hours <= 0) {
                return;
            }
            var fraction = hours / total;
            var color = PIE_COLORS[i % PIE_COLORS.length];
            var shape;
            if (fraction >= 0.999999) {
                // A single slice covers the full circle; arcs with the
                // same start and end point collapse to nothing, so fall
                // back to a plain circle for this case.
                shape = document.createElementNS(svgNs, 'circle');
                shape.setAttribute('cx', String(cx));
                shape.setAttribute('cy', String(cy));
                shape.setAttribute('r', String(r));
            } else {
                var endAngle = startAngle + fraction * 2 * Math.PI;
                var x1 = cx + r * Math.cos(startAngle);
                var y1 = cy + r * Math.sin(startAngle);
                var x2 = cx + r * Math.cos(endAngle);
                var y2 = cy + r * Math.sin(endAngle);
                var largeArc = fraction > 0.5 ? 1 : 0;
                var d = 'M ' + cx + ' ' + cy +
                        ' L ' + x1.toFixed(3) + ' ' + y1.toFixed(3) +
                        ' A ' + r + ' ' + r + ' 0 ' + largeArc + ' 1 ' +
                        x2.toFixed(3) + ' ' + y2.toFixed(3) + ' Z';
                shape = document.createElementNS(svgNs, 'path');
                shape.setAttribute('d', d);
                startAngle = endAngle;
            }
            shape.setAttribute('fill', color);
            var titleEl = document.createElementNS(svgNs, 'title');
            titleEl.textContent = slice.label + ': ' + formatHours(hours) + 'h (' +
                Math.round(fraction * 100) + '%)';
            shape.appendChild(titleEl);
            svg.appendChild(shape);
        });

        var legend = el('ul', { className: 'time-chart-legend' });
        slices.forEach(function (slice, i) {
            var hours = Number(slice.hours) || 0;
            if (hours <= 0) {
                return;
            }
            var percent = Math.round((hours / total) * 100);
            var swatch = el('span', { className: 'time-chart-swatch' });
            swatch.style.background = PIE_COLORS[i % PIE_COLORS.length];
            legend.appendChild(el('li', { className: 'time-chart-legend-item' }, [
                swatch,
                el('span', { className: 'time-chart-legend-label', text: slice.label }),
                el('span', {
                    className: 'time-chart-legend-value',
                    text: formatHours(hours) + 'h (' + percent + '%)'
                })
            ]));
        });

        section.appendChild(el('div', { className: 'time-chart-body' }, [svg, legend]));
        return section;
    }

    var renderers = {
        kanban: function (container, data, options) {
            var canEdit = !!(options && options.user);
            var columns = (data && data.columns) || [];
            var modal = canEdit ? buildAddCardModal() : null;
            columns.forEach(function (column) {
                var classes = 'kanban-column';
                if (column.discarded) {
                    classes += ' kanban-column-discarded';
                }
                if (column.id === 'waiting') {
                    classes += ' kanban-column-waiting';
                }
                var section = el('section', { className: classes });
                section.appendChild(el('h3', { className: 'kanban-title' }, [
                    document.createTextNode(column.title + ' '),
                    el('span', { className: 'kanban-count', 'data-kanban-count': '' })
                ]));

                var cardList = el('div', { className: 'kanban-cards' });
                cardList.setAttribute('data-column-id', column.id);
                (column.cards || []).forEach(function (card) {
                    cardList.appendChild(buildCardEl(card, canEdit));
                });
                if (canEdit) {
                    makeDropTarget(cardList);
                }
                section.appendChild(cardList);
                refreshKanbanColumnCount(cardList);
                if (canEdit && modal) {
                    modal.registerColumn(column.id, column.title, cardList);
                    section.appendChild(buildAddCardButton(modal, column.id));
                }

                container.appendChild(section);
            });
            if (modal) {
                container.appendChild(modal.root);
            }
        },

        releases: function (container, data) {
            var projects = (data && data.projects) || [];

            // Bucket projects by their top-level category. Projects
            // sitting at the root (path length 1) become a group of
            // their own so they still get a consistent heading.
            var groupOrder = [];
            var groups = {};
            projects.forEach(function (project) {
                var path = Array.isArray(project.path) ? project.path : [project.name];
                var groupName = project.group || path[0] || project.name;
                if (!groups[groupName]) {
                    groups[groupName] = {
                        name: groupName,
                        // When the group name matches the only project
                        // in it, render a flat single-project card;
                        // otherwise render a labelled group section.
                        grouped: path.length > 1,
                        projects: []
                    };
                    groupOrder.push(groupName);
                } else if (path.length > 1) {
                    groups[groupName].grouped = true;
                }
                groups[groupName].projects.push(project);
            });

            groupOrder.forEach(function (groupName) {
                var group = groups[groupName];
                if (group.grouped) {
                    var section = el('section', { className: 'release-group' });
                    section.appendChild(el('h3', {
                        className: 'release-group-title',
                        text: groupName
                    }));
                    var grid = el('div', { className: 'release-group-grid' });
                    group.projects.forEach(function (project) {
                        grid.appendChild(buildReleaseProjectEl(project, true));
                    });
                    section.appendChild(grid);
                    container.appendChild(section);
                } else {
                    group.projects.forEach(function (project) {
                        container.appendChild(buildReleaseProjectEl(project, false));
                    });
                }
            });
        },

        profile: function (container, data, options) {
            renderProfilePage(container, data, options);
        },

        time: function (container, data) {
            var categories = (data && data.categories) || [];
            var weeks = (data && data.weeks) || [];
            var categoryTotals = (data && data.categoryTotals) || [];
            var contributorTotals = (data && data.contributorTotals) || [];

            var charts = buildTimeCharts(categoryTotals, contributorTotals);
            if (charts) {
                container.appendChild(charts);
            }

            weeks.forEach(function (week) {
                var section = el('section', { className: 'time-week' });
                section.appendChild(el('h3', {
                    className: 'time-week-title',
                    text: 'Week of ' + week.start + ' \u2013 ' + week.end
                }));

                var headRow = el('tr', null, [el('th', { scope: 'col', text: 'Issue' })]);
                categories.forEach(function (category) {
                    headRow.appendChild(el('th', { scope: 'col', text: category }));
                });
                headRow.appendChild(el('th', { scope: 'col', text: 'Total' }));

                var body = el('tbody');
                var columnTotals = categories.map(function () { return 0; });
                var grandTotal = 0;

                (week.issues || []).forEach(function (issue) {
                    var labelCell = el('th', { scope: 'row' });
                    if (issue.id != null) {
                        labelCell.appendChild(el('a', {
                            className: 'time-issue-link',
                            href: 'kanban.html#issue-' + issue.id,
                            text: issue.label
                        }));
                    } else {
                        labelCell.textContent = issue.label;
                    }
                    var row = el('tr', null, [labelCell]);
                    var rowTotal = 0;
                    var hours = issue.hours || [];
                    for (var i = 0; i < categories.length; i++) {
                        var value = Number(hours[i] || 0);
                        columnTotals[i] += value;
                        rowTotal += value;
                        row.appendChild(el('td', { text: formatHours(value) }));
                    }
                    row.appendChild(el('td', { className: 'total', text: formatHours(rowTotal) }));
                    grandTotal += rowTotal;
                    body.appendChild(row);
                });

                var footRow = el('tr', null, [el('th', { scope: 'row', text: 'Total' })]);
                columnTotals.forEach(function (value) {
                    footRow.appendChild(el('td', { text: formatHours(value) }));
                });
                footRow.appendChild(el('td', { className: 'total', text: formatHours(grandTotal) }));

                var table = el('table', { className: 'time-table' }, [
                    el('thead', null, [headRow]),
                    body,
                    el('tfoot', null, [footRow])
                ]);

                section.appendChild(el('div', { className: 'table-wrap' }, [table]));
                container.appendChild(section);
            });
        }
    };
})();
