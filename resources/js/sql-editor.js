import { EditorView, basicSetup } from 'codemirror';
import { keymap } from '@codemirror/view';
import { Compartment, Prec } from '@codemirror/state';
import { sql, StandardSQL, PostgreSQL, MySQL, MariaSQL, MSSQL, SQLite } from '@codemirror/lang-sql';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { tags } from '@lezer/highlight';

const isDark = () => document.documentElement.dataset.theme !== 'light';

/*
 * Every colour below is a design token read from the document, so one theme
 * object serves both light and dark: flipping <html data-theme> re-paints the
 * editor for free. CodeMirror renders in the light DOM, so the custom
 * properties inherit normally.
 */
const chrome = EditorView.theme({
    '&': {
        backgroundColor: 'var(--color-panel)',
        color: 'var(--color-ink-3)',
        fontSize: '12.5px',
    },
    '&.cm-focused': { outline: 'none' },
    '.cm-scroller': { fontFamily: 'var(--font-mono)', lineHeight: '1.7', minHeight: '220px' },
    '.cm-content': { caretColor: 'var(--color-accent)', padding: '8px 0' },
    '.cm-line': { padding: '0 12px' },

    '.cm-cursor, .cm-dropCursor': { borderLeftColor: 'var(--color-accent)', borderLeftWidth: '2px' },
    '.cm-selectionBackground, &.cm-focused .cm-selectionBackground, .cm-content ::selection': {
        backgroundColor: 'var(--color-raised)',
    },

    '.cm-gutters': {
        backgroundColor: 'var(--color-header)',
        color: 'var(--color-hairline)',
        border: 'none',
        borderRight: '1px solid var(--color-line)',
    },
    '.cm-lineNumbers .cm-gutterElement': { padding: '0 10px 0 8px', fontVariantNumeric: 'tabular-nums' },
    '.cm-activeLine': { backgroundColor: 'var(--color-raised)' },
    '.cm-activeLineGutter': { backgroundColor: 'var(--color-raised)', color: 'var(--color-ink-5)' },

    '.cm-matchingBracket, &.cm-focused .cm-matchingBracket': {
        backgroundColor: 'var(--color-accent-bg)',
        outline: '1px solid var(--color-accent-line)',
    },
    '.cm-nonmatchingBracket': { outline: '1px solid var(--color-danger-line)' },
    '.cm-selectionMatch': { backgroundColor: 'var(--color-accent-bg)' },

    '.cm-tooltip': {
        backgroundColor: 'var(--color-panel)',
        border: '1px solid var(--color-line-strong)',
        borderRadius: 'var(--radius-control)',
        color: 'var(--color-ink-3)',
    },
    '.cm-tooltip-autocomplete > ul > li': { fontFamily: 'var(--font-mono)', padding: '2px 8px' },
    '.cm-tooltip-autocomplete > ul > li[aria-selected]': {
        backgroundColor: 'var(--color-raised)',
        color: 'var(--color-ink)',
    },
});

/* Overrides basicSetup's defaultHighlightStyle, which registers with fallback: true. */
const highlight = HighlightStyle.define([
    { tag: [tags.keyword, tags.operatorKeyword, tags.modifier, tags.controlKeyword], color: 'var(--color-accent)', fontWeight: '500' },
    { tag: [tags.string, tags.special(tags.string), tags.character], color: 'var(--color-ok)' },
    { tag: [tags.number, tags.bool, tags.null, tags.literal], color: 'var(--color-pending)' },
    { tag: [tags.comment, tags.lineComment, tags.blockComment], color: 'var(--color-mute-4)', fontStyle: 'italic' },
    { tag: [tags.function(tags.variableName), tags.standard(tags.name)], color: 'var(--color-running)' },
    { tag: [tags.typeName, tags.className, tags.namespace], color: 'var(--color-write)' },
    { tag: [tags.variableName, tags.propertyName, tags.attributeName], color: 'var(--color-ink-2)' },
    { tag: [tags.operator, tags.punctuation, tags.separator, tags.bracket, tags.paren], color: 'var(--color-mute)' },
    { tag: tags.invalid, color: 'var(--color-danger)' },
]);

// Connection driver (App\Enums\DbDriver) -> CodeMirror dialect and sql-formatter language.
const editorDialects = {
    pgsql: PostgreSQL,
    mysql: MySQL,
    mariadb: MariaSQL,
    sqlsrv: MSSQL,
    sqlite: SQLite,
};

