/* Independent forms in Shadow DOM never join Geeklog's surrounding settings form. */
(() => {
    const host = document.getElementById('xp-settings-panel');
    if (!host && location.hash === '#xpost') {
        const group = document.forms.namedItem('group');
        if (group && group.elements.namedItem('conf_group')) {
            history.replaceState(null, '', location.pathname + location.search);
            group.elements.namedItem('conf_group').value = 'xpost'; group.submit();
        }
        return;
    }
    if (!host || host.shadowRoot) return;
    const root = host.attachShadow({mode: 'open'});
    const nativeSave = document.getElementById('save_changes');
    if (nativeSave) host.style.setProperty('--xp-native-button-font', getComputedStyle(nativeSave).font);
    host.textContent = '';
    const url = new URL(host.dataset.settingsUrl, location.href);
    if (url.origin !== location.origin) return;
    async function load(options) {
        try {
            // Geeklog binds each one-time token to the settings document URL.
            const response = await fetch(url.href, {...options, credentials: 'same-origin', cache: 'no-store', referrer: url.href});
            if (!response.ok) throw new Error('request');
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const shell = doc.querySelector('main.xp-shell');
            if (!shell) throw new Error('login');
            // Reuse the active Geeklog theme, not the standalone posting-page design.
            const styles = Array.from(document.querySelectorAll('link[rel="stylesheet"]'), link => link.cloneNode());
            const native = document.createElement('link');
            native.rel = 'stylesheet'; native.href = new URL('settings-native.css?v=actions-20261021', url).href;
            shell.querySelector('.xp-panel > h2')?.remove();
            shell.querySelectorAll('label[for]').forEach(label => {
                const input = shell.querySelector('#' + label.htmlFor);
                if (!input) return;
                const row = document.createElement('div'); row.className = 'config_name';
                label.before(row); label.classList.add('config_label');
                input.classList.add('uk-input', 'opt');
                if (!input.hasAttribute('type')) input.type = 'text';
                row.append(label, input);
            });
            const bar = document.createElement('div'); bar.className = 'xp-config-buttons';
            shell.querySelectorAll('button').forEach((button, index) => {
                const form = button.closest('form'); form.id = 'xp-config-form-' + index;
                button.className = 'uk-button ' + (form.querySelector('[name="action"]').value === 'settings' ? 'uk-button-primary' : 'uk-button-default');
                button.setAttribute('form', form.id);
                bar.append(button);
            });
            shell.querySelector('#settings').append(bar);
            root.replaceChildren(...styles, native, shell);
            root.querySelectorAll('form').forEach(form => form.addEventListener('submit', async event => {
                event.preventDefault(); event.stopPropagation();
                if (form.dataset.sending) return;
                form.dataset.sending = '1';
                const data = new FormData(form);
                root.querySelectorAll('button').forEach(button => { button.disabled = true; });
                await load({method: 'POST', body: data});
            }));
        } catch (_) {
            root.replaceChildren();
            const error = document.createElement('p');
            error.setAttribute('role', 'alert');
            error.textContent = 'API設定を取得できません。ログイン状態を確認してページを再読み込みしてください。保存・接続確認の結果が不明な場合は、自動再送しません。';
            root.append(error);
        }
    }
    load();
})();
