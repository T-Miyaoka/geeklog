(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var title = document.getElementById('downloads-filetitle');
    if (!title || !title.form) return;
    var form = title.form, cat = form.querySelector('select[name="cid"]');
    if (!cat) return;
    form.classList.add('dlm-registration');
    var ja = document.documentElement.lang.indexOf('ja') === 0;
    function t(j, e) { return ja ? j : e; }
    function el(tag, text, cls) { var n = document.createElement(tag); if (text) n.textContent = text; if (cls) n.className = cls; return n; }
    function field(name) { return form.elements.namedItem(name); }
    function value(name) { var n = field(name); return n && typeof n.value === 'string' ? n.value : ''; }
    var banner = el('section', '', 'dlm-registration-destination');
    var destination = el('p'); banner.appendChild(destination);
    banner.appendChild(el('p', t('カテゴリを選び、公開カードの見え方を確認してから登録してください。プレビューでは保存・アップロードしません。', 'Choose a category and check the public card before saving. Preview does not save or upload files.')));
    var categoryLink = el('a', t('このカテゴリの公開ページを見る', 'View this public category'));
    banner.appendChild(categoryLink); form.insertBefore(banner, form.firstChild);
    function categoryName() { return cat.selectedOptions.length ? cat.selectedOptions[0].textContent.trim() : ''; }
    function updateDestination() {
      destination.textContent = t('登録先カテゴリ：', 'Destination category: ') + categoryName();
      categoryLink.href = '/downloads/index.php?cid=' + encodeURIComponent(cat.value);
    }
    cat.addEventListener('change', updateDestination); updateDestination();
    var hints = {
      title: t('公開ページのカードの見出しになります。', 'Heading on the public card.'),
      cid: t('このカテゴリのダウンロード一覧へ掲載します。', 'Lists this file in the selected download category.'),
      newfile: t('ダウンロードボタンで取得するファイルです。選択だけではアップロードされません。', 'File delivered by the download button. Selecting it does not upload it.'),
      version: t('公開ページのカードに大きな数字で表示します。必ず入力してください。', 'Shown as a large number on the public card. Required.'),
      text_description: t('このビルドで対応したことを、1 行に 1 項目で書きます。最新のビルドでは全項目を箇条書きで、過去のバージョンの一覧では 1 行目だけを表示します。', 'Write what this build changes, one item per line. The latest build lists every item; earlier versions show only the first line.'),
      lid: t('内部管理用の識別子です。通常は自動生成値を変更しません。', 'Internal identifier. Normally leave the generated value unchanged.'),
      tags: t('分類・検索の補助情報です。公開カードの見出しにはなりません。', 'Supporting classification/search metadata, not the card heading.'),
      is_released: t('公開の可否を指定します。公開日時・閲覧権限の条件も適用されます。', 'Controls release status. Release date and viewing permissions also apply.'),
      is_listing: t('公開一覧に掲載するかを指定します。', 'Controls whether this file appears in public listings.')
    };
    Object.keys(hints).forEach(function (name) {
      var input = field(name), dd = input && input.closest('dd');
      if (!dd) return;
      var hint = el('p', hints[name], 'dlm-registration-hint'); hint.id = 'dlm-hint-' + name;
      dd.appendChild(hint); input.setAttribute('aria-describedby', hint.id);
    });
    var placeholders = {
      title: t('例：Ashwind Chronicle Windows 開発ビルド', 'Example: Ashwind Chronicle Windows development build'),
      version: t('例：0.9.8', 'Example: 0.9.8'),
      text_description: t('例：\n国家概況の窓に飾り枠を付けた\n物資の需給に足りない区分と充足率を表示した', 'Example:\nAdded an ornamental frame to the country overview\nListed short supplies with their fill rates')
    };
    Object.keys(placeholders).forEach(function (name) { var input = field(name); if(input) input.placeholder = placeholders[name]; });
    ['text_description'].forEach(function(name){var input=field(name);if(input)input.rows=6;});
    var versionInput = field('version'); if (versionInput) versionInput.required = true;
    [['text_description',t('このビルドで対応したこと','What this build changes')]].forEach(function(pair){var input=field(pair[0]), dd=input&&input.closest('dd'), dt=dd&&dd.previousElementSibling;if(dt&&dt.tagName==='DT')dt.textContent=pair[1];});
    // The public page has no detail page and no card image: the detail text and
    // the image stay in the form (hidden) so saved values are not lost.
    var keep = ['title', 'cid', 'newfile', 'version', 'text_description'];
    form.querySelectorAll('.admin_basic > dl.form_block > dd').forEach(function (dd) {
      if (!keep.some(function(name) { var input=field(name); return input && dd.contains(input); })) {
        dd.classList.add('dlm-registration-auto');
        if(dd.previousElementSibling && dd.previousElementSibling.tagName==='DT') dd.previousElementSibling.classList.add('dlm-registration-auto');
      }
    });
    // Keep values enabled and in the form: existing IDs, dates and settings must
    // survive edits/retries rather than being reset by missing POST parameters.
    var logourl=field('logourl'); if(logourl)logourl.classList.add('dlm-registration-auto');
    var imageWrapper=form.querySelector('.dlm_shot_wrapper'); if(imageWrapper)imageWrapper.classList.add('dlm-registration-auto');
    var preview = el('section', '', 'dlm-registration-preview'); preview.hidden = true;
    preview.appendChild(el('h3', t('「最新のビルド」のプレビュー（未保存）', 'Latest build preview (not saved)')));
    preview.appendChild(el('p', t('公開日がいちばん新しいファイルは、公開ページでこの形で表示します。それより古いファイルは「過去のバージョン」に 1 行で並びます。HTMLの装飾は簡略表示し、公開日時・権限による表示可否は保存時の設定に従います。', 'The file with the newest release date appears like this on the public page; older files are listed as earlier versions. HTML formatting is simplified; release date and permissions still determine visibility.'), 'dlm-registration-hint'));
    var cardHost = el('div', '', 'happa-downloads happa-downloads-page happa-dl');
    var card = el('article', '', 'happa-dl-latest'); cardHost.appendChild(card); preview.appendChild(cardHost);
    var notice = el('p'); preview.appendChild(notice);
    banner.appendChild(preview);
    function editorText(name) {
      var mode = value('postmode'), raw = value('text_' + name);
      if (mode === 'adveditor') {
        var id = 'html_' + name;
        raw = window.CKEDITOR && window.CKEDITOR.instances[id] ? window.CKEDITOR.instances[id].getData() : value(id);
      }
      // Never execute supplied markup or load its remote resources in a preview.
      if (mode !== 'plaintext') raw = new DOMParser().parseFromString(raw, 'text/html').body.textContent || '';
      return raw;
    }
    var svgNs = 'http://www.w3.org/2000/svg';
    function downloadIcon() {
      var svg = document.createElementNS(svgNs, 'svg'), path = document.createElementNS(svgNs, 'path');
      svg.setAttribute('width', '16'); svg.setAttribute('height', '16'); svg.setAttribute('viewBox', '0 0 16 16');
      svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '1.6'); svg.setAttribute('aria-hidden', 'true');
      path.setAttribute('d', 'M8 2v9M4 7.5 8 11.5 12 7.5M2.5 14h11'); svg.appendChild(path); return svg;
    }
    // Same splitting as the public page: one item per line, leading bullets removed.
    function changeItems(text) {
      return text.split(/\r\n|\r|\n/).map(function (line) {
        return line.replace(/^[\s\u3000]*(?:[-*\u30fb\u2022\u25cf\u25c6\u25a0]|\d+[.)])[\s\u3000]*/, '').trim();
      }).filter(function (line) { return line !== ''; });
    }
    function render() {
      updateDestination(); card.replaceChildren();
      var plate = el('div', '', 'happa-dl-plate happa-dl-plate--large');
      plate.appendChild(el('span', t('最新ビルド', 'Latest build'), 'happa-dl-plate__label'));
      plate.appendChild(el('span', value('version') || '—', 'happa-dl-plate__version'));
      plate.appendChild(el('span', '', 'happa-dl-plate__rule'));
      plate.appendChild(el('span', 'Windows PC', 'happa-dl-plate__os'));
      card.appendChild(plate);
      var body = el('div', '', 'happa-dl-latest__body'); card.appendChild(body);
      body.appendChild(el('h3', title.value || t('ダウンロードのタイトル', 'Download title')));
      var changes = el('section', '', 'happa-dl-changes');
      changes.appendChild(el('h4', t('このビルドで対応したこと', 'What this build changes')));
      var items = changeItems(editorText('description'));
      if (items.length) {
        var list = el('ul', '', 'happa-dl-changes__list');
        items.forEach(function (item) { list.appendChild(el('li', item)); });
        changes.appendChild(list);
      } else {
        changes.appendChild(el('p', t('対応したことを入力すると、ここに箇条書きで表示します。', 'Items you enter appear here as a list.'), 'dlm-registration-hint'));
      }
      body.appendChild(changes);
      var file = field('newfile'), selected = file && file.files && file.files[0];
      var size=selected ? (selected.size<1048576 ? selected.size.toLocaleString()+' bytes' : (selected.size/1048576).toFixed(2)+' MB') : value('size') || t('保存時に確定','determined on save');
      var stamp=['year','month','day'].map(function(part){return value('release_'+part);}).join('/');
      var action = el('div', '', 'happa-dl-latest__action');
      var button = el('a', '', 'happa-dl-button happa-dl-button--primary'); button.setAttribute('aria-disabled', 'true');
      button.appendChild(downloadIcon()); button.appendChild(document.createTextNode(t('ダウンロード', 'Download')));
      action.appendChild(button);
      action.appendChild(el('span', size + ' ・ ' + (ja ? stamp + ' 公開' : 'Released ' + stamp), 'happa-dl-meta'));
      body.appendChild(action);
      function yesNo(name) { return value(name) === '1' ? t('はい', 'Yes') : t('いいえ', 'No'); }
      notice.textContent = t('登録ファイル：', 'Selected file: ') + (selected ? selected.name : value('url') || t('未選択', 'none')) + ' / ' + t('公開：', 'Released: ') + yesNo('is_released') + ' / ' + t('一覧掲載：', 'Listed: ') + yesNo('is_listing');
      preview.hidden = false;
    }
    function previewButton() {
      var button = el('button', t('公開カードをプレビュー（保存しません）', 'Preview public card (without saving)'), 'dlm-registration-preview-button');
      button.type = 'button'; button.addEventListener('click', function () { render(); preview.scrollIntoView({block:'start'}); }); return button;
    }
    banner.insertBefore(previewButton(), preview);
    var submit = form.querySelector('.submit'); if (submit) submit.insertBefore(previewButton(), submit.firstChild);
    form.addEventListener('input', function () { if (!preview.hidden) render(); });
    form.addEventListener('change', function () { if (!preview.hidden) render(); });
  });
})();
