"""Review helper for the review player: keeps the station's model loaded so "Which bird was it?" answers in about a
second instead of loading the model for every clip, and redraws a clip's spectrogram with the palette, floor, range
and contrast chosen in the player.

Listens on 127.0.0.1 only (scripts/play.php starts it on demand as the station user and talks to it); it exits by
itself after IDLE_SECONDS without a request, so it costs nothing when nobody is reviewing.
  GET /ping
  GET /alternatives?clip=<path>&sci=<detected scientific name>&n=<count>   -> JSON list (clip_alternatives.py)
  GET /spectro?clip=<path>&palette=&floor=&range=&contrast=                -> PNG, no title
Usage: review_worker.py [port]
"""
import json
import os
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8099
IDLE_SECONDS = 900
BY_DATE = os.path.realpath(os.path.expanduser('~/BirdSongs/Extracted'))
last_request = time.time()
model_lock = threading.Lock()
model = None


def get_loaded_model():
    global model
    if model is None:
        from utils.models import get_model
        model = get_model()
    return model


def clip_path(q):
    clip = os.path.realpath(q.get('clip', [''])[0])
    if not clip.startswith(BY_DATE + os.sep) or not os.path.isfile(clip):
        raise ValueError('bad clip')
    return clip


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def send(self, code, body, ctype):
        self.send_response(code)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        global last_request
        last_request = time.time()
        url = urlparse(self.path)
        q = parse_qs(url.query)
        try:
            if url.path == '/ping':
                self.send(200, b'pong', 'text/plain')
            elif url.path == '/alternatives':
                from clip_alternatives import alternatives
                with model_lock:
                    out = alternatives(clip_path(q), q.get('sci', [''])[0], int(q.get('n', ['6'])[0]), get_loaded_model())
                self.send(200, json.dumps(out, ensure_ascii=False).encode(), 'application/json')
            elif url.path == '/spectro':
                from spectrogram_png import render
                opts = {k: q[k][0] for k in ('palette', 'floor', 'range', 'contrast') if k in q}
                with tempfile.TemporaryDirectory() as tmp:
                    out = os.path.join(tmp, 'clip.png')
                    render(clip_path(q), out, '', opts=opts)
                    with open(out, 'rb') as f:
                        self.send(200, f.read(), 'image/png')
            else:
                self.send(404, b'not found', 'text/plain')
        except Exception as e:
            self.send(400, str(e).encode(), 'text/plain')


def idle_watch(server):
    while time.time() - last_request < IDLE_SECONDS:
        time.sleep(30)
    server.shutdown()


if __name__ == '__main__':
    server = ThreadingHTTPServer(('127.0.0.1', PORT), Handler)
    threading.Thread(target=idle_watch, args=(server,), daemon=True).start()
    # load the model at once: the first "Which bird was it?" is the one the reviewer waits for
    def preload():
        with model_lock:
            get_loaded_model()
    threading.Thread(target=preload, daemon=True).start()
    server.serve_forever()
