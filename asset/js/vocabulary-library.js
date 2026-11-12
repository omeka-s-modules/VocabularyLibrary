(function ($) {

    const TERM_SEARCH_DELAY = 400;
    // Long enough for a term search's results to change the count first.
    const ANNOUNCE_DELAY = 1000;

    /**
     * Shows a tab of the details sidebar and its section, as core's resource
     * form does for its sidebar tabs; core's handler isn't loaded here.
     */
    const showTab = function (tab) {
        tab.closest('[role="tablist"]').querySelectorAll('[role="tab"]').forEach(function (other) {
            const selected = other === tab;
            other.classList.toggle('active', selected);
            other.setAttribute('aria-selected', selected ? 'true' : 'false');
            document.getElementById(other.dataset.id).classList.toggle('active', selected);
        });
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
        const clearFiltersButton = empty.find('.vocabulary-library-clear-filters');
        const filterNames = ['tag', 'lang', 'show'];
        const shown = $('.vocabulary-library-shown');
        const countText = () => $('.vocabulary-library-count').text().trim().replace(/\s+/g, ' ');
        const announcement = document.querySelector('.vocabulary-library-announcement');
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
        let announced = null;
        let announceTimer = null;

        // Reads the count out once the admin pauses, and only if it changed,
        // so a screen reader doesn't speak it at every keystroke.
        const announce = function () {
            clearTimeout(announceTimer);
            announceTimer = setTimeout(function () {
                if (countText() !== announced) {
                    announced = countText();
                    announcement.textContent = announced;
                }
            }, ANNOUNCE_DELAY);
        };

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
            // is long enough for a term search, and to clear the filters,
            // keeping the query.
            searchTermsButton.prop('hidden', form.terms.checked || form.q.value.trim().length < termQueryMin);
            clearFiltersButton.prop('hidden', filterNames.every(name => form[name].value === ''));
            saveState();
            announce();
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
                });
            return termRequest;
        };

        // Keep the search, filters and sort in the URL, so a view can be
        // bookmarked or shared, and survives a reload.
        const saveState = function () {
            const params = new URLSearchParams();
            if (form.q.value.trim()) {
                params.set('q', form.q.value.trim());
            }
            if (form.terms.checked) {
                params.set('terms', '1');
            }
            ['tag', 'lang', 'show', 'sort_by', 'sort_order'].forEach(function (name) {
                if (form[name].value !== form[name].options[0].value) {
                    params.set(name, form[name].value);
                }
            });
            const search = params.toString();
            history.replaceState(null, '', location.pathname + (search ? '?' + search : ''));
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

        // The empty state's buttons change the controls they stand for, and
        // move focus there, since the buttons hide once something matches.
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

        // When the details sidebar opens, set up its term filter and move
        // focus into it: to the term filter, on the Classes and properties
        // tab, when it opened from "All matching terms", so the matches are in
        // view, otherwise to the title. Core keeps the sidebar's content
        // hidden, which can't take focus, until just after this event, so
        // focusing waits for the next frame, when the browser has applied
        // that.
        $(document).on('o:sidebar-content-loaded', '.sidebar', function () {
            const sidebar = this;
            initTermFilter(sidebar);
            requestAnimationFrame(function () {
                const filter = sidebar.querySelector('.vocabulary-library-term-filter input');
                if (filter && filter.value !== '') {
                    showTab(sidebar.querySelector('#vocabulary-library-term-lists-label'));
                    filter.scrollIntoView({block: 'start'});
                    filter.focus({preventScroll: true});
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
            clearTimeout(announceTimer);
            announced = countText();
        });
    };

    /**
     * The details sidebar's term filter: narrows the class and property lists
     * to terms whose label or local name contains the text, as the term search
     * matches them, and opens the lists while it has any. Emptied, it
     * closes the lists it opened, leaving any the admin opened. It starts with
     * the text from a term search's "All matching terms", if any.
     */
    const initTermFilter = function (sidebar) {
        const input = sidebar.querySelector('.vocabulary-library-term-filter input');
        if (!input) {
            return;
        }
        const empty = sidebar.querySelector('.vocabulary-library-term-filter-empty');
        const groups = [...sidebar.querySelectorAll('.vocabulary-library-terms')].map(group => ({
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
        };
        input.addEventListener('input', filter);
        filter();
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
        const check = function () {
            const prefix = input.value.trim();
            const holder = own(installed, prefix.toLowerCase());
            taken.prop('hidden', !holder);
            taken.find('.vocabulary-library-prefix-holder').text(holder || '');
            reserved.prop('hidden', !/^o(-|$)/.test(prefix));
        };
        $(input).on('input', check);
        check();
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
