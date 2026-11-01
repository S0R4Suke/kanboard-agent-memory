#!/usr/bin/env python3
"""Agent-neutral JSON-RPC client; Python standard library only."""
import argparse
import base64
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request


class Client:
    def __init__(self, url, username, token):
        parsed = urllib.parse.urlsplit(url)
        if parsed.scheme not in ('http', 'https') or not parsed.hostname or parsed.username or parsed.password:
            raise ValueError('Use an http(s) URL without embedded credentials')
        if parsed.scheme != 'https' and parsed.hostname not in ('localhost', '127.0.0.1', '::1'):
            raise ValueError('Remote connections require HTTPS')
        self.url = url
        self.authorization = 'Basic ' + base64.b64encode(f'{username}:{token}'.encode()).decode()

    def call(self, method, **params):
        payload = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params}).encode()
        request = urllib.request.Request(self.url, data=payload, headers={'Authorization': self.authorization, 'Content-Type': 'application/json'})
        # Never forward credentials through an HTTP redirect.
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl):
                return None
        opener = urllib.request.build_opener(NoRedirect)
        try:
            with opener.open(request, timeout=20) as response:
                result = json.load(response)
        except urllib.error.HTTPError as error:
            raise RuntimeError(f'HTTP {error.code}; check endpoint and user credentials') from None
        if 'error' in result:
            raise RuntimeError(json.dumps(result['error'], ensure_ascii=False))
        return result['result']


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('method')
    parser.add_argument('params', nargs='?', default='{}', help='JSON object or @filename')
    args = parser.parse_args()
    raw = open(args.params[1:], encoding='utf-8').read() if args.params.startswith('@') else args.params
    params = json.loads(raw)
    client = Client(os.environ.get('KANBOARD_URL', 'http://127.0.0.1:8088/jsonrpc.php'), os.environ['KANBOARD_USER'], os.environ['KANBOARD_TOKEN'])
    print(json.dumps(client.call(args.method, **params), ensure_ascii=False, indent=2))


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, ValueError, KeyError, OSError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
