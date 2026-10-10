# Klucz SSH śledzony w repozytorium — plan rotacji i usunięcia

**Stan (sprawdzone wyłącznie metadane, 10.10.2026):** w katalogu motywu są pliki `key` (464 B, uprawnienia 600) i `key.pub` (102 B), śledzone w Git od commita `9b24093` („Initial commit”, 2026-10-09), wypchniętego do `origin/main` (`github.com/ohsofreshcreative/uglybutrich`). Pliki nie były ignorowane. Zawartości nie odczytywano, klucza nie używano ani nie kopiowano; jest wykluczony z paczek wdrożeniowych.

Rozmiar sugeruje (prawdopodobnie) klucz Ed25519 w formacie OpenSSH — należy go traktować jako **ujawniony** (każdy z dostępem do repozytorium lub jego kopii ma klucz prywatny).

## Plan (każdy krok wymaga decyzji właściciela)
1. **Ustal, gdzie klucz jest używany** (bez unieważniania w ciemno): porównaj odcisk `ssh-keygen -lf key.pub` (lokalnie, u właściciela) z: GitHub → Settings → SSH keys i Deploy keys repozytoriów, `~/.ssh/authorized_keys` na serwerach (hosting, VPS, staging), panele hostingu (SFTP/SSH), CI/CD (sekrety GitHub Actions).
2. **Wygeneruj nowy klucz poza repozytorium** (`ssh-keygen -t ed25519 -C "deploy uglybutrich"` w `~/.ssh/`), dodaj go we wszystkich miejscach z kroku 1, sprawdź logowanie nowym kluczem.
3. **Unieważnij stary klucz** (usuń publiczną część ze wszystkich miejsc z kroku 1). Dopiero po tym kroku ryzyko jest usunięte.
4. **Usuń pliki z bieżącego drzewa**: `git rm --cached key key.pub` + wpisy w `.gitignore` (już dodane: `key`, `key.pub`, `*.pem`, `id_rsa*`, `id_ed25519*`), commit.
5. **Historia Git (tylko za zgodą):** `git filter-repo --path key --path key.pub --invert-paths` (albo BFG), następnie `git push --force` na wszystkie gałęzie i tagi; poinformuj współpracowników o konieczności ponownego sklonowania; poproś GitHub Support o usunięcie zbuforowanych widoków, jeśli repozytorium było publiczne. Przepisanie historii nie unieważnia klucza — krok 3 jest obowiązkowy niezależnie.

## Zasada na przyszłość
Sekrety (ClickUp Client ID/Secret, `UBR_ENCRYPTION_KEY`, klucze API) wyłącznie w `wp-config.php` / zmiennych środowiskowych — nigdy w repozytorium, dokumentacji ani paczkach wdrożeniowych.
