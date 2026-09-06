import { EditorView, basicSetup } from 'codemirror';
import { sql } from '@codemirror/lang-sql';

// Factory used by the Query Studio Livewire component (see query-studio.blade.php).
window.createSqlEditor = function (parent, initialDoc, onChange) {
    return new EditorView({
        doc: initialDoc ?? '',
        parent,
        extensions: [
            basicSetup,
            sql(),
            EditorView.updateListener.of((update) => {
                if (update.docChanged) {
                    onChange(update.state.doc.toString());
                }
            }),
            EditorView.theme({
                '&': { fontSize: '13px', minHeight: '220px' },
                '.cm-content': { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace' },
                '.cm-gutters': { backgroundColor: '#f8fafc', color: '#94a3b8', border: 'none' },
            }),
        ],
    });
};
