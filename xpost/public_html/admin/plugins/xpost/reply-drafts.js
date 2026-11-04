// X-Post reply drafts: weight counter and an emoji list opened by typing "@" (or "＠") in a draft.
// Choosing an emoji replaces the "@" (and any filter text typed after it); keep typing a name,
// press Esc or leave the field to keep "@" as a normal mention.
(function () {
    'use strict';
    var EMOJI = [
        ['🙏', 'ありがとう 感謝 お願い thanks'], ['😊', 'うれしい 笑顔 smile'], ['😄', 'わらい 笑 happy'], ['😆', 'わらい 楽しい laugh'],
        ['🥰', 'うれしい 好き love'], ['😍', 'すてき 好き love'], ['🤔', 'かんがえる 考え 悩む think'], ['😅', 'あせ 苦笑 sweat'],
        ['😭', 'なく 涙 感動 cry'], ['😮', 'おどろき 驚き wow'], ['🙌', 'ばんざい やった hooray'], ['👏', 'はくしゅ 拍手 clap'],
        ['👍', 'いいね 了解 good ok'], ['👀', 'みる 注目 look'], ['💪', 'がんばる 頑張 strong'], ['🎉', 'おめでとう 祝 party'],
        ['✨', 'きらきら 新しい sparkle'], ['🔥', 'あつい 熱 fire'], ['💡', 'アイデア ひらめき idea'], ['📊', 'グラフ 数値 統計 chart'],
        ['📈', 'のびる 上昇 増加 up'], ['📉', 'さがる 下落 減少 down'], ['🗺', 'ちず 地図 map'], ['🏰', 'しろ 城 国 castle'],
        ['⚔', 'たたかい 戦争 戦闘 battle'], ['🛡', 'まもり 防衛 防御 shield'], ['🌾', 'むぎ 小麦 収穫 農業 wheat'], ['🏭', 'こうじょう 工場 生産 factory'],
        ['💰', 'おかね お金 予算 財政 money'], ['🏦', 'ぎんこう 銀行 bank'], ['📦', 'にもつ 物資 在庫 box'], ['⚖', 'てんびん 需給 バランス balance'],
        ['🛠', 'こうぐ 工具 改善 修正 tools'], ['🧪', 'じっけん 実験 研究 test'], ['📜', 'まきもの 巻物 記録 歴史 scroll'], ['📝', 'メモ 記録 note'],
        ['🎮', 'ゲーム 遊ぶ game'], ['🧭', 'らしんばん 羅針盤 方針 compass'], ['⏳', 'まつ 待つ 時間 wait'], ['📅', 'こよみ 暦 日付 calendar'],
        ['☀', 'はれ 晴れ 天気 sun'], ['🌧', 'あめ 雨 天気 rain'], ['❄', 'ゆき 雪 冬 snow'], ['🙇', 'おじぎ すみません sorry']
    ];
    function weight(text) {
        // Mirrors Text::weight: URLs count 23; U+0000-10FF, U+2000-200D, U+2010-201F, U+2032-2037 count 1; others 2.
        var t = text.replace(/https?:\/\/[a-zA-Z0-9.\/?&=%_#:+@!,$;*()\-]+/g, new Array(24).join('a')), w = 0;
        for (var ch of t) {
            var c = ch.codePointAt(0);
            w += (c <= 0x10FF || (c >= 0x2000 && c <= 0x200D) || (c >= 0x2010 && c <= 0x201F) || (c >= 0x2032 && c <= 0x2037)) ? 1 : 2;
        }
        return w;
    }
    function counter(area) {
        var out = document.querySelector('.xp-weight[data-for="' + area.id + '"]');
        if (!out) { return; }
        var w = weight(area.value);
        out.textContent = '文字数: ' + w + ' / 280' + (w > 280 ? '（超えています）' : '');
        out.style.color = w > 280 ? '#a3331f' : '';
    }
    var list = null, active = null, anchor = -1, index = 0, matches = [];
    function close() { if (list) { list.remove(); } list = null; active = null; anchor = -1; matches = []; }
    function query() { return active.value.slice(anchor + 1, active.selectionStart); }
    function choose(i) {
        if (!active || !matches[i]) { return; }
        var area = active, before = area.value.slice(0, anchor), after = area.value.slice(area.selectionStart), emoji = matches[i][0];
        area.value = before + emoji + after;
        var pos = before.length + emoji.length;
        close(); area.focus(); area.setSelectionRange(pos, pos); counter(area);
    }
    function render() {
        var q = query().toLowerCase();
        if (/\s/.test(q)) { close(); return; }
        matches = EMOJI.filter(function (e) { return q === '' || e[1].toLowerCase().indexOf(q) !== -1; });
        if (!matches.length) { close(); return; }    // no emoji matches: treat "@..." as a normal mention
        if (index >= matches.length) { index = 0; }
        if (!list) {
            list = document.createElement('div'); list.className = 'xp-emoji-list'; list.setAttribute('role', 'listbox');
            active.parentNode.insertBefore(list, active.nextSibling);
        }
        list.innerHTML = '';
        matches.forEach(function (e, i) {
            var b = document.createElement('button');
            b.type = 'button'; b.textContent = e[0]; b.title = e[1]; b.setAttribute('role', 'option');
            if (i === index) { b.className = 'xp-emoji-active'; b.setAttribute('aria-selected', 'true'); }
            b.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(i); });
            list.appendChild(b);
        });
    }
    document.addEventListener('input', function (ev) {
        var area = ev.target;
        if (!area.classList || !area.classList.contains('xp-reply-editor')) { return; }
        counter(area);
        var pos = area.selectionStart;
        // "@" or the full-width "＠" typed through a Japanese IME both open the list.
        var typed = typeof ev.data === 'string' ? ev.data.slice(-1) : '', before = area.value.charAt(pos - 1);
        if ((typed === '@' || typed === '＠') && before === typed) { close(); active = area; anchor = pos - 1; index = 0; render(); return; }
        if (active === area) { if (pos <= anchor) { close(); } else { render(); } }
    });
    document.addEventListener('keydown', function (ev) {
        if (!list || ev.target !== active) { return; }
        if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') { index = (index + 1) % matches.length; render(); ev.preventDefault(); }
        else if (ev.key === 'ArrowLeft' || ev.key === 'ArrowUp') { index = (index - 1 + matches.length) % matches.length; render(); ev.preventDefault(); }
        else if (ev.key === 'Enter' || ev.key === 'Tab') { choose(index); ev.preventDefault(); }
        else if (ev.key === 'Escape') { close(); ev.preventDefault(); }
    });
    document.addEventListener('focusout', function (ev) { if (ev.target === active) { close(); } });
    function init() { Array.prototype.forEach.call(document.querySelectorAll('.xp-reply-editor'), counter); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
