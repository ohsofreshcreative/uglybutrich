#!/usr/bin/env python3
"""Lokalny, testowy serwer SMTP (Prompt 10.5) — WYŁĄCZNIE do testów transportu w kontenerze.

Nie przekazuje poczty dalej: zapisuje przyjęte wiadomości do pliku JSONL. Tryb zachowania serwera czytany
z pliku sterującego przy każdym połączeniu:
  ok                — przyjmuje wiadomość (250 OK queued as …)
  auth_fail         — 535 przy logowaniu
  reject_rcpt       — 550 5.1.1 przy RCPT (nieistniejący adres)
  temp_rcpt         — 451 przy RCPT (chwilowy błąd, przed treścią)
  timeout_after_data — przyjmuje treść, ale nie odpowiada po końcu DATA (wynik nieznany po stronie klienta)
  reject_after_data — 554 po końcu DATA (jawne odrzucenie treści)

Uruchomienie: fake_smtp.py <port> <plik_trybu> <plik_wiadomości>
"""
import base64
import json
import socket
import sys
import threading
import time

PORT = int(sys.argv[1])
MODE_FILE = sys.argv[2]
OUT_FILE = sys.argv[3]


def mode():
    try:
        with open(MODE_FILE) as f:
            return f.read().strip() or 'ok'
    except OSError:
        return 'ok'


def handle(conn):
    m = mode()
    f = conn.makefile('rb')
    send = lambda line: conn.sendall((line + '\r\n').encode())
    send('220 fake.ubrtest.local ESMTP test')
    mail_from, rcpt, user = None, [], None
    try:
        while True:
            raw = f.readline()
            if not raw:
                return
            line = raw.decode('utf-8', 'replace').rstrip('\r\n')
            cmd = line.split(' ', 1)[0].upper()
            if cmd in ('EHLO', 'HELO'):
                send('250-fake.ubrtest.local')
                send('250-AUTH PLAIN')
                send('250 8BITMIME')
            elif cmd == 'AUTH':
                parts = line.split(' ')
                if m == 'auth_fail':
                    send('535 5.7.8 Authentication credentials invalid')
                    continue
                cred = parts[2] if len(parts) >= 3 else None
                if cred is None:
                    send('334 ')
                    cred = f.readline().decode('utf-8', 'replace').strip()
                try:
                    user = base64.b64decode(cred).split(b'\x00')[1].decode()
                except Exception:
                    user = '?'
                send('235 2.7.0 Authentication successful')
            elif cmd == 'MAIL':
                mail_from = line[10:].strip('<> ')
                send('250 2.1.0 OK')
            elif cmd == 'RCPT':
                if m == 'reject_rcpt':
                    send('550 5.1.1 <' + line[8:].strip('<> ') + '>: Recipient address rejected: User unknown')
                    continue
                if m == 'temp_rcpt':
                    send('451 4.3.0 Temporary failure, try again later')
                    continue
                rcpt.append(line[8:].strip('<> '))
                send('250 2.1.5 OK')
            elif cmd == 'DATA':
                send('354 End data with <CR><LF>.<CR><LF>')
                data = []
                while True:
                    l = f.readline()
                    if not l or l in (b'.\r\n', b'.\n'):
                        break
                    data.append(l.decode('utf-8', 'replace'))
                if m == 'timeout_after_data':
                    time.sleep(8)
                    return
                if m == 'reject_after_data':
                    send('554 5.7.1 Message rejected (test)')
                    continue
                with open(OUT_FILE, 'a') as out:
                    out.write(json.dumps({'from': mail_from, 'to': rcpt, 'user': user, 'data': ''.join(data)}) + '\n')
                send('250 2.0.0 OK queued as TEST%d' % int(time.time() * 1000))
            elif cmd == 'RSET':
                mail_from, rcpt = None, []
                send('250 OK')
            elif cmd == 'QUIT':
                send('221 Bye')
                return
            else:
                send('502 Command not implemented')
    finally:
        try:
            conn.close()
        except OSError:
            pass


def main():
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind(('127.0.0.1', PORT))
    s.listen(16)
    while True:
        c, _ = s.accept()
        threading.Thread(target=handle, args=(c,), daemon=True).start()


if __name__ == '__main__':
    main()
