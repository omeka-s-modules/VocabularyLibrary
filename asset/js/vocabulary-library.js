(function ($) {

    const TERM_SEARCH_DELAY = 400;

    /**
     * Lowercases and strips accents, so "Ontologie" matches "ontologié".
     */
    const fold = function (text) {
        return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
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
        const shown = $('.vocabulary-library-shown');
        const termItem = document.getElementById('vocabulary-library-term-item').content.firstElementChild;
        // A list item for a matching term, or for how many more match.
        const item = function (text, more) {
            const li = termItem.cloneNode(true);
            li.textContent = text;
            li.classList.toggle('vocabulary-library-term-more', more);
            return li;
        };
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

        const apply = function () {
            const query = fold(form.q.value.trim());
            const tag = form.tag.value;
            const lang = form.lang.value;
            const show = form.show.value;
            let count = 0;
            rows.forEach(function (row) {
                // Matches held from the last term search, narrowed to what is
                // typed now, so rows never show terms from an earlier query.
                const found = own(termMatches, row.dataset.slug);
                const terms = (found ? found.terms : []).filter(term => fold(term).includes(query));
                // How many more match, while the terms shown are for exactly
                // this query rather than narrowed from an earlier one.
                const more = found && termQuery === query ? found.count - found.terms.length : 0;
                let visible = !query || searchText.get(row).includes(query) || terms.length > 0;
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
                const items = terms.map(term => item(term, false));
                if (more > 0) {
                    items.push(item(filters.data('term-more').replace('%s', more), true));
                }
                matches.querySelector('.vocabulary-library-term-list').replaceChildren(...items);
                count += visible ? 1 : 0;
            });
            shown.text(count);
            table.prop('hidden', count === 0);
            empty.prop('hidden', count > 0);
            saveState();
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

        const searchTerms = function () {
            const query = form.q.value.trim();
            if (termRequest) {
                termRequest.abort();
                termRequest = null;
            }
            if (!form.terms.checked || query.length < termQueryMin) {
                termMatches = null;
                apply();
                return;
            }
            termRequest = $.get(filters.data('term-search-url'), {q: query})
                .done(function (data) {
                    termMatches = data.matches;
                    termQuery = fold(query);
                    apply();
                });
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

        // Move focus into the details sidebar when it opens.
        $(document).on('o:sidebar-content-loaded', '.sidebar', function () {
            $(this).find('.vocabulary-library-details-title').trigger('focus');
        });

        // The template has already set the controls from the URL.
        sort();
        apply();
        searchTerms();
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
