# Changelog

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
