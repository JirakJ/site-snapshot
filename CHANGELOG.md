# Changelog

## 0.2.0 – 2026-09-23

- **nginx:** na serveru s nginx zobrazí karta Záloha upozornění s pravidlem `location ^~ <uploads>/site-snapshot-`
  (na multisite regex pro všechny podweby). Ověřeno na nginx 1.31: archiv, výpis složky i `.htaccess` → 404,
  i s `%2D`, `//`, `/./`, `/../` v adrese a i když je před pravidlem regex blok pro statické soubory.
  Ochrana `.htaccess` ověřena na Apache 2.4 (403).
- **Dokumentace:** kompletní návod [`docs/NAVOD.md`](docs/NAVOD.md) se screenshoty – instalace, zabezpečení,
  FTP přístupy, záloha, obnova, velké weby, řešení problémů, odinstalace.
- Licence GPL-2.0 (`LICENSE`), repozitář je veřejný.
- Z repozitáře odstraněna omylem commitnutá lokální metadata nástrojů (`.claude/`).

## 0.1.0 – 2026-09-23

První verze.

- Záloha souborů + databáze do jednoho ZIPu po krocích (resumable, ZIP64), s `SITE-INFO.txt` / `site-info.json`
  (verze PHP / DB / WP / serveru, přístupové údaje) a návodem k obnově.
- Prohlížeč souborů webu se stažením souboru nebo složky jako ZIP.
- Přehled systému a přístupů, šifrované FTP/hosting údaje.
- Audit log akcí (záloha, stažení, zobrazení přístupů).

Ověřeno na WordPress 7.1.2 / PHP 8.5.7 / MariaDB 13.0.2: `unzip -t` + Python `zipfile` bez chyb, obsah ZIPu
shodný se zdrojem (`diff -r`), obnova `database.sql` do čisté DB → `CHECKSUM TABLE` shodný u všech tabulek
(včetně BLOB/BIT/JSON/NULL/emoji/generovaného sloupce, pohledu a tabulky s ` v názvu), 9 simulovaných pádů
uprostřed zápisu, ZIP64 se souborem 4,5 GB.
