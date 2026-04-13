// Project View frontend entry point.
// Looks for a [data-view] container on the page, fetches the matching
// payload from the PHP API and renders it into the DOM.
(function () {
    'use strict';

    var API_URL = 'index.php';

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
                clear(container);
                renderer(container, results[0], { user: results[1] });
            })
            .catch(function (err) {
                clear(container);
                var msg = document.createElement('p');
                msg.className = 'muted';
                msg.textContent = 'Failed to load data: ' + err.message;
                container.appendChild(msg);
            });
    });

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
        return fetch(API_URL + '?endpoint=' + encodeURIComponent(endpoint), options)
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

    function buildCardEl(card, draggable) {
        var cardEl = el('article', { className: 'kanban-card' }, [
            el('h4', { text: card.title }),
            el('p', { className: 'kanban-meta', text: 'Category: ' + card.category }),
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
        return cardEl;
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
        var categoryInput = el('input', {
            type: 'text',
            placeholder: 'Category',
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
                        category: created.category || payload.category,
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
            projects.forEach(function (project) {
                var releases = project.releases || [];
                var latest = releases[0];
                var headerChildren = [el('h3', { text: project.name })];
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

                container.appendChild(el('article', { className: 'release-project' }, [
                    el('header', { className: 'release-project-header' }, headerChildren),
                    list
                ]));
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
