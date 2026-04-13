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

        // Fetch the current user once and share the result between the
        // nav user-menu and the view renderer. The promise never
        // rejects - it resolves to null when the caller isn't signed in
        // so callers can treat the value as a simple boolean.
        var userPromise = apiRequest('GET', 'me')
            .then(function (data) {
                return (data && data.user && data.user.name) || null;
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

    // Populate the nav user-menu slot based on the resolved auth state.
    function renderUserMenu(name) {
        var slot = document.querySelector('[data-user-menu]');
        if (!slot) {
            return;
        }
        if (name) {
            renderSignedIn(slot, name);
        } else {
            renderSignedOut(slot);
        }
    }

    function renderSignedIn(slot, name) {
        clear(slot);
        slot.appendChild(el('span', { className: 'user-menu-name', text: name }));
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
    function apiRequest(method, endpoint, body) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        };
        if (body !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        return fetch(API_BASE + encodeURIComponent(endpoint), options)
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
        return Number(value).toFixed(1);
    }

    function dateOrDash(value) {
        if (!value) {
            return document.createTextNode('\u2014');
        }
        return el('time', { datetime: value, text: value });
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

        var list = el('ol', { className: 'release-list' });
        releases.forEach(function (release) {
            list.appendChild(el('li', { className: 'release' }, [
                el('span', { className: 'release-version', text: release.version }),
                el('time', {
                    className: 'release-date',
                    datetime: release.date,
                    text: release.date
                }),
                el('p', { className: 'release-notes', text: release.notes })
            ]));
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

    function buildCardEl(card, draggable) {
        var path = Array.isArray(card.categoryPath) ? card.categoryPath : null;
        var categoryNode;
        if (path && path.length > 1) {
            // Render a breadcrumb where each segment except the last
            // is de-emphasised, so the leaf project (the thing the
            // card actually belongs to) stands out at a glance.
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

        var cardEl = el('article', { className: 'kanban-card' }, [
            el('h4', { text: card.title }),
            descriptionNode,
            categoryNode,
            el('p', { className: 'kanban-meta', text: 'Milestone: ' + (card.milestone || '\u2014') }),
            el('p', { className: 'kanban-meta' }, ['Work started: ', dateOrDash(card.workStarted)]),
            el('p', { className: 'kanban-meta' }, ['Work completed: ', dateOrDash(card.workCompleted)]),
            el('p', {
                className: 'kanban-meta kanban-time',
                text: 'Time spent: ' + formatHours(card.timeSpent) + 'h'
            })
        ]);
        if (card.id != null) {
            cardEl.setAttribute('data-card-id', String(card.id));
        }
        if (draggable) {
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
            openDetailView(card.id, cardEl);
        });
        return cardEl;
    }

    // Task detail modal. Shares one overlay (defined in kanban.html)
    // across every card; each open() replaces its contents.
    function openDetailView(issueId, cardEl) {
        var overlay = document.querySelector('[data-detail-overlay]');
        var body = document.querySelector('[data-detail-body]');
        if (!overlay || !body) {
            return;
        }
        var closeBtn = document.querySelector('[data-detail-close]');
        overlay.hidden = false;
        clear(body);
        body.appendChild(el('p', { className: 'muted', text: 'Loading\u2026' }));

        function close() {
            overlay.hidden = true;
            clear(body);
            overlay.removeEventListener('click', onOverlayClick);
            document.removeEventListener('keydown', onKey);
            if (closeBtn) {
                closeBtn.removeEventListener('click', close);
            }
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
                renderDetailView(body, data, cardEl, close);
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
    function renderDetailView(container, data, cardEl, close) {
        var issue = data && data.issue ? data.issue : {};
        var timeEntries = (data && data.timeEntries) || [];
        var comments = (data && data.comments) || [];

        container.appendChild(el('h3', { id: 'detail-title', text: issue.title || 'Task' }));

        var editSection = buildEditSection(issue, cardEl);
        var timeSection = buildTimeSection(issue, timeEntries, cardEl);
        var commentSection = buildCommentSection(issue, comments);

        container.appendChild(editSection);
        container.appendChild(timeSection);
        container.appendChild(commentSection);
    }

    function buildEditSection(issue, cardEl) {
        var section = el('section', { className: 'detail-section' });
        section.appendChild(el('h4', { text: 'Edit' }));

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

        var submitBtn = el('button', {
            type: 'submit',
            className: 'detail-button',
            text: 'Save'
        });
        var statusNode = el('p', { className: 'detail-status' });
        var errorNode = el('p', { className: 'detail-error', hidden: 'hidden' });

        form.appendChild(field('Title', titleInput, true));
        form.appendChild(field('Status', statusSelect, false));
        form.appendChild(field('Category', categoryInput, true));
        form.appendChild(field('Milestone', milestoneInput, true));
        form.appendChild(field('Work started', startedInput, false));
        form.appendChild(field('Work completed', completedInput, false));
        form.appendChild(field('Description', descriptionInput, true));
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

    function buildTimeSection(issue, entries, cardEl) {
        var section = el('section', { className: 'detail-section' });
        section.appendChild(el('h4', { text: 'Time spent' }));

        var listWrap = el('div');
        renderTimeEntries(listWrap, entries);
        section.appendChild(listWrap);

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
                        note: entry.note || ''
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
            var children = [el('p', { className: 'detail-entry-meta', text: meta })];
            if (entry.note) {
                children.push(el('p', { className: 'detail-entry-note', text: entry.note }));
            }
            list.appendChild(el('li', { className: 'detail-entry' }, children));
        });
        wrap.appendChild(list);
    }

    function buildCommentSection(issue, comments) {
        var section = el('section', { className: 'detail-section' });
        section.appendChild(el('h4', { text: 'Comments' }));

        var listWrap = el('div');
        renderComments(listWrap, comments);
        section.appendChild(listWrap);

        var form = el('form', { className: 'detail-form' });
        var bodyInput = el('textarea', {
            rows: '3',
            required: 'required',
            placeholder: 'Leave a comment\u2026'
        });
        var submitBtn = el('button', {
            type: 'submit',
            className: 'detail-button',
            text: 'Post comment'
        });
        var statusNode = el('p', { className: 'detail-status' });
        var errorNode = el('p', { className: 'detail-error', hidden: 'hidden' });

        form.appendChild(field('Comment', bodyInput, true));
        form.appendChild(el('div', { className: 'detail-actions' }, [submitBtn, statusNode]));
        form.appendChild(errorNode);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            errorNode.hidden = true;
            errorNode.textContent = '';
            var text = bodyInput.value.trim();
            if (!text) {
                return;
            }
            submitBtn.disabled = true;
            statusNode.textContent = 'Posting\u2026';
            apiRequest('POST', 'issue-comment-add', { id: issue.id, body: text })
                .then(function (result) {
                    submitBtn.disabled = false;
                    statusNode.textContent = 'Posted.';
                    var c = (result && result.comment) || {
                        author: '',
                        body: text,
                        createdAt: new Date().toISOString()
                    };
                    comments.push(c);
                    renderComments(listWrap, comments);
                    bodyInput.value = '';
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
            list.appendChild(el('li', { className: 'detail-comment' }, [
                el('p', {
                    className: 'detail-comment-meta',
                    text: (c.author || 'unknown') + ' \u00b7 ' + (c.createdAt || '')
                }),
                el('p', { className: 'detail-comment-body', text: c.body || '' })
            ]));
        });
        wrap.appendChild(list);
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
        var targetParent = parent;
        if (issue.status && issue.status !== currentStatus) {
            var candidate = document.querySelector('[data-column-id="' + issue.status + '"]');
            if (candidate) {
                targetParent = candidate;
            }
        }
        var draggable = cardEl.getAttribute('draggable') === 'true';
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
        var replacement = buildCardEl(card, draggable);
        targetParent.appendChild(replacement);
        if (cardEl !== replacement) {
            cardEl.parentNode.removeChild(cardEl);
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
        }).catch(function (err) {
            if (window.console) {
                window.console.warn('kanban-move failed: ' + err.message);
            }
        });
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

    function buildAddCardUi(cardListEl, columnId) {
        var wrap = el('div', { className: 'kanban-add' });
        var button = el('button', {
            type: 'button',
            className: 'kanban-add-button',
            text: '+ Add card'
        });
        var form = el('form', { className: 'kanban-add-form', hidden: 'hidden' });
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
            className: 'kanban-add-input'
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
        form.appendChild(titleInput);
        form.appendChild(descriptionInput);
        form.appendChild(categoryInput);
        form.appendChild(milestoneInput);
        form.appendChild(errorNode);
        form.appendChild(actions);

        function openForm() {
            button.hidden = true;
            form.hidden = false;
            titleInput.focus();
        }
        function closeForm() {
            form.reset();
            form.hidden = true;
            button.hidden = false;
            errorNode.hidden = true;
            errorNode.textContent = '';
            submitBtn.disabled = false;
        }

        button.addEventListener('click', openForm);
        cancelBtn.addEventListener('click', closeForm);
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var title = titleInput.value.trim();
            if (!title) {
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
                    var card = buildCardEl({
                        id: created.id != null ? created.id : null,
                        title: created.title || payload.title,
                        description: typeof created.description === 'string' ? created.description : payload.description,
                        category: created.category || payload.category,
                        categoryPath: Array.isArray(created.categoryPath) ? created.categoryPath : null,
                        milestone: created.milestone != null ? created.milestone : payload.milestone,
                        workStarted: created.workStarted != null ? created.workStarted : null,
                        workCompleted: created.workCompleted != null ? created.workCompleted : null,
                        timeSpent: created.timeSpent != null ? created.timeSpent : 0.0
                    }, true);
                    cardListEl.appendChild(card);
                    closeForm();
                })
                .catch(function (err) {
                    submitBtn.disabled = false;
                    errorNode.textContent = err.message || 'Failed to add card';
                    errorNode.hidden = false;
                });
        });

        wrap.appendChild(button);
        wrap.appendChild(form);
        return wrap;
    }

    var renderers = {
        kanban: function (container, data, options) {
            var canEdit = !!(options && options.user);
            var columns = (data && data.columns) || [];
            columns.forEach(function (column) {
                var section = el('section', {
                    className: 'kanban-column' + (column.discarded ? ' kanban-column-discarded' : '')
                });
                section.appendChild(el('h3', { className: 'kanban-title', text: column.title }));

                var cardList = el('div', { className: 'kanban-cards' });
                cardList.setAttribute('data-column-id', column.id);
                (column.cards || []).forEach(function (card) {
                    cardList.appendChild(buildCardEl(card, canEdit));
                });
                if (canEdit) {
                    makeDropTarget(cardList);
                }
                section.appendChild(cardList);
                if (canEdit) {
                    section.appendChild(buildAddCardUi(cardList, column.id));
                }

                container.appendChild(section);
            });
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

        time: function (container, data) {
            var categories = (data && data.categories) || [];
            var weeks = (data && data.weeks) || [];
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
                    var row = el('tr', null, [el('th', { scope: 'row', text: issue.label })]);
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
