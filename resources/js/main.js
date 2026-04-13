// Project View frontend entry point.
// Looks for a [data-view] container on the page, fetches the matching
// payload from the PHP API and renders it into the DOM.
(function () {
    'use strict';

    var API_URL = 'index.php';

    document.addEventListener('DOMContentLoaded', function () {
        renderUserMenu();
        bindLoginForm();

        var container = document.querySelector('[data-view]');
        if (!container) {
            return;
        }
        var view = container.getAttribute('data-view');
        var renderer = renderers[view];
        if (!renderer) {
            return;
        }
        fetchEndpoint(view)
            .then(function (data) {
                clear(container);
                renderer(container, data);
            })
            .catch(function (err) {
                clear(container);
                var msg = document.createElement('p');
                msg.className = 'muted';
                msg.textContent = 'Failed to load data: ' + err.message;
                container.appendChild(msg);
            });
    });

    // Ask the API who (if anyone) the current request is authenticated
    // as and populate the nav user-menu slot accordingly.
    function renderUserMenu() {
        var slot = document.querySelector('[data-user-menu]');
        if (!slot) {
            return;
        }
        apiRequest('GET', 'me')
            .then(function (data) {
                var name = (data && data.user && data.user.name) || '';
                renderSignedIn(slot, name);
            })
            .catch(function () {
                renderSignedOut(slot);
            });
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

    var renderers = {
        kanban: function (container, data) {
            var columns = (data && data.columns) || [];
            columns.forEach(function (column) {
                var section = el('section', {
                    className: 'kanban-column' + (column.discarded ? ' kanban-column-discarded' : '')
                });
                section.appendChild(el('h3', { className: 'kanban-title', text: column.title }));
                (column.cards || []).forEach(function (card) {
                    section.appendChild(el('article', { className: 'kanban-card' }, [
                        el('h4', { text: card.title }),
                        el('p', { className: 'kanban-meta', text: 'Category: ' + card.category }),
                        el('p', { className: 'kanban-meta', text: 'Milestone: ' + (card.milestone || '\u2014') }),
                        el('p', { className: 'kanban-meta' }, ['Work started: ', dateOrDash(card.workStarted)]),
                        el('p', { className: 'kanban-meta' }, ['Work completed: ', dateOrDash(card.workCompleted)]),
                        el('p', {
                            className: 'kanban-meta kanban-time',
                            text: 'Time spent: ' + formatHours(card.timeSpent) + 'h'
                        })
                    ]));
                });
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
