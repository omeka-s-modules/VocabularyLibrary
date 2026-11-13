(function ($) {

    const TERM_SEARCH_DELAY = 400;
    // Long enough for a term search's results to change the count first.
    const ANNOUNCE_DELAY = 1000;

    /**
     * Shows a tab of the details sidebar and its section, as core's resource
     * form does for its sidebar tabs; core's handler isn't loaded here. Only
     * the tab shown is in the tab order: the arrow keys move between tabs, as
     * a screen reader's user expects of tabs.
     */
    const showTab = function (tab) {
        tab.closest('[role="tablist"]').querySelectorAll('[role="tab"]').forEach(function (other) {
            const selected = other === tab;
            other.classList.toggle('active', selected);
            other.setAttribute('aria-selected', selected ? 'true' : 'false');
            other.tabIndex = selected ? 0 : -1;
            document.getElementById(other.dataset.id).classList.toggle('active', selected);
        });
        // A tab whose content is loaded when wanted.
        const panel = document.getElementById(tab.dataset.id);
        if (panel.dataset.termsUrl) {
            loadTerms(panel);
        }
    };

    /**
     * Loads the details sidebar's class and property lists into their tab,
     * once: they come from the vocabulary's file, which for a large
     * vocabulary takes a moment to read. The tab says it is loading, and if
     * the request fails, says so and offers to try again.
     *
     * @param {Element} panel The "Classes and properties" tab's section
     * @param {function()} [then] Called once the lists are in, now if they
     *   already are
     */
    const loadTerms = function (panel, then) {
        if (panel.dataset.loaded) {
            if (then) {
                then();
            }
            return;
        }
        panel.waiting = panel.waiting || [];
        if (then) {
            panel.waiting.push(then);
        }
        if (panel.getAttribute('aria-busy') === 'true') {
            return;
        }
        const loading = panel.querySelector('.vocabulary-library-terms-loading');
        const failed = panel.querySelector('.vocabulary-library-terms-failed');
        panel.setAttribute('aria-busy', 'true');
        loading.hidden = false;
        failed.hidden = true;
        $.get(panel.dataset.termsUrl)
            .done(function (html) {
                // The sidebar may show another vocabulary by now.
                if (!panel.isConnected) {
                    return;
                }
                panel.removeAttribute('aria-busy');
                panel.dataset.loaded = '1';
                $(panel).html(html);
                initTermFilter(panel);
                panel.waiting.splice(0).forEach(fn => fn());
            })
            .fail(function () {
                if (!panel.isConnected) {
                    return;
                }
                // What was waiting for the lists still waits, for a retry.
                panel.removeAttribute('aria-busy');
                loading.hidden = true;
                failed.hidden = false;
            });
    };

    /**
     * Reads text out through a live region once it has stopped changing, and
     * only if it differs from what the admin last heard, so a screen reader
     * speaks the result of typing and not every step of it.
     *
     * @param {Element} region
     * @return {{say: function(string), heard: function(string)}} say() takes
     *   the text to read out; heard() takes text the admin already has, such
     *   as what the page showed when it loaded, which is then not read out
     */
    const announcer = function (region) {
        let timer = null;
        let last = null;
        return {
            say: function (text) {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    if (text !== last) {
                        last = text;
                        region.textContent = text;
                    }
                }, ANNOUNCE_DELAY);
            },
            heard: function (text) {
                clearTimeout(timer);
                last = text;
            },
        };
    };

    /**
     * Lowercases and strips accents, so "Ontologie" matches "ontologié".
     */
    const fold = function (text) {
        return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    };

    /**
     * A value the object has of its own, so a key such as "constructor"
     * doesn't find what every object inherits.
     */
    const own = function (object, key) {
        return object && Object.prototype.hasOwnProperty.call(object, key) ? object[key] : undefined;
    };

    /**
     * The library page: filters, sorting and term search over the rows the
     * page already has. The markup, including every message, is in
     * browse.phtml; this only hides, reorders and fills in rows.
     */
    const initBrowse = function (filters) {
        const form = filters[0];
        const table = $('#vocabulary-library-table');
        const tbody = table.children('tbody');
        const rows = tbody.children('tr').get();
        const empty = $('.vocabulary-library-empty');
        const searchTermsButton = empty.find('.vocabulary-library-search-terms');
        const clearFiltersButton = filters.find('.vocabulary-library-clear-filters');
        const filterNames = ['tag', 'lang', 'show'];
        const shown = $('.vocabulary-library-shown');
        const countText = () => $('.vocabulary-library-count').text().trim().replace(/\s+/g, ' ');
        // Reads the count out once the admin pauses.
        const count = announcer(document.querySelector('.vocabulary-library-announcement'));
        const termSearchFailed = $('.vocabulary-library-term-search-failed');
        // The link the details sidebar was opened from, and its row, to
        // return focus to. The row is kept too, since a link to all matching
        // terms is replaced whenever the rows are filled in again.
        let sidebarOpener = null;
        let sidebarOpenerRow = null;
        const termItem = document.getElementById('vocabulary-library-term-item').content.firstElementChild;
        const allItem = document.getElementById('vocabulary-library-term-all').content.firstElementChild;
        // A matching term's text, as Omeka writes terms.
        const termText = match => match.label + ' (' + match.term + ')';
        const searchText = new Map(rows.map(row => [row, fold(row.dataset.search)]));
        // Term search needs as many characters as the server asks for.
        const termQueryMin = filters.data('term-query-min');
        const collator = new Intl.Collator(document.documentElement.lang || undefined, {sensitivity: 'base', numeric: true});
        // Per vocabulary, the best few terms matching termQuery, and how many
        // match in all.
        let termMatches = null;
        let termQuery = null;
        let termRequest = null;
        let termTimer = null;
        // Whether the admin is leaving the page, which cancels a term search
        // under way: that is not a search that failed.
        let leaving = false;
        window.addEventListener('beforeunload', function () {
            leaving = true;
        });

        const apply = function () {
            const query = fold(form.q.value.trim());
            // Every word must match a row, in any order.
            const words = query.split(/\s+/).filter(Boolean);
            const tag = form.tag.value;
            const lang = form.lang.value;
            const show = form.show.value;
            let count = 0;
            rows.forEach(function (row) {
                // Matches held from the last term search, narrowed to what is
                // typed now, word by word as the server matches them, so rows
                // never show terms from an earlier query.
                const found = own(termMatches, row.dataset.slug);
                const terms = (found ? found.terms : []).filter(function (match) {
                    const text = fold(termText(match));
                    return words.every(word => text.includes(word));
                });
                // How many match in all, while the terms shown are for exactly
                // this query rather than narrowed from an earlier one. The
                // server compares accents, so this compares the text as typed.
                const all = found && termQuery === form.q.value.trim().toLowerCase() ? found.count : 0;
                let visible = words.every(word => searchText.get(row).includes(word)) || terms.length > 0;
                if (tag !== '' && !row.dataset.tags.split(' ').includes(tag)) {
                    visible = false;
                }
                if (lang !== '' && !row.dataset.languages.split(' ').includes(lang)) {
                    visible = false;
                }
                if ((show === 'installed' && row.dataset.installed !== '1')
                    || (show === 'not-installed' && row.dataset.installed === '1')
                ) {
                    visible = false;
                }
                row.hidden = !visible;
                const matches = row.querySelector('.vocabulary-library-term-matches');
                matches.hidden = !(visible && terms.length);
                const items = terms.map(function (match) {
                    const li = termItem.cloneNode(true);
                    li.textContent = termText(match);
                    return li;
                });
                if (all) {
                    // All of them in the details sidebar, filtered by the
                    // query.
                    const li = allItem.cloneNode(true);
                    const link = li.firstElementChild;
                    const detailsUrl = row.querySelector('.vocabulary-library-title > a.sidebar-content').dataset.sidebarContentUrl;
                    link.textContent = filters.data('term-all').replace('%s', all);
                    link.dataset.sidebarContentUrl = detailsUrl + '?' + new URLSearchParams({filter: form.q.value.trim()});
                    items.push(li);
                }
                matches.querySelector('.vocabulary-library-term-list').replaceChildren(...items);
                count += visible ? 1 : 0;
            });
            shown.text(count);
            table.prop('hidden', count === 0);
            empty.prop('hidden', count > 0);
            // When nothing matches, offer to search terms too, if the query
            // is long enough for a term search. Clear filters shows while any
            // is set.
            searchTermsButton.prop('hidden', form.terms.checked || form.q.value.trim().length < termQueryMin);
            clearFiltersButton.prop('hidden', filterNames.every(name => form[name].value === ''));
            saveState();
            count.say(countText());
        };

        const sort = function () {
            const key = form.sort_by.value;
            const direction = form.sort_order.value;
            const numeric = key === 'classes' || key === 'properties';
            rows.sort(function (a, b) {
                const order = numeric
                    ? Number(a.dataset[key]) - Number(b.dataset[key])
                    : collator.compare(a.dataset[key], b.dataset[key]);
                // Ties fall back to the title.
                return (direction === 'desc' ? -order : order) || collator.compare(a.dataset.title, b.dataset.title);
            });
            tbody.append(rows);
            saveState();
        };

        /**
         * @return {jqXHR|null} The term search request, if one is made
         */
        const searchTerms = function () {
            const query = form.q.value.trim();
            if (termRequest) {
                termRequest.abort();
                termRequest = null;
            }
            termSearchFailed.prop('hidden', true);
            if (!form.terms.checked || query.length < termQueryMin) {
                termMatches = null;
                apply();
                return null;
            }
            termRequest = $.get(filters.data('term-search-url'), {q: query})
                .done(function (data) {
                    termMatches = data.matches;
                    termQuery = query.toLowerCase();
                    apply();
                })
                .fail(function (xhr, status) {
                    // A request that fails leaves no matches, rather than
                    // those of an earlier query. One aborted for a newer
                    // query keeps them until that one answers.
                    if (status !== 'abort' && !leaving) {
                        termMatches = null;
                        termSearchFailed.prop('hidden', false);
                        apply();
                    }
                });
            return termRequest;
        };

        // Keep the search, filters and sort in the URL, so a view can be
        // bookmarked or shared, and survives a reload.
        const saveState = function () {
            // The controls the page keeps, as the server lists them, each
            // where it differs from how the page first shows: a search typed,
            // a box checked, or a menu not on its first choice.
            const params = new URLSearchParams();
            filters.data('state-names').split(' ').forEach(function (name) {
                const control = form[name];
                if (control.type === 'checkbox') {
                    if (control.checked) {
                        params.set(name, control.value);
                    }
                } else if (control.tagName === 'SELECT') {
                    if (control.value !== control.options[0].value) {
                        params.set(name, control.value);
                    }
                } else if (control.value.trim()) {
                    params.set(name, control.value.trim());
                }
            });
            const search = params.toString();
            // A browser may refuse when the URL changes too often, as it
            // does at every keystroke; the search itself must go on.
            try {
                history.replaceState(null, '', location.pathname + (search ? '?' + search : ''));
            } catch (e) {
                // The URL is only a convenience.
            }
        };

        filters.on('submit', function (e) {
            e.preventDefault();
        });
        $(form.q).on('input', function () {
            apply();
            clearTimeout(termTimer);
            termTimer = setTimeout(searchTerms, TERM_SEARCH_DELAY);
        });
        $(form.terms).on('change', searchTerms);
        $(form.tag).add(form.lang).add(form.show).on('change', apply);
        $(form.sort_by).add(form.sort_order).on('change', sort);

        // Import and Compare carry the library's search, filters and sort, so
        // that their links back return to it as it is now. Without the
        // script, they lead back to the library as it first shows.
        $(document).on('click', 'a.vocabulary-library-keep-place', function () {
            // The link may carry what was set when it was last followed, as
            // after coming Back to this page, so it is set or removed anew.
            const state = location.search.slice(1);
            const url = new URL(this.href, location.href);
            if (state) {
                url.searchParams.set('return', state);
            } else {
                url.searchParams.delete('return');
            }
            this.href = url.toString();
        });

        // The empty state's button, and Clear filters, change the controls
        // they stand for and move focus there, since each hides once it has
        // done its work.
        searchTermsButton.on('click', function () {
            form.terms.checked = true;
            form.terms.focus();
            searchTerms();
        });
        clearFiltersButton.on('click', function () {
            filterNames.forEach(function (name) {
                form[name].value = '';
            });
            form.tag.focus();
            apply();
        });

        // A tag in the details sidebar filters by that tag in place. Its link
        // also works without the script.
        $(document).on('click', 'a.vocabulary-library-tag', function (e) {
            e.preventDefault();
            form.tag.value = this.dataset.tag;
            apply();
        });

        // Core's expand toggle names each link "Expand" or "Collapse". The
        // sidebar's toggles have visible text, which names them better, and
        // aria-expanded already says which state they're in.
        $(document).on('o:expanded o:collapsed', '.vocabulary-library-more > a, .vocabulary-library-terms > a', function () {
            $(this).removeAttr('aria-label title');
        });

        $(document).on('click', '.sidebar .sidebar-section-nav [role="tab"]', function () {
            showTab(this);
        });
        // Trying again hides the button, so focus goes first to the tab,
        // where the lists, once in, are reached, and from where an "All
        // matching terms" waiting for them moves it on to their filter.
        $(document).on('click', '.sidebar .vocabulary-library-terms-retry', function () {
            const panel = this.closest('[data-terms-url]');
            document.getElementById(panel.getAttribute('aria-labelledby')).focus();
            loadTerms(panel);
        });
        $(document).on('keydown', '.sidebar .sidebar-section-nav [role="tab"]', function (e) {
            // With a modifier, the key is the browser's, as Alt+Left is Back.
            if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) {
                return;
            }
            const tabs = [...this.closest('[role="tablist"]').querySelectorAll('[role="tab"]')];
            const to = {
                ArrowRight: tabs[(tabs.indexOf(this) + 1) % tabs.length],
                ArrowLeft: tabs[(tabs.indexOf(this) + tabs.length - 1) % tabs.length],
                Home: tabs[0],
                End: tabs[tabs.length - 1],
            }[e.key];
            if (to) {
                e.preventDefault();
                showTab(to);
                to.focus();
            }
        });

        // Closing the sidebar returns focus to where it was opened from, or
        // else to that vocabulary's name, or the search: the close link
        // itself is hidden with the sidebar. Escape closes it too.
        $(document).on('click', 'a.sidebar-content', function () {
            sidebarOpener = this;
            sidebarOpenerRow = this.closest('tr');
        });
        $(document).on('o:sidebar-closed', '.sidebar', function () {
            const rowName = sidebarOpenerRow && sidebarOpenerRow.querySelector('.vocabulary-library-title > a.sidebar-content');
            const target = [sidebarOpener, rowName, form.q].find(el => el && el.isConnected && !el.closest('[hidden]'));
            sidebarOpener = sidebarOpenerRow = null;
            if (target) {
                target.focus();
            }
        });
        // Not from a text field, where Escape is for the field: it cancels
        // what is being composed, or dismisses the browser's suggestions.
        $(document).on('keydown', '.sidebar.active', function (e) {
            if (e.key === 'Escape' && !e.isComposing && !e.isDefaultPrevented() && !$(e.target).is('input, textarea, select')) {
                Omeka.closeSidebar($(this));
            }
        });

        // When the details sidebar opens, move focus into it: to the title,
        // or, when it opened from "All matching terms", to the Classes and
        // properties tab, which loads its lists, and once they are in, to
        // their filter, set to the search, so the matches are in view. Core
        // keeps the sidebar's content hidden, which can't take focus, until
        // just after this event, so focusing waits for the next frame, when
        // the browser has applied that.
        $(document).on('o:sidebar-content-loaded', '.sidebar', function () {
            const sidebar = this;
            const activeTab = sidebar.querySelector('[role="tab"].active');
            if (activeTab) {
                showTab(activeTab);
            }
            requestAnimationFrame(function () {
                const panel = sidebar.querySelector('#vocabulary-library-term-lists');
                if (panel && panel.dataset.filter) {
                    const tab = sidebar.querySelector('#vocabulary-library-term-lists-label');
                    showTab(tab);
                    tab.focus();
                    loadTerms(panel, function () {
                        // Unless the admin has moved on while they loaded.
                        const filter = panel.querySelector('.vocabulary-library-term-filter input');
                        if (filter && document.activeElement === tab) {
                            filter.scrollIntoView({block: 'start'});
                            filter.focus({preventScroll: true});
                        }
                    });
                    return;
                }
                const title = sidebar.querySelector('.vocabulary-library-details-title');
                if (title) {
                    title.focus();
                }
            });
        });

        // The template has already set the controls from the URL.
        sort();
        apply();
        // The page is read as a whole on load, count included, so nothing is
        // announced until the admin changes something, not even the count a
        // term search in the URL settles on.
        $.when(searchTerms()).always(function () {
            count.heard(countText());
        });
    };

    /**
     * The details sidebar's term filter: narrows the class and property lists
     * to terms whose label or local name contains every word of the text, as
     * the term search matches them, and opens the lists while it has any.
     * Emptied, it closes the lists it opened, leaving any the admin opened. It
     * starts with the text from a term search's "All matching terms", if any.
     */
    const initTermFilter = function (panel) {
        const input = panel.querySelector('.vocabulary-library-term-filter input');
        if (!input) {
            return;
        }
        const empty = panel.querySelector('.vocabulary-library-term-filter-empty');
        const status = announcer(panel.querySelector('.vocabulary-library-term-filter-status'));
        const groups = [...panel.querySelectorAll('.vocabulary-library-terms')].map(group => ({
            group: group,
            toggle: group.querySelector(':scope > a'),
            heading: group.querySelector('h4'),
            openedByFilter: false,
            values: [...group.querySelectorAll('.value')].map(function (value) {
                // The local name without the prefix, which every term shares.
                const term = value.querySelector('.field-term').textContent;
                return {
                    value: value,
                    text: fold(value.querySelector('.vocabulary-library-term-label').textContent + ' ' + term.slice(term.indexOf(':') + 1)),
                };
            }),
        }));
        // Opens or closes a list as core's toggle does, without the events
        // core fires for the admin's own clicks.
        const setOpen = function (group, open) {
            group.toggle.classList.replace(open ? 'expand' : 'collapse', open ? 'collapse' : 'expand');
            group.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            group.openedByFilter = open;
        };
        // A list the admin opens or closes is theirs, so emptying the filter
        // leaves it as it is.
        groups.forEach(function (group) {
            if (group.toggle) {
                $(group.toggle).on('o:expanded o:collapsed', function () {
                    group.openedByFilter = false;
                });
            }
        });
        const filter = function () {
            const query = fold(input.value.trim());
            // Every word must match, in any order.
            const words = query.split(/\s+/).filter(Boolean);
            let total = 0;
            groups.forEach(function (group) {
                let count = 0;
                group.values.forEach(function (term) {
                    term.value.hidden = !words.every(word => term.text.includes(word));
                    count += term.value.hidden ? 0 : 1;
                });
                // Every group shows until there's a filter, even one with no
                // terms at all, so the sidebar keeps one structure.
                group.group.hidden = query !== '' && count === 0;
                group.heading.textContent = group.heading.dataset.label + ' (' + count + ')';
                if (query && count && group.toggle.classList.contains('expand')) {
                    setOpen(group, true);
                } else if (!query && group.openedByFilter) {
                    setOpen(group, false);
                }
                total += count;
            });
            empty.hidden = !(query && total === 0);
            // What a screen reader is told: the headings with their counts,
            // as they show, or that nothing matches.
            return total ? groups.filter(group => !group.group.hidden).map(group => group.heading.textContent).join(', ') : empty.textContent.trim();
        };
        // What typing leaves is read out; what the sidebar opens with is not.
        input.addEventListener('input', function () {
            status.say(filter());
        });
        status.heard(filter());
    };

    /**
     * The import form: warns while the admin types a prefix that the site
     * already uses, or that Omeka reserves. Omeka compares prefixes without
     * regard to case.
     */
    const initImport = function (warnings) {
        const input = document.getElementById('o:prefix');
        const installed = warnings.data('installed-prefixes');
        const taken = warnings.find('.vocabulary-library-prefix-taken');
        const reserved = warnings.find('.vocabulary-library-prefix-reserved');
        const warning = announcer(document.querySelector('.vocabulary-library-prefix-announcement'));
        const check = function () {
            const prefix = input.value.trim();
            const holder = own(installed, prefix.toLowerCase());
            taken.prop('hidden', !holder);
            taken.find('.vocabulary-library-prefix-holder').text(holder || '');
            reserved.prop('hidden', !/^o(-|$)/.test(prefix));
            return warnings.children(':not([hidden])').text().trim().replace(/\s+/g, ' ');
        };
        // A warning that appears or changes while typing is read out. One
        // the form opens with is read with the field, which it describes.
        $(input).on('input', function () {
            warning.say(check());
        });
        warning.heard(check());
    };

    $(document).ready(function () {
        const filters = $('#vocabulary-library-filters');
        if (filters.length) {
            initBrowse(filters);
        }
        const warnings = $('#vocabulary-library-prefix-warnings');
        if (warnings.length) {
            initImport(warnings);
        }
    });

})(jQuery);