const formatterLanguages = {
    pgsql: 'postgresql',
    mysql: 'mysql',
    mariadb: 'mariadb',
    sqlsrv: 'transactsql',
    sqlite: 'sqlite',
};

/*
 * lang-sql completes columns only after "table." (or for one defaultTable).
 * This extra source also offers the columns of every known table named after
 * FROM / JOIN / UPDATE / INTO in the document, so "WHERE em" finds "email".
 */
const TABLE_REFERENCE = /\b(?:from|join|update|into)\s+(?:[`"[]?[\w$]+[`"\]]?\s*\.\s*)?[`"[]?([\w$]+)/gi;

const referencedColumns = (columnsByTable) => (context) => {
    const word = context.matchBefore(/[\w$]*/);

    if (!word || (word.from === word.to && !context.explicit)) {
        return null;
    }

    if (context.state.sliceDoc(Math.max(0, word.from - 1), word.from) === '.') {
        return null;
    }

    const options = new Map();

    for (const match of context.state.doc.toString().matchAll(TABLE_REFERENCE)) {
        for (const column of columnsByTable[match[1].toLowerCase()] ?? []) {
            options.set(column.label, column);
        }
    }

    return options.size ? { from: word.from, options: [...options.values()], validFor: /^[\w$]*$/ } : null;
};

const sqlSupport = ({ driver = null, schema = {}, defaultSchema, columnsByTable = {} } = {}) => {
    const support = sql({
        dialect: editorDialects[driver] ?? StandardSQL,
        schema,
        defaultSchema,
        upperCaseKeywords: true,
    });

    return [support, support.language.data.of({ autocomplete: referencedColumns(columnsByTable) })];
};

/*
 * sql-formatter is only needed when someone presses "Format", so it is loaded
 * on demand and stays out of the main bundle.
 */
const formatSql = async (source, driver) => {
    const { format } = await import('sql-formatter');

    return format(source, {
        language: formatterLanguages[driver] ?? 'sql',
        keywordCase: 'upper',
        tabWidth: 2,
    });
};

// Factory used by the Query Studio Livewire component (see query-studio.blade.php).
window.createSqlEditor = function (parent, initialDoc, onChange, { onFormat = null } = {}) {
    const darkFlag = new Compartment();
    const sqlFlag = new Compartment();
    let driver = null;

    const view = new EditorView({
        doc: initialDoc ?? '',
        parent,
        extensions: [
            basicSetup,
            sqlFlag.of(sqlSupport()),
            chrome,
            syntaxHighlighting(highlight),
            darkFlag.of(EditorView.darkTheme.of(isDark())),
            Prec.high(keymap.of([{
                key: 'Mod-Shift-f',
                preventDefault: true,
                run: () => {
                    onFormat?.();

                    return true;
                },
            }])),
            EditorView.updateListener.of((update) => {
                if (update.docChanged) {
                    onChange(update.state.doc.toString());
                }
            }),
        ],
    });

    // Re-point completion at a connection: dialect from its driver, names from its schema tree.
    view.setSqlContext = (context) => {
        driver = context?.driver ?? null;
        view.dispatch({ effects: sqlFlag.reconfigure(sqlSupport(context ?? {})) });
    };

    // Replace the selection (or insert at the caret) and hand the keyboard back to the editor.
    view.insertText = (text) => {
        view.dispatch(view.state.replaceSelection(text), { scrollIntoView: true, userEvent: 'input' });
        view.focus();
    };

    // Resolves { ok: true } or { ok: false, message }; the document is untouched on failure.
    view.formatDocument = async () => {
        const source = view.state.doc.toString();

        if (source.trim() === '') {
            return { ok: false, message: 'There is nothing to format.' };
        }

        try {
            const formatted = await formatSql(source, driver);

            if (view.state.doc.toString() === source && formatted !== source) {
                view.dispatch({ changes: { from: 0, to: source.length, insert: formatted }, userEvent: 'input' });
            }

            return { ok: true };
        } catch (error) {
            return { ok: false, message: String(error?.message ?? error).split('\n')[0] };
        }
    };

    // Colours follow the custom properties on their own; only CodeMirror's own
    // light/dark boolean (tooltip and panel defaults) has to be told.
    const onThemeChange = () => view.dispatch({
        effects: darkFlag.reconfigure(EditorView.darkTheme.of(isDark())),
    });

    document.documentElement.addEventListener('qp:theme-changed', onThemeChange);

    const destroy = view.destroy.bind(view);
    view.destroy = () => {
        document.documentElement.removeEventListener('qp:theme-changed', onThemeChange);
        destroy();
    };

    return view;
};
