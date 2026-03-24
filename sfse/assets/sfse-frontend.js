/**
 * Sustainable Food System Events — Front-end Filter Logic
 * Pure JavaScript, no dependencies.
 */

(function () {
    'use strict';

    // ── State ────────────────────────────────────────────────────────────────────
    const state = {
        country:       '',
        dateFrom:      '',
        dateTo:        '',
        preset:        '',
        format:        '',
        topic:         '',
        continent:     '',
        eventType:     '',
        cost:          'all',
        eventLanguage: '',
        organiser:     '',
    };

    // ── DOM refs ─────────────────────────────────────────────────────────────────
    let grid        = null;
    let resultCount = null;
    const cards     = () => Array.from(grid ? grid.querySelectorAll('.sfse-card') : []);

    // ── Helpers ──────────────────────────────────────────────────────────────────
    function getData(card, key) {
        return (card.dataset[key] || '').toLowerCase().trim();
    }

    function parseDate(str) {
        if (!str) return null;
        const d = new Date(str);
        return isNaN(d) ? null : d;
    }

    function today() {
        const d = new Date();
        d.setHours(0, 0, 0, 0);
        return d;
    }

    function addDays(date, n) {
        const d = new Date(date);
        d.setDate(d.getDate() + n);
        return d;
    }

    function addMonths(date, n) {
        const d = new Date(date);
        d.setMonth(d.getMonth() + n);
        return d;
    }

    function isFree(costVal) {
        if (!costVal) return false;
        const v = costVal.toLowerCase().trim();
        return v === 'free' || v === '0' || v === 'gratis' || v === 'gratuit' || v === 'kostenlos' || v === 'gratu\u00efts';
    }

    // ── Apply preset ─────────────────────────────────────────────────────────────
    function applyPreset(preset) {
        const t = today();
        state.preset = preset;
        state.dateFrom = '';
        state.dateTo = '';

        const fromInput = document.getElementById('sfse-date-from');
        const toInput   = document.getElementById('sfse-date-to');

        if (fromInput) fromInput.value = '';
        if (toInput)   toInput.value   = '';

        document.querySelectorAll('.sfse-preset-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.preset === preset);
        });

        if (preset === '30d') {
            state.dateFrom = t.toISOString().slice(0, 10);
            state.dateTo   = addDays(t, 30).toISOString().slice(0, 10);
        } else if (preset === '3m') {
            state.dateFrom = t.toISOString().slice(0, 10);
            state.dateTo   = addMonths(t, 3).toISOString().slice(0, 10);
        } else if (preset === '6m') {
            state.dateFrom = t.toISOString().slice(0, 10);
            state.dateTo   = addMonths(t, 6).toISOString().slice(0, 10);
        }
        filterCards();
    }

    // ── Filter logic ─────────────────────────────────────────────────────────────
    function filterCards() {
        const fromDate = parseDate(state.dateFrom);
        const toDate   = parseDate(state.dateTo);
        if (toDate) toDate.setHours(23, 59, 59);

        let visible = 0;

        cards().forEach(card => {
            let show = true;

            // Country
            if (state.country) {
                const cardCountry = getData(card, 'country');
                if (!cardCountry.includes(state.country.toLowerCase())) show = false;
            }

            // Date range
            if (show && (fromDate || toDate)) {
                const cardStart = parseDate(card.dataset.dateStart);
                if (cardStart) {
                    if (fromDate && cardStart < fromDate) show = false;
                    if (toDate   && cardStart > toDate)   show = false;
                }
            }

            // Format
            if (show && state.format) {
                if (getData(card, 'format') !== state.format) show = false;
            }

            // Topic
            if (show && state.topic) {
                const topics = getData(card, 'topics');
                if (!topics.includes(state.topic)) show = false;
            }

            // Continent
            if (show && state.continent) {
                if (getData(card, 'continent') !== state.continent.toLowerCase()) show = false;
            }

            // Event type
            if (show && state.eventType) {
                if (getData(card, 'eventType') !== state.eventType) show = false;
            }

            // Cost
            if (show && state.cost !== 'all') {
                const costVal = card.dataset.cost || '';
                if (state.cost === 'free'  && !isFree(costVal)) show = false;
                if (state.cost === 'paid'  &&  isFree(costVal)) show = false;
            }

            // Event language
            if (show && state.eventLanguage) {
                const langs = getData(card, 'eventLanguages');
                if (!langs.includes(state.eventLanguage)) show = false;
            }

            // Organiser (text search)
            if (show && state.organiser) {
                const org = getData(card, 'organiser');
                if (!org.includes(state.organiser.toLowerCase())) show = false;
            }

            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        // No results message
        let noResults = grid ? grid.querySelector('.sfse-no-results') : null;
        if (visible === 0) {
            if (!noResults) {
                noResults = document.createElement('div');
                noResults.className = 'sfse-no-results';
                noResults.innerHTML = '<p>No events match your current filters.</p><p>Try adjusting or resetting the filters above.</p>';
                if (grid) grid.appendChild(noResults);
            }
            noResults.style.display = '';
        } else if (noResults) {
            noResults.style.display = 'none';
        }

        // Result count
        const total = cards().length;
        if (resultCount) {
            resultCount.textContent = visible === total
                ? `${total} event${total !== 1 ? 's' : ''}`
                : `${visible} of ${total} event${total !== 1 ? 's' : ''}`;
        }
    }

    // ── Bind filters ─────────────────────────────────────────────────────────────
    function bindSelect(id, stateKey) {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', () => {
            state[stateKey] = el.value;
            if (stateKey !== 'country' && stateKey !== 'organiser') state.preset = '';
            document.querySelectorAll('.sfse-preset-btn').forEach(b => {
                if (stateKey === 'dateFrom' || stateKey === 'dateTo') b.classList.remove('active');
            });
            filterCards();
        });
    }

    function bindInput(id, stateKey) {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', () => {
            state[stateKey] = el.value;
            filterCards();
        });
    }

    function bindDateInput(id, stateKey) {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', () => {
            state[stateKey] = el.value;
            state.preset = '';
            document.querySelectorAll('.sfse-preset-btn').forEach(b => b.classList.remove('active'));
            filterCards();
        });
    }

    // ── Init ─────────────────────────────────────────────────────────────────────
    function init() {
        // Assign DOM refs at init time so LiteSpeed bundling doesn't cause early null
        grid        = document.getElementById('sfse-grid');
        resultCount = document.getElementById('sfse-result-count');

        // Advanced filters toggle — bind only once
        const advBtn   = document.getElementById('sfse-advanced-btn');
        const advPanel = document.getElementById('sfse-advanced-panel');
        if (advBtn && advPanel && !advBtn.dataset.sfseInit) {
            advBtn.dataset.sfseInit = '1';
            advBtn.addEventListener('click', () => {
                const isOpen = advPanel.classList.toggle('open');
                advBtn.classList.toggle('open', isOpen);
                advBtn.setAttribute('aria-expanded', isOpen);
            });
        }

        // Reset button — bind only once
        const resetBtn = document.getElementById('sfse-reset-filters');
        if (resetBtn && !resetBtn.dataset.sfseInit) {
            resetBtn.dataset.sfseInit = '1';
            resetBtn.addEventListener('click', () => {
                Object.keys(state).forEach(k => { state[k] = k === 'cost' ? 'all' : ''; });
                document.querySelectorAll('.sfse-filters select').forEach(s => s.value = '');
                document.querySelectorAll('.sfse-filters input[type="date"]').forEach(i => i.value = '');
                document.querySelectorAll('.sfse-filters input[type="text"]').forEach(i => i.value = '');
                document.querySelectorAll('.sfse-preset-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.sfse-cost-toggle button').forEach(b => {
                    b.classList.toggle('active', b.dataset.cost === 'all');
                });
                if (grid) filterCards();
            });
        }

        // Everything below requires the grid
        if (!grid) return;

        // Primary filters
        bindSelect('sfse-filter-country',    'country');
        bindDateInput('sfse-date-from',      'dateFrom');
        bindDateInput('sfse-date-to',        'dateTo');

        // Advanced filters
        bindSelect('sfse-filter-format',     'format');
        bindSelect('sfse-filter-topic',      'topic');
        bindSelect('sfse-filter-continent',  'continent');
        bindSelect('sfse-filter-type',       'eventType');
        bindSelect('sfse-filter-language',   'eventLanguage');
        bindInput('sfse-filter-organiser',   'organiser');

        // Preset buttons
        document.querySelectorAll('.sfse-preset-btn').forEach(btn => {
            btn.addEventListener('click', () => applyPreset(btn.dataset.preset));
        });

        // Cost toggle
        document.querySelectorAll('.sfse-cost-toggle button').forEach(btn => {
            btn.addEventListener('click', () => {
                state.cost = btn.dataset.cost;
                document.querySelectorAll('.sfse-cost-toggle button').forEach(b => {
                    b.classList.toggle('active', b.dataset.cost === state.cost);
                });
                filterCards();
            });
        });

        // Initial count
        filterCards();
    }

    // Run on DOMContentLoaded or immediately if already loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
