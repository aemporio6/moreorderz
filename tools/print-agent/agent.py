#!/usr/bin/env python3
"""
MoreOrderz print helper – runs on the packing PC and prints the jobs of printers set to "Print helper" silently (no print window).

Setup (once):
  1. In MoreOrderz: System > API connections > Add > "Print helper on the packing PC". Type a long random token. Save.
  2. On the PC: Python 3.8+. Windows also needs SumatraPDF (https://www.sumatrapdfreader.org) - set SUMATRA below if it is not on PATH.
  3. Copy settings.example.ini to settings.ini next to this file and fill in server and token.
  4. Run:  python agent.py      (Windows: put a shortcut in the Startup folder; macOS / Linux: a launchd / systemd service)

The helper asks the server every few seconds for the next job, prints the PDF on the printer named in the job ("Name on the computer"
of the printer in MoreOrderz) with the copies asked, and reports done / failed. It needs no open port on the PC.
"""
import base64, configparser, json, os, platform, subprocess, sys, tempfile, time, urllib.request, urllib.error

HERE = os.path.dirname(os.path.abspath(__file__))
cfg = configparser.ConfigParser(); cfg.read(os.path.join(HERE, 'settings.ini'))
SERVER = os.environ.get('MO_SERVER') or cfg.get('helper', 'server', fallback='').rstrip('/')
TOKEN = os.environ.get('MO_TOKEN') or cfg.get('helper', 'token', fallback='')
POLL = int(cfg.get('helper', 'poll_seconds', fallback='3'))
SUMATRA = cfg.get('helper', 'sumatra', fallback='SumatraPDF.exe')

def call(path, body=None):
    req = urllib.request.Request(SERVER + path, data=json.dumps(body).encode() if body is not None else None, method='POST' if body is not None else 'GET',
                                 headers={'X-Agent-Token': TOKEN, 'Accept': 'application/json', 'Content-Type': 'application/json'})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode() or '{}')

def print_pdf(path, printer, copies):
    system = platform.system()
    if system == 'Windows':
        for _ in range(max(1, copies)):
            subprocess.run([SUMATRA, '-print-to', printer, '-print-settings', 'noscale', '-silent', path], check=True, timeout=120)
    else:
        subprocess.run(['lp', '-d', printer, '-n', str(max(1, copies)), '-o', 'fit-to-page=false', path], check=True, timeout=120)

def main():
    if not SERVER or not TOKEN:
        sys.exit('Fill in server and token in settings.ini (see the top of this file).')
    print('MoreOrderz print helper started for', SERVER)
    while True:
        try:
            job = call('/print-agent/next').get('job')
            if not job:
                time.sleep(POLL); continue
            fd, path = tempfile.mkstemp(suffix='.pdf'); os.write(fd, base64.b64decode(job['pdf_base64'])); os.close(fd)
            try:
                print_pdf(path, job['printer'], job['copies'])
                call('/print-agent/%d/done' % job['job'], {'ok': True})
                print('printed', job['code'], 'on', job['printer'])
            except Exception as e:
                call('/print-agent/%d/done' % job['job'], {'ok': False, 'error': str(e)[:200]})
                print('FAILED', job['code'], e)
            finally:
                os.remove(path)
        except urllib.error.HTTPError as e:
            print('server said', e.code, '- check the token'); time.sleep(30)
        except Exception as e:
            print('waiting for the server:', e); time.sleep(15)

if __name__ == '__main__':
    main()
