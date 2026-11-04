/*
 * Schema explorer for the Query Studio (ADR-029).
 *
 * One Alpine component owns the whole workbench: it loads the table/column
 * tree from GET /connections/{id}/schema, renders it as an ARIA tree, feeds
 * the same names to the SQL editor for completion, and inserts names into
 * the editor on click. The editor view lives in a closure variable on
 * purpose: Alpine must not wrap a CodeMirror view in a reactive proxy.
 */

const LG_BREAKPOINT = '(min-width: 1024px)';

const FAILURE_MESSAGES = {
    401: 'Your session has expired. Reload the page to sign in again.',
    403: "You don't have access to this connection's schema.",
    404: 'This connection could not be found in your current team.',
    419: 'Your session has expired. Reload the page and try again.',
    429: 'The schema was refreshed too often. Wait a minute and try again.',
    503: 'The connection is busy running queries. Try again in a moment.',
};

// Rows rendered at once; a wider result asks the user to narrow the filter instead.
const MAX_ROWS = 500;

/*
 * Words that are legal column or table names but change meaning (or fail to
 * parse) when left bare: `SELECT user` is current_user in PostgreSQL. A small
 * cross-dialect set is enough; quoting a word that did not need it is harmless.
 */
const RESERVED_WORDS = new Set([
    'all', 'alter', 'and', 'any', 'as', 'asc', 'between', 'by', 'case', 'check', 'column', 'constraint',
    'create', 'cross', 'current_date', 'current_time', 'current_timestamp', 'current_user', 'database',
    'default', 'delete', 'desc', 'distinct', 'drop', 'else', 'end', 'except', 'exists', 'false', 'for',
    'foreign', 'from', 'full', 'grant', 'group', 'having', 'in', 'index', 'inner', 'insert', 'intersect',
    'into', 'is', 'join', 'key', 'left', 'like', 'limit', 'natural', 'not', 'null', 'offset', 'on', 'or',
    'order', 'outer', 'primary', 'references', 'right', 'select', 'session_user', 'set', 'some', 'table',
    'then', 'to', 'true', 'union', 'unique', 'update', 'user', 'using', 'values', 'view', 'when', 'where',
    'with',
]);

// Identifiers that are safe unquoted; anything else is quoted for the driver.
const plainIdentifier = (driver, name) => (driver === 'pgsql'
    ? /^[a-z_][a-z0-9_$]*$/.test(name)
    : /^[A-Za-z_][A-Za-z0-9_$]*$/.test(name)) && !RESERVED_WORDS.has(name.toLowerCase());

const quoteIdentifier = (driver, name) => {
    if (plainIdentifier(driver, name)) {
        return name;
    }

    if (driver === 'mysql' || driver === 'mariadb') {
        return '`' + name.replaceAll('`', '``') + '`';
    }

    if (driver === 'sqlsrv') {
        return '[' + name.replaceAll(']', ']]') + ']';
    }

    return '"' + name.replaceAll('"', '""') + '"';
};

const columnDetail = (column) => [column.type, column.nullable ? 'nullable' : null, column.masked ? 'masked' : null]
    .filter(Boolean)
    .join(' · ');

