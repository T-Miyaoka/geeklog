import importlib.util, pathlib, tempfile, unittest

spec = importlib.util.spec_from_file_location('history_import', pathlib.Path(__file__).parents[1] / 'import-work-history.py')
history = importlib.util.module_from_spec(spec)
spec.loader.exec_module(history)

class HistoryImportChecks(unittest.TestCase):
    def test_utf8_chunks_preserve_complete_record(self):
        original = '確認😀<a id="evidence"></a>\\save\n' * 20
        parts = history.chunks(original, 23)
        self.assertEqual(''.join(parts), original)
        self.assertTrue(all(len(p.encode()) <= 23 for p in parts))

    def test_generic_verification_belongs_to_previous_item(self):
        original = '# 履歴\n\n## ABC-001 項目\n本文\n\n## 検証\n成功\n\n## ABC-002 次\n次の本文\n'
        prefix, sections = history.split_sections(original)
        self.assertEqual(len(sections), 2)
        self.assertIn('## 検証\n成功', sections[0]['text'])
        self.assertEqual(prefix + ''.join(s['text'] for s in sections), original)

    def test_repeated_import_retains_item_identity_after_title_change(self):
        root = pathlib.Path(r'E:\Codex\CodexVerificationTemp\history-import-fixtures')
        root.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(dir=root) as name:
            fixture = pathlib.Path(name)
            self.assertTrue(fixture.resolve().is_relative_to(root.resolve()))
            repo, external, out = fixture/'repo', fixture/'history', fixture/'output'
            document = repo/'AshwindChronicle/Doc/spec/完了履歴.md'
            document.parent.mkdir(parents=True)
            document.write_text('# 履歴\n\n## ABC-001 名称\n原文<a id="x"></a>\n', encoding='utf-8')
            archive = external/'doc-work-history-archive-20261021/作業履歴'
            naming = archive/'命名規則/2026-08-29_命名規則完了履歴.md'
            naming.parent.mkdir(parents=True)
            naming.write_text('# 名称\n\n## NAM-001\n記録\n', encoding='utf-8')
            design = archive/'デザイン規則/整理前_完了履歴.md'
            design.parent.mkdir(parents=True)
            design.write_text('# 規則\n\n| ID | 日付 | 内容 | 結果 |\n|---|---|---|---|\n' + ''.join(f'| DSG-{i:03} | 2026-08-29 | 内容 | 成功 |\n' for i in range(1,5)), encoding='utf-8')
            first = history.build(repo, external, out)
            document.write_text('# 履歴\n\n## ABC-001 名称の修正\n原文<a id="x"></a>\n', encoding='utf-8')
            second = history.build(repo, external, out)
            a = next(r for r in first['records'] if r['ids'] == ['ABC-001'])
            b = next(r for r in second['records'] if r['ids'] == ['ABC-001'])
            self.assertEqual(a['source_id'], b['source_id'])
            self.assertEqual(a['ticket_id'], b['ticket_id'])
            document.write_text('# 案内\n\n作業履歴の正本は[Backlogのチケット]へ移行しました。\n', encoding='utf-8')
            with self.assertRaisesRegex(ValueError, 'already a ticket link'):
                history.build(repo, external, out)

if __name__ == '__main__':
    unittest.main()
