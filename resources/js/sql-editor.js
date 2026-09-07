import { EditorView, basicSetup } from 'codemirror';
import { Compartment } from '@codemirror/state';
import { sql } from '@codemirror/lang-sql';
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

// Factory used by the Query Studio Livewire component (see query-studio.blade.php).
window.createSqlEditor = function (parent, initialDoc, onChange) {
    const darkFlag = new Compartment();

    const view = new EditorView({
        doc: initialDoc ?? '',
        parent,
        extensions: [
            basicSetup,
            sql(),
            chrome,
            syntaxHighlighting(highlight),
            darkFlag.of(EditorView.darkTheme.of(isDark())),
            EditorView.updateListener.of((update) => {
                if (update.docChanged) {
                    onChange(update.state.doc.toString());
                }
            }),
        ],
    });

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
