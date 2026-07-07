import { Controller } from '@hotwired/stimulus';

/*
 * Dependency-free rich-text editor. Progressively enhances a <textarea> with a
 * small formatting toolbar and a contenteditable surface; the underlying
 * textarea stays in the DOM and is kept in sync, so the form works with or
 * without JavaScript. The stored HTML is sanitised again on the server.
 *
 * Usage: render a textarea with data-controller="rich-text".
 */
export default class extends Controller {
    connect() {
        const textarea = this.element;
        if (textarea.dataset.richTextReady) {
            return;
        }
        textarea.dataset.richTextReady = '1';
        textarea.style.display = 'none';

        const wrapper = document.createElement('div');
        wrapper.className = 'rich-text border rounded';

        const toolbar = document.createElement('div');
        toolbar.className = 'rich-text-toolbar btn-toolbar gap-1 p-2 border-bottom';
        toolbar.setAttribute('role', 'toolbar');

        const commands = [
            { cmd: 'bold', label: 'B', title: 'Bold' },
            { cmd: 'italic', label: 'I', title: 'Italic' },
            { cmd: 'underline', label: 'U', title: 'Underline' },
            { cmd: 'insertUnorderedList', label: '• List', title: 'Bullet list' },
            { cmd: 'insertOrderedList', label: '1. List', title: 'Numbered list' },
            { cmd: 'formatBlock:H2', label: 'H2', title: 'Heading' },
            { cmd: 'formatBlock:H3', label: 'H3', title: 'Subheading' },
            { cmd: 'formatBlock:P', label: 'P', title: 'Paragraph' },
            { cmd: 'createLink', label: 'Link', title: 'Insert link' },
            { cmd: 'removeFormat', label: 'Clear', title: 'Clear formatting' },
        ];

        const editor = document.createElement('div');
        editor.className = 'rich-text-surface p-2';
        editor.contentEditable = 'true';
        editor.style.minHeight = '12rem';
        editor.innerHTML = textarea.value;

        const sync = () => { textarea.value = editor.innerHTML; };

        commands.forEach((c) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary';
            btn.textContent = c.label;
            btn.title = c.title;
            btn.addEventListener('click', () => {
                editor.focus();
                if (c.cmd === 'createLink') {
                    const url = window.prompt('Link URL (https://…)');
                    if (url) document.execCommand('createLink', false, url);
                } else if (c.cmd.startsWith('formatBlock:')) {
                    document.execCommand('formatBlock', false, c.cmd.split(':')[1]);
                } else {
                    document.execCommand(c.cmd, false, null);
                }
                sync();
            });
            toolbar.appendChild(btn);
        });

        editor.addEventListener('input', sync);
        editor.addEventListener('blur', sync);
        this.boundSync = sync;
        this.editor = editor;

        wrapper.appendChild(toolbar);
        wrapper.appendChild(editor);
        textarea.parentNode.insertBefore(wrapper, textarea.nextSibling);
        this.wrapper = wrapper;
    }

    disconnect() {
        if (this.boundSync) {
            this.boundSync();
        }
        if (this.wrapper) {
            this.wrapper.remove();
        }
        this.element.style.display = '';
        delete this.element.dataset.richTextReady;
    }
}
