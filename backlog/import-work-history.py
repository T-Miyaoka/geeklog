"""Prepare loss-checked, idempotent private-inbox requests from existing work records."""
import argparse, collections, hashlib, json, re, uuid
from pathlib import Path

NAMESPACE = uuid.UUID('85997f9b-40a8-431c-b572-c8ea3db44768')
SECRET = re.compile(r'(?:xox[baprs]-[A-Za-z0-9-]{20,}|sk-[A-Za-z0-9_-]{30,}|-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----)')

def split_sections(text):
    parts = re.split(r'(?m)(?=^## )', text)
    prefix, sections = parts[0], []
    for part in parts[1:]:
        title = part.splitlines()[0][3:].strip()
        if title in {'検証', '確認', '検証結果'} and sections:
            sections[-1]['text'] += part
        else:
            sections.append({'title': title, 'text': part})
    assert prefix + ''.join(item['text'] for item in sections) == text
    return prefix, sections

def chunks(value, limit):
    if limit < 4:
        raise ValueError('The UTF-8 chunk limit must fit a complete code point.')
    raw = value.encode('utf-8')
    result = []
    while len(raw) > limit:
        boundary = limit
        while raw[boundary] & 0xc0 == 0x80:
            boundary -= 1
        result.append(raw[:boundary].decode('utf-8'))
        raw = raw[boundary:]
    result.append(raw.decode('utf-8'))
    assert ''.join(result) == value
    return result

def item_ids(title):
    found = re.findall(r'\b[A-Z]{2,5}-\d{3}\b', title)
    for prefix, start, end in re.findall(r'\b([A-Z]{2,5})-(\d{3})[～〜~](\d{3})\b', title):
        if int(end) - int(start) < 20:
            found.extend(f'{prefix}-{n:03}' for n in range(int(start), int(end) + 1))
    return list(dict.fromkeys(found))

def overview(value):
    for line in value.splitlines():
        line = line.strip()
        if line and not line.startswith(('#', '<a ', '|', '<!--')):
            return (line[:89] + '…') if len(line) > 90 else line
    return '移管した作業記録です。実施内容と確認結果は詳細を参照してください。'

def ticket_id(source_id):
    h = hashlib.md5(('codex-task:' + source_id).encode()).hexdigest()
    return f'{h[:8]}-{h[8:12]}-{h[12:16]}-{h[16:20]}-{h[20:28]}'

