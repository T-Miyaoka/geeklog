"""Backlog API client (https://www.happa.bz/admin/plugins/backlog/api.php).

  python backlog_api.py list [--category improvement|other|all] [--status 0,1] [--q TEXT] [--text] [--all-pages]
  python backlog_api.py get TICKET_ID
  python backlog_api.py submit REQUEST.json [--wait]        # private-inbox request (kind "ticket" or "status")
  python backlog_api.py status TICKET_ID STATUS EVENT_ID COMMENT [--wait]
  python backlog_api.py result QUEUED_NAME [--wait]

The token is read from --token-path or BACKLOG_API_TOKEN_PATH (never pass the token itself on the
command line). Output is JSON on stdout.
"""
import argparse, json, os, sys, time, urllib.error, urllib.parse, urllib.request

BASE = 'https://www.happa.bz/admin/plugins/backlog/api.php'


def call(token, method, params, body=None):
    url = BASE + '?' + urllib.parse.urlencode(params)
    data = None if body is None else body.encode('utf-8')
    request = urllib.request.Request(url, data=data, method=method, headers={
        'Authorization': 'Bearer ' + token, 'Accept': 'application/json',
        **({'Content-Type': 'application/json; charset=utf-8'} if data is not None else {})})
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return response.status, json.loads(response.read().decode('utf-8'))
    except urllib.error.HTTPError as error:
        text = error.read().decode('utf-8', 'replace')
        try:
            return error.code, json.loads(text)
        except ValueError:
            return error.code, {'error': text[:500]}


def wait_result(token, name, minutes):
    deadline = time.time() + minutes * 60
    while True:
        code, payload = call(token, 'GET', {'action': 'result', 'name': name})
        if payload.get('state') in ('done', 'failed') or time.time() > deadline:
            return code, payload
        time.sleep(20)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--token-path', default=os.environ.get('BACKLOG_API_TOKEN_PATH'))
    sub = parser.add_subparsers(dest='command', required=True)
    p = sub.add_parser('list'); p.add_argument('--category', default='all'); p.add_argument('--status', default='')
    p.add_argument('--q', default=''); p.add_argument('--text', action='store_true'); p.add_argument('--limit', type=int, default=500)
    p.add_argument('--all-pages', action='store_true')
    p = sub.add_parser('get'); p.add_argument('id')
    p = sub.add_parser('submit'); p.add_argument('file'); p.add_argument('--wait', action='store_true')
    p = sub.add_parser('status'); p.add_argument('id'); p.add_argument('status', type=int); p.add_argument('event_id'); p.add_argument('comment')
    p.add_argument('--wait', action='store_true')
    p = sub.add_parser('result'); p.add_argument('name'); p.add_argument('--wait', action='store_true')
    args = parser.parse_args()
    if not args.token_path:
        sys.exit('Pass --token-path or set BACKLOG_API_TOKEN_PATH.')
    with open(args.token_path, encoding='utf-8') as handle:
        token = handle.read().strip()

    if args.command == 'list':
        params = {'action': 'list', 'category': args.category, 'limit': args.limit, 'offset': 0}
        if args.status: params['status'] = args.status
        if args.q: params['q'] = args.q
        if args.text: params['include_text'] = '1'
        code, payload = call(token, 'GET', params)
        page = payload
        while args.all_pages and code == 200 and page['count'] and len(payload['tickets']) < payload['total']:
            params['offset'] += page['count']
            code, page = call(token, 'GET', params)
            if code != 200:
                payload = page
                break
            payload['tickets'] += page['tickets']
            payload['count'] = len(payload['tickets'])
    elif args.command == 'get':
        code, payload = call(token, 'GET', {'action': 'get', 'id': args.id})
    elif args.command in ('submit', 'status'):
        if args.command == 'submit':
            with open(args.file, encoding='utf-8-sig') as handle:
                body = handle.read()
        else:
            body = json.dumps({'schema_version': 1, 'kind': 'status', 'ticket_id': args.id, 'status': args.status,
                               'event_id': args.event_id, 'comment': args.comment}, ensure_ascii=False)
        code, payload = call(token, 'POST', {'action': 'submit'}, body)
        if args.wait and code in (200, 202):
            code, payload = wait_result(token, payload['queued'], 12)
    else:
        code, payload = (wait_result(token, args.name, 12) if args.wait
                         else call(token, 'GET', {'action': 'result', 'name': args.name}))
    json.dump(payload, sys.stdout, ensure_ascii=False, indent=2)
    print()
    sys.exit(0 if code < 400 and payload.get('state') != 'failed' else 1)


if __name__ == '__main__':
    main()