document.addEventListener('alpine:init', () => {
    window.Alpine.data('schemaExplorer', (config) => {
        let editor = null;
        let controller = null;
        let formatTimer = null;
        // Kept outside Alpine's state: a deep reactive proxy over thousands of columns is pure overhead.
        let schemas = [];
        const media = window.matchMedia(LG_BREAKPOINT);

        return {
            connections: config.connections,

            // Tree data
            status: 'idle', // idle | loading | ready | failed
            error: '',
            driver: null,
            hasObjects: false,
            moreRows: 0,
            defaultSchema: null,
            truncated: false,
            source: null,
            fetchedAt: null,

            // Tree view state
            query: '',
            expanded: {},
            rows: [],
            activeKey: null,
            description: '',

            refreshing: false,

            // Panel state
            shown: media.matches,
            isDrawer: !media.matches,

            // Format button
            formatNote: '',
            formatFailed: false,

            init() {
                const onViewportChange = () => {
                    this.isDrawer = !media.matches;
                    this.shown = media.matches;
                };

                media.addEventListener('change', onViewportChange);

                this.$wire.$watch('connectionId', () => this.load());

                this.load();
            },

            mountEditor(element) {
                editor = window.createSqlEditor(
                    element,
                    this.$wire.sql,
                    (value) => this.$wire.$set('sql', value, false),
                    { onFormat: () => this.format() },
                );

                this.syncEditor();
            },

            // ---- loading ---------------------------------------------------

            currentConnection() {
                const id = this.$wire.connectionId;

                return id ? (this.connections[String(id)] ?? null) : null;
            },

            reset() {
                schemas = [];
                this.hasObjects = false;
                this.moreRows = 0;
                this.defaultSchema = null;
                this.truncated = false;
                this.source = null;
                this.fetchedAt = null;
                this.expanded = {};
                this.activeKey = null;
                this.query = '';
                this.description = '';
                this.error = '';
                this.rows = [];
            },

            async load(refresh = false) {
                controller?.abort();

                const connection = this.currentConnection();

                if (!connection) {
                    this.driver = null;
                    this.status = 'idle';
                    this.reset();
                    this.syncEditor();

                    return;
                }

                this.driver = connection.driver;

                // A refresh keeps the tree on screen; a connection switch must not show the old one.
                if (!refresh) {
                    this.reset();
                    this.syncEditor();
                }

                this.status = refresh && schemas.length ? 'ready' : 'loading';
                this.error = '';
                this.refreshing = refresh;

                const current = new AbortController();
                controller = current;

                try {
                    const response = await fetch(connection.url + (refresh ? '?refresh=1' : ''), {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        signal: current.signal,
                    });

                    if (!response.ok) {
                        let message = FAILURE_MESSAGES[response.status];

                        if (!message) {
                            const body = await response.json().catch(() => null);
                            message = body?.message || `The schema could not be loaded (HTTP ${response.status}).`;
                        }

                        throw new Error(message);
                    }

                    const { data } = await response.json();

                    this.accept(data, refresh);
                    this.status = 'ready';
                } catch (error) {
                    if (current.signal.aborted) {
                        return;
                    }

                    this.error = error instanceof TypeError
                        ? 'The schema could not be loaded. Check your connection and try again.'
                        : error.message;
                    this.status = schemas.length ? 'ready' : 'failed';
                } finally {
                    if (controller === current) {
                        this.refreshing = false;
                    }
                }
            },

            accept(data, keepState) {
                schemas = data.schemas ?? [];
                this.hasObjects = schemas.some((schema) => schema.tables.length > 0);
                this.defaultSchema = data.default_schema ?? (schemas.length === 1 ? schemas[0].name : null);
                this.truncated = Boolean(data.truncated);
                this.source = data.source ?? null;
                this.fetchedAt = data.fetched_at ?? null;

                if (!keepState) {
                    const expanded = {};

                    schemas.forEach((schema, index) => {
                        if (schema.name === this.defaultSchema) {
                            expanded[`s${index}`] = true;
                        }
                    });

                    this.expanded = expanded;
                }

                this.syncEditor();
                this.rebuild();
            },

            get loadedLabel() {
                if (!this.source) {
                    return '';
                }

                const time = this.fetchedAt
                    ? new Date(this.fetchedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                    : '';

                return `${this.source === 'cache' ? 'Cached' : 'Live'}${time ? ` · ${time}` : ''}`;
            },

            // ---- editor bridge --------------------------------------------

            syncEditor() {
                if (!editor) {
                    return;
                }

                const schema = {};
                const columnsByTable = {};

                for (const group of schemas) {
                    const tables = {};

                    for (const table of group.tables) {
                        const columns = table.columns.map((column) => ({
                            label: column.name,
                            type: 'property',
                            detail: columnDetail(column),
                        }));

                        tables[table.name] = {
                            self: { label: table.name, type: 'class', detail: table.type },
                            children: columns,
                        };

                        // Same table name in two schemas: the columns merge by name in the editor.
                        (columnsByTable[table.name.toLowerCase()] ??= []).push(...columns);
                    }

                    schema[group.name] = tables;
                }

                editor.setSqlContext({
                    driver: this.driver,
                    schema,
                    columnsByTable,
                    defaultSchema: this.defaultSchema ?? undefined,
                });
            },

            insertName(row) {
                if (!editor || row.kind === 'schema') {
                    return;
                }

                const driver = this.driver;
                let text = quoteIdentifier(driver, row.name);

                if (row.kind === 'table' && row.schema !== this.defaultSchema) {
                    text = `${quoteIdentifier(driver, row.schema)}.${text}`;
                }

                editor.insertText(text);

                // The drawer's focus trap swallows the focus() above while it is still active, so the
                // editor takes the keyboard back once the drawer is gone (not the toggle button).
                if (this.isDrawer) {
                    this.close(false);
                    this.$nextTick(() => setTimeout(() => editor.focus(), 0));
                }
            },

            async format() {
                if (!editor) {
                    return;
                }

                const result = await editor.formatDocument();

                this.formatFailed = !result.ok;
                this.formatNote = result.ok ? 'SQL formatted.' : `Could not format: ${result.message}`;

                clearTimeout(formatTimer);

                if (result.ok) {
                    formatTimer = setTimeout(() => { this.formatNote = ''; }, 2500);
                }

                editor.focus();
            },

            // ---- tree model -----------------------------------------------

            rebuild() {
                const q = this.query.trim().toLowerCase();
                const searching = q !== '';
                const rows = [];

                schemas.forEach((schema, si) => {
                    const tables = [];

                    schema.tables.forEach((table, ti) => {
                        const tableHit = !searching || table.name.toLowerCase().includes(q);
                        const columns = searching && !tableHit
                            ? table.columns.filter((column) => column.name.toLowerCase().includes(q))
                            : table.columns;

                        if (tableHit || columns.length) {
                            tables.push({ table, ti, columns, columnOnlyHit: searching && !tableHit });
                        }
                    });

                    if (searching && !tables.length) {
                        return;
                    }

                    const sKey = `s${si}`;
                    const sOpen = this.expanded[sKey] ?? searching;

                    rows.push({
                        key: sKey,
                        kind: 'schema',
                        level: 1,
                        name: schema.name,
                        schema: schema.name,
                        expandable: true,
                        open: sOpen,
                        count: schema.tables.length,
                        parentKey: null,
                    });

                    if (!sOpen) {
                        return;
                    }

                    tables.forEach(({ table, ti, columns, columnOnlyHit }) => {
                        const tKey = `t${si}.${ti}`;
                        const tOpen = this.expanded[tKey] ?? columnOnlyHit;

                        rows.push({
                            key: tKey,
                            kind: 'table',
                            level: 2,
                            name: table.name,
                            schema: schema.name,
                            type: table.type,
                            expandable: table.columns.length > 0,
                            open: tOpen,
                            count: table.columns.length,
                            parentKey: sKey,
                        });

                        if (!tOpen) {
                            return;
                        }

                        columns.forEach((column, ci) => {
                            rows.push({
                                key: `c${si}.${ti}.${ci}`,
                                kind: 'column',
                                level: 3,
                                name: column.name,
                                schema: schema.name,
                                table: table.name,
                                type: column.type,
                                nullable: Boolean(column.nullable),
                                masked: Boolean(column.masked),
                                expandable: false,
                                open: false,
                                parentKey: tKey,
                            });
                        });
                    });
                });

                this.moreRows = Math.max(0, rows.length - MAX_ROWS);
                this.rows = this.moreRows ? rows.slice(0, MAX_ROWS) : rows;

                if (!this.rows.some((row) => row.key === this.activeKey)) {
                    this.activeKey = this.rows[0]?.key ?? null;
                }
            },

            toggle(row) {
                if (!row.expandable) {
                    return;
                }

                this.expanded[row.key] = !row.open;
                this.rebuild();
            },

            activate(row) {
                if (row.kind === 'schema') {
                    this.toggle(row);

                    return;
                }

                this.insertName(row);
            },

            rowLabel(row) {
                if (row.kind === 'schema') {
                    return `Schema ${row.name}, ${row.count} ${row.count === 1 ? 'object' : 'objects'}`;
                }

                if (row.kind === 'table') {
                    return `${row.type === 'view' ? 'View' : 'Table'} ${row.name}, ${row.count} ${row.count === 1 ? 'column' : 'columns'}`;
                }

                return `Column ${row.name}, ${row.type}${row.nullable ? ', nullable' : ''}${row.masked ? ', masked' : ''}`;
            },

            describe(row) {
                if (row.kind === 'column') {
                    return `${row.table}.${row.name} — ${row.type}${row.nullable ? ', nullable' : ', not null'}`
                        + (row.masked ? '. Masked: values are hidden in query results.' : '.');
                }

                return this.rowLabel(row) + '.';
            },

            focusRow(key) {
                this.activeKey = key;
                this.$nextTick(() => {
                    this.$refs.tree?.querySelector(`[data-key="${CSS.escape(key)}"]`)?.focus();
                });
            },

            onKeydown(event, row) {
                const index = this.rows.findIndex((candidate) => candidate.key === row.key);
                const move = (target) => {
                    if (target) {
                        event.preventDefault();
                        this.focusRow(target.key);
                    }
                };

                switch (event.key) {
                    case 'ArrowDown':
                        move(this.rows[index + 1]);
                        break;
                    case 'ArrowUp':
                        move(this.rows[index - 1]);
                        break;
                    case 'Home':
                        move(this.rows[0]);
                        break;
                    case 'End':
                        move(this.rows[this.rows.length - 1]);
                        break;
                    case 'ArrowRight':
                        event.preventDefault();

                        if (row.expandable && !row.open) {
                            this.toggle(row);
                        } else if (row.expandable) {
                            move(this.rows[index + 1]);
                        }

                        break;
                    case 'ArrowLeft':
                        event.preventDefault();

                        if (row.expandable && row.open) {
                            this.toggle(row);
                        } else {
                            move(this.rows.find((candidate) => candidate.key === row.parentKey));
                        }

                        break;
                    case 'Enter':
                    case ' ':
                        event.preventDefault();
                        this.activate(row);
                        break;
                    default:
                }
            },

            // ---- panel ----------------------------------------------------

            toggleShown() {
                this.shown = !this.shown;

                if (this.shown) {
                    this.$nextTick(() => this.$refs.search?.focus());
                }
            },

            close(returnFocus = true) {
                this.shown = false;

                if (returnFocus) {
                    this.$nextTick(() => this.$refs.toggle?.focus());
                }
            },
        };
    });
});