def build(repo, external, output):
    output.mkdir(parents=True, exist_ok=True)
    request_dir = output / 'requests'
    request_dir.mkdir(exist_ok=True)
    sources, records, attached = {}, [], set()
    prior_ids = {}
    if (output / 'manifest.json').is_file():
        previous = json.loads((output / 'manifest.json').read_text(encoding='utf-8'))
        for item in previous['records']:
            key = (item['source_path'], tuple(sorted(item['ids'])) if item['ids'] else item['title'])
            prior_ids[key] = item['source_id']

    def read(path):
        path = path.resolve()
        raw = path.read_bytes()
        text = raw.decode('utf-8-sig').replace('\r\n', '\n').replace('\r', '\n')
        if '作業履歴の正本は[Backlogのチケット]' in text:
            raise ValueError(f'Source is already a ticket link; do not reimport its stub: {path}')
        if SECRET.search(text):
            raise ValueError(f'Possible credential material; source held: {path}')
        sources[str(path)] = {'sha256': hashlib.sha256(raw).hexdigest(), 'bytes': len(raw), 'normalized_sha256': hashlib.sha256(text.encode()).hexdigest()}
        return text

    def add(path, title, body, category, prefix=''):
        ids = item_ids(title)
        identity = str(path.relative_to(repo)) if path.is_relative_to(repo) else str(path.relative_to(external))
        item_key = ','.join(ids) if ids else title
        prior_key = (str(path), tuple(sorted(ids)) if ids else title)
        source_id = prior_ids.get(prior_key, str(uuid.uuid5(NAMESPACE, identity.replace('\\', '/') + '#' + item_key)))
        record = {'source_path': str(path), 'source_id': source_id, 'ticket_id': ticket_id(source_id), 'title': title,
                  'category': category, 'ids': ids, 'original_section': body, 'prefix': prefix, 'attachments': []}
        # Linked work-history text becomes part of its corresponding item, rather than a duplicate ticket.
        for target in re.findall(r'\]\(<?([^)>]+)>?\)', body):
            target = target.split('#', 1)[0].replace('/', '\\')
            if re.match(r'^[A-Za-z]:\\', target):
                candidate = Path(target)
                if candidate.suffix.lower() == '.md' and candidate.is_relative_to(external) and candidate.is_file():
                    record['attachments'].append({'path': str(candidate), 'text': read(candidate)})
                    attached.add(candidate.resolve())
        records.append(record)

    for path in sorted((repo / 'AshwindChronicle/Doc').rglob('完了履歴.md')):
        text = read(path)
        prefix, sections = split_sections(text)
        for item in sections:
            category = 'other' if 'チェックイン後の完了履歴復元' in item['title'] else 'improvement'
            add(path, item['title'], item['text'], category, prefix)

    archive = external / 'doc-work-history-archive-20261021/作業履歴'
    naming = archive / '命名規則/2026-08-29_命名規則完了履歴.md'
    prefix, sections = split_sections(read(naming))
    names = {'NAM-001': '四文明の名称辞典', 'NAM-002': '類似名称の診断', 'NAM-004': '名称変更の参照差分'}
    for item in sections:
        ids = item_ids(item['title'])
        title = item['title'] + (' ' + names[ids[0]] if ids and ids[0] in names else '')
        add(naming, title, item['text'], 'improvement', prefix)
    attached.add(naming.resolve())

    design = archive / 'デザイン規則/整理前_完了履歴.md'
    text = read(design)
    rows = text.splitlines(keepends=True)
    header = ''.join(rows[:4])
    names = {'DSG-001': '画像規則の共通検証', 'DSG-002': '画像規則の例外台帳', 'DSG-003': '正式画像の制作証跡', 'DSG-004': '画像の縮小・色覚比較'}
    selected = []
    for line in rows[4:]:
        match = re.match(r'\|\s*(DSG-\d{3})\s*\|', line)
        if match:
            selected.append(line)
            add(design, match[1] + ' ' + names[match[1]], header + line, 'improvement')
        elif line.strip():
            raise ValueError('Unrecognized design-history content; refusing partial migration.')
    assert len(selected) == 4
    attached.add(design.resolve())

    for path in sorted(archive.rglob('*.md')):
        if path.resolve() in attached or not re.match(r'\d{4}-\d{2}-\d{2}_', path.name):
            continue
        text = read(path)
        title = next((line[2:].strip() for line in text.splitlines() if line.startswith('# ')), path.stem)
        add(path, title, text, 'improvement' if item_ids(title) else 'other')

    manifest = {'schema_version': 1, 'sources': sources, 'records': [], 'requests': []}
    for record in records:
        body = record.pop('original_section')
        prefix = record.pop('prefix')
        intro = f"【元記録】\n{record['source_path']}\n原本SHA-256: {sources[record['source_path']]['sha256']}\n"
        if record['ids']:
            intro += '改善項目ID: ' + '、'.join(record['ids']) + '\n'
        content = intro + ('\n【文書共通事項】\n' + prefix if prefix.strip() else '') + '\n【実施内容・検証記録（原文）】\n' + body
        for attachment in record.pop('attachments'):
            content += '\n\n【関連する作業記録】\n' + attachment['path'] + '\n' + attachment['text']
        # Do not turn candidate acceptance into final acceptance during migration.
        status = 2 if '最終工程' in body and '候補受入' in body else 4
        first = chunks(content, 44000)
        primary, remainder = first[0], ''.join(first[1:])
        description = '【概要】\n' + overview(body) + '\n\n【詳細】\n' + primary
        comments = chunks(remainder, 14500) if remainder else []
        assert primary + ''.join(comments) == content
        requests = []
        for index, comment in enumerate(['元記録を内容照合の対象としてチケットへ移管しました。実施日・検証結果・未確認事項は原文の記載を維持しています。'] + comments):
            request = {'schema_version': 1, 'source_id': record['source_id'], 'title': record['title'], 'text': description,
                       'status': status, 'category': record['category'], 'event_id': f"history-import-{index:03}",
                       'comment': comment if index == 0 else f'【詳細の続き {index}/{len(comments)}】\n' + comment}
            assert len(request['title'].encode()) <= 255 and len(request['text'].encode()) <= 50000 and len(request['comment'].encode()) <= 16000
            filename = f"history-{record['ticket_id']}-{index:03}.json"
            encoded = json.dumps(request, ensure_ascii=False, indent=2).encode()
            assert len(encoded) <= 65536
            (request_dir / filename).write_bytes(encoded)
            receipt = {'file': filename, 'source_id': record['source_id'], 'ticket_id': record['ticket_id'],
                       'category': record['category'], 'status': status, 'event_id': request['event_id'],
                       'request_sha256': hashlib.sha256(encoded).hexdigest(),
                       'ticket_text_sha256': hashlib.sha256(('Codex作業ID: ' + record['source_id'] + '\n' + description).encode()).hexdigest()}
            manifest['requests'].append(receipt)
            requests.append(filename)
        record.update({'status': status, 'ticket_url': 'https://www.happa.bz/admin/plugins/backlog/index.php?mode=comment&id=' + record['ticket_id'],
                       'content_sha256': hashlib.sha256(content.encode()).hexdigest(), 'content_bytes': len(content.encode()), 'requests': requests})
        manifest['records'].append(record)
    assert len({r['ticket_id'] for r in records}) == len(records)
    (output / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding='utf-8')
    return manifest

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--repo', type=Path, required=True)
    parser.add_argument('--history', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if not str(args.output.absolute()).lower().startswith('e:\\codex\\'):
        raise ValueError('Migration work files must be under E:\\Codex')
    manifest = build(args.repo, args.history, args.output)
    print(json.dumps({'sources': len(manifest['sources']), 'tickets': len(manifest['records']), 'requests': len(manifest['requests']),
                      'categories': dict(collections.Counter(r['category'] for r in manifest['records'])),
                      'approval_pending': [r['title'] for r in manifest['records'] if r['status'] == 2]}, ensure_ascii=False))
