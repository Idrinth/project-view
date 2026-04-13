// Project View frontend entry point.
// Looks for a [data-view] container on the page, fetches the matching
// payload from the PHP API and renders it into the DOM.
(function () {
    'use strict';

    var API_URL = 'index.php';

    document.addEventListener('DOMContentLoaded', function () {
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

    function fetchEndpoint(name) {
        return fetch(API_URL + '?endpoint=' + encodeURIComponent(name), {
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
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

    function buildCardEl(card) {
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
        makeDraggable(cardEl);
        return cardEl;
    }

    function makeDraggable(cardEl) {
        cardEl.setAttribute('draggable', 'true');
        cardEl.addEventListener('dragstart', function (e) {
            draggedCard = cardEl;
            cardEl.classList.add('kanban-card-dragging');
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', ''); } catch (err) { /* ignore */ }
            }
        });
        cardEl.addEventListener('dragend', function () {
            cardEl.classList.remove('kanban-card-dragging');
            draggedCard = null;
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

    function buildAddCardUi(cardListEl) {
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
        var actions = el('div', { className: 'kanban-add-actions' }, [submitBtn, cancelBtn]);
        form.appendChild(titleInput);
        form.appendChild(categoryInput);
        form.appendChild(milestoneInput);
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
        }

        button.addEventListener('click', openForm);
        cancelBtn.addEventListener('click', closeForm);
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var title = titleInput.value.trim();
            if (!title) {
                return;
            }
            var card = buildCardEl({
                title: title,
                category: categoryInput.value.trim() || 'Uncategorised',
                milestone: milestoneInput.value.trim() || null,
                workStarted: null,
                workCompleted: null,
                timeSpent: 0.0
            });
            cardListEl.appendChild(card);
            closeForm();
        });

        wrap.appendChild(button);
        wrap.appendChild(form);
        return wrap;
    }

    var renderers = {
        kanban: function (container, data) {
            var columns = (data && data.columns) || [];
            columns.forEach(function (column) {
                var section = el('section', {
                    className: 'kanban-column' + (column.discarded ? ' kanban-column-discarded' : '')
                });
                section.appendChild(el('h3', { className: 'kanban-title', text: column.title }));

                var cardList = el('div', { className: 'kanban-cards' });
                (column.cards || []).forEach(function (card) {
                    cardList.appendChild(buildCardEl(card));
                });
                makeDropTarget(cardList);
                section.appendChild(cardList);
                section.appendChild(buildAddCardUi(cardList));

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
